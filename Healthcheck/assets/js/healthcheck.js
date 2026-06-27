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

    // Read a translated label provided by the view via data-i18n-* attributes, with a
    // hard-coded English fallback so the JS still works if an attribute is missing.
    function t(root, key, fallback) {
        if (root) {
            var value = root.getAttribute('data-i18n-' + key);
            if (value !== null && value !== '') {
                return value;
            }
        }
        return fallback;
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

    // Copy text to the clipboard with graceful degradation: the async Clipboard API is
    // only available in secure (https) contexts, so fall back to execCommand on http.
    function copyText(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function'
            && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            try {
                var temp = document.createElement('textarea');
                temp.value = text;
                temp.setAttribute('readonly', '');
                temp.style.position = 'absolute';
                temp.style.left = '-9999px';
                document.body.appendChild(temp);
                temp.select();
                temp.setSelectionRange(0, temp.value.length);

                var ok = document.execCommand('copy');
                document.body.removeChild(temp);

                if (ok) {
                    resolve();
                }
                else {
                    reject(new Error('execCommand copy failed'));
                }
            }
            catch (e) {
                reject(e);
            }
        });
    }

    function initSettingsPage() {
        var root = document.getElementById('healthcheck-settings-root');

        if (!root) {
            return;
        }

        root.addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-add-row]');
            var removeButton = event.target.closest('.hc-remove-row');
            var copyButton = event.target.closest('.hc-copy-btn');

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
                return;
            }

            if (copyButton) {
                event.preventDefault();
                var block = copyButton.closest('.hc-copy-block');
                var target = block ? block.querySelector('.hc-copy-target') : null;

                if (target) {
                    var text = target.value || target.textContent;
                    var original = copyButton.textContent;

                    copyText(text).then(function () {
                        copyButton.textContent = t(root, 'copied', 'Copied!');
                        setTimeout(function () { copyButton.textContent = original; }, 1500);
                    }).catch(function () {
                        copyButton.textContent = t(root, 'copy-failed', 'Copy failed');
                        setTimeout(function () { copyButton.textContent = original; }, 1500);
                    });
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
                submitBtn.textContent = t(root, 'saving', 'Saving…');
            }

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form)
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        return unwrapResponse(text)
                            || {ok: false, error: t(root, 'unexpected', 'Unexpected response from server.')};
                    });
                })
                .then(function (data) {
                    if (data.ok) {
                        window.location.reload();
                    }
                    else {
                        showPageStatus(root, data.error || data.message || t(root, 'save-failed', 'Save failed.'), true);
                    }
                })
                .catch(function (error) {
                    showPageStatus(root, t(root, 'save-failed', 'Save failed.') + ' ' + error.message, true);
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = t(root, 'save', 'Save settings');
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
                showPageStatus(root, t(root, 'run-url-missing', 'Run URL is missing.'), true);
                return;
            }

            var originalText = button.textContent;
            button.disabled = true;
            button.textContent = t(root, 'running', 'Running…');

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
                        return unwrapResponse(text)
                            || {ok: false, message: t(root, 'unexpected', 'Unexpected response from server.')};
                    });
                })
                .then(function (data) {
                    showPageStatus(
                        root,
                        data.message || (data.ok ? t(root, 'run-completed', 'Run completed.') : t(root, 'run-failed', 'Run failed.')),
                        !data.ok
                    );

                    if (data.ok) {
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    }
                })
                .catch(function (error) {
                    showPageStatus(root, t(root, 'run-failed', 'Run failed.') + ' ' + error.message, true);
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = originalText;
                });
        });
    }

    // Replace the former <meta http-equiv="refresh"> with a JS-driven reload that skips
    // the cycle while a run/save status banner is showing, so AJAX feedback is never wiped.
    function initHeartbeatAutoRefresh() {
        var root = document.getElementById('healthcheck-heartbeat-root');

        if (!root) {
            return;
        }

        var seconds = parseInt(root.getAttribute('data-refresh-seconds'), 10);
        if (!seconds || seconds < 10) {
            seconds = 30;
        }

        var toggle = document.getElementById('hc-autorefresh-toggle');

        function tick() {
            window.setTimeout(function () {
                var enabled = !toggle || toggle.checked;
                var bannerShowing = !!root.querySelector('.hc-page-status');

                if (enabled && !bannerShowing) {
                    window.location.reload();
                    return;
                }

                // Skipped this cycle (disabled or a banner is visible) — try again later.
                tick();
            }, seconds * 1000);
        }

        tick();
    }

    function initSchedulerCommands() {
        var section = document.getElementById('healthcheck-scheduler-section');

        if (!section) {
            return;
        }

        var select = document.getElementById('hc-runner-user-select');
        var cronEl = document.getElementById('hc-cron-commands');
        var systemdEl = document.getElementById('hc-systemd-commands');
        var testEl = document.getElementById('hc-test-command');

        if (!select || !cronEl || !systemdEl || !testEl) {
            return;
        }

        var runnerPath = section.getAttribute('data-runner-path');
        var cronSchedule = section.getAttribute('data-cron-schedule');

        function buildCommands(user) {
            var cronLine = cronSchedule + ' /usr/bin/php ' + runnerPath + ' --json >/var/log/zabbix/healthcheck-runner.log 2>&1';

            cronEl.value = [
                '# Install or update the cron job for user "' + user + '"',
                '# (safe to re-run — replaces any previous healthcheck-runner entry)',
                'sudo crontab -u ' + user + ' -l 2>/dev/null | grep -v healthcheck-runner | { cat; echo \'' + cronLine + '\'; } | sudo crontab -u ' + user + ' -',
                '',
                '# Verify',
                'sudo crontab -u ' + user + ' -l'
            ].join('\n');

            systemdEl.value = [
                '# Create / update the service unit',
                'cat << \'EOF\' | sudo tee /etc/systemd/system/healthcheck-runner.service > /dev/null',
                '[Unit]',
                'Description=Zabbix Healthcheck module runner',
                'Wants=network-online.target',
                'After=network-online.target',
                '',
                '[Service]',
                'Type=oneshot',
                'User=' + user,
                'Group=' + user,
                'ExecStart=/usr/bin/php ' + runnerPath + ' --json',
                'NoNewPrivileges=true',
                'PrivateTmp=true',
                'ProtectHome=true',
                'ProtectSystem=full',
                'EOF',
                '',
                '# Create / update the timer unit',
                'cat << \'EOF\' | sudo tee /etc/systemd/system/healthcheck-runner.timer > /dev/null',
                '[Unit]',
                'Description=Run the Zabbix Healthcheck module runner every minute',
                '',
                '[Timer]',
                'OnBootSec=1min',
                'OnUnitActiveSec=1min',
                'Unit=healthcheck-runner.service',
                'Persistent=true',
                '',
                '[Install]',
                'WantedBy=timers.target',
                'EOF',
                '',
                '# Reload, enable and (re)start the timer',
                'sudo systemctl daemon-reload',
                'sudo systemctl enable --now healthcheck-runner.timer',
                'sudo systemctl restart healthcheck-runner.timer',
                '',
                '# Verify',
                'systemctl list-timers healthcheck-runner.timer'
            ].join('\n');

            testEl.value = 'sudo -u ' + user + ' /usr/bin/php ' + runnerPath + ' --json';
        }

        select.addEventListener('change', function () {
            buildCommands(select.value);
        });

        // Set initial values.
        buildCommands(select.value);
    }

    function initAll() {
        initSettingsPage();
        initRunButtons();
        initHeartbeatAutoRefresh();
        initSchedulerCommands();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    }
    else {
        initAll();
    }
}());
