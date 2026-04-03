<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class ZabbixApiClient {

    private string $url;
    private string $token;
    private bool $verify_peer;
    private int $timeout;
    private string $auth_mode;

    public function __construct(string $url, string $token, bool $verify_peer = true, int $timeout = 15, string $auth_mode = 'auto') {
        $this->url = trim($url);
        $this->token = trim($token);
        $this->verify_peer = $verify_peer;
        $this->timeout = $timeout;
        $this->auth_mode = $auth_mode !== '' ? $auth_mode : 'auto';
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

    public static function deriveApiUrl(): string {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '/zabbix.php';
        $base_path = rtrim(str_replace('\\', '/', dirname($script_name)), '/.');

        return $scheme.'://'.$host.$base_path.'/api_jsonrpc.php';
    }

    public function call(string $method, array $params = []): array {
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

    public function getProblems(?string $hostid = null, string $search = '', int $limit = 50): array {
        $params = [
            'output' => ['eventid', 'name', 'severity'],
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

        $problems = [];

        foreach ($result as $row) {
            $problems[] = [
                'eventid' => $row['eventid'],
                'name' => $row['name'] ?? '',
                'severity' => $row['severity'] ?? '0'
            ];
        }

        return $problems;
    }

    /**
     * Get problems with extended filters for AI actions.
     */
    public function getProblemsFiltered(array $params = []): array {
        $api_params = [
            'output' => ['eventid', 'name', 'severity', 'acknowledged', 'clock', 'r_eventid'],
            'selectHosts' => ['hostid', 'host', 'name'],
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

        return $this->call('problem.get', $api_params);
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
            'selectGroups' => ['groupid', 'name'],
            'selectInterfaces' => ['ip', 'dns', 'port', 'type', 'main'],
            'selectInventory' => 'extend',
            'selectTags' => ['tag', 'value'],
            'filter' => ['host' => [$hostname]]
        ]);

        return $result[0] ?? null;
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
     */
    public function createMaintenance(array $hostnames, float $duration_hours, ?string $start_time = null, string $name = '', string $description = ''): array {
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

        $active_since = $start_time !== null
            ? strtotime($start_time)
            : time();

        if ($active_since === false) {
            $active_since = time();
        }

        $active_till = $active_since + (int) ($duration_hours * 3600);

        if ($name === '') {
            $name = 'AI maintenance: '.implode(', ', $resolved_names);
        }

        $result = $this->call('maintenance.create', [
            'name' => Util::truncate($name, 128),
            'active_since' => $active_since,
            'active_till' => $active_till,
            'hosts' => $host_ids,
            'timeperiods' => [[
                'timeperiod_type' => 0,
                'period' => (int) ($duration_hours * 3600)
            ]],
            'description' => $description
        ]);

        return [
            'maintenanceid' => $result['maintenanceids'][0] ?? null,
            'name' => $name,
            'hosts' => $resolved_names,
            'start' => date('Y-m-d H:i:s', $active_since),
            'end' => date('Y-m-d H:i:s', $active_till),
            'duration_hours' => $duration_hours
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
