<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/**
 * Raised only when Zabbix explicitly rejects the supplied authentication.
 * Keeping this distinct from transport/API failures prevents unsafe retries.
 */
class ZabbixApiAuthenticationException extends RuntimeException {
}

class ZabbixApiClient {

    private string $url;
    private string $token;
    private bool $verify_peer;
    private int $timeout;
    private string $auth_mode;
    private string $transport;
    private ?string $frontend_url_cache = null;
    private array $confirmed_target_bindings = [];

    public function __construct(string $url, string $token, bool $verify_peer = true, int $timeout = 15, string $auth_mode = 'bearer', string $transport = 'http') {
        $this->url = trim($url);
        $this->token = trim($token);
        $this->verify_peer = $verify_peer;
        $this->timeout = $timeout;
        $this->auth_mode = $auth_mode !== '' ? $auth_mode : 'bearer';
        $this->transport = $transport === 'frontend' ? 'frontend' : 'http';

        if ($this->transport === 'http' && $this->token !== '' && !self::isValidServiceTokenUrl($this->url)) {
            throw new RuntimeException('The Zabbix API service-token URL must be an absolute HTTPS URL without embedded credentials.');
        }
    }

    public static function fromConfig(array $config): ?self {
        $config = Config::mergeWithDefaults($config);
        $token = Config::resolveSecret(
            $config['zabbix_api']['token'] ?? '',
            $config['zabbix_api']['token_env'] ?? '',
            Config::allowsPlaintextSecrets($config)
        );
        $url = trim((string) ($config['zabbix_api']['url'] ?? ''));

        if ($token === '') {
            return null;
        }

        // A service token must never be sent to a destination derived from the
        // request Host header. Requiring one fixed HTTPS URL also makes the
        // configured host the effective allowlist; HttpClient does not follow
        // redirects, so credentials cannot be redirected elsewhere.
        if ($url === '') {
            throw new RuntimeException('An explicit HTTPS Zabbix API URL is required when a service token is configured.');
        }

        if (!self::isValidServiceTokenUrl($url)) {
            throw new RuntimeException('The Zabbix API service-token URL must be an absolute HTTPS URL without embedded credentials.');
        }

        return new self(
            $url,
            $token,
            (bool) ($config['zabbix_api']['verify_peer'] ?? true),
            (int) ($config['zabbix_api']['timeout'] ?? 15),
            (string) ($config['zabbix_api']['auth_mode'] ?? 'bearer')
        );
    }

    /**
     * Prefer Zabbix's in-process frontend API facade for authenticated frontend
     * controllers. By default, interactive reads fail closed when the current
     * user's frontend API identity is unavailable. Split/token-only deployments
     * may explicitly opt into the configured service-token fallback.
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

        $config = Config::mergeWithDefaults($config);
        if (!Util::truthy($config['zabbix_api']['allow_service_token_read_fallback'] ?? false)) {
            return null;
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
        catch (ZabbixApiAuthenticationException $e) {
            // `auto` exists only for compatibility with old Zabbix versions.
            // Never issue a second request for a mutating method: the first
            // request may have committed even if its response was lost.
            if (!self::isReadOnlyMethod($method)) {
                throw $e;
            }

            return $this->callWithLegacyAuthField($method, $params);
        }
    }

    private static function isReadOnlyMethod(string $method): bool {
        return substr($method, -4) === '.get';
    }

    private static function isValidServiceTokenUrl(string $url): bool {
        $parts = parse_url($url);
        $valid = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
        if (!$valid) {
            return false;
        }
        try {
            Util::assertNoEmbeddedUrlCredentials($url);
        }
        catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Install the encrypted, confirmation-bound target registry for one write
     * execution. Name-based helpers below then query the frozen immutable ID
     * and verify its human-visible fingerprint instead of resolving a fresh
     * same-name object after confirmation.
     */
    public function bindConfirmedTargets(array $bindings): void {
        if (($bindings['version'] ?? '') !== 'zabbix-ai-targets-v1') {
            throw new RuntimeException('Confirmed write target registry is missing or unsupported.');
        }
        $this->confirmed_target_bindings = $bindings;
    }

    /** Non-secret display fields plus an opaque service-token identity digest. */
    public function confirmationIdentityFingerprint(): array {
        if ($this->transport === 'frontend') {
            $userid = '';
            if (class_exists('CWebUser') && isset(\CWebUser::$data) && is_array(\CWebUser::$data)) {
                $userid = (string) (\CWebUser::$data['userid'] ?? '');
            }
            return [
                'transport' => 'frontend_user',
                'userid' => $userid
            ];
        }

        return [
            'transport' => 'service_token',
            'api_url' => Util::sanitizeUrlForDisplay($this->url),
            'verify_peer' => $this->verify_peer,
            'auth_mode' => $this->auth_mode,
            'token_hmac' => Crypto::keyedFingerprint(
                $this->token,
                'Zabbix service-token identity'
            )
        ];
    }

    private function confirmedTarget(string $kind, string $name): ?array {
        $targets = $this->confirmed_target_bindings[$kind] ?? null;
        if (!is_array($targets) || !array_key_exists($name, $targets)) {
            return null;
        }

        return is_array($targets[$name]) ? $targets[$name] : null;
    }

    private function assertConfirmedMaintenance(array $maintenance): void {
        $maintenanceid = (string) ($maintenance['maintenanceid'] ?? '');
        $confirmed = $this->confirmedTarget('maintenances', $maintenanceid);
        if ($confirmed === null) {
            return;
        }
        $period_json = json_encode(
            $maintenance['timeperiods'] ?? [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($period_json === false
            || (string) ($confirmed['id'] ?? '') !== $maintenanceid
            || (string) ($confirmed['name'] ?? '') !== (string) ($maintenance['name'] ?? '')
            || (string) ($confirmed['active_since'] ?? '') !== (string) ($maintenance['active_since'] ?? '')
            || (string) ($confirmed['active_till'] ?? '') !== (string) ($maintenance['active_till'] ?? '')
            || (string) ($confirmed['timeperiods_sha256'] ?? '') !== hash('sha256', $period_json)) {
            throw new RuntimeException('Confirmed maintenance target changed after preview. Review a fresh preview.');
        }
    }

    /** Recheck an exact web-scenario name set against the staged host ID. */
    public function assertConfirmedWebScenario(string $hostname, string $scenario_name): void {
        $key = $hostname.' / '.$scenario_name;
        $scenarios = $this->confirmed_target_bindings['web_scenarios'] ?? null;
        if (!is_array($scenarios) || !array_key_exists($key, $scenarios)) {
            return;
        }
        $expected = is_array($scenarios[$key]) ? $scenarios[$key] : [];
        $hostid = $this->getHostIdByName($hostname);
        if ($hostid === null) {
            throw new RuntimeException('Confirmed web-scenario host disappeared. Review a fresh preview.');
        }
        $rows = $this->call('httptest.get', [
            'hostids' => [$hostid],
            'output' => ['httptestid', 'hostid', 'name'],
            'filter' => ['name' => [$scenario_name]]
        ]);
        $current = [];
        foreach ($rows as $row) {
            $current[] = [
                'hostid' => (string) ($row['hostid'] ?? $hostid),
                'id' => (string) ($row['httptestid'] ?? ''),
                'name' => (string) ($row['name'] ?? '')
            ];
        }
        usort($current, static function(array $a, array $b): int {
            return strnatcmp($a['id'], $b['id']);
        });
        if ($current !== $expected) {
            throw new RuntimeException('Confirmed web-scenario target changed after preview. Review a fresh preview.');
        }
    }

    public function getHostIdByName(string $hostname): ?string {
        $confirmed = $this->confirmedTarget('hosts', $hostname);
        if ($confirmed !== null) {
            if (($confirmed['state'] ?? '') === 'absent') {
                $current = $this->call('host.get', [
                    'output' => ['hostid'],
                    'filter' => ['host' => [$hostname]]
                ]);
                if ($current) {
                    throw new RuntimeException('Host "'.$hostname.'" appeared after confirmation. Review a fresh preview.');
                }
                return null;
            }

            $hostid = trim((string) ($confirmed['id'] ?? ''));
            $current = $hostid !== '' ? $this->call('host.get', [
                'hostids' => [$hostid],
                'output' => ['hostid', 'host', 'name']
            ]) : [];
            if (count($current) !== 1
                || (string) ($current[0]['host'] ?? '') !== (string) ($confirmed['technical_name'] ?? '')
                || (string) ($current[0]['name'] ?? '') !== (string) ($confirmed['visible_name'] ?? '')) {
                throw new RuntimeException('Confirmed host target "'.$hostname.'" changed or disappeared. Review a fresh preview.');
            }

            return $hostid;
        }

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

        $status = strtolower(trim((string) ($filters['status'] ?? 'enabled')));
        if ($status === '') {
            $status = 'enabled';
        }
        if ($status === 'enabled') {
            $params['filter']['status'] = 0;
        }
        elseif ($status === 'disabled') {
            $params['filter']['status'] = 1;
        }
        else {
            throw new RuntimeException('Host status filter must be "enabled" or "disabled".');
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
            if ($host_id === null) {
                return [];
            }
            $api_params['hostids'] = [$host_id];
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

            if (!$groups) {
                return [];
            }
            $params['groupids'] = array_column($groups, 'groupid');
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
        $hostid = $this->getHostIdByName($hostname);
        if ($hostid === null) {
            return null;
        }
        $result = $this->call('host.get', [
            'output' => ['hostid', 'host', 'name', 'status', 'description', 'maintenance_status'],
            'selectHostGroups' => ['groupid', 'name'],
            'selectInterfaces' => ['ip', 'dns', 'port', 'type', 'main'],
            'selectInventory' => 'extend',
            'selectTags' => ['tag', 'value'],
            'hostids' => [$hostid]
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
                'start_date' => $window['active_since'],
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
                'start_date' => $window['active_since'],
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
                'start_date' => $window['active_since'],
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
        $raw_additional_seconds = $additional_hours * 3600;
        $additional_seconds = (int) round($raw_additional_seconds);
        if (abs($raw_additional_seconds - $additional_seconds) > 0.01
            || $additional_seconds < 60 || $additional_seconds % 60 !== 0) {
            throw new RuntimeException('additional_hours must represent at least one exact whole minute.');
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
        $this->assertConfirmedMaintenance($m);
        $old_till = (int) ($m['active_till'] ?? 0);
        $now = (int) floor(time() / 60) * 60;
        $base = max($old_till, $now);
        $new_till = $base + $additional_seconds;

        // maintenance.update replaces the whole timeperiods set, so periods must
        // be sent back complete.  A maintenance with multiple/mixed one-time
        // periods has no unambiguous period to extend: stretching every one to
        // the envelope end would silently turn separated windows into continuous
        // maintenance.  Support only this module's simple one-time shape, or a
        // recurring-only schedule whose envelope alone is extended.
        $source_periods = is_array($m['timeperiods'] ?? null) ? $m['timeperiods'] : [];
        if (!$source_periods) {
            throw new RuntimeException('This maintenance has no schedule periods and cannot be extended safely in AI chat.');
        }
        $one_time_periods = array_values(array_filter($source_periods, static function($tp): bool {
            return is_array($tp) && (int) ($tp['timeperiod_type'] ?? 0) === 0;
        }));
        if ($one_time_periods && (count($one_time_periods) !== 1 || count($source_periods) !== 1)) {
            throw new RuntimeException('Maintenance with mixed or multiple one-time periods must be extended directly in Zabbix.');
        }

        $timeperiods = [];
        foreach ($source_periods as $tp) {
            if (!is_array($tp)) {
                throw new RuntimeException('This maintenance has an invalid schedule period and cannot be extended safely.');
            }
            $type = (int) ($tp['timeperiod_type'] ?? 0);
            if ($type === 0) {
                $tp_start = (int) ($tp['start_date'] ?? ($m['active_since'] ?? $now));
                $old_period = (int) ($tp['period'] ?? 0);
                if ($tp_start <= 0 || $old_period <= 0 || $tp_start + $old_period !== $old_till) {
                    throw new RuntimeException('This one-time maintenance period does not end at active_till and cannot be extended safely in AI chat.');
                }
                $new_period = $new_till - $tp_start;
                if ($new_period > 86399940) {
                    throw new RuntimeException('Extending this maintenance would exceed Zabbix\'s maximum one-time period.');
                }
                $timeperiods[] = [
                    'timeperiod_type' => 0,
                    'start_date' => $tp_start,
                    'period' => $new_period
                ];
            }
            else {
                // The API rejects fields that do not apply to the period type
                // ("unexpected parameter"), so only round-trip the valid ones:
                // daily (2), weekly (3), monthly (4).
                $fields_by_type = [
                    2 => ['every', 'start_time', 'period'],
                    3 => ['every', 'dayofweek', 'start_time', 'period'],
                    4 => ['every', 'month', 'dayofweek', 'day', 'start_time', 'period']
                ];
                if (!isset($fields_by_type[$type])) {
                    throw new RuntimeException('This maintenance uses an unsupported recurring schedule type and cannot be extended safely.');
                }
                $clean = ['timeperiod_type' => $type];
                foreach ($fields_by_type[$type] as $field) {
                    if (isset($tp[$field])) {
                        $clean[$field] = (int) $tp[$field];
                    }
                }
                $timeperiods[] = $clean;
            }
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
            'output' => ['maintenanceid', 'name', 'active_since', 'active_till'],
            'selectTimeperiods' => 'extend'
        ]);

        if (!$existing) {
            throw new RuntimeException('Maintenance '.$maintenance_id.' not found.');
        }

        $m = $existing[0];
        $this->assertConfirmedMaintenance($m);

        if ($delete) {
            $this->call('maintenance.delete', [$maintenance_id]);
            return [
                'maintenanceid' => $maintenance_id,
                'name' => $m['name'] ?? '',
                'action' => 'deleted'
            ];
        }

        // Zabbix stores maintenance boundaries at minute precision.  Freeze
        // "now" to the same boundary so the confirmed/result time is exact.
        $now = (int) floor(time() / 60) * 60;
        $now_minute = (int) floor($now / 60) * 60;
        $active_since = (int) ($m['active_since'] ?? $now);

        // active_till must be strictly greater than active_since. If the
        // window starts in the future (or less than one minute ago), move the
        // tiny envelope wholly into the past so "end now" cannot activate it
        // later merely to satisfy that invariant.
        $update = [
            'maintenanceid' => $maintenance_id,
            'active_till' => $now_minute
        ];
        if ($active_since + 60 > $now_minute) {
            $update['active_since'] = $now_minute - 60;
        }
        $new_till = $now_minute;

        $this->call('maintenance.update', $update);

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
            throw new RuntimeException('start_time is invalid. Use an ISO 8601 timestamp or YYYY-MM-DD HH:MM.');
        }

        $raw_period = $duration_hours * 3600;
        $period = (int) round($raw_period);
        if (abs($raw_period - $period) > 0.01 || $period % 60 !== 0) {
            throw new RuntimeException('Maintenance duration must represent an exact whole number of minutes.');
        }
        if ($period < 300 || $period > 86399940) {
            throw new RuntimeException('Maintenance duration must be a whole-minute period between 300 and 86399940 seconds.');
        }
        $active_since = (int) floor($active_since / 60) * 60;
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
        $confirmed = $this->confirmedTarget('templates', $template_name);
        if ($confirmed !== null) {
            $templateid = trim((string) ($confirmed['id'] ?? ''));
            $current = $templateid !== '' ? $this->call('template.get', [
                'templateids' => [$templateid],
                'output' => ['templateid', 'host', 'name']
            ]) : [];
            if (count($current) !== 1
                || (string) ($current[0]['host'] ?? '') !== (string) ($confirmed['technical_name'] ?? '')
                || (string) ($current[0]['name'] ?? '') !== (string) ($confirmed['visible_name'] ?? '')) {
                throw new RuntimeException('Confirmed template target "'.$template_name.'" changed or disappeared. Review a fresh preview.');
            }

            return $templateid;
        }

        $matches = [];

        // Resolve exact technical and visible names, then require one unique
        // template across both namespaces. Partial search must never select a
        // write target or silently broaden a scoped read.
        foreach (['host', 'name'] as $field) {
            $result = $this->call('template.get', [
                'output' => ['templateid', 'host', 'name'],
                'filter' => [$field => [$template_name]]
            ]);
            foreach ($result as $template) {
                $template_id = trim((string) ($template['templateid'] ?? ''));
                if ($template_id !== '') {
                    $matches[$template_id] = $template;
                }
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $match = reset($matches);

        return is_array($match) ? (string) ($match['templateid'] ?? '') : null;
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
            if ($tid === null) {
                return null;
            }
            $params['templateids'] = [$tid];
        }
        elseif ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid === null) {
                return null;
            }
            $params['hostids'] = [$hid];
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
            if ($hid === null) {
                return [];
            }
            $params['hostids'] = [$hid];
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
        foreach ($usrgrps as $index => $grp) {
            if (!is_string($grp) || !preg_match('/^[1-9]\d*$/D', $grp)) {
                throw new RuntimeException('User group ID at index '.$index.' must be a positive decimal string.');
            }
            $groups[] = ['usrgrpid' => $grp];
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
            if ($tid === null) {
                return [];
            }
            $params['templateids'] = [$tid];
        }
        elseif ($hostname !== '') {
            $hid = $this->getHostIdByName($hostname);
            if ($hid === null) {
                return [];
            }
            $params['hostids'] = [$hid];
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
     * first (history=0), then falls back to unsigned/string/text/log types.
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

        // If numeric float returned nothing, try unsigned int, string, text, log.
        if (!$result && $history_type === 0) {
            foreach ([3, 1, 4, 2] as $alt) {
                $result = $this->getItemHistory($itemid, $limit, $alt, $period_hours);
                if ($result) {
                    break;
                }
            }
            return $result; // already oldest-first from the recursive call
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

            $history = $this->getItemHistory($itemid, $limit_per_item, (int) ($item['value_type'] ?? 0), $period_hours);
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
            'selectItems' => ['itemid', 'name', 'key_', 'description', 'value_type'],
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
                    'description' => $item['description'] ?? '',
                    'value_type' => (int) ($item['value_type'] ?? 0)
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
        $confirmed = $this->confirmedTarget('host_groups', $name);
        if ($confirmed !== null) {
            if (($confirmed['state'] ?? '') === 'absent') {
                $current = $this->call('hostgroup.get', [
                    'output' => ['groupid', 'name'],
                    'filter' => ['name' => [$name]]
                ]);
                if ($current) {
                    throw new RuntimeException('Host group "'.$name.'" appeared after confirmation. Review a fresh preview.');
                }
                return null;
            }

            $groupid = trim((string) ($confirmed['id'] ?? ''));
            $current = $groupid !== '' ? $this->call('hostgroup.get', [
                'groupids' => [$groupid],
                'output' => ['groupid', 'name']
            ]) : [];
            if (count($current) !== 1
                || (string) ($current[0]['name'] ?? '') !== (string) ($confirmed['name'] ?? '')) {
                throw new RuntimeException('Confirmed host-group target "'.$name.'" changed or disappeared. Review a fresh preview.');
            }

            return $current[0];
        }

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
     * Add hosts to an existing host group. Group creation is a separate,
     * independently confirmed action.
     *
     * @param string[] $hostnames  Technical hostnames to add.
     * @param string   $group_name Host group name.
     * @return array   Result with groupid, hosts added, and whether group was created.
     */
    public function addHostsToGroup(array $hostnames, string $group_name): array {
        $group_name = trim($group_name);
        if ($group_name === '') {
            throw new RuntimeException('Host group name is required.');
        }
        // Freeze and reject duplicate technical names before any target lookup.
        $canonical_hostnames = [];
        $seen_names = [];
        foreach ($hostnames as $index => $hostname) {
            if (!is_string($hostname)) {
                throw new RuntimeException('Hostname at index '.$index.' must be a string.');
            }
            $hostname = trim($hostname);
            if ($hostname === '') {
                throw new RuntimeException('Hostname at index '.$index.' must not be empty.');
            }
            if (isset($seen_names[$hostname])) {
                throw new RuntimeException('Hostname "'.$hostname.'" is duplicated.');
            }
            $seen_names[$hostname] = true;
            $canonical_hostnames[] = $hostname;
        }
        if (!$canonical_hostnames) {
            throw new RuntimeException('At least one hostname is required.');
        }

        $group = $this->getHostGroupByName($group_name);
        if ($group === null) {
            throw new RuntimeException(
                'Host group "'.$group_name.'" does not exist. Create it with create_host_group first.'
            );
        }

        // Resolve every existing host before a missing group is created. A
        // stale host binding must not leave an otherwise-unused group behind.
        $host_ids = [];
        $resolved = [];
        $not_found = [];

        $seen_ids = [];
        foreach ($canonical_hostnames as $hostname) {
            $hid = $this->getHostIdByName($hostname);
            if ($hid !== null) {
                if (isset($seen_ids[$hid])) {
                    throw new RuntimeException('Multiple host names resolved to the same host ID '.$hid.'. No group change was made.');
                }
                $seen_ids[$hid] = true;
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
        if ($not_found) {
            throw new RuntimeException('Host(s) not found: '.implode(', ', $not_found).'. No group change was made.');
        }

        $groupid = $group['groupid'];

        // Zabbix API: massadd to add hosts to the group without removing existing members.
        $this->call('hostgroup.massadd', [
            'groups' => [['groupid' => $groupid]],
            'hosts' => $host_ids
        ]);

        return [
            'groupid' => $groupid,
            'group_name' => $group_name,
            'group_created' => false,
            'hosts_added' => $resolved,
            'hosts_not_found' => $not_found
        ];
    }

    public function addProblemComment(string $eventid, string $message, int $action = 4, int $chunk_size = 1900): array {
        // Only close (1) / acknowledge (2) may accompany the mandatory
        // add-message bit (4); severity/suppress/rank bits need parameters
        // this method never sends and must go through their dedicated methods.
        $action = ($action & 7) | 4;
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
            'output' => ['actionid', 'name', 'status', 'esc_period', 'eventsource'],
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
                'evaltype' => (string) ($action['filter']['evaltype'] ?? '0')
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
        $condition_results = [];
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

                case 16: // suppressed (operator 10 = Yes, 11 = No)
                    $matched = $operator === 10 ? $suppressed : !$suppressed;
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

            $condition_results[] = ['type' => $type, 'matched' => $matched];

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

        // eval_type: 0 = AND/OR, 1 = AND, 2 = OR, 3 = custom expression
        if ($eval_type === 1) {
            // AND: all must match; any false condition decides, otherwise
            // unresolved conditions leave the outcome undetermined.
            $status = $matched_count === $total
                ? 'matched'
                : (($matched_count + $undetermined_count) >= $total ? 'undetermined' : 'did_not_match');
        }
        elseif ($eval_type === 2) {
            // OR: any matches
            $status = $matched_count > 0 ? 'matched' : ($undetermined_count > 0 ? 'undetermined' : 'did_not_match');
        }
        elseif ($eval_type === 3) {
            // Custom expression: conditions combine with and/or only (no
            // negation), so the outcome is monotone — all matched means true,
            // none matched means false, anything else needs the formula.
            if ($matched_count === $total) {
                $status = 'matched';
            }
            elseif ($matched_count === 0 && $undetermined_count === 0) {
                $status = 'did_not_match';
            }
            else {
                $status = 'undetermined';
                $reasons[] = 'custom expression "'.(string) ($filter['eval_formula'] ?? $filter['formula'] ?? '').'" not fully evaluated';
            }
        }
        else {
            // AND/OR: conditions of the same type are OR-ed, different types are AND-ed
            $status = 'matched';
            $groups = [];
            foreach ($condition_results as $cr) {
                $groups[$cr['type']][] = $cr['matched'];
            }
            foreach ($groups as $group) {
                if (in_array(true, $group, true)) {
                    continue; // at least one condition of this type matched
                }
                if (in_array(null, $group, true)) {
                    $status = 'undetermined';
                    continue;
                }
                $status = 'did_not_match';
                break;
            }
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
            foreach ([3, 1, 4, 2] as $alt) {
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
            'selectAcknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action'],
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
            'selectAcknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action'],
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

                    $history = $this->getItemHistoryRange($itemid, $since, $until, (int) ($item['value_type'] ?? 0), $history_per_item);
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
            'selectAcknowledges' => ['acknowledgeid', 'userid', 'message', 'clock', 'action', 'old_severity', 'new_severity', 'suppress_until'],
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
     * Service-tree impact for an event: the services whose problem tags match
     * the event's tags (Zabbix's own problem-to-service mapping rule).
     */
    public function getServiceImpact(string $eventid): array {
        $events = $this->call('event.get', [
            'eventids' => [$eventid],
            'output' => ['eventid', 'objectid'],
            'selectTags' => ['tag', 'value'],
            'limit' => 1
        ]);

        if (!$events) {
            return [];
        }

        $triggerid = (string) ($events[0]['objectid'] ?? '');
        $event_tags = $events[0]['tags'] ?? [];

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

        // A problem maps to a service when ALL of the service's problem tags
        // match one of the event's tags (operator 0 = equals, 2 = contains;
        // a contains-tag with empty value matches any value). Services without
        // problem tags are structural nodes and never map to problems directly.
        $tag_matches = static function (array $ptag) use ($event_tags): bool {
            $pname = (string) ($ptag['tag'] ?? '');
            $pval = (string) ($ptag['value'] ?? '');
            $op = (int) ($ptag['operator'] ?? 0);

            foreach ($event_tags as $et) {
                if ((string) ($et['tag'] ?? '') !== $pname) {
                    continue;
                }
                $eval = (string) ($et['value'] ?? '');
                if ($op === 2 ? ($pval === '' || strpos($eval, $pval) !== false) : ($eval === $pval)) {
                    return true;
                }
            }

            return false;
        };

        $impacted = array_values(array_filter($services, static function ($svc) use ($tag_matches) {
            $ptags = $svc['problem_tags'] ?? [];
            if (!$ptags) {
                return false;
            }
            foreach ($ptags as $pt) {
                if (!$tag_matches($pt)) {
                    return false;
                }
            }
            return true;
        }));

        return [
            'eventid' => $eventid,
            'triggerid' => $triggerid,
            'services' => $impacted
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

        // Treat the macro as a base URL, never as a credential/query carrier.
        // Runtime redaction is applied by ChatSend before this enters a prompt.
        if ($url !== '') {
            $parts = parse_url($url);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if (!is_array($parts)
                || !in_array($scheme, ['http', 'https'], true)
                || trim((string) ($parts['host'] ?? '')) === ''
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                $url = '';
            }
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
        if ($until_unix < 0) {
            throw new RuntimeException('suppress_until must be non-negative; 0 means indefinite suppression.');
        }
        return $this->call('event.acknowledge', [
            'eventids' => [$eventid],
            'action' => 32,
            'suppress_until' => $until_unix
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
        $name_length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name_length > 128) {
            throw new RuntimeException('Service name must be at most 128 characters.');
        }

        $problems = $this->normalizeMatchTags($problem_tags);
        if (!$problems && !$child_serviceids) {
            throw new RuntimeException('At least one problem_tag is required so the service can map problems (only a parent service with child_serviceids may omit them).');
        }
        if ($problems && $child_serviceids) {
            throw new RuntimeException('Zabbix does not allow a service to have both problem_tags and children — a LEAF maps problems, a PARENT aggregates children.');
        }

        $plain = $this->normalizePlainTags($service_tags);

        if (!in_array($algorithm, [1, 2], true)) {
            throw new RuntimeException('Service algorithm must be 1 or 2.');
        }
        if ($sortorder < 0 || $sortorder > 999) {
            throw new RuntimeException('sortorder must be between 0 and 999.');
        }

        $payload = [
            'name' => $name,
            'algorithm' => $algorithm,
            'sortorder' => $sortorder
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
        if (abs($slo - round($slo, 4)) > 0.000000001) {
            throw new RuntimeException('slo supports at most 4 fractional digits.');
        }
        if (!in_array($period, [0, 1, 2, 3, 4], true)) {
            throw new RuntimeException('period must be 0=daily, 1=weekly, 2=monthly, 3=quarterly or 4=annually.');
        }
        if (!in_array($status, [0, 1], true)) {
            throw new RuntimeException('SLA status must be 0 or 1.');
        }
        $description_length = function_exists('mb_strlen')
            ? mb_strlen($description, 'UTF-8')
            : strlen($description);
        if ($description_length > 65535) {
            throw new RuntimeException('SLA description must be at most 65535 characters.');
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
            'status' => $status,
            'service_tags' => $tags
        ];
        if ($effective_date !== null && $effective_date > 0) {
            $payload['effective_date'] = $effective_date;
        }
        if ($description !== '') {
            $payload['description'] = $description;
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

        $operation = strtolower(trim($operation));
        if (!in_array($operation, ['add', 'remove', 'replace'], true)) {
            throw new RuntimeException('Host tag operation must be add, remove, or replace.');
        }

        $input = [];
        foreach ($tags as $index => $t) {
            if (!is_array($t)) {
                throw new RuntimeException('Host tag at index '.$index.' must be an object.');
            }
            foreach (array_keys($t) as $field) {
                if (!in_array($field, ['tag', 'value'], true)) {
                    throw new RuntimeException('Host tag at index '.$index.' has unexpected field "'.$field.'".');
                }
            }
            if (!is_string($t['tag'] ?? null) || !is_string($t['value'] ?? '')) {
                throw new RuntimeException('Host tag names and values must be strings.');
            }
            $name = trim($t['tag']);
            if ($name === '') {
                throw new RuntimeException('Host tag at index '.$index.' needs a non-empty name.');
            }
            $input[] = ['tag' => $name, 'value' => $t['value'] ?? ''];
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
            if (!is_string($k) || trim($k) === '') {
                throw new RuntimeException('Every inventory field name must be a non-empty string.');
            }
            if (!is_string($v)) {
                throw new RuntimeException('Inventory field "'.trim($k).'" must have a string value.');
            }
            $inventory[trim($k)] = $v;
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
        // Validate the complete batch before the first API write. Homogeneous
        // creates or updates are submitted as one array-form API mutation;
        // mixed batches are rejected below and must be confirmed separately.
        if (!$macros) {
            throw new RuntimeException('At least one host macro is required.');
        }
        $seen = [];
        foreach ($macros as $index => $m) {
            if (!is_array($m)) {
                throw new RuntimeException('Host macro at index '.$index.' must be an object.');
            }
            foreach (array_keys($m) as $field) {
                if (!in_array($field, ['macro', 'value', 'type'], true)) {
                    throw new RuntimeException('Host macro at index '.$index.' has unexpected field "'.$field.'".');
                }
            }
            $macro = $m['macro'] ?? null;
            $value = $m['value'] ?? null;
            $type = $m['type'] ?? 0;
            if (!is_string($macro) || $macro !== trim($macro)
                || !Util::isValidZabbixUserMacro($macro)) {
                throw new RuntimeException('Host macro at index '.$index.' has an invalid name.');
            }
            if (!is_string($value)) {
                throw new RuntimeException('Host macro "'.$macro.'" must have a string value.');
            }
            $name_length = function_exists('mb_strlen') ? mb_strlen($macro, 'UTF-8') : strlen($macro);
            $value_length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($name_length > 255 || $value_length > 2048) {
                throw new RuntimeException('Host macro "'.$macro.'" exceeds the Zabbix name/value length limit.');
            }
            if (!is_int($type) || $type !== 0) {
                throw new RuntimeException('Host macro "'.$macro.'" must be plain text type 0.');
            }
            if (isset($seen[$macro])) {
                throw new RuntimeException('Host macro "'.$macro.'" is duplicated.');
            }
            $seen[$macro] = true;
        }

        $hid = $this->getHostIdByName($hostname);
        if ($hid === null) {
            throw new RuntimeException('Host "'.$hostname.'" not found.');
        }

        $existing = $this->call('usermacro.get', [
            'hostids' => [$hid],
            'output' => ['hostmacroid', 'macro', 'type', 'automatic']
        ]);
        $by_macro = [];
        foreach ($existing as $m) {
            $by_macro[(string) ($m['macro'] ?? '')] = $m;
        }

        $confirmed_macros = $this->confirmed_target_bindings['host_macros'] ?? null;
        if (is_array($confirmed_macros)) {
            foreach ($confirmed_macros as $macro_name => $confirmed_macro) {
                if (!is_array($confirmed_macro)) {
                    throw new RuntimeException('Confirmed macro target registry is invalid.');
                }
                $current_macro = $by_macro[(string) $macro_name] ?? null;
                if (($confirmed_macro['state'] ?? '') === 'absent') {
                    if ($current_macro !== null) {
                        throw new RuntimeException('Host macro "'.$macro_name.'" appeared after confirmation. Review a fresh preview.');
                    }
                    continue;
                }
                if (!is_array($current_macro)
                    || (string) ($current_macro['hostmacroid'] ?? '') !== (string) ($confirmed_macro['id'] ?? '')
                    || (int) ($current_macro['type'] ?? 0) !== (int) ($confirmed_macro['type'] ?? 0)
                    || (int) ($current_macro['automatic'] ?? 0) !== (int) ($confirmed_macro['automatic'] ?? 0)) {
                    throw new RuntimeException('Confirmed host macro "'.$macro_name.'" changed or disappeared. Review a fresh preview.');
                }
            }
        }

        // Inspect every existing target before issuing the first update.
        foreach ($macros as $m) {
            $macro = (string) $m['macro'];
            if (!isset($by_macro[$macro])) {
                continue;
            }
            if ((int) ($by_macro[$macro]['type'] ?? 0) !== 0) {
                throw new RuntimeException('Host macro "'.$macro.'" already exists as secret/vault type and cannot be overwritten or converted through AI chat.');
            }
            if ((int) ($by_macro[$macro]['automatic'] ?? 0) !== 0) {
                throw new RuntimeException('Host macro "'.$macro.'" is discovery-managed and cannot be changed through AI chat.');
            }
        }

        $create_payloads = [];
        $update_payloads = [];
        $created = [];
        $updated = [];
        foreach ($macros as $m) {
            $macro = $m['macro'];
            $value = $m['value'];
            $type = $m['type'] ?? 0;

            if (isset($by_macro[$macro])) {
                $update_payloads[] = [
                    'hostmacroid' => $by_macro[$macro]['hostmacroid'],
                    'value' => $value,
                    'type' => $type
                ];
                $updated[] = ['macro' => $macro, 'type' => $type];
            }
            else {
                $create_payloads[] = [
                    'hostid' => $hid,
                    'macro' => $macro,
                    'value' => $value,
                    'type' => $type
                ];
                $created[] = ['macro' => $macro, 'type' => $type];
            }
        }

        // Zabbix supports array payloads for both methods, so a homogeneous
        // batch is one server-side mutation. A mixed create+update would need
        // two independent commits and is deliberately split into two separate
        // confirmations to prevent partial success.
        if ($create_payloads && $update_payloads) {
            throw new RuntimeException(
                'A single macro action cannot mix new and existing macros. Confirm the creates and updates separately.'
            );
        }
        if ($update_payloads) {
            $this->call('usermacro.update', $update_payloads);
        }
        elseif ($create_payloads) {
            $this->call('usermacro.create', $create_payloads);
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
                $update[$k] = ($k === 'useip') ? (int) $v : (string) $v;
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

        $this->assertConfirmedWebScenario($hostname, $name);

        $params = [
            'name' => $name,
            'hostid' => $hid,
            'delay' => $delay,
            'steps' => [[
                'name' => (string) ($opts['step_name'] ?? 'Check'),
                'url' => $url,
                'no' => 1,
                // Never let an allowlisted URL redirect the Zabbix server or
                // proxy to loopback, metadata or another non-allowlisted host.
                'follow_redirects' => 0,
                'status_codes' => $status_codes
            ]]
        ];

        if (!empty($opts['tags']) && is_array($opts['tags'])) {
            $tags = [];
            foreach ($opts['tags'] as $index => $t) {
                if (!is_array($t)) {
                    throw new RuntimeException('Web-scenario tag at index '.$index.' must be an object.');
                }
                foreach (array_keys($t) as $field) {
                    if (!in_array($field, ['tag', 'value'], true)) {
                        throw new RuntimeException('Web-scenario tag at index '.$index.' has unexpected field "'.$field.'".');
                    }
                }
                if (!is_string($t['tag'] ?? null) || !is_string($t['value'] ?? '')) {
                    throw new RuntimeException('Web-scenario tag names and values must be strings.');
                }
                $tag_name = trim($t['tag']);
                if ($tag_name === '') {
                    throw new RuntimeException('Web-scenario tag at index '.$index.' needs a non-empty name.');
                }
                $tags[] = ['tag' => $tag_name, 'value' => $t['value'] ?? ''];
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
     * Create a new host in existing groups. Templates are linked if provided
     * (and must already exist). An agent
     * interface is added only when an IP or DNS is given (otherwise agentless,
     * which is fine for web/URL monitoring or template-only hosts).
     */
    public function createHost(string $hostname, array $group_names, array $opts): array {
        $length = static function(string $value): int {
            return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        };

        // Validate and freeze the complete one-call host payload before any
        // target lookup. Group creation is intentionally a separate confirmed
        // tool; this method therefore performs exactly one API mutation.
        $hostname = trim($hostname);
        if ($hostname === '' || $length($hostname) > 128
            || preg_match('/^[A-Za-z0-9._ -]+$/D', $hostname) !== 1) {
            throw new RuntimeException(
                'Host technical name must be 1-128 characters using letters, digits, spaces, dots, dashes, or underscores.'
            );
        }
        $allowed_options = [
            'visible_name', 'description', 'templates',
            'interface_ip', 'interface_dns', 'interface_port'
        ];
        foreach (array_keys($opts) as $option) {
            if (!in_array($option, $allowed_options, true)) {
                throw new RuntimeException('Unexpected create-host option "'.$option.'".');
            }
        }

        $canonical_groups = [];
        $seen_groups = [];
        foreach ($group_names as $index => $group_name) {
            if (!is_string($group_name)) {
                throw new RuntimeException('Host group at index '.$index.' must be a string.');
            }
            $group_name = trim($group_name);
            if ($group_name === '' || $length($group_name) > 255) {
                throw new RuntimeException('Host group names must be 1-255 characters.');
            }
            if (isset($seen_groups[$group_name])) {
                throw new RuntimeException('Host group "'.$group_name.'" is duplicated.');
            }
            $seen_groups[$group_name] = true;
            $canonical_groups[] = $group_name;
        }
        if (!$canonical_groups) {
            throw new RuntimeException('At least one valid host group is required.');
        }

        $raw_templates = $opts['templates'] ?? [];
        if (!is_array($raw_templates)) {
            throw new RuntimeException('Host templates must be an array.');
        }
        $canonical_templates = [];
        $seen_template_names = [];
        foreach ($raw_templates as $index => $template_name) {
            if (!is_string($template_name)) {
                throw new RuntimeException('Template at index '.$index.' must be a string.');
            }
            $template_name = trim($template_name);
            if ($template_name === '' || $length($template_name) > 128) {
                throw new RuntimeException('Template names must be 1-128 characters.');
            }
            if (isset($seen_template_names[$template_name])) {
                throw new RuntimeException('Template "'.$template_name.'" is duplicated.');
            }
            $seen_template_names[$template_name] = true;
            $canonical_templates[] = $template_name;
        }

        foreach (['visible_name', 'description', 'interface_ip', 'interface_dns', 'interface_port'] as $field) {
            if (array_key_exists($field, $opts) && !is_string($opts[$field])) {
                throw new RuntimeException('Host option "'.$field.'" must be a string.');
            }
        }
        $visible_name = trim((string) ($opts['visible_name'] ?? ''));
        if ($visible_name !== '' && $length($visible_name) > 128) {
            throw new RuntimeException('Visible host name must be at most 128 characters.');
        }
        $description = (string) ($opts['description'] ?? '');
        if ($length($description) > 65535) {
            throw new RuntimeException('Host description must be at most 65535 characters.');
        }

        $ip = trim((string) ($opts['interface_ip'] ?? ''));
        $dns = trim((string) ($opts['interface_dns'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Agent interface IP must be a valid IPv4 or IPv6 address.');
        }
        if ($dns !== '' && ($length($dns) > 255
            || filter_var($dns, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new RuntimeException('Agent interface DNS must be a valid hostname of at most 255 characters.');
        }
        $port = trim((string) ($opts['interface_port'] ?? '10050'));
        if ($ip !== '' || $dns !== '') {
            $numeric_port = ctype_digit($port) ? (int) $port : 0;
            if (($numeric_port < 1 || $numeric_port > 65535) && !Util::isValidZabbixUserMacro($port)) {
                throw new RuntimeException('Agent interface port must be 1-65535 or a valid user macro.');
            }
            if ($length($port) > 64) {
                throw new RuntimeException('Agent interface port must be at most 64 characters.');
            }
        }

        // The confirmed binding for a new host records an absence
        // precondition. Recheck it through the bound-ID helper immediately
        // before resolving dependencies and calling host.create.
        if ($this->getHostIdByName($hostname) !== null) {
            throw new RuntimeException('Host "'.$hostname.'" already exists. Review a fresh preview.');
        }

        // Resolve the already-existing immutable dependencies. Distinct names
        // which alias the same template ID are rejected before host.create.
        $resolved_template_ids = [];
        $seen_template_ids = [];
        foreach ($canonical_templates as $tn) {
                $tid = $this->getTemplateIdByName($tn);
                if ($tid === null) {
                    throw new RuntimeException('Template "'.$tn.'" not found.');
                }
                if (isset($seen_template_ids[$tid])) {
                    throw new RuntimeException('Multiple template names resolve to template ID '.$tid.'.');
                }
                $seen_template_ids[$tid] = true;
                $resolved_template_ids[] = ['templateid' => $tid];
        }

        $groupids = [];
        $seen_group_ids = [];
        foreach ($canonical_groups as $gn) {
            $existing = $this->getHostGroupByName($gn);
            $gid = $existing['groupid'] ?? null;
            if ($gid === null) {
                throw new RuntimeException('Host group "'.$gn.'" does not exist. Create it with create_host_group first.');
            }
            $gid = (string) $gid;
            if (isset($seen_group_ids[$gid])) {
                throw new RuntimeException('Multiple host-group names resolve to group ID '.$gid.'.');
            }
            $seen_group_ids[$gid] = true;
            $groupids[] = ['groupid' => $gid];
        }

        $params = ['host' => $hostname, 'groups' => $groupids];

        if ($visible_name !== '') {
            $params['name'] = $visible_name;
        }
        if ($description !== '') {
            $params['description'] = $description;
        }

        if ($resolved_template_ids) {
            $params['templates'] = $resolved_template_ids;
        }

        if ($ip !== '' || $dns !== '') {
            $params['interfaces'] = [[
                'type' => 1,
                'main' => 1,
                'useip' => ($ip !== '') ? 1 : 0,
                'ip' => $ip,
                'dns' => $dns,
                'port' => $port
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
     * Add a tag to an explicit host-ID set in one array-form host.update call,
     * preserving each host's existing tags and skipping hosts that have it.
     */
    public function bulkAddTagToHosts(array $host_ids, string $tag, string $value): void {
        $tag = trim($tag);
        if ($tag === '') {
            throw new RuntimeException('Tag name is required.');
        }
        $length = static function(string $text): int {
            return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        };
        if ($length($tag) > 255 || $length($value) > 255) {
            throw new RuntimeException('Host tag names and values must be at most 255 characters.');
        }

        $ids = [];
        $seen_ids = [];
        foreach ($host_ids as $index => $host_id) {
            if (!is_string($host_id) || preg_match('/^[1-9][0-9]*$/D', $host_id) !== 1) {
                throw new RuntimeException('Host ID at index '.$index.' must be a positive decimal string.');
            }
            if (isset($seen_ids[$host_id])) {
                throw new RuntimeException('Host ID '.$host_id.' is duplicated.');
            }
            $seen_ids[$host_id] = true;
            $ids[] = $host_id;
        }
        if (!$ids) {
            throw new RuntimeException('At least one host ID is required.');
        }

        // Resolve the complete frozen set first, then submit one array-form
        // host.update mutation. A missing/inaccessible host therefore fails
        // before any tag is changed, and a server rejection cannot leave an
        // earlier host committed by this action.
        $rows = $this->call('host.get', [
            'hostids' => $ids,
            'output' => ['hostid'],
            'selectTags' => ['tag', 'value'],
            'preservekeys' => true
        ]);
        $by_id = [];
        foreach ($rows as $row) {
            $row_id = (string) ($row['hostid'] ?? '');
            if ($row_id !== '') {
                $by_id[$row_id] = $row;
            }
        }
        if (count($by_id) !== count($ids)) {
            throw new RuntimeException('One or more confirmed host targets disappeared or became inaccessible. No tag was changed.');
        }

        $updates = [];
        foreach ($ids as $host_id) {
            if (!isset($by_id[$host_id])) {
                throw new RuntimeException('Confirmed host ID '.$host_id.' disappeared or became inaccessible. No tag was changed.');
            }
            $existing = is_array($by_id[$host_id]['tags'] ?? null) ? $by_id[$host_id]['tags'] : [];
            $final = [];
            $present = false;
            foreach ($existing as $existing_tag) {
                if (!is_array($existing_tag)) {
                    throw new RuntimeException('Host ID '.$host_id.' returned an invalid tag object. No tag was changed.');
                }
                $name = (string) ($existing_tag['tag'] ?? '');
                $existing_value = (string) ($existing_tag['value'] ?? '');
                $final[] = ['tag' => $name, 'value' => $existing_value];
                if ($name === $tag && $existing_value === $value) {
                    $present = true;
                }
            }
            if (!$present) {
                $final[] = ['tag' => $tag, 'value' => $value];
                $updates[] = ['hostid' => $host_id, 'tags' => $final];
            }
        }

        if ($updates) {
            $this->call('host.update', $updates);
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
            'itemprototype' => 'ItemPrototype',
            'maintenance' => 'Maintenance',
            'mediatype' => 'MediaType',
            'problem' => 'Problem',
            'proxy' => 'Proxy',
            'service' => 'Service',
            'sla' => 'Sla',
            'template' => 'Template',
            'trend' => 'Trend',
            'trigger' => 'Trigger',
            'triggerprototype' => 'TriggerPrototype',
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
            $detail = trim((string) $message.' '.(string) $data);

            if (self::isAuthenticationError($detail)) {
                throw new ZabbixApiAuthenticationException(
                    $method.' failed via '.$auth_label.': '.Util::truncate($detail, 600)
                );
            }

            throw new RuntimeException($method.' failed via '.$auth_label.': '.Util::truncate($detail, 600));
        }

        $result = $json['result'] ?? [];

        return is_array($result) ? $result : [$result];
    }

    private static function isAuthenticationError(string $detail): bool {
        $detail = strtolower($detail);

        foreach ([
            'not authorized',
            'not authorised',
            'unauthorized',
            'unauthorised',
            'invalid api token',
            'api token is invalid',
            'api token has expired',
            'session terminated',
            're-login'
        ] as $needle) {
            if (strpos($detail, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
