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
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$provider = new MotdDataProvider();
		$payload = [
			'ok' => true,
			'data' => $provider->getData()
		];

		header('Content-Type: application/json; charset=UTF-8');
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			]))->disableView()
		);
	}
}
