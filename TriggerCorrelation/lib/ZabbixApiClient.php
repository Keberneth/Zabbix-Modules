<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/CorrelationStore.php';

/**
 * Zabbix API access with two transports, mirroring the reference AI module:
 *
 *  - "frontend": Zabbix's in-process PHP API facade (API::Host()->get(), ...)
 *    under the current logged-in user's session. Preferred for the interactive
 *    rule-builder reads (host/trigger/item search, API self-test) so they need
 *    no API token and no fragile frontend -> HTTP -> frontend loop, which is
 *    exactly what breaks on split / load-balanced / Docker installs.
 *
 *  - "http": outbound JSON-RPC to api_jsonrpc.php with a bearer token. Required
 *    for the unattended evaluation endpoint (history.push, problem.get), which
 *    is called by the Zabbix server HTTP agent with no user session.
 */
final class ZabbixApiClient {

    private string $url;
    private string $token;
    private string $authMode;
    private bool $verifyPeer;
    private int $timeout;
    private string $transport;
    private bool $urlTrusted;
    private int $requestId = 1;
    private bool $urlChecked = false;

    public function __construct(
        string $url,
        string $token,
        string $authMode = 'auto',
        bool $verifyPeer = true,
        int $timeout = 15,
        string $transport = 'http',
        bool $urlTrusted = true
    ) {
        $this->url = trim($url);
        $this->token = Util::stripControlChars(trim($token));
        $this->authMode = in_array($authMode, ['auto', 'bearer', 'auth_property'], true) ? $authMode : 'auto';
        $this->verifyPeer = $verifyPeer;
        $this->timeout = max(3, $timeout);
        $this->transport = $transport === 'frontend' ? 'frontend' : 'http';
        // Whether $url came from explicit configuration (trusted) rather than
        // being derived from the incoming request host. The token is only ever
        // attached to a trusted URL.
        $this->urlTrusted = $urlTrusted;
    }

    /** Token-based HTTP client (used by the unattended eval/history.push path). */
    public static function fromConfig(array $settings): self {
        $configuredUrl = trim((string) ($settings['api_url'] ?? ''));
        $urlTrusted = $configuredUrl !== '';
        $url = $urlTrusted ? $configuredUrl : self::deriveApiUrl();

        return new self(
            $url,
            CorrelationStore::apiToken($settings),
            (string) ($settings['api_auth_mode'] ?? 'auto'),
            (bool) ($settings['verify_peer'] ?? true),
            (int) ($settings['timeout'] ?? 15),
            'http',
            $urlTrusted
        );
    }

    /** In-process facade, or null when there is no valid frontend user session. */
    public static function fromFrontend(array $settings = []): ?self {
        if (!self::canUseFrontendApi()) {
            return null;
        }

        return new self('', '', 'auto', true, (int) ($settings['timeout'] ?? 15), 'frontend');
    }

    /** Prefer the in-process facade; fall back to the token HTTP client. */
    public static function fromFrontendOrConfig(array $settings): self {
        return self::fromFrontend($settings) ?? self::fromConfig($settings);
    }

    public static function deriveApiUrl(): string {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/zabbix.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }
        return $scheme.'://'.$host.$dir.'/api_jsonrpc.php';
    }

    public function getUrl(): string {
        return $this->transport === 'frontend' ? 'in-process frontend API' : $this->url;
    }

    public function isFrontend(): bool {
        return $this->transport === 'frontend';
    }

    public function hasToken(): bool {
        return $this->transport === 'frontend' || $this->token !== '';
    }

    public function version(): string {
        $result = $this->call('apiinfo.version', []);
        if (is_array($result)) {
            return (string) ($result[0] ?? '');
        }
        return (string) $result;
    }

    public function hostCount(): int {
        $result = $this->call('host.get', ['countOutput' => true]);
        if (is_array($result)) {
            return (int) ($result[0] ?? 0);
        }
        return (int) $result;
    }

    public function call(string $method, array $params = []) {
        if ($this->transport === 'frontend') {
            return $this->callWithFrontendApi($method, $params);
        }

        // apiinfo.version must be called WITHOUT authentication in Zabbix 7.x.
        if ($method === 'apiinfo.version') {
            return $this->callOnce($method, $params, 'none');
        }

        if ($this->token === '') {
            throw new \RuntimeException('Zabbix API token is not configured. Set the API token or its environment variable.');
        }

        $modes = $this->authMode === 'auto' ? ['bearer', 'auth_property'] : [$this->authMode];

        $last_error = null;
        foreach ($modes as $mode) {
            try {
                return $this->callOnce($method, $params, $mode);
            }
            catch (\RuntimeException $e) {
                // Try the next auth mode on any failure (HTTP 401/403 and
                // localized API errors are not all the same string).
                $last_error = $e;
            }
        }

        throw $last_error ?: new \RuntimeException('Zabbix API request failed.');
    }

    public function searchHosts(string $q, string $triggerQ = '', int $limit = 25): array {
        $q = trim($q);
        $triggerQ = trim($triggerQ);
        $limit = max(1, min(100, $limit));

        if ($triggerQ !== '') {
            $triggers = $this->call('trigger.get', [
                'output' => ['triggerid', 'description', 'priority', 'status'],
                'selectHosts' => ['hostid', 'host', 'name', 'status'],
                'search' => ['description' => $triggerQ],
                'searchByAny' => true,
                'expandDescription' => true,
                'sortfield' => 'description',
                'limit' => 100
            ]);

            $hosts = [];
            foreach ((array) $triggers as $trigger) {
                foreach ((array) ($trigger['hosts'] ?? []) as $host) {
                    $hostid = (string) ($host['hostid'] ?? '');
                    if ($hostid === '') {
                        continue;
                    }
                    $label = (string) (($host['name'] ?? '') ?: ($host['host'] ?? '') ?: $hostid);
                    if ($q !== '' && stripos($label.' '.($host['host'] ?? ''), $q) === false) {
                        continue;
                    }
                    $hosts[$hostid] = [
                        'hostid' => $hostid,
                        'host' => (string) ($host['host'] ?? ''),
                        'name' => (string) ($host['name'] ?? ''),
                        'status' => (string) ($host['status'] ?? ''),
                        'label' => $label
                    ];
                    if (count($hosts) >= $limit) {
                        break 2;
                    }
                }
            }
            return array_values($hosts);
        }

        $params = [
            'output' => ['hostid', 'host', 'name', 'status'],
            'sortfield' => 'name',
            'limit' => $limit
        ];
        if ($q !== '') {
            $params['search'] = ['host' => $q, 'name' => $q];
            $params['searchByAny'] = true;
        }

        $hosts = $this->call('host.get', $params);
        return array_map(static function (array $host): array {
            $label = (string) (($host['name'] ?? '') ?: ($host['host'] ?? '') ?: ($host['hostid'] ?? ''));
            return [
                'hostid' => (string) ($host['hostid'] ?? ''),
                'host' => (string) ($host['host'] ?? ''),
                'name' => (string) ($host['name'] ?? ''),
                'status' => (string) ($host['status'] ?? ''),
                'label' => $label
            ];
        }, (array) $hosts);
    }

    public function searchTriggers(string $q, string $hostid = '', string $hostQ = '', int $limit = 50): array {
        $q = trim($q);
        $hostid = trim($hostid);
        $hostQ = trim($hostQ);
        $limit = max(1, min(100, $limit));

        $params = [
            'output' => ['triggerid', 'description', 'priority', 'status', 'value', 'expression'],
            'selectHosts' => ['hostid', 'host', 'name', 'status'],
            'expandDescription' => true,
            'sortfield' => 'description',
            'limit' => $limit
        ];

        if ($hostid !== '') {
            $params['hostids'] = [$hostid];
        }
        elseif ($hostQ !== '') {
            $hosts = $this->searchHosts($hostQ, '', 30);
            $hostids = array_values(array_filter(array_map(static fn(array $host): string => (string) $host['hostid'], $hosts)));
            if ($hostids === []) {
                return [];
            }
            $params['hostids'] = $hostids;
        }

        if ($q !== '') {
            $params['search'] = ['description' => $q];
        }

        $triggers = $this->call('trigger.get', $params);
        return array_map(static function (array $trigger): array {
            $hosts = array_values((array) ($trigger['hosts'] ?? []));
            $host_labels = array_map(static function (array $host): string {
                return (string) (($host['name'] ?? '') ?: ($host['host'] ?? '') ?: ($host['hostid'] ?? ''));
            }, $hosts);
            $description = (string) (($trigger['description'] ?? '') ?: ($trigger['triggerid'] ?? ''));
            return [
                'triggerid' => (string) ($trigger['triggerid'] ?? ''),
                'description' => $description,
                'priority' => (string) ($trigger['priority'] ?? ''),
                'status' => (string) ($trigger['status'] ?? ''),
                'value' => (string) ($trigger['value'] ?? ''),
                'expression' => (string) ($trigger['expression'] ?? ''),
                'hosts' => $hosts,
                'label' => $description.' — '.implode(', ', $host_labels)
            ];
        }, (array) $triggers);
    }

    public function searchItems(string $q, string $hostid = '', bool $trapperOnly = true, int $limit = 50): array {
        $q = trim($q);
        $hostid = trim($hostid);
        $limit = max(1, min(100, $limit));

        $params = [
            'output' => ['itemid', 'hostid', 'name', 'key_', 'type', 'value_type', 'status'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'sortfield' => 'name',
            'limit' => $limit
        ];
        if ($hostid !== '') {
            $params['hostids'] = [$hostid];
        }
        if ($q !== '') {
            $params['search'] = ['name' => $q, 'key_' => $q];
            $params['searchByAny'] = true;
        }
        if ($trapperOnly) {
            $params['filter'] = ['type' => 2];
        }

        $items = $this->call('item.get', $params);
        return array_map(static function (array $item): array {
            $hosts = array_values((array) ($item['hosts'] ?? []));
            $host = $hosts[0] ?? [];
            return [
                'itemid' => (string) ($item['itemid'] ?? ''),
                'hostid' => (string) ($item['hostid'] ?? ''),
                'host' => (string) (($host['host'] ?? '') ?: ($host['name'] ?? '')),
                'name' => (string) ($item['name'] ?? ''),
                'key_' => (string) ($item['key_'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'value_type' => (string) ($item['value_type'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'label' => (string) (($item['name'] ?? '') ?: ($item['key_'] ?? '') ?: ($item['itemid'] ?? '')).' — '.(string) ($item['key_'] ?? '')
            ];
        }, (array) $items);
    }

    public function searchHostGroups(string $q = '', int $limit = 25): array {
        $q = trim($q);
        $limit = max(1, min(100, $limit));

        $params = [
            'output' => ['groupid', 'name'],
            'sortfield' => 'name',
            'limit' => $limit
        ];
        if ($q !== '') {
            $params['search'] = ['name' => $q];
        }

        $groups = $this->call('hostgroup.get', $params);
        return array_map(static function (array $group): array {
            $name = (string) ($group['name'] ?? '');
            return [
                'groupid' => (string) ($group['groupid'] ?? ''),
                'name' => $name,
                'label' => $name !== '' ? $name : (string) ($group['groupid'] ?? '')
            ];
        }, (array) $groups);
    }

    public function activeProblemsForTrigger(string $triggerid, string $hostid = '', array $settings = []): array {
        $params = [
            // NOTE: 'symptom' is NOT a valid problem.get output field in Zabbix
            // 7.x (it is only a top-level filter, applied below). 'cause_eventid'
            // is used instead to tell cause vs symptom apart.
            'output' => ['eventid', 'objectid', 'name', 'clock', 'severity', 'acknowledged', 'suppressed', 'cause_eventid'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'recent' => false,
            'selectTags' => 'extend',
            'selectSuppressionData' => 'extend',
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC'
        ];
        if ($hostid !== '') {
            $params['hostids'] = [$hostid];
        }
        if ((bool) ($settings['ignore_suppressed'] ?? true)) {
            $params['suppressed'] = false;
        }
        if ((bool) ($settings['ignore_symptoms'] ?? true)) {
            $params['symptom'] = false;
        }

        return (array) $this->call('problem.get', $params);
    }

    public function historyPush(array $rows): array {
        if ($rows === []) {
            return ['response' => 'success', 'data' => []];
        }
        if ($this->transport === 'frontend') {
            throw new \RuntimeException('history.push requires the token HTTP transport.');
        }
        return (array) $this->call('history.push', $rows);
    }

    // ── Correlation comment injection (token transport) ────────────────────

    public function getHostId(string $host): string {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        $result = (array) $this->call('host.get', [
            'output' => ['hostid'],
            'filter' => ['host' => [$host]],
            'limit' => 1
        ]);
        return (string) ($result[0]['hostid'] ?? '');
    }

    /**
     * Active (unresolved) problems carrying a tag, newest first — used to locate
     * the synthetic correlation problem on the receiver host by its
     * correlation.id tag.
     */
    public function activeProblemsByTag(string $tag, string $value, string $hostid = ''): array {
        $params = [
            'output' => ['eventid', 'name', 'severity', 'clock', 'objectid', 'r_eventid'],
            'source' => 0,
            'object' => 0,
            'recent' => false,
            // operator 1 = Equal (0 = Like/Contains, which would also match a
            // correlation whose tag value merely contains this slug as a substring).
            'tags' => [['tag' => $tag, 'value' => $value, 'operator' => 1]],
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit' => 20
        ];
        if ($hostid !== '') {
            $params['hostids'] = [$hostid];
        }
        return (array) $this->call('problem.get', $params);
    }

    /** Trigger ids attached to an item (by itemid, or host technical name + key). */
    public function triggerIdsForItem(string $itemid, string $host = '', string $key = ''): array {
        $params = [
            'output' => ['itemid'],
            'selectTriggers' => ['triggerid'],
            'limit' => 5
        ];
        if ($itemid !== '') {
            $params['itemids'] = [$itemid];
        }
        elseif ($host !== '' && $key !== '') {
            // item.get has no top-level 'host' parameter; resolve the host id first.
            $hostid = $this->getHostId($host);
            if ($hostid === '') {
                return [];
            }
            $params['hostids'] = [$hostid];
            $params['filter'] = ['key_' => $key];
        }
        else {
            return [];
        }

        $items = (array) $this->call('item.get', $params);
        $ids = [];
        foreach ($items as $item) {
            foreach ((array) ($item['triggers'] ?? []) as $trigger) {
                $tid = (string) ($trigger['triggerid'] ?? '');
                if ($tid !== '') {
                    $ids[$tid] = true;
                }
            }
        }
        return array_keys($ids);
    }

    public function activeProblemsForTriggers(array $triggerids, string $hostid = ''): array {
        $triggerids = array_values(array_filter(array_map('strval', $triggerids)));
        if ($triggerids === []) {
            return [];
        }
        $params = [
            'output' => ['eventid', 'name', 'severity', 'clock', 'objectid'],
            'source' => 0,
            'object' => 0,
            'objectids' => $triggerids,
            'recent' => false,
            'sortfield' => ['eventid'],
            'sortorder' => 'DESC',
            'limit' => 20
        ];
        if ($hostid !== '') {
            $params['hostids'] = [$hostid];
        }
        return (array) $this->call('problem.get', $params);
    }

    /**
     * Post a comment (problem update message) on an event, chunked to respect the
     * Zabbix message size limit. action bitmask 4 = add message. Returns the
     * number of update messages posted.
     */
    public function addProblemComment(string $eventid, string $message, int $action = 4, int $chunkSize = 1900): int {
        $eventid = trim($eventid);
        $message = trim($message);
        if ($eventid === '' || $message === '') {
            return 0;
        }

        $chunks = Util::chunkText($message, max(200, $chunkSize - 32));
        $count = count($chunks);
        $posted = 0;
        foreach ($chunks as $index => $chunk) {
            if (trim($chunk) === '') {
                continue;
            }
            $prefix = $count > 1 ? '[TC '.($index + 1).'/'.$count.'] ' : '[TC] ';
            $this->call('event.acknowledge', [
                'eventids' => [$eventid],
                'action' => $action,
                'message' => $prefix.$chunk
            ]);
            $posted++;
        }
        return $posted;
    }

    /**
     * Change the severity of one active problem event (Update problem → Change
     * severity, action bitmask 8), optionally adding a short message in the same
     * update (bitmask 4). This is how the severity-escalation rules raise/restore
     * the severity of an EXISTING problem instead of creating a new one — it edits
     * the event's (manual) severity, never the trigger's configured priority, so it
     * is fully reversible and re-applies cleanly. Returns 1 on a call, else 0.
     */
    public function setEventSeverity(string $eventid, int $severity, string $message = '', int $baseAction = 8): int {
        $eventid = trim($eventid);
        if ($eventid === '') {
            return 0;
        }
        $severity = max(0, min(5, $severity));
        $message = trim($message);
        $action = $baseAction | 8; // always include "change severity"
        $params = ['eventids' => [$eventid], 'action' => $action, 'severity' => $severity];
        if ($message !== '') {
            $params['action'] = $action | 4; // also add the message in the same update
            $params['message'] = Util::truncate($message, 1900);
        }
        $this->call('event.acknowledge', $params);
        return 1;
    }

    // ── HTTP transport ─────────────────────────────────────────────────────

    private function callOnce(string $method, array $params, string $mode) {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $this->requestId++
        ];

        $headers = ['Content-Type: application/json-rpc'];
        if ($mode !== 'none' && $this->token !== '') {
            if (!$this->urlTrusted) {
                // SSRF / token-exfiltration guard: never attach the privileged
                // API token to a URL derived from the incoming request host.
                throw new \RuntimeException('Set an explicit Zabbix API URL in the module settings; the API token is never sent to a URL derived from the request host.');
            }
            if ($mode === 'auth_property') {
                $payload['auth'] = $this->token;
            }
            else {
                $headers[] = 'Authorization: Bearer '.$this->token;
            }
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new \RuntimeException('Unable to encode Zabbix API request.');
        }

        $response = $this->httpPost($body, $headers);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            // Never echo the raw remote body back to the caller.
            throw new \RuntimeException('Invalid (non-JSON) response from the Zabbix API.');
        }
        if (isset($decoded['error'])) {
            $error = $decoded['error'];
            $message = (string) ($error['message'] ?? 'Zabbix API error');
            $data = (string) ($error['data'] ?? '');
            throw new \RuntimeException(Util::truncate(trim($message.($data !== '' ? ': '.$data : '')), 400));
        }

        return $decoded['result'] ?? null;
    }

    private function httpPost(string $body, array $headers): string {
        if (!$this->urlChecked) {
            // Enforce http(s) and block link-local / cloud-metadata targets
            // before any token-bearing request leaves the frontend (SSRF guard).
            Util::assertSafeApiUrl($this->url);
            $this->urlChecked = true;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->url);
            if ($ch === false) {
                throw new \RuntimeException('Unable to initialize the HTTP client.');
            }

            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
                CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0
            ];
            // Restrict to HTTP(S) so a redirect or crafted URL cannot pivot to
            // file://, gopher://, etc.
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('The Zabbix API HTTP request failed (connection or TLS error).');
            }
            if ($code >= 400) {
                throw new \RuntimeException('The Zabbix API returned HTTP '.$code.'.');
            }
            return (string) $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                // Never auto-follow redirects (the curl path disables them too): a
                // 30x could otherwise bounce this token-bearing request to an
                // unvalidated host (e.g. link-local/metadata). A redirect instead
                // surfaces as a non-JSON body and a clean "invalid response" error.
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1
            ],
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer
            ]
        ]);

        $response = @file_get_contents($this->url, false, $context);
        if ($response === false) {
            throw new \RuntimeException('The Zabbix API HTTP request failed.');
        }
        return (string) $response;
    }

    // ── In-process frontend transport ──────────────────────────────────────

    private static function canUseFrontendApi(): bool {
        if (!class_exists('\API') || !class_exists('\CWebUser')) {
            return false;
        }
        if (!isset(\CWebUser::$data) || !is_array(\CWebUser::$data)) {
            return false;
        }
        $userid = (string) (\CWebUser::$data['userid'] ?? '');
        if ($userid === '' || $userid === '0') {
            return false;
        }
        if (defined('USER_TYPE_ZABBIX_USER')) {
            $user_type = (int) (\CWebUser::$data['type'] ?? 0);
            if ($user_type < USER_TYPE_ZABBIX_USER) {
                return false;
            }
        }
        return true;
    }

    private static function frontendServiceName(string $api_object): string {
        static $map = [
            'apiinfo' => 'APIInfo',
            'host' => 'Host',
            'hostgroup' => 'HostGroup',
            'item' => 'Item',
            'problem' => 'Problem',
            'trigger' => 'Trigger'
        ];
        return $map[strtolower(trim($api_object))] ?? '';
    }

    private function callWithFrontendApi(string $method, array $params) {
        $parts = explode('.', $method, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid Zabbix API method: '.$method);
        }

        [$api_object, $api_action] = $parts;
        $service_name = self::frontendServiceName($api_object);
        if ($service_name === '') {
            throw new \RuntimeException('The "'.$method.'" method is not available over the in-process API.');
        }

        try {
            $service = call_user_func(['\API', $service_name]);
            $result = $service->{$api_action}($params);
        }
        catch (\Throwable $e) {
            throw new \RuntimeException(Util::truncate($method.' failed: '.$e->getMessage(), 400), 0, $e);
        }

        return $result;
    }
}
