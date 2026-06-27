(function () {
	'use strict';

	const SEVERITIES = [
		{key: 0, label: 'Not classified', color: '#97AAB3'},
		{key: 1, label: 'Information', color: '#7499FF'},
		{key: 2, label: 'Warning', color: '#FFC859'},
		{key: 3, label: 'Average', color: '#FFA059'},
		{key: 4, label: 'High', color: '#E97659'},
		{key: 5, label: 'Disaster', color: '#E45959'}
	];
	const SEV_BY_KEY = Object.fromEntries(SEVERITIES.map((s) => [s.key, s]));
	// Highest severity first — the order incidents load progressively.
	const SEV_DESC = SEVERITIES.map((s) => s.key).slice().reverse();

	const PRESETS = [{months: 1, label: '1M'}, {months: 3, label: '3M'}, {months: 6, label: '6M'}, {months: 12, label: '12M'}];

	const PALETTE_LIGHT = {
		grid: '#d7e1ec', axis: '#8695a5', axisText: '#5c6b7a', title: '#34485f',
		highlight: 'rgba(15,106,216,0.10)', brush: 'rgba(15,106,216,0.18)', brushStroke: '#0f6ad8'
	};
	const PALETTE_DARK = {
		grid: '#33425a', axis: '#5b6b7f', axisText: '#9fb0c3', title: '#dbe6f0',
		highlight: 'rgba(116,153,255,0.16)', brush: 'rgba(116,153,255,0.22)', brushStroke: '#7499FF'
	};

	const CLICK_THRESHOLD_PX = 5;
	const FREQ_UNITS = [
		['sec', 1], ['min', 60], ['hour', 3600], ['day', 86400],
		['week', 604800], ['month', 2592000], ['year', 31536000]
	];

	document.addEventListener('DOMContentLoaded', () => {
		const root = document.getElementById('incident-timeline-root');
		if (root) {
			new IncidentTimelineApp(root).init();
		}
	});

	class IncidentTimelineApp {
		constructor(root) {
			this.root = root;
			this.dataUrl = root.dataset.dataUrl || 'zabbix.php?action=incident.timeline.data';
			this.maxRangeDays = 768;

			this.bucket = 'auto';
			this.activeTab = 'timeline';
			this.filters = {group: '', host: '', template: '', name: '', nameRegex: false};
			this.resolved = null; // {fsig, groupids, hostids, empty, summary}
			this.activeSeverities = new Set(SEVERITIES.map((s) => s.key));
			this.elements = {};

			// Timeline data (progressive, per severity).
			this.tl = this.emptyTimelineState();
			// Top-triggers data.
			this.top = {sig: null, rows: [], meta: null, sort: {key: 'count', dir: 'desc'}, ready: false};

			this.timelineGeom = null;
			this.trendGeom = null;

			this.loadSeq = 0;
			this.loadAbort = null;
			this.csvAbort = null;
			this.drillAbort = null;
			this.drillCache = new Map();

			this.brush = null;
			this.preZoom = null;

			this.onPointerMove = this.onPointerMove.bind(this);
			this.onPointerUp = this.onPointerUp.bind(this);
		}

		emptyTimelineState() {
			return {sig: null, skeleton: null, sev: {}, totals: {}, loaded: new Set(), inflight: new Set(),
				granularity: 'day', granularityClamped: false, limitReached: false, ready: false};
		}

		// ---- date helpers (UTC) ----------------------------------------------------
		static pad(n) { return String(n).padStart(2, '0'); }

		static dateInputToEpoch(value, endOfDay) {
			const p = String(value || '').split('-').map(Number);
			if (p.length !== 3 || !p[0] || !p[1] || !p[2]) {
				return null;
			}
			const ms = endOfDay ? Date.UTC(p[0], p[1] - 1, p[2], 23, 59, 59) : Date.UTC(p[0], p[1] - 1, p[2], 0, 0, 0);
			return Math.floor(ms / 1000);
		}

		static epochToDateInput(epoch) {
			const d = new Date(epoch * 1000);
			return `${d.getUTCFullYear()}-${IncidentTimelineApp.pad(d.getUTCMonth() + 1)}-${IncidentTimelineApp.pad(d.getUTCDate())}`;
		}

		static todayUtcDate() {
			const n = new Date();
			return new Date(Date.UTC(n.getUTCFullYear(), n.getUTCMonth(), n.getUTCDate()));
		}

		// ---- lifecycle -------------------------------------------------------------
		init() {
			this.renderShell();
			this.bindEvents();
			this.applyInitialState();
			this.loadActiveTab();
		}

		getPalette() {
			const themed = this.root.closest('[theme]');
			const theme = themed ? themed.getAttribute('theme') : '';
			return (theme === 'dark-theme' || theme === 'hc-dark') ? PALETTE_DARK : PALETTE_LIGHT;
		}

		renderShell() {
			this.root.innerHTML = `
				<div class="incident-shell">
					<div class="incident-toolbar">
						<div class="incident-toolbar-group">
							<div class="incident-field">
								<label>Quick range</label>
								<div class="incident-presets" data-role="presets">
									${PRESETS.map((p) => `<button type="button" class="incident-btn incident-preset-btn" data-preset="${p.months}">${p.label}</button>`).join('')}
								</div>
							</div>
							<div class="incident-field"><label for="incident-from">From</label><input type="date" id="incident-from" data-role="from"></div>
							<div class="incident-field"><label for="incident-to">To</label><input type="date" id="incident-to" data-role="to"></div>
							<div class="incident-field">
								<label>Shift</label>
								<div class="incident-month-nav">
									<button type="button" class="incident-btn incident-nav-btn" data-role="prev" title="Previous window">&lsaquo;</button>
									<button type="button" class="incident-btn incident-nav-btn" data-role="next" title="Next window">&rsaquo;</button>
								</div>
							</div>
							<div class="incident-field">
								<label>Granularity</label>
								<div class="incident-seg" data-role="granularity">
									<button type="button" class="incident-seg-btn is-active" data-bucket="auto">Auto</button>
									<button type="button" class="incident-seg-btn" data-bucket="day">Day</button>
									<button type="button" class="incident-seg-btn" data-bucket="week">Week</button>
									<button type="button" class="incident-seg-btn" data-bucket="month">Month</button>
								</div>
							</div>
						</div>
						<div class="incident-actions">
							<button type="button" class="incident-btn incident-btn-primary" data-role="reset-zoom" hidden>Reset zoom</button>
							<button type="button" class="incident-btn" data-role="export-png">Export PNG</button>
							<button type="button" class="incident-btn" data-role="export-pdf">Export PDF</button>
							<button type="button" class="incident-btn" data-role="export-html">Export HTML</button>
							<button type="button" class="incident-btn" data-role="export-csv">Export CSV</button>
						</div>
					</div>

					<div class="incident-filterbar">
						<div class="incident-field"><label for="f-group">Host group</label><input type="text" id="f-group" data-filter="group" placeholder="e.g. Databases"></div>
						<div class="incident-field"><label for="f-host">Host</label><input type="text" id="f-host" data-filter="host" placeholder="e.g. db01"></div>
						<div class="incident-field"><label for="f-template">Template</label><input type="text" id="f-template" data-filter="template" placeholder="e.g. Linux by Zabbix agent"></div>
						<div class="incident-field incident-field-grow"><label for="f-name">Incident name</label><input type="text" id="f-name" data-filter="name" placeholder="e.g. MSSQL or CPU"></div>
						<label class="incident-filter-check incident-regex-check"><input type="checkbox" data-filter="nameRegex"> Regex</label>
						<div class="incident-filter-actions">
							<button type="button" class="incident-btn incident-btn-primary" data-role="apply-filters">Apply</button>
							<button type="button" class="incident-btn" data-role="clear-filters">Clear</button>
						</div>
						<div class="incident-filter-summary" data-role="filter-summary"></div>
					</div>

					<div class="incident-severity-filter" data-role="severity-filter">
						<span class="incident-severity-filter-label">Severity filter</span>
						<div class="incident-severity-filter-options">
							${SEVERITIES.map((s) => `<label class="incident-filter-check"><input type="checkbox" value="${s.key}" checked><span class="incident-badge sev-${s.key}"></span>${this.escapeHtml(s.label)}</label>`).join('')}
						</div>
					</div>

					<div class="incident-tabs" data-role="tabs">
						<button type="button" class="incident-tab is-active" data-tab="timeline">Timeline</button>
						<button type="button" class="incident-tab" data-tab="top">Top triggers</button>
					</div>

					<div class="incident-meta" data-role="meta"></div>
					<div data-role="warning" hidden></div>
					<div data-role="error" hidden></div>
					<div class="incident-loading" data-role="loading">Loading…</div>

					<div class="incident-tabpanel" data-panel="timeline">
						<div class="incident-grid">
							<div class="incident-card">
								<h3>Incidents over time</h3>
								<p class="incident-card-subtitle" data-role="timeline-subtitle">Trigger problem events grouped by severity. Drag to zoom, click a bar for details.</p>
								<div class="incident-legend" data-role="legend"></div>
								<div class="incident-chart-surface" data-role="timeline-surface"></div>
							</div>
							<div class="incident-card">
								<h3>Incidents by severity</h3>
								<p class="incident-card-subtitle">Total number of incidents for the selected range.</p>
								<div class="incident-chart-fit" data-role="severity-surface"></div>
							</div>
						</div>

						<div class="incident-card">
							<h3>Severity trend</h3>
							<p class="incident-card-subtitle">Incident count per severity as trend lines. Hover for values.</p>
							<div class="incident-legend" data-role="trend-legend"></div>
							<div class="incident-chart-surface" data-role="trend-surface"></div>
						</div>

						<div class="incident-card" data-role="drill-card" hidden>
							<div class="incident-drill-head">
								<h3 data-role="drill-title">Incident details</h3>
								<button type="button" class="incident-btn incident-drill-close" data-role="drill-close">Close</button>
							</div>
							<p class="incident-card-subtitle" data-role="drill-subtitle"></p>
							<div data-role="drill-body"></div>
						</div>

						<div class="incident-card">
							<h3>Summary</h3>
							<p class="incident-card-subtitle">Counts and percentages for each severity level.</p>
							<div data-role="summary"></div>
							<div class="incident-footer-note">CSV export includes event ID, trigger ID, severity, start time and recovery time for the selected range and filters.</div>
						</div>
					</div>

					<div class="incident-tabpanel" data-panel="top" hidden>
						<div class="incident-card">
							<h3>Top triggers</h3>
							<p class="incident-card-subtitle" data-role="top-subtitle">Triggers ranked by number of problems. With filters, every matching trigger is shown; otherwise the top 100.</p>
							<div data-role="top-body"></div>
						</div>
					</div>
				</div>
			`;

			const q = (s) => this.root.querySelector(s);
			this.elements = {
				presets: q('[data-role="presets"]'), from: q('[data-role="from"]'), to: q('[data-role="to"]'),
				prev: q('[data-role="prev"]'), next: q('[data-role="next"]'), granularity: q('[data-role="granularity"]'),
				resetZoom: q('[data-role="reset-zoom"]'),
				exportPng: q('[data-role="export-png"]'), exportPdf: q('[data-role="export-pdf"]'),
				exportHtml: q('[data-role="export-html"]'), exportCsv: q('[data-role="export-csv"]'),
				filterbar: q('.incident-filterbar'), filterSummary: q('[data-role="filter-summary"]'),
				applyFilters: q('[data-role="apply-filters"]'), clearFilters: q('[data-role="clear-filters"]'),
				tabs: q('[data-role="tabs"]'),
				meta: q('[data-role="meta"]'), warning: q('[data-role="warning"]'), error: q('[data-role="error"]'),
				loading: q('[data-role="loading"]'),
				legend: q('[data-role="legend"]'), timelineSubtitle: q('[data-role="timeline-subtitle"]'),
				timelineSurface: q('[data-role="timeline-surface"]'), trendLegend: q('[data-role="trend-legend"]'),
				trendSurface: q('[data-role="trend-surface"]'), severitySurface: q('[data-role="severity-surface"]'),
				drillCard: q('[data-role="drill-card"]'), drillTitle: q('[data-role="drill-title"]'),
				drillSubtitle: q('[data-role="drill-subtitle"]'), drillBody: q('[data-role="drill-body"]'),
				drillClose: q('[data-role="drill-close"]'), summary: q('[data-role="summary"]'),
				severityFilter: q('[data-role="severity-filter"]'),
				panelTimeline: q('[data-panel="timeline"]'), panelTop: q('[data-panel="top"]'),
				topSubtitle: q('[data-role="top-subtitle"]'), topBody: q('[data-role="top-body"]')
			};

			this.tooltip = document.createElement('div');
			this.tooltip.className = 'incident-tooltip';
			this.tooltip.setAttribute('role', 'tooltip');
			this.tooltip.hidden = true;
			this.root.appendChild(this.tooltip);

			this.renderLegend();
			this.renderTrendLegend();
		}

		bindEvents() {
			this.elements.presets.addEventListener('click', (e) => {
				const b = e.target.closest('[data-preset]');
				if (b) { this.applyPreset(Number(b.dataset.preset)); }
			});
			this.elements.from.addEventListener('change', () => this.onManualRangeChange());
			this.elements.to.addEventListener('change', () => this.onManualRangeChange());
			this.elements.prev.addEventListener('click', () => this.shiftWindow(-1));
			this.elements.next.addEventListener('click', () => this.shiftWindow(1));
			this.elements.granularity.addEventListener('click', (e) => {
				const b = e.target.closest('[data-bucket]');
				if (b) { this.setGranularity(b.dataset.bucket); }
			});
			this.elements.resetZoom.addEventListener('click', () => this.resetZoom());

			this.elements.applyFilters.addEventListener('click', () => this.applyFilters());
			this.elements.clearFilters.addEventListener('click', () => this.clearFilters());
			this.elements.filterbar.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && e.target.matches('input[data-filter]')) { this.applyFilters(); }
			});

			this.elements.tabs.addEventListener('click', (e) => {
				const b = e.target.closest('[data-tab]');
				if (b) { this.switchTab(b.dataset.tab); }
			});

			this.elements.severityFilter.addEventListener('change', (e) => {
				if (e.target.type === 'checkbox') { this.onSeverityToggle(); }
			});
			this.elements.legend.addEventListener('click', (e) => this.onLegendToggle(e));
			this.elements.trendLegend.addEventListener('click', (e) => this.onLegendToggle(e));

			this.elements.exportCsv.addEventListener('click', () => this.exportCsv());
			this.elements.exportPdf.addEventListener('click', () => this.exportPdf());
			this.elements.exportHtml.addEventListener('click', () => this.exportHtml());
			this.elements.exportPng.addEventListener('click', () => {
				this.exportPng().catch((err) => this.showError(err instanceof Error ? err.message : 'Failed to export PNG.'));
			});
			this.elements.drillClose.addEventListener('click', () => this.hideDrill());

			this.bindChartSurface(this.elements.timelineSurface, 'timeline', true);
			this.bindChartSurface(this.elements.trendSurface, 'trend', false);
		}

		bindChartSurface(surface, kind, allowBrush) {
			surface.addEventListener('pointermove', (e) => this.onChartHover(e, surface, kind));
			surface.addEventListener('pointerleave', () => this.hideHover(kind));
			if (allowBrush) {
				surface.addEventListener('pointerdown', (e) => this.onBrushStart(e, surface));
			}
		}

		applyInitialState() {
			const url = new URL(window.location.href);
			const ds = this.root.dataset;
			const p = (k) => url.searchParams.get(k);
			const today = IncidentTimelineApp.todayUtcDate();

			const from = p('from') || ds.initialFrom || '';
			const to = p('to') || ds.initialTo || '';
			const month = ds.initialMonth || '';

			if (from && to) {
				this.elements.from.value = from;
				this.elements.to.value = to;
			}
			else if (month) {
				const [y, m] = month.split('-').map(Number);
				this.elements.from.value = IncidentTimelineApp.epochToDateInput(Date.UTC(y, m - 1, 1) / 1000);
				this.elements.to.value = IncidentTimelineApp.epochToDateInput(Date.UTC(y, m, 0) / 1000);
			}
			else {
				const f = new Date(today); f.setUTCMonth(f.getUTCMonth() - 3);
				this.elements.from.value = IncidentTimelineApp.epochToDateInput(f.getTime() / 1000);
				this.elements.to.value = IncidentTimelineApp.epochToDateInput(today.getTime() / 1000);
			}
			this.elements.to.max = IncidentTimelineApp.epochToDateInput(today.getTime() / 1000);

			const bucket = p('bucket') || ds.initialBucket || '';
			if (['auto', 'day', 'week', 'month'].includes(bucket)) { this.bucket = bucket; }

			this.filters.group = p('group') || '';
			this.filters.host = p('host') || '';
			this.filters.template = p('template') || '';
			this.filters.name = p('name') || '';
			this.filters.nameRegex = p('name_regex') === '1';
			this.elements.filterbar.querySelector('[data-filter="group"]').value = this.filters.group;
			this.elements.filterbar.querySelector('[data-filter="host"]').value = this.filters.host;
			this.elements.filterbar.querySelector('[data-filter="template"]').value = this.filters.template;
			this.elements.filterbar.querySelector('[data-filter="name"]').value = this.filters.name;
			this.elements.filterbar.querySelector('[data-filter="nameRegex"]').checked = this.filters.nameRegex;

			const tab = p('tab');
			if (tab === 'top') { this.activeTab = 'top'; }

			this.syncGranularityButtons();
			this.syncTabs();
			this.updateNavButtons();
			this.updateFilterSummary();
		}

		// ---- tabs ------------------------------------------------------------------
		switchTab(tab) {
			if (tab === this.activeTab) { return; }
			this.activeTab = tab;
			this.syncTabs();
			this.updateLocationState();
			this.updateExportLabels();
			this.loadActiveTab();
		}

		syncTabs() {
			this.elements.tabs.querySelectorAll('[data-tab]').forEach((b) => {
				b.classList.toggle('is-active', b.dataset.tab === this.activeTab);
			});
			this.elements.panelTimeline.hidden = this.activeTab !== 'timeline';
			this.elements.panelTop.hidden = this.activeTab !== 'top';
			this.elements.granularity.closest('.incident-field').style.display = this.activeTab === 'timeline' ? '' : 'none';
			this.updateExportLabels();
		}

		updateExportLabels() {
			const top = this.activeTab === 'top';
			this.elements.exportPng.style.display = top ? 'none' : '';
			this.elements.exportPdf.style.display = top ? 'none' : '';
		}

		loadActiveTab() {
			if (this.activeTab === 'top') { this.loadTopTriggers(); }
			else { this.loadTimeline(); }
		}

		// ---- range controls --------------------------------------------------------
		applyPreset(months) {
			const today = IncidentTimelineApp.todayUtcDate();
			const f = new Date(today); f.setUTCMonth(f.getUTCMonth() - months);
			this.elements.from.value = IncidentTimelineApp.epochToDateInput(f.getTime() / 1000);
			this.elements.to.value = IncidentTimelineApp.epochToDateInput(today.getTime() / 1000);
			this.hideZoomReset();
			this.invalidateData();
			this.loadActiveTab();
		}

		onManualRangeChange() {
			this.hideZoomReset();
			this.invalidateData();
			this.loadActiveTab();
		}

		shiftWindow(direction) {
			const r = this.getSelectedRange();
			if (!r) { return; }
			const spanDays = Math.max(1, Math.round((r.time_to - r.time_from) / 86400));
			const f = new Date(r.time_from * 1000), t = new Date(r.time_to * 1000);
			f.setUTCDate(f.getUTCDate() + direction * spanDays);
			t.setUTCDate(t.getUTCDate() + direction * spanDays);
			const tMid = Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), t.getUTCDate());
			if (tMid > IncidentTimelineApp.todayUtcDate().getTime()) { return; } // do not move past today
			this.elements.from.value = IncidentTimelineApp.epochToDateInput(f.getTime() / 1000);
			this.elements.to.value = IncidentTimelineApp.epochToDateInput(t.getTime() / 1000);
			this.hideZoomReset();
			this.invalidateData();
			this.loadActiveTab();
		}

		setGranularity(bucket) {
			if (!['auto', 'day', 'week', 'month'].includes(bucket) || bucket === this.bucket) { return; }
			this.bucket = bucket;
			this.syncGranularityButtons();
			this.tl.sig = null; // granularity only affects the timeline
			if (this.activeTab === 'timeline') { this.loadTimeline(); }
		}

		syncGranularityButtons() {
			this.elements.granularity.querySelectorAll('[data-bucket]').forEach((b) => {
				b.classList.toggle('is-active', b.dataset.bucket === this.bucket);
			});
		}

		updateNavButtons() {
			const r = this.getSelectedRange();
			if (!r) { return; }
			const spanDays = Math.max(1, Math.round((r.time_to - r.time_from) / 86400));
			const nextTo = new Date(r.time_to * 1000);
			nextTo.setUTCDate(nextTo.getUTCDate() + spanDays);
			const nextMid = Date.UTC(nextTo.getUTCFullYear(), nextTo.getUTCMonth(), nextTo.getUTCDate());
			this.elements.next.disabled = nextMid > IncidentTimelineApp.todayUtcDate().getTime();
		}

		getSelectedRange() {
			const time_from = IncidentTimelineApp.dateInputToEpoch(this.elements.from.value, false);
			const time_to = IncidentTimelineApp.dateInputToEpoch(this.elements.to.value, true);
			return (time_from === null || time_to === null) ? null : {time_from, time_to};
		}

		resetZoom() {
			if (this.preZoom) {
				this.elements.from.value = this.preZoom.from;
				this.elements.to.value = this.preZoom.to;
				this.preZoom = null;
			}
			this.hideZoomReset();
			this.invalidateData();
			this.loadActiveTab();
		}

		hideZoomReset() { this.elements.resetZoom.hidden = true; this.preZoom = null; }
		showZoomReset() { this.elements.resetZoom.hidden = false; }

		// ---- filters ---------------------------------------------------------------
		applyFilters() {
			const fb = this.elements.filterbar;
			this.filters.group = fb.querySelector('[data-filter="group"]').value.trim();
			this.filters.host = fb.querySelector('[data-filter="host"]').value.trim();
			this.filters.template = fb.querySelector('[data-filter="template"]').value.trim();
			this.filters.name = fb.querySelector('[data-filter="name"]').value.trim();
			this.filters.nameRegex = fb.querySelector('[data-filter="nameRegex"]').checked;
			this.resolved = null;
			this.hideZoomReset();
			this.invalidateData();
			this.updateFilterSummary();
			this.loadActiveTab();
		}

		clearFilters() {
			['group', 'host', 'template', 'name'].forEach((k) => {
				this.elements.filterbar.querySelector(`[data-filter="${k}"]`).value = '';
			});
			this.elements.filterbar.querySelector('[data-filter="nameRegex"]').checked = false;
			this.applyFilters();
		}

		hasNameOrSeverityFilter() {
			return this.filters.name !== '' || this.activeSeverities.size < SEVERITIES.length;
		}

		filterSignature() {
			const sev = SEV_DESC.filter((k) => this.activeSeverities.has(k)).join('');
			return JSON.stringify([this.filters, sev]);
		}

		updateFilterSummary() {
			const parts = [];
			if (this.filters.group) { parts.push(`group~"${this.filters.group}"`); }
			if (this.filters.host) { parts.push(`host~"${this.filters.host}"`); }
			if (this.filters.template) { parts.push(`template~"${this.filters.template}"`); }
			if (this.filters.name) { parts.push(`name ${this.filters.nameRegex ? '=~' : '~'} "${this.filters.name}"`); }
			this.elements.filterSummary.textContent = parts.length ? `Filter: ${parts.join('  ·  ')}` : '';
		}

		async ensureResolved() {
			const fsig = JSON.stringify([this.filters.group, this.filters.host, this.filters.template]);
			if (this.resolved && this.resolved.fsig === fsig) { return this.resolved; }

			if (!this.filters.group && !this.filters.host && !this.filters.template) {
				this.resolved = {fsig, groupids: [], hostids: [], empty: false, summary: ''};
				return this.resolved;
			}

			const r = await this.fetchData({
				time_from: 0, time_to: 1, mode: 'resolve',
				group: this.filters.group, host: this.filters.host, template: this.filters.template
			}, null);
			this.resolved = {fsig, groupids: r.groupids || [], hostids: r.hostids || [], empty: !!r.empty,
				truncated: !!r.truncated, summary: r.summary || ''};
			if (r.truncated) {
				this.updateFilterSummary();
				this.elements.filterSummary.textContent += `  ·  ${r.summary}`;
			}
			return this.resolved;
		}

		baseFilterParams() {
			const params = {};
			if (this.resolved && this.resolved.groupids.length) { params.groupids = this.resolved.groupids.join(','); }
			if (this.resolved && this.resolved.hostids.length) { params.hostids = this.resolved.hostids.join(','); }
			if (this.filters.name) { params.name = this.filters.name; params.name_regex = this.filters.nameRegex ? '1' : '0'; }
			return params;
		}

		activeSeveritiesCsv() {
			return SEV_DESC.filter((k) => this.activeSeverities.has(k)).sort((a, b) => a - b).join(',');
		}

		invalidateData() {
			this.tl = this.emptyTimelineState();
			this.top.sig = null;
			this.top.ready = false;
			this.drillCache.clear();
			this.hideDrill();
		}

		// ---- severity filter -------------------------------------------------------
		updateActiveSeverities() {
			this.activeSeverities.clear();
			this.elements.severityFilter.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
				if (cb.checked) { this.activeSeverities.add(Number(cb.value)); }
			});
		}

		onSeverityToggle() {
			this.updateActiveSeverities();
			this.syncLegendState();
			if (this.activeTab === 'top') {
				// Severity scope changes the top-triggers result set.
				this.top.sig = null;
				this.loadTopTriggers();
				return;
			}
			// Timeline: load any newly-active severity not yet fetched or in flight,
			// else just re-render (toggle-off needs no network).
			const missing = SEV_DESC.filter((k) =>
				this.activeSeverities.has(k) && this.tl.skeleton && !this.tl.loaded.has(k) && !this.tl.inflight.has(k));
			if (missing.length && this.tl.skeleton) {
				this.loadTimelineSeverities(missing);
			}
			else if (!this.tl.skeleton) {
				this.loadTimeline();
			}
			else {
				this.reRenderTimeline();
			}
		}

		onLegendToggle(e) {
			const item = e.target.closest('[data-severity]');
			if (!item) { return; }
			const key = Number(item.dataset.severity);
			const cb = this.elements.severityFilter.querySelector(`input[value="${key}"]`);
			if (cb) { cb.checked = !cb.checked; this.onSeverityToggle(); }
		}

		getFilteredSeverities() {
			return SEVERITIES.filter((s) => this.activeSeverities.has(s.key));
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
			if (payload && payload.error) { throw new Error(payload.error.message || 'Failed to load incident data.'); }
			if (!response.ok) { throw new Error(`Request failed with HTTP ${response.status}.`); }
			return payload;
		}

		validatedRange() {
			const r = this.getSelectedRange();
			if (!r) { this.showError('Please choose a valid From and To date.'); return null; }
			if (r.time_from > r.time_to) { this.showError('The From date must be on or before the To date.'); return null; }
			if (Math.round((r.time_to - r.time_from) / 86400) > this.maxRangeDays) {
				this.showError(`The selected range is too large (max ${this.maxRangeDays} days).`); return null;
			}
			return r;
		}

		// ---- TIMELINE tab (progressive per-severity) -------------------------------
		async loadTimeline() {
			const range = this.validatedRange();
			if (!range) { return; }

			this.requestRange = range;
			const sig = JSON.stringify([range, this.bucket, this.filterSignature()]);
			this.clearMessages();
			this.updateNavButtons();
			this.updateGranularityHints(Math.round((range.time_to - range.time_from) / 86400));
			this.updateLocationState();

			// Cache hit: same range/bucket/filters/severities already fully loaded.
			if (this.tl.sig === sig && this.tl.ready) {
				this.reRenderTimeline();
				this.updateExportState();
				return;
			}

			if (this.loadAbort) { this.loadAbort.abort(); }
			this.loadAbort = new AbortController();
			const seq = ++this.loadSeq;
			const signal = this.loadAbort.signal;

			this.tl = this.emptyTimelineState();
			this.tl.sig = sig;
			this.updateExportState(); // disable exports until this load completes
			this.setLoading(true, 'Resolving filter…');

			try {
				const resolved = await this.ensureResolved();
				if (seq !== this.loadSeq) { return; }
				if (resolved.empty) {
					this.setLoading(false);
					this.renderEmptyTimeline('No hosts or groups matched the filter.');
					this.updateExportState();
					return;
				}

				const order = SEV_DESC.filter((k) => this.activeSeverities.has(k));
				if (!order.length) {
					this.setLoading(false);
					this.renderEmptyTimeline('No severities selected.');
					return;
				}

				for (let i = 0; i < order.length; i++) {
					const sev = order[i];
					if (this.tl.loaded.has(sev) || this.tl.inflight.has(sev)) { continue; }
					this.setLoading(true, `Loading ${SEV_BY_KEY[sev].label}… (${i + 1}/${order.length})`);
					this.tl.inflight.add(sev);
					try {
						const resp = await this.fetchData({
							time_from: range.time_from, time_to: range.time_to, bucket: this.bucket,
							mode: 'aggregate', severity: String(sev), ...this.baseFilterParams()
						}, signal);
						if (seq !== this.loadSeq) { return; }
						this.mergeSeverity(resp, sev);
						this.reRenderTimeline();
					}
					finally {
						this.tl.inflight.delete(sev);
					}
				}
				this.tl.ready = true;
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				if (seq === this.loadSeq) {
					this.renderEmptyTimeline(err instanceof Error ? err.message : 'Failed to load incident data.');
					this.showError(err instanceof Error ? err.message : 'Failed to load incident data.');
				}
			}
			finally {
				if (seq === this.loadSeq && !this.tl.inflight.size) {
					this.setLoading(false);
					this.updateExportState();
				}
			}
		}

		// Load specific severities (toggled on mid-session) without resetting the rest.
		async loadTimelineSeverities(severities) {
			const range = this.requestRange;
			if (!range || !this.tl.skeleton) { this.loadTimeline(); return; }
			const seq = this.loadSeq; // piggyback the current generation (keeps existing data)
			const signal = this.loadAbort ? this.loadAbort.signal : null;
			this.setLoading(true, 'Loading severity…');
			try {
				for (const sev of severities) {
					if (this.tl.loaded.has(sev) || this.tl.inflight.has(sev)) { continue; }
					this.tl.inflight.add(sev);
					try {
						const resp = await this.fetchData({
							time_from: range.time_from, time_to: range.time_to, bucket: this.bucket,
							mode: 'aggregate', severity: String(sev), ...this.baseFilterParams()
						}, signal);
						if (seq !== this.loadSeq) { return; }
						this.mergeSeverity(resp, sev);
						this.reRenderTimeline();
					}
					finally {
						this.tl.inflight.delete(sev);
					}
				}
			}
			catch (err) {
				if (!(err && err.name === 'AbortError')) { this.reRenderTimeline(); }
			}
			finally {
				if (seq === this.loadSeq && !this.tl.inflight.size) { this.setLoading(false); }
			}
		}

		mergeSeverity(resp, sev) {
			const buckets = Array.isArray(resp.buckets) ? resp.buckets : [];
			if (!this.tl.skeleton || this.tl.skeleton.length !== buckets.length) {
				this.tl.skeleton = buckets.map((b) => ({key: b.key, label: b.label, start: b.start, end: b.end}));
				this.tl.sev = {};
				this.tl.totals = {};
				this.tl.loaded = new Set();
			}
			this.tl.sev[sev] = buckets.map((b) => Number(b[`sev_${sev}`] || 0));
			this.tl.totals[sev] = Number((resp.meta && resp.meta.total_incidents) || 0);
			this.tl.loaded.add(sev);
			if (resp.meta) {
				this.tl.granularity = resp.meta.granularity || this.tl.granularity;
				this.tl.granularityClamped = !!resp.meta.granularity_clamped;
				if (resp.meta.limit_reached) { this.tl.limitReached = true; }
			}
		}

		displayBuckets() {
			if (!this.tl.skeleton) { return []; }
			return this.tl.skeleton.map((b, i) => {
				const row = {key: b.key, label: b.label, start: b.start, end: b.end};
				SEVERITIES.forEach((s) => {
					const arr = this.tl.sev[s.key];
					row[`sev_${s.key}`] = arr ? Number(arr[i] || 0) : 0;
				});
				return row;
			});
		}

		reRenderTimeline() {
			const buckets = this.displayBuckets();
			const active = this.getFilteredSeverities();
			const summaryActive = active.map((s) => ({severity: s.key, label: s.label, count: this.tl.totals[s.key] || 0}));
			const filteredTotal = summaryActive.reduce((sum, r) => sum + Number(r.count || 0), 0);

			let warning = '';
			if (this.tl.limitReached) { warning = 'Some severities hit the scan limit; counts may be partial.'; }
			else if (this.tl.granularityClamped) { warning = `The range is large, so data is grouped by ${this.tl.granularity}.`; }
			this.renderWarning(warning);

			this.updateTimelineSubtitle(this.tl.granularity);
			this.renderMeta(filteredTotal);
			this.renderTimeline(buckets);
			this.renderTrendLine(buckets);
			this.renderSeverityChart(summaryActive);
			this.renderSummary(summaryActive, filteredTotal);
		}

		renderEmptyTimeline(message) {
			this.timelineGeom = null; this.trendGeom = null;
			this.elements.meta.textContent = message || '';
			const empty = `<div class="incident-empty">${this.escapeHtml(message || 'No data is available.')}</div>`;
			this.elements.timelineSurface.innerHTML = empty;
			this.elements.trendSurface.innerHTML = empty;
			this.elements.severitySurface.innerHTML = empty;
			this.elements.summary.innerHTML = empty;
		}

		updateTimelineSubtitle(granularity) {
			const word = {day: 'Daily', week: 'Weekly', month: 'Monthly'}[granularity] || 'Daily';
			this.elements.timelineSubtitle.textContent =
				`${word} trigger problem events grouped by severity. Drag to zoom, click a bar for details.`;
		}

		updateGranularityHints(spanDays) {
			const dayBtn = this.elements.granularity.querySelector('[data-bucket="day"]');
			if (dayBtn) {
				dayBtn.disabled = spanDays > 120;
				dayBtn.title = dayBtn.disabled ? 'Range too large for daily buckets' : '';
			}
		}

		renderMeta(incidentCount) {
			const r = this.requestRange || {};
			const fromLabel = r.time_from ? this.formatDate(new Date(r.time_from * 1000)) : 'n/a';
			const toLabel = r.time_to ? this.formatDate(new Date(r.time_to * 1000)) : 'n/a';
			const loadedCount = this.tl.loaded ? this.tl.loaded.size : 0;
			const progress = (loadedCount < this.activeSeverities.size) ? ` • loading ${loadedCount}/${this.activeSeverities.size} severities…` : '';
			this.elements.meta.textContent =
				`Range: ${fromLabel} → ${toLabel} • Incidents: ${incidentCount} • Grouped by ${this.tl.granularity}${progress}`;
		}

		// ---- legends ---------------------------------------------------------------
		renderLegend() {
			this.elements.legend.innerHTML = SEVERITIES.map((s) =>
				`<button type="button" class="incident-legend-item" data-severity="${s.key}" aria-pressed="true"><span class="incident-badge sev-${s.key}"></span>${this.escapeHtml(s.label)}</button>`
			).join('');
		}

		renderTrendLegend() {
			this.elements.trendLegend.innerHTML = SEVERITIES.map((s) =>
				`<button type="button" class="incident-legend-item" data-severity="${s.key}" aria-pressed="true"><span style="display:inline-block;width:18px;height:3px;background:${s.color};vertical-align:middle;border-radius:2px;margin-right:4px;"></span>${this.escapeHtml(s.label)}</button>`
			).join('');
		}

		syncLegendState() {
			this.root.querySelectorAll('.incident-legend-item[data-severity]').forEach((item) => {
				const on = this.activeSeverities.has(Number(item.dataset.severity));
				item.setAttribute('aria-pressed', on ? 'true' : 'false');
				item.classList.toggle('is-off', !on);
			});
		}

		// ---- timeline chart (stacked bars) ----------------------------------------
		renderTimeline(buckets) {
			this.syncLegendState();
			if (!buckets.length) {
				this.timelineGeom = null;
				this.elements.timelineSurface.innerHTML = '<div class="incident-empty">No incidents were found for the selected range.</div>';
				return;
			}
			const built = this.buildTimelineSvg(buckets, this.getFilteredSeverities(), this.getPalette(), true);
			this.elements.timelineSurface.innerHTML = built.svg;
			this.timelineGeom = built.geom;
			const svg = this.elements.timelineSurface.querySelector('svg');
			this.timelineEls = svg ? {svg, highlight: svg.querySelector('.incident-col-highlight'), brush: svg.querySelector('.incident-brush')} : null;
		}

		buildTimelineSvg(buckets, visibleSeverities, palette, interactive) {
			const margin = {top: 18, right: 20, bottom: 78, left: 52};
			const plotWidth = Math.max(640, buckets.length * 20);
			const width = margin.left + plotWidth + margin.right;
			const height = 360;
			const plotHeight = height - margin.top - margin.bottom;
			const barSlot = plotWidth / Math.max(buckets.length, 1);
			const barWidth = Math.max(2, Math.min(22, barSlot - 2));
			const totals = buckets.map((b) => visibleSeverities.reduce((s, sv) => s + Number(b[`sev_${sv.key}`] || 0), 0));
			const maxValue = Math.max(1, ...totals);
			const yTicks = this.buildTicks(maxValue, 5);
			const labelStep = Math.max(1, Math.ceil(buckets.length / 12));

			let svg = this.createSvgOpenTag(width, height);
			if (interactive) {
				svg += `<rect class="incident-col-highlight" x="0" y="${margin.top}" width="0" height="${plotHeight}" fill="${palette.highlight}" opacity="0" pointer-events="none" />`;
			}
			yTicks.forEach((t) => {
				const y = margin.top + plotHeight - ((t / maxValue) * plotHeight);
				svg += `<line x1="${margin.left}" y1="${y}" x2="${width - margin.right}" y2="${y}" stroke="${palette.grid}" stroke-width="1" />`;
				svg += `<text x="${margin.left - 8}" y="${y + 4}" font-size="11" text-anchor="end" fill="${palette.axisText}">${t}</text>`;
			});
			svg += `<line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<text x="${margin.left}" y="${margin.top - 4}" font-size="12" font-weight="600" fill="${palette.title}">Incidents per bucket</text>`;

			buckets.forEach((bucket, index) => {
				const x = margin.left + (index * barSlot) + ((barSlot - barWidth) / 2);
				let stacked = 0;
				visibleSeverities.forEach((sev) => {
					const value = Number(bucket[`sev_${sev.key}`] || 0);
					if (value <= 0) { return; }
					const rectH = Math.max(1, (value / maxValue) * plotHeight);
					const y = margin.top + plotHeight - stacked - rectH;
					svg += `<rect x="${x}" y="${y}" width="${barWidth}" height="${rectH}" fill="${sev.color}" />`;
					stacked += rectH;
				});
				if (index % labelStep === 0 || index === buckets.length - 1) {
					const lx = x + (barWidth / 2), ly = margin.top + plotHeight + 14;
					svg += `<text x="${lx}" y="${ly}" font-size="10" fill="${palette.axisText}" transform="rotate(45 ${lx} ${ly})" text-anchor="start">${this.escapeHtml(bucket.label)}</text>`;
				}
			});
			if (interactive) {
				svg += `<rect class="incident-brush" x="0" y="${margin.top}" width="0" height="${plotHeight}" fill="${palette.brush}" stroke="${palette.brushStroke}" stroke-width="1" opacity="0" pointer-events="none" />`;
			}
			svg += '</svg>';
			return {svg, geom: {kind: 'bar', margin, plotWidth, plotHeight, barSlot, n: buckets.length, vbWidth: width, vbHeight: height, buckets}};
		}

		// ---- trend chart -----------------------------------------------------------
		renderTrendLine(buckets) {
			if (!buckets.length) {
				this.trendGeom = null;
				this.elements.trendSurface.innerHTML = '<div class="incident-empty">No trend data is available.</div>';
				return;
			}
			const built = this.buildTrendSvg(buckets, this.getFilteredSeverities(), this.getPalette(), true);
			this.elements.trendSurface.innerHTML = built.svg;
			this.trendGeom = built.geom;
			const svg = this.elements.trendSurface.querySelector('svg');
			this.trendEls = svg ? {svg, crosshair: svg.querySelector('.incident-crosshair')} : null;
		}

		buildTrendSvg(buckets, visibleSeverities, palette, interactive) {
			const margin = {top: 24, right: 30, bottom: 78, left: 52};
			const plotWidth = Math.max(800, buckets.length * 28);
			const width = margin.left + plotWidth + margin.right;
			const height = 380;
			const plotHeight = height - margin.top - margin.bottom;
			const labelStep = Math.max(1, Math.ceil(buckets.length / 12));
			const slotWidth = plotWidth / Math.max(buckets.length - 1, 1);

			let maxValue = 0;
			buckets.forEach((b) => visibleSeverities.forEach((s) => {
				const v = Number(b[`sev_${s.key}`] || 0);
				if (v > maxValue) { maxValue = v; }
			}));
			maxValue = Math.max(1, maxValue);
			const yTicks = this.buildTicks(maxValue, 5);

			let svg = this.createSvgOpenTag(width, height);
			if (interactive) {
				svg += `<line class="incident-crosshair" x1="0" y1="${margin.top}" x2="0" y2="${margin.top + plotHeight}" stroke="${palette.brushStroke}" stroke-width="1" stroke-dasharray="3 3" opacity="0" pointer-events="none" />`;
			}
			yTicks.forEach((t) => {
				const y = margin.top + plotHeight - ((t / maxValue) * plotHeight);
				svg += `<line x1="${margin.left}" y1="${y}" x2="${width - margin.right}" y2="${y}" stroke="${palette.grid}" stroke-width="1" />`;
				svg += `<text x="${margin.left - 8}" y="${y + 4}" font-size="11" text-anchor="end" fill="${palette.axisText}">${t}</text>`;
			});
			svg += `<line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + plotHeight}" stroke="${palette.axis}" stroke-width="1" />`;
			svg += `<text x="${margin.left}" y="${margin.top - 6}" font-size="12" font-weight="600" fill="${palette.title}">Incidents per period (trend)</text>`;

			buckets.forEach((bucket, index) => {
				if (index % labelStep === 0 || index === buckets.length - 1) {
					const lx = margin.left + (index * slotWidth), ly = margin.top + plotHeight + 14;
					svg += `<text x="${lx}" y="${ly}" font-size="10" fill="${palette.axisText}" transform="rotate(45 ${lx} ${ly})" text-anchor="start">${this.escapeHtml(bucket.label)}</text>`;
				}
			});
			visibleSeverities.forEach((sev) => {
				const points = buckets.map((b, i) => {
					const v = Number(b[`sev_${sev.key}`] || 0);
					return `${margin.left + (i * slotWidth)},${margin.top + plotHeight - ((v / maxValue) * plotHeight)}`;
				});
				if (points.length > 1) {
					svg += `<polyline points="${points.join(' ')}" fill="none" stroke="${sev.color}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />`;
				}
				else if (points.length === 1) {
					// A single bucket has no line segment — draw a marker so it is visible.
					const [px, py] = points[0].split(',');
					svg += `<circle cx="${px}" cy="${py}" r="3.5" fill="${sev.color}" />`;
				}
			});
			svg += '</svg>';
			return {svg, geom: {kind: 'line', margin, plotWidth, plotHeight, slotWidth, n: buckets.length, vbWidth: width, vbHeight: height, buckets}};
		}

		// ---- severity bar chart (responsive) --------------------------------------
		renderSeverityChart(summary) {
			const palette = this.getPalette();
			if (!summary.length) {
				this.elements.severitySurface.innerHTML = '<div class="incident-empty">No severity data is available.</div>';
				return;
			}
			this.elements.severitySurface.innerHTML = this.buildSeveritySvg(summary, palette);
		}

		buildSeveritySvg(summary, palette) {
			const margin = {top: 16, right: 56, bottom: 28, left: 120};
			const rowHeight = 34;
			const plotWidth = 360;
			const width = margin.left + plotWidth + margin.right;
			const height = margin.top + (summary.length * rowHeight) + margin.bottom;
			const maxValue = Math.max(1, ...summary.map((r) => Number(r.count || 0)));
			const ticks = this.buildTicks(maxValue, 4);

			let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" preserveAspectRatio="xMinYMin meet" style="width:100%;height:auto;max-width:${width}px" role="img" aria-label="Incidents by severity">`;
			svg += `<text x="${margin.left}" y="12" font-size="12" font-weight="600" fill="${palette.title}">Incident count</text>`;
			ticks.forEach((t) => {
				const x = margin.left + ((t / maxValue) * plotWidth);
				svg += `<line x1="${x}" y1="${margin.top}" x2="${x}" y2="${height - margin.bottom}" stroke="${palette.grid}" stroke-width="1" />`;
				svg += `<text x="${x}" y="${height - 8}" font-size="11" text-anchor="middle" fill="${palette.axisText}">${t}</text>`;
			});
			summary.forEach((row, index) => {
				const sev = SEV_BY_KEY[Number(row.severity)] || {color: '#999', label: row.label};
				const y = margin.top + (index * rowHeight);
				const bw = (Number(row.count || 0) / maxValue) * plotWidth;
				const ly = y + 14;
				svg += `<text x="${margin.left - 8}" y="${ly}" font-size="12" text-anchor="end" fill="${palette.title}">${this.escapeHtml(row.label || sev.label)}</text>`;
				svg += `<rect x="${margin.left}" y="${y}" width="${bw}" height="18" rx="3" ry="3" fill="${sev.color}" />`;
				svg += `<text x="${margin.left + bw + 8}" y="${ly}" font-size="12" fill="${palette.axisText}">${Number(row.count || 0)}</text>`;
			});
			svg += '</svg>';
			return svg;
		}

		renderSummary(summary, total) {
			if (!summary.length) {
				this.elements.summary.innerHTML = '<div class="incident-empty">No summary data is available.</div>';
				return;
			}
			const rows = summary.map((row) => {
				const pct = total > 0 ? ((Number(row.count || 0) / total) * 100).toFixed(1) : '0.0';
				return `<tr><td><span class="incident-severity-cell"><span class="incident-badge sev-${Number(row.severity)}"></span>${this.escapeHtml(row.label || 'Unknown')}</span></td><td>${Number(row.count || 0)}</td><td>${pct}%</td></tr>`;
			}).join('');
			this.elements.summary.innerHTML =
				`<table class="incident-summary-table"><thead><tr><th>Severity</th><th>Count</th><th>Percentage</th></tr></thead><tbody>${rows}<tr><td><strong>Total</strong></td><td><strong>${total}</strong></td><td><strong>${total > 0 ? '100.0%' : '0.0%'}</strong></td></tr></tbody></table>`;
		}

		// ---- chart interactivity ---------------------------------------------------
		geomFor(kind) { return kind === 'timeline' ? this.timelineGeom : this.trendGeom; }

		svgUserX(svg, clientX) {
			const rect = svg.getBoundingClientRect();
			const vb = svg.viewBox.baseVal;
			const vbWidth = (vb && vb.width) ? vb.width : rect.width;
			return (clientX - rect.left) * (vbWidth / rect.width);
		}

		indexAt(geom, userX) {
			const rel = userX - geom.margin.left;
			if (geom.kind === 'bar') {
				if (rel < 0 || rel > geom.plotWidth) { return -1; }
				return Math.min(geom.n - 1, Math.max(0, Math.floor(rel / geom.barSlot)));
			}
			if (rel < -geom.slotWidth / 2 || rel > geom.plotWidth + geom.slotWidth / 2) { return -1; }
			return Math.min(geom.n - 1, Math.max(0, Math.round(rel / geom.slotWidth)));
		}

		onChartHover(e, surface, kind) {
			if (this.brush) { return; }
			const geom = this.geomFor(kind);
			const svg = surface.querySelector('svg');
			if (!geom || !svg) { return; }
			const idx = this.indexAt(geom, this.svgUserX(svg, e.clientX));
			if (idx < 0) { this.hideHover(kind); return; }
			const bucket = geom.buckets[idx];
			this.showTooltip(e, this.tooltipHtml(bucket));
			if (kind === 'timeline' && this.timelineEls && this.timelineEls.highlight) {
				this.timelineEls.highlight.setAttribute('x', String(geom.margin.left + idx * geom.barSlot));
				this.timelineEls.highlight.setAttribute('width', String(geom.barSlot));
				this.timelineEls.highlight.setAttribute('opacity', '1');
			}
			if (kind === 'trend' && this.trendEls && this.trendEls.crosshair) {
				const x = geom.margin.left + idx * geom.slotWidth;
				this.trendEls.crosshair.setAttribute('x1', String(x));
				this.trendEls.crosshair.setAttribute('x2', String(x));
				this.trendEls.crosshair.setAttribute('opacity', '1');
			}
		}

		tooltipHtml(bucket) {
			const active = this.getFilteredSeverities();
			let total = 0;
			const rows = active.map((s) => {
				const v = Number(bucket[`sev_${s.key}`] || 0);
				total += v;
				return v > 0 ? `<div class="incident-tt-row"><span class="incident-badge sev-${s.key}"></span>${this.escapeHtml(s.label)}<span class="incident-tt-val">${v}</span></div>` : '';
			}).join('');
			return `<div class="incident-tt-title">${this.escapeHtml(bucket.label)}</div>${rows || '<div class="incident-tt-row">No incidents</div>'}<div class="incident-tt-total">Total: ${total}</div>`;
		}

		showTooltip(e, html) {
			this.tooltip.innerHTML = html;
			this.tooltip.hidden = false;
			const pad = 14, w = this.tooltip.offsetWidth, h = this.tooltip.offsetHeight;
			let x = e.clientX + pad, y = e.clientY + pad;
			if (x + w > window.innerWidth - 8) { x = e.clientX - w - pad; }
			if (y + h > window.innerHeight - 8) { y = e.clientY - h - pad; }
			this.tooltip.style.left = `${Math.max(8, x)}px`;
			this.tooltip.style.top = `${Math.max(8, y)}px`;
		}

		hideHover(kind) {
			this.tooltip.hidden = true;
			if (kind === 'timeline' && this.timelineEls && this.timelineEls.highlight) { this.timelineEls.highlight.setAttribute('opacity', '0'); }
			if (kind === 'trend' && this.trendEls && this.trendEls.crosshair) { this.trendEls.crosshair.setAttribute('opacity', '0'); }
		}

		// ---- brush-to-zoom + click-to-drill ---------------------------------------
		onBrushStart(e, surface) {
			if (e.button !== 0) { return; }
			const geom = this.timelineGeom, svg = surface.querySelector('svg');
			if (!geom || !svg) { return; }
			const startIdx = this.indexAt(geom, this.svgUserX(svg, e.clientX));
			if (startIdx < 0) { return; }
			this.brush = {surface, svg, geom, startClientX: e.clientX, startIdx, startUserX: this.clampToPlot(geom, this.svgUserX(svg, e.clientX)), currentIdx: startIdx};
			surface.classList.add('is-brushing');
			document.addEventListener('pointermove', this.onPointerMove);
			document.addEventListener('pointerup', this.onPointerUp, {once: true});
			e.preventDefault();
		}

		clampToPlot(geom, userX) {
			return Math.min(geom.margin.left + geom.plotWidth, Math.max(geom.margin.left, userX));
		}

		onPointerMove(e) {
			if (!this.brush) { return; }
			e.preventDefault();
			const {geom, svg} = this.brush;
			const userX = this.clampToPlot(geom, this.svgUserX(svg, e.clientX));
			// Use the CLAMPED x so a drag that overshoots the plot edge still selects
			// the first/last bucket (unclamped would give -1 and cancel the zoom).
			this.brush.currentIdx = this.indexAt(geom, userX);
			const x0 = Math.min(this.brush.startUserX, userX), x1 = Math.max(this.brush.startUserX, userX);
			const brush = this.elements.timelineSurface.querySelector('.incident-brush');
			if (brush) {
				brush.setAttribute('x', String(x0));
				brush.setAttribute('width', String(Math.max(0, x1 - x0)));
				brush.setAttribute('opacity', '1');
			}
			this.tooltip.hidden = true;
		}

		onPointerUp(e) {
			if (!this.brush) { return; }
			document.removeEventListener('pointermove', this.onPointerMove);
			const {surface, geom, startIdx, startClientX, currentIdx} = this.brush;
			surface.classList.remove('is-brushing');
			const moved = Math.abs(e.clientX - startClientX);
			this.brush = null;
			const brush = this.elements.timelineSurface.querySelector('.incident-brush');
			if (brush) { brush.setAttribute('opacity', '0'); brush.setAttribute('width', '0'); }

			if (moved < CLICK_THRESHOLD_PX) {
				const bucket = geom.buckets[startIdx];
				if (bucket) { this.drillInto(bucket); }
				return;
			}
			const a = Math.min(startIdx, currentIdx), b = Math.max(startIdx, currentIdx);
			const from = geom.buckets[a], to = geom.buckets[b];
			if (!from || !to) { return; }
			this.preZoom = {from: this.elements.from.value, to: this.elements.to.value};
			this.elements.from.value = IncidentTimelineApp.epochToDateInput(Number(from.start));
			this.elements.to.value = IncidentTimelineApp.epochToDateInput(Number(to.end));
			this.bucket = 'auto';
			this.syncGranularityButtons();
			this.showZoomReset();
			this.invalidateData();
			this.loadTimeline();
		}

		// ---- drill-down ------------------------------------------------------------
		async drillInto(bucket) {
			const start = Number(bucket.start), end = Number(bucket.end);
			const cacheKey = `${start}-${end}-${this.filterSignature()}`;
			this.elements.drillCard.hidden = false;
			this.elements.drillTitle.textContent = `Incidents — ${bucket.label}`;
			this.elements.drillSubtitle.textContent = 'Loading incident details…';
			this.elements.drillBody.innerHTML = '';
			this.elements.drillCard.scrollIntoView({behavior: 'smooth', block: 'nearest'});

			if (this.drillCache.has(cacheKey)) { this.renderDrill(bucket, this.drillCache.get(cacheKey)); return; }
			if (this.drillAbort) { this.drillAbort.abort(); }
			this.drillAbort = new AbortController();
			try {
				const params = {time_from: start, time_to: end, bucket: 'day', mode: 'incidents', ...this.baseFilterParams()};
				const sev = this.activeSeveritiesCsv();
				if (sev) { params.severities = sev; }
				const payload = await this.fetchData(params, this.drillAbort.signal);
				const incidents = Array.isArray(payload.incidents) ? payload.incidents : [];
				this.drillCache.set(cacheKey, incidents);
				this.renderDrill(bucket, incidents);
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				this.elements.drillSubtitle.textContent = '';
				this.elements.drillBody.innerHTML = `<div class="incident-error">${this.escapeHtml(err instanceof Error ? err.message : 'Failed to load incident details.')}</div>`;
			}
		}

		renderDrill(bucket, incidents) {
			const visible = incidents
				.filter((i) => this.activeSeverities.has(Number(i.s ?? i.severity ?? 0)))
				.sort((a, b) => Number(b.c ?? b.clock ?? 0) - Number(a.c ?? a.clock ?? 0));
			this.elements.drillSubtitle.textContent = `${visible.length} incident${visible.length === 1 ? '' : 's'} in ${bucket.label} (matching active severities).`;
			if (!visible.length) {
				this.elements.drillBody.innerHTML = '<div class="incident-empty">No incidents for the active severities in this period.</div>';
				return;
			}
			const rows = visible.slice(0, 500).map((inc) => {
				const sev = Number(inc.s ?? inc.severity ?? 0), clock = Number(inc.c ?? inc.clock ?? 0), rc = Number(inc.rc ?? 0);
				return `<tr><td><span class="incident-severity-cell"><span class="incident-badge sev-${sev}"></span>${this.escapeHtml(SEV_BY_KEY[sev] ? SEV_BY_KEY[sev].label : '')}</span></td><td>${this.escapeHtml(inc.n || inc.name || '')}</td><td>${this.escapeHtml(this.formatDateTime(new Date(clock * 1000)))}</td><td>${rc > 0 ? this.escapeHtml(this.formatDateTime(new Date(rc * 1000))) : '<span class="incident-ongoing">Ongoing</span>'}</td></tr>`;
			}).join('');
			const note = visible.length > 500 ? '<div class="incident-footer-note">Showing the first 500 incidents.</div>' : '';
			this.elements.drillBody.innerHTML = `<table class="incident-summary-table incident-drill-table"><thead><tr><th>Severity</th><th>Name</th><th>Problem time</th><th>Recovery time</th></tr></thead><tbody>${rows}</tbody></table>${note}`;
		}

		hideDrill() { this.elements.drillCard.hidden = true; this.elements.drillBody.innerHTML = ''; }

		// ---- TOP TRIGGERS tab ------------------------------------------------------
		async loadTopTriggers() {
			const range = this.validatedRange();
			if (!range) { return; }
			this.requestRange = range;
			const sig = JSON.stringify([range, this.filterSignature()]);
			this.clearMessages();
			this.updateNavButtons();
			this.updateLocationState();

			if (this.activeSeverities.size === 0) {
				this.setLoading(false);
				this.elements.meta.textContent = '';
				this.elements.topBody.innerHTML = '<div class="incident-empty">No severities selected.</div>';
				this.top = {sig: null, rows: [], meta: null, sort: this.top.sort, ready: false};
				this.updateExportState();
				return;
			}

			if (this.top.sig === sig && this.top.ready) {
				this.renderTopMeta();
				this.renderTopTable();
				this.updateExportState();
				return;
			}

			if (this.loadAbort) { this.loadAbort.abort(); }
			this.loadAbort = new AbortController();
			const seq = ++this.loadSeq;
			const signal = this.loadAbort.signal;
			this.setLoading(true, 'Loading top triggers…');
			this.top = {sig, rows: [], meta: null, sort: this.top.sort, ready: false};
			this.updateExportState(); // disable exports until this load completes

			try {
				const resolved = await this.ensureResolved();
				if (seq !== this.loadSeq) { return; }
				if (resolved.empty) {
					this.setLoading(false);
					this.elements.meta.textContent = '';
					this.elements.topBody.innerHTML = '<div class="incident-empty">No hosts or groups matched the filter.</div>';
					return;
				}
				const params = {time_from: range.time_from, time_to: range.time_to, mode: 'top_triggers', ...this.baseFilterParams()};
				const sev = this.activeSeveritiesCsv();
				if (sev) { params.severities = sev; }
				const payload = await this.fetchData(params, signal);
				if (seq !== this.loadSeq) { return; }
				this.top.rows = Array.isArray(payload.top_triggers) ? payload.top_triggers : [];
				this.top.meta = payload.meta || {};
				this.top.ready = true;
				this.renderTopMeta();
				this.renderTopTable();
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				this.elements.topBody.innerHTML = `<div class="incident-error">${this.escapeHtml(err instanceof Error ? err.message : 'Failed to load top triggers.')}</div>`;
				this.showError(err instanceof Error ? err.message : 'Failed to load top triggers.');
			}
			finally {
				if (seq === this.loadSeq) { this.setLoading(false); this.updateExportState(); }
			}
		}

		renderTopMeta() {
			const m = this.top.meta || {};
			const r = this.requestRange || {};
			const fromLabel = r.time_from ? this.formatDate(new Date(r.time_from * 1000)) : 'n/a';
			const toLabel = r.time_to ? this.formatDate(new Date(r.time_to * 1000)) : 'n/a';
			const scope = m.filtered ? `${m.shown} matching trigger${m.shown === 1 ? '' : 's'}` : `top ${m.shown} of ${m.trigger_count} triggers`;
			this.elements.meta.textContent = `Range: ${fromLabel} → ${toLabel} • ${scope} • ${m.total_incidents || 0} incidents`;
			if (m.limit_reached) { this.renderWarning('The scan hit its event limit; counts and ranking may be partial — narrow the range or add a filter.'); }
			else { this.renderWarning(''); }
		}

		topSortedRows() {
			const {key, dir} = this.top.sort;
			const mul = dir === 'asc' ? 1 : -1;
			const rows = this.top.rows.slice();
			rows.sort((a, b) => {
				let av, bv;
				if (key === 'name') { av = (a.name || '').toLowerCase(); bv = (b.name || '').toLowerCase(); return av < bv ? -mul : av > bv ? mul : 0; }
				if (key === 'mttr') { av = a.mttr == null ? -1 : a.mttr; bv = b.mttr == null ? -1 : b.mttr; }
				else { av = Number(a[key] || 0); bv = Number(b[key] || 0); }
				return (av - bv) * mul;
			});
			return rows;
		}

		renderTopTable() {
			const rows = this.top.rows;
			if (!rows.length) {
				this.elements.topBody.innerHTML = '<div class="incident-empty">No triggers matched the selected range and filters.</div>';
				return;
			}
			const span = Math.max(1, (this.requestRange.time_to - this.requestRange.time_from));
			const maxShare = Math.max(...rows.map((r) => Number(r.share || 0)), 0.0001);
			const sorted = this.topSortedRows();
			const sortInd = (key) => this.top.sort.key === key ? (this.top.sort.dir === 'asc' ? ' ▲' : ' ▼') : '';

			const body = sorted.map((r) => {
				const sev = SEV_BY_KEY[Number(r.severity)] || {color: '#999', label: ''};
				const freq = this.formatFrequency(Number(r.count || 0), span);
				const mttr = r.mttr == null ? '—' : this.formatDuration(Number(r.mttr));
				const last = r.last ? this.formatDateTime(new Date(Number(r.last) * 1000)) : '';
				const sharePct = (Number(r.share || 0) * 100).toFixed(1);
				const barW = Math.round((Number(r.share || 0) / maxShare) * 100);
				return `<tr>
					<td class="ttr-rank">${r.rank}</td>
					<td><div class="ttr-name">${this.escapeHtml(r.name)}</div>${r.host ? `<div class="ttr-host">${this.escapeHtml(r.host)}</div>` : ''}</td>
					<td><span class="incident-severity-cell"><span class="incident-badge sev-${Number(r.severity)}"></span>${this.escapeHtml(sev.label)}</span></td>
					<td class="ttr-num">${Number(r.count || 0).toLocaleString()}</td>
					<td class="ttr-num">${this.escapeHtml(freq)}</td>
					<td class="ttr-num">${this.escapeHtml(mttr)}</td>
					<td>${this.escapeHtml(last)}</td>
					<td class="ttr-share"><span class="ttr-share-bar" style="width:${barW}%"></span><span class="ttr-share-val">${sharePct}%</span></td>
				</tr>`;
			}).join('');

			this.elements.topBody.innerHTML = `
				<div class="incident-table-scroll">
				<table class="incident-summary-table incident-top-table">
					<thead><tr>
						<th>#</th>
						<th class="ttr-sortable" data-sort="name">Trigger / Host${sortInd('name')}</th>
						<th class="ttr-sortable" data-sort="severity">Severity${sortInd('severity')}</th>
						<th class="ttr-sortable ttr-num" data-sort="count">Problems${sortInd('count')}</th>
						<th class="ttr-sortable ttr-num" data-sort="count">Avg frequency${sortInd('count')}</th>
						<th class="ttr-sortable ttr-num" data-sort="mttr">Mean time to resolve${sortInd('mttr')}</th>
						<th class="ttr-sortable" data-sort="last">Last occurrence${sortInd('last')}</th>
						<th class="ttr-sortable" data-sort="share">Share${sortInd('share')}</th>
					</tr></thead>
					<tbody>${body}</tbody>
				</table>
				</div>`;

			this.elements.topBody.querySelectorAll('.ttr-sortable').forEach((th) => {
				th.addEventListener('click', () => {
					const key = th.dataset.sort;
					if (this.top.sort.key === key) { this.top.sort.dir = this.top.sort.dir === 'asc' ? 'desc' : 'asc'; }
					else { this.top.sort.key = key; this.top.sort.dir = (key === 'name' || key === 'last') ? 'asc' : 'desc'; }
					this.renderTopTable();
				});
			});
		}

		formatFrequency(count, spanSeconds) {
			if (count <= 0) { return '—'; }
			for (const [unit, secs] of FREQ_UNITS) {
				const rate = count * secs / spanSeconds;
				if (rate >= 1) { return `≈ ${this.formatRate(rate)} / ${unit}`; }
			}
			const perYear = count * 31536000 / spanSeconds;
			return `≈ ${perYear.toFixed(2)} / year`;
		}

		formatRate(v) {
			if (v >= 100) { return Math.round(v).toLocaleString(); }
			if (v >= 10) { return v.toFixed(1); }
			return v.toFixed(2);
		}

		formatDuration(s) {
			s = Math.max(0, Math.round(s));
			const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
			if (d > 0) { return `${d}d ${h}h`; }
			if (h > 0) { return `${h}h ${m}m`; }
			if (m > 0) { return `${m}m`; }
			return `${sec}s`;
		}

		// ---- exports ---------------------------------------------------------------
		async ensureIncidents() {
			const range = this.requestRange;
			if (!range) { throw new Error('No data range is loaded.'); }
			const sig = `${range.time_from}-${range.time_to}-${this.filterSignature()}`;
			if (this._incidents && this._incidentsSig === sig) { return this._incidents; }
			if (this.csvAbort) { this.csvAbort.abort(); }
			this.csvAbort = new AbortController();
			const params = {time_from: range.time_from, time_to: range.time_to, bucket: this.bucket, mode: 'incidents', ...this.baseFilterParams()};
			const sev = this.activeSeveritiesCsv();
			if (sev) { params.severities = sev; }
			const payload = await this.fetchData(params, this.csvAbort.signal);
			this._incidents = Array.isArray(payload.incidents) ? payload.incidents : [];
			this._incidentsSig = sig;
			this._incidentsLimitReached = !!(payload.meta && payload.meta.limit_reached);
			return this._incidents;
		}

		async exportCsv() {
			if (this.activeTab === 'top') { this.exportTopCsv(); return; }
			const btn = this.elements.exportCsv, original = btn.textContent;
			btn.disabled = true; btn.textContent = 'Exporting…';
			try {
				const incidents = await this.ensureIncidents();
				const header = ['Event ID', 'Trigger ID', 'Name', 'Severity', 'Severity name', 'Problem time', 'Recovery time', 'Resolved'];
				const rows = [header];
				incidents.forEach((inc) => {
					const sev = Number(inc.s ?? inc.severity ?? 0), clock = Number(inc.c ?? inc.clock ?? 0), rc = Number(inc.rc ?? 0);
					if (!this.activeSeverities.has(sev)) { return; }
					rows.push([inc.eid || '', inc.oid || '', inc.n || '', sev, (SEV_BY_KEY[sev] || {}).label || '',
						this.formatIsoDateTime(clock), rc > 0 ? this.formatIsoDateTime(rc) : 'Ongoing', rc > 0 ? 'Yes' : 'No']);
				});
				const csv = rows.map((r) => r.map((v) => this.csvEscape(v)).join(',')).join('\n');
				this.downloadBlob(new Blob([csv], {type: 'text/csv;charset=utf-8;'}), this.buildFileName('incident-timeline', 'csv'));
				if (this._incidentsLimitReached) {
					this.renderWarning(`CSV export was truncated to the first ${incidents.length.toLocaleString()} incidents (server scan limit). Narrow the range or filters for a complete export.`);
				}
			}
			catch (err) {
				if (!(err && err.name === 'AbortError')) { this.showError(err instanceof Error ? err.message : 'Failed to export CSV.'); }
			}
			finally { btn.textContent = original; btn.disabled = false; }
		}

		exportTopCsv() {
			if (!this.top.rows.length) { return; }
			const span = Math.max(1, (this.requestRange.time_to - this.requestRange.time_from));
			const header = ['Rank', 'Trigger', 'Host', 'Severity', 'Problems', 'Avg per day', 'Avg frequency', 'Mean time to resolve (s)', 'Mean time to resolve', 'Last occurrence', 'Share %'];
			const rows = [header];
			this.topSortedRows().forEach((r) => {
				rows.push([r.rank, r.name || '', r.host || '', (SEV_BY_KEY[Number(r.severity)] || {}).label || '',
					Number(r.count || 0), (Number(r.count || 0) * 86400 / span).toFixed(3),
					this.formatFrequency(Number(r.count || 0), span).replace('≈ ', ''),
					r.mttr == null ? '' : r.mttr, r.mttr == null ? '' : this.formatDuration(Number(r.mttr)),
					r.last ? this.formatIsoDateTime(Number(r.last)) : '', (Number(r.share || 0) * 100).toFixed(2)]);
			});
			const csv = rows.map((r) => r.map((v) => this.csvEscape(v)).join(',')).join('\n');
			this.downloadBlob(new Blob([csv], {type: 'text/csv;charset=utf-8;'}), this.buildFileName('top-triggers', 'csv'));
		}

		buildReportHtml() {
			const metaText = this.escapeHtml(this.elements.meta.textContent || '');
			const filterText = this.escapeHtml(this.elements.filterSummary.textContent || '');
			let content;
			if (this.activeTab === 'top') {
				content = `<div class="card"><h2>Top triggers</h2>${this.elements.topBody.innerHTML}</div>`;
			}
			else {
				const buckets = this.displayBuckets();
				const active = this.getFilteredSeverities();
				const timelineSvg = buckets.length ? this.buildTimelineSvg(buckets, active, PALETTE_LIGHT, false).svg : '<p>No chart data.</p>';
				const trendSvg = buckets.length ? this.buildTrendSvg(buckets, active, PALETTE_LIGHT, false).svg : '<p>No chart data.</p>';
				const summaryActive = active.map((s) => ({severity: s.key, label: s.label, count: this.tl.totals[s.key] || 0}));
				const sevSvg = summaryActive.length ? this.buildSeveritySvg(summaryActive, PALETTE_LIGHT) : '<p>No chart data.</p>';
				content =
					`<div class="card"><h2>Incidents over time</h2>${timelineSvg}</div>
					 <div class="card"><h2>Severity trend</h2>${trendSvg}</div>
					 <div class="card"><h2>Incidents by severity</h2>${sevSvg}</div>
					 <div class="card"><h2>Summary</h2>${this.elements.summary.innerHTML}</div>`;
			}
			return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Incident Timeline Report</title>
				<style>
				body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#1f2b3a;}
				h1{margin:0 0 6px;} .meta,.filter{color:#5c6b7a;font-size:13px;margin:0 0 6px;}
				.card{border:1px solid #dfe4ec;border-radius:6px;padding:16px;margin:16px 0;}
				table{width:100%;border-collapse:collapse;} th,td{border-bottom:1px solid #e7edf4;padding:8px 10px;text-align:left;font-size:13px;}
				th{font-weight:700;} svg{max-width:100%;height:auto;}
				.ttr-share-bar{display:inline-block;height:8px;background:#0f6ad8;border-radius:2px;margin-right:6px;vertical-align:middle;}
				.incident-badge{display:inline-block;width:11px;height:11px;border-radius:2px;margin-right:6px;vertical-align:middle;}
				.sev-0{background:#97AAB3}.sev-1{background:#7499FF}.sev-2{background:#FFC859}.sev-3{background:#FFA059}.sev-4{background:#E97659}.sev-5{background:#E45959}
				.incident-ongoing{color:#d97706;font-weight:600;}
				</style></head><body>
				<h1>Incident Timeline Report</h1>
				<div class="meta">${metaText}</div>${filterText ? `<div class="filter">${filterText}</div>` : ''}
				${content}
				<div class="meta">Generated ${this.escapeHtml(this.formatDateTime(new Date()))}</div>
				</body></html>`;
		}

		exportHtml() {
			try {
				const html = this.buildReportHtml();
				const prefix = this.activeTab === 'top' ? 'top-triggers' : 'incident-timeline';
				this.downloadBlob(new Blob([html], {type: 'text/html;charset=utf-8;'}), this.buildFileName(prefix, 'html'));
			}
			catch (err) {
				this.showError(err instanceof Error ? err.message : 'Failed to export HTML.');
			}
		}

		exportPdf() {
			if (this.activeTab === 'top') { return; }
			const buckets = this.displayBuckets();
			if (!buckets.length) { return; }
			const html = this.buildReportHtml();
			const w = window.open('', '_blank', 'noopener');
			if (!w) { this.showError('Unable to open a print window for PDF export.'); return; }
			w.document.open(); w.document.write(html); w.document.close(); w.focus();
			setTimeout(() => w.print(), 250);
		}

		async exportPng() {
			if (this.activeTab === 'top') { return; }
			const buckets = this.displayBuckets();
			const active = this.getFilteredSeverities();
			if (!buckets.length) { throw new Error('Nothing is available to export as PNG.'); }
			const summaryActive = active.map((s) => ({severity: s.key, label: s.label, count: this.tl.totals[s.key] || 0}));

			const timelineSvg = this.buildTimelineSvg(buckets, active, PALETTE_LIGHT, false).svg;
			const trendSvg = this.buildTrendSvg(buckets, active, PALETTE_LIGHT, false).svg;
			const [tImg, trImg] = await Promise.all([this.svgStringToImage(timelineSvg), this.svgStringToImage(trendSvg)]);

			const canvasWidth = Math.max(tImg.width, trImg.width, 900);
			const summaryRows = summaryActive.map((r) => ({label: r.label || '', count: String(Number(r.count || 0))}));
			const summaryHeight = 40 + (summaryRows.length * 24);
			const canvasHeight = 80 + tImg.height + 24 + trImg.height + 24 + summaryHeight;
			const canvas = document.createElement('canvas');
			canvas.width = canvasWidth; canvas.height = canvasHeight;
			const ctx = canvas.getContext('2d');
			if (!ctx) { throw new Error('Failed to create a canvas for PNG export.'); }
			ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
			ctx.fillStyle = '#1f2b3a'; ctx.font = 'bold 24px Arial'; ctx.fillText('Incident Timeline Report', 20, 34);
			ctx.font = '13px Arial'; ctx.fillStyle = '#5c6b7a'; ctx.fillText(this.elements.meta.textContent || '', 20, 56);
			let y = 80;
			ctx.drawImage(tImg.image, 0, y, tImg.width, tImg.height); y += tImg.height + 24;
			ctx.drawImage(trImg.image, 0, y, trImg.width, trImg.height); y += trImg.height + 24;
			ctx.fillStyle = '#1f2b3a'; ctx.font = 'bold 16px Arial'; ctx.fillText('Summary', 20, y); y += 24;
			ctx.font = '13px Arial';
			summaryRows.forEach((row) => { ctx.fillStyle = '#1f2b3a'; ctx.fillText(row.label, 20, y); ctx.fillText(row.count, 280, y); y += 22; });
			const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
			if (!blob) { throw new Error('Failed to render the PNG file.'); }
			this.downloadBlob(blob, this.buildFileName('incident-timeline', 'png'));
		}

		svgStringToImage(svgText) {
			return new Promise((resolve, reject) => {
				const m = svgText.match(/viewBox="0 0 (\d+(?:\.\d+)?) (\d+(?:\.\d+)?)"/);
				const width = m ? Number(m[1]) : 800, height = m ? Number(m[2]) : 400;
				const blob = new Blob([svgText], {type: 'image/svg+xml;charset=utf-8'});
				const objectUrl = URL.createObjectURL(blob);
				const image = new Image();
				image.onload = () => { URL.revokeObjectURL(objectUrl); resolve({image, width, height}); };
				image.onerror = () => { URL.revokeObjectURL(objectUrl); reject(new Error('Failed to render SVG for PNG export.')); };
				image.src = objectUrl;
			});
		}

		// ---- misc ------------------------------------------------------------------
		buildTicks(maxValue, approxCount) {
			if (maxValue <= 1) { return [0, 1]; }
			const rawStep = maxValue / Math.max(1, approxCount);
			const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
			let step = magnitude;
			if (rawStep / magnitude > 5) { step = 10 * magnitude; }
			else if (rawStep / magnitude > 2) { step = 5 * magnitude; }
			else if (rawStep / magnitude > 1) { step = 2 * magnitude; }
			step = Math.max(1, Math.round(step));
			const ticks = [0];
			for (let v = step; v < maxValue; v += step) { ticks.push(v); }
			ticks.push(maxValue);
			return Array.from(new Set(ticks));
		}

		createSvgOpenTag(width, height) {
			return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="Incident chart">`;
		}

		setLoading(isLoading, message) {
			this.elements.loading.hidden = !isLoading;
			if (isLoading && message) { this.elements.loading.textContent = message; }
			[this.elements.from, this.elements.to, this.elements.prev, this.elements.next].forEach((el) => { el.disabled = isLoading; });
		}

		showError(message) {
			this.elements.error.hidden = false;
			this.elements.error.className = 'incident-error';
			this.elements.error.textContent = message;
		}

		clearMessages() {
			this.elements.error.hidden = true;
			this.elements.error.className = '';
			this.elements.error.textContent = '';
			this.renderWarning('');
		}

		renderWarning(message) {
			if (!message) { this.elements.warning.hidden = true; this.elements.warning.className = ''; this.elements.warning.textContent = ''; return; }
			this.elements.warning.hidden = false;
			this.elements.warning.className = 'incident-warning';
			this.elements.warning.textContent = message;
		}

		updateExportState() {
			// Timeline exports stay disabled until the progressive load fully completes.
			const tlReady = !!this.tl.ready;
			const topReady = !!(this.top.ready && this.top.rows.length);
			const has = this.activeTab === 'top' ? topReady : tlReady;
			this.elements.exportCsv.disabled = !has;
			this.elements.exportHtml.disabled = !has;
			this.elements.exportPdf.disabled = !tlReady;
			this.elements.exportPng.disabled = !tlReady;
		}

		updateLocationState() {
			const url = new URL(window.location.href);
			const sp = url.searchParams;
			sp.set('action', 'incident.timeline.view');
			sp.set('from', this.elements.from.value);
			sp.set('to', this.elements.to.value);
			sp.set('bucket', this.bucket);
			sp.set('tab', this.activeTab);
			const setOrDel = (k, v) => { if (v) { sp.set(k, v); } else { sp.delete(k); } };
			setOrDel('group', this.filters.group);
			setOrDel('host', this.filters.host);
			setOrDel('template', this.filters.template);
			setOrDel('name', this.filters.name);
			setOrDel('name_regex', this.filters.nameRegex ? '1' : '');
			['month', 'period', 'date_from', 'date_to'].forEach((k) => sp.delete(k));
			window.history.replaceState({}, '', url.toString());
		}

		csvEscape(value) {
			// Neutralize spreadsheet formula injection: names can embed macros from
			// logs/SNMP/traps that start with = + - @ (or tab/CR) and would execute.
			let s = String(value ?? '');
			if (/^[=+\-@\t\r]/.test(s)) { s = `'${s}`; }
			return `"${s.replace(/"/g, '""')}"`;
		}

		downloadBlob(blob, fileName) {
			const link = document.createElement('a');
			const url = URL.createObjectURL(blob);
			link.href = url; link.download = fileName; link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		}

		buildFileName(prefix, ext) { return `${prefix}-${IncidentTimelineApp.epochToDateInput(Date.now() / 1000)}.${ext}`; }

		formatDate(d) { return d.toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: '2-digit', timeZone: 'UTC'}); }

		formatDateTime(d) { return d.toLocaleString(undefined, {year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC'}); }

		formatIsoDateTime(ts) { return ts ? new Date(ts * 1000).toISOString() : ''; }

		escapeHtml(value) {
			return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}
	}
})();
