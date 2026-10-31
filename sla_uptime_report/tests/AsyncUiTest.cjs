// Offline browser tests: all requests are fulfilled locally; no Zabbix server is contacted.
const {execFileSync} = require('node:child_process');
const {mkdtempSync} = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.SLA_PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname,'..');
const output = mkdtempSync(path.join(os.tmpdir(),'sla-async-ui-'));
const docker = ['run','--rm','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges',
    '-v',`${root}:/work:ro`,'-w','/work','php:8.4-cli','php','tests/AsyncRenderFixture.php'];
const shell = execFileSync('docker',[...docker,'shell'],{encoding:'utf8'});
const responses = Object.fromEntries([1,7,30].map(days=>[days,execFileSync('docker',[...docker,'data',String(days)],{encoding:'utf8'})]));
let checks = 0;
function check(value,message) { assert(value,message); checks++; }
async function main() {
    const browser = await chromium.launch({headless:true,...(process.env.SLA_BROWSER_CHANNEL?{channel:process.env.SLA_BROWSER_CHANNEL}:{})});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const errors = []; page.on('pageerror',error=>errors.push(error.message));
        // Model a transport that cannot cancel in-flight server work: generation checks must still reject late responses.
        await page.addInitScript(()=>{
            const original = window.fetch;
            window.fetch = (url,options)=>original(url,{...options,signal:undefined});
        });
        const requests = [], waiters = [];
        function nextRequest() { return requests.length ? Promise.resolve(requests.shift()) : new Promise(resolve=>waiters.push(resolve)); }
        await page.route('**/*',async route=>{
            const url = new URL(route.request().url());
            if (url.searchParams.get('action') === 'slareport.report.data' || url.searchParams.get('action') === 'slareport.report.download') {
                const item = {route,url};
                if (waiters.length) { waiters.shift()(item); } else { requests.push(item); }
            }
            else { await route.fulfill({contentType:'text/html',body:shell}); }
        });
        await page.goto('http://sla-fixture.test/zabbix.php?action=slareport.report.view');
        const initial = await nextRequest();
        check(await page.getByRole('button',{name:'Apply',exact:true}).isEnabled(),'Filters are editable before data returns');
        check(await page.locator('[data-sr-progress]').isVisible(),'Initial report shows progress immediately');
        check(await page.locator('[data-sr-progress]').getAttribute('aria-valuenow')===null,'Progress does not invent a percentage');
        check(await page.locator('[data-sr-download]').count()===0,'Shell has no misleading downloads or measured totals');
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent.includes('s elapsed.'));
        check(await page.getByRole('button',{name:'Apply',exact:true}).isEnabled(),'Elapsed progress leaves filter controls usable');
        await page.screenshot({path:path.join(output,'report-loading.png'),fullPage:true});
        await page.locator('#sr_days_back').fill('7');
        await page.getByRole('button',{name:'Apply',exact:true}).click();
        const newer = await nextRequest();
        check(newer.url.searchParams.get('filter_days_back')==='7','Apply submits the latest filters asynchronously');
        await newer.route.fulfill({contentType:'application/json',body:responses[7]});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        check((await page.locator('.sr-head-period').textContent())==='7 day fixture','New filter response renders');
        await initial.route.fulfill({contentType:'application/json',body:responses[30]});
        await page.waitForFunction(()=>!document.querySelector('[data-sr-progress]').hidden===false);
        check((await page.locator('.sr-head-period').textContent())==='7 day fixture','Older response cannot replace the new selection');
        const cacheText = await page.locator('[data-sr-cache-status]').textContent();
        check(/Reused 1\D?000 cached readings; fetched 5 readings/.test(cacheText),`Cache reuse is visible: ${cacheText}`);
        await page.getByRole('tab',{name:'Host availability',exact:true}).click();
        check(await page.locator('#sr-panel-availability').isVisible(),'Tabs still switch locally after asynchronous replacement');
        check(await page.locator('.sr-tooltip').count()===1,'Fragment replacement does not leak tooltips');
        await page.locator('#sr_days_back').fill('1');
        check(await page.locator('[data-sr-download][href]').count()===0,'Editing filters disables downloads of previous results');
        await page.getByRole('button',{name:'Apply',exact:true}).click();
        const failed = await nextRequest();
        await failed.route.fulfill({contentType:'application/json',body:JSON.stringify({error:'Fixture report failed.'})});
        await page.waitForFunction(()=>!document.querySelector('[data-sr-retry]').hidden);
        check((await page.locator('[data-sr-load-status]').textContent()).includes('Fixture report failed.'),'Load failure survives stale-state rendering');
        check(await page.locator('[data-sr-report-results]').evaluate(el=>el.classList.contains('sr-results-stale')),'Previous report is marked stale after a failure');
        check(await page.locator('[data-sr-download][href]').count()===0,'Failed requests cannot export old data as the new selection');
        await page.getByRole('button',{name:'Try again',exact:true}).click();
        const retry = await nextRequest();
        await retry.route.fulfill({contentType:'application/json',body:responses[1]});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        check((await page.locator('.sr-head-period').textContent())==='1 day fixture','Retry succeeds without reloading the page');
        await page.getByRole('button',{name:'Refresh data',exact:true}).click();
        const refresh = await nextRequest();
        check(refresh.url.searchParams.get('refresh')==='1','Refresh explicitly requests an uncached rebuild');
        const forcedResponse = JSON.parse(responses[1]);
        forcedResponse.cache.forced = true; forcedResponse.cache.generated_at = Math.floor(Date.now()/1000);
        await refresh.route.fulfill({contentType:'application/json',body:JSON.stringify(forcedResponse)});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        check((await page.locator('[data-sr-cache-status]').textContent()).includes('Full refresh completed. Updated'),'Forced refresh and freshness time are visible');
        const downloadEvent = page.waitForEvent('download');
        await page.getByRole('link',{name:'Availability CSV',exact:true}).click();
        const exportRequest = await nextRequest();
        check(await page.locator('[data-sr-progress]').isVisible(),'Export generation shows progress');
        check((await page.locator('[data-sr-load-status]').textContent()).includes('Creating report export'),'Export phase is explained');
        check(await page.locator('[data-sr-download][href]').count()===0,'Duplicate export clicks are disabled while generating');
        await page.screenshot({path:path.join(output,'export-loading.png'),fullPage:true});
        await exportRequest.route.fulfill({contentType:'text/csv',headers:{'Content-Disposition':'attachment; filename="fixture.csv"'},body:'Host,Availability\nWebsite,100\n'});
        const downloaded = await downloadEvent;
        check(downloaded.suggestedFilename()==='fixture.csv','Completed export downloads with its server filename');
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report export created.');
        await page.getByRole('link',{name:'SLA CSV',exact:true}).click();
        const badExport = await nextRequest();
        await badExport.route.fulfill({contentType:'text/html',body:'<html>Sign in</html>'});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent.includes('could not be created'));
        check(await page.locator('[data-sr-download][href]').count()===4,'An export error restores controls');
        await page.goBack();
        const back = await nextRequest();
        check(back.url.searchParams.get('filter_days_back')==='7','Back restores the previous complete filter state');
        await back.route.fulfill({contentType:'application/json',body:responses[7]});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        await page.goBack();
        const first = await nextRequest();
        check(first.url.searchParams.get('filter_days_back')==='30','Back to an initial URL without filters restores original defaults');
        await first.route.fulfill({contentType:'application/json',body:responses[30]});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        await page.getByRole('button',{name:'More filters',exact:true}).click();
        await page.locator('label.sr-chip').filter({hasText:/^\s*Exclude disabled hosts\s*$/}).click();
        await page.getByRole('button',{name:'Apply',exact:true}).click();
        const includeDisabled = await nextRequest();
        check(includeDisabled.url.searchParams.get('filter_exclude_disabled')==='0','Back navigation preserves the unchecked checkbox fallback');
        const noNumericWork = JSON.parse(responses[30]);
        noNumericWork.cache = {enabled:false,available:false,reason:'not_needed',generated_at:Math.floor(Date.now()/1000)};
        await includeDisabled.route.fulfill({contentType:'application/json',body:JSON.stringify(noNumericWork)});
        await page.waitForFunction(()=>document.querySelector('[data-sr-load-status]').textContent==='Report loaded.');
        check(!/disabled|unavailable/.test(await page.locator('[data-sr-cache-status]').textContent()),'No numeric work is not reported as a cache failure');
        check(errors.length===0,`No browser errors: ${errors.join('; ')}`);
        await page.screenshot({path:path.join(output,'report-loaded.png'),fullPage:true});
        const nojs = await browser.newContext({javaScriptEnabled:false});
        const fallback = await nojs.newPage();
        await fallback.route('**/*',route=>route.fulfill({contentType:'text/html',body:shell}));
        await fallback.goto('http://sla-fixture.test/zabbix.php?action=slareport.report.view');
        check((await fallback.getByRole('link',{name:'Load report without JavaScript'}).getAttribute('href')).includes('full=1'),'No-JavaScript shell provides a synchronous full-report link');
        check(await fallback.locator('input[name="full"]').getAttribute('form')==='sr-filter-form','No-JavaScript Apply requests a full report');
        await nojs.close();
        console.log(`${checks} asynchronous UI checks passed. Screenshots: ${output}`);
    }
    finally { await browser.close(); }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
