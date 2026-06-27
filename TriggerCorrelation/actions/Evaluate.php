<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';

/**
 * Unattended evaluation endpoint, called by the receiver template's HTTP-agent
 * item on the Zabbix server (no user session). Authentication is the shared
 * evaluation token, supplied ONLY via an HTTP header so it cannot leak into
 * access logs / browser history / Referer the way a ?token= query string would.
 *
 * The in-UI "Run now" button uses the separate, CSRF-protected RunEval action
 * instead, so this endpoint does not grant access to an interactive session.
 */
class Evaluate extends CController {
    use JsonResponse;

    public function init(): void {
        // Machine-to-machine endpoint: the Zabbix server is not a browser with a
        // CSRF token. Authentication is the shared eval token (checked below).
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        try {
            $store = new CorrelationStore();
            $config = $store->load();
            return CorrelationStore::verifyToken($config['settings'], self::requestToken());
        }
        catch (\Throwable $e) {
            return false;
        }
    }

    protected function doAction(): void {
        try {
            $store = new CorrelationStore();
            $evaluator = new CorrelationEvaluator($store);
            $ruleId = $this->inputString('ruleid');
            $result = $evaluator->evaluate($ruleId !== '' ? $ruleId : null);
            // The response is stored verbatim in the trigger.correlation.eval item,
            // readable by anyone with receiver-host access, so return only coarse
            // status codes here — full detail is kept for the Super-Admin-only
            // RunEval response.
            $this->jsonResponse(['ok' => true, 'result' => self::sanitizeSummary($result)]);
        }
        catch (\Throwable $e) {
            // The response value is stored in a Zabbix item readable by users
            // with receiver-host access, so keep it free of internal detail.
            $this->jsonResponse(['ok' => false, 'error' => 'Evaluation failed.'], 500);
        }
    }

    /**
     * Reduce the evaluation summary to coarse, non-sensitive status codes for the
     * token endpoint (whose response is persisted in a Zabbix item).
     */
    private static function sanitizeSummary(array $result): array {
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

    private static function requestToken(): string {
        $headers = [
            'HTTP_X_TRIGGER_CORRELATION_TOKEN',
            'HTTP_X_TRIGGER_CORRELATION_TOKEN',
            'HTTP_X_CORRELATION_TOKEN'
        ];
        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && is_string($_SERVER[$header]) && $_SERVER[$header] !== '') {
                return trim($_SERVER[$header]);
            }
        }
        return '';
    }
}
