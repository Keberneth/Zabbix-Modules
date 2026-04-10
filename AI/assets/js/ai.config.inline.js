(function () {
    'use strict';

    // ── Constants ──
    var CSRF_FIELD = '_csrf_token';
    var FORM_TYPES = {
        'item': { label: 'Item', title: 'AI Item Assistant', placeholder: 'Describe the item you want to create...\n\nExample: "Monitor CPU load average over 5 minutes using Zabbix agent"' },
        'trigger': { label: 'Trigger', title: 'AI Trigger Assistant', placeholder: 'Describe the trigger you want to create...\n\nExample: "Alert when CPU load is above 5 for more than 3 minutes"' },
        'discovery': { label: 'Discovery Rule', title: 'AI Discovery Assistant', placeholder: 'Describe the discovery rule you want to create...\n\nExample: "Discover all mounted filesystems using Zabbix agent"' },
        'item_prototype': { label: 'Item Prototype', title: 'AI Item Prototype Assistant', placeholder: 'Describe the item prototype you want to create...\n\nExample: "Monitor used space percentage for each discovered filesystem"' },
        'trigger_prototype': { label: 'Trigger Prototype', title: 'AI Trigger Prototype Assistant', placeholder: 'Describe the trigger prototype you want to create...\n\nExample: "Alert when any discovered filesystem is over 90% full"' },
        'history': { label: 'History', title: 'AI History Analysis', placeholder: 'Ask about this item\'s history data...\n\nExample: "Analyze the trend and identify any anomalies or spikes"' }
    };

    // Map overlay dialog titles to form types.
    var DIALOG_TITLE_MAP = {
        'new item': 'item',
        'item': 'item',
        'new trigger': 'trigger',
        'trigger': 'trigger',
        'new discovery rule': 'discovery',
        'discovery rule': 'discovery',
        'new item prototype': 'item_prototype',
        'item prototype': 'item_prototype',
        'new trigger prototype': 'trigger_prototype',
        'trigger prototype': 'trigger_prototype'
    };

    // ── State ──
    var csrfTokens = {};
    var defaultProviderId = '';
    var activeDrawer = null;
    var activeFormType = null;
    var configChats = {};

    function getConfigChat(formType, hostid) {
        var key = formType + '_' + (hostid || 'unknown');
        if (!configChats[key]) {
            configChats[key] = {
                history: [],
                sessionId: 'config_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8),
                context: null
            };
        }
        return configChats[key];
    }

    function zbxUrl(action) {
        // Module actions are always routed through zabbix.php, even when
        // the current page is a different script (history.php, host_discovery.php, etc.).
        var base = location.pathname;
        var scriptName = base.split('/').pop() || '';
        if (scriptName !== 'zabbix.php') {
            // Replace the script name with zabbix.php, keeping the path prefix.
            base = base.substring(0, base.length - scriptName.length) + 'zabbix.php';
        }
        return base + '?action=' + encodeURIComponent(action);
    }

    // ── Hostid extraction ──
    // Tries to extract the hostid from the page URL or the overlay/form.
    function extractHostId() {
        // From URL params — check multiple possible names.
        var params = new URLSearchParams(location.search);
        var hostid = params.get('hostid') || params.get('templateid') || '';
        if (hostid) return hostid;

        // Zabbix list pages use filter_hostids[]=<id> (array format).
        hostid = params.get('filter_hostids[]') || params.get('filter_hostids[0]') || '';
        if (hostid) return hostid;

        // Also check via raw URL matching for filter_hostids.
        var rawSearch = location.search || '';
        var filterMatch = rawSearch.match(/filter_hostids(?:%5B%5D|\[\])=(\d+)/i);
        if (filterMatch) return filterMatch[1];

        // From context parameter (template pages).
        var context = params.get('context');
        if (context === 'template') {
            hostid = params.get('templateid') || '';
            if (hostid) return hostid;
        }

        // From the overlay dialog form fields.
        var overlay = document.querySelector('.overlay-dialogue-body');
        if (overlay) {
            var hiddenField = overlay.querySelector('input[name="hostid"], input[name="templateid"]');
            if (hiddenField && hiddenField.value) return hiddenField.value;
        }

        // From full-page form hidden fields (not in an overlay).
        var pageForm = document.querySelector('form[name="itemForm"], form[name="triggersForm"], form#item-form, form#trigger-form, form#discovery-form, form[action*="item"], form[action*="trigger"], form[action*="discovery"]');
        if (pageForm) {
            var fld = pageForm.querySelector('input[name="hostid"], input[name="templateid"]');
            if (fld && fld.value) return fld.value;
        }

        // Broader search: any hidden hostid input on the page.
        var anyHostId = document.querySelector('input[name="hostid"][type="hidden"], input[name="templateid"][type="hidden"]');
        if (anyHostId && anyHostId.value) return anyHostId.value;

        // Try breadcrumb links (e.g., "All templates / Windows Event Log Monitoring").
        var breadcrumbLinks = document.querySelectorAll('.breadcrumbs a[href], .filter-breadcrumb a[href], [class*="breadcrumb"] a[href]');
        for (var i = 0; i < breadcrumbLinks.length; i++) {
            var href = breadcrumbLinks[i].getAttribute('href') || '';
            var m = href.match(/[?&](?:hostid|templateid)=(\d+)/);
            if (m) return m[1];
        }

        // Try any link on the page that has hostid in the query.
        var navLinks = document.querySelectorAll('.top-nav-container a[href], .ui-tabs-nav a[href]');
        for (var j = 0; j < navLinks.length; j++) {
            var navHref = navLinks[j].getAttribute('href') || '';
            var nm = navHref.match(/[?&](?:hostid|templateid)=(\d+)/);
            if (nm) return nm[1];
        }

        return '';
    }

    function extractParentDiscoveryId() {
        var params = new URLSearchParams(location.search);
        var did = params.get('parent_discoveryid');
        if (did) return did;

        var overlay = document.querySelector('.overlay-dialogue-body');
        if (overlay) {
            var hiddenField = overlay.querySelector('input[name="parent_discoveryid"]');
            if (hiddenField) return hiddenField.value;
        }

        return '';
    }

    // ── Extract IDs and form values from the overlay dialog or full-page form ──
    function extractFormIds() {
        var ids = { itemid: '', triggerid: '', discoveryid: '' };

        // Try URL params first.
        var params = new URLSearchParams(location.search);
        ids.itemid = params.get('itemid') || '';
        ids.triggerid = params.get('triggerid') || '';
        ids.discoveryid = params.get('discoveryid') || '';

        // History page uses itemids[]=<id> (array format).
        if (!ids.itemid) {
            ids.itemid = params.get('itemids[]') || params.get('itemids[0]') || '';
        }
        if (!ids.itemid) {
            var rawSearch = location.search || '';
            var itemIdsMatch = rawSearch.match(/itemids(?:%5B%5D|\[\]|\[0\])=(\d+)/i);
            if (itemIdsMatch) ids.itemid = itemIdsMatch[1];
        }

        // Search everywhere on the page (overlay and full-page forms).
        var searchContainers = [
            document.querySelector('.overlay-dialogue-body'),
            document.querySelector('form[name="itemForm"], form[name="triggersForm"], form#item-form, form#trigger-form'),
            document.querySelector('main'),
            document.body
        ].filter(Boolean);

        for (var c = 0; c < searchContainers.length; c++) {
            var container = searchContainers[c];
            if (!ids.itemid) {
                var itemField = container.querySelector('input[name="itemid"]');
                if (itemField && itemField.value && itemField.value !== '0') ids.itemid = itemField.value;
            }
            if (!ids.triggerid) {
                var triggerField = container.querySelector('input[name="triggerid"]');
                if (triggerField && triggerField.value && triggerField.value !== '0') ids.triggerid = triggerField.value;
            }
            if (!ids.discoveryid) {
                var discoveryField = container.querySelector('input[name="discoveryid"]');
                if (discoveryField && discoveryField.value && discoveryField.value !== '0') ids.discoveryid = discoveryField.value;
            }
            if (ids.itemid || ids.triggerid || ids.discoveryid) break;
        }

        return ids;
    }

    /**
     * Extract current form field values from the overlay dialog or full-page form DOM.
     * This captures what the user currently sees/has entered in the form.
     */
    function extractFormValues() {
        // Try overlay first, then full-page form containers.
        var overlay = document.querySelector('.overlay-dialogue-body')
            || document.querySelector('form[name="itemForm"], form[name="triggersForm"], form#item-form, form#trigger-form')
            || document.querySelector('.form-grid')
            || document.querySelector('main form');
        if (!overlay) return null;

        var values = {};

        // Common fields across item/trigger/discovery forms.
        var fieldSelectors = {
            'name': 'input[name="name"], input[id="name"]',
            'key_': 'input[name="key"], input[id="key"]',
            'type': 'select[name="type"], z-select[name="type"]',
            'value_type': 'select[name="value_type"], z-select[name="value_type"]',
            'units': 'input[name="units"], input[id="units"]',
            'delay': 'input[name="delay"], input[id="delay"]',
            'history': 'input[name="history"], input[id="history"]',
            'trends': 'input[name="trends"], input[id="trends"]',
            'description': 'textarea[name="description"]',
            'expression': 'textarea[name="expression"], textarea[id="expression"]',
            'recovery_expression': 'textarea[name="recovery_expression"]',
            'status': 'input[name="status"]',
            'logtimefmt': 'input[name="logtimefmt"]',
            'url': 'input[name="url"]'
        };

        for (var fieldName in fieldSelectors) {
            try {
                var el = overlay.querySelector(fieldSelectors[fieldName]);
                if (el) {
                    var tagName = (el.tagName || '').toUpperCase();
                    if (tagName === 'SELECT' || tagName === 'Z-SELECT') {
                        var label = '';
                        try {
                            // Standard <select>
                            if (el.options && el.selectedIndex >= 0 && el.options[el.selectedIndex]) {
                                label = el.options[el.selectedIndex].textContent || '';
                            }
                        } catch (e) {}
                        // z-select may expose value via textContent or a visible label.
                        if (!label && el.querySelector) {
                            var selectedEl = el.querySelector('[selected], .selected, [aria-selected="true"]');
                            if (selectedEl) {
                                label = selectedEl.textContent || '';
                            }
                        }
                        values[fieldName] = {
                            value: el.value || '',
                            label: (label || el.value || '').trim()
                        };
                    } else if (tagName === 'TEXTAREA') {
                        values[fieldName] = el.value || '';
                    } else {
                        values[fieldName] = el.value || '';
                    }
                }
            } catch (fieldErr) {
                // Skip fields that can't be read.
            }
        }

        // Extract preprocessing steps from the form DOM.
        var preprocessingSteps = extractPreprocessingFromDOM(overlay);
        if (preprocessingSteps.length > 0) {
            values['preprocessing'] = preprocessingSteps;
        }

        // Extract tags from the form DOM.
        var tags = extractTagsFromDOM(overlay);
        if (tags.length > 0) {
            values['tags'] = tags;
        }

        // Extract severity for triggers.
        var severityInputs = overlay.querySelectorAll('input[name="priority"]');
        severityInputs.forEach(function (input) {
            if (input.checked) {
                var severityLabels = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
                values['priority'] = {
                    value: input.value,
                    label: severityLabels[parseInt(input.value, 10)] || input.value
                };
            }
        });

        // Also try radio-button style severity (Zabbix 7 uses these).
        if (!values['priority']) {
            var activeSeverity = overlay.querySelector('.btn-toggle-severity.active, [name="priority"]:checked');
            if (activeSeverity) {
                values['priority'] = { value: activeSeverity.value || '', label: '' };
            }
        }

        return Object.keys(values).length > 0 ? values : null;
    }

    function extractPreprocessingFromDOM(container) {
        var steps = [];

        // Zabbix renders preprocessing steps in a list/table structure.
        var stepRows = container.querySelectorAll(
            '.preprocessing-list-item, ' +
            '#preprocessing .preprocessing-step, ' +
            '[id*="preprocessing"] tr, ' +
            '.preprocessing-list-foot ~ .preprocessing-list-item, ' +
            '.list-numbered-item'
        );

        stepRows.forEach(function (row) {
            var step = {};

            // Type select/dropdown.
            var typeSelect = row.querySelector('select[name*="type"], z-select[name*="type"]');
            if (typeSelect) {
                var selectedOpt = typeSelect.options ? typeSelect.options[typeSelect.selectedIndex] : null;
                step.type = typeSelect.value || '';
                step.type_label = selectedOpt ? selectedOpt.textContent.trim() : '';
            }

            // Parameters (text inputs, textareas).
            var paramInputs = row.querySelectorAll(
                'input[name*="params"], textarea[name*="params"]'
            );
            var params = [];
            paramInputs.forEach(function (input) {
                if (input.value) params.push(input.value);
            });
            if (params.length > 0) {
                step.params = params.join('\n');
            }

            // Error handler.
            var errorSelect = row.querySelector('select[name*="error_handler"], z-select[name*="error_handler"]');
            if (errorSelect) {
                step.error_handler = errorSelect.value || '0';
            }

            if (step.type || step.params) {
                steps.push(step);
            }
        });

        return steps;
    }

    function extractTagsFromDOM(container) {
        var tags = [];
        var tagRows = container.querySelectorAll('.tag-row, [id*="tags"] tr, .tags-table tr');

        tagRows.forEach(function (row) {
            var tagInput = row.querySelector('input[name*="[tag]"], input[placeholder*="tag"]');
            var valueInput = row.querySelector('input[name*="[value]"], input[placeholder*="value"]');
            if (tagInput && tagInput.value) {
                tags.push({
                    tag: tagInput.value,
                    value: valueInput ? valueInput.value : ''
                });
            }
        });

        return tags;
    }

    // ── Detect overlay dialogs ──
    function detectFormType(overlayElement) {
        // Check the dialog title.
        var titleEl = overlayElement.querySelector('.overlay-dialogue-header h4, .dashboard-widget-head h4, .overlay-dialogue-controls h4');

        if (!titleEl) {
            // Try the generic title container.
            titleEl = overlayElement.querySelector('[class*="title"]');
        }

        if (titleEl) {
            var titleText = (titleEl.textContent || '').trim().toLowerCase();

            for (var pattern in DIALOG_TITLE_MAP) {
                if (titleText === pattern || titleText.indexOf(pattern) === 0) {
                    return DIALOG_TITLE_MAP[pattern];
                }
            }
        }

        // Fallback: check for form elements characteristic of each type.
        var body = overlayElement.querySelector('.overlay-dialogue-body') || overlayElement;

        // Item form has a "Key" field.
        var keyLabel = body.querySelector('label[for*="key"], [class*="label"]:not([class*="trigger"])');
        var expressionField = body.querySelector('textarea[name="expression"], #expression');

        // Check for key_ input (items).
        var keyInput = body.querySelector('input[name="key"], input[id="key"]');

        if (expressionField && !keyInput) {
            // Has expression but no key = trigger.
            if (body.querySelector('input[name="parent_discoveryid"]')) {
                return 'trigger_prototype';
            }
            return 'trigger';
        }

        if (keyInput) {
            if (body.querySelector('input[name="parent_discoveryid"]')) {
                return 'item_prototype';
            }

            // Check if it's a discovery rule by looking for "LLD macros" or filter section.
            var lldSection = body.querySelector('[id*="lld"], [id*="filter"], [class*="lld"]');
            if (lldSection) {
                return 'discovery';
            }

            return 'item';
        }

        return null;
    }

    // ── Button injection into overlay ──
    function injectAIButton(overlayElement, formType) {
        if (overlayElement.querySelector('.ai-config-btn')) {
            return; // Already injected.
        }

        var headerActions = overlayElement.querySelector('.overlay-dialogue-controls');
        if (!headerActions) {
            // Try to find the header area.
            headerActions = overlayElement.querySelector('.overlay-dialogue-header');
        }

        if (!headerActions) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-config-btn';
        btn.title = 'Get AI help creating this ' + (FORM_TYPES[formType] ? FORM_TYPES[formType].label : 'configuration');
        btn.textContent = 'AI';
        btn.dataset.formType = formType;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            openConfigDrawer(formType);
        });

        // Insert before the close button if possible.
        var closeBtn = headerActions.querySelector('button[title="Close"], .overlay-close-btn, .btn-overlay-close');
        if (closeBtn) {
            headerActions.insertBefore(btn, closeBtn);
        } else {
            headerActions.appendChild(btn);
        }
    }

    // ── Drawer ──
    function openConfigDrawer(formType) {
        if (activeDrawer && activeFormType === formType) {
            closeDrawer();
            return;
        }

        if (activeDrawer) {
            closeDrawer();
        }

        activeFormType = formType;
        var hostid = extractHostId();
        var chat = getConfigChat(formType, hostid);
        var formInfo = FORM_TYPES[formType] || FORM_TYPES['item'];

        var drawer = document.createElement('div');
        drawer.className = 'ai-config-drawer';
        drawer.id = 'ai-config-drawer';

        var historyControls = '';
        if (formType === 'history') {
            historyControls =
                '<div class="ai-history-controls" id="ai-history-controls">' +
                    '<div class="ai-history-range">' +
                        '<label>Period: <select id="ai-history-period">' +
                            '<option value="1">Last 1 hour</option>' +
                            '<option value="3">Last 3 hours</option>' +
                            '<option value="6">Last 6 hours</option>' +
                            '<option value="12">Last 12 hours</option>' +
                            '<option value="24" selected>Last 24 hours</option>' +
                            '<option value="48">Last 2 days</option>' +
                            '<option value="72">Last 3 days</option>' +
                            '<option value="168">Last 7 days</option>' +
                            '<option value="720">Last 30 days</option>' +
                            '<option value="custom">Custom range...</option>' +
                        '</select></label>' +
                        '<label>Max rows: <input type="number" id="ai-history-limit" value="50" min="10" max="1000" style="width:70px"></label>' +
                        '<button type="button" class="btn-alt" id="ai-history-reload" title="Reload history data with new settings">Reload data</button>' +
                    '</div>' +
                    '<div class="ai-history-custom" id="ai-history-custom" style="display:none">' +
                        '<label>From: <input type="datetime-local" id="ai-history-from" step="60"></label>' +
                        '<label>To: <input type="datetime-local" id="ai-history-to" step="60"></label>' +
                    '</div>' +
                    '<div class="ai-history-info" id="ai-history-info"></div>' +
                '</div>';
        }

        drawer.innerHTML =
            '<div class="ai-drawer-header">' +
                '<div class="ai-drawer-title">' +
                    '<span class="ai-drawer-icon">AI</span>' +
                    '<span class="ai-drawer-label">' + escapeHtml(formInfo.title) + '</span>' +
                '</div>' +
                '<div class="ai-drawer-header-actions">' +
                    '<button type="button" class="ai-drawer-close" title="Close">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div class="ai-config-context" id="ai-config-context">' +
                '<div class="ai-drawer-loading">Loading configuration context...</div>' +
            '</div>' +
            historyControls +
            '<div class="ai-drawer-transcript" id="ai-config-transcript"></div>' +
            '<div class="ai-drawer-status ai-hidden" id="ai-config-status"></div>' +
            '<form class="ai-drawer-compose" id="ai-config-compose">' +
                '<textarea class="ai-drawer-input" id="ai-config-message" rows="4" placeholder="' + escapeHtml(formInfo.placeholder) + '"></textarea>' +
                '<div class="ai-drawer-actions">' +
                    '<button type="submit" class="btn ai-drawer-send" id="ai-config-send">Send</button>' +
                '</div>' +
            '</form>';

        document.body.appendChild(drawer);
        activeDrawer = drawer;

        drawer.querySelector('.ai-drawer-close').addEventListener('click', closeDrawer);

        var form = document.getElementById('ai-config-compose');
        var msgField = document.getElementById('ai-config-message');
        var sendBtn = document.getElementById('ai-config-send');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            sendConfigMessage(formType, hostid, msgField, sendBtn);
        });

        // Enable Ctrl+Enter to send.
        msgField.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                sendConfigMessage(formType, hostid, msgField, sendBtn);
            }
        });

        // History page: wire up reload button.
        if (formType === 'history') {
            var reloadBtn = document.getElementById('ai-history-reload');
            if (reloadBtn) {
                reloadBtn.addEventListener('click', function () {
                    loadConfigContext(formType, hostid);
                });
            }
            updateHistoryInfo();
        }

        renderTranscript(formType, hostid);
        loadConfigContext(formType, hostid);
    }

    /**
     * Update the info line showing estimated data points for the selected period.
     */
    function updateHistoryInfo() {
        var periodEl = document.getElementById('ai-history-period');
        var limitEl = document.getElementById('ai-history-limit');
        var infoEl = document.getElementById('ai-history-info');
        var customEl = document.getElementById('ai-history-custom');
        if (!periodEl || !limitEl || !infoEl) return;

        var isCustom = periodEl.value === 'custom';
        var limit = parseInt(limitEl.value, 10) || 50;

        // Show/hide custom date inputs.
        if (customEl) {
            customEl.style.display = isCustom ? 'flex' : 'none';
        }

        // Set defaults for custom range inputs if empty.
        if (isCustom) {
            var fromEl = document.getElementById('ai-history-from');
            var toEl = document.getElementById('ai-history-to');
            if (fromEl && !fromEl.value) {
                // Default to today, 6 hours ago.
                var now = new Date();
                var sixHoursAgo = new Date(now.getTime() - 6 * 3600000);
                fromEl.value = formatLocalDatetime(sixHoursAgo);
                toEl.value = formatLocalDatetime(now);
            }
            var fromLabel = fromEl && fromEl.value ? fromEl.value.replace('T', ' ') : '?';
            var toLabel = toEl && toEl.value ? toEl.value.replace('T', ' ') : '?';
            infoEl.textContent = fromLabel + ' to ' + toLabel + ', max ' + limit + ' data points';
        } else {
            var periodLabel = periodEl.options[periodEl.selectedIndex]
                ? periodEl.options[periodEl.selectedIndex].textContent
                : periodEl.value + 'h';
            infoEl.textContent = periodLabel + ', max ' + limit + ' data points';
        }

        // Wire up change events.
        periodEl.onchange = updateHistoryInfo;
        limitEl.onchange = updateHistoryInfo;
        var fromInput = document.getElementById('ai-history-from');
        var toInput = document.getElementById('ai-history-to');
        if (fromInput) fromInput.onchange = updateHistoryInfo;
        if (toInput) toInput.onchange = updateHistoryInfo;
    }

    function formatLocalDatetime(date) {
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
            'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    /**
     * Get the user-selected history period and limit from the controls.
     */
    function getHistoryParams() {
        var periodEl = document.getElementById('ai-history-period');
        var limitEl = document.getElementById('ai-history-limit');
        var params = {
            period: 24,
            limit: limitEl ? parseInt(limitEl.value, 10) || 50 : 50,
            time_from: '',
            time_to: ''
        };

        if (periodEl && periodEl.value === 'custom') {
            var fromEl = document.getElementById('ai-history-from');
            var toEl = document.getElementById('ai-history-to');
            if (fromEl && fromEl.value) {
                params.time_from = fromEl.value;
            }
            if (toEl && toEl.value) {
                params.time_to = toEl.value;
            }
            // Calculate period from the range for backward compatibility.
            if (params.time_from && params.time_to) {
                var diffMs = new Date(params.time_to).getTime() - new Date(params.time_from).getTime();
                params.period = Math.max(1, Math.ceil(diffMs / 3600000));
            }
        } else {
            params.period = periodEl ? parseInt(periodEl.value, 10) || 24 : 24;
        }

        return params;
    }

    function closeDrawer() {
        if (activeDrawer) {
            activeDrawer.remove();
            activeDrawer = null;
            activeFormType = null;
        }
    }

    function loadConfigContext(formType, hostid) {
        var contextEl = document.getElementById('ai-config-context');
        if (!contextEl) return;

        // Extract the specific item/trigger/discovery ID from the form.
        var formIds = {};
        try { formIds = extractFormIds(); } catch (e) { formIds = { itemid: '', triggerid: '', discoveryid: '' }; }

        // Extract current form field values from the DOM.
        var formValues = null;
        try { formValues = extractFormValues(); } catch (e) { /* non-critical */ }

        var url = zbxUrl('ai.config.context') +
            '&hostid=' + encodeURIComponent(hostid) +
            '&form_type=' + encodeURIComponent(formType);

        // Pass IDs so the backend can fetch full details with preprocessing.
        if (formIds.itemid && (formType === 'item' || formType === 'item_prototype' || formType === 'history')) {
            url += '&itemid=' + encodeURIComponent(formIds.itemid);
        }
        if (formIds.triggerid && (formType === 'trigger' || formType === 'trigger_prototype')) {
            url += '&triggerid=' + encodeURIComponent(formIds.triggerid);
        }
        if (formIds.discoveryid && formType === 'discovery') {
            url += '&discoveryid=' + encodeURIComponent(formIds.discoveryid);
        }

        // For history pages, pass user-selected period and limit.
        if (formType === 'history') {
            var histParams = getHistoryParams();
            url += '&history_period=' + histParams.period;
            url += '&history_limit=' + histParams.limit;
            if (histParams.time_from) {
                url += '&time_from=' + encodeURIComponent(histParams.time_from);
            }
            if (histParams.time_to) {
                url += '&time_to=' + encodeURIComponent(histParams.time_to);
            }
        }

        var parentDiscoveryId = extractParentDiscoveryId();
        if (parentDiscoveryId) {
            url += '&parent_discoveryid=' + encodeURIComponent(parentDiscoveryId);
        }

        fetch(url, { method: 'GET', credentials: 'same-origin' })
            .then(function (resp) {
                // Guard: if the response is a redirect to login or HTML page,
                // detect it before trying to parse JSON.
                var contentType = resp.headers.get('content-type') || '';
                if (contentType.indexOf('json') === -1 && resp.url && resp.url.indexOf('action=ai.config.context') === -1) {
                    throw new Error('Server returned non-JSON response (possible redirect to login page).');
                }
                return handleJsonResponse(resp);
            })
            .then(function (response) {
              try {
                if (!response || !response.ok) {
                    var errMsg = (response && typeof response.error === 'string')
                        ? response.error
                        : 'Failed to load context.';
                    contextEl.innerHTML = '<div class="ai-drawer-context-error">' + escapeHtml(errMsg) + '</div>';

                    // Even if context failed, try to get CSRF tokens from
                    // the simpler problem context endpoint as a fallback.
                    if (!csrfTokens['ai.chat.send']) {
                        fetchCsrfFallback();
                    }
                    return;
                }

                // Store CSRF tokens.
                if (response.csrf) {
                    if (response.csrf.field_name) {
                        CSRF_FIELD = response.csrf.field_name;
                    }
                    if (response.csrf.chat_send) {
                        csrfTokens['ai.chat.send'] = response.csrf.chat_send;
                    }
                }

                if (response.default_provider_id) {
                    defaultProviderId = response.default_provider_id;
                }

                var chat = getConfigChat(formType, hostid);
                // Attach both API context and DOM-extracted form values.
                response.form_values = formValues;
                response.form_ids = formIds;
                chat.context = response;

                var html = '';
                var host = response.host;
                var isEditing = !!(response.current_item || response.current_trigger || response.current_discovery);

                if (host) {
                    html += '<div class="ai-ctx-row"><strong>' + (host.is_template ? 'Template:' : 'Host:') + '</strong> ' + escapeHtml(host.name || host.host) + '</div>';

                    if (host.interfaces && host.interfaces.length) {
                        var ifaceTypes = { '1': 'Agent', '2': 'SNMP', '3': 'IPMI', '4': 'JMX' };
                        var ifaces = host.interfaces.map(function (i) {
                            return (ifaceTypes[i.type] || 'Type ' + i.type) + ': ' + (i.ip || i.dns);
                        });
                        html += '<div class="ai-ctx-row"><strong>Interfaces:</strong> ' + escapeHtml(ifaces.join(', ')) + '</div>';
                    }

                    if (host.parent_templates && host.parent_templates.length) {
                        var tplNames = host.parent_templates.map(function (t) { return t.name; });
                        html += '<div class="ai-ctx-row"><strong>Templates:</strong> ' + escapeHtml(tplNames.join(', ')) + '</div>';
                    }
                }

                // Show current item/trigger details.
                if (response.current_item) {
                    var ci = response.current_item;
                    html += '<div class="ai-ctx-row"><strong>Current item:</strong> ' + escapeHtml(ci.name) + '</div>';
                    html += '<div class="ai-ctx-row"><strong>Key:</strong> <code>' + escapeHtml(ci.key_) + '</code></div>';
                    html += '<div class="ai-ctx-row"><strong>Type:</strong> ' + escapeHtml(ci.type_label) + '</div>';
                    html += '<div class="ai-ctx-row"><strong>Info type:</strong> ' + escapeHtml(ci.value_type_label) + '</div>';
                    if (ci.preprocessing && ci.preprocessing.length) {
                        html += '<div class="ai-ctx-row"><strong>Preprocessing:</strong> ' + ci.preprocessing.length + ' step(s)</div>';
                        ci.preprocessing.forEach(function (step, idx) {
                            html += '<div class="ai-ctx-row" style="padding-left:12px">' + (idx + 1) + '. ' + escapeHtml(step.type_label);
                            if (step.params) {
                                var paramPreview = step.params.length > 80 ? step.params.substring(0, 80) + '...' : step.params;
                                html += ': <code>' + escapeHtml(paramPreview) + '</code>';
                            }
                            html += '</div>';
                        });
                    }
                }

                if (response.current_trigger) {
                    var ct = response.current_trigger;
                    html += '<div class="ai-ctx-row"><strong>Current trigger:</strong> ' + escapeHtml(ct.description) + '</div>';
                    html += '<div class="ai-ctx-row"><strong>Severity:</strong> ' + escapeHtml(ct.priority_label) + '</div>';
                    html += '<div class="ai-ctx-row"><strong>Expression:</strong> <code>' + escapeHtml(ct.expression) + '</code></div>';
                }

                if (response.current_discovery) {
                    var cd = response.current_discovery;
                    html += '<div class="ai-ctx-row"><strong>Current rule:</strong> ' + escapeHtml(cd.name) + '</div>';
                    html += '<div class="ai-ctx-row"><strong>Key:</strong> <code>' + escapeHtml(cd.key_) + '</code></div>';
                }

                if (response.items && response.items.length) {
                    html += '<div class="ai-ctx-row"><strong>Existing items:</strong> ' + response.items.length + '</div>';
                }

                if (response.triggers && response.triggers.length) {
                    html += '<div class="ai-ctx-row"><strong>Existing triggers:</strong> ' + response.triggers.length + '</div>';
                }

                // History page: show item history summary, triggers, macros, problems.
                if (formType === 'history') {
                    if (response.item_history && response.item_history.length) {
                        var periodInfo = response.history_params
                            ? response.history_params.period_hours + 'h, limit ' + response.history_params.limit
                            : '';
                        var totalInfo = '';
                        if (response.history_total_count !== undefined && response.history_total_count !== null) {
                            totalInfo = ' of ' + response.history_total_count + ' available';
                        }
                        html += '<div class="ai-ctx-row"><strong>History data:</strong> ' + response.item_history.length + totalInfo + ' data points' + (periodInfo ? ' (' + periodInfo + ')' : '') + '</div>';
                        if (response.history_params && response.history_params.time_from) {
                            html += '<div class="ai-ctx-row"><strong>Range:</strong> ' + escapeHtml(response.history_params.time_from) + ' to ' + escapeHtml(response.history_params.time_to) + '</div>';
                        }
                    }
                    if (response.item_triggers && response.item_triggers.length) {
                        html += '<div class="ai-ctx-row"><strong>Related triggers:</strong> ' + response.item_triggers.length + '</div>';
                        response.item_triggers.forEach(function (t) {
                            var stateClass = t.value === 'PROBLEM' ? 'color:#e53935' : 'color:#43a047';
                            html += '<div class="ai-ctx-row" style="padding-left:12px">- ' + escapeHtml(t.description) + ' <span style="' + stateClass + '">(' + t.value + ')</span></div>';
                        });
                    }
                    if (response.host_macros && response.host_macros.length) {
                        html += '<div class="ai-ctx-row"><strong>Host macros:</strong> ' + response.host_macros.length + ' macros loaded</div>';
                    }
                    if (response.recent_problems && response.recent_problems.total_events > 0) {
                        html += '<div class="ai-ctx-row"><strong>Recent problems:</strong> ' + response.recent_problems.total_events + ' events in last ' + response.recent_problems.period_days + ' days</div>';
                    }
                }

                var formLabel = FORM_TYPES[formType] ? FORM_TYPES[formType].label : formType;
                if (formType === 'history') {
                    html += '<div class="ai-ctx-row ai-ctx-hint">AI has item details, history data, triggers, host macros, and recent problems. Ask about trends, anomalies, threshold tuning, or improvements.</div>';
                } else if (isEditing) {
                    html += '<div class="ai-ctx-row ai-ctx-hint">AI has full context of this ' + escapeHtml(formLabel.toLowerCase()) + ' including preprocessing. Ask about improvements, issues, or changes.</div>';
                } else {
                    html += '<div class="ai-ctx-row ai-ctx-hint">Describe what ' + escapeHtml(formLabel.toLowerCase()) + ' you want to create. The AI will suggest the correct configuration.</div>';
                }

                contextEl.innerHTML = html || '<div class="ai-drawer-context-empty">No host context available. You can still ask for help.</div>';

              } catch (innerErr) {
                // Catch any rendering errors so the drawer doesn't get stuck.
                console.error('[AI Config] Error processing context response:', innerErr);
                if (contextEl) {
                    contextEl.innerHTML = '<div class="ai-drawer-context-error">Error processing context: ' + escapeHtml(String(innerErr.message || innerErr)) + '</div>';
                }
                if (!csrfTokens['ai.chat.send']) {
                    fetchCsrfFallback();
                }
              }
            })
            .catch(function (err) {
                var msg = (err && typeof err.message === 'string') ? err.message : 'Failed to load context.';
                console.error('[AI Config] Context fetch error:', err);
                if (contextEl) {
                    contextEl.innerHTML = '<div class="ai-drawer-context-error">' + escapeHtml(msg) + '</div>';
                }

                // Even if context failed completely, try to get CSRF tokens
                // so the chat can still work without host context.
                if (!csrfTokens['ai.chat.send']) {
                    fetchCsrfFallback();
                }
            });
    }

    /**
     * Fallback: fetch CSRF tokens from the problem context endpoint.
     * This endpoint is simpler and more reliable — it only needs
     * a dummy eventid to return tokens and provider info.
     */
    function fetchCsrfFallback() {
        fetch(zbxUrl('ai.config.context') + '&hostid=&form_type=item', {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then(handleJsonResponse)
            .then(function (response) {
                if (response && response.csrf) {
                    if (response.csrf.field_name) {
                        CSRF_FIELD = response.csrf.field_name;
                    }
                    if (response.csrf.chat_send) {
                        csrfTokens['ai.chat.send'] = response.csrf.chat_send;
                    }
                }
                if (response && response.default_provider_id) {
                    defaultProviderId = response.default_provider_id;
                }
            })
            .catch(function () {
                // If even this fails, try the problem context endpoint.
                fetch(zbxUrl('ai.problem.context') + '&eventid=0', {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                    .then(handleJsonResponse)
                    .then(function (response) {
                        if (response && response.csrf) {
                            if (response.csrf.field_name) {
                                CSRF_FIELD = response.csrf.field_name;
                            }
                            if (response.csrf.chat_send) {
                                csrfTokens['ai.chat.send'] = response.csrf.chat_send;
                            }
                        }
                        if (response && response.default_provider_id) {
                            defaultProviderId = response.default_provider_id;
                        }
                    })
                    .catch(function () {
                        // Nothing more we can do.
                    });
            });
    }

    function sendConfigMessage(formType, hostid, msgField, sendBtn) {
        var message = (msgField.value || '').trim();
        if (!message) return;

        var chat = getConfigChat(formType, hostid);
        chat.history.push({ role: 'user', content: message });
        renderTranscript(formType, hostid);
        msgField.value = '';
        sendBtn.disabled = true;
        showStatus('Sending...', false);

        doSend(formType, hostid, message, function () {
            sendBtn.disabled = false;
            msgField.focus();
        });
    }

    function doSend(formType, hostid, message, onDone) {
        var chat = getConfigChat(formType, hostid);

        var requestHistory = chat.history.slice(0, -1).filter(function (m) {
            return m.role === 'user' || m.role === 'assistant';
        });

        if (requestHistory.length > 12) {
            requestHistory = requestHistory.slice(requestHistory.length - 12);
        }

        var csrf = csrfTokens['ai.chat.send'] || '';

        if (!csrf) {
            chat.history.push({ role: 'assistant', content: '[Error] CSRF token not available. Try closing and reopening the AI drawer.' });
            renderTranscript(formType, hostid);
            showStatus('CSRF token missing.', true);
            if (onDone) onDone();
            return;
        }

        // Build a context-rich message by prepending system context.
        var contextPrefix = buildContextPrefix(formType, chat.context);

        var params = new URLSearchParams();
        params.set('message', message);
        params.set('history_json', JSON.stringify(requestHistory));
        params.set('eventid', '');
        params.set('hostname', (chat.context && chat.context.host) ? (chat.context.host.host || '') : '');
        params.set('problem_summary', '');
        params.set('extra_context', contextPrefix);
        params.set('chat_session_id', chat.sessionId);
        params.set('provider_id', defaultProviderId);
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

                chat.history.push({ role: 'assistant', content: response.reply || '' });
                renderTranscript(formType, hostid);
                showStatus('Reply received from ' + (response.provider_name || 'AI') + '.', false);
            })
            .catch(function (err) {
                var msg = (err && typeof err.message === 'string') ? err.message : String(err);
                chat.history.push({ role: 'assistant', content: '[Error] ' + msg });
                renderTranscript(formType, hostid);
                showStatus(msg, true);
            })
            .finally(function () {
                if (onDone) onDone();
            });
    }

    function buildContextPrefix(formType, context) {
        if (!context) return '';

        var lines = [];
        lines.push('=== AI Configuration Assistant Context ===');
        lines.push('Form type: ' + formType);

        var formInfo = FORM_TYPES[formType] || {};
        var isEditing = !!(context.current_item || context.current_trigger || context.current_discovery);

        if (isEditing) {
            lines.push('The user is EDITING an existing Zabbix ' + (formInfo.label || formType) + '.');
        } else {
            lines.push('The user is creating a new Zabbix ' + (formInfo.label || formType) + '.');
        }
        lines.push('');

        // ── Include full details of the current item/trigger/discovery being edited ──
        if (context.current_item) {
            var ci = context.current_item;
            lines.push('=== CURRENT ITEM BEING EDITED ===');
            lines.push('Item ID: ' + ci.itemid);
            lines.push('Name: ' + ci.name);
            lines.push('Key: ' + ci.key_);
            lines.push('Type: ' + ci.type_label + ' (type ID: ' + ci.type + ')');
            lines.push('Type of information: ' + ci.value_type_label + ' (value_type: ' + ci.value_type + ')');
            lines.push('Update interval: ' + (ci.delay || 'N/A'));
            lines.push('History: ' + (ci.history || 'N/A'));
            lines.push('Trends: ' + (ci.trends || 'N/A'));
            lines.push('Units: ' + (ci.units || 'none'));
            lines.push('Status: ' + (ci.status === '0' ? 'Enabled' : 'Disabled'));
            if (ci.description) {
                lines.push('Description: ' + ci.description);
            }
            if (ci.logtimefmt) {
                lines.push('Log time format: ' + ci.logtimefmt);
            }
            if (ci.master_itemid && ci.master_itemid !== '0') {
                lines.push('Master item ID: ' + ci.master_itemid + ' (this is a dependent item)');
            }
            if (ci.tags && ci.tags.length) {
                lines.push('Tags:');
                ci.tags.forEach(function (tag) {
                    lines.push('  - ' + tag.tag + (tag.value ? ': ' + tag.value : ''));
                });
            }
            if (ci.preprocessing && ci.preprocessing.length) {
                lines.push('');
                lines.push('PREPROCESSING STEPS (' + ci.preprocessing.length + ' total):');
                ci.preprocessing.forEach(function (step, idx) {
                    lines.push('  Step ' + (idx + 1) + ': ' + step.type_label + ' (type ID: ' + step.type + ')');
                    if (step.params) {
                        lines.push('    Parameters:');
                        // Split multi-line params (e.g. JavaScript code).
                        step.params.split('\n').forEach(function (line) {
                            lines.push('    | ' + line);
                        });
                    }
                    if (step.error_handler && step.error_handler !== '0') {
                        var errorHandlers = { '1': 'Discard value', '2': 'Set value to', '3': 'Set error to' };
                        lines.push('    Error handler: ' + (errorHandlers[step.error_handler] || step.error_handler));
                        if (step.error_handler_params) {
                            lines.push('    Error handler params: ' + step.error_handler_params);
                        }
                    }
                });
            } else {
                lines.push('Preprocessing: None configured');
            }
            lines.push('=== END CURRENT ITEM ===');
            lines.push('');
        }

        if (context.current_trigger) {
            var ct = context.current_trigger;
            lines.push('=== CURRENT TRIGGER BEING EDITED ===');
            lines.push('Trigger ID: ' + ct.triggerid);
            lines.push('Name: ' + ct.description);
            lines.push('Severity: ' + ct.priority_label + ' (priority: ' + ct.priority + ')');
            lines.push('Expression: ' + ct.expression);
            if (ct.recovery_expression) {
                lines.push('Recovery expression: ' + ct.recovery_expression);
            }
            if (ct.event_name) {
                lines.push('Event name: ' + ct.event_name);
            }
            if (ct.opdata) {
                lines.push('Operational data: ' + ct.opdata);
            }
            if (ct.comments) {
                lines.push('Description/Comments: ' + ct.comments);
            }
            lines.push('Status: ' + (ct.status === '0' ? 'Enabled' : 'Disabled'));
            lines.push('Manual close: ' + (ct.manual_close === '1' ? 'Yes' : 'No'));
            if (ct.items && ct.items.length) {
                lines.push('Referenced items:');
                ct.items.forEach(function (item) {
                    lines.push('  - ' + item.name + ' [key: ' + item.key_ + ']');
                });
            }
            if (ct.tags && ct.tags.length) {
                lines.push('Tags:');
                ct.tags.forEach(function (tag) {
                    lines.push('  - ' + tag.tag + (tag.value ? ': ' + tag.value : ''));
                });
            }
            if (ct.dependencies && ct.dependencies.length) {
                lines.push('Dependencies:');
                ct.dependencies.forEach(function (dep) {
                    lines.push('  - ' + dep.description);
                });
            }
            lines.push('=== END CURRENT TRIGGER ===');
            lines.push('');
        }

        if (context.current_discovery) {
            var cd = context.current_discovery;
            lines.push('=== CURRENT DISCOVERY RULE BEING EDITED ===');
            lines.push('Discovery rule ID: ' + cd.itemid);
            lines.push('Name: ' + cd.name);
            lines.push('Key: ' + cd.key_);
            lines.push('Type: ' + (cd.type || 'N/A'));
            lines.push('Update interval: ' + (cd.delay || 'N/A'));
            lines.push('Keep lost resources: ' + (cd.lifetime || 'N/A'));
            if (cd.description) {
                lines.push('Description: ' + cd.description);
            }
            if (cd.filter && cd.filter.conditions && cd.filter.conditions.length) {
                lines.push('Filters:');
                cd.filter.conditions.forEach(function (cond) {
                    lines.push('  - Macro: ' + (cond.macro || '') + ', Value: ' + (cond.value || '') + ', Operator: ' + (cond.operator || ''));
                });
            }
            if (cd.lld_macro_paths && cd.lld_macro_paths.length) {
                lines.push('LLD macro paths:');
                cd.lld_macro_paths.forEach(function (mp) {
                    lines.push('  - ' + (mp.lld_macro || '') + ' => ' + (mp.path || ''));
                });
            }
            if (cd.preprocessing && cd.preprocessing.length) {
                lines.push('Preprocessing steps (' + cd.preprocessing.length + '):');
                cd.preprocessing.forEach(function (step, idx) {
                    lines.push('  Step ' + (idx + 1) + ': type=' + step.type);
                    if (step.params) {
                        lines.push('    Params: ' + step.params);
                    }
                });
            }
            lines.push('=== END CURRENT DISCOVERY RULE ===');
            lines.push('');
        }

        // Also include DOM-extracted form values (captures unsaved changes).
        if (context.form_values) {
            var fv = context.form_values;
            lines.push('--- Current form field values (from the open dialog) ---');
            for (var field in fv) {
                if (field === 'preprocessing' || field === 'tags') continue;
                var val = fv[field];
                if (typeof val === 'object' && val !== null && val.label) {
                    lines.push(field + ': ' + val.label + ' (value: ' + val.value + ')');
                } else if (typeof val === 'string' && val) {
                    lines.push(field + ': ' + val);
                }
            }
            if (fv.tags && fv.tags.length) {
                lines.push('Form tags:');
                fv.tags.forEach(function (t) {
                    lines.push('  - ' + t.tag + (t.value ? ': ' + t.value : ''));
                });
            }
            lines.push('---');
            lines.push('');
        }

        if (formType === 'item' || formType === 'item_prototype') {
            if (isEditing) {
                lines.push('IMPORTANT: You are helping EDIT an existing Zabbix ITEM. The full item details including preprocessing steps are provided above.');
                lines.push('You have access to ALL current configuration including:');
                lines.push('- Item name, key, type, value type, intervals, units');
                lines.push('- All preprocessing steps with their full parameters (including JavaScript code)');
                lines.push('- Tags and description');
                lines.push('');
                lines.push('When suggesting preprocessing improvements:');
                lines.push('- Show the complete preprocessing chain you recommend');
                lines.push('- For JavaScript preprocessing, provide the complete code');
                lines.push('- Explain what each step does and why');
                lines.push('- For Zabbix preprocessing steps, use the format:');
                lines.push('  **Preprocessing step N:** <type> (e.g., JavaScript, Regular expression, Replace, Discard unchanged with heartbeat)');
                lines.push('  **Parameters:** <full parameters/code>');
                lines.push('  **Error handling:** <action if step fails>');
            } else {
                lines.push('IMPORTANT: You are helping create a Zabbix ITEM. Provide specific values for these fields:');
            }
            lines.push('- Name: The display name for this item');
            lines.push('- Type: The item type (Zabbix agent, Zabbix agent (active), SNMP agent, Simple check, etc.)');
            lines.push('- Key: The item key (e.g., system.cpu.load[avg5], vfs.fs.size[/,pfree], net.if.in[eth0])');
            lines.push('- Type of information: (Numeric unsigned, Numeric float, Character, Log, Text)');
            lines.push('- Units: The units for the value (e.g., %, B, bps, unixtime)');
            lines.push('- Update interval: How often to collect data (e.g., 1m, 30s, 5m)');
            lines.push('- History storage period: How long to keep detailed data (e.g., 7d, 14d, 90d)');
            lines.push('- Trend storage period: How long to keep trends (e.g., 365d)');
            lines.push('- Preprocessing: Steps to transform collected data (JavaScript, regex, trim, discard unchanged, etc.)');
            lines.push('- Description: A helpful description of what this item monitors');
            if (formType === 'item_prototype') {
                lines.push('- For item prototypes, use LLD macros like {#FSNAME}, {#IFNAME}, {#SNMPINDEX} in keys and names');
            }
            lines.push('');
            lines.push('Format your response with each field on its own line like:');
            lines.push('**Name:** <value>');
            lines.push('**Type:** <value>');
            lines.push('**Key:** <value>');
            lines.push('etc.');
        }

        if (formType === 'trigger' || formType === 'trigger_prototype') {
            lines.push('IMPORTANT: You are helping create a Zabbix TRIGGER. Provide specific values for these fields:');
            lines.push('- Name: The trigger name (can use macros like {HOST.NAME})');
            lines.push('- Severity: (Not classified, Information, Warning, Average, High, Disaster)');
            lines.push('- Expression: The trigger expression using Zabbix syntax');
            lines.push('  Common trigger functions: last(), avg(), min(), max(), diff(), change(), nodata(), count(), percentile()');
            lines.push('  Expression format: last(/hostname/key)>threshold');
            lines.push('  Example: last(/host/system.cpu.load[avg5])>5');
            lines.push('  Example: avg(/host/system.cpu.load[avg5],5m)>3');
            lines.push('  Example: last(/host/vfs.fs.size[/,pfree])<10');
            lines.push('  Example: nodata(/host/agent.ping,5m)=1');
            lines.push('- Recovery expression: (optional) expression for recovery');
            lines.push('- Description: Operational notes about this trigger');
            lines.push('- Allow manual close: whether users can manually close the problem');
            if (formType === 'trigger_prototype') {
                lines.push('- For trigger prototypes, use LLD macros in the expression');
            }
            lines.push('');
            lines.push('Format your response with each field on its own line like:');
            lines.push('**Name:** <value>');
            lines.push('**Severity:** <value>');
            lines.push('**Expression:** <value>');
            lines.push('etc.');
        }

        if (formType === 'discovery') {
            lines.push('IMPORTANT: You are helping create a Zabbix LOW-LEVEL DISCOVERY RULE. Provide specific values for:');
            lines.push('- Name: The display name for this discovery rule');
            lines.push('- Type: (Zabbix agent, Zabbix agent (active), SNMP agent, etc.)');
            lines.push('- Key: The discovery key (e.g., vfs.fs.discovery, net.if.discovery, system.sw.packages.get)');
            lines.push('  Common discovery keys:');
            lines.push('  - vfs.fs.discovery - filesystem discovery');
            lines.push('  - net.if.discovery - network interface discovery');
            lines.push('  - system.cpu.discovery - CPU discovery');
            lines.push('  - vfs.dev.discovery - block device discovery');
            lines.push('  - For SNMP: discovery[{#SNMPVALUE},OID]');
            lines.push('- Update interval: How often to run discovery (e.g., 1h, 30m)');
            lines.push('- Keep lost resources period: (e.g., 30d)');
            lines.push('- LLD macros: What macros will be returned (e.g., {#FSNAME}, {#FSTYPE}, {#IFNAME})');
            lines.push('- Filters: Any filters to apply to discovered entities');
            lines.push('');
            lines.push('Format your response with each field on its own line like:');
            lines.push('**Name:** <value>');
            lines.push('**Type:** <value>');
            lines.push('**Key:** <value>');
            lines.push('etc.');
        }

        if (formType === 'history') {
            lines.push('IMPORTANT: You are helping analyze Zabbix ITEM HISTORY DATA.');
            lines.push('The user is viewing the history/graph page for a monitored item.');
            lines.push('You have access to the item details and recent history values.');
            lines.push('');
            lines.push('Help the user by:');
            lines.push('- Analyzing trends, patterns, anomalies, and spikes in the data');
            lines.push('- Identifying potential problems or threshold violations');
            lines.push('- Suggesting trigger expressions based on the observed data patterns');
            lines.push('- Explaining what the values mean in context');
            lines.push('- Recommending monitoring improvements (thresholds, intervals, preprocessing)');
            lines.push('');
            lines.push('When suggesting trigger improvements, format them as copyable values.');
        }

        // Add host context.
        if (context.host) {
            lines.push('');
            lines.push('--- Host/Template information ---');
            lines.push((context.host.is_template ? 'Template' : 'Host') + ': ' + (context.host.name || context.host.host));

            if (context.host.interfaces && context.host.interfaces.length) {
                var ifaceTypes = { '1': 'Agent', '2': 'SNMP', '3': 'IPMI', '4': 'JMX' };
                context.host.interfaces.forEach(function (iface) {
                    lines.push('Interface: ' + (ifaceTypes[iface.type] || 'Unknown') + ' - ' + (iface.ip || iface.dns) + ':' + iface.port);
                });
            }

            if (context.host.parent_templates && context.host.parent_templates.length) {
                lines.push('Linked templates: ' + context.host.parent_templates.map(function (t) { return t.name; }).join(', '));
            }
        }

        // Add existing items context (summarized).
        if (context.items && context.items.length) {
            lines.push('');
            lines.push('--- Existing items on this host (' + context.items.length + ' total) ---');
            // Include up to 50 items for AI context.
            var itemsToShow = context.items.slice(0, 50);
            itemsToShow.forEach(function (item) {
                lines.push('- ' + item.name + ' [key: ' + item.key_ + ']');
            });
            if (context.items.length > 50) {
                lines.push('... and ' + (context.items.length - 50) + ' more items');
            }
        }

        // Add existing triggers context for trigger creation.
        if (context.triggers && context.triggers.length && (formType === 'trigger' || formType === 'trigger_prototype')) {
            lines.push('');
            lines.push('--- Existing triggers on this host (' + context.triggers.length + ' total) ---');
            var triggersToShow = context.triggers.slice(0, 30);
            triggersToShow.forEach(function (trigger) {
                lines.push('- ' + trigger.description + ' [expression: ' + trigger.expression + ']');
            });
            if (context.triggers.length > 30) {
                lines.push('... and ' + (context.triggers.length - 30) + ' more triggers');
            }
        }

        // Add discovery rules context.
        if (context.discovery_rules && context.discovery_rules.length && formType === 'discovery') {
            lines.push('');
            lines.push('--- Existing discovery rules (' + context.discovery_rules.length + ') ---');
            context.discovery_rules.forEach(function (rule) {
                lines.push('- ' + rule.name + ' [key: ' + rule.key_ + ']');
            });
        }

        // For trigger forms, emphasize using the actual host/template name in expressions.
        if (formType === 'trigger' || formType === 'trigger_prototype') {
            lines.push('');
            if (context.host) {
                lines.push('IMPORTANT: In trigger expressions, use the host technical name "' + (context.host.host || '') + '" (e.g., last(/' + (context.host.host || 'hostname') + '/key)>threshold)');
                lines.push('Use ONLY item keys that exist on this host (listed above) in the trigger expression.');
            }
        }

        // For history pages, include recent history data, triggers, macros, and problems.
        if (formType === 'history') {
            if (context.item_history && context.item_history.length) {
                lines.push('');
                var periodLabel = context.history_params ? 'last ' + context.history_params.period_hours + 'h' : 'last 24h';
                lines.push('--- Recent item history values (' + context.item_history.length + ' data points, ' + periodLabel + ') ---');
                context.item_history.forEach(function (h) {
                    lines.push('  ' + h.time + '  =>  ' + h.value);
                });
            }

            if (context.item_triggers && context.item_triggers.length) {
                lines.push('');
                lines.push('--- Triggers associated with this item (' + context.item_triggers.length + ') ---');
                context.item_triggers.forEach(function (t) {
                    lines.push('- ' + t.description + ' [severity: ' + t.priority_label + ', status: ' + t.status + ', state: ' + t.value + ']');
                    lines.push('  Expression: ' + t.expression);
                    lines.push('  Last change: ' + t.lastchange);
                });
            }

            // Host macros — critical for understanding trigger thresholds.
            if (context.host_macros && context.host_macros.length) {
                lines.push('');
                lines.push('--- Host macros (used in trigger expressions) ---');
                lines.push('These macros resolve to specific values on this host and are used in trigger expressions above.');
                lines.push('The user can adjust these macros per-host to tune trigger thresholds without changing the template trigger.');
                context.host_macros.forEach(function (m) {
                    var desc = m.description ? ' (' + m.description + ')' : '';
                    var src = m.source !== 'host' ? ' [from: ' + m.source + ']' : '';
                    lines.push('  ' + m.macro + ' = ' + m.value + desc + src);
                });
                lines.push('');
                lines.push('IMPORTANT for analysis:');
                lines.push('- When trigger expressions use macros like {$MACRO_NAME}, look up the actual value from the macros list above');
                lines.push('- Recommend macro value changes (per-host) rather than trigger expression changes when possible');
                lines.push('- Suggest creating new host-level macros to override template defaults for specific tuning');
            }

            // Recent problem events — shows trigger firing patterns.
            if (context.recent_problems && context.recent_problems.total_events > 0) {
                lines.push('');
                lines.push('--- Recent problem events (last ' + context.recent_problems.period_days + ' days, ' + context.recent_problems.total_events + ' events) ---');

                // Per-trigger summary.
                if (context.recent_problems.per_trigger) {
                    lines.push('Problem frequency summary:');
                    for (var tid in context.recent_problems.per_trigger) {
                        var pt = context.recent_problems.per_trigger[tid];
                        lines.push('  - ' + pt.name + ': ' + pt.count + ' events');
                    }
                }

                // Show recent events (up to 30).
                lines.push('');
                lines.push('Recent events (newest first):');
                var eventsToShow = context.recent_problems.events.slice(0, 30);
                eventsToShow.forEach(function (evt) {
                    lines.push('  ' + evt.time + ' | duration: ' + evt.duration + ' | ' + evt.name);
                });
                if (context.recent_problems.events.length > 30) {
                    lines.push('  ... and ' + (context.recent_problems.events.length - 30) + ' more events');
                }

                lines.push('');
                lines.push('Use this problem frequency data to:');
                lines.push('- Identify time-of-day patterns (e.g., business hours vs off-hours)');
                lines.push('- Assess if the trigger threshold is appropriate for this host');
                lines.push('- Recommend macro adjustments to reduce false positives');
                lines.push('- Suggest time-based trigger modifications if the pattern is predictable');
            }
        }

        return lines.join('\n');
    }

    // ── Transcript rendering ──
    function renderTranscript(formType, hostid) {
        var transcript = document.getElementById('ai-config-transcript');
        if (!transcript) return;

        var chat = getConfigChat(formType, hostid);
        transcript.innerHTML = '';

        if (!chat.history.length) {
            var empty = document.createElement('div');
            empty.className = 'ai-empty-state';
            empty.textContent = 'Describe what you want to create and the AI will suggest the configuration.';
            transcript.appendChild(empty);
            return;
        }

        chat.history.forEach(function (msg) {
            var item = document.createElement('div');
            item.className = 'ai-msg ai-msg-' + msg.role;

            var title = document.createElement('div');
            title.className = 'ai-msg-title';
            title.textContent = msg.role === 'assistant' ? 'AI' : 'You';

            var body = document.createElement('div');
            body.className = 'ai-msg-body';

            if (msg.role === 'assistant') {
                body.innerHTML = formatAssistantMessage(msg.content || '');
            } else {
                var pre = document.createElement('pre');
                pre.textContent = msg.content || '';
                body.appendChild(pre);
            }

            item.appendChild(title);
            item.appendChild(body);
            transcript.appendChild(item);
        });

        transcript.scrollTop = transcript.scrollHeight;
    }

    // Format assistant message with copy buttons for field values.
    function formatAssistantMessage(content) {
        // Convert markdown-style bold field labels to structured HTML.
        var html = escapeHtml(content);

        // Convert **Field:** value patterns into copyable blocks.
        html = html.replace(/\*\*([^*]+):\*\*\s*([^\n]+)/g, function (match, field, value) {
            var cleanValue = value.trim();
            return '<div class="ai-config-field">' +
                '<span class="ai-config-field-label">' + field + ':</span> ' +
                '<span class="ai-config-field-value">' + cleanValue + '</span>' +
                '<button type="button" class="ai-copy-btn" data-copy="' + escapeAttr(cleanValue) + '" title="Copy to clipboard">Copy</button>' +
                '</div>';
        });

        // Convert code blocks.
        html = html.replace(/```([^`]*?)```/g, function (match, code) {
            return '<div class="ai-config-code-block">' +
                '<pre>' + code.trim() + '</pre>' +
                '<button type="button" class="ai-copy-btn ai-copy-block" data-copy="' + escapeAttr(code.trim()) + '" title="Copy to clipboard">Copy</button>' +
                '</div>';
        });

        // Convert inline code.
        html = html.replace(/`([^`]+)`/g, '<code class="ai-config-inline-code">$1</code>');

        // Convert newlines to line breaks.
        html = html.replace(/\n/g, '<br>');

        return html;
    }

    function showStatus(message, isError) {
        var el = document.getElementById('ai-config-status');
        if (!el) return;
        el.textContent = String(message);
        el.className = 'ai-drawer-status ' + (isError ? 'ai-status-error' : 'ai-status-ok');
    }

    // ── Copy functionality ──
    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.ai-copy-btn');
        if (!copyBtn) return;

        var textToCopy = copyBtn.dataset.copy || '';
        if (!textToCopy) return;

        e.preventDefault();
        e.stopPropagation();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy).then(function () {
                showCopyFeedback(copyBtn, 'Copied!');
            }).catch(function () {
                fallbackCopy(textToCopy, copyBtn);
            });
        } else {
            fallbackCopy(textToCopy, copyBtn);
        }
    });

    function fallbackCopy(text, btn) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showCopyFeedback(btn, 'Copied!');
        } catch (err) {
            showCopyFeedback(btn, 'Failed');
        }
        document.body.removeChild(textarea);
    }

    function showCopyFeedback(btn, text) {
        var originalText = btn.textContent;
        btn.textContent = text;
        btn.classList.add('ai-copy-success');
        setTimeout(function () {
            btn.textContent = originalText;
            btn.classList.remove('ai-copy-success');
        }, 1500);
    }

    // ── Utilities ──
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function handleJsonResponse(response) {
        return response.text().then(function (text) {
            var parsed;
            try { parsed = JSON.parse(text); } catch (e) { parsed = null; }

            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                try {
                    var inner = JSON.parse(parsed.main_block);
                    if (inner && typeof inner === 'object') {
                        parsed = inner;
                    }
                } catch (e) {}
            }

            if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'object' && parsed.main_block !== null) {
                parsed = parsed.main_block;
            }

            if (!parsed || typeof parsed !== 'object') {
                var snippet = (text || '').substring(0, 200);
                return { ok: false, error: 'Invalid response from server: ' + snippet };
            }

            if (!response.ok && !parsed.error) {
                parsed.error = 'HTTP ' + response.status;
            }

            return parsed;
        });
    }

    // ── Detect and inject AI button into full-page forms ──
    function detectFullPageForm() {
        // Skip if an overlay dialog is open — overlays get their own AI button
        // via injectAIButton(), so we don't want a duplicate in the page.
        if (document.querySelector('.overlay-dialogue .ai-config-btn')) {
            return;
        }

        // Detect the form type from the Zabbix URL action parameter.
        var params = new URLSearchParams(location.search);
        var action = params.get('action') || '';
        var scriptName = location.pathname.split('/').pop() || '';

        var formTypeFromAction = {
            'item.edit': 'item',
            'trigger.edit': 'trigger',
            'host_discovery.edit': 'discovery',
            'item.prototype.edit': 'item_prototype',
            'trigger.prototype.edit': 'trigger_prototype'
        };

        var formType = formTypeFromAction[action] || null;

        // ── History page detection (history.php) ──
        if (!formType && scriptName === 'history.php') {
            formType = 'history';
        }

        // ── Discovery rule page detection (host_discovery.php) ──
        if (!formType && scriptName === 'host_discovery.php') {
            // Check if we're on a discovery rule edit form (not the list).
            // The edit form has form fields like name, key, etc.
            var hasDiscoveryForm = document.querySelector('input[name="name"]') &&
                (document.querySelector('input[name="key"]') || document.querySelector('input[name="key_"]'));
            if (hasDiscoveryForm) {
                formType = 'discovery';
            }
        }

        // If not matched by action or script, try to detect from page content.
        if (!formType) {
            // Check page title / heading.
            var pageTitle = document.querySelector('.header-title h1, .page-title-general, h1');
            if (pageTitle) {
                var titleText = (pageTitle.textContent || '').trim().toLowerCase();
                for (var pattern in DIALOG_TITLE_MAP) {
                    if (titleText === pattern || titleText.indexOf(pattern) !== -1) {
                        formType = DIALOG_TITLE_MAP[pattern];
                        break;
                    }
                }
            }

            // Check for tab navigation that matches item/trigger/discovery forms.
            if (!formType) {
                var tabs = document.querySelectorAll('.ui-tabs-nav a, .cssmenu-actions a, [role="tablist"] a, .tabs-nav a');
                var tabTexts = [];
                tabs.forEach(function (t) { tabTexts.push((t.textContent || '').trim().toLowerCase()); });
                var tabStr = tabTexts.join(' ');

                if (tabStr.indexOf('preprocessing') !== -1 && tabStr.indexOf('lld macros') !== -1) {
                    formType = 'discovery';
                } else if (tabStr.indexOf('preprocessing') !== -1 && tabStr.indexOf('item') !== -1) {
                    formType = 'item';
                } else if (tabStr.indexOf('preprocessing') !== -1 && (tabStr.indexOf('key') !== -1 || document.querySelector('input[name="key"]'))) {
                    formType = 'item';
                } else if (tabStr.indexOf('dependencies') !== -1 && (tabStr.indexOf('trigger') !== -1 || document.querySelector('textarea[name="expression"]'))) {
                    formType = 'trigger';
                }
            }

            // Last resort: check for characteristic form elements.
            if (!formType) {
                if (document.querySelector('input[name="key"]') && document.querySelector('input[name="delay"]')) {
                    // Discovery rules have LLD macros tab or filter tab.
                    var hasLLD = document.querySelector('[id*="lld"], [class*="lld"]') ||
                        (document.querySelector('a[href*="lld_macro"]') || document.querySelector('.ui-tabs-nav a'));
                    var tabLinks = document.querySelectorAll('.ui-tabs-nav a, [role="tab"] a');
                    var hasLLDTab = false;
                    tabLinks.forEach(function (t) {
                        if ((t.textContent || '').toLowerCase().indexOf('lld') !== -1) hasLLDTab = true;
                    });

                    if (hasLLDTab) {
                        formType = 'discovery';
                    } else if (document.querySelector('input[name="parent_discoveryid"]')) {
                        formType = 'item_prototype';
                    } else {
                        formType = 'item';
                    }
                } else if (document.querySelector('textarea[name="expression"]') && document.querySelector('input[name="priority"]')) {
                    formType = document.querySelector('input[name="parent_discoveryid"]') ? 'trigger_prototype' : 'trigger';
                }
            }
        }

        if (!formType) return;

        // Don't inject twice.
        if (document.querySelector('.ai-config-btn-page')) return;

        // Find a suitable place to inject the AI button.
        var buttonBar = null;

        if (formType === 'history') {
            // History page: inject next to the time filter / Zoom out / period area.
            // Look for the container that holds the time navigation (< Zoom out > Yesterday).
            var zoomBtn = document.querySelector('button[title="Zoom out"], button.btn-time-zoomout');
            if (zoomBtn) {
                buttonBar = zoomBtn.parentElement;
            }
            if (!buttonBar) {
                // Try the time period tab area.
                buttonBar = document.querySelector('.btn-time, [class*="time-period"]');
                if (buttonBar) buttonBar = buttonBar.parentElement;
            }
            if (!buttonBar) {
                // Fallback: content controls nav.
                buttonBar = document.querySelector('nav[aria-label="Content controls"]');
            }
        }

        if (!buttonBar) {
            // Try the filter area (where the filter button and AI button appear on list pages).
            var filterBtn = document.querySelector('.btn-filter, [class*="filter-trigger"]');
            if (filterBtn && filterBtn.parentElement) {
                buttonBar = filterBtn.parentElement;
            }
        }

        if (!buttonBar) {
            // Try the button footer bar (where Update/Clone/Delete buttons are).
            buttonBar = document.querySelector('.tfoot-buttons, .table-forms-td-right .btn-group, .form-actions');
        }

        if (!buttonBar) {
            var updateBtn = document.querySelector('button[name="update"], button[name="add"], input[name="update"], input[name="add"]');
            if (updateBtn) {
                buttonBar = updateBtn.parentElement;
            }
        }

        if (!buttonBar) {
            buttonBar = document.querySelector('.ui-tabs-nav, .tabs-nav, [role="tablist"]');
        }

        if (!buttonBar) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ai-config-btn ai-config-btn-page';
        btn.title = formType === 'history'
            ? 'Ask AI to analyze this item\'s history'
            : 'Get AI help with this ' + (FORM_TYPES[formType] ? FORM_TYPES[formType].label : 'configuration');
        btn.textContent = 'AI';
        btn.dataset.formType = formType;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            e.preventDefault();
            openConfigDrawer(formType);
        });

        buttonBar.appendChild(btn);
    }

    // ── MutationObserver for overlay dialogs and dynamic form loads ──
    function observeOverlays() {
        var target = document.body;
        var pendingFullPageCheck = false;

        var observer = new MutationObserver(function (mutations) {
            var foundOverlay = false;

            for (var i = 0; i < mutations.length; i++) {
                var addedNodes = mutations[i].addedNodes;
                for (var j = 0; j < addedNodes.length; j++) {
                    var node = addedNodes[j];
                    if (node.nodeType !== 1) continue;

                    // Check if this is an overlay dialog.
                    var overlays = [];

                    if (node.classList && node.classList.contains('overlay-dialogue')) {
                        overlays.push(node);
                    }

                    // Also check children.
                    var childOverlays = node.querySelectorAll ? node.querySelectorAll('.overlay-dialogue') : [];
                    for (var k = 0; k < childOverlays.length; k++) {
                        overlays.push(childOverlays[k]);
                    }

                    overlays.forEach(function (overlay) {
                        foundOverlay = true;
                        // Small delay to let the dialog content load.
                        setTimeout(function () {
                            var formType = detectFormType(overlay);
                            if (formType) {
                                injectAIButton(overlay, formType);
                            }
                        }, 300);
                    });

                    // Also detect dynamically loaded full-page forms
                    // (e.g., clicking an item in item.list loads the form via AJAX).
                    if (!foundOverlay && !pendingFullPageCheck) {
                        // Check if a form-like element was added.
                        var hasFormContent = false;
                        if (node.querySelector) {
                            hasFormContent = !!(
                                node.querySelector('input[name="key"], input[name="itemid"], textarea[name="expression"], input[name="triggerid"]') ||
                                (node.tagName === 'FORM' && (node.querySelector('input[name="key"]') || node.querySelector('textarea[name="expression"]')))
                            );
                        }
                        if (hasFormContent) {
                            pendingFullPageCheck = true;
                            setTimeout(function () {
                                pendingFullPageCheck = false;
                                detectFullPageForm();
                            }, 400);
                        }
                    }
                }
            }
        });

        observer.observe(target, {
            childList: true,
            subtree: true
        });

        // Also check for existing overlays and forms on page load.
        setTimeout(function () {
            var existingOverlays = document.querySelectorAll('.overlay-dialogue');
            existingOverlays.forEach(function (overlay) {
                var formType = detectFormType(overlay);
                if (formType) {
                    injectAIButton(overlay, formType);
                }
            });
            // Re-check for full-page forms that may have loaded.
            detectFullPageForm();
        }, 500);
    }

    // ── Init ──
    function init() {
        // Detect full-page item/trigger/discovery forms.
        detectFullPageForm();

        // Observe for overlay dialogs (popup forms).
        observeOverlays();

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activeDrawer) {
                closeDrawer();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
