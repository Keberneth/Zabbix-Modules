<?php declare(strict_types = 1);

/** @var array $data */
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$settings = $data['settings'];
$can_edit = $data['can_edit'];
$render_rule = static function(array $rule, string $prefix) use ($esc): void {
	$modes = ['auto' => _('Automatic item (ping fallback)'), 'binary' => _('Value 1 is healthy'),
		'equals' => _('Equals healthy value'), 'range' => _('Within healthy range')];
	?>
	<td><input type="text" name="<?= $esc($prefix) ?>[label]" value="<?= $esc($rule['label'] ?? '') ?>"
		maxlength="255" aria-label="<?= $esc(_('Measurement name')) ?>" placeholder="<?= $esc(_('Optional display name')) ?>" class="sr-settings-label"></td>
	<td><input type="text" name="<?= $esc($prefix) ?>[key]" value="<?= $esc($rule['key']) ?>"
		maxlength="2048" aria-label="<?= $esc(_('Exact item key')) ?>" placeholder="agent.ping" class="sr-settings-key"></td>
	<td><select name="<?= $esc($prefix) ?>[mode]" aria-label="<?= $esc(_('Healthy-value rule')) ?>">
		<?php foreach ($modes as $value => $label): ?>
			<option value="<?= $esc($value) ?>"<?= $rule['mode'] === $value ? ' selected' : '' ?>><?= $esc($label) ?></option>
		<?php endforeach; ?>
	</select></td>
	<td><input type="number" step="any" name="<?= $esc($prefix) ?>[value]" value="<?= $esc($rule['value']) ?>" aria-label="<?= $esc(_('Healthy value for equals')) ?>"></td>
	<td><input type="number" step="any" name="<?= $esc($prefix) ?>[min]" value="<?= $esc($rule['min']) ?>" aria-label="<?= $esc(_('Minimum healthy value')) ?>"></td>
	<td><input type="number" step="any" name="<?= $esc($prefix) ?>[max]" value="<?= $esc($rule['max']) ?>" aria-label="<?= $esc(_('Maximum healthy value')) ?>"></td>
	<td><input type="number" min="0" max="86400" name="<?= $esc($prefix) ?>[interval_seconds]" value="<?= $esc($rule['interval_seconds']) ?>" aria-label="<?= $esc(_('Expected poll interval in seconds; zero uses item delay or inference')) ?>"></td>
	<?php
};
$default_rule = \Modules\SlaUptimeReport\Helpers\ReportSettings::defaults()['global'];
ob_start();
?>
<div class="slareport sr-settings" data-sr-settings>
	<div class="sr-head"><div><p class="sr-head-title"><?= $esc($data['title']) ?></p>
		<p class="sr-hint"><?= $esc(_('Measurement rules and exclusions apply to host availability in reports and exports. Zabbix SLA calculations are unchanged.')) ?></p>
	</div><a class="sr-btn" href="zabbix.php?action=slareport.report.view"><?= $esc(_('Back to report')) ?></a></div>
	<?php if ($data['error'] !== ''): ?><div class="sr-alert" role="alert" id="sr-settings-error"><?= $esc($data['error']) ?></div><?php endif; ?>
	<?php if ($data['saved']): ?><div class="sr-panel" role="status"><?= $esc(_('Report settings saved.')) ?></div><?php endif; ?>
	<div class="sr-panel">
		<p><?= $esc(_('Host measurements take precedence over group rules, then the global rule. Add several measurements for the same host with distinct item keys to report each website or web scenario separately. Each measurement can have its own name and healthy response code. For hosts in several configured groups, the lowest priority number wins; ties use the lowest group ID. Group rules and exclusions apply to direct membership.')) ?></p>
		<p class="sr-hint"><?= $esc(_('Only enabled numeric items can measure availability. Automatic selects agent.ping, icmpping, then zabbix[host,agent,available]. An explicit key that is absent or disabled reports No item; it does not fall back to another item.')) ?></p>
		<p class="sr-hint"><?= $esc(_('For a website use its exact web.test.rspcode[scenario,step] item key. Set Equals 200 for a page expected to return 200, or Equals 302 when a login or overview page is expected to redirect. Use a range only when every code in it is acceptable. Custom comparisons need raw history for reliable results; hourly averages cannot reconstruct response codes.')) ?></p>
		<p class="sr-hint"><?= $esc(_('Use explicit item keys for every measurement when a host has several measurements. Automatic ping selection cannot be combined with additional website measurements on that host. Blank measurement names use the item key, or the host name for automatic selection.')) ?></p>
		<p class="sr-hint"><?= $esc(_('Expected poll interval: 0 uses the item delay or estimates it from samples. Set the actual interval in seconds for web scenario or dependent items whose delay is 0. An explicit interval makes missing-data boundaries eligible for individual maintenance creation; use the real collection schedule.')) ?></p>
		<?php if (!$can_edit): ?><p class="sr-hint"><?= $esc(_('Administrators can view settings for their accessible hosts and groups. Only Super administrators can change this shared configuration, as required by Zabbix module permissions.')) ?></p><?php endif; ?>
	</div>
	<form method="post" action="zabbix.php?action=slareport.settings.save">
		<?php if ($can_edit): ?>
			<input type="hidden" name="<?= $esc(CSRF_TOKEN_NAME) ?>" value="<?= $esc(CCsrfTokenHelper::get('slareport.settings.save')) ?>">
			<input type="hidden" name="revision" value="<?= $esc($data['revision']) ?>">
		<?php endif; ?>
		<fieldset<?= $can_edit ? '' : ' disabled' ?> class="sr-settings-fieldset">
			<div class="sr-panel"><h2 class="sr-panel-title"><?= $esc(_('Report cache')) ?></h2>
				<input type="hidden" name="settings[cache][enabled]" value="0">
				<label><input type="checkbox" name="settings[cache][enabled]" value="1"<?= !empty($settings['cache']['enabled']) ? ' checked' : '' ?>>
					<?= $esc(_('Reuse previously loaded measurement data')) ?></label>
				<p class="sr-hint"><?= $esc(_('The cache stores numeric readings on this frontend. Reports fetch missing intervals and refresh recent data; permissions, measurement rules and maintenance are checked each time. Use Refresh data on the report to reread the selected period. If private cache storage is unavailable, the report reads live data.')) ?></p>
			</div>
			<div class="sr-panel"><h2 class="sr-panel-title"><?= $esc(_('Global measurement')) ?></h2>
				<div class="sr-table-wrap"><table class="sr-table"><thead><tr>
					<th scope="col"><?= $esc(_('Measurement name')) ?></th><th scope="col"><?= $esc(_('Exact item key')) ?></th><th scope="col"><?= $esc(_('Healthy-value rule')) ?></th><th scope="col"><?= $esc(_('Equals')) ?></th><th scope="col"><?= $esc(_('Minimum')) ?></th><th scope="col"><?= $esc(_('Maximum')) ?></th><th scope="col"><?= $esc(_('Poll seconds')) ?></th>
				</tr></thead><tbody><tr><?php $render_rule($settings['global'], 'settings[global]'); ?></tr></tbody></table></div>
			</div>
			<?php foreach (['groups' => ['groupid', _('Host group overrides')], 'hosts' => ['hostid', _('Host and website measurements')]] as $kind => [$id_field, $title]): ?>
				<div class="sr-panel"><h2 class="sr-panel-title"><?= $esc($title) ?></h2>
					<?php if ($kind === 'hosts' && $can_edit): ?>
						<details class="sr-settings-import"><summary><?= $esc(_('Add website measurements from a table')) ?></summary>
							<p class="sr-hint"><?= $esc(_('Paste tab-separated rows: Host name, Measurement label, Exact item key, Healthy value, Poll seconds. Host names must match the available hosts exactly; a listed host ID also works. Leave Poll seconds blank to use the item delay or infer it.')) ?></p>
							<p class="sr-hint"><?= $esc(_('Import adds or updates matching host/item pairs using an exact healthy-value comparison. It replaces automatic ping overrides for imported hosts and preserves their other explicit measurements. Review all changes, then save the shared settings.')) ?></p>
							<label for="sr-checks-tsv"><?= $esc(_('Website measurement rows (tab-separated)')) ?></label>
							<textarea id="sr-checks-tsv" rows="7" data-sr-checks-tsv spellcheck="false"></textarea>
							<button type="button" class="sr-btn" data-sr-import-checks><?= $esc(_('Import measurements')) ?></button>
							<p class="sr-hint" role="status" aria-live="polite" data-sr-import-status></p>
						</details>
					<?php endif; ?>
					<div class="sr-table-wrap"><table class="sr-table"><thead><tr>
						<th scope="col"><?= $esc($kind === 'groups' ? _('Host group') : _('Host')) ?></th>
						<?php if ($kind === 'groups'): ?><th scope="col"><?= $esc(_('Priority')) ?></th><?php endif; ?>
						<th scope="col"><?= $esc(_('Measurement name')) ?></th><th scope="col"><?= $esc(_('Exact item key')) ?></th><th scope="col"><?= $esc(_('Healthy-value rule')) ?></th><th scope="col"><?= $esc(_('Equals')) ?></th><th scope="col"><?= $esc(_('Minimum')) ?></th><th scope="col"><?= $esc(_('Maximum')) ?></th><th scope="col"><?= $esc(_('Poll seconds')) ?></th><th scope="col"><?= $esc(_('Remove')) ?></th>
					</tr></thead><tbody data-sr-rule-rows="<?= $esc($kind) ?>">
					<?php $object_names = array_column($data[$kind], 'name', $id_field);
					$rows = $settings[$kind];
					if ($can_edit && (empty($data['unsaved_settings']) || $rows === [])) { $rows[] = $default_rule + [$id_field => '', 'priority' => 100]; }
					foreach ($rows as $index => $rule): $prefix = 'settings['.$kind.']['.$index.']'; ?>
						<tr data-sr-rule-row>
							<td><input type="text" inputmode="numeric" pattern="[1-9][0-9]*" name="<?= $esc($prefix) ?>[<?= $esc($id_field) ?>]" value="<?= $esc($rule[$id_field]) ?>"
								list="sr-settings-<?= $esc($kind) ?>" aria-label="<?= $esc($kind === 'groups' ? _('Host group ID') : _('Host ID')) ?>" placeholder="<?= $esc(_('Select or enter ID')) ?>">
								<span class="sr-hint" data-sr-object-name><?= $esc($object_names[$rule[$id_field]] ?? ($rule[$id_field] === '' ? '' : _('Unavailable object'))) ?></span></td>
							<?php if ($kind === 'groups'): ?><td><input type="number" min="0" max="99999" name="<?= $esc($prefix) ?>[priority]" value="<?= $esc($rule['priority']) ?>" aria-label="<?= $esc(_('Group priority')) ?>"></td><?php endif; ?>
							<?php $render_rule($rule, $prefix); ?>
							<td><button type="button" class="sr-btn" data-sr-remove-rule><?= $esc(_('Remove')) ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table></div>
					<?php if ($can_edit): ?><button type="button" class="sr-btn" data-sr-add-rule="<?= $esc($kind) ?>"><?= $esc($kind === 'hosts' ? _('Add measurement') : _('Add override')) ?></button>
						<p class="sr-hint"><?= $esc(_('Add all required rows, then save them together. Blank host/group IDs are ignored. Without JavaScript, save and reopen to add another row.')) ?></p><?php endif; ?>
				</div>
			<?php endforeach; ?>
			<div class="sr-panel"><h2 class="sr-panel-title"><?= $esc(_('Exclude from host availability')) ?></h2>
				<p class="sr-hint"><?= $esc(_('Excluded hosts leave the availability denominator, charts, host counts and exports. Excluding a group excludes every directly assigned host, including hosts also belonging to another reported group. Hold Ctrl (or Command) to select several entries.')) ?></p>
				<div class="sr-settings-exclusions">
				<?php foreach (['groups' => ['groupid', 'excluded_groupids', _('Excluded host groups')], 'hosts' => ['hostid', 'excluded_hostids', _('Excluded hosts')]] as $kind => [$id_field, $field, $label]): ?>
					<label><?= $esc($label) ?><select multiple size="9" name="settings[<?= $esc($field) ?>][]">
					<?php foreach ($data[$kind] as $object): ?><option value="<?= $esc($object[$id_field]) ?>"<?= in_array((string) $object[$id_field], $settings[$field], true) ? ' selected' : '' ?>><?= $esc($object['name'].' (#'.$object[$id_field].')') ?></option><?php endforeach; ?>
					</select></label>
				<?php endforeach; ?>
				</div>
				<?php if ($data['hosts_capped'] || ($settings['excluded_hostids_extra'] ?? '') !== ''): ?><p class="sr-hint"><?= $esc(_('Host suggestions show the first 2000 accessible hosts plus currently configured hosts. Enter host IDs below to exclude additional hosts.')) ?></p>
					<label><?= $esc(_('Additional excluded host IDs (separated by commas or spaces)')) ?>
						<input type="text" name="settings[excluded_hostids_extra]" value="<?= $esc($settings['excluded_hostids_extra'] ?? '') ?>" aria-label="<?= $esc(_('Additional excluded host IDs')) ?>">
					</label><?php endif; ?>
			</div>
			<?php if (!empty($data['unsaved_settings'])): ?><p class="sr-alert" role="status"><?= $esc(_('Your submitted rows are retained in this form. Saving has not been confirmed.')) ?>
				<a href="#sr-settings-error"><?= $esc(_('Review the save error above')) ?></a></p><?php endif; ?>
			<?php if ($can_edit): ?><input type="hidden" name="form_complete" value="1"><button class="sr-btn sr-btn--primary" type="submit"><?= $esc(_('Save shared settings')) ?></button><?php endif; ?>
		</fieldset>
	</form>
	<?php foreach (['groups' => 'groupid', 'hosts' => 'hostid'] as $kind => $id_field): ?>
		<datalist id="sr-settings-<?= $esc($kind) ?>"><?php foreach ($data[$kind] as $object): ?><option value="<?= $esc($object[$id_field]) ?>"><?= $esc($object['name']) ?></option><?php endforeach; ?></datalist>
	<?php endforeach; ?>
</div>
<?php
(new CHtmlPage())->setTitle($data['title'])->addItem(new CObject((string) ob_get_clean()))->show();
