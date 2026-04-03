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
        var eventidSearchField = document.getElementById('ai-eventid-search');
        var eventidList = document.getElementById('ai-eventid-list');
        var hostnameField = document.getElementById('ai-hostname');
        var hostnameIdField = document.getElementById('ai-hostname-id');
        var hostnameSearchField = document.getElementById('ai-hostname-search');
        var hostnameList = document.getElementById('ai-hostname-list');
        var problemSummaryField = document.getElementById('ai-problem-summary');
        var extraContextField = document.getElementById('ai-extra-context');
        var clearButton = document.getElementById('ai-clear-session');
        var postButton = document.getElementById('ai-post-last-answer');
        var sideStatus = document.getElementById('ai-side-status');

        var sendUrl = root.dataset.sendUrl;
        var commentUrl = root.dataset.commentUrl;
        var hostsUrl = root.dataset.hostsUrl;
        var problemsUrl = root.dataset.problemsUrl;
        var executeUrl = root.dataset.executeUrl || '';
        var chatCsrf = root.dataset.chatCsrf;
        var commentCsrf = root.dataset.commentCsrf;
        var executeCsrf = root.dataset.executeCsrf || '';
        var historyLimit = parseInt(root.dataset.historyLimit || '12', 10);
        var hasZabbixApi = root.dataset.hasZabbixApi === '1';
        var csrfFieldName = root.dataset.csrfFieldName || '_csrf_token';

        // Tracks a pending write action awaiting user confirmation.
        var pendingAction = null;

        var HISTORY_KEY = 'zbx_ai_chat_history_v1';
        var CONTEXT_KEY = 'zbx_ai_chat_context_v1';
        var CHAT_SESSION_KEY = 'zbx_ai_chat_session_id_v1';
        var SEVERITY_LABELS = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

        var chatSessionId = ensureChatSessionId();

        var allHosts = [];
        var allProblems = [];
        var problemsLoaded = false;
        var problemsFetchController = null;

        var history = loadJson(HISTORY_KEY, []);
        var context = loadJson(CONTEXT_KEY, {});

        if (!hostnameField.value && context.hostname) {
            hostnameField.value = context.hostname;
            hostnameSearchField.value = context.hostname;
        }
        if (hostnameField.value) {
            hostnameSearchField.value = hostnameField.value;
        }
        if (!eventidField.value && context.eventid) {
            eventidField.value = context.eventid;
            if (context.eventid_label) {
                eventidSearchField.value = context.eventid_label;
            } else {
                eventidSearchField.value = context.eventid;
            }
        }
        if (eventidField.value && !eventidSearchField.value) {
            eventidSearchField.value = eventidField.value;
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
        if (context.hostid) {
            hostnameIdField.value = context.hostid;
        }

        history = normalizeHistory(history, historyLimit);
        renderHistory();
        updatePostButtonState();

        if (hasZabbixApi) {
            loadHosts();
        }

        [providerField, problemSummaryField, extraContextField].forEach(function (element) {
            if (!element) {
                return;
            }

            element.addEventListener('input', saveContext);
            element.addEventListener('change', saveContext);
        });

        initSearchableDropdown(hostnameSearchField, hostnameList, {
            getItems: function () { return allHosts; },
            formatItem: function (host) {
                var label = host.host;
                if (host.name && host.name !== host.host) {
                    label += ' (' + host.name + ')';
                }
                return label;
            },
            filterItem: function (host, query) {
                var q = query.toLowerCase();
                return host.host.toLowerCase().indexOf(q) !== -1 ||
                    (host.name && host.name.toLowerCase().indexOf(q) !== -1);
            },
            onSelect: function (host) {
                hostnameField.value = host.host;
                hostnameIdField.value = host.hostid;
                hostnameSearchField.value = host.host;
                eventidField.value = '';
                eventidSearchField.value = '';
                allProblems = [];
                problemsLoaded = false;
                saveContext();
            },
            onClear: function () {
                hostnameField.value = '';
                hostnameIdField.value = '';
                allProblems = [];
                problemsLoaded = false;
                saveContext();
            }
        });

        initSearchableDropdown(eventidSearchField, eventidList, {
            serverSide: true,
            getItems: function () { return allProblems; },
            formatItem: function (problem) {
                var sev = SEVERITY_LABELS[parseInt(problem.severity, 10)] || 'Unknown';
                return problem.eventid + ' \u2014 [' + sev + '] ' + problem.name;
            },
            filterItem: function (problem, query) {
                var q = query.toLowerCase();
                return problem.eventid.indexOf(q) !== -1 ||
                    problem.name.toLowerCase().indexOf(q) !== -1;
            },
            onSearch: function (query) {
                searchProblems(query);
            },
            onSelect: function (problem) {
                eventidField.value = problem.eventid;
                var sev = SEVERITY_LABELS[parseInt(problem.severity, 10)] || 'Unknown';
                eventidSearchField.value = problem.eventid + ' \u2014 [' + sev + '] ' + problem.name;
                if (problem.name && !problemSummaryField.value) {
                    problemSummaryField.value = problem.name;
                }
                saveContext();
            },
            onClear: function () {
                eventidField.value = '';
                saveContext();
            },
            onFocus: function () {
                if (!problemsLoaded) {
                    searchProblems('');
                }
            }
        });

        function loadHosts() {
            if (!hostsUrl) return;
            fetch(hostsUrl, { method: 'GET', credentials: 'same-origin' })
                .then(handleJsonResponse)
                .then(function (response) {
                    if (response.ok && Array.isArray(response.hosts)) {
                        allHosts = response.hosts;
                        if (hostnameField.value && !hostnameIdField.value) {
                            for (var i = 0; i < allHosts.length; i++) {
                                if (allHosts[i].host === hostnameField.value) {
                                    hostnameIdField.value = allHosts[i].hostid;
                                    break;
                                }
                            }
                        }
                    }
                })
                .catch(function () {});
        }

        function searchProblems(query) {
            if (!problemsUrl) return;

            if (problemsFetchController) {
                problemsFetchController.abort();
            }
            problemsFetchController = new AbortController();

            var url = problemsUrl;
            var sep = url.indexOf('?') !== -1 ? '&' : '?';
            var hostid = hostnameIdField.value || '';
            if (hostid) {
                url += sep + 'hostid=' + encodeURIComponent(hostid);
                sep = '&';
            }
            if (query) {
                url += sep + 'search=' + encodeURIComponent(query);
            }

            fetch(url, { method: 'GET', credentials: 'same-origin', signal: problemsFetchController.signal })
                .then(handleJsonResponse)
                .then(function (response) {
                    if (response.ok && Array.isArray(response.problems)) {
                        allProblems = response.problems;
                        problemsLoaded = true;
                        renderDropdownItems(eventidList, {
                            getItems: function () { return allProblems; },
                            formatItem: function (problem) {
                                var sev = SEVERITY_LABELS[parseInt(problem.severity, 10)] || 'Unknown';
                                return problem.eventid + ' \u2014 [' + sev + '] ' + problem.name;
                            },
                            filterItem: function () { return true; },
                            onSelect: function (problem) {
                                eventidField.value = problem.eventid;
                                var sev = SEVERITY_LABELS[parseInt(problem.severity, 10)] || 'Unknown';
                                eventidSearchField.value = problem.eventid + ' \u2014 [' + sev + '] ' + problem.name;
                                if (problem.name && !problemSummaryField.value) {
                                    problemSummaryField.value = problem.name;
                                }
                                saveContext();
                                eventidList.classList.add('ai-hidden');
                            }
                        }, '');
                        eventidList.classList.remove('ai-hidden');
                    }
                })
                .catch(function (e) {
                    if (e.name !== 'AbortError') {
                        problemsLoaded = false;
                    }
                });
        }

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
            params.set('chat_session_id', chatSessionId);
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

                    // Handle pending write action that needs confirmation.
                    if (response.action_pending) {
                        pendingAction = {
                            action_id: response.pending_action_id || '',
                            tool: response.pending_tool || '',
                            provider_id: providerField ? providerField.value : ''
                        };

                        history = normalizeHistory(history.concat([{ role: 'assistant', content: response.reply || '' }]), historyLimit);
                        persistHistory();
                        renderHistory();
                        showActionConfirmButtons();
                        showSideStatus('Action requires confirmation.', false);
                        updatePostButtonState();
                        return;
                    }

                    pendingAction = null;

                    var replyPrefix = '';
                    if (response.action_executed) {
                        replyPrefix = '[Zabbix Action: ' + (response.action_tool || 'executed') + ']\n\n';
                    }

                    history = normalizeHistory(history.concat([{ role: 'assistant', content: replyPrefix + (response.reply || '') }]), historyLimit);
                    persistHistory();
                    renderHistory();

                    var statusText = 'Reply received from ' + (response.provider_name || 'AI') + '.';
                    if (response.action_executed) {
                        statusText = 'Zabbix action "' + (response.action_tool || '') + '" executed. ' + statusText;
                    }
                    showSideStatus(statusText, false);
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
            sessionStorage.removeItem(CHAT_SESSION_KEY);
            chatSessionId = ensureChatSessionId(true);
            if (eventidField) {
                eventidField.value = '';
            }
            if (eventidSearchField) {
                eventidSearchField.value = '';
            }
            if (hostnameField) {
                hostnameField.value = '';
            }
            if (hostnameIdField) {
                hostnameIdField.value = '';
            }
            if (hostnameSearchField) {
                hostnameSearchField.value = '';
            }
            if (problemSummaryField) {
                problemSummaryField.value = '';
            }
            if (extraContextField) {
                extraContextField.value = '';
            }
            allProblems = [];
            problemsLoaded = false;
            pendingAction = null;
            removeConfirmBar();
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
                params.set('chat_session_id', chatSessionId);
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
                var isAction = message.role === 'assistant' && message.content && message.content.indexOf('[Zabbix Action:') === 0;

                var item = document.createElement('div');
                item.className = 'ai-msg ai-msg-' + message.role + (isAction ? ' ai-msg-action' : '');

                var title = document.createElement('div');
                title.className = 'ai-msg-title';
                title.textContent = isAction ? 'AI (Zabbix Action)' : (message.role === 'assistant' ? 'AI' : 'You');

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
                eventid_label: eventidSearchField ? eventidSearchField.value : '',
                hostname: hostnameField ? hostnameField.value : '',
                hostid: hostnameIdField ? hostnameIdField.value : '',
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

        /**
         * Show Confirm / Cancel buttons in the transcript for a pending write action.
         */
        function showActionConfirmButtons() {
            var btns = document.createElement('div');
            btns.className = 'ai-action-confirm-buttons';
            btns.id = 'ai-action-confirm-bar';

            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'btn ai-confirm-btn';
            confirmBtn.textContent = 'Confirm';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn-alt ai-cancel-btn';
            cancelBtn.textContent = 'Cancel';

            confirmBtn.addEventListener('click', function () {
                removeConfirmBar();
                executeConfirmedAction();
            });

            cancelBtn.addEventListener('click', function () {
                removeConfirmBar();
                pendingAction = null;
                history = normalizeHistory(history.concat([{ role: 'assistant', content: 'Action cancelled by user.' }]), historyLimit);
                persistHistory();
                renderHistory();
                showSideStatus('Action cancelled.', false);
            });

            btns.appendChild(confirmBtn);
            btns.appendChild(cancelBtn);
            transcript.appendChild(btns);
            transcript.scrollTop = transcript.scrollHeight;
        }

        function removeConfirmBar() {
            var bar = document.getElementById('ai-action-confirm-bar');
            if (bar) bar.remove();
        }

        /**
         * Execute a confirmed write action via the ChatExecute endpoint.
         */
        function executeConfirmedAction() {
            if (!pendingAction || !executeUrl) {
                showSideStatus('No pending action to execute.', true);
                return;
            }

            setBusy(true);
            showSideStatus('Executing Zabbix action...', false);

            var params = new URLSearchParams();
            params.set('action_id', pendingAction.action_id || '');
            params.set('provider_id', pendingAction.provider_id || '');
            params.set('chat_session_id', chatSessionId);
            params.set(csrfFieldName, executeCsrf);

            var actionTool = pendingAction.tool || 'executed';
            pendingAction = null;

            fetch(executeUrl, {
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
                        throw new Error(response.error || 'Action execution failed.');
                    }

                    var prefix = '[Zabbix Action: ' + (response.action_tool || actionTool) + ']\n\n';
                    history = normalizeHistory(history.concat([{ role: 'assistant', content: prefix + (response.reply || '') }]), historyLimit);
                    persistHistory();
                    renderHistory();
                    showSideStatus('Zabbix action "' + (response.action_tool || actionTool) + '" executed successfully.', false);
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
        }
    }

    function generateId(prefix) {
        return prefix + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    function ensureChatSessionId(reset) {
        var key = 'zbx_ai_chat_session_id_v1';

        if (reset) {
            sessionStorage.removeItem(key);
        }

        var current = sessionStorage.getItem(key);
        if (current) {
            return current;
        }

        current = generateId('chat');
        sessionStorage.setItem(key, current);
        return current;
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

    function initSearchableDropdown(searchField, listEl, opts) {
        var debounceTimer = null;
        var debounceMs = opts.serverSide ? 350 : 150;

        searchField.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var query = (searchField.value || '').trim();
                if (opts.serverSide && opts.onSearch) {
                    opts.onSearch(query);
                } else {
                    renderDropdownItems(listEl, opts, query);
                    listEl.classList.remove('ai-hidden');
                }
            }, debounceMs);
        });

        searchField.addEventListener('focus', function () {
            if (opts.serverSide && opts.onFocus) {
                opts.onFocus();
            } else if (!opts.serverSide) {
                var query = (searchField.value || '').trim();
                renderDropdownItems(listEl, opts, query);
                listEl.classList.remove('ai-hidden');
            }
        });

        document.addEventListener('mousedown', function (e) {
            if (!searchField.parentNode.contains(e.target)) {
                listEl.classList.add('ai-hidden');
            }
        });

        searchField.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                listEl.classList.add('ai-hidden');
                searchField.blur();
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var first = listEl.querySelector('.ai-dropdown-item');
                if (first) first.focus();
            }
        });

        listEl.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var next = document.activeElement && document.activeElement.nextElementSibling;
                if (next && next.classList.contains('ai-dropdown-item')) next.focus();
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prev = document.activeElement && document.activeElement.previousElementSibling;
                if (prev && prev.classList.contains('ai-dropdown-item')) {
                    prev.focus();
                } else {
                    searchField.focus();
                }
            }
            if (e.key === 'Escape') {
                listEl.classList.add('ai-hidden');
                searchField.focus();
            }
        });

        searchField.addEventListener('change', function () {
            if (searchField.value.trim() === '' && opts.onClear) {
                opts.onClear();
            }
        });
    }

    function renderDropdownItems(listEl, opts, query) {
        listEl.innerHTML = '';
        var items = opts.getItems();
        var shown = 0;
        var MAX = 100;

        for (var i = 0; i < items.length && shown < MAX; i++) {
            if (query && !opts.filterItem(items[i], query)) {
                continue;
            }

            var div = document.createElement('div');
            div.className = 'ai-dropdown-item';
            div.textContent = opts.formatItem(items[i]);
            div.tabIndex = 0;
            div.dataset.index = String(i);

            (function (item) {
                div.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    opts.onSelect(item);
                    listEl.classList.add('ai-hidden');
                });
                div.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        opts.onSelect(item);
                        listEl.classList.add('ai-hidden');
                    }
                });
            })(items[i]);

            listEl.appendChild(div);
            shown++;
        }

        if (shown === 0) {
            var empty = document.createElement('div');
            empty.className = 'ai-dropdown-empty';
            empty.textContent = 'No matches found.';
            listEl.appendChild(empty);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
}());

