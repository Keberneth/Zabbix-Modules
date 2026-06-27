<?php

declare(strict_types=1);

use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\SeverityEvaluator;

/**
 * Standalone evaluation endpoint for the Trigger Correlation module.
 *
 * Zabbix does not route module frontend actions (zabbix.php?action=...) to
 * anonymous callers when guest frontend access is disabled — and the Zabbix
 * server's HTTP-agent item is anonymous — so it returns "Page not found" before
 * the in-frontend eval action's token check ever runs. This script avoids that:
 * it connects to the Zabbix database directly (using the frontend's own
 * zabbix.conf.php, no login required), verifies the shared evaluation token from
 * the X-Trigger-Correlation-Token header, and runs the same evaluator.
 *
 * Point the receiver template macro {$TRIGGER.CORRELATION.URL} at this file, e.g.
 *   https://your-zabbix/modules/TriggerCorrelation/eval.php
 *
 * Authentication is the Evaluation shared secret (Settings) supplied ONLY via the
 * X-Trigger-Correlation-Token header. The response is JSON.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__.'/lib/Util.php';
require_once __DIR__.'/lib/Crypto.php';
require_once __DIR__.'/lib/CorrelationStore.php';
require_once __DIR__.'/lib/ZabbixApiClient.php';
require_once __DIR__.'/lib/CorrelationEvaluator.php';
require_once __DIR__.'/lib/SeverityEvaluator.php';

function tc_eval_respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function tc_eval_token(): string {
    foreach (['HTTP_X_TRIGGER_CORRELATION_TOKEN', 'HTTP_X_CORRELATION_TOKEN'] as $header) {
        if (isset($_SERVER[$header]) && is_string($_SERVER[$header]) && $_SERVER[$header] !== '') {
            return trim($_SERVER[$header]);
        }
    }
    return '';
}

/** Coarse, non-sensitive summary — this response is also stored in a Zabbix item. */
function tc_eval_sanitize(array $result): array {
    $rules = [];
    foreach ((array) ($result['rules'] ?? []) as $rule) {
        $status = 'ok';
        if (!empty($rule['error'])) {
            $status = !empty($rule['pending']) ? 'discovery_pending' : 'error';
        }
        $rules[] = [
            'id' => (string) ($rule['id'] ?? ''),
            'name' => (string) ($rule['name'] ?? ''),
            'state' => (int) ($rule['state'] ?? 0),
            'matched' => (bool) ($rule['matched'] ?? false),
            'status' => $status
        ];
    }
    $discovery = $result['discovery'] ?? null;
    return [
        'evaluated_at' => (string) ($result['evaluated_at'] ?? ''),
        'rules_total' => (int) ($result['rules_total'] ?? 0),
        'rules_evaluated' => (int) ($result['rules_evaluated'] ?? 0),
        'discovery_ok' => is_array($discovery) ? (bool) ($discovery['ok'] ?? true) : true,
        'persisted' => empty($result['persist_error']),
        'rules' => $rules
    ];
}

/** Coarse, non-sensitive summary of a severity-escalation run (no rule ids/names). */
function tc_eval_sanitize_severity(array $result): array {
    $rules = [];
    foreach ((array) ($result['rules'] ?? []) as $rule) {
        $rules[] = [
            'state' => (int) ($rule['state'] ?? 0),
            'active' => (bool) ($rule['active'] ?? false),
            'escalated' => (int) ($rule['escalated'] ?? 0),
            'restored' => (int) ($rule['restored'] ?? 0),
            'targets' => (int) ($rule['targets_count'] ?? 0),
            'status' => !empty($rule['error']) ? 'error' : 'ok'
        ];
    }
    return [
        'rules_total' => (int) ($result['rules_total'] ?? 0),
        'rules_evaluated' => (int) ($result['rules_evaluated'] ?? 0),
        'persisted' => empty($result['persist_error']),
        'rules' => $rules
    ];
}

/**
 * Connect to the Zabbix database using the frontend's own configuration.
 * The path discovery, image-constant predefinition and PDO build live in
 * CorrelationStore::connectStandalone() so the Settings self-check can reproduce
 * the exact same connection in-process and surface the real error to an admin.
 */
function tc_eval_pdo(): \PDO {
    return CorrelationStore::connectStandalone();
}

try {
    try {
        $pdo = tc_eval_pdo();
    }
    catch (\Throwable $e) {
        // The database/config layer is the most common deployment failure: the web
        // user cannot locate zabbix.conf.php or cannot connect. Log the real cause
        // server-side and return a non-sensitive stage label so the Settings
        // self-check can point at the database rather than the rule logic.
        error_log('[TriggerCorrelation] eval.php database connect failed: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        tc_eval_respond(500, ['ok' => false, 'error' => 'Database connection failed.', 'stage' => 'database']);
    }

    CorrelationStore::useDatabase($pdo);

    $store = new CorrelationStore();
    $config = $store->load();

    if (!CorrelationStore::verifyToken((array) ($config['settings'] ?? []), tc_eval_token())) {
        tc_eval_respond(401, ['ok' => false, 'error' => 'Invalid evaluation token.']);
    }

    $ruleId = (isset($_GET['ruleid']) && is_string($_GET['ruleid'])) ? trim($_GET['ruleid']) : '';
    $evaluator = new CorrelationEvaluator($store);
    $result = $evaluator->evaluate($ruleId !== '' ? $ruleId : null);

    $response = ['ok' => true, 'result' => tc_eval_sanitize($result)];

    // The same heartbeat also drives severity escalation (the second feature).
    // Only on a full run (no specific correlation rule id) and fully isolated so a
    // severity failure can never break the correlation response.
    if ($ruleId === '') {
        try {
            $severity = (new SeverityEvaluator($store))->evaluate(null);
            $response['severity'] = tc_eval_sanitize_severity($severity);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] eval.php severity escalation failed: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            $response['severity'] = ['error' => true];
        }
    }

    tc_eval_respond(200, $response);
}
catch (\Throwable $e) {
    error_log('[TriggerCorrelation] eval.php failed: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    tc_eval_respond(500, ['ok' => false, 'error' => 'Evaluation failed.', 'stage' => 'evaluate']);
}
