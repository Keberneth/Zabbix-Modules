<?php declare(strict_types = 1);

/** @var array $data */
$helper = $data['helper'];
$detail = $data['detail'];
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$date = static fn(int $v): string => gmdate('Y-m-d H:i:s', $v).' UTC';
$back_query = ['action' => 'slareport.report.view'];
foreach ($data['return_filter'] as $key => $value) { $back_query['filter_'.$key] = $value; }
$back = 'zabbix.php?'.http_build_query($back_query);
$labels = ['missing' => _('Missing readings'), 'failed' => _('Failed readings'),
	'uncovered' => _('No trend coverage'), 'trend_failed' => _('Hour counted as down'),
	'trend_mixed' => _('Mixed readings; hour counted as down')];
$rule = $detail['measurement']['rule'] ?? $detail['item']['rule'] ?? null;
$measurement_choices = $detail['measurements'] ?? [];
$measurementid = $detail['measurement']['measurementid'] ?? '';
$selection_required = !empty($detail['selection_required']);
$criterion = $rule === null ? '' : ($rule['mode'] === 'equals'
	? sprintf(_('Value equals %1$s'), (string) $rule['value'])
	: ($rule['mode'] === 'range' ? sprintf(_('Value from %1$s to %2$s, inclusive'), (string) $rule['min'], (string) $rule['max'])
		: _('Value equals 1')));
$scopes = ['host' => _('Host override'), 'group' => _('Host group override'), 'global' => _('Global default')];
ob_start();
?>
<div class="slareport">
	<div class="sr-head">
		<div>
			<p class="sr-head-title"><?= $esc($detail ? ($detail['host']['name'] ?: $detail['host']['host']) : _('Host availability detail')) ?></p>
			<?php if (!empty($detail['measurement']['label'])): ?>
				<p class="sr-head-period"><?= $esc($detail['measurement']['label']) ?></p>
			<?php endif; ?>
			<p class="sr-hint"><?= $esc($date($data['from']).' — '.$date($data['to'])) ?></p>
		</div>
		<a class="sr-btn" href="<?= $esc($back) ?>"><?= $esc(_('Back to host availability')) ?></a>
	</div>
	<?php if ($data['error'] !== null): ?>
		<div class="sr-alert"><?= $esc($data['error']) ?></div>
	<?php elseif ($detail !== null): ?>
		<?php if ($detail['excluded']): ?><div class="sr-alert"><?= $esc(_('This host is excluded from the report by the shared settings. Evidence is shown for inspection only.')) ?></div><?php endif; ?>
		<?php foreach ($detail['warnings'] as $warning): ?><div class="sr-alert"><?= $esc($warning) ?></div><?php endforeach; ?>
		<div class="sr-panel">
			<h2><?= $esc(_('Measurement')) ?></h2>
			<?php if (count($measurement_choices) > 1): ?>
				<form method="get" action="zabbix.php" class="sr-filter">
					<input type="hidden" name="action" value="slareport.host.view">
					<?php foreach (['hostid' => $detail['host']['hostid'], 'time_from' => $data['from'], 'time_to' => $data['to'], 'return_filter' => json_encode($data['return_filter'])] as $name => $value): ?>
						<input type="hidden" name="<?= $esc($name) ?>" value="<?= $esc($value) ?>">
					<?php endforeach; ?>
					<div class="sr-filter-row"><div class="sr-field">
						<label for="sr-measurement"><?= $esc(_('Website / measurement')) ?></label>
						<select id="sr-measurement" name="measurementid" required>
							<?php if ($selection_required): ?><option value="" selected><?= $esc(_('Choose a measurement')) ?></option><?php endif; ?>
							<?php foreach ($measurement_choices as $choice): ?>
								<option value="<?= $esc($choice['measurementid']) ?>"<?= $choice['measurementid'] === $measurementid ? ' selected' : '' ?>><?= $esc($choice['label']) ?></option>
							<?php endforeach; ?>
						</select>
					</div><button class="sr-btn" type="submit"><?= $esc(_('Show measurement')) ?></button></div>
				</form>
			<?php endif; ?>
			<?php if (!$selection_required): ?>
			<p><?= $esc(_('Item')) ?>: <strong><?= $esc($detail['item']['key_'] ?? _('No matching item')) ?></strong>
				· <?= $esc($detail['source'] === 'trends' ? _('Hourly trends') : _('Raw history')) ?>
				· <?= $esc(sprintf(_('%1$s readings'), number_format($detail['samples'], 0, '.', ' '))) ?></p>
			<?php if ($rule !== null): ?>
				<p><?= $esc(_('Healthy reading')) ?>: <strong><?= $esc($criterion) ?></strong>
					· <?= $esc($scopes[$rule['scope'] ?? 'global']) ?></p>
			<?php endif; ?>
			<?php if ($detail['interval'] !== null): ?>
				<p><?= $esc(_('Polling interval')) ?>: <?= $esc($helper->formatDuration($detail['interval'])) ?>
					(<?= $esc($detail['fixed_cadence'] ? _('configured') : _('estimated')) ?>)</p>
			<?php endif; ?>
			<p class="sr-hint"><?= $esc(_('All times below are UTC; end times are exclusive. Missing readings and observed failures are different evidence. The report uses sample counts, while these spans infer duration from the polling interval; their total can differ from the reported downtime. Small polling jitter is not shown as a gap.')) ?></p>
			<?php endif; ?>
		</div>
		<div class="sr-panel">
			<h2><?= $esc(_('Maintenance windows')) ?> · <?= $esc($helper->formatDuration($detail['maintenance_seconds'])) ?></h2>
			<p class="sr-hint"><?= $esc(sprintf(_('Overlapping windows count once in the total. Recurring windows are expanded using %1$s. The list reflects the current maintenance configuration and your Zabbix permissions.'), $detail['timezone'])) ?></p>
			<p class="sr-hint"><?= $esc(_('These maintenance windows apply to every measurement on this host, including all websites and scenarios.')) ?></p>
			<?php if ($detail['maintenance'] === []): ?>
				<p><?= $esc(_('No applicable maintenance windows were found in this period.')) ?></p>
			<?php else: ?>
				<div class="sr-table-wrap"><table class="sr-table"><thead><tr>
					<th scope="col"><?= $esc(_('Maintenance')) ?></th><th scope="col"><?= $esc(_('Start (UTC)')) ?></th><th scope="col"><?= $esc(_('End (UTC, exclusive)')) ?></th>
					<th scope="col" class="sr-num"><?= $esc(_('Duration in period')) ?></th><th scope="col"><?= $esc(_('Data collection')) ?></th>
				</tr></thead><tbody>
				<?php foreach ($detail['maintenance'] as $window): ?>
					<tr><td><?= $esc($window['name']) ?></td><td><?= $esc($date($window['start'])) ?></td><td><?= $esc($date($window['end'])) ?></td>
						<td class="sr-num"><?= $esc($helper->formatDuration($window['end'] - $window['start'])) ?></td>
						<td><?= $esc((int) $window['maintenance_type'] === 0 ? _('With data collection') : _('Without data collection')) ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
		</div>
		<div class="sr-panel">
			<h2><?= $esc(_('Gaps and failed readings outside maintenance')) ?></h2>
			<?php if ($data['can_create']): ?>
				<p class="sr-hint"><?= $esc(_('For each confirmed gap, review Create maintenance and record why this was planned work. This creates a real, retrospective Zabbix maintenance for the entire host, with data collection enabled. Every website and measurement on this host will exclude the window, not only the selected measurement. It does not recreate historical problem suppression or rewrite SLA history.')) ?></p>
				<p class="sr-hint"><?= $esc(_('Creation is offered only for completely scanned raw-history gaps bounded by readings and a fixed polling interval. Zabbix requires at least five whole minutes; the proposed window is kept inside the gap. Separate days have separate choices.')) ?></p>
			<?php endif; ?>
			<?php if ($selection_required): ?>
				<p><?= $esc(_('Select a measurement above to load its readings and gaps.')) ?></p>
			<?php elseif ($detail['intervals'] === []): ?>
				<p><?= $esc($detail['item'] === null ? _('Gap evidence requires a matching measurement item.') : _('No gap or failed-reading intervals were found in the available evidence.')) ?></p>
			<?php else: ?>
				<div class="sr-table-wrap"><table class="sr-table"><thead><tr>
					<th scope="col"><?= $esc(_('Evidence')) ?></th><th scope="col"><?= $esc(_('Start (UTC)')) ?></th><th scope="col"><?= $esc(_('End (UTC, exclusive)')) ?></th>
					<th scope="col" class="sr-num"><?= $esc(_('Duration')) ?></th><th scope="col"><?= $esc(_('Action / precision')) ?></th>
				</tr></thead><tbody>
				<?php foreach ($detail['intervals'] as $i => $span): ?>
					<tr><td><?= $esc($labels[$span['kind']]) ?></td><td><?= $esc($date($span['start'])) ?></td><td><?= $esc($date($span['end'])) ?></td>
						<td class="sr-num"><?= $esc($helper->formatDuration($span['seconds'])) ?></td><td>
						<?php if ($data['can_create'] && $span['creatable']): ?>
							<details><summary><?= $esc(_('Create maintenance')) ?></summary>
								<form method="post" action="zabbix.php">
									<input type="hidden" name="action" value="slareport.maintenance.create">
									<input type="hidden" name="<?= $esc(CSRF_TOKEN_NAME) ?>" value="<?= $esc(CCsrfTokenHelper::get('slareport.maintenance.create')) ?>">
									<input type="hidden" name="return_filter" value="<?= $esc(json_encode($data['return_filter'])) ?>">
									<input type="hidden" name="measurementid" value="<?= $esc($measurementid) ?>">
									<?php foreach (['hostid' => $detail['host']['hostid'], 'time_from' => $data['from'], 'time_to' => $data['to'], 'gap_start' => $span['start'], 'gap_end' => $span['end']] as $name => $value): ?>
										<input type="hidden" name="<?= $esc($name) ?>" value="<?= $esc($value) ?>">
									<?php endforeach; ?>
									<p><?= $esc($date($span['proposal']['start']).' — '.$date($span['proposal']['end'])) ?></p>
									<p><label for="sr-reason-<?= (int) $i ?>"><?= $esc(_('Reason / change reference')) ?></label><br>
										<input id="sr-reason-<?= (int) $i ?>" name="reason" type="text" required minlength="3" maxlength="1000"></p>
									<p><label><input name="acknowledge" type="checkbox" value="1" required>
										<?= $esc(_('I confirm this was planned maintenance for the host and this window should be excluded from every measurement on the host.')) ?></label></p>
									<button class="sr-btn" type="submit"><?= $esc(_('Create this maintenance window')) ?></button>
								</form>
							</details>
						<?php elseif ($span['kind'] === 'missing'): ?>
							<?= $esc(!$span['bracketed'] ? _('Unconfirmed boundary or estimated cadence; inspect collection and retention.')
								: ($span['proposal'] === null ? _('Less than five whole minutes; inspect manually.') : _('Maintenance creation requires eligible evidence and host edit permission.'))) ?>
						<?php elseif ($span['kind'] === 'failed'): ?>
							<?= $esc(_('Reading did not meet the configured healthy-value rule.')) ?>
						<?php else: ?><?= $esc(_('Hourly precision; inspect raw history for exact boundaries.')) ?><?php endif; ?>
						</td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
(new CHtmlPage())->setTitle($data['title'])->addItem(new CObject($content))->show();
