<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\Util;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';

class ApiTest extends CController {
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
            // apiinfo.version is not always exposed over the in-process facade;
            // keep it best-effort so a working host.get still reports success.
            $version = '';
            try {
                $version = $api->version();
            }
            catch (\Throwable $e) {
                $version = 'n/a';
            }
            $hostCount = $api->hostCount();
            $this->jsonResponse([
                'ok' => true,
                'transport' => $api->isFrontend() ? 'in-process frontend API' : 'HTTP token',
                'api_url' => $api->getUrl(),
                'version' => $version,
                'host_count' => $hostCount
            ]);
        }
        catch (\Throwable $e) {
            // Super-admin-only endpoint, so a precise message helps configuration.
            $this->jsonResponse(['ok' => false, 'error' => Util::truncate($e->getMessage(), 400)], 502);
        }
    }
}
