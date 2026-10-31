<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Actions;

require_once __DIR__.'/../helpers/MotdDataProvider.php';
require_once __DIR__.'/../helpers/RequestContext.php';
require_once __DIR__.'/../helpers/OverviewRenderer.php';

use CController;
use CControllerResponseData;
use Modules\MessageOfTheDay\Helpers\MotdDataProvider;
use Modules\MessageOfTheDay\Helpers\RequestContext;
use Modules\MessageOfTheDay\Helpers\OverviewRenderer;

class BannerData extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'fingerprint' => 'string',
			'overview' => 'in 0,1',
			'groupid' => 'string',
			'tag' => 'string',
			'tag_value' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			set_time_limit(60);
			$filters = RequestContext::filters([
				'groupid' => $this->getInput('groupid', '0'),
				'tag' => $this->getInput('tag', ''),
				'tag_value' => $this->getInput('tag_value', '')
			]);

			$provider = new MotdDataProvider();
			$data = RequestContext::decorate($provider->getData((string) (\CWebUser::$data['userid'] ?? '0'), $filters));

			$client_fp = $this->hasInput('fingerprint') ? (string) $this->getInput('fingerprint') : '';
			if ($client_fp !== '' && $client_fp === (string) ($data['fingerprint'] ?? '')) {
				$payload = [
					'ok' => true,
					'not_modified' => true,
					'fingerprint' => (string) ($data['fingerprint'] ?? '')
				];
				foreach (['generated_at', 'generated_at_text', 'timezone', 'checked_at', 'status', 'issues', 'ui', 'preference_key', 'idle_timeout'] as $key) {
					$payload[$key] = $data[$key] ?? null;
				}
			}
			else {
				$payload = [
					'ok' => true,
					'data' => $data
				];
				if ((int) $this->getInput('overview', 0) === 1) {
					$payload['html'] = OverviewRenderer::render($data);
				}
			}

			$json = json_encode(
				$payload,
				JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			if ($json === false) {
				$json = '{"ok":false,"error":"Failed to encode response."}';
			}
		}
		catch (\InvalidArgumentException $e) {
			http_response_code(400);
			$json = json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
		}
		catch (\Throwable $e) {
			error_log('MOTD BannerData: '.$e->getMessage());
			http_response_code(500);
			$json = '{"ok":false,"error":"An internal error occurred while building the reminder."}';
		}

		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		$this->setResponse(
			(new CControllerResponseData([
				'main_block' => $json
			]))->disableView()
		);
	}
}
