<?php declare(strict_types = 1);

/**
 * Overview tab - "are we meeting our commitments, and where are we losing
 * uptime".
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\SlaUptimeReport\Helpers\ReportDataHelper $helper
 * @var \Modules\SlaUptimeReport\Helpers\ChartRenderer $chart
 * @var \Modules\SlaUptimeReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 * @var callable $minutes_axis
 */

$fleet = $report['fleet'];
$sla_summary = $report['sla_summary'];
?>

<?php if ($report['cards'] !== []): ?>
	<div class="sr-kpis">
		<?php foreach ($report['cards'] as $card): ?>
			<div class="sr-kpi sr-kpi--<?= $esc($card['tone']) ?>">
				<div class="sr-kpi-label"><?= $esc($card['label']) ?></div>
				<div class="sr-kpi-value"><?= $esc($card['value']) ?></div>
				<div class="sr-kpi-sub"><?= $esc($card['sub']) ?></div>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="sr-panel">
	<div class="sr-panel-head">
		<h2 class="sr-panel-title"><?= $esc(_('Downtime per day')) ?></h2>
		<span class="sr-panel-note"><?= $esc(_('Stacked by host group')) ?></span>
	</div>
	<p class="sr-panel-sub">
		<?= $esc(_('Minutes of host downtime each day, summed across the selected hosts. A flat baseline at zero is the goal; spikes are incidents worth a look.')) ?>
	</p>
	<div class="sr-chart-scroll">
		<?= $chart->stackedTime(
			$report['daily']['dates'],
			$report['daily']['series'],
			[
				'title' => _('Downtime minutes per day, stacked by host group'),
				'format' => $minutes_axis,
				'height' => 280,
				'empty_text' => _('No downtime was recorded in this period — or no availability data was found.')
			]
		) ?>
	</div>
	<?= $chart->legend($report['daily']['series']) ?>
</div>

<div class="sr-grid-2">
	<div class="sr-panel">
		<div class="sr-panel-head">
			<h2 class="sr-panel-title"><?= $esc(_('Availability by host group')) ?></h2>
			<span class="sr-panel-note"><?= $esc(_('Period average')) ?></span>
		</div>
		<p class="sr-panel-sub">
			<?= $esc(sprintf(
				_('Average availability of each group over this period, against the %1$s target.'),
				$helper->formatPct((float) $filter['target'], 1)
			)) ?>
		</p>

		<?php if ($report['groups'] === []): ?>
			<p class="sr-chart-empty"><?= $esc(_('No host groups matched the filter.')) ?></p>
		<?php else: ?>
			<?php
			// Bars compare groups by downtime: zero-based and linear, because an
			// availability bar on a 0-100 scale looks identical for 91% and 99.9%.
			$max_downtime = 0;
			foreach ($report['groups'] as $group) {
				$max_downtime = max($max_downtime, (int) $group['downtime_seconds']);
			}
			?>
			<div class="sr-table-wrap">
				<table class="sr-table">
					<thead>
						<tr>
							<th><?= $esc(_('Host group')) ?></th>
							<th class="sr-num"><?= $esc(_('Hosts')) ?></th>
							<th class="sr-num"><?= $esc(_('Average')) ?></th>
							<th style="width: 30%"><?= $esc(_('Downtime')) ?></th>
							<th class="sr-num"><?= $esc(_('Worst host')) ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($report['groups'] as $group): ?>
							<?php
							$avg = $group['avg'];
							$avg_state = $avg === null ? 'neutral'
								: ($avg >= (float) $filter['target'] ? 'ok'
									: ($avg >= \Modules\SlaUptimeReport\Helpers\ReportDataHelper::BAD_THRESHOLD ? 'warning' : 'critical'));
							$bar_token = $avg_state === 'critical' ? '--sr-critical'
								: ($avg_state === 'warning' ? '--sr-warning' : '--sr-s1');
							$bar_pct = $max_downtime > 0
								? ((int) $group['downtime_seconds'] / $max_downtime) * 100.0
								: 0.0;
							?>
							<tr>
								<td>
									<?= $fmt->swatch($group['token']) ?>
									<span class="sr-strong"><?= $esc($group['name']) ?></span>
								</td>
								<td class="sr-num"><?= $esc((string) $group['hosts_total']) ?></td>
								<td class="sr-num"><?= $fmt->pill($avg_state === 'ok' ? 'ok' : ($avg_state === 'neutral' ? 'neutral' : $avg_state), $helper->formatPct($avg, 2)) ?></td>
								<td>
									<?php if ($group['with_data'] === 0): ?>
										<span class="sr-dim">—</span>
									<?php else: ?>
										<?= $chart->meter($bar_pct, [
											'token' => $bar_token,
											'label' => sprintf(_('%1$s of downtime'), $helper->formatDuration((int) $group['downtime_seconds']))
										]) ?>
										<div class="sr-dim" style="font-size:11.5px; margin-top:4px">
											<?= $esc($helper->formatDuration((int) $group['downtime_seconds'])) ?>
										</div>
									<?php endif; ?>
								</td>
								<td class="sr-num sr-dim"><?= $esc($group['worst_host'] !== null
									? $group['worst_host'].' · '.$helper->formatPct($group['worst_pct'], 2)
									: '—') ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div class="sr-panel">
		<div class="sr-panel-head">
			<h2 class="sr-panel-title"><?= $esc(_('SLA compliance')) ?></h2>
			<span class="sr-panel-note"><?= $esc(_('Latest measured month')) ?></span>
		</div>
		<p class="sr-panel-sub">
			<?= $esc(_('Every service across the selected SLAs, judged against its own SLO.')) ?>
		</p>

		<?php if ((int) $sla_summary['services_total'] === 0): ?>
			<p class="sr-chart-empty"><?= $esc(_('No enabled SLAs with services were found. Configure them under Services → SLA.')) ?></p>
		<?php else: ?>
			<?php
			$slices = [];
			if ((int) $sla_summary['meeting'] > 0) {
				$slices[] = [
					'label' => _('Meeting SLO'),
					'value' => (float) $sla_summary['meeting'],
					'text' => (string) (int) $sla_summary['meeting'],
					'token' => '--sr-ok'
				];
			}
			if ((int) $sla_summary['below'] > 0) {
				$slices[] = [
					'label' => _('Below SLO'),
					'value' => (float) $sla_summary['below'],
					'text' => (string) (int) $sla_summary['below'],
					'token' => '--sr-critical'
				];
			}
			if ((int) $sla_summary['na'] > 0) {
				$slices[] = [
					'label' => _('Not measured'),
					'value' => (float) $sla_summary['na'],
					'text' => (string) (int) $sla_summary['na'],
					'token' => '--sr-axis'
				];
			}
			$graded = (int) $sla_summary['meeting'] + (int) $sla_summary['below'];
			?>
			<div class="sr-split">
				<div>
					<?= $chart->donut(
						$slices,
						$graded > 0 ? $helper->formatPct($graded > 0 ? ($sla_summary['meeting'] / $graded) * 100.0 : null, 0) : '—',
						_('meeting SLO'),
						['title' => _('Services meeting their SLO')]
					) ?>
				</div>
				<div class="sr-table-wrap">
					<table class="sr-table">
						<thead>
							<tr>
								<th><?= $esc(_('State')) ?></th>
								<th class="sr-num"><?= $esc(_('Services')) ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?= $fmt->swatch('--sr-ok') ?><?= $esc(_('Meeting SLO')) ?></td>
								<td class="sr-num sr-strong"><?= $esc((string) (int) $sla_summary['meeting']) ?></td>
							</tr>
							<tr>
								<td><?= $fmt->swatch('--sr-critical') ?><?= $esc(_('Below SLO')) ?></td>
								<td class="sr-num sr-strong"><?= $esc((string) (int) $sla_summary['below']) ?></td>
							</tr>
							<tr>
								<td><?= $fmt->swatch('--sr-axis') ?><?= $esc(_('Not measured')) ?></td>
								<td class="sr-num"><?= $esc((string) (int) $sla_summary['na']) ?></td>
							</tr>
							<tr>
								<td class="sr-strong"><?= $esc(_('SLO breaches in 12 months')) ?></td>
								<td class="sr-num sr-strong"><?= $esc((string) (int) $sla_summary['breach_months']) ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>

<div class="sr-panel">
	<div class="sr-panel-head">
		<h2 class="sr-panel-title"><?= $esc(_('Needs attention')) ?></h2>
		<?php if ($report['attention'] !== []): ?>
			<span class="sr-panel-note"><?= $esc(count($report['attention']) === 1
				? _('1 item')
				: sprintf(_('%1$d items'), count($report['attention']))) ?></span>
		<?php endif; ?>
	</div>

	<?php
		$has_subject = $report['groups'] !== [] || (int) $sla_summary['services_total'] > 0;
	?>
	<?php if ($report['attention'] === [] && !$has_subject): ?>
		<p class="sr-empty">
			<?= $esc(_('Nothing to check yet. No hosts or SLAs were found for this selection.')) ?>
		</p>
	<?php elseif ($report['attention'] === []): ?>
		<div class="sr-all-clear">
			<span aria-hidden="true">●</span>
			<span><?= $esc(_('Everything is on target. Every SLO is met and every measured host is above the availability target.')) ?></span>
		</div>
	<?php else: ?>
		<div class="sr-attention">
			<?php foreach (array_slice($report['attention'], 0, 12) as $item): ?>
				<div class="sr-attention-item sr-attention-item--<?= $esc($item['severity']) ?>">
					<span class="sr-attention-bar" aria-hidden="true"></span>
					<div class="sr-attention-body">
						<div class="sr-attention-title"><?= $esc($item['title']) ?></div>
						<div class="sr-attention-detail"><?= $esc($item['detail']) ?></div>
					</div>
					<span class="sr-attention-scope"><?= $esc($item['scope']) ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php if (count($report['attention']) > 12): ?>
			<p class="sr-hint" style="margin-top:12px">
				<?= $esc(sprintf(
					_('%1$d more items are listed on the SLA compliance and Host availability tabs.'),
					count($report['attention']) - 12
				)) ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
