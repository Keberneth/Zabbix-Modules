<?php declare(strict_types = 0);

// Offline, dependency-free checks; all credential values here are synthetic.
require_once __DIR__.'/../lib/CredentialMasker.php';

use Modules\AI\Lib\CredentialMasker;

$checks = 0;
function credentialCheck(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function credentialFixture(string $name, string $input, string $expected, array $removed = []): array {
    $result = CredentialMasker::redact($input);
    credentialCheck($result['text'] === $expected, $name.': unexpected masked output.');
    credentialCheck($result['count'] > 0, $name.': no masking recorded.');
    foreach ($removed as $secret) {
        credentialCheck(strpos($result['text'], $secret) === false, $name.': credential remained in output.');
        credentialCheck(in_array($secret, $result['secrets'], true), $name.': missing request-local purge candidate.');
    }
    $again = CredentialMasker::redact($result['text']);
    credentialCheck($again['text'] === $result['text'], $name.': masking is not idempotent.');
    credentialCheck($again['count'] === 0 && $again['secrets'] === [], $name.': marker became a new credential.');
    return $result;
}

credentialFixture('JSON', '{"password":"PresetOnlySecret42!","status":"failed","api_key":"FixtureApiKey42"}',
    '{"password":"[REDACTED]","status":"failed","api_key":"[REDACTED]"}', ['PresetOnlySecret42!', 'FixtureApiKey42']);
credentialFixture('Quoted log', 'request password="log secret 42; !" result=denied',
    'request password="[REDACTED]" result=denied', ['log secret 42; !']);
credentialFixture('Single quote', "service password='single secret 42' code=500",
    "service password='[REDACTED]' code=500", ['single secret 42']);
credentialFixture('Log key suffix', 'db.user=report db.password=FixtureDbSecret; status=denied',
    'db.user=report db.password=[REDACTED]; status=denied', ['FixtureDbSecret']);
credentialFixture('INI/env', "DB_PASSWORD=env secret,42;!\r\nPORT=5432\nexport CLIENT_SECRET=AnotherFixtureSecret\nSTATUS=ready",
    "DB_PASSWORD=[REDACTED]\r\nPORT=5432\nexport CLIENT_SECRET=[REDACTED]\nSTATUS=ready", ['env secret,42;!', 'AnotherFixtureSecret']);
$escaped_secret = 'fixture"secret\\tail';
credentialFixture('JSON escaped quote', json_encode(['password' => $escaped_secret, 'status' => 'denied'], JSON_THROW_ON_ERROR),
    '{"password":"[REDACTED]","status":"denied"}', [$escaped_secret]);
$inner = json_encode(['password' => 'nestedFixture"Secret', 'status' => 'ready'], JSON_THROW_ON_ERROR);
$encoded = json_encode(['message' => $inner], JSON_THROW_ON_ERROR);
$expected_inner = json_encode(['password' => '[REDACTED]', 'status' => 'ready'], JSON_THROW_ON_ERROR);
credentialFixture('Nested encoded JSON', $encoded, json_encode(['message' => $expected_inner], JSON_THROW_ON_ERROR));
credentialFixture('Unterminated quote', "password=\"truncatedFixture\nsecond private line",
    'password="[REDACTED]', ["truncatedFixture\nsecond private line"]);
credentialFixture('Empty field then newline', "password=\napi_token=NextLineFixture\nstatus=ready",
    "password=\napi_token=[REDACTED]\nstatus=ready", ['NextLineFixture']);
credentialFixture('Email credential', 'password="ops.person@secret-tenant.corp"',
    'password="[REDACTED]"', ['ops.person@secret-tenant.corp']);

credentialFixture('HTTP headers', "GET /health HTTP/1.1\r\nAuthorization: Bearer FixtureBearer42\r\nProxy-Authorization: Basic Zml4dHVyZTpzZWNyZXQ=\r\nX-Request-ID: request42",
    "GET /health HTTP/1.1\r\nAuthorization: [REDACTED]\r\nProxy-Authorization: [REDACTED]\r\nX-Request-ID: request42",
    ['FixtureBearer42', 'Zml4dHVyZTpzZWNyZXQ=']);
credentialFixture('Curl header', '> Authorization: Bearer CurlFixtureToken',
    '> Authorization: [REDACTED]', ['CurlFixtureToken']);
credentialFixture('Header dictionary', '{"Authorization":"Bearer DictionaryFixture42","Content-Type":"application/json"}',
    '{"Authorization":"[REDACTED]","Content-Type":"application/json"}', ['DictionaryFixture42']);
credentialFixture('Cookie', "Cookie: session=CookieFixture42; csrf=CookieCsrf42\nStatus: 401",
    "Cookie: [REDACTED]\nStatus: 401", ['CookieFixture42', 'CookieCsrf42']);
$set_cookie = credentialFixture('Set-Cookie', "Set-Cookie: session=SetCookieFixture42; Domain=internal.example; Path=/; Max-Age=3600; Secure; HttpOnly; SameSite=Lax\nStatus: 200",
    "Set-Cookie: [REDACTED]\nStatus: 200", ['SetCookieFixture42']);
foreach (['internal.example', '/', '3600', 'Lax'] as $attribute) {
    credentialCheck(!in_array($attribute, $set_cookie['secrets'], true), 'Set-Cookie attribute became a purge candidate.');
}
credentialFixture('JSON cookies', '{"Cookie":"session=JsonCookieFixture42; csrf=JsonCsrf42","ok":true}',
    '{"Cookie":"[REDACTED]","ok":true}', ['JsonCookieFixture42', 'JsonCsrf42']);

credentialFixture('URL query', 'https://service.example/api?api_key=QueryFixture42&limit=10&token_count=20#metrics',
    'https://service.example/api?api_key=[REDACTED]&limit=10&token_count=20#metrics', ['QueryFixture42']);
credentialFixture('Encoded query', 'https://service.example/api?api%5Fkey=Encoded%2FKey%2B42&region=eu&access_token=Another+Fixture%21',
    'https://service.example/api?api%5Fkey=[REDACTED]&region=eu&access_token=[REDACTED]',
    ['Encoded%2FKey%2B42', 'Encoded/Key+42', 'Another+Fixture%21', 'Another Fixture!']);
credentialFixture('HTML query', 'https://service.example/?limit=5&amp;api_key=HtmlFixture42&amp;region=eu',
    'https://service.example/?limit=5&amp;api_key=[REDACTED]&amp;region=eu', ['HtmlFixture42']);
credentialFixture('Signed URL', 'https://storage.example/a?X-Amz-Signature=SignatureFixture42&X-Amz-Date=20260101&X-Amz-Credential=UserFixture%2Fscope',
    'https://storage.example/a?X-Amz-Signature=[REDACTED]&X-Amz-Date=20260101&X-Amz-Credential=[REDACTED]',
    ['SignatureFixture42', 'UserFixture%2Fscope', 'UserFixture/scope']);
credentialFixture('URL userinfo', 'postgres://fixtureuser:Fixture%21Password@db.example:5432/app?sslmode=require',
    'postgres://[REDACTED]@db.example:5432/app?sslmode=require', ['fixtureuser:Fixture%21Password', 'Fixture%21Password', 'Fixture!Password']);
credentialFixture('URL userinfo JSON', '{"url":"https:\/\/fixture:EscapedUrlFixture42@service.example\/api?token=UrlTokenFixture42&limit=5"}',
    '{"url":"https:\/\/[REDACTED]@service.example\/api?token=[REDACTED]&limit=5"}', ['EscapedUrlFixture42', 'UrlTokenFixture42']);
credentialFixture('Query key boundaries', '?api_key_hint=help&api_key=BoundaryFixture42&notapi_key=keep&api_key_length=42',
    '?api_key_hint=help&api_key=[REDACTED]&notapi_key=keep&api_key_length=42', ['BoundaryFixture42']);

$base64url = static function(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
$jwt = $base64url('{"alg":"HS256","typ":"JWT"}').'.'.$base64url('{"sub":"fixture42"}').'.'.$base64url('fixture-signature');
credentialFixture('JWT', 'request carried '.$jwt.'; status=401', 'request carried [REDACTED]; status=401', [$jwt]);
$unsigned = $base64url('{"alg":"none"}').'.'.$base64url('{"sub":"fixture"}').'.';
credentialFixture('Unsigned JWT', 'token value '.$unsigned, 'token value [REDACTED]', [$unsigned]);
foreach (['sk-proj-'.str_repeat('A', 30), 'sk-ant-api03-'.str_repeat('B', 30), 'sk-'.str_repeat('C', 30),
        'ghp_'.str_repeat('D', 30), 'github_pat_'.str_repeat('E', 30), 'glpat-'.str_repeat('F', 30),
        'xoxb-123456789012-123456789012-'.str_repeat('G', 20)] as $index => $token) {
    credentialFixture('Vendor token '.$index, 'copied '.$token.'; status=denied', 'copied [REDACTED]; status=denied', [$token]);
}
foreach (['PRIVATE KEY', 'RSA PRIVATE KEY', 'EC PRIVATE KEY', 'DSA PRIVATE KEY', 'OPENSSH PRIVATE KEY', 'ENCRYPTED PRIVATE KEY'] as $label) {
    $pem = '-----BEGIN '.$label."-----\nRklYVFVSRV9QUklWQVRFX0tFWQ==\n".'-----END '.$label.'-----';
    credentialFixture($label, "before\n".$pem."\nafter", "before\n[REDACTED]\nafter", [$pem]);
}
$truncated = "-----BEGIN RSA PRIVATE KEY-----\nRklYVFVSRV9QUklWQVRFX0tFWQ==\ntruncated";
credentialFixture('Truncated PEM', "before\n".$truncated, "before\n[REDACTED]", [$truncated]);

$false_positives = [
    'password_length=12 password_policy=strong api_key_env=ENV_NAME token_count=42 max_tokens=500',
    'The token bucket has 42 requests; password failures=3; public key checksum=abc123',
    'key=item.key code=500 authorization_status=denied cookie_name=session secret_count=2',
    'https://service.example/?limit=50&token_count=7&api_key_hint=docs&key=diagnostic&code=200',
    'aabbccddeeff0011223344556677889900 server.internal.example 1.2.3 eyJub3QiOiJhbGci.abc.def',
    'sk-short sk-proj-test ghp_example github_pat_example glpat-test xoxb-test',
    "-----BEGIN PUBLIC KEY-----\nUHVibGljS2V5\n-----END PUBLIC KEY-----",
    "-----BEGIN CERTIFICATE-----\nQ2VydGlmaWNhdGU=\n-----END CERTIFICATE-----",
    'password="" api_key="[REDACTED]" Authorization: [REDACTED]',
    "non-secret binary: \xff\xfe\nstatus=ready"
];
foreach ($false_positives as $index => $text) {
    $result = CredentialMasker::redact($text);
    credentialCheck($result['text'] === $text && $result['count'] === 0 && $result['secrets'] === [],
        'False-positive fixture '.$index.' was changed.');
}

// A failed regex must stop the request with a generic message, never raw input.
$old_limit = ini_get('pcre.backtrack_limit');
try {
    ini_set('pcre.backtrack_limit', '1');
    $failed = false;
    try {
        CredentialMasker::redact('password="NeverIncludeThisFixtureInErrors42"');
    }
    catch (RuntimeException $exception) {
        $failed = $exception->getMessage() === 'Credential masking failed; the request was not sent.';
    }
    credentialCheck($failed, 'Regex failure did not fail closed with the generic error.');
}
finally {
    ini_set('pcre.backtrack_limit', $old_limit);
}

// Bound request-local extraction; do not return an incompletely scrubbed result.
$many = '';
for ($index = 0; $index < 4097; ++$index) {
    $many .= 'password="UniqueSyntheticFixture'.$index.'"'."\n";
}
$bounded = false;
try {
    CredentialMasker::redact($many);
}
catch (RuntimeException $exception) {
    $bounded = $exception->getMessage() === 'Credential masking failed; the request was not sent.';
}
credentialCheck($bounded, 'Credential extraction did not enforce its request-local bound.');

echo 'OK: '.$checks." credential masking checks\n";
