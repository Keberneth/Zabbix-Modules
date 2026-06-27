<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\Util;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ZabbixApiClient.php';

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
                : $this->check('eval_secret', 'Evaluation shared secret', 'fail', 'Not set — the eval endpoint rejects every call (Access denied). Set it here and the matching {$TRIGGER.CORRELATION.TOKEN} macro on the receiver host.');

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

            $this->jsonResponse(['ok' => true, 'checks' => $checks, 'eval_url' => $evalUrl]);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => Util::truncate($e->getMessage(), 300)], 500);
        }
    }

    private function check(string $key, string $label, string $status, string $message): array {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    }

    private static function evalUrl(): string {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/.');
        return $scheme.'://'.$host.$base.'/modules/TriggerCorrelation/eval.php';
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
        curl_setopt_array($ch, [
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
            return $this->check('eval_reach', 'eval.php reachable', 'ok', 'Reachable and token-gated (returned "Invalid evaluation token"). Point {$TRIGGER.CORRELATION.URL} at it.');
        }
        if (stripos($bodyStr, '<?php') !== false) {
            return $this->check('eval_reach', 'eval.php reachable', 'fail', 'The web server returns eval.php as source instead of executing it. Route this .php to php-fpm (standard Zabbix nginx already does; on Apache add a handler).');
        }
        if ($code === 404 || stripos($bodyStr, 'Page not found') !== false) {
            return $this->check('eval_reach', 'eval.php reachable', 'fail', 'Got 404 / Page not found. Re-deploy the module and ensure the web server serves /modules/TriggerCorrelation/eval.php.');
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
