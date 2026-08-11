(function () {
    'use strict';

    var CSRF_FIELD = '_csrf_token';
    var SEVERITY_LABELS = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

    // CSRF tokens and provider info — populated from ai.problem.context response.
    var csrfTokens = {};
    var defaultProviderId = '';

    function zbxUrl(action) {
        var base = location.pathname;
        return base + '?action=' + encodeURIComponent(action);
    }

    // ── Drawer state ──
    var activeDrawer = null;
    var activeEventId = null;
    var activeDrawerGeneration = 0;
    var contextAbortController = null;
    var historyAbortController = null;

    function isCurrentDrawer(drawer, eventid, generation) {
        return activeDrawer === drawer
            && activeEventId === eventid
            && drawer
            && drawer.dataset.eventid === String(eventid)
            && drawer.dataset.generation === String(generation);
    }

    // Per-event in-memory chat state.
    var eventChats = {};

    function getEventChat(eventid) {
        if (!eventChats[eventid]) {
            eventChats[eventid] = {
                history: [],
                sessionId: 'problem_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8),
                context: null,
                pendingAction: null
            };
        }
        return eventChats[eventid];
    }

    // ── Button injection ──
    function injectButtons() {
        var rows = document.querySelectorAll('table.list-table tbody tr');

        rows.forEach(function (row) {
            if (row.querySelector('.ai-problem-btn')) {
                return;
            }

            var eventid = extractEventId(row);
            if (!eventid) return;

            var nameCell = findProblemNameCell(row);
            if (!nameCell) return;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ai-problem-btn';
            btn.title = 'Ask AI about this problem';
            btn.textContent = 'AI';
            btn.dataset.eventid = eventid;

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                e.preventDefault();
                openDrawer(eventid);
            });

            nameCell.appendChild(btn);
        });
    }

    function extractEventId(row) {
        var links = row.querySelectorAll('a[href]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            var m = href.match(/eventid=(\d+)/);
            if (m) return m[1];
        }

        var checkbox = row.querySelector('input[type="checkbox"][name*="eventid"]');
        if (checkbox) return checkbox.value;

        if (row.dataset.eventid) return row.dataset.eventid;

        return null;
    }

    function findProblemNameCell(row) {
        var cells = row.querySelectorAll('td');

        for (var i = 0; i < cells.length; i++) {
            var cell = cells[i];
            var link = cell.querySelector('a');
            if (link) {
                var text = (link.textContent || '').trim();
                if (text.length > 3 && !/^\d+$/.test(text)) {
                    return cell;
                }
            }
        }

        if (cells.length > 2) return cells[cells.length - 2];

        return cells[cells.length - 1] || null;
    }

    // ── Drawer ──
    function openDrawer(eventid) {
        if (activeDrawer && activeEventId === eventid) {
            closeDrawer();
            return;
        }

        if (activeDrawer) {
            closeDrawer();
        }

        activeEventId = eventid;
        var chat = getEventChat(eventid);
        chat.context = null;
        chat.pendingAction = null;
        var drawerGeneration = ++activeDrawerGeneration;

        var chatUrl = zbxUrl('ai.chat') + '&eventid=' + encodeURIComponent(eventid);

        var drawer = document.createElement('div');
        drawer.className = 'ai-problem-drawer';
        drawer.id = 'ai-problem-drawer';

        drawer.innerHTML =
            '<div class="ai-drawer-header">' +
                '<div class="ai-drawer-title">' +
                    '<span class="ai-drawer-icon">AI</span>' +
                    '<span class="ai-drawer-label">Problem Analysis</span>' +
                    '<span class="ai-drawer-eventid">Event #' + escapeHtml(eventid) + '</span>' +
                '</div>' +
                '<div class="ai-drawer-header-actions">' +
                    '<a class="btn-alt ai-drawer-open-chat" href="' + escapeHtml(chatUrl) + '" target="_blank" title="Open full AI chat for this problem">Full chat</a>' +
                    '<button type="button" class="ai-drawer-close" title="Close">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div class="ai-drawer-context" id="ai-drawer-context">' +
                '<div class="ai-drawer-loading">Loading problem context...</div>' +
            '</div>' +
            '<div class="ai-drawer-transcript" id="ai-drawer-transcript"></div>' +
            '<div class="ai-drawer-status ai-hidden" id="ai-drawer-status"></div>' +
            '<form class="ai-drawer-compose" id="ai-drawer-compose">' +
                '<textarea class="ai-drawer-input" id="ai-drawer-message" rows="3" placeholder="Ask about this problem..."></textarea>' +
                '<div class="ai-drawer-actions">' +
                    '<button type="submit" class="btn ai-drawer-send" id="ai-drawer-send" disabled>Send</button>' +
                    '<button type="button" class="btn-alt ai-drawer-history-btn" id="ai-drawer-history-btn" disabled title="Fetch item history/trends and send to AI for deeper analysis">Include history</button>' +
                    '<button type="button" class="btn-alt ai-drawer-post" id="ai-drawer-post" disabled title="Post last AI answer as problem update comment">Post to event</button>' +
                '</div>' +
            '</form>';

        document.body.appendChild(drawer);
        drawer.dataset.eventid = String(eventid);
        drawer.dataset.generation = String(drawerGeneration);
        activeDrawer = drawer;

        drawer.querySelector('.ai-drawer-close').addEventListener('click', closeDrawer);

        // When "Full chat" is clicked, transfer drawer state to sessionStorage
        // so the chat page picks up the conversation history.
        var fullChatLink = drawer.querySelector('.ai-drawer-open-chat');
        if (fullChatLink) {
            fullChatLink.addEventListener('click', function () {
                transferStateToSessionStorage(eventid);
            });
        }

        var form = drawer.querySelector('#ai-drawer-compose');
        var msgField = drawer.querySelector('#ai-drawer-message');
        var sendBtn = drawer.querySelector('#ai-drawer-send');
        var postBtn = drawer.querySelector('#ai-drawer-post');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            sendMessage(eventid, drawer, drawerGeneration, msgField, sendBtn);
        });

        postBtn.addEventListener('click', function () {
            postLastAnswer(eventid, drawer, drawerGeneration);
        });

        var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
        historyBtn.addEventListener('click', function () {
            fetchAndSendItemHistory(eventid, drawer, drawerGeneration);
        });

        renderTranscript(eventid, drawer);
        loadContext(eventid, drawer, drawerGeneration);
    }

    function closeDrawer() {
        activeDrawerGeneration += 1;
        if (contextAbortController) {
            contextAbortController.abort();
            contextAbortController = null;
        }
        if (historyAbortController) {
            historyAbortController.abort();
            historyAbortController = null;
        }
        if (activeDrawer) {
            activeDrawer.remove();
            activeDrawer = null;
            activeEventId = null;
        }
    }

    function loadContext(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var contextEl = drawer.querySelector('#ai-drawer-context');
        if (!contextEl) return;

        if (contextAbortController) contextAbortController.abort();
        contextAbortController = typeof AbortController !== 'undefined'
            ? new AbortController()
            : null;
        var requestController = contextAbortController;
        var options = { method: 'GET', credentials: 'same-origin' };
        if (requestController) options.signal = requestController.signal;
        function enableMatchingControls() {
            if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
            var sendBtn = drawer.querySelector('#ai-drawer-send');
            var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
            if (sendBtn) sendBtn.disabled = false;
            if (historyBtn) historyBtn.disabled = false;
            updatePostBtn(eventid, drawer);
        }

        fetch(zbxUrl('ai.problem.context') + '&eventid=' + encodeURIComponent(eventid), options)
            .then(handleJsonResponse)
            .then(function (response) {
                if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
                if (!response || !response.ok) {
                    var errMsg = (response && typeof response.error === 'string')
                        ? response.error
                        : 'Failed to load context.';
                    contextEl.innerHTML = '<div class="ai-drawer-context-error">' + escapeHtml(errMsg) + '</div>';
                    enableMatchingControls();
                    return;
                }

                // Store CSRF tokens from the response.
                if (response.csrf) {
                    if (response.csrf.field_name) {
                        CSRF_FIELD = response.csrf.field_name;
                    }
                    if (response.csrf.chat_send) {
                        csrfTokens['ai.chat.send'] = response.csrf.chat_send;
                    }
                    if (response.csrf.event_comment) {
                        csrfTokens['ai.event.comment'] = response.csrf.event_comment;
                    }
                    if (response.csrf.chat_execute) {
                        csrfTokens['ai.chat.execute'] = response.csrf.chat_execute;
                    }
                }

                if (response.default_provider_id) {
                    defaultProviderId = response.default_provider_id;
                }

                var ctx = response.context;

                if (!ctx || typeof ctx !== 'object') {
                    contextEl.innerHTML = '<div class="ai-drawer-context-error">Invalid context data received.</div>';
                    enableMatchingControls();
                    return;
                }

                var chat = getEventChat(eventid);
                chat.context = ctx;

                // Update "Full chat" link with hostname and problem summary.
                var chatLink = drawer.querySelector('.ai-drawer-open-chat');
                if (chatLink && ctx.hostname) {
                    chatLink.href = zbxUrl('ai.chat') +
                        '&eventid=' + encodeURIComponent(eventid) +
                        '&hostname=' + encodeURIComponent(ctx.hostname || '') +
                        '&problem_summary=' + encodeURIComponent(ctx.problem_summary || '');
                }

                var html = '';

                if (ctx.trigger_name) {
                    html += '<div class="ai-ctx-row"><strong>Trigger:</strong> ' + escapeHtml(String(ctx.trigger_name)) + '</div>';
                }
                if (ctx.hostname) {
                    html += '<div class="ai-ctx-row"><strong>Host:</strong> ' + escapeHtml(String(ctx.hostname)) + '</div>';
                }
                if (ctx.severity !== undefined) {
                    html += '<div class="ai-ctx-row"><strong>Severity:</strong> ' + escapeHtml(SEVERITY_LABELS[parseInt(ctx.severity, 10)] || 'Unknown') + '</div>';
                }
                if (ctx.template_names && ctx.template_names.length) {
                    var tplNames = ctx.template_names.filter(function (n) { return n; });
                    if (tplNames.length) {
                        html += '<div class="ai-ctx-row"><strong>Template:</strong> ' + escapeHtml(tplNames.join(', ')) + '</div>';
                    }
                }

                contextEl.innerHTML = html || '<div class="ai-drawer-context-empty">No additional context available.</div>';
                enableMatchingControls();

                // Auto-send starter message if this is a fresh chat and auto_analyze is enabled.
                var autoAnalyze = !response.settings || response.settings.auto_analyze !== false;
                if (chat.history.length === 0 && autoAnalyze) {
                    autoStartAnalysis(eventid, drawer, drawerGeneration);
                }
            })
            .catch(function (err) {
                if (!isCurrentDrawer(drawer, eventid, drawerGeneration)
                    || (err && err.name === 'AbortError')) return;
                var msg = (err && typeof err.message === 'string') ? err.message : 'Failed to load context.';
                contextEl.innerHTML = '<div class="ai-drawer-context-error">' + escapeHtml(msg) + '</div>';
                enableMatchingControls();
            })
            .finally(function () {
                if (requestController === contextAbortController) {
                    contextAbortController = null;
                }
            });
    }

    function autoStartAnalysis(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var chat = getEventChat(eventid);
        var starterMessage = 'Analyze this active Zabbix problem. ' +
            'Summarize the likely cause, suggest safe diagnostic checks, ' +
            'and use Zabbix tools when helpful.';

        chat.history.push({ role: 'user', content: starterMessage });
        renderTranscript(eventid, drawer);

        var sendBtn = drawer.querySelector('#ai-drawer-send');
        var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
        if (sendBtn) sendBtn.disabled = true;
        if (historyBtn) historyBtn.disabled = true;
        showDrawerStatus('Analyzing problem...', false, drawer);

        doSend(eventid, starterMessage, drawer, drawerGeneration, function () {
            if (sendBtn) sendBtn.disabled = false;
            if (historyBtn) historyBtn.disabled = false;
        });
    }

    function sendMessage(eventid, drawer, drawerGeneration, msgField, sendBtn) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration) || sendBtn.disabled) return;
        var message = (msgField.value || '').trim();
        if (!message) return;

        var chat = getEventChat(eventid);
        chat.history.push({ role: 'user', content: message });
        renderTranscript(eventid, drawer);
        msgField.value = '';
        sendBtn.disabled = true;
        var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
        if (historyBtn) historyBtn.disabled = true;
        showInlineTypingIndicator('ai-drawer-transcript', true, drawer);
        showDrawerStatus('Sending...', false, drawer);

        doSend(eventid, message, drawer, drawerGeneration, function () {
            sendBtn.disabled = false;
            if (historyBtn) historyBtn.disabled = false;
            showInlineTypingIndicator('ai-drawer-transcript', false, drawer);
            msgField.focus();
        });
    }

    function doSend(eventid, message, drawer, drawerGeneration, onDone, untrustedMonitoringContext) {
        var chat = getEventChat(eventid);
        function current() {
            return isCurrentDrawer(drawer, eventid, drawerGeneration);
        }

        var requestHistory = chat.history.slice(0, -1).filter(function (m) {
            return (m.role === 'user' || m.role === 'assistant') && !m.non_forwardable;
        });

        if (requestHistory.length > 12) {
            requestHistory = requestHistory.slice(requestHistory.length - 12);
        }

        var csrf = csrfTokens['ai.chat.send'] || '';

        if (!csrf) {
            chat.history.push({ role: 'assistant', content: '[Error] CSRF token not available. Try closing and reopening the AI drawer.' });
            if (current()) {
                renderTranscript(eventid, drawer);
                showDrawerStatus('CSRF token missing. Reopen the drawer to retry.', true, drawer);
                if (onDone) onDone();
            }
            return;
        }

        var params = new URLSearchParams();
        params.set('message', message);
        params.set('history_json', JSON.stringify(requestHistory));
        params.set('eventid', eventid);
        params.set('hostname', (chat.context && chat.context.hostname) || '');
        params.set('problem_summary', (chat.context && chat.context.problem_summary) || '');
        params.set('extra_context', '');
        params.set('untrusted_monitoring_context', untrustedMonitoringContext || '');
        params.set('chat_session_id', chat.sessionId);
        params.set('provider_id', defaultProviderId);
        params.set('provider_user_override', '0');
        // Scopes the optional read auto-approval to this drawer. It grants
        // nothing on its own: the server also requires the administrator
        // setting and a problem context it resolved itself.
        params.set('surface', 'problem_drawer');
        params.set(CSRF_FIELD, csrf);

        fetch(zbxUrl('ai.chat.send'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        })
            .then(handleJsonResponse)
            .then(function (response) {
                if (!response || !response.ok) {
                    throw new Error((response && typeof response.error === 'string') ? response.error : 'Chat request failed.');
                }

                if (response.action_pending) {
                    if (!current()) {
                        chat.pendingAction = null;
                        return;
                    }
                    chat.pendingAction = {
                        action_id: response.pending_action_id || '',
                        confirmation_hash: response.pending_confirmation_hash || '',
                        confirmation_level: response.confirmation_level || 'standard',
                        tool: response.pending_tool || ''
                    };
                    chat.history.push({
                        role: 'assistant',
                        content: response.reply || '',
                        non_forwardable: true
                    });
                    renderTranscript(eventid, drawer);
                    showActionConfirm(eventid, drawer, drawerGeneration);
                    showDrawerStatus('Operation requires confirmation.', false, drawer);
                    updatePostBtn(eventid, drawer);
                    return;
                }

                chat.pendingAction = null;

                var prefix = '';
                if (response.action_executed) {
                    prefix = '[Zabbix Action: ' + (response.action_tool || 'executed') + ']\n\n';
                }

                chat.history.push({ role: 'assistant', content: prefix + (response.reply || '') });
                if (current()) {
                    renderTranscript(eventid, drawer);
                    showDrawerStatus('Reply received from ' + (response.provider_name || 'AI') + '.', false, drawer);
                    updatePostBtn(eventid, drawer);
                }
            })
            .catch(function (err) {
                var msg = (err && typeof err.message === 'string') ? err.message : String(err);
                chat.history.push({ role: 'assistant', content: '[Error] ' + msg });
                if (current()) {
                    renderTranscript(eventid, drawer);
                    showDrawerStatus(msg, true, drawer);
                }
            })
            .finally(function () {
                if (current() && onDone) onDone();
            });
    }

    function showActionConfirm(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var transcript = drawer.querySelector('#ai-drawer-transcript');
        if (!transcript) return;
        var oldBar = transcript.querySelector('#ai-drawer-confirm-bar');
        if (oldBar) oldBar.remove();

        var bar = document.createElement('div');
        bar.className = 'ai-action-confirm-buttons';
        bar.id = 'ai-drawer-confirm-bar';

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'btn ai-confirm-btn';
        var chat = getEventChat(eventid);
        var highImpact = chat.pendingAction && chat.pendingAction.confirmation_level === 'high_impact';
        var highImpactArmed = false;
        confirmBtn.textContent = highImpact ? 'Review high-impact action' : 'Confirm';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn-alt ai-cancel-btn';
        cancelBtn.textContent = 'Cancel';

        confirmBtn.addEventListener('click', function () {
            if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
            if (highImpact && !highImpactArmed) {
                highImpactArmed = true;
                confirmBtn.textContent = 'Execute high-impact action';
                showDrawerStatus('High-impact action reviewed. Click Execute to confirm it explicitly.', true, drawer);
                return;
            }
            bar.remove();
            executeConfirmed(eventid, drawer, drawerGeneration);
        });

        cancelBtn.addEventListener('click', function () {
            if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
            bar.remove();
            var chat = getEventChat(eventid);
            chat.pendingAction = null;
            chat.history.push({ role: 'assistant', content: 'Action cancelled by user.' });
            renderTranscript(eventid, drawer);
            showDrawerStatus('Action cancelled.', false, drawer);
        });

        bar.appendChild(confirmBtn);
        bar.appendChild(cancelBtn);
        transcript.appendChild(bar);
        transcript.scrollTop = transcript.scrollHeight;
    }

    function executeConfirmed(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var chat = getEventChat(eventid);
        if (!chat.pendingAction) return;

        var sendBtn = drawer.querySelector('#ai-drawer-send');
        var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
        if (sendBtn) sendBtn.disabled = true;
        if (historyBtn) historyBtn.disabled = true;
        showDrawerStatus('Executing confirmed operation...', false, drawer);

        var actionId = chat.pendingAction.action_id;
        var actionTool = chat.pendingAction.tool;
        var confirmationHash = chat.pendingAction.confirmation_hash || '';
        var highImpactConfirmed = chat.pendingAction.confirmation_level === 'high_impact';
        chat.pendingAction = null;

        var csrf = csrfTokens['ai.chat.execute'] || '';

        var params = new URLSearchParams();
        params.set('action_id', actionId);
        params.set('confirmation_hash', confirmationHash);
        params.set('high_impact_confirmed', highImpactConfirmed ? '1' : '0');
        params.set('provider_id', defaultProviderId);
        params.set('chat_session_id', chat.sessionId);
        params.set(CSRF_FIELD, csrf);

        fetch(zbxUrl('ai.chat.execute'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        })
            .then(handleJsonResponse)
            .then(function (response) {
                if (!response || !response.ok) {
                    throw new Error((response && typeof response.error === 'string') ? response.error : 'Action execution failed.');
                }

                var prefix = '[Zabbix Action: ' + (response.action_tool || actionTool) + ']\n\n';
                var storedReply = prefix + (response.reply || '');
                if (response.sensitive_reply) {
                    window.prompt(
                        'Sensitive one-time output for Event #' + eventid + ' — copy it now. It will not be stored in chat history:',
                        response.reply || ''
                    );
                    storedReply = prefix + '[Sensitive one-time output was shown separately and was not stored.]';
                }
                chat.history.push({ role: 'assistant', content: storedReply });
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    renderTranscript(eventid, drawer);
                    showDrawerStatus('Confirmed operation executed.', false, drawer);
                    updatePostBtn(eventid, drawer);
                }
            })
            .catch(function (err) {
                var msg = (err && typeof err.message === 'string') ? err.message : String(err);
                chat.history.push({ role: 'assistant', content: '[Error] ' + msg });
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    renderTranscript(eventid, drawer);
                    showDrawerStatus(msg, true, drawer);
                }
            })
            .finally(function () {
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    if (sendBtn) sendBtn.disabled = false;
                    if (historyBtn) historyBtn.disabled = false;
                }
            });
    }

    function fetchAndSendItemHistory(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var historyBtn = drawer.querySelector('#ai-drawer-history-btn');
        var sendBtn = drawer.querySelector('#ai-drawer-send');
        if (historyBtn) historyBtn.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        showDrawerStatus('Fetching item history...', false, drawer);

        if (historyAbortController) historyAbortController.abort();
        historyAbortController = typeof AbortController !== 'undefined'
            ? new AbortController()
            : null;
        var requestController = historyAbortController;
        var options = { method: 'GET', credentials: 'same-origin' };
        if (requestController) options.signal = requestController.signal;

        fetch(zbxUrl('ai.problem.context') + '&eventid=' + encodeURIComponent(eventid) + '&include_history=1&history_limit=30', options)
            .then(handleJsonResponse)
            .then(function (response) {
                if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
                if (!response || !response.ok) {
                    throw new Error((response && typeof response.error === 'string') ? response.error : 'Failed to fetch item history.');
                }

                var items = response.item_history || [];

                if (!items.length) {
                    showDrawerStatus('No item history data available for this problem.', true, drawer);
                    if (historyBtn) historyBtn.disabled = false;
                    if (sendBtn) sendBtn.disabled = false;
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
                var operatorInstruction = 'Analyze the recently loaded item history and incorporate relevant trends into your assessment.';
                var chat = getEventChat(eventid);
                chat.history.push({
                    role: 'assistant',
                    content: '[Untrusted monitoring data — display only]\n\n' + historyMessage,
                    non_forwardable: true
                });
                chat.history.push({ role: 'user', content: operatorInstruction });
                renderTranscript(eventid, drawer);

                showDrawerStatus('Analyzing item history trends...', false, drawer);

                doSend(eventid, operatorInstruction, drawer, drawerGeneration, function () {
                    if (sendBtn) sendBtn.disabled = false;
                    if (historyBtn) historyBtn.disabled = false;
                }, historyMessage);
            })
            .catch(function (err) {
                if (!isCurrentDrawer(drawer, eventid, drawerGeneration)
                    || (err && err.name === 'AbortError')) return;
                var msg = (err && typeof err.message === 'string') ? err.message : String(err);
                showDrawerStatus(msg, true, drawer);
                if (historyBtn) historyBtn.disabled = false;
                if (sendBtn) sendBtn.disabled = false;
            })
            .finally(function () {
                if (requestController === historyAbortController) {
                    historyAbortController = null;
                }
            });
    }

    function postLastAnswer(eventid, drawer, drawerGeneration) {
        if (!isCurrentDrawer(drawer, eventid, drawerGeneration)) return;
        var chat = getEventChat(eventid);
        var lastReply = '';
        for (var i = chat.history.length - 1; i >= 0; i--) {
            if (chat.history[i].role === 'assistant' && !chat.history[i].non_forwardable
                && (chat.history[i].content || '').trim()) {
                lastReply = chat.history[i].content;
                break;
            }
        }
        if (!lastReply) {
            showDrawerStatus('No AI reply to post.', true, drawer);
            return;
        }

        var postBtn = drawer.querySelector('#ai-drawer-post');
        if (postBtn) postBtn.disabled = true;

        var csrf = csrfTokens['ai.event.comment'] || '';

        var params = new URLSearchParams();
        params.set('eventid', eventid);
        params.set('message', lastReply);
        params.set('chat_session_id', chat.sessionId);
        params.set(CSRF_FIELD, csrf);

        fetch(zbxUrl('ai.event.comment'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        })
            .then(handleJsonResponse)
            .then(function (response) {
                if (!response || !response.ok) {
                    throw new Error((response && typeof response.error === 'string') ? response.error : 'Could not post comment.');
                }
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    showDrawerStatus('Posted ' + (response.chunks || 0) + ' comment chunk(s) to event.', false, drawer);
                }
            })
            .catch(function (err) {
                var msg = (err && typeof err.message === 'string') ? err.message : String(err);
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    showDrawerStatus(msg, true, drawer);
                }
            })
            .finally(function () {
                if (isCurrentDrawer(drawer, eventid, drawerGeneration)) {
                    updatePostBtn(eventid, drawer);
                }
            });
    }

    function showInlineTypingIndicator(transcriptId, visible, drawer) {
        var transcript = drawer
            ? drawer.querySelector('#' + transcriptId)
            : document.getElementById(transcriptId);
        if (!transcript) return;

        var existing = transcript.querySelector('.ai-msg-typing');

        if (!visible) {
            if (existing) existing.remove();
            return;
        }

        if (existing) return;

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

    function renderTranscript(eventid, drawer) {
        var transcript = drawer
            ? drawer.querySelector('#ai-drawer-transcript')
            : document.getElementById('ai-drawer-transcript');
        if (!transcript) return;

        var chat = getEventChat(eventid);
        transcript.innerHTML = '';

        if (!chat.history.length) {
            var empty = document.createElement('div');
            empty.className = 'ai-empty-state';
            empty.textContent = 'Waiting for analysis...';
            transcript.appendChild(empty);
            return;
        }

        chat.history.forEach(function (msg) {
            var isAction = msg.role === 'assistant' && msg.content && msg.content.indexOf('[Zabbix Action:') === 0;

            var item = document.createElement('div');
            item.className = 'ai-msg ai-msg-' + msg.role + (isAction ? ' ai-msg-action' : '');

            var title = document.createElement('div');
            title.className = 'ai-msg-title';
            title.textContent = isAction ? 'AI (Zabbix Action)' : (msg.role === 'assistant' ? 'AI' : 'You');

            var body = document.createElement('pre');
            body.className = 'ai-msg-body';
            body.textContent = msg.content || '';

            item.appendChild(title);
            item.appendChild(body);
            transcript.appendChild(item);
        });

        transcript.scrollTop = transcript.scrollHeight;
    }

    function showDrawerStatus(message, isError, drawer) {
        var el = drawer
            ? drawer.querySelector('#ai-drawer-status')
            : document.getElementById('ai-drawer-status');
        if (!el) return;
        el.textContent = String(message);
        el.className = 'ai-drawer-status ' + (isError ? 'ai-status-error' : 'ai-status-ok');
    }

    function updatePostBtn(eventid, drawer) {
        var postBtn = drawer
            ? drawer.querySelector('#ai-drawer-post')
            : document.getElementById('ai-drawer-post');
        if (!postBtn) return;

        var chat = getEventChat(eventid);
        var hasReply = false;
        for (var i = chat.history.length - 1; i >= 0; i--) {
            if (chat.history[i].role === 'assistant' && !chat.history[i].non_forwardable
                && (chat.history[i].content || '').trim()) {
                hasReply = true;
                break;
            }
        }
        postBtn.disabled = !hasReply;
    }

    // ── State transfer to full chat page ──
    // sessionStorage is per-tab, so we use localStorage with a temporary
    // transfer key. The chat page checks for it on load, imports it into
    // its own sessionStorage, and deletes the localStorage entry.
    var TRANSFER_KEY = 'zbx_ai_chat_transfer';

    function transferStateToSessionStorage(eventid) {
        var chat = getEventChat(eventid);
        var ctx = chat.context || {};

        try {
            localStorage.setItem(TRANSFER_KEY, JSON.stringify({
                history: chat.history,
                sessionId: chat.sessionId,
                context: {
                    provider_id: defaultProviderId,
                    provider_user_override: false,
                    eventid: eventid,
                    eventid_label: eventid,
                    hostname: ctx.hostname || '',
                    hostid: '',
                    problem_summary: ctx.problem_summary || '',
                    extra_context: ''
                },
                timestamp: Date.now()
            }));
        }
        catch (e) {
            // localStorage may be unavailable; proceed anyway.
        }
    }

    // ── Utilities ──
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function handleJsonResponse(response) {
        return response.text().then(function (text) {
            var parsed;
            try { parsed = JSON.parse(text); } catch (e) { parsed = null; }

            // Zabbix wraps module JSON responses in main_block.
            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                try {
                    var inner = JSON.parse(parsed.main_block);
                    if (inner && typeof inner === 'object') {
                        parsed = inner;
                    }
                }
                catch (e) {
                    // main_block was not valid JSON — keep outer parsed.
                }
            }

            // If main_block is already an object (not string-encoded), unwrap it.
            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'object' && parsed.main_block !== null) {
                parsed = parsed.main_block;
            }

            if (!parsed || typeof parsed !== 'object') {
                // Could not parse — return a clean error.
                var snippet = (text || '').substring(0, 200);
                return { ok: false, error: 'Invalid response from server: ' + snippet };
            }

            if (!response.ok && !parsed.error) {
                parsed.error = 'HTTP ' + response.status;
            }

            return parsed;
        });
    }

    // ── MutationObserver for AJAX refreshes ──
    function observeTableRefresh() {
        var target = document.querySelector('.wrapper') ||
                     document.querySelector('main') ||
                     document.body;

        var observer = new MutationObserver(function () {
            if (observer._pending) return;
            observer._pending = true;
            requestAnimationFrame(function () {
                observer._pending = false;
                injectButtons();
            });
        });

        observer.observe(target, {
            childList: true,
            subtree: true
        });
    }

    // ── Init ──
    function init() {
        var action = new URLSearchParams(location.search).get('action');
        if (action !== 'problem.view') {
            return;
        }

        injectButtons();
        observeTableRefresh();

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activeDrawer) {
                closeDrawer();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
}());
