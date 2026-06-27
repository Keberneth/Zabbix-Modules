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
            $config = $store->load();
            $rules = array_values((array) ($config['severity_rules'] ?? []));
            $removed = null;
            $kept = [];
            foreach ($rules as $rule) {
                if ((string) ($rule['id'] ?? '') === $id) {
                    $removed = $rule;
                }
                else {
                    $kept[] = $rule;
                }
            }

            // Restore any severities this rule had raised before forgetting it, so
            // problems do not stay stuck at the escalated severity.
            if ($removed !== null) {
                try {
                    (new SeverityEvaluator($store))->revertRule($removed);
                }
                catch (\Throwable $e) {
                    // best effort — never block the delete
                }
            }

            $config['severity_rules'] = $kept;
            $store->save($config);

            $this->jsonResponse(['ok' => true, 'deleted' => $removed !== null ? 1 : 0] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to delete the severity rule.'], 500);
        }
    }
}
