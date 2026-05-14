# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [2.16.29] - 2026-05-14

### Added

- Step-level checkpoints inside `PluginDatainjectionCommonInjectionLib::processAddOrUpdate()` — the lib method that actually runs the injection. New `processAddOrUpdate: <stage>` log lines for: `enter`, `after_manageRelations`, `after_processDictionnariesIfNeeded`, `after_manageFieldValues`, `before_dataAlreadyInDB`, `after_dataAlreadyInDB`, `before_reformat`, `after_reformat`, `after_check`, `before_effectiveAddOrUpdate`, `after_effectiveAddOrUpdate`. With `engine.injectLine` already instrumented, we can now isolate a silent death to a single sub-step within the lib.

## [2.16.28] - 2026-05-14

### Added

- **Internal `injectLine` checkpoint logging.** `PluginDatainjectionEngine::injectLine()` now logs at five points: `enter`, `before_getOptions`, `after_getOptions`, `before_addOrUpdateObject`, `after_addOrUpdateObject`, `return`. Each carries elapsed-ms-since-entry + memory. When a row dies silently inside vendor/GLPI code, the last checkpoint tells us which internal stage was alive.
- **Cumulative-session-state diagnostic** in `processBatch`. New `processBatch: session state` line at the top of each batch reports `results_count`, `results_json_kb`, `error_lines_count`, `error_lines_json_kb`. Exposes whether the silent death around row ~22 correlates with growing per-session tmp-file size.

### Changed

- Template default `data-batch-size` dropped 2 → 1. With one row per AJAX request, a silent death is unambiguously attributable to a specific `i` / `injectionline`. Crank back up after the bug is fixed.

## [2.16.27] - 2026-05-14

### Fixed

- **Imports are no longer reported as FAILED when they actually succeed.** `PluginDatainjectionCommonInjectionLib::checkType()`'s `'date'` branch only accepted strict `YYYY-MM-DD`. GLPI auto-injects `date_creation` / `date_mod` with full MySQL `DATETIME` values (`'2026-05-14 09:21:42'`), so the date-only regex returned `TYPE_MISMATCH` for those two auto-fields on every single row. That stamped the entire row's status `FAILED` (the lib promotes any per-field non-SUCCESS to a row-level FAILED) — even though the asset was already saved with a real ID. The dump from 2.16.26 confirmed it: `result_dump` showed `"Glpi\\CustomAsset\\drukarkimobilneAsset": 11268, 11269, …` sequential IDs alongside the FAILED status. Regex now also accepts the optional ` HH:MM:SS` suffix.

## [2.16.26] - 2026-05-14

### Added

- `processBatch: injectLine non-success` now also dumps the **entire `$result`** array (`result_keys` + truncated `result_dump` JSON). Earlier diagnostics showed `error_message` / `field_in_error` are null on every FAILED row, which means the injection lib reports the rejection through some other key (probably a per-field nested status, or `values_to_inject` with per-field codes). Dumping the whole structure exposes which key actually carries the reason.

## [2.16.25] - 2026-05-14

### Fixed

- **Surfaced the silent "every row fails" condition.** The previous diagnostics showed `injectLine post … status: 11` on every row, and 11 is `PluginDatainjectionCommonInjectionLib::FAILED` ("Error during injection"), not SUCCESS. The batch loop was treating that as fine and moving on. Every non-SUCCESS row now logs at WARN with its translated status name (FAILED / TYPE_MISMATCH / MANDATORY / ITEM_NOT_FOUND / …), the lib's `error_message`, and `field_in_error` so the actual reason for the rejection lands in `datainjection.log`.
- **Shrunk default `batch_size` from 10 → 2.** Field log showed the AJAX worker dying mid-`injectLine` in batch 2 with no PHP fatal in any log; memory was flat at 6 MB. The variability between attempts (died at different rows) ruled out a data issue and pointed at an external request-timeout (php-fpm `request_terminate_timeout` / nginx `proxy_read_timeout`) cutting the worker because each custom-asset `injectLine` rebuilds `Search::getOptions` many times and is slow. batch_size=2 keeps each AJAX call short enough to comfortably finish inside any reasonable proxy/FPM timeout.

### Added

- `injectLine post` now also logs `status_label` (the translated name) and `elapsed_ms` for that row.
- New `processBatch: loop done` line at the end of every batch with `batch_elapsed_ms` and `lines_in_batch`. Spot a slow batch immediately.

## [2.16.24] - 2026-05-14

### Added

- `processBatch` now also logs a **row preview** (`preview`: first ~240 chars of the joined cell values), the live **memory footprint** (`mem_mb` / `mem_peak_mb`), and a symmetric `processBatch: injectLine post` line after each successful return. Field log: when the batch dies silently mid-`injectLine`, the *last* `injectLine pre` (with preview) tells you which CSV row killed it, and a missing matching `injectLine post` is the unambiguous signature of a non-throwing death (PHP fatal that bypasses both \\Throwable and the shutdown handler).
- `ajax/inject_batch.php` installs a **local** shutdown handler — fires regardless of whether the fatal traces through plugin code (the global setup.php handler bails on vendor-only fatals). Logs message + location + memory, and if headers haven't been sent yet, returns a structured `{error, message: "inject_batch.php fatal: …", where, class: "PHP_FATAL"}` 500 so the JS banner shows the cause instead of hanging.

### Changed

- `ajax/inject_batch.php` raises `memory_limit=1024M`, `max_execution_time=0`, `set_time_limit(0)`, `ignore_user_abort(true)` before doing anything else. Eliminates "worker tripped over a too-low ini limit" / "client disconnect aborted the script" as causes of silent death.

## [2.16.23] - 2026-05-14

### Fixed

- **Stop emitting `Undefined array key "filename"` + `Trying to access array offset on null` (and storing NULL as `file_name` in the session) on every successful upload.** `front/clientinjection.form.php:107` was reading `$_FILES['filename']['name']` AFTER `readUploadedFile()` had already `unset($_FILES['filename'])` (the workaround for Symfony's UploadedFile validator). We now capture the original filename into a local at the top of the upload branch, before processUploadedFile runs, and persist that.

### Added

- Per-line breadcrumbs in `PluginDatainjectionClientInjection::processBatch`:
  - `processBatch: unserialize model`
  - `processBatch: lines decoded` (lines_count, json_len, etc.)
  - `processBatch: starting injection loop` (offset/end/total/itemtype)
  - `processBatch: injectLine pre` — emitted before each `$engine->injectLine()` call so a mid-batch crash is pinpointable to a specific CSV row.
- `$engine->injectLine()` is now wrapped in a per-line try/catch — a single bad row records a FAILED result + logged exception instead of taking the whole batch down with a 500.

### Changed

- The shutdown handler in `setup.php` previously only logged PHP fatals whose `error_get_last()['file']` was inside the plugin directory. It now ALSO logs when the request URI / script name targets `/plugins/datainjection/…` — so a fatal originating from GLPI or vendor code that was triggered by our endpoints (e.g. `Search::getOptions` deep inside `inject_batch.php`) finally produces a breadcrumb.

## [2.16.22] - 2026-05-14

### Fixed

- **Import progress page no longer flips to "Import failed — Wystąpił nieoczekiwany błąd" with nothing in `datainjection.log` to explain it.** `ajax/inject_batch.php` had `Html::header_nocache()` and `Session::checkCentralAccess()` *outside* the `try/catch` wrapper added in 2.16.19. Anything either of those threw (most commonly GLPI's Symfony ExceptionHandler rewriting an upstream error into the generic localised message) skipped our logger entirely and reached the JS as `{message: "An unexpected error occurred"}` → "Wystąpił nieoczekiwany błąd" in Polish, with no breadcrumb on disk. Everything is now inside the try block, and a `received` log line is emitted as the very first action of the script so we can confirm the endpoint was reached even when the body throws.
- `ajax/inject_batch.php` now fails fast with a specific message when the session has lost `currentmodel` or `injection_lines` between the upload step and the first batch, instead of letting `processBatch` blow up on a null unserialize.

### Changed

- `inject_batch.php` error payload now includes the exception class and `file:line` alongside the message; `injection_progress.js` renders that as a second muted line under the "Import failed" banner. When GLPI rewrites `$e->getMessage()` to its localised generic string, the operator can still grep for the class/where pair in `datainjection.log`.
- AJAX error handler in the progress JS also surfaces the HTTP status when the response body wasn't parseable — distinguishes "endpoint 404 (routing)" from "endpoint 500 with no logged exception" at a glance.

## [2.16.21] - 2026-05-13

### Fixed

- **Custom-asset import no longer fails with `Table 'glpi.glpi_plugin_datainjection_customasset<defname>injections' doesn't exist`.** `PluginDatainjectionCommonInjectionLib::dataAlreadyInDB()` resolves the target table via `$injectionClass->getTable()`, and GLPI's `CommonDBTM::getTable()` derives the name from `static::class` (`getTableForItemType()`) rather than reading the inherited `public static $table` property. The registry-generated per-definition wrapper classes therefore fell through to GLPI's auto-pluralization and produced a nonexistent table name. Added an explicit `getTable()` override on `PluginDatainjectionCustomAssetBaseInjection` that always returns `glpi_assets_assets`, so every generated subclass resolves to the real shared custom-asset table.

## [2.16.20] - 2026-05-13

### Fixed

- **Abort button now actually aborts the stuck import.** `front/clientinjection.form.php` was checking `if (has session 'go')` *before* the explicit POST branches, so the cancel/finish POSTs were never reached while a stuck import was in flight. Reordered: explicit `cancel` / `finish` / `upload` now win first, then the session-bound `go (showInjectionForm)` branch.
- **`Cannot instantiate abstract class Glpi\Asset\Asset` no longer kills imports at offset 0.** `PluginDatainjectionCommonInjectionLib::getFieldValue()`'s dropdown / relation case was doing `new getItemTypeForTable($table)()` blindly. For options whose `table = glpi_assets_assets`, the resolver returns the **abstract** `\Glpi\Asset\Asset` base (multiple per-definition concrete subclasses share the table), so the `new` fataled and took the whole batch with it. Added a `ReflectionClass::isAbstract()` short-circuit: the lookup is skipped and the raw value is passed through as text. Logged via `PluginDatainjectionLogger::warning`.
- Cleaned up an `Undefined array key "filename"` warning emitted by the diagnostic logger inside the upload branch — it now logs only the available keys, never touches `$_FILES['filename']` directly after `readUploadedFile()` has already unset it.

## [2.16.19] - 2026-05-13

### Added

- "Abort and start over" button on the injection progress page. Posts `cancel=1` to the legacy controller, which calls `PluginDatainjectionSession::removeParams()` to clear the stuck-import state and redirects back to the model picker. No more reinstalling the plugin to recover from a failed import.
- In-page red error banner that surfaces when the batch loop reports a failure — shows the server-supplied error message and points at `/var/log/glpi/datainjection.log` for the trace.

### Changed

- `ajax/inject_batch.php` is now wrapped in `try/catch` + logger. On exception the endpoint returns a structured JSON error body (`{error: true, message: …, offset: …}`) with HTTP 500 instead of an empty 500 page. The progress-bar JS parses that body, shows the message in the error banner, and stops polling — so a server-side fault no longer leaves the bar spinning forever.
- The progress-bar JS reads `xhr.responseJSON.message` (falling back to `responseText` then a generic label), so the user sees the actual reason instead of a flat "Error".

## [2.16.18] - 2026-05-13

### Fixed

- `PluginDatainjectionBackendxlsx` now implements `openFile()` and `closeFile()`. The legacy CSV-shaped backend interface includes these (CSV streams from disk), and `PluginDatainjectionClientInjection::showInjectionForm()` calls them when fetching rows for batch processing during import. Without them, opening the import page for an XLSX-backed model produced `Call to undefined method PluginDatainjectionBackendxlsx::openFile()` and a 500. `openFile()` lazy-parses (if not already) and rewinds the cursor so re-iteration works; `closeFile()` is a no-op (rows live in memory).

## [2.16.17] - 2026-05-13

### Added

- `front/clientinjection.form.php` (the actual import UI) is now wrapped in the same `try/catch` + branch-label logger pattern as `front/model.form.php`. The 500 you currently see when the model finishes validation and the user opens the import page will be logged as `clientinjection.form.php failed (branch=…)` with a precise file:line + trace, instead of disappearing behind GLPI's generic "Wystąpił nieoczekiwany błąd" page. Branches: `go` (showInjectionForm), `upload`, `finish`, `cancel`, `showForm`. Entry breadcrumb records the request method, POST/GET keys, and whether the session already has `go` / files.
- `customAsset.getOptions: returning` breadcrumb now includes `pairs` — a compact `linkfield=name` listing of every option that survived to the Mappings UI (capped at ~1500 chars). Lets us tell at a glance whether "Producent" / "Lokalizacja" / etc. are present, and whether anything is leaking a raw column name instead of a human label.

### Changed

- The clientinjection import branch no longer hard-codes `'file_encoding' => $_POST['file_encoding']` blindly; if that POST key is missing, it falls back to `PluginDatainjectionBackend::ENCODING_AUTO` instead of passing `null` into the backend.

## [2.16.16] - 2026-05-13

### Fixed

- Mappings → Fields dropdown now lists the full set of native columns (Nazwa, Numer seryjny, Lokalizacja, Status, Producent, …) for custom-asset itemtypes. The 2.16.13 diagnostic exposed that `PluginDatainjectionCommonInjectionLib::addToSearchOptions()` was throwing `Typed static property Glpi\Asset\Asset::$definition_system_name must not be accessed before initialization` when it called `getItemTypeForTable('glpi_assets_assets')::getTypeName(1)` — the table resolves to the abstract base `\Glpi\Asset\Asset` whose static is only initialised on the per-definition subclasses. We were preserving the raw options as a fallback, but the options had no `injectable=FIELD_INJECTABLE` flag, so `dropdownFields()` filtered them all out and the user saw only the post-appended custom fields.

### Changed

- Stopped calling `addToSearchOptions()` for custom-asset itemtypes. Replaced with `PluginDatainjectionCustomAssetBaseInjection::processSearchOptionsForCustomAsset()`, which performs the load-bearing parts of the shared helper without the failing introspection: filter out blacklisted / field-less options, mark the rest `FIELD_INJECTABLE` (or `FIELD_VIRTUAL` if no `linkfield`), derive `displaytype`/`checktype` defaults from `datatype`, apply the caller's explicit overrides, dedupe by `linkfield` (preferring `completename` > `name` > first encountered).

## [2.16.15] - 2026-05-13

### Fixed

- Custom-field rows in the Mappings → Fields dropdown now show the human label ("Lokalizacja", "Regal") instead of the internal `system_name` (`locations_id`, `custom_regal`), and drop the noisy `(custom field)` suffix. The registry now parses GLPI 11's actual `glpi_assets_customfielddefinitions` schema: `label` for the source-language name plus `translations` (JSON map) for the active locale, with a fallback chain `lang → en_GB → en → first non-empty → system_name`.
- Custom fields that point at a foreign itemtype (Location, Manufacturer, Group, …) — including the capacity-supplied ones GLPI stores under `system_name="locations_id"` / `"groups_id"` / `"manufacturers_id"` in the `custom_fields` JSON column — are now exposed to the mapping form as proper dropdowns. The injection lib therefore looks up the value by name at import time (CSV/XLSX can carry "CHO > IT-Stock", not the raw ID) and stores the resolved FK id in `custom_fields[system_name]`.

### Changed

- `parseCustomFieldEntry()` now normalises GLPI 11's `type` column from a class FQCN (`Glpi\Asset\CustomFieldType\DropdownType`) to a short token (`dropdown`, `text`, `number`, `date`, `boolean`, `user`, `url`, …) so downstream switches don't depend on the full namespace.
- `parseCustomFieldEntry()` now also surfaces the field's target `itemtype` (the FK target class) so the injection options can derive the joined table for name→ID lookups.

## [2.16.14] - 2026-05-13

### Fixed

- Mappings page Fields dropdown now lists the standard `glpi_assets_assets` columns (Name, Serial, Inventory, Comments, Entity, Location, Status, Manufacturer, User, Group, Technician in charge, Group in charge, dates, deleted/template flags, …) for custom-asset itemtypes — `addToSearchOptions()` was stripping them on its way out. Hard-wired catalogue keyed on the live table schema (`$DB->fieldExists`) so the appended set adapts to whichever columns the install actually has — different GLPI versions and enabled capacities ship slightly different column sets.

### Added

- `PluginDatainjectionCustomAssetBaseInjection::nativeAssetFieldCatalog()` — declarative table of standard asset columns → search-option metadata (display type, check type, FK joined table). Easy to extend if a capacity introduces a column we should also map.
- Breadcrumb `customAsset.getOptions: native fields appended | {"appended":N,"kept_count":M}` so the next investigation can tell at a glance whether the appender is doing work.

## [2.16.13] - 2026-05-13

### Changed

- `PluginDatainjectionCustomAssetBaseInjection::getOptions()` now wraps `PluginDatainjectionCommonInjectionLib::addToSearchOptions()` in a `try/catch`. The lib introspects each option's table via `getItemTypeForTable(...)->getTypeName(1)`, which can throw when GLPI's search options for custom assets reference a table that doesn't map cleanly to an itemtype. Any throw is now logged with a full trace and the previously-patched options are preserved as a best-effort fallback, instead of leaving the Fields dropdown empty with no breadcrumb.

### Added

- Diagnostic breadcrumbs for the linkfield-patching pass: counts of how many options already carry `linkfield`, how many lack `field`, how many lack `table`. Plus a `first_option` sample dump showing the keys / `field` / `table` / `linkfield` / `name` of the first numeric-keyed option so the actual shape of GLPI's stock search options for AssetDefinition classes is visible. The 2.16.12 breadcrumb reported `patched_linkfield: 0` with no further detail, which left "did the options already have it?" vs "is `field` missing?" indistinguishable.

## [2.16.12] - 2026-05-13

### Fixed

- Mappings page now lists fields for custom-asset itemtypes. Native injection classes (Computer, Monitor, …) hand-fill `linkfield` on every `Search::getOptions()` entry, so the common-injection library's `addToSearchOptions()` keeps them. GLPI's stock search options for `AssetDefinition` classes don't, and `addToSearchOptions()`'s "dedupe by linkfield" pass was therefore stripping *every* entry. `PluginDatainjectionCustomAssetBaseInjection::getOptions()` now populates `linkfield` defensively before calling `addToSearchOptions`: for the asset's own columns `linkfield = field`, for joined dropdown tables it's derived from the table name (`glpi_locations` → `locations_id`).
- `front/model.form.php`'s top-level `try/catch` no longer logs `Glpi\Exception\RedirectException` as a plugin error. GLPI 11's `Html::redirect()` *throws* that exception as its normal mechanism for issuing a 302 (the outer `LegacyFileLoadController` catches it and converts it to a real redirect) — treating it as a failure produced noisy false-positive `ERROR` lines after every successful save / back / redirect.

### Added

- Breadcrumbs in `getOptions()` for custom assets: raw count from `Search::getOptions`, how many entries got a fresh `linkfield`, count surviving `addToSearchOptions`, and final count including custom fields. Makes "empty Fields dropdown" investigations measurable.

## [2.16.11] - 2026-05-13

### Fixed

- File upload (`front/model.form.php?upload=…`) no longer 500s with `FileNotFoundException: The file "/tmp/php…" does not exist`. Root cause: GLPI 11 routes the response through Symfony's HttpKernel; later in the request lifecycle (e.g. when `Html::back()` builds its redirect) Symfony re-constructs a `Request` from globals, walks `$_FILES`, and instantiates an `UploadedFile` whose ctor verifies `tmp_name` exists — but our `move_uploaded_file()` had already relocated the file out of `/tmp`, leaving a stale path. `readUploadedFile()` now drops `$_FILES['filename']` immediately after a successful move so the `FileBag` has nothing to validate.

## [2.16.10] - 2026-05-13

### Fixed

- `front/model.form.php` update branch hard-coded `PluginDatainjectionModel::getInstance('csv')`, which silently routed XLSX model updates into the CSV companion table (and never persisted XLSX-specific fields). It now picks the companion class based on the model's actual `filetype` and falls back to CSV only when the requested companion class is missing.
- The upload branch passed `'file_encoding' => 'csv'` (a *filetype*, not an encoding). Switched to `PluginDatainjectionBackend::ENCODING_AUTO` so the backend's encoding detection actually runs.

### Added

- `front/model.form.php` is now wrapped in a top-level `try/catch` that logs the failing branch name (`add`/`update`/`upload`/`delete`/`purge`/`validate`/`sample`/`display`) plus a stack trace before re-throwing — so any 500 surfaces in `/var/log/glpi/datainjection.log` with a precise breadcrumb instead of just the generic "Wystąpił nieoczekiwany błąd" page.
- Entry breadcrumb logging the request method, requested id, and POST keys.

## [2.16.9] - 2026-05-13

### Fixed

- `readUploadedFile()` no longer logs `Undefined array key "delimiter"` when uploading to an XLSX model. `delimiter` is a CSV-only column; the access is now guarded with `isset()` so xlsx models pass through cleanly.

### Added

- Breadcrumb logging on the upload path: `readUploadedFile` and `processUploadedFile` log entry, the moved temp filename, the backend's parsed line count, and any backend `read()` exception (with stack trace). When a model creation gets stuck somewhere after the upload, the log now shows exactly which step ran last.
- Backend `read()` is wrapped in try/catch so a failure inside the XLSX parser surfaces as an `ERROR` line plus a user-facing GLPI message — instead of an unexplained "go back to upload step".

## [2.16.8] - 2026-05-13

### Fixed

- Opening the model overview tab for an XLSX-format model no longer 500s with `Table 'glpi.glpi_plugin_datainjection_modelxlsxes' doesn't exist`. GLPI's `CommonDBTM::getTable()` auto-pluralizes `xlsx` (ending in `x`) to `xlsxes`, but the install / migration helpers were creating the table as `modelxlsxs` (no `e`). Canonical name is now `glpi_plugin_datainjection_modelxlsxes`.

### Changed

- `plugin_datainjection_migration_xlsx_support()` now self-heals existing installs: if the legacy `glpi_plugin_datainjection_modelxlsxs` table is present it gets renamed to the canonical `…modelxlsxes` (or dropped if the canonical one already exists). Re-running `php bin/console glpi:plugin:install datainjection` on any 2.16.0–2.16.7 install picks up the fix automatically.
- `plugin_datainjection_uninstall()` drops both names so the legacy table can never linger.

## [2.16.7] - 2026-05-13

### Fixed

- Custom-asset and Form-Category itemtypes now appear in the "Type of data to import" dropdown on the model creation form. Two regressions were stacking:
  - `PluginDatainjectionInjectionType::getItemtypes()` was probing rights against the *wrapper* class (`PluginDatainjectionCustomAsset<X>Injection`, `PluginDatainjectionCategoryInjection`), which has an empty `$rightname` and therefore failed `canCreate()`. The probe now targets the actual itemtype declared by `getInjectionItemtype()` — i.e. `\Glpi\CustomAsset\<X>Asset` or `\Glpi\Form\Category` — which carries the real per-definition rights.
  - The dropdown was keyed by `get_parent_class()`, so every per-definition custom-asset wrapper collapsed to a single row (they all extend the same base class). Keyed by `getInjectionItemtype()` when available so each definition gets its own entry.
- `PluginDatainjectionCustomAssetBaseInjection::getTypeName()` now delegates to the underlying asset class, so the dropdown shows the human-readable definition label (e.g. "Ipads", "PM90") instead of the wrapper class name.

## [2.16.6] - 2026-05-13

### Fixed

- `PluginDatainjectionCategoryInjection` no longer crashes with `Compile Error: Class PluginDatainjectionCategoryInjection cannot extend final class Glpi\Form\Category`. GLPI 11 emits `Glpi\Form\Category` as `final`, so the previous `extends Category` was a compile-time fatal during autoload — which is why the breadcrumb logging never had a chance to write anything: the class file dies before `plugin_init` returns. Refactored to the same composition pattern used for custom assets: extend `CommonTreeDropdown`, delegate `add()` / `update()` to a freshly-instantiated `Category` in `customimport()`.

### Changed

- `PluginDatainjectionLogger` now mirrors every `ERROR` / `WARN` to PHP's `error_log()` in addition to writing the dedicated log file — not only on file-write failure. When `/var/log/glpi/datainjection.log` is unwritable by the web user (common after `touch` as root), the tagged lines still surface in `php-fpm` / `apache2` error logs prefixed with `[datainjection]`. `INFO` is still only mirrored on file-write failure to keep volume reasonable.

## [2.16.5] - 2026-05-12

### Added

- Entry/exit breadcrumb logging on the heaviest tab callbacks (`getTabNameForItem`, `displayTabContentForItem`, `showAdvancedForm`, `PluginDatainjectionInjectionType::getItemtypes`) so the log shows where a failing page-load actually dies — even when the exception surfaces in GLPI's tab loader rather than our own code. Each line records the item class, id, tab number, and structural context (number of injectable types, twig render boundary, additional-form filetype).
- Plugin-scoped `set_error_handler` that records non-fatal PHP warnings/notices originating in our files. Daisy-chains to the previous handler so it never blocks GLPI's own error reporting.

## [2.16.4] - 2026-05-12

### Fixed

- Custom-asset injection no longer crashes with `Class PluginDatainjectionCustomAsset<X>Injection cannot extend final class Glpi\CustomAsset\<X>Asset`. GLPI 11 emits the per-definition dynamic asset class as `final`, so the previous strategy of `eval`-ing a subclass was a compile-time fatal — which surfaced as a 500 on `/ajax/common.tabs.php` whenever the plugin scanned `glpi_assets_assetdefinitions`. The generated injection class now extends a non-final `PluginDatainjectionCustomAssetBaseInjection` and delegates CRUD to a freshly-instantiated asset object (instantiating a final class is permitted; only extending it isn't).

### Removed

- `inc/customassetinjectiontrait.class.php` (its methods moved into the new base class).

## [2.16.3] - 2026-05-12

### Fixed

- `clientinjection.html.twig` no longer renders a bare `const modelId = ;` (and similarly for `step` / `resultStep`) when the corresponding session keys are unset, which was throwing `Uncaught SyntaxError: Unexpected token ';'` on every load of `front/clientinjection.form.php`. PHP-side, `models_id`, `step`, and `result_step` are coerced to `int` (defaulting to 0) before being passed to the template.

## [2.16.2] - 2026-05-12

### Changed

- `CONTRIBUTING.md` now also pins the workflow: every change ships on its own branch and is opened as a PR against `main`.

## [2.16.1] - 2026-05-12

### Added

- Logger falls through to PHP's `error_log()` when `<GLPI_LOG_DIR>/datainjection.log` cannot be written, so messages are never silently dropped.
- `register_shutdown_function` traps fatal errors originating inside the plugin and routes them through the same logger — captures the 500-class failures that bypass our try/catch wrappers.

## [2.16.0] - 2026-05-12

### Added

- Custom-asset support: every GLPI 11 `AssetDefinition` is exposed as its own injectable type and per-definition JSON custom fields become mappable columns. Definition rows are scoped via `assets_assetdefinitions_id` so two definitions cannot collide on a shared identifier.
- XLSX import path via a pure-PHP `ZipArchive` + `SimpleXML` reader (no external dependency). Companion table `glpi_plugin_datainjection_modelxlsxs` and an idempotent migration for existing installs.
- File-based logger `PluginDatainjectionLogger` writing to `<GLPI_LOG_DIR>/datainjection.log` with timestamp, level, user id and JSON context. Size-based rotation at 5 MB, up to 3 retained segments.
- Catch-all wrappers around `getTabNameForItem` / `displayTabContentForItem` so tab AJAX errors surface a "see datainjection.log" notice instead of a 500.
- `tools/check-install.php` diagnostic for verifying GLPI discovers the plugin.

### Changed

- Bumped to `2.16.0` so GLPI prefers this fork over upstream `2.15.x`.
- Author set to `geek95dg`; manifest links point at this repo.
- Minimum GLPI version lowered to `11.0.0` (was `11.0.5`).
- `setup.php` and `hook.php` no longer use `Safe\define` / `Safe\mkdir`, removing a hard dependency on `thecodingmachine/safe` being loaded before the plugin's setup file is included.

## [2.15.6] - 2026-05-05

### Fixed

- Fix injection loading bar crash

## [2.15.5] - 2026-04-30

### Fixed

- Fix crash when injection model has no mandatory fields defined
- Fix models created on parent entities can't be used on child entites
- Fix association of imported data with non-existing groups when the group column is empty in the import file
- Fix responsible group injection payload normalization so group remains visible in GLPI after import
- Fix incorrect escaping of apostrophes and accents
- Fix plugin rights initialization and cleanup
- Fix injection loading bar


## [2.15.4] - 2026-03-16

### Fixed

- Fix model subform not displayed after page reload
- Rename visibility field label to "Is Private"
- Restored group selection in mappings
- Fix special caracters malformed in translations
- Fix error when displaying the additional information form
- Fix the `computer contact` injection when the corresponding value in the CSV file is empty

## [2.15.3] - 2025-12-22

### Fixed

- Fix `name` and `locations_id` when updating the `completename`
- Fix user field updates and email import

### Added

- Handle `Service catalog category`

## [2.15.2] - 2025-11-25

### Fixed

- Fix truncated CSV export
- Fix injection for `groups_id` and `groups_id_tech` fields
- Fix missing `purge` action
- Fix `Model` visibilty criteria
- Fix user fields nullability to prevent SQL errors during injection
- Remove groups as import link field
- Fix `clean` function
- Prevent fatal error with `Safe\ini_set()`

## [2.14.4] - 2025-11-25

### Fixed

- Fix truncated CSV export
- Fix missing `purge` action

## [2.15.1] - 2025-10-14

### Fixed

- Fix file upload

## [2.15.0] - 2025-09-30

### Added

- GLPI 11 compatibility

### Fixed

- Fix `NetworkName` ip adresses injection
- Fix injection to the `date_expiration` of certificates
- Fix the `Task completed` message during plugin update

## [2.14.3] - 2025-09-16

### Fixed

- Fix `NetworkName` ip adresses injection
- Fix injection to the `date_expiration` of certificates
- Fix the `Task completed` message during plugin update
- Fix `License` injection

## [2.14.2] - 2025-08-22

### Fixed

- Use `global`  configuration for injection links
- Escape  data when check if already exist
- Fix injection of `values in entity tabs` when injecting an `entity`
- Fix `pdffont` field error for users
- Move `Notepads` search options to the Black List option
- Fix the SQL error: `Column ‘...’ cannot be null in the query`


### Added

- Add option to `replace` (instead of `append`)  the value of multiline text fields (e.g. `comment`)


### Removed

- Integration of the WebService plugin (plugin is no longer maintained)


## [2.14.1] - 2024-12-27

### Added

- Add injection of the ```Itemtype```, ```Item``` and ```Path``` for the database instance

### Fixed

- Fix relation (`CommonDBRelation`) insertion
- Fix default entity insertion for a user
- Fixed `SQL` error when creating new injection model
- Fixed issue with missing dropdown options

## [2.14.0] - 2024-10-10

### Added

- Display max upload size
- Add ```default_entity``` to ```User``` injection mapping

### Fixed

- Fix network port unicity
- Fix visibility of injection models
- Fix ```CommonDBRelation``` import
- Fix ```IPAddress``` import which adds a ```lock``` on ```last_update``` field
- Fix ```Agent``` lost when update dynamic asset

## [2.13.5] - 2024-02-22


### Fixed

- Allow lockedfield update
