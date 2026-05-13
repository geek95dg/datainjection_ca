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

Session::checkRight("plugin_datainjection_use", READ);

// Wrap the controller exactly like model.form.php: every branch logs its
// entry, and any \Throwable is caught, logged with a full trace, then
// re-thrown so GLPI still renders its normal error page. `Glpi\Exception\
// RedirectException` is GLPI's normal 302 signal and is NOT treated as
// an error.
$di_log_branch = 'idle';
try {
    PluginDatainjectionLogger::info('clientinjection.form.php: enter', [
        'method'    => $_SERVER['REQUEST_METHOD'] ?? null,
        'post_keys' => array_keys($_POST),
        'get_keys'  => array_keys($_GET),
        'has_go'    => isset($_SESSION['datainjection']['go']),
        'has_files' => !empty($_FILES),
    ]);

    Html::header(
        __('Data injection', 'datainjection'),
        $_SERVER["PHP_SELF"],
        "tools",
        "plugindatainjectionmenu",
        "client",
    );

    // IMPORTANT branch ORDER:
    //
    // Explicit POST actions (cancel / finish / upload) MUST be checked
    // BEFORE the session-bound "go (showInjectionForm)" branch. Otherwise
    // the user who tries to abort a stuck import — `_SESSION['datainjection']['go']`
    // is true while they click the Abort button — gets bounced straight
    // back into `showInjectionForm()` because the session flag wins, and
    // the cancel POST is never seen.
    if (isset($_POST['finish']) || isset($_POST['cancel'])) {
        $di_log_branch = isset($_POST['finish']) ? 'finish' : 'cancel';
        PluginDatainjectionSession::removeParams();
        Html::redirect(Toolbox::getItemTypeFormURL('PluginDatainjectionClientInjection'));
    } elseif (isset($_POST['upload'])) {
        $di_log_branch = 'upload';
        $model = new PluginDatainjectionModel();
        $model->can($_POST['id'], READ);
        $_SESSION['datainjection']['infos'] = ($_POST['info'] ?? []);

        //If additional informations provided : check if mandatory infos are present
        if (!$model->checkMandatoryFields($_SESSION['datainjection']['infos'])) {
            PluginDatainjectionLogger::warning('clientinjection.form.php: mandatory info missing');
            Session::addMessageAfterRedirect(
                __s('One mandatory field is not filled', 'datainjection'),
                true,
                ERROR,
                true,
            );
        } elseif (
            isset($_FILES['filename']['name'])
            && $_FILES['filename']['name']
             && $_FILES['filename']['tmp_name']
                && !$_FILES['filename']['error']
                   && $_FILES['filename']['size']
        ) {
            //Read file using automatic encoding detection, and do not delete file once readed
            // file_encoding here should be an encoding constant (auto/utf-8/iso),
            // not the model's filetype. Use AUTO if no explicit value supplied.
            $file_encoding = $_POST['file_encoding'] ?? PluginDatainjectionBackend::ENCODING_AUTO;
            $options = [
                'file_encoding' => $file_encoding,
                'mode'          => PluginDatainjectionModel::PROCESS,
                'delete_file'   => false,
            ];
            $response = $model->processUploadedFile($options);
            PluginDatainjectionLogger::info('clientinjection.form.php: processUploadedFile returned', [
                'response' => (bool) $response,
            ]);
            $model->cleanData();

            if ($response) {
                //File uploaded successfully and matches the given model : switch to the import tab
                $_SESSION['datainjection']['file_name']    = $_FILES['filename']['name'];
                $_SESSION['datainjection']['step']         = PluginDatainjectionClientInjection::STEP_PROCESS;
                //Store model in session for injection
                $_SESSION['datainjection']['currentmodel'] = serialize($model);
                $_SESSION['datainjection']['go']           = true;
            } else {
                //Go back to the file upload page
                $_SESSION['datainjection']['step'] = PluginDatainjectionClientInjection::STEP_UPLOAD;
            }
        } else {
            // Diagnostic only — do NOT touch $_FILES['filename'] beyond
            // an isset check. readUploadedFile() unsets that key after a
            // successful move (Symfony FileBag workaround), so by the time
            // we land in this branch on a *later* request, accessing it
            // would emit "Undefined array key" warnings.
            PluginDatainjectionLogger::warning('clientinjection.form.php: no usable file in $_FILES', [
                'files_keys'    => array_keys($_FILES),
                'filename_keys' => isset($_FILES['filename']) && is_array($_FILES['filename'])
                    ? array_keys($_FILES['filename'])
                    : null,
            ]);
            Session::addMessageAfterRedirect(
                __s('The file could not be found (Maybe it exceeds the maximum size allowed)', 'datainjection'),
                true,
                ERROR,
                true,
            );
        }

        Html::back();
    } elseif (isset($_SESSION['datainjection']['go'])) {
        // Session-bound: only reached when no explicit POST action above
        // matched. That ordering lets the Abort button (cancel=1) reset a
        // stuck import even when 'go' is still set.
        $di_log_branch = 'go (showInjectionForm)';
        $model = unserialize($_SESSION['datainjection']['currentmodel']);
        PluginDatainjectionClientInjection::showInjectionForm($model, $_SESSION['glpiactive_entity']);
    } else {
        $di_log_branch = 'showForm';
        if (isset($_GET['id'])) { // Allow link to a model
            PluginDatainjectionSession::setParam('models_id', $_GET['id']);
        }
        $clientInjection = new PluginDatainjectionClientInjection();
        $clientInjection->title();
        $clientInjection->showForm(0);
    }

    Html::footer();
    PluginDatainjectionLogger::info('clientinjection.form.php: ok', ['branch' => $di_log_branch]);
} catch (\Throwable $e) {
    if (
        $e instanceof \Glpi\Exception\RedirectException
        || (is_object($e) && str_ends_with(get_class($e), 'RedirectException'))
    ) {
        throw $e;
    }
    PluginDatainjectionLogger::exception($e, 'clientinjection.form.php failed (branch=' . $di_log_branch . ')');
    throw $e;
}
