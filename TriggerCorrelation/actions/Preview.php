<?php
declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\SeverityEvaluator;
use Modules\TriggerCorrelation\Lib\RuleDefinition;
use Modules\TriggerCorrelation\Lib\JsonResponse;

require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';
require_once dirname(__DIR__).'/lib/SeverityEvaluator.php';
require_once dirname(__DIR__).'/lib/RuleDefinition.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';

/** POST accepts an unsaved draft. The evaluation token is used for every read. */
class Preview extends CController {
    use JsonResponse;
    protected function checkInput(): bool { return true; }
    protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_SUPER_ADMIN; }

    protected function doAction(): void {
        try {
            $kind = (string) ($_POST['kind'] ?? 'correlation');
            $draft = RuleDefinition::normalize($this->postJsonField('rule'), $kind);
            $store = new CorrelationStore();
            $config = $store->load();
            // Runtime is trusted only from storage, never from the submitted draft.
            foreach ((array) ($config[$kind === 'severity' ? 'severity_rules' : 'rules'] ?? []) as $saved) {
                if ((string) ($saved['id'] ?? '') === $draft['id']) {
                    foreach (['last_state', 'pending_state', 'pending_since', 'last_evaluated', 'last_successful', 'source_signature', 'source_members', 'applied'] as $key) {
                        if (array_key_exists($key, $saved)) { $draft[$key] = $saved[$key]; }
                    }
                    // Disabling an existing automatic rule clears its provisioned
                    // output; an unprovisioned draft has no output to clear.
                    if ($kind === 'correlation' && !empty($draft['output']['receiver_auto'])
                            && !empty($saved['output']['receiver_auto'])) {
                        $draft['output']['receiver_hostid'] = (string) ($saved['output']['receiver_hostid'] ?? '');
                    }
                    break;
                }
            }
            $evaluator = $kind === 'severity' ? new SeverityEvaluator($store) : new CorrelationEvaluator($store);
            $this->jsonResponse(['ok' => true, 'result' => $evaluator->preview($draft)]);
        }
        catch (\Throwable $e) { $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400); }
    }
}
