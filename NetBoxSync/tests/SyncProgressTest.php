<?php declare(strict_types = 1);

require_once dirname(__DIR__).'/lib/bootstrap.php';

use Modules\NetBoxSync\Lib\Config;
use Modules\NetBoxSync\Lib\NetBoxClient;
use Modules\NetBoxSync\Lib\StateStore;
use Modules\NetBoxSync\Lib\SyncEngine;
use Modules\NetBoxSync\Lib\ZabbixApiClient;

final class ProgressZabbix extends ZabbixApiClient {
    public array $hosts;
    public $on_fetch = null;
    public $on_prefetch = null;
    public bool $fail_fetch = false;
    public function __construct(array $hosts) { $this->hosts = $hosts; }
    public function getAllHosts(int $limit = 0): array {
        if ($this->on_fetch !== null) { ($this->on_fetch)(); }
        if ($this->fail_fetch) { throw new RuntimeException('Fixture host fetch failed.'); }
        return $this->hosts;
    }
    public function getItemsByExactKeys(array $hostids, array $keys): array {
        if ($this->on_prefetch !== null) { ($this->on_prefetch)(); }
        return [];
    }
    public function getItemsByExactNames(array $hostids, array $names): array { return []; }
    public function searchItemsByKeyForHosts(array $hostids, string $pattern, array $extra = []): array { return []; }
    public function getInterfacesForHosts(array $hostids): array { return []; }
}
final class ProgressNetBox extends NetBoxClient {
    public function __construct() {}
}
class ProgressStore extends StateStore {
    public array $snapshots = [];
    public string $failure = '';
    public bool $failed_once = false;
    public function saveProgress(array $progress): void {
        if ($this->failure === 'partial' && !$this->failed_once && $progress['phase'] === 'syncing' && $progress['hosts_completed'] === 1) {
            $this->failed_once = true;
            throw new RuntimeException('Fixture progress write failed after one host.');
        }
        parent::saveProgress($progress);
        $this->snapshots[] = $this->getProgress($progress['run_id']);
    }
    public function saveLastSummary(array $summary): void {
        if ($this->failure === 'summary') { throw new RuntimeException('Fixture summary finalization failed.'); }
        parent::saveLastSummary($summary);
    }
    public function saveTimestamps(): void {
        if ($this->failure === 'timestamps') { throw new RuntimeException('Fixture timestamp finalization failed.'); }
        parent::saveTimestamps();
    }
    public function log(string $level, string $message, array $context = []): void {
        if ($this->failure === 'host' && $message === 'Processing host.' && ($context['host'] ?? '') === 'bad-host') {
            throw new RuntimeException('Fixture host failed.');
        }
        parent::log($level, $message, $context);
    }
}
final class FinishingProgressReader extends StateStore {
    public $during_probe;
    protected function runOwnsLock(string $run_id): bool {
        ($this->during_probe)();
        return parent::runOwnsLock($run_id);
    }
}

// Minimal controller/DB boundaries for the new endpoint; no Zabbix services.
class CController {
    public int $user_type = 1;
    public $response;
    protected function validateInput(array $fields): bool { return true; }
    protected function getUserType(): int { return $this->user_type; }
    protected function setResponse($response): void { $this->response = $response; }
    public function disableCsrfValidation(): void {}
}
class CControllerResponseData {
    public array $data;
    public function __construct(array $data) { $this->data = $data; }
    public function disableView(): self { return $this; }
}
if (!defined('USER_TYPE_SUPER_ADMIN')) { define('USER_TYPE_SUPER_ADMIN', 3); }
if (!function_exists('_')) { function _($value) { return $value; } }
function DBselect($query) { return true; }
function DBfetch($result): array { return ['config' => $GLOBALS['progress_endpoint_config'] ?? []]; }
function zbx_dbstr($value): string { return "'".$value."'"; }
require_once dirname(__DIR__).'/actions/RunStatus.php';

$checks = 0;
$assert = static function(bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) { throw new RuntimeException('FAIL: '.$message); }
};
$fails = static function(callable $operation, string $message) use ($assert): void {
    try { $operation(); }
    catch (Throwable $e) { $assert(true, $message); return; }
    $assert(false, $message);
};
$root = sys_get_temp_dir().'/netboxsync-progress-'.bin2hex(random_bytes(6));
mkdir($root, 0700);
$fixture_index = 0;
$fixture = static function(array $hosts, string $failure = '') use ($root, &$fixture_index): array {
    $directory = $root.'/run-'.(++$fixture_index);
    $config = Config::defaults();
    $config['netbox']['enabled'] = true;
    $config['netbox']['url'] = 'https://netbox.example.test';
    $config['netbox']['token'] = 'offline-fixture';
    $config['runner']['state_path'] = $directory.'/state';
    $config['runner']['log_path'] = $directory.'/log';
    $config['vm']['enabled'] = false;
    $config['device']['enabled'] = false;
    $config['services']['enabled'] = false;
    $store = new ProgressStore($config['runner']['state_path'], $config['runner']['log_path']);
    $store->failure = $failure;
    $zabbix = new ProgressZabbix($hosts);
    $run_id = bin2hex(random_bytes(16));
    $engine = new SyncEngine($config, ['run_id' => $run_id, 'zabbix_client' => $zabbix, 'netbox_client' => new ProgressNetBox()]);
    $property = new ReflectionProperty(SyncEngine::class, 'store');
    $property->setAccessible(true);
    $property->setValue($engine, $store);
    return [$engine, $store, $zabbix, $config, $run_id];
};
$execute = static function(SyncEngine $engine): array {
    $method = new ReflectionMethod($engine, 'execute');
    $method->setAccessible(true);
    return $method->invoke($engine);
};
$hosts = [['hostid' => '1', 'host' => 'one'], ['hostid' => '2', 'host' => 'two'], ['hostid' => '3', 'host' => 'three']];

try {
    [$engine, $store, $zabbix, $config, $run_id] = $fixture($hosts);
    $zabbix->on_fetch = static function() use ($store, $run_id, $assert): void {
        $status = $store->getProgress($run_id);
        $assert($status['phase'] === 'fetching' && $status['percent'] === null && !$status['total_known'], 'host fetching is indeterminate');
    };
    $zabbix->on_prefetch = static function() use ($store, $run_id, $assert): void {
        $status = $store->getProgress($run_id);
        $assert($status['phase'] === 'prefetching' && $status['percent'] === 0 && $status['hosts_total'] === 3, 'prefetch denominator from actual fetched hosts');
    };
    $summary = $execute($engine);
    $assert($summary['ok'] && $summary['summary_saved'] && $summary['hosts_completed'] === 3, 'successful run counted and persisted');
    $phases = array_column($store->snapshots, 'phase');
    $assert($phases === ['preparing', 'fetching', 'prefetching', 'syncing', 'syncing', 'syncing', 'syncing', 'finalizing', 'completed'], 'actual engine phase order');
    $syncing = array_values(array_filter($store->snapshots, fn($s) => $s['phase'] === 'syncing'));
    $assert(array_column($syncing, 'percent') === [0, 33, 66, 99], 'host attempts drive percentages');
    $assert($store->snapshots[7]['percent'] === 99 && $store->snapshots[7]['running'], 'finalizing is not complete');
    $terminal = $store->getProgress($run_id);
    $assert($terminal['phase'] === 'completed' && $terminal['percent'] === 100 && !$terminal['running'], 'terminal success after finalization');
    $assert($store->getLastSummary()['run_id'] === $run_id, 'saved summary belongs to current run');
    $assert(!$store->ownsLock(), 'lock released after success');
    if (DIRECTORY_SEPARATOR === '/') {
        $assert((fileperms($config['runner']['state_path'].'/progress.json') & 0777) === 0660, 'snapshot supports authorized shared group');
    }

    [$engine, $store] = $fixture([]);
    $summary = $execute($engine);
    $assert($summary['ok'] && $summary['hosts_completed'] === 0 && $summary['progress']['percent'] === 100, 'zero-host run completes only after finalization');
    foreach ($store->snapshots as $snapshot) {
        $assert(!$snapshot['running'] || $snapshot['percent'] !== 100, 'empty run never prematurely reaches100');
    }

    [$engine, $store] = $fixture([['hostid' => '1', 'host' => 'bad-host'], ['hostid' => '', 'host' => 'invalid'], ['hostid' => '3', 'host' => 'good']], 'host');
    $summary = $execute($engine);
    $assert(!$summary['ok'] && $summary['errors'] === 1 && $summary['hosts_processed'] === 1, 'failed and skipped hosts remain distinguishable');
    $assert($summary['hosts_completed'] === 3 && $summary['progress']['percent'] === 100 && $summary['progress']['phase'] === 'failed', 'all attempts finished can be100 with failed outcome');

    [$engine, $store] = $fixture($hosts, 'partial');
    $summary = $execute($engine);
    $assert(!$summary['ok'] && $summary['hosts_completed'] === 1 && $summary['progress']['percent'] === 33, 'partial abort retains actual fraction');
    $assert($store->getProgress()['phase'] === 'failed' && !$store->ownsLock(), 'partial failure recorded and lock released');

    foreach (['summary', 'timestamps'] as $failure) {
        [$engine, $store] = $fixture($hosts, $failure);
        $summary = $execute($engine);
        $assert(!$summary['ok'] && $summary['progress']['phase'] === 'failed' && $summary['progress']['percent'] === 100, 'finalization failure is not success');
        $assert($summary['summary_saved'] === ($failure !== 'summary'), 'summary persistence is reported accurately');
        $assert($store->getProgress()['phase'] === 'failed' && !$store->ownsLock(), 'finalization failure publishes failed and releases lock');
    }

    [$engine, $store, $zabbix] = $fixture($hosts);
    $zabbix->fail_fetch = true;
    $summary = $execute($engine);
    $assert(!$summary['ok'] && $summary['progress']['percent'] === null && !$summary['progress']['total_known'], 'fetch failure never invents denominator');

    [$engine, $store, $zabbix, $config] = $fixture($hosts);
    $zabbix->on_fetch = static function() use ($store, $config, $assert): void {
        $before_progress = file_get_contents($config['runner']['state_path'].'/progress.json');
        $before_summary = json_encode(['run_id' => 'previous-summary']);
        file_put_contents($config['runner']['state_path'].'/last_summary.json', $before_summary);
        $contender = SyncEngine::run($config, ['zabbix_client' => new ProgressZabbix([]), 'netbox_client' => new ProgressNetBox()]);
        $assert(!$contender['ok'] && !$contender['summary_saved'] && !isset($contender['progress']), 'competing run fails without claiming progress');
        $assert(file_get_contents($config['runner']['state_path'].'/progress.json') === $before_progress, 'lock contender cannot overwrite active progress');
        $assert(file_get_contents($config['runner']['state_path'].'/last_summary.json') === $before_summary, 'lock contender cannot overwrite previous summary');
        $assert($store->ownsLock(), 'owner lock survives contention');
    };
    $assert($execute($engine)['ok'], 'owning run continues after rejected contender');

    $state = $root.'/status/state'; $log = $root.'/status/log';
    $writer = new StateStore($state, $log);
    $reader = new StateStore($state, $log);
    $id = str_repeat('a', 32);
    $writer->acquireLock($id);
    $snapshot = ['run_id' => $id, 'phase' => 'syncing', 'running' => true, 'percent' => 33,
        'hosts_completed' => 1, 'hosts_total' => 3, 'total_known' => true, 'errors' => 0,
        'source' => 'cli', 'started_at' => gmdate('c'), 'updated_at' => gmdate('c'), 'finished_at' => null];
    $writer->saveProgress($snapshot + ['unexpected_secret' => 'not-persisted']);
    $assert(strpos(file_get_contents($state.'/progress.json'), 'unexpected_secret') === false, 'snapshot allowlist');
    $assert($reader->getProgress($id)['running'], 'independent status reader sees active lock');
    $assert($reader->getProgress(str_repeat('b', 32)) === null, 'run id prevents prior-run confusion');
    $fails(fn() => $reader->saveProgress($snapshot), 'nonowner progress write refused');
    $fails(fn() => $reader->saveLastSummary([]), 'nonowner summary write refused');
    $writer->releaseLock();
    $stale = $reader->getProgress($id);
    $assert(!$stale['running'] && $stale['phase'] === 'interrupted' && $stale['percent'] === 33, 'crashed snapshot becomes interrupted, not complete');
    $assert(json_decode(file_get_contents($state.'/progress.json'), true)['phase'] === 'syncing', 'status does not mutate history');
    $writer->acquireLock(str_repeat('b', 32));
    $assert($reader->getProgress($id)['phase'] === 'interrupted', 'different run lock cannot revive stale snapshot');
    $writer->releaseLock();
    $writer->acquireLock($id);
    $writer->saveProgress($snapshot);
    $finishing_reader = new FinishingProgressReader($state, $log);
    $finishing_reader->during_probe = static function() use ($writer, $snapshot): void {
        $writer->saveProgress(array_replace($snapshot, ['phase' => 'completed', 'running' => false,
            'percent' => 100, 'hosts_completed' => 3, 'finished_at' => gmdate('c')]));
        $writer->releaseLock();
    };
    $assert($finishing_reader->getProgress($id)['phase'] === 'completed', 'completion during lock probe is not mislabeled interrupted');

    foreach (['{broken', str_repeat('x', 16385), json_encode(array_replace($snapshot, ['percent' => 100])),
        json_encode(array_replace($snapshot, ['hosts_completed' => 99])), json_encode(array_replace($snapshot, ['source' => ['bad']])),
        json_encode(array_replace($snapshot, ['phase' => 'completed', 'running' => false, 'percent' => 17]))] as $malformed) {
        file_put_contents($state.'/progress.json', $malformed);
        $assert($reader->getProgress() === null, 'malformed progress never becomes plausible status');
    }
    $fails(fn() => $reader->getProgress('../bad'), 'invalid run filter rejected');

    $controller = new \Modules\NetBoxSync\Actions\RunStatus();
    $invoke = static function(object $target, string $method) {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($target);
    };
    $assert($invoke($controller, 'checkPermissions') === false, 'status endpoint denies ordinary users');
    $controller->user_type = USER_TYPE_SUPER_ADMIN;
    $assert($invoke($controller, 'checkPermissions') === true, 'status endpoint allows Super Admin');
    $_REQUEST = ['run_id' => '../malformed'];
    $assert($invoke($controller, 'checkInput') === false && http_response_code() === 400, 'status rejects malformed request identifier');
    $_REQUEST = ['run_id' => $id];
    $assert($invoke($controller, 'checkInput') === true, 'status accepts exact run identifier');
    $GLOBALS['progress_endpoint_config'] = ['runner' => ['state_path' => $state, 'log_path' => $log], 'netbox' => ['token' => 'must-not-leak']];
    file_put_contents($state.'/progress.json', json_encode($snapshot + ['token' => 'must-not-leak']));
    $invoke($controller, 'doAction');
    $payload = json_decode($controller->response->data['main_block'], true);
    $assert($payload['ok'] && $payload['progress']['phase'] === 'interrupted' && $payload['progress']['run_id'] === $id, 'endpoint returns correlated status');
    $assert(strpos($controller->response->data['main_block'], 'must-not-leak') === false, 'endpoint emits only progress fields');
    $_REQUEST = ['run_id' => str_repeat('c', 32)];
    $invoke($controller, 'doAction');
    $assert(json_decode($controller->response->data['main_block'], true)['progress'] === null, 'endpoint does not return another run');
    echo 'OK: '.$checks." sync progress checks (offline engine and file-lock fixtures)\n";
}
finally {
    // Cleanup is confined to this test's newly created random temporary root.
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); }
    }
    rmdir($root);
}
