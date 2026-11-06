(function () {
    'use strict';
    // Wait well past the server's 20-second collection budget: it is checked only between API calls,
    // so a query started near its end can still finish.
    var ROOT = 'motd-banner-root', REFRESH = 60000, TIMEOUT = 60000;
    var labels = {
        expand: 'Expand', collapse: 'Collapse', open_details: 'Open details', updated: 'Updated',
        last_checked: 'Last checked', refreshing: 'Refreshing…', refresh: 'Refresh',
        refresh_failed: 'Refresh failed. Try again.', stale: 'Showing the last available data.',
        partial: 'Some data is unavailable.', unavailable: 'Reminder data is unavailable.',
        snooze: 'Hide banner for 1 hour', resume: 'Show banner again', snoozed: 'Banner hidden until',
        host_group: 'Host group', scope: 'Showing data for', all_hosts: 'All accessible hosts', tag: 'Tag',
        idle_paused: 'Automatic refresh paused while you are inactive.'
    };
    var ui = Object.assign({}, labels), memory = {local: {}, session: {}};
    var overview, preferenceKey = '', preferencesReady = false, fingerprint = '', latest = null;
    var filters = {groupid: '0', tag: '', tag_value: ''}, collapsed = true, inFlight = null;
    var requestId = 0, refreshTimer, lastChecked = '', failed = false;
    var activityVersion = 0, consumedActivity = -1, lastActivity = Date.now(), idlePaused = false;

    function el(id) { return document.getElementById(id); }
    function key(name) { return 'zbx_motd_v2_' + preferenceKey + '_' + name; }
    function storage(type, name, value) {
        if (arguments.length === 3) {
            memory[type][name] = value;
            try { window[type + 'Storage'].setItem(name, value); } catch (ignore) {}
            return value;
        }
        if (Object.prototype.hasOwnProperty.call(memory[type], name)) { return memory[type][name]; }
        try {
            var saved = window[type + 'Storage'].getItem(name);
            return saved === null ? (memory[type][name] || null) : saved;
        } catch (ignore) { return memory[type][name] || null; }
    }
    function normalize(value) {
        value = value || {};
        return {groupid: /^\d+$/.test(String(value.groupid || '0')) ? String(value.groupid || '0') : '0',
            tag: String(value.tag || '').trim(), tag_value: String(value.tag_value || '').trim()};
    }
    function same(a, b) { return JSON.stringify(normalize(a)) === JSON.stringify(normalize(b)); }
    function savedFilters() {
        try { return normalize(JSON.parse(storage('local', key('filters')) || '{}')); }
        catch (ignore) { return normalize({}); }
    }
    function metadata(data, response) {
        ui = Object.assign({}, labels, data.ui || response.ui || {});
        var nextKey = String(data.preference_key || response.preference_key || '');
        if (nextKey && nextKey !== preferenceKey) {
            preferenceKey = nextKey; preferencesReady = false;
        }
        if (!preferencesReady && preferenceKey) {
            preferencesReady = true;
            var params = new URLSearchParams(window.location.search);
            var explicit = overview && ['groupid', 'tag', 'tag_value'].some(function (name) { return params.has(name); });
            filters = explicit ? normalize(data.filters) : savedFilters();
            if (explicit) { storage('local', key('filters'), JSON.stringify(filters)); }
            syncForm();
            if (!same(filters, data.filters)) { return false; }
        }
        return true;
    }
    function init() {
        if (new URLSearchParams(window.location.search).get('action') === 'motd.banner.data') { return; }
        overview = el('motd-overview-content');
        var bootstrap = el('motd-bootstrap');
        if (overview && bootstrap) {
            try {
                latest = JSON.parse(bootstrap.textContent); filters = normalize(latest.filters);
                fingerprint = metadata(latest, {}) ? String(latest.fingerprint || '') : '';
            } catch (ignore) { latest = null; }
            bindControls(); status();
            var initialDetails = overview.querySelector('.motd-host-details');
            if (initialDetails && window.location.hash === '#motd-monitoring-health') { initialDetails.open = true; }
        }
        ['pointerdown', 'pointermove', 'keydown', 'click'].forEach(function (name) {
            document.addEventListener(name, function (event) {
                if (!event.isTrusted) { return; }
                activityVersion++; lastActivity = Date.now();
                if (idlePaused) { idlePaused = false; status(); }
            }, {capture: true, passive: true});
        });
        refresh(false);
        refreshTimer = window.setInterval(function () { if (!document.hidden) { refresh(false); } }, REFRESH);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(false); } });
        window.addEventListener('storage', function (event) {
            if (!preferenceKey || !event.key || event.key.indexOf('zbx_motd_v2_' + preferenceKey + '_') !== 0) { return; }
            delete memory.local[event.key];
            if (event.key === key('filters')) {
                filters = savedFilters(); fingerprint = ''; syncForm(); refresh(true);
            } else if (event.key === key('snooze')) {
                updateSnooze(); if (!isSnoozed()) { refresh(true); }
            }
        });
        window.addEventListener('beforeunload', function () {
            window.clearInterval(refreshTimer); if (inFlight) { inFlight.controller.abort(); }
        });
    }
    function bindControls() {
        el('motd-refresh').addEventListener('click', function () { refresh(true); });
        el('motd-resume').addEventListener('click', function () { storage('local', key('snooze'), '0'); updateSnooze(); });
        el('motd-snooze').addEventListener('click', snooze);
        el('motd-filter-form').addEventListener('submit', function (event) {
            event.preventDefault();
            filters = normalize({groupid: el('motd-groupid').value, tag: el('motd-tag').value,
                tag_value: el('motd-tag-value').value});
            if (!filters.tag) { filters.tag_value = ''; }
            saveFilters();
        });
        el('motd-filter-reset').addEventListener('click', function () { filters = normalize({}); saveFilters(); });
        syncForm(); updateSnooze();
    }
    function saveFilters() {
        storage('local', key('filters'), JSON.stringify(filters));
        var url = new URL(window.location.href);
        ['groupid', 'tag', 'tag_value'].forEach(function (name) {
            if (filters[name] && !(name === 'groupid' && filters[name] === '0')) { url.searchParams.set(name, filters[name]); }
            else { url.searchParams.delete(name); }
        });
        window.history.replaceState(null, '', url.toString());
        syncForm(); fingerprint = ''; refresh(true);
    }
    function syncForm() {
        if (!overview) { return; }
        var select = el('motd-groupid');
        if (select && !Array.prototype.some.call(select.options, function (option) { return option.value === filters.groupid; })) {
            var option = node('option', '', ui.host_group + ' #' + filters.groupid);
            option.value = filters.groupid; select.appendChild(option);
        }
        if (select) { select.value = filters.groupid; }
        if (el('motd-tag')) { el('motd-tag').value = filters.tag; }
        if (el('motd-tag-value')) { el('motd-tag-value').value = filters.tag_value; }
    }
    function isSnoozed() { return preferenceKey && Number(storage('local', key('snooze'))) > Date.now(); }
    function snooze() { storage('local', key('snooze'), String(Date.now() + 3600000)); updateSnooze(); }
    function updateSnooze() {
        var hidden = isSnoozed();
        // A snoozed banner comes back collapsed, the same as on every page load.
        if (hidden) { collapsed = true; if (el(ROOT)) { el(ROOT).remove(); } }
        if (overview) {
            el('motd-resume').hidden = !hidden; el('motd-snooze').hidden = !!hidden;
            el('motd-snooze-status').textContent = hidden
                ? ui.snoozed + ' ' + new Date(Number(storage('local', key('snooze')))).toLocaleTimeString() : '';
        }
    }
    function refresh(force) {
        if (document.hidden && !force) { return; }
        updateSnooze();
        if (!overview && isSnoozed()) { return; }
        if (force) { activityVersion++; lastActivity = Date.now(); }
        var idleTimeout = latest ? Number(latest.idle_timeout || 0) : 0;
        if (!force && idleTimeout > 0 && (activityVersion <= consumedActivity
                || Date.now() - lastActivity > Math.min(REFRESH, idleTimeout * 1000))) {
            idlePaused = true; status(); return;
        }
        if (inFlight) { if (!force) { return; } inFlight.controller.abort(); }
        idlePaused = false; consumedActivity = activityVersion;
        var id = ++requestId, controller = new AbortController(), timer, retry = false;
        inFlight = {id: id, controller: controller};
        var params = new URLSearchParams({action: 'motd.banner.data', _: String(Date.now()),
            groupid: filters.groupid, tag: filters.tag, tag_value: filters.tag_value});
        if (overview) { params.set('overview', '1'); }
        if (fingerprint && !force) { params.set('fingerprint', fingerprint); }
        status(true);
        var timeout = new Promise(function (resolve, reject) {
            timer = window.setTimeout(function () { controller.abort(); reject(new Error('timeout')); }, TIMEOUT);
        });
        Promise.race([fetch('zabbix.php?' + params.toString(), {credentials: 'same-origin', signal: controller.signal,
            headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(parseResponse), timeout])
            .then(function (response) {
                if (id !== requestId) { return; }
                if (!response || response.ok !== true) { throw new Error('unavailable'); }
                var data = response.not_modified ? Object.assign({}, latest || {}, response) : response.data;
                if (!data || typeof data !== 'object') { throw new Error('invalid data'); }
                if (!metadata(data, response)) { fingerprint = ''; retry = true; return; }
                if (data.status === 'unavailable' && latest && latest.status !== 'unavailable') { throw new Error('unavailable'); }
                if (overview && !response.not_modified && typeof response.html !== 'string') { throw new Error('missing html'); }
                latest = data; fingerprint = String(data.fingerprint || response.fingerprint || '');
                failed = false;
                lastChecked = response.checked_at_text || new Date().toLocaleTimeString();
                if (overview) {
                    if (!response.not_modified) {
                        var details = overview.querySelector('.motd-host-details');
                        var detailsOpen = details && details.open;
                        overview.innerHTML = response.html;
                        details = overview.querySelector('.motd-host-details');
                        if (details && (detailsOpen || window.location.hash === '#motd-monitoring-health')) { details.open = true; }
                    }
                } else if (!isSnoozed() && (!response.not_modified || !el(ROOT))) { renderBanner(data); }
                updateSnooze(); status();
            })
            .catch(function () {
                if (id !== requestId) { return; }
                failed = true;
                if (!overview && !el(ROOT) && !isSnoozed()) {
                    renderBanner({title: "Today's Reminder", status: 'unavailable', summary_line: ui.unavailable});
                }
                status();
            })
            .finally(function () {
                window.clearTimeout(timer);
                if (id === requestId) { inFlight = null; status(); if (retry) { refresh(true); } }
            });
    }
    function status(refreshing) {
        var target = el(overview ? 'motd-overview-status' : 'motd-banner-status'), text = [];
        if (refreshing) { text.push(ui.refreshing); }
        if (idlePaused) { text.push(ui.idle_paused); }
        if (failed) { text.push(ui.refresh_failed); if (latest) { text.push(ui.stale); } }
        else if (latest && latest.status === 'partial') { text.push(ui.partial); }
        else if (latest && latest.status === 'unavailable') { text.push(ui.unavailable); }
        if (latest) {
            text.push(ui.updated + ' ' + (latest.generated_at_text || ui.unavailable) + (latest.timezone ? ' · ' + latest.timezone : ''));
        }
        if (lastChecked) { text.push(ui.last_checked + ' ' + lastChecked); }
        if (target) {
            target.textContent = text.join(' · ');
            target.classList.toggle('motd-status--warning', failed || !!(latest && latest.status !== 'ok'));
        }
        var button = el(overview ? 'motd-refresh' : 'motd-banner-refresh');
        if (button) { button.disabled = !!inFlight; button.textContent = ui.refresh; }
        if (overview) { overview.setAttribute('aria-busy', inFlight ? 'true' : 'false'); }
    }
    function node(tag, className, text) {
        var result = document.createElement(tag);
        if (className) { result.className = className; }
        if (text !== undefined) { result.textContent = text; }
        return result;
    }
    function button(id, text, handler) {
        var result = node('button', '', text); result.type = 'button'; result.id = id;
        result.addEventListener('click', handler); return result;
    }
    // Zabbix 7.0 lays out <body> as a row: the sidebar, then .wrapper (the scrolling page column).
    // Pages built with CHtmlPage have a <main> inside .wrapper; module pages that print their own
    // HTML have none. Never insert into a flex/grid <body>: the banner would become a third column.
    function bannerTarget() {
        var target = document.querySelector('body > .wrapper > main') || document.querySelector('body > .wrapper')
            || document.querySelector('main') || document.querySelector('#page-content');
        if (target) { return target; }
        var body = document.body;
        return body && !/flex|grid/.test(window.getComputedStyle(body).display) ? body : null;
    }
    function renderBanner(data) {
        if (overview || isSnoozed()) { return; }
        var target = bannerTarget();
        if (!target) { return; }
        var existing = el(ROOT), focused = existing && existing.contains(document.activeElement) ? document.activeElement.id : '';
        if (existing) { existing.remove(); }
        var root = node('div'); root.id = ROOT;
        var banner = node('section', 'motd-banner'); banner.setAttribute('aria-label', data.title || "Today's Reminder");
        root.appendChild(banner);
        var header = node('div', 'motd-banner__header'); banner.appendChild(header);
        var titleWrap = node('div'); header.appendChild(titleWrap);
        titleWrap.appendChild(node('div', 'motd-banner__title', data.title || "Today's Reminder"));
        var live = node('div', 'motd-banner__meta motd-status'); live.id = 'motd-banner-status';
        live.setAttribute('role', 'status'); live.setAttribute('aria-live', 'polite'); titleWrap.appendChild(live);
        titleWrap.appendChild(node('div', 'motd-banner__summary', data.summary_line || ui.unavailable));
        if (data.filters) {
            var scope = normalize(data.filters), scopeParts = [];
            if (scope.groupid !== '0') { scopeParts.push(ui.host_group + ' #' + scope.groupid); }
            if (scope.tag) { scopeParts.push(ui.tag + ': ' + scope.tag + (scope.tag_value ? '=' + scope.tag_value : '')); }
            titleWrap.appendChild(node('div', 'motd-subtext', ui.scope + ': ' + (scopeParts.join(' · ') || ui.all_hosts)));
        }
        var actions = node('div', 'motd-banner__actions'); header.appendChild(actions);
        var details = node('a', '', ui.open_details);
        details.href = (data.links && data.links.module) || 'zabbix.php?action=motd.view'; actions.appendChild(details);
        actions.appendChild(button('motd-banner-refresh', ui.refresh, function () { refresh(true); }));
        if (preferenceKey) { actions.appendChild(button('motd-banner-snooze', ui.snooze, snooze)); }
        // Expanding applies to this page only; every page load starts collapsed.
        var toggle = button('motd-banner-toggle', '', function () {
            collapsed = !collapsed; collapse(banner, toggle, body);
        }); actions.appendChild(toggle);
        var body = node('div', 'motd-banner__body'); body.id = 'motd-banner-body'; banner.appendChild(body);
        toggle.setAttribute('aria-controls', body.id);
        if (Array.isArray(data.chips) && data.chips.length) {
            var row = node('div', 'motd-chip-row');
            data.chips.forEach(function (chip) {
                if (!chip) { return; }
                var item = node(chip.url ? 'a' : 'span', 'motd-chip motd-chip--' + (chip.kind || 'info'));
                if (chip.url) { item.href = chip.url; }
                item.appendChild(node('span', 'motd-chip__label', chip.label || ''));
                item.appendChild(node('span', 'motd-chip__value', chip.value == null ? ui.unavailable : String(chip.value)));
                row.appendChild(item);
            }); body.appendChild(row);
        }
        if (Array.isArray(data.banner_items) && data.banner_items.length) {
            var list = node('ul', 'motd-banner__list');
            data.banner_items.forEach(function (item) {
                var li = node('li');
                if (item.url) { var link = node('a', '', item.text || ''); link.href = item.url; li.appendChild(link); }
                else { li.textContent = item.text || ''; }
                list.appendChild(li);
            }); body.appendChild(list);
        }
        if (Array.isArray(data.issues) && data.issues.length) {
            var issues = node('ul', 'motd-issues');
            data.issues.forEach(function (issue) { issues.appendChild(node('li', '', issue.message || ui.partial)); });
            body.appendChild(issues);
        }
        collapse(banner, toggle, body);
        target.insertBefore(root, target.firstChild); status();
        if (focused && el(focused)) { el(focused).focus(); }
    }
    function collapse(banner, toggle, body) {
        banner.classList.toggle('motd-banner--collapsed', !!collapsed); body.hidden = !!collapsed;
        toggle.textContent = collapsed ? ui.expand : ui.collapse;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }
    function parseResponse(response) {
        if (response.ok === false) { throw new Error('http'); }
        return response.text().then(function (text) {
            var parsed = JSON.parse(text);
            return parsed && typeof parsed.main_block === 'string' ? JSON.parse(parsed.main_block) : parsed;
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
