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
 * The explicit "fix it" actions behind the Settings tab buttons. Kept apart from
 * the self-check, which is read-only (and runs without CSRF): everything here
 * changes Zabbix objects, so it is a CSRF-protected Super Admin POST.
 *
 *   op=repair      import missing templates, create/repair the engine host (a
 *                  refused heartbeat gets a new secret, an unreachable one a
 *                  server-verified URL), recreate missing correlation hosts,
 *                  push discovery and ask the server to run the heartbeat now
 *   op=cleanup     delete automatic correlation hosts no rule uses (never one
 *                  with an open problem)
 *   op=detect_api  find a working Zabbix API URL for the evaluator and save it
 */
class SetupRepair extends CController {
    use JsonResponse;

    /** The API URL op=detect_api found and saved ('' when none answered). */
    private string $detectedUrl = '';

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $op = (string) ($_POST['op'] ?? 'repair');
            $store = new CorrelationStore();

            switch ($op) {
                case 'cleanup':
                    $notes = $this->cleanup($store);
                    break;
                case 'detect_api':
                    $notes = $this->detectApi($store);
                    break;
                case 'repair':
                    $notes = $this->repair($store);
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown operation.');
            }

            $this->jsonResponse(['ok' => true, 'notes' => $notes, 'detected_url' => $this->detectedUrl] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] setup/repair failed: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            // Super Admin only, and the message is what they need to act on.
            $this->jsonResponse(['ok' => false, 'error' => 'Repair failed: '.Util::truncate($e->getMessage(), 300)], 500);
        }
    }

    private function repair(CorrelationStore $store): array {
        $notes = [];
        $settings = [];
        $receivers = [];

        // Server-side URL probes take seconds: run them before taking the lock,
        // when an engine has to be created or cannot reach eval.php.
        $preSettings = (array) (($store->load())['settings'] ?? []);
        $pre = ReceiverProvisioner::forFrontend($preSettings);
        if ($pre !== null) {
            $engines = $pre->findEngineHosts();
            $unreachable = array_filter($pre->managedEngines(), static fn(array $e): bool => $e['state'] === 1 && !ReceiverProvisioner::heartbeatRejected($e));
            if ($engines === [] || $unreachable !== []) {
                $pre->prepareEvalUrl();
            }
        }

        CorrelationStore::transaction(function () use ($store, &$notes, &$settings, &$receivers): void {
            $config = $store->load();
            $settings = (array) ($config['settings'] ?? []);
            $provisioner = ReceiverProvisioner::forFrontend($settings, $store);
            if ($provisioner === null) {
                throw new \RuntimeException('No frontend session.');
            }

            $provisioner->ensureTemplate(ReceiverProvisioner::ENGINE_TEMPLATE);
            $provisioner->ensureTemplate(ReceiverProvisioner::AUTO_TEMPLATE);
            if ($provisioner->keepLostItemsEnabled()) {
                $notes[] = 'Updated the engine template so a deleted or disabled rule’s item stays enabled until it is cleared (installed by an older version).';
            }

            $notes = array_merge($notes, $provisioner->ensureEngineAndSecret($store, $settings, true));

            // A heartbeat the server cannot deliver (connection error, 404 …) gets
            // the first URL the server itself proves it can reach.
            foreach ($provisioner->managedEngines(true) as $engine) {
                if ($engine['state'] === 1 && !ReceiverProvisioner::heartbeatRejected($engine)) {
                    $resolved = $provisioner->resolveEvalUrl();
                    if (!empty($resolved['verified'])) {
                        $provisioner->syncEngineMacros($resolved['url'], null);
                        $notes[] = 'Pointed the engine host at '.$resolved['url'].' (verified from the Zabbix server).';
                    }
                    else {
                        $notes[] = $resolved['note'];
                    }
                    break;
                }
            }

            // Recreate the correlation host of any automatic rule that lost it.
            $rules = array_values((array) ($config['rules'] ?? []));
            $existing = [];
            $ids = array_values(array_filter(array_map(static fn($r): string => !empty($r['output']['receiver_auto'])
                ? (string) ($r['output']['receiver_hostid'] ?? '') : '', $rules)));
            if ($ids !== []) {
                foreach ((array) ZabbixApiClient::fromFrontend($settings)->call('host.get', ['output' => ['hostid'], 'hostids' => $ids]) as $row) {
                    $existing[(string) $row['hostid']] = true;
                }
            }
            $changed = false;
            foreach ($rules as $i => $rule) {
                $output = (array) ($rule['output'] ?? []);
                if (empty($output['receiver_auto'])) {
                    if (($output['mode'] ?? 'receiver_lld') === 'receiver_lld') {
                        $receivers[] = (string) (($output['receiver_host'] ?? '') ?: ($settings['receiver_host'] ?? ''));
                    }
                    continue;
                }
                if (!isset($existing[(string) ($output['receiver_hostid'] ?? '')])) {
                    $host = $provisioner->ensureCorrelationHost((array) ($rule['conditions'] ?? []), '', array_keys($existing));
                    $rules[$i]['output']['receiver_host'] = $host['host'];
                    $rules[$i]['output']['receiver_hostid'] = $host['hostid'];
                    $rules[$i]['output']['receiver_host_name'] = $host['name'];
                    $rules[$i]['output']['receiver_hostset'] = $host['key'];
                    $rules[$i]['output']['provisioned_at'] = time();
                    $changed = true;
                    $notes[] = ($host['created'] ? 'Recreated' : 'Reconnected').' the correlation host “'.$host['name']
                        .'” for rule “'.(string) ($rule['name'] ?? '').'”.';
                }
                $receivers[] = (string) $rules[$i]['output']['receiver_host'];
            }
            $provisioner->adoptRuleHosts(array_keys($existing));
            $config['settings'] = $provisioner->settings();
            $settings = $config['settings'];
            if ($changed) {
                $config['rules'] = $rules;
                $store->save($config);
            }

            $provisioner->sweepAutoHosts($rules, 'mark');
        });

        // After the commit: clear correlation states no rule owns any more, then
        // refresh discovery everywhere and have the server run the heartbeat now,
        // so the result shows up in the self-check within seconds.
        try {
            $cleared = $this->clearOrphanStates($store, $settings);
            if ($cleared !== []) {
                $notes[] = 'Cleared '.count($cleared).' correlation state(s) left behind by rules that no longer exist: '
                    .Util::truncate(implode(', ', $cleared), 240).'.';
            }
        }
        catch (\Throwable $e) {
            $notes[] = 'Could not clear left-over correlation states: '.Util::truncate($e->getMessage(), 160);
        }
        try {
            $restored = $this->restoreOrphanSeverities($store, $settings);
            if ($restored !== []) {
                $notes[] = 'Restored the original severity of '.count($restored).' problem(s) still raised by escalation rules that no longer exist: '
                    .Util::truncate(implode(', ', $restored), 240).'.';
            }
        }
        catch (\Throwable $e) {
            $notes[] = 'Could not check for left-over raised severities: '.Util::truncate($e->getMessage(), 160);
        }
        try {
            (new CorrelationEvaluator($store, ZabbixApiClient::fromFrontend($settings)))->syncDiscovery($receivers);
        }
        catch (\Throwable $e) {
            // best effort
        }
        try {
            $provisioner = ReceiverProvisioner::forFrontend($settings);
            if ($provisioner !== null && $provisioner->checkHeartbeatNow() > 0) {
                $notes[] = 'Asked the Zabbix server to run the heartbeat now — run the self-check in a few seconds to see the result.';
            }
        }
        catch (\Throwable $e) {
            $notes[] = 'Could not ask the Zabbix server to run the heartbeat now: '.Util::truncate($e->getMessage(), 160);
        }

        if (trim((string) ($settings['api_url'] ?? '')) === '' || CorrelationStore::apiToken($settings) === '') {
            $notes[] = 'Automatic evaluation still needs the Zabbix API URL and an API token: Settings → Zabbix API.';
        }

        return $notes !== [] ? $notes : ['Everything was already in place.'];
    }

    /**
     * A correlation state item that is still non-zero but belongs to no rule —
     * typically because the module's configuration was lost (Modules → Scan on
     * a node without the module directory deletes it) — keeps its problem open
     * forever. Push 0 to each such item on the engine and automatic hosts.
     * Returns "host: key" labels of the items cleared.
     */
    private function clearOrphanStates(CorrelationStore $store, array $settings): array {
        $api = ZabbixApiClient::fromFrontend($settings);
        $provisioner = ReceiverProvisioner::forFrontend($settings);
        if ($api === null || $provisioner === null) {
            return [];
        }

        // Only the module's own hosts — never another host that happens to carry
        // a heartbeat item or our tags.
        $hostids = array_merge(array_column($provisioner->managedEngines(true), 'hostid'), array_keys($provisioner->autoHosts()));
        if ($hostids === []) {
            return [];
        }

        // Keys each receiver host is expected to carry for the current rules —
        // automatic rules by host ID (their host may have been renamed), the
        // others by technical name.
        $config = $store->load();
        $owned = [];
        foreach ((array) ($config['rules'] ?? []) as $rule) {
            $output = (array) ($rule['output'] ?? []);
            if ((string) ($output['mode'] ?? 'receiver_lld') !== 'receiver_lld') {
                continue;
            }
            $slug = CorrelationStore::slug((string) (trim((string) ($output['correlation_id'] ?? '')) ?: ($rule['name'] ?? '')));
            $key = 'trigger.correlation.state['.$slug.']';
            if (!empty($output['receiver_auto']) && (string) ($output['receiver_hostid'] ?? '') !== '') {
                $owned['id:'.(string) $output['receiver_hostid']."\0".$key] = true;
            }
            else {
                $host = trim((string) ($output['receiver_host'] ?? '')) ?: trim((string) ($settings['receiver_host'] ?? ''));
                $owned['name:'.$host."\0".$key] = true;
            }
        }

        $items = (array) $api->call('item.get', [
            'output' => ['itemid', 'hostid', 'key_', 'lastvalue'],
            'selectHosts' => ['host', 'name'],
            'selectTriggers' => ['value'],
            'hostids' => array_values(array_unique($hostids)),
            'search' => ['key_' => 'trigger.correlation.state['],
            'startSearch' => true
        ]);

        $rows = [];
        $labels = [];
        foreach ($items as $item) {
            $host = (string) ($item['hosts'][0]['host'] ?? '');
            if (isset($owned['id:'.(string) $item['hostid']."\0".(string) $item['key_']])
                    || isset($owned['name:'.$host."\0".(string) $item['key_']])) {
                continue;
            }
            // lastvalue only covers recent history, so an old stuck state reads
            // "0" while its trigger is still in PROBLEM: look at the triggers too.
            $firing = in_array('1', array_map(static fn($t): string => (string) ($t['value'] ?? '0'), (array) ($item['triggers'] ?? [])), true);
            if (!$firing && (string) ($item['lastvalue'] ?? '0') === '0') {
                continue;
            }
            $rows[] = ['itemid' => (string) $item['itemid'], 'value' => 0, 'clock' => time()];
            $labels[] = (string) (($item['hosts'][0]['name'] ?? '') ?: $host).': '.(string) $item['key_'];
        }
        if ($rows === []) {
            return [];
        }

        // Report only what the server accepted (a disabled item refuses the 0).
        $result = $api->historyPush($rows);
        $cleared = [];
        foreach (array_values((array) ($result['data'] ?? [])) as $i => $row) {
            if (is_array($row) && trim((string) ($row['error'] ?? '')) === '' && isset($labels[$i])) {
                $cleared[] = $labels[$i];
            }
        }
        return $cleared;
    }

    /**
     * Severity escalation remembers what it raised in its own rule state. If
     * that state is lost (rule deleted outside the UI, or the configuration
     * wiped) the problems stay raised for good. Every change the module makes
     * carries a "[TC severity]" message, and Zabbix records the old and new
     * severity of each update, so the original can be put back — but only where
     * no current rule holds the problem and nobody changed the severity since.
     * Returns "host: problem" labels of the problems restored.
     */
    private function restoreOrphanSeverities(CorrelationStore $store, array $settings): array {
        $api = ZabbixApiClient::fromFrontend($settings);
        if ($api === null) {
            return [];
        }

        $held = [];
        foreach ((array) (($store->load())['severity_rules'] ?? []) as $rule) {
            foreach (array_keys((array) ($rule['applied'] ?? [])) as $eventid) {
                $held[(string) $eventid] = true;
            }
        }

        $problems = (array) $api->call('problem.get', [
            'output' => ['eventid', 'objectid', 'name', 'severity'],
            'selectAcknowledges' => ['clock', 'action', 'message', 'old_severity', 'new_severity'],
            'source' => 0,
            'object' => 0,
            'recent' => false,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit' => 5000
        ]);

        $restore = [];
        foreach ($problems as $p) {
            $eventid = (string) $p['eventid'];
            if (isset($held[$eventid])) {
                continue;
            }
            $changes = array_values(array_filter((array) ($p['acknowledges'] ?? []),
                static fn($a): bool => ((int) ($a['action'] ?? 0) & 8) === 8));
            if ($changes === []) {
                continue;
            }
            usort($changes, static fn($a, $b): int => (int) $a['clock'] <=> (int) $b['clock']);
            $last = $changes[count($changes) - 1];
            // Only when the latest severity change is the module's own raise.
            if (!str_starts_with((string) ($last['message'] ?? ''), '[TC severity]')
                    || (int) $last['new_severity'] <= (int) $last['old_severity']
                    || (int) $p['severity'] !== (int) $last['new_severity']) {
                continue;
            }
            // Walk back over the module's consecutive raises to the original.
            $original = (int) $last['old_severity'];
            for ($i = count($changes) - 2; $i >= 0; $i--) {
                if (!str_starts_with((string) ($changes[$i]['message'] ?? ''), '[TC severity]')
                        || (int) $changes[$i]['new_severity'] <= (int) $changes[$i]['old_severity']
                        || (int) $changes[$i]['new_severity'] !== $original) {
                    break;
                }
                $original = (int) $changes[$i]['old_severity'];
            }
            $restore[$eventid] = ['to' => $original, 'objectid' => (string) $p['objectid'], 'name' => (string) $p['name']];
        }
        if ($restore === []) {
            return [];
        }

        $hostOf = [];
        foreach ((array) $api->call('trigger.get', [
            'output' => ['triggerid'],
            'selectHosts' => ['name'],
            'triggerids' => array_values(array_unique(array_column($restore, 'objectid')))
        ]) as $t) {
            $hostOf[(string) $t['triggerid']] = (string) ($t['hosts'][0]['name'] ?? '');
        }

        $labels = [];
        foreach ($restore as $eventid => $info) {
            $api->setEventSeverity((string) $eventid, $info['to'],
                '[TC severity] Restored the original severity: the escalation rule that raised it no longer exists.');
            $labels[] = ($hostOf[$info['objectid']] ?? '').': '.$info['name'];
        }
        return $labels;
    }

    private function cleanup(CorrelationStore $store): array {
        $report = ['deleted' => [], 'kept_open' => [], 'unused' => []];

        CorrelationStore::transaction(function () use ($store, &$report): void {
            $config = $store->load();
            $provisioner = ReceiverProvisioner::forFrontend((array) ($config['settings'] ?? []), $store);
            if ($provisioner === null) {
                throw new \RuntimeException('No frontend session.');
            }
            $report = $provisioner->sweepAutoHosts(array_values((array) ($config['rules'] ?? [])), 'now');
        });

        $notes = [];
        if ($report['deleted'] !== []) {
            $notes[] = 'Deleted '.count($report['deleted']).' unused correlation host(s): '.Util::truncate(implode(', ', $report['deleted']), 300).'.';
        }
        if ($report['kept_open'] !== []) {
            $notes[] = 'Kept '.Util::truncate(implode(', ', $report['kept_open']), 200).' — it still has an open problem; try again once it has resolved.';
        }
        return $notes !== [] ? $notes : ['No unused correlation hosts.'];
    }

    /**
     * Find an API URL the evaluator can use (it runs on this same web server, so
     * the loopback address of this server is the most robust choice in Docker).
     * apiinfo.version needs no authentication, so nothing secret is sent.
     */
    private function detectApi(CorrelationStore $store): array {
        $config = $store->load();
        $settings = (array) ($config['settings'] ?? []);

        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/.');
        $scheme = $https ? 'https' : 'http';
        $portPart = ($port > 0 && $port !== ($https ? 443 : 80)) ? ':'.$port : '';

        $candidates = [$scheme.'://127.0.0.1'.$portPart.$dir.'/api_jsonrpc.php'];
        $provisioner = ReceiverProvisioner::forFrontend($settings);
        $frontendUrl = $provisioner !== null ? $provisioner->frontendUrl() : '';
        if ($frontendUrl !== '') {
            $candidates[] = rtrim(preg_replace('#/(?:[^/]*\.php)?(?:\?.*)?$#', '', $frontendUrl) ?? $frontendUrl, '/').'/api_jsonrpc.php';
        }
        $candidates[] = ZabbixApiClient::deriveApiUrl();

        foreach (array_values(array_unique($candidates)) as $url) {
            try {
                $client = new ZabbixApiClient($url, '', 'auto', (bool) ($settings['verify_peer'] ?? true), 5, 'http', true);
                $version = $client->version();
                if ($version !== '') {
                    CorrelationStore::transaction(static function () use ($store, $url): void {
                        $store->updateSettings(['api_url' => $url]);
                    });
                    $this->detectedUrl = $url;
                    return ['Found the Zabbix API at '.$url.' (version '.$version.') and saved it as the API URL.'];
                }
            }
            catch (\Throwable $e) {
                // try the next candidate
            }
        }

        return ['No API URL answered. Enter it by hand: the address this web server reaches its own api_jsonrpc.php at.'];
    }
}
