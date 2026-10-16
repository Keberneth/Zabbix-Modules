<?php declare(strict_types = 0);

namespace Modules\PatchCalendar\Includes;

use Zabbix\Widgets\CWidgetForm;

use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldRadioButtonList,
	CWidgetFieldTextBox
};

use Modules\PatchCalendar\Widget;

/**
 * Patch calendar widget form.
 */
class WidgetForm extends CWidgetForm {

	public const AGENDA_DAYS_DEFAULT = 14;
	public const AGENDA_DAYS_MAX = 366;

	public function addFields(): self {
		return $this
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField($this->isTemplateDashboard()
				? null
				: new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField(
				new CWidgetFieldTextBox('maintenance_name', _('Maintenance name'))
			)
			->addField(
				(new CWidgetFieldRadioButtonList('view', _('View'), [
					Widget::VIEW_MONTH => _('Month'),
					Widget::VIEW_WEEK => _('Week'),
					Widget::VIEW_AGENDA => _('Agenda')
				]))->setDefault(Widget::VIEW_MONTH)
			)
			->addField(
				(new CWidgetFieldIntegerBox('agenda_days', _('Agenda days'), 1, self::AGENDA_DAYS_MAX))
					->setDefault(self::AGENDA_DAYS_DEFAULT)
			)
			->addField(
				(new CWidgetFieldRadioButtonList('week_start', _('First day of week'), [
					Widget::WEEK_START_MONDAY => _('Monday'),
					Widget::WEEK_START_SUNDAY => _('Sunday')
				]))->setDefault(Widget::WEEK_START_MONDAY)
			)
			->addField(
				(new CWidgetFieldCheckBox('show_week_numbers', _('Show week numbers')))->setDefault(1)
			)
			->addField(
				new CWidgetFieldServerTimeZone('server_timezone', _('Server time zone'))
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			);
	}
}
