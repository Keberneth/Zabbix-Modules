<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$config = $data['config'] ?? [];
$checks = is_array($config['checks'] ?? null) ? $config['checks'] : [];

$settings_save_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.settings.save')
    ->getUrl();

$heartbeat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.heartbeat')
    ->getUrl();

$history_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.history')
    ->getUrl();

$hc_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $hc_theme = 'dark';
    }
}

$render_check_row = static function(array $check = []) use ($h): string {
    ob_start();
    $id = $check['id'] ?? '';
    ?>
    <div class="hc-repeat-row hc-check-row" data-row-type="check">
        <input type="hidden" class="hc-row-id-field" name="checks[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="hc-repeat-grid hc-check-grid">
            <div>
                <label class="hc-label"><?= $h(_('Name')) ?></label>
                <input class="hc-input" type="text" name="checks[<?= $h($id) ?>][name]" value="<?= $h($check['name'] ?? '') ?>" placeholder="Primary Zabbix frontend">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Enabled')) ?></label>
                <label class="hc-checkbox">
                    <input type="checkbox" name="checks[<?= $h($id) ?>][enabled]" value="1" <?= !empty($check['enabled']) ? 'checked' : '' ?>>
                    <?= $h(_('Run this check')) ?>
                </label>
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Interval (seconds)')) ?></label>
                <input class="hc-input" type="number" min="30" max="86400" name="checks[<?= $h($id) ?>][interval_seconds]" value="<?= $h($check['interval_seconds'] ?? 300) ?>">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Timeout (seconds)')) ?></label>
                <input class="hc-input" type="number" min="3" max="300" name="checks[<?= $h($id) ?>][timeout]" value="<?= $h($check['timeout'] ?? 10) ?>">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Fresh data max age')) ?></label>
                <input class="hc-input" type="number" min="60" max="86400" name="checks[<?= $h($id) ?>][freshness_max_age]" value="<?= $h($check['freshness_max_age'] ?? 900) ?>">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('API auth mode')) ?></label>
                <?php
                $auth_mode_labels = [
                    'auto' => _('Automatic'),
                    'bearer' => _('Bearer token'),
                    'legacy_auth_field' => _('Legacy auth field')
                ];
                ?>
                <select class="hc-input" name="checks[<?= $h($id) ?>][auth_mode]">
                    <?php foreach ($auth_mode_labels as $auth_mode => $auth_label): ?>
                        <option value="<?= $h($auth_mode) ?>" <?= (($check['auth_mode'] ?? 'auto') === $auth_mode) ? 'selected' : '' ?>><?= $h($auth_label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hc-span-3">
                <label class="hc-label"><?= $h(_('Ping URL')) ?></label>
                <input class="hc-input" type="text" name="checks[<?= $h($id) ?>][ping_url]" value="<?= $h($check['ping_url'] ?? '') ?>" placeholder="https://hc-ping.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            </div>
            <div class="hc-span-3">
                <label class="hc-label"><?= $h(_('Zabbix API URL')) ?></label>
                <input class="hc-input" type="text" name="checks[<?= $h($id) ?>][zabbix_api_url]" value="<?= $h($check['zabbix_api_url'] ?? '') ?>" placeholder="https://zabbix.example.local/api_jsonrpc.php">
            </div>
            <div class="hc-span-2">
                <label class="hc-label"><?= $h(_('Zabbix API token')) ?></label>
                <input class="hc-input" type="password" name="checks[<?= $h($id) ?>][zabbix_api_token]" value="" placeholder="<?= !empty($check['zabbix_api_token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                <div class="hc-inline-notes">
                    <?php if (!empty($check['zabbix_api_token_present'])): ?>
                        <span class="hc-muted"><?= $h(_('Stored token exists.')) ?></span>
                    <?php endif; ?>
                    <label class="hc-checkbox">
                        <input type="checkbox" name="checks[<?= $h($id) ?>][clear_zabbix_api_token]" value="1">
                        <?= $h(_('Clear stored token')) ?>
                    </label>
                </div>
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Token environment variable')) ?></label>
                <input class="hc-input" type="text" name="checks[<?= $h($id) ?>][zabbix_api_token_env]" value="<?= $h($check['zabbix_api_token_env'] ?? '') ?>" placeholder="ZABBIX_API_TOKEN">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Verify TLS')) ?></label>
                <label class="hc-checkbox">
                    <input type="checkbox" name="checks[<?= $h($id) ?>][verify_peer]" value="1" <?= !empty($check['verify_peer']) ? 'checked' : '' ?>>
                    <?= $h(_('Enable certificate validation')) ?>
                </label>
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Host limit')) ?></label>
                <input class="hc-input" type="number" min="1" max="50000" name="checks[<?= $h($id) ?>][host_limit]" value="<?= $h($check['host_limit'] ?? 5000) ?>">
            </div>
            <div>
                <label class="hc-label"><?= $h(_('Item limit per host')) ?></label>
                <input class="hc-input" type="number" min="1" max="50000" name="checks[<?= $h($id) ?>][item_limit_per_host]" value="<?= $h($check['item_limit_per_host'] ?? 10000) ?>">
            </div>
        </div>
        <div class="hc-repeat-row-actions">
            <button type="button" class="hc-btn-danger hc-remove-row"><?= $h(_('Remove check')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

ob_start();
?>
<div id="healthcheck-settings-root" class="hc-page hc-settings-page"
     data-healthcheck-theme="<?= $h($hc_theme) ?>"
     data-i18n-saving="<?= $h(_('Saving…')) ?>"
     data-i18n-save="<?= $h(_('Save settings')) ?>"
     data-i18n-save-failed="<?= $h(_('Save failed.')) ?>"
     data-i18n-unexpected="<?= $h(_('Unexpected response from server.')) ?>"
     data-i18n-copied="<?= $h(_('Copied!')) ?>"
     data-i18n-copy-failed="<?= $h(_('Copy failed')) ?>">
    <div class="hc-header">
        <div>
            <h1><?= $h($data['title'] ?? _('Healthcheck settings')) ?></h1>
            <p class="hc-muted">
                Configure one or more Zabbix health checks. The API token field keeps the existing token when left blank, and you can switch to an environment variable later without exposing the stored value.
            </p>
        </div>
        <div class="hc-header-actions">
            <a class="btn-alt" href="<?= $h($heartbeat_url) ?>"><?= $h(_('Heartbeat')) ?></a>
            <a class="btn-alt" href="<?= $h($history_url) ?>"><?= $h(_('History')) ?></a>
        </div>
    </div>

    <form id="healthcheck-settings-form" method="post" action="<?= $h($settings_save_url) ?>">
        <input type="hidden" name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" value="<?= $h(CCsrfTokenHelper::get('healthcheck.settings.save')) ?>">

        <section class="hc-card">
            <h2><?= $h(_('Checks')) ?></h2>
            <p class="hc-muted">
                Each row represents a full health probe: Zabbix API availability, monitored host count, active problem trigger count, enabled item count, freshest item timestamp, and final ping delivery.
            </p>
            <div id="healthcheck-checks-list" class="hc-repeat-list">
                <?php foreach ($checks as $check): ?>
                    <?= $render_check_row($check) ?>
                <?php endforeach; ?>
            </div>
            <div class="hc-section-actions">
                <button type="button" class="btn-alt" data-add-row="check"><?= $h(_('Add check')) ?></button>
            </div>
        </section>

        <section class="hc-card">
            <h2><?= $h(_('History and retention')) ?></h2>
            <div class="hc-repeat-grid hc-settings-grid">
                <div>
                    <label class="hc-label"><?= $h(_('Retention (days)')) ?></label>
                    <input class="hc-input" type="number" min="1" max="3650" name="history[retention_days]" value="<?= $h($config['history']['retention_days'] ?? 90) ?>">
                </div>
                <div>
                    <label class="hc-label"><?= $h(_('Default history period (days)')) ?></label>
                    <input class="hc-input" type="number" min="1" max="365" name="history[default_period_days]" value="<?= $h($config['history']['default_period_days'] ?? 7) ?>">
                </div>
                <div>
                    <label class="hc-label"><?= $h(_('Recent run rows to keep in UI')) ?></label>
                    <input class="hc-input" type="number" min="20" max="1000" name="history[recent_runs_limit]" value="<?= $h($config['history']['recent_runs_limit'] ?? 200) ?>">
                </div>
            </div>
        </section>

        <?php
        $runner_path    = $data['runner_script_path'] ?? '/usr/share/zabbix/modules/Healthcheck/bin/healthcheck-runner.php';
        $cron_schedule  = $data['cron_schedule'] ?? '*/5 * * * *';
        $default_user   = 'nginx';

        // Build initial commands using the default user (JS updates these on dropdown change).
        $cron_line = $cron_schedule.' /usr/bin/php '.$runner_path.' --json >/var/log/zabbix/healthcheck-runner.log 2>&1';

        $cron_install = '# Install or update the cron job for user "'.$default_user.'"'."\n"
            .'# (safe to re-run — replaces any previous healthcheck-runner entry)'."\n"
            .'sudo crontab -u '.$default_user.' -l 2>/dev/null | grep -v healthcheck-runner | { cat; echo \''.$cron_line.'\'; } | sudo crontab -u '.$default_user.' -'."\n"
            ."\n"
            .'# Verify'."\n"
            .'sudo crontab -u '.$default_user.' -l';

        $systemd_commands = '# Create / update the service unit'."\n"
            .'cat << \'EOF\' | sudo tee /etc/systemd/system/healthcheck-runner.service > /dev/null'."\n"
            .'[Unit]'."\n"
            .'Description=Zabbix Healthcheck module runner'."\n"
            .'Wants=network-online.target'."\n"
            .'After=network-online.target'."\n"
            ."\n"
            .'[Service]'."\n"
            .'Type=oneshot'."\n"
            .'User='.$default_user."\n"
            .'Group='.$default_user."\n"
            .'ExecStart=/usr/bin/php '.$runner_path.' --json'."\n"
            .'NoNewPrivileges=true'."\n"
            .'PrivateTmp=true'."\n"
            .'ProtectHome=true'."\n"
            .'ProtectSystem=full'."\n"
            .'EOF'."\n"
            ."\n"
            .'# Create / update the timer unit'."\n"
            .'cat << \'EOF\' | sudo tee /etc/systemd/system/healthcheck-runner.timer > /dev/null'."\n"
            .'[Unit]'."\n"
            .'Description=Run the Zabbix Healthcheck module runner every minute'."\n"
            ."\n"
            .'[Timer]'."\n"
            .'OnBootSec=1min'."\n"
            .'OnUnitActiveSec=1min'."\n"
            .'Unit=healthcheck-runner.service'."\n"
            .'Persistent=true'."\n"
            ."\n"
            .'[Install]'."\n"
            .'WantedBy=timers.target'."\n"
            .'EOF'."\n"
            ."\n"
            .'# Reload, enable and (re)start the timer'."\n"
            .'sudo systemctl daemon-reload'."\n"
            .'sudo systemctl enable --now healthcheck-runner.timer'."\n"
            .'sudo systemctl restart healthcheck-runner.timer'."\n"
            ."\n"
            .'# Verify'."\n"
            .'systemctl list-timers healthcheck-runner.timer';

        $test_command = 'sudo -u '.$default_user.' /usr/bin/php '.$runner_path.' --json';
        ?>
        <section class="hc-card" id="healthcheck-scheduler-section"
                 data-runner-path="<?= $h($runner_path) ?>"
                 data-cron-schedule="<?= $h($cron_schedule) ?>">
            <h2><?= $h(_('Scheduler integration')) ?></h2>
            <p class="hc-muted">
                The module runs health checks opportunistically on every Zabbix page load, but for truly unattended operation
                (no one browsing the UI) you should also set up one of the schedulers below.
                The runner is called every minute; it skips checks that are not due yet based on each check's interval.
            </p>

            <div class="hc-repeat-grid" style="grid-template-columns: 1fr 1fr; max-width: 500px;">
                <div>
                    <label class="hc-label"><?= $h(_('Web server')) ?></label>
                    <select class="hc-input" id="hc-runner-user-select">
                        <option value="nginx" selected>nginx</option>
                        <option value="apache">apache</option>
                        <option value="www-data">www-data</option>
                        <option value="zabbix">zabbix</option>
                    </select>
                </div>
                <div>
                    <label class="hc-label"><?= $h(_('Cron schedule')) ?></label>
                    <input class="hc-input" type="text" readonly value="<?= $h($cron_schedule) ?>">
                </div>
            </div>

            <label class="hc-label"><?= $h(_('Runner path')) ?></label>
            <div class="hc-copy-block">
                <input class="hc-input hc-copy-target" type="text" readonly value="<?= $h($runner_path) ?>">
                <button type="button" class="btn-alt hc-copy-btn" title="<?= $h(_('Copy to clipboard')) ?>"><?= $h(_('Copy')) ?></button>
            </div>

            <h3 class="hc-label" style="margin-top:20px;font-size:1em;"><?= $h(_('Option A — Cron')) ?></h3>
            <p class="hc-muted">
                Copy-paste the block below to create or update the cron job.
                Re-running is safe &mdash; it replaces any previous healthcheck-runner entry.
            </p>
            <div class="hc-copy-block">
                <textarea class="hc-textarea hc-copy-target" id="hc-cron-commands" rows="5" readonly><?= $h($cron_install) ?></textarea>
                <button type="button" class="btn-alt hc-copy-btn" title="<?= $h(_('Copy to clipboard')) ?>"><?= $h(_('Copy')) ?></button>
            </div>

            <h3 class="hc-label" style="margin-top:20px;font-size:1em;"><?= $h(_('Option B — systemd timer (recommended)')) ?></h3>
            <p class="hc-muted">
                Copy-paste the block below to create or update the systemd units.
                The timer runs every minute and survives reboots.
                Safe to re-run after changing settings &mdash; overwrites the unit files and restarts the timer.
            </p>
            <div class="hc-copy-block">
                <textarea class="hc-textarea hc-copy-target" id="hc-systemd-commands" rows="25" readonly><?= $h($systemd_commands) ?></textarea>
                <button type="button" class="btn-alt hc-copy-btn" title="<?= $h(_('Copy to clipboard')) ?>"><?= $h(_('Copy')) ?></button>
            </div>
            <div class="hc-muted" style="margin-top:12px; padding:10px 14px; border:1px solid var(--hc-input-border); border-radius:4px;">
                <strong>Troubleshooting: Permission denied with nginx</strong>
                <p style="margin:6px 0 4px;">
                    If the runner fails with:
                </p>
                <code style="display:block; font-size:0.85em; margin:4px 0 10px; word-break:break-all;">
                    PHP Warning: require(/etc/zabbix/web/zabbix.conf.php): Failed to open stream: Permission denied
                </code>
                <p style="margin:6px 0 4px;">
                    This happens because <code>zabbix.conf.php</code> is typically owned by <code>apache</code> and not
                    readable by the <code>nginx</code> user.
                </p>
                <p style="margin:6px 0 2px;"><strong>Fix 1 (simplest):</strong> Select <code>apache</code> in the Web server dropdown above and re-copy the commands.</p>
                <p style="margin:6px 0 2px;"><strong>Fix 2 (keeps nginx):</strong> Add <code>nginx</code> to the <code>apache</code> group so it can read the config file:</p>
                <div class="hc-copy-block" style="margin-top:6px;">
                    <textarea class="hc-textarea hc-copy-target" rows="2" readonly style="font-size:0.9em;">sudo usermod -aG apache nginx
sudo chmod 640 /etc/zabbix/web/zabbix.conf.php</textarea>
                    <button type="button" class="btn-alt hc-copy-btn" title="<?= $h(_('Copy to clipboard')) ?>"><?= $h(_('Copy')) ?></button>
                </div>
            </div>

            <h3 class="hc-label" style="margin-top:20px;font-size:1em;"><?= $h(_('Manual test')) ?></h3>
            <p class="hc-muted">
                Run a one-off check to verify the runner works.
            </p>
            <div class="hc-copy-block">
                <textarea class="hc-textarea hc-copy-target" id="hc-test-command" rows="1" readonly><?= $h($test_command) ?></textarea>
                <button type="button" class="btn-alt hc-copy-btn" title="<?= $h(_('Copy to clipboard')) ?>"><?= $h(_('Copy')) ?></button>
            </div>
        </section>

        <div class="hc-form-actions">
            <button type="submit" class="hc-btn-primary"><?= $h(_('Save settings')) ?></button>
        </div>
    </form>

    <template id="healthcheck-check-template">
        <?= $render_check_row([
            'id' => '__ROW_ID__',
            'name' => '',
            'enabled' => true,
            'interval_seconds' => 300,
            'ping_url' => '',
            'zabbix_api_url' => '',
            'zabbix_api_token_present' => false,
            'zabbix_api_token_env' => '',
            'verify_peer' => true,
            'timeout' => 10,
            'freshness_max_age' => 900,
            'host_limit' => 5000,
            'item_limit_per_host' => 10000,
            'auth_mode' => 'auto'
        ]) ?>
    </template>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('Healthcheck settings'))
    ->addItem(new class($content) {
        private $html;

        public function __construct($html) {
            $this->html = $html;
        }

        public function toString($destroy = true) {
            return $this->html;
        }
    })
    ->show();
