// Network Map - application bootstrap
(function (global) {
  "use strict";

  const NM = (global.NetworkMap = global.NetworkMap || {});
  const filters = NM.filters || {};
  const state =
    (global.KNMState =
      global.KNMState ||
      {
        root: null,
        rawData: null,
        rawNodeMap: null,
        currentGraph: { nodes: [], edges: [] },
        cy: null,
        resizeHandler: null,
        summaryData: { incoming: [], outgoing: [] },
        hasDrawnGraph: false,
        initialized: false,
      });

  function getEl(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function ensureStateDefaults() {
    if (!(state.rawNodeMap instanceof Map)) {
      state.rawNodeMap = new Map();
    }

    if (!state.currentGraph) {
      state.currentGraph = { nodes: [], edges: [] };
    }

    if (!state.summaryData) {
      state.summaryData = { incoming: [], outgoing: [] };
    }

    if (typeof state.hasDrawnGraph !== "boolean") {
      state.hasDrawnGraph = false;
    }
  }

  function buildLayout(root) {
    if (!root || root.dataset.knmLayoutBuilt === "1") {
      return;
    }

    root.classList.add("knm-root");
    root.innerHTML = `
      <section class="knm-panel">
        <div class="knm-panel-header">
          <h2>Network map</h2>
        </div>

        <div class="knm-form-grid">
          <label class="knm-form-field">
            <span>Host scope</span>
            <div class="knm-autocomplete">
              <input id="knm-hostSelect" type="text" placeholder="All hosts" autocomplete="off">
              <input id="knm-hostSelectValue" type="hidden" value="">
              <ul id="knm-hostSelect-ac" class="knm-autocomplete-list"></ul>
            </div>
          </label>

          <label class="knm-form-field">
            <span>Source filter</span>
            <div class="knm-autocomplete">
              <input id="knm-filterSrc" type="text" placeholder="hostname or IP" autocomplete="off">
              <ul id="knm-filterSrc-ac" class="knm-autocomplete-list"></ul>
            </div>
          </label>

          <label class="knm-form-field">
            <span>Destination filter</span>
            <div class="knm-autocomplete">
              <input id="knm-filterDst" type="text" placeholder="hostname or IP" autocomplete="off">
              <ul id="knm-filterDst-ac" class="knm-autocomplete-list"></ul>
            </div>
          </label>

          <label class="knm-form-field">
            <span>Port filter</span>
            <input id="knm-filterPort" type="text" placeholder="443 or 80-443">
          </label>

          <label class="knm-form-field">
            <span>Excluded IPs / CIDRs / ranges</span>
            <input id="knm-filterIp" type="text" placeholder="10.0.0.0/8,192.168.1.10-192.168.1.40">
          </label>

          <label class="knm-form-field">
            <span>Minimum separation</span>
            <input id="knm-minSep" type="number" value="50" min="10" max="500" step="5">
          </label>

          <label class="knm-form-field">
            <span>Horizontal scale</span>
            <input id="knm-scaleX" type="number" value="1.0" min="0.1" max="5" step="0.1">
          </label>

          <label class="knm-form-field">
            <span>Vertical scale</span>
            <input id="knm-scaleY" type="number" value="1.0" min="0.1" max="5" step="0.1">
          </label>
        </div>

        <div class="knm-checkbox-row">
          <label class="knm-checkbox">
            <input id="knm-excludeNoisePorts" type="checkbox" checked>
            <span>Hide RPC / high ports</span>
          </label>

          <label class="knm-checkbox">
            <input id="knm-excludePub" type="checkbox">
            <span>Exclude public IPs</span>
          </label>
        </div>

        <div class="knm-help">
          Leave <strong>Host scope</strong> empty for the full graph. When a host is selected, only
          traffic where that host is the source or destination is shown.
        </div>

        <div class="knm-actions">
          <button id="knm-btnApply" class="knm-btn" type="button">Draw graph</button>
          <button id="knm-btnRefreshData" class="knm-btn knm-btn-secondary" type="button">Refresh data</button>
        </div>

        <div id="knm-dataStatus" class="knm-status" aria-live="polite">Loading network map…</div>
      </section>

      <div class="knm-main">
        <section class="knm-panel knm-graph-panel">
          <div id="knm-loading" class="knm-loading" hidden>Loading…</div>
          <div id="knm-cy" aria-live="polite"></div>
        </section>

        <aside class="knm-sidebar">
          <section id="knm-summary" class="knm-panel" hidden>
            <div class="knm-panel-header">
              <h3 id="knm-summaryTitle">Traffic summary</h3>

              <div class="knm-panel-actions">
                <button id="knm-minimizeSummary" class="knm-panel-toggle" type="button">Collapse</button>
                <button id="knm-closeSummary" class="knm-panel-toggle" type="button">Close</button>
              </div>
            </div>

            <div id="knm-summaryFilters">
              <div class="knm-form-grid">
                <label class="knm-form-field">
                  <span>Summary source filter</span>
                  <input id="knm-sumFilterSrc" type="text" placeholder="token or !exclude">
                </label>

                <label class="knm-form-field">
                  <span>Summary destination filter</span>
                  <input id="knm-sumFilterDst" type="text" placeholder="token or !exclude">
                </label>

                <label class="knm-form-field">
                  <span>Summary port filter</span>
                  <input id="knm-sumFilterPort" type="text" placeholder="443,80-443,!135">
                </label>
              </div>

              <div class="knm-help">
                Summary filters support comma-separated tokens. Prefix a token with <code>!</code> to exclude it.
              </div>
            </div>

            <pre id="knm-summaryContent" class="knm-summary-content"></pre>
          </section>
        </aside>
      </div>
    `;

    root.dataset.knmLayoutBuilt = "1";
  }

  function showBusy(show) {
    const overlay = getEl("knm-loading");
    const btnApply = getEl("knm-btnApply");
    const btnRefresh = getEl("knm-btnRefreshData");

    if (overlay) {
      overlay.hidden = !show;
    }

    [btnApply, btnRefresh].forEach((button) => {
      if (button) {
        button.disabled = !!show;
      }
    });
  }

  function buildNodeMap(nodes) {
    const map = new Map();

    (nodes || []).forEach((node) => {
      const id = node && node.data && node.data.id;

      if (id) {
        map.set(id, node);
      }
    });

    return map;
  }

  function sanitizeNetworkMapData(data) {
    const normalized = data && typeof data === "object" ? data : {};

    if (!Array.isArray(normalized.nodes)) {
      normalized.nodes = [];
    }

    if (!Array.isArray(normalized.edges)) {
      normalized.edges = [];
    }

    if (!normalized.meta || typeof normalized.meta !== "object") {
      normalized.meta = {};
    }

    normalized.nodes = normalized.nodes
      .filter((node) => node && node.data)
      .map((node) => {
        if (!node.data.id && node.data.label) {
          node.data.id = node.data.label;
        }

        if (!node.data.label && node.data.id) {
          node.data.label = node.data.id;
        }

        return node;
      })
      .filter((node) => !!node.data.id);

    normalized.edges = normalized.edges
      .filter((edge) => edge && edge.data && edge.data.source && edge.data.target)
      .map((edge, index) => {
        if (!edge.data.id) {
          edge.data.id = `e${index + 1}`;
        }

        return edge;
      });

    return normalized;
  }

  /* ── Autocomplete helper ─────────────────────────────────── */

  function buildAutocomplete(inputEl, listEl, getItems, onSelect) {
    let activeIndex = -1;

    function renderList(query) {
      const items = getItems(query);
      listEl.innerHTML = "";
      activeIndex = -1;

      if (!items.length) {
        listEl.classList.remove("knm-ac-open");
        return;
      }

      const queryLc = (query || "").toLowerCase();
      items.slice(0, 60).forEach((item, i) => {
        const li = document.createElement("li");
        li.dataset.value = item.value;
        li.dataset.index = i;

        // Highlight matching portion
        if (queryLc) {
          const idx = item.label.toLowerCase().indexOf(queryLc);
          if (idx >= 0) {
            li.innerHTML =
              escapeHtml(item.label.slice(0, idx)) +
              '<span class="knm-ac-match">' + escapeHtml(item.label.slice(idx, idx + queryLc.length)) + '</span>' +
              escapeHtml(item.label.slice(idx + queryLc.length));
          } else {
            li.textContent = item.label;
          }
        } else {
          li.textContent = item.label;
        }

        li.addEventListener("mousedown", (e) => {
          e.preventDefault();
          selectItem(item);
        });
        listEl.appendChild(li);
      });

      listEl.classList.add("knm-ac-open");
    }

    function selectItem(item) {
      listEl.classList.remove("knm-ac-open");
      onSelect(item);
    }

    function setActive(idx) {
      const lis = listEl.querySelectorAll("li");
      lis.forEach((li) => li.classList.remove("knm-ac-active"));
      if (idx >= 0 && idx < lis.length) {
        activeIndex = idx;
        lis[idx].classList.add("knm-ac-active");
        lis[idx].scrollIntoView({ block: "nearest" });
      }
    }

    inputEl.addEventListener("input", () => {
      renderList(inputEl.value);
    });

    inputEl.addEventListener("focus", () => {
      renderList(inputEl.value);
    });

    inputEl.addEventListener("blur", () => {
      // Delay to allow mousedown on list items
      setTimeout(() => listEl.classList.remove("knm-ac-open"), 150);
    });

    inputEl.addEventListener("keydown", (e) => {
      const lis = listEl.querySelectorAll("li");
      if (!listEl.classList.contains("knm-ac-open") || !lis.length) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        setActive(activeIndex < lis.length - 1 ? activeIndex + 1 : 0);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        setActive(activeIndex > 0 ? activeIndex - 1 : lis.length - 1);
      } else if (e.key === "Enter" && activeIndex >= 0) {
        e.preventDefault();
        const li = lis[activeIndex];
        const items = getItems(inputEl.value);
        if (items[activeIndex]) selectItem(items[activeIndex]);
      } else if (e.key === "Escape") {
        listEl.classList.remove("knm-ac-open");
      }
    });

    return { renderList };
  }

  /* ── Host scope data & autocomplete ────────────────────── */

  let hostItems = []; // { value, label } sorted

  function populateHostSelect(nodes) {
    const input = getEl("knm-hostSelect");
    const hiddenInput = getEl("knm-hostSelectValue");

    if (!input) {
      return;
    }

    const previousValue = hiddenInput ? hiddenInput.value : "";

    hostItems = [{ value: "", label: "All hosts" }];

    (nodes || [])
      .slice()
      .sort((a, b) => {
        const left = String(a?.data?.label || a?.data?.id || "").toLowerCase();
        const right = String(b?.data?.label || b?.data?.id || "").toLowerCase();
        return left.localeCompare(right);
      })
      .forEach((node) => {
        const id = node?.data?.id;
        if (!id) return;

        const label = node?.data?.label || id;
        const ip = node?.data?.ip || "";
        hostItems.push({ value: id, label, ip });
      });

    // Restore previous selection if still valid
    if (previousValue) {
      const found = hostItems.find((h) => h.value === previousValue);
      if (found) {
        input.value = found.label;
        if (hiddenInput) hiddenInput.value = found.value;
      }
    }

    // Also populate source/destination suggestion lists
    populateTrafficSuggestions();
  }

  /* ── Source / Destination suggestion data ───────────────── */

  let srcSuggestions = []; // { value, label }
  let dstSuggestions = [];

  function populateTrafficSuggestions() {
    if (!state.rawData) return;

    const srcSet = new Map();
    const dstSet = new Map();

    (state.rawData.edges || []).forEach((edge) => {
      const d = edge.data;
      if (!d) return;

      const srcId = d.source;
      const dstId = d.target;
      const srcLabel = d.sourceLabel || srcId;
      const dstLabel = d.targetLabel || dstId;

      if (srcId && !srcSet.has(srcId)) {
        const node = state.rawNodeMap && state.rawNodeMap.get(srcId);
        srcSet.set(srcId, {
          value: (node && node.data && node.data.label) || srcLabel,
          label: (node && node.data && node.data.label) || srcLabel,
          ip: (node && node.data && node.data.ip) || d.srcIp || d.src_ip || "",
        });
      }

      if (dstId && !dstSet.has(dstId)) {
        const node = state.rawNodeMap && state.rawNodeMap.get(dstId);
        dstSet.set(dstId, {
          value: (node && node.data && node.data.label) || dstLabel,
          label: (node && node.data && node.data.label) || dstLabel,
          ip: (node && node.data && node.data.ip) || d.dstIp || d.dst_ip || "",
        });
      }
    });

    const sorter = (a, b) => a.label.toLowerCase().localeCompare(b.label.toLowerCase());
    srcSuggestions = Array.from(srcSet.values()).sort(sorter);
    dstSuggestions = Array.from(dstSet.values()).sort(sorter);
  }

  function formatLocalDate(value) {
    if (!value) {
      return "unknown";
    }

    if (typeof value === "number" && Number.isFinite(value)) {
      try {
        return new Date(value * 1000).toLocaleString();
      } catch (error) {
        return String(value);
      }
    }

        const root = state.root || getEl("network-map-root");

    if (!Number.isNaN(date.getTime())) {
      return date.toLocaleString();
    }

    return String(value);
  }

  function formatAge(seconds) {
    const value = Number(seconds);

    if (!Number.isFinite(value) || value < 0) {
      return "";
    }

    if (value < 60) {
      return `${Math.round(value)}s`;
    }

    if (value < 3600) {
      return `${Math.round(value / 60)}m`;
    }

    return `${Math.round(value / 3600)}h`;
  }

  function setStatus(message, isError = false) {
    const status = getEl("knm-dataStatus");

    if (!status) {
      return;
    }

    status.textContent = message;
    status.classList.toggle("knm-status-error", !!isError);
  }

  function buildStatusText(meta) {
    const parts = [];

    if (meta.generated_at) {
      parts.push(`Updated: ${formatLocalDate(meta.generated_at)}`);
    } else if (meta.generated_at_iso) {
      parts.push(`Updated: ${formatLocalDate(meta.generated_at_iso)}`);
    }

    if (meta.time_from && meta.time_till) {
      parts.push(`Window: ${formatLocalDate(meta.time_from)} → ${formatLocalDate(meta.time_till)}`);
    } else if (meta.history_window_hours) {
      parts.push(`Window: last ${meta.history_window_hours}h`);
    }

    if (meta.nodes_count !== undefined) {
      parts.push(`Nodes: ${meta.nodes_count}`);
    }

    if (meta.edges_count !== undefined) {
      parts.push(`Edges: ${meta.edges_count}`);
    }

    if (meta.cached) {
      const age = formatAge(meta.cache_age_seconds);
      parts.push(age ? `Cache: yes (${age})` : "Cache: yes");
    } else {
      parts.push("Cache: fresh");
    }

    if (meta.stale) {
      parts.push("Using stale cache");
    }

    if (meta.warning) {
      parts.push(`Warning: ${meta.warning}`);
    }

    return parts.join(" | ");
  }

  function clearFadedGraph() {
    if (state.cy) {
      state.cy.elements().removeClass("faded");
    }
  }

  function hidePanels() {
    const summary = getEl("knm-summary");

    clearFadedGraph();

    if (summary) {
      summary.hidden = true;
    }
  }

  function setGraphPlaceholder(message) {
    const cyContainer = getEl("knm-cy");

    if (!cyContainer) {
      return;
    }

    cyContainer.innerHTML = `<div class="knm-graph-empty">${escapeHtml(message)}</div>`;
  }

  function readFilterSettings() {
    return {
      host: (getEl("knm-hostSelectValue") || {}).value || "",
      srcTokens: filters.parseListFilter
        ? filters.parseListFilter((getEl("knm-filterSrc") || {}).value || "")
        : [],
      dstTokens: filters.parseListFilter
        ? filters.parseListFilter((getEl("knm-filterDst") || {}).value || "")
        : [],
      portMatcher: filters.parsePortFilter
        ? filters.parsePortFilter(((getEl("knm-filterPort") || {}).value || "").trim())
        : null,
      excludePublic: !!(getEl("knm-excludePub") || {}).checked,
      excludeNoisePorts: (getEl("knm-excludeNoisePorts") || {}).checked !== false,
      ipFilters: filters.parseIpFilters
        ? filters.parseIpFilters(((getEl("knm-filterIp") || {}).value || "").trim())
        : [],
      minSep: Math.max(10, Number.parseInt(((getEl("knm-minSep") || {}).value || "50").trim(), 10) || 50),
      sx: Math.max(0.1, Number.parseFloat(((getEl("knm-scaleX") || {}).value || "1.0").trim()) || 1.0),
      sy: Math.max(0.1, Number.parseFloat(((getEl("knm-scaleY") || {}).value || "1.0").trim()) || 1.0),
    };
  }

  function applyFiltersAndDraw(options = {}) {
    const { showNoEdgesAlert = true } = options;

    if (!state.rawData) {
      setGraphPlaceholder("No network map data is loaded yet.");
      return false;
    }

    hidePanels();

    const settings = readFilterSettings();
    let subgraph = null;

    if (settings.host && typeof NM.buildSubgraph === "function") {
      subgraph = NM.buildSubgraph(
        settings.host,
        settings.srcTokens,
        settings.dstTokens,
        settings.portMatcher,
        settings.excludePublic,
        settings.excludeNoisePorts,
        settings.ipFilters
      );
    } else if (typeof NM.buildGlobalSubgraph === "function") {
      subgraph = NM.buildGlobalSubgraph(
        settings.srcTokens,
        settings.dstTokens,
        settings.portMatcher,
        settings.excludePublic,
        settings.excludeNoisePorts,
        settings.ipFilters
      );
    }

    if (!subgraph || !Array.isArray(subgraph.nodes) || !Array.isArray(subgraph.edges)) {
      setGraphPlaceholder("Failed to build the graph from the current filters.");
      state.hasDrawnGraph = false;
      return false;
    }

    if (typeof global.cytoscape === "undefined") {
      setGraphPlaceholder("Cytoscape failed to load.");
      state.hasDrawnGraph = false;
      return false;
    }

    if (typeof NM.drawGraph !== "function") {
      setGraphPlaceholder("Graph rendering code failed to load.");
      state.hasDrawnGraph = false;
      return false;
    }

    const drawn = NM.drawGraph({
      nodes: subgraph.nodes,
      edges: subgraph.edges,
      minSep: settings.minSep,
      sx: settings.sx,
      sy: settings.sy,
      showNoEdgesAlert,
    });

    state.hasDrawnGraph = drawn === true;

    if (!state.hasDrawnGraph) {
      if (!subgraph.edges.length) {
        setGraphPlaceholder("No edges matched the current filters.");
      } else {
        setGraphPlaceholder("The graph could not be rendered.");
      }
    }

    return state.hasDrawnGraph;
  }

  function withQuery(baseUrl, params) {
    const url = new URL(baseUrl, global.location.href);

    Object.entries(params || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, value);
      }
    });

    return url.toString();
  }

  async function fetchJson(url) {
    const response = await fetch(url, {
      headers: {
        Accept: "application/json",
      },
    });

    let payload = null;

    try {
      payload = await response.json();
    } catch (error) {
      if (!response.ok) {
        throw new Error(`Request failed with HTTP ${response.status}.`);
      }

      throw new Error("The server did not return valid JSON.");
    }

    if (payload && payload.error) {
      throw new Error(payload.error.message || "Unknown API error.");
    }

    if (!response.ok) {
      throw new Error(`Request failed with HTTP ${response.status}.`);
    }

    return payload;
  }

  async function fetchNetworkMap(options = {}) {
    const {
      force = false,
      redraw = true,
      showBusyOverlay = true,
      showNoEdgesAlert = false,
    } = options;
    const root = state.root || getEl("network-map-root");

    if (!root) {
      return null;
    }

    if (!root.dataset.dataUrl) {
      setStatus("The module view is missing the data endpoint URL.", true);
      return null;
    }

    const url = force ? withQuery(root.dataset.dataUrl, { force: "1" }) : root.dataset.dataUrl;

    if (showBusyOverlay) {
      showBusy(true);
    }

    try {
      const payload = sanitizeNetworkMapData(await fetchJson(url));
      state.rawData = payload;
      state.rawNodeMap = buildNodeMap(payload.nodes);

      populateHostSelect(payload.nodes);

      const statusText = buildStatusText(payload.meta || {});
      setStatus(statusText || "Network map loaded.");

      if (redraw) {
        applyFiltersAndDraw({ showNoEdgesAlert });
      }

      return payload;
    } catch (error) {
      console.error(error);
      setStatus(`Failed to load network map: ${error.message || String(error)}`, true);
      throw error;
    } finally {
      if (showBusyOverlay) {
        showBusy(false);
      }
    }
  }

  function bindSummaryPanel() {
    const inputs = ["knm-sumFilterSrc", "knm-sumFilterDst", "knm-sumFilterPort"]
      .map((id) => getEl(id))
      .filter(Boolean);
    const closeButton = getEl("knm-closeSummary");
    const minimizeButton = getEl("knm-minimizeSummary");

    inputs.forEach((element) => {
      element.addEventListener("input", () => {
        if (typeof NM.updateSummaryDisplay === "function") {
          NM.updateSummaryDisplay();
        }
      });
    });

    if (closeButton) {
      closeButton.addEventListener("click", () => {
        const panel = getEl("knm-summary");

        clearFadedGraph();

        if (panel) {
          panel.hidden = true;
        }
      });
    }

    if (minimizeButton) {
      minimizeButton.addEventListener("click", () => {
        const filtersContainer = getEl("knm-summaryFilters");
        const content = getEl("knm-summaryContent");

        if (!filtersContainer || !content) {
          return;
        }

        const collapsed = filtersContainer.style.display === "none";

        filtersContainer.style.display = collapsed ? "block" : "none";
        content.style.display = collapsed ? "block" : "none";
        minimizeButton.textContent = collapsed ? "Collapse" : "Expand";
      });
    }
  }


  function bindControls() {
    const applyButton = getEl("knm-btnApply");
    const refreshButton = getEl("knm-btnRefreshData");
    const hostInput = getEl("knm-hostSelect");
    const hostHidden = getEl("knm-hostSelectValue");
    const hostList = getEl("knm-hostSelect-ac");
    const srcInput = getEl("knm-filterSrc");
    const srcList = getEl("knm-filterSrc-ac");
    const dstInput = getEl("knm-filterDst");
    const dstList = getEl("knm-filterDst-ac");

    const filterInputs = [
      "knm-filterSrc",
      "knm-filterDst",
      "knm-filterPort",
      "knm-filterIp",
      "knm-minSep",
      "knm-scaleX",
      "knm-scaleY",
    ]
      .map((id) => getEl(id))
      .filter(Boolean);

    if (applyButton) {
      applyButton.addEventListener("click", () => {
        applyFiltersAndDraw({ showNoEdgesAlert: true });
      });
    }

    if (refreshButton) {
      refreshButton.addEventListener("click", () => {
        setStatus("Refreshing data…");
        fetchNetworkMap({
          force: true,
          redraw: true,
          showBusyOverlay: true,
          showNoEdgesAlert: false,
        }).catch(() => {
          // status is already set in fetchNetworkMap
        });
      });
    }

    // Host scope: searchable dropdown
    if (hostInput && hostList) {
      buildAutocomplete(
        hostInput,
        hostList,
        (query) => {
          const q = (query || "").toLowerCase();
          return hostItems.filter((item) => {
            if (!q) return true;
            return item.label.toLowerCase().includes(q) ||
              (item.ip && item.ip.toLowerCase().includes(q));
          });
        },
        (item) => {
          hostInput.value = item.value ? item.label : "";
          if (hostHidden) hostHidden.value = item.value;
          applyFiltersAndDraw({ showNoEdgesAlert: false });
        }
      );

      // Clear host selection when input is emptied manually
      hostInput.addEventListener("input", () => {
        if (!hostInput.value.trim()) {
          if (hostHidden) hostHidden.value = "";
        }
      });
    }

    // Source filter: autocomplete suggestions
    if (srcInput && srcList) {
      buildAutocomplete(
        srcInput,
        srcList,
        (query) => {
          const q = (query || "").toLowerCase();
          if (!q) return [];
          return srcSuggestions.filter((item) =>
            item.label.toLowerCase().includes(q) ||
            (item.ip && item.ip.toLowerCase().includes(q))
          );
        },
        (item) => {
          srcInput.value = item.label;
        }
      );
    }

    // Destination filter: autocomplete suggestions
    if (dstInput && dstList) {
      buildAutocomplete(
        dstInput,
        dstList,
        (query) => {
          const q = (query || "").toLowerCase();
          if (!q) return [];
          return dstSuggestions.filter((item) =>
            item.label.toLowerCase().includes(q) ||
            (item.ip && item.ip.toLowerCase().includes(q))
          );
        },
        (item) => {
          dstInput.value = item.label;
        }
      );
    }

    [getEl("knm-excludeNoisePorts"), getEl("knm-excludePub")]
      .filter(Boolean)
      .forEach((element) => {
        element.addEventListener("change", () => {
          applyFiltersAndDraw({ showNoEdgesAlert: false });
        });
      });

    filterInputs.forEach((element) => {
      element.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          applyFiltersAndDraw({ showNoEdgesAlert: true });
        }
      });
    });
  }

  function init() {
    const root = getEl("network-map-root");

    if (!root) {
      return;
    }

    if (state.initialized && state.root === root) {
      return;
    }

    ensureStateDefaults();
    state.root = root;

    buildLayout(root);
    bindSummaryPanel();
    bindControls();

    state.initialized = true;

    setStatus("Loading network map…");

    fetchNetworkMap({
      force: false,
      redraw: true,
      showBusyOverlay: true,
      showNoEdgesAlert: false,
    }).catch(() => {
      // fetchNetworkMap already updated the status text
    });
  }

  document.addEventListener("DOMContentLoaded", init);

  if (document.readyState !== "loading") {
    init();
  }
})(window);
