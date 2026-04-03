<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$config = $data['config'] ?? [];

$settings_save_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.settings.save')
    ->getUrl();

$chat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat')
    ->getUrl();

$providers = is_array($config['providers'] ?? null) ? $config['providers'] : [];
$instructions = is_array($config['instructions'] ?? null) ? $config['instructions'] : [];
$reference_links = is_array($config['reference_links'] ?? null) ? $config['reference_links'] : [];

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $ai_theme = 'dark';
    }
}

$render_provider_row = static function(array $provider = []) use ($h, $config): string {
    ob_start();
    $id = $provider['id'] ?? '';
    ?>
    <div class="ai-repeat-row ai-provider-row" data-row-type="provider">
        <input type="hidden" class="ai-provider-id-field" name="providers[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-provider-grid">
            <div>
                <label class="ai-label"><?= $h(_('Name')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][name]" value="<?= $h($provider['name'] ?? '') ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Type')) ?></label>
                <select class="ai-input" name="providers[<?= $h($id) ?>][type]">
                    <option value="openai_compatible" <?= (($provider['type'] ?? 'openai_compatible') === 'openai_compatible') ? 'selected' : '' ?>>openai_compatible</option>
                    <option value="ollama" <?= (($provider['type'] ?? '') === 'ollama') ? 'selected' : '' ?>>ollama</option>
                    <option value="anthropic" <?= (($provider['type'] ?? '') === 'anthropic') ? 'selected' : '' ?>>anthropic</option>
                </select>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][enabled]" value="1" <?= !empty($provider['enabled']) ? 'checked' : '' ?>> <?= $h(_('Use this provider')) ?></label>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Default for chat')) ?></label>
                <label class="ai-checkbox"><input class="ai-provider-default-chat" type="radio" name="default_chat_provider_id" value="<?= $h($id) ?>" <?= (($config['default_chat_provider_id'] ?? '') === $id) ? 'checked' : '' ?>> <?= $h(_('Select')) ?></label>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Default for webhook')) ?></label>
                <label class="ai-checkbox"><input class="ai-provider-default-webhook" type="radio" name="default_webhook_provider_id" value="<?= $h($id) ?>" <?= (($config['default_webhook_provider_id'] ?? '') === $id) ? 'checked' : '' ?>> <?= $h(_('Select')) ?></label>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Default for Zabbix actions')) ?></label>
                <label class="ai-checkbox"><input class="ai-provider-default-actions" type="radio" name="default_actions_provider_id" value="<?= $h($id) ?>" <?= (($config['default_actions_provider_id'] ?? '') === $id) ? 'checked' : '' ?>> <?= $h(_('Select')) ?></label>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Timeout (seconds)')) ?></label>
                <input class="ai-input" type="number" min="5" max="300" name="providers[<?= $h($id) ?>][timeout]" value="<?= $h($provider['timeout'] ?? 60) ?>">
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Endpoint URL')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][endpoint]" value="<?= $h($provider['endpoint'] ?? '') ?>" placeholder="https://api.openai.com/v1/chat/completions">
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('Model')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][model]" value="<?= $h($provider['model'] ?? '') ?>" placeholder="gpt-4.1-mini / llama3.2:3b / local model name">
            </div>
            <div class="ai-span-3">
                <label class="ai-label"><?= $h(_('API key / bearer token')) ?></label>
                <input class="ai-input" type="password" name="providers[<?= $h($id) ?>][api_key]" value="" placeholder="<?= !empty($provider['api_key_present']) ? $h(_('Leave blank to keep current secret')) : '' ?>">
                <div class="ai-inline-notes">
                    <?php if (!empty($provider['api_key_present'])): ?>
                        <span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span>
                    <?php endif; ?>
                    <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][clear_api_key]" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                </div>
            </div>
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('Secret environment variable')) ?></label>
                <input class="ai-input" type="text" name="providers[<?= $h($id) ?>][api_key_env]" value="<?= $h($provider['api_key_env'] ?? '') ?>" placeholder="OPENAI_API_KEY">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="providers[<?= $h($id) ?>][verify_peer]" value="1" <?= !empty($provider['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
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
    $id = $instruction['id'] ?? '';
    ?>
    <div class="ai-repeat-row" data-row-type="instruction">
        <input type="hidden" class="ai-row-id-field" name="instructions[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-instruction-grid">
            <div>
                <label class="ai-label"><?= $h(_('Title')) ?></label>
                <input class="ai-input" type="text" name="instructions[<?= $h($id) ?>][title]" value="<?= $h($instruction['title'] ?? '') ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="instructions[<?= $h($id) ?>][enabled]" value="1" <?= !empty($instruction['enabled']) ? 'checked' : '' ?>> <?= $h(_('Use this instruction block')) ?></label>
            </div>
            <div class="ai-span-2">
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
    $id = $link['id'] ?? '';
    ?>
    <div class="ai-repeat-row" data-row-type="reference_link">
        <input type="hidden" class="ai-row-id-field" name="reference_links[<?= $h($id) ?>][id]" value="<?= $h($id) ?>">
        <div class="ai-repeat-grid ai-link-grid">
            <div>
                <label class="ai-label"><?= $h(_('Title')) ?></label>
                <input class="ai-input" type="text" name="reference_links[<?= $h($id) ?>][title]" value="<?= $h($link['title'] ?? '') ?>">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                <label class="ai-checkbox"><input type="checkbox" name="reference_links[<?= $h($id) ?>][enabled]" value="1" <?= !empty($link['enabled']) ? 'checked' : '' ?>> <?= $h(_('Offer this link in responses')) ?></label>
            </div>
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('URL')) ?></label>
                <input class="ai-input" type="text" name="reference_links[<?= $h($id) ?>][url]" value="<?= $h($link['url'] ?? '') ?>" placeholder="https://docs.example.local/runbook">
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn-alt ai-remove-row"><?= $h(_('Remove link')) ?></button>
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
            <p class="ai-muted">
                Secrets can be stored directly in module config, but environment variables are safer for production.
            </p>
        </div>
        <div class="ai-header-actions">
            <a class="btn-alt" href="<?= $h($chat_url) ?>"><?= $h(_('Open chat')) ?></a>
        </div>
    </div>

    <form id="ai-settings-form" method="post" action="<?= $h($settings_save_url) ?>">
        <input type="hidden" name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>" value="<?= $h(CCsrfTokenHelper::get('ai.settings.save')) ?>">

        <section class="ai-card">
            <h2><?= $h(_('Providers')) ?></h2>
            <p class="ai-muted">
                Supported provider schemas in this version: openai_compatible and ollama.
            </p>
            <div id="ai-providers-list" class="ai-repeat-list" data-empty-row-type="provider">
                <?php foreach ($providers as $provider): ?>
                    <?= $render_provider_row($provider) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn-alt" data-add-row="provider"><?= $h(_('Add provider')) ?></button>
            </div>
        </section>

        <section class="ai-card">
            <h2><?= $h(_('General instructions')) ?></h2>
            <div id="ai-instructions-list" class="ai-repeat-list" data-empty-row-type="instruction">
                <?php foreach ($instructions as $instruction): ?>
                    <?= $render_instruction_row($instruction) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn-alt" data-add-row="instruction"><?= $h(_('Add instruction')) ?></button>
            </div>
        </section>

        <section class="ai-card">
            <h2><?= $h(_('Reference links')) ?></h2>
            <div id="ai-reference-links-list" class="ai-repeat-list" data-empty-row-type="reference_link">
                <?php foreach ($reference_links as $link): ?>
                    <?= $render_link_row($link) ?>
                <?php endforeach; ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn-alt" data-add-row="reference_link"><?= $h(_('Add link')) ?></button>
            </div>
        </section>

        <section class="ai-card">
            <h2><?= $h(_('Zabbix API')) ?></h2>
            <p class="ai-muted">
                Used for posting webhook output back to problems and for optional OS lookups. A token with the needed host/event permissions is recommended.
            </p>
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
                    <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_api[verify_peer]" value="1" <?= !empty($config['zabbix_api']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Timeout (seconds)')) ?></label>
                    <input class="ai-input" type="number" min="3" max="300" name="zabbix_api[timeout]" value="<?= $h($config['zabbix_api']['timeout'] ?? 15) ?>">
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
            <h2><?= $h(_('NetBox integration')) ?></h2>
            <p class="ai-muted">
                Optional enrichment for VM/device and service context.
            </p>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="netbox[enabled]" value="1" <?= !empty($config['netbox']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Use NetBox context')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Verify TLS')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="netbox[verify_peer]" value="1" <?= !empty($config['netbox']['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Timeout (seconds)')) ?></label>
                    <input class="ai-input" type="number" min="3" max="300" name="netbox[timeout]" value="<?= $h($config['netbox']['timeout'] ?? 10) ?>">
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
            <h2><?= $h(_('Webhook behavior')) ?></h2>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Webhook enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[enabled]" value="1" <?= !empty($config['webhook']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Accept webhook calls')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Add problem update')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[add_problem_update]" value="1" <?= !empty($config['webhook']['add_problem_update']) ? 'checked' : '' ?>> <?= $h(_('Post model output back to the event')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Skip resolved')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[skip_resolved]" value="1" <?= !empty($config['webhook']['skip_resolved']) ? 'checked' : '' ?>> <?= $h(_('Ignore event_value = 0')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Include NetBox')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_netbox]" value="1" <?= !empty($config['webhook']['include_netbox']) ? 'checked' : '' ?>> <?= $h(_('Add NetBox context when enabled')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Include OS hint')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="webhook[include_os_hint]" value="1" <?= !empty($config['webhook']['include_os_hint']) ? 'checked' : '' ?>> <?= $h(_('Look up host OS via Zabbix API')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Problem update action')) ?></label>
                    <input class="ai-input" type="number" min="1" max="256" name="webhook[problem_update_action]" value="<?= $h($config['webhook']['problem_update_action'] ?? 4) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment chunk size')) ?></label>
                    <input class="ai-input" type="number" min="200" max="2000" name="webhook[comment_chunk_size]" value="<?= $h($config['webhook']['comment_chunk_size'] ?? 1900) ?>">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Webhook shared secret')) ?></label>
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
            <h2><?= $h(_('Chat behavior')) ?></h2>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('History messages sent to model')) ?></label>
                    <input class="ai-input" type="number" min="0" max="50" name="chat[max_history_messages]" value="<?= $h($config['chat']['max_history_messages'] ?? 12) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Temperature')) ?></label>
                    <input class="ai-input" type="number" min="0" max="2" step="0.1" name="chat[temperature]" value="<?= $h($config['chat']['temperature'] ?? 0.2) ?>">
                </div>
            </div>
        </section>

        <section class="ai-card">
            <h2><?= $h(_('Zabbix Actions (AI-powered)')) ?></h2>
            <p class="ai-muted">
                When enabled, the AI chat can query and modify Zabbix via natural language.
                Read actions (list problems, host info, unsupported items) are available to all users.
                Write actions require explicit permission per category and can be restricted to Super Admins.
            </p>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Zabbix actions enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[enabled]" value="1" <?= !empty($config['zabbix_actions']['enabled']) ? 'checked' : '' ?>> <?= $h(_('Allow AI to interact with Zabbix')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Mode')) ?></label>
                    <select class="ai-input" name="zabbix_actions[mode]" id="ai-actions-mode">
                        <option value="read" <?= (($config['zabbix_actions']['mode'] ?? 'read') === 'read') ? 'selected' : '' ?>>Read only</option>
                        <option value="readwrite" <?= (($config['zabbix_actions']['mode'] ?? '') === 'readwrite') ? 'selected' : '' ?>>Read &amp; Write</option>
                    </select>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Require Super Admin for write')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[require_super_admin_for_write]" value="1" <?= !empty($config['zabbix_actions']['require_super_admin_for_write']) ? 'checked' : '' ?>> <?= $h(_('Only Super Admins can execute write actions')) ?></label>
                </div>
            </div>

            <div id="ai-write-permissions" class="ai-write-permissions-block" <?= (($config['zabbix_actions']['mode'] ?? 'read') !== 'readwrite') ? 'style="display:none"' : '' ?>>
                <h3 style="margin: 16px 0 8px;"><?= $h(_('Write permissions')) ?></h3>
                <p class="ai-muted" style="margin-bottom: 10px;">
                    Select which write operations the AI is allowed to perform. Each category controls a specific set of Zabbix API actions.
                </p>
                <div class="ai-repeat-grid ai-settings-grid">
                    <div>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][maintenance]" value="1" <?= !empty($config['zabbix_actions']['write_permissions']['maintenance']) ? 'checked' : '' ?>> <?= $h(_('Maintenance windows')) ?></label>
                        <div class="ai-muted" style="font-size: 12px; margin-top: 4px;"><?= $h(_('Create maintenance windows for hosts')) ?></div>
                    </div>
                    <div>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][items]" value="1" <?= !empty($config['zabbix_actions']['write_permissions']['items']) ? 'checked' : '' ?>> <?= $h(_('Items')) ?></label>
                        <div class="ai-muted" style="font-size: 12px; margin-top: 4px;"><?= $h(_('Enable/disable items, change intervals')) ?></div>
                    </div>
                    <div>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][triggers]" value="1" <?= !empty($config['zabbix_actions']['write_permissions']['triggers']) ? 'checked' : '' ?>> <?= $h(_('Triggers')) ?></label>
                        <div class="ai-muted" style="font-size: 12px; margin-top: 4px;"><?= $h(_('Modify trigger expressions, priority, status')) ?></div>
                    </div>
                    <div>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][users]" value="1" <?= !empty($config['zabbix_actions']['write_permissions']['users']) ? 'checked' : '' ?>> <?= $h(_('Users')) ?></label>
                        <div class="ai-muted" style="font-size: 12px; margin-top: 4px;"><?= $h(_('Create new Zabbix users')) ?></div>
                    </div>
                    <div>
                        <label class="ai-checkbox"><input type="checkbox" name="zabbix_actions[write_permissions][problems]" value="1" <?= !empty($config['zabbix_actions']['write_permissions']['problems']) ? 'checked' : '' ?>> <?= $h(_('Problems')) ?></label>
                        <div class="ai-muted" style="font-size: 12px; margin-top: 4px;"><?= $h(_('Acknowledge, close, or comment on problems')) ?></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="ai-form-actions">
            <button type="submit" class="btn"><?= $h(_('Save settings')) ?></button>
        </div>
    </form>

    <template id="ai-provider-template">
        <?= $render_provider_row([
            'id' => '__ROW_ID__',
            'name' => '',
            'type' => 'openai_compatible',
            'enabled' => true,
            'timeout' => 60,
            'endpoint' => '',
            'model' => '',
            'api_key_present' => false,
            'api_key_env' => '',
            'headers_json' => '',
            'verify_peer' => true,
            'default_actions' => false
        ]) ?>
    </template>

    <template id="ai-instruction-template">
        <?= $render_instruction_row([
            'id' => '__ROW_ID__',
            'title' => '',
            'enabled' => true,
            'content' => ''
        ]) ?>
    </template>

    <template id="ai-reference-link-template">
        <?= $render_link_row([
            'id' => '__ROW_ID__',
            'title' => '',
            'enabled' => true,
            'url' => ''
        ]) ?>
    </template>
</div>
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
