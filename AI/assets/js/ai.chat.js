(function () {
    'use strict';

    function init() {
        var root = document.getElementById('ai-chat-root');

        if (!root) {
            return;
        }

        var transcript = document.getElementById('ai-transcript');
        var composeForm = document.getElementById('ai-compose-form');
        var messageField = document.getElementById('ai-message');
        var sendButton = document.getElementById('ai-send-button');
        var providerField = document.getElementById('ai-provider-id');
        var eventidField = document.getElementById('ai-eventid');
        var hostnameField = document.getElementById('ai-hostname');
        var problemSummaryField = document.getElementById('ai-problem-summary');
        var extraContextField = document.getElementById('ai-extra-context');
        var clearButton = document.getElementById('ai-clear-session');
        var postButton = document.getElementById('ai-post-last-answer');
        var sideStatus = document.getElementById('ai-side-status');

        var sendUrl = root.dataset.sendUrl;
        var commentUrl = root.dataset.commentUrl;
        var chatCsrf = root.dataset.chatCsrf;
        var commentCsrf = root.dataset.commentCsrf;
        var historyLimit = parseInt(root.dataset.historyLimit || '12', 10);
        var hasZabbixApi = root.dataset.hasZabbixApi === '1';
        var csrfFieldName = root.dataset.csrfFieldName || '_csrf_token';

        var HISTORY_KEY = 'zbx_ai_chat_history_v1';
        var CONTEXT_KEY = 'zbx_ai_chat_context_v1';

        var history = loadJson(HISTORY_KEY, []);
        var context = loadJson(CONTEXT_KEY, {});

        if (!eventidField.value && context.eventid) {
            eventidField.value = context.eventid;
        }
        if (!hostnameField.value && context.hostname) {
            hostnameField.value = context.hostname;
        }
        if (!problemSummaryField.value && context.problem_summary) {
            problemSummaryField.value = context.problem_summary;
        }
        if (context.extra_context) {
            extraContextField.value = context.extra_context;
        }
        if (context.provider_id && providerField && providerField.querySelector('option[value="' + cssEscape(context.provider_id) + '"]')) {
            providerField.value = context.provider_id;
        }

        history = normalizeHistory(history, historyLimit);
        renderHistory();
        updatePostButtonState();

        [providerField, eventidField, hostnameField, problemSummaryField, extraContextField].forEach(function (element) {
            if (!element) {
                return;
            }

            element.addEventListener('input', saveContext);
            element.addEventListener('change', saveContext);
        });

        composeForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var message = (messageField.value || '').trim();

            if (!message) {
                showSideStatus('Enter a message first.', true);
                return;
            }

            if (!providerField || !providerField.value) {
                showSideStatus('Select a provider first.', true);
                return;
            }

            var requestHistory = normalizeHistory(history.slice(), historyLimit);
            history = normalizeHistory(requestHistory.concat([{ role: 'user', content: message }]), historyLimit);
            persistHistory();
            renderHistory();

            messageField.value = '';
            setBusy(true);

            var params = new URLSearchParams();
            params.set('provider_id', providerField.value);
            params.set('message', message);
            params.set('history_json', JSON.stringify(requestHistory));
            params.set('eventid', eventidField.value || '');
            params.set('hostname', hostnameField.value || '');
            params.set('problem_summary', problemSummaryField.value || '');
            params.set('extra_context', extraContextField.value || '');
            params.set(csrfFieldName, chatCsrf);

            fetch(sendUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: params.toString()
            })
                .then(handleJsonResponse)
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(response.error || 'Chat request failed.');
                    }

                    history = normalizeHistory(history.concat([{ role: 'assistant', content: response.reply || '' }]), historyLimit);
                    persistHistory();
                    renderHistory();
                    showSideStatus('Reply received from ' + (response.provider_name || 'AI') + '.', false);
                    updatePostButtonState();
                })
                .catch(function (error) {
                    history = normalizeHistory(history.concat([{ role: 'assistant', content: '[Error] ' + error.message }]), historyLimit);
                    persistHistory();
                    renderHistory();
                    showSideStatus(error.message, true);
                })
                .finally(function () {
                    setBusy(false);
                    messageField.focus();
                });
        });

        clearButton.addEventListener('click', function () {
            history = [];
            sessionStorage.removeItem(HISTORY_KEY);
            sessionStorage.removeItem(CONTEXT_KEY);
            if (eventidField) {
                eventidField.value = '';
            }
            if (hostnameField) {
                hostnameField.value = '';
            }
            if (problemSummaryField) {
                problemSummaryField.value = '';
            }
            if (extraContextField) {
                extraContextField.value = '';
            }
            renderHistory();
            showSideStatus('Session cleared.', false);
            updatePostButtonState();
        });

        if (postButton) {
            postButton.addEventListener('click', function () {
                if (!hasZabbixApi) {
                    showSideStatus('Configure Zabbix API settings first.', true);
                    return;
                }

                var eventid = (eventidField.value || '').trim();

                if (!eventid) {
                    showSideStatus('Event ID is required to post a comment.', true);
                    return;
                }

                var lastAssistant = getLastAssistantMessage();

                if (!lastAssistant) {
                    showSideStatus('There is no assistant reply to post yet.', true);
                    return;
                }

                postButton.disabled = true;

                var params = new URLSearchParams();
                params.set('eventid', eventid);
                params.set('message', lastAssistant);
                params.set(csrfFieldName, commentCsrf);

                fetch(commentUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: params.toString()
                })
                    .then(handleJsonResponse)
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error(response.error || 'Could not post event comment.');
                        }

                        showSideStatus('Posted ' + response.chunks + ' problem update comment chunk(s).', false);
                    })
                    .catch(function (error) {
                        showSideStatus(error.message, true);
                    })
                    .finally(function () {
                        updatePostButtonState();
                    });
            });
        }


        function setBusy(isBusy) {
            sendButton.disabled = isBusy;
            sendButton.textContent = isBusy ? 'Sending…' : 'Send';
        }

        function renderHistory() {
            transcript.innerHTML = '';

            if (!history.length) {
                var empty = document.createElement('div');
                empty.className = 'ai-empty-state';
                empty.textContent = 'No messages yet.';
                transcript.appendChild(empty);
                return;
            }

            history.forEach(function (message) {
                var item = document.createElement('div');
                item.className = 'ai-msg ai-msg-' + message.role;

                var title = document.createElement('div');
                title.className = 'ai-msg-title';
                title.textContent = message.role === 'assistant' ? 'AI' : 'You';

                var body = document.createElement('pre');
                body.className = 'ai-msg-body';
                body.textContent = message.content || '';

                item.appendChild(title);
                item.appendChild(body);
                transcript.appendChild(item);
            });

            transcript.scrollTop = transcript.scrollHeight;
        }

        function persistHistory() {
            sessionStorage.setItem(HISTORY_KEY, JSON.stringify(normalizeHistory(history, historyLimit)));
            saveContext();
        }

        function saveContext() {
            var currentContext = {
                provider_id: providerField ? providerField.value : '',
                eventid: eventidField ? eventidField.value : '',
                hostname: hostnameField ? hostnameField.value : '',
                problem_summary: problemSummaryField ? problemSummaryField.value : '',
                extra_context: extraContextField ? extraContextField.value : ''
            };

            sessionStorage.setItem(CONTEXT_KEY, JSON.stringify(currentContext));
            updatePostButtonState();
        }

        function showSideStatus(message, isError) {
            if (!sideStatus) {
                return;
            }

            sideStatus.textContent = message;
            sideStatus.classList.remove('ai-hidden', 'ai-status-error', 'ai-status-ok');
            sideStatus.classList.add(isError ? 'ai-status-error' : 'ai-status-ok');
        }

        function getLastAssistantMessage() {
            for (var i = history.length - 1; i >= 0; i -= 1) {
                if (history[i].role === 'assistant' && (history[i].content || '').trim() !== '') {
                    return history[i].content;
                }
            }

            return '';
        }

        function updatePostButtonState() {
            if (!postButton) {
                return;
            }

            postButton.disabled = !hasZabbixApi || !(eventidField.value || '').trim() || !getLastAssistantMessage();
        }
    }

    function loadJson(key, fallback) {
        try {
            var raw = sessionStorage.getItem(key);

            if (!raw) {
                return fallback;
            }

            var parsed = JSON.parse(raw);

            return parsed && typeof parsed === 'object' ? parsed : fallback;
        }
        catch (error) {
            return fallback;
        }
    }

    function normalizeHistory(history, limit) {
        if (!Array.isArray(history)) {
            return [];
        }

        var filtered = history.filter(function (item) {
            return item
                && (item.role === 'user' || item.role === 'assistant')
                && typeof item.content === 'string'
                && item.content.trim() !== '';
        });

        if (limit >= 0 && filtered.length > limit) {
            filtered = filtered.slice(filtered.length - limit);
        }

        return filtered;
    }

    function handleJsonResponse(response) {
        return response.text().then(function (text) {
            var parsed = parseJsonSafe(text);

            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                var inner = parseJsonSafe(parsed.main_block);

                if (inner) {
                    parsed = inner;
                }
            }

            if (!parsed) {
                parsed = { ok: false, error: text || 'Invalid JSON response.' };
            }

            if (!response.ok && !parsed.error) {
                parsed.error = 'HTTP ' + response.status;
            }

            return parsed;
        });
    }

    function parseJsonSafe(text) {
        try {
            return JSON.parse(text);
        }
        catch (error) {
            return null;
        }
    }

    function cssEscape(value) {
        return String(value).replace(/["\\]/g, '\\$&');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
}());

