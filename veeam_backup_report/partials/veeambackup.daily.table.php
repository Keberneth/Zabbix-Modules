<div class="veeamreport-section">
    <h2 class="veeamreport-section-title"><?php echo $esc(_('Daily totals')); ?></h2>
    <p class="veeamreport-section-subtitle">
        <?php echo $esc(_('Combined daily values from veeam.backup.total.* items on the selected Veeam hosts.')); ?>
    </p>

    <?php if (($report['daily'] ?? []) === []): ?>
        <p class="veeamreport-empty-note"><?php echo $esc(_('No daily totals available for the selected period.')); ?></p>
    <?php else: ?>
        <div class="table-forms-separator"></div>
        <table class="list-table">
            <thead>
                <tr>
                    <th><?php echo $esc(_('Date')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Total 24h')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Total 31d')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Assigned 31d')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Shared 31d')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Coverage')); ?></th>
                    <th class="veeamreport-num"><?php echo $esc(_('Hosts with data')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['daily'] as $row): ?>
                    <tr>
                        <td><?php echo $esc($row['date']); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['size24h'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['size31d'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['assigned31d'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatBytes($row['shared31d'])); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($helper->formatPct($row['coverage_pct'], 2)); ?></td>
                        <td class="veeamreport-num"><?php echo $esc($row['hosts_with_data']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
