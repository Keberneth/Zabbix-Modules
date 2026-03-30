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
$latest_runs = is_array($data['latest_runs'] ?? null) ? $data['latest_runs'] : [];
$selected_check_id = (string) ($data['selected_check_id'] ?? '');
$selected_run = $data['selected_run'] ?? null;
$selected_steps = is_array($data['selected_steps'] ?? null) ? $data['selected_steps'] : [];
$selected_recent_runs = is_array($data['selected_recent_runs'] ?? null) ? $data['selected_recent_runs'] : [];
$recent_failures = is_array($data['recent_failures'] ?? null) ? $data['recent_failures'] : [];
$is_super_admin = !empty($data['is_super_admin']);

$heartbeat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.heartbeat')
    ->getUrl();

$history_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.history')
    ->getUrl();

$settings_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.settings')
    ->getUrl();

$run_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'healthcheck.run')
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
<div id="healthcheck-heartbeat-root" class="hc-page hc-heartbeat-page" data-healthcheck-theme="<?= $h($hc_theme) ?>" data-run-url="<?= $h($run_url) ?>" data-run-csrf-name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" data-run-csrf-token="<?= $h(CCsrfTokenHelper::get('healthcheck.run')) ?>">
    <div class="hc-header">
        <div>
            <h1><?= $h($data['title'] ?? _('Healthcheck heartbeat')) ?></h1>
            <p class="hc-muted">
                Latest health state per configured check, with the most recent step-level details and recent failures.
            </p>
        </div>
        <div class="hc-header-actions">
            <?php if ($is_super_admin): ?>
                <button type="button" class="btn-alt hc-run-button" data-checkid="" data-force="1"><?= $h(_('Run all now')) ?></button>
            <?php endif; ?>
            <a class="btn-alt" href="<?= $h($history_url) ?>"><?= $h(_('History')) ?></a>
            <?php if ($is_super_admin): ?>
                <a class="btn-alt" href="<?= $h($settings_url) ?>"><?= $h(_('Settings')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <section class="hc-card">
        <h2><?= $h(_('Current status')) ?></h2>
        <?php if ($checks === []): ?>
            <p class="hc-empty-state"><?= $h(_('No checks are configured yet. Open Settings to create the first health check.')) ?></p>
        <?php else: ?>
            <div class="hc-metrics-grid">
                <?php foreach ($checks as $check): ?>
                    <?php
                    $check_id = (string) ($check['id'] ?? '');
                    $latest = $latest_runs[$check_id] ?? null;
                    $next_due = $latest !== null
                        ? ((int) ($latest['finished_at'] ?? 0) + (int) ($check['interval_seconds'] ?? 300))
                        : time();
                    ?>
                    <div class="hc-metric-card">
                        <div class="hc-metric-header">
                            <h3><?= $h($check['name'] ?? $check_id) ?></h3>
                            <?= $latest !== null ? $status_badge($latest['status'] ?? Storage::STATUS_SKIP) : $status_badge(Storage::STATUS_SKIP) ?>
                        </div>
                        <dl class="hc-definition-list">
                            <div><dt><?= $h(_('Last run')) ?></dt><dd><?= $h($latest !== null ? Util::formatTimestamp($latest['finished_at'] ?? 0) : _('Never')) ?></dd></div>
                            <div><dt><?= $h(_('Next due')) ?></dt><dd><?= $h(Util::formatTimestamp($next_due)) ?></dd></div>
                            <div><dt><?= $h(_('Duration')) ?></dt><dd><?= $h($latest !== null ? Util::formatDurationMs($latest['duration_ms'] ?? null) : '—') ?></dd></div>
                            <div><dt><?= $h(_('Summary')) ?></dt><dd><?= $h($latest['summary'] ?? _('No run recorded yet.')) ?></dd></div>
                        </dl>
                        <div class="hc-card-actions">
                            <a class="btn-alt" href="<?= $h((new CUrl('zabbix.php'))->setArgument('action', 'healthcheck.heartbeat')->setArgument('checkid', $check_id)->getUrl()) ?>"><?= $h(_('View details')) ?></a>
                            <?php if ($is_super_admin): ?>
                                <button type="button" class="btn-alt hc-run-button" data-checkid="<?= $h($check_id) ?>" data-force="1"><?= $h(_('Run now')) ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($checks !== []): ?>
        <section class="hc-card">
            <div class="hc-toolbar">
                <div>
                    <h2><?= $h(_('Latest run details')) ?></h2>
                </div>
                <form class="hc-inline-form" method="get" action="<?= $h($heartbeat_url) ?>">
                    <input type="hidden" name="action" value="healthcheck.heartbeat">
                    <label class="hc-label-inline">
                        <?= $h(_('Check')) ?>
                        <select class="hc-input" name="checkid" onchange="this.form.submit()">
                            <?php foreach ($checks as $check): ?>
                                <?php $check_id = (string) ($check['id'] ?? ''); ?>
                                <option value="<?= $h($check_id) ?>" <?= $selected_check_id === $check_id ? 'selected' : '' ?>><?= $h($check['name'] ?? $check_id) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>

            <?php if ($selected_run === null): ?>
                <p class="hc-empty-state"><?= $h(_('This check has not run yet.')) ?></p>
            <?php else: ?>
                <div class="hc-summary-line">
                    <span><?= $status_badge($selected_run['status'] ?? Storage::STATUS_SKIP) ?></span>
                    <span><?= $h(_('Started:')).' '.$h(Util::formatTimestamp($selected_run['started_at'] ?? 0)) ?></span>
                    <span><?= $h(_('Finished:')).' '.$h(Util::formatTimestamp($selected_run['finished_at'] ?? 0)) ?></span>
                    <span><?= $h(_('Duration:')).' '.$h(Util::formatDurationMs($selected_run['duration_ms'] ?? null)) ?></span>
                </div>

                <div class="hc-table-wrap">
                    <table class="hc-table">
                        <thead>
                            <tr>
                                <th><?= $h(_('Step')) ?></th>
                                <th><?= $h(_('Status')) ?></th>
                                <th><?= $h(_('Duration')) ?></th>
                                <th><?= $h(_('Metric')) ?></th>
                                <th><?= $h(_('Details')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selected_steps as $step): ?>
                                <tr>
                                    <td><?= $h($step['step_label'] ?? '') ?></td>
                                    <td><?= $status_badge($step['status'] ?? Storage::STATUS_SKIP) ?></td>
                                    <td><?= $h(Util::formatDurationMs($step['duration_ms'] ?? null)) ?></td>
                                    <td><?= $h($step['metric_value'] ?? '—') ?></td>
                                    <td><?= $h($step['detail_text'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($selected_steps === []): ?>
                                <tr><td colspan="5" class="hc-empty-cell"><?= $h(_('No step details are stored for this run.')) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="hc-card">
            <h2><?= $h(_('Recent runs for selected check')) ?></h2>
            <div class="hc-table-wrap">
                <table class="hc-table">
                    <thead>
                        <tr>
                            <th><?= $h(_('Time')) ?></th>
                            <th><?= $h(_('Status')) ?></th>
                            <th><?= $h(_('Duration')) ?></th>
                            <th><?= $h(_('Freshest age')) ?></th>
                            <th><?= $h(_('Ping latency')) ?></th>
                            <th><?= $h(_('Summary')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selected_recent_runs as $run): ?>
                            <tr>
                                <td><?= $h(Util::formatTimestamp($run['finished_at'] ?? 0)) ?></td>
                                <td><?= $status_badge($run['status'] ?? Storage::STATUS_SKIP) ?></td>
                                <td><?= $h(Util::formatDurationMs($run['duration_ms'] ?? null)) ?></td>
                                <td><?= $h(isset($run['freshest_age_sec']) ? Util::formatAge($run['freshest_age_sec']) : '—') ?></td>
                                <td><?= $h(isset($run['ping_latency_ms']) ? Util::formatDurationMs($run['ping_latency_ms']) : '—') ?></td>
                                <td><?= $h($run['summary'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($selected_recent_runs === []): ?>
                            <tr><td colspan="6" class="hc-empty-cell"><?= $h(_('No runs recorded yet.')) ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="hc-card">
        <h2><?= $h(_('Recent failures')) ?></h2>
        <div class="hc-table-wrap">
            <table class="hc-table">
                <thead>
                    <tr>
                        <th><?= $h(_('Check')) ?></th>
                        <th><?= $h(_('Time')) ?></th>
                        <th><?= $h(_('Duration')) ?></th>
                        <th><?= $h(_('Summary')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_failures as $failure): ?>
                        <tr>
                            <td><?= $h($failure['check_name'] ?? $failure['checkid'] ?? '') ?></td>
                            <td><?= $h(Util::formatTimestamp($failure['finished_at'] ?? 0)) ?></td>
                            <td><?= $h(Util::formatDurationMs($failure['duration_ms'] ?? null)) ?></td>
                            <td><?= $h($failure['summary'] ?? $failure['error_text'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($recent_failures === []): ?>
                        <tr><td colspan="4" class="hc-empty-cell"><?= $h(_('No failed runs are recorded yet.')) ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('Healthcheck heartbeat'))
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
