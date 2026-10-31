<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

/** One escaped renderer for the initial page, asynchronous results and no-JavaScript fallback. */
final class ReportRenderer {
	public static function render(array $data): string {
		ob_start();
		try {
			require __DIR__.'/../partials/slareport.report.php';
			return (string) ob_get_clean();
		}
		catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
	}
}
