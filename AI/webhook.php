<?php declare(strict_types = 0);

use Modules\AI\Lib\AuditLogger;
use Modules\AI\Lib\Config;
use Modules\AI\Lib\WebhookHandler;
use Modules\AI\Lib\Util;

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Only POST is accepted.']));
}

require_once __DIR__.'/lib/bootstrap.php';

$zabbix_conf_paths = [
    '/etc/zabbix/web/zabbix.conf.php',
    '/etc/zabbix/zabbix.conf.php',
    dirname(__DIR__, 2).'/conf/zabbix.conf.php'
];

$DB = null;
if (!defined('IMAGE_FORMAT_PNG')) { define('IMAGE_FORMAT_PNG', 0); }
if (!defined('IMAGE_FORMAT_JPEG')) { define('IMAGE_FORMAT_JPEG', 1); }
if (!defined('IMAGE_FORMAT_TEXT')) { define('IMAGE_FORMAT_TEXT', 2); }
if (!defined('IMAGE_FORMAT_GIF')) { define('IMAGE_FORMAT_GIF', 3); }

foreach ($zabbix_conf_paths as $conf_path) {
    if (file_exists($conf_path)) {
        require $conf_path;
        break;
    }
}

if (!is_array($DB) || empty($DB['DATABASE'])) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Cannot locate zabbix.conf.php or DB config is empty.']));
}

try {
    $pdo = ai_webhook_connect($DB);
    $config = WebhookHandler::loadConfigFromPdo($pdo);

    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $result = WebhookHandler::process($config, $decoded);
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'posted_chunks' => (int) ($result['posted_chunks'] ?? 0),
        'reply' => (string) ($result['reply'] ?? ''),
        'result' => (string) ($result['result'] ?? '')
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    if (!isset($config) || !is_array($config)) {
        $config = Config::mergeWithDefaults([]);
    }

    AuditLogger::log($config, 'errors', [
        'event' => 'webhook.failed',
        'source' => 'standalone.webhook',
        'status' => 'error',
        'message' => Util::truncate($e->getMessage(), 1000)
    ]);

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function ai_webhook_connect(array $DB): PDO {
    $type = strtoupper($DB['TYPE'] ?? 'MYSQL');
    $host = $DB['SERVER'] ?? 'localhost';
    $port = $DB['PORT'] ?? 0;
    $dbname = $DB['DATABASE'] ?? '';
    $user = $DB['USER'] ?? '';
    $pass = $DB['PASSWORD'] ?? '';
    $schema = $DB['SCHEMA'] ?? '';

    if ($type === 'POSTGRESQL') {
        $dsn = 'pgsql:host='.$host.';dbname='.$dbname;
        if ($port) {
            $dsn .= ';port='.$port;
        }
    }
    else {
        $dsn = 'mysql:host='.$host.';dbname='.$dbname.';charset=utf8';
        if ($port) {
            $dsn .= ';port='.$port;
        }
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);

    if ($type === 'POSTGRESQL' && $schema !== '') {
        $pdo->exec('SET search_path TO '.addcslashes($schema, "'\\"));
    }

    return $pdo;
}
