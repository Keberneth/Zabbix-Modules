<?php

use Modules\Healthcheck\Lib\Storage;
use Modules\Healthcheck\Lib\Util;

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$status_badge = static function($status) use ($h): string {
    $status = (int) $status;
    return '<span class="hc-badge '.Util::statusCssClass($status).'">'.$h(Util::statusLabel($status)).'</span>';
};

$checks = is_array($data['checks'] ?? null) ? $data['checks'] : [];
$selected_check_id = (string) ($data['selected_check_id'] ?? '');
$period_days = (int) ($data['period_days'] ?? 7);
$stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
$runs = is_array($data['runs'] ?? null) ? $data['runs'] : [];
$failures = is_array($data['failures'] ?? null) ? $data['failures'] : [];
$steps_by_runid = is_array($data['steps_by_runid'] ?? null) ? $data['steps_by_runid'] : [];
$is_super_admin = !empty($data['is_super_admin']);

$history_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.history')
    ->getUrl();

$heartbeat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.heartbeat')
    ->getUrl();

$settings_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.settings')
    ->getUrl();

$hc_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $hc_theme = 'dark';
    }
}

ob_start();
?>
<div id="healthcheck-history-root" class="hc-page hc-history-page" data-healthcheck-theme="<?= $h($hc_theme) ?>">
    <div class="hc-header">
        <div>
            <h1><?= $h($data['title'] ?? _('Healthcheck history')) ?></h1>
            <p class="hc-muted">
                Aggregated success statistics and recent run history for the selected period.
            </p>
        </div>
        <div class="hc-header-actions">
            <a class="btn-alt" href="<?= $h($heartbeat_url) ?>"><?= $h(_('Heartbeat')) ?></a>
            <?php if ($is_super_admin): ?>
                <a class="btn-alt" href="<?= $h($settings_url) ?>"><?= $h(_('Settings')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <section class="hc-card">
        <form class="hc-filter-form" method="get" action="<?= $h($history_url) ?>">
            <input type="hidden" name="action" value="healthcheck.history">
            <div class="hc-repeat-grid hc-settings-grid">
                <div>
                    <label class="hc-label"><?= $h(_('Check')) ?></label>
                    <select class="hc-input" name="checkid">
                        <option value=""><?= $h(_('All checks')) ?></option>
                        <?php foreach ($checks as $check): ?>
                            <?php $check_id = (string) ($check['id'] ?? ''); ?>
                            <option value="<?= $h($check_id) ?>" <?= $selected_check_id === $check_id ? 'selected' : '' ?>><?= $h($check['name'] ?? $check_id) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="hc-label"><?= $h(_('Period')) ?></label>
                    <select class="hc-input" name="period_days">
                        <?php foreach ([1, 7, 30, 90] as $candidate): ?>
                            <option value="<?= $h($candidate) ?>" <?= $period_days === $candidate ? 'selected' : '' ?>><?= $h($candidate.' day(s)') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hc-filter-actions">
                    <button type="submit" class="btn-alt"><?= $h(_('Apply')) ?></button>
                </div>
            </div>
        </form>
    </section>

    <section class="hc-card">
        <h2><?= $h(_('Statistics')) ?></h2>
        <div class="hc-metrics-grid">
            <div class="hc-metric-card">
                <h3><?= $h(_('Total runs')) ?></h3>
                <div class="hc-big-number"><?= $h($stats['total_runs'] ?? 0) ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Success rate')) ?></h3>
                <div class="hc-big-number"><?= $h(number_format((float) ($stats['success_rate'] ?? 0), 2)) ?>%</div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Failed runs')) ?></h3>
                <div class="hc-big-number"><?= $h($stats['failed_runs'] ?? 0) ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Average duration')) ?></h3>
                <div class="hc-big-number"><?= $h(Util::formatDurationMs($stats['avg_duration_ms'] ?? null)) ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Average ping latency')) ?></h3>
                <div class="hc-big-number"><?= $h(Util::formatDurationMs($stats['avg_ping_latency_ms'] ?? null)) ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Average freshest age')) ?></h3>
                <div class="hc-big-number"><?= $h(isset($stats['avg_freshest_age_sec']) ? Util::formatAge($stats['avg_freshest_age_sec']) : '—') ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Last success')) ?></h3>
                <div class="hc-big-number hc-small-text"><?= $h(Util::formatTimestamp($stats['last_success_at'] ?? null)) ?></div>
            </div>
            <div class="hc-metric-card">
                <h3><?= $h(_('Last failure')) ?></h3>
                <div class="hc-big-number hc-small-text"><?= $h(Util::formatTimestamp($stats['last_failure_at'] ?? null)) ?></div>
            </div>
        </div>
    </section>

    <section class="hc-card">
        <h2><?= $h(_('Recent failed runs')) ?></h2>
        <div class="hc-table-wrap">
            <table class="hc-table">
                <thead>
                    <tr>
                        <th><?= $h(_('Check')) ?></th>
                        <th><?= $h(_('Time')) ?></th>
                        <th><?= $h(_('Summary')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failures as $failure): ?>
                        <tr>
                            <td><?= $h($failure['check_name'] ?? $failure['checkid'] ?? '') ?></td>
                            <td><?= $h(Util::formatTimestamp($failure['finished_at'] ?? 0)) ?></td>
                            <td><?= $h($failure['summary'] ?? $failure['error_text'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($failures === []): ?>
                        <tr><td colspan="3" class="hc-empty-cell"><?= $h(_('No failed runs were found in the selected period.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="hc-card">
        <h2><?= $h(_('Run history')) ?></h2>
        <div class="hc-table-wrap">
            <table class="hc-table">
                <thead>
                    <tr>
                        <th><?= $h(_('Time')) ?></th>
                        <th><?= $h(_('Check')) ?></th>
                        <th><?= $h(_('Status')) ?></th>
                        <th><?= $h(_('Duration')) ?></th>
                        <th><?= $h(_('Hosts')) ?></th>
                        <th><?= $h(_('Problems')) ?></th>
                        <th><?= $h(_('Items')) ?></th>
                        <th><?= $h(_('Freshest age')) ?></th>
                        <th><?= $h(_('Ping latency')) ?></th>
                        <th><?= $h(_('Summary')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr>
                            <td><?= $h(Util::formatTimestamp($run['finished_at'] ?? 0)) ?></td>
                            <td><?= $h($run['check_name'] ?? $run['checkid'] ?? '') ?></td>
                            <td><?= $status_badge($run['status'] ?? Storage::STATUS_SKIP) ?></td>
                            <td><?= $h(Util::formatDurationMs($run['duration_ms'] ?? null)) ?></td>
                            <td><?= $h($run['hosts_count'] ?? '—') ?></td>
                            <td><?= $h($run['triggers_count'] ?? '—') ?></td>
                            <td><?= $h($run['items_count'] ?? '—') ?></td>
                            <td><?= $h(isset($run['freshest_age_sec']) ? Util::formatAge($run['freshest_age_sec']) : '—') ?></td>
                            <td><?= $h(isset($run['ping_latency_ms']) ? Util::formatDurationMs($run['ping_latency_ms']) : '—') ?></td>
                            <td>
                                <div><?= $h($run['summary'] ?? '') ?></div>
                                <?php $run_steps = $steps_by_runid[$run['runid']] ?? []; ?>
                                <?php if ($run_steps !== []): ?>
                                    <div class="hc-step-list">
                                        <?php foreach ($run_steps as $step): ?>
                                            <span class="hc-step-chip <?= $h(Util::statusCssClass((int) ($step['status'] ?? Storage::STATUS_SKIP))) ?>">
                                                <?= $h($step['step_label'] ?? $step['step_key'] ?? '') ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($runs === []): ?>
                        <tr><td colspan="10" class="hc-empty-cell"><?= $h(_('No runs were found in the selected period.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('Healthcheck history'))
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
