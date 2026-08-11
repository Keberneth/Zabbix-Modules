<?php declare(strict_types = 1);

/**
 * SLA compliance tab - one card per SLA: a rolling 12-month heatmap per
 * service, and this period's error budget.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\SlaUptimeReport\Helpers\ReportDataHelper $helper
 * @var \Modules\SlaUptimeReport\Helpers\ChartRenderer $chart
 * @var \Modules\SlaUptimeReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 */
?>

<?php if ($report['slas'] === []): ?>
	<div class="sr-panel">
		<p class="sr-empty">
			<?= $esc(_('No enabled SLAs with linked services were found. Configure SLAs under Services → SLA, link services by tag, and they appear here.')) ?>
		</p>
	</div>
<?php endif; ?>

<?php foreach ($report['slas'] as $sla): ?>
	<div class="sr-panel">
		<div class="sr-panel-head">
			<h2 class="sr-panel-title"><?= $esc($sla['name']) ?></h2>
			<span class="sr-panel-note">
				<?= $esc(sprintf(_('SLO %1$s'), $helper->formatPct($sla['slo'], 2))) ?>
			</span>
		</div>
		<p class="sr-panel-sub">
			<?php $service_count = count($sla['services']); ?>
			<?php if ((int) $sla['below'] === 0 && (int) $sla['na'] === 0): ?>
				<?= $esc(sprintf(
					$service_count === 1
						? _('The service meets its SLO in the latest measured period. 12-period average %2$s.')
						: _('All %1$d services meet their SLO in the latest measured period. 12-period average %2$s.'),
					$service_count,
					$helper->formatPct($sla['avg'], 2)
				)) ?>
			<?php elseif ((int) $sla['below'] > 0): ?>
				<?= $esc(sprintf(
					$service_count === 1
						? _('The service is below its SLO in the latest measured period. 12-period average %3$s.')
						: ((int) $sla['below'] === 1
							? _('%1$d of %2$d services is below its SLO in the latest measured period. 12-period average %3$s.')
							: _('%1$d of %2$d services are below their SLO in the latest measured period. 12-period average %3$s.')),
					(int) $sla['below'],
					$service_count,
					$helper->formatPct($sla['avg'], 2)
				)) ?>
			<?php else: ?>
				<?= $esc(sprintf(
					$service_count === 1
						? _('1 service, 12-period average %2$s.')
						: _('%1$d services, 12-period average %2$s.'),
					$service_count,
					$helper->formatPct($sla['avg'], 2)
				)) ?>
			<?php endif; ?>
		</p>

		<div class="sr-table-wrap">
			<table class="sr-table sr-heatmap">
				<thead>
					<tr>
						<th><?= $esc(_('Service')) ?></th>
						<th><?= $esc(_('State')) ?></th>
						<?php foreach ($sla['months'] as $month): ?>
							<th class="sr-heat-head" title="<?= $esc($month) ?>"><?= $esc($helper->shortMonth($month)) ?></th>
						<?php endforeach; ?>
						<th class="sr-num"><?= $esc(_('Breaches')) ?></th>
						<th style="min-width:180px"><?= $esc(sprintf(
							_('Error budget (%1$s)'),
							$sla['months'] !== [] ? $helper->shortMonth((string) end($sla['months'])) : _('this period')
						)) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($sla['services'] as $service): ?>
						<tr>
							<td class="sr-strong"><?= $esc($service['name']) ?></td>
							<td><?= $fmt->sloCompliance($service['state'], $service['latest'], $sla['slo']) ?></td>
							<?php foreach ($service['sli'] as $value): ?>
								<?= $fmt->sliCell($value, $sla['slo']) ?>
							<?php endforeach; ?>
							<td class="sr-num<?= $service['breaches'] > 0 ? ' sr-strong' : ' sr-dim' ?>">
								<?= $esc((string) (int) $service['breaches']) ?>
							</td>
							<td>
								<?php if ($service['budget'] === null): ?>
									<span class="sr-dim">—</span>
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
									<div class="sr-dim" style="font-size:11.5px; margin-top:4px">
										<?php if ($used !== null && $used >= 100.0): ?>
											<?= $esc(sprintf(
												_('Budget exceeded by %1$s'),
												$helper->formatDuration(max(0, -(int) $service['budget']['remaining_seconds']))
											)) ?>
										<?php else: ?>
											<?= $esc(sprintf(
												_('%1$s of %2$s used'),
												$helper->formatDuration((int) $service['budget']['consumed_seconds']),
												$helper->formatDuration((int) $service['budget']['allowed_seconds'])
											)) ?>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<p class="sr-hint" style="margin-top:10px">
			<?= $esc(_('Cells are tinted against the SLO: green at or above, amber up to half a point below, red further below. A dash means the period was not measured.')) ?>
		</p>
	</div>
<?php endforeach; ?>
