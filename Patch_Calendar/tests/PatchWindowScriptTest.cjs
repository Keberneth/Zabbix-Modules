/*
 * Tests for the Script item JavaScript (template/patch_window.js).
 *
 * 1. Parity: the script's window expansion must produce exactly the windows that MaintenanceSchedule.php produces
 *    (which is itself checked against a port of the Zabbix server code) for every fixture and time zone.
 * 2. Behaviour: the whole script runs against a stubbed Zabbix API and must report the right running and next
 *    window, "days until" (0 = today, 1 = tomorrow), name filters and errors.
 *
 * Run: php tests/export_windows.php > /tmp/windows.json && node tests/PatchWindowScriptTest.cjs /tmp/windows.json
 */
'use strict';

const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'template', 'patch_window.js'), 'utf8');
const main_marker = 'if (!isMacroSet(params.url)) {';

if (!source.includes(main_marker)) {
	throw new Error('Main section marker not found in patch_window.js');
}

const helpers = new Function('value', source.slice(0, source.indexOf(main_marker))
	+ '\nreturn {getWindows: getWindows};')('{}');

let failures = 0;

function check(label, actual, expected) {
	const ok = JSON.stringify(actual) === JSON.stringify(expected);

	console.log(`${ok ? 'OK  ' : 'FAIL'}  ${label}`);

	if (!ok) {
		console.log(`      expected: ${JSON.stringify(expected)}\n      actual:   ${JSON.stringify(actual)}`);
		failures++;
	}
}

function setTimezone(timezone) {
	process.env.TZ = timezone;
}

function merge(windows) {
	const merged = [];

	windows
		.slice()
		.sort((a, b) => (a.start - b.start) || (a.end - b.end))
		.forEach((window) => {
			const last = merged[merged.length - 1];

			if (last !== undefined && window.start < last.end) {
				last.end = Math.max(last.end, window.end);
			}
			else {
				merged.push({start: window.start, end: window.end});
			}
		});

	return merged;
}

// ---------------------------------------------------------------------------------------------------------------
console.log('Template');

const yaml = fs.readFileSync(path.join(__dirname, '..', 'template', 'Patch window by Zabbix API.yaml'), 'utf8');
const embedded = yaml.match(/\n {10}params: \|-\n((?: {12}.*\n|\n)+)/);

check('Template YAML embeds template/patch_window.js unchanged',
	embedded !== null
		&& embedded[1].split('\n').map((line) => line.slice(12)).join('\n').trimEnd() === source.trimEnd(),
	true
);

// ---------------------------------------------------------------------------------------------------------------
console.log('\nParity with MaintenanceSchedule.php');

const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const per_timezone = {};

for (const test_case of cases) {
	setTimezone(test_case.timezone);

	const actual = merge(helpers.getWindows(test_case.maintenance, test_case.from, test_case.till));
	const result = per_timezone[test_case.timezone] ??= {cases: 0, windows: 0, mismatches: []};

	result.cases++;
	result.windows += test_case.windows.length;

	if (JSON.stringify(actual) !== JSON.stringify(test_case.windows)) {
		result.mismatches.push(`${test_case.label} [${test_case.from}, ${test_case.till}):\n`
			+ `        php: ${JSON.stringify(test_case.windows)}\n        js:  ${JSON.stringify(actual)}`);
	}
}

for (const [timezone, result] of Object.entries(per_timezone)) {
	console.log(`${result.mismatches.length ? 'FAIL' : 'OK  '}  ${timezone.padEnd(18)} ${result.cases} cases, `
		+ `${result.windows} windows, ${result.mismatches.length} mismatches`);
	result.mismatches.slice(0, 5).forEach((mismatch) => console.log(`      ${mismatch}`));
	failures += result.mismatches.length > 0 ? 1 : 0;
}

// ---------------------------------------------------------------------------------------------------------------
console.log('\nScript behaviour against a stubbed API');

function local(text) {
	// "YYYY-MM-DD HH:MM" in the current process time zone.
	const [date, time = '00:00'] = text.split(' ');
	const [y, m, d] = date.split('-').map(Number);
	const [hh, mm] = time.split(':').map(Number);

	return Math.floor(new Date(y, m - 1, d, hh, mm).getTime() / 1000);
}

function runScript({now, params = {}, maintenances = [], hosts = null, status = 200, api_error = null}) {
	const requests = [];

	class HttpRequest {
		addHeader(header) {
			this.headers = (this.headers || []).concat(header);
		}

		post(url, body) {
			const request = JSON.parse(body);

			requests.push({url, method: request.method, params: request.params, headers: this.headers});

			if (api_error !== null) {
				return JSON.stringify({jsonrpc: '2.0', error: api_error, id: 1});
			}

			const result = request.method === 'host.get'
				? (hosts ?? [{hostid: '100', hostgroups: [{groupid: '10'}, {groupid: '11'}]}])
				: maintenances;

			return JSON.stringify({jsonrpc: '2.0', result, id: 1});
		}

		getStatus() {
			return status;
		}
	}

	const real_now = Date.now;
	Date.now = () => now * 1000;

	try {
		return {
			output: JSON.parse(new Function('value', 'HttpRequest', source)(JSON.stringify({
				url: 'http://zabbix-web:8080/api_jsonrpc.php',
				token: 'secret-token',
				host: 'web-fe01',
				name_matches: '.*',
				name_not_matches: 'CHANGE_IF_NEEDED',
				lookahead_days: '60',
				...params
			}), HttpRequest)),
			requests
		};
	}
	catch (error) {
		return {error: String(error), requests};
	}
	finally {
		Date.now = real_now;
	}
}

function fixtureMaintenances() {
	return [
		{
			maintenanceid: '9', name: 'Weekly patch window - Web Farm', maintenance_type: '0',
			active_since: String(local('2025-06-01')), active_till: String(local('2027-12-31')),
			hosts: [], hostgroups: [{groupid: '10'}],
			timeperiods: [{timeperiod_type: '3', every: '1', month: '0', dayofweek: '64', day: '0',
				start_time: '7200', period: '7200', start_date: '0'}]
		},
		{
			maintenanceid: '20', name: 'Other host only', maintenance_type: '0',
			active_since: String(local('2025-06-01')), active_till: String(local('2027-12-31')),
			hosts: [{hostid: '101'}], hostgroups: [],
			timeperiods: [{timeperiod_type: '2', every: '1', month: '0', dayofweek: '0', day: '0',
				start_time: '3600', period: '3600', start_date: '0'}]
		},
		{
			maintenanceid: '12', name: 'Exchange CU install', maintenance_type: '1',
			active_since: String(local('2026-10-20 05:00')), active_till: String(local('2026-10-20 07:00')),
			hosts: [{hostid: '100'}], hostgroups: [],
			timeperiods: [{timeperiod_type: '0', every: '1', month: '0', dayofweek: '0', day: '0',
				start_time: '0', period: '7200', start_date: String(local('2026-10-20 05:00'))}]
		}
	];
}

function summary(result) {
	if (result.error !== undefined) {
		return result.error;
	}

	const o = result.output;
	const fmt = (clock) => {
		if (clock === 0) {
			return '-';
		}

		const d = new Date(clock * 1000);
		const pad = (n) => String(n).padStart(2, '0');

		return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:`
			+ pad(d.getMinutes());
	};

	return `active=${o.active} until ${fmt(o.active_end)} | next "${o.next_name}" ${fmt(o.next_start)}`
		+ `..${fmt(o.next_end)} days=${o.next_days} type=${o.next_type} | maintenances=${o.maintenances}`;
}

for (const timezone of ['UTC', 'Europe/Stockholm', 'America/New_York']) {
	setTimezone(timezone);
	console.log(`  ${timezone}`);

	const maintenances = fixtureMaintenances();

	check('  Saturday before a Sunday window: tomorrow',
		summary(runScript({now: local('2026-10-10 10:00'), maintenances})),
		'active=0 until - | next "Weekly patch window - Web Farm" 2026-10-11 02:00..2026-10-11 04:00 days=1 '
			+ 'type=0 | maintenances=2'
	);

	check('  late Saturday evening: still tomorrow',
		summary(runScript({now: local('2026-10-10 23:59'), maintenances})),
		'active=0 until - | next "Weekly patch window - Web Farm" 2026-10-11 02:00..2026-10-11 04:00 days=1 '
			+ 'type=0 | maintenances=2'
	);

	check('  Sunday 01:00, window at 02:00: today',
		summary(runScript({now: local('2026-10-11 01:00'), maintenances})),
		'active=0 until - | next "Weekly patch window - Web Farm" 2026-10-11 02:00..2026-10-11 04:00 days=0 '
			+ 'type=0 | maintenances=2'
	);

	check('  inside the window: running, next one in 7 days',
		summary(runScript({now: local('2026-10-11 03:00'), maintenances})),
		'active=1 until 2026-10-11 04:00 | next "Weekly patch window - Web Farm" 2026-10-18 02:00..2026-10-18 04:00 '
			+ 'days=7 type=0 | maintenances=2'
	);

	check('  host maintenance before the next group window is picked first',
		summary(runScript({now: local('2026-10-19 12:00'), maintenances})),
		'active=0 until - | next "Exchange CU install" 2026-10-20 05:00..2026-10-20 07:00 days=1 type=1 '
			+ '| maintenances=2'
	);

	check('  {$PATCH.WINDOW.NAME.MATCHES} = patch (case-insensitive)',
		summary(runScript({now: local('2026-10-12 12:00'), maintenances, params: {name_matches: 'patch'}})),
		'active=0 until - | next "Weekly patch window - Web Farm" 2026-10-18 02:00..2026-10-18 04:00 days=6 '
			+ 'type=0 | maintenances=1'
	);

	check('  {$PATCH.WINDOW.NAME.NOT_MATCHES} = ^weekly',
		summary(runScript({now: local('2026-10-10 10:00'), maintenances, params: {name_not_matches: '^weekly'}})),
		'active=0 until - | next "Exchange CU install" 2026-10-20 05:00..2026-10-20 07:00 days=10 type=1 '
			+ '| maintenances=1'
	);

	check('  empty filter macros mean "all maintenances"',
		summary(runScript({now: local('2026-10-10 10:00'), maintenances,
			params: {name_matches: '', name_not_matches: ''}
		})),
		'active=0 until - | next "Weekly patch window - Web Farm" 2026-10-11 02:00..2026-10-11 04:00 days=1 '
			+ 'type=0 | maintenances=2'
	);

	const back_to_back = fixtureMaintenances().concat([{
		maintenanceid: '30', name: 'Follow-up window', maintenance_type: '0',
		active_since: String(local('2026-10-01')), active_till: String(local('2026-11-01')),
		hosts: [{hostid: '100'}], hostgroups: [],
		timeperiods: [{timeperiod_type: '0', every: '1', month: '0', dayofweek: '0', day: '0', start_time: '0',
			period: '3600', start_date: String(local('2026-10-11 04:00'))}]
	}]);

	check('  back-to-back window extends the running one instead of raising a reminder',
		summary(runScript({now: local('2026-10-11 03:00'), maintenances: back_to_back})),
		'active=1 until 2026-10-11 05:00 | next "Weekly patch window - Web Farm" 2026-10-18 02:00..2026-10-18 04:00 '
			+ 'days=7 type=0 | maintenances=3'
	);

	check('  nothing within the lookahead',
		summary(runScript({now: local('2026-10-10 10:00'), maintenances: [maintenances[2]],
			params: {lookahead_days: '5'}
		})),
		'active=0 until - | next "" -..- days=-1 type=-1 | maintenances=1'
	);
}

setTimezone('UTC');

const run = runScript({now: local('2026-10-10 10:00'), maintenances: fixtureMaintenances()});
check('API calls: host.get by technical name, then maintenance.get by the host groups',
	run.requests.map((r) => [r.method, r.method === 'host.get' ? r.params.filter : r.params.groupids]),
	[['host.get', {host: ['web-fe01']}], ['maintenance.get', ['10', '11']]]
);
check('API token is sent as Bearer header',
	run.requests[0].headers.includes('Authorization: Bearer secret-token'), true
);
check('unresolved URL macro is reported',
	runScript({now: 0, params: {url: '{$PATCH.WINDOW.API.URL}'}}).error,
	'Patch window: set the global macro {$PATCH.WINDOW.API.URL} to the Zabbix API URL, for example '
		+ 'https://zabbix.example.com/api_jsonrpc.php'
);
check('unresolved token macro is reported',
	runScript({now: 0, params: {token: '{$PATCH.WINDOW.API.TOKEN}'}}).error,
	'Patch window: set the global secret macro {$PATCH.WINDOW.API.TOKEN} to an API token'
);
check('host not visible to the token user is reported',
	runScript({now: 0, hosts: []}).error,
	'Patch window: host "web-fe01" is not visible to the user of the API token'
);
check('API error is reported',
	runScript({now: 0, api_error: {code: -32602, message: 'Invalid params.', data: 'Not authorized.'}}).error,
	'Patch window: host.get failed: Invalid params. Not authorized.'
);
check('HTTP error is reported',
	runScript({now: 0, status: 500}).error,
	'Patch window: host.get returned HTTP 500 from http://zabbix-web:8080/api_jsonrpc.php'
);
check('invalid regular expression is reported',
	runScript({now: 0, params: {name_matches: '(patch'}}).error.startsWith(
		'Patch window: invalid regular expression "(patch"'
	),
	true
);

console.log(failures === 0 ? '\nALL TESTS PASSED' : `\n${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
