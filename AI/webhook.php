<?php declare(strict_types = 0);
/**
 * Standalone AI webhook entry point.
 *
 * This file bypasses zabbix.php (which requires a frontend session) so that
 * the Zabbix media-type can call the AI webhook without authentication against
 * the frontend.  It reads the module config straight from the database and
 * reuses the module's own library classes for everything else.
 *
 * Deployment:
 *   /usr/share/zabbix/modules/AI/webhook.php   (this file)
 *
 * Nginx location (add inside the Zabbix server block):
 *   location = /ai-webhook {
 *       alias /usr/share/zabbix/modules/AI/webhook.php;
 *       include fastcgi_params;
 *       fastcgi_param SCRIPT_FILENAME /usr/share/zabbix/modules/AI/webhook.php;
 *       fastcgi_pass unix:/run/php-fpm/zabbix.sock;
 *   }
 *
 * Then point the media-type URL at:
 *   https://zabbix.kt4-iver.se/ai-webhook
 */

// ── request gate ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Only POST is accepted.']));
}

// ── load module libraries (no Zabbix framework dependency) ──────────────────
require_once __DIR__.'/lib/bootstrap.php';

use Modules\AI\Lib\Config;
use Modules\AI\Lib\NetBoxClient;
use Modules\AI\Lib\PromptBuilder;
use Modules\AI\Lib\ProviderClient;
use Modules\AI\Lib\Util;
use Modules\AI\Lib\ZabbixApiClient;
use Modules\AI\Lib\HttpClient;

// ── read Zabbix DB config ───────────────────────────────────────────────────
$zabbix_conf_paths = [
    '/etc/zabbix/web/zabbix.conf.php',
    '/etc/zabbix/zabbix.conf.php',
    dirname(__DIR__, 2).'/conf/zabbix.conf.php'
];

$DB = null;

// zabbix.conf.php references constants defined by the Zabbix frontend framework.
// Define any missing ones so the file can be loaded standalone.
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

// ── connect to the database ─────────────────────────────────────────────────
try {
    $pdo = ai_webhook_connect($DB);
}
catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'DB connection failed: '.$e->getMessage()]));
}

// ── read module config ──────────────────────────────────────────────────────
try {
    $config = ai_webhook_load_config($pdo);
}
catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'Config load failed: '.$e->getMessage()]));
}

// ── process the webhook ─────────────────────────────────────────────────────
try {
    if (!Util::truthy($config['webhook']['enabled'] ?? false)) {
        throw new RuntimeException('AI webhook is disabled.');
    }

    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $payload = ai_webhook_normalize_payload($decoded);
    ai_webhook_validate_secret($config, $payload);

    if (Util::truthy($config['webhook']['skip_resolved'] ?? false)
            && (string) ($payload['event_value'] ?? '1') === '0') {
        http_response_code(200);
        exit(json_encode(['ok' => true, 'result' => 'resolved event ignored']));
    }

    $provider = Config::getProvider($config, '', 'webhook');

    if ($provider === null) {
        throw new RuntimeException('No provider is configured for webhook use.');
    }

    $context = [];
    $zabbix_api = ZabbixApiClient::fromConfig($config);

    if (!empty($payload['hostname']) && Util::truthy($config['webhook']['include_os_hint'] ?? false)
            && $zabbix_api !== null) {
        $context['os_type'] = $zabbix_api->getOsTypeByHostname($payload['hostname']);
    }

    $netbox = NetBoxClient::fromConfig($config);

    if (!empty($payload['hostname']) && Util::truthy($config['webhook']['include_netbox'] ?? false)
            && $netbox !== null) {
        $context['netbox_info'] = $netbox->getContextForHostname($payload['hostname']);
    }

    $system_prompt = PromptBuilder::buildSystemPrompt($config, [
        'mode' => 'webhook automation',
        'response_style' => 'Focus on safe first-line troubleshooting guidance and keep the answer operational.'
    ]);

    $user_prompt = PromptBuilder::buildWebhookUserPrompt($payload, $context);

    $reply = ProviderClient::chat($provider, [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $user_prompt]
    ], (float) ($config['chat']['temperature'] ?? 0.2));

    $posted_chunks = 0;

    if (Util::truthy($config['webhook']['add_problem_update'] ?? false)) {
        if (empty($payload['eventid'])) {
            throw new RuntimeException('Event ID is required to post an update back to Zabbix.');
        }

        if ($zabbix_api === null) {
            throw new RuntimeException('Zabbix API token is not configured in AI settings.');
        }

        $chunks = $zabbix_api->addProblemComment(
            (string) $payload['eventid'],
            $reply,
            (int) ($config['webhook']['problem_update_action'] ?? 4),
            (int) ($config['webhook']['comment_chunk_size'] ?? 1900)
        );

        $posted_chunks = count($chunks);
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'posted_chunks' => $posted_chunks,
        'reply' => $reply
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Helper functions
// ═══════════════════════════════════════════════════════════════════════════

function ai_webhook_connect(array $DB): PDO {
    $type   = strtoupper($DB['TYPE'] ?? 'MYSQL');
    $host   = $DB['SERVER'] ?? 'localhost';
    $port   = $DB['PORT'] ?? 0;
    $dbname = $DB['DATABASE'] ?? '';
    $user   = $DB['USER'] ?? '';
    $pass   = $DB['PASSWORD'] ?? '';
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

function ai_webhook_load_config(PDO $pdo): array {
    $module_id = Config::MODULE_ID;

    $stmt = $pdo->prepare('SELECT config FROM module WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $module_id]);
    $row = $stmt->fetch();

    if (!$row || empty($row['config'])) {
        return Config::mergeWithDefaults([]);
    }

    $decoded = json_decode($row['config'], true);

    return Config::mergeWithDefaults(is_array($decoded) ? $decoded : []);
}

function ai_webhook_normalize_payload(array $payload): array {
    if (isset($payload['message']) && is_string($payload['message'])) {
        $message_payload = json_decode($payload['message'], true);

        if (is_array($message_payload)) {
            $payload = array_replace($payload, $message_payload);
        }
    }

    $normalized = [
        'eventid'     => Util::cleanString($payload['eventid'] ?? $payload['event_id'] ?? '', 128),
        'event_value' => Util::cleanString($payload['event_value'] ?? $payload['value'] ?? '1', 16),
        'trigger_name' => Util::cleanMultiline(
            $payload['trigger_name'] ?? $payload['problem_name'] ?? $payload['subject'] ?? $payload['name'] ?? '',
            2000
        ),
        'hostname' => Util::cleanString(
            $payload['hostname'] ?? $payload['host'] ?? $payload['host_name'] ?? '',
            255
        ),
        'severity' => Util::cleanString(
            $payload['severity'] ?? $payload['severity_name'] ?? $payload['trigger_severity'] ?? '',
            128
        ),
        'opdata'    => Util::cleanMultiline($payload['opdata'] ?? $payload['operational_data'] ?? '', 4000),
        'event_url' => Util::cleanUrl($payload['event_url'] ?? $payload['url'] ?? ''),
        'shared_secret' => Util::cleanString($payload['shared_secret'] ?? '', 512)
    ];

    $event_tags = $payload['event_tags'] ?? $payload['tags'] ?? $payload['event_tags_json'] ?? [];
    $normalized['event_tags_text'] = ai_webhook_normalize_tags($event_tags);

    return $normalized;
}

function ai_webhook_normalize_tags($event_tags): string {
    if (is_string($event_tags)) {
        $event_tags = trim($event_tags);

        if ($event_tags === '' || preg_match('/^\{[A-Z0-9_.]+\}$/', $event_tags)) {
            return '';
        }

        $trimmed = ltrim($event_tags);

        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            $decoded = json_decode($event_tags, true);

            if (is_array($decoded)) {
                return Util::formatTags($decoded);
            }
        }

        return Util::cleanMultiline($event_tags, 4000);
    }

    return Util::formatTags($event_tags);
}

function ai_webhook_validate_secret(array $config, array $payload): void {
    $expected = Config::resolveSecret(
        $config['webhook']['shared_secret'] ?? '',
        $config['webhook']['shared_secret_env'] ?? ''
    );

    if ($expected === '') {
        return;
    }

    $provided = trim((string) ($_SERVER['HTTP_X_AI_WEBHOOK_SECRET'] ?? $payload['shared_secret'] ?? ''));

    if ($provided === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Invalid webhook shared secret.');
    }
}