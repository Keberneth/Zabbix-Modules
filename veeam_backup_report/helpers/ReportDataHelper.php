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

    /**
     * Hard upper bound on rows held in memory across ALL chunks of one fetch.
     * A 600-item trend fetch over a year is ~370 MB of raw rows, well past the
     * usual 128M PHP limit, and the per-call cap does nothing about it.
     */
    private const MAX_TOTAL_ROWS = 600000;

    /**
     * Objects whose daily series is fetched. The breakdown, the by-type chart
     * and the object table all read from this one computation, so the cap is
     * reported to the user rather than silently applied.
     */
    private const MAX_SERIES_OBJECTS = 600;

    /** Repository free-space thresholds, mirroring {$VEEAM.REPO.FREE.PCT.MIN}. */
    private const REPO_FREE_WARN_PCT = 15.0;
    private const REPO_FREE_CRIT_PCT = 7.0;

    /** Repositories whose used-space series is fetched for the growth chart. */
    private const MAX_SERIES_REPOSITORIES = 200;

    /** Capacity spread below which two repository readings are the same disk. */
    private const REPO_CAPACITY_TOLERANCE = 0.02;

    /** A storage forecast is only shown when it lands inside this horizon. */
    private const FORECAST_HORIZON_DAYS = 730;

    /** True when any history/trend fetch hit its row cap (results may be partial). */
    private bool $limit_reached = false;

    /** Repository top-N accounting for the "showing top N" notice. */
    private int $repo_filtered = 0;
    private int $repo_shown = 0;

    /** Protected objects dropped by the series cap. */
    private int $objects_capped = 0;

    /** True when the repository used-space series hit its entity cap. */
    private bool $repositories_capped = false;

    /** entity id => final repository group key, set by buildRepositoryGroups(). */
    private array $repository_group_keys = [];

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

    private BackupTypeClassifier $classifier;

    public function __construct() {
        $this->classifier = new BackupTypeClassifier();
    }

    /**
     * Get the default filter state.
     */
    public static function getDefaultFilter(): array {
        $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $month = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');

        return [
            'mode' => 'days_back',
            'month' => $month,
            'date_from' => '',
            'date_to' => '',
            'days_back' => 30,
            'hostids' => [],
            'types' => [],
            'source' => self::SOURCE_AUTO,
            'metric' => self::METRIC_31D,
            'top' => 100,
            'stale_hours' => 26,
            'object_search' => '',
            'repo_search' => '',
            'tab' => 'overview'
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

        $allowed_tabs = ['overview', 'jobs', 'repositories', 'objects', 'growth'];
        if (!in_array($filter['tab'], $allowed_tabs, true)) {
            $filter['tab'] = $default['tab'];
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
        $filter['stale_hours'] = max(1, min(720, (int) $filter['stale_hours']));
        $filter['object_search'] = trim((string) $filter['object_search']);
        $filter['repo_search'] = trim((string) $filter['repo_search']);

        $hostids = [];
        foreach ((array) $filter['hostids'] as $hostid) {
            if (is_scalar($hostid) && preg_match('/^\d+$/', (string) $hostid)) {
                $hostids[] = (string) $hostid;
            }
        }
        $filter['hostids'] = array_values(array_unique($hostids));

        $types = [];
        foreach ((array) $filter['types'] as $type) {
            if (!is_scalar($type)) {
                continue;
            }
            $type = (string) $type;
            if ($type !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $type) === 1) {
                $types[] = $type;
            }
        }
        $filter['types'] = array_values(array_unique($types));

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
            ? _('Protected data (rolling 31 days)')
            : _('Data written (last 24 hours)');
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
        $this->objects_capped = 0;
        $this->repositories_capped = false;
        $this->repository_group_keys = [];

        $host_options = $this->getAvailableHosts();
        // Always strings: PHP turns the numeric-string keys of $host_options
        // into ints, and the views compare these with a strict in_array().
        $selected_hostids = $filter['hostids'] !== []
            ? array_values(array_intersect($filter['hostids'], array_map('strval', array_keys($host_options))))
            : array_map('strval', array_keys($host_options));

        $report = $this->emptyReport($filter);
        $report['host_options'] = $host_options;
        $report['selected_hostids'] = $selected_hostids;
        $report['source_requested'] = $filter['source'];
        $report['source_used'] = $this->resolveSource($filter['source'], $time_from, $time_to);
        $report['period'] = [
            'from' => $time_from,
            'to' => $time_to,
            'days' => max(1, (int) ceil(($time_to - $time_from) / 86400)),
            'label' => $this->formatPeriodLabel($time_from, $time_to)
        ];

        if ($selected_hostids === []) {
            $report['warnings'][] = _('No Veeam hosts with the backup-report items were found. Apply the v13 template to a host, wait for data, and refresh the page.');

            return $report;
        }

        $classified = $this->getClassifiedItems($selected_hostids);
        $source_mode = $report['source_used'];

        // One colour slot per Veeam server, fixed by its place in the complete
        // host list so the palette does not shift when servers are filtered.
        $slot_map = [];
        $slot = 0;
        foreach (array_keys($host_options) as $hostid) {
            $slot_map[(string) $hostid] = $slot % ChartRenderer::SERIES_SLOTS;
            $slot++;
        }

        // ---- Veeam servers -------------------------------------------------
        $daily_info = $this->buildDailyTotals($classified['sources'], $time_from, $time_to, $source_mode, $slot_map);
        $report['daily'] = $daily_info['rows'];
        $report['daily_by_host'] = $daily_info['by_host'];
        $report['totals'] = $daily_info['totals'];
        $report['source_hosts'] = $this->buildSourceHostRows(
            $classified['sources'], $filter['metric'], $daily_info['series']
        );

        // ---- Repositories --------------------------------------------------
        $report['repositories'] = $this->buildRepositoryRows(
            $classified['repositories'], $filter['metric'], $time_from, $time_to, $source_mode,
            $filter['repo_search'], $filter['top']
        );
        $report['repo_groups'] = $this->buildRepositoryGroups($report['repositories']);
        $report['storage'] = $this->buildStorageSummary($report['repo_groups']);
        $storage_series = $this->buildStorageSeries(
            $classified['repositories'], $report['repositories'], $time_from, $time_to, $source_mode
        );
        $report['storage_series'] = $storage_series;
        $report['storage_forecast'] = $this->buildStorageForecast($storage_series, $report['storage']);

        // ---- Protected objects, workload types -----------------------------
        $objects_info = $this->buildObjectRows(
            $classified['objects'], $filter, $time_from, $time_to, $source_mode
        );
        $report['objects'] = $objects_info['rows'];
        $report['objects_total'] = $objects_info['total'];
        $report['objects_filtered'] = $objects_info['filtered'];
        $report['objects_shown'] = $objects_info['shown'];
        $report['type_options'] = $objects_info['type_options'];
        $report['selected_types'] = $objects_info['selected_types'];
        $report['type_breakdown'] = $objects_info['type_breakdown'];
        $report['type_daily'] = $objects_info['type_daily'];
        $report['objects_with_data'] = $objects_info['with_data'];
        $report['object_health'] = $objects_info['health'];
        $report['growers'] = $objects_info['growers'];

        // ---- Jobs ----------------------------------------------------------
        $report['jobs'] = $this->buildJobRows($classified['jobs'], (int) $filter['stale_hours']);
        $report['job_health'] = $this->buildJobHealth($report['jobs']);

        // ---- Roll-ups ------------------------------------------------------
        $report['growth'] = $this->buildGrowth($report['daily'], $filter['metric'], $report['totals']);
        $report['cards'] = $this->buildCards($report, $filter);
        $report['attention'] = $this->buildAttention($report, $filter);
        $report['warnings'] = array_merge($report['warnings'], $this->buildWarnings($report, $filter));

        return $report;
    }

    /**
     * Empty-but-renderable report skeleton, also used by the error path.
     */
    public function emptyReport(array $filter): array {
        return [
            'host_options' => [],
            'selected_hostids' => [],
            'type_options' => [],
            'selected_types' => [],
            'source_requested' => $filter['source'] ?? self::SOURCE_AUTO,
            'source_used' => $filter['source'] ?? self::SOURCE_AUTO,
            'period' => ['from' => 0, 'to' => 0, 'days' => 0, 'label' => ''],
            'cards' => [],
            'daily' => [],
            'daily_by_host' => ['dates' => [], 'series' => []],
            'totals' => ['start' => [], 'end' => [], 'stale_hosts' => [], 'complete' => true],
            'type_breakdown' => [],
            'type_daily' => ['dates' => [], 'series' => []],
            'source_hosts' => [],
            'repositories' => [],
            'repo_groups' => [],
            'storage' => [
                'capacity_gb' => null, 'used_gb' => null, 'free_gb' => null, 'used_pct' => null,
                'reported_capacity_gb' => null, 'dedup_saving_gb' => 0.0, 'shared_count' => 0,
                'online' => 0, 'offline' => 0
            ],
            'storage_series' => ['dates' => [], 'values' => []],
            'storage_forecast' => [
                'dates' => [], 'values' => [], 'full_date' => null, 'days_to_full' => null,
                'growth_gb_day' => null, 'status' => 'unavailable'
            ],
            'objects' => [],
            'objects_total' => 0,
            'objects_filtered' => 0,
            'objects_shown' => 0,
            'objects_with_data' => 0,
            'object_health' => ['overdue' => 0, 'unknown' => 0, 'critical' => [], 'warning' => []],
            'growers' => [],
            'jobs' => [],
            'job_health' => ['success' => 0, 'warning' => 0, 'failed' => 0, 'none' => 0, 'total' => 0, 'rate' => null],
            'growth' => ['start' => null, 'end' => null, 'change' => null, 'pct' => null, 'per_day' => null, 'per_month' => null],
            'attention' => [],
            'warnings' => [],
            'error' => null
        ];
    }

    /**
     * Format a date range label.
     */
    public function formatPeriodLabel(int $time_from, int $time_to): string {
        if ($time_from <= 0 || $time_to <= 0) {
            return '';
        }

        return date('j M Y', $time_from).' – '.date('j M Y', $time_to);
    }

    // ------------------------------------------------------------------ items

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

        return $span > self::AUTO_TRENDS_DAYS * 86400
            ? self::SOURCE_TRENDS
            : self::SOURCE_HISTORY;
    }

    /**
     * Query the Veeam template items from the selected hosts and classify them
     * into Veeam servers, repositories, protected objects and jobs.
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
        $jobs = [];

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

        foreach ($items as $item) {
            $host = $item['hosts'][0] ?? ['hostid' => (string) $item['hostid'], 'name' => (string) $item['hostid']];
            $hostid = (string) $host['hostid'];
            $hostname = (string) $host['name'];
            $key = (string) $item['key_'];
            $name = (string) $item['name'];
            $tags = $this->mapTags((array) ($item['tags'] ?? []));

            if (!array_key_exists($hostid, $sources)) {
                $sources[$hostid] = ['hostid' => $hostid, 'host' => $hostname, 'items' => []];
            }

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
                        'repo_type' => (string) ($tags['type'] ?? ''),
                        'path' => (string) ($tags['path'] ?? ''),
                        'items' => []
                    ];
                }

                $repositories[$entity_id]['items'][$repo_field] = $item;

                // Templates that predate the path/type tags still carry both in
                // the item name: "Repository [NAME] [TYPE]: Capacity [PATH]".
                if ($repositories[$entity_id]['repo_type'] === '') {
                    $repositories[$entity_id]['repo_type'] = $this->parseBracket($name, 2);
                }
                if ($repositories[$entity_id]['path'] === '') {
                    $repositories[$entity_id]['path'] = $this->parseTrailingBracket($name);
                }
                if ($repositories[$entity_id]['repository'] === '') {
                    $repositories[$entity_id]['repository'] = $this->parseBracket($name, 1);
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
                        'obj_type' => (string) ($tags['type'] ?? ''),
                        'items' => []
                    ];
                }

                $objects[$entity_id]['items'][$object_field] = $item;
                if ($objects[$entity_id]['object'] === '') {
                    $objects[$entity_id]['object'] = $this->parseBracket($name, 1);
                }
                if ($objects[$entity_id]['platform'] === '') {
                    $objects[$entity_id]['platform'] = $this->parseBracket($name, 2);
                }
                continue;
            }

            $job_field = $this->matchJobField($key);
            if ($job_field !== null) {
                $entity_id = $hostid.'|'.$this->extractKeyParameter($key);
                if (!array_key_exists($entity_id, $jobs)) {
                    $jobs[$entity_id] = [
                        'entity_id' => $entity_id,
                        'hostid' => $hostid,
                        'host' => $hostname,
                        'job' => (string) ($tags['job'] ?? $this->extractKeyParameter($key)),
                        'job_type' => (string) ($tags['type'] ?? ''),
                        'workload' => (string) ($tags['workload'] ?? ''),
                        'items' => []
                    ];
                }

                $jobs[$entity_id]['items'][$job_field] = $item;
                if ($jobs[$entity_id]['job'] === '') {
                    $jobs[$entity_id]['job'] = $this->parseBracket($name, 1);
                }
                if ($jobs[$entity_id]['job_type'] === '') {
                    $jobs[$entity_id]['job_type'] = $this->parseBracket($name, 2);
                }
            }
        }

        return [
            'sources' => $sources,
            'repositories' => $repositories,
            'objects' => $objects,
            'jobs' => $jobs
        ];
    }

    // -------------------------------------------------------- Veeam servers

    /**
     * Daily totals across the selected Veeam servers, plus the per-server
     * split that feeds the stacked "written per day" chart.
     *
     * @return array{rows:array,by_host:array}
     */
    private function buildDailyTotals(array $sources, int $time_from, int $time_to, string $source_mode,
            array $slot_map = []): array {
        $fields = [self::METRIC_24H, self::METRIC_31D, 'assigned31d', 'shared31d'];

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
                $rows_by_itemid[$itemid] ?? [], $item, $time_from, $time_to, $source_mode
            );
        }

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

        $dates = array_keys($days);
        sort($dates);

        $rows = [];
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

        // Headline totals are deliberately NOT the last daily row.
        //
        // A daily row only sums the servers that happened to have a sample that
        // day, so one server whose collection stopped mid-period would silently
        // subtract its entire share from "Protected data" and show up as a huge
        // negative growth. Rolling footprints are gauges, so the honest total is
        // each server's last known value inside the period, added up - and the
        // servers that are stale get reported rather than quietly dropped.
        $totals = ['start' => [], 'end' => [], 'stale_hosts' => [], 'complete' => true];
        $last_date = $dates === [] ? null : end($dates);

        foreach ($fields as $field) {
            $totals['start'][$field] = null;
            $totals['end'][$field] = null;

            foreach (($series[$field] ?? []) as $entity_id => $daily_series) {
                if ($daily_series === []) {
                    continue;
                }

                $first = reset($daily_series);
                $last = end($daily_series);

                $totals['start'][$field] = (float) ($totals['start'][$field] ?? 0.0) + (float) ($first['last'] ?? 0.0);
                $totals['end'][$field] = (float) ($totals['end'][$field] ?? 0.0) + (float) ($last['last'] ?? 0.0);

                if ($field === self::METRIC_31D && $last_date !== null && !isset($daily_series[$last_date])) {
                    $host_name = (string) ($sources[$entity_id]['host'] ?? $entity_id);
                    $totals['stale_hosts'][$host_name] = (string) array_key_last($daily_series);
                    $totals['complete'] = false;
                }
            }
        }

        // Per-server split of "data written per day".
        //
        // The colour slot comes from the server's position in the FULL host
        // list, not its position in this (already filtered) array, so
        // deselecting one server never repaints the ones that remain.
        $by_host = ['dates' => $dates, 'series' => []];
        foreach ($sources as $hostid => $source) {
            $slot = (int) ($slot_map[(string) $hostid] ?? 0);
            $values = [];
            $has_data = false;
            foreach ($dates as $date) {
                $value = (float) ($series[self::METRIC_24H][$hostid][$date]['last'] ?? 0.0);
                $values[] = $value;
                $has_data = $has_data || $value > 0;
            }

            if (!$has_data) {
                continue;
            }

            $by_host['series'][] = [
                'hostid' => (string) $hostid,
                'label' => (string) $source['host'],
                'token' => ChartRenderer::seriesToken($slot),
                'values' => $values
            ];
        }

        return ['rows' => $rows, 'by_host' => $by_host, 'totals' => $totals, 'series' => $series];
    }

    /**
     * Build per-Veeam host summary rows.
     */
    private function buildSourceHostRows(array $sources, string $metric, array $daily_series): array {
        $rows = [];

        // buildDailyTotals() already fetched every one of these itemids as part
        // of its four-field batch, so reuse those series rather than issuing a
        // second, identical history/trend round-trip.
        $series = $daily_series[$metric] ?? [];

        foreach ($sources as $source) {
            $hostid = (string) $source['hostid'];
            $stats = $this->summarizeDailySeries($series[$hostid] ?? [], $source['items'][$metric] ?? null);

            $assigned = $this->itemLastNumeric($source['items']['assigned31d'] ?? null);
            $shared = $this->itemLastNumeric($source['items']['shared31d'] ?? null);
            if ($assigned !== null && $shared !== null && ($assigned + $shared) > 0.0) {
                $coverage = ($assigned / ($assigned + $shared)) * 100.0;
            }
            else {
                $coverage = $this->itemLastNumeric($source['items']['coverage'] ?? null);
            }

            $rows[] = [
                'hostid' => $hostid,
                'host' => (string) $source['host'],
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'spark' => $stats['spark'],
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

    // ---------------------------------------------------------- repositories

    /**
     * Build repository summary rows (one row per repository per Veeam server).
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
            $haystack = strtolower($repository['host'].' '.$repository['repository'].' '.$repository['path'].' '.$repository['repo_type']);
            if ($search !== '' && strpos($haystack, strtolower($search)) === false) {
                continue;
            }

            if (!isset($repository['items'][$metric])) {
                continue;
            }

            $filtered_entities[$entity_id] = $repository;
        }

        $this->repo_filtered = count($filtered_entities);

        // Pre-sort by current metric value and keep only the top-N before
        // pulling any history/trend series.
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
            $stats = $this->summarizeDailySeries($series[$entity_id] ?? [], $repository['items'][$metric] ?? null);

            if ($stats['days'] === 0) {
                continue;
            }

            $capacity = $this->itemLastNumeric($repository['items']['capacityGb'] ?? null);
            $used = $this->itemLastNumeric($repository['items']['usedGb'] ?? null);
            $free = $this->itemLastNumeric($repository['items']['freeGb'] ?? null);
            $free_pct = $this->itemLastNumeric($repository['items']['freePct'] ?? null);

            // The template returns 0 for free percent when Veeam reports no
            // capacity at all ("if (capacity <= 0) return 0"), which is an
            // unknown, not a full disk. Object-storage and capacity-tier
            // repositories hit this, and taking it literally paints them red.
            if ($free_pct !== null && $free_pct == 0.0 && ($capacity === null || $capacity <= 0)) {
                $free_pct = null;
            }
            if ($free_pct === null && $capacity !== null && $capacity > 0 && $free !== null) {
                $free_pct = ($free / $capacity) * 100.0;
            }

            $online_raw = $this->itemLastNumeric($repository['items']['online'] ?? null);
            $outofdate_raw = $this->itemLastNumeric($repository['items']['outOfDate'] ?? null);

            $rows[] = [
                'entity_id' => $entity_id,
                'hostid' => (string) $repository['hostid'],
                'host' => (string) $repository['host'],
                'repository' => (string) $repository['repository'],
                'repo_type' => (string) $repository['repo_type'],
                'path' => (string) $repository['path'],
                'group_key' => $this->repositoryGroupKey($repository),
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'spark' => $stats['spark'],
                'files_31d' => $this->itemLastNumeric($repository['items']['files31d'] ?? null),
                'capacity_gb' => $capacity,
                'used_gb' => $used,
                'free_gb' => $free,
                'free_pct' => $free_pct,
                'online' => $online_raw === null ? null : ($online_raw === 1.0),
                'out_of_date' => $outofdate_raw === null ? null : ($outofdate_raw === 1.0),
                'state' => $this->repositoryState($online_raw, $free_pct),
                'last_clock' => $stats['last_clock']
            ];
        }

        usort($rows, fn(array $a, array $b): int => $this->sortDescByNumeric($a['metric_end'], $b['metric_end']));

        $this->repo_shown = count($rows);

        return $rows;
    }

    /**
     * Collapse repositories that are the same physical storage seen from more
     * than one Veeam server.
     *
     * Three VBR servers mounting one repository disk each report its full
     * capacity, so naively summing the per-server totals multiplies the array
     * by three. Grouping on path (falling back to name) counts the disk once
     * while still summing the backup data each server writes to it.
     */
    /**
     * Second half of the identity test: separate repositories that share a name
     * and a path but are plainly different disks.
     *
     * The comparison runs over each name+path bucket sorted by capacity, and
     * starts a new sub-group wherever the step to the next capacity exceeds the
     * tolerance. Sorting first is what makes the result independent of the
     * order the rows happened to arrive in - comparing each row against
     * "whichever one got here first" would group 100/101/103 GB differently
     * depending on which server was listed first.
     *
     * The tolerance is deliberately loose: two servers reading the same disk
     * seconds apart differ by rounding, not by percent.
     *
     * @return array<int,array{0:string,1:array}> [group key, row] pairs
     */
    private function splitGroupsByCapacity(array $repository_rows): array {
        $buckets = [];
        foreach ($repository_rows as $row) {
            $buckets[(string) $row['group_key']][] = $row;
        }

        $assigned = [];

        foreach ($buckets as $key => $rows) {
            usort($rows, static function(array $a, array $b): int {
                $ca = $a['capacity_gb'] === null ? -1.0 : (float) $a['capacity_gb'];
                $cb = $b['capacity_gb'] === null ? -1.0 : (float) $b['capacity_gb'];

                return $ca <=> $cb ?: strcmp((string) $a['entity_id'], (string) $b['entity_id']);
            });

            $sub = 0;
            $anchor = null;

            foreach ($rows as $row) {
                $capacity = $row['capacity_gb'] === null ? null : (float) $row['capacity_gb'];

                if ($capacity !== null) {
                    if ($anchor === null) {
                        $anchor = $capacity;
                    }
                    elseif ($anchor > 0
                            && abs($capacity - $anchor) / $anchor > self::REPO_CAPACITY_TOLERANCE) {
                        $sub++;
                        $anchor = $capacity;
                    }
                }

                $assigned[] = [$key.($sub > 0 ? '#'.$sub : ''), $row];
            }
        }

        return $assigned;
    }

    private function buildRepositoryGroups(array $repository_rows): array {
        $groups = [];
        $this->repository_group_keys = [];

        foreach ($this->splitGroupsByCapacity($repository_rows) as [$key, $row]) {
            // Record the group this row landed in, including any capacity
            // split, so every consumer resolves the same identity.
            $this->repository_group_keys[(string) $row['entity_id']] = $key;

            if (!array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'group_key' => $key,
                    'repository' => (string) $row['repository'],
                    'repo_type' => (string) $row['repo_type'],
                    'path' => (string) $row['path'],
                    'hosts' => [],
                    'capacity_gb' => null,
                    'used_gb' => null,
                    'free_gb' => null,
                    'free_pct' => null,
                    'written_period' => 0.0,
                    'files_31d' => 0.0,
                    'online' => null,
                    'out_of_date' => false,
                    'members' => 0,
                    'last_clock' => 0
                ];
            }

            $group = &$groups[$key];
            $group['members']++;
            if (!in_array($row['host'], $group['hosts'], true)) {
                $group['hosts'][] = (string) $row['host'];
            }

            // One physical disk: take the widest reported capacity/used rather
            // than adding them up.
            $group['capacity_gb'] = $this->maxNullable($group['capacity_gb'], $row['capacity_gb']);
            $group['used_gb'] = $this->maxNullable($group['used_gb'], $row['used_gb']);
            $group['free_gb'] = $this->minNullable($group['free_gb'], $row['free_gb']);

            // Bytes written, however, really are per server and do add up.
            $group['written_period'] += (float) ($row['metric_end'] ?? 0.0);
            $group['files_31d'] += (float) ($row['files_31d'] ?? 0.0);

            if ($row['online'] === false) {
                $group['online'] = false;
            }
            elseif ($group['online'] === null && $row['online'] === true) {
                $group['online'] = true;
            }
            if ($row['out_of_date'] === true) {
                $group['out_of_date'] = true;
            }
            $group['last_clock'] = max((int) $group['last_clock'], (int) $row['last_clock']);
            unset($group);
        }

        foreach ($groups as $key => $group) {
            $capacity = $group['capacity_gb'];
            $free = $group['free_gb'];
            if ($capacity !== null && $capacity > 0) {
                if ($free === null && $group['used_gb'] !== null) {
                    $free = max(0.0, $capacity - $group['used_gb']);
                    $groups[$key]['free_gb'] = $free;
                }
                $groups[$key]['free_pct'] = $free !== null ? ($free / $capacity) * 100.0 : null;
                $groups[$key]['used_pct'] = $group['used_gb'] !== null
                    ? min(100.0, ($group['used_gb'] / $capacity) * 100.0)
                    : null;
            }
            else {
                $groups[$key]['used_pct'] = null;
            }

            $groups[$key]['shared'] = count($group['hosts']) > 1;
            $groups[$key]['state'] = $this->repositoryState(
                $group['online'] === null ? null : ($group['online'] ? 1.0 : 0.0),
                $groups[$key]['free_pct']
            );
        }

        uasort($groups, fn(array $a, array $b): int => $this->sortDescByNumeric($a['capacity_gb'], $b['capacity_gb']));

        return array_values($groups);
    }

    /**
     * Storage totals with the shared-disk double count removed.
     */
    private function buildStorageSummary(array $groups): array {
        $capacity = 0.0;
        $used = 0.0;
        $free = 0.0;
        $reported = 0.0;
        $shared = 0;
        $online = 0;
        $offline = 0;
        $seen = false;

        foreach ($groups as $group) {
            if ($group['capacity_gb'] !== null) {
                $capacity += (float) $group['capacity_gb'];
                $reported += (float) $group['capacity_gb'] * max(1, count($group['hosts']));
                $seen = true;
            }
            if ($group['used_gb'] !== null) {
                $used += (float) $group['used_gb'];
            }
            if ($group['free_gb'] !== null) {
                $free += (float) $group['free_gb'];
            }
            if ($group['shared']) {
                $shared++;
            }
            if ($group['online'] === false) {
                $offline++;
            }
            else {
                $online++;
            }
        }

        return [
            'capacity_gb' => $seen ? $capacity : null,
            'used_gb' => $seen ? $used : null,
            'free_gb' => $seen ? $free : null,
            'used_pct' => ($seen && $capacity > 0) ? min(100.0, ($used / $capacity) * 100.0) : null,
            'reported_capacity_gb' => $seen ? $reported : null,
            'dedup_saving_gb' => max(0.0, $reported - $capacity),
            'shared_count' => $shared,
            'online' => $online,
            'offline' => $offline
        ];
    }

    /**
     * Deduplicated "used space" across all physical repositories per day.
     *
     * @return array{dates:array,values:array}
     */
    private function buildStorageSeries(array $repositories, array $repository_rows, int $time_from, int $time_to,
            string $source_mode): array {
        $empty = ['dates' => [], 'values' => []];

        // Use exactly the repositories the capacity figures were built from.
        // Reading the series off the unfiltered set instead would draw a chart
        // that disagrees with the capacity line printed on it whenever a
        // repository search, the row limit or the capacity split excluded one.
        $group_of = [];
        foreach ($repository_rows as $row) {
            $entity_id = (string) $row['entity_id'];
            $group_of[$entity_id] = $this->repository_group_keys[$entity_id] ?? (string) $row['group_key'];
        }

        $used_items = [];
        foreach ($repositories as $entity_id => $repository) {
            $entity_id = (string) $entity_id;
            if (!isset($repository['items']['usedGb'], $group_of[$entity_id])) {
                continue;
            }
            $used_items[$entity_id] = $repository['items']['usedGb'];
        }

        if ($used_items === []) {
            return $empty;
        }

        // used.gb rides the 5-minute metrics item, so the raw row count is
        // large even though the result is one point per day. Bound it.
        if (count($used_items) > self::MAX_SERIES_REPOSITORIES) {
            $used_items = array_slice($used_items, 0, self::MAX_SERIES_REPOSITORIES, true);
            $this->repositories_capped = true;
        }

        $series = $this->fetchDailySeriesByEntity($used_items, $time_from, $time_to, $source_mode);

        // Per day, one value per physical disk (the largest reading any server
        // gave for it), then summed across disks.
        $per_day = [];
        $group_dates = [];
        foreach ($series as $entity_id => $daily) {
            $group = $group_of[(string) $entity_id] ?? (string) $entity_id;
            foreach ($daily as $date => $point) {
                $value = (float) ($point['last'] ?? 0.0);
                if (!isset($per_day[$date][$group]) || $value > $per_day[$date][$group]) {
                    $per_day[$date][$group] = $value;
                }
                $group_dates[$group][$date] = true;
            }
        }

        if ($per_day === []) {
            return $empty;
        }

        ksort($per_day);
        $all_dates = array_keys($per_day);

        // A disk that only starts reporting mid-window would otherwise add its
        // whole capacity as a one-day step, which the least-squares fit reads
        // as real growth. Start the series where every disk is present, and
        // carry each disk's last reading forward across days it did not report
        // (used space does not vanish because a poll was missed).
        $start_index = 0;
        foreach ($group_dates as $group => $dates_seen) {
            $first = (string) array_key_first($dates_seen);
            $index = array_search($first, $all_dates, true);
            if ($index !== false && $index > $start_index) {
                $start_index = (int) $index;
            }
        }

        $dates = [];
        $values = [];
        $carried = [];

        foreach (array_slice($all_dates, $start_index) as $date) {
            foreach ($per_day[$date] as $group => $value) {
                $carried[$group] = $value;
            }

            if (count($carried) < count($group_dates)) {
                continue;
            }

            $dates[] = (string) $date;
            $values[] = array_sum($carried);
        }

        return ['dates' => $dates, 'values' => $values];
    }

    /**
     * Least-squares projection of the deduplicated used-space series.
     */
    private function buildStorageForecast(array $series, array $storage): array {
        $empty = [
            'dates' => [], 'values' => [], 'full_date' => null, 'days_to_full' => null,
            'growth_gb_day' => null, 'status' => 'unavailable'
        ];

        $values = $series['values'] ?? [];
        $dates = $series['dates'] ?? [];
        $n = count($values);

        if ($n < 5) {
            return $empty;
        }

        // Regress against real elapsed days rather than the array index. A
        // period with gaps in history has fewer points than days, and indexing
        // would silently understate growth - which is the one number the
        // "storage runs out" headline rests on.
        $first_ts = strtotime((string) $dates[0]);
        if ($first_ts === false) {
            return $empty;
        }

        $x_values = [];
        foreach ($dates as $i => $date) {
            $timestamp = strtotime((string) $date);
            if ($timestamp === false) {
                return $empty;
            }
            $x_values[$i] = ($timestamp - $first_ts) / 86400.0;
        }

        $sum_x = 0.0;
        $sum_y = 0.0;
        $sum_xy = 0.0;
        $sum_xx = 0.0;
        foreach ($values as $i => $value) {
            $x = $x_values[$i];
            $sum_x += $x;
            $sum_y += (float) $value;
            $sum_xy += $x * (float) $value;
            $sum_xx += $x * $x;
        }

        $denominator = ($n * $sum_xx) - ($sum_x * $sum_x);
        if ($denominator == 0.0) {
            return $empty;
        }

        // x is in days, so the slope is GB per day.
        $slope = (($n * $sum_xy) - ($sum_x * $sum_y)) / $denominator;
        $intercept = ($sum_y - ($slope * $sum_x)) / $n;

        $capacity = $storage['capacity_gb'] ?? null;
        $last_value = (float) end($values);
        $last_date = (string) end($dates);
        $last_x = (float) end($x_values);

        $days_to_full = null;
        $full_date = null;
        if ($capacity !== null && $capacity > 0 && $slope > 0.0 && $last_value < $capacity) {
            $days = (int) ceil(($capacity - $last_value) / $slope);
            if ($days > 0 && $days <= self::FORECAST_HORIZON_DAYS) {
                $days_to_full = $days;
                $full_date = date('Y-m-d', strtotime($last_date.' +'.$days.' day'));
            }
        }

        // Draw the projection for a quarter ahead, or up to the full date.
        $horizon = $days_to_full !== null ? min($days_to_full, 120) : 90;
        $f_dates = [];
        $f_values = [];
        for ($i = 1; $i <= $horizon; $i++) {
            $f_dates[] = date('Y-m-d', strtotime($last_date.' +'.$i.' day'));
            $projected = $intercept + $slope * ($last_x + $i);
            $f_values[] = $capacity !== null ? min($projected, $capacity * 1.02) : $projected;
        }

        // Three outcomes, kept apart on purpose: a date, "growth is too slow
        // to reach capacity inside the horizon", and "no projection could be
        // computed at all". Collapsing the last two into one would tell an
        // operator their storage is fine when in fact nothing was measured.
        if ($days_to_full !== null) {
            $status = 'projected';
        }
        elseif ($capacity !== null && $capacity > 0) {
            $status = 'beyond_horizon';
        }
        else {
            $status = 'unavailable';
        }

        return [
            'dates' => $f_dates,
            'values' => $f_values,
            'full_date' => $full_date,
            'days_to_full' => $days_to_full,
            'growth_gb_day' => $slope,
            'status' => $status
        ];
    }

    // ------------------------------------------------------ protected objects

    /**
     * Protected objects, workload-type options and the type breakdown.
     *
     * The type options are derived from the objects that exist on the selected
     * Veeam servers, so a workload nobody protects is never offered as a
     * filter. Options are computed BEFORE the type filter is applied, or
     * selecting a type would erase every other choice.
     */
    private function buildObjectRows(array $objects, array $filter, int $time_from, int $time_to, string $source_mode): array {
        $metric = (string) $filter['metric'];
        $search = (string) $filter['object_search'];
        $top = (int) $filter['top'];
        $stale_hours = (int) $filter['stale_hours'];

        $result = [
            'rows' => [], 'total' => 0, 'filtered' => 0, 'shown' => 0, 'with_data' => 0,
            'type_options' => [], 'selected_types' => [], 'type_breakdown' => [],
            'type_daily' => ['dates' => [], 'series' => []], 'growers' => [],
            'health' => ['overdue' => 0, 'unknown' => 0, 'critical' => [], 'warning' => []]
        ];

        if ($objects === []) {
            return $result;
        }

        $result['total'] = count($objects);

        // Classify everything first: the type list must describe the data, not
        // the classifier's table.
        foreach ($objects as $entity_id => $object) {
            $class = $this->classifier->classify((string) $object['platform'], (string) $object['obj_type']);
            $objects[$entity_id]['type_key'] = $class['key'];
            $objects[$entity_id]['type_label'] = $class['label'];
            $objects[$entity_id]['type_weight'] = $class['weight'];
        }

        $type_options = [];
        foreach ($objects as $object) {
            $key = (string) $object['type_key'];
            if (!isset($type_options[$key])) {
                $type_options[$key] = [
                    'key' => $key,
                    'label' => (string) $object['type_label'],
                    'weight' => (int) $object['type_weight'],
                    'objects' => 0
                ];
            }
            $type_options[$key]['objects']++;
        }

        uasort($type_options, static function(array $a, array $b): int {
            return $a['weight'] <=> $b['weight'] ?: strcasecmp($a['label'], $b['label']);
        });

        // A stable colour slot per workload type, assigned in the display order
        // of the full option list so the colour of "SQL database" never moves
        // when another type is filtered out.
        $slot = 0;
        foreach ($type_options as $key => $option) {
            $type_options[$key]['token'] = ChartRenderer::seriesToken($slot);
            $slot++;
        }

        $result['type_options'] = array_values($type_options);

        $selected_types = array_values(array_intersect((array) $filter['types'], array_keys($type_options)));
        $result['selected_types'] = $selected_types;

        // Apply the type filter and the free-text search.
        $filtered_entities = [];
        foreach ($objects as $entity_id => $object) {
            if (!isset($object['items'][$metric])) {
                continue;
            }

            if ($selected_types !== [] && !in_array((string) $object['type_key'], $selected_types, true)) {
                continue;
            }

            if ($search !== '') {
                $haystack = strtolower(
                    $object['host'].' '.$object['object'].' '.$object['platform'].' '.$object['type_label'].' '.
                    (string) ($object['items']['repositories']['lastvalue'] ?? '')
                );
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            $filtered_entities[$entity_id] = $object;
        }

        $result['filtered'] = count($filtered_entities);

        // Biggest first, so a truncated series set is still representative.
        uasort($filtered_entities, function(array $a, array $b) use ($metric): int {
            return $this->sortDescByNumeric(
                $this->itemLastNumeric($a['items'][$metric] ?? null),
                $this->itemLastNumeric($b['items'][$metric] ?? null)
            );
        });

        if (count($filtered_entities) > self::MAX_SERIES_OBJECTS) {
            $this->objects_capped = count($filtered_entities) - self::MAX_SERIES_OBJECTS;
            $filtered_entities = array_slice($filtered_entities, 0, self::MAX_SERIES_OBJECTS, true);
        }

        $metric_items = [];
        foreach ($filtered_entities as $entity_id => $object) {
            $metric_items[$entity_id] = $object['items'][$metric];
        }

        $series = $this->fetchDailySeriesByEntity($metric_items, $time_from, $time_to, $source_mode);

        $rows = [];
        $type_totals = [];
        $type_daily = [];
        $all_dates = [];

        foreach ($filtered_entities as $entity_id => $object) {
            $stats = $this->summarizeDailySeries($series[$entity_id] ?? [], $object['items'][$metric] ?? null);

            if ($stats['days'] === 0) {
                continue;
            }

            $type_key = (string) $object['type_key'];
            $last_backup_raw = (string) ($object['items']['lastBackup']['lastvalue'] ?? '');
            $last_backup_ts = $this->parseVeeamTime($last_backup_raw);

            $rows[] = [
                'entity_id' => $entity_id,
                'hostid' => (string) $object['hostid'],
                'host' => (string) $object['host'],
                'object' => (string) $object['object'],
                'platform' => (string) $object['platform'],
                'type_key' => $type_key,
                'type_label' => (string) $object['type_label'],
                'token' => (string) ($type_options[$type_key]['token'] ?? '--vr-s1'),
                'metric_start' => $stats['start'],
                'metric_end' => $stats['end'],
                'metric_change' => $stats['change'],
                'metric_avg' => $stats['avg'],
                'metric_peak' => $stats['peak'],
                'days' => $stats['days'],
                'spark' => $stats['spark'],
                'restorepoints_31d' => $this->itemLastNumeric($object['items']['restorepoints31d'] ?? null),
                'backupfiles_31d' => $this->itemLastNumeric($object['items']['backupfiles31d'] ?? null),
                'last_backup' => $last_backup_raw,
                'last_backup_ts' => $last_backup_ts,
                'age_seconds' => $last_backup_ts !== null ? max(0, time() - $last_backup_ts) : null,
                'freshness' => $this->freshnessState($last_backup_ts, $stale_hours),
                'repositories' => (string) ($object['items']['repositories']['lastvalue'] ?? ''),
                'attribution' => (string) ($object['items']['attribution']['lastvalue'] ?? ''),
                'last_clock' => $stats['last_clock']
            ];

            // Breakdown + by-type daily stack, both from this one computation.
            if (!isset($type_totals[$type_key])) {
                $type_totals[$type_key] = ['bytes' => 0.0, 'objects' => 0, 'change' => 0.0];
            }
            $type_totals[$type_key]['bytes'] += (float) ($stats['end'] ?? 0.0);
            $type_totals[$type_key]['objects']++;
            $type_totals[$type_key]['change'] += (float) ($stats['change'] ?? 0.0);

            foreach (($series[$entity_id] ?? []) as $date => $point) {
                $all_dates[$date] = true;
                $type_daily[$type_key][$date] = ($type_daily[$type_key][$date] ?? 0.0) + (float) ($point['last'] ?? 0.0);
            }
        }

        usort($rows, fn(array $a, array $b): int => $this->sortDescByNumeric($a['metric_end'], $b['metric_end']));

        $grand_total = 0.0;
        foreach ($type_totals as $totals) {
            $grand_total += $totals['bytes'];
        }

        $breakdown = [];
        foreach ($type_options as $key => $option) {
            if (!isset($type_totals[$key])) {
                continue;
            }

            $breakdown[] = [
                'key' => $key,
                'label' => $option['label'],
                'token' => $option['token'],
                'bytes' => $type_totals[$key]['bytes'],
                'change' => $type_totals[$key]['change'],
                'objects' => $type_totals[$key]['objects'],
                'pct' => $grand_total > 0 ? ($type_totals[$key]['bytes'] / $grand_total) * 100.0 : null
            ];
        }

        usort($breakdown, fn(array $a, array $b): int => $this->sortDescByNumeric($a['bytes'], $b['bytes']));

        $dates = array_keys($all_dates);
        sort($dates);

        $type_series = [];
        foreach ($breakdown as $entry) {
            $values = [];
            foreach ($dates as $date) {
                $values[] = (float) ($type_daily[$entry['key']][$date] ?? 0.0);
            }
            $type_series[] = [
                'label' => $entry['label'],
                'token' => $entry['token'],
                'values' => $values
            ];
        }

        // The table is truncated to the row limit, but the health signals and
        // the "fastest growing" ranking must not be: an overdue object sitting
        // at position 101 is exactly the one worth surfacing.
        $result['health'] = $this->summarizeObjectHealth($rows);

        $growers = $rows;
        usort($growers, static fn(array $a, array $b): int => ($b['metric_change'] ?? 0) <=> ($a['metric_change'] ?? 0));
        $result['growers'] = array_slice($growers, 0, 10);

        $result['with_data'] = count($rows);
        $result['rows'] = array_slice($rows, 0, $top);
        $result['shown'] = count($result['rows']);
        $result['type_breakdown'] = $breakdown;
        $result['type_daily'] = ['dates' => $dates, 'series' => $type_series];

        // Options carry the filtered byte totals so the filter can show sizes.
        foreach ($result['type_options'] as $i => $option) {
            $result['type_options'][$i]['bytes'] = $type_totals[$option['key']]['bytes'] ?? null;
        }

        return $result;
    }

    /**
     * Object freshness counted over every matching object, not just the page.
     *
     * @return array{overdue:int,unknown:int,critical:array,warning:array}
     */
    private function summarizeObjectHealth(array $rows): array {
        $health = ['overdue' => 0, 'unknown' => 0, 'critical' => [], 'warning' => []];

        foreach ($rows as $row) {
            switch ($row['freshness']) {
                case 'critical':
                    $health['overdue']++;
                    $health['critical'][] = $row;
                    break;

                case 'warning':
                    $health['overdue']++;
                    $health['warning'][] = $row;
                    break;

                case 'unknown':
                    $health['unknown']++;
                    break;
            }
        }

        return $health;
    }

    // -------------------------------------------------------------- jobs

    /**
     * Backup jobs: what ran, when, and how it ended.
     */
    private function buildJobRows(array $jobs, int $stale_hours): array {
        $rows = [];

        foreach ($jobs as $job) {
            $last_result = trim((string) ($job['items']['lastResult']['lastvalue'] ?? ''));
            $status = trim((string) ($job['items']['status']['lastvalue'] ?? ''));
            $last_run_raw = trim((string) ($job['items']['lastRun']['lastvalue'] ?? ''));
            $next_run_raw = trim((string) ($job['items']['nextRun']['lastvalue'] ?? ''));

            $last_run_ts = $this->parseVeeamTime($last_run_raw);
            $next_run_ts = $this->parseVeeamTime($next_run_raw);

            $rows[] = [
                'hostid' => (string) $job['hostid'],
                'host' => (string) $job['host'],
                'job' => (string) $job['job'],
                'job_type' => (string) $job['job_type'],
                'workload' => (string) $job['workload'],
                'last_result' => $last_result,
                'result_state' => $this->jobResultState($last_result),
                'status' => $status,
                'status_state' => $this->jobStatusState($status),
                'last_run' => $last_run_raw,
                'last_run_ts' => $last_run_ts,
                'age_seconds' => $last_run_ts !== null ? max(0, time() - $last_run_ts) : null,
                'next_run' => $next_run_raw,
                'next_run_ts' => $next_run_ts,
                'freshness' => $this->jobFreshness($last_run_ts, $next_run_ts, $stale_hours),
                'objects_count' => $this->itemLastNumeric($job['items']['objectsCount'] ?? null)
            ];
        }

        // Problems first, then the most recently run.
        $severity = ['failed' => 0, 'warning' => 1, 'none' => 2, 'success' => 3, 'unknown' => 4];
        usort($rows, static function(array $a, array $b) use ($severity): int {
            $cmp = ($severity[$a['result_state']] ?? 9) <=> ($severity[$b['result_state']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($b['last_run_ts'] ?? 0) <=> ($a['last_run_ts'] ?? 0);
        });

        return $rows;
    }

    private function buildJobHealth(array $jobs): array {
        $health = ['success' => 0, 'warning' => 0, 'failed' => 0, 'none' => 0, 'total' => 0, 'rate' => null];

        foreach ($jobs as $job) {
            $health['total']++;
            $state = (string) $job['result_state'];
            if (array_key_exists($state, $health)) {
                $health[$state]++;
            }
            else {
                $health['none']++;
            }
        }

        $graded = $health['success'] + $health['warning'] + $health['failed'];
        if ($graded > 0) {
            $health['rate'] = ($health['success'] / $graded) * 100.0;
        }

        return $health;
    }

    // ---------------------------------------------------------- roll-ups

    private function buildGrowth(array $daily, string $metric, array $totals): array {
        $empty = ['start' => null, 'end' => null, 'change' => null, 'pct' => null, 'per_day' => null, 'per_month' => null];

        $start = $totals['start'][$metric] ?? null;
        $end = $totals['end'][$metric] ?? null;

        if ($start === null || $end === null || $daily === []) {
            return $empty;
        }

        // Endpoints are per-server first/last known values summed, so a server
        // that stopped reporting mid-period cannot masquerade as a cliff.
        $start = (float) $start;
        $end = (float) $end;
        $change = $end - $start;
        $days = max(1, count($daily) - 1);

        return [
            'start' => $start,
            'end' => $end,
            'change' => $change,
            'pct' => $start > 0 ? ($change / $start) * 100.0 : null,
            'per_day' => $change / $days,
            'per_month' => ($change / $days) * 30.0
        ];
    }

    /**
     * The headline numbers. Deliberately few: five tiles that answer "is my
     * backup healthy, how much data is protected, and will I run out of room".
     */
    private function buildCards(array $report, array $filter): array {
        $storage = $report['storage'];
        $health = $report['job_health'];
        $growth = $report['growth'];

        // Each server's last known value inside the period, summed - not the
        // last daily row, which would drop a server that stopped reporting.
        $protected_bytes = $report['totals']['end'][self::METRIC_31D] ?? null;
        $written = $report['totals']['end'][self::METRIC_24H] ?? null;

        $stale = (int) ($report['object_health']['overdue'] ?? 0);

        $cards = [];

        $cards[] = [
            'key' => 'health',
            'label' => _('Backup success rate'),
            'value' => $health['rate'] === null ? '—' : $this->formatPct($health['rate'], 1),
            'sub' => sprintf(
                _('%1$d of %2$d jobs succeeded'),
                (int) $health['success'],
                max(0, (int) $health['success'] + (int) $health['warning'] + (int) $health['failed'])
            ),
            'tone' => $this->rateTone($health['rate'], $health['failed'])
        ];

        $cards[] = [
            'key' => 'protected',
            'label' => _('Protected data'),
            'value' => $this->formatBytes($protected_bytes),
            'sub' => sprintf(_('%1$s protected objects'), $this->formatInt((float) $report['objects_filtered'])),
            'tone' => 'neutral'
        ];

        $cards[] = [
            'key' => 'written',
            'label' => _('Written in last 24 h'),
            'value' => $this->formatBytes($written),
            'sub' => $growth['per_month'] === null
                ? _('Growth unavailable')
                : sprintf(_('Trend %1$s per month'), $this->formatSignedBytes($growth['per_month'])),
            'tone' => 'neutral'
        ];

        $cards[] = [
            'key' => 'storage',
            'label' => _('Repository usage'),
            'value' => $storage['used_pct'] === null ? '—' : $this->formatPct($storage['used_pct'], 1),
            'sub' => sprintf(
                _('%1$s of %2$s used'),
                $this->formatGb($storage['used_gb']),
                $this->formatGb($storage['capacity_gb'])
            ),
            'tone' => $this->usageTone($storage['used_pct'])
        ];

        $forecast = $report['storage_forecast'];
        $forecast_status = (string) ($forecast['status'] ?? 'unavailable');

        if ($forecast_status === 'projected') {
            $forecast_value = sprintf(_('%1$d days'), (int) $forecast['days_to_full']);
            $forecast_sub = sprintf(_('Around %1$s'), date('j M Y', (int) strtotime((string) $forecast['full_date'])));
        }
        elseif ($forecast_status === 'beyond_horizon') {
            $forecast_value = _('Over 2 years');
            $forecast_sub = _('At the current growth rate');
        }
        else {
            $forecast_value = '—';
            $forecast_sub = _('Not enough data to project');
        }

        $cards[] = [
            'key' => 'forecast',
            'label' => _('Repositories full in'),
            'value' => $forecast_value,
            'sub' => $forecast_sub,
            'tone' => $forecast_status === 'unavailable' ? 'neutral' : $this->forecastTone($forecast['days_to_full'])
        ];

        if ($stale > 0) {
            $cards[] = [
                'key' => 'stale',
                'label' => _('Objects needing attention'),
                'value' => $this->formatInt((float) $stale),
                'sub' => sprintf(_('No backup for over %1$d h'), (int) $filter['stale_hours']),
                'tone' => 'warning'
            ];
        }

        return $cards;
    }

    /**
     * A prioritised, plain-language list of everything that is wrong.
     */
    private function buildAttention(array $report, array $filter): array {
        $items = [];

        foreach ($report['jobs'] as $job) {
            if ($job['result_state'] === 'failed') {
                $items[] = [
                    'severity' => 'critical',
                    'scope' => _('Job'),
                    'title' => sprintf(_('%1$s failed'), $job['job']),
                    'detail' => sprintf(
                        _('On %1$s. Last run %2$s.'),
                        $job['host'],
                        $job['last_run_ts'] !== null ? $this->formatAge($job['age_seconds']) : _('unknown')
                    )
                ];
            }
        }

        foreach ($report['repo_groups'] as $group) {
            if ($group['online'] === false) {
                $items[] = [
                    'severity' => 'critical',
                    'scope' => _('Repository'),
                    'title' => sprintf(_('%1$s is offline'), $group['repository']),
                    'detail' => sprintf(_('Reported by %1$s.'), implode(', ', $group['hosts']))
                ];
            }
            elseif ($group['free_pct'] !== null && $group['free_pct'] < self::REPO_FREE_CRIT_PCT) {
                $items[] = [
                    'severity' => 'critical',
                    'scope' => _('Repository'),
                    'title' => sprintf(_('%1$s is almost full'), $group['repository']),
                    'detail' => sprintf(
                        _('%1$s free of %2$s (%3$s).'),
                        $this->formatGb($group['free_gb']),
                        $this->formatGb($group['capacity_gb']),
                        $this->formatPct($group['free_pct'], 1)
                    )
                ];
            }
            elseif ($group['free_pct'] !== null && $group['free_pct'] < self::REPO_FREE_WARN_PCT) {
                $items[] = [
                    'severity' => 'warning',
                    'scope' => _('Repository'),
                    'title' => sprintf(_('%1$s is running low on space'), $group['repository']),
                    'detail' => sprintf(
                        _('%1$s free of %2$s (%3$s).'),
                        $this->formatGb($group['free_gb']),
                        $this->formatGb($group['capacity_gb']),
                        $this->formatPct($group['free_pct'], 1)
                    )
                ];
            }

            if ($group['out_of_date']) {
                $items[] = [
                    'severity' => 'warning',
                    'scope' => _('Repository'),
                    'title' => sprintf(_('%1$s components are out of date'), $group['repository']),
                    'detail' => _('Upgrade the repository components from the Veeam console.')
                ];
            }
        }

        foreach (($report['object_health']['critical'] ?? []) as $object) {
            $items[] = [
                'severity' => 'critical',
                'scope' => _('Protected object'),
                'title' => sprintf(_('%1$s has no recent backup'), $object['object']),
                'detail' => $object['age_seconds'] === null
                    ? sprintf(_('No restore point reported on %1$s.'), $object['host'])
                    : sprintf(_('Last backup %1$s on %2$s.'), $this->formatAge($object['age_seconds']), $object['host'])
            ];
        }

        foreach ($report['jobs'] as $job) {
            if ($job['result_state'] === 'warning') {
                $items[] = [
                    'severity' => 'warning',
                    'scope' => _('Job'),
                    'title' => sprintf(_('%1$s finished with warnings'), $job['job']),
                    'detail' => sprintf(_('On %1$s.'), $job['host'])
                ];
            }
        }

        $forecast = $report['storage_forecast'];
        if ($forecast['days_to_full'] !== null && $forecast['days_to_full'] <= 90) {
            $items[] = [
                'severity' => $forecast['days_to_full'] <= 30 ? 'critical' : 'warning',
                'scope' => _('Capacity'),
                'title' => sprintf(_('Repositories are projected to fill in %1$d days'), (int) $forecast['days_to_full']),
                'detail' => sprintf(
                    _('Growing about %1$s per day. Around %2$s at this rate.'),
                    $this->formatGb($forecast['growth_gb_day']),
                    $forecast['full_date'] !== null ? date('j M Y', (int) strtotime($forecast['full_date'])) : '—'
                )
            ];
        }

        foreach (($report['object_health']['warning'] ?? []) as $object) {
            $items[] = [
                'severity' => 'warning',
                'scope' => _('Protected object'),
                'title' => sprintf(_('%1$s backup is overdue'), $object['object']),
                'detail' => sprintf(_('Last backup %1$s on %2$s.'), $this->formatAge($object['age_seconds']), $object['host'])
            ];
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($items, static fn(array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

        return $items;
    }

    private function buildWarnings(array $report, array $filter): array {
        $warnings = [];

        if ($report['source_used'] === self::SOURCE_TRENDS) {
            $warnings[] = _('Long periods are read from Zabbix hourly trends. Daily values are the last hourly average of each day rather than the exact last raw sample.');
        }

        if ($filter['source'] === self::SOURCE_HISTORY && $report['source_used'] === self::SOURCE_TRENDS) {
            $warnings[] = sprintf(
                _('History was requested, but the selected period is longer than %1$d days. Trends are used instead to keep the report responsive.'),
                self::HISTORY_MAX_DAYS
            );
        }

        if ($this->repo_filtered > $this->repo_shown && $this->repo_filtered > (int) $filter['top']) {
            $warnings[] = sprintf(
                _('Only the top %1$d repositories are shown. Raise the row limit to see the rest.'),
                (int) $filter['top']
            );
        }

        foreach (($report['totals']['stale_hosts'] ?? []) as $host_name => $last_seen) {
            $warnings[] = sprintf(
                _('%1$s last reported on %2$s, before the end of this period. Its totals are carried from that reading.'),
                $host_name,
                $last_seen
            );
        }

        if ($this->repositories_capped) {
            $warnings[] = sprintf(
                _('Only the first %1$d repositories are included in the storage growth chart. Narrow the repository filter for a complete projection.'),
                self::MAX_SERIES_REPOSITORIES
            );
        }

        if ($this->objects_capped > 0) {
            $warnings[] = sprintf(
                _('%1$d protected objects beyond the %2$d largest are excluded from the charts and totals. Narrow the filter for a complete picture.'),
                $this->objects_capped,
                self::MAX_SERIES_OBJECTS
            );
        }

        // Only a real truncation, and phrased with the count actually shown -
        // objects dropped for having no data in the period are a different
        // thing and raising the row limit would not bring them back.
        if ($report['objects_with_data'] > $report['objects_shown']) {
            $warnings[] = sprintf(
                _('The object table lists the largest %1$d of %2$d objects with data in this period. Raise the row limit to see more.'),
                (int) $report['objects_shown'],
                (int) $report['objects_with_data']
            );
        }
        elseif ($report['objects_filtered'] > $report['objects_with_data'] && $report['objects_filtered'] > 0) {
            $warnings[] = sprintf(
                _('%1$d of %2$d matching objects have no backup data in this period and are not counted in the totals.'),
                (int) $report['objects_filtered'] - (int) $report['objects_with_data'],
                (int) $report['objects_filtered']
            );
        }

        if ($this->limit_reached) {
            $warnings[] = sprintf(
                _('Some series hit the %1$s row fetch cap and may be partial. Narrow the period or switch to trends.'),
                number_format(self::MAX_FETCH_ROWS, 0, '.', ' ')
            );
        }

        if ($report['daily'] === [] && $report['source_hosts'] === [] && $report['repositories'] === [] && $report['objects'] === []) {
            $warnings[] = _('No history or trend data was found for this period. Check item retention, change the data source, or pick a more recent period.');
        }

        return $warnings;
    }

    // ------------------------------------------------------------ series I/O

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
        $entities_by_itemid = [];
        foreach ($items_by_entity as $entity_id => $item) {
            $itemid = (string) $item['itemid'];
            $items_by_id[$itemid] = $item;
            $entities_by_itemid[$itemid][] = $entity_id;
        }

        $rows_by_itemid = $this->fetchNumericRows($items_by_id, $time_from, $time_to, $source_mode);

        $series_by_entity = [];
        foreach ($items_by_id as $itemid => $item) {
            $series = $this->buildItemDailySeries(
                $rows_by_itemid[$itemid] ?? [], $item, $time_from, $time_to, $source_mode
            );

            foreach ($entities_by_itemid[$itemid] as $entity_id) {
                $series_by_entity[$entity_id] = $series;
            }
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
        $fetched = 0;

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
                    'limit' => $limit
                ]);

                if (count($rows) >= $limit) {
                    $this->limit_reached = true;
                }

                foreach ($rows as $row) {
                    $itemid = (string) $row['itemid'];
                    $rows_by_itemid[$itemid][] = $row;
                }

                // chunkRowLimit only bounds a single API call; without this the
                // chunks of a wide fetch accumulate until PHP's memory_limit
                // kills the request with a blank page.
                $fetched += count($rows);
                if ($fetched >= self::MAX_TOTAL_ROWS) {
                    $this->limit_reached = true;
                    break;
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

                $fetched += count($rows);
                if ($fetched >= self::MAX_TOTAL_ROWS) {
                    $this->limit_reached = true;
                    break 2;
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
            $daily[$date]['avg'] = $data['avg_count'] > 0 ? $data['avg_sum'] / $data['avg_count'] : null;
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
    private function summarizeDailySeries(array $daily_series, ?array $item): array {
        if ($daily_series === []) {
            return [
                'start' => null, 'end' => null, 'change' => null, 'avg' => null, 'peak' => null,
                'days' => 0, 'spark' => [], 'last_clock' => $item !== null ? (int) ($item['lastclock'] ?? 0) : 0
            ];
        }

        ksort($daily_series);

        $peaks = [];
        $avgs = [];
        $spark = [];
        $last_clock = 0;

        foreach ($daily_series as $data) {
            if ($data['max'] !== null) {
                $peaks[] = (float) $data['max'];
            }
            if ($data['avg'] !== null) {
                $avgs[] = (float) $data['avg'];
            }
            if ($data['last'] !== null) {
                $spark[] = (float) $data['last'];
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
            'spark' => $spark,
            'last_clock' => $last_clock
        ];
    }

    // --------------------------------------------------------------- states

    private function repositoryState(?float $online, ?float $free_pct): string {
        if ($online !== null && $online !== 1.0) {
            return 'critical';
        }
        if ($free_pct === null) {
            return 'unknown';
        }
        if ($free_pct < self::REPO_FREE_CRIT_PCT) {
            return 'critical';
        }
        if ($free_pct < self::REPO_FREE_WARN_PCT) {
            return 'warning';
        }

        return 'ok';
    }

    private function jobResultState(string $result): string {
        $result = strtolower(trim($result));

        if ($result === '') {
            return 'unknown';
        }
        if (strpos($result, 'success') !== false) {
            return 'success';
        }
        if (strpos($result, 'warning') !== false) {
            return 'warning';
        }
        if (strpos($result, 'fail') !== false) {
            return 'failed';
        }
        if ($result === 'none') {
            return 'none';
        }

        return 'unknown';
    }

    private function jobStatusState(string $status): string {
        $status = strtolower(trim($status));

        if (strpos($status, 'running') !== false) {
            return 'running';
        }
        if (strpos($status, 'disabled') !== false) {
            return 'disabled';
        }
        if (strpos($status, 'inactive') !== false || strpos($status, 'idle') !== false) {
            return 'idle';
        }

        return 'unknown';
    }

    /**
     * Freshness of a backup relative to the user's staleness threshold.
     */
    private function freshnessState(?int $timestamp, int $stale_hours): string {
        if ($timestamp === null) {
            return 'unknown';
        }

        $age_hours = (time() - $timestamp) / 3600.0;

        if ($age_hours <= $stale_hours) {
            return 'ok';
        }
        if ($age_hours <= $stale_hours * 2) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * Whether a JOB is behind schedule.
     *
     * Age alone is the wrong test for a job: a monthly tape job that last ran
     * four days ago is perfectly on time. When Veeam tells us the next run, we
     * judge against that instead - a job is late only once its own next run has
     * come and gone. Only jobs with no schedule fall back to plain age.
     */
    private function jobFreshness(?int $last_run, ?int $next_run, int $stale_hours): string {
        if ($next_run !== null) {
            $overdue_hours = (time() - $next_run) / 3600.0;

            if ($overdue_hours <= 0) {
                return 'ok';
            }
            if ($overdue_hours <= $stale_hours) {
                return 'warning';
            }

            return 'critical';
        }

        return $this->freshnessState($last_run, $stale_hours);
    }

    private function rateTone(?float $rate, int $failed): string {
        if ($rate === null) {
            return 'neutral';
        }
        if ($failed > 0 || $rate < 90.0) {
            return $rate < 75.0 ? 'critical' : 'warning';
        }

        return 'ok';
    }

    private function usageTone(?float $used_pct): string {
        if ($used_pct === null) {
            return 'neutral';
        }
        if ($used_pct >= 100.0 - self::REPO_FREE_CRIT_PCT) {
            return 'critical';
        }
        if ($used_pct >= 100.0 - self::REPO_FREE_WARN_PCT) {
            return 'warning';
        }

        return 'ok';
    }

    private function forecastTone(?int $days_to_full): string {
        if ($days_to_full === null) {
            return 'ok';
        }
        if ($days_to_full <= 30) {
            return 'critical';
        }
        if ($days_to_full <= 90) {
            return 'warning';
        }

        return 'neutral';
    }

    // -------------------------------------------------------------- helpers

    /**
     * Physical identity of a repository.
     *
     * The same disk mounted by several Veeam servers gets its own repository id
     * on each of them, so the id cannot be the identity. Path alone cannot be
     * either: two servers each having a local "D:\Backups" are two different
     * disks, and merging them would under-report capacity. Requiring the
     * repository NAME to match as well separates those, because an admin
     * mounting one share on three servers names it once, while local disks are
     * named per server.
     *
     * A capacity mismatch inside a group is caught separately, in
     * buildRepositoryGroups(), and splits the group.
     */
    private function repositoryGroupKey(array $repository): string {
        $name = strtolower(trim((string) ($repository['repository'] ?? '')));
        $path = strtolower(trim((string) ($repository['path'] ?? '')));
        $path = rtrim(str_replace('\\', '/', $path), '/');

        if ($name === '' && $path === '') {
            return 'e:'.(string) ($repository['entity_id'] ?? '');
        }

        return 'r:'.$name.'@'.$path;
    }

    /**
     * Parse Veeam's ISO-8601 timestamps into a unix timestamp.
     */
    public function parseVeeamTime(string $value): ?int {
        $value = trim($value);

        if ($value === '' || strcasecmp($value, 'null') === 0 || strcasecmp($value, 'none') === 0) {
            return null;
        }

        if (preg_match('/^\d{9,11}$/', $value) === 1) {
            return (int) $value;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return $timestamp;
    }

    /**
     * Nth [bracketed] chunk of an item name, 1-based.
     */
    private function parseBracket(string $name, int $index): string {
        if (preg_match_all('/\[([^\]]*)\]/', $name, $matches) === 0) {
            return '';
        }

        return (string) ($matches[1][$index - 1] ?? '');
    }

    /**
     * The trailing [bracketed] chunk, used for "…: Capacity [/mnt/veeam]".
     */
    private function parseTrailingBracket(string $name): string {
        if (preg_match('/\[([^\]]*)\]\s*$/', $name, $matches) === 1 && strpos($name, ':') !== false) {
            $after_colon = substr($name, strpos($name, ':'));
            if (strpos($after_colon, '[') !== false) {
                return (string) $matches[1];
            }
        }

        return '';
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

        return $this->matchPrefix($key, $map);
    }

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

        return $this->matchPrefix($key, $map);
    }

    private function matchJobField(string $key): ?string {
        $map = [
            'veeam.jobs.last.result[' => 'lastResult',
            'veeam.jobs.last.run[' => 'lastRun',
            'veeam.jobs.next.run[' => 'nextRun',
            'veeam.jobs.status[' => 'status',
            'veeam.jobs.objects.count[' => 'objectsCount'
        ];

        return $this->matchPrefix($key, $map);
    }

    /**
     * @param array<string,string> $map
     */
    private function matchPrefix(string $key, array $map): ?string {
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
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function maxNullable(?float $a, ?float $b): ?float {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return max($a, $b);
    }

    private function minNullable(?float $a, ?float $b): ?float {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return min($a, $b);
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

    // ------------------------------------------------------------ formatting

    public function formatBytes($bytes, int $precision = 2): string {
        if ($bytes === null) {
            return '—';
        }

        $bytes = (float) $bytes;
        $sign = $bytes < 0 ? '-' : '';
        $bytes = abs($bytes);
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        // Big units read better with fewer decimals.
        if ($index <= 1) {
            $precision = 0;
        }

        return $sign.number_format($bytes, $precision, '.', ' ').' '.$units[$index];
    }

    /**
     * Format bytes in the unit a reference value would use, so every tick on
     * one axis carries the same unit instead of switching from GiB to TiB
     * halfway up.
     */
    public function formatBytesScaled($bytes, int $precision = 0, $reference = null): string {
        if ($bytes === null) {
            return '—';
        }

        if ($reference === null) {
            return $this->formatBytes($bytes, $precision);
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $scale = abs((float) $reference);
        $index = 0;
        while ($scale >= 1024 && $index < count($units) - 1) {
            $scale /= 1024;
            $index++;
        }

        $value = (float) $bytes / (1024 ** $index);

        return number_format($value, $index <= 1 ? 0 : $precision, '.', ' ').' '.$units[$index];
    }

    /**
     * The same idea for values already expressed in GB.
     */
    public function formatGbScaled($gb, int $precision = 0, $reference = null): string {
        if ($gb === null) {
            return '—';
        }

        if ($reference === null) {
            return $this->formatGb($gb, $precision);
        }

        $units = ['GB', 'TB', 'PB'];
        $scale = abs((float) $reference);
        $index = 0;
        while ($scale >= 1024 && $index < count($units) - 1) {
            $scale /= 1024;
            $index++;
        }

        $value = (float) $gb / (1024 ** $index);

        return number_format($value, $precision, '.', ' ').' '.$units[$index];
    }

    public function formatSignedBytes($bytes, int $precision = 2): string {
        if ($bytes === null) {
            return '—';
        }

        $prefix = (float) $bytes > 0 ? '+' : '';

        return $prefix.$this->formatBytes($bytes, $precision);
    }

    public function formatGb($gb, int $precision = 1): string {
        if ($gb === null) {
            return '—';
        }

        $gb = (float) $gb;
        if (abs($gb) >= 1024 * 1024) {
            return number_format($gb / (1024 * 1024), $precision, '.', ' ').' PB';
        }
        if (abs($gb) >= 1024) {
            return number_format($gb / 1024, $precision, '.', ' ').' TB';
        }

        return number_format($gb, $precision, '.', ' ').' GB';
    }

    public function formatNumber($value, int $precision = 2): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $precision, '.', ' ');
    }

    public function formatInt($value): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, 0, '.', ' ');
    }

    public function formatPct($value, int $precision = 2): string {
        if ($value === null) {
            return '—';
        }

        return number_format((float) $value, $precision, '.', ' ').'%';
    }

    public function formatDateTime($timestamp): string {
        if ($timestamp === null || (int) $timestamp <= 0) {
            return '—';
        }

        return date('Y-m-d H:i', (int) $timestamp);
    }

    /**
     * Human-readable "how long ago", e.g. "4 h ago", "3 days ago".
     */
    public function formatAge($seconds): string {
        if ($seconds === null) {
            return '—';
        }

        $seconds = (int) $seconds;
        if ($seconds < 90) {
            return _('just now');
        }
        if ($seconds < 5400) {
            return sprintf(_('%1$d min ago'), (int) round($seconds / 60));
        }
        if ($seconds < 172800) {
            return sprintf(_('%1$d h ago'), (int) round($seconds / 3600));
        }

        return sprintf(_('%1$d days ago'), (int) round($seconds / 86400));
    }

    /**
     * Short "in x" for a future timestamp.
     */
    public function formatUntil($timestamp): string {
        if ($timestamp === null || (int) $timestamp <= 0) {
            return '—';
        }

        $delta = (int) $timestamp - time();
        if ($delta <= 0) {
            return _('due');
        }
        if ($delta < 5400) {
            return sprintf(_('in %1$d min'), (int) round($delta / 60));
        }
        if ($delta < 172800) {
            return sprintf(_('in %1$d h'), (int) round($delta / 3600));
        }

        return sprintf(_('in %1$d days'), (int) round($delta / 86400));
    }

    // ------------------------------------------------------------- CSV export

    public function flattenDailyRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['date'],
                $row['size24h'], $this->formatBytes($row['size24h']),
                $row['size31d'], $this->formatBytes($row['size31d']),
                $row['assigned31d'], $this->formatBytes($row['assigned31d']),
                $row['shared31d'], $this->formatBytes($row['shared31d']),
                $row['coverage_pct'], $this->formatPct($row['coverage_pct'], 2),
                $row['hosts_with_data']
            ];
        }

        return $out;
    }

    public function flattenSourceHostRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'],
                $row['metric_start'], $this->formatBytes($row['metric_start']),
                $row['metric_end'], $this->formatBytes($row['metric_end']),
                $row['metric_change'], $this->formatBytes($row['metric_change']),
                $row['metric_avg'], $this->formatBytes($row['metric_avg']),
                $row['metric_peak'], $this->formatBytes($row['metric_peak']),
                $row['days'],
                $row['repo_capacity_gb'], $this->formatGb($row['repo_capacity_gb']),
                $row['repo_used_gb'], $this->formatGb($row['repo_used_gb']),
                $row['repo_free_gb'], $this->formatGb($row['repo_free_gb']),
                $row['repo_online_count'], $row['repo_offline_count'],
                $row['assigned_31d'], $this->formatBytes($row['assigned_31d']),
                $row['shared_31d'], $this->formatBytes($row['shared_31d']),
                $row['coverage_pct'], $this->formatPct($row['coverage_pct'], 2),
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    public function flattenRepositoryRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'], $row['repository'], $row['repo_type'], $row['path'],
                $row['metric_start'], $this->formatBytes($row['metric_start']),
                $row['metric_end'], $this->formatBytes($row['metric_end']),
                $row['metric_change'], $this->formatBytes($row['metric_change']),
                $row['metric_avg'], $this->formatBytes($row['metric_avg']),
                $row['metric_peak'], $this->formatBytes($row['metric_peak']),
                $row['days'], $row['files_31d'],
                $row['capacity_gb'], $this->formatGb($row['capacity_gb']),
                $row['used_gb'], $this->formatGb($row['used_gb']),
                $row['free_gb'], $this->formatGb($row['free_gb']),
                $row['free_pct'], $this->formatPct($row['free_pct'], 2),
                $row['online'] === null ? '' : ($row['online'] ? 'Yes' : 'No'),
                $row['out_of_date'] === null ? '' : ($row['out_of_date'] ? 'Yes' : 'No'),
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    public function flattenObjectRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'], $row['object'], $row['type_label'], $row['platform'],
                $row['metric_start'], $this->formatBytes($row['metric_start']),
                $row['metric_end'], $this->formatBytes($row['metric_end']),
                $row['metric_change'], $this->formatBytes($row['metric_change']),
                $row['metric_avg'], $this->formatBytes($row['metric_avg']),
                $row['metric_peak'], $this->formatBytes($row['metric_peak']),
                $row['days'], $row['restorepoints_31d'], $row['backupfiles_31d'],
                $row['last_backup'], $this->formatDateTime($row['last_backup_ts']),
                $row['freshness'], $row['repositories'], $row['attribution'],
                $this->formatDateTime($row['last_clock'])
            ];
        }

        return $out;
    }

    public function flattenJobRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['host'], $row['job'], $row['job_type'], $row['workload'],
                $row['last_result'], $row['status'],
                $row['last_run'], $this->formatDateTime($row['last_run_ts']),
                $row['next_run'], $this->formatDateTime($row['next_run_ts']),
                $row['objects_count'], $row['freshness']
            ];
        }

        return $out;
    }

    public function flattenTypeRows(array $rows): array {
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                $row['label'], $row['objects'],
                $row['bytes'], $this->formatBytes($row['bytes']),
                $row['change'], $this->formatBytes($row['change']),
                $row['pct'], $this->formatPct($row['pct'], 2)
            ];
        }

        return $out;
    }
}
