# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

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
