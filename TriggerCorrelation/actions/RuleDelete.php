<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

class RuleDelete extends CController {
    use JsonResponse;

    // State-changing POST: framework CSRF stays enabled.

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $id = trim((string) ($_POST['id'] ?? ''));
            if ($id === '') {
                throw new \InvalidArgumentException('Rule id is required.');
            }

            $store = new CorrelationStore();
            $removed = null;
            $notes = [];
            $settings = [];
            $hostStillUsed = false;

            CorrelationStore::transaction(function () use ($store, $id, &$removed, &$notes, &$settings, &$hostStillUsed): void {
                $config = $store->load();
                $settings = (array) ($config['settings'] ?? []);
                $kept = [];
                foreach (array_values((array) ($config['rules'] ?? [])) as $rule) {
                    if ((string) ($rule['id'] ?? '') === $id) {
                        $removed = $rule;
                    }
                    else {
                        $kept[] = $rule;
                    }
                }

                $config['rules'] = $kept;
                $store->save($config);

                $removedHostid = (string) ($removed['output']['receiver_hostid'] ?? '');
                $hostStillUsed = $removedHostid !== '' && isset(ReceiverProvisioner::referencedHosts($kept)['id:'.$removedHostid]);

                // The rule's automatic correlation host is kept (it holds the
                // problem history); it is only marked unused, and retired after a
                // day when Settings → "Delete unused correlation hosts" is on.
                try {
                    $provisioner = ReceiverProvisioner::forFrontend($settings, $store);
                    if ($provisioner !== null) {
                        $sweep = $provisioner->sweepAutoHosts($kept, !empty($settings['auto_delete_hosts']) ? 'grace' : 'mark');
                        foreach ($sweep['deleted'] as $name) {
                            $notes[] = 'Removed the correlation host “'.$name.'” — unused for over a day.';
                        }
                    }
                }
                catch (\Throwable $e) {
                    error_log('[TriggerCorrelation] correlation host cleanup failed: '.$e->getMessage());
                }
            });

            // After the commit: resolve the correlation problem (push 0) so it does
            // not stick at the last severity, then refresh the host's discovery so
            // the rule's item ages out. In-process, best effort.
            if ($removed !== null) {
                $evaluator = new CorrelationEvaluator($store, ZabbixApiClient::fromFrontend($settings));
                try {
                    $evaluator->clearRule($removed);
                }
                catch (\Throwable $e) {
                    // best effort — never block the delete
                }
                $output = (array) ($removed['output'] ?? []);
                if ((string) ($output['mode'] ?? 'receiver_lld') === 'receiver_lld') {
                    try {
                        $evaluator->syncDiscovery([(string) ($output['receiver_host'] ?? '')]);
                    }
                    catch (\Throwable $e) {
                        // best effort
                    }
                }
                if (!empty($output['receiver_auto']) && !$hostStillUsed && empty($settings['auto_delete_hosts'])) {
                    $notes[] = 'Its correlation host “'.(string) (($output['receiver_host_name'] ?? '') ?: ($output['receiver_host'] ?? ''))
                        .'” was kept with its history; delete unused hosts from Settings → Automatic setup.';
                }
            }

            $this->jsonResponse(['ok' => true, 'deleted' => $removed !== null ? 1 : 0, 'notes' => $notes] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] rule delete failed: '.$e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to delete the rule.'], 500);
        }
    }
}
