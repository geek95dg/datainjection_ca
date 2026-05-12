# GLPI Plugin Development Guide

Practical reference for building GLPI 11.x plugins. Based on real production experience — every pattern, gotcha, and rule here comes from working code, not theory. Written for AI assistants and developers starting new GLPI plugin projects.

**Target:** GLPI 11.0–11.99, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+

---

## Table of Contents

1. [Plugin Structure](#1-plugin-structure)
2. [setup.php — Registration & Hooks](#2-setupphp--registration--hooks)
3. [hook.php — Install, Upgrade, Uninstall](#3-hookphp--install-upgrade-uninstall)
4. [Classes (inc/*.class.php)](#4-classes)
5. [Front Controllers (front/*.php)](#5-front-controllers)
6. [AJAX Endpoints (ajax/*.php)](#6-ajax-endpoints)
7. [Database Access](#7-database-access)
8. [GLPI Hook System](#8-glpi-hook-system)
9. [Permissions & Rights](#9-permissions--rights)
10. [Sessions & Authentication](#10-sessions--authentication)
11. [Translations (i18n)](#11-translations)
12. [Frontend (JS/CSS)](#12-frontend)
13. [File & Document Handling](#13-file--document-handling)
14. [PDF Generation](#14-pdf-generation)
15. [Notifications](#15-notifications)
16. [Logging](#16-logging)
17. [Security Checklist](#17-security-checklist)
18. [What Does NOT Work / Forbidden Patterns](#18-what-does-not-work--forbidden-patterns)
19. [Common Gotchas](#19-common-gotchas)
20. [Useful Constants & Paths](#20-useful-constants--paths)
21. [GLPI REST API (v1) for Plugins](#21-glpi-rest-api-v1-for-plugins)
22. [Anonymous/Public Endpoints](#22-anonymouspublic-endpoints)

---

## 1. Plugin Structure

```
myplugin/                         # Plugin root (inside GLPI's plugins/ directory)
├── setup.php                     # Plugin registration, hooks, version info (REQUIRED)
├── hook.php                      # DB install/upgrade/uninstall (REQUIRED)
├── front/                        # User-facing pages (routing entry points)
│   ├── page.php                  # Display page
│   └── page.form.php            # POST handler for page
├── inc/                          # Core classes (auto-discovered by GLPI)
│   └── classname.class.php      # Must follow naming convention exactly
├── ajax/                         # AJAX endpoints
│   └── endpoint.php
├── public/                       # Static assets
│   ├── css/
│   └── js/
└── locales/                      # Gettext .po translation files
    ├── en_GB.po
    └── pl_PL.po
```

**No build tools.** GLPI plugins use plain PHP, CSS, and JS. No npm, no Composer, no webpack. Edit files directly.

**GLPI autoloader discovers classes from `inc/`** by filename convention — no manual autoloading needed.

---

## 2. setup.php — Registration & Hooks

This file is loaded on every GLPI page load when the plugin is active. Keep it lightweight.

### Required Functions

```php
<?php

define('PLUGIN_MYPLUGIN_VERSION', '1.0.0');

function plugin_version_myplugin(): array {
    return [
        'name'         => 'My Plugin',
        'version'      => PLUGIN_MYPLUGIN_VERSION,
        'author'       => 'Author Name',
        'license'      => 'GPLv3',
        'homepage'     => 'https://example.com',
        'requirements' => [
            'glpi' => ['min' => '11.0', 'max' => '11.99'],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_myplugin_check_prerequisites(): bool {
    return true; // Add version checks if needed
}

function plugin_myplugin_check_config($verbose = false): bool {
    return true; // Validate config state
}
```

### plugin_init Function

```php
function plugin_init_myplugin(): void {
    global $PLUGIN_HOOKS;

    // REQUIRED: Declare CSRF compliance
    $PLUGIN_HOOKS['csrf_compliant']['myplugin'] = true;

    // Register classes for GLPI autoloader
    Plugin::registerClass('PluginMypluginProfile', ['addtabon' => ['Profile']]);

    // Menu entry (appears under Tools)
    $PLUGIN_HOOKS['menu_toadd']['myplugin'] = ['tools' => 'PluginMypluginMenu'];

    // Config page (gear icon in Setup > Plugins)
    $PLUGIN_HOOKS['config_page']['myplugin'] = 'front/config.php';

    // CSS/JS — always append version to bust cache
    $v = PLUGIN_MYPLUGIN_VERSION;
    $PLUGIN_HOOKS['add_css']['myplugin'] = ["public/css/myplugin.css?v={$v}"];
    $PLUGIN_HOOKS['add_javascript']['myplugin'] = ["public/js/myplugin.js?v={$v}"];

    // Hook registrations (see Section 8 for details)
    $PLUGIN_HOOKS['item_update']['myplugin'] = [
        'Ticket' => 'PluginMypluginTicket::onTicketUpdate',
    ];
    $PLUGIN_HOOKS['pre_item_update']['myplugin'] = [
        'Ticket' => 'PluginMypluginTicket::onPreTicketUpdate',
    ];

    // IMPORTANT: Wrap permission checks in try-catch.
    // Tables don't exist during install — any DB query will throw.
    if (Session::getLoginUserID()) {
        try {
            $canAccess = PluginMypluginProfile::hasRight('right_feature', READ);
        } catch (\Throwable $e) {
            $canAccess = false;
        }
    }
}
```

### Key Rules

- `csrf_compliant` **must** be set to `true` or GLPI blocks all POST requests.
- Always append `?v=VERSION` to CSS/JS includes to prevent browser caching stale files.
- Wrap ALL DB-dependent code in `try-catch` — plugin_init runs during install when tables don't exist yet.
- Keep plugin_init fast — it runs on every page load.

---

## 3. hook.php — Install, Upgrade, Uninstall

### Install

```php
function plugin_myplugin_install(): bool {
    global $DB;

    // Create tables (use IF NOT EXISTS for idempotency)
    if (!$DB->tableExists('glpi_plugin_myplugin_items')) {
        $DB->doQuery("
            CREATE TABLE IF NOT EXISTS `glpi_plugin_myplugin_items` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`          VARCHAR(255) NOT NULL DEFAULT '',
                `status`        VARCHAR(50)  NOT NULL DEFAULT 'active',
                `users_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` DATETIME     DEFAULT NULL,
                `date_mod`      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `users_id` (`users_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // Insert default config values
    $defaults = [
        'setting_name' => 'default_value',
        'feature_enabled' => '1',
    ];
    foreach ($defaults as $key => $value) {
        $existing = $DB->request([
            'FROM'  => 'glpi_plugin_myplugin_configs',
            'WHERE' => ['config_key' => $key],
            'LIMIT' => 1,
        ]);
        if (count($existing) === 0) {
            $DB->insert('glpi_plugin_myplugin_configs', [
                'config_key' => $key,
                'value'      => $value,
            ]);
        }
    }

    // Set default permissions for known profiles
    // Super-Admin=4, Admin=3 typically get full rights, Technician=6 gets limited
    return true;
}
```

### Upgrade

```php
function plugin_myplugin_upgrade(string $fromVersion): bool {
    global $DB;

    // Guard each migration by checking if the change is already applied
    if (!$DB->fieldExists('glpi_plugin_myplugin_items', 'new_column')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_myplugin_items`
            ADD `new_column` VARCHAR(100) NOT NULL DEFAULT '' AFTER `status`");
    }

    // For new config keys
    $newConfigs = ['new_setting' => 'default'];
    foreach ($newConfigs as $key => $value) {
        $exists = $DB->request([
            'FROM' => 'glpi_plugin_myplugin_configs',
            'WHERE' => ['config_key' => $key],
            'LIMIT' => 1,
        ]);
        if (count($exists) === 0) {
            $DB->insert('glpi_plugin_myplugin_configs', [
                'config_key' => $key,
                'value' => $value,
            ]);
        }
    }

    return true;
}
```

### Uninstall

```php
function plugin_myplugin_uninstall(): bool {
    global $DB;

    $tables = [
        'glpi_plugin_myplugin_items',
        'glpi_plugin_myplugin_configs',
        'glpi_plugin_myplugin_profiles',
    ];
    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    return true;
}
```

### Key Rules

- Always use `IF NOT EXISTS` / `$DB->tableExists()` / `$DB->fieldExists()` — install and upgrade must be idempotent (safe to run multiple times).
- Always specify `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
- Never use version string comparisons for migrations — check the actual DB state (`fieldExists`, `tableExists`).
- Add indexes on foreign key columns and frequently filtered columns.

---

## 4. Classes

### Naming Convention (MANDATORY)

```
Class name:  PluginMypluginFeature
File name:   inc/feature.class.php
Table name:  glpi_plugin_myplugin_features
```

GLPI's autoloader matches these patterns exactly. Any deviation breaks auto-discovery.

### Base Pattern

```php
<?php

class PluginMypluginFeature extends CommonDBTM
{
    public static $rightname = 'plugin_myplugin_feature';

    public static function getTypeName($nb = 0): string {
        return __('Feature', 'myplugin');
    }
}
```

### Adding a Tab to an Existing GLPI Item

```php
class PluginMypluginProfile extends CommonDBTM
{
    // Register in setup.php: Plugin::registerClass('PluginMypluginProfile', ['addtabon' => ['Profile']]);

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string {
        if ($item instanceof Profile) {
            return __('My Plugin', 'myplugin');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        if ($item instanceof Profile) {
            self::showForProfile($item);
        }
        return true;
    }
}
```

### Menu Registration

```php
class PluginMypluginMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string {
        return __('My Plugin', 'myplugin');
    }

    public static function getMenuName(): string {
        return __('My Plugin', 'myplugin');
    }

    public static function getMenuContent(): array {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/myplugin/front/page.php',
            'icon'  => 'fas fa-box',
        ];
        // Add sub-items if needed
        if (Session::haveRight('config', READ)) {
            $menu['options']['config'] = [
                'title' => __('Configuration', 'myplugin'),
                'page'  => '/plugins/myplugin/front/config.php',
                'icon'  => 'fas fa-cog',
            ];
        }
        return $menu;
    }
}
```

---

## 5. Front Controllers

Files in `front/` are the entry points for user-facing pages.

### Display Page (front/page.php)

```php
<?php
include_once(__DIR__ . '/../../../inc/includes.php');

// Check login
if (!Session::getLoginUserID()) {
    Html::displayRightError();
    exit;
}

// Check rights (always wrap in try-catch)
$canAccess = false;
try {
    $canAccess = PluginMypluginProfile::hasRight('right_feature', READ);
} catch (\Throwable $e) {
    $canAccess = false;
}
if (!$canAccess) {
    Html::displayRightError();
    exit;
}

// Display page
Html::header(__('My Plugin', 'myplugin'), $_SERVER['PHP_SELF'], 'tools', 'PluginMypluginMenu');
PluginMypluginFeature::showForm();
Html::footer();
```

### Form Handler (front/page.form.php)

```php
<?php
include_once(__DIR__ . '/../../../inc/includes.php');

/**
 * CRITICAL: Do NOT call Session::checkCRSF() here!
 *
 * GLPI's inc/includes.php automatically validates and CONSUMES the CSRF
 * token for ALL POST requests to /front/ URLs. The token is stored in
 * $_SESSION['glpicsrftokens'] and removed after validation.
 * Calling checkCRSF() again will FAIL because the token pool is empty.
 */

if (!Session::getLoginUserID()) {
    Html::displayRightError();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = PluginMypluginFeature::handleForm($_POST);
    Html::back(); // Redirect back to the form
}
```

### CRITICAL RULE: Never Call Session::checkCRSF() in /front/ Files

GLPI's bootstrap (`inc/includes.php`, lines ~155-169) automatically validates CSRF tokens for all POST requests routed through `/front/`. Calling `Session::checkCRSF()` a second time causes a failure because the token has already been consumed from the session token pool (`$_SESSION['glpicsrftokens']`).

### GLPI 11 Slim Router: /front/ Files Are NOT Served Directly

In GLPI 11, **all requests** (including `/front/*.php`) are routed through `public/index.php` by the Slim framework router. Dropping a custom PHP file into `/front/` does NOT make it directly accessible — the Slim router intercepts the URL, can't find a matching route, and returns either a 404 ("Item not found") or a login redirect.

The only files that work in `/front/` are those GLPI's own router knows about (like `login.php`, `lostpassword.php`). Plugin endpoints should live in `/ajax/` (for authenticated AJAX) or be exposed via the GLPI REST API (see [Section 21](#21-glpi-rest-api-v1-for-plugins)).

**What also doesn't work:** putting `.php` files in GLPI's `/public/` directory. nginx typically serves PHP from `/public/` as raw downloads (no FastCGI processing configured for that directory), while `.html` files are served correctly.

---

## 6. AJAX Endpoints

```php
<?php
// ajax/getData.php
include_once(__DIR__ . '/../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

// Authentication check (no CSRF needed for AJAX)
if (!Session::getLoginUserID()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    return;
}

try {
    $param = $_GET['param'] ?? '';
    $result = PluginMypluginFeature::getData($param);
    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
```

### Key Rules

- AJAX endpoints do **not** need CSRF validation — session authentication is sufficient.
- Always set `Content-Type: application/json; charset=utf-8`.
- Always check `Session::getLoginUserID()` and return 403 if not authenticated.
- Wrap in try-catch to prevent PHP errors from corrupting JSON output.

### Avoid `$_GET['id']` in Custom Endpoints

GLPI's front-controller and routing middleware automatically reads `$_GET['id']` and tries to load a GLPI item with that ID. If your endpoint uses `id` as a parameter name, GLPI may intercept the request and show "Item not found" before your code runs. Use a different parameter name:

```php
// WRONG — GLPI intercepts ?id=42
$transferId = (int)($_GET['id'] ?? 0);

// CORRECT — no collision with GLPI's routing
$transferId = (int)($_GET['transfer_id'] ?? 0);
```

### Public-Endpoint Flags (Anonymous Access)

These flags tell GLPI's middleware to skip session/CSRF/referer checks. They must be set **before** `include_once('inc/includes.php')`:

```php
$SECURITY_STRATEGY   = 'no_check';   // GLPI 11 SecurityMiddleware: skip auth
$USEDBREPLICATE      = 1;            // ok to serve from DB replica
$DONOTCHECKDBSTATUS  = 1;            // don't bounce on DB status flags
$AJAX_INCLUDE        = 1;            // declare AJAX context
define('DO_NOT_CHECK_HTTP_REFERER', 1);

include_once(__DIR__ . '/../../../inc/includes.php');
```

**IMPORTANT:** These flags only work at the **PHP level**. If nginx blocks anonymous requests to `/plugins/*` at the web-server level (302 redirect to login before PHP runs), these flags are useless. See [Section 22](#22-anonymouspublic-endpoints) for the nginx-bypassing approach.

---

## 7. Database Access

GLPI provides a `$DB` global object. **Never use raw `mysqli_*` calls.**

### Read (SELECT)

```php
global $DB;

// Simple query
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_items',
    'WHERE' => ['status' => 'active', 'users_id' => $userId],
    'ORDER' => 'name ASC',
    'LIMIT' => 50,
]);
foreach ($rows as $row) {
    $id = $row['id'];
    $name = $row['name'];
}

// With JOIN
$rows = $DB->request([
    'SELECT' => ['i.id', 'i.name', 'u.realname'],
    'FROM'   => 'glpi_plugin_myplugin_items AS i',
    'LEFT JOIN' => [
        'glpi_users AS u' => [
            'FKEY' => ['i' => 'users_id', 'u' => 'id'],
        ],
    ],
    'WHERE' => ['i.status' => 'active'],
]);

// LIKE search (escape user input)
$pattern = '%' . $DB->escape($searchTerm) . '%';
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_items',
    'WHERE' => [
        'OR' => [
            ['name'   => ['LIKE', $pattern]],
            ['serial' => ['LIKE', $pattern]],
        ],
    ],
]);

// IN clause
$rows = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_items',
    'WHERE' => ['id' => [1, 2, 3, 4]],  // Generates IN (1,2,3,4)
]);

// Count results
$count = count($DB->request([...]));
```

### Insert

```php
$DB->insert('glpi_plugin_myplugin_items', [
    'name'          => $name,
    'status'        => 'active',
    'users_id'      => (int)$userId,
    'date_creation' => date('Y-m-d H:i:s'),
    'date_mod'      => date('Y-m-d H:i:s'),
]);
$newId = $DB->insertId();
```

### Update

```php
$DB->update(
    'glpi_plugin_myplugin_items',
    ['status' => 'completed', 'date_mod' => date('Y-m-d H:i:s')],  // SET
    ['id' => $itemId]  // WHERE
);
```

### Delete

```php
$DB->delete('glpi_plugin_myplugin_items', ['id' => $itemId]);
```

### Schema Checks

```php
$DB->tableExists('glpi_plugin_myplugin_items');    // true/false
$DB->fieldExists('glpi_plugin_myplugin_items', 'new_column');  // true/false
```

### Key Rules

- All values passed in arrays are auto-escaped — no manual escaping needed for insert/update/where.
- Use `$DB->escape()` only for LIKE patterns or raw `doQuery()` calls.
- Always cast integer IDs: `(int)$userId`.
- `$DB->request()` returns an iterator — use `foreach` or `count()`.
- For raw SQL (migrations only): `$DB->doQuery("ALTER TABLE ...")`.

---

## 8. GLPI Hook System

### Registration (in setup.php)

```php
// Post-action hooks (fired AFTER the DB write)
$PLUGIN_HOOKS['item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::afterTicketUpdate'];
$PLUGIN_HOOKS['item_add']['myplugin']    = ['Ticket' => 'PluginMypluginHook::afterTicketAdd'];

// Pre-action hooks (fired BEFORE the DB write — can block the operation)
$PLUGIN_HOOKS['pre_item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::beforeTicketUpdate'];
$PLUGIN_HOOKS['pre_item_add']['myplugin']    = ['ITILSolution' => 'PluginMypluginHook::beforeSolutionAdd'];
```

### Hook Execution Order

```
1. pre_item_add / pre_item_update    ← Can block the operation
2. Database write happens
3. item_add / item_update            ← Post-action, informational only
```

### The fields[] vs input[] Rule (CRITICAL)

This is the single most important thing to understand about GLPI hooks:

```php
public static function afterTicketUpdate(Ticket $ticket): void {
    // $ticket->fields  = OLD values (current DB state BEFORE update)
    // $ticket->input   = NEW values (what is being written)

    $oldStatus = (int)($ticket->fields['status'] ?? 0);
    $newStatus = (int)($ticket->input['status'] ?? 0);

    if ($newStatus !== $oldStatus && $newStatus === CommonITILObject::CLOSED) {
        // Ticket was just closed — react to it
    }
}
```

| Context | `$item->fields` | `$item->input` |
|---------|-----------------|----------------|
| `pre_item_update` | Old DB values | New values being applied |
| `item_update` | Old DB values | New values just applied |
| `pre_item_add` | Empty/unset | Values being inserted |
| `item_add` | Values just inserted | Values just inserted |

### Blocking Operations in pre_ Hooks

```php
public static function beforeTicketUpdate(Ticket $ticket): void {
    if ($shouldBlockStatusChange) {
        // Remove the field from input to prevent it from being saved
        unset($ticket->input['status']);
        Session::addMessageAfterRedirect(__('Cannot close this ticket yet.', 'myplugin'), true, ERROR);
    }
}

public static function beforeSolutionAdd(ITILSolution $solution): void {
    if ($shouldBlockSolution) {
        // Set input to false to completely prevent the add
        $solution->input = false;
        Session::addMessageAfterRedirect(__('Add a follow-up first.', 'myplugin'), true, ERROR);
    }
}
```

### Ticket Status Detection — Cover All Paths

Ticket status changes can happen through multiple paths. Register hooks for all of them:

```php
// Direct status field update
$PLUGIN_HOOKS['item_update']['myplugin']['Ticket'] = 'PluginMypluginHook::afterTicketUpdate';
$PLUGIN_HOOKS['pre_item_update']['myplugin'] = ['Ticket' => 'PluginMypluginHook::beforeTicketUpdate'];

// Adding a solution (which changes status to SOLVED)
$PLUGIN_HOOKS['pre_item_add']['myplugin'] = ['ITILSolution' => 'PluginMypluginHook::beforeSolutionAdd'];
$PLUGIN_HOOKS['item_add']['myplugin']['ITILSolution'] = 'PluginMypluginHook::afterSolutionAdd';
```

### Available Hook Names

| Hook | Fires | Can Block? |
|------|-------|-----------|
| `pre_item_add` | Before DB insert | Yes (`$item->input = false`) |
| `item_add` | After DB insert | No |
| `pre_item_update` | Before DB update | Yes (`unset($item->input['field'])`) |
| `item_update` | After DB update | No |
| `pre_item_purge` | Before permanent delete | Yes |
| `item_purge` | After permanent delete | No |
| `pre_item_delete` | Before soft delete (trash) | Yes |
| `item_delete` | After soft delete | No |

### Hook Idempotency — Handlers Fire Multiple Times

When a technician closes a ticket by adding a solution, GLPI fires **both** `item_update` on Ticket AND `item_add` on ITILSolution in the same request. If GLPI also auto-promotes the solution status to "closed", a third hook invocation may fire. Any state-mutating handler (stock changes, DB inserts, state flips) must be **idempotent** or it will run 2–3 times.

**Pattern: idempotency flag on DB rows**

```php
// Add a tinyint 'processed' column to your items table.
// Before mutating, check and set the flag atomically:

$items = $DB->request([
    'FROM'  => 'glpi_plugin_myplugin_items',
    'WHERE' => ['transfer_id' => $id, 'processed' => 0],
]);
foreach ($items as $item) {
    doExpensiveOperation($item);
    $DB->update('glpi_plugin_myplugin_items',
        ['processed' => 1], ['id' => $item['id']]);
}
// Second hook invocation finds no unprocessed rows → no-op.
```

### post_updateItem() for API-Triggered Logic

When the GLPI REST API does a PUT on your plugin item, GLPI calls `$item->update()` which triggers `post_updateItem()`. Use `$this->oldvalues` to detect which fields changed:

```php
public function post_updateItem($history = true)
{
    parent::post_updateItem($history);

    // Detect: confirmation_token was cleared while status is pending
    if (isset($this->oldvalues['confirmation_token'])
        && !empty($this->oldvalues['confirmation_token'])
        && empty($this->fields['confirmation_token'])
        && $this->fields['status'] === 'pending_email')
    {
        // Token cleared via API → run finalization
        self::finalise((int) $this->fields['id']);
    }
}
```

This is the correct way to trigger server-side logic from a client-side API call. Do NOT set `status='completed'` via PUT and expect finalization to run — the guard clause in your finalization method will see the status is already "completed" and skip.

---

## 9. Permissions & Rights

### Bitmask Constants

```php
READ               = 1
CREATE             = 2
UPDATE             = 4
DELETE             = 8
PURGE              = 16
ALLSTANDARDRIGHT   = 31   // READ + CREATE + UPDATE + DELETE + PURGE
```

### Checking Rights

```php
// GLPI built-in rights
Session::haveRight('config', READ);        // Global config access
Session::haveRight('ticket', CREATE);      // Can create tickets
Session::haveRight('profile', UPDATE);     // Can edit profiles

// Plugin custom rights — check against your profiles table
// Use bitmask AND:
if (($userRight & CREATE) === CREATE) {
    // User has CREATE permission
}
```

### Profile Rights Storage

Store a rights row per GLPI profile:

```sql
CREATE TABLE `glpi_plugin_myplugin_profiles` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `profiles_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `right_feature`  INT UNSIGNED NOT NULL DEFAULT 0,
    `right_config`   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `profiles_id` (`profiles_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Default Permissions on Install

```php
// Super-Admin (profile ID 4) and Admin (3): full rights
// Technician (6): feature rights only, read-only config
$DB->insert('glpi_plugin_myplugin_profiles', [
    'profiles_id'   => 4,
    'right_feature' => ALLSTANDARDRIGHT,
    'right_config'  => ALLSTANDARDRIGHT,
]);
```

### Layered Permission Checking

Always check both plugin rights AND GLPI global rights as fallback:

```php
$canConfig = false;
try {
    $canConfig = PluginMypluginProfile::hasRight('right_config', UPDATE);
} catch (\Throwable $e) {}

// Fallback: GLPI sysadmin can always access (profile/UPDATE covers Admin
// profiles which don't have config/UPDATE in default GLPI).
$isSysAdmin = Session::haveRight('config', UPDATE)
           || Session::haveRight('profile', UPDATE);
if (!$canConfig && !$isSysAdmin) {
    Html::displayRightError();
    exit;
}
```

### Custom Profiles Table vs GLPI API

If your plugin uses a custom profiles table (not `glpi_profilerights`), the GLPI REST API's `canView()` / `canUpdate()` checks will fail because your `$rightname` isn't registered in the native system. Override these methods on your CommonDBTM class:

```php
public static function canView(): bool
{
    try {
        if (PluginMypluginProfile::hasRight('right_feature', READ)) return true;
    } catch (\Throwable $e) {}
    return Session::haveRight('config', UPDATE)
        || Session::haveRight('profile', UPDATE);
}

public static function canUpdate(): bool
{
    try {
        if (PluginMypluginProfile::hasRight('right_feature', UPDATE)) return true;
    } catch (\Throwable $e) {}
    return Session::haveRight('config', UPDATE);
}
```

Without these overrides, `GET /api.php/v1/PluginMypluginItem/42` returns 404 even when the item exists — the API thinks the user has no rights.

### Permissions Form — Use Plain HTML, Not Profile::dropdownRight()

`Profile::dropdownRight()` with bracket-notation field names (e.g. `perm[42][right_transfer]`) does NOT round-trip correctly through POST in all GLPI versions. The `$_POST` data arrives empty.

Use plain `<select>` elements with explicit bitmask values and the HTML5 `form=""` attribute to bind selects in sibling `<td>` cells to a form in the Actions cell:

```php
// Each profile row is its own form (one Save button per row)
echo "<form id='et-perm-form-{$pid}' method='post' action='...'>";
echo Html::hidden('profiles_id', ['value' => $pid]);
echo "<button type='submit' name='update_permissions'>Save</button>";
Html::closeForm();  // CRITICAL: injects CSRF token. Never use raw </form>.

// Selects in sibling cells bind via form= attribute
echo "<select name='right_transfer' form='et-perm-form-{$pid}'>";
echo "<option value='0'>No access</option>";
echo "<option value='1'>Read</option>";
echo "<option value='7'>Read + Update + Create</option>";
echo "<option value='31'>Full (CRUD + Purge)</option>";
echo "</select>";
```

### CSRF: Always Use Html::closeForm()

**Never** close a form with raw `</form>`. GLPI's `Html::closeForm()` injects the `_glpi_csrf_token` hidden field. Without it, every POST to `/front/` returns 403 "Akcja nie jest możliwa" (Action not allowed).

---

## 10. Sessions & Authentication

### Session Data Access

```php
$userId    = Session::getLoginUserID();                    // 0 if not logged in
$profileId = $_SESSION['glpiactiveprofile']['id'] ?? 0;    // Active profile ID
$entityId  = $_SESSION['glpiactive_entity'] ?? 0;          // Active entity ID
$userName  = $_SESSION['glpiname'] ?? '';                   // Login username
$language  = $_SESSION['glpilanguage'] ?? 'en_GB';         // User language
```

### Getting User Details

```php
$user = new User();
if ($user->getFromDB($userId)) {
    $fullName = $user->getFriendlyName();           // "First Last"
    $email    = UserEmail::getDefaultForUser($userId);
}
```

### Important: Sessions in Cron Context

Cron tasks run without a user session. `Session::getLoginUserID()` returns `0`. Do not rely on `$_SESSION` in cron task code — pass entity/user IDs explicitly.

### Session Impersonation for Anonymous Endpoints

When a plugin endpoint needs to create DB records (e.g. ITILFollowup) but runs without a logged-in user, temporarily impersonate the target user:

```php
$hadSession = (int)(Session::getLoginUserID() ?: 0) > 0;
if (!$hadSession && $userId > 0) {
    $_SESSION['glpiID']     = $userId;
    $_SESSION['glpiname']   = 'myplugin-bot';
    $_SESSION['glpiactive'] = 1;
}

// ... create followup, update items, etc.

if (!$hadSession) {
    unset($_SESSION['glpiID'], $_SESSION['glpiname'], $_SESSION['glpiactive']);
}
```

### Session::getLoginUserID() Returns the Bot in API Calls

When the GLPI API processes a request authenticated as a service-account ("bot user"), `Session::getLoginUserID()` returns the bot's user ID, not the original technician's. If you need the original technician (e.g. for PDF generation), read `users_id_tech` from the stored DB record and pass it explicitly:

```php
// WRONG — returns the bot user during API calls
$techName = User::getFriendlyNameById(Session::getLoginUserID());

// CORRECT — reads the actual technician from the transfer record
$techId = (int)$transfer['users_id_tech'];
$techUser = new User();
$techName = $techUser->getFromDB($techId) ? $techUser->getFriendlyName() : '?';
```

---

## 11. Translations

### PHP Usage

```php
__('String to translate', 'myplugin');              // Simple translation
_n('One item', '%d items', $count, 'myplugin');     // Plural form
_x('String', 'context', 'myplugin');                // With disambiguation context
sprintf(__('Hello %s', 'myplugin'), $name);         // With parameters
```

### .po File Format (locales/en_GB.po, locales/pl_PL.po)

```po
msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"

msgid "Equipment Transfer"
msgstr "Equipment Transfer"

msgid "Hello %s"
msgstr "Hello %s"
```

### Rules

- **Always** use the plugin domain (second parameter): `__('text', 'myplugin')`.
- **Never** hardcode user-visible strings without translation wrapper.
- Add every new string to **both** language files.
- The domain must match the plugin directory name.

---

## 12. Frontend

### JavaScript

No build tools — vanilla JS only. Edit files directly.

```javascript
// AJAX call pattern
fetch(pluginBaseUrl + '/ajax/getData.php?param=' + encodeURIComponent(value), {
    method: 'GET',
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        // Process data.data
    }
})
.catch(error => console.error('AJAX error:', error));
```

### Passing Config from PHP to JS

```php
// In your PHP form/page:
$jsConfig = [
    'ajaxUrl'  => Plugin::getWebDir('myplugin') . '/ajax/',
    'csrfToken' => Session::getNewCSRFToken(),  // Only if needed for non-/front/ POST
    'labels'   => [
        'confirm' => __('Confirm', 'myplugin'),
        'cancel'  => __('Cancel', 'myplugin'),
    ],
];
echo '<script>var MyPluginConfig = ' . json_encode($jsConfig) . ';</script>';
```

### CSS

```css
/* Prefix plugin classes to avoid collisions */
.plugin-myplugin-container { }
.plugin-myplugin-button { }

/* Use GLPI's Bootstrap variables where possible */
```

### CSS/JS Cache Busting

In `setup.php`, always append version to asset URLs:

```php
$v = PLUGIN_MYPLUGIN_VERSION;
$PLUGIN_HOOKS['add_css']['myplugin']        = ["public/css/myplugin.css?v={$v}"];
$PLUGIN_HOOKS['add_javascript']['myplugin'] = ["public/js/myplugin.js?v={$v}"];
```

---

## 13. File & Document Handling

### Plugin Storage Directories

```php
$pluginDir = GLPI_DOC_DIR . '/_plugins/myplugin/';
@mkdir($pluginDir . 'uploads/', 0755, true);
```

### Creating a GLPI Document Record

```php
$doc = new Document();
$docId = $doc->add([
    'name'          => 'Protocol_' . $itemId . '.pdf',
    'filename'      => $filename,
    'filepath'      => $relativePath,    // Relative to GLPI_DOC_DIR
    'mime'          => 'application/pdf',
    'entities_id'   => $_SESSION['glpiactive_entity'],
    'is_recursive'  => 1,
]);
```

### Linking Documents to Items

```php
(new Document_Item())->add([
    'documents_id' => $docId,
    'itemtype'     => 'User',       // Or 'Ticket', 'Computer', etc.
    'items_id'     => $userId,
    'entities_id'  => $_SESSION['glpiactive_entity'],
]);
```

### File Upload Validation (Multi-Layer)

```php
// 1. Check upload success
if ($_FILES['upload']['error'] !== UPLOAD_ERR_OK) { return; }

// 2. Validate MIME type
$mime = mime_content_type($_FILES['upload']['tmp_name']);
if (!in_array($mime, ['image/png', 'image/jpeg'], true)) { reject(); }

// 3. Validate extension
$ext = strtolower(pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) { reject(); }

// 4. Check file size
if ($_FILES['upload']['size'] > 2 * 1024 * 1024) { reject(); }

// 5. Validate image integrity (skip for SVG)
if ($mime !== 'image/svg+xml') {
    $info = @getimagesize($_FILES['upload']['tmp_name']);
    if ($info === false) { reject(); }
}

// 6. Generate safe filename (never use user-supplied filename directly)
$safeFilename = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
```

---

## 14. PDF Generation

### Fallback Chain Pattern

```php
public static function generatePDF(string $html): ?string {
    // 1. Try wkhtmltopdf (best quality, 1:1 with browser Ctrl+P)
    $wkPath = self::findWkhtmltopdf();
    if ($wkPath) {
        return self::renderWithWkhtmltopdf($wkPath, $html);
    }

    // 2. Try Chromium headless
    $chromePath = self::findChromium();
    if ($chromePath) {
        return self::renderWithChromium($chromePath, $html);
    }

    // 3. Try mPDF (PHP library, limited CSS)
    if (class_exists('\\Mpdf\\Mpdf')) {
        return self::renderWithMpdf($html);
    }

    // 4. Fallback: save as HTML
    return self::saveAsHtml($html);
}
```

### Shell Command Safety

```php
$cmd = sprintf(
    '%s --quiet --page-size A4 --encoding utf-8 %s %s 2>&1',
    escapeshellarg($binaryPath),
    escapeshellarg($inputHtmlPath),
    escapeshellarg($outputPdfPath)
);
exec($cmd, $output, $exitCode);
```

### Temp File Handling

```php
$tmpDir = GLPI_TMP_DIR;
$htmlPath = $tmpDir . '/protocol_' . uniqid() . '.html';
file_put_contents($htmlPath, $html);
// ... generate PDF ...
@unlink($htmlPath);  // Always clean up
```

### mPDF Adaptation

mPDF has limited CSS support. Adapt HTML before passing:

```php
private static function adaptForMpdf(string $html): string {
    // mPDF doesn't support Segoe UI
    $html = str_replace("'Segoe UI'", "'DejaVu Sans'", $html);
    // mPDF handles max-width differently
    $html = str_replace('max-width: 800px;', '', $html);
    return $html;
}
```

### Design Rules for Printable HTML

- Use **table-based layout** only (no flexbox, no CSS grid) — mPDF can't render them.
- Use **inline styles** — external CSS classes don't carry into PDF rendering.
- Embed images as **base64 data URLs** — PDF engines can't fetch external URLs.
- Include `@page { size: A4; margin: 15mm 20mm; }` in `<style>` block.

---

## 15. Notifications

### Custom Notification Target

```php
class PluginMypluginNotificationTarget extends NotificationTarget
{
    // Define events this plugin can fire
    public function getEvents() {
        return [
            'transfer_completed' => __('Transfer completed', 'myplugin'),
        ];
    }

    // Define possible recipients
    public function addNotificationTargets($event = '') {
        $this->addTarget(Notification::USER, __('Employee', 'myplugin'));
        $this->addTarget(Notification::ASSIGN_TECH, __('Technician', 'myplugin'));
    }

    // Populate template tags
    public function addDataForTemplate($event, $options = []) {
        $this->data['##myplugin.employee##'] = $options['employee_name'] ?? '';
    }
}
```

### Firing a Notification

```php
NotificationEvent::raiseEvent('transfer_completed', $transferObject, [
    'employee_name' => $name,
    'employee_id'   => $userId,
]);
```

### Suppressing Standard GLPI Notifications

When creating tickets programmatically, prevent GLPI from sending its default notifications:

```php
$ticket = new Ticket();
$ticketId = $ticket->add([
    'name'             => 'My ticket',
    'content'          => 'Content',
    '_disablenotif'    => true,   // Suppress standard notification
    '_users_id_assign' => $techId,
]);
```

### Rules

- Method signatures in NotificationTarget subclasses **must match parent exactly** — no extra parameters, no return type hints.
- Register the notification target class in `setup.php`: `Plugin::registerClass('PluginMypluginNotificationTarget', ['notificationtargets_types' => true])`.
- Unregister in `plugin_myplugin_uninstall()`.

---

## 16. Logging

### Recommended Pattern

```php
class PluginMypluginLogger
{
    private static ?string $logFile = null;

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
        // Also log to GLPI's native logger for visibility
        try { Toolbox::logError("MyPlugin: {$message}"); } catch (\Throwable $e) {}
    }

    private static function log(string $level, string $message, array $context): void {
        $file = self::getLogFile();
        $ts = date('Y-m-d H:i:s');
        $userId = Session::getLoginUserID() ?: 0;
        $ctxStr = $context ? ' | ' . json_encode($context) : '';
        $line = "[{$ts}] [{$level}] [user:{$userId}] {$message}{$ctxStr}\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function getLogFile(): string {
        if (!self::$logFile) {
            self::$logFile = (defined('GLPI_LOG_DIR') ? GLPI_LOG_DIR : '/tmp') . '/myplugin.log';
        }
        return self::$logFile;
    }
}
```

### Key Rules

- Never log sensitive data (tokens, passwords, signatures) at any level.
- Log to the plugin's own file (`GLPI_LOG_DIR/myplugin.log`), not GLPI's main log.
- Also write errors to `Toolbox::logError()` for GLPI admin visibility.

---

## 17. Security Checklist

Every code change must satisfy:

| Check | Method |
|-------|--------|
| User input sanitized on output | `Html::cleanInputText()`, `htmlspecialchars()` |
| SQL queries parameterized | `$DB->request()` with array bindings, `$DB->escape()` for LIKE |
| Shell commands escaped | `escapeshellarg()` on ALL arguments |
| CSRF tokens on forms | GLPI auto-handles in `/front/`; use `Session::getNewCSRFToken()` elsewhere |
| Cryptographic tokens | `bin2hex(random_bytes(24))` — never `md5(time())` or `uniqid()` |
| File uploads validated | MIME + extension + size + integrity checks |
| Filenames sanitized | Generate safe names, never use user input directly |
| Authentication checked | `Session::getLoginUserID()` at every entry point |
| Permissions verified | Check plugin rights + GLPI global rights |

---

## 18. What Does NOT Work / Forbidden Patterns

### FORBIDDEN — Will break or cause subtle bugs

| Pattern | Why It Fails |
|---------|-------------|
| `Session::checkCRSF()` in `/front/*.php` | GLPI already consumed the token — second check always fails |
| Raw `mysqli_*` calls | Bypasses GLPI's connection management and escaping |
| `md5(time())` for tokens | Predictable, not cryptographically secure |
| `echo $userInput` without escaping | XSS vulnerability |
| Shell commands without `escapeshellarg()` | Command injection vulnerability |
| Accessing `$item->fields` in `pre_item_add` | Fields aren't populated yet — only `input` exists |
| Using `$item->fields['status']` to detect NEW status in `item_update` | `fields` contains the OLD value — use `$item->input['status']` |
| Hardcoding user-visible strings | Breaks translations, violates GLPI conventions |
| jQuery / npm imports | GLPI plugins use vanilla JS; GLPI's own jQuery is internal |
| `composer require` in plugin directory | GLPI has no Composer autoload for plugins |
| `$DB->query()` with string concatenation | SQL injection risk; use `$DB->request()` with arrays |
| `git push --force` to shared branches | Destroys history |
| Flexbox/CSS Grid in printable HTML | mPDF fallback can't render them — use tables |
| `Profile::dropdownRight()` with bracket names | POST data comes back empty — use plain `<select>` with `form=""` |
| Raw `</form>` instead of `Html::closeForm()` | Missing CSRF token → 403 on every POST |
| Custom PHP files in `/front/` | Slim router intercepts — file never executes, shows 404 |
| `Session::getLoginUserID()` for tech name in API/cron | Returns the bot/cron user, not the original technician |
| PUT `status='completed'` via API and expecting full logic | `post_updateItem` guard sees "already done" and skips finalization |
| Single `$_GET['id']` param in custom endpoints | GLPI's routing intercepts it — use `transfer_id` or similar |

### DOES NOT WORK — GLPI limitations

| Expectation | Reality |
|-------------|---------|
| `_n()` with plugin domain works like gettext | Use `__()` for most strings; `_n()` plural support is inconsistent in some GLPI versions |
| Plugin tables exist during `plugin_init` | They don't during install — always wrap in try-catch |
| Session data available in cron | `Session::getLoginUserID()` returns 0 in cron context |
| `NotificationEvent::raiseEvent()` works in uninstall | Plugin is already being deactivated — notifications may fail |
| Calling `Html::closeForm()` generates CSRF token | It does, but only for the NEXT form submission — not retroactively |
| Custom Asset queries on `glpi_computers` etc. | Custom Assets (GLPI 11) use `glpi_assets_assets` — completely different table |

---

## 19. Common Gotchas

### 1. Tables Don't Exist During Install

Every DB access in `setup.php`, `menu.class.php`, or any class method that runs at load time must be guarded:

```php
try {
    $value = $DB->request([...]);
} catch (\Throwable $e) {
    $value = $default;
}
// OR
if ($DB->tableExists('glpi_plugin_myplugin_configs')) {
    // Safe to query
}
```

### 2. Ticket Status Is an Integer, Not a String

```php
// Correct
$status = (int)$ticket->fields['status'];
if ($status === CommonITILObject::CLOSED) { ... }

// Wrong — string comparison may fail
if ($ticket->fields['status'] === 'closed') { ... }

// Status constants:
CommonITILObject::INCOMING    = 1
CommonITILObject::ASSIGNED    = 2
CommonITILObject::PLANNED     = 3
CommonITILObject::WAITING     = 4
CommonITILObject::SOLVED      = 5
CommonITILObject::CLOSED      = 6
```

### 3. Equipment State IDs May Vary Per Installation

State IDs like 2 (IN USE) and 29 (TO CHECK) are common defaults but not guaranteed. If your plugin depends on specific states, make them configurable or look them up by name from `glpi_states`.

### 4. Custom Assets (GLPI 11) Have a Different Schema

```php
// Native assets: glpi_computers, glpi_monitors, etc.
// Custom assets: glpi_assets_assets with assets_assetdefinitions_id filter

if ($isCustomAsset) {
    $rows = $DB->request([
        'FROM'  => 'glpi_assets_assets',
        'WHERE' => [
            'assets_assetdefinitions_id' => $definitionId,
            'id' => $itemId,
        ],
    ]);
} else {
    $rows = $DB->request([
        'FROM'  => $nativeTable,  // 'glpi_computers', etc.
        'WHERE' => ['id' => $itemId],
    ]);
}
```

### 5. Blocking Ticket Closure Requires Multiple Hooks

A user can close a ticket via:
- Changing the status field directly → `pre_item_update` on Ticket
- Adding an ITILSolution → `pre_item_add` on ITILSolution
- Approving a pending solution → `pre_item_update` on Ticket

You need hooks on **all paths** to reliably block premature closure.

### 6. Notification Method Signatures Must Match Parent

```php
// WRONG — extra return type breaks GLPI's reflection
public function getEvents(): array { }

// CORRECT — match parent signature exactly
public function getEvents() { }
```

### 7. Plugin CSS/JS Caching Is Aggressive

Browsers and GLPI cache static assets aggressively. Without version query strings, users will see stale JS/CSS after updates. Always use `?v=VERSION` and increment on every release.

### 8. Base64 Images in Database Can Be Very Large

Signature pad captures or embedded images stored as base64 in `LONGTEXT` columns can be 50KB+ each. For high-DPI screens, they can be much larger. Plan your column types accordingly.

### 9. GLPI's Html::redirect() Does Not Exit

After `Html::redirect($url)`, your PHP code continues executing. Always call `exit;` or `return` after redirect if needed.

### 10. SuperAdmin Can Override Plugin Restrictions

Profile ID 4 (Super-Admin) can bypass many GLPI restrictions. Your plugin should handle this gracefully — either allow it with logging, or explicitly check and block with an explanation.

### 11. Hooks Fire 2–3 Times on Ticket Close

When a technician closes a ticket by adding a solution, GLPI fires `item_update` on Ticket AND `item_add` on ITILSolution in the same request. Sometimes a third `item_update` fires when GLPI auto-promotes to "closed". Any stock/state mutation in the handler runs multiple times unless guarded with an idempotency flag.

### 12. GLPI API Requires BOTH App-Token AND User-Token

Sending only the `Authorization: user_token` header without `App-Token` returns `ERROR_APP_TOKEN_PARAMETERS_MISSING`. Both are required on every API call, including `initSession`.

### 13. Config Defaults Are Only Seeded on First Install

The `$newKeys` pattern (`if count($e)===0 then insert`) only inserts MISSING keys. If a config key already exists with an old default value (e.g. `'bridge'` from a previous version), the upgrade does NOT overwrite it. For important default changes, add an explicit `$DB->update(... WHERE value='old_value')` in the upgrade path.

### 14. The `/public/` Directory Is the nginx Document Root

In GLPI 11, `GLPI_ROOT/public/` is the web server's document root, NOT `GLPI_ROOT/`. URLs like `/front/login.php` actually resolve to `GLPI_ROOT/public/../front/login.php` through the Slim router. Static files (`.html`, `.css`, `.js`) placed in `public/` are served directly by nginx. PHP files in `public/` are NOT processed — they're downloaded as raw files.

### 15. post_updateItem oldvalues[] for Detecting API Changes

`$this->oldvalues` in `post_updateItem()` contains ONLY the fields that actually changed. Use `isset($this->oldvalues['field_name'])` to detect whether a specific field was modified. `$this->fields` contains the new values after the update.

---

## 20. Useful Constants & Paths

```php
GLPI_ROOT                // /var/www/glpi (or wherever GLPI is installed)
GLPI_DOC_DIR             // /var/lib/glpi/files (document storage)
GLPI_LOG_DIR             // /var/log/glpi
GLPI_TMP_DIR             // /var/lib/glpi/_tmp
$CFG_GLPI['root_doc']   // URL base path, e.g., '/glpi' or '/'
$CFG_GLPI['url_base']   // Full URL base, e.g., 'https://glpi.example.com/glpi'

// Plugin paths
GLPI_ROOT . '/plugins/myplugin/'                           // Plugin files
GLPI_DOC_DIR . '/_plugins/myplugin/'                       // Plugin document storage
Plugin::getWebDir('myplugin')                              // Web-accessible URL path
Plugin::getPhpDir('myplugin')                              // Filesystem path

// Get current entity
$_SESSION['glpiactive_entity']
$_SESSION['glpiactive_entity_name']

// Rights constants
READ              = 1
CREATE            = 2
UPDATE            = 4
DELETE            = 8
PURGE             = 16
ALLSTANDARDRIGHT  = 31
```

---

## 21. GLPI REST API (v1) for Plugins

### Plugin Items Are Auto-Exposed

Any class extending `CommonDBTM` is automatically accessible via the API:

```
GET    /api.php/v1/PluginMypluginMyClass/          # List items
GET    /api.php/v1/PluginMypluginMyClass/42         # Get item #42
POST   /api.php/v1/PluginMypluginMyClass/           # Create item
PUT    /api.php/v1/PluginMypluginMyClass/42          # Update item #42
DELETE /api.php/v1/PluginMypluginMyClass/42          # Delete item #42
```

**BUT:** the API calls `canView()`, `canCreate()`, `canUpdate()` on your class. If your plugin uses a custom profiles table (not `glpi_profilerights`), you MUST override these methods (see [Section 9](#custom-profiles-table-vs-glpi-api)).

### Authentication: App-Token + User-Token

GLPI API v1 requires **both** tokens on every request:

```
GET /api.php/v1/initSession
Headers:
  Authorization: user_token <USER_API_TOKEN>
  App-Token: <APP_TOKEN>
```

| Token | Where to get it |
|-------|----------------|
| **App-Token** | Setup > General > API > API clients > click client > Application token |
| **User-Token** | User preferences > Remote access keys > API token (Regenerate) |

Without the App-Token, the API returns `ERROR_APP_TOKEN_PARAMETERS_MISSING`. Without the User-Token, it returns `ERROR_LOGIN_PARAMETERS_MISSING`.

### Triggering Server-Side Logic via API PUT

Do NOT set fields that have guard clauses (e.g. `status='completed'`) directly via PUT. The guard will think the work is already done and skip it. Instead, clear a trigger field (e.g. `confirmation_token`) and detect the change in `post_updateItem()` (see [Section 8](#postupdateitem-for-api-triggered-logic)).

### Static HTML Landing Pages + JS Fetch

For anonymous users (email confirm links), serve a static `.html` file from GLPI's `public/` directory that calls the API via JavaScript:

```javascript
// 1. Authenticate
var res = await fetch(apiUrl + '/initSession', {
  headers: {
    'Authorization': 'user_token ' + userToken,
    'App-Token': appToken
  }
});
var { session_token } = await res.json();

// 2. Read/update plugin item
await fetch(apiUrl + '/PluginMypluginItem/' + id, {
  method: 'PUT',
  headers: {
    'Session-Token': session_token,
    'App-Token': appToken,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({ input: { my_field: 'new_value' } })
});

// 3. Kill session
await fetch(apiUrl + '/killSession', {
  headers: { 'Session-Token': session_token, 'App-Token': appToken }
});
```

---

## 22. Anonymous/Public Endpoints

### The Problem

GLPI 11 production setups typically have nginx configured to block anonymous access to `/plugins/*`. Making a truly public endpoint for email confirmation links is surprisingly hard:

| Approach | Why It Fails |
|----------|-------------|
| PHP in `/plugins/*/ajax/` | nginx auth blocks anonymous requests before PHP runs |
| PHP in `/front/` | GLPI's Slim router intercepts ALL `/front/*.php` requests |
| PHP in `/public/` | nginx serves PHP files as raw downloads (no FastCGI processing) |
| `$SECURITY_STRATEGY = 'no_check'` flags | Only work at PHP level; useless if nginx already redirected |

### What Works

**Static HTML + GLPI API** — the only reliable combination:

1. Place a `.html` file (not `.php`) in GLPI's `public/` directory. nginx serves static HTML correctly.
2. The HTML page uses JavaScript `fetch()` to call GLPI's REST API at `/api.php/v1/`. The API is always accessible (third-party integrations depend on it).
3. The API authenticates with a service-account's User-Token + App-Token.
4. The JS reads/updates the plugin item via standard CRUD endpoints.
5. A `post_updateItem()` hook on the plugin class detects the change and triggers the full server-side logic.

### Fallback: Email Reply via Mailcollector

GLPI's mailcollector can process email replies as ticket followups. A plugin can detect a specific phrase in the followup content and trigger confirmation. This works without any URL access:

```php
// In setup.php
$PLUGIN_HOOKS['item_add']['myplugin']['ITILFollowup'] = 'plugin_myplugin_followupAdd';

// In hook.php / class
function plugin_myplugin_followupAdd(ITILFollowup $followup): void {
    $content = strip_tags($followup->fields['content'] ?? '');
    if (str_contains(mb_strtolower($content), 'i confirm')) {
        // Process confirmation
    }
}
```

The employee just replies to the notification email with the confirmation phrase.

---

## Quick Reference: New Plugin Checklist

When starting a new GLPI plugin from scratch:

1. Create directory structure: `setup.php`, `hook.php`, `front/`, `inc/`, `ajax/`, `public/`, `locales/`
2. Define plugin version constant and `plugin_version_*()` function
3. Implement `plugin_init_*()` with `csrf_compliant = true`
4. Implement install/uninstall/upgrade in `hook.php`
5. Create main class extending `CommonDBTM` with correct naming
6. Create menu class extending `CommonGLPI`
7. Create profile class with permissions section on the plugin's Configuration page (not a Profile tab — GLPI 11's router interferes with tab registration)
8. Set default permissions for Super-Admin, Admin, Technician
9. Add translation files for all supported languages
10. Add CSS/JS with version cache-busting
11. Test: fresh install, uninstall, reinstall, upgrade path

---

*This guide is maintained as a living document. Update it when you discover new GLPI patterns, quirks, or breaking changes across versions.*
