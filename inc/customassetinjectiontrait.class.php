<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Trait used by injection classes generated for GLPI 11 custom assets.
 *
 * The dynamically-built class extends the dynamic asset class (a subclass
 * of \Glpi\Asset\Asset) and uses this trait. It must declare a
 * `getDefinitionId(): int` static method pointing to the
 * `glpi_assets_assetdefinitions` row it represents — the registry
 * generates that automatically.
 * -------------------------------------------------------------------------
 */

trait PluginDatainjectionCustomAssetInjectionTrait
{
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
     * Return the stable itemtype name for this injection class. Custom
     * assets all share `glpi_assets_assets` so we cannot rely on the table
     * name to derive itemtype — we expose the dynamic class FQCN instead.
     */
    public function getInjectionItemtype(): string
    {
        return get_parent_class($this);
    }

    /**
     * Return search options for the underlying asset itemtype plus the
     * custom fields exposed by its definition.
     */
    public function getOptions($primary_type = '')
    {
        $parent = get_parent_class($this);
        $tab    = [];

        try {
            $tab = Search::getOptions($parent);
        } catch (\Throwable $e) {
            $tab = [];
        }

        $options = [
            'ignore_fields' => PluginDatainjectionCommonInjectionLib::getBlacklistedOptions($parent),
            'displaytype'   => [
                'dropdown' => $this->collectDropdownOptionIds($tab),
                'user'     => $this->collectUserOptionIds($tab),
            ],
        ];

        $tab = PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);

        // Append per-definition custom fields. We use option ids well above
        // the 1000 reserved range and outside the template/standard space.
        $defId         = static::getDefinitionId();
        $customFields  = PluginDatainjectionCustomAssetRegistry::getCustomFields($defId);
        $nextId        = 5000;
        $table         = method_exists($parent, 'getTable') ? $parent::getTable() : 'glpi_assets_assets';

        foreach ($customFields as $field) {
            $linkfield = PluginDatainjectionCustomAssetRegistry::CUSTOM_FIELD_LINK_PREFIX . $field['key'];
            $displaytype = $this->mapCustomFieldDisplayType($field['type']);
            $checktype   = $this->mapCustomFieldCheckType($field['type']);

            $tab[$nextId] = [
                'id'          => $nextId,
                'table'       => $table,
                'field'       => $linkfield,
                'linkfield'   => $linkfield,
                'name'        => sprintf(
                    __('%1$s (custom field)', 'datainjection'),
                    $field['label'],
                ),
                'datatype'    => 'string',
                'injectable'  => PluginDatainjectionCommonInjectionLib::FIELD_INJECTABLE,
                'displaytype' => $displaytype,
                'checktype'   => $checktype,
            ];
            $nextId++;
        }

        return $tab;
    }

    /**
     * Restrict "already in DB" lookups to rows belonging to this definition;
     * otherwise two custom assets sharing the same identifier would be
     * conflated (all rows live in `glpi_assets_assets`).
     */
    public function checkPresent($values, $options)
    {
        return " AND `assets_assetdefinitions_id` = '" . (int) static::getDefinitionId() . "' ";
    }

    /**
     * Hook called by PluginDatainjectionCommonInjectionLib::effectiveAddOrUpdate
     * for both add and update. We extract custom field values from $fields,
     * encode them into the `custom_fields` JSON column and persist via the
     * underlying asset class.
     *
     * @param array        $fields The normalised values prepared for injection.
     * @param bool         $add    True when adding, false when updating.
     * @param array<mixed> $rights Plugin rights (unused here).
     *
     * @return int|false
     */
    public function customimport($fields, $add, $rights)
    {
        $prefix      = PluginDatainjectionCustomAssetRegistry::CUSTOM_FIELD_LINK_PREFIX;
        $prefixLen   = strlen($prefix);
        $customValues = [];

        foreach ($fields as $key => $value) {
            if (is_string($key) && strncmp($key, $prefix, $prefixLen) === 0) {
                $customKey = substr($key, $prefixLen);
                $customValues[$customKey] = $value;
                unset($fields[$key]);
            }
        }

        $parent = get_parent_class($this);
        /** @var CommonDBTM $item */
        $item = new $parent();

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
     *
     * @param mixed                $existing Raw value as stored in DB (string|array|null).
     * @param array<string, mixed> $values   New values keyed by field key.
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
            // Treat the canonical empty marker as "unset"
            if ($value === PluginDatainjectionCommonInjectionLib::EMPTY_VALUE) {
                unset($current[$key]);
                continue;
            }
            $current[$key] = $value;
        }

        return json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * Build the list of search-option ids that should be rendered as
     * GLPI dropdowns.
     *
     * @param array $tab
     * @return array<int, int>
     */
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
}
