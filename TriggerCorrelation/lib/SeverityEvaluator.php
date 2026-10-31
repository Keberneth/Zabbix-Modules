<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/ZabbixApiClient.php';
require_once __DIR__.'/RuleEngine.php';

/** Coordinates all severity rules through a durable, per-event ownership ledger. */
final class SeverityEvaluator {
    private CorrelationStore $store;
    private array $config;
    private array $settings;
    private ZabbixApiClient $api;
    private bool $injectedApi;
    private ?RuleEngine $engine = null;
    private array $targetVisibility = [];

    public function __construct(CorrelationStore $store, ?ZabbixApiClient $api = null) {
        $this->store = $store;
        $this->config = $store->load();
        $this->settings = (array) ($this->config['settings'] ?? []);
        $this->injectedApi = $api !== null;
        $this->api = $api ?? ZabbixApiClient::fromConfig($this->settings);
    }

    public function evaluate(?string $ruleId = null): array {
        return $this->store->withSeverityEvaluationLock(function () use ($ruleId): array {
            $this->reload();
            return $this->evaluateLocked($ruleId);
        });
    }

    /** A read-only prediction using the same source, target and timing logic as execution. */
    public function preview(array $rule): array {
        $engine = $this->engine ??= new RuleEngine($this->api, $this->settings);
        $sources = (bool) ($rule['enabled'] ?? true)
            ? $engine->inspectSources($rule)
            : ['valid' => true, 'results' => [], 'active_count' => 0, 'total' => 0, 'errors' => []];
        $valid = (bool) ($sources['valid'] ?? false);
        $desired = $valid && (bool) ($rule['enabled'] ?? true)
            && $this->conditionMet($rule, (int) ($sources['active_count'] ?? 0), (int) ($sources['total'] ?? 0)) ? 1 : 0;
        $rule['_source_signature'] = (string) ($sources['source_signature'] ?? '');
        $timing = RuleEngine::timedState($rule, $desired, time());
        if (!(bool) ($rule['enabled'] ?? true)) {
            $timing = ['state' => 0, 'candidate_state' => 0, 'pending_state' => null, 'pending_since' => 0,
                'pending_seconds' => 0, 'status' => 'disabled'];
        }
        $errors = array_values((array) ($sources['errors'] ?? []));
        $targets = [];
        if ($valid && ($desired !== 0 || (int) ($timing['state'] ?? 0) !== 0)) {
            try {
                $targets = $this->resolveTargets($rule);
            }
            catch (\Throwable $e) {
                $valid = false;
                $errors[] = $e->getMessage();
            }
        }
        if (!$valid) {
            $timing['state'] = (int) ($rule['last_state'] ?? 0);
            $timing['status'] = 'unknown';
            // An interrupted observation must not count towards a continuous delay.
            $timing['pending_state'] = null;
            $timing['pending_since'] = 0;
        }
        $severity = max(1, min(5, (int) ($rule['severity'] ?? 4)));
        $ledger = (array) (($this->store->loadSeverityLedger())['events'] ?? []);
        $id = (string) ($rule['id'] ?? '__preview__');
        $selectedTargets = [];
        foreach ($targets as &$target) {
            $eid = (string) $target['eventid'];
            $selectedTargets[$eid] = true;
            $entry = (array) ($ledger[$eid] ?? ['baseline' => (int) $target['severity'],
                'applied_severity' => (int) $target['severity'], 'owners' => []]);
            $entry['owners'][$id] = ['severity' => $severity, 'only_raise' => (bool) ($rule['only_raise'] ?? true)];
            $target['manual_override'] = (bool) ($entry['manual_override'] ?? false)
                || (empty($entry['pending']) && (int) $entry['applied_severity'] !== (int) $target['severity']);
            $target['requested_severity'] = $severity;
            $target['effective_severity'] = $this->desiredSeverity($entry);
            $target['would_change'] = (int) $timing['state'] !== 0 && !$target['manual_override']
                && (int) $target['severity'] !== $target['effective_severity'];
        }
        unset($target);
        $restorations = [];
        $release = [];
        foreach ($ledger as $eid => $entry) {
            if ($valid && isset($entry['owners'][$id]) && ((int) $timing['state'] === 0 || !isset($selectedTargets[$eid]))) {
                $release[] = (string) $eid;
            }
        }
        if ($release !== []) {
            try {
                $currentEvents = $this->readEvents($release);
                foreach ($release as $eid) {
                    $entry = $ledger[$eid];
                    unset($entry['owners'][$id]);
                    $event = $currentEvents[$eid] ?? null;
                    $manual = !empty($entry['manual_override']) || ($event !== null && empty($entry['pending'])
                        && (int) $entry['applied_severity'] !== (int) $event['severity']);
                    $restorations[] = ['eventid' => $eid, 'host' => (string) ($entry['host'] ?? ''),
                        'name' => (string) ($entry['name'] ?? ''), 'available' => $event !== null,
                        'severity' => $event !== null ? (int) $event['severity'] : null,
                        'effective_severity' => $this->desiredSeverity($entry), 'manual_override' => $manual,
                        'would_change' => $event !== null && (string) ($event['r_eventid'] ?? '0') === '0' && !$manual
                            && (int) $event['severity'] !== $this->desiredSeverity($entry)];
                }
            }
            catch (\Throwable $e) {
                // Read-only preview can expose an unavailable recovery check without losing intent.
                $errors[] = 'Recovery preview unavailable: '.$e->getMessage();
            }
        }
        return array_merge($timing, [
            'type' => 'severity', 'valid' => $valid, 'active' => $desired !== 0,
            'conditions' => (array) ($sources['results'] ?? []), 'active_count' => (int) ($sources['active_count'] ?? 0),
            'total' => (int) ($sources['total'] ?? 0), 'targets' => $targets, 'targets_count' => count($targets),
            'errors' => $errors, 'error' => implode('; ', $errors), 'severity' => $severity,
            'restorations' => $restorations, 'restorations_count' => count($restorations),
            'source_signature' => $rule['_source_signature'],
            'source_members' => array_column((array) ($sources['conditions'] ?? []), 'triggerid')
        ]);
    }

    private function reload(): void {
        $this->config = $this->store->load();
        $this->settings = (array) ($this->config['settings'] ?? []);
        if (!$this->injectedApi) {
            $this->api = ZabbixApiClient::fromConfig($this->settings);
        }
        $this->engine = null;
        $this->targetVisibility = [];
    }

    private function evaluateLocked(?string $ruleId): array {
        $clock = time();
        $rules = array_values((array) ($this->config['severity_rules'] ?? []));
        $selected = [];
        $known = [];
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            $known[$id] = true;
            if ($ruleId === null || $id === $ruleId) {
                $selected[$id] = $rule;
            }
        }
        if ($ruleId !== null && $selected === []) {
            throw new \RuntimeException('Severity escalation rule not found.');
        }
        $ledger = $this->initialLedger($rules);
        $events = (array) $ledger['events'];
        $drafts = [];
        $targets = [];
        $affected = [];
        foreach ($events as $eid => $entry) {
            foreach (array_keys((array) ($entry['release_owners'] ?? [])) as $owner) {
                $affected[$eid][$owner] = true;
            }
        }
        foreach ($selected as $id => $rule) {
            $drafts[$id] = $this->preview($rule);
            if (!$drafts[$id]['valid']) {
                continue; // Unknown never removes an existing contribution.
            }
            foreach ($events as $eid => &$entry) {
                if (isset($entry['owners'][$id])) {
                    $affected[$eid][$id] = true;
                    $entry['release_owners'][$id] = true;
                    unset($entry['owners'][$id]);
                }
            }
            unset($entry);
            if ((int) $drafts[$id]['state'] === 0) {
                continue;
            }
            foreach ($drafts[$id]['targets'] as $target) {
                $eid = (string) $target['eventid'];
                $targets[$eid] = $target;
                $affected[$eid][$id] = true;
                if (!isset($events[$eid])) {
                    $events[$eid] = ['baseline' => (int) $target['severity'], 'applied_severity' => (int) $target['severity'],
                        'owners' => [], 'host' => (string) ($target['host'] ?? ''), 'name' => (string) ($target['name'] ?? ''),
                        'manual_override' => false];
                }
                $events[$eid]['owners'][$id] = ['severity' => $drafts[$id]['severity'],
                    'only_raise' => (bool) ($rule['only_raise'] ?? true)];
                unset($events[$eid]['release_owners'][$id]);
            }
        }
        // Deleted rules release ownership even if their last UI request failed.
        foreach ($events as $eid => &$entry) {
            foreach (array_keys((array) ($entry['owners'] ?? [])) as $owner) {
                if (!isset($known[$owner])) {
                    unset($entry['owners'][$owner]);
                }
            }
        }
        unset($entry);

        $errors = [];
        $changed = [];
        $restored = [];
        $observed = $this->readEvents(array_keys($events));
        $operations = [];
        foreach ($events as $eid => &$entry) {
            $event = $observed[$eid] ?? null;
            if ($event === null) {
                $errors[$eid] = 'Event '.$eid.' is unavailable to the API token; its recovery record is retained.';
                continue;
            }
            if ((string) ($event['r_eventid'] ?? '0') !== '0') {
                unset($events[$eid]);
                continue;
            }
            if (!empty($entry['baseline_uncertain'])) {
                $errors[$eid] = 'Event '.$eid.' has conflicting legacy baselines and no usable severity history. Recovery requires review; no severity was changed.';
                continue;
            }
            $current = (int) $event['severity'];
            $pending = (array) ($entry['pending'] ?? []);
            if ($pending !== [] && $current === (int) $pending['to']) {
                // Covers a successful API write followed by a lost response or failed commit.
                $entry['applied_severity'] = $current;
                $entry['last_operation'] = (string) ($pending['id'] ?? '');
                unset($entry['pending']);
            }
            elseif ($pending !== [] && $current !== (int) $pending['from']) {
                $entry['manual_override'] = true;
                unset($entry['pending']);
            }
            elseif ($pending === [] && $current !== (int) $entry['applied_severity']) {
                $entry['manual_override'] = true;
            }
            if (!empty($entry['manual_override'])) {
                // Respect an operator's severity for the rest of this ownership period.
                if (empty($entry['owners'])) {
                    unset($events[$eid]);
                }
                continue;
            }
            $wanted = $this->desiredSeverity($entry);
            if ($current === $wanted) {
                unset($entry['pending']);
                $entry['applied_severity'] = $current;
                if (empty($entry['owners'])) {
                    unset($events[$eid]);
                }
                continue;
            }
            $opid = bin2hex(random_bytes(8));
            $entry['pending'] = ['id' => $opid, 'from' => $current, 'to' => $wanted];
            $operations[$eid] = $entry['pending'];
        }
        unset($entry);

        // Entire intended batch and baselines must fit durable storage before the first side effect.
        $ledger['events'] = $events;
        $this->store->saveSeverityLedger($ledger);
        foreach ($operations as $eid => $operation) {
            try {
                // Recheck immediately before writing so a human edit since the snapshot wins.
                $latest = $this->readEvents([(string) $eid]);
                $event = $latest[$eid] ?? null;
                if ($event === null) {
                    throw new \RuntimeException('Event '.$eid.' became unavailable; restoration remains pending.');
                }
                if ((string) ($event['r_eventid'] ?? '0') !== '0') {
                    unset($ledger['events'][$eid]);
                }
                elseif ((int) $event['severity'] !== (int) $operation['from']) {
                    $ledger['events'][$eid]['manual_override'] = true;
                    unset($ledger['events'][$eid]['pending']);
                }
                else {
                    $recovery = empty($ledger['events'][$eid]['owners']);
                    $message = $this->operationMessage($ledger['events'][$eid], $operation, $rules, $drafts);
                    $this->api->setEventSeverity((string) $eid, (int) $operation['to'], $message);
                    if ($recovery) {
                        $restored[$eid] = true;
                        unset($ledger['events'][$eid]);
                    }
                    else {
                        $changed[$eid] = true;
                        $ledger['events'][$eid]['applied_severity'] = (int) $operation['to'];
                        $ledger['events'][$eid]['last_operation'] = $operation['id'];
                        unset($ledger['events'][$eid]['pending']);
                    }
                }
            }
            catch (\Throwable $e) {
                $errors[$eid] = $e->getMessage();
                // Keep the durable intent; next run observes whether a timed-out write succeeded.
            }
            // A failed commit aborts the remaining API writes; the original journal remains recoverable.
            $this->store->saveSeverityLedger($ledger);
        }

        $runtime = [];
        $summary = ['type' => 'severity', 'evaluated_at' => gmdate('c', $clock), 'rules_total' => count($rules),
            'rules_evaluated' => count($selected), 'persist_error' => null, 'rules' => [], 'errors' => array_values($errors)];
        foreach ($selected as $id => $rule) {
            $draft = $drafts[$id];
            $ruleErrors = (array) $draft['errors'];
            $applied = [];
            $manual = 0;
            foreach ((array) $ledger['events'] as $eid => $entry) {
                if (isset($entry['owners'][$id]) || isset($affected[$eid][$id])) {
                    if (!empty($entry['manual_override'])) {
                        $manual++;
                    }
                    if (isset($errors[$eid])) {
                        $ruleErrors[] = $errors[$eid];
                    }
                    if (isset($entry['owners'][$id]) || isset($entry['release_owners'][$id]) || !empty($entry['pending'])) {
                        $applied[$eid] = ['sev' => (int) $entry['baseline'],
                            'to' => !empty($entry['manual_override']) && isset($observed[$eid])
                                ? (int) $observed[$eid]['severity'] : (int) $entry['applied_severity'],
                            'host' => (string) ($entry['host'] ?? ''), 'name' => (string) ($entry['name'] ?? ''),
                            'manual_override' => !empty($entry['manual_override']),
                            'pending_restore' => empty($entry['owners']) && empty($entry['manual_override'])];
                    }
                }
            }
            $error = Util::truncate(implode('; ', array_unique($ruleErrors)), 1000);
            $status = !$draft['valid'] ? 'unknown' : ($error !== '' ? 'error' : ($manual > 0 ? 'manual_override' : (string) $draft['status']));
            $patch = ['last_state' => (int) $draft['state'], 'last_error' => $error, 'last_evaluated' => $clock,
                'last_evaluated_iso' => gmdate('c', $clock), 'applied' => $applied, 'last_targets_count' => $draft['targets_count'],
                'status' => $status, 'manual_override_count' => $manual,
                'source_signature' => $draft['source_signature'], 'source_members' => $draft['source_members']];
            foreach (['pending_state', 'pending_since', 'pending_seconds', 'candidate_state'] as $key) {
                $patch[$key] = $draft[$key] ?? null;
            }
            if ($error === '') {
                $patch['last_successful'] = $clock;
            }
            $commentSig = (string) ($rule['last_comment_sig'] ?? '');
            if ($draft['valid'] && (int) $draft['state'] !== 0 && $error === '') {
                $sources = [];
                foreach ((array) $draft['conditions'] as $condition) {
                    foreach ((array) ($condition['problems'] ?? []) as $problem) {
                        $sources[] = $problem + ['host' => (string) ($condition['host'] ?? ''),
                            'trigger' => (string) ($condition['trigger'] ?? '')];
                    }
                }
                $commentApplied = array_filter($applied, static fn(array $entry): bool => empty($entry['manual_override']) && empty($entry['pending_restore']));
                $commentSig = $this->maybeCommentSources($rule, (string) ($rule['name'] ?? $id),
                    (int) $draft['severity'], $sources, $commentApplied, $commentSig);
            }
            elseif ((int) $draft['state'] === 0) {
                $commentSig = '';
            }
            $patch['last_comment_sig'] = $commentSig;
            $runtime[$id] = $patch;
            $ruleAffected = array_filter($affected, static fn(array $owners): bool => isset($owners[$id]));
            $summary['rules'][] = ['id' => $id, 'name' => (string) ($rule['name'] ?? $id), 'state' => (int) $draft['state'],
                'active' => (int) $draft['state'] !== 0, 'escalated' => count(array_intersect_key($changed, $ruleAffected)),
                'restored' => count(array_intersect_key($restored, $ruleAffected)), 'targets_count' => $draft['targets_count'],
                'manual_overrides' => $manual, 'status' => $status, 'error' => $error];
        }
        $this->store->saveSeverityRuntimeState($runtime);
        $finishedDeletes = [];
        foreach ($selected as $id => $rule) {
            if (!empty($rule['deletion_pending']) && ($runtime[$id]['last_error'] ?? '') === '') {
                $finishedDeletes[$id] = true;
            }
        }
        if ($finishedDeletes !== []) {
            CorrelationStore::transaction(function () use ($finishedDeletes): void {
                $config = $this->store->load();
                $config['severity_rules'] = array_values(array_filter((array) ($config['severity_rules'] ?? []),
                    static fn(array $rule): bool => !isset($finishedDeletes[(string) ($rule['id'] ?? '')])));
                $this->store->save($config);
            });
        }
        return $summary;
    }

    private function initialLedger(array $rules): array {
        $ledger = $this->store->loadSeverityLedger();
        if ((int) ($ledger['version'] ?? 0) >= 1) {
            return $ledger;
        }
        $events = [];
        foreach ($rules as $rule) {
            $id = (string) ($rule['id'] ?? '');
            foreach ($this->normalizeApplied($rule['applied'] ?? []) as $eid => $info) {
                if (!isset($events[$eid])) {
                    $events[$eid] = ['baseline' => $info['sev'], 'applied_severity' => $info['to'], 'owners' => [],
                        'host' => $info['host'], 'name' => $info['name'], 'manual_override' => false];
                }
                $events[$eid]['baseline'] = min((int) $events[$eid]['baseline'], $info['sev']);
                $events[$eid]['applied_severity'] = max((int) $events[$eid]['applied_severity'], $info['to']);
                $events[$eid]['owners'][$id] = ['severity' => $info['to'], 'only_raise' => (bool) ($rule['only_raise'] ?? true)];
            }
        }
        // Old versions kept independent baselines. Recover the beginning of the
        // current module-change chain when event history is available.
        if ($events !== []) {
            $history = $this->readEvents(array_keys($events), true);
            foreach ($events as $eid => &$entry) {
                $changes = array_values(array_filter((array) ($history[$eid]['acknowledges'] ?? []),
                    static fn(array $change): bool => ((int) ($change['action'] ?? 0) & 8) !== 0));
                $foundBaseline = false;
                foreach ($changes as $index => $change) {
                    $message = (string) ($change['message'] ?? '');
                    if (!str_starts_with($message, '[TC severity]')) {
                        if ($index === 0) { $entry['manual_override'] = true; }
                        break;
                    }
                    if (stripos($message, 'restor') !== false) { break; }
                    $entry['baseline'] = (int) ($change['old_severity'] ?? $entry['baseline']);
                    $foundBaseline = true;
                }
                if (!$foundBaseline && count($entry['owners']) > 1) {
                    foreach ($entry['owners'] as $owner) {
                        if (!$owner['only_raise']) { $entry['baseline_uncertain'] = true; }
                    }
                }
            }
            unset($entry);
        }
        return ['version' => 1, 'events' => $events];
    }

    private function desiredSeverity(array $entry): int {
        $owners = (array) ($entry['owners'] ?? []);
        if ($owners === []) {
            return (int) $entry['baseline'];
        }
        $wanted = 0;
        foreach ($owners as $owner) {
            $severity = (int) $owner['severity'];
            if ((bool) ($owner['only_raise'] ?? true)) {
                $severity = max($severity, (int) $entry['baseline']);
            }
            $wanted = max($wanted, $severity);
        }
        return $wanted;
    }

    private function operationMessage(array $entry, array $operation, array $rules, array $drafts): string {
        $message = '[TC severity]';
        if (empty($entry['owners'])) {
            $message .= ' Restored original severity to '.self::sevText((int) $operation['to']).'.';
        }
        else {
            $reasons = [];
            foreach ($rules as $rule) {
                $id = (string) ($rule['id'] ?? '');
                if (!isset($entry['owners'][$id]) || !(bool) ($rule['comment_target'] ?? true)) { continue; }
                $label = '"'.(string) ($rule['name'] ?? $id).'"';
                $problems = [];
                foreach ((array) ($drafts[$id]['conditions'] ?? []) as $condition) {
                    foreach ((array) ($condition['problems'] ?? []) as $problem) { $problems[] = $problem; }
                }
                if ($problems !== []) { $label .= ' because '.$this->sourceLabel($problems); }
                $reasons[] = $label;
            }
            if ($reasons !== []) {
                $message .= ' Severity '.self::sevText((int) $operation['to']).' from active escalation '.implode('; ', $reasons).'.';
            }
        }
        return $message.' [operation '.$operation['id'].']';
    }

    /** Missing events remain unknown; only a visible recovery event proves resolution. */
    private function readEvents(array $ids, bool $history = false): array {
        $events = [];
        foreach (array_chunk(array_map('strval', $ids), 200) as $chunk) {
            $params = ['output' => ['eventid', 'severity', 'r_eventid'],
                'eventids' => $chunk, 'source' => 0, 'object' => 0];
            if ($history) {
                $params['selectAcknowledges'] = ['action', 'message', 'old_severity', 'new_severity'];
            }
            foreach ((array) $this->api->call('event.get', $params) as $event) {
                $events[(string) $event['eventid']] = $event;
            }
        }
        return $events;
    }

    /** Retire a rule without discarding recovery intent if the API or storage fails. */
    public function deleteRule(string $id): array {
        return $this->store->withSeverityEvaluationLock(function () use ($id): array {
            $found = false;
            CorrelationStore::transaction(function () use ($id, &$found): void {
                $config = $this->store->load();
                foreach ($config['severity_rules'] as &$rule) {
                    if ((string) ($rule['id'] ?? '') === $id) {
                        $rule['enabled'] = false;
                        $rule['deletion_pending'] = true;
                        $found = true;
                    }
                }
                unset($rule);
                if ($found) {
                    $this->store->save($config);
                }
            });
            if (!$found) {
                return ['deleted' => 0, 'pending' => false];
            }
            $this->reload();
            $result = $this->evaluateLocked($id);
            $error = (string) ($result['rules'][0]['error'] ?? '');
            if ($error !== '') {
                return ['deleted' => 0, 'pending' => true, 'error' => $error];
            }
            return ['deleted' => 1, 'pending' => false];
        });
    }

    /** Compatibility for callers that still retain a rule before requesting its removal. */
    public function revertRule(array $rule): int {
        $result = $this->deleteRule((string) ($rule['id'] ?? ''));
        if (!empty($result['pending'])) {
            throw new \RuntimeException((string) $result['error']);
        }
        return (int) ($result['deleted'] ?? 0);
    }

    private function conditionMet(array $rule, int $activeCount, int $total): bool {
        return match ((string) ($rule['match_mode'] ?? 'all')) {
            'any' => $activeCount >= 1,
            'count' => $activeCount >= max(1, (int) ($rule['min_active'] ?? 1)),
            default => $total > 0 && $activeCount === $total
        };
    }
    private function resolveTargets(array $rule): array {
        $results = [];

        foreach ((array) ($rule['targets'] ?? []) as $target) {
            $target = (array) $target;
            $scope = (string) ($target['scope'] ?? 'host');
            $triggerName = trim((string) ($target['trigger'] ?? ''));
            $matchMode = (string) ($target['match'] ?? 'exact');
            $this->assertTargetVisible($target);

            $params = [
                // NOTE: problem.get in Zabbix 7.0 has NO selectHosts — the host is
                // resolved separately via trigger.get (by objectid) below.
                'output' => ['eventid', 'name', 'severity', 'objectid', 'clock'],
                'source' => 0,
                'object' => 0,
                'recent' => false,
                'sortfield' => ['eventid'],
                'sortorder' => 'DESC',
                // Fetch one extra so the safety limit is explicit, never silent partial coverage.
                'limit' => 1001
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

            $problems = (array) $this->api->call('problem.get', $params);
            if (count($problems) > 1000) {
                throw new \RuntimeException('The escalation target matches more than 1000 problems. Narrow its host group or name filter; no partial escalation was applied.');
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

    /** An empty problem query proves only no visible matches, not target visibility. */
    private function assertTargetVisible(array $target): void {
        $scope = (string) ($target['scope'] ?? 'host');
        if ($scope === 'all') { return; }
        $triggerid = trim((string) ($target['triggerid'] ?? ''));
        $hostid = trim((string) ($target['hostid'] ?? ''));
        $groupid = trim((string) ($target['groupid'] ?? ''));
        $cacheKey = $scope.'|'.$triggerid.'|'.$hostid.'|'.$groupid;
        if (isset($this->targetVisibility[$cacheKey])) { return; }
        if ($scope === 'hostgroup') {
            if ($groupid === '' || !(array) $this->api->call('hostgroup.get', ['output' => ['groupid'], 'groupids' => [$groupid]])) {
                throw new \RuntimeException('The escalation target host group is unavailable to the API token or was removed. Existing escalation ownership is retained.');
            }
        }
        elseif ($triggerid !== '') {
            $rows = (array) $this->api->call('trigger.get', ['output' => ['triggerid'], 'triggerids' => [$triggerid],
                'selectHosts' => ['hostid']]);
            $visible = false;
            foreach ($rows as $row) {
                if ((string) ($row['triggerid'] ?? '') !== $triggerid) { continue; }
                if ($hostid === '' || in_array($hostid, array_map('strval', array_column((array) ($row['hosts'] ?? []), 'hostid')), true)) {
                    $visible = true;
                }
            }
            if (!$visible) {
                throw new \RuntimeException('The escalation target trigger is unavailable to the API token, was removed, or no longer belongs to the selected host. Existing escalation ownership is retained.');
            }
        }
        elseif ($hostid === '' || !(array) $this->api->call('host.get', ['output' => ['hostid'], 'hostids' => [$hostid]])) {
            throw new \RuntimeException('The escalation target host is unavailable to the API token or was removed. Existing escalation ownership is retained.');
        }
        $this->targetVisibility[$cacheKey] = true;
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
        $message = 'While this problem is active, escalation "'.$name.'" requests '.self::sevText($severity).' for '
            .($targetList !== '' ? $targetList : count($applied).' problem(s)').'. Other active rules may request a higher severity.';

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
