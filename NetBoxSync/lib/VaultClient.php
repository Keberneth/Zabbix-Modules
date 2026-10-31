<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use InvalidArgumentException;

/** Minimal read-only HashiCorp KV v2 client; bootstrap settings belong to the server. */
final class VaultClient {

    private const MAX_RESPONSE_BYTES = 1048576;

    public static function read(string $reference): string {
        $reference = SecretReference::normalize($reference);
        if (strncmp($reference, 'vault:', 6) !== 0) {
            throw new InvalidArgumentException('A HashiCorp Vault reference is required.');
        }
        [$path, $key] = explode('#', substr($reference, 6), 2);
        $address = trim((string) getenv('NETBOXSYNC_VAULT_ADDR'));
        $parts = parse_url($address);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || !in_array($parts['path'] ?? '', ['', '/'], true)
                || preg_match('/[\x00-\x20\x7f\\\\]/', $address)) {
            throw new InvalidArgumentException('NETBOXSYNC_VAULT_ADDR must be an HTTPS origin, for example https://vault.example:8200.');
        }

        $token = (string) getenv('NETBOXSYNC_VAULT_TOKEN');
        $token_file = trim((string) getenv('NETBOXSYNC_VAULT_TOKEN_FILE'));
        if (trim($token) !== '' && $token_file !== '') {
            throw new InvalidArgumentException('Configure only one of NETBOXSYNC_VAULT_TOKEN and NETBOXSYNC_VAULT_TOKEN_FILE.');
        }
        if ($token_file !== '') {
            $token_path = self::serverFile($token_file, 'Vault authentication token', true);
            $token = @file_get_contents($token_path, false, null, 0, 65537);
            if (!is_string($token)) {
                throw new InvalidArgumentException('The Vault authentication token file could not be read.');
            }
        }
        if (trim($token) === '') {
            throw new InvalidArgumentException('Configure NETBOXSYNC_VAULT_TOKEN or NETBOXSYNC_VAULT_TOKEN_FILE in this PHP process.');
        }
        $token = SecretReference::validateValue($token);
        $namespace = trim((string) getenv('NETBOXSYNC_VAULT_NAMESPACE'));
        if ($namespace !== '' && (strlen($namespace) > 1024 || !preg_match('#\A[A-Za-z0-9][A-Za-z0-9_.-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*)*/?\z#', $namespace))) {
            throw new InvalidArgumentException('NETBOXSYNC_VAULT_NAMESPACE is invalid.');
        }
        $ca_file = trim((string) getenv('NETBOXSYNC_VAULT_CACERT'));
        $ca_path = $ca_file !== '' ? self::serverFile($ca_file, 'Vault CA certificate', false) : '';
        if (!function_exists('curl_init')) {
            throw new InvalidArgumentException('The PHP cURL extension is required for direct Vault references.');
        }

        $headers = ['Accept: application/json', 'X-Vault-Request: true', 'X-Vault-Token: '.$token];
        if ($namespace !== '') {
            $headers[] = 'X-Vault-Namespace: '.$namespace;
        }
        $body = '';
        $too_large = false;
        $curl = curl_init();
        if ($curl === false) {
            throw new InvalidArgumentException('Could not initialize the Vault connection.');
        }
        try {
            $options = [
                CURLOPT_URL => rtrim($address, '/').'/v1/'.$path,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body, &$too_large): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $too_large = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                }
            ];
            if ($ca_path !== '') {
                $options[CURLOPT_CAINFO] = $ca_path;
            }
            if (!curl_setopt_array($curl, $options)) {
                throw new InvalidArgumentException('Could not configure the Vault connection.');
            }
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($too_large) {
                throw new InvalidArgumentException('The Vault response exceeds the safe size limit.');
            }
            if ($result === false) {
                // Do not expose curl diagnostics, response bodies or authentication headers.
                throw new InvalidArgumentException('Vault connection failed. Check its address, TLS trust and connectivity.');
            }
            if ($status !== 200) {
                throw new InvalidArgumentException('Vault returned HTTP '.$status.'. Check authentication, the read policy and secret path.');
            }
        }
        finally {
            curl_close($curl);
        }

        $decoded = json_decode($body, true, 64);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('Vault returned an invalid JSON response.');
        }
        $data = $decoded['data']['data'] ?? null;
        if (!is_array($data) || !array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new InvalidArgumentException('The Vault KV v2 secret key is missing or is not a string.');
        }
        return SecretReference::validateValue($data[$key]);
    }

    private static function serverFile(string $path, string $label, bool $private): string {
        if (!preg_match('#\A(?:/|[A-Za-z]:[\\\\/]|[\\\\/]{2})#', $path)) {
            throw new InvalidArgumentException($label.' file must use an absolute path.');
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            throw new InvalidArgumentException($label.' file is unavailable or unreadable.');
        }
        if (DIRECTORY_SEPARATOR === '/') {
            foreach ([$canonical, dirname($canonical)] as $entry) {
                clearstatcache(true, $entry);
                $mode = @fileperms($entry);
                if (!is_int($mode) || ($mode & 0022) !== 0) {
                    throw new InvalidArgumentException($label.' file or directory is group/world writable.');
                }
            }
            if ($private && (fileperms($canonical) & 0007) !== 0) {
                throw new InvalidArgumentException('The Vault authentication token file must not be accessible to other users.');
            }
        }
        return $canonical;
    }
}
