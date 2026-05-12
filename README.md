# Datainjection (Custom Assets fork) — `datainjectionca`

Fork of the upstream `pluginsGLPI/datainjection` plugin that adds:

- **GLPI 11 custom-asset support** — every `AssetDefinition` row is exposed as its own injectable type and per-definition custom fields stored in the JSON `glpi_assets_assets.custom_fields` column are surfaced as extra mappable columns.
- **XLSX (.xlsx) import path** — pure-PHP reader using `ZipArchive` + `SimpleXML`, no external dependency.

This fork ships under `<glpi_root>/plugins/` and is intentionally renamed so it can coexist with the upstream `datainjection` plugin.

---

## Installation

> **The plugin's directory on disk MUST be named `datainjectionca` — no underscore, no other casing.**
> GLPI 11 enforces `PLUGIN_KEY_PATTERN = '/^[a-z0-9]+$/i'` in `Plugin::loadPluginSetupFile()`. The repo on GitHub keeps the `datainjection_ca` name for branding, but the deployed folder must drop the underscore.

```bash
# 1. Clone the repo to a working location
git clone https://github.com/geek95dg/datainjection_ca.git ~/github/datainjection_ca

# 2. Make it available under GLPI as `datainjectionca`
ln -s ~/github/datainjection_ca /var/www/html/glpi/plugins/datainjectionca
#   - or -
cp -r ~/github/datainjection_ca /var/www/html/glpi/plugins/datainjectionca

# 3. (Re)build the GLPI cache so the new plugin is discovered
sudo -u www-data php /var/www/html/glpi/bin/console cache:clear

# 4. List plugins — `datainjectionca` should appear with state "Not installed"
sudo -u www-data php /var/www/html/glpi/bin/console glpi:plugin:list

# 5. Install and activate
sudo -u www-data php /var/www/html/glpi/bin/console glpi:plugin:install datainjectionca
sudo -u www-data php /var/www/html/glpi/bin/console glpi:plugin:activate datainjectionca
```

### Troubleshooting

| Symptom | Cause |
|---|---|
| `Invalid plugin directory "datainjection_ca"` | You passed the underscored name. Use `datainjectionca`. |
| `Invalid plugin directory "datainjectionca"` | Folder on disk has underscore (or wrong case). Run `ls /var/www/html/glpi/plugins/` and verify the directory is literally `datainjectionca`. |
| Plugin missing from `glpi:plugin:list` | Folder is not under any of GLPI's plugin directories (`/var/www/html/glpi/plugins` or `/var/www/html/glpi/marketplace`), OR `setup.php` is missing inside it, OR the cache is stale — re-run `cache:clear`. |
| `plugin_version_datainjectionca method must be defined!` | `setup.php` wasn't loaded — usually a permissions issue. Ensure `www-data` can read the folder and `setup.php`. |

---

## What's imported

- Native assets: Computer, Monitor, Printer, NetworkEquipment, Phone, Peripheral, etc.
- **GLPI 11 custom assets** (any `AssetDefinition`), including custom fields.
- Management: Contract, Contact, Supplier, Document, License.
- Configuration: User, Group, Entity, Profile, Location.
- Inventory: Software, Cartridge, Consumable, Budget, NetworkPort, VLAN.
- Device components and operating systems.

## Supported file formats

- **CSV** — pick a delimiter and whether the first row is a header.
- **XLSX** — first worksheet, with shared and inline strings.

## License

GPL-2.0 — see `LICENSE`. Original work by the Teclib datainjection team; custom-asset and XLSX additions by [@geek95dg](https://github.com/geek95dg).
