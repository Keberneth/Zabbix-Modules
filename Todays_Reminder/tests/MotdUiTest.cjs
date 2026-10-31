'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
let playwright;
try { playwright = require('playwright'); }
catch (_) {
    const bundled = path.join(os.homedir(), '.cache', 'codex-runtimes', 'codex-primary-runtime',
        'dependencies', 'node', 'node_modules', 'playwright');
    try { playwright = require(bundled); }
    catch (_) { throw new Error('Playwright is required. Install it or set NODE_PATH to its node_modules directory.'); }
}
const root = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8').replace(/^\uFEFF/, ''));
const source = fs.readFileSync(path.join(root, 'assets/js', manifest.assets.js[0]), 'utf8');
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
const base = {
    title: "Today's Reminder", status: 'ok', issues: [], fingerprint: 'fp-a', preference_key: 'user-a',
    generated_at_text: '2026-10-31 10:00', timezone: 'Europe/Stockholm',
    filters: {groupid: '0', tag: '', tag_value: ''},
    summary_line: 'One High problem', links: {module: 'zabbix.php?action=motd.view'},
    chips: [{kind: 'high', label: 'High', value: '1'}, {kind: 'maintenance', label: 'Today', value: '0'}],
    banner_items: [{text: 'Incident A', url: 'zabbix.php?action=problem.view&triggerids[1]=1'}]
};
const response = data => ({ok: true, data: {...base, ...data}});
const pref = name => 'zbx_motd_v2_user-a_' + name;
const flush = async page => { await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 30))); };

async function setup(browser, options = {}) {
    const context = await browser.newContext();
    const page = await context.newPage();
    const state = {requests: [], queue: [], pending: [], current: response({}), errors: []};
    await page.addInitScript(({blocked, writeBlocked, saved}) => {
        window.__hidden = false;
        window.__clockOffset = 0;
        const realNow = Date.now;
        Date.now = () => realNow() + window.__clockOffset;
        Object.defineProperty(document, 'hidden', {get: () => window.__hidden});
        window.setInterval = fn => { window.__interval = fn; return 1; };
        window.clearInterval = () => {};
        const nativeTimeout = window.setTimeout.bind(window);
        window.setTimeout = (fn, ms, ...args) => {
            if (ms === 20000) { window.__timeout = fn; }
            return nativeTimeout(fn, ms, ...args);
        };
        if (blocked) {
            for (const name of ['localStorage', 'sessionStorage']) {
                Object.defineProperty(window, name, {get: () => { throw new DOMException('blocked', 'SecurityError'); }});
            }
        } else if (saved) {
            for (const [key, value] of Object.entries(saved)) { localStorage.setItem(key, value); }
        }
        if (writeBlocked) { Storage.prototype.setItem = () => { throw new DOMException('full', 'QuotaExceededError'); }; }
    }, {blocked: !!options.blocked, writeBlocked: !!options.writeBlocked, saved: options.saved || null});
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('https://motd.test/**', async route => {
        if (route.request().url().includes('action=motd.banner.data')) {
            state.requests.push(new URL(route.request().url()));
            const item = state.queue.length ? state.queue.shift() : state.current;
            if (item.pending) { state.pending.push(route); return; }
            if (item.http) { return route.fulfill({status: item.http, body: 'Failed'}); }
            return route.fulfill({contentType: 'application/json', body: JSON.stringify(item)});
        }
        const initial = {...base, ...(options.initial || {})};
        const overview = options.overview ? '<div class="motd-page">' +
            '<div id="motd-overview-status" role="status"></div>' +
            '<button id="motd-refresh">Refresh</button><button id="motd-snooze">Snooze</button>' +
            '<button id="motd-resume" hidden>Resume</button><p id="motd-snooze-status"></p>' +
            '<form id="motd-filter-form"><select id="motd-groupid"><option value="0">All groups</option><option value="9">Production</option></select>' +
            '<input id="motd-tag"><input id="motd-tag-value"><button type="submit">Apply</button>' +
            '<button type="button" id="motd-filter-reset">Reset</button></form>' +
            '<div id="motd-overview-content"><p id="initial-summary">Initial overview data</p></div>' +
            '<script type="application/json" id="motd-bootstrap">' + JSON.stringify(initial).replace(/</g, '\\u003c') + '</script></div>' : '';
        await route.fulfill({contentType: 'text/html', body: '<!doctype html><html><head><title>Fixture</title></head><body><main>' + overview + '</main></body></html>'});
    });
    state.load = async (query = options.overview ? '?action=motd.view' : '?action=problem.view') => {
        await page.goto('https://motd.test/zabbix.php' + query);
        await page.addScriptTag({content: source});
        await flush(page);
    };
    state.tick = async () => { await page.evaluate(() => window.__interval()); await flush(page); };
    return {context, page, state};
}

(async () => {
    const browser = await playwright.chromium.launch({
        headless: true,
        ...(process.env.MOTD_BROWSER_CHANNEL ? {channel: process.env.MOTD_BROWSER_CHANNEL}
            : process.platform === 'win32' ? {channel: 'msedge'} : {})
    });
    try {
        {
            const {context, page, state} = await setup(browser);
            await state.load();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'true', 'first tab visit expands');
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-controls') === 'motd-banner-body', 'toggle names controlled body');
            check(await page.locator('.motd-chip--maintenance').count() === 1, 'maintenance chip is visible');
            await state.load();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'false', 'later page load collapses by default');
            await page.locator('#motd-banner-toggle').click();
            state.current = response({fingerprint: 'fp-b', banner_items: [{text: 'Incident B'}]});
            await state.tick();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'true', 'explicit expansion survives content change');
            check((await page.locator('.motd-banner__list').innerText()).includes('Incident B'), 'changed incident is replaced');
            await state.load();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'true', 'explicit preference survives navigation');
            await page.evaluate(() => { window.__oldBanner = document.getElementById('motd-banner-root'); });
            state.current = {ok: true, not_modified: true, fingerprint: 'fp-b', generated_at_text: '2026-10-31 10:01',
                status: 'ok', preference_key: 'user-a', checked_at_text: '10:01:30'};
            await state.tick();
            check(await page.evaluate(() => window.__oldBanner === document.getElementById('motd-banner-root')), 'not modified preserves DOM');
            check((await page.locator('#motd-banner-status').innerText()).includes('10:01:30'), 'not modified updates last checked');
            state.current = {http: 503};
            await state.tick();
            check((await page.locator('.motd-banner__list').innerText()).includes('Incident B'), 'failed refresh retains prior data');
            check((await page.locator('#motd-banner-status').innerText()).includes('Refresh failed'), 'failure is visible');
            check((await page.locator('#motd-banner-status').innerText()).includes('last available data'), 'retained data is marked stale');
            state.current = response({status: 'partial', issues: [{section: 'health', message: 'Proxy access denied'}]});
            await page.locator('#motd-banner-refresh').click(); await flush(page);
            check((await page.locator('#motd-banner-status').innerText()).includes('Some data'), 'partial status visible');
            check((await page.locator('.motd-issues').innerText()).includes('Proxy access denied'), 'partial reasons visible');
            check(!(await page.locator('#motd-banner-status').innerText()).includes('Refresh failed'), 'successful retry clears error');
            const beforeHidden = state.requests.length;
            await page.evaluate(() => { window.__hidden = true; }); await state.tick();
            check(state.requests.length === beforeHidden, 'hidden tabs do not poll');
            await page.evaluate(() => { window.__hidden = false; document.dispatchEvent(new Event('visibilitychange')); }); await flush(page);
            check(state.requests.length === beforeHidden + 1, 'visible tab refreshes immediately');
            state.queue.push({pending: true}); await state.tick();
            const activeCount = state.requests.length; await state.tick();
            check(state.requests.length === activeCount, 'overlapping periodic requests are prevented');
            await page.evaluate(() => window.__timeout()); await flush(page);
            check((await page.locator('#motd-banner-status').innerText()).includes('Refresh failed'), 'timeout is visible');
            check(!(await page.locator('#motd-banner-refresh').isDisabled()), 'timeout releases inflight guard');
            await page.locator('#motd-banner-snooze').click();
            check(await page.locator('#motd-banner-root').count() === 0, 'snooze hides banner');
            const snoozedCount = state.requests.length; await state.tick();
            check(state.requests.length === snoozedCount, 'snooze pauses polling after user identity is known');
            await page.evaluate(() => { window.__clockOffset += 3600001; });
            state.current = response({}); await state.tick();
            check(await page.locator('#motd-banner-root').count() === 1, 'expired snooze restores banner');
            check(state.errors.length === 0, 'no uncaught browser errors');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser, {blocked: true});
            await state.load();
            check(await page.locator('#motd-banner-toggle').innerText() === 'Collapse', 'storage denial retains working initial toggle');
            await page.locator('#motd-banner-toggle').click();
            state.current = response({fingerprint: 'new'}); await state.tick();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'false', 'storage fallback preserves preference in page');
            check(state.errors.length === 0, 'storage denial throws no uncaught errors');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser, {writeBlocked: true,
                saved: {[pref('snooze')]: '0', [pref('expanded')]: 'expanded'}});
            await state.load();
            await page.locator('#motd-banner-toggle').click();
            state.current = response({fingerprint: 'write-denied'}); await state.tick();
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'false', 'failed preference writes override old stored value in memory');
            await page.locator('#motd-banner-snooze').click();
            check(await page.locator('#motd-banner-root').count() === 0, 'failed snooze write overrides old stored zero');
            check(state.errors.length === 0, 'quota errors do not escape');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser, {saved: {[pref('filters')]: JSON.stringify({groupid: '9', tag: 'env', tag_value: 'prod'})}});
            state.queue.push(response({}));
            state.current = response({filters: {groupid: '9', tag: 'env', tag_value: 'prod'}, summary_line: 'Production only'});
            await state.load(); await flush(page);
            check(state.requests.length === 2, 'banner resolves user preference then requests saved scope');
            check(state.requests[1].searchParams.get('groupid') === '9', 'saved group reaches endpoint');
            check(state.requests[1].searchParams.get('tag_value') === 'prod', 'saved exact tag reaches endpoint');
            check((await page.locator('.motd-banner__summary').innerText()) === 'Production only', 'only scoped payload rendered');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser, {overview: true});
            state.current = {ok: true, not_modified: true, fingerprint: 'fp-a', status: 'ok'};
            await state.load();
            check(await page.locator('#motd-banner-root').count() === 0, 'overview never duplicates the banner');
            check(state.requests[0].searchParams.get('overview') === '1', 'overview requests rendered HTML');
            await page.evaluate(() => { window.__controls = document.getElementById('motd-filter-form'); });
            state.current = {...response({fingerprint: 'fp-b'}), html: '<p id="fresh-data">Updated whole overview</p><details class="motd-host-details"><summary>Hosts</summary>host-a</details>'};
            await page.locator('#motd-refresh').click(); await flush(page);
            check(await page.locator('#fresh-data').count() === 1, 'manual refresh updates complete overview content');
            check(await page.evaluate(() => window.__controls === document.getElementById('motd-filter-form')), 'refresh preserves controls');
            await page.locator('.motd-host-details summary').click();
            await state.tick();
            check(await page.locator('.motd-host-details').evaluate(node => node.open), 'expanded host details survive refresh');
            await page.selectOption('#motd-groupid', '9'); await page.fill('#motd-tag', 'env'); await page.fill('#motd-tag-value', 'prod');
            await page.locator('button[type=submit]').click(); await flush(page);
            check(state.requests.at(-1).searchParams.get('groupid') === '9', 'overview group applied');
            check(state.requests.at(-1).searchParams.get('tag') === 'env', 'overview tag applied');
            check(await page.evaluate(key => JSON.parse(localStorage.getItem(key)).tag_value, pref('filters')) === 'prod', 'filters persist per user');
            check(new URL(page.url()).searchParams.get('groupid') === '9', 'applied filters replace stale URL scope');
            await page.locator('#motd-filter-reset').click(); await flush(page);
            check(state.requests.at(-1).searchParams.get('groupid') === '0', 'reset requests all groups');
            check(!new URL(page.url()).searchParams.has('groupid') && !new URL(page.url()).searchParams.has('tag'), 'reset removes explicit URL filters');
            await page.locator('#motd-snooze').click();
            check(await page.locator('#motd-resume').isVisible(), 'overview exposes snooze resume');
            await page.locator('#motd-resume').click();
            check(!(await page.locator('#motd-resume').isVisible()), 'resume clears snooze');
            state.current = {http: 500}; await state.tick();
            check(await page.locator('#fresh-data').count() === 1, 'overview failure retains data');
            check((await page.locator('#motd-overview-status').innerText()).includes('last available data'), 'overview failure marked stale');
            check(state.errors.length === 0, 'overview has no uncaught errors');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser);
            state.current = response({idle_timeout: 300});
            await state.load();
            const first = state.requests.length; await state.tick();
            check(state.requests.length === first, 'automatic refresh cannot extend an idle authenticated session');
            check((await page.locator('#motd-banner-status').innerText()).includes('inactive'), 'idle pause is explained');
            await page.mouse.move(20, 20); await state.tick();
            check(state.requests.length === first + 1, 'trusted interaction resumes automatic refresh');
            await state.tick();
            check(state.requests.length === first + 1, 'one interaction is consumed by one refresh');
            await page.mouse.move(50, 50);
            await page.evaluate(() => { window.__clockOffset += 61000; }); await state.tick();
            check(state.requests.length === first + 1, 'old activity does not extend the session');
            await page.locator('#motd-banner-refresh').click(); await flush(page);
            check(state.requests.length === first + 2, 'manual refresh remains available during idle pause');
            await context.close();
        }
        {
            const {context, page, state} = await setup(browser, {saved: {[pref('snooze')]: String(Date.now() + 3600000)}});
            state.current = response({preference_key: 'user-b'});
            await state.load();
            check(await page.locator('#motd-banner-root').count() === 1, 'another account does not inherit snooze');
            check(await page.locator('#motd-banner-toggle').getAttribute('aria-expanded') === 'true', 'another account has independent visit state');
            await context.close();
        }
        console.log('PASS: ' + checks + ' browser assertions.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
