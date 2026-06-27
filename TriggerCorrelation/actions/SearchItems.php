<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';

class SearchItems extends CController {
    use JsonResponse;

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
        try {
            $store = new CorrelationStore();
            $config = $store->load();
            $api = ZabbixApiClient::fromFrontendOrConfig($config['settings']);
            $items = $api->searchItems(
                $this->inputString('q'),
                $this->inputString('hostid'),
                $this->inputString('trapper_only', '1') !== '0',
                $this->inputInt('limit', 50)
            );
            $this->jsonResponse(['ok' => true, 'items' => $items]);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Item search failed.', 'items' => []], 502);
        }
    }
}
