<?php
declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/ZabbixApiClient.php';

/** Shared, read-only source inspection and transition timing. */
final class RuleEngine {
    private ZabbixApiClient $api;
    private array $settings;
    private array $cache = [];

    public function __construct(ZabbixApiClient $api, array $settings = []) {
        $this->api = $api;
        $this->settings = $settings;
    }

    public function resolveSources(array $rule): array {
        $selector = (array) ($rule['source_selector'] ?? []);
        if (($selector['mode'] ?? 'static') !== 'group') {
            $conditions = [];
            foreach ((array) ($rule['conditions'] ?? []) as $c) { $conditions[(string) ($c['triggerid'] ?? '')] = (array) $c; }
            return array_values($conditions);
        }
        $groupid = (string) ($selector['groupid'] ?? '');
        $groups = $this->api->call('hostgroup.get', ['output' => ['groupid'], 'groupids' => [$groupid]]);
        if (!$groups) { throw new \RuntimeException('The evaluation token cannot see the dynamic source group, or it was removed.'); }
        $params = ['output' => ['triggerid', 'description'], 'groupids' => [$groupid],
            'filter' => ['description' => [(string) ($selector['trigger_name'] ?? '')]],
            'selectHosts' => ['hostid', 'host', 'name'], 'limit' => 101];
        if (trim((string) ($selector['tag'] ?? '')) !== '') {
            $params['tags'] = [['tag' => (string) $selector['tag'], 'value' => (string) ($selector['tag_value'] ?? ''), 'operator' => 1]];
        }
        $rows = (array) $this->api->call('trigger.get', $params);
        if (count($rows) > 100) { throw new \RuntimeException('Dynamic selection exceeds 100 triggers. Narrow the group, trigger name or tag. No partial selection was evaluated.'); }
        $conditions = [];
        $hosts = [];
        foreach ($rows as $row) {
            $host = (array) ($row['hosts'][0] ?? []);
            $hostid = (string) ($host['hostid'] ?? '');
            if ($hostid === '') { throw new \RuntimeException('A dynamic trigger has no visible host.'); }
            if (isset($hosts[$hostid])) { throw new \RuntimeException('More than one matching trigger exists on '.$host['host'].'. Narrow the selector with a tag.'); }
            $hosts[$hostid] = true;
            $conditions[] = ['hostid' => $hostid, 'host' => (string) (($host['name'] ?? '') ?: ($host['host'] ?? $hostid)),
                'triggerid' => (string) $row['triggerid'], 'trigger' => (string) $row['description']];
        }
        usort($conditions, static fn(array $a, array $b): int => strcmp($a['triggerid'], $b['triggerid']));
        if (!$conditions) { throw new \RuntimeException('No visible triggers match the dynamic source selector. The previous result is retained.'); }
        return $conditions;
    }

    public function inspectSources(array $rule): array {
        $conditions = [];
        $results = [];
        $errors = [];
        $active = 0;
        try {
            $conditions = $this->resolveSources($rule);
            if (!$conditions) { throw new \RuntimeException('No source triggers are selected.'); }
            if (count($conditions) > 100) { throw new \RuntimeException('A rule supports at most 100 source triggers.'); }
            $ids = array_values(array_unique(array_map(static fn(array $c): string => (string) ($c['triggerid'] ?? ''), $conditions)));
            sort($ids);
            $key = implode(',', $ids);
            if (!isset($this->cache[$key])) {
                $triggers = [];
                foreach ((array) $this->api->call('trigger.get', ['output' => ['triggerid', 'description', 'status', 'state', 'error'],
                    'triggerids' => $ids, 'selectHosts' => ['hostid', 'host', 'name', 'status'], 'selectItems' => ['itemid', 'status']]) as $t) {
                    $triggers[(string) $t['triggerid']] = $t;
                }
                $problems = [];
                foreach ((array) $this->api->call('problem.get', ['output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged', 'suppressed', 'cause_eventid'],
                    'objectids' => $ids, 'source' => 0, 'object' => 0, 'recent' => false, 'selectTags' => 'extend']) as $p) {
                    $problems[(string) $p['objectid']][] = $p;
                }
                $this->cache[$key] = [$triggers, $problems];
            }
            [$triggers, $problems] = $this->cache[$key];
            $now = time();
            foreach ($conditions as $c) {
                $tid = (string) ($c['triggerid'] ?? '');
                $t = $triggers[$tid] ?? null;
                $row = $c + ['active' => false, 'available' => true, 'reason' => 'No active problem', 'problems' => [], 'excluded' => []];
                $host = null;
                foreach ((array) ($t['hosts'] ?? []) as $h) { if ((string) $h['hostid'] === (string) ($c['hostid'] ?? '')) { $host = $h; break; } }
                if ($t === null || $host === null) {
                    $row['available'] = false;
                    $row['reason'] = 'Source unavailable to the evaluation token or removed';
                }
                elseif ((int) ($t['status'] ?? 0) !== 0 || (int) ($host['status'] ?? 0) !== 0 || (int) ($t['state'] ?? 0) !== 0
                        || array_filter((array) ($t['items'] ?? []), static fn(array $i): bool => (int) ($i['status'] ?? 0) !== 0)) {
                    $row['available'] = false;
                    $row['reason'] = 'Source or item is disabled, its host is unmonitored, or its trigger is unknown';
                }
                if (!$row['available']) {
                    $errors[] = ($c['host'] ?? $c['hostid']).': '.($c['trigger'] ?? $tid).' — '.$row['reason'];
                    $results[] = $row;
                    continue;
                }
                $row['host'] = (string) (($host['name'] ?? '') ?: ($host['host'] ?? $row['host']));
                $row['trigger'] = (string) ($t['description'] ?? $row['trigger']);
                foreach ($problems[$tid] ?? [] as $p) {
                    $reason = '';
                    if (!empty($this->settings['ignore_suppressed']) && !empty($p['suppressed'])) { $reason = 'Suppressed'; }
                    elseif (($this->settings['ignore_symptoms'] ?? true) && !empty($p['cause_eventid'])) { $reason = 'Symptom excluded'; }
                    elseif ($now - (int) ($p['clock'] ?? 0) < max(0, (int) ($this->settings['min_active_seconds'] ?? 0))) { $reason = 'Waiting for minimum source problem age'; }
                    $p['host'] = $row['host']; $p['hostid'] = (string) $c['hostid'];
                    $p['trigger'] = $row['trigger']; $p['triggerid'] = $tid;
                    $p['age_seconds'] = max(0, $now - (int) ($p['clock'] ?? 0));
                    if ($reason !== '') { $row['excluded'][] = $p + ['reason' => $reason]; }
                    else { $row['problems'][] = $p; }
                }
                $row['active'] = count($row['problems']) > 0;
                if ($row['active']) { $active++; $row['reason'] = 'Active problem'; }
                elseif ($row['excluded']) { $row['reason'] = implode('; ', array_unique(array_column($row['excluded'], 'reason'))); }
                $row['problem_count'] = count($row['problems']);
                $results[] = $row;
            }
        }
        catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        return ['conditions' => $conditions, 'results' => $results, 'active_count' => $active, 'total' => count($conditions),
            'valid' => !$errors, 'errors' => $errors, 'source_signature' => hash('sha256', implode(',', array_column($conditions, 'triggerid')))];
    }

    public static function timedState(array $rule, int $desired, int $now): array {
        $previous = (int) ($rule['last_state'] ?? 0);
        $recovering = $desired < $previous;
        $delay = max(0, (int) ($rule[$recovering ? 'recovery_seconds' : 'activation_seconds'] ?? 0));
        // Disabling a rule is an explicit request to clear it, not a timed recovery.
        if (isset($rule['enabled']) && !$rule['enabled']) { $desired = 0; $delay = 0; }
        $pendingSince = (int) ($rule['pending_since'] ?? 0);
        if ($desired === $previous || $delay === 0) {
            return ['state' => $desired, 'candidate_state' => $desired, 'pending_state' => null, 'pending_since' => 0,
                'pending_seconds' => 0, 'status' => $desired === 0 ? 'clear' : 'applied'];
        }
        if (($rule['pending_state'] ?? null) !== $desired || $pendingSince <= 0 || $pendingSince > $now
                || (!empty($rule['last_evaluated']) && $now - (int) $rule['last_evaluated'] > 180)
                || (isset($rule['_source_signature']) && ($rule['source_signature'] ?? '') !== $rule['_source_signature'])) { $pendingSince = $now; }
        $remaining = max(0, $delay - ($now - $pendingSince));
        return ['state' => $remaining > 0 ? $previous : $desired, 'candidate_state' => $desired,
            'pending_state' => $remaining > 0 ? $desired : null, 'pending_since' => $remaining > 0 ? $pendingSince : 0,
            'pending_seconds' => $remaining, 'status' => $remaining > 0 ? ($recovering ? 'pending_recovery' : 'pending_activation') : ($desired === 0 ? 'clear' : 'applied')];
    }
}
