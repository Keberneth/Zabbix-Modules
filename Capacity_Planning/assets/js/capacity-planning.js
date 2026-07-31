(function () {
	'use strict';

	const RISKS = [
		{key: 'Critical', color: '#b42318', dark: true},
		{key: 'High', color: '#d92d20', dark: true},
		{key: 'Medium', color: '#f79009', dark: true},
		{key: 'Watch', color: '#fec84b', dark: false},
		{key: 'Healthy', color: '#12b76a', dark: true},
		{key: 'Unknown', color: '#667085', dark: true}
	];
	const RISK_BY_KEY = Object.fromEntries(RISKS.map((r) => [r.key, r]));
	const RISK_ORDER = {Critical: 5, High: 4, Medium: 3, Watch: 2, Healthy: 1, Unknown: 0, Pending: -1};
	const CONFIDENCE_RANK = {High: 4, Medium: 3, Low: 2, None: 1, 'Current state': 0, '': -1};
	const HTML_ESCAPES = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

	const LOOKBACKS = [
		{days: 92, label: '3M'}, {days: 183, label: '6M'}, {days: 365, label: '12M'}
	];
	const MIN_LOOKBACK_DAYS = 7;
	const MAX_LOOKBACK_DAYS = 730;
	const RESOURCE_TABS = ['cpu', 'memory'];
	const WINDOW_LABELS = {
		'12m': '12 months', '6m': '6 months', '3m': '3 months', '1m': '1 month',
		'2w': '2 weeks', '1w': '1 week'
	};
	const HORIZON_DAYS = 365;
	const FORECAST_WORKER_LIMIT = 3;
	// The backend removes at most 10,000 entries per request and the cache has a
	// hard 100,000-entry bound. Twenty attempts leave room for fixed metadata and
	// directory cleanup without allowing an unbounded browser loop.
	const MAX_CACHE_CLEAR_REQUESTS = 20;
	const GIB = 1024 * 1024 * 1024;
	const CACHE_OPTIONS = [
		{seconds: 0, label: 'Off'},
		{seconds: 900, label: '15 minutes'},
		{seconds: 1800, label: '30 minutes'},
		{seconds: 3600, label: '60 minutes'}
	];

	const PALETTE_LIGHT = {
		grid: '#d7e1ec', axis: '#8695a5', axisText: '#5c6b7a', title: '#34485f',
		line: '#0f6ad8', band: 'rgba(15,106,216,0.14)', projection: '#7c5cd6',
		warn: '#f79009', crit: '#d92d20', today: '#8695a5', crosshair: '#0f6ad8'
	};
	const PALETTE_DARK = {
		grid: '#33425a', axis: '#5b6b7f', axisText: '#9fb0c3', title: '#dbe6f0',
		line: '#7499FF', band: 'rgba(116,153,255,0.18)', projection: '#b39ddb',
		warn: '#f5a623', crit: '#f97066', today: '#5b6b7f', crosshair: '#7499FF'
	};

	document.addEventListener('DOMContentLoaded', () => {
		const root = document.getElementById('capacity-planning-root');
		if (root) {
			new CapacityPlanningApp(root).init();
		}
	});

	class CapacityPlanningApp {
		constructor(root) {
			this.root = root;
			this.dataUrl = root.dataset.dataUrl || 'zabbix.php?action=capacity.planning.data';
			this.settingsSaveUrl = root.dataset.settingsSaveUrl
				|| 'zabbix.php?action=capacity.planning.settings.save';
			this.cacheStatusUrl = root.dataset.cacheStatusUrl
				|| 'zabbix.php?action=capacity.planning.cache.status';
			const parseObject = (raw) => {
				try {
					const value = JSON.parse(raw || '{}');
					return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
				}
				catch (_error) { return {}; }
			};
			this.cacheSettings = this.normalizeCacheSettings(parseObject(root.dataset.cacheSettings));
			this.cacheStatus = parseObject(root.dataset.cacheStatus);
			this.canManageSettings = root.dataset.canManageSettings === '1';
			this.csrfName = root.dataset.csrfName || '';
			this.csrfToken = root.dataset.csrfToken || '';
			// Sent only with refresh=1; the server downgrades a forced refresh to a
			// normal cached load when this per-action token is missing or invalid.
			this.dataCsrfToken = root.dataset.dataCsrfToken || '';
			this.settingsBusy = false;
			this.cacheStatusRequested = false;
			this.cacheStatusLoading = false;
			this.analysisStarted = false;
			this.isLoading = false;

			this.lookbackDays = 92;
			this.activeTab = 'overview';
			this.filters = {group: '', host: '', template: '', name: ''};
			this.resultFilters = {group: '', hostid: '', type: '', status: ''};
			this.resolved = null; // {fsig, groupids, hostids, empty, truncated, summary}
			this.scopePreviewTimer = null;
			this.scopePreviewAbort = null;
			this.scopePreviewSeq = 0;
			this.scopePreview = null;
			this.scopePreviewResolved = null;
			this.scopePreviewValidatedSig = null;
			this.scopePreviewFocusKey = null;
			this.activeRisks = new Set(RISKS.map((r) => r.key));
			this.elements = {};

			this.inv = this.emptyInventory();
			this.fc = new Map(); // finding id -> forecast payload
			this.fcTotal = 0;
			this.fcDone = 0;
			this.fcReady = false;
			// Server clock the current forecast run's ETAs are anchored to; charts
			// use it so "today"/crossing markers agree with the printed ETA dates.
			this.fcGeneratedAt = null;
			this.forecastRuns = new Map(); // completed lookback -> page-lifetime result
			this.cacheRunMeta = null;

			this.sort = {
				disks: {key: 'risk', dir: 'desc'},
				resources: {key: 'risk', dir: 'desc'}
			};
			this.pageSize = 25;
			this.pages = {disks: 1, resources: 1};
			this.selected = null; // {kind: 'disk'|'res', id}
			this.detailGeom = null;
			this.detailLastFocused = null;
			this.detailChartSpec = null;
			this.detailChartId = null;
			this.detailZoom = null;
			this.detailDrag = null;
			this.resultFilterTimer = null;

			this.loadSeq = 0;
			this.loadAbort = null;
		}

		emptyInventory() {
			return {sig: null, disks: [], resources: [], quality: [], meta: null, ready: false,
				hosts: [], hostgroups: [], byHostId: new Map(), byId: new Map()};
		}

		// ---- lifecycle -------------------------------------------------------------
		init() {
			this.renderShell();
			this.bindEvents();
			this.applyInitialState();
			this.renderSettings();
			this.updateExportState();
			if (this.activeTab === 'settings') {
				this.setLoading(false);
				this.updateLocationState();
				this.loadCacheStatus();
			}
			else {
				this.load();
			}
		}

		getPalette() {
			const themed = this.root.closest('[theme]');
			const theme = themed ? themed.getAttribute('theme') : '';
			return (theme === 'dark-theme' || theme === 'hc-dark') ? PALETTE_DARK : PALETTE_LIGHT;
		}

		scopeFieldHtml(key, label, placeholder, grow = false) {
			const inputId = `cap-f-${key}`;
			const listId = `cap-scope-${key}-options`;
			const statusId = `cap-scope-${key}-status`;
			return `<div class="cap-field cap-scope-field${grow ? ' cap-field-grow' : ''}" data-scope-field="${key}">
				<label for="${inputId}">${this.escapeHtml(label)}</label>
				<div class="cap-scope-input-wrap">
					<input type="text" id="${inputId}" data-filter="${key}" placeholder="${this.escapeHtml(placeholder)}" maxlength="2048"
						autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list"
						aria-haspopup="listbox" aria-expanded="false" aria-controls="${listId}"
						aria-describedby="cap-scope-help ${statusId}">
					<div class="cap-scope-options" id="${listId}" data-scope-options="${key}" role="listbox"
						aria-label="Matching ${this.escapeHtml(label.toLowerCase())}" hidden></div>
				</div>
				<div class="cap-scope-field-status" id="${statusId}" data-scope-field-status="${key}"></div>
			</div>`;
		}

		renderShell() {
			this.root.innerHTML = `
				<div class="cap-shell">
					<div class="cap-toolbar" data-role="analysis-toolbar">
						<div class="cap-toolbar-group">
							<div class="cap-field">
								<label>Analysis lookback</label>
								<div class="cap-seg" data-role="lookback">
									${LOOKBACKS.map((l) => `<button type="button" class="cap-seg-btn" data-days="${l.days}">${l.label}</button>`).join('')}
									<button type="button" class="cap-seg-btn" data-role="custom-lookback-toggle">Custom</button>
								</div>
							</div>
							<div class="cap-custom-lookback" data-role="custom-lookback" hidden>
								<div class="cap-field"><label for="cap-custom-lookback-days">Custom range</label><div class="cap-inline-input"><input type="number" id="cap-custom-lookback-days" min="${MIN_LOOKBACK_DAYS}" max="${MAX_LOOKBACK_DAYS}" step="1" value="92"><span>days ending today</span></div></div>
								<div class="cap-field"><label>&nbsp;</label><button type="button" class="cap-btn cap-btn-primary" data-role="apply-custom-lookback">Apply range</button></div>
							</div>
							<div class="cap-field">
								<label>&nbsp;</label>
						<button type="button" class="cap-btn" data-role="reload">Refresh now</button>
							</div>
						</div>
						<div class="cap-actions">
							<button type="button" class="cap-btn" data-role="export-png">Export PNG</button>
							<button type="button" class="cap-btn" data-role="export-html">Export HTML</button>
							<button type="button" class="cap-btn" data-role="export-csv">Export CSV</button>
						</div>
					</div>

					<div class="cap-filterbar" data-role="analysis-scope">
						<div class="cap-filter-heading"><strong>Inventory scope</strong><span>Matching names are previewed as you type; Apply scope loads the report.</span></div>
						<div class="cap-scope-help" id="cap-scope-help">Separate values with commas. Plain text is a case-insensitive contains match; use <code>/pattern/i</code> for a regular expression. Values in one field use OR; populated fields combine with AND.</div>
						${this.scopeFieldHtml('group', 'Host groups', 'e.g. Databases, Production')}
						${this.scopeFieldHtml('host', 'Hosts', 'e.g. db01, /web-\\d+/i')}
						${this.scopeFieldHtml('template', 'Templates', 'e.g. Linux, /^SAP HANA/i', true)}
						<div class="cap-filter-actions">
							<button type="button" class="cap-btn cap-btn-primary" data-role="apply-filters">Apply scope</button>
							<button type="button" class="cap-btn" data-role="clear-filters">Clear scope</button>
						</div>
						<div class="cap-scope-error" data-role="scope-error" role="alert" hidden></div>
						<div class="cap-scope-preview-summary" data-role="scope-preview-summary" role="status" aria-live="polite" aria-atomic="true"></div>
						<div class="cap-filter-summary" data-role="filter-summary"></div>
					</div>

					<div class="cap-results-filter" data-role="results-filter">
						<div class="cap-filter-heading"><strong>Displayed results</strong><span>Instant filters; these also control overview counts and exports.</span></div>
						<div class="cap-results-controls">
							<div class="cap-field cap-field-grow"><label for="cap-r-query">Search host or resource</label><input type="search" id="cap-r-query" data-result-filter="name" placeholder="Host, filesystem, CPU or memory"></div>
							<div class="cap-field"><label for="cap-r-group">Host group</label><select id="cap-r-group" data-result-filter="group"><option value="">All host groups</option></select></div>
							<div class="cap-field"><label for="cap-r-host">Host</label><select id="cap-r-host" data-result-filter="hostid"><option value="">All hosts</option></select></div>
							<div class="cap-field"><label for="cap-r-type">Resource type</label><select id="cap-r-type" data-result-filter="type">
								<option value="">All resource types</option><option value="disk-local">Local filesystems</option><option value="disk-remote">Remote filesystems</option><option value="cpu">CPU</option><option value="memory">Memory</option>
							</select></div>
							<div class="cap-field"><label for="cap-r-status">Data status</label><select id="cap-r-status" data-result-filter="status"><option value="">All data states</option><option value="issues">Data issues only</option></select></div>
							<div class="cap-filter-actions"><button type="button" class="cap-btn" data-role="clear-result-filters">Clear displayed filters</button></div>
						</div>
						<div class="cap-results-summary" data-role="results-summary" aria-live="polite"></div>
					</div>

					<div class="cap-risk-filter" data-role="risk-filter">
						<span class="cap-risk-filter-label">Capacity risk</span>
						<div class="cap-risk-presets">
							<button type="button" class="cap-link-btn" data-risk-preset="actionable">Action required</button>
							<button type="button" class="cap-link-btn" data-risk-preset="all">Show all</button>
						</div>
						<div class="cap-risk-filter-options">
							${RISKS.map((r) => `<label class="cap-filter-check"><input type="checkbox" value="${r.key}" checked><span class="cap-badge risk-${r.key.toLowerCase()}"></span>${r.key}<span class="cap-filter-count" data-risk-count="${r.key}">0</span></label>`).join('')}
						</div>
					</div>

					<div class="cap-tabs" data-role="tabs">
						<button type="button" class="cap-tab is-active" data-tab="overview">Overview</button>
						<button type="button" class="cap-tab" data-tab="disks">Filesystems</button>
						<button type="button" class="cap-tab" data-tab="cpu">CPU</button>
						<button type="button" class="cap-tab" data-tab="memory">RAM</button>
						<button type="button" class="cap-tab" data-tab="settings">Settings</button>
					</div>

					<div data-role="analysis-status">
						<div class="cap-meta" data-role="meta"></div>
						<div data-role="warning" hidden></div>
						<div data-role="error" hidden></div>
						<div class="cap-loading" data-role="loading">Loading…</div>
					</div>

					<div class="cap-tabpanel" data-panel="overview">
						<div class="cap-cards" data-role="cards"></div>
						<div class="cap-grid">
							<div class="cap-card">
								<h3>Capacity runway</h3>
								<p class="cap-card-subtitle">Projected days until each filesystem reaches its next Zabbix alarm threshold. Hover for dates, click a bar for details.</p>
								<div class="cap-chart-surface" data-role="runway-surface"></div>
							</div>
							<div class="cap-card">
								<h3>Risk distribution</h3>
								<p class="cap-card-subtitle">Findings per risk level across the selected scope.</p>
								<div class="cap-chart-fit" data-role="dist-surface"></div>
							</div>
						</div>
						<div class="cap-card">
							<h3>Top capacity risks</h3>
							<p class="cap-card-subtitle">The most urgent findings across filesystems, CPU and memory.</p>
							<div data-role="top-risks"></div>
						</div>
						<div class="cap-card">
							<h3>Data quality</h3>
							<p class="cap-card-subtitle" data-role="quality-subtitle">Threshold fallbacks, stale items and gaps that reduce forecast confidence.</p>
							<div data-role="quality-body"></div>
						</div>
					</div>

					<div class="cap-tabpanel" data-panel="disks" hidden>
						<div class="cap-card">
							<h3>Filesystem capacity forecast</h3>
							<p class="cap-card-subtitle" data-role="disks-subtitle">Growth is a robust trend over the best-covered window; ETAs are projected threshold crossings, not exact Zabbix problem times. Open a row for the usage chart.</p>
							<div data-role="disks-body"></div>
						</div>
					</div>

					<div class="cap-tabpanel" data-panel="resources" hidden>
						<div class="cap-card">
							<h3 data-role="resources-title">CPU capacity evidence</h3>
							<p class="cap-card-subtitle" data-role="resources-subtitle">Risk combines sustained utilization with confirmed peak recurrence, duration and height against each host's Zabbix thresholds. A single isolated spike is not an upgrade decision. Open a row for the full evidence and historical chart.</p>
							<div data-role="resources-body"></div>
						</div>
					</div>

					<div class="cap-tabpanel" data-panel="settings" hidden>
						<div class="cap-settings-grid">
							<section class="cap-card cap-settings-card">
								<h3>Shared analysis cache</h3>
								<p class="cap-card-subtitle">This installation-wide cache reuses numeric history and trend data across users and analysis ranges. It reduces repeat Zabbix API reads; host metadata and completed reports are not stored here.</p>
								<div class="cap-cache-summary" data-role="cache-summary"></div>
								<dl class="cap-settings-status" data-role="cache-status"></dl>
							</section>
							<section class="cap-card cap-settings-card">
								<h3>Cache controls</h3>
								${this.canManageSettings ? `
									<p class="cap-card-subtitle">Choose when mutable current-day and current-month shards load newer samples. This interval is not retention: historical shards remain until age or size cleanup, or a manual clear.</p>
									<div class="cap-settings-control">
										<div class="cap-field"><label for="cap-cache-ttl">Recent-shard refresh interval</label><select id="cap-cache-ttl" data-role="cache-ttl">${CACHE_OPTIONS.map((option) => `<option value="${option.seconds}">${option.label}</option>`).join('')}</select></div>
										<div class="cap-settings-actions">
											<button type="button" class="cap-btn cap-btn-primary" data-role="save-cache-settings">Save</button>
											<button type="button" class="cap-btn" data-role="clear-shared-cache">Clear shared cache</button>
										</div>
									</div>` : `
									<p class="cap-settings-readonly" data-role="settings-readonly">These installation-wide controls are read-only for your account. A Zabbix Super Admin can change the recent-shard refresh interval or clear the shared cache.</p>`}
								<div class="cap-settings-message" data-role="settings-message" aria-live="polite" aria-atomic="true" hidden></div>
							</section>
						</div>
					</div>

					<div class="cap-detail-modal" data-role="detail-modal" role="dialog" aria-modal="true" aria-labelledby="cap-detail-modal-title" aria-hidden="true">
						<div class="cap-modal-overlay" data-close-detail></div>
						<div class="cap-modal-container" role="document">
							<button type="button" class="cap-modal-close" data-role="detail-close" data-close-detail aria-label="Close detail window">&#10005;</button>
							<div class="cap-modal-content">
								<p class="cap-modal-eyebrow" data-role="detail-eyebrow">Capacity evidence</p>
								<h2 class="cap-modal-title" id="cap-detail-modal-title" data-role="detail-title">Capacity detail</h2>
								<p class="cap-card-subtitle" data-role="detail-subtitle" aria-live="polite"></p>
								<div class="cap-modal-zoombar"><span data-role="detail-zoom-label" hidden></span><button type="button" class="cap-link-btn" data-role="detail-zoom-reset" hidden>Reset zoom</button></div>
								<div class="cap-detail-stats" data-role="detail-stats"></div>
								<div class="cap-legend" data-role="detail-legend"></div>
								<div class="cap-chart-surface cap-modal-chart" data-role="detail-surface" tabindex="0" aria-label="Interactive capacity history chart"></div>
								<div class="cap-modal-hint">Drag across the historical timeline to inspect a smaller range. Reset zoom returns to the complete analysis and projection.</div>
							</div>
						</div>
					</div>
				</div>
			`;

			const q = (s) => this.root.querySelector(s);
			this.elements = {
				analysisToolbar: q('[data-role="analysis-toolbar"]'),
				lookback: q('[data-role="lookback"]'), customLookback: q('[data-role="custom-lookback"]'),
				customLookbackToggle: q('[data-role="custom-lookback-toggle"]'),
				customLookbackDays: q('#cap-custom-lookback-days'),
				applyCustomLookback: q('[data-role="apply-custom-lookback"]'), reload: q('[data-role="reload"]'),
				exportPng: q('[data-role="export-png"]'), exportHtml: q('[data-role="export-html"]'),
				exportCsv: q('[data-role="export-csv"]'),
				filterbar: q('.cap-filterbar'), filterSummary: q('[data-role="filter-summary"]'),
				scopeError: q('[data-role="scope-error"]'),
				scopePreviewSummary: q('[data-role="scope-preview-summary"]'),
				applyFilters: q('[data-role="apply-filters"]'), clearFilters: q('[data-role="clear-filters"]'),
				resultsFilter: q('[data-role="results-filter"]'), resultsSummary: q('[data-role="results-summary"]'),
				clearResultFilters: q('[data-role="clear-result-filters"]'),
				riskFilter: q('[data-role="risk-filter"]'), tabs: q('[data-role="tabs"]'),
				analysisStatus: q('[data-role="analysis-status"]'),
				meta: q('[data-role="meta"]'), warning: q('[data-role="warning"]'), error: q('[data-role="error"]'),
				loading: q('[data-role="loading"]'),
				cards: q('[data-role="cards"]'), runwaySurface: q('[data-role="runway-surface"]'),
				distSurface: q('[data-role="dist-surface"]'), topRisks: q('[data-role="top-risks"]'),
				qualitySubtitle: q('[data-role="quality-subtitle"]'), qualityBody: q('[data-role="quality-body"]'),
				disksBody: q('[data-role="disks-body"]'), disksSubtitle: q('[data-role="disks-subtitle"]'),
				resourcesTitle: q('[data-role="resources-title"]'), resourcesSubtitle: q('[data-role="resources-subtitle"]'),
				resourcesBody: q('[data-role="resources-body"]'),
				cacheSummary: q('[data-role="cache-summary"]'), cacheStatus: q('[data-role="cache-status"]'),
				cacheTtl: q('[data-role="cache-ttl"]'), saveCacheSettings: q('[data-role="save-cache-settings"]'),
				clearSharedCache: q('[data-role="clear-shared-cache"]'),
				settingsMessage: q('[data-role="settings-message"]'),
				detailModal: q('[data-role="detail-modal"]'), detailClose: q('[data-role="detail-close"]'),
				detailEyebrow: q('[data-role="detail-eyebrow"]'), detailTitle: q('[data-role="detail-title"]'),
				detailSubtitle: q('[data-role="detail-subtitle"]'), detailStats: q('[data-role="detail-stats"]'),
				detailLegend: q('[data-role="detail-legend"]'), detailSurface: q('[data-role="detail-surface"]'),
				detailZoomLabel: q('[data-role="detail-zoom-label"]'), detailZoomReset: q('[data-role="detail-zoom-reset"]'),
				panelOverview: q('[data-panel="overview"]'), panelDisks: q('[data-panel="disks"]'),
				panelResources: q('[data-panel="resources"]'), panelSettings: q('[data-panel="settings"]')
			};
			// Keep the detail renderers compact while both kinds share one modal.
			Object.assign(this.elements, {
				diskDetail: this.elements.detailModal, diskDetailTitle: this.elements.detailTitle,
				diskDetailSubtitle: this.elements.detailSubtitle, diskDetailStats: this.elements.detailStats,
				diskDetailLegend: this.elements.detailLegend, diskDetailSurface: this.elements.detailSurface,
				resDetail: this.elements.detailModal, resDetailTitle: this.elements.detailTitle,
				resDetailSubtitle: this.elements.detailSubtitle, resDetailStats: this.elements.detailStats,
				resDetailLegend: this.elements.detailLegend, resDetailSurface: this.elements.detailSurface
			});

			this.tooltip = document.createElement('div');
			this.tooltip.className = 'cap-tooltip';
			this.tooltip.setAttribute('role', 'tooltip');
			this.tooltip.hidden = true;
			this.root.appendChild(this.tooltip);
		}

		bindEvents() {
			this.elements.lookback.addEventListener('click', (e) => {
				const b = e.target.closest('[data-days]');
				if (b) { this.setLookback(Number(b.dataset.days)); }
				if (e.target.closest('[data-role="custom-lookback-toggle"]')) { this.toggleCustomLookback(); }
			});
			this.elements.applyCustomLookback.addEventListener('click', () => this.applyCustomLookback());
			this.elements.customLookbackDays.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') { this.applyCustomLookback(); }
			});
			this.elements.reload.addEventListener('click', () => this.load(true));
			if (this.elements.saveCacheSettings) {
				this.elements.saveCacheSettings.addEventListener('click', () => this.saveCacheConfiguration());
			}
			if (this.elements.clearSharedCache) {
				this.elements.clearSharedCache.addEventListener('click', () => this.clearSharedCache());
			}

			this.elements.applyFilters.addEventListener('click', () => this.applyFilters());
			this.elements.clearFilters.addEventListener('click', () => this.clearFilters());
			this.elements.filterbar.addEventListener('input', (e) => {
				if (e.target.matches('input[data-filter]')) { this.queueScopePreview(e.target); }
			});
			this.elements.filterbar.addEventListener('focusin', (e) => {
				if (e.target.matches('input[data-filter]')) {
					this.scopePreviewFocusKey = e.target.dataset.filter;
					this.openScopeSuggestions(this.scopePreviewFocusKey);
				}
			});
			this.elements.filterbar.addEventListener('click', (e) => {
				const suggestion = e.target.closest('[data-scope-suggestion]');
				if (suggestion) { this.chooseScopeSuggestion(suggestion); }
				else if (e.target.matches('input[data-filter]')) {
					this.scopePreviewFocusKey = e.target.dataset.filter;
					this.openScopeSuggestions(this.scopePreviewFocusKey);
				}
			});
			this.elements.filterbar.addEventListener('keydown', (e) => {
				this.onScopeKeydown(e);
			});
			document.addEventListener('pointerdown', (e) => {
				if (!this.elements.filterbar.contains(e.target)) { this.closeScopeSuggestions(); }
			});
			this.elements.resultsFilter.addEventListener('input', (e) => {
				if (e.target.matches('[data-result-filter="name"]')) { this.queueResultFilterApply(); }
			});
			this.elements.resultsFilter.addEventListener('change', (e) => {
				if (e.target.matches('select[data-result-filter]')) { this.applyResultFilters(); }
			});
			this.elements.clearResultFilters.addEventListener('click', () => this.clearResultFilters());

			this.elements.tabs.addEventListener('click', (e) => {
				const b = e.target.closest('[data-tab]');
				if (b) { this.switchTab(b.dataset.tab); }
			});

			this.elements.riskFilter.addEventListener('change', (e) => {
				if (e.target.type === 'checkbox') { this.onRiskToggle(); }
			});
			this.elements.riskFilter.addEventListener('click', (e) => {
				const preset = e.target.closest('[data-risk-preset]');
				if (preset) { this.applyRiskPreset(preset.dataset.riskPreset); }
			});

			this.elements.exportCsv.addEventListener('click', () => this.exportCsv());
			this.elements.exportHtml.addEventListener('click', () => this.exportHtml());
			this.elements.exportPng.addEventListener('click', () => {
				this.exportPng().catch((err) => this.showError(err instanceof Error ? err.message : 'Failed to export PNG.'));
			});

			this.elements.detailModal.querySelectorAll('[data-close-detail]').forEach((el) => {
				el.addEventListener('click', () => this.hideDetail());
			});
			this.elements.detailZoomReset.addEventListener('click', () => this.resetDetailZoom());
			document.addEventListener('keydown', (e) => this.onDetailModalKeydown(e));
			this.bindDetailSurface(this.elements.detailSurface);

			// Runway interactions are delegated to the persistent surface so re-renders
			// mid-hover cannot orphan the tooltip or drop handlers.
			this.elements.runwaySurface.addEventListener('click', (e) => {
				const g = e.target.closest('.cap-runway-row');
				if (g) {
					this.switchTab('disks');
					this.openDetail('disk', g.dataset.id, g);
				}
			});
			this.elements.runwaySurface.addEventListener('keydown', (e) => {
				const g = e.target.closest('.cap-runway-row');
				if (g && (e.key === 'Enter' || e.key === ' ')) {
					e.preventDefault();
					this.switchTab('disks');
					this.openDetail('disk', g.dataset.id, g);
				}
			});
			this.elements.runwaySurface.addEventListener('pointermove', (e) => this.onRunwayHover(e));
			this.elements.runwaySurface.addEventListener('pointerleave', () => { this.tooltip.hidden = true; });
		}

		onRunwayHover(e) {
			const g = e.target.closest('.cap-runway-row');
			const row = g ? (this._runwayRows || []).find((r) => r.id === g.dataset.id) : null;
			if (!row) {
				this.tooltip.hidden = true;
				return;
			}
			const lines = [
				`<div class="cap-tt-title">${this.escapeHtml(row.host)} ${this.escapeHtml(row.fs)}</div>`,
				`<div class="cap-tt-row">Next threshold<span class="cap-tt-val">${this.escapeHtml(this.fmtDays(row.next))}</span></div>`,
				row.nextDate ? `<div class="cap-tt-row">Date<span class="cap-tt-val">${this.escapeHtml(this.fmtDate(row.nextDate))}</span></div>` : '',
				row.basis ? `<div class="cap-tt-row">Basis<span class="cap-tt-val">${this.escapeHtml(row.basis)}</span></div>` : '',
				row.crit != null ? `<div class="cap-tt-row">Critical<span class="cap-tt-val">${this.escapeHtml(this.fmtDays(row.crit))}</span></div>` : '',
				row.full != null ? `<div class="cap-tt-row">Full<span class="cap-tt-val">${this.escapeHtml(this.fmtDays(row.full))}</span></div>` : ''
			];
			this.showTooltip(e, lines.join(''));
		}

		bindDetailSurface(surface) {
			surface.addEventListener('pointerdown', (e) => this.startDetailDrag(e, surface));
			surface.addEventListener('pointermove', (e) => {
				if (this.detailDrag) { this.updateDetailDrag(e, surface); }
				else { this.onDetailHover(e, surface); }
			});
			surface.addEventListener('pointerup', (e) => this.finishDetailDrag(e, surface));
			surface.addEventListener('pointercancel', (e) => this.finishDetailDrag(e, surface, true));
			surface.addEventListener('pointerleave', () => {
				if (!this.detailDrag) { this.hideDetailHover(); }
			});
		}

		applyInitialState() {
			const url = new URL(window.location.href);
			const p = (k) => url.searchParams.get(k);
			const ds = this.root.dataset;

			const lookback = Number(p('lookback') || ds.initialLookback || 0);
			if (Number.isInteger(lookback) && lookback >= MIN_LOOKBACK_DAYS && lookback <= MAX_LOOKBACK_DAYS) {
				this.lookbackDays = lookback;
			}

			let tab = p('tab') || ds.initialTab || '';
			// Preserve existing bookmarks while writing only the new canonical tab id.
			if (tab === 'resources') { tab = 'cpu'; }
			if (['overview', 'disks', ...RESOURCE_TABS, 'settings'].includes(tab)) { this.activeTab = tab; }

			this.filters.group = p('group') || '';
			this.filters.host = p('host') || '';
			this.filters.template = p('template') || '';
			this.filters.name = p('name') || '';
			['group', 'host', 'template'].forEach((k) => {
				this.elements.filterbar.querySelector(`[data-filter="${k}"]`).value = this.filters[k];
			});
			this.elements.resultsFilter.querySelector('[data-result-filter="name"]').value = this.filters.name;
			this.resultFilters.group = p('result_group') || '';
			this.resultFilters.hostid = p('result_host') || '';
			this.resultFilters.type = ['disk-local', 'disk-remote', 'cpu', 'memory'].includes(p('type')) ? p('type') : '';
			this.resultFilters.status = ['issues', 'OK', 'Stale', 'Missing', 'Incomplete', 'Invalid current value']
				.includes(p('status')) ? p('status') : '';
			this.syncTypeForActiveTab();
			if (this.resultFilters.status === 'issues') {
				this.elements.resultsFilter.querySelector('[data-result-filter="status"]').value = 'issues';
			}
			const rows = Number(p('rows') || 0);
			if ([25, 50, 100].includes(rows)) { this.pageSize = rows; }

			const riskParam = p('risks');
			const risks = (riskParam || '').split(',').filter((r) => RISK_BY_KEY[r]);
			if (riskParam === 'none' || risks.length) {
				this.activeRisks = new Set(risks);
				this.elements.riskFilter.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
					cb.checked = this.activeRisks.has(cb.value);
				});
			}

			this.syncLookbackButtons();
			this.syncTabs();
			this.updateFilterSummary();
		}

		// ---- tabs / controls -------------------------------------------------------
		switchTab(tab) {
			if (!['overview', 'disks', ...RESOURCE_TABS, 'settings'].includes(tab)) { return; }
			const changed = tab !== this.activeTab;
			this.activeTab = tab;
			const filterChanged = this.syncTypeForActiveTab();
			if (!changed && !filterChanged) { return; }
			if (tab === 'settings') { this.hideDetail(false); }
			this.resetPages();
			this.syncTabs();
			this.updateLocationState();
			if (tab === 'settings') {
				this.renderSettings();
				this.loadCacheStatus();
			}
			else if (!this.analysisStarted) {
				this.load();
			}
			else {
				this.renderActiveTab();
			}
			this.updateExportState();
		}

		syncTypeForActiveTab() {
			let type = this.resultFilters.type;
			if (RESOURCE_TABS.includes(this.activeTab)) {
				type = this.activeTab;
			}
			else if (this.activeTab === 'disks' && RESOURCE_TABS.includes(type)) {
				type = '';
			}
			const changed = type !== this.resultFilters.type;
			this.resultFilters.type = type;
			this.elements.resultsFilter.querySelector('[data-result-filter="type"]').value = type;
			return changed;
		}

		syncTabs() {
			const settings = this.activeTab === 'settings';
			this.elements.tabs.querySelectorAll('[data-tab]').forEach((b) => {
				b.classList.toggle('is-active', b.dataset.tab === this.activeTab);
				b.setAttribute('aria-selected', b.dataset.tab === this.activeTab ? 'true' : 'false');
			});
			this.elements.analysisToolbar.hidden = settings;
			this.elements.filterbar.hidden = settings;
			this.elements.resultsFilter.hidden = settings;
			this.elements.riskFilter.hidden = settings;
			this.elements.analysisStatus.hidden = settings;
			this.elements.panelOverview.hidden = this.activeTab !== 'overview';
			this.elements.panelDisks.hidden = this.activeTab !== 'disks';
			this.elements.panelResources.hidden = !RESOURCE_TABS.includes(this.activeTab);
			this.elements.panelSettings.hidden = !settings;
			if (RESOURCE_TABS.includes(this.activeTab)) {
				const label = this.activeTab === 'memory' ? 'RAM' : 'CPU';
				this.elements.resourcesTitle.textContent = `${label} capacity evidence`;
				this.elements.resourcesSubtitle.textContent = `${label} risk combines sustained utilization with confirmed peak recurrence, duration and height against each host's Zabbix thresholds. A single isolated spike is not an upgrade decision. Open a row for the full evidence and historical chart.`;
			}
		}

		setLookback(days) {
			if (!Number.isInteger(days) || days < MIN_LOOKBACK_DAYS || days > MAX_LOOKBACK_DAYS) { return; }
			this.elements.customLookback.hidden = true;
			if (days === this.lookbackDays) { this.syncLookbackButtons(); return; }
			this.lookbackDays = days;
			this.syncLookbackButtons();
			this.updateLocationState();
			// The inventory (items, thresholds, current values) is lookback-independent;
			// only the forecasts must be recomputed.
			if (this.inv.ready) {
				this.startForecasts();
			}
			else {
				this.load();
			}
		}

		toggleCustomLookback() {
			this.elements.customLookback.hidden = !this.elements.customLookback.hidden;
			if (!this.elements.customLookback.hidden) {
				this.elements.customLookbackDays.value = String(this.lookbackDays);
				this.elements.customLookbackDays.focus();
			}
		}

		applyCustomLookback() {
			const days = Math.round(Number(this.elements.customLookbackDays.value));
			if (!Number.isFinite(days) || days < MIN_LOOKBACK_DAYS || days > MAX_LOOKBACK_DAYS) {
				this.showError(`Custom range must be between ${MIN_LOOKBACK_DAYS} and ${MAX_LOOKBACK_DAYS} days.`);
				return;
			}
			this.elements.error.hidden = true;
			this.elements.error.textContent = '';
			this.setLookback(days);
		}

		syncLookbackButtons() {
			this.elements.lookback.querySelectorAll('[data-days]').forEach((b) => {
				b.classList.toggle('is-active', Number(b.dataset.days) === this.lookbackDays);
			});
			const custom = !LOOKBACKS.some((l) => l.days === this.lookbackDays);
			this.elements.customLookbackToggle.classList.toggle('is-active', custom);
			this.elements.customLookbackDays.value = String(this.lookbackDays);
		}

		// ---- shared cache settings -------------------------------------------------
		normalizeCacheSettings(settings) {
			const source = settings && typeof settings === 'object' ? settings : {};
			const enabled = source.enabled === true || source.enabled === 1 || source.enabled === '1';
			const ttl = Number(source.ttl_seconds);
			return {
				enabled,
				ttl_seconds: CACHE_OPTIONS.some((option) => option.seconds === ttl) ? ttl : 1800
			};
		}

		cacheOptionValue() {
			if (!this.cacheSettings.enabled || this.cacheSettings.ttl_seconds === 0) { return 0; }
			return CACHE_OPTIONS.some((option) => option.seconds === this.cacheSettings.ttl_seconds)
				? this.cacheSettings.ttl_seconds
				: 1800;
		}

		cacheIntervalLabel() {
			const value = this.cacheOptionValue();
			const option = CACHE_OPTIONS.find((candidate) => candidate.seconds === value);
			return option ? option.label : '30 minutes';
		}

		renderSettings() {
			const status = this.cacheStatus && typeof this.cacheStatus === 'object' ? this.cacheStatus : {};
			const enabled = this.cacheOptionValue() !== 0;
			const backendKnown = Object.prototype.hasOwnProperty.call(status, 'backend_available');
			const backendAvailable = status.backend_available === true || status.backend_available === 1
				|| status.backend_available === '1';
			const files = Number(status.files);
			const bytes = Number(status.bytes);
			const maxBytes = Number(status.max_bytes);
			const scanComplete = status.scan_complete !== false;
			const privateVerified = status.private_permissions_verified === true
				|| status.private_permissions_verified === 1 || status.private_permissions_verified === '1';
			const boot = status.boot_invalidation && typeof status.boot_invalidation === 'object'
				? status.boot_invalidation
				: {};
			const bootAvailable = boot.available === true || boot.available === 1 || boot.available === '1';
			const stateClass = enabled && backendKnown && !backendAvailable
				? 'is-unavailable'
				: (enabled ? 'is-on' : 'is-off');
			const stateLabel = enabled
				? (backendKnown && !backendAvailable ? 'Enabled · live fallback' : `On · recent refresh ${this.cacheIntervalLabel()}`)
				: 'Off';
			const stateDetail = enabled
				? (backendKnown && !backendAvailable
					? 'Protected shared storage is unavailable, so analysis uses live Zabbix reads.'
					: (backendKnown
						? 'Cached ranges are reused; mutable recent shards load newer samples after the selected interval. Historical shards are not deleted by this timer.'
						: (this.canManageSettings
							? (this.cacheStatusLoading ? 'Inspecting protected shared storage…' : 'Detailed storage health is loaded only when Settings is opened.')
							: 'Fresh cached ranges are reused across users and analysis ranges.')))
				: 'Every analysis reads the required history directly from Zabbix.';

			this.elements.cacheSummary.innerHTML = `<span class="cap-cache-state ${stateClass}">${this.escapeHtml(stateLabel)}</span><span>${this.escapeHtml(stateDetail)}</span>`;
			const rows = [
				['Configuration', enabled ? `Enabled; mutable shards refresh after ${this.cacheIntervalLabel()}` : 'Disabled'],
				['Scope', 'Shared by this Zabbix installation; not tied to a browser session']
			];
			if (this.canManageSettings) {
				if (backendKnown) {
					rows.push(
						['Cache backend', backendAvailable ? 'Available' : 'Unavailable · live reads remain available'],
						['Stored shards', Number.isFinite(files) ? `${scanComplete ? '' : 'At least '}${Math.max(0, Math.round(files))}` : 'Status unavailable'],
						['Storage used', Number.isFinite(bytes)
							? `${scanComplete ? '' : 'At least '}${this.fmtBytes(Math.max(0, bytes))}${Number.isFinite(maxBytes) && maxBytes > 0 ? ` of ${this.fmtBytes(maxBytes)} limit` : ''}`
							: 'Status unavailable'],
						['Private storage', privateVerified ? 'Permissions verified (cached values are not encrypted)' : 'Not verified · live fallback is used'],
						['Restart invalidation', bootAvailable
							? 'Automatic for operating-system restarts; clear after a service-only restart'
							: 'Manual refresh or clear required after restart']
					);
				}
				else {
					rows.push(['Detailed cache health', this.cacheStatusLoading ? 'Loading…' : 'Unavailable; configuration remains usable']);
				}
			}
			this.elements.cacheStatus.innerHTML = rows.map(([label, value]) => `<div><dt>${this.escapeHtml(label)}</dt><dd>${this.escapeHtml(value)}</dd></div>`).join('');
			if (this.elements.cacheTtl) {
				this.elements.cacheTtl.value = String(this.cacheOptionValue());
			}
			this.setSettingsBusy(this.settingsBusy);
		}

		setSettingsBusy(busy) {
			this.settingsBusy = !!busy;
			const disabled = this.settingsBusy || this.cacheStatusLoading;
			[this.elements.cacheTtl, this.elements.saveCacheSettings, this.elements.clearSharedCache]
				.filter(Boolean).forEach((control) => { control.disabled = disabled; });
		}

		showSettingsMessage(message, error = false) {
			const element = this.elements.settingsMessage;
			if (!element) { return; }
			element.hidden = !message;
			element.className = `cap-settings-message${error ? ' is-error' : ' is-success'}`;
			element.textContent = message || '';
		}

		settingsErrorMessage(value, fallbackMessage = '') {
			const code = value && typeof value === 'object' ? String(value.code || '') : String(value || '');
			const suppliedMessage = value && typeof value === 'object' ? String(value.message || '') : fallbackMessage;
			if (code.includes('cache_busy')) {
				return 'The shared cache is busy. Wait for the active analysis to finish, then try again.';
			}
			if (code.includes('clear_io_failed')) {
				return 'The shared cache could not be cleared because a file operation failed. Check the PHP-FPM log and cache-directory ownership, mode, and SELinux context.';
			}
			return suppliedMessage || code || 'The cache operation failed.';
		}

		async loadCacheStatus() {
			if (!this.canManageSettings || this.cacheStatusRequested || !this.cacheStatusUrl) { return; }
			this.cacheStatusRequested = true;
			this.cacheStatusLoading = true;
			this.renderSettings();
			try {
				const response = await fetch(this.cacheStatusUrl, {
					method: 'GET', headers: {'X-Requested-With': 'XMLHttpRequest'},
					cache: 'no-store', credentials: 'same-origin'
				});
				const text = await response.text();
				let payload = {};
				try { payload = text.trim() ? JSON.parse(text) : {}; }
				catch (_error) { throw new Error('The server returned an invalid cache status response.'); }
				if (!response.ok || payload.ok !== true || !payload.cache_status
						|| typeof payload.cache_status !== 'object') {
					throw new Error('Detailed cache status is temporarily unavailable.');
				}
				if (payload.cache && typeof payload.cache === 'object') {
					this.cacheSettings = this.normalizeCacheSettings(payload.cache);
				}
				this.cacheStatus = payload.cache_status;
			}
			catch (_error) {
				this.showSettingsMessage('Detailed cache health could not be loaded. The configured cache setting shown above is still valid.', true);
			}
			finally {
				this.cacheStatusLoading = false;
				this.renderSettings();
			}
		}

		async postSettings(fields) {
			if (!this.canManageSettings) { throw new Error('Only a Zabbix Super Admin can change cache settings.'); }
			if (!this.csrfName || !this.csrfToken) { throw new Error('The settings security token is unavailable. Reload this page and try again.'); }
			const body = new URLSearchParams();
			Object.entries(fields).forEach(([key, value]) => body.set(key, String(value)));
			body.set(this.csrfName, this.csrfToken);
			const response = await fetch(this.settingsSaveUrl, {
				method: 'POST',
				headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
				body: body.toString(), cache: 'no-store'
			});
			const text = await response.text();
			let payload = {};
			try { payload = text.trim() ? JSON.parse(text) : {}; }
			catch (_error) { throw new Error('The server returned an invalid settings response.'); }
			const rawError = payload.error && typeof payload.error === 'object'
				? payload.error
				: String(payload.error || 'The cache operation failed.');
			if (!response.ok || payload.ok !== true) {
				throw new Error(this.settingsErrorMessage(rawError,
					payload.error && typeof payload.error === 'object' ? payload.error.message : ''));
			}
			if (payload.clear_result && payload.clear_result.ok === false) {
				throw new Error(this.settingsErrorMessage(payload.clear_result.reason));
			}
			return payload;
		}

		async applySettingsOperation(fields, fallbackMessage) {
			if (this.settingsBusy) { return; }
			this.showSettingsMessage('');
			this.setSettingsBusy(true);
			try {
				const payload = await this.postSettings(fields);
				if (payload.cache && typeof payload.cache === 'object') {
					this.cacheSettings = this.normalizeCacheSettings(payload.cache);
				}
				if (payload.cache_status && typeof payload.cache_status === 'object') {
					this.cacheStatus = payload.cache_status;
					this.cacheStatusRequested = true;
				}
				this.renderSettings();
				const removed = payload.clear_result && Number.isFinite(Number(payload.clear_result.removed_files))
					? ` ${Math.max(0, Math.round(Number(payload.clear_result.removed_files)))} cache file(s) removed.`
					: '';
				this.showSettingsMessage(`${payload.message || fallbackMessage}${removed}`);
			}
			catch (error) {
				this.showSettingsMessage(error instanceof Error ? error.message : 'The cache operation failed.', true);
			}
			finally {
				this.setSettingsBusy(false);
			}
		}

		saveCacheConfiguration() {
			if (!this.elements.cacheTtl) { return; }
			const seconds = Number(this.elements.cacheTtl.value);
			if (!CACHE_OPTIONS.some((option) => option.seconds === seconds)) {
				this.showSettingsMessage('Select a valid recent-shard refresh interval.', true);
				return;
			}
			this.applySettingsOperation({
				cache_enabled: seconds === 0 ? 0 : 1,
				cache_ttl_seconds: seconds
			}, 'Capacity Planning cache settings updated.');
		}

		async clearSharedCache() {
			if (this.settingsBusy) { return; }
			this.showSettingsMessage('');
			this.setSettingsBusy(true);
			let removedFileTotal = 0;
			let removedDirectoryTotal = 0;
			try {
				for (let attempt = 1; attempt <= MAX_CACHE_CLEAR_REQUESTS; attempt++) {
					const payload = await this.postSettings({clear_cache: 1});
					if (payload.cache && typeof payload.cache === 'object') {
						this.cacheSettings = this.normalizeCacheSettings(payload.cache);
					}
					if (payload.cache_status && typeof payload.cache_status === 'object') {
						this.cacheStatus = payload.cache_status;
						this.cacheStatusRequested = true;
					}
					const result = payload.clear_result && typeof payload.clear_result === 'object'
						? payload.clear_result : null;
					if (!result || result.ok !== true) {
						throw new Error('The server returned an incomplete cache-clear response.');
					}
					const removedFiles = Number(result.removed_files);
					const removedDirectories = result.removed_directories === undefined
						? 0 : Number(result.removed_directories);
					if (!Number.isInteger(removedFiles) || removedFiles < 0
							|| !Number.isInteger(removedDirectories) || removedDirectories < 0) {
						throw new Error('The server returned invalid cache-clear counters.');
					}
					removedFileTotal += removedFiles;
					removedDirectoryTotal += removedDirectories;
					const removedThisPass = removedFiles + removedDirectories;
					const removedSummary = `${removedFileTotal} cache file(s) removed`
						+ (removedDirectoryTotal > 0 ? `; ${removedDirectoryTotal} empty director${removedDirectoryTotal === 1 ? 'y' : 'ies'} removed` : '');
					this.renderSettings();
					if (result.complete === true && result.progress !== true) {
						this.showSettingsMessage(`${payload.message || 'The shared Capacity Planning cache was cleared.'} ${removedSummary}.`);
						return;
					}
					if (result.complete !== false || result.progress !== true) {
						throw new Error('The server returned an invalid cache-clear progress response.');
					}
					if (removedThisPass <= 0) {
						throw new Error('The shared cache clear reported no progress. Wait for active analysis to finish, then try again.');
					}
					this.showSettingsMessage(`Clearing shared cache… ${removedSummary}.`);
					await new Promise((resolve) => setTimeout(resolve, 0));
				}
				throw new Error('The shared cache is still being cleared after the safe request limit. Try again to continue.');
			}
			catch (error) {
				const message = error instanceof Error ? error.message : 'The cache operation failed.';
				// A later chunk can fail after earlier chunks already removed data.
				// Refresh the read-only status once so the Settings panel never keeps a
				// pre-clear count while still preserving the actionable clear error.
				this.cacheStatusRequested = false;
				await this.loadCacheStatus();
				this.showSettingsMessage(message, true);
			}
			finally {
				this.setSettingsBusy(false);
			}
		}

		// ---- filters ---------------------------------------------------------------
		scopeDraft() {
			const draft = {};
			['group', 'host', 'template'].forEach((key) => {
				draft[key] = this.elements.filterbar.querySelector(`[data-filter="${key}"]`).value.trim();
			});
			return draft;
		}

		scopeSignature(draft) {
			const value = draft || this.scopeDraft();
			return JSON.stringify([value.group || '', value.host || '', value.template || '']);
		}

		cancelScopePreviewRequest() {
			if (this.scopePreviewTimer !== null) {
				clearTimeout(this.scopePreviewTimer);
				this.scopePreviewTimer = null;
			}
			if (this.scopePreviewAbort) {
				this.scopePreviewAbort.abort();
				this.scopePreviewAbort = null;
			}
			this.scopePreviewSeq++;
		}

		clearScopePreviewError() {
			this.elements.scopeError.hidden = true;
			this.elements.scopeError.textContent = '';
			this.elements.filterbar.querySelectorAll('input[data-filter]').forEach((input) => {
				input.removeAttribute('aria-invalid');
			});
		}

		showScopePreviewError(message, invalidKey = null) {
			this.elements.scopeError.textContent = message;
			this.elements.scopeError.hidden = false;
			this.elements.applyFilters.disabled = true;
			if (invalidKey) {
				const input = this.elements.filterbar.querySelector(`[data-filter="${invalidKey}"]`);
				if (input) { input.setAttribute('aria-invalid', 'true'); }
			}
			this.elements.scopePreviewSummary.textContent = 'This draft scope has not been applied.';
			this.closeScopeSuggestions();
		}

		queueScopePreview(input, immediate = false) {
			this.cancelScopePreviewRequest();
			this.clearScopePreviewError();
			this.scopePreviewFocusKey = input ? input.dataset.filter : this.scopePreviewFocusKey;
			this.scopePreview = null;
			this.scopePreviewResolved = null;
			this.scopePreviewValidatedSig = null;

			const draft = this.scopeDraft();
			const fsig = this.scopeSignature(draft);
			const hasScope = !!(draft.group || draft.host || draft.template);
			if (!hasScope) {
				this.scopePreviewValidatedSig = fsig;
				this.scopePreviewResolved = {fsig, groupids: [], hostids: [], empty: false,
					truncated: false, blocked: false, summary: ''};
				this.elements.applyFilters.disabled = false;
				this.renderScopePreview();
				return;
			}

			this.elements.applyFilters.disabled = true;
			this.renderScopePreview();
			this.elements.scopePreviewSummary.textContent = 'Checking permission-visible matches… Draft scope is not yet applied.';
			const seq = this.scopePreviewSeq;
			this.scopePreviewTimer = setTimeout(() => {
				this.scopePreviewTimer = null;
				this.resolveScopePreview(draft, fsig, this.scopePreviewFocusKey, seq);
			}, immediate ? 0 : 300);
		}

		async resolveScopePreview(draft, fsig, activeKey, seq) {
			if (seq !== this.scopePreviewSeq) { return; }
			const controller = new AbortController();
			this.scopePreviewAbort = controller;
			try {
				const response = await this.fetchData({
					mode: 'resolve', group: draft.group, host: draft.host, template: draft.template
				}, controller.signal);
				if (seq !== this.scopePreviewSeq || fsig !== this.scopeSignature()) { return; }

				this.scopePreview = response.preview && typeof response.preview === 'object'
					? response.preview : null;
				this.scopePreviewResolved = {
					fsig, groupids: response.groupids || [], hostids: response.hostids || [],
					empty: !!response.empty, truncated: !!response.truncated, blocked: !!response.blocked,
					summary: response.summary || ''
				};
				if (this.scopePreviewResolved.truncated || this.scopePreviewResolved.blocked) {
					this.scopePreviewResolved = null;
					this.scopePreviewValidatedSig = null;
					this.renderScopePreview(activeKey);
					this.showScopePreviewError(response.summary
						|| 'The matching scope is too broad to resolve completely. Narrow it before applying.');
					return;
				}

				this.scopePreviewValidatedSig = fsig;
				this.elements.applyFilters.disabled = false;
				this.clearScopePreviewError();
				this.renderScopePreview(activeKey);
			}
			catch (error) {
				if ((error && error.name === 'AbortError') || seq !== this.scopePreviewSeq) { return; }
				this.scopePreview = null;
				this.scopePreviewResolved = null;
				this.scopePreviewValidatedSig = null;
				this.renderScopePreview();
				const invalidKey = error && error.field
					? (['group', 'host', 'template'].includes(error.field) ? error.field : null)
					: activeKey;
				this.showScopePreviewError(error instanceof Error ? error.message : 'The scope could not be validated.', invalidKey);
			}
			finally {
				if (seq === this.scopePreviewSeq && this.scopePreviewAbort === controller) {
					this.scopePreviewAbort = null;
				}
			}
		}

		scopePreviewCount(section, label) {
			if (!section || section.available === false || section.count == null
				|| !Number.isFinite(Number(section.count))) { return '';
			}
			const count = Math.max(0, Number(section.count));
			const prefix = section.count_is_lower_bound ? 'At least ' : '';
			return `${prefix}${count} ${label}${count === 1 ? '' : 's'} match`;
		}

		renderScopePreview(activeKey = this.scopePreviewFocusKey) {
			const sectionNames = {group: 'groups', host: 'hosts', template: 'templates'};
			const labels = {group: 'host group', host: 'host', template: 'template'};
			const draft = this.scopeDraft();
			this.closeScopeSuggestions();

			Object.keys(sectionNames).forEach((key) => {
				const section = this.scopePreview && this.scopePreview[sectionNames[key]];
				const status = this.elements.filterbar.querySelector(`[data-scope-field-status="${key}"]`);
				status.textContent = draft[key] ? this.scopePreviewCount(section, labels[key]) : '';
				this.renderScopeOptions(key, section);
			});
			if (!draft.group && !draft.host && !draft.template) {
				this.elements.scopePreviewSummary.textContent = '';
				return;
			}

			const combined = this.scopePreview && this.scopePreview.resolved_hosts;
			const countText = this.scopePreviewCount(combined, 'permission-visible host');
			const applied = this.scopePreviewValidatedSig === this.scopeSignature(this.filters)
				&& this.scopePreviewValidatedSig === this.scopeSignature(draft);
			if (countText) {
				this.elements.scopePreviewSummary.textContent = `${countText} the combined scope. `
					+ (applied ? 'This scope is applied.' : 'Apply scope to load the report.');
			}
			else if (this.scopePreviewValidatedSig) {
				const combinedCountAvailable = combined && combined.available !== false && combined.count != null;
				this.elements.scopePreviewSummary.textContent = combinedCountAvailable
					? `No permission-visible hosts match the combined scope. ${applied
						? 'This scope is applied.' : 'Apply scope to show the empty result.'}`
					: `Scope syntax is valid. ${applied ? 'This scope is applied.' : 'Apply scope to load the report.'}`;
			}
			else {
				this.elements.scopePreviewSummary.textContent = '';
			}

			if (activeKey && document.activeElement
				&& document.activeElement.matches(`input[data-filter="${activeKey}"]`)) {
				this.openScopeSuggestions(activeKey);
			}
		}

		openScopeSuggestions(key) {
			const input = this.elements.filterbar.querySelector(`[data-filter="${key}"]`);
			const list = this.elements.filterbar.querySelector(`[data-scope-options="${key}"]`);
			const sectionName = {group: 'groups', host: 'hosts', template: 'templates'}[key];
			const section = this.scopePreview && this.scopePreview[sectionName];
			if (!input || !list || input.value.trim() === '') { return; }
			this.renderScopeOptions(key, section);
			if (!list.querySelector('[data-scope-suggestion]')) { return; }
			this.closeScopeSuggestions(key);
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		renderScopeOptions(key, section) {
			const input = this.elements.filterbar.querySelector(`[data-filter="${key}"]`);
			const list = this.elements.filterbar.querySelector(`[data-scope-options="${key}"]`);
			if (!input || !list) { return; }
			const samples = input.value.trim() ? this.scopeSamplesForInput(section, input) : [];
			list.innerHTML = samples.map((sample) => {
				const label = String(sample && (sample.label ?? sample.name) || '');
				return label === '' ? '' : `<button type="button" role="option" class="cap-scope-option"
					data-scope-suggestion="${key}" data-value="${this.escapeHtml(label)}">${this.escapeHtml(label)}</button>`;
			}).join('');
		}

		scopeSamplesForInput(section, input) {
			if (!section || section.available === false) { return [];
			}
			if (Array.isArray(section.term_samples)) {
				const index = this.scopeActiveTermIndex(input);
				if (index == null) { return [];
				}
				const term = section.term_samples.find((candidate) => Number(candidate.index) === index);
				return term && Array.isArray(term.samples) ? term.samples : [];
			}
			if (Object.prototype.hasOwnProperty.call(section, 'active_samples')) {
				return Array.isArray(section.active_samples) ? section.active_samples : [];
			}
			return Array.isArray(section.samples) ? section.samples : [];
		}

		closeScopeSuggestions(exceptKey = null) {
			this.elements.filterbar.querySelectorAll('[data-scope-options]').forEach((list) => {
				if (exceptKey && list.dataset.scopeOptions === exceptKey) { return; }
				list.hidden = true;
				const input = this.elements.filterbar.querySelector(`[data-filter="${list.dataset.scopeOptions}"]`);
				if (input) { input.setAttribute('aria-expanded', 'false'); }
			});
		}

		scopeTokenRanges(value) {
			const ranges = [];
			let start = 0;
			let inRegex = false;
			let escaped = false;
			for (let index = 0; index < value.length; index++) {
				const character = value[index];
				if (escaped) { escaped = false; continue; }
				if (character === '\\') { escaped = true; continue; }
				if (inRegex) {
					if (character === '/') { inRegex = false; }
					continue;
				}
				if (character === '/' && value.slice(start, index).trim() === '') {
					inRegex = true;
					continue;
				}
				if (character === ',') {
					ranges.push({start, end: index});
					start = index + 1;
				}
			}
			ranges.push({start, end: value.length});
			return ranges;
		}

		scopeTokenBounds(value, cursor) {
			const ranges = this.scopeTokenRanges(value);
			return ranges.find((range) => cursor >= range.start && cursor <= range.end) || ranges[ranges.length - 1];
		}

		scopeActiveTermIndex(input) {
			const value = input.value;
			const cursor = input.selectionStart == null ? value.length : input.selectionStart;
			const active = this.scopeTokenBounds(value, cursor);
			let parsedIndex = -1;
			for (const range of this.scopeTokenRanges(value)) {
				if (value.slice(range.start, range.end).trim() === '') { continue; }
				parsedIndex++;
				if (range.start === active.start && range.end === active.end) { return parsedIndex; }
			}
			return null;
		}

		encodeScopeLiteral(value) {
			let encoded = String(value).replace(/\\/g, '\\\\').replace(/,/g, '\\,');
			if (encoded.startsWith('/')) { encoded = `\\${encoded}`; }
			return encoded;
		}

		chooseScopeSuggestion(suggestion) {
			const key = suggestion.dataset.scopeSuggestion;
			const input = this.elements.filterbar.querySelector(`[data-filter="${key}"]`);
			if (!input) { return; }
			const value = input.value;
			const cursor = input.selectionStart == null ? value.length : input.selectionStart;
			const bounds = this.scopeTokenBounds(value, cursor);
			const current = value.slice(bounds.start, bounds.end);
			const leading = (current.match(/^\s*/) || [''])[0];
			const trailing = (current.match(/\s*$/) || [''])[0];
			const replacement = `${leading}${this.encodeScopeLiteral(suggestion.dataset.value)}${trailing}`;
			input.value = value.slice(0, bounds.start) + replacement + value.slice(bounds.end);
			const newCursor = bounds.start + replacement.length - trailing.length;
			input.focus();
			input.setSelectionRange(newCursor, newCursor);
			this.queueScopePreview(input);
		}

		onScopeKeydown(event) {
			const input = event.target.matches('input[data-filter]') ? event.target : null;
			const suggestion = event.target.closest('[data-scope-suggestion]');
			if (input) {
				if (event.key === 'Escape') {
					event.preventDefault();
					this.closeScopeSuggestions();
				}
				else if (event.key === 'ArrowDown') {
					this.openScopeSuggestions(input.dataset.filter);
					const first = this.elements.filterbar.querySelector(`[data-scope-options="${input.dataset.filter}"] [data-scope-suggestion]`);
					if (first) { event.preventDefault(); first.focus(); }
				}
				else if (event.key === 'Enter') {
					event.preventDefault();
					if (!this.elements.applyFilters.disabled) { this.applyFilters(); }
				}
				return;
			}
			if (!suggestion) { return; }
			const list = suggestion.closest('[data-scope-options]');
			const options = [...list.querySelectorAll('[data-scope-suggestion]')];
			const index = options.indexOf(suggestion);
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault();
				const offset = event.key === 'ArrowDown' ? 1 : -1;
				options[(index + offset + options.length) % options.length].focus();
			}
			else if (event.key === 'Escape') {
				event.preventDefault();
				const owner = this.elements.filterbar.querySelector(`[data-filter="${list.dataset.scopeOptions}"]`);
				if (owner) { owner.focus(); }
				this.closeScopeSuggestions();
			}
		}

		applyFilters() {
			const draft = this.scopeDraft();
			const fsig = this.scopeSignature(draft);
			if ((draft.group || draft.host || draft.template) && this.elements.applyFilters.disabled) { return; }
			this.cancelScopePreviewRequest();
			this.filters.group = draft.group;
			this.filters.host = draft.host;
			this.filters.template = draft.template;
			this.resolved = this.scopePreviewValidatedSig === fsig && this.scopePreviewResolved
				? this.scopePreviewResolved : null;
			this.resetPages();
			this.invalidate();
			this.updateFilterSummary();
			this.closeScopeSuggestions();
			this.renderScopePreview(null);
			this.load();
		}

		clearFilters() {
			['group', 'host', 'template'].forEach((k) => {
				this.elements.filterbar.querySelector(`[data-filter="${k}"]`).value = '';
			});
			this.cancelScopePreviewRequest();
			this.clearScopePreviewError();
			this.scopePreview = null;
			this.scopePreviewResolved = null;
			this.scopePreviewValidatedSig = this.scopeSignature();
			this.elements.applyFilters.disabled = false;
			this.applyFilters();
		}

		queueResultFilterApply() {
			if (this.resultFilterTimer !== null) { clearTimeout(this.resultFilterTimer); }
			this.resultFilterTimer = setTimeout(() => {
				this.resultFilterTimer = null;
				this.applyResultFilters();
			}, 160);
		}

		applyResultFilters() {
			if (this.resultFilterTimer !== null) {
				clearTimeout(this.resultFilterTimer);
				this.resultFilterTimer = null;
			}
			const box = this.elements.resultsFilter;
			this.filters.name = box.querySelector('[data-result-filter="name"]').value.trim();
			['group', 'hostid', 'type', 'status'].forEach((key) => {
				this.resultFilters[key] = box.querySelector(`[data-result-filter="${key}"]`).value;
			});
			if (RESOURCE_TABS.includes(this.resultFilters.type) && this.activeTab !== 'overview') {
				this.activeTab = this.resultFilters.type;
			}
			else if (this.resultFilters.type.startsWith('disk-') && RESOURCE_TABS.includes(this.activeTab)) {
				this.activeTab = 'disks';
			}
			this.syncTypeForActiveTab();
			this.syncTabs();
			this.resetPages();
			if (this.selected && !this.findingVisible(this.inv.byId.get(this.selected.id))) {
				this.hideDetail(false);
			}
			this.updateLocationState();
			this.renderActiveTab();
		}

		clearResultFilters() {
			const box = this.elements.resultsFilter;
			box.querySelectorAll('[data-result-filter]').forEach((control) => { control.value = ''; });
			this.applyResultFilters();
		}

		applyRiskPreset(preset) {
			const wanted = preset === 'actionable'
				? new Set(['Critical', 'High', 'Medium'])
				: new Set(RISKS.map((risk) => risk.key));
			this.elements.riskFilter.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
				cb.checked = wanted.has(cb.value);
			});
			this.onRiskToggle();
		}

		resetPages() {
			this.pages.disks = 1;
			this.pages.resources = 1;
		}

		updateFilterSummary() {
			const parts = [];
			if (this.filters.group) { parts.push(`group~"${this.filters.group}"`); }
			if (this.filters.host) { parts.push(`host~"${this.filters.host}"`); }
			if (this.filters.template) { parts.push(`template~"${this.filters.template}"`); }
			this.elements.filterSummary.textContent = parts.length ? `Server scope: ${parts.join('  ·  ')}` : '';
		}

		syncResultFilterOptions() {
			const findingHostIds = new Set([...this.inv.disks, ...this.inv.resources].map((finding) => String(finding.hostid)));
			const hosts = this.inv.hosts.filter((host) => findingHostIds.has(String(host.hostid)));
			const groupNames = new Set();
			hosts.forEach((host) => (host.groups || []).forEach((group) => groupNames.add(group)));
			const statuses = [...new Set([...this.inv.disks, ...this.inv.resources]
				.map((finding) => String(finding.status || '')).filter(Boolean))]
				.sort((a, b) => a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'}));

			const setOptions = (select, firstLabel, options, wanted, fixed = []) => {
				select.innerHTML = `<option value="">${this.escapeHtml(firstLabel)}</option>`
					+ fixed.map((option) => `<option value="${this.escapeHtml(option.value)}">${this.escapeHtml(option.label)}</option>`).join('')
					+ options.map((option) => `<option value="${this.escapeHtml(option.value)}">${this.escapeHtml(option.label)}</option>`).join('');
				const available = [...select.options].some((option) => option.value === wanted);
				select.value = available ? wanted : '';
				return select.value;
			};

			this.resultFilters.group = setOptions(
				this.elements.resultsFilter.querySelector('[data-result-filter="group"]'),
				'All host groups', [...groupNames].sort((a, b) => a.localeCompare(b, undefined,
					{numeric: true, sensitivity: 'base'})).map((name) => ({value: name, label: name})),
				this.resultFilters.group
			);
			this.resultFilters.hostid = setOptions(
				this.elements.resultsFilter.querySelector('[data-result-filter="hostid"]'),
				'All hosts', hosts.map((host) => ({value: String(host.hostid), label: host.name})),
				this.resultFilters.hostid
			);
			this.resultFilters.status = setOptions(
				this.elements.resultsFilter.querySelector('[data-result-filter="status"]'),
				'All data states', statuses.map((status) => ({value: status, label: status})),
				this.resultFilters.status, [{value: 'issues', label: 'Data issues only'}]
			);
			this.elements.resultsFilter.querySelector('[data-result-filter="type"]').value = this.resultFilters.type;
			this.updateLocationState();
		}

		async ensureResolved() {
			const fsig = JSON.stringify([this.filters.group, this.filters.host, this.filters.template]);
			if (this.resolved && this.resolved.fsig === fsig) { return this.resolved; }

			if (!this.filters.group && !this.filters.host && !this.filters.template) {
				this.resolved = {fsig, groupids: [], hostids: [], empty: false, summary: ''};
				return this.resolved;
			}

			try {
				const r = await this.fetchData({
					mode: 'resolve',
					group: this.filters.group, host: this.filters.host, template: this.filters.template
				}, null);
				if (r.truncated || r.blocked) {
					const message = r.summary
						|| 'The matching scope is too broad to resolve completely. Narrow it before applying.';
					if (fsig === this.scopeSignature()) { this.showScopePreviewError(message); }
					throw new Error(message);
				}
				this.resolved = {fsig, groupids: r.groupids || [], hostids: r.hostids || [], empty: !!r.empty,
					truncated: false, blocked: false, summary: r.summary || ''};
				if (fsig === this.scopeSignature()) {
					this.scopePreview = r.preview && typeof r.preview === 'object' ? r.preview : null;
					this.scopePreviewResolved = this.resolved;
					this.scopePreviewValidatedSig = fsig;
					this.elements.applyFilters.disabled = false;
					this.clearScopePreviewError();
					this.renderScopePreview();
				}
				return this.resolved;
			}
			catch (error) {
				if (!(error && error.name === 'AbortError') && fsig === this.scopeSignature()) {
					this.showScopePreviewError(error instanceof Error ? error.message : 'The scope could not be validated.');
				}
				throw error;
			}
		}

		onRiskToggle() {
			this.activeRisks.clear();
			this.elements.riskFilter.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
				if (cb.checked) { this.activeRisks.add(cb.value); }
			});
			this.resetPages();
			if (this.selected && !this.findingVisible(this.inv.byId.get(this.selected.id))) {
				this.hideDetail(false);
			}
			this.updateLocationState();
			this.renderActiveTab();
		}

		invalidate(clearForecastRuns = true) {
			this.inv = this.emptyInventory();
			this.fc = new Map();
			this.fcTotal = 0;
			this.fcDone = 0;
			this.fcReady = false;
			this.fcGeneratedAt = null;
			this.cacheRunMeta = null;
			if (clearForecastRuns) { this.forecastRuns.clear(); }
			this.hideDetail();
			this.updateResultsSummary();
			this.updateExportState();
		}

		// ---- data fetch ------------------------------------------------------------
		async fetchData(params, signal) {
			const body = new URLSearchParams(Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])));
			const response = await fetch(this.dataUrl, {
				method: 'POST',
				headers: {'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'},
				body: body.toString(), cache: 'no-store', signal
			});
			const text = await response.text();
			let payload = {};
			if (text.trim() !== '') {
				try { payload = JSON.parse(text); }
				catch (_e) { throw new Error('The server returned an invalid JSON response.'); }
			}
			if (payload && payload.error) {
				const error = new Error(payload.error.message || 'Failed to load capacity data.');
				if (payload.error.field) { error.field = String(payload.error.field); }
				throw error;
			}
			if (!response.ok) { throw new Error(`Request failed with HTTP ${response.status}.`); }
			return payload;
		}

		// ---- main load (inventory, then progressive forecasts) ---------------------
		async load(forceRefresh = false) {
			this.analysisStarted = true;
			if (forceRefresh && (this.filters.group || this.filters.host || this.filters.template)) {
				// Refresh must re-evaluate names and current permissions before reusing
				// an applied allow-list; hosts may have been added, renamed or revoked.
				this.resolved = null;
			}
			if (this.loadAbort) { this.loadAbort.abort(); }
			this.loadAbort = new AbortController();
			const seq = ++this.loadSeq;
			const signal = this.loadAbort.signal;

			this.clearMessages();
			this.invalidate();
			this.setLoading(true, 'Resolving filter…');
			this.updateLocationState();

			try {
				const resolved = await this.ensureResolved();
				if (seq !== this.loadSeq) { return; }
				if (resolved.empty) {
					this.setLoading(false);
					this.renderEmpty('No hosts or groups matched the filter.');
					return;
				}

				this.setLoading(true, 'Discovering capacity items and thresholds…');
				const params = {mode: 'inventory'};
				if (resolved.groupids.length) { params.groupids = resolved.groupids.join(','); }
				if (resolved.hostids.length) { params.hostids = resolved.hostids.join(','); }
				const payload = await this.fetchData(params, signal);
				if (seq !== this.loadSeq) { return; }

				this.inv.sig = resolved.fsig;
				this.inv.disks = Array.isArray(payload.disks) ? payload.disks : [];
				this.inv.resources = Array.isArray(payload.resources) ? payload.resources : [];
				this.inv.quality = Array.isArray(payload.quality) ? payload.quality : [];
				this.inv.meta = payload.meta || {};
				const facets = payload.facets && typeof payload.facets === 'object' ? payload.facets : {};
				this.inv.hosts = Array.isArray(facets.hosts) ? facets.hosts : [];
				this.inv.hostgroups = Array.isArray(facets.hostgroups) ? facets.hostgroups : [];
				this.inv.byHostId = new Map(this.inv.hosts.map((host) => [String(host.hostid), host]));
				this.inv.ready = true;
				this.inv.byId = new Map();
				this.inv.disks.forEach((d) => this.inv.byId.set(d.id, d));
				this.inv.resources.forEach((r) => this.inv.byId.set(r.id, r));

				const warnings = [];
				if (this.inv.meta.hosts_truncated) { warnings.push('The host scope was truncated — narrow the filter.'); }
				if (this.inv.meta.items_truncated) { warnings.push('The item scan hit its limit; some findings may be missing.'); }
				if (this.inv.meta.findings_truncated) { warnings.push('The findings list was truncated.'); }
				this.renderWarning(warnings.join(' '));

				this.syncResultFilterOptions();
				this.renderActiveTab();
				await this.startForecasts(seq, forceRefresh);
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				if (seq === this.loadSeq) {
					this.setLoading(false);
					this.renderEmpty(err instanceof Error ? err.message : 'Failed to load capacity data.');
					this.showError(err instanceof Error ? err.message : 'Failed to load capacity data.');
				}
			}
		}

		buildSpec(finding, kind) {
			if (kind === 'disk') {
				return {
					id: finding.id, itemid: finding.itemid, pct_itemid: finding.pct_itemid,
					kind: 'disk', tr: finding.tr, pr: finding.pr,
					pct_tr: finding.pct_tr || 'identity', pct_pr: finding.pct_pr,
					os: finding.os, fs_kind: finding.kind,
					ok: finding.status === 'OK',
					current_observation_usable: finding.current_observation_usable !== false,
					total: finding.total, used: finding.used, free: finding.free, pused: finding.pused,
					warn_pct: finding.warn_pct ? finding.warn_pct.v : null,
					crit_pct: finding.crit_pct ? finding.crit_pct.v : null,
					warn_free: finding.warn_free ? finding.warn_free.v : null,
					crit_free: finding.crit_free ? finding.crit_free.v : null
				};
			}
			return {
				id: finding.id, itemid: finding.itemid, kind: 'res', rtype: finding.rtype,
				tr: finding.tr, pr: finding.pr, current: finding.current,
				lastclock: finding.lastclock, status: finding.status,
				current_observation_usable: finding.current_observation_usable !== false,
				warn: finding.warn ? finding.warn.v : null,
				crit: finding.crit ? finding.crit.v : null
			};
		}

		async startForecasts(seq, forceRefresh = false) {
			if (seq === undefined) {
				if (this.loadAbort) { this.loadAbort.abort(); }
				this.loadAbort = new AbortController();
				seq = ++this.loadSeq;
			}
			const signal = this.loadAbort ? this.loadAbort.signal : null;
			const runKey = String(this.lookbackDays);
			this.elements.error.hidden = true;
			this.elements.error.className = '';
			this.elements.error.textContent = '';
			const savedRun = !forceRefresh ? this.forecastRuns.get(runKey) : null;
			if (savedRun) {
				this.fc = new Map(savedRun.forecasts);
				this.fcTotal = savedRun.total;
				this.fcDone = savedRun.total;
				this.fcReady = true;
				this.fcGeneratedAt = savedRun.generatedAt || null;
				this.cacheRunMeta = Object.assign({}, savedRun.cacheRunMeta || {}, {page_hit: true});
				this.setLoading(false);
				this.renderActiveTab();
				this.updateExportState();
				return;
			}

			this.fc = new Map();
			this.fcReady = false;
			this.cacheRunMeta = {page_hit: false, requests: 0, shard_hits: 0, shard_misses: 0,
				shards_written: 0, live_fallbacks: 0, reasons: []};
			this.updateExportState();
			// An open detail card belongs to the previous forecast run; show its
			// loading state until the new forecast for it arrives.
			if (this.selected) {
				this.detailChartSpec = null;
				this.detailChartId = null;
				this.detailZoom = null;
				this.updateDetailZoomUi();
				this.openDetail(this.selected.kind, this.selected.id);
			}

			// Highest-utilization findings first, so the riskiest rows resolve early.
			const disks = this.inv.disks.filter((d) => d.itemid)
				.slice().sort((a, b) => (b.pused ?? -1) - (a.pused ?? -1));
			const resources = this.inv.resources.filter((r) => r.itemid)
				.slice().sort((a, b) => (b.current ?? -1) - (a.current ?? -1));
			const diskSpecs = disks.map((d) => this.buildSpec(d, 'disk'));
			const resourceSpecs = resources.map((r) => this.buildSpec(r, 'res'));
			this.fcTotal = diskSpecs.length + resourceSpecs.length;
			this.fcDone = 0;

			if (!this.fcTotal) {
				this.fcReady = true;
				this.forecastRuns.set(runKey, {
					forecasts: new Map(), total: 0, generatedAt: null,
					cacheRunMeta: Object.assign({}, this.cacheRunMeta)
				});
				this.setLoading(false);
				this.renderActiveTab();
				this.updateExportState();
				return;
			}

			const generatedAt = Number(this.inv.meta && this.inv.meta.generated_at);
			const nowSec = Number.isFinite(generatedAt) && generatedAt > 0
				? Math.floor(generatedAt)
				: Math.floor(Date.now() / 1000);
			this.fcGeneratedAt = nowSec;
			const timeFrom = nowSec - this.lookbackDays * 86400;
			const meta = this.inv.meta || {};
			const diskBatchMax = Math.min(10, Math.max(1,
				Math.floor(Number(meta.forecast_batch_max) || 10)));
			// Resource requests include up to 31 days of high-resolution samples per
			// item. Keep them separate and deliberately smaller than disk batches.
			const resourceBatchMax = Math.min(10, Math.max(1,
				Math.floor(Number(meta.resource_forecast_batch_max) || 2)));
			const batches = [];
			for (let i = 0; i < diskSpecs.length; i += diskBatchMax) {
				batches.push(diskSpecs.slice(i, i + diskBatchMax));
			}
			for (let i = 0; i < resourceSpecs.length; i += resourceBatchMax) {
				batches.push(resourceSpecs.slice(i, i + resourceBatchMax));
			}

			try {
				let completed = 0;
				let nextBatch = 0;
				let failedFindings = 0;
				const failures = [];
				const isCurrent = () => seq === this.loadSeq && !(signal && signal.aborted);
				const runWorker = async () => {
					while (isCurrent()) {
						// JavaScript runs this claim synchronously, so workers always take the
						// already risk-sorted batches in order even though responses may finish
						// out of order.
						const batchIndex = nextBatch++;
						if (batchIndex >= batches.length) { return; }
						const batch = batches[batchIndex];
						let payload = null;
						try {
							const params = {
								mode: 'forecast', time_from: timeFrom, time_to: nowSec,
								specs: JSON.stringify(batch), refresh: forceRefresh ? 1 : 0
							};
							if (forceRefresh && this.csrfName && this.dataCsrfToken) {
								params[this.csrfName] = this.dataCsrfToken;
							}
							payload = await this.fetchData(params, signal);
						}
						catch (err) {
							if ((err && err.name === 'AbortError') || !isCurrent()) { return; }
							failedFindings += batch.length;
							failures.push(err instanceof Error ? err.message : 'Failed to compute a forecast batch.');
						}

						if (!isCurrent()) { return; }
						if (payload) {
							this.absorbForecastCacheMeta(payload.meta ? payload.meta.cache : null);
							// ETA dates are computed against the forecast run's server clock;
							// anchor the charts to the same instant.
							const g = payload.meta ? Number(payload.meta.generated_at) : NaN;
							if (Number.isFinite(g) && g > 0) {
								this.fcGeneratedAt = Math.max(this.fcGeneratedAt || 0, Math.floor(g));
							}
							(Array.isArray(payload.forecasts) ? payload.forecasts : []).forEach((f) => {
								if (f && f.id) { this.fc.set(f.id, f); }
							});
						}
						completed += batch.length;
						this.fcDone = Math.min(completed, this.fcTotal);
						this.setLoading(true, `Forecasting ${this.fcDone}/${this.fcTotal}…`);
						this.renderActiveTab();
					}
				};

				this.setLoading(true, `Forecasting 0/${this.fcTotal}…`);
				const workerCount = Math.min(FORECAST_WORKER_LIMIT, batches.length);
				await Promise.all(Array.from({length: workerCount}, () => runWorker()));
				if (!isCurrent()) { return; }

				this.fcDone = this.fcTotal;
				this.fcReady = true;
				if (!failures.length) {
					this.forecastRuns.set(runKey, {
						forecasts: new Map(this.fc), total: this.fcTotal,
						generatedAt: this.fcGeneratedAt,
						cacheRunMeta: Object.assign({}, this.cacheRunMeta, {
							reasons: Array.isArray(this.cacheRunMeta.reasons)
								? this.cacheRunMeta.reasons.slice() : []
						})
					});
				}
				else {
					const noun = failures.length === 1 ? 'batch' : 'batches';
					const findingNoun = failedFindings === 1 ? 'finding is' : 'findings are';
					this.showError(`${failures.length} of ${batches.length} forecast ${noun} failed. `
						+ `Remaining batches completed; ${failedFindings} affected ${findingNoun} shown as Unknown. `
						+ failures[0]);
				}
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				if (seq === this.loadSeq) {
					this.fcDone = this.fcTotal;
					this.fcReady = true;
					this.showError(err instanceof Error ? err.message : 'Failed to compute forecasts.');
				}
			}
			finally {
				if (seq === this.loadSeq) {
					this.setLoading(false);
					this.renderActiveTab();
					this.updateExportState();
				}
			}
		}

		absorbForecastCacheMeta(meta) {
			if (!meta || typeof meta !== 'object' || !this.cacheRunMeta) { return; }
			['enabled', 'ttl_seconds', 'protection', 'forced_refresh'].forEach((key) => {
				if (Object.prototype.hasOwnProperty.call(meta, key)) { this.cacheRunMeta[key] = meta[key]; }
			});
			if (Object.prototype.hasOwnProperty.call(meta, 'backend_available')) {
				const available = meta.backend_available === true || meta.backend_available === 1
					|| meta.backend_available === '1';
				this.cacheRunMeta.backend_available = Object.prototype.hasOwnProperty.call(
					this.cacheRunMeta, 'backend_available')
					? this.cacheRunMeta.backend_available === true && available
					: available;
			}
			const request = meta.request && typeof meta.request === 'object' ? meta.request : {};
			['requests', 'shard_hits', 'shard_misses', 'shards_written', 'live_fallbacks'].forEach((key) => {
				this.cacheRunMeta[key] = Number(this.cacheRunMeta[key] || 0) + Number(request[key] || 0);
			});
			const reasons = new Set(Array.isArray(this.cacheRunMeta.reasons) ? this.cacheRunMeta.reasons : []);
			(Array.isArray(request.reasons) ? request.reasons : []).forEach((reason) => {
				if (typeof reason === 'string' && reason) { reasons.add(reason); }
			});
			this.cacheRunMeta.reasons = [...reasons];
		}

		// ---- severity helpers ------------------------------------------------------
		severityOf(finding) {
			const fc = this.fc.get(finding.id);
			if (fc && fc.severity) { return fc.severity; }
			if (fc) {
				// Forecast finished without a usable series: fall back to the current
				// threshold state; without breach the risk is genuinely unknown.
				if (finding.warn_pct !== undefined) {
					if (this.diskBreach(finding, 'crit')) { return 'Critical'; }
					if (this.diskBreach(finding, 'warn')) { return 'High'; }
				}
				if (finding.current_severity && RISK_BY_KEY[finding.current_severity]) {
					return finding.current_severity;
				}
				return 'Unknown';
			}
			if (finding.current_severity && finding.current_severity !== 'Unknown'
					&& RISK_BY_KEY[finding.current_severity]) {
				return finding.current_severity;
			}
			if (!finding.itemid) { return finding.current_severity || 'Unknown'; }
			return this.fcReady ? (finding.current_severity || 'Unknown') : 'Pending';
		}

		selectedStats(fc) {
			if (!fc) { return null; }
			if (fc.selected && typeof fc.selected === 'object' && !Array.isArray(fc.selected)) {
				return fc.selected;
			}
			return fc.sel && fc.windows && fc.windows[fc.sel] ? fc.windows[fc.sel] : null;
		}

		selectedLabel(fc) {
			if (!fc) { return 'n/a'; }
			if (typeof fc.sel_label === 'string' && fc.sel_label.trim() !== '') {
				return fc.sel_label.trim();
			}
			return fc.sel ? (WINDOW_LABELS[fc.sel] || fc.sel) : 'n/a';
		}

		percentageSelectedLabel(fc) {
			if (!fc) { return 'n/a'; }
			if (typeof fc.pct_sel_label === 'string' && fc.pct_sel_label.trim() !== '') {
				return fc.pct_sel_label.trim();
			}
			return fc.pct_sel ? (WINDOW_LABELS[fc.pct_sel] || fc.pct_sel) : 'n/a';
		}

		regimeSummary(regime) {
			if (!regime || typeof regime !== 'object') { return ''; }
			if (!regime.detected) {
				return regime.reason ? `No regime change: ${regime.reason}` : 'No regime change detected';
			}
			const parts = [`${regime.direction || 'changed'} regime`];
			if (regime.change_clock) { parts.push(`since ${this.fmtIsoDate(regime.change_clock)}`); }
			if (regime.prior_average != null || regime.recent_average != null) {
				parts.push(`${this.fmtPct(regime.prior_average)} prior → ${this.fmtPct(regime.recent_average)} recent`);
			}
			if (regime.prior_days != null || regime.recent_days != null) {
				const priorDays = regime.prior_days == null ? 'N/A' : `${this.fmtCount(regime.prior_days)}d`;
				const recentDays = regime.recent_days == null ? 'N/A' : `${this.fmtCount(regime.recent_days)}d`;
				parts.push(`${priorDays} prior / ${recentDays} recent`);
			}
			if (regime.prior_coverage_pct != null || regime.recent_coverage_pct != null) {
				parts.push(`${this.fmtPct(regime.prior_coverage_pct)} prior / ${this.fmtPct(regime.recent_coverage_pct)} recent coverage`);
			}
			if (regime.delta_pct_points != null) {
				parts.push(`${regime.delta_pct_points >= 0 ? '+' : ''}${this.trimNum(regime.delta_pct_points)} pp`);
			}
			if (regime.relative_change_pct != null) {
				parts.push(`${regime.relative_change_pct >= 0 ? '+' : ''}${this.trimNum(regime.relative_change_pct)}% relative`);
			}
			if (regime.confidence) { parts.push(`${regime.confidence} confidence`); }
			if (regime.reason) { parts.push(regime.reason); }
			return parts.join(' · ');
		}

		countDaysLabel(count, days) {
			if (count == null && days == null) { return 'N/A'; }
			if (days == null) { return this.fmtCount(count); }
			return `${this.fmtCount(count)} / ${this.fmtCount(days)}d`;
		}

		historicalSaturationSummary(saturation) {
			if (!saturation || typeof saturation !== 'object') { return ''; }
			const parts = [
				`${this.fmtCount(saturation.confirmed_episode_count)} confirmed episode(s) across ${this.fmtCount(saturation.confirmed_episode_days)} day(s)`,
				`longest ${this.fmtDurationMinutes(saturation.confirmed_longest_minutes)}`,
				`total ${this.fmtDurationMinutes(saturation.confirmed_total_minutes)}`
			];
			if (saturation.source && saturation.source !== 'none') { parts.push(`source ${saturation.source}`); }
			if (saturation.confidence && saturation.confidence !== 'None') {
				parts.push(`${saturation.confidence} confidence`);
			}
			if (saturation.reason) { parts.push(saturation.reason); }
			return parts.join(' · ');
		}

		diskBreach(d, level) {
			const pct = level === 'crit' ? (d.crit_pct && d.crit_pct.v) : (d.warn_pct && d.warn_pct.v);
			const freeThr = level === 'crit' ? (d.crit_free && d.crit_free.v) : (d.warn_free && d.warn_free.v);
			return (d.pused != null && pct != null && d.pused > pct)
				|| (d.free != null && freeThr != null && freeThr > 0 && d.free < freeThr);
		}

		riskVisible(sev) {
			return sev === 'Pending' ? this.activeRisks.size === RISKS.length : this.activeRisks.has(sev);
		}

		hostFacet(finding) {
			return finding ? this.inv.byHostId.get(String(finding.hostid)) || null : null;
		}

		maintenanceFor(finding) {
			const facet = this.hostFacet(finding);
			const raw = finding && finding.maintenance && typeof finding.maintenance === 'object'
				? finding.maintenance
				: (facet && facet.maintenance && typeof facet.maintenance === 'object' ? facet.maintenance : {});
			const active = raw.active === true || raw.active === 1 || raw.active === '1';
			const type = active && ['no_data_collection', 'with_data_collection'].includes(raw.type)
				? raw.type
				: 'none';
			const since = Number(raw.since);
			return {active, type, since: Number.isFinite(since) && since > 0 ? since : null};
		}

		isNoDataMaintenance(finding) {
			const maintenance = this.maintenanceFor(finding);
			return maintenance.active && maintenance.type === 'no_data_collection';
		}

		currentObservationUsable(finding) {
			return !!finding && finding.current_observation_usable !== false
				&& !this.isNoDataMaintenance(finding);
		}

		observationSemantics(finding) {
			return this.currentObservationUsable(finding) ? 'Current observation' : 'Last accepted';
		}

		currentObservationText(finding, formatted) {
			if (this.currentObservationUsable(finding)) { return formatted; }
			return formatted === 'N/A' ? 'No accepted value' : `Last accepted: ${formatted}`;
		}

		maintenanceSummary(finding) {
			const maintenance = this.maintenanceFor(finding);
			if (!maintenance.active) { return ''; }
			const since = maintenance.since ? ` since ${this.fmtDateTime(maintenance.since)}` : '';
			if (maintenance.type === 'no_data_collection') {
				return `Maintenance without data collection${since}; collection is paused. Values shown are the last accepted values and are not live current observations.`
					+ (finding && finding.expected_gap ? ' The resulting collection gap is expected.' : '');
			}
			return `Maintenance with data collection${since}; current observations continue normally.`;
		}

		maintenanceBadgeHtml(finding) {
			const maintenance = this.maintenanceFor(finding);
			if (!maintenance.active) { return ''; }
			const label = maintenance.type === 'no_data_collection' ? 'Maintenance · no data' : 'Maintenance';
			return `<span class="cap-maintenance-badge" title="${this.escapeHtml(this.maintenanceSummary(finding))}">${this.escapeHtml(label)}</span>`;
		}

		maintenanceDetailHtml(finding) {
			const summary = this.maintenanceSummary(finding);
			return summary
				? `<span class="cap-detail-maintenance">${this.maintenanceBadgeHtml(finding)}<span>${this.escapeHtml(summary)}</span></span>`
				: '';
		}

		currentStateExplanation(finding, reasons = []) {
			return [this.maintenanceSummary(finding), ...(Array.isArray(reasons) ? reasons : [])]
				.filter((value, index, all) => value && all.indexOf(value) === index).join(' ');
		}

		findingReasons(finding, forecast = null) {
			return [
				...(Array.isArray(finding && finding.current_reasons) ? finding.current_reasons : []),
				...(Array.isArray(forecast && forecast.reasons) ? forecast.reasons : [])
			].filter((value, index, all) => value && all.indexOf(value) === index);
		}

		nameMatch(finding) {
			const needle = this.filters.name.toLowerCase();
			if (!needle) { return true; }
			// Findings are replaced wholesale on every inventory load, so the lowered
			// haystack can be cached on the object for the many visibility passes.
			if (finding._hay === undefined) {
				const facet = this.hostFacet(finding);
				finding._hay = (`${finding.host} ${finding.fs || ''} ${finding.metric || ''} ${finding.rtype || ''} `
					+ `${finding.item_key || ''} ${facet ? (facet.groups || []).join(' ') : ''}`).toLowerCase();
			}
			return finding._hay.includes(needle);
		}

		findingType(finding) {
			if (!finding) { return ''; }
			if (finding.fs !== undefined) { return finding.kind === 'Remote' ? 'disk-remote' : 'disk-local'; }
			if (String(finding.rtype).toLowerCase() === 'cpu') { return 'cpu'; }
			if (String(finding.rtype).toLowerCase() === 'memory') { return 'memory'; }
			return '';
		}

		resourceTabForFinding(finding) {
			return this.findingType(finding) === 'memory' ? 'memory' : 'cpu';
		}

		matchesResultFilters(finding) {
			if (!finding || !this.nameMatch(finding)) { return false; }
			if (this.resultFilters.hostid && String(finding.hostid) !== this.resultFilters.hostid) { return false; }
			if (this.resultFilters.type && this.findingType(finding) !== this.resultFilters.type) { return false; }
			if (this.resultFilters.status) {
				const status = String(finding.status || '');
				if (this.resultFilters.status === 'issues') {
					if (status.toUpperCase() === 'OK' || finding.expected_gap) { return false; }
				}
				else if (status !== this.resultFilters.status) { return false; }
			}
			if (this.resultFilters.group) {
				const facet = this.hostFacet(finding);
				if (!facet || !(facet.groups || []).includes(this.resultFilters.group)) { return false; }
			}
			return true;
		}

		findingVisible(finding) {
			return !!finding && this.matchesResultFilters(finding) && this.riskVisible(this.severityOf(finding));
		}

		updateResultsSummary() {
			if (!this.inv.ready) {
				this.elements.resultsSummary.textContent = '';
				return;
			}
			const all = [...this.inv.disks, ...this.inv.resources];
			const fieldMatched = all.filter((finding) => this.matchesResultFilters(finding));
			const severities = fieldMatched.map((finding) => this.severityOf(finding));
			const shown = fieldMatched.filter((_finding, index) => this.riskVisible(severities[index]));
			const hosts = new Set(shown.map((finding) => String(finding.hostid)));
			const fieldHidden = all.length - fieldMatched.length;
			const riskHidden = fieldMatched.length - shown.length;
			const hidden = [fieldHidden ? `${fieldHidden} by result fields` : '', riskHidden ? `${riskHidden} by risk` : '']
				.filter(Boolean).join(', ');
			this.elements.resultsSummary.textContent = `Showing ${shown.length} of ${all.length} findings across ${hosts.size} host${hosts.size === 1 ? '' : 's'}`
				+ (hidden ? ` · hidden: ${hidden}` : '');

			const counts = Object.fromEntries(RISKS.map((risk) => [risk.key, 0]));
			severities.forEach((severity) => {
				if (counts[severity] !== undefined) { counts[severity]++; }
			});
			this.elements.riskFilter.querySelectorAll('[data-risk-count]').forEach((count) => {
				count.textContent = String(counts[count.dataset.riskCount] || 0);
			});
		}

		visibleDisks() {
			return this.inv.disks.filter((finding) => this.findingVisible(finding));
		}

		visibleResources() {
			return this.inv.resources.filter((finding) => this.findingVisible(finding));
		}

		// ---- rendering -------------------------------------------------------------
		renderActiveTab() {
			this.tooltip.hidden = true; // re-render invalidates whatever was hovered
			if (this.activeTab === 'settings') {
				this.renderSettings();
				return;
			}
			this.renderMeta();
			this.updateResultsSummary();
			if (!this.inv.ready) { return; }
			if (this.activeTab === 'overview') { this.renderOverview(); }
			else if (this.activeTab === 'disks') { this.renderDisks(); }
			else { this.renderResources(); }
			// A detail card opened before its forecast arrived refreshes once the
			// forecast lands in a later batch.
			if (this.selected && this.detailPending && this.fc.get(this.selected.id)) {
				this.openDetail(this.selected.kind, this.selected.id);
			}
		}

		renderEmpty(message) {
			this._qualityInv = null; // qualityBody is overwritten below
			const empty = `<div class="cap-empty">${this.escapeHtml(message || 'No data is available.')}</div>`;
			this.elements.cards.innerHTML = '';
			this.elements.runwaySurface.innerHTML = empty;
			this.elements.distSurface.innerHTML = empty;
			this.elements.topRisks.innerHTML = empty;
			this.elements.qualityBody.innerHTML = empty;
			this.elements.disksBody.innerHTML = empty;
			this.elements.resourcesBody.innerHTML = empty;
			this.elements.meta.textContent = '';
		}

		renderMeta() {
			if (!this.inv.ready) { this.elements.meta.textContent = ''; return; }
			const m = this.inv.meta || {};
			const lb = LOOKBACKS.find((l) => l.days === this.lookbackDays);
			const progress = (!this.fcReady && this.fcTotal > 0)
				? ` • forecasting ${this.fcDone}/${this.fcTotal}…`
				: '';
			let cache = '';
			const cm = this.cacheRunMeta;
			if (cm && cm.page_hit) {
				cache = ' • cache: page result';
			}
			else if (cm && cm.enabled === false) {
				cache = ' • cache: off';
			}
			else if (cm && (cm.backend_available === false || Number(cm.live_fallbacks || 0) > 0)) {
				const fallbackCount = Math.max(0, Number(cm.live_fallbacks || 0));
				const activity = Number(cm.shard_hits || 0) + Number(cm.shard_misses || 0);
				const fallbackLabel = fallbackCount > 0
					? `partial live fallback (${fallbackCount} range${fallbackCount === 1 ? '' : 's'})`
					: 'live fallback';
				cache = ` • cache: ${fallbackLabel}`;
				if (activity > 0) {
					cache += ` • ${Number(cm.shard_hits || 0)} reused, ${Number(cm.shard_misses || 0)} loaded, `
						+ `${Number(cm.shards_written || 0)} stored`;
				}
			}
			else if (cm && (Number(cm.shard_hits || 0) + Number(cm.shard_misses || 0)) > 0) {
				cache = ` • cache: ${Number(cm.shard_hits || 0)} reused, ${Number(cm.shard_misses || 0)} loaded, `
					+ `${Number(cm.shards_written || 0)} stored`;
			}
			this.elements.meta.textContent =
				`Scope: ${m.hosts_analyzed || 0} hosts • ${this.inv.disks.length} filesystems • `
				+ `${this.inv.resources.length} CPU/memory metrics • Lookback: ${lb ? lb.label : this.lookbackDays + 'd'}`
				+ progress + cache;
		}

		riskCounts(disks = this.visibleDisks(), resources = this.visibleResources()) {
			const counts = Object.fromEntries(RISKS.map((r) => [r.key, 0]));
			[...disks, ...resources].forEach((f) => {
				const sev = this.severityOf(f);
				if (counts[sev] !== undefined) { counts[sev]++; }
			});
			return counts;
		}

		renderOverview() {
			const disks = this.visibleDisks();
			const resources = this.visibleResources();
			const counts = this.riskCounts(disks, resources);
			const hosts = new Set([...disks, ...resources].map((finding) => String(finding.hostid)));
			const actions = counts.Critical + counts.High + counts.Medium;
			const cards = [
				{value: hosts.size, label: 'Servers displayed'},
				{value: disks.length, label: 'Filesystems displayed'},
				{value: resources.length, label: 'CPU / memory displayed'},
				{value: actions, label: 'Capacity actions'},
				{value: counts.Critical, label: 'Critical risks', critical: counts.Critical > 0}
			];
			this.elements.cards.innerHTML = cards.map((c) =>
				`<div class="cap-stat"><div class="cap-stat-value${c.critical ? ' is-critical' : ''}">${c.value}</div><div class="cap-stat-label">${this.escapeHtml(c.label)}</div></div>`
			).join('');

			this._runwayRows = this.runwayRows(disks);
			this.elements.runwaySurface.innerHTML = this.buildRunwaySvg(this._runwayRows, this.getPalette(), true);
			this.elements.distSurface.innerHTML = this.buildDistributionSvg(counts, this.getPalette());
			this.renderTopRisks(disks, resources);
			// The static quality table only changes with a new inventory object; skip
			// the innerHTML rebuild on the per-forecast-batch re-renders.
			if (this._qualityInv !== this.inv) {
				this.renderQuality();
				this._qualityInv = this.inv;
			}
		}

		runwayRows(disks = this.visibleDisks()) {
			const rows = [];
			disks.forEach((d) => {
				const fc = this.fc.get(d.id);
				if (!fc || !fc.eta || fc.eta.next_days == null) { return; }
				rows.push({
					id: d.id, host: d.host, fs: d.fs, sev: this.severityOf(d),
					next: fc.eta.next_days, nextDate: fc.eta.next_date, basis: fc.eta.next_basis,
					crit: fc.eta.crit_days, full: fc.eta.full_days
				});
			});
			rows.sort((a, b) => a.next - b.next);
			return rows.slice(0, 20);
		}

		buildRunwaySvg(rows, palette, interactive) {
			if (!rows.length) {
				return '<div class="cap-empty">No projected threshold crossings inside the forecast horizon (or forecasts are still loading).</div>';
			}
			const margin = {top: 26, right: 40, bottom: 30, left: 230};
			const rowHeight = 26;
			const plotWidth = 620;
			const width = margin.left + plotWidth + margin.right;
			const height = margin.top + rows.length * rowHeight + margin.bottom;
			const maxDays = HORIZON_DAYS;
			const x = (days) => margin.left + Math.min(days, maxDays) / maxDays * plotWidth;

			let svg = this.createSvgOpenTag(width, height, 'Capacity runway');
			svg += `<text x="${margin.left}" y="15" font-size="12" font-weight="600" fill="${palette.title}">Days until the next alarm threshold</text>`;
			[0, 30, 90, 180, 270, 365].forEach((t) => {
				const tx = x(t);
				svg += `<line x1="${tx}" y1="${margin.top}" x2="${tx}" y2="${height - margin.bottom}" stroke="${palette.grid}" stroke-width="1" />`;
				svg += `<text x="${tx}" y="${height - 10}" font-size="10" text-anchor="middle" fill="${palette.axisText}">${t}d</text>`;
			});
			rows.forEach((row, i) => {
				const y = margin.top + i * rowHeight;
				const cy = y + rowHeight / 2;
				const color = (RISK_BY_KEY[row.sev] || RISK_BY_KEY.Unknown).color;
				const label = `${row.host} ${row.fs}`;
				const shown = label.length > 34 ? label.slice(0, 33) + '…' : label;
				svg += `<text x="${margin.left - 10}" y="${cy + 3.5}" font-size="11" text-anchor="end" fill="${palette.title}">${this.escapeHtml(shown)}</text>`;
				const barW = Math.max(3, x(row.next) - margin.left);
				svg += `<g${interactive ? ` class="cap-runway-row" data-id="${row.id}" style="cursor:pointer" role="button" tabindex="0" aria-haspopup="dialog" aria-label="Open capacity details for ${this.escapeHtml(label)}"` : ''}>`;
				svg += `<rect x="${margin.left}" y="${cy - 6}" width="${plotWidth}" height="12" fill="transparent" />`;
				svg += `<rect x="${margin.left}" y="${cy - 6}" width="${barW}" height="12" rx="2" ry="2" fill="${color}" />`;
				if (row.next > maxDays) {
					svg += `<text x="${margin.left + plotWidth + 6}" y="${cy + 3.5}" font-size="10" fill="${palette.axisText}">&gt;1y</text>`;
				}
				else {
					svg += `<text x="${margin.left + barW + 6}" y="${cy + 3.5}" font-size="10" fill="${palette.axisText}">${this.fmtDaysShort(row.next)}</text>`;
				}
				svg += '</g>';
			});
			svg += '</svg>';
			return svg;
		}


		buildDistributionSvg(counts, palette) {
			const margin = {top: 16, right: 56, bottom: 8, left: 88};
			const rowHeight = 30;
			const plotWidth = 300;
			const width = margin.left + plotWidth + margin.right;
			const height = margin.top + RISKS.length * rowHeight + margin.bottom;
			const maxValue = Math.max(1, ...RISKS.map((r) => counts[r.key] || 0));

			let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMinYMin meet" style="width:100%;height:auto;max-width:${width}px" role="img" aria-label="Risk distribution">`;
			svg += `<text x="${margin.left}" y="12" font-size="12" font-weight="600" fill="${palette.title}">Findings</text>`;
			RISKS.forEach((r, i) => {
				const y = margin.top + i * rowHeight;
				const count = counts[r.key] || 0;
				const bw = count / maxValue * plotWidth;
				svg += `<text x="${margin.left - 8}" y="${y + 15}" font-size="12" text-anchor="end" fill="${palette.title}">${r.key}</text>`;
				svg += `<rect x="${margin.left}" y="${y + 4}" width="${Math.max(count > 0 ? 3 : 0, bw)}" height="16" rx="3" ry="3" fill="${r.color}" />`;
				svg += `<text x="${margin.left + Math.max(count > 0 ? 3 : 0, bw) + 8}" y="${y + 16}" font-size="12" fill="${palette.axisText}">${count}</text>`;
			});
			svg += '</svg>';
			return svg;
		}

		renderTopRisks(disks = this.visibleDisks(), resources = this.visibleResources()) {
			const rows = [];
			disks.forEach((d) => {
				const fc = this.fc.get(d.id);
				rows.push({
					kind: 'disk', id: d.id, finding: d, sev: this.severityOf(d), host: d.host,
					resource: d.fs, current: this.currentObservationText(d, this.fmtPct(d.pused)),
					next: fc && fc.eta ? fc.eta.next_days : null,
					nextLabel: fc && fc.eta ? this.fmtDays(fc.eta.next_days) : '—',
					confidence: fc ? (fc.confidence || '—') : (d.current_severity ? 'Current state' : '…'),
					action: (fc && fc.recommendation) || d.current_recommendation || ''
				});
			});
			resources.forEach((r) => {
				const fc = this.fc.get(r.id);
				rows.push({
					kind: 'res', id: r.id, finding: r, sev: this.severityOf(r), host: r.host,
					resource: r.rtype, current: this.currentObservationText(r, this.fmtPct(r.current)),
					next: null, nextLabel: '—',
					confidence: fc ? (fc.confidence || '—') : (r.current_severity ? 'Current state' : '…'),
					action: (fc && fc.recommendation) || r.current_recommendation || ''
				});
			});
			rows.sort((a, b) => (RISK_ORDER[b.sev] ?? -1) - (RISK_ORDER[a.sev] ?? -1)
				|| ((a.next ?? Infinity) - (b.next ?? Infinity))
				|| a.host.localeCompare(b.host));
			const top = rows.slice(0, 15);
			if (!top.length) {
				this.elements.topRisks.innerHTML = '<div class="cap-empty">No findings matched the current filters.</div>';
				return;
			}
			const body = top.map((r) => `
				<tr class="is-clickable" data-kind="${r.kind}" data-id="${r.id}" data-detail-trigger role="button" tabindex="0" aria-haspopup="dialog" aria-label="Open capacity details for ${this.escapeHtml(`${r.host} ${r.resource}`)}">
					<td>${this.riskPill(r.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(r.host)}${this.maintenanceBadgeHtml(r.finding)}</div><div class="cap-cell-sub">${this.escapeHtml(r.resource)}</div></td>
					<td class="cap-num">${this.escapeHtml(r.current)}</td>
					<td class="cap-num">${this.etaCell(r.next, r.nextLabel)}</td>
					<td>${this.escapeHtml(r.confidence)}</td>
					<td>${this.escapeHtml(r.action)}</td>
				</tr>`).join('');
			this.elements.topRisks.innerHTML = `
				<div class="cap-table-scroll"><table class="cap-table">
					<thead><tr><th>Risk</th><th>Host / resource</th><th class="cap-num">Current / last accepted</th><th class="cap-num">Next threshold</th><th>Confidence</th><th>Recommended action</th></tr></thead>
					<tbody>${body}</tbody>
				</table></div>`;
			this.elements.topRisks.querySelectorAll('tr.is-clickable').forEach((tr) => {
				const open = () => {
					const finding = this.inv.byId.get(tr.dataset.id);
					this.switchTab(tr.dataset.kind === 'disk' ? 'disks' : this.resourceTabForFinding(finding));
					this.openDetail(tr.dataset.kind, tr.dataset.id, tr);
				};
				tr.addEventListener('click', open);
				tr.addEventListener('keydown', (e) => {
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
				});
			});
		}

		renderQuality() {
			const issues = this.inv.quality || [];
			this.elements.qualitySubtitle.textContent = issues.length
				? `${issues.length} issue(s) recorded${(this.inv.meta || {}).quality_truncated ? ' (list truncated)' : ''}. Threshold fallbacks, stale items and gaps reduce forecast confidence.`
				: 'No data-quality issues were recorded for this scope.';
			if (!issues.length) {
				this.elements.qualityBody.innerHTML = '';
				return;
			}
			const body = issues.slice(0, 200).map((q) => `
				<tr>
					<td>${this.escapeHtml(q.sev || '')}</td>
					<td>${this.escapeHtml(q.host || '')}</td>
					<td>${this.escapeHtml(q.resource || '')}</td>
					<td>${this.escapeHtml(q.issue || '')}</td>
					<td>${this.escapeHtml(q.detail || '')}</td>
				</tr>`).join('');
			const note = issues.length > 200 ? '<div class="cap-footer-note">Showing the first 200 issues.</div>' : '';
			this.elements.qualityBody.innerHTML = `
				<div class="cap-table-scroll cap-table-window"><table class="cap-table">
					<thead><tr><th>Level</th><th>Host</th><th>Resource</th><th>Issue</th><th>Detail</th></tr></thead>
					<tbody>${body}</tbody>
				</table></div>${note}`;
		}

		// ---- filesystems tab -------------------------------------------------------
		diskRow(d) {
			const fc = this.fc.get(d.id) || null;
			const reasons = this.findingReasons(d, fc);
			const sev = this.severityOf(d);
			return {
				id: d.id, d, fc,
				sev: sev,
				risk: RISK_ORDER[sev] ?? -1,
				host: d.host, fs: d.fs,
				pused: d.pused, free: d.free, usable: this.diskUsableCapacity(d),
				growth: fc ? fc.growth_day : null,
				growthPct: fc ? fc.growth_pct_day : null,
				modelWindow: fc ? this.selectedLabel(fc) : '',
				pctWindow: fc ? this.percentageSelectedLabel(fc) : '',
				pctSource: fc ? (fc.pct_source || '') : '',
				pctConfidence: fc ? (fc.pct_confidence || '') : '',
				pctSeriesDirect: !!(fc && fc.pct_series_direct),
				warn: fc && fc.eta ? fc.eta.warn_days : null,
				warnBasis: fc && fc.eta ? (fc.eta.warn_basis || '') : '',
				crit: fc && fc.eta ? fc.eta.crit_days : null,
				critBasis: fc && fc.eta ? (fc.eta.crit_basis || '') : '',
				full: fc && fc.eta ? fc.eta.full_days : null,
				conf: fc ? (fc.confidence || '') : (d.current_severity ? 'Current state' : ''),
				status: d.status || '', note: fc ? (fc.note || '') : '', reasons,
				maintenance: this.maintenanceFor(d), currentUsable: this.currentObservationUsable(d),
				action: (fc && fc.recommendation) || d.current_recommendation || ''
			};
		}

		paginate(rows, tableKey) {
			const pages = Math.max(1, Math.ceil(rows.length / this.pageSize));
			this.pages[tableKey] = Math.max(1, Math.min(this.pages[tableKey] || 1, pages));
			const start = (this.pages[tableKey] - 1) * this.pageSize;
			return {
				rows: rows.slice(start, start + this.pageSize), total: rows.length,
				start, end: Math.min(rows.length, start + this.pageSize),
				page: this.pages[tableKey], pages
			};
		}

		pagerHtml(tableKey, page, noun) {
			const start = page.total ? page.start + 1 : 0;
			return `<div class="cap-pager" data-pager="${tableKey}">
				<div class="cap-pager-summary">${start}–${page.end} of ${page.total} ${this.escapeHtml(noun)}</div>
				<div class="cap-pager-controls">
					<label>Rows <select data-page-size aria-label="Rows per page">${[25, 50, 100].map((size) => `<option value="${size}"${size === this.pageSize ? ' selected' : ''}>${size}</option>`).join('')}</select></label>
					<button type="button" class="cap-btn cap-btn-compact" data-page-action="prev" aria-label="Previous page"${page.page <= 1 ? ' disabled' : ''}>Previous</button>
					<span>Page ${page.page} of ${page.pages}</span>
					<button type="button" class="cap-btn cap-btn-compact" data-page-action="next" aria-label="Next page"${page.page >= page.pages ? ' disabled' : ''}>Next</button>
				</div>
			</div>`;
		}

		renderDisks() {
			const rows = this.visibleDisks().map((d) => this.diskRow(d));
			if (!rows.length) {
				this.elements.disksBody.innerHTML = '<div class="cap-empty">No filesystems matched the selected scope and filters.</div>';
				return;
			}
			this.sortRows(rows, this.sort.disks);
			const sortInd = (key) => this.sort.disks.key === key ? (this.sort.disks.dir === 'asc' ? ' ▲' : ' ▼') : '';
			const page = this.paginate(rows, 'disks');

			const body = page.rows.map((r) => {
				const observation = r.currentUsable ? '' : '<div class="cap-cell-sub cap-last-accepted">Last accepted</div>';
				const usage = r.pused != null
					? `<div class="cap-usage"><span class="cap-usage-val">${this.fmtPct(r.pused)}</span>${observation}<div class="cap-usage-track"><div class="cap-usage-fill risk-${this.usageRisk(r).toLowerCase()}" style="width:${Math.min(100, Math.max(1, r.pused))}%"></div></div></div>`
					: `<span class="cap-muted">N/A</span>${observation}`;
				const selected = this.selected && this.selected.kind === 'disk' && this.selected.id === r.id;
				let growth = '<span class="cap-muted">No sustained growth</span>';
				if (r.growth != null || r.growthPct != null) {
					const capacityGrowth = r.growth != null ? `${this.fmtBytes(r.growth)}/day` : 'No byte trend';
					const percentageGrowth = r.growthPct != null
						? `${r.growthPct >= 0 ? '+' : ''}${this.trimNum(r.growthPct)} pp/day`
						: 'No percentage trend';
					growth = `<div class="cap-cell-name">${this.escapeHtml(capacityGrowth)}</div><div class="cap-cell-sub">${this.escapeHtml(percentageGrowth)}</div>`;
				}
				return `
				<tr class="is-clickable${selected ? ' is-selected' : ''}" data-kind="disk" data-id="${r.id}" data-detail-trigger role="button" tabindex="0" aria-haspopup="dialog" aria-label="Open capacity details for ${this.escapeHtml(`${r.host} ${r.fs}`)}">
					<td>${this.riskPill(r.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(r.host)}${this.maintenanceBadgeHtml(r.d)}</div><div class="cap-cell-sub">${this.escapeHtml(r.fs)}${r.d.kind === 'Remote' ? ' · remote' : ''}</div></td>
					<td>${usage}</td>
					<td class="cap-num"><div class="cap-cell-name">${this.fmtBytes(r.free)}</div>${observation}</td>
					<td class="cap-num">${growth}</td>
					<td class="cap-num">${this.etaCell(r.warn, this.fmtDays(r.warn))}</td>
					<td class="cap-num">${this.etaCell(r.crit, this.fmtDays(r.crit))}</td>
					<td class="cap-num">${this.etaCell(r.full, this.fmtDays(r.full))}</td>
					<td>${this.escapeHtml(r.conf || (r.fc ? '' : (this.fcReady ? '—' : '…')))}${r.fc && (r.fc.accelerating || r.fc.pct_accelerating) ? ' <span title="Recent growth is accelerating">⚠</span>' : ''}</td>
				</tr>`;
			}).join('');

			const pager = this.pagerHtml('disks', page, 'filesystems');
			this.elements.disksBody.innerHTML = `
				${pager}<div class="cap-table-scroll cap-table-window"><table class="cap-table" data-table="disks">
					<thead><tr>
						<th class="cap-sortable" data-sort="risk">Risk${sortInd('risk')}</th>
						<th class="cap-sortable" data-sort="host">Host / filesystem${sortInd('host')}</th>
						<th class="cap-sortable" data-sort="pused">Used${sortInd('pused')}</th>
						<th class="cap-sortable cap-num" data-sort="free">Free${sortInd('free')}</th>
						<th class="cap-sortable cap-num" data-sort="growth">Growth${sortInd('growth')}</th>
						<th class="cap-sortable cap-num" data-sort="warn">Warning ETA${sortInd('warn')}</th>
						<th class="cap-sortable cap-num" data-sort="crit">Critical ETA${sortInd('crit')}</th>
						<th class="cap-sortable cap-num" data-sort="full">Full ETA${sortInd('full')}</th>
						<th class="cap-sortable" data-sort="conf">Confidence${sortInd('conf')}</th>
					</tr></thead>
					<tbody>${body}</tbody>
				</table></div>${pager}`;

			this.bindTable(this.elements.disksBody, 'disks', 'disk');
		}

		usageRisk(row) {
			if (!row.currentUsable) { return 'Unknown'; }
			const d = row.d;
			if (this.diskBreach(d, 'crit')) { return 'Critical'; }
			if (this.diskBreach(d, 'warn')) { return 'High'; }
			return 'Healthy';
		}

		// ---- CPU & memory tab ------------------------------------------------------
		resourceRow(r) {
			const fc = this.fc.get(r.id) || null;
			const sel = this.selectedStats(fc);
			const saturation = fc && fc.saturation && typeof fc.saturation === 'object'
				? fc.saturation
				: null;
			const sev = this.severityOf(r);
			return {
				id: r.id, r, fc,
				sev: sev,
				risk: RISK_ORDER[sev] ?? -1,
				host: r.host, rtype: r.rtype,
				current: r.current,
				avg: sel ? sel.avg : null,
				p95: sel ? sel.p95 : null,
				peak: sel ? sel.peak : null,
				aboveWarn: sel ? sel.above_warn : null,
				aboveCrit: sel ? sel.above_crit : null,
				baselineSeverity: fc ? (fc.baseline_severity || '') : '',
				saturationSeverity: fc ? (fc.saturation_severity || (saturation && saturation.severity) || '') : '',
				confidence: fc ? (fc.confidence || '') : (r.current_severity ? 'Current state' : ''),
				window: fc ? this.selectedLabel(fc) : '',
				selectedSource: fc ? (fc.selected_source || '') : '',
				note: fc ? (fc.note || '') : '',
				episodes: saturation ? saturation.confirmed_episode_count : null,
				episodeDays: saturation ? saturation.confirmed_episode_days : null,
				longest: saturation ? saturation.confirmed_longest_minutes : null,
				totalDuration: saturation ? saturation.confirmed_total_minutes : null,
				nearFullCount: saturation ? saturation.max_observation_count : null,
				nearFullDays: saturation ? saturation.max_observation_days : null,
				saturation,
				historicalSaturation: fc && fc.historical_saturation
					&& typeof fc.historical_saturation === 'object' ? fc.historical_saturation : null,
				regime: fc && fc.regime && typeof fc.regime === 'object' ? fc.regime : null,
				reasons: this.findingReasons(r, fc),
				maintenance: this.maintenanceFor(r), currentUsable: this.currentObservationUsable(r),
				action: (fc && fc.recommendation) || r.current_recommendation || ''
			};
		}

		renderResources() {
			const rows = this.visibleResources().map((r) => this.resourceRow(r));
			const label = this.activeTab === 'memory' ? 'RAM' : 'CPU';
			if (!rows.length) {
				this.elements.resourcesBody.innerHTML = `<div class="cap-empty">No ${label} metrics matched the selected scope and filters.</div>`;
				return;
			}
			this.sortRows(rows, this.sort.resources);
			const sortInd = (key) => this.sort.resources.key === key ? (this.sort.resources.dir === 'asc' ? ' ▲' : ' ▼') : '';
			const page = this.paginate(rows, 'resources');

			const body = page.rows.map((row) => {
				const selected = this.selected && this.selected.kind === 'res' && this.selected.id === row.id;
				const observation = row.currentUsable ? '' : '<div class="cap-cell-sub cap-last-accepted">Last accepted</div>';
				const provisioned = row.r.provisioned != null
					? (row.r.unit === 'bytes' ? this.fmtBytes(row.r.provisioned) : `${Math.round(row.r.provisioned)} ${row.r.unit || ''}`)
					: '—';
				return `
				<tr class="is-clickable${selected ? ' is-selected' : ''}" data-kind="res" data-id="${row.id}" data-detail-trigger role="button" tabindex="0" aria-haspopup="dialog" aria-label="Open capacity details for ${this.escapeHtml(`${row.host} ${row.rtype}`)}">
					<td>${this.riskPill(row.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(row.host)}${this.maintenanceBadgeHtml(row.r)}</div><div class="cap-cell-sub">${this.escapeHtml(row.rtype)} · ${this.escapeHtml(provisioned)} · ${this.escapeHtml(row.window || 'window pending')}</div></td>
					<td class="cap-num"><div class="cap-cell-name">${this.fmtPct(row.current)}</div>${observation}</td>
					<td class="cap-num"><div class="cap-cell-name">${this.fmtPct(row.p95)}</div><div class="cap-cell-sub">avg ${this.fmtPct(row.avg)}</div></td>
					<td class="cap-num"><div class="cap-cell-name">${this.fmtPct(row.peak)}</div><div class="cap-cell-sub">review ${this.fmtPct(row.aboveWarn)} · alarm ${this.fmtPct(row.aboveCrit)}</div></td>
					<td class="cap-num">${this.countDaysLabel(row.episodes, row.episodeDays)}</td>
					<td class="cap-num"><div class="cap-cell-name">${this.fmtDurationMinutes(row.longest)}</div><div class="cap-cell-sub">total ${this.fmtDurationMinutes(row.totalDuration)}</div></td>
					<td><div class="cap-cell-name">${this.escapeHtml(row.baselineSeverity || '—')} / ${this.escapeHtml(row.saturationSeverity || '—')}</div><div class="cap-cell-sub">${this.escapeHtml(row.confidence || '—')} · ${this.escapeHtml(row.selectedSource || 'source pending')}</div></td>
					<td class="cap-assessment-cell">${this.escapeHtml(row.action || (this.fcReady ? '—' : '…'))}</td>
				</tr>`;
			}).join('');

			const pager = this.pagerHtml('resources', page, `${label} metrics`);
			this.elements.resourcesBody.innerHTML = `
				${pager}<div class="cap-table-scroll cap-table-window"><table class="cap-table" data-table="resources">
					<thead><tr>
						<th class="cap-sortable" data-sort="risk">Risk${sortInd('risk')}</th>
						<th class="cap-sortable" data-sort="host">Host / resource${sortInd('host')}</th>
						<th class="cap-sortable cap-num" data-sort="current">Current / last accepted${sortInd('current')}</th>
						<th class="cap-sortable cap-num" data-sort="p95">p95 / average${sortInd('p95')}</th>
						<th class="cap-sortable cap-num" data-sort="peak">Peak / exposure${sortInd('peak')}</th>
						<th class="cap-sortable cap-num" data-sort="episodes">Confirmed episodes / days${sortInd('episodes')}</th>
						<th class="cap-sortable cap-num" data-sort="longest">Longest / total${sortInd('longest')}</th>
						<th>Baseline / saturation · confidence</th>
						<th>Assessment</th>
					</tr></thead>
					<tbody>${body}</tbody>
				</table></div>${pager}`;

			this.bindTable(this.elements.resourcesBody, 'resources', 'res');
		}

		bindTable(container, tableKey, kind) {
			const rerender = () => {
				if (tableKey === 'disks') { this.renderDisks(); } else { this.renderResources(); }
			};
			container.querySelectorAll('th.cap-sortable').forEach((th) => {
				th.addEventListener('click', () => {
					const key = th.dataset.sort;
					const sort = this.sort[tableKey];
					if (sort.key === key) { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'; }
					else { sort.key = key; sort.dir = (key === 'host' || key === 'conf') ? 'asc' : 'desc'; }
					this.pages[tableKey] = 1;
					rerender();
				});
			});
			container.querySelectorAll('tr.is-clickable').forEach((tr) => {
				tr.addEventListener('click', () => this.openDetail(kind, tr.dataset.id, tr));
				tr.addEventListener('keydown', (e) => {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						this.openDetail(kind, tr.dataset.id, tr);
					}
				});
			});
			container.querySelectorAll('[data-page-action]').forEach((button) => {
				button.addEventListener('click', () => {
					this.pages[tableKey] += button.dataset.pageAction === 'next' ? 1 : -1;
					rerender();
				});
			});
			container.querySelectorAll('[data-page-size]').forEach((select) => {
				select.addEventListener('change', () => {
					const size = Number(select.value);
					if (![25, 50, 100].includes(size)) { return; }
					this.pageSize = size;
					this.resetPages();
					this.updateLocationState();
					rerender();
				});
			});
		}

		sortRows(rows, sort) {
			const {key, dir} = sort;
			const mul = dir === 'asc' ? 1 : -1;
			const nullsLast = ['warn', 'crit', 'full', 'growth', 'pused', 'free', 'current', 'avg', 'p95',
				'peak', 'aboveWarn', 'aboveCrit', 'episodes', 'longest', 'totalDuration', 'nearFullCount',
				'nearFullDays'];
			// Key dispatch is hoisted out of the comparator; each branch body is
			// unchanged, so the resulting order is identical.
			const isNullsLast = nullsLast.includes(key);
			const rank = (v) => CONFIDENCE_RANK[String(v || '')] ?? -1;
			rows.sort((a, b) => {
				let av = a[key];
				let bv = b[key];
				if (key === 'conf') {
					// Confidence is an ordered scale; alphabetical order would put
					// Low between High and Medium.
					return (rank(av) - rank(bv)) * mul;
				}
				if (key === 'host') {
					av = String(av || '').toLowerCase();
					bv = String(bv || '').toLowerCase();
					return av < bv ? -mul : av > bv ? mul : 0;
				}
				if (isNullsLast) {
					// ETA sorting keeps "no projection" at the bottom in both directions.
					if (av == null && bv == null) { return 0; }
					if (av == null) { return 1; }
					if (bv == null) { return -1; }
					return (av - bv) * mul;
				}
				return ((Number(av) || 0) - (Number(bv) || 0)) * mul;
			});
		}

		// ---- detail (drill-down chart) ---------------------------------------------
		openDetail(kind, id, opener = null) {
			const finding = this.inv.byId.get(id);
			if (!finding) { return; }
			const modalWasOpen = this.elements.detailModal.classList.contains('is-open');
			const changedFinding = !this.selected || this.selected.kind !== kind || this.selected.id !== id;
			if (!modalWasOpen || changedFinding) {
				this.detailLastFocused = opener || document.activeElement;
			}
			if (changedFinding) {
				this.detailChartSpec = null;
				this.detailChartId = null;
				this.detailZoom = null;
				this.updateDetailZoomUi();
			}
			this.selected = {kind, id};
			this.detailPending = !this.fc.get(id);
			if (kind === 'disk') { this.renderDisks(); this.renderDiskDetail(finding); }
			else { this.renderResources(); this.renderResourceDetail(finding); }
		}

		showDetailModal(kind, finding = null) {
			const modal = this.elements.detailModal;
			const wasOpen = modal.classList.contains('is-open');
			this.elements.detailEyebrow.textContent = kind === 'disk'
				? 'Filesystem capacity evidence'
				: `${this.resourceTabForFinding(finding) === 'memory' ? 'RAM' : 'CPU'} capacity evidence`;
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
			document.body.classList.add('cap-modal-open');
			this.setBackgroundInert(true);
			if (!wasOpen) {
				const focusClose = () => {
					if (modal.classList.contains('is-open')) { this.elements.detailClose.focus({preventScroll: true}); }
				};
				focusClose();
				requestAnimationFrame(focusClose);
				setTimeout(focusClose, 30);
				// The browser's asynchronous inert focus fixup can land later than
				// the timed retries under load and silently drop focus to <body>.
				// Re-assert while the dialog settles whenever focus escapes it;
				// closing removes is-open first, so focus restoration is untouched.
				const guard = (e) => {
					if (!modal.classList.contains('is-open')) { return; }
					if (!e.relatedTarget || !modal.contains(e.relatedTarget)) { focusClose(); }
				};
				modal.addEventListener('focusout', guard);
				setTimeout(() => modal.removeEventListener('focusout', guard), 400);
			}
		}

		setBackgroundInert(inert) {
			// The Tab trap covers keyboard focus; inert additionally hides the
			// report behind the dialog from assistive-technology virtual cursors.
			// Only the modal's siblings inside the shell are affected — the shared
			// tooltip node outside the shell stays usable for the modal chart.
			const modal = this.elements.detailModal;
			const shell = modal && modal.parentElement;
			if (!shell) { return; }
			// Blur a focused background element (e.g. the clicked table row) before
			// inerting its container: the browser's own inert focus fixup can land
			// asynchronously and would steal the focus given to the dialog.
			const active = document.activeElement;
			if (inert && active instanceof HTMLElement && shell.contains(active)
					&& !modal.contains(active)) {
				active.blur();
			}
			[...shell.children].forEach((child) => {
				if (child === modal) { return; }
				if ('inert' in child) { child.inert = inert; }
				if (inert) { child.setAttribute('aria-hidden', 'true'); }
				else { child.removeAttribute('aria-hidden'); }
			});
		}

		onDetailModalKeydown(e) {
			const modal = this.elements.detailModal;
			if (!modal.classList.contains('is-open')) { return; }
			if (e.key === 'Escape') {
				e.preventDefault();
				this.hideDetail();
				return;
			}
			if (e.key !== 'Tab') { return; }
			const focusable = [...modal.querySelectorAll('button:not([disabled]), select:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')]
				.filter((el) => el.offsetParent !== null);
			if (!focusable.length) { e.preventDefault(); return; }
			const first = focusable[0];
			const last = focusable[focusable.length - 1];
			if (!modal.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
			else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}

		hideDetail(restoreFocus = true) {
			const previous = this.selected ? {...this.selected} : null;
			const wasOpen = this.elements.detailModal.classList.contains('is-open');
			this.elements.detailModal.classList.remove('is-open');
			this.elements.detailModal.setAttribute('aria-hidden', 'true');
			document.body.classList.remove('cap-modal-open');
			// Uninert before focus restoration: an inert element cannot take focus.
			this.setBackgroundInert(false);
			this.selected = null;
			this.detailPending = false;
			this.detailGeom = null;
			this.detailEls = null;
			this.detailChartSpec = null;
			this.detailChartId = null;
			this.detailZoom = null;
			this.detailDrag = null;
			this.hideDetailHover();
			if (previous && this.inv.ready) {
				if (previous.kind === 'disk' && this.activeTab === 'disks') { this.renderDisks(); }
				if (previous.kind === 'res' && RESOURCE_TABS.includes(this.activeTab)) { this.renderResources(); }
			}
			if (wasOpen && restoreFocus) {
				let target = this.detailLastFocused;
				const isVisible = (element) => !!element && element.isConnected
					&& element.getClientRects().length > 0 && !element.closest('[hidden]');
				if (!isVisible(target)) {
					const selector = previous
						? `[data-detail-trigger][data-kind="${previous.kind}"][data-id="${previous.id}"]`
						: '';
					target = selector
						? [...this.root.querySelectorAll(selector)].find((element) => isVisible(element)) || null
						: null;
				}
				if (target && typeof target.focus === 'function') { target.focus(); }
			}
			this.detailLastFocused = null;
		}

		renderDiskDetail(d) {
			const fc = this.fc.get(d.id);
			const els = this.elements;
			els.diskDetailTitle.textContent = `${d.host} — ${d.fs}`;
			this.showDetailModal('disk');
			const maintenanceDetail = this.maintenanceDetailHtml(d);

			const hasCurrentAssessment = d.current_severity && RISK_BY_KEY[d.current_severity];
			if ((fc && fc.status === 'denied') || (!fc && !hasCurrentAssessment)) {
				els.diskDetailSubtitle.textContent = fc && fc.status === 'denied'
					? 'This item is not readable with your permissions.'
					: (this.fcReady ? 'No forecast or current-state assessment is available.' : 'The forecast is still loading…');
				els.diskDetailStats.innerHTML = maintenanceDetail;
				els.diskDetailLegend.innerHTML = '';
				els.diskDetailSurface.innerHTML = '<div class="cap-empty">No chart data.</div>';
				this.detailGeom = null;
				this.detailEls = null;
				return;
			}

			const hasModel = !!fc && fc.status === 'ok';
			const modelPending = !fc && !!d.itemid && !this.fcReady;
			const eta = fc && fc.eta ? fc.eta : {};
			const windowLabel = this.selectedLabel(fc);
			const pctWindowLabel = this.percentageSelectedLabel(fc);
			const sel = this.selectedStats(fc);
			if (hasModel) {
				const modelParts = [];
				if (fc.sel) {
					modelParts.push(`used-capacity window ${windowLabel}`
						+ (sel ? ` (${sel.days} days, ${sel.cov}% coverage${sel.r2 != null ? `, R² ${sel.r2}` : ''})` : ''));
				}
				if (fc.pct_sel) {
					modelParts.push(`used-percentage window ${pctWindowLabel}`);
				}
				els.diskDetailSubtitle.textContent =
					`Historical disk-growth evidence${Array.isArray(fc.series) && fc.series.length ? ' with a projected trend' : ''}. `
					+ `${modelParts.length ? modelParts.join(' · ') : 'No model window was selected'}`
					+ `${fc.source === 'history' ? ' — short raw-history fallback.' : '.'}`;
			}
			else {
				els.diskDetailSubtitle.textContent = modelPending
					? 'Current-state threshold evidence is shown while the historical forecast loads.'
					: 'Current-state threshold evidence is available, but no usable historical growth model was found.';
			}

			const usable = this.diskUsableCapacity(d);
			const reportedTotal = d.total != null
				? ` · reported total <b>${this.fmtBytes(d.total)}</b>`
				: '';
			const currentReasons = this.findingReasons(d, fc);
			const recommendation = (fc && fc.recommendation) || d.current_recommendation || '';
			const basis = (value) => value ? ` · basis <b>${this.escapeHtml(value)}</b>` : '';
			const pctTrend = fc && fc.growth_pct_day != null
				? `${fc.growth_pct_day >= 0 ? '+' : ''}${this.trimNum(fc.growth_pct_day)} pp/day`
				: 'no sustained percentage growth';
			const pctOrigin = fc && fc.pct_sel
				? (fc.pct_series_direct ? ' · direct percentage history' : ' · derived percentage history')
				: '';
			const stats = [
				maintenanceDetail,
				`Overall risk: <b>${this.escapeHtml(this.severityOf(d))}</b> · data status: <b>${this.escapeHtml(d.status || 'Unknown')}</b>`,
				`${this.currentObservationUsable(d) ? 'Now' : 'Last accepted'}: <b>${this.fmtPct(d.pused)}</b> used, <b>${this.fmtBytes(d.free)}</b> free · usable capacity <b>${this.fmtBytes(usable)}</b>${reportedTotal}`,
				hasModel
					? `Used-capacity trend: <b>${fc.growth_day != null ? this.fmtBytes(fc.growth_day) + '/day' : 'no sustained byte growth'}</b> · window <b>${this.escapeHtml(windowLabel)}</b> · source <b>${this.escapeHtml(fc.source || 'n/a')}</b>${fc.accelerating ? ' (accelerating)' : ''}`
					: 'Growth model: <b>unavailable</b>',
				hasModel
					? `Used-percentage trend: <b>${this.escapeHtml(pctTrend)}</b> · window <b>${this.escapeHtml(pctWindowLabel)}</b> · source <b>${this.escapeHtml(fc.pct_source || 'n/a')}</b> · confidence <b>${this.escapeHtml(fc.pct_confidence || 'n/a')}</b>${pctOrigin}`
					: '',
				`Warning: <b>${this.fmtDays(eta.warn_days)}</b>${eta.warn_date ? ` (${this.fmtDate(eta.warn_date)})` : ''}${basis(eta.warn_basis)}`,
				`Critical: <b>${this.fmtDays(eta.crit_days)}</b>${eta.crit_date ? ` (${this.fmtDate(eta.crit_date)})` : ''}${basis(eta.crit_basis)}`,
				`Full: <b>${this.fmtDays(eta.full_days)}</b>${eta.full_date ? ` (${this.fmtDate(eta.full_date)})` : ''}`,
				fc && fc.confidence ? `Confidence: <b>${this.escapeHtml(fc.confidence)}</b>` : '',
				`Thresholds: warn ${this.thresholdLabel(d.warn_pct, '%')} · crit ${this.thresholdLabel(d.crit_pct, '%')}`
					+ `${d.warn_free && d.warn_free.v > 0 ? ` · warn free ${this.fmtBytes(d.warn_free.v)}` : ''}`
					+ `${d.crit_free && d.crit_free.v > 0 ? ` · crit free ${this.fmtBytes(d.crit_free.v)}` : ''}`,
				currentReasons.length ? `Current-state evidence: <b>${this.escapeHtml(currentReasons.join('; '))}</b>` : '',
				fc && fc.note ? `Data note: <b>${this.escapeHtml(fc.note)}</b>` : '',
				recommendation ? `Assessment: <b>${this.escapeHtml(recommendation)}</b>` : ''
			].filter(Boolean);
			els.diskDetailStats.innerHTML = stats.map((s) => s === maintenanceDetail ? s : `<span>${s}</span>`).join('');

			if (hasModel) {
				const spec = this.diskChartSpec(d, fc);
				this.renderDetailChart(els.diskDetailSurface, els.diskDetailLegend, spec);
			}
			else {
				els.diskDetailLegend.innerHTML = '';
				els.diskDetailSurface.innerHTML = `<div class="cap-empty">${modelPending ? 'Historical forecast still loading.' : 'No historical model; current-state evidence is shown above.'}</div>`;
				this.detailGeom = null;
				this.detailEls = null;
			}
		}

		thresholdLabel(t, suffix) {
			if (!t || t.v == null) { return 'n/a'; }
			return `${this.trimNum(t.v)}${suffix}${t.fb ? '*' : ''}`;
		}

		diskUsableCapacity(d) {
			if (d.usable != null && isFinite(Number(d.usable)) && Number(d.usable) > 0) {
				return Number(d.usable);
			}
			if (d.used != null && d.free != null && d.used + d.free > 0) { return d.used + d.free; }
			if (d.used != null && d.pused != null && d.pused > 0) { return d.used / (d.pused / 100); }
			return d.os === 'Windows' && d.total != null && d.total > 0 ? d.total : null;
		}

		diskChartSpec(d, fc) {
			// Chart in percent when a capacity is known; otherwise raw GiB.
			const capacity = this.diskUsableCapacity(d);
			const asPct = capacity != null && capacity > 0;
			const scale = asPct ? 100 / capacity : 1 / GIB;
			const hasPctSeries = asPct && Array.isArray(fc.pct_series) && fc.pct_series.length > 0;
			const chartSeries = hasPctSeries ? fc.pct_series : (fc.series || []);
			const chartScale = hasPctSeries ? 1 : scale;
			const series = chartSeries.map((p) => ({
				clock: p[0], min: p[1] * chartScale, avg: p[2] * chartScale, max: p[3] * chartScale
			}));
			const currentUsable = this.currentObservationUsable(d);
			const currentValue = !currentUsable
				? null
				: (asPct
					? (d.pused != null ? d.pused : (series.length ? series[series.length - 1].avg : null))
					: (d.used != null ? d.used / GIB : null));
			// Prefer the direct used-percentage slope on percentage charts. This keeps
			// visual threshold crossings aligned with percentage-based ETAs.
			const slope = !currentUsable
				? null
				: (asPct && fc.growth_pct_day != null
					? fc.growth_pct_day
					: (fc.growth_day != null ? fc.growth_day * scale : null));
			const thresholds = [];
			if (asPct) {
				if (d.warn_pct && d.warn_pct.v != null) { thresholds.push({value: d.warn_pct.v, label: `Warning ${this.trimNum(d.warn_pct.v)}%`, kind: 'warn'}); }
				if (d.crit_pct && d.crit_pct.v != null) { thresholds.push({value: d.crit_pct.v, label: `Critical ${this.trimNum(d.crit_pct.v)}%`, kind: 'crit'}); }
				if (d.warn_free && d.warn_free.v > 0) {
					const value = Math.max(0, Math.min(100, (capacity - d.warn_free.v) / capacity * 100));
					thresholds.push({value, label: `Warning free ${this.fmtBytes(d.warn_free.v)} (${this.trimNum(value)}% used)`, kind: 'warn'});
				}
				if (d.crit_free && d.crit_free.v > 0) {
					const value = Math.max(0, Math.min(100, (capacity - d.crit_free.v) / capacity * 100));
					thresholds.push({value, label: `Critical free ${this.fmtBytes(d.crit_free.v)} (${this.trimNum(value)}% used)`, kind: 'crit'});
				}
			}
			return {
				title: asPct ? 'Used capacity (%)' : 'Used capacity (GiB)',
				unit: asPct ? '%' : ' GiB',
				yMax: asPct ? 100 : null,
				series, currentValue, slope, thresholds,
				projectionLabel: currentUsable ? 'Projected growth' : ''
			};
		}

		renderResourceDetail(r) {
			const fc = this.fc.get(r.id);
			const els = this.elements;
			els.resDetailTitle.textContent = `${r.host} — ${r.rtype}`;
			this.showDetailModal('res', r);
			const maintenanceDetail = this.maintenanceDetailHtml(r);

			const hasCurrentAssessment = r.current_severity && RISK_BY_KEY[r.current_severity];
			if ((fc && fc.status === 'denied') || (!fc && !hasCurrentAssessment)) {
				els.resDetailSubtitle.textContent = fc && fc.status === 'denied'
					? 'This item is not readable with your permissions.'
					: (this.fcReady ? 'No baseline or current-state assessment is available.' : 'The analysis is still loading…');
				els.resDetailStats.innerHTML = maintenanceDetail;
				els.resDetailLegend.innerHTML = '';
				els.resDetailSurface.innerHTML = '<div class="cap-empty">No chart data.</div>';
				this.detailGeom = null;
				this.detailEls = null;
				return;
			}

			const hasHistory = !!fc && fc.status === 'ok';
			const analysisPending = !fc && !!r.itemid && !this.fcReady;
			const windowLabel = this.selectedLabel(fc);
			const sel = this.selectedStats(fc);
			const saturation = fc && fc.saturation && typeof fc.saturation === 'object' ? fc.saturation : null;
			const historicalSaturation = fc && fc.historical_saturation
				&& typeof fc.historical_saturation === 'object' ? fc.historical_saturation : null;
			const regime = fc && fc.regime && typeof fc.regime === 'object' ? fc.regime : null;
			const saturationSeverity = (fc && fc.saturation_severity)
				|| (saturation && saturation.severity) || '';
			const reasons = this.findingReasons(r, fc);
			const recommendation = (fc && fc.recommendation) || r.current_recommendation || '';
			if (hasHistory) {
				els.resDetailSubtitle.textContent =
					`Historical utilization evidence. Assessment window: ${windowLabel}`
					+ (sel ? ` (${sel.days} days of data, ${sel.cov}% coverage)` : '')
					+ (fc.confidence ? ` · ${fc.confidence} confidence.` : '.');
			}
			else {
				els.resDetailSubtitle.textContent = analysisPending
					? 'Current-state threshold evidence is shown while historical analysis loads.'
					: 'Current-state threshold evidence is available, but no qualified historical baseline was found.';
			}

			const stats = [
				maintenanceDetail,
				`Overall risk: <b>${this.escapeHtml(this.severityOf(r))}</b> · data status: <b>${this.escapeHtml(r.status || 'Unknown')}</b>`,
				`${this.currentObservationUsable(r) ? 'Now' : 'Last accepted'}: <b>${this.fmtPct(r.current)}</b>`,
				sel ? `Average: <b>${this.fmtPct(sel.avg)}</b> · p95: <b>${this.fmtPct(sel.p95)}</b> · peak: <b>${this.fmtPct(sel.peak)}</b>` : '',
				sel && sel.above_warn != null ? `Time above review: <b>${this.fmtPct(sel.above_warn)}</b>` : '',
				sel && sel.above_crit != null ? `Time above alarm: <b>${this.fmtPct(sel.above_crit)}</b>` : '',
				(fc && fc.baseline_severity) || saturationSeverity
					? `Baseline risk: <b>${this.escapeHtml((fc && fc.baseline_severity) || 'n/a')}</b> · saturation risk: <b>${this.escapeHtml(saturationSeverity || 'n/a')}</b>`
					: '',
				fc && fc.selected_source ? `Baseline source: <b>${this.escapeHtml(fc.selected_source)}</b>` : '',
				saturation
					? `Confirmed saturation episodes: <b>${this.fmtCount(saturation.confirmed_episode_count)}</b> across <b>${this.fmtCount(saturation.confirmed_episode_days)}</b> day(s) · longest: <b>${this.fmtDurationMinutes(saturation.confirmed_longest_minutes)}</b> · total: <b>${this.fmtDurationMinutes(saturation.confirmed_total_minutes)}</b>`
					: '',
				saturation
					? `Long episodes: <b>${this.fmtCount(saturation.confirmed_long_episode_count)}</b> · critical episodes: <b>${this.fmtCount(saturation.confirmed_critical_episode_count)}</b> · ongoing duration: <b>${this.fmtDurationMinutes(saturation.confirmed_ongoing_minutes)}</b>`
					: '',
				saturation
					? `Near-full peaks: <b>${this.fmtCount(saturation.max_observation_count)}</b> across <b>${this.fmtCount(saturation.max_observation_days)}</b> day(s) · duration unknown for <b>${this.fmtCount(saturation.duration_unknown_max_count)}</b>`
					: '',
				saturation
					? `Saturation evidence: threshold <b>${this.fmtPct(saturation.threshold_pct)}</b> · near-full <b>${this.fmtPct(saturation.near_full_threshold_pct)}</b> · ${this.fmtCount(saturation.window_days)} day window at ${this.fmtPct(saturation.coverage_pct)} coverage · <b>${this.escapeHtml(saturation.source || 'n/a')}</b> / <b>${this.escapeHtml(saturation.confidence || 'n/a')}</b>`
					: '',
				regime ? `Regime analysis: <b>${this.escapeHtml(this.regimeSummary(regime))}</b>` : '',
				saturation && saturation.reason
					? `Saturation rationale: <b>${this.escapeHtml(saturation.reason)}</b>`
					: '',
				historicalSaturation
					? `Pre-change saturation (historical only): <b>${this.escapeHtml(this.historicalSaturationSummary(historicalSaturation))}</b>`
					: '',
				`Review level: ${this.thresholdLabel(r.warn, '%')} · Alarm level: ${this.thresholdLabel(r.crit, '%')}`,
				fc && fc.growth_pct_day != null ? `Descriptive baseline drift: <b>${fc.growth_pct_day >= 0 ? '+' : ''}${this.trimNum(fc.growth_pct_day * 30)} pp/month</b> (not extrapolated)` : '',
				fc && fc.note ? `Data note: <b>${this.escapeHtml(fc.note)}</b>` : '',
				reasons.length ? `Assessment evidence: <b>${this.escapeHtml(reasons.join('; '))}</b>` : '',
				recommendation ? `Assessment: <b>${this.escapeHtml(recommendation)}</b>` : ''
			].filter(Boolean);
			els.resDetailStats.innerHTML = stats.map((s) => s === maintenanceDetail ? s : `<span>${s}</span>`).join('');

			if (hasHistory) {
				const thresholds = [];
				if (r.warn && r.warn.v != null) { thresholds.push({value: r.warn.v, label: `Review ${this.trimNum(r.warn.v)}%`, kind: 'warn'}); }
				if (r.crit && r.crit.v != null) { thresholds.push({value: r.crit.v, label: `Alarm ${this.trimNum(r.crit.v)}%`, kind: 'crit'}); }
				const spec = {
					title: `${r.rtype} utilization (%)`,
					unit: '%',
					yMax: 100,
					series: (fc.series || []).map((p) => ({clock: p[0], min: p[1], avg: p[2], max: p[3]})),
					currentValue: this.currentObservationUsable(r) ? r.current : null,
					slope: null,
					thresholds,
					projectionLabel: ''
				};
				this.renderDetailChart(els.resDetailSurface, els.resDetailLegend, spec);
			}
			else {
				els.resDetailLegend.innerHTML = '';
				els.resDetailSurface.innerHTML = `<div class="cap-empty">${analysisPending ? 'Historical analysis still loading.' : 'No qualified historical baseline; current-state evidence is shown above.'}</div>`;
				this.detailGeom = null;
				this.detailEls = null;
			}
		}

		renderDetailChart(surface, legendEl, spec) {
			if (!spec.series.length) {
				surface.innerHTML = '<div class="cap-empty">No series data.</div>';
				legendEl.innerHTML = '';
				this.detailGeom = null;
				this.detailEls = null;
				this.detailChartSpec = null;
				return;
			}
			const chartId = this.selected ? this.selected.id : null;
			if (chartId !== this.detailChartId) { this.detailZoom = null; }
			this.detailChartId = chartId;
			this.detailChartSpec = spec;
			this.drawDetailChart(surface, legendEl);
		}

		drawDetailChart(surface = this.elements.detailSurface, legendEl = this.elements.detailLegend) {
			if (!this.detailChartSpec || !this.detailChartSpec.series.length) { return; }
			const fullSpec = this.detailChartSpec;
			let spec = fullSpec;
			if (this.detailZoom) {
				const series = fullSpec.series.filter((point) =>
					point.clock >= this.detailZoom.from && point.clock <= this.detailZoom.to);
				if (series.length >= 2) {
					spec = {...fullSpec, series, currentValue: null, slope: null, projectionLabel: '',
						viewFrom: this.detailZoom.from, viewTo: this.detailZoom.to};
				}
				else {
					this.detailZoom = null;
				}
			}
			const palette = this.getPalette();
			const built = this.buildUsageSvg(spec, palette, true);
			legendEl.innerHTML = [
				`<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${palette.line}"></span>Daily average</span>`,
				`<span class="cap-legend-item"><span class="cap-legend-swatch is-band" style="background:${palette.line}"></span>Daily min–max</span>`,
				spec.slope != null ? `<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${palette.projection}"></span>${this.escapeHtml(spec.projectionLabel)}</span>` : '',
				built.geom.patternDrawn ? `<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${palette.projection};opacity:0.55"></span>Possible path (history pattern)</span>` : '',
				...spec.thresholds.map((t) => `<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${t.kind === 'crit' ? palette.crit : palette.warn}"></span>${this.escapeHtml(t.label)}</span>`)
			].filter(Boolean).join('');

			surface.innerHTML = built.svg;
			this.detailGeom = built.geom;
			const svg = surface.querySelector('svg');
			this.detailEls = svg ? {svg, crosshair: svg.querySelector('.cap-crosshair'), surface} : null;
			this.updateDetailZoomUi();
		}

		updateDetailZoomUi() {
			const zoomed = !!this.detailZoom;
			this.elements.detailZoomReset.hidden = !zoomed;
			this.elements.detailZoomLabel.hidden = !zoomed;
			this.elements.detailZoomLabel.textContent = zoomed
				? `Selected chart range: ${this.fmtDate(this.detailZoom.from)} – ${this.fmtDate(this.detailZoom.to)}`
				: '';
		}

		resetDetailZoom() {
			if (!this.detailZoom) { return; }
			this.detailZoom = null;
			this.drawDetailChart();
			this.elements.detailSurface.focus({preventScroll: true});
		}

		detailPointerPosition(e, surface) {
			const geom = this.detailGeom;
			const svg = surface.querySelector('svg');
			if (!geom || !svg || !geom.series || geom.series.length < 2) { return null; }
			const rect = svg.getBoundingClientRect();
			if (!rect.width) { return null; }
			const viewBox = svg.viewBox.baseVal;
			const width = viewBox && viewBox.width ? viewBox.width : rect.width;
			const height = viewBox && viewBox.height ? viewBox.height : rect.height;
			const rawX = (e.clientX - rect.left) * (width / rect.width);
			const rawY = (e.clientY - rect.top) * (height / rect.height);
			const plotStart = geom.margin.left;
			const plotEnd = geom.margin.left + geom.plotWidth;
			if (rawX < plotStart || rawX > plotEnd || rawY < geom.margin.top
					|| rawY > geom.margin.top + geom.plotHeight) { return null; }
			const historyStart = geom.series[0].clock;
			const historyEnd = geom.series[geom.series.length - 1].clock;
			const rawTime = geom.tMin + (rawX - plotStart) / geom.plotWidth * geom.span;
			const time = Math.max(historyStart, Math.min(historyEnd, rawTime));
			const x = plotStart + (time - geom.tMin) / geom.span * geom.plotWidth;
			return {svg, x, time};
		}

		startDetailDrag(e, surface) {
			if (e.button !== 0 || !this.detailChartSpec) { return; }
			const pos = this.detailPointerPosition(e, surface);
			if (!pos) { return; }
			this.hideDetailHover();
			const brush = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
			brush.setAttribute('class', 'cap-chart-brush');
			brush.setAttribute('x', String(pos.x));
			brush.setAttribute('y', String(this.detailGeom.margin.top));
			brush.setAttribute('width', '0');
			brush.setAttribute('height', String(this.detailGeom.plotHeight));
			pos.svg.appendChild(brush);
			this.detailDrag = {pointerId: e.pointerId, startX: pos.x, currentX: pos.x,
				startTime: pos.time, currentTime: pos.time, brush};
			surface.setPointerCapture(e.pointerId);
			e.preventDefault();
		}

		updateDetailDrag(e, surface) {
			if (!this.detailDrag || e.pointerId !== this.detailDrag.pointerId) { return; }
			const pos = this.detailPointerPosition(e, surface);
			if (!pos) { return; }
			this.detailDrag.currentX = pos.x;
			this.detailDrag.currentTime = pos.time;
			this.detailDrag.brush.setAttribute('x', String(Math.min(this.detailDrag.startX, pos.x)));
			this.detailDrag.brush.setAttribute('width', String(Math.abs(pos.x - this.detailDrag.startX)));
			e.preventDefault();
		}

		finishDetailDrag(e, surface, cancelled = false) {
			const drag = this.detailDrag;
			if (!drag || e.pointerId !== drag.pointerId) { return; }
			if (surface.hasPointerCapture(e.pointerId)) { surface.releasePointerCapture(e.pointerId); }
			if (drag.brush.isConnected) { drag.brush.remove(); }
			this.detailDrag = null;
			if (cancelled || Math.abs(drag.currentX - drag.startX) < 12) { return; }
			const from = Math.min(drag.startTime, drag.currentTime);
			const to = Math.max(drag.startTime, drag.currentTime);
			const selected = this.detailChartSpec.series.filter((point) => point.clock >= from && point.clock <= to);
			if (selected.length < 2) { return; }
			this.detailZoom = {from: selected[0].clock, to: selected[selected.length - 1].clock};
			this.drawDetailChart();
			e.preventDefault();
		}

		// ---- pattern-aware "possible path" (display only) --------------------------
		// Detects a genuinely repeating usage pattern in the daily residuals and,
		// only when every statistical gate passes, returns a zero-mean cycle
		// template used to draw a SECOND, purely visual line alongside the
		// authoritative straight projection. Every computed number — ETAs, dates,
		// crossing markers, severities — still comes from the linear model alone.
		projectionPattern(spec, nowSec) {
			if (spec.__projPattern && spec.__projPattern.nowSec === nowSec) {
				return spec.__projPattern.result;
			}
			const result = this.computeProjectionPattern(spec, nowSec);
			spec.__projPattern = {nowSec, result};
			return result;
		}

		computeProjectionPattern(spec, nowSec) {
			const series = spec.series;
			if (!series || series.length < 21 || spec.slope == null || spec.currentValue == null) { return null; }
			const t0 = series[0].clock;
			const D = Math.round((series[series.length - 1].clock - t0) / 86400) + 1;
			if (D < 21 || D > 800) { return null; }

			// Daily grid of average values.
			const sums = new Array(D).fill(0);
			const counts = new Array(D).fill(0);
			const spacings = [];
			let prevDay = null;
			series.forEach((p) => {
				const d = Math.round((p.clock - t0) / 86400);
				if (d < 0 || d >= D) { return; }
				sums[d] += p.avg;
				counts[d]++;
				if (prevDay != null && d > prevDay) { spacings.push(d - prevDay); }
				prevDay = d;
			});
			const bin = new Array(D).fill(0);
			const mask = new Array(D).fill(false);
			let nValid = 0;
			let dFirst = null;
			let dLast = null;
			for (let d = 0; d < D; d++) {
				if (counts[d] > 0) {
					bin[d] = sums[d] / counts[d];
					mask[d] = true;
					nValid++;
					if (dFirst == null) { dFirst = d; }
					dLast = d;
				}
			}
			if (nValid < 21) { return null; }
			spacings.sort((a, b) => a - b);
			const medianSpacing = spacings.length ? spacings[Math.floor(spacings.length / 2)] : 1;

			// Residuals against the series' own least-squares line (period
			// detection is invariant to which linear detrend is used).
			let sx = 0;
			let sy = 0;
			let sxx = 0;
			let sxy = 0;
			for (let d = 0; d < D; d++) {
				if (!mask[d]) { continue; }
				sx += d; sy += bin[d]; sxx += d * d; sxy += d * bin[d];
			}
			const denom = nValid * sxx - sx * sx;
			const olsSlope = denom !== 0 ? (nValid * sxy - sx * sy) / denom : 0;
			const olsBase = (sy - olsSlope * sx) / nValid;
			const resid = new Array(D).fill(0);
			let residMean = 0;
			for (let d = 0; d < D; d++) {
				if (mask[d]) { resid[d] = bin[d] - (olsSlope * d + olsBase); residMean += resid[d]; }
			}
			residMean /= nValid;
			let sigma2 = 0;
			for (let d = 0; d < D; d++) {
				if (mask[d]) { resid[d] -= residMean; sigma2 += resid[d] * resid[d]; }
			}
			sigma2 /= nValid;
			if (sigma2 <= 0) { return null; }

			// Masked autocorrelation. Candidate periods need >= 3 observed cycles
			// (P <= D/3); lags run to 2D/3 so the harmonic check at 2P always has data.
			const lagMin = Math.max(2, Math.ceil(2 * medianSpacing));
			const periodMax = Math.floor(D / 3);
			const lagMax = Math.floor(2 * D / 3);
			if (periodMax < lagMin) { return null; }
			const rho = new Array(lagMax + 2).fill(null);
			for (let lag = lagMin; lag <= lagMax; lag++) {
				let m = 0;
				let s = 0;
				for (let d = 0; d + lag < D; d++) {
					if (mask[d] && mask[d + lag]) { s += resid[d] * resid[d + lag]; m++; }
				}
				if (m >= 12) { rho[lag] = Math.min(1, (s / m) / sigma2); }
			}
			let period = null;
			let bestRho = -Infinity;
			for (let lag = lagMin; lag <= periodMax; lag++) {
				if (rho[lag] != null && rho[lag] > bestRho) { bestRho = rho[lag]; period = lag; }
			}
			if (period == null) { return null; }
			// Prefer the fundamental when a divisor explains the signal as well
			// (e.g. a weekly cadence that also autocorrelates at 14 or 21 days).
			for (let d = lagMin; d < period; d++) {
				if (period % d !== 0 || rho[d] == null || rho[d] < 0.9 * rho[period]) { continue; }
				const dl = rho[d - 1];
				const dr = rho[d + 1];
				if ((dl == null || rho[d] > dl) && (dr == null || rho[d] >= dr)) { period = d; break; }
			}
			// Significance (3-sigma against the white-noise null, floored at 0.5),
			// true-peak shape, and mandatory harmonic confirmation at 2P.
			let pairCount = 0;
			for (let d = 0; d + period < D; d++) {
				if (mask[d] && mask[d + period]) { pairCount++; }
			}
			if (pairCount < 12 || rho[period] == null
					|| rho[period] < Math.max(0.5, 3 / Math.sqrt(pairCount))) { return null; }
			const leftRho = rho[period - 1];
			const rightRho = rho[period + 1];
			if ((leftRho != null && rho[period] <= leftRho)
					|| (rightRho != null && rho[period] < rightRho)) { return null; }
			if (rho[2 * period] == null || rho[2 * period] < 0.25) { return null; }
			if (dFirst == null || Math.floor((dLast - dFirst) / period) < 3) { return null; }

			// Amplitude stability over the last 3 complete cycles: each window must
			// be >= 60% filled and peak-to-trough amplitudes must not vary > 2.5x.
			const windowStart = dLast - 3 * period + 1;
			if (windowStart < 0) { return null; }
			let ampMin = Infinity;
			let ampMax = -Infinity;
			for (let c = 0; c < 3; c++) {
				const start = dLast - (c + 1) * period + 1;
				let filled = 0;
				let lo = Infinity;
				let hi = -Infinity;
				for (let d = start; d < start + period; d++) {
					if (d < 0 || !mask[d]) { continue; }
					filled++;
					if (resid[d] < lo) { lo = resid[d]; }
					if (resid[d] > hi) { hi = resid[d]; }
				}
				if (filled < 0.6 * period || hi <= lo) { return null; }
				const amp = hi - lo;
				if (amp < ampMin) { ampMin = amp; }
				if (amp > ampMax) { ampMax = amp; }
			}
			if (ampMin <= 0 || ampMax / ampMin > 2.5) { return null; }

			// Phase-anchored fold: index 0 is TODAY'S phase, so the forward
			// repetition continues seamlessly; per-phase medians over the last 3
			// complete cycles resist single anomalous days.
			const dNow = Math.round((nowSec - t0) / 86400);
			const phases = [];
			for (let k = 0; k < period; k++) { phases.push([]); }
			for (let d = windowStart; d <= dLast; d++) {
				if (d < 0 || !mask[d]) { continue; }
				phases[((d - dNow) % period + period) % period].push(resid[d]);
			}
			let empty = 0;
			const template = new Array(period).fill(null);
			for (let k = 0; k < period; k++) {
				if (!phases[k].length) { empty++; continue; }
				phases[k].sort((a, b) => a - b);
				const mid = Math.floor(phases[k].length / 2);
				template[k] = phases[k].length % 2 === 1
					? phases[k][mid]
					: (phases[k][mid - 1] + phases[k][mid]) / 2;
			}
			if (empty > 0.4 * period) { return null; }
			// Reject folds with a long contiguous run of empty phases, then fill
			// the short gaps by circular linear interpolation.
			const gapLimit = Math.max(2, period / 3);
			let run = 0;
			let maxRun = 0;
			for (let k = 0; k < 2 * period; k++) {
				if (template[k % period] == null) { run++; if (run > maxRun) { maxRun = run; } }
				else { run = 0; }
			}
			if (maxRun > gapLimit) { return null; }
			for (let k = 0; k < period; k++) {
				if (template[k] != null) { continue; }
				let back = 1;
				while (template[(k - back + period * 2) % period] == null) { back++; }
				let fwd = 1;
				while (template[(k + fwd) % period] == null) { fwd++; }
				const prev = template[(k - back + period * 2) % period];
				const next = template[(k + fwd) % period];
				template[k] = prev + (next - prev) * back / (back + fwd);
			}
			// One circular 3-point smoothing pass, then exact zero-mean (last step).
			const smoothed = new Array(period);
			for (let k = 0; k < period; k++) {
				smoothed[k] = (template[(k - 1 + period) % period] + template[k]
					+ template[(k + 1) % period]) / 3;
			}
			let mean = 0;
			for (let k = 0; k < period; k++) { mean += smoothed[k]; }
			mean /= period;
			for (let k = 0; k < period; k++) { smoothed[k] -= mean; }

			// The recurring shape must explain a meaningful share of the residual
			// variance — this is what separates a real sawtooth from noise.
			let explained = 0;
			for (let d = 0; d < D; d++) {
				if (!mask[d]) { continue; }
				const v = smoothed[((d - dNow) % period + period) % period];
				explained += v * v;
			}
			if (explained / nValid < 0.35 * sigma2) { return null; }

			let tplMin = Infinity;
			let tplMax = -Infinity;
			for (let k = 0; k < period; k++) {
				if (smoothed[k] < tplMin) { tplMin = smoothed[k]; }
				if (smoothed[k] > tplMax) { tplMax = smoothed[k]; }
			}
			if (tplMax - tplMin <= 0) { return null; }

			return {period, template: smoothed, app: tplMax - tplMin};
		}

		// Pattern value at dDays >= 0 after "today": circularly interpolated
		// template, amplitude constant for the first two projected cycles then
		// damped per whole cycle (each full drawn cycle stays exactly zero-mean
		// around the trend), plus a short cubic seam so the drawn path starts
		// exactly at the anchor point (nowSec, currentValue).
		projectionPatternValue(pat, dDays) {
			const period = pat.period;
			const phi = ((dDays % period) + period) % period;
			const idx = Math.floor(phi);
			const frac = phi - idx;
			const base = pat.template[idx % period] * (1 - frac) + pat.template[(idx + 1) % period] * frac;
			const cycle = Math.floor(dDays / period);
			const amp = cycle <= 1 ? 1 : Math.max(0.35, Math.exp(-(cycle - 2) / 4));
			const seamSpan = Math.min(period, 21);
			const seam = dDays < seamSpan
				? -pat.template[0] * Math.pow(1 - dDays / seamSpan, 3)
				: 0;
			return amp * base + seam;
		}

		buildUsageSvg(spec, palette, interactive) {
			const margin = {top: 26, right: 30, bottom: 64, left: 56};
			const height = 380;
			const plotHeight = height - margin.top - margin.bottom;
			const series = spec.series;
			// Anchor "today" and the projection to the server clock the ETAs were
			// computed from, so crossing markers agree with the printed ETA dates.
			const nowSec = Number.isFinite(this.fcGeneratedAt) && this.fcGeneratedAt > 0
				? this.fcGeneratedAt
				: Math.floor(Date.now() / 1000);

			const projected = spec.slope != null && spec.currentValue != null;
			const tMin = spec.viewFrom != null ? spec.viewFrom : series[0].clock;
			const tMax = spec.viewTo != null
				? spec.viewTo
				: (projected ? nowSec + HORIZON_DAYS * 86400 : Math.max(nowSec, series[series.length - 1].clock));
			const span = Math.max(86400, tMax - tMin);
			const plotWidth = Math.max(760, Math.min(1100, Math.round(span / 86400) * 2));
			const width = margin.left + plotWidth + margin.right;
			const x = (t) => margin.left + (t - tMin) / span * plotWidth;

			let vMax = 0;
			series.forEach((p) => { if (p.max > vMax) { vMax = p.max; } });
			spec.thresholds.forEach((t) => { if (t.value > vMax) { vMax = t.value; } });
			// The projection keeps its true slope: when it would leave the value
			// domain the LINE is truncated in time instead of bending the endpoint
			// (a clamped endpoint would flatten the slope and detach the threshold
			// crossing markers from the line).
			let projEnd = null;
			let projT = tMax;
			if (projected) {
				const raw = spec.currentValue + spec.slope * HORIZON_DAYS;
				projEnd = raw;
				if (spec.slope > 0 && spec.yMax != null && raw > spec.yMax) {
					projT = Math.max(nowSec, nowSec + (spec.yMax - spec.currentValue) / spec.slope * 86400);
					projEnd = spec.yMax;
				}
				else if (spec.slope < 0 && raw < 0) {
					projT = Math.max(nowSec, nowSec + (0 - spec.currentValue) / spec.slope * 86400);
					projEnd = 0;
				}
				projEnd = Math.max(0, spec.yMax != null ? Math.min(projEnd, spec.yMax) : projEnd);
				if (projEnd > vMax) { vMax = projEnd; }
			}
			if (spec.yMax != null) { vMax = Math.min(Math.max(vMax * 1.06, 10), spec.yMax); }
			else { vMax = Math.max(vMax * 1.1, 1); }
			const y = (v) => margin.top + plotHeight - (Math.max(0, Math.min(v, vMax)) / vMax) * plotHeight;

			let svg = this.createSvgOpenTag(width, height, spec.title);
			if (interactive) {
				svg += `<line class="cap-crosshair" x1="0" y1="${margin.top}" x2="0" y2="${margin.top + plotHeight}" stroke="${palette.crosshair}" stroke-width="1" stroke-dasharray="3 3" opacity="0" pointer-events="none" />`;
			}

			// Y grid + labels.
			this.buildValueTicks(vMax, 5).forEach((t) => {
				const ty = y(t);
				svg += `<line x1="${margin.left}" y1="${ty}" x2="${width - margin.right}" y2="${ty}" stroke="${palette.grid}" stroke-width="1" />`;
				svg += `<text x="${margin.left - 8}" y="${ty + 4}" font-size="11" text-anchor="end" fill="${palette.axisText}">${this.trimNum(t)}${spec.unit === '%' ? '%' : ''}</text>`;
			});
			svg += `<line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<text x="${margin.left}" y="${margin.top - 8}" font-size="12" font-weight="600" fill="${palette.title}">${this.escapeHtml(spec.title)}</text>`;

			// X labels (~8 ticks, date-rotated like the incident timeline).
			const tickCount = 8;
			for (let i = 0; i <= tickCount; i++) {
				const t = tMin + span * i / tickCount;
				const lx = x(t);
				const ly = margin.top + plotHeight + 14;
				svg += `<text x="${lx}" y="${ly}" font-size="10" fill="${palette.axisText}" transform="rotate(45 ${lx} ${ly})" text-anchor="start">${this.escapeHtml(this.fmtDate(t))}</text>`;
			}

			// Min–max band.
			if (series.length > 1) {
				const upper = series.map((p) => `${x(p.clock)},${y(p.max)}`);
				const lower = series.slice().reverse().map((p) => `${x(p.clock)},${y(p.min)}`);
				svg += `<polygon points="${upper.join(' ')} ${lower.join(' ')}" fill="${palette.band}" stroke="none" />`;
			}

			// Average line.
			const avgPoints = series.map((p) => `${x(p.clock)},${y(p.avg)}`);
			if (avgPoints.length > 1) {
				svg += `<polyline points="${avgPoints.join(' ')}" fill="none" stroke="${palette.line}" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" />`;
			}
			else {
				const [px, py] = avgPoints[0].split(',');
				svg += `<circle cx="${px}" cy="${py}" r="3.5" fill="${palette.line}" />`;
			}

			// Today divider.
			if (nowSec > tMin && nowSec < tMax) {
				const tx = x(nowSec);
				svg += `<line x1="${tx}" y1="${margin.top}" x2="${tx}" y2="${margin.top + plotHeight}" stroke="${palette.today}" stroke-width="1" stroke-dasharray="2 3" />`;
				svg += `<text x="${tx + 4}" y="${margin.top + 12}" font-size="10" fill="${palette.axisText}">today</text>`;
			}

			// Projection. The straight dashed line stays the authoritative model
			// (ETAs and crossing markers are computed from it); when history shows
			// a statistically confirmed repeating pattern, a second fainter line
			// additionally shows the possible path with that rhythm laid on top.
			let patternDrawn = false;
			if (projected) {
				const pat = this.projectionPattern(spec, nowSec);
				// Draw-time visibility floor: below ~3px peak-to-peak the pattern
				// would read as rendering noise rather than information.
				if (pat && pat.app / vMax * plotHeight >= 3) {
					const points = [];
					const lastWholeDay = Math.max(0, Math.floor((projT - nowSec) / 86400));
					for (let k = 0; k <= lastWholeDay; k++) {
						const t = nowSec + k * 86400;
						points.push(`${x(t)},${y(spec.currentValue + spec.slope * k + this.projectionPatternValue(pat, k))}`);
					}
					if (projT > nowSec + lastWholeDay * 86400) {
						const dDays = (projT - nowSec) / 86400;
						points.push(`${x(projT)},${y(spec.currentValue + spec.slope * dDays + this.projectionPatternValue(pat, dDays))}`);
					}
					svg += `<polyline points="${points.join(' ')}" fill="none" stroke="${palette.projection}" stroke-width="1.6" stroke-dasharray="2 4" opacity="0.65" stroke-linejoin="round" stroke-linecap="round" />`;
					patternDrawn = true;
				}
				svg += `<line x1="${x(nowSec)}" y1="${y(spec.currentValue)}" x2="${x(projT)}" y2="${y(projEnd)}" stroke="${palette.projection}" stroke-width="2.2" stroke-dasharray="6 5" stroke-linecap="round" />`;
				svg += `<circle cx="${x(nowSec)}" cy="${y(spec.currentValue)}" r="3.5" fill="${palette.projection}" />`;
			}

			// Threshold lines.
			spec.thresholds.forEach((t) => {
				const ty = y(t.value);
				const color = t.kind === 'crit' ? palette.crit : palette.warn;
				svg += `<line x1="${margin.left}" y1="${ty}" x2="${width - margin.right}" y2="${ty}" stroke="${color}" stroke-width="1.4" stroke-dasharray="7 4" />`;
				svg += `<text x="${width - margin.right - 4}" y="${ty - 4}" font-size="10" text-anchor="end" fill="${color}">${this.escapeHtml(t.label)}</text>`;
				// Crossing marker where the projection meets the threshold.
				if (projected && spec.slope > 0 && spec.currentValue < t.value && projEnd >= t.value) {
					const crossT = nowSec + (t.value - spec.currentValue) / spec.slope * 86400;
					if (crossT <= tMax) {
						svg += `<circle cx="${x(crossT)}" cy="${ty}" r="4.5" fill="${color}" stroke="#ffffff" stroke-width="1.2" />`;
					}
				}
			});

			svg += '</svg>';
			return {
				svg,
				geom: {margin, plotWidth, plotHeight, tMin, span, vbWidth: width, vbHeight: height,
					series, spec, nowSec, projEnd, tMax, patternDrawn}
			};
		}

		onDetailHover(e, surface) {
			const geom = this.detailGeom;
			const svg = surface.querySelector('svg');
			if (!geom || !svg || !this.detailEls || this.detailEls.surface !== surface) { return; }
			const rect = svg.getBoundingClientRect();
			if (!rect.width) { return; }
			const vb = svg.viewBox.baseVal;
			const vbWidth = (vb && vb.width) ? vb.width : rect.width;
			const userX = (e.clientX - rect.left) * (vbWidth / rect.width);
			const t = geom.tMin + (userX - geom.margin.left) / geom.plotWidth * geom.span;
			if (userX < geom.margin.left || userX > geom.margin.left + geom.plotWidth) {
				this.hideDetailHover();
				return;
			}

			if (this.detailEls.crosshair) {
				this.detailEls.crosshair.setAttribute('x1', String(userX));
				this.detailEls.crosshair.setAttribute('x2', String(userX));
				this.detailEls.crosshair.setAttribute('opacity', '1');
			}

			const spec = geom.spec;
			const unit = spec.unit === '%' ? '%' : spec.unit;
			let html = `<div class="cap-tt-title">${this.escapeHtml(this.fmtDate(t))}</div>`;
			if (t <= geom.nowSec && geom.series.length) {
				let nearest = geom.series[0];
				let bestDist = Math.abs(nearest.clock - t);
				geom.series.forEach((p) => {
					const dist = Math.abs(p.clock - t);
					if (dist < bestDist) { nearest = p; bestDist = dist; }
				});
				html += `<div class="cap-tt-row">Average<span class="cap-tt-val">${this.trimNum(nearest.avg)}${unit}</span></div>`;
				html += `<div class="cap-tt-row">Min – max<span class="cap-tt-val">${this.trimNum(nearest.min)} – ${this.trimNum(nearest.max)}${unit}</span></div>`;
			}
			else if (spec.slope != null && spec.currentValue != null) {
				let projected = spec.currentValue + spec.slope * (t - geom.nowSec) / 86400;
				if (spec.yMax != null) { projected = Math.min(projected, spec.yMax); }
				html += `<div class="cap-tt-row">Projected<span class="cap-tt-val">${this.trimNum(Math.max(0, projected))}${unit}</span></div>`;
			}
			this.showTooltip(e, html);
		}

		hideDetailHover() {
			this.tooltip.hidden = true;
			if (this.detailEls && this.detailEls.crosshair) {
				this.detailEls.crosshair.setAttribute('opacity', '0');
			}
		}

		// ---- exports ---------------------------------------------------------------
		exportCsv() {
			try {
				if (this.activeTab === 'disks') { this.exportDisksCsv(); }
				else if (RESOURCE_TABS.includes(this.activeTab)) { this.exportResourcesCsv(); }
				else { this.exportActionsCsv(); }
			}
			catch (err) {
				this.showError(err instanceof Error ? err.message : 'Failed to export CSV.');
			}
		}

		exportActionsCsv() {
			const rows = [['Severity', 'Category', 'Host', 'Resource', 'Current / last accepted',
				'Observation semantics', 'Maintenance / current state', 'Next threshold',
				'Critical threshold', 'Full', 'Confidence', 'Evidence', 'Action']];
			this.visibleDisks().forEach((d) => {
				const fc = this.fc.get(d.id);
				const reasons = this.findingReasons(d, fc);
				rows.push([this.severityOf(d), 'Filesystem', d.host, d.fs, this.fmtPct(d.pused),
					this.observationSemantics(d), this.currentStateExplanation(d, reasons),
					fc && fc.eta ? this.fmtDays(fc.eta.next_days) : '', fc && fc.eta ? this.fmtDays(fc.eta.crit_days) : '',
					fc && fc.eta ? this.fmtDays(fc.eta.full_days) : '', fc ? fc.confidence || '' : (d.current_severity ? 'Current state' : ''),
					reasons.join('; '), (fc && fc.recommendation) || d.current_recommendation || '']);
			});
			this.visibleResources().forEach((r) => {
				const fc = this.fc.get(r.id);
				const reasons = this.findingReasons(r, fc);
				rows.push([this.severityOf(r), r.rtype, r.host, r.metric || r.rtype, this.fmtPct(r.current),
					this.observationSemantics(r), this.currentStateExplanation(r, reasons),
					'', '', '', fc ? fc.confidence || '' : (r.current_severity ? 'Current state' : ''),
					reasons.join('; '), (fc && fc.recommendation) || r.current_recommendation || '']);
			});
			this.downloadCsv(rows, 'capacity-actions');
		}

		exportDisksCsv() {
			const rows = [['Severity', 'Host', 'OS', 'Filesystem', 'Kind', 'Reported total GiB', 'Usable GiB', 'Used GiB', 'Free GiB',
				'Used %', 'Observation semantics', 'Warn %', 'Critical %', 'Warn free GiB', 'Critical free GiB', 'Growth GiB/day',
				'Used percentage growth pp/day', 'Used-capacity model window', 'Used-percentage model window',
				'Used-percentage source', 'Used-percentage confidence', 'Direct percentage history',
				'Confidence', 'Accelerating', 'Days to warning', 'Warning date', 'Warning basis',
				'Days to critical', 'Critical date', 'Critical basis', 'Days to full', 'Full date', 'Next threshold basis',
				'Data status', 'Expected maintenance gap', 'Maintenance / current state', 'Analysis note',
				'Current-state evidence', 'Item key', 'Recommendation']];
			this.visibleDisks().map((d) => this.diskRow(d)).sort((a, b) => b.risk - a.risk).forEach((r) => {
				const d = r.d;
				const fc = r.fc;
				const gib = (v) => v != null ? (v / GIB).toFixed(2) : '';
				rows.push([r.sev, d.host, d.os, d.fs, d.kind, gib(d.total), gib(this.diskUsableCapacity(d)), gib(d.used), gib(d.free),
					d.pused != null ? d.pused.toFixed(2) : '', this.observationSemantics(d),
					d.warn_pct && d.warn_pct.v != null ? d.warn_pct.v : '',
					d.crit_pct && d.crit_pct.v != null ? d.crit_pct.v : '',
					d.warn_free && d.warn_free.v > 0 ? gib(d.warn_free.v) : '',
					d.crit_free && d.crit_free.v > 0 ? gib(d.crit_free.v) : '',
					fc && fc.growth_day != null ? (fc.growth_day / GIB).toFixed(4) : '',
					fc && fc.growth_pct_day != null ? Number(fc.growth_pct_day).toFixed(5) : '',
					fc ? this.selectedLabel(fc) : '', fc ? this.percentageSelectedLabel(fc) : '',
					fc ? fc.pct_source || '' : '', fc ? fc.pct_confidence || '' : '',
					fc && fc.pct_sel ? (fc.pct_series_direct ? 'Yes' : 'No') : '',
					fc ? fc.confidence || '' : '',
					fc && (fc.accelerating || fc.pct_accelerating) ? 'Yes' : 'No',
					fc && fc.eta && fc.eta.warn_days != null ? fc.eta.warn_days : '',
					fc && fc.eta && fc.eta.warn_date ? this.fmtIsoDate(fc.eta.warn_date) : '',
					fc && fc.eta ? fc.eta.warn_basis || '' : '',
					fc && fc.eta && fc.eta.crit_days != null ? fc.eta.crit_days : '',
					fc && fc.eta && fc.eta.crit_date ? this.fmtIsoDate(fc.eta.crit_date) : '',
					fc && fc.eta ? fc.eta.crit_basis || '' : '',
					fc && fc.eta && fc.eta.full_days != null ? fc.eta.full_days : '',
					fc && fc.eta && fc.eta.full_date ? this.fmtIsoDate(fc.eta.full_date) : '',
					fc && fc.eta ? fc.eta.next_basis || '' : '', d.status, d.expected_gap ? 'Yes' : 'No',
					this.maintenanceSummary(d), fc ? fc.note || '' : '', this.currentStateExplanation(d, r.reasons),
					d.item_key || '', (fc && fc.recommendation) || d.current_recommendation || '']);
			});
			this.downloadCsv(rows, 'capacity-filesystems');
		}

		exportResourcesCsv() {
			const rows = [['Severity', 'Baseline severity', 'Saturation severity', 'Analysis confidence',
				'Host', 'OS', 'Resource', 'Provisioned', 'Unit', 'Current %', 'Observation semantics',
				'Expected maintenance gap', 'Maintenance / current state', 'Window', 'Baseline coverage %',
				'Baseline source', 'Analysis note', 'Average %', 'p95 %',
				'Peak %', 'Above review %', 'Above alarm %', 'Confirmed episodes', 'Confirmed episode days',
				'Long episodes', 'Critical episodes', 'Longest episode minutes', 'Total episode minutes',
				'Ongoing episode minutes', 'Near-full peak observations', 'Near-full peak days',
				'Duration-unknown peaks', 'Saturation threshold %', 'Near-full threshold %',
				'Saturation window days', 'Saturation coverage %', 'Saturation source', 'Saturation confidence',
				'Saturation reason', 'Pre-change episodes', 'Pre-change episode days',
				'Pre-change longest episode minutes', 'Pre-change total episode minutes',
				'Pre-change saturation source', 'Pre-change saturation confidence', 'Pre-change saturation reason',
				'Regime detected', 'Regime direction', 'Regime change UTC',
				'Prior window days', 'Recent window days', 'Prior average %', 'Recent average %',
				'Delta percentage points', 'Relative change %', 'Prior coverage %', 'Recent coverage %',
				'Regime confidence', 'Regime reason', 'Evidence reasons', 'Current-state explanation', 'Review %', 'Alarm %',
				'Data status', 'Last value UTC', 'Item key', 'Recommendation']];
			this.visibleResources().map((r) => this.resourceRow(r)).sort((a, b) => b.risk - a.risk).forEach((row) => {
				const r = row.r;
				const fc = row.fc;
				const sel = this.selectedStats(fc);
				const saturation = row.saturation || {};
				const historical = row.historicalSaturation || {};
				const regime = row.regime || {};
				const num = (value, digits = 2) => value != null && isFinite(Number(value))
					? Number(value).toFixed(digits)
					: '';
				let provisioned = r.provisioned;
				let unit = r.unit || '';
				if (unit === 'bytes' && provisioned != null) { provisioned = (provisioned / GIB).toFixed(1); unit = 'GiB'; }
				rows.push([row.sev, row.baselineSeverity, row.saturationSeverity, row.confidence,
					r.host, r.os, r.rtype, provisioned != null ? provisioned : '', unit, num(r.current),
					this.observationSemantics(r), r.expected_gap ? 'Yes' : 'No', this.maintenanceSummary(r),
					fc ? this.selectedLabel(fc) : '', num(sel && sel.cov, 1), row.selectedSource, row.note,
					num(sel && sel.avg), num(sel && sel.p95), num(sel && sel.peak),
					num(sel && sel.above_warn), num(sel && sel.above_crit),
					saturation.confirmed_episode_count ?? '', saturation.confirmed_episode_days ?? '',
					saturation.confirmed_long_episode_count ?? '', saturation.confirmed_critical_episode_count ?? '',
					saturation.confirmed_longest_minutes ?? '', saturation.confirmed_total_minutes ?? '',
					saturation.confirmed_ongoing_minutes ?? '', saturation.max_observation_count ?? '',
					saturation.max_observation_days ?? '', saturation.duration_unknown_max_count ?? '',
					num(saturation.threshold_pct), num(saturation.near_full_threshold_pct),
					saturation.window_days ?? '', num(saturation.coverage_pct), saturation.source || '',
					saturation.confidence || '', saturation.reason || '', historical.confirmed_episode_count ?? '',
					historical.confirmed_episode_days ?? '', historical.confirmed_longest_minutes ?? '',
					historical.confirmed_total_minutes ?? '', historical.source || '', historical.confidence || '',
					historical.reason || '',
					regime.detected == null ? '' : (regime.detected ? 'Yes' : 'No'),
					regime.direction || '', regime.change_clock ? this.fmtIsoDate(regime.change_clock) : '',
					regime.prior_days ?? '', regime.recent_days ?? '', num(regime.prior_average),
					num(regime.recent_average), num(regime.delta_pct_points), num(regime.relative_change_pct),
					num(regime.prior_coverage_pct), num(regime.recent_coverage_pct), regime.confidence || '',
					regime.reason || '', row.reasons.join('; '), this.currentStateExplanation(r, row.reasons),
					r.warn && r.warn.v != null ? r.warn.v : '',
					r.crit && r.crit.v != null ? r.crit.v : '', r.status,
					r.lastclock ? new Date(Number(r.lastclock) * 1000).toISOString() : '', r.item_key || '',
					row.action]);
			});
			const prefix = this.activeTab === 'cpu'
				? 'capacity-cpu'
				: (this.activeTab === 'memory' ? 'capacity-memory' : 'capacity-resources');
			this.downloadCsv(rows, prefix);
		}

		downloadCsv(rows, prefix) {
			const csv = rows.map((r) => r.map((v) => this.csvEscape(v)).join(',')).join('\n');
			this.downloadBlob(new Blob([csv], {type: 'text/csv;charset=utf-8;'}), this.buildFileName(prefix, 'csv'));
		}

		buildReportHtml() {
			const metaText = this.escapeHtml(this.elements.meta.textContent || '');
			const filterText = this.escapeHtml([this.elements.filterSummary.textContent || '',
				this.elements.resultsSummary.textContent || ''].filter(Boolean).join(' · '));
			const counts = this.riskCounts();
			const visibleDisks = this.visibleDisks();
			const visibleResources = this.visibleResources();
			const visibleHosts = new Set([...visibleDisks, ...visibleResources].map((finding) => String(finding.hostid)));
			const cardsHtml = `<div class="cards">${[
				[`${visibleHosts.size}`, 'Servers displayed'],
				[`${visibleDisks.length}`, 'Filesystems displayed'],
				[`${visibleResources.length}`, 'CPU / memory displayed'],
				[`${counts.Critical + counts.High + counts.Medium}`, 'Capacity actions'],
				[`${counts.Critical}`, 'Critical risks']
			].map(([v, l]) => `<div class="stat"><div class="v">${v}</div><div class="l">${l}</div></div>`).join('')}</div>`;

			const runwaySvg = this.buildRunwaySvg(this.runwayRows(), PALETTE_LIGHT, false);
			const distSvg = this.buildDistributionSvg(counts, PALETTE_LIGHT);

			const diskRows = this.visibleDisks().map((d) => this.diskRow(d)).sort((a, b) => b.risk - a.risk).slice(0, 500);
			const diskTable = diskRows.length ? `<div class="table-scroll"><table><thead><tr><th>Risk</th><th>Host</th><th>Filesystem</th><th>Current / last accepted used</th><th>Current / last accepted free</th><th>Usable capacity</th><th>Growth (capacity / percentage)</th><th>Model evidence (capacity / percentage)</th><th>Warning ETA / basis</th><th>Critical ETA / basis</th><th>Full ETA</th><th>Confidence</th><th>Data status</th><th>Maintenance</th><th>Current-state explanation</th><th>Evidence / note</th><th>Assessment</th></tr></thead><tbody>${
				diskRows.map((r) => `<tr><td>${this.riskPill(r.sev)}</td><td>${this.escapeHtml(r.host)}${this.maintenanceBadgeHtml(r.d)}</td><td>${this.escapeHtml(r.fs)}</td><td>${this.escapeHtml(this.currentObservationText(r.d, this.fmtPct(r.pused)))}</td><td>${this.escapeHtml(this.currentObservationText(r.d, this.fmtBytes(r.free)))}</td><td>${this.fmtBytes(r.usable)}</td><td>${r.growth != null ? this.fmtBytes(r.growth) + '/day' : '—'} / ${r.growthPct != null ? `${r.growthPct >= 0 ? '+' : ''}${this.trimNum(r.growthPct)} pp/day` : '—'}</td><td>${this.escapeHtml(`${r.modelWindow || '—'} / ${r.pctWindow || '—'} · ${r.pctSource || '—'}${r.pctWindow && r.pctWindow !== 'n/a' ? (r.pctSeriesDirect ? ' (direct)' : ' (derived)') : ''}`)}</td><td>${this.fmtDays(r.warn)}${r.warnBasis ? ` / ${this.escapeHtml(r.warnBasis)}` : ''}</td><td>${this.fmtDays(r.crit)}${r.critBasis ? ` / ${this.escapeHtml(r.critBasis)}` : ''}</td><td>${this.fmtDays(r.full)}</td><td>${this.escapeHtml(r.conf || '—')}</td><td>${this.escapeHtml(r.status || '—')}</td><td>${this.escapeHtml(this.maintenanceSummary(r.d) || '—')}</td><td>${this.escapeHtml(this.currentStateExplanation(r.d, r.reasons) || '—')}</td><td>${this.escapeHtml([r.reasons.join('; '), r.note].filter(Boolean).join(' · ') || '—')}</td><td>${this.escapeHtml(r.action || '—')}</td></tr>`).join('')
			}</tbody></table></div>` : '<p>No filesystem findings.</p>';

			const resRows = this.visibleResources().map((r) => this.resourceRow(r)).sort((a, b) => b.risk - a.risk).slice(0, 500);
			const resTable = resRows.length ? `<div class="table-scroll"><table class="resource-table"><thead><tr><th>Risk</th><th>Baseline / saturation</th><th>Host</th><th>Resource</th><th>Window / source</th><th>Current / last accepted</th><th>Maintenance</th><th>Current-state explanation</th><th>Avg</th><th>p95</th><th>Peak</th><th>Above review</th><th>Above alarm</th><th>Confirmed episodes / days</th><th>Longest</th><th>Total duration</th><th>Near-full peaks / days</th><th>Confidence</th><th>Regime</th><th>Pre-change saturation</th><th>Evidence / note</th><th>Assessment</th></tr></thead><tbody>${
				resRows.map((row) => `<tr><td>${this.riskPill(row.sev)}</td><td>${this.escapeHtml(row.baselineSeverity || '—')} / ${this.escapeHtml(row.saturationSeverity || '—')}</td><td>${this.escapeHtml(row.host)}${this.maintenanceBadgeHtml(row.r)}</td><td>${this.escapeHtml(row.rtype)}</td><td>${this.escapeHtml(row.window || '—')} / ${this.escapeHtml(row.selectedSource || '—')}</td><td>${this.escapeHtml(this.currentObservationText(row.r, this.fmtPct(row.current)))}</td><td>${this.escapeHtml(this.maintenanceSummary(row.r) || '—')}</td><td>${this.escapeHtml(this.currentStateExplanation(row.r, row.reasons) || '—')}</td><td>${this.fmtPct(row.avg)}</td><td>${this.fmtPct(row.p95)}</td><td>${this.fmtPct(row.peak)}</td><td>${this.fmtPct(row.aboveWarn)}</td><td>${this.fmtPct(row.aboveCrit)}</td><td>${this.countDaysLabel(row.episodes, row.episodeDays)}</td><td>${this.fmtDurationMinutes(row.longest)}</td><td>${this.fmtDurationMinutes(row.totalDuration)}</td><td>${this.countDaysLabel(row.nearFullCount, row.nearFullDays)}</td><td>${this.escapeHtml(row.confidence || '—')}</td><td>${this.escapeHtml(this.regimeSummary(row.regime) || '—')}</td><td>${this.escapeHtml(this.historicalSaturationSummary(row.historicalSaturation) || '—')}</td><td>${this.escapeHtml([row.reasons.join('; '), row.note].filter(Boolean).join(' · ') || '—')}</td><td>${this.escapeHtml(row.action || '—')}</td></tr>`).join('')
			}</tbody></table></div>` : '<p>No CPU/memory findings.</p>';

			return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Capacity Planning Report</title>
				<style>
				body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#1f2b3a;}
				h1{margin:0 0 6px;} .meta,.filter{color:#5c6b7a;font-size:13px;margin:0 0 6px;}
				.card{border:1px solid #dfe4ec;border-radius:6px;padding:16px;margin:16px 0;}
				.cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;}
				.stat{border:1px solid #dfe4ec;border-radius:6px;padding:12px 22px;text-align:center;min-width:120px;}
				.stat .v{font-size:24px;font-weight:700;} .stat .l{font-size:10px;color:#5c6b7a;text-transform:uppercase;letter-spacing:0.06em;margin-top:4px;}
				table{width:100%;border-collapse:collapse;} th,td{border-bottom:1px solid #e7edf4;padding:8px 10px;text-align:left;font-size:13px;}
				th{font-weight:700;} .table-scroll{overflow-x:auto}.resource-table{min-width:2200px} svg{max-width:100%;height:auto;}
				.cap-risk-pill{display:inline-block;min-width:62px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;text-align:center;color:#fff;}
				.cap-risk-pill.risk-watch{color:#101828;}
				.cap-maintenance-badge{display:inline-block;margin-left:6px;padding:1px 6px;border:1px solid #f79009;border-radius:999px;background:#fff4e5;color:#934c00;font-size:10px;font-weight:700;white-space:nowrap;}
				.risk-critical{background:#b42318}.risk-high{background:#d92d20}.risk-medium{background:#f79009}.risk-watch{background:#fec84b}.risk-healthy{background:#12b76a}.risk-unknown{background:#667085}
				</style></head><body>
				<h1>Capacity Planning &amp; Prediction Report</h1>
				<div class="meta">${metaText}</div>${filterText ? `<div class="filter">${filterText}</div>` : ''}
				${cardsHtml}
				<div class="card"><h2>Capacity runway</h2>${runwaySvg}</div>
				<div class="card"><h2>Risk distribution</h2>${distSvg}</div>
				<div class="card"><h2>Filesystem capacity forecast</h2>${diskTable}</div>
				<div class="card"><h2>CPU and memory capacity evidence</h2>${resTable}</div>
				<div class="meta">Forecast ETAs are projected threshold crossings based on robust growth trends — planning estimates, not guarantees. Generated ${this.escapeHtml(new Date().toISOString())}</div>
				</body></html>`;
		}

		exportHtml() {
			try {
				this.downloadBlob(new Blob([this.buildReportHtml()], {type: 'text/html;charset=utf-8;'}),
					this.buildFileName('capacity-planning', 'html'));
			}
			catch (err) {
				this.showError(err instanceof Error ? err.message : 'Failed to export HTML.');
			}
		}

		async exportPng() {
			let svgs = [];
			if (this.activeTab !== 'overview' && this.selected && this.detailGeom) {
				const finding = this.inv.byId.get(this.selected.id);
				const fc = this.fc.get(this.selected.id);
				if (finding && fc && fc.status === 'ok') {
					const spec = this.selected.kind === 'disk'
						? this.diskChartSpec(finding, fc)
						: null;
					if (spec) {
						svgs.push(this.buildUsageSvg(spec, PALETTE_LIGHT, false).svg);
					}
					else {
						const thresholds = [];
						if (finding.warn && finding.warn.v != null) { thresholds.push({value: finding.warn.v, label: `Review ${this.trimNum(finding.warn.v)}%`, kind: 'warn'}); }
						if (finding.crit && finding.crit.v != null) { thresholds.push({value: finding.crit.v, label: `Alarm ${this.trimNum(finding.crit.v)}%`, kind: 'crit'}); }
						svgs.push(this.buildUsageSvg({
							title: `${finding.rtype} utilization (%)`, unit: '%', yMax: 100,
							series: (fc.series || []).map((p) => ({clock: p[0], min: p[1], avg: p[2], max: p[3]})),
							currentValue: this.currentObservationUsable(finding) ? finding.current : null,
							slope: null, thresholds,
							projectionLabel: ''
						}, PALETTE_LIGHT, false).svg);
					}
				}
			}
			if (!svgs.length) {
				const runway = this.buildRunwaySvg(this.runwayRows(), PALETTE_LIGHT, false);
				if (runway.startsWith('<svg')) { svgs.push(runway); }
				svgs.push(this.buildDistributionSvg(this.riskCounts(), PALETTE_LIGHT));
			}
			if (!svgs.length) { throw new Error('Nothing is available to export as PNG.'); }

			const images = await Promise.all(svgs.map((s) => this.svgStringToImage(s)));
			const canvasWidth = Math.max(900, ...images.map((i) => i.width));
			const canvasHeight = 80 + images.reduce((sum, i) => sum + i.height + 24, 0);
			const canvas = document.createElement('canvas');
			canvas.width = canvasWidth;
			canvas.height = canvasHeight;
			const ctx = canvas.getContext('2d');
			if (!ctx) { throw new Error('Failed to create a canvas for PNG export.'); }
			ctx.fillStyle = '#ffffff';
			ctx.fillRect(0, 0, canvas.width, canvas.height);
			ctx.fillStyle = '#1f2b3a';
			ctx.font = 'bold 24px Arial';
			ctx.fillText('Capacity Planning Report', 20, 34);
			ctx.font = '13px Arial';
			ctx.fillStyle = '#5c6b7a';
			ctx.fillText(this.elements.meta.textContent || '', 20, 56);
			let yPos = 80;
			images.forEach((img) => {
				ctx.drawImage(img.image, 0, yPos, img.width, img.height);
				yPos += img.height + 24;
			});
			const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
			if (!blob) { throw new Error('Failed to render the PNG file.'); }
			this.downloadBlob(blob, this.buildFileName('capacity-planning', 'png'));
		}

		svgStringToImage(svgText) {
			return new Promise((resolve, reject) => {
				const m = svgText.match(/viewBox="0 0 (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)"/);
				const width = m ? Number(m[1]) : 800;
				const height = m ? Number(m[2]) : 400;
				const blob = new Blob([svgText], {type: 'image/svg+xml;charset=utf-8'});
				const objectUrl = URL.createObjectURL(blob);
				const image = new Image();
				image.onload = () => { URL.revokeObjectURL(objectUrl); resolve({image, width, height}); };
				image.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Failed to render SVG for PNG export.')); };
				image.src = objectUrl;
			});
		}

		updateExportState() {
			const ready = this.inv.ready && this.activeTab !== 'settings';
			this.elements.exportCsv.disabled = !ready;
			this.elements.exportHtml.disabled = !ready;
			this.elements.exportPng.disabled = !ready;
		}

		// ---- misc ------------------------------------------------------------------
		riskPill(sev) {
			if (sev === 'Pending') {
				return '<span class="cap-muted">…</span>';
			}
			return `<span class="cap-risk-pill risk-${String(sev).toLowerCase()}">${this.escapeHtml(sev)}</span>`;
		}

		etaCell(days, label) {
			if (days == null) { return `<span class="cap-muted">${this.escapeHtml(label || 'Not projected')}</span>`; }
			const cls = days <= 30 ? 'cap-eta-soon' : (days <= 90 ? 'cap-eta-near' : '');
			return cls ? `<span class="${cls}">${this.escapeHtml(label)}</span>` : this.escapeHtml(label);
		}

		buildValueTicks(maxValue, approxCount) {
			if (maxValue <= 0) { return [0]; }
			const rawStep = maxValue / Math.max(1, approxCount);
			const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
			let step = magnitude;
			if (rawStep / magnitude > 5) { step = 10 * magnitude; }
			else if (rawStep / magnitude > 2) { step = 5 * magnitude; }
			else if (rawStep / magnitude > 1) { step = 2 * magnitude; }
			const ticks = [];
			for (let v = 0; v <= maxValue + step * 0.001; v += step) { ticks.push(Number(v.toFixed(6))); }
			return ticks;
		}

		createSvgOpenTag(width, height, label) {
			return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="${this.escapeHtml(label || 'Capacity chart')}">`;
		}

		setLoading(isLoading, message) {
			this.isLoading = !!isLoading;
			this.elements.loading.hidden = !isLoading;
			if (isLoading && message) { this.elements.loading.textContent = message; }
		}

		showError(message) {
			this.elements.error.hidden = false;
			this.elements.error.className = 'cap-error';
			this.elements.error.textContent = message;
		}

		clearMessages() {
			this.elements.error.hidden = true;
			this.elements.error.className = '';
			this.elements.error.textContent = '';
			this.renderWarning('');
		}

		renderWarning(message) {
			if (!message) {
				this.elements.warning.hidden = true;
				this.elements.warning.className = '';
				this.elements.warning.textContent = '';
				return;
			}
			this.elements.warning.hidden = false;
			this.elements.warning.className = 'cap-warning';
			this.elements.warning.textContent = message;
		}

		showTooltip(e, html) {
			this.tooltip.innerHTML = html;
			this.tooltip.hidden = false;
			const pad = 14;
			const w = this.tooltip.offsetWidth;
			const h = this.tooltip.offsetHeight;
			let x = e.clientX + pad;
			let y = e.clientY + pad;
			if (x + w > window.innerWidth - 8) { x = e.clientX - w - pad; }
			if (y + h > window.innerHeight - 8) { y = e.clientY - h - pad; }
			this.tooltip.style.left = `${Math.max(8, x)}px`;
			this.tooltip.style.top = `${Math.max(8, y)}px`;
		}

		updateLocationState() {
			const url = new URL(window.location.href);
			const sp = url.searchParams;
			sp.set('action', 'capacity.planning.view');
			sp.set('lookback', String(this.lookbackDays));
			sp.set('tab', this.activeTab);
			const setOrDel = (k, v) => { if (v) { sp.set(k, v); } else { sp.delete(k); } };
			setOrDel('group', this.filters.group);
			setOrDel('host', this.filters.host);
			setOrDel('template', this.filters.template);
			setOrDel('name', this.filters.name);
			setOrDel('result_group', this.resultFilters.group);
			setOrDel('result_host', this.resultFilters.hostid);
			setOrDel('type', this.resultFilters.type);
			setOrDel('status', this.resultFilters.status);
			setOrDel('rows', this.pageSize !== 25 ? String(this.pageSize) : '');
			setOrDel('risks', this.activeRisks.size === RISKS.length
				? ''
				: (this.activeRisks.size
					? RISKS.map((r) => r.key).filter((k) => this.activeRisks.has(k)).join(',')
					: 'none'));
			window.history.replaceState({}, '', url.toString());
		}

		csvEscape(value) {
			// Neutralize spreadsheet formula injection: host/filesystem names can start
			// with = + - @ (or tab/CR) and would execute as formulas.
			let s = String(value ?? '');
			if (/^[=+\-@\t\r]/.test(s)) { s = `'${s}`; }
			return `"${s.replace(/"/g, '""')}"`;
		}

		downloadBlob(blob, fileName) {
			const link = document.createElement('a');
			const url = URL.createObjectURL(blob);
			link.href = url;
			link.download = fileName;
			link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		}

		buildFileName(prefix, ext) {
			const d = new Date();
			const pad = (n) => String(n).padStart(2, '0');
			return `${prefix}-${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}.${ext}`;
		}

		fmtPct(v) {
			return v == null || !isFinite(v) ? 'N/A' : `${Number(v).toFixed(1)}%`;
		}

		fmtCount(v) {
			return v == null || !isFinite(v) ? 'N/A' : String(Math.max(0, Math.round(Number(v))));
		}

		fmtDurationMinutes(v) {
			if (v == null || !isFinite(v)) { return 'N/A'; }
			const minutes = Math.max(0, Number(v));
			if (minutes < 60) { return `${Math.round(minutes)} min`; }
			if (minutes < 1440) { return `${this.trimNum(minutes / 60)} h`; }
			return `${this.trimNum(minutes / 1440)} d`;
		}

		fmtBytes(v) {
			if (v == null || !isFinite(v)) { return 'N/A'; }
			const abs = Math.abs(v);
			if (abs >= GIB * 1024) { return `${(v / (GIB * 1024)).toFixed(2)} TiB`; }
			if (abs >= GIB) { return `${(v / GIB).toFixed(1)} GiB`; }
			if (abs >= 1048576) { return `${(v / 1048576).toFixed(1)} MiB`; }
			if (abs >= 1024) { return `${(v / 1024).toFixed(1)} KiB`; }
			return `${Math.round(v)} B`;
		}

		fmtDays(v) {
			if (v == null || !isFinite(v)) { return 'Not projected'; }
			if (v <= 0) { return 'Now'; }
			if (v < 1) { return `${Math.round(v * 24)} hours`; }
			if (v < 60) { return `${Math.round(v)} days`; }
			if (v < 730) { return `${(v / 30.44).toFixed(1)} months`; }
			if (v <= 3650) { return `${(v / 365.25).toFixed(1)} years`; }
			return '>10 years';
		}

		fmtDaysShort(v) {
			if (v == null) { return ''; }
			if (v <= 0) { return 'now'; }
			if (v < 60) { return `${Math.round(v)}d`; }
			return `${(v / 30.44).toFixed(1)}mo`;
		}

		fmtDate(epoch) {
			return new Date(Number(epoch) * 1000).toLocaleDateString(undefined,
				{year: 'numeric', month: 'short', day: '2-digit', timeZone: 'UTC'});
		}

		fmtDateTime(epoch) {
			return `${new Date(Number(epoch) * 1000).toISOString().slice(0, 16).replace('T', ' ')} UTC`;
		}

		fmtIsoDate(epoch) {
			return new Date(Number(epoch) * 1000).toISOString().slice(0, 10);
		}

		trimNum(v) {
			if (v == null || !isFinite(v)) { return 'N/A'; }
			const n = Number(v);
			if (Math.abs(n) >= 100) { return String(Math.round(n)); }
			if (Math.abs(n) >= 10) { return n.toFixed(1).replace(/\.0$/, ''); }
			return n.toFixed(2).replace(/\.?0+$/, '') || '0';
		}

		escapeHtml(value) {
			return String(value).replace(/[&<>"']/g, (c) => HTML_ESCAPES[c]);
		}
	}
})();
