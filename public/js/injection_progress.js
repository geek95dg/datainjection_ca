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

/**
 * Start the batch injection process driven by the injection container element.
 *
 * @param {HTMLElement} container  The element carrying data-* config attributes.
 */
function startBatchInjection(container) {
    const batchUrl     = container.dataset.batchUrl;
    const resultUrl    = container.dataset.resultUrl;
    const modelId      = parseInt(container.dataset.modelId, 10);
    const nblines      = parseInt(container.dataset.nblines, 10);
    const batchSize    = parseInt(container.dataset.batchSize || '10', 10);
    const statusLabel  = container.dataset.statusLabel || '';
    const linesLabel   = container.dataset.linesLabel || '';
    const errorLabel   = container.dataset.errorLabel || '';

    let offset = 0;

    function updateProgressBar(progress) {
        const progressBar = container.querySelector('.progress-bar');
        const progressContainer = container.querySelector('.progress');
        if (progressBar && progressContainer) {
            progressBar.style.width = progress + '%';
            progressBar.textContent = progress + '%';
            progressContainer.setAttribute('aria-valuenow', progress);
        }
    }

    function updateStatus(processed, total) {
        const status = container.querySelector('#injection_status');
        if (status) {
            status.textContent = statusLabel + ' — ' + processed + ' / ' + total + ' ' + linesLabel;
        }
    }

    /** Show the in-page error banner with a server-supplied message and
     *  stop the progress spinner. Keeps the "Abort and start over" form
     *  reachable so the user can recover without reinstalling.
     *
     *  `details` (optional) is appended on a second line — used to surface
     *  the exception class + file:line returned by inject_batch.php so the
     *  user can search datainjection.log even when GLPI rewrote the
     *  primary message to a generic localised string. */
    function showError(message, details) {
        const progressBar = container.querySelector('.progress-bar');
        if (progressBar) {
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.remove('bg-info');
            progressBar.classList.add('bg-danger');
            progressBar.textContent = errorLabel;
        }
        const errBox = container.querySelector('#injection_error');
        const errMsg = container.querySelector('#injection_error_message');
        if (errBox) {
            errBox.style.display = '';
        }
        if (errMsg) {
            errMsg.textContent = message || errorLabel;
            if (details) {
                const sub = document.createElement('div');
                sub.className = 'small text-muted mt-1';
                sub.textContent = details;
                errMsg.appendChild(sub);
            }
        }
    }

    /** Build a "ClassName @ /path/to/file.php:NN" subline when the server
     *  payload carries those fields. Returns undefined when nothing useful
     *  is available so the banner stays single-line. */
    function buildDetails(payload) {
        if (!payload || typeof payload !== 'object') {
            return undefined;
        }
        const where = payload.where;
        const klass = payload.class;
        if (where && klass) {
            return klass + ' @ ' + where;
        }
        return where || klass || undefined;
    }

    function processBatch() {
        $.ajax({
            url: batchUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                offset: offset,
                batch_size: batchSize
            },
            success: function(response) {
                // inject_batch.php may also return a structured error
                // payload with HTTP 200 in degenerate cases; treat it
                // like an error.
                if (response && response.error) {
                    showError(response.message || errorLabel, buildDetails(response));
                    return;
                }
                updateProgressBar(response.progress);
                updateStatus(response.processed, response.total);
                offset = response.offset;

                if (response.done) {
                    const progressBar = container.querySelector('.progress-bar');
                    if (progressBar) {
                        progressBar.classList.remove('progress-bar-animated');
                    }
                    $('#span_injection').load(resultUrl, {
                        models_id: modelId,
                        nblines: nblines
                    });
                } else {
                    processBatch();
                }
            },
            error: function(xhr) {
                // Prefer the structured error body the wrapped
                // inject_batch.php now returns even on 500. Fall back
                // to a generic message if the response was empty or
                // un-parseable.
                let message = errorLabel;
                let payload = null;
                try {
                    if (xhr && xhr.responseJSON) {
                        payload = xhr.responseJSON;
                    } else if (xhr && xhr.responseText) {
                        payload = JSON.parse(xhr.responseText);
                    }
                    if (payload && payload.message) {
                        message = payload.message;
                    }
                } catch (e) {
                    /* keep default label */
                }
                // When the body had nothing parseable, at least surface the
                // HTTP status so the user can distinguish "endpoint 404"
                // from "endpoint 500 with no logged exception".
                let details = buildDetails(payload);
                if (!details && xhr && xhr.status) {
                    details = 'HTTP ' + xhr.status
                        + (xhr.statusText ? ' ' + xhr.statusText : '');
                }
                showError(message, details);
            }
        });
    }

    processBatch();
}
