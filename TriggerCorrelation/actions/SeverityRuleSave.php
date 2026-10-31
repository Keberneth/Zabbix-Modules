<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\Util;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';
require_once dirname(__DIR__).'/lib/RuleDefinition.php';
require_once dirname(__DIR__).'/lib/RuleEngine.php';

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
            $notes = [];
            if (!empty($rule['enabled'])) {
                $settings = (array) ($store->load()['settings'] ?? []);
                $inspection = (new \Modules\TriggerCorrelation\Lib\RuleEngine(\Modules\TriggerCorrelation\Lib\ZabbixApiClient::fromConfig($settings), $settings))->inspectSources($rule);
                if (!$inspection['valid']) { throw new \InvalidArgumentException(implode(' ', $inspection['errors'])); }
                $rule['conditions'] = $inspection['conditions'];
            }
            ReceiverProvisioner::prepareForSave($store);

            $store->withSeverityEvaluationLock(function () use ($store, $rule, &$notes): void {
            CorrelationStore::transaction(function () use ($store, $rule, &$notes): void {
                $config = $store->load();
                $rules = array_values((array) ($config['severity_rules'] ?? []));
                $found = false;

                foreach ($rules as $i => $existing) {
                    if ((string) ($existing['id'] ?? '') === $rule['id']) {
                        $runtime = [];
                        foreach (['last_state', 'last_error', 'last_evaluated', 'last_evaluated_iso',
                            'applied', 'last_comment_sig', 'last_targets_count', 'last_successful', 'status', 'candidate_state', 'manual_override_count', 'source_signature', 'source_members'] as $key) {
                            if (array_key_exists($key, $existing)) {
                                $runtime[$key] = $existing[$key];
                            }
                        }
                        $rules[$i] = array_merge($rule, $runtime, ['pending_since' => 0, 'pending_state' => null, 'pending_seconds' => 0]);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $rules[] = $rule;
                }

                // Severity escalation needs no receiver host, but it is driven by
                // the same once-a-minute heartbeat, so make sure the engine exists.
                $settings = (array) ($config['settings'] ?? []);
                try {
                    $provisioner = ReceiverProvisioner::forFrontend($settings, $store);
                    if ($provisioner !== null) {
                        $notes = $provisioner->ensureEngineAndSecret($store, $settings);
                        $config['settings'] = $provisioner->settings();
                    }
                }
                catch (\Throwable $e) {
                    error_log('[TriggerCorrelation] engine host setup failed: '.$e->getMessage());
                    $notes[] = 'The engine host could not be set up automatically: '.Util::truncate($e->getMessage(), 200);
                }

                $config['severity_rules'] = $rules;
                $store->save($config);
            });
            });

            $this->jsonResponse(['ok' => true, 'notes' => $notes] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] severity rule save failed: '.$e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save the severity rule.'], 500);
        }
    }

    private function normalizeRule(array $rule): array {
        return \Modules\TriggerCorrelation\Lib\RuleDefinition::normalize($rule, 'severity');
    }
}
