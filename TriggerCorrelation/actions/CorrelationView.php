<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use CControllerResponseData;
use Modules\TriggerCorrelation\Lib\CorrelationStore;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';

class CorrelationView extends CController {
    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $store = new CorrelationStore();

        $this->setResponse(new CControllerResponseData([
            'title' => _('Trigger Correlation'),
            'config' => $store->publicConfig()
        ]));
    }
}
