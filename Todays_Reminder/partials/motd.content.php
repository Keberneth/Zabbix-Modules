<?php
/** Shared overview body. $motd is supplied by OverviewRenderer. */
$h = static function($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$section = static function(string $name) use ($motd): array {
	return is_array($motd[$name] ?? null) ? $motd[$name] : ['status' => 'unavailable'];
};
$notice = static function(array $value) use ($h): string {
	if (($value['status'] ?? 'unavailable') === 'ok') {
		return '';
	}
	$message = $value['message'] ?? '';
	if ($message === '') {
		$message = ($value['status'] ?? '') === 'partial'
			? _('Some data in this section is unavailable.') : _('This section is unavailable.');
	}
	return '<p class="motd-section-notice">'.$h($message).'</p>';
};
$count = static function($value, bool $lower_bound = false) use ($h): string {
	return $value === null ? $h(_('Unavailable')) : $h((string) (int) $value).($lower_bound ? '+' : '');
};
$problemTable = static function(array $rows, string $label, bool $resolved = false) use ($h): string {
	ob_start();
	?>
	<div class="motd-table-scroll" role="region" aria-label="<?= $h($label) ?>" tabindex="0">
	<table class="motd-table">
		<thead><tr>
			<th scope="col"><?= $h(_('Severity')) ?></th><th scope="col"><?= $h(_('Problem')) ?></th>
			<th scope="col"><?= $h(_('Started')) ?></th><th scope="col"><?= $h(_('Host(s)')) ?></th>
			<?php if ($resolved): ?><th scope="col"><?= $h(_('Resolved')) ?></th><th scope="col"><?= $h(_('MTTR')) ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ($rows as $row): ?>
			<tr>
				<td><span class="motd-pill motd-pill--<?= $h($row['severity_key'] ?? 'info') ?>"><?= $h($row['severity_label'] ?? '') ?></span></td>
				<td>
					<?php if (!empty($row['url'])): ?><a href="<?= $h($row['url']) ?>"><?= $h($row['name'] ?? '') ?></a>
					<?php else: ?><?= $h($row['name'] ?? '') ?><?php endif; ?>
					<div class="motd-subtext">#<?= $h($row['eventid'] ?? '') ?><?php if (!empty($row['age_text'])): ?> · <?= $h($row['age_text']) ?><?php endif; ?></div>
				</td>
				<td><?= $h($row['clock_text'] ?? '') ?></td><td><?= $h($row['host_text'] ?? '') ?></td>
				<?php if ($resolved): ?><td><?= $h($row['resolved_age_text'] ?? '') ?></td><td><?= $h($row['mttr_text'] ?? '') ?></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php
	return (string) ob_get_clean();
};
$problems = $section('problems');
$health = $section('health');
$new_hosts = $section('new_hosts');
$maintenance = $section('maintenances');
?>
<div class="motd-overview-summary">
<p class="motd-page__summary"><?= $h($motd['summary_line'] ?? _('Reminder data is unavailable.')) ?></p>
<?php
$scope = $motd['filters'] ?? [];
$scope_parts = [];
if (($scope['groupid'] ?? '0') !== '0') {
	$group_name = '#'.(string) $scope['groupid'];
	foreach ($motd['filter_options']['groups'] ?? [] as $group) {
		if ((string) $group['groupid'] === (string) $scope['groupid']) { $group_name = (string) $group['name']; break; }
	}
	$scope_parts[] = _('Host group').': '.$group_name;
}
if (!empty($scope['tag'])) {
	$scope_parts[] = _('Tag').': '.$scope['tag'].(($scope['tag_value'] ?? '') !== '' ? '='.$scope['tag_value'] : '');
}
?>
<p class="motd-subtext motd-confirmed-scope"><?= $h(_('Showing data for')) ?>: <?= $h($scope_parts ? implode(' · ', $scope_parts) : _('All accessible hosts')) ?></p>
<?php if (!empty($motd['chips'])): ?>
<div class="motd-chip-row motd-chip-row--page">
	<?php foreach ($motd['chips'] as $chip): ?>
		<?php $tag = !empty($chip['url']) ? 'a' : 'span'; ?>
		<<?= $tag ?> class="motd-chip motd-chip--<?= $h($chip['kind'] ?? 'info') ?>"<?php if ($tag === 'a'): ?> href="<?= $h($chip['url']) ?>"<?php endif; ?>>
			<span class="motd-chip__label"><?= $h($chip['label'] ?? '') ?></span>
			<span class="motd-chip__value"><?= $h($chip['value'] ?? _('Unavailable')) ?></span>
		</<?= $tag ?>>
	<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?= $notice($problems) ?>
<div class="motd-grid">
<?php foreach ([
	['key' => 'unacked', 'title' => _('Unacknowledged High / Critical'), 'empty' => _('No unacknowledged High/Critical problems in this scope.')],
	['key' => 'longest', 'title' => _('Longest-running High / Critical problems'), 'empty' => _('No unresolved High/Critical problems in this scope.')],
	['key' => 'resolved', 'title' => _('Recently resolved (24h)'), 'empty' => _('No High/Critical problems resolved in the last 24 hours in this scope.')]
] as $card): ?>
	<?php $value = $section($card['key']); ?>
	<section class="motd-card">
		<div class="motd-card__header">
			<h2><?= $h($card['title']) ?><?php if (isset($value['count'])): ?>
				<span class="motd-count"><?= $count($value['count'], !empty($value['count_is_lower_bound'])) ?></span>
			<?php endif; ?></h2>
			<?php $url = $value['url'] ?? ($card['key'] === 'longest' ? ($problems['high_critical_url'] ?? '') : ''); ?>
			<?php if ($url !== ''): ?><a class="motd-card__link" href="<?= $h($url) ?>"><?= $h(_('Open view')) ?></a><?php endif; ?>
		</div>
		<?= $notice($value) ?>
		<?php if ($card['key'] === 'resolved'): ?>
			<p class="motd-subtext"><?= $h(_('Average MTTR')) ?>:
				<?= $h($value['avg_mttr_text'] ?? '') !== '' ? $h($value['avg_mttr_text']) : $h(_('Unavailable')) ?>
			</p>
		<?php endif; ?>
		<?php if (!empty($value['recent'])): ?>
			<?= $problemTable($value['recent'], $card['title'], $card['key'] === 'resolved') ?>
		<?php elseif (($value['status'] ?? '') === 'ok'): ?><div class="motd-empty"><?= $h($card['empty']) ?></div><?php endif; ?>
		<?php if (!empty($value['truncated'])): ?><p class="motd-subtext"><?= $h(_('The result is incomplete; the count is a lower bound.')) ?></p><?php endif; ?>
	</section>
<?php endforeach; ?>
	<section class="motd-card" id="motd-monitoring-health">
		<div class="motd-card__header"><h2><?= $h(_('Monitoring health')) ?></h2></div>
		<?= $notice($health) ?>
		<?php
		$unsupported = $health['stale_items_total'] ?? null;
		$unsupported_hosts = $health['stale_items_hosts'] ?? null;
		$unsupported_text = $unsupported === null ? _('Unavailable') : sprintf(_('%1$d unsupported items'), (int) $unsupported);
		if ($unsupported !== null && $unsupported_hosts !== null) {
			$unsupported_text .= ' · '.(!empty($health['stale_items_hosts_truncated']) || !empty($health['stale_items_truncated'])
				? sprintf(_('at least %1$d hosts'), (int) $unsupported_hosts) : sprintf(_('%1$d hosts'), (int) $unsupported_hosts));
		}
		$rows = [
			['field' => 'stale_items_total', 'label' => _('Unsupported items'), 'text' => $unsupported_text, 'url' => $health['stale_url'] ?? ''],
			['field' => 'unreachable_hosts', 'label' => _('Unreachable hosts'), 'url' => ''],
			['field' => 'unreachable_proxies', 'label' => _('Unreachable proxies (global)'), 'url' => $health['proxy_url'] ?? ''],
			['field' => 'queue_backlog', 'label' => _('Server queue > 10m (global)'), 'url' => $health['queue_url'] ?? '']
		];
		?>
		<ul class="motd-health">
		<?php foreach ($rows as $row): ?>
			<?php $number = $health[$row['field']] ?? null; $text = $row['text'] ?? ($number === null ? _('Unavailable') : (string) (int) $number);
			if (!empty($health[$row['field'].'_truncated']) && $number !== null) { $text .= '+'; } ?>
			<li class="motd-health__row">
				<span class="motd-health__label"><?= $h($row['label']) ?></span>
				<span class="motd-health__value">
					<?php if ($row['url'] !== '' && $number !== null): ?><a href="<?= $h($row['url']) ?>"><?= $h($text) ?></a>
					<?php else: ?><?= $h($text) ?><?php endif; ?>
					<?php if (!empty($health['field_messages'][$row['field']])): ?><span class="motd-subtext motd-health__detail"><?= $h($health['field_messages'][$row['field']]) ?></span><?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php if (!empty($health['unreachable_details'])): ?>
			<details class="motd-host-details"><summary><?= $h(_('Show unreachable hosts')) ?></summary>
				<ul><?php foreach ($health['unreachable_details'] as $host): ?><li>
					<?php if (!empty($host['url'])): ?><a href="<?= $h($host['url']) ?>"><?= $h($host['name'] ?? '') ?></a>
					<?php else: ?><?= $h($host['name'] ?? '') ?><?php endif; ?>
				</li><?php endforeach; ?></ul>
				<?php if (!empty($health['unreachable_details_truncated'])): ?><p class="motd-subtext"><?= $h(_('Only the first matching hosts are listed.')) ?></p><?php endif; ?>
			</details>
		<?php endif; ?>
		<div class="motd-new-hosts"><h3><?= $h(_('New hosts (24h)')) ?>: <?= $count($new_hosts['count'] ?? null, !empty($new_hosts['count_is_lower_bound'])) ?></h3>
			<?= $notice($new_hosts) ?>
			<?php if (!empty($new_hosts['coverage_note'])): ?><p class="motd-subtext"><?= $h($new_hosts['coverage_note']) ?></p><?php endif; ?>
			<?php if (!empty($new_hosts['recent'])): ?><ul><?php foreach ($new_hosts['recent'] as $host): ?><li><?= $h($host['name'] ?? '') ?></li><?php endforeach; ?></ul><?php endif; ?>
		</div>
	</section>
</div>
<div class="motd-grid" id="motd-maintenance">
<?php foreach ([['key' => 'today', 'title' => _('Today’s maintenance windows')], ['key' => 'week', 'title' => _('Upcoming later this week')]] as $card): ?>
	<section class="motd-card">
		<div class="motd-card__header"><h2><?= $h($card['title']) ?></h2></div>
		<?php if (!empty($motd['maintenance_timezone'])): ?><p class="motd-subtext"><?= $h(_('Schedule timezone')) ?>: <?= $h($motd['maintenance_timezone']) ?></p><?php endif; ?>
		<?= $notice($maintenance) ?>
		<?php if (!empty($maintenance[$card['key']])): ?>
			<div class="motd-table-scroll" role="region" aria-label="<?= $h($card['title']) ?>" tabindex="0">
			<table class="motd-table"><thead><tr>
				<th scope="col"><?= $h(_('Status')) ?></th><th scope="col"><?= $h(_('Maintenance')) ?></th>
				<th scope="col"><?= $h(_('Time')) ?></th><th scope="col"><?= $h(_('Scope')) ?></th>
			</tr></thead><tbody>
			<?php foreach ($maintenance[$card['key']] as $row): ?>
				<tr><td><span class="motd-pill motd-pill--<?= $h($row['status'] ?? 'upcoming') ?>"><?= $h($row['status_label'] ?? '') ?></span></td>
					<td><?= $h($row['name'] ?? '') ?><?php if (!empty($row['description'])): ?><div class="motd-subtext"><?= $h($row['description']) ?></div><?php endif; ?></td>
					<td><?= $h(!empty($row['start_text']) && !empty($row['end_text']) ? $row['start_text'].' – '.$row['end_text'] : ($row['time_text'] ?? '')) ?>
						<?php if (!empty($row['remaining_text'])): ?><div class="motd-subtext"><?= $h($row['remaining_text']) ?></div><?php endif; ?>
					</td><td><?= $h($row['scope_text'] ?? '') ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table></div>
		<?php elseif (($maintenance['status'] ?? '') === 'ok'): ?><div class="motd-empty"><?= $h(_('No maintenance windows in this scope and period.')) ?></div><?php endif; ?>
		<?php if (!empty($maintenance['truncated'])): ?><p class="motd-subtext"><?= $h(_('Only part of the maintenance schedule is shown.')) ?></p><?php endif; ?>
	</section>
<?php endforeach; ?>
</div>

