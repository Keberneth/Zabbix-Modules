<div class="veeamreport-section">
    <h2 class="veeamreport-section-title"><?php echo $esc(_('Repositories')); ?></h2>
    <p class="veeamreport-section-subtitle">
        <?php echo $esc(_('Per-repository history/trend summary using the selected metric and current repository state fields from the template.')); ?>
    </p>

    <?php if (($report['repositories'] ?? []) === []): ?>
        <p class="veeamreport-empty-note"><?php echo $esc(_('No repository data available.')); ?></p>
    <?php else: ?>
        <table class="list-table">
            <thead>
                <tr>
                    <th><?php echo $esc(_('Veeam host')); ?></th>
                    <th><?php echo $esc(_('Repository')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Start')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('End')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Change')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Average')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Peak')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Days')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Files 31d')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Capacity')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Used')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Free')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Free %')); ?></th>
                    <th><?php echo $esc(_('Online')); ?></th>
                    <th><?php echo $esc(_('Out of date')); ?></th>
                    <th><?php echo $esc(_('Last update')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['repositories'] as $row): ?>
                    <tr>
                        <td><?php echo $esc($row['host']); ?></td>
                        <td><?php echo $esc($row['repository']); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['metric_start'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['metric_end'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['metric_change'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['metric_avg'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['metric_peak'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($row['days']); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatInt($row['files_31d'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatNumber($row['capacity_gb'], 2).' GB'); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatNumber($row['used_gb'], 2).' GB'); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatNumber($row['free_gb'], 2).' GB'); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatPct($row['free_pct'], 2)); ?></td>
                        <td><?php echo $esc($row['online']); ?></td>
                        <td><?php echo $esc($row['out_of_date']); ?></td>
                        <td><?php echo $esc($helper->formatDateTime($row['last_clock'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
