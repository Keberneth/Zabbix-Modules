<?php declare(strict_types = 1);

/**
 * Backup jobs tab - what ran, when it ran, and how it ended.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\VeeamBackupReport\Helpers\ReportDataHelper $helper
 * @var \Modules\VeeamBackupReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 */

$health = $report['job_health'];
?>

<div class="vr-kpis">
    <div class="vr-kpi vr-kpi--<?= (int) $health['failed'] > 0 ? 'critical' : ((int) $health['warning'] > 0 ? 'warning' : 'ok') ?>">
        <div class="vr-kpi-label"><?= $esc(_('Success rate')) ?></div>
        <div class="vr-kpi-value"><?= $esc($health['rate'] === null ? '—' : $helper->formatPct($health['rate'], 1)) ?></div>
        <div class="vr-kpi-sub"><?= $esc(sprintf(_('Across %1$d discovered jobs'), (int) $health['total'])) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= (int) $health['failed'] > 0 ? 'critical' : 'ok' ?>">
        <div class="vr-kpi-label"><?= $esc(_('Failed')) ?></div>
        <div class="vr-kpi-value"><?= $esc((string) (int) $health['failed']) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Last run ended in failure')) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= (int) $health['warning'] > 0 ? 'warning' : 'ok' ?>">
        <div class="vr-kpi-label"><?= $esc(_('Warnings')) ?></div>
        <div class="vr-kpi-value"><?= $esc((string) (int) $health['warning']) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Completed, but not cleanly')) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Succeeded')) ?></div>
        <div class="vr-kpi-value"><?= $esc((string) (int) $health['success']) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Last run completed cleanly')) ?></div>
    </div>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Every backup job')) ?></h2>
        <span class="vr-panel-note"><?= $esc(_('Problems first, then most recently run')) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc(sprintf(
            _('A job is flagged only once its own scheduled next run has come and gone: amber up to %1$d hours late, red beyond that. A monthly job that last ran four days ago is on time, so it stays green. Jobs Veeam reports no schedule for are judged on the age of their last run instead. Adjust the threshold under More filters.'),
            (int) $filter['stale_hours']
        )) ?>
    </p>

    <?php if ($report['jobs'] === []): ?>
        <p class="vr-empty">
            <?= $esc(_('No backup jobs were discovered. Jobs appear once the Veeam template job discovery has run.')) ?>
        </p>
    <?php else: ?>
        <div class="vr-table-wrap">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Job')) ?></th>
                        <th><?= $esc(_('Veeam server')) ?></th>
                        <th><?= $esc(_('Type')) ?></th>
                        <th><?= $esc(_('Workload')) ?></th>
                        <th><?= $esc(_('Last result')) ?></th>
                        <th><?= $esc(_('Last run')) ?></th>
                        <th><?= $esc(_('Next run')) ?></th>
                        <th class="vr-num"><?= $esc(_('Objects')) ?></th>
                        <th><?= $esc(_('State')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['jobs'] as $job): ?>
                        <tr>
                            <td class="vr-strong"><?= $esc($job['job']) ?></td>
                            <td><?= $esc($job['host']) ?></td>
                            <td><?= $esc($job['job_type'] !== '' ? $job['job_type'] : '—') ?></td>
                            <td><?= $esc($job['workload'] !== '' ? $job['workload'] : '—') ?></td>
                            <td><?= $fmt->jobResult($job) ?></td>
                            <td>
                                <?= $fmt->freshness((string) $job['freshness'], $job['age_seconds']) ?>
                                <?php if ($job['last_run_ts'] !== null): ?>
                                    <div class="vr-dim" style="font-size:11.5px; margin-top:3px">
                                        <?= $esc($helper->formatDateTime($job['last_run_ts'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($job['next_run_ts'] !== null): ?>
                                    <?= $esc($helper->formatUntil($job['next_run_ts'])) ?>
                                    <div class="vr-dim" style="font-size:11.5px; margin-top:3px">
                                        <?= $esc($helper->formatDateTime($job['next_run_ts'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="vr-dim"><?= $esc(_('Not scheduled')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="vr-num"><?= $esc($helper->formatInt($job['objects_count'])) ?></td>
                            <td>
                                <?php
                                $status_tone = [
                                    'running' => 'neutral',
                                    'idle' => 'neutral',
                                    'disabled' => 'warning',
                                    'unknown' => 'neutral'
                                ][$job['status_state']] ?? 'neutral';
                                ?>
                                <?= $fmt->pill($status_tone, $job['status'] !== '' ? $job['status'] : _('unknown')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
