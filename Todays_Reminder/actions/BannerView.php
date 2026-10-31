<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Actions;

require_once __DIR__.'/../helpers/MotdDataProvider.php';
require_once __DIR__.'/../helpers/RequestContext.php';

use CController;
use CControllerResponseData;
use Modules\MessageOfTheDay\Helpers\MotdDataProvider;
use Modules\MessageOfTheDay\Helpers\RequestContext;

class BannerView extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['groupid' => 'string', 'tag' => 'string', 'tag_value' => 'string']);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$filters = ['groupid' => '0', 'tag' => '', 'tag_value' => ''];

		try {
			set_time_limit(60);
			$filters = RequestContext::filters([
				'groupid' => $this->getInput('groupid', '0'),
				'tag' => $this->getInput('tag', ''),
				'tag_value' => $this->getInput('tag_value', '')
			]);

			$provider = new MotdDataProvider();
			$motd = $provider->getData((string) (\CWebUser::$data['userid'] ?? '0'), $filters);
		}
		catch (\InvalidArgumentException $e) {
			$motd = RequestContext::unavailable($filters, $e->getMessage());
		}
		catch (\Throwable $e) {
			error_log('MOTD BannerView: '.$e->getMessage());
			$motd = RequestContext::unavailable($filters, _('Unable to load reminder data. Try refreshing.'));
		}
		$motd = RequestContext::decorate($motd);
		header('Cache-Control: private, no-store');

		$this->setResponse(new CControllerResponseData([
			'title' => _('Today\'s Reminder'),
			'motd' => $motd
		]));
	}
}
