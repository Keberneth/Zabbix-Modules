<?php declare(strict_types = 1);

use Modules\AI\Lib\Config;
use Modules\AI\Lib\RedactionStore;
use Modules\AI\Lib\Redactor;

require_once __DIR__.'/../lib/bootstrap.php';

function checkPreset(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function presetConfig(array $enabled = []): array {
    $config = Config::defaults();
    $config['security']['enabled'] = true;
    $config['security']['strict_mode'] = true;
    foreach ($config['security']['categories'] as $name => $value) {
        if (is_bool($value)) {
            $config['security']['categories'][$name] = false;
        }
    }
    $config['security']['categories']['os_mode'] = 'off';
    foreach ($enabled as $name) {
        $config['security']['categories'][$name] = true;
    }
    return $config;
}

function assertPresetAbsent(string $text, array $originals, string $label): void {
    foreach ($originals as $original) {
        checkPreset(!str_contains($text, $original), $label.' exposed '.$original.'.');
    }
}

$defaults = Config::defaults()['security']['categories'];
foreach (['credentials' => true, 'emails' => true, 'accounts' => false,
    'mac_addresses' => false, 'asset_identifiers' => false, 'personal_paths' => false] as $key => $expected) {
    checkPreset(($defaults[$key] ?? null) === $expected, 'Unexpected default for '.$key.'.');
}

$fixtures = [
    'emails' => [
        'text' => 'Contact alice.smith+oncall@example.org or alice.smith+oncall@example.org.',
        'sensitive' => ['alice.smith', 'example.org']
    ],
    'accounts' => [
        'text' => 'DOMAIN\\alice; username=bob; login: carol; account: david; user=eric',
        'sensitive' => ['DOMAIN', 'alice', 'bob', 'carol', 'david', 'eric']
    ],
    'mac_addresses' => [
        'text' => 'NIC 00:1A:2B:3C:4D:5E; NIC 00-1A-2B-3C-4D-5E; NIC 001a.2b3c.4d5e',
        'restored' => 'NIC 00:1A:2B:3C:4D:5E; NIC 00:1A:2B:3C:4D:5E; NIC 00:1A:2B:3C:4D:5E',
        'sensitive' => ['00:1A:2B:3C:4D:5E', '00-1A-2B-3C-4D-5E', '001a.2b3c.4d5e']
    ],
    'asset_identifiers' => [
        'text' => 'serial=SN12345; asset_tag: ASSET-9876; '
            .'{"serialno_a":"INVENTORY-111","serialno_b":"INVENTORY-222","asset_tag":"ASSET-333"}',
        'sensitive' => ['SN12345', 'ASSET-9876', 'INVENTORY-111', 'INVENTORY-222', 'ASSET-333']
    ],
    'personal_paths' => [
        'text' => 'C:\\Users\\Alice\\Documents\\report.txt /home/bob/logs/app.log /Users/Carol/Desktop/report.txt',
        'sensitive' => ['Alice', 'bob', 'Carol']
    ]
];
$credential_text = 'password="PresetOnlySecret42!"; api_key="sk-proj-PresetOnlySecret00000000000000000000"';
$credential_originals = ['PresetOnlySecret42', 'sk-proj-PresetOnlySecret00000000000000000000'];
$channels = ['chat', 'webhook', 'action_reads', 'action_writes', 'action_formatting'];
$unchanged_controls = 'HTTP 503; error 0x80070005; errno=13; /var/log/zabbix /usr/bin '
    .'C:\\Windows\\System32 /Applications';

// Every preset follows the existing channel gate, and identity masking remains reversible.
foreach ($channels as $channel) {
    foreach ($fixtures as $category => $fixture) {
        $redactor = Redactor::forEphemeral(presetConfig([$category]));
        $masked = $redactor->redactText($fixture['text'], $channel);
        assertPresetAbsent($masked, $fixture['sensitive'], $category.' on '.$channel);
        checkPreset($redactor->restoreText($masked) === ($fixture['restored'] ?? $fixture['text']),
            $category.' did not restore on '.$channel.'.');
        checkPreset($redactor->redactText($fixture['text'], $channel) === $masked, $category.' aliases changed on repeat.');
        checkPreset($redactor->redactText($masked, $channel) === $masked, $category.' masked its own aliases.');
        checkPreset(Redactor::forEphemeral(presetConfig())->redactText($fixture['text'], $channel) === $fixture['text'],
            'Disabled '.$category.' still masked text.');

        $disabled_channel = presetConfig([$category]);
        $disabled_channel['security']['apply_to'][$channel] = false;
        checkPreset(Redactor::forEphemeral($disabled_channel)->redactText($fixture['text'], $channel) === $fixture['text'],
            $category.' ignored disabled channel '.$channel.'.');
    }

    $credentials = Redactor::forEphemeral(presetConfig(['credentials']));
    $masked = $credentials->redactText($credential_text, $channel);
    assertPresetAbsent($masked, $credential_originals, 'Credentials on '.$channel);
    assertPresetAbsent($credentials->restoreText($masked), $credential_originals, 'Credential restoration');
    checkPreset($credentials->mappingDetails() === [], 'Credentials created reversible mapping details.');
    checkPreset($credentials->redactText($masked, $channel) === $masked, 'Credential masking was not idempotent.');
    checkPreset(Redactor::forEphemeral(presetConfig())->redactText($credential_text, $channel) === $credential_text,
        'Disabled credentials still masked text.');
    $disabled_credentials_channel = presetConfig(['credentials']);
    $disabled_credentials_channel['security']['apply_to'][$channel] = false;
    checkPreset(Redactor::forEphemeral($disabled_credentials_channel)->redactText($credential_text, $channel) === $credential_text,
        'Credentials ignored disabled channel '.$channel.'.');
    checkPreset(Redactor::forEphemeral(presetConfig())->redactText($unchanged_controls, $channel) === $unchanged_controls,
        'Disabled presets changed error codes or standard paths.');
}

// Repeated complete addresses receive one stable alias, never a partly exposed local part.
$emails = Redactor::forEphemeral(presetConfig(['emails']));
$address = 'alice.smith+oncall@example.org';
$email_alias = $emails->redactText($address);
checkPreset($email_alias !== $address && !str_contains($email_alias, 'alice') && !str_contains($email_alias, 'example.org'),
    'Email address was only partially masked.');
checkPreset($emails->redactText($address.' '.$address) === $email_alias.' '.$email_alias,
    'Repeated email address did not use the same complete alias.');
checkPreset($emails->restoreText($email_alias) === $address, 'Complete email alias did not restore.');

// URL/email overlap must retain the protocol, query keys and diagnostic values.
foreach ([
    'https://portal.example.com/notify/alice@example.org?view=short',
    'https://portal.example.com/?email=alice@example.org&view=short',
    'https://portal.example.com?email=alice@example.org&view=short'
] as $url) {
    $url_redactor = Redactor::forEphemeral(presetConfig(['emails', 'urls', 'fqdns']));
    $masked_url = $url_redactor->redactText($url);
    checkPreset(strpos($masked_url, 'https://') === 0 && str_contains($masked_url, 'view=short'),
        'Email detection swallowed URL structure or diagnostic parameters.');
    checkPreset(!str_contains($masked_url, 'alice') && !str_contains($masked_url, 'example.org'),
        'URL email was not completely masked.');
    checkPreset($url_redactor->restoreText($masked_url) === $url, 'URL email aliases did not restore.');
}

$macs = Redactor::forEphemeral(presetConfig(['mac_addresses']));
$mac_alias = $macs->redactText('00:1A:2B:3C:4D:5E');
foreach (['00-1A-2B-3C-4D-5E', '001a.2b3c.4d5e'] as $variant) {
    checkPreset($macs->redactText($variant) === $mac_alias, 'Equivalent MAC formats did not share one alias.');
}
checkPreset($macs->redactText('00:1A:2B:3C:4D:5F') !== $mac_alias, 'Different MAC addresses shared one alias.');

$paths = Redactor::forEphemeral(presetConfig(['personal_paths']));
$masked_paths = $paths->redactText($fixtures['personal_paths']['text']);
foreach (['C:\\Users\\', '\\Documents\\report.txt', '/home/', '/logs/app.log', '/Users/', '/Desktop/report.txt'] as $visible) {
    checkPreset(str_contains($masked_paths, $visible), 'Personal path masking removed useful directory structure.');
}
checkPreset($paths->redactText('Alice explains the root cause; bob checks the service.') ===
    'Alice explains the root cause; bob checks the service.', 'Personal path state guessed identities outside paths.');

// The full pipeline must not turn a new alias into an IP/domain/hostname/custom-rule alias.
$all_categories = ['credentials', 'emails', 'accounts', 'mac_addresses', 'asset_identifiers', 'personal_paths',
    'ipv4', 'ipv6', 'hostnames', 'fqdns', 'urls'];
$combined_config = presetConfig($all_categories);
$combined_config['security']['custom_rules'] = [
    ['enabled' => true, 'type' => 'exact', 'match' => 'ProjectNebula', 'replace' => 'ai-project-001']
];
$combined = Redactor::forEphemeral($combined_config);
$combined_text = implode("\n", array_column($fixtures, 'text'))."\n"
    .'ProjectNebula db-prod-17 10.23.45.67 fd12:3456:789a::1 api.intranet.corp https://portal.intranet.corp/status';
$combined_masked = $combined->redactText($combined_text);
foreach ($fixtures as $fixture) {
    assertPresetAbsent($combined_masked, $fixture['sensitive'], 'Combined pipeline');
}
assertPresetAbsent($combined_masked,
    ['ProjectNebula', 'db-prod-17', '10.23.45.67', 'fd12:3456:789a::1', 'intranet.corp'], 'Combined existing masks');
$combined_restored = str_replace($fixtures['mac_addresses']['text'], $fixtures['mac_addresses']['restored'], $combined_text);
checkPreset($combined->restoreText($combined_masked) === $combined_restored, 'Combined aliases did not restore.');
foreach ($channels as $channel) {
    checkPreset($combined->redactText($combined_text, $channel) === $combined_masked,
        'Combined aliases changed across '.$channel.'.');
    checkPreset($combined->redactText($combined_masked, $channel) === $combined_masked,
        'Combined aliases were remasked on '.$channel.'.');
}

// Generic system paths and error codes are not personal identifiers even when the presets are on.
$identities = Redactor::forEphemeral(presetConfig(array_keys($fixtures)));
checkPreset($identities->redactText($unchanged_controls) === $unchanged_controls,
    'Identity presets changed error codes or standard paths.');

// Old session state has none of the new counters. New aliases must coexist with its existing mappings.
$temp_dir = sys_get_temp_dir().'/zabbix-ai-presets-'.bin2hex(random_bytes(6));
$persistent_config = $combined_config;
$persistent_config['security']['state_path'] = $temp_dir;
$old_state = [
    'forward' => ['10.23.45.67' => '192.0.2.1', 'api.intranet.corp' => 'ai-domain-001.invalid'],
    'reverse' => ['192.0.2.1' => '10.23.45.67', 'ai-domain-001.invalid' => 'api.intranet.corp'],
    'meta' => ['10.23.45.67' => ['type' => 'ipv4', 'alias' => '192.0.2.1'],
        'api.intranet.corp' => ['type' => 'fqdn', 'alias' => 'ai-domain-001.invalid']],
    'counters' => ['hostname' => 0, 'ipv4' => 1, 'ipv6' => 0, 'fqdn' => 1,
        'url' => 0, 'os' => 0, 'custom' => 0, 'service' => 0],
    'created_at' => time(), 'updated_at' => time()
];
// A prior custom mapping may contain a credential. Once detected, it must stop being reversible.
$legacy_secret = 'sk-proj-PresetOnlySecret00000000000000000000';
$old_state['forward'][$legacy_secret] = 'ai-legacy-secret-001';
$old_state['reverse']['ai-legacy-secret-001'] = $legacy_secret;
$old_state['meta'][$legacy_secret] = ['type' => 'custom', 'alias' => 'ai-legacy-secret-001'];
try {
    RedactionStore::save($persistent_config, 'preset-server', 'preset-chat', $old_state);
    $session = Redactor::forChatSession($persistent_config, 'preset-server', 'preset-chat');
    checkPreset($session->redactText('10.23.45.67 api.intranet.corp') === '192.0.2.1 ai-domain-001.invalid',
        'Legacy session mappings changed.');
    $first_alias = $session->redactText($address);
    $credential_overlap = 'password="ops.person@secret-tenant.corp"; '.$credential_text
        .'; repeated value: ops.person@secret-tenant.corp';
    $masked_secret = $session->redactText($credential_overlap);
    $never_store = array_merge($credential_originals, ['ops.person', 'secret-tenant.corp']);
    assertPresetAbsent($masked_secret, $never_store, 'Credential precedence');
    assertPresetAbsent($session->restoreText($masked_secret), $never_store, 'Persistent credential restoration');
    assertPresetAbsent($session->restoreText('ai-legacy-secret-001'), [$legacy_secret], 'Legacy credential restoration');
    assertPresetAbsent(json_encode($session->mappingDetails(1000), JSON_THROW_ON_ERROR), $never_store,
        'Credential mapping details');
    $session->save();

    $reloaded = Redactor::forChatSession($persistent_config, 'preset-server', 'preset-chat');
    checkPreset($reloaded->redactText($address, 'webhook') === $first_alias, 'Shared session email alias changed.');
    $second_alias = $reloaded->redactText('second.person@example.org', 'action_reads');
    checkPreset($second_alias !== $first_alias, 'Distinct emails reused the same alias after state load.');
    checkPreset($reloaded->restoreText($first_alias) === $address, 'Shared alias lost its original.');
    checkPreset($reloaded->redactText('10.23.45.67 api.intranet.corp') === '192.0.2.1 ai-domain-001.invalid',
        'New presets damaged old mappings after reload.');
    $reloaded->save();
    $files = glob($temp_dir.'/*.json') ?: [];
    checkPreset(count($files) === 1, 'Expected one saved session state.');
    foreach ($files as $path) {
        assertPresetAbsent((string) file_get_contents($path), $never_store, 'Saved redaction state');
    }
}
finally {
    foreach (glob($temp_dir.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($temp_dir)) {
        rmdir($temp_dir);
    }
}

echo "masking_presets: ok\n";
