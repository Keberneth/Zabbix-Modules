<?php
declare(strict_types=1);

namespace Modules\NetworkMap\Lib;

final class MapBuilder {
    /** Hard ceiling on history rows processed per build (timeout/OOM guard). */
    private const MAX_HISTORY_ROWS = 200000;

    /** Number of item ids per batched API::History()->get() call. */
    private const HISTORY_BATCH = 50;

    private Cache $cache;

    public function __construct(?Cache $cache = null) {
        $this->cache = $cache ?? new Cache();
    }

    /**
     * Return the network-map payload for the given user, serving a fresh disk
     * cache when available and otherwise rebuilding it. On a rebuild failure a
     * stale cache entry (if any) is returned with a generic 'stale' warning;
     * the real cause is logged server-side, never exposed to the client.
     */
    public function getMap(bool $force_refresh, string $user_id, ?int $history_hours = null): array {
        $effective_hours = $history_hours ?? (int) Config::get('history_window_hours', 24);
        $cache_key = 'map-user-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $user_id ?: '0')
            . '-h' . $effective_hours;
        $ttl = (int) Config::get('cache_ttl_seconds', 1800);

        if (!$force_refresh) {
            $cached = $this->cache->read($cache_key, $ttl);

            if (is_array($cached)) {
                $cached['meta'] = array_merge($cached['meta'] ?? [], [
                    'cached' => true,
                    'cache_age_seconds' => $this->cache->age($cache_key) ?? 0
                ]);

                return $cached;
            }
        }

        try {
            $payload = $this->build($effective_hours);
            $payload['meta'] = array_merge($payload['meta'] ?? [], [
                'cached' => false,
                'cache_age_seconds' => 0
            ]);

            $this->cache->write($cache_key, $payload);

            return $payload;
        }
        catch (\Throwable $e) {
            // Log the real cause server-side; never leak it to the browser.
            error_log('NetworkMap: map rebuild failed: ' . $e->getMessage());

            $stale = $this->cache->readAny($cache_key);

            if (is_array($stale)) {
                $stale['meta'] = array_merge($stale['meta'] ?? [], [
                    'cached' => true,
                    'stale' => true,
                    'warning' => 'Refresh failed, stale cached data returned.',
                    'cache_age_seconds' => $this->cache->age($cache_key) ?? null
                ]);

                return $stale;
            }

            throw $e;
        }
    }

    /**
     * Build the nodes/edges/meta payload by scanning recent connection history.
     *
     * History is fetched in batched bulk calls (one per value_type, chunked by
     * HISTORY_BATCH item ids) rather than one call per item, and the total
     * number of processed rows is capped at MAX_HISTORY_ROWS to bound memory
     * and execution time on large estates (meta['limit_reached'] flags a hit).
     */
    private function build(int $history_hours = 24): array {
        set_time_limit(300);

        $generated_at = time();
        $time_till = $generated_at;
        $time_from = $generated_at - ($history_hours * 3600);

        [$ip_to_node, $host_nodes] = $this->getNodeMaps();

        $edge_index = [];
        $nodes_index = [];

        $items = $this->getNetworkItems();

        // Pre-resolve each item's local host node and group item ids by
        // value_type so history can be pulled in bulk batches instead of one
        // API round-trip per item (the original N+1 bottleneck).
        $item_host_node = [];
        $items_by_type = [];

        foreach ($items as $item) {
            $itemid = (string) ($item['itemid'] ?? '');

            if ($itemid === '') {
                continue;
            }

            $value_type = isset($item['value_type'])
                ? (int) $item['value_type']
                : (defined('ITEM_VALUE_TYPE_TEXT') ? ITEM_VALUE_TYPE_TEXT : 4);

            $item_host_node[$itemid] = $this->getItemHostNode($item, $host_nodes);
            $items_by_type[$value_type][] = $itemid;
        }

        $per_item_limit = (int) Config::get('history_limit_per_item', 50000);
        $processed_rows = 0;
        $per_item_rows = [];
        $limit_reached = false;

        foreach ($items_by_type as $value_type => $itemids) {
            if ($limit_reached) {
                break;
            }

            foreach (array_chunk($itemids, self::HISTORY_BATCH) as $chunk) {
                if ($limit_reached) {
                    break;
                }

                foreach ($this->getHistory($chunk, (int) $value_type, $time_from, $time_till) as $history_entry) {
                    $itemid = (string) ($history_entry['itemid'] ?? '');

                    if ($itemid === '' || !array_key_exists($itemid, $item_host_node)) {
                        continue;
                    }

                    // Per-item processed-row ceiling: a single chatty item must
                    // not crowd out the rest of the estate.
                    $per_item_rows[$itemid] = ($per_item_rows[$itemid] ?? 0) + 1;
                    if ($per_item_rows[$itemid] > $per_item_limit) {
                        continue;
                    }

                    // Global processed-row ceiling (timeout/OOM guard).
                    if ($processed_rows >= self::MAX_HISTORY_ROWS) {
                        $limit_reached = true;
                        break;
                    }
                    $processed_rows++;

                    $local_host_node = $item_host_node[$itemid];

                    $payload = Helpers::decodeJsonObject((string) ($history_entry['value'] ?? ''));

                    if (!is_array($payload)) {
                        continue;
                    }

                $incoming = Helpers::normalizeConnectionList(
                    $payload['incomingconnections']
                    ?? $payload['incomingConnections']
                    ?? $payload['incoming_connections']
                    ?? []
                );
                $outgoing = Helpers::normalizeConnectionList(
                    $payload['outgoingconnections']
                    ?? $payload['outgoingConnections']
                    ?? $payload['outgoing_connections']
                    ?? []
                );

                foreach ([['in', $incoming], ['out', $outgoing]] as [$direction, $connections]) {
                    foreach ($connections as $connection) {
                        if (!is_array($connection)) {
                            continue;
                        }

                        $local_ip = Helpers::firstNonEmpty(
                            $connection['localip'] ?? null,
                            $connection['localIp'] ?? null,
                            $connection['local_ip'] ?? null
                        );
                        $remote_ip = Helpers::firstNonEmpty(
                            $connection['remoteip'] ?? null,
                            $connection['remoteIp'] ?? null,
                            $connection['remote_ip'] ?? null
                        );
                        $local_port = Helpers::firstNonEmpty(
                            $connection['localport'] ?? null,
                            $connection['localPort'] ?? null,
                            $connection['local_port'] ?? null
                        );
                        $remote_port = Helpers::firstNonEmpty(
                            $connection['remoteport'] ?? null,
                            $connection['remotePort'] ?? null,
                            $connection['remote_port'] ?? null
                        );

                        if ($local_ip === '' || $remote_ip === '') {
                            continue;
                        }

                        $local_node = $local_host_node ?? $this->resolveEndpointByIp($local_ip, $ip_to_node);
                        $remote_node = $this->resolveEndpointByIp($remote_ip, $ip_to_node);

                        if ($direction === 'in') {
                            $src_node = $remote_node;
                            $dst_node = $local_node;
                            $src_ip = $remote_ip;
                            $dst_ip = $local_ip;
                            $service_port = $local_port;
                        }
                        else {
                            $src_node = $local_node;
                            $dst_node = $remote_node;
                            $src_ip = $local_ip;
                            $dst_ip = $remote_ip;
                            $service_port = $remote_port;
                        }

                        if (($src_node['id'] ?? '') === '' || ($dst_node['id'] ?? '') === '') {
                            continue;
                        }

                        $this->registerNode($nodes_index, $src_node, $src_ip);
                        $this->registerNode($nodes_index, $dst_node, $dst_ip);

                        $is_public = Helpers::isPublicIp($remote_ip);
                        $edge_key = implode('|', [
                            $src_node['id'],
                            $dst_node['id'],
                            $service_port,
                            $local_port,
                            $remote_port,
                            $is_public ? '1' : '0',
                            $src_ip,
                            $dst_ip
                        ]);

                        $edge_index[$edge_key] = [
                            'source' => (string) $src_node['id'],
                            'target' => (string) $dst_node['id'],
                            'sourceLabel' => (string) ($nodes_index[(string) $src_node['id']]['label'] ?? $src_node['label'] ?? $src_node['id']),
                            'targetLabel' => (string) ($nodes_index[(string) $dst_node['id']]['label'] ?? $dst_node['label'] ?? $dst_node['id']),
                            'servicePort' => $service_port,
                            'localPort' => $local_port,
                            'remotePort' => $remote_port,
                            'isPublic' => $is_public,
                            'srcIp' => $src_ip,
                            'dstIp' => $dst_ip
                        ];
                    }
                }
                }
            }
        }

        $degree = [];
        foreach ($nodes_index as $node_id => $_node) {
            $degree[$node_id] = 0;
        }

        foreach ($edge_index as $edge) {
            $degree[$edge['source']] = ($degree[$edge['source']] ?? 0) + 1;
            $degree[$edge['target']] = ($degree[$edge['target']] ?? 0) + 1;
        }

        $color_map = (array) Config::get('node_color_map', []);
        $sorted_nodes = array_values($nodes_index);
        usort($sorted_nodes, static function(array $left, array $right): int {
            $left_key = strtolower((string) ($left['label'] ?? $left['id'] ?? ''));
            $right_key = strtolower((string) ($right['label'] ?? $right['id'] ?? ''));

            return $left_key <=> $right_key ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        $nodes = [];
        foreach ($sorted_nodes as $node) {
            $ip = (string) ($node['ip'] ?? '');
            $node_id = (string) ($node['id'] ?? '');
            $display_label = $this->buildDisplayLabel($node);

            if ($ip !== '' && Helpers::isPublicIp($ip)) {
                $color = $color_map['external'] ?? '#ff3366';
            }
            elseif (!empty($node['known_host'])) {
                $color = $color_map['monitored_host'] ?? '#007bff';
            }
            else {
                $color = $color_map['private_ip'] ?? '#6c757d';
            }

            $nodes[] = [
                'data' => [
                    'id' => $node_id,
                    'label' => $display_label,
                    'shortLabel' => (string) ($node['label'] ?? $node_id),
                    'degree' => $degree[$node_id] ?? 0,
                    'ip' => $ip,
                    'knownHost' => !empty($node['known_host']),
                    'technicalName' => (string) ($node['technical_name'] ?? ''),
                    'color' => $color
                ]
            ];
        }

        $edges = [];
        $edge_counter = 0;
        foreach ($edge_index as $edge) {
            $edge_counter++;

            $edges[] = [
                'data' => [
                    'id' => 'e' . $edge_counter,
                    'source' => $edge['source'],
                    'target' => $edge['target'],
                    'sourceLabel' => $edge['sourceLabel'],
                    'targetLabel' => $edge['targetLabel'],
                    'label' => $edge['servicePort'] !== '' ? sprintf('port %s', $edge['servicePort']) : '',
                    'servicePort' => $edge['servicePort'],
                    'localPort' => $edge['localPort'],
                    'remotePort' => $edge['remotePort'],
                    'isPublic' => $edge['isPublic'],
                    'srcIp' => $edge['srcIp'],
                    'dstIp' => $edge['dstIp']
                ]
            ];
        }

        $meta = [
            'generated_at' => $generated_at,
            'generated_at_iso' => Helpers::iso8601($generated_at),
            'time_from' => $time_from,
            'time_from_iso' => Helpers::iso8601($time_from),
            'time_till' => $time_till,
            'time_till_iso' => Helpers::iso8601($time_till),
            'history_window_hours' => $history_hours,
            'nodes_count' => count($nodes),
            'edges_count' => count($edges),
            'host_label_source' => (string) Config::get('host_label_source', 'visible'),
            'permissions_filtered' => true,
            'limit_reached' => $limit_reached,
            'rows_processed' => $processed_rows
        ];

        if ($items === []) {
            $meta['warning'] = 'No matching Zabbix items were found. Check zabbix_item_names in config.local.php.';
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => $meta
        ];
    }

    /**
     * Build the host lookup maps used to resolve connection endpoints.
     *
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
     *     [ip => host-node] for IP resolution and [technical_name => host-node].
     */
    private function getNodeMaps(): array {
        $status_monitored = defined('HOST_STATUS_MONITORED') ? HOST_STATUS_MONITORED : 0;

        $hosts = \API::Host()->get([
            'output' => ['host', 'name'],
            'selectInterfaces' => ['ip', 'main'],
            'filter' => ['status' => $status_monitored]
        ]);

        $ip_to_node = [];
        $host_nodes = [];

        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }

            $technical_name = Helpers::safeString($host['host'] ?? '');
            $visible_name = Helpers::firstNonEmpty($host['name'] ?? null, $technical_name);

            if ($technical_name === '') {
                continue;
            }

            $ips = [];
            $primary_ip = '';

            foreach (($host['interfaces'] ?? []) as $interface) {
                if (!is_array($interface)) {
                    continue;
                }

                $ip = Helpers::safeString($interface['ip'] ?? '');

                if ($ip === '') {
                    continue;
                }

                if ($primary_ip === '' || (string) ($interface['main'] ?? '0') === '1') {
                    $primary_ip = $ip;
                }

                $ips[$ip] = true;
            }

            $node = [
                'id' => $technical_name,
                'label' => $this->selectHostLabel($technical_name, $visible_name),
                'ip' => $primary_ip,
                'known_host' => true,
                'technical_name' => $technical_name
            ];

            $host_nodes[$technical_name] = $node;

            foreach (array_keys($ips) as $ip) {
                if (!array_key_exists($ip, $ip_to_node)) {
                    $ip_to_node[$ip] = $node;
                }
            }
        }

        return [$ip_to_node, $host_nodes];
    }

    private function selectHostLabel(string $technical_name, string $visible_name): string {
        $source = (string) Config::get('host_label_source', 'visible');

        if ($source === 'technical') {
            return $technical_name;
        }

        return $visible_name !== '' ? $visible_name : $technical_name;
    }

    private function getItemHostNode(array $item, array $host_nodes): ?array {
        $hosts = $item['hosts'] ?? [];

        if (!is_array($hosts)) {
            return null;
        }

        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }

            $technical_name = Helpers::safeString($host['host'] ?? '');
            $visible_name = Helpers::firstNonEmpty($host['name'] ?? null, $technical_name);

            if ($technical_name === '') {
                continue;
            }

            if (array_key_exists($technical_name, $host_nodes)) {
                return $host_nodes[$technical_name];
            }

            return [
                'id' => $technical_name,
                'label' => $this->selectHostLabel($technical_name, $visible_name),
                'ip' => '',
                'known_host' => true,
                'technical_name' => $technical_name
            ];
        }

        return null;
    }

    private function resolveEndpointByIp(string $ip, array $ip_to_node): array {
        if ($ip !== '' && array_key_exists($ip, $ip_to_node)) {
            return $ip_to_node[$ip];
        }

        return [
            'id' => $ip,
            'label' => $ip,
            'ip' => $ip,
            'known_host' => false,
            'technical_name' => ''
        ];
    }

    private function registerNode(array &$nodes_index, array $node, string $observed_ip): void {
        $node_id = Helpers::safeString($node['id'] ?? '');

        if ($node_id === '') {
            return;
        }

        $label = Helpers::firstNonEmpty($node['label'] ?? null, $node_id);
        $known_host = !empty($node['known_host']);
        $technical_name = Helpers::safeString($node['technical_name'] ?? '');
        $ip = Helpers::firstNonEmpty($node['ip'] ?? null, $observed_ip);

        if (!array_key_exists($node_id, $nodes_index)) {
            $nodes_index[$node_id] = [
                'id' => $node_id,
                'label' => $label,
                'ip' => $ip,
                'known_host' => $known_host,
                'technical_name' => $technical_name
            ];
            return;
        }

        if (($nodes_index[$node_id]['label'] ?? '') === '' || ($nodes_index[$node_id]['label'] ?? '') === $node_id) {
            $nodes_index[$node_id]['label'] = $label;
        }

        if (($nodes_index[$node_id]['ip'] ?? '') === '' && $ip !== '') {
            $nodes_index[$node_id]['ip'] = $ip;
        }

        if (!$nodes_index[$node_id]['known_host'] && $known_host) {
            $nodes_index[$node_id]['known_host'] = true;
        }

        if (($nodes_index[$node_id]['technical_name'] ?? '') === '' && $technical_name !== '') {
            $nodes_index[$node_id]['technical_name'] = $technical_name;
        }
    }

    private function buildDisplayLabel(array $node): string {
        $label = Helpers::firstNonEmpty($node['label'] ?? null, $node['id'] ?? null);
        $ip = Helpers::safeString($node['ip'] ?? '');

        if (!empty($node['known_host']) && (bool) Config::get('append_primary_ip_to_host_labels', true) && $ip !== '' && $ip !== $label) {
            return sprintf('%s (%s)', $label, $ip);
        }

        return $label;
    }

    private function getNetworkItems(): array {
        $item_names = array_values(array_filter(
            (array) Config::get('zabbix_item_names', []),
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));

        if ($item_names === []) {
            return [];
        }

        $items = \API::Item()->get([
            'output' => ['itemid', 'name', 'value_type'],
            'filter' => ['name' => $item_names],
            'selectHosts' => ['host', 'name']
        ]);

        return is_array($items) ? $items : [];
    }

    /**
     * Fetch raw history rows for a batch of item ids sharing one value_type.
     *
     * Only itemid/clock/value are requested (lean output, enough to route each
     * row back to its item and decode it) and the call is bounded by
     * MAX_HISTORY_ROWS so a single batch can never exceed the global ceiling.
     *
     * @param string[] $itemids
     * @return array<int,array<string,mixed>>
     */
    private function getHistory(array $itemids, int $history_type, int $time_from, int $time_till): array {
        if ($itemids === []) {
            return [];
        }

        $history_type = $history_type >= 0 ? $history_type : (defined('ITEM_VALUE_TYPE_TEXT') ? ITEM_VALUE_TYPE_TEXT : 4);

        $history = \API::History()->get([
            'output' => ['itemid', 'clock', 'value'],
            'history' => $history_type,
            'itemids' => array_values($itemids),
            'time_from' => $time_from,
            'time_till' => $time_till,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => self::MAX_HISTORY_ROWS
        ]);

        return is_array($history) ? $history : [];
    }
}
