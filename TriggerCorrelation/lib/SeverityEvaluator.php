<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/ZabbixApiClient.php';

/**
 * Severity-escalation evaluator — the second half of the module.
 *
 * Where the correlation evaluator raises a NEW synthetic problem when a set of
 * source triggers are active together, this one leaves the topology alone and
 * instead RAISES THE SEVERITY of one or more existing problems while the same
 * kind of source condition holds, then restores their original severity when it
 * clears. It works purely through event.acknowledge (Update problem → Change
 * severity, action bitmask 8), so it edits each problem's manual event severity,
 * never the trigger's configured priority — fully reversible and re-appliable.
 *
 * Example: when "demo-sccm01: SSMS service is down" is active, raise
 * "Current month CU not installed" to High on a specific host, a host group, or
 * every host that currently has that problem.
 *
 * It shares the same source-condition primitives, settings and token API
 * transport as the correlation evaluator, but reads/writes its own
 * `severity_rules` set so the correlation feature is completely untouched.
 */
final class SeverityEvaluator {

    private CorrelationStore $store;
    private array $config;
    private array $settings;
    private ZabbixApiClient $api;

    public function __construct(CorrelationStore $store) {
        $this->store = $store;
        $this->config = $store->load();
        $this->settings = (array) ($this->config['settings'] ?? []);
        // Runs from the unattended HTTP-agent endpoint (no user session) and uses
        // event.acknowledge, so it must use the token HTTP client — the same as the
        // correlation evaluator.
        $this->api = ZabbixApiClient::fromConfig($this->settings);
    }

    public function evaluate(?string $ruleId = null): array {
        $started = time();
        $rules = array_values((array) ($this->config['severity_rules'] ?? []));
        $selected = [];
        foreach ($rules as $rule) {
            if ($ruleId !== null && (string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $selected[] = $rule;
        }

        if ($ruleId !== null && $selected === []) {
            throw new \RuntimeException('Severity escalation rule not found.');
        }

        $summary = [
            'type' => 'severity',
            'evaluated_at' => gmdate('c', $started),
            'rules_total' => count($rules),
            'rules_evaluated' => count($selected),
            'persist_error' => null,
            'rules' => []
        ];

        $runtimeByRuleId = [];
        foreach ($selected as $rule) {
            $result = $this->evaluateRule($rule, $started);
            $id = (string) ($rule['id'] ?? '');
            if ($id !== '') {
                $runtimeByRuleId[$id] = [
                    'last_state' => $result['state'],
                    'last_error' => $result['error'],
                    'last_evaluated' => $started,
                    'last_evaluated_iso' => gmdate('c', $started),
                    'applied' => $result['applied'],
                    'last_comment_sig' => $result['comment_sig'],
                    'last_targets_count' => $result['targets_count']
                ];
            }
            $summary['rules'][] = [
                'id' => $id,
                'name' => (string) ($rule['name'] ?? $id),
                'state' => $result['state'],
                'active' => $result['active'],
                'escalated' => $result['escalated'],
                'restored' => $result['restored'],
                'targets_count' => $result['targets_count'],
                'error' => $result['error']
            ];
        }

        try {
            $this->store->saveSeverityRuntimeState($runtimeByRuleId);
        }
        catch (\Throwable $e) {
            $summary['persist_error'] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * Restore every severity this rule had raised, e.g. when the rule is deleted or
     * disabled from the UI. Best-effort; never throws. Returns the number reverted.
     */
    public function revertRule(array $rule): int {
        $name = (string) ($rule['name'] ?? '');
        $reverted = 0;
        foreach ($this->normalizeApplied($rule['applied'] ?? []) as $eventid => $info) {
            try {
                $this->api->setEventSeverity(
                    (string) $eventid,
                    (int) ($info['sev'] ?? 0),
                    '[TC severity] Restored original severity (escalation "'.$name.'" removed).'
                );
                $reverted++;
            }
            catch (\Throwable $e) {
                // The problem may already be resolved — nothing to restore.
            }
        }
        return $reverted;
    }

    private function evaluateRule(array $rule, int $clock): array {
        $name = (string) ($rule['name'] ?? ($rule['id'] ?? ''));
        $enabled = (bool) ($rule['enabled'] ?? true);
        $applied = $this->normalizeApplied($rule['applied'] ?? []);
        $commentSig = (string) ($rule['last_comment_sig'] ?? '');
        $state = (int) ($rule['last_state'] ?? 0);
        $error = '';
        $active = false;
        $escalated = 0;
        $restored = 0;
        $targetsCount = 0;

        try {
            $conditions = array_values((array) ($rule['conditions'] ?? []));
            if ($conditions === []) {
                throw new \RuntimeException('Severity escalation rule has no source trigger conditions.');
            }

            $activeCount = 0;
            $total = 0;
            $activeProblems = [];
            foreach ($conditions as $condition) {
                $total++;
                $cr = $this->conditionActive((array) $condition);
                if ($cr['active']) {
                    $activeCount++;
                    foreach ($cr['problems'] as $p) {
                        $activeProblems[] = $p;
                    }
                }
            }

            $active = $enabled && $this->conditionMet($rule, $activeCount, $total);

            if ($active) {
                $severity = max(1, min(5, (int) ($rule['severity'] ?? 4)));
                $onlyRaise = (bool) ($rule['only_raise'] ?? true);
                $commentTarget = (bool) ($rule['comment_target'] ?? true);
                $sourceLabel = $this->sourceLabel($activeProblems);

                $targets = $this->resolveTargets($rule);
                $targetsCount = count($targets);
                $newApplied = [];

                foreach ($targets as $target) {
                    $eventid = (string) $target['eventid'];
                    if ($eventid === '') {
                        continue;
                    }
                    $current = (int) $target['severity'];
                    $original = isset($applied[$eventid]) ? (int) $applied[$eventid]['sev'] : $current;
                    $needsChange = $onlyRaise ? ($current < $severity) : ($current !== $severity);

                    if ($needsChange) {
                        $message = $commentTarget
                            ? '[TC severity] Raised to '.self::sevText($severity).' by escalation "'.$name.'" because: '.$sourceLabel
                            : '';
                        try {
                            $this->api->setEventSeverity($eventid, $severity, $message);
                            $escalated++;
                            $newApplied[$eventid] = [
                                'sev' => $original,
                                'to' => $severity,
                                'host' => (string) $target['host'],
                                'name' => (string) $target['name']
                            ];
                        }
                        catch (\Throwable $e) {
                            // Keep any prior record so a transient API error does not
                            // make us forget we already raised (and must later restore).
                            if (isset($applied[$eventid])) {
                                $newApplied[$eventid] = $applied[$eventid];
                            }
                        }
                    }
                    elseif (isset($applied[$eventid])) {
                        // Already at/over target and we raised it before: keep the memory.
                        $newApplied[$eventid] = $applied[$eventid];
                    }
                    // else: already as-severe and we never touched it → leave it alone.
                }

                // Cross-link the SOURCE problems once per (source set → target set)
                // change, mirroring the correlation feature's source comments.
                $commentSig = $this->maybeCommentSources($rule, $name, $severity, $activeProblems, $newApplied, $commentSig);

                $applied = $newApplied;
                $state = 1;
            }
            else {
                // Condition no longer met (or rule disabled): restore every severity
                // we raised, then forget them.
                foreach ($applied as $eventid => $info) {
                    try {
                        $this->api->setEventSeverity(
                            (string) $eventid,
                            (int) ($info['sev'] ?? 0),
                            '[TC severity] Restored severity to '.self::sevText((int) ($info['sev'] ?? 0)).' (escalation "'.$name.'" cleared).'
                        );
                        $restored++;
                    }
                    catch (\Throwable $e) {
                        // Problem already resolved — nothing to restore.
                    }
                }
                $applied = [];
                $commentSig = '';
                $state = 0;
            }
        }
        catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'state' => $state,
            'active' => $active,
            'escalated' => $escalated,
            'restored' => $restored,
            'targets_count' => $targetsCount,
            'applied' => $applied,
            'comment_sig' => $commentSig,
            'error' => $error
        ];
    }

    /** Active problems for one source condition (same rules as correlation). */
    private function conditionActive(array $condition): array {
        $triggerid = trim((string) ($condition['triggerid'] ?? ''));
        $hostid = trim((string) ($condition['hostid'] ?? ''));
        $host = trim((string) ($condition['host'] ?? ''));
        $trigger = trim((string) ($condition['trigger'] ?? ''));

        if ($triggerid === '') {
            throw new \RuntimeException('A condition is missing its trigger selection for host '.$host.'.');
        }

        $problems = $this->api->activeProblemsForTrigger($triggerid, $hostid, $this->settings);
        $minActive = max(0, (int) ($this->settings['min_active_seconds'] ?? 0));
        $ignoreSymptoms = (bool) ($this->settings['ignore_symptoms'] ?? true);
        $now = time();
        $out = [];

        foreach ($problems as $problem) {
            $clock = (int) ($problem['clock'] ?? 0);
            if ($minActive > 0 && $clock > 0 && ($now - $clock) < $minActive) {
                continue;
            }
            $cause = (string) ($problem['cause_eventid'] ?? '');
            if ($ignoreSymptoms && $cause !== '' && $cause !== '0') {
                continue;
            }
            $out[] = [
                'eventid' => (string) ($problem['eventid'] ?? ''),
                'host' => $host,
                'trigger' => $trigger !== '' ? $trigger : (string) ($problem['name'] ?? ''),
                'severity' => (string) ($problem['severity'] ?? '')
            ];
        }

        return ['active' => count($out) > 0, 'problems' => $out];
    }

    private function conditionMet(array $rule, int $activeCount, int $total): bool {
        $mode = (string) ($rule['match_mode'] ?? 'all');
        if ($mode === 'any') {
            return $activeCount >= 1;
        }
        if ($mode === 'count') {
            return $activeCount >= max(1, (int) ($rule['min_active'] ?? 1));
        }
        return $total > 0 && $activeCount === $total;
    }

    /**
     * Resolve every active problem a rule's targets point at, deduped by event id.
     * Each target is a trigger plus a scope:
     *   host      → that exact trigger's active problem(s) (by trigger id)
     *   hostgroup → active problems with the same trigger NAME in a host group
     *   all       → active problems with the same trigger NAME on any host
     */
    private function resolveTargets(array $rule): array {
        $results = [];

        foreach ((array) ($rule['targets'] ?? []) as $target) {
            $target = (array) $target;
            $scope = (string) ($target['scope'] ?? 'host');
            $triggerName = trim((string) ($target['trigger'] ?? ''));
            $matchMode = (string) ($target['match'] ?? 'exact');

            $params = [
                // NOTE: problem.get in Zabbix 7.0 has NO selectHosts — the host is
                // resolved separately via trigger.get (by objectid) below.
                'output' => ['eventid', 'name', 'severity', 'objectid', 'clock'],
                'source' => 0,
                'object' => 0,
                'recent' => false,
                'sortfield' => ['eventid'],
                'sortorder' => 'DESC',
                'limit' => 200
            ];
            if ((bool) ($this->settings['ignore_suppressed'] ?? true)) {
                $params['suppressed'] = false;
            }
            if ((bool) ($this->settings['ignore_symptoms'] ?? true)) {
                $params['symptom'] = false;
            }

            $byTriggerId = false;
            if ($scope === 'host') {
                $triggerid = trim((string) ($target['triggerid'] ?? ''));
                if ($triggerid !== '') {
                    $params['objectids'] = [$triggerid];
                    $byTriggerId = true;
                }
                else {
                    $hostid = trim((string) ($target['hostid'] ?? ''));
                    if ($hostid !== '') {
                        $params['hostids'] = [$hostid];
                    }
                    if ($triggerName !== '') {
                        $params['search'] = ['name' => $triggerName];
                    }
                }
            }
            elseif ($scope === 'hostgroup') {
                $groupid = trim((string) ($target['groupid'] ?? ''));
                if ($groupid !== '') {
                    $params['groupids'] = [$groupid];
                }
                if ($triggerName !== '') {
                    $params['search'] = ['name' => $triggerName];
                }
            }
            else {
                if ($triggerName !== '') {
                    $params['search'] = ['name' => $triggerName];
                }
            }

            try {
                $problems = (array) $this->api->call('problem.get', $params);
            }
            catch (\Throwable $e) {
                continue;
            }

            foreach ($problems as $problem) {
                $pname = (string) ($problem['name'] ?? '');
                // For name-based scopes, problem.get 'search' is a substring match;
                // apply the chosen exact/contains rule precisely.
                if (!$byTriggerId && $triggerName !== '') {
                    if ($matchMode === 'exact') {
                        if (strcasecmp($pname, $triggerName) !== 0) {
                            continue;
                        }
                    }
                    elseif (stripos($pname, $triggerName) === false) {
                        continue;
                    }
                }

                $eventid = (string) ($problem['eventid'] ?? '');
                if ($eventid === '') {
                    continue;
                }
                $results[$eventid] = [
                    'eventid' => $eventid,
                    'name' => $pname,
                    'severity' => (string) ($problem['severity'] ?? ''),
                    'objectid' => (string) ($problem['objectid'] ?? ''),
                    // Seed with the target's own host (correct for "this host" scope);
                    // overwritten with the real per-problem host below.
                    'host' => (string) ($target['host'] ?? ''),
                    'hostid' => (string) ($target['hostid'] ?? '')
                ];
            }
        }

        // problem.get cannot selectHosts in Zabbix 7.0, so resolve every matched
        // problem's host in one trigger.get (by its objectid = trigger id) for the
        // comments and the rule-state display.
        $objectids = array_values(array_unique(array_filter(array_map(static function (array $r): string {
            return (string) ($r['objectid'] ?? '');
        }, $results))));
        if ($objectids !== []) {
            $hostMap = $this->hostLabelsForTriggers($objectids);
            foreach ($results as $eid => $r) {
                $oid = (string) ($r['objectid'] ?? '');
                if (isset($hostMap[$oid]) && $hostMap[$oid]['host'] !== '') {
                    $results[$eid]['host'] = $hostMap[$oid]['host'];
                    $results[$eid]['hostid'] = $hostMap[$oid]['hostid'];
                }
            }
        }

        return array_values($results);
    }

    /** Map trigger ids → host label/id (problem.get cannot selectHosts in 7.0). */
    private function hostLabelsForTriggers(array $triggerids): array {
        $triggerids = array_values(array_unique(array_filter(array_map('strval', $triggerids))));
        if ($triggerids === []) {
            return [];
        }
        try {
            $triggers = (array) $this->api->call('trigger.get', [
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'host', 'name'],
                'triggerids' => $triggerids
            ]);
        }
        catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($triggers as $trigger) {
            $tid = (string) ($trigger['triggerid'] ?? '');
            if ($tid === '') {
                continue;
            }
            $hosts = array_values((array) ($trigger['hosts'] ?? []));
            $host = $hosts[0] ?? [];
            $map[$tid] = [
                'host' => (string) (($host['name'] ?? '') ?: ($host['host'] ?? '')),
                'hostid' => (string) ($host['hostid'] ?? '')
            ];
        }
        return $map;
    }

    private function maybeCommentSources(array $rule, string $name, int $severity, array $activeProblems, array $applied, string $lastSig): string {
        if (!(bool) ($rule['comment_source'] ?? true)) {
            return $lastSig;
        }

        $sourceEventIds = array_values(array_unique(array_filter(array_map(static function ($p): string {
            return (string) ($p['eventid'] ?? '');
        }, $activeProblems))));
        sort($sourceEventIds);

        $targetEventIds = array_keys($applied);
        sort($targetEventIds);

        if ($sourceEventIds === [] || $targetEventIds === []) {
            return $lastSig;
        }

        $sig = sha1($severity.'|'.implode(',', $sourceEventIds).'|'.implode(',', $targetEventIds));
        if ($sig === $lastSig) {
            return $lastSig;
        }

        $targets = [];
        foreach ($applied as $info) {
            $label = (string) ($info['host'] ?? '').': '.(string) ($info['name'] ?? '');
            $targets[trim($label, ': ')] = true;
        }
        $targetList = implode('; ', array_keys($targets));
        $message = 'While this problem is active, escalation "'.$name.'" raises the severity of '
            .($targetList !== '' ? $targetList : count($applied).' problem(s)').' to '.self::sevText($severity).'.';

        $allPosted = true;
        foreach ($sourceEventIds as $eventid) {
            try {
                $this->api->addProblemComment($eventid, $message);
            }
            catch (\Throwable $e) {
                $allPosted = false;
            }
        }

        return $allPosted ? $sig : $lastSig;
    }

    private function sourceLabel(array $activeProblems): string {
        $parts = [];
        foreach ($activeProblems as $p) {
            $label = trim((string) ($p['host'] ?? '').': '.(string) ($p['trigger'] ?? ''), ': ');
            if ($label !== '') {
                $parts[$label] = true;
            }
        }
        return $parts === [] ? 'the correlation condition is active' : implode('; ', array_keys($parts));
    }

    /** Coerce a stored applied map ({eventid: {sev,...}}) into a clean array. */
    private function normalizeApplied($applied): array {
        if (!is_array($applied)) {
            return [];
        }
        $out = [];
        foreach ($applied as $eventid => $info) {
            $eventid = (string) $eventid;
            if ($eventid === '' || !is_array($info)) {
                continue;
            }
            $out[$eventid] = [
                'sev' => (int) ($info['sev'] ?? 0),
                'to' => (int) ($info['to'] ?? 0),
                'host' => (string) ($info['host'] ?? ''),
                'name' => (string) ($info['name'] ?? '')
            ];
        }
        return $out;
    }

    private static function sevText(int $severity): string {
        return match ($severity) {
            0 => 'Not classified',
            1 => 'Information',
            2 => 'Warning',
            3 => 'Average',
            4 => 'High',
            5 => 'Disaster',
            default => 'Unknown'
        };
    }
}
