<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Actions;

require_once __DIR__.'/../helpers/MotdDataProvider.php';

use CController;
use CControllerResponseData;
use Modules\MessageOfTheDay\Helpers\MotdDataProvider;

class BannerData extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'fingerprint' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			set_time_limit(300);

			$provider = new MotdDataProvider();
			$data = $provider->getData((string) (\CWebUser::$data['userid'] ?? '0'));

			$client_fp = $this->hasInput('fingerprint') ? (string) $this->getInput('fingerprint') : '';
			if ($client_fp !== '' && $client_fp === (string) ($data['fingerprint'] ?? '')) {
				$payload = [
					'ok' => true,
					'not_modified' => true,
					'fingerprint' => (string) ($data['fingerprint'] ?? '')
				];
			}
			else {
				$payload = [
					'ok' => true,
					'data' => $data
				];
			}

			$json = json_encode(
				$payload,
				JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			if ($json === false) {
				$json = '{"ok":false,"error":"Failed to encode response."}';
			}
		}
		catch (\Throwable $e) {
			error_log('MOTD BannerData: '.$e->getMessage());
			$json = '{"ok":false,"error":"An internal error occurred while building the reminder."}';
		}

		header('Content-Type: application/json; charset=UTF-8');
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => $json
			]))->disableView()
		);
	}
}
