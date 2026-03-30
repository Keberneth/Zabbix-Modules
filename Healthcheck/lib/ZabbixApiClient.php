<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use RuntimeException;

class ZabbixApiClient {

    private $url;
    private $token;
    private $verify_peer;
    private $timeout;
    private $auth_mode;

    public function __construct(
        string $url,
        string $token,
        bool $verify_peer = true,
        int $timeout = 10,
        string $auth_mode = 'auto'
    ) {
        $this->url = trim($url);
        $this->token = trim($token);
        $this->verify_peer = $verify_peer;
        $this->timeout = $timeout;
        $this->auth_mode = $auth_mode !== '' ? $auth_mode : 'auto';
    }

    public static function fromCheck(array $check): ?self {
        $token = Config::resolveSecret(
            $check['zabbix_api_token'] ?? '',
            $check['zabbix_api_token_env'] ?? ''
        );
        $url = trim((string) ($check['zabbix_api_url'] ?? ''));

        if ($url === '') {
            $url = self::deriveApiUrl();
        }

        if ($url === '' || $token === '') {
            return null;
        }

        return new self(
            $url,
            $token,
            (bool) ($check['verify_peer'] ?? true),
            (int) ($check['timeout'] ?? 10),
            (string) ($check['auth_mode'] ?? 'auto')
        );
    }

    public static function deriveApiUrl(): string {
        if (empty($_SERVER['HTTP_HOST'])) {
            return '';
        }

        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '/zabbix.php';
        $base_path = rtrim(str_replace('\\', '/', dirname($script_name)), '/.');

        return $scheme.'://'.$host.$base_path.'/api_jsonrpc.php';
    }

    public function call(string $method, array $params = [], bool $use_auth = true) {
        if (!$use_auth) {
            return $this->callWithoutAuth($method, $params);
        }

        if ($this->auth_mode === 'bearer') {
            return $this->callWithBearer($method, $params);
        }

        if ($this->auth_mode === 'legacy_auth_field') {
            return $this->callWithLegacyAuthField($method, $params);
        }

        try {
            return $this->callWithBearer($method, $params);
        }
        catch (\Throwable $e) {
            return $this->callWithLegacyAuthField($method, $params);
        }
    }

    private function callWithBearer(string $method, array $params) {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];

        $response = HttpClient::expectSuccess('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json-rpc',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->token
            ],
            'json' => $payload,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        return $this->extractResult($response['json'], $method, 'Bearer');
    }

    private function callWithLegacyAuthField(string $method, array $params) {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'auth' => $this->token,
            'id' => 1
        ];

        $response = HttpClient::expectSuccess('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json-rpc',
                'Accept' => 'application/json'
            ],
            'json' => $payload,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        return $this->extractResult($response['json'], $method, 'legacy auth field');
    }

    private function callWithoutAuth(string $method, array $params) {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1
        ];

        $response = HttpClient::expectSuccess('POST', $this->url, [
            'headers' => [
                'Content-Type' => 'application/json-rpc',
                'Accept' => 'application/json'
            ],
            'json' => $payload,
            'timeout' => $this->timeout,
            'verify_peer' => $this->verify_peer
        ]);

        return $this->extractResult($response['json'], $method, 'no auth');
    }

    private function extractResult($json, string $method, string $auth_label) {
        if (!is_array($json)) {
            throw new RuntimeException('Zabbix API returned a non-JSON response for '.$method.' using '.$auth_label.'.');
        }

        if (array_key_exists('error', $json)) {
            $message = $json['error']['message'] ?? 'Unknown Zabbix API error';
            $data = $json['error']['data'] ?? '';
            throw new RuntimeException($method.' failed via '.$auth_label.': '.$message.' '.Util::truncate((string) $data, 600));
        }

        return $json['result'] ?? [];
    }
}
