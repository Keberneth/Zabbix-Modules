<?php declare(strict_types = 0);

/**
 * Patch calendar widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$groupids = array_key_exists('groupids', $data['fields'])
	? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
	: null;

(new CWidgetFormView($data))
	->addField($groupids)
	->addField(array_key_exists('hostids', $data['fields'])
		? (new CWidgetFieldMultiSelectHostView($data['fields']['hostids']))
			->setFilterPreselect([
				'id' => $groupids->getId(),
				'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
				'submit_as' => 'groupid'
			])
		: null
	)
	->addField(
		(new CWidgetFieldTextBoxView($data['fields']['maintenance_name']))
			->setPlaceholder(_('all maintenances'))
			->setFieldHint(makeHelpIcon(
				_('Show only maintenances whose name contains this text, for example "patch".')
			))
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['view'])
	)
	->addField(
		new CWidgetFieldIntegerBoxView($data['fields']['agenda_days'])
	)
	->addField($data['templateid'] === null
		? new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
		: null
	)
	->addFieldset(
		(new CWidgetFormFieldsetCollapsibleView(_('Advanced configuration')))
			->addField(
				new CWidgetFieldRadioButtonListView($data['fields']['week_start'])
			)
			->addField(
				new CWidgetFieldCheckBoxView($data['fields']['show_week_numbers'])
			)
			->addField(
				(new CWidgetFieldSelectView($data['fields']['server_timezone']))
					->setFieldHint(makeHelpIcon(
						_('Zabbix server calculates recurring maintenance periods in its own time zone. Select it here if it differs from the frontend system time zone.')
					))
			)
	)
	->show();
