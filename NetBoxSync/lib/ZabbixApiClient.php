<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use API;
use JsonException;
use RuntimeException;

/**
 * Read-only Zabbix API client used by the synchronization engine.
 *
 * With no constructor arguments the client uses Zabbix's in-process API facade.
 * This is the transport used by authenticated manual runs in the frontend. For
 * unattended/CLI runs, fromConfig() creates an HTTP JSON-RPC client authenticated
 * with a Zabbix API token in the Authorization: Bearer header.
 */
class ZabbixApiClient {

    /** Hosts per Item/HostInterface bulk-prefetch chunk (keeps `hostids` lists sane). */
    private const HOST_CHUNK = 500;
    /** Refuse unexpectedly large responses before decoding them into memory again. */
    private const MAX_RESPONSE_BYTES = 16777216;

    private const TRANSPORT_FRONTEND = 'frontend';
    private const TRANSPORT_HTTP = 'http';

    private string $transport = self::TRANSPORT_FRONTEND;
    private string $api_url = '';
    private string $api_token = '';
    private bool $verify_peer = true;
    private int $timeout = 15;
    private int $request_id = 0;

    /**
     * Construct the frontend transport, or pass the `zabbix_api` configuration
     * section directly to construct the token-authenticated HTTP transport.
     *
     * HTTP configuration keys:
     *   - url: explicit absolute URL ending in /api_jsonrpc.php
     *   - token: Zabbix API token (never placed in the JSON request body)
     *   - verify_peer: verify the HTTPS certificate and host (default true)
     *   - timeout: connect and total request timeout in seconds (1..300)
     */
    public function __construct(array $http_config = []) {
        if ($http_config === []) {
            if (!class_exists('API')) {
                throw new RuntimeException('Zabbix API facade is not available in this context.');
            }

            return;
        }

        $this->configureHttpTransport($http_config);
    }

    /**
     * Create the unattended HTTP transport from the complete module config.
     * Environment-backed tokens are resolved here, with the stored token used
     * as the same fallback that Config::sanitizeForRuntime() provides.
     */
    public static function fromConfig(array $config): self {
        if (!isset($config['zabbix_api']) || !is_array($config['zabbix_api'])) {
            throw new RuntimeException('Missing zabbix_api configuration for unattended Zabbix API access.');
        }

        $api_config = $config['zabbix_api'];
        $env_name = trim((string) ($api_config['token_env'] ?? ''));
        if ($env_name !== '') {
            $env_value = getenv($env_name);
            if ($env_value !== false && trim((string) $env_value) !== '') {
                $api_config['token'] = trim((string) $env_value);
            }
        }

        return new self($api_config);
    }

    /**
     * Fetch monitored hosts. A positive $limit bounds the result set so the sync
     * never pulls an unbounded host list into memory; max_hosts_per_run is honored
     * at fetch time by the caller.
     */
    public function getAllHosts(int $limit = 0): array {
        $params = [
            'output' => ['hostid', 'host'],
            'sortfield' => 'host'
        ];

        if ($limit > 0) {
            $params['limit'] = $limit;
        }

        return $this->call('host.get', $params);
    }

    /**
     * Bulk variant of getItemByExactKey across many hosts in array_chunk batches.
     * Returns map[hostid][key_] = item (first item wins per host+key).
     */
    public function getItemsByExactKeys(array $hostids, array $keys): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));
        $keys = array_values(array_unique(array_filter($keys, static fn($k) => (string) $k !== '')));

        if ($hostids === [] || $keys === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $items = $this->call('item.get', [
                'hostids' => $chunk,
                'filter' => ['key_' => $keys],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue']
            ]);

            foreach ($items as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                $key = (string) ($item['key_'] ?? '');
                if ($hostid === '' || $key === '' || isset($map[$hostid][$key])) {
                    continue;
                }
                $map[$hostid][$key] = $item;
            }
        }

        return $map;
    }

    /**
     * Bulk variant of getItemByExactName across many hosts in array_chunk batches.
     * Returns map[hostid][name] = item (first item wins per host+name).
     */
    public function getItemsByExactNames(array $hostids, array $names): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));
        $names = array_values(array_unique(array_filter($names, static fn($n) => (string) $n !== '')));

        if ($hostids === [] || $names === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $items = $this->call('item.get', [
                'hostids' => $chunk,
                'filter' => ['name' => $names],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue'],
                'sortfield' => 'name'
            ]);

            foreach ($items as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                $name = (string) ($item['name'] ?? '');
                if ($hostid === '' || $name === '' || isset($map[$hostid][$name])) {
                    continue;
                }
                $map[$hostid][$name] = $item;
            }
        }

        return $map;
    }

    /**
     * Bulk key-search across many hosts in array_chunk batches.
     * Returns map[hostid] = list of matching items.
     */
    public function searchItemsByKeyForHosts(array $hostids, string $pattern, array $extra = []): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));

        if ($hostids === [] || $pattern === '') {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $params = [
                'hostids' => $chunk,
                'search' => ['key_' => $pattern],
                'output' => ['itemid', 'hostid', 'name', 'key_', 'lastvalue']
            ] + $extra;

            foreach ($this->call('item.get', $params) as $item) {
                $hostid = (string) ($item['hostid'] ?? '');
                if ($hostid === '') {
                    continue;
                }
                $map[$hostid][] = $item;
            }
        }

        return $map;
    }

    /**
     * One HostInterface.get for many hosts in array_chunk batches.
     * Returns map[hostid] = list of interfaces.
     */
    public function getInterfacesForHosts(array $hostids): array {
        $map = [];
        $hostids = array_values(array_unique(array_filter($hostids, static fn($id) => (string) $id !== '')));

        if ($hostids === []) {
            return $map;
        }

        foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
            $rows = $this->call('hostinterface.get', [
                'hostids' => $chunk,
                'output' => ['interfaceid', 'hostid', 'type', 'ip', 'dns', 'port', 'main', 'useip']
            ]);

            foreach ($rows as $row) {
                $hostid = (string) ($row['hostid'] ?? '');
                if ($hostid === '') {
                    continue;
                }
                $map[$hostid][] = $row;
            }
        }

        return $map;
    }

    /** Pick the main Zabbix agent interface from a prefetched interface list. */
    public static function pickMainAgentInterface(array $interfaces): ?array {
        foreach ($interfaces as $iface) {
            if ((string) ($iface['type'] ?? '') === '1' && (string) ($iface['main'] ?? '') === '1') {
                return $iface;
            }
        }

        return null;
    }

    public function getItemByExactKey(string $hostid, string $key): ?array {
        if ($key === '') {
            return null;
        }

        $items = $this->call('item.get', [
            'hostids' => [$hostid],
            'filter' => ['key_' => $key],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ]);

        return $items[0] ?? null;
    }

    public function getItemByExactName(string $hostid, string $name): ?array {
        if ($name === '') {
            return null;
        }

        $items = $this->call('item.get', [
            'hostids' => [$hostid],
            'filter' => ['name' => $name],
            'output' => ['itemid', 'name', 'key_', 'lastvalue'],
            'sortfield' => 'name'
        ]);

        return $items[0] ?? null;
    }

    public function searchItemsByKey(string $hostid, string $pattern, array $extra = []): array {
        if ($pattern === '') {
            return [];
        }

        $params = [
            'hostids' => [$hostid],
            'search' => ['key_' => $pattern],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ] + $extra;

        return $this->call('item.get', $params);
    }

    public function searchItemsByName(string $hostid, string $pattern, array $extra = []): array {
        if ($pattern === '') {
            return [];
        }

        $params = [
            'hostids' => [$hostid],
            'search' => ['name' => $pattern],
            'output' => ['itemid', 'name', 'key_', 'lastvalue']
        ] + $extra;

        return $this->call('item.get', $params);
    }

    public function getHostInterfaces(string $hostid): array {
        return $this->call('hostinterface.get', [
            'hostids' => [$hostid],
            'output' => ['interfaceid', 'type', 'ip', 'dns', 'port', 'main', 'useip']
        ]);
    }

    public function getMainAgentInterface(string $hostid): ?array {
        foreach ($this->getHostInterfaces($hostid) as $iface) {
            if ((string) ($iface['type'] ?? '') === '1' && (string) ($iface['main'] ?? '') === '1') {
                return $iface;
            }
        }

        return null;
    }

    /** Keep API tokens out of var_dump()/exception diagnostics. */
    public function __debugInfo(): array {
        return [
            'transport' => $this->transport,
            'api_url' => $this->api_url,
            'verify_peer' => $this->verify_peer,
            'timeout' => $this->timeout
        ];
    }

    private function configureHttpTransport(array $config): void {
        $url = trim((string) ($config['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('zabbix_api.url must be an explicit api_jsonrpc.php URL.');
        }
        if (preg_match('/[\\x00-\\x20\\x7f]/', $url) === 1) {
            throw new RuntimeException('zabbix_api.url contains invalid whitespace or control characters.');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!is_array($parts)
                || !in_array($scheme, ['http', 'https'], true)
                || (string) ($parts['host'] ?? '') === '') {
            throw new RuntimeException('zabbix_api.url must be an absolute HTTP(S) URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('zabbix_api.url must not contain embedded credentials.');
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('zabbix_api.url must not contain a query string or fragment.');
        }
        if (preg_match('~(?:^|/)api_jsonrpc\\.php$~', $path) !== 1) {
            throw new RuntimeException('zabbix_api.url must end with /api_jsonrpc.php.');
        }

        $token = trim((string) ($config['token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('zabbix_api.token is required for unattended Zabbix API access.');
        }
        if (preg_match('/[\\r\\n]/', $token) === 1) {
            throw new RuntimeException('zabbix_api.token contains invalid newline characters.');
        }

        $verify_peer = $this->readBoolean($config['verify_peer'] ?? true, 'zabbix_api.verify_peer');
        $timeout = filter_var($config['timeout'] ?? 15, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300]
        ]);
        if ($timeout === false) {
            throw new RuntimeException('zabbix_api.timeout must be an integer between 1 and 300 seconds.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for unattended Zabbix API access.');
        }

        $this->transport = self::TRANSPORT_HTTP;
        $this->api_url = $url;
        $this->api_token = $token;
        $this->verify_peer = $verify_peer;
        $this->timeout = (int) $timeout;
    }

    /** Dispatch a read call without changing the result shape seen by callers. */
    private function call(string $method, array $params): array {
        if ($this->transport === self::TRANSPORT_HTTP) {
            return $this->callHttp($method, $params);
        }

        switch ($method) {
            case 'host.get':
                return (array) API::Host()->get($params);

            case 'item.get':
                return (array) API::Item()->get($params);

            case 'hostinterface.get':
                return (array) API::HostInterface()->get($params);
        }

        throw new RuntimeException('Unsupported Zabbix API method: '.$method.'.');
    }

    /** Perform one non-redirecting JSON-RPC 2.0 request with bearer authentication. */
    private function callHttp(string $method, array $params): array {
        $request_id = ++$this->request_id;

        try {
            $request_body = json_encode([
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => $request_id
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        catch (JsonException $e) {
            throw new RuntimeException(
                'Could not encode the '.$method.' Zabbix API request: '.self::safeErrorText($e->getMessage()).'.',
                0,
                $e
            );
        }

        $response_body = '';
        $response_too_large = false;
        $write_response = static function ($curl, string $chunk) use (&$response_body, &$response_too_large): int {
            $chunk_length = strlen($chunk);
            if (strlen($response_body) + $chunk_length > self::MAX_RESPONSE_BYTES) {
                $response_too_large = true;
                return 0;
            }

            $response_body .= $chunk;
            return $chunk_length;
        };

        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the PHP cURL client for the Zabbix API.');
        }

        $options = [
            CURLOPT_URL => $this->api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request_body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer '.$this->api_token,
                'Content-Type: application/json-rpc',
                'Content-Length: '.strlen($request_body),
                'Expect:'
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $this->verify_peer,
            CURLOPT_SSL_VERIFYHOST => $this->verify_peer ? 2 : 0,
            CURLOPT_WRITEFUNCTION => $write_response
        ];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if (!curl_setopt_array($curl, $options)) {
            curl_close($curl);
            throw new RuntimeException('Could not configure the PHP cURL client for the Zabbix API.');
        }

        $success = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($response_too_large) {
            throw new RuntimeException(
                'Zabbix API response exceeded the '.self::MAX_RESPONSE_BYTES.'-byte safety limit.'
            );
        }
        if ($success === false) {
            $detail = self::safeErrorText($curl_error);
            throw new RuntimeException(
                'Zabbix API HTTP request failed'.($detail !== '' ? ': '.$detail : '.').
                ($detail !== '' ? '.' : '')
            );
        }
        if ($http_status < 200 || $http_status >= 300) {
            throw new RuntimeException('Zabbix API returned HTTP status '.$http_status.'.');
        }

        try {
            $response = json_decode($response_body, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $e) {
            throw new RuntimeException('Zabbix API returned invalid JSON.', 0, $e);
        }

        if (!is_array($response) || ($response['jsonrpc'] ?? '') !== '2.0') {
            throw new RuntimeException('Zabbix API returned an invalid JSON-RPC response.');
        }
        if (!array_key_exists('id', $response) || (string) $response['id'] !== (string) $request_id) {
            throw new RuntimeException('Zabbix API returned a response with an unexpected request ID.');
        }
        if (isset($response['error'])) {
            $error = is_array($response['error']) ? $response['error'] : [];
            $code = isset($error['code']) ? (string) $error['code'] : 'unknown';
            $message = self::safeErrorText((string) ($error['message'] ?? 'Unknown API error'));
            throw new RuntimeException(
                'Zabbix API '.$method.' failed (code '.$code.'): '.$message.'.'
            );
        }
        if (!array_key_exists('result', $response) || !is_array($response['result'])) {
            throw new RuntimeException('Zabbix API '.$method.' returned an invalid result.');
        }

        return $response['result'];
    }

    /** Parse booleans without treating the string "false" as truthy. */
    private function readBoolean($value, string $setting): bool {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
                return true;
            }
            if ($normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
                return false;
            }
        }

        throw new RuntimeException($setting.' must be a boolean value.');
    }

    /** Make external error text single-line, bounded, and safe for module logs. */
    private static function safeErrorText(string $message): string {
        $message = (string) preg_replace('/[\\x00-\\x1f\\x7f]+/', ' ', $message);
        return trim(substr($message, 0, 500));
    }
}
