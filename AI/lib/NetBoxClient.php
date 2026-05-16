<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class NetBoxClient {

    private string $url;
    private string $token;
    private bool $verify_peer;
    private int $timeout;

    /** @var array<int, array>|null */
    private ?array $vm_cache = null;

    /** @var array<int, array>|null */
    private ?array $device_cache = null;

    public function __construct(string $url, string $token, bool $verify_peer = true, int $timeout = 10) {
        $this->url = rtrim(trim($url), '/');
        $this->token = trim($token);
        $this->verify_peer = $verify_peer;
        $this->timeout = $timeout;
    }

    public static function fromConfig(array $config): ?self {
        $config = Config::mergeWithDefaults($config);

        if (!Util::truthy($config['netbox']['enabled'] ?? false)) {
            return null;
        }

        $url = trim((string) ($config['netbox']['url'] ?? ''));
        $token = Config::resolveSecret($config['netbox']['token'] ?? '', $config['netbox']['token_env'] ?? '');

        if ($url === '' || $token === '') {
            return null;
        }

        return new self(
            $url,
            $token,
            (bool) ($config['netbox']['verify_peer'] ?? true),
            (int) ($config['netbox']['timeout'] ?? 10)
        );
    }

    /**
     * Look up a single VM or device by hostname and return its raw NetBox
     * record (or null if not found). Used by the AI tool `get_netbox_info`.
     *
     * @return array{kind: string, record: array}|null
     */
    public function lookupByHostname(string $hostname): ?array {
        $hostname = trim($hostname);

        if ($hostname === '') {
            return null;
        }

        $vm = $this->findVirtualMachine($hostname);
        if ($vm !== null) {
            return ['kind' => 'vm', 'record' => $vm];
        }

        $device = $this->findDevice($hostname);
        if ($device !== null) {
            return ['kind' => 'device', 'record' => $device];
        }

        return null;
    }

    /**
     * List NetBox VMs and/or devices with optional filters. Used by the AI
     * tool `list_netbox_devices` to build inventory reports across many
     * servers in a single API roundtrip.
     *
     * Accepted filters (all optional, all best-effort substring/equality
     * matches against the corresponding NetBox fields):
     *   - kind          'vm' | 'device' | 'both' (default 'both')
     *   - search        substring matched against name / display
     *   - platform      e.g. 'Linux', 'Windows'
     *   - role          e.g. 'Server', 'Database'
     *   - site          e.g. 'Stockholm'
     *   - status        e.g. 'active', 'offline'
     *   - tenant        e.g. 'Production'
     *   - limit         max combined rows returned (default 200, cap 1000)
     *
     * @return array<int, array{kind: string, ...}>
     */
    public function listDevicesAndVMs(array $filters = []): array {
        $kind = strtolower(trim((string) ($filters['kind'] ?? 'both')));
        if (!in_array($kind, ['vm', 'device', 'both'], true)) {
            $kind = 'both';
        }

        $limit = (int) ($filters['limit'] ?? 200);
        $limit = max(1, min($limit, 1000));

        $needles = [];
        foreach (['search', 'platform', 'role', 'site', 'status', 'tenant'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $needles[$key] = strtolower($value);
            }
        }

        $rows = [];

        if ($kind === 'vm' || $kind === 'both') {
            $vms = $this->get('/api/virtualization/virtual-machines/', ['limit' => $limit]);
            foreach (($vms['results'] ?? []) as $vm) {
                if (!$this->matchesFilters($vm, $needles, 'vm')) {
                    continue;
                }
                $rows[] = $this->summariseVm($vm);
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        if ($kind === 'device' || $kind === 'both') {
            $devices = $this->get('/api/dcim/devices/', ['limit' => $limit]);
            foreach (($devices['results'] ?? []) as $device) {
                if (!$this->matchesFilters($device, $needles, 'device')) {
                    continue;
                }
                $rows[] = $this->summariseDevice($device);
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    private function matchesFilters(array $record, array $needles, string $kind): bool {
        $name = strtolower((string) ($record['name'] ?? ''));
        $display = strtolower((string) ($record['display'] ?? ''));
        $platform = strtolower((string) ($record['platform']['display'] ?? ($record['platform']['name'] ?? '')));
        $role = strtolower((string) ($record['role']['display'] ?? ($record['role']['name'] ?? '')));
        $site = strtolower((string) ($record['site']['display'] ?? ($record['site']['name'] ?? '')));
        $status = strtolower((string) ($record['status']['label'] ?? ($record['status']['value'] ?? '')));
        $tenant = strtolower((string) ($record['tenant']['display'] ?? ($record['tenant']['name'] ?? '')));

        foreach ($needles as $key => $needle) {
            $haystack = '';
            if ($key === 'search') {
                $haystack = $name.' '.$display;
            }
            elseif ($key === 'platform') {
                $haystack = $platform;
            }
            elseif ($key === 'role') {
                $haystack = $role;
            }
            elseif ($key === 'site') {
                $haystack = $site;
            }
            elseif ($key === 'status') {
                $haystack = $status;
            }
            elseif ($key === 'tenant') {
                $haystack = $tenant;
            }

            if (strpos($haystack, $needle) === false) {
                return false;
            }
        }

        return true;
    }

    private function summariseVm(array $vm): array {
        $custom = is_array($vm['custom_fields'] ?? null) ? $vm['custom_fields'] : [];

        return [
            'kind' => 'vm',
            'id' => (int) ($vm['id'] ?? 0),
            'name' => (string) ($vm['name'] ?? ''),
            'display' => (string) ($vm['display'] ?? ''),
            'status' => (string) ($vm['status']['label'] ?? ''),
            'site' => (string) ($vm['site']['display'] ?? ''),
            'cluster' => (string) ($vm['cluster']['display'] ?? ''),
            'role' => (string) ($vm['role']['display'] ?? ''),
            'tenant' => (string) ($vm['tenant']['display'] ?? ''),
            'platform' => (string) ($vm['platform']['display'] ?? ''),
            'primary_ip' => (string) ($vm['primary_ip4']['address'] ?? ($vm['primary_ip']['address'] ?? '')),
            'vcpus' => $vm['vcpus'] !== null && $vm['vcpus'] !== '' ? (float) $vm['vcpus'] : null,
            'memory_mb' => $vm['memory'] !== null && $vm['memory'] !== '' ? (int) $vm['memory'] : null,
            'disk_mb' => $vm['disk'] !== null && $vm['disk'] !== '' ? (int) $vm['disk'] : null,
            'operating_system' => (string) ($custom['operating_system'] ?? '')
        ];
    }

    private function summariseDevice(array $device): array {
        return [
            'kind' => 'device',
            'id' => (int) ($device['id'] ?? 0),
            'name' => (string) ($device['name'] ?? ''),
            'display' => (string) ($device['display'] ?? ''),
            'status' => (string) ($device['status']['label'] ?? ''),
            'site' => (string) ($device['site']['display'] ?? ''),
            'rack' => (string) ($device['rack']['display'] ?? ''),
            'role' => (string) ($device['role']['display'] ?? ''),
            'device_type' => (string) ($device['device_type']['display'] ?? ''),
            'tenant' => (string) ($device['tenant']['display'] ?? ''),
            'platform' => (string) ($device['platform']['display'] ?? ''),
            'primary_ip' => (string) ($device['primary_ip4']['address'] ?? ($device['primary_ip']['address'] ?? '')),
            'serial' => (string) ($device['serial'] ?? ''),
            'vcpus' => null,
            'memory_mb' => null,
            'disk_mb' => null,
            'operating_system' => ''
        ];
    }

    public function getContextForHostname(string $hostname): string {
        $hostname = trim($hostname);

        if ($hostname === '') {
            return '';
        }

        $vm = $this->findVirtualMachine($hostname);

        if ($vm) {
            $services = $this->getServices(['virtual_machine_id' => $vm['id'] ?? 0]);

            return $this->formatVirtualMachine($vm, $services);
        }

        $device = $this->findDevice($hostname);

        if ($device) {
            $services = $this->getServices(['device_id' => $device['id'] ?? 0]);

            return $this->formatDevice($device, $services);
        }

        return 'No NetBox VM or device match found.';
    }

    private function getAllVMs(): array {
        if ($this->vm_cache !== null) {
            return $this->vm_cache;
        }

        try {
            $data = $this->get('/api/virtualization/virtual-machines/', ['limit' => 1000]);
            $this->vm_cache = is_array($data['results'] ?? null) ? $data['results'] : [];
        }
        catch (\Throwable $e) {
            $this->vm_cache = [];
        }

        return $this->vm_cache;
    }

    private function getAllDevices(): array {
        if ($this->device_cache !== null) {
            return $this->device_cache;
        }

        try {
            $data = $this->get('/api/dcim/devices/', ['limit' => 1000]);
            $this->device_cache = is_array($data['results'] ?? null) ? $data['results'] : [];
        }
        catch (\Throwable $e) {
            $this->device_cache = [];
        }

        return $this->device_cache;
    }

    private function findVirtualMachine(string $hostname): ?array {
        $target = strtolower(trim($hostname));
        if ($target === '') {
            return null;
        }

        foreach ($this->getAllVMs() as $vm) {
            $name = strtolower((string) ($vm['name'] ?? ''));
            $display = strtolower((string) ($vm['display'] ?? ''));

            if (strpos($name, $target) !== false || strpos($display, $target) !== false) {
                return $vm;
            }
        }

        return null;
    }

    private function findDevice(string $hostname): ?array {
        $target = strtolower(trim($hostname));
        if ($target === '') {
            return null;
        }

        foreach ($this->getAllDevices() as $device) {
            $name = strtolower((string) ($device['name'] ?? ''));
            $display = strtolower((string) ($device['display'] ?? ''));

            if (strpos($name, $target) !== false || strpos($display, $target) !== false) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Scan a free-form text (typically a user chat message) for tokens that
     * look like server hostnames and resolve those that exist in NetBox.
     *
     * Returns an array of [hostname => formatted-NetBox-record] for hostnames
     * that matched a VM or device. Uses the cached VM/device list so a chat
     * turn that mentions several hostnames still incurs only one VM + one
     * device API call.
     *
     * @return array<string, string>
     */
    public function detectAndLookupHostnames(string $text, int $max_candidates = 5): array {
        $text = trim($text);
        if ($text === '' || $max_candidates < 1) {
            return [];
        }

        $candidates = self::extractHostnameCandidates($text, $max_candidates);
        if (!$candidates) {
            return [];
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            // Use single-pass: only call getContextForHostname when we have a
            // match candidate, so we don't pay the formatting cost for tokens
            // that don't exist in NetBox.
            $vm = $this->findVirtualMachine($candidate);
            if ($vm !== null) {
                $services = $this->getServices(['virtual_machine_id' => $vm['id'] ?? 0]);
                $matches[$candidate] = $this->formatVirtualMachine($vm, $services);
                continue;
            }
            $device = $this->findDevice($candidate);
            if ($device !== null) {
                $services = $this->getServices(['device_id' => $device['id'] ?? 0]);
                $matches[$candidate] = $this->formatDevice($device, $services);
            }
        }

        return $matches;
    }

    /**
     * Extract candidate hostname tokens from a free-form message. Matches
     * server-name patterns (alphanumeric + hyphen/underscore, length 5-64,
     * containing at least one digit OR at least one hyphen — so plain English
     * words like "memory" or "server" do not trigger lookups). Stop-words and
     * common Zabbix terms are filtered out.
     *
     * @return array<int, string>
     */
    public static function extractHostnameCandidates(string $text, int $max): array {
        // Aggressive stopword list: common technical / English words that
        // could accidentally match the hostname regex. Cheap to add, cheap to
        // check — keep it long.
        $stopwords = [
            'zabbix', 'netbox', 'server', 'servers', 'host', 'hosts', 'hostname',
            'linux', 'windows', 'database', 'cluster', 'production', 'staging',
            'item', 'items', 'trigger', 'triggers', 'problem', 'problems',
            'graph', 'graphs', 'report', 'reports', 'inventory', 'capacity',
            'cpu', 'ram', 'disk', 'memory', 'storage', 'metric', 'metrics',
            'true', 'false', 'null', 'none', 'system', 'systems',
            'address', 'password', 'username', 'config', 'configuration',
            'interface', 'software', 'process', 'service', 'services',
            'docker', 'kubernetes', 'kubectl', 'systemd', 'apache', 'nginx',
            'response', 'request', 'session', 'context', 'message', 'messages',
            'restart', 'reboot', 'reload', 'enable', 'disable', 'enabled', 'disabled'
        ];

        $candidates = [];
        $seen = [];

        if (preg_match_all('/(?<![A-Za-z0-9_])([A-Za-z][A-Za-z0-9_\-]{4,63})(?![A-Za-z0-9_])/', $text, $matches)) {
            foreach ($matches[1] as $token) {
                $lower = strtolower($token);

                if (isset($seen[$lower]) || in_array($lower, $stopwords, true)) {
                    continue;
                }

                if (!self::looksLikeHostname($token)) {
                    continue;
                }

                $seen[$lower] = true;
                $candidates[] = $token;

                if (count($candidates) >= $max) {
                    break;
                }
            }
        }

        return $candidates;
    }

    /**
     * Stricter "this token looks like a server hostname" test. Returns true
     * only when the token shows a hostname-like signature:
     *   - has 2+ consecutive uppercase letters (LHBHANA101, SRV-01), OR
     *   - has 3+ segments split by '-' (kt4-jump-linux, web-app-prod), OR
     *   - ends in 2+ digits and contains at least one letter (lhbansible101)
     *
     * Rejects bait like "auto-detect", "linux-fan", "cpu-load-1m" without
     * needing to enumerate every false-positive in stopwords.
     */
    private static function looksLikeHostname(string $token): bool {
        // Must contain at least one digit somewhere.
        if (!preg_match('/[0-9]/', $token)) {
            return false;
        }

        // Pattern 1: a block of 2+ consecutive uppercase letters.
        if (preg_match('/[A-Z]{2,}/', $token)) {
            return true;
        }

        // Pattern 2: 3 or more hyphen-separated segments.
        if (substr_count($token, '-') >= 2) {
            return true;
        }

        // Pattern 3: token ends in 2+ digits (e.g. lhbansible101, srv01).
        if (preg_match('/[0-9]{2,}$/', $token)) {
            return true;
        }

        return false;
    }

    private function getServices(array $params): array {
        $filtered = array_filter($params, static function($value) {
            return !empty($value);
        });

        if ($filtered === []) {
            return [];
        }

        $data = $this->get('/api/ipam/services/', $filtered);

        return $data['results'] ?? [];
    }

    private function get(string $endpoint, array $params = []): array {
        $headers = [
            'Authorization' => 'Token '.$this->token,
            'Accept' => 'application/json'
        ];

        $url = $this->url.$endpoint;

        if ($params) {
            $url .= '?'.http_build_query($params);
        }

        $response = HttpClient::expectSuccess('GET', $url, [
            'headers' => $headers,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('NetBox did not return valid JSON for '.$endpoint);
        }

        return $response['json'];
    }

    private function formatVirtualMachine(array $vm, array $services): string {
        $lines = [];
        $lines[] = 'NetBox object type: Virtual machine';
        $lines[] = 'Name: '.($vm['name'] ?? 'N/A');
        $lines[] = 'Status: '.($vm['status']['label'] ?? 'N/A');
        $lines[] = 'Site: '.($vm['site']['display'] ?? 'N/A');
        $lines[] = 'Cluster: '.($vm['cluster']['display'] ?? 'N/A');
        $lines[] = 'Role: '.($vm['role']['display'] ?? 'N/A');
        $lines[] = 'Tenant: '.($vm['tenant']['display'] ?? 'N/A');
        $lines[] = 'Platform: '.($vm['platform']['display'] ?? 'N/A');
        $lines[] = 'Primary IP: '.($vm['primary_ip4']['address'] ?? $vm['primary_ip']['address'] ?? 'N/A');
        $lines[] = 'Resources: vCPU '.($vm['vcpus'] ?? 'N/A').', RAM '.($vm['memory'] ?? 'N/A').' MB, Disk '.($vm['disk'] ?? 'N/A').' MB';

        $custom = is_array($vm['custom_fields'] ?? null) ? $vm['custom_fields'] : [];

        if (!empty($custom['operating_system'])) {
            $lines[] = 'Operating system: '.$custom['operating_system'];
        }

        if (!empty($custom['ha_with_server']) && is_array($custom['ha_with_server'])) {
            $ha = [];

            foreach ($custom['ha_with_server'] as $item) {
                if (is_array($item)) {
                    $ha[] = $item['display'] ?? $item['name'] ?? json_encode($item);
                }
                else {
                    $ha[] = (string) $item;
                }
            }

            if ($ha) {
                $lines[] = 'HA with server: '.implode(', ', $ha);
            }
        }

        if (!empty($custom['operations_services']) && is_array($custom['operations_services'])) {
            $lines[] = 'Operations services: '.implode(', ', $custom['operations_services']);
        }

        $this->appendServices($lines, $services);

        return implode("\n", $lines);
    }

    private function formatDevice(array $device, array $services): string {
        $lines = [];
        $lines[] = 'NetBox object type: Device';
        $lines[] = 'Name: '.($device['name'] ?? 'N/A');
        $lines[] = 'Status: '.($device['status']['label'] ?? 'N/A');
        $lines[] = 'Site: '.($device['site']['display'] ?? 'N/A');
        $lines[] = 'Rack: '.($device['rack']['display'] ?? 'N/A');
        $lines[] = 'Role: '.($device['role']['display'] ?? 'N/A');
        $lines[] = 'Device type: '.($device['device_type']['display'] ?? 'N/A');
        $lines[] = 'Tenant: '.($device['tenant']['display'] ?? 'N/A');
        $lines[] = 'Platform: '.($device['platform']['display'] ?? 'N/A');
        $lines[] = 'Primary IP: '.($device['primary_ip4']['address'] ?? $device['primary_ip']['address'] ?? 'N/A');
        $lines[] = 'Serial: '.($device['serial'] ?? 'N/A');
        $this->appendServices($lines, $services);

        return implode("\n", $lines);
    }

    private function appendServices(array &$lines, array $services): void {
        if (!$services) {
            return;
        }

        $lines[] = 'Services:';

        foreach ($services as $service) {
            $name = $service['name'] ?? 'N/A';
            $protocol = $service['protocol']['label'] ?? 'N/A';
            $ports = is_array($service['ports'] ?? null) ? implode(',', $service['ports']) : '';
            $description = trim((string) ($service['description'] ?? ''));
            $lines[] = '  - '.$name.' ('.$protocol.($ports !== '' ? '/'.$ports : '').')'.($description !== '' ? ' '.$description : '');
        }
    }
}
