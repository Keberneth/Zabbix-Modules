<?php declare(strict_types = 1);

/**
 * @var array $data
 */

$sla_heatmap = $data['sla_heatmap'] ?? [];
$helper = $data['helper'];

if ($sla_heatmap === []) {
	echo (new CTableInfo())
		->setHeader([_('SLA'), _('Service'), _('SLO'), _('Status')])
		->setNoDataMessage(_('No SLA data is available for the selected filter.'))
		->toString();

	return;
}

foreach ($sla_heatmap as $sla) {
	$service_data = $sla['service_data'] ?? [];

	echo (new CTag('h3', true, (string) ($sla['sla_name'] ?? _('Unknown SLA'))))
		->addClass('slareport-block-title')
		->toString();

	if ($service_data === []) {
		echo (new CDiv(_('No services are linked to this SLA.')))
			->addClass('slareport-empty-note')
			->toString();

		continue;
	}

	$month_labels = [];
	foreach ($service_data as $service) {
		if (!empty($service['month_labels'])) {
			$month_labels = $service['month_labels'];
			break;
		}
	}

	$meet = 0;
	$fail = 0;
	$na = 0;
	$all_values = [];

	foreach ($service_data as $service) {
		$latest_pct = null;

		foreach (array_reverse($service['monthly_sli'] ?? []) as $value) {
			$parsed = $helper->parsePct((string) $value);

			if ($parsed !== null) {
				$latest_pct = $parsed;
				break;
			}
		}

		$slo_value = $service['slo_value'] ?? null;

		if ($latest_pct === null || $slo_value === null) {
			$na++;
		}
		elseif ($latest_pct >= $slo_value) {
			$meet++;
		}
		else {
			$fail++;
		}

		foreach ($service['monthly_sli'] ?? [] as $value) {
			$parsed = $helper->parsePct((string) $value);

			if ($parsed !== null) {
				$all_values[] = $parsed;
			}
		}
	}

	$avg = $all_values !== [] ? array_sum($all_values) / count($all_values) : null;

	$summary = (new CDiv())
		->addClass('slareport-summary-line')
		->addItem([
			(new CSpan(_('Services') . ': ' . count($service_data)))->addClass('slareport-pill'),
			(new CSpan(_('Meeting SLA') . ': ' . $meet))->addClass('slareport-pill slareport-ok'),
			(new CSpan(_('Below SLA') . ': ' . $fail))->addClass($fail > 0 ? 'slareport-pill slareport-bad' : 'slareport-pill'),
			(new CSpan(_('N/A') . ': ' . $na))->addClass('slareport-pill slareport-na-text'),
			(new CSpan(_('12 month average') . ': ' . $helper->formatPct($avg, 1)))->addClass('slareport-pill')
		]);

	echo $summary->toString();

	$header = [_('Service'), _('SLO')];

	foreach ($month_labels as $month_label) {
		$short_label = $month_label;

		if (preg_match('/^(\d{4})-(\d{2})$/', (string) $month_label, $matches) === 1) {
			$short_label = gmdate('M', gmmktime(0, 0, 0, (int) $matches[2], 1, (int) $matches[1]));
		}

		$header[] = $short_label;
	}

	$header[] = _('Latest');

	$table = (new CTableInfo())->setHeader($header);

	foreach ($service_data as $service) {
		$row = [
			(string) ($service['name'] ?? _('Unknown')),
			(string) ($service['slo'] ?? 'N/A')
		];

		$slo_value = $service['slo_value'] ?? null;

		foreach ($service['monthly_sli'] ?? [] as $value) {
			$parsed = $helper->parsePct((string) $value);

			$row[] = (new CSpan((string) $value))
				->addClass($helper->sliCssClass($parsed, $slo_value));
		}

		$latest_value = 'N/A';
		$latest_pct = null;

		foreach (array_reverse($service['monthly_sli'] ?? []) as $value) {
			$parsed = $helper->parsePct((string) $value);

			if ($parsed !== null) {
				$latest_pct = $parsed;
				$latest_value = $helper->formatPct($parsed, 1);
				break;
			}
		}

		$row[] = (new CSpan($latest_value))
			->addClass($helper->sliCssClass($latest_pct, $slo_value))
			->addClass('bold');

		$table->addRow($row);
	}

	echo $table->toString();
}
