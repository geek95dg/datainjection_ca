<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Model parameters specific to XLSX imports. The class mirrors the CSV
 * variant but does not expose a delimiter — Excel cells are already
 * structured.
 * -------------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;

class PluginDatainjectionCaModelxlsx extends CommonDBChild
{
    public static $rightname = 'plugin_datainjection_ca_model';
    public $specific_fields;

    // From CommonDBChild
    public static $itemtype  = 'PluginDatainjectionCaModel';
    public static $items_id  = 'models_id';
    public $dohistory        = true;

    public function getEmpty()
    {
        $this->fields['is_header_present'] = 1;
        // Stored for API parity with the CSV model; not used while reading.
        $this->fields['delimiter']         = '';
        return true;
    }

    public function init() {}

    public function getDelimiter()
    {
        return '';
    }

    public function isHeaderPresent()
    {
        return $this->fields['is_header_present'] ?? 1;
    }

    public function haveSample()
    {
        return $this->fields['is_header_present'] ?? 1;
    }

    /**
     * Stream a tab-separated sample to the browser. Tabs are accepted by
     * Excel when pasted and let users round-trip the headers without
     * needing a real .xlsx writer in PHP.
     */
    public function showSample(PluginDatainjectionCaModel $model)
    {
        $headers = PluginDatainjectionCaMapping::getMappingsSortedByRank($model->fields['id']);
        $sample  = implode("\t", $headers) . "\n";
        $name    = str_replace(' ', '_', $model->getName());

        header('Content-disposition: attachment; filename="' . $name . '.txt"');
        header('Content-Type: text/tab-separated-values; charset=UTF-8');
        header('Content-Transfer-Encoding: UTF-8');
        header('Content-Length: ' . strlen($sample));
        header('Pragma: no-cache');
        header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');
        echo $sample;
    }

    /**
     * Only allow .xlsx uploads. Matches the (inverted) semantics used by
     * the CSV variant: returns *true* when the filename is rejected.
     */
    public function checkFileName($filename)
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        return $extension !== 'xlsx';
    }

    public function getFromDBByModelID($models_id)
    {
        /** @var DBmysql $DB */
        global $DB;

        $query = "SELECT `id`
                FROM `" . $this->getTable() . "`
                WHERE `models_id` = '" . (int) $models_id . "'";

        $results = $DB->doQuery($query);
        $id = 0;

        if ($DB->numrows($results) > 0) {
            $id = $DB->result($results, 0, 'id');
            $this->getFromDB($id);
        } else {
            $this->getEmpty();
            $tmp = $this->fields;
            $tmp['models_id'] = $models_id;
            $id  = $this->add($tmp);
            $this->getFromDB($id);
        }
        return $id;
    }

    public function showAdditionnalForm(PluginDatainjectionCaModel $model, $options = [])
    {
        $id      = $this->getFromDBByModelID($model->fields['id']);
        $canedit = $this->can($id, UPDATE);

        $data = [
            'canedit'           => $canedit,
            'is_header_present' => $this->isHeaderPresent(),
        ];

        TemplateRenderer::getInstance()->display('@datainjection_ca/modelxlsx_additional_form.html.twig', $data);
    }

    public function saveFields($fields)
    {
        $xlsx                       = clone $this;
        $tmp['models_id']           = $fields['id'];
        $tmp['is_header_present']   = $fields['is_header_present'] ?? 1;
        $xlsx->getFromDBByModelID($fields['id']);
        $tmp['id']                  = $xlsx->fields['id'];
        $xlsx->update($tmp);
    }
}
