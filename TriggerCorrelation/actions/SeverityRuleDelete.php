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
            $removed = null;

            // Under the module-row lock, so this whole-config save cannot undo a
            // concurrent change (e.g. a secret rotation) made on another node.
            CorrelationStore::transaction(function () use ($store, $id, &$removed): void {
                $config = $store->load();
                $kept = [];
                foreach (array_values((array) ($config['severity_rules'] ?? [])) as $rule) {
                    if ((string) ($rule['id'] ?? '') === $id) {
                        $removed = $rule;
                    }
                    else {
                        $kept[] = $rule;
                    }
                }
                $config['severity_rules'] = $kept;
                $store->save($config);
            });

            // Then restore every severity the rule had raised, so problems do not
            // stay stuck at the escalated severity. Best effort: anything left
            // behind is found again by "Repair automatic setup" (it carries the
            // [TC severity] marker).
            if ($removed !== null) {
                try {
                    (new SeverityEvaluator($store))->revertRule($removed);
                }
                catch (\Throwable $e) {
                    // never block the delete
                }
            }

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
