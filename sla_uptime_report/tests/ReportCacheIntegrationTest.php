<?php declare(strict_types=1);

// Complete report calculations over generated numeric history; only the Zabbix API/storage boundary is mocked.
require __DIR__.'/bootstrap.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
if (!defined('TIMEPERIOD_TYPE_ONETIME')) { define('TIMEPERIOD_TYPE_ONETIME', 0); }

use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

function DBselect(string $query, int $limit = 0) { return true; }
function DBfetch($result): array { return ['moduleid'=>'1','config'=>json_encode(['availability'=>$GLOBALS['rc_settings']])]; }
function zbx_dbstr(string $value): string { return "'".str_replace("'", "''", $value)."'"; }

$checks = 0;
function rc_check(bool $condition, string $message): void {
	global $checks;
	if (!$condition) { throw new RuntimeException($message); }
	$checks++;
}
function rc_history_calls(): array {
	return array_values(array_map(static fn(array $call): array => $call[1],
		array_filter(API::$calls, static fn(array $call): bool => $call[0] === 'History')));
}
function rc_trend_calls(): array {
	return array_values(array_map(static fn(array $call): array => $call[1],
		array_filter(API::$calls, static fn(array $call): bool => $call[0] === 'Trend')));
}
function rc_build(int $from, int $to, bool $force = false): array {
	API::$calls = [];
	$helper = new ReportDataHelper($force);
	$method = new ReflectionMethod($helper, 'buildAvailability');
	if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
	$report = $method->invoke($helper, [], $from, $to, true, 99.0, 100, [['groupid'=>'10','name'=>'Websites']], false);
	$property = new ReflectionProperty($helper, 'cache_stats');
	if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
	$rows = [];
	foreach ($report['groups'] as $group) {
		foreach ($group['rows_all'] as $row) { $rows[$row['measurement_label']] = $row; }
	}
	ksort($rows);
	return ['report'=>$report,'rows'=>$rows,'cache'=>$property->getValue($helper),
		'queries'=>rc_history_calls(),'trend_queries'=>rc_trend_calls()];
}

$directory = sys_get_temp_dir().'/sla-report-integration-'.bin2hex(random_bytes(8));
$old_directory = getenv('SLA_REPORT_CACHE_DIR');
$old_namespace = getenv('SLA_REPORT_CACHE_NAMESPACE');
putenv('SLA_REPORT_CACHE_DIR='.$directory);
putenv('SLA_REPORT_CACHE_NAMESPACE');
$GLOBALS['DB'] = ['TYPE'=>'MYSQL','SERVER'=>'synthetic-cache-test','DATABASE'=>'synthetic-'.bin2hex(random_bytes(6))];
$GLOBALS['rc_visible'] = ['1','2','3','4','5'];
$GLOBALS['rc_maintenance'] = [];
$GLOBALS['rc_settings'] = ['hosts'=>[], 'cache'=>['enabled'=>true]];
$hostids = ['1','1','1','2','3','4','5'];
$expected = [200,302,302,200,200,200,200];
$items = [];
foreach ($hostids as $index=>$hostid) {
	$id = (string) (101 + $index);
	$key = 'web.test.rspcode[Website '.$id.',Step]';
	$GLOBALS['rc_settings']['hosts'][] = ['hostid'=>$hostid,'key'=>$key,'label'=>'Check '.$id,
		'mode'=>'equals','value'=>$expected[$index],'interval_seconds'=>60];
	$items[] = ['itemid'=>$id,'hostid'=>$hostid,'key_'=>$key,'name'=>'Check '.$id,'status'=>0,'value_type'=>3,'delay'=>'0'];
}
$GLOBALS['rc_items'] = $items;
$GLOBALS['rc_codes'] = array_combine(array_column($items,'itemid'), $expected);
$from = 1700000040;
$to = $from + 30 * 86400 - 1;
$GLOBALS['rc_origin'] = $from;
$GLOBALS['rc_until'] = $to;

API::$handlers['HostGroup'] = static fn(array $options): array => [['groupid'=>'10','name'=>'Websites']];
API::$handlers['Host'] = static function(array $options): array {
	return array_map(static fn(string $id): array => ['hostid'=>$id,'host'=>'host-'.$id,'name'=>'Host '.$id,
		'status'=>0,'hostgroups'=>[['groupid'=>'10','name'=>'Websites']]], $GLOBALS['rc_visible']);
};
API::$handlers['Item'] = static fn(array $options): array => array_values(array_filter($GLOBALS['rc_items'],
	static fn(array $item): bool => in_array($item['hostid'], $GLOBALS['rc_visible'], true)
		&& in_array($item['hostid'], array_map('strval',$options['hostids']), true)
		&& in_array($item['key_'], $options['filter']['key_'], true)));
API::$handlers['Maintenance'] = static fn(array $options): array => $GLOBALS['rc_maintenance'];
API::$handlers['Trend'] = static fn(array $options): array => [];
API::$handlers['History'] = static function(array $options): array {
	rc_check(count($options['itemids']) === 1, 'History queries must use a single item index.');
	$id = (string) $options['itemids'][0];
	$origin = $GLOBALS['rc_origin'];
	$cadence = $GLOBALS['rc_cadence'][$id] ?? 60;
	$start = max($origin, (int) $options['time_from']);
	$start = $origin + max(0, (int) ceil(($start - $origin) / $cadence)) * $cadence;
	$until = min($GLOBALS['rc_until'], (int) $options['time_till']);
	$rows = [];
	for ($clock=$start; $clock<=$until && count($rows)<$options['limit']; $clock+=$cadence) {
		$value = $id === '101' && $clock === $origin ? 503 : $GLOBALS['rc_codes'][$id];
		$rows[] = ['itemid'=>$id,'clock'=>$clock,'value'=>(string) $value,'ns'=>0];
	}
	return $rows;
};

try {
	$cold = rc_build($from,$to);
	$cold_queries = count($cold['queries']);
	rc_check(count($cold['rows']) === 7, 'Cold report retains all seven website measurements.');
	rc_check($cold['report']['fleet']['hosts_total'] === 5, 'Three measurements on one host do not multiply host counts.');
	rc_check(abs($cold['rows']['Check 101']['pct'] - 100 * 43199 / 43200) < 0.000001, 'Cold history includes the deliberately failed reading.');
	rc_check($cold['rows']['Check 102']['pct'] === 100.0 && $cold['rows']['Check 103']['pct'] === 100.0, 'Both expected 302 scenarios remain healthy.');
	rc_check($cold_queries < 100, 'Cold query count is bounded below the former roughly 152 calls for this fixture.');
	if (PHP_OS_FAMILY !== 'Linux') {
		echo 'Report cache integration: '.$checks.' checks passed (live fallback; persistent cache unavailable).'.PHP_EOL;
		return;
	}
	rc_check($cold['cache']['available'], 'Private Linux cache must be available for the integration test.');
	$warm = rc_build($from,$to);
	rc_check($warm['rows'] === $cold['rows'], 'Warm cache preserves every reported result exactly.');
	rc_check(count($warm['queries']) === 0, 'An unchanged historical 30-day report needs no History calls.');
	rc_check($warm['cache']['rows_reused'] === 302400, 'Warm report reuses all 302400 readings.');

	$GLOBALS['rc_until'] = $to + 300;
	$extended = rc_build($from,$to+300);
	rc_check(count($extended['queries']) === 7, 'Extending the historical interval queries each item only for the new suffix.');
	foreach ($extended['queries'] as $query) {
		rc_check($query['time_from'] === $to+1 && $query['time_till'] === $to+300, 'Historical extension never re-fetches an already covered interval.');
	}

	$GLOBALS['rc_settings']['hosts'][0]['value'] = 302;
	$changed_rule = rc_build($from,$to);
	rc_check($changed_rule['rows']['Check 101']['pct'] === 0.0, 'Changed healthy value is applied to cached raw samples.');
	rc_check(count($changed_rule['queries']) === 0, 'A policy change reuses raw numeric data without re-fetching it.');
	$GLOBALS['rc_settings']['hosts'][0]['value'] = 200;
	$GLOBALS['rc_maintenance'] = [['maintenanceid'=>'1','name'=>'Retrospective planned work','maintenance_type'=>0,
		'active_since'=>$from,'active_till'=>$from+60,'hosts'=>[['hostid'=>'1']],'hostgroups'=>[],'tags'=>[],
		'timeperiods'=>[['timeperiod_type'=>0,'start_date'=>$from,'period'=>60]]]];
	$maintained = rc_build($from,$to);
	rc_check($maintained['rows']['Check 101']['pct'] === 100.0 && $maintained['rows']['Check 101']['maintenance_seconds'] === 60,
		'New maintenance is applied to cached readings and excludes the failed interval.');
	rc_check(count($maintained['queries']) === 0, 'Maintenance changes do not require re-reading numeric history.');
	$GLOBALS['rc_maintenance'] = [];

	$GLOBALS['rc_visible'] = ['1','2','3','4'];
	$revoked = rc_build($from,$to);
	rc_check(count($revoked['rows']) === 6 && !isset($revoked['rows']['Check 107']), 'A warm cache cannot retain a host after access is revoked.');
	rc_check(count($revoked['queries']) === 0, 'Authorized remaining hosts still reuse their data.');
	$GLOBALS['rc_visible'] = ['1','2','3','4','5'];

	$forced = rc_build($from,$from+3599,true);
	rc_check(count($forced['queries']) === 7 && $forced['cache']['rows_reused'] === 0, 'Force refresh bypasses historical cache coverage.');
	$GLOBALS['rc_settings']['cache']['enabled'] = false;
	$disabled = rc_build($from,$from+3599);
	rc_check(count($disabled['queries']) === 7 && !$disabled['cache']['enabled'], 'Disabled cache performs live queries.');
	$GLOBALS['rc_settings']['cache']['enabled'] = true;

	$recent_to = (int) (floor(time()/60)*60) - 181;
	$recent_from = $recent_to - 3599;
	$GLOBALS['rc_origin'] = $recent_from;
	$GLOBALS['rc_until'] = $recent_to + 120;
	rc_build($recent_from,$recent_to);
	$tail = rc_build($recent_from,$recent_to+120);
	rc_check(count($tail['rows']) === 7 && $tail['cache']['rows_reused'] > 0, 'Live extension keeps older covered readings.');
	rc_check(count($tail['queries']) > 0 && count($tail['queries']) <= 14, 'Only bounded recent ranges are fetched for a live refresh.');
	foreach ($tail['queries'] as $query) {
		rc_check($query['time_from'] >= $recent_to-900 && $query['time_till'] <= $recent_to+120,
			'Live refresh is restricted to the mutable 15-minute tail and new samples.');
	}

	// A dozen ordinary binary checks use batched hourly trends for the same month.
	$trend_from = (int) floor($from / 3600) * 3600;
	$trend_to = $trend_from + 30 * 86400 - 1;
	$GLOBALS['rc_visible'] = array_map('strval', range(21,32));
	$GLOBALS['rc_items'] = [];
	$GLOBALS['rc_settings']['hosts'] = [];
	foreach ($GLOBALS['rc_visible'] as $index=>$hostid) {
		$id = (string) (201 + $index);
		$GLOBALS['rc_settings']['hosts'][] = ['hostid'=>$hostid,'key'=>'agent.ping',
			'label'=>'Trend '.$id,'mode'=>'binary','interval_seconds'=>60];
		$GLOBALS['rc_items'][] = ['itemid'=>$id,'hostid'=>$hostid,'key_'=>'agent.ping',
			'name'=>'Trend '.$id,'status'=>0,'value_type'=>3,'delay'=>'60s'];
	}
	API::$handlers['Trend'] = static function(array $options) use ($trend_from, $trend_to): array {
		$rows = [];
		foreach ($options['itemids'] as $id) {
			$start = max($trend_from, (int) ceil($options['time_from']/3600)*3600);
			$end = min($trend_to, $options['time_till']);
			for ($clock=$start; $clock<=$end; $clock+=3600) {
				$value = (string) ($id === '201' && $clock === $trend_from ? 0 : 1);
				$rows[] = ['itemid'=>$id,'clock'=>$clock,'num'=>'60',
					'value_min'=>$value,'value_avg'=>$value,'value_max'=>$value];
			}
		}
		return $rows;
	};
	$trend_cold = rc_build($trend_from,$trend_to);
	$weeks = (int) floor(($trend_to-345600)/(7*86400)) - (int) floor(($trend_from-345600)/(7*86400)) + 1;
	rc_check(count($trend_cold['rows']) === 12 && $trend_cold['report']['fleet']['hosts_total'] === 12,
		'Cold trend report retains all twelve hosts.');
	rc_check($trend_cold['queries'] === [] && count($trend_cold['trend_queries']) === $weeks,
		'Cold trends use one shared API call per weekly shard, rather than one per host.');
	foreach ($trend_cold['trend_queries'] as $query) {
		rc_check(count($query['itemids']) === 12, 'Each cold trend call includes all twelve authorized items.');
	}
	rc_check(abs($trend_cold['rows']['Trend 201']['pct'] - 100*719/720) < 0.000001,
		'The failed hour contributes downtime in the cold trend report.');
	$trend_warm = rc_build($trend_from,$trend_to);
	rc_check($trend_warm['rows'] === $trend_cold['rows'], 'Warm trends reproduce the complete cold report.');
	rc_check($trend_warm['queries'] === [] && $trend_warm['trend_queries'] === [],
		'An unchanged warm trend report makes no History or Trend calls.');
	rc_check($trend_warm['cache']['rows_reused'] === 8640, 'Warm trends reuse all twelve hosts and 720 hours.');
	$GLOBALS['rc_maintenance'] = [['maintenanceid'=>'2','name'=>'Retrospective trend maintenance','maintenance_type'=>0,
		'active_since'=>$trend_from,'active_till'=>$trend_from+3600,'hosts'=>[['hostid'=>'21']],'hostgroups'=>[],'tags'=>[],
		'timeperiods'=>[['timeperiod_type'=>0,'start_date'=>$trend_from,'period'=>3600]]]];
	$trend_maintained = rc_build($trend_from,$trend_to);
	rc_check($trend_maintained['rows']['Trend 201']['pct'] === 100.0
		&& $trend_maintained['rows']['Trend 201']['maintenance_seconds'] === 3600,
		'New maintenance is reapplied to cached trends and excludes the failed hour.');
	rc_check($trend_maintained['trend_queries'] === [], 'Maintenance changes reuse the cached hourly trends.');
	$GLOBALS['rc_maintenance'] = [];
	$GLOBALS['rc_visible'][] = '33';
	$GLOBALS['rc_settings']['hosts'][] = ['hostid'=>'33','key'=>'agent.ping',
		'label'=>'Trend 213','mode'=>'binary','interval_seconds'=>60];
	$GLOBALS['rc_items'][] = ['itemid'=>'213','hostid'=>'33','key_'=>'agent.ping',
		'name'=>'Trend 213','status'=>0,'value_type'=>3,'delay'=>'60s'];
	$trend_added = rc_build($trend_from,$trend_to);
	rc_check(count($trend_added['rows']) === 13 && count($trend_added['trend_queries']) === $weeks,
		'Adding a thirteenth host loads its missing weekly trends.');
	foreach ($trend_added['trend_queries'] as $query) {
		rc_check($query['itemids'] === ['213'], 'A newly added host never causes warm peers to be fetched again.');
	}
	$series = hash('sha256', \Modules\SlaUptimeReport\Helpers\HistoryCache::SCHEMA."\0trend\0".'201'."\0".'3');
	$shards = glob($directory.'/*/'.$series.'-*.json.gz');
	rc_check(is_array($shards) && count($shards) === $weeks, 'The cached trend fixture has one shard per week.');
	sort($shards);
	rc_check(unlink($shards[0]), 'Evict exactly one owned fixture shard.');
	$trend_evicted = rc_build($trend_from,$trend_to);
	rc_check($trend_evicted['rows'] === $trend_added['rows'], 'Replacing an evicted shard preserves all trend results.');
	rc_check(count($trend_evicted['trend_queries']) === 1 && $trend_evicted['trend_queries'][0]['itemids'] === ['201'],
		'One evicted trend shard only re-fetches the missing item and week.');

	// Web scenario items often report delay=0 even when their actual cadence is much shorter.
	$GLOBALS['rc_visible'] = ['41','42'];
	$GLOBALS['rc_settings']['hosts'] = [];
	$GLOBALS['rc_items'] = [];
	$GLOBALS['rc_origin'] = $from;
	$GLOBALS['rc_until'] = $to;
	$GLOBALS['rc_codes'] = ['301'=>200,'302'=>200];
	$GLOBALS['rc_cadence'] = ['301'=>10,'302'=>60];
	foreach (['301'=>'41','302'=>'42'] as $itemid=>$hostid) {
		$id = (string) $itemid;
		$key = 'web.test.rspcode[Fast '.$id.',Step]';
		$GLOBALS['rc_settings']['hosts'][] = ['hostid'=>$hostid,'key'=>$key,
			'label'=>'Fast '.$id,'mode'=>'equals','value'=>200,'interval_seconds'=>0];
		$GLOBALS['rc_items'][] = ['itemid'=>$id,'hostid'=>$hostid,'key_'=>$key,
			'name'=>'Fast '.$id,'status'=>0,'value_type'=>3,'delay'=>'0'];
	}
	$fast = rc_build($from,$to);
	rc_check(count($fast['rows']) === 2 && $fast['rows']['Fast 301']['pct'] === 100.0,
		'A 10-second scenario with unknown configured delay completes its whole 30-day history.');
	rc_check($fast['rows']['Fast 301']['coverage_pct'] === 100.0 && $fast['rows']['Fast 302']['pct'] === 100.0
		&& $fast['rows']['Fast 302']['coverage_pct'] === 100.0,
		'Adaptive slicing retains complete coverage and continues to later items below the global row cap.');
	rc_check($fast['report']['fleet']['na'] === 0, 'Dense but valid history does not produce no-data hosts.');
	echo 'Report cache integration: '.$checks.' checks passed. Synthetic seven-check, 30-day History calls: cold='
		.$cold_queries.', warm='.count($warm['queries']).', extension='.count($extended['queries'])
		.'. Twelve-host Trend calls: cold='.count($trend_cold['trend_queries']).', warm='.count($trend_warm['trend_queries']).'.'.PHP_EOL;
}
finally {
	putenv($old_directory === false ? 'SLA_REPORT_CACHE_DIR' : 'SLA_REPORT_CACHE_DIR='.$old_directory);
	putenv($old_namespace === false ? 'SLA_REPORT_CACHE_NAMESPACE' : 'SLA_REPORT_CACHE_NAMESPACE='.$old_namespace);
	if (is_dir($directory)) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $entry) {
			if ($entry->isLink() || $entry->isFile()) { unlink($entry->getPathname()); }
			else { rmdir($entry->getPathname()); }
		}
		rmdir($directory);
	}
}
