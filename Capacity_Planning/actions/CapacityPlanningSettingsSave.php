<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use CController;
use CControllerResponseData;
use Modules\CapacityPlanning\Lib\Config;
use Modules\CapacityPlanning\Lib\SeriesCache;

require_once __DIR__.'/../lib/Config.php';
require_once __DIR__.'/../lib/SeriesCache.php';

/** Super-admin-only, CSRF-protected global cache settings endpoint. */
final class CapacityPlanningSettingsSave extends CController {
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'cache_enabled' => 'in 0,1',
			'cache_ttl_seconds' => 'in 0,900,1800,3600',
			'clear_cache' => 'in 0,1'
		]);

		if (!$valid) {
			$this->respond([
				'ok' => false,
				'error' => _('Invalid cache settings request.')
			], 400);
		}

		return $valid;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() === USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$current = Config::cacheSettings();
			$enabled = $this->hasInput('cache_enabled')
				? (string) $this->getInput('cache_enabled') === '1'
				: $current['enabled'];
			$ttl = $this->hasInput('cache_ttl_seconds')
				? (int) $this->getInput('cache_ttl_seconds')
				: $current['ttl_seconds'];
			$clear_requested = (string) $this->getInput('clear_cache', '0') === '1';
			if (!$this->hasInput('cache_enabled') && !$this->hasInput('cache_ttl_seconds') && !$clear_requested) {
				throw new \InvalidArgumentException('No cache settings operation was requested.');
			}

			$cache = new SeriesCache($current);
			$clear_result = null;

			// Clear first so a failed clear cannot be reported as a failed save after
			// settings were already committed.
			if ($clear_requested) {
				$clear_result = $cache->clear();
				if (!$clear_result['ok']) {
					$this->respond([
						'ok' => false,
						'error' => _('The shared cache could not be cleared now. Please retry.'),
						'clear_result' => $clear_result
					], 409);
					return;
				}
			}

			$settings_saved = $this->hasInput('cache_enabled') || $this->hasInput('cache_ttl_seconds');
			$saved = $settings_saved
				? Config::saveCacheSettings($enabled, $ttl)
				: $current;
			$cache = new SeriesCache($saved);
			$message = $settings_saved
				? ($clear_result === null
					? _('Capacity Planning cache settings updated.')
					: _('Capacity Planning cache settings updated and the shared cache was cleared.'))
				: _('The Capacity Planning shared cache was cleared.');

			$this->respond([
				'ok' => true,
				'message' => $message,
				'cache' => $saved,
				'cache_status' => $cache->publicStatus(),
				'clear_result' => $clear_result
			]);
		}
		catch (\InvalidArgumentException $e) {
			$this->respond(['ok' => false, 'error' => $e->getMessage()], 400);
		}
		catch (\Throwable $e) {
			error_log('CapacityPlanning SettingsSave: '.$e->getMessage());
			$this->respond([
				'ok' => false,
				'error' => _('An internal error occurred while saving Capacity Planning cache settings.')
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
