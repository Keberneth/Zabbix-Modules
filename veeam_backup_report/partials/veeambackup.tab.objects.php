<?php declare(strict_types = 1);

/**
 * Protected objects tab - every VM, database, agent and file share, and when
 * each was last backed up.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\VeeamBackupReport\Helpers\ReportDataHelper $helper
 * @var \Modules\VeeamBackupReport\Helpers\ChartRenderer $chart
 * @var \Modules\VeeamBackupReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 * @var string $metric_label
 */

// Counted over every matching object, not the truncated page - an overdue
// object at position 101 is exactly the one worth surfacing.
$stale = (int) ($report['object_health']['overdue'] ?? 0);
$never = (int) ($report['object_health']['unknown'] ?? 0);
$with_data = (int) ($report['objects_with_data'] ?? 0);
?>

<div class="vr-kpis">
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Protected objects')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatInt((float) $with_data)) ?></div>
        <div class="vr-kpi-sub"><?= $esc($with_data === (int) $report['objects_filtered']
            ? sprintf(_('%1$d discovered in total'), (int) $report['objects_total'])
            : sprintf(
                _('%1$d match the filter, %2$d have data in this period'),
                (int) $report['objects_filtered'],
                $with_data
            )) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= $stale > 0 ? 'warning' : 'ok' ?>">
        <div class="vr-kpi-label"><?= $esc(_('Overdue backups')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatInt((float) $stale)) ?></div>
        <div class="vr-kpi-sub"><?= $esc(sprintf(
            _('No restore point for over %1$d h'),
            (int) $filter['stale_hours']
        )) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= $never > 0 ? 'warning' : 'ok' ?>">
        <div class="vr-kpi-label"><?= $esc(_('No backup time reported')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatInt((float) $never)) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Veeam did not return a restore point')) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Workload types')) ?></div>
        <div class="vr-kpi-value"><?= $esc((string) count($report['type_breakdown'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Present in the current selection')) ?></div>
    </div>
</div>

<?php if ($report['type_breakdown'] !== []): ?>
    <div class="vr-panel">
        <div class="vr-panel-head">
            <h2 class="vr-panel-title"><?= $esc(_('Protected data by backup type')) ?></h2>
            <span class="vr-panel-note"><?= $esc($metric_label) ?></span>
        </div>
        <p class="vr-panel-sub">
            <?= $esc(_('Totals for each workload type in the current selection. Use the Backup type filter above to focus on one of them.')) ?>
        </p>
        <?= $chart->hBars(
            array_map(static function(array $entry) use ($helper): array {
                return [
                    'label' => $entry['label'],
                    'value' => (float) $entry['bytes'],
                    'text' => $helper->formatBytes($entry['bytes']),
                    'token' => $entry['token']
                ];
            }, $report['type_breakdown']),
            ['title' => _('Protected data by backup type'), 'max_rows' => 12]
        ) ?>

        <div class="vr-table-wrap" style="margin-top:16px">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Backup type')) ?></th>
                        <th class="vr-num"><?= $esc(_('Objects')) ?></th>
                        <th class="vr-num"><?= $esc(_('Total size')) ?></th>
                        <th class="vr-num"><?= $esc(_('Share')) ?></th>
                        <th class="vr-num"><?= $esc(_('Change in period')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['type_breakdown'] as $entry): ?>
                        <tr>
                            <td><?= $fmt->swatch($entry['token']) ?><span class="vr-strong"><?= $esc($entry['label']) ?></span></td>
                            <td class="vr-num"><?= $esc($helper->formatInt((float) $entry['objects'])) ?></td>
                            <td class="vr-num vr-strong"><?= $esc($helper->formatBytes($entry['bytes'])) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatPct($entry['pct'], 1)) ?></td>
                            <td class="vr-num"><?= $fmt->delta($entry['change']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Protected objects')) ?></h2>
        <span class="vr-panel-note"><?= $esc(sprintf(
            _('Showing %1$d of %2$d with data in this period'),
            (int) $report['objects_shown'],
            $with_data
        )) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc(_('Largest first. "Last backup" is the newest restore point Veeam reported for the object.')) ?>
    </p>

    <?php if ($report['objects'] === []): ?>
        <p class="vr-empty"><?= $esc(_('No protected objects matched the current filter.')) ?></p>
    <?php else: ?>
        <div class="vr-table-wrap">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Object')) ?></th>
                        <th><?= $esc(_('Backup type')) ?></th>
                        <th><?= $esc(_('Veeam server')) ?></th>
                        <th><?= $esc(_('Last backup')) ?></th>
                        <th><?= $esc(_('Trend')) ?></th>
                        <th class="vr-num"><?= $esc(_('Size')) ?></th>
                        <th class="vr-num"><?= $esc(_('Change')) ?></th>
                        <th class="vr-num"><?= $esc(_('Restore points')) ?></th>
                        <th class="vr-wrap"><?= $esc(_('Repositories')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['objects'] as $row): ?>
                        <tr>
                            <td class="vr-strong"><?= $esc($row['object']) ?></td>
                            <td><?= $fmt->swatch($row['token']) ?><?= $esc($row['type_label']) ?></td>
                            <td><?= $esc($row['host']) ?></td>
                            <td>
                                <?= $fmt->freshness((string) $row['freshness'], $row['age_seconds']) ?>
                                <?php if ($row['last_backup_ts'] !== null): ?>
                                    <div class="vr-dim" style="font-size:11.5px; margin-top:3px">
                                        <?= $esc($helper->formatDateTime($row['last_backup_ts'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= $chart->sparkline($row['spark'], $row['token']) ?></td>
                            <td class="vr-num vr-strong"><?= $esc($helper->formatBytes($row['metric_end'])) ?></td>
                            <td class="vr-num"><?= $fmt->delta($row['metric_change']) ?></td>
                            <td class="vr-num"><?= $esc($helper->formatInt($row['restorepoints_31d'])) ?></td>
                            <td class="vr-wrap vr-dim"><?= $esc($row['repositories'] !== '' ? $row['repositories'] : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
