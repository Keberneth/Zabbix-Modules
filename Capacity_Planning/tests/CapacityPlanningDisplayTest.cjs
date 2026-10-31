'use strict';

// Exercise the production rendering and export methods without a browser or API.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const asset = manifest.assets.js.find((name) => name.startsWith('capacity-planning'));
const source = fs.readFileSync(path.join(root, 'assets', 'js', asset), 'utf8');
const context = {document: {addEventListener() {}}, console};
vm.createContext(context);
vm.runInContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.App = CapacityPlanningApp; globalThis.palette = PALETTE_LIGHT; })();'), context);
const app = Object.create(context.App.prototype);
app.fc = new Map();
app.fcReady = true;
app.inv = {byHostId: new Map()};
app.elements = {meta: {textContent: '501 filesystems and 501 resources'},
	filterSummary: {textContent: ''}, resultsSummary: {textContent: ''}};
const disks = Array.from({length: 501}, (_, i) => ({id: `d${i}`, hostid: `${i}`, host: `disk-host-${i}`,
	fs: `/filesystem-${i}`, pused: 25, used: 25, free: 75, usable: 100, current_severity: 'Healthy', status: 'OK'}));
const resources = Array.from({length: 501}, (_, i) => ({id: `r${i}`, hostid: `${i}`, host: `resource-host-${i}`,
	rtype: 'CPU', current: 25, current_severity: 'Healthy', status: 'OK'}));
app.visibleDisks = () => disks;
app.visibleResources = () => resources;
let checks = 0;
const ok = (value, message) => { checks++; assert.ok(value, message); };
const same = (actual, expected, message) => { checks++; assert.strictEqual(actual, expected, message); };

const report = app.buildReportHtml();
same((report.match(/<tbody>/g) || []).length, 2, 'The full report must contain both resource tables.');
same((report.match(/disk-host-\d+<\/td>/g) || []).length, 501, 'HTML export must include every counted disk.');
same((report.match(/resource-host-\d+<\/td>/g) || []).length, 501, 'HTML export must include every counted resource.');
ok(report.includes('Bucket p95') && report.includes('not exact time above it'), 'Export must explain aggregated statistics.');
let csvRows = null;
app.downloadCsv = (rows) => { csvRows = rows; };
app.exportDisksCsv();
same(csvRows.length, 502, 'CSV must include the same complete disk set as HTML.');
ok(csvRows.every((row) => row.length === csvRows[0].length), 'Disk CSV headings and values must stay aligned.');
ok(csvRows[0].includes('Projection basis') && csvRows[0].includes('Observed percentage-history growth pp/day'),
	'Disk exports must separate forecast basis from observed percentage history.');
app.exportResourcesCsv();
ok(csvRows[0].includes('Bucket-average p95 %') && csvRows[0].includes('Above-review bucket exposure %'),
	'Resource CSV headings must identify aggregate statistics.');
ok(csvRows.every((row) => row.length === csvRows[0].length), 'Resource CSV headings and values must stay aligned.');

const day = 86400;
const base = 19000 * day;
app.fcGeneratedAt = base + 10 * day;
const points = [[base, 10, 20, 30, base, base + day],
	[base + day, 20, 30, 40, base + day, base + 2 * day],
	[base + 8 * day, 40, 50, 60, base + 8 * day, base + 9 * day],
	[base + 9 * day, 50, 60, 70, base + 9 * day, base + 10 * day]];
const spec = {series: app.chartSeries(points), title: 'CPU utilization (%)', unit: '%', yMax: 100,
	thresholds: [], currentValue: null, slope: null};
const chart = app.buildUsageSvg(spec, context.palette, false);
same((chart.svg.match(/<polyline data-role="history-average"/g) || []).length, 2,
	'The history line must break at missing days.');
same((chart.svg.match(/<polygon data-role="history-band"/g) || []).length, 2,
	'The min-max band must also break at missing days.');
const svg = {getBoundingClientRect: () => ({left: 0, width: chart.geom.vbWidth}),
	viewBox: {baseVal: {width: chart.geom.vbWidth}}};
const surface = {querySelector: () => svg};
app.detailGeom = chart.geom;
app.detailEls = {surface};
let tooltip = '';
app.showTooltip = (_event, html) => { tooltip = html; };
const hoverAt = (time) => app.onDetailHover({clientX: chart.geom.margin.left
	+ (time - chart.geom.tMin) / chart.geom.span * chart.geom.plotWidth}, surface);
hoverAt(base + 5 * day);
ok(tooltip.includes('No observations for this date') && !tooltip.includes('Interval average'),
	'A missing date must not borrow a distant sample and label it as that date.');
hoverAt(base + day + 3600);
ok(tooltip.includes('Interval average') && tooltip.includes('30%'), 'An observed interval must show its actual average.');

const grouped = app.chartSeries([[base + day / 2, 10, 30, 70, base, base + 2 * day]]);
app.detailGeom = {...chart.geom, series: grouped};
hoverAt(base + day + 3600);
ok(tooltip.includes(`${app.fmtDate(base)} – ${app.fmtDate(base + 2 * day - 1)}`),
	'A grouped point must show its actual date range rather than one invented observation date.');

const pctOnly = {id: 'pct', hostid: '1', pused: 70, status: 'Incomplete',
	current_snapshot_usable: true, current_observation_usable: true, byte_source: 'none',
	warn_pct: {v: 90}, crit_pct: {v: 95}, warn_free: {v: 100}, crit_free: {v: 50}};
const pctForecast = {pct_series: [[base, 60, 65, 70, base, base + day]], series: [],
	growth_day: null, growth_pct_day: 1, projection_basis: 'percentage_only'};
const pctSpec = app.diskChartSpec(pctOnly, pctForecast);
same(pctSpec.unit, '%', 'A percentage-only filesystem must stay in percentage units.');
same(pctSpec.series[0].avg, 65, 'Percentage-only history must not be scaled into GiB.');
same(pctSpec.slope, 1, 'The percentage-only projection must retain its percentage-point slope.');
same(pctSpec.thresholds.length, 2, 'Byte thresholds cannot be plotted without byte capacity.');
same(app.buildSpec(pctOnly, 'disk').ok, true, 'A usable percentage snapshot must be analyzed despite incomplete bytes.');
same(app.buildSpec(pctOnly, 'disk').byte_source, 'none', 'Requests must carry byte-history provenance.');
same(app.currentObservationText({...resources[0], status: 'Stale'}, '25%'), 'Last accepted: 25%',
	'A stale CPU/RAM value must not be labelled as a current observation.');
same(app.currentObservationUsable({...pctOnly, status: 'Stale'}), true,
	'Fresh disk percentage evidence must remain usable when only its byte values are stale.');
same(app.currentObservationUsable({...pctOnly, current_snapshot_usable: false}), false,
	'An explicitly unusable disk snapshot cannot anchor a current projection.');
same(app.diskByteGrowthText({...pctForecast, forecast_reliable: true}), 'Byte growth unavailable',
	'A valid percentage model must not imply stable byte usage when bytes cannot be assessed.');
same(app.diskByteGrowthText({sel: '3m', byte_source: 'used', forecast_reliable: true, growth_day: null}),
	'No sustained byte growth', 'A qualified flat byte model must remain distinguishable from missing evidence.');
app.visibleDisks = () => [disks[0]];
app.fc.set(disks[0].id, {status: 'ok', forecast_reliable: false, severity: 'Unknown',
	growth_day: null, growth_pct_day: null});
app.elements.disksBody = {innerHTML: ''};
app.sort = {disks: {key: 'risk', dir: 'desc'}};
app.pages = {disks: 1};
app.pageSize = 25;
app.bindTable = () => {};
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('No reliable forecast')
	&& !app.elements.disksBody.innerHTML.includes('No sustained growth'),
	'A withheld forecast must not be presented as observed no growth.');
app.fc.clear();
app.fcReady = false;
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('Loading growth…'),
	'Pending analysis must not be presented as observed no growth.');

const unconfirmed = {status: 'ok', severity: 'Watch', confidence: 'Medium', forecast_reliable: true,
	sel: '3m', pct_sel: '3m', byte_source: 'used', projection_basis: 'used_bytes_fixed_capacity',
	growth_day: 2 ** 28, growth_pct_day: 0.025, accelerating: false, pct_accelerating: false,
	acceleration_unconfirmed: true, acceleration_note: 'Faster monthly growth is unconfirmed; the 3-month baseline is retained.',
	acceleration_evidence: {candidate_rate: 2 ** 30, unit: 'bytes/day', baseline_window: '3m',
		recent_net_rate: -(2 ** 29), recent_window: '1w', confirmed: false}, series: [], pct_series: []};
app.fc.set(disks[0].id, unconfirmed);
app.fcReady = true;
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('Acceleration unconfirmed')
	&& app.elements.disksBody.innerHTML.includes('256.0 MiB/day'),
	'The table must show the retained baseline alongside an explicit unconfirmed-acceleration marker.');
ok(app.elements.disksBody.innerHTML.includes('Observed monthly candidate: 1.0 GiB/day')
	&& app.elements.disksBody.innerHTML.includes('Recent 1-week net growth: -512.0 MiB/day'),
	'The confidence tooltip must preserve the faster observed candidate and negative recent net growth.');
ok(app.diskAccelerationSummary({...unconfirmed,
	acceleration_evidence: {...unconfirmed.acceleration_evidence, recent_window: '2w'}})
	.includes('Recent 2-week net growth: -512.0 MiB/day'),
	'Two-week confirmation evidence must retain its actual window in the detail, tooltip, and export summary.');
ok(app.diskAccelerationSummary({...unconfirmed,
	acceleration_evidence: {...unconfirmed.acceleration_evidence, recent_window: undefined}})
	.includes('Recent net growth: -512.0 MiB/day'),
	'Evidence without a declared recent window must not invent a one-week interval.');
app.showDetailModal = () => {};
app.renderDetailChart = () => {};
for (const name of ['diskDetailTitle', 'diskDetailSubtitle', 'diskDetailStats', 'diskDetailLegend', 'diskDetailSurface']) {
	app.elements[name] = {textContent: '', innerHTML: ''};
}
app.renderDiskDetail(disks[0]);
ok(app.elements.diskDetailStats.innerHTML.includes('Acceleration review:')
	&& app.elements.diskDetailStats.innerHTML.includes('Observed monthly candidate: 1.0 GiB/day')
	&& app.elements.diskDetailStats.innerHTML.includes('256.0 MiB/day'),
	'Details must distinguish the observed monthly candidate from the retained projected growth.');
app.exportDisksCsv();
same(csvRows[1][csvRows[0].indexOf('Confirmed acceleration')], 'No',
	'An unconfirmed monthly rate must not be exported as confirmed acceleration.');
same(csvRows[1][csvRows[0].indexOf('Acceleration unconfirmed')], 'Yes',
	'CSV must explicitly disclose unresolved acceleration.');
same(csvRows[1][csvRows[0].indexOf('Observed monthly candidate growth')], 2 ** 30,
	'CSV must preserve the candidate rate independently of the projected growth.');
ok(app.buildReportHtml().includes('Observed monthly candidate: 1.0 GiB/day'),
	'The HTML report must retain the acceleration review evidence.');
ok(!app.diskAccelerationMarker({...unconfirmed, acceleration_unconfirmed: false, accelerating: true})
	.includes('Acceleration unconfirmed'), 'Confirmed acceleration must remain distinct from the review state.');
app.fc.set(disks[0].id, {...unconfirmed, growth_day: null, growth_pct_day: null});
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('Growth under review')
	&& !app.elements.disksBody.innerHTML.includes('No sustained growth'),
	'An unconfirmed faster rate must not be summarized as current no-growth when the baseline is flat.');

const criticalDisk = {...disks[0], pused: 97.2, used: 972, free: 28, usable: 1000,
	current_severity: 'Critical', current_observation_usable: true, current_snapshot_usable: true,
	warn_pct: {v: 90}, crit_pct: {v: 95}};
const criticalSpike = {status: 'ok', severity: 'Critical', confidence: 'Low', forecast_reliable: false,
	projection_basis: 'used_bytes_fixed_capacity', byte_source: 'used',
	forecast_status: 'unavailable', forecast_reason_codes: ['recent_growth_unconfirmed'],
	forecast_unavailable_reason: 'Recent growth is not corroborated by enough persistent observations.',
	eta: {warn_days: 0, crit_days: 0, full_days: null}, series: [], pct_series: [],
	information: {
		summary: 'Critical now: 97.2% used. A recent jump needs review; no reliable future date is available.',
		current: 'Current used space exceeds the critical threshold of 95%.',
		recent: 'A sharp daily-average increase was observed during the last week; recurrence is unproven.',
		long_term: 'The longer window includes both growth and cleanup, so its behaviour differs from the recent jump.',
		forecast: 'No reliable forecast: recent growth is not corroborated by enough persistent observations.',
		signals: ['Recent spike observed', 'Current critical threshold exceeded']
	}};
app.visibleDisks = () => [criticalDisk];
app.fc.set(criticalDisk.id, criticalSpike);
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('<th>Information</th>')
	&& app.elements.disksBody.innerHTML.includes(criticalSpike.information.summary),
	'The filesystem table must expose a concise Information summary.');
ok(app.elements.disksBody.innerHTML.includes('risk-critical')
	&& app.elements.disksBody.innerHTML.includes('Forecast confidence')
	&& app.elements.disksBody.innerHTML.includes('>Low</td>'),
	'Low forecast confidence must remain separate from a confirmed current Critical risk.');
same((app.elements.disksBody.innerHTML.match(/>Now<\/span>/g) || []).length, 2,
	'Warning and critical thresholds already reached must remain Now even when future forecasts are unavailable.');
ok(app.elements.disksBody.innerHTML.includes(`title="${criticalSpike.forecast_unavailable_reason}"`),
	'An unavailable ETA must explain its specific reason in the tooltip.');
app.renderDiskDetail(criticalDisk);
for (const label of ['Current risk', 'Recent behaviour', 'Longer-term behaviour', 'Forecast assessment']) {
	ok(app.elements.diskDetailStats.innerHTML.includes(`<dt>${label}</dt>`), `Details must have a ${label} block.`);
}
ok(app.elements.diskDetailStats.innerHTML.includes(criticalSpike.information.recent)
	&& app.elements.diskDetailStats.innerHTML.includes('Current critical threshold exceeded'),
	'Details must retain the recent spike and current threshold evidence without inventing recurrence.');
app.exportDisksCsv();
for (const [heading, value] of [['Information', criticalSpike.information.summary],
	['Current risk', criticalSpike.information.current], ['Recent behaviour', criticalSpike.information.recent],
	['Longer-term behaviour', criticalSpike.information.long_term], ['Forecast assessment', criticalSpike.information.forecast],
	['Forecast status', 'unavailable'], ['Forecast reason codes', 'recent_growth_unconfirmed'],
	['Critical ETA assessment', 'Now'], ['Full ETA assessment', 'Not projected']]) {
	same(csvRows[1][csvRows[0].indexOf(heading)], value, `Disk CSV must preserve ${heading}.`);
}
ok(csvRows.every((row) => row.length === csvRows[0].length), 'Explanation columns must preserve CSV alignment.');
const informationReport = app.buildReportHtml();
ok(informationReport.includes('<th>Information</th>')
	&& informationReport.includes(criticalSpike.information.long_term)
	&& informationReport.includes('<dt>Forecast assessment</dt>'),
	'HTML export must retain the summary and all expandable explanation blocks.');
app.exportActionsCsv();
same(csvRows[1][csvRows[0].indexOf('Information')], criticalSpike.information.summary,
	'The overview action CSV must also carry the filesystem Information summary.');
ok(csvRows.every((row) => row.length === csvRows[0].length), 'Mixed resource action CSV rows must stay aligned.');

for (const status of ['stable', 'shrinking']) {
	const flat = {...criticalSpike, severity: 'Healthy', confidence: 'High', forecast_status: status,
		forecast_reliable: true, forecast_unavailable_reason: null, eta: {},
		information: {...criticalSpike.information, summary: `The qualified model is ${status}.`,
			forecast: `The qualified model is ${status}; no growing-usage threshold crossing is projected.`}};
	same(app.diskEtaLabel(null, flat), 'No rising trend', `${status} must be distinguished from an unavailable forecast.`);
	same(app.diskEtaLabel(0, flat), 'Now', `${status} must never conceal a current threshold breach.`);
	app.fc.set(criticalDisk.id, flat);
	app.renderDisks();
	ok(app.elements.disksBody.innerHTML.includes(status === 'shrinking' ? 'Declining baseline' : 'Stable baseline'),
		`Projected growth must identify the qualified ${status} baseline without merging both states into no-growth.`);
	same((app.elements.disksBody.innerHTML.match(/>No rising trend<\/span>/g) || []).length, 3,
		`All null ${status} ETAs must use the qualified-model label.`);
}

const percentageStable = {...criticalSpike, forecast_status: 'stable', forecast_reliable: true,
	projection_basis: 'percentage_only', byte_source: 'none', forecast_reason_codes: [],
	forecast_unavailable_reason: null, eta: {}};
same(app.diskEtaLabel(null, percentageStable, 'warn'), 'No rising trend',
	'A qualified percentage model may describe the lack of a rising percentage-threshold trend.');
same(app.diskEtaLabel(null, percentageStable, 'full'), 'Not projected',
	'A stable percentage model must not imply that byte-based fullness is stable.');
same(app.diskEtaLabel(0, percentageStable, 'full'), 'Now',
	'A reached full threshold must remain Now regardless of future model availability.');
ok(app.diskEtaExplanation(percentageStable, 'full').includes('Percentage history alone is insufficient'),
	'An unavailable full ETA must explain the missing byte-growth evidence.');
const weakPercentage = {...percentageStable, forecast_status: 'unavailable', forecast_reliable: false,
	forecast_unavailable_reason: 'The percentage model has a weak fit.'};
ok(app.diskEtaExplanation(weakPercentage, 'full').includes(weakPercentage.forecast_unavailable_reason)
	&& app.diskEtaExplanation(weakPercentage, 'full').includes('Percentage history alone is insufficient'),
	'The specific forecast failure must remain visible alongside the Full model-basis limitation.');
app.fc.set(criticalDisk.id, percentageStable);
app.renderDisks();
same((app.elements.disksBody.innerHTML.match(/>No rising trend<\/span>/g) || []).length, 2,
	'The filesystem table must not apply the percentage-only stable label to Full.');
app.renderDiskDetail(criticalDisk);
ok(/Full: <b><span[^>]*>Not projected<\/span><\/b>/.test(app.elements.diskDetailStats.innerHTML),
	'The detail Full assessment must preserve the unavailable byte-based model.');
app.exportDisksCsv();
same(csvRows[1][csvRows[0].indexOf('Full ETA assessment')], 'Not projected',
	'The disk CSV must preserve the Full ETA basis restriction.');
app.exportActionsCsv();
same(csvRows[1][csvRows[0].indexOf('Full')], 'Not projected',
	'The action CSV must preserve the Full ETA basis restriction.');
ok(app.buildReportHtml().includes('Percentage history alone is insufficient'),
	'The HTML Full assessment must preserve the missing byte-model explanation.');
const missingFree = {...percentageStable, projection_basis: 'used_bytes_fixed_capacity', byte_source: 'used',
	forecast_reason_codes: ['missing_free_space_evidence']};
for (const target of ['warn', 'crit', 'full', 'threshold']) {
	same(app.diskEtaLabel(null, missingFree, target), 'Not projected',
		`Missing free-space evidence must not imply a fully assessed ${target} threshold.`);
	same(app.diskEtaLabel(0, missingFree, target), 'Now',
		`Missing free-space evidence must not hide a known current ${target} breach.`);
}
ok(app.diskEtaExplanation(missingFree, 'crit').includes('Current free-space evidence is unavailable'),
	'The combined-threshold tooltip must identify the specific missing absolute evidence.');

const malicious = '<img src=x onerror="alert(1)">';
const unsafe = {...criticalSpike, forecast_unavailable_reason: malicious,
	information: Object.fromEntries(['summary', 'current', 'recent', 'long_term', 'forecast'].map((key) => [key, malicious]))};
unsafe.information.signals = [malicious];
app.fc.set(criticalDisk.id, unsafe);
app.renderDisks();
app.renderDiskDetail(criticalDisk);
for (const [name, html] of [['table', app.elements.disksBody.innerHTML],
	['detail', app.elements.diskDetailStats.innerHTML], ['HTML export', app.buildReportHtml()]]) {
	ok(!html.includes('<img') && html.includes('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;'),
		`${name} must escape backend explanation text and tooltip attributes.`);
}
app.fc.clear();
app.fcReady = false;
app.renderDisks();
ok(app.elements.disksBody.innerHTML.includes('Historical assessment is still loading')
	&& !app.elements.disksBody.innerHTML.includes('No rising trend'),
	'A pending result must explain the missing assessment without inventing stability.');
same(app.diskForecastConfidence(null), 'Pending', 'A current risk must not be substituted for forecast confidence.');
app.fcReady = true;
same(app.diskForecastConfidence(null), 'Not assessed', 'A missing forecast must retain an explicit confidence state.');
ok(app.diskInformation(criticalDisk, null).recent.includes('cannot yet be assessed'),
	'Missing forecasts must not invent recent behaviour.');
ok(app.diskInformation(criticalDisk, {status: 'ok'}).long_term.includes('cannot yet be assessed'),
	'Older payloads without interpretation must not invent longer-term behaviour.');

console.log(`OK: ${checks} display accuracy checks`);
