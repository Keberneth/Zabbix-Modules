<?php

namespace Modules\MessageOfTheDay\Helpers;

final class OverviewRenderer {
	public static function render(array $motd): string {
		ob_start();
		try {
			require __DIR__.'/../partials/motd.content.php';
			return (string) ob_get_clean();
		}
		catch (\Throwable $e) {
			ob_end_clean();
			throw $e;
		}
	}
}
