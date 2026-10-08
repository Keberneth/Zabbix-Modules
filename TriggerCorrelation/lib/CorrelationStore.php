<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/Crypto.php';

/**
 * Configuration + rule storage.
 *
 * The authoritative store is the Zabbix `module` DB table row for this module
 * (id "trigger_correlation"), exactly like the reference AI module's
 * Config layer. Keeping config in the database is what makes the module work on
 * split / multi-frontend / Docker installs: every frontend node and the
 * (load-balanced) HTTP-agent eval endpoint read and write the same shared row,
 * and nothing is lost when an ephemeral/read-only frontend container restarts.
 *
 * A pre-database on-disk config.json is imported once, automatically, on first
 * DB-backed load so upgrading single-server installs keeps their rules and
 * secrets. The old local-file path is otherwise used only as a read fallback;
 * the module never silently writes to /tmp anymore.
 *
 * The public surface (defaults(), load(), save(), publicConfig() and the static
 * token/api helpers) is kept stable so the action controllers and the evaluator
 * do not need to know where the data lives.
 */
/** The configuration would exceed the module.config column. Message is user-facing. */
final class ConfigTooLargeException extends \InvalidArgumentException {
}

final class CorrelationStore {

    public const MODULE_ID = 'trigger_correlation';

    /** Leaves headroom under MySQL's 65,535-byte TEXT (the API re-encodes less compactly). */
    private const CONFIG_MAX_BYTES = 58000;

    /**
     * Optional PDO connection for the standalone eval.php entry point, which runs
     * outside the Zabbix frontend (no \DBselect / \API). When set, all DB access
     * goes through it; otherwise the Zabbix frontend DB layer is used.
     */
    private static ?\PDO $pdo = null;

    public static function useDatabase(\PDO $pdo): void {
        self::$pdo = $pdo;
    }

    /**
     * Build a direct PDO connection to the Zabbix database using the frontend's
     * own zabbix.conf.php, for the sessionless eval.php entry point. The Settings
     * self-check calls this too (in-process, as a super-admin) so the real
     * connection error is shown in the UI; eval.php itself only logs the detail
     * and returns a generic message to anonymous callers.
     *
     * Mirrors the connection pattern of the other working modules on this kind of
     * install (AI webhook / Healthcheck DbConnector): it predefines the image
     * constants some configs reference, honours an already-loaded $GLOBALS['DB'],
     * and tries the standard RHEL/Docker config paths. Throws \RuntimeException
     * with a specific, non-credential message on failure.
     */
    public static function connectStandalone(?array $dbConfig = null): \PDO {
        $DB = $dbConfig ?? self::loadFrontendDbConfig();

        $type = strtoupper((string) ($DB['TYPE'] ?? 'MYSQL'));
        // Zabbix uses an empty SERVER to mean "local default"; '' would otherwise
        // produce an invalid 'host=' DSN, so normalise it to localhost.
        $host = trim((string) ($DB['SERVER'] ?? ''));
        if ($host === '') {
            $host = 'localhost';
        }
        $port = (int) ($DB['PORT'] ?? 0);
        $name = (string) ($DB['DATABASE'] ?? '');
        $user = (string) ($DB['USER'] ?? '');
        $pass = (string) ($DB['PASSWORD'] ?? '');
        $schema = (string) ($DB['SCHEMA'] ?? '');
        $encrypt = !empty($DB['ENCRYPTION']);

        if ($name === '') {
            throw new \RuntimeException('The Zabbix database configuration does not contain a database name.');
        }

        $driver = $type === 'POSTGRESQL' ? 'pdo_pgsql' : 'pdo_mysql';
        if (!extension_loaded($driver)) {
            throw new \RuntimeException(
                'The PHP '.$driver.' extension is not installed for this runtime, so the standalone '
                .'eval endpoint cannot open its own database connection. (The frontend uses the non-PDO '
                .'driver, which is why the UI still works.) Install '.$driver.' and reload php-fpm.'
            );
        }

        if ($pass === '' && self::dbUsesVault($DB)) {
            throw new \RuntimeException(
                'Zabbix resolves its database password from a secret vault (HashiCorp/CyberArk), which '
                .'the standalone eval endpoint cannot read. Run the evaluation through the frontend, or '
                .'give the web user database credentials it can use directly.'
            );
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5
        ];

        if ($type === 'POSTGRESQL') {
            $dsn = 'pgsql:host='.$host.';dbname='.$name.($port > 0 ? ';port='.$port : '');
            if ($encrypt) {
                $dsn .= ';sslmode='.(!empty($DB['VERIFY_HOST']) ? 'verify-full' : (!empty($DB['VERIFY_CA']) ? 'verify-ca' : 'require'));
                if (!empty($DB['CA_FILE'])) { $dsn .= ';sslrootcert='.$DB['CA_FILE']; }
                if (!empty($DB['CERT_FILE'])) { $dsn .= ';sslcert='.$DB['CERT_FILE']; }
                if (!empty($DB['KEY_FILE'])) { $dsn .= ';sslkey='.$DB['KEY_FILE']; }
            }
        }
        else {
            // utf8mb4 to match the Zabbix 7 schema (proven on this install by the
            // Healthcheck module's connector).
            $dsn = 'mysql:host='.$host.';dbname='.$name.';charset=utf8mb4'.($port > 0 ? ';port='.$port : '');
            if ($encrypt && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                if (!empty($DB['CA_FILE'])) { $options[\PDO::MYSQL_ATTR_SSL_CA] = (string) $DB['CA_FILE']; }
                if (!empty($DB['CERT_FILE'])) { $options[\PDO::MYSQL_ATTR_SSL_CERT] = (string) $DB['CERT_FILE']; }
                if (!empty($DB['KEY_FILE'])) { $options[\PDO::MYSQL_ATTR_SSL_KEY] = (string) $DB['KEY_FILE']; }
                if (!empty($DB['CIPHER_LIST']) && defined('PDO::MYSQL_ATTR_SSL_CIPHER')) { $options[\PDO::MYSQL_ATTR_SSL_CIPHER] = (string) $DB['CIPHER_LIST']; }
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) { $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !empty($DB['VERIFY_HOST']); }
            }
        }

        $pdo = new \PDO($dsn, $user, $pass, $options);

        if ($type === 'POSTGRESQL' && $schema !== '' && preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
            $pdo->exec('SET search_path TO "'.$schema.'"');
        }

        return $pdo;
    }

    /** Locate and load the frontend DB configuration ($DB) for connectStandalone(). */
    private static function loadFrontendDbConfig(): array {
        // The frontend (and the in-process self-check) already has $DB populated.
        if (isset($GLOBALS['DB']) && is_array($GLOBALS['DB']) && !empty($GLOBALS['DB']['DATABASE'])) {
            return $GLOBALS['DB'];
        }

        // Some installs reference these constants while the config is loaded;
        // predefine them so the require below cannot fatal on an unknown constant.
        foreach (['IMAGE_FORMAT_PNG' => 0, 'IMAGE_FORMAT_JPEG' => 1, 'IMAGE_FORMAT_TEXT' => 2, 'IMAGE_FORMAT_GIF' => 3] as $const => $value) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        $env = getenv('ZABBIX_WEB_CONFIG') ?: getenv('ZABBIX_CONF_PATH');
        $paths = array_values(array_filter([
            is_string($env) && trim($env) !== '' ? trim($env) : null,
            '/etc/zabbix/web/zabbix.conf.php',
            '/etc/zabbix/zabbix.conf.php',
            dirname(__DIR__, 3).'/conf/zabbix.conf.php',
            dirname(__DIR__, 4).'/conf/zabbix.conf.php'
        ]));

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $DB = null;
            require $path;
            if (is_array($DB) && !empty($DB['DATABASE'])) {
                return $DB;
            }
        }

        throw new \RuntimeException(
            'Cannot locate the Zabbix frontend database configuration (zabbix.conf.php). Looked in: '
            .implode(', ', $paths).'.'
        );
    }

    private static function dbUsesVault(array $DB): bool {
        foreach (['VAULT', 'VAULT_URL', 'VAULT_DB_PATH', 'VAULT_CACHE'] as $key) {
            if (!empty($DB[$key])) {
                return true;
            }
        }
        return false;
    }

    public function __construct(?string $path = null) {
        // The legacy constructor accepted an explicit file path. It is retained
        // for signature compatibility but ignored: storage is now the DB row.
        unset($path);
    }

    public function load(): array {
        $defaults = self::defaults();
        $record = self::getModuleRecord();

        if ($record === null) {
            // Module row not present yet (e.g. scanned but not enabled). Fall
            // back to a read-only import of any legacy on-disk config so the UI
            // can still show it; it becomes durable once the module is enabled
            // and settings are saved.
            $file = self::readLegacyFile();

            return $file !== null ? self::mergeRecursiveDistinct($defaults, $file) : $defaults;
        }

        $config = self::mergeRecursiveDistinct($defaults, self::decodeConfig($record['config'] ?? ''));

        // One-time migration: import an existing pre-DB config.json into the DB.
        // Skipped on the sessionless eval.php path (PDO set): load() must stay
        // side-effect-free there so an unauthenticated caller can never trigger a
        // DB write before the token has been verified.
        if (self::$pdo === null && empty($config['storage_initialized']) && self::looksEmpty($config)) {
            $file = self::readLegacyFile();

            if ($file !== null) {
                $config = self::mergeRecursiveDistinct($config, $file);
                $config['migrated_from_file'] = true;

                try {
                    self::persist($record['moduleid'], self::encryptSecrets($config));
                    $config['storage_initialized'] = true;
                }
                catch (\Throwable $e) {
                    // Best-effort: leave unmarked so an authenticated save retries.
                }
            }
        }

        return $config;
    }

    public function save(array $config): void {
        $record = self::getModuleRecord();

        if ($record === null) {
            throw new \RuntimeException(
                'The Trigger Correlation module is not registered in the Zabbix database. '
                .'Enable it in Administration → General → Modules, then try again.'
            );
        }

        $config = self::mergeRecursiveDistinct(self::defaults(), $config);
        $config['storage_initialized'] = true;
        $config = self::encryptSecrets($config);

        self::persist($record['moduleid'], $config);
    }

    /**
     * Per-rule runtime-state field names the evaluator rewrites every run. These
     * are the ONLY keys persisted by saveRuntimeState(); everything else (the
     * user-editable rule definition, settings, secrets) is left exactly as it was
     * read back from the DB, so an evaluation can never clobber a concurrent UI
     * edit.
     */
    private const RUNTIME_KEYS = [
        'last_state', 'last_error', 'last_evaluated', 'last_evaluated_iso', 'last_eventids',
        'last_push_result', 'last_correlation_comment_sig', 'last_correlation_eventid',
        'last_source_comment_sig'
    ];

    /** Runtime fields the severity-escalation evaluator rewrites each run. */
    private const SEVERITY_RUNTIME_KEYS = [
        'last_state', 'last_error', 'last_evaluated', 'last_evaluated_iso',
        'applied', 'last_comment_sig', 'last_targets_count'
    ];

    /**
     * Persist only the per-correlation-rule runtime state produced by an
     * evaluation, without overwriting the rest of the config. See
     * writeRuntimeState() for why (lost-update avoidance).
     *
     * $runtimeByRuleId maps rule id → [runtime field => value].
     */
    public function saveRuntimeState(array $runtimeByRuleId): void {
        $this->writeRuntimeState('rules', self::RUNTIME_KEYS, $runtimeByRuleId);
    }

    /** Same as saveRuntimeState() but for the severity-escalation rule set. */
    public function saveSeverityRuntimeState(array $runtimeByRuleId): void {
        $this->writeRuntimeState('severity_rules', self::SEVERITY_RUNTIME_KEYS, $runtimeByRuleId);
    }

    /**
     * Overlay only the given runtime fields onto the rules in $configKey, without
     * touching anything else.
     *
     * The evaluators and the unattended eval.php run on the Zabbix-server
     * HTTP-agent schedule (typically every minute) and previously wrote the WHOLE
     * config blob back — a load→mutate→save window that overlaps interactive
     * rule/settings edits and silently reverted them (lost update). This re-reads
     * the current row (FOR UPDATE on the standalone PDO path, where eval.php is the
     * frequent writer), overlays only $runtimeKeys onto the rules it still finds by
     * id, and writes that back. Rules added/edited/deleted concurrently are
     * respected: a missing id is skipped, and nothing but runtime fields changes.
     */
    private function writeRuntimeState(string $configKey, array $runtimeKeys, array $runtimeByRuleId): void {
        $runtimeByRuleId = array_filter($runtimeByRuleId, static fn($id): bool => (string) $id !== '', ARRAY_FILTER_USE_KEY);
        if ($runtimeByRuleId === []) {
            return;
        }

        if (self::$pdo !== null) {
            $pdo = self::$pdo;
            $ownTxn = !$pdo->inTransaction();
            if ($ownTxn) {
                $pdo->beginTransaction();
            }
            try {
                $stmt = $pdo->prepare('SELECT moduleid,config FROM module WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => self::MODULE_ID]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $config = self::applyRuntimeState(self::decodeConfig($row['config'] ?? ''), $configKey, $runtimeKeys, $runtimeByRuleId);
                    self::persist($row['moduleid'], $config);
                }
                if ($ownTxn) {
                    $pdo->commit();
                }
            }
            catch (\Throwable $e) {
                if ($ownTxn && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            return;
        }

        // Frontend path (the interactive "Run evaluation now"): re-read the current
        // row under the module-row lock, so the write reflects any edit committed
        // since this request loaded — and cannot slip in between a concurrent
        // save's read and write — and overlay only the runtime fields.
        self::transaction(static function () use ($configKey, $runtimeKeys, $runtimeByRuleId): void {
            $record = self::getModuleRecord();
            if ($record === null) {
                return;
            }
            $config = self::applyRuntimeState(self::decodeConfig($record['config'] ?? ''), $configKey, $runtimeKeys, $runtimeByRuleId);
            self::persist($record['moduleid'], $config);
        });
    }

    /**
     * Write only the given settings keys onto a fresh re-read of the row, like
     * writeRuntimeState(), so a value set during a slow request (e.g. the
     * evaluation-secret hash written alongside the engine host macro) cannot be
     * lost to — or clobber — a concurrent whole-config save. Secrets in $patch
     * are stored in their at-rest form (encrypted API token).
     */
    public function updateSettings(array $patch): void {
        if ($patch === []) {
            return;
        }
        $patch = self::encryptSecrets(['settings' => $patch])['settings'];

        $apply = static function (array $row) use ($patch): void {
            $config = self::decodeConfig($row['config'] ?? '');
            $config['settings'] = array_merge((array) ($config['settings'] ?? []), $patch);
            $config['storage_initialized'] = true;
            self::persist($row['moduleid'], $config);
        };

        if (self::$pdo !== null) {
            $pdo = self::$pdo;
            $ownTxn = !$pdo->inTransaction();
            if ($ownTxn) {
                $pdo->beginTransaction();
            }
            try {
                $stmt = $pdo->prepare('SELECT moduleid,config FROM module WHERE id = :id FOR UPDATE');
                $stmt->execute([':id' => self::MODULE_ID]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $apply($row);
                }
                if ($ownTxn) {
                    $pdo->commit();
                }
            }
            catch (\Throwable $e) {
                if ($ownTxn && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            return;
        }

        self::transaction(static function () use ($apply): void {
            $record = self::getModuleRecord();
            if ($record === null) {
                throw new \RuntimeException('The Trigger Correlation module is not registered in the Zabbix database.');
            }
            $apply($record);
        });
    }

    /**
     * Replace the evaluation secret hash, keeping the previous one valid for a
     * short grace window: the Zabbix server only picks up the engine host's new
     * macro on its next configuration-cache sync, and a heartbeat sent with the
     * old value in between must not be rejected (and turn the item unsupported).
     */
    public function rotateEvalSecret(array &$settings, string $secret): void {
        $patch = [
            'eval_token_hash' => self::tokenHash($secret),
            'eval_token_hash_prev' => (string) ($settings['eval_token_hash'] ?? ''),
            'eval_token_rotated_at' => time()
        ];
        $this->updateSettings($patch);
        $settings = array_merge($settings, $patch);
    }

    /**
     * Overlay runtime fields onto a freshly-decoded config. The stored config is
     * used as-is (the API token stays in its at-rest form — no decrypt/re-encrypt
     * round-trip), and storage_initialized is preserved so the legacy-file import
     * does not re-trigger.
     */
    private static function applyRuntimeState(array $config, string $configKey, array $runtimeKeys, array $runtimeByRuleId): array {
        $rules = array_values((array) ($config[$configKey] ?? []));
        foreach ($rules as $i => $rule) {
            $id = (string) ($rule['id'] ?? '');
            if ($id === '' || !isset($runtimeByRuleId[$id]) || !is_array($runtimeByRuleId[$id])) {
                continue;
            }
            foreach ($runtimeKeys as $key) {
                if (array_key_exists($key, $runtimeByRuleId[$id])) {
                    $rules[$i][$key] = $runtimeByRuleId[$id][$key];
                }
            }
        }
        $config[$configKey] = $rules;
        $config['storage_initialized'] = true;
        return $config;
    }

    public function publicConfig(): array {
        $config = $this->load();
        $settings = (array) ($config['settings'] ?? []);

        $settings['api_token_set'] = self::hasSecret($settings['api_token'] ?? '')
            || self::hasSecretFromEnv($settings['api_token_env'] ?? '');
        $settings['api_token'] = '';
        $settings['eval_token_set'] = self::hasSecret($settings['eval_token_hash'] ?? '')
            || self::hasSecretFromEnv($settings['eval_token_env'] ?? '');
        $settings['eval_token'] = '';
        unset($settings['eval_token_hash'], $settings['eval_token_hash_prev']);

        return [
            // Generic, non-sensitive description only — never the absolute path.
            'storage' => self::storageDescription(),
            'secret_storage' => Crypto::status(),
            'settings' => $settings,
            'rules' => array_values($config['rules'] ?? []),
            'severity_rules' => array_values($config['severity_rules'] ?? [])
        ];
    }

    public static function defaults(): array {
        return [
            'schema_version' => 2,
            'settings' => [
                'api_url' => '',
                'api_token' => '',
                'api_token_env' => 'ZABBIX_TRIGGER_CORRELATION_API_TOKEN',
                'api_auth_mode' => 'auto',
                'verify_peer' => true,
                'timeout' => 15,
                'eval_token_hash' => '',
                'eval_token_env' => 'ZABBIX_TRIGGER_CORRELATION_EVAL_TOKEN',
                'receiver_host' => 'Zabbix Correlation Engine',
                // Automatic setup (see ReceiverProvisioner).
                'eval_url' => '',
                // auto = the module keeps an engine host whose heartbeat calls
                // eval.php; external = you call eval.php yourself (cron/curl).
                'eval_driver' => 'auto',
                // Empty = the engine host's group when one exists (the API token
                // user already has rights there), else "Trigger Correlation".
                'auto_host_group' => '',
                // Deleting a host purges its problem history, so keep by default.
                'auto_delete_hosts' => false,
                'receiver_discovery_key' => 'trigger.correlation.discovery',
                'receiver_state_key_template' => 'trigger.correlation.state[%s]',
                'receiver_context_key_template' => 'trigger.correlation.context[%s]',
                'push_discovery_every_eval' => true,
                'min_active_seconds' => 0,
                'ignore_suppressed' => true,
                'ignore_symptoms' => true,
                'clear_disabled_rules' => true,
                // Problem-comment injection (event.acknowledge).
                'problem_update_action' => 4,
                'comment_chunk_size' => 1900
            ],
            'rules' => [],
            'severity_rules' => []
        ];
    }

    public static function generateId(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function slug(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? $value;
        $value = trim($value, '_.-');
        return $value !== '' ? substr($value, 0, 120) : 'correlation_'.substr(self::generateId(), 0, 8);
    }

    public static function tokenHash(string $token): string {
        return password_hash($token, PASSWORD_DEFAULT);
    }

    public static function verifyToken(array $settings, string $token): bool {
        $token = Util::stripControlChars($token);
        if ($token === '') {
            return false;
        }

        $env_name = trim((string) ($settings['eval_token_env'] ?? ''));
        if ($env_name !== '') {
            $env_value = getenv($env_name);
            if (is_string($env_value) && $env_value !== '') {
                return hash_equals(Util::stripControlChars(trim($env_value)), $token);
            }
        }

        $hash = (string) ($settings['eval_token_hash'] ?? '');
        if ($hash !== '' && password_verify($token, $hash)) {
            return true;
        }

        // Grace window after an automatic rotation (see rotateEvalSecret()).
        $prev = (string) ($settings['eval_token_hash_prev'] ?? '');
        $rotatedAt = (int) ($settings['eval_token_rotated_at'] ?? 0);
        return $prev !== '' && $rotatedAt > 0 && (time() - $rotatedAt) < self::SECRET_GRACE_SECONDS
            && password_verify($token, $prev);
    }

    /** How long the previous evaluation secret stays valid after a rotation. */
    public const SECRET_GRACE_SECONDS = 600;

    public static function apiToken(array $settings): string {
        $env_name = trim((string) ($settings['api_token_env'] ?? ''));
        if ($env_name !== '') {
            $env_value = getenv($env_name);
            if (is_string($env_value) && $env_value !== '') {
                return Util::stripControlChars(trim($env_value));
            }
        }

        $stored = (string) ($settings['api_token'] ?? '');
        // Stored value may be encrypted at rest (enc:v1:...). decrypt() returns
        // plaintext unchanged when no key is configured.
        return Util::stripControlChars(trim(Crypto::decrypt($stored)));
    }

    /** The module's path relative to the frontend root, e.g. "modules/TriggerCorrelation". */
    public static function moduleRelativePath(): string {
        try {
            $record = self::getModuleRecord();
        }
        catch (\Throwable $e) {
            $record = null;
        }
        return trim((string) ($record['relative_path'] ?? ''));
    }

    public static function storageDescription(): string {
        return self::getModuleRecord() !== null
            ? 'Zabbix database (module configuration — shared by every frontend node)'
            : 'Zabbix database (module not registered yet — enable it in Administration → Modules)';
    }

    // ── Internal: DB access ────────────────────────────────────────────────

    private static function getModuleRecord(): ?array {
        if (self::$pdo !== null) {
            $stmt = self::$pdo->prepare('SELECT moduleid,id,relative_path,status,config FROM module WHERE id = :id');
            $stmt->execute([':id' => self::MODULE_ID]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $result = \DBselect(
            'SELECT moduleid,id,relative_path,status,config'
            .' FROM module'
            .' WHERE id='.\zbx_dbstr(self::MODULE_ID)
        );

        $row = \DBfetch($result);

        return $row ?: null;
    }

    private static function persist($moduleid, array $config): void {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            // A bad encode (e.g. malformed UTF-8 in a stored value) must fail
            // loudly: binding false would coerce to '' and blank module.config,
            // wiping every rule and the eval token hash on the next load().
            throw new \RuntimeException('Unable to encode module configuration: '.json_last_error_msg());
        }
        // module.config is a TEXT column (64 KB on MySQL): refuse, with a clear
        // message, a configuration that would be cut off or rejected.
        if (strlen($json) > self::CONFIG_MAX_BYTES) {
            throw new ConfigTooLargeException('The rules no longer fit in the module configuration ('
                .intdiv(strlen($json), 1024).' KB of at most '.intdiv(self::CONFIG_MAX_BYTES, 1024).' KB). '
                .'Remove source triggers or rules — a rule needs one source trigger per host, not every trigger of a group.');
        }

        if (self::$pdo !== null) {
            $stmt = self::$pdo->prepare('UPDATE module SET config = :config WHERE moduleid = :moduleid');
            $stmt->execute([':config' => $json, ':moduleid' => (string) $moduleid]);
            return;
        }

        try {
            // The frontend API wrapper reports errors by returning false (with
            // the message on the frontend message stack), not by throwing.
            $ok = \API::Module()->update([[
                'moduleid' => (string) $moduleid,
                'config' => $config
            ]]);
            if ($ok === false) {
                if (class_exists('\CMessageHelper')) {
                    \CMessageHelper::clear();
                }
                throw new \RuntimeException('module.update failed');
            }
        }
        catch (\Throwable $e) {
            // Sessionless callers (the HTTP-agent eval endpoint) have no
            // authorized user for the API facade; fall back to a direct DB
            // write, which needs no user session. Mirrors the reference module.
            \DB::update('module', [[
                'values' => [
                    'config' => $json
                ],
                'where' => [
                    'moduleid' => $moduleid
                ]
            ]]);
        }
    }

    /**
     * Run $fn as one critical section across every frontend node: a database
     * transaction holding a row lock on this module's config row. The in-process
     * API calls made inside join the transaction (the local API client only opens
     * its own when none is active), so provisioning hosts, saving the config and
     * cleaning up either all happen or none do, and two saves — from two nodes,
     * or a double click — run one after the other instead of creating duplicate
     * hosts or losing each other's rule. eval.php locks the same row for its
     * runtime-state write. Frontend path only.
     */
    public static function transaction(callable $fn) {
        global $DB;

        if (self::$pdo !== null || !function_exists('DBstart')) {
            return $fn();
        }
        // Already inside one (and holding the lock): Zabbix transactions do not nest.
        if (!empty($DB['TRANSACTIONS'])) {
            return $fn();
        }

        \DBstart();
        try {
            // DBselect() reports a lock-wait timeout by returning false, not by
            // throwing — carrying on would run the save without the lock.
            if (\DBselect('SELECT moduleid FROM module WHERE id='.\zbx_dbstr(self::MODULE_ID).' FOR UPDATE') === false) {
                throw new \RuntimeException('The module configuration is locked by another save that is still running. Try again in a moment.');
            }
            $result = $fn();
        }
        catch (\Throwable $e) {
            \DBend(false);
            throw $e;
        }

        // DBend() rolls back (and returns false) when any statement inside failed.
        if (!\DBend(true)) {
            throw new \RuntimeException('The change could not be saved: the database transaction was rolled back.');
        }
        return $result;
    }

    /**
     * Note who drives the evaluation (called by eval.php after a valid call), so
     * automatic setup can tell an external driver (cron/curl) from the engine
     * host's heartbeat and never creates a second driver next to it.
     */
    public function recordDriverCall(string $userAgent): void {
        $this->updateSettings([
            'driver_last_call' => time(),
            'driver_user_agent' => Util::truncate(Util::stripControlChars($userAgent), 120)
        ]);
    }

    private static function decodeConfig($config): array {
        if (is_array($config)) {
            return $config;
        }

        $config = trim((string) $config);
        if ($config === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function encryptSecrets(array $config): array {
        if (isset($config['settings']['api_token'])) {
            $config['settings']['api_token'] = Crypto::encrypt((string) $config['settings']['api_token']);
        }
        return $config;
    }

    // ── Internal: legacy file import (read-only) ───────────────────────────

    private static function readLegacyFile(): ?array {
        foreach (self::legacyPaths() as $path) {
            if ($path === '' || !is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false || trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function legacyPaths(): array {
        $paths = [];
        $env = getenv('ZABBIX_TRIGGER_CORRELATION_CONFIG');
        if (is_string($env) && trim($env) !== '') {
            $paths[] = trim($env);
        }
        $paths[] = '/var/lib/zabbix/modules/trigger-correlation/config.json';
        return $paths;
    }

    private static function looksEmpty(array $config): bool {
        $settings = (array) ($config['settings'] ?? []);
        return empty($config['rules'])
            && trim((string) ($settings['api_url'] ?? '')) === ''
            && trim((string) ($settings['api_token'] ?? '')) === ''
            && trim((string) ($settings['eval_token_hash'] ?? '')) === '';
    }

    private static function hasSecret($value): bool {
        return is_string($value) && trim($value) !== '';
    }

    private static function hasSecretFromEnv($env_name): bool {
        if (!is_string($env_name) || trim($env_name) === '') {
            return false;
        }
        $value = getenv(trim($env_name));
        return is_string($value) && $value !== '';
    }

    private static function mergeRecursiveDistinct(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !self::isList($value)) {
                $base[$key] = self::mergeRecursiveDistinct($base[$key], $value);
            }
            else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function isList(array $array): bool {
        if ($array === []) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }
}
