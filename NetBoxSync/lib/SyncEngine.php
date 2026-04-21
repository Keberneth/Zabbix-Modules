<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use RuntimeException;
use Throwable;

class SyncEngine {

    private array $config;
    private StateStore $store;
    private LogStore $events;
    private ZabbixApiClient $zabbix;
    private NetBoxClient $netbox;
    private array $summary = [];
    private bool $force = false;
    private ?HostContext $current_host = null;

    public static function run(array $config, array $options = []): array {
        $engine = new self($config, $options);
        return $engine->execute();
    }

    public function __construct(array $config, array $options = []) {
        $this->config = Config::sanitizeForRuntime($config ?? []);
        $this->force = !empty($options['force']);
        $this->summary = [
            'ok' => true,
            'source' => (string) ($options['source'] ?? 'manual'),
            'force' => $this->force,
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'elapsed_seconds' => 0,
            'hosts_total' => 0,
            'hosts_processed' => 0,
            'mappings_run' => 0,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => []
        ];

        $runner = $this->config['runner'] ?? [];
        $state_path = (string) ($runner['state_path'] ?? '');
        $log_path = (string) ($runner['log_path'] ?? '');
        $this->store = new StateStore($state_path, $log_path);
        $this->events = new LogStore($log_path);

        $this->zabbix = new ZabbixApiClient();

        $netbox = $this->config['netbox'] ?? [];
        if (empty($netbox['enabled'])) {
            throw new RuntimeException('NetBox integration is disabled in module settings.');
        }

        $this->netbox = new NetBoxClient(
            (string) ($netbox['url'] ?? ''),
            (string) ($netbox['token'] ?? ''),
            !empty($netbox['verify_peer']),
            (int) ($netbox['timeout'] ?? 15)
        );
    }

    private function execute(): array {
        $started_at = microtime(true);

        try {
            $this->store->ensureDirectories();
            $this->store->acquireLock();
            $this->log('info', 'NetBox sync run started.', [
                'source' => $this->summary['source'],
                'force' => $this->force
            ]);

            $this->ensureBuiltInCustomFields();

            $hosts = $this->zabbix->getAllHosts();
            $max_hosts = (int) ($this->config['runner']['max_hosts_per_run'] ?? 0);

            $this->summary['hosts_total'] = count($hosts);

            foreach ($hosts as $index => $host) {
                if ($max_hosts > 0 && $this->summary['hosts_processed'] >= $max_hosts) {
                    $this->note('Reached max_hosts_per_run limit; remaining hosts skipped.');
                    break;
                }

                $hostid = (string) ($host['hostid'] ?? '');
                $hostname = (string) ($host['host'] ?? '');

                if ($hostid === '' || $hostname === '') {
                    $this->summary['skipped']++;
                    continue;
                }

                $ctx = new HostContext($host, $this->zabbix, $this->config);

                try {
                    $this->processHost($ctx);
                    $this->summary['hosts_processed']++;
                }
                catch (Throwable $e) {
                    $this->summary['ok'] = false;
                    $this->summary['errors']++;
                    $this->note('Host '.$hostname.' failed: '.$e->getMessage(), 'error');
                    $this->events->record([
                        'type' => LogStore::TYPE_ERROR,
                        'host' => $hostname,
                        'hostid' => $hostid,
                        'sync_id' => 'host',
                        'message' => $e->getMessage()
                    ]);
                    $this->log('error', 'Host sync failed.', [
                        'hostid' => $hostid,
                        'host' => $hostname,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->store->saveTimestamps();
        }
        catch (Throwable $e) {
            $this->summary['ok'] = false;
            $this->summary['errors']++;
            $this->note($e->getMessage(), 'error');
            $this->events->record([
                'type' => LogStore::TYPE_ERROR,
                'sync_id' => 'runner',
                'message' => $e->getMessage()
            ]);
            $this->log('error', 'NetBox sync run failed.', [
                'error' => $e->getMessage()
            ]);
        }
        finally {
            $this->summary['finished_at'] = gmdate('c');
            $this->summary['elapsed_seconds'] = round(microtime(true) - $started_at, 3);
            $this->store->saveLastSummary($this->summary);
            $this->store->releaseLock();
        }

        return $this->summary;
    }

    private function processHost(HostContext $ctx): void {
        $this->current_host = $ctx;
        $hostid = $ctx->hostId();
        $hostname = $ctx->hostName();

        $this->log('info', 'Processing host.', [
            'hostid' => $hostid,
            'host' => $hostname
        ]);

        $standard_catalog = Config::standardSyncCatalog();

        foreach ($standard_catalog as $entry) {
            $sync_id = (string) ($entry['id'] ?? '');

            if (!$this->isStandardSyncEnabled($sync_id)) {
                continue;
            }

            $interval = $this->effectiveInterval(Config::getStandardSyncConfig($this->config, $sync_id));
            $mapping_key = 'std:'.$sync_id;

            if (!$this->force && !$this->store->isDue($hostid, $mapping_key, $interval)) {
                continue;
            }

            $this->summary['mappings_run']++;

            try {
                $changed = $this->runStandardSync($sync_id, $ctx);
                $this->store->touch($hostid, $mapping_key, 'ok', ['changed' => $changed]);
            }
            catch (Throwable $e) {
                $this->summary['ok'] = false;
                $this->summary['errors']++;
                $this->note($hostname.' · '.$sync_id.' failed: '.$e->getMessage(), 'error');
                $this->store->touch($hostid, $mapping_key, 'error', ['error' => Util::truncate($e->getMessage(), 800)]);
                $this->recordEvent(LogStore::TYPE_ERROR, [
                    'sync_id' => $sync_id,
                    'message' => $e->getMessage()
                ]);
                $this->log('error', 'Standard sync failed.', [
                    'hostid' => $hostid,
                    'host' => $hostname,
                    'sync_id' => $sync_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        foreach (($this->config['custom_mappings'] ?? []) as $mapping) {
            if (!is_array($mapping) || empty($mapping['enabled'])) {
                continue;
            }

            $mapping_id = Util::cleanId((string) ($mapping['id'] ?? ''), 'map');
            $interval = $this->effectiveInterval($mapping);
            $store_key = 'map:'.$mapping_id;

            if (!$this->force && !$this->store->isDue($hostid, $store_key, $interval)) {
                continue;
            }

            $this->summary['mappings_run']++;

            try {
                $changed = $this->applyCustomMapping($ctx, $mapping);
                $this->store->touch($hostid, $store_key, 'ok', ['changed' => $changed]);
            }
            catch (Throwable $e) {
                $this->summary['ok'] = false;
                $this->summary['errors']++;
                $this->note($hostname.' · custom '.$mapping_id.' failed: '.$e->getMessage(), 'error');
                $this->store->touch($hostid, $store_key, 'error', ['error' => Util::truncate($e->getMessage(), 800)]);
                $this->recordEvent(LogStore::TYPE_ERROR, [
                    'sync_id' => 'custom:'.$mapping_id,
                    'message' => $e->getMessage()
                ]);
                $this->log('error', 'Custom mapping failed.', [
                    'hostid' => $hostid,
                    'host' => $hostname,
                    'mapping_id' => $mapping_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->current_host = null;
    }

    private function runStandardSync(string $sync_id, HostContext $ctx): bool {
        switch ($sync_id) {
            case 'vm_object':
                return $this->syncVmObject($ctx);

            case 'vm_os_eol':
                return $this->syncVmOsEol($ctx);

            case 'vm_sql_license':
                return $this->syncVmSqlLicense($ctx);

            case 'vm_disks':
                return $this->syncVmDisks($ctx);

            case 'vm_interfaces':
                return $this->syncVmInterfaces($ctx);

            case 'vm_primary_ip':
                return $this->syncVmPrimaryIp($ctx);

            case 'vm_services':
                return $this->syncVmServices($ctx);

            case 'device_object':
                return $this->syncDeviceObject($ctx);

            case 'device_serial':
                return $this->syncDeviceSerial($ctx);

            default:
                $this->summary['skipped']++;
                return false;
        }
    }

    private function isStandardSyncEnabled(string $sync_id): bool {
        $sync = Config::getStandardSyncConfig($this->config, $sync_id);

        if (empty($sync['enabled'])) {
            return false;
        }

        if (str_starts_with($sync_id, 'vm_')) {
            if (empty($this->config['vm']['enabled'])) {
                return false;
            }
            if ($sync_id === 'vm_services' && empty($this->config['services']['enabled'])) {
                return false;
            }
        }

        if (str_starts_with($sync_id, 'device_') && empty($this->config['device']['enabled'])) {
            return false;
        }

        return true;
    }

    private function effectiveInterval(array $row): int {
        $override = (int) ($row['interval_seconds'] ?? 0);
        if ($override > 0) {
            return $override;
        }

        return max(0, (int) ($this->config['runner']['global_interval_seconds'] ?? 0));
    }

    private function syncVmObject(HostContext $ctx): bool {
        $vm_config = $this->config['vm'] ?? [];
        $host_name = $ctx->hostName();
        $os_value = (string) ($ctx->getOsValue() ?? '');
        $os_info = $ctx->getOsInfo();
        $vendor = (string) ($os_info['vendor'] ?? '');

        $linux_vendors = ['ubuntu', 'rhel', 'redhat', 'oracle-linux', 'rocky-linux', 'sles', 'opensuse', 'alma', 'almalinux', 'debian', 'centos', 'centos-stream', 'fedora', 'alpine', 'amazon-linux', 'linux'];

        if ($os_value === '' && !empty($vm_config['require_os_for_create'])) {
            $existing = $this->findExistingVmByCandidate($ctx);
            if ($existing !== null) {
                $ctx->setResolvedVm($existing);
                $this->summary['unchanged']++;
                return false;
            }

            $this->summary['skipped']++;
            $this->note($host_name.' skipped for VM base sync because no OS value was found.');
            return false;
        }

        $cpu_memory = $ctx->getCpuMemory();
        $platform_name = 'Generic';
        $os_value_lower = strtolower($os_value);

        if ($os_value !== '') {
            if (strpos($os_value_lower, 'windows') !== false || str_starts_with($vendor, 'windows')) {
                $platform_name = 'Windows';
            }
            elseif (strpos($os_value_lower, 'linux') !== false || in_array($vendor, $linux_vendors, true)) {
                $platform_name = 'Linux';
            }
        }

        $platform_id = null;
        if ($platform_name !== '') {
            try {
                $platform_id = $this->netbox->getPlatformIdByName($platform_name);
            }
            catch (Throwable $e) {
                $platform_id = null;
            }
        }

        $resolved = $this->findExistingVmByCandidate($ctx);
        $vcpus = (int) ($cpu_memory['vcpus'] ?? 0);
        $memory_mb = (int) ($cpu_memory['memory_mb'] ?? 0);
        $payload = [
            'platform' => $platform_id
        ];

        if ($vcpus > 0) {
            $payload['vcpus'] = $vcpus;
        }

        if ($memory_mb > 0) {
            $payload['memory'] = $memory_mb;
        }

        if ($resolved === null) {
            if (empty($vm_config['create_missing'])) {
                $this->summary['skipped']++;
                return false;
            }

            $vm_name = $this->preferredVmName($ctx);
            $create_payload = [
                'name' => $vm_name,
                'site' => (int) ($vm_config['default_site'] ?? 1),
                'status' => 'active',
                'platform' => $platform_id
            ];

            if ($vcpus > 0) {
                $create_payload['vcpus'] = $vcpus;
            }

            if ($memory_mb > 0) {
                $create_payload['memory'] = $memory_mb;
            }

            $vm = $this->netbox->createVm($create_payload);
            $ctx->setResolvedVm($vm);
            $this->summary['created']++;
            $this->note($ctx->hostName().' → created VM '.$vm_name.'.');
            $this->recordEvent(LogStore::TYPE_ADDED, [
                'sync_id' => 'vm_object',
                'target_type' => 'vm',
                'target_name' => $vm_name,
                'target_id' => (int) ($vm['id'] ?? 0),
                'new_value' => 'vcpus='.$vcpus.', memory='.$memory_mb
            ]);
            return true;
        }

        $ctx->setResolvedVm($resolved);

        if (empty($vm_config['update_existing'])) {
            $this->summary['unchanged']++;
            return false;
        }

        $changes = [];

        if ($vcpus > 0 && (int) ($resolved['vcpus'] ?? 0) !== $vcpus) {
            $changes['vcpus'] = $vcpus;
        }

        if ($memory_mb > 0 && (int) ($resolved['memory'] ?? 0) !== $memory_mb) {
            $changes['memory'] = $memory_mb;
        }

        $current_platform_id = (int) ($resolved['platform']['id'] ?? $resolved['platform'] ?? 0);
        $new_platform_id = (int) ($payload['platform'] ?? 0);

        if ($current_platform_id !== $new_platform_id) {
            $changes['platform'] = $payload['platform'];
        }

        if ($changes === []) {
            $this->summary['unchanged']++;
            return false;
        }

        $updated = $this->netbox->updateVm((int) $resolved['id'], $changes);
        $ctx->setResolvedVm($updated);
        $this->summary['updated']++;

        foreach ($changes as $field => $new_value) {
            $old_value = $field === 'platform'
                ? (int) ($resolved['platform']['id'] ?? $resolved['platform'] ?? 0)
                : ($resolved[$field] ?? '');
            $this->recordEvent(LogStore::TYPE_CHANGED, [
                'sync_id' => 'vm_object',
                'target_type' => 'vm',
                'target_name' => (string) ($updated['name'] ?? $resolved['name'] ?? ''),
                'target_id' => (int) ($updated['id'] ?? $resolved['id'] ?? 0),
                'field' => $field,
                'old_value' => (string) (is_array($old_value) ? json_encode($old_value) : $old_value),
                'new_value' => (string) (is_array($new_value) ? json_encode($new_value) : $new_value)
            ]);
        }

        return true;
    }

    private function syncVmOsEol(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $os_value = (string) ($ctx->getOsValue() ?? '');
        if ($os_value === '') {
            $this->summary['skipped']++;
            return false;
        }

        $os_eol = $ctx->getOsEol();
        $current_os = (string) Util::getPath($vm, 'custom_fields.operating_system', '');
        $current_eol = (string) Util::getPath($vm, 'custom_fields.operating_system_eol', '');

        if ($current_os === $os_value && $current_eol === $os_eol) {
            $this->summary['unchanged']++;
            return false;
        }

        $updated = $this->netbox->updateVm((int) $vm['id'], [
            'custom_fields' => [
                'operating_system' => $os_value,
                'operating_system_eol' => $os_eol
            ]
        ]);

        $ctx->setResolvedVm($updated);
        $this->summary['updated']++;

        if ($current_os !== $os_value) {
            $this->recordEvent(LogStore::TYPE_CHANGED, [
                'sync_id' => 'vm_os_eol',
                'target_type' => 'vm',
                'target_name' => (string) ($updated['name'] ?? $vm['name'] ?? ''),
                'target_id' => (int) ($updated['id'] ?? $vm['id'] ?? 0),
                'field' => 'custom_fields.operating_system',
                'old_value' => $current_os,
                'new_value' => $os_value
            ]);
        }

        if ($current_eol !== $os_eol) {
            $this->recordEvent(LogStore::TYPE_CHANGED, [
                'sync_id' => 'vm_os_eol',
                'target_type' => 'vm',
                'target_name' => (string) ($updated['name'] ?? $vm['name'] ?? ''),
                'target_id' => (int) ($updated['id'] ?? $vm['id'] ?? 0),
                'field' => 'custom_fields.operating_system_eol',
                'old_value' => $current_eol,
                'new_value' => $os_eol
            ]);
        }

        return true;
    }

    private function syncVmSqlLicense(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $license = $ctx->getSqlLicense();
        if ($license === null || $license === '') {
            $this->summary['skipped']++;
            return false;
        }

        $current = (string) Util::getPath($vm, 'custom_fields.microsoft_sql_server_license', '');
        if ($current === $license) {
            $this->summary['unchanged']++;
            return false;
        }

        $updated = $this->netbox->updateVm((int) $vm['id'], [
            'custom_fields' => [
                'microsoft_sql_server_license' => $license
            ]
        ]);

        $ctx->setResolvedVm($updated);
        $this->summary['updated']++;

        $this->recordEvent(LogStore::TYPE_CHANGED, [
            'sync_id' => 'vm_sql_license',
            'target_type' => 'vm',
            'target_name' => (string) ($updated['name'] ?? $vm['name'] ?? ''),
            'target_id' => (int) ($updated['id'] ?? $vm['id'] ?? 0),
            'field' => 'custom_fields.microsoft_sql_server_license',
            'old_value' => $current,
            'new_value' => $license
        ]);

        return true;
    }

    private function syncVmDisks(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $os_value = strtolower((string) ($ctx->getOsValue() ?? ''));
        $os_vendor = (string) ($ctx->getOsInfo()['vendor'] ?? '');
        $linux_vendors = ['ubuntu', 'rhel', 'redhat', 'oracle-linux', 'rocky-linux', 'sles', 'opensuse', 'alma', 'almalinux', 'debian', 'centos', 'centos-stream', 'fedora', 'alpine', 'amazon-linux', 'linux'];

        $os_supported = false;
        if (strpos($os_value, 'windows') !== false) {
            $disks = $ctx->getWindowsDisks();
            $os_supported = true;
        }
        elseif (strpos($os_value, 'linux') !== false || in_array($os_vendor, $linux_vendors, true)) {
            $disks = $ctx->getLinuxDisks();
            $os_supported = true;
        }
        else {
            $disks = [];
        }

        $prune = !empty($this->config['vm']['prune_disks']);

        if ($disks === [] && (!$prune || !$os_supported)) {
            $this->summary['skipped']++;
            return false;
        }

        $existing = [];
        foreach ($this->netbox->getVirtualDisks((int) $vm['id']) as $disk) {
            $existing[(string) ($disk['name'] ?? '')] = $disk;
        }

        $disk_unit = strtolower((string) ($this->config['vm']['disk_unit'] ?? 'mb'));
        $unit_label = $disk_unit === 'gb' ? 'GB' : 'MB';
        $changed = false;

        foreach ($disks as $name => $size_gb) {
            $name = (string) $name;
            $size_gb_int = max(0, (int) $size_gb);
            $size = $disk_unit === 'gb' ? $size_gb_int : $size_gb_int * 1000;

            if (!isset($existing[$name])) {
                $this->netbox->createVirtualDisk((int) $vm['id'], $name, $size);
                $this->summary['created']++;
                $changed = true;
                $this->recordEvent(LogStore::TYPE_ADDED, [
                    'sync_id' => 'vm_disks',
                    'target_type' => 'vm_disk',
                    'target_name' => (string) ($vm['name'] ?? '').' / '.$name,
                    'target_id' => (int) ($vm['id'] ?? 0),
                    'field' => 'size',
                    'disk_name' => $name,
                    'new_value' => $size.' '.$unit_label
                ]);
                continue;
            }

            $current_size = (int) ($existing[$name]['size'] ?? 0);
            if ($current_size !== $size) {
                $this->netbox->updateVirtualDisk((int) $existing[$name]['id'], $size);
                $this->summary['updated']++;
                $changed = true;
                $this->recordEvent(LogStore::TYPE_CHANGED, [
                    'sync_id' => 'vm_disks',
                    'target_type' => 'vm_disk',
                    'target_name' => (string) ($vm['name'] ?? '').' / '.$name,
                    'target_id' => (int) ($existing[$name]['id'] ?? 0),
                    'field' => 'size',
                    'disk_name' => $name,
                    'old_value' => $current_size.' '.$unit_label,
                    'new_value' => $size.' '.$unit_label
                ]);
            }

            unset($existing[$name]);
        }

        if ($prune) {
            foreach ($existing as $name => $disk) {
                $this->netbox->deleteVirtualDisk((int) $disk['id']);
                $this->summary['deleted']++;
                $changed = true;
                $this->recordEvent(LogStore::TYPE_REMOVED, [
                    'sync_id' => 'vm_disks',
                    'target_type' => 'vm_disk',
                    'target_name' => (string) ($vm['name'] ?? '').' / '.$name,
                    'target_id' => (int) ($disk['id'] ?? 0),
                    'disk_name' => (string) $name,
                    'old_value' => ((int) ($disk['size'] ?? 0)).' '.$unit_label
                ]);
            }
        }

        if (!$changed) {
            $this->summary['unchanged']++;
        }

        return $changed;
    }

    private function syncVmInterfaces(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $interface_names = $ctx->getInterfaceNames();
        $prune = !empty($this->config['vm']['prune_interfaces']);

        if ($interface_names === [] && !$prune) {
            $this->summary['skipped']++;
            return false;
        }

        $existing = [];
        foreach ($this->netbox->getVmInterfaces((int) $vm['id']) as $interface) {
            $existing[(string) ($interface['name'] ?? '')] = $interface;
        }

        $changed = false;

        foreach ($interface_names as $name) {
            if (isset($existing[$name])) {
                unset($existing[$name]);
                continue;
            }

            $created_interface = $this->netbox->createVmInterface((int) $vm['id'], $name);
            $this->summary['created']++;
            $changed = true;
            $this->recordEvent(LogStore::TYPE_ADDED, [
                'sync_id' => 'vm_interfaces',
                'target_type' => 'vm_interface',
                'target_name' => (string) ($vm['name'] ?? '').' / '.$name,
                'target_id' => (int) ($created_interface['id'] ?? 0),
                'field' => 'name',
                'new_value' => $name
            ]);
        }

        if ($prune) {
            foreach ($existing as $interface) {
                $this->netbox->deleteVmInterface((int) $interface['id']);
                $this->summary['deleted']++;
                $changed = true;
                $this->recordEvent(LogStore::TYPE_REMOVED, [
                    'sync_id' => 'vm_interfaces',
                    'target_type' => 'vm_interface',
                    'target_name' => (string) ($vm['name'] ?? '').' / '.(string) ($interface['name'] ?? ''),
                    'target_id' => (int) ($interface['id'] ?? 0),
                    'old_value' => (string) ($interface['name'] ?? '')
                ]);
            }
        }

        if (!$changed) {
            $this->summary['unchanged']++;
        }

        return $changed;
    }

    private function syncVmPrimaryIp(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $agent = $ctx->getAgentInterface();
        $ip_addr = trim((string) ($agent['ip'] ?? ''));

        if ($ip_addr === '') {
            $this->summary['skipped']++;
            return false;
        }

        $vm = $this->refreshVm($ctx);
        $interfaces = $this->netbox->getVmInterfaces((int) $vm['id']);

        if ($interfaces === []) {
            $this->summary['skipped']++;
            return false;
        }

        $os_type = (stripos((string) ($ctx->getOsValue() ?? ''), 'windows') !== false) ? 'windows' : 'linux';
        $selected = $this->selectCommonVmInterface($interfaces, $os_type);

        if ($selected === null) {
            $this->summary['skipped']++;
            return false;
        }

        $prefix_length = $this->getExistingPrefixLength($ip_addr);
        if ($prefix_length === null) {
            $prefix_length = max(1, min(32, (int) ($this->config['runner']['default_prefix_length'] ?? 24)));
            $this->ensurePrefixExists($ip_addr, $prefix_length);
        }

        $address = $ip_addr.'/'.$prefix_length;
        $ip_obj = $this->netbox->getIpByAddress($address);
        $changed = false;

        if ($ip_obj !== null) {
            $assigned_id = (int) ($ip_obj['assigned_object_id'] ?? 0);
            $assigned_type = (string) ($ip_obj['assigned_object_type'] ?? '');

            if (!($assigned_id === (int) $selected['id'] && $assigned_type === 'virtualization.vminterface')) {
                $this->summary['skipped']++;
                return false;
            }
        }
        else {
            $existing_ips = $this->netbox->getVmInterfaceIps((int) $selected['id']);
            if ($existing_ips !== []) {
                $this->summary['skipped']++;
                return false;
            }

            $ip_obj = $this->netbox->createVmInterfaceIp($address, (int) $selected['id']);
            $this->summary['created']++;
            $changed = true;
            $this->recordEvent(LogStore::TYPE_ADDED, [
                'sync_id' => 'vm_primary_ip',
                'target_type' => 'ip_address',
                'target_name' => $address,
                'target_id' => (int) ($ip_obj['id'] ?? 0),
                'field' => 'address',
                'new_value' => $address
            ]);
        }

        $current_primary = (int) ($vm['primary_ip4']['id'] ?? $vm['primary_ip4'] ?? 0);
        $new_primary = (int) ($ip_obj['id'] ?? 0);

        if ($new_primary > 0 && $current_primary !== $new_primary) {
            $vm = $this->netbox->setVmPrimaryIp4((int) $vm['id'], $new_primary);
            $ctx->setResolvedVm($vm);
            $this->summary['updated']++;
            $changed = true;
            $this->recordEvent(LogStore::TYPE_CHANGED, [
                'sync_id' => 'vm_primary_ip',
                'target_type' => 'vm',
                'target_name' => (string) ($vm['name'] ?? ''),
                'target_id' => (int) ($vm['id'] ?? 0),
                'field' => 'primary_ip4',
                'old_value' => (string) $current_primary,
                'new_value' => $address
            ]);
        }

        if (!$changed) {
            $this->summary['unchanged']++;
        }

        return $changed;
    }

    private function syncVmServices(HostContext $ctx): bool {
        $vm = $this->ensureVmResolved($ctx, false);

        if ($vm === null) {
            $this->summary['skipped']++;
            return false;
        }

        $entries = $this->getListeningServiceEntries($ctx);

        if ($entries === []) {
            $this->summary['skipped']++;
            return false;
        }

        $vm = $this->refreshVm($ctx);
        $ip_id = (int) ($vm['primary_ip4']['id'] ?? $vm['primary_ip4'] ?? 0);
        $existing = [];

        foreach ($this->netbox->listServicesForVm((int) $vm['id']) as $service) {
            foreach (($service['ports'] ?? []) as $port) {
                $existing[(int) $port] = $service;
            }
        }

        $reported = [];
        $changed = false;

        foreach ($entries as $entry) {
            $port = (int) ($entry['Port'] ?? 0);
            if ($port <= 0) {
                continue;
            }

            $reported[$port] = true;
            $service_name = $this->normalizeStringField($entry['ServiceName'] ?? null);
            $process = $this->normalizeStringField($entry['Process'] ?? null);
            $description = $this->normalizeStringField($entry['Description'] ?? null);

            if ($service_name !== '') {
                $name = $service_name;
            }
            elseif ($process !== '') {
                $name = $process;
                $description = $description !== '' ? $description : ($process.' listening on port '.$port);
            }
            else {
                continue;
            }

            if ($description === '') {
                $description = $name;
            }

            if (isset($existing[$port])) {
                $svc = $existing[$port];
                $cur_name = (string) ($svc['name'] ?? '');
                $cur_desc = (string) ($svc['description'] ?? '');

                if ($cur_name !== $name || $cur_desc !== $description) {
                    $this->netbox->updateService((int) $svc['id'], $name, $description);
                    $this->summary['updated']++;
                    $changed = true;
                    $this->recordEvent(LogStore::TYPE_CHANGED, [
                        'sync_id' => 'vm_services',
                        'target_type' => 'service',
                        'target_name' => (string) ($vm['name'] ?? '').' :'.$port,
                        'target_id' => (int) ($svc['id'] ?? 0),
                        'field' => $cur_name !== $name ? 'name' : 'description',
                        'old_value' => $cur_name !== $name ? $cur_name : $cur_desc,
                        'new_value' => $cur_name !== $name ? $name : $description
                    ]);
                }
                unset($existing[$port]);
                continue;
            }

            $created = $this->netbox->createService((int) $vm['id'], $ip_id > 0 ? $ip_id : null, $port, $name, $description);
            $this->summary['created']++;
            $changed = true;
            $this->recordEvent(LogStore::TYPE_ADDED, [
                'sync_id' => 'vm_services',
                'target_type' => 'service',
                'target_name' => (string) ($vm['name'] ?? '').' :'.$port,
                'target_id' => (int) ($created['id'] ?? 0),
                'field' => 'name',
                'new_value' => $name.' (tcp/'.$port.')'
            ]);
        }

        if (!empty($this->config['services']['prune_stale'])) {
            foreach ($existing as $port => $service) {
                if (!isset($reported[(int) $port])) {
                    $this->netbox->deleteService((int) $service['id']);
                    $this->summary['deleted']++;
                    $changed = true;
                    $this->recordEvent(LogStore::TYPE_REMOVED, [
                        'sync_id' => 'vm_services',
                        'target_type' => 'service',
                        'target_name' => (string) ($vm['name'] ?? '').' :'.$port,
                        'target_id' => (int) ($service['id'] ?? 0),
                        'old_value' => (string) ($service['name'] ?? '').' (tcp/'.$port.')'
                    ]);
                }
            }
        }

        if (!$changed) {
            $this->summary['unchanged']++;
        }

        return $changed;
    }

    private function syncDeviceObject(HostContext $ctx): bool {
        $device = $this->ensureDeviceResolved($ctx, true);

        if ($device === null) {
            $this->summary['skipped']++;
            return false;
        }

        return !empty($ctx->vars()['device_last_changed'] ?? false);
    }

    private function syncDeviceSerial(HostContext $ctx): bool {
        $device = $this->ensureDeviceResolved($ctx, false);

        if ($device === null) {
            $this->summary['skipped']++;
            return false;
        }

        $value = $ctx->resolveSourceValue(
            (string) ($this->config['device']['serial_source_mode'] ?? 'item_key'),
            (string) ($this->config['device']['serial_source_value'] ?? '')
        );

        $value = $this->stringOrNull($value);

        if ($value === null || $value === '') {
            $this->summary['skipped']++;
            return false;
        }

        $current = trim((string) ($device['serial'] ?? ''));
        if ($current === $value) {
            $this->summary['unchanged']++;
            return false;
        }

        $updated = $this->netbox->updateDevice((int) $device['id'], [
            'serial' => $value
        ]);

        $ctx->setResolvedDevice($updated);
        $this->summary['updated']++;
        $this->recordEvent(LogStore::TYPE_CHANGED, [
            'sync_id' => 'device_serial',
            'target_type' => 'device',
            'target_name' => (string) ($updated['name'] ?? $device['name'] ?? ''),
            'target_id' => (int) ($updated['id'] ?? $device['id'] ?? 0),
            'field' => 'serial',
            'old_value' => $current,
            'new_value' => $value
        ]);
        return true;
    }

    private function ensureVmResolved(HostContext $ctx, bool $allow_create = true): ?array {
        $vm = $ctx->getResolvedVm();
        if ($vm !== null) {
            return $vm;
        }

        $vm = $this->findExistingVmByCandidate($ctx);
        if ($vm !== null) {
            $ctx->setResolvedVm($vm);
            return $vm;
        }

        if ($allow_create && !empty($this->config['vm']['enabled']) && !empty($this->config['vm']['create_missing'])) {
            $this->syncVmObject($ctx);
            return $ctx->getResolvedVm();
        }

        return null;
    }

    private function ensureDeviceResolved(HostContext $ctx, bool $allow_create = true): ?array {
        $device_config = $this->config['device'] ?? [];
        $existing = $ctx->getResolvedDevice();
        if ($existing !== null) {
            return $existing;
        }

        $name = $this->resolveNamedValue($ctx,
            (string) ($device_config['name_source_mode'] ?? 'host_name'),
            (string) ($device_config['name_source_value'] ?? '')
        );

        if ($name === '') {
            return null;
        }

        $ctx->setVar('device_name_requested', $name);

        $device = $this->netbox->findDeviceByName($name);
        $manufacturer_name = $this->resolveNamedValue($ctx,
            (string) ($device_config['manufacturer_source_mode'] ?? 'static'),
            (string) ($device_config['manufacturer_source_value'] ?? '')
        );

        $raw_model = $this->resolveNamedValue($ctx,
            (string) ($device_config['model_source_mode'] ?? 'item_key'),
            (string) ($device_config['model_source_value'] ?? '')
        );

        $device_type = null;
        if ($manufacturer_name !== '' && $raw_model !== '') {
            $device_type = $this->ensureDeviceTypeFlexible(
                $manufacturer_name,
                $raw_model,
                !empty($device_config['create_missing_manufacturer']),
                !empty($device_config['create_missing_device_type'])
            );
        }

        $ctx->setVar('device_manufacturer', $manufacturer_name);
        $ctx->setVar('device_model', $raw_model);
        if (is_array($device_type)) {
            $ctx->setVar('device_type_id', (string) ($device_type['id'] ?? ''));
            $ctx->setVar('device_type_model', (string) ($device_type['model'] ?? ''));
        }

        if ($device === null) {
            if (!$allow_create || empty($device_config['create_missing'])) {
                return null;
            }

            if ($device_type === null) {
                $this->note($name.' skipped for device creation because no device type could be resolved.');
                return null;
            }

            $payload = [
                'name' => $name,
                'site' => (int) ($device_config['default_site'] ?? 1),
                'role' => (int) ($device_config['default_role_id'] ?? 1),
                'status' => (string) ($device_config['default_status'] ?? 'active'),
                'device_type' => (int) $device_type['id']
            ];

            $device = $this->netbox->createDevice($payload);
            $ctx->setResolvedDevice($device);
            $ctx->setVar('device_last_changed', '1');
            $this->summary['created']++;
            $this->recordEvent(LogStore::TYPE_ADDED, [
                'sync_id' => 'device_object',
                'target_type' => 'device',
                'target_name' => (string) ($device['name'] ?? $name),
                'target_id' => (int) ($device['id'] ?? 0),
                'field' => 'name',
                'new_value' => (string) ($device['name'] ?? $name)
            ]);
            return $device;
        }

        $ctx->setResolvedDevice($device);

        if (empty($device_config['update_existing'])) {
            return $device;
        }

        $changes = [];
        $role_id = (int) ($device['role']['id'] ?? $device['role'] ?? 0);
        $site_id = (int) ($device['site']['id'] ?? $device['site'] ?? 0);
        $status = (string) ($device['status']['value'] ?? $device['status'] ?? '');

        $new_site = (int) ($device_config['default_site'] ?? 1);
        $new_role = (int) ($device_config['default_role_id'] ?? 1);
        $new_status = (string) ($device_config['default_status'] ?? 'active');
        $current_device_type_id = (int) ($device['device_type']['id'] ?? $device['device_type'] ?? 0);
        $new_device_type_id = (int) ($device_type['id'] ?? 0);

        if ($site_id !== $new_site) {
            $changes['site'] = $new_site;
        }

        if ($role_id !== $new_role) {
            $changes['role'] = $new_role;
        }

        if ($status !== $new_status) {
            $changes['status'] = $new_status;
        }

        if ($new_device_type_id > 0 && $current_device_type_id !== $new_device_type_id) {
            $changes['device_type'] = $new_device_type_id;
        }

        if ($changes === []) {
            return $device;
        }

        $snapshot = $device;
        $device = $this->netbox->updateDevice((int) $device['id'], $changes);
        $ctx->setResolvedDevice($device);
        $ctx->setVar('device_last_changed', '1');
        $this->summary['updated']++;

        foreach ($changes as $field => $new_value) {
            $old_value = '';
            if ($field === 'device_type') {
                $old_value = (string) (int) ($snapshot['device_type']['id'] ?? $snapshot['device_type'] ?? 0);
            }
            elseif ($field === 'status') {
                $old_value = (string) ($snapshot['status']['value'] ?? $snapshot['status'] ?? '');
            }
            else {
                $raw = $snapshot[$field] ?? '';
                $old_value = is_array($raw) ? (string) ($raw['id'] ?? json_encode($raw)) : (string) $raw;
            }

            $this->recordEvent(LogStore::TYPE_CHANGED, [
                'sync_id' => 'device_object',
                'target_type' => 'device',
                'target_name' => (string) ($device['name'] ?? ''),
                'target_id' => (int) ($device['id'] ?? 0),
                'field' => $field,
                'old_value' => $old_value,
                'new_value' => (string) (is_array($new_value) ? json_encode($new_value) : $new_value)
            ]);
        }

        return $device;
    }

    private function applyCustomMapping(HostContext $ctx, array $mapping): bool {
        $mapping_id = Util::cleanId((string) ($mapping['id'] ?? ''), 'map');
        $host_regex = trim((string) ($mapping['host_name_regex'] ?? ''));

        if ($host_regex !== '') {
            $ok = @preg_match($host_regex, $ctx->hostName());
            if ($ok !== 1) {
                $this->summary['skipped']++;
                return false;
            }
        }

        $source_value = $this->resolveMappingSourceValue($ctx, $mapping);
        $target_object = (string) ($mapping['target_object'] ?? 'vm');
        $mode = (string) ($mapping['mode'] ?? 'field_patch');
        $target_field = trim((string) ($mapping['target_field'] ?? ''));
        $on_empty = (string) ($mapping['on_empty'] ?? 'skip');

        if (($source_value === null || $source_value === '') && $mode !== 'ensure_device_type') {
            if ($on_empty === 'skip') {
                $this->summary['skipped']++;
                return false;
            }
        }

        [$current_object, $target_url, $context_type] = $this->resolveMappingTarget($ctx, $mapping);

        if ($target_url === '') {
            throw new RuntimeException('Target URL could not be resolved.');
        }

        $patch_value = null;

        if ($mode === 'field_patch') {
            $patch_value = $source_value;
            if (($patch_value === null || $patch_value === '') && $on_empty === 'clear') {
                $patch_value = null;
            }
        }
        elseif ($mode === 'relation_lookup') {
            if (($source_value === null || $source_value === '') && $on_empty === 'clear') {
                $patch_value = null;
            }
            elseif ($source_value === null || $source_value === '') {
                $this->summary['skipped']++;
                return false;
            }
            else {
                $patch_value = $this->resolveRelationId($ctx, $mapping, (string) $source_value);
                if ($patch_value === null && $on_empty !== 'clear') {
                    throw new RuntimeException('Relation lookup returned no matching NetBox object.');
                }
            }
        }
        elseif ($mode === 'ensure_device_type') {
            $manufacturer = $this->resolveNamedValue($ctx,
                (string) ($mapping['relation_manufacturer_source_mode'] ?? 'static'),
                (string) ($mapping['relation_manufacturer_source_value'] ?? '')
            );

            if ($manufacturer === '') {
                throw new RuntimeException('Manufacturer is required for ensure_device_type mappings.');
            }

            $model = (string) ($source_value ?? '');
            if ($model === '') {
                if ($on_empty === 'clear') {
                    $patch_value = null;
                }
                else {
                    $this->summary['skipped']++;
                    return false;
                }
            }
            else {
                $device_type = $this->ensureDeviceTypeFlexible(
                    $manufacturer,
                    $model,
                    true,
                    true
                );

                if ($device_type === null) {
                    throw new RuntimeException('Unable to resolve or create the target device type.');
                }

                $patch_value = (int) $device_type['id'];
                if ($target_field === '') {
                    $target_field = 'device_type';
                }
            }
        }
        else {
            throw new RuntimeException('Unsupported custom mapping mode: '.$mode);
        }

        if ($target_field === '') {
            throw new RuntimeException('Target field is empty.');
        }

        if (is_array($current_object)) {
            $current_value = Util::getPath($current_object, $target_field, null);
            if ($this->valuesEquivalent($current_value, $patch_value)) {
                $this->summary['unchanged']++;
                return false;
            }
        }

        $payload = [];
        Util::setPath($payload, $target_field, $patch_value);

        $result = $this->netbox->patchObjectByUrl($target_url, $payload);

        if ($context_type === 'vm' && is_array($result)) {
            $ctx->setResolvedVm($result);
        }
        elseif ($context_type === 'device' && is_array($result)) {
            $ctx->setResolvedDevice($result);
        }

        $this->summary['updated']++;

        $old_value = '';
        if (is_array($current_object)) {
            $raw = Util::getPath($current_object, $target_field, '');
            $old_value = is_array($raw) ? (string) ($raw['id'] ?? json_encode($raw)) : (string) $raw;
        }

        $this->recordEvent(LogStore::TYPE_CHANGED, [
            'sync_id' => 'custom:'.($mapping['id'] ?? ''),
            'target_type' => $context_type,
            'target_name' => (string) (is_array($result) ? ($result['name'] ?? $result['display'] ?? '') : ''),
            'target_id' => is_array($result) ? (int) ($result['id'] ?? 0) : 0,
            'field' => $target_field,
            'old_value' => $old_value,
            'new_value' => (string) (is_array($patch_value) ? json_encode($patch_value) : ($patch_value ?? ''))
        ]);

        return true;
    }

    private function resolveRelationId(HostContext $ctx, array $mapping, string $source_value): ?int {
        $endpoint = trim((string) ($mapping['lookup_endpoint'] ?? ''));
        $query_field = trim((string) ($mapping['lookup_query_field'] ?? 'name'));
        $ensure_mode = (string) ($mapping['ensure_missing_mode'] ?? 'none');

        if ($endpoint === '' && $ensure_mode === 'none') {
            throw new RuntimeException('Relation lookup mappings require a lookup endpoint.');
        }

        if ($ensure_mode === 'manufacturer') {
            $manufacturer = $this->netbox->ensureManufacturer($source_value, true);
            return is_array($manufacturer) ? (int) ($manufacturer['id'] ?? 0) : null;
        }

        if ($ensure_mode === 'device_type') {
            $manufacturer = $this->resolveNamedValue($ctx,
                (string) ($mapping['relation_manufacturer_source_mode'] ?? 'static'),
                (string) ($mapping['relation_manufacturer_source_value'] ?? '')
            );

            if ($manufacturer === '') {
                throw new RuntimeException('Manufacturer source is required for device type mappings.');
            }

            $device_type = $this->ensureDeviceTypeFlexible($manufacturer, $source_value, true, true);

            return is_array($device_type) ? (int) ($device_type['id'] ?? 0) : null;
        }

        $id = $this->netbox->lookupRelationId($endpoint, $query_field, $source_value);

        return $id !== null ? (int) $id : null;
    }

    private function resolveMappingTarget(HostContext $ctx, array $mapping): array {
        $target_object = (string) ($mapping['target_object'] ?? 'vm');
        $target_url_template = trim((string) ($mapping['target_url_template'] ?? ''));

        if ($target_object === 'vm') {
            $vm = $this->ensureVmResolved($ctx, true);
            if ($vm === null) {
                throw new RuntimeException('VM target could not be resolved.');
            }
            return [$vm, (string) ($vm['url'] ?? ''), 'vm'];
        }

        if ($target_object === 'device') {
            $device = $this->ensureDeviceResolved($ctx, true);
            if ($device === null) {
                throw new RuntimeException('Device target could not be resolved.');
            }
            return [$device, (string) ($device['url'] ?? ''), 'device'];
        }

        $url = Util::interpolate($target_url_template, $ctx->vars());

        return [null, $url, 'custom_url'];
    }

    private function resolveMappingSourceValue(HostContext $ctx, array $mapping) {
        $source_mode = (string) ($mapping['source_mode'] ?? 'item_key');
        $source_value = (string) ($mapping['source_value'] ?? '');
        $json_path = trim((string) ($mapping['source_json_path'] ?? ''));
        $transform = (string) ($mapping['transform'] ?? 'none');

        $raw = $ctx->resolveSourceValue($source_mode, $source_value);

        if ($raw === null) {
            return null;
        }

        $value = $raw;

        if ($json_path !== '') {
            $decoded = Util::decodeJson($raw, null);
            if (!is_array($decoded)) {
                return null;
            }
            $value = Util::getPath($decoded, $json_path, null);
        }

        return $this->applyTransform($value, $transform);
    }

    private function applyTransform($value, string $transform) {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $string = trim((string) $value);

        switch ($transform) {
            case 'trim':
                return $string;

            case 'int':
                return is_numeric($string) ? (int) $string : null;

            case 'float':
                return is_numeric($string) ? (float) $string : null;

            case 'lower':
                return strtolower($string);

            case 'upper':
                return strtoupper($string);

            case 'slug':
                return Util::slugify($string);

            case 'none':
            default:
                return $string;
        }
    }

    private function ensureBuiltInCustomFields(): void {
        $needs_vm_custom_fields = $this->isStandardSyncEnabled('vm_os_eol') || $this->isStandardSyncEnabled('vm_sql_license');

        if (!$needs_vm_custom_fields) {
            return;
        }

        $fields = [
            [
                'name' => 'operating_system',
                'label' => 'Operating System',
                'type' => 'text',
                'filter_logic' => 'loose',
                'weight' => 1000,
                'display_weight' => 100,
                'search_weight' => 100,
                'description' => '',
                'ui_visible' => 'always'
            ],
            [
                'name' => 'operating_system_eol',
                'label' => 'Operating System End of Life',
                'type' => 'text',
                'filter_logic' => 'exact',
                'weight' => 1001,
                'display_weight' => 101,
                'search_weight' => 101,
                'description' => 'End of life date for the operating system',
                'ui_visible' => 'always'
            ],
            [
                'name' => 'microsoft_sql_server_license',
                'label' => 'Microsoft SQL Server License',
                'type' => 'text',
                'filter_logic' => 'loose',
                'weight' => 1002,
                'display_weight' => 102,
                'search_weight' => 102,
                'description' => 'Records SQL Server license information',
                'ui_visible' => 'always'
            ]
        ];

        foreach ($fields as $field) {
            $existing = $this->netbox->findCustomFieldByName($field['name']);
            if ($existing !== null) {
                continue;
            }

            $payload = [
                'object_types' => ['virtualization.virtualmachine'],
                'content_types' => ['virtualization.virtualmachine'],
                'name' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => false,
                'filter_logic' => $field['filter_logic'],
                'weight' => $field['weight'],
                'description' => $field['description'],
                'ui_visible' => $field['ui_visible'],
                'display_weight' => $field['display_weight'],
                'search_weight' => $field['search_weight'],
                'show_in_table' => true,
                'use_in_export' => true,
                'is_cloneable' => false,
                'default' => null
            ];

            $created = $this->netbox->createCustomField($payload);

            $this->events->record([
                'type' => LogStore::TYPE_ADDED,
                'sync_id' => 'netbox_bootstrap',
                'target_type' => 'custom_field',
                'target_name' => (string) ($field['name'] ?? ''),
                'target_id' => (int) ($created['id'] ?? 0),
                'field' => 'name',
                'new_value' => (string) ($field['name'] ?? '')
            ]);
        }
    }

    private function findExistingVmByCandidate(HostContext $ctx): ?array {
        foreach ($ctx->resolveVmCandidateNames() as $candidate) {
            $vm = $this->netbox->findVmByName($candidate);
            if ($vm !== null) {
                $ctx->setVar('vm_name_requested', $candidate);
                return $vm;
            }
        }

        return null;
    }

    private function preferredVmName(HostContext $ctx): string {
        $candidates = $ctx->resolveVmCandidateNames();

        return $candidates[0] ?? $ctx->hostName();
    }

    private function refreshVm(HostContext $ctx): array {
        $vm = $ctx->getResolvedVm();

        if ($vm === null) {
            throw new RuntimeException('VM is not resolved.');
        }

        $id = (int) ($vm['id'] ?? 0);
        if ($id <= 0) {
            return $vm;
        }

        $fresh = $this->netbox->getVm($id);
        if ($fresh !== null) {
            $ctx->setResolvedVm($fresh);
            return $fresh;
        }

        return $vm;
    }

    private function getListeningServiceEntries(HostContext $ctx): array {
        $names = [
            (string) ($this->config['services']['windows_item_name'] ?? 'Listening Services JSON'),
            (string) ($this->config['services']['linux_item_name'] ?? 'Linux Listening Services JSON')
        ];

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            $item = $ctx->getItemByName($name);
            if (!is_array($item)) {
                continue;
            }

            $decoded = Util::decodeJson((string) ($item['lastvalue'] ?? ''), null);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function selectCommonVmInterface(array $interfaces, string $os_type = 'linux'): ?array {
        $common_names = $os_type === 'windows'
            ? ['Ethernet', 'Local Area Connection', 'ens160', 'eth0', 'Gigabit Network Connection']
            : ['eth0', 'ens18', 'ens160', 'enp0s3', 'enp1s0', 'eno1', 'ens192', 'ens33'];

        foreach ($common_names as $common_name) {
            foreach ($interfaces as $interface) {
                $name = (string) ($interface['name'] ?? '');
                if ($name !== '' && stripos($name, $common_name) !== false) {
                    return $interface;
                }
            }
        }

        return null;
    }

    private function getExistingPrefixLength(string $ip_addr): ?int {
        $prefixes = $this->netbox->getPrefixesByQuery($ip_addr);

        foreach ($prefixes as $prefix) {
            $prefix_str = (string) ($prefix['prefix'] ?? '');
            if ($prefix_str === '') {
                continue;
            }

            if ($this->ipInPrefix($ip_addr, $prefix_str)) {
                $parts = explode('/', $prefix_str);
                return isset($parts[1]) ? (int) $parts[1] : null;
            }
        }

        return null;
    }

    private function ensurePrefixExists(string $ip_addr, int $prefix_length): void {
        $prefixes = $this->netbox->getPrefixesByQuery($ip_addr);

        foreach ($prefixes as $prefix) {
            $prefix_str = (string) ($prefix['prefix'] ?? '');
            if ($prefix_str !== '' && $this->ipInPrefix($ip_addr, $prefix_str)) {
                return;
            }
        }

        $network = $this->networkFromIpAndPrefix($ip_addr, $prefix_length);
        if ($network === null) {
            throw new RuntimeException('Unable to derive network prefix for '.$ip_addr.'/'.$prefix_length);
        }

        $created = $this->netbox->createPrefix($network);
        $this->summary['created']++;
        $this->recordEvent(LogStore::TYPE_ADDED, [
            'sync_id' => 'vm_primary_ip',
            'target_type' => 'prefix',
            'target_name' => $network,
            'target_id' => (int) ($created['id'] ?? 0),
            'field' => 'prefix',
            'new_value' => $network
        ]);
    }

    private function ipInPrefix(string $ip, string $prefix): bool {
        if (strpos($ip, '.') === false) {
            return false;
        }

        [$network_ip, $prefix_length] = array_pad(explode('/', $prefix, 2), 2, null);
        if ($network_ip === null || $prefix_length === null || strpos($network_ip, '.') === false) {
            return false;
        }

        $mask_bits = max(0, min(32, (int) $prefix_length));
        $ip_long = ip2long($ip);
        $network_long = ip2long($network_ip);

        if ($ip_long === false || $network_long === false) {
            return false;
        }

        if ($mask_bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $mask_bits);

        return (($ip_long & $mask) === ($network_long & $mask));
    }

    private function networkFromIpAndPrefix(string $ip, int $prefix_length): ?string {
        if (strpos($ip, '.') === false) {
            return null;
        }

        $ip_long = ip2long($ip);
        if ($ip_long === false) {
            return null;
        }

        $mask_bits = max(0, min(32, $prefix_length));
        $mask = $mask_bits === 0 ? 0 : (-1 << (32 - $mask_bits));
        $network_long = $ip_long & $mask;

        return long2ip($network_long).'/'.$mask_bits;
    }

    private function normalizeStringField($value): string {
        if (is_string($value)) {
            return trim($value);
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function recordEvent(string $type, array $event): void {
        $event['type'] = $type;

        if ($this->current_host !== null) {
            $event['host'] = $event['host'] ?? $this->current_host->hostName();
            $event['hostid'] = $event['hostid'] ?? $this->current_host->hostId();
            $os = $this->current_host->getOsValue();
            if ($os !== null && $os !== '') {
                $event['os'] = $event['os'] ?? $os;
            }
        }

        $this->events->record($event);
    }

    private function note(string $message, string $level = 'info'): void {
        $this->summary['messages'][] = [
            'time' => gmdate('H:i:s'),
            'level' => $level,
            'message' => Util::truncate($message, 1000)
        ];

        if (count($this->summary['messages']) > 200) {
            $this->summary['messages'] = array_slice($this->summary['messages'], -200);
        }

        $this->log($level, $message);
    }

    private function log(string $level, string $message, array $context = []): void {
        $this->store->log($level, $message, $context);
    }

    private function valuesEquivalent($current, $new): bool {
        if ($current === $new) {
            return true;
        }

        if (is_array($current) && array_key_exists('id', $current) && is_scalar($new)) {
            return (string) $current['id'] === (string) $new;
        }

        if (is_scalar($current) && is_array($new) && array_key_exists('id', $new)) {
            return (string) $current === (string) $new['id'];
        }

        if ($current === null && $new === '') {
            return true;
        }

        if ($current === '' && $new === null) {
            return true;
        }

        return is_scalar($current) && is_scalar($new) && (string) $current === (string) $new;
    }

    private function resolveNamedValue(HostContext $ctx, string $mode, string $value): string {
        $resolved = $ctx->resolveSourceValue($mode, $value);
        return trim((string) ($resolved ?? ''));
    }

    private function stringOrNull($value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function ensureDeviceTypeFlexible(
        string $manufacturer_name,
        string $raw_model,
        bool $allow_manufacturer_create = true,
        bool $allow_device_type_create = true
    ): ?array {
        $manufacturer_name = trim($manufacturer_name);
        $raw_model = trim($raw_model);

        if ($manufacturer_name === '' || $raw_model === '') {
            return null;
        }

        $manufacturer = $this->netbox->ensureManufacturer($manufacturer_name, $allow_manufacturer_create);
        if (!is_array($manufacturer) || empty($manufacturer['id'])) {
            return null;
        }

        $manufacturer_id = (int) $manufacturer['id'];
        $candidates = $this->deviceTypeCandidates($raw_model);

        foreach ($candidates as $candidate) {
            $found = $this->netbox->findDeviceType($manufacturer_id, $candidate);
            if ($found !== null) {
                return $found;
            }
        }

        $all = $this->netbox->listResults('/dcim/device-types/', ['manufacturer_id' => $manufacturer_id]);
        $normalized_candidates = [];
        foreach ($candidates as $candidate) {
            $normalized_candidates[$this->normalizeComparableModel($candidate)] = true;
        }

        foreach ($all as $device_type) {
            $existing_values = [
                (string) ($device_type['model'] ?? ''),
                (string) ($device_type['display'] ?? ''),
                (string) ($device_type['slug'] ?? '')
            ];

            foreach ($existing_values as $existing_value) {
                if ($existing_value === '') {
                    continue;
                }

                if (isset($normalized_candidates[$this->normalizeComparableModel($existing_value)])) {
                    return $device_type;
                }
            }
        }

        if (!$allow_device_type_create) {
            return null;
        }

        $create_model = $this->formatModelForCreate($candidates[0] ?? $raw_model);

        return $this->netbox->createDeviceType($manufacturer_id, $create_model);
    }

    private function deviceTypeCandidates(string $raw_model): array {
        $raw_model = trim($raw_model);
        $candidates = [];

        if ($raw_model !== '') {
            $candidates[] = $raw_model;
        }

        if (preg_match_all('/\(([^)]+)\)/', $raw_model, $matches)) {
            foreach (($matches[1] ?? []) as $match) {
                $match = trim((string) $match);
                if ($match !== '') {
                    $candidates[] = $match;
                }
            }
        }

        $cleaned = preg_replace('/\s+/', ' ', preg_replace('/\s*\([^)]*\)\s*/', ' ', $raw_model) ?? '');
        $cleaned = trim((string) $cleaned);
        if ($cleaned !== '') {
            $candidates[] = $cleaned;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function normalizeComparableModel(string $value): string {
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
    }

    private function formatModelForCreate(string $value): string {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        if (preg_match('/^[A-Z]{1,5}\d[A-Z0-9-]*$/', $value) && !str_contains($value, '-')) {
            if (preg_match('/^([A-Z]{1,5})(\d[A-Z0-9-]*)$/', $value, $m)) {
                return $m[1].'-'.$m[2];
            }
        }

        return $value;
    }
}
