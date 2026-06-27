<?php declare(strict_types = 1);

/**
 * @var CView $this
 * @var array $data
 */

$helper = $data['helper'];

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

// Prefill the typeahead pickers with only the currently-selected entries (resolved server-side via
// the helper); the rest are searched on demand by Zabbix's native multiselect autocomplete.
$hostgroup_prefill = [];
foreach ($helper->getSelectedHostGroupOptions($data['filter']['hostgroupids']) as $group) {
	$hostgroup_prefill[] = ['id' => (string) $group['groupid'], 'name' => (string) $group['name']];
}

$sla_prefill = [];
foreach ($helper->getSelectedSlaOptions($data['filter']['slaids']) as $sla) {
	$sla_prefill[] = ['id' => (string) $sla['slaid'], 'name' => (string) $sla['name']];
}

$hostgroup_select = (new CMultiSelect([
	'name' => 'filter_hostgroupids[]',
	'object_name' => 'hostGroup',
	'data' => $hostgroup_prefill,
	'popup' => [
		'parameters' => [
			'srctbl' => 'host_groups',
			'srcfld1' => 'groupid',
			'dstfrm' => 'zbx_filter',
			'dstfld1' => 'filter_hostgroupids_',
			'with_hosts' => true
		]
	]
]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH);

$sla_select = (new CMultiSelect([
	'name' => 'filter_slaids[]',
	'object_name' => 'sla',
	'data' => $sla_prefill,
	'popup' => [
		'parameters' => [
			'srctbl' => 'sla',
			'srcfld1' => 'slaid',
			'dstfrm' => 'zbx_filter',
			'dstfld1' => 'filter_slaids_',
			'enabled_only' => 1
		]
	]
]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH);

// Tag the period-mode-specific rows so slareport.filter.js can show only the active mode's fields.
$mode_label = static function (CLabel $label, string $mode): CLabel {
	return $label->setAttribute('data-slareport-mode', $mode);
};
$mode_field = static function (CFormField $field, string $mode): CFormField {
	return $field->setAttribute('data-slareport-mode', $mode);
};

$filter_grid = (new CFormGrid())
	->addItem([
		new CLabel(_('Period mode')),
		new CFormField($mode_radio)
	])
	->addItem([
		$mode_label(new CLabel(_('Specific month'), 'filter_month'), 'specific_month'),
		$mode_field(new CFormField(
			(new CTextBox('filter_month', (string) $data['filter']['month']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM')
		), 'specific_month')
	])
	->addItem([
		$mode_label(new CLabel(_('From date'), 'filter_date_from'), 'custom_range'),
		$mode_field(new CFormField(
			(new CTextBox('filter_date_from', (string) $data['filter']['date_from']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM-DD')
		), 'custom_range')
	])
	->addItem([
		$mode_label(new CLabel(_('To date'), 'filter_date_to'), 'custom_range'),
		$mode_field(new CFormField(
			(new CTextBox('filter_date_to', (string) $data['filter']['date_to']))
				->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
				->setAttribute('placeholder', 'YYYY-MM-DD')
		), 'custom_range')
	])
	->addItem([
		$mode_label(new CLabel(_('Days back'), 'filter_days_back'), 'days_back'),
		$mode_field(new CFormField(
			(new CNumericBox('filter_days_back', (string) $data['filter']['days_back'], 3))
				->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
		), 'days_back')
	])
	->addItem([
		new CLabel(_('Host groups'), 'filter_hostgroupids__ms'),
		new CFormField(
			(new CDiv())
				->addItem($hostgroup_select)
				->addItem((new CDiv(_('Start typing to search host groups; leave empty to include all groups.')))->addClass('slareport-field-hint'))
		)
	])
	->addItem([
		new CLabel(_('SLAs'), 'filter_slaids__ms'),
		new CFormField(
			(new CDiv())
				->addItem($sla_select)
				->addItem((new CDiv(_('Start typing to search SLAs; leave empty to include all enabled SLAs.')))->addClass('slareport-field-hint'))
		)
	])
	->addItem([
		new CLabel(_('Exclude disabled hosts')),
		new CFormField(
			(new CCheckBox('filter_exclude_disabled', '1'))
				->setUncheckedValue('0')
				->setChecked((int) $data['filter']['exclude_disabled'] === 1)
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

$error_banner = null;
if (($data['error'] ?? null) !== null && $data['error'] !== '') {
	$error_banner = (new CDiv($data['error']))->addClass('slareport-error');
}

$notes_banner = null;
if (!empty($data['notes'])) {
	$notes_banner = (new CDiv())->addClass('slareport-notes');

	foreach ($data['notes'] as $note) {
		$notes_banner->addItem((new CDiv((string) $note))->addClass('slareport-note'));
	}
}

$html_page = (new CHtmlPage())
	->setTitle(_('SLA & Uptime Report'))
	->addItem($filter)
	->addItem($info_bar)
	->addItem($error_banner)
	->addItem($notes_banner)
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
