(function () {
  'use strict';

  // Zabbix injects module JS in the page <head>, so this runs before the
  // <body> (and #tc-root) is parsed. Defer all DOM work to DOMContentLoaded —
  // otherwise getElementById('tc-root') is null, the script returns early, and
  // none of the tab/button handlers ever bind (the page still shows the
  // server-rendered default tab, so only interaction looks "dead").
  function start() {
  var root = document.getElementById('tc-root');
  if (!root) {
    return;
  }

  var csrfField = root.getAttribute('data-csrf-field-name') || '_csrf_token';
  var urls = {
    rulesGet: root.getAttribute('data-url-rules-get'),
    ruleSave: root.getAttribute('data-url-rule-save'),
    ruleDelete: root.getAttribute('data-url-rule-delete'),
    severityRuleSave: root.getAttribute('data-url-severity-rule-save'),
    severityRuleDelete: root.getAttribute('data-url-severity-rule-delete'),
    settingsSave: root.getAttribute('data-url-settings-save'),
    searchHosts: root.getAttribute('data-url-search-hosts'),
    searchTriggers: root.getAttribute('data-url-search-triggers'),
    searchItems: root.getAttribute('data-url-search-items'),
    searchHostGroups: root.getAttribute('data-url-search-hostgroups'),
    run: root.getAttribute('data-url-run'),
    apiTest: root.getAttribute('data-url-api-test'),
    selfCheck: root.getAttribute('data-url-selfcheck'),
    setup: root.getAttribute('data-url-setup'),
    dashboard: root.getAttribute('data-url-dashboard'),
    preview: root.getAttribute('data-url-preview'),
    exportRules: root.getAttribute('data-url-export'),
    importRules: root.getAttribute('data-url-import'),
    problems: root.getAttribute('data-url-problems')
  };
  var csrf = {
    ruleSave: root.getAttribute('data-csrf-rule-save'),
    ruleDelete: root.getAttribute('data-csrf-rule-delete'),
    severityRuleSave: root.getAttribute('data-csrf-severity-rule-save'),
    severityRuleDelete: root.getAttribute('data-csrf-severity-rule-delete'),
    run: root.getAttribute('data-csrf-run'),
    preview: root.getAttribute('data-csrf-preview'),
    importRules: root.getAttribute('data-csrf-import'),
    setup: root.getAttribute('data-csrf-setup')
  };

  var rulesData = readRulesData();
  var severityRulesData = readSeverityRulesData();
  var defaultReceiver = (qs('#tc-receiver-host') || {}).value || '';

  // ── helpers ───────────────────────────────────────────────────────────
  function qs(sel, ctx) { return (ctx || root).querySelector(sel); }
  function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

  function val(elOrSel) {
    var el = typeof elOrSel === 'string' ? qs(elOrSel) : elOrSel;
    return el ? String(el.value).trim() : '';
  }
  function setVal(sel, value) {
    var el = qs(sel);
    if (el) { el.value = value == null ? '' : value; }
  }

  function safeJson(text) {
    try { return JSON.parse(text); }
    catch (e) { return null; }
  }

  function parseResponse(response) {
    return response.text().then(function (text) {
      var parsed = safeJson(text);
      if (parsed && typeof parsed === 'object' && typeof parsed.main_block === 'string') {
        var inner = safeJson(parsed.main_block);
        if (inner) { parsed = inner; }
      }
      return parsed || {ok: false, error: 'Unexpected server response.'};
    });
  }

  function getJson(url) {
    return fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(parseResponse);
  }

  function postForm(url, fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) {
      if (fields[k] !== undefined && fields[k] !== null) { fd.append(k, fields[k]); }
    });
    return fetch(url, {method: 'POST', credentials: 'same-origin', body: fd}).then(parseResponse);
  }

  function buildUrl(base, params) {
    var u = new URL(base, window.location.href);
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v !== undefined && v !== null && v !== '') { u.searchParams.set(k, v); }
    });
    return u.toString();
  }

  function setStatus(message, isError) {
    var el = qs('#tc-status');
    if (!el) { return; }
    el.classList.remove('ai-status-ok', 'ai-status-error');
    if (!message) { el.classList.add('ai-hidden'); el.textContent = ''; return; }
    el.textContent = message;
    el.classList.remove('ai-hidden');
    el.classList.add(isError ? 'ai-status-error' : 'ai-status-ok');
    if (el.scrollIntoView) { el.scrollIntoView({block: 'nearest'}); }
  }

  function readRulesData() {
    var el = document.getElementById('tc-rules-data');
    var parsed = el ? safeJson(el.textContent || '[]') : [];
    return Array.isArray(parsed) ? parsed : [];
  }

  function readSeverityRulesData() {
    var el = document.getElementById('tc-severity-rules-data');
    var parsed = el ? safeJson(el.textContent || '[]') : [];
    return Array.isArray(parsed) ? parsed : [];
  }

  function slug(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9_.-]+/g, '_').replace(/^[_.\-]+|[_.\-]+$/g, '') || 'correlation';
  }

  // ── tabs ──────────────────────────────────────────────────────────────
  function setupTabs() {
    var tabs = qsa('.ai-settings-tab');
    var keys = ['dashboard', 'rules', 'severity', 'settings', 'help'];
    var storageKey = 'tcActiveTab';

    function activate(key, focus) {
      if (keys.indexOf(key) === -1) { key = keys[0]; }
      root.setAttribute('data-active-tab', key);
      tabs.forEach(function (t) {
        var active = t.getAttribute('data-tab') === key;
        t.classList.toggle('is-active', active);
        t.setAttribute('aria-selected', active ? 'true' : 'false');
        t.setAttribute('tabindex', active ? '0' : '-1');
        if (active && focus) { t.focus(); }
      });
      try { window.sessionStorage.setItem(storageKey, key); } catch (e) {}
      onTabChange(key);
    }

    var tablist = qs('.ai-settings-tabs');
    if (tablist) {
      tablist.addEventListener('click', function (e) {
        var t = e.target.closest('.ai-settings-tab');
        if (t) { e.preventDefault(); activate(t.getAttribute('data-tab'), false); }
      });
      tablist.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
        var cur = tabs.indexOf(document.activeElement);
        if (cur === -1) { return; }
        e.preventDefault();
        var next = e.key === 'ArrowRight' ? (cur + 1) % tabs.length : (cur - 1 + tabs.length) % tabs.length;
        activate(tabs[next].getAttribute('data-tab'), true);
      });
    }

    var initial = keys[0];
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (keys.indexOf(hash) !== -1) { initial = hash; }
    else {
      try { var s = window.sessionStorage.getItem(storageKey); if (s && keys.indexOf(s) !== -1) { initial = s; } } catch (e) {}
    }
    activate(initial, false);
    return activate;
  }

  // ── FAQ toggles ───────────────────────────────────────────────────────
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.ai-faq-toggle');
    if (!btn) { return; }
    e.preventDefault();
    var id = btn.getAttribute('data-faq-target');
    var box = id ? document.getElementById(id) : null;
    if (box) {
      var vis = box.classList.contains('ai-faq-visible');
      box.classList.toggle('ai-faq-visible', !vis);
      btn.classList.toggle('ai-faq-active', !vis);
    }
  });

  // ── typeahead ───────────────────────────────────────────────────────────
  function renderDropdown(dropdown, items, onPick) {
    dropdown.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('div');
      empty.className = 'ai-dropdown-empty';
      empty.textContent = 'No match';
      dropdown.appendChild(empty);
      dropdown.classList.remove('ai-hidden');
      return;
    }
    items.forEach(function (item, index) {
      var row = document.createElement('div');
      row.className = 'ai-dropdown-item';
      row.id = dropdown.id + '-option-' + index;
      row.setAttribute('role', 'option');
      row.setAttribute('aria-selected', 'false');
      row.textContent = item.label || item.name || item.host || item.description || item.key_ || item.itemid || '';
      row.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      row.addEventListener('click', function () { onPick(item); });
      dropdown.appendChild(row);
    });
    dropdown.classList.remove('ai-hidden');
  }

  var typeaheadId = 0;
  function makeTypeahead(input, hidden, dropdown, provider, onSelect) {
    if (!input || !dropdown || input.dataset.bound) { return; }
    input.dataset.bound = '1';
    // Treat the value present on load/edit as a confirmed selection.
    input.dataset.selectedLabel = input.value;
    var timer = null;
    var request = 0;
    var activeIndex = -1;
    dropdown.id = dropdown.id || 'tc-dropdown-' + (++typeaheadId);
    input.id = input.id || dropdown.id + '-input';
    var label = input.parentElement.previousElementSibling;
    if (!label || label.tagName !== 'LABEL') { label = qs('label', input.parentElement); }
    if (label) { label.setAttribute('for', input.id); }
    dropdown.setAttribute('role', 'listbox');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', dropdown.id);
    input.setAttribute('aria-expanded', 'false');
    function close() {
      request++;
      if (timer) { clearTimeout(timer); }
      dropdown.classList.add('ai-hidden');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      activeIndex = -1;
    }
    function select(item) {
      onSelect(item);
      input.dataset.selectedLabel = input.value;
      close();
      input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    input.addEventListener('input', function () {
      // Only invalidate the stored id when the text actually diverges from the
      // last selected label (so an unrelated keystroke does not silently drop it).
      if (hidden && input.value !== (input.dataset.selectedLabel || '')) {
        hidden.value = '';
      }
      var q = input.value.trim();
      var currentRequest = ++request;
      if (timer) { clearTimeout(timer); }
      dropdown.innerHTML = '';
      dropdown.appendChild(el('div', 'ai-dropdown-empty', 'Searching…'));
      dropdown.classList.remove('ai-hidden');
      input.setAttribute('aria-expanded', 'true');
      activeIndex = -1;
      timer = setTimeout(function () {
        provider(q).then(function (items) {
          if (request !== currentRequest || !input.isConnected) { return; }
          renderDropdown(dropdown, items, select);
        }).catch(function (err) {
          if (request !== currentRequest || !input.isConnected) { return; }
          dropdown.innerHTML = '';
          var d = document.createElement('div');
          d.className = 'ai-dropdown-empty';
          d.textContent = (err && err.message) || 'Search failed';
          dropdown.appendChild(d);
          dropdown.classList.remove('ai-hidden');
        });
      }, 250);
    });

    input.addEventListener('focus', function () {
      if (input.value.trim() !== '') { input.dispatchEvent(new Event('input')); }
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { close(); event.preventDefault(); return; }
      if (event.key === 'Tab') { close(); return; }
      var options = qsa('[role="option"]', dropdown);
      if (dropdown.classList.contains('ai-hidden') || !options.length) { return; }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
        options.forEach(function (option, index) { option.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant', options[activeIndex].id);
        options[activeIndex].scrollIntoView({block: 'nearest'});
      }
      else if (event.key === 'Enter' && activeIndex >= 0) { event.preventDefault(); options[activeIndex].click(); }
    });
    input.addEventListener('blur', close);
  }

  function bindTypeaheads() {
    qsa('.tc-condition').forEach(function (box) {
      var hostInput = qs('.cond-host', box);
      var hostId = qs('.cond-hostid', box);
      var trigInput = qs('.cond-trigger', box);
      var trigId = qs('.cond-triggerid', box);
      var hostDrop = qs('[data-typeahead="host"] .ai-dropdown-list', box);
      var trigDrop = qs('[data-typeahead="trigger"] .ai-dropdown-list', box);

      makeTypeahead(hostInput, hostId, hostDrop, function (q) {
        return getJson(buildUrl(urls.searchHosts, {q: q, trigger_q: trigInput.value, limit: 25})).then(function (d) { return d.items || []; });
      }, function (item) {
        hostInput.value = item.label || item.host || item.name || '';
        hostId.value = item.hostid || '';
        // host changed → re-pick the trigger
        trigId.value = '';
        trigInput.dataset.selectedLabel = '\u0000';
        updateAutoHostLabel();
      });

      makeTypeahead(trigInput, trigId, trigDrop, function (q) {
        return getJson(buildUrl(urls.searchTriggers, {q: q, hostid: hostId.value, host_q: hostInput.value, limit: 50})).then(function (d) { return d.items || []; });
      }, function (item) {
        trigInput.value = item.description || item.label || '';
        trigId.value = item.triggerid || '';
        var hosts = item.hosts || [];
        if (hosts.length && (!hostId.value || !hosts.some(function (hh) { return String(hh.hostid) === String(hostId.value); }))) {
          var hh = hosts[0];
          hostId.value = hh.hostid || '';
          hostInput.value = hh.name || hh.host || hh.hostid || '';
          hostInput.dataset.selectedLabel = hostInput.value;
        }
        updateAutoHostLabel();
      });
    });

    var oHost = qs('#tc-output-host');
    var oHostId = qs('#tc-output-hostid');
    var oHostDrop = qs('[data-typeahead="output-host"] .ai-dropdown-list');
    makeTypeahead(oHost, oHostId, oHostDrop, function (q) {
      return getJson(buildUrl(urls.searchHosts, {q: q, limit: 25})).then(function (d) { return d.items || []; });
    }, function (item) {
      oHost.value = item.label || item.host || item.name || '';
      oHostId.value = item.hostid || '';
    });

    var oItem = qs('#tc-output-item');
    var oItemId = qs('#tc-output-itemid');
    var oItemDrop = qs('[data-typeahead="output-item"] .ai-dropdown-list');
    makeTypeahead(oItem, oItemId, oItemDrop, function (q) {
      return getJson(buildUrl(urls.searchItems, {q: q, hostid: oHostId ? oHostId.value : '', trapper_only: 1, limit: 50})).then(function (d) { return d.items || []; });
    }, function (item) {
      oItem.value = item.key_ || item.name || '';
      oItemId.value = item.itemid || '';
    });
  }

  // "Add the same trigger from every host in a group".
  function bindGroupAdd() {
    qsa('.tc-group-add').forEach(function (box) {
      var grp = qs('.ga-group', box);
      var grpId = qs('.ga-groupid', box);
      var drop = qs('[data-typeahead="ga-group"] .ai-dropdown-list', box);
      makeTypeahead(grp, grpId, drop, function (q) {
        return getJson(buildUrl(urls.searchHostGroups, {q: q, limit: 25})).then(function (d) { return d.items || []; });
      }, function (item) {
        grp.value = item.name || item.label || '';
        grpId.value = item.groupid || '';
      });
    });
  }

  function addFromGroup(box) {
    var groupid = val(qs('.ga-groupid', box));
    var name = val(qs('.ga-trigger', box));
    var list = document.getElementById(box.getAttribute('data-target'));
    if (!groupid) { setStatus('Pick a host group from the dropdown first.', true); return; }
    if (!name) { setStatus('Type the trigger name to look for on each host.', true); return; }
    setStatus('Looking for “' + name + '” on the hosts of the group…', false);
    getJson(buildUrl(urls.searchTriggers, {q: name, groupid: groupid, limit: 500})).then(function (d) {
      if (!d || d.ok === false) { setStatus((d && d.error) || 'Trigger search failed.', true); return; }
      var items = d.items || [];
      // Prefer exact name matches; fall back to "contains" — but never guess
      // between several triggers on the same host.
      var exact = items.filter(function (t) { return String(t.description).toLowerCase() === name.toLowerCase(); });
      var pool = exact.length ? exact : items;
      var perHost = {};
      var ambiguous = {};
      pool.forEach(function (t) {
        var h = (t.hosts || [])[0];
        if (!h) { return; }
        if (perHost[h.hostid]) { ambiguous[h.hostid] = h.name || h.host; return; }
        perHost[h.hostid] = {host: h, trigger: t};
      });
      Object.keys(ambiguous).forEach(function (hid) { delete perHost[hid]; });
      var existingCount = qsa('.tc-condition .cond-triggerid', list).filter(function (i) { return !!i.value; }).length;
      if (existingCount + Object.keys(perHost).length > 100) {
        setStatus('That would make ' + (existingCount + Object.keys(perHost).length) + ' source triggers; a rule can have at most 100. Narrow the group or the trigger name.', true);
        return;
      }
      var existing = {};
      qsa('.tc-condition .cond-triggerid', list).forEach(function (i) { if (i.value) { existing[i.value] = true; } });
      var added = 0;
      Object.keys(perHost).forEach(function (hid) {
        var p = perHost[hid];
        if (existing[p.trigger.triggerid]) { return; }
        var cond = {hostid: p.host.hostid, host: p.host.name || p.host.host, triggerid: p.trigger.triggerid, trigger: p.trigger.description};
        // Fill an empty row first, then append.
        var empty = qsa('.tc-condition', list).filter(function (row) { return !val(qs('.cond-hostid', row)) && !val(qs('.cond-triggerid', row)); })[0];
        var row = buildCondition(cond);
        if (empty) { list.replaceChild(row, empty); } else { list.appendChild(row); }
        added++;
      });
      bindTypeaheads();
      updateAutoHostLabel();
      var hosts = Object.keys(perHost).length;
      var ambiguousNames = Object.keys(ambiguous).map(function (hid) { return ambiguous[hid]; });
      var skipped = ambiguousNames.length
        ? ' Skipped ' + ambiguousNames.join(', ') + ': several triggers there contain “' + name + '” — type the full name.' : '';
      if (!hosts) {
        setStatus((ambiguousNames.length ? 'Nothing added.' : 'No enabled host in that group has a trigger matching “' + name + '”.') + skipped, true);
        return;
      }
      setStatus('Added ' + added + ' source trigger(s) from ' + hosts + ' host(s)' + (exact.length ? '' : ' (name contains “' + name + '”)')
        + (added < hosts ? '; ' + (hosts - added) + ' were already in the rule' : '') + '.' + skipped, ambiguousNames.length > 0);
    }).catch(function (e) { setStatus('Search failed: ' + e.message, true); });
  }

  // One source host in problem → High; all of them → Disaster.
  function applyClusterPreset() {
    var editor = qs('#tc-rule-editor');
    // Count mode counts active CONDITIONS (triggers), not hosts.
    var hosts = {};
    var conds = 0;
    qsa('.tc-condition', editor).forEach(function (row) {
      var hid = val(qs('.cond-hostid', row));
      if (hid && val(qs('.cond-triggerid', row))) { hosts[hid] = true; conds++; }
    });
    var nHosts = Object.keys(hosts).length;
    var n = Math.max(2, conds);
    setVal('#tc-match-mode', 'count');
    var tiers = qs('#tc-tiers');
    if (tiers) {
      tiers.innerHTML = '';
      tiers.appendChild(buildTier({min: 1, value: 4}));
      tiers.appendChild(buildTier({min: n, value: 5}));
    }
    updateMatchMode();
    if (conds && conds !== nHosts) {
      setStatus('Cluster preset: 1 active → High, all ' + n + ' source triggers active → Disaster. Note: the count is of triggers, and some of the '
        + nHosts + ' hosts have more than one — use one trigger per node for “every node down”.', true);
    }
    else {
      setStatus('Cluster preset: 1 active → High, ' + n + ' active (every node) → Disaster. Adjust the tiers if you like.', false);
    }
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.ai-searchable-dropdown')) {
      qsa('.ai-dropdown-list').forEach(function (d) { d.classList.add('ai-hidden'); });
    }
  });

  // ── rule editor ─────────────────────────────────────────────────────────
  function buildCondition(c) {
    c = c || {};
    var tpl = document.getElementById('tc-condition-template');
    var wrap = document.createElement('div');
    wrap.innerHTML = (tpl.innerHTML || '').trim();
    var el = wrap.firstElementChild;
    qs('.cond-host', el).value = c.host || '';
    qs('.cond-hostid', el).value = c.hostid || '';
    qs('.cond-trigger', el).value = c.trigger || '';
    qs('.cond-triggerid', el).value = c.triggerid || '';
    return el;
  }

  function buildTier(t) {
    t = t || {};
    var tpl = document.getElementById('tc-tier-template');
    var wrap = document.createElement('div');
    wrap.innerHTML = (tpl.innerHTML || '').trim();
    var el = wrap.firstElementChild;
    if (t.min != null) { qs('.tc-tier-min', el).value = t.min; }
    if (t.value != null) { qs('.tc-tier-value', el).value = String(t.value); }
    return el;
  }

  function updateOutputMode() {
    var mode = (qs('#tc-output-mode') || {}).value || 'auto';
    var receiver = qs('#tc-output-receiver');
    var receiverHost = qs('#tc-receiver-host-wrap');
    var auto = qs('#tc-output-auto');
    var existing = qs('#tc-output-existing');
    if (receiver) { receiver.classList.toggle('ai-hidden', mode === 'existing_item'); }
    if (receiverHost) { receiverHost.classList.toggle('ai-hidden', mode !== 'receiver_lld'); }
    if (auto) { auto.classList.toggle('ai-hidden', mode !== 'auto'); }
    if (existing) { existing.classList.toggle('ai-hidden', mode !== 'existing_item'); }
  }

  // The automatic correlation host belongs to the exact SET of source hosts, so
  // the label only names the current host while the editor still has that set.
  function updateAutoHostLabel() {
    var label = qs('#tc-auto-host-label');
    var editor = qs('#tc-rule-editor');
    if (!label || !editor) { return; }
    var selector = sourceBox('tc');
    if (selector && val(qs('.tc-source-mode', selector)) === 'group') {
      label.textContent = 'created or reused for this group and trigger selector; membership follows ' + (val(qs('.tc-source-group', selector)) || 'the selected group') + '. Preview shows current members.';
      return;
    }
    var assigned = editor.getAttribute('data-auto-host') || '';
    var assignedSet = editor.getAttribute('data-auto-hostset') || '';
    var ids = [];
    qsa('.tc-condition .cond-hostid', editor).forEach(function (el) {
      var v = String(el.value || '').trim();
      if (v && ids.indexOf(v) === -1) { ids.push(v); }
    });
    ids.sort();
    if (assigned && assignedSet && ids.join(',') === assignedSet) {
      label.textContent = assigned + ' (shared by every rule over these hosts)';
      return;
    }
    // Preview: another saved rule may already own a host for exactly this set.
    var editingId = editor.getAttribute('data-rule-id') || '';
    var editing = rulesData.filter(function (r) { return String(r.id) === String(editingId); })[0];
    var ownHostid = editing && editing.output && editing.output.receiver_auto ? String(editing.output.receiver_hostid || '') : '';
    var sharing = rulesData.filter(function (r) {
      var o = r.output || {};
      return o.receiver_auto && String(r.id) !== String(editingId)
        && (o.receiver_hostset_ids || []).slice().sort().join(',') === ids.join(',');
    });
    if (ids.length && sharing.length) {
      var o = sharing[0].output || {};
      label.textContent = (o.receiver_host_name || o.receiver_host) + ' — already exists; shared with “' + sharing[0].name + '”'
        + (sharing.length > 1 ? ' and ' + (sharing.length - 1) + ' more' : '');
    }
    else if (ids.length && ownHostid && !rulesData.some(function (r) {
      return String(r.id) !== String(editingId) && r.output && String(r.output.receiver_hostid || '') === ownHostid;
    })) {
      label.textContent = '“' + assigned + '” is moved to these source hosts when you save (its history is kept)';
    }
    else {
      label.textContent = ids.length
        ? 'created for these ' + ids.length + ' source host(s) when you save (or re-used if one exists)'
        : 'created (or reused) automatically for these source hosts when you save';
    }
  }

  function updateMatchMode() {
    var mode = (qs('#tc-match-mode') || {}).value || 'all';
    var tiersWrap = qs('#tc-tiers-wrap');
    var valueWrap = qs('#tc-match-value-wrap');
    if (tiersWrap) { tiersWrap.classList.toggle('ai-hidden', mode !== 'count'); }
    if (valueWrap) { valueWrap.classList.toggle('ai-hidden', mode === 'count'); }
    if (mode === 'count') {
      var list = qs('#tc-tiers');
      if (list && !list.querySelector('.tc-tier')) { list.appendChild(buildTier({min: 2, value: 4})); }
    }
  }

  function loadRule(rule) {
    rule = rule || {};
    var editor = qs('#tc-rule-editor');
    editor.setAttribute('data-rule-id', rule.id || '');
    var title = qs('#tc-editor-title');
    if (title) { title.textContent = rule.id ? 'Edit rule' : 'Rule editor'; }

    qs('#tc-rule-enabled').checked = rule.enabled !== false;
    setVal('#tc-rule-name', rule.name || '');
    setVal('#tc-rule-description', rule.description || '');

    var list = qs('#tc-conditions');
    list.innerHTML = '';
    var conds = (rule.conditions && rule.conditions.length) ? rule.conditions.slice() : [{}, {}];
    while (conds.length < 2) { conds.push({}); }
    conds.forEach(function (c) { list.appendChild(buildCondition(c)); });

    var out = rule.output || {};
    // New rules default to the automatic correlation host; saved rules keep the
    // mode they were created with (legacy receiver rules have no receiver_auto).
    var uiMode = out.mode === 'existing_item' ? 'existing_item' : (!out.mode || out.receiver_auto ? 'auto' : 'receiver_lld');
    setVal('#tc-output-mode', uiMode);
    var advanced = qs('#tc-output-advanced'); if (advanced) { advanced.open = uiMode !== 'auto'; }
    editor.setAttribute('data-auto-host', out.receiver_auto ? (out.receiver_host_name || out.receiver_host || '') : '');
    editor.setAttribute('data-auto-hostset', out.receiver_auto ? (out.receiver_hostset_ids || []).slice().sort().join(',') : '');
    setVal('#tc-match-mode', out.match_mode || 'all');
    setVal('#tc-match-value', String(out.match_value || 4));
    setVal('#tc-receiver-host', (!out.receiver_auto && out.receiver_host) || defaultReceiver);
    setVal('#tc-correlation-id', out.correlation_id || '');
    setVal('#tc-output-host', out.host || '');
    setVal('#tc-output-hostid', out.hostid || '');
    setVal('#tc-output-item', out.key || '');
    setVal('#tc-output-itemid', out.itemid || '');

    var tiersList = qs('#tc-tiers');
    if (tiersList) {
      tiersList.innerHTML = '';
      var tiers = (out.severity_tiers && out.severity_tiers.length) ? out.severity_tiers : [{min: 2, value: 4}];
      tiers.forEach(function (t) { tiersList.appendChild(buildTier(t)); });
    }

    var cc = qs('#tc-comment-correlation');
    if (cc) { cc.checked = out.comment_correlation_problem !== false; }
    var cs = qs('#tc-comment-source');
    if (cs) { cs.checked = out.comment_source_problems !== false; }

    updateOutputMode();
    updateMatchMode();
    bindTypeaheads();
    updateAutoHostLabel();
    loadSourceOptions('tc', rule);
    updateAutoHostLabel();
    markClean('correlation');
    updateRuleSummary();
    setStatus('', false);
  }

  function collectRule() {
    var editor = qs('#tc-rule-editor');
    var conditions = [];
    qsa('.tc-condition', editor).forEach(function (box) {
      var c = {
        hostid: val(qs('.cond-hostid', box)),
        host: val(qs('.cond-host', box)),
        triggerid: val(qs('.cond-triggerid', box)),
        trigger: val(qs('.cond-trigger', box))
      };
      if (c.hostid || c.triggerid || c.host || c.trigger) { conditions.push(c); }
    });

    var uiMode = (qs('#tc-output-mode') || {}).value || 'auto';
    // "Automatic" is a receiver-LLD rule whose receiver host the server provisions.
    var mode = uiMode === 'existing_item' ? 'existing_item' : 'receiver_lld';
    var matchMode = (qs('#tc-match-mode') || {}).value || 'all';
    var output = {
      mode: mode,
      receiver_auto: uiMode === 'auto',
      match_mode: matchMode,
      match_value: Number(val('#tc-match-value') || 4),
      clear_value: 0,
      comment_correlation_problem: !!(qs('#tc-comment-correlation') || {}).checked,
      comment_source_problems: !!(qs('#tc-comment-source') || {}).checked
    };
    if (matchMode === 'count') {
      output.severity_tiers = [];
      qsa('.tc-tier', editor).forEach(function (row) {
        var min = Number(val(qs('.tc-tier-min', row)) || 0);
        var value = Number(val(qs('.tc-tier-value', row)) || 0);
        if (min >= 1 && value >= 1 && value <= 5) { output.severity_tiers.push({min: min, value: value}); }
      });
    }
    if (mode === 'existing_item') {
      output.hostid = val('#tc-output-hostid');
      output.host = val('#tc-output-host');
      output.itemid = val('#tc-output-itemid');
      output.key = val('#tc-output-item');
    }
    else {
      if (uiMode !== 'auto') { output.receiver_host = val('#tc-receiver-host'); }
      output.correlation_id = val('#tc-correlation-id') || slug(val('#tc-rule-name'));
    }

    return Object.assign({
      id: editor.getAttribute('data-rule-id') || '',
      enabled: qs('#tc-rule-enabled').checked,
      name: val('#tc-rule-name'),
      description: val('#tc-rule-description'),
      conditions: conditions,
      output: output
    }, collectSourceOptions('tc'));
  }

  function saveRule() {
    var rule = collectRule();
    if (!validateDraft(rule, 'correlation')) { return; }
    var complete = rule.conditions.filter(function (c) { return c.hostid && c.triggerid; });
    if (rule.source_selector.mode !== 'group' && complete.length < 2) { setStatus('Select a host and trigger for at least two source conditions.', true); return; }
    if (rule.output.match_mode === 'count' && (!rule.output.severity_tiers || !rule.output.severity_tiers.length)) {
      setStatus('Count mode needs at least one severity tier (minimum active count → severity).', true); return;
    }

    if (!guardSettingsReload()) { return; }
    var saveButton = qs('#tc-save-rule');
    if (saveButton.disabled) { return; }
    saveButton.disabled = true;
    setStatus('Saving rule…', false);
    var fields = {rule: JSON.stringify(rule)};
    fields[csrfField] = csrf.ruleSave;
    postForm(urls.ruleSave, fields).then(function (data) {
      if (data.ok) {
        flashNotes('Rule saved.', data.notes);
        markClean('correlation');
        reloadWithDrafts();
      }
      else { setStatus(data.error || 'Save failed.', true); }
    }).catch(function (e) { setStatus('Save failed: ' + e.message, true); })
      .finally(function () { saveButton.disabled = false; });
  }

  // Carry what the server set up automatically (hosts created, secret set, …)
  // across the reload into the status line.
  function flashNotes(lead, notes) {
    if (!notes || !notes.length) { return; }
    try { window.sessionStorage.setItem('tcFlash', [lead].concat(notes).join(' ')); } catch (e) {}
  }

  function deleteRule(id) {
    if (!id || !window.confirm('Delete this correlation rule?')) { return; }
    setStatus('Deleting rule…', false);
    var fields = {id: id};
    fields[csrfField] = csrf.ruleDelete;
    postForm(urls.ruleDelete, fields).then(function (data) {
      if (data.ok) { refreshSavedRules(data); setStatus(['Rule deleted.'].concat(data.notes || []).join(' '), false); }
      else { setStatus(data.error || 'Delete failed.', true); }
    }).catch(function (e) { setStatus('Delete failed: ' + e.message, true); });
  }

  function runEvaluation(ruleid) {
    setStatus(ruleid ? 'Evaluating selected rule…' : 'Evaluating all rules…', false);
    var fields = {};
    fields[csrfField] = csrf.run;
    if (ruleid) { fields.ruleid = ruleid; }
    postForm(urls.run, fields).then(function (data) {
      if (data.ok) {
        var note = describeEval(data.result);
        refreshSavedRules(data);
        setStatus(note, evaluationHasErrors(data.result));
      }
      else { setStatus(data.error || 'Evaluation failed.', true); }
    }).catch(function (e) { setStatus('Evaluation failed: ' + e.message, true); });
  }

  function describeEval(result) {
    if (!result) { return 'Evaluation completed.'; }
    var parts = ['Evaluation completed (' + (result.rules_evaluated || 0) + ' rule(s)).'];
    if (result.error) { parts.push(result.error); }
    if (result.persist_error) { parts.push('Persist warning: ' + result.persist_error); }
    (result.errors || []).forEach(function (error) { parts.push(String(error)); });
    if (result.discovery && result.discovery.ok === false) { parts.push('Discovery warning: ' + (result.discovery.error || 'see logs')); }
    (result.rules || []).forEach(function (r) {
      if (r.error) { parts.push((r.name || r.id) + ': ' + r.error); }
      (r.errors || []).forEach(function (error) { parts.push((r.name || r.id || 'Rule') + ': ' + error); });
    });
    if (result.severity) {
      var s = result.severity;
      if (s.error) { parts.push('Severity escalation: ' + s.error); }
      if (s.persist_error) { parts.push('Severity persistence warning: ' + s.persist_error); }
      (s.errors || []).forEach(function (error) { parts.push('Severity escalation: ' + error); });
      (s.rules || []).forEach(function (rule) { if (rule.error) { parts.push((rule.name || rule.id || 'Severity rule') + ': ' + rule.error); } });
      if (!s.error) {
        var esc = 0, res = 0;
        (s.rules || []).forEach(function (r) { esc += r.escalated || 0; res += r.restored || 0; });
        parts.push('Severity escalation: ' + (s.rules_evaluated || 0) + ' rule(s)'
          + (esc ? ', raised ' + esc + ' problem(s)' : '')
          + (res ? ', restored ' + res + ' problem(s)' : '') + '.');
      }
    }
    return parts.join(' ');
  }

  function testApi() {
    setStatus('Testing Zabbix API…', false);
    getJson(urls.apiTest).then(function (data) {
      if (data.ok) {
        setStatus('Saved runtime API OK via ' + (data.transport || 'API') + '. Version ' + data.version + ', hosts visible: ' + data.host_count + '.', false);
        qs('#tc-setup-api-state').textContent = 'verified using the runtime token';
      }
      else { qs('#tc-setup-api-state').textContent = 'needs attention'; setStatus(data.error || 'API test failed.', true); }
    }).catch(function (e) { setStatus('API test failed: ' + e.message, true); });
  }

  function runSelfCheck() {
    var box = qs('#tc-selfcheck-results');
    if (box) { box.innerHTML = ''; }
    setStatus('Running self-check…', false);
    getJson(urls.selfCheck).then(function (data) {
      if (!data.ok) { setStatus(data.error || 'Self-check failed.', true); return; }
      if (box) {
        (data.checks || []).forEach(function (c) {
          var row = document.createElement('div');
          row.className = 'tc-check tc-check-' + (c.status || 'ok');
          var icon = c.status === 'fail' ? '✗' : (c.status === 'warn' ? '⚠' : '✓');
          row.textContent = icon + '  ' + c.label + (c.message ? ' — ' + c.message : '');
          box.appendChild(row);
        });
        if (data.eval_url) {
          var u = document.createElement('div');
          u.className = 'tc-check tc-check-info';
          u.textContent = 'ℹ  Evaluation URL the engine host is given ({$TRIGGER.CORRELATION.URL}):  ' + data.eval_url;
          box.appendChild(u);
        }
      }
      var bad = (data.checks || []).filter(function (c) { return c.status === 'fail'; }).length;
      var warnings = (data.checks || []).filter(function (c) { return c.status === 'warn'; }).length;
      qs('#tc-setup-health-state').textContent = bad ? bad + ' failed check(s)' : warnings ? warnings + ' warning(s); review below' : 'self-check passed';
      setStatus(bad ? (bad + ' problem(s) found — see the self-check results below.') : warnings ? ('Self-check completed with ' + warnings + ' warning(s) — review the results below.') : 'Self-check passed.', bad > 0);
    }).catch(function (e) { setStatus('Self-check failed: ' + e.message, true); });
  }

  // Setup/repair actions (Settings tab). They change Zabbix objects, so they
  // are CSRF-protected POSTs, separate from the read-only self-check.
  // Unsaved edits in the settings form (a reload would silently drop them).
  function settingsDirty() {
    var form = qs('#tc-settings-form');
    if (!form) { return false; }
    return qsa('input, select, textarea', form).some(function (f) {
      if (f.type === 'hidden' || f.type === 'button' || f.type === 'submit') { return false; }
      if (f.type === 'checkbox' || f.type === 'radio') { return f.checked !== f.defaultChecked; }
      if (f.tagName === 'SELECT') {
        return Array.prototype.some.call(f.options, function (o) { return o.selected !== o.defaultSelected; });
      }
      return f.value !== f.defaultValue;
    });
  }

  function runSetup(op, busyText, confirmText) {
    if (op !== 'detect_api' && settingsDirty()
        && !window.confirm('You have unsaved changes in Settings. Continue without saving them? (Save settings first to keep them.)')) {
      return;
    }
    if (confirmText && !window.confirm(confirmText)) { return; }
    setStatus(busyText, false);
    var fields = {op: op};
    fields[csrfField] = csrf.setup;
    postForm(urls.setup, fields).then(function (data) {
      if (!data.ok) { setStatus(data.error || 'The action failed.', true); return; }
      if (op === 'detect_api') {
        // Fill the field in place — keeps anything else typed (e.g. a pasted token).
        var input = qs('#tc-settings-form input[name="api_url"]');
        var detected = data.detected_url || '';
        if (input && detected) { input.value = detected; input.defaultValue = detected; }
        setStatus((data.notes || []).join(' '), !detected);
        return;
      }
      try { window.sessionStorage.setItem('tcActiveTab', 'settings'); } catch (e) {}
      try { window.sessionStorage.setItem('tcFlash', (data.notes || []).join(' ')); } catch (e) {}
      reloadWithDrafts();
    }).catch(function (e) { setStatus('The action failed: ' + e.message, true); });
  }

  // ── severity escalation editor ──────────────────────────────────────────
  function buildTarget(t) {
    t = t || {};
    var tpl = document.getElementById('tc-starget-template');
    var wrap = document.createElement('div');
    wrap.innerHTML = (tpl.innerHTML || '').trim();
    var el = wrap.firstElementChild;
    qs('.starget-trigger', el).value = t.trigger || '';
    qs('.starget-triggerid', el).value = t.triggerid || '';
    qs('.starget-host', el).value = t.host || '';
    qs('.starget-hostid', el).value = t.hostid || '';
    qs('.starget-group', el).value = t.group || '';
    qs('.starget-groupid', el).value = t.groupid || '';
    if (t.scope) { qs('.starget-scope', el).value = t.scope; }
    if (t.match) { qs('.starget-match', el).value = t.match; }
    return el;
  }

  function updateTargetScope(box) {
    var scope = (qs('.starget-scope', box) || {}).value || 'host';
    var groupWrap = qs('.starget-group-wrap', box);
    var matchWrap = qs('.starget-match-wrap', box);
    if (groupWrap) { groupWrap.classList.toggle('ai-hidden', scope !== 'hostgroup'); }
    if (matchWrap) { matchWrap.classList.toggle('ai-hidden', scope === 'host'); }
  }

  function bindSeverityTargets() {
    qsa('.tc-starget').forEach(function (box) {
      var trigInput = qs('.starget-trigger', box);
      var trigId = qs('.starget-triggerid', box);
      var trigHost = qs('.starget-host', box);
      var trigHostId = qs('.starget-hostid', box);
      var trigDrop = qs('[data-typeahead="starget-trigger"] .ai-dropdown-list', box);
      makeTypeahead(trigInput, trigId, trigDrop, function (q) {
        return getJson(buildUrl(urls.searchTriggers, {q: q, limit: 50})).then(function (d) { return d.items || []; });
      }, function (item) {
        trigInput.value = item.description || item.label || '';
        trigId.value = item.triggerid || '';
        var hosts = item.hosts || [];
        if (hosts.length) {
          trigHostId.value = hosts[0].hostid || '';
          trigHost.value = hosts[0].name || hosts[0].host || hosts[0].hostid || '';
        }
      });

      var grpInput = qs('.starget-group', box);
      var grpId = qs('.starget-groupid', box);
      var grpDrop = qs('[data-typeahead="starget-group"] .ai-dropdown-list', box);
      makeTypeahead(grpInput, grpId, grpDrop, function (q) {
        return getJson(buildUrl(urls.searchHostGroups, {q: q, limit: 25})).then(function (d) { return d.items || []; });
      }, function (item) {
        grpInput.value = item.name || item.label || '';
        grpId.value = item.groupid || '';
      });

      var scope = qs('.starget-scope', box);
      if (scope && !scope.dataset.scopeBound) {
        scope.dataset.scopeBound = '1';
        scope.addEventListener('change', function () { updateTargetScope(box); });
      }
      updateTargetScope(box);
    });
  }

  function updateSevMatchMode() {
    var mode = (qs('#tc-sev-match-mode') || {}).value || 'all';
    var wrap = qs('#tc-sev-min-active-wrap');
    if (wrap) { wrap.classList.toggle('ai-hidden', mode !== 'count'); }
  }

  function loadSeverityRule(rule) {
    rule = rule || {};
    var editor = qs('#tc-sev-editor');
    if (!editor) { return; }
    editor.setAttribute('data-rule-id', rule.id || '');
    var title = qs('#tc-sev-editor-title');
    if (title) { title.textContent = rule.id ? 'Edit severity escalation' : 'Severity escalation editor'; }

    qs('#tc-sev-enabled').checked = rule.enabled !== false;
    setVal('#tc-sev-name', rule.name || '');
    setVal('#tc-sev-description', rule.description || '');

    var clist = qs('#tc-sev-conditions');
    clist.innerHTML = '';
    var conds = (rule.conditions && rule.conditions.length) ? rule.conditions.slice() : [{}];
    conds.forEach(function (c) { clist.appendChild(buildCondition(c)); });

    setVal('#tc-sev-match-mode', rule.match_mode || 'all');
    setVal('#tc-sev-min-active', String(rule.min_active || 1));

    var tlist = qs('#tc-sev-targets');
    tlist.innerHTML = '';
    var targets = (rule.targets && rule.targets.length) ? rule.targets.slice() : [{}];
    targets.forEach(function (t) { tlist.appendChild(buildTarget(t)); });

    setVal('#tc-sev-severity', String(rule.severity || 4));
    qs('#tc-sev-only-raise').checked = rule.only_raise !== false;
    qs('#tc-sev-comment-target').checked = rule.comment_target !== false;
    qs('#tc-sev-comment-source').checked = rule.comment_source !== false;

    updateSevMatchMode();
    bindTypeaheads();
    bindSeverityTargets();
    loadSourceOptions('tc-sev', rule);
    markClean('severity');
    updateRuleSummary();
    setStatus('', false);
  }

  function collectSeverityRule() {
    var editor = qs('#tc-sev-editor');
    var conditions = [];
    qsa('.tc-condition', editor).forEach(function (box) {
      var c = {
        hostid: val(qs('.cond-hostid', box)),
        host: val(qs('.cond-host', box)),
        triggerid: val(qs('.cond-triggerid', box)),
        trigger: val(qs('.cond-trigger', box))
      };
      if (c.hostid || c.triggerid || c.host || c.trigger) { conditions.push(c); }
    });

    var targets = [];
    qsa('.tc-starget', editor).forEach(function (box) {
      var t = {
        scope: (qs('.starget-scope', box) || {}).value || 'host',
        trigger: val(qs('.starget-trigger', box)),
        triggerid: val(qs('.starget-triggerid', box)),
        host: val(qs('.starget-host', box)),
        hostid: val(qs('.starget-hostid', box)),
        group: val(qs('.starget-group', box)),
        groupid: val(qs('.starget-groupid', box)),
        match: (qs('.starget-match', box) || {}).value || 'exact'
      };
      if (t.trigger || t.triggerid || t.groupid) { targets.push(t); }
    });

    return Object.assign({
      id: editor.getAttribute('data-rule-id') || '',
      enabled: qs('#tc-sev-enabled').checked,
      name: val('#tc-sev-name'),
      description: val('#tc-sev-description'),
      conditions: conditions,
      match_mode: (qs('#tc-sev-match-mode') || {}).value || 'all',
      min_active: Number(val('#tc-sev-min-active') || 1),
      targets: targets,
      severity: Number(val('#tc-sev-severity') || 4),
      only_raise: !!(qs('#tc-sev-only-raise') || {}).checked,
      comment_target: !!(qs('#tc-sev-comment-target') || {}).checked,
      comment_source: !!(qs('#tc-sev-comment-source') || {}).checked
    }, collectSourceOptions('tc-sev'));
  }

  function saveSeverityRule() {
    var rule = collectSeverityRule();
    if (!validateDraft(rule, 'severity')) { return; }
    var validConds = rule.conditions.filter(function (c) { return c.hostid && c.triggerid; });
    if (rule.source_selector.mode !== 'group' && validConds.length < 1) { setStatus('Select a host and trigger for at least one source condition.', true); return; }
    var validTargets = rule.targets.filter(function (t) {
      if (t.scope === 'host') { return !!(t.triggerid || (t.hostid && t.trigger)); }
      if (t.scope === 'hostgroup') { return !!(t.groupid && t.trigger); }
      return !!t.trigger;
    });
    if (validTargets.length < 1) { setStatus('Add at least one complete escalation target.', true); return; }

    if (!guardSettingsReload()) { return; }
    var saveButton = qs('#tc-sev-save');
    if (saveButton.disabled) { return; }
    saveButton.disabled = true;
    setStatus('Saving escalation rule…', false);
    var fields = {rule: JSON.stringify(rule)};
    fields[csrfField] = csrf.severityRuleSave;
    postForm(urls.severityRuleSave, fields).then(function (data) {
      if (data.ok) { flashNotes('Escalation rule saved.', data.notes); markClean('severity'); try { window.sessionStorage.setItem('tcActiveTab', 'severity'); } catch (e) {} reloadWithDrafts(); }
      else { setStatus(data.error || 'Save failed.', true); }
    }).catch(function (e) { setStatus('Save failed: ' + e.message, true); })
      .finally(function () { saveButton.disabled = false; });
  }

  function deleteSeverityRule(id) {
    if (!id || !window.confirm('Delete this severity escalation rule? Its severity changes will be reconciled with other rules and operator overrides. Failed recovery is retained for retry.')) { return; }
    setStatus('Deleting escalation rule…', false);
    var fields = {id: id};
    fields[csrfField] = csrf.severityRuleDelete;
    postForm(urls.severityRuleDelete, fields).then(function (data) {
      if (data.ok) { refreshSavedRules(data); setStatus(['Escalation rule deleted.'].concat(data.notes || []).join(' '), false); }
      else { if (Array.isArray(data.rules)) { refreshSavedRules(data); } setStatus(data.error || (data.pending ? 'Recovery is pending. The disabled rule is retained for retry.' : 'Delete failed.'), true); }
    }).catch(function (e) { setStatus('Delete failed: ' + e.message, true); });
  }

  function runSeverity(ruleid) {
    setStatus(ruleid ? 'Evaluating selected escalation…' : 'Evaluating severity escalations…', false);
    var fields = {kind: 'severity'};
    fields[csrfField] = csrf.run;
    if (ruleid) { fields.ruleid = ruleid; }
    postForm(urls.run, fields).then(function (data) {
      if (data.ok) {
        refreshSavedRules(data);
        setStatus(describeSeverity(data.result), evaluationHasErrors(data.result));
      }
      else { setStatus(data.error || 'Evaluation failed.', true); }
    }).catch(function (e) { setStatus('Evaluation failed: ' + e.message, true); });
  }

  function describeSeverity(result) {
    if (!result) { return 'Severity evaluation completed.'; }
    var parts = ['Severity evaluation completed (' + (result.rules_evaluated || 0) + ' rule(s)).'];
    if (result.error) { parts.push(result.error); }
    if (result.persist_error) { parts.push('Persistence warning: ' + result.persist_error); }
    (result.errors || []).forEach(function (error) { parts.push(String(error)); });
    (result.rules || []).forEach(function (r) {
      if (r.error) { parts.push((r.name || r.id) + ': ' + r.error); }
      (r.errors || []).forEach(function (error) { parts.push((r.name || r.id || 'Rule') + ': ' + error); });
      if (r.escalated) { parts.push((r.name || 'rule') + ': raised ' + r.escalated + ' problem(s).'); }
      if (r.restored) { parts.push((r.name || 'rule') + ': restored ' + r.restored + ' problem(s).'); }
    });
    return parts.join(' ');
  }
  function evaluationHasErrors(result) {
    return !!result && (!!result.error || !!result.persist_error || !!(result.errors || []).length
      || !!(result.discovery && result.discovery.ok === false)
      || (result.rules || []).some(function (rule) { return !!rule.error || !!(rule.errors || []).length; })
      || evaluationHasErrors(result.severity));
  }

  // ── dashboard ───────────────────────────────────────────────────────────
  var activateTab = null;
  var dash = {view: 'correlations', data: null, timer: null, loading: false};
  var SEV_LABELS = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
  try { var savedView = window.sessionStorage.getItem('tcDashView'); if (savedView === 'hosts') { dash.view = 'hosts'; } } catch (e) {}

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function sevChip(sev, text) {
    var n = Math.max(0, Math.min(5, Number(sev) || 0));
    return el('span', 'tc-sev tc-sev-' + n, text || SEV_LABELS[n]);
  }

  function ago(clock) {
    if (!clock) { return ''; }
    var s = Math.max(0, Math.floor(Date.now() / 1000) - Number(clock));
    if (s < 60) { return s + 's'; }
    if (s < 3600) { return Math.floor(s / 60) + 'm'; }
    if (s < 86400) { return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm'; }
    return Math.floor(s / 86400) + 'd ' + Math.floor((s % 86400) / 3600) + 'h';
  }

  function problemsLink(hostid, text) {
    var a = el('a', 'tc-link', text);
    a.href = urls.problems + (hostid ? '&hostids%5B%5D=' + encodeURIComponent(hostid) : '');
    return a;
  }

  function onTabChange(key) {
    if (key === 'dashboard') { loadDashboard(); scheduleDashboard(); }
    else if (dash.timer) { clearTimeout(dash.timer); dash.timer = null; }
  }

  function scheduleDashboard() {
    if (dash.timer) { clearTimeout(dash.timer); }
    dash.timer = null;
    var auto = qs('#tc-dash-auto');
    if (auto && auto.checked && root.getAttribute('data-active-tab') === 'dashboard') {
      dash.timer = setTimeout(function () { loadDashboard(); scheduleDashboard(); }, 30000);
    }
  }

  function loadDashboard() {
    if (!urls.dashboard || dash.loading || document.hidden) { return; }
    dash.loading = true;
    getJson(urls.dashboard).then(function (data) {
      dash.loading = false;
      if (!data || !data.ok) {
        renderDashError((data && data.error) || 'Could not load the dashboard.');
        return;
      }
      dash.data = data;
      renderDashboard();
      var upd = qs('#tc-dash-updated');
      if (upd) { upd.textContent = 'Updated ' + new Date(data.generated_at * 1000).toLocaleTimeString(); }
    }).catch(function (e) {
      dash.loading = false;
      renderDashError('Could not load the dashboard: ' + e.message);
    });
  }

  function renderDashError(msg) {
    var body = qs('#tc-dash-body');
    if (!body) { return; }
    body.innerHTML = '';
    body.appendChild(el('div', 'ai-status ai-status-error', msg));
  }

  function renderDashboard() {
    var data = dash.data;
    if (!data) { return; }
    renderSummary(data.summary || {});
    var body = qs('#tc-dash-body');
    body.innerHTML = '';
    qsa('.tc-seg-btn').forEach(function (b) {
      var on = b.getAttribute('data-dash-view') === dash.view;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    if (!(data.correlations || []).length && !(data.escalations || []).length) {
      var empty = el('div', 'tc-empty');
      empty.appendChild(el('strong', '', 'No rules yet.'));
      empty.appendChild(el('p', 'ai-muted', 'Create a correlation rule (a new problem when related triggers fire together) or a severity escalation (raise existing problems while a condition holds). The correlation host is set up for you.'));
      var go = el('button', 'btn', 'Create a correlation rule');
      go.type = 'button';
      go.addEventListener('click', function () { if (activateTab) { activateTab('rules', true); } });
      empty.appendChild(go);
      body.appendChild(empty);
      return;
    }

    var query = val('#tc-dashboard-search').toLowerCase(); var filter = val('#tc-dashboard-state') || 'all';
    var correlations = (data.correlations || []).filter(function (rule) { return matchesRule(rule, query, filter); });
    var escalations = (data.escalations || []).filter(function (rule) { return matchesRule(rule, query, filter); });
    if (!correlations.length && !escalations.length) { body.appendChild(el('p', 'tc-empty', 'No rules match these filters.')); return; }
    if (dash.view === 'hosts') {
      var ids = correlations.concat(escalations).map(function (rule) { return String(rule.id); });
      renderHosts(body, (data.hosts || []).filter(function (host) {
        return (!query && filter === 'all') || (host.correlations || []).concat(host.escalations || []).some(function (rule) { return ids.indexOf(String(rule.id)) !== -1; }) || (filter === 'all' && String(host.name || '').toLowerCase().indexOf(query) !== -1);
      }));
      return;
    }
    if (correlations.length) {
      body.appendChild(el('h3', 'tc-dash-h', 'Correlations'));
      var grid = el('div', 'tc-cards');
      correlations.forEach(function (c) { grid.appendChild(correlationCard(c)); });
      body.appendChild(grid);
    }
    if (escalations.length) {
      body.appendChild(el('h3', 'tc-dash-h', 'Severity escalations'));
      var egrid = el('div', 'tc-cards');
      escalations.forEach(function (e) { egrid.appendChild(escalationCard(e)); });
      body.appendChild(egrid);
    }
  }

  function tile(label, value, sub, status) {
    var t = el('div', 'tc-tile' + (status ? ' tc-tile-' + status : ''));
    t.appendChild(el('div', 'tc-tile-label', label));
    var v = el('div', 'tc-tile-value');
    if (value instanceof Node) { v.appendChild(value); } else { v.textContent = String(value); }
    t.appendChild(v);
    if (sub) {
      var s = el('div', 'tc-tile-sub');
      if (sub instanceof Node) { s.appendChild(sub); } else { s.textContent = sub; }
      t.appendChild(s);
    }
    return t;
  }

  function renderSummary(sum) {
    var box = qs('#tc-dash-summary');
    if (!box) { return; }
    box.innerHTML = '';
    var chips = el('span', 'tc-chip-row');
    var bySev = sum.by_severity || {};
    Object.keys(bySev).sort(function (a, b) { return b - a; }).forEach(function (sev) {
      chips.appendChild(sevChip(sev, bySev[sev] + ' ' + SEV_LABELS[sev]));
    });
    if (!chips.childNodes.length) { chips.textContent = 'all quiet'; }
    box.appendChild(tile('Correlations firing', (sum.firing || 0) + ' / ' + (sum.correlations || 0), chips, sum.firing ? 'alert' : 'ok'));
    box.appendChild(tile('Problems raised by escalation', sum.raised || 0,
      (sum.escalations_active || 0) + ' of ' + (sum.escalations || 0) + ' escalation rule(s) active', sum.raised ? 'alert' : ''));
    box.appendChild(tile('Source problems involved', sum.source_problems || 0, 'active problems on watched triggers'));
    var eng = sum.engine || {};
    box.appendChild(tile('Evaluation', eng.text || 'Unknown', eng.lastclock ? 'last run ' + ago(eng.lastclock) + ' ago' : '',
      eng.status === 'ok' ? 'ok' : (eng.status === 'fail' ? 'alert' : 'warn')));
  }

  function memberList(members) {
    var wrap = el('div', 'tc-members');
    (members || []).forEach(function (m) {
      var hostBox = el('div', 'tc-member');
      var head = el('div', 'tc-member-host');
      head.appendChild(m.hostid ? problemsLink(m.hostid, m.name) : el('span', '', m.name));
      var firing = (m.triggers || []).filter(function (t) { return t.problem; }).length;
      head.appendChild(el('span', 'ai-muted', ' · ' + firing + ' of ' + (m.triggers || []).length + ' in problem'));
      hostBox.appendChild(head);
      var ul = el('ul', 'tc-trig-list');
      (m.triggers || []).forEach(function (t) {
        var li = el('li', 'tc-trig' + (t.problem ? ' is-problem' : ''));
        if (t.problem) { li.appendChild(sevChip(t.problem.severity)); }
        else { li.appendChild(el('span', 'tc-sev ' + (t.available === false ? 'tc-sev-unknown' : 'tc-sev-ok'), t.available === false ? 'Unavailable' : t.missing ? 'Missing' : (t.disabled ? 'Disabled' : t.excluded ? 'Excluded' : 'Clear'))); }
        li.appendChild(el('span', 'tc-trig-name', t.description));
        if (t.reason) { li.appendChild(el('span', 'ai-muted', ' — ' + t.reason)); }
        if (t.problem) {
          li.appendChild(el('span', 'ai-muted tc-trig-age', ago(t.problem.clock) + (t.problem.acknowledged ? ' · ack' : '')));
        }
        ul.appendChild(li);
      });
      hostBox.appendChild(ul);
      wrap.appendChild(hostBox);
    });
    return wrap;
  }

  function editButton(cls, id, text) {
    var b = el('button', 'btn ' + cls, text);
    b.type = 'button';
    b.setAttribute('data-id', id);
    return b;
  }

  function correlationCard(c) {
    var card = el('div', 'tc-card tc-card-sev-' + (c.state || 'ok') + (c.enabled ? '' : ' is-disabled'));
    var head = el('div', 'tc-card-head');
    var titles = el('div', 'tc-card-titles');
    titles.appendChild(el('h4', 'tc-card-title', c.name));
    var meta = el('div', 'ai-muted tc-card-meta');
    meta.textContent = c.active + ' of ' + c.total + ' active · ' + c.match + (c.enabled ? '' : ' · disabled');
    titles.appendChild(meta);
    head.appendChild(titles);
    head.appendChild(c.state ? sevChip(c.state) : el('span', 'tc-sev tc-sev-ok', 'OK'));
    card.appendChild(head);
    card.appendChild(evaluationStatus(c));
    card.appendChild(memberList(c.members));

    var foot = el('div', 'tc-card-foot');
    var info = el('div', 'tc-card-problem');
    if (c.problem) {
      info.appendChild(sevChip(c.problem.severity, 'Problem'));
      info.appendChild(el('span', '', ' ' + c.problem.name + ' · since ' + ago(c.problem.clock) + (c.problem.acknowledged ? ' · acknowledged' : '')));
    }
    else if (c.state && c.problem_lookup) {
      info.appendChild(el('span', 'ai-muted', 'The applied state has no visible receiver problem yet. Check evaluation status and receiver discovery.'));
    }
    else if (c.state) {
      info.appendChild(el('span', 'ai-muted', 'Correlation active — your own trigger on ' + (c.host || 'the output item') + ' raises the problem.'));
    }
    else {
      info.appendChild(el('span', 'ai-muted', 'No correlation problem.'));
    }
    foot.appendChild(info);
    var actions = el('div', 'tc-card-actions');
    if (c.host) {
      var hostLink = problemsLink(c.hostid, (c.host_auto ? 'Correlation host: ' : 'Writes to: ') + c.host);
      hostLink.classList.add('ai-muted');
      actions.appendChild(hostLink);
    }
    actions.appendChild(editButton('tc-edit', c.id, 'Edit'));
    actions.appendChild(editButton('tc-duplicate', c.id, 'Duplicate'));
    foot.appendChild(actions);
    card.appendChild(foot);
    if (c.last_error) { card.appendChild(el('div', 'tc-error-text tc-card-error', c.last_error)); }
    return card;
  }

  function escalationCard(e) {
    var sev = e.active ? e.severity : 0;
    var card = el('div', 'tc-card tc-card-sev-' + (sev || 'ok') + (e.enabled ? '' : ' is-disabled'));
    var head = el('div', 'tc-card-head');
    var titles = el('div', 'tc-card-titles');
    titles.appendChild(el('h4', 'tc-card-title', e.name));
    var targets = (e.targets || []).map(function (t) {
      return t.trigger + (t.scope === 'all' ? ' (all hosts)' : (t.scope === 'hostgroup' ? ' (in ' + t.group + ')' : ' (' + t.host + ')'));
    }).join(', ');
    titles.appendChild(el('div', 'ai-muted tc-card-meta', 'when ' + e.match + ' active → raise ' + targets + ' to ' + SEV_LABELS[e.severity]));
    head.appendChild(titles);
    head.appendChild(e.active ? sevChip(e.severity, 'Escalating') : el('span', 'tc-sev tc-sev-ok', 'Idle'));
    card.appendChild(head);
    card.appendChild(evaluationStatus(e, true));
    card.appendChild(memberList(e.members));

    if ((e.raised || []).length) {
      var box = el('div', 'tc-raised');
      box.appendChild(el('div', 'tc-raised-title', 'Tracked problems'));
      var ul = el('ul', 'tc-trig-list');
      e.raised.forEach(function (r) {
        var li = el('li', 'tc-trig is-problem');
        li.appendChild(sevChip(r.from));
        li.appendChild(el('span', 'tc-arrow', '→'));
        li.appendChild(sevChip(r.to));
        li.appendChild(el('span', 'tc-trig-name', (r.host ? r.host + ': ' : '') + r.name));
        if (r.manual_override) { li.appendChild(el('strong', '', 'Manual override — operator severity preserved')); }
        else if (r.pending_restore) { li.appendChild(el('strong', 'tc-error-text', 'Recovery pending')); }
        li.appendChild(el('span', 'ai-muted tc-trig-age', ago(r.clock)));
        ul.appendChild(li);
      });
      box.appendChild(ul);
      card.appendChild(box);
    }
    var foot = el('div', 'tc-card-foot');
    foot.appendChild(el('div', 'ai-muted', (e.conditions_active || 0) + ' of ' + (e.conditions_total || 0) + ' source trigger(s) active'));
    if (e.manual_override_count) { foot.appendChild(el('span', 'ai-muted', e.manual_override_count + ' operator override(s) preserved')); }
    var actions = el('div', 'tc-card-actions');
    actions.appendChild(editButton('sev-edit', e.id, 'Edit'));
    actions.appendChild(editButton('sev-duplicate', e.id, 'Duplicate'));
    foot.appendChild(actions);
    card.appendChild(foot);
    if (e.last_error) { card.appendChild(el('div', 'tc-error-text tc-card-error', e.last_error)); }
    return card;
  }

  function renderHosts(body, hosts) {
    body.appendChild(el('h3', 'tc-dash-h', 'Hosts in correlations'));
    var grid = el('div', 'tc-cards');
    hosts.forEach(function (h) {
      var hot = h.has_problem || h.worst > 0;
      var card = el('div', 'tc-card tc-card-sev-' + (hot ? h.worst : 'ok'));
      var head = el('div', 'tc-card-head');
      var titles = el('div', 'tc-card-titles');
      var title = el('h4', 'tc-card-title');
      title.appendChild(problemsLink(h.hostid, h.name));
      titles.appendChild(title);
      var firing = (h.triggers || []).filter(function (t) { return t.problem; }).length;
      titles.appendChild(el('div', 'ai-muted tc-card-meta', firing + ' of ' + (h.triggers || []).length + ' watched trigger(s) in problem'));
      head.appendChild(titles);
      head.appendChild(hot ? sevChip(h.worst) : el('span', 'tc-sev tc-sev-ok', 'OK'));
      card.appendChild(head);

      card.appendChild(memberList([{hostid: '', name: 'Watched triggers', triggers: h.triggers}]));

      var feeds = el('div', 'tc-feeds');
      feeds.appendChild(el('span', 'ai-muted', 'Feeds: '));
      (h.correlations || []).forEach(function (c) {
        var chip = c.state ? sevChip(c.state, c.name) : el('span', 'tc-sev tc-sev-ok', c.name);
        chip.title = 'Correlation';
        feeds.appendChild(chip);
      });
      (h.escalations || []).forEach(function (e) {
        var chip = el('span', 'tc-sev ' + (e.active ? 'tc-sev-esc' : 'tc-sev-ok'), '↑ ' + e.name);
        chip.title = 'Severity escalation';
        feeds.appendChild(chip);
      });
      card.appendChild(feeds);
      grid.appendChild(card);
    });
    body.appendChild(grid);
  }

  root.addEventListener('click', function (e) {
    var seg = e.target.closest('.tc-seg-btn');
    if (seg) {
      e.preventDefault();
      dash.view = seg.getAttribute('data-dash-view') === 'hosts' ? 'hosts' : 'correlations';
      try { window.sessionStorage.setItem('tcDashView', dash.view); } catch (err) {}
      renderDashboard();
    }
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && root.getAttribute('data-active-tab') === 'dashboard') { loadDashboard(); scheduleDashboard(); }
  });

  // ── wiring ──────────────────────────────────────────────────────────────
  root.addEventListener('click', function (e) {
    var editBtn = e.target.closest('.tc-edit');
    if (editBtn) {
      e.preventDefault();
      if (!guardEditor('correlation')) { return; }
      var id = editBtn.getAttribute('data-id');
      var rule = rulesData.filter(function (r) { return String(r.id) === String(id); })[0];
      loadRule(rule ? JSON.parse(JSON.stringify(rule)) : {});
      if (activateTab) { activateTab('rules', false); } else { root.setAttribute('data-active-tab', 'rules'); }
      var ed = qs('#tc-rule-editor');
      if (ed && ed.scrollIntoView) { ed.scrollIntoView({block: 'nearest'}); }
      return;
    }
    var runBtn = e.target.closest('.tc-run');
    if (runBtn) { e.preventDefault(); runEvaluation(runBtn.getAttribute('data-id')); return; }
    var delBtn = e.target.closest('.tc-delete');
    if (delBtn) { e.preventDefault(); deleteRule(delBtn.getAttribute('data-id')); return; }

    var sevEditBtn = e.target.closest('.sev-edit');
    if (sevEditBtn) {
      e.preventDefault();
      if (!guardEditor('severity')) { return; }
      var sid = sevEditBtn.getAttribute('data-id');
      var srule = severityRulesData.filter(function (r) { return String(r.id) === String(sid); })[0];
      loadSeverityRule(srule ? JSON.parse(JSON.stringify(srule)) : {});
      if (activateTab) { activateTab('severity', false); } else { root.setAttribute('data-active-tab', 'severity'); }
      var sed = qs('#tc-sev-editor');
      if (sed && sed.scrollIntoView) { sed.scrollIntoView({block: 'nearest'}); }
      return;
    }
    var sevRunBtn = e.target.closest('.sev-run');
    if (sevRunBtn) { e.preventDefault(); runSeverity(sevRunBtn.getAttribute('data-id')); return; }
    var sevDelBtn = e.target.closest('.sev-delete');
    if (sevDelBtn) { e.preventDefault(); deleteSeverityRule(sevDelBtn.getAttribute('data-id')); return; }

    var rmCond = e.target.closest('.tc-remove-condition');
    if (rmCond) {
      e.preventDefault();
      var box = rmCond.closest('.tc-condition');
      var list = box && box.closest('.ai-repeat-list');
      if (box && list) {
        var minCond = (list.id === 'tc-sev-conditions') ? 1 : 2;
        if (list.querySelectorAll('.tc-condition').length > minCond) { box.remove(); updateAutoHostLabel(); }
        else { setStatus('A rule needs at least ' + minCond + ' source condition' + (minCond > 1 ? 's' : '') + '.', true); }
      }
      return;
    }
    var rmTarget = e.target.closest('.tc-remove-starget');
    if (rmTarget) {
      e.preventDefault();
      var tlist = qs('#tc-sev-targets');
      var tbox = rmTarget.closest('.tc-starget');
      if (tbox && tlist && tlist.querySelectorAll('.tc-starget').length > 1) { tbox.remove(); }
      else { setStatus('A severity rule needs at least one target.', true); }
      return;
    }
    var gaBtn = e.target.closest('.tc-group-add-btn');
    if (gaBtn) {
      e.preventDefault();
      addFromGroup(gaBtn.closest('.tc-group-add'));
      return;
    }
    var rmTier = e.target.closest('.tc-remove-tier');
    if (rmTier) {
      e.preventDefault();
      var trow = rmTier.closest('.tc-tier');
      if (trow) { trow.remove(); }
      return;
    }
  });

  on('#tc-test-api', 'click', function () { testApi(); });
  on('#tc-dash-refresh', 'click', function () { loadDashboard(); scheduleDashboard(); });
  on('#tc-cluster-preset', 'click', function () { applyClusterPreset(); });
  on('#tc-dash-auto', 'change', function () { scheduleDashboard(); });
  on('#tc-selfcheck-btn', 'click', function () { runSelfCheck(); });
  on('#tc-repair-btn', 'click', function () { runSetup('repair', 'Repairing the automatic setup…'); });
  on('#tc-cleanup-btn', 'click', function () {
    runSetup('cleanup', 'Deleting unused correlation hosts…',
      'Delete every automatic correlation host that no rule uses? Their problem history is deleted with them.');
  });
  on('#tc-detect-api-btn', 'click', function () { runSetup('detect_api', 'Looking for the Zabbix API…'); });
  on('#tc-run-all', 'click', function () { runEvaluation(''); });
  on('#tc-add-condition', 'click', function () {
    var list = qs('#tc-conditions');
    list.appendChild(buildCondition({}));
    bindTypeaheads();
  });
  on('#tc-add-tier', 'click', function () {
    var l = qs('#tc-tiers');
    if (l) { l.appendChild(buildTier({min: 2, value: 4})); }
  });
  on('#tc-reset-rule', 'click', function () { if (guardEditor('correlation')) { loadRule({output: {receiver_host: defaultReceiver}}); } });
  on('#tc-save-rule', 'click', function () { saveRule(); });
  on('#tc-output-mode', 'change', function () { updateOutputMode(); });
  on('#tc-match-mode', 'change', function () { updateMatchMode(); });

  on('#tc-sev-add-condition', 'click', function () {
    var list = qs('#tc-sev-conditions');
    if (list) { list.appendChild(buildCondition({})); bindTypeaheads(); }
  });
  on('#tc-sev-add-target', 'click', function () {
    var list = qs('#tc-sev-targets');
    if (list) { list.appendChild(buildTarget({})); bindSeverityTargets(); }
  });
  on('#tc-sev-save', 'click', function () { saveSeverityRule(); });
  on('#tc-sev-reset', 'click', function () { if (guardEditor('severity')) { loadSeverityRule({}); } });
  on('#tc-sev-match-mode', 'change', function () { updateSevMatchMode(); });

  var settingsForm = qs('#tc-settings-form');
  if (settingsForm) {
    settingsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = settingsForm.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
      fetch(settingsForm.action, {method: 'POST', credentials: 'same-origin', body: new FormData(settingsForm)})
        .then(parseResponse)
        .then(function (data) {
          if (data.ok) {
            flashNotes('Settings saved.', data.notes);
            reloadWithDrafts();
          }
          else { setStatus(data.error || 'Save failed.', true); }
        })
        .catch(function (e2) { setStatus('Save failed: ' + e2.message, true); })
        .finally(function () { if (btn) { btn.disabled = false; btn.textContent = 'Save settings'; } });
    });
  }

  function on(sel, evt, fn) {
    var el = qs(sel);
    if (el) { el.addEventListener(evt, fn); }
  }

  // Drafts contain rule definitions only. API credentials never enter browser storage.
  var cleanDrafts = {};
  var intentionalReload = false;
  var draftKey = 'tcDrafts:' + window.location.pathname + ':' + (root.getAttribute('data-user-id') || 'session');
  function draft(kind) { return kind === 'severity' ? collectSeverityRule() : collectRule(); }
  function markClean(kind) { cleanDrafts[kind] = JSON.stringify(draft(kind)); }
  function isDirty(kind) { return cleanDrafts[kind] !== undefined && cleanDrafts[kind] !== JSON.stringify(draft(kind)); }
  function persistDrafts() {
    var saved = {};
    ['correlation', 'severity'].forEach(function (kind) { if (isDirty(kind)) { saved[kind] = draft(kind); } });
    try { window.sessionStorage.setItem(draftKey, JSON.stringify(saved)); } catch (ignore) {}
  }
  function guardEditor(kind) {
    return !isDirty(kind) || window.confirm('Discard the unsaved changes in this rule?');
  }
  function guardSettingsReload() {
    return !settingsDirty() || window.confirm('Settings have unsaved changes. Continue and discard those settings?');
  }
  function reloadWithDrafts() { persistDrafts(); intentionalReload = true; window.location.reload(); }
  window.addEventListener('beforeunload', function (event) {
    persistDrafts();
    if (!intentionalReload && (isDirty('correlation') || isDirty('severity') || settingsDirty())) {
      event.preventDefault(); event.returnValue = '';
    }
  });

  function sourceBox(prefix) { return qs('[data-source-prefix="' + prefix + '"]'); }
  function collectSourceOptions(prefix) {
    var box = sourceBox(prefix);
    return {
      activation_seconds: Number(val('#' + prefix + '-activation') || 0),
      recovery_seconds: Number(val('#' + prefix + '-recovery') || 0),
      source_selector: {
        mode: val(qs('.tc-source-mode', box)) || 'static',
        groupid: val(qs('.tc-source-groupid', box)), group: val(qs('.tc-source-group', box)),
        trigger_name: val(qs('.tc-source-trigger', box)), tag: val(qs('.tc-source-tag', box)), tag_value: val(qs('.tc-source-tag-value', box))
      }
    };
  }
  function updateSourceMode(prefix) {
    var box = sourceBox(prefix);
    var dynamic = val(qs('.tc-source-mode', box)) === 'group';
    qs('.tc-dynamic-source', box).classList.toggle('ai-hidden', !dynamic);
    qs('.tc-static-sources', box.closest('[data-rule-id]')).classList.toggle('ai-hidden', dynamic);
  }
  function loadSourceOptions(prefix, rule) {
    var selector = rule.source_selector || {};
    var box = sourceBox(prefix);
    qs('.tc-source-mode', box).value = selector.mode === 'group' ? 'group' : 'static';
    ['group', 'groupid', 'trigger', 'tag', 'tag-value'].forEach(function (field) {
      var key = field === 'trigger' ? 'trigger_name' : field.replace('-', '_');
      qs('.tc-source-' + field, box).value = selector[key] || '';
    });
    qs('.tc-source-group', box).dataset.selectedLabel = selector.group || '';
    setVal('#' + prefix + '-activation', rule.activation_seconds || 0);
    setVal('#' + prefix + '-recovery', rule.recovery_seconds || 0);
    updateSourceMode(prefix);
    qsa('.tc-preview', box.closest('[data-rule-id]')).forEach(function (panel) { panel.classList.add('ai-hidden'); });
  }
  function validationError(message, field) {
    setStatus(message, true);
    if (field) {
      var details = field.closest('details'); if (details) { details.open = true; }
      field.setAttribute('aria-invalid', 'true'); field.focus();
    }
    return false;
  }
  function validateDraft(rule, kind) {
    var prefix = kind === 'severity' ? 'tc-sev' : 'tc';
    var editor = qs(kind === 'severity' ? '#tc-sev-editor' : '#tc-rule-editor');
    qsa('[aria-invalid]', editor).forEach(function (field) { field.removeAttribute('aria-invalid'); });
    if (!rule.name) { return validationError('Give this rule a name.', qs('#' + prefix + (kind === 'severity' ? '-name' : '-rule-name'))); }
    if (![rule.activation_seconds, rule.recovery_seconds].every(function (n) { return Number.isInteger(n) && n >= 0 && n <= 86400; })) {
      return validationError('Timing must be a whole number from 0 to 86400 seconds.', qs('#' + prefix + '-activation'));
    }
    var dynamic = rule.source_selector.mode === 'group';
    if (kind === 'correlation' && rule.output.match_mode === 'count') {
      var badTier = qsa('.tc-tier', editor).filter(function (row) {
        var minimum = Number(val(qs('.tc-tier-min', row))); var value = Number(val(qs('.tc-tier-value', row)));
        return !Number.isInteger(minimum) || minimum < 1 || !Number.isInteger(value) || value < 1 || value > 5;
      })[0];
      if (badTier) { return validationError('Every tier needs a whole-number count of at least one and a severity.', qs('.tc-tier-min', badTier)); }
    }
    if (dynamic) {
      if (!rule.source_selector.groupid || !rule.source_selector.trigger_name) {
        return validationError('Select a host group and enter the exact trigger name.', qs('.tc-source-group', sourceBox(prefix)));
      }
    }
    else {
      var ids = {};
      var conditions = rule.conditions || [];
      for (var i = 0; i < conditions.length; i++) {
        var c = conditions[i];
        if (!c.hostid || !c.triggerid) { return validationError('Source ' + (i + 1) + ': select both a host and trigger from the dropdown.', qsa('.cond-trigger', editor)[i]); }
        if (ids[c.triggerid]) { return validationError('The same trigger is selected more than once. Remove the duplicate source.', qsa('.cond-trigger', editor)[i]); }
        ids[c.triggerid] = true;
      }
      if (conditions.length < (kind === 'severity' ? 1 : 2)) { return validationError('Select at least ' + (kind === 'severity' ? 'one source trigger.' : 'two source triggers.'), qs('.cond-host', editor)); }
      var mode = kind === 'severity' ? rule.match_mode : rule.output.match_mode;
      var thresholds = kind === 'severity' ? [rule.min_active] : (rule.output.severity_tiers || []).map(function (tier) { return tier.min; });
      if (mode === 'count' && thresholds.some(function (n) { return n > conditions.length; })) {
        return validationError('An active-count threshold exceeds the number of distinct source triggers.', qs(kind === 'severity' ? '#tc-sev-min-active' : '.tc-tier-min', editor));
      }
    }
    return true;
  }
  function chosenSeverities(rule) {
    var output = rule.output || {};
    var values = output.match_mode === 'count' ? (output.severity_tiers || []).map(function (tier) { return Number(tier.value); }) : [Number(output.match_value || 4)];
    return values.filter(function (n, index) { return n >= 1 && n <= 5 && values.indexOf(n) === index; }).sort();
  }
  function severityName(value) { return SEV_LABELS[Number(value)] || (Number(value) === 0 ? 'OK' : String(value)); }
  function stateName(value) { return Number(value) === 0 ? 'OK' : severityName(value); }
  function evaluationStatus(rule, severityRule) {
    var status = rule.status || (rule.last_error ? 'error' : 'unknown');
    var labels = {clear: 'Clear', applied: 'Applied', active: 'Applied', ok: 'Applied', pending: 'Waiting', pending_activation: 'Waiting to activate', pending_recovery: 'Waiting to recover', awaiting_evaluation: 'Waiting for evaluation', manual_override: 'Operator severity preserved', error: 'Needs attention', stale: 'Evaluation overdue', disabled: 'Disabled', never: 'Not evaluated yet', unknown: 'Evaluation status unavailable'};
    var box = el('div', 'tc-evaluation-status tc-evaluation-' + status);
    box.appendChild(el('strong', '', labels[status] || status));
    var describeState = function (value) { return severityRule ? (Number(value) ? 'escalation active' : 'escalation inactive') : stateName(value); };
    box.appendChild(el('span', '', ' · Expected ' + (rule.candidate_state == null ? 'unknown' : describeState(rule.candidate_state))));
    if (rule.stored_state != null) { box.appendChild(el('span', '', ' · Applied ' + describeState(rule.stored_state))); }
    if (rule.pending_seconds > 0) { box.appendChild(el('span', '', ' · ' + rule.pending_seconds + 's remaining')); }
    box.appendChild(el('div', 'ai-muted', rule.last_successful ? 'Last successful evaluation ' + ago(rule.last_successful) + ' ago' : 'No successful evaluation recorded'));
    appendMessages(box, rule.source_issues, 'tc-error-text');
    return box;
  }
  function updateRuleSummary() {
    var rule = collectRule();
    var values = chosenSeverities(rule).map(severityName);
    var creates = 'Creates ' + (values.length ? values.join(' and ') : 'no selected severity') + ' only; OK clears the problem.';
    qs('#tc-selected-severities').textContent = creates;
    var source = rule.source_selector.mode === 'group' ? 'matching triggers in ' + (rule.source_selector.group || 'the selected group') : rule.conditions.length + ' selected source trigger(s)';
    qs('#tc-rule-summary').textContent = 'When ' + (rule.output.match_mode === 'all' ? 'all ' : rule.output.match_mode === 'any' ? 'any of ' : 'the configured count of ') + source + ' are active: ' + creates;
    var srule = collectSeverityRule();
    qs('#tc-sev-summary').textContent = 'When the source condition holds, ' + (srule.only_raise ? 'raise' : 'set') + ' matching active problems to ' + severityName(srule.severity) + '. Preview lists the affected problems.';
    updateAutoHostLabel();
    ['correlation', 'severity'].forEach(function (kind) {
      var panel = qs('#tc-preview-' + kind);
      if (panel.dataset.draft && panel.dataset.draft !== JSON.stringify(draft(kind)) && !qs('.tc-preview-stale', panel)) {
        panel.insertBefore(el('p', 'tc-error-text tc-preview-stale', 'This draft changed after the preview. Preview again to update these results.'), panel.firstChild);
      }
    });
  }
  function appendMessages(panel, messages, cls) {
    (messages || []).forEach(function (message) { panel.appendChild(el('p', cls || 'ai-muted', typeof message === 'string' ? message : (message.message || JSON.stringify(message)))); });
  }
  function previewDraft(kind) {
    var rule = draft(kind);
    if (!validateDraft(rule, kind)) { return; }
    var button = qs(kind === 'severity' ? '#tc-sev-preview' : '#tc-preview-rule');
    var panel = qs('#tc-preview-' + kind);
    var submitted = JSON.stringify(rule);
    button.disabled = true;
    panel.classList.remove('ai-hidden'); panel.innerHTML = ''; panel.appendChild(el('p', '', 'Checking the current sources and affected problems…'));
    var fields = {kind: kind, rule: submitted}; fields[csrfField] = csrf.preview;
    postForm(urls.preview, fields).then(function (data) {
      panel.innerHTML = '';
      if (!data.ok) { panel.appendChild(el('p', 'tc-error-text', data.error || 'Preview failed.')); return; }
      var result = data.result || {};
      panel.dataset.draft = submitted;
      panel.appendChild(el('h3', '', 'Preview — no changes applied'));
      if (submitted !== JSON.stringify(draft(kind))) { panel.appendChild(el('p', 'tc-error-text', 'The draft changed while this preview was running. Preview again before saving.')); }
      var unknown = result.status === 'unknown' || result.valid === false;
      var expected = unknown ? 'unknown; previous applied result retained'
        : kind === 'severity' ? (result.active ? 'condition matches; requested severity ' + severityName(result.severity || rule.severity) : 'condition does not match; release this rule’s escalation')
          : stateName(result.candidate_state == null ? result.state : result.candidate_state);
      panel.appendChild(el('p', '', (result.active_count || 0) + ' of ' + (result.total || 0) + ' sources active. Status: ' + (result.status || 'unknown') + '. Expected result: ' + expected + '.'));
      if (rule.enabled === false) { panel.appendChild(el('p', 'ai-muted', 'This draft is disabled. Check “Rule is active” and preview again to inspect what enabling it would do; preview still makes no changes.')); }
      if (result.pending_seconds) { panel.appendChild(el('p', 'ai-muted', result.pending_seconds + ' seconds remain before the change can apply.')); }
      appendMessages(panel, result.errors, 'tc-error-text');
      var list = el('ul', 'tc-preview-sources');
      (result.conditions || []).forEach(function (condition) {
        var row = el('li', condition.available === false ? 'tc-error-text' : '');
        row.appendChild(el('strong', '', (condition.host || '') + ': ' + (condition.trigger || condition.triggerid || 'Source')));
        row.appendChild(el('span', '', ' — ' + (condition.reason || (condition.available === false ? 'Unavailable' : condition.active ? 'Active' : 'Clear'))));
        list.appendChild(row);
      });
      panel.appendChild(list);
      if (kind === 'severity') {
        panel.appendChild(el('h4', '', 'Matching active problems (' + (result.targets || []).length + ')'));
        var targetList = el('ul', 'tc-preview-sources');
        (result.targets || []).forEach(function (target) { targetList.appendChild(previewTarget(target, false, result)); });
        panel.appendChild(targetList);
        if (!(result.targets || []).length) { panel.appendChild(el('p', 'ai-muted', 'No active target problems match right now.')); }
        if ((result.restorations || []).length) {
          panel.appendChild(el('h4', '', 'Recovery and released targets (' + result.restorations.length + ')'));
          var restorationList = el('ul', 'tc-preview-sources');
          result.restorations.forEach(function (target) { restorationList.appendChild(previewTarget(target, true, result)); });
          panel.appendChild(restorationList);
        }
      }
      else { panel.appendChild(el('p', 'ai-muted', 'Creates ' + (result.selected_severities || chosenSeverities(rule)).map(severityName).join(' and ') + ' only.')); }
      panel.focus();
    }).catch(function (error) { panel.innerHTML = ''; panel.appendChild(el('p', 'tc-error-text', 'Preview failed: ' + error.message)); })
      .finally(function () { button.disabled = false; });
  }
  function previewTarget(target, restoration, result) {
    var current = target.severity == null ? 'unknown' : severityName(target.severity);
    var effective = target.effective_severity == null ? current : severityName(target.effective_severity);
    var outcome = target.available === false ? 'Unavailable; recovery remains pending'
      : target.manual_override ? 'No change — operator severity is preserved'
        : target.would_change ? (restoration ? 'Would restore/reconcile to ' : 'Would change to ') + effective
          : result.pending_seconds > 0 ? 'Waiting for the rule timer; then ' + effective
            : 'No change required';
    return el('li', target.available === false ? 'tc-error-text' : '', (target.host ? target.host + ': ' : '')
      + (target.name || target.eventid) + ' — current ' + current + '. ' + outcome + ' (event ' + target.eventid + ').');
  }

  function matchesRule(rule, query, state) {
    var status = rule.status || '';
    var active = Number(rule.candidate_state == null ? (rule.last_state || rule.state || 0) : rule.candidate_state) > 0 || !!rule.active;
    var attention = !!rule.last_error || ['error', 'stale', 'pending', 'pending_activation', 'pending_recovery', 'awaiting_evaluation', 'manual_override', 'never', 'unknown'].indexOf(status) !== -1;
    return (!query || JSON.stringify([rule.name, rule.conditions, rule.members, rule.targets, rule.source_selector]).toLowerCase().indexOf(query) !== -1)
      && (state === 'all' || (state === 'disabled' && rule.enabled === false) || (state === 'active' && active) || (state === 'attention' && attention));
  }
  function filterLists() {
    ['correlation', 'severity'].forEach(function (kind) {
      var prefix = kind === 'severity' ? 'tc-severity' : 'tc-rules';
      var data = kind === 'severity' ? severityRulesData : rulesData;
      var query = val('#' + prefix + '-search').toLowerCase(); var state = val('#' + prefix + '-state') || 'all';
      var visible = 0;
      qsa('[data-rule-row="' + kind + '"]').forEach(function (row) {
        var rule = data.filter(function (item) { return String(item.id) === row.getAttribute('data-id'); })[0];
        var show = !!rule && matchesRule(rule, query, state); row.hidden = !show; if (show) { visible++; }
      });
      qs('.tc-filter-count[data-list-kind="' + kind + '"]').textContent = visible + ' of ' + data.length;
    });
  }
  function refreshSavedRules(data) {
    var apply = function (config) {
      if (Array.isArray(config.rules)) { rulesData = config.rules; }
      if (Array.isArray(config.severity_rules)) { severityRulesData = config.severity_rules; }
      filterLists(); loadDashboard();
      qsa('[data-rule-row]').forEach(function (row) {
        var rules = row.getAttribute('data-rule-row') === 'severity' ? severityRulesData : rulesData;
        var rule = rules.filter(function (item) { return String(item.id) === row.getAttribute('data-id'); })[0];
        if (!rule) { row.remove(); return; }
        if (row.cells[0]) { row.cells[0].textContent = rule.enabled === false ? 'Disabled' : 'Enabled'; }
        var severityRule = row.getAttribute('data-rule-row') === 'severity';
        var stateCell = row.cells[severityRule ? 4 : 3];
        if (stateCell) { stateCell.textContent = rule.last_error ? 'Needs attention: ' + rule.last_error : severityRule ? (rule.last_state ? 'Escalating' : 'Idle') : stateName(rule.last_state || 0); }
        var lastCell = row.cells[severityRule ? 5 : 4]; if (lastCell) { lastCell.textContent = rule.last_evaluated_iso || ''; }
      });
    };
    if (Array.isArray(data.rules)) { apply(data); }
    else { getJson(urls.rulesGet).then(function (result) { if (result.ok !== false) { apply(result); } }); }
  }
  function duplicateRule(kind, id) {
    if (!guardEditor(kind)) { return; }
    var rules = kind === 'severity' ? severityRulesData : rulesData;
    var source = rules.filter(function (rule) { return String(rule.id) === String(id); })[0];
    if (!source) { return; }
    var copy = JSON.parse(JSON.stringify(source)); copy.id = ''; copy.name += ' (copy)'; copy.enabled = false;
    if (copy.output) { copy.output.correlation_id = ''; }
    if (kind === 'severity') { loadSeverityRule(copy); } else { loadRule(copy); }
    cleanDrafts[kind] = '__new_copy__'; persistDrafts();
    activateTab(kind === 'severity' ? 'severity' : 'rules', false);
    qs(kind === 'severity' ? '#tc-sev-name' : '#tc-rule-name').focus();
    setStatus('Created a disabled draft copy. Review its sources and preview before saving.', false);
  }

  var importDocument = null;
  var reviewedImport = null;
  var importSequence = 0;
  function importRules(op) {
    if (!importDocument) { return; }
    if (op === 'apply' && reviewedImport !== importDocument) { setStatus('Preview this import before applying it.', true); return; }
    if (op === 'apply' && !guardSettingsReload()) { return; }
    var panel = qs('#tc-import-preview'); var applyButton = qs('#tc-import-apply');
    applyButton.disabled = true; panel.classList.remove('ai-hidden'); panel.innerHTML = '';
    panel.appendChild(el('p', '', op === 'apply' ? 'Applying import…' : 'Checking names, references and conflicts…'));
    var submitted = importDocument;
    var fields = {document: submitted, op: op, conflict: 'rename'}; fields[csrfField] = csrf.importRules;
    postForm(urls.importRules, fields).then(function (data) {
      if (submitted !== importDocument) { return; }
      panel.innerHTML = '';
      var preview = data.preview || {};
      if (op === 'apply' && data.ok) { flashNotes('Rules imported as disabled drafts.', data.notes); reloadWithDrafts(); return; }
      panel.appendChild(el('h3', '', 'Import preview'));
      panel.appendChild(el('p', '', (preview.rules_count || 0) + ' correlation rules and ' + (preview.severity_rules_count || 0) + ' severity rules. Name conflicts will be renamed. Imported rules start disabled.'));
      appendMessages(panel, preview.conflicts); appendMessages(panel, preview.warnings); appendMessages(panel, preview.errors, 'tc-error-text');
      if (data.error) { panel.appendChild(el('p', 'tc-error-text', data.error)); }
      applyButton.classList.remove('ai-hidden'); applyButton.disabled = !data.ok || !!(preview.errors || []).length;
      reviewedImport = applyButton.disabled ? null : submitted;
      panel.focus();
    }).catch(function (error) { panel.innerHTML = ''; panel.appendChild(el('p', 'tc-error-text', 'Import failed: ' + error.message)); });
  }
  function setupImprovements() {
    ['tc', 'tc-sev'].forEach(function (prefix) {
      var box = sourceBox(prefix);
      makeTypeahead(qs('.tc-source-group', box), qs('.tc-source-groupid', box), qs('.ai-dropdown-list', box), function (query) {
        return getJson(buildUrl(urls.searchHostGroups, {q: query, limit: 25})).then(function (data) { return data.items || []; });
      }, function (item) { qs('.tc-source-group', box).value = item.name; qs('.tc-source-groupid', box).value = item.groupid; });
      qs('.tc-source-mode', box).addEventListener('change', function () { updateSourceMode(prefix); });
      updateSourceMode(prefix);
    });
    // Keep the ordinary path short; existing advanced outputs open their controls when edited.
    var outputChoice = qs('.tc-output-mode-choice');
    qs('#tc-output-advanced').insertBefore(outputChoice, qs('#tc-output-advanced').children[1]);
    qsa('.tc-group-add').forEach(function (group) {
      var details = el('details', 'tc-advanced'); details.appendChild(el('summary', '', 'Add a cluster or farm in one step'));
      group.parentElement.insertBefore(details, group); details.appendChild(group);
    });
    qsa('#tc-settings-form > section').forEach(function (section) {
      var title = qs('h2', section); if (!title) { return; }
      if (['Evaluation endpoint', 'Automatic setup', 'Custom receiver keys', 'Evaluation behavior'].indexOf(title.textContent.trim()) === -1) { return; }
      var details = el('details', 'tc-advanced'); var summary = el('summary', '', title.textContent.trim()); details.appendChild(summary);
      while (section.firstChild) { details.appendChild(section.firstChild); } section.appendChild(details); title.parentElement.classList.add('ai-hidden');
    });
    var settings = qs('#tc-settings-form');
    var apiSection = qs('input[name="api_url"]', settings).closest('section');
    settings.insertBefore(apiSection, qs('section', settings));
    var connectionDetails = el('details', 'tc-advanced'); connectionDetails.appendChild(el('summary', '', 'Advanced connection settings'));
    var connectionGrid = el('div', 'ai-repeat-grid ai-settings-grid'); connectionDetails.appendChild(connectionGrid);
    ['api_auth_mode', 'timeout', 'verify_peer'].forEach(function (name) {
      var field = qs('[name="' + name + '"]', apiSection);
      var fieldBox = field.closest('.ai-repeat-grid > div'); if (fieldBox) { connectionGrid.appendChild(fieldBox); }
    });
    apiSection.appendChild(connectionDetails);
    var backup = qs('#tc-import-file').closest('section'); settings.parentElement.insertBefore(backup, settings.nextSibling);
    root.addEventListener('input', function (event) {
      if (event.target.closest('[data-rule-id]')) { updateRuleSummary(); persistDrafts(); }
      if (event.target.matches('.tc-list-search')) { filterLists(); }
      if (event.target.id === 'tc-dashboard-search') { renderDashboard(); }
    });
    root.addEventListener('change', function (event) {
      if (event.target.closest('[data-rule-id]')) { updateRuleSummary(); persistDrafts(); }
      if (event.target.matches('.tc-list-state')) { filterLists(); }
      if (event.target.id === 'tc-dashboard-state') { renderDashboard(); }
    });
    root.addEventListener('click', function (event) {
      var choice = event.target.closest('[data-new-kind]');
      if (choice) {
        var kind = choice.getAttribute('data-new-kind'); if (!guardEditor(kind)) { return; }
        if (kind === 'severity') { loadSeverityRule({}); } else { loadRule({}); }
        activateTab(kind === 'severity' ? 'severity' : 'rules', false);
        qs(kind === 'severity' ? '#tc-sev-name' : '#tc-rule-name').focus();
      }
      var duplicate = event.target.closest('.tc-duplicate, .sev-duplicate');
      if (duplicate) { duplicateRule(duplicate.classList.contains('sev-duplicate') ? 'severity' : 'correlation', duplicate.getAttribute('data-id')); }
      if (event.target.closest('[data-rule-id]')) { updateRuleSummary(); persistDrafts(); }
    });
    on('#tc-preview-rule', 'click', function () { previewDraft('correlation'); });
    on('#tc-sev-preview', 'click', function () { previewDraft('severity'); });
    on('#tc-export', 'click', function () {
      getJson(urls.exportRules).then(function (data) {
        if (!data.ok || !data.export) { setStatus(data.error || 'Export failed.', true); return; }
        var blob = new Blob([JSON.stringify(data.export, null, 2)], {type: 'application/json'}); var url = URL.createObjectURL(blob);
        var link = el('a'); link.href = url; link.download = 'trigger-correlation-rules-' + new Date().toISOString().slice(0, 10) + '.json'; document.body.appendChild(link); link.click(); link.remove(); setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      }).catch(function (error) { setStatus('Export failed: ' + error.message, true); });
    });
    on('#tc-import-file', 'change', function (event) {
      importDocument = null; reviewedImport = null; var sequence = ++importSequence; qs('#tc-import-apply').disabled = true;
      var file = event.target.files[0]; if (!file) { return; }
      if (file.size > 1024 * 1024) { setStatus('The import file must be smaller than 1 MB.', true); return; }
      file.text().then(function (text) { if (sequence !== importSequence) { return; } if (!safeJson(text)) { setStatus('Choose a valid JSON backup.', true); return; } importDocument = text; importRules('preview'); });
    });
    on('#tc-import-apply', 'click', function () { importRules('apply'); });
    markClean('correlation'); markClean('severity');
    try {
      var saved = safeJson(window.sessionStorage.getItem(draftKey) || '{}') || {}; var restored = [];
      ['correlation', 'severity'].forEach(function (kind) {
        if (!saved[kind]) { return; }
        if (kind === 'severity') { loadSeverityRule(saved[kind]); } else { loadRule(saved[kind]); }
        cleanDrafts[kind] = '__restored_draft__'; restored.push(kind);
      });
      if (restored.length) { var notice = qs('#tc-draft-notice'); notice.classList.remove('ai-hidden'); notice.textContent = 'Restored your unsaved ' + restored.join(' and ') + ' draft. Preview and save when ready.'; }
    } catch (ignore) {}
    updateRuleSummary(); filterLists();
  }

  // ── init ────────────────────────────────────────────────────────────────
  activateTab = setupTabs();
  updateOutputMode();
  updateMatchMode();
  updateSevMatchMode();
  bindTypeaheads();
  bindSeverityTargets();
  bindGroupAdd();
  setupImprovements();

  // Surface a one-shot message left by a "Run evaluation" reload.
  try {
    var flash = window.sessionStorage.getItem('tcFlash');
    if (flash) { window.sessionStorage.removeItem('tcFlash'); setStatus(flash, false); }
  } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  }
  else {
    start();
  }
})();
