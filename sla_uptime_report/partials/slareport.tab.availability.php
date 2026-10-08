<?php declare(strict_types = 1);

/**
 * Host availability tab - every host, grouped by host group, worst first.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\SlaUptimeReport\Helpers\ReportDataHelper $helper
 * @var \Modules\SlaUptimeReport\Helpers\ChartRenderer $chart
 * @var \Modules\SlaUptimeReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 */

$fleet = $report['fleet'];
$target = (float) $filter['target'];

// The maintenance column only appears when some host had maintenance.
$show_maintenance = (int) $fleet['maintenance_hosts'] > 0;
?>

<div class="sr-kpis">
	<div class="sr-kpi sr-kpi--<?= $fleet['avg'] === null ? 'neutral'
		: ($fleet['avg'] >= $target ? 'ok'
			: ($fleet['avg'] >= \Modules\SlaUptimeReport\Helpers\ReportDataHelper::BAD_THRESHOLD ? 'warning' : 'critical')) ?>">
		<div class="sr-kpi-label"><?= $esc(_('Fleet availability')) ?></div>
		<div class="sr-kpi-value"><?= $esc($helper->formatPct($fleet['avg'], 2)) ?></div>
		<div class="sr-kpi-sub"><?= $esc(sprintf(_('Across %1$d hosts with data'), (int) $fleet['with_data'])) ?></div>
	</div>
	<div class="sr-kpi sr-kpi--<?= (int) $fleet['below_target'] > 0 ? 'warning' : 'ok' ?>">
		<div class="sr-kpi-label"><?= $esc(sprintf(_('Below %1$s'), $helper->formatTargetPct($target))) ?></div>
		<div class="sr-kpi-value"><?= $esc((string) (int) $fleet['below_target']) ?></div>
		<div class="sr-kpi-sub"><?= $esc(_('Hosts under the availability target')) ?></div>
	</div>
	<div class="sr-kpi sr-kpi--neutral">
		<div class="sr-kpi-label"><?= $esc(_('Total downtime')) ?></div>
		<div class="sr-kpi-value"><?= $esc($fleet['with_data'] > 0
			? $helper->formatDuration((int) $fleet['downtime_seconds'])
			: '—') ?></div>
		<div class="sr-kpi-sub"><?= $esc(_('Summed across all measured hosts')) ?></div>
	</div>
	<?php if ($show_maintenance): ?>
		<div class="sr-kpi sr-kpi--neutral">
			<div class="sr-kpi-label"><?= $esc(_('Maintenance')) ?></div>
			<div class="sr-kpi-value"><?= $esc($helper->formatDuration((int) $fleet['maintenance_seconds'])) ?></div>
			<div class="sr-kpi-sub"><?= $esc((int) $fleet['maintenance_hosts'] === 1
				? _('On 1 host, excluded from availability')
				: sprintf(_('On %1$d hosts, excluded from availability'), (int) $fleet['maintenance_hosts'])) ?></div>
		</div>
	<?php endif; ?>
	<div class="sr-kpi sr-kpi--<?= (int) $fleet['na'] > 0 ? 'warning' : 'ok' ?>">
		<div class="sr-kpi-label"><?= $esc(_('Without data')) ?></div>
		<div class="sr-kpi-value"><?= $esc((string) (int) $fleet['na']) ?></div>
		<div class="sr-kpi-sub"><?= $esc(_('No availability item, or no samples')) ?></div>
	</div>
</div>

<?php if ($report['groups'] === []): ?>
	<div class="sr-panel">
		<p class="sr-empty">
			<?= $esc(_('No hosts matched the filter. Hosts need an agent.ping, icmpping or agent-available item to be measured.')) ?>
		</p>
	</div>
<?php endif; ?>

<?php foreach ($report['groups'] as $group): ?>
	<div class="sr-panel">
		<div class="sr-panel-head">
			<h2 class="sr-panel-title">
				<?= $fmt->swatch($group['token']) ?><?= $esc($group['name']) ?>
			</h2>
			<span class="sr-panel-note">
				<?= $esc(sprintf(
					_('Average %1$s · worst %2$s'),
					$helper->formatPct($group['avg'], 2),
					$group['worst_host'] !== null
						? $group['worst_host'].' '.$helper->formatPct($group['worst_pct'], 2)
						: '—'
				)) ?>
			</span>
		</div>

		<?php if ((int) $group['rows_total'] > count($group['rows'])): ?>
			<p class="sr-panel-sub">
				<?= $esc(sprintf(
					_('Showing the %1$d worst of %2$d hosts. Raise "Rows per group" under More filters to see the rest.'),
					count($group['rows']),
					(int) $group['rows_total']
				)) ?>
			</p>
		<?php endif; ?>

		<div class="sr-table-wrap">
			<table class="sr-table">
				<thead>
					<tr>
						<th><?= $esc(_('Host')) ?></th>
						<th><?= $esc(_('Availability')) ?></th>
						<th><?= $esc(_('Daily trend')) ?></th>
						<th class="sr-num"><?= $esc(_('Uptime')) ?></th>
						<th class="sr-num"><?= $esc(_('Downtime')) ?></th>
						<?php if ($show_maintenance): ?>
							<th class="sr-num"><?= $esc(_('Maintenance')) ?></th>
						<?php endif; ?>
						<th><?= $esc(_('Item')) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($group['rows'] as $row): ?>
						<tr>
							<td class="sr-strong">
								<?= $esc($row['host']) ?>
								<?php if (!$row['enabled']): ?>
									<span class="sr-status sr-status--neutral" style="margin-left:8px"><?= $esc(_('disabled')) ?></span>
								<?php endif; ?>
							</td>
							<td><?= $fmt->hostState((string) $row['state'], $row['pct']) ?></td>
							<td><?= $chart->sparkline($row['spark'], $row['state'] === 'bad' ? '--sr-critical'
								: ($row['state'] === 'warn' ? '--sr-warning' : '--sr-s1')) ?></td>
							<td class="sr-num"><?= $esc($row['pct'] !== null
								? $helper->formatDuration((int) $row['uptime_seconds'])
								: '—') ?></td>
							<td class="sr-num<?= (int) $row['downtime_seconds'] > 0 && $row['pct'] !== null ? ' sr-strong' : '' ?>">
								<?= $esc($row['pct'] !== null
									? $helper->formatDuration((int) $row['downtime_seconds'])
									: '—') ?>
							</td>
							<?php if ($show_maintenance): ?>
								<td class="sr-num sr-dim"><?= $esc((int) $row['maintenance_seconds'] > 0
									? $helper->formatDuration((int) $row['maintenance_seconds'])
									: '—') ?></td>
							<?php endif; ?>
							<td class="sr-dim"><?= $esc($row['item_key'] ?? _('none')) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endforeach; ?>

<div class="sr-panel">
	<p class="sr-hint">
		<?php if ($report['source_used'] === 'trends'): ?>
			<?= $esc(_('This period is read from hourly trends: each hour counts as up or down as a whole, so short blips inside an hour can round away. For exact figures pick a window of 7 days or less, which is read from raw history.')) ?>
		<?php else: ?>
			<?= $esc(_('This period is read from raw history samples. Missing samples count as downtime, so a polling gap shows up rather than hiding.')) ?>
		<?php endif; ?>
	</p>
	<p class="sr-hint">
		<?= $esc(sprintf(
			_('Maintenance windows on a host, or on one of its host groups, are accepted downtime: that time is left out of the host\'s measured time and counts as neither uptime nor downtime. Recurring maintenance periods are laid out in the %1$s time zone (the frontend default), which must match the Zabbix server\'s.'),
			$report['maintenance']['timezone']
		)) ?>
		<?php if ($report['source_used'] === 'trends'): ?>
			<?= $esc(_('On hourly trends, an hour that touches a maintenance window is left out whole.')) ?>
		<?php endif; ?>
		<?php if ($report['maintenance']['restricted']): ?>
			<?= $esc(_('Only maintenances whose hosts and host groups you can all read are applied.')) ?>
		<?php endif; ?>
	</p>
</div>
