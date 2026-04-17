(function() {
    'use strict';

    const COLUMNS = {
        added: [
            {key: 'timestamp', label: 'Time', fmt: formatTime},
            {key: 'host', label: 'Host'},
            {key: 'os', label: 'OS'},
            {key: 'sync_id', label: 'Sync'},
            {key: 'target_type', label: 'Target'},
            {key: 'target_name', label: 'Name'},
            {key: 'field', label: 'Field'},
            {key: 'new_value', label: 'Value'}
        ],
        changed: [
            {key: 'timestamp', label: 'Time', fmt: formatTime},
            {key: 'host', label: 'Host'},
            {key: 'os', label: 'OS'},
            {key: 'sync_id', label: 'Sync'},
            {key: 'target_type', label: 'Target'},
            {key: 'target_name', label: 'Name'},
            {key: 'field', label: 'Field'},
            {key: 'old_value', label: 'Old value'},
            {key: 'new_value', label: 'New value'}
        ],
        removed: [
            {key: 'timestamp', label: 'Time', fmt: formatTime},
            {key: 'host', label: 'Host'},
            {key: 'os', label: 'OS'},
            {key: 'sync_id', label: 'Sync'},
            {key: 'target_type', label: 'Target'},
            {key: 'target_name', label: 'Name'},
            {key: 'old_value', label: 'Removed value'}
        ],
        error: [
            {key: 'timestamp', label: 'Time', fmt: formatTime},
            {key: 'host', label: 'Host'},
            {key: 'sync_id', label: 'Sync'},
            {key: 'message', label: 'Message'}
        ]
    };

    const TAB_TO_TYPE = {added: 'added', changed: 'changed', removed: 'removed', error: 'error'};
    const FACET_FIELDS = ['host', 'os', 'target_type', 'sync_id', 'field', 'disk_name'];

    let state = {
        tab: 'added',
        offset: 0,
        limit: 250,
        items: [],
        hasMore: false,
        columnFilters: {},
        tabCounts: {added: 0, changed: 0, removed: 0, error: 0}
    };

    let root;
    let fetchUrl;
    let clearUrl;

    function qs(sel, r) {
        return (r || document).querySelector(sel);
    }

    function qsa(sel, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(sel));
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatTime(value) {
        if (!value) return '';
        const d = new Date(value);
        if (isNaN(d.getTime())) return value;
        const pad = function(n) { return n < 10 ? '0' + n : String(n); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function showStatus(message, level) {
        const box = qs('#nbs-log-status');
        if (!box) return;
        box.hidden = false;
        box.className = 'nbs-status is-' + (level || 'ok');
        box.textContent = message;
    }

    function hideStatus() {
        const box = qs('#nbs-log-status');
        if (box) box.hidden = true;
    }

    function selectedValues(select) {
        if (!select) return [];
        return Array.prototype.slice.call(select.selectedOptions).map(function(opt) {
            return opt.value;
        }).filter(function(v) { return v !== ''; });
    }

    function buildFilters() {
        const filters = {};
        filters.since = qs('#nbs-log-since').value;
        filters.until = qs('#nbs-log-until').value;
        const q = qs('#nbs-log-q').value.trim();
        if (q) filters.q = q;

        qsa('.nbs-facet-select').forEach(function(sel) {
            const field = sel.getAttribute('data-facet-field');
            const values = selectedValues(sel);
            if (values.length > 0) {
                filters[field] = values;
            }
        });

        Object.keys(state.columnFilters).forEach(function(key) {
            const value = (state.columnFilters[key] || '').trim();
            if (!value) return;
            if (filters[key]) {
                if (Array.isArray(filters[key])) {
                    filters[key].push(value);
                } else {
                    filters[key] = [filters[key], value];
                }
            } else {
                filters[key] = value;
            }
        });

        return filters;
    }

    function buildRequestUrl(filters, extra) {
        const params = new URLSearchParams();
        params.set('type', TAB_TO_TYPE[state.tab] || 'added');

        Object.keys(filters).forEach(function(key) {
            const value = filters[key];
            if (Array.isArray(value)) {
                value.forEach(function(v) {
                    params.append(key + '[]', v);
                });
            } else if (value !== '' && value !== null && value !== undefined) {
                params.set(key, value);
            }
        });

        if (extra) {
            Object.keys(extra).forEach(function(k) { params.set(k, extra[k]); });
        }

        const sep = fetchUrl.indexOf('?') === -1 ? '?' : '&';
        return fetchUrl + sep + params.toString();
    }

    async function fetchItems(append) {
        const filters = buildFilters();
        const offset = append ? state.offset : 0;
        const url = buildRequestUrl(filters, {limit: state.limit, offset: offset});

        showStatus('Loading…', 'warn');

        let response;
        try {
            response = await fetch(url, {credentials: 'same-origin'});
        } catch (err) {
            showStatus('Failed to fetch log: ' + err.message, 'error');
            return;
        }

        let payload;
        try {
            payload = await response.json();
        } catch (err) {
            showStatus('Invalid JSON from log endpoint.', 'error');
            return;
        }

        if (!payload || !payload.ok) {
            showStatus((payload && payload.error) || 'Log fetch failed.', 'error');
            return;
        }

        if (append) {
            state.items = state.items.concat(payload.items || []);
        } else {
            state.items = payload.items || [];
        }

        state.offset = offset + (payload.count || 0);
        state.hasMore = Boolean(payload.has_more);
        hideStatus();

        renderGrid();
        renderFacets(payload.facets || {});
        updatePagerInfo(payload);
        refreshTabCounts();
    }

    async function refreshTabCounts() {
        const filters = buildFilters();
        const tabs = Object.keys(state.tabCounts);
        await Promise.all(tabs.map(async function(tab) {
            const params = Object.assign({}, filters);
            const url = buildRequestUrl(Object.assign({}, params), {limit: 1, offset: 0, mode: 'items'});
            const u = url.replace(/type=[^&]*/, 'type=' + encodeURIComponent(tab));

            try {
                const r = await fetch(u, {credentials: 'same-origin'});
                const p = await r.json();
                if (p && p.ok) {
                    const count = (p.count || 0) + (p.has_more ? '+' : '');
                    state.tabCounts[tab] = count;
                    const el = qs('[data-tab-count="' + tab + '"]');
                    if (el) el.textContent = String(count);
                }
            } catch (e) {
                // ignore
            }
        }));
    }

    function renderGrid() {
        const grid = qs('#nbs-log-grid');
        const heads = qs('.nbs-log-grid-heads', grid);
        const filters = qs('.nbs-log-grid-filters', grid);
        const body = qs('tbody', grid);
        const columns = COLUMNS[state.tab] || COLUMNS.added;

        heads.innerHTML = columns.map(function(col) {
            return '<th>' + escapeHtml(col.label) + '</th>';
        }).join('');

        filters.innerHTML = columns.map(function(col) {
            const existing = escapeHtml(state.columnFilters[col.key] || '');
            return '<th><input type="search" class="nbs-log-col-filter"'
                + ' data-col="' + escapeHtml(col.key) + '"'
                + ' value="' + existing + '"'
                + ' placeholder="filter"></th>';
        }).join('');

        if (!state.items.length) {
            body.innerHTML = '<tr><td class="nbs-log-empty" colspan="' + columns.length + '">No rows match the current filters.</td></tr>';
            return;
        }

        body.innerHTML = state.items.map(function(item) {
            return '<tr>' + columns.map(function(col) {
                const raw = item[col.key];
                const value = col.fmt ? col.fmt(raw) : (raw === null || raw === undefined ? '' : String(raw));
                return '<td title="' + escapeHtml(value) + '">' + escapeHtml(value) + '</td>';
            }).join('') + '</tr>';
        }).join('');

        qsa('.nbs-log-col-filter', filters).forEach(function(input) {
            input.addEventListener('input', debounce(function() {
                state.columnFilters[input.getAttribute('data-col')] = input.value;
                state.offset = 0;
                fetchItems(false);
            }, 300));
        });
    }

    function renderFacets(facets) {
        FACET_FIELDS.forEach(function(field) {
            const select = qs('.nbs-facet-select[data-facet-field="' + field + '"]');
            if (!select) return;
            const previous = selectedValues(select);
            const values = facets[field] || [];
            const options = values.map(function(entry) {
                const val = typeof entry === 'object' ? entry.value : entry;
                const count = typeof entry === 'object' ? entry.count : '';
                const sel = previous.indexOf(String(val)) !== -1 ? ' selected' : '';
                const label = count !== '' ? (val + ' (' + count + ')') : val;
                return '<option value="' + escapeHtml(val) + '"' + sel + '>' + escapeHtml(label) + '</option>';
            }).join('');
            select.innerHTML = options || '<option value="" disabled>No values yet</option>';
        });
    }

    function updatePagerInfo(payload) {
        const info = qs('#nbs-log-pager-info');
        const more = qs('#nbs-log-load-more');
        const count = qs('#nbs-log-count');

        if (info) {
            info.textContent = 'Showing ' + state.items.length + (state.hasMore ? '+ rows (window capped)' : ' rows');
        }
        if (more) {
            more.hidden = !state.hasMore;
        }
        if (count) {
            count.textContent = state.items.length + ' rows';
        }
    }

    function debounce(fn, ms) {
        let timer = null;
        return function() {
            const args = arguments;
            const ctx = this;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, ms);
        };
    }

    function setActiveTab(tab) {
        state.tab = tab;
        state.columnFilters = {};
        state.offset = 0;
        qsa('.nbs-log-tab').forEach(function(btn) {
            const isActive = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        fetchItems(false);
    }

    async function clearLog() {
        if (!window.confirm('This will delete every NetBox sync log file on disk. Continue?')) {
            return;
        }

        const form = new FormData();
        const name = root.getAttribute('data-csrf-name');
        const token = root.getAttribute('data-csrf-clear');
        if (name && token) {
            form.append(name, token);
        }

        showStatus('Clearing log…', 'warn');

        const response = await fetch(clearUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        });

        let payload;
        try {
            payload = await response.json();
        } catch (err) {
            showStatus('Invalid JSON from clear endpoint.', 'error');
            return;
        }

        if (!payload || !payload.ok) {
            showStatus((payload && payload.error) || 'Clear failed.', 'error');
            return;
        }

        showStatus('Removed ' + (payload.removed || 0) + ' log files.', 'ok');
        fetchItems(false);
    }

    function initTabs() {
        qsa('.nbs-log-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setActiveTab(btn.getAttribute('data-tab'));
            });
        });
    }

    function initControls() {
        qs('#nbs-log-refresh').addEventListener('click', function() {
            state.offset = 0;
            fetchItems(false);
        });
        qs('#nbs-log-apply').addEventListener('click', function() {
            state.offset = 0;
            fetchItems(false);
        });
        qs('#nbs-log-reset').addEventListener('click', function() {
            qs('#nbs-log-q').value = '';
            qsa('.nbs-facet-select').forEach(function(sel) {
                Array.prototype.slice.call(sel.options).forEach(function(opt) { opt.selected = false; });
            });
            state.columnFilters = {};
            state.offset = 0;
            fetchItems(false);
        });
        qs('#nbs-log-load-more').addEventListener('click', function() {
            fetchItems(true);
        });
        qs('#nbs-log-clear').addEventListener('click', function() {
            clearLog().catch(function(err) {
                showStatus(err.message || String(err), 'error');
            });
        });
        qs('#nbs-log-q').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                state.offset = 0;
                fetchItems(false);
            }
        });
    }

    function init() {
        root = qs('#nbs-log-root');
        if (!root) return;

        fetchUrl = root.getAttribute('data-fetch-url');
        clearUrl = root.getAttribute('data-clear-url');

        initTabs();
        initControls();
        fetchItems(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
