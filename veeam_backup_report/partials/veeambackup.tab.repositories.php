<?php declare(strict_types = 1);

/**
 * Repositories tab.
 *
 * Two views of the same storage: the physical disks (counted once, even when
 * several Veeam servers mount the same one) and the raw per-server rows that
 * Veeam actually reports.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\VeeamBackupReport\Helpers\ReportDataHelper $helper
 * @var \Modules\VeeamBackupReport\Helpers\ChartRenderer $chart
 * @var \Modules\VeeamBackupReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 * @var string $metric_label
 */

$storage = $report['storage'];
?>

<div class="vr-kpis">
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Physical capacity')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatGb($storage['capacity_gb'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc(
            _n('%1$d repository', '%1$d repositories', count($report['repo_groups']))) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= $storage['used_pct'] !== null && $storage['used_pct'] >= 85 ? 'warning' : 'ok' ?>">
        <div class="vr-kpi-label"><?= $esc(_('Used')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatGb($storage['used_gb'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc($storage['used_pct'] === null
            ? '—'
            : sprintf(_('%1$s of capacity'), $helper->formatPct($storage['used_pct'], 1))) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--ok">
        <div class="vr-kpi-label"><?= $esc(_('Free')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatGb($storage['free_gb'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc(sprintf(
            _('%1$d online, %2$d offline'),
            (int) $storage['online'],
            (int) $storage['offline']
        )) ?></div>
    </div>
    <?php if ((int) $storage['shared_count'] > 0): ?>
        <div class="vr-kpi vr-kpi--neutral">
            <div class="vr-kpi-label"><?= $esc(_('Shared disk correction')) ?></div>
            <div class="vr-kpi-value">−<?= $esc($helper->formatGb($storage['dedup_saving_gb'])) ?></div>
            <div class="vr-kpi-sub"><?= $esc(
                _n(
                    '%1$d repository is mounted by several Veeam servers and is counted once',
                    '%1$d repositories are mounted by several Veeam servers and are counted once',
                    (int) $storage['shared_count']
                )) ?></div>
        </div>
    <?php endif; ?>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Physical repositories')) ?></h2>
        <span class="vr-panel-note"><?= $esc(_('Each disk counted once')) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc(_('When several Veeam servers mount the same repository disk, each of them reports its full capacity. Rows here are grouped by repository name, path and size, so the capacity below is the real amount of disk you own. Two servers each holding their own local D:\\Backups stay separate.')) ?>
    </p>

    <?php if ($report['repo_groups'] === []): ?>
        <p class="vr-empty"><?= $esc(_('No repositories matched the filter.')) ?></p>
    <?php else: ?>
        <div class="vr-table-wrap">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Repository')) ?></th>
                        <th><?= $esc(_('Type')) ?></th>
                        <th><?= $esc(_('Mounted by')) ?></th>
                        <th style="width: 22%"><?= $esc(_('Usage')) ?></th>
                        <th class="vr-num"><?= $esc(_('Used')) ?></th>
                        <th class="vr-num"><?= $esc(_('Free')) ?></th>
                        <th class="vr-num"><?= $esc(_('Capacity')) ?></th>
                        <th class="vr-num"><?= $esc(_('Backup files 31d')) ?></th>
                        <th><?= $esc(_('State')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['repo_groups'] as $group): ?>
                        <?php
                        $token = $group['state'] === 'critical' ? '--vr-critical'
                            : ($group['state'] === 'warning' ? '--vr-warning' : '--vr-s1');
                        ?>
                        <tr>
                            <td>
                                <span class="vr-strong"><?= $esc($group['repository']) ?></span>
                                <?php if ($group['path'] !== ''): ?>
                                    <div class="vr-dim" style="font-size:11.5px"><?= $esc($group['path']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $esc($group['repo_type'] !== '' ? $group['repo_type'] : '—') ?></td>
                            <td class="vr-wrap">
                                <?= $esc(implode(', ', $group['hosts'])) ?>
                                <?php if ($group['shared']): ?>
                                    <span class="vr-status vr-status--neutral" style="margin-left:6px">
                                        <?= $esc(_('shared')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $chart->meter($group['used_pct'], [
                                    'token' => $token,
                                    'label' => sprintf(_('%1$s used'), $helper->formatPct($group['used_pct'], 1))
                                ]) ?>
                                <div class="vr-dim" style="font-size:11.5px; margin-top:4px">
                                    <?= $esc($helper->formatPct($group['used_pct'], 1)) ?>
                                </div>
                            </td>
                            <td class="vr-num"><?= $esc($helper->formatGb($group['used_gb'])) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatGb($group['free_gb'])) ?></td>
                            <td class="vr-num vr-strong"><?= $esc($helper->formatGb($group['capacity_gb'])) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatInt($group['files_31d'])) ?></td>
                            <td><?= $fmt->repositoryState(
                                (string) $group['state'],
                                $group['online'] !== false,
                                (bool) $group['out_of_date']
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('As reported by each Veeam server')) ?></h2>
        <span class="vr-panel-note"><?= $esc($metric_label) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc(_('The raw per-server view. A repository mounted by three servers appears three times here - that is Veeam reporting, not an error.')) ?>
    </p>

    <?php if ($report['repositories'] === []): ?>
        <p class="vr-empty"><?= $esc(_('No repository data available for this period.')) ?></p>
    <?php else: ?>
        <div class="vr-table-wrap">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Veeam server')) ?></th>
                        <th><?= $esc(_('Repository')) ?></th>
                        <th><?= $esc(_('Trend')) ?></th>
                        <th class="vr-num"><?= $esc(_('At start')) ?></th>
                        <th class="vr-num"><?= $esc(_('At end')) ?></th>
                        <th class="vr-num"><?= $esc(_('Change')) ?></th>
                        <th class="vr-num"><?= $esc(_('Peak')) ?></th>
                        <th class="vr-num"><?= $esc(_('Capacity')) ?></th>
                        <th class="vr-num"><?= $esc(_('Free %')) ?></th>
                        <th><?= $esc(_('State')) ?></th>
                        <th><?= $esc(_('Updated')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['repositories'] as $row): ?>
                        <tr>
                            <td><?= $esc($row['host']) ?></td>
                            <td class="vr-strong"><?= $esc($row['repository']) ?></td>
                            <td><?= $chart->sparkline($row['spark']) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatBytes($row['metric_start'])) ?></td>
                            <td class="vr-num vr-strong"><?= $esc($helper->formatBytes($row['metric_end'])) ?></td>
                            <td class="vr-num"><?= $fmt->delta($row['metric_change']) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatBytes($row['metric_peak'])) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatGb($row['capacity_gb'])) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatPct($row['free_pct'], 1)) ?></td>
                            <td><?= $fmt->repositoryState(
                                (string) $row['state'],
                                $row['online'] !== false,
                                $row['out_of_date'] === true
                            ) ?></td>
                            <td class="vr-dim"><?= $esc($helper->formatDateTime($row['last_clock'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
