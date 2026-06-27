<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/ZabbixApiClient.php';

final class CorrelationEvaluator {
    private CorrelationStore $store;
    private array $config;
    private array $settings;
    private ZabbixApiClient $api;
    private array $hostIdCache = [];

    public function __construct(CorrelationStore $store) {
        $this->store = $store;
        $this->config = $store->load();
        $this->settings = $this->config['settings'];
        // The evaluator runs from the unattended HTTP-agent endpoint (no user
        // session) and uses history.push, so it must use the token HTTP client.
        $this->api = ZabbixApiClient::fromConfig($this->settings);
    }

    public function evaluate(?string $ruleId = null): array {
        $started = time();
        $rules = array_values((array) ($this->config['rules'] ?? []));
        $selectedRules = [];

        foreach ($rules as $idx => $rule) {
            if ($ruleId !== null && (string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            $selectedRules[] = [$idx, $rule];
        }

        if ($ruleId !== null && $selectedRules === []) {
            throw new \RuntimeException('Rule not found.');
        }

        $summary = [
            'evaluated_at' => gmdate('c', $started),
            'rules_total' => count($rules),
            'rules_evaluated' => count($selectedRules),
            'discovery' => null,
            'persist_error' => null,
            'rules' => []
        ];

        // A discovery-push failure must NOT abort the whole evaluation: rules
        // (especially existing_item-mode ones that need no discovery) still run.
        if ((bool) ($this->settings['push_discovery_every_eval'] ?? true)) {
            try {
                $summary['discovery'] = $this->pushDiscovery($rules, $ruleId);
            }
            catch (\Throwable $e) {
                $summary['discovery'] = ['type' => 'discovery', 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        // Collect only the runtime fields each evaluated rule produced, keyed by
        // rule id, so persistence overwrites nothing but those fields on a fresh
        // re-read of the row (see CorrelationStore::saveRuntimeState). Writing the
        // whole config blob here used to clobber concurrent UI edits.
        $runtimeByRuleId = [];
        foreach ($selectedRules as [$idx, $rule]) {
            $result = $this->evaluateRule($rule, $started);
            $id = (string) ($rule['id'] ?? '');
            $runtime = [
                'last_state' => $result['state'],
                'last_error' => $result['error'],
                'last_evaluated' => $started,
                'last_evaluated_iso' => gmdate('c', $started),
                'last_eventids' => $result['matched_eventids'],
                'last_push_result' => $result['push_result']
            ];
            if (isset($result['comment_state'])) {
                $runtime['last_correlation_comment_sig'] = (string) ($result['comment_state']['last_correlation_comment_sig'] ?? '');
                $runtime['last_correlation_eventid'] = (string) ($result['comment_state']['last_correlation_eventid'] ?? '');
                $runtime['last_source_comment_sig'] = (string) ($result['comment_state']['last_source_comment_sig'] ?? '');
                unset($result['comment_state']);
            }
            if ($id !== '') {
                $runtimeByRuleId[$id] = $runtime;
            }
            $summary['rules'][] = $result;
        }

        // Persistence failure (e.g. read-only frontend) must not be reported as
        // an evaluation failure when the pushes themselves succeeded.
        try {
            $this->store->saveRuntimeState($runtimeByRuleId);
        }
        catch (\Throwable $e) {
            $summary['persist_error'] = $e->getMessage();
        }

        return $summary;
    }

    /**
     * Push a clear (0) to a single rule's output item. Used when a rule is deleted
     * or retargeted so its correlation problem resolves instead of sticking at the
     * last severity (a false positive). Best-effort — never throws.
     */
    public function clearRule(array $rule): bool {
        try {
            $context = $this->buildContext($rule, 0, false, [], [], time(), 0, 0);
            $push = $this->pushState($rule, 0, $context, time());
            return !empty($push['ok']);
        }
        catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Stable identifier of where a rule writes its state, so callers can tell when
     * an edit retargets a rule to a different item (and the old one must be cleared).
     */
    public static function outputTarget(array $rule): string {
        $o = (array) ($rule['output'] ?? []);
        $mode = (string) ($o['mode'] ?? 'receiver_lld');
        if ($mode === 'existing_item') {
            return 'item|'.trim((string) ($o['itemid'] ?? '')).'|'.trim((string) ($o['host'] ?? '')).'|'.trim((string) ($o['key'] ?? ''));
        }
        $id = trim((string) ($o['correlation_id'] ?? ''));
        if ($id === '') {
            $id = (string) ($rule['name'] ?? $rule['id'] ?? 'correlation');
        }
        return 'lld|'.trim((string) ($o['receiver_host'] ?? '')).'|'.CorrelationStore::slug($id);
    }

    private function evaluateRule(array $rule, int $clock): array {
        $ruleId = (string) ($rule['id'] ?? '');
        $name = (string) ($rule['name'] ?? $ruleId);
        $enabled = (bool) ($rule['enabled'] ?? true);
        $conditions = array_values((array) ($rule['conditions'] ?? []));
        $output = (array) ($rule['output'] ?? []);
        $state = (int) ($rule['last_state'] ?? 0);
        $error = '';
        $pending = false;
        $conditionResults = [];
        $matchedProblems = [];
        $matchedEventIds = [];
        $pushResult = null;
        $commentState = null;

        try {
            if (!$enabled && !(bool) ($this->settings['clear_disabled_rules'] ?? true)) {
                return [
                    'id' => $ruleId,
                    'name' => $name,
                    'enabled' => false,
                    'state' => (int) ($rule['last_state'] ?? 0),
                    'matched' => false,
                    'matched_eventids' => [],
                    'conditions' => [],
                    'push_result' => null,
                    'pending' => false,
                    'error' => ''
                ];
            }

            if ($conditions === []) {
                throw new \RuntimeException('Rule has no source trigger conditions.');
            }

            $activeCount = 0;
            foreach ($conditions as $condition) {
                $conditionResult = $this->evaluateCondition((array) $condition);
                $conditionResults[] = $conditionResult;
                if ($conditionResult['active']) {
                    $activeCount++;
                }
                foreach ($conditionResult['problems'] as $problem) {
                    $matchedProblems[] = $problem;
                    if (isset($problem['eventid'])) {
                        $matchedEventIds[] = (string) $problem['eventid'];
                    }
                }
            }
            $total = count($conditionResults);

            $state = $enabled ? $this->resolveState($output, $activeCount, $total) : 0;
            $matched = $state !== 0;
            $context = $this->buildContext($rule, $state, $matched, $conditionResults, $matchedProblems, $clock, $activeCount, $total);

            $push = $this->pushState($rule, $state, $context, $clock);
            $pushResult = $push['result'];
            if (!$push['ok']) {
                $pending = $push['pending'];
                $error = ($pending ? 'Discovery pending (the receiver item is not created yet): ' : '').$push['error'];
            }

            // Best-effort comment injection — fully isolated so it can never turn a
            // successful state push into a reported failure. Reset the throttle
            // signatures when not matched so a future re-fire comments again.
            if ($matched && $push['ok']) {
                try {
                    $commentState = $this->postComments($rule, $state, $matchedProblems);
                }
                catch (\Throwable $e) {
                    $commentState = null;
                }
            }
            else {
                $commentState = ['last_correlation_comment_sig' => '', 'last_correlation_eventid' => '', 'last_source_comment_sig' => ''];
            }
        }
        catch (\Throwable $e) {
            $error = $e->getMessage();
            $pushResult = null;
        }

        return [
            'id' => $ruleId,
            'name' => $name,
            'enabled' => $enabled,
            'state' => $state,
            'matched' => $state !== 0,
            'matched_eventids' => array_values(array_unique($matchedEventIds)),
            'conditions' => $conditionResults,
            'push_result' => $pushResult,
            'pending' => $pending,
            'error' => $error,
            'comment_state' => $commentState
        ];
    }

    private function evaluateCondition(array $condition): array {
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
        $activeProblems = [];

        foreach ($problems as $problem) {
            $clock = (int) ($problem['clock'] ?? 0);
            if ($minActive > 0 && $clock > 0 && ($now - $clock) < $minActive) {
                continue;
            }
            $cause = (string) ($problem['cause_eventid'] ?? '');
            // Defensive client-side fallback in case a Zabbix point release does
            // not honor the problem.get 'symptom' filter.
            if ($ignoreSymptoms && $cause !== '' && $cause !== '0') {
                continue;
            }
            $activeProblems[] = [
                'eventid' => (string) ($problem['eventid'] ?? ''),
                'objectid' => (string) ($problem['objectid'] ?? ''),
                'name' => (string) ($problem['name'] ?? $trigger),
                'hostid' => $hostid,
                'host' => $host,
                'triggerid' => $triggerid,
                'trigger' => $trigger,
                'clock' => $clock,
                'age_seconds' => $clock > 0 ? max(0, $now - $clock) : null,
                'severity' => (string) ($problem['severity'] ?? ''),
                'acknowledged' => (string) ($problem['acknowledged'] ?? ''),
                'suppressed' => (string) ($problem['suppressed'] ?? ''),
                'symptom' => ($cause !== '' && $cause !== '0') ? '1' : '0',
                'cause_eventid' => $cause,
                'tags' => (array) ($problem['tags'] ?? [])
            ];
        }

        return [
            'hostid' => $hostid,
            'host' => $host,
            'triggerid' => $triggerid,
            'trigger' => $trigger,
            'active' => count($activeProblems) > 0,
            'problem_count' => count($activeProblems),
            'problems' => $activeProblems
        ];
    }

    private function pushDiscovery(array $rules, ?string $ruleId): array {
        $groups = [];
        foreach ($rules as $rule) {
            if ($ruleId !== null && (string) ($rule['id'] ?? '') !== $ruleId) {
                continue;
            }
            if (!(bool) ($rule['enabled'] ?? true)) {
                continue;
            }
            $output = (array) ($rule['output'] ?? []);
            if (($output['mode'] ?? 'receiver_lld') !== 'receiver_lld') {
                continue;
            }
            $receiverHost = trim((string) ($output['receiver_host'] ?? $this->settings['receiver_host'] ?? ''));
            if ($receiverHost === '') {
                continue;
            }
            $correlationId = $this->correlationId($rule);
            $groups[$receiverHost][] = [
                '{#CORRELATION.ID}' => $correlationId,
                '{#CORRELATION.NAME}' => (string) ($rule['name'] ?? $correlationId),
                '{#CORRELATION.DESCRIPTION}' => (string) ($rule['description'] ?? ''),
                '{#CORRELATION.RULE_ID}' => (string) ($rule['id'] ?? '')
            ];
        }

        $discoveryKey = (string) ($this->settings['receiver_discovery_key'] ?? '') ?: 'trigger.correlation.discovery';
        $pushes = [];
        $allOk = true;

        foreach ($groups as $receiverHost => $data) {
            $payload = ['data' => array_values($data)];
            // One bad receiver host must not abort discovery for the others.
            try {
                $result = $this->api->historyPush([[
                    'host' => $receiverHost,
                    'key' => $discoveryKey,
                    'value' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'clock' => time()
                ]]);
                $inspect = $this->inspectPush($result);
            }
            catch (\Throwable $e) {
                $inspect = ['ok' => false, 'pending' => false, 'error' => $e->getMessage()];
            }
            if (!$inspect['ok']) {
                $allOk = false;
            }
            $pushes[] = [
                'host' => $receiverHost,
                'key' => $discoveryKey,
                'ok' => $inspect['ok'],
                'error' => $inspect['error']
            ];
        }

        return ['type' => 'discovery', 'ok' => $allOk, 'groups' => count($groups), 'pushes' => $pushes];
    }

    private function pushState(array $rule, int $state, array $context, int $clock): array {
        $output = (array) ($rule['output'] ?? []);
        $mode = (string) ($output['mode'] ?? 'receiver_lld');
        $rows = [];

        if ($mode === 'existing_item') {
            $itemid = trim((string) ($output['itemid'] ?? ''));
            if ($itemid !== '') {
                $rows[] = ['itemid' => $itemid, 'value' => $state, 'clock' => $clock];
            }
            else {
                $host = trim((string) ($output['host'] ?? ''));
                $key = trim((string) ($output['key'] ?? ''));
                if ($host === '' || $key === '') {
                    throw new \RuntimeException('Existing-item output requires an item id or a host and key.');
                }
                $rows[] = ['host' => $host, 'key' => $key, 'value' => $state, 'clock' => $clock];
            }
        }
        else {
            $receiverHost = trim((string) ($output['receiver_host'] ?? $this->settings['receiver_host'] ?? ''));
            if ($receiverHost === '') {
                throw new \RuntimeException('The receiver host is not set.');
            }
            $correlationId = $this->correlationId($rule);
            // str_replace (not sprintf) so a stray '%' in the user-editable
            // template can never throw a ValueError; '?:' so a cleared field
            // falls back to the default instead of producing an empty key.
            $stateTemplate = (string) ($this->settings['receiver_state_key_template'] ?? '') ?: 'trigger.correlation.state[%s]';
            $stateKey = str_replace('%s', $correlationId, $stateTemplate);
            $rows[] = ['host' => $receiverHost, 'key' => $stateKey, 'value' => $state, 'clock' => $clock];

            $contextKeyTemplate = trim((string) ($this->settings['receiver_context_key_template'] ?? ''));
            if ($contextKeyTemplate !== '') {
                $rows[] = [
                    'host' => $receiverHost,
                    'key' => str_replace('%s', $correlationId, $contextKeyTemplate),
                    'value' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'clock' => $clock
                ];
            }
        }

        $result = $this->api->historyPush($rows);
        $inspect = $this->inspectPush($result);
        $inspect['result'] = $result;
        return $inspect;
    }

    /**
     * history.push returns {"response":"success","data":[{...,"error":...}]} where
     * individual rows can fail without a top-level JSON-RPC error. Surface those
     * so the rule no longer shows healthy, and flag the "item not discovered yet"
     * case as a transient pending state that retries next cycle.
     */
    private function inspectPush(array $result): array {
        $report = ['ok' => true, 'pending' => false, 'error' => ''];

        $response = (string) ($result['response'] ?? 'success');
        $rows = (array) ($result['data'] ?? []);

        $errors = [];
        $pendingCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $err = trim((string) ($row['error'] ?? ''));
            if ($err !== '') {
                $errors[] = $err;
                if (self::isPendingError($err)) {
                    $pendingCount++;
                }
            }
        }

        if ($response !== '' && strtolower($response) !== 'success' && $errors === []) {
            $report['ok'] = false;
            $report['error'] = 'history.push reported: '.Util::truncate($response, 200);
            return $report;
        }

        if ($errors !== []) {
            $report['ok'] = false;
            $report['pending'] = ($pendingCount === count($errors));
            $report['error'] = Util::truncate(implode('; ', array_values(array_unique($errors))), 400);
        }

        return $report;
    }

    private static function isPendingError(string $error): bool {
        $error = strtolower($error);
        foreach ([
            'cannot find',
            'not found',
            'no permissions to referred object',
            'does not exist',
            'unknown item'
        ] as $needle) {
            if (strpos($error, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function buildContext(array $rule, int $state, bool $matched, array $conditionResults, array $matchedProblems, int $clock, int $activeCount = 0, int $total = 0): array {
        return [
            'rule_id' => (string) ($rule['id'] ?? ''),
            'rule_name' => (string) ($rule['name'] ?? ''),
            'state' => $state,
            'state_text' => self::stateText($state),
            'matched' => $matched,
            'match_mode' => (string) (((array) ($rule['output'] ?? []))['match_mode'] ?? 'all'),
            'active_count' => $activeCount,
            'total_conditions' => $total,
            'evaluated_at' => gmdate('c', $clock),
            'conditions' => $conditionResults,
            'matched_problems' => $matchedProblems,
            'matched_eventids' => array_values(array_unique(array_filter(array_map(static function (array $problem): string {
                return (string) ($problem['eventid'] ?? '');
            }, $matchedProblems)))),
            'description' => (string) ($rule['description'] ?? '')
        ];
    }

    private function correlationId(array $rule): string {
        $output = (array) ($rule['output'] ?? []);
        $id = trim((string) ($output['correlation_id'] ?? ''));
        if ($id !== '') {
            return CorrelationStore::slug($id);
        }
        return CorrelationStore::slug((string) ($rule['name'] ?? $rule['id'] ?? 'correlation'));
    }

    private static function stateText(int $state): string {
        return match ($state) {
            0 => 'OK',
            1 => 'Information',
            2 => 'Warning',
            3 => 'Average',
            4 => 'High',
            5 => 'Critical/Disaster',
            default => 'Unknown'
        };
    }

    /**
     * Severity from the active-condition count, per the rule's match mode:
     *   all   → match_value only when every condition is active
     *   any   → match_value when at least one is active
     *   count → the highest tier value whose min ≤ active count (0 below the lowest)
     */
    private function resolveState(array $output, int $activeCount, int $total): int {
        $mode = (string) ($output['match_mode'] ?? 'all');

        if ($mode === 'any') {
            return $activeCount >= 1 ? max(1, min(5, (int) ($output['match_value'] ?? 4))) : 0;
        }

        if ($mode === 'count') {
            $best = 0;
            foreach ((array) ($output['severity_tiers'] ?? []) as $tier) {
                $min = (int) ($tier['min'] ?? 0);
                $value = (int) ($tier['value'] ?? 0);
                if ($min >= 1 && $value >= 1 && $value <= 5 && $activeCount >= $min) {
                    $best = max($best, $value);
                }
            }
            return $best;
        }

        return ($total > 0 && $activeCount === $total) ? max(1, min(5, (int) ($output['match_value'] ?? 4))) : 0;
    }

    /**
     * Best-effort comment injection. Posts a summary comment on the correlation
     * problem and/or a cross-link comment on each active source problem, throttled
     * by a per-target signature (hash of state + active event ids) so the periodic
     * eval does not repeat them. Returns the updated throttle signatures.
     */
    private function postComments(array $rule, int $state, array $matchedProblems): array {
        $output = (array) ($rule['output'] ?? []);
        // Default OFF at eval time: legacy rules saved before this feature have no
        // comment_* keys and must NOT silently start posting — only rules re-saved
        // through the editor (which persist explicit true/false) opt in.
        $commentCorrelation = (bool) ($output['comment_correlation_problem'] ?? false);
        $commentSource = (bool) ($output['comment_source_problems'] ?? false);

        $out = [
            'last_correlation_comment_sig' => (string) ($rule['last_correlation_comment_sig'] ?? ''),
            'last_correlation_eventid' => (string) ($rule['last_correlation_eventid'] ?? ''),
            'last_source_comment_sig' => (string) ($rule['last_source_comment_sig'] ?? '')
        ];

        $active = array_values(array_filter($matchedProblems, static function ($p): bool {
            return is_array($p) && (string) ($p['eventid'] ?? '') !== '';
        }));
        if ($active === [] || (!$commentCorrelation && !$commentSource)) {
            return $out;
        }

        $name = (string) ($rule['name'] ?? '');
        $stateText = self::stateText($state);

        // Throttle on the set of active source TRIGGERS (not event ids) + state, so a
        // single correlated trigger flapping (a new event id each cycle) does not
        // repost every minute. Re-comment only when the trigger set or severity changes.
        $keys = array_values(array_unique(array_map(static function ($p): string {
            return (string) ((string) ($p['triggerid'] ?? '') !== '' ? $p['triggerid'] : ($p['objectid'] ?? ''));
        }, $active)));
        sort($keys);
        $sig = sha1($state.'|'.implode(',', $keys));

        $lines = [];
        foreach ($active as $p) {
            $label = (string) ((string) ($p['trigger'] ?? '') !== '' ? $p['trigger'] : ($p['name'] ?? ''));
            $lines[] = ' - '.(string) ($p['host'] ?? '').': '.$label.' ('.self::severityText((int) ($p['severity'] ?? 0)).self::ageSuffix($p).')';
        }

        if ($commentCorrelation) {
            try {
                $eventid = $this->findCorrelationEventId($rule);
                // Comment when the active set/severity changed OR the correlation
                // problem was resolved and re-raised (new event id, same signature).
                if ($eventid !== '' && ($sig !== $out['last_correlation_comment_sig'] || $eventid !== $out['last_correlation_eventid'])) {
                    $message = 'Correlation "'.$name.'" — '.$stateText.' ('.count($active).' related trigger problem(s) active):'."\n".implode("\n", $lines);
                    $this->api->addProblemComment($eventid, $message, $this->commentAction(), $this->commentChunk());
                    $out['last_correlation_comment_sig'] = $sig;
                    $out['last_correlation_eventid'] = $eventid;
                }
            }
            catch (\Throwable $e) {
                // best effort; signature unchanged → retries next cycle
            }
        }

        if ($commentSource && $sig !== $out['last_source_comment_sig']) {
            $allPosted = true;
            foreach ($active as $p) {
                $eventid = (string) $p['eventid'];
                $others = [];
                foreach ($active as $q) {
                    if ((string) $q['eventid'] === $eventid) {
                        continue;
                    }
                    $qlabel = (string) ((string) ($q['trigger'] ?? '') !== '' ? $q['trigger'] : ($q['name'] ?? ''));
                    $others[] = ' - '.(string) ($q['host'] ?? '').': '.$qlabel;
                }
                $message = 'Part of correlation "'.$name.'" (now '.$stateText.').';
                if ($others !== []) {
                    $message .= "\nAlso active now:\n".implode("\n", $others);
                }
                try {
                    $this->api->addProblemComment($eventid, $message, $this->commentAction(), $this->commentChunk());
                }
                catch (\Throwable $e) {
                    // skip this one; do not advance the signature so it retries later
                    $allPosted = false;
                }
            }
            if ($allPosted) {
                $out['last_source_comment_sig'] = $sig;
            }
        }

        return $out;
    }

    private function findCorrelationEventId(array $rule): string {
        $output = (array) ($rule['output'] ?? []);
        $mode = (string) ($output['mode'] ?? 'receiver_lld');

        if ($mode === 'existing_item') {
            $triggerids = $this->api->triggerIdsForItem(
                trim((string) ($output['itemid'] ?? '')),
                trim((string) ($output['host'] ?? '')),
                trim((string) ($output['key'] ?? ''))
            );
            if ($triggerids === []) {
                return '';
            }
            $events = $this->api->activeProblemsForTriggers($triggerids, trim((string) ($output['hostid'] ?? '')));
            return (string) ($events[0]['eventid'] ?? '');
        }

        // receiver_lld: the template tags the correlation trigger with correlation.id.
        $receiverHost = trim((string) ($output['receiver_host'] ?? $this->settings['receiver_host'] ?? ''));
        $correlationId = $this->correlationId($rule);
        $hostid = $receiverHost !== '' ? $this->resolveHostId($receiverHost) : '';
        $events = $this->api->activeProblemsByTag('correlation.id', $correlationId, $hostid);
        return (string) ($events[0]['eventid'] ?? '');
    }

    private function resolveHostId(string $host): string {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if (!array_key_exists($host, $this->hostIdCache)) {
            try {
                $this->hostIdCache[$host] = $this->api->getHostId($host);
            }
            catch (\Throwable $e) {
                $this->hostIdCache[$host] = '';
            }
        }
        return $this->hostIdCache[$host];
    }

    private function commentAction(): int {
        return max(1, (int) ($this->settings['problem_update_action'] ?? 4));
    }

    private function commentChunk(): int {
        return max(200, min(2000, (int) ($this->settings['comment_chunk_size'] ?? 1900)));
    }

    private static function severityText(int $sev): string {
        return match ($sev) {
            0 => 'Not classified',
            1 => 'Information',
            2 => 'Warning',
            3 => 'Average',
            4 => 'High',
            5 => 'Disaster',
            default => 'Unknown'
        };
    }

    private static function ageSuffix(array $p): string {
        $age = $p['age_seconds'] ?? null;
        if (!is_int($age) && !is_float($age)) {
            return '';
        }
        $age = (int) $age;
        if ($age < 60) {
            return ', '.$age.'s';
        }
        if ($age < 3600) {
            return ', '.intdiv($age, 60).'m';
        }
        return ', '.intdiv($age, 3600).'h';
    }
}
