<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$helper = $data['helper'];

$build_multi_select = static function (string $name, string $id, array $options, array $selected_ids, string $value_key, string $label_key): CTag {
	$selected_lookup = array_flip(array_map('strval', $selected_ids));

	$select = (new CTag('select', true))
		->setId($id)
		->setAttribute('name', $name.'[]')
		->setAttribute('multiple', 'multiple')
		->setAttribute('size', (string) min(10, max(6, count($options))))
		->setAttribute('class', 'slareport-multiselect');

	foreach ($options as $option) {
		$value = (string) ($option[$value_key] ?? '');
		$label = (string) ($option[$label_key] ?? $value);

		$option_tag = (new CTag('option', true, $label))
			->setAttribute('value', $value);

		if (isset($selected_lookup[$value])) {
			$option_tag->setAttribute('selected', 'selected');
		}

		$select->addItem($option_tag);
	}

	return $select;
};

$download_url = static function (string $format) use ($data): string {
	return (new CUrl('zabbix.php'))
		->setArgument('action', 'slareport.report.download')
		->setArgument('format', $format)
		->setArgument('filter_mode', $data['filter']['mode'])
		->setArgument('filter_month', $data['filter']['month'])
		->setArgument('filter_date_from', $data['filter']['date_from'])
		->setArgument('filter_date_to', $data['filter']['date_to'])
		->setArgument('filter_days_back', $data['filter']['days_back'])
		->setArgument('filter_hostgroupids', $data['filter']['hostgroupids'])
		->setArgument('filter_slaids', $data['filter']['slaids'])
		->setArgument('filter_exclude_disabled', $data['filter']['exclude_disabled'])
		->getUrl();
};

$mode_radio = (new CRadioButtonList('filter_mode', $data['filter']['mode']))
	->addValue(_('Previous month'), 'prev_month')
	->addValue(_('Specific month'), 'specific_month')
	->addValue(_('Custom range'), 'custom_range')
	->addValue(_('Days back'), 'days_back')
	->setModern(true);

$hostgroup_select = $build_multi_select(
	'filter_hostgroupids',
	'filter_hostgroupids',
	$data['hostgroup_options'],
	$data['filter']['hostgroupids'],
	'groupid',
	'name'
);

$sla_select = $build_multi_select(
	'filter_slaids',
	'filter_slaids',
	$data['sla_options'],
	$data['filter']['slaids'],
	'slaid',
	'name'
);

$filter_grid = (new CFormGrid())
	->addItem([
		new CLabel(_('Period mode')),
		new CFormField($mode_radio)
	])
	->addItem([
		new CLabel(_('Specific month'), 'filter_month'),
		new CFormField(
			(new CTextBox('filter_month', (string) $data['filter']['month']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM')
		)
	])
	->addItem([
		new CLabel(_('From date'), 'filter_date_from'),
		new CFormField(
			(new CTextBox('filter_date_from', (string) $data['filter']['date_from']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM-DD')
		)
	])
	->addItem([
		new CLabel(_('To date'), 'filter_date_to'),
		new CFormField(
			(new CTextBox('filter_date_to', (string) $data['filter']['date_to']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM-DD')
		)
	])
	->addItem([
		new CLabel(_('Days back'), 'filter_days_back'),
		new CFormField(
			(new CNumericBox('filter_days_back', (string) $data['filter']['days_back'], 3))
				->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
		)
	])
	->addItem([
		new CLabel(_('Host groups'), 'filter_hostgroupids'),
		new CFormField(
			(new CDiv())
				->addItem($hostgroup_select)
				->addItem((new CDiv(_('Hold Ctrl/Cmd to select multiple entries.')))->addClass('slareport-field-hint'))
		)
	])
	->addItem([
		new CLabel(_('SLAs'), 'filter_slaids'),
		new CFormField(
			(new CDiv())
				->addItem($sla_select)
				->addItem((new CDiv(_('Leave empty to include all enabled SLAs.')))->addClass('slareport-field-hint'))
		)
	])
	->addItem([
		new CLabel(_('Exclude disabled hosts')),
		new CFormField(
			(new CCheckBox('filter_exclude_disabled', '1'))
				->setUncheckedValue('0')
				->setChecked((int) $data['filter']['exclude_disabled'] === 1)
		)
	])
	->addItem([
		new CLabel(_('Filter note')),
		new CFormField(
			(new CDiv(_('Only the fields used by the selected period mode are evaluated.')))
				->addClass('slareport-field-hint')
		)
	]);

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'slareport.report.view'))
	->setProfile('web.slareport.filter')
	->setActiveTab($data['active_tab'])
	->addFilterTab(_('Filter'), [$filter_grid])
	->addVar('action', 'slareport.report.view');

$info_bar = (new CDiv())
	->addClass('slareport-info-bar')
	->addItem([
		(new CSpan(_('Period') . ': '))->addClass('slareport-info-label'),
		new CSpan(gmdate('Y-m-d H:i', $data['time_from']).' UTC → '.gmdate('Y-m-d H:i', $data['time_to']).' UTC'),
		(new CSpan(' · ' . _('Mode') . ': ' . $data['filter']['mode']))->addClass('slareport-info-muted')
	]);

$downloads = (new CDiv())
	->addClass('slareport-downloads')
	->addItem([
		(new CRedirectButton(_('Download HTML report'), $download_url('html')))->addClass(ZBX_STYLE_BTN_ALT),
		(new CRedirectButton(_('Download SLA CSV'), $download_url('sla_csv')))->addClass(ZBX_STYLE_BTN_ALT),
		(new CRedirectButton(_('Download availability CSV'), $download_url('availability_csv')))->addClass(ZBX_STYLE_BTN_ALT)
	]);

$html_page = (new CHtmlPage())
	->setTitle(_('SLA & Uptime Report'))
	->addItem($filter)
	->addItem($info_bar)
	->addItem($downloads)
	->addItem(
		(new CDiv())
			->addClass('slareport-section')
			->addItem((new CTag('h2', true, _('SLA overview')))->addClass('slareport-section-title'))
			->addItem((new CDiv(_('Rolling 12-month heatmap ending in the selected report month.')))->addClass('slareport-section-subtitle'))
			->addItem(
				new CPartial('slareport.sla.table', [
					'sla_heatmap' => $data['sla_heatmap'],
					'helper' => $helper
				])
			)
	)
	->addItem(
		(new CDiv())
			->addClass('slareport-section')
			->addItem((new CTag('h2', true, _('Availability overview')))->addClass('slareport-section-title'))
			->addItem((new CDiv(_('For long date ranges the module uses trend data to keep the frontend responsive.')))->addClass('slareport-section-subtitle'))
			->addItem(
				new CPartial('slareport.availability.table', [
					'availability' => $data['availability'],
					'helper' => $helper
				])
			)
	);

$html_page->show();
