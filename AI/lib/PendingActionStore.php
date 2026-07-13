<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class PendingActionStore {

    private const PAYLOAD_HASH_VERSION = 'zabbix-ai-confirmation-v2';

    public static function create(array $config, string $server_session_id, array $action, int $ttl_seconds = 1800): string {
        $server_session_id = trim($server_session_id);
        if ($server_session_id === '') {
            throw new RuntimeException('Server session ID is required for pending actions.');
        }

        $action_json = json_encode(
            $action,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($action_json === false) {
            throw new RuntimeException('Could not encode the pending action payload.');
        }

        // Pending actions can contain passwords, macro values, sensitive-read
        // source bindings and write parameters. Unlike long-lived config secrets, they must never fall
        // back to plaintext storage when the encryption key is missing.
        $action_encrypted = Crypto::encryptRequired($action_json, 'pending action payload');

        $id = Util::generateId('action');
        $data = [
            'id' => $id,
            'server_session_hash' => hash('sha256', $server_session_id),
            'created_at' => time(),
            'expires_at' => time() + max(300, $ttl_seconds),
            'action_encrypted' => $action_encrypted
        ];

        Filesystem::writeJsonAtomic(self::path($config, $id), $data);
        self::cleanup($config);

        return $id;
    }

    public static function consume(array $config, string $server_session_id, string $action_id): array {
        return self::consumeAtomic($config, $server_session_id, $action_id, '');
    }

    /**
     * Atomically consume a confirmation-bound write or sensitive-read action.
     *
     * The browser must echo the exact payload hash returned with the server-
     * generated preview. Both the supplied hash and the stored action payload
     * are checked before the action can leave the pending store.
     */
    public static function consumeBound(array $config, string $server_session_id, string $action_id, string $payload_hash, bool $high_impact_confirmed = false): array {
        $payload_hash = strtolower(trim($payload_hash));
        if (!preg_match('/^[a-f0-9]{64}$/D', $payload_hash)) {
            throw new RuntimeException('The confirmation binding is missing or invalid. Review the action again before executing it.');
        }

        return self::consumeAtomic($config, $server_session_id, $action_id, $payload_hash, $high_impact_confirmed);
    }

    public static function load(array $config, string $server_session_id, string $action_id): array {
        $server_session_id = trim($server_session_id);
        $action_id = Util::cleanId($action_id, 'action');

        if ($server_session_id === '' || $action_id === '') {
            throw new RuntimeException('Pending action ID is required.');
        }

        $data = Filesystem::readJson(self::path($config, $action_id));

        if ($data === []) {
            throw new RuntimeException('Pending action not found or already used.');
        }

        if (($data['server_session_hash'] ?? '') !== hash('sha256', $server_session_id)) {
            throw new RuntimeException('Pending action does not belong to this session.');
        }

        if ((int) ($data['expires_at'] ?? 0) < time()) {
            @unlink(self::path($config, $action_id));
            throw new RuntimeException('Pending action expired. Please ask the AI to generate it again.');
        }

        $data['action'] = self::decryptAction($data);

        return $data;
    }

    /**
     * Build the confirmation payload entirely on the server. Model-authored
     * confirmation prose is deliberately ignored: the preview below is derived
     * only from the validated tool name and parameters that will execute.
     */
    public static function buildConfirmation(array $config, string $server_session_id, string $tool_name, array $params, array $observed_state = []): array {
        $canonical_params = self::canonicalize($params);
        $confirmation_state = self::canonicalize($observed_state);
        $payload_hash = self::payloadHash($tool_name, $canonical_params, $confirmation_state);
        $level = self::confirmationLevel($tool_name, $canonical_params);
        $lines = [];

        $lines[] = $level === 'high_impact'
            ? '### High-impact Zabbix change'
            : ($level === 'sensitive_read'
                ? '### Confirm sensitive data read'
                : '### Confirm Zabbix change');
        $lines[] = '';
        if ($level === 'high_impact') {
            $lines[] = '> **Warning:** This is a bulk, destructive, or scope-widening operation. Review every target and value below. The UI requires a second explicit confirmation before execution.';
            $lines[] = '';
        }
        elseif ($level === 'sensitive_read') {
            $lines[] = '> **Privacy check:** This read may retrieve fleet problem/maintenance data, event comments, broad inventory, contact, NetBox, macro or audit data. Unless the result is returned only as a local artifact, it will be sent to the configured AI provider for formatting. Confirm only if that is what you requested.';
            $lines[] = '';
        }

        $lines[] = '- Action: `'.self::markdownCode((string) $tool_name).'`';
        foreach (self::semanticPreviewLines($config, $server_session_id, $tool_name, $canonical_params, $observed_state) as $line) {
            $lines[] = '- '.$line;
        }

        $lines[] = '';
        $lines[] = '**Exact validated parameters**';
        $lines[] = '';
        $safe_params = self::maskSensitiveValues($canonical_params);
        $json = json_encode($safe_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        if ($json === false) {
            throw new RuntimeException('Could not render the confirmation preview.');
        }
        foreach (explode("\n", $json) as $json_line) {
            $lines[] = '    '.$json_line;
        }
        $lines[] = '';
        $lines[] = 'Confirming authorizes only this exact server-staged payload. Any changed action or parameter requires a new preview.';

        return [
            'preview' => implode("\n", $lines),
            'payload_hash' => $payload_hash,
            'level' => $level,
            // Persisted only inside the encrypted pending action. This binds
            // every server-resolved target and live SLA scope shown above to
            // the browser hash echoed at execution time.
            'confirmation_state' => $confirmation_state
        ];
    }

    public static function payloadHash(string $tool_name, array $params, array $confirmation_state = []): string {
        $payload = [
            'tool' => trim($tool_name),
            'params' => self::canonicalize($params),
            'confirmation_state' => self::canonicalize($confirmation_state)
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new RuntimeException('Could not canonicalize the pending action payload.');
        }

        return hash('sha256', self::PAYLOAD_HASH_VERSION."\n".$json);
    }

    /** Stable equality fingerprint for server-resolved confirmation state. */
    public static function stateHash(array $state): string {
        $json = json_encode(
            self::canonicalize($state),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new RuntimeException('Could not canonicalize confirmation state.');
        }

        return hash('sha256', "zabbix-ai-confirmation-state-v1\n".$json);
    }

    public static function cleanup(array $config): void {
        static $cleaned = false;

        if ($cleaned) {
            return;
        }
        $cleaned = true;

        $dir = self::baseDir($config);

        if (!is_dir($dir)) {
            return;
        }

        foreach (Filesystem::safeGlob($dir.'/pending_*.json') as $file) {
            $data = Filesystem::readJson($file);
            if ($data === [] || (int) ($data['expires_at'] ?? 0) < time()) {
                @unlink($file);
            }
        }

        // A process that dies after atomically claiming an action leaves a
        // fail-closed claim file. Remove only stale claims; never restore them,
        // because execution may already have started before the crash.
        foreach (Filesystem::safeGlob($dir.'/pending_*.json.claim_*') as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime <= 0 || $mtime < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private static function consumeAtomic(array $config, string $server_session_id, string $action_id, string $expected_payload_hash, bool $high_impact_confirmed = false): array {
        $server_session_id = trim($server_session_id);
        $action_id = Util::cleanId($action_id, 'action');
        if ($server_session_id === '' || $action_id === '') {
            throw new RuntimeException('Pending action ID is required.');
        }

        $path = self::path($config, $action_id);
        try {
            $claim_suffix = bin2hex(random_bytes(12));
        }
        catch (\Throwable $e) {
            $claim_suffix = preg_replace('/[^A-Za-z0-9_.-]/', '_', Util::generateId('claim'));
        }
        $claim_path = $path.'.claim_'.$claim_suffix;

        // Atomic rename is the one-time-consumption primitive. Exactly one
        // concurrent request can move the source path to its unique claim path;
        // every other request observes the action as already used.
        if (!@rename($path, $claim_path)) {
            throw new RuntimeException('Pending action not found or already used.');
        }

        $restore_on_failure = true;
        try {
            $data = Filesystem::readJson($claim_path);
            if ($data === []) {
                $restore_on_failure = false;
                throw new RuntimeException('Pending action is corrupt or unreadable.');
            }

            if (($data['server_session_hash'] ?? '') !== hash('sha256', $server_session_id)) {
                throw new RuntimeException('Pending action does not belong to this session.');
            }

            if ((int) ($data['expires_at'] ?? 0) < time()) {
                $restore_on_failure = false;
                throw new RuntimeException('Pending action expired. Please ask the AI to generate it again.');
            }

            $action = self::decryptAction($data);

            if ($expected_payload_hash !== '') {
                $tool_name = trim((string) ($action['tool'] ?? ''));
                $params = is_array($action['params'] ?? null) ? $action['params'] : [];
                $actual_confirmation_level = self::confirmationLevel($tool_name, $params);
                if (($action['confirmation_level'] ?? '') !== $actual_confirmation_level) {
                    $restore_on_failure = false;
                    throw new RuntimeException('Pending action failed its confirmation-level integrity check. Generate a new preview.');
                }
                if ($actual_confirmation_level === 'high_impact' && !$high_impact_confirmed) {
                    throw new RuntimeException('This high-impact action requires the additional explicit confirmation step.');
                }

                $stored_hash = strtolower(trim((string) ($action['payload_hash'] ?? '')));
                $confirmation_state = is_array($action['confirmation_state'] ?? null)
                    ? $action['confirmation_state']
                    : [];
                $actual_hash = self::payloadHash($tool_name, $params, $confirmation_state);

                if ($stored_hash === '' || !hash_equals($actual_hash, $stored_hash)) {
                    $restore_on_failure = false;
                    throw new RuntimeException('Pending action failed its server-side integrity check. Generate a new preview.');
                }
                if (!hash_equals($stored_hash, $expected_payload_hash)) {
                    throw new RuntimeException('The confirmed preview does not match the pending action. Review it again before executing.');
                }
            }

            $restore_on_failure = false;
            @unlink($claim_path);
            return $action;
        }
        catch (\Throwable $e) {
            if ($restore_on_failure && !is_file($path)) {
                @rename($claim_path, $path);
            }
            if (is_file($claim_path)) {
                @unlink($claim_path);
            }
            throw $e;
        }
    }

    private static function decryptAction(array $data): array {
        $encrypted = (string) ($data['action_encrypted'] ?? '');
        if ($encrypted === '' || !Crypto::isEncrypted($encrypted)) {
            throw new RuntimeException(
                'Pending action is not encrypted. Generate a new preview after configuring '
                .'ZABBIX_AI_ENCRYPTION_KEY_FILE or ZABBIX_AI_ENCRYPTION_KEY.'
            );
        }
        $plain = Crypto::decryptRequired($encrypted, 'pending action payload');

        $action = json_decode($plain, true);
        if (!is_array($action)) {
            throw new RuntimeException('Pending action payload is invalid.');
        }

        return $action;
    }

    private static function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            return array_map([self::class, 'canonicalize'], array_values($value));
        }

        $normalized = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $normalized[(string) $key] = self::canonicalize($value[$key]);
        }
        return $normalized;
    }

    private static function isList(array $value): bool {
        $index = 0;
        foreach ($value as $key => $_) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private static function confirmationLevel(string $tool_name, array $params): string {
        if (ZabbixActionExecutor::requiresSensitiveReadConfirmation($tool_name)) {
            return 'sensitive_read';
        }

        $high_impact_tools = [
            'apply_bulk_action', 'disable_host', 'disable_lld_rule',
            'mark_problem_as_cause', 'mark_problem_as_symptom',
            'change_problem_severity', 'unacknowledge_problem',
            'suppress_problem', 'create_user', 'unlink_template_from_host',
            'end_maintenance', 'update_host_macros'
        ];
        if (in_array($tool_name, $high_impact_tools, true)) {
            return 'high_impact';
        }
        if (in_array($tool_name, ['create_hostgroup_maintenance', 'create_tag_scoped_maintenance'], true)) {
            return 'high_impact';
        }
        if ($tool_name === 'acknowledge_problem' && (((int) ($params['action'] ?? 0)) & 1) === 1) {
            return 'high_impact';
        }
        if (in_array($tool_name, ['update_trigger', 'update_item'], true)
            && is_array($params['changes'] ?? null)
            && (int) ($params['changes']['status'] ?? 0) === 1) {
            return 'high_impact';
        }
        if ($tool_name === 'update_host_tags'
            && in_array(strtolower(trim((string) ($params['operation'] ?? ''))), ['remove', 'replace'], true)) {
            return 'high_impact';
        }
        if ($tool_name === 'create_sla_service'
            && (Util::truthy($params['allow_shared_service_tag'] ?? false)
                || Util::truthy($params['allow_broad_problem_tags'] ?? false))) {
            return 'high_impact';
        }
        if ($tool_name === 'create_sla'
            && Util::truthy($params['allow_multiple_matching_services'] ?? false)) {
            return 'high_impact';
        }
        foreach (['hostnames', 'group_names', 'groups', 'templates', 'child_serviceids', 'usrgrpids'] as $key) {
            if (is_array($params[$key] ?? null) && count($params[$key]) > 1) {
                return 'high_impact';
            }
        }

        return 'standard';
    }

    private static function semanticPreviewLines(array $config, string $server_session_id, string $tool_name, array $params, array $observed_state): array {
        $lines = [];
        if (trim((string) ($observed_state['target_name'] ?? '')) !== '') {
            $lines[] = 'Resolved target: `'.self::markdownCode((string) $observed_state['target_name']).'`';
        }
        $target_fields = [
            'eventid' => 'Event ID', 'cause_eventid' => 'Cause event ID',
            'trigger_id' => 'Trigger ID', 'item_id' => 'Item ID',
            'maintenance_id' => 'Maintenance ID', 'interfaceid' => 'Interface ID',
            'lld_rule_id' => 'LLD rule ID', 'hostname' => 'Host',
            'group_name' => 'Host group', 'template' => 'Template',
            'username' => 'Username', 'name' => 'Name'
        ];
        foreach ($target_fields as $key => $label) {
            if (array_key_exists($key, $params) && !is_array($params[$key])) {
                $lines[] = $label.': `'.self::markdownCode(self::scalarText($params[$key])).'`';
            }
        }
        foreach ([
            'hostnames' => 'Hosts', 'group_names' => 'Host groups',
            'child_serviceids' => 'Child service IDs', 'usrgrpids' => 'User group IDs',
            'groups' => 'Host groups', 'templates' => 'Templates',
            'tags' => 'Tags', 'macros' => 'Macros',
            'problem_tags' => 'Problem-tag matchers', 'service_tags' => 'Service-tag matchers'
        ] as $key => $label) {
            if (is_array($params[$key] ?? null)) {
                $display_values = self::maskSensitiveValues($params[$key], $key);
                $values = array_map([self::class, 'scalarText'], $display_values);
                $lines[] = $label.' ('.count($values).'): `'.self::markdownCode(implode('`, `', $values)).'`';
            }
        }

        $observed_binary_status = isset($observed_state['values']['status'])
            ? ((string) $observed_state['values']['status'] === '0' ? 'enabled' : 'disabled')
            : '';
        $state_transitions = [
            'enable_host' => 'Host monitoring state: '.($observed_binary_status !== '' ? $observed_binary_status : 'current state').' → enabled',
            'disable_host' => 'Host monitoring state: '.($observed_binary_status !== '' ? $observed_binary_status : 'current state').' → disabled',
            'enable_lld_rule' => 'LLD rule state: '.($observed_binary_status !== '' ? $observed_binary_status : 'current state').' → enabled',
            'disable_lld_rule' => 'LLD rule state: '.($observed_binary_status !== '' ? $observed_binary_status : 'current state').' → disabled',
            'unsuppress_problem' => 'Problem suppression: suppressed → unsuppressed',
            'unacknowledge_problem' => 'Problem acknowledgement: acknowledged → unacknowledged'
        ];
        if (isset($state_transitions[$tool_name])) {
            $lines[] = $state_transitions[$tool_name];
        }
        if ($tool_name === 'suppress_problem') {
            $until = (int) ($params['suppress_until'] ?? 0);
            $lines[] = $until === 0
                ? 'Problem suppression: indefinitely (until manually unsuppressed)'
                : 'Problem suppression until: '.date(DATE_ATOM, $until).' (Unix '.$until.')';
        }

        foreach (['changes' => 'Field changes', 'fields' => 'Inventory changes'] as $key => $label) {
            if (!is_array($params[$key] ?? null)) {
                continue;
            }
            foreach ($params[$key] as $field => $new_value) {
                $before = is_array($observed_state['values'] ?? null)
                    && array_key_exists($field, $observed_state['values'])
                    ? self::scalarText($observed_state['values'][$field])
                    : 'current value';
                $lines[] = $label.' — `'.self::markdownCode((string) $field).'`: `'
                    .self::markdownCode($before).'` → `'.self::markdownCode(self::scalarText($new_value)).'`';
            }
        }

        if (is_array($observed_state['top_level_fields'] ?? null)) {
            foreach ($observed_state['top_level_fields'] as $field) {
                if (!array_key_exists($field, $params)) {
                    continue;
                }
                $before = is_array($observed_state['values'] ?? null)
                    && array_key_exists($field, $observed_state['values'])
                    ? self::scalarText($observed_state['values'][$field])
                    : 'current value';
                $lines[] = 'Field change — `'.self::markdownCode((string) $field).'`: `'
                    .self::markdownCode($before).'` → `'.self::markdownCode(self::scalarText($params[$field])).'`';
            }
        }

        if (!empty($observed_state['values'])) {
            $lines[] = 'Before-values above were read from Zabbix when this preview was staged.';
        }

        if (is_array($observed_state['target_bindings'] ?? null)) {
            foreach ($observed_state['target_bindings'] as $kind => $targets) {
                if (!is_array($targets)) {
                    continue;
                }
                foreach ($targets as $name => $identity) {
                    $identity_text = self::scalarText($identity);
                    $lines[] = 'Frozen '.str_replace('_', ' ', (string) $kind).' target: `'
                        .self::markdownCode((string) $name).'` → `'.self::markdownCode($identity_text).'`';
                }
            }
        }

        if (is_array($observed_state['sla_scope'] ?? null)) {
            $scope = $observed_state['sla_scope'];
            $kind = (string) ($scope['kind'] ?? 'matched');
            $services = is_array($scope['services'] ?? null) ? $scope['services'] : [];
            $label = $kind === 'colliding'
                ? 'Existing services already carrying this `sla_scope` handle'
                : 'Live services this SLA will measure';
            $lines[] = $label.' ('.count($services).'):';
            if (!$services) {
                $lines[] = '  `none`';
            }
            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }
                $lines[] = '  `'.self::markdownCode((string) ($service['name'] ?? ''))
                    .'` (ID `'.self::markdownCode((string) ($service['serviceid'] ?? ''))
                    .'`, algorithm `'.self::markdownCode((string) ($service['algorithm'] ?? '')) .'`)';
            }
        }

        if (is_array($observed_state['provider_egress'] ?? null)) {
            $provider = $observed_state['provider_egress'];
            $lines[] = 'Confirmed AI provider: `'.self::markdownCode((string) ($provider['name'] ?? ''))
                .'` (ID `'.self::markdownCode((string) ($provider['id'] ?? ''))
                .'`, type `'.self::markdownCode((string) ($provider['type'] ?? ''))
                .'`, model `'.self::markdownCode((string) ($provider['model'] ?? '')) .'`)';
            $lines[] = 'Confirmed provider endpoint: `'
                .self::markdownCode(Util::sanitizeUrlForDisplay((string) ($provider['endpoint'] ?? ''))).'`';
            if (!empty($provider['custom_headers_configured'])) {
                $lines[] = 'Provider routing: custom encrypted headers are configured and bound to this confirmation.';
            }
        }

        if (is_array($observed_state['zabbix_read_identity'] ?? null)) {
            $identity = $observed_state['zabbix_read_identity'];
            if (($identity['transport'] ?? '') === 'service_token') {
                $lines[] = 'Zabbix read scope: shared service-token identity at `'
                    .self::markdownCode((string) ($identity['api_url'] ?? '')).'` (not the interactive caller\'s RBAC).';
                $lines[] = 'Zabbix service-token transport: TLS verification `'
                    .(!empty($identity['verify_peer']) ? 'enabled' : 'disabled').'`, auth mode `'
                    .self::markdownCode((string) ($identity['auth_mode'] ?? '')).'`.';
            }
            else {
                $lines[] = 'Zabbix read scope: current frontend user ID `'
                    .self::markdownCode((string) ($identity['userid'] ?? '')).'`.';
            }
        }

        if (is_array($observed_state['zabbix_write_identity'] ?? null)) {
            $identity = $observed_state['zabbix_write_identity'];
            if (($identity['transport'] ?? '') === 'service_token') {
                $lines[] = 'Zabbix write identity: shared service token at `'
                    .self::markdownCode((string) ($identity['api_url'] ?? '')).'`.';
                $lines[] = 'Zabbix write transport: TLS verification `'
                    .(!empty($identity['verify_peer']) ? 'enabled' : 'disabled').'`, auth mode `'
                    .self::markdownCode((string) ($identity['auth_mode'] ?? '')).'`.';
            }
            else {
                $lines[] = 'Zabbix write identity: current frontend user ID `'
                    .self::markdownCode((string) ($identity['userid'] ?? '')).'`.';
            }
        }

        if (is_array($observed_state['netbox_source'] ?? null)) {
            $netbox = $observed_state['netbox_source'];
            $lines[] = 'Confirmed NetBox source: `'
                .self::markdownCode(Util::sanitizeUrlForDisplay((string) ($netbox['url'] ?? '')))
                .'` (credential identity and TLS policy are bound to this confirmation).';
        }

        if ($tool_name === 'create_sla' && Util::truthy($params['allow_multiple_matching_services'] ?? false)) {
            $lines[] = 'SLA scope override: broad OR aggregation across every listed service is permitted.';
        }
        if ($tool_name === 'create_sla_service' && Util::truthy($params['allow_shared_service_tag'] ?? false)) {
            $lines[] = 'SLA handle override: sharing this `sla_scope` value with existing services is permitted.';
        }
        if ($tool_name === 'create_sla_service' && Util::truthy($params['allow_broad_problem_tags'] ?? false)) {
            $lines[] = 'Problem mapping override: broad problem tags that can span hosts or environments are permitted.';
        }

        if ($tool_name === 'end_maintenance') {
            $lines[] = Util::truthy($params['delete'] ?? false)
                ? 'Maintenance: existing → permanently deleted'
                : 'Maintenance end time: current value → now';
        }
        if ($tool_name === 'unlink_template_from_host') {
            $lines[] = Util::truthy($params['clear'] ?? false)
                ? 'Template link: linked → unlinked, inherited entities permanently cleared'
                : 'Template link: linked → unlinked (inherited entities retained)';
        }
        if ($tool_name === 'update_host_tags') {
            $operation = strtolower(trim((string) ($params['operation'] ?? 'add')));
            $lines[] = 'Host tags: current set → `'.self::markdownCode($operation).'` '
                .count(is_array($params['tags'] ?? null) ? $params['tags'] : []).' supplied tag(s)';
        }
        if ($tool_name === 'update_host_macros') {
            $lines[] = 'Host macros: current values → set '
                .count(is_array($params['macros'] ?? null) ? $params['macros'] : []).' supplied macro value(s)';
        }
        if ($tool_name === 'acknowledge_problem') {
            $action = (int) ($params['action'] ?? 0);
            $effects = [];
            if (($action & 1) === 1) {
                $effects[] = 'close problem';
            }
            if (($action & 2) === 2) {
                $effects[] = 'acknowledge problem';
            }
            if (($action & 4) === 4) {
                $effects[] = 'add message';
            }
            $lines[] = 'Event action bitmask `'.$action.'`: '.($effects ? implode(', ', $effects) : 'no recognized effect');
        }
        if ($tool_name === 'apply_bulk_action') {
            $token = trim((string) ($params['preview_token'] ?? ''));
            if ($token !== '') {
                try {
                    $bulk = self::load($config, $server_session_id, $token)['action'] ?? [];
                    if (($bulk['kind'] ?? '') !== 'bulk_preview') {
                        throw new RuntimeException('not a bulk preview');
                    }
                    $ids = is_array($bulk['ids'] ?? null) ? array_values($bulk['ids']) : [];
                    $lines[] = 'Frozen bulk operation: `'.self::markdownCode((string) ($bulk['operation'] ?? '')).'`';
                    $lines[] = 'Frozen targets ('.count($ids).') by ID: `'.self::markdownCode(implode('`, `', array_map([self::class, 'scalarText'], $ids))).'`';
                    if (is_array($bulk['params'] ?? null) && $bulk['params']) {
                        $lines[] = 'Frozen operation values: `'.self::markdownCode(self::scalarText(self::maskSensitiveValues($bulk['params']))).'`';
                    }
                }
                catch (\Throwable $e) {
                    throw new RuntimeException('The bulk preview is unavailable or expired. Run the preview again before confirming.');
                }
            }
        }

        return $lines;
    }

    private static function maskSensitiveValues($value, string $parent_key = '') {
        if (!is_array($value)) {
            return self::isSensitiveKey($parent_key) ? '[secret value supplied]' : $value;
        }

        if ($parent_key === 'macros') {
            $macros = [];
            foreach ($value as $key => $macro) {
                if (is_array($macro) && array_key_exists('value', $macro)
                        && (int) ($macro['type'] ?? 0) !== 0) {
                    $macro['value'] = '[macro value supplied]';
                }
                $macros[$key] = self::maskSensitiveValues($macro, (string) $key);
            }
            return $macros;
        }

        $masked = [];
        foreach ($value as $key => $child) {
            $key_string = (string) $key;
            $masked[$key] = self::isSensitiveKey($key_string)
                ? '[secret value supplied]'
                : self::maskSensitiveValues($child, $key_string);
        }
        return $masked;
    }

    private static function isSensitiveKey(string $key): bool {
        $key = strtolower($key);
        return $key === 'passwd' || $key === 'password' || $key === 'token'
            || $key === 'preview_token' || $key === 'report_token'
            || strpos($key, 'secret') !== false || strpos($key, 'api_key') !== false;
    }

    private static function scalarText($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $json !== false ? $json : '[unrenderable value]';
        }
        return (string) $value;
    }

    private static function markdownCode(string $value): string {
        return str_replace(["\r", "\n", '`'], [' ', ' ', 'ˋ'], $value);
    }

    private static function baseDir(array $config): string {
        $base = RedactionStore::baseDir($config).'/pending';
        Filesystem::ensureDir($base);
        return $base;
    }

    private static function path(array $config, string $action_id): string {
        return self::baseDir($config).'/pending_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $action_id).'.json';
    }
}
