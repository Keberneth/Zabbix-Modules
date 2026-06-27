<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Helpers;

use API;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class ReportDataHelper {

    private const HISTORY_FLOAT = 0;
    private const HISTORY_UINT = 3;

    private const SOURCE_AUTO = 'auto';
    private const SOURCE_HISTORY = 'history';
    private const SOURCE_TRENDS = 'trends';

    /** Auto mode switches to trends once the range exceeds this many days. */
    private const AUTO_TRENDS_DAYS = 7;

    /** Even explicit History mode falls back to trends past this many days to bound row volume. */
    private const HISTORY_MAX_DAYS = 31;

    /** Item-id chunk size for history/trend fetches. */
    private const FETCH_CHUNK = 100;

    /** Soft per-item row budget used to size the API 'limit' on each chunked fetch. */
    private const MAX_ROWS_PER_ITEM = 50000;

    /** Hard upper bound on rows transferred per API call. */
    private const MAX_FETCH_ROWS = 2000000;

    /** True when any history/trend fetch hit its row cap (results may be partial). */
    private bool $limit_reached = false;

    /** Repository top-N accounting for the "showing top N" notice. */
    private int $repo_filtered = 0;
    private int $repo_shown = 0;

    private const METRIC_24H = 'size24h';
    private const METRIC_31D = 'size31d';

    private const KEY_HOST_TOTAL_24H = 'veeam.backup.total.size.24h';
    private const KEY_HOST_TOTAL_31D = 'veeam.backup.total.size.31d';
    private const KEY_HOST_ASSIGNED_31D = 'veeam.backup.total.assigned.size.31d';
    private const KEY_HOST_SHARED_31D = 'veeam.backup.total.shared.size.31d';
    private const KEY_HOST_COVERAGE = 'veeam.backup.total.attribution.coverage';
    private const KEY_REPO_CAPACITY_TOTAL = 'veeam.repositories.total.capacity.gb';
    private const KEY_REPO_FREE_TOTAL = 'veeam.repositories.total.free.gb';
    private const KEY_REPO_USED_TOTAL = 'veeam.repositories.total.used.gb';
    private const KEY_REPO_ONLINE_COUNT = 'veeam.repositories.online.count';
    private const KEY_REPO_OFFLINE_COUNT = 'veeam.repositories.offline.count';
    private const KEY_BACKUP_REPORT = 'veeam.get.backup.report';

    /**
     * Get the default filter state.
     */
    public static function getDefaultFilter(): array {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $month = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');

        return [
            'mode' => 'prev_month',
            'month' => $month,
            'date_from' => '',
            'date_to' => '',
            'days_back' => 31,
            'hostids' => [],
            'source' => self::SOURCE_AUTO,
            'metric' => self::METRIC_24H,
            'top' => 100,
            'object_search' => '',
            'repo_search' => ''
        ];
    }

    /**
     * Normalize user input.
     */
    public static function normalizeFilter(array $input): array {
        $default = self::getDefaultFilter();

        $filter = array_merge($default, $input);

        $allowed_modes = ['prev_month', 'specific_month', 'custom_range', 'days_back'];
        if (!in_array($filter['mode'], $allowed_modes, true)) {
            $filter['mode'] = $default['mode'];
        }

        $allowed_sources = [self::SOURCE_AUTO, self::SOURCE_HISTORY, self::SOURCE_TRENDS];
        if (!in_array($filter['source'], $allowed_sources, true)) {
            $filter['source'] = $default['source'];
        }

        $allowed_metrics = [self::METRIC_24H, self::METRIC_31D];
        if (!in_array($filter['metric'], $allowed_metrics, true)) {
            $filter['metric'] = $default['metric'];
        }

        $filter['month'] = preg_match('/^\d{4}-\d{2}$/', (string) $filter['month'])
            ? (string) $filter['month']
            : $default['month'];

        $filter['date_from'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['date_from'])
            ? (string) $filter['date_from']
            : '';

        $filter['date_to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['date_to'])
            ? (string) $filter['date_to']
            : '';

        $filter['days_back'] = max(1, min(366, (int) $filter['days_back']));
        $filter['top'] = max(10, min(500, (int) $filter['top']));
        $filter['object_search'] = trim((string) $filter['object_search']);
        $filter['repo_search'] = trim((string) $filter['repo_search']);

        $hostids = [];
        foreach ((array) $filter['hostids'] as $hostid) {
            if (preg_match('/^\d+$/', (string) $hostid)) {
                $hostids[] = (string) $hostid;
            }
        }
        $filter['hostids'] = array_values(array_unique($hostids));

        return $filter;
    }

    /**
     * Convert the selected filter into a concrete time range in the frontend timezone.
     *
     * @return array{0:int,1:int}
     */
    public static function resolveDateRange(array $filter): array {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $now = new DateTimeImmutable('now', $tz);

        try {
            switch ($filter['mode']) {
                case 'specific_month':
                    $month = DateTimeImmutable::createFromFormat('!Y-m', (string) $filter['month'], $tz);
                    if ($month === false) {
                        throw new \RuntimeException('Invalid month.');
                    }

                    $from = $month->setTime(0, 0, 0);
                    $to = $month->modify('last day of this month')->setTime(23, 59, 59);
                    break;

                case 'custom_range':
                    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filter['date_from'], $tz);
                    $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filter['date_to'], $tz);

                    if ($from === false || $to === false) {
                        throw new \RuntimeException('Invalid custom range.');
                    }

                    $from = $from->setTime(0, 0, 0);
                    $to = $to->setTime(23, 59, 59);
                    if ($to < $from) {
                        [$from, $to] = [$to, $from];
                    }
                    break;

                case 'days_back':
                    $days_back = max(1, (int) $filter['days_back']);
                    $to = $now;
                    $from = $to->sub(new DateInterval('P'.max(0, $days_back - 1).'D'))->setTime(0, 0, 0);
                    break;

                case 'prev_month':
                default:
                    $from = (new DateTimeImmutable('first day of last month', $tz))->setTime(0, 0, 0);
                    $to = $from->modify('last day of this month')->setTime(23, 59, 59);
                    break;
            }
        }
        catch (Throwable $e) {
            $from = (new DateTimeImmutable('first day of last month', $tz))->setTime(0, 0, 0);
            $to = $from->modify('last day of this month')->setTime(23, 59, 59);
        }

        return [$from->getTimestamp(), $to->getTimestamp()];
    }

    /**
     * User-facing label for the selected metric.
     */
    public function getMetricLabel(string $metric): string {
        return $metric === self::METRIC_31D
            ? _('Rolling 31-day backup size')
            : _('Backup size 24h');
    }

    /**
     * Get the Veeam hosts that currently expose the v13 backup-report items.
     */
    public function getAvailableHosts(): array {
        $items = API::Item()->get([
            'output' => ['itemid', 'hostid', 'lastclock'],
            'filter' => ['key_' => self::KEY_HOST_TOTAL_24H],
            'selectHosts' => ['hostid', 'name'],
            'preservekeys' => false
        ]);

        $hosts = [];

        foreach ($items as $item) {
            if ((int) ($item['lastclock'] ?? 0) <= 0) {
                continue;
            }

            $host = $item['hosts'][0] ?? null;
            if ($host === null) {
                continue;
            }

            $hosts[(string) $host['hostid']] = [
                'hostid' => (string) $host['hostid'],
                'name' => (string) $host['name']
            ];
        }

        uasort($hosts, static function(array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $hosts;
    }

    /**
     * Main report builder.
     */
    public function buildReport(array $filter, int $time_from, int $time_to): array {
        $this->limit_reached = false;
        $this->repo_filtered = 0;
        $this->repo_shown = 0;

        $host_options = $this->getAvailableHosts();
        $selected_hostids = $filter['hostids'] !== []
            ? array_values(array_intersect($filter['hostids'], array_keys($host_options)))
            : array_keys($host_options);

        $report = [
            'host_options' => $host_options,
            'selected_hostids' => $selected_hostids,
            'source_requested' => $filter['source'],
            'source_used' => $this->resolveSource($filter['source'], $time_from, $time_to),
            'summary' => [],
            'daily' => [],
            'source_hosts' => [],
            'repositories' => [],
            'objects' => [],
            'objects_total' => 0,
            'objects_filtered' => 0,
            'objects_shown' => 0,
            'warnings' => [],
            'error' => null
        ];

        if ($selected_hostids === []) {
            $report['warnings'][] = _('No Veeam hosts with the backup-report items were found. Apply the v13 template to a host, wait for data, and refresh the page.');

            return $report;
        }

        $classified = $this->getClassifiedItems($selected_hostids);

        $daily = $this->buildDailyTotals($classified['sources'], $time_from, $time_to, $report['source_used']);
        $source_rows = $this->buildSourceHostRows($classified['sources'], $filter['metric'], $time_from, $time_to, $report['source_used']);
        $repository_rows = $this->buildRepositoryRows(
            $classified['repositories'],
            $filter['metric'],
            $time_from,
            $time_to,
            $report['source_used'],
            $filter['repo_search'],
            $filter['top']
        );
        $object_rows_info = $this->buildObjectRows(
            $classified['objects'],
            $filter['metric'],
            $time_from,
            $time_to,
            $report['source_used'],
            $filter['object_search'],
            $filter['top']
        );

        $report['daily'] = $daily;
        $report['source_hosts'] = $source_rows;
        $report['repositories'] = $repository_rows;
        $report['objects'] = $object_rows_info['rows'];
        $report['objects_total'] = $object_rows_info['total'];
        $report['objects_filtered'] = $object_rows_info['filtered'];
        $report['objects_shown'] = $object_rows_info['shown'];
        $report['summary'] = $this->buildSummary(
            $daily,
            $source_rows,
            $repository_rows,
            $object_rows_info,
            $filter['metric']
        );

        if ($report['source_used'] === self::SOURCE_TRENDS) {
            $report['warnings'][] = _('Trend mode uses Zabbix hourly trend buckets. Daily end values are estimated from the last hourly average in the day, not from the exact last raw sample.');
        }

        if ($filter['source'] === self::SOURCE_HISTORY && $report['source_used'] === self::SOURCE_TRENDS) {
            $report['warnings'][] = sprintf(
                _('History was requested, but the selected range exceeds %1$d days. Trend data is used instead to keep the report responsive.'),
                self::HISTORY_MAX_DAYS
            );
        }

        if ($this->repo_filtered > $this->repo_shown) {
            $report['warnings'][] = sprintf(
                _('Only the top %1$d repositories are shown. Increase the limit to see more.'),
                $filter['top']
            );
        }

        if ($this->limit_reached) {
            $report['warnings'][] = sprintf(
                _('Some series hit the %1$s-row fetch cap and may be partial. Narrow the period or switch to trend mode.'),
                number_format(self::MAX_FETCH_ROWS, 0, '.', ' ')
            );
        }

        if ($filter['object_search'] !== '') {
            $report['warnings'][] = sprintf(_('Object filter applied: "%1$s".'), $filter['object_search']);
        }

        if ($filter['repo_search'] !== '') {
            $report['warnings'][] = sprintf(_('Repository filter applied: "%1$s".'), $filter['repo_search']);
        }

        if ($object_rows_info['filtered'] > $object_rows_info['shown']) {
            $report['warnings'][] = sprintf(
                _('Only the top %1$d protected objects are shown. Increase the limit to see more.'),
                $filter['top']
            );
        }

        if ($daily === [] && $source_rows === [] && $repository_rows === [] && $object_rows_info['rows'] === []) {
            $report['warnings'][] = _('No history/trend data was found for the selected period. Check item retention, switch the source mode, or choose a newer period.');
        }

        return $report;
    }

    /**
     * Format a date range label.
     */
    public function formatPeriodLabel(int $time_from, int $time_to): string {
        return date('Y-m-d H:i:s', $time_from).' → '.date('Y-m-d H:i:s', $time_to);
    }

    /**
     * Flatten daily rows for CSV export.
     */
    public function flattenDailyRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['date'],
                $row['size24h'],
                $this->formatBytes($row['size24h']),
                $row['size31d'],
                $this->formatBytes($row['size31d']),
                $row['assigned31d'],
                $this->formatBytes($row['assigned31d']),
                $row['shared31d'],
                $this->formatBytes($row['shared31d']),
                $row['coverage_pct'],
                $this->formatPct($row['coverage_pct'], 2),
                $row['hosts_with_data']
            ];
        }

        return $out;
    }

    /**
     * Flatten Veeam-source host rows for CSV export.
     */
    public function flattenSourceHostRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'],
                $row['metric_start'],
                $this->formatBytes($row['metric_start']),
                $row['metric_end'],
                $this->formatBytes($row['metric_end']),
                $row['metric_change'],
                $this->formatBytes($row['metric_change']),
                $row['metric_avg'],
                $this->formatBytes($row['metric_avg']),
                $row['metric_peak'],
                $this->formatBytes($row['metric_peak']),
                $row['days'],
                $row['repo_capacity_gb'],
                $this->formatNumber($row['repo_capacity_gb'], 2),
                $row['repo_used_gb'],
                $this->formatNumber($row['repo_used_gb'], 2),
                $row['repo_free_gb'],
                $this->formatNumber($row['repo_free_gb'], 2),
                $row['repo_online_count'],
                $row['repo_offline_count'],
                $row['assigned_31d'],
                $this->formatBytes($row['assigned_31d']),
                $row['shared_31d'],
                $this->formatBytes($row['shared_31d']),
                $row['coverage_pct'],
                $this->formatPct($row['coverage_pct'], 2),
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    /**
     * Flatten repository rows for CSV export.
     */
    public function flattenRepositoryRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'],
                $row['repository'],
                $row['metric_start'],
                $this->formatBytes($row['metric_start']),
                $row['metric_end'],
                $this->formatBytes($row['metric_end']),
                $row['metric_change'],
                $this->formatBytes($row['metric_change']),
                $row['metric_avg'],
                $this->formatBytes($row['metric_avg']),
                $row['metric_peak'],
                $this->formatBytes($row['metric_peak']),
                $row['days'],
                $row['files_31d'],
                $row['capacity_gb'],
                $this->formatNumber($row['capacity_gb'], 2),
                $row['used_gb'],
                $this->formatNumber($row['used_gb'], 2),
                $row['free_gb'],
                $this->formatNumber($row['free_gb'], 2),
                $row['free_pct'],
                $this->formatPct($row['free_pct'], 2),
                $row['online'],
                $row['out_of_date'],
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    /**
     * Flatten protected object rows for CSV export.
     */
    public function flattenObjectRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'],
                $row['object'],
                $row['platform'],
                $row['metric_start'],
                $this->formatBytes($row['metric_start']),
                $row['metric_end'],
                $this->formatBytes($row['metric_end']),
                $row['metric_change'],
                $this->formatBytes($row['metric_change']),
                $row['metric_avg'],
                $this->formatBytes($row['metric_avg']),
                $row['metric_peak'],
                $this->formatBytes($row['metric_peak']),
                $row['days'],
                $row['restorepoints_31d'],
                $row['backupfiles_31d'],
                $row['last_backup'],
                $row['repositories'],
                $row['attribution'],
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    /**
     * Render a standalone HTML export.
     */
    public function renderStandaloneHtml(array $filter, array $report, int $time_from, int $time_to): string {
        $title = 'Veeam Backup Report';
        $period = $this->formatPeriodLabel($time_from, $time_to);
        $metric_label = $this->getMetricLabel((string) $filter['metric']);
        $source_label = ucfirst((string) $report['source_used']);
        $generated = date('Y-m-d H:i:s');

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo self::h($title); ?></title>
<style>
body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: #1f2b3a; margin: 24px; }
h1, h2, h3 { color: #2a3441; }
table { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
th, td { border: 1px solid #e7edf4; padding: 9px 10px; text-align: left; vertical-align: top; }
th { background: #f7f9fb; color: #2a3441; }
.summary-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
.card { border: 1px solid #dfe4ec; border-radius: 4px; padding: 16px; min-width: 220px; }
.label { color: #5c6b7a; font-size: 12px; margin-bottom: 6px; }
.value { font-size: 24px; font-weight: bold; color: #1f2b3a; }
.sub { color: #5c6b7a; margin-top: 6px; }
.note { background: #f7f9fc; border: 1px solid #dfe4ec; color: #34485f; padding: 10px 12px; border-radius: 4px; margin-bottom: 18px; }
.warn { background: #fff7e6; border-color: #f1d59c; color: #7a5a12; }
</style>
</head>
<body>
<h1><?php echo self::h($title); ?></h1>

<p><strong>Period:</strong> <?php echo self::h($period); ?><br>
<strong>Metric:</strong> <?php echo self::h($metric_label); ?><br>
<strong>Source:</strong> <?php echo self::h($source_label); ?><br>
<strong>Generated:</strong> <?php echo self::h($generated); ?></p>

<?php foreach (($report['warnings'] ?? []) as $warning): ?>
<div class="note warn"><?php echo self::h((string) $warning); ?></div>
<?php endforeach; ?>

<div class="summary-grid">
<?php foreach (($report['summary']['cards'] ?? []) as $card): ?>
    <div class="card">
        <div class="label"><?php echo self::h((string) $card['label']); ?></div>
        <div class="value"><?php echo self::h((string) $card['value']); ?></div>
        <div class="sub"><?php echo self::h((string) $card['sub']); ?></div>
    </div>
<?php endforeach; ?>
</div>

<h2>Daily totals</h2>
<table>
<thead>
<tr>
    <th>Date</th>
    <th>Total 24h</th>
    <th>Total 31d</th>
    <th>Assigned 31d</th>
    <th>Shared 31d</th>
    <th>Coverage</th>
    <th>Hosts with data</th>
</tr>
</thead>
<tbody>
<?php if (($report['daily'] ?? []) === []): ?>
<tr><td colspan="7">No data available.</td></tr>
<?php else: ?>
<?php foreach ($report['daily'] as $row): ?>
<tr>
    <td><?php echo self::h((string) $row['date']); ?></td>
    <td><?php echo self::h($this->formatBytes($row['size24h'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['size31d'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['assigned31d'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['shared31d'])); ?></td>
    <td><?php echo self::h($this->formatPct($row['coverage_pct'], 2)); ?></td>
    <td><?php echo self::h((string) $row['hosts_with_data']); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<h2>Veeam source hosts</h2>
<table>
<thead>
<tr>
    <th>Host</th>
    <th>Start</th>
    <th>End</th>
    <th>Change</th>
    <th>Average</th>
    <th>Peak</th>
    <th>Days</th>
    <th>Repo capacity</th>
    <th>Repo used</th>
    <th>Repo free</th>
    <th>Coverage</th>
</tr>
</thead>
<tbody>
<?php if (($report['source_hosts'] ?? []) === []): ?>
<tr><td colspan="11">No data available.</td></tr>
<?php else: ?>
<?php foreach ($report['source_hosts'] as $row): ?>
<tr>
    <td><?php echo self::h((string) $row['host']); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_start'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_end'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_change'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_avg'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_peak'])); ?></td>
    <td><?php echo self::h((string) $row['days']); ?></td>
    <td><?php echo self::h($this->formatNumber($row['repo_capacity_gb'], 2).' GB'); ?></td>
    <td><?php echo self::h($this->formatNumber($row['repo_used_gb'], 2).' GB'); ?></td>
    <td><?php echo self::h($this->formatNumber($row['repo_free_gb'], 2).' GB'); ?></td>
    <td><?php echo self::h($this->formatPct($row['coverage_pct'], 2)); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<h2>Repositories</h2>
<table>
<thead>
<tr>
    <th>Host</th>
    <th>Repository</th>
    <th>Start</th>
    <th>End</th>
    <th>Change</th>
    <th>Average</th>
    <th>Peak</th>
    <th>Days</th>
    <th>Files 31d</th>
    <th>Online</th>
</tr>
</thead>
<tbody>
<?php if (($report['repositories'] ?? []) === []): ?>
<tr><td colspan="10">No data available.</td></tr>
<?php else: ?>
<?php foreach ($report['repositories'] as $row): ?>
<tr>
    <td><?php echo self::h((string) $row['host']); ?></td>
    <td><?php echo self::h((string) $row['repository']); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_start'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_end'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_change'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_avg'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_peak'])); ?></td>
    <td><?php echo self::h((string) $row['days']); ?></td>
    <td><?php echo self::h((string) ($row['files_31d'] ?? '')); ?></td>
    <td><?php echo self::h((string) $row['online']); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<h2>Protected objects</h2>
<table>
<thead>
<tr>
    <th>Host</th>
    <th>Object</th>
    <th>Platform</th>
    <th>Start</th>
    <th>End</th>
    <th>Change</th>
    <th>Average</th>
    <th>Peak</th>
    <th>Days</th>
    <th>Restore points 31d</th>
    <th>Backup files 31d</th>
    <th>Last backup</th>
    <th>Repositories</th>
    <th>Attribution</th>
</tr>
</thead>
<tbody>
<?php if (($report['objects'] ?? []) === []): ?>
<tr><td colspan="14">No data available.</td></tr>
<?php else: ?>
<?php foreach ($report['objects'] as $row): ?>
<tr>
    <td><?php echo self::h((string) $row['host']); ?></td>
    <td><?php echo self::h((string) $row['object']); ?></td>
    <td><?php echo self::h((string) $row['platform']); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_start'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_end'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_change'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_avg'])); ?></td>
    <td><?php echo self::h($this->formatBytes($row['metric_peak'])); ?></td>
    <td><?php echo self::h((string) $row['days']); ?></td>
    <td><?php echo self::h((string) ($row['restorepoints_31d'] ?? '')); ?></td>
    <td><?php echo self::h((string) ($row['backupfiles_31d'] ?? '')); ?></td>
    <td><?php echo self::h((string) ($row['last_backup'] ?? '')); ?></td>
    <td><?php echo self::h((string) ($row['repositories'] ?? '')); ?></td>
    <td><?php echo self::h((string) ($row['attribution'] ?? '')); ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Resolve the source mode.
     */
    private function resolveSource(string $requested, int $time_from, int $time_to): string {
        $span = $time_to - $time_from;

        if ($requested === self::SOURCE_TRENDS) {
            return self::SOURCE_TRENDS;
        }

        if ($requested === self::SOURCE_HISTORY) {
            // Even when History is explicitly requested, prefer trends for wide
            // ranges so we never pull an unbounded raw-history result set.
            return $span > self::HISTORY_MAX_DAYS * 86400
                ? self::SOURCE_TRENDS
                : self::SOURCE_HISTORY;
        }

        // Auto.
        return $span > self::AUTO_TRENDS_DAYS * 86400
            ? self::SOURCE_TRENDS
            : self::SOURCE_HISTORY;
    }

    /**
     * Query the Veeam template items from the selected hosts and classify them.
     */
    private function getClassifiedItems(array $hostids): array {
        $items = API::Item()->get([
            'output' => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock', 'status', 'state'],
            'hostids' => $hostids,
            'search' => ['key_' => 'veeam.'],
            'startSearch' => true,
            'searchWildcardsEnabled' => false,
            'selectHosts' => ['hostid', 'name'],
            'selectTags' => 'extend',
            'filter' => ['status' => 0],
            'preservekeys' => false
        ]);

        $sources = [];
        $repositories = [];
        $objects = [];

        foreach ($items as $item) {
            $host = $item['hosts'][0] ?? ['hostid' => (string) $item['hostid'], 'name' => (string) $item['hostid']];
            $hostid = (string) $host['hostid'];
            $hostname = (string) $host['name'];
            $key = (string) $item['key_'];
            $tags = $this->mapTags((array) ($item['tags'] ?? []));

            if (!array_key_exists($hostid, $sources)) {
                $sources[$hostid] = [
                    'hostid' => $hostid,
                    'host' => $hostname,
                    'items' => []
                ];
            }

            $source_exact_map = [
                self::KEY_HOST_TOTAL_24H => self::METRIC_24H,
                self::KEY_HOST_TOTAL_31D => self::METRIC_31D,
                self::KEY_HOST_ASSIGNED_31D => 'assigned31d',
                self::KEY_HOST_SHARED_31D => 'shared31d',
                self::KEY_HOST_COVERAGE => 'coverage',
                self::KEY_REPO_CAPACITY_TOTAL => 'repoCapacityGb',
                self::KEY_REPO_USED_TOTAL => 'repoUsedGb',
                self::KEY_REPO_FREE_TOTAL => 'repoFreeGb',
                self::KEY_REPO_ONLINE_COUNT => 'repoOnlineCount',
                self::KEY_REPO_OFFLINE_COUNT => 'repoOfflineCount'
            ];

            if (array_key_exists($key, $source_exact_map)) {
                $sources[$hostid]['items'][$source_exact_map[$key]] = $item;
                continue;
            }

            $repo_field = $this->matchRepositoryField($key);
            if ($repo_field !== null) {
                $entity_id = $hostid.'|'.$this->extractKeyParameter($key);
                if (!array_key_exists($entity_id, $repositories)) {
                    $repositories[$entity_id] = [
                        'entity_id' => $entity_id,
                        'hostid' => $hostid,
                        'host' => $hostname,
                        'repository' => (string) ($tags['repository'] ?? $this->extractKeyParameter($key)),
                        'items' => []
                    ];
                }

                $repositories[$entity_id]['items'][$repo_field] = $item;
                if ($repositories[$entity_id]['repository'] === '') {
                    $repositories[$entity_id]['repository'] = (string) ($tags['repository'] ?? $this->extractKeyParameter($key));
                }
                continue;
            }

            $object_field = $this->matchObjectField($key);
            if ($object_field !== null) {
                $entity_id = $hostid.'|'.$this->extractKeyParameter($key);
                if (!array_key_exists($entity_id, $objects)) {
                    $objects[$entity_id] = [
                        'entity_id' => $entity_id,
                        'hostid' => $hostid,
                        'host' => $hostname,
                        'object' => (string) ($tags['object'] ?? $this->extractKeyParameter($key)),
                        'platform' => (string) ($tags['platform'] ?? ''),
                        'items' => []
                    ];
                }

                $objects[$entity_id]['items'][$object_field] = $item;
                if ($objects[$entity_id]['object'] === '') {
                    $objects[$entity_id]['object'] = (string) ($tags['object'] ?? $this->extractKeyParameter($key));
                }
                if ($objects[$entity_id]['platform'] === '') {
                    $objects[$entity_id]['platform'] = (string) ($tags['platform'] ?? '');
                }
            }
        }

        return [
            'sources' => $sources,
            'repositories' => $repositories,
            'objects' => $objects
        ];
    }

    /**
     * Build the daily totals block from per-host global items.
     */
    private function buildDailyTotals(array $sources, int $time_from, int $time_to, string $source_mode): array {
        $fields = [self::METRIC_24H, self::METRIC_31D, 'assigned31d', 'shared31d'];

        // Collect every metric item across all four fields into a single batch
        // keyed by item-id, plus a field/host -> item-id map to reassemble later.
        $all_items_by_id = [];
        $field_host_itemid = [];

        foreach ($sources as $source) {
            foreach ($fields as $field) {
                if (!isset($source['items'][$field])) {
                    continue;
                }

                $item = $source['items'][$field];
                $itemid = (string) $item['itemid'];
                $all_items_by_id[$itemid] = $item;
                $field_host_itemid[$field][$source['hostid']] = $itemid;
            }
        }

        // One chunked History/Trend round-trip for all four metric fields.
        $rows_by_itemid = $this->fetchNumericRows($all_items_by_id, $time_from, $time_to, $source_mode);

        $series_by_itemid = [];
        foreach ($all_items_by_id as $itemid => $item) {
            $series_by_itemid[$itemid] = $this->buildItemDailySeries(
                $rows_by_itemid[$itemid] ?? [],
                $item,
                $time_from,
                $time_to,
                $source_mode
            );
        }

        // Reassemble the per-field, per-host series the rest of the method expects.
        $series = [];
        foreach ($fields as $field) {
            $series[$field] = [];
            foreach (($field_host_itemid[$field] ?? []) as $hostid => $itemid) {
                $series[$field][$hostid] = $series_by_itemid[$itemid];
            }
        }

        $days = [];
        foreach ($series as $entity_series) {
            foreach ($entity_series as $item_series) {
                foreach ($item_series as $date => $_row) {
                    $days[$date] = true;
                }
            }
        }

        $rows = [];
        $dates = array_keys($days);
        sort($dates);

        foreach ($dates as $date) {
            $row = [
                'date' => $date,
                'size24h' => 0.0,
                'size31d' => 0.0,
                'assigned31d' => 0.0,
                'shared31d' => 0.0,
                'coverage_pct' => null,
                'hosts_with_data' => 0
            ];

            $hosts_with_data = [];

            foreach ($series as $field => $entity_series) {
                foreach ($entity_series as $entity_id => $daily_series) {
                    if (!isset($daily_series[$date])) {
                        continue;
                    }

                    $row[$field] += (float) ($daily_series[$date]['last'] ?? 0.0);
                    $hosts_with_data[$entity_id] = true;
                }
            }

            $row['hosts_with_data'] = count($hosts_with_data);
            $denominator = $row['assigned31d'] + $row['shared31d'];
            if ($denominator > 0) {
                $row['coverage_pct'] = ($row['assigned31d'] / $denominator) * 100.0;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Build per-Veeam host summary rows.
     */
    private function buildSourceHostRows(array $sources, string $metric, int $time_from, int $time_to, string $source_mode): array {
        $rows = [];

        $metric_items = [];
        foreach ($sources as $source) {
            if (isset($source['items'][$metric])) {
                $metric_items[$source['hostid']] = $source['items'][$metric];
            }
        }

        $series = $this->fetchDailySeriesByEntity($metric_items, $time_from, $time_to, $source_mode);

        foreach ($sources as $source) {
            $hostid = (string) $source['hostid'];
            $stats = $this->summarizeDailySeries($series[$hostid] ?? [], $source['items'][$metric] ?? null, $time_from, $time_to);

            $assigned = $this->itemLastNumeric($source['items']['assigned31d'] ?? null);
            $shared = $this->itemLastNumeric($source['items']['shared31d'] ?? null);
            $coverage = null;
            if ($assigned !== null && $shared !== null && ($assigned + $shared) > 0.0) {
                $coverage = ($assigned / ($assigned + $shared)) * 100.0;
            }
            else {
                $coverage = $this->itemLastNumeric($source['items']['coverage'] ?? null);
            }

            $rows[] = [
                'host' => (string) $source['host'],
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'repo_capacity_gb' => $this->itemLastNumeric($source['items']['repoCapacityGb'] ?? null),
                'repo_used_gb' => $this->itemLastNumeric($source['items']['repoUsedGb'] ?? null),
                'repo_free_gb' => $this->itemLastNumeric($source['items']['repoFreeGb'] ?? null),
                'repo_online_count' => $this->itemLastNumeric($source['items']['repoOnlineCount'] ?? null),
                'repo_offline_count' => $this->itemLastNumeric($source['items']['repoOfflineCount'] ?? null),
                'assigned_31d' => $assigned,
                'shared_31d' => $shared,
                'coverage_pct' => $coverage,
                'last_clock' => $stats['last_clock']
            ];
        }

        usort($rows, fn(array $a, array $b): int => $this->sortDescByNumeric($a['metric_end'], $b['metric_end']));

        return $rows;
    }

    /**
     * Build repository summary rows.
     */
    private function buildRepositoryRows(
        array $repositories,
        string $metric,
        int $time_from,
        int $time_to,
        string $source_mode,
        string $search,
        int $top
    ): array {
        if ($repositories === []) {
            return [];
        }

        $filtered_entities = [];
        foreach ($repositories as $entity_id => $repository) {
            $haystack = strtolower($repository['host'].' '.$repository['repository']);
            if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
                continue;
            }

            if (!isset($repository['items'][$metric])) {
                continue;
            }

            $filtered_entities[$entity_id] = $repository;
        }

        $this->repo_filtered = count($filtered_entities);

        // Pre-sort by current metric value and keep only the top-N before pulling
        // any history/trend series, mirroring the protected-objects path.
        uasort($filtered_entities, function(array $a, array $b) use ($metric): int {
            return $this->sortDescByNumeric(
                $this->itemLastNumeric($a['items'][$metric] ?? null),
                $this->itemLastNumeric($b['items'][$metric] ?? null)
            );
        });
        $filtered_entities = array_slice($filtered_entities, 0, $top, true);

        $metric_items = [];
        foreach ($filtered_entities as $entity_id => $repository) {
            $metric_items[$entity_id] = $repository['items'][$metric];
        }

        $series = $this->fetchDailySeriesByEntity($metric_items, $time_from, $time_to, $source_mode);

        $rows = [];
        foreach ($filtered_entities as $entity_id => $repository) {
            $stats = $this->summarizeDailySeries($series[$entity_id] ?? [], $repository['items'][$metric] ?? null, $time_from, $time_to);

            if ($stats['days'] === 0) {
                continue;
            }

            $rows[] = [
                'host' => (string) $repository['host'],
                'repository' => (string) $repository['repository'],
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'files_31d' => $this->itemLastNumeric($repository['items']['files31d'] ?? null),
                'capacity_gb' => $this->itemLastNumeric($repository['items']['capacityGb'] ?? null),
                'used_gb' => $this->itemLastNumeric($repository['items']['usedGb'] ?? null),
                'free_gb' => $this->itemLastNumeric($repository['items']['freeGb'] ?? null),
                'free_pct' => $this->itemLastNumeric($repository['items']['freePct'] ?? null),
                'online' => $this->itemLastNumeric($repository['items']['online'] ?? null) === 1.0 ? _('Yes') : _('No'),
                'out_of_date' => $this->itemLastNumeric($repository['items']['outOfDate'] ?? null) === 1.0 ? _('Yes') : _('No'),
                'last_clock' => $stats['last_clock']
            ];
        }

        usort($rows, fn(array $a, array $b): int => $this->sortDescByNumeric($a['metric_end'], $b['metric_end']));

        $this->repo_shown = count($rows);

        return $rows;
    }

    /**
     * Build protected object summary rows.
     *
     * @return array{rows:array,total:int,filtered:int,shown:int}
     */
    private function buildObjectRows(
        array $objects,
        string $metric,
        int $time_from,
        int $time_to,
        string $source_mode,
        string $search,
        int $top
    ): array {
        if ($objects === []) {
            return ['rows' => [], 'total' => 0, 'filtered' => 0, 'shown' => 0];
        }

        $total = count($objects);

        $filtered_entities = [];
        foreach ($objects as $entity_id => $object) {
            if (!isset($object['items'][$metric])) {
                continue;
            }

            $haystack = strtolower(
                $object['host'].' '.$object['object'].' '.$object['platform'].' '.
                (string) (($object['items']['repositories']['lastvalue'] ?? ''))
            );

            if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
                continue;
            }

            $filtered_entities[$entity_id] = $object;
        }

        uasort($filtered_entities, function(array $a, array $b) use ($metric): int {
            return $this->sortDescByNumeric(
                $this->itemLastNumeric($a['items'][$metric] ?? null),
                $this->itemLastNumeric($b['items'][$metric] ?? null)
            );
        });

        $filtered_count = count($filtered_entities);
        $limited_entities = array_slice($filtered_entities, 0, $top, true);

        $metric_items = [];
        foreach ($limited_entities as $entity_id => $object) {
            $metric_items[$entity_id] = $object['items'][$metric];
        }

        $series = $this->fetchDailySeriesByEntity($metric_items, $time_from, $time_to, $source_mode);

        $rows = [];
        foreach ($limited_entities as $entity_id => $object) {
            $stats = $this->summarizeDailySeries($series[$entity_id] ?? [], $object['items'][$metric] ?? null, $time_from, $time_to);

            if ($stats['days'] === 0) {
                continue;
            }

            $rows[] = [
                'host' => (string) $object['host'],
                'object' => (string) $object['object'],
                'platform' => (string) $object['platform'],
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'restorepoints_31d' => $this->itemLastNumeric($object['items']['restorepoints31d'] ?? null),
                'backupfiles_31d' => $this->itemLastNumeric($object['items']['backupfiles31d'] ?? null),
                'last_backup' => (string) (($object['items']['lastBackup']['lastvalue'] ?? '')),
                'repositories' => (string) (($object['items']['repositories']['lastvalue'] ?? '')),
                'attribution' => (string) (($object['items']['attribution']['lastvalue'] ?? '')),
                'last_clock' => $stats['last_clock']
            ];
        }

        usort($rows, fn(array $a, array $b): int => $this->sortDescByNumeric($a['metric_end'], $b['metric_end']));

        return [
            'rows' => $rows,
            'total' => $total,
            'filtered' => $filtered_count,
            'shown' => count($rows)
        ];
    }

    /**
     * Build top-level summary cards.
     */
    private function buildSummary(array $daily, array $source_hosts, array $repositories, array $objects_info, string $metric): array {
        $metric_field = $metric;
        $metric_values = [];
        foreach ($daily as $row) {
            if ($row[$metric_field] !== null) {
                $metric_values[] = (float) $row[$metric_field];
            }
        }

        $current = $metric_values !== [] ? end($metric_values) : null;
        $avg = $metric_values !== [] ? array_sum($metric_values) / count($metric_values) : null;
        $peak = $metric_values !== [] ? max($metric_values) : null;

        $current_31d = null;
        $assigned = null;
        $shared = null;
        $coverage = null;
        if ($daily !== []) {
            $last = end($daily);
            $current_31d = $last['size31d'];
            $assigned = $last['assigned31d'];
            $shared = $last['shared31d'];
            $coverage = $last['coverage_pct'];
        }

        $repo_capacity = 0.0;
        $repo_used = 0.0;
        $repo_free = 0.0;
        foreach ($source_hosts as $row) {
            $repo_capacity += (float) ($row['repo_capacity_gb'] ?? 0.0);
            $repo_used += (float) ($row['repo_used_gb'] ?? 0.0);
            $repo_free += (float) ($row['repo_free_gb'] ?? 0.0);
        }

        return [
            'cards' => [
                [
                    'label' => $this->getMetricLabel($metric),
                    'value' => $this->formatBytes($current),
                    'sub' => sprintf(_('Average %1$s • Peak %2$s'), $this->formatBytes($avg), $this->formatBytes($peak))
                ],
                [
                    'label' => _('Rolling 31-day footprint'),
                    'value' => $this->formatBytes($current_31d),
                    'sub' => sprintf(_('Assigned %1$s • Shared %2$s'), $this->formatBytes($assigned), $this->formatBytes($shared))
                ],
                [
                    'label' => _('Attribution coverage'),
                    'value' => $this->formatPct($coverage, 2),
                    'sub' => sprintf(_('Veeam hosts %1$d • Repositories %2$d'), count($source_hosts), count($repositories))
                ],
                [
                    'label' => _('Protected objects'),
                    'value' => $this->formatInt((float) ($objects_info['shown'] ?? 0)),
                    'sub' => sprintf(_('Shown %1$d of %2$d'), (int) ($objects_info['shown'] ?? 0), (int) ($objects_info['filtered'] ?? 0))
                ],
                [
                    'label' => _('Repository capacity'),
                    'value' => $this->formatNumber($repo_capacity, 2).' GB',
                    'sub' => sprintf(_('Used %1$s GB • Free %2$s GB'), $this->formatNumber($repo_used, 2), $this->formatNumber($repo_free, 2))
                ]
            ]
        ];
    }

    /**
     * Fetch daily series for multiple entities.
     *
     * @param array<string,array> $items_by_entity
     * @return array<string,array<string,array>>
     */
    private function fetchDailySeriesByEntity(array $items_by_entity, int $time_from, int $time_to, string $source_mode): array {
        if ($items_by_entity === []) {
            return [];
        }

        $items_by_id = [];
        $entity_by_itemid = [];
        foreach ($items_by_entity as $entity_id => $item) {
            $itemid = (string) $item['itemid'];
            $items_by_id[$itemid] = $item;
            $entity_by_itemid[$itemid] = $entity_id;
        }

        $rows_by_itemid = $this->fetchNumericRows($items_by_id, $time_from, $time_to, $source_mode);

        $series_by_entity = [];
        foreach ($items_by_id as $itemid => $item) {
            $entity_id = $entity_by_itemid[$itemid];
            $series_by_entity[$entity_id] = $this->buildItemDailySeries(
                $rows_by_itemid[$itemid] ?? [],
                $item,
                $time_from,
                $time_to,
                $source_mode
            );
        }

        return $series_by_entity;
    }

    /**
     * Aggregate one item's fetched rows into a daily series, falling back to the
     * item's last value when no history/trend rows fall inside the range.
     */
    private function buildItemDailySeries(array $rows, array $item, int $time_from, int $time_to, string $source_mode): array {
        $daily_series = $source_mode === self::SOURCE_TRENDS
            ? $this->aggregateTrendRowsByDay($rows)
            : $this->aggregateHistoryRowsByDay($rows);

        if ($daily_series === []) {
            $last_clock = (int) ($item['lastclock'] ?? 0);
            $last_value = $this->itemLastNumeric($item);

            if ($last_clock >= $time_from && $last_clock <= $time_to && $last_value !== null) {
                $date = date('Y-m-d', $last_clock);
                $daily_series[$date] = [
                    'date' => $date,
                    'min' => $last_value,
                    'max' => $last_value,
                    'avg' => $last_value,
                    'last' => $last_value,
                    'last_clock' => $last_clock,
                    'points' => 1
                ];
            }
        }

        return $daily_series;
    }

    /**
     * Fetch raw numeric rows from history.get or trend.get.
     *
     * @param array<string,array> $items_by_id
     * @return array<string,array<int,array>>
     */
    private function fetchNumericRows(array $items_by_id, int $time_from, int $time_to, string $source_mode): array {
        $rows_by_itemid = [];

        if ($items_by_id === []) {
            return $rows_by_itemid;
        }

        if ($source_mode === self::SOURCE_TRENDS) {
            foreach (array_chunk(array_keys($items_by_id), self::FETCH_CHUNK) as $chunk) {
                $limit = $this->chunkRowLimit(count($chunk));

                $rows = API::Trend()->get([
                    'output' => ['itemid', 'clock', 'num', 'value_min', 'value_avg', 'value_max'],
                    'itemids' => $chunk,
                    'time_from' => $time_from,
                    'time_till' => $time_to,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit' => $limit
                ]);

                if (count($rows) >= $limit) {
                    $this->limit_reached = true;
                }

                foreach ($rows as $row) {
                    $itemid = (string) $row['itemid'];
                    $rows_by_itemid[$itemid][] = $row;
                }
            }

            return $rows_by_itemid;
        }

        $grouped_itemids = [
            self::HISTORY_FLOAT => [],
            self::HISTORY_UINT => []
        ];

        foreach ($items_by_id as $itemid => $item) {
            $value_type = (int) ($item['value_type'] ?? self::HISTORY_UINT);
            $history_type = $value_type === self::HISTORY_FLOAT ? self::HISTORY_FLOAT : self::HISTORY_UINT;
            $grouped_itemids[$history_type][] = $itemid;
        }

        foreach ($grouped_itemids as $history_type => $itemids) {
            if ($itemids === []) {
                continue;
            }

            foreach (array_chunk($itemids, self::FETCH_CHUNK) as $chunk) {
                $limit = $this->chunkRowLimit(count($chunk));

                $rows = API::History()->get([
                    'output' => ['itemid', 'clock', 'value'],
                    'history' => $history_type,
                    'itemids' => $chunk,
                    'time_from' => $time_from,
                    'time_till' => $time_to,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit' => $limit
                ]);

                if (count($rows) >= $limit) {
                    $this->limit_reached = true;
                }

                foreach ($rows as $row) {
                    $itemid = (string) $row['itemid'];
                    $rows_by_itemid[$itemid][] = $row;
                }
            }
        }

        return $rows_by_itemid;
    }

    /**
     * Per-chunk API 'limit': scale by item count but never exceed the hard cap.
     */
    private function chunkRowLimit(int $chunk_size): int {
        $limit = self::MAX_ROWS_PER_ITEM * max(1, $chunk_size);

        return $limit > self::MAX_FETCH_ROWS ? self::MAX_FETCH_ROWS : $limit;
    }

    /**
     * Aggregate history rows by local day.
     */
    private function aggregateHistoryRowsByDay(array $rows): array {
        $daily = [];

        foreach ($rows as $row) {
            $clock = (int) $row['clock'];
            $date = date('Y-m-d', $clock);
            $value = (float) $row['value'];

            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'min' => $value,
                    'max' => $value,
                    'avg_sum' => $value,
                    'avg_count' => 1,
                    'last' => $value,
                    'last_clock' => $clock,
                    'points' => 1
                ];
                continue;
            }

            $daily[$date]['min'] = min($daily[$date]['min'], $value);
            $daily[$date]['max'] = max($daily[$date]['max'], $value);
            $daily[$date]['avg_sum'] += $value;
            $daily[$date]['avg_count']++;
            $daily[$date]['points']++;
            if ($clock >= $daily[$date]['last_clock']) {
                $daily[$date]['last'] = $value;
                $daily[$date]['last_clock'] = $clock;
            }
        }

        foreach ($daily as $date => $data) {
            $daily[$date]['avg'] = $data['avg_count'] > 0
                ? $data['avg_sum'] / $data['avg_count']
                : null;
            unset($daily[$date]['avg_sum'], $daily[$date]['avg_count']);
        }

        ksort($daily);

        return $daily;
    }

    /**
     * Aggregate trend rows by local day.
     */
    private function aggregateTrendRowsByDay(array $rows): array {
        $daily = [];

        foreach ($rows as $row) {
            $clock = (int) $row['clock'];
            $date = date('Y-m-d', $clock);
            $min = (float) $row['value_min'];
            $avg = (float) $row['value_avg'];
            $max = (float) $row['value_max'];
            $num = max(1, (int) ($row['num'] ?? 1));

            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'min' => $min,
                    'max' => $max,
                    'avg_weighted_sum' => $avg * $num,
                    'avg_weighted_count' => $num,
                    'last' => $avg,
                    'last_clock' => $clock,
                    'points' => 1
                ];
                continue;
            }

            $daily[$date]['min'] = min($daily[$date]['min'], $min);
            $daily[$date]['max'] = max($daily[$date]['max'], $max);
            $daily[$date]['avg_weighted_sum'] += $avg * $num;
            $daily[$date]['avg_weighted_count'] += $num;
            $daily[$date]['points']++;
            if ($clock >= $daily[$date]['last_clock']) {
                $daily[$date]['last'] = $avg;
                $daily[$date]['last_clock'] = $clock;
            }
        }

        foreach ($daily as $date => $data) {
            $daily[$date]['avg'] = $data['avg_weighted_count'] > 0
                ? $data['avg_weighted_sum'] / $data['avg_weighted_count']
                : null;
            unset($daily[$date]['avg_weighted_sum'], $daily[$date]['avg_weighted_count']);
        }

        ksort($daily);

        return $daily;
    }

    /**
     * Summarize a daily series.
     */
    private function summarizeDailySeries(array $daily_series, ?array $item, int $time_from, int $time_to): array {
        if ($daily_series === []) {
            return [
                'start' => null,
                'end' => null,
                'change' => null,
                'avg' => null,
                'peak' => null,
                'days' => 0,
                'last_clock' => $item !== null ? (int) ($item['lastclock'] ?? 0) : 0
            ];
        }

        ksort($daily_series);

        $starts = [];
        $peaks = [];
        $avgs = [];
        $last_clock = 0;

        foreach ($daily_series as $date => $data) {
            if ($data['last'] !== null) {
                $starts[] = (float) $data['last'];
            }
            if ($data['max'] !== null) {
                $peaks[] = (float) $data['max'];
            }
            if ($data['avg'] !== null) {
                $avgs[] = (float) $data['avg'];
            }
            $last_clock = max($last_clock, (int) ($data['last_clock'] ?? 0));
        }

        $first = reset($daily_series);
        $last = end($daily_series);

        $start = $first['last'] ?? null;
        $end = $last['last'] ?? null;

        return [
            'start' => $start,
            'end' => $end,
            'change' => ($start !== null && $end !== null) ? ($end - $start) : null,
            'avg' => $avgs !== [] ? array_sum($avgs) / count($avgs) : null,
            'peak' => $peaks !== [] ? max($peaks) : null,
            'days' => count($daily_series),
            'last_clock' => $last_clock
        ];
    }

    /**
     * Convert a tag list to a simple key/value map.
     */
    private function mapTags(array $tags): array {
        $map = [];

        foreach ($tags as $tag) {
            $tag_name = (string) ($tag['tag'] ?? '');
            $tag_value = (string) ($tag['value'] ?? '');

            if ($tag_name !== '' && !array_key_exists($tag_name, $map)) {
                $map[$tag_name] = $tag_value;
            }
        }

        return $map;
    }

    /**
     * Match repository item keys to logical fields.
     */
    private function matchRepositoryField(string $key): ?string {
        $map = [
            'veeam.repository.backup.size.24h[' => self::METRIC_24H,
            'veeam.repository.backup.size.31d[' => self::METRIC_31D,
            'veeam.repository.backup.files.31d[' => 'files31d',
            'veeam.repository.capacity.gb[' => 'capacityGb',
            'veeam.repository.used.gb[' => 'usedGb',
            'veeam.repository.free.gb[' => 'freeGb',
            'veeam.repository.free.pct[' => 'freePct',
            'veeam.repository.online[' => 'online',
            'veeam.repository.outofdate[' => 'outOfDate'
        ];

        foreach ($map as $prefix => $field) {
            if (str_starts_with($key, $prefix)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Match protected object item keys to logical fields.
     */
    private function matchObjectField(string $key): ?string {
        $map = [
            'veeam.backup.object.size.24h[' => self::METRIC_24H,
            'veeam.backup.object.size.31d[' => self::METRIC_31D,
            'veeam.backup.object.restorepoints.31d[' => 'restorepoints31d',
            'veeam.backup.object.backupfiles.31d[' => 'backupfiles31d',
            'veeam.backup.object.last.backup[' => 'lastBackup',
            'veeam.backup.object.repositories[' => 'repositories',
            'veeam.backup.object.attribution[' => 'attribution'
        ];

        foreach ($map as $prefix => $field) {
            if (str_starts_with($key, $prefix)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Extract the parameter value from key[param].
     */
    private function extractKeyParameter(string $key): string {
        if (preg_match('/\[(.*)\]$/', $key, $matches) === 1) {
            return (string) $matches[1];
        }

        return $key;
    }

    /**
     * Parse the current numeric value from an item.
     */
    private function itemLastNumeric(?array $item): ?float {
        if ($item === null) {
            return null;
        }

        $value = trim((string) ($item['lastvalue'] ?? ''));
        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Sorting helper for descending numeric order with NULLs last.
     */
    private function sortDescByNumeric($a, $b): int {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        return $a < $b ? 1 : ($a > $b ? -1 : 0);
    }

    /**
     * Escape HTML.
     */
    private static function h(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format bytes.
     */
    public function formatBytes($bytes, int $precision = 2): string {
        if ($bytes === null) {
            return '—';
        }

        $bytes = (float) $bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $precision, '.', ' ').' '.$units[$index];
    }

    /**
     * Format a general number.
     */
    public function formatNumber($value, int $precision = 2): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $precision, '.', ' ');
    }

    /**
     * Format an integer-looking number.
     */
    public function formatInt($value): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, 0, '.', ' ');
    }

    /**
     * Format a percentage.
     */
    public function formatPct($value, int $precision = 2): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $precision, '.', ' ').'%';
    }

    /**
     * Format a timestamp.
     */
    public function formatDateTime($timestamp): string {
        if ($timestamp === null || (int) $timestamp <= 0) {
            return '—';
        }

        return date('Y-m-d H:i:s', (int) $timestamp);
    }
}
