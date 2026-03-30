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
                <select class="hc-input" name="checks[<?= $h($id) ?>][auth_mode]">
                    <?php foreach (['auto', 'bearer', 'legacy_auth_field'] as $auth_mode): ?>
                        <option value="<?= $h($auth_mode) ?>" <?= (($check['auth_mode'] ?? 'auto') === $auth_mode) ? 'selected' : '' ?>><?= $h($auth_mode) ?></option>
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
            <button type="button" class="btn-alt hc-remove-row"><?= $h(_('Remove check')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

ob_start();
?>
<div id="healthcheck-settings-root" class="hc-page hc-settings-page" data-healthcheck-theme="<?= $h($hc_theme) ?>">
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

        <section class="hc-card">
            <h2><?= $h(_('Scheduler integration')) ?></h2>
            <p class="hc-muted">
                The module includes a CLI runner that should be called every minute from a systemd timer or cron job. Each check still uses its own interval, so the runner skips checks that are not due yet.
            </p>
            <label class="hc-label"><?= $h(_('Runner path')) ?></label>
            <input class="hc-input" type="text" readonly value="<?= $h($data['runner_script_path'] ?? '') ?>">
            <label class="hc-label"><?= $h(_('Recommended command')) ?></label>
            <textarea class="hc-textarea" rows="3" readonly>/usr/bin/php <?= $h($data['runner_script_path'] ?? '/usr/share/zabbix/modules/Healthcheck/bin/healthcheck-runner.php') ?> --json</textarea>
            <p class="hc-muted">
                Example systemd unit and timer files are included in the module under <code>examples/systemd/</code>.
            </p>
        </section>

        <div class="hc-form-actions">
            <button type="submit" class="btn-alt hc-primary-button"><?= $h(_('Save settings')) ?></button>
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
