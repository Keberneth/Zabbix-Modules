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
        $version = null;

        if ($os_string !== '') {
            if (strpos($os_string, 'ubuntu') !== false) {
                $vendor = 'ubuntu';
                if (preg_match('/ubuntu\s+(\d+\.\d+)/', $os_string, $m)) {
                    $version = $m[1];
                }
            }
            elseif (strpos($os_string, 'oracle linux') !== false) {
                $vendor = 'oracle-linux';
                if (preg_match('/oracle linux.*?(\d+)\.?/', $os_string, $m)) {
                    $version = $m[1];
                }
            }
            elseif (strpos($os_string, 'red hat enterprise linux') !== false) {
                $vendor = 'redhat';
                if (preg_match('/red hat enterprise linux.*?(\d+)\.?/', $os_string, $m)) {
                    $version = $m[1];
                }
            }
            elseif (strpos($os_string, 'rocky linux') !== false) {
                $vendor = 'rocky-linux';
                if (preg_match('/rocky linux\s+(\d+)\.?/', $os_string, $m)) {
                    $version = $m[1];
                }
            }
            elseif (strpos($os_string, 'suse linux enterprise server') !== false) {
                $vendor = 'sles';
                if (preg_match('/suse linux enterprise server\s+(\d+)(?:\s*sp(\d+))?/', $os_string, $m)) {
                    $version = isset($m[2]) && $m[2] !== '' ? ($m[1].'.'.$m[2]) : $m[1];
                }
            }
            elseif (strpos($os_string, 'centos') !== false) {
                $vendor = (strpos($os_string, 'stream') !== false) ? 'centos-stream' : 'centos';
                if (preg_match('/centos(?: linux| stream)?(?: release)?\s*(\d+)(?:\.(\d+))?/', $os_string, $m)) {
                    $version = isset($m[2]) && $m[2] !== '' ? ($m[1].'.'.$m[2]) : $m[1];
                }
            }
            elseif (strpos($os_string, 'windows server') !== false) {
                $vendor = 'windows-server';
                if (preg_match('/windows server.*?\b(2003|2008|2012|2016|2019|2022|2025)\b/', $os_string, $m)) {
                    $version = $m[1];
                }
            }
            elseif (strpos($os_string, 'linux') !== false) {
                $vendor = 'linux';
            }
        }

        $this->os_info = [
            'vendor' => $vendor,
            'version' => $version
        ];

        return $this->os_info;
    }

    public function getOsEol(): string {
        if ($this->os_eol !== null) {
            return $this->os_eol;
        }

        $info = $this->getOsInfo();
        $vendor = (string) ($info['vendor'] ?? '');
        $version = (string) ($info['version'] ?? '');

        if ($vendor === '' || $version === '') {
            $this->os_eol = 'Unknown';
            return $this->os_eol;
        }

        try {
            if ($vendor === 'sles') {
                $rows = $this->httpGetJson('https://endoflife.date/api/sles.json');

                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        if ((string) ($row['cycle'] ?? '') === $version) {
                            $eol = trim((string) ($row['eol'] ?? ''));
                            $this->os_eol = $eol !== '' ? $eol : 'Still Supported';
                            return $this->os_eol;
                        }
                    }
                }
            }
            else {
                $row = $this->httpGetJson('https://endoflife.date/api/'.$vendor.'/'.$version.'.json');
                if (is_array($row)) {
                    $eol = trim((string) ($row['eol'] ?? ''));
                    $this->os_eol = $eol !== '' ? $eol : 'Unknown';
                    return $this->os_eol;
                }
            }
        }
        catch (\Throwable $e) {
            // Fall through to Unknown.
        }

        $this->os_eol = 'Unknown';
        return $this->os_eol;
    }

    public function getCpuMemory(): array {
        if ($this->cpu_memory !== null) {
            return $this->cpu_memory;
        }

        $os_value = strtolower((string) ($this->getOsValue() ?? ''));
        $vendor = (string) ($this->getOsInfo()['vendor'] ?? '');
        $linux_vendors = ['ubuntu', 'redhat', 'oracle-linux', 'rocky-linux', 'sles', 'centos', 'centos-stream', 'linux'];

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
                $memory_mb = $memory_gb * 1000;
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
