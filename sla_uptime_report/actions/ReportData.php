<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use CControllerResponseData;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;
use Modules\SlaUptimeReport\Helpers\ReportRenderer;

require_once __DIR__.'/ReportView.php';
require_once __DIR__.'/../helpers/ReportRenderer.php';

/** Read-only report endpoint. Every request resolves access and report policy again. */
class ReportData extends ReportView {
	protected function checkInput(): bool {
		if (!$this->validateInput(self::inputFields() + ['refresh' => 'in 0,1'])) {
			$this->respond(['error' => _('The report filters are invalid. Adjust them and try again.')]);
			return false;
		}
		return true;
	}

	protected function doAction(): void {
		set_time_limit(300);
		// Request authorization has already completed. Long reads must not keep a native session locked.
		if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
		try {
			$filter = ReportDataHelper::normalizeFilter($this->readFilterInput());
			[$from, $to] = ReportDataHelper::resolveDateRange($filter);
			$helper = new ReportDataHelper((bool) $this->getInput('refresh', 0));
			$report = $helper->buildReport($filter, $from, $to);
			$this->respond([
				'html' => ReportRenderer::render(['title' => _('SLA & Uptime Report'), 'helper' => $helper,
					'filter' => $filter, 'report' => $report, 'time_from' => $from, 'time_to' => $to, 'async' => false]),
				'filter' => $filter, 'cache' => $report['cache'] ?? []
			]);
		}
		catch (\InvalidArgumentException $e) {
			$this->respond(['error' => _('The report filters are invalid. Adjust them and try again.')]);
		}
		catch (\Throwable $e) {
			error_log('SLA Uptime Report data: '.$e->getMessage());
			$this->respond(['error' => _('The report could not be loaded. Try again or check the server log.')]);
		}
	}

	private function respond(array $payload): void {
		header('Cache-Control: no-store, private');
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($payload,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]));
	}
}
