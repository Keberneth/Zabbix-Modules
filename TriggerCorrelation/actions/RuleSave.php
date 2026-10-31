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
require_once dirname(__DIR__).'/lib/RuleDefinition.php';
require_once dirname(__DIR__).'/lib/RuleEngine.php';
require_once dirname(__DIR__).'/lib/OutputIdentity.php';

class RuleSave extends CController {
    use JsonResponse;

    /** Keeps every rule well inside the module configuration's size limit. */
    private const MAX_CONDITIONS = 100;

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
            $posted = $this->postJsonField('rule');
            $rule = $this->normalizeRule($posted);
            $store = new CorrelationStore();
            $notes = [];
            if (!empty($rule['enabled'])) {
                $settings = (array) ($store->load()['settings'] ?? []);
                $inspection = (new \Modules\TriggerCorrelation\Lib\RuleEngine(\Modules\TriggerCorrelation\Lib\ZabbixApiClient::fromConfig($settings), $settings))->inspectSources($rule);
                if (!$inspection['valid']) { throw new \InvalidArgumentException(implode(' ', $inspection['errors'])); }
                $rule['conditions'] = $inspection['conditions'];
            }
            $clearOld = null;
            $syncHosts = [];
            $settings = [];

            // If this save may have to create the engine host, find a working
            // evaluation URL now — the server-side probes take seconds and must
            // not run while the transaction below holds the module-row lock.
            ReceiverProvisioner::prepareForSave($store);

            // One critical section across all frontend nodes (module-row lock):
            // read the current rules, set up hosts, save, tidy up — or none of it.
            $store->withEvaluationLock('correlation', function () use ($store, $posted, &$rule, &$notes, &$clearOld, &$syncHosts, &$settings): void {
            CorrelationStore::transaction(function () use ($store, $posted, &$rule, &$notes, &$clearOld, &$syncHosts, &$settings): void {
                $config = $store->load();
                $settings = (array) ($config['settings'] ?? []);
                $rules = array_values((array) ($config['rules'] ?? []));
                $previous = null;
                foreach ($rules as $existing) {
                    if ((string) ($existing['id'] ?? '') === $rule['id']) {
                        $previous = $existing;
                        break;
                    }
                }
                $previousOutput = (array) ($previous['output'] ?? []);
                if (empty($rule['enabled']) && $previous !== null) {
                    // Disabling must work even after a dynamic source group disappears.
                    // Keep the actual output identity so the evaluator can still clear it.
                    if (($rule['source_selector']['mode'] ?? 'static') === 'group') {
                        $rule['conditions'] = (array) ($previous['conditions'] ?? []);
                    }
                    if (!empty($rule['output']['receiver_auto']) && !empty($previousOutput['receiver_auto'])) {
                        foreach (['receiver_host', 'receiver_hostid', 'receiver_host_name', 'receiver_hostset', 'receiver_hostset_ids', 'provisioned_at'] as $key) {
                            if (array_key_exists($key, $previousOutput)) { $rule['output'][$key] = $previousOutput[$key]; }
                        }
                    }
                }

                // A browser still running the previous version of the page does
                // not know the automatic flag; keep an automatic rule automatic.
                if (!empty($previousOutput['receiver_auto']) && $rule['output']['mode'] === 'receiver_lld'
                        && !array_key_exists('receiver_auto', (array) ($posted['output'] ?? []))) {
                    $rule['output']['receiver_auto'] = true;
                    $rule['output']['receiver_host'] = '';
                }

                // Automatic setup, under this Super Admin's session: the engine
                // host (heartbeat) whatever the output mode, and the shared
                // correlation host for this rule's source hosts in automatic mode.
                $provisioner = ReceiverProvisioner::forFrontend($settings, $store);
                if ($provisioner !== null && !empty($rule['enabled'])) {
                    try {
                        $provisioner->ensureTemplate(ReceiverProvisioner::ENGINE_TEMPLATE);
                        $notes = array_merge($notes, $provisioner->ensureEngineAndSecret($store, $settings));
                    }
                    catch (\Throwable $e) {
                        // The rule itself is still valid; report, do not block the save.
                        error_log('[TriggerCorrelation] engine host setup failed: '.$e->getMessage());
                        $notes[] = 'The engine host could not be set up automatically: '.Util::truncate($e->getMessage(), 200);
                    }
                    $config['settings'] = $settings;
                }

                if (!empty($rule['output']['receiver_auto']) && !empty($rule['enabled'])) {
                    if ($provisioner === null) {
                        throw new \InvalidArgumentException('The automatic correlation host can only be set up from a logged-in Zabbix frontend session.');
                    }

                    // When the source hosts change and no other rule shares the
                    // rule's current host, move that host to the new set instead of
                    // starting a new one, so history and open problems carry over.
                    $oldHostid = !empty($previousOutput['receiver_auto']) ? (string) ($previousOutput['receiver_hostid'] ?? '') : '';
                    $rekey = $oldHostid !== '' && self::usersOfHost($rules, $oldHostid, $rule['id']) === 0 ? $oldHostid : '';

                    $ruleHostids = array_values(array_filter(array_map(
                        static fn($r): string => !empty($r['output']['receiver_auto']) ? (string) ($r['output']['receiver_hostid'] ?? '') : '',
                        $rules
                    )));
                    try {
                        $host = $provisioner->ensureCorrelationHost($rule['conditions'], $rekey, $ruleHostids, (array) ($rule['source_selector'] ?? []));
                    }
                    catch (\Throwable $e) {
                        error_log('[TriggerCorrelation] correlation host setup failed: '.$e->getMessage());
                        throw new \InvalidArgumentException('Could not set up the correlation host: '.Util::truncate($e->getMessage(), 300));
                    }
                    $rule['output']['receiver_host'] = $host['host'];
                    $rule['output']['receiver_hostid'] = $host['hostid'];
                    $rule['output']['receiver_host_name'] = $host['name'];
                    $rule['output']['receiver_hostset'] = $host['key'];
                    $rule['output']['receiver_hostset_ids'] = ReceiverProvisioner::conditionHostIds($rule['conditions']);
                    $rule['output']['provisioned_at'] = $host['created'] || $host['rekeyed'] || $host['hostid'] !== $oldHostid
                        ? time() : (int) ($previousOutput['provisioned_at'] ?? time());

                    $sharing = self::usersOfHost($rules, $host['hostid'], $rule['id']);
                    if ($host['created']) {
                        $notes[] = 'Created the correlation host “'.$host['name'].'”. The first result appears within about 2 minutes.';
                    }
                    elseif ($host['rekeyed']) {
                        $notes[] = 'Moved this rule’s correlation host to the new source hosts (history kept): “'.$host['name'].'”.';
                    }
                    elseif ($host['hostid'] !== $oldHostid) {
                        $notes[] = 'Using the existing correlation host “'.$host['name'].'”'
                            .($sharing > 0 ? ', shared with '.$sharing.' other rule(s) over the same hosts.' : '.');
                    }
                }

                $found = false;
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
                            'last_correlation_comment_sig', 'last_correlation_eventid', 'last_source_comment_sig', 'last_successful', 'status', 'candidate_state', 'source_signature', 'source_members'] as $key) {
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

                $this->assertCorrelationIdUnique($rule, $rules, $settings);

                if ($provisioner !== null) {
                    // Keep what the provisioner recorded (managed host ids, secret).
                    $settings = $provisioner->settings();
                    $config['settings'] = $settings;
                }
                $config['rules'] = $rules;
                $store->save($config);

                // Mark (and, when enabled, retire after the grace period) the
                // automatic hosts no rule uses any more.
                if ($provisioner !== null && (!empty($rule['output']['receiver_auto']) || !empty($previousOutput['receiver_auto']))) {
                    try {
                        $sweep = $provisioner->sweepAutoHosts($rules, !empty($settings['auto_delete_hosts']) ? 'grace' : 'mark');
                        foreach ($sweep['deleted'] as $name) {
                            $notes[] = 'Removed the correlation host “'.$name.'” — unused for over a day.';
                        }
                    }
                    catch (\Throwable $e) {
                        error_log('[TriggerCorrelation] correlation host cleanup failed: '.$e->getMessage());
                    }
                }

                // Refresh discovery on the rule's new and old receiver hosts now.
                $syncHosts = array_values(array_unique(array_filter([
                    (string) ($rule['output']['receiver_host'] ?? ''),
                    $previous !== null && ($previousOutput['mode'] ?? 'receiver_lld') === 'receiver_lld'
                        ? (string) ($previousOutput['receiver_host'] ?? '') : ''
                ])));
            });

            // After the commit: resolve the rule's old target and push discovery,
            // in-process under this session (no API token needed for it).
            $evaluator = new CorrelationEvaluator($store, ZabbixApiClient::fromFrontend($settings));
            if ($clearOld !== null) {
                try {
                    $evaluator->clearRule($clearOld);
                }
                catch (\Throwable $e) {
                    // best effort
                }
            }
            try {
                $evaluator->syncDiscovery($syncHosts);
            }
            catch (\Throwable $e) {
                // best effort — the next heartbeat pushes discovery anyway
            }

            });

            if (trim((string) ($settings['api_url'] ?? '')) === '' || CorrelationStore::apiToken($settings) === '') {
                $notes[] = 'Automatic evaluation still needs the Zabbix API URL and an API token: Settings → Zabbix API.';
            }
            elseif (!empty($rule['output']['receiver_auto']) && (string) ($rule['output']['receiver_hostid'] ?? '') !== '') {
                // The evaluator writes with the API token: make sure its user can
                // see the correlation host, or every push would fail forever.
                try {
                    $visible = (array) ZabbixApiClient::fromConfig($settings)->call('host.get', [
                        'output' => ['hostid'],
                        'hostids' => [(string) $rule['output']['receiver_hostid']]
                    ]);
                    if ($visible === []) {
                        $notes[] = 'Warning: the API token’s user cannot see the correlation host “'.(string) $rule['output']['receiver_host_name']
                            .'”. Give its user group at least Read on that host group, or the correlation cannot be written.';
                    }
                }
                catch (\Throwable $e) {
                    $notes[] = 'Could not check the API token against the correlation host: '.Util::truncate($e->getMessage(), 160);
                }
            }

            $this->jsonResponse(['ok' => true, 'notes' => $notes] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] rule save failed: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save the rule.'], 500);
        }
    }

    /**
     * Identity of a rule's receiver host: the host id for automatic rules (their
     * technical name can change), the technical name otherwise.
     */
    private static function receiverKey(array $output, string $defaultReceiver): string {
        if (!empty($output['receiver_auto']) && (string) ($output['receiver_hostid'] ?? '') !== '') {
            return 'id:'.(string) $output['receiver_hostid'];
        }
        return 'name:'.(trim((string) ($output['receiver_host'] ?? '')) ?: $defaultReceiver);
    }

    /** How many rules other than $ruleId use the host $hostid as their receiver. */
    private static function usersOfHost(array $rules, string $hostid, string $ruleId): int {
        $count = 0;
        foreach ($rules as $other) {
            if ((string) ($other['id'] ?? '') !== $ruleId
                    && (string) ($other['output']['receiver_hostid'] ?? '') === $hostid) {
                $count++;
            }
        }
        return $count;
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
        $api = ZabbixApiClient::fromFrontend($settings);
        if ($api === null) { throw new \InvalidArgumentException('A frontend session is required to validate the output.'); }
        $identity = new \Modules\TriggerCorrelation\Lib\OutputIdentity([$api, 'call']);
        $other = $identity->findConflict($rule, $rules, $settings);
        if ($other !== null) {
            throw new \InvalidArgumentException('Another rule ("'.(string) ($other['name'] ?? $other['id'] ?? '')
                .'") writes to the same output item. Choose a different Correlation ID or output item.');
        }
    }

    private function normalizeRule(array $rule): array {
        return \Modules\TriggerCorrelation\Lib\RuleDefinition::normalize($rule, 'correlation');
    }
}
