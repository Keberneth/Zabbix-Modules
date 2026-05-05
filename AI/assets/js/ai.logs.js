(function() {
    'use strict';

    // Single column set works for every tab — AI log records share the same
    // shape across categories. Click a row to expand the full JSON.
    var COLUMNS = [
        {key: 'ts', label: 'Time', fmt: formatTime},
        {key: 'category', label: 'Category'},
        {key: 'status', label: 'Status', cls: statusClass},
        {key: 'event', label: 'Event'},
        {key: 'source', label: 'Source'},
        {key: 'tool', label: 'Tool'},
        {key: 'request_id', label: 'Request'},
        {key: 'message', label: 'Message'},
        {key: 'user', label: 'User', fmt: formatUser}
    ];

    var FACET_FIELDS = ['category', 'status', 'source', 'tool'];

    var TABS = ['all', 'chat', 'webhook', 'tools', 'settings_changes', 'errors'];

    var state = {
        tab: 'all',
        offset: 0,
        limit: 250,
        items: [],
        hasMore: false,
        columnFilters: {},
        tabCounts: {}
    };

    var root;
    var fetchUrl;
    var clearUrl;

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
        var d = new Date(value);
        if (isNaN(d.getTime())) return value;
        var pad = function(n) { return n < 10 ? '0' + n : String(n); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function formatUser(value) {
        if (!value || typeof value !== 'object') return value || '';
        return String(value.username || value.name || value.userid || '');
    }

    function statusClass(value) {
        var v = String(value || '').toLowerCase();
        if (v === 'error') return 'ai-log-status-error';
        if (v === 'pending') return 'ai-log-status-warn';
        if (v === 'denied' || v === 'blocked') return 'ai-log-status-warn';
        if (v === 'ok' || v === 'success') return 'ai-log-status-ok';
        return '';
    }

    function showStatus(message, level) {
        var box = qs('#ai-log-status');
        if (!box) return;
        box.hidden = false;
        box.className = 'ai-status is-' + (level || 'ok');
        box.textContent = message;
    }

    function hideStatus() {
        var box = qs('#ai-log-status');
        if (box) box.hidden = true;
    }

    function selectedValues(select) {
        if (!select) return [];
        return Array.prototype.slice.call(select.selectedOptions).map(function(opt) {
            return opt.value;
        }).filter(function(v) { return v !== ''; });
    }

    function buildFilters() {
        var filters = {};
        var since = qs('#ai-log-since').value;
        var until = qs('#ai-log-until').value;
        if (since) filters.since = since;
        if (until) filters.until = until;
        var q = qs('#ai-log-q').value.trim();
        if (q) filters.q = q;

        qsa('.ai-facet-select').forEach(function(sel) {
            var field = sel.getAttribute('data-facet-field');
            var values = selectedValues(sel);
            if (values.length > 0) {
                filters[field] = values;
            }
        });

        Object.keys(state.columnFilters).forEach(function(key) {
            var value = (state.columnFilters[key] || '').trim();
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
        var params = new URLSearchParams();
        params.set('category', state.tab || 'all');

        Object.keys(filters).forEach(function(key) {
            var value = filters[key];
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

        var sep = fetchUrl.indexOf('?') === -1 ? '?' : '&';
        return fetchUrl + sep + params.toString();
    }

    function fetchItems(append) {
        var filters = buildFilters();
        var offset = append ? state.offset : 0;
        var url = buildRequestUrl(filters, {limit: state.limit, offset: offset});

        showStatus('Loading…', 'warn');

        fetch(url, {credentials: 'same-origin'})
            .then(function(response) {
                return response.text().then(function(text) {
                    var parsed = parseJsonSafe(text);
                    if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                        var inner = parseJsonSafe(parsed.main_block);
                        if (inner) parsed = inner;
                    }
                    return parsed || {ok: false, error: 'Invalid response from log endpoint.'};
                });
            })
            .then(function(payload) {
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
            })
            .catch(function(err) {
                showStatus('Failed to fetch log: ' + (err && err.message ? err.message : err), 'error');
            });
    }

    function parseJsonSafe(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    function refreshTabCounts() {
        var filters = buildFilters();

        TABS.forEach(function(tab) {
            // Strip the active 'category' filter to count by tab.
            var url = buildRequestUrl(filters, {limit: 1, offset: 0, mode: 'items'});
            url = url.replace(/category=[^&]*/, 'category=' + encodeURIComponent(tab));

            fetch(url, {credentials: 'same-origin'})
                .then(function(r) { return r.text(); })
                .then(function(text) {
                    var parsed = parseJsonSafe(text);
                    if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                        var inner = parseJsonSafe(parsed.main_block);
                        if (inner) parsed = inner;
                    }
                    if (parsed && parsed.ok) {
                        var count = (parsed.count || 0) + (parsed.has_more ? '+' : '');
                        state.tabCounts[tab] = count;
                        var el = qs('[data-tab-count="' + tab + '"]');
                        if (el) el.textContent = String(count);
                    }
                })
                .catch(function() { /* ignore */ });
        });
    }

    function renderGrid() {
        var grid = qs('#ai-log-grid');
        var heads = qs('.ai-log-grid-heads', grid);
        var filters = qs('.ai-log-grid-filters', grid);
        var body = qs('tbody', grid);

        heads.innerHTML = COLUMNS.map(function(col) {
            return '<th>' + escapeHtml(col.label) + '</th>';
        }).join('');

        filters.innerHTML = COLUMNS.map(function(col) {
            var existing = escapeHtml(state.columnFilters[col.key] || '');
            return '<th><input type="search" class="ai-log-col-filter"'
                + ' data-col="' + escapeHtml(col.key) + '"'
                + ' value="' + existing + '"'
                + ' placeholder="filter"></th>';
        }).join('');

        if (!state.items.length) {
            body.innerHTML = '<tr><td class="ai-log-empty" colspan="' + COLUMNS.length + '">'
                + 'No rows match the current filters.</td></tr>';
            attachColumnFilterHandlers(filters);
            return;
        }

        body.innerHTML = state.items.map(function(item, idx) {
            return '<tr class="ai-log-row" data-row-index="' + idx + '">' + COLUMNS.map(function(col) {
                var raw = item[col.key];
                var value = col.fmt ? col.fmt(raw) : (raw === null || raw === undefined ? '' : String(raw));
                var cls = col.cls ? col.cls(raw) : '';
                var clsAttr = cls ? ' class="' + escapeHtml(cls) + '"' : '';
                return '<td' + clsAttr + ' title="' + escapeHtml(value) + '">' + escapeHtml(value) + '</td>';
            }).join('') + '</tr>';
        }).join('');

        qsa('.ai-log-row', body).forEach(function(tr) {
            tr.addEventListener('click', function() {
                var idx = parseInt(tr.getAttribute('data-row-index'), 10);
                if (!isNaN(idx) && state.items[idx]) {
                    showDetail(state.items[idx]);
                }
            });
        });

        attachColumnFilterHandlers(filters);
    }

    function attachColumnFilterHandlers(filters) {
        qsa('.ai-log-col-filter', filters).forEach(function(input) {
            input.addEventListener('input', debounce(function() {
                state.columnFilters[input.getAttribute('data-col')] = input.value;
                state.offset = 0;
                fetchItems(false);
            }, 300));
        });
    }

    function renderFacets(facets) {
        FACET_FIELDS.forEach(function(field) {
            var select = qs('.ai-facet-select[data-facet-field="' + field + '"]');
            if (!select) return;
            var previous = selectedValues(select);
            var values = facets[field] || [];
            var options = values.map(function(entry) {
                var val = typeof entry === 'object' ? entry.value : entry;
                var count = typeof entry === 'object' ? entry.count : '';
                var sel = previous.indexOf(String(val)) !== -1 ? ' selected' : '';
                var label = count !== '' ? (val + ' (' + count + ')') : val;
                return '<option value="' + escapeHtml(val) + '"' + sel + '>' + escapeHtml(label) + '</option>';
            }).join('');
            select.innerHTML = options || '<option value="" disabled>No values yet</option>';
        });
    }

    function updatePagerInfo(payload) {
        var info = qs('#ai-log-pager-info');
        var more = qs('#ai-log-load-more');
        var count = qs('#ai-log-count');

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

    function showDetail(item) {
        var card = qs('#ai-log-detail');
        var body = qs('#ai-log-detail-body');
        if (!card || !body) return;
        body.textContent = JSON.stringify(item, null, 2);
        card.hidden = false;
        card.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }

    function hideDetail() {
        var card = qs('#ai-log-detail');
        if (card) card.hidden = true;
    }

    function debounce(fn, ms) {
        var timer = null;
        return function() {
            var args = arguments;
            var ctx = this;
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { fn.apply(ctx, args); }, ms);
        };
    }

    function setActiveTab(tab) {
        state.tab = tab;
        state.columnFilters = {};
        state.offset = 0;
        qsa('.ai-log-tab').forEach(function(btn) {
            var isActive = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        fetchItems(false);
    }

    function clearLog() {
        if (!window.confirm('This will delete every AI log file (live and archive) on disk. Continue?')) {
            return;
        }

        var form = new FormData();
        var name = root.getAttribute('data-csrf-name');
        var token = root.getAttribute('data-csrf-clear');
        if (name && token) {
            form.append(name, token);
        }

        showStatus('Clearing log…', 'warn');

        fetch(clearUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        })
            .then(function(response) {
                return response.text().then(function(text) {
                    var parsed = parseJsonSafe(text);
                    if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
                        var inner = parseJsonSafe(parsed.main_block);
                        if (inner) parsed = inner;
                    }
                    return parsed || {ok: false, error: 'Invalid response from clear endpoint.'};
                });
            })
            .then(function(payload) {
                if (!payload || !payload.ok) {
                    showStatus((payload && payload.error) || 'Clear failed.', 'error');
                    return;
                }
                showStatus('Removed ' + (payload.removed || 0) + ' log files.', 'ok');
                fetchItems(false);
            })
            .catch(function(err) {
                showStatus('Clear failed: ' + (err && err.message ? err.message : err), 'error');
            });
    }

    function initTabs() {
        qsa('.ai-log-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setActiveTab(btn.getAttribute('data-tab'));
            });
        });
    }

    function initControls() {
        var refresh = qs('#ai-log-refresh');
        var apply = qs('#ai-log-apply');
        var reset = qs('#ai-log-reset');
        var more = qs('#ai-log-load-more');
        var clear = qs('#ai-log-clear');
        var search = qs('#ai-log-q');
        var detailClose = qs('#ai-log-detail-close');

        if (refresh) refresh.addEventListener('click', function() { state.offset = 0; fetchItems(false); });
        if (apply) apply.addEventListener('click', function() { state.offset = 0; fetchItems(false); });
        if (reset) reset.addEventListener('click', function() {
            qs('#ai-log-q').value = '';
            qsa('.ai-facet-select').forEach(function(sel) {
                Array.prototype.slice.call(sel.options).forEach(function(opt) { opt.selected = false; });
            });
            state.columnFilters = {};
            state.offset = 0;
            fetchItems(false);
        });
        if (more) more.addEventListener('click', function() { fetchItems(true); });
        if (clear) clear.addEventListener('click', function() { clearLog(); });
        if (search) search.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                state.offset = 0;
                fetchItems(false);
            }
        });
        if (detailClose) detailClose.addEventListener('click', hideDetail);
    }

    function init() {
        root = qs('#ai-logs-root');
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
