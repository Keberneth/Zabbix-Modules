<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\Util;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';

class SettingsSave extends CController {
    use JsonResponse;

    // State-changing POST: framework CSRF stays enabled (UI sends _csrf_token).

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $post = $_POST;

            $store = new CorrelationStore();
            $config = $store->load();
            $settings = (array) ($config['settings'] ?? []);

            $stringKeys = [
                'api_token_env', 'api_auth_mode', 'eval_token_env', 'receiver_host',
                'receiver_discovery_key', 'receiver_state_key_template', 'receiver_context_key_template'
            ];
            foreach ($stringKeys as $key) {
                if (array_key_exists($key, $post)) {
                    $settings[$key] = Util::cleanString($post[$key], 500);
                }
            }

            // API URL must be empty (derive) or a clean http(s) URL.
            if (array_key_exists('api_url', $post)) {
                $url = trim((string) $post['api_url']);
                if ($url === '') {
                    $settings['api_url'] = '';
                }
                else {
                    $clean = Util::cleanUrl($url);
                    if ($clean === '') {
                        throw new \InvalidArgumentException('The Zabbix API URL must be an http(s) URL.');
                    }
                    $settings['api_url'] = $clean;
                }
            }

            // Checkboxes are always present in the settings form, so recompute.
            foreach (['verify_peer', 'push_discovery_every_eval', 'ignore_suppressed', 'ignore_symptoms', 'clear_disabled_rules'] as $key) {
                $settings[$key] = Util::truthy($post[$key] ?? false);
            }

            $settings['timeout'] = Util::cleanInt($post['timeout'] ?? ($settings['timeout'] ?? 15), 15, 3, 120);
            $settings['min_active_seconds'] = Util::cleanInt($post['min_active_seconds'] ?? 0, 0, 0, 86400);
            $settings['problem_update_action'] = Util::cleanInt($post['problem_update_action'] ?? ($settings['problem_update_action'] ?? 4), 4, 1, 256);
            $settings['comment_chunk_size'] = Util::cleanInt($post['comment_chunk_size'] ?? ($settings['comment_chunk_size'] ?? 1900), 1900, 200, 2000);

            if (!in_array((string) ($settings['api_auth_mode'] ?? 'auto'), ['auto', 'bearer', 'auth_property'], true)) {
                $settings['api_auth_mode'] = 'auto';
            }

            // Secrets: control chars stripped so a token can never inject headers.
            $apiTokenInput = Util::stripControlChars(trim((string) ($post['api_token'] ?? '')));
            if ($apiTokenInput !== '') {
                $settings['api_token'] = $apiTokenInput;
            }
            if (Util::truthy($post['clear_api_token'] ?? false)) {
                $settings['api_token'] = '';
            }

            $evalTokenInput = Util::stripControlChars(trim((string) ($post['eval_token'] ?? '')));
            if ($evalTokenInput !== '') {
                $settings['eval_token_hash'] = CorrelationStore::tokenHash($evalTokenInput);
            }
            if (Util::truthy($post['clear_eval_token'] ?? false)) {
                $settings['eval_token_hash'] = '';
            }

            $config['settings'] = $settings;
            $store->save($config);
            $this->jsonResponse(['ok' => true] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save settings.'], 500);
        }
    }
}
