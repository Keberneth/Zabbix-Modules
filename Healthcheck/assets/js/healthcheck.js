(function () {
    'use strict';

    function parseJsonSafe(text) {
        try {
            return JSON.parse(text);
        }
        catch (e) {
            return null;
        }
    }

    function unwrapResponse(text) {
        var parsed = parseJsonSafe(text);

        if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
            var inner = parseJsonSafe(parsed.main_block);
            if (inner) {
                return inner;
            }
        }

        return parsed;
    }

    function showPageStatus(root, message, isError) {
        if (!root) {
            return;
        }

        var existing = root.querySelector('.hc-page-status');
        if (existing) {
            existing.remove();
        }

        var el = document.createElement('div');
        el.className = 'hc-page-status hc-status ' + (isError ? 'hc-status-error' : 'hc-status-ok');
        el.textContent = message;

        root.insertBefore(el, root.firstChild.nextSibling);
    }

    function generateId(prefix) {
        return prefix + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    function initSettingsPage() {
        var root = document.getElementById('healthcheck-settings-root');

        if (!root) {
            return;
        }

        root.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-row]');
            var removeButton = event.target.closest('.hc-remove-row');

            if (addButton) {
                event.preventDefault();
                addRow(addButton.getAttribute('data-add-row'));
                return;
            }

            if (removeButton) {
                event.preventDefault();
                var row = removeButton.closest('.hc-repeat-row');
                if (row) {
                    row.remove();
                }
            }
        });

        var list = document.getElementById('healthcheck-checks-list');
        if (list && !list.querySelector('.hc-check-row')) {
            addRow('check');
        }

        var form = document.getElementById('healthcheck-settings-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Saving…';
            }

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form)
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        return unwrapResponse(text) || {ok: false, error: 'Unexpected response from server.'};
                    });
                })
                .then(function (data) {
                    if (data.ok) {
                        window.location.reload();
                    }
                    else {
                        showPageStatus(root, data.error || data.message || 'Save failed.', true);
                    }
                })
                .catch(function (error) {
                    showPageStatus(root, 'Save failed: ' + error.message, true);
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Save settings';
                    }
                });
        });

        function addRow(type) {
            var template = document.getElementById('healthcheck-' + type + '-template');
            var target = document.getElementById('healthcheck-' + type + 's-list');

            if (!template || !target) {
                return;
            }

            var html = template.innerHTML.replace(/__ROW_ID__/g, generateId(type));
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();

            if (wrapper.firstElementChild) {
                target.appendChild(wrapper.firstElementChild);
            }
        }
    }

    function initRunButtons() {
        var root = document.getElementById('healthcheck-heartbeat-root');

        if (!root) {
            return;
        }

        root.addEventListener('click', function (event) {
            var button = event.target.closest('.hc-run-button');
            if (!button) {
                return;
            }

            event.preventDefault();

            var runUrl = root.getAttribute('data-run-url');
            if (!runUrl) {
                showPageStatus(root, 'Run URL is missing.', true);
                return;
            }

            var originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Running…';

            var formData = new FormData();
            formData.append('force', button.getAttribute('data-force') || '1');

            var csrfToken = root.getAttribute('data-run-csrf-token');
            var csrfName = root.getAttribute('data-run-csrf-name') || '_csrf_token';
            if (csrfToken) {
                formData.append(csrfName, csrfToken);
            }

            var checkId = button.getAttribute('data-checkid') || '';
            if (checkId !== '') {
                formData.append('checkid', checkId);
            }

            fetch(runUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        return unwrapResponse(text) || {ok: false, message: 'Unexpected response from server.'};
                    });
                })
                .then(function (data) {
                    showPageStatus(root, data.message || (data.ok ? 'Run completed.' : 'Run failed.'), !data.ok);

                    if (data.ok) {
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    }
                })
                .catch(function (error) {
                    showPageStatus(root, 'Run failed: ' + error.message, true);
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = originalText;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initSettingsPage();
            initRunButtons();
        });
    }
    else {
        initSettingsPage();
        initRunButtons();
    }
}());
