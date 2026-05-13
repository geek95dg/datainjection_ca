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

Session::checkLoginUser();

if (!isset($_GET["id"])) {
    $_GET["id"] = "";
}

if (!isset($_GET["withtemplate"])) {
    $_GET["withtemplate"] = "";
}

// Whole controller is wrapped so any fatal — PHP throw, GLPI internal
// exception, missing column, …  — lands in /var/log/glpi/datainjection.log
// with a stack trace instead of disappearing into a generic 500 page.
$di_log_branch = 'idle';
try {
    PluginDatainjectionLogger::info('model.form.php: enter', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'id'     => $_GET['id'],
        'post_keys' => array_keys($_POST),
    ]);

    $model = new PluginDatainjectionModel();
    $model->checkGlobal(READ);

    if (isset($_POST["add"])) {
        $di_log_branch = 'add';
        $model->check(-1, UPDATE, $_POST);
        $newID = $model->add($_POST);
        PluginDatainjectionLogger::info('model.form.php: add ok', ['newID' => $newID]);

        //Set display to the advanced options tab
        Session::setActiveTab('PluginDatainjectionModel', 'PluginDatainjectionModel$3');
        Html::redirect(Toolbox::getItemTypeFormURL('PluginDatainjectionModel') . "?id=$newID");
    } elseif (isset($_POST["delete"])) {
        $di_log_branch = 'delete';
        $model->check($_POST['id'], DELETE);
        $model->delete($_POST);
        $model->redirectToList();
    } elseif (isset($_POST["update"])) {
        $di_log_branch = 'update';
        //Update model
        $model->check($_POST['id'], UPDATE);
        $model->update($_POST);

        // Save the specific-format companion record. The previous code
        // hard-coded 'csv' here, which silently corrupted XLSX models on
        // every save (the wrong companion table got the update). Pick the
        // companion based on the model's actual filetype.
        $filetype       = $model->fields['filetype'] ?? 'csv';
        $specific_model = PluginDatainjectionModel::getInstance($filetype)
            ?: PluginDatainjectionModel::getInstance('csv');
        if (method_exists($specific_model, 'saveFields')) {
            $specific_model->saveFields($_POST);
        }
        PluginDatainjectionLogger::info('model.form.php: update ok', ['filetype' => $filetype]);
        Html::back();
    } elseif (isset($_POST["purge"])) {
        $di_log_branch = 'purge';
        $model->check($_POST['id'], PURGE);
        $model->delete($_POST, true);
        $model->redirectToList();
    } elseif (isset($_POST["validate"])) {
        $di_log_branch = 'validate';
        $model->check($_POST['id'], UPDATE);
        $model->switchReadyToUse();
        Html::back();
    } elseif (isset($_POST['upload'])) {
        $di_log_branch = 'upload';
        if (!empty($_FILES)) {
            $model->check($_POST['id'], UPDATE);

            // The previous code passed 'csv' as `file_encoding`. That's
            // a *filetype*, not an encoding — the backend then read the
            // file with an undefined encoding constant. Use AUTO so the
            // backend detects UTF-8 / ISO-8859 properly.
            $file_encoding = $_POST['file_encoding'] ?? PluginDatainjectionBackend::ENCODING_AUTO;

            if (
                $model->processUploadedFile(
                    [
                        'file_encoding' => $file_encoding,
                        'mode'          => PluginDatainjectionModel::CREATION,
                    ],
                )
            ) {
                Session::setActiveTab('PluginDatainjectionModel', 'PluginDatainjectionModel$4');
            } else {
                Session::addMessageAfterRedirect(
                    __s('The file could not be found (Maybe it exceeds the maximum size allowed)', 'datainjection'),
                    true,
                    ERROR,
                    true,
                );
            }
        }
        Html::back();
    } elseif (isset($_GET['sample'])) {
        $di_log_branch = 'sample';
        $model->check($_GET['sample'], READ);
        $modeltype = PluginDatainjectionModel::getInstance($model->getField('filetype'));
        $modeltype->getFromDBByModelID($model->getField('id'));
        $modeltype->showSample($model);
        return;
    } else {
        $di_log_branch = 'display';
    }

    Html::header(
        PluginDatainjectionModel::getTypeName(),
        '',
        "tools",
        "plugindatainjectionmenu",
        "model",
    );

    $model->display(['id' => $_GET["id"]]);

    Html::footer();
    PluginDatainjectionLogger::info('model.form.php: ok', ['branch' => $di_log_branch]);
} catch (\Throwable $e) {
    PluginDatainjectionLogger::exception($e, 'model.form.php failed (branch=' . $di_log_branch . ')');
    // Re-throw so GLPI still renders its generic error page — the user
    // already saw "Wystąpił nieoczekiwany błąd"; this just makes sure the
    // root cause is on disk.
    throw $e;
}
