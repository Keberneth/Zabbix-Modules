<?php declare(strict_types = 1);

/**
 * Overview tab - the answer to "is my backup healthy, how much data is
 * protected, and am I about to run out of room".
 *
 * @var array $report
 * @var array $filter
 * @var \Modules\VeeamBackupReport\Helpers\ReportDataHelper $helper
 * @var \Modules\VeeamBackupReport\Helpers\ChartRenderer $chart
 * @var \Modules\VeeamBackupReport\Helpers\ViewFormatter $fmt
 * @var callable $esc
 * @var callable $bytes_axis
 * @var string $metric_label
 */

$health = $report['job_health'];
$storage = $report['storage'];
?>

<?php if ($report['cards'] !== []): ?>
    <div class="vr-kpis">
        <?php foreach ($report['cards'] as $card): ?>
            <div class="vr-kpi vr-kpi--<?= $esc($card['tone']) ?>">
                <div class="vr-kpi-label"><?= $esc($card['label']) ?></div>
                <div class="vr-kpi-value"><?= $esc($card['value']) ?></div>
                <div class="vr-kpi-sub"><?= $esc($card['sub']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Backup data written per day')) ?></h2>
        <span class="vr-panel-note"><?= $esc(_('Stacked by Veeam server')) ?></span>
    </div>
    <p class="vr-panel-sub">
        <?= $esc(_('How much new backup data landed on disk each day. Regular dips are normal on weekends; a day at zero means nothing ran.')) ?>
    </p>
    <div class="vr-chart-scroll">
        <?= $chart->stackedTime(
            $report['daily_by_host']['dates'],
            $report['daily_by_host']['series'],
            [
                'title' => _('Backup data written per day, stacked by Veeam server'),
                'format' => $bytes_axis,
                'scale_base' => 1024.0,
                'height' => 300
            ]
        ) ?>
    </div>
    <?= $chart->legend($report['daily_by_host']['series']) ?>
</div>

<div class="vr-grid-2">
    <div class="vr-panel">
        <div class="vr-panel-head">
            <h2 class="vr-panel-title"><?= $esc(_('What is being backed up')) ?></h2>
        </div>
        <p class="vr-panel-sub">
            <?= $esc(_('Protected data split by workload type. Only the types that exist in your environment are shown.')) ?>
        </p>

        <?php if ($report['type_breakdown'] === []): ?>
            <p class="vr-chart-empty"><?= $esc(_('No protected objects matched the filter.')) ?></p>
        <?php else: ?>
            <?php
            // Part-to-whole at a glance stays legible up to six slices; the
            // tail is folded so nothing is hidden, only grouped.
            $slices = [];
            $tail = 0.0;
            $tail_count = 0;
            foreach ($report['type_breakdown'] as $index => $entry) {
                if ($index < 5) {
                    $slices[] = [
                        'label' => $entry['label'],
                        'value' => $entry['bytes'],
                        'text' => $helper->formatBytes($entry['bytes']).' · '.$helper->formatPct($entry['pct'], 1),
                        'token' => $entry['token']
                    ];
                }
                else {
                    $tail += (float) $entry['bytes'];
                    $tail_count++;
                }
            }
            if ($tail_count > 0) {
                $slices[] = [
                    'label' => _n('%1$d other type', '%1$d other types', $tail_count),
                    'value' => $tail,
                    'text' => $helper->formatBytes($tail),
                    'token' => '--vr-s8'
                ];
            }

            $total_bytes = 0.0;
            foreach ($report['type_breakdown'] as $entry) {
                $total_bytes += (float) $entry['bytes'];
            }
            ?>
            <div class="vr-split">
                <div>
                    <?= $chart->donut(
                        $slices,
                        $helper->formatBytes($total_bytes),
                        _('protected'),
                        ['title' => _('Protected data by workload type')]
                    ) ?>
                </div>
                <div class="vr-table-wrap">
                    <table class="vr-table">
                        <thead>
                            <tr>
                                <th><?= $esc(_('Type')) ?></th>
                                <th class="vr-num"><?= $esc(_('Objects')) ?></th>
                                <th class="vr-num"><?= $esc(_('Size')) ?></th>
                                <th class="vr-num"><?= $esc(_('Share')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['type_breakdown'] as $entry): ?>
                                <tr>
                                    <td><?= $fmt->swatch($entry['token']) ?><?= $esc($entry['label']) ?></td>
                                    <td class="vr-num"><?= $esc($helper->formatInt((float) $entry['objects'])) ?></td>
                                    <td class="vr-num vr-strong"><?= $esc($helper->formatBytes($entry['bytes'])) ?></td>
                                    <td class="vr-num"><?= $esc($helper->formatPct($entry['pct'], 1)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="vr-panel">
        <div class="vr-panel-head">
            <h2 class="vr-panel-title"><?= $esc(_('Backup job results')) ?></h2>
        </div>
        <p class="vr-panel-sub">
            <?= $esc(_('The outcome of the last run of every discovered job across the selected Veeam servers.')) ?>
        </p>

        <?php if ((int) $health['total'] === 0): ?>
            <p class="vr-chart-empty"><?= $esc(_('No backup jobs were discovered.')) ?></p>
        <?php else: ?>
            <?php
            $job_slices = [];
            $job_states = [
                ['success', _('Success'), '--vr-ok'],
                ['warning', _('Warning'), '--vr-warning'],
                ['failed', _('Failed'), '--vr-critical'],
                ['none', _('Not run yet'), '--vr-axis']
            ];
            foreach ($job_states as [$key, $label, $token]) {
                if ((int) $health[$key] > 0) {
                    $job_slices[] = [
                        'label' => $label,
                        'value' => (float) $health[$key],
                        'text' => _n('%1$d job', '%1$d jobs', (int) $health[$key]),
                        'token' => $token
                    ];
                }
            }
            ?>
            <div class="vr-split">
                <div>
                    <?= $chart->donut(
                        $job_slices,
                        $health['rate'] === null ? '—' : $helper->formatPct($health['rate'], 0),
                        _('success rate'),
                        ['title' => _('Backup job results')]
                    ) ?>
                </div>
                <div class="vr-table-wrap">
                    <table class="vr-table">
                        <thead>
                            <tr>
                                <th><?= $esc(_('Result')) ?></th>
                                <th class="vr-num"><?= $esc(_('Jobs')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($job_states as [$key, $label, $token]): ?>
                                <tr>
                                    <td><?= $fmt->swatch($token) ?><?= $esc($label) ?></td>
                                    <td class="vr-num vr-strong"><?= $esc((string) (int) $health[$key]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td class="vr-strong"><?= $esc(_('Total')) ?></td>
                                <td class="vr-num vr-strong"><?= $esc((string) (int) $health['total']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="vr-panel">
    <div class="vr-panel-head">
        <h2 class="vr-panel-title"><?= $esc(_('Repository capacity')) ?></h2>
        <span class="vr-panel-note">
            <?= $esc(sprintf(
                _('%1$s of %2$s used'),
                $helper->formatGb($storage['used_gb']),
                $helper->formatGb($storage['capacity_gb'])
            )) ?>
        </span>
    </div>
    <p class="vr-panel-sub">
        <?php if ((int) $storage['shared_count'] > 0): ?>
            <?= $esc(sprintf(
                (int) $storage['shared_count'] === 1
                    ? _('Physical storage, counted once. %1$d repository is mounted by more than one Veeam server, so the %2$s those servers report between them is really %3$s of disk.')
                    : _('Physical storage, counted once. %1$d repositories are mounted by more than one Veeam server, so the %2$s those servers report between them is really %3$s of disk.'),
                (int) $storage['shared_count'],
                $helper->formatGb($storage['reported_capacity_gb']),
                $helper->formatGb($storage['capacity_gb'])
            )) ?>
        <?php else: ?>
            <?= $esc(_('Current capacity of every backup repository behind the selected Veeam servers.')) ?>
        <?php endif; ?>
    </p>

    <?php if ($report['repo_groups'] === []): ?>
        <p class="vr-chart-empty"><?= $esc(_('No repositories matched the filter.')) ?></p>
    <?php else: ?>
        <div class="vr-table-wrap">
            <table class="vr-table">
                <thead>
                    <tr>
                        <th><?= $esc(_('Repository')) ?></th>
                        <th style="width: 30%"><?= $esc(_('Usage')) ?></th>
                        <th class="vr-num"><?= $esc(_('Used')) ?></th>
                        <th class="vr-num"><?= $esc(_('Free')) ?></th>
                        <th class="vr-num"><?= $esc(_('Capacity')) ?></th>
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
                                <?php if ($group['shared']): ?>
                                    <span class="vr-status vr-status--neutral" style="margin-left:8px">
                                        <?= $esc(sprintf(_('shared by %1$d servers'), count($group['hosts']))) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($group['path'] !== ''): ?>
                                    <div class="vr-dim" style="font-size:11.5px"><?= $esc($group['path']) ?></div>
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
                            <td class="vr-num"><?= $esc($helper->formatGb($group['capacity_gb'])) ?></td>
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
        <h2 class="vr-panel-title"><?= $esc(_('Needs attention')) ?></h2>
        <?php if ($report['attention'] !== []): ?>
            <span class="vr-panel-note"><?= $esc(
                _n('%1$d item', '%1$d items', count($report['attention']))) ?></span>
        <?php endif; ?>
    </div>

    <?php
        // An empty attention list only means "healthy" when there was something
        // to inspect. With no jobs, objects or repositories at all, a green
        // all-clear would claim a result the report never actually checked.
        $has_subject = $report['jobs'] !== [] || $report['objects'] !== [] || $report['repo_groups'] !== [];
    ?>
    <?php if ($report['attention'] === [] && !$has_subject): ?>
        <p class="vr-empty">
            <?= $esc(_('Nothing to check yet. No backup jobs, protected objects or repositories were found for this period.')) ?>
        </p>
    <?php elseif ($report['attention'] === []): ?>
        <div class="vr-all-clear">
            <span aria-hidden="true">●</span>
            <span><?= $esc(_('Everything looks healthy. No failed jobs, offline repositories or overdue backups.')) ?></span>
        </div>
    <?php else: ?>
        <div class="vr-attention">
            <?php foreach (array_slice($report['attention'], 0, 12) as $item): ?>
                <div class="vr-attention-item vr-attention-item--<?= $esc($item['severity']) ?>">
                    <span class="vr-attention-bar" aria-hidden="true"></span>
                    <div class="vr-attention-body">
                        <div class="vr-attention-title"><?= $esc($item['title']) ?></div>
                        <div class="vr-attention-detail"><?= $esc($item['detail']) ?></div>
                    </div>
                    <span class="vr-attention-scope"><?= $esc($item['scope']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($report['attention']) > 12): ?>
            <p class="vr-hint" style="margin-top:12px">
                <?= $esc(sprintf(
                    _('%1$d more items are listed on the Backup jobs and Protected objects tabs.'),
                    count($report['attention']) - 12
                )) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
