'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '..');
const jsAsset = path.join(root, 'assets', 'js', 'capacity-planning-1.4.0-20260801.1.js');
const cssAsset = path.join(root, 'assets', 'css', 'capacity-planning.css');
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
	|| 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MODULE_VERSION = '1.4.0';
const CLIENT_BUILD_ID = '1.4.0-20260801.1';

function appDocument(initialTab = 'disks', extraAttributes = '') {
	return `<!doctype html><html><body><main id="capacity-planning-root" data-data-url="/fake"
		data-module-version="${MODULE_VERSION}" data-server-build-id="${CLIENT_BUILD_ID}"
		data-csrf-name="csrf_key" data-data-csrf-token="data-token-123" data-initial-lookback=""
		data-initial-tab="${initialTab}" ${extraAttributes}></main></body></html>`;
}

function fixture() {
	const now = Math.floor(Date.now() / 1000);
	const hosts = [
		{hostid: '1', name: 'db01', groups: ['Databases', 'Production'], os: 'Linux',
			maintenance: {active: true, type: 'no_data_collection', since: now - 3600}},
		{hostid: '2', name: 'web01', groups: ['Production', 'Web'], os: 'Linux',
			maintenance: {active: true, type: 'with_data_collection', since: now - 7200}},
		{hostid: '3', name: 'dev01', groups: ['Development'], os: 'Linux',
			maintenance: {active: false, type: 'none', since: null}}
	];
	const disks = [];
	for (let i = 0; i < 63; i++) {
		const bucket = Math.floor(i / 21);
		const host = hosts[bucket];
		const severity = ['Critical', 'High', 'Healthy'][bucket];
		const status = ['Stale', 'Stale', 'Incomplete'][bucket];
		const kind = bucket === 1 ? 'Remote' : 'Local';
		const pused = [96, 91, 55][bucket];
		disks.push({
			id: `d${i}`, hostid: host.hostid, host: host.name, os: host.os,
			fs: `${kind === 'Remote' ? 'nfs:/share' : '/data'}/${String(i).padStart(2, '0')}`,
			kind, itemid: i === 0 ? '1000' : null, pct_itemid: null,
			item_key: `vfs.fs.size[/data/${i},used]`, pct_item_key: '', tr: 'identity', pr: null,
			total: 1000, usable: 1000, used: pused * 10, free: (100 - pused) * 10, pused,
			warn_pct: {v: 90, src: 'fixture', fb: false}, crit_pct: {v: 95, src: 'fixture', fb: false},
			warn_free: {v: 0, src: 'fixture', fb: false}, crit_free: {v: 0, src: 'fixture', fb: false},
			status, current_severity: severity,
			expected_gap: bucket === 0, current_observation_usable: bucket !== 0,
			current_reasons: bucket === 0
				? ['Current observation withheld during maintenance; last accepted value retained.']
				: [`Fixture ${severity}`],
			current_recommendation: `${severity} fixture assessment`
		});
	}
	const series = [];
	for (let day = 60; day >= 0; day--) {
		const average = 760 + (60 - day) * 3;
		series.push([now - day * 86400, average - 12, average, average + 12]);
	}
	const resources = [
		{id: 'r0', hostid: '1', host: 'db01', os: 'Linux', rtype: 'CPU', metric: 'CPU utilization',
			itemid: null, item_key: '', tr: 'identity', pr: null, current: 42, provisioned: 8,
			unit: 'logical CPUs', lastclock: now, warn: {v: 90}, crit: {v: 99}, status: 'OK',
			expected_gap: true, current_observation_usable: false,
			current_reasons: ['CPU value is last accepted because maintenance pauses collection.'],
			current_severity: 'Healthy', current_recommendation: 'No CPU action.'},
		{id: 'r1', hostid: '1', host: 'db01', os: 'Linux', rtype: 'Memory', metric: 'Memory utilization',
			itemid: null, item_key: '', tr: 'identity', pr: null, current: 58, provisioned: 16 * 1024 ** 3,
			unit: 'bytes', lastclock: now, warn: {v: 90}, crit: {v: 95}, status: 'OK',
			expected_gap: true, current_observation_usable: false,
			current_reasons: ['RAM value is last accepted because maintenance pauses collection.'],
			current_severity: 'Healthy', current_recommendation: 'No memory action.'}
	];
	return {
		inventory: {
			build_id: CLIENT_BUILD_ID,
			disks, resources, quality: [],
			facets: {hosts, hostgroups: ['Databases', 'Development', 'Production', 'Web']},
			meta: {generated_at: now - 120, hosts_analyzed: 3, forecast_batch_max: 10, resource_forecast_batch_max: 2,
				hosts_truncated: false, items_truncated: false, findings_truncated: false}
		},
		forecast: {
			id: 'd0', status: 'ok', severity: 'Critical', confidence: 'High', source: 'trends',
			sel: '3m', sel_label: '3 months', selected: {days: 60, cov: 100, r2: 0.98},
			pct_sel: '3m', pct_sel_label: '3 months', pct_source: 'trends', pct_confidence: 'High',
			pct_windows: {'3m': {days: 60, cov: 100, r2: 0.97}},
			pct_series_direct: false, growth_day: 3, growth_pct_day: 0.3, accelerating: false,
			series, pct_series: [], reasons: ['Recurring growth fixture'], recommendation: 'Plan capacity.',
			eta: {warn_days: 0, warn_date: now, warn_basis: 'used percentage', crit_days: 0,
				crit_date: now, crit_basis: 'used percentage', full_days: 14, full_date: now + 14 * 86400,
				warn_pct_days: 0, warn_pct_date: now, warn_free_days: null, warn_free_date: null,
				crit_pct_days: 0, crit_pct_date: now, crit_free_days: null, crit_free_date: null,
				next_days: 0, next_date: now, next_basis: 'used percentage'}
		}
	};
}

function forecastPoolFixture() {
	const data = fixture();
	const utilizations = [44, 99, 75, 88, 62, 51];
	data.inventory.disks = utilizations.map((pused, index) => ({
		...data.inventory.disks[index],
		id: `pool-${index}`, itemid: String(5000 + index), hostid: '3', host: 'dev01',
		fs: `/pool/${index}`, kind: 'Local', total: 1000, usable: 1000,
		used: pused * 10, free: (100 - pused) * 10, pused,
		status: 'OK', current_severity: 'Unknown', expected_gap: false,
		current_observation_usable: true, current_reasons: [],
		current_recommendation: 'No current-state recommendation.'
	}));
	data.inventory.resources = [];
	data.inventory.meta = {...data.inventory.meta, hosts_analyzed: 1, forecast_batch_max: 1};
	return data;
}

function settingsDocument(canManage, enabled = true, ttlSeconds = 1800) {
	const settings = JSON.stringify({enabled, ttl_seconds: ttlSeconds});
	const status = JSON.stringify({enabled, ttl_seconds: ttlSeconds, status_pending: canManage});
	return `<!doctype html><html><body><main id="capacity-planning-root"
		data-data-url="/fake" data-settings-save-url="/settings-save" data-cache-status-url="/cache-status"
		data-module-version="${MODULE_VERSION}" data-server-build-id="${CLIENT_BUILD_ID}"
		data-cache-settings='${settings}' data-cache-status='${status}'
		data-can-manage-settings="${canManage ? '1' : '0'}" data-csrf-name="csrf_key"
		data-csrf-token="${canManage ? 'csrf-value-123' : ''}" data-data-csrf-token="data-token-123"
		data-initial-lookback="" data-initial-tab="settings"></main></body></html>`;
}

async function run() {
	const browser = await chromium.launch({headless: true, executablePath});
	const page = await browser.newPage({viewport: {width: 1280, height: 760}});
	const data = fixture();
	const browserErrors = [];
	page.on('pageerror', (error) => browserErrors.push(error.message));
	page.on('console', (message) => { if (message.type() === 'error') { browserErrors.push(message.text()); } });
	let assertions = 0;
	const ok = (condition, message) => { assertions++; assert.ok(condition, message); };
	const same = (expected, actual, message) => { assertions++; assert.strictEqual(actual, expected, message); };

	try {
		const initialMismatchPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		await initialMismatchPage.addInitScript(() => {
			window.__mismatchFetches = 0;
			window.fetch = async () => { window.__mismatchFetches++; return new Response('{}'); };
		});
		await initialMismatchPage.route('http://initial-build-mismatch.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html',
			body: appDocument('disks').replace(`data-server-build-id="${CLIENT_BUILD_ID}"`,
				'data-server-build-id="1.4.0-stale-server"')
		}));
		await initialMismatchPage.goto('http://initial-build-mismatch.test/zabbix.php?action=capacity.planning.view');
		await initialMismatchPage.addScriptTag({path: jsAsset});
		await initialMismatchPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await initialMismatchPage.waitForFunction(() =>
			document.querySelector('[data-role="error"]').textContent.includes('Mixed or stale'));
		const initialMismatchText = await initialMismatchPage.locator('[data-role="error"]').textContent();
		ok(initialMismatchText.includes('server build 1.4.0-stale-server')
			&& initialMismatchText.includes(`browser build ${CLIENT_BUILD_ID}`)
			&& initialMismatchText.includes('restart PHP-FPM/Apache')
			&& initialMismatchText.includes('hard-refresh'),
			'An initial build mismatch should immediately show actionable PHP/browser recovery steps.');
		same(0, await initialMismatchPage.evaluate(() => window.__mismatchFetches),
			'An initial build mismatch must fail closed before any API request.');
		await initialMismatchPage.close();

		const responseMismatchPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		await responseMismatchPage.addInitScript(() => {
			window.fetch = async () => new Response(JSON.stringify({build_id: '1.4.0-stale-response'}),
				{status: 200, headers: {'Content-Type': 'application/json'}});
		});
		await responseMismatchPage.route('http://response-build-mismatch.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: appDocument('disks')
		}));
		await responseMismatchPage.goto('http://response-build-mismatch.test/zabbix.php?action=capacity.planning.view');
		await responseMismatchPage.addScriptTag({path: jsAsset});
		await responseMismatchPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await responseMismatchPage.waitForFunction(() =>
			document.querySelector('[data-role="error"]').textContent.includes('Mixed or stale'));
		ok((await responseMismatchPage.locator('[data-role="error"]').textContent())
			.includes('server build 1.4.0-stale-response'),
			'Every capacity-data response should reject a mismatched build identity.');
		await responseMismatchPage.close();

		const cacheMismatchPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		await cacheMismatchPage.addInitScript(() => {
			window.fetch = async () => new Response(JSON.stringify({
				build_id: '1.4.0-stale-cache', ok: true, cache_status: {}
			}), {status: 200, headers: {'Content-Type': 'application/json'}});
		});
		await cacheMismatchPage.route('http://cache-build-mismatch.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(true)
		}));
		await cacheMismatchPage.goto('http://cache-build-mismatch.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await cacheMismatchPage.addScriptTag({path: jsAsset});
		await cacheMismatchPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await cacheMismatchPage.waitForFunction(() =>
			document.querySelector('[data-role="settings-message"]').textContent.includes('Mixed or stale'));
		ok((await cacheMismatchPage.locator('[data-role="settings-message"]').textContent())
			.includes('cache-status response')
			&& (await cacheMismatchPage.locator('[data-role="settings-message"]').textContent())
				.includes('server build 1.4.0-stale-cache'),
			'A cache-status response with a stale build must fail closed with deployment recovery guidance.');
		await cacheMismatchPage.close();

		const settingsMismatchPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		await settingsMismatchPage.addInitScript((buildId) => {
			window.fetch = async (url) => {
				if (String(url).includes('/cache-status')) {
					return new Response(JSON.stringify({
						build_id: buildId, ok: true,
						cache: {enabled: true, ttl_seconds: 1800},
						cache_status: {backend_available: true, files: 0, bytes: 0,
							max_bytes: 1048576, private_permissions_verified: true, scan_complete: true}
					}), {status: 200, headers: {'Content-Type': 'application/json'}});
				}
				return new Response(JSON.stringify({build_id: '1.4.0-stale-settings', ok: true}),
					{status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, CLIENT_BUILD_ID);
		await settingsMismatchPage.route('http://settings-build-mismatch.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(true)
		}));
		await settingsMismatchPage.goto('http://settings-build-mismatch.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await settingsMismatchPage.addScriptTag({path: jsAsset});
		await settingsMismatchPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await settingsMismatchPage.waitForFunction(() =>
			!document.querySelector('[data-role="save-cache-settings"]').disabled);
		await settingsMismatchPage.locator('[data-role="save-cache-settings"]').click();
		await settingsMismatchPage.waitForFunction(() =>
			document.querySelector('[data-role="settings-message"]').textContent.includes('Mixed or stale'));
		const staleSettingsMessage = await settingsMismatchPage.locator('[data-role="settings-message"]').textContent();
		ok(staleSettingsMessage.includes('settings response')
			&& staleSettingsMessage.includes('server build 1.4.0-stale-settings'),
			'A settings-save response with a stale build must fail closed instead of applying its payload.');
		await settingsMismatchPage.close();

		const adminPage = await browser.newPage({viewport: {width: 1180, height: 760}});
		adminPage.on('pageerror', (error) => browserErrors.push(`settings admin: ${error.message}`));
		adminPage.on('console', (message) => {
			if (message.type() === 'error') { browserErrors.push(`settings admin: ${message.text()}`); }
		});
		await adminPage.addInitScript((payload) => {
			window.__capacityFixture = payload;
			window.__settingsRequests = [];
			window.__settingsDataModes = [];
			window.__settingsForecastEnds = [];
			let selectedTtl = 1800;
			let clearStep = 0;
			const buildId = window.__capacityFixture.inventory.build_id;
			const fullStatus = (files, scanComplete = true) => ({
				enabled: selectedTtl !== 0, ttl_seconds: selectedTtl, backend_available: true,
				unavailable_reason: '', private_permissions_verified: true, files,
				bytes: files * 1024, max_bytes: 1024 * 1024, scan_complete: scanComplete,
				boot_invalidation: {available: true, source: 'linux-boot-id'}
			});
			window.fetch = async (url, options = {}) => {
				const href = String(url);
				const request = {url: href, method: options.method || 'GET', body: String(options.body || '')};
				window.__settingsRequests.push(request);
				if (href.includes('/cache-status')) {
					return new Response(JSON.stringify({build_id: buildId, ok: true,
						cache: {enabled: true, ttl_seconds: selectedTtl}, cache_status: fullStatus(4, false)}),
					{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				if (href.includes('/settings-save')) {
					const params = new URLSearchParams(request.body);
					const clearing = params.get('clear_cache') === '1';
					if (!clearing) { selectedTtl = Number(params.get('cache_ttl_seconds')); }
					if (clearing) { clearStep++; }
					const clearResult = !clearing ? null : (clearStep < 3
						? {ok: true, complete: false, progress: true, reason: 'clear_in_progress',
							removed_files: clearStep === 1 ? 3 : 0, removed_directories: clearStep === 2 ? 2 : 0}
						: {ok: true, complete: true, progress: false, reason: '',
							removed_files: 1, removed_directories: 0});
					return new Response(JSON.stringify({
						build_id: buildId,
						ok: true,
						message: clearing
							? (clearResult.complete ? 'Shared cache cleared.' : 'Shared cache clearing is in progress.')
							: 'Cache settings updated.',
						cache: {enabled: selectedTtl !== 0, ttl_seconds: selectedTtl},
						cache_status: fullStatus(clearing ? Math.max(0, 6 - clearStep * 2) : 5),
						clear_result: clearResult
					}), {status: 200, headers: {'Content-Type': 'application/json'}});
				}
				const params = new URLSearchParams(request.body);
				const mode = params.get('mode') || '';
				window.__settingsDataModes.push(mode);
				if (mode === 'forecast') { window.__settingsForecastEnds.push(Number(params.get('time_to'))); }
				let body;
				if (mode === 'inventory') { body = window.__capacityFixture.inventory; }
				else if (mode === 'forecast') { body = {build_id: buildId, forecasts: [window.__capacityFixture.forecast]}; }
				else { body = {build_id: buildId, groupids: [], hostids: [], empty: false}; }
				return new Response(JSON.stringify(body), {status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, data);
		await adminPage.route('http://settings-admin.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(true)
		}));
		await adminPage.goto('http://settings-admin.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await adminPage.addStyleTag({path: cssAsset});
		await adminPage.addScriptTag({path: jsAsset});
		await adminPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await adminPage.waitForFunction(() => window.__settingsRequests.some((request) => request.url.includes('/cache-status'))
			&& document.querySelector('[data-role="cache-status"]').textContent.includes('4'));
		same('settings', await adminPage.evaluate(() => new URL(window.location.href).searchParams.get('tab')),
			'The Settings deep link should remain canonical.');
		same(0, await adminPage.evaluate(() => window.__settingsDataModes.length),
			'Opening Settings must not load inventory or forecasts.');
		same(1, await adminPage.evaluate(() => window.__settingsRequests.filter((request) => request.url.includes('/cache-status')).length),
			'A Super Admin Settings view should lazily inspect shared cache status once.');
		same('GET', await adminPage.evaluate(() => window.__settingsRequests.find((request) => request.url.includes('/cache-status')).method),
			'The read-only cache status request should use GET.');
		ok(await adminPage.locator('[data-role="analysis-toolbar"]').isHidden()
			&& await adminPage.locator('[data-role="results-filter"]').isHidden(),
			'Analysis controls should stay out of the Settings view.');
		ok((await adminPage.locator('[data-role="cache-summary"]').textContent()).includes('On · recent refresh 30 minutes'),
			'The shared-cache configuration should be visible on Settings.');
		ok((await adminPage.locator('[data-role="cache-status"]').textContent()).includes('Stored shardsAt least 4'),
			'The lazily loaded status should show shared cache usage without a filesystem path.');
		ok((await adminPage.locator('[data-role="cache-status"]').textContent())
			.includes('Storage usedAt least 4.0 KiB of 1.0 MiB limit'),
			'An incomplete status scan should label both shard count and storage bytes as lower bounds.');
		const settingsCopy = await adminPage.locator('[data-panel="settings"]').textContent();
		ok(settingsCopy.includes(`PHP module version${MODULE_VERSION}`)
			&& settingsCopy.includes(`PHP/server build${CLIENT_BUILD_ID}`)
			&& settingsCopy.includes(`Browser build${CLIENT_BUILD_ID}`),
			'The Settings view should expose matching PHP and browser build identities.');
		ok(settingsCopy.includes('Recent-shard refresh interval') && settingsCopy.includes('not retention')
			&& settingsCopy.includes('historical shards remain'),
			'The Settings copy should explain that 15/30/60 minutes refreshes mutable shards and does not delete history.');
		ok(!(await adminPage.locator('[data-panel="settings"]').textContent()).match(/[A-Z]:\\|\/var\/|\/tmp\//),
			'The Settings view must not expose a cache filesystem path.');

		await adminPage.locator('[data-role="cache-ttl"]').selectOption('3600');
		await adminPage.locator('[data-role="save-cache-settings"]').click();
		await adminPage.waitForFunction(() => document.querySelector('[data-role="settings-message"]').textContent.includes('Cache settings updated.'));
		const saveBody = await adminPage.evaluate(() => window.__settingsRequests
			.filter((request) => request.url.includes('/settings-save'))[0].body);
		const savedParams = new URLSearchParams(saveBody);
		same('1', savedParams.get('cache_enabled'), 'Saving 60 minutes should enable the shared cache.');
		same('3600', savedParams.get('cache_ttl_seconds'), 'Saving should post the selected TTL.');
		same('csrf-value-123', savedParams.get('csrf_key'), 'Saving should include the configured CSRF field and token.');
		same(null, savedParams.get('clear_cache'), 'Saving settings should not clear the cache.');
		same('3600', await adminPage.locator('[data-role="cache-ttl"]').inputValue(),
			'The controls should update from the saved response.');

		await adminPage.evaluate(() => {
			window.__clearMessages = [];
			new MutationObserver(() => window.__clearMessages.push(
				document.querySelector('[data-role="settings-message"]').textContent
			)).observe(document.querySelector('[data-role="settings-message"]'), {childList: true, subtree: true});
		});
		await adminPage.locator('[data-role="clear-shared-cache"]').click();
		await adminPage.waitForFunction(() => {
			const message = document.querySelector('[data-role="settings-message"]').textContent;
			return message.includes('4 cache file(s) removed') && message.includes('2 empty directories removed');
		});
		const clearRequests = await adminPage.evaluate(() => window.__settingsRequests
			.filter((request) => request.url.includes('/settings-save'))
			.slice(1));
		same(3, clearRequests.length,
			'A single Clear click should continue bounded server requests until the cache reports complete.');
		ok(await adminPage.locator('[data-role="settings-message"]').textContent()
			.then((message) => message.includes('4 cache file(s) removed')
				&& message.includes('2 empty directories removed')),
			'Directory-only progress should continue automatically and be reported separately from payload files.');
		ok(await adminPage.evaluate(() => window.__clearMessages.some((message) =>
			message.includes('Clearing shared cache') && message.includes('cache file(s) removed'))),
			'Chunked clear progress should remain visible while follow-up requests run.');
		const clearBody = clearRequests[0].body;
		const clearParams = new URLSearchParams(clearBody);
		same('1', clearParams.get('clear_cache'), 'Clear should request the shared-cache clear operation.');
		same('csrf-value-123', clearParams.get('csrf_key'), 'Clear should include the configured CSRF field and token.');
		same(null, clearParams.get('cache_enabled'), 'Clear should not silently save an edited enabled state.');
		same(null, clearParams.get('cache_ttl_seconds'), 'Clear should not silently save an edited TTL.');
		ok(clearRequests.every((request) => new URLSearchParams(request.body).get('csrf_key') === 'csrf-value-123'),
			'Every bounded clear continuation should remain CSRF protected.');
		ok((await adminPage.locator('[data-role="cache-status"]').textContent()).includes('Stored shards0'),
			'Clear should update the displayed shared cache status from the response.');

		await adminPage.locator('[data-tab="overview"]').click();
		await adminPage.waitForFunction(() => window.__settingsDataModes.includes('inventory'));
		same(1, await adminPage.evaluate(() => window.__settingsDataModes.filter((mode) => mode === 'inventory').length),
			'The first analysis tab opened after Settings should lazy-load inventory once.');
		await adminPage.waitForFunction(() => window.__settingsForecastEnds.length > 0);
		same(data.inventory.meta.generated_at,
			await adminPage.evaluate(() => window.__settingsForecastEnds[0]),
			'Forecast requests should use the server-generated inventory time instead of the browser clock.');
		await adminPage.close();

		const readOnlyPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		readOnlyPage.on('pageerror', (error) => browserErrors.push(`settings read-only: ${error.message}`));
		readOnlyPage.on('console', (message) => {
			if (message.type() === 'error') { browserErrors.push(`settings read-only: ${message.text()}`); }
		});
		await readOnlyPage.addInitScript(() => {
			window.__readOnlyFetches = [];
			window.fetch = async (url, options = {}) => {
				window.__readOnlyFetches.push({url: String(url), body: String(options.body || '')});
				return new Response(JSON.stringify({build_id: '1.4.0-20260801.1', ok: false,
					error: 'Unexpected request'}), {status: 500});
			};
		});
		await readOnlyPage.route('http://settings-user.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(false, false, 0)
		}));
		await readOnlyPage.goto('http://settings-user.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await readOnlyPage.addStyleTag({path: cssAsset});
		await readOnlyPage.addScriptTag({path: jsAsset});
		await readOnlyPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await readOnlyPage.waitForSelector('[data-role="settings-readonly"]');
		await readOnlyPage.waitForTimeout(30);
		same(0, await readOnlyPage.evaluate(() => window.__readOnlyFetches.length),
			'A non-admin Settings view should not call detailed status or analysis endpoints.');
		same(0, await readOnlyPage.locator('[data-role="cache-ttl"], [data-role="save-cache-settings"], [data-role="clear-shared-cache"]').count(),
			'Cache mutation controls should not be rendered for non-admin users.');
		ok(await readOnlyPage.locator('[data-role="settings-readonly"]').isVisible(),
			'Non-admin users should see an explicit read-only explanation.');
		ok((await readOnlyPage.locator('[data-role="cache-summary"]').textContent()).includes('Off'),
			'Non-admin users should still see the cheap shared-cache configuration snapshot.');
		await readOnlyPage.close();

		const statusFailurePage = await browser.newPage({viewport: {width: 1000, height: 700}});
		statusFailurePage.on('pageerror', (error) => browserErrors.push(`settings status fallback: ${error.message}`));
		await statusFailurePage.addInitScript((buildId) => {
			window.__statusFailureFetches = [];
			window.fetch = async (url) => {
				window.__statusFailureFetches.push(String(url));
				return new Response(JSON.stringify({build_id: buildId, ok: false, error: 'cache_busy'}),
					{status: 503, headers: {'Content-Type': 'application/json'}});
			};
		}, CLIENT_BUILD_ID);
		await statusFailurePage.route('http://settings-fallback.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(true)
		}));
		await statusFailurePage.goto('http://settings-fallback.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await statusFailurePage.addStyleTag({path: cssAsset});
		await statusFailurePage.addScriptTag({path: jsAsset});
		await statusFailurePage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await statusFailurePage.waitForFunction(() => document.querySelector('[data-role="settings-message"]')
			.textContent.includes('configured cache setting shown above is still valid'));
		same(1, await statusFailurePage.evaluate(() => window.__statusFailureFetches.length),
			'A failed detailed status inspection should not be retried or trigger analysis calls.');
		ok((await statusFailurePage.locator('[data-role="cache-summary"]').textContent()).includes('On · recent refresh 30 minutes'),
			'The cheap configured snapshot should remain visible when detailed status fails.');
		ok(await statusFailurePage.locator('[data-role="save-cache-settings"]').isEnabled(),
			'Settings controls should recover after a failed read-only status inspection.');
		await statusFailurePage.close();

		for (const failure of [
			{code: 'cache_busy', status: 409,
				expected: 'The shared cache is busy. Wait for the active analysis to finish, then try again.'},
			{code: 'clear_io_failed', status: 500,
				expected: 'The shared cache could not be cleared because a file operation failed. Check the PHP-FPM log and cache-directory ownership, mode, and SELinux context.'}
		]) {
			const failurePage = await browser.newPage({viewport: {width: 1000, height: 700}});
			failurePage.on('pageerror', (error) => browserErrors.push(`settings ${failure.code}: ${error.message}`));
			await failurePage.addInitScript((scenario) => {
				window.__clearFailureRequests = [];
				window.fetch = async (url, options = {}) => {
					const href = String(url);
					if (href.includes('/cache-status')) {
						return new Response(JSON.stringify({build_id: scenario.buildId, ok: true,
							cache: {enabled: true, ttl_seconds: 1800},
							cache_status: {enabled: true, ttl_seconds: 1800, backend_available: true,
								private_permissions_verified: true, files: 2, bytes: 2048, max_bytes: 1048576,
								scan_complete: true, boot_invalidation: {available: true}}}),
						{status: 200, headers: {'Content-Type': 'application/json'}});
					}
					window.__clearFailureRequests.push(String(options.body || ''));
					return new Response(JSON.stringify({build_id: scenario.buildId, ok: false,
						error: {code: scenario.code, message: 'Server-provided safe fallback message.'},
						clear_result: {ok: false, complete: false, reason: scenario.code, removed_files: 0}}),
						{status: scenario.status, headers: {'Content-Type': 'application/json'}});
				};
			}, {...failure, buildId: CLIENT_BUILD_ID});
			const host = `settings-${failure.code.replaceAll('_', '-')}.test`;
			await failurePage.route(`http://${host}/**`, (route) => route.fulfill({
				status: 200, contentType: 'text/html', body: settingsDocument(true)
			}));
			await failurePage.goto(`http://${host}/zabbix.php?action=capacity.planning.view&tab=settings`);
			await failurePage.addStyleTag({path: cssAsset});
			await failurePage.addScriptTag({path: jsAsset});
			await failurePage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
			await failurePage.waitForFunction(() => !document.querySelector('[data-role="cache-status"]')
				.textContent.includes('Loading'));
			await failurePage.locator('[data-role="clear-shared-cache"]').click();
			await failurePage.waitForFunction((expected) =>
				document.querySelector('[data-role="settings-message"]').textContent === expected, failure.expected);
			same(failure.expected, await failurePage.locator('[data-role="settings-message"]').textContent(),
				`${failure.code} should retain its exact actionable cache-clear explanation.`);
			same(1, await failurePage.evaluate(() => window.__clearFailureRequests.length),
				`${failure.code} must stop automatic clear continuation after a real failure.`);
			await failurePage.close();
		}

		const noProgressPage = await browser.newPage({viewport: {width: 1000, height: 700}});
		noProgressPage.on('pageerror', (error) => browserErrors.push(`settings clear no progress: ${error.message}`));
		await noProgressPage.addInitScript((buildId) => {
			window.__noProgressClearRequests = 0;
			window.__malformedClearFinal = false;
			window.fetch = async (url) => {
				if (String(url).includes('/cache-status')) {
					return new Response(JSON.stringify({build_id: buildId, ok: true,
						cache: {enabled: true, ttl_seconds: 1800},
						cache_status: {enabled: true, ttl_seconds: 1800, backend_available: true,
							private_permissions_verified: true, files: 2, bytes: 2048, max_bytes: 1048576,
							scan_complete: true, boot_invalidation: {available: true}}}),
					{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				window.__noProgressClearRequests++;
				if (window.__malformedClearFinal) {
					return new Response(JSON.stringify({build_id: buildId, ok: true, message: 'Shared cache cleared.',
						cache: {enabled: true, ttl_seconds: 1800},
						clear_result: {ok: true, removed_files: 0, removed_directories: 0}}),
					{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				return new Response(JSON.stringify({build_id: buildId, ok: true, message: 'Shared cache clearing is in progress.',
					cache: {enabled: true, ttl_seconds: 1800},
					clear_result: {ok: true, complete: false, progress: true,
						reason: 'clear_in_progress', removed_files: 0}}),
				{status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, CLIENT_BUILD_ID);
		await noProgressPage.route('http://settings-no-progress.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: settingsDocument(true)
		}));
		await noProgressPage.goto('http://settings-no-progress.test/zabbix.php?action=capacity.planning.view&tab=settings');
		await noProgressPage.addStyleTag({path: cssAsset});
		await noProgressPage.addScriptTag({path: jsAsset});
		await noProgressPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await noProgressPage.waitForFunction(() => !document.querySelector('[data-role="cache-status"]')
			.textContent.includes('Loading'));
		await noProgressPage.locator('[data-role="clear-shared-cache"]').click();
		const noProgressError = 'The shared cache clear reported no progress. Wait for active analysis to finish, then try again.';
		await noProgressPage.waitForFunction((expected) =>
			document.querySelector('[data-role="settings-message"]').textContent === expected, noProgressError);
		same(1, await noProgressPage.evaluate(() => window.__noProgressClearRequests),
			'A successful partial response that removes no files must stop after one request instead of looping.');
		same(noProgressError, await noProgressPage.locator('[data-role="settings-message"]').textContent(),
			'A zero-progress partial clear should show its actionable stop reason.');
		ok(await noProgressPage.locator('[data-role="clear-shared-cache"]').isEnabled(),
			'The Clear control should recover after a zero-progress response.');
		await noProgressPage.evaluate(() => { window.__malformedClearFinal = true; });
		await noProgressPage.locator('[data-role="clear-shared-cache"]').click();
		const malformedFinalError = 'The server returned an invalid cache-clear progress response.';
		await noProgressPage.waitForFunction((expected) =>
			document.querySelector('[data-role="settings-message"]').textContent === expected, malformedFinalError);
		same(2, await noProgressPage.evaluate(() => window.__noProgressClearRequests),
			'A malformed terminal response must stop after one request.');
		same(malformedFinalError, await noProgressPage.locator('[data-role="settings-message"]').textContent(),
			'A successful-looking response without complete=true must never be reported as cleared.');
		await noProgressPage.close();

		const scopePage = await browser.newPage({viewport: {width: 1180, height: 760}});
		const scopeData = fixture();
		scopeData.inventory.disks = [];
		scopeData.inventory.resources = [];
		scopeData.inventory.quality = [];
		scopePage.on('pageerror', (error) => browserErrors.push(`inventory scope: ${error.message}`));
		scopePage.on('console', (message) => {
			if (message.type() === 'error') { browserErrors.push(`inventory scope: ${message.text()}`); }
		});
		await scopePage.addInitScript((payload) => {
			window.__scopeFixture = payload;
			const buildId = payload.inventory.build_id;
			window.__scopeRequests = [];
			window.__scopeAborts = 0;
			const delay = (milliseconds, signal) => new Promise((resolve, reject) => {
				let timer = null;
				const cleanup = () => { if (signal) { signal.removeEventListener('abort', onAbort); } };
				const onAbort = () => {
					if (timer !== null) { clearTimeout(timer); }
					cleanup();
					window.__scopeAborts++;
					reject(new DOMException('The operation was aborted.', 'AbortError'));
				};
				if (signal && signal.aborted) { onAbort(); return; }
				if (signal) { signal.addEventListener('abort', onAbort, {once: true}); }
				timer = setTimeout(() => { cleanup(); resolve(); }, milliseconds);
			});
			const section = (count, samples, activeSamples = samples, termSamples = null) => {
				const preview = {
					available: true, count, count_is_lower_bound: false,
					samples: samples.map((label, index) => ({id: String(index + 1), label})),
					active_samples: activeSamples.map((label, index) => ({id: String(index + 101), label}))
				};
				if (termSamples !== null) { preview.term_samples = termSamples; }
				return preview;
			};
			window.fetch = async (_url, options = {}) => {
				const params = new URLSearchParams(options.body || '');
				const request = {
					mode: params.get('mode') || '', group: params.get('group') || '',
					host: params.get('host') || '', template: params.get('template') || ''
				};
				window.__scopeRequests.push(request);
				if (request.mode === 'inventory') {
					return new Response(JSON.stringify(window.__scopeFixture.inventory),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				if (request.mode === 'forecast') {
					return new Response(JSON.stringify({build_id: buildId, forecasts: []}),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				if (request.mode !== 'resolve') {
					return new Response(JSON.stringify({build_id: buildId, groupids: [], hostids: [], empty: false}),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				await delay(request.group === 'slow' ? 450 : 20, options.signal);
				if (request.template.includes('/bad[/')) {
					return new Response(JSON.stringify({build_id: buildId, error: {
						message: 'Invalid regular expression in Templates.', field: 'template'
					}}), {status: 400, headers: {'Content-Type': 'application/json'}});
				}
				if (request.group === 'wide') {
					return new Response(JSON.stringify({
						build_id: buildId,
						groupids: ['10'], hostids: ['1'], empty: false, truncated: true,
						summary: 'Too many hosts matched. Narrow the inventory scope.',
						preview: {groups: section(6000, ['Wide group']), hosts: section(0, []),
							templates: section(0, []), resolved_hosts: section(5000, [])}
					}), {status: 200, headers: {'Content-Type': 'application/json'}});
				}
				const groupSamples = request.group === 'slow' ? ['Slow result']
					: (request.group === 'fast' ? ['Fast result'] : ['Database Servers', 'Data Warehouse']);
				const activeGroups = request.group.toLowerCase().includes('zzzzz') ? []
					: (request.group.toLowerCase().includes('pro') || request.group.toLowerCase().includes('pla')
						? ['Production, Core\\EU', '/Platform'] : groupSamples);
				let groupSection = section(request.group ? 2 : 0, groupSamples, activeGroups);
				if (request.group === 'first, mid, last') {
					groupSection = section(3, ['First result', 'Middle result', 'Last result'], ['Last result'], [
						{index: 0, kind: 'literal', value: 'first', count: 1, count_is_lower_bound: false,
							samples: [{id: '201', label: 'First result'}]},
						{index: 1, kind: 'literal', value: 'mid', count: 1, count_is_lower_bound: false,
							samples: [{id: '202', label: 'Middle result'}]},
						{index: 2, kind: 'literal', value: 'last', count: 1, count_is_lower_bound: false,
							samples: [{id: '203', label: 'Last result'}]}
					]);
				}
				else if (request.group === 'Database, zzzzz') {
					groupSection = section(1, ['Database Servers'], [], [
						{index: 0, kind: 'literal', value: 'Database', count: 1, count_is_lower_bound: false,
							samples: [{id: '204', label: 'Database Servers'}]},
						{index: 1, kind: 'literal', value: 'zzzzz', count: 0, count_is_lower_bound: false, samples: []}
					]);
				}
				const combinedAvailable = !!(request.host || request.template);
				return new Response(JSON.stringify({
					build_id: buildId,
					groupids: request.group ? ['10'] : [], hostids: ['1'], empty: false, truncated: false,
					preview: {
						groups: groupSection,
						hosts: section(request.host ? 2 : 0, ['db01', 'web-01']),
						templates: section(request.template ? 1 : 0, ['Linux by Zabbix agent']),
						resolved_hosts: combinedAvailable ? section(2, [])
							: {available: false, count: null, count_is_lower_bound: false, samples: [], active_samples: []}
					}
				}), {status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, scopeData);
		await scopePage.route('http://scope.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: appDocument('disks')
		}));
		await scopePage.goto('http://scope.test/zabbix.php?action=capacity.planning.view&tab=disks');
		await scopePage.addStyleTag({path: cssAsset});
		await scopePage.addScriptTag({path: jsAsset});
		await scopePage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await scopePage.waitForFunction(() => document.querySelector('[data-role="loading"]').hidden);
		same(1, await scopePage.evaluate(() => window.__scopeRequests.filter((request) => request.mode === 'inventory').length),
			'The scope fixture should begin with one unfiltered inventory load.');
		const scopeHelp = await scopePage.locator('#cap-scope-help').textContent();
		ok(scopeHelp.includes('commas') && scopeHelp.includes('/pattern/i') && scopeHelp.includes('OR') && scopeHelp.includes('AND'),
			'Inventory scope should explain comma values, regex syntax, and its OR/AND semantics.');

		const groupInput = scopePage.locator('[data-filter="group"]');
		await groupInput.fill('Data');
		await scopePage.waitForTimeout(120);
		same(0, await scopePage.evaluate(() => window.__scopeRequests.filter((request) => request.mode === 'resolve').length),
			'Scope matching should be debounced rather than requested on every key stroke.');
		same(1, await scopePage.evaluate(() => window.__scopeRequests.filter((request) => request.mode === 'inventory').length),
			'Typing a draft scope must not reload the report inventory.');
		await scopePage.waitForFunction(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.group === 'Data'));
		await scopePage.waitForFunction(() => document.querySelector('[data-scope-field-status="group"]').textContent.includes('2 host groups'));
		ok((await scopePage.locator('[data-role="scope-preview-summary"]').textContent()).includes('Scope syntax is valid')
			&& !(await scopePage.locator('[data-role="scope-preview-summary"]').textContent()).includes('No permission-visible hosts'),
			'An unavailable combined count must not be announced as zero matching hosts.');
		ok((await scopePage.locator('[data-role="scope-preview-summary"]').textContent()).includes('Apply scope'),
			'Live matching should make clear that the draft is not yet applied.');
		same(null, await scopePage.evaluate(() => new URL(window.location.href).searchParams.get('group')),
			'Typing must keep draft scope separate from the applied URL state.');
		await groupInput.press('ArrowDown');
		ok(await scopePage.evaluate(() => document.activeElement
			&& document.activeElement.matches('[data-scope-options="group"] [role="option"]')),
			'Arrow Down should move keyboard focus from the combobox into its listbox.');
		await scopePage.keyboard.press('Escape');
		ok(await groupInput.evaluate((input) => input === document.activeElement && input.getAttribute('aria-expanded') === 'false'),
			'Escape in the listbox should close it and return focus to the owning combobox.');

		await groupInput.fill('first, mid, last');
		await scopePage.waitForFunction(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.group === 'first, mid, last'));
		await scopePage.waitForFunction(() => !document.querySelector('[data-role="apply-filters"]').disabled);
		await groupInput.evaluate((input) => {
			const cursor = input.value.indexOf('mid') + 1;
			input.setSelectionRange(cursor, cursor);
			input.click();
		});
		await scopePage.waitForFunction(() => [...document.querySelectorAll('[data-scope-options="group"] [data-scope-suggestion]')]
			.some((button) => button.textContent === 'Middle result'));
		ok(!(await scopePage.locator('[data-scope-options="group"]').textContent()).includes('Last result'),
			'Moving the cursor to a middle comma term should show that term\'s matches, not the last term\'s matches.');
		await scopePage.locator('[data-scope-options="group"] [data-scope-suggestion]', {hasText: 'Middle result'}).click();
		same('first, Middle result, last', await groupInput.inputValue(),
			'Choosing a middle-token suggestion should preserve the comma terms on both sides.');

		await groupInput.fill('Databases, pro');
		await scopePage.waitForFunction(() => [...document.querySelectorAll('[data-scope-options="group"] [data-scope-suggestion]')]
			.some((button) => button.textContent === 'Production, Core\\EU'));
		await scopePage.locator('[data-scope-options="group"] [data-scope-suggestion]', {hasText: 'Production, Core\\EU'}).click();
		same('Databases, Production\\, Core\\\\EU', await groupInput.inputValue(),
			'Choosing a suggestion should replace the active comma token and escape comma/backslash literals.');
		await groupInput.fill('Databases, pla');
		await scopePage.waitForFunction(() => [...document.querySelectorAll('[data-scope-options="group"] [data-scope-suggestion]')]
			.some((button) => button.textContent === '/Platform'));
		await scopePage.locator('[data-scope-options="group"] [data-scope-suggestion]', {hasText: '/Platform'}).click();
		same('Databases, \\/Platform', await groupInput.inputValue(),
			'A suggestion beginning with slash should be escaped as a literal instead of becoming regex.');

		await groupInput.fill('Database, /Prod-.+/i');
		await scopePage.waitForFunction(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.group === 'Database, /Prod-.+/i'));
		await scopePage.locator('[data-scope-options="group"]').waitFor({state: 'visible'});
		ok(await scopePage.locator('[data-scope-options="group"]').isVisible(),
			'Matching permission-filtered suggestions should open while the field has focus.');
		await scopePage.locator('[data-role="analysis-toolbar"]').click();
		ok(await scopePage.locator('[data-scope-options="group"]').isHidden(),
			'Clicking outside Inventory scope should close its suggestion list.');
		await groupInput.focus();
		ok(await scopePage.locator('[data-scope-options="group"]').isVisible(),
			'Refocusing a validated draft should reopen available suggestions.');
		await groupInput.press('Escape');
		ok(await scopePage.locator('[data-scope-options="group"]').isHidden(),
			'Escape should close suggestions without changing the draft.');
		const regexRequest = await scopePage.evaluate(() => window.__scopeRequests.find((request) => request.mode === 'resolve'
			&& request.group === 'Database, /Prod-.+/i'));
		same('Database, /Prod-.+/i', regexRequest.group,
			'Comma-separated literals and regex must be sent to the server verbatim.');
		await groupInput.fill('Database, zzzzz');
		await scopePage.waitForFunction(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.group === 'Database, zzzzz'));
		await scopePage.waitForFunction(() => !document.querySelector('[data-role="apply-filters"]').disabled);
		same(0, await scopePage.locator('[data-scope-options="group"] [data-scope-suggestion]').count(),
			'An empty active-token result must not fall back to unrelated matches from earlier comma terms.');

		await groupInput.fill('slow');
		await scopePage.waitForFunction(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.group === 'slow'));
		await groupInput.fill('fast');
		await scopePage.waitForFunction(() => [...document.querySelectorAll('[data-scope-options="group"] [data-scope-suggestion]')]
			.some((button) => button.textContent === 'Fast result'));
		await scopePage.waitForTimeout(480);
		ok(await scopePage.locator('[data-scope-options="group"]').textContent().then((text) => text.includes('Fast result') && !text.includes('Slow result')),
			'A stale slow response must not replace suggestions for newer input.');
		ok(await scopePage.evaluate(() => window.__scopeAborts > 0),
			'A newer draft should abort its own obsolete preview request.');

		const templateInput = scopePage.locator('[data-filter="template"]');
		const hostInput = scopePage.locator('[data-filter="host"]');
		await templateInput.fill('/bad[/');
		await hostInput.fill('db');
		await scopePage.waitForFunction(() => !document.querySelector('[data-role="scope-error"]').hidden);
		ok((await scopePage.locator('[data-role="scope-error"]').textContent()).includes('Invalid regular expression'),
			'Server regex validation errors should be shown beside Inventory scope.');
		same('true', await templateInput.getAttribute('aria-invalid'),
			'The server-reported invalid field should be marked even when another field has focus.');
		same(null, await hostInput.getAttribute('aria-invalid'),
			'A validation response should not mark the merely active field when the server identifies another field.');
		ok(await scopePage.locator('[data-role="apply-filters"]').isDisabled(),
			'An invalid regex must disable Apply scope.');
		const inventoryBeforeInvalidEnter = await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length);
		await hostInput.press('Enter');
		await scopePage.waitForTimeout(40);
		same(inventoryBeforeInvalidEnter, await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length),
			'Enter must not apply a draft rejected by server validation.');

		await templateInput.fill('/Linux.*/i');
		await scopePage.waitForFunction(() => document.querySelector('[data-role="scope-error"]').hidden
			&& !document.querySelector('[data-role="apply-filters"]').disabled);
		ok(await scopePage.evaluate(() => window.__scopeRequests.some((request) => request.mode === 'resolve'
			&& request.template === '/Linux.*/i')),
			'A valid regex should be preserved verbatim in the preview request.');
		await groupInput.fill('wide');
		await scopePage.waitForFunction(() => document.querySelector('[data-role="scope-error"]')
			.textContent.includes('Narrow the inventory scope'));
		ok(await scopePage.locator('[data-role="apply-filters"]').isDisabled(),
			'A truncated resolution must not allow an arbitrary partial scope to be applied.');
		await groupInput.fill('fast');
		await scopePage.waitForFunction(() => document.querySelector('[data-role="scope-error"]').hidden
			&& !document.querySelector('[data-role="apply-filters"]').disabled);
		const resolvesBeforeApply = await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'resolve').length);
		const inventoriesBeforeApply = await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length);
		await scopePage.locator('[data-role="apply-filters"]').click();
		await scopePage.waitForFunction((before) => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length === before + 1, inventoriesBeforeApply);
		same(resolvesBeforeApply, await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'resolve').length),
			'Apply should reuse the exact successful preview instead of resolving the same draft twice.');
		same('fast', await scopePage.evaluate(() => new URL(window.location.href).searchParams.get('group')),
			'Only Apply scope should commit the validated draft to the report URL.');
		ok((await scopePage.locator('[data-role="scope-preview-summary"]').textContent()).includes('This scope is applied'),
			'The matching summary should identify the scope as applied after report loading begins.');
		const requestsBeforeRefresh = await scopePage.evaluate(() => window.__scopeRequests.length);
		const inventoriesBeforeRefresh = await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length);
		await scopePage.locator('[data-role="reload"]').click();
		await scopePage.waitForFunction((before) => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length === before + 1, inventoriesBeforeRefresh);
		const refreshModes = await scopePage.evaluate((start) => window.__scopeRequests.slice(start)
			.map((request) => request.mode), requestsBeforeRefresh);
		same('resolve', refreshModes[0],
			'Refresh now should re-evaluate a populated applied scope before reusing its allow-list.');
		same('inventory', refreshModes[1],
			'Refresh now should request inventory only after the applied scope resolves completely again.');

		await scopePage.goto('http://scope.test/zabbix.php?action=capacity.planning.view&tab=disks&group=wide');
		await scopePage.addStyleTag({path: cssAsset});
		await scopePage.addScriptTag({path: jsAsset});
		await scopePage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await scopePage.waitForFunction(() => !document.querySelector('[data-role="scope-error"]').hidden
			&& document.querySelector('[data-role="loading"]').hidden);
		const blockedDeepLinkText = await scopePage.locator('[data-role="scope-error"]').textContent();
		ok(blockedDeepLinkText.includes('Narrow the inventory scope'),
			'A deep-linked broad scope should show the safety-limit explanation beside its fields.');
		same(0, await scopePage.evaluate(() => window.__scopeRequests
			.filter((request) => request.mode === 'inventory').length),
			'A deep-linked truncated resolution must be blocked before inventory is requested.');
		ok((await scopePage.locator('[data-role="disks-body"] .cap-empty').textContent()).includes('Narrow the inventory scope')
			&& !(await scopePage.locator('[data-role="disks-body"] .cap-empty').textContent()).includes('No hosts'),
			'A blocked deep link should not be misreported as a legitimate empty match.');
		await scopePage.close();

		const poolPage = await browser.newPage({viewport: {width: 1180, height: 760}});
		const poolData = forecastPoolFixture();
		poolPage.on('pageerror', (error) => browserErrors.push(`forecast pool: ${error.message}`));
		poolPage.on('console', (message) => {
			if (message.type() === 'error') { browserErrors.push(`forecast pool: ${message.text()}`); }
		});
		await poolPage.addInitScript((payload) => {
			window.__capacityFixture = payload;
			const buildId = payload.inventory.build_id;
			window.__poolStats = {
				active: 0, maxActive: 0, activeByDays: {}, maxByDays: {},
				started: [], finished: [], failed: [], aborted: []
			};
			const delayById = {'pool-1': 90, 'pool-3': 20, 'pool-2': 140, 'pool-4': 50, 'pool-5': 40, 'pool-0': 30};
			const abortableDelay = (milliseconds, signal) => new Promise((resolve, reject) => {
				let timer = null;
				const cleanup = () => {
					if (signal) { signal.removeEventListener('abort', onAbort); }
				};
				const onAbort = () => {
					if (timer !== null) { clearTimeout(timer); }
					cleanup();
					reject(new DOMException('The operation was aborted.', 'AbortError'));
				};
				if (signal && signal.aborted) { onAbort(); return; }
				if (signal) { signal.addEventListener('abort', onAbort, {once: true}); }
				timer = setTimeout(() => { cleanup(); resolve(); }, milliseconds);
			});
			const forecast = (id) => ({
				id, status: 'ok', severity: 'Healthy', confidence: 'Pool result', source: 'history',
				sel: '3m', sel_label: '3 months', selected: {days: 90, cov: 100, r2: 0.9},
				growth_day: 0, growth_pct_day: 0, series: [], pct_series: [], reasons: [],
				recommendation: 'No action.', eta: {warn_days: null, crit_days: null, full_days: null}
			});
			window.fetch = async (_url, options = {}) => {
				const params = new URLSearchParams(options.body || '');
				const mode = params.get('mode') || '';
				if (mode === 'inventory') {
					return new Response(JSON.stringify(window.__capacityFixture.inventory),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				if (mode !== 'forecast') {
					return new Response(JSON.stringify({build_id: buildId, groupids: [], hostids: [], empty: false}),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}

				const specs = JSON.parse(params.get('specs') || '[]');
				const id = specs[0].id;
				const days = Math.round((Number(params.get('time_to')) - Number(params.get('time_from'))) / 86400);
				const stats = window.__poolStats;
				stats.started.push({id, days,
					refresh: params.get('refresh') || '', csrf: params.get('csrf_key') || ''});
				stats.active++;
				stats.maxActive = Math.max(stats.maxActive, stats.active);
				stats.activeByDays[days] = Number(stats.activeByDays[days] || 0) + 1;
				stats.maxByDays[days] = Math.max(Number(stats.maxByDays[days] || 0), stats.activeByDays[days]);
				try {
					await abortableDelay(days === 183 ? 300 : (days === 365 ? 15 : delayById[id]), options.signal);
					if (days === 92 && id === 'pool-1') {
						stats.failed.push({id, days});
						return new Response(JSON.stringify({build_id: buildId,
							error: {message: 'Fixture forecast batch failed.'}}),
							{status: 500, headers: {'Content-Type': 'application/json'}});
					}
					stats.finished.push({id, days});
					const cacheFallback = days === 92 && id === 'pool-0';
					return new Response(JSON.stringify({build_id: buildId,
						forecasts: [forecast(id)], meta: {cache: {
						enabled: true, backend_available: !cacheFallback, ttl_seconds: 3600,
						request: {requests: 1, shard_hits: 1, shard_misses: 2,
							shards_written: cacheFallback ? 1 : 2, live_fallbacks: cacheFallback ? 1 : 0,
							reasons: cacheFallback ? ['cache_io_unavailable'] : []}
					}}}),
						{status: 200, headers: {'Content-Type': 'application/json'}});
				}
				catch (error) {
					if (error && error.name === 'AbortError') { stats.aborted.push({id, days}); }
					throw error;
				}
				finally {
					stats.active--;
					stats.activeByDays[days]--;
				}
			};
		}, poolData);
		await poolPage.route('http://forecast-pool.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html',
			body: appDocument('disks')
		}));
		await poolPage.goto('http://forecast-pool.test/zabbix.php?action=capacity.planning.view&tab=disks');
		await poolPage.addStyleTag({path: cssAsset});
		await poolPage.addScriptTag({path: jsAsset});
		await poolPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await poolPage.waitForFunction(() => window.__poolStats.started.filter((entry) => entry.days === 92).length >= 3);
		same(3, await poolPage.evaluate(() => window.__poolStats.maxByDays[92]),
			'The forecast worker pool should cap one analysis run at three concurrent requests.');
		await poolPage.waitForFunction(() => window.__poolStats.activeByDays[92] > 0
			&& document.querySelector('[data-table="disks"] tbody tr[data-id="pool-3"] td:nth-child(9)')
				.textContent.includes('Pool result'));
		ok(await poolPage.locator('[data-role="loading"]').isVisible(),
			'A completed high-risk batch should render while other forecast batches are still in flight.');
		await poolPage.waitForFunction(() => document.querySelector('[data-role="loading"]').hidden);
		const expectedRiskOrder = poolData.inventory.disks.slice()
			.sort((a, b) => b.pused - a.pused).map((disk) => disk.id).join(',');
		same(expectedRiskOrder, await poolPage.evaluate(() => window.__poolStats.started
			.filter((entry) => entry.days === 92).map((entry) => entry.id).join(',')),
			'Concurrent workers should still claim batches in descending utilization order.');
		same(6, await poolPage.evaluate(() => window.__poolStats.started.filter((entry) => entry.days === 92).length),
			'A failed forecast batch should not prevent later queued batches from running.');
		same(5, await poolPage.locator('[data-table="disks"] tbody tr td:nth-child(9)', {hasText: 'Pool result'}).count(),
			'Every successful forecast batch should remain in the progressively assembled result set.');
		same('Unknown', await poolPage.locator('[data-table="disks"] tbody tr[data-id="pool-1"] .cap-risk-pill').textContent(),
			'The finding from a failed batch should settle to Unknown instead of remaining Pending.');
		same(0, await poolPage.locator('[data-table="disks"] tbody tr td:first-child .cap-muted').count(),
			'Completion should leave no rows in the Pending state after an isolated batch failure.');
		const poolError = await poolPage.locator('[data-role="error"]').textContent();
		ok(poolError.includes('1 of 6 forecast batch failed')
			&& poolError.includes('1 affected finding is shown as Unknown')
			&& poolError.includes('Fixture forecast batch failed.'),
			'The completed run should report the isolated failure without discarding successful batches.');
		const cacheMetaText = await poolPage.locator('[data-role="meta"]').textContent();
		ok(cacheMetaText.includes('cache: partial live fallback (1 range)')
			&& cacheMetaText.includes('5 reused, 10 loaded, 9 stored')
			&& !cacheMetaText.includes('refreshed'),
			'Cache metadata should retain any failed backend batch and label misses as loaded, not refreshed.');

		await poolPage.locator('[data-role="custom-lookback-toggle"]').click();
		await poolPage.locator('#cap-custom-lookback-days').fill('183');
		await poolPage.locator('[data-role="apply-custom-lookback"]').click();
		await poolPage.waitForFunction(() => window.__poolStats.started.filter((entry) => entry.days === 183).length === 3);
		await poolPage.locator('[data-role="custom-lookback-toggle"]').click();
		await poolPage.locator('#cap-custom-lookback-days').fill('365');
		await poolPage.locator('[data-role="apply-custom-lookback"]').click();
		await poolPage.waitForFunction(() => new URL(window.location.href).searchParams.get('lookback') === '365'
			&& document.querySelector('[data-role="loading"]').hidden);
		same(3, await poolPage.evaluate(() => window.__poolStats.started.filter((entry) => entry.days === 183).length),
			'Aborting a run should prevent its workers from claiming additional queued batches.');
		same(3, await poolPage.evaluate(() => window.__poolStats.aborted.filter((entry) => entry.days === 183).length),
			'Changing lookback should abort every in-flight request from the superseded run.');
		same(6, await poolPage.evaluate(() => window.__poolStats.finished.filter((entry) => entry.days === 365).length),
			'The replacement run should complete all forecasts after the previous pool is aborted.');
		ok(await poolPage.locator('[data-role="error"]').isHidden(),
			'An aborted stale run must not overwrite the replacement run with an error state.');
		ok(await poolPage.evaluate(() => window.__poolStats.started
			.every((entry) => entry.refresh === '0' && entry.csrf === '')),
			'Ordinary forecast requests must not force a refresh or carry the per-action CSRF token.');
		const startedBeforeReload = await poolPage.evaluate(() => window.__poolStats.started.length);
		await poolPage.locator('[data-role="reload"]').click();
		await poolPage.waitForFunction((start) => window.__poolStats.started.length > start, startedBeforeReload);
		const reloadForecast = await poolPage.evaluate((start) => window.__poolStats.started[start],
			startedBeforeReload);
		same('1', reloadForecast.refresh,
			'Refresh now must request uncached forecasts.');
		same('data-token-123', reloadForecast.csrf,
			'A forced refresh must send the per-action CSRF token under the configured field name; '
			+ 'without it the server silently downgrades to a cached load.');
		await poolPage.waitForFunction(() => document.querySelector('[data-role="loading"]').hidden);
		await poolPage.close();

		await page.addInitScript((payload) => {
			window.__capacityRequests = [];
			window.__capacityForecastDays = [];
			window.__capacityFixture = payload;
			window.fetch = async (_url, options = {}) => {
				const params = new URLSearchParams(options.body || '');
				const mode = params.get('mode') || '';
				window.__capacityRequests.push(mode);
				if (mode === 'forecast') {
					window.__capacityForecastDays.push(Math.round(
						(Number(params.get('time_to')) - Number(params.get('time_from'))) / 86400
					));
				}
				let body;
				if (mode === 'inventory') { body = window.__capacityFixture.inventory; }
				else if (mode === 'forecast') { body = {build_id: window.__capacityFixture.inventory.build_id,
					forecasts: [window.__capacityFixture.forecast]}; }
				else { body = {build_id: window.__capacityFixture.inventory.build_id,
					groupids: [], hostids: [], empty: false}; }
				return new Response(JSON.stringify(body), {status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, data);
		await page.route('http://capacity.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: appDocument('disks')
		}));
		await page.goto('http://capacity.test/zabbix.php?action=capacity.planning.view&tab=resources');
		await page.addStyleTag({path: cssAsset});
		await page.addScriptTag({path: jsAsset});
		await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await page.waitForSelector('[data-table="resources"] tbody tr');
		await page.waitForFunction(() => !document.querySelector('[data-role="loading"]')
			|| document.querySelector('[data-role="loading"]').hidden);
		same('cpu', await page.evaluate(() => new URL(window.location.href).searchParams.get('tab')),
			'The legacy resources tab should canonicalize to CPU.');
		same('cpu', await page.locator('[data-result-filter="type"]').inputValue(),
			'The CPU tab should synchronize the resource-type filter.');
		same(1, await page.locator('[data-table="resources"] tbody tr').count(),
			'The legacy resources tab should render only CPU rows.');
		same('r0', await page.locator('[data-table="resources"] tbody tr').first().getAttribute('data-id'),
			'The CPU tab should render the CPU finding.');
		ok(await page.locator('[data-table="resources"] tbody tr[data-id="r0"] .cap-maintenance-badge').isVisible(),
			'The CPU row should show an active maintenance badge.');
		ok((await page.locator('[data-table="resources"] tbody tr[data-id="r0"] td').nth(2).textContent()).includes('Last accepted'),
			'CPU values collected before no-data maintenance should be labelled last accepted.');

		await page.locator('[data-tab="memory"]').click();
		same('memory', await page.locator('[data-result-filter="type"]').inputValue(),
			'The RAM tab should synchronize the resource-type filter.');
		same('r1', await page.locator('[data-table="resources"] tbody tr').first().getAttribute('data-id'),
			'The RAM tab should render the memory finding.');
		ok(await page.locator('[data-table="resources"] tbody tr[data-id="r1"] .cap-maintenance-badge').isVisible(),
			'The RAM row should show an active maintenance badge.');
		ok((await page.locator('[data-table="resources"] tbody tr[data-id="r1"] td').nth(2).textContent()).includes('Last accepted'),
			'RAM values collected before no-data maintenance should be labelled last accepted.');
		await page.locator('[data-tab="disks"]').click();
		same('', await page.locator('[data-result-filter="type"]').inputValue(),
			'Opening Filesystems from a resource tab should clear an incompatible resource filter.');
		await page.waitForSelector('[data-table="disks"] tbody tr');

		same(25, await page.locator('[data-table="disks"] tbody tr').count(), 'The first page should contain 25 rows.');
		const noDataMaintenanceRow = page.locator('[data-table="disks"] tbody tr[data-id="d0"]');
		ok(await noDataMaintenanceRow.locator('.cap-maintenance-badge').isVisible(),
			'A filesystem in no-data maintenance should show a maintenance badge.');
		same(2, await noDataMaintenanceRow.locator('.cap-last-accepted').count(),
			'Used and free values should both be labelled last accepted during no-data maintenance.');
		const collectingMaintenanceRow = page.locator('[data-table="disks"] tbody tr[data-id="d21"]');
		ok(await collectingMaintenanceRow.locator('.cap-maintenance-badge').isVisible(),
			'A filesystem in maintenance with collection should still show a maintenance badge.');
		same(0, await collectingMaintenanceRow.locator('.cap-last-accepted').count(),
			'Maintenance with data collection should keep normal current-value semantics.');
		ok((await page.locator('[data-role="disks-body"] .cap-pager-summary').first().textContent()).includes('1–25 of 63'),
			'The pager should report the first 25 of 63 filesystems.');
		ok(await page.locator('[data-days="92"]').evaluate((el) => el.classList.contains('is-active')),
			'3M should be the default lookback.');
		same(0, await page.locator('[data-days="730"]').count(), 'The 24M preset should not be rendered.');

		await page.locator('[data-role="disks-body"] .cap-pager').first().locator('[data-page-action="next"]').click();
		ok((await page.locator('[data-role="disks-body"] .cap-pager-summary').first().textContent()).includes('26–50 of 63'),
			'Next should move to the second page.');
		await page.locator('[data-table="disks"] th[data-sort="host"]').click();
		ok((await page.locator('[data-role="disks-body"] .cap-pager-summary').first().textContent()).includes('1–25 of 63'),
			'Sorting should reset pagination to page one.');
		await page.locator('[data-role="disks-body"] .cap-pager').first().locator('[data-page-size]').selectOption('50');
		same(50, await page.locator('[data-table="disks"] tbody tr').count(), 'Page size 50 should render 50 rows.');

		await page.locator('[data-result-filter="group"]').selectOption({label: 'Databases'});
		same(21, await page.locator('[data-table="disks"] tbody tr').count(), 'The Databases group should contain 21 rows.');
		await page.locator('[data-result-filter="group"]').selectOption('');
		await page.locator('[data-result-filter="hostid"]').selectOption('2');
		same(21, await page.locator('[data-table="disks"] tbody tr').count(), 'The exact host facet should contain 21 rows.');
		await page.locator('[data-result-filter="hostid"]').selectOption('');
		await page.locator('[data-result-filter="group"]').selectOption({label: 'Web'});
		await page.locator('[data-result-filter="type"]').selectOption('disk-remote');
		await page.locator('[data-result-filter="status"]').selectOption('issues');
		same(21, await page.locator('[data-table="disks"] tbody tr').count(),
			'Group, resource type, and data status should combine with AND semantics.');
		await page.locator('[data-role="clear-result-filters"]').click();
		same(50, await page.locator('[data-table="disks"] tbody tr').count(), 'Clearing display fields should restore all rows.');
		await page.locator('[data-result-filter="status"]').selectOption('issues');
		same(42, await page.locator('[data-table="disks"] tbody tr').count(),
			'Expected no-data maintenance gaps should not be mixed into the data-issues filter.');
		await page.locator('[data-role="clear-result-filters"]').click();

		await page.evaluate(() => {
			const boxes = [...document.querySelectorAll('[data-role="risk-filter"] input[type="checkbox"]')];
			boxes.forEach((box) => { box.checked = box.value === 'Critical'; });
			boxes[0].dispatchEvent(new Event('change', {bubbles: true}));
		});
		same(21, await page.locator('[data-table="disks"] tbody tr').count(), 'Critical-only should show exactly 21 rows.');
		await page.locator('[data-risk-preset="all"]').click();
		same(50, await page.locator('[data-table="disks"] tbody tr').count(), 'Show all should restore the paginated list.');
		same(1, await page.evaluate(() => window.__capacityRequests.filter((mode) => mode === 'inventory').length),
			'Local filters, risk changes, sorting, and pagination must not reload inventory.');
		await page.locator('[data-tab="cpu"]').click();
		same(1, await page.locator('[data-table="resources"] tbody tr').count(), 'The CPU tab should render only CPU.');
		same('r0', await page.locator('[data-table="resources"] tbody tr').first().getAttribute('data-id'),
			'The CPU table should contain the CPU finding.');
		same(9, await page.locator('[data-table="resources"] thead th').count(),
			'The compact resource table should keep detailed secondary evidence in the modal.');
		same('CPU capacity evidence', await page.locator('[data-role="resources-title"]').textContent(),
			'The shared resource panel should use the CPU heading.');
		let downloadPromise = page.waitForEvent('download');
		await page.locator('[data-role="export-csv"]').click();
		let download = await downloadPromise;
		let csv = fs.readFileSync(await download.path(), 'utf8');
		ok(download.suggestedFilename().startsWith('capacity-cpu-'), 'The CPU export should have a CPU-specific filename.');
		ok(csv.includes('"CPU"') && !csv.includes('"Memory"'), 'The CPU CSV should exclude memory rows.');
		ok(csv.includes('Maintenance without data collection') && csv.includes('"Last accepted"'),
			'The CPU CSV should explain maintenance and last-accepted observation semantics.');
		ok(csv.includes('CPU value is last accepted because maintenance pauses collection.'),
			'The CPU CSV should include the current-state reason.');

		await page.locator('[data-tab="memory"]').click();
		same(1, await page.locator('[data-table="resources"] tbody tr').count(), 'The RAM tab should render only memory.');
		same('r1', await page.locator('[data-table="resources"] tbody tr').first().getAttribute('data-id'),
			'The RAM table should contain the memory finding.');
		same('RAM capacity evidence', await page.locator('[data-role="resources-title"]').textContent(),
			'The shared resource panel should use the RAM heading.');
		downloadPromise = page.waitForEvent('download');
		await page.locator('[data-role="export-csv"]').click();
		download = await downloadPromise;
		csv = fs.readFileSync(await download.path(), 'utf8');
		ok(download.suggestedFilename().startsWith('capacity-memory-'), 'The RAM export should have a memory-specific filename.');
		ok(csv.includes('"Memory"') && !csv.includes('"CPU"'), 'The RAM CSV should exclude CPU rows.');
		ok(csv.includes('Maintenance without data collection') && csv.includes('"Last accepted"'),
			'The RAM CSV should explain maintenance and last-accepted observation semantics.');
		ok(csv.includes('RAM value is last accepted because maintenance pauses collection.'),
			'The RAM CSV should include the current-state reason.');

		downloadPromise = page.waitForEvent('download');
		await page.locator('[data-role="export-html"]').click();
		download = await downloadPromise;
		const html = fs.readFileSync(await download.path(), 'utf8');
		ok(html.includes('Maintenance without data collection'),
			'The HTML export should identify maintenance without data collection.');
		ok(html.includes('Last accepted') && html.includes('not live current observations'),
			'The HTML export should explain that retained values are not live current observations.');

		await page.locator('[data-result-filter="type"]').selectOption('cpu');
		ok(await page.locator('[data-tab="cpu"]').evaluate((el) => el.classList.contains('is-active')),
			'Selecting CPU while RAM is open should route to the CPU tab.');
		await page.locator('[data-tab="disks"]').click();
		downloadPromise = page.waitForEvent('download');
		await page.locator('[data-role="export-csv"]').click();
		download = await downloadPromise;
		csv = fs.readFileSync(await download.path(), 'utf8');
		ok(download.suggestedFilename().startsWith('capacity-filesystems-'),
			'The filesystem export should have a filesystem-specific filename.');
		ok(csv.includes('Maintenance without data collection') && csv.includes('"Last accepted"'),
			'The filesystem CSV should explain maintenance and last-accepted observation semantics.');
		ok(csv.includes('Current observation withheld during maintenance; last accepted value retained.'),
			'The filesystem CSV should include the current-state explanation.');
		ok(csv.includes('Maintenance with data collection') && csv.includes('current observations continue normally.'),
			'The filesystem CSV should preserve normal semantics for maintenance with collection.');

		const row = page.locator('[data-table="disks"] tbody tr[data-id="d0"]');
		await row.scrollIntoViewIfNeeded();
		const scrollBefore = await page.evaluate(() => window.scrollY);
		// Several short-lived Settings pages ran earlier; foreground the primary
		// report immediately before asserting the browser's active element.
		await page.bringToFront();
		await row.click();
		await page.waitForSelector('[data-role="detail-modal"].is-open');
		ok(await page.locator('[data-role="detail-stats"] .cap-detail-maintenance').isVisible(),
			'The filesystem modal should show the compact maintenance explanation.');
		const diskMaintenanceText = await page.locator('[data-role="detail-stats"]').textContent();
		ok(diskMaintenanceText.includes('Maintenance without data collection')
			&& diskMaintenanceText.includes('Last accepted')
			&& diskMaintenanceText.includes('not live current observations'),
			'The filesystem modal should clearly distinguish retained values from live observations.');
		ok(diskMaintenanceText.includes('96.00% used'),
			'The detail view should retain two percentage decimals near an exact threshold.');
		const diskSubtitleText = await page.locator('[data-role="detail-subtitle"]').textContent();
		ok(diskSubtitleText.includes('used-capacity window 3 months (60 days, 100% coverage, linear-fit R² 0.980)')
			&& diskSubtitleText.includes('used-percentage window 3 months (60 days, 100% coverage, linear-fit R² 0.970)'),
			'The modal should identify both byte and percentage linear-fit R² values without conflating them.');
		same('false', await page.locator('[data-role="detail-modal"]').getAttribute('aria-hidden'),
			'The open modal should be exposed to assistive technology.');
		await page.waitForFunction(() => document.activeElement
			&& document.activeElement.getAttribute('data-role') === 'detail-close');
		ok(await page.locator('[data-role="detail-close"]').evaluate((el) => el === document.activeElement),
			'Focus should move into the modal.');
		same(scrollBefore, await page.evaluate(() => window.scrollY), 'Opening details should not scroll the report page.');
		ok(await page.locator('body').evaluate((body) => body.classList.contains('cap-modal-open')),
			'Opening details should lock background scrolling.');
		await page.keyboard.press('Shift+Tab');
		ok(await page.locator('[data-role="detail-surface"]').evaluate((el) => el === document.activeElement),
			'Shift+Tab should wrap focus to the last modal control.');
		await page.keyboard.press('Tab');
		ok(await page.locator('[data-role="detail-close"]').evaluate((el) => el === document.activeElement),
			'Tab should wrap focus back to the close control.');
		let modalBounds = await page.locator('.cap-modal-container').boundingBox();
		ok(modalBounds.x >= 0 && modalBounds.y >= 0 && modalBounds.x + modalBounds.width <= 1280
			&& modalBounds.y + modalBounds.height <= 760, 'The desktop modal should stay inside the viewport.');
		await page.setViewportSize({width: 360, height: 740});
		modalBounds = await page.locator('.cap-modal-container').boundingBox();
		ok(modalBounds.x >= 0 && modalBounds.y >= 0 && modalBounds.x + modalBounds.width <= 360
			&& modalBounds.y + modalBounds.height <= 740, 'The narrow modal should stay inside the viewport.');
		await page.setViewportSize({width: 1280, height: 760});

		const chart = page.locator('[data-role="detail-surface"] svg');
		await chart.waitFor();
		same('end', await chart.locator('text[data-axis-edge="right"]').getAttribute('text-anchor'),
			'The rightmost rotated date should anchor inward instead of clipping past the SVG edge.');
		ok(await chart.evaluate((svg) => {
			const tick = svg.querySelector('text[data-axis-edge="right"]');
			if (!tick) { return false; }
			const svgRect = svg.getBoundingClientRect();
			const tickRect = tick.getBoundingClientRect();
			return tickRect.right <= svgRect.right + 1 && tickRect.left >= svgRect.left - 1;
		}), 'The rightmost date label should remain inside the rendered SVG bounds.');
		const drag = await chart.evaluate((svg) => {
			const rect = svg.getBoundingClientRect();
			const vb = svg.viewBox.baseVal;
			const client = (x, y) => ({x: rect.left + x / vb.width * rect.width, y: rect.top + y / vb.height * rect.height});
			return {from: client(62, 100), to: client(145, 100)};
		});
		await page.mouse.move(drag.from.x, drag.from.y);
		await page.mouse.down();
		await page.mouse.move(drag.to.x, drag.to.y, {steps: 8});
		await page.mouse.up();
		await page.waitForFunction(() => !document.querySelector('[data-role="detail-zoom-label"]').hidden);
		ok((await page.locator('[data-role="detail-zoom-label"]').textContent()).includes('Selected chart range'),
			'Dragging across history should select and label a smaller chart range.');
		await page.locator('[data-role="detail-zoom-reset"]').click();
		ok(await page.locator('[data-role="detail-zoom-reset"]').isHidden(), 'Reset zoom should restore the complete chart.');

		await page.keyboard.press('Escape');
		await page.waitForFunction(() => !document.querySelector('[data-role="detail-modal"]').classList.contains('is-open'));
		ok(!await page.locator('body').evaluate((body) => body.classList.contains('cap-modal-open')),
			'Closing should restore body scrolling.');
		same('d0', await page.evaluate(() => document.activeElement && document.activeElement.dataset.id),
			'Closing should restore focus to the activating row after the table rerenders.');
		await page.keyboard.press('Enter');
		await page.waitForSelector('[data-role="detail-modal"].is-open');
		await page.locator('[data-role="detail-modal"] .cap-modal-overlay').click({position: {x: 5, y: 5}});
		await page.waitForFunction(() => !document.querySelector('[data-role="detail-modal"]').classList.contains('is-open'));

		await page.locator('[data-tab="overview"]').click();
		const overviewRow = page.locator('[data-role="top-risks"] tr[data-kind="disk"][data-id="d0"]');
		await overviewRow.focus();
		await overviewRow.press('Enter');
		await page.waitForSelector('[data-role="detail-modal"].is-open');
		await page.keyboard.press('Escape');
		await page.waitForFunction(() => !document.querySelector('[data-role="detail-modal"]').classList.contains('is-open'));
		ok(await page.evaluate(() => document.activeElement
			&& document.activeElement.matches('[data-table="disks"] tbody tr[data-id="d0"]')
			&& document.activeElement.getClientRects().length > 0),
			'Closing an Overview-opened modal should restore focus to the visible resource row.');

		await page.locator('[data-tab="overview"]').click();
		await page.locator('[data-result-filter="type"]').selectOption('memory');
		const memoryRisk = page.locator('[data-role="top-risks"] tr[data-kind="res"][data-id="r1"]');
		await memoryRisk.focus();
		await memoryRisk.press('Enter');
		await page.waitForSelector('[data-role="detail-modal"].is-open');
		ok(await page.locator('[data-tab="memory"]').evaluate((el) => el.classList.contains('is-active')),
			'A memory top-risk row should route to the RAM tab.');
		same('RAM capacity evidence', await page.locator('[data-role="detail-eyebrow"]').textContent(),
			'The memory detail modal should identify RAM evidence.');
		ok(await page.locator('[data-role="detail-stats"] .cap-detail-maintenance').isVisible(),
			'The RAM modal should show the compact maintenance explanation.');
		const ramMaintenanceText = await page.locator('[data-role="detail-stats"]').textContent();
		ok(ramMaintenanceText.includes('Maintenance without data collection')
			&& ramMaintenanceText.includes('Last accepted')
			&& ramMaintenanceText.includes('not live current observations'),
			'The RAM modal should clearly distinguish retained values from live observations.');
		await page.keyboard.press('Escape');
		await page.waitForFunction(() => !document.querySelector('[data-role="detail-modal"]').classList.contains('is-open'));
		ok(await page.evaluate(() => document.activeElement
			&& document.activeElement.matches('[data-table="resources"] tbody tr[data-id="r1"]')
			&& document.activeElement.getClientRects().length > 0),
			'Closing a memory top-risk modal should restore focus to the RAM row.');

		// ---- pattern-aware "possible path" line ---------------------------------
		// The projection chart may add a second display-only line only when the
		// history shows a statistically confirmed repeating pattern; a linear
		// history must keep exactly the single straight projection line.
		const patternPage = await browser.newPage({viewport: {width: 1280, height: 760}});
		patternPage.on('pageerror', (error) => browserErrors.push(`pattern: ${error.message}`));
		patternPage.on('console', (message) => {
			if (message.type() === 'error') { browserErrors.push(`pattern: ${message.text()}`); }
		});
		const patternData = fixture();
		const patternLastClock = patternData.forecast.series[patternData.forecast.series.length - 1][0];
		patternData.inventory.disks = [{
			...patternData.inventory.disks[0],
			hostid: '3', host: 'dev01', status: 'OK', expected_gap: false,
			current_observation_usable: true, current_reasons: [],
			maintenance: {active: false, type: 'none', id: null, since: null},
			used: 940, free: 60, pused: 94, usable: 1000, total: 1000,
			warn_pct: {v: 95, src: 'fixture', fb: false},
			crit_pct: {v: 99, src: 'fixture', fb: false},
			warn_free: {v: 40, src: 'fixture', fb: false},
			crit_free: {v: 20, src: 'fixture', fb: false}
		}];
		patternData.inventory.resources = [];
		patternData.forecast.growth_pct_day = 0.05;
		patternData.forecast.eta = {
			warn_days: 5, warn_date: null, warn_basis: 'free GB',
			crit_days: 12, crit_date: null, crit_basis: 'free GB',
			full_days: 20, full_date: null, next_days: 5, next_date: null, next_basis: 'warning free GB',
			warn_pct_days: 20, warn_pct_date: null, warn_free_days: 5, warn_free_date: null,
			crit_pct_days: 100, crit_pct_date: null, crit_free_days: 12, crit_free_date: null
		};
		await patternPage.addInitScript((payload) => {
			window.__capacityFixture = payload;
			window.fetch = async (_url, options = {}) => {
				const params = new URLSearchParams(options.body || '');
				const mode = params.get('mode') || '';
				let body;
				if (mode === 'inventory') { body = window.__capacityFixture.inventory; }
				else if (mode === 'forecast') { body = {build_id: window.__capacityFixture.inventory.build_id,
					forecasts: [window.__capacityFixture.forecast]}; }
				else { body = {build_id: window.__capacityFixture.inventory.build_id,
					groupids: [], hostids: [], empty: false}; }
				return new Response(JSON.stringify(body), {status: 200, headers: {'Content-Type': 'application/json'}});
			};
		}, patternData);
		await patternPage.route('http://pattern.test/**', (route) => route.fulfill({
			status: 200, contentType: 'text/html', body: appDocument('disks')
		}));
		await patternPage.goto('http://pattern.test/zabbix.php?action=capacity.planning.view&tab=disks');
		await patternPage.addStyleTag({path: cssAsset});
		await patternPage.addScriptTag({path: jsAsset});
		await patternPage.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
		await patternPage.waitForSelector('[data-table="disks"] tbody tr[data-id="d0"]');
		await patternPage.waitForFunction(() => document.querySelector('[data-role="loading"]').hidden);
		await patternPage.locator('[data-table="disks"] tbody tr[data-id="d0"]').click();
		await patternPage.waitForSelector('[data-role="detail-modal"].is-open');
		const patternChart = patternPage.locator('[data-role="detail-surface"] svg');
		await patternChart.waitFor();
		ok(await patternChart.evaluate((svg) => !!svg.querySelector('line[stroke-dasharray="6 5"]')),
			'A projected linear history should draw the straight projection line.');
		ok(await patternChart.evaluate((svg) => !svg.querySelector('polyline[stroke-dasharray="2 4"]')),
			'A linear history must not grow a fabricated pattern line.');
		ok(!(await patternPage.locator('[data-role="detail-legend"]').textContent()).includes('Possible path'),
			'The possible-path legend entry requires a confirmed pattern.');
		same('0', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-detected'),
			'Linear history should not pass the statistical detector.');
		const baselineChartState = await patternChart.evaluate((svg) => ({
			projection: Object.fromEntries(['x1', 'y1', 'x2', 'y2'].map((name) =>
				[name, svg.querySelector('[data-role="authoritative-projection"]').getAttribute(name)])),
			markers: [...svg.querySelectorAll('[data-threshold-marker]')].map((marker) => ({
				id: marker.getAttribute('data-threshold-marker'),
				basis: marker.getAttribute('data-threshold-basis'),
				days: marker.getAttribute('data-marker-days'),
				cx: marker.getAttribute('cx'), cy: marker.getAttribute('cy')
			})).sort((a, b) => a.id.localeCompare(b.id))
		}));
		same(4, baselineChartState.markers.length,
			'Each configured percentage/free-space threshold should get its own marker.');
		const markerById = Object.fromEntries(baselineChartState.markers.map((marker) => [marker.id, marker]));
		same('20', markerById['warn-pct'].days, 'The percentage warning marker should use its percentage ETA.');
		same('5', markerById['warn-free'].days, 'The free-space warning marker should use its byte-model ETA.');
		same('used % model', markerById['crit-pct'].basis,
			'The percentage marker should expose its own model basis.');
		same('absolute free-space model', markerById['crit-free'].basis,
			'The converted free-space marker should expose its independent byte-model basis.');
		ok(Number(markerById['warn-free'].cx) < Number(markerById['warn-pct'].cx),
			'A sooner absolute-free ETA must plot before a later percentage ETA even at nearby thresholds.');
		const baselineStatsText = await patternPage.locator('[data-role="detail-stats"]').textContent();

		const closePatternModal = async () => {
			await patternPage.keyboard.press('Escape');
			await patternPage.waitForFunction(() =>
				!document.querySelector('[data-role="detail-modal"]').classList.contains('is-open'));
		};
		const reopenPatternModal = async () => {
			await patternPage.locator('[data-role="reload"]').click();
			await patternPage.waitForFunction(() => document.querySelector('[data-role="loading"]').hidden);
			await patternPage.locator('[data-table="disks"] tbody tr[data-id="d0"]').click();
			await patternPage.waitForSelector('[data-role="detail-modal"].is-open');
			await patternChart.waitFor();
		};
		const loadPatternCase = async (kind) => {
			await closePatternModal();
			await patternPage.evaluate(({caseName, lastClock}) => {
				const point = (day, average, band = 12) =>
					[lastClock - day * 86400, average - band, average, average + band];
				const series = [];
				if (caseName === 'two-cycle') {
					for (let day = 91; day >= 0; day--) {
						const index = 91 - day;
						const inCleanup = (index >= 10 && index <= 12) || (index >= 62 && index <= 64);
						series.push(point(day, 650 + index * 3 - (inCleanup ? 60 : 0)));
					}
				}
				else if (caseName === 'noise') {
					let seed = 0x5f3759df;
					for (let day = 91; day >= 0; day--) {
						seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
						const index = 91 - day;
						series.push(point(day, 650 + index * 3 + (seed / 4294967296 - 0.5) * 60));
					}
				}
				else if (caseName === 'tiny-weekly') {
					for (let day = 60; day >= 0; day--) {
						const index = 60 - day;
						series.push(point(day, 760 + index * 3 + ((index % 7) / 7 - 0.5) * 0.5, 0.05));
					}
				}
				else if (caseName === 'long-period') {
					for (let day = 182; day >= 0; day--) {
						const index = 182 - day;
						const phase = index % 52;
						series.push(point(day, 390 + index * 3 - (phase >= 10 && phase <= 12 ? 60 : 0)));
					}
				}
				else {
					for (let day = 60; day >= 0; day--) {
						const index = 60 - day;
						series.push(point(day, 760 + index * 3 + ((index % 7) / 7 - 0.5) * 60));
					}
				}
				window.__capacityFixture.forecast.series = series;
			}, {caseName: kind, lastClock: patternLastClock});
			await reopenPatternModal();
		};

		// Explicit null candidate ETAs are authoritative "no projection" results;
		// they must never fall back to the displayed percentage slope. Exercise all
		// four bases at once, then keep the same payload to prove that a tiny but
		// nonzero full-precision trend is still drawn rather than rounded to zero.
		await closePatternModal();
		await patternPage.evaluate(() => {
			const forecast = window.__capacityFixture.forecast;
			forecast.growth_pct_day = 0.0000049;
			forecast.eta.warn_pct_days = null;
			forecast.eta.warn_free_days = null;
			forecast.eta.crit_pct_days = null;
			forecast.eta.crit_free_days = null;
		});
		await reopenPatternModal();
		same(0, await patternChart.locator('[data-threshold-marker]').count(),
			'Explicit null ETA candidates must suppress all legacy slope-derived threshold markers.');
		const tinyProjection = await patternChart.locator('[data-role="authoritative-projection"]')
			.evaluate((line) => ({y1: Number(line.getAttribute('y1')), y2: Number(line.getAttribute('y2'))}));
		ok(tinyProjection.y1 !== tinyProjection.y2,
			'A tiny nonzero full-precision percentage slope must remain a non-flat authoritative projection.');
		ok((await patternPage.locator('[data-role="detail-stats"]').textContent())
			.includes('+0.0000049 pp/day'),
			'A tiny qualified slope must remain visibly nonzero beside its finite ETA.');
		await closePatternModal();
		const tinyDownloadPromise = patternPage.waitForEvent('download');
		await patternPage.locator('[data-role="export-csv"]').click();
		const tinyDownload = await tinyDownloadPromise;
		const tinyCsv = fs.readFileSync(await tinyDownload.path(), 'utf8');
		ok(tinyCsv.includes('"0.0000049"'),
			'A tiny qualified percentage slope must retain its precision in the filesystem CSV.');
		await reopenPatternModal();
		await patternPage.evaluate(() => {
			const forecast = window.__capacityFixture.forecast;
			forecast.growth_pct_day = 0.05;
			forecast.eta.warn_pct_days = 20;
			forecast.eta.warn_free_days = 5;
			forecast.eta.crit_pct_days = 100;
			forecast.eta.crit_free_days = 12;
		});

		await loadPatternCase('two-cycle');
		same('0', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-detected'),
			'Two long cleanup cycles must not satisfy the three-cycle evidence gate.');
		ok(!await patternChart.locator('[data-role="possible-path"]').count(),
			'Two cleanup events must leave the chart on its authoritative straight projection only.');

		await loadPatternCase('noise');
		same('0', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-detected'),
			'Deterministic non-recurring noise must fail the pattern detector.');

		await loadPatternCase('tiny-weekly');
		same('1', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-detected'),
			'A real but sub-pixel weekly rhythm should pass statistical detection.');
		same('0', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-drawn'),
			'A detected rhythm below the three-pixel visibility floor must not be drawn as noise.');

		await loadPatternCase('long-period');
		same('1', await patternPage.locator('[data-role="detail-surface"]').getAttribute('data-pattern-detected'),
			'A 6-month history with more than three stable 52-day cleanup cycles should be detected.');
		ok(!!await patternChart.locator('[data-role="possible-path"]').count(),
			'The confirmed long-period cleanup rhythm should draw a possible path.');

		// Return to the original 61-day horizon with a weekly sawtooth: the added
		// visual line must not mutate printed ETAs or any authoritative marker.
		await loadPatternCase('weekly');
		ok(await patternChart.evaluate((svg) => !!svg.querySelector('line[stroke-dasharray="6 5"]')),
			'The straight projection stays authoritative when a pattern is drawn.');
		ok(await patternChart.evaluate((svg) => !!svg.querySelector('[data-role="possible-path"]')),
			'A confirmed weekly sawtooth should add the display-only possible-path line.');
		ok((await patternPage.locator('[data-role="detail-legend"]').textContent()).includes('Possible path (history pattern)'),
			'The pattern line should be labelled in the legend.');
		ok(await patternChart.evaluate((svg) => {
			const line = svg.querySelector('[data-role="possible-path"]');
			const anchor = svg.querySelector('[data-role="projection-anchor"]');
			const first = line.getAttribute('points').trim().split(' ')[0].split(',').map(Number);
			return Math.abs(first[0] - Number(anchor.getAttribute('cx'))) < 1
				&& Math.abs(first[1] - Number(anchor.getAttribute('cy'))) < 1;
		}), 'The possible-path line must start exactly at the projection anchor.');
		ok((await patternChart.locator('[data-role="possible-path"] title').textContent())
			.includes('zero-mean recurring-history template with a short anchor transition'),
			'The possible-path wording should distinguish the zero-mean template from its anchor seam.');
		const patternedChartState = await patternChart.evaluate((svg) => ({
			projection: Object.fromEntries(['x1', 'y1', 'x2', 'y2'].map((name) =>
				[name, svg.querySelector('[data-role="authoritative-projection"]').getAttribute(name)])),
			markers: [...svg.querySelectorAll('[data-threshold-marker]')].map((marker) => ({
				id: marker.getAttribute('data-threshold-marker'),
				basis: marker.getAttribute('data-threshold-basis'),
				days: marker.getAttribute('data-marker-days'),
				cx: marker.getAttribute('cx'), cy: marker.getAttribute('cy')
			})).sort((a, b) => a.id.localeCompare(b.id))
		}));
		same(JSON.stringify(baselineChartState), JSON.stringify(patternedChartState),
			'The possible path must not move the straight projection or basis-specific threshold markers.');
		same(baselineStatsText, await patternPage.locator('[data-role="detail-stats"]').textContent(),
			'The possible path must not alter any printed ETA or assessment text.');
		await patternPage.close();

		await page.locator('[data-role="custom-lookback-toggle"]').click();
		await page.locator('#cap-custom-lookback-days').fill('45');
		await page.locator('[data-role="apply-custom-lookback"]').click();
		await page.waitForFunction(() => new URL(window.location.href).searchParams.get('lookback') === '45');
		ok(await page.locator('[data-role="custom-lookback-toggle"]').evaluate((el) => el.classList.contains('is-active')),
			'A non-preset custom range should mark Custom as active.');
		await page.waitForFunction(() => window.__capacityForecastDays.includes(45));
		ok(await page.evaluate(() => window.__capacityForecastDays.includes(45)),
			'The custom lookback should request exactly 45 days of forecast history.');
		same(1, await page.evaluate(() => window.__capacityRequests.filter((mode) => mode === 'inventory').length),
			'Changing only the analysis range should reuse inventory.');
		same(0, browserErrors.length, `The browser should not report JavaScript errors: ${browserErrors.join(' | ')}`);

		console.log(`CapacityPlanningUiTest: ${assertions} assertions passed.`);
	}
	finally {
		await browser.close();
	}
}

run().catch((error) => {
	console.error(error && error.stack ? error.stack : error);
	process.exitCode = 1;
});
