'use strict';

// Offline browser integration: all requests are intercepted, with synthetic data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
let playwright;
try { playwright = require('playwright'); }
catch (_) { playwright = require(require.resolve('playwright', {paths: [path.resolve(__dirname, '../../Capacity_Planning')]})); }

const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const js = fs.readFileSync(path.join(root, 'assets/js', manifest.assets.js[0]), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css', manifest.assets.css[0]), 'utf8');
const epoch = (date) => Date.parse(date + 'T00:00:00Z') / 1000;
const reportFrom = epoch('2026-07-15');
const reportTo = epoch('2026-10-10') + 86399;
const severities = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
const screenshotDir = process.env.INCIDENT_SCREENSHOT_DIR;
if (screenshotDir) { fs.mkdirSync(screenshotDir, {recursive: true}); }
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }

function aggregate(params) {
  const severity = Number(params.get('severity'));
  const buckets = [7, 8, 9, 10].map((month, i) => {
    const start = Date.UTC(2026, month - 1, 1) / 1000;
    const end = Date.UTC(2026, month, 1) / 1000 - 1;
    const row = {key: '2026-' + month, label: ['Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'][i], start, end};
    for (let s = 0; s < 6; s++) { row['sev_' + s] = s === severity ? [0, 120, 1458, 350, 900, 23][s] * (i + 1) : 0; }
    return row;
  });
  const total = buckets.reduce((n, b) => n + b['sev_' + severity], 0);
  return {buckets, severity_summary: severities.map((label, s) => ({severity: s, label, count: s === severity ? total : 0})),
    meta: {total_incidents: total, granularity: 'month', severity, limit_reached: false, time_from: reportFrom, time_to: reportTo}};
}

function topFixture() {
  const top_triggers = [
    {rank: 1, triggerid: '11', name: 'Synthetic database latency', host: 'demo-db-01', severity: 4, count: 1200,
      first: reportFrom, last: reportTo - 3600, resolved: 1100, duration_sample_count: 1000, recovery_time_unavailable: 100, mttr: 1234, share: 0.6},
    {rank: 2, triggerid: '12', name: 'Synthetic <script> & queue backlog', host: 'demo-worker-01', severity: 2, count: 300,
      first: reportFrom, last: reportTo - 5000, resolved: 0, duration_sample_count: 0, recovery_time_unavailable: 0, mttr: null, share: 0.15}
  ];
  return {top_triggers, meta: {total_incidents: 2000, trigger_count: 3, shown: 2, limit_applied: 2, filtered: true,
    limit_reached: false, recovery_time_unavailable: 100, time_from: reportFrom, time_to: reportTo}};
}

function incidents(params) {
  const c = Number(params.get('time_from')) + 60;
  return {incidents: [
    {eid: '100', oid: '11', n: 'Resolved event with unavailable timestamp', s: 4, c, r: '101', rc: null, status: 'resolved_time_unavailable'},
    {eid: '102', oid: '12', n: 'Synthetic ongoing incident', s: 2, c: c + 10, r: '0', rc: 0, status: 'ongoing'}
  ], meta: {limit_reached: true, recovery_time_unavailable: 1, total_incidents: 2}};
}

function documentHtml(theme) {
  return `<!doctype html><html theme="${theme}"><head><meta charset="utf-8"><style>
    body{margin:0;padding:20px;font:14px Arial,sans-serif;background:${theme === 'dark-theme' ? '#111820' : '#f5f7fa'};color:${theme === 'dark-theme' ? '#eef3f7' : '#1f2b3a'}}
    button,input{font:inherit} h1{font-size:24px;margin:0 0 12px} [hidden]{display:none!important}
    ${css}</style></head><body><h1>Incident Timeline · synthetic preview</h1>
    <main class="incident-timeline-root" id="incident-timeline-root" data-data-url="/data" data-module-version="${manifest.version}"></main>
    <script>${js}</script></body></html>`;
}

async function downloadText(page, button) {
  const pending = page.waitForEvent('download');
  await page.locator('[data-role="' + button + '"]').click();
  const download = await pending;
  return fs.readFileSync(await download.path(), 'utf8');
}

async function takeScreenshot(page, name, fullPage = true) {
  if (screenshotDir) { await page.screenshot({path: path.join(screenshotDir, name + '.png'), fullPage}); }
}

async function layoutCheck(page, label) {
  const layout = await page.evaluate(() => {
    const root = document.querySelector('#incident-timeline-root');
    const charts = [...document.querySelectorAll('[data-role="timeline-surface"], [data-role="trend-surface"], [data-role="severity-surface"]')];
    return {pageWidth: document.documentElement.scrollWidth, viewport: innerWidth, rootWidth: root.getBoundingClientRect().width,
      charts: charts.map(e => ({client: e.clientWidth, scroll: e.scrollWidth, svg: e.querySelector('svg')?.getBoundingClientRect().width}))};
  });
  check(layout.pageWidth <= layout.viewport + 1, `${label}: page must not overflow horizontally (${JSON.stringify(layout)})`);
  check(layout.charts.every(c => c.svg > 0 && c.svg <= c.client + 1), `${label}: charts fit their cards (${JSON.stringify(layout)})`);
  check(layout.charts.every(c => c.scroll <= c.client + 1), `${label}: charts do not require horizontal scrolling`);
}

(async () => {
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    || (process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : undefined);
  const browser = await playwright.chromium.launch({headless: true, executablePath});
  try {
    for (const theme of ['light-theme', 'dark-theme']) {
      const context = await browser.newContext({viewport: {width: 1440, height: 1000}, acceptDownloads: true});
      const page = await context.newPage();
      const errors = [];
      const requests = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin !== 'https://incident.test') { throw new Error('Unexpected external request: ' + url.origin); }
        if (url.pathname === '/data') {
          const params = new URLSearchParams(route.request().postData());
          requests.push(Object.fromEntries(params));
          const mode = params.get('mode');
          const payload = mode === 'resolve' ? {groupids: ['1'], hostids: [], empty: false, truncated: false, summary: '1 host group'}
            : mode === 'top_triggers' ? topFixture() : mode === 'incidents' ? incidents(params) : aggregate(params);
          await route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify(payload)});
        }
        else { await route.fulfill({status: 200, contentType: 'text/html', body: documentHtml(theme)}); }
      });
      await page.goto('https://incident.test/report?from=2026-07-15&to=2026-10-10&bucket=month&group=Demo');
      await page.waitForFunction(() => !document.querySelector('[data-role="export-html"]').disabled);
      check((await page.locator('[data-role="meta"]').innerText()).includes('UTC'), `${theme}: report identifies UTC`);
      check(await page.getByRole('tab', {name: 'Timeline', exact: true}).getAttribute('aria-selected') === 'true', `${theme}: timeline tab selected`);
      check(requests.filter(r => r.mode === 'aggregate').length === 6, `${theme}: all six severities load`);
      await layoutCheck(page, theme + ' desktop');
      await takeScreenshot(page, 'timeline-' + theme + '-desktop');

      await page.setViewportSize({width: 390, height: 900});
      await page.waitForFunction(() => document.querySelector('[data-role="timeline-surface"] svg').getBoundingClientRect().width <= document.querySelector('[data-role="timeline-surface"]').clientWidth + 1);
      await layoutCheck(page, theme + ' mobile');
      await takeScreenshot(page, 'timeline-' + theme + '-mobile');
      await page.setViewportSize({width: 1440, height: 1000});

      // A real pointer click on the first bar must not include July 1–14.
      const firstBar = page.locator('[data-role="timeline-surface"] svg rect[fill="#7499FF"]').first();
      await firstBar.click();
      await page.locator('[data-role="drill-body"] table').waitFor();
      const firstDrill = requests.filter(r => r.mode === 'incidents').at(-1);
      check(Number(firstDrill.time_from) === reportFrom, `${theme}: first bucket drill respects report start`);
      check(Number(firstDrill.time_to) === epoch('2026-08-01') - 1, `${theme}: first bucket ends at July boundary`);
      const drill = await page.locator('[data-role="drill-body"]').innerText();
      check(/unavailable/i.test(drill), `${theme}: missing recovery time is explicitly unavailable`);
      check(/ongoing/i.test(drill), `${theme}: truly ongoing incidents remain identified`);
      check(/limit|partial|incomplete|truncat/i.test(await page.locator('[data-role="drill-card"]').innerText()), `${theme}: drill preserves scan-limit warning`);
      await page.locator('[data-role="drill-close"]').click();
      await page.locator('[data-role="timeline-surface"] svg rect[fill="#7499FF"]').last().click();
      await page.locator('[data-role="drill-body"] table').waitFor();
      check(Number(requests.filter(r => r.mode === 'incidents').at(-1).time_to) === reportTo, `${theme}: final bucket drill respects report end`);

      const csv = await downloadText(page, 'export-csv');
      check(/unavailable/i.test(csv), `${theme}: CSV keeps unavailable recovery status`);
      check(/limit|partial|incomplete|truncat/i.test(csv), `${theme}: CSV contains partial-result notice`);
      const pngPending = page.waitForEvent('download');
      await page.locator('[data-role="export-png"]').click();
      const pngDownload = await pngPending;
      const png = fs.readFileSync(await pngDownload.path());
      check(png.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])) && png.length > 10000, `${theme}: PNG export renders charts to an image`);
      if (screenshotDir) { fs.writeFileSync(path.join(screenshotDir, 'export-' + theme + '.png'), png); }
      await page.getByRole('tab', {name: 'Top triggers', exact: true}).click();
      await page.locator('.incident-top-table').waitFor();
      await page.waitForFunction(() => !document.querySelector('[data-role="export-html"]').disabled);
      check(await page.getByRole('tab', {name: 'Top triggers', exact: true}).getAttribute('aria-selected') === 'true', `${theme}: top tab selected`);
      check(/2.*3/.test(await page.locator('[data-role="meta"]').innerText()), `${theme}: shown and total trigger counts visible`);
      const warnings = await page.locator('[data-role="warning"]').innerText();
      check(/recovery/i.test(warnings), `${theme}: recovery coverage warning visible`);
      check(/shown|showing|limit|display|matching|rank|table/i.test(warnings), `${theme}: display cap warning visible`);
      check(await page.locator('[data-role="top-body"] script').count() === 0, `${theme}: trigger names cannot inject markup`);
      const html = await downloadText(page, 'export-html');
      check(/recovery/i.test(html) && html.includes(warnings), `${theme}: HTML retains the full data-quality warning`);
      await takeScreenshot(page, 'top-triggers-' + theme + '-desktop', false);
      await page.setViewportSize({width: 390, height: 900});
      const width = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, viewport: innerWidth}));
      check(width.scroll <= width.viewport + 1, `${theme}: top table scroll is contained on mobile`);
      await takeScreenshot(page, 'top-triggers-' + theme + '-mobile', true);
      check(errors.length === 0, `${theme}: no browser runtime errors (${errors.join('; ')})`);
      await context.close();
    }
    console.log(`PASS: ${checks} offline browser checks (light/dark, desktop/mobile, drill bounds, recovery coverage, exports).`);
    if (screenshotDir) { console.log('Synthetic screenshots: ' + path.resolve(screenshotDir)); }
  }
  finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
