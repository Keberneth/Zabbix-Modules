<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class Redactor {

    private const COMMON_TLDS = [
        'com', 'net', 'org', 'edu', 'gov', 'mil', 'io', 'ai', 'app', 'dev', 'cloud', 'local', 'lan', 'internal',
        'corp', 'home', 'intra', 'example', 'test', 'invalid', 'se', 'no', 'dk', 'fi', 'de', 'fr', 'uk', 'us'
    ];

    /**
     * Token-edge lookarounds shared by every hostname-like scan (inventory,
     * services, hostname heuristic and the strict-mode leak check — they must
     * stay identical or strict mode can false-block). A candidate is rejected
     * when it is glued to a letter/digit/underscore/hyphen, or when a dot
     * connects it to another alphanumeric ("db-01" inside "myprd-db-01x",
     * "amd64fre" inside "17763.1.amd64fre.rs5"). A dot followed by whitespace
     * or end of text is sentence punctuation, so "check LHBDC103." still masks.
     */
    private const BOUNDARY_BEFORE = '(?<![A-Za-z0-9_\-])(?<![A-Za-z0-9]\.)';
    private const BOUNDARY_AFTER = '(?![A-Za-z0-9_\-])(?!\.[A-Za-z0-9])';

    /**
     * Two-label names ending in one of these are almost always file names in
     * monitoring text (backup.sh, README.md, node.js), not domains under the
     * matching ccTLD (.sh/.py/.md exist but are rare in ops chat). Names with
     * more labels (www.example.md) are still treated as domains.
     */
    private const CODE_FILE_EXTENSIONS = ['sh', 'py', 'md', 'js', 'ts', 'go', 'rb', 'cs', 'vb', 'tf', 'gz', 'xz'];

    private array $config;
    private array $state;
    private bool $persistent;
    private string $server_session_id;
    private string $client_session_id;

    /** [lowercase_canonical_hostname => alias] — Zabbix host inventory aliases. */
    private array $zbx_inventory_aliases = [];
    /** [lowercase_phrase => lowercase_canonical_hostname] — full hosts + identifier substrings. */
    private array $zbx_inventory_phrases = [];
    /** [lowercase_canonical => original_case_hostname] — preserves case for restoration. */
    private array $zbx_inventory_canonical = [];

    /** [lowercase_service_name => alias] — Zabbix service aliases (opt-in category). */
    private array $zbx_service_aliases = [];
    /** [lowercase_service_name => original_case_name] — preserves case for restoration. */
    private array $zbx_service_canonical = [];

    /**
     * Set when an admin-supplied custom regex rule fails to compile or aborts at
     * runtime (e.g. PCRE backtrack limit on a pathological pattern). In strict
     * mode this forces the request to fail closed instead of silently forwarding
     * text the broken rule was meant to mask. Reset at the start of each
     * applyCustomRules() pass.
     */
    private bool $custom_rule_failed = false;

    private array $stats = [
        'hostnames' => 0,
        'ipv4' => 0,
        'ipv6' => 0,
        'fqdns' => 0,
        'urls' => 0,
        'os' => 0,
        'custom_rules' => 0,
        'services' => 0,
        'total' => 0
    ];

    public static function forChatSession(array $config, string $server_session_id, string $client_session_id): self {
        $state = RedactionStore::load($config, $server_session_id, $client_session_id);

        return new self($config, $state, true, $server_session_id, $client_session_id);
    }

    public static function forEphemeral(array $config): self {
        return new self($config, [
            'forward' => [],
            'reverse' => [],
            'meta' => [],
            'counters' => [
                'hostname' => 0,
                'ipv4' => 0,
                'ipv6' => 0,
                'fqdn' => 0,
                'url' => 0,
                'os' => 0,
                'custom' => 0,
                'service' => 0
            ],
            'created_at' => time(),
            'updated_at' => time()
        ], false, '', '');
    }

    public function __construct(array $config, array $state, bool $persistent, string $server_session_id, string $client_session_id) {
        $this->config = Config::mergeWithDefaults($config);
        $this->state = $state;
        $this->persistent = $persistent;
        $this->server_session_id = $server_session_id;
        $this->client_session_id = $client_session_id;
    }

    public function save(): void {
        if ($this->persistent) {
            RedactionStore::save($this->config, $this->server_session_id, $this->client_session_id, $this->state);
        }
    }

    public function isEnabled(): bool {
        return Util::truthy($this->config['security']['enabled'] ?? false);
    }

    public function shouldApply(string $channel): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        return Util::truthy($this->config['security']['apply_to'][$channel] ?? false);
    }

    public function redactMessages(array $messages, string $channel = 'chat'): array {
        if (!$this->shouldApply($channel)) {
            return $messages;
        }

        $result = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $message['content'] = $this->redactText((string) ($message['content'] ?? ''), $channel);
            $result[] = $message;
        }

        return $result;
    }

    /**
     * Redact every message except role=system. The system prompt is treated
     * as admin-authored content; sensitive segments inside it must be
     * pre-redacted by PromptBuilder before this method is called.
     */
    public function redactNonSystemMessages(array $messages, string $channel = 'chat'): array {
        if (!$this->shouldApply($channel)) {
            return $messages;
        }

        $result = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') !== 'system') {
                $message['content'] = $this->redactText((string) ($message['content'] ?? ''), $channel);
            }
            $result[] = $message;
        }

        return $result;
    }

    public function redactText(string $text, string $channel = 'chat'): string {
        if (!$this->shouldApply($channel) || trim($text) === '') {
            return $text;
        }

        // Apply any existing mappings first so aliasing stays stable across requests.
        $text = $this->applyExistingForwardMappings($text);

        $text = $this->applyCustomRules($text);
        $text = $this->applyZabbixInventoryRedaction($text);
        $text = $this->applyServiceRedaction($text);
        $text = $this->applyOsRedaction($text);
        $text = $this->applyUrlRedaction($text);
        $text = $this->applyIpV4Redaction($text);
        $text = $this->applyIpV6Redaction($text);
        // Hostnames before FQDNs so that an FQDN whose first label is a
        // hostname masked in this same message reuses that host's alias
        // (aliasForFqdn), keeping host and FQDN correlated for the AI.
        $text = $this->applyHostnameRedaction($text);
        $text = $this->applyFqdnRedaction($text);

        $this->assertNoKnownLeaks($text);
        $this->assertCustomRulesApplied();

        return $text;
    }

    /**
     * Fail closed in strict mode if any custom redaction rule failed to apply.
     * assertNoKnownLeaks() only catches values already registered in the forward
     * map; a rule that never ran (bad pattern / PCRE abort) leaves no mapping to
     * detect, so its intended secret could otherwise pass through unmasked.
     */
    private function assertCustomRulesApplied(): void {
        if ($this->custom_rule_failed && Util::truthy($this->config['security']['strict_mode'] ?? true)) {
            throw new RuntimeException('Security redaction blocked a request because a custom redaction rule failed to apply (invalid pattern or PCRE limit). Fix the rule or disable strict mode if you need best-effort behavior.');
        }
    }

    /**
     * Load the Zabbix host inventory and pre-allocate stable aliases for every
     * known hostname plus identifier-like substrings (e.g. "db-01" inside
     * "prd-db-01"). The actual replacement happens in
     * applyZabbixInventoryRedaction(), which uses word-boundary regex so that
     * generic words like "db" or partial fragments inside unrelated tokens
     * are never touched.
     *
     * Aliases are persisted in a separate inventory cache file so that the
     * mapping prd-db-01 → ai-host-001 stays stable across sessions and users.
     *
     * Safe to call repeatedly; the cache TTL is enforced internally.
     */
    public function loadZabbixHostInventory(?ZabbixApiClient $api): void {
        if ($api === null || !$this->isEnabled()) {
            return;
        }

        $host_inventory_on = Util::truthy($this->config['security']['categories']['zabbix_inventory'] ?? true);
        $services_on = Util::truthy($this->config['security']['categories']['services'] ?? false);

        if (!$host_inventory_on && !$services_on) {
            return;
        }

        $ttl = (int) ($this->config['security']['categories']['inventory_ttl_seconds'] ?? 300);
        $ttl = max(30, min(86400, $ttl));

        $cache = $this->fetchInventoryCache($api, $ttl);

        $this->zbx_inventory_aliases = [];
        $this->zbx_inventory_phrases = [];
        $this->zbx_inventory_canonical = [];

        $highest_alias_index = 0;

        foreach (($host_inventory_on ? ($cache['hosts'] ?? []) : []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $canonical = trim((string) ($entry['canonical'] ?? ''));
            $alias = trim((string) ($entry['alias'] ?? ''));

            if ($canonical === '' || $alias === '') {
                continue;
            }

            $canonical_lower = strtolower($canonical);
            $this->zbx_inventory_aliases[$canonical_lower] = $alias;
            $this->zbx_inventory_canonical[$canonical_lower] = $canonical;
            $this->zbx_inventory_phrases[$canonical_lower] = $canonical_lower;

            if (preg_match('/(\d+)$/', $alias, $m)) {
                $highest_alias_index = max($highest_alias_index, (int) $m[1]);
            }

            foreach ($this->deriveHostnameSubtokens($canonical) as $sub) {
                $sub_lower = strtolower($sub);
                if (!isset($this->zbx_inventory_phrases[$sub_lower])) {
                    $this->zbx_inventory_phrases[$sub_lower] = $canonical_lower;
                }
            }

            // Visible name (if it differs and is identifier-like) gets the same alias.
            $visible = trim((string) ($entry['visible'] ?? ''));
            if ($visible !== '' && $visible !== $canonical && strpos($visible, ' ') === false) {
                $vlower = strtolower($visible);
                if (!isset($this->zbx_inventory_phrases[$vlower])) {
                    $this->zbx_inventory_phrases[$vlower] = $canonical_lower;
                }
            }
        }

        // Make sure the per-session "hostname" counter (used by the legacy
        // heuristic if it is also enabled) cannot allocate an ai-host-NNN
        // alias that collides with one already reserved by the inventory.
        $current_counter = (int) ($this->state['counters']['hostname'] ?? 0);
        if ($highest_alias_index > $current_counter) {
            $this->state['counters']['hostname'] = $highest_alias_index;
        }

        // Optional, opt-in service-name aliases (security.categories.services).
        $this->zbx_service_aliases = [];
        $this->zbx_service_canonical = [];

        if ($services_on) {
            foreach (($cache['services'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $canonical = trim((string) ($entry['canonical'] ?? ''));
                $alias = trim((string) ($entry['alias'] ?? ''));

                if ($canonical === '' || $alias === '') {
                    continue;
                }

                $key = strtolower($canonical);
                $this->zbx_service_aliases[$key] = $alias;
                $this->zbx_service_canonical[$key] = $canonical;
            }
        }
    }

    /**
     * Word-boundary scan that replaces every appearance of a known Zabbix
     * hostname (or identifier-like substring of one) with that hostname's
     * stable alias.
     */
    private function applyZabbixInventoryRedaction(string $text): string {
        if (empty($this->zbx_inventory_phrases)) {
            return $text;
        }

        $phrases = array_keys($this->zbx_inventory_phrases);
        $phrases = Util::sortByLengthDesc($phrases);

        $escaped = array_map(static function(string $p): string {
            return preg_quote($p, '~');
        }, $phrases);

        $pattern = '~'.self::BOUNDARY_BEFORE.'(?:'.implode('|', $escaped).')'.self::BOUNDARY_AFTER.'~iu';

        return preg_replace_callback($pattern, function(array $m): string {
            $matched = $m[0];

            if ($this->isAliasValue($matched)) {
                return $matched;
            }

            $matched_lower = strtolower($matched);
            $canonical_lower = $this->zbx_inventory_phrases[$matched_lower] ?? null;
            if ($canonical_lower === null) {
                return $matched;
            }

            $alias = $this->zbx_inventory_aliases[$canonical_lower] ?? null;
            if ($alias === null) {
                return $matched;
            }

            $canonical = $this->zbx_inventory_canonical[$canonical_lower] ?? $canonical_lower;
            $alias = $this->sessionInventoryAlias($canonical, $alias);

            // Register restore mapping for the canonical (idempotent).
            if (!isset($this->state['reverse'][$alias])) {
                $this->state['reverse'][$alias] = $canonical;
            }
            if (!isset($this->state['forward'][$canonical])) {
                $this->state['forward'][$canonical] = $alias;
                $this->state['meta'][$canonical] = ['type' => 'hostname', 'alias' => $alias];
            }
            // Persist the matched phrase too (visible name or identifier
            // subtoken): a later request in this session must keep masking it
            // even if the Zabbix API is unavailable and the inventory cannot
            // be reloaded. Only phrases actually seen are stored, so the
            // per-session state stays small.
            if ($matched !== $canonical && !isset($this->state['forward'][$matched])) {
                $this->state['forward'][$matched] = $alias;
                $this->state['meta'][$matched] = ['type' => 'hostname', 'alias' => $alias];
            }

            $this->bumpStat('hostnames');
            return $alias;
        }, $text);
    }

    /**
     * Session-safe alias for an inventory host. If the cache-assigned alias is
     * already used by this session for a DIFFERENT value (e.g. the hostname
     * heuristic allocated ai-host-011 before an 11th host was added to
     * Zabbix), a uniquified variant is pinned to this session instead, so two
     * hosts never share one alias within a conversation.
     */
    private function sessionInventoryAlias(string $canonical, string $inventory_alias): string {
        if (isset($this->state['forward'][$canonical])) {
            return $this->state['forward'][$canonical];
        }

        $current = $this->state['reverse'][$inventory_alias] ?? null;
        if ($current !== null && $current !== $canonical) {
            return $this->ensureUniqueAlias($inventory_alias);
        }

        return $inventory_alias;
    }

    /**
     * Replace every appearance of a known Zabbix service name with its stable
     * ai-service-NNN alias. Opt-in (security.categories.services). Service names
     * are matched in full (no substrings) with token-edge boundaries so a name
     * embedded inside a larger identifier is not masked.
     */
    private function applyServiceRedaction(string $text): string {
        if (empty($this->zbx_service_aliases)) {
            return $text;
        }

        $names = Util::sortByLengthDesc(array_keys($this->zbx_service_aliases));

        $escaped = array_map(static function(string $n): string {
            return preg_quote($n, '~');
        }, $names);

        $pattern = '~'.self::BOUNDARY_BEFORE.'(?:'.implode('|', $escaped).')'.self::BOUNDARY_AFTER.'~iu';

        $replaced = preg_replace_callback($pattern, function(array $m): string {
            $matched = $m[0];

            if ($this->isAliasValue($matched)) {
                return $matched;
            }

            $key = strtolower($matched);
            $alias = $this->zbx_service_aliases[$key] ?? null;
            if ($alias === null) {
                return $matched;
            }

            $canonical = $this->zbx_service_canonical[$key] ?? $matched;

            if (!isset($this->state['reverse'][$alias])) {
                $this->state['reverse'][$alias] = $canonical;
            }
            if (!isset($this->state['forward'][$canonical])) {
                $this->state['forward'][$canonical] = $alias;
                $this->state['meta'][$canonical] = ['type' => 'service', 'alias' => $alias];
            }

            $this->bumpStat('services');
            return $alias;
        }, $text);

        return is_string($replaced) ? $replaced : $text;
    }

    /**
     * Identifier-like substrings of a hostname that should also alias to the
     * same value. Rules: contiguous segment range from the hyphen split,
     * length >= 4, contains a digit, and either contains a hyphen or has at
     * least one letter (so "01" alone is rejected but "KT4B" is allowed).
     * The full hostname itself is excluded — it's already in the inventory.
     */
    private function deriveHostnameSubtokens(string $host): array {
        $tokens = [];

        if ($host === '') {
            return $tokens;
        }

        $segments = preg_split('/-/', $host);
        if (!is_array($segments)) {
            return $tokens;
        }

        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i; $j < $count; $j++) {
                $sub = implode('-', array_slice($segments, $i, $j - $i + 1));

                if ($sub === '' || $sub === $host) {
                    continue;
                }

                if (strlen($sub) < 4) {
                    continue;
                }

                if (!preg_match('/[0-9]/', $sub)) {
                    continue;
                }

                if (!preg_match('/[A-Za-z]/', $sub) && strpos($sub, '-') === false) {
                    continue;
                }

                $tokens[] = $sub;
            }
        }

        return $tokens;
    }

    private function fetchInventoryCache(ZabbixApiClient $api, int $ttl): array {
        $state_path = (string) ($this->config['security']['state_path'] ?? '');
        if ($state_path === '') {
            return $this->buildInventoryFromApi($api, []);
        }

        $cache_dir = rtrim($state_path, '/\\').'/inventory';
        $cache_key = substr(hash('sha256', (string) ($this->config['zabbix_api']['url'] ?? '')), 0, 16);
        $cache_file = $cache_dir.'/zabbix-hosts-'.$cache_key.'.json';

        $now = time();
        $existing = [];

        if (is_file($cache_file)) {
            try {
                $existing = Filesystem::readJson($cache_file);
            }
            catch (\Throwable $e) {
                $existing = [];
            }

            $fetched_at = (int) ($existing['fetched_at'] ?? 0);
            // Force a rebuild when services redaction was just enabled but the
            // cached payload predates it (so the toggle takes effect promptly).
            $services_missing = Util::truthy($this->config['security']['categories']['services'] ?? false)
                && !array_key_exists('services', $existing);
            if ($fetched_at > 0 && ($now - $fetched_at) < $ttl && !$services_missing) {
                return $existing;
            }
        }

        try {
            $rebuilt = $this->buildInventoryFromApi($api, $existing);
        }
        catch (\Throwable $e) {
            // Network or auth failure — fall back to whatever we had.
            return $existing;
        }

        try {
            Filesystem::ensureDir($cache_dir);
            Filesystem::writeJsonAtomic($cache_file, $rebuilt);
        }
        catch (\Throwable $e) {
            // Caching is best-effort; ignore write failures.
        }

        return $rebuilt;
    }

    private function buildInventoryFromApi(ZabbixApiClient $api, array $existing): array {
        $hosts = $api->getHosts();

        $previous_hosts = [];
        $highest_index = 0;

        if (isset($existing['hosts']) && is_array($existing['hosts'])) {
            foreach ($existing['hosts'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $canonical = (string) ($row['canonical'] ?? '');
                $alias = (string) ($row['alias'] ?? '');
                if ($canonical === '' || $alias === '') {
                    continue;
                }
                $previous_hosts[strtolower($canonical)] = $row;
                if (preg_match('/(\d+)$/', $alias, $m)) {
                    $highest_index = max($highest_index, (int) $m[1]);
                }
            }
        }

        $rebuilt = [];

        foreach ($hosts as $row) {
            $canonical = trim((string) ($row['host'] ?? ''));
            if ($canonical === '') {
                continue;
            }

            $key = strtolower($canonical);

            if (isset($previous_hosts[$key])) {
                $entry = $previous_hosts[$key];
            }
            else {
                $highest_index++;
                $entry = [
                    'canonical' => $canonical,
                    'alias' => 'ai-host-'.str_pad((string) $highest_index, 3, '0', STR_PAD_LEFT)
                ];
            }

            $visible = trim((string) ($row['name'] ?? ''));
            if ($visible !== '') {
                $entry['visible'] = $visible;
            }

            $rebuilt[$key] = $entry;
        }

        return [
            'fetched_at' => time(),
            'hosts' => array_values($rebuilt),
            'services' => $this->buildServiceInventory($api, $existing)
        ];
    }

    /**
     * Build the stable ai-service-NNN alias list for Zabbix service names.
     * Only runs when the opt-in services category is enabled; otherwise returns
     * an empty list so the host cache stays cheap for the common case.
     */
    private function buildServiceInventory(ZabbixApiClient $api, array $existing): array {
        if (!Util::truthy($this->config['security']['categories']['services'] ?? false)) {
            return [];
        }

        $previous = [];
        $highest_index = 0;

        if (isset($existing['services']) && is_array($existing['services'])) {
            foreach ($existing['services'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $canonical = (string) ($row['canonical'] ?? '');
                $alias = (string) ($row['alias'] ?? '');
                if ($canonical === '' || $alias === '') {
                    continue;
                }
                $previous[strtolower($canonical)] = $row;
                if (preg_match('/(\d+)$/', $alias, $m)) {
                    $highest_index = max($highest_index, (int) $m[1]);
                }
            }
        }

        $rebuilt = [];

        foreach ($api->getServiceNames() as $name) {
            $name = trim((string) $name);
            // Skip very short names — they would mask common words and create
            // noisy, confusing redactions.
            if (strlen($name) < 3) {
                continue;
            }

            $key = strtolower($name);
            if (isset($rebuilt[$key])) {
                continue;
            }

            if (isset($previous[$key])) {
                $rebuilt[$key] = $previous[$key];
            }
            else {
                $highest_index++;
                $rebuilt[$key] = [
                    'canonical' => $name,
                    'alias' => 'ai-service-'.str_pad((string) $highest_index, 3, '0', STR_PAD_LEFT)
                ];
            }
        }

        return array_values($rebuilt);
    }

    public function restoreText(string $text): string {
        if (!$this->isEnabled() || trim($text) === '' || empty($this->state['reverse'])) {
            return $text;
        }

        $aliases = array_keys($this->state['reverse']);
        $aliases = Util::sortByLengthDesc($aliases);
        $replace = [];
        foreach ($aliases as $alias) {
            $replace[$alias] = $this->state['reverse'][$alias];
        }

        return strtr($text, $replace);
    }

    public function restoreValue($value) {
        return Util::mapStrings($value, function(string $text) {
            return $this->restoreText($text);
        });
    }

    public function stats(): array {
        $summary = $this->stats;
        $summary['mapping_count'] = count($this->state['forward'] ?? []);

        return $summary;
    }

    public function mappingDetails(int $limit = 100): array {
        $details = [];
        $count = 0;

        foreach (($this->state['forward'] ?? []) as $original => $alias) {
            $details[] = [
                'type' => $this->state['meta'][$original]['type'] ?? '',
                'original' => $original,
                'alias' => $alias
            ];
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $details;
    }

    /**
     * Re-apply prior session mappings with type-appropriate boundaries. A raw
     * substring pass (strtr) corrupts longer tokens that merely contain a
     * mapped value — "WEB011" became "ai-host-0041" once "WEB01" was mapped,
     * and "110.140.48.38" became "1192.0.2.1" once "10.140.48.38" was mapped.
     * A boundary miss here is safe: the per-category passes re-detect the value
     * and registerMapping() dedupes onto the same stable alias.
     */
    private function applyExistingForwardMappings(string $text): string {
        if (empty($this->state['forward'])) {
            return $text;
        }

        $originals = Util::sortByLengthDesc(array_keys($this->state['forward']));

        foreach ($originals as $original) {
            $original = (string) $original;
            $alias = (string) ($this->state['forward'][$original] ?? '');

            if ($original === '' || $alias === '' || $original === $alias) {
                continue;
            }

            $type = $this->state['meta'][$original]['type'] ?? '';

            // Custom rules are substring-matched by their own pass, so their
            // re-application keeps the same substring semantics.
            if ($type === 'custom') {
                $text = str_replace($original, $alias, $text);
                continue;
            }

            // OS originals contain spaces and prefix each other ("Windows"
            // inside "Windows Server 2019"); re-applying them here would
            // preempt the OS pass's longest match and leak the version part.
            // applyOsRedaction always re-detects and dedupes onto the same
            // alias, so skipping is safe.
            if ($type === 'os') {
                continue;
            }

            $quoted = preg_quote($original, '~');

            if ($type === 'ipv4' || $type === 'ipv6') {
                $pattern = ($type === 'ipv4')
                    ? '~(?<![0-9.])'.$quoted.'(?![0-9.])~'
                    : '~(?<![A-Fa-f0-9:.])'.$quoted.'(?![A-Fa-f0-9:.])~i';
            }
            else {
                // hostname / service / os / fqdn: match case-insensitively so a
                // case variant maps to the same alias instead of a new one.
                $pattern = '~'.self::BOUNDARY_BEFORE.$quoted.self::BOUNDARY_AFTER.'~iu';
            }

            $replaced = @preg_replace_callback($pattern, static function() use ($alias): string {
                return $alias;
            }, $text);

            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    private function applyCustomRules(string $text): string {
        $this->custom_rule_failed = false;

        $rules = is_array($this->config['security']['custom_rules'] ?? null)
            ? $this->config['security']['custom_rules']
            : [];

        foreach ($rules as $rule) {
            if (!is_array($rule) || !Util::truthy($rule['enabled'] ?? false)) {
                continue;
            }

            $type = Util::cleanEnum($rule['type'] ?? 'exact', ['exact', 'regex', 'domain_suffix'], 'exact');
            $match = trim((string) ($rule['match'] ?? ''));
            $replace = trim((string) ($rule['replace'] ?? ''));

            if ($match === '' || $replace === '') {
                continue;
            }

            if ($type === 'exact') {
                if (strpos($text, $match) !== false) {
                    $alias = $this->registerMapping($match, $replace, 'custom');
                    $text = str_replace($match, $alias, $text);
                    $this->bumpStat('custom_rules');
                }
                continue;
            }

            if ($type === 'domain_suffix') {
                $suffix = preg_quote(ltrim($match, '.'), '~');
                $text = preg_replace_callback(
                    '~\b(?:[A-Za-z0-9-]+\.)*'.$suffix.'\b~iu',
                    function(array $m) use ($match, $replace) {
                        $original = $m[0];
                        if ($this->isAliasValue($original)) {
                            return $original;
                        }

                        $lower_original = strtolower($original);
                        $lower_match = strtolower(ltrim($match, '.'));

                        if ($lower_original === $lower_match) {
                            $alias = $replace;
                        }
                        else {
                            // The kept subdomain labels may themselves be
                            // sensitive ("db-prod-01.corp.example.com" must not
                            // reach the AI as "db-prod-01.masked.example"), so
                            // known hosts and identifier-like labels are
                            // aliased before the suffix is swapped.
                            $prefix = substr($original, 0, -strlen(ltrim($match, '.')));
                            $alias = $this->maskFqdnPrefixLabels($prefix).$replace;
                        }

                        $alias = $this->registerMapping($original, $alias, 'custom');
                        $this->bumpStat('custom_rules');
                        return $alias;
                    },
                    $text
                );
                continue;
            }

            if ($type === 'regex') {
                // Escape any unescaped delimiter in the admin-supplied pattern so a
                // literal '~' can't terminate or redefine the regex (delimiter
                // injection). Already-escaped '\~' is left intact. A callback is
                // used so the replacement is inserted literally (a plain
                // preg_replace replacement would reinterpret the backslash).
                $safe_match = preg_replace_callback('/(?<!\\\\)~/', static function() {
                    return '\\~';
                }, $match);
                $pattern = '~'.$safe_match.'~u';
                $test = @preg_match($pattern, 'test');
                if ($test === false) {
                    // Pattern does not compile — it can never mask its target, so
                    // record the failure for the strict-mode fail-closed check.
                    $this->custom_rule_failed = true;
                    continue;
                }

                $replaced = preg_replace_callback($pattern, function(array $m) use ($pattern, $replace) {
                    $original = $m[0];
                    if ($original === '' || $this->isAliasValue($original)) {
                        return $original;
                    }

                    $alias = @preg_replace($pattern, $replace, $original, 1);
                    if (!is_string($alias) || $alias === '') {
                        return $original;
                    }

                    $alias = $this->registerMapping($original, $alias, 'custom');
                    $this->bumpStat('custom_rules');
                    return $alias;
                }, $text);

                // preg_* returns null on a PCRE failure (e.g. backtrack limit on a
                // pathological pattern); never let that wipe the text being masked.
                // Record the failure so strict mode can fail closed rather than
                // forward text this rule was supposed to redact.
                if (is_string($replaced)) {
                    $text = $replaced;
                }
                else {
                    $this->custom_rule_failed = true;
                }
            }
        }

        return $text;
    }

    private function applyOsRedaction(string $text): string {
        $mode = (string) ($this->config['security']['categories']['os_mode'] ?? 'family_only');

        if ($mode === 'off') {
            return $text;
        }

        // BOUNDARY lookarounds (not \b) so the case-insensitive patterns never
        // re-match inside the module's own aliases ("windows" inside
        // "ai-windows-family-001" would nest into garbage). The backslash
        // guards keep path components ("C:\Windows\System32") intact — a
        // family alias would reveal the same information while corrupting the
        // path.
        $bodies = [
            'Windows(?:\s+Server)?(?:\s+\d{2,4}(?:[A-Za-z0-9._-]*)?)?' => 'windows-family',
            '(?:Red Hat Enterprise Linux|RHEL|Ubuntu|Debian|CentOS|Rocky Linux|AlmaLinux|Fedora|SUSE(?: Linux)?(?: Enterprise)?|Oracle Linux|Amazon Linux)(?:\s+\d+(?:[A-Za-z0-9._-]*)?)?' => 'linux-family',
            'Linux' => 'linux-family',
            '(?:FortiOS|PAN-OS|IOS XE|NX-OS|Junos|ArubaOS|RouterOS|EOS)' => 'network-os-family',
            '(?:VMware ESXi|ESXi|Proxmox VE)' => 'hypervisor-family'
        ];

        $patterns = [];
        foreach ($bodies as $body => $family) {
            $patterns['~'.self::BOUNDARY_BEFORE.'(?<!\\\\)'.$body.self::BOUNDARY_AFTER.'(?!\\\\)~iu'] = $family;
        }

        foreach ($patterns as $pattern => $family) {
            $text = preg_replace_callback($pattern, function(array $m) use ($mode, $family) {
                $original = $m[0];
                if ($this->isAliasValue($original)) {
                    return $original;
                }

                if (isset($this->state['forward'][$original])) {
                    $this->bumpStat('os');
                    return $this->state['forward'][$original];
                }

                if ($mode === 'full_alias') {
                    $alias_seed = 'ai-os-';
                }
                else {
                    $alias_seed = 'ai-'.$family.'-';
                }

                $alias = $this->generateSequentialAlias('os', $alias_seed);
                $alias = $this->registerMapping($original, $alias, 'os');
                $this->bumpStat('os');
                return $alias;
            }, $text);
        }

        return $text;
    }

    private function applyUrlRedaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['urls'] ?? true)) {
            return $text;
        }

        return preg_replace_callback(
            '~\b([A-Za-z][A-Za-z0-9+.-]*://[^\s<>")\]\}]+)~u',
            function(array $m) {
                $original = $m[1];
                if ($this->isAliasValue($original)) {
                    return $original;
                }

                $parts = @parse_url($original);
                if (!is_array($parts) || empty($parts['host'])) {
                    return $original;
                }

                $host = (string) $parts['host'];
                $alias_host = $this->aliasHostLikeValue($host);

                $rebuilt = '';
                if (!empty($parts['scheme'])) {
                    $rebuilt .= $parts['scheme'].'://';
                }
                $rebuilt .= $alias_host;
                if (isset($parts['port'])) {
                    $rebuilt .= ':'.$parts['port'];
                }
                if (isset($parts['path'])) {
                    $rebuilt .= $parts['path'];
                }
                if (!Util::truthy($this->config['security']['categories']['strip_url_query'] ?? false)
                    && isset($parts['query']) && $parts['query'] !== '') {
                    $rebuilt .= '?'.$parts['query'];
                }
                if (isset($parts['fragment']) && $parts['fragment'] !== '') {
                    $rebuilt .= '#'.$parts['fragment'];
                }

                if ($rebuilt !== $original) {
                    $this->bumpStat('urls');
                }

                return $rebuilt;
            },
            $text
        );
    }

    private function applyIpV4Redaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['ipv4'] ?? true)) {
            return $text;
        }

        // The lookarounds refuse to bite a 4-octet chunk out of a longer
        // dotted numeric string, so SNMP OIDs (1.3.6.1.2.1.1.5.0) and 5-part
        // versions are left intact instead of becoming fake RFC5737 addresses.
        return preg_replace_callback(
            '~(?<![0-9A-Za-z_.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b(?!\.?\d)~',
            function(array $m) {
                $original = $m[0];
                if ($this->isAliasValue($original)) {
                    return $original;
                }

                // RFC 5737 documentation addresses are non-sensitive by
                // definition and are the module's own alias space — masking
                // them would only chain aliases.
                if (preg_match('/^(?:192\.0\.2|198\.51\.100|203\.0\.113)\./', $original)) {
                    return $original;
                }

                if (isset($this->state['forward'][$original])) {
                    $this->bumpStat('ipv4');
                    return $this->state['forward'][$original];
                }

                $alias = $this->registerMapping($original, $this->nextIpv4Alias(), 'ipv4');
                $this->bumpStat('ipv4');
                return $alias;
            },
            $text
        );
    }

    private function applyIpV6Redaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['ipv6'] ?? true)) {
            return $text;
        }

        // The lookbehind rejects a start glued to any alphanumeric (a leading
        // hex char could belong to a word: "SRC:fd00::5" must match at fd00,
        // not at "C:fd00::5" which is itself valid IPv6), but NOT one after a
        // colon — otherwise a label prefix ("IP:2a02::...") makes the address
        // unmatchable and it leaks. The trailing (?!\.[0-9]) refuses to split
        // an IPv4-mapped form ("::ffff:1.2.3.4") whose "::ffff:1" prefix would
        // otherwise validate on its own. Punctuation colons swallowed by the
        // greedy match are peeled off in the callback.
        return preg_replace_callback(
            '~(?<![A-Za-z0-9])(?:[A-Fa-f0-9]{0,4}:){2,7}[A-Fa-f0-9]{0,4}(?![A-Fa-f0-9:])(?!\.[0-9])~',
            function(array $m) {
                $original = $m[0];
                if ($original === '' || $this->isAliasValue($original)) {
                    return $original;
                }

                // The match may include a leading label colon ("IP:2a02::…")
                // or a trailing punctuation colon ("… 2001:db8::5: timeout"),
                // which makes validation fail and used to leak the address.
                // Peel colons from both ends until the remainder validates.
                $candidate = $original;
                $lead = '';
                while ($candidate !== '' && $candidate[0] === ':' && strpos($candidate, '::') !== 0
                        && @filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    $lead .= ':';
                    $candidate = substr($candidate, 1);
                }
                while ($candidate !== '' && substr($candidate, -1) === ':'
                        && @filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    $candidate = substr($candidate, 0, -1);
                }

                if ($candidate === '' || @filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    return $original;
                }

                if ($this->isAliasValue($candidate)) {
                    return $original;
                }

                // RFC 3849 documentation prefix — non-sensitive by definition
                // and the module's own alias space.
                if (preg_match('/^2001:db8(?::|$)/i', $candidate)) {
                    return $original;
                }

                $suffix = substr($original, strlen($lead) + strlen($candidate));

                if (isset($this->state['forward'][$candidate])) {
                    $this->bumpStat('ipv6');
                    return $lead.$this->state['forward'][$candidate].$suffix;
                }

                $alias = $this->registerMapping($candidate, $this->nextIpv6Alias(), 'ipv6');
                $this->bumpStat('ipv6');
                return $lead.$alias.$suffix;
            },
            $text
        );
    }

    private function applyFqdnRedaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['fqdns'] ?? true)) {
            return $text;
        }

        return preg_replace_callback(
            '~\b(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+(?:[A-Za-z]{2,24})\b~u',
            function(array $m) {
                $original = $m[0];
                if ($this->isAliasValue($original) || !$this->isLikelyDomain($original)) {
                    return $original;
                }

                $alias = $this->aliasForFqdn($original);
                if ($alias !== $original) {
                    $this->bumpStat('fqdns');
                }
                return $alias;
            },
            $text
        );
    }

    /**
     * Alias an FQDN. When its first label is a known host — from the Zabbix
     * inventory (including identifier subtokens and visible names) or a
     * hostname already mapped in this session — the FQDN becomes
     * "<host-alias>.example" instead of an unrelated ai-domain-NNN.example, so
     * the AI can tell that lhbdc103.lh.example.net is the FQDN of LHBDC103
     * (ai-host-010 => ai-host-010.example). Unrelated FQDNs keep the
     * ai-domain-NNN.example form.
     *
     * FQDN keys are lowercased (DNS is case-insensitive) so case variants of
     * the same name share one alias.
     */
    private function aliasForFqdn(string $fqdn): string {
        $key = strtolower(trim($fqdn));

        if ($key === '') {
            return $fqdn;
        }

        // Never re-mask an alias the module itself generated (e.g. pasted back
        // into a fresh session where it is not in the reverse map yet).
        if (preg_match('/^ai-(?:host|domain)-[a-z0-9-]+\.example$/', $key)) {
            return $fqdn;
        }

        if (isset($this->state['forward'][$key])) {
            return $this->state['forward'][$key];
        }

        $host_alias = $this->hostAliasForFqdn($key);
        $desired = ($host_alias !== null)
            ? $host_alias.'.example'
            : $this->generateSequentialAlias('fqdn', 'ai-domain-', '.example');

        return $this->registerMapping($key, $desired, 'fqdn', '.example');
    }

    /**
     * Resolve the host alias to correlate an FQDN with, or null when the first
     * label is not a known host.
     */
    private function hostAliasForFqdn(string $fqdn_lower): ?string {
        $first_label = (string) strstr($fqdn_lower, '.', true);

        return ($first_label === '') ? null : $this->knownHostAlias($first_label);
    }

    /**
     * Alias for a single hostname label when it is a known host — from the
     * Zabbix inventory (including identifier subtokens and visible names) or a
     * hostname already mapped in this session. For inventory hosts the bare
     * hostname mapping is registered as well, so a reply that mentions the
     * bare "ai-host-NNN" alias restores even if the hostname itself never
     * appeared in the chat.
     */
    private function knownHostAlias(string $label_lower): ?string {
        $canonical_lower = $this->zbx_inventory_phrases[$label_lower] ?? null;
        if ($canonical_lower !== null) {
            $alias = $this->zbx_inventory_aliases[$canonical_lower] ?? null;
            if ($alias !== null) {
                $canonical = $this->zbx_inventory_canonical[$canonical_lower] ?? $canonical_lower;
                $alias = $this->sessionInventoryAlias($canonical, $alias);
                if (!isset($this->state['reverse'][$alias])) {
                    $this->state['reverse'][$alias] = $canonical;
                }
                if (!isset($this->state['forward'][$canonical])) {
                    $this->state['forward'][$canonical] = $alias;
                    $this->state['meta'][$canonical] = ['type' => 'hostname', 'alias' => $alias];
                }
                return $alias;
            }
        }

        foreach (($this->state['forward'] ?? []) as $original => $alias) {
            if (($this->state['meta'][$original]['type'] ?? '') === 'hostname'
                    && strtolower((string) $original) === $label_lower) {
                return (string) $alias;
            }
        }

        return null;
    }

    /**
     * Mask the subdomain labels a domain_suffix custom rule keeps in front of
     * its replacement. Known hosts reuse their stable alias (preserving the
     * host/FQDN correlation); other identifier-like labels get a fresh
     * ai-host-NNN alias; generic labels such as "www" pass through.
     */
    private function maskFqdnPrefixLabels(string $prefix): string {
        $labels = explode('.', $prefix);

        foreach ($labels as &$label) {
            if ($label === '') {
                continue;
            }

            $alias = $this->knownHostAlias(strtolower($label));

            if ($alias === null && $this->isLikelyHostname($label)) {
                $alias = isset($this->state['forward'][$label])
                    ? $this->state['forward'][$label]
                    : $this->registerMapping($label, $this->generateSequentialAlias('hostname', 'ai-host-'), 'hostname');
            }

            if ($alias !== null && $alias !== '') {
                $label = $alias;
            }
        }
        unset($label);

        return implode('.', $labels);
    }

    private function applyHostnameRedaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['hostnames'] ?? true)) {
            return $text;
        }

        // The BOUNDARY lookarounds reject tokens glued to '_'/'-'/alphanumerics
        // or dot-connected to another alphanumeric, so a fragment embedded in a
        // dotted version/build string (e.g. "amd64fre" inside
        // "17763.1.amd64fre.rs5_release") is never treated as a standalone
        // hostname, while a sentence-ending dot ("check db-prod-01.") is.
        // Internal hyphens are still allowed, so "db-prod-01" is one token.
        // Backslash-adjacent tokens are Windows path components
        // ("C:\Windows\System32"), not hostnames — known hosts in UNC paths
        // are still caught by the inventory pass, which has no such guard.
        return preg_replace_callback(
            '~'.self::BOUNDARY_BEFORE.'(?<!\\\\)[A-Za-z][A-Za-z0-9_-]{2,62}'.self::BOUNDARY_AFTER.'(?!\\\\)~u',
            function(array $m) {
                $original = $m[0];

                if ($this->isAliasValue($original) || !$this->isLikelyHostname($original)) {
                    return $original;
                }

                if (isset($this->state['forward'][$original])) {
                    $this->bumpStat('hostnames');
                    return $this->state['forward'][$original];
                }

                $alias = $this->registerMapping($original, $this->generateSequentialAlias('hostname', 'ai-host-'), 'hostname');
                $this->bumpStat('hostnames');
                return $alias;
            },
            $text
        );
    }

    private function aliasHostLikeValue(string $value): string {
        // Never alias an alias — e.g. the same URL sent again after the
        // forward-mapping pass already rewrote its host. Re-aliasing would
        // chain mappings and restoreText would show an alias to the user.
        if ($this->isAliasValue($value)) {
            return $value;
        }

        // Per-type stats are bumped here as well so the redaction log's
        // counters match the mapping_details rows a URL produces.
        if (@filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (preg_match('/^(?:192\.0\.2|198\.51\.100|203\.0\.113)\./', $value)) {
                return $value;
            }

            $alias = isset($this->state['forward'][$value])
                ? $this->state['forward'][$value]
                : $this->registerMapping($value, $this->nextIpv4Alias(), 'ipv4');
            $this->bumpStat('ipv4');
            return $alias;
        }

        if (@filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (preg_match('/^2001:db8(?::|$)/i', $value)) {
                return $value;
            }

            $alias = isset($this->state['forward'][$value])
                ? $this->state['forward'][$value]
                : $this->registerMapping($value, $this->nextIpv6Alias(), 'ipv6');
            $this->bumpStat('ipv6');
            return $alias;
        }

        if ($this->isLikelyDomain($value)) {
            $alias = $this->aliasForFqdn($value);
            if ($alias !== $value) {
                $this->bumpStat('fqdns');
            }
            return $alias;
        }

        if ($this->isLikelyHostname($value)) {
            $alias = isset($this->state['forward'][$value])
                ? $this->state['forward'][$value]
                : $this->registerMapping($value, $this->generateSequentialAlias('hostname', 'ai-host-'), 'hostname');
            $this->bumpStat('hostnames');
            return $alias;
        }

        return $value;
    }

    private function isLikelyDomain(string $value): bool {
        $value = trim($value, '.');
        if ($value === '' || strpos($value, '..') !== false) {
            return false;
        }

        $lower = strtolower($value);

        if (preg_match('/^(system|vfs|net|proc|log|agent|vmware|mysql|pgsql|oracle|icmpping|jmx|snmp)\./', $lower)) {
            return false;
        }

        if (@filter_var($lower, FILTER_VALIDATE_IP)) {
            return false;
        }

        $parts = explode('.', $lower);
        if (count($parts) < 2) {
            return false;
        }

        $tld = (string) end($parts);
        if (!preg_match('/^[a-z]{2,24}$/', $tld)) {
            return false;
        }

        if (count($parts) === 2 && in_array($tld, self::CODE_FILE_EXTENSIONS, true)) {
            return false;
        }

        if (strlen($tld) === 2 || in_array($tld, self::COMMON_TLDS, true)) {
            return true;
        }

        return false;
    }

    private function isLikelyHostname(string $value): bool {
        if (strpos($value, '.') !== false) {
            return false;
        }

        // Underscores are not valid in DNS hostnames, so an underscore token is
        // almost always a programming identifier, a process/job name, or one of
        // the module's own prompt fence labels (UNTRUSTED_DATA, ZABBIX_CONTEXT,
        // NETBOX_CONTEXT, WEBHOOK_PROBLEM, TOOL_RESULT_*). Real Zabbix hosts that
        // contain underscores are masked earlier by exact inventory matching
        // (applyZabbixInventoryRedaction runs before this heuristic), so rejecting
        // them here only drops false positives — it never unmasks a real host.
        if (strpos($value, '_') !== false) {
            return false;
        }

        // Require at least one digit or hyphen so plain dictionary words are not
        // treated as hostnames.
        if (!preg_match('/[0-9-]/', $value)) {
            return false;
        }

        // All-lowercase hyphenated words with no digits are almost always compound
        // English terms (date-time, first-line, evidence-gathering), not hostnames.
        if ($value === strtolower($value) && strpos($value, '-') !== false && !preg_match('/[0-9]/', $value)) {
            return false;
        }

        $lower = strtolower($value);

        $deny = [
            'rhel7', 'rhel8', 'rhel9', 'ubuntu20', 'ubuntu22', 'windows10', 'windows11', 'gpt4', 'gpt41',
            'http2', 'tls12', 'tls13', 'sha256', 'sha512',
            // Common technical tokens that would otherwise become phantom
            // ai-host aliases and confuse the AI's answer.
            'md5', 'sha1', 'sha-1', 'sha-256', 'sha-512', 'utf-8', 'utf8', 'utf-16', 'utf16',
            'x64', 'x86', 'amd64', 'arm64', 'i386', 'i686', 'ipv4', 'ipv6', 'log4j', 'http3',
            'p50', 'p90', 'p95', 'p99', 'gpt-4', 'gpt-5', 'gpt5', 'oauth2',
            // RFC 3849 documentation prefix label: it appears inside the
            // module's own IPv6 aliases (2001:db8::N), which must not be
            // re-masked into "2001:ai-host-NNN::N".
            'db8'
        ];

        if (in_array($lower, $deny, true)) {
            return false;
        }

        if (preg_match('/^ai-(?:host|domain|os|service|windows-family|linux-family|network-os-family|hypervisor-family)-/i', $value)) {
            return false;
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9-]{2,62}$/', $value)) {
            return false;
        }

        return true;
    }

    private function registerMapping(string $original, string $desired_alias, string $type, string $suffix = ''): string {
        $original = trim($original);
        $desired_alias = trim($desired_alias);

        if ($original === '' || $desired_alias === '') {
            return $original;
        }

        if (isset($this->state['forward'][$original])) {
            return $this->state['forward'][$original];
        }

        // Case variants of case-insensitive value types must share one alias
        // ("WEBSRV-99" and "websrv-99" are the same machine; DNS names,
        // service names, OS strings and IPv6 are all case-insensitive).
        // Custom rules and IPv4 stay exact.
        if (in_array($type, ['hostname', 'fqdn', 'service', 'os', 'ipv6'], true)) {
            $existing = $this->findForwardCaseInsensitive($original, $type);
            if ($existing !== null) {
                return $existing;
            }
        }

        $alias = $this->ensureUniqueAlias($desired_alias, $suffix, $type);

        $this->state['forward'][$original] = $alias;
        $this->state['reverse'][$alias] = $original;
        $this->state['meta'][$original] = ['type' => $type, 'alias' => $alias];
        $this->bumpCounter($type);

        return $alias;
    }

    private function findForwardCaseInsensitive(string $original, string $type): ?string {
        $lower = strtolower($original);

        foreach (($this->state['forward'] ?? []) as $key => $alias) {
            if (($this->state['meta'][$key]['type'] ?? '') === $type && strtolower((string) $key) === $lower) {
                return (string) $alias;
            }
        }

        return null;
    }

    private function ensureUniqueAlias(string $alias, string $suffix = '', string $type = ''): string {
        if (!$this->isAliasTaken($alias)) {
            return $alias;
        }

        // IP aliases must stay valid addresses — advance the counter instead
        // of appending "-2".
        if ($type === 'ipv4' || $type === 'ipv6') {
            do {
                $alias = ($type === 'ipv4') ? $this->nextIpv4Alias() : $this->nextIpv6Alias();
            } while ($this->isAliasTaken($alias));

            return $alias;
        }

        $base = $alias;
        $counter = 2;

        if ($suffix !== '' && substr($alias, -strlen($suffix)) === $suffix) {
            $base = substr($alias, 0, -strlen($suffix));
        }

        do {
            $candidate = $base.'-'.$counter.$suffix;
            $counter++;
        } while ($this->isAliasTaken($candidate));

        return $candidate;
    }

    /**
     * An alias is unusable both when it is already an alias and when it equals
     * a tracked ORIGINAL (e.g. a pasted documentation IP that was itself
     * masked): reusing it would make restoreText ambiguous and trip the
     * strict-mode leak check on the request.
     */
    private function isAliasTaken(string $alias): bool {
        return isset($this->state['reverse'][$alias]) || isset($this->state['forward'][$alias]);
    }

    private function isAliasValue(string $value): bool {
        return isset($this->state['reverse'][$value]);
    }

    private function generateSequentialAlias(string $counter_key, string $prefix, string $suffix = ''): string {
        $current = (int) ($this->state['counters'][$counter_key] ?? 0);
        $current++;
        $this->state['counters'][$counter_key] = $current;

        return $prefix.str_pad((string) $current, 3, '0', STR_PAD_LEFT).$suffix;
    }

    private function nextIpv4Alias(): string {
        $current = (int) ($this->state['counters']['ipv4'] ?? 0) + 1;
        $this->state['counters']['ipv4'] = $current;

        // RFC 5737 documentation ranges first (instantly recognizable as fake).
        $blocks = [
            [192, 0, 2],
            [198, 51, 100],
            [203, 0, 113]
        ];
        $doc_capacity = count($blocks) * 254; // 762 unique addresses

        if ($current <= $doc_capacity) {
            $block = $blocks[(int) floor(($current - 1) / 254)];
            $last = (($current - 1) % 254) + 1;

            return $block[0].'.'.$block[1].'.'.$block[2].'.'.$last;
        }

        // Once the documentation ranges are exhausted, map the overflow into the
        // RFC 6598 shared-address space (100.64.0.0/10, ~4.19M addresses) so we
        // never repeat an alias and never fall back to an invalid "x.x.x.x-2" form.
        $idx = $current - $doc_capacity - 1;
        $second = 64 + (int) (floor($idx / 65536) % 64);
        $third = (int) (floor($idx / 256) % 256);
        $fourth = $idx % 256;

        return '100.'.$second.'.'.$third.'.'.$fourth;
    }

    private function nextIpv6Alias(): string {
        $current = (int) ($this->state['counters']['ipv6'] ?? 0) + 1;
        $this->state['counters']['ipv6'] = $current;

        return '2001:db8::'.$current;
    }

    private function bumpCounter(string $type): void {
        if (!isset($this->state['counters'][$type])) {
            $this->state['counters'][$type] = 0;
        }

        if ($type !== 'ipv4' && $type !== 'ipv6') {
            $this->state['counters'][$type] = (int) $this->state['counters'][$type];
        }
    }

    private function bumpStat(string $name): void {
        if (!isset($this->stats[$name])) {
            $this->stats[$name] = 0;
        }

        $this->stats[$name]++;
        $this->stats['total']++;
    }

    private function assertNoKnownLeaks(string $text): void {
        if (!Util::truthy($this->config['security']['strict_mode'] ?? true)) {
            return;
        }

        foreach (($this->state['forward'] ?? []) as $original => $alias) {
            if ($original === '' || $original === $alias) {
                continue;
            }

            $type = $this->state['meta'][$original]['type'] ?? '';

            if ($type === 'hostname' || $type === 'hostname_partial' || $type === 'service') {
                // Hostname and service tokens are matched with the same
                // boundaries as the masking passes, so substring hits inside
                // unrelated identifiers don't count as leaks (e.g. "redb-01x"
                // vs the host "db-01", or the service "Web" inside "Website").
                $pattern = '~'.self::BOUNDARY_BEFORE.preg_quote($original, '~').self::BOUNDARY_AFTER.'~iu';
                if (@preg_match($pattern, $text) === 1) {
                    throw new RuntimeException('Security redaction blocked a request because a known sensitive value remained after masking. Review the custom rules or disable strict mode if you need best-effort behavior.');
                }
                continue;
            }

            if (strpos($text, $original) !== false) {
                throw new RuntimeException('Security redaction blocked a request because a known sensitive value remained after masking. Review the custom rules or disable strict mode if you need best-effort behavior.');
            }
        }
    }
}
