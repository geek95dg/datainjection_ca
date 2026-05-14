<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of DataInjection.
 *
 * DataInjection is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * DataInjection is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DataInjection. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2007-2023 by DataInjection plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/datainjection
 * -------------------------------------------------------------------------
 */
use Glpi\Application\View\TemplateRenderer;
use Glpi\Debug\Profile;
use Glpi\Error\ErrorHandler;
use Safe\Exceptions\InfoException;

use function Safe\fclose;
use function Safe\filesize;
use function Safe\fopen;
use function Safe\fputcsv;
use function Safe\ini_set;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\readfile;
use function Safe\unlink;

class PluginDatainjectionClientInjection
{
    public static $rightname = "plugin_datainjection_use";

    public const STEP_UPLOAD  = 0;
    public const STEP_PROCESS = 1;
    public const STEP_RESULT  = 2;

    /**
    * Print a good title for group pages
    *
    *@return void nothing (display)
   **/
    public function title(): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $buttons =  [];
        $title   = "";

        if (Session::haveRight(static::$rightname, UPDATE)) {
            $url           = Toolbox::getItemTypeSearchURL('PluginDatainjectionModel');
            $buttons[$url] = PluginDatainjectionModel::getTypeName();
            $title         = "";
            Html::displayTitle(
                plugin_datainjection_geturl() . "pics/datainjection.png",
                PluginDatainjectionModel::getTypeName(),
                $title,
                $buttons,
            );
        }
    }


    public function showForm($ID, $options = [])
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        TemplateRenderer::getInstance()->display('@datainjection/clientinjection.html.twig', [
            'form_action' => Toolbox::getItemTypeFormURL(self::class),
            'models' => PluginDatainjectionModel::getModels(Session::getLoginUserID(), 'name', $_SESSION['glpiactive_entity'], false),
            'can_create_model' => Session::haveRight('plugin_datainjection_model', CREATE),
            'model_type_name' => PluginDatainjectionModel::getTypeName(),
            // Coerce to int so the twig template never emits a bare
            // `const modelId = ;` when the session has no value (which the
            // browser parses as a SyntaxError).
            'models_id' => (int) (PluginDatainjectionSession::getParam('models_id') ?: 0),
            'step' => (int) (PluginDatainjectionSession::getParam('step') ?: 0),
            'upload_url' => $CFG_GLPI['root_doc'] . "/plugins/datainjection/ajax/dropdownSelectModel.php",
            'result_url' => $CFG_GLPI['root_doc'] . "/plugins/datainjection/ajax/results.php",
            'params' => ['models_id' => PluginDatainjectionSession::getParam('models_id')],
            'upload_step' => self::STEP_UPLOAD,
            'result_step' => (int) self::STEP_RESULT,
        ]);
    }


    /**
    * @param array $options
   **/
    public static function showUploadFileForm($options = [])
    {
        $add_form = (isset($options['add_form']) && $options['add_form']);
        $confirm  = ($options['confirm'] ?? false);
        $url      = (($confirm == 'creation') ? Toolbox::getItemTypeFormURL('PluginDatainjectionModel')
                                                : Toolbox::getItemTypeFormURL(self::class));

        $data = [
            'add_form' => $add_form,
            'url' => $url,
            'models_id' => $options['models_id'] ?? null,
            'confirm' => $confirm,
            'submit_label' => $options['submit'] ?? __('Launch the import', 'datainjection'),
            'file_encoding_values' => PluginDatainjectionDropdown::getFileEncodingValue(),
        ];

        TemplateRenderer::getInstance()->display('@datainjection/clientinjection_upload_file.html.twig', $data);
    }


    /**
    * @param PluginDatainjectionModel $model PluginDatainjectionModel object
    * @param integer $entities_id
   **/
    public static function showInjectionForm(PluginDatainjectionModel $model, $entities_id)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!PluginDatainjectionSession::getParam('infos')) {
            PluginDatainjectionSession::setParam('infos', []);
        }

        $nblines = PluginDatainjectionSession::getParam('nblines');

        //Read all CSV lines into session for batch processing
        $backend = $model->getBackend();
        $model->loadSpecificModel();
        $backend->openFile();

        $lines = [];
        $line  = $backend->getNextLine();

        //If header is present, skip it
        if ($model->getSpecificModel()->isHeaderPresent()) {
            $line = $backend->getNextLine();
        }

        while ($line != null) {
            $lines[] = $line;
            $line    = $backend->getNextLine();
        }
        $backend->closeFile();

        //Store lines in session for batch processing
        PluginDatainjectionSession::setParam('injection_lines', json_encode($lines));
        PluginDatainjectionSession::setParam('injection_results', json_encode([]));
        PluginDatainjectionSession::setParam('injection_error_lines', json_encode([]));

        $batch_url  = $CFG_GLPI['root_doc'] . "/plugins/datainjection/ajax/inject_batch.php";
        $result_url = $CFG_GLPI['root_doc'] . "/plugins/datainjection/ajax/results.php";

        TemplateRenderer::getInstance()->display('@datainjection/clientinjection_injection.html.twig', [
            'model_name'  => $model->fields['name'],
            'nblines'     => $nblines,
            'model_id'    => $model->fields['id'],
            'batch_url'   => $batch_url,
            'result_url'  => $result_url,
            'plugin_url'  => plugin_datainjection_geturl(),
            // Where the "Abort and start over" button POSTs cancel=1.
            'form_action' => Toolbox::getItemTypeFormURL(self::class),
        ]);

        echo "<span id='span_injection' name='span_injection'></span>";
    }


    /**
    * Process a batch of injection lines.
    *
    * @param int $offset     Starting line offset
    * @param int $batch_size Number of lines to process in this batch
    *
    * @return array JSON-serializable result with progress info
   **/
    public static function processBatch(int $offset, int $batch_size): array
    {
        try {
            ini_set("max_execution_time", "0");
        } catch (InfoException $e) {
            ErrorHandler::logCaughtException($e);
        }

        Profile::getCurrent()->disable();

        PluginDatainjectionLogger::info('processBatch: unserialize model', [
            'offset' => $offset,
        ]);
        $model = unserialize($_SESSION['datainjection']['currentmodel']);
        $model->loadSpecificModel();
        $entities_id = $_SESSION['glpiactive_entity'];
        $lines_json  = PluginDatainjectionSession::getParam('injection_lines');
        $lines       = json_decode($lines_json, true);
        PluginDatainjectionLogger::info('processBatch: lines decoded', [
            'lines_count'    => is_array($lines) ? count($lines) : null,
            'lines_is_array' => is_array($lines),
            'json_len'       => is_string($lines_json) ? strlen($lines_json) : null,
        ]);

        $results_json     = PluginDatainjectionSession::getParam('injection_results');
        $results          = json_decode($results_json, true) ?: [];
        $error_lines_json = PluginDatainjectionSession::getParam('injection_error_lines');
        $error_lines      = json_decode($error_lines_json, true) ?: [];

        $engine = new PluginDatainjectionEngine(
            $model,
            PluginDatainjectionSession::getParam('infos'),
            $entities_id,
        );

        $header_offset = $model->getSpecificModel()->isHeaderPresent() ? 2 : 1;
        $total         = count($lines ?: []);
        $end           = min($offset + $batch_size, $total);

        PluginDatainjectionLogger::info('processBatch: starting injection loop', [
            'offset'        => $offset,
            'end'           => $end,
            'total'         => $total,
            'header_offset' => $header_offset,
            'itemtype'      => method_exists($model, 'getItemtype') ? $model->getItemtype() : null,
        ]);
        $batch_started_at = microtime(true);

        // Cheap pretty-print for status codes — translate the int into
        // its constant name so the log is readable without cross-referencing.
        $statusLabel = static function (int $s): string {
            return match ($s) {
                PluginDatainjectionCommonInjectionLib::SUCCESS        => 'SUCCESS',
                PluginDatainjectionCommonInjectionLib::FAILED         => 'FAILED',
                PluginDatainjectionCommonInjectionLib::WARNING        => 'WARNING',
                PluginDatainjectionCommonInjectionLib::TYPE_MISMATCH  => 'TYPE_MISMATCH',
                PluginDatainjectionCommonInjectionLib::MANDATORY      => 'MANDATORY',
                PluginDatainjectionCommonInjectionLib::ITEM_NOT_FOUND => 'ITEM_NOT_FOUND',
                default                                              => (string) $s,
            };
        };

        for ($i = $offset; $i < $end; $i++) {
            $injectionline = $i + $header_offset;
            // Per-line breadcrumb: when a batch dies mid-loop, the last
            // `processBatch: injectLine pre` line tells us which CSV row
            // caused it (matched against the source file via
            // $injectionline). Wrap injectLine() itself so a thrown
            // exception inside one line records a structured error result
            // instead of killing the entire batch.
            //
            // We also dump a short preview of the raw row data and the
            // current memory footprint so the next silent death (PHP
            // fatal that bypasses both \Throwable and the shutdown
            // handler) leaves us with both "which row" and "was memory
            // climbing".
            $row_for_preview = $lines[$i][0] ?? null;
            $preview         = null;
            if (is_array($row_for_preview)) {
                $joined  = implode(' | ', array_map(
                    static fn($v) => is_scalar($v) ? (string) $v : gettype($v),
                    $row_for_preview,
                ));
                $preview = mb_strlen($joined) > 240
                    ? mb_substr($joined, 0, 240) . '…'
                    : $joined;
            }
            PluginDatainjectionLogger::info('processBatch: injectLine pre', [
                'i'             => $i,
                'injectionline' => $injectionline,
                'cols'          => is_array($row_for_preview) ? count($row_for_preview) : null,
                'preview'       => $preview,
                'mem_mb'        => round(memory_get_usage(true) / (1024 * 1024), 1),
                'mem_peak_mb'   => round(memory_get_peak_usage(true) / (1024 * 1024), 1),
            ]);
            $line_started_at = microtime(true);
            try {
                $result = $engine->injectLine($lines[$i][0], $injectionline);
            } catch (\Throwable $e) {
                PluginDatainjectionLogger::exception(
                    $e,
                    'processBatch: injectLine threw on line ' . $injectionline,
                );
                $result = [
                    'status'        => PluginDatainjectionCommonInjectionLib::FAILED,
                    'line'          => $injectionline,
                    'error_message' => $e->getMessage(),
                ];
            }
            $elapsed_ms = (int) round((microtime(true) - $line_started_at) * 1000);

            // Surface why a row failed. Every non-SUCCESS result now
            // logs at WARN with the row's i, its translated status
            // name, AND the full dump of $result. Earlier diagnostics
            // showed `error_message`/`field_in_error` keys are null,
            // which means the injection lib reports the rejection via
            // some *other* key (commonly `values_to_inject` + per-field
            // status, or a nested `errors[]` array). Dump the whole
            // structure (truncated) so we can see which keys the lib
            // actually populates.
            $status_int = (int) ($result['status'] ?? -1);
            if ($status_int !== PluginDatainjectionCommonInjectionLib::SUCCESS) {
                $result_keys = is_array($result) ? array_keys($result) : null;
                $result_dump = is_array($result) ? json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
                ) : null;
                if (is_string($result_dump) && strlen($result_dump) > 1200) {
                    $result_dump = substr($result_dump, 0, 1200) . '…';
                }
                PluginDatainjectionLogger::warning('processBatch: injectLine non-success', [
                    'i'              => $i,
                    'injectionline'  => $injectionline,
                    'status'         => $status_int,
                    'status_label'   => $statusLabel($status_int),
                    'error_message'  => $result['error_message']
                                        ?? $result['message']
                                        ?? null,
                    'field_in_error' => $result['field_in_error'] ?? null,
                    'result_keys'    => $result_keys,
                    'result_dump'    => $result_dump,
                    'elapsed_ms'     => $elapsed_ms,
                ]);
            }
            // Symmetric "post" breadcrumb. A missing `post` for the i we
            // last saw `pre` for is the unambiguous signature that
            // injectLine died mid-call without throwing.
            PluginDatainjectionLogger::info('processBatch: injectLine post', [
                'i'             => $i,
                'injectionline' => $injectionline,
                'status'        => $result['status'] ?? null,
                'status_label'  => $statusLabel($status_int),
                'elapsed_ms'    => $elapsed_ms,
                'mem_mb'        => round(memory_get_usage(true) / (1024 * 1024), 1),
            ]);
            $results[]     = $result;

            if ($result['status'] != PluginDatainjectionCommonInjectionLib::SUCCESS) {
                $error_lines[] = $lines[$i][0];
            }
        }

        $batch_elapsed_ms = (int) round((microtime(true) - $batch_started_at) * 1000);
        PluginDatainjectionLogger::info('processBatch: loop done', [
            'offset'           => $offset,
            'end'              => $end,
            'batch_elapsed_ms' => $batch_elapsed_ms,
            'lines_in_batch'   => $end - $offset,
        ]);

        //Store updated results
        PluginDatainjectionSession::setParam('injection_results', json_encode($results));
        PluginDatainjectionSession::setParam('injection_error_lines', json_encode($error_lines));

        $done     = ($end >= $total);
        $progress = $total > 0 ? round(($end / $total) * 100, 1) : 100;

        if ($done) {
            //Finalize: move results to the standard session params, clean up
            PluginDatainjectionSession::setParam('results', json_encode($results));
            PluginDatainjectionSession::setParam('error_lines', json_encode($error_lines));

            $_SESSION['datainjection']['step'] = self::STEP_RESULT;
            unset($_SESSION['datainjection']['go']);

            //Delete CSV file
            $backend = $model->getBackend();
            $backend->deleteFile();
        }

        Profile::getCurrent()->enable();

        return [
            'progress'  => $progress,
            'done'      => $done,
            'offset'    => $end,
            'total'     => $total,
            'processed' => $end,
        ];
    }


    /**
    * to be used instead of Toolbox::stripslashes_deep to reduce memory usage
    * execute stripslashes in place (no copy)
    *
    * @param array|string|null $value array of value
    */
    public static function stripslashes_array(&$value) // phpcs:ignore
    {

        if (is_array($value)) {
            foreach (array_keys($value) as $key) {
                self::stripslashes_array($value[$key]);
            }
        } elseif (!is_null($value)) {
            $value = stripslashes($value);
        }
    }


    /**
    * @param PluginDatainjectionModel $model  PluginDatainjectionModel object
   **/
    public static function showResultsForm(PluginDatainjectionModel $model)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $results     = json_decode(PluginDatainjectionSession::getParam('results'), true);
        self::stripslashes_array($results);
        $error_lines = json_decode(PluginDatainjectionSession::getParam('error_lines'), true);
        self::stripslashes_array($error_lines);

        $ok = true;
        foreach ($results as $result) {
            if ($result['status'] != PluginDatainjectionCommonInjectionLib::SUCCESS) {
                $ok = false;
                break;
            }
        }

        $from_url = plugin_datainjection_geturl() . "front/clientinjection.form.php";
        $plugin      = new Plugin();

        $data = [
            'ok'            => $ok,
            'from_url'      => $from_url,
            'popup_url'     => plugin_datainjection_geturl() . "front/popup.php?popup=log&models_id=" . $model->fields['id'],
            'model_id'      => $model->fields['id'],
            'has_pdf'       => $plugin->isActivated('pdf'),
            'has_errors'    => !empty($error_lines),
        ];

        TemplateRenderer::getInstance()->display('@datainjection/clientinjection_result.html.twig', $data);
    }

    public static function exportErrorsInCSV()
    {

        $error_lines = json_decode(PluginDatainjectionSession::getParam('error_lines'), true);
        self::stripslashes_array($error_lines);

        if (!empty($error_lines)) {
            $model = unserialize(PluginDatainjectionSession::getParam('currentmodel'));
            $file  = PLUGIN_DATAINJECTION_UPLOAD_DIR . PluginDatainjectionSession::getParam('file_name');

            $mappings = $model->getMappings();
            $tmpfile  = fopen($file, 'w');

            //If headers present
            if ($model->getBackend()->isHeaderPresent()) {
                $headers = PluginDatainjectionMapping::getMappingsSortedByRank($model->fields['id']);
                fputcsv($tmpfile, $headers, $model->getBackend()->getDelimiter());
            }

            //Write lines
            foreach ($error_lines as $line) {
                fputcsv($tmpfile, $line, $model->getBackend()->getDelimiter());
            }
            fclose($tmpfile);

            $name = "Error-" . PluginDatainjectionSession::getParam('file_name');
            $name = str_replace(' ', '', $name);
            header('Content-disposition: attachment; filename=' . $name);
            header('Content-Type: application/octet-stream');
            header('Content-Transfer-Encoding: fichier');
            header('Content-Length: ' . filesize($file));
            header('Pragma: no-cache');
            header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            readfile($file);
            unlink($file);
        }
    }
}
