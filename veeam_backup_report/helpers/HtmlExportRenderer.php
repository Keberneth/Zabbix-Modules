<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Helpers;

/**
 * The presentation-ready export: one self-contained HTML file with inline CSS
 * and inline SVG, no external requests, that opens anywhere and prints to A4.
 *
 * It is written for two audiences at once. A manager should be able to read the
 * verdict, the headline numbers and the plain-language sentences under each
 * chart and stop there; a backup administrator gets the full tables below.
 */
class HtmlExportRenderer {

    private ReportDataHelper $helper;
    private ChartRenderer $chart;
    private ViewFormatter $fmt;

    public function __construct(ReportDataHelper $helper) {
        $this->helper = $helper;
        $this->chart = new ChartRenderer();
        $this->fmt = new ViewFormatter($helper);
    }

    public function render(array $filter, array $report, int $time_from, int $time_to): string {
        $h = fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $helper = $this->helper;
        $chart = $this->chart;
        $fmt = $this->fmt;

        $metric_label = $helper->getMetricLabel((string) $filter['metric']);
        $bytes_axis = static fn($value, int $decimals = 0, $reference = null): string
    => $helper->formatBytesScaled($value, $decimals, $reference);
        $gb_axis = static fn($value, int $decimals = 0, $reference = null): string
    => $helper->formatGbScaled($value, $decimals, $reference);

        $verdict = $this->verdict($report);
        $storage = $report['storage'];
        $health = $report['job_health'];
        $growth = $report['growth'];
        $forecast = $report['storage_forecast'];

        $server_names = [];
        foreach ($report['source_hosts'] as $row) {
            $server_names[] = (string) $row['host'];
        }
        if ($server_names === []) {
            foreach ($report['host_options'] as $host) {
                if (in_array((string) $host['hostid'], array_map('strval', $report['selected_hostids']), true)) {
                    $server_names[] = (string) $host['name'];
                }
            }
        }

        $storage_points = [];
        foreach ($report['storage_series']['dates'] as $i => $date) {
            $storage_points[] = ['label' => $date, 'value' => (float) $report['storage_series']['values'][$i]];
        }
        $forecast_points = [];
        foreach ($forecast['dates'] as $i => $date) {
            $forecast_points[] = ['label' => $date, 'value' => (float) $forecast['values'][$i]];
        }

        $type_total = 0.0;
        foreach ($report['type_breakdown'] as $entry) {
            $type_total += (float) $entry['bytes'];
        }

        $job_states = [
            ['success', _('Success'), '--vr-ok'],
            ['warning', _('Warning'), '--vr-warning'],
            ['failed', _('Failed'), '--vr-critical'],
            ['none', _('Not run yet'), '--vr-axis']
        ];
        $job_slices = [];
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

        $problem_jobs = array_values(array_filter(
            $report['jobs'],
            static fn(array $job): bool => in_array($job['result_state'], ['failed', 'warning'], true)
        ));

        // From the untruncated health summary, not the truncated table: an
        // overdue object outside the row limit is exactly what a customer
        // report must not omit.
        $overdue = array_merge(
            (array) ($report['object_health']['critical'] ?? []),
            (array) ($report['object_health']['warning'] ?? [])
        );

        ob_start();
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(_('Veeam Backup Report')) ?> — <?= $h($report['period']['label']) ?></title>
<style><?= $this->styles() ?></style>
</head>
<body>
<div class="veeamreport">

<header class="cover">
    <div class="cover-main">
        <p class="cover-eyebrow"><?= $h(_('Backup report')) ?></p>
        <h1><?= $h(_('Veeam Backup & Replication')) ?></h1>
        <p class="cover-period"><?= $h($report['period']['label']) ?></p>
        <p class="cover-servers"><?= $h($server_names === []
            ? _('No Veeam servers selected')
            : implode(' · ', $server_names)) ?></p>
    </div>
    <div class="cover-verdict verdict--<?= $h($verdict['tone']) ?>">
        <div class="verdict-word"><?= $h($verdict['word']) ?></div>
        <div class="verdict-line"><?= $h($verdict['line']) ?></div>
    </div>
</header>

<section class="section">
    <h2><?= $h(_('At a glance')) ?></h2>
    <div class="kpis">
        <?php foreach ($report['cards'] as $card): ?>
            <div class="kpi kpi--<?= $h($card['tone']) ?>">
                <div class="kpi-label"><?= $h($card['label']) ?></div>
                <div class="kpi-value"><?= $h($card['value']) ?></div>
                <div class="kpi-sub"><?= $h($card['sub']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <h2><?= $h(_('How much data is being backed up')) ?></h2>
    <p class="lede">
        <?= $h(_('Each column is one day of new backup data written to disk, stacked by Veeam server. Weekend dips are normal; a gap means nothing ran that day.')) ?>
    </p>
    <div class="card">
        <?= $chart->stackedTime($report['daily_by_host']['dates'], $report['daily_by_host']['series'], [
            'title' => _('Backup data written per day'),
            'format' => $bytes_axis,
            'scale_base' => 1024.0,
            'height' => 300
        ]) ?>
        <?= $chart->legend($report['daily_by_host']['series']) ?>
    </div>
    <p class="takeaway"><?= $h($fmt->growthSentence($growth, $metric_label)) ?></p>
</section>

<?php if ($report['type_breakdown'] !== []): ?>
<section class="section">
    <h2><?= $h(_('What is being protected')) ?></h2>
    <p class="lede">
        <?= $h(sprintf(
            _('%1$s of protected data across %2$d objects, split by workload type.'),
            $helper->formatBytes($type_total),
            (int) $report['objects_filtered']
        )) ?>
    </p>
    <div class="card">
        <?= $chart->hBars(
            array_map(static function(array $entry) use ($helper): array {
                return [
                    'label' => $entry['label'],
                    'value' => (float) $entry['bytes'],
                    'text' => $helper->formatBytes($entry['bytes']).'  ·  '.$helper->formatPct($entry['pct'], 1),
                    'token' => $entry['token']
                ];
            }, $report['type_breakdown']),
            ['title' => _('Protected data by backup type'), 'max_rows' => 12]
        ) ?>
    </div>
    <table>
        <thead>
            <tr>
                <th><?= $h(_('Backup type')) ?></th>
                <th class="num"><?= $h(_('Objects')) ?></th>
                <th class="num"><?= $h(_('Total size')) ?></th>
                <th class="num"><?= $h(_('Share')) ?></th>
                <th class="num"><?= $h(_('Change in period')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report['type_breakdown'] as $entry): ?>
                <tr>
                    <td><?= $fmt->swatch($entry['token']) ?><strong><?= $h($entry['label']) ?></strong></td>
                    <td class="num"><?= $h($helper->formatInt((float) $entry['objects'])) ?></td>
                    <td class="num"><strong><?= $h($helper->formatBytes($entry['bytes'])) ?></strong></td>
                    <td class="num"><?= $h($helper->formatPct($entry['pct'], 1)) ?></td>
                    <td class="num"><?= $fmt->delta($entry['change']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="section">
    <h2><?= $h(_('Did the backups run')) ?></h2>
    <p class="lede">
        <?= $h($health['total'] === 0
            ? _('No backup jobs were discovered on the selected Veeam servers.')
            : sprintf(
                _('%1$d of %2$d backup jobs completed cleanly on their last run.'),
                (int) $health['success'],
                (int) $health['total']
            )) ?>
    </p>
    <?php if ($job_slices !== []): ?>
        <div class="card split">
            <div><?= $chart->donut(
                $job_slices,
                $health['rate'] === null ? '—' : $helper->formatPct($health['rate'], 0),
                _('success rate'),
                ['title' => _('Backup job results')]
            ) ?></div>
            <table class="tight">
                <thead>
                    <tr><th><?= $h(_('Result')) ?></th><th class="num"><?= $h(_('Jobs')) ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($job_states as [$key, $label, $token]): ?>
                        <tr>
                            <td><?= $fmt->swatch($token) ?><?= $h($label) ?></td>
                            <td class="num"><strong><?= $h((string) (int) $health[$key]) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($problem_jobs !== []): ?>
        <h3><?= $h(_('Jobs that did not finish cleanly')) ?></h3>
        <table>
            <thead>
                <tr>
                    <th><?= $h(_('Job')) ?></th>
                    <th><?= $h(_('Veeam server')) ?></th>
                    <th><?= $h(_('Result')) ?></th>
                    <th><?= $h(_('Last run')) ?></th>
                    <th class="num"><?= $h(_('Objects')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($problem_jobs as $job): ?>
                    <tr>
                        <td><strong><?= $h($job['job']) ?></strong></td>
                        <td><?= $h($job['host']) ?></td>
                        <td><?= $fmt->jobResult($job) ?></td>
                        <td><?= $h($job['last_run_ts'] !== null
                            ? $helper->formatDateTime($job['last_run_ts']).' ('.$helper->formatAge($job['age_seconds']).')'
                            : '—') ?></td>
                        <td class="num"><?= $h($helper->formatInt($job['objects_count'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="takeaway takeaway--good"><?= $h(_('Every discovered backup job completed successfully on its last run.')) ?></p>
    <?php endif; ?>
</section>

<section class="section">
    <h2><?= $h(_('Is there room to keep going')) ?></h2>
    <p class="lede">
        <?php if ((int) $storage['shared_count'] > 0): ?>
            <?= $h(sprintf(
                (int) $storage['shared_count'] === 1
                    ? _('%1$s of physical repository storage, %2$s of it in use. The Veeam servers report %3$s between them because %4$d repository is mounted by more than one of them; it is counted once here.')
                    : _('%1$s of physical repository storage, %2$s of it in use. The Veeam servers report %3$s between them because %4$d repositories are mounted by more than one of them; they are counted once here.'),
                $helper->formatGb($storage['capacity_gb']),
                $helper->formatGb($storage['used_gb']),
                $helper->formatGb($storage['reported_capacity_gb']),
                (int) $storage['shared_count']
            )) ?>
        <?php else: ?>
            <?= $h(sprintf(
                _('%1$s of repository storage, %2$s of it in use.'),
                $helper->formatGb($storage['capacity_gb']),
                $helper->formatGb($storage['used_gb'])
            )) ?>
        <?php endif; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th><?= $h(_('Repository')) ?></th>
                <th><?= $h(_('Mounted by')) ?></th>
                <th class="meter-col"><?= $h(_('Usage')) ?></th>
                <th class="num"><?= $h(_('Used')) ?></th>
                <th class="num"><?= $h(_('Free')) ?></th>
                <th class="num"><?= $h(_('Capacity')) ?></th>
                <th><?= $h(_('State')) ?></th>
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
                        <strong><?= $h($group['repository']) ?></strong>
                        <?php if ($group['path'] !== ''): ?>
                            <div class="dim"><?= $h($group['path']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="wrap"><?= $h(implode(', ', $group['hosts'])) ?></td>
                    <td>
                        <?= $chart->meter($group['used_pct'], [
                            'token' => $token,
                            'label' => sprintf(_('%1$s used'), $helper->formatPct($group['used_pct'], 1))
                        ]) ?>
                        <div class="dim"><?= $h($helper->formatPct($group['used_pct'], 1)) ?></div>
                    </td>
                    <td class="num"><?= $h($helper->formatGb($group['used_gb'])) ?></td>
                    <td class="num"><?= $h($helper->formatGb($group['free_gb'])) ?></td>
                    <td class="num"><strong><?= $h($helper->formatGb($group['capacity_gb'])) ?></strong></td>
                    <td><?= $fmt->repositoryState(
                        (string) $group['state'],
                        $group['online'] !== false,
                        (bool) $group['out_of_date']
                    ) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($storage_points !== []): ?>
        <div class="card">
            <?= $chart->lineChart($storage_points, $forecast_points, [
                'title' => _('Repository space used, with projection'),
                'format' => $gb_axis,
                'scale_base' => 1024.0,
                'token' => '--vr-s1',
                'height' => 300,
                'threshold' => $storage['capacity_gb'],
                'threshold_label' => sprintf(_('Capacity %1$s'), $helper->formatGb($storage['capacity_gb']))
            ]) ?>
        </div>
        <p class="takeaway">
            <?php $forecast_status = (string) ($forecast['status'] ?? 'unavailable'); ?>
            <?php if ($forecast_status === 'projected'): ?>
                <?= $h(sprintf(
                    _('Used space is growing by about %1$s per day. At that rate the repositories fill up around %2$s, in roughly %3$d days.'),
                    $helper->formatGb($forecast['growth_gb_day'], 2),
                    date('j F Y', (int) strtotime((string) $forecast['full_date'])),
                    (int) $forecast['days_to_full']
                )) ?>
            <?php elseif ($forecast_status === 'beyond_horizon' && (float) $forecast['growth_gb_day'] > 0): ?>
                <?= $h(sprintf(
                    _('Used space is growing by about %1$s per day, which leaves more than two years of headroom at the current capacity.'),
                    $helper->formatGb($forecast['growth_gb_day'], 2)
                )) ?>
            <?php elseif ($forecast_status === 'beyond_horizon'): ?>
                <?= $h(_('Used space is stable or falling over this period, so no capacity date can be projected.')) ?>
            <?php else: ?>
                <?= $h(_('There is not enough repository history in this period to project a capacity date.')) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<section class="section">
    <h2><?= $h(_('What needs attention')) ?></h2>
    <?php $has_subject = $report['jobs'] !== [] || $report['objects'] !== [] || $report['repo_groups'] !== []; ?>
    <?php if ($report['attention'] === [] && !$has_subject): ?>
        <p class="dim">
            <?= $h(_('Nothing to check: no backup jobs, protected objects or repositories were found for this period.')) ?>
        </p>
    <?php elseif ($report['attention'] === []): ?>
        <p class="takeaway takeaway--good">
            <?= $h(_('Nothing outstanding. No failed jobs, no offline repositories, and every protected object has a recent restore point.')) ?>
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th class="sev-col"><?= $h(_('Severity')) ?></th>
                    <th><?= $h(_('Area')) ?></th>
                    <th><?= $h(_('Finding')) ?></th>
                    <th><?= $h(_('Detail')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['attention'] as $item): ?>
                    <tr>
                        <td><?= $fmt->pill(
                            $item['severity'] === 'critical' ? 'critical' : ($item['severity'] === 'warning' ? 'warning' : 'neutral'),
                            $item['severity'] === 'critical' ? _('Critical') : ($item['severity'] === 'warning' ? _('Warning') : _('Info'))
                        ) ?></td>
                        <td><?= $h($item['scope']) ?></td>
                        <td><strong><?= $h($item['title']) ?></strong></td>
                        <td class="wrap"><?= $h($item['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php if ($overdue !== []): ?>
<section class="section">
    <h2><?= $h(_('Objects without a recent backup')) ?></h2>
    <p class="lede">
        <?= $h(sprintf(
            _('These objects have no restore point newer than %1$d hours.'),
            (int) $filter['stale_hours']
        )) ?>
    </p>
    <table>
        <thead>
            <tr>
                <th><?= $h(_('Object')) ?></th>
                <th><?= $h(_('Backup type')) ?></th>
                <th><?= $h(_('Veeam server')) ?></th>
                <th><?= $h(_('Last backup')) ?></th>
                <th class="num"><?= $h(_('Size')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($overdue as $row): ?>
                <tr>
                    <td><strong><?= $h($row['object']) ?></strong></td>
                    <td><?= $fmt->swatch($row['token']) ?><?= $h($row['type_label']) ?></td>
                    <td><?= $h($row['host']) ?></td>
                    <td><?= $fmt->freshness((string) $row['freshness'], $row['age_seconds']) ?>
                        <?php if ($row['last_backup_ts'] !== null): ?>
                            <div class="dim"><?= $h($helper->formatDateTime($row['last_backup_ts'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $h($helper->formatBytes($row['metric_end'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="section page-break">
    <h2><?= $h(_('Protected objects in detail')) ?></h2>
    <p class="lede">
        <?= $h(sprintf(
            _('The %1$d largest of %2$d protected objects with data in this period, measured as %3$s.'),
            (int) $report['objects_shown'],
            (int) ($report['objects_with_data'] ?? $report['objects_filtered']),
            $metric_label
        )) ?>
    </p>
    <?php if ($report['objects'] === []): ?>
        <p class="dim"><?= $h(_('No protected objects matched the filter.')) ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th><?= $h(_('Object')) ?></th>
                    <th><?= $h(_('Backup type')) ?></th>
                    <th><?= $h(_('Veeam server')) ?></th>
                    <th><?= $h(_('Last backup')) ?></th>
                    <th class="num"><?= $h(_('Size')) ?></th>
                    <th class="num"><?= $h(_('Change')) ?></th>
                    <th class="num"><?= $h(_('Restore points')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['objects'] as $row): ?>
                    <tr>
                        <td><strong><?= $h($row['object']) ?></strong></td>
                        <td><?= $fmt->swatch($row['token']) ?><?= $h($row['type_label']) ?></td>
                        <td><?= $h($row['host']) ?></td>
                        <td><?= $h($row['last_backup_ts'] !== null
                            ? $helper->formatDateTime($row['last_backup_ts'])
                            : '—') ?></td>
                        <td class="num"><?= $h($helper->formatBytes($row['metric_end'])) ?></td>
                        <td class="num"><?= $fmt->delta($row['metric_change']) ?></td>
                        <td class="num"><?= $h($helper->formatInt($row['restorepoints_31d'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<footer class="footer">
    <div>
        <strong><?= $h(_('Veeam Backup Report')) ?></strong> ·
        <?= $h(sprintf(_('Generated %1$s'), date('j F Y H:i'))) ?>
    </div>
    <div class="dim">
        <?= $h(sprintf(
            _('Source: Zabbix %1$s from the "Veeam Backup and Replication by HTTP v13" template. Metric: %2$s.'),
            $report['source_used'] === 'trends' ? _('hourly trends') : _('raw history'),
            $metric_label
        )) ?>
    </div>
    <?php foreach (($report['warnings'] ?? []) as $warning): ?>
        <div class="dim footnote"><?= $h($warning) ?></div>
    <?php endforeach; ?>
</footer>

</div>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * One-line verdict for the cover, so the first thing a reader sees is an
     * answer rather than a number.
     *
     * @return array{tone:string,word:string,line:string}
     */
    private function verdict(array $report): array {
        $critical = 0;
        $warning = 0;
        foreach ($report['attention'] as $item) {
            if ($item['severity'] === 'critical') {
                $critical++;
            }
            elseif ($item['severity'] === 'warning') {
                $warning++;
            }
        }

        if ($critical > 0) {
            $line = _n('%1$d critical finding', '%1$d critical findings', $critical);
            if ($warning > 0) {
                $line .= ' · '._n('%1$d warning', '%1$d warnings', $warning);
            }

            return [
                'tone' => 'critical',
                'word' => _('Action needed'),
                'line' => $line
            ];
        }

        if ($warning > 0) {
            return [
                'tone' => 'warning',
                'word' => _('Mostly healthy'),
                'line' => _n('%1$d item to review', '%1$d items to review', $warning)
            ];
        }

        // "Healthy" is a claim about something that was inspected. With no
        // jobs, objects or repositories there is nothing to vouch for.
        if ($report['jobs'] === [] && $report['objects'] === [] && $report['repo_groups'] === []) {
            return [
                'tone' => 'neutral',
                'word' => _('No data'),
                'line' => _('No backup jobs, protected objects or repositories were found for this period')
            ];
        }

        return [
            'tone' => 'ok',
            'word' => _('Healthy'),
            'line' => _('No failed jobs, no full repositories, no overdue backups')
        ];
    }

    /**
     * Light-only stylesheet. The export is a document people print and email,
     * so it commits to one look rather than following a viewer's theme.
     */
    private function styles(): string {
        return <<<'CSS'
:root {
    --vr-surface: #ffffff;
    --vr-surface-2: #f6f8fb;
    --vr-border: #e2e8f0;
    --vr-border-strong: #cbd5e1;
    --vr-ink: #16202e;
    --vr-ink-2: #3f4f63;
    --vr-muted: #64748b;
    --vr-accent: #0f6ad8;
    --vr-grid: #eaeff6;
    --vr-axis: #cbd5e1;
    --vr-track: #edf1f7;

    --vr-ok: #0ca30c;
    --vr-warning: #fab219;
    --vr-serious: #ec835a;
    --vr-critical: #d03b3b;
    --vr-ok-ink: #067506;
    --vr-warning-ink: #8a5a00;
    --vr-critical-ink: #b02121;

    --vr-s1: #2a78d6;
    --vr-s2: #eb6834;
    --vr-s3: #1baf7a;
    --vr-s4: #eda100;
    --vr-s5: #e87ba4;
    --vr-s6: #008300;
    --vr-s7: #4a3aa7;
    --vr-s8: #e34948;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 32px 28px 48px;
    background: #eef2f7;
    color: var(--vr-ink);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 13.5px;
    line-height: 1.55;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.veeamreport {
    max-width: 1120px;
    margin: 0 auto;
    padding: 40px 44px 36px;
    background: var(--vr-surface);
    border-radius: 10px;
    box-shadow: 0 2px 20px rgba(22, 32, 46, 0.08);
}

/* ---- cover ---- */

.cover {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    padding-bottom: 26px;
    border-bottom: 3px solid var(--vr-accent);
}

.cover-eyebrow {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--vr-accent);
}

.cover h1 { margin: 0; font-size: 30px; font-weight: 600; line-height: 1.15; }
.cover-period { margin: 8px 0 2px; font-size: 17px; font-weight: 600; color: var(--vr-ink-2); }
.cover-servers { margin: 0; font-size: 13px; color: var(--vr-muted); }

.cover-verdict {
    min-width: 240px;
    padding: 14px 18px;
    border-radius: 8px;
    border-left: 4px solid var(--vr-border-strong);
    background: var(--vr-surface-2);
}

.verdict--ok { border-left-color: var(--vr-ok); background: rgba(12, 163, 12, 0.07); }
.verdict--warning { border-left-color: var(--vr-warning); background: rgba(250, 178, 25, 0.1); }
.verdict--critical { border-left-color: var(--vr-critical); background: rgba(208, 59, 59, 0.07); }

.verdict-word { font-size: 20px; font-weight: 700; }
.verdict--ok .verdict-word { color: var(--vr-ok-ink); }
.verdict--warning .verdict-word { color: var(--vr-warning-ink); }
.verdict--critical .verdict-word { color: var(--vr-critical-ink); }
.verdict-line { margin-top: 3px; font-size: 12.5px; color: var(--vr-ink-2); }

/* ---- sections ---- */

.section { margin-top: 36px; }
.section h2 {
    margin: 0 0 6px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--vr-border);
    font-size: 18px;
    font-weight: 600;
}
.section h3 { margin: 26px 0 8px; font-size: 14.5px; font-weight: 600; }

.lede { margin: 12px 0 16px; color: var(--vr-ink-2); max-width: 86ch; }

.takeaway {
    margin: 14px 0 0;
    padding: 12px 16px;
    border-left: 3px solid var(--vr-accent);
    border-radius: 0 6px 6px 0;
    background: var(--vr-surface-2);
    color: var(--vr-ink-2);
    max-width: 92ch;
}

.takeaway--good { border-left-color: var(--vr-ok); background: rgba(12, 163, 12, 0.07); color: var(--vr-ok-ink); }

/* ---- kpis ---- */

.kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    margin-top: 16px;
}

.kpi {
    position: relative;
    padding: 15px 17px;
    border: 1px solid var(--vr-border);
    border-radius: 8px;
    overflow: hidden;
}

.kpi::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 3px;
    background: var(--vr-accent);
}

.kpi--ok::before { background: var(--vr-ok); }
.kpi--warning::before { background: var(--vr-warning); }
.kpi--critical::before { background: var(--vr-critical); }

.kpi-label {
    margin-bottom: 7px;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--vr-muted);
}

.kpi-value { font-size: 24px; font-weight: 600; line-height: 1.15; }
.kpi-sub { margin-top: 6px; font-size: 11.5px; color: var(--vr-muted); }

/* ---- cards & charts ---- */

.card {
    margin-top: 16px;
    padding: 18px;
    border: 1px solid var(--vr-border);
    border-radius: 8px;
}

.card.split {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 24px;
    align-items: center;
}

.vr-chart { display: block; width: 100%; height: auto; overflow: visible; }
.vr-chart-empty { margin: 0; padding: 26px; text-align: center; color: var(--vr-muted); }

.vr-grid { stroke: var(--vr-grid); stroke-width: 1; }
.vr-baseline { stroke: var(--vr-axis); stroke-width: 1; }
.vr-tick { fill: var(--vr-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.vr-label { fill: var(--vr-ink-2); font-size: 12px; text-anchor: end; }
.vr-value { fill: var(--vr-ink); font-size: 12px; font-weight: 600; text-anchor: end; font-variant-numeric: tabular-nums; }
.vr-track { fill: var(--vr-track); }
.vr-track-ring { stroke: var(--vr-track); }
.vr-line { fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.vr-line--forecast { stroke-dasharray: 5 5; opacity: 0.75; }
.vr-area { opacity: 0.1; }
.vr-stack-area { opacity: 0.85; }
.vr-end-dot { stroke: var(--vr-surface); stroke-width: 2; }
.vr-threshold { stroke: var(--vr-critical); stroke-width: 1.5; opacity: 0.8; }
.vr-threshold-label { fill: var(--vr-critical-ink); font-size: 11px; font-weight: 600; }
.vr-donut-value { fill: var(--vr-ink); font-size: 26px; font-weight: 600; }
.vr-donut-label { fill: var(--vr-muted); font-size: 11px; }
.vr-sparkline { display: inline-block; vertical-align: middle; }

.vr-legend { display: flex; flex-wrap: wrap; gap: 6px 20px; margin-top: 14px; }
.vr-legend-item { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; }
.vr-legend-swatch { width: 10px; height: 10px; border-radius: 2px; }
.vr-legend-label { color: var(--vr-ink-2); }
.vr-legend-value { color: var(--vr-muted); font-variant-numeric: tabular-nums; }

.vr-meter { display: block; width: 100%; height: 8px; border-radius: 999px; background: var(--vr-track); overflow: hidden; }
.vr-meter-fill { display: block; height: 100%; border-radius: 999px; }

.vr-swatch { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 7px; }

/* ---- tables ---- */

table {
    width: 100%;
    margin-top: 16px;
    border-collapse: collapse;
    font-size: 12.5px;
}

table.tight { margin-top: 0; }

th, td {
    padding: 9px 12px;
    text-align: left;
    border-bottom: 1px solid var(--vr-border);
    vertical-align: middle;
}

thead th {
    background: var(--vr-surface-2);
    color: var(--vr-muted);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--vr-border-strong);
}

tbody tr:last-child td { border-bottom: none; }

.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.wrap { word-break: break-word; }
.dim { color: var(--vr-muted); font-size: 11.5px; }
.meter-col { width: 20%; }
.sev-col { width: 110px; }

/* ---- status pills ---- */

.vr-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 9px;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.vr-status-glyph { font-size: 9px; line-height: 1; }
.vr-status--ok { background: rgba(12,163,12,0.12); border-color: rgba(12,163,12,0.35); color: var(--vr-ok-ink); }
.vr-status--warning { background: rgba(250,178,25,0.16); border-color: rgba(250,178,25,0.45); color: var(--vr-warning-ink); }
.vr-status--critical { background: rgba(208,59,59,0.12); border-color: rgba(208,59,59,0.4); color: var(--vr-critical-ink); }
.vr-status--neutral { background: var(--vr-surface-2); border-color: var(--vr-border); color: var(--vr-muted); }

.vr-delta-up { color: var(--vr-warning-ink); font-variant-numeric: tabular-nums; }
.vr-delta-down { color: var(--vr-ok-ink); font-variant-numeric: tabular-nums; }
.vr-delta-flat { color: var(--vr-muted); font-variant-numeric: tabular-nums; }

/* ---- footer ---- */

.footer {
    margin-top: 40px;
    padding-top: 18px;
    border-top: 1px solid var(--vr-border);
    font-size: 11.5px;
    color: var(--vr-ink-2);
}

.footnote { margin-top: 5px; }

/* ---- print ---- */

@page { size: A4; margin: 14mm 12mm; }

@media print {
    body { padding: 0; background: #ffffff; font-size: 10.5px; }
    .veeamreport { max-width: none; padding: 0; box-shadow: none; border-radius: 0; }
    .section { margin-top: 22px; break-inside: auto; }
    .section h2 { break-after: avoid; }
    .card, .kpi, .cover, .takeaway { break-inside: avoid; }
    .page-break { break-before: page; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
    .cover h1 { font-size: 24px; }
    .kpi-value { font-size: 19px; }
}

@media (max-width: 720px) {
    body { padding: 12px; }
    .veeamreport { padding: 22px 18px; }
    .card.split { grid-template-columns: 1fr; }
}
CSS;
    }
}
