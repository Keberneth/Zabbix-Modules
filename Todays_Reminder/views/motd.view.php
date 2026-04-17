<?php

declare(strict_types = 0);

$h = static function($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$motd = is_array($data['motd'] ?? null) ? $data['motd'] : [];
$problems = is_array($motd['problems'] ?? null) ? $motd['problems'] : [];
$unacked = is_array($motd['unacked'] ?? null) ? $motd['unacked'] : [];
$longest = is_array($motd['longest'] ?? null) ? $motd['longest'] : [];
$resolved = is_array($motd['resolved'] ?? null) ? $motd['resolved'] : [];
$health = is_array($motd['health'] ?? null) ? $motd['health'] : [];
$new_hosts = is_array($motd['new_hosts'] ?? null) ? $motd['new_hosts'] : [];
$maintenances = is_array($motd['maintenances'] ?? null) ? $motd['maintenances'] : [];
$chips = is_array($motd['chips'] ?? null) ? $motd['chips'] : [];

$renderProblemTable = static function(array $rows, callable $h, array $extra_columns = []): string {
	ob_start();
	?>
	<table class="motd-table">
		<thead>
			<tr>
				<th><?= $h(_('Severity')) ?></th>
				<th><?= $h(_('Problem')) ?></th>
				<th><?= $h(_('Started')) ?></th>
				<th><?= $h(_('Host(s)')) ?></th>
				<?php foreach ($extra_columns as $col): ?>
					<th><?= $h($col['label']) ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $row): ?>
				<tr>
					<td>
						<span class="motd-pill motd-pill--<?= $h($row['severity_key'] ?? 'info') ?>">
							<?= $h($row['severity_label'] ?? '') ?>
						</span>
					</td>
					<td>
						<?php if (($row['url'] ?? '') !== ''): ?>
							<a href="<?= $h($row['url']) ?>"><?= $h($row['name'] ?? '') ?></a>
						<?php else: ?>
							<?= $h($row['name'] ?? '') ?>
						<?php endif; ?>
						<div class="motd-subtext">#<?= $h($row['eventid'] ?? '') ?><?php if (($row['age_text'] ?? '') !== ''): ?> · <?= $h($row['age_text']) ?><?php endif; ?></div>
					</td>
					<td><?= $h($row['clock_text'] ?? '') ?></td>
					<td><?= $h($row['host_text'] ?? '') ?></td>
					<?php foreach ($extra_columns as $col): ?>
						<td><?= $h($row[$col['field']] ?? '') ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	return (string) ob_get_clean();
};

ob_start();
?>
<div class="motd-page">
	<div class="motd-page__header">
		<div>
			<h1><?= $h($motd['title'] ?? _('Today\'s Reminder')) ?></h1>
			<p class="motd-page__meta">
				<?= $h(_('Generated')) ?>: <?= $h($motd['generated_at_text'] ?? '') ?>
				<?php if (($motd['timezone'] ?? '') !== ''): ?>
					· <?= $h($motd['timezone']) ?>
				<?php endif; ?>
			</p>
			<p class="motd-page__summary"><?= $h($motd['summary_line'] ?? '') ?></p>
		</div>
		<div class="motd-page__header-actions">
			<?php if (($problems['all_url'] ?? '') !== ''): ?>
				<a class="btn-alt" href="<?= $h($problems['all_url']) ?>"><?= $h(_('Open Problems')) ?></a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($chips): ?>
		<div class="motd-chip-row motd-chip-row--page">
			<?php foreach ($chips as $chip): ?>
				<?php $url = (string) ($chip['url'] ?? ''); ?>
				<?php $kind = (string) ($chip['kind'] ?? 'info'); ?>
				<?php $label = (string) ($chip['label'] ?? ''); ?>
				<?php $value = (string) ($chip['value'] ?? ''); ?>
				<?php if ($url !== ''): ?>
					<a class="motd-chip motd-chip--<?= $h($kind) ?>" href="<?= $h($url) ?>">
						<span class="motd-chip__label"><?= $h($label) ?></span>
						<span class="motd-chip__value"><?= $h($value) ?></span>
					</a>
				<?php else: ?>
					<span class="motd-chip motd-chip--<?= $h($kind) ?>">
						<span class="motd-chip__label"><?= $h($label) ?></span>
						<span class="motd-chip__value"><?= $h($value) ?></span>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="motd-grid">
		<section class="motd-card">
			<div class="motd-card__header">
				<h2>
					<?= $h(_('Unacknowledged High / Critical')) ?>
					<?php if ((int) ($unacked['count'] ?? 0) > 0): ?>
						<span class="motd-count motd-count--warn"><?= $h((string) (int) $unacked['count']) ?></span>
					<?php endif; ?>
				</h2>
				<?php if (($unacked['url'] ?? '') !== ''): ?>
					<a class="motd-card__link" href="<?= $h($unacked['url']) ?>"><?= $h(_('Open view')) ?></a>
				<?php endif; ?>
			</div>

			<?php if (!empty($unacked['recent'])): ?>
				<?= $renderProblemTable($unacked['recent'], $h) ?>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('Every High/Critical problem has been acknowledged.')) ?></div>
			<?php endif; ?>
		</section>

		<section class="motd-card">
			<div class="motd-card__header">
				<h2><?= $h(_('Longest-running open problems')) ?></h2>
				<?php $incidents_url = (string) ($problems['high_critical_url'] ?? $problems['all_url'] ?? ''); ?>
				<?php if ($incidents_url !== ''): ?>
					<a class="motd-card__link" href="<?= $h($incidents_url) ?>"><?= $h(_('Open view')) ?></a>
				<?php endif; ?>
			</div>

			<?php if (!empty($longest['recent'])): ?>
				<?= $renderProblemTable($longest['recent'], $h) ?>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('No unresolved High or Critical incidents.')) ?></div>
			<?php endif; ?>
		</section>
	</div>

	<div class="motd-grid">
		<section class="motd-card">
			<div class="motd-card__header">
				<h2>
					<?= $h(_('Recently resolved (24h)')) ?>
					<?php if ((int) ($resolved['count'] ?? 0) > 0): ?>
						<span class="motd-count motd-count--ok"><?= $h((string) (int) $resolved['count']) ?></span>
					<?php endif; ?>
				</h2>
				<?php if (($resolved['avg_mttr_text'] ?? '') !== ''): ?>
					<span class="motd-subtext"><?= $h(_('Avg MTTR')) ?>: <?= $h($resolved['avg_mttr_text']) ?></span>
				<?php endif; ?>
			</div>

			<?php if (!empty($resolved['recent'])): ?>
				<?= $renderProblemTable($resolved['recent'], $h, [
					['label' => _('Resolved'), 'field' => 'resolved_age_text'],
					['label' => _('MTTR'), 'field' => 'mttr_text']
				]) ?>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('No High/Critical problems have resolved in the last 24 hours.')) ?></div>
			<?php endif; ?>
		</section>

		<section class="motd-card">
			<div class="motd-card__header">
				<h2><?= $h(_('Monitoring health')) ?></h2>
			</div>

			<?php
				$health_rows = [];
				$stale_total = (int) ($health['stale_items_total'] ?? 0);
				$stale_hosts = (int) ($health['stale_items_hosts'] ?? 0);
				$health_rows[] = [
					'label' => _('Items in "not supported"'),
					'value' => $stale_total > 0
						? sprintf(_('%1$d items across %2$d hosts'), $stale_total, $stale_hosts)
						: _('0'),
					'kind' => $stale_total > 0 ? 'warn' : 'ok',
					'url' => $stale_total > 0 ? (string) ($health['stale_url'] ?? '') : ''
				];
				$unreach = (int) ($health['unreachable_hosts'] ?? 0);
				$health_rows[] = [
					'label' => _('Unreachable hosts'),
					'value' => (string) $unreach,
					'kind' => $unreach > 0 ? 'warn' : 'ok',
					'url' => $unreach > 0 ? (string) ($health['unreachable_hosts_url'] ?? '') : ''
				];
				$unreach_p = (int) ($health['unreachable_proxies'] ?? 0);
				$health_rows[] = [
					'label' => _('Unreachable proxies'),
					'value' => (string) $unreach_p,
					'kind' => $unreach_p > 0 ? 'warn' : 'ok',
					'url' => $unreach_p > 0 ? (string) ($health['proxy_url'] ?? '') : ''
				];
				$queue = $health['queue_backlog'];
				$queue_val = $queue === null ? _('unavailable') : (string) (int) $queue;
				$queue_kind = $queue === null ? 'muted' : ((int) $queue > 0 ? 'warn' : 'ok');
				$health_rows[] = [
					'label' => _('Server queue > 10m'),
					'value' => $queue_val,
					'kind' => $queue_kind,
					'url' => (string) ($health['queue_url'] ?? '')
				];
				$new_hosts_count = (int) ($new_hosts['count'] ?? 0);
				$new_hosts_extra = '';
				if (!empty($new_hosts['recent'])) {
					$names = array_map(static function($n) { return (string) ($n['name'] ?? ''); }, $new_hosts['recent']);
					$names = array_values(array_filter($names, static function($v) { return $v !== ''; }));
					if ($names) {
						$new_hosts_extra = implode(', ', array_slice($names, 0, 3));
						if ($new_hosts_count > 3) {
							$new_hosts_extra .= sprintf(_(' +%1$d more'), $new_hosts_count - 3);
						}
					}
				}
				$health_rows[] = [
					'label' => _('New hosts (24h)'),
					'value' => (string) $new_hosts_count,
					'detail' => $new_hosts_extra,
					'kind' => $new_hosts_count > 0 ? 'info' : 'ok',
					'url' => $new_hosts_count > 0 ? (string) ($new_hosts['url'] ?? '') : ''
				];
			?>

			<ul class="motd-health">
				<?php foreach ($health_rows as $row): ?>
					<li class="motd-health__row motd-health__row--<?= $h($row['kind']) ?>">
						<span class="motd-health__label"><?= $h($row['label']) ?></span>
						<span class="motd-health__value">
							<?php if (($row['url'] ?? '') !== ''): ?>
								<a href="<?= $h($row['url']) ?>"><?= $h($row['value']) ?></a>
							<?php else: ?>
								<?= $h($row['value']) ?>
							<?php endif; ?>
							<?php if (($row['detail'] ?? '') !== ''): ?>
								<span class="motd-subtext"> · <?= $h($row['detail']) ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>

	<div class="motd-grid">
		<section class="motd-card">
			<div class="motd-card__header">
				<h2><?= $h(_('Today’s maintenance windows')) ?></h2>
			</div>

			<?php if (!empty($maintenances['today'])): ?>
				<table class="motd-table">
					<thead>
						<tr>
							<th><?= $h(_('Status')) ?></th>
							<th><?= $h(_('Maintenance')) ?></th>
							<th><?= $h(_('Time')) ?></th>
							<th><?= $h(_('Scope')) ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($maintenances['today'] as $occurrence): ?>
							<tr>
								<td><span class="motd-pill motd-pill--<?= $h($occurrence['status'] ?? 'upcoming') ?>"><?= $h($occurrence['status_label'] ?? '') ?></span></td>
								<td>
									<?= $h($occurrence['name'] ?? '') ?>
									<?php if (($occurrence['description'] ?? '') !== ''): ?>
										<div class="motd-subtext"><?= $h($occurrence['description']) ?></div>
									<?php endif; ?>
								</td>
								<td><?= $h($occurrence['time_text'] ?? '') ?></td>
								<td><?= $h($occurrence['scope_text'] ?? '') ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('No maintenance windows start today.')) ?></div>
			<?php endif; ?>
		</section>

		<section class="motd-card">
			<div class="motd-card__header">
				<h2><?= $h(_('Upcoming later this week')) ?></h2>
			</div>

			<?php if (!empty($maintenances['week'])): ?>
				<table class="motd-table">
					<thead>
						<tr>
							<th><?= $h(_('Day')) ?></th>
							<th><?= $h(_('Maintenance')) ?></th>
							<th><?= $h(_('Time')) ?></th>
							<th><?= $h(_('Scope')) ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($maintenances['week'] as $occurrence): ?>
							<tr>
								<td><?= $h($occurrence['day_text'] ?? '') ?></td>
								<td>
									<?= $h($occurrence['name'] ?? '') ?>
									<?php if (($occurrence['description'] ?? '') !== ''): ?>
										<div class="motd-subtext"><?= $h($occurrence['description']) ?></div>
									<?php endif; ?>
								</td>
								<td><?= $h($occurrence['time_text'] ?? '') ?></td>
								<td><?= $h($occurrence['scope_text'] ?? '') ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('No additional maintenance windows are scheduled later this week.')) ?></div>
			<?php endif; ?>
		</section>
	</div>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
	->setTitle($data['title'] ?? _('Today\'s Reminder'))
	->addItem(new class($content) {
		private $html;
		public function __construct($html) { $this->html = $html; }
		public function toString($destroy = true) { return $this->html; }
	})
	->show();
