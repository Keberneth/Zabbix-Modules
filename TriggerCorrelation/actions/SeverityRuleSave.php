<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';

/**
 * Create/update one severity-escalation rule (the new "Severity escalation" tab).
 * Mirrors RuleSave: framework CSRF stays on, Super Admin only, and the rule's
 * own runtime fields (state + the applied-escalation memory) are carried over so
 * a save never forgets a severity this rule has currently raised.
 */
class SeverityRuleSave extends CController {
    use JsonResponse;

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $rule = $this->normalizeRule($this->postJsonField('rule'));

            $store = new CorrelationStore();
            $config = $store->load();
            $rules = array_values((array) ($config['severity_rules'] ?? []));
            $found = false;

            foreach ($rules as $i => $existing) {
                if ((string) ($existing['id'] ?? '') === $rule['id']) {
                    $runtime = [];
                    foreach (['last_state', 'last_error', 'last_evaluated', 'last_evaluated_iso',
                        'applied', 'last_comment_sig', 'last_targets_count'] as $key) {
                        if (array_key_exists($key, $existing)) {
                            $runtime[$key] = $existing[$key];
                        }
                    }
                    $rules[$i] = array_merge($rule, $runtime);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $rules[] = $rule;
            }

            $config['severity_rules'] = $rules;
            $store->save($config);

            $this->jsonResponse(['ok' => true] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save the severity rule.'], 500);
        }
    }

    private function normalizeRule(array $rule): array {
        $id = trim((string) ($rule['id'] ?? ''));
        if ($id === '') {
            $id = CorrelationStore::generateId();
        }

        $name = trim((string) ($rule['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Rule name is required.');
        }

        // ── Source conditions (the "when"): at least one host+trigger ──────────
        $conditions = [];
        foreach ((array) ($rule['conditions'] ?? []) as $condition) {
            $condition = (array) $condition;
            $hostid = trim((string) ($condition['hostid'] ?? ''));
            $triggerid = trim((string) ($condition['triggerid'] ?? ''));
            if ($hostid === '' && $triggerid === ''
                && trim((string) ($condition['host'] ?? '')) === ''
                && trim((string) ($condition['trigger'] ?? '')) === '') {
                continue;
            }
            if ($hostid === '' || $triggerid === '') {
                throw new \InvalidArgumentException('Each source condition must have a selected host and trigger.');
            }
            $conditions[] = [
                'hostid' => $hostid,
                'host' => trim((string) ($condition['host'] ?? '')),
                'triggerid' => $triggerid,
                'trigger' => trim((string) ($condition['trigger'] ?? ''))
            ];
        }
        if (count($conditions) < 1) {
            throw new \InvalidArgumentException('At least one source trigger condition is required.');
        }

        $matchMode = (string) ($rule['match_mode'] ?? 'all');
        if (!in_array($matchMode, ['all', 'any', 'count'], true)) {
            $matchMode = 'all';
        }
        $minActive = max(1, (int) ($rule['min_active'] ?? 1));

        // ── Targets (the "what to escalate") ──────────────────────────────────
        $targets = [];
        foreach ((array) ($rule['targets'] ?? []) as $target) {
            $target = (array) $target;
            $scope = (string) ($target['scope'] ?? 'host');
            if (!in_array($scope, ['host', 'hostgroup', 'all'], true)) {
                $scope = 'host';
            }
            $trigger = trim((string) ($target['trigger'] ?? ''));
            $triggerid = trim((string) ($target['triggerid'] ?? ''));
            $hostid = trim((string) ($target['hostid'] ?? ''));
            $host = trim((string) ($target['host'] ?? ''));
            $groupid = trim((string) ($target['groupid'] ?? ''));
            $group = trim((string) ($target['group'] ?? ''));
            $match = (string) ($target['match'] ?? 'exact');
            if (!in_array($match, ['exact', 'contains'], true)) {
                $match = 'exact';
            }

            // Skip a fully-empty target row.
            if ($trigger === '' && $triggerid === '' && $groupid === '') {
                continue;
            }

            if ($scope === 'host') {
                if ($triggerid === '' && ($hostid === '' || $trigger === '')) {
                    throw new \InvalidArgumentException('A "this host" target needs a selected trigger.');
                }
            }
            elseif ($scope === 'hostgroup') {
                if ($groupid === '' || $trigger === '') {
                    throw new \InvalidArgumentException('A "host group" target needs a host group and a trigger name.');
                }
            }
            else { // all
                if ($trigger === '') {
                    throw new \InvalidArgumentException('An "all hosts" target needs a trigger name.');
                }
            }

            $targets[] = [
                'scope' => $scope,
                'trigger' => $trigger,
                'triggerid' => $scope === 'host' ? $triggerid : '',
                'hostid' => $scope === 'host' ? $hostid : '',
                'host' => $scope === 'host' ? $host : '',
                'groupid' => $scope === 'hostgroup' ? $groupid : '',
                'group' => $scope === 'hostgroup' ? $group : '',
                'match' => $match
            ];
        }
        if ($targets === []) {
            throw new \InvalidArgumentException('At least one escalation target is required.');
        }

        return [
            'id' => $id,
            'enabled' => (bool) ($rule['enabled'] ?? true),
            'name' => $name,
            'description' => trim((string) ($rule['description'] ?? '')),
            'conditions' => $conditions,
            'match_mode' => $matchMode,
            'min_active' => $minActive,
            'targets' => $targets,
            'severity' => max(1, min(5, (int) ($rule['severity'] ?? 4))),
            'only_raise' => (bool) ($rule['only_raise'] ?? true),
            'comment_target' => (bool) ($rule['comment_target'] ?? true),
            'comment_source' => (bool) ($rule['comment_source'] ?? true),
            'updated_at' => time(),
            'updated_at_iso' => gmdate('c')
        ];
    }
}
