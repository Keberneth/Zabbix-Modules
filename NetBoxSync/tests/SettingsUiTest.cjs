'use strict';

// Render SettingsViewFixture.php with PHP, then pass its HTML file as argv[2].
// Requires Playwright and Chrome, or PLAYWRIGHT_CHROMIUM_EXECUTABLE on other hosts.
// All requests are intercepted; this suite never contacts NetBox or Zabbix.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');

const fixturePath = process.argv[2];
if (!fixturePath) {
    throw new Error('Usage: node tests/SettingsUiTest.cjs /path/to/rendered-settings.html');
}
const html = fs.readFileSync(fixturePath, 'utf8');
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    || (process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : undefined);
const tabKeys = ['integrations', 'scheduling', 'built-in', 'vms', 'devices', 'mappings', 'last-run'];
const vaultReference = 'vault:secret/data/netbox#api_token';
let checks = 0;
function check(value, message) { assert.ok(value, message); checks++; }
function same(actual, expected, message) { assert.deepEqual(actual, expected, message); checks++; }

function parseForm(request) {
    const result = {};
    const contentType = request.headers()['content-type'] || '';
    const boundary = contentType.match(/boundary=(.+)$/)?.[1];
    assert.ok(boundary, 'FormData request has a multipart boundary');
    for (const part of (request.postData() || '').split('--' + boundary)) {
        const name = part.match(/Content-Disposition: form-data; name="([^"]+)"/i)?.[1];
        if (name) result[name] = part.split('\r\n\r\n').slice(1).join('\r\n\r\n').replace(/\r\n$/, '');
    }
    return result;
}

(async () => {
    check(!html.includes('fixture-token-must-never-appear-in-html'), 'NetBox token is redacted from rendered settings');
    check(!html.includes('fixture-zabbix-token-must-never-appear-in-html'), 'Zabbix token is redacted from rendered settings');
    const browser = await chromium.launch({headless: true, executablePath});
    try {
        const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
        const page = await context.newPage();
        const errors = [];
        const requests = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.dismiss());
        await context.route('**/*', async route => {
            const request = route.request();
            const pathname = new URL(request.url()).pathname;
            if (pathname === '/settings') {
                await route.fulfill({contentType: 'text/html', body: html});
                return;
            }
            if (pathname === '/run-status') {
                await route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, progress: null})});
                return;
            }
            if (['/save', '/run', '/test-netbox', '/test-zabbix'].includes(pathname)) {
                requests.push({path: pathname, method: request.method(), form: parseForm(request)});
                if (pathname === '/run') {
                    const runId = requests[requests.length - 1].form.run_id;
                    const progress = {run_id: runId, phase: 'failed', running: false,
                        percent: 100, hosts_completed: 3, hosts_total: 3, total_known: true,
                        errors: 1, updated_at: new Date().toISOString()};
                    await route.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({
                        ok: false, summary_saved: true, progress, error: 'Fixture partial failure', summary: {
                            ok: false, run_id: runId, hosts_processed: 2, hosts_completed: 3, hosts_total: 3,
                            errors: 1, mappings_run: 4, progress
                        }
                    })});
                }
                else {
                    await route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, message: 'Fixture saved.'})});
                }
                return;
            }
            await route.abort();
        });
        const tab = key => page.locator('#nbs-tab-' + key);
        const selected = async key => {
            same(await tab(key).getAttribute('aria-selected'), 'true', key + ' is selected');
            same(await page.getByRole('tabpanel').count(), 1, 'Only one panel is visible');
        };
        await page.goto('https://netboxsync.test/settings');
        const actionButtons = await page.locator('.nbs-header-actions .nbs-button, #nbs-test-connection, #nbs-test-zabbix-connection').evaluateAll(buttons => buttons.map(button => {
            const css = getComputedStyle(button);
            return {label: button.textContent.trim(), height: button.getBoundingClientRect().height,
                border: parseFloat(css.borderTopWidth), background: css.backgroundColor,
                decoration: css.textDecorationLine};
        }));
        same(actionButtons.length, 5, 'Header actions and integration tests use explicit module button styling');
        for (const button of actionButtons) {
            check(button.height >= 32, button.label + ' has a usable button hit area despite frontend reset');
            check(button.border >= 1 || !['transparent', 'rgba(0, 0, 0, 0)'].includes(button.background), button.label + ' has a visible button boundary or fill');
            check(!button.decoration.includes('underline'), button.label + ' is styled as an action button');
        }
        same(await page.getByRole('tab').count(), 7, 'Seven settings tabs');
        for (const key of tabKeys) {
            await tab(key).click();
            await selected(key);
            check(await page.locator('#nbs-panel-' + key).isVisible(), 'Visible matching panel for ' + key);
        }
        await tab('integrations').focus();
        await page.keyboard.press('ArrowRight');
        await selected('scheduling');
        same(await page.locator(':focus').getAttribute('id'), 'nbs-tab-scheduling', 'Arrow key moves focus');
        await page.keyboard.press('End');
        await selected('last-run');
        await page.keyboard.press('Home');
        await selected('integrations');
        await page.keyboard.press('ArrowLeft');
        await selected('last-run');
        check((await page.locator('#nbs-panel-last-run').innerText()).includes('2/3'), 'Summary shows partial host coverage');
        check((await page.locator('#nbs-panel-last-run').innerText()).includes('Fixture mapping failed <script>'), 'Summary message is displayed as literal text');

        await tab('scheduling').click();
        await page.locator('[name="runner[global_interval_seconds]"]').fill('7200');
        await tab('devices').click();
        await page.locator('[name="device[model_source_value]"]').fill('fixture.device.model');
        await tab('integrations').click();
        await page.locator('[name="netbox[token_env]"]').fill('NETBOX_FORM_TOKEN');
        await page.locator('[name="netbox[clear_token]"]').check();
        await page.locator('#nbs-test-connection').click();
        await page.waitForFunction(() => document.querySelector('#nbs-status').textContent.includes('Fixture saved'));
        await page.locator('[name="netbox[token_env]"]').fill(vaultReference);
        await page.locator('#nbs-test-connection').click();
        await page.waitForFunction(() => document.querySelector('#nbs-status').textContent.includes('Fixture saved'));
        await page.locator('[name="netbox[clear_token]"]').uncheck();
        await page.locator('[name="zabbix_api[token_env]"]').fill('ZABBIX_FORM_TOKEN');
        await page.locator('[name="zabbix_api[clear_token]"]').check();
        await page.locator('#nbs-test-zabbix-connection').click();
        await page.waitForFunction(() => document.querySelector('#nbs-status').textContent.includes('Fixture saved'));
        await page.locator('[name="zabbix_api[clear_token]"]').uncheck();

        await tab('mappings').click();
        await page.locator('[data-mapping-example="serial"]').click();
        await page.locator('[data-mapping-example="serial"]').click();
        await page.locator('[data-mapping-example="role"]').click();
        const mappings = await page.locator('#nbs-mappings-list .nbs-repeat-row').evaluateAll(rows => rows.map(row => {
            const field = name => row.querySelector('[name$="[' + name + ']"]');
            return {id: field('id').value, enabled: field('enabled').checked,
                target: field('target_object').value, mode: field('mode').value,
                source: field('source_value').value, targetField: field('target_field').value,
                empty: field('on_empty').value};
        }));
        same(mappings.length, 3, 'Each example adds an editable mapping');
        same(new Set(mappings.map(row => row.id)).size, 3, 'Inserted rows have distinct identifiers');
        check(mappings.every(row => !row.enabled && row.empty === 'skip'), 'Examples start disabled with skip-on-empty');
        same(mappings[0].targetField, 'custom_fields.serial_from_zabbix', 'Serial example targets a custom field');
        same(mappings[0].source, 'fgate.device.serialnumber', 'Serial example has a real template item-key example');
        same(mappings[2].mode, 'relation_lookup', 'Role example demonstrates relation lookup');
        same(mappings[2].targetField, 'role', 'Role example writes the device role');

        await page.locator('#nbs-run-now').click();
        same(requests.filter(r => r.path === '/run').length, 0, 'Unsaved settings cannot trigger a run');
        check(/save/i.test(await page.locator('#nbs-status').innerText()), 'Dirty-run guard explains saving first');
        await page.getByRole('button', {name: 'Save settings', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('#nbs-status').textContent.includes('Fixture saved'));
        const save = requests.find(r => r.path === '/save');
        check(!!save, 'Save submits the full form');
        same(save.form['runner[global_interval_seconds]'], '7200', 'Hidden scheduling edit is submitted');
        same(save.form['device[model_source_value]'], 'fixture.device.model', 'Hidden device edit is submitted');
        same(save.form['netbox[url]'], 'https://netbox.example.test', 'Hidden integration remains in form');
        same(save.form['netbox[token_env]'], vaultReference, 'Direct Vault reference including its key separator is saved');
        same(save.form['standard_syncs[vm_object][enabled]'], '1', 'Hidden checked catalogue entry is submitted');
        same(save.form['custom_mappings[' + mappings[0].id + '][target_field]'], 'custom_fields.serial_from_zabbix', 'Example fields are submitted');
        check(!Object.hasOwn(save.form, 'custom_mappings[' + mappings[0].id + '][enabled]'), 'Disabled example is not submitted as enabled');

        await tab('scheduling').click();
        await page.locator('[name="runner[global_interval_seconds]"]').fill('-1');
        await tab('integrations').click();
        const saveCount = requests.filter(r => r.path === '/save').length;
        await page.getByRole('button', {name: 'Save settings', exact: true}).click();
        await selected('scheduling');
        same(requests.filter(r => r.path === '/save').length, saveCount, 'Invalid hidden input blocks saving');
        same(await page.locator(':focus').getAttribute('name'), 'runner[global_interval_seconds]', 'Invalid input receives focus');
        await page.locator('[name="runner[global_interval_seconds]"]').fill('7200');

        await Promise.all([
            page.waitForEvent('load'),
            page.locator('#nbs-run-now').click()
        ]);
        await page.waitForURL('**/settings#last-run');
        await selected('last-run');
        await page.waitForFunction(() => document.readyState === 'complete');
        same(requests.filter(r => r.path === '/run').length, 1, 'Saved settings allow one run');
        check(/fail|error/i.test(await page.locator('#nbs-panel-last-run').innerText()), 'Partial failure remains visible in Last run');
        same(requests.find(r => r.path === '/run').form._csrf_token, 'fixture-netboxsync.run', 'Run uses its own CSRF token');
        check(/^[a-f0-9]{32}$/.test(requests.find(r => r.path === '/run').form.run_id || ''), 'Run creates a unique request identifier for progress');

        // Browser persistence and responsive layout should work without active APIs.
        await tab('mappings').click();
        await page.reload();
        await selected('mappings');
        for (const width of [640, 390]) {
            await page.setViewportSize({width, height: 900});
            for (const key of tabKeys) {
                await tab(key).click();
                check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'No document overflow at ' + width + 'px on ' + key + ' tab');
            }
        }
        if (process.env.NBS_UI_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.NBS_UI_SCREENSHOT_DIR, {recursive: true});
            await page.setViewportSize({width: 1440, height: 1000});
            await tab('integrations').click();
            await page.screenshot({path: path.join(process.env.NBS_UI_SCREENSHOT_DIR, 'netboxsync-integrations.png'), fullPage: true});
            await tab('mappings').click();
            await page.locator('[data-mapping-example="serial"]').click();
            await page.screenshot({path: path.join(process.env.NBS_UI_SCREENSHOT_DIR, 'netboxsync-mappings.png'), fullPage: true});
            await tab('integrations').click();
            await page.locator('#nbs-settings-root').evaluate(root => { root.dataset.nbsTheme = 'dark'; });
            await page.evaluate(() => { document.body.style.background = '#182333'; });
            // Let the tab's 150 ms color transition finish before visual QA.
            await page.waitForTimeout(200);
            await page.screenshot({path: path.join(process.env.NBS_UI_SCREENSHOT_DIR, 'netboxsync-integrations-dark.png'), fullPage: true});
            await page.setViewportSize({width: 390, height: 900});
            await page.screenshot({path: path.join(process.env.NBS_UI_SCREENSHOT_DIR, 'netboxsync-integrations-mobile.png'), fullPage: true});
        }
        same(requests.find(r => r.path === '/test-netbox').form.token_env, 'NETBOX_FORM_TOKEN', 'NetBox connection test uses the unsaved secret reference');
        same(requests.find(r => r.path === '/test-netbox').form.clear_token, '1', 'NetBox connection test honors clear stored token');
        same(requests.filter(r => r.path === '/test-netbox')[1].form.token_env, vaultReference, 'Direct Vault connection test preserves the literal # key separator');
        same(requests.filter(r => r.path === '/test-netbox')[1].form.clear_token, '1', 'Direct Vault test honors clear stored token');
        same(requests.find(r => r.path === '/test-zabbix').form.token_env, 'ZABBIX_FORM_TOKEN', 'Zabbix connection test uses the unsaved secret reference');
        same(requests.find(r => r.path === '/test-zabbix').form.clear_token, '1', 'Zabbix connection test honors clear stored token');
        same(errors, [], 'No uncaught browser errors');
        console.log('OK: ' + checks + ' offline settings UI checks');
    }
    finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
