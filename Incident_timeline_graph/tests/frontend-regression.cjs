'use strict';

// No browser/dependencies needed: exercise the real controller with a minimal DOM
// and deliberately out-of-order server responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const source = fs.readFileSync(path.join(root, 'assets/js', manifest.assets.js[0]), 'utf8');
const location = {href: 'https://example.test/zabbix.php?action=incident.timeline.view'};
const context = vm.createContext({URL, URLSearchParams, AbortController, DOMException, Error, Blob,
  setTimeout, clearTimeout, console,
  document: {addEventListener() {}, removeEventListener() {}},
  window: {location, history: {replaceState(_state, _title, url) { location.href = url; }}}
});
vm.runInContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.TestApp = IncidentTimelineApp; })();'), context);
const App = context.TestApp;
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
function el(value = '') {
  return {value, textContent: '', innerHTML: '', disabled: false, hidden: false, style: {},
    setAttribute() {}, querySelector() { return null; }, querySelectorAll() { return []; },
    scrollIntoView() {}, classList: {toggle() {}, remove() {}}};
}
function app(severities = [5]) {
  const a = new App({dataset: {}, setAttribute() {}, querySelectorAll() { return []; }});
  a.elements = Object.fromEntries(['from', 'to', 'prev', 'next', 'loading', 'error', 'warning',
    'exportCsv', 'exportHtml', 'exportPng', 'timelineSurface', 'trendSurface', 'severitySurface',
    'summary', 'meta', 'filterSummary', 'timelineSubtitle', 'topBody', 'drillCard', 'drillTitle',
    'drillSubtitle', 'drillBody', 'resetZoom'].map((name) => [name, el()]));
  a.elements.from.value = '2026-01-15'; a.elements.to.value = '2026-01-20';
  a.elements.granularity = {querySelector() { return el(); }};
  a.activeSeverities = new Set(severities);
  a.renderTimeline = () => {}; a.renderTrendLine = () => {};
  a.renderSeverityChart = (rows) => { a.renderedSeverities = rows; };
  a.requestRange = a.getSelectedRange();
  return a;
}
function aggregate(count = 3, severity = 5) {
  return {buckets: [{key: '2026-01', label: 'Jan 2026', start: 1767225600, end: 1769903999,
    ['sev_' + severity]: count}], meta: {total_incidents: count, granularity: 'month'}};
}
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return {promise, resolve, reject}; }
const flush = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
  check(App.dateInputToEpoch('2026-02-30', false) === null, 'Reject impossible calendar dates');
  check(App.dateInputToEpoch('2024-02-29', false) !== null, 'Accept leap day');
  check(App.epochToDateInput(App.monthsBefore(new Date('2026-10-31T00:00:00Z'), 1).getTime() / 1000) === '2026-09-30', 'Month preset clamps to September end');
  check(App.epochToDateInput(App.monthsBefore(new Date('2024-03-31T00:00:00Z'), 1).getTime() / 1000) === '2024-02-29', 'Month preset respects leap years');
  const a = app();
  const narrowLabels = a.chartLabelIndexes(120, 180);
  check(narrowLabels.size <= 3 && narrowLabels.has(0) && narrowLabels.has(119), 'Dense narrow charts retain first/last dates without crowded labels');
  for (const max of [1, 3, 1561, 6163, 32269, 1000000]) {
    const ticks = a.buildTicks(max, 5);
    check(ticks[0] === 0 && ticks.at(-1) >= max, 'Axis contains all values: ' + max);
    check(ticks.length <= 7 && ticks.slice(1).every((v, i) => v - ticks[i] === ticks[1]), 'Ticks have equal spacing: ' + max);
  }
  a.activeSeverities = new Set(); a.updateLocationState();
  check(new URL(location.href).searchParams.has('severities') && new URL(location.href).searchParams.get('severities') === '', 'Empty severity selection is preserved');
  a.activeSeverities = new Set([4, 5]); a.updateLocationState();
  check(new URL(location.href).searchParams.get('severities') === '4,5', 'Selected severity scope is preserved');
  check(a.recoveryLabel({r: '123', rc: null, status: 'resolved_time_unavailable'}) === 'Resolved (time unavailable)', 'Unknown recovery time is not ongoing');
  check(a.recoveryLabel({r: '0', rc: 0, status: 'ongoing'}) === 'Ongoing', 'Known ongoing status retained');
  check(a.csvEscape('=HYPERLINK("x")').startsWith('"\''), 'CSV formula values are neutralized');
  a.elements.to.value = App.epochToDateInput(Date.now() / 1000);
  a.setLoading(true); a.setLoading(false);
  check(a.elements.next.disabled, 'Next stays disabled at today after loading');

  const progressive = app();
  progressive.fetchData = async () => aggregate();
  await progressive.loadTimeline();
  check(progressive.tl.ready && !progressive.elements.exportCsv.disabled, 'Completed selected severity permits export');
  const added = deferred();
  progressive.activeSeverities.add(4);
  progressive.fetchData = () => added.promise;
  const supplement = progressive.loadTimeline(); await flush();
  check(!progressive.tl.ready && progressive.elements.exportCsv.disabled, 'Adding an uncached severity disables export');
  check(progressive.elements.summary.innerHTML.includes('Loading…') && progressive.elements.summary.innerHTML.includes('Loaded subtotal'), 'Loading severity is not presented as zero or complete total');
  added.reject(new Error('Simplify the regular expression.')); await supplement;
  check(!progressive.tl.ready && progressive.elements.exportHtml.disabled, 'Failed series cannot export complete-looking report');
  check(progressive.elements.summary.innerHTML.includes('Unavailable'), 'Failed series has explicit unavailable count');
  check(progressive.elements.error.textContent.includes('Simplify the regular expression.'), 'Actionable backend error survives per-severity failure');
  progressive.fetchData = async (params) => aggregate(7, Number(params.severity));
  await progressive.loadTimeline();
  check(progressive.tl.ready && progressive.tl.totals[5] === 3 && progressive.tl.totals[4] === 7, 'Retry fills missing series and retains good cached series');

  const unavailable = app();
  unavailable.renderTimeline = App.prototype.renderTimeline;
  unavailable.fetchData = async () => { throw new Error('API unavailable'); };
  await unavailable.loadTimeline();
  check(unavailable.elements.timelineSurface.innerHTML.includes('not available') && !unavailable.elements.timelineSurface.innerHTML.includes('No incidents were found'), 'Total API failure is not labeled as an empty incident history');

  const stale = app(); const oldData = deferred();
  stale.fetchData = () => oldData.promise;
  const first = stale.loadTimeline(); await flush();
  stale.filters.name = 'new selection'; stale.fetchData = async () => aggregate(9);
  await stale.loadTimeline(); oldData.resolve(aggregate(100)); await first;
  check(stale.tl.ready && stale.tl.totals[5] === 9 && stale.tl.inflight.size === 0, 'Late old timeline response cannot overwrite new selection or loading state');

  const resolveRace = app(); const oldResolve = deferred();
  resolveRace.filters.host = 'old'; resolveRace.fetchData = () => oldResolve.promise;
  const resolving = resolveRace.ensureResolved(null);
  resolveRace.filters.host = 'new';
  resolveRace.fetchData = async () => ({hostids: ['2'], groupids: [], empty: false});
  await resolveRace.ensureResolved(null);
  oldResolve.resolve({hostids: ['1'], groupids: [], empty: false});
  await assert.rejects(resolving, {name: 'AbortError'}); checks++;
  check(resolveRace.resolved.hostids[0] === '2', 'Old filter resolver cannot overwrite newer host scope');

  const top = app(); top.activeTab = 'top'; const pendingTop = deferred();
  top.fetchData = () => pendingTop.promise;
  const topLoad = top.loadTopTriggers(); await flush();
  top.activeSeverities.clear(); await top.loadTopTriggers();
  pendingTop.resolve({top_triggers: [{count: 99}], meta: {}}); await topLoad;
  check(!top.top.ready && top.top.rows.length === 0 && top.elements.topBody.innerHTML.includes('No severities'), 'Deselection cancels in-flight top-trigger load');

  const drill = app(); const pendingDrill = deferred();
  const bucket = {label: 'Jan', start: 1767225600, end: 1769903999};
  let request;
  drill.fetchData = (params) => { request = params; return pendingDrill.promise; };
  const drillLoad = drill.drillInto(bucket);
  check(request.time_from === drill.requestRange.time_from && request.time_to === drill.requestRange.time_to, 'Details are clipped to selected portion of calendar bucket');
  drill.hideDrill(); pendingDrill.resolve({incidents: [{n: 'stale'}], meta: {}}); await drillLoad;
  check(drill.elements.drillCard.hidden && drill.elements.drillBody.innerHTML === '', 'Closed drilldown ignores late server data');
  drill.renderDrill(bucket, [{s: 5, c: 1768435200, n: '<unsafe>', r: '88', rc: null, status: 'resolved_time_unavailable'}], {limit_reached: true, recovery_time_unavailable: 1});
  check(drill.elements.drillSubtitle.textContent.includes('Partial results') && drill.elements.drillBody.innerHTML.includes('Resolved (time unavailable)'), 'Drilldown preserves scan and recovery uncertainty');
  check(drill.elements.drillBody.innerHTML.includes('&lt;unsafe&gt;'), 'Incident names remain escaped');

  const csv = app(); csv.tl.ready = true; const csvPending = deferred();
  csv.fetchData = () => csvPending.promise;
  const csvLoad = csv.ensureIncidents();
  csv.cancelExport(); csvPending.resolve({incidents: [], meta: {}});
  await assert.rejects(csvLoad, {name: 'AbortError'}); checks++;
  check(csv._incidentsSig === undefined, 'Cancelled export is not cached');

  const report = app(); report.activeTab = 'top';
  report.top = {ready: true, rows: [], meta: {filtered: true, shown: 1000, trigger_count: 1001, total_incidents: 1500, recovery_time_unavailable: 2}, sort: {key: 'count', dir: 'desc'}};
  report.renderTopMeta();
  check(report.elements.meta.textContent.includes('1000 of 1001'), 'Capped ranking shows returned and matching trigger totals');
  check(report.elements.warning.textContent.includes('first 1000') && report.elements.warning.textContent.includes('2 resolved'), 'Ranking and recovery coverage warnings are preserved');
  report.elements.topBody.innerHTML = '<table></table>';
  const html = report.buildReportHtml();
  check(html.includes('first 1000') && html.includes('2 resolved') && html.includes('All times are UTC'), 'HTML export carries coverage and timezone');
  report.top.rows = [{name: '=unsafe', count: 3, severity: 5, resolved: 2, duration_sample_count: 1, recovery_time_unavailable: 1}];
  let exported;
  report.downloadBlob = (blob) => { exported = blob; };
  report.exportTopCsv();
  const csvText = await exported.text();
  check(csvText.includes('Resolution duration samples') && csvText.includes('first 1000'), 'Top CSV retains MTTR coverage and ranking cap');

  context.fetch = async () => ({text: async () => '', ok: true});
  await assert.rejects(a.fetchData({}, null), /empty response/); checks++;
  context.fetch = async () => ({text: async () => 'null', ok: true});
  await assert.rejects(a.fetchData({}, null), /invalid data response/); checks++;
  console.log(`PASS: ${checks} frontend regression checks`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
