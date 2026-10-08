<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation\Lib;

require_once __DIR__.'/Util.php';
require_once __DIR__.'/CorrelationStore.php';
require_once __DIR__.'/ZabbixApiClient.php';

/**
 * Creates and maintains the Zabbix objects the rules need, so the user never has
 * to import a template, create a host, link it or set macros by hand.
 *
 *  - The two shipped templates (imported on demand, create-only — an existing
 *    template is never modified, so local customisations survive).
 *  - ONE engine host carrying "Template Trigger Correlation Receiver": its
 *    HTTP-agent heartbeat calls eval.php every minute and evaluates every rule.
 *    Any host that already has the heartbeat item counts as the engine, so an
 *    existing manual setup is reused as-is.
 *  - The evaluation shared secret, which the module owns: generated when none is
 *    configured (or when the heartbeat is being rejected), written to the engine
 *    host's SECRET_TEXT macro, and stored here only as a hash.
 *  - One "automatic correlation host" per distinct SET of source hosts, carrying
 *    the heartbeat-less "Template Trigger Correlation Auto Receiver". It is found
 *    again by its tc.hostset tag, so every rule over the same hosts shares it.
 *
 * Runs only in authenticated, CSRF-protected Super Admin controllers, over the
 * in-process API under that session — never with the stored API token, so that
 * token can stay least-privilege and the audit log names the real admin.
 */
final class ReceiverProvisioner {

    public const ENGINE_TEMPLATE = 'Template Trigger Correlation Receiver';
    public const AUTO_TEMPLATE = 'Template Trigger Correlation Auto Receiver';
    public const HEARTBEAT_KEY = 'trigger.correlation.eval';
    public const DISCOVERY_KEY = 'trigger.correlation.discovery';
    /** Host tag set while an automatic correlation host is used by no rule. */
    public const TAG_UNUSED = 'tc.unused-since';
    /** Grace period before an unused automatic host may be deleted. */
    public const UNUSED_GRACE_SECONDS = 86400;
    /** An external evaluation call this recent means "do not add an engine host". */
    public const DRIVER_EVIDENCE_SECONDS = 900;
    public const DEFAULT_GROUP = 'Trigger Correlation';
    public const DEFAULT_ENGINE = 'Zabbix Correlation Engine';

    /** Host tag marking a host the module created (value "auto" or "engine"). */
    public const TAG_MANAGED = 'trigger-correlation';
    /** Host tag holding the source-host-set key of an automatic correlation host. */
    public const TAG_HOSTSET = 'tc.hostset';

    public const MACRO_URL = '{$TRIGGER.CORRELATION.URL}';
    public const MACRO_TOKEN = '{$TRIGGER.CORRELATION.TOKEN}';

    private const TEMPLATE_FILES = [
        self::ENGINE_TEMPLATE => 'trigger_correlation_receiver_zabbix_7.yaml',
        self::AUTO_TEMPLATE => 'trigger_correlation_auto_receiver_zabbix_7.yaml'
    ];

    private const NAME_MAX = 128;
    private const MACRO_TYPE_TEXT = 0;
    private const MACRO_TYPE_SECRET = 1;
    private const MACRO_TYPE_VAULT = 2;
    private const TASK_CHECK_NOW = 6;

    private ZabbixApiClient $api;
    private array $settings;
    private ?CorrelationStore $store;
    private array $templateIds = [];
    private ?string $groupId = null;
    private ?array $engineCache = null;
    private static ?array $resolvedUrl = null;

    public function __construct(ZabbixApiClient $api, array $settings, ?CorrelationStore $store = null) {
        if (!$api->isFrontend()) {
            throw new \RuntimeException('Automatic setup must run from the Zabbix frontend under a Super Admin session.');
        }
        $this->api = $api;
        $this->settings = $settings;
        $this->store = $store;
    }

    /**
     * Provisioner over the current frontend session, or null outside one. Pass
     * the store whenever the provisioner may create hosts: it records them (see
     * managedIds()), and only recorded hosts are ever written to or deleted.
     */
    public static function forFrontend(array $settings, ?CorrelationStore $store = null): ?self {
        $api = ZabbixApiClient::fromFrontend($settings);
        return $api !== null ? new self($api, $settings, $store) : null;
    }

    /** The settings as updated by this provisioner (managed host ids, secret hash). */
    public function settings(): array {
        return $this->settings;
    }

    /**
     * Hosts this module created (or a Super Admin explicitly handed to it with
     * "Repair"). Anyone with write access to some host group can add an item with
     * the heartbeat key or a tc.hostset tag to a host of their own, so a host is
     * never trusted for what it carries: secrets and URLs are only written to,
     * and hosts only re-used or deleted from, this recorded set.
     */
    public function managedIds(string $kind): array {
        $key = $kind === 'engine' ? 'managed_engine_hostids' : 'managed_auto_hostids';
        return array_values(array_unique(array_filter(array_map('strval', (array) ($this->settings[$key] ?? [])))));
    }

    private function recordManaged(string $kind, string $hostid, bool $add = true): void {
        $key = $kind === 'engine' ? 'managed_engine_hostids' : 'managed_auto_hostids';
        $ids = $this->managedIds($kind);
        $ids = $add ? array_values(array_unique(array_merge($ids, [$hostid]))) : array_values(array_diff($ids, [$hostid]));
        $this->settings[$key] = $ids;
        if ($this->store !== null) {
            $this->store->updateSettings([$key => $ids]);
        }
    }

    /** Stable key of a set of source hosts: order- and duplicate-insensitive. */
    public static function hostsetKey(array $hostids): string {
        return substr(sha1(implode(',', self::normalizeIds($hostids))), 0, 12);
    }

    /** Sorted, de-duplicated, non-empty host ids. */
    public static function normalizeIds(array $hostids): array {
        $ids = [];
        foreach ($hostids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_map('strval', array_keys($ids));
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** Source host ids of a rule's conditions, normalized. */
    public static function conditionHostIds(array $conditions): array {
        return self::normalizeIds(array_map(static fn($c): string => (string) (((array) $c)['hostid'] ?? ''), $conditions));
    }

    /** Technical host name of the automatic correlation host for a set key. */
    public static function autoHostName(string $key): string {
        return 'trigger-correlation-'.$key;
    }

    // ── Evaluation URL ─────────────────────────────────────────────────────

    /**
     * Best guess at the URL the Zabbix SERVER uses to reach eval.php, without
     * asking the API: the explicit setting, else the API URL's scheme/host/port
     * (unless it is a loopback address — fine for the frontend calling itself,
     * but from the server container it would point at the server), else the
     * address of the current request.
     */
    public static function effectiveEvalUrl(array $settings, string $frontendUrl = ''): string {
        $explicit = trim((string) ($settings['eval_url'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $relative = trim(CorrelationStore::moduleRelativePath(), '/');
        if ($relative === '') {
            $relative = 'modules/'.basename(dirname(__DIR__));
        }

        // Administration → General → Other → "Frontend URL", when set.
        $frontendUrl = trim($frontendUrl);
        if ($frontendUrl !== '' && preg_match('#^https?://#i', $frontendUrl)) {
            $base = preg_replace('#/(?:[^/]*\.php)?(?:\?.*)?$#', '', $frontendUrl) ?? $frontendUrl;
            return rtrim($base, '/').'/'.$relative.'/eval.php';
        }

        $api = trim((string) ($settings['api_url'] ?? ''));
        if ($api !== '' && preg_match('#^(https?://.+?)/(?:[^/]*\.php)?$#i', $api, $m) && !self::isLoopbackUrl($api)) {
            return rtrim($m[1], '/').'/'.$relative.'/eval.php';
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/.');

        return ($https ? 'https' : 'http').'://'.$host.$base.'/'.$relative.'/eval.php';
    }

    private static function isLoopbackUrl(string $url): bool {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /** "Frontend URL" from Administration → General → Other ('' when unset). */
    public function frontendUrl(): string {
        try {
            $result = (array) $this->api->call('settings.get', ['output' => ['url']]);
            return trim((string) ($result['url'] ?? ''));
        }
        catch (\Throwable $e) {
            return '';
        }
    }

    // ── Templates & group ──────────────────────────────────────────────────

    /** Template id by technical name, importing the shipped YAML when missing. */
    public function ensureTemplate(string $name): string {
        if (isset($this->templateIds[$name])) {
            return $this->templateIds[$name];
        }

        $id = $this->findTemplateId($name);
        if ($id === '') {
            $file = self::TEMPLATE_FILES[$name] ?? '';
            $path = dirname(__DIR__).'/templates/'.$file;
            $source = ($file !== '' && is_file($path)) ? (string) file_get_contents($path) : '';
            if ($source === '') {
                throw new \RuntimeException('The template file for "'.$name.'" is missing from the module directory.');
            }

            // A template renamed by the user is still ours: find it by UUID
            // rather than importing a second copy next to it.
            $uuid = preg_match('/^    - uuid: ([0-9a-f]{32})\R      template:/m', $source, $m) ? $m[1] : '';
            if ($uuid !== '') {
                $rows = (array) $this->api->call('template.get', ['output' => ['templateid'], 'filter' => ['uuid' => [$uuid]], 'limit' => 1]);
                if ($rows !== []) {
                    return $this->templateIds[$name] = (string) $rows[0]['templateid'];
                }
            }

            // The import resolves the template group by name: if the user renamed
            // our group, point the YAML at its current name.
            if (preg_match('/^  template_groups:\R    - uuid: ([0-9a-f]{32})\R      name: (.+)$/m', $source, $g)) {
                $rows = (array) $this->api->call('templategroup.get', ['output' => ['name'], 'filter' => ['uuid' => [$g[1]]], 'limit' => 1]);
                $current = (string) ($rows[0]['name'] ?? '');
                if ($current !== '' && $current !== trim($g[2], " '\"")) {
                    $quoted = "'".str_replace("'", "''", $current)."'";
                    // Callback, not a replacement string: a backslash or $ in the
                    // group name must be inserted literally.
                    $source = preg_replace_callback('/^(\s+(?:- )?name: )'.preg_quote($g[2], '/').'$/m',
                        static fn(array $m): string => $m[1].$quoted, $source) ?? $source;
                }
            }

            // Create-only: never update/delete anything that already exists.
            $this->api->call('configuration.import', [
                'format' => 'yaml',
                'source' => $source,
                'rules' => [
                    'template_groups' => ['createMissing' => true],
                    'templates' => ['createMissing' => true],
                    'items' => ['createMissing' => true],
                    'discoveryRules' => ['createMissing' => true],
                    'triggers' => ['createMissing' => true],
                    'valueMaps' => ['createMissing' => true]
                ]
            ]);

            $id = $this->findTemplateId($name);
            if ($id === '') {
                throw new \RuntimeException('Importing the template "'.$name.'" did not create it.');
            }
        }

        return $this->templateIds[$name] = $id;
    }

    /**
     * Installs set up by an older version have the engine template's discovery
     * rule on Zabbix's default "disable lost items immediately": the item of a
     * deleted or disabled rule is disabled before the module's clear (0) can be
     * written, and its problem stays open. Switch it to "never disable" (they
     * are still deleted after the rule's lifetime). Returns true when changed.
     */
    public function keepLostItemsEnabled(): bool {
        $templateid = $this->findTemplateId(self::ENGINE_TEMPLATE);
        if ($templateid === '') {
            return false;
        }
        $rules = (array) $this->api->call('discoveryrule.get', [
            'output' => ['itemid', 'enabled_lifetime_type', 'lifetime_type'],
            'templateids' => [$templateid],
            'filter' => ['key_' => [self::DISCOVERY_KEY]]
        ]);
        $changed = false;
        foreach ($rules as $rule) {
            // Only relevant while lost items are kept (lifetime_type 0 = delete after).
            if ((int) ($rule['lifetime_type'] ?? 0) !== 2 && (int) ($rule['enabled_lifetime_type'] ?? 1) !== 1) {
                $this->api->call('discoveryrule.update', [['itemid' => (string) $rule['itemid'], 'enabled_lifetime_type' => 1]]);
                $changed = true;
            }
        }
        return $changed;
    }

    private function findTemplateId(string $name): string {
        $rows = (array) $this->api->call('template.get', [
            'output' => ['templateid'],
            'filter' => ['host' => [$name]],
            'limit' => 1
        ]);
        return (string) ($rows[0]['templateid'] ?? '');
    }

    /**
     * Host group for the hosts the module creates: the configured one, else the
     * existing engine host's group (the API token user already has rights there,
     * so its correlation problems are visible to the same people), else
     * "Trigger Correlation". Created when missing.
     */
    public function ensureGroup(): string {
        if ($this->groupId !== null) {
            return $this->groupId;
        }

        $target = $this->targetGroup();
        $id = $target['groupid'];
        if ($id === '') {
            try {
                $result = (array) $this->api->call('hostgroup.create', [['name' => $target['name']]]);
                $id = (string) ($result['groupids'][0] ?? '');
            }
            catch (\RuntimeException $e) {
                // Lost a race with another frontend creating the same group.
                $id = $this->findGroupId($target['name']);
                if ($id === '') {
                    throw $e;
                }
            }
        }

        return $this->groupId = $id;
    }

    /**
     * The group created hosts go to, WITHOUT creating it (for the read-only
     * self-check): ['groupid' => '' when it does not exist yet, 'name'].
     */
    public function targetGroup(): array {
        $name = trim((string) ($this->settings['auto_host_group'] ?? ''));
        if ($name === '') {
            $engine = $this->managedEngines()[0] ?? null;
            if ($engine !== null) {
                $rows = (array) $this->api->call('host.get', [
                    'output' => ['hostid'],
                    'hostids' => [$engine['hostid']],
                    'selectHostGroups' => ['groupid', 'name']
                ]);
                $group = (array) ($rows[0]['hostgroups'][0] ?? []);
                if ($group !== []) {
                    return ['groupid' => (string) $group['groupid'], 'name' => (string) $group['name']];
                }
            }
            $name = self::DEFAULT_GROUP;
        }
        return ['groupid' => $this->findGroupId($name), 'name' => $name];
    }

    private function findGroupId(string $name): string {
        $rows = (array) $this->api->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => [$name]], 'limit' => 1]);
        return (string) ($rows[0]['groupid'] ?? '');
    }

    /**
     * Which non-Super-Admin user groups can see a host group (Super Admins see
     * everything). A brand-new top-level group inherits no rights, so its
     * correlation problems would reach — and notify — nobody else.
     */
    public function groupVisibility(string $groupid): array {
        $groups = (array) $this->api->call('usergroup.get', [
            'output' => ['usrgrpid', 'name'],
            'selectHostGroupRights' => ['id', 'permission']
        ]);
        $names = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['hostgroup_rights'] ?? []) as $right) {
                // 0 = deny, 2 = read, 3 = read-write.
                if ((string) ($right['id'] ?? '') === $groupid && (int) ($right['permission'] ?? 0) >= 2) {
                    $names[] = (string) $group['name'];
                }
            }
        }
        return $names;
    }

    // ── Engine host (the heartbeat) ────────────────────────────────────────

    /**
     * Every host (not template) carrying the heartbeat item, with that item's
     * state — which proves whether the Zabbix SERVER can reach eval.php.
     */
    public function findEngineHosts(bool $refresh = false): array {
        if ($this->engineCache !== null && !$refresh) {
            return $this->engineCache;
        }

        $items = (array) $this->api->call('item.get', [
            'output' => ['itemid', 'hostid', 'status', 'state', 'error', 'lastclock'],
            'selectHosts' => ['hostid', 'host', 'name', 'status'],
            'filter' => ['key_' => [self::HEARTBEAT_KEY]],
            'templated' => false
        ]);

        $engines = [];
        foreach ($items as $item) {
            $host = (array) ($item['hosts'][0] ?? []);
            $hostid = (string) ($host['hostid'] ?? ($item['hostid'] ?? ''));
            if ($hostid === '') {
                continue;
            }
            $engines[$hostid] = [
                'hostid' => $hostid,
                'host' => (string) ($host['host'] ?? ''),
                'name' => (string) ($host['name'] ?? ''),
                'host_status' => (int) ($host['status'] ?? 0),
                'itemid' => (string) ($item['itemid'] ?? ''),
                'item_status' => (int) ($item['status'] ?? 0),
                'state' => (int) ($item['state'] ?? 0),
                'error' => (string) ($item['error'] ?? ''),
                'lastclock' => (int) ($item['lastclock'] ?? 0)
            ];
        }

        return $this->engineCache = array_values($engines);
    }

    /** True when an engine's heartbeat is being rejected by eval.php's token check. */
    public static function heartbeatRejected(array $engine): bool {
        $error = (string) ($engine['error'] ?? '');
        return (int) ($engine['state'] ?? 0) === 1
            && (stripos($error, 'Invalid evaluation token') !== false || stripos($error, '"401"') !== false);
    }

    /**
     * True when something other than an engine heartbeat called eval.php
     * recently (cron/curl) — then the module must not add a driver of its own.
     */
    public static function externalDriverActive(array $settings): bool {
        $agent = trim((string) ($settings['driver_user_agent'] ?? ''));
        $recent = time() - (int) ($settings['driver_last_call'] ?? 0) < self::DRIVER_EVIDENCE_SECONDS;
        // The Zabbix HTTP agent sends no User-Agent unless the item sets one
        // (the shipped templates send "Zabbix …"); curl, wget and scripting
        // clients always send their own. Only a named non-Zabbix client counts.
        return $recent && $agent !== '' && stripos($agent, 'zabbix') !== 0;
    }

    /** Heartbeat hosts the module manages (see managedIds()), with their item state. */
    public function managedEngines(bool $refresh = false): array {
        $ids = array_flip($this->managedIds('engine'));
        return array_values(array_filter($this->findEngineHosts($refresh), static fn(array $e): bool => isset($ids[$e['hostid']])));
    }

    /**
     * Make sure the engine host exists and that its token macro matches the
     * stored evaluation secret — the one piece users used to get wrong. Only the
     * module's own (recorded) engine is ever written to.
     *
     * Implicitly (a rule save) the engine is only CREATED on an install that has
     * no evaluation secret yet and no heartbeat host at all; anything else — a
     * hand-made engine, a secret an external cron caller may be using — is left
     * alone and the note points to "Repair automatic setup" ($repair = true),
     * which adopts a hand-made engine (the heartbeat host named like Settings →
     * Engine host, or the only heartbeat host) or creates one.
     *
     * Only a hash is stored, so the module owns the secret and its plaintext never
     * leaves this method (no notes, logs or responses):
     *   - a secret from the environment variable is always the one used (and its
     *     hash stored, for frontend nodes that lack the variable);
     *   - otherwise a new one is generated and written to every managed engine
     *     before its hash is stored when none is configured, when the engine was
     *     just created or adopted, or when the heartbeat is refused with "Invalid
     *     evaluation token" and the secret is one the module generated (a secret
     *     an admin typed may be in use elsewhere — only Repair replaces it);
     *   - the previous secret stays valid for a few minutes (rotateEvalSecret)
     *     until the Zabbix server has picked up the new macro.
     *
     * Returns human-readable notes describing what changed or needs attention.
     */
    public function ensureEngineAndSecret(CorrelationStore $store, array &$settings, bool $repair = false): array {
        $this->settings = $settings;
        $this->store = $store;
        if ((string) ($settings['eval_driver'] ?? 'auto') === 'external') {
            return [];
        }

        $notes = [];
        $engines = $this->managedEngines();
        $fresh = false;

        if ($engines === []) {
            $heartbeats = $this->findEngineHosts();
            $envSecret = self::envSecret($settings);
            $hasSecret = trim((string) ($settings['eval_token_hash'] ?? '')) !== '' || $envSecret !== '';

            if ($heartbeats !== []) {
                $adopt = $repair ? $this->adoptableEngine($heartbeats) : null;
                if ($adopt === null) {
                    $names = implode(', ', array_map(static fn(array $e): string => '“'.($e['name'] ?: $e['host']).'”', $heartbeats));
                    $settings = $this->settings;
                    return [$repair
                        ? 'Several hosts carry the evaluator heartbeat ('.$names.') and none is named like Settings → Engine host, so none was taken over. Keep one, or enter its name as Engine host, and repair again.'
                        : 'Found an existing engine host '.$names.'. Click “Repair automatic setup” (Settings) to let the module manage its evaluation secret and URL.'];
                }
                $this->recordManaged('engine', $adopt['hostid']);
                $notes[] = 'The module now manages the engine host “'.($adopt['name'] ?: $adopt['host']).'”.';
            }
            elseif (!$repair && ($hasSecret || self::externalDriverActive($settings))) {
                $settings = $this->settings;
                return ['There is no engine host. If nothing else calls eval.php, click “Repair automatic setup” (Settings) to create one — it sets a new evaluation secret.'];
            }
            else {
                $engine = $this->createEngine();
                $this->recordManaged('engine', $engine['hostid']);
                $notes[] = 'Created the engine host “'.$engine['name'].'” — it runs the evaluation once a minute.';
                if ($engine['renamed']) {
                    $notes[] = 'A host named “'.$engine['wanted'].'” already exists, so the engine host got another name.';
                }
                $notes[] = $engine['url_note'];
            }
            $fresh = true;
            $engines = $this->managedEngines(true);
        }

        if ($this->engineTokenIsVault($engines)) {
            $settings = $this->settings;
            return array_merge($notes, ['The engine host takes its evaluation secret from a secret vault, so the module left it alone.']);
        }

        $envName = trim((string) ($settings['eval_token_env'] ?? ''));
        $envSecret = self::envSecret($settings);
        $rejected = array_values(array_filter($engines, static fn(array $e): bool => self::heartbeatRejected($e)));
        $noSecret = trim((string) ($settings['eval_token_hash'] ?? '')) === '';
        $mayReplace = $repair || !empty($settings['eval_token_managed']);

        if ($envSecret !== '') {
            if ($fresh || ($rejected !== [] && $mayReplace)) {
                $this->setTokenOnEngines($engines, $envSecret);
                $notes[] = 'Set the evaluation secret from the '.$envName.' environment variable on the engine host.';
            }
            $stored = (string) ($settings['eval_token_hash'] ?? '');
            if ($stored === '' || !password_verify($envSecret, $stored)) {
                $store->rotateEvalSecret($settings, $envSecret);
            }
        }
        elseif ($noSecret || $fresh || ($rejected !== [] && $mayReplace)) {
            $secret = bin2hex(random_bytes(24));
            $this->setTokenOnEngines($engines, $secret);
            $store->rotateEvalSecret($settings, $secret);
            $store->updateSettings(['eval_token_managed' => true]);
            $settings['eval_token_managed'] = true;
            $notes[] = $rejected !== [] && !$fresh
                ? 'The engine host was being refused (“Invalid evaluation token”), so it was given a new evaluation secret.'
                : 'Generated the evaluation shared secret and set it on the engine host.';
        }
        elseif ($rejected !== []) {
            $notes[] = 'The engine host is being refused by eval.php (“Invalid evaluation token”). Use “Repair automatic setup” in Settings.';
        }

        $settings = array_merge($settings, [
            'managed_engine_hostids' => $this->managedIds('engine'),
            'managed_auto_hostids' => $this->managedIds('auto')
        ]);
        $this->settings = $settings;
        return array_values(array_filter($notes, static fn($n): bool => is_string($n) && $n !== ''));
    }

    /**
     * The hand-made engine a Super Admin's Repair may take over: the heartbeat
     * host named like Settings → Engine host, else the only heartbeat host.
     */
    private function adoptableEngine(array $heartbeats): ?array {
        $wanted = trim((string) ($this->settings['receiver_host'] ?? '')) ?: self::DEFAULT_ENGINE;
        foreach ($heartbeats as $engine) {
            if ($engine['host'] === $wanted) {
                return $engine;
            }
        }
        return count($heartbeats) === 1 ? $heartbeats[0] : null;
    }

    private static function envSecret(array $settings): string {
        $envName = trim((string) ($settings['eval_token_env'] ?? ''));
        $envValue = $envName !== '' ? getenv($envName) : false;
        return is_string($envValue) ? Util::stripControlChars(trim($envValue)) : '';
    }

    private function setTokenOnEngines(array $engines, string $secret): void {
        foreach ($engines as $engine) {
            $this->setHostMacro($engine['hostid'], self::MACRO_TOKEN, $secret, self::MACRO_TYPE_SECRET,
                'Evaluation shared secret. Managed by the Trigger Correlation module.');
        }
    }

    /** A token macro of type Vault secret is the user's — never overwrite it. */
    private function engineTokenIsVault(array $engines): bool {
        if ($engines === []) {
            return false;
        }
        $rows = (array) $this->api->call('usermacro.get', [
            'output' => ['type'],
            'hostids' => array_column($engines, 'hostid'),
            'filter' => ['macro' => [self::MACRO_TOKEN]]
        ]);
        foreach ($rows as $row) {
            if ((int) ($row['type'] ?? 0) === self::MACRO_TYPE_VAULT) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create the engine host. A host that already has the configured name is
     * never taken over (it could be anyone's); the engine gets a distinct name.
     */
    private function createEngine(): array {
        $templateid = $this->ensureTemplate(self::ENGINE_TEMPLATE);
        $wanted = trim((string) ($this->settings['receiver_host'] ?? '')) ?: self::DEFAULT_ENGINE;
        $technical = $wanted;
        $name = $wanted;
        $renamed = false;

        // The technical name allows only [0-9a-zA-Z_. -]; the visible name may
        // carry the explanation. Both must be unique.
        for ($i = 0; $i < 5; $i++) {
            $takenHost = (array) $this->api->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => [$technical]], 'limit' => 1]);
            $takenName = (array) $this->api->call('host.get', ['output' => ['hostid'], 'filter' => ['name' => [$name]], 'limit' => 1]);
            if ($takenHost === [] && $takenName === []) {
                break;
            }
            $suffix = $i > 0 ? ' '.($i + 1) : '';
            $technical = 'trigger-correlation-engine'.($i > 0 ? '-'.($i + 1) : '');
            $name = Util::truncate($wanted.' (Trigger Correlation'.$suffix.')', self::NAME_MAX);
            $renamed = true;
        }

        $groupid = $this->ensureGroup();
        $result = (array) $this->api->call('host.create', [[
            'host' => $technical,
            'name' => $name,
            'groups' => [['groupid' => $groupid]],
            'templates' => [['templateid' => $templateid]],
            'tags' => [['tag' => self::TAG_MANAGED, 'value' => 'engine']],
            'description' => 'Created automatically by the Trigger Correlation module. Its "Trigger correlation '
                .'evaluator heartbeat" item calls the module once a minute to evaluate every correlation and '
                .'severity-escalation rule. Keep exactly one such host.'
        ]]);
        $hostid = (string) ($result['hostids'][0] ?? '');
        $adopted = false;

        $resolved = $this->resolveEvalUrl();
        $this->setHostMacro($hostid, self::MACRO_URL, $resolved['url'], self::MACRO_TYPE_TEXT,
            'URL of the module evaluation endpoint, as reachable FROM the Zabbix server. Managed by the Trigger Correlation module (Settings → Evaluation URL).');

        return ['hostid' => $hostid, 'name' => $name, 'wanted' => $wanted, 'adopted' => $adopted, 'renamed' => $renamed,
            'url_note' => $resolved['note']];
    }

    /**
     * Pick the eval.php URL for the engine host by asking the Zabbix SERVER to
     * fetch each candidate (the same item test the frontend's "Test" button
     * uses) and keeping the first one that answers with eval.php's own token
     * refusal — proof that the server reaches the module through the web
     * server. Only verified addresses are trusted; when none verifies (or the
     * server cannot be asked), the best guess is used and the note says so.
     *
     * Returns ['url', 'verified' => ?bool, 'note'].
     */
    public function resolveEvalUrl(): array {
        if (self::$resolvedUrl !== null) {
            return self::$resolvedUrl;
        }
        $candidates = $this->evalUrlCandidates();
        $explicit = trim((string) ($this->settings['eval_url'] ?? ''));
        $tried = 0;

        foreach (array_slice($candidates, 0, 8) as $url) {
            $probe = $this->probeEvalUrl($url);
            if ($probe['ok'] === null) {
                break; // the server cannot be asked from here; stop probing
            }
            $tried++;
            if ($probe['ok']) {
                return ['url' => $url, 'verified' => true, 'note' => 'The Zabbix server reaches the module at '.$url.'.'];
            }
            if ($explicit !== '' && $url === $explicit) {
                // An explicit setting is used even when it fails — but say why.
                return ['url' => $url, 'verified' => false,
                    'note' => 'The Zabbix server could not reach the Evaluation URL '.$url.' ('.$probe['detail'].'). Check it in Settings → Automatic setup.'];
            }
        }

        $url = $candidates[0] ?? self::effectiveEvalUrl($this->settings);
        return ['url' => $url, 'verified' => $tried > 0 ? false : null,
            'note' => $tried > 0
                ? 'The Zabbix server could not reach eval.php at any of '.$tried.' addresses tried, so the engine host was given '.$url
                    .'. Set Settings → Automatic setup → Evaluation URL to an address the Zabbix server can reach (in Docker, e.g. the web container’s service name).'
                : 'The engine host was given the evaluation URL '.$url.'; run the self-check to confirm the Zabbix server reaches it.'];
    }

    /**
     * Probe the evaluation URL candidates now — BEFORE a save opens its database
     * transaction — so the server round trips (seconds each) never run while the
     * module row is locked; resolveEvalUrl() then answers from this result.
     */
    public function prepareEvalUrl(): void {
        self::$resolvedUrl = null;
        self::$resolvedUrl = $this->resolveEvalUrl();
    }

    /**
     * Called by the save actions BEFORE their transaction: when the save is going
     * to create the engine host (no heartbeat host exists), probe for a
     * server-reachable evaluation URL now, outside the module-row lock.
     */
    public static function prepareForSave(CorrelationStore $store): void {
        try {
            $settings = (array) (($store->load())['settings'] ?? []);
            if ((string) ($settings['eval_driver'] ?? 'auto') === 'external') {
                return;
            }
            $provisioner = self::forFrontend($settings);
            if ($provisioner !== null && $provisioner->findEngineHosts() === []) {
                $provisioner->prepareEvalUrl();
            }
        }
        catch (\Throwable $e) {
            // the locked part resolves it itself
        }
    }

    /** eval.php URLs worth trying, most trustworthy first, without duplicates. */
    public function evalUrlCandidates(): array {
        $relative = trim(CorrelationStore::moduleRelativePath(), '/') ?: 'modules/'.basename(dirname(__DIR__));
        $list = [];

        $explicit = trim((string) ($this->settings['eval_url'] ?? ''));
        if ($explicit !== '') {
            $list[] = $explicit;
        }

        // What the module's own engine host already uses (it may be working).
        // Never another heartbeat host's: anyone could have set that one.
        foreach ($this->managedEngines() as $engine) {
            foreach ((array) $this->api->call('usermacro.get', [
                'output' => ['value', 'type'],
                'hostids' => [$engine['hostid']],
                'filter' => ['macro' => [self::MACRO_URL]]
            ]) as $macro) {
                if ((int) ($macro['type'] ?? 0) === self::MACRO_TYPE_TEXT && trim((string) ($macro['value'] ?? '')) !== '') {
                    $list[] = trim((string) $macro['value']);
                }
            }
        }

        $list[] = self::effectiveEvalUrl($this->settings, $this->frontendUrl());

        $api = trim((string) ($this->settings['api_url'] ?? ''));
        if ($api !== '' && preg_match('#^(https?://.+?)/(?:[^/]*\.php)?$#i', $api, $m)) {
            $list[] = rtrim($m[1], '/').'/'.$relative.'/eval.php';
        }

        // This web server under its own names — in Docker/Podman the server
        // usually reaches the web container by its container name.
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/zabbix.php'))), '/.');
        $scheme = $https ? 'https' : 'http';
        $portPart = ($port > 0 && $port !== ($https ? 443 : 80)) ? ':'.$port : '';
        foreach (self::ownHostNames() as $name) {
            $list[] = $scheme.'://'.$name.$portPart.$base.'/'.$relative.'/eval.php';
        }

        $out = [];
        foreach ($list as $url) {
            if (preg_match('#^https?://#i', $url) && !in_array($url, $out, true)) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /** Names this machine/container is known by (container name first). */
    private static function ownHostNames(): array {
        $hostname = (string) gethostname();
        $ips = $hostname !== '' ? (array) @gethostbynamel($hostname) : [];
        $names = [];
        $hosts = @file('/etc/hosts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (is_array($hosts) ? $hosts : [] as $line) {
            $parts = preg_split('/\s+/', trim(preg_replace('/#.*/', '', $line) ?? ''));
            if (!$parts || count($parts) < 2 || !in_array($parts[0], $ips, true)) {
                continue;
            }
            foreach (array_slice($parts, 1) as $name) {
                if ($name !== $hostname && $name !== 'localhost' && preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                    $names[] = $name;
                }
            }
        }
        if ($hostname !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $hostname)) {
            $names[] = $hostname;
        }
        return array_values(array_unique($names));
    }

    /**
     * Ask the Zabbix server to GET $url with a deliberately wrong token. eval.php
     * answers 401 {"error":"Invalid evaluation token."}, which proves the
     * server → web server → eval.php path without running an evaluation.
     *
     * Returns ['ok' => true|false|null (null = cannot ask the server), 'detail'].
     */
    public function probeEvalUrl(string $url): array {
        global $ZBX_SERVER, $ZBX_SERVER_PORT;

        if (!class_exists('\CZabbixServer') || !class_exists('\CSessionHelper') || !class_exists('\CSettingsHelper')
                || !function_exists('timeUnitToSeconds') || !defined('ZBX_SOCKET_BYTES_LIMIT')) {
            return ['ok' => null, 'detail' => 'the Zabbix server cannot be asked from here'];
        }

        try {
            $server = new \CZabbixServer($ZBX_SERVER, $ZBX_SERVER_PORT,
                timeUnitToSeconds(\CSettingsHelper::get(\CSettingsHelper::CONNECT_TIMEOUT)),
                timeUnitToSeconds(\CSettingsHelper::get(\CSettingsHelper::ITEM_TEST_TIMEOUT)), ZBX_SOCKET_BYTES_LIMIT);
            $result = $server->testItem([
                'item' => [
                    'type' => 19, // HTTP agent
                    'value_type' => 4,
                    'key' => 'trigger.correlation.probe',
                    'url' => $url,
                    'headers' => 'X-Trigger-Correlation-Token: probe-not-the-secret',
                    'timeout' => '5s',
                    'status_codes' => '200,401,500',
                    'follow_redirects' => 0,
                    'verify_peer' => 0,
                    'verify_host' => 0
                ],
                'host' => [],
                'options' => ['single' => true, 'state' => 0]
            ], \CSessionHelper::getId());
        }
        catch (\Throwable $e) {
            return ['ok' => null, 'detail' => Util::truncate($e->getMessage(), 160)];
        }

        if ($result === false) {
            return ['ok' => null, 'detail' => Util::truncate((string) $server->getError(), 160)];
        }
        if (isset($result['error'])) {
            return ['ok' => false, 'detail' => Util::truncate((string) $result['error'], 160)];
        }
        if (isset($result['item']['error'])) {
            return ['ok' => false, 'detail' => Util::truncate((string) $result['item']['error'], 160)];
        }

        $body = (string) ($result['item']['result'] ?? '');
        if (stripos($body, 'Invalid evaluation token') !== false) {
            return ['ok' => true, 'detail' => 'reached eval.php'];
        }
        // eval.php answers its own database failure before the token check: the
        // address is right, eval.php itself needs fixing (see the self-check).
        if (preg_match('/"stage"\s*:\s*"database"/', $body)) {
            return ['ok' => true, 'detail' => 'reached eval.php, but eval.php cannot connect to the database (see the self-check)'];
        }
        return ['ok' => false, 'detail' => 'answered, but not as eval.php'];
    }

    /**
     * Copy a newly entered evaluation URL and/or secret onto every engine host,
     * so the macros never have to be kept in sync by hand. Returns the number of
     * hosts updated.
     */
    public function syncEngineMacros(?string $url, ?string $secret): int {
        $count = 0;
        foreach ($this->managedEngines() as $engine) {
            if ($url !== null && $url !== '') {
                $this->setHostMacro($engine['hostid'], self::MACRO_URL, $url, self::MACRO_TYPE_TEXT, null);
            }
            // A token kept in a secret vault is the admin's; never replace it.
            if ($secret !== null && $secret !== '' && !$this->engineTokenIsVault([$engine])) {
                $this->setHostMacro($engine['hostid'], self::MACRO_TOKEN, $secret, self::MACRO_TYPE_SECRET, null);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Ask the Zabbix server to run every engine heartbeat now ("Execute now"),
     * so a repaired setup is verified from the SERVER's side within seconds.
     */
    public function checkHeartbeatNow(): int {
        $tasks = [];
        foreach ($this->managedEngines(true) as $engine) {
            if ($engine['itemid'] !== '' && $engine['host_status'] === 0 && $engine['item_status'] === 0) {
                $tasks[] = ['type' => self::TASK_CHECK_NOW, 'request' => ['itemid' => $engine['itemid']]];
            }
        }
        if ($tasks !== []) {
            $this->api->call('task.create', $tasks);
        }
        return count($tasks);
    }

    /**
     * Create or update ONE host macro without touching the host's other macros
     * (host.update with "macros" would replace the whole list).
     */
    private function setHostMacro(string $hostid, string $macro, string $value, int $type, ?string $description): void {
        $rows = (array) $this->api->call('usermacro.get', [
            'output' => ['hostmacroid', 'macro', 'type'],
            'hostids' => [$hostid],
            'filter' => ['macro' => [$macro]]
        ]);

        $fields = ['value' => $value, 'type' => $type];
        if ($description !== null) {
            $fields['description'] = $description;
        }

        if ($rows !== []) {
            if ((int) ($rows[0]['type'] ?? 0) === self::MACRO_TYPE_VAULT) {
                return; // a vault reference is the admin's — never overwrite it
            }
            $this->api->call('usermacro.update', [['hostmacroid' => (string) $rows[0]['hostmacroid']] + $fields]);
        }
        else {
            $this->api->call('usermacro.create', [['hostid' => $hostid, 'macro' => $macro] + $fields]);
        }
    }

    // ── Automatic correlation hosts ────────────────────────────────────────

    /**
     * The automatic correlation host for the source hosts of $conditions: the
     * existing one for this exact host set, or a new one. Idempotent, and safe
     * against a concurrent save on another frontend (a create that loses the
     * race falls back to the winner's host).
     *
     * $rekeyHostid: the rule's previous automatic host when NO other rule uses
     * it. If the new host set has no host yet, that host is re-keyed in place
     * (tags + names) instead of creating a new one, so the correlation keeps its
     * history, problem and any actions/maintenance bound to the host.
     *
     * Returns ['hostid', 'host', 'name', 'key', 'created' => bool, 'rekeyed' => bool].
     */
    public function ensureCorrelationHost(array $conditions, string $rekeyHostid = '', array $ruleHostids = []): array {
        $hostids = self::conditionHostIds($conditions);
        if ($hostids === []) {
            throw new \RuntimeException('The rule has no source hosts to build a correlation host for.');
        }

        // Hosts the module may re-use: the ones it recorded, plus the automatic
        // hosts its own rules already point at (e.g. created before recording).
        $trusted = array_flip(array_merge($this->managedIds('auto'), array_map('strval', $ruleHostids)));

        $key = self::hostsetKey($hostids);
        $templateid = $this->ensureTemplate(self::AUTO_TEMPLATE);

        $found = $this->findAutoHost($key, $trusted);
        if ($found !== null) {
            $this->ensureLinked($found, $templateid);
            if (!in_array($found['hostid'], $this->managedIds('auto'), true)) {
                $this->recordManaged('auto', $found['hostid']);
            }
            return $found + ['key' => $key, 'created' => false, 'rekeyed' => false];
        }

        $sources = $this->sourceHostNames($hostids);
        $technical = self::autoHostName($key);
        // Someone else's host may already carry the technical name: never take it
        // over, use a distinct name instead.
        $taken = (array) $this->api->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => [$technical]], 'limit' => 1]);
        if ($taken !== []) {
            $technical .= '-'.substr(bin2hex(random_bytes(3)), 0, 4);
        }
        $description = Util::truncate(
            'Created automatically by the Trigger Correlation module for correlations across: '
            .implode(', ', $sources).'. Every correlation rule over this same set of hosts uses this host.', 2000);
        $tags = [
            ['tag' => self::TAG_MANAGED, 'value' => 'auto'],
            ['tag' => self::TAG_HOSTSET, 'value' => $key]
        ];
        $names = [self::visibleName($sources, ''), self::visibleName($sources, $key)];

        $rekeyTags = $rekeyHostid !== '' && isset($trusted[$rekeyHostid]) ? $this->autoHostTags($rekeyHostid) : null;
        if ($rekeyTags !== null) {
            // Keep the user's own tags; replace only the set key (and drop the
            // unused marker — the host is in use again).
            foreach ($rekeyTags as $tag) {
                if (!in_array((string) $tag['tag'], [self::TAG_MANAGED, self::TAG_HOSTSET, self::TAG_UNUSED], true)) {
                    $tags[] = ['tag' => (string) $tag['tag'], 'value' => (string) ($tag['value'] ?? '')];
                }
            }
            foreach ($names as $visible) {
                try {
                    $this->api->call('host.update', [
                        'hostid' => $rekeyHostid,
                        'host' => $technical,
                        'name' => $visible,
                        'tags' => $tags,
                        'description' => $description
                    ]);
                    return ['hostid' => $rekeyHostid, 'host' => $technical, 'name' => $visible, 'key' => $key,
                        'created' => false, 'rekeyed' => true];
                }
                catch (\RuntimeException $e) {
                    // Visible-name clash → retry with the key suffix, else create below.
                }
            }
        }

        $groupid = $this->ensureGroup();
        $base = [
            'host' => $technical,
            'groups' => [['groupid' => $groupid]],
            'templates' => [['templateid' => $templateid]],
            'tags' => $tags,
            'description' => $description
        ];

        $lastError = null;
        foreach ($names as $visible) {
            try {
                $result = (array) $this->api->call('host.create', [$base + ['name' => $visible]]);
                $hostid = (string) ($result['hostids'][0] ?? '');
                if ($hostid !== '') {
                    $this->recordManaged('auto', $hostid);
                    return ['hostid' => $hostid, 'host' => $technical, 'name' => $visible, 'key' => $key,
                        'created' => true, 'rekeyed' => false];
                }
            }
            catch (\RuntimeException $e) {
                $lastError = $e;
                // A save on another node may have created it a moment ago.
                $found = $this->findAutoHost($key, array_flip($this->managedIds('auto')));
                if ($found !== null) {
                    $this->ensureLinked($found, $templateid);
                    return $found + ['key' => $key, 'created' => false, 'rekeyed' => false];
                }
                // Otherwise most likely a visible-name clash: retry with the key suffix.
            }
        }

        throw new \RuntimeException('Could not create the correlation host: '
            .($lastError !== null ? $lastError->getMessage() : 'unknown error'));
    }

    /**
     * Find the automatic host of a host set — by tag, then by technical name —
     * among the TRUSTED hosts only ($trusted: hostid => anything). A host someone
     * tagged or named like ours is ignored.
     */
    private function findAutoHost(string $key, array $trusted): ?array {
        if ($trusted === []) {
            return null;
        }
        $params = [
            'output' => ['hostid', 'host', 'name'],
            'selectParentTemplates' => ['templateid'],
            'hostids' => array_map('strval', array_keys($trusted))
        ];

        // operator 1 = equals (0 would be a substring match).
        $rows = (array) $this->api->call('host.get', $params + [
            'evaltype' => 0,
            'tags' => [['tag' => self::TAG_HOSTSET, 'value' => $key, 'operator' => 1]]
        ]);
        if ($rows === []) {
            $rows = (array) $this->api->call('host.get', $params + ['filter' => ['host' => [self::autoHostName($key)]]]);
        }
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn($a, $b): int => (int) $a['hostid'] <=> (int) $b['hostid']);

        return [
            'hostid' => (string) $rows[0]['hostid'],
            'host' => (string) $rows[0]['host'],
            'name' => (string) $rows[0]['name'],
            'templateids' => array_map(static fn($t): string => (string) ($t['templateid'] ?? ''), (array) ($rows[0]['parentTemplates'] ?? []))
        ];
    }

    /** Tags of $hostid when it is an automatic correlation host, else null. */
    private function autoHostTags(string $hostid): ?array {
        $rows = (array) $this->api->call('host.get', [
            'output' => ['hostid'],
            'selectTags' => ['tag', 'value'],
            'hostids' => [$hostid],
            'evaltype' => 0,
            'tags' => [['tag' => self::TAG_MANAGED, 'value' => 'auto', 'operator' => 1]]
        ]);
        return $rows !== [] ? (array) ($rows[0]['tags'] ?? []) : null;
    }

    private function ensureLinked(array &$host, string $templateid): void {
        $linked = (array) ($host['templateids'] ?? []);
        unset($host['templateids']);
        if (in_array($templateid, $linked, true)) {
            return;
        }
        // Any template (or a host-level rule) already providing the discovery
        // rule is enough — and a second one could not be linked anyway: the two
        // receiver templates share their keys.
        $rules = (array) $this->api->call('discoveryrule.get', [
            'output' => ['itemid'],
            'hostids' => [$host['hostid']],
            'filter' => ['key_' => [self::DISCOVERY_KEY]],
            'limit' => 1
        ]);
        if ($rules !== []) {
            return;
        }
        $this->api->call('host.massadd', [
            'hosts' => [['hostid' => $host['hostid']]],
            'templates' => [['templateid' => $templateid]]
        ]);
    }

    /** Current visible names of the source hosts, sorted for a stable label. */
    private function sourceHostNames(array $hostids): array {
        $rows = (array) $this->api->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'hostids' => array_values($hostids),
            'templated_hosts' => true
        ]);
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) (($row['name'] ?? '') ?: ($row['host'] ?? '') ?: ($row['hostid'] ?? ''));
        }
        if ($names === []) {
            $names = array_map('strval', $hostids);
        }
        natcasesort($names);
        return array_values($names);
    }

    /**
     * "Correlation: db01 + web01" — shortened to fit the 128-char visible-name
     * limit ("… +3 more"), with the set key appended when $key is given (used to
     * dodge a visible-name clash, which Zabbix rejects).
     */
    private static function visibleName(array $sources, string $key): string {
        $suffix = $key !== '' ? ' ['.$key.']' : '';
        $prefix = 'Correlation: ';
        $budget = self::NAME_MAX - mb_strlen($prefix) - mb_strlen($suffix);

        $label = implode(' + ', $sources);
        if (mb_strlen($label) > $budget) {
            $label = '';
            $total = count($sources);
            foreach ($sources as $i => $source) {
                $rest = $total - $i - 1;
                $candidate = ($label === '' ? '' : $label.' + ').$source;
                $more = $rest > 0 ? ' +'.$rest.' more' : '';
                if (mb_strlen($candidate.$more) > $budget) {
                    $remaining = $total - $i;
                    $label = $label === ''
                        ? mb_substr($source, 0, max(1, $budget - 12)).'… +'.($remaining - 1).' more'
                        : $label.' +'.$remaining.' more';
                    break;
                }
                $label = $candidate;
            }
        }

        return mb_substr($prefix.$label.$suffix, 0, self::NAME_MAX);
    }

    /**
     * Record the automatic hosts the module's own rules point at but that are
     * not in the managed list yet (created before it existed). Only hosts that
     * carry the module's "auto" tag are taken. Returns how many were recorded.
     */
    public function adoptRuleHosts(array $hostids): int {
        $hostids = array_values(array_diff(self::normalizeIds($hostids), $this->managedIds('auto')));
        if ($hostids === []) {
            return 0;
        }
        $rows = (array) $this->api->call('host.get', [
            'output' => ['hostid'],
            'hostids' => $hostids,
            'evaltype' => 0,
            'tags' => [['tag' => self::TAG_MANAGED, 'value' => 'auto', 'operator' => 1]]
        ]);
        foreach ($rows as $row) {
            $this->recordManaged('auto', (string) $row['hostid']);
        }
        return count($rows);
    }

    /** The module's automatic correlation hosts (recorded ids), with their tags: hostid → row. */
    public function autoHosts(): array {
        $ids = $this->managedIds('auto');
        if ($ids === []) {
            return [];
        }
        $rows = (array) $this->api->call('host.get', [
            'output' => ['hostid', 'host', 'name', 'status'],
            'selectTags' => ['tag', 'value'],
            'hostids' => $ids
        ]);
        $hosts = [];
        foreach ($rows as $row) {
            $hosts[(string) $row['hostid']] = $row;
        }
        return $hosts;
    }

    /**
     * Host ids / technical names referenced by ANY rule — enabled or not, any
     * output mode, flagged automatic or not — so cleanup never removes a host a
     * rule still points at.
     */
    public static function referencedHosts(array $rules): array {
        $used = [];
        foreach ($rules as $rule) {
            $output = (array) ($rule['output'] ?? []);
            foreach ([(string) ($output['receiver_hostid'] ?? ''), (string) ($output['hostid'] ?? '')] as $id) {
                if ($id !== '') {
                    $used['id:'.$id] = true;
                }
            }
            foreach ([(string) ($output['receiver_host'] ?? ''), (string) ($output['host'] ?? '')] as $name) {
                if (trim($name) !== '') {
                    $used['name:'.trim($name)] = true;
                }
            }
        }
        return $used;
    }

    /**
     * Keep automatic correlation hosts tidy. Each host no rule references is
     * tagged tc.unused-since=<time> (and the tag is dropped again once a rule
     * uses it). It is deleted only when $mode allows it AND it has no open
     * problem (deleting a trigger with an open problem would never send the
     * "resolved" notification):
     *   'mark'   — only maintain the tags;
     *   'grace'  — also delete hosts unused for UNUSED_GRACE_SECONDS (Settings);
     *   'now'    — also delete every unused host right away (explicit button).
     * $rules must be the current rules, read under the module-row lock.
     *
     * Returns ['deleted' => names, 'kept_open' => names, 'unused' => names].
     */
    public function sweepAutoHosts(array $rules, string $mode = 'mark'): array {
        $used = self::referencedHosts($rules);
        $report = ['deleted' => [], 'kept_open' => [], 'unused' => []];
        $delete = [];

        foreach ($this->autoHosts() as $id => $row) {
            $tags = (array) ($row['tags'] ?? []);
            $since = 0;
            foreach ($tags as $tag) {
                if ((string) ($tag['tag'] ?? '') === self::TAG_UNUSED) {
                    $since = (int) ($tag['value'] ?? 0);
                }
            }
            $name = (string) (($row['name'] ?? '') ?: $row['host']);
            $isUsed = isset($used['id:'.$id]) || isset($used['name:'.(string) $row['host']]);

            if ($isUsed) {
                if ($since > 0) {
                    $this->setUnusedTag((string) $id, $tags, null);
                }
                continue;
            }

            $report['unused'][] = $name;
            if ($since === 0) {
                $this->setUnusedTag((string) $id, $tags, time());
                $since = time();
            }

            $due = $mode === 'now' || ($mode === 'grace' && time() - $since >= self::UNUSED_GRACE_SECONDS);
            if (!$due) {
                continue;
            }
            $open = (int) $this->api->call('problem.get', ['countOutput' => true, 'hostids' => [(string) $id]]);
            if ($open > 0) {
                $report['kept_open'][] = $name;
                continue;
            }
            $delete[] = (string) $id;
            $report['deleted'][] = $name;
        }

        if ($delete !== []) {
            $this->api->call('host.delete', $delete);
            foreach ($delete as $id) {
                $this->recordManaged('auto', $id, false);
            }
        }

        // Forget recorded ids whose host no longer exists (deleted by hand).
        $existing = $this->autoHosts();
        foreach ($this->managedIds('auto') as $id) {
            if (!isset($existing[$id])) {
                $this->recordManaged('auto', $id, false);
            }
        }

        return $report;
    }

    /** Add (with a timestamp) or remove the unused marker, keeping all other tags. */
    private function setUnusedTag(string $hostid, array $tags, ?int $since): void {
        $kept = [];
        foreach ($tags as $tag) {
            if ((string) ($tag['tag'] ?? '') !== self::TAG_UNUSED) {
                $kept[] = ['tag' => (string) $tag['tag'], 'value' => (string) ($tag['value'] ?? '')];
            }
        }
        if ($since !== null) {
            $kept[] = ['tag' => self::TAG_UNUSED, 'value' => (string) $since];
        }
        $this->api->call('host.update', ['hostid' => $hostid, 'tags' => $kept]);
    }
}
