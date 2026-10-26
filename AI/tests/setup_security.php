<?php declare(strict_types = 1);

require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/SetupHealth.php';

use Modules\AI\Lib\Config;
use Modules\AI\Lib\Crypto;
use Modules\AI\Lib\HttpClient;
use Modules\AI\Lib\NetBoxClient;
use Modules\AI\Lib\SetupHealth;

function checkSetup(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function rejectsTransport(string $url, bool $exception = false): bool {
    try { HttpClient::assertTransport($url, $exception); return false; }
    catch (RuntimeException $e) { return true; }
}
checkSetup(rejectsTransport('http://provider.example/v1'), 'Remote HTTP accepted by default');
checkSetup(!rejectsTransport('http://provider.example/v1', true), 'Approved HTTP rejected');
checkSetup(!rejectsTransport('https://provider.example/v1'), 'HTTPS rejected');
foreach (['http://localhost:11434/api/chat', 'http://127.0.0.1:11434', 'http://[::1]:11434'] as $url) {
    checkSetup(!rejectsTransport($url), 'Local service exception failed');
}
foreach (['file:///etc/passwd', 'ftp://example.com', '//example.com', 'http://localhost.example', 'http://127.0.0.1.example'] as $url) {
    checkSetup(rejectsTransport($url), 'Invalid or misleading service URL accepted');
}
checkSetup(rejectsTransport('file:///etc/passwd', true), 'HTTP exception accepted another protocol');

$config = Config::defaults();
$post = ['providers' => [['id' => 'test', 'name' => 'Local test', 'model' => 'test',
    'endpoint' => 'http://provider.example', 'enabled' => 1, 'allow_insecure_http' => 1]],
    'netbox' => ['url' => 'http://netbox.example', 'allow_insecure_http' => 1]];
$saved = Config::buildFromPost($post, $config);
checkSetup($saved['providers'][0]['allow_insecure_http'] && $saved['netbox']['allow_insecure_http'], 'Exceptions not persisted');
$post['providers'][0]['allow_insecure_http'] = 0;
try { Config::buildFromPost($post, $config); throw new LogicException('Unapproved HTTP saved'); }
catch (RuntimeException $e) {}

putenv('ZABBIX_AI_ENCRYPTION_KEY_FILE');
putenv('ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS');
putenv('ZABBIX_AI_ENCRYPTION_KEY='.str_repeat('a', 64));
Crypto::resetRuntimeKeyCache();
$config['providers'] = [['enabled' => true, 'model' => 'test', 'api_key' => Crypto::encryptRequired('synthetic-secret'), 'endpoint' => 'https://example.com', 'verify_peer' => true]];
$root = sys_get_temp_dir().'/ai-setup-test-'.bin2hex(random_bytes(6));
foreach (['state', 'state/pending', 'reports', 'logs', 'archive'] as $subdir) { mkdir($root.'/'.$subdir, 0700, true); }
$config['security']['state_path'] = $root.'/state';
$config['logging']['path'] = $root.'/logs';
$config['logging']['archive_path'] = $root.'/archive';
$config['reports']['directory'] = $root.'/reports';
try {
    $health = SetupHealth::check($config);
    checkSetup(strpos(json_encode($health), 'synthetic-secret') === false, 'Health output leaked secret');
    foreach ($health['checks'] as $check) {
        if (strpos($check['label'], 'directory') !== false || $check['label'] === 'Provider 1 key') {
            checkSetup($check['status'] === 'ok', 'Working credential/directory reported broken');
        }
    }
    putenv('ZABBIX_AI_ENCRYPTION_KEY='.str_repeat('b', 64));
    Crypto::resetRuntimeKeyCache();
    $health = SetupHealth::check($config);
    $credential = array_values(array_filter($health['checks'], static function(array $check): bool { return $check['label'] === 'Provider 1 key'; }));
    checkSetup($credential[0]['status'] === 'error' && !$health['ready'], 'Wrong replacement key reported healthy');
    $config['security']['state_path'] = $root.'/missing';
    checkSetup(!SetupHealth::check($config)['ready'], 'Missing state directory reported healthy');
    foreach (['state/pending', 'state', 'reports', 'logs', 'archive'] as $subdir) {
        checkSetup(count(glob($root.'/'.$subdir.'/.ai-health-*') ?: []) === 0, 'Health probe file left behind');
    }
}
finally {
    foreach (['state/pending', 'state', 'reports', 'logs', 'archive'] as $subdir) { rmdir($root.'/'.$subdir); }
    rmdir($root);
    putenv('ZABBIX_AI_ENCRYPTION_KEY');
    Crypto::resetRuntimeKeyCache();
}
echo "setup_security: ok\n";
