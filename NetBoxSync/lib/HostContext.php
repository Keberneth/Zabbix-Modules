<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

class HostContext {

    private array $host;
    private ZabbixApiClient $zabbix;
    private array $config;
    private array $item_key_cache = [];
    private array $item_name_cache = [];
    private array $search_key_cache = [];
    private array $search_name_cache = [];
    private ?string $os_value = null;
    private ?array $os_info = null;
    private ?string $os_eol = null;
    private ?array $cpu_memory = null;
    private ?array $windows_disks = null;
    private ?array $linux_disks = null;
    private ?array $interfaces = null;
    private $agent_interface = false;
    private ?array $resolved_vm = null;
    private ?array $resolved_device = null;
    private array $vars = [];
    private static ?array $endoflife_products = null;

    public function __construct(array $host, ZabbixApiClient $zabbix, array $config) {
        $this->host = $host;
        $this->zabbix = $zabbix;
        $this->config = $config;
        $this->vars = [
            'host' => $this->hostName(),
            'hostid' => $this->hostId()
        ];
    }

    public function hostId(): string {
        return (string) ($this->host['hostid'] ?? '');
    }

    public function hostName(): string {
        return (string) ($this->host['host'] ?? '');
    }

    public function vars(): array {
        return $this->vars;
    }

    public function setVar(string $key, $value): void {
        $this->vars[$key] = $value;
    }

    public function getResolvedVm(): ?array {
        return $this->resolved_vm;
    }

    public function setResolvedVm(?array $vm): void {
        $this->resolved_vm = $vm;

        if (is_array($vm)) {
            $this->setVar('vm_id', (string) ($vm['id'] ?? ''));
            $this->setVar('vm_name', (string) ($vm['name'] ?? ''));
            $this->setVar('vm_url', (string) ($vm['url'] ?? ''));
        }
    }

    public function getResolvedDevice(): ?array {
        return $this->resolved_device;
    }

    public function setResolvedDevice(?array $device): void {
        $this->resolved_device = $device;

        if (is_array($device)) {
            $this->setVar('device_id', (string) ($device['id'] ?? ''));
            $this->setVar('device_name', (string) ($device['name'] ?? ''));
            $this->setVar('device_url', (string) ($device['url'] ?? ''));
        }
    }

    public function getItemByKey(string $key): ?array {
        if ($key === '') {
            return null;
        }

        if (!array_key_exists($key, $this->item_key_cache)) {
            $this->item_key_cache[$key] = $this->zabbix->getItemByExactKey($this->hostId(), $key);
        }

        return $this->item_key_cache[$key];
    }

    public function getItemByName(string $name): ?array {
        if ($name === '') {
            return null;
        }

        if (!array_key_exists($name, $this->item_name_cache)) {
            $this->item_name_cache[$name] = $this->zabbix->getItemByExactName($this->hostId(), $name);
        }

        return $this->item_name_cache[$name];
    }

    public function searchItemsByKey(string $pattern, array $extra = []): array {
        $cache_key = md5($pattern.'|'.json_encode($extra));

        if (!array_key_exists($cache_key, $this->search_key_cache)) {
            $this->search_key_cache[$cache_key] = $this->zabbix->searchItemsByKey($this->hostId(), $pattern, $extra);
        }

        return $this->search_key_cache[$cache_key];
    }

    public function searchItemsByName(string $pattern, array $extra = []): array {
        $cache_key = md5($pattern.'|'.json_encode($extra));

        if (!array_key_exists($cache_key, $this->search_name_cache)) {
            $this->search_name_cache[$cache_key] = $this->zabbix->searchItemsByName($this->hostId(), $pattern, $extra);
        }

        return $this->search_name_cache[$cache_key];
    }

    public function getItemValueByKey(string $key, string $default = ''): string {
        $item = $this->getItemByKey($key);

        return is_array($item) ? (string) ($item['lastvalue'] ?? $default) : $default;
    }

    public function getItemValueByName(string $name, string $default = ''): string {
        $item = $this->getItemByName($name);

        return is_array($item) ? (string) ($item['lastvalue'] ?? $default) : $default;
    }

    public function resolveSourceValue(string $mode, string $value): ?string {
        $mode = trim($mode);

        switch ($mode) {
            case 'host_name':
                return $this->hostName();

            case 'agent_ip':
                $iface = $this->getAgentInterface();
                return is_array($iface) ? (string) ($iface['ip'] ?? '') : null;

            case 'static':
                return $value;

            case 'item_name':
                $resolved = $this->getItemValueByName($value, '');
                return $resolved !== '' ? $resolved : null;

            case 'item_key':
            default:
                $resolved = $this->getItemValueByKey($value, '');
                return $resolved !== '' ? $resolved : null;
        }
    }

    public function getOsValue(): ?string {
        if ($this->os_value !== null) {
            return $this->os_value;
        }

        $pretty_name_item = (string) ($this->config['vm']['os_pretty_name_item_name'] ?? '');
        $fallback_key = (string) ($this->config['vm']['os_fallback_item_key'] ?? '');

        $os_value = $this->getItemValueByName($pretty_name_item, '');

        if ($os_value === '') {
            $os_value = $this->getItemValueByKey($fallback_key, '');
        }

        $os_value = trim($os_value);
        $this->os_value = $os_value !== '' ? $os_value : null;

        if ($this->os_value !== null) {
            $this->setVar('os_value', $this->os_value);
        }

        return $this->os_value;
    }

    public function getOsInfo(): array {
        if ($this->os_info !== null) {
            return $this->os_info;
        }

        $os_string = strtolower((string) ($this->getOsValue() ?? ''));
        $vendor = null;
        $version_candidates = [];

        if ($os_string !== '') {
            if (preg_match_all('/\b(\d+(?:\.\d+)+)\b/', $os_string, $matches)) {
                foreach ($matches[1] as $full) {
                    $parts = explode('.', $full);
                    while (count($parts) > 0) {
                        $version_candidates[] = implode('.', $parts);
                        array_pop($parts);
                    }
                }
            }

            if (preg_match('/(\d+)\s*sp(\d+)/', $os_string, $m)) {
                $version_candidates[] = $m[1].'.'.$m[2];
                $version_candidates[] = $m[1];
            }

            if (preg_match('/\b(20\d{2})\b/', $os_string, $m)) {
                $version_candidates[] = $m[1];
            }

            if (preg_match('/\b(\d+)\b/', $os_string, $m)) {
                $version_candidates[] = $m[1];
            }

            $version_candidates = array_values(array_unique($version_candidates));

            $normalized_os = preg_replace('/[^a-z0-9]+/', '', $os_string);
            $products = $this->endoflifeProducts();
            $best = null;
            $best_len = 0;

            foreach ($products as $product) {
                $nslug = preg_replace('/[^a-z0-9]+/', '', strtolower($product));
                if ($nslug === '' || strlen($nslug) < 3) {
                    continue;
                }
                if (strpos($normalized_os, $nslug) !== false && strlen($nslug) > $best_len) {
                    $best = $product;
                    $best_len = strlen($nslug);
                }
            }

            $aliases = [
                'redhatenterpriselinux' => 'rhel',
                'redhat' => 'rhel',
                'suselinuxenterpriseserver' => 'sles',
                'opensuseleap' => 'opensuse',
                'amazonlinux' => 'amazon-linux'
            ];
            $products_set = array_flip($products);

            foreach ($aliases as $alias_key => $slug) {
                if (strpos($normalized_os, $alias_key) !== false
                    && isset($products_set[$slug])
                    && strlen($alias_key) > $best_len) {
                    $best = $slug;
                    $best_len = strlen($alias_key);
                }
            }

            if ($best !== null) {
                $vendor = $best;
            }
            elseif (strpos($os_string, 'linux') !== false) {
                $vendor = 'linux';
            }
        }

        $this->os_info = [
            'vendor' => $vendor,
            'version' => $version_candidates[0] ?? null,
            'version_candidates' => $version_candidates
        ];

        return $this->os_info;
    }

    public function getOsEol(): string {
        if ($this->os_eol !== null) {
            return $this->os_eol;
        }

        $info = $this->getOsInfo();
        $vendor = (string) ($info['vendor'] ?? '');
        $candidates = is_array($info['version_candidates'] ?? null) ? $info['version_candidates'] : [];

        if ($vendor === '' || $candidates === []) {
            $this->os_eol = 'Unknown';
            return $this->os_eol;
        }

        try {
            $rows = $this->httpGetJson('https://endoflife.date/api/'.rawurlencode($vendor).'.json');

            if (is_array($rows)) {
                foreach ($candidates as $candidate) {
                    $best_row = null;
                    $best_cycle_len = -1;

                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $cycle = (string) ($row['cycle'] ?? '');
                        if ($cycle === '') {
                            continue;
                        }

                        $matches = ($cycle === $candidate) || (strpos($candidate, $cycle.'.') === 0);
                        if ($matches && strlen($cycle) > $best_cycle_len) {
                            $best_row = $row;
                            $best_cycle_len = strlen($cycle);
                        }
                    }

                    if ($best_row !== null) {
                        $eol = trim((string) ($best_row['eol'] ?? ''));
                        $this->os_eol = $eol !== '' ? $eol : 'Still Supported';
                        return $this->os_eol;
                    }
                }
            }
        }
        catch (\Throwable $e) {
            // Fall through to Unknown.
        }

        $this->os_eol = 'Unknown';
        return $this->os_eol;
    }

    private function endoflifeProducts(): array {
        if (self::$endoflife_products !== null) {
            return self::$endoflife_products;
        }

        $data = $this->httpGetJson('https://endoflife.date/api/all.json');
        self::$endoflife_products = is_array($data) ? array_values(array_filter($data, 'is_string')) : [];

        return self::$endoflife_products;
    }

    public function getCpuMemory(): array {
        if ($this->cpu_memory !== null) {
            return $this->cpu_memory;
        }

        $os_value = strtolower((string) ($this->getOsValue() ?? ''));
        $vendor = (string) ($this->getOsInfo()['vendor'] ?? '');
        $linux_vendors = ['ubuntu', 'rhel', 'redhat', 'oracle-linux', 'rocky-linux', 'sles', 'opensuse', 'alma', 'almalinux', 'debian', 'centos', 'centos-stream', 'fedora', 'alpine', 'amazon-linux', 'linux'];

        $cpu_key = '';

        if (strpos($os_value, 'windows') !== false) {
            $cpu_key = (string) ($this->config['vm']['windows_cpu_item_key'] ?? '');
        }
        elseif (strpos($os_value, 'linux') !== false || in_array($vendor, $linux_vendors, true)) {
            $cpu_key = (string) ($this->config['vm']['linux_cpu_item_key'] ?? '');
        }

        $memory_key = (string) ($this->config['vm']['memory_item_key'] ?? '');

        $vcpus = 0;
        $memory_mb = 0;

        if ($cpu_key !== '') {
            $cpu_value = $this->getItemValueByKey($cpu_key, '0');
            if (is_numeric($cpu_value)) {
                $vcpus = (int) ceil((float) $cpu_value);
            }
        }

        if ($memory_key !== '') {
            $memory_value = $this->getItemValueByKey($memory_key, '0');
            if (is_numeric($memory_value)) {
                $memory_gb = (int) ceil(((float) $memory_value) / (1024 ** 3));
                $memory_mb = strtolower((string) ($this->config['vm']['memory_unit'] ?? 'mb')) === 'gb'
                    ? $memory_gb
                    : $memory_gb * 1000;
            }
        }

        $this->cpu_memory = [
            'vcpus' => $vcpus,
            'memory_mb' => $memory_mb
        ];

        $this->setVar('vcpus', (string) $vcpus);
        $this->setVar('memory_mb', (string) $memory_mb);

        return $this->cpu_memory;
    }

    public function getSqlLicense(): ?string {
        $key = (string) ($this->config['vm']['sql_version_item_key'] ?? '');

        if ($key === '') {
            return null;
        }

        $value = strtolower($this->getItemValueByKey($key, ''));

        if ($value === '') {
            return null;
        }

        if (strpos($value, 'standard edition') !== false) {
            return 'SQL Server Standard Edition';
        }

        if (strpos($value, 'enterprise edition') !== false) {
            return 'SQL Server Enterprise Edition';
        }

        if (strpos($value, 'web edition') !== false) {
            return 'SQL Server Web Edition';
        }

        return null;
    }

    public function getWindowsDisks(): array {
        if ($this->windows_disks !== null) {
            return $this->windows_disks;
        }

        $pattern = (string) ($this->config['vm']['windows_disk_search'] ?? '');
        $rows = $this->searchItemsByKey($pattern);

        $disks = [];

        foreach ($rows as $item) {
            $key = (string) ($item['key_'] ?? '');
            $value = (string) ($item['lastvalue'] ?? '0');

            if (preg_match('/\[([a-zA-Z]:),total\]/', $key, $m)) {
                $disks[$m[1]] = $this->bytesToRoundedGb($value);
            }
        }

        $this->windows_disks = $disks;

        return $this->windows_disks;
    }

    public function getLinuxDisks(): array {
        if ($this->linux_disks !== null) {
            return $this->linux_disks;
        }

        $pattern = (string) ($this->config['vm']['linux_disk_search'] ?? '');
        $rows = $this->searchItemsByKey($pattern);

        $disks = [];

        foreach ($rows as $item) {
            $key = (string) ($item['key_'] ?? '');
            $value = (string) ($item['lastvalue'] ?? '0');

            if (preg_match('#vfs\.file\.contents\[/sys/block/(.*?)/size\]#', $key, $m)) {
                $disks[$m[1]] = $this->bytesToRoundedGb($value);
            }
        }

        $this->linux_disks = $disks;

        return $this->linux_disks;
    }

    public function getInterfaceNames(): array {
        if ($this->interfaces !== null) {
            return $this->interfaces;
        }

        $pattern = (string) ($this->config['vm']['interface_search'] ?? '');
        $rows = $this->searchItemsByKey($pattern, ['selectTags' => 'extend']);

        $interfaces = [];

        foreach ($rows as $item) {
            $name = null;

            foreach (($item['tags'] ?? []) as $tag) {
                if (($tag['tag'] ?? '') === 'interface') {
                    $name = (string) ($tag['value'] ?? '');
                    break;
                }
            }

            if ($name !== null && $name !== '') {
                $interfaces[$name] = true;
            }
        }

        $this->interfaces = array_keys($interfaces);

        return $this->interfaces;
    }

    public function getAgentInterface(): ?array {
        if ($this->agent_interface !== false) {
            return $this->agent_interface;
        }

        $this->agent_interface = $this->zabbix->getMainAgentInterface($this->hostId());

        if (is_array($this->agent_interface)) {
            $this->setVar('agent_ip', (string) ($this->agent_interface['ip'] ?? ''));
        }

        return $this->agent_interface;
    }

    public function resolveVmCandidateNames(): array {
        $base_name = $this->hostName();
        $suffix = trim((string) ($this->config['vm']['name_suffix'] ?? ''));
        $separator = (string) ($this->config['vm']['name_suffix_separator'] ?? '.');

        $candidates = [$base_name];

        if ($suffix !== '') {
            $candidates[] = $base_name.$separator.$suffix;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function bytesToRoundedGb(string $value): int {
        if (!is_numeric($value)) {
            return 0;
        }

        return (int) ceil(((float) $value) / (1024 ** 3));
    }

    private function httpGetJson(string $url) {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            curl_close($ch);
            return null;
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            return null;
        }

        return Util::decodeJson($body, null);
    }
}
