# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

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
