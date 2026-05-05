(function () {
    'use strict';

    // Models whose APIs only accept the default temperature (typically 1).
    // When a user picks one of these, we force the per-provider temperature
    // field to 1 and explain why. The user can still override.
    var FORCED_DEFAULT_TEMPERATURE_PATTERNS = [
        /^gpt-5(?:[-.].*)?$/i,
        /^o1(?:[-.].*)?$/i,
        /^o3(?:[-.].*)?$/i,
        /^o4(?:[-.].*)?$/i
    ];
    var FORCED_DEFAULT_TEMPERATURE_VALUE = '1';

    function modelRequiresDefaultTemperature(modelName) {
        var trimmed = (modelName || '').trim();
        if (!trimmed) {
            return false;
        }
        for (var i = 0; i < FORCED_DEFAULT_TEMPERATURE_PATTERNS.length; i++) {
            if (FORCED_DEFAULT_TEMPERATURE_PATTERNS[i].test(trimmed)) {
                return true;
            }
        }
        return false;
    }

    function init() {
        var root = document.getElementById('ai-settings-root');

        if (!root) {
            return;
        }

        var templates = {
            provider: document.getElementById('ai-provider-template'),
            instruction: document.getElementById('ai-instruction-template'),
            reference_link: document.getElementById('ai-reference-link-template')
        };

        var lists = {
            provider: document.getElementById('ai-providers-list'),
            instruction: document.getElementById('ai-instructions-list'),
            reference_link: document.getElementById('ai-reference-links-list')
        };

        root.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-row]');
            var removeButton = event.target.closest('.ai-remove-row');
            var testProviderButton = event.target.closest('[data-test-provider]');
            var testNetBoxButton = event.target.closest('[data-test-netbox]');

            if (addButton) {
                event.preventDefault();
                addRow(addButton.getAttribute('data-add-row'));
                return;
            }

            if (removeButton) {
                event.preventDefault();
                var row = removeButton.closest('.ai-repeat-row');

                if (row) {
                    row.remove();
                }
                return;
            }

            if (testProviderButton) {
                event.preventDefault();
                handleTestProvider(testProviderButton, root);
                return;
            }

            if (testNetBoxButton) {
                event.preventDefault();
                handleTestNetBox(testNetBoxButton, root);
            }
        });

        // React to model changes (typing or picking from the datalist) so the
        // per-provider temperature is auto-set when the chosen model only
        // supports the default temperature.
        root.addEventListener('input', function (event) {
            var modelInput = event.target.closest('.ai-provider-model-input');
            if (modelInput) {
                applyTemperatureDefaultForModel(modelInput);
            }
        });
        root.addEventListener('change', function (event) {
            var modelInput = event.target.closest('.ai-provider-model-input');
            if (modelInput) {
                applyTemperatureDefaultForModel(modelInput);
            }
        });

        if (lists.provider && !lists.provider.querySelector('.ai-provider-row')) {
            addRow('provider');
        }

        var apiKeyEnvPlaceholders = {
            openai_compatible: 'OPENAI_API_KEY',
            anthropic: 'ANTHROPIC_API_KEY',
            ollama: ''
        };

        function updateApiKeyEnvPlaceholder(typeSelect) {
            var row = typeSelect.closest('.ai-provider-row');
            if (!row) {
                return;
            }
            var envInput = row.querySelector('.ai-provider-api-key-env');
            if (!envInput) {
                return;
            }
            var placeholder = apiKeyEnvPlaceholders[typeSelect.value];
            envInput.placeholder = (placeholder === undefined) ? 'OPENAI_API_KEY' : placeholder;
        }

        root.addEventListener('change', function (event) {
            var typeSelect = event.target.closest('.ai-provider-type-select');
            if (typeSelect) {
                updateApiKeyEnvPlaceholder(typeSelect);
            }
        });

        // FAQ toggle buttons
        root.addEventListener('click', function (event) {
            var faqBtn = event.target.closest('.ai-faq-toggle');
            if (faqBtn) {
                event.preventDefault();
                var targetId = faqBtn.getAttribute('data-faq-target');
                var box = targetId ? document.getElementById(targetId) : null;
                if (box) {
                    var isVisible = box.classList.contains('ai-faq-visible');
                    box.classList.toggle('ai-faq-visible', !isVisible);
                    faqBtn.classList.toggle('ai-faq-active', !isVisible);
                }
                return;
            }
        });

        // Toggle write permissions visibility based on mode selection.
        var actionsMode = document.getElementById('ai-actions-mode');
        var writePermsBlock = document.getElementById('ai-write-permissions');

        if (actionsMode && writePermsBlock) {
            actionsMode.addEventListener('change', function () {
                writePermsBlock.style.display = actionsMode.value === 'readwrite' ? '' : 'none';
            });
        }

        // AJAX form submission
        var form = document.getElementById('ai-settings-form');

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Saving\u2026';
                }

                fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(form)
                })
                    .then(function (response) {
                        return response.text().then(function (text) {
                            var parsed = parseJsonSafe(text);

                            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                                var inner = parseJsonSafe(parsed.main_block);

                                if (inner) {
                                    parsed = inner;
                                }
                            }

                            return parsed || {ok: false, error: 'Unexpected response from server.'};
                        });
                    })
                    .then(function (data) {
                        if (data.ok) {
                            window.location.reload();
                        }
                        else {
                            showStatus(data.error || 'Save failed.', true);
                        }
                    })
                    .catch(function (error) {
                        showStatus('Save failed: ' + error.message, true);
                    })
                    .finally(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Save settings';
                        }
                    });
            });
        }

        // Initial pass: any existing provider rows whose saved model name forces
        // a specific temperature should display the warning hint on page load.
        var existingModelInputs = root.querySelectorAll('.ai-provider-model-input');
        for (var idx = 0; idx < existingModelInputs.length; idx++) {
            applyTemperatureDefaultForModel(existingModelInputs[idx]);
        }
    }

    function parseJsonSafe(text) {
        try {
            return JSON.parse(text);
        }
        catch (e) {
            return null;
        }
    }

    function showStatus(message, isError) {
        var existing = document.getElementById('ai-settings-status');

        if (existing) {
            existing.remove();
        }

        var el = document.createElement('div');
        el.id = 'ai-settings-status';
        el.className = 'ai-status ' + (isError ? 'ai-status-error' : 'ai-status-ok');
        el.textContent = message;

        var form = document.getElementById('ai-settings-form');

        if (form) {
            form.parentNode.insertBefore(el, form);
        }
    }

    function getProviderRow(element) {
        return element ? element.closest('.ai-provider-row') : null;
    }

    function buildProviderTestPayload(row) {
        var data = new FormData();
        var inputs = row.querySelectorAll('input[name], select[name], textarea[name]');

        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var name = input.getAttribute('name') || '';
            var match = name.match(/\[([^\]]+)\]$/);

            if (!match) {
                continue;
            }

            var fieldName = match[1];

            if (input.type === 'checkbox') {
                if (input.checked) {
                    data.append(fieldName, input.value || '1');
                }
                continue;
            }

            if (input.type === 'radio' && !input.checked) {
                continue;
            }

            data.append(fieldName, input.value);
        }

        return data;
    }

    function applyTemperatureDefaultForModel(modelInput) {
        var row = getProviderRow(modelInput);
        if (!row) {
            return;
        }

        var tempInput = row.querySelector('.ai-provider-temperature-input');
        var tempHint = row.querySelector('.ai-provider-temperature-hint');

        if (!tempInput) {
            return;
        }

        var modelName = modelInput.value || '';

        if (modelRequiresDefaultTemperature(modelName)) {
            // If the user has set an explicit value other than 1, the API will
            // reject it — bump it to 1. Blank stays blank (uses the global,
            // which is also 1).
            var current = (tempInput.value || '').trim();
            if (current !== '') {
                var currentNum = parseFloat(current);
                if (isNaN(currentNum) || currentNum !== 1) {
                    tempInput.value = FORCED_DEFAULT_TEMPERATURE_VALUE;
                }
            }

            if (tempHint) {
                tempHint.textContent = 'This model only accepts the default temperature (1).';
                tempHint.classList.add('ai-status-warn');
            }
        }
        else if (tempHint) {
            tempHint.textContent = 'Leave blank to use global chat temperature.';
            tempHint.classList.remove('ai-status-warn');
        }
    }

    function describeError(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        if (typeof value === 'string') {
            return value;
        }
        if (Array.isArray(value)) {
            var pieces = [];
            for (var i = 0; i < value.length; i++) {
                var part = describeError(value[i]);
                if (part) {
                    pieces.push(part);
                }
            }
            return pieces.join('; ');
        }
        if (typeof value === 'object') {
            if (typeof value.message === 'string' && value.message) {
                return value.message;
            }
            if (typeof value.title === 'string' && value.title) {
                return value.title;
            }
            if (Array.isArray(value.messages) && value.messages.length) {
                return describeError(value.messages);
            }
            if (Array.isArray(value.errors) && value.errors.length) {
                return describeError(value.errors);
            }
            try {
                return JSON.stringify(value);
            }
            catch (e) {
                return String(value);
            }
        }
        return String(value);
    }

    function setProviderTestStatus(row, message, kind) {
        var status = row.querySelector('.ai-test-provider-status');

        if (!status) {
            return;
        }

        var text = (typeof message === 'string') ? message : describeError(message);
        status.textContent = text || '';
        status.classList.remove('ai-status-ok', 'ai-status-error', 'ai-status-warn');

        if (kind) {
            status.classList.add('ai-status-' + kind);
        }
    }

    function populateModelDatalist(row, models) {
        var datalist = row.querySelector('.ai-provider-model-datalist');
        var hint = row.querySelector('.ai-provider-model-hint');

        if (!datalist) {
            return;
        }

        datalist.textContent = '';

        for (var i = 0; i < models.length; i++) {
            var option = document.createElement('option');
            option.value = models[i];
            datalist.appendChild(option);
        }

        if (hint) {
            if (models.length > 0) {
                hint.textContent = models.length + ' model(s) detected. Click the field for autocomplete.';
            }
            else {
                hint.textContent = 'No models returned by the provider.';
            }
        }
    }

    function handleTestProvider(button, root) {
        var row = getProviderRow(button);

        if (!row) {
            return;
        }

        var endpoint = root.getAttribute('data-test-provider-url') || '';

        if (!endpoint) {
            setProviderTestStatus(row, 'Test endpoint is not configured.', 'error');
            return;
        }

        var originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Testing…';
        setProviderTestStatus(row, 'Connecting…', null);

        var payload = buildProviderTestPayload(row);

        // Zabbix enforces CSRF on POST endpoints; attach the per-action token
        // exposed by the view via data-* attributes.
        var csrfFieldName = root.getAttribute('data-csrf-field-name') || '';
        var csrfToken = root.getAttribute('data-test-provider-csrf') || '';
        if (csrfFieldName && csrfToken) {
            payload.append(csrfFieldName, csrfToken);
        }

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var parsed = parseJsonSafe(text);

                    if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                        var inner = parseJsonSafe(parsed.main_block);
                        if (inner) {
                            parsed = inner;
                        }
                    }

                    if (parsed === null || parsed === undefined) {
                        return {
                            ok: false,
                            error: 'Server returned non-JSON response (HTTP ' + response.status + '). '
                                + 'Check that the AI module is enabled in Administration > Modules.'
                        };
                    }

                    return parsed;
                });
            })
            .then(function (data) {
                if (data && data.ok) {
                    var models = Array.isArray(data.models) ? data.models : [];
                    populateModelDatalist(row, models);
                    setProviderTestStatus(row, data.message || 'Connection succeeded.', 'ok');

                    var modelInput = row.querySelector('.ai-provider-model-input');
                    if (modelInput) {
                        applyTemperatureDefaultForModel(modelInput);
                    }
                }
                else {
                    var errorText = describeError(data && (data.error || data.errors || data.messages || data));
                    setProviderTestStatus(row, errorText || 'Connection failed.', 'error');
                }
            })
            .catch(function (error) {
                var msg = (error && error.message) ? error.message : describeError(error);
                setProviderTestStatus(row, 'Test failed: ' + (msg || 'unknown error'), 'error');
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    }

    function buildNamedFieldsPayload(scope) {
        var data = new FormData();
        var inputs = scope.querySelectorAll('input[name], select[name], textarea[name]');

        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var name = input.getAttribute('name') || '';
            var match = name.match(/\[([^\]]+)\]$/);

            if (!match) {
                continue;
            }

            var fieldName = match[1];

            if (input.type === 'checkbox') {
                if (input.checked) {
                    data.append(fieldName, input.value || '1');
                }
                continue;
            }

            if (input.type === 'radio' && !input.checked) {
                continue;
            }

            data.append(fieldName, input.value);
        }

        return data;
    }

    function setNetBoxTestStatus(scope, message, kind) {
        var status = scope.querySelector('.ai-test-netbox-status');

        if (!status) {
            return;
        }

        var text = (typeof message === 'string') ? message : describeError(message);
        status.textContent = text || '';
        status.classList.remove('ai-status-ok', 'ai-status-error', 'ai-status-warn');

        if (kind) {
            status.classList.add('ai-status-' + kind);
        }
    }

    function handleTestNetBox(button, root) {
        var scope = document.getElementById('ai-netbox-section');

        if (!scope) {
            return;
        }

        var endpoint = root.getAttribute('data-test-netbox-url') || '';

        if (!endpoint) {
            setNetBoxTestStatus(scope, 'Test endpoint is not configured.', 'error');
            return;
        }

        var originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Testing…';
        setNetBoxTestStatus(scope, 'Connecting…', null);

        var payload = buildNamedFieldsPayload(scope);

        var csrfFieldName = root.getAttribute('data-csrf-field-name') || '';
        var csrfToken = root.getAttribute('data-test-netbox-csrf') || '';
        if (csrfFieldName && csrfToken) {
            payload.append(csrfFieldName, csrfToken);
        }

        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var parsed = parseJsonSafe(text);

                    if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                        var inner = parseJsonSafe(parsed.main_block);
                        if (inner) {
                            parsed = inner;
                        }
                    }

                    if (parsed === null || parsed === undefined) {
                        return {
                            ok: false,
                            error: 'Server returned non-JSON response (HTTP ' + response.status + '). '
                                + 'Check that the AI module is enabled in Administration > Modules.'
                        };
                    }

                    return parsed;
                });
            })
            .then(function (data) {
                if (data && data.ok) {
                    setNetBoxTestStatus(scope, data.message || 'Connection succeeded.', 'ok');
                }
                else {
                    var errorText = describeError(data && (data.error || data.errors || data.messages || data));
                    setNetBoxTestStatus(scope, errorText || 'Connection failed.', 'error');
                }
            })
            .catch(function (error) {
                var msg = (error && error.message) ? error.message : describeError(error);
                setNetBoxTestStatus(scope, 'Test failed: ' + (msg || 'unknown error'), 'error');
            })
            .finally(function () {
                button.disabled = false;
                button.textContent = originalText;
            });
    }

    function addRow(type) {
        var template = document.getElementById('ai-' + type.replace('_', '-') + '-template')
            || document.getElementById('ai-' + type + '-template');
        var list = document.getElementById('ai-' + type.replace('_', '-') + 's-list')
            || document.getElementById('ai-' + type + 's-list');

        if (!template || !list) {
            return;
        }

        var html = template.innerHTML.replace(/__ROW_ID__/g, generateId(type));
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();

        if (wrapper.firstElementChild) {
            list.appendChild(wrapper.firstElementChild);
        }
    }

    function generateId(prefix) {
        return prefix + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
}());
