<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class HttpClient {

    public static function request(string $method, string $url, array $options = []): array {
        $headers = self::normalizeHeaders($options['headers'] ?? []);
        $timeout = (int) ($options['timeout'] ?? 30);
        $verify_peer = array_key_exists('verify_peer', $options) ? (bool) $options['verify_peer'] : true;
        $body = null;

        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        }
        elseif (array_key_exists('body', $options)) {
            $body = (string) $options['body'];
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $header_lines = [];

        foreach ($headers as $name => $value) {
            $header_lines[] = $name.': '.$value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => min(max(1, $timeout), 15),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verify_peer,
            CURLOPT_SSL_VERIFYHOST => $verify_peer ? 2 : 0,
            CURLOPT_USERAGENT => 'Zabbix-AI-Module/1.0'
        ]);

        if ($body !== null && strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response_body = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effective_url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($response_body === false || $curl_errno !== 0) {
            $parts = ['HTTP request failed'];

            if ($curl_errno !== 0) {
                $parts[] = 'curl error '.$curl_errno;
            }

            if ($curl_error !== '') {
                $parts[] = $curl_error;
            }

            $parts[] = 'URL: '.$effective_url;

            throw new RuntimeException(implode(' — ', $parts));
        }

        $json = null;

        if ($response_body !== '' && (stripos($content_type, 'json') !== false || self::looksLikeJson($response_body))) {
            $decoded = json_decode(self::stripBom($response_body), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        return [
            'status' => $status,
            'body' => $response_body,
            'json' => $json,
            'content_type' => $content_type
        ];
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

            throw new RuntimeException(
                'HTTP '.$response['status'].' from '.$url.': '.$error_detail
            );
        }

        return $response;
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

    private static function looksLikeJson(string $body): bool {
        $body = ltrim(self::stripBom($body));

        if ($body === '') {
            return false;
        }

        $first = $body[0];

        // Accept any JSON value start (object, array, string, number, literal)
        // so a JSON body served without a "json" content-type is still decoded.
        return $first === '{' || $first === '[' || $first === '"' || $first === '-'
            || ctype_digit($first)
            || strncmp($body, 'true', 4) === 0
            || strncmp($body, 'false', 5) === 0
            || strncmp($body, 'null', 4) === 0;
    }

    private static function stripBom(string $body): string {
        return strncmp($body, "\xEF\xBB\xBF", 3) === 0 ? substr($body, 3) : $body;
    }
}
