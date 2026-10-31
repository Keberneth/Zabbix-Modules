/* Focused browser checks against the real PHP view and JS, with mocked API responses.
 * Run: node tests/ui.test.cjs (PHP_BINARY optional; otherwise uses local php:8.4-cli Docker image).
 * Playwright may be installed locally or in ../Capacity_Planning/node_modules.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const {chromium} = require(require.resolve('playwright', {paths: [root, path.resolve(root, '../Capacity_Planning')]}));
const render = process.env.PHP_BINARY
  ? spawnSync(process.env.PHP_BINARY, ['tests/render-ui.php'], {cwd: root, encoding: 'utf8'})
  : spawnSync('docker', ['run', '--rm', '--network', 'none', '-v', `${root}:/app:ro`, '-w', '/app', 'php:8.4-cli', 'php', 'tests/render-ui.php'], {encoding: 'utf8'});
assert.equal(render.status, 0, render.stderr);
assert.ok(!render.stdout.includes('Warning:'), render.stdout);
const html = '<!doctype html><html><head><meta charset="utf-8"><style>' + fs.readFileSync(path.join(root, 'assets/css/trigger_correlation.css'), 'utf8') + '</style></head><body>' + render.stdout + '<script>' + fs.readFileSync(path.join(root, 'assets/js/trigger_correlation.js'), 'utf8') + '</script></body></html>';
const server = http.createServer((req, res) => {res.setHeader('Content-Type', 'text/html'); res.end(html);});
const errors = [];
const calls = [];
let checks = 0;
let severityUnavailable = false;
let dashboardData = {ok: true, generated_at: 100, summary: {}, correlations: [], escalations: []};
async function check(label, fn) { await fn(); checks++; console.log('PASS ' + label); }
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({headless: true, channel: process.env.BROWSER_CHANNEL || 'chrome'});
  const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => dialog.dismiss());
  await page.route('**/zabbix.php?**', async route => {
    const request = route.request(); const url = new URL(request.url()); const action = url.searchParams.get('action');
    const posted = request.postData() || ''; const form = new URLSearchParams();
    if ((request.headers()['content-type'] || '').includes('multipart/form-data')) {
      for (const match of posted.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)) { form.set(match[1], match[2]); }
    } else { new URLSearchParams(posted).forEach((value, key) => form.set(key, value)); }
    calls.push({action, form});
    let body = {ok: true};
    if (action === 'triggercorrelation.dashboard.data') { body = dashboardData; }
    if (action === 'triggercorrelation.search.hosts') {
      const query = url.searchParams.get('q');
      await new Promise(resolve => setTimeout(resolve, query === 'old' ? 400 : 10));
      body = {ok: true, items: [{hostid: query === 'old' ? '9' : '1', name: query, host: query, label: query}]};
    }
    if (action === 'triggercorrelation.preview') { body = {ok: true, result: {valid: false, active_count: 1, total: 2, candidate_state: null, state: 4, status: 'unknown', conditions: [{host: 'web01', trigger: 'Service down', active: true, available: true, reason: 'Active'}, {host: 'db01', trigger: 'Database down', active: false, available: false, reason: 'Token cannot see source'}], errors: ['Source unavailable'], selected_severities: [4], targets: []}}; }
    if (action === 'triggercorrelation.preview' && form.get('kind') === 'severity') {
      body = {ok: true, result: {active: true, severity: 5, active_count: 1, total: 1, candidate_state: 1, state: 1, status: 'applied', conditions: [], errors: [], targets: [
        {eventid: '100', host: 'db01', name: 'Database down', severity: 2, effective_severity: 5, requested_severity: 5, would_change: true, manual_override: false},
        {eventid: '101', host: 'db02', name: 'Database down', severity: 3, effective_severity: 5, would_change: false, manual_override: true}
      ], restorations: [{eventid: '102', host: 'db03', name: 'Previous target', severity: 5, effective_severity: 2, available: true, would_change: true}]}};
      if (severityUnavailable) { body = {ok: true, result: {valid: false, active: false, severity: 5, candidate_state: null, state: 1, status: 'unknown', conditions: [], errors: ['Source unavailable'], targets: [], restorations: []}}; }
    }
    if (action === 'triggercorrelation.rules.import') { body = {ok: true, preview: {rules_count: 1, severity_rules_count: 0, conflicts: [], warnings: [], errors: []}}; }
    if (action === 'triggercorrelation.run') { body = {ok: true, result: {rules_evaluated: 0, errors: ['Ledger recovery pending'], severity: {rules_evaluated: 0, errors: ['Event 555 unavailable'], persist_error: 'Disk write failed', rules: [{name: 'Rule A', error: 'Target query failed'}]}}, rules: [], severity_rules: []}; }
    if (action === 'triggercorrelation.severity.rule.delete') { body = {ok: false, pending: true, error: 'Recovery pending; disabled rule retained.', rules: [], severity_rules: [{id: 's1', name: 'Raise database', enabled: false, last_state: 1, last_error: 'Recovery pending', conditions: [], targets: []}]}; }
    await route.fulfill({contentType: 'application/json', body: JSON.stringify(body)});
  });
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  await check('view initializes without JavaScript errors', async () => { await page.waitForSelector('#tc-rule-summary', {state: 'attached'}); assert.deepEqual(errors, []); });
  await page.click('[data-tab="rules"].ai-settings-tab');
  await page.click('.tc-edit[data-id="r1"]');
  await check('simple editor derives only the chosen severity', async () => {
    assert.match(await page.locator('#tc-selected-severities').textContent(), /Creates High only/);
    assert.equal(await page.locator('#tc-output-advanced').getAttribute('open'), null);
    await page.screenshot({path: path.join(require('node:os').tmpdir(), 'trigger-correlation-editor.png'), fullPage: true});
  });
  await check('preview sends draft and never runs a live evaluation', async () => {
    await page.click('#tc-preview-rule'); await page.waitForSelector('#tc-preview-correlation h3');
    assert.match(await page.locator('#tc-preview-correlation').textContent(), /Token cannot see source/);
    assert.match(await page.locator('#tc-preview-correlation').textContent(), /Expected result: unknown; previous applied result retained/);
    const call = calls.find(call => call.action === 'triggercorrelation.preview');
    assert.equal(call.form.get('kind'), 'correlation');
    assert.equal(JSON.parse(call.form.get('rule')).output.match_value, 4);
    assert.ok(!calls.some(call => call.action === 'triggercorrelation.run'));
  });
  await check('duplicate creates a disabled draft with a fresh identity', async () => {
    await page.click('.tc-duplicate[data-id="r1"]');
    assert.equal(await page.locator('#tc-rule-editor').getAttribute('data-rule-id'), '');
    assert.equal(await page.locator('#tc-rule-enabled').isChecked(), false);
    assert.equal(await page.locator('#tc-correlation-id').inputValue(), '');
    assert.equal(await page.locator('#tc-rule-name').inputValue(), 'Web outage (copy)');
  });
  await check('new-rule action cannot silently discard a dirty editor', async () => {
    await page.fill('#tc-rule-name', 'Unsaved name'); await page.click('#tc-reset-rule');
    assert.equal(await page.locator('#tc-rule-name').inputValue(), 'Unsaved name');
  });
  await check('draft survives a page reload', async () => {
    await page.evaluate(() => { window.onbeforeunload = null; });
    // Explicit navigation in automation bypasses the dismissed beforeunload prompt.
    await page.evaluate(() => sessionStorage.setItem('testReload', '1'));
    page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.accept());
    await page.reload();
    assert.equal(await page.locator('#tc-rule-name').inputValue(), 'Unsaved name');
    assert.match(await page.locator('#tc-draft-notice').textContent(), /Restored/);
    page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.dismiss());
  });
  await check('keyboard selection ignores late autocomplete results', async () => {
    const input = page.locator('#tc-conditions .cond-host').first();
    await input.fill('old'); await page.waitForTimeout(270); await input.fill('new'); await page.waitForTimeout(720);
    const dropdown = page.locator('#tc-conditions .tc-typeahead').first().locator('[role="listbox"]');
    assert.equal(await dropdown.locator('[role="option"]').first().textContent(), 'new');
    await input.press('ArrowDown'); await input.press('Enter');
    assert.equal(await page.locator('#tc-conditions .cond-hostid').first().inputValue(), '1');
    assert.equal(await input.getAttribute('aria-expanded'), 'false');
  });
  await check('filters hide unrelated rule rows', async () => {
    await page.fill('#tc-rules-search', 'does not exist');
    assert.equal(await page.locator('[data-rule-row="correlation"]').isVisible(), false);
    await page.fill('#tc-rules-search', 'web');
    assert.equal(await page.locator('[data-rule-row="correlation"]').isVisible(), true);
  });
  await check('dynamic source mode collects group and timing in preview', async () => {
    await page.selectOption('#tc-source-mode', 'group');
    await page.fill('#tc-source-group', 'Cluster');
    await page.locator('.tc-source-options[data-source-prefix="tc"] .tc-source-groupid').evaluate(input => {input.value = '7';});
    await page.fill('#tc-source-trigger', 'Node down');
    await page.locator('#tc-activation').evaluate(input => {input.value = '60';});
    await page.click('#tc-preview-rule'); await page.waitForTimeout(100);
    const call = calls.filter(call => call.action === 'triggercorrelation.preview').at(-1);
    const rule = JSON.parse(call.form.get('rule')); assert.equal(rule.source_selector.groupid, '7'); assert.equal(rule.activation_seconds, 60);
  });
  await check('import requires a reviewed file and displays disabled-rule behavior', async () => {
    await page.click('[data-tab="settings"].ai-settings-tab');
    await page.setInputFiles('#tc-import-file', {name: 'rules.json', mimeType: 'application/json', buffer: Buffer.from('{"format":"trigger-correlation-rules","version":1,"rules":[]}')});
    await page.waitForSelector('#tc-import-preview h3');
    assert.match(await page.locator('#tc-import-preview').textContent(), /Imported rules start disabled/);
    assert.equal(await page.locator('#tc-import-apply').isEnabled(), true);
    assert.ok(!calls.some(call => call.action === 'triggercorrelation.rules.import' && call.form.get('op') === 'apply'));
    await page.screenshot({path: path.join(require('node:os').tmpdir(), 'trigger-correlation-settings.png'), fullPage: true});
  });
  await check('severity preview shows actual changes, operator overrides and recovery', async () => {
    await page.click('[data-tab="severity"].ai-settings-tab'); await page.click('.sev-edit[data-id="s1"]');
    await page.click('#tc-sev-preview'); await page.waitForSelector('#tc-preview-severity h3');
    const preview = await page.locator('#tc-preview-severity').textContent();
    assert.match(preview, /current Warning. Would change to Disaster/);
    assert.match(preview, /operator severity is preserved/);
    assert.match(preview, /Would restore\/reconcile to Warning/);
    assert.ok(!preview.includes('Expected result: Information'));
  });
  await check('unavailable severity sources never imply recovery', async () => {
    severityUnavailable = true;
    await page.click('#tc-sev-preview');
    await page.waitForFunction(() => document.querySelector('#tc-preview-severity').textContent.includes('unknown; previous applied result retained'));
    const text = await page.locator('#tc-preview-severity').textContent();
    assert.ok(!text.includes('release this rule'));
    severityUnavailable = false;
  });
  await check('pending severity deletion refreshes disabled state and retains drafts', async () => {
    page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.accept());
    await page.click('.sev-delete[data-id="s1"]');
    await page.waitForFunction(() => document.querySelector('[data-rule-row="severity"][data-id="s1"]').cells[0].textContent === 'Disabled');
    assert.match(await page.locator('[data-rule-row="severity"][data-id="s1"]').textContent(), /Recovery pending/);
    assert.equal(await page.locator('#tc-rule-name').inputValue(), 'Unsaved name');
    page.removeAllListeners('dialog'); page.on('dialog', dialog => dialog.dismiss());
  });
  await check('live evaluation retains draft and reports ledger and persistence failures', async () => {
    await page.click('#tc-run-all'); await page.waitForTimeout(100);
    assert.equal(await page.locator('#tc-rule-name').inputValue(), 'Unsaved name');
    const text = await page.locator('#tc-status').textContent();
    for (const message of ['Ledger recovery pending', 'Event 555 unavailable', 'Disk write failed', 'Target query failed']) { assert.ok(text.includes(message), text); }
    assert.match(await page.locator('#tc-status').getAttribute('class'), /ai-status-error/);
  });
  await check('dashboard separates expected and applied status with unavailable sources', async () => {
    dashboardData = {ok: true, generated_at: Date.now() / 1000, summary: {}, correlations: [{id: 'd1', name: 'Pending database', enabled: true, state: 0, candidate_state: 4, stored_state: 0, status: 'pending_activation', pending_seconds: 45, active: 2, total: 2, match: 'all', source_issues: [], members: [{name: 'db01', triggers: [{description: 'Database down', available: false, reason: 'Source unavailable', problem: null}]}]}], escalations: []};
    await page.click('[data-tab="dashboard"].ai-settings-tab'); await page.click('#tc-dash-refresh'); await page.waitForSelector('.tc-evaluation-pending_activation');
    assert.match(await page.locator('.tc-evaluation-status').textContent(), /Expected High · Applied OK · 45s remaining/);
    assert.match(await page.locator('.tc-members').textContent(), /Unavailable/);
    await page.selectOption('#tc-dashboard-state', 'attention'); assert.equal(await page.locator('.tc-card').count(), 1);
  });
  await page.screenshot({path: path.join(require('node:os').tmpdir(), 'trigger-correlation-ui.png'), fullPage: true});
  assert.deepEqual(errors, []);
  console.log(`${checks} browser checks passed.`);
  await browser.close(); server.close();
})().catch(error => {console.error(error); server.close(); process.exit(1);});
