<?php declare(strict_types = 0);

namespace Modules\PatchCalendar;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public const VIEW_MONTH = 0;
	public const VIEW_WEEK = 1;
	public const VIEW_AGENDA = 2;

	public const WEEK_START_SUNDAY = 0;
	public const WEEK_START_MONDAY = 1;

	public function getDefaultName(): string {
		return _('Patch calendar');
	}

	public function getTranslationStrings(): array {
		return [
			'class.widget.js' => [
				'+%1$s more' => _('+%1$s more')
			]
		];
	}
}
