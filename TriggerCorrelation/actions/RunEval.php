<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\SeverityEvaluator;
use Modules\TriggerCorrelation\Lib\Util;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';
require_once dirname(__DIR__).'/lib/SeverityEvaluator.php';

/**
 * Interactive "Run evaluation now" endpoint used by the module page. Runs the
 * same evaluator as the unattended Evaluate endpoint, but under an authenticated
 * Super Admin session with normal framework CSRF protection (the UI submits the
 * _csrf_token), so it does not need — and does not accept — the shared token.
 *
 *   kind=severity            → run severity-escalation rule(s)
 *   ruleid set (no kind)     → run that one correlation rule
 *   nothing                  → the top "Run evaluation now": both feature sets
 */
class RunEval extends CController {
    use JsonResponse;

    // No disableCsrfValidation(): CSRF-protected POST from the module page.

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $store = new CorrelationStore();
            $ruleId = trim((string) ($_POST['ruleid'] ?? ''));
            $kind = trim((string) ($_POST['kind'] ?? ''));

            if ($kind === 'severity') {
                $result = (new SeverityEvaluator($store))->evaluate($ruleId !== '' ? $ruleId : null);
            }
            elseif ($ruleId !== '') {
                $result = (new CorrelationEvaluator($store))->evaluate($ruleId);
            }
            else {
                $result = (new CorrelationEvaluator($store))->evaluate(null);
                // Severity escalation is isolated: a failure there must not fail the
                // correlation run.
                try {
                    $result['severity'] = (new SeverityEvaluator($store))->evaluate(null);
                }
                catch (\Throwable $e) {
                    $result['severity'] = ['type' => 'severity', 'error' => Util::truncate($e->getMessage(), 300)];
                }
            }

            $this->jsonResponse(['ok' => true, 'result' => $result] + $store->publicConfig());
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => Util::truncate($e->getMessage(), 400)], 500);
        }
    }
}
