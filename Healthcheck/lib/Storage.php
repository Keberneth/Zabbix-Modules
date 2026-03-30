<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use PDO;

class Storage {

    public const STATUS_FAIL = 0;
    public const STATUS_OK = 1;
    public const STATUS_SKIP = 2;

    private const RUN_TABLE = 'module_healthcheck_run';
    private const STEP_TABLE = 'module_healthcheck_run_step';

    public static function ensureSchema(PDO $pdo): void {
        static $done = false;

        if ($done) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::RUN_TABLE.' ('.
                'runid VARCHAR(64) NOT NULL,'.
                'checkid VARCHAR(128) NOT NULL,'.
                'check_name VARCHAR(255) NOT NULL,'.
                'started_at BIGINT NOT NULL,'.
                'finished_at BIGINT NOT NULL,'.
                'duration_ms INTEGER NOT NULL,'.
                'status INTEGER NOT NULL,'.
                'summary VARCHAR(512) NULL,'.
                'error_text TEXT NULL,'.
                'api_version VARCHAR(64) NULL,'.
                'hosts_count INTEGER NULL,'.
                'triggers_count INTEGER NULL,'.
                'items_count INTEGER NULL,'.
                'freshest_age_sec INTEGER NULL,'.
                'ping_sent INTEGER NULL,'.
                'ping_http_status INTEGER NULL,'.
                'ping_latency_ms INTEGER NULL,'.
                'PRIMARY KEY (runid)'.
            ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::STEP_TABLE.' ('.
                'stepid VARCHAR(64) NOT NULL,'.
                'runid VARCHAR(64) NOT NULL,'.
                'checkid VARCHAR(128) NOT NULL,'.
                'step_key VARCHAR(64) NOT NULL,'.
                'step_label VARCHAR(128) NOT NULL,'.
                'step_order INTEGER NOT NULL,'.
                'status INTEGER NOT NULL,'.
                'started_at BIGINT NOT NULL,'.
                'finished_at BIGINT NOT NULL,'.
                'duration_ms INTEGER NOT NULL,'.
                'metric_value VARCHAR(255) NULL,'.
                'detail_text TEXT NULL,'.
                'PRIMARY KEY (stepid)'.
            ')'
        );

        $done = true;
    }

    public static function insertRun(PDO $pdo, array $run): void {
        self::ensureSchema($pdo);

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO '.self::RUN_TABLE.' ('.
                    'runid, checkid, check_name, started_at, finished_at, duration_ms, status,'.
                    'summary, error_text, api_version, hosts_count, triggers_count, items_count,'.
                    'freshest_age_sec, ping_sent, ping_http_status, ping_latency_ms'.
                ') VALUES ('.
                    ':runid, :checkid, :check_name, :started_at, :finished_at, :duration_ms, :status,'.
                    ':summary, :error_text, :api_version, :hosts_count, :triggers_count, :items_count,'.
                    ':freshest_age_sec, :ping_sent, :ping_http_status, :ping_latency_ms'.
                ')'
            );

            $stmt->execute([
                ':runid' => $run['runid'],
                ':checkid' => $run['checkid'],
                ':check_name' => $run['check_name'],
                ':started_at' => (int) $run['started_at'],
                ':finished_at' => (int) $run['finished_at'],
                ':duration_ms' => (int) $run['duration_ms'],
                ':status' => (int) $run['status'],
                ':summary' => $run['summary'] ?? null,
                ':error_text' => $run['error_text'] ?? null,
                ':api_version' => $run['api_version'] ?? null,
                ':hosts_count' => array_key_exists('hosts_count', $run) ? $run['hosts_count'] : null,
                ':triggers_count' => array_key_exists('triggers_count', $run) ? $run['triggers_count'] : null,
                ':items_count' => array_key_exists('items_count', $run) ? $run['items_count'] : null,
                ':freshest_age_sec' => array_key_exists('freshest_age_sec', $run) ? $run['freshest_age_sec'] : null,
                ':ping_sent' => array_key_exists('ping_sent', $run) ? $run['ping_sent'] : null,
                ':ping_http_status' => array_key_exists('ping_http_status', $run) ? $run['ping_http_status'] : null,
                ':ping_latency_ms' => array_key_exists('ping_latency_ms', $run) ? $run['ping_latency_ms'] : null
            ]);

            $step_stmt = $pdo->prepare(
                'INSERT INTO '.self::STEP_TABLE.' ('.
                    'stepid, runid, checkid, step_key, step_label, step_order, status,'.
                    'started_at, finished_at, duration_ms, metric_value, detail_text'.
                ') VALUES ('.
                    ':stepid, :runid, :checkid, :step_key, :step_label, :step_order, :status,'.
                    ':started_at, :finished_at, :duration_ms, :metric_value, :detail_text'.
                ')'
            );

            foreach (($run['steps'] ?? []) as $step) {
                $step_stmt->execute([
                    ':stepid' => $step['stepid'],
                    ':runid' => $step['runid'],
                    ':checkid' => $step['checkid'],
                    ':step_key' => $step['step_key'],
                    ':step_label' => $step['step_label'],
                    ':step_order' => (int) $step['step_order'],
                    ':status' => (int) $step['status'],
                    ':started_at' => (int) $step['started_at'],
                    ':finished_at' => (int) $step['finished_at'],
                    ':duration_ms' => (int) $step['duration_ms'],
                    ':metric_value' => $step['metric_value'] ?? null,
                    ':detail_text' => $step['detail_text'] ?? null
                ]);
            }

            $pdo->commit();
        }
        catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function pruneHistory(PDO $pdo, int $retention_days): int {
        self::ensureSchema($pdo);

        $cutoff = time() - max(1, $retention_days) * 86400;

        $step_stmt = $pdo->prepare(
            'DELETE FROM '.self::STEP_TABLE.
            ' WHERE started_at < :cutoff'
        );
        $run_stmt = $pdo->prepare(
            'DELETE FROM '.self::RUN_TABLE.
            ' WHERE started_at < :cutoff'
        );

        $step_stmt->execute([':cutoff' => $cutoff]);
        $deleted_steps = $step_stmt->rowCount();

        $run_stmt->execute([':cutoff' => $cutoff]);

        return $deleted_steps + $run_stmt->rowCount();
    }

    public static function getLatestRunByCheckId(PDO $pdo, string $checkid): ?array {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT * FROM '.self::RUN_TABLE.
            ' WHERE checkid = :checkid'.
            ' ORDER BY started_at DESC'.
            ' LIMIT 1'
        );
        $stmt->execute([':checkid' => $checkid]);
        $row = $stmt->fetch();

        return $row ? self::hydrateRunRow($row) : null;
    }

    public static function getLatestRuns(PDO $pdo, int $limit = 50, string $checkid = ''): array {
        return self::queryRuns($pdo, null, $checkid, $limit, false);
    }

    public static function getRuns(PDO $pdo, int $from_ts, string $checkid = '', int $limit = 200): array {
        return self::queryRuns($pdo, $from_ts, $checkid, $limit, false);
    }

    public static function getRecentFailures(PDO $pdo, int $limit = 20, string $checkid = ''): array {
        self::ensureSchema($pdo);

        $limit = max(1, (int) $limit);
        $sql = 'SELECT * FROM '.self::RUN_TABLE.' WHERE status = :status';
        $params = [':status' => self::STATUS_FAIL];

        if ($checkid !== '') {
            $sql .= ' AND checkid = :checkid';
            $params[':checkid'] = $checkid;
        }

        $sql .= ' ORDER BY started_at DESC LIMIT '.$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::hydrateRunRow($row);
        }

        return $rows;
    }

    public static function getFailures(PDO $pdo, int $from_ts, string $checkid = '', int $limit = 50): array {
        self::ensureSchema($pdo);

        $limit = max(1, (int) $limit);
        $sql = 'SELECT * FROM '.self::RUN_TABLE.' WHERE started_at >= :from_ts AND status = :status';
        $params = [
            ':from_ts' => $from_ts,
            ':status' => self::STATUS_FAIL
        ];

        if ($checkid !== '') {
            $sql .= ' AND checkid = :checkid';
            $params[':checkid'] = $checkid;
        }

        $sql .= ' ORDER BY started_at DESC LIMIT '.$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::hydrateRunRow($row);
        }

        return $rows;
    }

    public static function getStats(PDO $pdo, int $from_ts, string $checkid = ''): array {
        self::ensureSchema($pdo);

        $sql = 'SELECT '.
            'COUNT(*) AS total_runs, '.
            'SUM(CASE WHEN status = '.self::STATUS_OK.' THEN 1 ELSE 0 END) AS success_runs, '.
            'SUM(CASE WHEN status = '.self::STATUS_FAIL.' THEN 1 ELSE 0 END) AS failed_runs, '.
            'SUM(CASE WHEN status = '.self::STATUS_SKIP.' THEN 1 ELSE 0 END) AS skipped_runs, '.
            'AVG(duration_ms) AS avg_duration_ms, '.
            'AVG(ping_latency_ms) AS avg_ping_latency_ms, '.
            'AVG(freshest_age_sec) AS avg_freshest_age_sec, '.
            'MAX(CASE WHEN status = '.self::STATUS_OK.' THEN finished_at ELSE NULL END) AS last_success_at, '.
            'MAX(CASE WHEN status = '.self::STATUS_FAIL.' THEN finished_at ELSE NULL END) AS last_failure_at '.
            'FROM '.self::RUN_TABLE.
            ' WHERE started_at >= :from_ts';

        $params = [':from_ts' => $from_ts];

        if ($checkid !== '') {
            $sql .= ' AND checkid = :checkid';
            $params[':checkid'] = $checkid;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch() ?: [];

        $total = (int) ($row['total_runs'] ?? 0);
        $success = (int) ($row['success_runs'] ?? 0);
        $failed = (int) ($row['failed_runs'] ?? 0);
        $skipped = (int) ($row['skipped_runs'] ?? 0);

        return [
            'total_runs' => $total,
            'success_runs' => $success,
            'failed_runs' => $failed,
            'skipped_runs' => $skipped,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0.0,
            'avg_duration_ms' => isset($row['avg_duration_ms']) ? (int) round((float) $row['avg_duration_ms']) : null,
            'avg_ping_latency_ms' => isset($row['avg_ping_latency_ms']) ? (int) round((float) $row['avg_ping_latency_ms']) : null,
            'avg_freshest_age_sec' => isset($row['avg_freshest_age_sec']) ? (int) round((float) $row['avg_freshest_age_sec']) : null,
            'last_success_at' => !empty($row['last_success_at']) ? (int) $row['last_success_at'] : null,
            'last_failure_at' => !empty($row['last_failure_at']) ? (int) $row['last_failure_at'] : null
        ];
    }

    public static function getStepsByRunId(PDO $pdo, string $runid): array {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'SELECT * FROM '.self::STEP_TABLE.
            ' WHERE runid = :runid'.
            ' ORDER BY step_order ASC, started_at ASC'
        );
        $stmt->execute([':runid' => $runid]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::hydrateStepRow($row);
        }

        return $rows;
    }

    public static function getStepsByRunIds(PDO $pdo, array $runids): array {
        self::ensureSchema($pdo);

        $runids = array_values(array_filter(array_map('strval', $runids), static function(string $value): bool {
            return $value !== '';
        }));

        if ($runids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($runids as $index => $runid) {
            $placeholder = ':runid_'.$index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $runid;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM '.self::STEP_TABLE.
            ' WHERE runid IN ('.implode(', ', $placeholders).')'.
            ' ORDER BY runid ASC, step_order ASC, started_at ASC'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $step = self::hydrateStepRow($row);
            $grouped[$step['runid']][] = $step;
        }

        return $grouped;
    }

    private static function queryRuns(PDO $pdo, ?int $from_ts, string $checkid, int $limit, bool $ascending): array {
        self::ensureSchema($pdo);

        $limit = max(1, (int) $limit);

        $sql = 'SELECT * FROM '.self::RUN_TABLE.' WHERE 1=1';
        $params = [];

        if ($from_ts !== null) {
            $sql .= ' AND started_at >= :from_ts';
            $params[':from_ts'] = $from_ts;
        }

        if ($checkid !== '') {
            $sql .= ' AND checkid = :checkid';
            $params[':checkid'] = $checkid;
        }

        $sql .= ' ORDER BY started_at '.($ascending ? 'ASC' : 'DESC').' LIMIT '.$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::hydrateRunRow($row);
        }

        return $rows;
    }

    private static function hydrateRunRow(array $row): array {
        $integer_fields = [
            'started_at', 'finished_at', 'duration_ms', 'status', 'hosts_count',
            'triggers_count', 'items_count', 'freshest_age_sec', 'ping_sent',
            'ping_http_status', 'ping_latency_ms'
        ];

        foreach ($integer_fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $row[$field] = (int) $row[$field];
            }
            else {
                $row[$field] = null;
            }
        }

        return $row;
    }

    private static function hydrateStepRow(array $row): array {
        $integer_fields = [
            'step_order', 'status', 'started_at', 'finished_at', 'duration_ms'
        ];

        foreach ($integer_fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $row[$field] = (int) $row[$field];
            }
            else {
                $row[$field] = null;
            }
        }

        return $row;
    }
}
