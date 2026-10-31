// Requires Docker with cached php:8.4-cli and Playwright (set SLA_PLAYWRIGHT_PATH if external).
const {execFileSync} = require('node:child_process');
const {mkdtempSync, writeFileSync, readFileSync} = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.SLA_PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const output = mkdtempSync(path.join(os.tmpdir(), 'sla-report-ui-'));
let checks = 0;
function check(value, message) { assert(value, message); checks++; }
function hostValues(page) {
    return page.locator('[data-sr-rule-rows="hosts"] tr').evaluateAll(rows=>rows.map(row=>{
        const get = name => row.querySelector('[name$="['+name+']"]').value;
        return {hostid:get('hostid'),label:get('label'),key:get('key'),mode:get('mode'),value:get('value'),interval:get('interval_seconds')};
    }).filter(row=>row.hostid));
}
async function saveAndReload(page, name) {
    // Submit the real form: browser constraints and submit listeners run before
    // PHP parses the actual URL-encoded names, including added/removed row indices.
    await page.evaluate(()=>{
        const form = document.querySelector('[data-sr-settings] form');
        window.__srFixtureSubmit = new Promise(resolve=>form.addEventListener('submit', event=>{
            event.preventDefault();
            queueMicrotask(()=>resolve({post:new URLSearchParams(new FormData(form)).toString(),stored:window.__srFixtureSettings}));
        },{once:true}));
    });
    await page.getByRole('button',{name:'Save shared settings',exact:true}).click();
    const submission = await page.evaluate(()=>window.__srFixtureSubmit);
    const docker = ['run','--rm','-i','--network','none','--read-only','--cap-drop','ALL',
        '--security-opt','no-new-privileges','-v',`${root}:/work:ro`,'-w','/work','php:8.4-cli','php'];
    const result = JSON.parse(execFileSync('docker',[...docker,'tests/SettingsSubmitFixture.php'],
        {encoding:'utf8',input:JSON.stringify(submission)}));
    check(result.writes===1,`${name}: one save persists the entire form`);
    const html = execFileSync('docker',[...docker,'tests/RenderFixture.php','settings','stdin'],
        {encoding:'utf8',input:JSON.stringify(result.settings)});
    check(!/Warning:|Fatal error:|Parse error:/.test(html),`${name}: saved settings render cleanly`);
    const target = path.join(output,`${name}.html`); writeFileSync(target,html);
    await page.goto(pathToFileURL(target).href);
    check((await page.locator('[role="status"]').first().textContent()).includes('Report settings saved.'),`${name}: reload confirms save`);
    return result;
}
async function main() {
    const fixtures = {};
    for (const view of ['report', 'settings', 'host']) {
        const html = execFileSync('docker', ['run','--rm','--network','none','--read-only','--cap-drop','ALL',
            '--security-opt','no-new-privileges','-v',`${root}:/work:ro`,'-w','/work',
            'php:8.4-cli','php','tests/RenderFixture.php',view], {encoding:'utf8'});
        check(!/Warning:|Fatal error:|Parse error:/.test(html), `${view} renders without PHP errors`);
        const target = path.join(output, `${view}.html`); writeFileSync(target, html); fixtures[view] = pathToFileURL(target).href;
    }
    const browser = await chromium.launch({headless:true,
        ...(process.env.SLA_BROWSER_CHANNEL ? {channel:process.env.SLA_BROWSER_CHANNEL} : {})});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const errors = []; page.on('pageerror', e=>errors.push(e.message));
        await page.goto(fixtures.report);
        await page.locator('#sr-panel-overview [data-sr-view-nodata]').first().click();
        check(await page.locator('#sr-panel-availability').isVisible(), 'Without data switches tab');
        check(await page.locator('#sr-without-data').isVisible(), 'Without data opens full list');
        check(await page.locator('#sr-without-data').textContent().then(s=>s.includes('No samples host')), 'List includes host omitted by table cap');
        check(await page.getByRole('columnheader', {name:/^maintenance$/i}).count() === 1, 'Maintenance column is present');
        check(await page.getByRole('columnheader', {name:/^coverage$/i}).count() === 1, 'Coverage column is present');
        const href = await page.getByRole('link',{name:'Website <example>',exact:true}).getAttribute('href');
        check(href.includes('time_from=') && href.includes('return_filter=') && href.includes('measurementid='), 'Host link preserves measurement, period and return filter');
        check(await page.getByRole('link',{name:'Homepage check',exact:true}).count()===1, 'Separate measurement label is linked');
        await page.screenshot({path:path.join(output,'report.png'),fullPage:true});
        await page.goto(fixtures.settings);
        const rows = page.locator('[data-sr-rule-rows="groups"] tr');
        const before = await rows.count();
        await page.locator('[data-sr-add-rule="groups"]').click();
        check(await rows.count()===before+1,'Add group override works');
        await rows.last().locator('[data-sr-remove-rule]').click();
        check(await rows.count()===before,'Remove group override works');
        check(await page.locator('input[name="form_complete"]').count()===1,'Settings includes truncation guard');
        const manual = [
            ['12','Homepage','web.test.rspcode[https://secure.landshypotek.se/,https://secure.landshypotek.se]','200'],
            ['11','Kundkannedom','web.test.rspcode[https://kundkannedom.landshypotek.se/,https://kundkannedom.landshypotek.se/]','200'],
            ['12','Loan login','web.test.rspcode[https://secure.landshypotek.se/user/loanlogin?nyttBoLan=,https://secure.landshypotek.se/user/loanlogin?nyttBoLan=]','302'],
            ['12','Overview','web.test.rspcode[https://secure.landshypotek.se/User/Overview,https://secure.landshypotek.se/User/Overview]','302'],
            ['13','SME','web.test.rspcode[https://sme.landshypotek.se/login?nyttLantbruksLan=,https://sme.landshypotek.se/login?nyttLantbruksLan=]','200'],
            ['14','Loggain','web.test.rspcode[loggain.landshypotek.se,loggain.landshypotek.se]','200'],
            ['15','WWW','web.test.rspcode[www.landshypotek.se,www.landshypotek.se]','200']
        ];
        const fillRow = async (row, values) => {
            for (const [field,value] of Object.entries({hostid:values[0],label:values[1],key:values[2],value:values[3],interval_seconds:'60'})) {
                await row.locator(`[name$="[${field}]"]`).fill(value);
            }
            await row.locator('[name$="[mode]"]').selectOption('equals');
        };
        for (let index=0; index<manual.length; index++) {
            if (index>=2) { await page.locator('[data-sr-add-rule="hosts"]').click(); }
            await fillRow(page.locator('[data-sr-rule-rows="hosts"] tr').nth(index),manual[index]);
        }
        await page.locator('[data-sr-rule-rows="hosts"] tr').nth(1).locator('[data-sr-remove-rule]').click();
        await page.locator('[data-sr-add-rule="hosts"]').click();
        await fillRow(page.locator('[data-sr-rule-rows="hosts"] tr').last(),manual[1]);
        const manualBefore = await hostValues(page);
        const manualSaved = await saveAndReload(page,'settings-manual-saved');
        check(manualSaved.settings.hosts.length===7,'All seven manually added checks persist together');
        check(manualSaved.settings.hosts.filter(row=>row.hostid==='12').map(row=>row.value).join(',')==='200,302,302',
            'One save retains all three same-host scenarios and their separate healthy codes');
        check(JSON.stringify(await hostValues(page))===JSON.stringify(manualBefore),'Save/reload preserves every edited value after remove/add');
        await page.goto(fixtures.settings);
        const tsv = readFileSync(path.join(root,'examples','landshypotek-web-checks.tsv'),'utf8');
        await page.locator('.sr-settings-import summary').click();
        await page.locator('[data-sr-checks-tsv]').fill(tsv);
        await page.locator('[data-sr-import-checks]').click();
        check((await page.locator('[data-sr-import-status]').textContent()).includes('10 measurements added or updated'), 'All ten website rows import');
        const imported = await hostValues(page);
        check(imported.length===10 && new Set(imported.map(row=>row.hostid)).size===6, 'Ten checks cover six distinct hosts');
        check(imported.filter(row=>row.value==='302').length===2 && imported.filter(row=>row.value==='200').length===8, 'Only two checks expect302');
        check(imported.every(row=>row.mode==='equals' && row.interval==='0'), 'Exact expected codes with no guessed cadence');
        check(!imported.some(row=>row.mode==='auto'), 'Imported host auto override is replaced');
        await page.locator('[data-sr-import-checks]').click();
        check(JSON.stringify(await hostValues(page))===JSON.stringify(imported), 'Importing twice is idempotent');
        await page.locator('[data-sr-checks-tsv]').fill(tsv.replace('kundkannedom.landshypotek.se\tKundkannedom /','missing-host.invalid\tKundkannedom /'));
        await page.locator('[data-sr-import-checks]').click();
        check((await page.locator('[data-sr-import-status]').textContent()).startsWith('Import unchanged:'), 'Unknown host fails import');
        check(JSON.stringify(await hostValues(page))===JSON.stringify(imported), 'Rejected import leaves every row unchanged');
        await page.locator('[data-sr-checks-tsv]').fill(tsv+'\n'+tsv.trim().split('\n')[1]);
        await page.locator('[data-sr-import-checks]').click();
        check((await page.locator('[data-sr-import-status]').textContent()).includes('appears twice'), 'Duplicate host/key import is rejected');
        check(JSON.stringify(await hostValues(page))===JSON.stringify(imported), 'Duplicate rejection is atomic');
        await page.locator('[data-sr-checks-tsv]').fill(tsv);
        await page.locator('[data-sr-import-checks]').click();
        await page.screenshot({path:path.join(output,'settings.png'),fullPage:true});
        const importedSaved = await saveAndReload(page,'settings-imported-saved');
        check(importedSaved.settings.hosts.length===10,'All ten imported checks persist in one save');
        check(JSON.stringify(await hostValues(page))===JSON.stringify(imported),'Imported host identities, keys, labels and rules survive save/reload');
        await page.goto(fixtures.host);
        await page.getByText('Create maintenance',{exact:true}).click();
        const form = page.locator('form[method="post"]');
        check(await page.locator('#sr-measurement option').count()===2,'Host detail offers independent website choices');
        check(await form.locator('[name="measurementid"]').inputValue()==='a'.repeat(32),'Maintenance review carries selected measurement');
        check((await form.textContent()).includes('every measurement on the host'),'Maintenance confirmation states host-wide effect');
        check(await form.isVisible(),'Per-gap review opens');
        check(await form.locator('input[name="_csrf_token"]').count()===1,'Maintenance form has native CSRF field');
        check(await form.evaluate(el=>!el.checkValidity()),'Reason and confirmation required');
        await form.locator('[name="reason"]').fill('Approved change CHG-123');
        await form.locator('[name="acknowledge"]').check();
        check(await form.evaluate(el=>el.checkValidity()),'Completed review is submit-ready');
        check(await page.locator('script').count()===1, 'Host name remains escaped text');
        await page.screenshot({path:path.join(output,'host.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        await page.goto(fixtures.settings);
        check(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),'Settings page fits mobile viewport');
        check(errors.length===0,`No browser errors: ${errors.join('; ')}`);
        const nojs = await browser.newContext({javaScriptEnabled:false});
        const fallback = await nojs.newPage(); await fallback.goto(fixtures.report);
        check(await fallback.locator('#sr-panel-overview [data-sr-view-nodata]').first().getAttribute('href').then(s=>s.includes('filter_tab=availability')), 'No-JS link has server fallback');
        await nojs.close();
        console.log(`${checks} UI checks passed. Synthetic screenshots: ${output}`);
    } finally { await browser.close(); }
}
main().catch(e=>{console.error(e);process.exitCode=1;});
