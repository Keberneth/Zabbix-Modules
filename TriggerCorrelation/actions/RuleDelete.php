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

class RuleDelete extends CController {
    use JsonResponse;

    // State-changing POST: framework CSRF stays enabled.

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
            $rules = array_values((array) ($config['rules'] ?? []));
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

            // Resolve the correlation problem (push 0) before forgetting the rule,
            // so it does not stick at the last pushed severity (a false positive).
            if ($removed !== null) {
                try {
                    (new CorrelationEvaluator($store))->clearRule($removed);
                }
                catch (\Throwable $e) {
                    // best effort — never block the delete
                }
            }

            $config['rules'] = $kept;
            $store->save($config);

            $this->jsonResponse(['ok' => true, 'deleted' => $removed !== null ? 1 : 0] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to delete the rule.'], 500);
        }
    }
}
