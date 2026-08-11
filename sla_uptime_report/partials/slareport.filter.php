<?php declare(strict_types = 1);

/**
 * Report filter.
 *
 * Plain GET form, so it works without JavaScript. Host groups and SLAs are
 * selectable pills; a small text box filters the group pills client-side when
 * the list is long.
 *
 * @var array $report
 * @var array $filter
 * @var callable $esc
 */

$period_modes = [
	'days_back' => _('Last N days'),
	'prev_month' => _('Previous month'),
	'specific_month' => _('Specific month'),
	'custom_range' => _('Custom range')
];
?>
<form class="sr-filter" id="sr-filter-form" method="get" action="zabbix.php" data-sr-filter>
	<input type="hidden" name="action" value="slareport.report.view">
	<?php /* Only the clicked submitter is sent, so without this hidden field a
	         no-JS Apply would lose the current tab. The tab buttons are named
	         submitters and override it; the script keeps it in sync. */ ?>
	<input type="hidden" name="filter_tab" value="<?= $esc($filter['tab']) ?>" data-sr-tab-input>

	<div class="sr-filter-row">
		<div class="sr-field">
			<label for="sr_mode"><?= $esc(_('Period')) ?></label>
			<select id="sr_mode" name="filter_mode">
				<?php foreach ($period_modes as $value => $label): ?>
					<option value="<?= $esc($value) ?>"<?= $filter['mode'] === $value ? ' selected' : '' ?>>
						<?= $esc($label) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="sr-field" data-sr-modes="days_back">
			<label for="sr_days_back"><?= $esc(_('Days')) ?></label>
			<input id="sr_days_back" type="number" min="1" max="366" name="filter_days_back"
				   value="<?= $esc($filter['days_back']) ?>">
		</div>

		<div class="sr-field" data-sr-modes="specific_month">
			<label for="sr_month"><?= $esc(_('Month')) ?></label>
			<input id="sr_month" type="month" name="filter_month" value="<?= $esc($filter['month']) ?>">
		</div>

		<div class="sr-field" data-sr-modes="custom_range">
			<label for="sr_date_from"><?= $esc(_('From')) ?></label>
			<input id="sr_date_from" type="date" name="filter_date_from" value="<?= $esc($filter['date_from']) ?>">
		</div>

		<div class="sr-field" data-sr-modes="custom_range">
			<label for="sr_date_to"><?= $esc(_('To')) ?></label>
			<input id="sr_date_to" type="date" name="filter_date_to" value="<?= $esc($filter['date_to']) ?>">
		</div>

		<div class="sr-field sr-field--grow">
			<span class="sr-field-label"><?= $esc(_('Host groups')) ?></span>
			<?php if (($report['group_options'] ?? []) === []): ?>
				<p class="sr-hint"><?= $esc(_('No host groups with hosts were found.')) ?></p>
			<?php else: ?>
				<?php if (count($report['group_options']) > 12): ?>
					<input type="text" class="sr-chip-search" data-sr-chip-search="sr-group-chips"
						   placeholder="<?= $esc(_('Type to filter groups…')) ?>"
						   aria-label="<?= $esc(_('Filter the host group list')) ?>" autocomplete="off">
				<?php endif; ?>
				<div class="sr-chips" id="sr-group-chips">
					<?php foreach ($report['group_options'] as $group): ?>
						<?php $on = in_array((string) $group['groupid'], (array) $filter['hostgroupids'], true); ?>
						<label class="sr-chip<?= $on ? ' is-on' : '' ?>">
							<input type="checkbox" name="filter_hostgroupids[]"
								   value="<?= $esc($group['groupid']) ?>"<?= $on ? ' checked' : '' ?>>
							<?= $esc($group['name']) ?>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="sr-hint"><?= $esc(_('Select none to include every host group you can see.')) ?></div>
			<?php endif; ?>
		</div>
	</div>

	<?php if (($report['sla_options'] ?? []) !== []): ?>
		<div class="sr-filter-row">
			<div class="sr-field sr-field--full">
				<span class="sr-field-label"><?= $esc(_('SLAs')) ?></span>
				<div class="sr-chips">
					<?php foreach ($report['sla_options'] as $sla): ?>
						<?php $on = in_array((string) $sla['slaid'], (array) $filter['slaids'], true); ?>
						<label class="sr-chip<?= $on ? ' is-on' : '' ?>">
							<input type="checkbox" name="filter_slaids[]"
								   value="<?= $esc($sla['slaid']) ?>"<?= $on ? ' checked' : '' ?>>
							<?= $esc($sla['name']) ?>
							<?php if ($sla['slo'] !== null): ?>
								<span class="sr-chip-count"><?= $esc(number_format((float) $sla['slo'],
									(float) $sla['slo'] == floor((float) $sla['slo']) ? 0 : 2).'%') ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="sr-hint"><?= $esc(_('Select none to include every enabled SLA.')) ?></div>
			</div>
		</div>
	<?php endif; ?>

	<?php
		$defaults = \Modules\SlaUptimeReport\Helpers\ReportDataHelper::getDefaultFilter();
		$advanced_open = (float) $filter['target'] !== (float) $defaults['target']
			|| (int) $filter['top'] !== (int) $defaults['top']
			|| (int) $filter['exclude_disabled'] !== (int) $defaults['exclude_disabled'];
	?>
	<div class="sr-filter-row sr-advanced" data-sr-advanced="<?= $advanced_open ? 'open' : 'closed' ?>">
		<div class="sr-field">
			<label for="sr_target"><?= $esc(_('Availability target (%)')) ?></label>
			<input id="sr_target" type="number" min="50" max="99.999" step="0.001" name="filter_target"
				   value="<?= $esc($filter['target']) ?>">
			<div class="sr-hint"><?= $esc(_('Hosts below this are flagged; below 90% is critical.')) ?></div>
		</div>

		<div class="sr-field">
			<label for="sr_top"><?= $esc(_('Rows per group')) ?></label>
			<input id="sr_top" type="number" min="10" max="500" name="filter_top" value="<?= $esc($filter['top']) ?>">
		</div>

		<div class="sr-field">
			<span class="sr-field-label"><?= $esc(_('Disabled hosts')) ?></span>
			<?php /* The hidden 0 makes "unchecked" actually submit a value;
			         a checked box overrides it because the later field wins. */ ?>
			<input type="hidden" name="filter_exclude_disabled" value="0">
			<label class="sr-chip<?= (int) $filter['exclude_disabled'] === 1 ? ' is-on' : '' ?>">
				<input type="checkbox" name="filter_exclude_disabled" value="1"
					<?= (int) $filter['exclude_disabled'] === 1 ? ' checked' : '' ?>>
				<?= $esc(_('Exclude disabled hosts')) ?>
			</label>
		</div>
	</div>

	<div class="sr-filter-actions">
		<button type="submit" class="sr-btn sr-btn--primary"><?= $esc(_('Apply')) ?></button>
		<a class="sr-btn" href="zabbix.php?action=slareport.report.view"><?= $esc(_('Reset')) ?></a>
		<span class="sr-spacer"></span>
		<button type="button" class="sr-btn sr-btn--ghost" data-sr-advanced-toggle
				aria-expanded="<?= $advanced_open ? 'true' : 'false' ?>"
				data-label-more="<?= $esc(_('More filters')) ?>" data-label-less="<?= $esc(_('Fewer filters')) ?>">
			<?= $esc($advanced_open ? _('Fewer filters') : _('More filters')) ?>
		</button>
	</div>
</form>
