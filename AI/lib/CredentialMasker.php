<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/**
 * Irreversible masking of recognizable credentials, not entropy guessing.
 *
 * The returned secrets are REQUEST-LOCAL purge candidates only. They include
 * removed literal values, extracted auth/cookie/password components, and
 * decoded URL values. Never persist, log or return them to a browser.
 */
final class CredentialMasker {
    private const MARKER = '[REDACTED]';
    private const FAILURE = 'Credential masking failed; the request was not sent.';
    private const MAX_SECRETS = 4096;

    public static function redact(string $text): array {
        $secrets = [];
        $count = 0;

        // A missing END marker consumes the remaining text deliberately: a
        // truncated private key must not leak its final lines as ordinary prose.
        $text = self::replace(
            '~-----BEGIN ((?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY)-----[\s\S]*?(?:-----END \1-----|$)~',
            static function(array $match) use (&$secrets, &$count): string {
                return self::protect($match[0], $secrets, $count);
            },
            $text
        );

        // Real HTTP headers are line values. Cookie attributes and auth schemes
        // are part of that value; JSON/header dictionaries are handled below.
        $text = self::replace(
            '~^([ \t]*(?:[<>][ \t]*)?(Authorization|Proxy-Authorization|Cookie|Set-Cookie)[ \t]*:[ \t]*)([^\r\n]*)~im',
            static function(array $match) use (&$secrets, &$count): string {
                self::collectComponents($match[2], $match[3], $secrets);
                return $match[1].self::protect($match[3], $secrets, $count);
            },
            $text
        );

        $userinfo_pattern = <<<'REGEX'
~\b([a-z][a-z0-9+.-]{1,20}:(?:\\?/){2})([^\s/\\<>"'@]+)@~i
REGEX;
        $text = self::replace(
            $userinfo_pattern,
            static function(array $match) use (&$secrets, &$count): string {
                if ($match[2] === self::MARKER) {
                    return $match[0];
                }
                self::addUrlSecret($match[2], $secrets);
                $colon = strpos($match[2], ':');
                if ($colon !== false) {
                    self::addUrlSecret(substr($match[2], $colon + 1), $secrets);
                }
                return $match[1].self::protect($match[2], $secrets, $count).'@';
            },
            $text
        );

        $query_pattern = <<<'REGEX'
~([?&](?:amp;)?)([A-Za-z0-9_.%\~-]{1,128})=([^\s&#<>"']*)~i
REGEX;
        $text = self::replace(
            $query_pattern,
            static function(array $match) use (&$secrets, &$count): string {
                $name = rawurldecode($match[2]);
                if (!self::isCredentialName($name, true) || $match[3] === self::MARKER) {
                    return $match[0];
                }
                self::addUrlSecret($match[3], $secrets);
                return $match[1].$match[2].'='.self::protect($match[3], $secrets, $count);
            },
            $text
        );

        $text = self::assignments($text, $secrets, $count);

        // Only distinctive published token prefixes, with minimum and maximum
        // lengths. Ordinary random strings and hashes are deliberately ignored.
        $text = self::replace(
            '~(?<![A-Za-z0-9_-])(?:sk-(?:proj-|ant-)?[A-Za-z0-9_-]{20,512}|ghp_[A-Za-z0-9]{20,255}|github_pat_[A-Za-z0-9_]{20,255}|glpat-[A-Za-z0-9_-]{16,255}|xoxb-[A-Za-z0-9-]{20,255})(?![A-Za-z0-9_-])~',
            static function(array $match) use (&$secrets, &$count): string {
                return self::protect($match[0], $secrets, $count);
            },
            $text
        );

        // Validate the JOSE header so arbitrary dotted strings, hostnames,
        // version numbers and hashes do not become "JWTs".
        $text = self::replace(
            '~(?<![A-Za-z0-9_.-])(eyJ[A-Za-z0-9_-]{1,4096})\.([A-Za-z0-9_-]{1,8192})\.([A-Za-z0-9_-]{0,4096})(?![A-Za-z0-9_.-])~',
            static function(array $match) use (&$secrets, &$count): string {
                $header = self::decodeBase64Url($match[1]);
                $decoded = $header !== null ? json_decode($header, true) : null;
                if (!is_array($decoded) || !isset($decoded['alg']) || !is_string($decoded['alg'])) {
                    return $match[0];
                }
                return self::protect($match[0], $secrets, $count);
            },
            $text
        );

        return ['text' => $text, 'count' => $count, 'secrets' => array_values($secrets)];
    }

    private static function assignments(string $text, array &$secrets, int &$count): string {
        // Match only the field prefix. A small scanner handles ordinary and
        // escaped JSON quotes, without a nested/unbounded regex for values.
        $pattern = <<<'REGEX'
~(?<![A-Za-z0-9_.-])(?<q>\\?["']?)(?<name>[A-Za-z_][A-Za-z0-9_.-]{0,127})\k<q>[ \t]*(?<separator>[:=])[ \t]*~
REGEX;
        $matched = preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matched === false) {
            throw new RuntimeException(self::FAILURE);
        }
        $cursor = 0;
        $output = '';
        $length = strlen($text);

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($offset < $cursor || !self::isCredentialName($match['name'][0])) {
                continue;
            }
            $start = $offset + strlen($match[0][0]);
            if ($start >= $length || $text[$start] === "\n" || $text[$start] === "\r") {
                continue;
            }

            // Avoid consuming safe URL parameters after the query pass.
            if (substr($text, $start, strlen(self::MARKER)) === self::MARKER) {
                continue;
            }
            [$value_start, $value_end, $end] = self::valueBounds(
                $text, $start, $offset, $match['separator'][0], $match['q'][0], $match['name'][0]
            );
            if ($value_end <= $value_start) {
                continue;
            }
            $value = substr($text, $value_start, $value_end - $value_start);
            if ($value === self::MARKER) {
                continue;
            }
            // Include the decoded string as a purge candidate when a JSON
            // credential is escaped; the masked output keeps its original form.
            if ($text[$start] === '"' || substr($text, $start, 2) === '\\"') {
                $decoded = json_decode('"'.$value.'"', true);
                if (is_string($decoded)) {
                    self::addSecret($decoded, $secrets);
                    if ($text[$start] === '\\') {
                        $inner = json_decode('"'.$decoded.'"', true);
                        if (is_string($inner)) {
                            self::addSecret($inner, $secrets);
                        }
                    }
                }
            }
            self::collectComponents($match['name'][0], $value, $secrets);
            $replacement = self::protect($value, $secrets, $count);
            $output .= substr($text, $cursor, $value_start - $cursor).$replacement
                .substr($text, $value_end, $end - $value_end);
            $cursor = $end;
        }

        return $output.substr($text, $cursor);
    }

    /** [first value byte, first byte after value, first byte after closing quote] */
    private static function valueBounds(
        string $text, int $start, int $key_start, string $separator, string $key_quote, string $name
    ): array {
        $length = strlen($text);
        $escaped_quote = $text[$start] === '\\' && $start + 1 < $length
            && ($text[$start + 1] === '"' || $text[$start + 1] === "'");
        $quote = $escaped_quote ? $text[$start + 1] : $text[$start];
        if ($quote === '"' || $quote === "'") {
            $value_start = $start + ($escaped_quote ? 2 : 1);
            $slashes = 0;
            for ($index = $value_start; $index < $length; ++$index) {
                if ($text[$index] === '\\') {
                    ++$slashes;
                    continue;
                }
                if ($text[$index] === $quote
                        && ($escaped_quote ? $slashes % 4 === 1 : $slashes % 2 === 0)) {
                    return [$value_start, $index - ($escaped_quote ? 1 : 0), $index + 1];
                }
                $slashes = 0;
            }
            return [$value_start, $length, $length];
        }

        // Unquoted line-oriented INI/env values may contain spaces, commas or
        // punctuation. Preserve those as part of the secret rather than leaking
        // a password suffix. General log/DSN fields stop at their separators.
        $whole_line = $separator === '=' && $key_quote === ''
            && self::startsAssignmentLine($text, $key_start);
        $normalized = strtolower(str_replace(['-', '.'], '_', $name));
        $header = in_array($normalized, ['authorization', 'proxy_authorization', 'cookie', 'set_cookie'], true);
        $end = $start;
        while ($end < $length && $text[$end] !== "\r" && $text[$end] !== "\n") {
            if (!$whole_line && !$header && strpos(" \t,;}>&\"'", $text[$end]) !== false) {
                break;
            }
            ++$end;
        }
        return [$start, $end, $end];
    }

    private static function startsAssignmentLine(string $text, int $offset): bool {
        // Inspect only the whitespace immediately before this key, rather than
        // copying/scanning the entire preceding input for every log field.
        while ($offset > 0 && strpos(" \t\r", $text[$offset - 1]) !== false) {
            --$offset;
        }
        if ($offset === 0 || $text[$offset - 1] === "\n") {
            return true;
        }
        if ($offset < 6 || substr($text, $offset - 6, 6) !== 'export') {
            return false;
        }
        $offset -= 6;
        while ($offset > 0 && strpos(" \t\r", $text[$offset - 1]) !== false) {
            --$offset;
        }
        return $offset === 0 || $text[$offset - 1] === "\n";
    }

    private static function isCredentialName(string $name, bool $query = false): bool {
        $name = strtolower(str_replace(['-', '.'], '_', $name));
        $compact = str_replace('_', '', $name);
        if (in_array($compact, [
            'password', 'passwd', 'pwd', 'passphrase', 'secret', 'clientsecret',
            'apikey', 'apitoken', 'accesstoken', 'refreshtoken', 'idtoken', 'authtoken',
            'authorization', 'proxyauthorization', 'cookie', 'setcookie', 'token',
            'sessiontoken', 'sessionid', 'secretaccesskey', 'accesskeyid', 'accesskey',
            'privatekey', 'sastoken', 'sharedaccesssignature'
        ], true)) {
            return true;
        }
        if ($query && in_array($compact, [
            'sig', 'signature', 'xamzsignature', 'xamzcredential', 'xamzsecuritytoken',
            'xgoogsignature', 'xgoogcredential', 'awsaccesskeyid', 'googleaccessid'
        ], true)) {
            return true;
        }
        return self::matches(
            '~(?:^|_)(?:password|passwd|pwd|passphrase|secret|token|api_key|apikey|secret_access_key|access_key_id|access_key)$~',
            $name
        );
    }

    private static function protect(string $value, array &$secrets, int &$count): string {
        if (trim($value) === '' || trim($value) === self::MARKER) {
            return $value;
        }
        self::addSecret($value, $secrets);
        if (trim($value) !== $value) {
            self::addSecret(trim($value), $secrets);
        }
        ++$count;
        return self::MARKER;
    }

    private static function collectComponents(string $name, string $value, array &$secrets): void {
        $name = strtolower(str_replace(['-', '.'], '_', $name));
        if (in_array($name, ['authorization', 'proxy_authorization'], true)) {
            $matched = preg_match('~^(?:Bearer|Basic|Token|ApiKey)[ \t]+(.+)$~i', trim($value), $match);
            if ($matched === false) {
                throw new RuntimeException(self::FAILURE);
            }
            if ($matched) {
                self::addSecret($match[1], $secrets);
            }
        }
        if (in_array($name, ['cookie', 'set_cookie'], true)) {
            $matched = preg_match_all('~(?:^|;)[ \t]*[^=;\s]+[ \t]*=[ \t]*([^;]*)~', $value, $matches);
            if ($matched === false) {
                throw new RuntimeException(self::FAILURE);
            }
            // Set-Cookie has one cookie followed by attributes. Path, Domain,
            // SameSite, Max-Age etc. must never become secret purge candidates.
            $cookies = $name === 'set_cookie' ? array_slice($matches[1], 0, 1) : $matches[1];
            foreach ($cookies as $cookie) {
                self::addUrlSecret(trim($cookie, " \t\"'"), $secrets);
            }
        }
    }

    private static function addUrlSecret(string $value, array &$secrets): void {
        self::addSecret($value, $secrets);
        self::addSecret(rawurldecode($value), $secrets);
        // Query-form '+' can encode a space. Include both interpretations only
        // as local purge candidates; the original text is not decoded/rebuilt.
        if (strpos($value, '+') !== false) {
            self::addSecret(urldecode($value), $secrets);
        }
    }

    private static function addSecret(string $value, array &$secrets): void {
        if ($value === '' || $value === self::MARKER) {
            return;
        }
        $key = hash('sha256', $value);
        if (!isset($secrets[$key]) && count($secrets) >= self::MAX_SECRETS) {
            throw new RuntimeException(self::FAILURE);
        }
        $secrets[$key] = $value;
    }

    private static function decodeBase64Url(string $value): ?string {
        $value = strtr($value, '-_', '+/');
        $decoded = base64_decode($value.str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return is_string($decoded) ? $decoded : null;
    }

    private static function replace(string $pattern, callable $callback, string $text): string {
        $result = preg_replace_callback($pattern, $callback, $text);
        if (!is_string($result)) {
            throw new RuntimeException(self::FAILURE);
        }
        return $result;
    }

    private static function matches(string $pattern, string $text): bool {
        $result = preg_match($pattern, $text);
        if ($result === false) {
            throw new RuntimeException(self::FAILURE);
        }
        return $result === 1;
    }
}
