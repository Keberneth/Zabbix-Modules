(function() {
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

    function addMappingRow() {
        var template = qs('#nbs-template-mapping');
        var list = qs('#nbs-mappings-list');

        if (!template || !list) {
            return;
        }

        var id = nextRowId('map');
        var html = template.innerHTML.replace(/__ROW_ID__/g, id);
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html;

        while (wrapper.firstElementChild) {
            list.appendChild(wrapper.firstElementChild);
        }
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
        var saveUrl = form.getAttribute('data-save-url');
        var formData = new FormData(form);

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

        showStatus(payload.message || 'Settings saved.', 'ok');
    }

    async function runNow(form) {
        var runUrl = form.getAttribute('data-run-url');
        var tokenName = qs('#nbs-run-csrf-token-name');
        var tokenValue = qs('#nbs-run-csrf-token-value');
        var formData = new FormData();

        formData.append('force', '1');

        if (tokenName && tokenValue) {
            formData.append(tokenName.value, tokenValue.value);
        }

        showStatus('Starting sync run…', 'warn');

        var response = await fetch(runUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        var payload;
        try {
            payload = await response.json();
        }
        catch (e) {
            throw new Error('The run action did not return valid JSON.');
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Run failed.');
        }

        var summary = payload.summary || {};
        var message = 'Run completed.\n'
            + 'Processed hosts: ' + (summary.hosts_processed || 0) + '/' + (summary.hosts_total || 0) + '\n'
            + 'Mappings run: ' + (summary.mappings_run || 0) + '\n'
            + 'Created: ' + (summary.created || 0) + ', Updated: ' + (summary.updated || 0) + ', Deleted: ' + (summary.deleted || 0) + '\n'
            + 'Errors: ' + (summary.errors || 0);

        showStatus(message, (summary.errors || 0) > 0 ? 'warn' : 'ok');

        window.setTimeout(function() {
            window.location.reload();
        }, 1200);
    }

    function initForm() {
        var form = qs('#nbs-settings-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function(event) {
            event.preventDefault();
            saveSettings(form).catch(function(error) {
                showStatus(error.message || String(error), 'error');
            });
        });

        var runButton = qs('#nbs-run-now');
        if (runButton) {
            runButton.addEventListener('click', function() {
                runNow(form).catch(function(error) {
                    showStatus(error.message || String(error), 'error');
                });
            });
        }

        qsa('[data-add-row="mapping"]').forEach(function(button) {
            button.addEventListener('click', addMappingRow);
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
