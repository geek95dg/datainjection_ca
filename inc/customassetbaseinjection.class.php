<?php

/**
 * Base class for per-definition custom-asset injection classes.
 *
 * GLPI 11 generates one `final` class per AssetDefinition under
 * `\Glpi\CustomAsset\…`. We cannot extend a final class, so the injection
 * class is decoupled: it extends `CommonDBTM` (with the shared
 * `glpi_assets_assets` table) and *delegates* CRUD operations to a fresh
 * instance of the dynamic asset class. Per-definition subclasses are
 * generated at runtime by `PluginDatainjectionCustomAssetRegistry` and
 * only need to override two static methods:
 *
 *     public static function getDefinitionId(): int       // the row id
 *     public static function getAssetClass(): string      // the dynamic class FQCN
 */
class PluginDatainjectionCustomAssetBaseInjection extends CommonDBTM implements PluginDatainjectionInjectionInterface
{
    public static $table    = 'glpi_assets_assets';
    public static $rightname = '';

    /** Overridden by generated subclasses. */
    public static function getDefinitionId(): int
    {
        return 0;
    }

    /** Overridden by generated subclasses. Must return the FQCN of the dynamic asset class. */
    public static function getAssetClass(): string
    {
        return '';
    }

    /**
     * Surface the underlying asset's human-readable name in dropdowns,
     * search-options column headers and breadcrumbs — otherwise every
     * generated injection wrapper would advertise itself by its raw
     * class name (`PluginDatainjectionCustomAsset<X>Injection`).
     */
    public static function getTypeName($nb = 0)
    {
        $cls = static::getAssetClass();
        if ($cls !== '' && class_exists($cls)) {
            return $cls::getTypeName($nb);
        }
        return parent::getTypeName($nb);
    }

    public function isPrimaryType()
    {
        return true;
    }

    public function connectedTo()
    {
        return [];
    }

    public function isNullable($field)
    {
        return true;
    }

    /**
     * Stable itemtype for the injection-library lookup. All custom assets
     * share the same DB table so `getItemTypeForTable()` cannot disambiguate
     * — the registry-generated FQCN is used instead.
     */
    public function getInjectionItemtype(): string
    {
        return static::getAssetClass();
    }

    /**
     * Delegate the `maybe*` / `isField` family to a real asset instance so
     * `dataAlreadyInDB()` builds the correct WHERE clause. We cache the
     * delegate to avoid re-instantiating on every introspection call.
     */
    private function assetDelegate(): ?CommonDBTM
    {
        static $delegates = [];
        $cls = static::getAssetClass();
        if ($cls === '' || !class_exists($cls)) {
            return null;
        }
        if (!isset($delegates[$cls])) {
            $delegates[$cls] = new $cls();
        }
        return $delegates[$cls];
    }

    public function maybeDeleted()
    {
        $d = $this->assetDelegate();
        return $d ? $d->maybeDeleted() : parent::maybeDeleted();
    }

    public function maybeTemplate()
    {
        $d = $this->assetDelegate();
        return $d ? $d->maybeTemplate() : parent::maybeTemplate();
    }

    public function maybeRecursive()
    {
        $d = $this->assetDelegate();
        return $d ? $d->maybeRecursive() : parent::maybeRecursive();
    }

    public function isEntityAssign()
    {
        $d = $this->assetDelegate();
        return $d ? $d->isEntityAssign() : parent::isEntityAssign();
    }

    public function isField($field)
    {
        $d = $this->assetDelegate();
        return $d ? $d->isField($field) : parent::isField($field);
    }

    /**
     * Search options for the underlying asset + extra options for every
     * custom field declared on the definition.
     */
    public function getOptions($primary_type = '')
    {
        $assetClass = static::getAssetClass();
        $tab        = [];

        if ($assetClass !== '' && class_exists($assetClass)) {
            try {
                $tab = Search::getOptions($assetClass);
            } catch (\Throwable $e) {
                if (class_exists('PluginDatainjectionLogger')) {
                    PluginDatainjectionLogger::exception($e, 'Search::getOptions failed for ' . $assetClass);
                }
                $tab = [];
            }
        }

        // Custom-asset search options arrive WITHOUT `linkfield`, unlike
        // the native classes (Computer, Monitor, …) where each injection
        // class hand-fills it. `addToSearchOptions()` later filters out
        // any option that has no `linkfield` (via its dedupe-by-linkfield
        // pass), so without this step the field dropdown on the Mappings
        // page comes back empty for every AssetDefinition. Two cases:
        //
        // 1. Option points at the asset's own table (glpi_assets_assets):
        //    `linkfield = field` (the column name IS the column name).
        // 2. Option points at a foreign dropdown table (glpi_locations,
        //    glpi_manufacturers, glpi_states, glpi_users, …): derive the
        //    FK column from the table name (`glpi_locations` →
        //    `locations_id`). Falls back to skipping (option stays
        //    non-injectable) when the table name doesn't follow that
        //    convention.
        $patched = 0;
        $has_linkfield = 0;
        $missing_field = 0;
        $missing_table = 0;
        foreach ($tab as $id => &$opt) {
            if (!is_array($opt)) {
                continue;
            }
            if (isset($opt['linkfield'])) {
                $has_linkfield++;
                continue;
            }
            if (!isset($opt['field'])) {
                $missing_field++;
                continue;
            }
            if (!isset($opt['table'])) {
                $missing_table++;
                continue;
            }
            if ($opt['table'] === self::$table) {
                $opt['linkfield'] = $opt['field'];
                $patched++;
            } elseif (is_string($opt['table']) && str_starts_with($opt['table'], 'glpi_')) {
                $stripped = substr($opt['table'], strlen('glpi_'));
                if ($stripped !== '' && substr($stripped, -1) === 's') {
                    $opt['linkfield'] = $stripped . '_id';
                    $patched++;
                }
            }
        }
        unset($opt);

        // Sample the first numeric-keyed option so we can see what shape
        // GLPI's stock search options actually take for the AssetDefinition
        // class — without this we're guessing whether `linkfield` was already
        // there, whether `field`/`table` are even set, etc.
        $sample = null;
        foreach ($tab as $id => $opt) {
            if (is_numeric($id) && is_array($opt)) {
                $sample = [
                    'id'   => $id,
                    'keys' => array_keys($opt),
                    'field' => $opt['field']     ?? null,
                    'table' => $opt['table']     ?? null,
                    'linkfield' => $opt['linkfield'] ?? null,
                    'name'  => $opt['name']      ?? null,
                ];
                break;
            }
        }

        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::info('customAsset.getOptions: search options', [
                'asset_class'        => $assetClass,
                'raw_count'          => count($tab),
                'patched_linkfield'  => $patched,
                'already_linkfield'  => $has_linkfield,
                'missing_field'      => $missing_field,
                'missing_table'      => $missing_table,
                'first_option'       => $sample,
            ]);
        }

        $options = [
            'ignore_fields' => PluginDatainjectionCommonInjectionLib::getBlacklistedOptions($assetClass),
            'displaytype'   => [
                'dropdown' => $this->collectDropdownOptionIds($tab),
                'user'     => $this->collectUserOptionIds($tab),
            ],
        ];

        // We deliberately do NOT call
        // `PluginDatainjectionCommonInjectionLib::addToSearchOptions()` here
        // for custom assets. That helper calls
        // `getItemTypeForTable($value['table'])::getTypeName(1)` on every
        // option, which for the `glpi_assets_assets` table resolves to the
        // abstract `\Glpi\Asset\Asset` base class — and that class has a
        // typed static `$definition_system_name` that is only initialised
        // on the per-definition subclasses. Calling
        // `Asset::getTypeName(1)` therefore throws
        //
        //     Typed static property Glpi\Asset\Asset::$definition_system_name
        //     must not be accessed before initialization
        //
        // The throw aborts the whole search-options pipeline and the
        // Fields dropdown ends up empty (or limited to the per-definition
        // custom fields we append after). Run our own safe pass instead:
        // filter, mark injectable, apply the displaytype overrides we
        // collected above.
        $tab = $this->processSearchOptionsForCustomAsset($tab, $options);

        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::info('customAsset.getOptions: after addToSearchOptions', [
                'asset_class' => $assetClass,
                'kept_count'  => count($tab),
            ]);
        }

        // GLPI 11's search-options pipeline strips the `glpi_assets_assets`
        // native columns somewhere along the way for AssetDefinition
        // classes — by the time `addToSearchOptions` returns, the dropdown
        // is left with only group-label rows, so users can't map a CSV
        // column to `name`/`serial`/`locations_id`/etc. Hard-wire the
        // standard columns here, keyed on the live table schema so we
        // adapt to whichever columns the install actually has (capacities
        // such as Serial / NetworkPort / States add columns dynamically).
        $nativeAppended = $this->appendNativeAssetFieldOptions($tab);

        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::info('customAsset.getOptions: native fields appended', [
                'asset_class' => $assetClass,
                'appended'    => $nativeAppended,
                'kept_count'  => count($tab),
            ]);
        }

        $defId        = static::getDefinitionId();
        $customFields = PluginDatainjectionCustomAssetRegistry::getCustomFields($defId);
        $nextId       = 5000;

        foreach ($customFields as $field) {
            $linkfield   = PluginDatainjectionCustomAssetRegistry::CUSTOM_FIELD_LINK_PREFIX . $field['key'];
            $displaytype = $this->mapCustomFieldDisplayType($field['type']);
            $checktype   = $this->mapCustomFieldCheckType($field['type']);

            // FK-typed custom fields (Location, Manufacturer, Group, …)
            // store an ID in custom_fields JSON. CSVs/XLSXs contain the
            // human-readable name (e.g. "CHO > IT-Stock"), so we need to
            // tell the injection lib to resolve it to the corresponding ID
            // before saving — that means: displaytype='dropdown' and
            // `table` pointing at the joined dropdown table.
            $fkTable = $this->customFieldFkTable($field);
            if ($fkTable !== null) {
                $displaytype = 'dropdown';
                $checktype   = 'text';
            }

            $tab[$nextId] = [
                'id'          => $nextId,
                'table'       => $fkTable ?? self::$table,
                'field'       => $fkTable !== null ? 'name' : $linkfield,
                'linkfield'   => $linkfield,
                'name'        => $field['label'],
                'datatype'    => 'string',
                'injectable'  => PluginDatainjectionCommonInjectionLib::FIELD_INJECTABLE,
                'displaytype' => $displaytype,
                'checktype'   => $checktype,
            ];
            $nextId++;
        }

        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::info('customAsset.getOptions: returning', [
                'asset_class'         => $assetClass,
                'total_count'         => count($tab),
                'custom_field_count'  => count($customFields),
            ]);
        }

        return $tab;
    }

    /**
     * Scope `dataAlreadyInDB()` lookups to the active definition — without
     * this, two definitions sharing a serial/name in `glpi_assets_assets`
     * would conflate rows.
     */
    public function checkPresent($values, $options)
    {
        return " AND `assets_assetdefinitions_id` = '" . (int) static::getDefinitionId() . "' ";
    }

    /**
     * Called by PluginDatainjectionCommonInjectionLib::effectiveAddOrUpdate
     * for add and update. We pull our custom-field columns out of $fields,
     * pack them into the JSON `custom_fields` column, then delegate to the
     * real asset class.
     *
     * @param array $fields The normalised values prepared for injection.
     * @param bool  $add    True when adding, false when updating.
     * @param array $rights Plugin rights (unused here).
     *
     * @return int|false
     */
    public function customimport($fields, $add, $rights)
    {
        $prefix       = PluginDatainjectionCustomAssetRegistry::CUSTOM_FIELD_LINK_PREFIX;
        $prefixLen    = strlen($prefix);
        $customValues = [];

        foreach ($fields as $key => $value) {
            if (is_string($key) && strncmp($key, $prefix, $prefixLen) === 0) {
                $customKey = substr($key, $prefixLen);
                $customValues[$customKey] = $value;
                unset($fields[$key]);
            }
        }

        $assetClass = static::getAssetClass();
        if ($assetClass === '' || !class_exists($assetClass)) {
            if (class_exists('PluginDatainjectionLogger')) {
                PluginDatainjectionLogger::error(
                    'customimport: asset class missing',
                    ['definitionId' => static::getDefinitionId(), 'assetClass' => $assetClass],
                );
            }
            return false;
        }

        /** @var CommonDBTM $item */
        $item = new $assetClass();

        if ($add) {
            $fields['assets_assetdefinitions_id'] = static::getDefinitionId();
            unset($fields['id']);

            if (!empty($customValues)) {
                $fields['custom_fields'] = $this->mergeCustomFields(null, $customValues);
            }

            $newID = $item->add($fields);
            return $newID ?: false;
        }

        $id = (int) ($fields['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        if (!empty($customValues)) {
            $existing = null;
            if ($item->getFromDB($id)) {
                $existing = $item->fields['custom_fields'] ?? null;
            }
            $fields['custom_fields'] = $this->mergeCustomFields($existing, $customValues);
        }

        if ($item->update($fields)) {
            return $id;
        }
        return false;
    }

    /**
     * Merge a partial set of custom field values with any existing JSON payload.
     */
    private function mergeCustomFields($existing, array $values): string
    {
        $current = [];
        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        } elseif (is_array($existing)) {
            $current = $existing;
        }

        foreach ($values as $key => $value) {
            if ($value === PluginDatainjectionCommonInjectionLib::EMPTY_VALUE) {
                unset($current[$key]);
                continue;
            }
            $current[$key] = $value;
        }

        return (string) json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * For dropdown-typed custom fields, resolve the joined dropdown
     * table from the field's `itemtype` (e.g. `Location` → `glpi_locations`)
     * so the injection lib can look up by name and store the FK id.
     * Returns null for non-dropdown / unknown types.
     *
     * @param array{type:string, itemtype:?string} $field
     */
    private function customFieldFkTable(array $field): ?string
    {
        $type = strtolower((string) ($field['type'] ?? ''));
        if (!in_array($type, ['dropdown', 'foreignkey', 'foreign_key', 'itemlink'], true)) {
            return null;
        }
        $itemtype = $field['itemtype'] ?? null;
        if (!is_string($itemtype) || $itemtype === '' || !class_exists($itemtype)) {
            return null;
        }
        try {
            return $itemtype::getTable();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mapCustomFieldDisplayType(string $type): string
    {
        return match (strtolower($type)) {
            'bool', 'boolean', 'yesno' => 'bool',
            'date'                     => 'date',
            'datetime'                 => 'date',
            'number', 'integer', 'int' => 'text',
            'decimal', 'float'         => 'decimal',
            'text', 'string'           => 'text',
            'multiline_text', 'textarea' => 'multiline_text',
            'dropdown', 'foreignkey', 'foreign_key', 'itemlink' => 'dropdown',
            'url'                      => 'text',
            'user'                     => 'user',
            default                    => 'text',
        };
    }

    private function mapCustomFieldCheckType(string $type): string
    {
        return match (strtolower($type)) {
            'bool', 'boolean', 'yesno' => 'bool',
            'date'                     => 'date',
            'datetime'                 => 'date',
            'number', 'integer', 'int' => 'integer',
            'decimal', 'float'         => 'float',
            default                    => 'text',
        };
    }

    /**
     * Safe equivalent of
     * `PluginDatainjectionCommonInjectionLib::addToSearchOptions()` for
     * custom-asset itemtypes. The shared helper introspects each option's
     * table via `getItemTypeForTable($table)::getTypeName(1)`, which
     * fatals on `glpi_assets_assets` because the resolved itemtype
     * (`\Glpi\Asset\Asset`) has an uninitialised typed static property
     * (`$definition_system_name`). We don't need that introspection —
     * it's only used to append a `(Foo)` suffix to option names — so
     * skip it entirely and just do the bits that are actually load-bearing
     * for the Mappings UI:
     *   1. drop options without a `field` or in the blacklist
     *   2. mark the rest as `injectable=FIELD_INJECTABLE` if they carry a
     *      `linkfield`, otherwise `FIELD_VIRTUAL`
     *   3. derive a sensible `displaytype` / `checktype` if the option
     *      didn't already specify one
     *   4. apply the explicit displaytype/checktype overrides the caller
     *      collected
     *   5. dedupe by `linkfield`, preferring `completename` > `name` >
     *      first encountered
     *
     * @param array $tab     options returned by `Search::getOptions()`
     * @param array $options shape used by `addToSearchOptions`: ignore_fields,
     *                       displaytype/checktype maps
     */
    private function processSearchOptionsForCustomAsset(array $tab, array $options): array
    {
        $ignore_fields = $options['ignore_fields'] ?? [];

        // 1 + 2 + 3: filter & enrich
        foreach ($tab as $id => $opt) {
            if (!is_array($opt) || !isset($opt['field']) || in_array($id, $ignore_fields, true)) {
                unset($tab[$id]);
                continue;
            }
            $has_linkfield = isset($opt['linkfield']) && $opt['linkfield'] !== '';
            $tab[$id]['injectable'] = $has_linkfield
                ? PluginDatainjectionCommonInjectionLib::FIELD_INJECTABLE
                : PluginDatainjectionCommonInjectionLib::FIELD_VIRTUAL;
            if (!isset($opt['displaytype'])) {
                $datatype = $opt['datatype'] ?? '';
                $tab[$id]['displaytype'] = match ($datatype) {
                    'dropdown', 'itemlink' => 'dropdown',
                    'date'                 => 'date',
                    'datetime'             => 'date',
                    'bool', 'boolean'      => 'bool',
                    'number', 'integer'    => 'text',
                    'decimal', 'float'     => 'decimal',
                    'multiline_text', 'textarea' => 'multiline_text',
                    default                => 'text',
                };
            }
            if (!isset($opt['checktype'])) {
                $tab[$id]['checktype'] = match ($tab[$id]['displaytype']) {
                    'bool'   => 'bool',
                    'date'   => 'date',
                    default  => 'text',
                };
            }
        }

        // 4: apply caller-supplied displaytype / checktype overrides
        foreach (['displaytype', 'checktype'] as $paramtype) {
            if (!isset($options[$paramtype]) || !is_array($options[$paramtype])) {
                continue;
            }
            foreach ($options[$paramtype] as $type => $tabsID) {
                foreach ((array) $tabsID as $tabID) {
                    if (isset($tab[$tabID])) {
                        $tab[$tabID][$paramtype] = $type;
                    }
                }
            }
        }

        // 5: dedupe by linkfield — same precedence as
        // `addToSearchOptions`: prefer `completename` > `name` > first
        $preserved = [];
        foreach ($tab as $opt) {
            if (!isset($opt['linkfield'])) {
                continue;
            }
            $lf = $opt['linkfield'];
            if (!isset($preserved[$lf])) {
                $preserved[$lf] = $opt;
                continue;
            }
            if (($opt['field'] ?? '') === 'completename') {
                $preserved[$lf] = $opt;
            } elseif (
                ($opt['field'] ?? '') === 'name'
                && ($preserved[$lf]['field'] ?? '') !== 'completename'
            ) {
                $preserved[$lf] = $opt;
            }
        }
        // Rebuild the array keeping only the preserved entries (and any
        // options that have no linkfield at all — those are virtual /
        // informational rows we don't want to silently drop).
        $out = [];
        foreach ($tab as $id => $opt) {
            if (!isset($opt['linkfield']) || $opt['linkfield'] === '') {
                $out[$id] = $opt;
                continue;
            }
            if (isset($preserved[$opt['linkfield']]) && $preserved[$opt['linkfield']] === $opt) {
                $out[$id] = $opt;
            }
        }
        return $out;
    }

    /** @param array $tab @return array<int, int> */
    private function collectDropdownOptionIds(array $tab): array
    {
        $ids = [];
        foreach ($tab as $id => $option) {
            if (!is_array($option) || !is_numeric($id)) {
                continue;
            }
            $datatype = $option['datatype'] ?? '';
            if (in_array($datatype, ['dropdown', 'itemlink'], true)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /** @param array $tab @return array<int, int> */
    private function collectUserOptionIds(array $tab): array
    {
        $ids = [];
        foreach ($tab as $id => $option) {
            if (!is_array($option) || !is_numeric($id)) {
                continue;
            }
            if (($option['table'] ?? '') === 'glpi_users') {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Catalogue of the standard fields on `glpi_assets_assets`. Mapped to
     * the search-option metadata the injection lib needs: `name`, the
     * display type (so the lib renders dropdown / user / date inputs in
     * the mapping form), the check type (input validation), and for FK
     * columns the joined dropdown table.
     *
     * Each entry is keyed by the column name on `glpi_assets_assets`.
     *
     * @return array<string, array{
     *   name: string,
     *   displaytype: string,
     *   checktype:   string,
     *   table?:      string
     * }>
     */
    private function nativeAssetFieldCatalog(): array
    {
        return [
            'name'              => ['name' => __('Name'),                          'displaytype' => 'text',     'checktype' => 'text'],
            'serial'            => ['name' => __('Serial number'),                 'displaytype' => 'text',     'checktype' => 'text'],
            'otherserial'       => ['name' => __('Inventory number'),              'displaytype' => 'text',     'checktype' => 'text'],
            'contact'           => ['name' => __('Alternate username'),            'displaytype' => 'text',     'checktype' => 'text'],
            'contact_num'       => ['name' => __('Alternate username number'),     'displaytype' => 'text',     'checktype' => 'text'],
            'comment'           => ['name' => __('Comments'),                      'displaytype' => 'multiline_text', 'checktype' => 'text'],
            'entities_id'       => ['name' => __('Entity'),                        'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_entities'],
            'locations_id'      => ['name' => __('Location'),                      'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_locations'],
            'states_id'         => ['name' => __('Status'),                        'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_states'],
            'manufacturers_id'  => ['name' => __('Manufacturer'),                  'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_manufacturers'],
            'users_id'          => ['name' => __('User'),                          'displaytype' => 'user',     'checktype' => 'text', 'table' => 'glpi_users'],
            'groups_id'         => ['name' => __('Group'),                         'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_groups'],
            'users_id_tech'     => ['name' => __('Technician in charge'),          'displaytype' => 'user',     'checktype' => 'text', 'table' => 'glpi_users'],
            'groups_id_tech'    => ['name' => __('Group in charge'),               'displaytype' => 'dropdown', 'checktype' => 'text', 'table' => 'glpi_groups'],
            'date_creation'     => ['name' => __('Creation date'),                 'displaytype' => 'date',     'checktype' => 'date'],
            'date_mod'          => ['name' => __('Last update'),                   'displaytype' => 'date',     'checktype' => 'date'],
            'is_recursive'      => ['name' => __('Child entities'),                'displaytype' => 'bool',     'checktype' => 'bool'],
            'is_deleted'        => ['name' => __('Deleted'),                       'displaytype' => 'bool',     'checktype' => 'bool'],
            'is_dynamic'        => ['name' => __('Automatic inventory'),           'displaytype' => 'bool',     'checktype' => 'bool'],
            'is_template'       => ['name' => __('Template'),                      'displaytype' => 'bool',     'checktype' => 'bool'],
            'template_name'     => ['name' => __('Template name'),                 'displaytype' => 'text',     'checktype' => 'text'],
        ];
    }

    /**
     * Append injectable search-option entries for the standard columns of
     * `glpi_assets_assets` that the live table actually has. Returns the
     * number of entries appended. Entries already present in `$tab` with
     * the same `linkfield` are left untouched, so a definition that
     * already produced its own option for `name` (etc.) wins.
     *
     * @param array $tab passed by reference
     */
    private function appendNativeAssetFieldOptions(array &$tab): int
    {
        /** @var DBmysql|null $DB */
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return 0;
        }

        $existing_linkfields = [];
        foreach ($tab as $opt) {
            if (is_array($opt) && isset($opt['linkfield']) && is_string($opt['linkfield'])) {
                $existing_linkfields[$opt['linkfield']] = true;
            }
        }

        $catalog = $this->nativeAssetFieldCatalog();
        $nextId  = 4000;
        $count   = 0;
        foreach ($catalog as $column => $meta) {
            // Only expose columns that exist on the live table (different
            // GLPI versions / enabled capacities ship slightly different
            // column sets).
            try {
                if (!$DB->fieldExists(self::$table, $column)) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            if (isset($existing_linkfields[$column])) {
                continue;
            }
            $entry = [
                'id'          => $nextId,
                'table'       => $meta['table'] ?? self::$table,
                'field'       => ($meta['table'] ?? null) ? 'name' : $column,
                'linkfield'   => $column,
                'name'        => $meta['name'],
                'datatype'    => 'string',
                'injectable'  => PluginDatainjectionCommonInjectionLib::FIELD_INJECTABLE,
                'displaytype' => $meta['displaytype'],
                'checktype'   => $meta['checktype'],
            ];
            $tab[$nextId] = $entry;
            $nextId++;
            $count++;
        }
        return $count;
    }

    /**
     * Standard injection entry point — matches the legacy class signature.
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        $lib = new PluginDatainjectionCommonInjectionLib($this, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }
}
