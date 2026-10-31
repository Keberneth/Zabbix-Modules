<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\SeverityEvaluator;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/SeverityEvaluator.php';

class SeverityRuleDelete extends CController {
    use JsonResponse;

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $id = trim((string) ($_POST['id'] ?? ''));
            if ($id === '') {
                throw new \InvalidArgumentException('Rule id is required.');
            }

            $store = new CorrelationStore();
            $result = (new SeverityEvaluator($store))->deleteRule($id);
            $this->jsonResponse(['ok' => empty($result['pending'])] + $result + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] severity rule deletion pending: '.$e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'The rule could not be fully removed. Any disabled rule and pending recovery records are retained for retry.'], 500);
        }
    }
}
