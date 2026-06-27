<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';

class RulesGet extends CController {
    use JsonResponse;

    public function init(): void {
        // Read-only GET endpoint; no state change, so CSRF is not applicable.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $store = new CorrelationStore();
            $this->jsonResponse(['ok' => true] + $store->publicConfig());
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to load configuration.'], 500);
        }
    }
}
