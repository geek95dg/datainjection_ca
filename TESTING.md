# Datainjection — Tester Q&A

A pre-release acceptance checklist. Designed so a non-developer can walk through every user-facing feature plus the security gates. Each section has:

- **What you'll check** — one-line intent.
- **Steps** — copy-paste-able instructions, screenshots optional.
- **Expected result** — what success looks like.
- **Pass / Fail** — your verdict.
- **Notes** — paste error messages, screenshots, log snippets here.

> Skip notation: each item is independent. If a test section doesn't apply to your install (e.g. you don't use Genericobject), mark it **N/A** and move on.

Suggested tooling for the security tests:

- A browser with **DevTools** (F12 in Chrome, Firefox, Edge).
- **curl** on the command line. Comes pre-installed on macOS / Linux; on Windows use WSL or the bundled `curl.exe`.
- An SSH/terminal session to the GLPI server for log inspection.
- **No** Burp Suite / sqlmap / etc. is required — every test below uses only the browser and curl.

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

**Pass / Fail**: ☐
**Notes**: 

---

### A2. Menu item appears for a permitted user

**What you'll check**: the new menu entry routes correctly.

**Steps**:
1. Log in as a user whose profile has the **"Injection of the file" → Read** right ticked (Setup → Profile → Data injection tab).
2. Top menu → **Tools → Data injection**.

**Expected**:
- A page titled "Data injection" opens at `…/plugins/datainjection/front/clientinjection.form.php`.
- A model picker dropdown is visible.

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

---

### B2. Re-import the same file (update path)

**Steps**:
1. Edit `computers.csv` — change `INV-0001` to `INV-0001-UPDATED`.
2. Re-upload the same file with the same model.

**Expected**:
- Result table status is **Success** with action **Update** (not Add).
- Computer `TEST-COMP-01` now has `INV-0001-UPDATED` as its inventory number.
- No duplicate rows in Assets → Computers.

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

---

### C3. Large custom-asset import — confirms FPM tuning + retry layer

**Test data** — generate a CSV with 100 rows:

```bash
# On the GLPI server, or any machine with bash:
{
  echo 'name;serial;otherserial';
  for i in $(seq 1 100); do
    printf 'BULK-%03d;SN-BULK-%03d;INV-BULK-%03d\n' $i $i $i;
  done;
} > /tmp/bulk100.csv
```

**Steps**:
1. Use the same custom-asset model from C1.
2. Import `bulk100.csv`.
3. Watch the status line in the UI during the import. Watch `/var/log/glpi/datainjection.log` in another terminal:
   ```bash
   sudo tail -f /var/log/glpi/datainjection.log | grep -E 'inject_batch|loop done'
   ```

**Expected**:
- The import reaches **100% / Success**.
- You **may** see `— retry N/8` text flash in the status line once or twice during the run, especially if your FPM `pm.max_requests` is **not** set to 20 (default 0). This is the JS retry layer absorbing the GLPI-core hang we discussed in [README → PHP-FPM tuning](README.md#php-fpm-tuning).
- Result page links open the asset detail.
- **No** "Import failed" red banner.

**Pass / Fail**: ☐
**Notes**: 

---

### C4. XLSX format for custom assets

**Test data**: open `mobileprinters.csv` from C1 in Excel / LibreOffice, save as `mobileprinters.xlsx`.

**Steps**:
1. Either reuse the C1 model with filetype changed to `xlsx`, or create a fresh `xlsx`-typed model.
2. Upload + import.

**Expected**:
- Same outcome as C1, but with the XLSX backend.
- Datainjection log shows `backendClass: PluginDatainjectionBackendxlsx`.

**Pass / Fail**: ☐
**Notes**: 

---

## Section D — Failure paths

### D1. Wrong delimiter

**Steps**:
1. Use the C1 model (which expects `;`).
2. Upload a CSV with `,` as the delimiter.

**Expected**:
- The upload step shows "The number of columns of the file is incorrect" (or a similar friendly message), not a 500.

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

---

### D3. Abort & start over button

**Steps**:
1. Start a large import (C3's `bulk100.csv`).
2. While the progress bar is moving, click **"Abort and start over"** at the bottom of the page.

**Expected**:
- Returns to the model picker.
- A subsequent import on the same model works without issue (session was cleaned).

**Pass / Fail**: ☐
**Notes**: 

---

## Section E — Security gates

> These tests are **safe to run** on a non-production GLPI. They verify the security boundaries the plugin relies on. None of them require special tools beyond the browser DevTools and `curl`.

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

---

### E4. Right enforcement — `plugin_datainjection_use` Read is required

**Steps**:
1. Create a profile **without** the "Injection of the file" Read right.
2. Log in as a user with that profile.
3. Go directly to `…/plugins/datainjection/front/clientinjection.form.php`.

**Expected**:
- GLPI's standard "Access denied" page, not the model picker.

**Pass / Fail**: ☐
**Notes**: 

---

### E5. File-upload guards — wrong extension

**Test data**: save a small text file as `bogus.exe`.

**Steps**:
1. Try to upload it on a CSV-typed model.

**Expected**:
- Result message: "File format is wrong. Extension csv required."
- `glpi_plugin_datainjection_uploads/` directory has **no** new file.

**Pass / Fail**: ☐
**Notes**: 

---

### E6. File-upload guards — `tmp_name` not a real upload

**Background**: PHP requires `is_uploaded_file($_FILES['filename']['tmp_name'])` to be true before moving a file out of the upload temp dir. This is a defense against path-traversal/attacker-supplied `$_FILES`-spoofing.

**Steps**:
1. (Manual verification) Skim `inc/model.class.php → readUploadedFile()` — it uses `move_uploaded_file()`, which has the `is_uploaded_file` check built in. Confirm the call is there (around line 1059).
2. No runtime attack is required for this check; it's a code-review pass.

**Expected**:
- `grep -n move_uploaded_file inc/model.class.php` returns exactly one match inside `readUploadedFile()`.

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

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

**Pass / Fail**: ☐
**Notes**: 

---

## Final sign-off

| | Result |
|---|---|
| Sections A (smoke) | ☐ Pass / ☐ Fail |
| Section B (native import) | ☐ Pass / ☐ Fail |
| Section C (custom assets) | ☐ Pass / ☐ Fail |
| Section D (failure paths) | ☐ Pass / ☐ Fail |
| Section E (security) | ☐ Pass / ☐ Fail |
| Section F (ops) | ☐ Pass / ☐ Fail |

**Plugin version tested**: __________
**GLPI version**: __________
**PHP version**: __________
**Tester name**: __________
**Date**: __________

**Overall verdict**: ☐ Ship it / ☐ Block ship / ☐ Ship with caveats (note them below)

**Caveats / outstanding issues**:
