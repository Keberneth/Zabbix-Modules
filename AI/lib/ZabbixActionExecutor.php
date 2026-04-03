<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/**
 * Dispatches AI tool calls to Zabbix API methods.
 *
 * Each tool has a name, description, parameter schema, and a read/write flag.
 * Write tools are further categorised (maintenance, items, triggers, users, problems)
 * so that permissions can be enforced per category.
 */
class ZabbixActionExecutor {

    private const SEVERITY_LABELS = [
        '0' => 'Not classified',
        '1' => 'Information',
        '2' => 'Warning',
        '3' => 'Average',
        '4' => 'High',
        '5' => 'Disaster'
    ];

    /**
     * Full catalogue of available tools.
     *
     * Each entry:
     *   'description' => human-readable text for the AI system prompt
     *   'params'      => parameter descriptions for the AI
     *   'rw'          => 'read' | 'write'
     *   'category'    => write sub-category (only relevant when rw=write)
     */
    public static function allTools(): array {
        return [
            'get_problems' => [
                'description' => 'Get active problems / alerts from Zabbix.',
                'params' => [
                    'severity_min' => '(int, optional) Minimum severity 0-5 (0=Not classified, 1=Information, 2=Warning, 3=Average, 4=High, 5=Disaster).',
                    'acknowledged' => '(bool, optional) Filter by acknowledged status. true=only acknowledged, false=only unacknowledged.',
                    'host' => '(string, optional) Filter by hostname.',
                    'search' => '(string, optional) Search problem name text.',
                    'limit' => '(int, optional) Max results, default 50.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_unsupported_items' => [
                'description' => 'Get items that are in an unsupported state (failing to collect data). Returns items grouped by host with error details.',
                'params' => [
                    'host_group' => '(string, optional) Filter by host group name.',
                    'limit' => '(int, optional) Max results, default 200.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_host_info' => [
                'description' => 'Get detailed information about a host including inventory, groups, interfaces, and tags.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_host_uptime' => [
                'description' => 'Get the current uptime of a host from the system.uptime item.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_host_os' => [
                'description' => 'Get the operating system of a host from the system.sw.os item.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_triggers' => [
                'description' => 'Get triggers with optional filters. You can search by template name OR hostname. When the user mentions a template name, always use the template parameter instead of hostname.',
                'params' => [
                    'template' => '(string, optional) Filter by template name. Use this when the user specifies a template (e.g. "Windows Monitoring Zabbix Agent Active"). Takes priority over hostname.',
                    'hostname' => '(string, optional) Filter by hostname. Only used if template is not given.',
                    'search' => '(string, optional) Search trigger name/description text.',
                    'value' => '(int, optional) 0=OK, 1=PROBLEM.',
                    'min_severity' => '(int, optional) Minimum severity 0-5.',
                    'limit' => '(int, optional) Max results, default 50.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_items' => [
                'description' => 'Get monitored items with optional filters.',
                'params' => [
                    'hostname' => '(string, optional) Filter by hostname.',
                    'search' => '(string, optional) Search item name text.',
                    'status' => '(int, optional) 0=enabled, 1=disabled.',
                    'limit' => '(int, optional) Max results, default 50.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'create_maintenance' => [
                'description' => 'Create a maintenance window for one or more hosts.',
                'params' => [
                    'hostnames' => '(array of strings, required) List of hostnames to put in maintenance.',
                    'duration_hours' => '(number, required) Duration in hours.',
                    'start_time' => '(string, optional) Start time in ISO 8601 or "YYYY-MM-DD HH:MM" format. Defaults to now.',
                    'name' => '(string, optional) Maintenance window name.',
                    'description' => '(string, optional) Description.'
                ],
                'rw' => 'write',
                'category' => 'maintenance'
            ],
            'update_trigger' => [
                'description' => 'Update a trigger. IMPORTANT: First use get_triggers to find the trigger ID, then call this tool. FIELD NAMES in Zabbix: "comments" is the operational notes/comment text field. "description" is the trigger NAME/title. Do NOT change "description" or "expression" unless the user explicitly asks to rename the trigger or change the expression. When the user says "update comment" or "change comment", use the "comments" field.',
                'params' => [
                    'trigger_id' => '(string, required) The trigger ID to update. Use get_triggers to find it first.',
                    'changes' => '(object, required) Fields to change. Allowed fields: comments (operational notes text), description (trigger name - ONLY if user wants to rename), expression (ONLY if user explicitly wants to change the expression), priority (0-5), status (0=enabled, 1=disabled), recovery_expression.'
                ],
                'rw' => 'write',
                'category' => 'triggers'
            ],
            'update_item' => [
                'description' => 'Update an item. First use get_items to find the item, then update it.',
                'params' => [
                    'item_id' => '(string, required) The item ID to update. Use get_items to find it.',
                    'changes' => '(object, required) Fields to change. Allowed: status (0=enabled, 1=disabled), delay, name, description, history, trends.'
                ],
                'rw' => 'write',
                'category' => 'items'
            ],
            'create_user' => [
                'description' => 'Create a new Zabbix user.',
                'params' => [
                    'username' => '(string, required) Login username.',
                    'name' => '(string, optional) First name.',
                    'surname' => '(string, optional) Last name.',
                    'passwd' => '(string, required) Password (min 8 chars).',
                    'usrgrpids' => '(array of strings, required) User group IDs.',
                    'roleid' => '(int, required) Role ID (1=User, 2=Admin, 3=Super admin).'
                ],
                'rw' => 'write',
                'category' => 'users'
            ],
            'acknowledge_problem' => [
                'description' => 'Acknowledge, close, or add a message to a problem event.',
                'params' => [
                    'eventid' => '(string, required) The event ID.',
                    'action' => '(int, required) Bitmask: 1=close, 2=acknowledge, 4=add message, 8=change severity. Combine with +.',
                    'message' => '(string, optional) Comment message.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ]
        ];
    }

    /**
     * Return tool definitions filtered by the given permissions.
     */
    public static function getToolDefinitions(array $permissions): array {
        $tools = self::allTools();
        $result = [];

        foreach ($tools as $name => $tool) {
            if ($tool['rw'] === 'read') {
                $result[$name] = $tool;
                continue;
            }

            // Write tool — check if the mode is readwrite and the category is allowed.
            if (($permissions['mode'] ?? 'read') !== 'readwrite') {
                continue;
            }

            $cat = $tool['category'];
            if ($cat !== '' && empty($permissions['write_permissions'][$cat])) {
                continue;
            }

            $result[$name] = $tool;
        }

        return $result;
    }

    /**
     * Build the tool-description block for the AI system prompt.
     */
    public static function buildToolSystemPrompt(array $permissions): string {
        $tools = self::getToolDefinitions($permissions);

        if (!$tools) {
            return '';
        }

        $lines = [];
        $lines[] = 'You have access to Zabbix tools. When you need to query or modify Zabbix, respond with ONLY a JSON tool call in this exact format (no other text):';
        $lines[] = '{"tool": "tool_name", "params": {"param1": "value1"}}';
        $lines[] = '';
        $lines[] = 'For WRITE actions (create_maintenance, update_trigger, update_item, create_user, acknowledge_problem), you MUST first describe what you will do and ask for confirmation. Respond with:';
        $lines[] = '{"tool": "tool_name", "params": {...}, "confirm": true, "confirm_message": "I will [describe exactly what will be changed, including which field]. Should I proceed?"}';
        $lines[] = '';
        $lines[] = 'For update_trigger, ALWAYS specify in the confirm_message which Zabbix field you will change (e.g. "comments", "expression", "priority") and what the new value will be.';
        $lines[] = '';
        $lines[] = 'For READ actions, execute them immediately without confirmation.';
        $lines[] = '';
        $lines[] = 'If the user message is a normal conversation or troubleshooting question that does not require a Zabbix tool, respond normally with text.';
        $lines[] = '';
        $lines[] = 'Available tools:';
        $lines[] = '';

        foreach ($tools as $name => $tool) {
            $rw_label = $tool['rw'] === 'write' ? ' [WRITE]' : ' [READ]';
            $lines[] = '### '.$name.$rw_label;
            $lines[] = $tool['description'];
            $lines[] = 'Parameters:';

            foreach ($tool['params'] as $pname => $pdesc) {
                $lines[] = '  - '.$pname.': '.$pdesc;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Try to parse a tool call from an AI response.
     *
     * Returns ['tool' => ..., 'params' => ..., 'confirm' => bool, 'confirm_message' => string]
     * or null if the response is not a tool call.
     */
    public static function parseToolCall(string $response): ?array {
        $trimmed = trim($response);

        // Try to extract JSON from the response — it may be wrapped in markdown code fences.
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        // If it starts with '{' try direct parse.
        if (strncmp($trimmed, '{', 1) !== 0) {
            // Check if the response contains a JSON block somewhere.
            $json_start = strpos($trimmed, '{"tool"');
            if ($json_start === false) {
                return null;
            }
            $trimmed = substr($trimmed, $json_start);
            // Find the matching closing brace.
            $depth = 0;
            $end = 0;
            for ($i = 0, $len = strlen($trimmed); $i < $len; $i++) {
                if ($trimmed[$i] === '{') $depth++;
                if ($trimmed[$i] === '}') $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
            if ($end > 0) {
                $trimmed = substr($trimmed, 0, $end);
            }
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded) || !isset($decoded['tool'])) {
            return null;
        }

        $tool_name = trim((string) ($decoded['tool'] ?? ''));

        if ($tool_name === '' || !isset(self::allTools()[$tool_name])) {
            return null;
        }

        return [
            'tool' => $tool_name,
            'params' => is_array($decoded['params'] ?? null) ? $decoded['params'] : [],
            'confirm' => !empty($decoded['confirm']),
            'confirm_message' => trim((string) ($decoded['confirm_message'] ?? ''))
        ];
    }

    /**
     * Check if a tool is a write action and return its category.
     * Returns '' for read tools, or the category name for write tools.
     */
    public static function getWriteCategory(string $tool_name): string {
        $tools = self::allTools();
        $tool = $tools[$tool_name] ?? null;

        if ($tool === null || $tool['rw'] !== 'write') {
            return '';
        }

        return $tool['category'];
    }

    /**
     * Execute a tool call and return the result as a formatted string.
     */
    public static function execute(string $tool_name, array $params, ZabbixApiClient $zabbix_api): string {
        switch ($tool_name) {
            case 'get_problems':
                return self::executeGetProblems($params, $zabbix_api);

            case 'get_unsupported_items':
                return self::executeGetUnsupportedItems($params, $zabbix_api);

            case 'get_host_info':
                return self::executeGetHostInfo($params, $zabbix_api);

            case 'get_host_uptime':
                return self::executeGetHostUptime($params, $zabbix_api);

            case 'get_host_os':
                return self::executeGetHostOs($params, $zabbix_api);

            case 'get_triggers':
                return self::executeGetTriggers($params, $zabbix_api);

            case 'get_items':
                return self::executeGetItems($params, $zabbix_api);

            case 'create_maintenance':
                return self::executeCreateMaintenance($params, $zabbix_api);

            case 'update_trigger':
                return self::executeUpdateTrigger($params, $zabbix_api);

            case 'update_item':
                return self::executeUpdateItem($params, $zabbix_api);

            case 'create_user':
                return self::executeCreateUser($params, $zabbix_api);

            case 'acknowledge_problem':
                return self::executeAcknowledgeProblem($params, $zabbix_api);

            default:
                throw new RuntimeException('Unknown tool: '.$tool_name);
        }
    }

    // ── Read tool executors ────────────────────────────────────────

    private static function executeGetProblems(array $params, ZabbixApiClient $api): string {
        $problems = $api->getProblemsFiltered($params);

        if (!$problems) {
            return 'No problems found matching the given filters.';
        }

        $lines = ['Found '.count($problems).' problem(s):', ''];

        foreach ($problems as $p) {
            $sev = self::SEVERITY_LABELS[$p['severity'] ?? '0'] ?? 'Unknown';
            $ack = !empty($p['acknowledged']) ? 'Acknowledged' : 'Unacknowledged';
            $hosts = [];
            foreach (($p['hosts'] ?? []) as $h) {
                $hosts[] = $h['host'] ?? $h['name'] ?? '?';
            }
            $host_str = $hosts ? implode(', ', $hosts) : 'N/A';
            $time = isset($p['clock']) ? date('Y-m-d H:i:s', (int) $p['clock']) : '';

            $lines[] = '- [Event '.$p['eventid'].'] ['.$sev.'] ['.$ack.'] '.$p['name'];
            $lines[] = '  Host(s): '.$host_str.($time ? '  Time: '.$time : '');
        }

        return implode("\n", $lines);
    }

    private static function executeGetUnsupportedItems(array $params, ZabbixApiClient $api): string {
        $items = $api->getUnsupportedItems(
            (string) ($params['host_group'] ?? ''),
            true,
            true,
            (int) ($params['limit'] ?? 200)
        );

        if (!$items) {
            return 'No unsupported items found.';
        }

        // Group by host.
        $by_host = [];
        foreach ($items as $item) {
            $host_name = 'Unknown';
            foreach (($item['hosts'] ?? []) as $h) {
                $host_name = $h['host'] ?? $h['name'] ?? 'Unknown';
                break;
            }
            $by_host[$host_name][] = $item;
        }

        $lines = ['Found '.count($items).' unsupported item(s) across '.count($by_host).' host(s):', ''];

        foreach ($by_host as $host => $host_items) {
            $lines[] = '## Host: '.$host.' ('.count($host_items).' items)';
            foreach ($host_items as $item) {
                $lines[] = '- '.$item['name'].' (key: '.$item['key_'].')';
                if (!empty($item['error'])) {
                    $lines[] = '  Error: '.$item['error'];
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function executeGetHostInfo(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname parameter is required.';
        }

        $host = $api->getHostInfo($hostname);

        if ($host === null) {
            return 'Host "'.$hostname.'" not found.';
        }

        $lines = ['Host: '.$host['host'].' ('.$host['name'].')'];
        $lines[] = 'Status: '.($host['status'] === '0' ? 'Enabled' : 'Disabled');
        $lines[] = 'Maintenance: '.($host['maintenance_status'] === '1' ? 'In maintenance' : 'Normal');

        if (!empty($host['description'])) {
            $lines[] = 'Description: '.$host['description'];
        }

        $groups = [];
        foreach (($host['groups'] ?? []) as $g) {
            $groups[] = $g['name'] ?? '';
        }
        if ($groups) {
            $lines[] = 'Groups: '.implode(', ', array_filter($groups));
        }

        $interfaces = [];
        foreach (($host['interfaces'] ?? []) as $iface) {
            $ip = $iface['ip'] ?? '';
            $dns = $iface['dns'] ?? '';
            $addr = $ip !== '' ? $ip : $dns;
            $interfaces[] = $addr.':'.$iface['port'];
        }
        if ($interfaces) {
            $lines[] = 'Interfaces: '.implode(', ', $interfaces);
        }

        $tags = [];
        foreach (($host['tags'] ?? []) as $t) {
            $tags[] = $t['tag'].($t['value'] !== '' ? '='.$t['value'] : '');
        }
        if ($tags) {
            $lines[] = 'Tags: '.implode(', ', $tags);
        }

        $inv = $host['inventory'] ?? [];
        if (is_array($inv) && array_filter($inv)) {
            $lines[] = '';
            $lines[] = 'Inventory:';
            $inv_fields = ['os', 'os_full', 'hardware', 'software', 'contact', 'location', 'serialno_a', 'model', 'vendor', 'type'];
            foreach ($inv_fields as $f) {
                if (!empty($inv[$f])) {
                    $lines[] = '  '.ucfirst(str_replace('_', ' ', $f)).': '.$inv[$f];
                }
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetHostUptime(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname parameter is required.';
        }

        $result = $api->getHostUptime($hostname);

        if ($result === null) {
            return 'Could not retrieve uptime for host "'.$hostname.'". The host may not exist or may not have a system.uptime item.';
        }

        return 'Host: '.$result['hostname']."\n"
            .'Uptime: '.$result['uptime_formatted']."\n"
            .'Last check: '.$result['last_check'];
    }

    private static function executeGetHostOs(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname parameter is required.';
        }

        $host_id = $api->getHostIdByName($hostname);

        if ($host_id === null) {
            return 'Host "'.$hostname.'" not found.';
        }

        // Get the full OS string, not just the category.
        $items = $api->call('item.get', [
            'hostids' => [$host_id],
            'search' => ['key_' => 'system.sw.os'],
            'output' => ['lastvalue', 'lastclock']
        ]);

        $lastvalue = trim((string) ($items[0]['lastvalue'] ?? ''));

        if ($lastvalue === '') {
            return 'Host "'.$hostname.'" does not have an OS detection item or it has no data.';
        }

        return 'Host: '.$hostname."\n".'Operating System: '.$lastvalue;
    }

    private static function executeGetTriggers(array $params, ZabbixApiClient $api): string {
        $triggers = $api->getTriggersFiltered(
            (string) ($params['hostname'] ?? ''),
            [
                'template' => $params['template'] ?? null,
                'search' => $params['search'] ?? null,
                'value' => $params['value'] ?? null,
                'min_severity' => $params['min_severity'] ?? null
            ],
            (int) ($params['limit'] ?? 50)
        );

        if (!$triggers) {
            return 'No triggers found matching the given filters.';
        }

        $lines = ['Found '.count($triggers).' trigger(s):', ''];

        foreach ($triggers as $t) {
            $sev = self::SEVERITY_LABELS[$t['priority'] ?? '0'] ?? 'Unknown';
            $status = ($t['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled';
            $state = ($t['value'] ?? '0') === '1' ? 'PROBLEM' : 'OK';
            $hosts = [];
            foreach (($t['hosts'] ?? []) as $h) {
                $hosts[] = $h['host'] ?? '';
            }
            $comments_preview = '';
            if (!empty($t['comments'])) {
                $c = trim((string) $t['comments']);
                if (strlen($c) > 100) {
                    $c = substr($c, 0, 100).'...';
                }
                $comments_preview = $c;
            }

            $lines[] = '- [ID: '.$t['triggerid'].'] ['.$sev.'] ['.$state.'] ['.$status.'] '.$t['description'];
            $lines[] = '  Expression: '.$t['expression'];
            if ($hosts) {
                $lines[] = '  Host/Template: '.implode(', ', array_filter($hosts));
            }
            if ($comments_preview !== '') {
                $lines[] = '  Comments: '.$comments_preview;
            } else {
                $lines[] = '  Comments: (empty)';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private static function executeGetItems(array $params, ZabbixApiClient $api): string {
        $items = $api->getItemsFiltered(
            (string) ($params['hostname'] ?? ''),
            [
                'search' => $params['search'] ?? null,
                'status' => $params['status'] ?? null
            ],
            (int) ($params['limit'] ?? 50)
        );

        if (!$items) {
            return 'No items found matching the given filters.';
        }

        $lines = ['Found '.count($items).' item(s):', ''];

        foreach ($items as $item) {
            $status = ($item['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled';
            $state = ($item['state'] ?? '0') === '1' ? 'UNSUPPORTED' : 'Normal';
            $hosts = [];
            foreach (($item['hosts'] ?? []) as $h) {
                $hosts[] = $h['host'] ?? '';
            }

            $lines[] = '- [ID: '.$item['itemid'].'] ['.$status.'] ['.$state.'] '.$item['name'];
            $lines[] = '  Key: '.$item['key_'];
            if (!empty($item['lastvalue'])) {
                $lines[] = '  Last value: '.$item['lastvalue'];
            }
            if (!empty($item['error'])) {
                $lines[] = '  Error: '.$item['error'];
            }
            if ($hosts) {
                $lines[] = '  Host(s): '.implode(', ', array_filter($hosts));
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ── Write tool executors ───────────────────────────────────────

    private static function executeCreateMaintenance(array $params, ZabbixApiClient $api): string {
        $hostnames = (array) ($params['hostnames'] ?? []);

        if (!$hostnames) {
            return 'Error: hostnames parameter is required (array of hostnames).';
        }

        $duration = (float) ($params['duration_hours'] ?? 0);

        if ($duration <= 0) {
            return 'Error: duration_hours must be greater than 0.';
        }

        $result = $api->createMaintenance(
            $hostnames,
            $duration,
            isset($params['start_time']) ? (string) $params['start_time'] : null,
            (string) ($params['name'] ?? ''),
            (string) ($params['description'] ?? '')
        );

        return 'Maintenance window created successfully.'
            ."\nID: ".$result['maintenanceid']
            ."\nName: ".$result['name']
            ."\nHosts: ".implode(', ', $result['hosts'])
            ."\nStart: ".$result['start']
            ."\nEnd: ".$result['end']
            ."\nDuration: ".$result['duration_hours'].' hours';
    }

    private static function executeUpdateTrigger(array $params, ZabbixApiClient $api): string {
        $trigger_id = trim((string) ($params['trigger_id'] ?? ''));

        if ($trigger_id === '') {
            return 'Error: trigger_id parameter is required.';
        }

        $changes = (array) ($params['changes'] ?? []);

        if (!$changes) {
            return 'Error: changes parameter is required.';
        }

        // Safety: fetch current trigger state before updating so we can report what changed.
        $current = $api->call('trigger.get', [
            'triggerids' => [$trigger_id],
            'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'status'],
            'limit' => 1
        ]);

        if (!$current) {
            return 'Error: trigger ID '.$trigger_id.' not found.';
        }

        $before = $current[0];

        $api->updateTrigger($trigger_id, $changes);

        // Build a detailed change report.
        $report = ['Trigger '.$trigger_id.' updated successfully.'];
        $report[] = 'Trigger name: '.($before['description'] ?? 'N/A');
        $report[] = '';

        $field_labels = [
            'comments' => 'Comments/Notes',
            'description' => 'Trigger name',
            'expression' => 'Expression',
            'priority' => 'Severity',
            'status' => 'Status',
            'recovery_expression' => 'Recovery expression',
            'url' => 'URL'
        ];

        foreach ($changes as $key => $new_value) {
            $label = $field_labels[$key] ?? $key;
            $old_value = $before[$key] ?? '(not available)';

            if ($key === 'comments') {
                $report[] = 'Field: '.$label;
                $report[] = 'Old value: '.($old_value !== '' ? $old_value : '(empty)');
                $report[] = 'New value: '.$new_value;
            }
            else {
                $report[] = $label.': '.$old_value.' -> '.$new_value;
            }
        }

        return implode("\n", $report);
    }

    private static function executeUpdateItem(array $params, ZabbixApiClient $api): string {
        $item_id = trim((string) ($params['item_id'] ?? ''));

        if ($item_id === '') {
            return 'Error: item_id parameter is required.';
        }

        $changes = (array) ($params['changes'] ?? []);

        if (!$changes) {
            return 'Error: changes parameter is required.';
        }

        $api->updateItem($item_id, $changes);

        return 'Item '.$item_id.' updated successfully. Changed fields: '.implode(', ', array_keys($changes));
    }

    private static function executeCreateUser(array $params, ZabbixApiClient $api): string {
        $username = trim((string) ($params['username'] ?? ''));

        if ($username === '') {
            return 'Error: username parameter is required.';
        }

        $passwd = (string) ($params['passwd'] ?? '');

        if (strlen($passwd) < 8) {
            return 'Error: passwd must be at least 8 characters.';
        }

        $usrgrpids = (array) ($params['usrgrpids'] ?? []);

        if (!$usrgrpids) {
            return 'Error: usrgrpids parameter is required (array of user group IDs).';
        }

        $result = $api->createUser(
            $username,
            (string) ($params['name'] ?? ''),
            (string) ($params['surname'] ?? ''),
            $passwd,
            $usrgrpids,
            (int) ($params['roleid'] ?? 1)
        );

        $userid = $result['userids'][0] ?? 'unknown';

        return 'User "'.$username.'" created successfully with ID: '.$userid;
    }

    private static function executeAcknowledgeProblem(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid parameter is required.';
        }

        $action = (int) ($params['action'] ?? 4);
        $message = (string) ($params['message'] ?? '');

        $api->acknowledgeProblem($eventid, $action, $message);

        $actions_taken = [];
        if ($action & 1) $actions_taken[] = 'closed';
        if ($action & 2) $actions_taken[] = 'acknowledged';
        if ($action & 4) $actions_taken[] = 'message added';
        if ($action & 8) $actions_taken[] = 'severity changed';

        return 'Event '.$eventid.' updated: '.implode(', ', $actions_taken ?: ['action '.$action]).'.';
    }
}
