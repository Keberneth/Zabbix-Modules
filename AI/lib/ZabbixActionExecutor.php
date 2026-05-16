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

    /**
     * Sentinel prefix: when a tool returns a result starting with this marker,
     * the caller (ChatSend / ChatExecute) skips the AI re-formatting pass and
     * shows the remaining text verbatim. Use this for outputs that contain
     * carefully-built artefacts (download URLs, embedded images) the AI must
     * not rewrite.
     */
    public const RAW_OUTPUT_SENTINEL = "[[AI-RAW]]\n";

    private const SEVERITY_LABELS = [
        '0' => 'Not classified',
        '1' => 'Information',
        '2' => 'Warning',
        '3' => 'Average',
        '4' => 'High',
        '5' => 'Disaster'
    ];

    /**
     * If $output starts with the raw-output sentinel, return the stripped text.
     * Otherwise return null. Used by the chat controllers to decide whether to
     * bypass the AI formatting pass.
     */
    public static function extractRawOutput(string $output): ?string {
        if (strncmp($output, self::RAW_OUTPUT_SENTINEL, strlen(self::RAW_OUTPUT_SENTINEL)) !== 0) {
            return null;
        }

        return substr($output, strlen(self::RAW_OUTPUT_SENTINEL));
    }

    /**
     * Server-side schema for write tools.
     *
     * The map is `tool_name => [param_name => [type, required]]`. Types:
     *   'string'      — non-empty trimmed string
     *   'int'         — integer (numeric string accepted)
     *   'number'      — int or float (numeric string accepted)
     *   'bool'        — boolean (PHP truthy)
     *   'array'       — array with at least one element
     *   'array_str'   — array, every element a non-empty string
     *   'object'      — associative array
     *
     * Required entries fail validation when missing or when the value does not
     * match the type. Optional entries fail only when present and of the wrong
     * type. Unknown params are passed through but logged.
     *
     * Read tools are intentionally not schema-validated: they tolerate fuzzy AI
     * inputs and are side-effect-free. Write tools must pass validation before
     * the executor dispatches.
     */
    public static function writeToolSchemas(): array {
        return [
            'create_maintenance' => [
                'hostnames'      => ['array_str', true],
                'duration_hours' => ['number',    true],
                'start_time'     => ['string',    false],
                'name'           => ['string',    false],
                'description'    => ['string',    false],
                'data_collection'=> ['bool',      false]
            ],
            'create_hostgroup_maintenance' => [
                'group_names'    => ['array_str', true],
                'duration_hours' => ['number',    true],
                'start_time'     => ['string',    false],
                'name'           => ['string',    false],
                'description'    => ['string',    false],
                'data_collection'=> ['bool',      false]
            ],
            'create_tag_scoped_maintenance' => [
                'tags'           => ['array',     true],
                'duration_hours' => ['number',    true],
                'hostnames'      => ['array_str', false],
                'group_names'    => ['array_str', false],
                'start_time'     => ['string',    false],
                'name'           => ['string',    false],
                'description'    => ['string',    false],
                'data_collection'=> ['bool',      false],
                'tags_evaltype'  => ['int',       false]
            ],
            'extend_maintenance' => [
                'maintenance_id'   => ['string', true],
                'additional_hours' => ['number', true]
            ],
            'end_maintenance' => [
                'maintenance_id' => ['string', true],
                'delete'         => ['bool',   false]
            ],
            'update_trigger' => [
                'trigger_id' => ['string', true],
                'changes'    => ['object', true]
            ],
            'update_item' => [
                'item_id' => ['string', true],
                'changes' => ['object', true]
            ],
            'create_user' => [
                'username'  => ['string',     true],
                'passwd'    => ['string',     true],
                'usrgrpids' => ['array',      true],
                'roleid'    => ['int',        true],
                'name'      => ['string',     false],
                'surname'   => ['string',     false]
            ],
            'acknowledge_problem' => [
                'eventid' => ['string', true],
                'action'  => ['int',    true],
                'message' => ['string', false]
            ],
            'suppress_problem' => [
                'eventid'         => ['string', true],
                'suppress_until'  => ['int',    false]
            ],
            'unsuppress_problem' => [
                'eventid' => ['string', true]
            ],
            'mark_problem_as_cause' => [
                'eventid' => ['string', true]
            ],
            'mark_problem_as_symptom' => [
                'eventid' => ['string', true]
            ],
            'add_hosts_to_group' => [
                'hostnames'    => ['array_str', true],
                'group_name'   => ['string',    true],
                'create_group' => ['bool',      false]
            ],
            'create_host_group' => [
                'name' => ['string', true]
            ],
            'post_evidence_to_event' => [
                'eventid'      => ['string', true],
                'report_token' => ['string', true],
                'note'         => ['string', false]
            ]
        ];
    }

    /**
     * Validate $params against the schema for $tool_name (write tools only).
     *
     * Returns a list of human-readable error strings; empty when valid. Callers
     * (ChatExecute, ChatSend) MUST reject the call when the array is non-empty
     * — never trust the AI to pass valid arguments.
     */
    public static function validateWriteParams(string $tool_name, array $params): array {
        $schemas = self::writeToolSchemas();

        if (!isset($schemas[$tool_name])) {
            return [];
        }

        $errors = [];

        foreach ($schemas[$tool_name] as $name => $rule) {
            [$type, $required] = $rule;
            $present = array_key_exists($name, $params);
            $value = $present ? $params[$name] : null;

            if (!$present) {
                if ($required) {
                    $errors[] = 'missing required parameter "'.$name.'" ('.$type.')';
                }
                continue;
            }

            $ok = self::checkType($type, $value);

            if (!$ok) {
                $errors[] = 'parameter "'.$name.'" must be of type '.$type;
            }
        }

        return $errors;
    }

    private static function checkType(string $type, $value): bool {
        switch ($type) {
            case 'string':
                return is_string($value) && trim($value) !== '';
            case 'int':
                return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value));
            case 'number':
                return is_int($value) || is_float($value)
                    || (is_string($value) && is_numeric($value));
            case 'bool':
                return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false', 'yes', 'no'], true);
            case 'array':
                return is_array($value) && count($value) > 0;
            case 'array_str':
                if (!is_array($value) || count($value) === 0) {
                    return false;
                }
                foreach ($value as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        return false;
                    }
                }
                return true;
            case 'object':
                if (!is_array($value)) {
                    return false;
                }
                if ($value === []) {
                    return false;
                }
                // Associative array: keys must not be all sequential ints.
                $keys = array_keys($value);
                return $keys !== range(0, count($keys) - 1);
        }

        return false;
    }

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
                'description' => 'Create a maintenance window for one or more individual hosts. Use create_hostgroup_maintenance when the user targets a host group, and create_tag_scoped_maintenance when only certain trigger tags should be suppressed.',
                'params' => [
                    'hostnames' => '(array of strings, required) List of hostnames to put in maintenance.',
                    'duration_hours' => '(number, required) Duration in hours.',
                    'start_time' => '(string, optional) Start time in ISO 8601 or "YYYY-MM-DD HH:MM" format. Defaults to now.',
                    'name' => '(string, optional) Maintenance window name.',
                    'description' => '(string, optional) Description.',
                    'data_collection' => '(bool, optional, default true) When false, Zabbix stops collecting data from the host for the duration of the window (maintenance_type=1). True keeps collecting data but suppresses alerting.'
                ],
                'rw' => 'write',
                'category' => 'maintenance'
            ],
            'create_hostgroup_maintenance' => [
                'description' => 'Create a maintenance window targeting one or more host groups. All hosts currently in those groups are affected.',
                'params' => [
                    'group_names' => '(array of strings, required) Host group names to put in maintenance.',
                    'duration_hours' => '(number, required) Duration in hours.',
                    'start_time' => '(string, optional) Start time. Defaults to now.',
                    'name' => '(string, optional) Maintenance window name.',
                    'description' => '(string, optional) Description.',
                    'data_collection' => '(bool, optional, default true) False stops data collection for the duration.'
                ],
                'rw' => 'write',
                'category' => 'maintenance'
            ],
            'create_tag_scoped_maintenance' => [
                'description' => 'Create a maintenance window that only suppresses problems whose triggers match the given tags. Much safer than blanket host maintenance because unrelated alerts still fire. Either hostnames or group_names (or both) is required.',
                'params' => [
                    'hostnames' => '(array of strings, optional) Hostnames to scope.',
                    'group_names' => '(array of strings, optional) Host groups to scope.',
                    'tags' => '(array, required) Tag filters. Each entry: {"tag": "service", "operator": 0, "value": "mysql"}. operator: 0=Equals (default), 2=Contains.',
                    'duration_hours' => '(number, required) Duration in hours.',
                    'start_time' => '(string, optional) Start time. Defaults to now.',
                    'name' => '(string, optional) Maintenance window name.',
                    'description' => '(string, optional) Description.',
                    'data_collection' => '(bool, optional, default true) False stops data collection.',
                    'tags_evaltype' => '(int, optional, default 0) 0=And/Or, 2=Or.'
                ],
                'rw' => 'write',
                'category' => 'maintenance'
            ],
            'list_active_maintenance' => [
                'description' => 'List maintenance windows currently in effect (or all known windows when only_active is false).',
                'params' => [
                    'only_active' => '(bool, optional, default true) When true, only windows that are active right now are returned.',
                    'limit' => '(int, optional) Max windows to return. Default 50.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'extend_maintenance' => [
                'description' => 'Extend an existing maintenance window by N additional hours past its current end time. Use list_active_maintenance first to find the maintenance ID.',
                'params' => [
                    'maintenance_id' => '(string, required) ID of the maintenance to extend.',
                    'additional_hours' => '(number, required) Hours to add on top of the current end time (or now, whichever is later).'
                ],
                'rw' => 'write',
                'category' => 'maintenance'
            ],
            'end_maintenance' => [
                'description' => 'End a maintenance window immediately. By default the active_till is set to now (record kept for audit). Pass delete=true to fully remove the maintenance record. Use list_active_maintenance first to find the maintenance ID.',
                'params' => [
                    'maintenance_id' => '(string, required) ID of the maintenance to end.',
                    'delete' => '(bool, optional, default false) When true, the maintenance record is deleted instead of just ended.'
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
            ],
            'add_hosts_to_group' => [
                'description' => 'Add one or more hosts to a host group. If the host group does not exist, you can create it automatically by setting create_group to true. Useful for organizing hosts (e.g. "add all MSSQL hosts to a Microsoft SQL Server group"). First use get_host_info or get_triggers with a template filter to identify the relevant hosts, then use this tool to add them.',
                'params' => [
                    'hostnames' => '(array of strings, required) List of technical hostnames to add to the group.',
                    'group_name' => '(string, required) The name of the host group.',
                    'create_group' => '(bool, optional) If true, create the host group if it does not exist. Default false — will ask for confirmation first if group is missing.'
                ],
                'rw' => 'write',
                'category' => 'hostgroups'
            ],
            'create_host_group' => [
                'description' => 'Create a new host group in Zabbix.',
                'params' => [
                    'name' => '(string, required) The name for the new host group.'
                ],
                'rw' => 'write',
                'category' => 'hostgroups'
            ],
            'generate_report' => [
                'description' => 'Generate a downloadable report file and return a Markdown link the user can click to download it. Use this when the user asks for a "report", "export", "download" or wants the result as a file. Currently supports report_type "unsupported_items" (items in unsupported state with the failure reason). Always preserve the returned Markdown link exactly as-is in your reply so the user can click it.',
                'params' => [
                    'report_type' => '(string, required) Report to generate. Allowed: "unsupported_items".',
                    'format' => '(string, optional) Output format: "csv" (default, opens in Excel), "html" (styled table in browser), or "json".',
                    'host_group' => '(string, optional) Filter by host group name (unsupported_items only).',
                    'limit' => '(int, optional) Max rows, default 1000.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_actions_for_event' => [
                'description' => 'List the configured trigger actions (alert rules) and whether each one matches the given event. Use this when the user asks why an alert did or did not notify someone. Returns a match_status of "matched", "did_not_match", "disabled" or "undetermined" with the specific reasons.',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_alerts_for_event' => [
                'description' => 'List the actual alerts (notifications) that Zabbix attempted to send for an event. Each entry includes the user, media type, send-to address, status (Sent / Failed / Not sent), retry count and error message.',
                'params' => [
                    'eventid' => '(string, required) The event ID.',
                    'limit' => '(int, optional) Max alerts to return. Default 100.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_mediatypes_status' => [
                'description' => 'List configured media types (email, SMS, webhooks, etc.) with their enabled/disabled status. Useful when a "Failed" alert points at a disabled or broken media type.',
                'params' => [
                    'limit' => '(int, optional) Max media types. Default 50.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_user_media_for_problem' => [
                'description' => 'Show which users would receive notifications for an event (based on matched actions) and how their media is configured. Useful for diagnosing "no one was notified" cases.',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_escalation_path' => [
                'description' => 'Combined view of an event\'s alert-delivery path: matched actions, attempted alerts with status, and intended recipients. The single best tool when answering "why did this alert not notify anyone?".',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'generate_problem_graph' => [
                'description' => 'Generate a stacked bar chart of problems over time, coloured by Zabbix severity (Information=blue, Warning=yellow, Average=orange, High=red-orange, Disaster=red). Use this when the user asks for a graph, chart, or visual of problems over a period (e.g. "how many problems in the last 2 weeks, graph"). The reply contains an inline image plus a download link — always preserve the Markdown image and link syntax exactly as returned so the chat can render the chart inline.',
                'params' => [
                    'period_days' => '(int, optional, default 14) Number of days back from now to include.',
                    'group_by' => '(string, optional, default "day") Bucket size: "hour", "day", or "week".',
                    'severity_min' => '(int, optional, default 0) Minimum severity 0-5.',
                    'host_group' => '(string, optional) Restrict to a host group name.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'generate_evidence_bundle' => [
                'description' => 'Generate a full evidence / RCA bundle for a problem event and return a Markdown download link. The bundle includes event details, trigger expression, item history, recent problems on the same host, host inventory/templates, maintenance status, audit trail and operator comments. Use this when the user asks for an "evidence bundle", "RCA bundle", "context dump" or "everything we have about this problem".',
                'params' => [
                    'eventid' => '(string, required) The event ID to build the bundle around.',
                    'format' => '(string, optional) "md" (default, human-readable Markdown) or "json".',
                    'period_hours' => '(int, optional, default 24) How many hours of history to include.',
                    'include_audit' => '(bool, optional, default true) Whether to include recent Zabbix audit log entries.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_event_timeline' => [
                'description' => 'Reconstruct the timeline of a problem event: when it opened, when it recovered, and every operator action (ack, close, comment, severity change, suppress, unsuppress, rank change).',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_related_problems' => [
                'description' => 'Find problems related to an event: same host(s) and same trigger tags within a recent time window. Useful for correlation ("are other problems happening at the same time?").',
                'params' => [
                    'eventid' => '(string, required) The event ID to correlate from.',
                    'window_hours' => '(int, optional, default 24) Lookback window in hours.',
                    'limit' => '(int, optional, default 50) Max problems per scope.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_recent_changes' => [
                'description' => 'List recent Zabbix audit-log changes for a specific object (trigger, item, host, action, etc.). Use this to answer "what changed recently?". The resourcetype value follows Zabbix audit constants: 4=host, 13=trigger, 15=item, 5=action, 14=template, 11=user.',
                'params' => [
                    'resourcetype' => '(int, required) Zabbix audit resourcetype constant.',
                    'resourceid' => '(string, required) ID of the object to inspect.',
                    'since_unix' => '(int, optional) UNIX timestamp lower bound. 0 = no lower bound.',
                    'limit' => '(int, optional, default 50) Max entries.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_service_impact' => [
                'description' => 'Show the IT service tree and report which services are affected by the given problem event. Falls back to "service.get not available" on Zabbix configurations without the Services module enabled.',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_host_templates' => [
                'description' => 'List templates linked to a host (direct + inherited) and any inherited tags.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_effective_macros' => [
                'description' => 'Show all user macros that apply to a host: host-level macros plus macros inherited from linked templates. Secret macros are masked.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_lld_rules' => [
                'description' => 'List low-level discovery (LLD) rules configured on a host, with state, status and any error message.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_proxy_status' => [
                'description' => 'List all Zabbix proxies with their last-seen time, version and availability state. Useful when monitoring stops collecting data on a passive proxy.',
                'params' => [],
                'rw' => 'read',
                'category' => ''
            ],
            'get_action_config' => [
                'description' => 'Return the full configuration of a single Zabbix trigger action (filter, operations, recovery and update operations). Find the action ID first via get_actions_for_event.',
                'params' => [
                    'actionid' => '(string, required) The action ID.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_auditlog_for_object' => [
                'description' => 'Audit log entries scoped to a specific Zabbix object. resourcetype constants: 4=host, 13=trigger, 15=item, 5=action, 14=template, 11=user.',
                'params' => [
                    'resourcetype' => '(int, required) Zabbix audit resourcetype constant.',
                    'resourceid' => '(string, required) ID of the object to inspect.',
                    'since_unix' => '(int, optional) UNIX timestamp lower bound.',
                    'limit' => '(int, optional, default 50) Max entries.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'suppress_problem' => [
                'description' => 'Suppress a problem event so it no longer escalates / pages anyone. Supports an optional "suppress_until" UNIX timestamp; omit for indefinite suppression.',
                'params' => [
                    'eventid' => '(string, required) The event ID.',
                    'suppress_until' => '(int, optional) UNIX timestamp at which suppression ends. Omit for indefinite suppression.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'unsuppress_problem' => [
                'description' => 'Lift suppression on a previously suppressed problem event.',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'mark_problem_as_cause' => [
                'description' => 'Promote a problem event to "cause" rank in Zabbix cause/symptom problem grouping.',
                'params' => [
                    'eventid' => '(string, required) The event ID to mark as the cause.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'mark_problem_as_symptom' => [
                'description' => 'Mark a problem event as a symptom of another cause event.',
                'params' => [
                    'eventid' => '(string, required) The event ID to mark as a symptom.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'post_evidence_to_event' => [
                'description' => 'Post a short summary of a previously generated evidence bundle as a comment on the event (with the download link). Use after generate_evidence_bundle has produced a download link and the user explicitly asks to post it.',
                'params' => [
                    'eventid' => '(string, required) The event ID to comment on.',
                    'report_token' => '(string, required) The download token from generate_evidence_bundle.',
                    'note' => '(string, optional) Free-text note to prepend to the comment.'
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
        $lines[] = 'For WRITE actions (create_maintenance, update_trigger, update_item, create_user, acknowledge_problem, add_hosts_to_group, create_host_group), you MUST first describe what you will do and ask for confirmation. Respond with:';
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
     * Strip all JSON tool call blocks from a response string.
     *
     * Removes any {"tool":"...",...} blocks (including markdown-fenced ones)
     * so that raw tool JSON is never shown to the user.
     */
    public static function stripToolCalls(string $response): string {
        // Remove markdown-fenced tool calls.
        $cleaned = preg_replace('/```(?:json)?\s*\{"tool"\s*:[\s\S]*?\}\s*```/', '', $response);

        // Remove bare {"tool":...} blocks.
        $result = '';
        $len = strlen($cleaned);
        $i = 0;

        while ($i < $len) {
            $next = strpos($cleaned, '{"tool"', $i);

            if ($next === false) {
                $result .= substr($cleaned, $i);
                break;
            }

            $result .= substr($cleaned, $i, $next - $i);

            // Find the matching closing brace.
            $depth = 0;
            $end = $next;

            for ($j = $next; $j < $len; $j++) {
                if ($cleaned[$j] === '{') $depth++;
                if ($cleaned[$j] === '}') $depth--;
                if ($depth === 0) {
                    $end = $j + 1;
                    break;
                }
            }

            $i = $end;
        }

        // Clean up excessive whitespace left behind.
        $result = preg_replace('/\n{3,}/', "\n\n", $result);

        return trim($result);
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
     *
     * @param array $context Optional execution context. Recognized keys:
     *   - 'config' (array)         Module config, required for tools that persist files.
     *   - 'server_session' (string) Server session id for binding generated artifacts.
     */
    public static function execute(string $tool_name, array $params, ZabbixApiClient $zabbix_api, array $context = []): string {
        switch ($tool_name) {
            case 'get_problems':
                return self::executeGetProblems($params, $zabbix_api);

            case 'get_unsupported_items':
                return self::executeGetUnsupportedItems($params, $zabbix_api);

            case 'generate_report':
                return self::executeGenerateReport($params, $zabbix_api, $context);

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

            case 'create_hostgroup_maintenance':
                return self::executeCreateHostGroupMaintenance($params, $zabbix_api);

            case 'create_tag_scoped_maintenance':
                return self::executeCreateTagScopedMaintenance($params, $zabbix_api);

            case 'list_active_maintenance':
                return self::executeListActiveMaintenance($params, $zabbix_api);

            case 'extend_maintenance':
                return self::executeExtendMaintenance($params, $zabbix_api);

            case 'end_maintenance':
                return self::executeEndMaintenance($params, $zabbix_api);

            case 'get_actions_for_event':
                return self::executeGetActionsForEvent($params, $zabbix_api);

            case 'get_alerts_for_event':
                return self::executeGetAlertsForEvent($params, $zabbix_api);

            case 'get_mediatypes_status':
                return self::executeGetMediaTypesStatus($params, $zabbix_api);

            case 'get_user_media_for_problem':
                return self::executeGetUserMediaForProblem($params, $zabbix_api);

            case 'get_escalation_path':
                return self::executeGetEscalationPath($params, $zabbix_api);

            case 'generate_problem_graph':
                return self::executeGenerateProblemGraph($params, $zabbix_api, $context);

            case 'generate_evidence_bundle':
                return self::executeGenerateEvidenceBundle($params, $zabbix_api, $context);

            case 'post_evidence_to_event':
                return self::executePostEvidenceToEvent($params, $zabbix_api, $context);

            case 'get_event_timeline':
                return self::executeGetEventTimeline($params, $zabbix_api);

            case 'get_related_problems':
                return self::executeGetRelatedProblems($params, $zabbix_api);

            case 'get_recent_changes':
            case 'get_auditlog_for_object':
                return self::executeGetAuditLogForObject($params, $zabbix_api);

            case 'get_service_impact':
                return self::executeGetServiceImpact($params, $zabbix_api);

            case 'get_host_templates':
                return self::executeGetHostTemplates($params, $zabbix_api);

            case 'get_effective_macros':
                return self::executeGetEffectiveMacros($params, $zabbix_api);

            case 'get_lld_rules':
                return self::executeGetLldRules($params, $zabbix_api);

            case 'get_proxy_status':
                return self::executeGetProxyStatus($params, $zabbix_api);

            case 'get_action_config':
                return self::executeGetActionConfig($params, $zabbix_api);

            case 'suppress_problem':
                return self::executeSuppressProblem($params, $zabbix_api);

            case 'unsuppress_problem':
                return self::executeUnsuppressProblem($params, $zabbix_api);

            case 'mark_problem_as_cause':
                return self::executeMarkProblemAsCause($params, $zabbix_api);

            case 'mark_problem_as_symptom':
                return self::executeMarkProblemAsSymptom($params, $zabbix_api);

            case 'update_trigger':
                return self::executeUpdateTrigger($params, $zabbix_api);

            case 'update_item':
                return self::executeUpdateItem($params, $zabbix_api);

            case 'create_user':
                return self::executeCreateUser($params, $zabbix_api);

            case 'acknowledge_problem':
                return self::executeAcknowledgeProblem($params, $zabbix_api);

            case 'add_hosts_to_group':
                return self::executeAddHostsToGroup($params, $zabbix_api);

            case 'create_host_group':
                return self::executeCreateHostGroup($params, $zabbix_api);

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

    private static function executeGenerateReport(array $params, ZabbixApiClient $api, array $context): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $server_session = (string) ($context['server_session'] ?? '');

        if ($config === null || $server_session === '') {
            return 'Error: report generation is not available in this context.';
        }

        $report_type = trim((string) ($params['report_type'] ?? ''));
        $format = strtolower(trim((string) ($params['format'] ?? 'csv')));

        if (!in_array($format, ReportStore::ALLOWED_FORMATS, true)) {
            return 'Error: format must be one of '.implode(', ', ReportStore::ALLOWED_FORMATS).'.';
        }

        if ($report_type === 'unsupported_items') {
            return self::buildUnsupportedItemsReport($params, $api, $config, $server_session, $format);
        }

        return 'Error: unknown report_type "'.$report_type.'". Allowed: unsupported_items.';
    }

    private static function buildUnsupportedItemsReport(array $params, ZabbixApiClient $api, array $config, string $server_session, string $format): string {
        $host_group = trim((string) ($params['host_group'] ?? ''));
        $limit = (int) ($params['limit'] ?? 1000);
        $limit = max(1, min($limit, 5000));

        $items = $api->getUnsupportedItems($host_group, true, true, $limit);

        $columns = ['host', 'host_visible_name', 'item_name', 'item_key', 'error', 'last_check', 'status'];
        $headers = ['Host', 'Host visible name', 'Item', 'Item key', 'Reason (error)', 'Last check', 'Status'];

        $rows = [];

        foreach ($items as $item) {
            $host_tech = '';
            $host_visible = '';

            foreach (($item['hosts'] ?? []) as $h) {
                $host_tech = (string) ($h['host'] ?? '');
                $host_visible = (string) ($h['name'] ?? $host_tech);
                break;
            }

            $last_clock = (int) ($item['lastclock'] ?? 0);
            $last_check = $last_clock > 0 ? date('Y-m-d H:i:s', $last_clock) : '';
            $status = ((string) ($item['status'] ?? '0')) === '0' ? 'Enabled' : 'Disabled';

            $rows[] = [
                'host' => $host_tech,
                'host_visible_name' => $host_visible,
                'item_name' => (string) ($item['name'] ?? ''),
                'item_key' => (string) ($item['key_'] ?? ''),
                'error' => (string) ($item['error'] ?? ''),
                'last_check' => $last_check,
                'status' => $status
            ];
        }

        usort($rows, static function ($a, $b) {
            $cmp = strcasecmp($a['host'], $b['host']);
            return $cmp !== 0 ? $cmp : strcasecmp($a['item_name'], $b['item_name']);
        });

        $meta = [
            'title' => 'Unsupported items report',
            'generated_at' => time(),
            'filters' => array_filter([
                'host_group' => $host_group,
                'limit' => $limit
            ], static function ($v) {
                return $v !== '' && $v !== null;
            })
        ];

        $result = ReportStore::create(
            $config,
            $server_session,
            'unsupported_items',
            $format,
            $columns,
            $headers,
            $rows,
            $meta
        );

        $expires_in_min = max(1, (int) round(($result['expires_at'] - time()) / 60));
        $size_kb = max(1, (int) round($result['size'] / 1024));

        $filter_summary = $host_group !== '' ? ' (host group: '.$host_group.')' : '';

        $lines = [];
        $lines[] = 'Report generated: **'.count($rows).' unsupported item(s)**'.$filter_summary.'.';
        $lines[] = '';
        $lines[] = '[Download '.$result['filename'].']('.$result['url'].') &mdash; '.strtoupper($format).', ~'.$size_kb.' KB, expires in ~'.$expires_in_min.' min.';

        return self::RAW_OUTPUT_SENTINEL.implode("\n", $lines);
    }

    private static function executeGenerateProblemGraph(array $params, ZabbixApiClient $api, array $context): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $server_session = (string) ($context['server_session'] ?? '');

        if ($config === null || $server_session === '') {
            return 'Error: graph generation is not available in this context.';
        }

        $period_days = max(1, min((int) ($params['period_days'] ?? 14), 90));
        $group_by = strtolower(trim((string) ($params['group_by'] ?? 'day')));
        if (!in_array($group_by, ['hour', 'day', 'week'], true)) {
            $group_by = 'day';
        }

        $severity_min = max(0, min((int) ($params['severity_min'] ?? 0), 5));
        $host_group = trim((string) ($params['host_group'] ?? ''));

        $host_group_ids = [];

        if ($host_group !== '') {
            $g = $api->getHostGroupByName($host_group);
            if ($g === null) {
                return 'Error: host group "'.$host_group.'" not found.';
            }
            $host_group_ids[] = (string) $g['groupid'];
        }

        $until = time();
        $since = $until - ($period_days * 86400);

        $events = $api->getProblemsTimeline($since, $until, $severity_min, $host_group_ids, 20000);

        $buckets = self::bucketProblems($events, $since, $until, $group_by);

        $total = 0;
        $by_severity_total = array_fill(0, 6, 0);
        foreach ($buckets['data'] as $bucket) {
            foreach ($bucket['counts'] as $sev => $count) {
                $by_severity_total[$sev] += $count;
                $total += $count;
            }
        }

        $title = 'Problems over the last '.$period_days.' day(s)'
            .($host_group !== '' ? ' — '.$host_group : '')
            .($severity_min > 0 ? ' (severity ≥ '.$severity_min.')' : '');

        $svg = self::renderProblemBarChartSvg($title, $buckets, $by_severity_total, $total);

        $report_type = 'problems_'.$period_days.'d_'.$group_by;
        $svg_result = ReportStore::createDocument(
            $config,
            $server_session,
            $report_type,
            'svg',
            $svg,
            ['generated_at' => time()]
        );

        $expires_in_min = max(1, (int) round(($svg_result['expires_at'] - time()) / 60));
        $inline_url = $svg_result['url'].'&inline=1';

        $summary_lines = [
            'Total problems: '.$total.' across '.count($buckets['data']).' '.$group_by.'(s).',
            ''
        ];

        $sev_labels = self::SEVERITY_LABELS;
        foreach ([5, 4, 3, 2, 1, 0] as $sev) {
            if ($by_severity_total[$sev] > 0) {
                $summary_lines[] = '- '.$sev_labels[(string) $sev].': '.$by_severity_total[$sev];
            }
        }

        $summary_lines[] = '';
        $summary_lines[] = '![Problems over the last '.$period_days.' days]('.$inline_url.')';
        $summary_lines[] = '';
        $summary_lines[] = '[Download '.$svg_result['filename'].']('.$svg_result['url'].') &mdash; SVG, expires in ~'.$expires_in_min.' min.';

        return self::RAW_OUTPUT_SENTINEL.implode("\n", $summary_lines);
    }

    /**
     * Bucket raw {clock, severity} rows into time-aligned buckets.
     *
     * @return array{labels: string[], data: array<int, array{label: string, counts: array<int,int>}>}
     */
    private static function bucketProblems(array $events, int $since, int $until, string $group_by): array {
        $bucket_size = ['hour' => 3600, 'day' => 86400, 'week' => 7 * 86400][$group_by];
        $date_fmt = ['hour' => 'm-d H:00', 'day' => 'Y-m-d', 'week' => 'Y \W\eW'][$group_by];

        // Align "since" to bucket boundary.
        if ($group_by === 'day') {
            $since_aligned = strtotime('today 00:00', $since);
        }
        elseif ($group_by === 'hour') {
            $since_aligned = $since - ($since % 3600);
        }
        else {
            // Align to start of ISO week (Monday 00:00).
            $since_aligned = strtotime('monday this week 00:00', $since);
            if ($since_aligned > $since) {
                $since_aligned -= 7 * 86400;
            }
        }

        $buckets = [];
        for ($t = $since_aligned; $t < $until; $t += $bucket_size) {
            $buckets[$t] = [
                'label' => date($date_fmt, $t),
                'counts' => array_fill(0, 6, 0)
            ];
        }

        foreach ($events as $ev) {
            $clock = (int) $ev['clock'];
            if ($group_by === 'day') {
                $bucket_key = strtotime('today 00:00', $clock);
            }
            elseif ($group_by === 'hour') {
                $bucket_key = $clock - ($clock % 3600);
            }
            else {
                $bucket_key = strtotime('monday this week 00:00', $clock);
                if ($bucket_key > $clock) {
                    $bucket_key -= 7 * 86400;
                }
            }

            if (!isset($buckets[$bucket_key])) {
                continue;
            }

            $sev = max(0, min((int) $ev['severity'], 5));
            $buckets[$bucket_key]['counts'][$sev]++;
        }

        $data = [];
        foreach ($buckets as $bucket) {
            $data[] = $bucket;
        }

        return ['data' => $data];
    }

    private const SEVERITY_COLORS = [
        0 => '#97AAB3', // Not classified
        1 => '#7499FF', // Information
        2 => '#FFC859', // Warning
        3 => '#FFA059', // Average
        4 => '#E97659', // High
        5 => '#E45959'  // Disaster
    ];

    /**
     * Render a stacked bar chart of problem counts per bucket as a self-contained SVG.
     */
    private static function renderProblemBarChartSvg(string $title, array $buckets, array $by_severity_total, int $total): string {
        $data = $buckets['data'] ?? [];
        $bucket_count = max(1, count($data));

        $width = max(640, min(1200, 80 + $bucket_count * 40));
        $height = 360;
        $margin_top = 50;
        $margin_bottom = 90;
        $margin_left = 60;
        $margin_right = 180; // room for legend
        $plot_w = $width - $margin_left - $margin_right;
        $plot_h = $height - $margin_top - $margin_bottom;

        $max_count = 0;
        foreach ($data as $bucket) {
            $sum = array_sum($bucket['counts']);
            if ($sum > $max_count) {
                $max_count = $sum;
            }
        }

        $y_max = self::niceCeil(max($max_count, 1));
        $bar_slot = $plot_w / max($bucket_count, 1);
        $bar_w = max(4, $bar_slot * 0.75);

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $parts = [];
        $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" font-family="-apple-system, Segoe UI, Roboto, sans-serif">';

        // Background.
        $parts[] = '<rect width="100%" height="100%" fill="#ffffff"/>';

        // Title.
        $parts[] = '<text x="'.($width / 2).'" y="26" font-size="16" font-weight="600" text-anchor="middle" fill="#222">'.$h($title).'</text>';

        // Plot area frame.
        $parts[] = '<rect x="'.$margin_left.'" y="'.$margin_top.'" width="'.$plot_w.'" height="'.$plot_h.'" fill="#fafafa" stroke="#e0e0e0"/>';

        // Y axis gridlines + labels.
        $gridlines = 5;
        for ($i = 0; $i <= $gridlines; $i++) {
            $value = (int) round($y_max * $i / $gridlines);
            $y = $margin_top + $plot_h - ($plot_h * $i / $gridlines);
            $parts[] = '<line x1="'.$margin_left.'" y1="'.$y.'" x2="'.($margin_left + $plot_w).'" y2="'.$y.'" stroke="#e8e8e8" stroke-width="1"/>';
            $parts[] = '<text x="'.($margin_left - 8).'" y="'.($y + 4).'" font-size="11" text-anchor="end" fill="#555">'.$h($value).'</text>';
        }

        // Bars (stacked by severity, low to high so disasters end on top).
        $x = $margin_left + ($bar_slot - $bar_w) / 2;
        foreach ($data as $bucket) {
            $stack_y = $margin_top + $plot_h;

            foreach ([0, 1, 2, 3, 4, 5] as $sev) {
                $count = (int) ($bucket['counts'][$sev] ?? 0);
                if ($count <= 0) {
                    continue;
                }
                $seg_h = $plot_h * ($count / $y_max);
                $stack_y -= $seg_h;
                $color = self::SEVERITY_COLORS[$sev];
                $parts[] = '<rect x="'.$h(number_format($x, 2, '.', '')).'" y="'.$h(number_format($stack_y, 2, '.', '')).'" width="'.$h(number_format($bar_w, 2, '.', '')).'" height="'.$h(number_format($seg_h, 2, '.', '')).'" fill="'.$color.'"><title>'.$h(self::SEVERITY_LABELS[(string) $sev].': '.$count).'</title></rect>';
            }

            // X label.
            $label_x = $x + $bar_w / 2;
            $label_y = $margin_top + $plot_h + 18;
            $rotation = $bucket_count > 14 ? -45 : 0;
            $anchor = $rotation === 0 ? 'middle' : 'end';
            $parts[] = '<text x="'.$h(number_format($label_x, 2, '.', '')).'" y="'.$h($label_y).'" font-size="11" text-anchor="'.$anchor.'" fill="#555" transform="rotate('.$rotation.' '.$h(number_format($label_x, 2, '.', '')).' '.$h($label_y).')">'.$h($bucket['label']).'</text>';

            $x += $bar_slot;
        }

        // Legend.
        $legend_x = $margin_left + $plot_w + 20;
        $legend_y = $margin_top + 4;
        $parts[] = '<text x="'.$legend_x.'" y="'.$legend_y.'" font-size="12" font-weight="600" fill="#222">Severity</text>';
        $line_y = $legend_y + 18;
        foreach ([5, 4, 3, 2, 1, 0] as $sev) {
            $color = self::SEVERITY_COLORS[$sev];
            $label = self::SEVERITY_LABELS[(string) $sev].' ('.(int) ($by_severity_total[$sev] ?? 0).')';
            $parts[] = '<rect x="'.$legend_x.'" y="'.($line_y - 10).'" width="14" height="12" fill="'.$color.'"/>';
            $parts[] = '<text x="'.($legend_x + 20).'" y="'.$line_y.'" font-size="11" fill="#333">'.$h($label).'</text>';
            $line_y += 18;
        }

        $parts[] = '<text x="'.$legend_x.'" y="'.($line_y + 12).'" font-size="11" font-style="italic" fill="#666">Total: '.$h($total).'</text>';

        $parts[] = '</svg>';

        return implode("\n", $parts);
    }

    private static function niceCeil(int $value): int {
        if ($value <= 5)   return 5;
        if ($value <= 10)  return 10;
        if ($value <= 20)  return 20;
        if ($value <= 50)  return 50;
        if ($value <= 100) return 100;

        $power = (int) pow(10, floor(log10($value)));
        return (int) (ceil($value / $power) * $power);
    }

    // ── Alert-delivery troubleshooting executors ───────────────────

    private static function executeGetActionsForEvent(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $actions = $api->getActionsForEvent($eventid);

        if (!$actions) {
            return 'No trigger actions are configured.';
        }

        $matched = [];
        $not_matched = [];
        $disabled = [];
        $undetermined = [];

        foreach ($actions as $a) {
            switch ($a['match_status'] ?? '') {
                case 'matched':       $matched[] = $a; break;
                case 'did_not_match': $not_matched[] = $a; break;
                case 'disabled':      $disabled[] = $a; break;
                default:              $undetermined[] = $a;
            }
        }

        $lines = [];
        $lines[] = 'Actions evaluated for event '.$eventid.':';
        $lines[] = '- Matched: '.count($matched);
        $lines[] = '- Did not match: '.count($not_matched);
        $lines[] = '- Disabled: '.count($disabled);
        $lines[] = '- Undetermined: '.count($undetermined);
        $lines[] = '';

        $emit = static function (array $group, string $label) use (&$lines) {
            if (!$group) {
                return;
            }
            $lines[] = '### '.$label;
            foreach ($group as $a) {
                $lines[] = '- ['.$a['actionid'].'] '.$a['name'];
                foreach ((array) ($a['reasons'] ?? []) as $reason) {
                    $lines[] = '  · '.$reason;
                }
            }
            $lines[] = '';
        };

        $emit($matched,      'Matched');
        $emit($not_matched,  'Did not match');
        $emit($disabled,     'Disabled');
        $emit($undetermined, 'Undetermined (manual review)');

        return rtrim(implode("\n", $lines));
    }

    private static function executeGetAlertsForEvent(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $limit = (int) ($params['limit'] ?? 100);
        $alerts = $api->getAlertsForEvent($eventid, $limit);

        if (!$alerts) {
            return 'No alerts were sent for event '.$eventid.'. Either no action matched, the event has been suppressed, or escalation has not reached a notify step yet.';
        }

        $lines = ['Found '.count($alerts).' alert attempt(s) for event '.$eventid.':', ''];

        $status_map = ['0' => 'Not sent', '1' => 'Sent', '2' => 'Failed', '3' => 'New'];

        foreach ($alerts as $a) {
            $clock = (int) ($a['clock'] ?? 0);
            $when = $clock > 0 ? date('Y-m-d H:i:s', $clock) : '';
            $status = $status_map[(string) ($a['status'] ?? '')] ?? (string) ($a['status'] ?? '');
            $mt = $a['mediatypes'][0]['name'] ?? '?';
            $user = $a['users'][0]['username'] ?? '';
            $sendto = (string) ($a['sendto'] ?? '');
            $retries = (int) ($a['retries'] ?? 0);
            $error = trim((string) ($a['error'] ?? ''));

            $lines[] = '- ['.$when.'] '.$status.' via '.$mt.($user !== '' ? ' to '.$user : '').($sendto !== '' ? ' ('.$sendto.')' : '');
            if (!empty($a['subject'])) {
                $lines[] = '  Subject: '.$a['subject'];
            }
            if ($retries > 0) {
                $lines[] = '  Retries: '.$retries;
            }
            if ($error !== '') {
                $lines[] = '  Error: '.$error;
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetMediaTypesStatus(array $params, ZabbixApiClient $api): string {
        $limit = (int) ($params['limit'] ?? 50);
        $media = $api->getMediaTypes($limit);

        if (!$media) {
            return 'No media types are configured.';
        }

        $type_map = ['0' => 'Email', '1' => 'Script', '2' => 'SMS', '4' => 'Webhook'];

        $lines = ['Found '.count($media).' media type(s):', ''];

        foreach ($media as $m) {
            $status = (string) ($m['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled';
            $type = $type_map[(string) ($m['type'] ?? '')] ?? 'Type '.($m['type'] ?? '?');
            $lines[] = '- ['.$status.'] '.$m['name'].' ('.$type.', max attempts '.(int) ($m['maxattempts'] ?? 0).')';
        }

        return implode("\n", $lines);
    }

    private static function executeGetUserMediaForProblem(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $result = $api->getUserMediaForProblem($eventid);
        $recipients = $result['recipients'] ?? [];
        $matched_actions = $result['matched_actions'] ?? [];

        if (!$matched_actions) {
            return 'No actions matched this event, so no users would have been notified. Use get_actions_for_event to see why.';
        }

        if (!$recipients) {
            $note = $result['note'] ?? 'Matched actions have no user or group operations configured.';
            return $note;
        }

        $lines = ['Matched actions: '.implode(', ', array_column($matched_actions, 'name')), ''];
        $lines[] = 'Intended recipients ('.count($recipients).'):';
        $lines[] = '';

        foreach ($recipients as $r) {
            $lines[] = '- '.$r['username'].($r['fullname'] !== '' ? ' ('.$r['fullname'].')' : '');
            if (!$r['media']) {
                $lines[] = '  · No media configured — this user will not receive any notification.';
                continue;
            }
            foreach ($r['media'] as $m) {
                $enabled = $m['enabled'] ? 'enabled' : 'DISABLED';
                $lines[] = '  · mediatype '.$m['mediatypeid'].' ['.$enabled.'] sendto='.$m['sendto'].' period='.$m['period'];
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetEscalationPath(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $path = $api->getEscalationPath($eventid);

        $lines = ['Escalation path for event '.$eventid.':', ''];

        $matched = $path['matched_actions'] ?? [];
        $lines[] = '### Matched actions ('.count($matched).')';
        if (!$matched) {
            $lines[] = '- None. No notifications would be sent. Run get_actions_for_event to see why each action was skipped.';
        }
        else {
            foreach ($matched as $a) {
                $lines[] = '- ['.$a['actionid'].'] '.$a['name'];
                foreach ((array) ($a['reasons'] ?? []) as $r) {
                    $lines[] = '  · '.$r;
                }
            }
        }
        $lines[] = '';

        $alerts = $path['alerts'] ?? [];
        $lines[] = '### Alert attempts ('.count($alerts).')';
        if (!$alerts) {
            $lines[] = '- No alert attempts recorded. Escalation may not have reached a notify step, or the event is too old for alert history.';
        }
        else {
            foreach ($alerts as $a) {
                $lines[] = '- ['.$a['clock'].'] '.$a['status'].' via '.($a['mediatype'] ?: '?').' to '.$a['user'].' ('.$a['sendto'].')';
                if ($a['error'] !== '') {
                    $lines[] = '  · Error: '.$a['error'];
                }
            }
        }
        $lines[] = '';

        $recipients = $path['recipients'] ?? [];
        $lines[] = '### Intended recipients ('.count($recipients).')';
        if (!$recipients) {
            $lines[] = '- None resolved.';
        }
        else {
            foreach ($recipients as $r) {
                $lines[] = '- '.$r['username'].($r['fullname'] !== '' ? ' ('.$r['fullname'].')' : '');
                foreach ($r['media'] as $m) {
                    $enabled = $m['enabled'] ? 'enabled' : 'DISABLED';
                    $lines[] = '  · mediatype '.$m['mediatypeid'].' ['.$enabled.'] sendto='.$m['sendto'];
                }
            }
        }

        return implode("\n", $lines);
    }

    // ── New read tools ────────────────────────────────────────────

    private static function executeGetEventTimeline(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $timeline = $api->getEventTimeline($eventid);

        if (!$timeline || empty($timeline['entries'])) {
            return 'No timeline found for event '.$eventid.'.';
        }

        $lines = ['Timeline for event '.$eventid.($timeline['hostname'] !== '' ? ' on `'.$timeline['hostname'].'`' : '').':', ''];

        foreach ($timeline['entries'] as $entry) {
            $when = date('Y-m-d H:i:s', (int) $entry['clock']);
            $lines[] = '- ['.$when.'] '.$entry['description'];
        }

        return implode("\n", $lines);
    }

    private static function executeGetRelatedProblems(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $window_hours = (int) ($params['window_hours'] ?? 24);
        $limit = (int) ($params['limit'] ?? 50);

        $related = $api->getRelatedProblems($eventid, $window_hours, $limit);

        if (!$related) {
            return 'No related problems found for event '.$eventid.'.';
        }

        $lines = ['Related problems for event '.$eventid.' (window: '.$related['window_hours'].'h):', ''];

        $emit = static function (array $rows, string $label) use (&$lines) {
            $lines[] = '### '.$label.' ('.count($rows).')';
            if (!$rows) {
                $lines[] = '- None.';
            }
            else {
                foreach ($rows as $p) {
                    $sev = self::SEVERITY_LABELS[(string) ($p['severity'] ?? '0')] ?? '?';
                    $when = date('Y-m-d H:i', (int) ($p['clock'] ?? 0));
                    $line = '- ['.$when.'] ['.$sev.'] '.($p['name'] ?? '').' (event '.$p['eventid'].')';
                    if (!empty($p['hosts'])) {
                        $hosts = array_column($p['hosts'], 'host');
                        if ($hosts) {
                            $line .= ' on '.implode(', ', $hosts);
                        }
                    }
                    $lines[] = $line;
                }
            }
            $lines[] = '';
        };

        $emit($related['by_host'] ?? [], 'Same host(s)');
        $emit($related['by_tag'] ?? [], 'Same trigger tag(s)');

        return rtrim(implode("\n", $lines));
    }

    private static function executeGetAuditLogForObject(array $params, ZabbixApiClient $api): string {
        $resourcetype = isset($params['resourcetype']) ? (int) $params['resourcetype'] : -1;
        $resourceid = trim((string) ($params['resourceid'] ?? ''));

        if ($resourcetype < 0 || $resourceid === '') {
            return 'Error: resourcetype and resourceid are required.';
        }

        $since = (int) ($params['since_unix'] ?? 0);
        $limit = (int) ($params['limit'] ?? 50);

        $entries = $api->getAuditLogForObject($resourcetype, $resourceid, $since, $limit);

        if (!$entries) {
            return 'No audit log entries for resourcetype='.$resourcetype.', resourceid='.$resourceid.'.';
        }

        $lines = [count($entries).' audit log entry/entries for resourcetype='.$resourcetype.', resourceid='.$resourceid.':', ''];

        foreach ($entries as $e) {
            $when = date('Y-m-d H:i:s', (int) ($e['clock'] ?? 0));
            $action = (string) ($e['action'] ?? '');
            $note = (string) ($e['note'] ?? ($e['details_int'] ?? ''));
            $username = (string) ($e['username'] ?? '');
            $resource = (string) ($e['resourcename'] ?? '');
            $row = '- ['.$when.'] action='.$action;
            if ($username !== '') $row .= ' user='.$username;
            if ($resource !== '') $row .= ' resource="'.$resource.'"';
            if ($note !== '') $row .= ' note='.self::truncateCell($note, 200);
            $lines[] = $row;
        }

        return implode("\n", $lines);
    }

    private static function executeGetServiceImpact(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $impact = $api->getServiceImpact($eventid);

        if (!$impact || isset($impact['error'])) {
            return 'No service-tree data available'.(isset($impact['error']) ? ' ('.$impact['error'].')' : '').'.';
        }

        $services = $impact['services'] ?? [];
        if (!$services) {
            return 'No services are configured. The Services module may be unused on this Zabbix instance.';
        }

        $status_labels = ['-1' => 'OK', '0' => 'Not classified', '1' => 'Information', '2' => 'Warning', '3' => 'Average', '4' => 'High', '5' => 'Disaster'];

        $lines = ['Service tree ('.count($services).' service(s)):', ''];

        foreach ($services as $svc) {
            $status = $status_labels[(string) ($svc['status'] ?? '-1')] ?? $svc['status'];
            $lines[] = '- ['.$svc['serviceid'].'] '.$svc['name'].' — status: '.$status;
            $parents = array_column($svc['parents'] ?? [], 'name');
            $children = array_column($svc['children'] ?? [], 'name');
            if ($parents)  $lines[] = '  parents: '.implode(', ', $parents);
            if ($children) $lines[] = '  children: '.implode(', ', $children);
        }

        return implode("\n", $lines);
    }

    private static function executeGetHostTemplates(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }

        $info = $api->getHostTemplates($hostname);

        if (!$info) {
            return 'Host "'.$hostname.'" not found.';
        }

        $lines = ['Templates linked to host `'.$info['hostname'].'`:', ''];

        if (!$info['templates']) {
            $lines[] = '- None.';
        }
        else {
            foreach ($info['templates'] as $t) {
                $lines[] = '- ['.$t['templateid'].'] '.($t['name'] ?? $t['host'] ?? '?');
            }
        }

        if (!empty($info['inherited_tags'])) {
            $lines[] = '';
            $lines[] = 'Inherited tags:';
            foreach ($info['inherited_tags'] as $t) {
                $lines[] = '- '.$t['tag'].(($t['value'] ?? '') !== '' ? '='.$t['value'] : '');
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetEffectiveMacros(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }

        $macros = $api->getEffectiveMacros($hostname);

        if (!$macros) {
            return 'Host "'.$hostname.'" not found.';
        }

        $lines = ['Effective user macros for host `'.$macros['hostname'].'`:', ''];

        $lines[] = '### Host-level ('.count($macros['host_macros']).')';
        if (!$macros['host_macros']) {
            $lines[] = '- None.';
        }
        else {
            foreach ($macros['host_macros'] as $m) {
                $lines[] = '- '.$m['macro'].' = '.$m['value']
                    .(((int) ($m['type'] ?? 0) === 1) ? ' (secret)' : '');
            }
        }
        $lines[] = '';
        $lines[] = '### Inherited from templates ('.count($macros['template_macros']).')';
        if (!$macros['template_macros']) {
            $lines[] = '- None.';
        }
        else {
            foreach ($macros['template_macros'] as $m) {
                $lines[] = '- '.$m['macro'].' = '.$m['value']
                    .(((int) ($m['type'] ?? 0) === 1) ? ' (secret)' : '');
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetLldRules(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }

        $rules = $api->getLldRules($hostname);

        if (!$rules) {
            return 'No LLD rules found for host "'.$hostname.'".';
        }

        $lines = ['LLD rules on host `'.$hostname.'` ('.count($rules).'):', ''];

        foreach ($rules as $r) {
            $status = (string) ($r['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled';
            $state = (string) ($r['state'] ?? '0') === '1' ? 'UNSUPPORTED' : 'Normal';
            $lines[] = '- ['.$r['itemid'].'] ['.$status.'] ['.$state.'] '.$r['name'].' (key: '.$r['key_'].')';
            if (!empty($r['error'])) {
                $lines[] = '  Error: '.$r['error'];
            }
        }

        return implode("\n", $lines);
    }

    private static function executeGetProxyStatus(array $params, ZabbixApiClient $api): string {
        $proxies = $api->getProxyStatus();

        if (!$proxies) {
            return 'No Zabbix proxies are configured.';
        }

        $now = time();
        $lines = ['Zabbix proxies ('.count($proxies).'):', ''];

        foreach ($proxies as $p) {
            $name = (string) ($p['name'] ?? $p['host'] ?? '?');
            $last = (int) ($p['lastaccess'] ?? 0);
            $age = $last > 0 ? ($now - $last) : null;
            $age_str = $age === null
                ? 'never seen'
                : ($age < 60 ? $age.'s ago' : ($age < 3600 ? round($age / 60).'m ago' : round($age / 3600, 1).'h ago'));

            $mode_or_status = isset($p['operating_mode'])
                ? ((int) $p['operating_mode'] === 0 ? 'active' : 'passive')
                : ((string) ($p['status'] ?? '') === '5' ? 'active' : 'passive');

            $version = (string) ($p['version'] ?? '?');
            $lines[] = '- ['.$p['proxyid'].'] '.$name.' — '.$mode_or_status.' — last seen '.$age_str.' (v'.$version.')';
        }

        return implode("\n", $lines);
    }

    private static function executeGetActionConfig(array $params, ZabbixApiClient $api): string {
        $actionid = trim((string) ($params['actionid'] ?? ''));

        if ($actionid === '') {
            return 'Error: actionid is required.';
        }

        $action = $api->getActionConfig($actionid);

        if (!$action) {
            return 'Action '.$actionid.' not found.';
        }

        $lines = ['Action ['.$action['actionid'].'] '.($action['name'] ?? ''), ''];
        $lines[] = 'Status: '.((string) ($action['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled');
        $lines[] = 'Escalation period: '.($action['esc_period'] ?? '?');

        $filter = $action['filter'] ?? [];
        $conditions = $filter['conditions'] ?? [];
        $lines[] = '';
        $lines[] = 'Conditions ('.count($conditions).'):';
        foreach ($conditions as $c) {
            $lines[] = '- type='.($c['conditiontype'] ?? '?').' op='.($c['operator'] ?? '?').' value='.($c['value'] ?? '');
        }

        $ops = $action['operations'] ?? [];
        $lines[] = '';
        $lines[] = 'Operations ('.count($ops).'):';
        foreach ($ops as $op) {
            $targets = [];
            foreach (($op['opmessage_usr'] ?? []) as $u) {
                $targets[] = 'user:'.$u['userid'];
            }
            foreach (($op['opmessage_grp'] ?? []) as $g) {
                $targets[] = 'group:'.$g['usrgrpid'];
            }
            $lines[] = '- type='.($op['operationtype'] ?? '?').' targets=['.implode(', ', $targets).']';
        }

        return implode("\n", $lines);
    }

    // ── New write tools ───────────────────────────────────────────

    private static function executeSuppressProblem(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $until = (int) ($params['suppress_until'] ?? 0);
        $api->suppressProblem($eventid, $until);

        return 'Event '.$eventid.' suppressed'
            .($until > 0 ? ' until '.date('Y-m-d H:i:s', $until) : ' indefinitely').'.';
    }

    private static function executeUnsuppressProblem(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $api->unsuppressProblem($eventid);

        return 'Event '.$eventid.' unsuppressed.';
    }

    private static function executeMarkProblemAsCause(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $api->markProblemAsCause($eventid);

        return 'Event '.$eventid.' marked as a cause.';
    }

    private static function executeMarkProblemAsSymptom(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $api->markProblemAsSymptom($eventid);

        return 'Event '.$eventid.' marked as a symptom.';
    }

    private static function executeGenerateEvidenceBundle(array $params, ZabbixApiClient $api, array $context): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $server_session = (string) ($context['server_session'] ?? '');

        if ($config === null || $server_session === '') {
            return 'Error: evidence bundle generation is not available in this context.';
        }

        $eventid = trim((string) ($params['eventid'] ?? ''));

        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $format = strtolower(trim((string) ($params['format'] ?? 'md')));

        if (!in_array($format, ReportStore::ALLOWED_DOCUMENT_FORMATS, true)) {
            return 'Error: format must be one of '.implode(', ', ReportStore::ALLOWED_DOCUMENT_FORMATS).'.';
        }

        $period_hours = (int) ($params['period_hours'] ?? 24);
        $include_audit = array_key_exists('include_audit', $params)
            ? (bool) $params['include_audit']
            : true;

        $bundle = $api->getEvidenceBundle($eventid, $period_hours, 30, $include_audit);

        $content = $format === 'json'
            ? json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : self::renderEvidenceBundleMarkdown($bundle);

        $report_type = 'evidence_event_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $eventid);

        $result = ReportStore::createDocument(
            $config,
            $server_session,
            $report_type,
            $format,
            (string) $content,
            ['generated_at' => time()]
        );

        $expires_in_min = max(1, (int) round(($result['expires_at'] - time()) / 60));
        $size_kb = max(1, (int) round($result['size'] / 1024));

        $event_name = (string) ($bundle['event']['name'] ?? '');
        $hostname = (string) ($bundle['event']['hostname'] ?? '');

        $lines = [];
        $lines[] = 'Evidence bundle generated for event **'.$eventid.'**'
            .($event_name !== '' ? ' — '.$event_name : '')
            .($hostname !== '' ? ' on `'.$hostname.'`' : '').'.';
        $lines[] = '';
        $lines[] = '[Download '.$result['filename'].']('.$result['url'].') &mdash; '.strtoupper($format).', ~'.$size_kb.' KB, expires in ~'.$expires_in_min.' min.';
        $lines[] = '';
        $lines[] = 'Token: `'.$result['token'].'` (use with post_evidence_to_event to attach a summary comment to the event).';

        return self::RAW_OUTPUT_SENTINEL.implode("\n", $lines);
    }

    private static function renderEvidenceBundleMarkdown(array $bundle): string {
        $severity_labels = self::SEVERITY_LABELS;
        $event = $bundle['event'] ?? [];
        $trigger = $bundle['trigger'] ?? null;
        $host = $bundle['host'] ?? null;

        $md = [];
        $md[] = '# Evidence bundle — event '.($bundle['eventid'] ?? '');
        $md[] = '';
        $md[] = 'Generated: '.date('Y-m-d H:i:s', (int) ($bundle['generated_at'] ?? time()));
        $md[] = 'Window: last '.(int) ($bundle['period_hours'] ?? 24).' hours';
        $md[] = '';

        $md[] = '## Event';
        $md[] = '- Name: '.($event['name'] ?? '');
        $md[] = '- Severity: '.($severity_labels[(string) ($event['severity'] ?? 0)] ?? $event['severity'] ?? '?');
        $md[] = '- State: '.((int) ($event['value'] ?? 0) === 1 ? 'PROBLEM' : 'OK');
        $md[] = '- Acknowledged: '.(($event['acknowledged'] ?? false) ? 'yes' : 'no');
        $md[] = '- Suppressed: '.(($event['suppressed'] ?? false) ? 'yes' : 'no');
        $md[] = '- Time: '.($event['clock_str'] ?? '');
        $md[] = '- Recovery event: '.(($event['r_eventid'] ?? '') !== '' && $event['r_eventid'] !== '0' ? $event['r_eventid'] : '(none yet)');
        $md[] = '- Host: '.($event['hostname'] ?? '');
        if (!empty($event['tags'])) {
            $tag_pairs = [];
            foreach ($event['tags'] as $t) {
                $tag_pairs[] = $t['tag'].($t['value'] !== '' ? '='.$t['value'] : '');
            }
            $md[] = '- Tags: '.implode(', ', $tag_pairs);
        }
        $md[] = '';

        if ($trigger !== null) {
            $md[] = '## Trigger';
            $md[] = '- Name: '.$trigger['description'];
            $md[] = '- Expression: `'.$trigger['expression'].'`';
            if ($trigger['recovery_expression'] !== '') {
                $md[] = '- Recovery expression: `'.$trigger['recovery_expression'].'`';
            }
            $md[] = '- Status: '.$trigger['status'];
            $md[] = '- State: '.$trigger['state'];
            if ($trigger['comments'] !== '') {
                $md[] = '- Operational notes:';
                foreach (preg_split('/\r?\n/', $trigger['comments']) as $line) {
                    $md[] = '  > '.$line;
                }
            }
            if (!empty($trigger['dependencies'])) {
                $md[] = '- Depends on:';
                foreach ($trigger['dependencies'] as $d) {
                    $md[] = '  - ['.$d['triggerid'].'] '.$d['description'];
                }
            }
            $md[] = '';
        }

        if (!empty($bundle['items'])) {
            $md[] = '## Items in the trigger';
            foreach ($bundle['items'] as $item) {
                $md[] = '- **'.$item['name'].'** (`'.$item['key_'].'`)'.($item['units'] !== '' ? ' — units: '.$item['units'] : '').' — last value: '.$item['lastvalue'];
            }
            $md[] = '';
        }

        if (!empty($bundle['item_history'])) {
            $md[] = '## Recent values';
            foreach ($bundle['item_history'] as $itemid => $hist) {
                $md[] = '### '.$hist['name'].' (`'.$hist['key_'].'`)';
                $md[] = '';
                $md[] = '| Time | Value |';
                $md[] = '|------|-------|';
                foreach ($hist['values'] as $v) {
                    $val = self::truncateCell((string) $v['value']);
                    $md[] = '| '.$v['time'].' | '.$val.($hist['units'] !== '' ? ' '.$hist['units'] : '').' |';
                }
                $md[] = '';
            }
        }

        if ($host !== null) {
            $md[] = '## Host';
            $md[] = '- Technical name: '.($host['host'] ?? '');
            $md[] = '- Visible name: '.($host['name'] ?? '');
            $md[] = '- Status: '.((string) ($host['status'] ?? '0') === '0' ? 'Enabled' : 'Disabled');
            $md[] = '- Maintenance status: '.((string) ($host['maintenance_status'] ?? '0') === '1' ? 'In maintenance' : 'Normal');

            $groups = [];
            foreach (($host['groups'] ?? []) as $g) {
                $groups[] = $g['name'] ?? '';
            }
            if ($groups) {
                $md[] = '- Groups: '.implode(', ', array_filter($groups));
            }

            if (!empty($bundle['host_templates'])) {
                $md[] = '- Templates: '.implode(', ', $bundle['host_templates']);
            }

            $interfaces = [];
            foreach (($host['interfaces'] ?? []) as $iface) {
                $ip = $iface['ip'] ?? '';
                $dns = $iface['dns'] ?? '';
                $addr = $ip !== '' ? $ip : $dns;
                $interfaces[] = $addr.':'.($iface['port'] ?? '');
            }
            if ($interfaces) {
                $md[] = '- Interfaces: '.implode(', ', $interfaces);
            }

            $tags = [];
            foreach (($host['tags'] ?? []) as $t) {
                $tags[] = $t['tag'].(($t['value'] ?? '') !== '' ? '='.$t['value'] : '');
            }
            if ($tags) {
                $md[] = '- Host tags: '.implode(', ', $tags);
            }

            $inv = $host['inventory'] ?? [];
            if (is_array($inv) && array_filter($inv)) {
                $md[] = '- Inventory:';
                foreach (['os_full', 'hardware', 'software', 'contact', 'location', 'serialno_a', 'model', 'vendor', 'type'] as $f) {
                    if (!empty($inv[$f])) {
                        $md[] = '  - '.ucfirst(str_replace('_', ' ', $f)).': '.$inv[$f];
                    }
                }
            }
            $md[] = '';
        }

        if (!empty($bundle['maintenance'])) {
            $md[] = '## Active maintenance';
            foreach ($bundle['maintenance'] as $m) {
                $type_label = (int) ($m['maintenance_type'] ?? 0) === 1 ? 'No data collection' : 'With data collection';
                $md[] = '- ['.$m['maintenanceid'].'] '.$m['name'].' — '.$type_label;
                $md[] = '  Window: '.date('Y-m-d H:i', (int) $m['active_since']).' → '.date('Y-m-d H:i', (int) $m['active_till']);
                if (!empty($m['tags'])) {
                    $tag_strs = [];
                    foreach ($m['tags'] as $t) {
                        $op = (int) ($t['operator'] ?? 0);
                        $op_label = $op === 2 ? 'contains' : '=';
                        $tag_strs[] = ($t['tag'] ?? '').' '.$op_label.' '.($t['value'] ?? '');
                    }
                    $md[] = '  Tag scope: '.implode(', ', $tag_strs);
                }
            }
            $md[] = '';
        }
        else {
            $md[] = '## Active maintenance';
            $md[] = '- None active on this host.';
            $md[] = '';
        }

        $recent = $bundle['recent_problems'] ?? [];
        $md[] = '## Recent problems on this host (last '.(int) ($bundle['period_hours'] ?? 24).'h)';
        if (!$recent) {
            $md[] = '- None.';
        }
        else {
            foreach ($recent as $p) {
                $sev = $severity_labels[(string) ($p['severity'] ?? '0')] ?? '?';
                $when = date('Y-m-d H:i', (int) ($p['clock'] ?? 0));
                $md[] = '- ['.$when.'] ['.$sev.'] '.($p['name'] ?? '').' (event '.($p['eventid'] ?? '').')';
            }
        }
        $md[] = '';

        $acks = $bundle['acknowledgements'] ?? [];
        $md[] = '## Operator comments & acknowledgements';
        if (!$acks) {
            $md[] = '- None.';
        }
        else {
            foreach ($acks as $a) {
                $md[] = '- ['.$a['clock_str'].'] action='.$a['action'].' user='.$a['userid'];
                if ($a['message'] !== '') {
                    foreach (preg_split('/\r?\n/', $a['message']) as $line) {
                        $md[] = '  > '.$line;
                    }
                }
            }
        }
        $md[] = '';

        $audit = $bundle['audit'] ?? [];
        $md[] = '## Recent audit log entries';
        if (!$audit) {
            $md[] = '- None or audit log is not accessible.';
        }
        else {
            foreach ($audit as $entry) {
                $when = date('Y-m-d H:i:s', (int) ($entry['clock'] ?? 0));
                $action = (string) ($entry['action'] ?? '');
                $resource = (string) ($entry['resourcename'] ?? ($entry['resourceid'] ?? ''));
                $note = (string) ($entry['note'] ?? '');
                $md[] = '- ['.$when.'] action='.$action.' resource='.$resource.($note !== '' ? ' — '.self::truncateCell($note, 200) : '');
            }
        }
        $md[] = '';

        return implode("\n", $md);
    }

    private static function truncateCell(string $value, int $max = 80): string {
        $value = str_replace(["\r\n", "\n", "\r", '|'], [' ', ' ', ' ', '\\|'], $value);
        if (function_exists('mb_strlen') && mb_strlen($value) > $max) {
            return mb_substr($value, 0, $max - 1).'…';
        }
        if (strlen($value) > $max) {
            return substr($value, 0, $max - 1).'…';
        }
        return $value;
    }

    private static function executePostEvidenceToEvent(array $params, ZabbixApiClient $api, array $context): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $server_session = (string) ($context['server_session'] ?? '');

        if ($config === null || $server_session === '') {
            return 'Error: posting evidence is not available in this context.';
        }

        $eventid = trim((string) ($params['eventid'] ?? ''));
        $token = trim((string) ($params['report_token'] ?? ''));

        if ($eventid === '' || $token === '') {
            return 'Error: eventid and report_token are required.';
        }

        try {
            $meta = ReportStore::load($config, $server_session, $token);
        }
        catch (\Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        $note = trim((string) ($params['note'] ?? ''));
        $url = 'zabbix.php?action=ai.report.download&token='.urlencode($token);

        $message_parts = [];
        if ($note !== '') {
            $message_parts[] = $note;
        }
        $message_parts[] = 'Evidence bundle: '.$meta['filename'].' ('.strtoupper($meta['format']).')';
        $message_parts[] = 'Download: '.$url;
        $message_parts[] = 'Expires: '.date('Y-m-d H:i', (int) $meta['expires_at']);

        $message = implode("\n", $message_parts);

        $api->addProblemComment($eventid, $message, 4);

        return 'Evidence bundle link posted to event '.$eventid.'.';
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

        $data_collection = array_key_exists('data_collection', $params)
            ? (bool) $params['data_collection']
            : true;

        $result = $api->createMaintenance(
            $hostnames,
            $duration,
            isset($params['start_time']) ? (string) $params['start_time'] : null,
            (string) ($params['name'] ?? ''),
            (string) ($params['description'] ?? ''),
            $data_collection
        );

        return self::formatMaintenanceResult('Maintenance window created successfully.', $result);
    }

    private static function executeCreateHostGroupMaintenance(array $params, ZabbixApiClient $api): string {
        $group_names = (array) ($params['group_names'] ?? []);

        if (!$group_names) {
            return 'Error: group_names parameter is required (array of host group names).';
        }

        $duration = (float) ($params['duration_hours'] ?? 0);

        if ($duration <= 0) {
            return 'Error: duration_hours must be greater than 0.';
        }

        $data_collection = array_key_exists('data_collection', $params)
            ? (bool) $params['data_collection']
            : true;

        $result = $api->createHostGroupMaintenance(
            $group_names,
            $duration,
            isset($params['start_time']) ? (string) $params['start_time'] : null,
            (string) ($params['name'] ?? ''),
            (string) ($params['description'] ?? ''),
            $data_collection
        );

        return self::formatMaintenanceResult('Host-group maintenance window created.', $result);
    }

    private static function executeCreateTagScopedMaintenance(array $params, ZabbixApiClient $api): string {
        $hostnames = (array) ($params['hostnames'] ?? []);
        $group_names = (array) ($params['group_names'] ?? []);
        $tags = (array) ($params['tags'] ?? []);

        if (!$hostnames && !$group_names) {
            return 'Error: either hostnames or group_names is required.';
        }

        if (!$tags) {
            return 'Error: tags parameter is required (at least one tag filter).';
        }

        $duration = (float) ($params['duration_hours'] ?? 0);

        if ($duration <= 0) {
            return 'Error: duration_hours must be greater than 0.';
        }

        $data_collection = array_key_exists('data_collection', $params)
            ? (bool) $params['data_collection']
            : true;

        $tags_evaltype = isset($params['tags_evaltype']) ? (int) $params['tags_evaltype'] : 0;

        $result = $api->createTagScopedMaintenance(
            $hostnames,
            $group_names,
            $tags,
            $duration,
            isset($params['start_time']) ? (string) $params['start_time'] : null,
            (string) ($params['name'] ?? ''),
            (string) ($params['description'] ?? ''),
            $data_collection,
            $tags_evaltype
        );

        return self::formatMaintenanceResult('Tag-scoped maintenance window created.', $result);
    }

    private static function executeListActiveMaintenance(array $params, ZabbixApiClient $api): string {
        $only_active = array_key_exists('only_active', $params)
            ? (bool) $params['only_active']
            : true;
        $limit = (int) ($params['limit'] ?? 50);

        $maintenances = $api->listMaintenances($only_active, $limit);

        if (!$maintenances) {
            return $only_active
                ? 'No maintenance windows are currently active.'
                : 'No maintenance windows are configured.';
        }

        $lines = [count($maintenances).' maintenance window(s):', ''];

        foreach ($maintenances as $m) {
            $start = (int) ($m['active_since'] ?? 0);
            $end = (int) ($m['active_till'] ?? 0);
            $type = (int) ($m['maintenance_type'] ?? 0);
            $type_label = $type === 1 ? 'No data collection' : 'With data collection';

            $hosts = array_column($m['hosts'] ?? [], 'host');
            $groups = array_column($m['groups'] ?? [], 'name');

            $tag_strs = [];
            foreach (($m['tags'] ?? []) as $t) {
                $op = (int) ($t['operator'] ?? 0);
                $op_label = $op === 2 ? 'contains' : '=';
                $tag_strs[] = ($t['tag'] ?? '').' '.$op_label.' '.($t['value'] ?? '');
            }

            $lines[] = '- [ID '.$m['maintenanceid'].'] '.$m['name'];
            $lines[] = '  Window: '.date('Y-m-d H:i', $start).' → '.date('Y-m-d H:i', $end).' ('.$type_label.')';
            if ($hosts) {
                $lines[] = '  Hosts: '.implode(', ', $hosts);
            }
            if ($groups) {
                $lines[] = '  Groups: '.implode(', ', $groups);
            }
            if ($tag_strs) {
                $lines[] = '  Tag scope: '.implode(', ', $tag_strs);
            }
        }

        return implode("\n", $lines);
    }

    private static function executeExtendMaintenance(array $params, ZabbixApiClient $api): string {
        $maintenance_id = trim((string) ($params['maintenance_id'] ?? ''));
        $additional_hours = (float) ($params['additional_hours'] ?? 0);

        if ($maintenance_id === '') {
            return 'Error: maintenance_id is required.';
        }

        if ($additional_hours <= 0) {
            return 'Error: additional_hours must be greater than 0.';
        }

        $result = $api->extendMaintenance($maintenance_id, $additional_hours);

        return 'Maintenance window extended.'
            ."\nID: ".$result['maintenanceid']
            ."\nName: ".$result['name']
            ."\nOld end: ".$result['old_end']
            ."\nNew end: ".$result['new_end']
            ."\nAdded: ".$result['added_hours'].' hours';
    }

    private static function executeEndMaintenance(array $params, ZabbixApiClient $api): string {
        $maintenance_id = trim((string) ($params['maintenance_id'] ?? ''));

        if ($maintenance_id === '') {
            return 'Error: maintenance_id is required.';
        }

        $delete = !empty($params['delete']);

        $result = $api->endMaintenance($maintenance_id, $delete);

        if (($result['action'] ?? '') === 'deleted') {
            return 'Maintenance '.$result['maintenanceid'].' ('.$result['name'].') deleted.';
        }

        return 'Maintenance '.$result['maintenanceid'].' ('.$result['name'].') ended at '.$result['ended_at'].'.';
    }

    private static function formatMaintenanceResult(string $headline, array $result): string {
        $lines = [$headline];
        $lines[] = 'ID: '.($result['maintenanceid'] ?? '?');
        $lines[] = 'Name: '.($result['name'] ?? '');

        $hosts = $result['targets']['hosts'] ?? [];
        $groups = $result['targets']['host_groups'] ?? [];

        if ($hosts) {
            $lines[] = 'Hosts: '.implode(', ', $hosts);
        }
        if ($groups) {
            $lines[] = 'Host groups: '.implode(', ', $groups);
        }

        $lines[] = 'Data collection: '.(($result['data_collection'] ?? true) ? 'enabled' : 'disabled (no data collected)');

        if (!empty($result['tags'])) {
            $tag_strs = [];
            foreach ($result['tags'] as $t) {
                $op = (int) ($t['operator'] ?? 0);
                $op_label = $op === 2 ? 'contains' : '=';
                $tag_strs[] = ($t['tag'] ?? '').' '.$op_label.' '.($t['value'] ?? '');
            }
            $lines[] = 'Tag scope: '.implode(', ', $tag_strs);
        }

        $lines[] = 'Start: '.($result['start'] ?? '');
        $lines[] = 'End: '.($result['end'] ?? '');
        $lines[] = 'Duration: '.($result['duration_hours'] ?? 0).' hours';

        return implode("\n", $lines);
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

    private static function executeAddHostsToGroup(array $params, ZabbixApiClient $api): string {
        $hostnames = (array) ($params['hostnames'] ?? []);

        if (!$hostnames) {
            return 'Error: hostnames parameter is required (array of hostnames).';
        }

        $group_name = trim((string) ($params['group_name'] ?? ''));

        if ($group_name === '') {
            return 'Error: group_name parameter is required.';
        }

        $create_group = !empty($params['create_group']);

        $result = $api->addHostsToGroup($hostnames, $group_name, $create_group);

        $lines = [];

        if ($result['group_created']) {
            $lines[] = 'Host group "'.$result['group_name'].'" created (ID: '.$result['groupid'].').';
        }
        else {
            $lines[] = 'Using existing host group "'.$result['group_name'].'" (ID: '.$result['groupid'].').';
        }

        $lines[] = 'Hosts added: '.implode(', ', $result['hosts_added']);

        if ($result['hosts_not_found']) {
            $lines[] = 'Hosts not found (skipped): '.implode(', ', $result['hosts_not_found']);
        }

        return implode("\n", $lines);
    }

    private static function executeCreateHostGroup(array $params, ZabbixApiClient $api): string {
        $name = trim((string) ($params['name'] ?? ''));

        if ($name === '') {
            return 'Error: name parameter is required.';
        }

        // Check if it already exists.
        $existing = $api->getHostGroupByName($name);

        if ($existing !== null) {
            return 'Host group "'.$name.'" already exists (ID: '.$existing['groupid'].').';
        }

        $result = $api->createHostGroup($name);
        $groupid = $result['groupids'][0] ?? 'unknown';

        return 'Host group "'.$name.'" created successfully (ID: '.$groupid.').';
    }
}
