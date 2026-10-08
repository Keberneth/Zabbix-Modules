<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use CControllerResponseData;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

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
        $config = $store->publicConfig();
        // Shown as the placeholder of the (optional) Evaluation URL setting.
        $config['settings']['eval_url_effective'] = ReceiverProvisioner::effectiveEvalUrl((array) $config['settings']);

        $this->setResponse(new CControllerResponseData([
            'title' => _('Trigger Correlation'),
            'config' => $config
        ]));
    }
}
