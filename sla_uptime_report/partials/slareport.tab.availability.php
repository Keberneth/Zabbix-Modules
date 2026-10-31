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

$show_maintenance = true;
?>

<div class="sr-kpis">
	<div class="sr-kpi sr-kpi--<?= $fleet['avg'] === null ? 'neutral'
		: ($fleet['avg'] >= $target ? 'ok'
			: ($fleet['avg'] >= \Modules\SlaUptimeReport\Helpers\ReportDataHelper::BAD_THRESHOLD ? 'warning' : 'critical')) ?>">
		<div class="sr-kpi-label"><?= $esc(_('Average check availability')) ?></div>
		<div class="sr-kpi-value"><?= $esc($helper->formatPct($fleet['avg'], 2)) ?></div>
		<div class="sr-kpi-sub"><?= $esc(sprintf(_('Across %1$d checks with data'), (int) $fleet['with_data'])) ?></div>
	</div>
	<div class="sr-kpi sr-kpi--<?= (int) $fleet['below_target'] > 0 ? 'warning' : 'ok' ?>">
		<div class="sr-kpi-label"><?= $esc(sprintf(_('Below %1$s'), $helper->formatTargetPct($target))) ?></div>
		<div class="sr-kpi-value"><?= $esc((string) (int) $fleet['below_target']) ?></div>
		<div class="sr-kpi-sub"><?= $esc(_('Checks under the availability target')) ?></div>
	</div>
	<div class="sr-kpi sr-kpi--neutral">
		<div class="sr-kpi-label"><?= $esc(_('Total downtime')) ?></div>
		<div class="sr-kpi-value"><?= $esc($fleet['with_data'] > 0
			? $helper->formatDuration((int) $fleet['downtime_seconds'])
			: '—') ?></div>
		<div class="sr-kpi-sub"><?= $esc(_('Summed across all measured checks')) ?></div>
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
		<div class="sr-kpi-label"><a href="<?= $esc($nodata_url) ?>" data-sr-view-nodata><?= $esc(_('Without data')) ?></a></div>
		<div class="sr-kpi-value"><a href="<?= $esc($nodata_url) ?>" data-sr-view-nodata><?= $esc((string) (int) $fleet['na']) ?></a></div>
		<div class="sr-kpi-sub"><?= $esc(_('No availability item, or no samples')) ?></div>
	</div>
</div>

<details class="sr-panel" id="sr-without-data" open tabindex="-1">
	<summary class="sr-panel-title"><?= $esc(sprintf(_('Checks without data (%1$d)'), (int) $fleet['na'])) ?></summary>
	<p class="sr-hint"><?= $esc(_('This list includes every matching check without a usable measurement, including rows beyond the rows-per-group limit. Open a check to inspect its item and gaps.')) ?></p>
	<?php if ($report['availability_health']['nodata'] === []): ?>
		<p class="sr-empty"><?= $esc(_('No checks without data.')) ?></p>
	<?php else: ?>
		<div class="sr-table-wrap"><table class="sr-table">
			<thead><tr><th scope="col"><?= $esc(_('Host / check')) ?></th><th scope="col"><?= $esc(_('Reason')) ?></th><th scope="col"><?= $esc(_('Item')) ?></th></tr></thead>
			<tbody><?php foreach ($report['availability_health']['nodata'] as $row): ?>
				<tr><td><a href="<?= $esc($host_url((string) $row['hostid'], $row['measurementid'] ?? '')) ?>"><?= $esc($helper::measurementName($row)) ?></a></td>
					<td><?= $esc($row['state'] === 'noitem' ? _('No enabled numeric item matching the configured rule') : _('No usable data or incomplete scan')) ?></td>
					<td><?= $esc($row['item_key'] ?? '—') ?></td></tr>
			<?php endforeach; ?></tbody>
		</table></div>
	<?php endif; ?>
</details>

<?php if ($report['groups'] === []): ?>
	<div class="sr-panel">
		<p class="sr-empty">
			<?= $esc(_('No hosts matched the filter and report settings.')) ?>
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
					_('Showing the %1$d worst of %2$d checks. Raise "Rows per group" under More filters to see the rest.'),
					count($group['rows']),
					(int) $group['rows_total']
				)) ?>
			</p>
		<?php endif; ?>

		<div class="sr-table-wrap">
			<table class="sr-table">
				<thead>
					<tr>
						<th scope="col"><?= $esc(_('Host')) ?></th>
						<th scope="col"><?= $esc(_('Measurement')) ?></th>
						<th scope="col"><?= $esc(_('Availability')) ?></th>
						<th scope="col" class="sr-num"><?= $esc(_('Coverage')) ?></th>
						<th scope="col"><?= $esc(_('Daily trend')) ?></th>
						<th scope="col" class="sr-num"><?= $esc(_('Uptime')) ?></th>
						<th scope="col" class="sr-num"><?= $esc(_('Downtime')) ?></th>
						<?php if ($show_maintenance): ?>
							<th scope="col" class="sr-num"><?= $esc(_('Maintenance')) ?></th>
						<?php endif; ?>
						<th scope="col"><?= $esc(_('Item')) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($group['rows'] as $row): ?>
						<tr>
							<td class="sr-strong">
								<a href="<?= $esc($host_url((string) $row['hostid'], $row['measurementid'] ?? '')) ?>"><?= $esc($row['host']) ?></a>
								<?php if (!$row['enabled']): ?>
									<span class="sr-status sr-status--neutral" style="margin-left:8px"><?= $esc(_('disabled')) ?></span>
								<?php endif; ?>
							</td>
							<td><a href="<?= $esc($host_url((string) $row['hostid'], $row['measurementid'] ?? '')) ?>"><?= $esc($row['measurement_label'] ?? $row['item_key'] ?? '—') ?></a></td>
							<td><?= $fmt->hostState((string) $row['state'], $row['pct']) ?></td>
							<td class="sr-num"><?= $esc($helper->formatPct($row['coverage_pct'] ?? null, 1)) ?></td>
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
									: '0s') ?></td>
							<?php endif; ?>
							<td class="sr-dim"><?= $esc($row['item_key'] ?? _('none')) ?>
								<?php if (!empty($row['source'])): ?><br><small><?= $esc($helper::sourceLabel($row['source'])) ?></small><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endforeach; ?>

<div class="sr-panel">
	<p class="sr-hint"><?= $esc(_('Each configured check has its own availability result. Averages and total downtime combine checks; they do not represent the percentage of time all websites were available together. Maintenance is host-wide: rows show its effect on each check, while the maintenance total counts each host once.')) ?></p>
	<p class="sr-hint">
		<?= $esc(_('Coverage is observed samples versus expected polls for raw history, or covered eligible time for hourly trends. Missing raw samples count against availability; uncovered trend hours are unknown and excluded. Hourly trends classify the whole hour conservatively. Open a host for timestamped evidence.')) ?>
	</p>
	<p class="sr-hint">
		<?= $esc(sprintf(
			_('Maintenance windows on a host, or on one of its host groups, are accepted downtime: that time is left out of the host\'s measured time and counts as neither uptime nor downtime. Recurring maintenance periods are laid out in the %1$s time zone (the frontend default), which must match the Zabbix server\'s.'),
			$report['maintenance']['timezone']
		)) ?>
		<?php if ($report['source_used'] !== 'history'): ?>
			<?= $esc(_('On hourly trends, an hour that touches a maintenance window is left out whole.')) ?>
		<?php endif; ?>
		<?php if ($report['maintenance']['restricted']): ?>
			<?= $esc(_('Only maintenances whose hosts and host groups you can all read are applied.')) ?>
		<?php endif; ?>
	</p>
</div>
