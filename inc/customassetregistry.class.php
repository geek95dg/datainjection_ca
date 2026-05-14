<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Custom Asset support (GLPI 11+).
 *
 * Custom Assets in GLPI 11 are defined at runtime via the AssetDefinition
 * mechanism: every record in `glpi_assets_assetdefinitions` exposes a dynamic
 * PHP class (e.g. \Glpi\CustomAsset\MyAsset) that extends \Glpi\Asset\Asset
 * and stores its rows in the shared `glpi_assets_assets` table, filtered by
 * `assets_assetdefinitions_id`.
 *
 * This registry generates one injection class per definition on demand
 * (via eval) so each custom asset becomes a regular injectable type in the
 * plugin's `$INJECTABLE_TYPES` array. Custom fields declared on a definition
 * — stored as JSON in `glpi_assets_assets.custom_fields` — are exposed as
 * extra mappable columns.
 * -------------------------------------------------------------------------
 */

class PluginDatainjectionCustomAssetRegistry
{
    /** Cache of definition rows keyed by definition id. */
    private static ?array $definitions = null;

    /** Cache of generated injection class names keyed by definition id. */
    private static array $generatedClasses = [];

    /** Class FQCN -> definition id (for reverse lookups by injection class). */
    private static array $assetClassToDefinitionId = [];

    /**
     * Get all asset definitions visible to data injection.
     *
     * Returns an associative array keyed by definition id with at least:
     *  - id
     *  - system_name
     *  - label
     *  - asset_class (FQCN of the dynamic class)
     *  - custom_fields (array of decoded custom field definitions)
     */
    public static function getDefinitions(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (self::$definitions !== null) {
            return self::$definitions;
        }

        self::$definitions = [];

        try {
            if (!$DB->tableExists('glpi_assets_assetdefinitions')) {
                return self::$definitions;
            }
        } catch (\Throwable $e) {
            return self::$definitions;
        }

        try {
            $rows = $DB->request([
                'FROM'  => 'glpi_assets_assetdefinitions',
                'WHERE' => ['is_active' => 1],
            ]);
        } catch (\Throwable $e) {
            // is_active may not exist on older snapshots — fall back.
            try {
                $rows = $DB->request(['FROM' => 'glpi_assets_assetdefinitions']);
            } catch (\Throwable $e2) {
                return self::$definitions;
            }
        }

        foreach ($rows as $row) {
            $defId      = (int) $row['id'];
            $systemName = (string) ($row['system_name'] ?? '');
            if ($systemName === '') {
                continue;
            }

            $assetClass = self::resolveAssetClass($row, $systemName);
            if ($assetClass === null) {
                continue;
            }

            $label = self::extractLabel($row, $systemName);

            self::$definitions[$defId] = [
                'id'            => $defId,
                'system_name'   => $systemName,
                'label'         => $label,
                'asset_class'   => $assetClass,
                'custom_fields' => self::extractCustomFields($row),
            ];

            self::$assetClassToDefinitionId[ltrim($assetClass, '\\')] = $defId;
        }

        return self::$definitions;
    }

    /**
     * Build (if needed) and return all injection class names for custom assets.
     *
     * @return array Map of injection class name => 'datainjection'
     */
    public static function getInjectableTypes(): array
    {
        $types = [];
        foreach (self::getDefinitions() as $defId => $definition) {
            $cls = self::ensureInjectionClass($defId);
            if ($cls !== null) {
                $types[$cls] = 'datainjection';
            }
        }
        return $types;
    }

    /**
     * Does this itemtype correspond to a custom asset class?
     */
    public static function isCustomAssetItemtype(string $itemtype): bool
    {
        if ($itemtype === '' || !class_exists($itemtype)) {
            return false;
        }
        if (!class_exists('\\Glpi\\Asset\\Asset')) {
            return false;
        }
        return is_subclass_of($itemtype, '\\Glpi\\Asset\\Asset');
    }

    /**
     * Resolve the injection class for a custom asset itemtype, building it
     * lazily if necessary.
     */
    public static function getInjectionClassForItemtype(string $itemtype): ?string
    {
        $normalized = ltrim($itemtype, '\\');

        // Ensure definitions are loaded so the lookup table is populated.
        self::getDefinitions();

        if (!isset(self::$assetClassToDefinitionId[$normalized])) {
            // The class may have been generated after our cache; rescan.
            foreach (self::getDefinitions() as $defId => $def) {
                if (ltrim($def['asset_class'], '\\') === $normalized) {
                    self::$assetClassToDefinitionId[$normalized] = $defId;
                    break;
                }
            }
        }

        if (!isset(self::$assetClassToDefinitionId[$normalized])) {
            return null;
        }

        return self::ensureInjectionClass(self::$assetClassToDefinitionId[$normalized]);
    }

    /**
     * Get the definition descriptor for a given injection class, or null.
     */
    public static function getDefinitionForInjectionClass(string $injectionClass): ?array
    {
        foreach (self::getDefinitions() as $defId => $def) {
            if (($def['_injection_class'] ?? null) === $injectionClass) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Build a per-definition injection class via eval. The generated class
     * extends the asset's dynamic class and uses the common trait that
     * implements PluginDatainjectionInjectionInterface.
     */
    public static function ensureInjectionClass(int $defId): ?string
    {
        if (isset(self::$generatedClasses[$defId])) {
            return self::$generatedClasses[$defId];
        }

        $definitions = self::getDefinitions();
        if (!isset($definitions[$defId])) {
            return null;
        }

        $def        = $definitions[$defId];
        $assetClass = $def['asset_class'];
        if (!class_exists($assetClass)) {
            return null;
        }

        $suffix         = self::sanitizeIdentifier($def['system_name']);
        $injectionClass = 'PluginDatainjectionCustomAsset' . $suffix . 'Injection';

        // Ensure the base class is loaded — GLPI's plugin autoloader will
        // pick it up by name, but include explicitly so the eval below
        // never depends on autoload ordering.
        if (!class_exists('PluginDatainjectionCustomAssetBaseInjection', false)) {
            $baseFile = __DIR__ . '/customassetbaseinjection.class.php';
            if (is_file($baseFile)) {
                require_once $baseFile;
            }
        }

        if (!class_exists($injectionClass, false)) {
            // IMPORTANT: do NOT `extends \Glpi\CustomAsset\XAsset` here. Those
            // classes are emitted `final` by GLPI 11, so extending them is a
            // compile error. Instead we extend our own (non-final) base
            // class and reference the asset FQCN as a static — the base
            // class instantiates it directly inside `customimport`.
            $code = sprintf(
                'class %s extends PluginDatainjectionCustomAssetBaseInjection {'
                . ' public static function getDefinitionId(): int { return %d; }'
                . ' public static function getAssetClass(): string { return %s; }'
                . ' }',
                $injectionClass,
                $defId,
                var_export(ltrim($assetClass, '\\'), true),
            );
            try {
                eval($code);
            } catch (\Throwable $e) {
                if (class_exists('PluginDatainjectionLogger')) {
                    PluginDatainjectionLogger::exception(
                        $e,
                        'failed to build injection class for custom asset ' . $def['system_name'],
                    );
                }
                return null;
            }
        }

        self::$generatedClasses[$defId]                = $injectionClass;
        self::$definitions[$defId]['_injection_class'] = $injectionClass;

        return $injectionClass;
    }

    /**
     * Get custom field definitions for an asset definition.
     *
     * @return array<int, array{key:string,label:string,type:string,default:mixed}>
     */
    public static function getCustomFields(int $defId): array
    {
        $definitions = self::getDefinitions();
        return $definitions[$defId]['custom_fields'] ?? [];
    }

    /**
     * Marker prefix used for custom field linkfields in search options so the
     * common library treats them as plain text and we recognise them later.
     */
    public const CUSTOM_FIELD_LINK_PREFIX = '_customfield_';

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private static function resolveAssetClass(array $row, string $systemName): ?string
    {
        // GLPI 11 exposes AssetDefinition::getAssetClassName(). Prefer it.
        if (class_exists('\\Glpi\\Asset\\AssetDefinition')) {
            try {
                $def = new \Glpi\Asset\AssetDefinition();
                if ($def->getFromDB((int) $row['id']) && method_exists($def, 'getAssetClassName')) {
                    $cls = $def->getAssetClassName();
                    if (is_string($cls) && $cls !== '' && class_exists($cls)) {
                        return $cls;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to convention-based resolution.
            }
        }

        // Convention used by GLPI's generator.
        $candidate = '\\Glpi\\CustomAsset\\' . $systemName;
        if (class_exists($candidate)) {
            return $candidate;
        }
        $candidate = '\\Glpi\\CustomAsset\\' . $systemName . 'Asset';
        if (class_exists($candidate)) {
            return $candidate;
        }
        return null;
    }

    private static function extractLabel(array $row, string $fallback): string
    {
        // `label` column may contain a JSON map of translations or a plain string.
        $raw = $row['label'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $lang = $_SESSION['glpilanguage'] ?? 'en_GB';
                if (isset($decoded[$lang]) && is_string($decoded[$lang]) && $decoded[$lang] !== '') {
                    return $decoded[$lang];
                }
                foreach ($decoded as $value) {
                    if (is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            } else {
                return $raw;
            }
        }
        return $fallback;
    }

    /**
     * Decode the custom field definitions for one AssetDefinition.
     *
     * GLPI 11 stores them either as a JSON array on the definition row
     * (column commonly named `custom_fields` / `fields_display`) or in a
     * companion table `glpi_assets_customfielddefinitions`. We try both.
     */
    private static function extractCustomFields(array $row): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $fields = [];

        // 1. JSON column on the definition row.
        foreach (['custom_fields', 'fields_display', 'fields'] as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $raw = $row[$col];
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $entry) {
                $parsed = self::parseCustomFieldEntry($entry);
                if ($parsed !== null) {
                    $fields[$parsed['key']] = $parsed;
                }
            }
        }

        // 2. Companion table — overrides JSON if richer data is available.
        try {
            if ($DB->tableExists('glpi_assets_customfielddefinitions')) {
                $where = [];
                if ($DB->fieldExists('glpi_assets_customfielddefinitions', 'assets_assetdefinitions_id')) {
                    $where['assets_assetdefinitions_id'] = (int) $row['id'];
                } elseif ($DB->fieldExists('glpi_assets_customfielddefinitions', 'assetdefinitions_id')) {
                    $where['assetdefinitions_id'] = (int) $row['id'];
                }
                $rows = $DB->request([
                    'FROM'  => 'glpi_assets_customfielddefinitions',
                    'WHERE' => $where,
                ]);
                foreach ($rows as $cfRow) {
                    $parsed = self::parseCustomFieldEntry($cfRow);
                    if ($parsed !== null) {
                        $fields[$parsed['key']] = $parsed;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Best effort only.
        }

        // Dedup: when a definition stores its custom fields both in the
        // JSON display config AND in the companion table, we end up with
        // each real field listed twice — once under its bare key
        // (`polka`) and once under the asset-column form
        // (`custom_polka`). The companion-table entries are authoritative
        // (they carry `system_name`, `type`, label translations). Drop
        // any `custom_<key>` entry if `<key>` is already present.
        foreach (array_keys($fields) as $existingKey) {
            if (strncmp($existingKey, 'custom_', 7) === 0) {
                $stripped = substr($existingKey, 7);
                if ($stripped !== '' && isset($fields[$stripped])) {
                    unset($fields[$existingKey]);
                }
            }
        }

        return array_values($fields);
    }

    private static function parseCustomFieldEntry($entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $key = $entry['system_name']
            ?? $entry['key']
            ?? $entry['name']
            ?? null;
        if (!is_string($key) || $key === '') {
            return null;
        }

        // GLPI 11 schema on `glpi_assets_customfielddefinitions`:
        //   * `label` — plain string in the source language
        //   * `translations` — JSON map { "fr_FR": "...", "pl_PL": "...", … }
        //
        // Older snapshots / the JSON-on-definition fallback path may have
        // the translations baked into `label` itself; try both layouts.
        $label = $entry['label'] ?? $entry['display_name'] ?? '';
        if (is_string($label) && $label !== '') {
            $decoded = json_decode($label, true);
            if (is_array($decoded)) {
                $label = self::pickLocalisedString($decoded) ?? $key;
            }
        }
        if (!is_string($label) || $label === '') {
            $label = '';
        }
        if (isset($entry['translations']) && is_string($entry['translations']) && $entry['translations'] !== '') {
            $tr = json_decode($entry['translations'], true);
            if (is_array($tr)) {
                $localised = self::pickLocalisedString($tr);
                if ($localised !== null && $localised !== '') {
                    $label = $localised;
                }
            }
        }
        if ($label === '') {
            $label = $key;
        }

        // `type` is stored as a class FQCN in GLPI 11
        // (Glpi\Asset\CustomFieldType\DropdownType, …). Normalise to a
        // short token (`dropdown`, `text`, `number`, `date`, `datetime`,
        // `boolean`, `url`, `user`, `string`, …) so callers can do simple
        // switching without depending on the full namespace.
        $type      = (string) ($entry['type'] ?? $entry['datatype'] ?? 'string');
        $typeShort = self::normalizeCustomFieldType($type);

        // For dropdown / itemlink types, GLPI stores the target itemtype
        // (e.g. `Location`, `Manufacturer`) in its own column. Carry it
        // forward so the injection options can be built as proper
        // dropdowns (name → ID lookup at injection time).
        $itemtype = $entry['itemtype'] ?? null;
        if (!is_string($itemtype) || $itemtype === '') {
            $itemtype = null;
        }

        return [
            'key'           => $key,
            'label'         => (string) $label,
            'type'          => $typeShort,
            'type_raw'      => $type,
            'itemtype'      => $itemtype,
            'default'       => $entry['default_value'] ?? null,
        ];
    }

    /**
     * Pick the value for the current session locale from a translation map,
     * falling back to en_GB / `en` / the first non-empty entry.
     */
    private static function pickLocalisedString(array $map): ?string
    {
        $lang = $_SESSION['glpilanguage'] ?? 'en_GB';
        foreach ([$lang, 'en_GB', 'en'] as $candidate) {
            if (isset($map[$candidate]) && is_string($map[$candidate]) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }
        foreach ($map as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Reduce a GLPI 11 custom-field type — typically a class FQCN like
     * `Glpi\Asset\CustomFieldType\DropdownType` — to a short canonical
     * token used by the injection metadata mappers.
     */
    private static function normalizeCustomFieldType(string $type): string
    {
        if ($type === '') {
            return 'string';
        }
        // Already short / lowercase token? Return as-is.
        if (!str_contains($type, '\\') && ctype_lower($type[0])) {
            return $type;
        }
        $short = strtolower(ltrim(substr(strrchr('\\' . $type, '\\'), 1), '\\'));
        // Strip the `Type` suffix GLPI conventionally tacks on.
        if (str_ends_with($short, 'type')) {
            $short = substr($short, 0, -4);
        }
        return $short === '' ? 'string' : $short;
    }

    private static function sanitizeIdentifier(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '_', $value) ?? '';
        if ($clean === '' || preg_match('/^[0-9]/', $clean)) {
            $clean = 'Def' . $clean;
        }
        return $clean;
    }
}
