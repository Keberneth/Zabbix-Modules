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
            'secret_storage' => [
                // Unsafe compatibility option for isolated development. The
                // environment flag remains an independent server-side override.
                // Pending confirmations always require real encryption.
                'allow_plaintext_secrets' => false
            ],
            'instructions' => [[
                'id' => 'default_firstline_policy',
                'title' => 'Default first-line policy',
                'enabled' => true,
                'sensitive' => false,
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
                'auth_mode' => 'bearer',
                // Interactive controllers normally use the caller's frontend
                // session/RBAC. Split deployments must opt in before reads may
                // fall back to the shared service-token identity.
                'allow_service_token_read_fallback' => false
            ],
            'netbox' => [
                'enabled' => false,
                'url' => '',
                'token' => '',
                'token_env' => '',
                'verify_peer' => true,
                'timeout' => 10,
                // Interactive NetBox records require a sensitive-read
                // confirmation and are never inserted into the first prompt.
                'auto_enrich_chat' => false
            ],
            'webhook' => [
                'enabled' => true,
                'shared_secret' => '',
                'shared_secret_env' => '',
                // Secure by default: the webhook rejects calls unless a valid
                // shared secret is presented. An operator can deliberately opt out
                // by unticking "Require a valid shared secret" in the settings UI
                // (e.g. when the endpoint is already locked down at the network
                // layer). See WebhookHandler::validateSecret().
                'require_secret' => true,
                'add_problem_update' => true,
                'problem_update_action' => 4,
                'comment_chunk_size' => 1900,
                'skip_resolved' => true,
                'include_netbox' => false,
                'include_os_hint' => true
            ],
            'chat' => [
                'max_history_messages' => 12,
                'temperature' => 1.0,
                'item_history_period_hours' => 24,
                'item_history_max_rows' => 50
            ],
            'problem_inline' => [
                'auto_analyze' => true
            ],
            'security' => [
                'enabled' => true,
                'strict_mode' => true,
                'session_ttl_hours' => 12,
                'state_path' => '/tmp/zabbix-ai-module/state',
                'apply_to' => [
                    'chat' => true,
                    'webhook' => true,
                    'action_reads' => true,
                    'action_writes' => true,
                    'action_formatting' => true
                ],
                'categories' => [
                    'zabbix_inventory' => true,
                    'inventory_ttl_seconds' => 300,
                    'hostnames' => false,
                    'ipv4' => true,
                    'ipv6' => true,
                    'fqdns' => true,
                    'urls' => true,
                    'services' => false,
                    'strip_url_query' => false,
                    'os_mode' => 'family_only'
                ],
                'custom_rules' => []
            ],
            'logging' => [
                'enabled' => false,
                'path' => '/tmp/zabbix-ai-module/logs',
                'archive_path' => '/tmp/zabbix-ai-module/archive',
                'archive_enabled' => true,
                'compress_archives' => true,
                'retention_days' => 30,
                'max_payload_chars' => 8000,
                'include_payloads' => false,
                'include_mapping_details' => false,
                'categories' => [
                    'chat' => true,
                    'webhook' => true,
                    'reads' => true,
                    'writes' => true,
                    'translations' => true,
                    'user_activity' => true,
                    'settings_changes' => true,
                    'errors' => true
                ]
            ],
            'zabbix_actions' => [
                'enabled' => true,
                'mode' => 'read',
                'write_permissions' => [
                    'maintenance' => false,
                    'items' => false,
                    'triggers' => false,
                    'users' => false,
                    'problems' => false,
                    'hostgroups' => false,
                    'hosts' => false,
                    'interfaces' => false,
                    'web' => false,
                    'dashboards' => false,
                    'templates' => false,
                    'discovery' => false,
                    'bulk' => false,
                    'sla' => false
                ],
                'require_super_admin_for_write' => true,
                // Privacy confirmations for reads opened from the Problems-page
                // AI drawer. 'off' (shipped) confirms every sensitive read;
                // 'triage' auto-approves only the event-scoped triage subset;
                // 'all' auto-approves every sensitive read on that surface.
                // Writes are never affected by this setting.
                'problem_drawer_auto_reads' => 'off',
                'web_scenario_allowed_origins' => '',
                'bulk_max_hosts' => 25,
                'bulk_max_items' => 100
            ],
            'reports' => [
                'enabled' => true,
                'ttl_seconds' => 3600,
                'delete_after_download' => false,
                'directory' => ''
            ]
        ];
    }

    public static function getModuleRecord(): ?array {
        $result = DBselect(
            'SELECT moduleid,id,relative_path,status,config'
            .' FROM module'
            .' WHERE id='.zbx_dbstr(self::MODULE_ID)
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

        // Inline secrets must be encrypted at rest. Already-encrypted values and
        // env: references are preserved; new plaintext fails closed when the key
        // or crypto backend is unavailable.
        $config = self::encryptSecrets($config);

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
        $plaintext_secret_count = self::countPlaintextInlineSecrets($config);

        foreach ($config['providers'] as &$provider) {
            $provider['api_key_present'] = trim((string) ($provider['api_key'] ?? '')) !== '';
            $provider['api_key'] = '';
            $provider['headers_json_present'] = trim((string) ($provider['headers_json'] ?? '')) !== '';
            // Extra headers commonly carry Authorization/API-key values. Treat
            // the entire JSON object as a write-only secret in the settings UI.
            $provider['headers_json'] = '';
        }
        unset($provider);

        $config['zabbix_api']['token_present'] = trim((string) ($config['zabbix_api']['token'] ?? '')) !== '';
        $config['zabbix_api']['token'] = '';

        $config['netbox']['token_present'] = trim((string) ($config['netbox']['token'] ?? '')) !== '';
        $config['netbox']['token'] = '';

        $config['webhook']['shared_secret_present'] = trim((string) ($config['webhook']['shared_secret'] ?? '')) !== '';
        $config['webhook']['shared_secret'] = '';

        foreach ($config['security']['custom_rules'] as &$rule) {
            $rule['id'] = Util::cleanId($rule['id'] ?? '', 'rule');
        }
        unset($rule);

        // Add runtime status without losing the persisted compatibility policy.
        $configured_plaintext = Util::truthy(
            $config['secret_storage']['allow_plaintext_secrets'] ?? false
        );
        $config['secret_storage'] = array_replace(
            (array) ($config['secret_storage'] ?? []),
            Crypto::status($configured_plaintext)
        );
        $config['secret_storage']['plaintext_secret_count'] = $plaintext_secret_count;

        return $config;
    }

    /** Count legacy/compatibility inline values which are still plaintext. */
    private static function countPlaintextInlineSecrets(array $config): int {
        $count = 0;
        self::applyToSecretFields($config, static function(string $value) use (&$count): string {
            if ($value !== '' && !Crypto::isEncrypted($value)
                    && !SecretReference::isExplicitReference($value)) {
                ++$count;
            }

            return $value;
        });

        return $count;
    }

    public static function buildFromPost(array $post, array $current_config): array {
        $current_config = self::mergeWithDefaults($current_config);
        $new_config = self::defaults();

        $current_plaintext = Util::truthy(
            $current_config['secret_storage']['allow_plaintext_secrets'] ?? false
        );
        $requested_plaintext = Util::truthy(
            $post['secret_storage']['allow_plaintext_secrets'] ?? false
        );
        if ($requested_plaintext && !$current_plaintext
                && !Util::truthy($post['secret_storage']['plaintext_risk_acknowledged'] ?? false)) {
            throw new RuntimeException(
                'Enabling plaintext secret storage requires acknowledging that credentials will be readable '
                .'in the Zabbix database, backups and configuration exports.'
            );
        }
        $new_config['secret_storage']['allow_plaintext_secrets'] = $requested_plaintext;

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
            [$api_key, $api_key_reference] = self::buildSecretInput(
                $api_key_input,
                Util::cleanString($provider['api_key_env'] ?? '', 256),
                (string) ($existing['api_key'] ?? ''),
                $clear_api_key,
                'provider API key'
            );
            $clear_headers = Util::truthy($provider['clear_headers_json'] ?? false);
            $headers_input = Util::cleanMultiline($provider['headers_json'] ?? '', 10000);
            [$headers_json, $headers_reference] = self::buildSecretInput(
                $headers_input,
                Util::cleanString($provider['headers_json_ref'] ?? '', 256),
                (string) ($existing['headers_json'] ?? ''),
                $clear_headers,
                'provider custom headers'
            );
            $endpoint = Util::cleanUrl($provider['endpoint'] ?? '');
            if ($endpoint !== '') {
                Util::assertNoEmbeddedUrlCredentials($endpoint);
            }

            $new_config['providers'][] = [
                'id' => $id,
                'name' => Util::cleanString($provider['name'] ?? '', 128),
                'type' => self::normalizeProviderType($provider['type'] ?? 'openai_compatible'),
                'endpoint' => $endpoint,
                'model' => Util::cleanString($provider['model'] ?? '', 256),
                'api_key' => $api_key,
                // Retain the historical field name for config compatibility;
                // it now accepts env:NAME or file:NAME references.
                'api_key_env' => $api_key_reference,
                'headers_json' => $headers_json,
                'headers_json_ref' => $headers_reference,
                // Current forms always submit an explicit 0/1 value. If an
                // older/crafted form omits it, preserve an existing choice and
                // otherwise default a new provider to certificate validation.
                'verify_peer' => array_key_exists('verify_peer', $provider)
                    ? Util::truthy($provider['verify_peer'])
                    : (array_key_exists('verify_peer', $existing)
                        ? Util::truthy($existing['verify_peer'])
                        : true),
                'timeout' => Util::cleanInt($provider['timeout'] ?? 120, 120, 5, 600),
                'enabled' => Util::truthy($provider['enabled'] ?? false),
                'temperature' => Util::cleanFloat($provider['temperature'] ?? '', -1, 0, 2),
                'max_tokens' => Util::cleanInt($provider['max_tokens'] ?? 0, 0, 0, 128000),
                'num_ctx' => Util::cleanInt($provider['num_ctx'] ?? 0, 0, 0, 1048576)
            ];
        }

        $provider_ids = array_column($new_config['providers'], 'id');

        $default_chat_provider_id = Util::cleanString($post['default_chat_provider_id'] ?? '', 128);
        $default_webhook_provider_id = Util::cleanString($post['default_webhook_provider_id'] ?? '', 128);
        $default_actions_provider_id = Util::cleanString($post['default_actions_provider_id'] ?? '', 128);

        $new_config['default_chat_provider_id'] = in_array($default_chat_provider_id, $provider_ids, true)
            ? $default_chat_provider_id
            : '';
        $new_config['default_webhook_provider_id'] = in_array($default_webhook_provider_id, $provider_ids, true)
            ? $default_webhook_provider_id
            : '';
        $new_config['default_actions_provider_id'] = in_array($default_actions_provider_id, $provider_ids, true)
            ? $default_actions_provider_id
            : '';

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
                'sensitive' => Util::truthy($instruction['sensitive'] ?? false),
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
            try {
                Util::assertNoEmbeddedUrlCredentials($url);
            }
            catch (\Throwable $e) {
                throw new RuntimeException('Reference link URL is unsafe: '.$e->getMessage());
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
        $zabbix_url = Util::cleanUrl($post['zabbix_api']['url'] ?? '');
        $posted_zabbix_reference = Util::cleanString($post['zabbix_api']['token_env'] ?? '', 256);
        $zabbix_credential_changed = $clear_zabbix_token
            || $zabbix_token_input !== ''
            || trim($posted_zabbix_reference) !== trim((string) ($current_zabbix['token_env'] ?? ''));
        $legacy_zabbix_destination_unchanged = !$zabbix_credential_changed
            && trim($zabbix_url) === trim((string) ($current_zabbix['url'] ?? ''));
        [$zabbix_token, $zabbix_token_reference] = self::buildSecretInput(
            $zabbix_token_input,
            $posted_zabbix_reference,
            (string) ($current_zabbix['token'] ?? ''),
            $clear_zabbix_token,
            'Zabbix API token'
        );

        if ($zabbix_url !== '' && !self::isAbsoluteHttpsUrl($zabbix_url)
                && !$legacy_zabbix_destination_unchanged) {
            throw new RuntimeException('Zabbix API URL must be an absolute HTTPS URL without embedded credentials.');
        }
        if (($zabbix_token !== '' || $zabbix_token_reference !== '') && $zabbix_url === ''
                && !$legacy_zabbix_destination_unchanged) {
            throw new RuntimeException('An explicit HTTPS Zabbix API URL is required when a service token is configured.');
        }

        $new_config['zabbix_api'] = [
            'url' => $zabbix_url,
            'token' => $zabbix_token,
            'token_env' => $zabbix_token_reference,
            'verify_peer' => Util::truthy($post['zabbix_api']['verify_peer'] ?? false),
            'timeout' => Util::cleanInt($post['zabbix_api']['timeout'] ?? 15, 15, 3, 300),
            'auth_mode' => self::normalizeAuthMode($post['zabbix_api']['auth_mode'] ?? 'bearer'),
            'allow_service_token_read_fallback' => Util::truthy(
                $post['zabbix_api']['allow_service_token_read_fallback'] ?? false
            )
        ];

        $current_netbox = $current_config['netbox'];
        $clear_netbox_token = Util::truthy($post['netbox']['clear_token'] ?? false);
        $netbox_token_input = Util::cleanString($post['netbox']['token'] ?? '');
        [$netbox_token, $netbox_token_reference] = self::buildSecretInput(
            $netbox_token_input,
            Util::cleanString($post['netbox']['token_env'] ?? '', 256),
            (string) ($current_netbox['token'] ?? ''),
            $clear_netbox_token,
            'NetBox token'
        );
        $new_config['netbox'] = [
            'enabled' => Util::truthy($post['netbox']['enabled'] ?? false),
            // Kept in the stored schema for upgrade compatibility only. The
            // interactive controller intentionally ignores this legacy flag.
            'auto_enrich_chat' => false,
            'url' => Util::cleanUrl($post['netbox']['url'] ?? ''),
            'token' => $netbox_token,
            'token_env' => $netbox_token_reference,
            'verify_peer' => Util::truthy($post['netbox']['verify_peer'] ?? false),
            'timeout' => Util::cleanInt($post['netbox']['timeout'] ?? 10, 10, 3, 300)
        ];
        if ($new_config['netbox']['url'] !== '') {
            Util::assertNoEmbeddedUrlCredentials($new_config['netbox']['url']);
        }

        $current_webhook = $current_config['webhook'];
        $clear_secret = Util::truthy($post['webhook']['clear_shared_secret'] ?? false);
        $secret_input = Util::cleanString($post['webhook']['shared_secret'] ?? '');
        [$webhook_secret, $webhook_secret_reference] = self::buildSecretInput(
            $secret_input,
            Util::cleanString($post['webhook']['shared_secret_env'] ?? '', 256),
            (string) ($current_webhook['shared_secret'] ?? ''),
            $clear_secret,
            'webhook shared secret'
        );
        $new_config['webhook'] = [
            'enabled' => Util::truthy($post['webhook']['enabled'] ?? false),
            'shared_secret' => $webhook_secret,
            'shared_secret_env' => $webhook_secret_reference,
            'require_secret' => Util::truthy($post['webhook']['require_secret'] ?? false),
            'add_problem_update' => Util::truthy($post['webhook']['add_problem_update'] ?? false),
            // Only 4-7 are safe here: the add-message bit (4) optionally combined
            // with close (1) and/or acknowledge (2). Other event.acknowledge bits
            // need parameters the comment path never sends.
            'problem_update_action' => Util::cleanInt($post['webhook']['problem_update_action'] ?? 4, 4, 4, 7),
            'comment_chunk_size' => Util::cleanInt($post['webhook']['comment_chunk_size'] ?? 1900, 1900, 200, 2000),
            'skip_resolved' => Util::truthy($post['webhook']['skip_resolved'] ?? false),
            'include_netbox' => Util::truthy($post['webhook']['include_netbox'] ?? false),
            'include_os_hint' => Util::truthy($post['webhook']['include_os_hint'] ?? false)
        ];

        $new_config['chat'] = [
            'max_history_messages' => Util::cleanInt($post['chat']['max_history_messages'] ?? 12, 12, 0, 50),
            'temperature' => Util::cleanFloat($post['chat']['temperature'] ?? 1.0, 1.0, 0, 2),
            'item_history_period_hours' => Util::cleanInt($post['chat']['item_history_period_hours'] ?? 24, 24, 1, 720),
            'item_history_max_rows' => Util::cleanInt($post['chat']['item_history_max_rows'] ?? 50, 50, 5, 500)
        ];

        $new_config['problem_inline'] = [
            'auto_analyze' => Util::truthy($post['problem_inline']['auto_analyze'] ?? true)
        ];

        $security = $post['security'] ?? [];
        $new_config['security'] = [
            'enabled' => Util::truthy($security['enabled'] ?? false),
            'strict_mode' => Util::truthy($security['strict_mode'] ?? false),
            'session_ttl_hours' => Util::cleanInt($security['session_ttl_hours'] ?? 12, 12, 1, 720),
            'state_path' => self::normalizePathOrDefault($security['state_path'] ?? '', '/tmp/zabbix-ai-module/state'),
            'apply_to' => [
                'chat' => Util::truthy($security['apply_to']['chat'] ?? false),
                'webhook' => Util::truthy($security['apply_to']['webhook'] ?? false),
                'action_reads' => Util::truthy($security['apply_to']['action_reads'] ?? false),
                'action_writes' => Util::truthy($security['apply_to']['action_writes'] ?? false),
                'action_formatting' => Util::truthy($security['apply_to']['action_formatting'] ?? false)
            ],
            'categories' => [
                'zabbix_inventory' => Util::truthy($security['categories']['zabbix_inventory'] ?? false),
                'inventory_ttl_seconds' => Util::cleanInt($security['categories']['inventory_ttl_seconds'] ?? 300, 300, 30, 86400),
                'hostnames' => Util::truthy($security['categories']['hostnames'] ?? false),
                'ipv4' => Util::truthy($security['categories']['ipv4'] ?? false),
                'ipv6' => Util::truthy($security['categories']['ipv6'] ?? false),
                'fqdns' => Util::truthy($security['categories']['fqdns'] ?? false),
                'urls' => Util::truthy($security['categories']['urls'] ?? false),
                'services' => Util::truthy($security['categories']['services'] ?? false),
                'strip_url_query' => Util::truthy($security['categories']['strip_url_query'] ?? false),
                'os_mode' => Util::cleanEnum($security['categories']['os_mode'] ?? 'family_only', ['off', 'family_only', 'full_alias'], 'family_only')
            ],
            'custom_rules' => self::buildCustomRules($security['custom_rules'] ?? [])
        ];

        $logging = $post['logging'] ?? [];
        $new_config['logging'] = [
            'enabled' => Util::truthy($logging['enabled'] ?? false),
            'path' => self::normalizePathOrDefault($logging['path'] ?? '', '/tmp/zabbix-ai-module/logs'),
            'archive_path' => self::normalizePathOrDefault($logging['archive_path'] ?? '', '/tmp/zabbix-ai-module/archive'),
            'archive_enabled' => Util::truthy($logging['archive_enabled'] ?? false),
            'compress_archives' => Util::truthy($logging['compress_archives'] ?? false),
            'retention_days' => Util::cleanInt($logging['retention_days'] ?? 30, 30, 1, 3650),
            'max_payload_chars' => Util::cleanInt($logging['max_payload_chars'] ?? 8000, 8000, 200, 500000),
            'include_payloads' => Util::truthy($logging['include_payloads'] ?? false),
            'include_mapping_details' => Util::truthy($logging['include_mapping_details'] ?? false),
            'categories' => [
                'chat' => Util::truthy($logging['categories']['chat'] ?? false),
                'webhook' => Util::truthy($logging['categories']['webhook'] ?? false),
                'reads' => Util::truthy($logging['categories']['reads'] ?? false),
                'writes' => Util::truthy($logging['categories']['writes'] ?? false),
                'translations' => Util::truthy($logging['categories']['translations'] ?? false),
                'user_activity' => Util::truthy($logging['categories']['user_activity'] ?? false),
                'settings_changes' => Util::truthy($logging['categories']['settings_changes'] ?? false),
                'errors' => Util::truthy($logging['categories']['errors'] ?? false)
            ]
        ];

        $za = $post['zabbix_actions'] ?? [];
        // Unknown/absent values fall back to the safe 'off' level.
        $auto_read_level = (string) ($za['problem_drawer_auto_reads'] ?? 'off');
        if (!in_array($auto_read_level, ['off', 'triage', 'all'], true)) {
            $auto_read_level = 'off';
        }
        $new_config['zabbix_actions'] = [
            'enabled' => Util::truthy($za['enabled'] ?? false),
            'mode' => in_array(($za['mode'] ?? 'read'), ['read', 'readwrite'], true)
                ? ($za['mode'] ?? 'read')
                : 'read',
            'write_permissions' => [
                'maintenance' => Util::truthy($za['write_permissions']['maintenance'] ?? false),
                'items' => Util::truthy($za['write_permissions']['items'] ?? false),
                'triggers' => Util::truthy($za['write_permissions']['triggers'] ?? false),
                'users' => Util::truthy($za['write_permissions']['users'] ?? false),
                'problems' => Util::truthy($za['write_permissions']['problems'] ?? false),
                'hostgroups' => Util::truthy($za['write_permissions']['hostgroups'] ?? false),
                'hosts' => Util::truthy($za['write_permissions']['hosts'] ?? false),
                'interfaces' => Util::truthy($za['write_permissions']['interfaces'] ?? false),
                'web' => Util::truthy($za['write_permissions']['web'] ?? false),
                'dashboards' => Util::truthy($za['write_permissions']['dashboards'] ?? false),
                'templates' => Util::truthy($za['write_permissions']['templates'] ?? false),
                'discovery' => Util::truthy($za['write_permissions']['discovery'] ?? false),
                'bulk' => Util::truthy($za['write_permissions']['bulk'] ?? false),
                'sla' => Util::truthy($za['write_permissions']['sla'] ?? false)
            ],
            'require_super_admin_for_write' => Util::truthy($za['require_super_admin_for_write'] ?? true),
            'problem_drawer_auto_reads' => $auto_read_level,
            'web_scenario_allowed_origins' => Util::cleanMultiline(
                $za['web_scenario_allowed_origins'] ?? '',
                10000
            ),
            'bulk_max_hosts' => Util::cleanInt($za['bulk_max_hosts'] ?? 25, 25, 1, 1000),
            'bulk_max_items' => Util::cleanInt($za['bulk_max_items'] ?? 100, 100, 1, 5000)
        ];

        $current_write_mode_enabled = Util::truthy(
            $current_config['zabbix_actions']['enabled'] ?? false
        ) && (string) ($current_config['zabbix_actions']['mode'] ?? 'read') === 'readwrite';
        $new_write_mode_enabled = $new_config['zabbix_actions']['enabled']
            && $new_config['zabbix_actions']['mode'] === 'readwrite';
        if ($new_write_mode_enabled && !$current_write_mode_enabled && !Crypto::isAvailable()
                && !self::allowsPlaintextSecrets($new_config)) {
            throw new RuntimeException(
                'Read & Write mode requires ZABBIX_AI_ENCRYPTION_KEY or ZABBIX_AI_ENCRYPTION_KEY_FILE '
                .'and an OpenSSL or Sodium backend for encrypted confirmations. For isolated development '
                .'only, enable the warned plaintext compatibility option in the same save to stage '
                .'confirmations unencrypted instead.'
            );
        }

        $reports_post = $post['reports'] ?? [];
        $ttl = (int) ($reports_post['ttl_seconds'] ?? 3600);
        $new_config['reports'] = [
            'enabled' => Util::truthy($reports_post['enabled'] ?? true),
            'ttl_seconds' => max(300, min($ttl, 86400)),
            'delete_after_download' => Util::truthy($reports_post['delete_after_download'] ?? false),
            'directory' => Util::cleanPath($reports_post['directory'] ?? '')
        ];

        return self::mergeWithDefaults($new_config);
    }

    /**
     * Resolve one settings-form secret into either an inline value or a
     * server-side reference. A configured reference always wins fail-closed:
     * the previous inline copy is removed so a missing vault/file value can
     * never silently fall back to a stale database credential.
     *
     * @return array{0: string, 1: string}
     */
    private static function buildSecretInput(
        string $input,
        string $reference,
        string $existing,
        bool $clear,
        string $label
    ): array {
        $input = trim($input);
        $reference = trim($reference);

        // References may also be pasted into the password field for a simple
        // migration path; the dedicated reference field remains visible.
        if (SecretReference::isExplicitReference($input)) {
            $inline_reference = SecretReference::normalize($input);
            if ($reference !== '' && SecretReference::normalize($reference) !== $inline_reference) {
                throw new RuntimeException('Choose only one secret reference for '.$label.'.');
            }
            $reference = $inline_reference;
            $input = '';
        }
        elseif ($reference !== '') {
            $reference = SecretReference::normalize($reference);
        }

        if ($reference !== '') {
            if ($input !== '') {
                throw new RuntimeException('Choose either an inline '.$label.' or a secret reference, not both.');
            }

            return ['', $reference];
        }

        return [$clear ? '' : ($input !== '' ? $input : $existing), ''];
    }

    public static function getProvider(array $config, string $provider_id = '', string $purpose = 'chat'): ?array {
        $config = self::mergeWithDefaults($config);

        if ($provider_id === '') {
            if ($purpose === 'webhook') {
                $provider_id = (string) $config['default_webhook_provider_id'];
            }
            elseif ($purpose === 'actions') {
                $provider_id = (string) ($config['default_actions_provider_id'] ?? '');
            }
            else {
                $provider_id = (string) $config['default_chat_provider_id'];
            }
        }

        foreach ($config['providers'] as $provider) {
            if (($provider['id'] ?? '') === $provider_id
                    && Util::truthy($provider['enabled'] ?? false)) {
                return self::withRuntimeSecretPolicy($provider, $config);
            }
        }

        // An explicitly selected/configured provider is an egress policy, not
        // a hint. If it was removed or disabled, fail closed instead of routing
        // the payload to an unrelated first-enabled provider.
        if ($provider_id !== '') {
            return null;
        }

        foreach ($config['providers'] as $provider) {
            if (Util::truthy($provider['enabled'] ?? false)) {
                return self::withRuntimeSecretPolicy($provider, $config);
            }
        }

        return null;
    }

    /** Exact enabled-provider lookup with no default/first-provider fallback. */
    public static function getProviderByIdExact(array $config, string $provider_id): ?array {
        $provider_id = trim($provider_id);
        if ($provider_id === '') {
            return null;
        }
        $config = self::mergeWithDefaults($config);
        foreach ($config['providers'] as $provider) {
            if ((string) ($provider['id'] ?? '') === $provider_id
                && Util::truthy($provider['enabled'] ?? false)) {
                return self::withRuntimeSecretPolicy($provider, $config);
            }
        }

        return null;
    }

    /** Effective plaintext policy for callers which already hold the config. */
    public static function allowsPlaintextSecrets(array $config): bool {
        return Crypto::allowsPlaintextSecrets()
            || Util::truthy($config['secret_storage']['allow_plaintext_secrets'] ?? false);
    }

    /** Attach request-local policy metadata; this marker is never persisted. */
    private static function withRuntimeSecretPolicy(array $provider, array $config): array {
        // Config can be imported or modified outside this code path. Never
        // trust persisted request-only flags, because _secrets_resolved would
        // otherwise make stored plaintext look like an internally materialized
        // vault snapshot to ProviderClient.
        unset(
            $provider['_secrets_resolved'],
            $provider['_api_key_is_fresh'],
            $provider['_headers_json_is_fresh'],
            $provider['_allow_plaintext_secrets']
        );
        $provider['_allow_plaintext_secrets'] = self::allowsPlaintextSecrets($config);

        return $provider;
    }

    /**
     * Resolve provider credentials once for the current request. This prevents
     * a vault-file rotation between fingerprinting, native-tool turns and the
     * actual HTTP request from changing the credential/header identity midway.
     * The returned snapshot is request-local and must never be persisted.
     */
    public static function resolveProviderSecrets(array $provider): array {
        if (Util::truthy($provider['_secrets_resolved'] ?? false)) {
            return $provider;
        }

        $allow_plaintext = Util::truthy($provider['_allow_plaintext_secrets'] ?? false);
        $provider['api_key'] = self::resolveSecret(
            (string) ($provider['api_key'] ?? ''),
            (string) ($provider['api_key_env'] ?? ''),
            $allow_plaintext
        );
        $provider['headers_json'] = self::resolveSecret(
            (string) ($provider['headers_json'] ?? ''),
            (string) ($provider['headers_json_ref'] ?? ''),
            $allow_plaintext
        );
        $provider['api_key_env'] = '';
        $provider['headers_json_ref'] = '';
        $provider['_secrets_resolved'] = true;

        return $provider;
    }

    /** Non-secret fingerprint of the external destination confirmed by a user. */
    public static function providerEgressFingerprint(array $provider): array {
        $provider = self::resolveProviderSecrets($provider);
        $type = strtolower(trim((string) ($provider['type'] ?? 'openai_compatible')));
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = $type === 'ollama'
                ? 'http://localhost:11434/api/chat'
                : ($type === 'anthropic'
                    ? 'https://api.anthropic.com/v1/messages'
                    : 'https://api.openai.com/v1/chat/completions');
        }
        Util::assertNoEmbeddedUrlCredentials($endpoint);
        // resolveProviderSecrets() already established provenance and took a
        // request-local snapshot. Consume its opaque values verbatim: an API
        // key is allowed to begin with strings such as env:, file: or enc:v1:
        // without triggering a second reference/decryption pass.
        $api_key = (string) ($provider['api_key'] ?? '');
        $headers_json = (string) ($provider['headers_json'] ?? '');

        return [
            'id' => (string) ($provider['id'] ?? ''),
            'name' => (string) ($provider['name'] ?? ''),
            'type' => $type,
            'endpoint' => $endpoint,
            'model' => (string) ($provider['model'] ?? ''),
            'verify_peer' => Util::truthy($provider['verify_peer'] ?? true),
            // Server-only opaque digest: it binds gateway-routing headers and
            // API identity without displaying or persisting their plaintext in
            // the browser/audit preview.
            'auth_headers_hmac' => Crypto::keyedFingerprint(
                $api_key."\0".$headers_json,
                'provider authentication identity',
                Util::truthy($provider['_allow_plaintext_secrets'] ?? false)
            ),
            'custom_headers_configured' => trim($headers_json) !== ''
        ];
    }

    public static function resolveSecret($plain_value, $reference = '', bool $allow_plaintext = false): string {
        $plain_value = trim((string) $plain_value);
        $reference = trim((string) $reference);

        if ($reference === '' && SecretReference::isExplicitReference($plain_value)) {
            $reference = $plain_value;
            $plain_value = '';
        }

        if ($reference !== '') {
            if ($plain_value !== '') {
                throw new RuntimeException(
                    'A secret reference and an inline stored secret are both configured; remove the inline copy and re-save settings.'
                );
            }

            // A configured reference is authoritative. Missing environment or
            // file material fails closed and never falls back to stale DB data.
            return SecretReference::resolve($reference);
        }

        // The stored value may be encrypted at rest (enc:v1:...). Legacy
        // plaintext is readable only while encryption is available (so it can
        // be migrated on save), or under the explicit development-only opt-out.
        if ($plain_value !== '' && !Crypto::isEncrypted($plain_value)
                && !Crypto::isAvailable()
                && !$allow_plaintext
                && !Crypto::allowsPlaintextSecrets()) {
            throw new RuntimeException(
                'A stored plaintext secret was refused. Configure ZABBIX_AI_ENCRYPTION_KEY_FILE '
                .'(or ZABBIX_AI_ENCRYPTION_KEY) and re-save AI Settings to encrypt it. '
                .'You can instead use an env:/file: secret reference. '
                .'For isolated development only, the warned plaintext compatibility option in AI Settings '
                .'or ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS=1 restores legacy behavior.'
            );
        }

        $resolved = Crypto::decrypt($plain_value);
        if (Crypto::isEncrypted($plain_value) && $resolved === '') {
            throw new RuntimeException(
                'An encrypted secret is unavailable. Check ZABBIX_AI_ENCRYPTION_KEY or '
                .'ZABBIX_AI_ENCRYPTION_KEY_FILE and keep the same key on every frontend node.'
            );
        }

        return $resolved;
    }

    /**
     * The exact config fields treated as secrets for encryption-at-rest. Each
     * entry resolves to a string that Crypto::encrypt()/decrypt() acts on.
     */
    private static function applyToSecretFields(array $config, callable $fn): array {
        if (isset($config['providers']) && is_array($config['providers'])) {
            foreach ($config['providers'] as &$provider) {
                if (is_array($provider) && isset($provider['api_key'])) {
                    $provider['api_key'] = $fn((string) $provider['api_key']);
                }
                if (is_array($provider) && isset($provider['headers_json'])) {
                    $provider['headers_json'] = $fn((string) $provider['headers_json']);
                }
            }
            unset($provider);
        }

        if (isset($config['zabbix_api']['token'])) {
            $config['zabbix_api']['token'] = $fn((string) $config['zabbix_api']['token']);
        }

        if (isset($config['netbox']['token'])) {
            $config['netbox']['token'] = $fn((string) $config['netbox']['token']);
        }

        if (isset($config['webhook']['shared_secret'])) {
            $config['webhook']['shared_secret'] = $fn((string) $config['webhook']['shared_secret']);
        }

        return $config;
    }

    /**
     * Encrypt every stored inline secret before persistence. New plaintext
     * secrets fail closed when encryption is unavailable unless a Super Admin
     * has explicitly armed the warned development compatibility policy.
     * References and already-encrypted values are safe to preserve.
     */
    public static function encryptSecrets(array $config): array {
        $allow_plaintext = self::allowsPlaintextSecrets($config);

        return self::applyToSecretFields($config, static function(string $value) use ($allow_plaintext): string {
            if ($value === '' || Crypto::isEncrypted($value) || SecretReference::isExplicitReference($value)) {
                return $value;
            }
            if (!Crypto::isAvailable() && $allow_plaintext) {
                return $value;
            }

            return Crypto::encryptRequired($value, 'module secrets');
        });
    }

    public static function mergeWithDefaults(array $config): array {
        $defaults = self::defaults();
        $merged = $defaults;

        // One-time compatibility migration: older development builds stored
        // webhook NetBox enrichment under netbox.enrich_webhook_host. The
        // canonical setting is webhook.include_netbox; never OR two toggles,
        // because an unchecked control must actually disable data egress.
        if (!array_key_exists('include_netbox', (array) ($config['webhook'] ?? []))
            && array_key_exists('enrich_webhook_host', (array) ($config['netbox'] ?? []))) {
            if (!is_array($config['webhook'] ?? null)) {
                $config['webhook'] = [];
            }
            $config['webhook']['include_netbox'] = Util::truthy(
                $config['netbox']['enrich_webhook_host']
            );
        }

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

        $merged['security']['custom_rules'] = array_values(is_array($merged['security']['custom_rules'] ?? null)
            ? $merged['security']['custom_rules']
            : []);
        unset($merged['netbox']['enrich_webhook_host']);

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

    private static function buildCustomRules($rows): array {
        $rules = [];

        foreach ((array) $rows as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $match = Util::cleanMultiline($rule['match'] ?? '', 1000);
            $replace = Util::cleanMultiline($rule['replace'] ?? '', 1000);
            if ($match === '' || $replace === '') {
                continue;
            }

            $rules[] = [
                'id' => Util::cleanId($rule['id'] ?? '', 'rule'),
                'type' => Util::cleanEnum($rule['type'] ?? 'exact', ['exact', 'regex', 'domain_suffix'], 'exact'),
                'match' => $match,
                'replace' => $replace,
                'enabled' => Util::truthy($rule['enabled'] ?? false)
            ];
        }

        return $rules;
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
            : 'bearer';
    }

    private static function isAbsoluteHttpsUrl(string $url): bool {
        $parts = parse_url($url);
        $valid = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
        if (!$valid) {
            return false;
        }
        try {
            Util::assertNoEmbeddedUrlCredentials($url);
        }
        catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private static function normalizePathOrDefault($value, string $default): string {
        $value = Util::cleanPath($value);

        return $value !== '' ? $value : $default;
    }
}
