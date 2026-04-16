(function () {
    'use strict';

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
