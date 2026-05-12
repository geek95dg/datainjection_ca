# Contributing

## Required for every change

Each change must, in the same commit:

1. **Bump the version** (semver — see table below).
2. **Update the CHANGELOG**.
3. **Be pushed on its own branch and opened as a PR** (draft is fine) targeting `main` — no direct commits to `main`.

The version lives in three places that must move together:

| Where | What to change |
|---|---|
| `setup.php` | `PLUGIN_DATAINJECTION_VERSION` constant |
| `datainjection.xml` | `<num>` inside `<versions><version>` |
| `CHANGELOG.md` | new `## [X.Y.Z] - YYYY-MM-DD` section at the top |

Semver: bug fix → patch bump (`2.16.0` → `2.16.1`); new behaviour-preserving feature → minor bump (`2.16.1` → `2.17.0`); behaviour break / schema change requiring a migration → major bump.

The CHANGELOG follows [Keep a Changelog](http://keepachangelog.com/): group entries under `### Added` / `### Changed` / `### Fixed` / `### Removed`.

## Debugging

The plugin writes to `<GLPI_LOG_DIR>/datainjection.log` (typically `/var/log/glpi/datainjection.log`). If that file is missing or unwritable, messages fall through to PHP's `error_log()` — check `/var/log/php-fpm/error.log` or `/var/log/apache2/error.log`.

A `register_shutdown_function` in `setup.php` catches plugin-originated fatals so they end up in the same place. If a feature 500s without producing a log line, the failure is happening upstream of any plugin code and the diagnostic at `tools/check-install.php` can confirm that.
