<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\Util;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

/**
 * Live data for the Dashboard tab: every correlation with its source triggers
 * grouped by host and the problem it raised, every severity escalation with the
 * problems it is holding up, and the same triggers regrouped per host.
 *
 * Read-only (no writes, no evaluation), so like the other GET data endpoints it
 * runs without CSRF; Super Admin only. All reads use the in-process API under
 * the viewer's session — a handful of batched calls regardless of rule count.
 */
class DashboardData extends CController {
    use JsonResponse;

    private const MAX_TRIGGERS = 2000;

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $config = (new CorrelationStore())->load();
            $settings = (array) ($config['settings'] ?? []);
            $api = ZabbixApiClient::fromFrontend($settings);
            if ($api === null) {
                throw new \RuntimeException('No frontend session.');
            }

            $rules = array_values((array) ($config['rules'] ?? []));
            $sevRules = array_values((array) ($config['severity_rules'] ?? []));

            // 1. Every source trigger, and the active problem of each.
            $triggerids = [];
            foreach (array_merge($rules, $sevRules) as $rule) {
                foreach ((array) ($rule['conditions'] ?? []) as $c) {
                    $id = trim((string) ($c['triggerid'] ?? ''));
                    if ($id !== '') {
                        $triggerids[$id] = true;
                    }
                }
            }
            $triggerids = array_slice(array_keys($triggerids), 0, self::MAX_TRIGGERS);

            $triggers = [];
            $problems = [];
            if ($triggerids !== []) {
                foreach ((array) $api->call('trigger.get', [
                    'output' => ['triggerid', 'description', 'priority', 'value', 'status', 'lastchange'],
                    'selectHosts' => ['hostid', 'host', 'name'],
                    'triggerids' => $triggerids,
                    'expandDescription' => true,
                    'preservekeys' => true
                ]) as $id => $t) {
                    $triggers[(string) $id] = $t;
                }

                $params = [
                    'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged', 'suppressed', 'cause_eventid'],
                    'source' => 0,
                    'object' => 0,
                    'objectids' => $triggerids,
                    'recent' => false,
                    'sortfield' => ['eventid'],
                    'sortorder' => 'DESC'
                ];
                if (!empty($settings['ignore_suppressed'])) {
                    $params['suppressed'] = false;
                }
                if (!empty($settings['ignore_symptoms'])) {
                    $params['symptom'] = false;
                }
                foreach ((array) $api->call('problem.get', $params) as $p) {
                    $tid = (string) $p['objectid'];
                    // Newest problem per trigger (sorted DESC).
                    if (!isset($problems[$tid])) {
                        $problems[$tid] = $p;
                    }
                }
            }
            $minActive = max(0, (int) ($settings['min_active_seconds'] ?? 0));

            // 2. The correlation problem each rule raised.
            $correlationProblems = $this->correlationProblems($api, $rules, $settings);

            $hostIndex = [];
            $correlations = [];
            foreach ($rules as $rule) {
                $output = (array) ($rule['output'] ?? []);
                [$members, $active, $total] = $this->members((array) ($rule['conditions'] ?? []), $triggers, $problems, $minActive);
                $enabled = (bool) ($rule['enabled'] ?? true);
                $state = $enabled ? CorrelationEvaluator::resolveState($output, $active, $total) : 0;
                $id = (string) ($rule['id'] ?? '');
                $item = [
                    'id' => $id,
                    'name' => (string) ($rule['name'] ?? ''),
                    'description' => (string) ($rule['description'] ?? ''),
                    'enabled' => $enabled,
                    'state' => $state,
                    'stored_state' => (int) ($rule['last_state'] ?? 0),
                    'active' => $active,
                    'total' => $total,
                    'match' => self::matchText($output, $total),
                    'host' => $this->outputHostLabel($output, $settings),
                    'host_auto' => !empty($output['receiver_auto']),
                    'hostid' => (string) ($output['receiver_hostid'] ?? ($output['hostid'] ?? '')),
                    'problem' => $correlationProblems[$id] ?? null,
                    // Only the shipped receiver templates tag the problem with
                    // correlation.id; for "my own trapper item" or a custom key
                    // template the problem is raised by the user's own trigger.
                    'problem_lookup' => ($output['mode'] ?? 'receiver_lld') === 'receiver_lld'
                        && (!empty($output['receiver_auto'])
                            || (string) (($settings['receiver_state_key_template'] ?? '') ?: 'trigger.correlation.state[%s]') === 'trigger.correlation.state[%s]'),
                    'last_evaluated' => (int) ($rule['last_evaluated'] ?? 0),
                    'last_error' => (string) ($rule['last_error'] ?? ''),
                    'members' => $members
                ];
                $correlations[] = $item;
                $this->indexHosts($hostIndex, $members, 'correlations', ['id' => $id, 'name' => $item['name'], 'state' => $state]);
            }
            usort($correlations, static fn(array $a, array $b): int => [$b['state'], $a['name']] <=> [$a['state'], $b['name']]);

            // 3. Severity escalations and the problems they hold up.
            $raisedIds = [];
            foreach ($sevRules as $rule) {
                foreach (array_keys((array) ($rule['applied'] ?? [])) as $eventid) {
                    $raisedIds[(string) $eventid] = true;
                }
            }
            $raisedLive = [];
            if ($raisedIds !== []) {
                foreach ((array) $api->call('problem.get', [
                    'output' => ['eventid', 'name', 'severity', 'clock', 'acknowledged'],
                    'eventids' => array_keys($raisedIds),
                    'recent' => false
                ]) as $p) {
                    $raisedLive[(string) $p['eventid']] = $p;
                }
            }

            $escalations = [];
            $raisedCount = 0;
            foreach ($sevRules as $rule) {
                [$members, $active, $total] = $this->members((array) ($rule['conditions'] ?? []), $triggers, $problems, $minActive);
                $raised = [];
                foreach ((array) ($rule['applied'] ?? []) as $eventid => $info) {
                    $live = $raisedLive[(string) $eventid] ?? null;
                    if ($live === null) {
                        continue; // resolved since the last evaluation
                    }
                    $raised[] = [
                        'eventid' => (string) $eventid,
                        'host' => (string) ($info['host'] ?? ''),
                        'name' => (string) ($live['name'] ?? ($info['name'] ?? '')),
                        'from' => (int) ($info['sev'] ?? 0),
                        'to' => (int) ($live['severity'] ?? ($info['to'] ?? 0)),
                        'clock' => (int) ($live['clock'] ?? 0),
                        'acknowledged' => (string) ($live['acknowledged'] ?? '0') === '1'
                    ];
                }
                $raisedCount += count($raised);
                $id = (string) ($rule['id'] ?? '');
                $escalations[] = [
                    'id' => $id,
                    'name' => (string) ($rule['name'] ?? ''),
                    'enabled' => (bool) ($rule['enabled'] ?? true),
                    'active' => (int) ($rule['last_state'] ?? 0) !== 0,
                    'severity' => (int) ($rule['severity'] ?? 4),
                    'conditions_active' => $active,
                    'conditions_total' => $total,
                    'match' => self::sevMatchText($rule, $total),
                    'targets' => array_map(static fn($t): array => [
                        'trigger' => (string) ($t['trigger'] ?? ''),
                        'scope' => (string) ($t['scope'] ?? 'host'),
                        'host' => (string) ($t['host'] ?? ''),
                        'group' => (string) ($t['group'] ?? '')
                    ], (array) ($rule['targets'] ?? [])),
                    'raised' => $raised,
                    'last_error' => (string) ($rule['last_error'] ?? ''),
                    'members' => $members
                ];
                $this->indexHosts($hostIndex, $members, 'escalations', ['id' => $id, 'name' => (string) ($rule['name'] ?? ''), 'active' => (int) ($rule['last_state'] ?? 0) !== 0]);
            }

            // 4. Per host: its triggers used in rules, and the rules they feed.
            $hosts = array_values($hostIndex);
            foreach ($hosts as &$host) {
                $host['triggers'] = array_values($host['triggers']);
                $host['worst'] = 0;
                $host['has_problem'] = false;
                foreach ($host['triggers'] as $t) {
                    if ($t['problem'] !== null) {
                        $host['has_problem'] = true;
                        $host['worst'] = max($host['worst'], (int) $t['problem']['severity']);
                    }
                }
                foreach ($host['correlations'] as $c) {
                    $host['worst'] = max($host['worst'], (int) $c['state']);
                }
            }
            unset($host);
            usort($hosts, static fn(array $a, array $b): int
                => [$b['has_problem'] || $b['worst'] > 0, $b['worst'], $a['name']] <=> [$a['has_problem'] || $a['worst'] > 0, $a['worst'], $b['name']]);

            $firing = array_filter($correlations, static fn(array $c): bool => $c['state'] > 0);
            $bySeverity = [];
            foreach ($firing as $c) {
                $bySeverity[$c['state']] = ($bySeverity[$c['state']] ?? 0) + 1;
            }
            krsort($bySeverity);

            $this->jsonResponse([
                'ok' => true,
                'generated_at' => time(),
                'summary' => [
                    'correlations' => count($correlations),
                    'firing' => count($firing),
                    'by_severity' => $bySeverity,
                    'escalations' => count($escalations),
                    'escalations_active' => count(array_filter($escalations, static fn(array $e): bool => $e['active'])),
                    'raised' => $raisedCount,
                    'source_problems' => count($problems),
                    'engine' => $this->engineSummary($settings)
                ],
                'correlations' => $correlations,
                'escalations' => $escalations,
                'hosts' => $hosts
            ]);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] dashboard data failed: '.$e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'Could not load the dashboard: '.Util::truncate($e->getMessage(), 200)], 500);
        }
    }

    /**
     * A rule's conditions grouped by host, each trigger with its live problem.
     * Returns [members, active condition count, total].
     */
    private function members(array $conditions, array $triggers, array $problems, int $minActive): array {
        $byHost = [];
        $active = 0;
        $now = time();
        foreach ($conditions as $c) {
            $tid = trim((string) ($c['triggerid'] ?? ''));
            if ($tid === '') {
                continue;
            }
            $t = $triggers[$tid] ?? null;
            $host = (array) ($t['hosts'][0] ?? []);
            $hostid = (string) ($host['hostid'] ?? ($c['hostid'] ?? ''));
            $p = $problems[$tid] ?? null;
            if ($p !== null && $minActive > 0 && $now - (int) $p['clock'] < $minActive) {
                $p = null;
            }
            if ($p !== null) {
                $active++;
            }
            if (!isset($byHost[$hostid])) {
                $byHost[$hostid] = [
                    'hostid' => $hostid,
                    'name' => (string) (($host['name'] ?? '') ?: ($c['host'] ?? $hostid)),
                    'triggers' => []
                ];
            }
            $byHost[$hostid]['triggers'][] = [
                'triggerid' => $tid,
                'description' => (string) (($t['description'] ?? '') ?: ($c['trigger'] ?? $tid)),
                'priority' => (int) ($t['priority'] ?? 0),
                'missing' => $t === null,
                'disabled' => $t !== null && (string) ($t['status'] ?? '0') === '1',
                'problem' => $p === null ? null : [
                    'eventid' => (string) $p['eventid'],
                    'severity' => (int) $p['severity'],
                    'clock' => (int) $p['clock'],
                    'acknowledged' => (string) $p['acknowledged'] === '1'
                ]
            ];
        }
        return [array_values($byHost), $active, count($conditions)];
    }

    private function indexHosts(array &$index, array $members, string $kind, array $ref): void {
        foreach ($members as $m) {
            $id = $m['hostid'];
            if (!isset($index[$id])) {
                $index[$id] = ['hostid' => $id, 'name' => $m['name'], 'triggers' => [], 'correlations' => [], 'escalations' => []];
            }
            foreach ($m['triggers'] as $t) {
                $index[$id]['triggers'][$t['triggerid']] = $t;
            }
            $index[$id][$kind][] = $ref;
        }
    }

    /** The open problem each correlation rule raised, keyed by rule id. */
    private function correlationProblems(ZabbixApiClient $api, array $rules, array $settings): array {
        // Receiver hosts by id (automatic rules) or technical name (manual).
        $names = [];
        foreach ($rules as $rule) {
            $o = (array) ($rule['output'] ?? []);
            if (($o['mode'] ?? 'receiver_lld') === 'receiver_lld' && empty($o['receiver_hostid'])) {
                $names[trim((string) (($o['receiver_host'] ?? '') ?: ($settings['receiver_host'] ?? '')))] = true;
            }
        }
        $idByName = [];
        $names = array_filter(array_keys($names));
        if ($names !== []) {
            foreach ((array) $api->call('host.get', ['output' => ['hostid', 'host'], 'filter' => ['host' => array_values($names)]]) as $h) {
                $idByName[(string) $h['host']] = (string) $h['hostid'];
            }
        }

        $wanted = []; // hostid → slug → rule id
        foreach ($rules as $rule) {
            $o = (array) ($rule['output'] ?? []);
            if (($o['mode'] ?? 'receiver_lld') !== 'receiver_lld') {
                continue;
            }
            $hostid = (string) (($o['receiver_hostid'] ?? '') ?: ($idByName[trim((string) (($o['receiver_host'] ?? '') ?: ($settings['receiver_host'] ?? '')))] ?? ''));
            $slug = CorrelationStore::slug((string) (trim((string) ($o['correlation_id'] ?? '')) ?: ($rule['name'] ?? '')));
            if ($hostid !== '') {
                $wanted[$hostid][$slug] = (string) ($rule['id'] ?? '');
            }
        }
        if ($wanted === []) {
            return [];
        }

        $out = [];
        $rows = (array) $api->call('problem.get', [
            'output' => ['eventid', 'objectid', 'name', 'severity', 'clock', 'acknowledged'],
            'selectTags' => ['tag', 'value'],
            'hostids' => array_keys($wanted),
            'source' => 0,
            'object' => 0,
            'recent' => false,
            'tags' => [['tag' => 'correlation.id', 'operator' => 4]], // 4 = exists
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC'
        ]);
        if ($rows === []) {
            return [];
        }
        // problem.get carries no host id; map each problem's trigger to its host.
        $hostOf = [];
        foreach ((array) $api->call('trigger.get', [
            'output' => ['triggerid'],
            'selectHosts' => ['hostid'],
            'triggerids' => array_values(array_unique(array_column($rows, 'objectid')))
        ]) as $t) {
            $hostOf[(string) $t['triggerid']] = (string) ($t['hosts'][0]['hostid'] ?? '');
        }
        foreach ($rows as $p) {
            $slug = '';
            foreach ((array) ($p['tags'] ?? []) as $tag) {
                if ((string) $tag['tag'] === 'correlation.id') {
                    $slug = (string) $tag['value'];
                }
            }
            $ruleId = $wanted[$hostOf[(string) $p['objectid']] ?? ''][$slug] ?? '';
            if ($ruleId !== '' && !isset($out[$ruleId])) {
                $out[$ruleId] = [
                    'eventid' => (string) $p['eventid'],
                    'name' => (string) $p['name'],
                    'severity' => (int) $p['severity'],
                    'clock' => (int) $p['clock'],
                    'acknowledged' => (string) $p['acknowledged'] === '1'
                ];
            }
        }
        return $out;
    }

    private function outputHostLabel(array $o, array $settings): string {
        if (($o['mode'] ?? 'receiver_lld') === 'existing_item') {
            return trim((string) ($o['host'] ?? '')).' · '.trim((string) ($o['key'] ?? ''));
        }
        return (string) (($o['receiver_host_name'] ?? '') ?: ($o['receiver_host'] ?? '') ?: ($settings['receiver_host'] ?? ''));
    }

    private function engineSummary(array $settings): array {
        try {
            $provisioner = ReceiverProvisioner::forFrontend($settings);
            $engines = $provisioner !== null ? $provisioner->managedEngines() : [];
            // A hand-made engine the module does not manage yet still runs evaluations.
            if ($engines === [] && $provisioner !== null) {
                $engines = $provisioner->findEngineHosts();
            }
        }
        catch (\Throwable $e) {
            return ['status' => 'unknown', 'text' => 'Unknown'];
        }
        if ($engines === [] && ((string) ($settings['eval_driver'] ?? 'auto') === 'external' || ReceiverProvisioner::externalDriverActive($settings))) {
            $last = (int) ($settings['driver_last_call'] ?? 0);
            return ['status' => $last > 0 && time() - $last < 900 ? 'ok' : 'warn', 'text' => 'External driver', 'lastclock' => $last];
        }
        if ($engines === []) {
            return ['status' => 'warn', 'text' => 'No engine host yet'];
        }
        $e = $engines[0];
        if ($e['host_status'] !== 0 || $e['item_status'] !== 0) {
            return ['status' => 'fail', 'text' => 'Heartbeat disabled'];
        }
        if ($e['state'] === 1) {
            return ['status' => 'fail', 'text' => ReceiverProvisioner::heartbeatRejected($e) ? 'Heartbeat refused (secret)' : 'Heartbeat cannot reach eval.php'];
        }
        if ($e['lastclock'] === 0) {
            return ['status' => 'warn', 'text' => 'Waiting for first run'];
        }
        return ['status' => time() - $e['lastclock'] <= 420 ? 'ok' : 'warn', 'text' => 'Running', 'lastclock' => $e['lastclock']];
    }

    private static function matchText(array $o, int $total): string {
        $sev = static fn(int $v): string => ['', 'Information', 'Warning', 'Average', 'High', 'Disaster'][max(0, min(5, $v))];
        $mode = (string) ($o['match_mode'] ?? 'all');
        if ($mode === 'any') {
            return 'any of '.$total.' → '.$sev((int) ($o['match_value'] ?? 4));
        }
        if ($mode === 'count') {
            $tiers = [];
            foreach ((array) ($o['severity_tiers'] ?? []) as $t) {
                $tiers[] = '≥'.(int) ($t['min'] ?? 0).' → '.$sev((int) ($t['value'] ?? 0));
            }
            return implode(', ', $tiers);
        }
        return 'all '.$total.' → '.$sev((int) ($o['match_value'] ?? 4));
    }

    private static function sevMatchText(array $rule, int $total): string {
        $mode = (string) ($rule['match_mode'] ?? 'all');
        if ($mode === 'any') {
            return 'any of '.$total;
        }
        if ($mode === 'count') {
            return 'at least '.(int) ($rule['min_active'] ?? 1).' of '.$total;
        }
        return 'all '.$total;
    }
}
