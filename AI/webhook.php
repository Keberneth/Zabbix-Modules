<?php declare(strict_types = 0);

use Modules\AI\Lib\AuditLogger;
use Modules\AI\Lib\Config;
use Modules\AI\Lib\WebhookDatabase;
use Modules\AI\Lib\WebhookGuard;
use Modules\AI\Lib\WebhookHandler;
use Modules\AI\Lib\WebhookRequestException;
use Modules\AI\Lib\Util;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['ok' => false, 'error' => 'Only POST is accepted.']));
}

require_once __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/WebhookDatabase.php';

try {
    // Bound memory before the database-configured (usually smaller) limit is
    // available. Content-Length is never the only size enforcement.
    $input = fopen('php://input', 'rb');
    try {
        $raw = WebhookGuard::readBody($input, WebhookGuard::HARD_BODY_LIMIT, $_SERVER['CONTENT_LENGTH'] ?? null);
    }
    finally {
        if (is_resource($input)) {
            fclose($input);
        }
    }
    $decoded = json_decode($raw, true, 32);
    if (!is_array($decoded)) {
        throw new WebhookRequestException('Invalid JSON payload.', 400);
    }

    $config = WebhookDatabase::loadConfig();
    if (strlen($raw) > WebhookGuard::bodyLimit($config)) {
        throw new WebhookRequestException('Webhook request body is too large.', 413);
    }

    $result = WebhookHandler::process($config, $decoded);
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'posted_chunks' => (int) ($result['posted_chunks'] ?? 0),
        'reply' => (string) ($result['reply'] ?? ''),
        'result' => (string) ($result['result'] ?? ''),
        'duplicate' => !empty($result['duplicate'])
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    $initialized = isset($config) && is_array($config);
    $message = Util::truncate(Util::sanitizeSensitiveTextForDisplay($e->getMessage()), 1000);
    AuditLogger::log($initialized ? $config : Config::mergeWithDefaults([]), 'errors', [
        'event' => 'webhook.failed', 'source' => 'standalone.webhook', 'status' => 'error', 'message' => $message
    ]);

    $status = $e instanceof WebhookRequestException ? $e->httpStatus() : ($initialized ? 400 : 503);
    if (!$initialized && !($e instanceof WebhookRequestException)) {
        error_log('Zabbix AI webhook initialization failed: '.$message);
        $message = 'Zabbix webhook initialization failed; check the server log and frontend database/Vault configuration.';
    }
    http_response_code($status);
    if ($status === 429) {
        header('Retry-After: 60');
    }
    echo json_encode(['ok' => false, 'error' => $message]);
}
