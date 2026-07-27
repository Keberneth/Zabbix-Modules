<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use CController;
use CControllerResponseData;
use Modules\CapacityPlanning\Lib\Config;
use Modules\CapacityPlanning\Lib\SeriesCache;

require_once __DIR__.'/../lib/Config.php';
require_once __DIR__.'/../lib/SeriesCache.php';

/** Lazily inspect the protected shared cache without slowing normal reports. */
final class CapacityPlanningCacheStatus extends CController {
	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() === USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$settings = Config::cacheSettings();
			$this->respond([
				'ok' => true,
				'cache' => $settings,
				'cache_status' => (new SeriesCache($settings))->publicStatus()
			]);
		}
		catch (\Throwable $e) {
			error_log('CapacityPlanning CacheStatus: '.$e->getMessage());
			$this->respond([
				'ok' => false,
				'error' => _('The protected cache status could not be inspected.')
			], 500);
		}
	}

	private function respond(array $payload, int $http_status = 200): void {
		http_response_code($http_status);
		header('Content-Type: application/json; charset=UTF-8');
		$json = json_encode(
			$payload,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ($json === false) {
			$json = '{"ok":false,"error":"Failed to encode response."}';
		}

		$this->setResponse(
			(new CControllerResponseData(['main_block' => $json]))->disableView()
		);
	}
}
