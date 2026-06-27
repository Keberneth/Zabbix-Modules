<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';

class RuleSave extends CController {
    use JsonResponse;

    // No disableCsrfValidation(): this is a state-changing POST, so the Zabbix
    // framework validates the _csrf_token the UI sends with the form data.

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
            $rules = array_values((array) ($config['rules'] ?? []));
            $found = false;
            $clearOld = null;

            foreach ($rules as $i => $existing) {
                if ((string) ($existing['id'] ?? '') === $rule['id']) {
                    // If a firing rule is retargeted to a different item, the old
                    // item must be resolved or it sticks at the last severity.
                    if ((int) ($existing['last_state'] ?? 0) !== 0
                            && CorrelationEvaluator::outputTarget($existing) !== CorrelationEvaluator::outputTarget($rule)) {
                        $clearOld = $existing;
                    }
                    // Replace conditions/output wholesale (the normalized rule is
                    // authoritative); carry over only runtime fields the editor
                    // does not submit. Deep-merging here used to leave stale
                    // conditions and orphan output keys.
                    $runtime = [];
                    foreach (['last_state', 'last_error', 'last_evaluated', 'last_evaluated_iso', 'last_eventids', 'last_push_result',
                        'last_correlation_comment_sig', 'last_correlation_eventid', 'last_source_comment_sig'] as $key) {
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

            $this->assertCorrelationIdUnique($rule, $rules, (array) ($config['settings'] ?? []));

            $config['rules'] = $rules;
            $store->save($config);

            if ($clearOld !== null) {
                try {
                    (new CorrelationEvaluator($store))->clearRule($clearOld);
                }
                catch (\Throwable $e) {
                    // best effort
                }
            }

            $this->jsonResponse(['ok' => true] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save the rule.'], 500);
        }
    }

    /**
     * Reject a receiver_lld rule whose (effective receiver host, slugged
     * correlation id) collides with a different rule. Both would resolve to the
     * same {#CORRELATION.ID} discovery row and the same
     * trigger.correlation.state[<id>] item on the receiver host, so their pushes
     * would silently overwrite each other. The effective receiver host mirrors the
     * evaluator's fallback to the default receiver from Settings.
     */
    private function assertCorrelationIdUnique(array $rule, array $rules, array $settings): void {
        $output = (array) ($rule['output'] ?? []);
        if ((string) ($output['mode'] ?? 'receiver_lld') !== 'receiver_lld') {
            return;
        }

        $defaultReceiver = trim((string) ($settings['receiver_host'] ?? ''));
        $slug = CorrelationStore::slug((string) ($output['correlation_id'] ?? ''));
        $receiver = trim((string) ($output['receiver_host'] ?? '')) ?: $defaultReceiver;
        $key = $receiver."\0".$slug;

        foreach ($rules as $other) {
            if ((string) ($other['id'] ?? '') === (string) ($rule['id'] ?? '')) {
                continue;
            }
            $otherOutput = (array) ($other['output'] ?? []);
            if ((string) ($otherOutput['mode'] ?? 'receiver_lld') !== 'receiver_lld') {
                continue;
            }
            $otherReceiver = trim((string) ($otherOutput['receiver_host'] ?? '')) ?: $defaultReceiver;
            if (($otherReceiver."\0".CorrelationStore::slug((string) ($otherOutput['correlation_id'] ?? ''))) === $key) {
                throw new \InvalidArgumentException(
                    'Another rule ("'.(string) ($other['name'] ?? $other['id'] ?? '').'") already uses the Correlation ID "'
                    .$slug.'" on receiver host "'.$receiver.'". Choose a unique Correlation ID.'
                );
            }
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

        $conditions = array_values((array) ($rule['conditions'] ?? []));
        $normalizedConditions = [];
        foreach ($conditions as $condition) {
            $condition = (array) $condition;
            $hostid = trim((string) ($condition['hostid'] ?? ''));
            $triggerid = trim((string) ($condition['triggerid'] ?? ''));
            // Skip fully-empty rows so a half-filled extra condition does not block saving.
            if ($hostid === '' && $triggerid === ''
                && trim((string) ($condition['host'] ?? '')) === ''
                && trim((string) ($condition['trigger'] ?? '')) === '') {
                continue;
            }
            if ($hostid === '' || $triggerid === '') {
                throw new \InvalidArgumentException('Each condition must have a selected host and trigger.');
            }
            $normalizedConditions[] = [
                'hostid' => $hostid,
                'host' => trim((string) ($condition['host'] ?? '')),
                'triggerid' => $triggerid,
                'trigger' => trim((string) ($condition['trigger'] ?? ''))
            ];
        }

        if (count($normalizedConditions) < 2) {
            throw new \InvalidArgumentException('At least two source trigger conditions are required.');
        }

        $output = (array) ($rule['output'] ?? []);
        $mode = (string) ($output['mode'] ?? 'receiver_lld');
        if (!in_array($mode, ['receiver_lld', 'existing_item'], true)) {
            throw new \InvalidArgumentException('Unsupported output mode.');
        }

        $matchMode = (string) ($output['match_mode'] ?? 'all');
        if (!in_array($matchMode, ['all', 'any', 'count'], true)) {
            $matchMode = 'all';
        }

        $normalizedOutput = [
            'mode' => $mode,
            'match_mode' => $matchMode,
            'match_value' => max(1, min(5, (int) ($output['match_value'] ?? 4))),
            'clear_value' => 0,
            'comment_correlation_problem' => (bool) ($output['comment_correlation_problem'] ?? true),
            'comment_source_problems' => (bool) ($output['comment_source_problems'] ?? true)
        ];

        if ($matchMode === 'count') {
            // Tiers: highest active-count threshold reached wins. Dedupe by min,
            // sort ascending, cap to a sane number.
            $tiers = [];
            foreach ((array) ($output['severity_tiers'] ?? []) as $tier) {
                $tier = (array) $tier;
                $min = (int) ($tier['min'] ?? 0);
                $value = (int) ($tier['value'] ?? 0);
                if ($min < 1 || $value < 1 || $value > 5) {
                    continue;
                }
                $tiers[$min] = $value;
            }
            if ($tiers === []) {
                throw new \InvalidArgumentException('Count mode needs at least one severity tier (minimum active count → severity).');
            }
            ksort($tiers);
            $tiers = array_slice($tiers, 0, 10, true);
            $normalizedOutput['severity_tiers'] = [];
            foreach ($tiers as $min => $value) {
                $normalizedOutput['severity_tiers'][] = ['min' => $min, 'value' => $value];
            }
        }

        if ($mode === 'existing_item') {
            $normalizedOutput['itemid'] = trim((string) ($output['itemid'] ?? ''));
            $normalizedOutput['hostid'] = trim((string) ($output['hostid'] ?? ''));
            $normalizedOutput['host'] = trim((string) ($output['host'] ?? ''));
            $normalizedOutput['key'] = trim((string) ($output['key'] ?? ''));
            if ($normalizedOutput['itemid'] === '' && ($normalizedOutput['host'] === '' || $normalizedOutput['key'] === '')) {
                throw new \InvalidArgumentException('Existing-item output requires an item id or a host and key.');
            }
        }
        else {
            $correlationId = CorrelationStore::slug((string) ($output['correlation_id'] ?? $name));
            $normalizedOutput['correlation_id'] = $correlationId;
            $normalizedOutput['receiver_host'] = trim((string) ($output['receiver_host'] ?? ''));
        }

        return [
            'id' => $id,
            'enabled' => (bool) ($rule['enabled'] ?? true),
            'name' => $name,
            'description' => trim((string) ($rule['description'] ?? '')),
            'conditions' => $normalizedConditions,
            'output' => $normalizedOutput,
            'updated_at' => time(),
            'updated_at_iso' => gmdate('c')
        ];
    }
}
