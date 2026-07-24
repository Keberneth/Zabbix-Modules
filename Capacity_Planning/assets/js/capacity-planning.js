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

	const LOOKBACKS = [
		{days: 92, label: '3M'}, {days: 183, label: '6M'}, {days: 365, label: '12M'}, {days: 730, label: '24M'}
	];
	const WINDOW_LABELS = {'12m': '12 months', '6m': '6 months', '3m': '3 months', '1m': '1 month', '1w': '1 week'};
	const HORIZON_DAYS = 365;
	const GIB = 1024 * 1024 * 1024;

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

			this.lookbackDays = 365;
			this.activeTab = 'overview';
			this.filters = {group: '', host: '', template: '', name: ''};
			this.resolved = null; // {fsig, groupids, hostids, empty, truncated, summary}
			this.activeRisks = new Set(RISKS.map((r) => r.key));
			this.elements = {};

			this.inv = this.emptyInventory();
			this.fc = new Map(); // finding id -> forecast payload
			this.fcTotal = 0;
			this.fcDone = 0;
			this.fcReady = false;

			this.sort = {
				disks: {key: 'risk', dir: 'desc'},
				resources: {key: 'risk', dir: 'desc'}
			};
			this.selected = null; // {kind: 'disk'|'res', id}
			this.detailGeom = null;

			this.loadSeq = 0;
			this.loadAbort = null;
		}

		emptyInventory() {
			return {sig: null, disks: [], resources: [], quality: [], meta: null, ready: false,
				byId: new Map()};
		}

		// ---- lifecycle -------------------------------------------------------------
		init() {
			this.renderShell();
			this.bindEvents();
			this.applyInitialState();
			this.load();
		}

		getPalette() {
			const themed = this.root.closest('[theme]');
			const theme = themed ? themed.getAttribute('theme') : '';
			return (theme === 'dark-theme' || theme === 'hc-dark') ? PALETTE_DARK : PALETTE_LIGHT;
		}

		renderShell() {
			this.root.innerHTML = `
				<div class="cap-shell">
					<div class="cap-toolbar">
						<div class="cap-toolbar-group">
							<div class="cap-field">
								<label>Analysis lookback</label>
								<div class="cap-seg" data-role="lookback">
									${LOOKBACKS.map((l) => `<button type="button" class="cap-seg-btn" data-days="${l.days}">${l.label}</button>`).join('')}
								</div>
							</div>
							<div class="cap-field">
								<label>&nbsp;</label>
								<button type="button" class="cap-btn" data-role="reload">Reload</button>
							</div>
						</div>
						<div class="cap-actions">
							<button type="button" class="cap-btn" data-role="export-png">Export PNG</button>
							<button type="button" class="cap-btn" data-role="export-html">Export HTML</button>
							<button type="button" class="cap-btn" data-role="export-csv">Export CSV</button>
						</div>
					</div>

					<div class="cap-filterbar">
						<div class="cap-field"><label for="cap-f-group">Host group</label><input type="text" id="cap-f-group" data-filter="group" placeholder="e.g. Databases"></div>
						<div class="cap-field"><label for="cap-f-host">Host</label><input type="text" id="cap-f-host" data-filter="host" placeholder="e.g. db01"></div>
						<div class="cap-field"><label for="cap-f-template">Template</label><input type="text" id="cap-f-template" data-filter="template" placeholder="e.g. Linux by Zabbix agent"></div>
						<div class="cap-field cap-field-grow"><label for="cap-f-name">Host / filesystem contains</label><input type="text" id="cap-f-name" data-filter="name" placeholder="e.g. /var or C:"></div>
						<div class="cap-filter-actions">
							<button type="button" class="cap-btn cap-btn-primary" data-role="apply-filters">Apply</button>
							<button type="button" class="cap-btn" data-role="clear-filters">Clear</button>
						</div>
						<div class="cap-filter-summary" data-role="filter-summary"></div>
					</div>

					<div class="cap-risk-filter" data-role="risk-filter">
						<span class="cap-risk-filter-label">Risk filter</span>
						<div class="cap-risk-filter-options">
							${RISKS.map((r) => `<label class="cap-filter-check"><input type="checkbox" value="${r.key}" checked><span class="cap-badge risk-${r.key.toLowerCase()}"></span>${r.key}</label>`).join('')}
						</div>
					</div>

					<div class="cap-tabs" data-role="tabs">
						<button type="button" class="cap-tab is-active" data-tab="overview">Overview</button>
						<button type="button" class="cap-tab" data-tab="disks">Filesystems</button>
						<button type="button" class="cap-tab" data-tab="resources">CPU &amp; Memory</button>
					</div>

					<div class="cap-meta" data-role="meta"></div>
					<div data-role="warning" hidden></div>
					<div data-role="error" hidden></div>
					<div class="cap-loading" data-role="loading">Loading…</div>

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
							<p class="cap-card-subtitle" data-role="disks-subtitle">Growth is a robust trend over the best-covered window; ETAs are projected threshold crossings, not exact Zabbix problem times. Click a row for the usage chart.</p>
							<div data-role="disks-body"></div>
						</div>
						<div class="cap-card" data-role="disk-detail" hidden>
							<div class="cap-detail-head">
								<h3 data-role="disk-detail-title">Filesystem detail</h3>
								<button type="button" class="cap-btn" data-role="disk-detail-close">Close</button>
							</div>
							<p class="cap-card-subtitle" data-role="disk-detail-subtitle"></p>
							<div class="cap-detail-stats" data-role="disk-detail-stats"></div>
							<div class="cap-legend" data-role="disk-detail-legend"></div>
							<div class="cap-chart-surface" data-role="disk-detail-surface"></div>
						</div>
					</div>

					<div class="cap-tabpanel" data-panel="resources" hidden>
						<div class="cap-card">
							<h3>CPU and memory baselines</h3>
							<p class="cap-card-subtitle" data-role="resources-subtitle">Sustained utilization against each host's Zabbix alarm thresholds. A single spike is not an upgrade decision. Click a row for the utilization chart.</p>
							<div data-role="resources-body"></div>
						</div>
						<div class="cap-card" data-role="res-detail" hidden>
							<div class="cap-detail-head">
								<h3 data-role="res-detail-title">Resource detail</h3>
								<button type="button" class="cap-btn" data-role="res-detail-close">Close</button>
							</div>
							<p class="cap-card-subtitle" data-role="res-detail-subtitle"></p>
							<div class="cap-detail-stats" data-role="res-detail-stats"></div>
							<div class="cap-legend" data-role="res-detail-legend"></div>
							<div class="cap-chart-surface" data-role="res-detail-surface"></div>
						</div>
					</div>
				</div>
			`;

			const q = (s) => this.root.querySelector(s);
			this.elements = {
				lookback: q('[data-role="lookback"]'), reload: q('[data-role="reload"]'),
				exportPng: q('[data-role="export-png"]'), exportHtml: q('[data-role="export-html"]'),
				exportCsv: q('[data-role="export-csv"]'),
				filterbar: q('.cap-filterbar'), filterSummary: q('[data-role="filter-summary"]'),
				applyFilters: q('[data-role="apply-filters"]'), clearFilters: q('[data-role="clear-filters"]'),
				riskFilter: q('[data-role="risk-filter"]'), tabs: q('[data-role="tabs"]'),
				meta: q('[data-role="meta"]'), warning: q('[data-role="warning"]'), error: q('[data-role="error"]'),
				loading: q('[data-role="loading"]'),
				cards: q('[data-role="cards"]'), runwaySurface: q('[data-role="runway-surface"]'),
				distSurface: q('[data-role="dist-surface"]'), topRisks: q('[data-role="top-risks"]'),
				qualitySubtitle: q('[data-role="quality-subtitle"]'), qualityBody: q('[data-role="quality-body"]'),
				disksBody: q('[data-role="disks-body"]'), disksSubtitle: q('[data-role="disks-subtitle"]'),
				diskDetail: q('[data-role="disk-detail"]'), diskDetailTitle: q('[data-role="disk-detail-title"]'),
				diskDetailSubtitle: q('[data-role="disk-detail-subtitle"]'),
				diskDetailStats: q('[data-role="disk-detail-stats"]'),
				diskDetailLegend: q('[data-role="disk-detail-legend"]'),
				diskDetailSurface: q('[data-role="disk-detail-surface"]'),
				diskDetailClose: q('[data-role="disk-detail-close"]'),
				resourcesBody: q('[data-role="resources-body"]'),
				resDetail: q('[data-role="res-detail"]'), resDetailTitle: q('[data-role="res-detail-title"]'),
				resDetailSubtitle: q('[data-role="res-detail-subtitle"]'),
				resDetailStats: q('[data-role="res-detail-stats"]'),
				resDetailLegend: q('[data-role="res-detail-legend"]'),
				resDetailSurface: q('[data-role="res-detail-surface"]'),
				resDetailClose: q('[data-role="res-detail-close"]'),
				panelOverview: q('[data-panel="overview"]'), panelDisks: q('[data-panel="disks"]'),
				panelResources: q('[data-panel="resources"]')
			};

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
			});
			this.elements.reload.addEventListener('click', () => { this.invalidate(); this.load(); });

			this.elements.applyFilters.addEventListener('click', () => this.applyFilters());
			this.elements.clearFilters.addEventListener('click', () => this.clearFilters());
			this.elements.filterbar.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && e.target.matches('input[data-filter]')) { this.applyFilters(); }
			});

			this.elements.tabs.addEventListener('click', (e) => {
				const b = e.target.closest('[data-tab]');
				if (b) { this.switchTab(b.dataset.tab); }
			});

			this.elements.riskFilter.addEventListener('change', (e) => {
				if (e.target.type === 'checkbox') { this.onRiskToggle(); }
			});

			this.elements.exportCsv.addEventListener('click', () => this.exportCsv());
			this.elements.exportHtml.addEventListener('click', () => this.exportHtml());
			this.elements.exportPng.addEventListener('click', () => {
				this.exportPng().catch((err) => this.showError(err instanceof Error ? err.message : 'Failed to export PNG.'));
			});

			this.elements.diskDetailClose.addEventListener('click', () => this.hideDetail());
			this.elements.resDetailClose.addEventListener('click', () => this.hideDetail());

			this.bindDetailSurface(this.elements.diskDetailSurface);
			this.bindDetailSurface(this.elements.resDetailSurface);

			// Runway interactions are delegated to the persistent surface so re-renders
			// mid-hover cannot orphan the tooltip or drop handlers.
			this.elements.runwaySurface.addEventListener('click', (e) => {
				const g = e.target.closest('.cap-runway-row');
				if (g) {
					this.switchTab('disks');
					this.openDetail('disk', g.dataset.id);
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
			surface.addEventListener('pointermove', (e) => this.onDetailHover(e, surface));
			surface.addEventListener('pointerleave', () => this.hideDetailHover());
		}

		applyInitialState() {
			const url = new URL(window.location.href);
			const p = (k) => url.searchParams.get(k);
			const ds = this.root.dataset;

			const lookback = Number(p('lookback') || ds.initialLookback || 0);
			if (LOOKBACKS.some((l) => l.days === lookback)) { this.lookbackDays = lookback; }

			const tab = p('tab') || ds.initialTab || '';
			if (['overview', 'disks', 'resources'].includes(tab)) { this.activeTab = tab; }

			this.filters.group = p('group') || '';
			this.filters.host = p('host') || '';
			this.filters.template = p('template') || '';
			this.filters.name = p('name') || '';
			['group', 'host', 'template', 'name'].forEach((k) => {
				this.elements.filterbar.querySelector(`[data-filter="${k}"]`).value = this.filters[k];
			});

			const risks = (p('risks') || '').split(',').filter((r) => RISK_BY_KEY[r]);
			if (risks.length) {
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
			if (tab === this.activeTab) { return; }
			this.activeTab = tab;
			this.syncTabs();
			this.updateLocationState();
			this.renderActiveTab();
			this.updateExportState();
		}

		syncTabs() {
			this.elements.tabs.querySelectorAll('[data-tab]').forEach((b) => {
				b.classList.toggle('is-active', b.dataset.tab === this.activeTab);
			});
			this.elements.panelOverview.hidden = this.activeTab !== 'overview';
			this.elements.panelDisks.hidden = this.activeTab !== 'disks';
			this.elements.panelResources.hidden = this.activeTab !== 'resources';
		}

		setLookback(days) {
			if (!LOOKBACKS.some((l) => l.days === days) || days === this.lookbackDays) { return; }
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

		syncLookbackButtons() {
			this.elements.lookback.querySelectorAll('[data-days]').forEach((b) => {
				b.classList.toggle('is-active', Number(b.dataset.days) === this.lookbackDays);
			});
		}

		// ---- filters ---------------------------------------------------------------
		applyFilters() {
			const fb = this.elements.filterbar;
			this.filters.group = fb.querySelector('[data-filter="group"]').value.trim();
			this.filters.host = fb.querySelector('[data-filter="host"]').value.trim();
			this.filters.template = fb.querySelector('[data-filter="template"]').value.trim();
			this.filters.name = fb.querySelector('[data-filter="name"]').value.trim();
			this.resolved = null;
			this.invalidate();
			this.updateFilterSummary();
			this.load();
		}

		clearFilters() {
			['group', 'host', 'template', 'name'].forEach((k) => {
				this.elements.filterbar.querySelector(`[data-filter="${k}"]`).value = '';
			});
			this.applyFilters();
		}

		updateFilterSummary() {
			const parts = [];
			if (this.filters.group) { parts.push(`group~"${this.filters.group}"`); }
			if (this.filters.host) { parts.push(`host~"${this.filters.host}"`); }
			if (this.filters.template) { parts.push(`template~"${this.filters.template}"`); }
			if (this.filters.name) { parts.push(`name ~ "${this.filters.name}"`); }
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
				mode: 'resolve',
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

		onRiskToggle() {
			this.activeRisks.clear();
			this.elements.riskFilter.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
				if (cb.checked) { this.activeRisks.add(cb.value); }
			});
			this.updateLocationState();
			this.renderActiveTab();
		}

		invalidate() {
			this.inv = this.emptyInventory();
			this.fc = new Map();
			this.fcTotal = 0;
			this.fcDone = 0;
			this.fcReady = false;
			this.hideDetail();
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
			if (payload && payload.error) { throw new Error(payload.error.message || 'Failed to load capacity data.'); }
			if (!response.ok) { throw new Error(`Request failed with HTTP ${response.status}.`); }
			return payload;
		}

		// ---- main load (inventory, then progressive forecasts) ---------------------
		async load() {
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
				this.inv.ready = true;
				this.inv.byId = new Map();
				this.inv.disks.forEach((d) => this.inv.byId.set(d.id, d));
				this.inv.resources.forEach((r) => this.inv.byId.set(r.id, r));

				const warnings = [];
				if (this.inv.meta.hosts_truncated) { warnings.push('The host scope was truncated — narrow the filter.'); }
				if (this.inv.meta.items_truncated) { warnings.push('The item scan hit its limit; some findings may be missing.'); }
				if (this.inv.meta.findings_truncated) { warnings.push('The findings list was truncated.'); }
				this.renderWarning(warnings.join(' '));

				this.renderActiveTab();
				await this.startForecasts(seq);
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
					id: finding.id, itemid: finding.itemid, kind: 'disk', tr: finding.tr, pr: finding.pr,
					ok: finding.status === 'OK',
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
				warn: finding.warn ? finding.warn.v : null,
				crit: finding.crit ? finding.crit.v : null
			};
		}

		async startForecasts(seq) {
			if (seq === undefined) {
				if (this.loadAbort) { this.loadAbort.abort(); }
				this.loadAbort = new AbortController();
				seq = ++this.loadSeq;
			}
			const signal = this.loadAbort ? this.loadAbort.signal : null;

			this.fc = new Map();
			this.fcReady = false;
			this.updateExportState();
			// An open detail card belongs to the previous forecast run; show its
			// loading state until the new forecast for it arrives.
			if (this.selected) {
				this.openDetail(this.selected.kind, this.selected.id);
			}

			// Highest-utilization findings first, so the riskiest rows resolve early.
			const disks = this.inv.disks.filter((d) => d.itemid)
				.slice().sort((a, b) => (b.pused ?? -1) - (a.pused ?? -1));
			const resources = this.inv.resources.filter((r) => r.itemid)
				.slice().sort((a, b) => (b.current ?? -1) - (a.current ?? -1));
			const queue = [
				...disks.map((d) => this.buildSpec(d, 'disk')),
				...resources.map((r) => this.buildSpec(r, 'res'))
			];
			this.fcTotal = queue.length;
			this.fcDone = 0;

			if (!queue.length) {
				this.fcReady = true;
				this.setLoading(false);
				this.renderActiveTab();
				this.updateExportState();
				return;
			}

			const nowSec = Math.floor(Date.now() / 1000);
			const timeFrom = nowSec - this.lookbackDays * 86400;
			const batchMax = Math.max(1, Number((this.inv.meta || {}).forecast_batch_max || 10));

			try {
				for (let i = 0; i < queue.length; i += batchMax) {
					if (seq !== this.loadSeq) { return; }
					this.setLoading(true, `Forecasting ${Math.min(i + batchMax, queue.length)}/${queue.length}…`);
					const batch = queue.slice(i, i + batchMax);
					const payload = await this.fetchData({
						mode: 'forecast', time_from: timeFrom, time_to: nowSec, specs: JSON.stringify(batch)
					}, signal);
					if (seq !== this.loadSeq) { return; }
					(payload.forecasts || []).forEach((f) => {
						if (f && f.id) { this.fc.set(f.id, f); }
					});
					this.fcDone = Math.min(i + batchMax, queue.length);
					this.renderActiveTab();
				}
				this.fcReady = true;
			}
			catch (err) {
				if (err && err.name === 'AbortError') { return; }
				if (seq === this.loadSeq) {
					// Mark the run finished so remaining rows settle to Unknown instead
					// of a permanent "Pending"/"forecasting X/Y" state.
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
				return 'Unknown';
			}
			if (!finding.itemid) { return 'Unknown'; }
			return this.fcReady ? 'Unknown' : 'Pending';
		}

		diskBreach(d, level) {
			const pct = level === 'crit' ? (d.crit_pct && d.crit_pct.v) : (d.warn_pct && d.warn_pct.v);
			const freeThr = level === 'crit' ? (d.crit_free && d.crit_free.v) : (d.warn_free && d.warn_free.v);
			return (d.pused != null && pct != null && d.pused > pct)
				|| (d.free != null && freeThr != null && freeThr > 0 && d.free < freeThr);
		}

		riskVisible(sev) {
			return sev === 'Pending' || this.activeRisks.has(sev);
		}

		nameMatch(finding) {
			const needle = this.filters.name.toLowerCase();
			if (!needle) { return true; }
			const hay = `${finding.host} ${finding.fs || ''} ${finding.metric || ''}`.toLowerCase();
			return hay.includes(needle);
		}

		visibleDisks() {
			return this.inv.disks.filter((d) => this.nameMatch(d) && this.riskVisible(this.severityOf(d)));
		}

		visibleResources() {
			return this.inv.resources.filter((r) => this.nameMatch(r) && this.riskVisible(this.severityOf(r)));
		}

		// ---- rendering -------------------------------------------------------------
		renderActiveTab() {
			this.tooltip.hidden = true; // re-render invalidates whatever was hovered
			this.renderMeta();
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
			this.elements.meta.textContent =
				`Scope: ${m.hosts_analyzed || 0} hosts • ${this.inv.disks.length} filesystems • `
				+ `${this.inv.resources.length} CPU/memory metrics • Lookback: ${lb ? lb.label : this.lookbackDays + 'd'}`
				+ progress;
		}

		riskCounts() {
			const counts = Object.fromEntries(RISKS.map((r) => [r.key, 0]));
			[...this.inv.disks, ...this.inv.resources].forEach((f) => {
				const sev = this.severityOf(f);
				if (counts[sev] !== undefined) { counts[sev]++; }
			});
			return counts;
		}

		renderOverview() {
			const counts = this.riskCounts();
			const actions = counts.Critical + counts.High + counts.Medium;
			const cards = [
				{value: (this.inv.meta || {}).hosts_analyzed || 0, label: 'Servers analyzed'},
				{value: this.inv.disks.length, label: 'Filesystems'},
				{value: this.inv.resources.length, label: 'CPU / memory metrics'},
				{value: actions, label: 'Capacity actions'},
				{value: counts.Critical, label: 'Critical risks', critical: counts.Critical > 0}
			];
			this.elements.cards.innerHTML = cards.map((c) =>
				`<div class="cap-stat"><div class="cap-stat-value${c.critical ? ' is-critical' : ''}">${c.value}</div><div class="cap-stat-label">${this.escapeHtml(c.label)}</div></div>`
			).join('');

			this._runwayRows = this.runwayRows();
			this.elements.runwaySurface.innerHTML = this.buildRunwaySvg(this._runwayRows, this.getPalette(), true);
			this.elements.distSurface.innerHTML = this.buildDistributionSvg(counts, this.getPalette());
			this.renderTopRisks();
			this.renderQuality();
		}

		runwayRows() {
			const rows = [];
			this.visibleDisks().forEach((d) => {
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
				svg += `<g${interactive ? ` class="cap-runway-row" data-id="${row.id}" style="cursor:pointer"` : ''}>`;
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

		renderTopRisks() {
			const rows = [];
			this.visibleDisks().forEach((d) => {
				const fc = this.fc.get(d.id);
				rows.push({
					kind: 'disk', id: d.id, sev: this.severityOf(d), host: d.host,
					resource: d.fs, current: this.fmtPct(d.pused),
					next: fc && fc.eta ? fc.eta.next_days : null,
					nextLabel: fc && fc.eta ? this.fmtDays(fc.eta.next_days) : '—',
					confidence: fc ? (fc.confidence || '—') : '…',
					action: fc ? (fc.recommendation || '') : ''
				});
			});
			this.visibleResources().forEach((r) => {
				const fc = this.fc.get(r.id);
				rows.push({
					kind: 'res', id: r.id, sev: this.severityOf(r), host: r.host,
					resource: r.rtype, current: this.fmtPct(r.current),
					next: null, nextLabel: '—',
					confidence: fc ? 'Baseline' : '…',
					action: fc ? (fc.recommendation || '') : ''
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
				<tr class="is-clickable" data-kind="${r.kind}" data-id="${r.id}">
					<td>${this.riskPill(r.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(r.host)}</div><div class="cap-cell-sub">${this.escapeHtml(r.resource)}</div></td>
					<td class="cap-num">${this.escapeHtml(r.current)}</td>
					<td class="cap-num">${this.etaCell(r.next, r.nextLabel)}</td>
					<td>${this.escapeHtml(r.confidence)}</td>
					<td>${this.escapeHtml(r.action)}</td>
				</tr>`).join('');
			this.elements.topRisks.innerHTML = `
				<div class="cap-table-scroll"><table class="cap-table">
					<thead><tr><th>Risk</th><th>Host / resource</th><th class="cap-num">Current</th><th class="cap-num">Next threshold</th><th>Confidence</th><th>Recommended action</th></tr></thead>
					<tbody>${body}</tbody>
				</table></div>`;
			this.elements.topRisks.querySelectorAll('tr.is-clickable').forEach((tr) => {
				tr.addEventListener('click', () => {
					this.switchTab(tr.dataset.kind === 'disk' ? 'disks' : 'resources');
					this.openDetail(tr.dataset.kind, tr.dataset.id);
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
				<div class="cap-table-scroll"><table class="cap-table">
					<thead><tr><th>Level</th><th>Host</th><th>Resource</th><th>Issue</th><th>Detail</th></tr></thead>
					<tbody>${body}</tbody>
				</table></div>${note}`;
		}

		// ---- filesystems tab -------------------------------------------------------
		diskRow(d) {
			const fc = this.fc.get(d.id) || null;
			return {
				id: d.id, d, fc,
				sev: this.severityOf(d),
				risk: RISK_ORDER[this.severityOf(d)] ?? -1,
				host: d.host, fs: d.fs,
				pused: d.pused, free: d.free,
				growth: fc ? fc.growth_day : null,
				warn: fc && fc.eta ? fc.eta.warn_days : null,
				crit: fc && fc.eta ? fc.eta.crit_days : null,
				full: fc && fc.eta ? fc.eta.full_days : null,
				conf: fc ? (fc.confidence || '') : ''
			};
		}

		renderDisks() {
			const rows = this.visibleDisks().map((d) => this.diskRow(d));
			if (!rows.length) {
				this.elements.disksBody.innerHTML = '<div class="cap-empty">No filesystems matched the selected scope and filters.</div>';
				return;
			}
			this.sortRows(rows, this.sort.disks);
			const sortInd = (key) => this.sort.disks.key === key ? (this.sort.disks.dir === 'asc' ? ' ▲' : ' ▼') : '';
			const shown = rows.slice(0, 500);

			const body = shown.map((r) => {
				const usage = r.pused != null
					? `<div class="cap-usage"><span class="cap-usage-val">${this.fmtPct(r.pused)}</span><div class="cap-usage-track"><div class="cap-usage-fill risk-${this.usageRisk(r).toLowerCase()}" style="width:${Math.min(100, Math.max(1, r.pused))}%"></div></div></div>`
					: '<span class="cap-muted">N/A</span>';
				const selected = this.selected && this.selected.kind === 'disk' && this.selected.id === r.id;
				return `
				<tr class="is-clickable${selected ? ' is-selected' : ''}" data-id="${r.id}">
					<td>${this.riskPill(r.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(r.host)}</div><div class="cap-cell-sub">${this.escapeHtml(r.fs)}${r.d.kind === 'Remote' ? ' · remote' : ''}</div></td>
					<td>${usage}</td>
					<td class="cap-num">${this.fmtBytes(r.free)}</td>
					<td class="cap-num">${r.growth != null ? this.escapeHtml(this.fmtBytes(r.growth) + '/day') : '<span class="cap-muted">No growth</span>'}</td>
					<td class="cap-num">${this.etaCell(r.warn, this.fmtDays(r.warn))}</td>
					<td class="cap-num">${this.etaCell(r.crit, this.fmtDays(r.crit))}</td>
					<td class="cap-num">${this.etaCell(r.full, this.fmtDays(r.full))}</td>
					<td>${this.escapeHtml(r.conf || (r.fc ? '' : (this.fcReady ? '—' : '…')))}${r.fc && r.fc.accelerating ? ' <span title="Recent growth is accelerating">⚠</span>' : ''}</td>
				</tr>`;
			}).join('');

			const note = rows.length > 500 ? `<div class="cap-footer-note">Showing the first 500 of ${rows.length} filesystems (sorted). Narrow the filter for the rest.</div>` : '';
			this.elements.disksBody.innerHTML = `
				<div class="cap-table-scroll"><table class="cap-table" data-table="disks">
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
				</table></div>${note}`;

			this.bindTable(this.elements.disksBody, 'disks', 'disk');
		}

		usageRisk(row) {
			const d = row.d;
			if (d.crit_pct && d.crit_pct.v != null && row.pused > d.crit_pct.v) { return 'Critical'; }
			if (d.warn_pct && d.warn_pct.v != null && row.pused > d.warn_pct.v) { return 'High'; }
			return 'Healthy';
		}

		// ---- CPU & memory tab ------------------------------------------------------
		resourceRow(r) {
			const fc = this.fc.get(r.id) || null;
			const sel = fc && fc.sel ? fc.windows[fc.sel] : null;
			return {
				id: r.id, r, fc,
				sev: this.severityOf(r),
				risk: RISK_ORDER[this.severityOf(r)] ?? -1,
				host: r.host, rtype: r.rtype,
				current: r.current,
				avg: sel ? sel.avg : null,
				p95: sel ? sel.p95 : null,
				aboveWarn: sel ? sel.above_warn : null,
				aboveCrit: sel ? sel.above_crit : null,
				action: fc ? (fc.recommendation || '') : ''
			};
		}

		renderResources() {
			const rows = this.visibleResources().map((r) => this.resourceRow(r));
			if (!rows.length) {
				this.elements.resourcesBody.innerHTML = '<div class="cap-empty">No CPU or memory metrics matched the selected scope and filters.</div>';
				return;
			}
			this.sortRows(rows, this.sort.resources);
			const sortInd = (key) => this.sort.resources.key === key ? (this.sort.resources.dir === 'asc' ? ' ▲' : ' ▼') : '';
			const shown = rows.slice(0, 500);

			const body = shown.map((row) => {
				const selected = this.selected && this.selected.kind === 'res' && this.selected.id === row.id;
				const provisioned = row.r.provisioned != null
					? (row.r.unit === 'bytes' ? this.fmtBytes(row.r.provisioned) : `${Math.round(row.r.provisioned)} ${row.r.unit || ''}`)
					: '—';
				return `
				<tr class="is-clickable${selected ? ' is-selected' : ''}" data-id="${row.id}">
					<td>${this.riskPill(row.sev)}</td>
					<td><div class="cap-cell-name">${this.escapeHtml(row.host)}</div><div class="cap-cell-sub">${this.escapeHtml(row.rtype)} · ${this.escapeHtml(provisioned)}</div></td>
					<td class="cap-num">${this.fmtPct(row.current)}</td>
					<td class="cap-num">${this.fmtPct(row.avg)}</td>
					<td class="cap-num">${this.fmtPct(row.p95)}</td>
					<td class="cap-num">${this.fmtPct(row.aboveWarn)}</td>
					<td class="cap-num">${this.fmtPct(row.aboveCrit)}</td>
					<td>${this.escapeHtml(row.action || (this.fcReady ? '—' : '…'))}</td>
				</tr>`;
			}).join('');

			const note = rows.length > 500 ? `<div class="cap-footer-note">Showing the first 500 of ${rows.length} metrics (sorted).</div>` : '';
			this.elements.resourcesBody.innerHTML = `
				<div class="cap-table-scroll"><table class="cap-table" data-table="resources">
					<thead><tr>
						<th class="cap-sortable" data-sort="risk">Risk${sortInd('risk')}</th>
						<th class="cap-sortable" data-sort="host">Host / resource${sortInd('host')}</th>
						<th class="cap-sortable cap-num" data-sort="current">Current${sortInd('current')}</th>
						<th class="cap-sortable cap-num" data-sort="avg">Avg${sortInd('avg')}</th>
						<th class="cap-sortable cap-num" data-sort="p95">p95${sortInd('p95')}</th>
						<th class="cap-sortable cap-num" data-sort="aboveWarn">Above review${sortInd('aboveWarn')}</th>
						<th class="cap-sortable cap-num" data-sort="aboveCrit">Above alarm${sortInd('aboveCrit')}</th>
						<th>Assessment</th>
					</tr></thead>
					<tbody>${body}</tbody>
				</table></div>${note}`;

			this.bindTable(this.elements.resourcesBody, 'resources', 'res');
		}

		bindTable(container, tableKey, kind) {
			container.querySelectorAll('th.cap-sortable').forEach((th) => {
				th.addEventListener('click', () => {
					const key = th.dataset.sort;
					const sort = this.sort[tableKey];
					if (sort.key === key) { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'; }
					else { sort.key = key; sort.dir = (key === 'host' || key === 'conf') ? 'asc' : 'desc'; }
					if (tableKey === 'disks') { this.renderDisks(); } else { this.renderResources(); }
				});
			});
			container.querySelectorAll('tr.is-clickable').forEach((tr) => {
				tr.addEventListener('click', () => this.openDetail(kind, tr.dataset.id));
			});
		}

		sortRows(rows, sort) {
			const {key, dir} = sort;
			const mul = dir === 'asc' ? 1 : -1;
			const nullsLast = ['warn', 'crit', 'full', 'growth'];
			rows.sort((a, b) => {
				let av = a[key];
				let bv = b[key];
				if (key === 'host' || key === 'conf') {
					av = String(av || '').toLowerCase();
					bv = String(bv || '').toLowerCase();
					return av < bv ? -mul : av > bv ? mul : 0;
				}
				if (nullsLast.includes(key)) {
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
		openDetail(kind, id) {
			const finding = this.inv.byId.get(id);
			if (!finding) { return; }
			this.selected = {kind, id};
			this.detailPending = !this.fc.get(id);
			if (kind === 'disk') { this.renderDisks(); this.renderDiskDetail(finding); }
			else { this.renderResources(); this.renderResourceDetail(finding); }
		}

		hideDetail() {
			this.selected = null;
			this.detailPending = false;
			this.detailGeom = null;
			if (this.elements.diskDetail) {
				this.elements.diskDetail.hidden = true;
				this.elements.resDetail.hidden = true;
			}
		}

		renderDiskDetail(d) {
			const fc = this.fc.get(d.id);
			const els = this.elements;
			els.diskDetail.hidden = false;
			els.diskDetailTitle.textContent = `${d.host} — ${d.fs}`;
			els.resDetail.hidden = true;

			if (!fc || fc.status !== 'ok') {
				els.diskDetailSubtitle.textContent = fc
					? (fc.status === 'denied' ? 'This item is not readable with your permissions.' : 'No trend or history data is available for this filesystem.')
					: (this.fcReady ? 'No forecast is available.' : 'The forecast is still loading…');
				els.diskDetailStats.innerHTML = '';
				els.diskDetailLegend.innerHTML = '';
				els.diskDetailSurface.innerHTML = '<div class="cap-empty">No chart data.</div>';
				this.detailGeom = null;
				els.diskDetail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
				return;
			}

			const windowLabel = fc.sel ? (WINDOW_LABELS[fc.sel] || fc.sel) : 'n/a';
			const sel = fc.sel ? fc.windows[fc.sel] : null;
			els.diskDetailSubtitle.textContent =
				`Historical used capacity with the projected trend. Model window: ${windowLabel}`
				+ (sel ? ` (${sel.days} days of data, ${sel.cov}% coverage${sel.r2 != null ? `, R² ${sel.r2}` : ''})` : '')
				+ `${fc.source === 'history' ? ' — short raw-history fallback.' : '.'}`;

			const stats = [
				`Now: <b>${this.fmtPct(d.pused)}</b> used, <b>${this.fmtBytes(d.free)}</b> free of <b>${this.fmtBytes(d.total)}</b>`,
				`Growth: <b>${fc.growth_day != null ? this.fmtBytes(fc.growth_day) + '/day' : 'no sustained growth'}</b>${fc.accelerating ? ' (accelerating)' : ''}`,
				`Warning: <b>${this.fmtDays(fc.eta.warn_days)}</b>${fc.eta.warn_date ? ` (${this.fmtDate(fc.eta.warn_date)})` : ''}`,
				`Critical: <b>${this.fmtDays(fc.eta.crit_days)}</b>${fc.eta.crit_date ? ` (${this.fmtDate(fc.eta.crit_date)})` : ''}`,
				`Full: <b>${this.fmtDays(fc.eta.full_days)}</b>${fc.eta.full_date ? ` (${this.fmtDate(fc.eta.full_date)})` : ''}`,
				`Confidence: <b>${fc.confidence}</b>`,
				`Thresholds: warn ${this.thresholdLabel(d.warn_pct, '%')} · crit ${this.thresholdLabel(d.crit_pct, '%')}`
					+ `${d.warn_free && d.warn_free.v > 0 ? ` · warn free ${this.fmtBytes(d.warn_free.v)}` : ''}`
					+ `${d.crit_free && d.crit_free.v > 0 ? ` · crit free ${this.fmtBytes(d.crit_free.v)}` : ''}`
			];
			els.diskDetailStats.innerHTML = stats.map((s) => `<span>${s}</span>`).join('');

			const spec = this.diskChartSpec(d, fc);
			this.renderDetailChart(els.diskDetailSurface, els.diskDetailLegend, spec);
			els.diskDetail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
		}

		thresholdLabel(t, suffix) {
			if (!t || t.v == null) { return 'n/a'; }
			return `${this.trimNum(t.v)}${suffix}${t.fb ? '*' : ''}`;
		}

		diskChartSpec(d, fc) {
			// Chart in percent when a capacity is known; otherwise raw GiB.
			let capacity = null;
			if (d.used != null && d.pused != null && d.pused > 0) { capacity = d.used / (d.pused / 100); }
			else if (d.total != null) { capacity = d.total; }
			const asPct = capacity != null && capacity > 0;
			const scale = asPct ? 100 / capacity : 1 / GIB;
			const series = (fc.series || []).map((p) => ({
				clock: p[0], min: p[1] * scale, avg: p[2] * scale, max: p[3] * scale
			}));
			const currentValue = asPct
				? (d.pused != null ? d.pused : (series.length ? series[series.length - 1].avg : null))
				: (d.used != null ? d.used / GIB : null);
			const slope = fc.growth_day != null ? fc.growth_day * scale : null;
			const thresholds = [];
			if (asPct) {
				if (d.warn_pct && d.warn_pct.v != null) { thresholds.push({value: d.warn_pct.v, label: `Warning ${this.trimNum(d.warn_pct.v)}%`, kind: 'warn'}); }
				if (d.crit_pct && d.crit_pct.v != null) { thresholds.push({value: d.crit_pct.v, label: `Critical ${this.trimNum(d.crit_pct.v)}%`, kind: 'crit'}); }
			}
			return {
				title: asPct ? 'Used capacity (%)' : 'Used capacity (GiB)',
				unit: asPct ? '%' : ' GiB',
				yMax: asPct ? 100 : null,
				series, currentValue, slope, thresholds,
				projectionLabel: 'Projected growth'
			};
		}

		renderResourceDetail(r) {
			const fc = this.fc.get(r.id);
			const els = this.elements;
			els.resDetail.hidden = false;
			els.diskDetail.hidden = true;
			els.resDetailTitle.textContent = `${r.host} — ${r.rtype}`;

			if (!fc || fc.status !== 'ok') {
				els.resDetailSubtitle.textContent = fc
					? (fc.status === 'denied' ? 'This item is not readable with your permissions.' : 'No trend or history data is available for this metric.')
					: (this.fcReady ? 'No forecast is available.' : 'The forecast is still loading…');
				els.resDetailStats.innerHTML = '';
				els.resDetailLegend.innerHTML = '';
				els.resDetailSurface.innerHTML = '<div class="cap-empty">No chart data.</div>';
				this.detailGeom = null;
				els.resDetail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
				return;
			}

			const windowLabel = fc.sel ? (WINDOW_LABELS[fc.sel] || fc.sel) : 'n/a';
			const sel = fc.sel ? fc.windows[fc.sel] : null;
			els.resDetailSubtitle.textContent =
				`Utilization baseline and trend. Assessment window: ${windowLabel}`
				+ (sel ? ` (${sel.days} days of data, ${sel.cov}% coverage)` : '') + '.';

			const stats = [
				`Now: <b>${this.fmtPct(r.current)}</b>`,
				sel ? `Average: <b>${this.fmtPct(sel.avg)}</b> · p95: <b>${this.fmtPct(sel.p95)}</b> · peak: <b>${this.fmtPct(sel.peak)}</b>` : '',
				sel && sel.above_warn != null ? `Time above review: <b>${this.fmtPct(sel.above_warn)}</b>` : '',
				sel && sel.above_crit != null ? `Time above alarm: <b>${this.fmtPct(sel.above_crit)}</b>` : '',
				`Review level: ${this.thresholdLabel(r.warn, '%')} · Alarm level: ${this.thresholdLabel(r.crit, '%')}`,
				fc.growth_pct_day != null ? `Baseline drift: <b>${fc.growth_pct_day >= 0 ? '+' : ''}${this.trimNum(fc.growth_pct_day * 30)} pp/month</b>` : ''
			].filter(Boolean);
			els.resDetailStats.innerHTML = stats.map((s) => `<span>${s}</span>`).join('');

			const thresholds = [];
			if (r.warn && r.warn.v != null) { thresholds.push({value: r.warn.v, label: `Review ${this.trimNum(r.warn.v)}%`, kind: 'warn'}); }
			if (r.crit && r.crit.v != null) { thresholds.push({value: r.crit.v, label: `Alarm ${this.trimNum(r.crit.v)}%`, kind: 'crit'}); }
			const spec = {
				title: `${r.rtype} utilization (%)`,
				unit: '%',
				yMax: 100,
				series: (fc.series || []).map((p) => ({clock: p[0], min: p[1], avg: p[2], max: p[3]})),
				currentValue: r.current,
				slope: fc.growth_pct_day,
				thresholds,
				projectionLabel: 'Projected baseline'
			};
			this.renderDetailChart(els.resDetailSurface, els.resDetailLegend, spec);
			els.resDetail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
		}

		renderDetailChart(surface, legendEl, spec) {
			if (!spec.series.length) {
				surface.innerHTML = '<div class="cap-empty">No series data.</div>';
				legendEl.innerHTML = '';
				this.detailGeom = null;
				return;
			}
			const palette = this.getPalette();
			legendEl.innerHTML = [
				`<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${palette.line}"></span>Daily average</span>`,
				`<span class="cap-legend-item"><span class="cap-legend-swatch is-band" style="background:${palette.line}"></span>Daily min–max</span>`,
				spec.slope != null ? `<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${palette.projection}"></span>${this.escapeHtml(spec.projectionLabel)}</span>` : '',
				...spec.thresholds.map((t) => `<span class="cap-legend-item"><span class="cap-legend-swatch" style="background:${t.kind === 'crit' ? palette.crit : palette.warn}"></span>${this.escapeHtml(t.label)}</span>`)
			].filter(Boolean).join('');

			const built = this.buildUsageSvg(spec, palette, true);
			surface.innerHTML = built.svg;
			this.detailGeom = built.geom;
			const svg = surface.querySelector('svg');
			this.detailEls = svg ? {svg, crosshair: svg.querySelector('.cap-crosshair'), surface} : null;
		}

		buildUsageSvg(spec, palette, interactive) {
			const margin = {top: 26, right: 30, bottom: 64, left: 56};
			const height = 380;
			const plotHeight = height - margin.top - margin.bottom;
			const series = spec.series;
			const nowSec = Math.floor(Date.now() / 1000);

			const projected = spec.slope != null && spec.currentValue != null;
			const tMin = series[0].clock;
			const tMax = projected ? nowSec + HORIZON_DAYS * 86400 : Math.max(nowSec, series[series.length - 1].clock);
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

			// Projection.
			if (projected) {
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
					series, spec, nowSec, projEnd, tMax}
			};
		}

		onDetailHover(e, surface) {
			const geom = this.detailGeom;
			const svg = surface.querySelector('svg');
			if (!geom || !svg || !this.detailEls || this.detailEls.surface !== surface) { return; }
			const rect = svg.getBoundingClientRect();
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
				else if (this.activeTab === 'resources') { this.exportResourcesCsv(); }
				else { this.exportActionsCsv(); }
			}
			catch (err) {
				this.showError(err instanceof Error ? err.message : 'Failed to export CSV.');
			}
		}

		exportActionsCsv() {
			const rows = [['Severity', 'Category', 'Host', 'Resource', 'Current', 'Next threshold',
				'Critical threshold', 'Full', 'Confidence', 'Action']];
			this.visibleDisks().forEach((d) => {
				const fc = this.fc.get(d.id);
				rows.push([this.severityOf(d), 'Filesystem', d.host, d.fs, this.fmtPct(d.pused),
					fc && fc.eta ? this.fmtDays(fc.eta.next_days) : '', fc && fc.eta ? this.fmtDays(fc.eta.crit_days) : '',
					fc && fc.eta ? this.fmtDays(fc.eta.full_days) : '', fc ? fc.confidence || '' : '',
					fc ? fc.recommendation || '' : '']);
			});
			this.visibleResources().forEach((r) => {
				const fc = this.fc.get(r.id);
				rows.push([this.severityOf(r), r.rtype, r.host, r.metric || r.rtype, this.fmtPct(r.current),
					'', '', '', fc ? 'Baseline' : '', fc ? fc.recommendation || '' : '']);
			});
			this.downloadCsv(rows, 'capacity-actions');
		}

		exportDisksCsv() {
			const rows = [['Severity', 'Host', 'OS', 'Filesystem', 'Kind', 'Total GiB', 'Used GiB', 'Free GiB',
				'Used %', 'Warn %', 'Critical %', 'Warn free GiB', 'Critical free GiB', 'Growth GiB/day',
				'Model window', 'Confidence', 'Accelerating', 'Days to warning', 'Warning date',
				'Days to critical', 'Critical date', 'Days to full', 'Full date', 'Next threshold basis',
				'Data status', 'Item key', 'Recommendation']];
			this.visibleDisks().map((d) => this.diskRow(d)).sort((a, b) => b.risk - a.risk).forEach((r) => {
				const d = r.d;
				const fc = r.fc;
				const gib = (v) => v != null ? (v / GIB).toFixed(2) : '';
				rows.push([r.sev, d.host, d.os, d.fs, d.kind, gib(d.total), gib(d.used), gib(d.free),
					d.pused != null ? d.pused.toFixed(2) : '', d.warn_pct && d.warn_pct.v != null ? d.warn_pct.v : '',
					d.crit_pct && d.crit_pct.v != null ? d.crit_pct.v : '',
					d.warn_free && d.warn_free.v > 0 ? gib(d.warn_free.v) : '',
					d.crit_free && d.crit_free.v > 0 ? gib(d.crit_free.v) : '',
					fc && fc.growth_day != null ? (fc.growth_day / GIB).toFixed(4) : '',
					fc && fc.sel ? WINDOW_LABELS[fc.sel] || fc.sel : '', fc ? fc.confidence || '' : '',
					fc && fc.accelerating ? 'Yes' : 'No',
					fc && fc.eta && fc.eta.warn_days != null ? fc.eta.warn_days : '',
					fc && fc.eta && fc.eta.warn_date ? this.fmtIsoDate(fc.eta.warn_date) : '',
					fc && fc.eta && fc.eta.crit_days != null ? fc.eta.crit_days : '',
					fc && fc.eta && fc.eta.crit_date ? this.fmtIsoDate(fc.eta.crit_date) : '',
					fc && fc.eta && fc.eta.full_days != null ? fc.eta.full_days : '',
					fc && fc.eta && fc.eta.full_date ? this.fmtIsoDate(fc.eta.full_date) : '',
					fc && fc.eta ? fc.eta.next_basis || '' : '', d.status, d.item_key || '',
					fc ? fc.recommendation || '' : '']);
			});
			this.downloadCsv(rows, 'capacity-filesystems');
		}

		exportResourcesCsv() {
			const rows = [['Severity', 'Host', 'OS', 'Resource', 'Provisioned', 'Unit', 'Current %',
				'Window', 'Average %', 'p95 %', 'Peak %', 'Above review %', 'Above alarm %',
				'Review %', 'Alarm %', 'Data status', 'Item key', 'Recommendation']];
			this.visibleResources().map((r) => this.resourceRow(r)).sort((a, b) => b.risk - a.risk).forEach((row) => {
				const r = row.r;
				const fc = row.fc;
				const sel = fc && fc.sel ? fc.windows[fc.sel] : null;
				let provisioned = r.provisioned;
				let unit = r.unit || '';
				if (unit === 'bytes' && provisioned != null) { provisioned = (provisioned / GIB).toFixed(1); unit = 'GiB'; }
				rows.push([row.sev, r.host, r.os, r.rtype, provisioned != null ? provisioned : '', unit,
					r.current != null ? r.current.toFixed(2) : '',
					fc && fc.sel ? WINDOW_LABELS[fc.sel] || fc.sel : '',
					sel && sel.avg != null ? sel.avg.toFixed(2) : '', sel && sel.p95 != null ? sel.p95.toFixed(2) : '',
					sel && sel.peak != null ? sel.peak.toFixed(2) : '',
					sel && sel.above_warn != null ? sel.above_warn.toFixed(2) : '',
					sel && sel.above_crit != null ? sel.above_crit.toFixed(2) : '',
					r.warn && r.warn.v != null ? r.warn.v : '', r.crit && r.crit.v != null ? r.crit.v : '',
					r.status, r.item_key || '', fc ? fc.recommendation || '' : '']);
			});
			this.downloadCsv(rows, 'capacity-resources');
		}

		downloadCsv(rows, prefix) {
			const csv = rows.map((r) => r.map((v) => this.csvEscape(v)).join(',')).join('\n');
			this.downloadBlob(new Blob([csv], {type: 'text/csv;charset=utf-8;'}), this.buildFileName(prefix, 'csv'));
		}

		buildReportHtml() {
			const metaText = this.escapeHtml(this.elements.meta.textContent || '');
			const filterText = this.escapeHtml(this.elements.filterSummary.textContent || '');
			const counts = this.riskCounts();
			const cardsHtml = `<div class="cards">${[
				[`${(this.inv.meta || {}).hosts_analyzed || 0}`, 'Servers analyzed'],
				[`${this.inv.disks.length}`, 'Filesystems'],
				[`${this.inv.resources.length}`, 'CPU / memory metrics'],
				[`${counts.Critical + counts.High + counts.Medium}`, 'Capacity actions'],
				[`${counts.Critical}`, 'Critical risks']
			].map(([v, l]) => `<div class="stat"><div class="v">${v}</div><div class="l">${l}</div></div>`).join('')}</div>`;

			const runwaySvg = this.buildRunwaySvg(this.runwayRows(), PALETTE_LIGHT, false);
			const distSvg = this.buildDistributionSvg(counts, PALETTE_LIGHT);

			const diskRows = this.visibleDisks().map((d) => this.diskRow(d)).sort((a, b) => b.risk - a.risk).slice(0, 500);
			const diskTable = diskRows.length ? `<table><thead><tr><th>Risk</th><th>Host</th><th>Filesystem</th><th>Used</th><th>Free</th><th>Growth</th><th>Warning ETA</th><th>Critical ETA</th><th>Full ETA</th><th>Confidence</th></tr></thead><tbody>${
				diskRows.map((r) => `<tr><td>${this.riskPill(r.sev)}</td><td>${this.escapeHtml(r.host)}</td><td>${this.escapeHtml(r.fs)}</td><td>${this.fmtPct(r.pused)}</td><td>${this.fmtBytes(r.free)}</td><td>${r.growth != null ? this.fmtBytes(r.growth) + '/day' : '—'}</td><td>${this.fmtDays(r.warn)}</td><td>${this.fmtDays(r.crit)}</td><td>${this.fmtDays(r.full)}</td><td>${this.escapeHtml(r.conf || '—')}</td></tr>`).join('')
			}</tbody></table>` : '<p>No filesystem findings.</p>';

			const resRows = this.visibleResources().map((r) => this.resourceRow(r)).sort((a, b) => b.risk - a.risk).slice(0, 500);
			const resTable = resRows.length ? `<table><thead><tr><th>Risk</th><th>Host</th><th>Resource</th><th>Current</th><th>Avg</th><th>p95</th><th>Above review</th><th>Above alarm</th><th>Assessment</th></tr></thead><tbody>${
				resRows.map((row) => `<tr><td>${this.riskPill(row.sev)}</td><td>${this.escapeHtml(row.host)}</td><td>${this.escapeHtml(row.rtype)}</td><td>${this.fmtPct(row.current)}</td><td>${this.fmtPct(row.avg)}</td><td>${this.fmtPct(row.p95)}</td><td>${this.fmtPct(row.aboveWarn)}</td><td>${this.fmtPct(row.aboveCrit)}</td><td>${this.escapeHtml(row.action || '—')}</td></tr>`).join('')
			}</tbody></table>` : '<p>No CPU/memory findings.</p>';

			return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Capacity Planning Report</title>
				<style>
				body{font-family:Arial,Helvetica,sans-serif;padding:24px;color:#1f2b3a;}
				h1{margin:0 0 6px;} .meta,.filter{color:#5c6b7a;font-size:13px;margin:0 0 6px;}
				.card{border:1px solid #dfe4ec;border-radius:6px;padding:16px;margin:16px 0;}
				.cards{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;}
				.stat{border:1px solid #dfe4ec;border-radius:6px;padding:12px 22px;text-align:center;min-width:120px;}
				.stat .v{font-size:24px;font-weight:700;} .stat .l{font-size:10px;color:#5c6b7a;text-transform:uppercase;letter-spacing:0.06em;margin-top:4px;}
				table{width:100%;border-collapse:collapse;} th,td{border-bottom:1px solid #e7edf4;padding:8px 10px;text-align:left;font-size:13px;}
				th{font-weight:700;} svg{max-width:100%;height:auto;}
				.cap-risk-pill{display:inline-block;min-width:62px;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:700;text-align:center;color:#fff;}
				.cap-risk-pill.risk-watch{color:#101828;}
				.risk-critical{background:#b42318}.risk-high{background:#d92d20}.risk-medium{background:#f79009}.risk-watch{background:#fec84b}.risk-healthy{background:#12b76a}.risk-unknown{background:#667085}
				</style></head><body>
				<h1>Capacity Planning &amp; Prediction Report</h1>
				<div class="meta">${metaText}</div>${filterText ? `<div class="filter">${filterText}</div>` : ''}
				${cardsHtml}
				<div class="card"><h2>Capacity runway</h2>${runwaySvg}</div>
				<div class="card"><h2>Risk distribution</h2>${distSvg}</div>
				<div class="card"><h2>Filesystem capacity forecast</h2>${diskTable}</div>
				<div class="card"><h2>CPU and memory baselines</h2>${resTable}</div>
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
							currentValue: finding.current, slope: fc.growth_pct_day, thresholds,
							projectionLabel: 'Projected baseline'
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
			const ready = this.inv.ready;
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
			setOrDel('risks', this.activeRisks.size < RISKS.length
				? RISKS.map((r) => r.key).filter((k) => this.activeRisks.has(k)).join(',')
				: '');
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
			return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}
	}
})();
