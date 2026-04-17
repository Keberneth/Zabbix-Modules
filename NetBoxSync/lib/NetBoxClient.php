<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use RuntimeException;

class NetBoxClient {

    private string $base_url;
    private string $token;
    private bool $verify_peer;
    private int $timeout;

    public function __construct(string $base_url, string $token, bool $verify_peer = true, int $timeout = 15) {
        $base_url = Util::normalizeNetBoxBaseUrl($base_url);

        if ($base_url === '') {
            throw new RuntimeException('NetBox URL is empty.');
        }

        $this->base_url = $base_url;
        $this->token = trim($token);

        if ($this->token === '') {
            throw new RuntimeException('NetBox token is empty.');
        }

        $this->verify_peer = $verify_peer;
        $this->timeout = max(5, $timeout);
    }

    public function getBaseUrl(): string {
        return $this->base_url;
    }

    public function listResults(string $endpoint, array $query = []): array {
        if (!array_key_exists('limit', $query)) {
            $query['limit'] = 0;
        }

        $decoded = $this->request('GET', $endpoint, $query);

        return is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
    }

    public function getObject(string $endpoint, array $query = []): ?array {
        $results = $this->listResults($endpoint, $query);

        return $results[0] ?? null;
    }

    public function lookupRelationId(string $endpoint, string $query_field, string $query_value): ?int {
        $query_value = trim($query_value);

        if ($query_value === '') {
            return null;
        }

        $results = $this->listResults($endpoint, [
            $query_field => $query_value
        ]);

        if ($results === []) {
            return null;
        }

        foreach ($results as $result) {
            $candidate = (string) ($result[$query_field] ?? $result['name'] ?? $result['model'] ?? '');
            if ($candidate !== '' && strcasecmp($candidate, $query_value) === 0) {
                return (int) $result['id'];
            }
        }

        return isset($results[0]['id']) ? (int) $results[0]['id'] : null;
    }


    public function findCustomFieldByName(string $name): ?array {
        $results = $this->listResults('/extras/custom-fields/', ['name' => $name]);

        foreach ($results as $result) {
            if (strcasecmp((string) ($result['name'] ?? ''), $name) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    public function createCustomField(array $payload): array {
        return (array) $this->request('POST', '/extras/custom-fields/', [], $payload);
    }

    public function getPlatformIdByName(string $name): ?int {
        return $this->lookupRelationId('/dcim/platforms/', 'name', $name);
    }

    public function findVmByName(string $name): ?array {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $results = $this->listResults('/virtualization/virtual-machines/', ['name' => $name]);

        if ($results !== []) {
            return $results[0];
        }

        $results = $this->listResults('/virtualization/virtual-machines/', ['name__ic' => $name]);

        foreach ($results as $result) {
            if (strcasecmp((string) ($result['name'] ?? ''), $name) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    public function createVm(array $payload): array {
        return (array) $this->request('POST', '/virtualization/virtual-machines/', [], $payload);
    }

    public function updateVm(int $vm_id, array $payload): array {
        return (array) $this->request('PATCH', '/virtualization/virtual-machines/'.$vm_id.'/', [], $payload);
    }

    public function getVm(int $vm_id): ?array {
        $decoded = $this->request('GET', '/virtualization/virtual-machines/'.$vm_id.'/');

        return is_array($decoded) ? $decoded : null;
    }

    public function getVmInterfaces(int $vm_id): array {
        return $this->listResults('/virtualization/interfaces/', ['virtual_machine_id' => $vm_id]);
    }

    public function createVmInterface(int $vm_id, string $name): array {
        return (array) $this->request('POST', '/virtualization/interfaces/', [], [
            'virtual_machine' => $vm_id,
            'name' => $name
        ]);
    }

    public function deleteVmInterface(int $interface_id): void {
        $this->request('DELETE', '/virtualization/interfaces/'.$interface_id.'/');
    }

    public function getVirtualDisks(int $vm_id): array {
        return $this->listResults('/virtualization/virtual-disks/', ['virtual_machine_id' => $vm_id]);
    }

    public function createVirtualDisk(int $vm_id, string $name, int $size_mb): array {
        return (array) $this->request('POST', '/virtualization/virtual-disks/', [], [
            'virtual_machine' => $vm_id,
            'name' => $name,
            'size' => $size_mb
        ]);
    }

    public function updateVirtualDisk(int $disk_id, int $size_mb): array {
        return (array) $this->request('PATCH', '/virtualization/virtual-disks/'.$disk_id.'/', [], [
            'size' => $size_mb
        ]);
    }

    public function deleteVirtualDisk(int $disk_id): void {
        $this->request('DELETE', '/virtualization/virtual-disks/'.$disk_id.'/');
    }

    public function getPrefixesByQuery(string $query): array {
        return $this->listResults('/ipam/prefixes/', ['q' => $query]);
    }

    public function createPrefix(string $prefix): array {
        return (array) $this->request('POST', '/ipam/prefixes/', [], [
            'prefix' => $prefix,
            'status' => 'active'
        ]);
    }

    public function getIpByAddress(string $address): ?array {
        return $this->getObject('/ipam/ip-addresses/', ['address' => $address]);
    }

    public function getVmInterfaceIps(int $interface_id): array {
        return $this->listResults('/ipam/ip-addresses/', ['vminterface_id' => [$interface_id]]);
    }

    public function createVmInterfaceIp(string $address, int $interface_id): array {
        return (array) $this->request('POST', '/ipam/ip-addresses/', [], [
            'address' => $address,
            'assigned_object_type' => 'virtualization.vminterface',
            'assigned_object_id' => $interface_id
        ]);
    }

    public function setVmPrimaryIp4(int $vm_id, int $ip_id): array {
        return (array) $this->request('PATCH', '/virtualization/virtual-machines/'.$vm_id.'/', [], [
            'primary_ip4' => $ip_id
        ]);
    }

    public function listServicesForVm(int $vm_id): array {
        return $this->listResults('/ipam/services/', [
            'parent_object_type' => 'virtualization.virtualmachine',
            'parent_object_id' => $vm_id
        ]);
    }

    public function createService(int $vm_id, ?int $ip_id, int $port, string $name, string $description): array {
        $payload = [
            'parent_object_type' => 'virtualization.virtualmachine',
            'parent_object_id' => $vm_id,
            'name' => $name,
            'protocol' => 'tcp',
            'ports' => [$port],
            'description' => $description
        ];

        if ($ip_id !== null) {
            $payload['ipaddresses'] = [$ip_id];
        }

        return (array) $this->request('POST', '/ipam/services/', [], $payload);
    }

    public function updateService(int $service_id, string $name, string $description): array {
        return (array) $this->request('PATCH', '/ipam/services/'.$service_id.'/', [], [
            'name' => $name,
            'description' => $description
        ]);
    }

    public function deleteService(int $service_id): void {
        $this->request('DELETE', '/ipam/services/'.$service_id.'/');
    }

    public function findDeviceByName(string $name): ?array {
        $results = $this->listResults('/dcim/devices/', ['name' => $name]);

        if ($results !== []) {
            return $results[0];
        }

        $results = $this->listResults('/dcim/devices/', ['name__ic' => $name]);

        foreach ($results as $result) {
            if (strcasecmp((string) ($result['name'] ?? ''), $name) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    public function findManufacturerByName(string $name): ?array {
        $results = $this->listResults('/dcim/manufacturers/', ['name' => $name]);

        if ($results === []) {
            $results = $this->listResults('/dcim/manufacturers/', ['name__ic' => $name]);
        }

        foreach ($results as $result) {
            if (strcasecmp((string) ($result['name'] ?? ''), $name) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    public function createManufacturer(string $name): array {
        return (array) $this->request('POST', '/dcim/manufacturers/', [], [
            'name' => $name,
            'slug' => Util::slugify($name)
        ]);
    }

    public function ensureManufacturer(string $name, bool $allow_create = true): ?array {
        $existing = $this->findManufacturerByName($name);

        if ($existing) {
            return $existing;
        }

        if (!$allow_create) {
            return null;
        }

        return $this->createManufacturer($name);
    }

    public function findDeviceType(int $manufacturer_id, string $model): ?array {
        $results = $this->listResults('/dcim/device-types/', [
            'manufacturer_id' => $manufacturer_id,
            'model' => $model
        ]);

        if ($results === []) {
            $results = $this->listResults('/dcim/device-types/', [
                'manufacturer_id' => $manufacturer_id,
                'model__ic' => $model
            ]);
        }

        foreach ($results as $result) {
            if (strcasecmp((string) ($result['model'] ?? ''), $model) === 0) {
                return $result;
            }
        }

        return $results[0] ?? null;
    }

    public function createDeviceType(int $manufacturer_id, string $model): array {
        return (array) $this->request('POST', '/dcim/device-types/', [], [
            'manufacturer' => $manufacturer_id,
            'model' => $model,
            'slug' => Util::slugify($model)
        ]);
    }

    public function ensureDeviceType(string $manufacturer_name, string $model, bool $allow_manufacturer_create = true, bool $allow_device_type_create = true): ?array {
        $manufacturer = $this->ensureManufacturer($manufacturer_name, $allow_manufacturer_create);

        if (!$manufacturer) {
            return null;
        }

        $existing = $this->findDeviceType((int) $manufacturer['id'], $model);

        if ($existing) {
            return $existing;
        }

        if (!$allow_device_type_create) {
            return null;
        }

        return $this->createDeviceType((int) $manufacturer['id'], $model);
    }

    public function createDevice(array $payload): array {
        return (array) $this->request('POST', '/dcim/devices/', [], $payload);
    }

    public function updateDevice(int $device_id, array $payload): array {
        return (array) $this->request('PATCH', '/dcim/devices/'.$device_id.'/', [], $payload);
    }

    public function patchObjectByUrl(string $url_or_path, array $payload): array {
        return (array) $this->request('PATCH', $url_or_path, [], $payload);
    }

    public function request(string $method, string $url_or_path, array $query = [], ?array $payload = null) {
        $url = $this->resolveUrl($url_or_path);

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $headers = [
            'Authorization: Token '.$this->token,
            'Accept: application/json'
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verify_peer,
            CURLOPT_SSL_VERIFYHOST => $this->verify_peer ? 2 : 0
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);

        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('NetBox HTTP request failed: '.$error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 204) {
            return null;
        }

        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $details = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : trim((string) $body);
            throw new RuntimeException('NetBox HTTP '.$status.' for '.$method.' '.$url.': '.$details);
        }

        if ($body === '') {
            return null;
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from NetBox.');
        }

        return $decoded;
    }

    private function resolveUrl(string $url_or_path): string {
        if (Util::isAbsoluteUrl($url_or_path)) {
            return $url_or_path;
        }

        return Util::joinUrl($this->base_url, $url_or_path);
    }
}
