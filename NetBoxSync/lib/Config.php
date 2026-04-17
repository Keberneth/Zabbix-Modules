<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use API,
    DB,
    RuntimeException,
    Throwable;

class Config {

    public const MODULE_ID = 'custom_netbox_sync';

    public static function defaults(): array {
        return [
    'netbox' => [
        'enabled' => false,
        'url' => '',
        'token' => '',
        'token_env' => '',
        'verify_peer' => true,
        'timeout' => 15
    ],
    'runner' => [
        'enabled' => true,
        'shared_secret' => '',
        'shared_secret_env' => '',
        'state_path' => '/var/lib/zabbix-netbox-sync/state',
        'log_path' => '/var/log/zabbix-netbox-sync',
        'global_interval_seconds' => 3600,
        'lock_ttl_seconds' => 1800,
        'default_prefix_length' => 24,
        'max_hosts_per_run' => 0
    ],
    'vm' => [
        'enabled' => true,
        'create_missing' => true,
        'update_existing' => true,
        'require_os_for_create' => true,
        'default_site' => 1,
        'name_suffix' => '',
        'name_suffix_separator' => '.',
        'os_pretty_name_item_name' => 'OSI PRETTY_NAME',
        'os_fallback_item_key' => 'system.sw.os',
        'linux_cpu_item_key' => 'system.cpu.num',
        'windows_cpu_item_key' => 'wmi.get[root/cimv2,"Select NumberOfLogicalProcessors from Win32_ComputerSystem"]',
        'memory_item_key' => 'vm.memory.size[total]',
        'sql_version_item_key' => 'mssql.version',
        'windows_disk_search' => 'vfs.fs.dependent.size',
        'linux_disk_search' => 'vfs.file.contents[/sys/block/',
        'interface_search' => 'net.if.out[',
        'memory_unit' => 'mb',
        'disk_unit' => 'mb',
        'prune_disks' => true,
        'prune_interfaces' => true
    ],
    'services' => [
        'enabled' => false,
        'prune_stale' => false,
        'windows_item_name' => 'Listening Services JSON',
        'linux_item_name' => 'Linux Listening Services JSON'
    ],
    'device' => [
        'enabled' => false,
        'create_missing' => true,
        'update_existing' => true,
        'default_site' => 1,
        'default_role_id' => 1,
        'default_status' => 'active',
        'name_source_mode' => 'host_name',
        'name_source_value' => '',
        'manufacturer_source_mode' => 'static',
        'manufacturer_source_value' => '',
        'model_source_mode' => 'item_key',
        'model_source_value' => 'fgate.device.model',
        'serial_source_mode' => 'item_key',
        'serial_source_value' => 'fgate.device.serialnumber',
        'create_missing_manufacturer' => true,
        'create_missing_device_type' => true
    ],
    'standard_syncs' => [
        [
            'id' => 'vm_object',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_os_eol',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_sql_license',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_disks',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_interfaces',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_primary_ip',
            'enabled' => true,
            'interval_seconds' => 0
        ],
        [
            'id' => 'vm_services',
            'enabled' => false,
            'interval_seconds' => 0
        ],
        [
            'id' => 'device_object',
            'enabled' => false,
            'interval_seconds' => 0
        ],
        [
            'id' => 'device_serial',
            'enabled' => false,
            'interval_seconds' => 0
        ]
    ],
    'custom_mappings' => []
];
    }

    public static function standardSyncCatalog(): array {
        return [
    [
        'id' => 'vm_object',
        'title' => 'VM base object',
        'source' => 'Host name, OS detection, CPU item, memory item',
        'target' => 'virtualization.virtualmachine → name, site, platform, vcpus, memory',
        'notes' => 'Creates or updates a NetBox VM using the same base logic as the uploaded VM sync script.'
    ],
    [
        'id' => 'vm_os_eol',
        'title' => 'VM operating system and EOL',
        'source' => 'OSI PRETTY_NAME or system.sw.os, plus endoflife.date lookup',
        'target' => 'virtualization.virtualmachine.custom_fields.operating_system / operating_system_eol',
        'notes' => 'Ensures the two VM custom fields used by the current script are kept in sync.'
    ],
    [
        'id' => 'vm_sql_license',
        'title' => 'VM SQL Server license',
        'source' => 'mssql.version',
        'target' => 'virtualization.virtualmachine.custom_fields.microsoft_sql_server_license',
        'notes' => 'Maps detected SQL Server edition text to the NetBox custom field used by the current script.'
    ],
    [
        'id' => 'vm_disks',
        'title' => 'VM virtual disks',
        'source' => 'Windows: vfs.fs.dependent.size[*]; Linux: vfs.file.contents[/sys/block/*/size]',
        'target' => 'virtualization.virtual-disks',
        'notes' => 'Creates, updates, and removes NetBox virtual disks based on the current script patterns.'
    ],
    [
        'id' => 'vm_interfaces',
        'title' => 'VM interfaces',
        'source' => 'net.if.out[*] items tagged with interface',
        'target' => 'virtualization.interfaces',
        'notes' => 'Creates and removes VM interfaces based on discovered interface tag values.'
    ],
    [
        'id' => 'vm_primary_ip',
        'title' => 'VM primary IP',
        'source' => 'Main Zabbix agent interface IP',
        'target' => 'ipam.ip-addresses + virtualization.virtualmachine.primary_ip4',
        'notes' => 'Creates a prefix if needed, assigns the IP to a common VM interface, then sets the VM primary IPv4 field.'
    ],
    [
        'id' => 'vm_services',
        'title' => 'Listening services',
        'source' => 'Listening Services JSON / Linux Listening Services JSON',
        'target' => 'ipam.services',
        'notes' => 'Requires the Linux or Windows listening-service plugin/template. Creates and updates TCP services on the resolved VM.'
    ],
    [
        'id' => 'device_object',
        'title' => 'Device base object',
        'source' => 'Configured device name, manufacturer, and model sources',
        'target' => 'dcim.device → name, site, role, status, device_type',
        'notes' => 'Optionally creates manufacturers and device types when they do not exist, then creates or updates the device.'
    ],
    [
        'id' => 'device_serial',
        'title' => 'Device serial',
        'source' => 'Configured device serial source',
        'target' => 'dcim.device.serial',
        'notes' => 'Patches the NetBox device serial field after the device has been resolved.'
    ]
];
    }

    public static function getModuleRecord(): ?array {
        $result = DBselect(
            'SELECT moduleid,id,relative_path,status,config'
            .' FROM module'
            .' WHERE id='.zbx_dbstr(self::MODULE_ID)
        );

        $row = DBfetch($result);

        if (!$row) {
            return null;
        }

        $row['config'] = self::mergeWithDefaults(self::decodeConfig($row['config'] ?? ''));

        return $row;
    }

    public static function get(): array {
        $record = self::getModuleRecord();

        return $record ? $record['config'] : self::defaults();
    }

    public static function save(array $config): void {
        $record = self::getModuleRecord();

        if (!$record) {
            throw new RuntimeException('NetBox Sync module is not registered in the Zabbix module table.');
        }

        $config = self::mergeWithDefaults($config);

        try {
            API::Module()->update([[
                'moduleid' => $record['moduleid'],
                'config' => $config
            ]]);
        }
        catch (Throwable $e) {
            DB::update('module', [[
                'values' => [
                    'config' => json_encode($config, JSON_THROW_ON_ERROR)
                ],
                'where' => [
                    'moduleid' => $record['moduleid']
                ]
            ]]);
        }
    }

    public static function sanitizeForView(array $config): array {
        $config = self::mergeWithDefaults($config);

        $config['netbox']['token_present'] = trim((string) ($config['netbox']['token'] ?? '')) !== '';
        $config['netbox']['token'] = '';

        $config['runner']['shared_secret_present'] = trim((string) ($config['runner']['shared_secret'] ?? '')) !== '';
        $config['runner']['shared_secret'] = '';

        foreach ($config['custom_mappings'] as &$mapping) {
            $mapping['id'] = Util::cleanId($mapping['id'] ?? '', 'map');
        }
        unset($mapping);

        return $config;
    }


public static function sanitizeForRuntime(array $config): array {
    $config = self::mergeWithDefaults($config);

    $config['netbox']['token'] = self::resolveRuntimeSecret(
        (string) ($config['netbox']['token_env'] ?? ''),
        (string) ($config['netbox']['token'] ?? '')
    );

    $config['runner']['shared_secret'] = self::resolveRuntimeSecret(
        (string) ($config['runner']['shared_secret_env'] ?? ''),
        (string) ($config['runner']['shared_secret'] ?? '')
    );

    return $config;
}

    public static function buildFromPost(array $post, array $current_config): array {
        $current_config = self::mergeWithDefaults($current_config);
        $new_config = self::defaults();

        $new_config['netbox'] = [
            'enabled' => Util::truthy($post['netbox']['enabled'] ?? false),
            'url' => Util::cleanUrl($post['netbox']['url'] ?? ''),
            'token' => self::preserveSecret(
                $post['netbox']['token'] ?? '',
                $current_config['netbox']['token'] ?? '',
                $post['netbox']['clear_token'] ?? false
            ),
            'token_env' => Util::cleanString($post['netbox']['token_env'] ?? '', 128),
            'verify_peer' => Util::truthy($post['netbox']['verify_peer'] ?? false),
            'timeout' => Util::cleanInt($post['netbox']['timeout'] ?? 15, 15, 5, 300)
        ];

        $new_config['runner'] = [
            'enabled' => Util::truthy($post['runner']['enabled'] ?? false),
            'shared_secret' => self::preserveSecret(
                $post['runner']['shared_secret'] ?? '',
                $current_config['runner']['shared_secret'] ?? '',
                $post['runner']['clear_shared_secret'] ?? false
            ),
            'shared_secret_env' => Util::cleanString($post['runner']['shared_secret_env'] ?? '', 128),
            'state_path' => Util::cleanPath($post['runner']['state_path'] ?? '', 2048),
            'log_path' => Util::cleanPath($post['runner']['log_path'] ?? '', 2048),
            'global_interval_seconds' => Util::cleanInt($post['runner']['global_interval_seconds'] ?? 3600, 3600, 60, 86400 * 30),
            'lock_ttl_seconds' => Util::cleanInt($post['runner']['lock_ttl_seconds'] ?? 1800, 1800, 60, 86400),
            'default_prefix_length' => Util::cleanInt($post['runner']['default_prefix_length'] ?? 24, 24, 1, 32),
            'max_hosts_per_run' => Util::cleanInt($post['runner']['max_hosts_per_run'] ?? 0, 0, 0, 100000)
        ];

        $new_config['vm'] = [
            'enabled' => Util::truthy($post['vm']['enabled'] ?? false),
            'create_missing' => Util::truthy($post['vm']['create_missing'] ?? false),
            'update_existing' => Util::truthy($post['vm']['update_existing'] ?? false),
            'require_os_for_create' => Util::truthy($post['vm']['require_os_for_create'] ?? false),
            'default_site' => Util::cleanInt($post['vm']['default_site'] ?? 1, 1, 1, 1000000),
            'name_suffix' => Util::cleanString($post['vm']['name_suffix'] ?? '', 255),
            'name_suffix_separator' => Util::cleanEnum($post['vm']['name_suffix_separator'] ?? '.', ['.', '-', '', '_'], '.'),
            'os_pretty_name_item_name' => Util::cleanString($post['vm']['os_pretty_name_item_name'] ?? '', 255),
            'os_fallback_item_key' => Util::cleanString($post['vm']['os_fallback_item_key'] ?? '', 255),
            'linux_cpu_item_key' => Util::cleanString($post['vm']['linux_cpu_item_key'] ?? '', 255),
            'windows_cpu_item_key' => Util::cleanString($post['vm']['windows_cpu_item_key'] ?? '', 1024),
            'memory_item_key' => Util::cleanString($post['vm']['memory_item_key'] ?? '', 255),
            'sql_version_item_key' => Util::cleanString($post['vm']['sql_version_item_key'] ?? '', 255),
            'windows_disk_search' => Util::cleanString($post['vm']['windows_disk_search'] ?? '', 255),
            'linux_disk_search' => Util::cleanString($post['vm']['linux_disk_search'] ?? '', 255),
            'interface_search' => Util::cleanString($post['vm']['interface_search'] ?? '', 255),
            'memory_unit' => Util::cleanEnum($post['vm']['memory_unit'] ?? 'mb', ['mb', 'gb'], 'mb'),
            'disk_unit' => Util::cleanEnum($post['vm']['disk_unit'] ?? 'mb', ['mb', 'gb'], 'mb'),
            'prune_disks' => Util::truthy($post['vm']['prune_disks'] ?? false),
            'prune_interfaces' => Util::truthy($post['vm']['prune_interfaces'] ?? false)
        ];

        $new_config['services'] = [
            'enabled' => Util::truthy($post['services']['enabled'] ?? false),
            'prune_stale' => Util::truthy($post['services']['prune_stale'] ?? false),
            'windows_item_name' => Util::cleanString($post['services']['windows_item_name'] ?? '', 255),
            'linux_item_name' => Util::cleanString($post['services']['linux_item_name'] ?? '', 255)
        ];

        $new_config['device'] = [
            'enabled' => Util::truthy($post['device']['enabled'] ?? false),
            'create_missing' => Util::truthy($post['device']['create_missing'] ?? false),
            'update_existing' => Util::truthy($post['device']['update_existing'] ?? false),
            'default_site' => Util::cleanInt($post['device']['default_site'] ?? 1, 1, 1, 1000000),
            'default_role_id' => Util::cleanInt($post['device']['default_role_id'] ?? 1, 1, 1, 1000000),
            'default_status' => Util::cleanString($post['device']['default_status'] ?? 'active', 64),
            'name_source_mode' => Util::cleanEnum($post['device']['name_source_mode'] ?? 'host_name', ['host_name', 'item_key', 'item_name', 'static', 'agent_ip'], 'host_name'),
            'name_source_value' => Util::cleanString($post['device']['name_source_value'] ?? '', 255),
            'manufacturer_source_mode' => Util::cleanEnum($post['device']['manufacturer_source_mode'] ?? 'static', ['host_name', 'item_key', 'item_name', 'static'], 'static'),
            'manufacturer_source_value' => Util::cleanString($post['device']['manufacturer_source_value'] ?? '', 255),
            'model_source_mode' => Util::cleanEnum($post['device']['model_source_mode'] ?? 'item_key', ['host_name', 'item_key', 'item_name', 'static'], 'item_key'),
            'model_source_value' => Util::cleanString($post['device']['model_source_value'] ?? '', 255),
            'serial_source_mode' => Util::cleanEnum($post['device']['serial_source_mode'] ?? 'item_key', ['host_name', 'item_key', 'item_name', 'static', 'agent_ip'], 'item_key'),
            'serial_source_value' => Util::cleanString($post['device']['serial_source_value'] ?? '', 255),
            'create_missing_manufacturer' => Util::truthy($post['device']['create_missing_manufacturer'] ?? false),
            'create_missing_device_type' => Util::truthy($post['device']['create_missing_device_type'] ?? false)
        ];

        $syncs_by_id = self::indexById($post['standard_syncs'] ?? []);
        $new_config['standard_syncs'] = [];

        foreach (self::defaults()['standard_syncs'] as $sync) {
            $id = (string) $sync['id'];
            $row = $syncs_by_id[$id] ?? [];
            $new_config['standard_syncs'][] = [
                'id' => $id,
                'enabled' => Util::truthy($row['enabled'] ?? false),
                'interval_seconds' => Util::cleanInt($row['interval_seconds'] ?? 0, 0, 0, 86400 * 365)
            ];
        }

        $new_config['custom_mappings'] = [];

        foreach (($post['custom_mappings'] ?? []) as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $id = Util::cleanId($mapping['id'] ?? '', 'map');
            $name = Util::cleanString($mapping['name'] ?? '', 128);
            $source_value = Util::cleanString($mapping['source_value'] ?? '', 2048);
            $target_field = Util::cleanString($mapping['target_field'] ?? '', 255);
            $target_url_template = Util::cleanString($mapping['target_url_template'] ?? '', 1024);

            $is_empty = $name === ''
                && $source_value === ''
                && $target_field === ''
                && $target_url_template === '';

            if ($is_empty) {
                continue;
            }

            $new_config['custom_mappings'][] = [
                'id' => $id,
                'enabled' => Util::truthy($mapping['enabled'] ?? false),
                'name' => $name !== '' ? $name : ('Mapping '.(count($new_config['custom_mappings']) + 1)),
                'host_name_regex' => Util::cleanString($mapping['host_name_regex'] ?? '', 255),
                'target_object' => Util::cleanEnum($mapping['target_object'] ?? 'vm', ['vm', 'device', 'custom_url'], 'vm'),
                'mode' => Util::cleanEnum($mapping['mode'] ?? 'field_patch', ['field_patch', 'relation_lookup', 'ensure_device_type'], 'field_patch'),
                'source_mode' => Util::cleanEnum($mapping['source_mode'] ?? 'item_key', ['item_key', 'item_name', 'static', 'host_name', 'agent_ip'], 'item_key'),
                'source_value' => $source_value,
                'source_json_path' => Util::cleanString($mapping['source_json_path'] ?? '', 255),
                'transform' => Util::cleanEnum($mapping['transform'] ?? 'none', ['none', 'trim', 'int', 'float', 'lower', 'upper', 'slug'], 'none'),
                'target_field' => $target_field,
                'target_url_template' => $target_url_template,
                'lookup_endpoint' => Util::cleanString($mapping['lookup_endpoint'] ?? '', 255),
                'lookup_query_field' => Util::cleanString($mapping['lookup_query_field'] ?? 'name', 128),
                'ensure_missing_mode' => Util::cleanEnum($mapping['ensure_missing_mode'] ?? 'none', ['none', 'manufacturer', 'device_type'], 'none'),
                'relation_manufacturer_source_mode' => Util::cleanEnum($mapping['relation_manufacturer_source_mode'] ?? 'static', ['host_name', 'item_key', 'item_name', 'static'], 'static'),
                'relation_manufacturer_source_value' => Util::cleanString($mapping['relation_manufacturer_source_value'] ?? '', 255),
                'on_empty' => Util::cleanEnum($mapping['on_empty'] ?? 'skip', ['skip', 'clear'], 'skip'),
                'interval_seconds' => Util::cleanInt($mapping['interval_seconds'] ?? 0, 0, 0, 86400 * 365),
                'notes' => Util::cleanMultiline($mapping['notes'] ?? '', 5000)
            ];
        }

        return self::mergeWithDefaults($new_config);
    }

    public static function getStandardSyncConfig(array $config, string $id): array {
        $config = self::mergeWithDefaults($config);

        foreach ($config['standard_syncs'] as $sync) {
            if (($sync['id'] ?? '') === $id) {
                return $sync;
            }
        }

        foreach (self::defaults()['standard_syncs'] as $sync) {
            if (($sync['id'] ?? '') === $id) {
                return $sync;
            }
        }

        return ['id' => $id, 'enabled' => false, 'interval_seconds' => 0];
    }


private static function resolveRuntimeSecret(string $env_name, string $stored_value): string {
    $env_name = trim($env_name);

    if ($env_name !== '') {
        $env_value = getenv($env_name);
        if ($env_value !== false && trim((string) $env_value) !== '') {
            return trim((string) $env_value);
        }
    }

    return trim($stored_value);
}

    private static function preserveSecret($new_value, string $current_value, $clear_flag): string {
        if (Util::truthy($clear_flag)) {
            return '';
        }

        $new_value = Util::cleanString($new_value ?? '', 4096);

        return $new_value !== '' ? $new_value : $current_value;
    }

    private static function decodeConfig($value): array {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function mergeWithDefaults(array $config): array {
        return self::mergeRecursiveDistinct(self::defaults(), $config);
    }

    private static function mergeRecursiveDistinct(array $defaults, array $overrides): array {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && self::isAssociative($value) && self::isAssociative($defaults[$key])) {
                $defaults[$key] = self::mergeRecursiveDistinct($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    private static function isAssociative(array $value): bool {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function indexById($rows): array {
        $indexed = [];

        if (!is_array($rows)) {
            return $indexed;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = Util::cleanId($row['id'] ?? '', 'row');
            $row['id'] = $id;
            $indexed[$id] = $row;
        }

        return $indexed;
    }
}
