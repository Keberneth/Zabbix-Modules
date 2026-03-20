<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

require_once __DIR__.'/../Helpers/ReportDataHelper.php';

use CController;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

class ReportDownload extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'format' => 'in html,sla_csv,availability_csv',
			'filter_mode' => 'in prev_month,specific_month,custom_range,days_back',
			'filter_month' => 'string',
			'filter_date_from' => 'string',
			'filter_date_to' => 'string',
			'filter_days_back' => 'int32',
			'filter_hostgroupids' => 'array_id',
			'filter_slaids' => 'array_id',
			'filter_exclude_disabled' => 'in 0,1'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$filter = ReportDataHelper::normalizeFilter([
			'mode' => $this->getInput('filter_mode', ReportDataHelper::getDefaultFilter()['mode']),
			'month' => $this->getInput('filter_month', ReportDataHelper::getDefaultFilter()['month']),
			'date_from' => $this->getInput('filter_date_from', ''),
			'date_to' => $this->getInput('filter_date_to', ''),
			'days_back' => $this->getInput('filter_days_back', ReportDataHelper::getDefaultFilter()['days_back']),
			'hostgroupids' => $this->getInput('filter_hostgroupids', []),
			'slaids' => $this->getInput('filter_slaids', []),
			'exclude_disabled' => $this->getInput('filter_exclude_disabled', 1)
		]);

		[$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

		$helper = new ReportDataHelper();
		$format = (string) $this->getInput('format', 'html');

		switch ($format) {
			case 'sla_csv':
				$sla_heatmap = $helper->fetchSlaHeatmap($filter['slaids'], $time_to);
				$this->outputCsv(
					'sla_report_'.gmdate('Y-m', $time_to).'.csv',
					['SLA ID', 'SLA name', 'Service ID', 'Service name', 'SLO', 'Month', 'SLI'],
					$helper->flattenSlaRows($sla_heatmap)
				);
				break;

			case 'availability_csv':
				$availability = $helper->fetchAvailability(
					$filter['hostgroupids'],
					$time_from,
					$time_to,
					(bool) $filter['exclude_disabled']
				);
				$this->outputCsv(
					'availability_report_'.gmdate('Y-m', $time_to).'.csv',
					[
						'Host group',
						'Host',
						'Availability',
						'Availability pct',
						'Uptime seconds',
						'Downtime seconds',
						'Window start UTC',
						'Window end UTC'
					],
					$helper->flattenAvailabilityRows($availability, $time_from, $time_to)
				);
				break;

			case 'html':
			default:
				$sla_heatmap = $helper->fetchSlaHeatmap($filter['slaids'], $time_to);
				$availability = $helper->fetchAvailability(
					$filter['hostgroupids'],
					$time_from,
					$time_to,
					(bool) $filter['exclude_disabled']
				);

				$filename = 'sla_uptime_report_'.gmdate('Y-m', $time_to).'.html';

				header('Content-Type: text/html; charset=UTF-8');
				header('Content-Disposition: attachment; filename="'.$filename.'"');
				header('Cache-Control: no-cache, no-store, must-revalidate');

				echo $this->buildHtmlReport($helper, $filter, $time_from, $time_to, $sla_heatmap, $availability);
				exit;
		}
	}

	private function outputCsv(string $filename, array $header, array $rows): void {
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="'.$filename.'"');
		header('Cache-Control: no-cache, no-store, must-revalidate');

		$fp = fopen('php://output', 'wb');

		if ($fp === false) {
			exit;
		}

		fwrite($fp, "\xEF\xBB\xBF");
		fputcsv($fp, $header);

		foreach ($rows as $row) {
			fputcsv($fp, $row);
		}

		fclose($fp);
		exit;
	}

	private function buildHtmlReport(
		ReportDataHelper $helper,
		array $filter,
		int $time_from,
		int $time_to,
		array $sla_heatmap,
		array $availability
	): string {
		$generated = gmdate('Y-m-d H:i:s').' UTC';
		$period = gmdate('Y-m-d H:i:s', $time_from).' UTC → '.gmdate('Y-m-d H:i:s', $time_to).' UTC';

		ob_start();

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SLA &amp; Uptime Report</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#0f172a;margin:24px}
h1,h2,h3{margin:0 0 12px}
h1{font-size:24px}
h2{font-size:18px;margin-top:28px}
h3{font-size:15px;margin-top:20px}
.meta{padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:8px;margin-bottom:18px}
.meta div{margin:2px 0}
.pills{margin:8px 0 10px}
.pill{display:inline-block;margin:0 8px 8px 0;padding:4px 10px;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc}
.ok{color:#16a34a}
.warn{color:#d97706}
.bad{color:#dc2626}
.na{color:#94a3b8}
table{border-collapse:collapse;width:100%;margin:8px 0 18px}
th,td{border:1px solid #e2e8f0;padding:6px 8px;vertical-align:top}
th{background:#f8fafc;text-align:left}
.cell-ok{background:#dcfce7;color:#166534;font-weight:600;text-align:center}
.cell-warn{background:#fef3c7;color:#92400e;font-weight:600;text-align:center}
.cell-bad{background:#fee2e2;color:#991b1b;font-weight:600;text-align:center}
.cell-na{background:#f1f5f9;color:#64748b;text-align:center}
.small{color:#64748b;font-size:12px}
.dot{font-weight:700}
</style>
</head>
<body>
<h1>SLA &amp; Uptime Report</h1>

<div class="meta">
	<div><strong>Generated:</strong> <?= $this->h($generated) ?></div>
	<div><strong>Period:</strong> <?= $this->h($period) ?></div>
	<div><strong>Period mode:</strong> <?= $this->h((string) $filter['mode']) ?></div>
	<div><strong>Exclude disabled hosts:</strong> <?= $filter['exclude_disabled'] ? 'Yes' : 'No' ?></div>
</div>

<h2>SLA overview</h2>
<p class="small">Rolling <?= (int) ReportDataHelper::SLA_MONTHS ?> month SLA heatmap ending in the month of the selected report range.</p>

<?php if ($sla_heatmap === []): ?>
<p class="small">No SLA data available.</p>
<?php else: ?>
<?php foreach ($sla_heatmap as $sla): ?>
<?php
	$service_data = $sla['service_data'] ?? [];
	$all_values = [];
	$meet = 0;
	$fail = 0;
	$na = 0;
	$latest_month = '';

	foreach ($service_data as $service) {
		$latest_month = $latest_month === '' && !empty($service['month_labels']) ? (string) end($service['month_labels']) : $latest_month;
		$latest_pct = null;

		foreach (array_reverse($service['monthly_sli'] ?? []) as $value) {
			$parsed = $helper->parsePct((string) $value);

			if ($parsed !== null) {
				$latest_pct = $parsed;
				break;
			}
		}

		$slo = $service['slo_value'] ?? null;

		if ($latest_pct === null || $slo === null) {
			$na++;
		}
		elseif ($latest_pct >= $slo) {
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
?>
<h3><?= $this->h((string) ($sla['sla_name'] ?? 'Unknown SLA')) ?></h3>
<div class="pills">
	<span class="pill">Services: <?= count($service_data) ?></span>
	<span class="pill <?= $fail === 0 ? 'ok' : 'warn' ?>">Meeting SLA: <?= $meet ?></span>
	<span class="pill <?= $fail > 0 ? 'bad' : '' ?>">Below SLA: <?= $fail ?></span>
	<span class="pill na">N/A: <?= $na ?></span>
	<span class="pill">12 month average: <?= $this->h($helper->formatPct($avg, 1)) ?></span>
</div>

<table>
	<thead>
		<tr>
			<th>Service</th>
			<th>SLO</th>
<?php
	$header_months = [];
	foreach ($service_data as $service) {
		if (!empty($service['month_labels'])) {
			$header_months = $service['month_labels'];
			break;
		}
	}

	foreach ($header_months as $ym):
		$short_month = $ym;
		if (preg_match('/^(\d{4})-(\d{2})$/', (string) $ym, $matches) === 1) {
			$short_month = gmdate('M', gmmktime(0, 0, 0, (int) $matches[2], 1, (int) $matches[1]));
		}
?>
			<th><?= $this->h((string) $short_month) ?></th>
<?php endforeach; ?>
			<th>Latest</th>
		</tr>
	</thead>
	<tbody>
<?php foreach ($service_data as $service): ?>
<?php
	$latest_value = 'N/A';
	$latest_pct = null;
	foreach (array_reverse($service['monthly_sli'] ?? []) as $value) {
		$parsed = $helper->parsePct((string) $value);
		if ($parsed !== null) {
			$latest_value = $helper->formatPct($parsed, 1);
			$latest_pct = $parsed;
			break;
		}
	}

	$slo = $service['slo_value'] ?? null;
?>
		<tr>
			<td><?= $this->h((string) ($service['name'] ?? '')) ?></td>
			<td><?= $this->h((string) ($service['slo'] ?? 'N/A')) ?></td>
<?php foreach ($service['monthly_sli'] ?? [] as $value): ?>
<?php
	$parsed = $helper->parsePct((string) $value);
	$class = str_replace('slareport-', '', $helper->sliCssClass($parsed, $slo));
	$class = 'cell-'.str_replace('cell-', '', $class);
?>
			<td class="<?= $this->h($class) ?>"><?= $this->h((string) $value) ?></td>
<?php endforeach; ?>
<?php
	$latest_class = str_replace('slareport-', '', $helper->sliCssClass($latest_pct, $slo));
	$latest_class = 'cell-'.str_replace('cell-', '', $latest_class);
?>
			<td class="<?= $this->h($latest_class) ?>"><?= $this->h($latest_value) ?></td>
		</tr>
<?php endforeach; ?>
	</tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

<h2>Availability overview</h2>
<p class="small">For report windows longer than seven days the module uses trend data to keep the frontend responsive.</p>

<?php if ($availability === []): ?>
<p class="small">No availability data available.</p>
<?php else: ?>
<?php foreach ($availability as $group_name => $hosts): ?>
<?php
	$avg = $helper->getGroupAverage($hosts);
	$counts = $helper->getAvailabilityBandCounts($hosts);
?>
<h3><?= $this->h((string) $group_name) ?> — <span class="<?= $this->h($helper->availabilityCssClass($avg)) ?>"><?= $this->h($helper->formatPct($avg, 1)) ?></span></h3>
<div class="pills">
	<span class="pill ok"><span class="dot">●</span> ≥99%: <?= (int) $counts['green'] ?></span>
	<span class="pill warn"><span class="dot">●</span> 90–99%: <?= (int) $counts['yellow'] ?></span>
	<span class="pill bad"><span class="dot">●</span> &lt;90%: <?= (int) $counts['red'] ?></span>
	<span class="pill na"><span class="dot">●</span> N/A: <?= (int) $counts['na'] ?></span>
</div>

<table>
	<thead>
		<tr>
			<th>Host</th>
			<th>Status</th>
			<th>Availability</th>
			<th>Downtime</th>
		</tr>
	</thead>
	<tbody>
<?php foreach ($hosts as $host): ?>
<?php
	$pct = $host['availability_pct'];
	$class = $helper->availabilityCssClass($pct);
	$downtime = $pct !== null
		? $helper->formatDuration((int) ($host['downtime_seconds'] ?? 0))
		: '—';
?>
		<tr>
			<td><?= $this->h((string) ($host['host'] ?? '')) ?></td>
			<td class="<?= $this->h($class) ?>">●</td>
			<td class="<?= $this->h($class) ?>"><?= $this->h((string) ($host['availability'] ?? 'N/A')) ?></td>
			<td><?= $this->h($downtime) ?></td>
		</tr>
<?php endforeach; ?>
	</tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
<?php

		return (string) ob_get_clean();
	}

	private function h(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
