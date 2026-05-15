# Datainjection — Tester Q&A

A pre-release acceptance checklist. Designed so a non-developer can walk through every user-facing feature plus the security gates. Each section has:

- **What you'll check** — one-line intent.
- **Steps** — copy-paste-able instructions, screenshots optional.
- **Expected result** — what success looks like.
- **Result table** at the end of each test — fill **Status** (`Pass` / `Fail` / `N/A`), **Tester initials**, **Date**, and **Notes**.

> Skip notation: each item is independent. If a test section doesn't apply to your install (e.g. you don't use Genericobject), mark it **N/A** and move on.

Suggested tooling for the security tests:

- A browser with **DevTools** (F12 in Chrome, Firefox, Edge).
- **curl** on the command line. Comes pre-installed on macOS / Linux; on Windows use WSL or the bundled `curl.exe`.
- An SSH/terminal session to the GLPI server for log inspection.
- **No** Burp Suite / sqlmap / etc. is required — every test below uses only the browser and curl.

---

## Master results table

Fill this in as you go. The detailed instructions for each test are in the sections below. Cross-reference by the **Test ID** column.

| ID    | Title                                                                 | Status | Tester | Date | Quick notes |
|-------|-----------------------------------------------------------------------|--------|--------|------|-------------|
| A1    | Plugin shows up in the GLPI admin UI                                  |        |        |      |             |
| A2    | Menu item appears for a permitted user                                |        |        |      |             |
| A3    | Menu item is HIDDEN for a user without rights                         |        |        |      |             |
| B1    | Round-trip a Computer CSV                                             |        |        |      |             |
| B2    | Re-import the same file (update path)                                 |        |        |      |             |
| C1    | Custom asset CSV import — basic columns                               |        |        |      |             |
| C1b   | Mapping dropdown shows every standard column with a human label       |        |        |      |             |
| C1c   | No Fields-plugin entries pollute the custom-asset Mappings dropdown   |        |        |      |             |
| C1d   | No bogus `_customfield_<native>` rows in the dropdown                 |        |        |      |             |
| C2    | Custom asset with custom fields — values persist to DB                |        |        |      |             |
| C2b   | Imported custom-field values render in the asset GUI                  |        |        |      |             |
| C2c   | Import generates History entries (`glpi_logs`)                        |        |        |      |             |
| C3    | Large custom-asset import (100+ rows) completes end-to-end            |        |        |      |             |
| C4    | XLSX format for custom assets                                         |        |        |      |             |
| C5    | Fresh AssetDefinition with mandatory custom fields imports cleanly    |        |        |      |             |
| C6    | Re-import same XLSX over existing rows merges values (update path)    |        |        |      |             |
| D1    | Wrong delimiter                                                       |        |        |      |             |
| D2    | Empty mandatory field                                                 |        |        |      |             |
| D3    | Abort & start over button                                             |        |        |      |             |
| D4    | Direct-SQL fallback log line fires when expected                      |        |        |      |             |
| E1    | CSRF — upload form refuses requests without a valid token             |        |        |      |             |
| E2    | CSRF — abort button is also gated                                     |        |        |      |             |
| E3    | Access control — central-only endpoint                                |        |        |      |             |
| E4    | Right enforcement — `plugin_datainjection_use` Read is required       |        |        |      |             |
| E5    | File-upload guards — wrong extension                                  |        |        |      |             |
| E6    | File-upload guards — `tmp_name` not a real upload                     |        |        |      |             |
| E7    | Stored-data exposure — log file permissions                           |        |        |      |             |
| E8    | SQL injection — try a quote in a mapped value                         |        |        |      |             |
| E9    | XSS — quote-containing name in result table                           |        |        |      |             |
| E10   | Session isolation — uploaded file is per-user                         |        |        |      |             |
| F1    | Log file rotates at 5 MB                                              |        |        |      |             |
| F2    | No PHP warnings during a normal import                                |        |        |      |             |

> **Status legend**: `Pass` / `Fail` / `N/A` / `Blocked` / `In progress`.
> Paste screenshots, log snippets, and exception traces into the dedicated **Notes** row at the end of each test below.

---

## Section A — Smoke tests

### A1. Plugin shows up in the GLPI admin UI

**What you'll check**: the plugin is installed and activated.

**Steps**:
1. Log into GLPI as a user with `config` rights.
2. Top menu → **Setup → Plugins**.
3. Find **"Data injection (custom assets)"** in the list.

**Expected**:
- Row shows version **2.16.x** (where x is the latest, e.g. 2.16.32).
- Status is **Enabled** (green check, or "Activated" label depending on theme).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### A2. Menu item appears for a permitted user

**What you'll check**: the new menu entry routes correctly.

**Steps**:
1. Log in as a user whose profile has the **"Injection of the file" → Read** right ticked (Setup → Profile → Data injection tab).
2. Top menu → **Tools → Data injection**.

**Expected**:
- A page titled "Data injection" opens at `…/plugins/datainjection/front/clientinjection.form.php`.
- A model picker dropdown is visible.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### A3. Menu item is HIDDEN for a user without rights

**What you'll check**: access control on the menu.

**Steps**:
1. Edit a test profile and **uncheck** "Injection of the file" → Read.
2. Log in as a user with that test profile (or impersonate).
3. Look at the Tools menu.

**Expected**:
- "Data injection" entry is not in the menu.
- Going directly to `…/plugins/datainjection/front/clientinjection.form.php` yields a permission-denied page (HTTP 403 or GLPI's standard "access denied" screen).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Section B — Native-type CSV import

### B1. Round-trip a Computer CSV

**Test data** — save as `computers.csv`:

```csv
name;serial;otherserial
TEST-COMP-01;SN-0001;INV-0001
TEST-COMP-02;SN-0002;INV-0002
TEST-COMP-03;SN-0003;INV-0003
```

**Steps**:
1. Log in as a user with full data-injection rights.
2. Go to **Tools → Data injection**.
3. If no Computer model exists yet:
   - Click **"+ Add"** → create a model named `Computer test`, itemtype `Computer`, filetype `csv`, delimiter `;`, header `Yes`.
   - Upload `computers.csv` from the model's **File format** tab to populate mappings.
   - Map columns: `name → Name`, `serial → Serial number`, `otherserial → Inventory number`. Save mappings.
2. Go back to **Tools → Data injection**, select the model, click **Browse**, choose `computers.csv`, click **Launch the import**.

**Expected**:
- Progress bar climbs to 100%.
- Result table lists 3 rows with status **Success** (green).
- Each row has a clickable link in the **Item** column. Clicking opens the Computer detail page at `…/front/computer.form.php?id=NNN`.
- The newly-created computers appear in Assets → Computers.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### B2. Re-import the same file (update path)

**Steps**:
1. Edit `computers.csv` — change `INV-0001` to `INV-0001-UPDATED`.
2. Re-upload the same file with the same model.

**Expected**:
- Result table status is **Success** with action **Update** (not Add).
- Computer `TEST-COMP-01` now has `INV-0001-UPDATED` as its inventory number.
- No duplicate rows in Assets → Computers.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Section C — Custom-asset import (the fork's headline feature)

> Pre-requisite: at least one `AssetDefinition` exists. Create one under **Setup → Assets → Asset definitions** if needed; name it e.g. `MobilePrinter`.

### C1. Custom asset CSV import — basic columns

**Test data** — save as `mobileprinters.csv`:

```csv
name;serial;otherserial;states_id
PRT-001;PRTSN-0001;INV-PRT-0001;In stock
PRT-002;PRTSN-0002;INV-PRT-0002;In stock
PRT-003;PRTSN-0003;INV-PRT-0003;In use
```

(`In stock` and `In use` must exist in **Configuration → Dropdowns → Statuses of items**, or enable "Allow new dropdown values" on the model so they're auto-created.)

**Steps**:
1. Create a model: itemtype `MobilePrinter` (or whatever your AssetDefinition is), filetype `csv`, delimiter `;`, header `Yes`.
2. Upload the CSV to populate mappings, map columns to the matching custom-asset fields, save.
3. Run the import.

**Expected**:
- All 3 rows succeed.
- Result-table links point to `/front/asset/asset.form.php?class=mobileprinter&id=NNN` — **not** `/front/customasset/mobileprinterasset.form.php?id=NNN`.
- Clicking a link opens the asset detail page.
- The new assets appear in **Assets → <YourDefinitionName>**.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### C1b. Mapping dropdown shows every standard column with a human label

**What you'll check**: the **Field** dropdown on the Mappings tab contains the standard `glpi_assets_assets` columns (Name, Serial number, Inventory number, Manufacturer, Type, Model, Status, Location, …) with translated/human labels, **not** raw SQL identifiers like `manufacturers_id` or `is_recursive`. Specifically the **Manufacturer** entry must be present and selectable (separate from any "Firmware: Producent" device-relation entry that may also appear under Firmware).

**Steps**:
1. Edit a custom-asset model (the one from C1).
2. Open the Mappings tab.
3. On any unmapped row, open the **Field** dropdown.
4. Scroll through the list.

**Expected**:
- "Manufacturer" / "Producent" appears as a top-level option (not only under "Firmware: …").
- No entry in the dropdown looks like `snake_case_lowercase` (e.g. you should never see a raw `manufacturers_id`, `assets_assettypes_id`, `is_recursive`, etc.). If any do appear, paste a screenshot — those are bugs to fix.
- "Type", "Model", "Status", "Location", "User", "Technician in charge" all appear with their human labels.

**Quick way to verify after import**: pick "Manufacturer" in the mapping, import a row with `manufacturers_id=Brother` (or whichever vendor is in your dropdown), and confirm `glpi_assets_assets.manufacturers_id` for that row points at the Brother record — not at a firmware-related table.

```bash
sudo mysql -e "
  SELECT a.name, m.name AS manufacturer
  FROM glpi_assets_assets a
  LEFT JOIN glpi_manufacturers m ON m.id = a.manufacturers_id
  WHERE a.name LIKE 'TEST-%';" glpi
```

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### C1c. No Fields-plugin entries pollute the custom-asset Mappings dropdown

**What you'll check**: when the install also has the `fields` (PluginFields) plugin active, its search options are scoped to **native** itemtypes (Computer, Monitor, …). They should NOT bleed into the Field dropdown of a custom-asset mapping. If they do, the dropdown fills up with raw `custom_<key>` rows that don't map to anywhere sensible on the asset.

**Steps**:
1. Confirm the Fields plugin is installed:
   ```bash
   sudo mysql -e "SHOW TABLES LIKE 'glpi_plugin_fields_%';" glpi | head -5
   ```
   If you get rows back, run the rest of this test. If not, mark **N/A**.
2. Open a custom-asset model (any AssetDefinition) and go to its **Mappings** tab.
3. In an unmapped row's **Field** dropdown, type `custom_` in the search.

**Expected**:
- The dropdown returns **no results** matching `custom_…`. Specifically, no entries from any `glpi_plugin_fields_<container>` table appear.
- Native AssetDefinition custom fields (`polka`, `regal`, `Data legalizacji`, …) still appear under their proper labels.
- Sanity-check the log:
  ```bash
  sudo grep 'fields_plugin_drop' /var/log/glpi/datainjection.log | tail -2
  ```
  `fields_plugin_drop` should be **> 0** on installs that have the Fields plugin enabled (or 0 if none of its containers target this asset type).

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### C1d. No bogus `_customfield_<native>` rows in the dropdown

**What you'll check**: an AssetDefinition's **form-display layout** (the JSON config that lists which native columns appear on the form and in what order) must NOT be treated as if each entry were a custom-field definition. Otherwise the mapping dropdown shows native columns *twice* — once correctly (e.g. `Nazwa`) and once wrapped as a bogus `_customfield_name=name` row.

**Steps**:
1. Open a custom-asset model and go to **Mappings**.
2. In any unmapped row's **Field** dropdown, scroll/search for `_customfield_`.

**Expected**:
- Every entry starting with `_customfield_` corresponds to a **real** declared custom field (one row in `glpi_assets_customfielddefinitions` for this definition). Cross-check:
  ```sql
  SELECT system_name, label
    FROM glpi_assets_customfielddefinitions
    WHERE assets_assetdefinitions_id = <YOUR_DEFINITION_ID>;
  ```
- Nothing like `_customfield_name`, `_customfield_states_id`, `_customfield_locations_id`, `_customfield_users_id`, `_customfield_comment` appears — those are native columns the form-display config pins, **not** custom fields.

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### C2. Custom asset with custom fields

**Pre-requisite**: add at least one custom field to your `AssetDefinition` (e.g. a text field `Owner`, a dropdown field `Department`).

**Test data** — save as `mobileprinters_full.csv`:

```csv
name;serial;Owner;Department
PRT-FULL-01;PRTSN-FULL-0001;Alice;IT
PRT-FULL-02;PRTSN-FULL-0002;Bob;Sales
```

**Steps**:
1. Create / edit the model so the custom fields appear in the column dropdown.
2. Map `Owner → Owner` (your custom text field) and `Department → Department` (your custom dropdown).
3. Run the import.

**Expected**:
- Both rows succeed.
- Opening one of the imported assets: the **Owner** text shows correctly, **Department** dropdown shows the resolved value (not just an ID).
- The `custom_fields` JSON column in `glpi_assets_assets` for the new row contains the values:
  ```bash
  sudo mysql -e "SELECT name, custom_fields FROM glpi_assets_assets WHERE name = 'PRT-FULL-01' \G" glpi
  ```

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### C2b. Imported custom-field values render in the asset GUI

**What you'll check**: after a custom-field import, the values are not only in `glpi_assets_assets.custom_fields` JSON but the asset's detail page actually shows them in the dropdowns / fields. GLPI 11 keys that JSON by the **integer id** of each `glpi_assets_customfielddefinitions` row (not by `system_name`); the importer translates `system_name → id` and also fires a direct SQL fallback because GLPI's `prepareInputForUpdate` swallows the array.

**Steps**:
1. Run C2's import.
2. Open one of the imported assets in the GLPI UI.
3. Scroll through the custom-field dropdowns / text fields on the form.

**Expected**:
- Every value you imported is selected / displayed.
- The JSON's keys are numeric (the customfielddefinition IDs), not string `system_name`s:
  ```bash
  sudo mysql -e "
    SELECT name, custom_fields FROM glpi_assets_assets
    WHERE name = '<YOUR_TEST_NAME>';" glpi
  ```
  Expect something like `{"50":59,"49":3}` — id-keyed.
- The fallback log line confirms the direct write fired:
  ```bash
  sudo grep 'direct SQL fallback wrote custom_fields' /var/log/glpi/datainjection.log | tail -3
  ```

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### C2c. Import generates History entries (`glpi_logs`)

**What you'll check**: imported custom-field changes are visible in the asset's **History** tab. Because the direct-SQL fallback bypasses `$item->update()`, GLPI's own history logging would otherwise be skipped — the plugin compensates by calling `Log::history()` itself.

**Steps**:
1. Run a custom-field import (C2 or C5).
2. Inspect the History tab on one of the imported assets in the GLPI UI.
3. Or query `glpi_logs` directly:
   ```bash
   sudo mysql -e "
     SELECT items_id, itemtype, old_value, new_value, date_mod
     FROM glpi_logs
     WHERE itemtype LIKE '%CustomAsset%'
       AND date_mod > NOW() - INTERVAL 1 HOUR
     ORDER BY id DESC LIMIT 10;" glpi
   ```

**Expected**:
- At least one row with `new_value = "Update from CSV file"` per imported asset (the summary row).
- Per-changed-custom-field rows with `new_value` formatted as `"<Field label>: <new value>"` and the previous value in `old_value` (empty on a fresh add).
- The History tab in the GUI renders the same entries.

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### C3. Large custom-asset import (100+ rows) completes end-to-end

> Validated up to **1 000 rows** in production with `pm.max_requests=20` + the JS retry layer. If your batch sizes match, expect ~45 transparent FPM-worker recycles per 1 000 rows, each absorbed by the retry layer with a brief `— retry N/8` flash in the status line.

**Test data** — generate a CSV. Tune the row count to your appetite (100 is a quick smoke; 1000 is the full validation):

```bash
# On the GLPI server, or any machine with bash:
ROWS=100   # bump to 1000 for the full validation
{
  echo 'name;serial;otherserial';
  for i in $(seq 1 $ROWS); do
    printf 'BULK-%04d;SN-BULK-%04d;INV-BULK-%04d\n' $i $i $i;
  done;
} > /tmp/bulk.csv
```

**Steps**:
1. Use the same custom-asset model from C1.
2. Import `/tmp/bulk.csv`.
3. Watch the status line in the UI during the import. Watch `/var/log/glpi/datainjection.log` in another terminal:
   ```bash
   sudo tail -f /var/log/glpi/datainjection.log | grep -E 'inject_batch|loop done'
   ```

**Expected**:
- The import reaches **100% / Success**.
- You **may** see `— retry N/8` text flash in the status line a few times — once per FPM-worker hang the JS layer absorbed. For 1 000 rows this typically happens ~5-10 times. If you see retries marching past `N=6` you are likely on the default `pm.max_requests=0`; check [README → PHP-FPM tuning](README.md#php-fpm-tuning).
- Result page links open the asset detail.
- **No** "Import failed" red banner.
- DB check (the row count should match what you imported):
  ```bash
  sudo mysql -e "
    SELECT COUNT(*) AS imported FROM glpi_assets_assets
    WHERE name LIKE 'BULK-%';" glpi
  ```

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### C4. XLSX format for custom assets

**Test data**: open `mobileprinters.csv` from C1 in Excel / LibreOffice, save as `mobileprinters.xlsx`.

**Steps**:
1. Either reuse the C1 model with filetype changed to `xlsx`, or create a fresh `xlsx`-typed model.
2. Upload + import.

**Expected**:
- Same outcome as C1, but with the XLSX backend.
- Datainjection log shows `backendClass: PluginDatainjectionBackendxlsx`.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Section D — Failure paths

### C5. Fresh AssetDefinition with mandatory custom fields imports cleanly

**What you'll check**: a brand-new AssetDefinition (just created, no assets imported yet) with at least one custom field marked **mandatory** in the model's mapping shouldn't 500 on the first row with `Unknown column '_customfield_<key>' in 'WHERE'`. This was the regression that 2.16.43 fixed — `dataAlreadyInDB()` used to drop virtual `_customfield_*` linkfields into the unique-check SQL as if they were real columns.

**Steps**:
1. Create a new AssetDefinition (Setup → Assets → Asset definitions). Add at least one custom field — e.g. `numer_seryjny_1` of type DropdownType or Text.
2. Build a CSV model for that definition. In the model's Mappings tab, map the CSV's `numer_seryjny_1` column to the custom field AND tick the **Mandatory** checkbox for that mapping.
3. Import a 5–10 row test CSV.

**Expected**:
- All rows succeed.
- Specifically:
  ```bash
  sudo grep -E 'Unknown column.*_customfield_' /var/log/glpi/datainjection.log | tail -5
  ```
  Returns **no matches** after the import time.
- All rows reach `injectLine post` with `status_label: "SUCCESS"`.

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### C6. Re-import same XLSX over existing rows merges values (update path)

**What you'll check**: if you re-import a file whose rows already exist (matched on the model's unique-check columns — typically `name` / `serial`), the importer takes the **update** path: native columns get updated to the new values, and custom-field values are *merged* (existing JSON for fields not in the new file is preserved, fields in the new file overwrite by id key).

**Steps**:
1. Run C2's import once. Verify values land (use C2b's checks).
2. Edit the CSV: change one native column (e.g. `otherserial`) and one custom-field value for one row. Leave the other rows identical.
3. Re-upload.

**Expected**:
- The result table shows action `Update` for all rows (not `Add`).
- No duplicate assets in the GUI list (the row count for `glpi_assets_assets` filtered to your test name prefix is unchanged from after step 1).
- For the row you edited: the new `otherserial` is in the asset's detail page; the changed custom-field shows its new value; other custom fields on that row keep their previous values.
- A diagnostic line per row:
  ```bash
  sudo grep "after \$item->update" /var/log/glpi/datainjection.log | tail -10
  ```
  `sent_custom_fields` should be the **merged** array (existing + new), not just the new values.

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### D1. Wrong delimiter

**Steps**:
1. Use the C1 model (which expects `;`).
2. Upload a CSV with `,` as the delimiter.

**Expected**:
- The upload step shows "The number of columns of the file is incorrect" (or a similar friendly message), not a 500.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### D2. Empty mandatory field

**Test data**:
```csv
name;serial
PRT-EMPTY-01;
```

**Steps**:
1. On the model, mark `serial` as mandatory.
2. Import the file.

**Expected**:
- One row, status **Failed** with reason `MANDATORY` (visible in the result table).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### D3. Abort & start over button

**Steps**:
1. Start a large import (C3's `bulk100.csv`).
2. While the progress bar is moving, click **"Abort and start over"** at the bottom of the page.

**Expected**:
- Returns to the model picker.
- A subsequent import on the same model works without issue (session was cleaned).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Section E — Security gates

> These tests are **safe to run** on a non-production GLPI. They verify the security boundaries the plugin relies on. None of them require special tools beyond the browser DevTools and `curl`.

### D4. Direct-SQL fallback log line fires when expected

**What you'll check**: GLPI 11's `prepareInputForUpdate` consistently drops the `custom_fields` array our plugin hands it. The plugin compensates by running a direct `DB->update(..., ['custom_fields' => json_encode(...)], ...)` whenever it has values to write. The `customimport: direct SQL fallback wrote custom_fields` WARN line is the breadcrumb that the workaround actually fired. If it stops firing while the column shows up empty, the bypass has regressed.

**Steps**:
1. Run any custom-field import (C2, C5, or C6).
2. Immediately after, query the log:
   ```bash
   sudo grep 'direct SQL fallback wrote custom_fields' /var/log/glpi/datainjection.log | tail -10
   ```

**Expected**:
- At least one line per imported row that has at least one custom field set, including the `id` of the asset row and the JSON written.
- DB cross-check confirms the JSON matches what the log claims:
  ```bash
  sudo mysql -e "
    SELECT id, name, custom_fields FROM glpi_assets_assets
    WHERE id = <ID FROM LOG LINE>;" glpi
  ```

**Result row**:

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |

---

### E1. CSRF — upload form refuses requests without a valid token

**What you'll check**: the file-upload endpoint requires a valid CSRF token tied to the current session. Without it, GLPI rejects the request.

**Background — CSRF in 30 seconds**: a CSRF (Cross-Site Request Forgery) attack tricks a logged-in user's browser into making a state-changing request to a site they're authenticated to (e.g. clicking an evil link makes their browser POST to GLPI on their behalf). The defense is a hidden token in every form that only that user's session knows — a forged request can't guess it.

GLPI sets that token automatically for any plugin that registers with `$PLUGIN_HOOKS['csrf_compliant']['datainjection'] = true;`, which this plugin does (see `setup.php`).

**Steps**:

1. Open the import page in the browser. Open DevTools (F12) → **Network** tab → ensure **Preserve log** is checked.
2. Trigger a normal upload (a 3-row CSV is plenty). In the network log, click the `clientinjection.form.php` POST entry.
3. Click the **Payload** tab — you should see a form field named `_glpi_csrf_token` with a long hex value.
4. Copy the **full request as cURL** (right-click the entry → "Copy as cURL").
5. Open a terminal and paste it. Run once unmodified — should respond with `200`.
6. Now **delete** the `_glpi_csrf_token=...` segment from the cURL command and run it again.

**Expected** (modified request):
- HTTP **302** redirect to a GLPI error page, **OR** HTTP **400/403**, **OR** GLPI's generic error page.
- The CSV is **not** imported (verify in Assets → there are no new rows from this attempt).
- `/var/log/glpi/datainjection.log` shows no `processUploadedFile: enter` after the bad request (or shows an immediate abort).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E2. CSRF — abort button is also gated

**Steps**:
1. Start an import so the progress page is up.
2. The "Abort and start over" form has a hidden `_glpi_csrf_token`. View page source (Ctrl+U) and grep for `_glpi_csrf_token` to confirm it's present.
3. Construct a curl POST to `/plugins/datainjection/front/clientinjection.form.php` with `cancel=1` and no token. Use your authenticated session cookie:
   ```bash
   # Get your session cookie value from DevTools → Application → Cookies → glpi_<install>
   curl -i -k \
     -b 'glpi_<install>=<YOUR_SESSION_VALUE>' \
     -d 'cancel=1' \
     'https://<your-glpi-host>/plugins/datainjection/front/clientinjection.form.php'
   ```

**Expected**:
- Response is **302** to an error page or **403**.
- The in-flight import is **not** aborted — go back to the browser tab and refresh; the progress page should still show the in-flight state (until the JS retries make it pass through anyway).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E3. Access control — central-only endpoint

**What you'll check**: `inject_batch.php` requires central-interface access.

**Steps**:
1. Create a test user with only the **Self-service interface** profile (no Central rights).
2. Log in as that user (in a private/incognito window) and copy the session cookie value.
3. Run:
   ```bash
   curl -i -k \
     -b 'glpi_<install>=<SELF_SERVICE_SESSION>' \
     -d 'offset=0&batch_size=1' \
     'https://<your-glpi-host>/plugins/datainjection/ajax/inject_batch.php'
   ```

**Expected**:
- HTTP **302** to login, **OR** HTTP **403**, **OR** a JSON body with `"error": true` and a message about access being denied.
- No row gets inserted.
- `datainjection.log` shows the rejection (look for `inject_batch.php: received` followed by `inject_batch.php failed at offset 0` with a "no central access"-ish exception).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E4. Right enforcement — `plugin_datainjection_use` Read is required

**Steps**:
1. Create a profile **without** the "Injection of the file" Read right.
2. Log in as a user with that profile.
3. Go directly to `…/plugins/datainjection/front/clientinjection.form.php`.

**Expected**:
- GLPI's standard "Access denied" page, not the model picker.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E5. File-upload guards — wrong extension

**Test data**: save a small text file as `bogus.exe`.

**Steps**:
1. Try to upload it on a CSV-typed model.

**Expected**:
- Result message: "File format is wrong. Extension csv required."
- `glpi_plugin_datainjection_uploads/` directory has **no** new file.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E6. File-upload guards — `tmp_name` not a real upload

**Background**: PHP requires `is_uploaded_file($_FILES['filename']['tmp_name'])` to be true before moving a file out of the upload temp dir. This is a defense against path-traversal/attacker-supplied `$_FILES`-spoofing.

**Steps**:
1. (Manual verification) Skim `inc/model.class.php → readUploadedFile()` — it uses `move_uploaded_file()`, which has the `is_uploaded_file` check built in. Confirm the call is there (around line 1059).
2. No runtime attack is required for this check; it's a code-review pass.

**Expected**:
- `grep -n move_uploaded_file inc/model.class.php` returns exactly one match inside `readUploadedFile()`.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E7. Stored-data exposure — log file permissions

**What you'll check**: the plugin's log file isn't world-readable. Imported CSV data passes through it (row previews, mapped values) and could contain PII like usernames or serials.

**Steps**:
```bash
sudo ls -l /var/log/glpi/datainjection.log
```

**Expected**:
- Owner is `www-data` (or your PHP user).
- Mode is **NOT** `666` or `644` — should be `640` or stricter.
- Group is `www-data` or `adm` (whichever your distro uses).

**If too permissive**:
```bash
sudo chmod 640 /var/log/glpi/datainjection.log
sudo chown www-data:adm /var/log/glpi/datainjection.log
```

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E8. SQL injection — try a quote in a mapped value

**Background**: the injection lib passes user values through GLPI's DB layer (`$item->add()`), which parameterises everything. We just verify a quote-containing payload isn't mishandled at the plugin level.

**Test data** — save as `quotes_test.csv`:

```csv
name;serial
TEST-'; DROP TABLE glpi_assets_assets;--;SN-INJECT-1
TEST-"; DROP TABLE glpi_assets_assets;--;SN-INJECT-2
```

**Steps**:
1. Import via a custom-asset model.
2. After import, confirm `glpi_assets_assets` still exists:
   ```bash
   sudo mysql -e "SELECT COUNT(*) FROM glpi_assets_assets;" glpi
   ```
3. Check the imported rows:
   ```bash
   sudo mysql -e "SELECT name FROM glpi_assets_assets WHERE name LIKE 'TEST-%';" glpi
   ```

**Expected**:
- Both rows imported as data. The `name` column contains the literal quote-laden string, **not** a truncated or executed version.
- `glpi_assets_assets` table still has its normal row count (didn't drop).

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E9. XSS — quote-containing name in result table

**Test data** — save as `xss_test.csv`:

```csv
name;serial
"<script>alert('xss')</script>";SN-XSS-1
"<img src=x onerror=alert('xss')>";SN-XSS-2
```

**Steps**:
1. Import via a custom-asset model.
2. After import, the result page displays the imported names.

**Expected**:
- The `<script>` tag and the `<img>` tag are displayed **as literal text** in the result table, not executed.
- No JavaScript alert dialog pops up.
- The asset detail pages, once opened, also display the literal string.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### E10. Session isolation — uploaded file is per-user

**What you'll check**: the temp file from one user's upload isn't readable by another user's import session.

**Steps**:
1. As user A, upload a file but don't run the import (just complete the upload step, then leave the browser tab open).
2. As user B (different browser / incognito), go to **Tools → Data injection**.
3. User B should NOT see user A's filename anywhere, and starting a new model shouldn't pick up user A's session state.

**Expected**:
- User B sees a fresh, empty state.
- `/var/lib/glpi/plugins/datainjection/` (or `PLUGIN_DATAINJECTION_UPLOAD_DIR`) contains the temp file with `www-data` ownership; it's not directly user-accessible from a browser URL.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Section F — Operational

### F1. Log file rotates at 5 MB

**Steps**:
1. Inflate the log to >5 MB to force a rotation:
   ```bash
   sudo dd if=/dev/zero of=/var/log/glpi/datainjection.log bs=1M count=6 conv=notrunc
   ```
2. Trigger any logger call (e.g. visit the import page).

**Expected**:
- `ls /var/log/glpi/datainjection.log*` shows the live file plus at least one `.1` (or `.2`/`.3`) segment.
- The live file is <5 MB again.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

### F2. No PHP warnings during a normal import

**Steps**:
1. `sudo grep -v ' \[INFO\] ' /var/log/glpi/datainjection.log > /tmp/before.txt`
2. Run a normal 10-row import.
3. `sudo grep -v ' \[INFO\] ' /var/log/glpi/datainjection.log > /tmp/after.txt`
4. `diff /tmp/before.txt /tmp/after.txt`

**Expected**:
- The diff shows zero new lines from the plugin's own warnings (the `getFieldValue: skipping dropdown lookup for abstract class` entries are expected — they're informational, not bugs).
- No `Undefined array key`, no `Trying to access array offset on null`, no PHP fatal entries.

**Result row** (copy + fill into the Master results table at the top, plus add any longer detail below):

| Status | Tester | Date | Notes |
|--------|--------|------|-------|
|        |        |      |       |


---

## Final sign-off

Cross-reference the per-test Master results table at the top. Roll up to a section verdict here.

| Section                      | Result (Pass / Fail / Partial / N/A) | Section notes |
|------------------------------|--------------------------------------|---------------|
| A — Smoke                    |                                      |               |
| B — Native CSV               |                                      |               |
| C — Custom assets            |                                      |               |
| D — Failure paths            |                                      |               |
| E — Security                 |                                      |               |
| F — Operational              |                                      |               |

Environment captured at the time of testing:

| Item                    | Value |
|-------------------------|-------|
| Plugin version          |       |
| GLPI version            |       |
| PHP version             |       |
| FPM `pm.max_requests`   |       |
| FPM `pm.max_children`   |       |
| Tester name             |       |
| Test date(s)            |       |

**Overall verdict**: ☐ Ship it  ☐ Ship with caveats  ☐ Block ship

**Caveats / outstanding issues** (list the test IDs and a one-line summary; full details stay in each test's Notes row):
