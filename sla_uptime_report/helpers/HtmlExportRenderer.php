<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

/**
 * The presentation-ready export: one self-contained HTML file with inline CSS
 * and inline SVG, no external requests, that opens anywhere and prints to A4.
 *
 * Written for two audiences at once. A customer or manager reads the verdict,
 * the headline numbers and the plain-language sentences and stops there; an
 * operator gets the full heatmaps and host tables below.
 */
class HtmlExportRenderer {

	private ReportDataHelper $helper;
	private ChartRenderer $chart;
	private ViewFormatter $fmt;

	public function __construct(ReportDataHelper $helper) {
		$this->helper = $helper;
		$this->chart = new ChartRenderer();
		$this->fmt = new ViewFormatter($helper);
	}

	public function render(array $filter, array $report, int $time_from, int $time_to): string {
		$h = fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$helper = $this->helper;
		$chart = $this->chart;
		$fmt = $this->fmt;

		$fleet = $report['fleet'];
		$sla_summary = $report['sla_summary'];
		$target = (float) $filter['target'];
		$verdict = $this->verdict($report, $target);

		$minutes_axis = static function($value, int $decimals = 0, $reference = null): string {
		$value = (float) $value;
		$scale = $reference !== null ? abs((float) $reference) : $value;
	
		if ($scale >= 1440) {
			return number_format($value / 1440, $value == 0 ? 0 : 1, '.', ' ').' d';
		}
		if ($scale >= 120) {
			return number_format($value / 60, $decimals > 0 || $scale < 600 ? 1 : 0, '.', ' ').' h';
		}
	
		return number_format($value, $decimals, '.', ' ').' min';
	};

		$graded = (int) $sla_summary['meeting'] + (int) $sla_summary['below'];

		ob_start();
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(_('SLA & Uptime Report')) ?> — <?= $h($report['period']['label']) ?></title>
<style><?= $this->styles() ?></style>
</head>
<body>
<div class="slareport">

<header class="cover">
	<div class="cover-main">
		<p class="cover-eyebrow"><?= $h(_('Service level report')) ?></p>
		<h1><?= $h(_('SLA & Uptime Report')) ?></h1>
		<p class="cover-period"><?= $h($report['period']['label']) ?></p>
		<p class="cover-servers"><?= $h(sprintf(
			_('%1$d hosts · %2$d SLAs · availability target %3$s'),
			(int) $fleet['hosts_total'],
			(int) $sla_summary['slas_total'],
			$helper->formatTargetPct($target)
		)) ?></p>
	</div>
	<div class="cover-verdict verdict--<?= $h($verdict['tone']) ?>">
		<div class="verdict-word"><?= $h($verdict['word']) ?></div>
		<div class="verdict-line"><?= $h($verdict['line']) ?></div>
	</div>
</header>

<section class="section">
	<h2><?= $h(_('At a glance')) ?></h2>
	<div class="kpis">
		<?php foreach ($report['cards'] as $card): ?>
			<div class="kpi kpi--<?= $h($card['tone']) ?>">
				<div class="kpi-label"><?= $h($card['label']) ?></div>
				<div class="kpi-value"><?= $h($card['value']) ?></div>
				<div class="kpi-sub"><?= $h($card['sub']) ?></div>
			</div>
		<?php endforeach; ?>
	</div>
</section>

<section class="section">
	<h2><?= $h(_('Where uptime was lost')) ?></h2>
	<p class="lede">
		<?= $h(_('Minutes of host downtime per day, stacked by host group. A flat baseline at zero is the goal; each spike is an incident.')) ?>
	</p>
	<div class="card">
		<?= $chart->stackedTime($report['daily']['dates'], $report['daily']['series'], [
			'title' => _('Downtime minutes per day'),
			'format' => $minutes_axis,
			'height' => 280,
			'empty_text' => _('No downtime was recorded in this period.')
		]) ?>
		<?= $chart->legend($report['daily']['series']) ?>
	</div>
	<p class="takeaway">
		<?php if ($fleet['with_data'] > 0 && (int) $fleet['downtime_seconds'] === 0): ?>
			<?= $h(_('No measured host lost any time at all in this period.')) ?>
		<?php elseif ($fleet['with_data'] > 0): ?>
			<?= $h(sprintf(
				_('%1$s of downtime across %2$d measured hosts. The worst affected host was %3$s at %4$s availability.'),
				$helper->formatDuration((int) $fleet['downtime_seconds']),
				(int) $fleet['with_data'],
				(string) $fleet['worst_host'],
				$helper->formatPct($fleet['worst_pct'], 2)
			)) ?>
		<?php else: ?>
			<?= $h(_('No availability data was found for this period.')) ?>
		<?php endif; ?>
	</p>
</section>

<?php if ($report['slas'] !== []): ?>
<section class="section">
	<h2><?= $h(_('SLA compliance')) ?></h2>
	<p class="lede">
		<?= $h($graded > 0
			? sprintf(
				_('%1$d of %2$d services meet their SLO in the latest measured month. The heatmaps below show each service against its SLO over the last 12 months.'),
				(int) $sla_summary['meeting'],
				$graded
			)
			: _('No measured SLA data is available for this period.')) ?>
	</p>

	<?php foreach ($report['slas'] as $sla): ?>
		<h3><?= $h($sla['name']) ?> <span class="dim-inline"><?= $h(sprintf(_('SLO %1$s'), $helper->formatPct($sla['slo'], 2))) ?></span></h3>
		<table>
			<thead>
				<tr>
					<th><?= $h(_('Service')) ?></th>
					<th><?= $h(_('State')) ?></th>
					<?php foreach ($sla['months'] as $month): ?>
						<th class="heat-head"><?= $h($helper->shortMonth($month)) ?></th>
					<?php endforeach; ?>
					<th class="num"><?= $h(_('Breaches')) ?></th>
					<th><?= $h(sprintf(
						_('Error budget (%1$s)'),
						$sla['months'] !== [] ? $helper->shortMonth((string) end($sla['months'])) : _('this period')
					)) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($sla['services'] as $service): ?>
					<tr>
						<td><strong><?= $h($service['name']) ?></strong></td>
						<td><?= $fmt->sloCompliance($service['state'], $service['latest'], $sla['slo']) ?></td>
						<?php foreach ($service['sli'] as $value): ?>
							<?= $fmt->sliCell($value, $sla['slo']) ?>
						<?php endforeach; ?>
						<td class="num"><?= $h((string) (int) $service['breaches']) ?></td>
						<td class="budget-col">
							<?php if ($service['budget'] === null): ?>
								<span class="dim">—</span>
							<?php else: ?>
								<?php
								$used = $service['budget']['used_pct'];
								$meter_token = $used === null ? '--sr-s1'
									: ($used >= 100.0 ? '--sr-critical' : ($used >= 80.0 ? '--sr-warning' : '--sr-ok'));
								?>
								<?= $chart->meter($used === null ? null : min(100.0, $used), [
									'token' => $meter_token,
									'label' => sprintf(_('%1$s of the error budget used'), $helper->formatPct($used, 0))
								]) ?>
								<div class="dim">
									<?= $h($used !== null && $used >= 100.0
										? sprintf(_('Exceeded by %1$s'),
											$helper->formatDuration(max(0, -(int) $service['budget']['remaining_seconds'])))
										: sprintf(_('%1$s of %2$s used'),
											$helper->formatDuration((int) $service['budget']['consumed_seconds']),
											$helper->formatDuration((int) $service['budget']['allowed_seconds']))) ?>
								</div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
	<p class="dim">
		<?= $h(_('Cells are tinted against the SLO: green at or above, amber up to half a point below, red further below. A dash means the period was not measured.')) ?>
	</p>
</section>
<?php endif; ?>

<section class="section">
	<h2><?= $h(_('What needs attention')) ?></h2>
	<?php
		$has_subject = $report['groups'] !== [] || (int) $sla_summary['services_total'] > 0;
	?>
	<?php if ($report['attention'] === [] && !$has_subject): ?>
		<p class="dim"><?= $h(_('Nothing to check: no hosts or SLAs were found for this selection.')) ?></p>
	<?php elseif ($report['attention'] === []): ?>
		<p class="takeaway takeaway--good">
			<?= $h(_('Nothing outstanding. Every SLO is met and every measured host is above the availability target.')) ?>
		</p>
	<?php else: ?>
		<table>
			<thead>
				<tr>
					<th class="sev-col"><?= $h(_('Severity')) ?></th>
					<th><?= $h(_('Area')) ?></th>
					<th><?= $h(_('Finding')) ?></th>
					<th><?= $h(_('Detail')) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($report['attention'] as $item): ?>
					<tr>
						<td><?= $fmt->pill(
							$item['severity'] === 'critical' ? 'critical' : ($item['severity'] === 'warning' ? 'warning' : 'neutral'),
							$item['severity'] === 'critical' ? _('Critical') : ($item['severity'] === 'warning' ? _('Warning') : _('Info'))
						) ?></td>
						<td><?= $h($item['scope']) ?></td>
						<td><strong><?= $h($item['title']) ?></strong></td>
						<td class="wrap"><?= $h($item['detail']) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="section page-break">
	<h2><?= $h(_('Host availability in detail')) ?></h2>
	<p class="lede">
		<?= $h(sprintf(
			_('Every host in the selection, grouped by host group and sorted worst first, against the %1$s availability target.'),
			$helper->formatTargetPct($target)
		)) ?>
	</p>

	<?php if ($report['groups'] === []): ?>
		<p class="dim"><?= $h(_('No hosts matched the selection.')) ?></p>
	<?php endif; ?>

	<?php foreach ($report['groups'] as $group): ?>
		<h3><?= $h($group['name']) ?>
			<span class="dim-inline"><?= $h(sprintf(
				_('average %1$s · %2$s downtime'),
				$helper->formatPct($group['avg'], 2),
				$group['with_data'] > 0 ? $helper->formatDuration((int) $group['downtime_seconds']) : '—'
			)) ?></span>
		</h3>
		<table>
			<thead>
				<tr>
					<th><?= $h(_('Host')) ?></th>
					<th><?= $h(_('Availability')) ?></th>
					<th class="num"><?= $h(_('Uptime')) ?></th>
					<th class="num"><?= $h(_('Downtime')) ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($group['rows'] as $row): ?>
					<tr>
						<td><strong><?= $h($row['host']) ?></strong><?= $row['enabled'] ? '' : ' '.$fmt->pill('neutral', _('disabled')) ?></td>
						<td><?= $fmt->hostState((string) $row['state'], $row['pct']) ?></td>
						<td class="num"><?= $h($row['pct'] !== null ? $helper->formatDuration((int) $row['uptime_seconds']) : '—') ?></td>
						<td class="num"><?= $h($row['pct'] !== null ? $helper->formatDuration((int) $row['downtime_seconds']) : '—') ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ((int) $group['rows_total'] > count($group['rows'])): ?>
			<p class="dim"><?= $h(sprintf(
				_('Showing the %1$d worst of %2$d hosts in this group.'),
				count($group['rows']),
				(int) $group['rows_total']
			)) ?></p>
		<?php endif; ?>
	<?php endforeach; ?>
</section>

<footer class="footer">
	<div>
		<strong><?= $h(_('SLA & Uptime Report')) ?></strong> ·
		<?= $h(sprintf(_('Generated %1$s UTC'), gmdate('j F Y H:i'))) ?>
	</div>
	<div class="dim">
		<?= $h(sprintf(
			_('Source: Zabbix %1$s. SLI values come from the Zabbix SLA engine; host availability is measured from each host\'s availability item. All times UTC.'),
			$report['source_used'] === 'trends' ? _('hourly trends') : _('raw history')
		)) ?>
	</div>
	<?php foreach (($report['warnings'] ?? []) as $warning): ?>
		<div class="dim footnote"><?= $h($warning) ?></div>
	<?php endforeach; ?>
</footer>

</div>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * One-line verdict for the cover, so the first thing a reader sees is an
	 * answer rather than a number.
	 *
	 * @return array{tone:string,word:string,line:string}
	 */
	private function verdict(array $report, float $target): array {
		$critical = 0;
		$warning = 0;
		foreach ($report['attention'] as $item) {
			if ($item['severity'] === 'critical') {
				$critical++;
			}
			elseif ($item['severity'] === 'warning') {
				$warning++;
			}
		}

		$has_subject = $report['groups'] !== [] || (int) $report['sla_summary']['services_total'] > 0;
		if (!$has_subject) {
			return [
				'tone' => 'neutral',
				'word' => _('No data'),
				'line' => _('No hosts or SLAs were found for this selection')
			];
		}

		if ($critical > 0) {
			$line = $critical === 1 ? _('1 critical finding') : sprintf(_('%1$d critical findings'), $critical);
			if ($warning > 0) {
				$line .= ' · '.($warning === 1 ? _('1 warning') : sprintf(_('%1$d warnings'), $warning));
			}

			return ['tone' => 'critical', 'word' => _('Action needed'), 'line' => $line];
		}

		if ($warning > 0) {
			return [
				'tone' => 'warning',
				'word' => _('Mostly on target'),
				'line' => $warning === 1 ? _('1 item to review') : sprintf(_('%1$d items to review'), $warning)
			];
		}

		return [
			'tone' => 'ok',
			'word' => _('On target'),
			'line' => sprintf(_('Every SLO met, every measured host above %1$s'), $this->helper->formatTargetPct($target))
		];
	}

	/**
	 * Light-only stylesheet. The export is a document people print and email,
	 * so it commits to one look rather than following a viewer's theme.
	 */
	private function styles(): string {
		return <<<'CSS'
:root {
	--sr-surface: #ffffff;
	--sr-surface-2: #f6f8fb;
	--sr-border: #e2e8f0;
	--sr-border-strong: #cbd5e1;
	--sr-ink: #16202e;
	--sr-ink-2: #3f4f63;
	--sr-muted: #64748b;
	--sr-accent: #0f6ad8;
	--sr-grid: #eaeff6;
	--sr-axis: #cbd5e1;
	--sr-track: #edf1f7;

	--sr-ok: #0ca30c;
	--sr-warning: #fab219;
	--sr-critical: #d03b3b;
	--sr-ok-ink: #067506;
	--sr-warning-ink: #8a5a00;
	--sr-critical-ink: #b02121;

	--sr-s1: #2a78d6;
	--sr-s2: #eb6834;
	--sr-s3: #1baf7a;
	--sr-s4: #eda100;
	--sr-s5: #e87ba4;
	--sr-s6: #008300;
	--sr-s7: #4a3aa7;
	--sr-s8: #e34948;
}

* { box-sizing: border-box; }

body {
	margin: 0;
	padding: 32px 28px 48px;
	background: #eef2f7;
	color: var(--sr-ink);
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
	font-size: 13.5px;
	line-height: 1.55;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}

.slareport {
	max-width: 1120px;
	margin: 0 auto;
	padding: 40px 44px 36px;
	background: var(--sr-surface);
	border-radius: 10px;
	box-shadow: 0 2px 20px rgba(22, 32, 46, 0.08);
}

.cover {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	justify-content: space-between;
	gap: 24px;
	padding-bottom: 26px;
	border-bottom: 3px solid var(--sr-accent);
}

.cover-eyebrow {
	margin: 0 0 6px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--sr-accent);
}

.cover h1 { margin: 0; font-size: 30px; font-weight: 600; line-height: 1.15; }
.cover-period { margin: 8px 0 2px; font-size: 17px; font-weight: 600; color: var(--sr-ink-2); }
.cover-servers { margin: 0; font-size: 13px; color: var(--sr-muted); }

.cover-verdict {
	min-width: 240px;
	padding: 14px 18px;
	border-radius: 8px;
	border-left: 4px solid var(--sr-border-strong);
	background: var(--sr-surface-2);
}

.verdict--ok { border-left-color: var(--sr-ok); background: rgba(12, 163, 12, 0.07); }
.verdict--warning { border-left-color: var(--sr-warning); background: rgba(250, 178, 25, 0.1); }
.verdict--critical { border-left-color: var(--sr-critical); background: rgba(208, 59, 59, 0.07); }

.verdict-word { font-size: 20px; font-weight: 700; }
.verdict--ok .verdict-word { color: var(--sr-ok-ink); }
.verdict--warning .verdict-word { color: var(--sr-warning-ink); }
.verdict--critical .verdict-word { color: var(--sr-critical-ink); }
.verdict-line { margin-top: 3px; font-size: 12.5px; color: var(--sr-ink-2); }

.section { margin-top: 36px; }
.section h2 {
	margin: 0 0 6px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--sr-border);
	font-size: 18px;
	font-weight: 600;
}
.section h3 { margin: 26px 0 8px; font-size: 14.5px; font-weight: 600; }
.dim-inline { color: var(--sr-muted); font-weight: 400; font-size: 12.5px; margin-left: 8px; }

.lede { margin: 12px 0 16px; color: var(--sr-ink-2); max-width: 86ch; }

.takeaway {
	margin: 14px 0 0;
	padding: 12px 16px;
	border-left: 3px solid var(--sr-accent);
	border-radius: 0 6px 6px 0;
	background: var(--sr-surface-2);
	color: var(--sr-ink-2);
	max-width: 92ch;
}

.takeaway--good { border-left-color: var(--sr-ok); background: rgba(12, 163, 12, 0.07); color: var(--sr-ok-ink); }

.kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: 12px;
	margin-top: 16px;
}

.kpi {
	position: relative;
	padding: 15px 17px;
	border: 1px solid var(--sr-border);
	border-radius: 8px;
	overflow: hidden;
}

.kpi::before {
	content: "";
	position: absolute;
	inset: 0 auto 0 0;
	width: 3px;
	background: var(--sr-accent);
}

.kpi--ok::before { background: var(--sr-ok); }
.kpi--warning::before { background: var(--sr-warning); }
.kpi--critical::before { background: var(--sr-critical); }

.kpi-label {
	margin-bottom: 7px;
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: var(--sr-muted);
}

.kpi-value { font-size: 24px; font-weight: 600; line-height: 1.15; }
.kpi-sub { margin-top: 6px; font-size: 11.5px; color: var(--sr-muted); }

.card {
	margin-top: 16px;
	padding: 18px;
	border: 1px solid var(--sr-border);
	border-radius: 8px;
}

.sr-chart { display: block; width: 100%; height: auto; overflow: visible; }
.sr-chart-empty { margin: 0; padding: 26px; text-align: center; color: var(--sr-muted); }

.sr-grid { stroke: var(--sr-grid); stroke-width: 1; }
.sr-baseline { stroke: var(--sr-axis); stroke-width: 1; }
.sr-tick { fill: var(--sr-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.sr-label { fill: var(--sr-ink-2); font-size: 12px; text-anchor: end; }
.sr-value { fill: var(--sr-ink); font-size: 12px; font-weight: 600; text-anchor: end; font-variant-numeric: tabular-nums; }
.sr-track { fill: var(--sr-track); }
.sr-track-ring { stroke: var(--sr-track); }
.sr-line { fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.sr-area { opacity: 0.1; }
.sr-stack-area { opacity: 0.85; }
.sr-end-dot { stroke: var(--sr-surface); stroke-width: 2; }
.sr-donut-value { fill: var(--sr-ink); font-size: 26px; font-weight: 600; }
.sr-donut-label { fill: var(--sr-muted); font-size: 11px; }
.sr-sparkline { display: inline-block; vertical-align: middle; }

.sr-legend { display: flex; flex-wrap: wrap; gap: 6px 20px; margin-top: 14px; }
.sr-legend-item { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; }
.sr-legend-swatch { width: 10px; height: 10px; border-radius: 2px; }
.sr-legend-label { color: var(--sr-ink-2); }
.sr-legend-value { color: var(--sr-muted); font-variant-numeric: tabular-nums; }

.sr-meter { display: block; width: 100%; height: 8px; border-radius: 999px; background: var(--sr-track); overflow: hidden; }
.sr-meter-fill { display: block; height: 100%; border-radius: 999px; }

.sr-swatch { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 7px; }

table {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
	font-size: 12.5px;
}

th, td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--sr-border);
	vertical-align: middle;
}

thead th {
	background: var(--sr-surface-2);
	color: var(--sr-muted);
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	border-bottom: 1px solid var(--sr-border-strong);
}

tbody tr:last-child td { border-bottom: none; }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.wrap { word-break: break-word; }
.dim { color: var(--sr-muted); font-size: 11.5px; }
.sev-col { width: 110px; }
.heat-head { text-align: center; min-width: 44px; }
.budget-col { min-width: 170px; }

.sr-heat {
	text-align: center;
	font-variant-numeric: tabular-nums;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.sr-heat--ok { background: rgba(12, 163, 12, 0.10); color: var(--sr-ok-ink); }
.sr-heat--warn { background: rgba(250, 178, 25, 0.16); color: var(--sr-warning-ink); }
.sr-heat--bad { background: rgba(208, 59, 59, 0.14); color: var(--sr-critical-ink); }
.sr-heat--na { background: var(--sr-surface-2); color: var(--sr-muted); font-weight: 400; }

.sr-status {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 9px;
	border: 1px solid transparent;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	white-space: nowrap;
}

.sr-status-glyph { font-size: 9px; line-height: 1; }
.sr-status--ok { background: rgba(12,163,12,0.12); border-color: rgba(12,163,12,0.35); color: var(--sr-ok-ink); }
.sr-status--warning { background: rgba(250,178,25,0.16); border-color: rgba(250,178,25,0.45); color: var(--sr-warning-ink); }
.sr-status--critical { background: rgba(208,59,59,0.12); border-color: rgba(208,59,59,0.4); color: var(--sr-critical-ink); }
.sr-status--neutral { background: var(--sr-surface-2); border-color: var(--sr-border); color: var(--sr-muted); }

.footer {
	margin-top: 40px;
	padding-top: 18px;
	border-top: 1px solid var(--sr-border);
	font-size: 11.5px;
	color: var(--sr-ink-2);
}

.footnote { margin-top: 5px; }

@page { size: A4 landscape; margin: 12mm; }

@media print {
	body { padding: 0; background: #ffffff; font-size: 10px; }
	.slareport { max-width: none; padding: 0; box-shadow: none; border-radius: 0; }
	.section { margin-top: 22px; }
	.section h2 { break-after: avoid; }
	.card, .kpi, .cover, .takeaway { break-inside: avoid; }
	.page-break { break-before: page; }
	thead { display: table-header-group; }
	tr { break-inside: avoid; }
	.cover h1 { font-size: 24px; }
	.kpi-value { font-size: 19px; }
}

@media (max-width: 720px) {
	body { padding: 12px; }
	.slareport { padding: 22px 18px; }
}
CSS;
	}
}
