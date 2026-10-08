<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\Util;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

/**
 * Diagnoses the evaluation pipeline and reports exactly what is missing or wrong:
 * API URL/token, the evaluation shared secret, whether the token API path works,
 * and whether the standalone eval.php endpoint is reachable + token-gated.
 */
class SelfCheck extends CController {
    use JsonResponse;

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
            $store = new CorrelationStore();
            $config = $store->load();
            $settings = (array) ($config['settings'] ?? []);
            $checks = [];

            // 1. API URL
            $apiUrl = trim((string) ($settings['api_url'] ?? ''));
            $checks[] = $apiUrl !== ''
                ? $this->check('api_url', 'API URL', 'ok', $apiUrl)
                : $this->check('api_url', 'API URL', 'fail', 'Not set. Required: the eval endpoint will not send the API token to a URL derived from the request host.');

            // 2. API token
            $hasToken = CorrelationStore::apiToken($settings) !== '';
            $checks[] = $hasToken
                ? $this->check('api_token', 'API token', 'ok', 'Configured.')
                : $this->check('api_token', 'API token', 'fail', 'Not set — the eval endpoint cannot read problems or push state.');

            // 3. Zabbix API reachable via the token path (exactly what eval.php uses)
            try {
                $api = ZabbixApiClient::fromConfig($settings);
                $version = '';
                try { $version = $api->version(); } catch (\Throwable $e) { $version = ''; }
                $hostCount = $api->hostCount();
                $checks[] = $this->check('api_reach', 'Zabbix API reachable (token path)', 'ok',
                    'Connected'.($version !== '' ? ' (v'.$version.')' : '').', '.$hostCount.' hosts visible.');
            }
            catch (\Throwable $e) {
                $checks[] = $this->check('api_reach', 'Zabbix API reachable (token path)', 'fail', Util::truncate($e->getMessage(), 300));
            }

            // 4. Evaluation shared secret
            $evalHash = trim((string) ($settings['eval_token_hash'] ?? ''));
            $evalEnv = trim((string) ($settings['eval_token_env'] ?? ''));
            $evalEnvSet = $evalEnv !== '' && is_string(getenv($evalEnv)) && getenv($evalEnv) !== '';
            $checks[] = ($evalHash !== '' || $evalEnvSet)
                ? $this->check('eval_secret', 'Evaluation shared secret', 'ok', 'Configured.'.($evalEnvSet ? ' (from environment variable)' : ''))
                : $this->check('eval_secret', 'Evaluation shared secret', 'warn', 'Not set yet — it is generated and copied to the engine host automatically when you save a rule (or click “Repair automatic setup”).');

            // 5. Rules
            $rules = array_values((array) ($config['rules'] ?? []));
            $enabled = count(array_filter($rules, static fn($r): bool => (bool) ($r['enabled'] ?? true)));
            $checks[] = $this->check('rules', 'Rules', $rules ? 'ok' : 'warn',
                count($rules).' rule(s), '.$enabled.' enabled.');

            // 6. eval.php deployed + reachable + token-gated
            $evalUrl = self::evalUrl();
            $checks[] = $this->check('eval_file', 'eval.php deployed', is_file(dirname(__DIR__).'/eval.php') ? 'ok' : 'fail',
                is_file(dirname(__DIR__).'/eval.php') ? 'Present in the module directory.' : 'Missing — re-deploy the module.');

            // 6b. Reproduce eval.php's OWN database connection in-process. This runs
            // in the same php-fpm worker eval.php uses, so a super-admin sees the
            // real connection error here instead of eval.php's generic 500.
            try {
                $pdo = CorrelationStore::connectStandalone();
                $pdo->query('SELECT 1')->fetchColumn();
                $checks[] = $this->check('eval_db', 'Database (eval.php path)', 'ok',
                    'eval.php can open its own direct connection to the Zabbix database.');
            }
            catch (\Throwable $e) {
                $checks[] = $this->check('eval_db', 'Database (eval.php path)', 'fail',
                    'eval.php cannot connect to the database: '.Util::truncate($e->getMessage(), 300));
            }

            $checks[] = $this->probeEval($evalUrl, (bool) ($settings['verify_peer'] ?? true));

            // 7-8. Automatic setup: the engine heartbeat as the Zabbix SERVER sees
            // it, and the correlation hosts the rules point at.
            foreach ($this->setupChecks($settings, $rules) as $check) {
                $checks[] = $check;
            }

            $this->jsonResponse(['ok' => true, 'checks' => $checks]);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => Util::truncate($e->getMessage(), 300)], 500);
        }
    }

    private function setupChecks(array $settings, array $rules): array {
        $checks = [];
        try {
            $provisioner = ReceiverProvisioner::forFrontend($settings);
            if ($provisioner === null) {
                return [];
            }
            $allHeartbeats = $provisioner->findEngineHosts();
            $engines = $provisioner->managedEngines();
        }
        catch (\Throwable $e) {
            return [$this->check('engine', 'Engine host (heartbeat)', 'warn', 'Could not inspect: '.Util::truncate($e->getMessage(), 200))];
        }

        // Heartbeat hosts the module does not manage: shown, never written to.
        $managedIds = array_flip(array_column($engines, 'hostid'));
        $foreign = array_values(array_filter($allHeartbeats, static fn(array $e): bool => !isset($managedIds[$e['hostid']])));
        if ($foreign !== []) {
            $names = implode(', ', array_map(static fn(array $e): string => '“'.($e['name'] ?: $e['host']).'”', $foreign));
            $checks[] = $this->check('engine_foreign', 'Heartbeat hosts not managed by the module', $engines === [] ? 'warn' : 'warn',
                $engines === []
                    ? $names.' run the evaluation, but the module does not manage their secret and URL. Click “Repair automatic setup” to take over the one named like Settings → Engine host.'
                    : $names.' also call eval.php. Every extra heartbeat evaluates all rules again each minute — remove the item from hosts that should not drive the evaluation.');
        }
        if ($engines === []) {
            $engines = $foreign;
        }

        if ($engines === []) {
            $checks[] = $this->check('engine', 'Engine host (heartbeat)', $rules ? 'fail' : 'warn',
                'No host has the evaluator heartbeat yet, so nothing is evaluated automatically. It is created when you save a rule.');
        }
        else {
            $urls = [];
            try {
                foreach ((array) ZabbixApiClient::fromFrontend($settings)->call('usermacro.get', [
                    'output' => ['hostid', 'value', 'type'],
                    'hostids' => array_column($engines, 'hostid'),
                    'filter' => ['macro' => [ReceiverProvisioner::MACRO_URL]]
                ]) as $macro) {
                    $urls[(string) $macro['hostid']] = (string) ($macro['value'] ?? '');
                }
            }
            catch (\Throwable $e) {
                // the URL is only shown for information
            }
            foreach ($engines as $engine) {
                $label = 'Engine host “'.($engine['name'] ?: $engine['host']).'”';
                $at = ($urls[$engine['hostid']] ?? '') !== '' ? ' at '.$urls[$engine['hostid']] : '';
                if ($engine['host_status'] !== 0 || $engine['item_status'] !== 0) {
                    $checks[] = $this->check('engine', $label, 'fail', 'The host or its heartbeat item is disabled, so rules are not evaluated.');
                }
                elseif ($engine['state'] === 1) {
                    $checks[] = $this->check('engine', $label, 'fail', 'The Zabbix server cannot run the heartbeat'.$at.': '
                        .Util::truncate($engine['error'], 220).' — '.self::heartbeatHint($engine['error']));
                }
                elseif ($engine['lastclock'] > 0) {
                    $age = max(0, time() - $engine['lastclock']);
                    $checks[] = $this->check('engine', $label, $age <= 420 ? 'ok' : 'warn',
                        'The Zabbix server reached eval.php'.$at.' '.self::ago($age).'.'.($age > 420 ? ' That is older than expected (every minute) — check the Zabbix server.' : ''));
                }
                else {
                    $checks[] = $this->check('engine', $label, 'warn', 'Waiting for the first heartbeat (the server runs it within a minute or two of setup).');
                }
            }
            if (count($engines) > 1) {
                $checks[] = $this->check('engine_count', 'Engine hosts', 'warn',
                    count($engines).' hosts run the heartbeat, so every rule is evaluated several times a minute. Keep one (unlink the receiver template or disable the heartbeat item on the others).');
            }
        }

        // Who drives the evaluation besides (or instead of) the engine host.
        if ((string) ($settings['eval_driver'] ?? 'auto') === 'external') {
            $checks[] = $this->check('driver', 'Evaluation driver', 'ok', 'External (you call eval.php yourself) — the module never creates an engine host.');
        }
        elseif (ReceiverProvisioner::externalDriverActive($settings)) {
            $checks[] = $this->check('driver', 'Evaluation driver', $engines !== [] ? 'warn' : 'ok',
                'eval.php is also called by “'.Util::truncate((string) ($settings['driver_user_agent'] ?? ''), 60).'”.'
                .($engines !== [] ? ' Together with the engine host every rule is evaluated twice — keep one driver.' : ''));
        }

        // Where created hosts go, and who can see the problems raised there.
        try {
            $group = $provisioner->targetGroup();
            if ($group['groupid'] === '') {
                $checks[] = $this->check('group', 'Correlation host group', 'ok', '“'.$group['name'].'” — created with the first correlation host.');
            }
            else {
                $who = $provisioner->groupVisibility($group['groupid']);
                $checks[] = $who === []
                    ? $this->check('group', 'Correlation host group', 'warn', '“'.$group['name'].'” is visible only to Super Admins: operators will not see — or be notified about — correlation problems there. Grant their user groups Read on it.')
                    : $this->check('group', 'Correlation host group', 'ok', '“'.$group['name'].'” is visible to: '.Util::truncate(implode(', ', $who), 200).'.');
            }
        }
        catch (\Throwable $e) {
            $checks[] = $this->check('group', 'Correlation host group', 'warn', 'Could not inspect: '.Util::truncate($e->getMessage(), 200));
        }

        // Automatic correlation hosts no rule uses any more.
        try {
            $unused = [];
            $used = ReceiverProvisioner::referencedHosts($rules);
            foreach ($provisioner->autoHosts() as $id => $row) {
                if (!isset($used['id:'.$id]) && !isset($used['name:'.(string) $row['host']])) {
                    $unused[] = (string) (($row['name'] ?? '') ?: $row['host']);
                }
            }
            if ($unused !== []) {
                $checks[] = $this->check('unused_hosts', 'Unused correlation hosts', 'warn', count($unused).' kept with their history: '
                    .Util::truncate(implode(', ', $unused), 200).'. Use “Delete unused correlation hosts” to remove them.');
            }
        }
        catch (\Throwable $e) {
            // informational only
        }

        // Automatic correlation hosts referenced by rules still exist — by host
        // id, the identity the evaluator uses (a technical name can change)?
        $wanted = [];
        foreach ($rules as $rule) {
            $output = (array) ($rule['output'] ?? []);
            $id = !empty($output['receiver_auto']) ? trim((string) ($output['receiver_hostid'] ?? '')) : '';
            if ($id !== '') {
                $wanted[$id] = (string) (($output['receiver_host_name'] ?? '') ?: ($output['receiver_host'] ?? $id));
            }
        }
        if ($wanted !== []) {
            try {
                $api = ZabbixApiClient::fromFrontend($settings);
                $found = [];
                foreach ((array) ($api !== null ? $api->call('host.get', ['output' => ['hostid'], 'hostids' => array_keys($wanted)]) : []) as $row) {
                    $found[(string) $row['hostid']] = true;
                }
                $missing = array_values(array_diff_key($wanted, $found));
                $checks[] = $missing === []
                    ? $this->check('auto_hosts', 'Correlation hosts', 'ok', count($wanted).' automatic correlation host(s) in place.')
                    : $this->check('auto_hosts', 'Correlation hosts', 'fail', 'Missing: '.Util::truncate(implode(', ', $missing), 200)
                        .'. Use “Repair automatic setup” to recreate them.');

                // The evaluator writes with the API token: can its user see them?
                if ($missing === [] && CorrelationStore::apiToken($settings) !== '' && trim((string) ($settings['api_url'] ?? '')) !== '') {
                    $seen = [];
                    foreach ((array) ZabbixApiClient::fromConfig($settings)->call('host.get', ['output' => ['hostid'], 'hostids' => array_keys($wanted)]) as $row) {
                        $seen[(string) $row['hostid']] = true;
                    }
                    $hidden = array_values(array_diff_key($wanted, $seen));
                    $checks[] = $hidden !== []
                        ? $this->check('auto_hosts_token', 'API token can write correlation hosts', 'fail',
                            'The API token’s user cannot see: '.Util::truncate(implode(', ', $hidden), 200)
                            .'. Give its user group at least Read on the correlation host group.')
                        : $this->check('auto_hosts_token', 'API token can write correlation hosts', 'ok', 'The API token’s user can see every correlation host.');
                }
            }
            catch (\Throwable $e) {
                $checks[] = $this->check('auto_hosts', 'Correlation hosts', 'warn', 'Could not inspect: '.Util::truncate($e->getMessage(), 200));
            }
        }

        return $checks;
    }

    /** Turn the heartbeat item's error into the one thing to change. */
    private static function heartbeatHint(string $error): string {
        if (stripos($error, '401') !== false || stripos($error, 'token') !== false) {
            return 'the engine host’s {$TRIGGER.CORRELATION.TOKEN} does not match the evaluation secret. Click “Repair automatic setup” to give both a new one.';
        }
        if (stripos($error, '404') !== false || stripos($error, 'not found') !== false) {
            return 'the URL does not point at eval.php. Check Settings → Evaluation URL.';
        }
        if (stripos($error, '500') !== false) {
            return 'eval.php failed — see the “Database (eval.php path)” check and the web server PHP error log.';
        }
        return 'the server cannot reach that address. Click “Repair automatic setup” to let the Zabbix server find one that works, or set Settings → Evaluation URL (in Docker e.g. the web container’s service name).';
    }

    private static function ago(int $seconds): string {
        if ($seconds < 90) {
            return $seconds.'s ago';
        }
        if ($seconds < 5400) {
            return intdiv($seconds, 60).' min ago';
        }
        return intdiv($seconds, 3600).' h ago';
    }

    private function check(string $key, string $label, string $status, string $message): array {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    }

    /**
     * eval.php as THIS web server reaches itself: its own port on the loopback
     * address. The browser's address (HTTP_HOST) is often a published port that
     * does not exist inside a container or behind a proxy. Whether the Zabbix
     * server reaches it is checked separately (engine heartbeat).
     */
    private static function evalUrl(): string {
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $portPart = ($port > 0 && $port !== ($https ? 443 : 80)) ? ':'.$port : '';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/.');
        $relative = trim(CorrelationStore::moduleRelativePath(), '/') ?: 'modules/TriggerCorrelation';
        return ($https ? 'https' : 'http').'://127.0.0.1'.$portPart.$base.'/'.$relative.'/eval.php';
    }

    /**
     * Probe eval.php from the frontend with NO token: a correctly deployed,
     * web-server-routed endpoint answers 401 "Invalid evaluation token".
     */
    private function probeEval(string $url, bool $verifyPeer): array {
        if (!function_exists('curl_init')) {
            return $this->check('eval_reach', 'eval.php reachable', 'warn', 'Cannot probe (PHP cURL not available). Test it manually with curl.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->check('eval_reach', 'eval.php reachable', 'warn', 'Could not initialize the probe.');
        }
        // Loopback, but with the browser's Host header so a name-based virtual
        // host still routes the request to Zabbix.
        $hostHeader = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $hostHeader !== '' ? ['Host: '.$hostHeader] : [],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            // Reachability check only — self-signed frontends are common.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return $this->check('eval_reach', 'eval.php reachable', 'warn', 'Could not reach '.$url.' from the frontend: '.Util::truncate($err, 200).'. (The Zabbix server may still reach it.)');
        }

        $bodyStr = (string) $body;
        $decoded = json_decode($bodyStr, true);

        if ($code === 401 || (is_array($decoded) && isset($decoded['error']) && stripos((string) $decoded['error'], 'token') !== false)) {
            return $this->check('eval_reach', 'eval.php reachable', 'ok', 'Reachable on this web server and token-gated (returned "Invalid evaluation token").');
        }
        if (stripos($bodyStr, '<?php') !== false) {
            return $this->check('eval_reach', 'eval.php reachable', 'fail', 'The web server returns eval.php as source instead of executing it. Route this .php to php-fpm (standard Zabbix nginx already does; on Apache add a handler).');
        }
        if ($code === 404 || stripos($bodyStr, 'Page not found') !== false) {
            // From loopback this can also be a virtual-host mismatch; the engine
            // heartbeat line below is the authoritative test.
            return $this->check('eval_reach', 'eval.php reachable', 'warn', 'This web server answered 404 for '.$url.' (from itself). If the engine heartbeat below is green this is only a virtual-host detail; otherwise make sure the web server serves /modules/TriggerCorrelation/eval.php.');
        }
        if ($code >= 500) {
            $stage = (is_array($decoded) && isset($decoded['stage'])) ? (string) $decoded['stage'] : '';
            if ($stage === 'database') {
                return $this->check('eval_reach', 'eval.php reachable', 'fail', 'eval.php runs but cannot reach the database (HTTP 500): it could not load zabbix.conf.php or connect to the DB as the web/php-fpm user. See the "Database (eval.php path)" check above for the exact reason, or the web frontend PHP error log (inside the web container if Zabbix runs in Docker/Podman) for "[TriggerCorrelation] eval.php database connect failed".');
            }
            return $this->check('eval_reach', 'eval.php reachable', 'fail', 'eval.php runs but returned an internal error (HTTP '.$code.'). Check the web frontend PHP error log (inside the web container if Zabbix runs in Docker/Podman) for "[TriggerCorrelation] eval.php failed".');
        }
        if (is_array($decoded)) {
            return $this->check('eval_reach', 'eval.php reachable', 'ok', 'Reachable (HTTP '.$code.').');
        }
        return $this->check('eval_reach', 'eval.php reachable', 'warn', 'Unexpected response (HTTP '.$code.') from '.$url.'.');
    }
}
