<?php declare(strict_types = 1);

namespace Modules\NetBoxSync\Lib {
    /** Deterministic offline transport; no socket or HTTP request is opened. */
    final class VaultTransportDouble {
        public static array $options = [];
        public static int $status = 200;
        public static string $body = '';
        public static bool $fail = false;
        public static int $calls = 0;
        public static int $database_reads = 0;
        public static array $config = [];
    }
    function curl_init() { return new \stdClass(); }
    function curl_setopt_array($handle, array $options): bool {
        VaultTransportDouble::$options = $options;
        return true;
    }
    function curl_exec($handle) {
        VaultTransportDouble::$calls++;
        if (VaultTransportDouble::$fail) { return false; }
        $write = VaultTransportDouble::$options[CURLOPT_WRITEFUNCTION];
        foreach (str_split(VaultTransportDouble::$body, 8192) as $chunk) {
            if ($write($handle, $chunk) !== strlen($chunk)) { return false; }
        }
        return true;
    }
    function curl_getinfo($handle, $option): int { return VaultTransportDouble::$status; }
    function curl_close($handle): void {}
    function DBselect($query) { VaultTransportDouble::$database_reads++; return true; }
    function DBfetch($result): array { return ['config' => VaultTransportDouble::$config]; }
    function zbx_dbstr($value): string { return "'".$value."'"; }
}

namespace {
    // The production client still checks cURL availability. Supply only the
    // capability marker/constants when this offline PHP build omits cURL.
    if (!function_exists('curl_init')) { function curl_init() { return false; } }
    foreach (['CURLOPT_URL', 'CURLOPT_HTTPGET', 'CURLOPT_HTTPHEADER', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_MAXREDIRS', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROTOCOLS',
        'CURLPROTO_HTTPS', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_RETURNTRANSFER',
        'CURLOPT_HEADER', 'CURLOPT_WRITEFUNCTION', 'CURLOPT_CAINFO', 'CURLINFO_HTTP_CODE'] as $index => $name) {
        if (!defined($name)) { define($name, 90000 + $index); }
    }
    require_once dirname(__DIR__).'/lib/bootstrap.php';
    class CController {}
    class API {}
    require_once dirname(__DIR__).'/actions/RunSync.php';

    use Modules\NetBoxSync\Lib\Config;
    use Modules\NetBoxSync\Lib\SecretReference;
    use Modules\NetBoxSync\Lib\VaultTransportDouble;

    $checks = 0;
    $assert = static function(bool $ok, string $message) use (&$checks): void {
        $checks++;
        if (!$ok) { throw new \RuntimeException('FAIL: '.$message); }
    };
    $fails = static function(callable $operation, string $message, string $expected = '') use ($assert): void {
        try { $operation(); }
        catch (\Throwable $e) {
            $assert($expected === '' || strpos($e->getMessage(), $expected) !== false, $message.' (wrong error)');
            $assert(strpos($e->getMessage(), 'DO-NOT-EXPOSE') === false, $message.' (secret leak)');
            return;
        }
        $assert(false, $message.' (not rejected)');
    };
    $root = sys_get_temp_dir().'/netboxsync-vault-test-'.bin2hex(random_bytes(5));
    mkdir($root, 0700);
    $reference = 'vault:secret/data/netbox#api_token';
    $reset = static function(): void {
        putenv('NETBOXSYNC_VAULT_ADDR=https://vault.example.test:8200');
        putenv('NETBOXSYNC_VAULT_TOKEN=DO-NOT-EXPOSE-bootstrap');
        putenv('NETBOXSYNC_VAULT_TOKEN_FILE');
        putenv('NETBOXSYNC_VAULT_NAMESPACE');
        putenv('NETBOXSYNC_VAULT_CACERT');
        putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS');
        VaultTransportDouble::$status = 200;
        VaultTransportDouble::$fail = false;
        VaultTransportDouble::$body = json_encode(['data' => ['data' => ['api_token' => 'DO-NOT-EXPOSE-result']]]);
    };
    try {
        $reset();
        $assert(SecretReference::isExplicitReference($reference), 'Vault reference recognized');
        putenv('NETBOXSYNC_VAULT_ADDR');
        putenv('NETBOXSYNC_VAULT_TOKEN');
        $assert(SecretReference::normalize($reference) === $reference, 'save validation independent of deployment');
        $saved = Config::buildSecretSettings(['token' => $reference], ['token' => 'old']);
        $assert($saved === ['token' => '', 'token_env' => $reference], 'Vault reference replaces stored token');
        $fails(fn() => SecretReference::resolve($reference), 'missing server deployment', 'HTTPS origin');
        $before = VaultTransportDouble::$calls;
        $disabled = Config::defaults();
        $disabled['netbox']['token_env'] = $reference;
        $fails(fn() => new \Modules\NetBoxSync\Lib\SyncEngine($disabled), 'disabled integration does not resolve Vault', 'disabled');
        $assert(VaultTransportDouble::$calls === $before, 'disabled integration opens no transport');
        $_REQUEST = [];
        unset($_SERVER['HTTP_X_NETBOX_SYNC_SECRET'], $_SERVER['HTTP_X_NETBOXSYNC_SECRET']);
        $controller = new \Modules\NetBoxSync\Actions\RunSync();
        $auth = new \ReflectionMethod($controller, 'hasValidSharedSecret');
        $auth->setAccessible(true);
        $assert($auth->invoke($controller) === false, 'no provided runner secret');
        $assert(VaultTransportDouble::$database_reads === 0 && VaultTransportDouble::$calls === $before, 'manual run makes no legacy credential lookup');
        $_REQUEST['secret'] = 'provided';
        VaultTransportDouble::$config = ['runner' => ['enabled' => false, 'shared_secret_env' => $reference]];
        $assert($auth->invoke($controller) === false, 'disabled runner rejects legacy authentication');
        $assert(VaultTransportDouble::$database_reads === 1 && VaultTransportDouble::$calls === $before, 'disabled runner does not contact Vault');
        $_REQUEST = [];
        foreach (['vault:secret/netbox#token', 'vault:secret/data/../netbox#token', 'vault:secret/data/%2e%2e/x#token',
            'vault:https://other.example/data/x#token', 'vault:/secret/data/netbox#token', 'vault:secret/data/netbox?x=1#token',
            'vault:secret/data/netbox#token#other', 'vault:secret/data/netbox#', 'vault:secret/data/a\\b#token',
            "vault:secret/data/a\r\nHeader#token"] as $invalid) {
            $fails(fn() => SecretReference::normalize($invalid), 'invalid Vault path');
        }

        $reset();
        $assert(SecretReference::resolve($reference) === 'DO-NOT-EXPOSE-result', 'KV v2 value read');
        $options = VaultTransportDouble::$options;
        $assert($options[CURLOPT_URL] === 'https://vault.example.test:8200/v1/secret/data/netbox', 'correct KV v2 URL');
        $assert($options[CURLOPT_HTTPGET] === true, 'GET only');
        $assert($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'certificate and hostname verified');
        $assert($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'redirects disabled');
        $assert($options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'HTTPS only');
        $assert($options[CURLOPT_CONNECTTIMEOUT] === 5 && $options[CURLOPT_TIMEOUT] === 15, 'bounded timeouts');
        $assert(in_array('X-Vault-Token: DO-NOT-EXPOSE-bootstrap', $options[CURLOPT_HTTPHEADER], true), 'Vault auth header');
        $assert(in_array('X-Vault-Request: true', $options[CURLOPT_HTTPHEADER], true), 'Vault request header');
        SecretReference::resolve('vault:team/kv/data/production/netbox#api_token');
        $assert(VaultTransportDouble::$options[CURLOPT_URL] === 'https://vault.example.test:8200/v1/team/kv/data/production/netbox', 'nested mount and secret path');
        putenv('NETBOXSYNC_VAULT_NAMESPACE=admin/monitoring');
        file_put_contents($root.'/ca.pem', 'test-certificate');
        chmod($root.'/ca.pem', 0644);
        putenv('NETBOXSYNC_VAULT_CACERT='.$root.'/ca.pem');
        SecretReference::resolve($reference);
        $assert(in_array('X-Vault-Namespace: admin/monitoring', VaultTransportDouble::$options[CURLOPT_HTTPHEADER], true), 'namespace header');
        $assert(VaultTransportDouble::$options[CURLOPT_CAINFO] === $root.'/ca.pem', 'deployment CA file');

        foreach (['http://vault.example', 'https://user:DO-NOT-EXPOSE@vault.example', 'https://vault.example/path',
            'https://vault.example?token=DO-NOT-EXPOSE', 'https://vault.example#frag', "https://vault.example\r\nInjected: x"] as $address) {
            $reset(); putenv('NETBOXSYNC_VAULT_ADDR='.$address);
            $fails(fn() => SecretReference::resolve($reference), 'unsafe Vault address');
        }
        $reset(); putenv("NETBOXSYNC_VAULT_NAMESPACE=admin\r\nDO-NOT-EXPOSE: x");
        $fails(fn() => SecretReference::resolve($reference), 'namespace header injection');
        $reset(); putenv("NETBOXSYNC_VAULT_TOKEN=DO-NOT-EXPOSE\r\nInjected: x");
        $fails(fn() => SecretReference::resolve($reference), 'token header injection');
        $reset(); putenv('NETBOXSYNC_VAULT_TOKEN');
        $fails(fn() => SecretReference::resolve($reference), 'missing bootstrap token');

        $reset();
        foreach (['NETBOXSYNC_VAULT_ADDR', 'NETBOXSYNC_VAULT_TOKEN', 'NETBOXSYNC_VAULT_TOKEN_FILE',
            'NETBOXSYNC_VAULT_NAMESPACE', 'NETBOXSYNC_VAULT_CACERT'] as $reserved) {
            putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS='.$reserved);
            $fails(fn() => SecretReference::normalize('env:'.$reserved), 'bootstrap env cannot be repurposed');
        }
        $reset(); file_put_contents($root.'/auth-token', "DO-NOT-EXPOSE-file\n"); chmod($root.'/auth-token', 0600);
        putenv('NETBOXSYNC_VAULT_TOKEN_FILE='.$root.'/auth-token');
        $fails(fn() => SecretReference::resolve($reference), 'ambiguous bootstrap sources');
        putenv('NETBOXSYNC_VAULT_TOKEN');
        SecretReference::resolve($reference);
        $assert(in_array('X-Vault-Token: DO-NOT-EXPOSE-file', VaultTransportDouble::$options[CURLOPT_HTTPHEADER], true), 'file authentication');
        file_put_contents($root.'/auth-token', 'DO-NOT-EXPOSE-rotated');
        SecretReference::resolve($reference);
        $assert(in_array('X-Vault-Token: DO-NOT-EXPOSE-rotated', VaultTransportDouble::$options[CURLOPT_HTTPHEADER], true), 'bootstrap rotation');
        putenv('NETBOXSYNC_SECRET_DIR='.$root);
        $fails(fn() => SecretReference::resolve('file:auth-token'), 'bootstrap file not an integration credential');
        putenv('NETBOXSYNC_VAULT_TOKEN_FILE= '.$root.'/auth-token ');
        $fails(fn() => SecretReference::resolve('file:auth-token'), 'bootstrap file exclusion normalizes whitespace');
        putenv('NETBOXSYNC_VAULT_TOKEN_FILE='.$root.'/auth-token');
        if (DIRECTORY_SEPARATOR === '/') {
            symlink($root.'/auth-token', $root.'/auth-link');
            $fails(fn() => SecretReference::resolve('file:auth-link'), 'bootstrap symlink not an integration credential');
            link($root.'/auth-token', $root.'/auth-hardlink');
            $fails(fn() => SecretReference::resolve('file:auth-hardlink'), 'bootstrap hardlink not an integration credential');
            chmod($root.'/auth-token', 0644);
            $fails(fn() => SecretReference::resolve($reference), 'public bootstrap token refused');
            chmod($root.'/auth-token', 0660);
            $fails(fn() => SecretReference::resolve($reference), 'writable bootstrap token refused');
            chmod($root.'/auth-token', 0600);
        }
        putenv('NETBOXSYNC_VAULT_TOKEN_FILE=relative');
        $fails(fn() => SecretReference::resolve($reference), 'relative token file refused');

        foreach ([301, 302, 307, 400, 403, 404, 429, 500] as $status) {
            $reset(); VaultTransportDouble::$status = $status; VaultTransportDouble::$body = 'DO-NOT-EXPOSE-server-body';
            $before = VaultTransportDouble::$calls;
            $fails(fn() => SecretReference::resolve($reference), 'HTTP '.$status, 'HTTP '.$status);
            $assert(VaultTransportDouble::$calls === $before + 1, 'no retries or redirect follow-up');
        }
        $reset(); VaultTransportDouble::$fail = true;
        $fails(fn() => SecretReference::resolve($reference), 'transport failure', 'connection failed');
        $reset(); VaultTransportDouble::$body = str_repeat('x', 1048577);
        $fails(fn() => SecretReference::resolve($reference), 'bounded response', 'size limit');
        foreach (['DO-NOT-EXPOSE-not-json', '{}', '{"data":{"api_token":"DO-NOT-EXPOSE-kv1"}}',
            '{"data":{"data":{"api_token":null}}}', '{"data":{"data":{"api_token":123}}}',
            '{"data":{"data":{"api_token":[]}}}', '{"data":{"data":{"api_token":""}}}'] as $body) {
            $reset(); VaultTransportDouble::$body = $body;
            $fails(fn() => SecretReference::resolve($reference), 'invalid KV v2 result');
        }
        $reset(); VaultTransportDouble::$body = json_encode(['data' => ['data' => ['api_token' => str_repeat('x', 65537)]]]);
        $fails(fn() => SecretReference::resolve($reference), 'oversized returned credential');
        echo 'OK: '.$checks." direct Vault checks (offline transport double)\n";
    }
    finally {
        foreach (scandir($root) as $name) {
            if ($name !== '.' && $name !== '..') { unlink($root.'/'.$name); }
        }
        rmdir($root);
    }
}
