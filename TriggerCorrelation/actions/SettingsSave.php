<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Actions;

use CController;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\JsonResponse;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\Util;

require_once dirname(__DIR__).'/lib/CorrelationStore.php';
require_once dirname(__DIR__).'/lib/JsonResponse.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

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
            $notes = [];

            // Under the module-row lock, so a concurrent rule save on another
            // frontend node cannot be overwritten by this whole-config save.
            CorrelationStore::transaction(function () use ($store, $post, &$notes): void {
                $config = $store->load();
                $settings = (array) ($config['settings'] ?? []);

                $previousEvalUrl = trim((string) ($settings['eval_url'] ?? ''));

                $stringKeys = [
                    'api_token_env', 'api_auth_mode', 'eval_token_env', 'eval_driver', 'receiver_host', 'auto_host_group',
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

                // Evaluation URL (as the Zabbix server reaches it): empty = derive.
                if (array_key_exists('eval_url', $post)) {
                    $evalUrl = trim((string) $post['eval_url']);
                    if ($evalUrl === '') {
                        $settings['eval_url'] = '';
                    }
                    else {
                        $clean = Util::cleanUrl($evalUrl);
                        if ($clean === '') {
                            throw new \InvalidArgumentException('The evaluation URL must be an http(s) URL.');
                        }
                        $settings['eval_url'] = $clean;
                    }
                }

                // Checkboxes are always present in the settings form, so recompute.
                foreach (['verify_peer', 'push_discovery_every_eval', 'ignore_suppressed', 'ignore_symptoms', 'clear_disabled_rules', 'auto_delete_hosts'] as $key) {
                    $settings[$key] = Util::truthy($post[$key] ?? false);
                }

                $settings['timeout'] = Util::cleanInt($post['timeout'] ?? ($settings['timeout'] ?? 15), 15, 3, 120);
                $settings['min_active_seconds'] = Util::cleanInt($post['min_active_seconds'] ?? 0, 0, 0, 86400);
                $settings['problem_update_action'] = Util::cleanInt($post['problem_update_action'] ?? ($settings['problem_update_action'] ?? 4), 4, 1, 256);
                $settings['comment_chunk_size'] = Util::cleanInt($post['comment_chunk_size'] ?? ($settings['comment_chunk_size'] ?? 1900), 1900, 200, 2000);

                if (!in_array((string) ($settings['api_auth_mode'] ?? 'auto'), ['auto', 'bearer', 'auth_property'], true)) {
                    $settings['api_auth_mode'] = 'auto';
                }
                if (!in_array((string) ($settings['eval_driver'] ?? 'auto'), ['auto', 'external'], true)) {
                    $settings['eval_driver'] = 'auto';
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
                    // Keep the previous secret valid briefly: the engine host's macro
                    // (updated below) reaches the Zabbix server only on its next
                    // configuration-cache sync.
                    $settings['eval_token_hash_prev'] = (string) ($settings['eval_token_hash'] ?? '');
                    $settings['eval_token_rotated_at'] = time();
                    $settings['eval_token_hash'] = CorrelationStore::tokenHash($evalTokenInput);
                    // An admin-chosen secret may be in use elsewhere (cron, curl):
                    // the module never replaces it on its own, only via Repair.
                    $settings['eval_token_managed'] = false;
                }
                if (Util::truthy($post['clear_eval_token'] ?? false)) {
                    $settings['eval_token_hash'] = '';
                    $settings['eval_token_hash_prev'] = '';
                }

                $config['settings'] = $settings;
                $store->save($config);

                // Keep the engine host's macros in step with what was just entered, so
                // the secret and URL never have to be copied over by hand. Only values
                // the user actually changed are written: a macro someone fixed by hand
                // is never overwritten with a derived guess.
                $newUrl = ReceiverProvisioner::effectiveEvalUrl($settings);
                $external = (string) ($settings['eval_driver'] ?? 'auto') === 'external';
                $envName = trim((string) ($settings['eval_token_env'] ?? ''));
                $envSet = $envName !== '' && is_string(getenv($envName)) && getenv($envName) !== '';
                // Copy an explicitly entered URL whenever it changed — even when it
                // equals the derived guess shown as the field's placeholder.
                $explicitUrl = trim((string) ($settings['eval_url'] ?? ''));
                $syncUrl = !$external && $explicitUrl !== '' && $explicitUrl !== $previousEvalUrl ? $newUrl : null;
                // With the secret in an environment variable, eval.php checks only
                // that variable: copying the typed value to the host would break it.
                $syncSecret = (!$external && !$envSet && $evalTokenInput !== '' && !Util::truthy($post['clear_eval_token'] ?? false))
                    ? $evalTokenInput : null;
                if ($envSet && $evalTokenInput !== '') {
                    $notes[] = 'The evaluation secret comes from the '.$envName.' environment variable, which takes precedence — the engine host macro was not changed.';
                }
                if ($syncUrl !== null || $syncSecret !== null) {
                    try {
                        $provisioner = ReceiverProvisioner::forFrontend($settings, $store);
                        $count = $provisioner !== null ? $provisioner->syncEngineMacros($syncUrl, $syncSecret) : 0;
                        if ($count > 0) {
                            $notes[] = sprintf('Updated the %s on the engine host.',
                                $syncUrl !== null && $syncSecret !== null ? 'evaluation URL and secret'
                                    : ($syncSecret !== null ? 'evaluation secret' : 'evaluation URL'));
                        }
                    }
                    catch (\Throwable $e) {
                        error_log('[TriggerCorrelation] engine macro sync failed: '.$e->getMessage());
                        $notes[] = 'Settings saved, but the engine host macros could not be updated: '.Util::truncate($e->getMessage(), 200);
                    }
                }
            });

            $this->jsonResponse(['ok' => true, 'notes' => $notes] + $store->publicConfig());
        }
        catch (\InvalidArgumentException $e) {
            $this->jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('[TriggerCorrelation] settings save failed: '.$e->getMessage());
            $this->jsonResponse(['ok' => false, 'error' => 'Failed to save settings.'], 500);
        }
    }
}
