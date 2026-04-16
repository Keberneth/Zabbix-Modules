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

$settings_save_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.settings.save')
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
    'openai_compatible' => 'OPENAI_API_KEY',
    'anthropic' => 'ANTHROPIC_API_KEY',
    'ollama' => ''
];

$render_provider_row = static function(array $provider = []) use ($h, $api_key_env_placeholder_map): string {
    ob_start();
    $id = $provider['id'] ?? '__ROW_ID__';
    $current_type = $provider['type'] ?? 'openai_compatible';
    $api_key_env_placeholder = $api_key_env_placeholder_map[$current_type] ?? 'OPENAI_API_KEY';
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
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][model]" value="<?= $h($provider['model'] ?? '') ?>" placeholder="gpt-4.1-mini / llama3.2 / claude-sonnet">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Timeout')) ?></label>
                <input class="ai-input" type="number" min="5" max="300" name="providers[<?= $h($id) ?>][timeout]" value="<?= $h($provider['timeout'] ?? 60) ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Temperature')) ?></label>
                <input class="ai-input" type="number" min="0" max="2" step="0.1" name="providers[<?= $h($id) ?>][temperature]" value="<?= $h(($provider['temperature'] ?? -1) >= 0 ? $provider['temperature'] : '') ?>" placeholder="Global default">
                <span class="ai-muted"><?= $h(_('Leave blank to use global chat temperature.')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Max tokens')) ?></label>
                <input class="ai-input" type="number" min="0" max="128000" step="1" name="providers[<?= $h($id) ?>][max_tokens]" value="<?= $h(($provider['max_tokens'] ?? 0) > 0 ? $provider['max_tokens'] : '') ?>" placeholder="Provider default">
                <span class="ai-muted"><?= $h(_('Leave blank for provider default (4096 for Anthropic).')) ?></span>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][verify_peer]" value="1" <?= !empty($provider['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
            </div>
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('API key / secret')) ?></label>
                <input class="ai-input" type="password" name="providers[<?= $h($id) ?>][api_key]" value="" placeholder="<?= !empty($provider['api_key_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                <div class="ai-inline-notes">
                    <?php if (!empty($provider['api_key_present'])): ?>
                        <span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span>
                    <?php endif; ?>
                    <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][clear_api_key]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                </div>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Secret environment variable')) ?></label>
                <input class="ai-input ai-provider-api-key-env" type="text" name="providers[<?= $h($id) ?>][api_key_env]" value="<?= $h($provider['api_key_env'] ?? '') ?>" placeholder="<?= $h($api_key_env_placeholder) ?>">
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Extra headers JSON')) ?></label>
                <textarea class="ai-textarea" rows="3" name="providers[<?= $h($id) ?>][headers_json]" placeholder='{"X-Custom-Header":"value"}'><?= $h($provider['headers_json'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn-alt ai-remove-row"><?= $h(_('Remove provider')) ?></button>
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
            <button type="button" class="btn-alt ai-remove-row"><?= $h(_('Remove instruction')) ?></button>
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
            <button type="button" class="btn-alt ai-remove-row"><?= $h(_('Remove link')) ?></button>
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
            <button type="button" class="btn-alt ai-remove-row"><?= $h(_('Remove rule')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

ob_start();
?>
<div id="ai-settings-root" class="ai-page ai-settings-page" data-ai-theme="<?= $h($ai_theme) ?>">
    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('AI settings')) ?></h1>
            <p class="ai-muted">Configure providers, prompt policy, integrations, redaction, and local logging.</p>
        </div>
        <div class="ai-header-actions">
            <a class="btn-alt" href="<?= $h($chat_url) ?>"><?= $h(_('Open chat')) ?></a>
            <a class="btn-alt" href="<?= $h($logs_url) ?>"><?= $h(_('Open logs')) ?></a>
        </div>
    </div>

    <form id="ai-settings-form" method="post" action="<?= $h($settings_save_url) ?>">
        <input type="hidden" name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" value="<?= $h(CCsrfTokenHelper::get('ai.settings.save')) ?>">

        <section class="ai-card">
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
                <p><strong>Defaults:</strong> You can use different providers for chat, webhook, and Zabbix actions. For example, a fast/cheap model for chat and a more capable model for Zabbix actions.</p>
                <p><strong>API keys:</strong> Prefer environment variables over storing secrets directly. Set the env var name in "Secret environment variable" and ensure it is visible to your PHP/web process.</p>
            </div>
            <p class="ai-muted">Supported provider types: openai_compatible, ollama, anthropic.</p>
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
            <div id="ai-providers-list" class="ai-repeat-list">
                <?php foreach ($providers as $provider): ?>
                    <?= $render_provider_row($provider) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn-alt" data-add-row="provider"><?= $h(_('Add provider')) ?></button>
            </div>
        </section>

        <section class="ai-card">
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
                <button type="button" class="btn-alt" data-add-row="instruction"><?= $h(_('Add instruction')) ?></button>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Reference links')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-links" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-links" class="ai-faq-box">
                <p><strong>What is this?</strong> URLs the AI can suggest to operators when relevant. For example, internal runbooks, wiki pages, or dashboards.</p>
                <p>The AI sees these links in its system prompt and will suggest them when they are useful to the current problem.</p>
            </div>
            <div id="ai-reference-links-list" class="ai-repeat-list">
                <?php foreach ($reference_links as $link): ?>
                    <?= $render_link_row($link) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn-alt" data-add-row="reference_link"><?= $h(_('Add link')) ?></button>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Zabbix API')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-zabbix-api" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-zabbix-api" class="ai-faq-box">
                <p><strong>What is this?</strong> Connection to your Zabbix API. Required for AI-powered Zabbix actions, OS lookup, and posting AI answers back to events.</p>
                <p><strong>API URL:</strong> Usually <code>https://your-zabbix/api_jsonrpc.php</code></p>
                <p><strong>Auth mode:</strong></p>
                <ul>
                    <li><strong>auto</strong> &mdash; tries Bearer token first, falls back to legacy auth field (recommended)</li>
                    <li><strong>bearer</strong> &mdash; Zabbix 6.4+ API token in Authorization header</li>
                    <li><strong>legacy_auth_field</strong> &mdash; token sent in JSON auth field (older Zabbix versions)</li>
                </ul>
                <p><strong>Token permissions:</strong> The API token needs read access for read actions. For write actions (maintenance, trigger updates, etc.) the token also needs write access to those Zabbix objects.</p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('API URL')) ?></label>
                    <input class="ai-input" type="text" name="zabbix_api[url]" value="<?= $h($config['zabbix_api']['url'] ?? '') ?>" placeholder="https://zabbix.example.local/api_jsonrpc.php">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Auth mode')) ?></label>
                    <select class="ai-input" name="zabbix_api[auth_mode]">
                        <?php foreach (['auto', 'bearer', 'legacy_auth_field'] as $auth_mode): ?>
                            <option value="<?= $h($auth_mode) ?>" <?= (($config['zabbix_api']['auth_mode'] ?? 'auto') === $auth_mode) ? 'selected' : '' ?>><?= $h($auth_mode) ?></option>
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
                    <input class="ai-input" type="password" name="zabbix_api[token]" value="" placeholder="<?= !empty($config['zabbix_api']['token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['zabbix_api']['token_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored token exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_api[clear_token]" value="1"> <?= $h(_('Clear stored token')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Token environment variable')) ?></label>
                    <input class="ai-input" type="text" name="zabbix_api[token_env]" value="<?= $h($config['zabbix_api']['token_env'] ?? '') ?>" placeholder="ZABBIX_API_TOKEN">
                </div>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('NetBox')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-netbox" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-netbox" class="ai-faq-box">
                <p><strong>What is this?</strong> Optional NetBox/CMDB integration. When enabled, the AI receives extra context about hosts (VM details, device info, services) from NetBox when a hostname is provided in the chat or webhook.</p>
                <p><strong>When to use:</strong> If your team uses NetBox as a source of truth for infrastructure data and you want the AI to include that context in troubleshooting answers.</p>
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
                    <label class="ai-checkbox"><input type="checkbox" name="netbox[verify_peer]" value="1" <?= !empty($config['netbox']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('NetBox URL')) ?></label>
                    <input class="ai-input" type="text" name="netbox[url]" value="<?= $h($config['netbox']['url'] ?? '') ?>" placeholder="https://netbox.example.local">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('NetBox token')) ?></label>
                    <input class="ai-input" type="password" name="netbox[token]" value="" placeholder="<?= !empty($config['netbox']['token_present']) ? $h(_('Leave blank to keep current token')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['netbox']['token_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored token exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox"><input type="checkbox" name="netbox[clear_token]" value="1"> <?= $h(_('Clear stored token')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Token environment variable')) ?></label>
                    <input class="ai-input" type="text" name="netbox[token_env]" value="<?= $h($config['netbox']['token_env'] ?? '') ?>" placeholder="NETBOX_TOKEN">
                </div>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Webhook')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-webhook" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-webhook" class="ai-faq-box">
                <p><strong>What is this?</strong> The webhook lets Zabbix send problem events to the AI automatically. The AI generates first-line troubleshooting guidance and can post it back as a problem update comment.</p>
                <p><strong>Webhook URL:</strong> <code>https://your-zabbix/zabbix.php?action=ai.webhook</code></p>
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
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_netbox]" value="1" <?= !empty($config['webhook']['include_netbox']) ? 'checked' : '' ?>> <?= $h(_('Use NetBox context')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Include OS hint')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_os_hint]" value="1" <?= !empty($config['webhook']['include_os_hint']) ? 'checked' : '' ?>> <?= $h(_('Look up OS from Zabbix')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment action code')) ?></label>
                    <input class="ai-input" type="number" min="1" max="256" name="webhook[problem_update_action]" value="<?= $h($config['webhook']['problem_update_action'] ?? 4) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment chunk size')) ?></label>
                    <input class="ai-input" type="number" min="200" max="2000" name="webhook[comment_chunk_size]" value="<?= $h($config['webhook']['comment_chunk_size'] ?? 1900) ?>">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Shared secret')) ?></label>
                    <input class="ai-input" type="password" name="webhook[shared_secret]" value="" placeholder="<?= !empty($config['webhook']['shared_secret_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($config['webhook']['shared_secret_present'])): ?>
                            <span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span>
                        <?php endif; ?>
                        <label class="ai-checkbox"><input type="checkbox" name="webhook[clear_shared_secret]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Secret environment variable')) ?></label>
                    <input class="ai-input" type="text" name="webhook[shared_secret_env]" value="<?= $h($config['webhook']['shared_secret_env'] ?? '') ?>" placeholder="AI_WEBHOOK_SECRET">
                </div>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Chat')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-chat" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-chat" class="ai-faq-box">
                <p><strong>What is this?</strong> Controls for the chat page behavior.</p>
                <ul>
                    <li><strong>Max history messages</strong> &mdash; How many previous messages are sent to the AI for context. Higher = more context but slower and more tokens. Default 12.</li>
                    <li><strong>Temperature</strong> &mdash; Controls AI randomness. 0 = deterministic, 1 = creative, 2 = very random. Default 0.2 (focused and consistent). Can be overridden per provider.</li>
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
                    <input class="ai-input" type="number" min="0" max="2" step="0.1" name="chat[temperature]" value="<?= $h($config['chat']['temperature'] ?? 0.2) ?>">
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

        <section class="ai-card">
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

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Security / redaction')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-security" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-security" class="ai-faq-box">
                <p><strong>What is this?</strong> Replaces sensitive values (hostnames, IPs, domains, URLs, OS names) with safe aliases before sending data to the AI provider. When the AI responds, aliases are restored locally so you see the real values.</p>
                <p><strong>How it works:</strong> <code>prd-web-001</code> becomes <code>ai-host-001</code> outbound. The AI works with the alias. When the reply comes back, <code>ai-host-001</code> is replaced with <code>prd-web-001</code> before you see it.</p>
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
<pre># Set WEB_GROUP to your web server group: nginx, apache, or www-data
WEB_GROUP=nginx

# Using persistent path (recommended):
mkdir -p /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending
chown -R root:$WEB_GROUP /var/lib/zabbix-ai
chmod -R 0750 /var/lib/zabbix-ai

# SELinux (RHEL/CentOS):
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
restorecon -Rv /var/lib/zabbix-ai</pre>
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
                <button type="button" class="btn-alt" data-add-row="custom_rule"><?= $h(_('Add custom rule')) ?></button>
            </div>
        </section>

        <section class="ai-card">
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
<pre># Set WEB_GROUP to your web server group: nginx, apache, or www-data
WEB_GROUP=nginx

# Create log directories:
mkdir -p /var/log/zabbix-ai /var/log/zabbix-ai/archive
chown -R root:$WEB_GROUP /var/log/zabbix-ai
chmod -R 0750 /var/log/zabbix-ai

# SELinux (RHEL/CentOS):
semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
restorecon -Rv /var/log/zabbix-ai</pre>
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

            <div class="ai-note-box">
                <strong><?= $h(_('Current log target')) ?>:</strong>
                <div class="ai-muted"><?= $h($log_summary['current_log_file'] ?? '') ?></div>
                <div class="ai-muted"><?= $h(_('Log path writable')) ?>: <?= !empty($log_summary['path_writable']) ? $h(_('Yes')) : $h(_('No')) ?></div>
                <div class="ai-muted"><?= $h(_('Archive path writable')) ?>: <?= !empty($log_summary['archive_path_writable']) ? $h(_('Yes')) : $h(_('No')) ?></div>
                <div class="ai-muted"><?= $h(_('Live files')) ?>: <?= $h($log_summary['live_file_count'] ?? 0) ?> | <?= $h(_('Archived files')) ?>: <?= $h($log_summary['archive_file_count'] ?? 0) ?></div>
                <?php if ($permission_note !== ''): ?>
                    <p class="ai-muted ai-top-margin"><?= $h($permission_note) ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="ai-card">
            <div class="ai-section-header">
                <h2><?= $h(_('Zabbix actions')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-actions" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-actions" class="ai-faq-box">
                <p><strong>What is this?</strong> Lets the AI query and modify Zabbix through natural language. Ask things like "Show me all high-severity problems" or "Create a maintenance window for host db-01".</p>
                <p><strong>Read actions</strong> (always safe): get_problems, get_unsupported_items, get_host_info, get_host_uptime, get_host_os, get_triggers, get_items</p>
                <p><strong>Write actions</strong> (require confirmation): create_maintenance, update_trigger, update_item, create_user, acknowledge_problem</p>
                <p><strong>Settings:</strong></p>
                <ul>
                    <li><strong>Mode</strong> &mdash; "Read only" = AI can query but not modify. "Read &amp; Write" = AI can also suggest modifications (always with user confirmation).</li>
                    <li><strong>Write permissions</strong> &mdash; enable per category so you control exactly what the AI can modify</li>
                    <li><strong>Require Super Admin</strong> &mdash; when checked, only Super Admin users can execute write actions</li>
                </ul>
                <p><strong>Requires:</strong> Zabbix API must be configured above with a token that has sufficient permissions.</p>
                <p><strong>Tip:</strong> Larger AI models (GPT-4, Claude Sonnet/Opus) are much better at generating correct tool calls than smaller models.</p>
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
                    <label class="ai-label"><?= $h(_('Require Super Admin for write')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[require_super_admin_for_write]" value="1" <?= !empty($config['zabbix_actions']['require_super_admin_for_write']) ? 'checked' : '' ?>> <?= $h(_('Restrict write actions')) ?></label>
                </div>
            </div>
            <div id="ai-write-permissions" style="<?= (($config['zabbix_actions']['mode'] ?? 'read') === 'readwrite') ? '' : 'display:none;' ?>">
                <h3><?= $h(_('Write permissions')) ?></h3>
                <div class="ai-check-grid">
                    <?php foreach (['maintenance', 'items', 'triggers', 'users', 'problems', 'hostgroups'] as $perm): ?>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][<?= $h($perm) ?>]" value="1" <?= !empty($config['zabbix_actions']['write_permissions'][$perm]) ? 'checked' : '' ?>> <?= $h(ucfirst($perm)) ?></label>
                    <?php endforeach; ?>
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
