/** SLA report: responsive shell, permission-checked asynchronous results and incremental history reuse. */
(function () {
    'use strict';
    var TABS = ['overview', 'slas', 'availability'];

    function init() {
        var root = document.querySelector('[data-sr-root]');
        if (!root || root.dataset.srInitialized) { return; }
        root.dataset.srInitialized = '1';
        var activate = initTabs(root);
        initFilter(root);
        initTooltip(root);
        initLoading(root, activate);
    }

    function initTabs(root) {
        var carrier = root.querySelector('[data-sr-tab-input]');
        function activate(name, updateUrl) {
            if (TABS.indexOf(name) < 0) { name = TABS[0]; }
            root.querySelectorAll('[data-sr-tab]').forEach(function (button) {
                var on = button.dataset.srTab === name;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-selected', on ? 'true' : 'false');
                button.setAttribute('tabindex', on ? '0' : '-1');
            });
            root.querySelectorAll('[data-sr-panel]').forEach(function (panel) { panel.hidden = panel.dataset.srPanel !== name; });
            if (carrier) { carrier.value = name; }
            if (updateUrl) {
                var url = new URL(location.href); url.searchParams.set('filter_tab', name);
                history.replaceState(history.state || {}, '', url);
            }
        }
        root.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-sr-tab]');
            if (tab) { event.preventDefault(); activate(tab.dataset.srTab, true); return; }
            if (event.target.closest('[data-sr-view-nodata]')) {
                var list = root.querySelector('#sr-without-data');
                if (!list) { return; }
                event.preventDefault(); activate('availability', true);
                list.open = true; list.focus(); list.scrollIntoView({block:'start'});
            }
        });
        root.addEventListener('keydown', function (event) {
            if (!event.target.closest('[data-sr-tabs]')) { return; }
            if (['ArrowRight','ArrowLeft','Home','End'].indexOf(event.key) < 0) { return; }
            var buttons = Array.from(root.querySelectorAll('[data-sr-tab]'));
            var index = buttons.findIndex(function (b) { return b.classList.contains('is-active'); });
            if (index < 0) { return; }
            var next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
                : (index + (event.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length;
            event.preventDefault(); activate(buttons[next].dataset.srTab, true); buttons[next].focus();
        });
        activate(root.dataset.srInitialTab || 'overview', false);
        return activate;
    }

    function initLoading(root, activate) {
        var form = root.querySelector('[data-sr-filter]');
        var status = root.querySelector('[data-sr-load-status]');
        var cacheStatus = root.querySelector('[data-sr-cache-status]');
        var retry = root.querySelector('[data-sr-retry]');
        var refresh = root.querySelector('[data-sr-refresh]');
        var progress = root.querySelector('[data-sr-progress]');
        if (!form || !status || !window.fetch || !window.AbortController) { return; }
        var generation = 0, request = null, exportRequest = null, pending = false, exporting = false, failed = false;
        var hasResults = root.dataset.srAsync !== '1', applied = hasResults ? signature(params()) : '';
        var timer = null, phase = '', started = 0;
        refresh.hidden = false;
        var initialQuery = params().toString();
        history.replaceState({srFilter:initialQuery}, '', location.href);

        function params() {
            var result = new URLSearchParams();
            new FormData(form).forEach(function (value, name) {
                if (name.slice(-2) === '[]') { result.append(name, value); }
                else { result.set(name, value); }
            });
            result.delete('full'); result.delete('refresh');
            return result;
        }
        function signature(query) {
            return Array.from(query.entries()).filter(function (entry) { return entry[0].startsWith('filter_') && entry[0] !== 'filter_tab'; })
                .map(function (entry) { return entry[0] + '=' + entry[1]; }).sort().join('&');
        }
        function downloadingDisabled(disabled) {
            root.querySelectorAll('[data-sr-download]').forEach(function (link) {
                if (disabled) {
                    if (link.hasAttribute('href')) { link.dataset.srHref = link.getAttribute('href'); link.removeAttribute('href'); }
                    link.setAttribute('aria-disabled','true'); link.setAttribute('tabindex','-1');
                }
                else {
                    if (link.dataset.srHref) { link.setAttribute('href',link.dataset.srHref); delete link.dataset.srHref; }
                    link.removeAttribute('aria-disabled'); link.removeAttribute('tabindex');
                }
            });
        }
        function state() {
            var dirty = hasResults && signature(params()) !== applied;
            var results = root.querySelector('[data-sr-report-results]');
            results.classList.toggle('sr-results-stale', pending || dirty || failed);
            results.setAttribute('aria-busy', pending ? 'true' : 'false');
            downloadingDisabled(pending || exporting || dirty || failed || !hasResults);
            refresh.disabled = pending || exporting;
            if (!pending && !exporting && !failed && dirty) { status.textContent = 'Filters changed. Apply to update the report shown below.'; }
        }
        function busy(label) {
            clearInterval(timer); phase = label; started = Date.now();
            if (progress) { progress.hidden = false; }
            status.textContent = label;
            timer = setInterval(function () { status.textContent = phase + ' ' + Math.floor((Date.now()-started)/1000) + 's elapsed.'; }, 1000);
        }
        function idle() { clearInterval(timer); timer = null; if (progress) { progress.hidden = true; } }
        function showCache(meta) {
            if (!meta || !Object.keys(meta).length) { cacheStatus.textContent = ''; return; }
            var date = new Date(Number(meta.generated_at) * 1000);
            var updated = Number(meta.generated_at) > 0 && !Number.isNaN(date.getTime())
                ? ' Updated ' + date.toLocaleTimeString([], {timeZoneName:'short'}) + '.' : '';
            if (meta.reason === 'not_needed') { cacheStatus.textContent = updated.trim(); return; }
            if (meta.enabled === false) { cacheStatus.textContent = 'Measurement cache is disabled; readings were loaded live.' + updated; return; }
            if (meta.available === false) { cacheStatus.textContent = 'Measurement cache is unavailable; readings were loaded live.' + updated; return; }
            var reused = Math.max(0, Number(meta.rows_reused) || 0), fetched = Math.max(0, Number(meta.rows_fetched) || 0);
            cacheStatus.textContent = 'Reused ' + reused.toLocaleString() + ' cached readings; fetched ' + fetched.toLocaleString() + ' readings.'
                + (meta.forced ? ' Full refresh completed.' : ' Recent readings are refreshed for late data.') + updated;
        }
        async function load(force, pushUrl) {
            var id = ++generation;
            if (request) { request.abort(); }
            if (exportRequest) { exportRequest.abort(); exportRequest = null; exporting = false; }
            request = new AbortController();
            var query = params(), submitted = signature(query);
            if (pushUrl) {
                var publicUrl = new URL(form.action, location.href); publicUrl.search = query.toString();
                history.pushState({srFilter:query.toString()}, '', publicUrl);
            }
            query.set('action','slareport.report.data');
            if (force) { query.set('refresh','1'); }
            var url = new URL(root.dataset.srDataUrl, location.href); url.search = query.toString();
            pending = true; failed = false; retry.hidden = true; state();
            busy(force ? 'Refreshing report from live data…' : 'Loading report and calculating availability…');
            try {
                var response = await fetch(url, {credentials:'same-origin',cache:'no-store',signal:request.signal,headers:{Accept:'application/json'}});
                if (!response.ok) { throw new Error('The report request failed (' + response.status + '). Try again.'); }
                var data;
                try { data = await response.json(); }
                catch (_) { throw new Error('The server did not return report data. Your session may have expired; reload the page and sign in again.'); }
                if (id !== generation) { return; }
                if (data.error) { throw new Error(typeof data.error === 'string' ? data.error : data.error.message || 'The report could not be loaded.'); }
                if (typeof data.html !== 'string') { throw new Error('The report response was incomplete. Try again.'); }
                var parsed = new DOMParser().parseFromString(data.html,'text/html');
                var nextHeader = parsed.querySelector('[data-sr-report-header]'), nextResults = parsed.querySelector('[data-sr-report-results]');
                if (!nextHeader || !nextResults) { throw new Error('The report response was incomplete. Try again.'); }
                // Only this same-origin server-rendered, escaped report fragment is inserted; scripts are never executed.
                root.querySelector('[data-sr-report-header]').replaceWith(document.importNode(nextHeader,true));
                root.querySelector('[data-sr-report-results]').replaceWith(document.importNode(nextResults,true));
                hasResults = true; applied = submitted;
                activate(form.querySelector('[data-sr-tab-input]').value,false);
                showCache(data.cache);
                status.textContent = 'Report loaded.';
            }
            catch (error) {
                if (id !== generation || error.name === 'AbortError') { return; }
                failed = true;
                status.textContent = error.message + (hasResults ? ' The previous report remains below and has not been updated.' : '');
                retry.hidden = false;
            }
            finally {
                if (id === generation) { pending = false; idle(); state(); }
            }
        }
        form.addEventListener('submit', function (event) { event.preventDefault(); load(false,true); });
        form.addEventListener('input',state);
        form.addEventListener('change',state);
        retry.addEventListener('click', function () { load(false,false); });
        refresh.addEventListener('click', function () { load(true,false); });
        root.addEventListener('click', async function (event) {
            var link = event.target.closest('[data-sr-download]');
            if (!link) { return; }
            event.preventDefault();
            if (link.getAttribute('aria-disabled') === 'true' || exporting || pending) { return; }
            var url = link.href;
            exporting = true; exportRequest = new AbortController(); var currentExport = exportRequest;
            retry.hidden = true; state(); busy('Creating report export…');
            try {
                var response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:currentExport.signal});
                var disposition = response.headers.get('Content-Disposition') || '';
                if (!response.ok || !/^attachment\b/i.test(disposition)) {
                    throw new Error('The export could not be created. Your session may have expired; reload the page or try again.');
                }
                var blob = await response.blob();
                if (currentExport !== exportRequest) { return; }
                var match = /filename="?([^";]+)"?/i.exec(disposition);
                var filename = match ? match[1].replace(/[^a-zA-Z0-9._-]/g,'_') : 'sla-report-download';
                var download = document.createElement('a'), objectUrl = URL.createObjectURL(blob);
                download.href = objectUrl; download.download = filename; document.body.appendChild(download); download.click(); download.remove();
                setTimeout(function () { URL.revokeObjectURL(objectUrl); },60000);
                status.textContent = 'Report export created.';
            }
            catch (error) {
                if (currentExport === exportRequest && error.name !== 'AbortError') { status.textContent = error.message; }
            }
            finally {
                if (currentExport === exportRequest) { exporting = false; exportRequest = null; idle(); state(); }
            }
        });
        window.addEventListener('popstate', function (event) {
            var query = new URLSearchParams(event.state && event.state.srFilter ? event.state.srFilter : initialQuery);
            Array.from(form.elements).forEach(function (input) {
                if (!input.name || !input.name.startsWith('filter_')) { return; }
                // Keep the unchecked fallback value (0) independent of its checkbox's restored value (1).
                if (input.type === 'hidden' && Array.from(form.querySelectorAll('input[type="checkbox"]')).some(function (box) { return box.name === input.name; })) { return; }
                if (input.type === 'checkbox') { input.checked = query.getAll(input.name).includes(input.value); }
                else if (query.has(input.name)) { input.value = query.get(input.name); }
                input.dispatchEvent(new Event('change',{bubbles:true}));
            });
            var tab = new URL(location.href).searchParams.get('filter_tab');
            if (tab) { form.querySelector('[data-sr-tab-input]').value = tab; }
            load(false,false);
        });
        if (root.dataset.srAsync === '1') { load(false,false); }
        else { state(); }
    }
    // -------------------------------------------------------------- filter

    function initFilter(root) {
        var form = root.querySelector('[data-sr-filter]');
        if (!form) {
            return;
        }

        // Only show the date inputs that the selected period mode actually uses.
        var mode = form.querySelector('#sr_mode');
        if (mode) {
            var conditional = Array.prototype.slice.call(form.querySelectorAll('[data-sr-modes]'));
            var applyMode = function () {
                conditional.forEach(function (field) {
                    var modes = (field.getAttribute('data-sr-modes') || '').split(',');
                    field.classList.toggle('sr-field--hidden', modes.indexOf(mode.value) === -1);
                });
            };
            mode.addEventListener('change', applyMode);
            applyMode();
        }

        // Pills reflect their hidden checkbox.
        Array.prototype.slice.call(form.querySelectorAll('.sr-chip input')).forEach(function (input) {
            var chip = input.closest('.sr-chip');
            var sync = function () {
                chip.classList.toggle('is-on', input.checked);
            };
            input.addEventListener('change', sync);
            sync();
        });

        // Long chip lists get a text box that filters them client-side.
        Array.prototype.slice.call(form.querySelectorAll('[data-sr-chip-search]')).forEach(function (input) {
            var chips = document.getElementById(input.getAttribute('data-sr-chip-search'));
            if (!chips) {
                return;
            }
            input.addEventListener('input', function () {
                var needle = input.value.toLowerCase();
                Array.prototype.slice.call(chips.querySelectorAll('.sr-chip')).forEach(function (chip) {
                    var keep = needle === ''
                        || chip.textContent.toLowerCase().indexOf(needle) !== -1
                        || chip.querySelector('input').checked;
                    chip.style.display = keep ? '' : 'none';
                });
            });
        });

        // The advanced row ships expanded so it still works without JavaScript;
        // collapse it here unless the user already has one of those filters set.
        var advancedToggle = form.querySelector('[data-sr-advanced-toggle]');
        var advanced = form.querySelector('[data-sr-advanced]');
        if (advancedToggle && advanced) {
            var sync = function (open) {
                advanced.classList.toggle('is-collapsed', !open);
                advancedToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                advancedToggle.textContent = advancedToggle.getAttribute(
                    open ? 'data-label-less' : 'data-label-more'
                );
            };

            sync(advanced.getAttribute('data-sr-advanced') === 'open');

            advancedToggle.addEventListener('click', function () {
                sync(advanced.classList.contains('is-collapsed'));
            });
        }
    }

    // ------------------------------------------------------------- tooltip

    function initTooltip(root) {
        var tip = document.createElement('div');
        tip.className = 'sr-tooltip';
        tip.setAttribute('role', 'presentation');
        document.body.appendChild(tip);

        var hide = function () {
            tip.classList.remove('is-on');
        };

        root.addEventListener('mouseover', function (event) {
            var mark = event.target.closest('[data-tip]');
            if (!mark || !root.contains(mark)) {
                return;
            }

            tip.textContent = mark.getAttribute('data-tip');
            tip.classList.add('is-on');
            place(tip, event);
        });

        root.addEventListener('mousemove', function (event) {
            if (tip.classList.contains('is-on')) {
                place(tip, event);
            }
        });

        root.addEventListener('mouseout', function (event) {
            var mark = event.target.closest('[data-tip]');
            if (mark) {
                hide();
            }
        });

        window.addEventListener('scroll', hide, true);
    }

    function place(tip, event) {
        var pad = 14;
        var box = tip.getBoundingClientRect();
        var x = event.clientX + pad;
        var y = event.clientY + pad;

        if (x + box.width > window.innerWidth - 8) {
            x = event.clientX - box.width - pad;
        }
        if (y + box.height > window.innerHeight - 8) {
            y = event.clientY - box.height - pad;
        }

        tip.style.left = Math.max(8, x) + 'px';
        tip.style.top = Math.max(8, y) + 'px';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    }
    else {
        init();
    }
})();
