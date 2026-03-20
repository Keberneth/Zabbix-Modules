<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

class ChatView extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'eventid' => 'string',
            'hostname' => 'string',
            'problem_summary' => 'string'
        ]);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $config = Config::get();

        $providers = [];

        foreach ($config['providers'] as $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $providers[] = [
                'id' => $provider['id'] ?? '',
                'name' => $provider['name'] ?? ($provider['id'] ?? ''),
                'type' => $provider['type'] ?? '',
                'model' => $provider['model'] ?? '',
                'enabled' => Util::truthy($provider['enabled'] ?? false)
            ];
        }

        $response = new CControllerResponseData([
            'title' => _('AI chat'),
            'providers' => $providers,
            'default_provider_id' => (string) $config['default_chat_provider_id'],
            'initial_eventid' => $this->getInput('eventid', ''),
            'initial_hostname' => $this->getInput('hostname', ''),
            'initial_problem_summary' => $this->getInput('problem_summary', ''),
            'has_zabbix_api' => (ZabbixApiClient::fromConfig($config) !== null),
            'history_limit' => (int) ($config['chat']['max_history_messages'] ?? 12)
        ]);

        $this->setResponse($response);
    }
}

