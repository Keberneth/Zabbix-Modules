<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$config = $data['config'] ?? [];
$providers = is_array($config['providers'] ?? null) ? $config['providers'] : [];
$instructions = is_array($config['instructions'] ?? null) ? $config['instructions'] : [];
$reference_links = is_array($config['reference_links'] ?? null) ? $config['reference_links'] : [];
$custom_rules = is_array($config['security']['custom_rules'] ?? null) ? $config['security']['custom_rules'] : [];
$log_summary = $data['log_summary'] ?? [];
$permission_note = (string) ($data['permission_note'] ?? '');
$actions_catalog = is_array($data['actions_catalog'] ?? null) ? $data['actions_catalog'] : [];

$settings_save_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.settings.save')
    ->getUrl();

$test_provider_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.test.provider')
    ->getUrl();

$test_provider_csrf = CCsrfTokenHelper::get('ai.test.provider');
$test_netbox_csrf = CCsrfTokenHelper::get('ai.test.netbox');
$csrf_field_name = CCsrfTokenHelper::CSRF_TOKEN_NAME;

$test_netbox_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.test.netbox')
    ->getUrl();

$chat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat')
    ->getUrl();

$logs_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.logs')
    ->getUrl();

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $ai_theme = 'dark';
    }
}

$api_key_env_placeholder_map = [
    'openai_compatible' => 'env:OPENAI_API_KEY',
    'anthropic' => 'env:ANTHROPIC_API_KEY',
    'ollama' => ''
];

$render_provider_row = static function(array $provider = []) use ($h, $api_key_env_placeholder_map): string {
    ob_start();
    $id = $provider['id'] ?? '__ROW_ID__';
    $current_type = $provider['type'] ?? 'openai_compatible';
    $api_key_env_placeholder = $api_key_env_placeholder_map[$current_type] ?? 'env:OPENAI_API_KEY';
    $verify_peer = array_key_exists('verify_peer', $provider)
        ? !empty($provider['verify_peer'])
        : true;
    ?>
    <div class="ai-repeat-row ai-provider-row" data-row-type="provider">
        <input type="hidden" class="ai-row-id-field" name="providers[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-settings-grid">
            <div>
                <label class="ai-label"><?= $h(_('Name')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][name]" value="<?= $h($provider['name'] ?? '') ?>" placeholder="OpenAI / Ollama / Claude">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Type')) ?></label>
                <select class="ai-input ai-provider-type-select" name="providers[<?= $h($id) ?>][type]">
                    <?php foreach (['openai_compatible', 'ollama', 'anthropic'] as $type): ?>
                        <option value="<?= $h($type) ?>" <?= ($current_type === $type) ? 'selected' : '' ?>><?= $h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][enabled]" value="1" <?= !empty($provider['enabled']) ? 'checked' : '' ?>> <?= $h(_('Use this provider')) ?></label>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Endpoint')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][endpoint]" value="<?= $h($provider['endpoint'] ?? '') ?>" placeholder="Leave blank for default (OpenAI: api.openai.com, Ollama: localhost:11434, Anthropic: api.anthropic.com)">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Model')) ?></label>
                <input class="ai-input ai-provider-model-input" type="text" list="ai-models-<?= $h($id) ?>" name="providers[<?= $h($id) ?>][model]" value="<?= $h($provider['model'] ?? '') ?>" placeholder="gpt-4.1-mini / llama3.2 / claude-sonnet">
                <datalist id="ai-models-<?= $h($id) ?>" class="ai-provider-model-datalist"></datalist>
                <span class="ai-muted ai-provider-model-hint"></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Timeout (s)')) ?></label>
                <input class="ai-input" type="number" min="5" max="600" name="providers[<?= $h($id) ?>][timeout]" value="<?= $h($provider['timeout'] ?? 120) ?>">
                <span class="ai-muted"><?= $h(_('Increase for long multi-step reports (e.g. 180–240).')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Temperature')) ?></label>
                <input class="ai-input ai-provider-temperature-input" type="number" min="0" max="2" step="0.1" name="providers[<?= $h($id) ?>][temperature]" value="<?= $h(($provider['temperature'] ?? -1) >= 0 ? $provider['temperature'] : '') ?>" placeholder="Global default">
                <span class="ai-muted ai-provider-temperature-hint"><?= $h(_('Leave blank to use global chat temperature.')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Max tokens')) ?></label>
                <input class="ai-input" type="number" min="0" max="128000" step="1" name="providers[<?= $h($id) ?>][max_tokens]" value="<?= $h(($provider['max_tokens'] ?? 0) > 0 ? $provider['max_tokens'] : '') ?>" placeholder="Provider default">
                <span class="ai-muted"><?= $h(_('Leave blank for provider default (4096 for Anthropic). For Ollama this maps to num_predict (response cap), not the context window.')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Context window (Ollama)')) ?></label>
                <input class="ai-input" type="number" min="0" max="1048576" step="1024" name="providers[<?= $h($id) ?>][num_ctx]" value="<?= $h(($provider['num_ctx'] ?? 0) > 0 ? $provider['num_ctx'] : '') ?>" placeholder="16384">
                <span class="ai-muted"><?= $h(_('Ollama only. Sets num_ctx. Default 16384. Ollama\'s native default (2048) is often too small for the policy, conversation, evidence, and native tool schemas, so the model may stop selecting Zabbix tools.')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                <input type="hidden" name="providers[<?= $h($id) ?>][verify_peer]" value="0">
                <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][verify_peer]" value="1" <?= $verify_peer ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
            </div>
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('API key / secret (or reference)')) ?></label>
                <input class="ai-input" type="password" autocomplete="new-password" name="providers[<?= $h($id) ?>][api_key]" value="" placeholder="<?= !empty($provider['api_key_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                <div class="ai-inline-notes">
                    <?php if (!empty($provider['api_key_present'])): ?>
                        <span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span>
                    <?php endif; ?>
                    <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="providers[<?= $h($id) ?>][clear_api_key]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                </div>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Vault / secret reference')) ?></label>
                <input class="ai-input ai-provider-api-key-env" type="text" name="providers[<?= $h($id) ?>][api_key_env]" value="<?= $h($provider['api_key_env'] ?? '') ?>" placeholder="<?= $h($api_key_env_placeholder) ?>">
                <span class="ai-muted"><?= $h(_('Use env:NAME or file:NAME. file: names are confined to ZABBIX_AI_SECRET_DIR. Environment names must be standard module names, use the ZABBIX_AI_SECRET_* prefix, or be server-allowlisted. Clear this reference before saving a new inline key. Reference tests require a saved matching endpoint/type/TLS binding.')) ?></span>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Extra headers JSON (inline secret)')) ?></label>
                <textarea class="ai-textarea" rows="3" autocomplete="off" spellcheck="false" name="providers[<?= $h($id) ?>][headers_json]" placeholder="<?= !empty($provider['headers_json_present']) ? $h(_('Leave blank to keep the stored encrypted headers')) : $h('{"X-Custom-Header":"value"}') ?>"></textarea>
                <div class="ai-inline-notes">
                    <?php if (!empty($provider['headers_json_present'])): ?>
                        <span class="ai-muted"><?= $h(_('Stored inline headers exist.')) ?></span>
                    <?php endif; ?>
                    <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="providers[<?= $h($id) ?>][clear_headers_json]" value="1"> <?= $h(_('Clear stored headers')) ?></label>
                </div>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Extra headers vault / secret reference')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][headers_json_ref]" value="<?= $h($provider['headers_json_ref'] ?? '') ?>" placeholder="file:provider_headers_json">
                <span class="ai-muted"><?= $h(_('Optional alternative to storing the JSON above. The referenced value must contain the complete JSON object.')) ?></span>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn ai-test-provider" data-test-provider><?= $h(_('Test connection')) ?></button>
            <span class="ai-test-provider-status ai-muted" role="status" aria-live="polite"></span>
            <button type="button" class="btn ai-remove-row"><?= $h(_('Remove provider')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$render_instruction_row = static function(array $instruction = []) use ($h): string {
    ob_start();
    $id = $instruction['id'] ?? '__ROW_ID__';
    ?>
    <div class="ai-repeat-row" data-row-type="instruction">
        <input type="hidden" class="ai-row-id-field" name="instructions[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-settings-grid">
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('Title')) ?></label>
                <input class="ai-input" type="text" name="instructions[<?= $h($id) ?>][title]" value="<?= $h($instruction['title'] ?? '') ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="instructions[<?= $h($id) ?>][enabled]" value="1" <?= !empty($instruction['enabled']) ? 'checked' : '' ?>> <?= $h(_('Use this block')) ?></label>
            </div>
            <div>
                <label class="ai-label" title="<?= $h(_('When enabled, this instruction block is run through the security redactor before being sent to the model. Leave off for normal admin-authored policy text so words like \'first-line\' or example hostnames are not aliased.')) ?>"><?= $h(_('Sensitive')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="instructions[<?= $h($id) ?>][sensitive]" value="1" <?= !empty($instruction['sensitive']) ? 'checked' : '' ?>> <?= $h(_('Apply redaction to this block')) ?></label>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Instruction text')) ?></label>
                <textarea class="ai-textarea" rows="8" name="instructions[<?= $h($id) ?>][content]"><?= $h($instruction['content'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn ai-remove-row"><?= $h(_('Remove instruction')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$render_link_row = static function(array $link = []) use ($h): string {
    ob_start();
    $id = $link['id'] ?? '__ROW_ID__';
    ?>
    <div class="ai-repeat-row" data-row-type="reference_link">
        <input type="hidden" class="ai-row-id-field" name="reference_links[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-settings-grid">
            <div>
                <label class="ai-label"><?= $h(_('Title')) ?></label>
                <input class="ai-input" type="text" name="reference_links[<?= $h($id) ?>][title]" value="<?= $h($link['title'] ?? '') ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="reference_links[<?= $h($id) ?>][enabled]" value="1" <?= !empty($link['enabled']) ? 'checked' : '' ?>> <?= $h(_('Offer in responses')) ?></label>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('URL')) ?></label>
                <input class="ai-input" type="text" name="reference_links[<?= $h($id) ?>][url]" value="<?= $h($link['url'] ?? '') ?>" placeholder="https://runbooks.example.local/path">
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn ai-remove-row"><?= $h(_('Remove link')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$render_custom_rule_row = static function(array $rule = []) use ($h): string {
    ob_start();
    $id = $rule['id'] ?? '__ROW_ID__';
    ?>
    <div class="ai-repeat-row" data-row-type="custom_rule">
        <input type="hidden" class="ai-row-id-field" name="security[custom_rules][<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-settings-grid">
            <div>
                <label class="ai-label"><?= $h(_('Rule type')) ?></label>
                <select class="ai-input" name="security[custom_rules][<?= $h($id) ?>][type]">
                    <?php foreach (['exact', 'regex', 'domain_suffix'] as $type): ?>
                        <option value="<?= $h($type) ?>" <?= (($rule['type'] ?? 'exact') === $type) ? 'selected' : '' ?>><?= $h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[custom_rules][<?= $h($id) ?>][enabled]" value="1" <?= !empty($rule['enabled']) ? 'checked' : '' ?>> <?= $h(_('Apply rule')) ?></label>
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Match')) ?></label>
                <input class="ai-input" type="text" name="security[custom_rules][<?= $h($id) ?>][match]" value="<?= $h($rule['match'] ?? '') ?>" placeholder="contoso.com or \\bFortiGate\\b">
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Replace with')) ?></label>
                <input class="ai-input" type="text" name="security[custom_rules][<?= $h($id) ?>][replace]" value="<?= $h($rule['replace'] ?? '') ?>" placeholder="ai-domain.001 or FirewallPlatform">
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn ai-remove-row"><?= $h(_('Remove rule')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

ob_start();
?>
<div id="ai-settings-root" class="ai-page ai-settings-page" data-ai-theme="<?= $h($ai_theme) ?>" data-test-provider-url="<?= $h($test_provider_url) ?>" data-test-provider-csrf="<?= $h($test_provider_csrf) ?>" data-test-netbox-url="<?= $h($test_netbox_url) ?>" data-test-netbox-csrf="<?= $h($test_netbox_csrf) ?>" data-csrf-field-name="<?= $h($csrf_field_name) ?>">
    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('AI settings')) ?></h1>
            <p class="ai-muted">Configure providers, prompt policy, integrations, redaction, and local logging.</p>
        </div>
        <div class="ai-header-actions">
            <a class="btn" href="<?= $h($chat_url) ?>"><?= $h(_('Open chat')) ?></a>
            <a class="btn" href="<?= $h($logs_url) ?>"><?= $h(_('Open logs')) ?></a>
        </div>
    </div>

    <form id="ai-settings-form" method="post" action="<?= $h($settings_save_url) ?>" data-active-tab="providers" novalidate>
        <input type="hidden" name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" value="<?= $h(CCsrfTokenHelper::get('ai.settings.save')) ?>">

        <nav class="ai-settings-tabs" role="tablist" aria-label="<?= $h(_('Settings sections')) ?>">
            <button type="button" role="tab" class="ai-settings-tab is-active" data-tab="providers" aria-selected="true" tabindex="0"><?= $h(_('Providers')) ?></button>
            <button type="button" role="tab" class="ai-settings-tab" data-tab="enrichment" aria-selected="false" tabindex="-1"><?= $h(_('Enrichment')) ?></button>
            <button type="button" role="tab" class="ai-settings-tab" data-tab="zabbix" aria-selected="false" tabindex="-1"><?= $h(_('Zabbix')) ?></button>
            <button type="button" role="tab" class="ai-settings-tab" data-tab="chat" aria-selected="false" tabindex="-1"><?= $h(_('Chat')) ?></button>
            <button type="button" role="tab" class="ai-settings-tab" data-tab="security" aria-selected="false" tabindex="-1"><?= $h(_('Security')) ?></button>
        </nav>
        <noscript>
            <style>
                /* Without JS the tab bar can't switch, so fall back to one
                   long scrollable page with every section visible. */
                .ai-settings-tabs { display: none !important; }
                .ai-tab-section { display: block !important; }
            </style>
        </noscript>

        <section class="ai-card ai-tab-section" data-tab="providers">
            <div class="ai-section-header">
                <h2><?= $h(_('Providers')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-providers" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-providers" class="ai-faq-box">
                <p><strong>What is this?</strong> Providers are the AI services that answer your questions. You need at least one.</p>
                <p><strong>Provider types:</strong></p>
                <ul>
                    <li><strong>openai_compatible</strong> &mdash; OpenAI, Azure OpenAI, vLLM, LocalAI, or any <code>/chat/completions</code> endpoint</li>
                    <li><strong>ollama</strong> &mdash; Local or remote Ollama instances (e.g. <code>http://localhost:11434/api/chat</code>)</li>
                    <li><strong>anthropic</strong> &mdash; Anthropic Claude API (native Messages format)</li>
                </ul>
                <p><strong>Defaults:</strong> The chat default handles turns where Zabbix Actions are disabled. The Zabbix-actions default handles every action-enabled turn, even when the model ultimately makes no tool call. An operator's explicit provider choice overrides either default.</p>
                <p><strong>API keys:</strong> Prefer a vault/secret reference over storing the value directly. Use an allowed <code>env:NAME</code>, or let a local encrypted vault/deployment tool materialize a protected runtime file beneath <code>ZABBIX_AI_SECRET_DIR</code> and use <code>file:NAME</code>. Keep the database-encryption master key outside that directory.</p>
            </div>
            <p class="ai-muted">Supported provider types: openai_compatible, ollama, anthropic.</p>
            <?php $secret_storage = $config['secret_storage'] ?? ['available' => false, 'backend' => 'none']; ?>
            <?php $plaintext_secret_count = (int) ($secret_storage['plaintext_secret_count'] ?? 0); ?>
            <?php if (!empty($secret_storage['available']) && $plaintext_secret_count === 0): ?>
                <div class="ai-status ai-status-ok">
                    <strong><?= $h(_('Secret storage: encrypted at rest')) ?></strong>
                    <?= $h(sprintf(_('Inline API keys, custom provider headers, tokens, the webhook shared secret, and pending confirmed actions are protected with %1$s. Master-key source: %2$s. Keep the same key on every frontend node.'), (string) $secret_storage['backend'], (string) ($secret_storage['key_source'] ?? 'unknown'))) ?>
                </div>
            <?php elseif (!empty($secret_storage['available'])): ?>
                <div class="ai-warning">
                    <strong><?= $h(_('Encryption ready — plaintext migration pending')) ?></strong>
                    <?= $h(sprintf(_('%d inline secret value(s) are still stored as legacy plaintext. The key is ready, but the database is not fully protected until you click Save settings once or replace those values with env:/file: references.'), $plaintext_secret_count)) ?>
                </div>
            <?php elseif (!empty($secret_storage['plaintext_allowed'])): ?>
                <div class="ai-danger-notice" role="alert">
                    <p><strong><?= $h(_('Secret storage: unencrypted — compatibility mode active')) ?></strong></p>
                    <p><?= $h(!empty($secret_storage['has_key'])
                        ? sprintf(_('An encryption key is configured but no crypto backend is available in this PHP SAPI (backend: %s), and the warned compatibility option is in force, so nothing this module stores is encrypted at rest. Inline API keys, custom headers, tokens and the webhook shared secret are readable in the Zabbix database. Confirmed AI writes, sensitive reads and bulk previews do run, but their staged payloads — target IDs, parameters, macro and password values — are written unencrypted under the module state path. Install Sodium or OpenSSL for the serving PHP process to restore encryption at rest.'), (string) ($secret_storage['backend'] ?? 'none'))
                        : _('No encryption key is configured and the warned compatibility option is in force, so nothing this module stores is encrypted at rest. Inline API keys, custom headers, tokens and the webhook shared secret are readable in the Zabbix database. Confirmed AI writes, sensitive reads and bulk previews do run, but their staged payloads — target IDs, parameters, macro and password values — are written unencrypted under the module state path, and provider, Zabbix and NetBox confirmation identity digests are unkeyed instead of keyed HMACs.')) ?></p>
                    <?php if ($plaintext_secret_count > 0): ?>
                        <p><strong><?= $h(sprintf(_('%d existing inline secret value(s) are currently plaintext.'), $plaintext_secret_count)) ?></strong></p>
                    <?php endif; ?>
                    <p><?= $h(!empty($secret_storage['environment_plaintext_allowed'])
                        ? _('In force via the ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS server override.')
                        : _('In force via the settings option below.')) ?></p>
                    <p><?= $h(_('Use this only on an isolated development system. Recommended: set ZABBIX_AI_ENCRYPTION_KEY_FILE to a protected runtime credential file, then re-save this page to migrate legacy plaintext.')) ?></p>
                </div>
            <?php else: ?>
                <div class="ai-warning">
                    <strong><?= $h(_('Secret storage: encryption required')) ?></strong>
                    <?= $h(_('Without the explicitly warned compatibility option below, stored inline API keys, custom headers, tokens and webhook secrets cannot be saved or later used, and confirmed AI writes, sensitive reads and bulk previews cannot be staged. A freshly typed connection-test credential is request-local and is never persisted.')) ?>
                    <?php if ($plaintext_secret_count > 0): ?>
                        <br><strong><?= $h(sprintf(_('%d existing inline secret value(s) are currently plaintext.'), $plaintext_secret_count)) ?></strong>
                    <?php endif; ?>
                    <br><br>
                    <?= $h(_('Recommended: set ZABBIX_AI_ENCRYPTION_KEY_FILE to a protected runtime credential file, then re-save this page to migrate legacy plaintext. Direct ZABBIX_AI_ENCRYPTION_KEY remains supported. For provider keys and tokens, env:NAME or file:NAME references keep the values out of the database entirely.')) ?>
                </div>
            <?php endif; ?>
            <div class="ai-danger-notice" id="ai-plaintext-secret-option" role="alert">
                <p><strong><?= $h(_('Unsafe compatibility mode — isolated development only')) ?></strong></p>
                <label class="ai-checkbox ai-checkbox-danger">
                    <input
                        id="ai-allow-plaintext-secrets"
                        type="checkbox"
                        name="secret_storage[allow_plaintext_secrets]"
                        value="1"
                        data-initially-enabled="<?= !empty($secret_storage['configured_plaintext_allowed']) ? '1' : '0' ?>"
                        <?= !empty($secret_storage['configured_plaintext_allowed']) ? 'checked' : '' ?>
                    >
                    <?= $h(_('Allow inline secrets to be read and saved as plaintext when encryption is unavailable')) ?>
                </label>
                <p><?= $h(_('If enabled, provider keys/headers, Zabbix and NetBox tokens, and the webhook secret may be readable in the Zabbix database, database dumps, backups, and configuration exports. It also lets pending writes, sensitive-read confirmations and bulk previews run with no encryption key: their staged payloads are written unencrypted under the module state path, and confirmation identity digests become unkeyed hashes instead of keyed HMACs. Prefer env:/file: references.')) ?></p>
                <p><?= $h(_('This is the settings-managed equivalent of ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS=1 for module-config credentials; it does not modify the PHP process environment.')) ?></p>
                <?php if (!empty($secret_storage['plaintext_allowed'])): ?>
                    <p><strong><?= $h(_('Compatibility mode is active. Inline module credentials, and any pending write, sensitive-read or bulk-preview payload staged while no key is configured, remain unencrypted until you disable every override and save with encryption available.')) ?></strong></p>
                <?php endif; ?>
                <div id="ai-plaintext-risk-ack" style="<?= !empty($secret_storage['configured_plaintext_allowed']) ? 'display:none;' : '' ?>">
                    <label class="ai-checkbox ai-checkbox-danger">
                        <input id="ai-plaintext-risk-acknowledged" type="checkbox" name="secret_storage[plaintext_risk_acknowledged]" value="1">
                        <?= $h(_('I understand that this exposes configured credentials and staged confirmed-action payloads in plaintext at rest.')) ?>
                    </label>
                    <p class="ai-muted"><?= $h(_('This acknowledgment is one-time and is not stored. After a successful save, only the “Allow inline secrets…” option remains enabled.')) ?></p>
                </div>
                <?php if (!empty($secret_storage['environment_plaintext_allowed'])): ?>
                    <p><strong><?= $h(_('Server override active: ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS is enabled. It cannot be disabled from this page.')) ?></strong></p>
                <?php endif; ?>
            </div>
            <div class="ai-defaults-block">
                <h3 class="ai-defaults-heading"><?= $h(_('Default providers')) ?></h3>
                <p class="ai-muted ai-defaults-subhead"><?= $h(_('Pick which provider receives each context. The Zabbix-actions default receives every action-enabled chat turn; "Auto" uses the first enabled provider.')) ?></p>
                <div class="ai-repeat-grid ai-settings-grid">
                    <div>
                        <label class="ai-label"><?= $h(_('Default for chat')) ?></label>
                        <select class="ai-input" name="default_chat_provider_id">
                            <option value=""><?= $h(_('Auto')) ?></option>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?= $h($provider['id'] ?? '') ?>" <?= (($config['default_chat_provider_id'] ?? '') === ($provider['id'] ?? '')) ? 'selected' : '' ?>><?= $h($provider['name'] ?? $provider['id'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="ai-label"><?= $h(_('Default for webhook')) ?></label>
                        <select class="ai-input" name="default_webhook_provider_id">
                            <option value=""><?= $h(_('Auto')) ?></option>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?= $h($provider['id'] ?? '') ?>" <?= (($config['default_webhook_provider_id'] ?? '') === ($provider['id'] ?? '')) ? 'selected' : '' ?>><?= $h($provider['name'] ?? $provider['id'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="ai-label"><?= $h(_('Default for Zabbix actions')) ?></label>
                        <select class="ai-input" name="default_actions_provider_id">
                            <option value=""><?= $h(_('Auto')) ?></option>
                            <?php foreach ($providers as $provider): ?>
                                <option value="<?= $h($provider['id'] ?? '') ?>" <?= (($config['default_actions_provider_id'] ?? '') === ($provider['id'] ?? '')) ? 'selected' : '' ?>><?= $h($provider['name'] ?? $provider['id'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div id="ai-providers-list" class="ai-repeat-list">
                <?php foreach ($providers as $provider): ?>
                    <?= $render_provider_row($provider) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" data-add-row="provider"><?= $h(_('Add provider')) ?></button>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="enrichment">
            <div class="ai-section-header">
                <h2><?= $h(_('Global instructions')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-instructions" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-instructions" class="ai-faq-box">
                <p><strong>What is this?</strong> Instruction blocks are added to the AI system prompt. They define how the AI should behave, what rules to follow, and what style to use.</p>
                <p><strong>Default policy:</strong> A first-line troubleshooting policy is included by default. It tells the AI to never restart services, use safe checks only, and always include verification steps.</p>
                <p><strong>Tips:</strong> You can add multiple instruction blocks. Each can be enabled/disabled independently. Use this to enforce team-specific policies without editing the default.</p>
            </div>
            <div id="ai-instructions-list" class="ai-repeat-list">
                <?php foreach ($instructions as $instruction): ?>
                    <?= $render_instruction_row($instruction) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" data-add-row="instruction"><?= $h(_('Add instruction')) ?></button>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="enrichment">
            <div class="ai-section-header">
                <h2><?= $h(_('Reference links')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-links" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-links" class="ai-faq-box">
                <p><strong>What is this?</strong> URLs the AI can suggest to operators when relevant. For example, internal runbooks, wiki pages, or dashboards.</p>
                <p>The AI sees enabled links exactly as written in its system prompt and may suggest them when useful. They are not passed through URL redaction, so never include credentials, signed secrets, tokens, or passwords in a link.</p>
            </div>
            <div id="ai-reference-links-list" class="ai-repeat-list">
                <?php foreach ($reference_links as $link): ?>
                    <?= $render_link_row($link) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" data-add-row="reference_link"><?= $h(_('Add link')) ?></button>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="zabbix">
            <div class="ai-section-header">
                <h2><?= $h(_('Zabbix API')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-zabbix-api" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-zabbix-api" class="ai-faq-box">
                <p><strong>What is this?</strong> The module uses the Zabbix frontend internal API for logged-in chat/problem-page actions. The HTTP API URL/token is used for webhook/standalone automation. Interactive reads fail closed if the caller's frontend identity is unavailable unless the split-deployment fallback below is explicitly enabled. Module write gates (read/readwrite mode, per-category write permissions, "Require Super Admin for write") apply on both transports.</p>
                <p><strong>API URL:</strong> Usually <code>https://your-zabbix/api_jsonrpc.php</code>. An explicit HTTPS URL is required whenever a service token is configured. This must point to the Zabbix web frontend, not the Zabbix server daemon.</p>
                <p><strong>Auth mode:</strong> (only used when the HTTP transport is taken — webhook, standalone, or fallback)</p>
                <ul>
                    <li><strong>bearer</strong> &mdash; Zabbix 6.4+ API token in Authorization header (recommended)</li>
                    <li><strong>auto</strong> &mdash; tries Bearer first and retries only read-only <code>*.get</code> calls with the legacy auth field after an explicit authentication rejection</li>
                    <li><strong>legacy_auth_field</strong> &mdash; token sent in JSON auth field (older Zabbix versions)</li>
                </ul>
                <p><strong>Token permissions:</strong> For webhook/standalone/fallback use, the API token needs read access for read actions and write access for permitted write actions. Logged-in chat actions additionally inherit the current frontend user's Zabbix permissions.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('API URL')) ?></label>
                    <input class="ai-input" type="text" name="zabbix_api[url]" value="<?= $h($config['zabbix_api']['url'] ?? '') ?>" placeholder="https://zabbix.example.local/api_jsonrpc.php">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Auth mode')) ?></label>
                    <select class="ai-input" name="zabbix_api[auth_mode]">
                        <?php foreach (['bearer', 'auto', 'legacy_auth_field'] as $auth_mode): ?>
                            <option value="<?= $h($auth_mode) ?>" <?= (($config['zabbix_api']['auth_mode'] ?? 'bearer') === $auth_mode) ? 'selected' : '' ?>><?= $h($auth_mode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Timeout')) ?></label>
                    <input class="ai-input" type="number" min="3" max="300" name="zabbix_api[timeout]" value="<?= $h($config['zabbix_api']['timeout'] ?? 15) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_api[verify_peer]" value="1" <?= !empty($config['zabbix_api']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('API token')) ?></label>
                    <input class="ai-input" type="password" autocomplete="new-password" name="zabbix_api[token]" value="" placeholder="<?= !empty($config['zabbix_api']['token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['zabbix_api']['token_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored token exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="zabbix_api[clear_token]" value="1"> <?= $h(_('Clear stored token')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Token vault / secret reference')) ?></label>
                    <input class="ai-input" type="text" name="zabbix_api[token_env]" value="<?= $h($config['zabbix_api']['token_env'] ?? '') ?>" placeholder="env:ZABBIX_API_TOKEN">
                    <span class="ai-muted"><?= $h(_('Use env:NAME or file:NAME; setting a reference removes any stored inline token on save. Clear the reference before switching back to inline.')) ?></span>
                </div>
                <div class="ai-span-3">
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_api[allow_service_token_read_fallback]" value="1" <?= !empty($config['zabbix_api']['allow_service_token_read_fallback']) ? 'checked' : '' ?>> <?= $h(_('Allow interactive reads to use the shared service token when the caller\'s frontend API identity is unavailable (split deployments only)')) ?></label>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" id="ai-netbox-section" data-test-scope="netbox" data-tab="enrichment">
            <div class="ai-section-header">
                <h2><?= $h(_('NetBox')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-netbox" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-netbox" class="ai-faq-box">
                <p><strong>What is this?</strong> Optional NetBox/CMDB integration. Interactive NetBox data is retrieved only through a confirmed sensitive-read tool; webhook automation can add it as explicitly configured context.</p>
                <p><strong>When to use:</strong> If your team uses NetBox as a source of truth for infrastructure data and you want the AI to include that context in troubleshooting answers.</p>
                <p><strong>Interactive scope:</strong> NetBox tool results require operator confirmation and are limited to exact hostnames visible through the current Zabbix API identity. A hostname that cannot be resolved in that scope returns no NetBox data.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="netbox[enabled]" value="1" <?= !empty($config['netbox']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Enable NetBox enrichment')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Timeout')) ?></label>
                    <input class="ai-input" type="number" min="3" max="300" name="netbox[timeout]" value="<?= $h($config['netbox']['timeout'] ?? 10) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                    <input type="hidden" name="netbox[verify_peer]" value="0">
                    <label class="ai-checkbox"><input type="checkbox" name="netbox[verify_peer]" value="1" <?= !empty($config['netbox']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('NetBox URL')) ?></label>
                    <input class="ai-input" type="text" name="netbox[url]" value="<?= $h($config['netbox']['url'] ?? '') ?>" placeholder="https://netbox.example.local">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('NetBox token')) ?></label>
                    <input class="ai-input" type="password" autocomplete="new-password" name="netbox[token]" value="" placeholder="<?= !empty($config['netbox']['token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['netbox']['token_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored token exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="netbox[clear_token]" value="1"> <?= $h(_('Clear stored token')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Token vault / secret reference')) ?></label>
                    <input class="ai-input" type="text" name="netbox[token_env]" value="<?= $h($config['netbox']['token_env'] ?? '') ?>" placeholder="env:NETBOX_TOKEN">
                    <span class="ai-muted"><?= $h(_('Use env:NAME or file:NAME; setting a reference removes any stored inline token on save. Clear it before switching back to inline; save URL/TLS/reference changes before testing them.')) ?></span>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Enrichment behaviour')) ?></label>
                    <span class="ai-muted" style="display:block;margin:4px 0 8px 24px;">
                        <?= $h(_('Interactive NetBox records are retrieved only after an operator confirms a sensitive-read tool. Automatic webhook enrichment is controlled by the single "Include NetBox" setting in the Webhook section.')) ?>
                    </span>
                </div>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn ai-test-netbox" data-test-netbox><?= $h(_('Test connection')) ?></button>
                <span class="ai-test-netbox-status ai-muted" role="status" aria-live="polite"></span>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="zabbix">
            <div class="ai-section-header">
                <h2><?= $h(_('Webhook')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-webhook" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-webhook" class="ai-faq-box">
                <p><strong>What is this?</strong> The webhook lets Zabbix send problem events to the AI automatically. The AI generates first-line troubleshooting guidance and can post it back as a problem update comment.</p>
                <p><strong>Webhook URL:</strong> <code>https://your-zabbix/ai-webhook</code> (configure the web-server mapping in <code>INSTALL.md</code>)</p>
                <p><strong>Shared secret:</strong> Protects the webhook from unauthorized access. Set the same secret in the Zabbix media type and here.</p>
                <p><strong>Settings:</strong></p>
                <ul>
                    <li><strong>Post update back to event</strong> &mdash; AI answer is added as a problem comment in Zabbix</li>
                    <li><strong>Skip resolved</strong> &mdash; ignore events that are already resolved</li>
                    <li><strong>Comment action code</strong> &mdash; Zabbix problem_update action bitmask (4 = add message)</li>
                    <li><strong>Comment chunk size</strong> &mdash; max characters per comment chunk (Zabbix has a limit)</li>
                </ul>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[enabled]" value="1" <?= !empty($config['webhook']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Enable webhook handling')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Post update back to event')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[add_problem_update]" value="1" <?= !empty($config['webhook']['add_problem_update']) ? 'checked' : '' ?>> <?= $h(_('Add problem update comment')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Skip resolved')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[skip_resolved]" value="1" <?= !empty($config['webhook']['skip_resolved']) ? 'checked' : '' ?>> <?= $h(_('Ignore resolved events')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Include NetBox')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_netbox]" value="1" <?= !empty($config['webhook']['include_netbox']) ? 'checked' : '' ?>> <?= $h(_('Include the affected host\'s NetBox record in automated webhook prompts')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Include OS hint')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_os_hint]" value="1" <?= !empty($config['webhook']['include_os_hint']) ? 'checked' : '' ?>> <?= $h(_('Look up OS from Zabbix')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment action code')) ?></label>
                    <?php // Normalize legacy free-form values with the same mask the runtime applies. ?>
                    <?php $ack_current = ((int) ($config['webhook']['problem_update_action'] ?? 4) & 7) | 4; ?>
                    <select class="ai-input" name="webhook[problem_update_action]">
                        <?php foreach ([4 => _('Comment only'), 6 => _('Comment + acknowledge'), 5 => _('Comment + close'), 7 => _('Comment + acknowledge + close')] as $ack_code => $ack_label): ?>
                            <option value="<?= $h($ack_code) ?>" <?= ($ack_current === $ack_code) ? 'selected' : '' ?>><?= $h($ack_label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment chunk size')) ?></label>
                    <input class="ai-input" type="number" min="200" max="2000" name="webhook[comment_chunk_size]" value="<?= $h($config['webhook']['comment_chunk_size'] ?? 1900) ?>">
                </div>
                <div class="ai-span-3">
                    <label class="ai-label">
                        <?= $h(_('Require shared secret')) ?>
                        <span class="ai-recommended-badge" title="<?= $h(_('Strongly recommended when the webhook is enabled.')) ?>"><?= $h(_('Recommended')) ?></span>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-webhook-require-secret" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[require_secret]" value="1" <?= !empty($config['webhook']['require_secret']) ? 'checked' : '' ?>> <?= $h(_('Reject webhook calls that have no valid shared secret')) ?></label>
                    <div id="faq-webhook-require-secret" class="ai-faq-box">
                        <p><strong><?= $h(_('Why this matters:')) ?></strong> <?= $h(_('The standalone /ai-webhook endpoint has no Zabbix frontend session authentication because it is called by a machine. Authentication therefore relies on the shared secret and any upstream network controls.')) ?></p>
                        <p><?= $h(_('If the webhook is enabled but no shared secret is configured, the endpoint is UNAUTHENTICATED: any host that can reach the URL could trigger AI calls and — if "Post update back to event" is on — post AI-generated comments onto your Zabbix events.')) ?></p>
                        <p><?= $h(_('This is enabled by default. Requests with a missing or invalid secret (including the case where no secret is configured at all) are rejected and each rejection is logged with the source IP. Untick it only if you deliberately allow unauthenticated access (e.g. the endpoint is already protected by an upstream proxy or network ACL).')) ?></p>
                    </div>
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Shared secret')) ?></label>
                    <input class="ai-input" type="password" autocomplete="new-password" name="webhook[shared_secret]" value="" placeholder="<?= !empty($config['webhook']['shared_secret_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['webhook']['shared_secret_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="webhook[clear_shared_secret]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Shared-secret vault / secret reference')) ?></label>
                    <input class="ai-input" type="text" name="webhook[shared_secret_env]" value="<?= $h($config['webhook']['shared_secret_env'] ?? '') ?>" placeholder="env:AI_WEBHOOK_SECRET">
                    <span class="ai-muted"><?= $h(_('Use env:NAME or file:NAME; setting a reference removes any stored inline secret on save. Clear the reference before switching back to inline.')) ?></span>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="chat">
            <div class="ai-section-header">
                <h2><?= $h(_('Chat')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-chat" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-chat" class="ai-faq-box">
                <p><strong>What is this?</strong> Controls for the chat page behavior.</p>
                <ul>
                    <li><strong>Max history messages</strong> &mdash; How many previous messages are sent to the AI for context. Higher = more context but slower and more tokens. Default 12.</li>
                    <li><strong>Temperature</strong> &mdash; Controls AI randomness. 0 = deterministic, 1 = creative, 2 = very random. Default 1 (matches OpenAI's default; some newer models like GPT-5 only accept 1). Can be overridden per provider.</li>
                    <li><strong>Item history period</strong> &mdash; How far back to fetch item history when the "Include history" button is clicked. Default 24 hours.</li>
                    <li><strong>Item history max rows</strong> &mdash; Maximum number of data points per item to include. Default 50.</li>
                </ul>
                <p>Chat history is stored in your browser only (sessionStorage). Nothing is saved server-side.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Max history messages')) ?></label>
                    <input class="ai-input" type="number" min="0" max="50" name="chat[max_history_messages]" value="<?= $h($config['chat']['max_history_messages'] ?? 12) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Temperature')) ?></label>
                    <input class="ai-input" type="number" min="0" max="2" step="0.1" name="chat[temperature]" value="<?= $h($config['chat']['temperature'] ?? 1.0) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Item history period (hours)')) ?></label>
                    <input class="ai-input" type="number" min="1" max="720" name="chat[item_history_period_hours]" value="<?= $h($config['chat']['item_history_period_hours'] ?? 24) ?>">
                    <span class="ai-muted"><?= $h(_('How far back to fetch when "Include history" is clicked.')) ?></span>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Item history max rows')) ?></label>
                    <input class="ai-input" type="number" min="5" max="500" name="chat[item_history_max_rows]" value="<?= $h($config['chat']['item_history_max_rows'] ?? 50) ?>">
                    <span class="ai-muted"><?= $h(_('Max data points per item.')) ?></span>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="chat">
            <div class="ai-section-header">
                <h2><?= $h(_('Problem page integration')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-problem-inline" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-problem-inline" class="ai-faq-box">
                <p><strong>What is this?</strong> Controls the AI button that appears next to problems on the Problems page.</p>
                <ul>
                    <li><strong>Auto-analyze</strong> &mdash; When enabled, the AI drawer automatically sends a starter analysis prompt when opened. Disable this if you prefer to type your own first message.</li>
                </ul>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Auto-analyze on open')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="problem_inline[auto_analyze]" value="1" <?= !empty($config['problem_inline']['auto_analyze']) ? 'checked' : '' ?>> <?= $h(_('Automatically start AI analysis when drawer opens')) ?></label>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="security">
            <div class="ai-section-header">
                <h2><?= $h(_('Security / redaction')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-security" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-security" class="ai-faq-box">
                <p><strong>What is this?</strong> Replaces sensitive values (hostnames, IPs, domains, URLs, OS names) with safe aliases before sending data to the AI provider. When the AI responds, aliases are restored locally so you see the real values.</p>
                <p><strong>How it works:</strong> <code>prd-web-001</code> becomes <code>ai-host-001</code> outbound. The AI works with the alias. When the reply comes back, <code>ai-host-001</code> is replaced with <code>prd-web-001</code> before you see it.</p>
                <p><strong>Configuration/history assistant:</strong> Before sending its displayed form/API context, the drawer asks for explicit consent. That context may include preprocessing code, interface addresses, item/history values, triggers, non-secret macro values and recent problems. Secret/vault macros are masked. Untrusted-data fencing prevents model instructions from being taken from this data, but does not itself redact it.</p>
                <p><strong>Settings:</strong></p>
                <ul>
                    <li><strong>Strict mode</strong> &mdash; blocks requests if a known sensitive value was not fully masked. Safer but may need tuning.</li>
                    <li><strong>Apply masking on</strong> &mdash; choose which channels get redaction (chat, webhook, action results)</li>
                    <li><strong>Categories</strong> &mdash; pick what types of data to mask</li>
                    <li><strong>OS handling</strong> &mdash; <code>off</code> = no OS masking, <code>family_only</code> = "Windows Server 2022" becomes "ai-windows-family-001", <code>full_alias</code> = generic "ai-os-001"</li>
                    <li><strong>Custom rules</strong> &mdash; replace specific words, domains, or regex patterns (e.g. replace <code>contoso.com</code> with <code>ai-domain.001</code>)</li>
                </ul>
                <p><strong>State path:</strong> Alias mappings are stored as files so they persist across messages in the same chat session. The web server must be able to write here.</p>
                <p><strong>Setup commands (run as root):</strong></p>
<pre># Set WEB_GROUP to the actual php-fpm worker user.
# On RHEL/Alma/Rocky with nginx + php-fpm this is usually "apache",
# not "nginx". Confirm with: ps -eo user,comm | grep php-fpm
WEB_GROUP=apache   # or: nginx, www-data

# Using persistent path (recommended):
install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state
install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state/pending

# SELinux (RHEL/CentOS):
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
restorecon -Rv /var/lib/zabbix-ai

# Verify the worker can both write AND read back:
sudo -u $WEB_GROUP sh -c 'echo t &gt; /var/lib/zabbix-ai/state/.t \
  &amp;&amp; cat /var/lib/zabbix-ai/state/.t &gt; /dev/null \
  &amp;&amp; rm /var/lib/zabbix-ai/state/.t' &amp;&amp; echo "State: OK"</pre>
                <p>Then set "Local state path" above to <code>/var/lib/zabbix-ai/state</code></p>
            </div>
            <p class="ai-muted">Mask sensitive values before sending text to the AI and restore aliases locally when replies come back.</p>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enable redaction')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="security[enabled]" value="1" <?= !empty($config['security']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Mask sensitive data outbound')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Strict mode')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="security[strict_mode]" value="1" <?= !empty($config['security']['strict_mode']) ? 'checked' : '' ?>> <?= $h(_('Block requests if known sensitive values remain')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Local state retention (hours)')) ?></label>
                    <input class="ai-input" type="number" min="1" max="720" name="security[session_ttl_hours]" value="<?= $h($config['security']['session_ttl_hours'] ?? 12) ?>">
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Local state path')) ?></label>
                    <input class="ai-input" type="text" name="security[state_path]" value="<?= $h($config['security']['state_path'] ?? '/tmp/zabbix-ai-module/state') ?>">
                </div>
            </div>

            <h3><?= $h(_('Apply masking on')) ?></h3>
            <div class="ai-check-grid">
                <label class="ai-checkbox"><input type="checkbox" name="security[apply_to][chat]" value="1" <?= !empty($config['security']['apply_to']['chat']) ? 'checked' : '' ?>> <?= $h(_('Chat requests')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[apply_to][webhook]" value="1" <?= !empty($config['security']['apply_to']['webhook']) ? 'checked' : '' ?>> <?= $h(_('Webhook requests')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[apply_to][action_reads]" value="1" <?= !empty($config['security']['apply_to']['action_reads']) ? 'checked' : '' ?>> <?= $h(_('Read action results')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[apply_to][action_writes]" value="1" <?= !empty($config['security']['apply_to']['action_writes']) ? 'checked' : '' ?>> <?= $h(_('Write action confirmations')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[apply_to][action_formatting]" value="1" <?= !empty($config['security']['apply_to']['action_formatting']) ? 'checked' : '' ?>> <?= $h(_('Action result formatting')) ?></label>
            </div>

            <h3><?= $h(_('Replace these categories before send')) ?></h3>
            <div class="ai-check-grid">
                <label class="ai-checkbox" title="<?= $h(_('Fetch the host list from Zabbix and replace every real hostname (and identifier-like substrings of one, e.g. db-01 inside prd-db-01) with a stable ai-host-NNN alias. Generic words like \'db\' that are not Zabbix hosts are left alone.')) ?>"><input type="checkbox" name="security[categories][zabbix_inventory]" value="1" <?= !empty($config['security']['categories']['zabbix_inventory']) ? 'checked' : '' ?>> <?= $h(_('Zabbix host inventory (recommended)')) ?></label>
                <label class="ai-checkbox" title="<?= $h(_('Legacy heuristic that tries to guess hostnames by regex. Off by default because it produces false positives like \'first-line\' or \'Evidence-gathering\'. Enable only if you cannot use the inventory-based mode.')) ?>"><input type="checkbox" name="security[categories][hostnames]" value="1" <?= !empty($config['security']['categories']['hostnames']) ? 'checked' : '' ?>> <?= $h(_('Hostnames (heuristic, legacy)')) ?></label>
                <label class="ai-checkbox" title="<?= $h(_('Fetch the Zabbix service tree and replace business-service names with stable ai-service-NNN aliases. Off by default: service names that are common words (e.g. \'Database\', \'Web\') can over-mask surrounding text, so enable only if your service names are distinctive.')) ?>"><input type="checkbox" name="security[categories][services]" value="1" <?= !empty($config['security']['categories']['services']) ? 'checked' : '' ?>> <?= $h(_('Zabbix services')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[categories][ipv4]" value="1" <?= !empty($config['security']['categories']['ipv4']) ? 'checked' : '' ?>> <?= $h(_('IPv4')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[categories][ipv6]" value="1" <?= !empty($config['security']['categories']['ipv6']) ? 'checked' : '' ?>> <?= $h(_('IPv6')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[categories][fqdns]" value="1" <?= !empty($config['security']['categories']['fqdns']) ? 'checked' : '' ?>> <?= $h(_('FQDNs / domains')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[categories][urls]" value="1" <?= !empty($config['security']['categories']['urls']) ? 'checked' : '' ?>> <?= $h(_('URLs')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="security[categories][strip_url_query]" value="1" <?= !empty($config['security']['categories']['strip_url_query']) ? 'checked' : '' ?>> <?= $h(_('Strip URL query strings')) ?></label>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Inventory cache TTL (seconds)')) ?></label>
                    <input class="ai-input" type="number" min="30" max="86400" name="security[categories][inventory_ttl_seconds]" value="<?= $h((int) ($config['security']['categories']['inventory_ttl_seconds'] ?? 300)) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('OS handling')) ?></label>
                    <select class="ai-input" name="security[categories][os_mode]">
                        <?php foreach (['off', 'family_only', 'full_alias'] as $mode): ?>
                            <option value="<?= $h($mode) ?>" <?= (($config['security']['categories']['os_mode'] ?? 'family_only') === $mode) ? 'selected' : '' ?>><?= $h($mode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h3><?= $h(_('Custom replacements')) ?></h3>
            <p class="ai-muted">Use exact, regex, or domain suffix rules for service names, FQDNs, internal brands, or other site-specific terms.</p>
            <div id="ai-custom-rules-list" class="ai-repeat-list">
                <?php foreach ($custom_rules as $rule): ?>
                    <?= $render_custom_rule_row($rule) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" data-add-row="custom_rule"><?= $h(_('Add custom rule')) ?></button>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="security">
            <div class="ai-section-header">
                <h2><?= $h(_('Logging')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-logging" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-logging" class="ai-faq-box">
                <p><strong>What is this?</strong> Local JSONL audit logging. Logs chat requests, webhook calls, Zabbix actions, redaction events, user activity, settings changes, and errors.</p>
                <p><strong>Disabled by default.</strong> Enable it here and make sure the web server can write to the log path.</p>
                <p><strong>Settings:</strong></p>
                <ul>
                    <li><strong>Archive old logs</strong> &mdash; move yesterday's log files to the archive path</li>
                    <li><strong>Compress archives</strong> &mdash; gzip archived files to save disk space</li>
                    <li><strong>Retention days</strong> &mdash; delete archived files older than this</li>
                    <li><strong>Payload logging</strong> &mdash; include redacted message bodies in log entries</li>
                    <li><strong>Mapping details (high-risk)</strong> &mdash; stores alias-to-original mappings in logs. Useful for debugging but defeats the purpose of redaction. Off by default.</li>
                    <li><strong>Categories</strong> &mdash; pick which event types to log</li>
                </ul>
                <p><strong>View logs:</strong> Go to Monitoring &gt; AI &gt; Logs, or browse JSONL files directly on disk.</p>
                <p><strong>Setup commands (run as root):</strong></p>
<pre># Set WEB_GROUP to the actual php-fpm worker user.
# On RHEL/Alma/Rocky with nginx + php-fpm this is usually "apache",
# not "nginx". Confirm with: ps -eo user,comm | grep php-fpm
WEB_GROUP=apache   # or: nginx, www-data

# Create log directories (02770 = setgid + rwx for group, so new
# log files inherit the worker group automatically):
install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai
install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai/archive

# SELinux (RHEL/CentOS):
semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
restorecon -Rv /var/log/zabbix-ai

# Verify the worker can both write AND read back:
sudo -u $WEB_GROUP sh -c 'echo t &gt; /var/log/zabbix-ai/.t \
  &amp;&amp; cat /var/log/zabbix-ai/.t &gt; /dev/null \
  &amp;&amp; rm /var/log/zabbix-ai/.t' &amp;&amp; echo "Logs: OK"</pre>
                <p>Then set "Log path" to <code>/var/log/zabbix-ai</code> and "Archive path" to <code>/var/log/zabbix-ai/archive</code></p>
                <p><strong>Troubleshooting:</strong> If no logs appear after enabling, check: (1) at least one log category is selected, (2) the web process can write to the path: <code>sudo -u nginx touch /var/log/zabbix-ai/test &amp;&amp; rm /var/log/zabbix-ai/test</code>, (3) SELinux is not blocking writes: <code>ausearch -m avc -ts recent</code></p>
            </div>
            <p class="ai-muted">Logs are stored as local JSONL files and are redacted by default unless you explicitly enable mapping detail storage.</p>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enable logging')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[enabled]" value="1" <?= !empty($config['logging']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Write audit logs')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Archive old logs')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[archive_enabled]" value="1" <?= !empty($config['logging']['archive_enabled']) ? 'checked' : '' ?>> <?= $h(_('Move old logs to archive path')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Compress archives')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[compress_archives]" value="1" <?= !empty($config['logging']['compress_archives']) ? 'checked' : '' ?>> <?= $h(_('Use gzip when archiving')) ?></label>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Log path')) ?></label>
                    <input class="ai-input" type="text" name="logging[path]" value="<?= $h($config['logging']['path'] ?? '/tmp/zabbix-ai-module/logs') ?>">
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Archive path')) ?></label>
                    <input class="ai-input" type="text" name="logging[archive_path]" value="<?= $h($config['logging']['archive_path'] ?? '/tmp/zabbix-ai-module/archive') ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Retention days')) ?></label>
                    <input class="ai-input" type="number" min="1" max="3650" name="logging[retention_days]" value="<?= $h($config['logging']['retention_days'] ?? 30) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Max payload chars')) ?></label>
                    <input class="ai-input" type="number" min="200" max="500000" name="logging[max_payload_chars]" value="<?= $h($config['logging']['max_payload_chars'] ?? 8000) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Payload logging')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[include_payloads]" value="1" <?= !empty($config['logging']['include_payloads']) ? 'checked' : '' ?>> <?= $h(_('Include redacted payload bodies')) ?></label>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('High-risk option')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[include_mapping_details]" value="1" <?= !empty($config['logging']['include_mapping_details']) ? 'checked' : '' ?>> <?= $h(_('Store alias-to-original mapping details in logs')) ?></label>
                </div>
            </div>

            <h3><?= $h(_('Log categories')) ?></h3>
            <div class="ai-check-grid">
                <?php foreach ([
                    'chat' => _('Chat'),
                    'webhook' => _('Webhook'),
                    'reads' => _('Reads'),
                    'writes' => _('Writes'),
                    'translations' => _('Translations'),
                    'user_activity' => _('User activity'),
                    'settings_changes' => _('Settings changes'),
                    'errors' => _('Errors')
                ] as $key => $label): ?>
                    <label class="ai-checkbox"><input type="checkbox" name="logging[categories][<?= $h($key) ?>]" value="1" <?= !empty($config['logging']['categories'][$key]) ? 'checked' : '' ?>> <?= $h($label) ?></label>
                <?php endforeach; ?>
            </div>

            <?php
            $log_writable = !empty($log_summary['path_writable']);
            $archive_writable = !empty($log_summary['archive_path_writable']);
            $any_unwritable = !$log_writable || !$archive_writable;
            ?>
            <div class="ai-note-box">
                <strong><?= $h(_('Current log target')) ?>:</strong>
                <div class="ai-muted"><?= $h($log_summary['current_log_file'] ?? '') ?></div>
                <div class="ai-muted"><?= $h(_('Log path writable')) ?>: <?= $log_writable ? $h(_('Yes')) : '<strong style="color:#c00">'.$h(_('No')).'</strong>' ?></div>
                <div class="ai-muted"><?= $h(_('Archive path writable')) ?>: <?= $archive_writable ? $h(_('Yes')) : '<strong style="color:#c00">'.$h(_('No')).'</strong>' ?></div>
                <div class="ai-muted"><?= $h(_('Live files')) ?>: <?= $h($log_summary['live_file_count'] ?? 0) ?> | <?= $h(_('Archived files')) ?>: <?= $h($log_summary['archive_file_count'] ?? 0) ?></div>
                <?php if ($any_unwritable && $permission_note !== ''): ?>
                    <p class="ai-muted ai-top-margin"><strong><?= $h(_('How to fix:')) ?></strong> <?= $h($permission_note) ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="zabbix">
            <div class="ai-section-header">
                <h2><?= $h(_('Zabbix actions')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-actions" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div class="ai-danger-notice" role="alert">
                <p>
                    <strong><?= $h(_('Warning')) ?></strong> &mdash;
                    <?= $h(_('Enabling this lets the AI module read and (in Read & write mode) modify your Zabbix configuration on behalf of users. Write actions can create maintenance windows, change items/triggers, create users, and acknowledge problems. Only enable if you trust the AI provider, the configured Zabbix API token scope, and the users who can chat with the AI.')) ?>
                </p>
                <p class="ai-danger-notice-followup">
                    <?= $h(_('Routine troubleshooting reads may run automatically. Fleet problem/maintenance, event-comment and other privacy-sensitive reads pause for confirmation, and every write is shown with its exact server-validated identity, target and values before execution.')) ?>
                </p>
            </div>
            <div id="faq-actions" class="ai-faq-box">
                <p><strong>What is this?</strong> Lets the AI query and modify Zabbix through natural language. Ask things like "Show me all high-severity problems" or "Create a maintenance window for host db-01".</p>
                <?php
                $cat_reads = [];
                $cat_writes = [];
                foreach ($actions_catalog as $tool_name => $tool_def) {
                    if ((($tool_def['rw'] ?? 'read')) === 'write') {
                        $cat = (string) ($tool_def['category'] ?? '');
                        $cat_writes[$cat !== '' ? $cat : 'other'][] = $tool_name;
                    }
                    else {
                        $cat_reads[] = $tool_name;
                    }
                }
                $write_total = 0;
                foreach ($cat_writes as $names) { $write_total += count($names); }
                ksort($cat_writes);
                sort($cat_reads);
                ?>
                <p><strong><?= $h(_('Live action catalog')) ?>:</strong>
                    <?= $h(sprintf(_('The AI currently has %1$d tools — %2$d read, %3$d write. This list is generated from the module code, so it stays accurate as tools are added.'), count($cat_reads) + $write_total, count($cat_reads), $write_total)) ?>
                </p>
                <?php if ($cat_reads): ?>
                    <p><strong><?= $h(_('Read actions')) ?></strong> (<?= (int) count($cat_reads) ?>, <?= $h(_('fleet problem/maintenance, event-comment, broad inventory, contact, macro, NetBox and audit reads require privacy confirmation')) ?>): <?= $h(implode(', ', $cat_reads)) ?></p>
                <?php endif; ?>
                <?php if ($cat_writes): ?>
                    <p><strong><?= $h(_('Write actions')) ?></strong> (<?= (int) $write_total ?>, <?= $h(_('require confirmation; each category is gated by the Write permissions below')) ?>):</p>
                    <ul>
                        <?php foreach ($cat_writes as $cat => $names): sort($names); ?>
                            <li><strong><?= $h($cat) ?></strong>: <?= $h(implode(', ', $names)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p><strong>Settings:</strong></p>
                <ul>
                    <li><strong>Mode</strong> &mdash; "Read only" = AI can query but not modify. "Read &amp; Write" = AI can also suggest modifications (always with user confirmation).</li>
                    <li><strong>Write permissions</strong> &mdash; enable per category so you control exactly what the AI can modify</li>
                    <li><strong>Require Super Admin</strong> &mdash; when checked, only Super Admin users can execute write actions</li>
                </ul>
                <p><strong>Requires:</strong> a valid Zabbix frontend API session. The explicitly configured service-token fallback is available only under the identity rules described above.</p>
                <p><strong>Model compatibility:</strong> the selected provider and model must support provider-native structured tool calls whenever Zabbix Actions are enabled. Model prose is never parsed as an executable action.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[enabled]" value="1" <?= !empty($config['zabbix_actions']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow AI-driven Zabbix actions')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Mode')) ?></label>
                    <select id="ai-actions-mode" class="ai-input" name="zabbix_actions[mode]">
                        <option value="read" <?= (($config['zabbix_actions']['mode'] ?? 'read') === 'read') ? 'selected' : '' ?>><?= $h(_('Read only')) ?></option>
                        <option value="readwrite" <?= (($config['zabbix_actions']['mode'] ?? 'read') === 'readwrite') ? 'selected' : '' ?>><?= $h(_('Read & write')) ?></option>
                    </select>
                </div>
                <div>
                    <label class="ai-label">
                        <?= $h(_('Require Super Admin for write')) ?>
                        <span class="ai-recommended-badge" title="<?= $h(_('Strongly recommended in production.')) ?>"><?= $h(_('Recommended')) ?></span>
                    </label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[require_super_admin_for_write]" value="1" <?= !empty($config['zabbix_actions']['require_super_admin_for_write']) ? 'checked' : '' ?>> <?= $h(_('Restrict write actions')) ?></label>
                </div>
            </div>
            <?php $auto_reads = (string) ($config['zabbix_actions']['problem_drawer_auto_reads'] ?? 'off'); ?>
            <div class="ai-danger-notice" id="ai-problem-drawer-auto-reads" role="alert">
                <p><strong><?= $h(_('Problems-page AI drawer: privacy confirmations for reads')) ?></strong></p>
                <label class="ai-label" for="ai-problem-drawer-auto-reads-select"><?= $h(_('Confirmation level')) ?></label>
                <select id="ai-problem-drawer-auto-reads-select" class="ai-input" name="zabbix_actions[problem_drawer_auto_reads]">
                    <option value="off" <?= $auto_reads === 'off' ? 'selected' : '' ?>><?= $h(_('Confirm every sensitive read (recommended, default)')) ?></option>
                    <option value="triage" <?= $auto_reads === 'triage' ? 'selected' : '' ?>><?= $h(_('Auto-approve event-scoped triage reads')) ?></option>
                    <option value="all" <?= $auto_reads === 'all' ? 'selected' : '' ?>><?= $h(_('Auto-approve every sensitive read')) ?></option>
                </select>
                <p><?= $h(_('Applies only to the AI drawer opened from a row on the Problems page, and only when the server resolves that event itself under the same Zabbix identity the reads will use. The full AI chat page always asks. Write actions always pause for confirmation and are not affected by this setting at any level.')) ?></p>
                <p><?= $h(_('Auto-approve event-scoped triage reads: exempts related problems, event timeline, problems, problem graph, host info, host interfaces, items, triggers, trigger dependencies, unsupported items, active maintenance, alerts and actions for the event, escalation path, and service impact. Note that the two event-scoped alert/action reads in that set do disclose the notification recipients and media addresses used for the event. Fleet inventory, the media-type and action configuration, effective macro values, NetBox records, audit history, the service tree, bulk previews and report builders keep asking.')) ?></p>
                <p><?= $h(_('Auto-approve every sensitive read: also exempts those remaining reads on this surface, including the six bulk previews that are otherwise an operator\'s last look before a bulk write. Redaction still masks hostnames, addresses, FQDNs, URLs and OS strings, but it does not mask macro values, notification destinations, usernames, item keys, trigger expressions or free text — those reach the AI provider without a per-call confirmation.')) ?></p>
                <p><?= $h(_('At either level the results stay in ordinary chat history rather than being shown once and dropped, so continuing the conversation in the full AI chat page can re-send them. Auto-approved reads are recorded as zabbix.sensitive_read.auto_confirmed with the tool name and event ID — but only when module logging is enabled on the Logging tab, which is off by default. Turn logging on if you want that trail.')) ?></p>
            </div>
            <div id="ai-write-permissions" style="<?= (($config['zabbix_actions']['mode'] ?? 'read') === 'readwrite') ? '' : 'display:none;' ?>">
                <h3><?= $h(_('Write permissions')) ?></h3>
                <div class="ai-check-grid">
                    <?php foreach (['maintenance', 'items', 'triggers', 'users', 'problems', 'hostgroups', 'hosts', 'interfaces', 'web', 'dashboards', 'templates', 'discovery', 'bulk', 'sla'] as $perm): ?>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][<?= $h($perm) ?>]" value="1" <?= !empty($config['zabbix_actions']['write_permissions'][$perm]) ? 'checked' : '' ?>> <?= $h($perm === 'sla' ? 'SLA' : ucfirst($perm)) ?></label>
                    <?php endforeach; ?>
                </div>
                <h3><?= $h(_('Web scenario destination allowlist')) ?></h3>
                <p class="ai-muted"><?= $h(_('AI-created web scenarios are denied unless the URL origin is listed here. Enter one exact origin per line, for example https://status.example.com or https://*.checks.example.com:8443. Scheme and port are enforced; loopback, link-local and cloud metadata addresses remain blocked even if listed.')) ?></p>
                <textarea class="ai-textarea" rows="4" name="zabbix_actions[web_scenario_allowed_origins]" placeholder="https://status.example.com&#10;https://*.checks.example.com:8443"><?= $h($config['zabbix_actions']['web_scenario_allowed_origins'] ?? '') ?></textarea>
                <h3><?= $h(_('Bulk safety limits')) ?></h3>
                <p class="ai-muted"><?= $h(_('Maximum number of objects a single bulk action may affect. Bulk previews and writes are capped to these values.')) ?></p>
                <div class="ai-repeat-grid ai-settings-grid">
                    <div>
                        <label class="ai-label"><?= $h(_('Max hosts per bulk action')) ?></label>
                        <input class="ai-input" type="number" min="1" max="1000" name="zabbix_actions[bulk_max_hosts]" value="<?= $h((int) ($config['zabbix_actions']['bulk_max_hosts'] ?? 25)) ?>">
                    </div>
                    <div>
                        <label class="ai-label"><?= $h(_('Max items/triggers per bulk action')) ?></label>
                        <input class="ai-input" type="number" min="1" max="5000" name="zabbix_actions[bulk_max_items]" value="<?= $h((int) ($config['zabbix_actions']['bulk_max_items'] ?? 100)) ?>">
                    </div>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="chat">
            <div class="ai-section-header">
                <h2><?= $h(_('Reports')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-reports" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-reports" class="ai-faq-box">
                <p><strong>What is this?</strong> The chat can produce downloadable reports (CSV/HTML/JSON), Markdown evidence bundles, and inline SVG graphs (problem counts over time, with Zabbix severity colours).</p>
                <p><strong>Directory</strong> &mdash; on-disk location where generated files are kept until their TTL expires. Leave blank to reuse the security state path (a <code>reports/</code> subdirectory). For production, set a dedicated path like <code>/var/lib/zabbix-ai/reports</code> with the same ownership/SELinux context as the state path.</p>
                <p><strong>TTL (seconds)</strong> &mdash; how long each generated file is kept. 3600 (1 hour) is a sensible default. Files are removed by the next chat request after expiry.</p>
                <p><strong>Delete after download</strong> &mdash; remove the file as soon as the user clicks the download link. Off by default so the user can re-download or share the link (within TTL).</p>
                <p><strong>Permissions:</strong> the php-fpm worker user (often <code>apache</code> on RHEL even behind nginx) must be able to <em>create and read</em> files in this directory. SELinux label: <code>httpd_sys_rw_content_t</code>.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="reports[enabled]" value="1" <?= !empty($config['reports']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow report and graph generation')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('TTL (seconds)')) ?></label>
                    <input class="ai-input" type="number" min="300" max="86400" name="reports[ttl_seconds]" value="<?= $h($config['reports']['ttl_seconds'] ?? 3600) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Delete after download')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="reports[delete_after_download]" value="1" <?= !empty($config['reports']['delete_after_download']) ? 'checked' : '' ?>> <?= $h(_('Remove file once user downloads it')) ?></label>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Directory')) ?></label>
                    <input class="ai-input" type="text" name="reports[directory]" placeholder="<?= $h(_('Leave blank to reuse the security state path')) ?>" value="<?= $h($config['reports']['directory'] ?? '') ?>">
                </div>
            </div>
        </section>

        <div class="ai-section-actions ai-sticky-actions">
            <button type="submit" class="btn"><?= $h(_('Save settings')) ?></button>
        </div>
    </form>
</div>

<script type="text/template" id="ai-provider-template"><?= str_replace('</script>', '<\/script>', $render_provider_row()) ?></script>
<script type="text/template" id="ai-instruction-template"><?= str_replace('</script>', '<\/script>', $render_instruction_row()) ?></script>
<script type="text/template" id="ai-reference-link-template"><?= str_replace('</script>', '<\/script>', $render_link_row()) ?></script>
<script type="text/template" id="ai-custom-rule-template"><?= str_replace('</script>', '<\/script>', $render_custom_rule_row()) ?></script>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('AI settings'))
    ->addItem(new class($content) {
        private $html;
        public function __construct($html) { $this->html = $html; }
        public function toString($destroy = true) { return $this->html; }
    })
    ->show();
