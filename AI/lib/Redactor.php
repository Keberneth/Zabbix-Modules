<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class Redactor {

    private const COMMON_TLDS = [
        'com', 'net', 'org', 'edu', 'gov', 'mil', 'io', 'ai', 'app', 'dev', 'cloud', 'local', 'lan', 'internal',
        'corp', 'home', 'intra', 'example', 'test', 'invalid', 'se', 'no', 'dk', 'fi', 'de', 'fr', 'uk', 'us'
    ];

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
        $text = $this->applyFqdnRedaction($text);
        $text = $this->applyHostnameRedaction($text);

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

        // (?<![A-Za-z0-9_.-]) and (?![A-Za-z0-9_.-]) treat dot/underscore/hyphen
        // as part of the surrounding token, so "myprd-db-01x" is NOT matched
        // when only "db-01" is in the inventory.
        $pattern = '~(?<![A-Za-z0-9_.\-])(?:'.implode('|', $escaped).')(?![A-Za-z0-9_.\-])~iu';

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

            // Register restore mapping for the canonical (idempotent).
            if (!isset($this->state['reverse'][$alias])) {
                $this->state['reverse'][$alias] = $canonical;
            }
            // Persist only the full canonical spelling — substrings would
            // bloat the per-session state file across requests and they are
            // already rebuilt from the inventory cache on every load.
            if ($matched_lower === $canonical_lower && !isset($this->state['forward'][$canonical])) {
                $this->state['forward'][$canonical] = $alias;
                $this->state['meta'][$canonical] = ['type' => 'hostname', 'alias' => $alias];
            }

            $this->bumpStat('hostnames');
            return $alias;
        }, $text);
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

        $pattern = '~(?<![A-Za-z0-9_.\-])(?:'.implode('|', $escaped).')(?![A-Za-z0-9_.\-])~iu';

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

    private function applyExistingForwardMappings(string $text): string {
        if (empty($this->state['forward'])) {
            return $text;
        }

        $originals = array_keys($this->state['forward']);
        $originals = Util::sortByLengthDesc($originals);
        $replace = [];
        foreach ($originals as $original) {
            $replace[$original] = $this->state['forward'][$original];
        }

        return strtr($text, $replace);
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
                            $prefix = substr($original, 0, -strlen(ltrim($match, '.')));
                            $alias = $prefix.$replace;
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

        $patterns = [
            '~\bWindows(?:\s+Server)?(?:\s+\d{2,4}(?:[A-Za-z0-9._-]*)?)?\b~iu' => 'windows-family',
            '~\b(?:Red Hat Enterprise Linux|RHEL|Ubuntu|Debian|CentOS|Rocky Linux|AlmaLinux|Fedora|SUSE(?: Linux)?(?: Enterprise)?|Oracle Linux|Amazon Linux)(?:\s+\d+(?:[A-Za-z0-9._-]*)?)?\b~iu' => 'linux-family',
            '~\bLinux\b~iu' => 'linux-family',
            '~\b(?:FortiOS|PAN-OS|IOS XE|NX-OS|Junos|ArubaOS|RouterOS|EOS)\b~iu' => 'network-os-family',
            '~\b(?:VMware ESXi|ESXi|Proxmox VE)\b~iu' => 'hypervisor-family'
        ];

        foreach ($patterns as $pattern => $family) {
            $text = preg_replace_callback($pattern, function(array $m) use ($mode, $family) {
                $original = $m[0];
                if ($this->isAliasValue($original)) {
                    return $original;
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

        return preg_replace_callback(
            '~\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b~',
            function(array $m) {
                $original = $m[0];
                if ($this->isAliasValue($original)) {
                    return $original;
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

        return preg_replace_callback(
            '~(?<![A-Fa-f0-9:])(?:[A-Fa-f0-9]{0,4}:){2,7}[A-Fa-f0-9]{0,4}(?![A-Fa-f0-9:])~',
            function(array $m) {
                $original = $m[0];
                if ($original === '' || $this->isAliasValue($original)) {
                    return $original;
                }

                if (@filter_var($original, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                    return $original;
                }

                $alias = $this->registerMapping($original, $this->nextIpv6Alias(), 'ipv6');
                $this->bumpStat('ipv6');
                return $alias;
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

                $alias = $this->registerMapping($original, $this->generateSequentialAlias('fqdn', 'ai-domain-', '.example'), 'fqdn');
                $this->bumpStat('fqdns');
                return $alias;
            },
            $text
        );
    }

    private function applyHostnameRedaction(string $text): string {
        if (!Util::truthy($this->config['security']['categories']['hostnames'] ?? true)) {
            return $text;
        }

        // The boundary lookarounds reject tokens that are glued to a '.', '_' or
        // '-' on either side, so a fragment embedded in a dotted version/build
        // string (e.g. "amd64fre" inside "17763.1.amd64fre.rs5_release") is never
        // treated as a standalone hostname. Internal hyphens are still allowed,
        // so "db-prod-01" matches as one token.
        return preg_replace_callback(
            '~(?<![A-Za-z0-9._-])[A-Za-z][A-Za-z0-9_-]{2,62}(?![A-Za-z0-9._-])~u',
            function(array $m) {
                $original = $m[0];

                if ($this->isAliasValue($original) || !$this->isLikelyHostname($original)) {
                    return $original;
                }

                $alias = $this->registerMapping($original, $this->generateSequentialAlias('hostname', 'ai-host-'), 'hostname');
                $this->bumpStat('hostnames');
                return $alias;
            },
            $text
        );
    }

    private function aliasHostLikeValue(string $value): string {
        if (@filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->registerMapping($value, $this->nextIpv4Alias(), 'ipv4');
        }

        if (@filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->registerMapping($value, $this->nextIpv6Alias(), 'ipv6');
        }

        if ($this->isLikelyDomain($value)) {
            return $this->registerMapping($value, $this->generateSequentialAlias('fqdn', 'ai-domain-', '.example'), 'fqdn');
        }

        if ($this->isLikelyHostname($value)) {
            return $this->registerMapping($value, $this->generateSequentialAlias('hostname', 'ai-host-'), 'hostname');
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
            'http2', 'tls12', 'tls13', 'sha256', 'sha512'
        ];

        if (in_array($lower, $deny, true)) {
            return false;
        }

        if (preg_match('/^ai-(?:host|domain|os|windows-family|linux-family|network-os-family|hypervisor-family)-/i', $value)) {
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

        $alias = $this->ensureUniqueAlias($desired_alias, $suffix);

        $this->state['forward'][$original] = $alias;
        $this->state['reverse'][$alias] = $original;
        $this->state['meta'][$original] = ['type' => $type, 'alias' => $alias];
        $this->bumpCounter($type);

        return $alias;
    }

    private function ensureUniqueAlias(string $alias, string $suffix = ''): string {
        if (!$this->isAliasValue($alias)) {
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
        } while ($this->isAliasValue($candidate));

        return $candidate;
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
                // Hostname and service tokens are matched with word boundaries,
                // so substring hits inside unrelated identifiers don't count
                // as leaks (e.g. "redb-01x" vs the host "db-01", or the service
                // "Web" appearing inside "Website").
                $pattern = '~(?<![A-Za-z0-9_.\-])'.preg_quote($original, '~').'(?![A-Za-z0-9_.\-])~iu';
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
