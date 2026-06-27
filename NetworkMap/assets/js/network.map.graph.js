// Network Map - graph
(function (global) {
  "use strict";

  if (typeof global.cytoscape !== "undefined" && typeof global.cytoscapeCoseBilkent !== "undefined") {
    global.cytoscape.use(global.cytoscapeCoseBilkent);
  }

  const NM = (global.NetworkMap = global.NetworkMap || {});
  const filters = NM.filters;
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
        themeObserver: null,
        summaryData: { incoming: [], outgoing: [] },
        hasDrawnGraph: false,
      });

  function getRawNodeMap() {
    if (state.rawNodeMap instanceof Map) {
      return state.rawNodeMap;
    }

    const map = new Map();

    (state.rawData?.nodes || []).forEach((node) => {
      const id = node && node.data && node.data.id;
      if (id) {
        map.set(id, node);
      }
    });

    state.rawNodeMap = map;
    return map;
  }

  function getThemeRoot() {
    return state.root || document.getElementById("network-map-root") || document.documentElement;
  }

  function readCssVar(styles, name, fallback) {
    const value = styles.getPropertyValue(name);
    return value ? value.trim() : fallback;
  }

  function getThemePalette() {
    const styles = global.getComputedStyle(getThemeRoot());
    const muted = readCssVar(styles, "--knm-muted", "#667085");

    return {
      nodeText: readCssVar(styles, "--knm-node-text", "#ffffff"),
      nodeOutline: readCssVar(styles, "--knm-node-outline", "#333333"),
      edgeColor: readCssVar(styles, "--knm-edge-color", "#98a2b3"),
      edgeText: readCssVar(styles, "--knm-edge-text", muted),
      fadedOpacity: Number.parseFloat(readCssVar(styles, "--knm-faded-opacity", "0.1")) || 0.1,
    };
  }

  function applyCurrentGraphTheme() {
    if (!state.cy) {
      return;
    }

    const palette = getThemePalette();

    state.cy
      .style()
      .selector("node")
      .style({
        color: palette.nodeText,
        "text-outline-color": palette.nodeOutline,
      })
      .selector("edge")
      .style({
        color: palette.edgeText,
        "line-color": palette.edgeColor,
        "target-arrow-color": palette.edgeColor,
      })
      .selector(".faded")
      .style({
        opacity: palette.fadedOpacity,
      })
      .update();
  }

  function removeThemeObserver() {
    if (state.themeObserver) {
      state.themeObserver.disconnect();
      state.themeObserver = null;
    }
  }

  function ensureThemeObserver() {
    removeThemeObserver();

    if (typeof global.MutationObserver === "undefined") {
      return;
    }

    const themeHost = document.querySelector("[theme]") || document.documentElement;

    if (!themeHost) {
      return;
    }

    state.themeObserver = new global.MutationObserver((mutations) => {
      if (mutations.some((mutation) => mutation.type === "attributes" && mutation.attributeName === "theme")) {
        applyCurrentGraphTheme();
      }
    });

    state.themeObserver.observe(themeHost, {
      attributes: true,
      attributeFilter: ["theme"],
    });
  }

  function setCurrentGraph(nodes, edges) {
    state.currentGraph = {
      nodes: Array.isArray(nodes) ? nodes.slice() : [],
      edges: Array.isArray(edges) ? edges.slice() : [],
    };
  }

  function removeResizeHandler() {
    if (state.resizeHandler) {
      global.removeEventListener("resize", state.resizeHandler);
      state.resizeHandler = null;
    }
  }

  function hideDetailPanels() {
    const summary = document.getElementById("knm-summary");

    if (summary) {
      summary.hidden = true;
    }
  }

  function buildSubgraph(host, srcTokens, dstTokens, portMatcher, excludePublic, excludeNoisePorts, ipFilters) {
    const edges = (state.rawData?.edges || []).filter(
      (edge) =>
        (edge.data.source === host || edge.data.target === host) &&
        filters.edgeMatches(
          edge.data,
          srcTokens,
          dstTokens,
          portMatcher,
          excludePublic,
          excludeNoisePorts,
          ipFilters
        )
    );

    const ids = new Set([host]);
    edges.forEach((edge) => {
      ids.add(edge.data.source);
      ids.add(edge.data.target);
    });

    const nodeMap = getRawNodeMap();
    const nodes = Array.from(ids)
      .map((id) => nodeMap.get(id))
      .filter(Boolean);

    return { nodes, edges };
  }

  function buildGlobalSubgraph(srcTokens, dstTokens, portMatcher, excludePublic, excludeNoisePorts, ipFilters) {
    const edges = (state.rawData?.edges || []).filter((edge) =>
      filters.edgeMatches(
        edge.data,
        srcTokens,
        dstTokens,
        portMatcher,
        excludePublic,
        excludeNoisePorts,
        ipFilters
      )
    );

    const ids = new Set();
    edges.forEach((edge) => {
      ids.add(edge.data.source);
      ids.add(edge.data.target);
    });

    const nodeMap = getRawNodeMap();
    const nodes = Array.from(ids)
      .map((id) => nodeMap.get(id))
      .filter(Boolean);

    return { nodes, edges };
  }

  function graphLayout(minSep) {
    if (typeof global.cytoscapeCoseBilkent !== "undefined") {
      return {
        name: "cose-bilkent",
        animate: false,
        fit: false,
        idealEdgeLength: minSep * 1.5,
        nodeSeparation: minSep,
        avoidOverlap: true,
      };
    }

    return {
      name: "cose",
      animate: false,
      fit: false,
      idealEdgeLength: minSep * 1.5,
      nodeRepulsion: 450000,
      padding: 30,
    };
  }

  function drawGraph({ nodes, edges, minSep, sx, sy, showNoEdgesAlert = true }) {
    const cyContainer = document.getElementById("knm-cy");

    if (!cyContainer || typeof global.cytoscape === "undefined") {
      return false;
    }

    removeResizeHandler();
    removeThemeObserver();

    if (state.cy) {
      state.cy.destroy();
      state.cy = null;
    }

    if (!Array.isArray(nodes) || !Array.isArray(edges) || !nodes.length || !edges.length) {
      setCurrentGraph([], []);
      state.summaryData = { incoming: [], outgoing: [] };
      hideDetailPanels();

      cyContainer.innerHTML = "";

      // The inline placeholder/banner is set by setGraphPlaceholder() in the
      // app layer; no blocking window.alert() here.
      return false;
    }

    setCurrentGraph(nodes, edges);

    const palette = getThemePalette();
    const degrees = nodes.map((node) => node.data.degree || 0);
    const minDegree = Math.min(...degrees);
    const maxDegree = Math.max(...degrees);
    const nodeSize = minDegree === maxDegree ? 40 : `mapData(degree, ${minDegree}, ${maxDegree}, 20, 60)`;

    state.cy = global.cytoscape({
      container: cyContainer,
      elements: {
        nodes: nodes.map((node) => ({ data: node.data })),
        edges: edges.map((edge) => ({ data: edge.data })),
      },
      layout: graphLayout(minSep),
      style: [
        {
          selector: "node",
          style: {
            shape: "ellipse",
            width: nodeSize,
            height: nodeSize,
            label: "data(label)",
            "background-color": "data(color)",
            color: palette.nodeText,
            "font-size": 10,
            "text-valign": "center",
            "text-wrap": "wrap",
            "text-max-width": 90,
            "text-outline-width": 2,
            "text-outline-color": palette.nodeOutline,
          },
        },
        {
          selector: "edge",
          style: {
            width: 1,
            color: palette.edgeText,
            "line-color": palette.edgeColor,
            "target-arrow-shape": "triangle",
            "target-arrow-color": palette.edgeColor,
            "curve-style": "bezier",
            label: "data(label)",
            "font-size": 8,
          },
        },
        {
          selector: ".faded",
          style: {
            opacity: palette.fadedOpacity,
          },
        },
      ],
    });

    ensureThemeObserver();

    state.cy.ready(() => {
      state.cy.nodes().forEach((node) => {
        const position = node.position();
        node.position({ x: position.x * sx, y: position.y * sy });
      });

      state.cy.fit(40);
      applyCurrentGraphTheme();

      state.resizeHandler = () => {
        if (!state.cy) {
          return;
        }

        state.cy.resize();
        state.cy.fit(40);
      };

      global.addEventListener("resize", state.resizeHandler);
    });

    state.cy.on("tap", "node", (event) => {
      if (typeof NM.showSummary === "function") {
        NM.showSummary(event.target);
      }
    });

    state.cy.on("tap", (event) => {
      if (event.target === state.cy) {
        state.cy.elements().removeClass("faded");
        hideDetailPanels();
      }
    });

    return true;
  }

  NM.buildSubgraph = buildSubgraph;
  NM.buildGlobalSubgraph = buildGlobalSubgraph;
  NM.drawGraph = drawGraph;
})(window);

