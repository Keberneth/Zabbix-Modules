<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Actions;

require_once __DIR__.'/../helpers/MotdDataProvider.php';

use CController;
use CControllerResponseData;
use Modules\MessageOfTheDay\Helpers\MotdDataProvider;

class BannerView extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$motd = [];

		try {
			set_time_limit(300);

			$provider = new MotdDataProvider();
			$motd = $provider->getData((string) (\CWebUser::$data['userid'] ?? '0'));
		}
		catch (\Throwable $e) {
			error_log('MOTD BannerView: '.$e->getMessage());
			$motd = [];
		}

		$this->setResponse(new CControllerResponseData([
			'title' => _('Today\'s Reminder'),
			'motd' => $motd
		]));
	}
}
