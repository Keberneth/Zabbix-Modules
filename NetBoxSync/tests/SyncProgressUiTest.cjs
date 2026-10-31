'use strict';

// Real rendered view + real browser, with controlled offline run/status responses.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

if (!process.argv[2]) throw new Error('Usage: node tests/SyncProgressUiTest.cjs rendered-settings.html');
let html = fs.readFileSync(process.argv[2], 'utf8');
if (process.env.NBS_ZABBIX_THEME_CSS) {
    html = html.replace('<style>', '<style>' + fs.readFileSync(process.env.NBS_ZABBIX_THEME_CSS, 'utf8') + '</style><style>')
        .replace('data-nbs-theme="light"', 'data-nbs-theme="dark"');
}
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    || (process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : undefined);
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function same(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }
function parseForm(request) {
    const values = {};
    const boundary = (request.headers()['content-type'] || '').match(/boundary=(.+)$/)?.[1];
    assert.ok(boundary, 'Run submits FormData');
    for (const part of (request.postData() || '').split('--' + boundary)) {
        const name = part.match(/name="([^"]+)"/)?.[1];
        if (name) values[name] = part.split('\r\n\r\n').slice(1).join('\r\n\r\n').replace(/\r\n$/, '');
    }
    return values;
}

function queue() {
    const available = [];
    const waiting = [];
    return {
        push(value) {
            if (waiting.length) waiting.shift()(value);
            else available.push(value);
        },
        next() {
            if (available.length) return Promise.resolve(available.shift());
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('Timed out waiting for a mocked request')), 7000);
                waiting.push(value => { clearTimeout(timer); resolve(value); });
            });
        }
    };
}

async function harness(browser) {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    const runs = queue();
    const polls = queue();
    const state = {context, page, runs, polls, pollCount: 0, runCount: 0, errors: []};
    page.on('pageerror', error => state.errors.push(error.message));
    page.on('dialog', dialog => dialog.dismiss());
    await context.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname === '/settings') {
            await route.fulfill({contentType: 'text/html', body: html});
        }
        else if (url.pathname === '/run') {
            state.runCount++;
            runs.push({route, form: parseForm(request)});
        }
        else if (url.pathname === '/run-status') {
            state.pollCount++;
            polls.push({route, runId: url.searchParams.get('run_id'), method: request.method()});
        }
        else await route.abort();
    });
    await page.goto('https://netboxsync.test/settings');
    state.start = async () => {
        await page.locator('#nbs-run-now').click();
        const run = await runs.next();
        state.run = run;
        check(/^[a-f0-9]{32}$/.test(run.form.run_id || ''), 'Run identifier uses 128 random bits encoded as hex');
        same(run.form._csrf_token, 'fixture-netboxsync.run', 'Run remains CSRF protected');
        await page.locator('#nbs-run-progress').waitFor({state: 'visible'});
        return run;
    };
    state.reply = async (poll, progress, status = 200) => {
        same(poll.method, 'GET', 'Progress lookup is read-only');
        same(poll.runId, state.run.form.run_id, 'Status request is tied to this run identifier');
        await poll.route.fulfill({status, contentType: 'application/json', body: JSON.stringify({ok: status === 200, progress})});
    };
    return state;
}

function progress(runId, percent, step, overrides = {}) {
    return {run_id: runId, phase: 'syncing', running: true, percent,
        hosts_completed: percent === null ? 0 : Math.round(percent / 25), hosts_total: 4,
        total_known: percent !== null, errors: 0,
        updated_at: new Date(Date.UTC(2026, 9, 31, 12, 0, step)).toISOString(), ...overrides};
}
async function expectPercent(page, value) {
    await page.waitForFunction(expected => {
        const bar = document.querySelector('#nbs-run-progress-bar');
        return bar && bar.hasAttribute('value') && Number(bar.getAttribute('value')) === expected;
    }, value);
    same((await page.locator('#nbs-run-progress-percent').innerText()).trim(), value + '%', 'Text percent matches the server snapshot');
}
async function screenshot(page, name) {
    if (!process.env.NBS_UI_SCREENSHOT_DIR) return;
    fs.mkdirSync(process.env.NBS_UI_SCREENSHOT_DIR, {recursive: true});
    await page.screenshot({path: path.join(process.env.NBS_UI_SCREENSHOT_DIR, name), fullPage: true});
}

(async () => {
    const browser = await chromium.launch({headless: true, executablePath});
    try {
        // A deliberately slow POST allows observation of real server snapshots.
        const main = await harness(browser);
        const page = main.page;
        const buttons = await page.locator('.nbs-header-actions .nbs-button, #nbs-test-connection, #nbs-test-zabbix-connection').evaluateAll(items => items.map(item => {
            const css = getComputedStyle(item);
            return {label: item.textContent.trim(), height: item.getBoundingClientRect().height,
                border: parseFloat(css.borderTopWidth), background: css.backgroundColor, decoration: css.textDecorationLine};
        }));
        same(buttons.length, 5, 'All visible actions use explicit button styling');
        for (const button of buttons) {
            check(button.height >= 32, button.label + ' keeps a usable hit area under frontend styles');
            check(button.border >= 1 || !['transparent', 'rgba(0, 0, 0, 0)'].includes(button.background), button.label + ' retains its button affordance');
            check(!button.decoration.includes('underline'), button.label + ' does not fall back to an ordinary link');
        }
        await screenshot(page, process.env.NBS_ZABBIX_THEME_CSS ? 'netboxsync-buttons-zabbix-dark.png' : 'netboxsync-buttons.png');
        const run = await main.start();
        check(await page.locator('#nbs-run-now').isDisabled(), 'Run action stays disabled while work is pending');
        check(await page.locator('[name="netbox[url]"]').isDisabled(), 'Settings cannot be changed during a run');
        check(!(await page.locator('#nbs-run-progress-bar').getAttribute('value')), 'Initial progress is indeterminate');
        await main.reply(await main.polls.next(), null);
        check(!(await page.locator('#nbs-run-progress-bar').getAttribute('value')), 'No snapshot does not invent a percentage');
        await main.reply(await main.polls.next(), progress(run.form.run_id, null, 1, {phase: 'preparing', hosts_total: 0}));
        check(!(await page.locator('#nbs-run-progress-bar').getAttribute('value')), 'Unknown preparation stays indeterminate');
        await main.reply(await main.polls.next(), progress(run.form.run_id, 0, 2));
        await expectPercent(page, 0);
        await main.reply(await main.polls.next(), progress(run.form.run_id, 25, 3));
        await expectPercent(page, 25);
        check(/1\s*\/\s*4/.test(await page.locator('#nbs-run-progress-counts').innerText()), 'Progress shows completed host count');

        const slowPoll = await main.polls.next();
        const heldCount = main.pollCount;
        await page.waitForTimeout(1300);
        same(main.pollCount, heldCount, 'Pending status requests never overlap');
        await expectPercent(page, 25);
        await screenshot(page, process.env.NBS_ZABBIX_THEME_CSS ? 'netboxsync-progress-zabbix-dark.png' : 'netboxsync-progress.png');
        await main.reply(slowPoll, progress(run.form.run_id, 75, 5));
        await expectPercent(page, 75);

        await main.reply(await main.polls.next(), progress('f'.repeat(32), 100, 6));
        await expectPercent(page, 75);
        await main.reply(await main.polls.next(), progress(run.form.run_id, 25, 3));
        await expectPercent(page, 75);
        await main.reply(await main.polls.next(), null, 502);
        await page.waitForFunction(() => /unavailable|cannot|could not|not available/i.test(document.querySelector('#nbs-run-progress').innerText));
        check(!/100%/.test(await page.locator('#nbs-run-progress').innerText()), 'Polling failure never becomes completion');
        check(await page.locator('#nbs-run-now').isDisabled(), 'Polling failure does not pretend the pending run has stopped');

        const terminal = progress(run.form.run_id, 100, 8, {phase: 'failed', running: false, errors: 1});
        await main.reply(await main.polls.next(), terminal);
        await expectPercent(page, 100);
        check(await page.locator('#nbs-run-progress').evaluate(el => el.classList.contains('is-error')), 'Completed host attempts with errors are shown as failure');
        const reloaded = page.waitForEvent('load');
        await run.route.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({
            ok: false, summary_saved: true, error: 'Fixture partial failure', progress: terminal,
            summary: {ok: false, run_id: run.form.run_id, hosts_processed: 3, hosts_completed: 4, hosts_total: 4,
                mappings_run: 4, errors: 1, progress: terminal}
        })});
        await reloaded;
        same(await page.locator('#nbs-tab-last-run').getAttribute('aria-selected'), 'true', 'Final errors still lead to Last run');
        same(main.errors, [], 'Progress path has no uncaught browser errors');
        await main.context.close();

        // Broken POST must restore controls and stop timers/in-flight status reads.
        const broken = await harness(browser);
        const brokenRun = await broken.start();
        await broken.reply(await broken.polls.next(), progress(brokenRun.form.run_id, 25, 1));
        await expectPercent(broken.page, 25);
        await broken.polls.next(); // Leave one status request pending while POST fails.
        await brokenRun.route.abort('failed');
        await broken.page.waitForFunction(() => !document.querySelector('#nbs-run-now').disabled);
        check(await broken.page.locator('[name="netbox[url]"]').isEnabled(), 'Transport failure restores editable settings');
        check(/fail|unknown|uncertain|request|connection/i.test(await broken.page.locator('#nbs-status').innerText()), 'Transport failure is reported truthfully');
        check(!/Run completed\./.test(await broken.page.locator('#nbs-status').innerText()), 'Transport failure is never success');
        const stoppedAt = broken.pollCount;
        await broken.page.waitForTimeout(2300);
        same(broken.pollCount, stoppedAt, 'No status polling continues after the failed POST');
        same(broken.runCount, 1, 'Failure does not retry a write automatically');
        same(broken.errors, [], 'Transport failure has no uncaught browser errors');
        await broken.context.close();

        // Status transport failure starts indeterminate and can recover to real zero.
        const recovery = await harness(browser);
        const recoveryRun = await recovery.start();
        await (await recovery.polls.next()).route.abort('failed');
        await recovery.page.waitForFunction(() => /unavailable|cannot|could not|not available/i.test(document.querySelector('#nbs-run-progress').innerText));
        check(!(await recovery.page.locator('#nbs-run-progress-bar').getAttribute('value')), 'Unknown transport failure leaves the bar indeterminate');
        await recovery.reply(await recovery.polls.next(), progress(recoveryRun.form.run_id, 0, 1));
        await expectPercent(recovery.page, 0);
        const success = progress(recoveryRun.form.run_id, 100, 2, {phase: 'completed', running: false});
        await recovery.reply(await recovery.polls.next(), success);
        await expectPercent(recovery.page, 100);
        check(!await recovery.page.locator('#nbs-run-progress').evaluate(el => el.classList.contains('is-error')), 'Successful completed snapshot is not styled as failure');
        const successLoad = recovery.page.waitForEvent('load');
        await recoveryRun.route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, summary_saved: true, progress: success,
            summary: {ok: true, run_id: recoveryRun.form.run_id, hosts_processed: 4, hosts_completed: 4, hosts_total: 4, errors: 0, progress: success}})});
        await successLoad;
        same(recovery.errors, [], 'Recovery has no uncaught browser errors');
        await recovery.context.close();

        // A lock failure or failed summary write must not show an older Last run.
        for (const scenario of ['lock', 'summary-save', 'foreign-terminal']) {
            const unsaved = await harness(browser);
            const unsavedRun = await unsaved.start();
            let finalProgress = null;
            if (scenario !== 'lock') {
                finalProgress = progress(scenario === 'foreign-terminal' ? 'e'.repeat(32) : unsavedRun.form.run_id,
                    100, 1, {phase: 'failed', running: false, errors: 1});
            }
            const summary = {ok: false, run_id: unsavedRun.form.run_id,
                hosts_completed: scenario === 'lock' ? 0 : 4, hosts_total: scenario === 'lock' ? 0 : 4, errors: 1};
            await unsavedRun.route.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({
                ok: false, summary_saved: scenario === 'foreign-terminal', progress: finalProgress, summary,
                error: scenario === 'lock' ? 'Another run holds the lock.' : 'Unable to save the run summary.'
            })});
            await unsaved.page.waitForFunction(() => !document.querySelector('#nbs-run-now').disabled);
            const stoppedPolls = unsaved.pollCount;
            await unsaved.page.waitForTimeout(1300);
            same(await unsaved.page.locator('#nbs-tab-integrations').getAttribute('aria-selected'), 'true', scenario + ' does not navigate to a previous Last run');
            same(unsaved.pollCount, stoppedPolls, scenario + ' stops polling after the response');
            check(await unsaved.page.locator('#nbs-run-progress').isVisible(), scenario + ' leaves the current result onscreen');
            check(/error|fail|lock|save/i.test(await unsaved.page.locator('#nbs-status').innerText()), scenario + ' remains a visible error');
            if (scenario !== 'summary-save') {
                check(!(await unsaved.page.locator('#nbs-run-progress-bar').getAttribute('value')), scenario + ' cannot borrow a completion percent');
            }
            same(unsaved.errors, [], scenario + ' has no uncaught browser errors');
            await unsaved.context.close();
        }
        console.log('OK: ' + checks + ' offline sync progress UI checks' + (process.env.NBS_ZABBIX_THEME_CSS ? ' with actual Zabbix theme CSS' : ''));
    }
    finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
