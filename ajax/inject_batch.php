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

// Always declare the response Content-Type before anything that can throw,
// so jQuery's `dataType: 'json'` never falls back to HTML parsing in the
// error path.
header("Content-Type: application/json; charset=UTF-8");

// Raise PHP runtime limits BEFORE we hand control to GLPI / processBatch.
// Symptoms previously observed: batch 2 (offset=10) dies mid-injectLine
// with no logged exception, no PHP fatal, no entry in php-fpm/apache
// error.log. That pattern is consistent with the worker tripping over
// memory_limit, max_execution_time, or being aborted by the client.
// `@` because some installs disable ini_set for these knobs — we want
// best effort, not a crash.
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '0');
@set_time_limit(0);
ignore_user_abort(true);

// Install a LOCAL shutdown handler that always fires, regardless of
// setup.php's plugin-path filter. If injectLine dies inside vendor/GLPI
// code (so error_get_last()['file'] doesn't contain __DIR__) the global
// handler in setup.php currently bails. This local one keys off the
// fact that we're inside inject_batch.php — anything fatal here is by
// definition relevant to the batch loop.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (!($err['type'] & $fatal_types)) {
        return;
    }
    $msg = (string) ($err['message'] ?? 'unknown');
    $loc = (string) ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?');
    try {
        if (class_exists('PluginDatainjectionLogger')) {
            PluginDatainjectionLogger::error('inject_batch.php fatal', [
                'type'       => $err['type'] ?? null,
                'message'    => $msg,
                'where'      => $loc,
                'mem_mb'     => round(memory_get_usage(true) / (1024 * 1024), 1),
                'mem_peak_mb'=> round(memory_get_peak_usage(true) / (1024 * 1024), 1),
            ]);
        }
    } catch (\Throwable $e) {
        @error_log('[datainjection] inject_batch fatal in ' . $loc . ' — ' . $msg);
    }
    // If headers haven't been sent yet, surface a structured JSON 500
    // so the progress JS shows a banner instead of hanging.
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode([
            'error'   => true,
            'message' => 'inject_batch.php fatal: ' . $msg,
            'where'   => $loc,
            'class'   => 'PHP_FATAL',
        ]);
    }
});

// Breadcrumb the request *before* touching any GLPI symbol. If
// `Html::header_nocache()` or `Session::checkCentralAccess()` later throws,
// we want a log entry proving we at least entered the script. Anything
// thrown above this line is unreachable from the plugin (PHP would have
// 500'd on parse).
try {
    if (class_exists('PluginDatainjectionLogger')) {
        PluginDatainjectionLogger::info('inject_batch.php: received', [
            'method'         => $_SERVER['REQUEST_METHOD'] ?? null,
            'has_session'    => session_status() === PHP_SESSION_ACTIVE,
            'post_keys'      => array_keys($_POST),
            'memory_limit'   => ini_get('memory_limit'),
            'max_exec_time'  => ini_get('max_execution_time'),
        ]);
    }
} catch (\Throwable $e) {
    // Logger setup hiccup must not block the actual import flow.
}

$offset     = (int) ($_POST['offset'] ?? 0);
$batch_size = (int) ($_POST['batch_size'] ?? 10);

// Wrap EVERYTHING — including the GLPI helpers that run before the actual
// batch call. Previous revision left `Html::header_nocache()` /
// `Session::checkCentralAccess()` outside the try/catch, so anything they
// threw produced GLPI's generic "An unexpected error occurred" JSON
// response with no plugin log line.
try {
    Html::header_nocache();
    Session::checkCentralAccess();

    PluginDatainjectionLogger::info('inject_batch.php: enter', [
        'offset'        => $offset,
        'batch_size'    => $batch_size,
        // Confirm the prerequisites set up by showInjectionForm() / the
        // upload step are actually present. A missing key is the most
        // likely cause of an immediate fail on first batch.
        'has_model'     => isset($_SESSION['datainjection']['currentmodel']),
        'has_lines'     => isset($_SESSION['datainjection']['injection_lines']),
        'lines_len'     => isset($_SESSION['datainjection']['injection_lines'])
            ? strlen((string) $_SESSION['datainjection']['injection_lines'])
            : null,
    ]);

    // Friendlier diagnostics: if the upload step never populated the
    // session, fail with a specific message instead of letting
    // processBatch() blow up on a null unserialize.
    if (!isset($_SESSION['datainjection']['currentmodel'])) {
        throw new RuntimeException(
            'Session lost the import model. Re-upload the file to restart the import.',
        );
    }
    if (!isset($_SESSION['datainjection']['injection_lines'])) {
        throw new RuntimeException(
            'Session lost the parsed file rows. Re-upload the file to restart the import.',
        );
    }

    $result = PluginDatainjectionClientInjection::processBatch($offset, $batch_size);
    PluginDatainjectionLogger::info('inject_batch.php: ok', [
        'offset'    => $offset,
        'processed' => is_array($result) ? ($result['processed'] ?? null) : null,
        'done'      => is_array($result) ? ($result['done'] ?? null) : null,
    ]);
    echo json_encode($result);
} catch (\Throwable $e) {
    if (class_exists('PluginDatainjectionLogger')) {
        PluginDatainjectionLogger::exception(
            $e,
            'inject_batch.php failed at offset ' . $offset,
        );
    }
    // Don't propagate the throw — that produces a generic 500 with no
    // body, which jQuery interprets as a network failure and the
    // progress bar gets stuck. Instead return a structured error so the
    // JS can show the message and stop polling.
    http_response_code(500);
    echo json_encode([
        'error'    => true,
        // Expose the concrete failure to the operator. GLPI's ErrorHandler
        // sometimes rewrites $e->getMessage() to a localised generic
        // string ("An unexpected error occurred" / "Wystąpił nieoczekiwany
        // błąd"), so also surface the exception class and file:line —
        // that pair tells you exactly where to look in the log without
        // having to dig.
        'message'  => $e->getMessage(),
        'where'    => $e->getFile() . ':' . $e->getLine(),
        'class'    => get_class($e),
        'offset'   => $offset,
    ]);
}
