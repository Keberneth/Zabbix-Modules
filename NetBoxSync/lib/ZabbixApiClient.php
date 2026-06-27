<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use API;
use RuntimeException;

class ZabbixApiClient {

    /** Hosts per Item/HostInterface bulk-prefetch chunk (keeps `hostids` lists sane). */
    private const HOST_CHUNK = 500;

    public function __construct() {
        if (!class_exists('API')) {
            throw new RuntimeException('Zabbix API facade is not available in this context.');
        }
    }

    /**
     * Fetch monitored hosts. A positive $limit bounds the result set so the sync
     * never pulls an unbounded host list into memory; max_hosts_per_run is honored
     * at fetch time by the caller.
     */
    public function getAllHosts(int $limit = 0): array {
        $params = [
            'output' => ['hostid', 'host'],
            'sortfield' => 'host'
        ];

        if ($limit > 0) {
            $params['limit'] = $limit;
        }

        return (array) API::Host()->get($params);
    }

    /**
     * Bulk variant of getItemByExactKey across many hosts in array_chunk batches.
     * Returns map[hostid][key_] = item (first item wins per host+key).
     */
    public function getItemsByExactKeys(array $hostids, array $keys): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));
        $keys = array_values(array_unique(array_filter($keys, static fn($k) => (string) $k !== '')));

        if ($hostids === [] || $keys === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $items = (array) API::Item()->get([
                'hostids' => $chunk,
                'filter' => ['key_' => $keys],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue']
            ]);

            foreach ($items as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                $key = (string) ($item['key_'] ?? '');
                if ($hostid === '' || $key === '' || isset($map[$hostid][$key])) {
                    continue;
                }
                $map[$hostid][$key] = $item;
            }
        }

        return $map;
    }

    /**
     * Bulk variant of getItemByExactName across many hosts in array_chunk batches.
     * Returns map[hostid][name] = item (first item wins per host+name).
     */
    public function getItemsByExactNames(array $hostids, array $names): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));
        $names = array_values(array_unique(array_filter($names, static fn($n) => (string) $n !== '')));

        if ($hostids === [] || $names === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $items = (array) API::Item()->get([
                'hostids' => $chunk,
                'filter' => ['name' => $names],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue'],
                'sortfield' => 'name'
            ]);

            foreach ($items as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                $name = (string) ($item['name'] ?? '');
                if ($hostid === '' || $name === '' || isset($map[$hostid][$name])) {
                    continue;
                }
                $map[$hostid][$name] = $item;
            }
        }

        return $map;
    }

    /**
     * Bulk key-search across many hosts in array_chunk batches.
     * Returns map[hostid] = list of matching items.
     */
    public function searchItemsByKeyForHosts(array $hostids, string $pattern, array $extra = []): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));

        if ($hostids === [] || $pattern === '') {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $params = [
                'hostids' => $chunk,
                'search' => ['key_' => $pattern],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue']
            ] + $extra;

            foreach ((array) API::Item()->get($params) as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                if ($hostid === '') {
                    continue;
                }
                $map[$hostid][] = $item;
            }
        }

        return $map;
    }

    /**
     * One HostInterface.get for many hosts in array_chunk batches.
     * Returns map[hostid] = list of interfaces.
     */
    public function getInterfacesForHosts(array $hostids): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));

        if ($hostids === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $rows = (array) API::HostInterface()->get([
                'hostids' => $chunk,
                'output' => ['interfaceid', 'hostid', 'type', 'ip', 'dns', 'port', 'main', 'useip']
            ]);

            foreach ($rows as $row) {
                $hostid = (string) ($row['hostid'] ?? '');
                if ($hostid === '') {
                    continue;
                }
                $map[$hostid][] = $row;
            }
        }

        return $map;
    }

    /** Pick the main Zabbix agent interface from a prefetched interface list. */
    public static function pickMainAgentInterface(array $interfaces): ?array {
        foreach ($interfaces as $iface) {
            if ((string) ($iface['type'] ?? '') === '1' && (string) ($iface['main'] ?? '') === '1') {
                return $iface;
            }
        }

        return null;
    }

    public function getItemByExactKey(string $hostid, string $key): ?array {
        if ($key === '') {
            return null;
        }

        $items = (array) API::Item()->get([
            'hostids' => [$hostid],
            'filter' => ['key_' => $key],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ]);

        return $items[0] ?? null;
    }

    public function getItemByExactName(string $hostid, string $name): ?array {
        if ($name === '') {
            return null;
        }

        $items = (array) API::Item()->get([
            'hostids' => [$hostid],
            'filter' => ['name' => $name],
            'output' => ['itemid', 'name', 'key_', 'lastvalue'],
            'sortfield' => 'name'
        ]);

        return $items[0] ?? null;
    }

    public function searchItemsByKey(string $hostid, string $pattern, array $extra = []): array {
        if ($pattern === '') {
            return [];
        }

        $params = [
            'hostids' => [$hostid],
            'search' => ['key_' => $pattern],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ] + $extra;

        return (array) API::Item()->get($params);
    }

    public function searchItemsByName(string $hostid, string $pattern, array $extra = []): array {
        if ($pattern === '') {
            return [];
        }

        $params = [
            'hostids' => [$hostid],
            'search' => ['name' => $pattern],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ] + $extra;

        return (array) API::Item()->get($params);
    }

    public function getHostInterfaces(string $hostid): array {
        return (array) API::HostInterface()->get([
            'hostids' => [$hostid],
            'output' => ['interfaceid', 'type', 'ip', 'dns', 'port', 'main', 'useip']
        ]);
    }

    public function getMainAgentInterface(string $hostid): ?array {
        foreach ($this->getHostInterfaces($hostid) as $iface) {
            if ((string) ($iface['type'] ?? '') === '1' && (string) ($iface['main'] ?? '') === '1') {
                return $iface;
            }
        }

        return null;
    }
}
