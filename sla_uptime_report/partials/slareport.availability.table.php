<?php declare(strict_types = 1);

/**
 * @var array $data
 */

$availability = $data['availability'] ?? [];
$helper = $data['helper'];

if ($availability === []) {
	echo (new CTableInfo())
		->setHeader([_('Host group'), _('Host'), _('Availability'), _('Downtime')])
		->setNoDataMessage(_('No availability data is available for the selected filter.'))
		->toString();

	return;
}

foreach ($availability as $group_name => $hosts) {
	$avg = $helper->getGroupAverage($hosts);
	$counts = $helper->getAvailabilityBandCounts($hosts);

	echo (new CTag('h3', true, [
		$group_name,
		' ',
		(new CSpan('— ' . _('Group uptime') . ': ' . $helper->formatPct($avg, 1)))
			->addClass($helper->availabilityCssClass($avg))
	]))
		->addClass('slareport-block-title')
		->toString();

	echo (new CDiv())
		->addClass('slareport-summary-line')
		->addItem([
			(new CSpan('● ' . _('≥99%') . ': ' . $counts['green']))->addClass('slareport-pill slareport-ok'),
			(new CSpan('● ' . _('90–99%') . ': ' . $counts['yellow']))->addClass('slareport-pill slareport-warn'),
			(new CSpan('● ' . _('<90%') . ': ' . $counts['red']))->addClass('slareport-pill slareport-bad'),
			(new CSpan('● ' . _('N/A') . ': ' . $counts['na']))->addClass('slareport-pill slareport-na-text')
		])
		->toString();

	$table = (new CTableInfo())->setHeader([
		_('Host'),
		(new CColHeader(_('Status')))->addStyle('text-align: center; width: 60px;'),
		_('Availability'),
		_('Downtime')
	]);

	foreach ($hosts as $host) {
		$pct = $host['availability_pct'];
		$class = $helper->availabilityCssClass($pct);

		$downtime = '—';
		if ($pct !== null) {
			$downtime = $helper->formatDuration((int) ($host['downtime_seconds'] ?? 0));
		}

		$table->addRow([
			(string) ($host['host'] ?? ''),
			(new CCol((new CSpan('●'))->addClass($class)))->addStyle('text-align: center;'),
			(new CSpan((string) ($host['availability'] ?? 'N/A')))->addClass($class),
			$downtime
		]);
	}

	echo $table->toString();
}
