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

	document.addEventListener('DOMContentLoaded', () => {
		const root = document.getElementById('incident-timeline-root');

		if (!root) {
			return;
		}

		new IncidentTimelineApp(root).init();
	});

	class IncidentTimelineApp {
		constructor(root) {
			this.root = root;
			this.dataUrl = root.dataset.dataUrl || 'zabbix.php?action=incident.timeline.data';
			this.initialMonth = root.dataset.initialMonth || '';
			this.currentData = null;
			this.requestRange = null;
			this.activeSeverities = new Set(SEVERITIES.map((s) => s.key));
			this.elements = {};
		}

		static buildMonthOptions(count) {
			const options = [];
			const now = new Date();

			for (let i = 0; i < count; i++) {
				const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
				const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
				const label = date.toLocaleString(undefined, {year: 'numeric', month: 'long'});
				options.push({value, label});
			}

			return options;
		}

		static monthToRange(monthValue) {
			const [year, month] = monthValue.split('-').map(Number);
			const from = new Date(year, month - 1, 1, 0, 0, 0, 0);
			const to = new Date(year, month, 0, 23, 59, 59, 999);

			return {
				time_from: Math.floor(from.getTime() / 1000),
				time_to: Math.floor(to.getTime() / 1000),
				from,
				to
			};
		}

		init() {
			this.renderShell();
			this.bindEvents();
			this.applyInitialState();
			this.loadData();
		}

		renderShell() {
			const monthOptions = IncidentTimelineApp.buildMonthOptions(24);

			this.root.innerHTML = `
				<div class="incident-shell">
					<div class="incident-toolbar">
						<div class="incident-toolbar-group">
							<div class="incident-field">
								<label for="incident-month">Month</label>
								<div class="incident-month-nav">
									<button type="button" class="incident-btn incident-nav-btn" data-role="prev-month" title="Previous month">&lsaquo;</button>
									<select id="incident-month">
										${monthOptions.map((o) => `<option value="${o.value}">${this.escapeHtml(o.label)}</option>`).join('')}
									</select>
									<button type="button" class="incident-btn incident-nav-btn" data-role="next-month" title="Next month">&rsaquo;</button>
								</div>
							</div>
						</div>
						<div class="incident-actions">
							<button type="button" class="incident-btn" data-role="export-png" disabled>Export PNG</button>
							<button type="button" class="incident-btn" data-role="export-pdf" disabled>Export PDF</button>
							<button type="button" class="incident-btn" data-role="export-csv" disabled>Export CSV</button>
						</div>
					</div>

					<div class="incident-severity-filter" data-role="severity-filter">
						<span class="incident-severity-filter-label">Severity filter</span>
						<div class="incident-severity-filter-options">
							${SEVERITIES.map((s) => `
								<label class="incident-filter-check">
									<input type="checkbox" value="${s.key}" checked>
									<span class="incident-badge sev-${s.key}"></span>
									${this.escapeHtml(s.label)}
								</label>
							`).join('')}
						</div>
					</div>

					<div class="incident-meta" data-role="meta"></div>
					<div data-role="warning" hidden></div>
					<div data-role="error" hidden></div>
					<div class="incident-loading" data-role="loading">Loading incident timeline data…</div>

					<div class="incident-grid">
						<div class="incident-card">
							<h3>Incidents over time</h3>
							<p class="incident-card-subtitle">Daily trigger problem events grouped by severity.</p>
							<div class="incident-legend" data-role="legend"></div>
							<div class="incident-chart-surface" data-role="timeline-surface"></div>
						</div>
						<div class="incident-card">
							<h3>Incidents by severity</h3>
							<p class="incident-card-subtitle">Total number of incidents for the selected range.</p>
							<div class="incident-chart-surface" data-role="severity-surface"></div>
						</div>
					</div>

					<div class="incident-card">
						<h3>Severity trend</h3>
						<p class="incident-card-subtitle">Daily incident count per severity as trend lines.</p>
						<div class="incident-legend" data-role="trend-legend"></div>
						<div class="incident-chart-surface" data-role="trend-surface"></div>
					</div>

					<div class="incident-card">
						<h3>Summary</h3>
						<p class="incident-card-subtitle">Counts and percentages for each severity level.</p>
						<div data-role="summary"></div>
						<div class="incident-footer-note">CSV export includes event ID, trigger ID, severity, start time and recovery time.</div>
					</div>
				</div>
			`;

			this.elements = {
				month: this.root.querySelector('#incident-month'),
				prevMonth: this.root.querySelector('[data-role="prev-month"]'),
				nextMonth: this.root.querySelector('[data-role="next-month"]'),
				exportPng: this.root.querySelector('[data-role="export-png"]'),
				exportPdf: this.root.querySelector('[data-role="export-pdf"]'),
				exportCsv: this.root.querySelector('[data-role="export-csv"]'),
				meta: this.root.querySelector('[data-role="meta"]'),
				warning: this.root.querySelector('[data-role="warning"]'),
				error: this.root.querySelector('[data-role="error"]'),
				loading: this.root.querySelector('[data-role="loading"]'),
				legend: this.root.querySelector('[data-role="legend"]'),
				timelineSurface: this.root.querySelector('[data-role="timeline-surface"]'),
				trendLegend: this.root.querySelector('[data-role="trend-legend"]'),
				trendSurface: this.root.querySelector('[data-role="trend-surface"]'),
				severitySurface: this.root.querySelector('[data-role="severity-surface"]'),
				summary: this.root.querySelector('[data-role="summary"]'),
				severityFilter: this.root.querySelector('[data-role="severity-filter"]')
			};

			this.renderLegend();
			this.renderTrendLegend();
		}

		bindEvents() {
			this.elements.month.addEventListener('change', () => {
				this.updateNavButtons();
				this.loadData();
			});

			this.elements.prevMonth.addEventListener('click', () => this.navigateMonth(1));
			this.elements.nextMonth.addEventListener('click', () => this.navigateMonth(-1));

			this.elements.severityFilter.addEventListener('change', (event) => {
				if (event.target.type === 'checkbox') {
					this.updateActiveSeverities();
					this.reRenderCharts();
				}
			});

			this.elements.exportCsv.addEventListener('click', () => this.exportCsv());
			this.elements.exportPdf.addEventListener('click', () => this.exportPdf());
			this.elements.exportPng.addEventListener('click', () => {
				this.exportPng().catch((error) => {
					this.showError(error instanceof Error ? error.message : 'Failed to export PNG.');
				});
			});
		}

		applyInitialState() {
			if (this.initialMonth) {
				const option = this.elements.month.querySelector(`option[value="${this.initialMonth}"]`);

				if (option) {
					this.elements.month.value = this.initialMonth;
				}
			}

			this.updateNavButtons();
		}

		navigateMonth(direction) {
			const options = Array.from(this.elements.month.options);
			const currentIndex = options.findIndex((o) => o.value === this.elements.month.value);
			const newIndex = currentIndex + direction;

			if (newIndex >= 0 && newIndex < options.length) {
				this.elements.month.value = options[newIndex].value;
				this.updateNavButtons();
				this.loadData();
			}
		}

		updateNavButtons() {
			const options = Array.from(this.elements.month.options);
			const currentIndex = options.findIndex((o) => o.value === this.elements.month.value);

			this.elements.nextMonth.disabled = currentIndex <= 0;
			this.elements.prevMonth.disabled = currentIndex >= options.length - 1;
		}

		getSelectedRange() {
			return IncidentTimelineApp.monthToRange(this.elements.month.value);
		}

		updateActiveSeverities() {
			this.activeSeverities.clear();
			const checkboxes = this.elements.severityFilter.querySelectorAll('input[type="checkbox"]');

			checkboxes.forEach((cb) => {
				if (cb.checked) {
					this.activeSeverities.add(Number(cb.value));
				}
			});
		}

		getFilteredSeverities() {
			return SEVERITIES.filter((s) => this.activeSeverities.has(s.key));
		}

		reRenderCharts() {
			if (!this.currentData) {
				return;
			}

			const meta = this.currentData.meta || {};

			// Use server-side pre-aggregated daily data when available.
			let dailyData;

			if (Array.isArray(this.currentData.daily_data) && this.currentData.daily_data.length > 0) {
				dailyData = this.currentData.daily_data;
			}
			else {
				const incidents = Array.isArray(this.currentData.incidents) ? this.currentData.incidents : [];
				const filtered = incidents.filter((i) => this.activeSeverities.has(Number(i.severity || i.s)));
				dailyData = this.buildDailyData(
					filtered,
					meta.time_from || this.requestRange?.time_from,
					meta.time_to || this.requestRange?.time_to
				);
			}

			// Build severity summary from daily data (works with both pre-aggregated and client-built).
			const severitySummary = this.buildSeveritySummaryFromDailyData(dailyData);

			// Count only active severities for the displayed total.
			const filteredTotal = severitySummary
				.filter((row) => this.activeSeverities.has(Number(row.severity)))
				.reduce((sum, row) => sum + Number(row.count || 0), 0);

			this.renderMeta(meta, filteredTotal);
			this.renderTimeline(dailyData);
			this.renderTrendLine(dailyData);
			this.renderSeverityChart(severitySummary.filter((row) => this.activeSeverities.has(Number(row.severity))));
			this.renderSummary(severitySummary.filter((row) => this.activeSeverities.has(Number(row.severity))), filteredTotal);
		}

		buildSeveritySummaryFromDailyData(dailyData) {
			const counts = new Map(SEVERITIES.map((s) => [s.key, 0]));

			dailyData.forEach((day) => {
				SEVERITIES.forEach((s) => {
					const value = Number(day[`sev_${s.key}`] || 0);

					if (value > 0 && counts.has(s.key)) {
						counts.set(s.key, counts.get(s.key) + value);
					}
				});
			});

			return SEVERITIES.map((s) => ({
				severity: s.key,
				label: s.label,
				count: counts.get(s.key) || 0
			}));
		}

		renderLegend() {
			this.elements.legend.innerHTML = SEVERITIES.map((severity) => `
				<span class="incident-legend-item">
					<span class="incident-badge sev-${severity.key}"></span>
					${this.escapeHtml(severity.label)}
				</span>
			`).join('');
		}

		renderTrendLegend() {
			this.elements.trendLegend.innerHTML = SEVERITIES.map((severity) => `
				<span class="incident-legend-item">
					<span style="display:inline-block;width:18px;height:3px;background:${severity.color};vertical-align:middle;border-radius:2px;margin-right:4px;"></span>
					${this.escapeHtml(severity.label)}
				</span>
			`).join('');
		}

		renderTrendLine(dailyData) {
			if (dailyData.length === 0) {
				this.elements.trendSurface.innerHTML = '<div class="incident-empty">No trend data is available.</div>';
				return;
			}

			const margin = {top: 24, right: 30, bottom: 74, left: 52};
			const plotWidth = Math.max(800, dailyData.length * 28);
			const width = margin.left + plotWidth + margin.right;
			const height = 380;
			const plotHeight = height - margin.top - margin.bottom;
			const labelStep = Math.max(1, Math.ceil(dailyData.length / 10));

			const visibleSeverities = this.getFilteredSeverities();

			// Find the max value across visible severities per day.
			let maxValue = 0;

			dailyData.forEach((day) => {
				visibleSeverities.forEach((severity) => {
					const value = Number(day[`sev_${severity.key}`] || 0);

					if (value > maxValue) {
						maxValue = value;
					}
				});
			});

			maxValue = Math.max(1, maxValue);
			const yTicks = this.buildTicks(maxValue, 5);

			let svg = this.createSvgOpenTag(width, height);

			// Grid lines.
			yTicks.forEach((tick) => {
				const y = margin.top + plotHeight - ((tick / maxValue) * plotHeight);
				svg += `<line x1="${margin.left}" y1="${y}" x2="${width - margin.right}" y2="${y}" stroke="#d7e1ec" stroke-width="1" />`;
				svg += `<text x="${margin.left - 8}" y="${y + 4}" font-size="11" text-anchor="end" fill="#5c6b7a">${tick}</text>`;
			});

			// Axes.
			svg += `<line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="#8695a5" stroke-width="1" />`;
			svg += `<line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + plotHeight}" stroke="#8695a5" stroke-width="1" />`;
			svg += `<text x="${margin.left}" y="${margin.top - 6}" font-size="12" font-weight="600" fill="#34485f">Incidents per day (trend)</text>`;

			// X-axis labels.
			const slotWidth = plotWidth / Math.max(dailyData.length - 1, 1);

			dailyData.forEach((day, index) => {
				if (index % labelStep === 0 || index === dailyData.length - 1) {
					const labelX = margin.left + (index * slotWidth);
					const labelY = margin.top + plotHeight + 12;
					svg += `<text x="${labelX}" y="${labelY}" font-size="10" fill="#5c6b7a" transform="rotate(45 ${labelX} ${labelY})" text-anchor="start">${this.escapeHtml(day.date)}</text>`;
				}
			});

			// Draw a line for each visible severity.
			visibleSeverities.forEach((severity) => {
				const points = [];

				dailyData.forEach((day, index) => {
					const value = Number(day[`sev_${severity.key}`] || 0);
					const x = margin.left + (index * slotWidth);
					const y = margin.top + plotHeight - ((value / maxValue) * plotHeight);
					points.push(`${x},${y}`);
				});

				if (points.length > 0) {
					svg += `<polyline points="${points.join(' ')}" fill="none" stroke="${severity.color}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />`;
				}

				// Hover dots.
				dailyData.forEach((day, index) => {
					const value = Number(day[`sev_${severity.key}`] || 0);

					if (value <= 0) {
						return;
					}

					const x = margin.left + (index * slotWidth);
					const y = margin.top + plotHeight - ((value / maxValue) * plotHeight);
					const tooltip = `${day.date} • ${severity.label}: ${value}`;
					svg += `<circle cx="${x}" cy="${y}" r="3.5" fill="${severity.color}" stroke="#fff" stroke-width="1.5" opacity="0"><title>${this.escapeHtml(tooltip)}</title></circle>`;
					svg += `<circle cx="${x}" cy="${y}" r="8" fill="transparent" stroke="none"><title>${this.escapeHtml(tooltip)}</title></circle>`;
				});
			});

			svg += '</svg>';
			this.elements.trendSurface.innerHTML = svg;
		}

		async loadData() {
			const range = this.getSelectedRange();

			this.requestRange = range;
			this.setLoading(true);
			this.clearMessages();
			this.updateLocationState();

			const body = new URLSearchParams({
				time_from: String(range.time_from),
				time_to: String(range.time_to)
			});

			try {
				const response = await fetch(this.dataUrl, {
					method: 'POST',
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
						'Content-Type': 'application/x-www-form-urlencoded'
					},
					body: body.toString(),
					cache: 'no-store'
				});

				const payload = await this.readJsonResponse(response);

				if (!response.ok) {
					throw new Error(payload.error?.message || `Request failed with HTTP ${response.status}.`);
				}

				if (payload.error) {
					throw new Error(payload.error.message || 'Failed to load incident data.');
				}

				this.currentData = payload;
				this.renderData(payload);
			}
			catch (error) {
				this.currentData = null;
				this.renderEmptyState();
				this.showError(error instanceof Error ? error.message : 'Failed to load incident data.');
			}
			finally {
				this.setLoading(false);
				this.updateNavButtons();
				this.updateExportState();
			}
		}

		async readJsonResponse(response) {
			const text = await response.text();

			if (text.trim() === '') {
				return {};
			}

			try {
				return JSON.parse(text);
			}
			catch (_error) {
				throw new Error('The server returned an invalid JSON response.');
			}
		}

		renderData(payload) {
			const meta = payload.meta || {};
			this.renderWarning(meta.limit_reached ? 'The result hit the query limit and may be incomplete.' : '');
			this.reRenderCharts();
		}

		renderMeta(meta, incidentCount) {
			const timeFrom = Number(meta.time_from || this.requestRange?.time_from || 0);
			const timeTo = Number(meta.time_to || this.requestRange?.time_to || 0);
			const fromLabel = timeFrom > 0 ? this.formatDateTime(new Date(timeFrom * 1000)) : 'n/a';
			const toLabel = timeTo > 0 ? this.formatDateTime(new Date(timeTo * 1000)) : 'n/a';
			const generatedAt = Number(meta.generated_at || 0);
			const generatedLabel = generatedAt > 0 ? this.formatDateTime(new Date(generatedAt * 1000)) : 'n/a';

			this.elements.meta.textContent = `Range: ${fromLabel} → ${toLabel} • Incidents: ${incidentCount} • Generated: ${generatedLabel}`;
		}

		renderWarning(message) {
			if (!message) {
				this.elements.warning.hidden = true;
				this.elements.warning.className = '';
				this.elements.warning.textContent = '';
				return;
			}

			this.elements.warning.hidden = false;
			this.elements.warning.className = 'incident-warning';
			this.elements.warning.textContent = message;
		}

		renderTimeline(dailyData) {
			if (dailyData.length === 0) {
				this.elements.timelineSurface.innerHTML = '<div class="incident-empty">No incidents were found for the selected range.</div>';
				return;
			}

			const margin = {top: 18, right: 20, bottom: 74, left: 52};
			const plotWidth = Math.max(640, dailyData.length * 18);
			const width = margin.left + plotWidth + margin.right;
			const height = 360;
			const plotHeight = height - margin.top - margin.bottom;
			const barSlot = plotWidth / Math.max(dailyData.length, 1);
			const barWidth = Math.max(2, Math.min(16, barSlot - 2));
			const visibleSeverities = this.getFilteredSeverities();
			const totals = dailyData.map((day) => visibleSeverities.reduce((sum, s) => sum + Number(day[`sev_${s.key}`] || 0), 0));
			const maxValue = Math.max(1, ...totals);
			const yTicks = this.buildTicks(maxValue, 5);
			const labelStep = Math.max(1, Math.ceil(dailyData.length / 10));

			let svg = this.createSvgOpenTag(width, height);

			yTicks.forEach((tick) => {
				const y = margin.top + plotHeight - ((tick / maxValue) * plotHeight);
				svg += `<line x1="${margin.left}" y1="${y}" x2="${width - margin.right}" y2="${y}" stroke="#d7e1ec" stroke-width="1" />`;
				svg += `<text x="${margin.left - 8}" y="${y + 4}" font-size="11" text-anchor="end" fill="#5c6b7a">${tick}</text>`;
			});

			svg += `<line x1="${margin.left}" y1="${margin.top + plotHeight}" x2="${width - margin.right}" y2="${margin.top + plotHeight}" stroke="#8695a5" stroke-width="1" />`;
			svg += `<line x1="${margin.left}" y1="${margin.top}" x2="${margin.left}" y2="${margin.top + plotHeight}" stroke="#8695a5" stroke-width="1" />`;
			svg += `<text x="${margin.left}" y="${margin.top - 4}" font-size="12" font-weight="600" fill="#34485f">Incidents per day</text>`;

			dailyData.forEach((day, index) => {
				const x = margin.left + (index * barSlot) + ((barSlot - barWidth) / 2);
				let stackedHeight = 0;

				visibleSeverities.forEach((severity) => {
					const value = Number(day[`sev_${severity.key}`] || 0);

					if (value <= 0) {
						return;
					}

					const rectHeight = Math.max(1, (value / maxValue) * plotHeight);
					const y = margin.top + plotHeight - stackedHeight - rectHeight;
					const tooltip = `${day.date} • ${severity.label}: ${value}`;

					svg += `<rect x="${x}" y="${y}" width="${barWidth}" height="${rectHeight}" fill="${severity.color}"><title>${this.escapeHtml(tooltip)}</title></rect>`;
					stackedHeight += rectHeight;
				});

				if (index % labelStep === 0 || index === dailyData.length - 1) {
					const labelX = x + (barWidth / 2);
					const labelY = margin.top + plotHeight + 12;
					svg += `<text x="${labelX}" y="${labelY}" font-size="10" fill="#5c6b7a" transform="rotate(45 ${labelX} ${labelY})" text-anchor="start">${this.escapeHtml(day.date)}</text>`;
				}
			});

			svg += '</svg>';
			this.elements.timelineSurface.innerHTML = svg;
		}

		renderSeverityChart(summary) {
			if (summary.length === 0) {
				this.elements.severitySurface.innerHTML = '<div class="incident-empty">No severity data is available.</div>';
				return;
			}

			const margin = {top: 16, right: 50, bottom: 28, left: 126};
			const rowHeight = 36;
			const plotWidth = 420;
			const width = margin.left + plotWidth + margin.right;
			const height = margin.top + (summary.length * rowHeight) + margin.bottom;
			const maxValue = Math.max(1, ...summary.map((row) => Number(row.count || 0)));
			const ticks = this.buildTicks(maxValue, 4);

			let svg = this.createSvgOpenTag(width, height);
			svg += `<text x="${margin.left}" y="12" font-size="12" font-weight="600" fill="#34485f">Incident count</text>`;

			ticks.forEach((tick) => {
				const x = margin.left + ((tick / maxValue) * plotWidth);
				svg += `<line x1="${x}" y1="${margin.top}" x2="${x}" y2="${height - margin.bottom}" stroke="#d7e1ec" stroke-width="1" />`;
				svg += `<text x="${x}" y="${height - 8}" font-size="11" text-anchor="middle" fill="#5c6b7a">${tick}</text>`;
			});

			summary.forEach((row, index) => {
				const severity = SEVERITIES.find((entry) => entry.key === Number(row.severity)) || {color: '#999999', label: row.label || 'Unknown'};
				const y = margin.top + (index * rowHeight);
				const barHeight = 18;
				const barWidth = maxValue > 0 ? ((Number(row.count || 0) / maxValue) * plotWidth) : 0;
				const labelY = y + 14;

				svg += `<text x="${margin.left - 8}" y="${labelY}" font-size="12" text-anchor="end" fill="#34485f">${this.escapeHtml(row.label || severity.label)}</text>`;
				svg += `<rect x="${margin.left}" y="${y}" width="${barWidth}" height="${barHeight}" rx="3" ry="3" fill="${severity.color}"><title>${this.escapeHtml(`${row.label}: ${row.count}`)}</title></rect>`;
				svg += `<text x="${margin.left + barWidth + 8}" y="${labelY}" font-size="12" fill="#34485f">${Number(row.count || 0)}</text>`;
			});

			svg += '</svg>';
			this.elements.severitySurface.innerHTML = svg;
		}

		renderSummary(summary, totalIncidents) {
			if (summary.length === 0) {
				this.elements.summary.innerHTML = '<div class="incident-empty">No summary data is available.</div>';
				return;
			}

			const rows = summary.map((row) => {
				const percentage = totalIncidents > 0 ? ((Number(row.count || 0) / totalIncidents) * 100).toFixed(1) : '0.0';

				return `
					<tr>
						<td>
							<span class="incident-severity-cell">
								<span class="incident-badge sev-${Number(row.severity)}"></span>
								${this.escapeHtml(row.label || 'Unknown')}
							</span>
						</td>
						<td>${Number(row.count || 0)}</td>
						<td>${percentage}%</td>
					</tr>
				`;
			}).join('');

			this.elements.summary.innerHTML = `
				<table class="incident-summary-table">
					<thead>
						<tr>
							<th>Severity</th>
							<th>Count</th>
							<th>Percentage</th>
						</tr>
					</thead>
					<tbody>
						${rows}
						<tr>
							<td><strong>Total</strong></td>
							<td><strong>${totalIncidents}</strong></td>
							<td><strong>${totalIncidents > 0 ? '100.0%' : '0.0%'}</strong></td>
						</tr>
					</tbody>
				</table>
			`;
		}

		renderEmptyState() {
			this.elements.meta.textContent = '';
			this.elements.timelineSurface.innerHTML = '<div class="incident-empty">No chart data is available.</div>';
			this.elements.trendSurface.innerHTML = '<div class="incident-empty">No trend data is available.</div>';
			this.elements.severitySurface.innerHTML = '<div class="incident-empty">No severity data is available.</div>';
			this.elements.summary.innerHTML = '<div class="incident-empty">No summary data is available.</div>';
		}

		buildDailyData(incidents, timeFrom, timeTo) {
			if (!timeFrom || !timeTo || timeTo < timeFrom) {
				return [];
			}

			const days = new Map();
			const cursor = new Date(timeFrom * 1000);
			cursor.setHours(0, 0, 0, 0);
			const end = new Date(timeTo * 1000);
			end.setHours(0, 0, 0, 0);

			while (cursor.getTime() <= end.getTime()) {
				const key = this.formatDateInput(cursor);
				const row = {date: key};

				SEVERITIES.forEach((severity) => {
					row[`sev_${severity.key}`] = 0;
				});

				days.set(key, row);
				cursor.setDate(cursor.getDate() + 1);
			}

			incidents.forEach((incident) => {
				const clock = Number(incident.clock || 0);

				if (clock < timeFrom || clock > timeTo) {
					return;
				}

				const key = this.formatDateInput(new Date(clock * 1000));
				const row = days.get(key);
				const severity = Number(incident.severity || 0);

				if (row && Object.prototype.hasOwnProperty.call(row, `sev_${severity}`)) {
					row[`sev_${severity}`] += 1;
				}
			});

			return Array.from(days.values());
		}

		buildSeveritySummaryFromIncidents(incidents) {
			const counts = new Map(SEVERITIES.map((severity) => [severity.key, 0]));

			incidents.forEach((incident) => {
				const severity = Number(incident.severity || 0);
				if (counts.has(severity)) {
					counts.set(severity, counts.get(severity) + 1);
				}
			});

			return SEVERITIES.map((severity) => ({
				severity: severity.key,
				label: severity.label,
				count: counts.get(severity.key) || 0
			}));
		}

		totalIncidentsForDay(day) {
			return SEVERITIES.reduce((sum, severity) => sum + Number(day[`sev_${severity.key}`] || 0), 0);
		}

		buildTicks(maxValue, approxCount) {
			if (maxValue <= 1) {
				return [0, 1];
			}

			const rawStep = maxValue / Math.max(1, approxCount);
			const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
			let step = magnitude;

			if (rawStep / magnitude > 5) {
				step = 10 * magnitude;
			}
			else if (rawStep / magnitude > 2) {
				step = 5 * magnitude;
			}
			else if (rawStep / magnitude > 1) {
				step = 2 * magnitude;
			}

			step = Math.max(1, Math.round(step));

			const ticks = [0];
			for (let value = step; value < maxValue; value += step) {
				ticks.push(value);
			}
			ticks.push(maxValue);

			return Array.from(new Set(ticks));
		}

		createSvgOpenTag(width, height) {
			return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" role="img" aria-label="Incident chart">`;
		}

		setLoading(isLoading) {
			this.elements.loading.hidden = !isLoading;
			this.elements.month.disabled = isLoading;
			this.elements.prevMonth.disabled = isLoading;
			this.elements.nextMonth.disabled = isLoading;
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

		updateExportState() {
			const hasData = !!(this.currentData && (Array.isArray(this.currentData.daily_data) || Array.isArray(this.currentData.incidents)));
			this.elements.exportCsv.disabled = !hasData;
			this.elements.exportPdf.disabled = !hasData;
			this.elements.exportPng.disabled = !hasData;
		}

		updateLocationState() {
			const url = new URL(window.location.href);
			url.searchParams.set('action', 'incident.timeline.view');
			url.searchParams.set('month', this.elements.month.value);
			url.searchParams.delete('period');
			url.searchParams.delete('date_from');
			url.searchParams.delete('date_to');

			window.history.replaceState({}, '', url.toString());
		}

		exportCsv() {
			if (!this.currentData || !Array.isArray(this.currentData.incidents)) {
				return;
			}

			const sevNames = {};
			SEVERITIES.forEach((s) => { sevNames[s.key] = s.label; });

			const header = ['Event ID', 'Trigger ID', 'Name', 'Severity', 'Severity name', 'Problem time', 'Recovery time', 'Resolved'];
			const rows = [header];

			this.currentData.incidents.forEach((inc) => {
				// Support both compact (eid/oid/n/s/c/r/rc) and legacy (eventid/objectid/...) formats.
				const severity = Number(inc.s ?? inc.severity ?? 0);
				const clock = Number(inc.c ?? inc.clock ?? 0);
				const rClock = Number(inc.rc ?? inc.r_clock ?? 0);

				if (!this.activeSeverities.has(severity)) {
					return;
				}

				rows.push([
					inc.eid || inc.eventid || '',
					inc.oid || inc.objectid || '',
					inc.n || inc.name || '',
					severity,
					inc.severity_name || sevNames[severity] || '',
					this.formatIsoDateTime(clock),
					rClock > 0 ? this.formatIsoDateTime(rClock) : 'Ongoing',
					rClock > 0 ? 'Yes' : 'No'
				]);
			});

			const csv = rows.map((row) => row.map((value) => this.csvEscape(value)).join(',')).join('\n');
			this.downloadBlob(new Blob([csv], {type: 'text/csv;charset=utf-8;'}), this.buildFileName('incident-timeline', 'csv'));
		}

		exportPdf() {
			if (!this.currentData) {
				return;
			}

			const timelineSvg = this.elements.timelineSurface.querySelector('svg');
			const trendSvg = this.elements.trendSurface.querySelector('svg');
			const severitySvg = this.elements.severitySurface.querySelector('svg');
			const printWindow = window.open('', '_blank', 'noopener');

			if (!printWindow) {
				this.showError('Unable to open a print window for PDF export.');
				return;
			}

			const summaryHtml = this.elements.summary.innerHTML;
			const metaText = this.escapeHtml(this.elements.meta.textContent || '');
			const warningText = !this.elements.warning.hidden ? `<div style="margin-bottom:12px;padding:10px;border:1px solid #f1d59c;background:#fff7e6;border-radius:4px;">${this.escapeHtml(this.elements.warning.textContent || '')}</div>` : '';

			printWindow.document.open();
			printWindow.document.write(`<!DOCTYPE html>
				<html>
				<head>
					<title>Incident Timeline Report</title>
					<style>
						body { font-family: Arial, sans-serif; padding: 20px; color: #1f2b3a; }
						h1 { margin: 0 0 8px; }
						.meta { margin: 0 0 16px; color: #5c6b7a; font-size: 13px; }
						.card { border: 1px solid #dfe4ec; border-radius: 4px; padding: 16px; margin-bottom: 16px; }
						table { width: 100%; border-collapse: collapse; }
						th, td { border-bottom: 1px solid #e7edf4; padding: 8px 10px; text-align: left; }
						th { font-weight: 700; }
						svg { max-width: 100%; height: auto; }
					</style>
				</head>
				<body>
					<h1>Incident Timeline Report</h1>
					<div class="meta">${metaText}</div>
					${warningText}
					<div class="card">
						<h2>Incidents over time</h2>
						${timelineSvg ? timelineSvg.outerHTML : '<p>No chart data.</p>'}
					</div>
					<div class="card">
						<h2>Severity trend</h2>
						${trendSvg ? trendSvg.outerHTML : '<p>No chart data.</p>'}
					</div>
					<div class="card">
						<h2>Incidents by severity</h2>
						${severitySvg ? severitySvg.outerHTML : '<p>No chart data.</p>'}
					</div>
					<div class="card">
						<h2>Summary</h2>
						${summaryHtml}
					</div>
				</body>
				</html>`);
			printWindow.document.close();
			printWindow.focus();
			printWindow.print();
		}

		async exportPng() {
			if (!this.currentData) {
				return;
			}

			const timelineSvg = this.elements.timelineSurface.querySelector('svg');
			const trendSvg = this.elements.trendSurface.querySelector('svg');
			const severitySvg = this.elements.severitySurface.querySelector('svg');

			if (!timelineSvg || !severitySvg) {
				throw new Error('Nothing is available to export as PNG.');
			}

			const imagePromises = [
				this.svgElementToImage(timelineSvg),
				trendSvg ? this.svgElementToImage(trendSvg) : Promise.resolve(null),
				this.svgElementToImage(severitySvg)
			];

			const [timelineImage, trendImage, severityImage] = await Promise.all(imagePromises);

			const summaryRows = this.getSummaryRowsForExport();
			const chartWidths = [timelineImage.width, severityImage.width, 900];

			if (trendImage) {
				chartWidths.push(trendImage.width);
			}

			const canvasWidth = Math.max(...chartWidths);
			const summaryHeight = 40 + (summaryRows.length * 24);
			const trendHeight = trendImage ? trendImage.height + 24 : 0;
			const canvasHeight = 80 + timelineImage.height + 24 + trendHeight + severityImage.height + 24 + summaryHeight;
			const canvas = document.createElement('canvas');
			canvas.width = canvasWidth;
			canvas.height = canvasHeight;

			const context = canvas.getContext('2d');
			if (!context) {
				throw new Error('Failed to create a canvas for PNG export.');
			}

			context.fillStyle = '#ffffff';
			context.fillRect(0, 0, canvas.width, canvas.height);
			context.fillStyle = '#1f2b3a';
			context.font = 'bold 24px Arial';
			context.fillText('Incident Timeline Report', 20, 34);
			context.font = '13px Arial';
			context.fillStyle = '#5c6b7a';
			context.fillText(this.elements.meta.textContent || '', 20, 56);

			let y = 80;
			context.drawImage(timelineImage.image, 0, y, timelineImage.width, timelineImage.height);
			y += timelineImage.height + 24;

			if (trendImage) {
				context.drawImage(trendImage.image, 0, y, trendImage.width, trendImage.height);
				y += trendImage.height + 24;
			}

			context.drawImage(severityImage.image, 0, y, severityImage.width, severityImage.height);
			y += severityImage.height + 28;

			context.fillStyle = '#1f2b3a';
			context.font = 'bold 16px Arial';
			context.fillText('Summary', 20, y);
			y += 24;
			context.font = '13px Arial';

			summaryRows.forEach((row) => {
				context.fillStyle = '#1f2b3a';
				context.fillText(row.label, 20, y);
				context.fillText(String(row.count), 280, y);
				context.fillText(row.percentage, 360, y);
				y += 22;
			});

			const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
			if (!blob) {
				throw new Error('Failed to render the PNG file.');
			}

			this.downloadBlob(blob, this.buildFileName('incident-timeline', 'png'));
		}

		getSummaryRowsForExport() {
			const rows = [];
			const tableRows = this.elements.summary.querySelectorAll('tbody tr');
			tableRows.forEach((row) => {
				const columns = row.querySelectorAll('td');
				if (columns.length === 3) {
					rows.push({
						label: columns[0].textContent.trim().replace(/\s+/g, ' '),
						count: columns[1].textContent.trim(),
						percentage: columns[2].textContent.trim()
					});
				}
			});
			return rows;
		}

		svgElementToImage(svgElement) {
			return new Promise((resolve, reject) => {
				const serializer = new XMLSerializer();
				const svgText = serializer.serializeToString(svgElement);
				const blob = new Blob([svgText], {type: 'image/svg+xml;charset=utf-8'});
				const objectUrl = URL.createObjectURL(blob);
				const image = new Image();
				const viewBox = svgElement.viewBox.baseVal;
				const width = viewBox && viewBox.width ? viewBox.width : svgElement.clientWidth || 800;
				const height = viewBox && viewBox.height ? viewBox.height : svgElement.clientHeight || 400;

				image.onload = () => {
					URL.revokeObjectURL(objectUrl);
					resolve({image, width, height});
				};

				image.onerror = () => {
					URL.revokeObjectURL(objectUrl);
					reject(new Error('Failed to render SVG for PNG export.'));
				};

				image.src = objectUrl;
			});
		}

		csvEscape(value) {
			const stringValue = String(value ?? '');
			return `"${stringValue.replace(/"/g, '""')}"`;
		}

		downloadBlob(blob, fileName) {
			const link = document.createElement('a');
			const url = URL.createObjectURL(blob);
			link.href = url;
			link.download = fileName;
			link.click();
			setTimeout(() => URL.revokeObjectURL(url), 1000);
		}

		buildFileName(prefix, extension) {
			const date = this.formatDateInput(new Date());
			return `${prefix}-${date}.${extension}`;
		}

		formatDateInput(date) {
			const year = date.getFullYear();
			const month = String(date.getMonth() + 1).padStart(2, '0');
			const day = String(date.getDate()).padStart(2, '0');
			return `${year}-${month}-${day}`;
		}

		formatDateTime(date) {
			return date.toLocaleString(undefined, {
				year: 'numeric',
				month: 'short',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit'
			});
		}

		formatIsoDateTime(timestampSeconds) {
			if (!timestampSeconds) {
				return '';
			}
			return new Date(timestampSeconds * 1000).toISOString();
		}

		escapeHtml(value) {
			return String(value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}
	}
})();
