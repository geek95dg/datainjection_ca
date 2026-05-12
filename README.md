# Datainjection (Custom Assets fork)

Fork of [pluginsGLPI/datainjection](https://github.com/pluginsGLPI/datainjection) that adds:

- **GLPI 11 custom-asset support** — every `AssetDefinition` is exposed as its own injectable type, and per-definition custom fields stored in the JSON `glpi_assets_assets.custom_fields` column are surfaced as extra mappable columns.
- **XLSX (.xlsx) import path** — pure-PHP reader using `ZipArchive` + `SimpleXML`, no external dependency required.

This fork keeps the **same plugin key (`datainjection`) and directory name as upstream** — it is a drop-in replacement. Bumped to **`2.16.0`** so that GLPI prefers it over the upstream `2.15.x` series.

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

## License

GPL-2.0 — see `LICENSE`. Original work by the Teclib datainjection team; custom-asset and XLSX additions by [@geek95dg](https://github.com/geek95dg).
