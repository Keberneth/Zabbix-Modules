<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class ZabbixApiClient {

    private string $url;
    private string $token;
    private bool $verify_peer;
    private int $timeout;
    private string $auth_mode;
    private string $transport;
    private ?string $frontend_url_cache = null;

    public function __construct(string $url, string $token, bool $verify_peer = true, int $timeout = 15, string $auth_mode = 'auto', string $transport = 'http') {
        $this->url = trim($url);
        $this->token = trim($token);
        $this->verify_peer = $verify_peer;
        $this->timeout = $timeout;
        $this->auth_mode = $auth_mode !== '' ? $auth_mode : 'auto';
        $this->transport = $transport === 'frontend' ? 'frontend' : 'http';
    }

    public static function fromConfig(array $config): ?self {
        $config = Config::mergeWithDefaults($config);
        $token = Config::resolveSecret($config['zabbix_api']['token'] ?? '', $config['zabbix_api']['token_env'] ?? '');
        $url = trim((string) ($config['zabbix_api']['url'] ?? ''));

        if ($url === '') {
            $url = self::deriveApiUrl();
        }

        if ($token === '') {
            return null;
        }

        return new self(
            $url,
            $token,
            (bool) ($config['zabbix_api']['verify_peer'] ?? true),
            (int) ($config['zabbix_api']['timeout'] ?? 15),
            (string) ($config['zabbix_api']['auth_mode'] ?? 'auto')
        );
    }

    /**
     * Prefer Zabbix's in-process frontend API facade for authenticated frontend
     * controllers, falling back to the HTTP JSON-RPC client only when the module
     * is not running inside a valid Zabbix frontend user session.
     *
     * This avoids a fragile frontend -> HTTP -> frontend loop in split
     * deployments. Webhook/standalone automation should keep using fromConfig()
     * so it remains token-based and independent of an interactive user session.
     */
    public static function fromFrontendOrConfig(array $config): ?self {
        $frontend = self::fromFrontend($config);

        if ($frontend !== null) {
            return $frontend;
        }

        return self::fromConfig($config);
    }

    /**
     * Client factory for write actions triggered from an interactive frontend
     * session. Writes must run under the calling user's own Zabbix RBAC, so we
     * prefer the in-process frontend API. The configured service token is
     * typically a Super Admin token, so falling back to it for a lower-privileged
     * operator would execute the change with privileges the user does not hold.
     * The fallback is therefore allowed ONLY for Super Admins (whose rights
     * already match such a token). When neither transport is available this
     * returns null and the caller MUST fail closed rather than execute the write.
     *
     * The webhook/standalone automation path is unaffected: it keeps using
     * fromConfig() directly, because it is token-based by design and has no
     * interactive user session to enforce RBAC against.
     */
    public static function fromFrontendForWrite(array $config, bool $allow_service_token_fallback): ?self {
        $frontend = self::fromFrontend($config);

        if ($frontend !== null) {
            return $frontend;
        }

        if ($allow_service_token_fallback) {
            return self::fromConfig($config);
        }

        return null;
    }

    /**
     * Create a client that uses Zabbix's PHP API facade (API::Host()->get(),
     * API::Problem()->get(), etc.) under the current frontend user's session.
     */
    public static function fromFrontend(array $config): ?self {
        $config = Config::mergeWithDefaults($config);

        if (!self::canUseFrontendApi()) {
            return null;
        }

        return new self(
            '',
            '',
            true,
            (int) ($config['zabbix_api']['timeout'] ?? 15),
            'frontend',
            'frontend'
        );
    }

    public static function deriveApiUrl(): string {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '/zabbix.php';
        $base_path = rtrim(str_replace('\\', '/', dirname($script_name)), '/.');

        return $scheme.'://'.$host.$base_path.'/api_jsonrpc.php';
    }

    public function call(string $method, array $params = []): array {
        if ($this->transport === 'frontend') {
            return $this->callWithFrontendApi($method, $params);
        }

        if ($this->auth_mode === 'bearer') {
            return $this->callWithBearer($method, $params);
        }

        if ($this->auth_mode === 'legacy_auth_field') {
            return $this->callWithLegacyAuthField($method, $params);
        }

        try {
            return $this->callWithBearer($method, $params);
        }
        catch (\Throwable $e) {
            return $this->callWithLegacyAuthField($method, $params);
        }
    }

    public function getHostIdByName(string $hostname): ?string {
        $result = $this->call('host.get', [
            'output' => ['hostid'],
            'filter' => [
                'host' => [$hostname]
            ]
        ]);

        return $result[0]['hostid'] ?? null;
    }

    public function getOsTypeByHostname(string $hostname): string {
        $host_id = $this->getHostIdByName($hostname);

        if ($host_id === null) {
            return 'Unknown';
        }

        $items = $this->call('item.get', [
            'hostids' => [$host_id],
            'search' => [
                'key_' => 'system.sw.os'
            ],
            'output' => ['lastvalue']
        ]);

        $lastvalue = strtolower(trim((string) ($items[0]['lastvalue'] ?? '')));

        if ($lastvalue === '') {
            return 'Unknown';
        }

        if (strpos($lastvalue, 'windows') !== false) {
            return 'Windows';
        }

        foreach (['linux', 'red hat', 'rhel', 'ubuntu', 'debian', 'suse', 'centos', 'rocky', 'fedora'] as $needle) {
            if (strpos($lastvalue, $needle) !== false) {
                return 'Linux';
            }
        }

        return 'Unknown';
    }

    public function getHosts(): array {
        $result = $this->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'sortfield' => 'host',
            'sortorder' => 'ASC'
        ]);

        $hosts = [];

        foreach ($result as $row) {
            $hosts[] = [
                'hostid' => $row['hostid'],
                'host' => $row['host'],
                'name' => $row['name'] ?? $row['host']
            ];
        }

        return $hosts;
    }

    /**
     * Flat list of Zabbix business-service names. Used by the optional services
     * redaction category to mask service names before they reach the AI
     * provider. Best-effort: returns [] when there are no services or the call
     * fails (insufficient permissions, no service tree, etc.).
     */
    public function getServiceNames(int $limit = 2000): array {
        try {
            $result = $this->call('service.get', [
                'output' => ['name'],
                'sortfield' => 'name',
                'limit' => min(max($limit, 1), 5000)
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }

        $names = [];

        foreach ($result as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Bulk-list hosts with optional filters. Used by the AI `list_zabbix_hosts`
     * tool to support multi-host inventory reports in a single API call.
     *
     * Accepted filters (all optional):
     *   - host_group   Substring match against host group name.
     *   - search       Substring match against technical or visible name.
     *   - status       'enabled' | 'disabled' | '' (any).
     *   - tag          Filter by host tag (string "name=value" or "name").
     *   - limit        Cap on rows returned (default 200, hard cap 2000).
     *
     * Returns rows of: hostid, host, name, status, maintenance, groups, tags.
     */
    public function listHostsFiltered(array $filters = []): array {
        $params = [
            'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectTags' => ['tag', 'value'],
            'sortfield' => 'host',
            'sortorder' => 'ASC'
        ];

        $host_group = trim((string) ($filters['host_group'] ?? ''));
        if ($host_group !== '') {
            $groups = $this->call('hostgroup.get', [
                'output' => ['groupid', 'name'],
                'search' => ['name' => $host_group]
            ]);
            $group_ids = array_column($groups, 'groupid');
            if (!$group_ids) {
                return [];
            }
            $params['groupids'] = $group_ids;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $params['search'] = ['host' => $search, 'name' => $search];
            $params['searchByAny'] = true;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status === 'enabled') {
            $params['filter']['status'] = 0;
        }
        elseif ($status === 'disabled') {
            $params['filter']['status'] = 1;
        }

        $tag = trim((string) ($filters['tag'] ?? ''));
        if ($tag !== '') {
            if (strpos($tag, '=') !== false) {
                [$tag_name, $tag_value] = explode('=', $tag, 2);
                $params['tags'] = [['tag' => trim($tag_name), 'value' => trim($tag_value), 'operator' => 1]];
            }
            else {
                $params['tags'] = [['tag' => $tag, 'operator' => 0]];
            }
        }

        $limit = (int) ($filters['limit'] ?? 200);
        $params['limit'] = max(1, min($limit, 2000));

        $result = $this->call('host.get', $params);

        $rows = [];
        foreach ($result as $row) {
            $groups = [];
            foreach (($row['hostgroups'] ?? $row['groups'] ?? []) as $g) {
                if (!empty($g['name'])) {
                    $groups[] = $g['name'];
                }
            }
            $tags = [];
            foreach (($row['tags'] ?? []) as $t) {
                $tag_str = (string) ($t['tag'] ?? '');
                if ($tag_str === '') {
                    continue;
                }
                $value = (string) ($t['value'] ?? '');
                $tags[] = $value !== '' ? $tag_str.'='.$value : $tag_str;
            }
            $rows[] = [
                'hostid' => (string) ($row['hostid'] ?? ''),
                'host' => (string) ($row['host'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'status' => ((string) ($row['status'] ?? '0')) === '0' ? 'enabled' : 'disabled',
                'maintenance' => ((string) ($row['maintenance_status'] ?? '0')) === '1',
                'groups' => $groups,
                'tags' => $tags
            ];
        }

        return $rows;
    }

    public function getProblems(?string $hostid = null, string $search = '', int $limit = 50): array {
        $params = [
            'output' => ['eventid', 'name', 'severity', 'objectid'],
            'source' => 0,
            'object' => 0,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'recent' => true,
            'suppressed' => false,
            'limit' => $limit
        ];

        if ($hostid !== null && $hostid !== '') {
            $params['hostids'] = [$hostid];
        }

        if ($search !== '') {
            $params['search'] = ['name' => $search];
        }

        $result = $this->call('problem.get', $params);

        // problem.get cannot return host data directly, so resolve the trigger
        // (objectid) → host(s) in one batched trigger.get, the same pattern used
        // by getProblemContext().
        $trigger_hosts = $this->resolveTriggerHosts($result);

        $problems = [];

        foreach ($result as $row) {
            $triggerid = (string) ($row['objectid'] ?? '');

            $problems[] = [
                'eventid' => $row['eventid'],
                'name' => $row['name'] ?? '',
                'severity' => $row['severity'] ?? '0',
                'hosts' => $trigger_hosts[$triggerid] ?? []
            ];
        }

        return $problems;
    }

    /**
     * Map trigger id → host list for a set of problem rows in a single
     * trigger.get (problem.get does not support selectHosts).
     */
    private function resolveTriggerHosts(array $problem_rows): array {
        $triggerids = [];
        foreach ($problem_rows as $row) {
            $tid = (string) ($row['objectid'] ?? '');
            if ($tid !== '' && $tid !== '0') {
                $triggerids[$tid] = true;
            }
        }

        if (!$triggerids) {
            return [];
        }

        $triggers = $this->call('trigger.get', [
            'triggerids' => array_keys($triggerids),
            'output' => ['triggerid'],
            'selectHosts' => ['hostid', 'host', 'name']
        ]);

        $map = [];
        foreach ($triggers as $tr) {
            $tid = (string) ($tr['triggerid'] ?? '');
            if ($tid === '') {
                continue;
            }

            $hosts = [];
            foreach (($tr['hosts'] ?? []) as $h) {
                $host = (string) ($h['host'] ?? '');
                if ($host === '') {
                    continue;
                }
                $hosts[] = [
                    'hostid' => (string) ($h['hostid'] ?? ''),
                    'host' => $host,
                    'name' => (string) ($h['name'] ?? $host)
                ];
            }

            $map[$tid] = $hosts;
        }

        return $map;
    }

    /**
     * Get problems with extended filters for AI actions.
     */
    public function getProblemsFiltered(array $params = []): array {
        $api_params = [
            'output' => ['eventid', 'name', 'severity', 'acknowledged', 'clock', 'r_eventid', 'objectid'],
            'selectTags' => ['tag', 'value'],
            'source' => 0,
            'object' => 0,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'recent' => true,
            'suppressed' => false,
            'limit' => min((int) ($params['limit'] ?? 100), 500)
        ];

        if (isset($params['severity_min']) && $params['severity_min'] !== '') {
            $api_params['severities'] = range((int) $params['severity_min'], 5);
        }

        if (isset($params['acknowledged'])) {
            $api_params['acknowledged'] = $params['acknowledged'] ? true : false;
        }

        if (!empty($params['hostids'])) {
            $api_params['hostids'] = (array) $params['hostids'];
        }

        if (!empty($params['host'])) {
            $host_id = $this->getHostIdByName((string) $params['host']);
            if ($host_id !== null) {
                $api_params['hostids'] = [$host_id];
            }
        }

        if (!empty($params['search'])) {
            $api_params['search'] = ['name' => (string) $params['search']];
        }

        $rows = $this->call('problem.get', $api_params);

        // problem.get has no selectHosts; resolve trigger → host(s) separately.
        $trigger_hosts = $this->resolveTriggerHosts($rows);
        foreach ($rows as &$row) {
            $row['hosts'] = $trigger_hosts[(string) ($row['objectid'] ?? '')] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * Get unsupported items, optionally filtered by host group.
     */
    public function getUnsupportedItems(string $host_group = '', bool $exclude_disabled_hosts = true, bool $exclude_disabled_items = true, int $limit = 500): array {
        $params = [
            'output' => ['itemid', 'name', 'key_', 'error', 'lastclock', 'state', 'status'],
            'selectHosts' => ['hostid', 'host', 'name', 'status'],
            'filter' => ['state' => 1],
            'sortfield' => 'name',
            'limit' => min($limit, 1000)
        ];

        if ($exclude_disabled_items) {
            $params['filter']['status'] = 0;
        }

        if ($host_group !== '') {
            $groups = $this->call('hostgroup.get', [
                'output' => ['groupid'],
                'filter' => ['name' => [$host_group]]
            ]);

            if ($groups) {
                $params['groupids'] = array_column($groups, 'groupid');
            }
        }

        $items = $this->call('item.get', $params);

        if ($exclude_disabled_hosts) {
            $items = array_filter($items, static function ($item) {
                $hosts = $item['hosts'] ?? [];
                foreach ($hosts as $host) {
                    if (($host['status'] ?? '1') === '0') {
                        return true;
                    }
                }
                return false;
            });
            $items = array_values($items);
        }

        return $items;
    }

    /**
     * Get host details including inventory, groups, interfaces.
     */
    public function getHostInfo(string $hostname): ?array {
        $result = $this->call('host.get', [
            'output' => ['hostid', 'host', 'name', 'status', 'description', 'maintenance_status'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectInterfaces' => ['ip', 'dns', 'port', 'type', 'main'],
            'selectInventory' => 'extend',
            'selectTags' => ['tag', 'value'],
            'filter' => ['host' => [$hostname]]
        ]);

        $host = $result[0] ?? null;

        // selectHostGroups (Zabbix 7.0+; selectGroups was removed in 7.4) returns
        // host groups under 'hostgroups'. Expose them as 'groups' so the spread-out
        // consumers of this raw row keep a single, stable shape on every version.
        if (is_array($host) && !isset($host['groups'])) {
            $host['groups'] = $host['hostgroups'] ?? [];
        }

        return $host;
    }

    /**
     * Get host uptime from the system.uptime item.
     */
    public function getHostUptime(string $hostname): ?array {
        $host_id = $this->getHostIdByName($hostname);

        if ($host_id === null) {
            return null;
        }

        $items = $this->call('item.get', [
            'hostids' => [$host_id],
            'search' => ['key_' => 'system.uptime'],
            'output' => ['itemid', 'name', 'lastvalue', 'lastclock', 'units']
        ]);

        if (!$items) {
            return null;
        }

        $item = $items[0];
        $seconds = (int) ($item['lastvalue'] ?? 0);
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return [
            'hostname' => $hostname,
            'uptime_seconds' => $seconds,
            'uptime_formatted' => $days.'d '.$hours.'h '.$minutes.'m',
            'last_check' => date('Y-m-d H:i:s', (int) ($item['lastclock'] ?? 0))
        ];
    }

    /**
     * Create a maintenance window for one or more hosts.
     *
     * @param bool $data_collection When false, Zabbix stops collecting data on
     *                              the host(s) during the window (maintenance_type=1).
     *                              Default true (with data collection).
     */
    public function createMaintenance(array $hostnames, float $duration_hours, ?string $start_time = null, string $name = '', string $description = '', bool $data_collection = true): array {
        $host_ids = [];
        $resolved_names = [];

        foreach ($hostnames as $hostname) {
            $hid = $this->getHostIdByName(trim((string) $hostname));
            if ($hid !== null) {
                $host_ids[] = ['hostid' => $hid];
                $resolved_names[] = trim((string) $hostname);
            }
        }

        if (!$host_ids) {
            throw new RuntimeException('None of the specified hosts were found.');
        }

        $window = $this->resolveMaintenanceWindow($duration_hours, $start_time);

        if ($name === '') {
            $name = 'AI maintenance: '.implode(', ', $resolved_names);
        }

        $payload = [
            'name' => Util::truncate($name, 128),
            'active_since' => $window['active_since'],
            'active_till' => $window['active_till'],
            'maintenance_type' => $data_collection ? 0 : 1,
            'hosts' => $host_ids,
            'timeperiods' => [[
                'timeperiod_type' => 0,
                'period' => $window['period']
            ]],
            'description' => $description
        ];

        $result = $this->call('maintenance.create', $payload);

        return [
            'maintenanceid' => $result['maintenanceids'][0] ?? null,
            'name' => $name,
            'targets' => ['hosts' => $resolved_names, 'host_groups' => []],
            'data_collection' => $data_collection,
            'tags' => [],
            'start' => date('Y-m-d H:i:s', $window['active_since']),
            'end' => date('Y-m-d H:i:s', $window['active_till']),
            'duration_hours' => $duration_hours
        ];
    }

    /**
     * Create a maintenance window for one or more host groups.
     */
    public function createHostGroupMaintenance(array $group_names, float $duration_hours, ?string $start_time = null, string $name = '', string $description = '', bool $data_collection = true): array {
        $group_ids = [];
        $resolved_groups = [];

        foreach ($group_names as $group_name) {
            $group_name = trim((string) $group_name);
            if ($group_name === '') {
                continue;
            }

            $group = $this->getHostGroupByName($group_name);
            if ($group !== null) {
                $group_ids[] = ['groupid' => $group['groupid']];
                $resolved_groups[] = $group_name;
            }
        }

        if (!$group_ids) {
            throw new RuntimeException('None of the specified host groups were found.');
        }

        $window = $this->resolveMaintenanceWindow($duration_hours, $start_time);

        if ($name === '') {
            $name = 'AI maintenance (group): '.implode(', ', $resolved_groups);
        }

        $result = $this->call('maintenance.create', [
            'name' => Util::truncate($name, 128),
            'active_since' => $window['active_since'],
            'active_till' => $window['active_till'],
            'maintenance_type' => $data_collection ? 0 : 1,
            'groups' => $group_ids,
            'timeperiods' => [[
                'timeperiod_type' => 0,
                'period' => $window['period']
            ]],
            'description' => $description
        ]);

        return [
            'maintenanceid' => $result['maintenanceids'][0] ?? null,
            'name' => $name,
            'targets' => ['hosts' => [], 'host_groups' => $resolved_groups],
            'data_collection' => $data_collection,
            'tags' => [],
            'start' => date('Y-m-d H:i:s', $window['active_since']),
            'end' => date('Y-m-d H:i:s', $window['active_till']),
            'duration_hours' => $duration_hours
        ];
    }

    /**
     * Create a tag-scoped maintenance window.
     *
     * Only problems whose triggers match the given tags will be suppressed.
     * Either hostnames or group_names (or both) must be supplied.
     *
     * @param array $tags        Each entry: ['tag' => string, 'operator' => 0|2, 'value' => string]
     *                           Operator: 0 = Equals, 2 = Contains. Default 0.
     * @param int   $tags_evaltype 0 = And/Or, 2 = Or. Default 0.
     */
    public function createTagScopedMaintenance(array $hostnames, array $group_names, array $tags, float $duration_hours, ?string $start_time = null, string $name = '', string $description = '', bool $data_collection = true, int $tags_evaltype = 0): array {
        $host_ids = [];
        $resolved_hosts = [];

        foreach ($hostnames as $hostname) {
            $hostname = trim((string) $hostname);
            if ($hostname === '') {
                continue;
            }
            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                $host_ids[] = ['hostid' => $hid];
                $resolved_hosts[] = $hostname;
            }
        }

        $group_ids = [];
        $resolved_groups = [];

        foreach ($group_names as $group_name) {
            $group_name = trim((string) $group_name);
            if ($group_name === '') {
                continue;
            }
            $group = $this->getHostGroupByName($group_name);
            if ($group !== null) {
                $group_ids[] = ['groupid' => $group['groupid']];
                $resolved_groups[] = $group_name;
            }
        }

        if (!$host_ids && !$group_ids) {
            throw new RuntimeException('No hosts or host groups were resolved for tag-scoped maintenance.');
        }

        $normalized_tags = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $tname = trim((string) ($tag['tag'] ?? ''));
            if ($tname === '') {
                continue;
            }

            $operator = (int) ($tag['operator'] ?? 0);
            if (!in_array($operator, [0, 2], true)) {
                $operator = 0;
            }

            $normalized_tags[] = [
                'tag' => $tname,
                'operator' => $operator,
                'value' => (string) ($tag['value'] ?? '')
            ];
        }

        if (!$normalized_tags) {
            throw new RuntimeException('Tag-scoped maintenance requires at least one tag.');
        }

        $window = $this->resolveMaintenanceWindow($duration_hours, $start_time);

        if ($name === '') {
            $targets_str = $resolved_hosts ? implode(', ', $resolved_hosts) : implode(', ', $resolved_groups);
            $tag_strs = [];
            foreach ($normalized_tags as $t) {
                $tag_strs[] = $t['tag'].($t['value'] !== '' ? '='.$t['value'] : '');
            }
            $name = 'AI tag-scoped: '.$targets_str.' ['.implode(', ', $tag_strs).']';
        }

        if (!in_array($tags_evaltype, [0, 2], true)) {
            $tags_evaltype = 0;
        }

        $payload = [
            'name' => Util::truncate($name, 128),
            'active_since' => $window['active_since'],
            'active_till' => $window['active_till'],
            'maintenance_type' => $data_collection ? 0 : 1,
            'tags_evaltype' => $tags_evaltype,
            'tags' => $normalized_tags,
            'timeperiods' => [[
                'timeperiod_type' => 0,
                'period' => $window['period']
            ]],
            'description' => $description
        ];

        if ($host_ids) {
            $payload['hosts'] = $host_ids;
        }
        if ($group_ids) {
            $payload['groups'] = $group_ids;
        }

        $result = $this->call('maintenance.create', $payload);

        return [
            'maintenanceid' => $result['maintenanceids'][0] ?? null,
            'name' => $name,
            'targets' => ['hosts' => $resolved_hosts, 'host_groups' => $resolved_groups],
            'data_collection' => $data_collection,
            'tags' => $normalized_tags,
            'tags_evaltype' => $tags_evaltype,
            'start' => date('Y-m-d H:i:s', $window['active_since']),
            'end' => date('Y-m-d H:i:s', $window['active_till']),
            'duration_hours' => $duration_hours
        ];
    }

    /**
     * List maintenance windows. Set $only_active=true to filter to currently active ones.
     */
    public function listMaintenances(bool $only_active = true, int $limit = 100): array {
        $params = [
            'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till', 'maintenance_type', 'tags_evaltype'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectTags' => ['tag', 'operator', 'value'],
            'selectTimeperiods' => 'extend',
            'sortfield' => 'active_since',
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 500)
        ];

        $result = $this->call('maintenance.get', $params);

        // Normalize selectHostGroups output ('hostgroups') to the 'groups' key
        // that downstream formatting relies on (selectGroups removed in 7.4).
        foreach ($result as &$m_row) {
            if (is_array($m_row) && !isset($m_row['groups'])) {
                $m_row['groups'] = $m_row['hostgroups'] ?? [];
            }
        }
        unset($m_row);

        if ($only_active) {
            $now = time();
            $result = array_values(array_filter($result, static function ($m) use ($now) {
                $start = (int) ($m['active_since'] ?? 0);
                $end = (int) ($m['active_till'] ?? 0);
                return $start <= $now && $end > $now;
            }));
        }

        return $result;
    }

    /**
     * Extend an existing maintenance window by N additional hours past its current end.
     */
    public function extendMaintenance(string $maintenance_id, float $additional_hours): array {
        $maintenance_id = trim($maintenance_id);

        if ($maintenance_id === '' || $additional_hours <= 0) {
            throw new RuntimeException('Maintenance id and positive additional_hours are required.');
        }

        $existing = $this->call('maintenance.get', [
            'maintenanceids' => [$maintenance_id],
            'output' => ['maintenanceid', 'name', 'active_since', 'active_till'],
            'selectTimeperiods' => 'extend'
        ]);

        if (!$existing) {
            throw new RuntimeException('Maintenance '.$maintenance_id.' not found.');
        }

        $m = $existing[0];
        $old_till = (int) ($m['active_till'] ?? 0);
        $now = time();
        $base = max($old_till, $now);
        $new_till = $base + (int) ($additional_hours * 3600);

        $timeperiods = [];
        foreach ($m['timeperiods'] ?? [] as $tp) {
            $timeperiods[] = [
                'timeperiod_type' => (int) ($tp['timeperiod_type'] ?? 0),
                'period' => max($new_till - (int) ($m['active_since'] ?? $now), (int) ($tp['period'] ?? 0))
            ];
        }
        if (!$timeperiods) {
            $timeperiods = [[
                'timeperiod_type' => 0,
                'period' => $new_till - (int) ($m['active_since'] ?? $now)
            ]];
        }

        $this->call('maintenance.update', [
            'maintenanceid' => $maintenance_id,
            'active_till' => $new_till,
            'timeperiods' => $timeperiods
        ]);

        return [
            'maintenanceid' => $maintenance_id,
            'name' => $m['name'] ?? '',
            'old_end' => date('Y-m-d H:i:s', $old_till),
            'new_end' => date('Y-m-d H:i:s', $new_till),
            'added_hours' => $additional_hours
        ];
    }

    /**
     * End a maintenance window immediately by setting active_till to now.
     * When $delete is true, the maintenance record is removed entirely instead.
     */
    public function endMaintenance(string $maintenance_id, bool $delete = false): array {
        $maintenance_id = trim($maintenance_id);

        if ($maintenance_id === '') {
            throw new RuntimeException('Maintenance id is required.');
        }

        $existing = $this->call('maintenance.get', [
            'maintenanceids' => [$maintenance_id],
            'output' => ['maintenanceid', 'name', 'active_since', 'active_till']
        ]);

        if (!$existing) {
            throw new RuntimeException('Maintenance '.$maintenance_id.' not found.');
        }

        $m = $existing[0];

        if ($delete) {
            $this->call('maintenance.delete', [$maintenance_id]);
            return [
                'maintenanceid' => $maintenance_id,
                'name' => $m['name'] ?? '',
                'action' => 'deleted'
            ];
        }

        $now = time();
        $active_since = (int) ($m['active_since'] ?? $now);

        // active_till must be strictly greater than active_since.
        $new_till = max($now, $active_since + 60);

        $this->call('maintenance.update', [
            'maintenanceid' => $maintenance_id,
            'active_till' => $new_till
        ]);

        return [
            'maintenanceid' => $maintenance_id,
            'name' => $m['name'] ?? '',
            'action' => 'ended',
            'ended_at' => date('Y-m-d H:i:s', $new_till)
        ];
    }

    private function resolveMaintenanceWindow(float $duration_hours, ?string $start_time): array {
        if ($duration_hours <= 0) {
            throw new RuntimeException('duration_hours must be greater than 0.');
        }

        $active_since = $start_time !== null && $start_time !== ''
            ? strtotime($start_time)
            : time();

        if ($active_since === false) {
            $active_since = time();
        }

        $period = (int) ($duration_hours * 3600);
        $active_till = $active_since + $period;

        return [
            'active_since' => $active_since,
            'active_till' => $active_till,
            'period' => $period
        ];
    }

    /**
     * Get a template ID by template name (technical name).
     */
    public function getTemplateIdByName(string $template_name): ?string {
        // Try exact match first.
        $result = $this->call('template.get', [
            'output' => ['templateid'],
            'filter' => ['host' => [$template_name]]
        ]);

        if ($result) {
            return $result[0]['templateid'] ?? null;
        }

        // Try visible name match.
        $result = $this->call('template.get', [
            'output' => ['templateid'],
            'filter' => ['name' => [$template_name]]
        ]);

        if ($result) {
            return $result[0]['templateid'] ?? null;
        }

        // Try search (partial match).
        $result = $this->call('template.get', [
            'output' => ['templateid'],
            'search' => ['host' => $template_name],
            'limit' => 5
        ]);

        return $result[0]['templateid'] ?? null;
    }

    /**
     * Find a trigger by description text, with optional template or host filtering.
     *
     * When a template name is given, searches for triggers belonging to that
     * template. When a hostname is given, searches by host. When both are
     * given, template takes priority.
     */
    public function findTrigger(string $description, string $hostname = '', string $template_name = ''): ?array {
        $params = [
            'output' => ['triggerid', 'description', 'expression', 'priority', 'status', 'value', 'comments'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'search' => ['description' => $description],
            'expandExpression' => true,
            'limit' => 20
        ];

        if ($template_name !== '') {
            $tid = $this->getTemplateIdByName($template_name);
            if ($tid !== null) {
                $params['templateids'] = [$tid];
            }
        }
        elseif ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                $params['hostids'] = [$hid];
            }
        }

        $triggers = $this->call('trigger.get', $params);

        return $triggers[0] ?? null;
    }

    /**
     * Update a trigger's properties.
     *
     * Zabbix API field reference:
     *   description = the trigger NAME / title (e.g. "{HOST.NAME} has uptime over 60 days")
     *   comments    = the operational notes / comment text (free-text field)
     *   expression  = the trigger expression
     *   priority    = severity 0-5
     *   status      = 0=enabled, 1=disabled
     */
    public function updateTrigger(string $trigger_id, array $changes): array {
        $allowed = ['expression', 'description', 'priority', 'status', 'comments', 'url', 'recovery_expression'];
        $update = ['triggerid' => $trigger_id];

        foreach ($changes as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $update[$key] = $value;
            }
        }

        if (count($update) <= 1) {
            throw new RuntimeException('No valid fields to update. Allowed: '.implode(', ', $allowed));
        }

        return $this->call('trigger.update', $update);
    }

    /**
     * Get items for a host with optional filters.
     */
    public function getItemsFiltered(string $hostname = '', array $filters = [], int $limit = 100): array {
        $params = [
            'output' => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'status', 'state', 'type', 'delay', 'error'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'sortfield' => 'name',
            'limit' => min($limit, 500)
        ];

        if ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                $params['hostids'] = [$hid];
            }
        }

        if (!empty($filters['search'])) {
            $params['search'] = ['name' => $filters['search']];
        }

        if (isset($filters['status'])) {
            $params['filter'] = $params['filter'] ?? [];
            $params['filter']['status'] = (int) $filters['status'];
        }

        return $this->call('item.get', $params);
    }

    /**
     * Update an item's properties.
     */
    public function updateItem(string $item_id, array $changes): array {
        $allowed = ['status', 'delay', 'name', 'description', 'history', 'trends'];
        $update = ['itemid' => $item_id];

        foreach ($changes as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $update[$key] = $value;
            }
        }

        // Guard against empty/no-op updates (mirrors updateTrigger): if no
        // allowed field survived the whitelist, reject instead of issuing a
        // misleading "success" for an item.update that changes nothing.
        if (count($update) <= 1) {
            throw new RuntimeException('No valid fields to update. Allowed: '.implode(', ', $allowed));
        }

        return $this->call('item.update', $update);
    }

    /**
     * Create a Zabbix user.
     */
    public function createUser(string $username, string $name, string $surname, string $passwd, array $usrgrps, int $roleid): array {
        $groups = [];
        foreach ($usrgrps as $grp) {
            if (is_array($grp)) {
                $groups[] = $grp;
            } else {
                $groups[] = ['usrgrpid' => (string) $grp];
            }
        }

        return $this->call('user.create', [
            'username' => $username,
            'name' => $name,
            'surname' => $surname,
            'passwd' => $passwd,
            'usrgrps' => $groups,
            'roleid' => (string) $roleid
        ]);
    }

    /**
     * Acknowledge / close / add message to a problem event.
     */
    public function acknowledgeProblem(string $eventid, int $action, string $message = ''): array {
        $params = [
            'eventids' => [$eventid],
            'action' => $action
        ];

        if ($message !== '') {
            $params['message'] = $message;
        }

        return $this->call('event.acknowledge', $params);
    }

    /**
     * Get triggers with filters.
     *
     * Supports filtering by hostname OR template name. When template is given,
     * it takes priority over hostname.
     */
    public function getTriggersFiltered(string $hostname = '', array $filters = [], int $limit = 100): array {
        $params = [
            'output' => ['triggerid', 'description', 'expression', 'priority', 'status', 'value', 'lastchange', 'comments'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'sortfield' => 'description',
            'limit' => min($limit, 500),
            'expandExpression' => true
        ];

        // Template filtering takes priority over hostname.
        $template_name = $filters['template'] ?? '';

        if ($template_name !== '') {
            $tid = $this->getTemplateIdByName($template_name);
            if ($tid !== null) {
                $params['templateids'] = [$tid];
            }
        }
        elseif ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                $params['hostids'] = [$hid];
            }
        }

        if (!empty($filters['search'])) {
            $params['search'] = ['description' => $filters['search']];
        }

        if (isset($filters['value'])) {
            $params['filter'] = $params['filter'] ?? [];
            $params['filter']['value'] = (int) $filters['value'];
        }

        if (isset($filters['min_severity'])) {
            $params['min_severity'] = (int) $filters['min_severity'];
        }

        return $this->call('trigger.get', $params);
    }

    /**
     * Get recent history values for an item.
     *
     * Returns the last $limit values with timestamps. Tries numeric history
     * first (history=0), then falls back to text/string types.
     */
    public function getItemHistory(string $itemid, int $limit = 50, int $history_type = 0, int $period_hours = 0): array {
        $params = [
            'itemids' => [$itemid],
            'output' => ['clock', 'value', 'ns'],
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => min($limit, 500),
            'history' => $history_type
        ];

        if ($period_hours > 0) {
            $params['time_from'] = time() - ($period_hours * 3600);
        }

        $result = $this->call('history.get', $params);

        // If numeric float returned nothing, try unsigned int, then text.
        if (!$result && $history_type === 0) {
            $result = $this->getItemHistory($itemid, $limit, 3, $period_hours); // unsigned
            if (!$result) {
                $result = $this->getItemHistory($itemid, $limit, 1, $period_hours); // string
                if (!$result) {
                    $result = $this->getItemHistory($itemid, $limit, 4, $period_hours); // text
                }
            }
            return $result;
        }

        return array_reverse($result); // oldest first
    }

    /**
     * Get history for all items related to a problem event.
     *
     * Returns an array keyed by item name with recent values.
     */
    public function getProblemItemHistory(string $eventid, int $limit_per_item = 30, int $period_hours = 0): array {
        $context = $this->getProblemContext($eventid);

        if ($context === null || empty($context['items'])) {
            return [];
        }

        $result = [];

        foreach ($context['items'] as $item) {
            $itemid = $item['itemid'] ?? '';
            if ($itemid === '') {
                continue;
            }

            $history = $this->getItemHistory($itemid, $limit_per_item, 0, $period_hours);
            $label = ($item['name'] ?? 'Item '.$itemid).' ('.$item['key_'].')';

            $values = [];
            foreach ($history as $h) {
                $values[] = [
                    'time' => date('Y-m-d H:i:s', (int) ($h['clock'] ?? 0)),
                    'value' => $h['value'] ?? ''
                ];
            }

            if ($values) {
                $result[] = [
                    'item_name' => $item['name'] ?? '',
                    'item_key' => $item['key_'] ?? '',
                    'label' => $label,
                    'values' => $values
                ];
            }
        }

        return $result;
    }

    /**
     * Resolve full problem context for an event: trigger, items, hosts, templates.
     *
     * Returns a structured array suitable for AI enrichment. All data is
     * authoritative (fetched from the API, not the browser DOM).
     */
    public function getProblemContext(string $eventid): ?array {
        $problems = $this->call('problem.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'name', 'severity', 'clock', 'objectid'],
            'source' => 0,
            'object' => 0,
            'recent' => true,
            'limit' => 1
        ]);

        if (!$problems) {
            return null;
        }

        $problem = $problems[0];
        $triggerid = $problem['objectid'] ?? '';

        $result = [
            'eventid' => $problem['eventid'],
            'problem_summary' => $problem['name'] ?? '',
            'severity' => $problem['severity'] ?? '0',
            'hostname' => '',
            'triggerid' => $triggerid,
            'trigger_name' => '',
            'trigger_expression' => '',
            'trigger_comments' => '',
            'items' => [],
            'template_names' => []
        ];

        if ($triggerid === '') {
            return $result;
        }

        $triggers = $this->call('trigger.get', [
            'triggerids' => [$triggerid],
            'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'status', 'value'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'selectItems' => ['itemid', 'name', 'key_', 'description'],
            'expandDescription' => true,
            'expandExpression' => true,
            'limit' => 1
        ]);

        if ($triggers) {
            $trigger = $triggers[0];
            $result['trigger_name'] = $trigger['description'] ?? '';
            $result['trigger_expression'] = $trigger['expression'] ?? '';
            $result['trigger_comments'] = $trigger['comments'] ?? '';

            $hosts = $trigger['hosts'] ?? [];
            if ($hosts) {
                $result['hostname'] = $hosts[0]['host'] ?? '';
            }

            foreach ($trigger['items'] ?? [] as $item) {
                $result['items'][] = [
                    'itemid' => $item['itemid'] ?? '',
                    'name' => $item['name'] ?? '',
                    'key_' => $item['key_'] ?? '',
                    'description' => $item['description'] ?? ''
                ];
            }
        }

        $templates = $this->call('template.get', [
            'triggerids' => [$triggerid],
            'output' => ['templateid', 'name']
        ]);

        foreach ($templates as $tpl) {
            $result['template_names'][] = $tpl['name'] ?? '';
        }

        return $result;
    }

    /**
     * Get a host group by name. Returns the group or null.
     */
    public function getHostGroupByName(string $name): ?array {
        $result = $this->call('hostgroup.get', [
            'output' => ['groupid', 'name'],
            'filter' => ['name' => [$name]]
        ]);

        return $result[0] ?? null;
    }

    /**
     * Create a host group.
     */
    public function createHostGroup(string $name): array {
        return $this->call('hostgroup.create', [
            'name' => $name
        ]);
    }

    /**
     * Add hosts to a host group. Creates the group if it does not exist.
     *
     * @param string[] $hostnames  Technical hostnames to add.
     * @param string   $group_name Host group name.
     * @param bool     $create_if_missing  Create group if it doesn't exist.
     *
     * @return array   Result with groupid, hosts added, and whether group was created.
     */
    public function addHostsToGroup(array $hostnames, string $group_name, bool $create_if_missing = false): array {
        $group = $this->getHostGroupByName($group_name);
        $created = false;

        if ($group === null) {
            if (!$create_if_missing) {
                throw new RuntimeException(
                    'Host group "'.$group_name.'" does not exist. '
                    .'Set create_group=true to create it automatically.'
                );
            }

            $result = $this->createHostGroup($group_name);
            $groupid = $result['groupids'][0] ?? null;

            if ($groupid === null) {
                throw new RuntimeException('Failed to create host group "'.$group_name.'".');
            }

            $created = true;
        }
        else {
            $groupid = $group['groupid'];
        }

        // Resolve host IDs.
        $host_ids = [];
        $resolved = [];
        $not_found = [];

        foreach ($hostnames as $hostname) {
            $hostname = trim((string) $hostname);
            if ($hostname === '') {
                continue;
            }

            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                $host_ids[] = ['hostid' => $hid];
                $resolved[] = $hostname;
            }
            else {
                $not_found[] = $hostname;
            }
        }

        if (!$host_ids) {
            throw new RuntimeException('None of the specified hosts were found.');
        }

        // Zabbix API: massadd to add hosts to the group without removing existing members.
        $this->call('hostgroup.massadd', [
            'groups' => [['groupid' => $groupid]],
            'hosts' => $host_ids
        ]);

        return [
            'groupid' => $groupid,
            'group_name' => $group_name,
            'group_created' => $created,
            'hosts_added' => $resolved,
            'hosts_not_found' => $not_found
        ];
    }

    public function addProblemComment(string $eventid, string $message, int $action = 4, int $chunk_size = 1900): array {
        $chunks = Util::chunkText($message, max(200, $chunk_size - 32));
        $count = count($chunks);

        foreach ($chunks as $index => $chunk) {
            $prefix = ($count > 1)
                ? '[AI '.($index + 1).'/'.$count.'] '
                : '[AI] ';

            $this->call('event.acknowledge', [
                'eventids' => [$eventid],
                'action' => $action,
                'message' => $prefix.$chunk
            ]);
        }

        return $chunks;
    }

    /**
     * Get all configured trigger actions (rules that decide who is notified).
     * Returns actions with their filters, operations and recovery operations.
     */
    public function getTriggerActions(int $limit = 200): array {
        return $this->call('action.get', [
            'output' => ['actionid', 'name', 'status', 'esc_period', 'eventsource', 'evaltype'],
            'selectFilter' => 'extend',
            'selectOperations' => 'extend',
            'selectRecoveryOperations' => 'extend',
            'selectUpdateOperations' => 'extend',
            'filter' => ['eventsource' => 0],
            'sortfield' => 'name',
            'limit' => min(max($limit, 1), 500)
        ]);
    }

    /**
     * Get all media types with their enabled status and current error counters.
     */
    public function getMediaTypes(int $limit = 100): array {
        return $this->call('mediatype.get', [
            'output' => ['mediatypeid', 'name', 'type', 'status', 'maxsessions', 'maxattempts', 'attempt_interval'],
            'sortfield' => 'name',
            'limit' => min(max($limit, 1), 500)
        ]);
    }

    /**
     * Get the alerts dispatched for a specific event (and its recovery event when present).
     */
    public function getAlertsForEvent(string $eventid, int $limit = 100): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            throw new RuntimeException('Event id is required.');
        }

        // Also fetch the recovery event id if it exists so we can show full lifecycle alerts.
        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'r_eventid', 'objectid'],
            'limit' => 1
        ]);

        $eventids = [$eventid];
        if ($events && !empty($events[0]['r_eventid']) && (string) $events[0]['r_eventid'] !== '0') {
            $eventids[] = (string) $events[0]['r_eventid'];
        }

        $alerts = $this->call('alert.get', [
            'eventids' => $eventids,
            'output' => 'extend',
            'selectMediatypes' => ['mediatypeid', 'name', 'type', 'status'],
            'selectUsers' => ['userid', 'username', 'name', 'surname'],
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => min(max($limit, 1), 500)
        ]);

        return $alerts;
    }

    /**
     * Find the trigger actions whose conditions match a given event.
     *
     * Returns a list of actions with a 'match_status' field:
     *   'matched'        - the action condition matched and operations would run
     *   'did_not_match'  - condition evaluated false for this event
     *   'disabled'       - action is disabled at the action level
     *
     * Note: this is a best-effort condition evaluation. Some condition types
     * (template, application, suppressed) require additional API calls; we
     * surface those as 'undetermined' so the AI can flag them for review.
     */
    public function getActionsForEvent(string $eventid): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            throw new RuntimeException('Event id is required.');
        }

        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'severity', 'objectid', 'value', 'suppressed'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'selectTags' => ['tag', 'value'],
            'selectRelatedObject' => ['triggerid', 'priority'],
            'limit' => 1
        ]);

        if (!$events) {
            return [];
        }

        $event = $events[0];
        $host_ids = array_column($event['hosts'] ?? [], 'hostid');
        $host_group_ids = [];

        if ($host_ids) {
            $host_details = $this->call('host.get', [
                'hostids' => $host_ids,
                'output' => ['hostid'],
                'selectHostGroups' => ['groupid', 'name']
            ]);
            foreach ($host_details as $hd) {
                foreach (($hd['hostgroups'] ?? $hd['groups'] ?? []) as $g) {
                    $host_group_ids[] = (string) ($g['groupid'] ?? '');
                }
            }
            $host_group_ids = array_values(array_unique(array_filter($host_group_ids)));
        }

        $event_tags = [];
        foreach (($event['tags'] ?? []) as $t) {
            $event_tags[] = [
                'tag' => (string) ($t['tag'] ?? ''),
                'value' => (string) ($t['value'] ?? '')
            ];
        }

        $event_severity = (int) ($event['severity'] ?? 0);
        $event_triggerid = (string) ($event['objectid'] ?? '');
        $event_suppressed = (string) ($event['suppressed'] ?? '0') === '1';

        $actions = $this->getTriggerActions(500);
        $results = [];

        foreach ($actions as $action) {
            if ((string) ($action['status'] ?? '0') === '1') {
                $results[] = [
                    'actionid' => $action['actionid'],
                    'name' => $action['name'],
                    'status' => 'disabled',
                    'match_status' => 'disabled',
                    'reasons' => ['Action is disabled.']
                ];
                continue;
            }

            $eval = $this->evaluateActionConditions(
                $action,
                $event_triggerid,
                $event_severity,
                array_map('strval', $host_ids),
                array_map('strval', $host_group_ids),
                $event_tags,
                $event_suppressed
            );

            $results[] = [
                'actionid' => $action['actionid'],
                'name' => $action['name'],
                'status' => 'enabled',
                'match_status' => $eval['status'],
                'reasons' => $eval['reasons'],
                'evaltype' => $action['evaltype'] ?? '0'
            ];
        }

        return $results;
    }

    /**
     * Evaluate whether an action's filter conditions match a given event.
     *
     * Supports the most common condition types (host, host group, trigger,
     * trigger severity, event tag, suppressed status). Unsupported conditions
     * are reported as 'undetermined' rather than silently matching.
     */
    private function evaluateActionConditions(array $action, string $triggerid, int $severity, array $host_ids, array $host_group_ids, array $event_tags, bool $suppressed): array {
        $filter = $action['filter'] ?? [];
        $conditions = is_array($filter['conditions'] ?? null) ? $filter['conditions'] : [];

        if (!$conditions) {
            return ['status' => 'matched', 'reasons' => ['No conditions configured — action applies to all events.']];
        }

        $eval_type = (int) ($filter['evaltype'] ?? 0);
        $reasons = [];
        $matched_count = 0;
        $undetermined_count = 0;

        foreach ($conditions as $cond) {
            $type = (int) ($cond['conditiontype'] ?? -1);
            $operator = (int) ($cond['operator'] ?? 0);
            $value = (string) ($cond['value'] ?? '');
            $value2 = (string) ($cond['value2'] ?? '');

            $matched = null;

            switch ($type) {
                case 0: // host group
                    $matched = $this->evalContains($host_group_ids, $value, $operator);
                    $label = 'host group '.($matched ? 'matches' : 'does not match');
                    break;

                case 1: // host
                    $matched = $this->evalContains($host_ids, $value, $operator);
                    $label = 'host '.($matched ? 'matches' : 'does not match');
                    break;

                case 2: // trigger
                    $matched = $this->evalEquals([$triggerid], $value, $operator);
                    $label = 'trigger '.($matched ? 'matches' : 'does not match');
                    break;

                case 4: // trigger severity
                    $cmp_value = (int) $value;
                    if ($operator === 0)      $matched = $severity === $cmp_value;     // equals
                    elseif ($operator === 1)  $matched = $severity !== $cmp_value;     // does not equal
                    elseif ($operator === 5)  $matched = $severity >= $cmp_value;      // >=
                    elseif ($operator === 6)  $matched = $severity <= $cmp_value;      // <=
                    else                      $matched = null;
                    $label = 'severity '.($matched === true ? 'matches' : ($matched === false ? 'does not match' : 'check skipped'));
                    break;

                case 16: // suppressed
                    $matched = $operator === 11 ? $suppressed : !$suppressed;
                    $label = $matched ? 'suppression state matches' : 'suppression state does not match';
                    break;

                case 25: // event tag
                    $matched = $this->evalTagPresent($event_tags, $value, '', $operator, false);
                    $label = 'event tag "'.$value.'" '.($matched ? 'present' : 'not present');
                    break;

                case 26: // event tag value
                    $matched = $this->evalTagPresent($event_tags, $value, $value2, $operator, true);
                    $label = 'event tag "'.$value.'='.$value2.'" '.($matched ? 'matches' : 'does not match');
                    break;

                default:
                    $matched = null;
                    $label = 'condition type '.$type.' not evaluated';
            }

            if ($matched === true) {
                $matched_count++;
                $reasons[] = $label;
            }
            elseif ($matched === false) {
                $reasons[] = $label;
            }
            else {
                $undetermined_count++;
                $reasons[] = $label;
            }
        }

        $total = count($conditions);
        $status = 'did_not_match';

        // eval_type: 0 = AND/OR, 1 = AND, 2 = OR
        if ($eval_type === 1) {
            // AND: all must match
            $status = ($matched_count + $undetermined_count) >= $total && $matched_count > 0 ? 'matched' : 'did_not_match';
        }
        elseif ($eval_type === 2) {
            // OR: any matches
            $status = $matched_count > 0 ? 'matched' : ($undetermined_count > 0 ? 'undetermined' : 'did_not_match');
        }
        else {
            // AND/OR (group conditions of same type with OR, others with AND) — approximate
            $status = $matched_count >= 1 && $undetermined_count === 0 ? 'matched' : ($undetermined_count > 0 ? 'undetermined' : 'did_not_match');
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    private function evalEquals(array $haystack, string $value, int $operator): bool {
        $in = in_array($value, $haystack, true);
        return $operator === 1 ? !$in : $in;  // 0 = equals, 1 = does not equal
    }

    private function evalContains(array $haystack, string $value, int $operator): bool {
        $in = in_array($value, $haystack, true);
        return $operator === 1 ? !$in : $in;
    }

    private function evalTagPresent(array $event_tags, string $name, string $value, int $operator, bool $check_value): bool {
        foreach ($event_tags as $t) {
            if (($t['tag'] ?? '') !== $name) {
                continue;
            }

            if (!$check_value) {
                return $operator !== 1;  // operator 1 = "does not equal" / "is not present"
            }

            $tag_value = (string) ($t['value'] ?? '');
            $equals = $tag_value === $value;
            $contains = $value === '' || strpos($tag_value, $value) !== false;

            switch ($operator) {
                case 0: return $equals;       // equals
                case 1: return !$equals;      // does not equal
                case 2: return $contains;     // contains
                case 3: return !$contains;    // does not contain
            }

            return false;
        }

        // Tag not present at all.
        if (!$check_value) {
            return $operator === 1;
        }
        return in_array($operator, [1, 3], true);
    }

    /**
     * Compute who would be notified for an event and via which media.
     */
    public function getUserMediaForProblem(string $eventid): array {
        $action_results = $this->getActionsForEvent($eventid);
        $matched_actions = array_filter($action_results, static function ($a) {
            return ($a['match_status'] ?? '') === 'matched';
        });

        if (!$matched_actions) {
            return ['matched_actions' => [], 'recipients' => []];
        }

        // Collect userids and usrgrpids targeted by the matched actions' operations.
        $action_full = $this->call('action.get', [
            'actionids' => array_column($matched_actions, 'actionid'),
            'output' => ['actionid', 'name'],
            'selectOperations' => 'extend'
        ]);

        $userids = [];
        $usrgrpids = [];

        foreach ($action_full as $action) {
            foreach (($action['operations'] ?? []) as $op) {
                foreach (($op['opmessage_usr'] ?? []) as $u) {
                    $userids[] = (string) ($u['userid'] ?? '');
                }
                foreach (($op['opmessage_grp'] ?? []) as $g) {
                    $usrgrpids[] = (string) ($g['usrgrpid'] ?? '');
                }
            }
        }

        $userids = array_values(array_unique(array_filter($userids)));
        $usrgrpids = array_values(array_unique(array_filter($usrgrpids)));

        if ($usrgrpids) {
            $group_users = $this->call('user.get', [
                'usrgrpids' => $usrgrpids,
                'output' => ['userid']
            ]);
            foreach ($group_users as $u) {
                $userids[] = (string) ($u['userid'] ?? '');
            }
            $userids = array_values(array_unique(array_filter($userids)));
        }

        if (!$userids) {
            return [
                'matched_actions' => array_values($matched_actions),
                'recipients' => [],
                'note' => 'Matched actions have no user/group operations.'
            ];
        }

        $users = $this->call('user.get', [
            'userids' => $userids,
            'output' => ['userid', 'username', 'name', 'surname'],
            'selectMedias' => 'extend',
            'selectMediatypes' => ['mediatypeid', 'name', 'status']
        ]);

        $recipients = [];

        foreach ($users as $user) {
            $media_list = [];
            foreach (($user['medias'] ?? []) as $media) {
                $media_list[] = [
                    'mediatypeid' => (string) ($media['mediatypeid'] ?? ''),
                    'sendto' => is_array($media['sendto'] ?? null) ? implode(', ', $media['sendto']) : (string) ($media['sendto'] ?? ''),
                    'enabled' => (string) ($media['active'] ?? '0') === '0',  // 0 = active in Zabbix
                    'severity_mask' => (int) ($media['severity'] ?? 63),
                    'period' => (string) ($media['period'] ?? '')
                ];
            }

            $recipients[] = [
                'userid' => $user['userid'],
                'username' => $user['username'],
                'fullname' => trim(($user['name'] ?? '').' '.($user['surname'] ?? '')),
                'media' => $media_list
            ];
        }

        return [
            'matched_actions' => array_values($matched_actions),
            'recipients' => $recipients
        ];
    }

    /**
     * Combined view of why notifications did (or did not) reach users.
     */
    public function getEscalationPath(string $eventid): array {
        $alerts = $this->getAlertsForEvent($eventid, 200);
        $actions = $this->getActionsForEvent($eventid);
        $delivery = $this->getUserMediaForProblem($eventid);

        $alert_status_map = [
            '0' => 'Not sent',
            '1' => 'Sent',
            '2' => 'Failed',
            '3' => 'New'
        ];

        $alert_summary = [];
        foreach ($alerts as $a) {
            $status = (string) ($a['status'] ?? '');
            $alert_summary[] = [
                'clock' => date('Y-m-d H:i:s', (int) ($a['clock'] ?? 0)),
                'subject' => (string) ($a['subject'] ?? ''),
                'sendto' => (string) ($a['sendto'] ?? ''),
                'mediatype' => (string) (($a['mediatypes'][0]['name'] ?? '')),
                'mediatype_status' => (string) (($a['mediatypes'][0]['status'] ?? '')),
                'status' => $alert_status_map[$status] ?? $status,
                'error' => (string) ($a['error'] ?? ''),
                'retries' => (int) ($a['retries'] ?? 0),
                'user' => (string) (($a['users'][0]['username'] ?? ''))
            ];
        }

        return [
            'eventid' => $eventid,
            'alerts' => $alert_summary,
            'actions' => $actions,
            'recipients' => $delivery['recipients'] ?? [],
            'matched_actions' => $delivery['matched_actions'] ?? []
        ];
    }

    /**
     * Get history values for an item filtered by a time range (UNIX seconds).
     */
    public function getItemHistoryRange(string $itemid, int $since, int $until, int $history_type = 0, int $limit = 1000): array {
        $params = [
            'itemids' => [$itemid],
            'output' => ['clock', 'value', 'ns'],
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 5000),
            'history' => $history_type,
            'time_from' => $since,
            'time_till' => $until
        ];

        $result = $this->call('history.get', $params);

        if (!$result && $history_type === 0) {
            foreach ([3, 1, 4] as $alt) {
                $result = $this->call('history.get', array_merge($params, ['history' => $alt]));
                if ($result) {
                    break;
                }
            }
        }

        return array_reverse(is_array($result) ? $result : []);
    }

    /**
     * Get recent audit log entries related to an object or host.
     */
    public function getRecentAuditLogs(int $since_unix, array $resourcetypes = [], int $limit = 100): array {
        $params = [
            'output' => 'extend',
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 500),
            'time_from' => $since_unix
        ];

        if ($resourcetypes) {
            $params['filter'] = ['resourcetype' => array_values(array_map('intval', $resourcetypes))];
        }

        try {
            return $this->call('auditlog.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get acknowledgement / comment history for an event.
     */
    public function getEventAcknowledgements(string $eventid): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            return [];
        }

        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid'],
            'select_acknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action'],
            'limit' => 1
        ]);

        return $events[0]['acknowledges'] ?? [];
    }

    /**
     * Get maintenance windows currently in effect for a host.
     */
    public function getActiveMaintenanceForHost(string $hostname): array {
        $hid = $this->getHostIdByName($hostname);

        if ($hid === null) {
            return [];
        }

        $now = time();

        $maintenances = $this->call('maintenance.get', [
            'hostids' => [$hid],
            'output' => ['maintenanceid', 'name', 'active_since', 'active_till', 'maintenance_type'],
            'selectTags' => ['tag', 'operator', 'value']
        ]);

        return array_values(array_filter($maintenances, static function ($m) use ($now) {
            $start = (int) ($m['active_since'] ?? 0);
            $end = (int) ($m['active_till'] ?? 0);
            return $start <= $now && $end > $now;
        }));
    }

    /**
     * Aggregate every piece of context that helps explain why a problem fired.
     *
     * Pulls event/trigger/items/host/inventory/maintenance/recent-problems/audit/
     * acknowledgements and (optionally) recent item history. Returns a structured
     * array suitable for rendering as a Markdown report.
     */
    public function getEvidenceBundle(string $eventid, int $period_hours = 24, int $history_per_item = 30, bool $include_audit = true): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            throw new RuntimeException('Event id is required.');
        }

        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => 'extend',
            'selectHosts' => ['hostid', 'host', 'name'],
            'selectTags' => ['tag', 'value'],
            'select_acknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action'],
            'limit' => 1
        ]);

        if (!$events) {
            throw new RuntimeException('Event '.$eventid.' not found.');
        }

        $event = $events[0];
        $triggerid = (string) ($event['objectid'] ?? '');
        $hostname = (string) (($event['hosts'][0]['host'] ?? ''));
        $event_clock = (int) ($event['clock'] ?? time());
        $period_hours = max(1, min($period_hours, 168));
        $since = $event_clock - ($period_hours * 3600);
        $until = $event_clock + (3600);

        $bundle = [
            'eventid' => $eventid,
            'generated_at' => time(),
            'event' => [
                'name' => (string) ($event['name'] ?? ''),
                'severity' => (int) ($event['severity'] ?? 0),
                'clock' => $event_clock,
                'clock_str' => date('Y-m-d H:i:s', $event_clock),
                'value' => (int) ($event['value'] ?? 0),
                'r_eventid' => (string) ($event['r_eventid'] ?? ''),
                'acknowledged' => (string) ($event['acknowledged'] ?? '0') === '1',
                'suppressed' => (string) ($event['suppressed'] ?? '0') === '1',
                'hostname' => $hostname,
                'tags' => array_map(static function ($t) {
                    return [
                        'tag' => (string) ($t['tag'] ?? ''),
                        'value' => (string) ($t['value'] ?? '')
                    ];
                }, $event['tags'] ?? [])
            ],
            'trigger' => null,
            'items' => [],
            'item_history' => [],
            'host' => null,
            'host_templates' => [],
            'maintenance' => [],
            'recent_problems' => [],
            'audit' => [],
            'acknowledgements' => [],
            'period_hours' => $period_hours
        ];

        if ($triggerid !== '') {
            $triggers = $this->call('trigger.get', [
                'triggerids' => [$triggerid],
                'output' => ['triggerid', 'description', 'expression', 'recovery_expression', 'priority', 'status', 'value', 'comments', 'url', 'lastchange'],
                'selectItems' => ['itemid', 'name', 'key_', 'value_type', 'units', 'lastvalue'],
                'selectDependencies' => ['triggerid', 'description'],
                'selectHosts' => ['hostid', 'host', 'name'],
                'expandExpression' => true,
                'expandDescription' => true,
                'limit' => 1
            ]);

            if ($triggers) {
                $trigger = $triggers[0];
                $bundle['trigger'] = [
                    'triggerid' => (string) $trigger['triggerid'],
                    'description' => (string) ($trigger['description'] ?? ''),
                    'expression' => (string) ($trigger['expression'] ?? ''),
                    'recovery_expression' => (string) ($trigger['recovery_expression'] ?? ''),
                    'priority' => (int) ($trigger['priority'] ?? 0),
                    'status' => (string) ($trigger['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled',
                    'state' => (string) ($trigger['value'] ?? '0') === '1' ? 'PROBLEM' : 'OK',
                    'comments' => (string) ($trigger['comments'] ?? ''),
                    'url' => (string) ($trigger['url'] ?? ''),
                    'lastchange' => (int) ($trigger['lastchange'] ?? 0),
                    'dependencies' => array_map(static function ($d) {
                        return [
                            'triggerid' => (string) ($d['triggerid'] ?? ''),
                            'description' => (string) ($d['description'] ?? '')
                        ];
                    }, $trigger['dependencies'] ?? [])
                ];

                foreach ($trigger['items'] ?? [] as $item) {
                    $bundle['items'][] = [
                        'itemid' => (string) ($item['itemid'] ?? ''),
                        'name' => (string) ($item['name'] ?? ''),
                        'key_' => (string) ($item['key_'] ?? ''),
                        'value_type' => (int) ($item['value_type'] ?? 0),
                        'units' => (string) ($item['units'] ?? ''),
                        'lastvalue' => (string) ($item['lastvalue'] ?? '')
                    ];

                    $itemid = (string) ($item['itemid'] ?? '');
                    if ($itemid === '') {
                        continue;
                    }

                    $history = $this->getItemHistoryRange($itemid, $since, $until, 0, $history_per_item);
                    $values = [];
                    foreach ($history as $h) {
                        $values[] = [
                            'time' => date('Y-m-d H:i:s', (int) ($h['clock'] ?? 0)),
                            'value' => (string) ($h['value'] ?? '')
                        ];
                    }
                    if ($values) {
                        $bundle['item_history'][$itemid] = [
                            'name' => (string) ($item['name'] ?? ''),
                            'key_' => (string) ($item['key_'] ?? ''),
                            'units' => (string) ($item['units'] ?? ''),
                            'values' => $values
                        ];
                    }
                }
            }
        }

        if ($hostname !== '') {
            $host = $this->getHostInfo($hostname);

            if ($host !== null) {
                $bundle['host'] = $host;

                $hid = (string) ($host['hostid'] ?? '');
                if ($hid !== '') {
                    $templates = $this->call('host.get', [
                        'hostids' => [$hid],
                        'output' => ['hostid'],
                        'selectParentTemplates' => ['templateid', 'name']
                    ]);
                    foreach (($templates[0]['parentTemplates'] ?? []) as $tpl) {
                        $bundle['host_templates'][] = (string) ($tpl['name'] ?? '');
                    }
                }
            }

            $bundle['maintenance'] = $this->getActiveMaintenanceForHost($hostname);
            $bundle['recent_problems'] = $this->getRecentProblemsForHost($hostname, $since, 50);
        }

        $bundle['acknowledgements'] = array_map(function ($ack) {
            return [
                'acknowledgeid' => (string) ($ack['acknowledgeid'] ?? ''),
                'userid' => (string) ($ack['userid'] ?? ''),
                'message' => (string) ($ack['message'] ?? ''),
                'clock' => (int) ($ack['clock'] ?? 0),
                'clock_str' => date('Y-m-d H:i:s', (int) ($ack['clock'] ?? 0)),
                'action' => (int) ($ack['action'] ?? 0)
            ];
        }, $event['acknowledges'] ?? []);

        if ($include_audit) {
            // Audit resource types: 13 = trigger, 15 = item, 4 = host. Filter to recent window.
            $bundle['audit'] = $this->getRecentAuditLogs($since, [13, 15, 4], 50);
        }

        return $bundle;
    }

    /**
     * Fetch problem events opened within [$since_unix, $until_unix] for graphing.
     *
     * Returns minimal records {clock, severity} suitable for bucketing. Iterates
     * in pages by eventid to avoid the default API result limit.
     *
     * @param int $severity_min Lowest severity to include (0-5).
     * @param array $host_group_ids Optional restriction to host group IDs.
     * @param array $host_ids Optional restriction to specific host IDs.
     */
    public function getProblemsTimeline(int $since_unix, int $until_unix, int $severity_min = 0, array $host_group_ids = [], int $max_rows = 10000, array $host_ids = []): array {
        $severity_min = max(0, min($severity_min, 5));
        $max_rows = max(100, min($max_rows, 50000));

        $page_size = 1000;
        $collected = [];
        $eventid_till = null;
        $safety_pages = (int) ceil($max_rows / $page_size) + 5;

        for ($page = 0; $page < $safety_pages; $page++) {
            $params = [
                'output' => ['eventid', 'clock', 'severity'],
                'source' => 0,
                'object' => 0,
                'value' => 1,
                'time_from' => $since_unix,
                'time_till' => $until_unix,
                'severities' => range($severity_min, 5),
                'sortfield' => ['eventid'],
                'sortorder' => 'DESC',
                'limit' => $page_size
            ];

            if ($eventid_till !== null) {
                $params['eventid_till'] = $eventid_till;
            }

            if ($host_group_ids) {
                $params['groupids'] = array_values(array_map('strval', $host_group_ids));
            }

            if ($host_ids) {
                $params['hostids'] = array_values(array_map('strval', $host_ids));
            }

            $batch = $this->call('event.get', $params);

            if (!$batch) {
                break;
            }

            foreach ($batch as $row) {
                $collected[] = [
                    'clock' => (int) ($row['clock'] ?? 0),
                    'severity' => (int) ($row['severity'] ?? 0)
                ];
            }

            if (count($collected) >= $max_rows || count($batch) < $page_size) {
                break;
            }

            // Page backwards: next call returns events with eventid < smallest seen.
            $last = end($batch);
            $eventid_till = isset($last['eventid']) ? (string) ((int) $last['eventid'] - 1) : null;

            if ($eventid_till === null || $eventid_till === '-1') {
                break;
            }
        }

        return $collected;
    }

    /**
     * Get recent problems for a host within a time window.
     */
    public function getRecentProblemsForHost(string $hostname, int $since_unix, int $limit = 50): array {
        $hid = $this->getHostIdByName($hostname);

        if ($hid === null) {
            return [];
        }

        return $this->call('problem.get', [
            'hostids' => [$hid],
            'output' => ['eventid', 'name', 'severity', 'clock', 'r_eventid', 'acknowledged'],
            'time_from' => $since_unix,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 500)
        ]);
    }

    /**
     * Event timeline: when the event was created, recovered, acknowledged,
     * suppressed and commented. Useful for reconstructing what happened.
     */
    public function getEventTimeline(string $eventid): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            throw new RuntimeException('Event id is required.');
        }

        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'clock', 'r_eventid', 'severity', 'name', 'acknowledged', 'suppressed'],
            'selectHosts' => ['hostid', 'host'],
            'select_acknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action', 'old_severity', 'new_severity', 'suppress_until'],
            'limit' => 1
        ]);

        if (!$events) {
            return [];
        }

        $event = $events[0];
        $entries = [];

        $entries[] = [
            'clock' => (int) $event['clock'],
            'kind' => 'opened',
            'description' => 'Problem started: '.($event['name'] ?? '')
        ];

        $r_id = (string) ($event['r_eventid'] ?? '');

        if ($r_id !== '' && $r_id !== '0') {
            $recovery = $this->call('event.get', [
                'eventids' => [$r_id],
                'output' => ['eventid', 'clock'],
                'limit' => 1
            ]);
            if ($recovery) {
                $entries[] = [
                    'clock' => (int) ($recovery[0]['clock'] ?? 0),
                    'kind' => 'recovered',
                    'description' => 'Problem recovered (recovery event '.$r_id.')'
                ];
            }
        }

        $action_labels = [
            1 => 'closed', 2 => 'acknowledged', 4 => 'commented',
            8 => 'severity changed', 16 => 'unacknowledged',
            32 => 'suppressed', 64 => 'unsuppressed',
            128 => 'changed to cause', 256 => 'changed to symptom'
        ];

        foreach ($event['acknowledges'] ?? [] as $ack) {
            $bitmask = (int) ($ack['action'] ?? 0);
            $tags = [];
            foreach ($action_labels as $bit => $label) {
                if ($bitmask & $bit) {
                    $tags[] = $label;
                }
            }
            $tags_str = $tags ? implode(', ', $tags) : ('action '.$bitmask);

            $line = $tags_str;
            if (!empty($ack['message'])) {
                $line .= ': '.$ack['message'];
            }
            if (($bitmask & 8) && isset($ack['old_severity'], $ack['new_severity'])) {
                $line .= ' (severity '.$ack['old_severity'].' → '.$ack['new_severity'].')';
            }
            if (($bitmask & 32) && !empty($ack['suppress_until'])) {
                $line .= ' (until '.date('Y-m-d H:i', (int) $ack['suppress_until']).')';
            }

            $entries[] = [
                'clock' => (int) ($ack['clock'] ?? 0),
                'kind' => 'ack',
                'userid' => (string) ($ack['userid'] ?? ''),
                'description' => $line
            ];
        }

        usort($entries, static function ($a, $b) {
            return $a['clock'] <=> $b['clock'];
        });

        return [
            'eventid' => $eventid,
            'hostname' => (string) (($event['hosts'][0]['host'] ?? '')),
            'entries' => $entries
        ];
    }

    /**
     * Related problems for an event: same host, same host groups, and same trigger tags.
     */
    public function getRelatedProblems(string $eventid, int $window_hours = 24, int $limit = 50): array {
        $eventid = trim($eventid);

        if ($eventid === '') {
            return [];
        }

        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'clock', 'name'],
            'selectHosts' => ['hostid', 'host'],
            'selectTags' => ['tag', 'value'],
            'limit' => 1
        ]);

        if (!$events) {
            return [];
        }

        $event = $events[0];
        $host_ids = array_column($event['hosts'] ?? [], 'hostid');
        $event_clock = (int) ($event['clock'] ?? time());
        $since = $event_clock - ($window_hours * 3600);
        $until = $event_clock + 3600;

        $tags_filter = [];
        foreach (($event['tags'] ?? []) as $t) {
            $tag = (string) ($t['tag'] ?? '');
            if ($tag === '') {
                continue;
            }
            $tags_filter[] = [
                'tag' => $tag,
                'operator' => 0,
                'value' => (string) ($t['value'] ?? '')
            ];
        }

        $by_host = $host_ids
            ? $this->call('problem.get', [
                'hostids' => $host_ids,
                'output' => ['eventid', 'name', 'severity', 'clock', 'r_eventid'],
                'time_from' => $since,
                'time_till' => $until,
                'sortfield' => ['eventid'],
                'sortorder' => 'DESC',
                'limit' => $limit
            ])
            : [];

        $by_tag = [];
        if ($tags_filter) {
            $by_tag = $this->call('problem.get', [
                'output' => ['eventid', 'name', 'severity', 'clock', 'r_eventid'],
                'selectHosts' => ['hostid', 'host'],
                'tags' => $tags_filter,
                'evaltype' => 2,  // any tag matches
                'time_from' => $since,
                'time_till' => $until,
                'sortfield' => ['eventid'],
                'sortorder' => 'DESC',
                'limit' => $limit
            ]);
        }

        $filter_self = static function (array $rows) use ($eventid) {
            return array_values(array_filter($rows, static function ($p) use ($eventid) {
                return (string) ($p['eventid'] ?? '') !== $eventid;
            }));
        };

        return [
            'eventid' => $eventid,
            'window_hours' => $window_hours,
            'by_host' => $filter_self($by_host),
            'by_tag' => $filter_self($by_tag),
            'tag_filter' => $tags_filter
        ];
    }

    /**
     * Service-tree impact for an event: the services that include the affected host.
     */
    public function getServiceImpact(string $eventid): array {
        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'objectid'],
            'limit' => 1
        ]);

        if (!$events) {
            return [];
        }

        $triggerid = (string) ($events[0]['objectid'] ?? '');

        if ($triggerid === '') {
            return [];
        }

        try {
            $services = $this->call('service.get', [
                'output' => ['serviceid', 'name', 'status', 'algorithm'],
                'selectProblemTags' => 'extend',
                'selectParents' => ['serviceid', 'name'],
                'selectChildren' => ['serviceid', 'name']
            ]);
        }
        catch (\Throwable $e) {
            return ['error' => 'service.get not available: '.$e->getMessage()];
        }

        return [
            'eventid' => $eventid,
            'triggerid' => $triggerid,
            'services' => $services
        ];
    }

    /**
     * Templates linked to a host (both direct and inherited).
     */
    public function getHostTemplates(string $hostname): array {
        $hid = $this->getHostIdByName($hostname);

        if ($hid === null) {
            return [];
        }

        $result = $this->call('host.get', [
            'hostids' => [$hid],
            'output' => ['hostid'],
            'selectParentTemplates' => ['templateid', 'name', 'host'],
            'selectInheritedTags' => ['tag', 'value']
        ]);

        if (!$result) {
            return [];
        }

        return [
            'hostname' => $hostname,
            'templates' => $result[0]['parentTemplates'] ?? [],
            'inherited_tags' => $result[0]['inheritedTags'] ?? []
        ];
    }

    /**
     * Look up the value of a global user macro (e.g. "{$ZABBIX.URL}").
     *
     * Returns null when the macro does not exist, is empty, is a secret/vault
     * type (we never surface those), or when the API call fails. Best-effort:
     * never throws.
     */
    public function getGlobalMacroValue(string $macro): ?string {
        $macro = trim($macro);

        if ($macro === '') {
            return null;
        }

        try {
            $result = $this->call('usermacro.get', [
                'globalmacro' => true,
                'output' => ['macro', 'value', 'type'],
                'filter' => ['macro' => $macro]
            ]);
        }
        catch (\Throwable $e) {
            return null;
        }

        if (!is_array($result) || empty($result[0])) {
            return null;
        }

        // Type 1 = secret, 2 = vault. Never return non-text macros.
        if ((int) ($result[0]['type'] ?? 0) !== 0) {
            return null;
        }

        $value = trim((string) ($result[0]['value'] ?? ''));
        return $value !== '' ? $value : null;
    }

    /**
     * Best-effort resolution of the Zabbix frontend base URL.
     *
     * Order:
     *   1. The {$ZABBIX.URL} global macro (operator-configured).
     *   2. Stripping /api_jsonrpc.php from the API URL the module is using.
     *
     * Result is cached per request and returned without a trailing slash.
     */
    public function getFrontendUrl(): string {
        if (isset($this->frontend_url_cache)) {
            return $this->frontend_url_cache;
        }

        $url = $this->getGlobalMacroValue('{$ZABBIX.URL}');

        if ($url === null || $url === '') {
            $url = preg_replace('#/?api_jsonrpc\.php/?$#i', '', (string) $this->url);
        }

        $url = rtrim((string) $url, '/');

        // Only accept http(s) URLs so we never inject something the chat can't link to.
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = '';
        }

        $this->frontend_url_cache = $url;
        return $url;
    }

    /**
     * Effective user macros for a host (own + inherited from templates).
     */
    public function getEffectiveMacros(string $hostname): array {
        $hid = $this->getHostIdByName($hostname);

        if ($hid === null) {
            return [];
        }

        $own = $this->call('usermacro.get', [
            'hostids' => [$hid],
            'output' => ['macro', 'value', 'description', 'type'],
            'sortfield' => 'macro'
        ]);

        $tpl = $this->call('host.get', [
            'hostids' => [$hid],
            'output' => ['hostid'],
            'selectParentTemplates' => ['templateid', 'name']
        ]);

        $template_macros = [];
        $tpl_ids = array_column($tpl[0]['parentTemplates'] ?? [], 'templateid');
        if ($tpl_ids) {
            $template_macros = $this->call('usermacro.get', [
                'hostids' => $tpl_ids,
                'output' => ['macro', 'value', 'description', 'type', 'hostid']
            ]);
        }

        $masked = static function ($m) {
            if (in_array((int) ($m['type'] ?? 0), [1, 2], true)) {  // secret or vault
                $m['value'] = '*****';
            }
            return $m;
        };

        return [
            'hostname' => $hostname,
            'host_macros' => array_map($masked, $own),
            'template_macros' => array_map($masked, $template_macros)
        ];
    }

    /**
     * LLD rules configured on a host.
     */
    public function getLldRules(string $hostname): array {
        $hid = $this->getHostIdByName($hostname);

        if ($hid === null) {
            return [];
        }

        return $this->call('discoveryrule.get', [
            'hostids' => [$hid],
            'output' => ['itemid', 'name', 'key_', 'type', 'state', 'status', 'lifetime', 'error', 'delay'],
            'selectFilter' => 'extend',
            'sortfield' => 'name',
            'limit' => 500
        ]);
    }

    /**
     * Zabbix proxies with availability + last seen.
     */
    public function getProxyStatus(): array {
        try {
            return $this->call('proxy.get', [
                'output' => ['proxyid', 'name', 'operating_mode', 'lastaccess', 'version', 'compatibility', 'state', 'address'],
                'sortfield' => 'name'
            ]);
        }
        catch (\Throwable $e) {
            return $this->call('proxy.get', [
                'output' => ['proxyid', 'host', 'status', 'lastaccess'],
                'sortfield' => 'host'
            ]);
        }
    }

    /**
     * Single action's full configuration.
     */
    public function getActionConfig(string $actionid): array {
        $result = $this->call('action.get', [
            'actionids' => [$actionid],
            'output' => 'extend',
            'selectFilter' => 'extend',
            'selectOperations' => 'extend',
            'selectRecoveryOperations' => 'extend',
            'selectUpdateOperations' => 'extend'
        ]);

        return $result[0] ?? [];
    }

    /**
     * Audit log entries for a specific Zabbix object (trigger, item, host, etc.).
     *
     * @param int      $resourcetype Zabbix audit resource type (see API docs).
     * @param string   $resourceid   Object ID to filter on.
     */
    public function getAuditLogForObject(int $resourcetype, string $resourceid, int $since_unix = 0, int $limit = 50): array {
        $params = [
            'output' => 'extend',
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 500),
            'filter' => [
                'resourcetype' => $resourcetype,
                'resourceid' => $resourceid
            ]
        ];

        if ($since_unix > 0) {
            $params['time_from'] = $since_unix;
        }

        try {
            return $this->call('auditlog.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve a Zabbix username to its numeric user id. Returns null if no such
     * user exists or the API user lacks permission to list users.
     */
    public function getUserIdByUsername(string $username): ?string {
        $username = trim($username);

        if ($username === '') {
            return null;
        }

        try {
            $result = $this->call('user.get', [
                'output' => ['userid', 'username'],
                'filter' => ['username' => [$username]]
            ]);
        }
        catch (\Throwable $e) {
            return null;
        }

        return isset($result[0]['userid']) ? (string) $result[0]['userid'] : null;
    }

    /**
     * Search the audit log (auditlog.get). $filter may contain userid, action
     * (int or array of ints), resourcetype, etc. Returns [] on error/permission
     * denied — the Zabbix audit log is readable by Super Admin users only.
     */
    public function getAuditLog(array $filter = [], int $time_from = 0, int $time_till = 0, int $limit = 50): array {
        $params = [
            'output' => 'extend',
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit' => min(max($limit, 1), 500)
        ];

        $params = self::promoteAuditUserids($params, $filter);
        if ($filter) {
            $params['filter'] = $filter;
        }
        if ($time_from > 0) {
            $params['time_from'] = $time_from;
        }
        if ($time_till > 0) {
            $params['time_till'] = $time_till;
        }

        try {
            return $this->call('auditlog.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Count audit-log entries matching $filter (auditlog.get with countOutput).
     * Returns null on error/permission denied so callers can tell "0 matches"
     * apart from "could not read the audit log".
     */
    public function countAuditLog(array $filter = [], int $time_from = 0, int $time_till = 0): ?int {
        $params = ['countOutput' => true];

        $params = self::promoteAuditUserids($params, $filter);
        if ($filter) {
            $params['filter'] = $filter;
        }
        if ($time_from > 0) {
            $params['time_from'] = $time_from;
        }
        if ($time_till > 0) {
            $params['time_till'] = $time_till;
        }

        try {
            $result = $this->call('auditlog.get', $params);
        }
        catch (\Throwable $e) {
            return null;
        }

        // countOutput returns a scalar count, which extractResult() wraps as a
        // single-element array.
        if (!isset($result[0]) || !is_scalar($result[0])) {
            return null;
        }

        return (int) $result[0];
    }

    /**
     * auditlog.get expects userid as the top-level "userids" parameter, not a
     * filter field. Move it there and drop it from the filter.
     */
    private static function promoteAuditUserids(array $params, array &$filter): array {
        if (isset($filter['userid'])) {
            $uid = $filter['userid'];
            unset($filter['userid']);
            if ($uid !== '' && $uid !== []) {
                $params['userids'] = is_array($uid) ? array_values($uid) : [$uid];
            }
        }
        return $params;
    }

    /**
     * Suppress a problem event.
     *
     * @param string $eventid
     * @param int    $until_unix If 0, suppression is indefinite (until manual unsuppress).
     */
    public function suppressProblem(string $eventid, int $until_unix = 0): array {
        // Zabbix requires suppress_until with the suppress action bit; 0 means
        // suppress indefinitely. Always send it so the API never rejects it.
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 32,
            'suppress_until' => max(0, $until_unix)
        ]);
    }

    /**
     * Lift the suppression on a problem event.
     */
    public function unsuppressProblem(string $eventid): array {
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 64
        ]);
    }

    /**
     * Promote a problem event to "cause" rank.
     */
    public function markProblemAsCause(string $eventid): array {
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 128
        ]);
    }

    /**
     * Mark a problem event as a "symptom" of a specific cause event.
     *
     * Zabbix requires cause_eventid when changing an event's rank to symptom
     * (event.acknowledge action bit 256); without it the API rejects the call.
     */
    public function markProblemAsSymptom(string $eventid, string $cause_eventid): array {
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 256,
            'cause_eventid' => $cause_eventid
        ]);
    }

    /**
     * Change a problem event's severity (event.acknowledge action bit 8).
     *
     * @param int $severity 0=Not classified .. 5=Disaster.
     */
    public function changeProblemSeverity(string $eventid, int $severity): array {
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 8,
            'severity' => $severity
        ]);
    }

    /**
     * Un-acknowledge a problem event (event.acknowledge action bit 16).
     */
    public function unacknowledgeProblem(string $eventid): array {
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 16
        ]);
    }

    // ── Phase 2 read diagnostics ──────────────────────────────────────────

    /**
     * List a host's interfaces (Agent/SNMP/IPMI/JMX) with availability/errors.
     */
    public function getHostInterfaces(string $hostname): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            return [];
        }

        try {
            return $this->call('hostinterface.get', [
                'output' => 'extend',
                'hostids' => [$hid]
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Summarise a numeric item over a window (last/min/max/avg/p95) using raw
     * history for short windows and hourly trends for longer ones, so callers
     * never have to ship raw history to the model. Returns ['error' => reason]
     * on failure, otherwise an aggregate array.
     */
    public function getMetricSummary(string $hostname, string $item_search, int $period_hours = 24): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            return ['error' => 'host_not_found'];
        }

        $params = [
            'output' => ['itemid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock'],
            'hostids' => [$hid],
            'sortfield' => 'name',
            'limit' => 10
        ];
        if ($item_search !== '') {
            $params['search'] = ['name' => $item_search];
        }

        try {
            $items = $this->call('item.get', $params);
        }
        catch (\Throwable $e) {
            return ['error' => 'item_query_failed'];
        }
        if (!$items) {
            return ['error' => 'no_item'];
        }

        $item = null;
        foreach ($items as $it) {
            if (in_array((int) ($it['value_type'] ?? -1), [0, 3], true)) {
                $item = $it;
                break;
            }
        }
        if ($item === null) {
            return [
                'error' => 'not_numeric',
                'candidates' => array_slice(array_map(static function($i) { return (string) ($i['name'] ?? ''); }, $items), 0, 10)
            ];
        }

        $value_type = (int) $item['value_type'];
        $period_hours = max(1, min($period_hours, 8760));
        $time_from = time() - $period_hours * 3600;

        if ($period_hours <= 12) {
            try {
                $history = $this->call('history.get', [
                    'itemids' => [$item['itemid']],
                    'history' => $value_type,
                    'time_from' => $time_from,
                    'output' => ['clock', 'value'],
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit' => 50000
                ]);
            }
            catch (\Throwable $e) {
                $history = [];
            }

            $nums = [];
            foreach ($history as $h) {
                $nums[] = (float) ($h['value'] ?? 0);
            }
            if ($nums) {
                $first = $nums[0];
                $last = $nums[count($nums) - 1];
                sort($nums);
                $n = count($nums);
                return [
                    'item' => $item,
                    'source' => 'history',
                    'period_hours' => $period_hours,
                    'count' => $n,
                    'min' => $nums[0],
                    'max' => $nums[$n - 1],
                    'avg' => array_sum($nums) / $n,
                    'p95' => $nums[(int) floor(0.95 * ($n - 1))],
                    'first' => $first,
                    'last' => $last,
                    'units' => (string) ($item['units'] ?? '')
                ];
            }
            // fall through to trends if no raw history is kept
        }

        try {
            $trends = $this->call('trend.get', [
                'itemids' => [$item['itemid']],
                'time_from' => $time_from,
                'output' => ['clock', 'num', 'value_min', 'value_avg', 'value_max'],
                'limit' => 9000
            ]);
        }
        catch (\Throwable $e) {
            $trends = [];
        }

        if (!$trends) {
            return ['error' => 'no_data', 'item' => $item, 'period_hours' => $period_hours];
        }

        $min = null;
        $max = null;
        $sum = 0.0;
        $weight = 0;
        $first = null;
        $last = null;
        $first_clock = PHP_INT_MAX;
        $last_clock = -1;
        foreach ($trends as $t) {
            $vmin = (float) ($t['value_min'] ?? 0);
            $vmax = (float) ($t['value_max'] ?? 0);
            $vavg = (float) ($t['value_avg'] ?? 0);
            $num = max(1, (int) ($t['num'] ?? 1));
            $clock = (int) ($t['clock'] ?? 0);
            $min = ($min === null) ? $vmin : min($min, $vmin);
            $max = ($max === null) ? $vmax : max($max, $vmax);
            $sum += $vavg * $num;
            $weight += $num;
            if ($clock < $first_clock) { $first_clock = $clock; $first = $vavg; }
            if ($clock > $last_clock) { $last_clock = $clock; $last = $vavg; }
        }

        return [
            'item' => $item,
            'source' => 'trends',
            'period_hours' => $period_hours,
            'count' => count($trends),
            'min' => $min,
            'max' => $max,
            'avg' => $weight > 0 ? $sum / $weight : 0,
            'first' => $first,
            'last' => $last,
            'units' => (string) ($item['units'] ?? '')
        ];
    }

    /**
     * Triggers with their dependencies (for root-cause vs symptom analysis).
     */
    public function getTriggerDependencies(string $hostname = '', string $search = ''): array {
        $params = [
            'output' => ['triggerid', 'description', 'status', 'value', 'priority'],
            'selectDependencies' => ['triggerid', 'description'],
            'selectHosts' => ['host'],
            'expandDescription' => true,
            'sortfield' => 'description',
            'limit' => 300
        ];
        if ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid === null) {
                return [];
            }
            $params['hostids'] = [$hid];
        }
        if ($search !== '') {
            $params['search'] = ['description' => $search];
        }

        try {
            return $this->call('trigger.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Web monitoring scenarios (HTTP checks) with their steps and status.
     */
    public function getWebScenarios(string $hostname = ''): array {
        $params = [
            'output' => ['httptestid', 'name', 'delay', 'status'],
            'selectSteps' => ['no', 'name', 'url', 'status_codes'],
            'selectHosts' => ['host'],
            'sortfield' => 'name',
            'limit' => 200
        ];
        if ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid === null) {
                return [];
            }
            $params['hostids'] = [$hid];
        }

        try {
            return $this->call('httptest.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Triggers that fired the most problem events over a window (noisiest).
     */
    public function getNoisyTriggers(int $period_hours = 24, int $limit = 15): array {
        $time_from = time() - max(1, $period_hours) * 3600;

        try {
            $events = $this->call('event.get', [
                'output' => ['eventid', 'objectid', 'name'],
                'source' => 0,
                'object' => 0,
                'value' => 1,
                'time_from' => $time_from,
                'sortfield' => ['clock'],
                'sortorder' => 'DESC',
                'limit' => 10000
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }

        $counts = [];
        $names = [];
        foreach ($events as $ev) {
            $tid = (string) ($ev['objectid'] ?? '');
            if ($tid === '') {
                continue;
            }
            $counts[$tid] = ($counts[$tid] ?? 0) + 1;
            if (!isset($names[$tid])) {
                $names[$tid] = (string) ($ev['name'] ?? '');
            }
        }
        if (!$counts) {
            return [];
        }

        arsort($counts);
        $top = array_slice($counts, 0, max(1, $limit), true);

        $hostmap = [];
        try {
            $triggers = $this->call('trigger.get', [
                'triggerids' => array_keys($top),
                'output' => ['triggerid'],
                'selectHosts' => ['host']
            ]);
            foreach ($triggers as $t) {
                $hostmap[(string) $t['triggerid']] = (string) ($t['hosts'][0]['host'] ?? '');
            }
        }
        catch (\Throwable $e) {
        }

        $rows = [];
        foreach ($top as $tid => $count) {
            $rows[] = [
                'triggerid' => (string) $tid,
                'name' => $names[$tid] ?? '',
                'host' => $hostmap[$tid] ?? '',
                'count' => $count
            ];
        }
        return $rows;
    }

    /**
     * SLA definitions with their target SLO and service tags.
     */
    public function getSlaOverview(int $limit = 50): array {
        try {
            return $this->call('sla.get', [
                'output' => ['slaid', 'name', 'period', 'slo', 'status', 'timezone'],
                'selectServiceTags' => ['tag', 'operator', 'value'],
                'sortfield' => 'name',
                'limit' => min(max($limit, 1), 200)
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }
    }

    // ── SLA & IT service tooling (tag-scoped SLA creation) ────────────────

    /**
     * Normalise a match-tag array into [{tag, operator, value}].
     * Operator is clamped to {0 = equals, 2 = contains} (no other values exist).
     * Used for service.problem_tags and sla.service_tags. Public so the
     * executor can run its SLA-scope guards on the same normalised form that
     * is sent to the API.
     */
    public function normalizeMatchTags(array $tags): array {
        $out = [];
        foreach ($tags as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = trim((string) ($t['tag'] ?? ''));
            if ($name === '') {
                continue;
            }
            $op = (int) ($t['operator'] ?? 0);
            if (!in_array($op, [0, 2], true)) {
                $op = 0;
            }
            // Matcher values are trimmed like stored tag values: the Zabbix
            // server trims event tag values at creation, so a padded matcher
            // could never equals-match anything real.
            $out[] = ['tag' => $name, 'operator' => $op, 'value' => trim((string) ($t['value'] ?? ''))];
        }
        return $out;
    }

    /**
     * Normalise plain key/value tags into [{tag, value}] (service.tags, host/
     * template/trigger tags).
     */
    private function normalizePlainTags(array $tags): array {
        $out = [];
        foreach ($tags as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = trim((string) ($t['tag'] ?? ''));
            if ($name === '') {
                continue;
            }
            // Values are trimmed too: padded values render identically to
            // their trimmed twins everywhere but break exact-match selection.
            $out[] = ['tag' => $name, 'value' => trim((string) ($t['value'] ?? ''))];
        }
        return $out;
    }

    /**
     * Create an IT service, optionally linked into a parent/child hierarchy.
     *
     * A LEAF service maps problems via problem_tags (AND logic: a problem must
     * carry ALL listed tags) and carries plain service tags that an SLA selects
     * on (OR logic). A PARENT/GROUP service may omit problem_tags and instead
     * aggregates the existing services in $child_serviceids.
     *
     * @param array $problem_tags     [{tag, operator, value}] — AND-combined matchers.
     * @param array $service_tags     [{tag, value}] — the SLA selection handle.
     * @param array $parent_serviceids Existing services this one is attached under.
     * @param array $child_serviceids  Existing services attached as children.
     */
    public function createSlaService(string $name, array $problem_tags, array $service_tags, int $algorithm = 1, int $sortorder = 0, array $parent_serviceids = [], array $child_serviceids = []): array {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Service name is required.');
        }

        $problems = $this->normalizeMatchTags($problem_tags);
        if (!$problems && !$child_serviceids) {
            throw new RuntimeException('At least one problem_tag is required so the service can map problems (only a parent service with child_serviceids may omit them).');
        }
        if ($problems && $child_serviceids) {
            throw new RuntimeException('Zabbix does not allow a service to have both problem_tags and children — a LEAF maps problems, a PARENT aggregates children.');
        }

        $plain = $this->normalizePlainTags($service_tags);

        if (!in_array($algorithm, [0, 1, 2], true)) {
            $algorithm = $child_serviceids ? 2 : 1;
        }

        $payload = [
            'name' => Util::truncate($name, 128),
            'algorithm' => $algorithm,
            'sortorder' => max(0, min(999, $sortorder))
        ];
        if ($problems) {
            $payload['problem_tags'] = $problems;
        }
        if ($plain) {
            $payload['tags'] = $plain;
        }
        if ($parent_serviceids) {
            $parents = [];
            foreach ($parent_serviceids as $pid) {
                $parents[] = ['serviceid' => (string) $pid];
            }
            $payload['parents'] = $parents;
        }
        if ($child_serviceids) {
            $children = [];
            foreach ($child_serviceids as $cid) {
                $children[] = ['serviceid' => (string) $cid];
            }
            $payload['children'] = $children;
        }

        $result = $this->call('service.create', $payload);

        return [
            'serviceid' => $result['serviceids'][0] ?? null,
            'name' => $name,
            'algorithm' => $algorithm,
            'problem_tags' => $problems,
            'service_tags' => $plain,
            'parent_serviceids' => array_values($parent_serviceids),
            'child_serviceids' => array_values($child_serviceids)
        ];
    }

    /**
     * Create an SLA. service_tags select which services (by their service tags,
     * OR-combined) this SLA measures.
     */
    public function createSla(string $name, float $slo, int $period, array $service_tags, string $timezone, ?int $effective_date, int $status, string $description): array {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('SLA name is required.');
        }
        // The API stores at most 255 chars; truncating silently would store a
        // mangled name while this method reports the original back as created.
        if (Util::truncate($name, 255) !== $name) {
            throw new RuntimeException('SLA name must be at most 255 characters.');
        }
        if ($slo < 0 || $slo > 100) {
            throw new RuntimeException('slo must be between 0 and 100.');
        }
        if (!in_array($period, [0, 1, 2, 3, 4], true)) {
            throw new RuntimeException('period must be 0=daily, 1=weekly, 2=monthly, 3=quarterly or 4=annually.');
        }

        $tags = $this->normalizeMatchTags($service_tags);
        if (!$tags) {
            throw new RuntimeException('At least one service_tag is required — the SLA must select services by tag.');
        }

        $timezone = trim($timezone) !== '' ? trim($timezone) : (date_default_timezone_get() ?: 'UTC');

        $payload = [
            'name' => $name,
            'slo' => $slo,
            'period' => $period,
            'timezone' => $timezone,
            'status' => ($status === 0) ? 0 : 1,
            'service_tags' => $tags
        ];
        if ($effective_date !== null && $effective_date > 0) {
            $payload['effective_date'] = $effective_date;
        }
        if ($description !== '') {
            $payload['description'] = Util::truncate($description, 65535);
        }

        $result = $this->call('sla.create', $payload);

        return [
            'slaid' => $result['slaids'][0] ?? null,
            'name' => $name,
            'slo' => $slo,
            'period' => $period,
            'timezone' => $timezone,
            'status' => ($status === 0) ? 0 : 1,
            'service_tags' => $tags,
            'effective_date' => $effective_date
        ];
    }

    /**
     * Detailed IT-service listing used by the SLA scoping tools.
     *
     * $match_tags — [{tag, operator, value}] SLA-style matchers, OR-combined
     * exactly like sla.service_tags: a service matches when ANY matcher
     * matches one of its service tags. $keyword — case-insensitive substring
     * filter on the service name. Both filters optional; when both are given
     * a service must satisfy both. Throws on API failure so SLA guards can
     * fail closed instead of guessing.
     */
    public function getServicesDetailed(array $match_tags = [], string $keyword = '', int $limit = 5000): array {
        $matchers = $this->normalizeMatchTags($match_tags);
        $keyword = trim($keyword);

        $cap = min(max($limit, 1), 5000);
        $services = $this->call('service.get', [
            'output' => ['serviceid', 'name', 'algorithm', 'sortorder'],
            'selectTags' => ['tag', 'value'],
            'selectProblemTags' => ['tag', 'operator', 'value'],
            'selectParents' => ['serviceid', 'name'],
            'selectChildren' => ['serviceid', 'name'],
            'sortfield' => 'name',
            'limit' => $cap
        ]);

        // Tag-scope resolution must fail closed when the window is (possibly)
        // incomplete: a matching service beyond the cap would silently break
        // the uniqueness guards. A plain keyword search stays usable (it may
        // just be incomplete past the cap).
        if ($matchers && count($services) >= $cap) {
            throw new RuntimeException('The installation has '.$cap.'+ IT services — tag scope cannot be resolved reliably client-side. Verify the SLA scope manually in the Zabbix UI.');
        }
        $out = [];

        foreach ($services as $s) {
            if ($keyword !== '' && stripos((string) ($s['name'] ?? ''), $keyword) === false) {
                continue;
            }
            if ($matchers && !$this->serviceMatchesSlaTags((array) ($s['tags'] ?? []), $matchers)) {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Emulate Zabbix SLA service-tag matching (OR logic): true when ANY
     * matcher matches ANY of the service's tags. Mirrors the SQL Zabbix 7
     * uses for sla.service_tags: tag names compare exactly; operator 0
     * (equals) compares values exactly (an empty matcher value matches only
     * an empty tag value); operator 2 (contains) is a case-insensitive
     * substring match (an empty matcher value matches any value, like
     * LIKE '%%').
     */
    private function serviceMatchesSlaTags(array $service_tags, array $matchers): bool {
        foreach ($matchers as $m) {
            foreach ($service_tags as $t) {
                if ((string) ($t['tag'] ?? '') !== $m['tag']) {
                    continue;
                }
                $value = (string) ($t['value'] ?? '');
                if ((int) $m['operator'] === 2) {
                    if ($m['value'] === '' || mb_stripos($value, $m['value']) !== false) {
                        return true;
                    }
                }
                elseif ($value === $m['value']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Exact-name service lookup. Zabbix does NOT enforce unique service
     * names, so this returns ALL services carrying the name (capped) so
     * callers can detect ambiguity. Each entry has serviceid, name and tags.
     */
    public function getServicesByExactName(string $name, int $limit = 5): array {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        return $this->call('service.get', [
            'output' => ['serviceid', 'name'],
            'selectTags' => ['tag', 'value'],
            'filter' => ['name' => $name],
            'limit' => min(max($limit, 1), 50)
        ]);
    }

    /**
     * Resolve service IDs to names; used to validate parent/child links before
     * service.create. Returns [serviceid => name] for the IDs that exist.
     */
    public function getServiceNamesByIds(array $serviceids): array {
        $ids = [];
        foreach ($serviceids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && preg_match('/^\d+$/', $id)) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return [];
        }

        $services = $this->call('service.get', [
            'output' => ['serviceid', 'name'],
            'serviceids' => $ids
        ]);

        $map = [];
        foreach ($services as $s) {
            $map[(string) $s['serviceid']] = (string) ($s['name'] ?? '');
        }

        return $map;
    }

    /**
     * Add one tag to a template, preserving existing tags. template.update
     * REPLACES the whole tags array, so we read-merge-write.
     */
    public function addTemplateTag(string $template_name, string $tag, string $value): array {
        $tag = trim($tag);
        if ($tag === '') {
            throw new RuntimeException('Tag name is required.');
        }
        $tid = $this->getTemplateIdByName($template_name);
        if ($tid === null) {
            throw new RuntimeException('Template "'.$template_name.'" not found.');
        }

        $current = $this->call('template.get', [
            'templateids' => [$tid],
            'output' => ['templateid'],
            'selectTags' => ['tag', 'value']
        ]);
        $existing = $current[0]['tags'] ?? [];

        $key = static fn(array $t): string => ($t['tag'] ?? '').'='.($t['value'] ?? '');
        $final = [];
        $seen = [];
        foreach ($existing as $t) {
            $norm = ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')];
            $final[] = $norm;
            $seen[$key($norm)] = true;
        }
        $new = ['tag' => $tag, 'value' => $value];
        $added = !isset($seen[$key($new)]);
        if ($added) {
            $final[] = $new;
        }

        $this->call('template.update', ['templateid' => $tid, 'tags' => $final]);

        return [
            'templateid' => $tid,
            'template' => $template_name,
            'tag' => $tag,
            'value' => $value,
            'added' => $added,
            'total_tags' => count($final)
        ];
    }

    /**
     * Add one tag to a trigger, preserving existing tags (read-merge-write).
     */
    public function addTriggerTag(string $trigger_id, string $tag, string $value): array {
        $tag = trim($tag);
        if ($tag === '') {
            throw new RuntimeException('Tag name is required.');
        }
        $trigger_id = trim($trigger_id);
        if ($trigger_id === '' || !ctype_digit($trigger_id)) {
            throw new RuntimeException('A numeric trigger_id is required.');
        }

        $current = $this->call('trigger.get', [
            'triggerids' => [$trigger_id],
            'output' => ['triggerid', 'description'],
            'selectTags' => ['tag', 'value']
        ]);
        if (!$current) {
            throw new RuntimeException('Trigger '.$trigger_id.' not found.');
        }
        $existing = $current[0]['tags'] ?? [];
        $desc = (string) ($current[0]['description'] ?? '');

        $key = static fn(array $t): string => ($t['tag'] ?? '').'='.($t['value'] ?? '');
        $final = [];
        $seen = [];
        foreach ($existing as $t) {
            $norm = ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')];
            $final[] = $norm;
            $seen[$key($norm)] = true;
        }
        $new = ['tag' => $tag, 'value' => $value];
        $added = !isset($seen[$key($new)]);
        if ($added) {
            $final[] = $new;
        }

        $this->call('trigger.update', ['triggerid' => $trigger_id, 'tags' => $final]);

        return [
            'triggerid' => $trigger_id,
            'trigger' => $desc,
            'tag' => $tag,
            'value' => $value,
            'added' => $added,
            'total_tags' => count($final)
        ];
    }

    /**
     * Gather the tags relevant to an SLA-scope decision for a set of hosts:
     * host-level tags, linked-template tags, and trigger tags (optionally
     * filtered by a name keyword). Lets the AI judge whether a unique tag or
     * tag combination exists to scope an SLA precisely.
     */
    public function analyzeSlaScope(array $hostnames, string $group_name, string $keyword, int $max_hosts = 25): array {
        $hostids = [];

        $group_name = trim($group_name);
        if ($group_name !== '') {
            $g = $this->getHostGroupByName($group_name);
            if ($g !== null) {
                $hosts = $this->call('host.get', [
                    'groupids' => [$g['groupid']],
                    'output' => ['hostid'],
                    'limit' => $max_hosts
                ]);
                foreach ($hosts as $h) {
                    $hostids[(string) $h['hostid']] = true;
                }
            }
        }
        foreach ($hostnames as $hn) {
            $hn = trim((string) $hn);
            if ($hn === '') {
                continue;
            }
            $hid = $this->getHostIdByName($hn);
            if ($hid !== null) {
                $hostids[(string) $hid] = true;
            }
        }
        if (!$hostids) {
            return ['hosts' => [], 'triggers' => [], 'keyword' => $keyword];
        }
        $ids = array_slice(array_keys($hostids), 0, $max_hosts);

        $detail = $this->call('host.get', [
            'hostids' => $ids,
            'output' => ['hostid', 'host', 'name'],
            'selectTags' => ['tag', 'value'],
            'selectParentTemplates' => ['templateid', 'name']
        ]);

        $template_ids = [];
        foreach ($detail as $h) {
            foreach (($h['parentTemplates'] ?? []) as $t) {
                $template_ids[(string) $t['templateid']] = true;
            }
        }
        $template_tags = [];
        if ($template_ids) {
            $tpls = $this->call('template.get', [
                'templateids' => array_keys($template_ids),
                'output' => ['templateid', 'name'],
                'selectTags' => ['tag', 'value']
            ]);
            foreach ($tpls as $t) {
                $template_tags[(string) $t['templateid']] = $t['tags'] ?? [];
            }
        }

        // Fetch one row past the cap so truncation is DETECTED, not silent —
        // an incomplete tag tally otherwise misreports uniqueness. sortfield
        // keeps the window deterministic across calls.
        $trig_cap = 200;
        $trig_params = [
            'hostids' => $ids,
            'output' => ['triggerid', 'description'],
            'selectTags' => ['tag', 'value'],
            'selectHosts' => ['host'],
            'expandDescription' => true,
            'sortfield' => 'triggerid',
            'limit' => $trig_cap + 1
        ];
        if (trim($keyword) !== '') {
            $trig_params['search'] = ['description' => trim($keyword)];
        }
        $triggers = $this->call('trigger.get', $trig_params);
        $triggers_truncated = count($triggers) > $trig_cap;
        if ($triggers_truncated) {
            $triggers = array_slice($triggers, 0, $trig_cap);
        }

        // Problems inherit ITEM tags too (host + template + trigger + item),
        // so an instance-specific item tag may be the only unique
        // discriminator available. Only tagged items are kept.
        $item_cap = 500;
        $item_params = [
            'hostids' => $ids,
            'output' => ['itemid', 'name', 'hostid'],
            'selectTags' => ['tag', 'value'],
            'sortfield' => 'itemid',
            'limit' => $item_cap + 1
        ];
        if (trim($keyword) !== '') {
            $item_params['search'] = ['name' => trim($keyword)];
        }
        $items = $this->call('item.get', $item_params);
        $items_truncated = count($items) > $item_cap;
        if ($items_truncated) {
            $items = array_slice($items, 0, $item_cap);
        }

        $hosts_out = [];
        foreach ($detail as $h) {
            $tpls = [];
            foreach (($h['parentTemplates'] ?? []) as $t) {
                $tid = (string) $t['templateid'];
                $tpls[] = ['name' => (string) $t['name'], 'tags' => $template_tags[$tid] ?? []];
            }
            $hosts_out[] = [
                'host' => (string) $h['host'],
                'name' => (string) ($h['name'] ?? $h['host']),
                'host_tags' => $h['tags'] ?? [],
                'templates' => $tpls
            ];
        }

        $trig_out = [];
        foreach ($triggers as $t) {
            $hn = !empty($t['hosts']) ? (string) ($t['hosts'][0]['host'] ?? '') : '';
            $trig_out[] = [
                'triggerid' => (string) $t['triggerid'],
                'host' => $hn,
                'description' => (string) $t['description'],
                'tags' => $t['tags'] ?? []
            ];
        }

        $host_by_id = [];
        foreach ($detail as $h) {
            $host_by_id[(string) $h['hostid']] = (string) $h['host'];
        }
        $items_out = [];
        foreach ($items as $it) {
            if (empty($it['tags'])) {
                continue;
            }
            $items_out[] = [
                'itemid' => (string) $it['itemid'],
                'host' => $host_by_id[(string) ($it['hostid'] ?? '')] ?? '',
                'name' => (string) ($it['name'] ?? ''),
                'tags' => $it['tags']
            ];
        }

        return [
            'hosts' => $hosts_out,
            'triggers' => $trig_out,
            'items' => $items_out,
            'triggers_truncated' => $triggers_truncated,
            'items_truncated' => $items_truncated,
            'keyword' => $keyword
        ];
    }

    // ── Phase 3 controlled host administration (writes) ───────────────────

    /**
     * Enable (status 0) or disable (status 1) monitoring for a host.
     */
    public function setHostStatus(string $hostname, int $status): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        return $this->call('host.update', [
            'hostid' => $hid,
            'status' => ($status === 1) ? 1 : 0
        ]);
    }

    /**
     * Add, remove, or replace a host's tags. For add/remove the current tag set
     * is read first and merged, so other tags are preserved.
     */
    public function updateHostTags(string $hostname, string $operation, array $tags): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        $input = [];
        foreach ($tags as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = trim((string) ($t['tag'] ?? ''));
            if ($name === '') {
                continue;
            }
            $input[] = ['tag' => $name, 'value' => (string) ($t['value'] ?? '')];
        }
        if (!$input) {
            throw new RuntimeException('No valid tags provided (each tag needs a "tag" name).');
        }

        $key = static function(array $t): string {
            return ($t['tag'] ?? '').'='.($t['value'] ?? '');
        };

        if ($operation === 'replace') {
            $final = $input;
        }
        else {
            $current = $this->call('host.get', [
                'hostids' => [$hid],
                'output' => ['hostid'],
                'selectTags' => ['tag', 'value']
            ]);
            $existing = $current[0]['tags'] ?? [];

            if ($operation === 'remove') {
                $remove = [];
                foreach ($input as $t) {
                    $remove[$key($t)] = true;
                }
                $final = [];
                foreach ($existing as $t) {
                    $norm = ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')];
                    if (!isset($remove[$key($norm)])) {
                        $final[] = $norm;
                    }
                }
            }
            else {
                // add (default): keep existing, append new ones not already present.
                $final = [];
                $seen = [];
                foreach ($existing as $t) {
                    $norm = ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')];
                    $final[] = $norm;
                    $seen[$key($norm)] = true;
                }
                foreach ($input as $t) {
                    if (!isset($seen[$key($t)])) {
                        $final[] = $t;
                        $seen[$key($t)] = true;
                    }
                }
            }
        }

        return $this->call('host.update', [
            'hostid' => $hid,
            'tags' => $final
        ]);
    }

    /**
     * Update host inventory fields. Sets inventory to manual mode so the values
     * persist. $fields is a map of inventory field name => value.
     */
    public function updateHostInventory(string $hostname, array $fields): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        $inventory = [];
        foreach ($fields as $k => $v) {
            $k = trim((string) $k);
            if ($k === '') {
                continue;
            }
            $inventory[$k] = (string) $v;
        }
        if (!$inventory) {
            throw new RuntimeException('No inventory fields provided.');
        }

        return $this->call('host.update', [
            'hostid' => $hid,
            'inventory_mode' => 0,
            'inventory' => $inventory
        ]);
    }

    /**
     * Create or update host-level user macros via usermacro.create/update, so
     * other macros (including secret ones, whose values the API never returns)
     * are never touched or blanked. Returns metadata only — never macro values.
     */
    public function updateHostMacros(string $hostname, array $macros): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        $existing = $this->call('usermacro.get', [
            'hostids' => [$hid],
            'output' => ['hostmacroid', 'macro', 'type']
        ]);
        $by_macro = [];
        foreach ($existing as $m) {
            $by_macro[(string) ($m['macro'] ?? '')] = $m;
        }

        $created = [];
        $updated = [];
        foreach ($macros as $m) {
            if (!is_array($m)) {
                continue;
            }
            $macro = trim((string) ($m['macro'] ?? ''));
            if ($macro === '') {
                continue;
            }
            $value = (string) ($m['value'] ?? '');
            $type = isset($m['type']) ? (int) $m['type'] : 0; // 0=text, 1=secret, 2=vault

            if (isset($by_macro[$macro])) {
                $this->call('usermacro.update', [
                    'hostmacroid' => $by_macro[$macro]['hostmacroid'],
                    'value' => $value,
                    'type' => $type
                ]);
                $updated[] = ['macro' => $macro, 'type' => $type];
            }
            else {
                $this->call('usermacro.create', [
                    'hostid' => $hid,
                    'macro' => $macro,
                    'value' => $value,
                    'type' => $type
                ]);
                $created[] = ['macro' => $macro, 'type' => $type];
            }
        }

        if (!$created && !$updated) {
            throw new RuntimeException('No valid macros provided (each needs a "macro" name like {$NAME}).');
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Update a host interface's IP / DNS / port / useip. Requires interfaceid
     * (find it with getHostInterfaces). Guards against empty updates.
     */
    public function updateHostInterface(string $interfaceid, array $changes): array {
        $allowed = ['ip', 'dns', 'port', 'useip'];
        $update = ['interfaceid' => $interfaceid];
        foreach ($changes as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $update[$k] = ($k === 'useip') ? (string) ((int) $v) : (string) $v;
            }
        }
        if (count($update) <= 1) {
            throw new RuntimeException('No valid interface fields to update. Allowed: '.implode(', ', $allowed));
        }

        return $this->call('hostinterface.update', $update);
    }

    // ── Phase 3b + 4: web, dashboards, templates, discovery (writes) ──────

    /**
     * Create a single-step web monitoring scenario (HTTP check) on a host.
     */
    public function createWebScenario(string $hostname, string $name, string $url, array $opts): array {
        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('Web scenario URL must start with http:// or https://.');
        }
        $delay = (string) ($opts['delay'] ?? '60s');
        if (!preg_match('/^\d+[smhd]?$/', $delay)) {
            throw new RuntimeException('Invalid delay format. Use e.g. 30s, 60s, 5m, 1h.');
        }
        $status_codes = (string) ($opts['status_codes'] ?? '200');
        if (!preg_match('/^\d{3}(,\d{3})*$/', $status_codes)) {
            throw new RuntimeException('Invalid HTTP status code list. Use e.g. 200 or 200,301.');
        }

        $params = [
            'name' => $name,
            'hostid' => $hid,
            'delay' => $delay,
            'steps' => [[
                'name' => (string) ($opts['step_name'] ?? 'Check'),
                'url' => $url,
                'no' => 1,
                'status_codes' => $status_codes
            ]]
        ];

        if (!empty($opts['tags']) && is_array($opts['tags'])) {
            $tags = [];
            foreach ($opts['tags'] as $t) {
                if (is_array($t) && trim((string) ($t['tag'] ?? '')) !== '') {
                    $tags[] = ['tag' => (string) $t['tag'], 'value' => (string) ($t['value'] ?? '')];
                }
            }
            if ($tags) {
                $params['tags'] = $tags;
            }
        }

        return $this->call('httptest.create', $params);
    }

    /**
     * Create a private dashboard with a single Problems widget. Filters can be
     * refined in the UI afterwards.
     */
    public function createProblemDashboard(string $name): array {
        return $this->call('dashboard.create', [
            'name' => $name,
            'private' => 1,
            'pages' => [[
                'widgets' => [[
                    'type' => 'problems',
                    'x' => 0,
                    'y' => 0,
                    'width' => 24,
                    'height' => 12,
                    'view_mode' => 0,
                    'fields' => []
                ]]
            ]]
        ]);
    }

    /**
     * Link a template to an EXPLICIT list of hosts (host.massadd — existing
     * templates are preserved). Enforces $max_hosts; throws if a host/template
     * cannot be resolved.
     */
    public function linkTemplateToHosts(string $template_name, array $hostnames, int $max_hosts): array {
        $tid = $this->getTemplateIdByName($template_name);
        if ($tid === null) {
            throw new RuntimeException('Template "'.$template_name.'" not found.');
        }

        $hosts = [];
        $resolved = [];
        foreach ($hostnames as $hn) {
            $hn = trim((string) $hn);
            if ($hn === '') {
                continue;
            }
            $hid = $this->getHostIdByName($hn);
            if ($hid === null) {
                throw new RuntimeException('Host "'.$hn.'" not found.');
            }
            $hosts[] = ['hostid' => $hid];
            $resolved[] = $hn;
        }
        if (!$hosts) {
            throw new RuntimeException('No valid hosts provided.');
        }
        if (count($hosts) > $max_hosts) {
            throw new RuntimeException('Refusing to modify '.count($hosts).' hosts (limit '.$max_hosts.'). Narrow the host list or raise bulk_max_hosts.');
        }

        $this->call('host.massadd', [
            'hosts' => $hosts,
            'templates' => [['templateid' => $tid]]
        ]);

        return ['template' => $template_name, 'hosts' => $resolved];
    }

    /**
     * Unlink a template from an EXPLICIT list of hosts (host.massremove). When
     * $clear is true, also removes the items/triggers the template created.
     */
    public function unlinkTemplateFromHosts(string $template_name, array $hostnames, bool $clear, int $max_hosts): array {
        $tid = $this->getTemplateIdByName($template_name);
        if ($tid === null) {
            throw new RuntimeException('Template "'.$template_name.'" not found.');
        }

        $hostids = [];
        $resolved = [];
        foreach ($hostnames as $hn) {
            $hn = trim((string) $hn);
            if ($hn === '') {
                continue;
            }
            $hid = $this->getHostIdByName($hn);
            if ($hid === null) {
                throw new RuntimeException('Host "'.$hn.'" not found.');
            }
            $hostids[] = $hid;
            $resolved[] = $hn;
        }
        if (!$hostids) {
            throw new RuntimeException('No valid hosts provided.');
        }
        if (count($hostids) > $max_hosts) {
            throw new RuntimeException('Refusing to modify '.count($hostids).' hosts (limit '.$max_hosts.'). Narrow the host list or raise bulk_max_hosts.');
        }

        $params = ['hostids' => $hostids];
        if ($clear) {
            $params['templateids_clear'] = [$tid];
        }
        else {
            $params['templateids'] = [$tid];
        }

        $this->call('host.massremove', $params);

        return ['template' => $template_name, 'hosts' => $resolved, 'cleared' => $clear];
    }

    /**
     * Enable (status 0) or disable (status 1) a low-level discovery rule.
     */
    public function setLldRuleStatus(string $lld_rule_id, int $status): array {
        return $this->call('discoveryrule.update', [
            'itemid' => $lld_rule_id,
            'status' => ($status === 1) ? 1 : 0
        ]);
    }

    /**
     * Create a new host. At least one group is required; missing groups are
     * created. Templates are linked if provided (must already exist). An agent
     * interface is added only when an IP or DNS is given (otherwise agentless,
     * which is fine for web/URL monitoring or template-only hosts).
     */
    public function createHost(string $hostname, array $group_names, array $opts): array {
        $groupids = [];
        foreach ($group_names as $gn) {
            $gn = trim((string) $gn);
            if ($gn === '') {
                continue;
            }
            $existing = $this->call('hostgroup.get', [
                'output' => ['groupid'],
                'filter' => ['name' => [$gn]]
            ]);
            $gid = $existing[0]['groupid'] ?? null;
            if ($gid === null) {
                if (empty($opts['create_missing_groups'])) {
                    throw new RuntimeException('Host group "'.$gn.'" does not exist. Create it first (create_host_group) or set create_missing_groups=true.');
                }
                $res = $this->call('hostgroup.create', ['name' => $gn]);
                $gid = $res['groupids'][0] ?? null;
            }
            if ($gid !== null) {
                $groupids[] = ['groupid' => (string) $gid];
            }
        }
        if (!$groupids) {
            throw new RuntimeException('At least one valid host group is required.');
        }

        $params = ['host' => $hostname, 'groups' => $groupids];

        if (trim((string) ($opts['visible_name'] ?? '')) !== '') {
            $params['name'] = (string) $opts['visible_name'];
        }
        if (trim((string) ($opts['description'] ?? '')) !== '') {
            $params['description'] = (string) $opts['description'];
        }

        if (!empty($opts['templates']) && is_array($opts['templates'])) {
            $tids = [];
            foreach ($opts['templates'] as $tn) {
                $tn = trim((string) $tn);
                if ($tn === '') {
                    continue;
                }
                $tid = $this->getTemplateIdByName($tn);
                if ($tid === null) {
                    throw new RuntimeException('Template "'.$tn.'" not found.');
                }
                $tids[] = ['templateid' => $tid];
            }
            if ($tids) {
                $params['templates'] = $tids;
            }
        }

        $ip = trim((string) ($opts['interface_ip'] ?? ''));
        $dns = trim((string) ($opts['interface_dns'] ?? ''));
        if ($ip !== '' || $dns !== '') {
            $params['interfaces'] = [[
                'type' => 1,
                'main' => 1,
                'useip' => ($ip !== '') ? 1 : 0,
                'ip' => $ip,
                'dns' => $dns,
                'port' => (string) ($opts['interface_port'] ?? '10050')
            ]];
        }

        return $this->call('host.create', $params);
    }

    /**
     * Create a trigger from a Zabbix trigger expression.
     */
    public function createTrigger(string $description, string $expression, array $opts): array {
        $params = [
            'description' => $description,
            'expression' => $expression
        ];
        if (isset($opts['priority'])) {
            $params['priority'] = (int) $opts['priority'];
        }
        if (!empty($opts['comments'])) {
            $params['comments'] = (string) $opts['comments'];
        }
        if (!empty($opts['recovery_expression'])) {
            $params['recovery_mode'] = 1;
            $params['recovery_expression'] = (string) $opts['recovery_expression'];
        }

        return $this->call('trigger.create', $params);
    }

    /**
     * Show which hosts are assigned to each proxy. Fetches all proxies (there
     * are few) and filters by name in PHP so it works across the Zabbix 6.x
     * ("host" field) / 7.0 ("name" field) proxy API difference.
     */
    public function getProxyAssignedHosts(string $proxy_name = ''): array {
        try {
            $proxies = $this->call('proxy.get', [
                'output' => 'extend',
                'selectHosts' => ['hostid', 'host', 'name', 'status']
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }

        $proxy_name = trim($proxy_name);
        if ($proxy_name === '') {
            return $proxies;
        }

        $needle = strtolower($proxy_name);
        $out = [];
        foreach ($proxies as $p) {
            $pname = strtolower((string) ($p['name'] ?? ($p['host'] ?? '')));
            if ($pname === $needle || strpos($pname, $needle) !== false) {
                $out[] = $p;
            }
        }
        return $out;
    }

    // ── Phase 4 bulk: preview queries + capped bulk executors ─────────────

    private function groupIdByName(string $name): ?string {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            $r = $this->call('hostgroup.get', [
                'output' => ['groupid'],
                'filter' => ['name' => [$name]]
            ]);
        }
        catch (\Throwable $e) {
            return null;
        }
        return $r[0]['groupid'] ?? null;
    }

    /**
     * Find ENABLED triggers whose (expanded) name matches a pattern, optionally
     * within a host group. Returns [{triggerid, host, description}].
     */
    public function findEnabledTriggersByName(string $name_pattern, string $group_name, int $limit): array {
        $params = [
            'output' => ['triggerid', 'description', 'status'],
            'selectHosts' => ['host'],
            'search' => ['description' => $name_pattern],
            'filter' => ['status' => 0],
            'expandDescription' => true,
            'sortfield' => 'description',
            'limit' => max(1, $limit)
        ];
        if ($group_name !== '') {
            $gid = $this->groupIdByName($group_name);
            if ($gid === null) {
                return [];
            }
            $params['groupids'] = [$gid];
        }

        try {
            $rows = $this->call('trigger.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $t) {
            $out[] = [
                'triggerid' => (string) ($t['triggerid'] ?? ''),
                'host' => (string) ($t['hosts'][0]['host'] ?? ''),
                'description' => (string) ($t['description'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * Find ENABLED but UNSUPPORTED items whose error message contains a pattern,
     * optionally within a host group. Returns [{itemid, host, name, error}].
     */
    public function findUnsupportedItemsByError(string $error_pattern, string $group_name, int $limit): array {
        $params = [
            'output' => ['itemid', 'name', 'key_', 'error', 'status', 'state'],
            'selectHosts' => ['host'],
            'filter' => ['state' => 1, 'status' => 0],
            'sortfield' => 'name',
            'limit' => 2000
        ];
        if ($group_name !== '') {
            $gid = $this->groupIdByName($group_name);
            if ($gid === null) {
                return [];
            }
            $params['groupids'] = [$gid];
        }

        try {
            $rows = $this->call('item.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $it) {
            $err = (string) ($it['error'] ?? '');
            if ($error_pattern !== '' && stripos($err, $error_pattern) === false) {
                continue;
            }
            $out[] = [
                'itemid' => (string) ($it['itemid'] ?? ''),
                'host' => (string) ($it['hosts'][0]['host'] ?? ''),
                'name' => (string) ($it['name'] ?? ''),
                'error' => $err
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Find DISABLED items matching a name search, optionally within a host
     * group. Returns [{itemid, host, name}].
     */
    public function findDisabledItems(string $item_search, string $group_name, int $limit): array {
        $params = [
            'output' => ['itemid', 'name', 'key_', 'status'],
            'selectHosts' => ['host'],
            'filter' => ['status' => 1],
            'sortfield' => 'name',
            'limit' => max(1, $limit)
        ];
        if ($item_search !== '') {
            $params['search'] = ['name' => $item_search];
        }
        if ($group_name !== '') {
            $gid = $this->groupIdByName($group_name);
            if ($gid === null) {
                return [];
            }
            $params['groupids'] = [$gid];
        }

        try {
            $rows = $this->call('item.get', $params);
        }
        catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $it) {
            $out[] = [
                'itemid' => (string) ($it['itemid'] ?? ''),
                'host' => (string) ($it['hosts'][0]['host'] ?? ''),
                'name' => (string) ($it['name'] ?? '')
            ];
        }
        return $out;
    }

    /**
     * List hosts in a group. Returns [{hostid, host}].
     */
    public function findHostsInGroup(string $group_name, int $limit): array {
        $gid = $this->groupIdByName($group_name);
        if ($gid === null) {
            return [];
        }
        try {
            $rows = $this->call('host.get', [
                'output' => ['hostid', 'host'],
                'groupids' => [$gid],
                'sortfield' => 'host',
                'limit' => max(1, $limit)
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $h) {
            $out[] = ['hostid' => (string) ($h['hostid'] ?? ''), 'host' => (string) ($h['host'] ?? '')];
        }
        return $out;
    }

    /**
     * Bulk-set trigger status (0=enabled, 1=disabled) for an explicit id list.
     */
    public function bulkSetTriggerStatus(array $trigger_ids, int $status): void {
        $updates = [];
        foreach ($trigger_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $updates[] = ['triggerid' => $id, 'status' => ($status === 1) ? 1 : 0];
            }
        }
        if (!$updates) {
            throw new RuntimeException('No trigger IDs to update.');
        }
        $this->call('trigger.update', $updates);
    }

    /**
     * Bulk-set item status (0=enabled, 1=disabled) for an explicit id list.
     */
    public function bulkSetItemStatus(array $item_ids, int $status): void {
        $updates = [];
        foreach ($item_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $updates[] = ['itemid' => $id, 'status' => ($status === 1) ? 1 : 0];
            }
        }
        if (!$updates) {
            throw new RuntimeException('No item IDs to update.');
        }
        $this->call('item.update', $updates);
    }

    /**
     * Add a tag to each host in an explicit id list (read-modify-write per host
     * so existing tags are preserved; skips hosts that already have it).
     */
    public function bulkAddTagToHosts(array $host_ids, string $tag, string $value): void {
        $tag = trim($tag);
        if ($tag === '') {
            throw new RuntimeException('Tag name is required.');
        }

        foreach ($host_ids as $hid) {
            $hid = trim((string) $hid);
            if ($hid === '') {
                continue;
            }

            $cur = $this->call('host.get', [
                'hostids' => [$hid],
                'output' => ['hostid'],
                'selectTags' => ['tag', 'value']
            ]);
            $existing = $cur[0]['tags'] ?? [];

            $final = [];
            $present = false;
            foreach ($existing as $t) {
                $tname = (string) ($t['tag'] ?? '');
                $tval = (string) ($t['value'] ?? '');
                $final[] = ['tag' => $tname, 'value' => $tval];
                if ($tname === $tag && $tval === $value) {
                    $present = true;
                }
            }
            if (!$present) {
                $final[] = ['tag' => $tag, 'value' => $value];
            }

            $this->call('host.update', ['hostid' => $hid, 'tags' => $final]);
        }
    }

    /**
     * Link a template to an explicit list of host IDs (host.massadd).
     */
    public function bulkLinkTemplateByHostIds(array $host_ids, string $templateid): void {
        $templateid = trim($templateid);
        $hosts = [];
        foreach ($host_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $hosts[] = ['hostid' => $id];
            }
        }
        if (!$hosts || $templateid === '') {
            throw new RuntimeException('No hosts or template to link.');
        }
        $this->call('host.massadd', [
            'hosts' => $hosts,
            'templates' => [['templateid' => $templateid]]
        ]);
    }

    /**
     * Unlink a template from an explicit list of host IDs (host.massremove).
     * When $clear is true, also removes the items/triggers it created.
     */
    public function bulkUnlinkTemplateByHostIds(array $host_ids, string $templateid, bool $clear): void {
        $templateid = trim($templateid);
        $ids = [];
        foreach ($host_ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if (!$ids || $templateid === '') {
            throw new RuntimeException('No hosts or template to unlink.');
        }
        $params = ['hostids' => $ids];
        if ($clear) {
            $params['templateids_clear'] = [$templateid];
        }
        else {
            $params['templateids'] = [$templateid];
        }
        $this->call('host.massremove', $params);
    }

    private static function canUseFrontendApi(): bool {
        if (!class_exists('\API') || !class_exists('\CWebUser')) {
            return false;
        }

        if (!isset(\CWebUser::$data) || !is_array(\CWebUser::$data)) {
            return false;
        }

        $userid = (string) (\CWebUser::$data['userid'] ?? '');
        if ($userid === '' || $userid === '0') {
            return false;
        }

        if (defined('USER_TYPE_ZABBIX_USER')) {
            $user_type = (int) (\CWebUser::$data['type'] ?? 0);
            if ($user_type < USER_TYPE_ZABBIX_USER) {
                return false;
            }
        }

        return true;
    }

    private static function frontendServiceName(string $api_object): string {
        static $map = [
            'action' => 'Action',
            'alert' => 'Alert',
            'auditlog' => 'AuditLog',
            'dashboard' => 'Dashboard',
            'discoveryrule' => 'DiscoveryRule',
            'event' => 'Event',
            'history' => 'History',
            'host' => 'Host',
            'hostgroup' => 'HostGroup',
            'hostinterface' => 'HostInterface',
            'httptest' => 'HttpTest',
            'item' => 'Item',
            'maintenance' => 'Maintenance',
            'mediatype' => 'MediaType',
            'problem' => 'Problem',
            'proxy' => 'Proxy',
            'service' => 'Service',
            'sla' => 'Sla',
            'template' => 'Template',
            'trend' => 'Trend',
            'trigger' => 'Trigger',
            'user' => 'User',
            'usermacro' => 'UserMacro'
        ];

        $api_object = strtolower(trim($api_object));

        return $map[$api_object] ?? '';
    }

    private function callWithFrontendApi(string $method, array $params): array {
        $parts = explode('.', $method, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid Zabbix API method: '.$method);
        }

        [$api_object, $api_action] = $parts;
        $service_name = self::frontendServiceName($api_object);

        if ($service_name === '') {
            throw new RuntimeException('No frontend API service mapping for '.$method.'.');
        }

        try {
            $service = call_user_func(['\API', $service_name]);
            $result = $service->{$api_action}($params);
        }
        catch (\Throwable $e) {
            throw new RuntimeException($method.' failed via frontend internal API: '.$e->getMessage(), 0, $e);
        }

        return is_array($result) ? $result : [$result];
    }

    private function callWithBearer(string $method, array $params): array {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];

        $response = HttpClient::expectSuccess('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json-rpc',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->token
            ],
            'json' => $payload,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        return $this->extractResult($response['json'], $method, 'Bearer');
    }

    private function callWithLegacyAuthField(string $method, array $params): array {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'auth' => $this->token,
            'id' => 1
        ];

        $response = HttpClient::expectSuccess('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json-rpc',
                'Accept' => 'application/json'
            ],
            'json' => $payload,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        return $this->extractResult($response['json'], $method, 'legacy auth field');
    }

    private function extractResult($json, string $method, string $auth_label): array {
        if (!is_array($json)) {
            throw new RuntimeException('Zabbix API returned a non-JSON response for '.$method.' using '.$auth_label.'.');
        }

        if (array_key_exists('error', $json)) {
            $message = $json['error']['message'] ?? 'Unknown Zabbix API error';
            $data = $json['error']['data'] ?? '';

            throw new RuntimeException($method.' failed via '.$auth_label.': '.$message.' '.Util::truncate((string) $data, 600));
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [$result];
    }
}
