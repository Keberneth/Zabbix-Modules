/*
 * Patch window by Zabbix API - "Patch window: Get schedule" Script item.
 *
 * Reads the maintenances that cover this host (directly or through one of its host groups) from the Zabbix API
 * and returns the running and the next maintenance window as JSON for the dependent items.
 *
 * Recurring periods are expanded exactly like Zabbix server does it (same algorithm as
 * Patch_Calendar/includes/MaintenanceSchedule.php). The script runs on Zabbix server or proxy, so local time here
 * is the time zone the server evaluates maintenance periods in, and "today"/"tomorrow" use the server's calendar.
 *
 * Parameters: url, token, host, name_matches, name_not_matches, lookahead_days.
 */
var params = JSON.parse(value),
	SEC_PER_DAY = 86400,
	SEC_PER_WEEK = 604800,
	TYPE_ONETIME = 0,
	TYPE_DAILY = 2,
	TYPE_WEEKLY = 3,
	TYPE_MONTHLY = 4;

function fail(message) {
	throw 'Patch window: ' + message;
}

function isMacroSet(macro_value) {
	return typeof macro_value === 'string' && macro_value !== '' && !/^\{\$[^}]+\}$/.test(macro_value);
}

function api(method, method_params) {
	var request = new HttpRequest(),
		response;

	request.addHeader('Content-Type: application/json-rpc');
	request.addHeader('Authorization: Bearer ' + params.token);

	response = request.post(params.url,
		JSON.stringify({jsonrpc: '2.0', method: method, params: method_params, id: 1})
	);

	if (request.getStatus() !== 200) {
		fail(method + ' returned HTTP ' + request.getStatus() + ' from ' + params.url);
	}

	try {
		response = JSON.parse(response);
	}
	catch (error) {
		fail(method + ' did not return JSON: ' + String(response).substring(0, 200));
	}

	if (typeof response.error !== 'undefined') {
		fail(method + ' failed: ' + response.error.message + ' ' + response.error.data);
	}

	return response.result;
}

/*
 * Calendar helpers. A day number is the local calendar date as days since 1970-01-01.
 */
function getOffset(clock) {
	return -new Date(clock * 1000).getTimezoneOffset() * 60;
}

function getDayNumber(clock) {
	var date = new Date(clock * 1000);

	return Math.floor(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / 86400000);
}

function getDate(day_number) {
	var date = new Date(day_number * 86400000);

	return {
		year: date.getUTCFullYear(),
		month: date.getUTCMonth() + 1,
		day: date.getUTCDate(),
		days_in_month: new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate()
	};
}

// 0 = Monday ... 6 = Sunday (bit number in the "dayofweek" mask).
function getWeekday(day_number) {
	return ((day_number + 3) % 7 + 7) % 7;
}

// Midnight plus the seconds, corrected by the change of the UTC offset (server's dc_subtract_time()).
// Returns null in strict mode if the wall clock time does not exist on that day (DST start).
function getLocalTime(day_number, seconds, strict) {
	var date = getDate(day_number),
		midnight = Math.floor(new Date(date.year, date.month - 1, date.day, 0, 0, 0).getTime() / 1000),
		clock = midnight + seconds,
		local;

	clock -= getOffset(clock) - getOffset(midnight);

	if (strict) {
		local = new Date(clock * 1000);

		if (local.getHours() * 3600 + local.getMinutes() * 60 + local.getSeconds() !== seconds) {
			return null;
		}
	}

	return clock;
}

function monthlyDayMatches(day_number, month, day, dayofweek, every) {
	var date = getDate(day_number);

	if ((month & (1 << (date.month - 1))) === 0) {
		return false;
	}

	if (day !== 0) {
		return date.day === day;
	}

	if ((dayofweek & (1 << getWeekday(day_number))) === 0) {
		return false;
	}

	return every === 5 ? date.day + 7 > date.days_in_month : Math.floor((date.day - 1) / 7) + 1 === every;
}

function getTimePeriodWindows(timeperiod, active_since, active_till, from, till) {
	var type = parseInt(timeperiod.timeperiod_type, 10),
		period = parseInt(timeperiod.period, 10),
		start_time = parseInt(timeperiod.start_time, 10),
		every = Math.max(1, parseInt(timeperiod.every, 10)),
		dayofweek = parseInt(timeperiod.dayofweek, 10),
		month = parseInt(timeperiod.month, 10),
		day = parseInt(timeperiod.day, 10),
		windows = [],
		since_day, since_midnight, since_monday, first_day, last_day, day_number, start, end;

	if (type === TYPE_ONETIME) {
		start = Math.max(parseInt(timeperiod.start_date, 10), active_since);
		end = Math.min(parseInt(timeperiod.start_date, 10) + period, active_till);

		return (start < end && start < till && end > from) ? [{start: start, end: end}] : [];
	}

	if (type !== TYPE_DAILY && type !== TYPE_WEEKLY && type !== TYPE_MONTHLY) {
		return [];
	}

	since_day = getDayNumber(active_since);
	since_midnight = getLocalTime(since_day, 0, false);
	since_monday = getLocalTime(since_day - getWeekday(since_day), 0, false);

	// A window that started before "from" can still be running.
	first_day = Math.max(since_day, getDayNumber(Math.max(from - period, active_since)) - 1);
	last_day = getDayNumber(Math.min(till, active_till));

	for (day_number = first_day; day_number <= last_day; day_number++) {
		if (type === TYPE_WEEKLY && (dayofweek & (1 << getWeekday(day_number))) === 0) {
			continue;
		}

		if (type === TYPE_MONTHLY && !monthlyDayMatches(day_number, month, day, dayofweek, every)) {
			continue;
		}

		start = getLocalTime(day_number, start_time, true);

		if (start === null || start < active_since || start >= active_till) {
			continue;
		}

		if (type === TYPE_DAILY && Math.floor((start - since_midnight) / SEC_PER_DAY) % every !== 0) {
			continue;
		}

		if (type === TYPE_WEEKLY && Math.floor((start - since_monday) / SEC_PER_WEEK) % every !== 0) {
			continue;
		}

		end = Math.min(start + period, active_till);

		if (start < till && end > from) {
			windows.push({start: start, end: end});
		}
	}

	return windows;
}

function getWindows(maintenance, from, till) {
	var active_since = parseInt(maintenance.active_since, 10),
		active_till = parseInt(maintenance.active_till, 10),
		windows = [],
		i;

	if (Math.max(from, active_since) >= Math.min(till, active_till)) {
		return [];
	}

	for (i = 0; i < maintenance.timeperiods.length; i++) {
		windows = windows.concat(
			getTimePeriodWindows(maintenance.timeperiods[i], active_since, active_till, from, till)
		);
	}

	return windows;
}

function coversHost(maintenance, hostid, groupids) {
	var i;

	for (i = 0; i < maintenance.hosts.length; i++) {
		if (maintenance.hosts[i].hostid === hostid) {
			return true;
		}
	}

	for (i = 0; i < maintenance.hostgroups.length; i++) {
		if (groupids.indexOf(maintenance.hostgroups[i].groupid) !== -1) {
			return true;
		}
	}

	return false;
}

function getRegExp(macro_value, default_pattern) {
	try {
		return new RegExp(isMacroSet(macro_value) ? macro_value : default_pattern, 'i');
	}
	catch (error) {
		fail('invalid regular expression "' + macro_value + '": ' + error);
	}
}

if (!isMacroSet(params.url)) {
	fail('set the global macro {$PATCH.WINDOW.API.URL} to the Zabbix API URL, '
		+ 'for example https://zabbix.example.com/api_jsonrpc.php');
}

if (!isMacroSet(params.token)) {
	fail('set the global secret macro {$PATCH.WINDOW.API.TOKEN} to an API token');
}

var now = Math.floor(Date.now() / 1000),
	lookahead_days = Math.min(Math.max(parseInt(params.lookahead_days, 10) || 60, 1), 366),
	till = now + lookahead_days * SEC_PER_DAY,
	name_matches = getRegExp(params.name_matches, '.*'),
	name_not_matches = getRegExp(params.name_not_matches, '(?!)'),
	hosts = api('host.get', {
		output: ['hostid'],
		selectHostGroups: ['groupid'],
		filter: {host: [params.host]}
	}),
	hostid, groupids = [], maintenances = [], windows = [], active = null, next = null, busy_until = now, count = 0,
	i, j, maintenance, window_list;

if (hosts.length === 0) {
	fail('host "' + params.host + '" is not visible to the user of the API token');
}

hostid = hosts[0].hostid;

for (i = 0; i < hosts[0].hostgroups.length; i++) {
	groupids.push(hosts[0].hostgroups[i].groupid);
}

// Maintenances of the host's groups, or of any host in them (the host itself included).
if (groupids.length > 0) {
	maintenances = api('maintenance.get', {
		output: ['maintenanceid', 'name', 'maintenance_type', 'active_since', 'active_till'],
		groupids: groupids,
		selectHosts: ['hostid'],
		selectHostGroups: ['groupid'],
		selectTimeperiods: 'extend'
	});
}

for (i = 0; i < maintenances.length; i++) {
	maintenance = maintenances[i];

	if (!coversHost(maintenance, hostid, groupids) || !name_matches.test(maintenance.name)
			|| name_not_matches.test(maintenance.name)) {
		continue;
	}

	count++;
	window_list = getWindows(maintenance, now, till);

	for (j = 0; j < window_list.length; j++) {
		windows.push({
			start: window_list[j].start,
			end: window_list[j].end,
			name: maintenance.name,
			maintenanceid: maintenance.maintenanceid,
			maintenance_type: parseInt(maintenance.maintenance_type, 10)
		});
	}
}

windows.sort(function (a, b) {
	return (a.start - b.start) || (b.end - a.end) || (a.name < b.name ? -1 : (a.name > b.name ? 1 : 0));
});

// The next window is the first one that starts after the host has left the running maintenance,
// so back-to-back windows do not raise a reminder while the host is still in maintenance.
for (i = 0; i < windows.length; i++) {
	if (windows[i].start <= now) {
		if (active === null || windows[i].end > active.end) {
			active = windows[i];
		}

		busy_until = Math.max(busy_until, windows[i].end);
	}
	else if (active !== null && windows[i].start <= busy_until) {
		busy_until = Math.max(busy_until, windows[i].end);
	}
	else {
		next = windows[i];
		break;
	}
}

return JSON.stringify({
	clock: now,
	maintenances: count,
	active: active !== null ? 1 : 0,
	active_name: active !== null ? active.name : '',
	active_end: active !== null ? busy_until : 0,
	next_name: next !== null ? next.name : '',
	next_maintenanceid: next !== null ? next.maintenanceid : '',
	next_type: next !== null ? next.maintenance_type : -1,
	next_start: next !== null ? next.start : 0,
	next_end: next !== null ? next.end : 0,
	next_days: next !== null ? getDayNumber(next.start) - getDayNumber(now) : -1
});
