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

/** Host-group typeahead for the severity-escalation "host group" target scope. */
class SearchHostGroups extends CController {
    use JsonResponse;

    public function init(): void {
        // Read-only GET endpoint.
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
            // Prefer the in-process API under the current session; fall back to token.
            $api = ZabbixApiClient::fromFrontendOrConfig($config['settings']);
            $items = $api->searchHostGroups(
                $this->inputString('q'),
                $this->inputInt('limit', 25)
            );
            $this->jsonResponse(['ok' => true, 'items' => $items]);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Host group search failed.', 'items' => []], 502);
        }
    }
}
