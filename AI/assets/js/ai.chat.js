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
        var historyButton = document.getElementById('ai-include-history');
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
        var canPostEventComment = root.dataset.canPostEventComment === '1';
        var csrfFieldName = root.dataset.csrfFieldName || '_csrf_token';
        var contextUrl = root.dataset.contextUrl || '';
        var historyPeriod = parseInt(root.dataset.historyPeriod || '24', 10);
        var historyMaxRows = parseInt(root.dataset.historyMaxRows || '50', 10);

        // Tracks a pending write or privacy-sensitive read awaiting confirmation.
        var pendingAction = null;
        var pendingUntrustedMonitoringContext = '';
        var pendingUntrustedMonitoringEventId = '';
        var monitoringContextGeneration = 0;

        var HISTORY_KEY = 'zbx_ai_chat_history_v1';
        var CONTEXT_KEY = 'zbx_ai_chat_context_v1';
        var CHAT_SESSION_KEY = 'zbx_ai_chat_session_id_v1';
        var TRANSFER_KEY = 'zbx_ai_chat_transfer';
        var SEVERITY_LABELS = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

        // Check for state transferred from the problem drawer (via localStorage).
        importTransferredState();

        var chatSessionId = ensureChatSessionId();

        var allHosts = [];
        var allProblems = [];
        var problemsLoaded = false;
        var problemsFetchController = null;
        // When a problem is selected, the host dropdown is constrained to that
        // problem's host(s). null = no constraint (show all hosts).
        var problemHostLock = null;

        var history = loadJson(HISTORY_KEY, []);
        var context = loadJson(CONTEXT_KEY, {});
        var providerUserOverride = context.provider_user_override === true;

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
        if (providerUserOverride && context.provider_id && providerField && providerField.querySelector('option[value="' + cssEscape(context.provider_id) + '"]')) {
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

        [problemSummaryField, extraContextField].forEach(function (element) {
            if (!element) {
                return;
            }

            element.addEventListener('input', saveContext);
            element.addEventListener('change', saveContext);
        });
        if (providerField) {
            providerField.addEventListener('change', function () {
                providerUserOverride = providerField.value !== '';
                saveContext();
            });
        }

        initSearchableDropdown(hostnameSearchField, hostnameList, {
            // While a problem is selected, only its related host(s) are offered.
            getItems: function () { return problemHostLock || allHosts; },
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
                invalidatePendingMonitoringContext(true);
                hostnameField.value = host.host;
                hostnameIdField.value = host.hostid;
                hostnameSearchField.value = host.host;
                // Selecting a host re-scopes the problem list to that host. Drop a
                // previously selected problem only when it belongs to a different
                // host, so picking the problem's own host doesn't wipe it.
                if (!hostMatchesSelectedProblem(host)) {
                    eventidField.value = '';
                    eventidSearchField.value = '';
                    problemHostLock = null;
                }
                allProblems = [];
                problemsLoaded = false;
                saveContext();
            },
            onClear: function () {
                invalidatePendingMonitoringContext(true);
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
                selectProblem(problem);
            },
            onClear: function () {
                invalidatePendingMonitoringContext(true);
                eventidField.value = '';
                problemHostLock = null;
                saveContext();
            },
            onFocus: function () {
                if (!problemsLoaded) {
                    searchProblems('');
                }
            }
        });

        function selectProblem(problem) {
            invalidatePendingMonitoringContext(true);
            eventidField.value = problem.eventid;
            var sev = SEVERITY_LABELS[parseInt(problem.severity, 10)] || 'Unknown';
            eventidSearchField.value = problem.eventid + ' — [' + sev + '] ' + problem.name;
            if (problem.name && !problemSummaryField.value) {
                problemSummaryField.value = problem.name;
            }
            applyProblemHost(problem);
            saveContext();
        }

        // Bind a selected problem to its host: fill the host fields and constrain
        // the host dropdown to that problem's host(s) so the two stay consistent.
        function applyProblemHost(problem) {
            var hosts = (problem && Array.isArray(problem.hosts)) ? problem.hosts : [];
            if (!hosts.length) {
                return;
            }

            var primary = hosts[0];
            if (primary && primary.host) {
                hostnameField.value = primary.host;
                hostnameIdField.value = primary.hostid || '';
                var label = primary.host;
                if (primary.name && primary.name !== primary.host) {
                    label += ' (' + primary.name + ')';
                }
                hostnameSearchField.value = label;
            }

            problemHostLock = hosts;
        }

        function hostMatchesSelectedProblem(host) {
            if (!eventidField.value || !problemHostLock) {
                return false;
            }
            for (var i = 0; i < problemHostLock.length; i++) {
                if (problemHostLock[i].hostid && host.hostid && problemHostLock[i].hostid === host.hostid) {
                    return true;
                }
            }
            return false;
        }

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
                                selectProblem(problem);
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

            if (!providerField || providerField.disabled) {
                showSideStatus('Configure and enable a provider first.', true);
                return;
            }

            var requestHistory = normalizeHistory(history.filter(function (item) {
                return !item.non_forwardable;
            }), historyLimit);
            history = normalizeHistory(requestHistory.concat([{ role: 'user', content: message }]), historyLimit);
            persistHistory();
            renderHistory();

            messageField.value = '';
            setBusy(true);

            var params = new URLSearchParams();
            params.set('provider_id', providerField.value);
            params.set('provider_user_override', providerUserOverride ? '1' : '0');
            params.set('message', message);
            params.set('history_json', JSON.stringify(requestHistory));
            var selectedEventid = (eventidField.value || '').trim();
            params.set('eventid', selectedEventid);
            params.set('hostname', hostnameField.value || '');
            params.set('problem_summary', problemSummaryField.value || '');
            params.set('extra_context', extraContextField.value || '');
            params.set('untrusted_monitoring_context',
                pendingUntrustedMonitoringEventId === selectedEventid
                    ? pendingUntrustedMonitoringContext
                    : '');
            pendingUntrustedMonitoringContext = '';
            pendingUntrustedMonitoringEventId = '';
            monitoringContextGeneration += 1;
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

                    // Handle a pending write or privacy-sensitive read.
                    if (response.action_pending) {
                        pendingAction = {
                            action_id: response.pending_action_id || '',
                            confirmation_hash: response.pending_confirmation_hash || '',
                            confirmation_level: response.confirmation_level || 'standard',
                            tool: response.pending_tool || '',
                            provider_id: providerField ? providerField.value : ''
                        };

                        history = normalizeHistory(history.concat([{
                            role: 'assistant',
                            content: response.reply || '',
                            non_forwardable: true
                        }]), historyLimit);
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
            if (providerField) {
                providerField.value = '';
            }
            allProblems = [];
            problemsLoaded = false;
            problemHostLock = null;
            pendingAction = null;
            providerUserOverride = false;
            pendingUntrustedMonitoringContext = '';
            pendingUntrustedMonitoringEventId = '';
            monitoringContextGeneration += 1;
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

                if (!canPostEventComment) {
                    showSideStatus('Enable Read & Write mode and problems write permission in AI Settings > Zabbix Actions.', true);
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

        if (historyButton) {
            historyButton.addEventListener('click', function () {
                if (!hasZabbixApi || !contextUrl) {
                    showSideStatus('Configure Zabbix API settings first.', true);
                    return;
                }

                var eventid = (eventidField.value || '').trim();

                if (!eventid) {
                    showSideStatus('Select an event/problem first to include its item history.', true);
                    return;
                }

                historyButton.disabled = true;
                showSideStatus('Fetching item history...', false);
                invalidatePendingMonitoringContext(true);
                var requestGeneration = monitoringContextGeneration;

                var url = contextUrl;
                var sep = url.indexOf('?') !== -1 ? '&' : '?';
                url += sep + 'eventid=' + encodeURIComponent(eventid);
                url += '&include_history=1';
                url += '&history_limit=' + encodeURIComponent(historyMaxRows);

                fetch(url, { method: 'GET', credentials: 'same-origin' })
                    .then(handleJsonResponse)
                    .then(function (response) {
                        if (requestGeneration !== monitoringContextGeneration
                            || (eventidField.value || '').trim() !== eventid) {
                            throw new Error('The selected event changed while history was loading. Load its history again.');
                        }
                        if (!response.ok) {
                            throw new Error(response.error || 'Failed to fetch item history.');
                        }

                        var items = response.item_history || [];

                        if (!items.length) {
                            showSideStatus('No item history data available for this event.', true);
                            historyButton.disabled = false;
                            return;
                        }

                        var lines = ['Here is the recent item history/trend data for the related items of this problem. Please analyze the trends and incorporate this data into your assessment:\n'];

                        for (var i = 0; i < items.length; i++) {
                            var item = items[i];
                            lines.push('## ' + (item.label || 'Unknown item'));
                            for (var j = 0; j < (item.values || []).length; j++) {
                                lines.push('  ' + item.values[j].time + '  \u2192  ' + item.values[j].value);
                            }
                            lines.push('');
                        }

                        var historyMessage = lines.join('\n');
                        pendingUntrustedMonitoringContext = historyMessage;
                        pendingUntrustedMonitoringEventId = eventid;

                        // Display the fetched values locally, but never promote
                        // monitored labels/logs/values into a user message or
                        // provider-forwarded assistant history.
                        history = normalizeHistory(history.concat([{
                            role: 'assistant',
                            content: '[Untrusted monitoring data — display only]\n\n' + historyMessage,
                            non_forwardable: true,
                            monitoring_context_eventid: eventid
                        }]), historyLimit);
                        persistHistory();
                        renderHistory();
                        if ((messageField.value || '').trim() === '') {
                            messageField.value = 'Analyze the recently loaded item history and incorporate relevant trends into your assessment.';
                            showSideStatus('Item history loaded (' + items.length + ' item(s)). Review and click Send.', false);
                        }
                        else {
                            showSideStatus('Item history loaded as untrusted context (' + items.length + ' item(s)).', false);
                        }

                        historyButton.disabled = false;
                    })
                    .catch(function (error) {
                        showSideStatus(error.message, true);
                        historyButton.disabled = false;
                    });
            });
        }

        function invalidatePendingMonitoringContext(removeDisplayEntry) {
            pendingUntrustedMonitoringContext = '';
            pendingUntrustedMonitoringEventId = '';
            monitoringContextGeneration += 1;

            if (removeDisplayEntry) {
                var filtered = history.filter(function (item) {
                    return !item.monitoring_context_eventid;
                });
                if (filtered.length !== history.length) {
                    history = normalizeHistory(filtered, historyLimit);
                    persistHistory();
                    renderHistory();
                }
            }
        }

        function setBusy(isBusy) {
            sendButton.disabled = isBusy;
            sendButton.textContent = isBusy ? 'Sending…' : 'Send';
            showTypingIndicator(isBusy);
        }

        // Add (or remove) an "AI is thinking…" bubble at the bottom of the
        // transcript so the operator has feedback while a request is in flight.
        // The agentic chat loop can take 10–15s for multi-step tool sequences,
        // so a passive disabled button is not enough.
        function showTypingIndicator(visible) {
            if (!transcript) {
                return;
            }

            var existing = transcript.querySelector('.ai-msg-typing');

            if (!visible) {
                if (existing) {
                    existing.remove();
                }
                return;
            }

            if (existing) {
                return;
            }

            var item = document.createElement('div');
            item.className = 'ai-msg ai-msg-assistant ai-msg-typing';
            item.setAttribute('aria-live', 'polite');
            item.setAttribute('aria-label', 'AI is thinking');

            var title = document.createElement('div');
            title.className = 'ai-msg-title';
            title.textContent = 'AI';
            item.appendChild(title);

            var body = document.createElement('div');
            body.className = 'ai-msg-body ai-typing-body';

            var dots = document.createElement('span');
            dots.className = 'ai-typing-dots';
            for (var i = 0; i < 3; i++) {
                var dot = document.createElement('span');
                dot.className = 'ai-typing-dot';
                dots.appendChild(dot);
            }
            body.appendChild(dots);

            var hint = document.createElement('span');
            hint.className = 'ai-typing-hint';
            hint.textContent = 'Thinking…';
            body.appendChild(hint);

            item.appendChild(body);
            transcript.appendChild(item);
            transcript.scrollTop = transcript.scrollHeight;
        }

        // Render a restricted subset of Markdown into `target` using only DOM APIs
        // (no innerHTML), so the AI's output is shown as formatted text without
        // exposing the page to script injection.
        function renderMarkdown(target, text) {
            text = String(text || '').replace(/\r\n?/g, '\n');

            // Pull fenced code blocks out first; restore them as <pre><code>.
            var codeBlocks = [];
            text = text.replace(/```([a-zA-Z0-9_-]*)\n([\s\S]*?)```/g, function (_, lang, code) {
                codeBlocks.push({ lang: lang || '', code: code.replace(/\n$/, '') });
                return 'CODEBLOCK' + (codeBlocks.length - 1) + '';
            });

            var blocks = text.split(/\n{2,}/);

            blocks.forEach(function (block) {
                block = block.replace(/^\n+|\n+$/g, '');
                if (block === '') {
                    return;
                }

                // Restored code block placeholder.
                var codeMatch = /^CODEBLOCK(\d+)$/.exec(block);
                if (codeMatch) {
                    var entry = codeBlocks[parseInt(codeMatch[1], 10)];
                    var pre = document.createElement('pre');
                    pre.className = 'ai-md-code-block';
                    var code = document.createElement('code');
                    if (entry.lang) {
                        code.className = 'language-' + entry.lang;
                    }
                    code.textContent = entry.code;
                    pre.appendChild(code);
                    target.appendChild(pre);
                    return;
                }

                // Heading.
                var headingMatch = /^(#{1,6})\s+(.+)$/.exec(block);
                if (headingMatch) {
                    var level = headingMatch[1].length;
                    var h = document.createElement('h' + Math.min(level, 6));
                    h.className = 'ai-md-h';
                    renderInline(h, headingMatch[2]);
                    target.appendChild(h);
                    return;
                }

                // Download button marker: [[ai-download fname="x.svg" url="zabbix.php?..." fmt="SVG" size_kb="12" expires_min="60"]]
                var downloadMatch = /^\s*\[\[ai-download\s+(.+?)\]\]\s*$/.exec(block);
                if (downloadMatch) {
                    if (renderDownloadButton(target, downloadMatch[1])) {
                        return;
                    }
                }

                // Navigation link button marker: [[ai-link-button url="https://..." label="Open the CPU graph in Zabbix"]]
                var linkMatch = /^\s*\[\[ai-link-button\s+(.+?)\]\]\s*$/.exec(block);
                if (linkMatch) {
                    if (renderLinkButton(target, linkMatch[1])) {
                        return;
                    }
                }

                var lines = block.split('\n');

                // Markdown table: first line is the header row, second line is the separator
                // ("| --- | :---: |"), remaining lines are body rows. Pipes are required at
                // least once per row; we are lenient about leading/trailing pipes.
                if (lines.length >= 2 && /^\s*\|?.+\|.+\|?\s*$/.test(lines[0])
                    && /^\s*\|?[\s:|-]+\|[\s:|-]+\|?\s*$/.test(lines[1])
                    && /-/.test(lines[1])) {

                    var splitRow = function (row) {
                        return row.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(function (c) { return c.trim(); });
                    };

                    var headerCells = splitRow(lines[0]);
                    var alignSpec = splitRow(lines[1]).map(function (cell) {
                        var left = cell.charAt(0) === ':';
                        var right = cell.charAt(cell.length - 1) === ':';
                        if (left && right) return 'center';
                        if (right)         return 'right';
                        if (left)          return 'left';
                        return '';
                    });

                    var table = document.createElement('table');
                    table.className = 'ai-md-table';

                    var thead = document.createElement('thead');
                    var headRow = document.createElement('tr');
                    headerCells.forEach(function (cell, idx) {
                        var th = document.createElement('th');
                        if (alignSpec[idx]) th.style.textAlign = alignSpec[idx];
                        renderInline(th, cell);
                        headRow.appendChild(th);
                    });
                    thead.appendChild(headRow);
                    table.appendChild(thead);

                    var tbody = document.createElement('tbody');
                    for (var i = 2; i < lines.length; i++) {
                        var bodyRow = lines[i];
                        if (!/\|/.test(bodyRow)) continue;
                        var cells = splitRow(bodyRow);
                        var tr = document.createElement('tr');
                        cells.forEach(function (cell, idx) {
                            var td = document.createElement('td');
                            if (alignSpec[idx]) td.style.textAlign = alignSpec[idx];
                            renderInline(td, cell);
                            tr.appendChild(td);
                        });
                        tbody.appendChild(tr);
                    }
                    table.appendChild(tbody);

                    var wrap = document.createElement('div');
                    wrap.className = 'ai-md-table-wrap';
                    wrap.appendChild(table);
                    target.appendChild(wrap);
                    return;
                }

                // Bullet list.
                var isList = lines.every(function (l) { return /^\s*[-*]\s+/.test(l); });
                if (isList) {
                    var ul = document.createElement('ul');
                    ul.className = 'ai-md-list';
                    lines.forEach(function (l) {
                        var li = document.createElement('li');
                        renderInline(li, l.replace(/^\s*[-*]\s+/, ''));
                        ul.appendChild(li);
                    });
                    target.appendChild(ul);
                    return;
                }

                // Paragraph. Preserve single newlines as <br>.
                var p = document.createElement('p');
                p.className = 'ai-md-p';
                var paraLines = block.split('\n');
                paraLines.forEach(function (line, idx) {
                    renderInline(p, line);
                    if (idx < paraLines.length - 1) {
                        p.appendChild(document.createElement('br'));
                    }
                });
                target.appendChild(p);
            });
        }

        // Inline markdown handler: image, link, bold, inline code, plain text.
        // Everything else (escaped via textContent) is rendered literally.
        function renderInline(target, line) {
            // Token order matters: image (![…](…)) must run before link ([…](…)).
            // We do a single left-to-right scan, advancing past consumed spans.
            var re = /(!\[([^\]]*)\]\(([^\s)]+)\))|(\[([^\]]+)\]\(([^\s)]+)\))|(\*\*([^*]+)\*\*)|(`([^`]+)`)/g;
            var lastIndex = 0;
            var match;

            while ((match = re.exec(line)) !== null) {
                if (match.index > lastIndex) {
                    target.appendChild(document.createTextNode(line.slice(lastIndex, match.index)));
                }

                if (match[1]) {
                    // Image.
                    var src = safeImageSrc(match[3]);
                    if (src) {
                        var img = document.createElement('img');
                        img.className = 'ai-md-img';
                        img.src = src;
                        img.alt = match[2] || '';
                        img.loading = 'lazy';
                        target.appendChild(img);
                    }
                    else {
                        target.appendChild(document.createTextNode(match[0]));
                    }
                }
                else if (match[4]) {
                    // Link.
                    var href = safeLinkHref(match[6]);
                    if (href) {
                        var a = document.createElement('a');
                        a.href = href;
                        a.textContent = match[5];
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.className = 'ai-md-link';
                        target.appendChild(a);
                    }
                    else {
                        target.appendChild(document.createTextNode(match[0]));
                    }
                }
                else if (match[7]) {
                    // Bold.
                    var strong = document.createElement('strong');
                    strong.textContent = match[8];
                    target.appendChild(strong);
                }
                else if (match[9]) {
                    // Inline code.
                    var code = document.createElement('code');
                    code.className = 'ai-md-code-inline';
                    code.textContent = match[10];
                    target.appendChild(code);
                }

                lastIndex = re.lastIndex;
            }

            if (lastIndex < line.length) {
                target.appendChild(document.createTextNode(line.slice(lastIndex)));
            }
        }

        // Same-origin navigation links are restricted to a small allowlist of
        // read-only Zabbix view actions — the ones the assistant is instructed to
        // link to. This stops the model (or injected content) from rendering a
        // clickable link to an arbitrary Zabbix action. Keep this in sync with the
        // navigation links advertised in PromptBuilder.
        var SAFE_ZABBIX_ACTIONS = {
            'latest.view': 1, 'problem.view': 1, 'problem.list': 1,
            'host.view': 1, 'host.edit': 1, 'host.list': 1,
            'hostgroup.list': 1, 'maintenance.list': 1,
            'dashboard.view': 1, 'dashboard.list': 1,
            'service.list': 1, 'item.list': 1, 'trigger.list': 1,
            'charts.view': 1, 'web.view': 1
        };

        // Allow standard web URLs and a curated set of same-origin view links
        // only. Returns the safe href or null if the URL is rejected (the caller
        // then renders the text without a link). javascript:/data:/file: and bare
        // '#' anchors are all rejected.
        function safeLinkHref(url) {
            url = String(url || '').trim();
            if (url === '') {
                return null;
            }
            if (/^https?:\/\//i.test(url)) {
                return url;
            }
            if (/^zabbix\.php(\?|$)/i.test(url)) {
                var m = /[?&]action=([a-z0-9._-]+)/i.exec(url);
                var action = m ? m[1].toLowerCase() : '';
                if (action !== '' && SAFE_ZABBIX_ACTIONS[action] === 1) {
                    return url;
                }
                return null;
            }
            return null;
        }

        // Images are restricted to the AI module's own report download endpoint
        // (same-origin token-bound) to prevent the model from emitting trackers
        // or exfiltrating data via image src.
        function safeImageSrc(url) {
            url = String(url || '').trim();
            if (/^zabbix\.php\?action=ai\.report\.download(&|$)/i.test(url)) {
                return url;
            }
            return null;
        }

        // Download links are restricted to the AI module's own token-bound report
        // download endpoint (same rule as safeImageSrc). Used by the download
        // button so report/graph downloads are not blocked by the navigation
        // allowlist in safeLinkHref.
        function safeDownloadHref(url) {
            url = String(url || '').trim();
            if (/^zabbix\.php\?action=ai\.report\.download(&|$)/i.test(url)) {
                return url;
            }
            return null;
        }

        // Parse the attribute payload of an [[ai-... attr1="v1" attr2="v2"]]
        // marker into an object of key -> string. Values are quoted with " and
        // may escape \\ and \" via backslash. Used by both the download-button
        // and the link-button markers.
        function parseMarkerAttrs(attrsString) {
            var out = {};
            var re = /([a-z_][a-z0-9_]*)\s*=\s*"((?:[^"\\]|\\.)*)"/gi;
            var match;
            while ((match = re.exec(attrsString)) !== null) {
                out[match[1]] = match[2].replace(/\\(["\\])/g, '$1');
            }
            return out;
        }

        // Render a styled download button into `target` from the attribute
        // payload of an [[ai-download ...]] marker. Returns true on success;
        // false if the URL is rejected (in which case the marker is left as
        // plain text by the caller).
        function renderDownloadButton(target, attrsString) {
            var attrs = parseMarkerAttrs(attrsString);
            // The download button targets the AI module's own token-bound report
            // endpoint (ai.report.download), which is NOT one of the navigation
            // view actions on the safeLinkHref allowlist. Validate against that
            // exact endpoint (same rule as safeImageSrc) instead.
            var href = safeDownloadHref(attrs.url || '');
            if (!href) {
                return false;
            }

            var filename = String(attrs.fname || 'report');
            var fmt = String(attrs.fmt || '').toUpperCase();
            var sizeKb = parseInt(attrs.size_kb, 10);
            var expiresMin = parseInt(attrs.expires_min, 10);

            var a = document.createElement('a');
            a.className = 'ai-download-btn';
            a.href = href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.setAttribute('download', filename);
            a.setAttribute('aria-label', 'Download ' + filename);

            // Inline SVG download icon (created via createElementNS so we never
            // touch innerHTML on caller-controlled data).
            var SVG_NS = 'http://www.w3.org/2000/svg';
            var icon = document.createElementNS(SVG_NS, 'svg');
            icon.setAttribute('class', 'ai-download-icon');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('width', '20');
            icon.setAttribute('height', '20');
            icon.setAttribute('aria-hidden', 'true');
            var arrow = document.createElementNS(SVG_NS, 'path');
            arrow.setAttribute('d', 'M12 3v12m0 0l-5-5m5 5l5-5M5 21h14');
            arrow.setAttribute('fill', 'none');
            arrow.setAttribute('stroke', 'currentColor');
            arrow.setAttribute('stroke-width', '2');
            arrow.setAttribute('stroke-linecap', 'round');
            arrow.setAttribute('stroke-linejoin', 'round');
            icon.appendChild(arrow);
            a.appendChild(icon);

            var info = document.createElement('span');
            info.className = 'ai-download-info';

            var titleEl = document.createElement('span');
            titleEl.className = 'ai-download-title';
            titleEl.textContent = 'Download ' + filename;
            info.appendChild(titleEl);

            var metaParts = [];
            if (fmt) {
                metaParts.push(fmt);
            }
            if (!isNaN(sizeKb) && sizeKb > 0) {
                metaParts.push(sizeKb + ' KB');
            }
            if (!isNaN(expiresMin) && expiresMin > 0) {
                metaParts.push('expires in ~' + expiresMin + ' min');
            }

            if (metaParts.length > 0) {
                var meta = document.createElement('span');
                meta.className = 'ai-download-meta';
                meta.textContent = metaParts.join(' · ');
                info.appendChild(meta);
            }

            a.appendChild(info);
            target.appendChild(a);
            return true;
        }

        // Render a navigation link button (secondary style) from the attribute
        // payload of an [[ai-link-button url="..." label="..." icon="..."]]
        // marker. Used for primary call-to-action navigation links, e.g.
        // "Open the CPU graph in Zabbix". Returns true on success.
        function renderLinkButton(target, attrsString) {
            var attrs = parseMarkerAttrs(attrsString);
            var href = safeLinkHref(attrs.url || '');
            if (!href) {
                return false;
            }

            var label = String(attrs.label || attrs.url || 'Open link');
            var icon = String(attrs.icon || 'external').toLowerCase();

            var a = document.createElement('a');
            a.className = 'ai-link-btn';
            a.href = href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.setAttribute('aria-label', label);

            var SVG_NS = 'http://www.w3.org/2000/svg';
            var iconSvg = document.createElementNS(SVG_NS, 'svg');
            iconSvg.setAttribute('class', 'ai-link-btn-icon');
            iconSvg.setAttribute('viewBox', '0 0 24 24');
            iconSvg.setAttribute('width', '16');
            iconSvg.setAttribute('height', '16');
            iconSvg.setAttribute('aria-hidden', 'true');

            // Icon paths: 'external' (default), 'graph', 'open'.
            // Each is a single <path d="..."> using currentColor.
            var pathD = 'M14 4h6m0 0v6m0-6L10 14M5 7v12h12';
            if (icon === 'graph') {
                pathD = 'M4 19V5m0 14h16M7 15l3-4 3 3 4-6';
            }
            else if (icon === 'open' || icon === 'arrow') {
                pathD = 'M5 12h14m0 0l-5-5m5 5l-5 5';
            }

            var p = document.createElementNS(SVG_NS, 'path');
            p.setAttribute('d', pathD);
            p.setAttribute('fill', 'none');
            p.setAttribute('stroke', 'currentColor');
            p.setAttribute('stroke-width', '2');
            p.setAttribute('stroke-linecap', 'round');
            p.setAttribute('stroke-linejoin', 'round');
            iconSvg.appendChild(p);
            a.appendChild(iconSvg);

            var span = document.createElement('span');
            span.className = 'ai-link-btn-label';
            span.textContent = label;
            a.appendChild(span);

            target.appendChild(a);
            return true;
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
                var content = message.content || '';
                var isAction = message.role === 'assistant' && content.indexOf('[Zabbix Action:') === 0;

                // Strip the redundant "[Zabbix Action: tool]" prefix; the title already shows it.
                if (isAction) {
                    content = content.replace(/^\[Zabbix Action:[^\]]*\]\s*\n*/, '');
                }

                var item = document.createElement('div');
                item.className = 'ai-msg ai-msg-' + message.role + (isAction ? ' ai-msg-action' : '');

                var title = document.createElement('div');
                title.className = 'ai-msg-title';
                title.textContent = isAction ? 'AI (Zabbix Action)' : (message.role === 'assistant' ? 'AI' : 'You');

                var body;
                if (message.role === 'assistant') {
                    body = document.createElement('div');
                    body.className = 'ai-msg-body ai-md';
                    renderMarkdown(body, content);
                }
                else {
                    body = document.createElement('pre');
                    body.className = 'ai-msg-body';
                    body.textContent = content;
                }

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
                provider_user_override: providerUserOverride,
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
                if (history[i].role === 'assistant' && !history[i].non_forwardable
                    && (history[i].content || '').trim() !== '') {
                    return history[i].content;
                }
            }

            return '';
        }

        function updatePostButtonState() {
            if (!postButton) {
                return;
            }

            postButton.disabled = !hasZabbixApi || !canPostEventComment || !(eventidField.value || '').trim() || !getLastAssistantMessage();
        }

        /**
         * Show Confirm / Cancel buttons for a pending write or sensitive read.
         */
        function showActionConfirmButtons() {
            var btns = document.createElement('div');
            btns.className = 'ai-action-confirm-buttons';
            btns.id = 'ai-action-confirm-bar';

            var confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'btn ai-confirm-btn';
            var highImpact = pendingAction && pendingAction.confirmation_level === 'high_impact';
            var highImpactArmed = false;
            confirmBtn.textContent = highImpact ? 'Review high-impact action' : 'Confirm';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn-alt ai-cancel-btn';
            cancelBtn.textContent = 'Cancel';

            confirmBtn.addEventListener('click', function () {
                if (highImpact && !highImpactArmed) {
                    highImpactArmed = true;
                    confirmBtn.textContent = 'Execute high-impact action';
                    showSideStatus('High-impact action reviewed. Click Execute to confirm it explicitly.', true);
                    return;
                }
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
         * Execute a confirmed write or sensitive-read action via ChatExecute.
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
            params.set('confirmation_hash', pendingAction.confirmation_hash || '');
            params.set('high_impact_confirmed', pendingAction.confirmation_level === 'high_impact' ? '1' : '0');
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
                    var storedReply = prefix + (response.reply || '');
                    if (response.sensitive_reply) {
                        window.prompt(
                            'Sensitive one-time output — copy it now. It will not be stored in chat history:',
                            response.reply || ''
                        );
                        storedReply = prefix + '[Sensitive one-time output was shown separately and was not stored.]';
                    }
                    history = normalizeHistory(history.concat([{ role: 'assistant', content: storedReply }]), historyLimit);
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

    /**
     * Import state transferred from the problem drawer via localStorage.
     * The drawer writes a temporary entry; we move it into sessionStorage
     * and delete the localStorage key so it's single-use.
     */
    function importTransferredState() {
        var TRANSFER_KEY = 'zbx_ai_chat_transfer';

        try {
            var raw = localStorage.getItem(TRANSFER_KEY);
            if (!raw) return;

            var data = JSON.parse(raw);

            // Only import if the transfer is recent (< 30 seconds).
            if (!data || !data.timestamp || (Date.now() - data.timestamp) > 30000) {
                localStorage.removeItem(TRANSFER_KEY);
                return;
            }

            // Write to sessionStorage using the chat page's keys.
            if (data.history && Array.isArray(data.history) && data.history.length > 0) {
                sessionStorage.setItem('zbx_ai_chat_history_v1', JSON.stringify(data.history));
            }

            if (data.sessionId) {
                sessionStorage.setItem('zbx_ai_chat_session_id_v1', data.sessionId);
            }

            if (data.context && typeof data.context === 'object') {
                sessionStorage.setItem('zbx_ai_chat_context_v1', JSON.stringify(data.context));
            }

            // Clean up — single use.
            localStorage.removeItem(TRANSFER_KEY);
        }
        catch (e) {
            // Ignore errors — graceful fallback to empty chat.
            try { localStorage.removeItem(TRANSFER_KEY); } catch (e2) {}
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

