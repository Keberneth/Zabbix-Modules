<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

/**
 * Small input-cleaning and URL-safety helpers, mirroring the reference AI
 * module's Util so the two modules behave the same way for shared concerns
 * (URL validation, SSRF guarding, scalar coercion).
 */
final class Util {

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

    public static function cleanString($value, int $max_length = 0): string {
        $value = trim((string) $value);
        if ($max_length > 0 && strlen($value) > $max_length) {
            $value = substr($value, 0, $max_length);
        }
        return $value;
    }

    /**
     * Strip NUL and CR/LF control characters. Used on secrets/keys so a token
     * with an embedded newline can never be used to inject extra HTTP headers
     * on the stream-context transport.
     */
    public static function stripControlChars($value): string {
        $value = (string) $value;
        return preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '';
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

    /**
     * Accept only http(s) URLs. Blocks dangerous schemes (javascript:, data:,
     * file:, gopher:, ...) from being stored as the Zabbix API URL. Returns ''
     * for anything that is not a clean http(s) URL. Docker/loopback/private
     * hosts all use http(s), so this never breaks local or split deployments.
     */
    public static function cleanUrl($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            return '';
        }
        return self::stripControlChars($value);
    }

    /**
     * Guard a URL the server is about to fetch directly with a privileged token
     * attached. Enforces an http(s) scheme and blocks link-local / cloud-metadata
     * targets (169.254.0.0/16, IPv6 fe80::/10 and the well-known metadata
     * hostnames) — never a legitimate Zabbix frontend endpoint, but the classic
     * SSRF pivot to steal cloud credentials.
     *
     * Loopback and private ranges are deliberately allowed: single-server,
     * split and Docker deployments rely on them (localhost, 10.x, 172.16-31.x,
     * 192.168.x and container DNS names), so blocking them would break the
     * module's normal topologies.
     */
    public static function assertSafeApiUrl(string $url): void {
        $url = trim($url);

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Only http(s) Zabbix API URLs are allowed.');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            throw new \RuntimeException('The Zabbix API URL does not contain a valid host.');
        }

        $host = trim($host, '[]');
        $lower_host = strtolower($host);

        if ($lower_host === 'metadata.google.internal' || $lower_host === 'metadata') {
            throw new \RuntimeException('Refusing to call a cloud metadata endpoint.');
        }

        $candidates = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $candidates[] = $host;
        }
        else {
            $candidates = self::resolveHostIps($host);
        }

        foreach ($candidates as $ip) {
            if (self::isLinkLocalIp((string) $ip)) {
                throw new \RuntimeException('The Zabbix API URL resolves to a link-local/metadata address.');
            }
        }
    }

    /**
     * Resolve a hostname to BOTH its A and AAAA addresses so the SSRF check also
     * sees IPv6 targets. gethostbynamel() returns IPv4 only, which previously let
     * an AAAA-only hostname pointing at an IPv6 link-local / metadata address slip
     * past the guard entirely. dns_get_record() is DNS-only (it does not consult
     * /etc/hosts or container resolvers), so gethostbynamel() stays as the
     * fallback for hostnames resolved that way (e.g. Docker service names).
     */
    private static function resolveHostIps(string $host): array {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string) $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }
        return $ips;
    }

    private static function isLinkLocalIp(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return strpos($ip, '169.254.') === 0;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ((bool) preg_match('/^fe[89ab][0-9a-f]:/', $lower)
                    || strpos($lower, '::ffff:169.254.') !== false) {
                return true;
            }
            // AWS Instance Metadata Service over IPv6 (fd00:ec2::254). Compare the
            // packed form so any textual spelling of the same address matches.
            // Other unique-local (fc00::/7) addresses are deliberately allowed,
            // mirroring the IPv4 private ranges this module supports.
            $packed = @inet_pton($ip);
            return $packed !== false && $packed === @inet_pton('fd00:ec2::254');
        }
        return false;
    }

    public static function truncate(string $value, int $max_length = 800): string {
        $value = trim($value);
        if (strlen($value) <= $max_length) {
            return $value;
        }
        return rtrim(substr($value, 0, $max_length - 1)).'…';
    }

    /**
     * Split text into <= $max_length chunks on paragraph/word boundaries, so a
     * long correlation comment can be posted across several problem updates
     * (Zabbix limits the message length). Ported from the reference AI module.
     */
    public static function chunkText(string $text, int $max_length = 1900): array {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $max_length = max(200, $max_length);

        if ($text === '') {
            return [''];
        }

        $chunks = [];
        $buffer = '';
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [$text];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }

            $candidate = ($buffer === '') ? $paragraph : $buffer."\n\n".$paragraph;
            if (strlen($candidate) <= $max_length) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            while (strlen($paragraph) > $max_length) {
                $slice = substr($paragraph, 0, $max_length);
                $cut = strrpos($slice, "\n");
                if ($cut === false || $cut < (int) ($max_length * 0.6)) {
                    $cut = strrpos($slice, ' ');
                }
                if ($cut === false || $cut < (int) ($max_length * 0.6)) {
                    $cut = $max_length;
                }
                $cut = (int) $cut;
                // Never split a multibyte UTF-8 codepoint on the forced byte cut.
                while ($cut > 0 && $cut < strlen($paragraph) && (ord($paragraph[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $chunks[] = trim(substr($paragraph, 0, $cut));
                $paragraph = trim(substr($paragraph, $cut));
            }

            $buffer = $paragraph;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [''];
    }
}
