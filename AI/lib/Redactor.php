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

    public function redactText(string $text, string $channel = 'chat'): string {
        if (!$this->shouldApply($channel) || trim($text) === '') {
            return $text;
        }

        // Apply any existing mappings first so aliasing stays stable across requests.
        $text = $this->applyExistingForwardMappings($text);

        $text = $this->applyCustomRules($text);
        $text = $this->applyOsRedaction($text);
        $text = $this->applyUrlRedaction($text);
        $text = $this->applyIpV4Redaction($text);
        $text = $this->applyIpV6Redaction($text);
        $text = $this->applyFqdnRedaction($text);
        $text = $this->applyHostnameRedaction($text);

        $this->assertNoKnownLeaks($text);

        return $text;
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
                $pattern = '~'.$match.'~u';
                $test = @preg_match($pattern, 'test');
                if ($test === false) {
                    continue;
                }

                $text = preg_replace_callback($pattern, function(array $m) use ($pattern, $replace) {
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

        return preg_replace_callback(
            '~\b[A-Za-z][A-Za-z0-9_-]{2,62}\b~u',
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

        if (!preg_match('/[0-9_-]/', $value)) {
            return false;
        }

        $lower = strtolower($value);

        // All-lowercase strings with underscores but no digits or hyphens are almost
        // certainly programming identifiers (get_problems, severity_min, host_group,
        // confirm_message, tool_name, etc.), not hostnames.  Real hostnames that use
        // underscores nearly always also contain digits (db_server_1) or uppercase.
        if ($value === $lower && strpos($value, '_') !== false && !preg_match('/[0-9-]/', $value)) {
            return false;
        }

        $deny = [
            'rhel7', 'rhel8', 'rhel9', 'ubuntu20', 'ubuntu22', 'windows10', 'windows11', 'gpt4', 'gpt41',
            'http2', 'tls12', 'tls13', 'sha256', 'sha512',
            // Zabbix action tool names — belt-and-suspenders in case heuristic above misses an edge case.
            'get_problems', 'get_unsupported_items', 'get_host_info', 'get_host_uptime',
            'get_host_os', 'get_triggers', 'get_items', 'create_maintenance',
            'update_trigger', 'update_item', 'create_user', 'acknowledge_problem'
        ];

        if (in_array($lower, $deny, true)) {
            return false;
        }

        if (preg_match('/^ai-(?:host|domain|os|windows-family|linux-family|network-os-family|hypervisor-family)-/i', $value)) {
            return false;
        }

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,62}$/', $value)) {
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

        $blocks = [
            [192, 0, 2],
            [198, 51, 100],
            [203, 0, 113]
        ];

        $block = $blocks[(int) floor(($current - 1) / 254) % count($blocks)];
        $last = (($current - 1) % 254) + 1;

        return $block[0].'.'.$block[1].'.'.$block[2].'.'.$last;
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
            if ($original !== '' && $original !== $alias && strpos($text, $original) !== false) {
                throw new RuntimeException('Security redaction blocked a request because a known sensitive value remained after masking. Review the custom rules or disable strict mode if you need best-effort behavior.');
            }
        }
    }
}
