<?php declare(strict_types = 0);

namespace Modules\PatchCalendar\Includes;

use CTimezoneHelper;

use Zabbix\Widgets\Fields\CWidgetFieldSelect;

/**
 * Time zone select that stores the time zone name. Unlike CWidgetFieldTimeZone it has no "Local default" option,
 * because the browser's time zone has nothing to do with how Zabbix server evaluates maintenance periods.
 */
class CWidgetFieldServerTimeZone extends CWidgetFieldSelect {

	public function __construct(string $name, string $label) {
		parent::__construct($name, $label, [
			ZBX_DEFAULT_TIMEZONE => CTimezoneHelper::getTitle(CTimezoneHelper::getSystemTimezone(),
				_('System default')
			)
		] + CTimezoneHelper::getList());

		$this
			->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR)
			->setDefault(ZBX_DEFAULT_TIMEZONE);
	}
}
