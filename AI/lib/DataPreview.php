<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

/** Request-local, bounded views of already masked provider input. Never persisted. */
final class DataPreview {
    private const MAX_BYTES = 1048576;
    private const MAX_REQUESTS = 25;
    private const MAX_STRING_BYTES = 65536;

    private static bool $enabled = false;
    private static bool $preview = false;
    private static bool $truncated = false;
    private static int $bytes = 0;
    private static int $request_count = 0;
    private static string $context = '';
    private static array $requests = [];

    public static function canView(array $config, int $user_type, bool $is_guest): bool {
        if ($is_guest || $user_type < USER_TYPE_ZABBIX_USER) {
            return false;
        }
        $visibility = $config['chat']['data_preview_visibility'] ?? 'off';
        return $visibility === 'all'
            || ($visibility === 'super_admin' && $user_type === USER_TYPE_SUPER_ADMIN);
    }

    public static function start(bool $enabled): void {
        self::$enabled = $enabled;
        self::$preview = false;
        self::$truncated = false;
        self::$bytes = 0;
        self::$request_count = 0;
        self::$context = '';
        self::$requests = [];
    }

    /** Only the permitted, masked runtime context, never the full system prompt. */
    public static function setContext(string $context): void {
        if (self::$enabled) {
            self::$context = $context;
        }
    }

    public static function asPreview(): void {
        self::$preview = true;
    }

    public static function record(array $provider, array $body, array $headers = [], string $status = 'attempted'): ?int {
        if (!self::$enabled) {
            return null;
        }
        self::$request_count++;
        if (count(self::$requests) >= self::MAX_REQUESTS || self::$bytes >= self::MAX_BYTES) {
            self::$truncated = true;
            return null;
        }

        // Read only final provider JSON. Administrator policies, references,
        // tool schemas and transport/authentication configuration are excluded.
        $messages = [];
        foreach ($body['messages'] ?? [] as $message) {
            if (!is_array($message)
                    || !in_array(strtolower((string) ($message['role'] ?? '')), ['user', 'assistant', 'tool'], true)) {
                continue;
            }
            $messages[] = array_intersect_key($message, array_flip(['role', 'content', 'tool_calls', 'tool_call_id', 'tool_name', 'name']));
        }
        $tool_names = [];
        foreach ($body['tools'] ?? [] as $tool) {
            $tool_names[] = (string) ($tool['function']['name'] ?? $tool['name'] ?? '');
        }

        // A provider credential accidentally pasted into a message must not be
        // exposed by this feature either. No header names or values are returned.
        $secrets = [(string) ($provider['api_key'] ?? '')];
        foreach ($headers as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            if (is_int($name)) {
                $parts = explode(':', (string) $value, 2);
                $name = trim($parts[0]);
                $value = trim($parts[1] ?? '');
            }
            if (!in_array(strtolower((string) $name), ['content-type', 'accept', 'user-agent', 'anthropic-version'], true)) {
                $secrets[] = (string) $value;
                if (preg_match('/^(?:Bearer|Basic)\s+(.+)$/i', (string) $value, $match)) {
                    $secrets[] = $match[1];
                }
            }
        }
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $encoded = json_encode($secret, JSON_INVALID_UTF8_SUBSTITUTE);
                $secrets[] = substr($encoded, 1, -1);
            }
        }
        $secrets = array_values(array_unique(array_filter($secrets, static fn($value) => $value !== '')));
        usort($secrets, static fn($a, $b) => strlen($b) <=> strlen($a));

        $truncated = false;
        $remaining = self::MAX_BYTES - self::$bytes;
        $entry = self::bounded([
            'provider' => (string) ($provider['name'] ?? 'AI provider'),
            'model' => (string) ($body['model'] ?? ''),
            'type' => (string) ($provider['type'] ?? 'openai_compatible'),
            'status' => $status,
            'context' => self::$context,
            'messages' => $messages,
            'tool_names' => $tool_names
        ], $secrets, $remaining, $truncated);
        $entry['truncated'] = $truncated;
        self::$bytes = self::MAX_BYTES - $remaining;
        self::$truncated = self::$truncated || $truncated;
        self::$requests[] = $entry;
        return count(self::$requests) - 1;
    }

    public static function markSent(?int $index): void {
        if ($index !== null && isset(self::$requests[$index])) {
            self::$requests[$index]['status'] = 'sent';
        }
    }

    public static function snapshot(): ?array {
        if (!self::$enabled || !self::$requests) {
            return null;
        }
        return [
            'kind' => self::$preview ? 'preview' : 'sent',
            'notice' => (self::$preview
                ? 'Initial request only. No request has been sent to the AI provider. Later tool results depend on the AI response. '
                : 'Requests from this interaction. Attempted means delivery could not be confirmed. ')
                .'This is a filtered view of the data after configured masking. Administrator instructions, private references, '
                .'tool definitions and authentication details are omitted. Permitted monitoring context is shown separately. '
                .'Large views may be truncated.',
            'requests' => self::$requests,
            'request_count' => self::$request_count,
            'truncated' => self::$truncated
        ];
    }

    private static function bounded($value, array $secrets, int &$remaining, bool &$truncated, int $depth = 0) {
        if ($remaining <= 0 || $depth > 32) {
            $truncated = true;
            return '[truncated]';
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (count($result) >= 2000 || $remaining <= 0) {
                    $truncated = true;
                    break;
                }
                // Content can include arbitrary JSON keys, so scrub those too.
                $safe_key = is_string($key) ? self::bounded($key, $secrets, $remaining, $truncated, $depth + 1) : $key;
                $remaining -= 8;
                $result[$safe_key] = self::bounded($item, $secrets, $remaining, $truncated, $depth + 1);
            }
            return $result;
        }
        if (is_string($value)) {
            $value = str_replace($secrets, '[REDACTED]', $value);
            $limit = max(0, min(self::MAX_STRING_BYTES, $remaining));
            if (strlen($value) > $limit) {
                $value = substr($value, 0, $limit);
                // JSON replacement avoids invalid UTF-8 when a bound cuts a character.
                $value = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), true).' [truncated]';
                $truncated = true;
            }
            $remaining -= strlen($value);
            return $value;
        }
        $remaining -= 8;
        return $value;
    }
}
