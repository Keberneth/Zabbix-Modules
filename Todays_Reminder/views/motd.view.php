<?php

declare(strict_types = 0);

$h = static function($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$motd = is_array($data['motd'] ?? null) ? $data['motd'] : [];
$problems = is_array($motd['problems'] ?? null) ? $motd['problems'] : [];
$maintenances = is_array($motd['maintenances'] ?? null) ? $motd['maintenances'] : [];
$software_update = is_array($motd['software_update'] ?? null) ? $motd['software_update'] : [];
$chips = is_array($motd['chips'] ?? null) ? $motd['chips'] : [];

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
				<h2><?= $h(_('Open High / Critical incidents')) ?></h2>
				<?php if (($problems['all_url'] ?? '') !== ''): ?>
					<a class="motd-card__link" href="<?= $h($problems['all_url']) ?>"><?= $h(_('Open view')) ?></a>
				<?php endif; ?>
			</div>

			<?php if (!empty($problems['recent'])): ?>
				<table class="motd-table">
					<thead>
						<tr>
							<th><?= $h(_('Severity')) ?></th>
							<th><?= $h(_('Problem')) ?></th>
							<th><?= $h(_('Started')) ?></th>
							<th><?= $h(_('Host(s)')) ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($problems['recent'] as $problem): ?>
							<tr>
								<td>
									<span class="motd-pill motd-pill--<?= $h($problem['severity_key'] ?? 'info') ?>">
										<?= $h($problem['severity_label'] ?? '') ?>
									</span>
								</td>
								<td>
									<?php if (($problem['url'] ?? '') !== ''): ?>
										<a href="<?= $h($problem['url']) ?>"><?= $h($problem['name'] ?? '') ?></a>
									<?php else: ?>
										<?= $h($problem['name'] ?? '') ?>
									<?php endif; ?>
									<div class="motd-subtext">#<?= $h($problem['eventid'] ?? '') ?><?php if (($problem['age_text'] ?? '') !== ''): ?> · <?= $h($problem['age_text']) ?><?php endif; ?></div>
								</td>
								<td><?= $h($problem['clock_text'] ?? '') ?></td>
								<td><?= $h($problem['host_text'] ?? '') ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="motd-empty"><?= $h(_('No unresolved High or Critical incidents are currently visible to this user.')) ?></div>
			<?php endif; ?>
		</section>

		<section class="motd-card">
			<div class="motd-card__header">
				<h2><?= $h(_('Software update')) ?></h2>
			</div>
			<div class="motd-update">
				<p><?= $h($software_update['message'] ?? '') ?></p>
				<?php if (($software_update['support_message'] ?? '') !== ''): ?>
					<p class="motd-subtext"><?= $h($software_update['support_message']) ?></p>
				<?php endif; ?>
				<?php if (($software_update['checked_at_text'] ?? '') !== ''): ?>
					<p class="motd-subtext"><?= $h(_('Last check')) ?>: <?= $h($software_update['checked_at_text']) ?></p>
				<?php endif; ?>
				<?php if (($software_update['release_notes_url'] ?? '') !== ''): ?>
					<p><a href="<?= $h($software_update['release_notes_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h(_('Open release notes')) ?></a></p>
				<?php endif; ?>
			</div>
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
