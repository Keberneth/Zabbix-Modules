<?php
declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/Util.php';

/** One definition contract for saving, previewing and importing rules. No writes. */
final class RuleDefinition {
    public static function normalize(array $rule, string $kind = 'correlation'): array {
        if (!in_array($kind, ['correlation', 'severity'], true)) {
            throw new \InvalidArgumentException('Unknown rule type.');
        }
        $name = trim((string) ($rule['name'] ?? ''));
        if ($name === '' || strlen($name) > 255) {
            throw new \InvalidArgumentException('Enter a rule name of at most 255 bytes.');
        }
        $selector = self::selector((array) ($rule['source_selector'] ?? []));
        $conditions = [];
        foreach ($selector['mode'] === 'group' ? [] : (array) ($rule['conditions'] ?? []) as $c) {
            $c = (array) $c;
            $hostid = trim((string) ($c['hostid'] ?? ''));
            $triggerid = trim((string) ($c['triggerid'] ?? ''));
            if ($hostid === '' && $triggerid === '' && empty($c['host']) && empty($c['trigger'])) { continue; }
            if (!ctype_digit($hostid) || !ctype_digit($triggerid)) {
                throw new \InvalidArgumentException('Each source needs a host and trigger selected from the search results.');
            }
            $conditions[$triggerid] = [
                'hostid' => $hostid, 'host' => Util::cleanString($c['host'] ?? '', 255),
                'triggerid' => $triggerid, 'trigger' => Util::cleanString($c['trigger'] ?? '', 2048)
            ];
        }
        $conditions = array_values($conditions);
        if (count($conditions) > 100) { throw new \InvalidArgumentException('A rule supports at most 100 distinct source triggers.'); }
        $dynamic = $selector['mode'] === 'group';
        if (!$dynamic && count($conditions) < ($kind === 'severity' ? 1 : 2)) {
            throw new \InvalidArgumentException($kind === 'severity'
                ? 'Select at least one source trigger.' : 'Select at least two distinct source triggers.');
        }
        $out = [
            'id' => trim((string) ($rule['id'] ?? '')) ?: CorrelationStore::generateId(),
            'name' => $name, 'description' => Util::cleanString($rule['description'] ?? '', 4096),
            'enabled' => Util::truthy($rule['enabled'] ?? true), 'conditions' => $conditions,
            'source_selector' => $selector,
            'activation_seconds' => self::seconds($rule['activation_seconds'] ?? 0),
            'recovery_seconds' => self::seconds($rule['recovery_seconds'] ?? 0),
            'updated_at' => time(), 'updated_at_iso' => gmdate('c')
        ];
        if ($kind === 'correlation') {
            $o = (array) ($rule['output'] ?? []);
            $mode = (string) ($o['mode'] ?? 'receiver_lld');
            if (!in_array($mode, ['receiver_lld', 'existing_item'], true)) { throw new \InvalidArgumentException('Unsupported output mode.'); }
            $match = self::matchMode($o['match_mode'] ?? 'all');
            $output = [
                'mode' => $mode, 'match_mode' => $match,
                'match_value' => self::severity($o['match_value'] ?? 4), 'clear_value' => 0,
                'comment_correlation_problem' => Util::truthy($o['comment_correlation_problem'] ?? true),
                'comment_source_problems' => Util::truthy($o['comment_source_problems'] ?? true)
            ];
            if ($match === 'count') {
                $tiers = [];
                foreach ((array) ($o['severity_tiers'] ?? []) as $tier) {
                    $min = (int) ($tier['min'] ?? 0);
                    if ($min < 1 || $min > 100 || (!$dynamic && $min > count($conditions))) {
                        throw new \InvalidArgumentException('A severity threshold must be reachable by the distinct source triggers (1–100).');
                    }
                    if (isset($tiers[$min])) { throw new \InvalidArgumentException('Use one severity tier for each active-count threshold.'); }
                    $tiers[$min] = self::severity($tier['value'] ?? 0);
                }
                if (!$tiers || count($tiers) > 10) { throw new \InvalidArgumentException('Count mode needs between one and ten severity tiers.'); }
                ksort($tiers);
                $output['severity_tiers'] = [];
                foreach ($tiers as $min => $value) { $output['severity_tiers'][] = ['min' => $min, 'value' => $value]; }
            }
            if ($mode === 'existing_item') {
                foreach (['itemid', 'hostid', 'host', 'key'] as $key) { $output[$key] = trim((string) ($o[$key] ?? '')); }
                if ($output['itemid'] === '' && ($output['host'] === '' || $output['key'] === '')) {
                    throw new \InvalidArgumentException('Select the existing output trapper item.');
                }
            }
            else {
                $output['correlation_id'] = CorrelationStore::slug(trim((string) ($o['correlation_id'] ?? '')) ?: $name);
                $output['receiver_auto'] = Util::truthy($o['receiver_auto'] ?? false);
                $output['receiver_host'] = $output['receiver_auto'] ? '' : Util::cleanString($o['receiver_host'] ?? '', 128);
            }
            $out['output'] = $output;
        }
        else {
            $out['match_mode'] = self::matchMode($rule['match_mode'] ?? 'all');
            $out['min_active'] = max(1, (int) ($rule['min_active'] ?? 1));
            if ($out['match_mode'] === 'count' && ($out['min_active'] > 100 || (!$dynamic && $out['min_active'] > count($conditions)))) {
                throw new \InvalidArgumentException('The active-count threshold exceeds the number of distinct sources.');
            }
            $out['severity'] = self::severity($rule['severity'] ?? 4);
            foreach (['only_raise', 'comment_target', 'comment_source'] as $key) { $out[$key] = Util::truthy($rule[$key] ?? true); }
            $out['targets'] = [];
            foreach ((array) ($rule['targets'] ?? []) as $t) {
                $t = (array) $t;
                if (empty($t['trigger']) && empty($t['triggerid']) && empty($t['groupid'])) { continue; }
                $scope = (string) ($t['scope'] ?? 'host');
                if (!in_array($scope, ['host', 'hostgroup', 'all'], true)) { throw new \InvalidArgumentException('Unknown target scope.'); }
                $target = ['scope' => $scope, 'match' => ($t['match'] ?? 'exact') === 'contains' ? 'contains' : 'exact'];
                foreach (['trigger', 'triggerid', 'hostid', 'host', 'groupid', 'group'] as $key) { $target[$key] = trim((string) ($t[$key] ?? '')); }
                if ($scope === 'host' && $target['triggerid'] === '' && ($target['hostid'] === '' || $target['trigger'] === '')) {
                    throw new \InvalidArgumentException('Select a trigger for each host target.');
                }
                if ($scope !== 'host' && ($target['trigger'] === '' || ($scope === 'hostgroup' && $target['groupid'] === ''))) {
                    throw new \InvalidArgumentException('Choose the target group and exact problem name.');
                }
                if ($scope !== 'host') { $target['triggerid'] = $target['hostid'] = $target['host'] = ''; }
                if ($scope !== 'hostgroup') { $target['groupid'] = $target['group'] = ''; }
                $out['targets'][] = $target;
            }
            if (!$out['targets'] || count($out['targets']) > 100) { throw new \InvalidArgumentException('Select between one and 100 escalation targets.'); }
        }
        return $out;
    }

    private static function selector(array $s): array {
        if (($s['mode'] ?? 'static') === 'static') { return ['mode' => 'static']; }
        if (($s['mode'] ?? '') !== 'group' || !ctype_digit((string) ($s['groupid'] ?? '')) || trim((string) ($s['trigger_name'] ?? '')) === '') {
            throw new \InvalidArgumentException('Dynamic sources need a selected host group and an exact trigger name.');
        }
        return ['mode' => 'group', 'groupid' => (string) $s['groupid'],
            'group' => Util::cleanString($s['group'] ?? '', 255),
            'trigger_name' => Util::cleanString($s['trigger_name'], 2048),
            'tag' => Util::cleanString($s['tag'] ?? '', 255), 'tag_value' => Util::cleanString($s['tag_value'] ?? '', 255)];
    }

    private static function seconds($value): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 86400) {
            throw new \InvalidArgumentException('Timing must be a whole number of seconds between 0 and 86400.');
        }
        return (int) $value;
    }
    private static function severity($value): int {
        if ((int) $value < 1 || (int) $value > 5) { throw new \InvalidArgumentException('Select a severity from Information through Disaster.'); }
        return (int) $value;
    }
    private static function matchMode($mode): string {
        if (!in_array($mode, ['all', 'any', 'count'], true)) { throw new \InvalidArgumentException('Unknown match mode.'); }
        return $mode;
    }
    public static function selectedSeverities(array $output): array {
        $values = ($output['match_mode'] ?? 'all') === 'count'
            ? array_column((array) ($output['severity_tiers'] ?? []), 'value') : [$output['match_value'] ?? 4];
        $values = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $v): bool => $v >= 1 && $v <= 5)));
        sort($values);
        return $values;
    }
}
