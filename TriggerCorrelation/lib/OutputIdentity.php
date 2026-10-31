<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/ReceiverProvisioner.php';

/** Resolves output aliases to the host id and item key history.push writes. */
final class OutputIdentity {
    private $call;
    private array $cache = [];

    public function __construct(callable $call) {
        $this->call = $call;
    }

    /**
     * A physical key identifies an actual output across every editor mode.
     * Automatic drafts also have a logical key before their host is provisioned.
     * Non-strict resolution tolerates unrelated deleted/inaccessible outputs.
     */
    public function keys(array $rule, array $settings = [], bool $strict = false): array {
        $output = (array) ($rule['output'] ?? []);
        $mode = (string) ($output['mode'] ?? 'receiver_lld');
        $auto = $mode === 'receiver_lld' && !empty($output['receiver_auto']);
        $keys = [];
        $slug = CorrelationStore::slug((string) (($output['correlation_id'] ?? '') ?: ($rule['name'] ?? $rule['id'] ?? 'correlation')));
        $itemKey = str_replace('%s', $slug, $auto ? 'trigger.correlation.state[%s]'
            : ((string) ($settings['receiver_state_key_template'] ?? '') ?: 'trigger.correlation.state[%s]'));
        if ($auto) {
            $logical = self::automaticKey($rule);
            if ($logical !== null) { $keys[] = 'auto:'.json_encode([$logical, $itemKey]); }
        }
        try {
            if ($mode === 'existing_item') {
                $itemid = trim((string) ($output['itemid'] ?? ''));
                $params = ['output' => ['itemid', 'hostid', 'key_', 'type', 'value_type', 'status'], 'limit' => 2];
                if ($itemid !== '') {
                    $params['itemids'] = [$itemid];
                }
                else {
                    $host = $this->host('', trim((string) ($output['host'] ?? '')));
                    $key = trim((string) ($output['key'] ?? ''));
                    if ($key === '') { throw new \InvalidArgumentException('Select an output item or enter its key.'); }
                    $params['hostids'] = [(string) $host['hostid']];
                    $params['filter'] = ['key_' => $key];
                }
                $rows = $this->api('item.get', $params);
                if (count($rows) !== 1) { throw new \InvalidArgumentException('The output item is missing, inaccessible or ambiguous. Select it again.'); }
                $item = $rows[0];
                if ($strict && ((int) ($item['type'] ?? -1) !== 2 || !in_array((int) ($item['value_type'] ?? -1), [0, 3], true)
                        || (int) ($item['status'] ?? 0) !== 0)) {
                    throw new \InvalidArgumentException('The output must be an enabled numeric Zabbix trapper item.');
                }
                $hostid = trim((string) ($item['hostid'] ?? ''));
                $itemKey = (string) ($item['key_'] ?? '');
                if ($hostid === '' || $itemKey === '') { throw new \InvalidArgumentException('The output item has no usable host or key.'); }
                if ($strict && (int) ($this->host($hostid, '')['status'] ?? 0) !== 0) {
                    throw new \InvalidArgumentException('The output host is disabled. Enable it before activating the rule.');
                }
                $keys[] = self::physicalKey($hostid, $itemKey);
            }
            elseif ($mode === 'receiver_lld') {
                // Manual receivers are addressed by technical name by the
                // evaluator; an incidental/stale receiver_hostid is ignored.
                $hostid = $auto ? trim((string) ($output['receiver_hostid'] ?? '')) : '';
                $name = $auto ? trim((string) ($output['receiver_host'] ?? ''))
                    : (trim((string) ($output['receiver_host'] ?? '')) ?: trim((string) ($settings['receiver_host'] ?? '')));
                if ($auto && $hostid === '' && $name === '') {
                    // Disabled imports/drafts are deliberately unprovisioned.
                    if ($strict) { throw new \InvalidArgumentException('The automatic output host has not been provisioned. Save the rule to set it up.'); }
                    return $keys;
                }
                $host = $this->host($hostid, $name);
                if ($strict && (int) ($host['status'] ?? 0) !== 0) {
                    throw new \InvalidArgumentException('The output host is disabled. Enable it before activating the rule.');
                }
                $keys[] = self::physicalKey((string) $host['hostid'], $itemKey);
            }
            else { throw new \InvalidArgumentException('Unsupported output mode.'); }
        }
        catch (\Throwable $e) {
            if ($strict) { throw new \InvalidArgumentException($e->getMessage(), 0, $e); }
        }
        return array_values(array_unique($keys));
    }

    /** Return the conflicting rule, or null. The candidate may be an unsaved draft. */
    public function findConflict(array $candidate, array $rules, array $settings = []): ?array {
        $wanted = $this->keys($candidate, $settings, (bool) ($candidate['enabled'] ?? true));
        foreach ($rules as $rule) {
            if ((string) ($candidate['id'] ?? '') !== '' && (string) ($candidate['id'] ?? '') === (string) ($rule['id'] ?? '')) { continue; }
            if (array_intersect($wanted, $this->keys((array) $rule, $settings, false)) !== []) { return (array) $rule; }
        }
        return null;
    }

    /** Mirrors the provisioner's stable identity, including selector-based hosts. */
    public static function automaticKey(array $rule): ?string {
        $stored = trim((string) ($rule['output']['receiver_hostset'] ?? ''));
        if ($stored !== '') { return $stored; }
        $selector = (array) ($rule['source_selector'] ?? []);
        if (($selector['mode'] ?? '') === 'group') {
            return substr(hash('sha256', 'group:'.json_encode([
                $selector['groupid'] ?? '', $selector['trigger_name'] ?? '', $selector['tag'] ?? '', $selector['tag_value'] ?? ''
            ])), 0, 12);
        }
        $ids = ReceiverProvisioner::conditionHostIds((array) ($rule['conditions'] ?? []));
        return $ids === [] ? null : ReceiverProvisioner::hostsetKey($ids);
    }

    public static function physicalKey(string $hostid, string $itemKey): string {
        return 'item:'.json_encode([$hostid, $itemKey]);
    }

    private function host(string $id, string $name): array {
        if ($id === '' && $name === '') { throw new \InvalidArgumentException('The output host is not set.'); }
        $params = ['output' => ['hostid', 'host', 'status'], 'limit' => 2];
        if ($id !== '') { $params['hostids'] = [$id]; }
        else { $params['filter'] = ['host' => $name]; }
        $rows = $this->api('host.get', $params);
        if (count($rows) !== 1) { throw new \InvalidArgumentException('The output host is missing, inaccessible or ambiguous. Select it again.'); }
        return $rows[0];
    }

    private function api(string $method, array $params): array {
        $key = $method.' '.json_encode($params);
        if (!array_key_exists($key, $this->cache)) { $this->cache[$key] = array_values((array) ($this->call)($method, $params)); }
        return $this->cache[$key];
    }
}
