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
            // Test the exact credentials used by the unattended evaluator. A
            // working Super Admin session does not prove the saved token works.
            $settings = (array) ($config['settings'] ?? []);
            if (trim((string) ($settings['api_url'] ?? '')) === '' || CorrelationStore::apiToken($settings) === '') {
                throw new \InvalidArgumentException('Save an API URL and token first. This test checks the saved runtime credentials.');
            }
            $api = ZabbixApiClient::fromConfig($settings);
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
                'transport' => 'saved runtime API token',
                'runtime_token_tested' => true,
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
