(function() {
    var savedSnapshot = '';
    var operationBusy = false;
    var activeRunCleanup = null;

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function statusBox() {
        return qs('#nbs-status');
    }

    function showStatus(message, level) {
        var box = statusBox();
        if (!box) {
            return;
        }

        box.hidden = false;
        box.className = 'nbs-status is-' + (level || 'ok');
        box.textContent = message;
        box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function nextRowId(prefix) {
        var rand = Math.random().toString(16).slice(2, 10);
        return (prefix || 'row') + '_' + rand;
    }

    function createRunId() {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            throw new Error('This browser cannot create a secure sync run identifier.');
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, function(value) { return value.toString(16).padStart(2, '0'); }).join('');
    }

    function startRunProgress(form, runId) {
        var panel = qs('#nbs-run-progress');
        var bar = qs('#nbs-run-progress-bar');
        var percent = qs('#nbs-run-progress-percent');
        var phase = qs('#nbs-run-progress-phase');
        var counts = qs('#nbs-run-progress-counts');
        var note = qs('#nbs-run-progress-note');
        var timer = null;
        var requestTimeout = null;
        var controller = null;
        var stopped = false;
        var terminal = false;
        var lastTimestamp = -Infinity;
        var phases = {
            preparing: 'Preparing sync', fetching: 'Fetching hosts',
            prefetching: 'Loading Zabbix item data', syncing: 'Syncing hosts',
            finalizing: 'Finalizing run', completed: 'Run completed',
            failed: 'Run finished with errors', interrupted: 'Run interrupted'
        };
        panel.hidden = false;
        panel.className = 'nbs-run-progress';
        phase.textContent = phases.preparing;
        percent.textContent = '—';
        bar.removeAttribute('value');
        counts.textContent = 'Waiting for host discovery.';
        note.textContent = 'Progress reflects fetched hosts attempted, including hosts with errors.';

        function render(progress) {
            if (!progress || progress.run_id !== runId || !Object.prototype.hasOwnProperty.call(phases, progress.phase)) {
                return false;
            }
            var timestamp = typeof progress.updated_at === 'number'
                ? progress.updated_at * (progress.updated_at < 1000000000000 ? 1000 : 1)
                : Date.parse(progress.updated_at);
            if (!Number.isFinite(timestamp) || timestamp < lastTimestamp) {
                return false;
            }
            var completed = progress.hosts_completed;
            var total = progress.hosts_total;
            if (!Number.isInteger(completed) || completed < 0 || !Number.isInteger(total) || total < 0) {
                return false;
            }
            lastTimestamp = timestamp;
            var knownPercent = typeof progress.percent === 'number' && Number.isFinite(progress.percent)
                && progress.percent >= 0 && progress.percent <= 100 && progress.total_known !== false;
            var totalKnown = progress.total_known === true
                || (progress.total_known !== false && (total > 0 || knownPercent));
            var errorCount = Number.isInteger(progress.errors) && progress.errors >= 0 ? progress.errors : 0;
            var failed = progress.phase === 'failed' || progress.phase === 'interrupted';
            terminal = progress.running === false && ['completed', 'failed', 'interrupted'].indexOf(progress.phase) !== -1;
            phase.textContent = phases[progress.phase];
            panel.className = 'nbs-run-progress' + (failed ? ' is-error' : (errorCount > 0 ? ' is-warn' : ''));
            if (knownPercent) {
                bar.value = progress.percent;
                percent.textContent = (Math.round(progress.percent * 10) / 10) + '%';
            }
            else {
                bar.removeAttribute('value');
                percent.textContent = '—';
            }
            counts.textContent = totalKnown
                ? 'Fetched hosts attempted: ' + completed + ' / ' + total + ' · Errors: ' + errorCount
                : 'Waiting for host discovery. Errors: ' + errorCount;
            note.textContent = failed ? 'Review Last run and the log for errors.'
                : 'Progress reflects fetched hosts attempted, including hosts with errors.';
            return true;
        }

        function unavailable() {
            panel.classList.add('is-warn');
            note.textContent = 'Progress unavailable. The sync request is still pending; do not start another run.';
        }

        async function poll() {
            if (stopped) {
                return;
            }
            controller = new AbortController();
            requestTimeout = window.setTimeout(function() { controller.abort(); }, 8000);
            try {
                var url = new URL(form.getAttribute('data-run-status-url'), window.location.href);
                url.searchParams.set('run_id', runId);
                var response = await fetch(url.toString(), {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal
                });
                var payload = await response.json();
                if (stopped) {
                    return;
                }
                if (!response.ok || !payload || payload.ok !== true) {
                    throw new Error('Progress request failed.');
                }
                if (payload.progress === null) {
                    note.textContent = lastTimestamp === -Infinity
                        ? 'Waiting for this run to report progress.'
                        : 'Progress unavailable for this run. The sync request is still pending.';
                }
                else if (!render(payload.progress)) {
                    // Never reuse another run's snapshot or move backwards on an old response.
                    if (lastTimestamp === -Infinity) {
                        note.textContent = 'Waiting for this run to report progress.';
                    }
                }
            }
            catch (e) {
                if (!stopped) {
                    unavailable();
                }
            }
            finally {
                window.clearTimeout(requestTimeout);
                requestTimeout = null;
                controller = null;
                if (!stopped && !terminal) {
                    timer = window.setTimeout(poll, 1000);
                }
            }
        }

        function stop() {
            stopped = true;
            window.clearTimeout(timer);
            window.clearTimeout(requestTimeout);
            if (controller) {
                controller.abort();
            }
        }

        timer = window.setTimeout(poll, 1000);
        return {
            stop: stop,
            finish: function(progress, succeeded) {
                stop();
                var matched = render(progress);
                if (!matched) {
                    phase.textContent = succeeded ? 'Run response received' : 'Run request failed';
                    panel.classList.toggle('is-error', !succeeded);
                    note.textContent = 'No matching final progress snapshot was received for this run.';
                }
                return matched && terminal;
            },
            summaryUnavailable: function() {
                panel.classList.add('is-warn');
                note.textContent = 'This run\'s saved summary could not be confirmed. The Last run tab may still show an earlier run.';
            },
            failed: function() {
                stop();
                panel.classList.add('is-error');
                if (!terminal) {
                    phase.textContent = 'Run outcome not confirmed';
                }
                note.textContent = 'The run request failed or disconnected. Check Last run and the log before retrying; the server may still be working.';
            }
        };
    }

    function formSnapshot(form) {
        return JSON.stringify(Array.from(new FormData(form).entries()));
    }

    function hasUnsavedChanges(form) {
        return formSnapshot(form) !== savedSnapshot;
    }

    function beginOperation(form, lockFields) {
        operationBusy = true;
        var controls = qsa(lockFields ? 'input, select, textarea, button' : 'button', form)
            .concat(qsa('[form="nbs-settings-form"], #nbs-run-now'));
        var enabled = controls.filter(function(control) { return !control.disabled; });
        enabled.forEach(function(control) { control.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        return function() {
            enabled.forEach(function(control) { control.disabled = false; });
            form.removeAttribute('aria-busy');
            operationBusy = false;
        };
    }

    function initTabs(form) {
        var tablist = qs('.nbs-settings-tabs', form);
        var tabs = qsa('.nbs-settings-tab', tablist);
        var panels = qsa('.nbs-tab-panel', form);
        var keys = tabs.map(function(tab) { return tab.getAttribute('data-tab'); });
        var storageKey = 'netboxSyncSettingsActiveTab';

        function activate(key, focus) {
            if (keys.indexOf(key) === -1) {
                key = keys[0];
            }
            form.setAttribute('data-active-tab', key);
            tabs.forEach(function(tab) {
                var active = tab.getAttribute('data-tab') === key;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
                if (active && focus) {
                    tab.focus();
                }
            });
            panels.forEach(function(panel) { panel.hidden = panel.getAttribute('data-tab') !== key; });
            try { window.sessionStorage.setItem(storageKey, key); } catch (e) {}
            try { window.history.replaceState(null, '', '#' + key); } catch (e) {}
        }

        tablist.addEventListener('click', function(event) {
            var tab = event.target.closest('.nbs-settings-tab');
            if (tab) {
                activate(tab.getAttribute('data-tab'), false);
            }
        });
        tablist.addEventListener('keydown', function(event) {
            var current = tabs.indexOf(document.activeElement);
            if (current === -1 || ['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(event.key) === -1) {
                return;
            }
            event.preventDefault();
            var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            activate(keys[next], true);
        });
        form.addEventListener('invalid', function() {
            var invalid = form.querySelector(':invalid');
            var panel = invalid ? invalid.closest('.nbs-tab-panel') : null;
            if (panel) {
                activate(panel.getAttribute('data-tab'), false);
                var details = invalid.closest('details');
                if (details) {
                    details.open = true;
                }
            }
        }, true);
        var initial = window.location.hash.slice(1);
        if (keys.indexOf(initial) === -1) {
            try { initial = window.sessionStorage.getItem(storageKey); } catch (e) {}
        }
        activate(initial, false);
        tablist.hidden = false;
    }

    function addMappingRow(example) {
        var template = qs('#nbs-template-mapping');
        var list = qs('#nbs-mappings-list');

        if (!template || !list) {
            return;
        }

        var id = nextRowId('map');
        var html = template.innerHTML.replace(/__ROW_ID__/g, id);
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;

        var row = wrapper.firstElementChild;
        if (!row) {
            return;
        }
        var presets = {
            serial: {
                name: 'Device serial from Zabbix', target_object: 'device', mode: 'field_patch',
                source_mode: 'item_key', source_value: 'fgate.device.serialnumber',
                target_field: 'custom_fields.serial_from_zabbix', transform: 'trim',
                host_name_regex: '/^FW-/', on_empty: 'skip',
                notes: 'Verify the item key and host filter. Create the NetBox text custom field serial_from_zabbix for Device objects before enabling.'
            },
            role: {
                name: 'Assign device role', target_object: 'device', mode: 'relation_lookup',
                source_mode: 'static', source_value: 'Network device', target_field: 'role',
                lookup_endpoint: '/dcim/device-roles/', lookup_query_field: 'name',
                ensure_missing_mode: 'none', host_name_regex: '/^FW-/', on_empty: 'skip',
                notes: 'Replace Network device with an existing NetBox device-role name and adjust the host filter before enabling.'
            }
        };
        if (presets[example]) {
            Object.keys(presets[example]).forEach(function(field) {
                var input = row.querySelector('[name$="[' + field + ']"]');
                if (input) {
                    input.value = presets[example][field];
                }
            });
            row.querySelector('input[name$="[enabled]"]').checked = false;
            row.querySelector('details').open = true;
        }
        list.appendChild(row);
        row.querySelector('input[name$="[name]"]').focus();
        row.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function removeMappingRow(button) {
        var row = button.closest('.nbs-repeat-row');
        if (row) {
            row.remove();
        }
    }

    function initFaqs() {
        qsa('.nbs-faq-toggle').forEach(function(button) {
            button.addEventListener('click', function() {
                var targetId = button.getAttribute('data-faq-target');
                var target = targetId ? qs('#' + targetId) : null;
                if (target) {
                    target.classList.toggle('is-open');
                }
            });
        });
    }

    async function saveSettings(form) {
        if (operationBusy) {
            return;
        }
        var saveUrl = form.getAttribute('data-save-url');
        var formData = new FormData(form);
        var submittedSnapshot = formSnapshot(form);
        var release = beginOperation(form, false);

        try {
        showStatus('Saving settings…', 'warn');

        var response = await fetch(saveUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        var payload;
        try {
            payload = await response.json();
        }
        catch (e) {
            throw new Error('The save action did not return valid JSON.');
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Saving failed.');
        }

        savedSnapshot = submittedSnapshot;
        showStatus(payload.message || 'Settings saved.', 'ok');
        }
        finally {
            release();
        }
    }

    async function runNow(form) {
        if (operationBusy) {
            return false;
        }
        if (hasUnsavedChanges(form)) {
            showStatus('Save settings before running. Run now uses the saved settings; your unsaved changes are still here.', 'warn');
            return false;
        }
        var runUrl = form.getAttribute('data-run-url');
        var tokenName = qs('#nbs-run-csrf-token-name');
        var tokenValue = qs('#nbs-run-csrf-token-value');
        var formData = new FormData();
        var runId = createRunId();

        formData.append('force', '1');
        formData.append('run_id', runId);

        if (tokenName && tokenValue) {
            formData.append(tokenName.value, tokenValue.value);
        }

        var release = beginOperation(form, true);
        var progress = startRunProgress(form, runId);
        var runController = new AbortController();
        activeRunCleanup = function() {
            progress.stop();
            runController.abort();
        };
        try {
        showStatus('Starting sync run…', 'warn');

        var response = await fetch(runUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            signal: runController.signal
        });

        var payload;
        try {
            payload = await response.json();
        }
        catch (e) {
            throw new Error('The run action did not return valid JSON.');
        }

        if (!payload.summary || typeof payload.summary !== 'object' || Array.isArray(payload.summary)) {
            throw new Error(payload.error || 'Run failed.');
        }

        var summary = payload.summary;
        var succeeded = response.ok && payload.ok === true && summary.ok === true && !(summary.errors > 0);
        var matchingTerminal = progress.finish(payload.progress || summary.progress, succeeded);
        var canReload = matchingTerminal && payload.summary_saved === true;
        var message = (succeeded ? 'Run completed.' : 'Run finished with errors.') + '\n'
            + 'Processed hosts: ' + (summary.hosts_processed || 0) + '/' + (summary.hosts_total || 0) + '\n'
            + 'Mappings run: ' + (summary.mappings_run || 0) + '\n'
            + 'Created: ' + (summary.created || 0) + ', Updated: ' + (summary.updated || 0) + ', Deleted: ' + (summary.deleted || 0) + '\n'
            + 'Errors: ' + (summary.errors || 0);

        if (!canReload) {
            progress.summaryUnavailable();
            message += '\nThis run\'s saved summary could not be confirmed. The Last run tab may still show an earlier run.';
        }

        showStatus(message, succeeded ? (canReload ? 'ok' : 'warn') : 'error');
        return canReload;
        }
        catch (e) {
            progress.failed();
            throw e;
        }
        finally {
            progress.stop();
            activeRunCleanup = null;
            release();
        }
    }

    async function testConnection(form) {
        var button = qs('#nbs-test-connection');
        if (!button) {
            return;
        }

        var testUrl = button.getAttribute('data-test-url');
        var fd = new FormData();

        function fieldValue(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        }

        function fieldChecked(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el && el.checked ? '1' : '0';
        }

        fd.append('url', fieldValue('netbox[url]'));
        fd.append('token', fieldValue('netbox[token]'));
        fd.append('token_env', fieldValue('netbox[token_env]'));
        fd.append('clear_token', fieldChecked('netbox[clear_token]'));
        fd.append('verify_peer', fieldChecked('netbox[verify_peer]'));
        fd.append('timeout', fieldValue('netbox[timeout]') || '15');

        var tokenName = qs('#nbs-test-csrf-token-name');
        var tokenValue = qs('#nbs-test-csrf-token-value');
        if (tokenName && tokenValue) {
            fd.append(tokenName.value, tokenValue.value);
        }

        showStatus('Testing NetBox connection…', 'warn');

        var response = await fetch(testUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });

        var payload;
        try {
            payload = await response.json();
        }
        catch (e) {
            throw new Error('The test action did not return valid JSON.');
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Connection test failed.');
        }

        showStatus(payload.message || 'Connection OK.', 'ok');
    }

    async function testZabbixConnection(form) {
        var button = qs('#nbs-test-zabbix-connection');
        if (!button) {
            return;
        }

        var testUrl = button.getAttribute('data-test-url');
        var fd = new FormData();

        function fieldValue(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el ? el.value : '';
        }

        function fieldChecked(name) {
            var el = form.querySelector('[name="' + name + '"]');
            return el && el.checked ? '1' : '0';
        }

        fd.append('url', fieldValue('zabbix_api[url]'));
        fd.append('token', fieldValue('zabbix_api[token]'));
        fd.append('token_env', fieldValue('zabbix_api[token_env]'));
        fd.append('clear_token', fieldChecked('zabbix_api[clear_token]'));
        fd.append('verify_peer', fieldChecked('zabbix_api[verify_peer]'));
        fd.append('timeout', fieldValue('zabbix_api[timeout]') || '15');

        var tokenName = qs('#nbs-zabbix-test-csrf-token-name');
        var tokenValue = qs('#nbs-zabbix-test-csrf-token-value');
        if (tokenName && tokenValue) {
            fd.append(tokenName.value, tokenValue.value);
        }

        showStatus('Testing unattended Zabbix API access…', 'warn');

        var response = await fetch(testUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });

        var payload;
        try {
            payload = await response.json();
        }
        catch (e) {
            throw new Error('The Zabbix API test action did not return valid JSON.');
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Zabbix API connection test failed.');
        }

        showStatus(payload.message || 'Zabbix API connection OK.', 'ok');
    }

    function initForm() {
        var form = qs('#nbs-settings-form');
        if (!form) {
            return;
        }
        initTabs(form);
        savedSnapshot = formSnapshot(form);
        window.addEventListener('beforeunload', function(event) {
            if (operationBusy || hasUnsavedChanges(form)) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        window.addEventListener('pagehide', function() {
            if (activeRunCleanup) {
                activeRunCleanup();
            }
        });

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            saveSettings(form).catch(function(error) {
                showStatus(error.message || String(error), 'error');
            });
        });

        var runButton = qs('#nbs-run-now');
        if (runButton) {
            runButton.addEventListener('click', function() {
                runNow(form).then(function(reload) {
                    if (reload && !hasUnsavedChanges(form)) {
                        window.location.hash = 'last-run';
                        window.location.reload();
                    }
                }).catch(function(error) {
                    showStatus(error.message || String(error), 'error');
                });
            });
        }

        var testButton = qs('#nbs-test-connection');
        if (testButton) {
            testButton.addEventListener('click', function() {
                testConnection(form).catch(function(error) {
                    showStatus(error.message || String(error), 'error');
                });
            });
        }

        var zabbixTestButton = qs('#nbs-test-zabbix-connection');
        if (zabbixTestButton) {
            zabbixTestButton.addEventListener('click', function() {
                testZabbixConnection(form).catch(function(error) {
                    showStatus(error.message || String(error), 'error');
                });
            });
        }

        qsa('[data-add-row="mapping"]').forEach(function(button) {
            button.addEventListener('click', function() { addMappingRow(); });
        });
        qsa('[data-mapping-example]').forEach(function(button) {
            button.addEventListener('click', function() {
                addMappingRow(button.getAttribute('data-mapping-example'));
            });
        });

        document.addEventListener('click', function(event) {
            var button = event.target.closest('.nbs-remove-row');
            if (button) {
                removeMappingRow(button);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        initFaqs();
        initForm();
    });
})();
