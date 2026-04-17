<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use API;
use RuntimeException;

class ZabbixApiClient {

    public function __construct() {
        if (!class_exists('API')) {
            throw new RuntimeException('Zabbix API facade is not available in this context.');
        }
    }

    public function getAllHosts(): array {
        return (array) API::Host()->get([
            'output' => ['hostid', 'host']
        ]);
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
