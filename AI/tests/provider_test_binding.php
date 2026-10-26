<?php declare(strict_types = 1);

// Exercise the settings controller's credential binding without a database,
// provider connection, web session, or saved configuration writes.
class CController {}
function DBselect(string $query): bool { return true; }
function DBfetch($result): array { return ['config' => json_encode($GLOBALS['saved_config'])]; }
function zbx_dbstr(string $value): string { return "'".$value."'"; }

require_once __DIR__.'/../actions/TestProvider.php';

use Modules\AI\Actions\TestProvider;
use Modules\AI\Lib\Config;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['saved_config'] = Config::defaults();
$GLOBALS['saved_config']['providers'] = [[
    'id' => 'provider-1', 'type' => 'openai_compatible',
    'endpoint' => 'https://original.example/v1',
    'verify_peer' => true, 'allow_insecure_http' => false,
    'api_key' => 'enc:v1:stored-key-fixture',
    'headers_json' => 'enc:v1:stored-headers-fixture'
]];
$base_post = [
    'id' => 'provider-1', 'type' => 'openai_compatible',
    'endpoint' => 'https://original.example/v1', 'model' => 'selected-model',
    'verify_peer' => '1', 'allow_insecure_http' => '0'
];
$build = new ReflectionMethod(TestProvider::class, 'buildProviderFromPost');
$controller = new TestProvider();
$_POST = $base_post;
$provider = $build->invoke($controller);
check($provider['api_key'] === 'enc:v1:stored-key-fixture', 'Unchanged provider lost its stored key.');
check($provider['headers_json'] === 'enc:v1:stored-headers-fixture', 'Unchanged provider lost its stored headers.');
check($provider['model'] === 'selected-model', 'Selected model was not carried to capability test.');

foreach ([
    ['endpoint' => 'https://changed.example/v1'],
    ['verify_peer' => '0'],
    ['allow_insecure_http' => '1'],
    ['type' => 'anthropic']
] as $change) {
    $_POST = array_replace($base_post, $change);
    $provider = $build->invoke($controller);
    check($provider['api_key'] === '' && $provider['headers_json'] === '', 'Modified routing/TLS/HTTP policy reused saved credentials.');
}

$GLOBALS['saved_config']['providers'][0]['api_key_env'] = 'env:SAVED_PROVIDER_KEY';
$_POST = array_replace($base_post, [
    'endpoint' => 'https://changed.example/v1', 'api_key_env' => 'env:SAVED_PROVIDER_KEY'
]);
try {
    $build->invoke($controller);
    throw new RuntimeException('Unsaved destination reused a stored key reference.');
}
catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'Save the provider destination') !== false, 'Unexpected binding failure.');
}

$_POST = array_replace($base_post, [
    'endpoint' => 'https://changed.example/v1', 'api_key' => 'fresh-test-key'
]);
$provider = $build->invoke($controller);
check($provider['api_key'] === 'fresh-test-key' && $provider['api_key_env'] === '', 'Fresh request-local key was not usable.');
check($provider['headers_json'] === '', 'Fresh key reused headers from the saved destination.');

$GLOBALS['saved_config']['providers'][0]['allow_insecure_http'] = true;
$_POST = array_replace($base_post, ['allow_insecure_http' => '1']);
$provider = $build->invoke($controller);
check($provider['allow_insecure_http'] === true, 'Saved explicit HTTP policy was not preserved.');
check($provider['api_key_env'] === 'env:SAVED_PROVIDER_KEY', 'Matching saved HTTP policy lost its reference.');
echo "Provider test binding tests passed.\n";
