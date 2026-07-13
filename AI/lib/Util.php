<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use Throwable;

class Util {

    public static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Validate the canonical Zabbix user-macro syntax accepted by AI writes.
     *
     * Zabbix permits only uppercase A-Z, digits, underscore and dot in the
     * macro name. Contexts are limited here to the documented unquoted,
     * quoted, and regex:"..." forms. Keeping this deliberately strict makes
     * the confirmation payload identical to what the API will accept.
     */
    public static function isValidZabbixUserMacro(string $macro): bool {
        return preg_match(
            '/^\{\$[A-Z0-9_.]+(?:\:(?:[^"}]+|"(?:[^"\\\\]|\\\\.)*"|regex:"(?:[^"\\\\]|\\\\.)*"))?\}$/D',
            $macro
        ) === 1;
    }

    public static function cleanString($value, int $max_length = 0): string {
        $value = trim((string) $value);

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanMultiline($value, int $max_length = 0): string {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanUrl($value): string {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        // Only http(s) URLs are accepted. This blocks dangerous schemes such as
        // javascript:, data:, file: and gopher: from being stored as provider
        // endpoints, Zabbix/NetBox URLs or reference links (reference links are
        // rendered as clickable anchors in the chat UI). Docker/loopback/private
        // hosts all use http(s), so this never breaks local or split deployments.
        if (!preg_match('#^https?://#i', $value)) {
            return '';
        }

        return $value;
    }

    public static function cleanPath($value, int $max_length = 1024): string {
        $value = str_replace(["\0", '\\'], ['', '/'], trim((string) $value));
        $value = preg_replace('#/+#', '/', $value);

        // Strip parent-directory traversal and '.' segments so a configured base
        // path can never escape upward (e.g. "/var/lib/zabbix-ai/../../etc/..").
        // Legitimate data directories never need "..". Absolute paths are still
        // allowed because Docker volume mounts and multi-server installs
        // legitimately point at arbitrary mount points.
        $is_absolute = isset($value[0]) && $value[0] === '/';
        $segments = array_filter(explode('/', $value), static function($segment) {
            return $segment !== '' && $segment !== '.' && $segment !== '..';
        });
        $value = ($is_absolute ? '/' : '').implode('/', $segments);

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
    }

    /**
     * Guard an operator-supplied URL that the server is about to fetch directly
     * (the "Test connection" buttons). Enforces an http(s) scheme and blocks
     * link-local / cloud-metadata targets (169.254.0.0/16, IPv6 fe80::/10 and
     * the well-known metadata hostnames) — never a legitimate provider/NetBox
     * endpoint, but the classic SSRF pivot to steal cloud credentials.
     *
     * Loopback and private ranges are deliberately allowed: single-server,
     * multi-server and Docker deployments rely on them (localhost, 10.x,
     * 172.16-31.x, 192.168.x and container DNS names), so blocking them would
     * break the module's normal topologies.
     */
    public static function assertSafeProbeUrl(string $url): void {
        $url = trim($url);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Only http(s) URLs are allowed.');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new \RuntimeException('The URL does not contain a valid host.');
        }

        $host = trim($host, '[]');
        $lower_host = strtolower($host);

        if ($lower_host === 'metadata.google.internal' || $lower_host === 'metadata') {
            throw new \RuntimeException('This host is a cloud metadata endpoint and is not an allowed target.');
        }

        $candidates = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $candidates[] = $host;
        }
        else {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $candidates = $resolved;
            }
        }

        foreach ($candidates as $ip) {
            if (self::isLinkLocalIp((string) $ip)) {
                throw new \RuntimeException('This host resolves to a link-local/metadata address, which is not an allowed target.');
            }
        }
    }

    /** Reject credentials hidden in a configured HTTP endpoint URL. */
    public static function assertNoEmbeddedUrlCredentials(string $url): void {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('The configured HTTP endpoint is not a valid absolute URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Credentials in URL userinfo are forbidden; use the encrypted secret/header fields.');
        }
        if (!empty($parts['fragment'])) {
            throw new \RuntimeException('URL fragments are not allowed in configured HTTP endpoints.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $name) {
            if (preg_match('/(?:^|[_-])(?:key|api[_-]?key|token|secret|password|passwd|signature|sig|credential|access[_-]?token|client[_-]?secret)(?:$|[_-])/i', (string) $name)) {
                throw new \RuntimeException(
                    'Secret-bearing URL query parameter "'.$name.'" is forbidden; use an encrypted header or secret field.'
                );
            }
        }
    }

    /** Remove URL credentials before including an endpoint in an error. */
    public static function sanitizeUrlForDisplay(string $url): string {
        $url = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $url);
        $url = preg_replace_callback(
            '/([?&](?:key|api[_-]?key|token|secret|password|passwd|signature|sig|credential|access[_-]?token|client[_-]?secret)=)[^&#]*/i',
            static function(array $match): string {
                return $match[1].'[REDACTED]';
            },
            (string) $url
        );

        return (string) $url;
    }

    /** Best-effort credential scrub for outward-facing transport errors. */
    public static function sanitizeSensitiveTextForDisplay(string $text): string {
        $text = self::sanitizeUrlForDisplay($text);
        $text = preg_replace(
            '/("(?:password|passwd|secret|token|access[_-]?token|refresh[_-]?token|api[_-]?key|x[_-]?api[_-]?key|authorization|client[_-]?secret|private[_-]?key)"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i',
            '$1"[REDACTED]"',
            $text
        );
        $text = preg_replace(
            '/\b(Authorization\s*:\s*(?:Bearer|Basic|Token)\s+)[^\s,;]+/i',
            '$1[REDACTED]',
            (string) $text
        );

        return (string) $text;
    }

    /**
     * Validate a URL which Zabbix server/proxy will fetch as a web-scenario
     * step. Unlike provider/NetBox test endpoints, these destinations originate
     * in model parameters and therefore require an administrator allowlist.
     *
     * Allowlist entries are exact origins such as https://status.example.com or
     * wildcard origins such as https://*.checks.example.com:8443. Scheme and
     * effective port must match. Paths, userinfo and query strings are not valid
     * in allowlist entries.
     */
    public static function assertAllowedWebScenarioUrl(string $url, $allowed_origins): void {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Web scenarios may use only http(s) URLs.');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \RuntimeException('The web-scenario URL does not contain a valid host.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Credentials embedded in a web-scenario URL are not allowed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        $entries = is_array($allowed_origins)
            ? $allowed_origins
            : preg_split('/\r?\n/', (string) $allowed_origins);
        $matched = false;

        foreach ((array) $entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            $allowed = parse_url($entry);
            if (!is_array($allowed) || empty($allowed['scheme']) || empty($allowed['host'])
                    || isset($allowed['user']) || isset($allowed['pass'])
                    || !empty($allowed['query']) || !empty($allowed['fragment'])
                    || !in_array((string) ($allowed['path'] ?? ''), ['', '/'], true)) {
                continue;
            }

            $allowed_scheme = strtolower((string) $allowed['scheme']);
            if (!in_array($allowed_scheme, ['http', 'https'], true) || $allowed_scheme !== $scheme) {
                continue;
            }

            $allowed_port = isset($allowed['port'])
                ? (int) $allowed['port']
                : ($allowed_scheme === 'https' ? 443 : 80);
            if ($allowed_port !== $port) {
                continue;
            }

            $allowed_host = strtolower(trim((string) $allowed['host'], '[]'));
            if (strncmp($allowed_host, '*.', 2) === 0) {
                $suffix = substr($allowed_host, 1); // includes the leading dot
                $matched = $host !== substr($suffix, 1)
                    && strlen($host) > strlen($suffix)
                    && substr($host, -strlen($suffix)) === $suffix;
            }
            else {
                $matched = hash_equals($allowed_host, $host);
            }

            if ($matched) {
                break;
            }
        }

        if (!$matched) {
            throw new \RuntimeException(
                'The web-scenario destination is not in the administrator allowlist '
                .'(AI Settings > Zabbix Actions > Web scenario allowed origins).'
            );
        }

        self::assertWebScenarioHostIsNotRestricted($host);
    }

    private static function assertWebScenarioHostIsNotRestricted(string $host): void {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || substr($host, -10) === '.localhost'
                || in_array($host, ['metadata', 'metadata.google.internal'], true)) {
            throw new \RuntimeException('Loopback and cloud-metadata web-scenario destinations are blocked.');
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        }
        else {
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, DNS_A | DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        $ip = (string) ($record['ip'] ?? ($record['ipv6'] ?? ''));
                        if ($ip !== '') {
                            $addresses[] = $ip;
                        }
                    }
                }
            }
            if (!$addresses) {
                $resolved = @gethostbynamel($host);
                if (is_array($resolved)) {
                    $addresses = array_merge($addresses, $resolved);
                }
            }
        }

        if (!$addresses) {
            throw new \RuntimeException(
                'The web-scenario destination could not be resolved for SSRF validation. '
                .'Use a resolvable allowlisted origin.'
            );
        }

        foreach (array_unique($addresses) as $ip) {
            if (self::isRestrictedWebScenarioIp((string) $ip)) {
                throw new \RuntimeException(
                    'The web-scenario destination resolves to a loopback, link-local, '
                    .'metadata, multicast or unspecified address and is blocked.'
                );
            }
        }
    }

    private static function isRestrictedWebScenarioIp(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $packed = @inet_pton($ip);
            if (!is_string($packed) || strlen($packed) !== 4) {
                return true;
            }
            $n = unpack('N', $packed)[1];

            return ($n & 0xff000000) === 0x00000000       // 0.0.0.0/8
                || ($n & 0xff000000) === 0x7f000000       // 127.0.0.0/8
                || ($n & 0xffff0000) === 0xa9fe0000       // 169.254.0.0/16
                || ($n & 0xf0000000) === 0xe0000000       // multicast/reserved
                || in_array($ip, ['100.100.100.200'], true);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if (!is_string($packed) || strlen($packed) !== 16) {
                return true;
            }

            // IPv4-mapped/compatible spellings must inherit the IPv4 checks;
            // string matching misses compressed hexadecimal forms such as
            // ::ffff:7f00:1.
            if (substr($packed, 0, 10) === str_repeat("\0", 10)
                    && substr($packed, 10, 2) === "\xff\xff") {
                $mapped = @inet_ntop(substr($packed, 12, 4));

                return !is_string($mapped) || self::isRestrictedWebScenarioIp($mapped);
            }

            return $packed === str_repeat("\0", 16)       // ::
                || $packed === str_repeat("\0", 15)."\1" // ::1
                || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) // fe80::/10
                || ord($packed[0]) === 0xff                 // multicast
                || $packed === @inet_pton('fd00:ec2::254');
        }

        return true;
    }

    private static function isLinkLocalIp(string $ip): bool {
        // IPv4 link-local / cloud metadata range: 169.254.0.0/16.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return strpos($ip, '169.254.') === 0;
        }

        // IPv6 link-local fe80::/10 (fe80–febf) and IPv4-mapped 169.254.x.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);

            return (bool) preg_match('/^fe[89ab][0-9a-f]:/', $lower)
                || strpos($lower, '::ffff:169.254.') !== false;
        }

        return false;
    }

    public static function cleanInt($value, int $default = 0, ?int $min = null, ?int $max = null): int {
        if (!is_numeric($value)) {
            $value = $default;
        }

        $value = (int) $value;

        if ($min !== null && $value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    public static function cleanFloat($value, float $default = 0.0, ?float $min = null, ?float $max = null): float {
        if (!is_numeric($value)) {
            $value = $default;
        }

        $value = (float) $value;

        if ($min !== null && $value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    public static function cleanId($value, string $prefix = 'id'): string {
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '_', trim((string) $value));

        if ($value === '' || $value === null) {
            $value = self::generateId($prefix);
        }

        return $value;
    }

    public static function cleanEnum($value, array $allowed, string $default): string {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function generateId(string $prefix = 'id'): string {
        try {
            return $prefix.'_'.bin2hex(random_bytes(6));
        }
        catch (Throwable $e) {
            return $prefix.'_'.str_replace('.', '', (string) microtime(true)).'_'.mt_rand(1000, 9999);
        }
    }

    public static function normalizeMessages(array $messages, int $max_messages = 12): array {
        $normalized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? '';
            $content = self::cleanMultiline($message['content'] ?? '', 20000);

            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content
            ];
        }

        if ($max_messages > 0 && count($normalized) > $max_messages) {
            $normalized = array_slice($normalized, -$max_messages);
        }

        return array_values($normalized);
    }

    public static function decodeJsonArray($value): array {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function truncate(string $value, int $max_length = 800): string {
        $value = trim($value);

        if (self::strLen($value) <= $max_length) {
            return $value;
        }

        return rtrim(self::subStr($value, 0, $max_length - 1)).'…';
    }

    public static function chunkText(string $text, int $max_length = 1900): array {
        $text = self::cleanMultiline($text);
        $max_length = max(200, $max_length);

        if ($text === '') {
            return [''];
        }

        $chunks = [];
        $buffer = '';
        $paragraphs = preg_split("/\n{2,}/", $text);

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph === '') {
                continue;
            }

            $candidate = ($buffer === '') ? $paragraph : $buffer."\n\n".$paragraph;

            if (self::strLen($candidate) <= $max_length) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            while (self::strLen($paragraph) > $max_length) {
                $slice = self::subStr($paragraph, 0, $max_length);
                $cut_positions = array_filter([
                    self::strrPos($slice, "\n"),
                    self::strrPos($slice, '. '),
                    self::strrPos($slice, '; '),
                    self::strrPos($slice, ', '),
                    self::strrPos($slice, ' ')
                ], static function($candidate) {
                    return $candidate !== false;
                });

                $cut = $cut_positions ? max($cut_positions) : false;

                if ($cut === false || $cut < (int) ($max_length * 0.60)) {
                    $cut = $max_length;
                }

                $chunks[] = trim(self::subStr($paragraph, 0, (int) $cut));
                $paragraph = trim(self::subStr($paragraph, (int) $cut));
            }

            $buffer = $paragraph;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [''];
    }

    public static function formatTags($tags): string {
        if (!is_array($tags) || $tags === []) {
            return '';
        }

        $lines = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $name = trim((string) ($tag['tag'] ?? $tag['name'] ?? ''));
            $value = trim((string) ($tag['value'] ?? ''));

            if ($name === '') {
                continue;
            }

            $lines[] = ($value !== '') ? ($name.': '.$value) : $name;
        }

        return implode("\n", $lines);
    }

    /**
     * Recursively apply a callback to every string in an array/scalar value.
     */
    public static function mapStrings($value, callable $callback) {
        if (is_string($value)) {
            return $callback($value);
        }

        if (is_array($value)) {
            $mapped = [];
            foreach ($value as $key => $item) {
                $mapped[$key] = self::mapStrings($item, $callback);
            }
            return $mapped;
        }

        return $value;
    }

    /**
     * Truncate nested data for logging without breaking structure.
     */
    public static function truncateMixed($value, int $max_string_length = 2000, int $max_items = 100) {
        if (is_string($value)) {
            return self::truncate($value, $max_string_length);
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= $max_items) {
                    $result['__truncated__'] = 'Additional items omitted.';
                    break;
                }
                $result[$key] = self::truncateMixed($item, $max_string_length, $max_items);
                $count++;
            }
            return $result;
        }

        if (is_object($value)) {
            return self::truncateMixed((array) $value, $max_string_length, $max_items);
        }

        return $value;
    }


    private static function strLen(string $value): int {
        return function_exists('mb_strlen') ? (int) mb_strlen($value) : strlen($value);
    }

    private static function subStr(string $value, int $start, ?int $length = null): string {
        if (function_exists('mb_substr')) {
            return $length === null ? (string) mb_substr($value, $start) : (string) mb_substr($value, $start, $length);
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    private static function strrPos(string $haystack, string $needle) {
        return function_exists('mb_strrpos') ? mb_strrpos($haystack, $needle) : strrpos($haystack, $needle);
    }

    public static function sortByLengthDesc(array $values): array {
        usort($values, static function($a, $b) {
            return self::strLen((string) $b) <=> self::strLen((string) $a);
        });

        return $values;
    }
}
