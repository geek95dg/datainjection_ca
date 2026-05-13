<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Form Category injection.
 *
 * GLPI 11 emits `Glpi\Form\Category` as a `final` class, so we cannot
 * `extends Category` directly — PHP refuses to compile such a class and
 * the resulting fatal happens before any of our try/catch wrappers run.
 *
 * Instead we extend `CommonTreeDropdown` (the underlying type Category
 * itself extends) and delegate the actual persist call to a freshly-
 * instantiated Category in `customimport()`. Instantiating a final class
 * is permitted; only extending it isn't.
 *
 * Same composition pattern used by `PluginDatainjectionCustomAssetBaseInjection`.
 * -------------------------------------------------------------------------
 */

class PluginDatainjectionCategoryInjection extends CommonTreeDropdown implements PluginDatainjectionInjectionInterface
{
    public const TARGET_CLASS = '\\Glpi\\Form\\Category';

    public static function getTable($classname = null)
    {
        if (class_exists(self::TARGET_CLASS)) {
            $cls = self::TARGET_CLASS;
            return $cls::getTable();
        }
        // Fallback (older / form-less GLPI builds) — keep the convention.
        return 'glpi_forms_categories';
    }

    public function getInjectionItemtype(): string
    {
        return ltrim(self::TARGET_CLASS, '\\');
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
        return !in_array($field, ['illustration']);
    }

    /**
     * @see plugins/datainjection/inc/PluginDatainjectionInjectionInterface::getOptions()
     */
    public function getOptions($primary_type = '')
    {
        $tab = [];
        if (class_exists(self::TARGET_CLASS)) {
            try {
                $tab = Search::getOptions(self::TARGET_CLASS);
            } catch (\Throwable $e) {
                if (class_exists('PluginDatainjectionLogger')) {
                    PluginDatainjectionLogger::exception($e, 'Search::getOptions failed for ' . self::TARGET_CLASS);
                }
                $tab = [];
            }
        }

        $blacklist                = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions(self::TARGET_CLASS);
        $options['ignore_fields'] = $blacklist;

        return PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);
    }

    /**
     * @see plugins/datainjection/inc/PluginDatainjectionInjectionInterface::addOrUpdateObject()
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        $values = $this->fixCategoryTreeStructure($values);
        $lib    = new PluginDatainjectionCommonInjectionLib($this, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }

    public function fixCategoryTreeStructure($values)
    {
        if (isset($values['Category']['completename']) && !isset($values['Category']['name']) && !str_contains($values['Category']['completename'], '>')) {
            $values['Category']['name']                = trim($values['Category']['completename']);
            $values['Category']['forms_categories_id'] = '0';
            $values['Category']['ancestors_cache']     = '[]';
        }

        return $values;
    }

    /**
     * Delegate the actual DB write to the (final) target class. Mirrors the
     * pattern used by `PluginDatainjectionCustomAssetBaseInjection`.
     */
    public function customimport($fields, $add, $rights)
    {
        $cls = self::TARGET_CLASS;
        if (!class_exists($cls)) {
            if (class_exists('PluginDatainjectionLogger')) {
                PluginDatainjectionLogger::error('CategoryInjection: target class missing', ['target' => $cls]);
            }
            return false;
        }

        /** @var CommonDBTM $item */
        $item = new $cls();

        if ($add) {
            unset($fields['id']);
            $newID = $item->add($fields);
            return $newID ?: false;
        }

        $id = (int) ($fields['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        return $item->update($fields) ? $id : false;
    }
}
