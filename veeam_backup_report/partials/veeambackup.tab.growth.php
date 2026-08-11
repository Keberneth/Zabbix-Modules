<?php declare(strict_types = 1);

/**
 * Growth & forecast tab - where the storage is heading.
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\VeeamBackupReport\Helpers\ReportDataHelper $helper
 * @var \Modules\VeeamBackupReport\Helpers\ChartRenderer $chart
 * @var \Modules\VeeamBackupReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 * @var callable $bytes_axis
 * @var callable $gb_axis
 * @var string $metric_label
 */

$growth = $report['growth'];
$forecast = $report['storage_forecast'];
$storage = $report['storage'];

$storage_points = [];
foreach ($report['storage_series']['dates'] as $i => $date) {
    $storage_points[] = ['label' => $date, 'value' => (float) $report['storage_series']['values'][$i]];
}

$forecast_points = [];
foreach ($forecast['dates'] as $i => $date) {
    $forecast_points[] = ['label' => $date, 'value' => (float) $forecast['values'][$i]];
}

// Ranked in the helper over every matching object, not over the truncated
// table, so a fast-growing small object is not hidden by the row limit.
$growers = $report['growers'];
?>

<div class="vr-kpis">
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Growth this period')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatSignedBytes($growth['change'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc($growth['pct'] === null
            ? '—'
            : sprintf(_('%1$s of the starting size'), $helper->formatPct($growth['pct'], 1))) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Growth per month')) ?></div>
        <div class="vr-kpi-value"><?= $esc($helper->formatSignedBytes($growth['per_month'])) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Projected from this period')) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--neutral">
        <div class="vr-kpi-label"><?= $esc(_('Repository growth')) ?></div>
        <div class="vr-kpi-value"><?= $esc($forecast['growth_gb_day'] === null
            ? '—'
            : $helper->formatGb($forecast['growth_gb_day'], 2)) ?></div>
        <div class="vr-kpi-sub"><?= $esc(_('Per day, across all repositories')) ?></div>
    </div>
    <div class="vr-kpi vr-kpi--<?= $forecast['days_to_full'] === null
        ? 'neutral'
        : ($forecast['days_to_full'] <= 30 ? 'critical' : ($forecast['days_to_full'] <= 90 ? 'warning' : 'neutral')) ?>">
        <div class="vr-kpi-label"><?= $esc(_('Storage runs out')) ?></div>
        <?php $forecast_status = (string) ($forecast['status'] ?? 'unavailable'); ?>
        <div class="vr-kpi-value"><?= $esc($forecast_status === 'projected'
            ? date('j M Y', (int) strtotime((string) $forecast['full_date']))
            : ($forecast_status === 'beyond_horizon' ? _('Not within 2 years') : '—')) ?></div>
        <div class="vr-kpi-sub"><?= $esc($forecast_status === 'projected'
            ? sprintf(_('In about %1$d days'), (int) $forecast['days_to_full'])
            : ($forecast_status === 'beyond_horizon'
                ? _('At the current growth rate')
                : _('Not enough data in this period to project'))) ?></div>
    </div>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Repository space used, with projection')) ?></h2>
        <span class="vr-panel-note"><?= $esc(_('Shared disks counted once')) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?php if ($forecast['days_to_full'] !== null): ?>
            <?= $esc(sprintf(
                _('Used space is growing by about %1$s per day. Continuing at that rate, the repositories reach their %2$s capacity around %3$s.'),
                $helper->formatGb($forecast['growth_gb_day'], 2),
                $helper->formatGb($storage['capacity_gb']),
                date('j F Y', (int) strtotime((string) $forecast['full_date']))
            )) ?>
        <?php elseif ($forecast['growth_gb_day'] !== null && $forecast['growth_gb_day'] > 0): ?>
            <?= $esc(sprintf(
                _('Used space is growing by about %1$s per day. At that rate the repositories stay within their %2$s capacity for at least two more years.'),
                $helper->formatGb($forecast['growth_gb_day'], 2),
                $helper->formatGb($storage['capacity_gb'])
            )) ?>
        <?php else: ?>
            <?= $esc(_('The dashed line continues the current trend. It is a straight-line projection, not a promise.')) ?>
        <?php endif; ?>
    </p>
    <div class="vr-chart-scroll">
        <?= $chart->lineChart($storage_points, $forecast_points, [
            'title' => _('Repository space used, with projection'),
            'format' => $gb_axis,
            'scale_base' => 1024.0,
            'token' => '--vr-s1',
            'height' => 320,
            'threshold' => $storage['capacity_gb'],
            'threshold_label' => sprintf(_('Capacity %1$s'), $helper->formatGb($storage['capacity_gb']))
        ]) ?>
    </div>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Protected data by backup type over time')) ?></h2>
        <span class="vr-panel-note"><?= $esc($metric_label) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc($fmt->growthSentence($growth, $metric_label)) ?>
    </p>
    <div class="vr-chart-scroll">
        <?= $chart->stackedTime(
            $report['type_daily']['dates'],
            $report['type_daily']['series'],
            [
                'title' => _('Protected data by backup type over time'),
                'format' => $bytes_axis,
                'scale_base' => 1024.0,
                'height' => 320
            ]
        ) ?>
    </div>
    <?= $chart->legend($report['type_daily']['series']) ?>
</div>

<div class="vr-grid-2">
    <div class="vr-panel">
        <div class="vr-panel-head">
            <h2 class="vr-panel-title"><?= $esc(_('Fastest growing objects')) ?></h2>
        </div>
        <p class="vr-panel-sub">
            <?= $esc(_('The objects that added the most data during this period. These are the first places to look when storage fills faster than expected.')) ?>
        </p>

        <?php if ($growers === []): ?>
            <p class="vr-empty"><?= $esc(_('No object growth data for this period.')) ?></p>
        <?php else: ?>
            <div class="vr-table-wrap">
                <table class="vr-table">
                    <thead>
                        <tr>
                            <th><?= $esc(_('Object')) ?></th>
                            <th><?= $esc(_('Type')) ?></th>
                            <th class="vr-num"><?= $esc(_('Size')) ?></th>
                            <th class="vr-num"><?= $esc(_('Growth')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($growers as $row): ?>
                            <tr>
                                <td class="vr-strong"><?= $esc($row['object']) ?></td>
                                <td><?= $fmt->swatch($row['token']) ?><?= $esc($row['type_label']) ?></td>
                                <td class="vr-num"><?= $esc($helper->formatBytes($row['metric_end'])) ?></td>
                                <td class="vr-num"><?= $fmt->delta($row['metric_change']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="vr-panel">
        <div class="vr-panel-head">
            <h2 class="vr-panel-title"><?= $esc(_('Per Veeam server')) ?></h2>
            <span class="vr-panel-note"><?= $esc($metric_label) ?></span>
        </div>
        <p class="vr-panel-sub">
            <?= $esc(_('How each Veeam server contributed to the total, and how much it changed over the period.')) ?>
        </p>

        <?php if ($report['source_hosts'] === []): ?>
            <p class="vr-empty"><?= $esc(_('No Veeam server data for this period.')) ?></p>
        <?php else: ?>
            <div class="vr-table-wrap">
                <table class="vr-table">
                    <thead>
                        <tr>
                            <th><?= $esc(_('Veeam server')) ?></th>
                            <th><?= $esc(_('Trend')) ?></th>
                            <th class="vr-num"><?= $esc(_('At start')) ?></th>
                            <th class="vr-num"><?= $esc(_('At end')) ?></th>
                            <th class="vr-num"><?= $esc(_('Change')) ?></th>
                            <th class="vr-num"><?= $esc(_('Coverage')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['source_hosts'] as $row): ?>
                            <tr>
                                <td class="vr-strong"><?= $esc($row['host']) ?></td>
                                <td><?= $chart->sparkline($row['spark']) ?></td>
                                <td class="vr-num"><?= $esc($helper->formatBytes($row['metric_start'])) ?></td>
                                <td class="vr-num vr-strong"><?= $esc($helper->formatBytes($row['metric_end'])) ?></td>
                                <td class="vr-num"><?= $fmt->delta($row['metric_change']) ?></td>
                                <td class="vr-num"><?= $esc($helper->formatPct($row['coverage_pct'], 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="vr-hint" style="margin-top:12px">
                <?= $esc(_('Coverage is the share of backup data that Veeam could attribute to a specific protected object. The remainder sits in shared or legacy backup chains.')) ?>
            </p>
        <?php endif; ?>
    </div>
</div>
