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

        $options = [
            'ignore_fields' => PluginDatainjectionCommonInjectionLib::getBlacklistedOptions($assetClass),
            'displaytype'   => [
                'dropdown' => $this->collectDropdownOptionIds($tab),
                'user'     => $this->collectUserOptionIds($tab),
            ],
        ];

        $tab = PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);

        $defId        = static::getDefinitionId();
        $customFields = PluginDatainjectionCustomAssetRegistry::getCustomFields($defId);
        $nextId       = 5000;

        foreach ($customFields as $field) {
            $linkfield   = PluginDatainjectionCustomAssetRegistry::CUSTOM_FIELD_LINK_PREFIX . $field['key'];
            $displaytype = $this->mapCustomFieldDisplayType($field['type']);
            $checktype   = $this->mapCustomFieldCheckType($field['type']);

            $tab[$nextId] = [
                'id'          => $nextId,
                'table'       => self::$table,
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
     * Standard injection entry point — matches the legacy class signature.
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        $lib = new PluginDatainjectionCommonInjectionLib($this, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }
}
