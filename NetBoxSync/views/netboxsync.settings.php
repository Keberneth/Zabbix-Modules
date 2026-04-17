<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$config = $data['config'] ?? [];
$catalog = is_array($data['standard_sync_catalog'] ?? null) ? $data['standard_sync_catalog'] : [];
$last_summary = is_array($data['last_summary'] ?? null) ? $data['last_summary'] : [];

$settings_save_url = (string) ($data['settings_save_url'] ?? (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.settings.save')->getUrl());
$run_url = (string) ($data['run_url'] ?? (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.run')->getUrl());
$runner_url = (string) ($data['runner_url'] ?? $run_url);
$linux_plugin_url = (string) ($data['linux_plugin_url'] ?? '');
$windows_plugin_url = (string) ($data['windows_plugin_url'] ?? '');
$modules_repo_url = (string) ($data['modules_repo_url'] ?? '');
$log_url = (string) ($data['log_url'] ?? (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.log')->getUrl());

$nbs_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $nbs_theme = 'dark';
    }
}

$standard_syncs_by_id = [];
foreach (($config['standard_syncs'] ?? []) as $row) {
    if (is_array($row) && isset($row['id'])) {
        $standard_syncs_by_id[(string) $row['id']] = $row;
    }
}

$custom_mappings = is_array($config['custom_mappings'] ?? null) ? $config['custom_mappings'] : [];

$render_mapping_row = static function(array $mapping = []) use ($h): string {
    ob_start();

    $id = $mapping['id'] ?? '__ROW_ID__';
    $target_object = $mapping['target_object'] ?? 'vm';
    $mode = $mapping['mode'] ?? 'field_patch';
    $source_mode = $mapping['source_mode'] ?? 'item_key';
    $transform = $mapping['transform'] ?? 'none';
    $ensure_missing_mode = $mapping['ensure_missing_mode'] ?? 'none';
    $manufacturer_mode = $mapping['relation_manufacturer_source_mode'] ?? 'static';
    $on_empty = $mapping['on_empty'] ?? 'skip';
    ?>
    <div class="nbs-repeat-row" data-row-type="mapping">
        <input type="hidden" class="nbs-row-id-field" name="custom_mappings[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="nbs-repeat-grid nbs-settings-grid">
            <div>
                <label class="nbs-label"><?= $h(_('Enabled')) ?></label>
                <label class="nbs-checkbox">
                    <input type="checkbox" name="custom_mappings[<?= $h($id) ?>][enabled]" value="1" <?= !empty($mapping['enabled']) ? 'checked' : '' ?>>
                    <?= $h(_('Run this mapping')) ?>
                </label>
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('Name')) ?></label>
                <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][name]" value="<?= $h($mapping['name'] ?? '') ?>" placeholder="<?= $h(_('Firmware → device custom field')) ?>">
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('Target object')) ?></label>
                <select class="nbs-input nbs-target-object-select" name="custom_mappings[<?= $h($id) ?>][target_object]">
                    <?php foreach (['vm' => 'VM', 'device' => 'Device', 'custom_url' => 'Custom URL'] as $value => $label): ?>
                        <option value="<?= $h($value) ?>" <?= $target_object === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('Mode')) ?></label>
                <select class="nbs-input nbs-mode-select" name="custom_mappings[<?= $h($id) ?>][mode]">
                    <?php foreach (['field_patch' => 'Patch field', 'relation_lookup' => 'Lookup relation', 'ensure_device_type' => 'Ensure device type'] as $value => $label): ?>
                        <option value="<?= $h($value) ?>" <?= $mode === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('Source mode')) ?></label>
                <select class="nbs-input" name="custom_mappings[<?= $h($id) ?>][source_mode]">
                    <?php foreach (['item_key' => 'Item key', 'item_name' => 'Item name', 'static' => 'Static', 'host_name' => 'Host name', 'agent_ip' => 'Agent IP'] as $value => $label): ?>
                        <option value="<?= $h($value) ?>" <?= $source_mode === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="nbs-span-2">
                <label class="nbs-label"><?= $h(_('Source value')) ?></label>
                <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][source_value]" value="<?= $h($mapping['source_value'] ?? '') ?>" placeholder="<?= $h(_('fgate.device.model or static text')) ?>">
            </div>
            <div class="nbs-span-2">
                <label class="nbs-label"><?= $h(_('Target field')) ?></label>
                <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][target_field]" value="<?= $h($mapping['target_field'] ?? '') ?>" placeholder="<?= $h(_('custom_fields.firmware, serial, device_type')) ?>">
            </div>
            <div class="nbs-span-2">
                <label class="nbs-label"><?= $h(_('Target URL template')) ?></label>
                <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][target_url_template]" value="<?= $h($mapping['target_url_template'] ?? '') ?>" placeholder="<?= $h(_('Only for Custom URL targets, e.g. /dcim/devices/{device_id}/')) ?>">
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('Interval override (seconds)')) ?></label>
                <input class="nbs-input" type="number" min="0" max="31536000" name="custom_mappings[<?= $h($id) ?>][interval_seconds]" value="<?= $h((int) ($mapping['interval_seconds'] ?? 0)) ?>">
            </div>
            <div>
                <label class="nbs-label"><?= $h(_('On empty value')) ?></label>
                <select class="nbs-input" name="custom_mappings[<?= $h($id) ?>][on_empty]">
                    <?php foreach (['skip' => 'Skip', 'clear' => 'Clear target field'] as $value => $label): ?>
                        <option value="<?= $h($value) ?>" <?= $on_empty === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <details class="nbs-advanced">
            <summary><?= $h(_('Advanced options')) ?></summary>
            <div class="nbs-repeat-grid nbs-settings-grid nbs-advanced-grid">
                <div>
                    <label class="nbs-label"><?= $h(_('Host name regex')) ?></label>
                    <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][host_name_regex]" value="<?= $h($mapping['host_name_regex'] ?? '') ?>" placeholder="<?= $h(_('/^FW-/')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Source JSON path')) ?></label>
                    <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][source_json_path]" value="<?= $h($mapping['source_json_path'] ?? '') ?>" placeholder="<?= $h(_('service.version or 0.name')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Transform')) ?></label>
                    <select class="nbs-input" name="custom_mappings[<?= $h($id) ?>][transform]">
                        <?php foreach (['none' => 'None', 'trim' => 'Trim', 'int' => 'Integer', 'float' => 'Float', 'lower' => 'Lowercase', 'upper' => 'Uppercase', 'slug' => 'Slug'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= $transform === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Lookup endpoint')) ?></label>
                    <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][lookup_endpoint]" value="<?= $h($mapping['lookup_endpoint'] ?? '') ?>" placeholder="<?= $h(_('/dcim/device-roles/ or /dcim/manufacturers/')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Lookup query field')) ?></label>
                    <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][lookup_query_field]" value="<?= $h($mapping['lookup_query_field'] ?? 'name') ?>" placeholder="<?= $h(_('name')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Ensure if missing')) ?></label>
                    <select class="nbs-input" name="custom_mappings[<?= $h($id) ?>][ensure_missing_mode]">
                        <?php foreach (['none' => 'No', 'manufacturer' => 'Create manufacturer', 'device_type' => 'Create device type'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= $ensure_missing_mode === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Manufacturer source mode')) ?></label>
                    <select class="nbs-input" name="custom_mappings[<?= $h($id) ?>][relation_manufacturer_source_mode]">
                        <?php foreach (['static' => 'Static', 'item_key' => 'Item key', 'item_name' => 'Item name', 'host_name' => 'Host name'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= $manufacturer_mode === $value ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Manufacturer source value')) ?></label>
                    <input class="nbs-input" type="text" name="custom_mappings[<?= $h($id) ?>][relation_manufacturer_source_value]" value="<?= $h($mapping['relation_manufacturer_source_value'] ?? '') ?>" placeholder="<?= $h(_('Fortinet')) ?>">
                </div>
                <div class="nbs-span-3">
                    <label class="nbs-label"><?= $h(_('Notes')) ?></label>
                    <textarea class="nbs-textarea" rows="3" name="custom_mappings[<?= $h($id) ?>][notes]" placeholder="<?= $h(_('Optional explanation shown only in settings.')) ?>"><?= $h($mapping['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </details>

        <div class="nbs-repeat-row-actions">
            <button type="button" class="btn-alt nbs-remove-row"><?= $h(_('Remove mapping')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$summary_messages = is_array($last_summary['messages'] ?? null) ? $last_summary['messages'] : [];
$runner_secret_hint = trim((string) ($config['runner']['shared_secret_env'] ?? '')) !== ''
    ? '$'.$config['runner']['shared_secret_env']
    : 'replace-with-shared-secret';
$runner_curl = "curl -fsS -X POST -H 'X-NetBox-Sync-Secret: ".$runner_secret_hint."' '".$runner_url."'";
?>
<div id="nbs-settings-root" class="nbs-page nbs-settings-page" data-nbs-theme="<?= $h($nbs_theme) ?>">
    <div class="nbs-header">
        <div>
            <h1><?= $h($data['title'] ?? _('NetBox sync settings')) ?></h1>
            <p class="nbs-muted"><?= $h(_('Configure the NetBox connection, built-in syncs from your current scripts, device sync, and reusable custom mappings.')) ?></p>
        </div>
        <div class="nbs-header-actions">
            <button type="submit" form="nbs-settings-form" class="btn-alt"><?= $h(_('Save settings')) ?></button>
            <button type="button" id="nbs-run-now" class="btn-alt" data-run-url="<?= $h($run_url) ?>"><?= $h(_('Run now')) ?></button>
            <a class="btn-alt" href="<?= $h($log_url) ?>"><?= $h(_('Open log')) ?></a>
        </div>
    </div>

    <div id="nbs-status" class="nbs-status" hidden></div>

    <form id="nbs-settings-form" method="post" action="<?= $h($settings_save_url) ?>" data-save-url="<?= $h($settings_save_url) ?>" data-run-url="<?= $h($run_url) ?>">
        <input type="hidden" name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" value="<?= $h(CCsrfTokenHelper::get('netboxsync.settings.save')) ?>">
        <input type="hidden" id="nbs-run-csrf-token-name" value="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>">
        <input type="hidden" id="nbs-run-csrf-token-value" value="<?= $h(CCsrfTokenHelper::get('netboxsync.run')) ?>">

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Connections')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-connections">?</button>
            </div>
            <div id="nbs-faq-connections" class="nbs-faq-box">
                <p><strong><?= $h(_('Zabbix data')) ?>:</strong> <?= $h(_('The module runs inside the Zabbix frontend and reads hosts, items, and interfaces directly through the built-in API facade. No Zabbix URL or token is needed.')) ?></p>
                <p><strong><?= $h(_('NetBox')) ?>:</strong> <?= $h(_('Used for VM, device, interface, disk, IP, service, and custom-field updates. For secrets, prefer environment variables when possible.')) ?></p>
            </div>
            <div class="nbs-columns">
                <div class="nbs-column-card">
                    <h3><?= $h(_('NetBox')) ?></h3>
                    <div class="nbs-settings-grid">
                        <div>
                            <label class="nbs-label"><?= $h(_('Enabled')) ?></label>
                            <label class="nbs-checkbox"><input type="checkbox" name="netbox[enabled]" value="1" <?= !empty($config['netbox']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Enable NetBox sync')) ?></label>
                        </div>
                        <div>
                            <label class="nbs-label"><?= $h(_('Timeout')) ?></label>
                            <input class="nbs-input" type="number" min="5" max="300" name="netbox[timeout]" value="<?= $h((int) ($config['netbox']['timeout'] ?? 15)) ?>">
                        </div>
                        <div class="nbs-span-2">
                            <label class="nbs-label"><?= $h(_('Base URL')) ?></label>
                            <input class="nbs-input" type="text" name="netbox[url]" value="<?= $h($config['netbox']['url'] ?? '') ?>" placeholder="https://netbox.example.com/api">
                        </div>
                        <div class="nbs-span-2">
                            <label class="nbs-label"><?= $h(_('API token')) ?></label>
                            <input class="nbs-input" type="password" name="netbox[token]" value="" placeholder="<?= !empty($config['netbox']['token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                            <div class="nbs-inline-notes">
                                <?php if (!empty($config['netbox']['token_present'])): ?>
                                    <span class="nbs-muted"><?= $h(_('Stored token exists.')) ?></span>
                                <?php endif; ?>
                                <label class="nbs-checkbox"><input type="checkbox" name="netbox[clear_token]" value="1"> <?= $h(_('Clear stored token')) ?></label>
                            </div>
                        </div>
                        <div>
                            <label class="nbs-label"><?= $h(_('Token environment variable')) ?></label>
                            <input class="nbs-input" type="text" name="netbox[token_env]" value="<?= $h($config['netbox']['token_env'] ?? '') ?>" placeholder="NETBOX_API_TOKEN">
                        </div>
                        <div>
                            <label class="nbs-label"><?= $h(_('Verify TLS')) ?></label>
                            <label class="nbs-checkbox"><input type="checkbox" name="netbox[verify_peer]" value="1" <?= !empty($config['netbox']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Validate certificates')) ?></label>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Runner and scheduling')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-runner">?</button>
            </div>
            <div id="nbs-faq-runner" class="nbs-faq-box">
                <p><strong><?= $h(_('How it works')) ?>:</strong> <?= $h(_('The module includes a secure runner action for manual runs and for scheduler-triggered runs. Global interval is the default for every built-in sync and custom mapping unless that row has its own override.')) ?></p>
                <p><strong><?= $h(_('Runner URL')) ?>:</strong> <code><?= $h($runner_url) ?></code></p>
                <p><strong><?= $h(_('Suggested scheduler call')) ?>:</strong></p>
                <pre class="nbs-code"><?= $h($runner_curl) ?></pre>
            </div>

            <div class="nbs-settings-grid">
                <div>
                    <label class="nbs-label"><?= $h(_('Runner enabled')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="runner[enabled]" value="1" <?= !empty($config['runner']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow scheduled runs via shared secret')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Global interval (seconds)')) ?></label>
                    <input class="nbs-input" type="number" min="60" max="2592000" name="runner[global_interval_seconds]" value="<?= $h((int) ($config['runner']['global_interval_seconds'] ?? 3600)) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Default prefix length')) ?></label>
                    <input class="nbs-input" type="number" min="1" max="32" name="runner[default_prefix_length]" value="<?= $h((int) ($config['runner']['default_prefix_length'] ?? 24)) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Max hosts per run')) ?></label>
                    <input class="nbs-input" type="number" min="0" max="100000" name="runner[max_hosts_per_run]" value="<?= $h((int) ($config['runner']['max_hosts_per_run'] ?? 0)) ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Shared secret')) ?></label>
                    <input class="nbs-input" type="password" name="runner[shared_secret]" value="" placeholder="<?= !empty($config['runner']['shared_secret_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                    <div class="nbs-inline-notes">
                        <?php if (!empty($config['runner']['shared_secret_present'])): ?>
                            <span class="nbs-muted"><?= $h(_('Stored secret exists.')) ?></span>
                        <?php endif; ?>
                        <label class="nbs-checkbox"><input type="checkbox" name="runner[clear_shared_secret]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Shared-secret environment variable')) ?></label>
                    <input class="nbs-input" type="text" name="runner[shared_secret_env]" value="<?= $h($config['runner']['shared_secret_env'] ?? '') ?>" placeholder="NETBOXSYNC_SHARED_SECRET">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Lock TTL (seconds)')) ?></label>
                    <input class="nbs-input" type="number" min="60" max="86400" name="runner[lock_ttl_seconds]" value="<?= $h((int) ($config['runner']['lock_ttl_seconds'] ?? 1800)) ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('State path')) ?></label>
                    <input class="nbs-input" type="text" name="runner[state_path]" value="<?= $h($config['runner']['state_path'] ?? '') ?>" placeholder="/var/lib/zabbix-netbox-sync/state">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Log path')) ?></label>
                    <input class="nbs-input" type="text" name="runner[log_path]" value="<?= $h($config['runner']['log_path'] ?? '') ?>" placeholder="/var/log/zabbix-netbox-sync">
                </div>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Built-in sync catalogue')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-standard">?</button>
            </div>
            <div id="nbs-faq-standard" class="nbs-faq-box">
                <p><?= $h(_('These rows expose the logic already present in your current scripts, plus the new device flow. Each row can be enabled or disabled independently and can use the global schedule or its own interval override.')) ?></p>
            </div>

            <div class="nbs-table-wrap">
                <table class="nbs-table">
                    <thead>
                        <tr>
                            <th><?= $h(_('Enabled')) ?></th>
                            <th><?= $h(_('Sync')) ?></th>
                            <th><?= $h(_('Zabbix source')) ?></th>
                            <th><?= $h(_('NetBox target')) ?></th>
                            <th><?= $h(_('Interval override')) ?></th>
                            <th><?= $h(_('Notes')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catalog as $entry):
                            $id = (string) ($entry['id'] ?? '');
                            $row = $standard_syncs_by_id[$id] ?? ['id' => $id, 'enabled' => false, 'interval_seconds' => 0];
                        ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="standard_syncs[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
                                    <input type="checkbox" name="standard_syncs[<?= $h($id) ?>][enabled]" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <div class="nbs-table-title"><?= $h($entry['title'] ?? $id) ?></div>
                                    <div class="nbs-table-code"><?= $h($id) ?></div>
                                </td>
                                <td><?= $h($entry['source'] ?? '') ?></td>
                                <td><?= $h($entry['target'] ?? '') ?></td>
                                <td>
                                    <input class="nbs-input" type="number" min="0" max="31536000" name="standard_syncs[<?= $h($id) ?>][interval_seconds]" value="<?= $h((int) ($row['interval_seconds'] ?? 0)) ?>">
                                    <div class="nbs-mini-help"><?= $h(_('0 = use global interval')) ?></div>
                                </td>
                                <td><?= $h($entry['notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('VM sync defaults')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-vm">?</button>
            </div>
            <div id="nbs-faq-vm" class="nbs-faq-box">
                <p><?= $h(_('This section holds the standard item names and keys used by your current VM sync scripts: OS detection, CPU, memory, SQL version, disk discovery, and interface discovery.')) ?></p>
            </div>
            <div class="nbs-settings-grid">
                <div>
                    <label class="nbs-label"><?= $h(_('Enable VM sync')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="vm[enabled]" value="1" <?= !empty($config['vm']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow VM-targeted built-in syncs')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Default site ID')) ?></label>
                    <input class="nbs-input" type="number" min="1" max="1000000" name="vm[default_site]" value="<?= $h((int) ($config['vm']['default_site'] ?? 1)) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Create missing VMs')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="vm[create_missing]" value="1" <?= !empty($config['vm']['create_missing']) ? 'checked' : '' ?>> <?= $h(_('Create if not found')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Update existing VMs')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="vm[update_existing]" value="1" <?= !empty($config['vm']['update_existing']) ? 'checked' : '' ?>> <?= $h(_('Patch existing objects')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Require OS for create')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="vm[require_os_for_create]" value="1" <?= !empty($config['vm']['require_os_for_create']) ? 'checked' : '' ?>> <?= $h(_('Skip creation when OS cannot be detected')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('VM name suffix')) ?></label>
                    <input class="nbs-input" type="text" name="vm[name_suffix]" value="<?= $h($config['vm']['name_suffix'] ?? '') ?>" placeholder="<?= $h(_('Optional suffix')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Suffix separator')) ?></label>
                    <select class="nbs-input" name="vm[name_suffix_separator]">
                        <?php foreach (['.' => '.', '-' => '-', '_' => '_', '' => '(none)'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= (($config['vm']['name_suffix_separator'] ?? '.') === $value) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('OS pretty-name item')) ?></label>
                    <input class="nbs-input" type="text" name="vm[os_pretty_name_item_name]" value="<?= $h($config['vm']['os_pretty_name_item_name'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('OS fallback key')) ?></label>
                    <input class="nbs-input" type="text" name="vm[os_fallback_item_key]" value="<?= $h($config['vm']['os_fallback_item_key'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Linux CPU key')) ?></label>
                    <input class="nbs-input" type="text" name="vm[linux_cpu_item_key]" value="<?= $h($config['vm']['linux_cpu_item_key'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Windows CPU key')) ?></label>
                    <input class="nbs-input" type="text" name="vm[windows_cpu_item_key]" value="<?= $h($config['vm']['windows_cpu_item_key'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Memory key')) ?></label>
                    <input class="nbs-input" type="text" name="vm[memory_item_key]" value="<?= $h($config['vm']['memory_item_key'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('SQL version key')) ?></label>
                    <input class="nbs-input" type="text" name="vm[sql_version_item_key]" value="<?= $h($config['vm']['sql_version_item_key'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Windows disk search')) ?></label>
                    <input class="nbs-input" type="text" name="vm[windows_disk_search]" value="<?= $h($config['vm']['windows_disk_search'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Linux disk search')) ?></label>
                    <input class="nbs-input" type="text" name="vm[linux_disk_search]" value="<?= $h($config['vm']['linux_disk_search'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Interface item search')) ?></label>
                    <input class="nbs-input" type="text" name="vm[interface_search]" value="<?= $h($config['vm']['interface_search'] ?? '') ?>">
                </div>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Listening-services sync')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-services">?</button>
            </div>
            <div id="nbs-faq-services" class="nbs-faq-box">
                <p><?= $h(_('This sync expects one of the listening-service plugins/templates to populate the configured Zabbix item names. The built-in sync row "vm_services" can then mirror those TCP listeners into NetBox services.')) ?></p>
                <p><strong>Linux plugin:</strong> <a href="<?= $h($linux_plugin_url) ?>" target="_blank" rel="noopener noreferrer"><?= $h($linux_plugin_url) ?></a></p>
                <p><strong>Windows plugin:</strong> <a href="<?= $h($windows_plugin_url) ?>" target="_blank" rel="noopener noreferrer"><?= $h($windows_plugin_url) ?></a></p>
            </div>
            <div class="nbs-settings-grid">
                <div>
                    <label class="nbs-label"><?= $h(_('Enable services feature')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="services[enabled]" value="1" <?= !empty($config['services']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow the built-in listening-services sync')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Prune stale services')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="services[prune_stale]" value="1" <?= !empty($config['services']['prune_stale']) ? 'checked' : '' ?>> <?= $h(_('Delete NetBox services no longer reported by Zabbix')) ?></label>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Windows item name')) ?></label>
                    <input class="nbs-input" type="text" name="services[windows_item_name]" value="<?= $h($config['services']['windows_item_name'] ?? '') ?>">
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Linux item name')) ?></label>
                    <input class="nbs-input" type="text" name="services[linux_item_name]" value="<?= $h($config['services']['linux_item_name'] ?? '') ?>">
                </div>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Device sync defaults')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-device">?</button>
            </div>
            <div id="nbs-faq-device" class="nbs-faq-box">
                <p><?= $h(_('Device creation requires site, role, status, and a NetBox device type. This module can resolve the device type from manufacturer + model and optionally create missing manufacturers and device types. Matching is flexible enough to reuse existing types when the source model contains a parenthetical code such as FortiGate 100F (FG100F).')) ?></p>
            </div>
            <div class="nbs-settings-grid">
                <div>
                    <label class="nbs-label"><?= $h(_('Enable device sync')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="device[enabled]" value="1" <?= !empty($config['device']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow device-targeted built-in syncs and device mappings')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Default site ID')) ?></label>
                    <input class="nbs-input" type="number" min="1" max="1000000" name="device[default_site]" value="<?= $h((int) ($config['device']['default_site'] ?? 1)) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Default role ID')) ?></label>
                    <input class="nbs-input" type="number" min="1" max="1000000" name="device[default_role_id]" value="<?= $h((int) ($config['device']['default_role_id'] ?? 1)) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Default status')) ?></label>
                    <input class="nbs-input" type="text" name="device[default_status]" value="<?= $h($config['device']['default_status'] ?? 'active') ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Create missing devices')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="device[create_missing]" value="1" <?= !empty($config['device']['create_missing']) ? 'checked' : '' ?>> <?= $h(_('Create when name is not found')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Update existing devices')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="device[update_existing]" value="1" <?= !empty($config['device']['update_existing']) ? 'checked' : '' ?>> <?= $h(_('Patch existing objects')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Create missing manufacturer')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="device[create_missing_manufacturer]" value="1" <?= !empty($config['device']['create_missing_manufacturer']) ? 'checked' : '' ?>> <?= $h(_('Auto-create manufacturer')) ?></label>
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Create missing device type')) ?></label>
                    <label class="nbs-checkbox"><input type="checkbox" name="device[create_missing_device_type]" value="1" <?= !empty($config['device']['create_missing_device_type']) ? 'checked' : '' ?>> <?= $h(_('Auto-create device type')) ?></label>
                </div>

                <div>
                    <label class="nbs-label"><?= $h(_('Device name source mode')) ?></label>
                    <select class="nbs-input" name="device[name_source_mode]">
                        <?php foreach (['host_name' => 'Host name', 'item_key' => 'Item key', 'item_name' => 'Item name', 'static' => 'Static', 'agent_ip' => 'Agent IP'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= (($config['device']['name_source_mode'] ?? 'host_name') === $value) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Device name source value')) ?></label>
                    <input class="nbs-input" type="text" name="device[name_source_value]" value="<?= $h($config['device']['name_source_value'] ?? '') ?>" placeholder="<?= $h(_('Leave blank to use host name')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Manufacturer source mode')) ?></label>
                    <select class="nbs-input" name="device[manufacturer_source_mode]">
                        <?php foreach (['static' => 'Static', 'item_key' => 'Item key', 'item_name' => 'Item name', 'host_name' => 'Host name'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= (($config['device']['manufacturer_source_mode'] ?? 'static') === $value) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Manufacturer source value')) ?></label>
                    <input class="nbs-input" type="text" name="device[manufacturer_source_value]" value="<?= $h($config['device']['manufacturer_source_value'] ?? '') ?>" placeholder="<?= $h(_('Fortinet')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Model source mode')) ?></label>
                    <select class="nbs-input" name="device[model_source_mode]">
                        <?php foreach (['static' => 'Static', 'item_key' => 'Item key', 'item_name' => 'Item name', 'host_name' => 'Host name'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= (($config['device']['model_source_mode'] ?? 'item_key') === $value) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Model source value')) ?></label>
                    <input class="nbs-input" type="text" name="device[model_source_value]" value="<?= $h($config['device']['model_source_value'] ?? '') ?>" placeholder="<?= $h(_('fgate.device.model')) ?>">
                </div>
                <div>
                    <label class="nbs-label"><?= $h(_('Serial source mode')) ?></label>
                    <select class="nbs-input" name="device[serial_source_mode]">
                        <?php foreach (['static' => 'Static', 'item_key' => 'Item key', 'item_name' => 'Item name', 'host_name' => 'Host name', 'agent_ip' => 'Agent IP'] as $value => $label): ?>
                            <option value="<?= $h($value) ?>" <?= (($config['device']['serial_source_mode'] ?? 'item_key') === $value) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="nbs-span-2">
                    <label class="nbs-label"><?= $h(_('Serial source value')) ?></label>
                    <input class="nbs-input" type="text" name="device[serial_source_value]" value="<?= $h($config['device']['serial_source_value'] ?? '') ?>" placeholder="<?= $h(_('fgate.device.serialnumber')) ?>">
                </div>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Custom mappings')) ?></h2>
                <button type="button" class="nbs-faq-toggle" data-faq-target="nbs-faq-custom">?</button>
            </div>
            <div id="nbs-faq-custom" class="nbs-faq-box">
                <p><?= $h(_('Use these rows when you want to add extra syncs without changing PHP code. Most mappings only need: source mode + source value + target object + target field.')) ?></p>
                <ul>
                    <li><strong><?= $h(_('Simple custom field')) ?>:</strong> <?= $h(_('Source mode "item key", source value "fgate.device.serialnumber", target object "device", target field "custom_fields.serial_from_zabbix".')) ?></li>
                    <li><strong><?= $h(_('Advanced device type')) ?>:</strong> <?= $h(_('Mode "ensure device type", source mode "item key", source value "fgate.device.model", target object "device", target field "device_type", manufacturer source mode "static", manufacturer source value "Fortinet".')) ?></li>
                    <li><strong><?= $h(_('Direct URL patch')) ?>:</strong> <?= $h(_('Target object "custom URL" and target URL template "/dcim/devices/{device_id}/" lets you patch any NetBox object already resolved by another mapping or built-in sync.')) ?></li>
                </ul>
            </div>

            <div id="nbs-mappings-list" class="nbs-repeat-list">
                <?php foreach ($custom_mappings as $mapping): ?>
                    <?= $render_mapping_row($mapping) ?>
                <?php endforeach; ?>
            </div>

            <div class="nbs-section-actions">
                <button type="button" class="btn-alt" data-add-row="mapping"><?= $h(_('Add mapping')) ?></button>
            </div>
        </section>

        <section class="nbs-card">
            <div class="nbs-section-header">
                <h2><?= $h(_('Last run summary')) ?></h2>
            </div>
            <?php if ($last_summary === []): ?>
                <p class="nbs-muted"><?= $h(_('No sync run has been recorded yet.')) ?></p>
            <?php else: ?>
                <div class="nbs-summary-grid">
                    <div class="nbs-summary-tile"><span><?= $h(_('Started')) ?></span><strong><?= $h($last_summary['started_at'] ?? '') ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Finished')) ?></span><strong><?= $h($last_summary['finished_at'] ?? '') ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Elapsed')) ?></span><strong><?= $h($last_summary['elapsed_seconds'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Processed hosts')) ?></span><strong><?= $h(($last_summary['hosts_processed'] ?? 0).'/'.($last_summary['hosts_total'] ?? 0)) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Mappings run')) ?></span><strong><?= $h($last_summary['mappings_run'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Created')) ?></span><strong><?= $h($last_summary['created'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Updated')) ?></span><strong><?= $h($last_summary['updated'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Deleted')) ?></span><strong><?= $h($last_summary['deleted'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Unchanged')) ?></span><strong><?= $h($last_summary['unchanged'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Skipped')) ?></span><strong><?= $h($last_summary['skipped'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Errors')) ?></span><strong><?= $h($last_summary['errors'] ?? 0) ?></strong></div>
                    <div class="nbs-summary-tile"><span><?= $h(_('Source')) ?></span><strong><?= $h($last_summary['source'] ?? '') ?></strong></div>
                </div>

                <?php if ($summary_messages !== []): ?>
                    <div class="nbs-log-list">
                        <?php foreach (array_slice($summary_messages, -20) as $message): ?>
                            <div class="nbs-log-row nbs-level-<?= $h($message['level'] ?? 'info') ?>">
                                <span class="nbs-log-time"><?= $h($message['time'] ?? '') ?></span>
                                <span class="nbs-log-level"><?= $h(strtoupper((string) ($message['level'] ?? 'INFO'))) ?></span>
                                <span class="nbs-log-message"><?= $h($message['message'] ?? '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </form>

    <template id="nbs-template-mapping">
        <?= str_replace(["\n", "\r"], ['&#10;', ''], $render_mapping_row()) ?>
    </template>
</div>
