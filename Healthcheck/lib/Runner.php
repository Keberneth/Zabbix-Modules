<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use PDO,
    RuntimeException;

class Runner {

    public static function runDueChecks(array $config, PDO $pdo, string $only_check_id = '', bool $force = false): array {
        Storage::ensureSchema($pdo);

        $config = Config::mergeWithDefaults($config);
        Storage::pruneHistory($pdo, (int) ($config['history']['retention_days'] ?? 90));

        if ($only_check_id !== '') {
            $selected = Config::getCheck($config, $only_check_id);

            if ($selected === null) {
                return [
                    'ok' => false,
                    'message' => 'Requested check was not found.',
                    'results' => [],
                    'run_count' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0
                ];
            }

            $checks = [$selected];
        }
        else {
            $checks = Config::getEnabledChecks($config);
        }

        if ($checks === []) {
            return [
                'ok' => false,
                'message' => 'No enabled health checks are configured.',
                'results' => [],
                'run_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0
            ];
        }

        $results = [];
        $run_count = 0;
        $failed_count = 0;
        $skipped_count = 0;
        $now = time();

        foreach ($checks as $check) {
            $check_id = (string) ($check['id'] ?? '');
            $latest_run = Storage::getLatestRunByCheckId($pdo, $check_id);

            if (!$force && !self::isDue($check, $latest_run, $now)) {
                $skipped_count++;
                $results[] = [
                    'checkid' => $check_id,
                    'check_name' => (string) ($check['name'] ?? $check_id),
                    'status' => Storage::STATUS_SKIP,
                    'summary' => 'Check is not due yet.',
                    'runid' => null
                ];
                continue;
            }

            $run = self::runSingleCheck($check);
            Storage::insertRun($pdo, $run);

            $run_count++;
            if ((int) $run['status'] !== Storage::STATUS_OK) {
                $failed_count++;
            }

            $results[] = [
                'checkid' => $run['checkid'],
                'check_name' => $run['check_name'],
                'status' => $run['status'],
                'summary' => $run['summary'],
                'runid' => $run['runid']
            ];
        }

        if ($run_count === 0) {
            return [
                'ok' => true,
                'message' => 'No checks were due to run.',
                'results' => $results,
                'run_count' => 0,
                'failed_count' => 0,
                'skipped_count' => $skipped_count
            ];
        }

        return [
            'ok' => $failed_count === 0,
            'message' => $failed_count === 0
                ? $run_count.' check(s) completed successfully.'
                : $run_count.' check(s) executed, '.$failed_count.' failed.',
            'results' => $results,
            'run_count' => $run_count,
            'failed_count' => $failed_count,
            'skipped_count' => $skipped_count
        ];
    }

    public static function runSingleCheck(array $check): array {
        $started_at = time();
        $started_us = microtime(true);

        $run = [
            'runid' => Util::generateId('run'),
            'checkid' => (string) ($check['id'] ?? ''),
            'check_name' => (string) (($check['name'] ?? '') !== '' ? $check['name'] : ($check['id'] ?? 'Healthcheck')),
            'started_at' => $started_at,
            'finished_at' => $started_at,
            'duration_ms' => 0,
            'status' => Storage::STATUS_OK,
            'summary' => '',
            'error_text' => null,
            'api_version' => null,
            'hosts_count' => null,
            'triggers_count' => null,
            'items_count' => null,
            'freshest_age_sec' => null,
            'ping_sent' => 0,
            'ping_http_status' => null,
            'ping_latency_ms' => null,
            'steps' => []
        ];

        $step_order = 0;
        $ping_attempted = false;

        $record_step = static function(
            string $step_key,
            string $step_label,
            int $status,
            int $step_started,
            int $duration_ms,
            ?string $metric_value,
            ?string $detail_text
        ) use (&$run, &$step_order): void {
            $step_order++;

            $run['steps'][] = [
                'stepid' => Util::generateId('step'),
                'runid' => $run['runid'],
                'checkid' => $run['checkid'],
                'step_key' => $step_key,
                'step_label' => $step_label,
                'step_order' => $step_order,
                'status' => $status,
                'started_at' => $step_started,
                'finished_at' => $step_started + (int) floor($duration_ms / 1000),
                'duration_ms' => $duration_ms,
                'metric_value' => $metric_value,
                'detail_text' => $detail_text
            ];
        };

        $run_step = static function(string $step_key, string $step_label, callable $callback) use (&$run, &$record_step) {
            $step_started = time();
            $micro = microtime(true);

            try {
                $result = $callback();
                $duration_ms = max(1, (int) round((microtime(true) - $micro) * 1000));

                $record_step(
                    $step_key,
                    $step_label,
                    Storage::STATUS_OK,
                    $step_started,
                    $duration_ms,
                    isset($result['metric']) ? (string) $result['metric'] : null,
                    isset($result['detail']) ? (string) $result['detail'] : null
                );

                return is_array($result) ? $result : [];
            }
            catch (\Throwable $e) {
                $duration_ms = max(1, (int) round((microtime(true) - $micro) * 1000));
                $detail = Util::truncate($e->getMessage(), 1800);

                $record_step(
                    $step_key,
                    $step_label,
                    Storage::STATUS_FAIL,
                    $step_started,
                    $duration_ms,
                    null,
                    $detail
                );

                throw new RuntimeException($detail);
            }
        };

        try {
            self::validateCheck($check);

            $api = ZabbixApiClient::fromCheck($check);
            if ($api === null) {
                throw new RuntimeException('Zabbix API URL or token is not configured.');
            }

            $api_version = $run_step('api_version', 'Zabbix API availability', static function() use ($api): array {
                $version = $api->call('apiinfo.version', [], false);

                if ($version === '' || $version === null) {
                    throw new RuntimeException('Zabbix API did not return a version.');
                }

                return [
                    'detail' => 'Zabbix API version: '.$version,
                    'metric' => (string) $version,
                    'api_version' => (string) $version
                ];
            });
            $run['api_version'] = $api_version['api_version'] ?? null;

            $hosts_count = $run_step('hosts_count', 'Monitored host count', static function() use ($api): array {
                $count = (int) $api->call('host.get', ['countOutput' => true]);

                return [
                    'detail' => 'Total hosts monitored: '.$count,
                    'metric' => (string) $count,
                    'hosts_count' => $count
                ];
            });
            $run['hosts_count'] = $hosts_count['hosts_count'] ?? null;

            $triggers_count = $run_step('problem_triggers', 'Active enabled problem triggers', static function() use ($api): array {
                $count = (int) $api->call('trigger.get', [
                    'filter' => [
                        'value' => 1,
                        'status' => 0
                    ],
                    'countOutput' => true
                ]);

                return [
                    'detail' => 'Active enabled problem triggers: '.$count,
                    'metric' => (string) $count,
                    'triggers_count' => $count
                ];
            });
            $run['triggers_count'] = $triggers_count['triggers_count'] ?? null;

            $items_count = $run_step('enabled_items', 'Enabled item count', static function() use ($api): array {
                $count = (int) $api->call('item.get', [
                    'filter' => [
                        'status' => 0
                    ],
                    'countOutput' => true
                ]);

                return [
                    'detail' => 'Enabled items: '.$count,
                    'metric' => (string) $count,
                    'items_count' => $count
                ];
            });
            $run['items_count'] = $items_count['items_count'] ?? null;

            $freshest = $run_step('freshest_data', 'Most recent item data', static function() use ($api, $check): array {
                $hosts = $api->call('host.get', [
                    'filter' => [
                        'status' => 0
                    ],
                    'output' => ['hostid'],
                    'limit' => (int) ($check['host_limit'] ?? 5000)
                ]);

                if (!is_array($hosts) || $hosts === []) {
                    throw new RuntimeException('No monitored hosts found.');
                }

                $now_ts = time();
                $freshest_ts = 0;
                $checked_hosts_count = 0;
                $freshness_max_age = (int) ($check['freshness_max_age'] ?? 900);

                foreach ($hosts as $host) {
                    if (!is_array($host) || empty($host['hostid'])) {
                        continue;
                    }

                    $checked_hosts_count++;

                    $items = $api->call('item.get', [
                        'hostids' => [(string) $host['hostid']],
                        'filter' => [
                            'status' => 0,
                            'state' => 0
                        ],
                        'output' => ['itemid', 'lastclock'],
                        'limit' => (int) ($check['item_limit_per_host'] ?? 10000)
                    ]);

                    if (!is_array($items)) {
                        continue;
                    }

                    foreach ($items as $item) {
                        $last_clock = (int) ($item['lastclock'] ?? 0);
                        if ($last_clock > $freshest_ts) {
                            $freshest_ts = $last_clock;
                        }
                    }

                    if ($freshest_ts > 0 && ($now_ts - $freshest_ts) <= $freshness_max_age) {
                        break;
                    }
                }

                if ($freshest_ts <= 0) {
                    throw new RuntimeException('No items with a valid last data timestamp were found.');
                }

                $age = $now_ts - $freshest_ts;

                if ($age > $freshness_max_age) {
                    throw new RuntimeException(
                        'Latest data update is older than '.$freshness_max_age.' seconds.'
                    );
                }

                $detail = $age < 0
                    ? 'Most recent item data is '.abs($age).' seconds in the future (clock skew detected).'
                    : 'Most recent item data update was '.$age.' seconds ago (checked '.$checked_hosts_count.' hosts).';

                return [
                    'detail' => $detail,
                    'metric' => (string) $age,
                    'freshest_age_sec' => $age
                ];
            });
            $run['freshest_age_sec'] = $freshest['freshest_age_sec'] ?? null;

            $ping_attempted = true;
            $ping = $run_step('ping', 'Healthcheck ping', static function() use ($check): array {
                $response = HttpClient::expectSuccess('GET', (string) $check['ping_url'], [
                    'timeout' => (int) ($check['timeout'] ?? 10),
                    'verify_peer' => (bool) ($check['verify_peer'] ?? true)
                ]);

                if ((int) $response['status'] !== 200) {
                    throw new RuntimeException('Unexpected HTTP status from ping target: '.$response['status']);
                }

                return [
                    'detail' => 'Ping delivered successfully with HTTP '.$response['status'].'.',
                    'metric' => (string) $response['status'],
                    'ping_http_status' => (int) $response['status'],
                    'ping_latency_ms' => (int) ($response['duration_ms'] ?? 0)
                ];
            });
            $run['ping_sent'] = 1;
            $run['ping_http_status'] = $ping['ping_http_status'] ?? null;
            $run['ping_latency_ms'] = $ping['ping_latency_ms'] ?? null;

            $run['summary'] = 'All health checks passed.';
        }
        catch (\Throwable $e) {
            $run['status'] = Storage::STATUS_FAIL;
            $run['summary'] = Util::truncate($e->getMessage(), 512);
            $run['error_text'] = Util::truncate($e->getMessage(), 1800);

            if (!$ping_attempted && trim((string) ($check['ping_url'] ?? '')) !== '') {
                $record_step(
                    'ping',
                    'Healthcheck ping',
                    Storage::STATUS_SKIP,
                    time(),
                    1,
                    null,
                    'Skipped because an earlier validation step failed.'
                );
            }
        }

        $run['finished_at'] = time();
        $run['duration_ms'] = max(1, (int) round((microtime(true) - $started_us) * 1000));

        return $run;
    }

    private static function validateCheck(array $check): void {
        if (!Util::truthy($check['enabled'] ?? false)) {
            throw new RuntimeException('The check is disabled.');
        }

        if (trim((string) ($check['name'] ?? '')) === '') {
            throw new RuntimeException('Check name is required.');
        }

        if (trim((string) ($check['ping_url'] ?? '')) === '') {
            throw new RuntimeException('Ping URL is required.');
        }

        $api_url = trim((string) ($check['zabbix_api_url'] ?? ''));
        if ($api_url === '' && ZabbixApiClient::deriveApiUrl() === '') {
            throw new RuntimeException('Zabbix API URL is required.');
        }

        $token = Config::resolveSecret(
            $check['zabbix_api_token'] ?? '',
            $check['zabbix_api_token_env'] ?? ''
        );

        if ($token === '') {
            throw new RuntimeException('Zabbix API token is required.');
        }
    }

    private static function isDue(array $check, ?array $latest_run, int $now): bool {
        if ($latest_run === null) {
            return true;
        }

        $interval = max(30, (int) ($check['interval_seconds'] ?? 300));

        return ((int) ($latest_run['finished_at'] ?? 0) + $interval) <= $now;
    }
}
