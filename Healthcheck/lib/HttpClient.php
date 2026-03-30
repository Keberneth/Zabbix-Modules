<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use RuntimeException;

class HttpClient {

    public static function request(string $method, string $url, array $options = []): array {
        $method = strtoupper(trim($method));
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('HTTP URL is empty.');
        }

        $timeout = max(1, (int) ($options['timeout'] ?? 10));
        $verify_peer = (bool) ($options['verify_peer'] ?? true);
        $headers = self::normalizeHeaders($options['headers'] ?? []);
        $body = array_key_exists('body', $options) ? (string) $options['body'] : null;

        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }
        }

        if (!isset($headers['Accept'])) {
            $headers['Accept'] = 'application/json, text/plain, */*';
        }

        $headers['User-Agent'] = 'Zabbix-Healthcheck-Module/1.0';

        if (function_exists('curl_init')) {
            return self::requestWithCurl($method, $url, $headers, $body, $timeout, $verify_peer);
        }

        return self::requestWithStreams($method, $url, $headers, $body, $timeout, $verify_peer);
    }

    public static function expectSuccess(string $method, string $url, array $options = []): array {
        $response = self::request($method, $url, $options);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $error_detail = '';

            if (is_array($response['json'])) {
                $error_detail = $response['json']['error']['message']
                    ?? $response['json']['error']
                    ?? $response['json']['message']
                    ?? '';

                if (is_array($error_detail)) {
                    $error_detail = json_encode($error_detail);
                }
            }

            if ($error_detail === '') {
                $error_detail = Util::truncate((string) $response['body'], 600);
            }

            throw new RuntimeException('HTTP '.$response['status'].' from '.$url.': '.$error_detail);
        }

        return $response;
    }

    private static function requestWithCurl(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        bool $verify_peer
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name.': '.$value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(max(1, $timeout), 15),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verify_peer,
            CURLOPT_SSL_VERIFYHOST => $verify_peer ? 2 : 0
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        if ($body !== null && !in_array($method, ['GET', 'HEAD'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $started_at = microtime(true);
        $response_body = curl_exec($ch);
        $duration_ms = max(1, (int) round((microtime(true) - $started_at) * 1000));

        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        if ($response_body === false || $curl_errno !== 0) {
            $parts = ['HTTP request failed'];
            if ($curl_errno !== 0) {
                $parts[] = 'curl error '.$curl_errno;
            }
            if ($curl_error !== '') {
                $parts[] = $curl_error;
            }

            throw new RuntimeException(implode(' — ', $parts));
        }

        $response_body = (string) $response_body;

        return [
            'status' => $status,
            'body' => $response_body,
            'json' => self::decodeJson($response_body, $content_type),
            'content_type' => $content_type,
            'duration_ms' => $duration_ms
        ];
    }

    private static function requestWithStreams(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        bool $verify_peer
    ): array {
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name.': '.$value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $header_lines),
                'content' => ($body !== null && !in_array($method, ['GET', 'HEAD'], true)) ? $body : '',
                'timeout' => $timeout,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => $verify_peer,
                'verify_peer_name' => $verify_peer
            ]
        ]);

        $started_at = microtime(true);
        $response_body = @file_get_contents($url, false, $context);
        $duration_ms = max(1, (int) round((microtime(true) - $started_at) * 1000));
        $response_headers = $http_response_header ?? [];

        if ($response_body === false && $response_headers === []) {
            throw new RuntimeException('HTTP request failed for '.$url.'.');
        }

        $status = 0;
        $content_type = '';

        foreach ($response_headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $matches)) {
                $status = (int) $matches[1];
            }
            elseif (stripos((string) $header, 'Content-Type:') === 0) {
                $content_type = trim(substr((string) $header, 13));
            }
        }

        $response_body = (string) $response_body;

        return [
            'status' => $status,
            'body' => $response_body,
            'json' => self::decodeJson($response_body, $content_type),
            'content_type' => $content_type,
            'duration_ms' => $duration_ms
        ];
    }

    private static function normalizeHeaders(array $headers): array {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $parts = explode(':', (string) $value, 2);
                if (count($parts) === 2) {
                    $normalized[trim($parts[0])] = ltrim($parts[1]);
                }
                continue;
            }

            $normalized[trim((string) $name)] = (string) $value;
        }

        return $normalized;
    }

    private static function decodeJson(string $body, string $content_type) {
        if ($body === '') {
            return null;
        }

        if (stripos($content_type, 'json') === false && !self::looksLikeJson($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private static function looksLikeJson(string $body): bool {
        $body = ltrim($body);

        return $body !== '' && ($body[0] === '{' || $body[0] === '[');
    }
}
