<?php declare(strict_types = 1);

require_once dirname(__DIR__).'/lib/bootstrap.php';

use Modules\NetBoxSync\Lib\Config;
use Modules\NetBoxSync\Lib\SecretReference;
use Modules\NetBoxSync\Lib\SyncEngine;
use Modules\NetBoxSync\Lib\ZabbixApiClient;

// The manual sync constructor uses the logged-in API facade, without requests.
if (!class_exists('API')) {
    class API {}
}

$checks = 0;
$assert = static function(bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
};
$fails = static function(callable $operation, string $message, string $expected = '') use ($assert): void {
    try {
        $operation();
    }
    catch (Throwable $e) {
        $assert($expected === '' || strpos($e->getMessage(), $expected) !== false, $message.' (wrong error)');
        $assert(strpos($e->getMessage(), 'sentinel-secret-value') === false, $message.' (secret in error)');
        return;
    }
    $assert(false, $message.' (did not reject)');
};

$root = sys_get_temp_dir().'/netboxsync-secret-test-'.bin2hex(random_bytes(5));
$outside = $root.'-outside';
mkdir($root, 0700);
file_put_contents($outside, 'outside-secret');
chmod($outside, 0600);
putenv('NETBOXSYNC_SECRET_DIR='.$root);
putenv('NETBOX_API_TOKEN=sentinel-secret-value');
putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS');

try {
    foreach (['NETBOX_API_TOKEN', 'NETBOX_TOKEN', 'ZABBIX_API_TOKEN', 'NETBOXSYNC_ZABBIX_API_TOKEN', 'NETBOXSYNC_SHARED_SECRET'] as $name) {
        $assert(SecretReference::normalize($name) === 'env:'.$name, 'documented env compatibility');
    }
    $assert(SecretReference::resolve('NETBOX_API_TOKEN') === 'sentinel-secret-value', 'bare env resolution');
    $assert(SecretReference::resolve('env:NETBOX_API_TOKEN') === 'sentinel-secret-value', 'explicit env resolution');
    $assert(SecretReference::normalize('env:NETBOXSYNC_SECRET_CUSTOM') === 'env:NETBOXSYNC_SECRET_CUSTOM', 'dedicated namespace');
    $fails(fn() => SecretReference::normalize('NETBOXSYNC_SECRETIVE'), 'prefix boundary');
    $fails(fn() => SecretReference::normalize('PATH'), 'unapproved environment');
    putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS=CUSTOM_TOKEN, SECOND_TOKEN');
    $assert(SecretReference::normalize('CUSTOM_TOKEN') === 'env:CUSTOM_TOKEN', 'administrator allowlist');
    foreach (['NETBOXSYNC_SECRET_DIR', 'NETBOXSYNC_ALLOWED_SECRET_ENV_VARS', 'NETBOXSYNC_ZABBIX_DB_PASSWORD'] as $reserved) {
        putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS='.$reserved);
        $fails(fn() => SecretReference::normalize($reserved), 'reserved environment');
    }
    putenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS');
    foreach (['env:BAD-NAME', 'file:../token', 'file:/tmp/token', 'file:C:\\token', 'file:a/b', 'file:a\\b', 'file:..', 'vault:secret/key', '{$NETBOX.TOKEN}'] as $invalid) {
        $fails(fn() => SecretReference::normalize($invalid), 'invalid reference '.$invalid);
    }
    putenv('NETBOXSYNC_ZABBIX_API_TOKEN');
    $fails(fn() => SecretReference::resolve('env:NETBOXSYNC_ZABBIX_API_TOKEN'), 'missing environment');
    putenv('NETBOXSYNC_ZABBIX_API_TOKEN=');
    $fails(fn() => SecretReference::resolve('env:NETBOXSYNC_ZABBIX_API_TOKEN'), 'empty environment');

    file_put_contents($root.'/token', "sentinel-secret-value\n");
    chmod($root.'/token', 0600);
    $assert(SecretReference::resolve('file:token') === 'sentinel-secret-value', 'protected file read');
    file_put_contents($root.'/token', 'rotated-secret');
    $assert(SecretReference::resolve('file:token') === 'rotated-secret', 'file rotation read afresh');
    $fails(fn() => SecretReference::resolve('file:absent'), 'missing file');
    file_put_contents($root.'/token', '');
    $fails(fn() => SecretReference::resolve('file:token'), 'empty file');
    file_put_contents($root.'/token', str_repeat('x', 65537));
    $fails(fn() => SecretReference::resolve('file:token'), 'oversized file');
    file_put_contents($root.'/token', "sentinel-secret-value\r\nInjected: value");
    $fails(fn() => SecretReference::resolve('file:token'), 'header injection refused');
    file_put_contents($root.'/token', 'file-token');
    if (DIRECTORY_SEPARATOR === '/') {
        symlink($outside, $root.'/escape');
        $fails(fn() => SecretReference::resolve('file:escape'), 'escaping symlink');
        symlink($root.'/token', $root.'/inside');
        $assert(SecretReference::resolve('file:inside') === 'file-token', 'confined symlink');
        chmod($root.'/token', 0660);
        $fails(fn() => SecretReference::resolve('file:token'), 'writable secret file');
        chmod($root.'/token', 0600);
        chmod($root, 0770);
        $fails(fn() => SecretReference::resolve('file:token'), 'writable secret directory');
        chmod($root, 0700);
    }
    putenv('NETBOXSYNC_SECRET_DIR=relative');
    $fails(fn() => SecretReference::resolve('file:token'), 'relative root refused');
    putenv('NETBOXSYNC_SECRET_DIR='.$root);

    $old = ['token' => 'stored-secret', 'token_env' => ''];
    $selected = Config::buildSecretSettings(['token' => '', 'token_env' => 'NETBOX_API_TOKEN'], $old);
    $assert($selected === ['token' => '', 'token_env' => 'env:NETBOX_API_TOKEN'], 'reference clears stored credential');
    $assert(Config::resolveSectionSecret($selected) === 'sentinel-secret-value', 'saved source resolves');
    $assert(Config::buildSecretSettings(['token' => 'file:token'], $old)['token_env'] === 'file:token', 'reference pasted in credential field');
    $fails(fn() => Config::buildSecretSettings(['token' => 'sentinel-secret-value', 'token_env' => 'env:NETBOX_API_TOKEN'], $old), 'conflicting input');
    $fails(fn() => Config::buildSecretSettings(['token' => '{$NETBOX.TOKEN}'], $old), 'native macro refused', 'not expanded');
    $assert(Config::buildSecretSettings(['token' => ''], $old)['token'] === 'stored-secret', 'blank preserves stored credential');
    $assert(Config::buildSecretSettings(['clear_token' => '1'], $old)['token'] === '', 'clear stored token');
    $assert(Config::buildSecretSettings(['token_env' => '', 'token' => 'replacement'], $selected)['token'] === 'replacement', 'switch reference to stored');
    $fails(fn() => Config::resolveSectionSecret(['token' => 'stale', 'token_env' => 'env:NETBOXSYNC_ZABBIX_API_TOKEN']), 'unavailable source never falls back');

    $config = Config::defaults();
    $config['netbox'] = array_replace($config['netbox'], ['enabled' => true, 'url' => 'https://netbox.example.test', 'token_env' => 'env:NETBOX_API_TOKEN']);
    $config['zabbix_api']['token_env'] = 'env:NETBOXSYNC_ZABBIX_API_TOKEN';
    $config['runner']['shared_secret_env'] = 'file:absent';
    $view = Config::sanitizeForView($config);
    $assert($view['netbox']['token'] === '' && $view['zabbix_api']['token'] === '' && $view['runner']['shared_secret'] === '', 'view never resolves references');
    $runtime = Config::sanitizeForRuntime($config, ['netbox']);
    $assert($runtime['netbox']['token'] === 'sentinel-secret-value', 'NetBox only runtime');
    $assert($runtime['zabbix_api']['token'] === '', 'unrelated credential unresolved');
    $engine = new SyncEngine($config);
    $assert($engine instanceof SyncEngine, 'manual sync independent of CLI-only credentials');
    $fails(fn() => ZabbixApiClient::fromConfig($config), 'CLI resolves its missing reference before connecting', 'unavailable');

    $posted = Config::buildFromPost($config, Config::defaults());
    $assert($posted['netbox']['token'] === '' && $posted['netbox']['token_env'] === 'env:NETBOX_API_TOKEN', 'full save stores only reference');
    $assert($posted['runner']['shared_secret_env'] === 'file:absent', 'save does not read CLI-only files');

    echo 'OK: '.$checks." secret-reference checks\n";
}
finally {
    // Only test-created, flat files under our random temporary directory.
    foreach (scandir($root) as $name) {
        if ($name !== '.' && $name !== '..') {
            unlink($root.'/'.$name);
        }
    }
    rmdir($root);
    unlink($outside);
}
