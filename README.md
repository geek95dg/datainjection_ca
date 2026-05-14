# Datainjection (Custom Assets fork)

Fork of [pluginsGLPI/datainjection](https://github.com/pluginsGLPI/datainjection) that adds:

- **GLPI 11 custom-asset support** — every `AssetDefinition` is exposed as its own injectable type, and per-definition custom fields stored in the JSON `glpi_assets_assets.custom_fields` column are surfaced as extra mappable columns.
- **XLSX (.xlsx) import path** — pure-PHP reader using `ZipArchive` + `SimpleXML`, no external dependency required.
- **Resilient batch importer** — JS-side retry layer absorbs the transient PHP-FPM worker hangs that GLPI 11 occasionally triggers during large imports (see [PHP-FPM tuning](#php-fpm-tuning) below).

This fork keeps the **same plugin key (`datainjection`) and directory name as upstream** — it is a drop-in replacement. Bumped to **`2.16.x`** so that GLPI prefers it over the upstream `2.15.x` series.

## Installation

```bash
# Drop the repo in as <glpi_root>/plugins/datainjection — same name as upstream.
git clone https://github.com/geek95dg/datainjection_ca.git /var/www/html/glpi/plugins/datainjection

sudo -u www-data php /var/www/html/glpi/bin/console cache:clear
sudo -u www-data php /var/www/html/glpi/bin/console glpi:plugin:install datainjection
sudo -u www-data php /var/www/html/glpi/bin/console glpi:plugin:activate datainjection
```

> If you already have the upstream `datainjection` plugin installed under `marketplace/`, GLPI will pick whichever directory comes first in `GLPI_PLUGINS_DIRECTORIES`. Either remove the upstream copy or rely on the higher version number — but expect a routine `Plugin::isUpToDate` upgrade pass on activation.

## What's imported

- Native assets: Computer, Monitor, Printer, NetworkEquipment, Phone, Peripheral, etc.
- **GLPI 11 custom assets** (any `AssetDefinition`), including custom fields stored in the JSON `custom_fields` column.
- Management: Contract, Contact, Supplier, Document, License.
- Configuration: User, Group, Entity, Profile, Location.
- Inventory: Software, Cartridge, Consumable, Budget, NetworkPort, VLAN.
- Device components and operating systems.

## Supported file formats

- **CSV** — pick a delimiter and whether the first row is a header.
- **XLSX** — first worksheet; shared and inline strings handled.

## Compatibility

- GLPI **11.0.0 – 11.0.99**
- PHP **8.2+**

## Custom-asset imports — what's different from native types

GLPI 11 introduced the `AssetDefinition` concept: instead of one fixed schema per itemtype (`Computer`, `Monitor`, …), administrators can define their own asset types at runtime, each with its own custom fields. Internally every custom asset is stored in the shared `glpi_assets_assets` table, with the `assets_assetdefinitions_id` column pointing at the definition and a JSON `custom_fields` column holding the definition-specific values.

This plugin treats each `AssetDefinition` as its own injectable itemtype. The mapping UI shows:

- Every native column of `glpi_assets_assets` (`name`, `serial`, `otherserial`, `states_id`, `locations_id`, `manufacturers_id`, …)
- Every custom field declared on the definition, including text, dropdown, foreign-key, date, and boolean types.

Dropdown / foreign-key fields are auto-resolved by name during import — drop the human-readable value in the CSV (e.g. `Warsaw HQ` for a Location) and the plugin looks up the matching ID, optionally creating the dropdown entry when "Allow new dropdown values" is enabled on the model.

After a successful import, the result-page links point at GLPI 11's custom-asset route:

```
/front/asset/asset.form.php?class=<system_name>&id=<NNN>
```

…not the legacy `/front/customasset/<name>asset.form.php` path that 404s on GLPI 11.

## PHP-FPM tuning

For native-type imports (Computer, Monitor, …) the defaults are fine. For **custom-asset imports of more than ~22 rows**, GLPI 11's asset-pipeline internals accumulate per-worker state that eventually stalls `CommonDBTM::add()`. The plugin already absorbs this with a JS-side retry layer, but the import will be much smoother (and faster, because it doesn't have to wait through retry backoff) if FPM workers recycle frequently.

Add to your active pool config — usually `/etc/php/8.4/fpm/pool.d/www.conf`:

```ini
pm.max_requests = 20        ; recycle each worker after 20 requests
pm.max_children = 16        ; default of 5 is too low for parallel import + UI use
```

Then **restart**, not reload, so existing long-lived workers are actually killed:

```bash
sudo systemctl restart php8.4-fpm
# Confirm: no workers older than seconds.
ps -eo pid,etime,comm | grep php-fpm
```

Verify the running config picked up the new values:

```bash
sudo php-fpm8.4 -tt 2>&1 | grep -E 'pm\.max_(requests|children)'
```

**Why these numbers?** The hang threshold in GLPI 11's custom-asset pipeline is around 22 ADDs per worker; recycling at 20 keeps every worker fresh enough that the bug never gets a chance to trigger. 16 children give the JS retry layer enough fresh workers to fall onto if one ever does hang.

If you don't have root access to FPM, the JS retry layer (max 8 retries per batch with 750 ms → 12 s backoff) makes the import complete anyway — it just shows `— retry N/8` in the status line briefly.

## Logging

The plugin writes timestamped breadcrumbs to `<GLPI_LOG_DIR>/datainjection.log` (typically `/var/log/glpi/datainjection.log`). Rotates at 5 MB, keeps 3 segments.

Useful one-liners:

```bash
# Non-INFO entries only (errors, warnings, fatals):
sudo grep -v ' \[INFO\] ' /var/log/glpi/datainjection.log | tail -50

# Per-row injection timeline of the most recent import:
sudo grep -E 'injectLine pre|injectLine post|loop done' /var/log/glpi/datainjection.log | tail -40

# Was the last batch a clean success?
sudo grep 'inject_batch.php: ok' /var/log/glpi/datainjection.log | tail -3
```

## Testing

A pre-release Q&A checklist is in [`TESTING.md`](TESTING.md). It walks a non-developer tester through every user-facing feature plus the security gates (CSRF, access control, file-upload guards).

## License

GPL-2.0 — see `LICENSE`. Original work by the Teclib datainjection team; custom-asset, XLSX, retry, and observability additions by [@geek95dg](https://github.com/geek95dg).
