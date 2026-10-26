<?php declare(strict_types = 0);

// Offline tests: no provider, database, Vault or network calls are made.
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/WebhookDatabase.php';

use Modules\AI\Lib\Config;
use Modules\AI\Lib\WebhookDatabase;
use Modules\AI\Lib\WebhookGuard;
use Modules\AI\Lib\WebhookHandler;
use Modules\AI\Lib\WebhookRequestException;

$checks = 0;
function checkWebhook(bool $value, string $message): void {
    global $checks;
    ++$checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function rejectsWebhook(callable $callback, int $status): void {
    try {
        $callback();
    }
    catch (WebhookRequestException $e) {
        checkWebhook($e->httpStatus() === $status, 'Unexpected rejection: '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Expected webhook rejection '.$status);
}
function streamWebhook(string $body) {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $body);
    rewind($stream);
    return $stream;
}

$temporary = sys_get_temp_dir().'/zabbix-ai-webhook-tests-'.bin2hex(random_bytes(8));
mkdir($temporary, 0700, true);
$config = Config::mergeWithDefaults([
    'security' => ['state_path' => $temporary.'/state'],
    'logging' => ['enabled' => false],
    'webhook' => ['enabled' => true, 'rate_limit_per_minute' => 600, 'max_concurrent_requests' => 2]
]);
$payload = ['eventid' => '1001', 'event_value' => '1'];

try {
    $stream = streamWebhook('12345');
    checkWebhook(WebhookGuard::readBody($stream, 5) === '12345', 'Exact request bound failed.');
    fclose($stream);
    foreach ([null, '1', '60000'] as $length) {
        $stream = streamWebhook('123456');
        rejectsWebhook(static function() use ($stream, $length) { WebhookGuard::readBody($stream, 5, $length); }, 413);
        fclose($stream);
    }

    $key = WebhookGuard::deliveryKey($config, $payload);
    checkWebhook($key !== WebhookGuard::deliveryKey($config, array_replace($payload, ['event_value' => '0'])), 'Recovery collided with problem.');
    checkWebhook($key !== WebhookGuard::deliveryKey($config, array_replace($payload, ['event_source' => '4'])), 'Different event source collided.');
    $update = array_replace($payload, ['event_update_status' => '1', 'event_update_timestamp' => '1000', 'event_update_message' => 'First update']);
    checkWebhook($key !== WebhookGuard::deliveryKey($config, $update), 'Update collided with problem.');
    checkWebhook(WebhookGuard::deliveryKey($config, $update) !== WebhookGuard::deliveryKey($config,
        array_replace($update, ['event_update_message' => 'Second update'])), 'Distinct updates collided.');
    rejectsWebhook(static function() use ($config, $payload) {
        WebhookGuard::deliveryKey($config, array_replace($payload, ['event_update_status' => '1']));
    }, 400);

    $first = WebhookGuard::claim($config, $payload);
    rejectsWebhook(static function() use ($config, $payload) { WebhookGuard::claim($config, $payload); }, 409);
    $first->beginExternalWork();
    $first->complete(2);
    $duplicate = WebhookGuard::claim($config, $payload);
    checkWebhook($duplicate->duplicateResult()['posted_chunks'] === 2, 'Completed duplicate did not reuse result.');
    checkWebhook($duplicate->duplicateResult()['reply'] === '', 'Dedup state leaked/stored reply data.');
    unset($duplicate);

    $retry_payload = ['eventid' => '1002'];
    $retry = WebhookGuard::claim($config, $retry_payload);
    $retry->abort();
    $retry = WebhookGuard::claim($config, $retry_payload);
    checkWebhook($retry->duplicateResult() === null, 'Preflight failure blocked a safe retry.');
    unset($retry); // Simulated worker crash before any external call.
    $retry = WebhookGuard::claim($config, $retry_payload);
    checkWebhook($retry->duplicateResult() === null, 'Abandoned preflight remained locked.');
    $retry->beginExternalWork();
    $retry->abort();
    rejectsWebhook(static function() use ($config, $retry_payload) { WebhookGuard::claim($config, $retry_payload); }, 409);
    rejectsWebhook(static function() use ($config, $retry_payload) { WebhookGuard::claim($config, $retry_payload); }, 409);

    // Uncertainty expires only after the configured retention window.
    $retry_key = WebhookGuard::deliveryKey($config, $retry_payload);
    $shard = $temporary.'/state/webhook/deliveries_'.substr($retry_key, 0, 2).'.json';
    $records = json_decode(file_get_contents($shard), true);
    $records[$retry_key]['expires_at'] = time() - 1;
    file_put_contents($shard, json_encode($records));
    $expired = WebhookGuard::claim($config, $retry_payload);
    checkWebhook($expired->duplicateResult() === null, 'Expired delivery did not permit a new attempt.');
    $expired->abort();

    // Unknown/corrupt status is not treated as a safe abandoned preflight.
    $records = json_decode(file_get_contents($shard), true);
    $records[$retry_key] = ['status' => 'invented', 'expires_at' => time() + 1000];
    file_put_contents($shard, json_encode($records));
    rejectsWebhook(static function() use ($config, $retry_payload) { WebhookGuard::claim($config, $retry_payload); }, 503);
    unset($records[$retry_key]);
    file_put_contents($shard, json_encode($records));

    $limit_config = $config;
    $limit_config['security']['state_path'] = $temporary.'/concurrency';
    $limit_config['webhook']['max_concurrent_requests'] = 1;
    $one = WebhookGuard::claim($limit_config, $payload);
    $other = ['eventid' => '2000'];
    while (substr(WebhookGuard::deliveryKey($limit_config, $other), 0, 2) === substr($key, 0, 2)) {
        $other['eventid'] = (string) ((int) $other['eventid'] + 1);
    }
    rejectsWebhook(static function() use ($limit_config, $other) { WebhookGuard::claim($limit_config, $other); }, 429);
    $one->abort();
    $two = WebhookGuard::claim($limit_config, $other);
    checkWebhook($two->duplicateResult() === null, 'Admission rejection left a false dedup claim.');
    $two->abort();

    $rate_config = $config;
    $rate_config['security']['state_path'] = $temporary.'/rate';
    $rate_config['webhook']['rate_limit_per_minute'] = 1;
    $rate = WebhookGuard::claim($rate_config, $payload);
    $rate->complete(0);
    $duplicate = WebhookGuard::claim($rate_config, $payload);
    checkWebhook($duplicate->duplicateResult() !== null, 'Duplicate unnecessarily consumed rate budget.');
    unset($duplicate);
    rejectsWebhook(static function() use ($rate_config, $other) { WebhookGuard::claim($rate_config, $other); }, 429);

    // Authentication failure must not create state or consume admission budget.
    putenv('AI_WEBHOOK_SECRET=offline-test-secret');
    $auth_config = $config;
    $auth_config['security']['state_path'] = $temporary.'/authentication';
    $auth_config['webhook']['shared_secret_env'] = 'env:AI_WEBHOOK_SECRET';
    try {
        WebhookHandler::process($auth_config, ['eventid' => '3000', 'shared_secret' => 'wrong']);
        throw new RuntimeException('Invalid authentication was accepted.');
    }
    catch (RuntimeException $e) {
        checkWebhook(strpos($e->getMessage(), 'Invalid webhook shared secret') !== false, 'Unexpected auth error.');
    }
    checkWebhook(!is_dir($temporary.'/authentication'), 'Unauthenticated request wrote delivery state.');
    $result = WebhookHandler::process($auth_config, ['eventid' => '3000', 'event_value' => '0', 'shared_secret' => 'offline-test-secret']);
    checkWebhook($result['result'] === 'resolved event ignored', 'Recovery skip did not complete safely.');
    $result = WebhookHandler::process($auth_config, ['eventid' => '3000', 'event_value' => '0', 'shared_secret' => 'offline-test-secret']);
    checkWebhook(!empty($result['duplicate']), 'Skipped recovery retry was not deduplicated.');
    $result = WebhookHandler::process($auth_config, [
        'eventid' => '3000', 'event_update_status' => '1', 'event_update_timestamp' => '12345',
        'event_update_message' => '[AI 1/2] generated guidance', 'shared_secret' => 'offline-test-secret'
    ]);
    checkWebhook($result['result'] === 'AI-generated comment update ignored', 'AI comment loop was not prevented.');

    // Exercise the native initialization contract with a fake offline frontend.
    $frontend = $temporary.'/frontend';
    mkdir($frontend.'/include/classes/core', 0700, true);
    file_put_contents($frontend.'/include/classes/core/APP.php', <<<'PHP'
<?php
class APP {
    const EXEC_MODE_API = 'api';
    public static $failure = false;
    public static $calls = [];
    public static function getInstance() { return new self(); }
    public function run($mode) {
        self::$calls[] = $mode;
        if (self::$failure) { throw new RuntimeException('Native Vault/TLS initialization failed'); }
    }
}
PHP
    );
    $GLOBALS['webhook_test_row'] = ['moduleid' => '1', 'id' => 'custom_ai', 'status' => 1, 'config' => '{}'];
    function DBselect($query) { return true; }
    function DBfetch($result) { return $GLOBALS['webhook_test_row']; }
    function zbx_dbstr($value) { return "'".$value."'"; }
    putenv('ZABBIX_AI_FRONTEND_ROOT='.$frontend);
    $original_directory = getcwd();
    $native = WebhookDatabase::loadConfig();
    checkWebhook(APP::$calls === ['api'], 'Webhook did not use native API initialization.');
    checkWebhook(getcwd() === $original_directory, 'Native initialization changed caller working directory.');
    checkWebhook(is_array($native), 'Native module configuration was not loaded.');
    APP::$failure = true;
    try {
        WebhookDatabase::loadConfig();
        throw new RuntimeException('Native DB security failure was bypassed.');
    }
    catch (RuntimeException $e) {
        checkWebhook($e->getMessage() === 'Native Vault/TLS initialization failed', 'Native TLS/Vault error was replaced by a fallback.');
    }
    APP::$failure = false;
    $GLOBALS['webhook_test_row']['status'] = 0;
    try {
        WebhookDatabase::loadConfig();
        throw new RuntimeException('Disabled module was accepted.');
    }
    catch (RuntimeException $e) {
        checkWebhook(strpos($e->getMessage(), 'module is disabled') !== false, 'Disabled module did not fail closed.');
    }

    $yaml = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../mediatype/AI_Troubleshooter_mediatypes.yaml'));
    preg_match('/^      script: \|\n(.*?)^      timeout:/ms', $yaml, $match);
    $embedded = preg_replace('/^        /m', '', $match[1] ?? '');
    $sender = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../mediatype/media_type_ai_webhook.js'));
    checkWebhook(rtrim($embedded) === rtrim($sender), 'Imported and standalone media scripts have diverged.');

    echo 'Webhook regression checks passed: '.$checks."\n";
}
finally {
    putenv('AI_WEBHOOK_SECRET');
    putenv('ZABBIX_AI_FRONTEND_ROOT');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($temporary);
}
