<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use API,
    DB,
    RuntimeException,
    Throwable;

class Config {

    public const MODULE_ID = 'custom_ai';

    public static function defaults(): array {
        return [
            'providers' => [],
            'default_chat_provider_id' => '',
            'default_webhook_provider_id' => '',
            'default_actions_provider_id' => '',
            'instructions' => [[
                'id' => 'default_firstline_policy',
                'title' => 'Default first-line policy',
                'enabled' => true,
                'content' => "You are a first-line troubleshooting assistant.\n\n"
                    ."Absolute rules (must never be broken):\n"
                    ."- Never restart a server, VM, network device, or database cluster.\n"
                    ."- Never reinstall software, services, applications, or operating systems.\n"
                    ."- Use only safe, reversible checks and first-line remediation steps.\n"
                    ."- Gather evidence and, if the quick fix fails, prepare a clean escalation package.\n"
                    ."- Include CMDB/NetBox data if it is relevant to the issue.\n\n"
                    ."Always include:\n"
                    ."1. A quick, safe remediation attempt.\n"
                    ."2. A verification step with expected output.\n"
                    ."3. Evidence-gathering commands and log locations.\n"
                    ."4. Hints for deeper analysis.\n"
                    ."5. Exact artifacts to attach when escalating.\n\n"
                    ."Reply in Markdown and put commands in fenced code blocks."
            ]],
            'reference_links' => [],
            'zabbix_api' => [
                'url' => '',
                'token' => '',
                'token_env' => '',
                'verify_peer' => true,
                'timeout' => 15,
                'auth_mode' => 'auto'
            ],
            'netbox' => [
                'enabled' => false,
                'url' => '',
                'token' => '',
                'token_env' => '',
                'verify_peer' => true,
                'timeout' => 10
            ],
            'webhook' => [
                'enabled' => true,
                'shared_secret' => '',
                'shared_secret_env' => '',
                'add_problem_update' => true,
                'problem_update_action' => 4,
                'comment_chunk_size' => 1900,
                'skip_resolved' => true,
                'include_netbox' => true,
                'include_os_hint' => true
            ],
            'chat' => [
                'max_history_messages' => 12,
                'temperature' => 0.2
            ],
            'zabbix_actions' => [
                'enabled' => true,
                'mode' => 'read',
                'write_permissions' => [
                    'maintenance' => false,
                    'items' => false,
                    'triggers' => false,
                    'users' => false,
                    'problems' => false
                ],
                'require_super_admin_for_write' => true
            ]
        ];
    }

    public static function getModuleRecord(): ?array {
        $result = DBselect(
            'SELECT moduleid,id,relative_path,status,config'.
            ' FROM module'.
            ' WHERE id='.zbx_dbstr(self::MODULE_ID)
        );

        $row = DBfetch($result);

        if (!$row) {
            return null;
        }

        $row['config'] = self::mergeWithDefaults(self::decodeConfig($row['config'] ?? ''));

        return $row;
    }

    public static function get(): array {
        $record = self::getModuleRecord();

        return $record ? $record['config'] : self::defaults();
    }

    public static function save(array $config): void {
        $record = self::getModuleRecord();

        if (!$record) {
            throw new RuntimeException('AI module is not registered in the Zabbix module table.');
        }

        $config = self::mergeWithDefaults($config);

        try {
            API::Module()->update([[
                'moduleid' => $record['moduleid'],
                'config' => $config
            ]]);
        }
        catch (Throwable $e) {
            DB::update('module', [[
                'values' => [
                    'config' => json_encode($config, JSON_THROW_ON_ERROR)
                ],
                'where' => [
                    'moduleid' => $record['moduleid']
                ]
            ]]);
        }
    }

    public static function sanitizeForView(array $config): array {
        $config = self::mergeWithDefaults($config);

        foreach ($config['providers'] as &$provider) {
            $provider['api_key_present'] = trim((string) ($provider['api_key'] ?? '')) !== '';
            $provider['api_key'] = '';
        }
        unset($provider);

        $config['zabbix_api']['token_present'] = trim((string) ($config['zabbix_api']['token'] ?? '')) !== '';
        $config['zabbix_api']['token'] = '';

        $config['netbox']['token_present'] = trim((string) ($config['netbox']['token'] ?? '')) !== '';
        $config['netbox']['token'] = '';

        $config['webhook']['shared_secret_present'] = trim((string) ($config['webhook']['shared_secret'] ?? '')) !== '';
        $config['webhook']['shared_secret'] = '';

        return $config;
    }

    public static function buildFromPost(array $post, array $current_config): array {
        $current_config = self::mergeWithDefaults($current_config);
        $new_config = self::defaults();

        $new_config['providers'] = [];
        $current_providers = self::indexById($current_config['providers']);

        foreach (($post['providers'] ?? []) as $provider) {
            if (!is_array($provider)) {
                continue;
            }

            $is_empty = trim((string) ($provider['name'] ?? '')) === ''
                && trim((string) ($provider['endpoint'] ?? '')) === ''
                && trim((string) ($provider['model'] ?? '')) === '';

            if ($is_empty) {
                continue;
            }

            $id = Util::cleanId($provider['id'] ?? '', 'provider');
            $existing = $current_providers[$id] ?? [];

            $clear_api_key = Util::truthy($provider['clear_api_key'] ?? false);
            $api_key_input = Util::cleanString($provider['api_key'] ?? '');
            $api_key = $clear_api_key
                ? ''
                : (($api_key_input !== '') ? $api_key_input : (string) ($existing['api_key'] ?? ''));

            $new_config['providers'][] = [
                'id' => $id,
                'name' => Util::cleanString($provider['name'] ?? '', 128),
                'type' => self::normalizeProviderType($provider['type'] ?? 'openai_compatible'),
                'endpoint' => Util::cleanUrl($provider['endpoint'] ?? ''),
                'model' => Util::cleanString($provider['model'] ?? '', 256),
                'api_key' => $api_key,
                'api_key_env' => Util::cleanString($provider['api_key_env'] ?? '', 128),
                'headers_json' => Util::cleanMultiline($provider['headers_json'] ?? '', 10000),
                'verify_peer' => Util::truthy($provider['verify_peer'] ?? false),
                'timeout' => Util::cleanInt($provider['timeout'] ?? 60, 60, 5, 300),
                'enabled' => Util::truthy($provider['enabled'] ?? false)
            ];
        }

        $provider_ids = array_column($new_config['providers'], 'id');

        $default_chat_provider_id = Util::cleanString($post['default_chat_provider_id'] ?? '', 128);
        $default_webhook_provider_id = Util::cleanString($post['default_webhook_provider_id'] ?? '', 128);

        $new_config['default_chat_provider_id'] = in_array($default_chat_provider_id, $provider_ids, true)
            ? $default_chat_provider_id
            : '';

        $new_config['default_webhook_provider_id'] = in_array($default_webhook_provider_id, $provider_ids, true)
            ? $default_webhook_provider_id
            : '';

        $default_actions_provider_id = Util::cleanString($post['default_actions_provider_id'] ?? '', 128);
        $new_config['default_actions_provider_id'] = in_array($default_actions_provider_id, $provider_ids, true)
            ? $default_actions_provider_id
            : '';

        if ($new_config['default_chat_provider_id'] === '' && $provider_ids) {
            $new_config['default_chat_provider_id'] = $provider_ids[0];
        }

        if ($new_config['default_webhook_provider_id'] === '' && $provider_ids) {
            $new_config['default_webhook_provider_id'] = $provider_ids[0];
        }

        if ($new_config['default_actions_provider_id'] === '' && $provider_ids) {
            $new_config['default_actions_provider_id'] = $provider_ids[0];
        }

        $new_config['instructions'] = [];
        $current_instructions = self::indexById($current_config['instructions']);

        foreach (($post['instructions'] ?? []) as $instruction) {
            if (!is_array($instruction)) {
                continue;
            }

            $content = Util::cleanMultiline($instruction['content'] ?? '', 50000);

            if ($content === '') {
                continue;
            }

            $id = Util::cleanId($instruction['id'] ?? '', 'instruction');
            $existing = $current_instructions[$id] ?? [];

            $new_config['instructions'][] = [
                'id' => $id,
                'title' => Util::cleanString($instruction['title'] ?? ($existing['title'] ?? ''), 128),
                'enabled' => Util::truthy($instruction['enabled'] ?? false),
                'content' => $content
            ];
        }

        $new_config['reference_links'] = [];
        $current_links = self::indexById($current_config['reference_links']);

        foreach (($post['reference_links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = Util::cleanUrl($link['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $id = Util::cleanId($link['id'] ?? '', 'link');
            $existing = $current_links[$id] ?? [];

            $new_config['reference_links'][] = [
                'id' => $id,
                'title' => Util::cleanString($link['title'] ?? ($existing['title'] ?? ''), 128),
                'url' => $url,
                'enabled' => Util::truthy($link['enabled'] ?? false)
            ];
        }

        $current_zabbix = $current_config['zabbix_api'];
        $clear_zabbix_token = Util::truthy($post['zabbix_api']['clear_token'] ?? false);
        $zabbix_token_input = Util::cleanString($post['zabbix_api']['token'] ?? '');
        $new_config['zabbix_api'] = [
            'url' => Util::cleanUrl($post['zabbix_api']['url'] ?? ''),
            'token' => $clear_zabbix_token
                ? ''
                : (($zabbix_token_input !== '') ? $zabbix_token_input : (string) ($current_zabbix['token'] ?? '')),
            'token_env' => Util::cleanString($post['zabbix_api']['token_env'] ?? '', 128),
            'verify_peer' => Util::truthy($post['zabbix_api']['verify_peer'] ?? false),
            'timeout' => Util::cleanInt($post['zabbix_api']['timeout'] ?? 15, 15, 3, 300),
            'auth_mode' => self::normalizeAuthMode($post['zabbix_api']['auth_mode'] ?? 'auto')
        ];

        $current_netbox = $current_config['netbox'];
        $clear_netbox_token = Util::truthy($post['netbox']['clear_token'] ?? false);
        $netbox_token_input = Util::cleanString($post['netbox']['token'] ?? '');
        $new_config['netbox'] = [
            'enabled' => Util::truthy($post['netbox']['enabled'] ?? false),
            'url' => Util::cleanUrl($post['netbox']['url'] ?? ''),
            'token' => $clear_netbox_token
                ? ''
                : (($netbox_token_input !== '') ? $netbox_token_input : (string) ($current_netbox['token'] ?? '')),
            'token_env' => Util::cleanString($post['netbox']['token_env'] ?? '', 128),
            'verify_peer' => Util::truthy($post['netbox']['verify_peer'] ?? false),
            'timeout' => Util::cleanInt($post['netbox']['timeout'] ?? 10, 10, 3, 300)
        ];

        $current_webhook = $current_config['webhook'];
        $clear_secret = Util::truthy($post['webhook']['clear_shared_secret'] ?? false);
        $secret_input = Util::cleanString($post['webhook']['shared_secret'] ?? '');
        $new_config['webhook'] = [
            'enabled' => Util::truthy($post['webhook']['enabled'] ?? false),
            'shared_secret' => $clear_secret
                ? ''
                : (($secret_input !== '') ? $secret_input : (string) ($current_webhook['shared_secret'] ?? '')),
            'shared_secret_env' => Util::cleanString($post['webhook']['shared_secret_env'] ?? '', 128),
            'add_problem_update' => Util::truthy($post['webhook']['add_problem_update'] ?? false),
            'problem_update_action' => Util::cleanInt($post['webhook']['problem_update_action'] ?? 4, 4, 1, 256),
            'comment_chunk_size' => Util::cleanInt($post['webhook']['comment_chunk_size'] ?? 1900, 1900, 200, 2000),
            'skip_resolved' => Util::truthy($post['webhook']['skip_resolved'] ?? false),
            'include_netbox' => Util::truthy($post['webhook']['include_netbox'] ?? false),
            'include_os_hint' => Util::truthy($post['webhook']['include_os_hint'] ?? false)
        ];

        $new_config['chat'] = [
            'max_history_messages' => Util::cleanInt($post['chat']['max_history_messages'] ?? 12, 12, 0, 50),
            'temperature' => Util::cleanFloat($post['chat']['temperature'] ?? 0.2, 0.2, 0, 2)
        ];

        $za = $post['zabbix_actions'] ?? [];
        $new_config['zabbix_actions'] = [
            'enabled' => Util::truthy($za['enabled'] ?? false),
            'mode' => in_array(($za['mode'] ?? 'read'), ['read', 'readwrite'], true)
                ? $za['mode']
                : 'read',
            'write_permissions' => [
                'maintenance' => Util::truthy($za['write_permissions']['maintenance'] ?? false),
                'items' => Util::truthy($za['write_permissions']['items'] ?? false),
                'triggers' => Util::truthy($za['write_permissions']['triggers'] ?? false),
                'users' => Util::truthy($za['write_permissions']['users'] ?? false),
                'problems' => Util::truthy($za['write_permissions']['problems'] ?? false)
            ],
            'require_super_admin_for_write' => Util::truthy($za['require_super_admin_for_write'] ?? true)
        ];

        return self::mergeWithDefaults($new_config);
    }

    public static function getProvider(array $config, string $provider_id = '', string $purpose = 'chat'): ?array {
        $config = self::mergeWithDefaults($config);

        if ($provider_id === '') {
            if ($purpose === 'webhook') {
                $provider_id = (string) $config['default_webhook_provider_id'];
            } elseif ($purpose === 'actions') {
                $provider_id = (string) ($config['default_actions_provider_id'] ?? '');
                if ($provider_id === '') {
                    $provider_id = (string) $config['default_chat_provider_id'];
                }
            } else {
                $provider_id = (string) $config['default_chat_provider_id'];
            }
        }

        foreach ($config['providers'] as $provider) {
            if (($provider['id'] ?? '') === $provider_id) {
                return $provider;
            }
        }

        foreach ($config['providers'] as $provider) {
            if (Util::truthy($provider['enabled'] ?? false)) {
                return $provider;
            }
        }

        return $config['providers'][0] ?? null;
    }

    public static function resolveSecret($plain_value, $env_name = ''): string {
        $plain_value = trim((string) $plain_value);
        $env_name = trim((string) $env_name);

        if ($env_name === '' && strncmp($plain_value, 'env:', 4) === 0) {
            $env_name = substr($plain_value, 4);
            $plain_value = '';
        }

        if ($env_name !== '') {
            $env_value = getenv($env_name);

            if ($env_value !== false && $env_value !== null) {
                return trim((string) $env_value);
            }
        }

        return $plain_value;
    }

    public static function mergeWithDefaults(array $config): array {
        $defaults = self::defaults();
        $merged = $defaults;

        foreach ($config as $key => $value) {
            if (in_array($key, ['providers', 'instructions', 'reference_links'], true)) {
                $merged[$key] = is_array($value) ? array_values($value) : [];
            }
            elseif (isset($defaults[$key]) && is_array($defaults[$key]) && is_array($value)) {
                $merged[$key] = array_replace_recursive($defaults[$key], $value);
            }
            else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private static function decodeConfig($config): array {
        if (is_array($config)) {
            return $config;
        }

        $config = trim((string) $config);

        if ($config === '') {
            return self::defaults();
        }

        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : self::defaults();
    }

    private static function indexById(array $rows): array {
        $indexed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');

            if ($id !== '') {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    private static function normalizeProviderType($value): string {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['openai_compatible', 'ollama', 'anthropic'], true)
            ? $value
            : 'openai_compatible';
    }

    private static function normalizeAuthMode($value): string {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['auto', 'bearer', 'legacy_auth_field'], true)
            ? $value
            : 'auto';
    }
}
