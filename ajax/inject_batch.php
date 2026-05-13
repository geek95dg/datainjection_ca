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

header("Content-Type: application/json; charset=UTF-8");
Html::header_nocache();

Session::checkCentralAccess();

$offset     = (int) ($_POST['offset'] ?? 0);
$batch_size = (int) ($_POST['batch_size'] ?? 10);

// Wrap the actual batch call: any throw turns into a 500 that the
// progress JS can't recover from. Log it with full context, then return
// a JSON body the JS can parse so the user sees a real error message
// (and the "abort & start over" button stays clickable).
try {
    PluginDatainjectionLogger::info('inject_batch.php: enter', [
        'offset'     => $offset,
        'batch_size' => $batch_size,
    ]);
    $result = PluginDatainjectionClientInjection::processBatch($offset, $batch_size);
    PluginDatainjectionLogger::info('inject_batch.php: ok', [
        'offset'    => $offset,
        'processed' => is_array($result) ? count($result) : null,
    ]);
    echo json_encode($result);
} catch (\Throwable $e) {
    PluginDatainjectionLogger::exception($e, 'inject_batch.php failed at offset ' . $offset);
    // Don't propagate the throw — that produces a generic 500 with no
    // body, which jQuery interprets as a network failure and the
    // progress bar gets stuck. Instead return a structured error so the
    // JS can show the message and stop polling.
    http_response_code(500);
    echo json_encode([
        'error'    => true,
        'message'  => $e->getMessage(),
        'offset'   => $offset,
    ]);
}
