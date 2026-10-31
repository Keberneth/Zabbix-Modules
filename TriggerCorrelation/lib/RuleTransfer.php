<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/RuleDefinition.php';
require_once __DIR__.'/OutputIdentity.php';

/** Portable definitions only: no credentials, runtime state or foreign ids. */
final class RuleTransfer {
    public const FORMAT = 'trigger-correlation-rules';
    public const VERSION = 1;
    private $call;
    private array $cache = [];
    private OutputIdentity $outputIdentity;

    public function __construct(callable $call) {
        $this->call = $call;
        $this->outputIdentity = new OutputIdentity(fn(string $method, array $params): array => $this->api($method, $params));
    }

    public function export(array $config): array {
        $document = ['format' => self::FORMAT, 'version' => self::VERSION, 'rules' => [], 'severity_rules' => []];
        foreach (['rules', 'severity_rules'] as $key) {
            foreach ((array) ($config[$key] ?? []) as $rule) {
                $document[$key][] = $this->portable((array) $rule, $key === 'severity_rules');
            }
        }
        return $document;
    }

    /** Resolve every reference, then validate the entire batch before any write. */
    public function prepare(array $document, array $config, string $conflict = 'skip'): array {
        if (($document['format'] ?? '') !== self::FORMAT || ($document['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('This is not a supported Trigger Correlation rule export.');
        }
        if (!in_array($conflict, ['skip', 'rename'], true)) {
            throw new \InvalidArgumentException('Choose skip or rename for conflicts.');
        }
        $prepared = ['rules' => [], 'severity_rules' => []];
        $preview = ['rules_count' => 0, 'severity_rules_count' => 0, 'conflicts' => [], 'warnings' => [], 'errors' => []];
        $total = 0;
        foreach (['rules', 'severity_rules'] as $key) {
            if (!isset($document[$key]) || !is_array($document[$key])) {
                throw new \InvalidArgumentException('The export must contain both rule lists.');
            }
            $total += count($document[$key]);
        }
        if ($total > 200) {
            throw new \InvalidArgumentException('Import at most 200 rules in one batch.');
        }
        foreach (['rules', 'severity_rules'] as $key) {
            $existing = array_values((array) ($config[$key] ?? []));
            $names = [];
            foreach ($existing as $r) {
                $names[strtolower(trim((string) ($r['name'] ?? '')))] = true;
            }
            foreach ($document[$key] as $index => $input) {
                $label = is_array($input) ? trim((string) ($input['name'] ?? '')) : '';
                $label = $label !== '' ? $label : 'Rule '.($index + 1);
                try {
                    if (!is_array($input)) {
                        throw new \InvalidArgumentException('Rule must be an object.');
                    }
                    $nameKey = strtolower(trim((string) ($input['name'] ?? '')));
                    if (isset($names[$nameKey])) {
                        $preview['conflicts'][] = $label.' already exists. '.($conflict === 'skip' ? 'Skipped.' : 'A new name will be used.');
                        if ($conflict === 'skip') {
                            continue;
                        }
                        $base = (string) $input['name'];
                        $n = 1;
                        do {
                            $input['name'] = $base.' (imported'.($n > 1 ? ' '.$n : '').')';
                            $n++;
                        } while (isset($names[strtolower($input['name'])]));
                    }
                    $rule = $this->resolve($input, $key === 'severity_rules');
                    $mappedSources = $rule['conditions'];
                    $rule = RuleDefinition::normalize($rule, $key === 'severity_rules' ? 'severity' : 'correlation');
                    if (($rule['source_selector']['mode'] ?? '') === 'group') {
                        // The general normalizer discards browser member fields.
                        // These members came from the destination API above.
                        $rule['conditions'] = $mappedSources;
                    }
                    $rule['enabled'] = false;
                    if ($key === 'rules') {
                        $collision = $this->outputCollision($rule, array_merge($existing, $prepared[$key]), $config);
                        if ($collision !== '') {
                            $preview['conflicts'][] = $label.' writes to the same output as '.$collision.'.';
                            if ($conflict === 'skip') {
                                continue;
                            }
                            if (($rule['output']['mode'] ?? '') === 'existing_item') {
                                throw new \InvalidArgumentException('The output item is already used. Select a different output item after resolving this conflict; item keys cannot be renamed by import.');
                            }
                            $keyTemplate = !empty($rule['output']['receiver_auto']) ? 'trigger.correlation.state[%s]'
                                : ((string) ($config['settings']['receiver_state_key_template'] ?? '') ?: 'trigger.correlation.state[%s]');
                            if (strpos($keyTemplate, '%s') === false) {
                                throw new \InvalidArgumentException('The configured state item key is fixed. Renaming the Correlation ID cannot avoid this output collision.');
                            }
                            $base = $rule['output']['correlation_id'];
                            $n = 1;
                            do {
                                $suffix = '_imported'.($n > 1 ? '_'.$n : '');
                                $rule['output']['correlation_id'] = substr($base, 0, 120 - strlen($suffix)).$suffix;
                                $n++;
                                if ($n > 1000) { throw new \InvalidArgumentException('Could not find an unused output ID. Choose a different Correlation ID before importing.'); }
                            } while ($this->outputCollision($rule, array_merge($existing, $prepared[$key]), $config) !== '');
                            $preview['warnings'][] = $label.': Correlation ID changed to '.$rule['output']['correlation_id'].'.';
                        }
                    }
                    $names[strtolower($rule['name'])] = true;
                    $prepared[$key][] = $rule;
                }
                catch (\Throwable $e) {
                    $preview['errors'][] = $label.': '.$e->getMessage();
                }
            }
            $preview[$key === 'rules' ? 'rules_count' : 'severity_rules_count'] = count($prepared[$key]);
        }
        if ($prepared['rules'] !== [] || $prepared['severity_rules'] !== []) {
            $preview['warnings'][] = 'Imported rules are disabled. Review, enable and save each rule to provision its output and activate it.';
        }
        $candidate = $config;
        foreach (['rules', 'severity_rules'] as $key) {
            $candidate[$key] = array_merge((array) ($config[$key] ?? []), $prepared[$key]);
        }
        $usage = CorrelationStore::storageUsage($candidate);
        $preview['storage'] = $usage;
        if ((int) $usage['bytes'] > (int) $usage['max_bytes']) {
            $preview['errors'][] = 'These rules exceed the available module configuration storage. Import a smaller batch.';
        }
        return ['preview' => $preview, 'rules' => $prepared['rules'], 'severity_rules' => $prepared['severity_rules']];
    }

    private function portable(array $rule, bool $severity): array {
        $out = self::pick($rule, ['name', 'description', 'activation_seconds', 'recovery_seconds']);
        $out['enabled'] = false;
        $out['conditions'] = [];
        foreach (($rule['source_selector']['mode'] ?? '') === 'group' ? [] : (array) ($rule['conditions'] ?? []) as $condition) {
            $out['conditions'][] = $this->portableCondition((array) $condition);
        }
        if (($rule['source_selector']['mode'] ?? '') === 'group') {
            // Group membership is resolved again on the destination; old member
            // snapshots must not require hosts that have since left the group.
            $out['conditions'] = [];
            $selector = (array) $rule['source_selector'];
            $out['source_selector'] = self::pick($selector, ['mode', 'group', 'trigger_name', 'tag', 'tag_value']);
            $groups = $this->api('hostgroup.get', ['output' => ['groupid', 'name'], 'groupids' => [(string) ($selector['groupid'] ?? '')]]);
            if (count($groups) === 1) {
                $out['source_selector']['group'] = (string) $groups[0]['name'];
            }
        }
        if ($severity) {
            $out += self::pick($rule, ['match_mode', 'min_active', 'severity', 'only_raise', 'comment_target', 'comment_source']);
            $out['targets'] = [];
            foreach ((array) ($rule['targets'] ?? []) as $target) {
                $t = self::pick((array) $target, ['scope', 'host', 'trigger', 'group', 'match']);
                if (($t['scope'] ?? 'host') === 'host') {
                    $t = array_merge($t, $this->portableCondition((array) $target));
                }
                elseif (($t['scope'] ?? '') === 'hostgroup') {
                    $groups = $this->api('hostgroup.get', ['output' => ['groupid', 'name'], 'groupids' => [(string) ($target['groupid'] ?? '')]]);
                    if (count($groups) === 1) {
                        $t['group'] = (string) $groups[0]['name'];
                    }
                }
                $out['targets'][] = $t;
            }
        }
        else {
            $output = (array) ($rule['output'] ?? []);
            $out['output'] = self::pick($output, ['mode', 'receiver_auto', 'receiver_host', 'correlation_id', 'match_mode', 'match_value', 'clear_value', 'severity_tiers', 'comment_correlation_problem', 'comment_source_problems', 'host', 'key']);
            if (!empty($output['receiver_auto'])) {
                unset($out['output']['receiver_host']);
            }
            elseif (($output['mode'] ?? '') === 'existing_item' && !empty($output['itemid'])) {
                $items = $this->api('item.get', ['output' => ['itemid', 'key_'], 'selectHosts' => ['host'], 'itemids' => [(string) $output['itemid']]]);
                if (count($items) === 1) {
                    $out['output']['host'] = (string) ($items[0]['hosts'][0]['host'] ?? ($output['host'] ?? ''));
                    $out['output']['key'] = (string) $items[0]['key_'];
                }
            }
        }
        return $out;
    }

    private function portableCondition(array $condition): array {
        $out = self::pick($condition, ['host', 'trigger']);
        if (!empty($condition['triggerid'])) {
            $rows = $this->api('trigger.get', ['output' => ['triggerid', 'description'], 'selectHosts' => ['hostid', 'host'], 'triggerids' => [(string) $condition['triggerid']]]);
            if (count($rows) === 1) {
                $out['trigger'] = (string) $rows[0]['description'];
                foreach ((array) ($rows[0]['hosts'] ?? []) as $host) {
                    if ((string) ($host['hostid'] ?? '') === (string) ($condition['hostid'] ?? '') || count($rows[0]['hosts']) === 1) {
                        $out['host'] = (string) $host['host'];
                        break;
                    }
                }
            }
        }
        return $out;
    }

    private function resolve(array $input, bool $severity): array {
        $rule = self::pick($input, ['name', 'description', 'activation_seconds', 'recovery_seconds']);
        $rule['id'] = CorrelationStore::generateId();
        $rule['enabled'] = false;
        $rule['conditions'] = [];
        $conditions = ($input['source_selector']['mode'] ?? '') === 'group' ? [] : (array) ($input['conditions'] ?? []);
        if (count($conditions) > 100) {
            throw new \InvalidArgumentException('At most 100 source conditions are supported per rule.');
        }
        foreach ($conditions as $condition) {
            $rule['conditions'][] = $this->resolveCondition((array) $condition);
        }
        if (($input['source_selector']['mode'] ?? '') === 'group') {
            $selector = (array) $input['source_selector'];
            $group = $this->resolveGroup((string) ($selector['group'] ?? ''));
            $rule['source_selector'] = self::pick($selector, ['mode', 'trigger_name', 'tag', 'tag_value']);
            $rule['source_selector'] += ['groupid' => (string) $group['groupid'], 'group' => (string) $group['name']];
            $params = ['output' => ['triggerid', 'description'], 'selectHosts' => ['hostid', 'host', 'name'],
                'groupids' => [(string) $group['groupid']], 'filter' => ['description' => (string) ($selector['trigger_name'] ?? '')], 'limit' => 101];
            if (trim((string) ($selector['tag'] ?? '')) !== '') {
                $params['tags'] = [['tag' => (string) $selector['tag'], 'value' => (string) ($selector['tag_value'] ?? ''), 'operator' => 1]];
            }
            $rows = $this->api('trigger.get', $params);
            if ($rows === [] || count($rows) > 100) {
                throw new \InvalidArgumentException('The dynamic group selector must resolve to between one and 100 local triggers.');
            }
            $rule['conditions'] = [];
            $seen = [];
            foreach ($rows as $row) {
                $host = $row['hosts'][0] ?? [];
                $hostid = (string) ($host['hostid'] ?? '');
                if ($hostid === '' || isset($seen[$hostid])) {
                    throw new \InvalidArgumentException('The dynamic selector is ambiguous: use one matching trigger per host.');
                }
                $seen[$hostid] = true;
                $rule['conditions'][] = ['hostid' => $hostid, 'host' => (string) (($host['name'] ?? '') ?: ($host['host'] ?? '')),
                    'triggerid' => (string) $row['triggerid'], 'trigger' => (string) $row['description']];
            }
        }
        if ($severity) {
            $rule += self::pick($input, ['match_mode', 'min_active', 'severity', 'only_raise', 'comment_target', 'comment_source']);
            $rule['targets'] = [];
            if (count((array) ($input['targets'] ?? [])) > 100) {
                throw new \InvalidArgumentException('At most 100 targets are supported per imported rule.');
            }
            foreach ((array) ($input['targets'] ?? []) as $target) {
                $t = self::pick((array) $target, ['scope', 'trigger', 'match']);
                if (($t['scope'] ?? 'host') === 'host') {
                    $t = array_merge($t, $this->resolveCondition((array) $target));
                }
                elseif ($t['scope'] === 'hostgroup') {
                    $group = $this->resolveGroup((string) ($target['group'] ?? ''));
                    $t += ['groupid' => (string) $group['groupid'], 'group' => (string) $group['name']];
                }
                $rule['targets'][] = $t;
            }
        }
        else {
            $output = (array) ($input['output'] ?? []);
            $rule['output'] = self::pick($output, ['mode', 'receiver_auto', 'correlation_id', 'match_mode', 'match_value', 'clear_value', 'severity_tiers', 'comment_correlation_problem', 'comment_source_problems']);
            if (($output['mode'] ?? 'receiver_lld') === 'existing_item') {
                $host = $this->resolveHost((string) ($output['host'] ?? ''));
                $key = trim((string) ($output['key'] ?? ''));
                if ($key === '') {
                    throw new \InvalidArgumentException('Output item key is missing.');
                }
                $items = $this->api('item.get', ['output' => ['itemid', 'hostid', 'key_', 'type', 'value_type', 'status'], 'hostids' => [(string) $host['hostid']], 'filter' => ['key_' => $key], 'limit' => 2]);
                if (count($items) !== 1 || (int) $items[0]['type'] !== 2 || !in_array((int) $items[0]['value_type'], [0, 3], true) || (int) $items[0]['status'] !== 0) {
                    throw new \InvalidArgumentException('Output item '.$host['host'].': '.$key.' must resolve to one enabled numeric trapper item.');
                }
                $rule['output'] += ['itemid' => (string) $items[0]['itemid'], 'hostid' => (string) $host['hostid'], 'host' => (string) $host['host'], 'key' => $key];
            }
            elseif (empty($output['receiver_auto'])) {
                $host = $this->resolveHost((string) ($output['receiver_host'] ?? ''));
                $rule['output']['receiver_host'] = (string) $host['host'];
            }
        }
        return $rule;
    }

    private function resolveCondition(array $condition): array {
        $host = $this->resolveHost((string) ($condition['host'] ?? ''));
        $name = trim((string) ($condition['trigger'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('A trigger name is missing for '.$host['host'].'.');
        }
        $rows = $this->api('trigger.get', ['output' => ['triggerid', 'description'], 'hostids' => [(string) $host['hostid']], 'filter' => ['description' => $name], 'limit' => 2]);
        if (count($rows) !== 1) {
            throw new \InvalidArgumentException('Trigger '.$host['host'].': '.$name.' '.($rows === [] ? 'was not found.' : 'is ambiguous; give the triggers distinct names before importing.'));
        }
        return ['hostid' => (string) $host['hostid'], 'host' => (string) (($host['name'] ?? '') ?: $host['host']), 'triggerid' => (string) $rows[0]['triggerid'], 'trigger' => (string) $rows[0]['description']];
    }

    private function resolveHost(string $name): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A host name is missing.');
        }
        $rows = $this->api('host.get', ['output' => ['hostid', 'host', 'name'], 'filter' => ['host' => $name], 'limit' => 2]);
        if (count($rows) !== 1) {
            throw new \InvalidArgumentException('Technical host name '.$name.' '.($rows === [] ? 'was not found.' : 'is ambiguous.'));
        }
        return $rows[0];
    }

    private function resolveGroup(string $name): array {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A host group name is missing.');
        }
        $rows = $this->api('hostgroup.get', ['output' => ['groupid', 'name'], 'filter' => ['name' => $name], 'limit' => 2]);
        if (count($rows) !== 1) {
            throw new \InvalidArgumentException('Host group '.$name.' '.($rows === [] ? 'was not found.' : 'is ambiguous.'));
        }
        return $rows[0];
    }

    private function outputCollision(array $rule, array $others, array $config): string {
        $candidate = $this->collisionRule($rule, $others, $config);
        $resolvedOthers = array_map(fn($other): array => $this->collisionRule((array) $other, $others, $config), $others);
        $other = $this->outputIdentity->findConflict($candidate, $resolvedOthers, (array) ($config['settings'] ?? []));
        return $other === null ? '' : (string) ($other['name'] ?? 'another rule');
    }

    /** Attach known receiver identity only to the read-only collision candidate. */
    private function collisionRule(array $rule, array $others, array $config): array {
        if (empty($rule['output']['receiver_auto']) || !empty($rule['output']['receiver_hostid'])) { return $rule; }
        $key = OutputIdentity::automaticKey($rule);
        foreach ($others as $other) {
            if (!empty($other['output']['receiver_auto']) && !empty($other['output']['receiver_hostid'])
                    && OutputIdentity::automaticKey((array) $other) === $key) {
                $rule['output']['receiver_hostid'] = (string) $other['output']['receiver_hostid'];
                return $rule;
            }
        }
        $managed = array_values((array) ($config['settings']['managed_auto_hostids'] ?? []));
        if ($key !== null && $managed !== []) {
            foreach ($this->api('host.get', ['output' => ['hostid'], 'hostids' => $managed, 'selectTags' => ['tag', 'value']]) as $host) {
                foreach ((array) ($host['tags'] ?? []) as $tag) {
                    if (($tag['tag'] ?? '') === 'tc.hostset' && ($tag['value'] ?? '') === $key) {
                        $rule['output']['receiver_hostid'] = (string) $host['hostid'];
                        return $rule;
                    }
                }
            }
        }
        return $rule;
    }

    private function api(string $method, array $params): array {
        $key = $method.' '.json_encode($params);
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = array_values((array) ($this->call)($method, $params));
        }
        return $this->cache[$key];
    }

    private static function pick(array $source, array $keys): array {
        return array_intersect_key($source, array_flip($keys));
    }
}
