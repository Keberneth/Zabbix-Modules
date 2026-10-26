<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

/** Local diagnostics in the serving PHP process. Never sends data to providers. */
class SetupHealth {
    public static function check(array $config): array {
        $config = Config::mergeWithDefaults($config);
        $checks = [];
        $add = static function(string $label, string $status, string $message) use (&$checks): void {
            $checks[] = compact('label', 'status', 'message');
        };
        $plain = Config::allowsPlaintextSecrets($config);
        $crypto = Crypto::isAvailable();
        $add('Credential storage', $crypto ? 'ok' : ($plain ? 'notice' : 'error'),
            $crypto ? 'Encryption is available in this PHP process.'
                : ($plain ? 'Plaintext compatibility is explicitly enabled.'
                    : 'Configure the master key using bin/setup.sh, or approve plaintext compatibility below.'));
        if ($plain && $crypto) {
            $add('Storage exception', 'notice', 'Plaintext compatibility remains enabled; encryption is currently available.');
        }
        $key_path = (string) getenv('ZABBIX_AI_ENCRYPTION_KEY_FILE');
        if ($key_path !== '' && is_file($key_path) && DIRECTORY_SEPARATOR === '/') {
            $mode = @fileperms($key_path);
            if ($mode !== false && ($mode & 0007) !== 0) {
                $add('Master key permissions', 'error', 'Remove access for other users from the master-key file (use 0640 or stricter).');
            }
        }
        foreach (['curl', 'json', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) {
                $add('PHP '.$extension, 'error', 'Enable this extension in the PHP process serving Zabbix.');
            }
        }
        $providers = array_values(array_filter($config['providers'], static function(array $p): bool {
            return Util::truthy($p['enabled'] ?? false);
        }));
        if (!$providers) {
            $add('Provider', 'error', 'Add and enable a provider, select a model, then save settings.');
        }
        else {
            $purposes = ['chat', 'actions'];
            if (!empty($config['webhook']['enabled'])) { $purposes[] = 'webhook'; }
            foreach ($purposes as $purpose) {
                if (Config::getProvider($config, '', $purpose) === null) {
                    $add('Default '.$purpose.' provider', 'error', 'The selected provider is missing or disabled. Select an enabled provider and save settings.');
                }
            }
        }
        foreach ($providers as $i => $provider) {
            $label = 'Provider '.($i + 1);
            if (trim((string) ($provider['model'] ?? '')) === '') {
                $add($label, 'error', 'Select a model and save settings.');
            }
            self::transportCheck($provider['endpoint'] ?? '', $provider, $label, $add);
            self::secretCheck($provider, 'api_key', 'api_key_env', $label.' key', $plain, $add);
            self::secretCheck($provider, 'headers_json', 'headers_json_ref', $label.' headers', $plain, $add);
        }
        foreach ([['netbox', 'token', 'token_env'], ['webhook', 'shared_secret', 'shared_secret_env'],
                ['zabbix_api', 'token', 'token_env']] as [$section, $field, $reference]) {
            // Check every stored credential so key rotation cannot conceal an
            // unreadable secret just because its integration is currently off.
            self::secretCheck($config[$section], $field, $reference, $section.' credential', $plain, $add);
        }
        if (!empty($config['netbox']['enabled'])) {
            if ($config['netbox']['url'] === '' || ($config['netbox']['token'] === '' && $config['netbox']['token_env'] === '')) {
                $add('NetBox configuration', 'error', 'Enter a URL and token or secret reference, or disable NetBox.');
            }
            self::transportCheck($config['netbox']['url'], $config['netbox'], 'NetBox', $add);
        }
        if (!empty($config['webhook']['enabled'])) {
            if (!empty($config['webhook']['require_secret']) && $config['webhook']['shared_secret'] === ''
                    && $config['webhook']['shared_secret_env'] === '') {
                $add('Webhook authentication', 'error', 'Configure the shared secret or disable the optional webhook.');
            }
            if (!empty($config['webhook']['add_problem_update']) && ($config['zabbix_api']['url'] === ''
                    || ($config['zabbix_api']['token'] === '' && $config['zabbix_api']['token_env'] === ''))) {
                $add('Webhook event posting', 'error', 'Configure the Zabbix API URL and token for automated event updates.');
            }
        }
        $state = rtrim($config['security']['state_path'], '/');
        $paths = [];
        if (!empty($config['security']['enabled']) || !empty($config['zabbix_actions']['enabled'])
                || !empty($config['webhook']['enabled'])) {
            $paths['State directory'] = $state;
        }
        if (!empty($config['zabbix_actions']['enabled'])) {
            $paths['Confirmation directory'] = $state.'/pending';
        }
        if (!empty($config['reports']['enabled'])) {
            $paths['Reports directory'] = $config['reports']['directory'] ?: $state.'/reports';
        }
        if (!empty($config['logging']['enabled'])) {
            $paths['Audit directory'] = $config['logging']['path'];
            if (!empty($config['logging']['archive_enabled'])) {
                $paths['Audit archive directory'] = $config['logging']['archive_path'];
            }
        }
        foreach ($paths as $label => $path) {
            $ok = self::directoryWorks($path);
            $add($label, $ok ? 'ok' : 'error', $ok ? 'PHP can write and read this directory.'
                : 'Create this directory and grant the PHP worker read/write access: '.$path);
        }
        $errors = count(array_filter($checks, static function(array $c): bool { return $c['status'] === 'error'; }));
        return ['ready' => $errors === 0, 'errors' => $errors, 'checks' => $checks];
    }

    private static function secretCheck(array $values, string $field, string $reference, string $label,
            bool $plain, callable $add): void {
        $value = (string) ($values[$field] ?? '');
        $ref = (string) ($values[$reference] ?? '');
        if ($value === '' && $ref === '') {
            return; // Some local providers intentionally have no API key.
        }
        try {
            if (Config::resolveSecret($value, $ref, $plain) === '') {
                throw new \RuntimeException('Unavailable');
            }
            $legacy = $ref === '' && !Crypto::isEncrypted($value) && !SecretReference::isExplicitReference($value);
            $add($label, $legacy ? 'notice' : 'ok', $legacy
                ? 'Stored as plaintext. Save with encryption available to migrate, or keep the approved compatibility setting.'
                : 'Credential can be read; its value is never displayed.');
        }
        catch (\Throwable $e) {
            $add($label, 'error', 'Credential cannot be read. Check the master key or secret reference, or replace this credential.');
        }
    }

    private static function transportCheck(string $url, array $settings, string $label, callable $add): void {
        if ($url === '') { return; }
        try {
            HttpClient::assertTransport($url, !empty($settings['allow_insecure_http']));
            if (!empty($settings['allow_insecure_http']) || empty($settings['verify_peer'])) {
                $add($label.' transport', 'notice', 'A transport exception is enabled. Review it in this connection\'s settings.');
            }
        }
        catch (\Throwable $e) {
            $add($label.' transport', 'error', 'Use HTTPS or explicitly enable this connection\'s Allow HTTP exception.');
        }
    }

    private static function directoryWorks(string $path): bool {
        if (!is_dir($path) || !is_writable($path)) { return false; }
        $probe = rtrim($path, '/').'/'.'.ai-health-'.bin2hex(random_bytes(12));
        $handle = @fopen($probe, 'x+b');
        if ($handle === false) { return false; }
        try {
            @chmod($probe, 0600);
            if (fwrite($handle, 'ok') !== 2 || !fflush($handle) || !rewind($handle)) { return false; }
            return fread($handle, 2) === 'ok';
        }
        finally { fclose($handle); @unlink($probe); }
    }
}
