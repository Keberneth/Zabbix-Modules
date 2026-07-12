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
     * Category-style tag names that are shared across environments/hosts by
     * convention (including Zabbix's stock scope=availability/performance).
     * They classify problems but never pin a specific host or instance, so
     * problem_tags built ONLY from these blend every environment/host that
     * emits them (dev+test+prod) into one service.
     */
    private const CATEGORY_TAG_NAMES = [
        'service', 'application', 'app', 'component', 'env', 'environment',
        'class', 'type', 'team', 'group', 'target', 'scope'
    ];

    /**
     * Tag names that identify a specific host. Unlike category tags they DO
     * narrow problem_tags to one machine (host=web01), but on their own they
     * map EVERY problem on that host — valid only for host-availability SLAs.
     */
    private const HOST_ID_TAG_NAMES = ['host', 'hostname', 'server'];

    /**
     * True for tag NAMES that can never act as a unique SLA selection handle:
     * SLA service_tags are OR-matched, so selecting on a conventional shared
     * name silently widens the SLA to every service that carries it.
     */
    private static function isBroadTagName(string $name): bool {
        $name = strtolower($name);

        return in_array($name, self::CATEGORY_TAG_NAMES, true)
            || in_array($name, self::HOST_ID_TAG_NAMES, true);
    }

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
                'eventid'       => ['string', true],
                'cause_eventid' => ['string', true]
            ],
            'change_problem_severity' => [
                'eventid'  => ['string', true],
                'severity' => ['int',    true]
            ],
            'unacknowledge_problem' => [
                'eventid' => ['string', true]
            ],
            'add_problem_message' => [
                'eventid' => ['string', true],
                'message' => ['string', true]
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
            ],
            'enable_host' => [
                'hostname' => ['string', true]
            ],
            'disable_host' => [
                'hostname' => ['string', true]
            ],
            'update_host_tags' => [
                'hostname'  => ['string', true],
                'operation' => ['string', true],
                'tags'      => ['array',  true]
            ],
            'update_host_inventory' => [
                'hostname' => ['string', true],
                'fields'   => ['object', true]
            ],
            'update_host_macros' => [
                'hostname' => ['string', true],
                'macros'   => ['array',  true]
            ],
            'update_host_interface' => [
                'interfaceid' => ['string', true],
                'ip'          => ['string', false],
                'dns'         => ['string', false],
                'port'        => ['string', false],
                'useip'       => ['int',    false]
            ],
            'create_web_scenario' => [
                'hostname'            => ['string', true],
                'name'                => ['string', true],
                'url'                 => ['string', true],
                'delay'               => ['string', false],
                'status_codes'        => ['string', false],
                'step_name'           => ['string', false],
                'tags'                => ['array',  false],
                'add_failure_trigger' => ['bool',   false],
                'trigger_priority'    => ['int',    false]
            ],
            'create_web_scenario_trigger' => [
                'hostname'      => ['string', true],
                'scenario_name' => ['string', true],
                'name'          => ['string', false],
                'priority'      => ['int',    false]
            ],
            'create_problem_dashboard' => [
                'name' => ['string', true]
            ],
            'link_template_to_host' => [
                'template'  => ['string',    true],
                'hostnames' => ['array_str', true]
            ],
            'unlink_template_from_host' => [
                'template'  => ['string',    true],
                'hostnames' => ['array_str', true],
                'clear'     => ['bool',      false]
            ],
            'enable_lld_rule' => [
                'lld_rule_id' => ['string', true]
            ],
            'disable_lld_rule' => [
                'lld_rule_id' => ['string', true]
            ],
            'create_host' => [
                'hostname'              => ['string',    true],
                'groups'                => ['array_str', true],
                'visible_name'          => ['string',    false],
                'templates'             => ['array_str', false],
                'description'           => ['string',    false],
                'interface_ip'          => ['string',    false],
                'interface_dns'         => ['string',    false],
                'interface_port'        => ['string',    false],
                'create_missing_groups' => ['bool',      false]
            ],
            'create_trigger' => [
                'description'         => ['string', true],
                'expression'          => ['string', true],
                'priority'            => ['int',    false],
                'comments'            => ['string', false],
                'recovery_expression' => ['string', false]
            ],
            'apply_bulk_action' => [
                'preview_token' => ['string', true]
            ],
            'create_sla_service' => [
                'name'                     => ['string', true],
                'problem_tags'             => ['array',  false],
                'service_tags'             => ['array',  false],
                'algorithm'                => ['int',    false],
                'sortorder'                => ['int',    false],
                'parent_service'           => ['string', false],
                // 'array' (not array_str): models copy the numeric IDs from
                // tool output as JSON numbers; the executor validates each.
                'child_serviceids'         => ['array',  false],
                'allow_shared_service_tag' => ['bool',   false],
                'allow_broad_problem_tags' => ['bool',   false]
            ],
            'create_sla' => [
                'name'           => ['string', true],
                'slo'            => ['number', true],
                'period'         => ['string', true],
                'service_tags'   => ['array',  true],
                'timezone'       => ['string', false],
                'effective_date' => ['string', false],
                'status'         => ['int',    false],
                'description'    => ['string', false],
                'allow_multiple_matching_services' => ['bool', false]
            ],
            'add_template_tag' => [
                'template' => ['string', true],
                'tag'      => ['string', true],
                'value'    => ['string', false]
            ],
            'add_trigger_tag' => [
                'trigger_id' => ['string', true],
                'tag'        => ['string', true],
                'value'      => ['string', false]
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

            // LLMs often send an optional list param as [] meaning "none";
            // treat that as omitted instead of failing the non-empty check.
            if (!$required && $value === [] && in_array($type, ['array', 'array_str', 'object'], true)) {
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
                    'action' => '(int, required) Bitmask: 1=close, 2=acknowledge, 4=add message. Combine with +. To change severity use change_problem_severity; to add a plain comment use add_problem_message.',
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
            'build_file_report' => [
                'description' => 'Save arbitrary report content (HTML / CSV / Markdown / JSON) you have composed yourself as a downloadable file the user can click. Use this WHENEVER the user explicitly asks for a "report file", "download", "html report", "csv report", "give me a report I can download", or wants a result they can open in Excel or a browser. Build the full file body as a single string and pass it in "content". For HTML, include a complete document (<!DOCTYPE html>...</html>) with inline CSS so it renders standalone. The tool persists the file and returns a special download marker — preserve the entire returned text verbatim in your reply (do NOT alter or remove the marker). Do NOT dump the same content as a fenced code block in addition.',
                'params' => [
                    'title' => '(string, required) Short title used to name the file (e.g. "performance_report_LHBHANA101"). Used in the saved filename and HTML <title>.',
                    'format' => '(string, required) File format: "html", "csv", "md", or "json".',
                    'content' => '(string, required) Full file content as a single string. Up to ~1 MB.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'list_zabbix_hosts' => [
                'description' => 'List Zabbix hosts in bulk with optional filters. Use this FIRST when the user asks about "all servers", "all hosts", or wants an inventory across many hosts — it returns up to 2000 hosts in one call, with hostid + technical name + visible name + status + maintenance + groups + tags. Much faster than calling get_host_info per host. Returns the numeric hostid you need for zabbix.php?action=...&hostids[]=... URLs.',
                'params' => [
                    'host_group' => '(string, optional) Substring match on host-group name (e.g. "Linux", "Windows").',
                    'search' => '(string, optional) Substring match on the host technical or visible name.',
                    'status' => '(string, optional) "enabled" (default behaviour) or "disabled".',
                    'tag' => '(string, optional) Filter by host tag, either "name" or "name=value" (e.g. "env=production").',
                    'limit' => '(int, optional) Max rows, default 200, cap 2000.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'list_netbox_devices' => [
                'description' => 'List NetBox VMs and/or devices in bulk with optional filters. Returns one row per VM/device with name, role, site, platform, status, vCPU, RAM (MB), disk (MB), primary IP. Use this when the user asks for an INVENTORY / CAPACITY REPORT across many servers (CPU, RAM, disk counts, models) — NetBox is the system of record for that. Much faster than gathering item values from Zabbix one host at a time. Only available when NetBox is enabled in AI Settings.',
                'params' => [
                    'kind' => '(string, optional) "vm", "device", or "both" (default "both"). VMs typically have vCPU/RAM/disk populated; physical devices may not.',
                    'search' => '(string, optional) Substring match on the VM/device name or display name.',
                    'platform' => '(string, optional) Filter by platform name, e.g. "Linux", "Windows", "RHEL", "Ubuntu".',
                    'role' => '(string, optional) Filter by NetBox role, e.g. "Server", "Database", "Application".',
                    'site' => '(string, optional) Filter by site, e.g. "Stockholm".',
                    'status' => '(string, optional) Filter by status label, e.g. "active", "offline", "decommissioning".',
                    'tenant' => '(string, optional) Filter by tenant.',
                    'limit' => '(int, optional) Max rows, default 200, cap 1000.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_netbox_info' => [
                'description' => 'Look up a single host in NetBox by hostname. Returns the full NetBox record (VM or device) with status, site, cluster, role, platform, primary IP, vCPU, RAM, disk, OS, services, and custom fields. Use this for a deep-dive on ONE host. Only available when NetBox is enabled in AI Settings. For multi-host reports, prefer list_netbox_devices.',
                'params' => [
                    'hostname' => '(string, required) Hostname to look up (matches NetBox name or display).'
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
                    'host' => '(string, optional) Restrict to a single host by technical or visible name.',
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
            'get_audit_log' => [
                'description' => 'Search the Zabbix audit log (Reports > Audit log): user logins/logouts and configuration changes (add/update/delete/execute). Use this for questions like "how many times did user niska log in?", "show failed logins today", or "who changed something recently?". Filter by user and/or action over a time window. Set count_only=true to get just the number — best for "how many times" questions. NOTE: the Zabbix audit log is readable by Super Admin users only, and only covers the period kept by your Zabbix audit housekeeping (older events are purged). To inspect the change history of one specific object id (a given trigger/host/item), use get_auditlog_for_object instead.',
                'params' => [
                    'username' => '(string, optional) Zabbix username to filter by, e.g. "niska". Automatically resolved to its numeric user id.',
                    'userid' => '(string, optional) Zabbix user id to filter by. Use instead of username when you already have it.',
                    'action' => '(string, optional) One of: login, login_failed, logout, add, update, delete, execute. Omit for all actions.',
                    'resourcetype' => '(int, optional) Narrow config changes to a Zabbix audit resourcetype constant (e.g. 4=host, 13=trigger, 15=item, 11=user).',
                    'since_unix' => '(int, optional) UNIX timestamp lower bound (time_from). 0 = no lower bound.',
                    'until_unix' => '(int, optional) UNIX timestamp upper bound (time_till). 0 = no upper bound.',
                    'count_only' => '(bool, optional) When true, return only the number of matching entries. Best for "how many times" questions.',
                    'limit' => '(int, optional, default 50, max 500) Max entries to return when not counting.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_host_interfaces' => [
                'description' => 'List a host\'s configured interfaces (Agent/SNMP/IPMI/JMX) with IP/DNS, port, which is the default interface, availability state, and any interface error. Use this first for "host unreachable", "agent not responding", or SNMP connectivity problems.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_metric_summary' => [
                'description' => 'Summarise a numeric item over a time window WITHOUT dumping raw history: returns last/min/max/avg/p95 and the first->last change. Use this for "was CPU actually high before the trigger fired?", "is disk growth gradual or sudden?", "spike or sustained?". Picks the best-matching numeric item on the host.',
                'params' => [
                    'host' => '(string, required) The technical hostname.',
                    'item' => '(string, required) Text to match the item name, e.g. "CPU utilization", "available memory", "disk space /".',
                    'period_hours' => '(int, optional, default 24) Look-back window in hours. Windows <= 12h use raw history (incl. p95); longer windows use hourly trends.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_trigger_dependencies' => [
                'description' => 'List triggers that have dependencies configured (trigger A depends on trigger B). Use this to separate a root cause from its symptoms and avoid recommending remediation for a downstream/dependent alert.',
                'params' => [
                    'hostname' => '(string, optional) Restrict to one host.',
                    'search' => '(string, optional) Match trigger names.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_noisy_triggers' => [
                'description' => 'Rank the triggers that generated the most problem events over a time window (alert noise / flapping). Use this for "what is the noisiest alert this week?" or to find tuning candidates.',
                'params' => [
                    'period_hours' => '(int, optional, default 24) Look-back window in hours.',
                    'limit' => '(int, optional, default 15) How many top triggers to return.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_web_scenarios' => [
                'description' => 'List web monitoring scenarios (HTTP checks) with their steps, expected status codes, interval and enabled/disabled status. Use this to diagnose failing URL/endpoint checks.',
                'params' => [
                    'hostname' => '(string, optional) Restrict to one host.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_sla_overview' => [
                'description' => 'List configured SLAs with their target SLO percentage, reporting period, status and the service tags they apply to. Use this to bring business SLA/SLO context into an incident. Returns nothing if the Services/SLA feature is unused.',
                'params' => [
                    'limit' => '(int, optional, default 50) Max SLAs to return.'
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
                'description' => 'Mark a problem event as a symptom of a specific cause event. Requires the cause event ID — Zabbix needs cause_eventid to change an event to symptom rank.',
                'params' => [
                    'eventid' => '(string, required) The event ID to mark as a symptom.',
                    'cause_eventid' => '(string, required) The event ID of the parent CAUSE problem this is a symptom of.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'change_problem_severity' => [
                'description' => 'Change the severity of a problem event. Use this (not acknowledge_problem) for severity changes — Zabbix requires the new severity value.',
                'params' => [
                    'eventid' => '(string, required) The event ID.',
                    'severity' => '(int, required) New severity: 0=Not classified, 1=Information, 2=Warning, 3=Average, 4=High, 5=Disaster.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'unacknowledge_problem' => [
                'description' => 'Remove the acknowledgement from a problem event (reverses acknowledge_problem).',
                'params' => [
                    'eventid' => '(string, required) The event ID.'
                ],
                'rw' => 'write',
                'category' => 'problems'
            ],
            'add_problem_message' => [
                'description' => 'Add a comment/message to a problem event WITHOUT acknowledging or closing it. Use this for plain operator notes when the user just wants to record a comment.',
                'params' => [
                    'eventid' => '(string, required) The event ID.',
                    'message' => '(string, required) The comment text to add.'
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
            ],
            'enable_host' => [
                'description' => 'Enable monitoring for a host (sets host status to "monitored"). Use to bring a host back online after maintenance or migration.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'disable_host' => [
                'description' => 'Disable monitoring for a host (sets host status to "not monitored"). Use for decommissioned or very noisy hosts. Always state a reason in the confirmation message.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'update_host_tags' => [
                'description' => 'Add, remove, or replace a host\'s tags (used for maintenance scoping, routing, ownership, service impact). For "add"/"remove" existing tags are preserved/merged; "replace" overwrites the whole tag set.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.',
                    'operation' => '(string, required) One of: add, remove, replace.',
                    'tags' => '(array, required) Array of {"tag":"name","value":"val"} objects. value may be empty.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'update_host_inventory' => [
                'description' => 'Update host inventory metadata (owner, site, asset fields, etc.). Sets the host inventory to MANUAL mode so the values persist. Useful to align Zabbix with NetBox / operator input.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.',
                    'fields' => '(object, required) Map of inventory field name to value, e.g. {"location":"DC1","contact":"netops"}. Use standard Zabbix inventory field keys.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'update_host_macros' => [
                'description' => 'Create or update host-level user macros (e.g. thresholds). Other macros are left untouched. NEVER display secret macro VALUES back to the user. Set "type":1 for a secret macro, 2 for a Vault macro, 0 (default) for plain text.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname.',
                    'macros' => '(array, required) Array of {"macro":"{$NAME}","value":"...","type":0} objects. type: 0=text, 1=secret, 2=vault.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'update_host_interface' => [
                'description' => 'Update a host interface\'s IP, DNS name, port, or IP/DNS mode. HIGH RISK: a wrong value stops monitoring the host. First call get_host_interfaces to get the interfaceid and current values, then confirm the exact change.',
                'params' => [
                    'interfaceid' => '(string, required) The interface ID (from get_host_interfaces).',
                    'ip' => '(string, optional) New IP address.',
                    'dns' => '(string, optional) New DNS name.',
                    'port' => '(string, optional) New port.',
                    'useip' => '(int, optional) 1 = connect by IP, 0 = connect by DNS.'
                ],
                'rw' => 'write',
                'category' => 'interfaces'
            ],
            'create_web_scenario' => [
                'description' => 'Create a single-step web monitoring (HTTP) check on a host from a simple request, optionally with a failure trigger. Example: "monitor https://intranet/healthz every 60s, expect 200, on host APP-PROD-01, and alert if it fails" -> set add_failure_trigger=true.',
                'params' => [
                    'hostname' => '(string, required) The technical hostname to attach the check to.',
                    'name' => '(string, required) Scenario name.',
                    'url' => '(string, required) URL to check.',
                    'delay' => '(string, optional, default 60s) Check interval, e.g. 60s, 5m.',
                    'status_codes' => '(string, optional, default 200) Expected HTTP status code(s), e.g. "200" or "200,301".',
                    'step_name' => '(string, optional) Name of the single step.',
                    'tags' => '(array, optional) Array of {"tag":"name","value":"val"} tags.',
                    'add_failure_trigger' => '(bool, optional) When true, also create a trigger that fires when the scenario fails (last(/HOST/web.test.fail[Scenario])<>0).',
                    'trigger_priority' => '(int, optional, default 3) Severity for the failure trigger, 0=Not classified..5=Disaster.'
                ],
                'rw' => 'write',
                'category' => 'web'
            ],
            'create_web_scenario_trigger' => [
                'description' => 'Create a trigger that fires when a web scenario (HTTP check) fails on a host. Builds the standard expression last(/HOST/web.test.fail[Scenario])<>0 automatically. Use this after create_web_scenario (on the SAME host the scenario was created on), or for any existing web scenario.',
                'params' => [
                    'hostname' => '(string, required) The host the web scenario is on.',
                    'scenario_name' => '(string, required) The exact web scenario name.',
                    'name' => '(string, optional) Trigger name. Defaults to: Web scenario "<scenario>" failed on <host>.',
                    'priority' => '(int, optional, default 3) Severity 0=Not classified..5=Disaster.'
                ],
                'rw' => 'write',
                'category' => 'web'
            ],
            'create_problem_dashboard' => [
                'description' => 'Create a private dashboard with a Problems widget (all current problems). Personal dashboards only; refine its filters in the UI afterwards.',
                'params' => [
                    'name' => '(string, required) Dashboard name.'
                ],
                'rw' => 'write',
                'category' => 'dashboards'
            ],
            'link_template_to_host' => [
                'description' => 'Link a template to an EXPLICIT list of hosts to add monitoring coverage (existing templates are preserved). HIGH RISK: adds the template\'s items/triggers to every listed host. Resolve any group/filter to exact hostnames first; the list is capped by bulk_max_hosts. Show the full host list in the confirmation.',
                'params' => [
                    'template' => '(string, required) Template name (technical or visible).',
                    'hostnames' => '(array of strings, required) Explicit list of hostnames to link the template to.'
                ],
                'rw' => 'write',
                'category' => 'templates'
            ],
            'unlink_template_from_host' => [
                'description' => 'Unlink a template from an EXPLICIT list of hosts. VERY HIGH RISK when clear=true (also deletes the template-created items/triggers/graphs and their history). Provide the exact host list (capped by bulk_max_hosts). Show the host list and whether clear=true in the confirmation.',
                'params' => [
                    'template' => '(string, required) Template name.',
                    'hostnames' => '(array of strings, required) Explicit list of hostnames to unlink from.',
                    'clear' => '(bool, optional, default false) When true, also delete the template-created items/triggers (and their history). Default false unlinks but keeps them.'
                ],
                'rw' => 'write',
                'category' => 'templates'
            ],
            'enable_lld_rule' => [
                'description' => 'Enable a low-level discovery (LLD) rule by its id. Use get_lld_rules to find the id.',
                'params' => [
                    'lld_rule_id' => '(string, required) The LLD rule id (from get_lld_rules).'
                ],
                'rw' => 'write',
                'category' => 'discovery'
            ],
            'disable_lld_rule' => [
                'description' => 'Disable a low-level discovery (LLD) rule by its id to stop it creating noisy discovered entities. Use get_lld_rules to find the id.',
                'params' => [
                    'lld_rule_id' => '(string, required) The LLD rule id (from get_lld_rules).'
                ],
                'rw' => 'write',
                'category' => 'discovery'
            ],
            'create_host' => [
                'description' => 'Create a new monitored host. Requires at least one host group, which must already exist unless create_missing_groups=true. Optionally link templates and add an agent interface. For web/URL monitoring or template-only hosts, omit the interface (agentless). After creating the host you can attach a web scenario, items, or triggers to it.',
                'params' => [
                    'hostname' => '(string, required) Technical host name, e.g. "iver.se".',
                    'groups' => '(array of strings, required) Existing host group name(s). A missing group is an error unless create_missing_groups=true.',
                    'create_missing_groups' => '(bool, optional, default false) When true, any group that does not exist is created. Default false to avoid creating a wrong group from a typo.',
                    'visible_name' => '(string, optional) Visible name (defaults to the technical name).',
                    'templates' => '(array of strings, optional) Template names to link (must already exist).',
                    'description' => '(string, optional) Host description.',
                    'interface_ip' => '(string, optional) Agent interface IP. Omit (with interface_dns) for an agentless host.',
                    'interface_dns' => '(string, optional) Agent interface DNS name (alternative to IP).',
                    'interface_port' => '(string, optional, default 10050) Agent interface port.'
                ],
                'rw' => 'write',
                'category' => 'hosts'
            ],
            'create_trigger' => [
                'description' => 'Create a trigger (alert definition) on a host from a Zabbix trigger expression. Use this to alert on item or web-scenario failures, e.g. a web check not returning HTTP 200. First confirm the exact item key (via get_items / get_web_scenarios) so the expression references a real item, e.g. last(/HOST/web.test.fail[Scenario name])<>0.',
                'params' => [
                    'description' => '(string, required) Trigger name, e.g. "HTTP 200 check failed for iver.se".',
                    'expression' => '(string, required) Zabbix trigger expression, e.g. last(/KT4B-SRV-JUMP/web.test.fail[HTTP 200 check])<>0',
                    'priority' => '(int, optional) Severity 0=Not classified, 1=Information, 2=Warning, 3=Average, 4=High, 5=Disaster. Default 0.',
                    'comments' => '(string, optional) Operational notes shown with the problem.',
                    'recovery_expression' => '(string, optional) Separate recovery expression; omit to let Zabbix auto-recover when the problem expression is false.'
                ],
                'rw' => 'write',
                'category' => 'triggers'
            ],
            'get_proxy_assigned_hosts' => [
                'description' => 'Show which hosts are assigned to each Zabbix proxy (or a single named proxy) — distributed-monitoring visibility, e.g. "what does proxy DC2 monitor?". Read-only.',
                'params' => [
                    'proxy' => '(string, optional) Proxy name to filter by; omit for all proxies.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_disable_triggers' => [
                'description' => 'PREVIEW (read-only, no change) the enabled triggers whose name matches a pattern, e.g. to silence a noisy discovered trigger fleet-wide ("WpnService ... is not running"). Returns the exact list plus a preview_token. Then call apply_bulk_action with that token to actually disable them. Capped by bulk_max_items.',
                'params' => [
                    'name_pattern' => '(string, required) Text to match in the trigger name, e.g. "WpnService".',
                    'host_group' => '(string, optional) Limit to a host group, e.g. "Windows servers".'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_disable_items_by_error' => [
                'description' => 'PREVIEW (read-only) the enabled-but-UNSUPPORTED items whose error message matches a pattern, to disable noisy broken items. Returns the exact list plus a preview_token for apply_bulk_action. Capped by bulk_max_items.',
                'params' => [
                    'error_pattern' => '(string, required) Text to match in the item error, e.g. "Cannot find instance".',
                    'host_group' => '(string, optional) Limit to a host group.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_enable_items' => [
                'description' => 'PREVIEW (read-only) the currently DISABLED items matching a name search (optionally within a host group), to re-enable them in bulk. Returns the exact list plus a preview_token for apply_bulk_action. Capped by bulk_max_items.',
                'params' => [
                    'item_search' => '(string, required) Text to match in the item name.',
                    'host_group' => '(string, optional) Limit to a host group, e.g. "Windows servers".'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_bulk_add_host_tag' => [
                'description' => 'PREVIEW (read-only) the hosts in a host group that would receive a standard tag (ownership/site/environment). Returns the host list plus a preview_token for apply_bulk_action. Capped by bulk_max_hosts.',
                'params' => [
                    'host_group' => '(string, required) Host group whose hosts get the tag.',
                    'tag' => '(string, required) Tag name to add.',
                    'value' => '(string, optional) Tag value (may be empty).'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_link_template' => [
                'description' => 'PREVIEW (read-only) the hosts in a host group that a template would be linked to. Returns the host list plus a preview_token; apply_bulk_action then links the template to EXACTLY that frozen set. HIGH RISK on apply (adds the template items/triggers to every host). Capped by bulk_max_hosts.',
                'params' => [
                    'template' => '(string, required) Template name (technical or visible).',
                    'host_group' => '(string, required) Host group whose hosts get the template.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'preview_unlink_template' => [
                'description' => 'PREVIEW (read-only) the hosts in a host group a template would be unlinked from. Returns the host list plus a preview_token for apply_bulk_action. VERY HIGH RISK on apply when clear=true (also deletes the template-created items/triggers and their history). Capped by bulk_max_hosts.',
                'params' => [
                    'template' => '(string, required) Template name.',
                    'host_group' => '(string, required) Host group whose hosts lose the template.',
                    'clear' => '(bool, optional, default false) When true, also delete the template-created items/triggers (and history).'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'apply_bulk_action' => [
                'description' => 'Execute a bulk change that was previously computed by a preview_* tool. Pass the preview_token from the preview result; this applies the action to EXACTLY the frozen set the preview listed (it does not re-query). Requires operator confirmation — set confirm:true and a confirm_message that states the operation and the exact count from the preview.',
                'params' => [
                    'preview_token' => '(string, required) The token returned by a preview_* tool.'
                ],
                'rw' => 'write',
                'category' => 'bulk'
            ],
            'analyze_sla_scope' => [
                'description' => 'Inspect the tags available for scoping an SLA on a target. Returns the host tags, linked-template tags, trigger tags and item tags for the given hosts/group (optionally filtered by a keyword such as the service name), plus a tag-frequency tally and uniqueness guidance — problems inherit tags from ALL four levels. ALWAYS call this before create_sla_service so you can pick a tag (or AND-combination) that uniquely identifies the target and does NOT blend other environments/instances.',
                'params' => [
                    'hostnames' => '(array of strings, optional) Hosts to analyze.',
                    'group_name' => '(string, optional) Host group to analyze (its hosts are inspected).',
                    'keyword' => '(string, optional) Filter triggers AND items by name, e.g. the service name "filezilla" or "ftp". Item tags are only reported for items whose name matches it.'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'get_services' => [
                'description' => 'List Zabbix IT services with their service tags, problem tags, parents and children. Pass service_tags to see EXACTLY which services an SLA with those tags would measure (same OR matching as sla.create), or keyword to search services by name (e.g. to find an existing service to reuse or attach children to). ALWAYS call this with the planned service_tags BEFORE create_sla and show the operator the matched services in the confirmation.',
                'params' => [
                    'service_tags' => '(array, optional) SLA-style matchers, each {"tag":"sla_scope","operator":0,"value":"filezilla.prod"}. operator 0=equals, 2=contains. OR-combined exactly like sla.create service_tags.',
                    'keyword' => '(string, optional) Case-insensitive substring to search service names, e.g. "filezilla".'
                ],
                'rw' => 'read',
                'category' => ''
            ],
            'create_sla_service' => [
                'description' => 'Create a Zabbix IT service, optionally linked into a parent/child hierarchy. A LEAF service maps problems via problem_tags (AND logic: a problem must carry ALL listed tags — combine e.g. service=filezilla AND env=prod AND host=prod-app-01 to scope uniquely). A PARENT/GROUP service aggregates existing services via child_serviceids instead — Zabbix forbids a service having BOTH problem_tags and children, so create the leaves first, then the parent, and CHOOSE the parent\'s algorithm explicitly (1 = redundant HA/failover cluster, 2 = any child down counts; see algorithm). Every service gets EXACTLY ONE unique sla_scope service tag — the handle an SLA selects on, dot-notation <application>.<environment>.<host|cluster|all>. Build order: analyze_sla_scope → create leaf service(s) → optional parent → get_services to verify → create_sla.',
                'params' => [
                    'name' => '(string, required, max 128 chars) Service name, e.g. "FileZilla Server - PROD - prod-app-01". Fails if a service with this name already exists (reuse it instead).',
                    'problem_tags' => '(array, required for LEAF services, forbidden with child_serviceids) AND-combined problem matchers. Each entry: {"tag":"service","operator":0,"value":"filezilla"}. operator 0=equals, 2=contains. Include EVERY tag needed to make the match unique to this target (e.g. also env=prod and host=prod-app-01). Combinations made ONLY of broad category tags (service=filezilla alone, or service+env) blend dev/test/prod and are rejected unless allow_broad_problem_tags=true — include a host-identifying tag (host=web01) or a dedicated unique tag. Host-identifying tags ALONE are allowed but map EVERY problem on that host (host-availability SLA only).',
                    'service_tags' => '(array, optional) Plain service tags, each {"tag":"sla_scope","value":"filezilla.prod.prod-app-01"}. MUST include exactly ONE tag NAMED sla_scope whose value no other service carries (both enforced); descriptive tags (service, env, host) may be added alongside. If omitted, sla_scope=<slug-of-name> is derived.',
                    'algorithm' => '(int, REQUIRED for parents with child_serviceids; optional for leaves, default 1) Status rule: 1 = most critical if ALL children have problems (redundant HA/failover cluster — the service is UP while at least one node is up, so a single-node outage is NOT downtime), 2 = most critical of child services (any child problem propagates and counts as downtime). Ask the operator which matches the topology before creating a parent. 0 (set status to OK) is REJECTED — such a service ignores problems and its SLA would always read 100%.',
                    'sortorder' => '(int, optional, default 0) Display order 0-999.',
                    'parent_service' => '(string, optional) Serviceid (preferred) or exact name of an EXISTING service to attach this one under. Ambiguous names (Zabbix allows duplicates) are refused — pass the serviceid.',
                    'child_serviceids' => '(array of numeric serviceids, optional) EXISTING services to attach as children — this makes the new service a parent/group (e.g. a PROD cluster grouping prod-app-01 + prod-app-02). Cannot be combined with problem_tags.',
                    'allow_shared_service_tag' => '(bool, optional, default false) Set true ONLY when the operator explicitly confirmed that this service should share its scope tag with the listed existing services (one SLA deliberately covering several services).',
                    'allow_broad_problem_tags' => '(bool, optional, default false) Set true ONLY after the operator explicitly confirmed a broad multi-host/multi-environment scope for problem_tags built ONLY of category tags (service/env/scope/…) or a "contains" host matcher. Not needed when the combination already contains a host-identifying EQUALS tag or a dedicated unique tag.'
                ],
                'rw' => 'write',
                'category' => 'sla'
            ],
            'create_sla' => [
                'description' => 'Create a Zabbix SLA. service_tags select WHICH services this SLA measures, matched with OR logic — ANY tag matching ANY service includes it; Zabbix NEVER combines SLA service_tags with AND. Pass EXACTLY ONE unique sla_scope tag (operator 0) that matches EXACTLY ONE service. The backend resolves the tags against the live service list and REJECTS: zero matched services (always — create the target service first), and, unless allow_multiple_matching_services=true after explicit operator confirmation: multiple tags, operator=contains, broad tag names (service/env/host/application/…), or more than one matched service. Flow: create_sla_service → get_services (verify the tag matches only the intended service) → create_sla.',
                'params' => [
                    'name' => '(string, required) SLA name.',
                    'slo' => '(number, required) Target availability %, 0-100 (e.g. 99.9).',
                    'period' => '(string, required) Reporting period: daily, weekly, monthly, quarterly, or annually.',
                    'service_tags' => '(array, required) Exactly ONE unique scope tag: [{"tag":"sla_scope","operator":0,"value":"filezilla.prod"}]. Never rely on several tags as an AND — SLA matching is OR.',
                    'timezone' => '(string, optional) Valid PHP/IANA timezone identifier, e.g. "Europe/Stockholm". Invalid identifiers are rejected. Defaults to the server timezone.',
                    'effective_date' => '(string, optional) Date the SLA starts calculating, strictly YYYY-MM-DD (e.g. 2026-07-12). Any other format is rejected. Defaults to today.',
                    'status' => '(int, optional, default 1) 1=enabled, 0=disabled.',
                    'description' => '(string, optional) Free text.',
                    'allow_multiple_matching_services' => '(bool, optional, default false) Set true ONLY after the operator explicitly confirmed a deliberately broad SLA and your confirm_message lists EVERY matched service by name.'
                ],
                'rw' => 'write',
                'category' => 'sla'
            ],
            'add_template_tag' => [
                'description' => 'Add a tag to a template (preserving existing tags). Use when no existing tag uniquely identifies an SLA target: add a unique tag (e.g. sla_scope=filezilla-prod) to the template so every NEW problem on hosts linked to that template carries it. Then reference it in create_sla_service problem_tags.',
                'params' => [
                    'template' => '(string, required) Template name.',
                    'tag' => '(string, required) Tag name to add.',
                    'value' => '(string, optional) Tag value.'
                ],
                'rw' => 'write',
                'category' => 'templates'
            ],
            'add_trigger_tag' => [
                'description' => 'Add a tag to a specific trigger (preserving existing tags). Use to mark individual triggers as part of an SLA scope when a template-wide tag would be too broad. Get the trigger_id from get_triggers or analyze_sla_scope. Only NEW problems from the trigger carry the tag.',
                'params' => [
                    'trigger_id' => '(string, required) Numeric trigger ID.',
                    'tag' => '(string, required) Tag name to add.',
                    'value' => '(string, optional) Tag value.'
                ],
                'rw' => 'write',
                'category' => 'triggers'
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
        $lines[] = 'For every tool marked [WRITE], you MUST first describe exactly what will be changed and ask for confirmation. Respond with:';
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
     * Find the end offset of a JSON object that starts at $start in $haystack.
     *
     * Treats `"..."` (with backslash escapes) as opaque so braces and quotes
     * inside string values do not throw off the depth counter. Returns the
     * offset of the character AFTER the matching `}`, or -1 if no complete
     * object is found.
     *
     * This matters because (a) the AI sometimes emits multiple JSON tool
     * calls concatenated in one response, and (b) tool parameters can
     * legitimately contain strings with `{` / `}` (e.g. CSS in HTML report
     * content). A naive brace counter is fooled in both cases.
     */
    private static function findJsonObjectEnd(string $haystack, int $start): int {
        $len = strlen($haystack);

        if ($start >= $len || $haystack[$start] !== '{') {
            return -1;
        }

        $depth = 0;
        $in_string = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $haystack[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($in_string) {
                if ($c === '\\') {
                    $escape = true;
                }
                elseif ($c === '"') {
                    $in_string = false;
                }
                continue;
            }

            if ($c === '"') {
                $in_string = true;
            }
            elseif ($c === '{') {
                $depth++;
            }
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return -1;
    }

    /**
     * Try to parse the FIRST tool call from an AI response.
     *
     * Returns ['tool' => ..., 'params' => ..., 'confirm' => bool, 'confirm_message' => string]
     * or null if the response is not a tool call. Tolerates surrounding text,
     * markdown code fences, AND multiple concatenated tool calls (extracts
     * only the first complete `{"tool":...}` object).
     */
    public static function parseToolCall(string $response): ?array {
        $trimmed = trim($response);

        // Try to extract JSON from a markdown code fence first.
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $trimmed, $m)) {
            $trimmed = trim($m[1]);
        }

        // Find the first `{"tool"` block in the response.
        $json_start = strpos($trimmed, '{"tool"');

        if ($json_start === false) {
            // Maybe the AI used single quotes or extra whitespace. Try a
            // more permissive search before giving up.
            if (!preg_match('/\{\s*"tool"\s*:/', $trimmed, $m, PREG_OFFSET_CAPTURE)) {
                return null;
            }
            $json_start = (int) $m[0][1];
        }

        $end = self::findJsonObjectEnd($trimmed, $json_start);

        if ($end <= $json_start) {
            return null;
        }

        $candidate = substr($trimmed, $json_start, $end - $json_start);
        $decoded = json_decode($candidate, true);

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
     * Uses string-aware JSON object scanning so tool params containing
     * literal `{` / `}` (e.g. CSS in HTML content, JSON-stringified arrays)
     * do not confuse the brace counter. Removes both markdown-fenced and
     * bare {"tool":...} blocks.
     */
    public static function stripToolCalls(string $response): string {
        // Remove markdown-fenced tool calls. The non-greedy regex is a heuristic
        // for the common "AI wraps its own tool call in ```json ... ```" case.
        $cleaned = preg_replace('/```(?:json)?\s*\{"tool"\s*:[\s\S]*?\}\s*```/', '', $response);

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

            $end = self::findJsonObjectEnd($cleaned, $next);

            if ($end <= $next) {
                // Malformed (no matching close). Keep the rest verbatim to
                // avoid eating the entire tail of the message.
                $result .= substr($cleaned, $next);
                break;
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

            case 'build_file_report':
                return self::executeBuildFileReport($params, $context);

            case 'list_zabbix_hosts':
                return self::executeListZabbixHosts($params, $zabbix_api);

            case 'list_netbox_devices':
                return self::executeListNetBoxDevices($params, $context);

            case 'get_netbox_info':
                return self::executeGetNetBoxInfo($params, $context);

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

            case 'enable_host':
                return self::executeSetHostStatus($params, $zabbix_api, true);

            case 'disable_host':
                return self::executeSetHostStatus($params, $zabbix_api, false);

            case 'update_host_tags':
                return self::executeUpdateHostTags($params, $zabbix_api);

            case 'update_host_inventory':
                return self::executeUpdateHostInventory($params, $zabbix_api);

            case 'update_host_macros':
                return self::executeUpdateHostMacros($params, $zabbix_api);

            case 'update_host_interface':
                return self::executeUpdateHostInterface($params, $zabbix_api);

            case 'create_web_scenario':
                return self::executeCreateWebScenario($params, $zabbix_api);

            case 'create_web_scenario_trigger':
                return self::executeCreateWebScenarioTrigger($params, $zabbix_api);

            case 'create_problem_dashboard':
                return self::executeCreateProblemDashboard($params, $zabbix_api);

            case 'link_template_to_host':
                return self::executeLinkTemplateToHost($params, $zabbix_api, $context);

            case 'unlink_template_from_host':
                return self::executeUnlinkTemplateFromHost($params, $zabbix_api, $context);

            case 'enable_lld_rule':
                return self::executeSetLldRuleStatus($params, $zabbix_api, true);

            case 'disable_lld_rule':
                return self::executeSetLldRuleStatus($params, $zabbix_api, false);

            case 'create_host':
                return self::executeCreateHost($params, $zabbix_api);

            case 'create_trigger':
                return self::executeCreateTrigger($params, $zabbix_api);

            case 'get_proxy_assigned_hosts':
                return self::executeGetProxyAssignedHosts($params, $zabbix_api);

            case 'preview_disable_triggers':
                return self::executePreviewDisableTriggers($params, $zabbix_api, $context);

            case 'preview_disable_items_by_error':
                return self::executePreviewDisableItemsByError($params, $zabbix_api, $context);

            case 'preview_enable_items':
                return self::executePreviewEnableItems($params, $zabbix_api, $context);

            case 'preview_bulk_add_host_tag':
                return self::executePreviewBulkAddHostTag($params, $zabbix_api, $context);

            case 'preview_link_template':
                return self::executePreviewLinkTemplate($params, $zabbix_api, $context, false);

            case 'preview_unlink_template':
                return self::executePreviewLinkTemplate($params, $zabbix_api, $context, true);

            case 'apply_bulk_action':
                return self::executeApplyBulkAction($params, $zabbix_api, $context);

            case 'get_event_timeline':
                return self::executeGetEventTimeline($params, $zabbix_api);

            case 'get_related_problems':
                return self::executeGetRelatedProblems($params, $zabbix_api);

            case 'get_recent_changes':
            case 'get_auditlog_for_object':
                return self::executeGetAuditLogForObject($params, $zabbix_api);

            case 'get_audit_log':
                return self::executeGetAuditLog($params, $zabbix_api);

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

            case 'get_host_interfaces':
                return self::executeGetHostInterfaces($params, $zabbix_api);

            case 'get_metric_summary':
                return self::executeGetMetricSummary($params, $zabbix_api);

            case 'get_trigger_dependencies':
                return self::executeGetTriggerDependencies($params, $zabbix_api);

            case 'get_noisy_triggers':
                return self::executeGetNoisyTriggers($params, $zabbix_api);

            case 'get_web_scenarios':
                return self::executeGetWebScenarios($params, $zabbix_api);

            case 'get_sla_overview':
                return self::executeGetSlaOverview($params, $zabbix_api);

            case 'suppress_problem':
                return self::executeSuppressProblem($params, $zabbix_api);

            case 'unsuppress_problem':
                return self::executeUnsuppressProblem($params, $zabbix_api);

            case 'mark_problem_as_cause':
                return self::executeMarkProblemAsCause($params, $zabbix_api);

            case 'mark_problem_as_symptom':
                return self::executeMarkProblemAsSymptom($params, $zabbix_api);

            case 'change_problem_severity':
                return self::executeChangeProblemSeverity($params, $zabbix_api);

            case 'unacknowledge_problem':
                return self::executeUnacknowledgeProblem($params, $zabbix_api);

            case 'add_problem_message':
                return self::executeAddProblemMessage($params, $zabbix_api);

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

            case 'analyze_sla_scope':
                return self::executeAnalyzeSlaScope($params, $zabbix_api);

            case 'get_services':
                return self::executeGetServices($params, $zabbix_api);

            case 'create_sla_service':
                return self::executeCreateSlaService($params, $zabbix_api);

            case 'create_sla':
                return self::executeCreateSla($params, $zabbix_api);

            case 'add_template_tag':
                return self::executeAddTemplateTag($params, $zabbix_api);

            case 'add_trigger_tag':
                return self::executeAddTriggerTag($params, $zabbix_api);

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
        if (!empty($host['hostid'])) {
            $lines[] = 'Host ID: '.$host['hostid'].' (use this numeric ID in zabbix.php URLs that take hostids[])';
        }
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

        $filter_summary = $host_group !== '' ? ' (host group: '.$host_group.')' : '';

        $lines = [];
        $lines[] = 'Report generated: **'.count($rows).' unsupported item(s)**'.$filter_summary.'.';
        $lines[] = '';
        $lines[] = ReportStore::downloadMarker($result);

        return self::RAW_OUTPUT_SENTINEL.implode("\n", $lines);
    }

    /**
     * Save arbitrary AI-composed report content (HTML / CSV / MD / JSON) as a
     * downloadable file and emit a download-button marker. Used when the user
     * asks for a report file built from data the AI has already collected.
     */
    private static function executeBuildFileReport(array $params, array $context): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $server_session = (string) ($context['server_session'] ?? '');

        if ($config === null || $server_session === '') {
            return 'Error: file-report generation is not available in this context.';
        }

        $title = trim((string) ($params['title'] ?? ''));
        $format = strtolower(trim((string) ($params['format'] ?? '')));
        $content = (string) ($params['content'] ?? '');

        if ($title === '') {
            return 'Error: "title" parameter is required.';
        }

        $allowed_formats = ['html', 'csv', 'md', 'json'];
        if (!in_array($format, $allowed_formats, true)) {
            return 'Error: "format" must be one of '.implode(', ', $allowed_formats).'.';
        }

        if ($content === '') {
            return 'Error: "content" parameter is required.';
        }

        // 1 MB cap to bound disk usage from a runaway AI response.
        if (strlen($content) > 1048576) {
            return 'Error: report content exceeds the 1 MB limit.';
        }

        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'report';
        }
        $slug = substr($slug, 0, 96);

        try {
            $result = ReportStore::createDocument(
                $config,
                $server_session,
                $slug,
                $format,
                $content,
                ['generated_at' => time()]
            );
        }
        catch (\Throwable $e) {
            return 'Error saving report: '.$e->getMessage();
        }

        $lines = [
            'Report saved as **'.$result['filename'].'**.',
            '',
            ReportStore::downloadMarker($result)
        ];

        return self::RAW_OUTPUT_SENTINEL.implode("\n", $lines);
    }

    /**
     * Bulk-list Zabbix hosts with optional filters. Wraps
     * ZabbixApiClient::listHostsFiltered() for AI tool consumption.
     */
    private static function executeListZabbixHosts(array $params, ZabbixApiClient $api): string {
        $filters = [
            'host_group' => (string) ($params['host_group'] ?? ''),
            'search' => (string) ($params['search'] ?? ''),
            'status' => (string) ($params['status'] ?? ''),
            'tag' => (string) ($params['tag'] ?? ''),
            'limit' => (int) ($params['limit'] ?? 200)
        ];

        try {
            $rows = $api->listHostsFiltered($filters);
        }
        catch (\Throwable $e) {
            return 'Error listing hosts: '.$e->getMessage();
        }

        if (!$rows) {
            return 'No hosts matched those filters.';
        }

        $lines = ['Found '.count($rows).' host(s):', ''];

        foreach ($rows as $h) {
            $maintenance = $h['maintenance'] ? ' [maintenance]' : '';
            $groups = $h['groups'] ? ' groups: '.implode(', ', $h['groups']) : '';
            $tags = $h['tags'] ? ' tags: '.implode(', ', $h['tags']) : '';
            $name = $h['name'] !== '' && $h['name'] !== $h['host'] ? ' ('.$h['name'].')' : '';

            $lines[] = '- ['.$h['status'].$maintenance.'] hostid='.$h['hostid'].' '.$h['host'].$name.$groups.$tags;
        }

        return implode("\n", $lines);
    }

    /**
     * Bulk-list NetBox VMs / devices with optional filters. Used by the AI
     * to build inventory and capacity reports.
     */
    private static function executeListNetBoxDevices(array $params, array $context): string {
        $netbox = $context['netbox_client'] ?? null;

        if (!($netbox instanceof NetBoxClient)) {
            return 'Error: NetBox is not enabled or not configured. Ask an administrator to enable NetBox in AI Settings > NetBox.';
        }

        $filters = [
            'kind' => (string) ($params['kind'] ?? 'both'),
            'search' => (string) ($params['search'] ?? ''),
            'platform' => (string) ($params['platform'] ?? ''),
            'role' => (string) ($params['role'] ?? ''),
            'site' => (string) ($params['site'] ?? ''),
            'status' => (string) ($params['status'] ?? ''),
            'tenant' => (string) ($params['tenant'] ?? ''),
            'limit' => (int) ($params['limit'] ?? 200)
        ];

        try {
            $rows = $netbox->listDevicesAndVMs($filters);
        }
        catch (\Throwable $e) {
            return 'Error listing NetBox VMs/devices: '.$e->getMessage();
        }

        if (!$rows) {
            return 'No NetBox VMs or devices matched those filters.';
        }

        $fmt_size = static function (?int $mb): string {
            if ($mb === null || $mb <= 0) {
                return '';
            }
            if ($mb >= 1024 * 1024) {
                return number_format($mb / (1024 * 1024), 2).' TB';
            }
            if ($mb >= 1024) {
                return number_format($mb / 1024, 2).' GB';
            }
            return $mb.' MB';
        };

        $lines = ['Found '.count($rows).' NetBox entries:', ''];

        foreach ($rows as $r) {
            $parts = [];
            $parts[] = '['.$r['kind'].']';
            $parts[] = $r['name'];

            if ($r['status'] !== '') {
                $parts[] = 'status='.$r['status'];
            }
            if ($r['platform'] !== '') {
                $parts[] = 'platform='.$r['platform'];
            }
            if ($r['operating_system'] !== '') {
                $parts[] = 'OS='.$r['operating_system'];
            }
            if ($r['role'] !== '') {
                $parts[] = 'role='.$r['role'];
            }
            if ($r['site'] !== '') {
                $parts[] = 'site='.$r['site'];
            }
            if ($r['vcpus'] !== null) {
                $parts[] = 'vCPU='.(is_float($r['vcpus']) ? rtrim(rtrim(number_format($r['vcpus'], 2, '.', ''), '0'), '.') : $r['vcpus']);
            }
            $ram = $fmt_size($r['memory_mb']);
            if ($ram !== '') {
                $parts[] = 'RAM='.$ram;
            }
            $disk = $fmt_size($r['disk_mb']);
            if ($disk !== '') {
                $parts[] = 'disk='.$disk;
            }
            if ($r['primary_ip'] !== '') {
                $parts[] = 'ip='.$r['primary_ip'];
            }

            $lines[] = '- '.implode(' | ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Deep-dive on a single host in NetBox (VM or device).
     */
    private static function executeGetNetBoxInfo(array $params, array $context): string {
        $netbox = $context['netbox_client'] ?? null;

        if (!($netbox instanceof NetBoxClient)) {
            return 'Error: NetBox is not enabled or not configured. Ask an administrator to enable NetBox in AI Settings > NetBox.';
        }

        $hostname = trim((string) ($params['hostname'] ?? ''));

        if ($hostname === '') {
            return 'Error: "hostname" parameter is required.';
        }

        try {
            $context_text = $netbox->getContextForHostname($hostname);
        }
        catch (\Throwable $e) {
            return 'Error looking up NetBox info: '.$e->getMessage();
        }

        return $context_text !== '' ? $context_text : 'No NetBox VM or device match found for "'.$hostname.'".';
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
        $host = trim((string) ($params['host'] ?? ''));
        $host_group = trim((string) ($params['host_group'] ?? ''));

        $host_ids = [];

        if ($host !== '') {
            $hid = $api->getHostIdByName($host);
            if ($hid === null) {
                return 'Error: host "'.$host.'" not found.';
            }
            $host_ids[] = (string) $hid;
        }

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

        $events = $api->getProblemsTimeline($since, $until, $severity_min, $host_group_ids, 20000, $host_ids);

        $buckets = self::bucketProblems($events, $since, $until, $group_by);

        $total = 0;
        $by_severity_total = array_fill(0, 6, 0);
        foreach ($buckets['data'] as $bucket) {
            foreach ($bucket['counts'] as $sev => $count) {
                $by_severity_total[$sev] += $count;
                $total += $count;
            }
        }

        $scope = [];
        if ($host !== '') {
            $scope[] = $host;
        }
        if ($host_group !== '') {
            $scope[] = $host_group;
        }

        $title = 'Problems over the last '.$period_days.' day(s)'
            .($scope ? ' — '.implode(', ', $scope) : '')
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
        $summary_lines[] = ReportStore::downloadMarker($svg_result);

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

        $h = static function ($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $f = static function ($n): string {
            return number_format((float) $n, 2, '.', '');
        };

        // ── Layout (clean card matching the Incident Timeline charts) ──
        $pad = 16;
        $plot_x = 46;
        $width = (int) max(560, min(1100, $plot_x + 18 + $bucket_count * 46));

        // Legend chips: severities present in the data (fallback to all six).
        $sev_order = [5, 4, 3, 2, 1, 0];
        $present = [];
        foreach ($sev_order as $sev) {
            if ((int) ($by_severity_total[$sev] ?? 0) > 0) {
                $present[] = $sev;
            }
        }
        if (!$present) {
            $present = $sev_order;
        }
        $legend_items = [];
        foreach ($present as $sev) {
            $label = self::SEVERITY_LABELS[(string) $sev].' '.(int) ($by_severity_total[$sev] ?? 0);
            $legend_items[] = [
                'sev' => $sev,
                'label' => $label,
                'w' => 17 + (int) round(mb_strlen($label) * 6.3) + 16
            ];
        }
        $legend_avail = $width - $pad * 2;
        $rows = 1;
        $cursor = 0;
        foreach ($legend_items as $li) {
            if ($cursor > 0 && $cursor + $li['w'] > $legend_avail) {
                $rows++;
                $cursor = 0;
            }
            $cursor += $li['w'];
        }

        $title_y = $pad + 13;
        $legend_y0 = $title_y + 19;
        $plot_top = $legend_y0 + ($rows * 18) + 8;
        $x_label_space = $bucket_count > 14 ? 54 : 26;
        $plot_h = 200;
        $height = (int) ($plot_top + $plot_h + $x_label_space + $pad);
        $plot_w = $width - $plot_x - 18;
        $base_y = $plot_top + $plot_h;

        $max_count = 0;
        foreach ($data as $bucket) {
            $sum = (int) array_sum($bucket['counts']);
            if ($sum > $max_count) {
                $max_count = $sum;
            }
        }
        $y_max = self::niceCeil(max($max_count, 1));
        $bar_slot = $plot_w / max($bucket_count, 1);
        $bar_w = max(6.0, min(46.0, $bar_slot * 0.66));

        $clip_defs = [];
        $parts = [];
        $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $parts[] = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" font-family="-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif">';

        // Card background + subtle border.
        $parts[] = '<rect x="0.5" y="0.5" width="'.($width - 1).'" height="'.($height - 1).'" rx="6" fill="#ffffff" stroke="#dfe4ec"/>';

        // Title.
        $parts[] = '<text x="'.$pad.'" y="'.$title_y.'" font-size="14" font-weight="600" fill="#1f2b3a">'.$h($title).'</text>';

        // Legend chips.
        $lx = $pad;
        $ly = $legend_y0;
        $cursor = 0;
        foreach ($legend_items as $li) {
            if ($cursor > 0 && $cursor + $li['w'] > $legend_avail) {
                $ly += 18;
                $lx = $pad;
                $cursor = 0;
            }
            $parts[] = '<rect x="'.$lx.'" y="'.($ly - 9).'" width="11" height="11" rx="2" fill="'.self::SEVERITY_COLORS[$li['sev']].'"/>';
            $parts[] = '<text x="'.($lx + 16).'" y="'.$ly.'" font-size="11" fill="#5c6b7a">'.$h($li['label']).'</text>';
            $lx += $li['w'];
            $cursor += $li['w'];
        }

        // Horizontal gridlines + y-axis value labels (no boxed frame).
        $gridlines = 4;
        for ($i = 0; $i <= $gridlines; $i++) {
            $value = (int) round($y_max * $i / $gridlines);
            $y = $base_y - ($plot_h * $i / $gridlines);
            $parts[] = '<line x1="'.$plot_x.'" y1="'.$f($y).'" x2="'.$f($plot_x + $plot_w).'" y2="'.$f($y).'" stroke="'.($i === 0 ? '#dfe4ec' : '#eef2f6').'" stroke-width="1"/>';
            $parts[] = '<text x="'.($plot_x - 8).'" y="'.$f($y + 3.5).'" font-size="11" text-anchor="end" fill="#94a3b8">'.$h($value).'</text>';
        }

        // Stacked bars with rounded tops (clipped) + thin white separators.
        $x = $plot_x + ($bar_slot - $bar_w) / 2;
        $bi = 0;
        foreach ($data as $bucket) {
            $bi++;
            $bucket_total = (int) array_sum($bucket['counts']);

            if ($bucket_total > 0) {
                $stack_h = $plot_h * ($bucket_total / $y_max);
                $top_y = $base_y - $stack_h;
                $r = min(3.0, $bar_w / 2, $stack_h / 2);
                $cid = 'bc'.$bi;
                $clip_defs[] = '<clipPath id="'.$cid.'"><path d="M '.$f($x).' '.$f($top_y + $r)
                    .' Q '.$f($x).' '.$f($top_y).' '.$f($x + $r).' '.$f($top_y)
                    .' H '.$f($x + $bar_w - $r)
                    .' Q '.$f($x + $bar_w).' '.$f($top_y).' '.$f($x + $bar_w).' '.$f($top_y + $r)
                    .' V '.$f($base_y).' H '.$f($x).' Z"/></clipPath>';

                $parts[] = '<g clip-path="url(#'.$cid.')">';
                $stack_y = $base_y;
                $seps = [];
                foreach ([0, 1, 2, 3, 4, 5] as $sev) {
                    $count = (int) ($bucket['counts'][$sev] ?? 0);
                    if ($count <= 0) {
                        continue;
                    }
                    $seg_h = $plot_h * ($count / $y_max);
                    $stack_y -= $seg_h;
                    $parts[] = '<rect x="'.$f($x).'" y="'.$f($stack_y).'" width="'.$f($bar_w).'" height="'.$f($seg_h).'" fill="'.self::SEVERITY_COLORS[$sev].'"><title>'.$h(self::SEVERITY_LABELS[(string) $sev].': '.$count).'</title></rect>';
                    if ($stack_y > $top_y + 0.5) {
                        $seps[] = $stack_y;
                    }
                }
                foreach ($seps as $sy) {
                    $parts[] = '<line x1="'.$f($x).'" y1="'.$f($sy).'" x2="'.$f($x + $bar_w).'" y2="'.$f($sy).'" stroke="#ffffff" stroke-width="1"/>';
                }
                $parts[] = '</g>';
            }

            // X-axis bucket label.
            $label_x = $x + $bar_w / 2;
            $label_y = $base_y + 15;
            $rotation = $bucket_count > 14 ? -45 : 0;
            $anchor = $rotation === 0 ? 'middle' : 'end';
            $parts[] = '<text x="'.$f($label_x).'" y="'.$f($label_y).'" font-size="10.5" text-anchor="'.$anchor.'" fill="#64748b" transform="rotate('.$rotation.' '.$f($label_x).' '.$f($label_y).')">'.$h($bucket['label']).'</text>';

            $x += $bar_slot;
        }

        if ($clip_defs) {
            array_splice($parts, 2, 0, ['<defs>'.implode('', $clip_defs).'</defs>']);
        }

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

    private static function executeGetAuditLog(array $params, ZabbixApiClient $api): string {
        $username = trim((string) ($params['username'] ?? ''));
        $userid = trim((string) ($params['userid'] ?? ''));
        $action_name = strtolower(trim((string) ($params['action'] ?? '')));
        $resourcetype = (isset($params['resourcetype']) && $params['resourcetype'] !== '')
            ? (int) $params['resourcetype']
            : -1;
        $since = (int) ($params['since_unix'] ?? 0);
        $until = (int) ($params['until_unix'] ?? 0);
        $count_only = Util::truthy($params['count_only'] ?? false);
        $limit = (int) ($params['limit'] ?? 50);

        // The audit log can only be filtered by userid, so map a username first.
        if ($userid === '' && $username !== '') {
            $resolved = $api->getUserIdByUsername($username);
            if ($resolved === null) {
                return 'No Zabbix user found with username "'.$username.'". Check the spelling or pass a numeric userid. (Listing users requires Super Admin in Zabbix.)';
            }
            $userid = $resolved;
        }

        // Translate the friendly action name into Zabbix audit action code(s).
        $action_filter = null;
        if ($action_name !== '') {
            $map = self::auditActionMap();
            if (!isset($map[$action_name])) {
                return 'Unknown action "'.$action_name.'". Use one of: '.implode(', ', array_keys($map)).'.';
            }
            $action_filter = $map[$action_name];
        }

        $filter = [];
        if ($userid !== '') {
            $filter['userid'] = $userid;
        }
        if ($action_filter !== null) {
            $filter['action'] = $action_filter;
        }
        if ($resourcetype >= 0) {
            $filter['resourcetype'] = $resourcetype;
        }

        // Human-readable description of the query, for the reply header.
        $scope = [];
        if ($username !== '') {
            $scope[] = 'user "'.$username.'"';
        }
        elseif ($userid !== '') {
            $scope[] = 'userid '.$userid;
        }
        if ($action_name !== '') {
            $scope[] = 'action "'.$action_name.'"';
        }
        if ($resourcetype >= 0) {
            $scope[] = 'resourcetype '.$resourcetype;
        }
        if ($since > 0) {
            $scope[] = 'since '.date('Y-m-d H:i', $since);
        }
        if ($until > 0) {
            $scope[] = 'until '.date('Y-m-d H:i', $until);
        }
        $scope_str = $scope ? ' for '.implode(', ', $scope) : '';

        if ($count_only) {
            $count = $api->countAuditLog($filter, $since, $until);
            if ($count === null) {
                return 'Could not read the Zabbix audit log'.$scope_str.'. The audit log is readable by Super Admin users only — confirm the API user/token has Super Admin rights.';
            }
            return $count.' audit log entry/entries'.$scope_str.'.';
        }

        $entries = $api->getAuditLog($filter, $since, $until, $limit);
        if (!$entries) {
            return 'No audit log entries found'.$scope_str.'. (The Zabbix audit log is Super-Admin-only and limited by your audit housekeeping retention; older events may already be purged.)';
        }

        $lines = [count($entries).' audit log entry/entries'.$scope_str.' (most recent first):', ''];

        foreach ($entries as $e) {
            $when = date('Y-m-d H:i:s', (int) ($e['clock'] ?? 0));
            $act = self::auditActionLabel((int) ($e['action'] ?? -1));
            $user = (string) ($e['username'] ?? '');
            $ip = (string) ($e['ip'] ?? '');
            $resource = (string) ($e['resourcename'] ?? '');
            $row = '- ['.$when.'] '.$act;
            if ($user !== '') {
                $row .= ' user='.$user;
            }
            if ($ip !== '') {
                $row .= ' ip='.$ip;
            }
            if ($resource !== '') {
                $row .= ' resource="'.self::truncateCell($resource, 120).'"';
            }
            $lines[] = $row;
        }

        return implode("\n", $lines);
    }

    /**
     * Friendly audit action name -> Zabbix audit action code(s).
     * Codes follow Zabbix 6.0+ (this module targets Zabbix 6.4+).
     */
    private static function auditActionMap(): array {
        return [
            'login' => [8],
            'login_success' => [8],
            'login_failed' => [9],
            'logout' => [4],
            'add' => [0],
            'update' => [1],
            'delete' => [2],
            'execute' => [7],
            'push' => [12]
        ];
    }

    private static function auditActionLabel(int $action): string {
        $labels = [
            0 => 'add',
            1 => 'update',
            2 => 'delete',
            4 => 'logout',
            7 => 'execute',
            8 => 'login',
            9 => 'login_failed',
            10 => 'history_clear',
            11 => 'config_refresh',
            12 => 'push'
        ];

        return 'action='.($labels[$action] ?? (string) $action);
    }

    private static function executeGetHostInterfaces(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));
        if ($hostname === '') {
            return 'Error: hostname is required.';
        }

        $interfaces = $api->getHostInterfaces($hostname);
        if (!$interfaces) {
            return 'No interfaces found for host "'.$hostname.'" (the host may not exist, or has no interfaces).';
        }

        $types = [1 => 'Agent', 2 => 'SNMP', 3 => 'IPMI', 4 => 'JMX'];
        $avail = [0 => 'unknown', 1 => 'available', 2 => 'unavailable'];

        $lines = [count($interfaces).' interface(s) on host "'.$hostname.'":', ''];
        foreach ($interfaces as $i) {
            $type = $types[(int) ($i['type'] ?? 0)] ?? ('type'.($i['type'] ?? '?'));
            $useip = ((int) ($i['useip'] ?? 1) === 1);
            $addr = $useip ? (string) ($i['ip'] ?? '') : (string) ($i['dns'] ?? '');
            $port = (string) ($i['port'] ?? '');
            $main = ((int) ($i['main'] ?? 0) === 1) ? ' [default]' : '';
            $av = $avail[(int) ($i['available'] ?? 0)] ?? '?';
            $row = '- '.$type.$main.': '.($useip ? 'IP ' : 'DNS ').$addr.':'.$port.' — '.$av;
            $err = trim((string) ($i['error'] ?? ''));
            if ($err !== '') {
                $row .= ' — error: '.self::truncateCell($err, 160);
            }
            $lines[] = $row;
        }
        return implode("\n", $lines);
    }

    private static function executeGetMetricSummary(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['host'] ?? ($params['hostname'] ?? '')));
        $item_search = trim((string) ($params['item'] ?? ($params['item_search'] ?? '')));
        $period_hours = (int) ($params['period_hours'] ?? 24);
        if ($period_hours <= 0) {
            $period_hours = 24;
        }

        if ($hostname === '' || $item_search === '') {
            return 'Error: both host and item are required.';
        }

        $s = $api->getMetricSummary($hostname, $item_search, $period_hours);

        if (isset($s['error'])) {
            switch ($s['error']) {
                case 'host_not_found':
                    return 'Host "'.$hostname.'" was not found.';
                case 'no_item':
                    return 'No item on "'.$hostname.'" matched "'.$item_search.'".';
                case 'not_numeric':
                    $c = implode(', ', $s['candidates'] ?? []);
                    return 'No NUMERIC item on "'.$hostname.'" matched "'.$item_search.'". Matching (non-numeric) items: '.($c !== '' ? $c : '(none)').'.';
                case 'no_data':
                    return 'Item "'.($s['item']['name'] ?? $item_search).'" on "'.$hostname.'" has no data in the last '.$period_hours.'h.';
                default:
                    return 'Could not summarise "'.$item_search.'" on "'.$hostname.'".';
            }
        }

        $name = (string) ($s['item']['name'] ?? $item_search);
        $units = trim((string) ($s['units'] ?? ''));
        $fmt = static function($v) use ($units) {
            if ($v === null) {
                return 'n/a';
            }
            $v = (float) $v;
            $str = (abs($v) >= 1000) ? number_format($v, 0) : rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
            if ($str === '' || $str === '-') {
                $str = '0';
            }
            return $str.($units !== '' ? ' '.$units : '');
        };

        $lines = [
            'Metric summary for "'.$name.'" on "'.$hostname.'" over the last '.$s['period_hours'].'h ('.$s['source'].', '.$s['count'].' samples):',
            '- last: '.$fmt($s['last'] ?? null),
            '- min: '.$fmt($s['min'] ?? null),
            '- max: '.$fmt($s['max'] ?? null),
            '- avg: '.$fmt($s['avg'] ?? null)
        ];
        if (isset($s['p95'])) {
            $lines[] = '- p95: '.$fmt($s['p95']);
        }
        if (isset($s['first'], $s['last'])) {
            $delta = (float) $s['last'] - (float) $s['first'];
            $lines[] = '- change (first->last): '.($delta >= 0 ? '+' : '').$fmt($delta);
        }
        if (($s['source'] ?? '') === 'trends') {
            $lines[] = '(Long window — based on hourly trends; p95 not available.)';
        }
        return implode("\n", $lines);
    }

    private static function executeGetTriggerDependencies(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));
        $search = trim((string) ($params['search'] ?? ''));

        $triggers = $api->getTriggerDependencies($hostname, $search);
        if (!$triggers) {
            return 'No triggers found'.($hostname !== '' ? ' for host "'.$hostname.'"' : '').($search !== '' ? ' matching "'.$search.'"' : '').'.';
        }

        $with_deps = [];
        foreach ($triggers as $t) {
            if (!empty($t['dependencies'])) {
                $with_deps[] = $t;
            }
        }
        if (!$with_deps) {
            return 'Checked '.count($triggers).' trigger(s)'.($hostname !== '' ? ' on "'.$hostname.'"' : '').'; none have dependencies configured.';
        }

        $lines = [count($with_deps).' trigger(s) with dependencies:', ''];
        foreach ($with_deps as $t) {
            $host = (string) ($t['hosts'][0]['host'] ?? '');
            $name = (string) ($t['description'] ?? '');
            $dep_names = [];
            foreach ($t['dependencies'] as $d) {
                $dep_names[] = (string) ($d['description'] ?? ($d['triggerid'] ?? ''));
            }
            $lines[] = '- '.($host !== '' ? '['.$host.'] ' : '').$name;
            $lines[] = '    depends on: '.implode('; ', $dep_names);
        }
        return implode("\n", $lines);
    }

    private static function executeGetNoisyTriggers(array $params, ZabbixApiClient $api): string {
        $period_hours = (int) ($params['period_hours'] ?? 24);
        if ($period_hours <= 0) {
            $period_hours = 24;
        }
        $limit = (int) ($params['limit'] ?? 15);
        if ($limit <= 0) {
            $limit = 15;
        }

        $rows = $api->getNoisyTriggers($period_hours, $limit);
        if (!$rows) {
            return 'No problem events were recorded in the last '.$period_hours.'h.';
        }

        $lines = ['Top '.count($rows).' noisiest trigger(s) by problem count over the last '.$period_hours.'h:', ''];
        foreach ($rows as $r) {
            $host = (string) ($r['host'] ?? '');
            $lines[] = '- '.$r['count'].'x '.($host !== '' ? '['.$host.'] ' : '').(string) ($r['name'] ?? '');
        }
        return implode("\n", $lines);
    }

    private static function executeGetWebScenarios(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));

        $scenarios = $api->getWebScenarios($hostname);
        if (!$scenarios) {
            return 'No web scenarios found'.($hostname !== '' ? ' for host "'.$hostname.'"' : '').'.';
        }

        $lines = [count($scenarios).' web scenario(s)'.($hostname !== '' ? ' on "'.$hostname.'"' : '').':', ''];
        foreach ($scenarios as $w) {
            $host = (string) ($w['hosts'][0]['host'] ?? '');
            $status = ((int) ($w['status'] ?? 0) === 0) ? 'enabled' : 'disabled';
            $steps = is_array($w['steps'] ?? null) ? $w['steps'] : [];
            $lines[] = '- '.($host !== '' ? '['.$host.'] ' : '').(string) ($w['name'] ?? '').' — '.$status.', every '.(string) ($w['delay'] ?? '?').', '.count($steps).' step(s)';
            foreach ($steps as $st) {
                $codes = trim((string) ($st['status_codes'] ?? ''));
                $lines[] = '    '.(string) ($st['no'] ?? '').'. '.(string) ($st['name'] ?? '').' -> '.(string) ($st['url'] ?? '').($codes !== '' ? ' (expect '.$codes.')' : '');
            }
        }
        return implode("\n", $lines);
    }

    private static function executeGetSlaOverview(array $params, ZabbixApiClient $api): string {
        $slas = $api->getSlaOverview((int) ($params['limit'] ?? 50));
        if (!$slas) {
            return 'No SLAs are configured (or the Services/SLA feature is not in use on this Zabbix instance).';
        }

        $periods = [0 => 'daily', 1 => 'weekly', 2 => 'monthly', 3 => 'quarterly', 4 => 'annually'];

        $lines = [count($slas).' SLA(s):', ''];
        foreach ($slas as $s) {
            $status = ((int) ($s['status'] ?? 0) === 0) ? 'enabled' : 'disabled';
            $period = $periods[(int) ($s['period'] ?? 0)] ?? '?';
            $tags = [];
            foreach (($s['service_tags'] ?? []) as $tg) {
                $val = (string) ($tg['value'] ?? '');
                $tags[] = (string) ($tg['tag'] ?? '').($val !== '' ? '='.$val : '');
            }
            $lines[] = '- '.(string) ($s['name'] ?? '').' — SLO '.(string) ($s['slo'] ?? '?').'%, '.$period.', '.$status
                .($tags ? ' — services tagged: '.implode(', ', $tags) : '');
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
        $cause_eventid = trim((string) ($params['cause_eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }
        if ($cause_eventid === '') {
            return 'Error: cause_eventid is required — Zabbix needs the parent cause event to mark an event as a symptom.';
        }

        $api->markProblemAsSymptom($eventid, $cause_eventid);

        return 'Event '.$eventid.' marked as a symptom of cause event '.$cause_eventid.'.';
    }

    private static function executeChangeProblemSeverity(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        if (!isset($params['severity']) || $params['severity'] === '') {
            return 'Error: severity is required (0=Not classified .. 5=Disaster).';
        }
        $severity = (int) $params['severity'];
        if ($severity < 0 || $severity > 5) {
            return 'Error: severity must be between 0 (Not classified) and 5 (Disaster).';
        }

        $api->changeProblemSeverity($eventid, $severity);

        $label = self::SEVERITY_LABELS[(string) $severity] ?? (string) $severity;

        return 'Event '.$eventid.' severity changed to '.$severity.' ('.$label.').';
    }

    private static function executeUnacknowledgeProblem(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }

        $api->unacknowledgeProblem($eventid);

        return 'Event '.$eventid.' un-acknowledged.';
    }

    private static function executeAddProblemMessage(array $params, ZabbixApiClient $api): string {
        $eventid = trim((string) ($params['eventid'] ?? ''));
        $message = trim((string) ($params['message'] ?? ''));
        if ($eventid === '') {
            return 'Error: eventid is required.';
        }
        if ($message === '') {
            return 'Error: message is required.';
        }

        // event.acknowledge action bit 4 = add message only (no ack/close).
        $api->acknowledgeProblem($eventid, 4, $message);

        return 'Comment added to event '.$eventid.'.';
    }

    private static function executeSetHostStatus(array $params, ZabbixApiClient $api, bool $enable): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));
        if ($hostname === '') {
            return 'Error: hostname is required.';
        }

        $api->setHostStatus($hostname, $enable ? 0 : 1);

        return 'Host "'.$hostname.'" is now '.($enable ? 'ENABLED (monitored)' : 'DISABLED (not monitored)').'.';
    }

    private static function executeUpdateHostTags(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));
        $operation = strtolower(trim((string) ($params['operation'] ?? 'add')));
        $tags = $params['tags'] ?? [];

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }
        if (!in_array($operation, ['add', 'remove', 'replace'], true)) {
            return 'Error: operation must be one of add, remove, replace.';
        }
        if (!is_array($tags) || !$tags) {
            return 'Error: tags must be a non-empty array of {tag, value} objects.';
        }

        $api->updateHostTags($hostname, $operation, $tags);

        $labels = [];
        foreach ($tags as $t) {
            if (!is_array($t)) {
                continue;
            }
            $name = trim((string) ($t['tag'] ?? ''));
            if ($name === '') {
                continue;
            }
            $val = (string) ($t['value'] ?? '');
            $labels[] = $name.($val !== '' ? '='.$val : '');
        }

        return 'Host "'.$hostname.'" tags updated ('.$operation.'): '.implode(', ', $labels).'.';
    }

    private static function executeUpdateHostInventory(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));
        $fields = $params['fields'] ?? [];

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }
        if (!is_array($fields) || !$fields) {
            return 'Error: fields must be a non-empty object of inventory field => value.';
        }

        $api->updateHostInventory($hostname, $fields);

        return 'Host "'.$hostname.'" inventory updated (manual mode): '.implode(', ', array_keys($fields)).'.';
    }

    private static function executeUpdateHostMacros(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));
        $macros = $params['macros'] ?? [];

        if ($hostname === '') {
            return 'Error: hostname is required.';
        }
        if (!is_array($macros) || !$macros) {
            return 'Error: macros must be a non-empty array of {macro, value, type} objects.';
        }

        // Validate every macro before touching Zabbix, so a typo or bad type
        // can't reach the API or blank a secret/vault macro.
        foreach ($macros as $m) {
            if (!is_array($m)) {
                return 'Error: each macro must be an object like {"macro":"{$NAME}","value":"...","type":0}.';
            }
            $name = trim((string) ($m['macro'] ?? ''));
            if (!preg_match('/^\{\$[A-Z0-9_\.]+(:.*)?\}$/i', $name)) {
                return 'Error: "'.$name.'" is not a valid Zabbix user macro name (expected {$NAME}).';
            }
            $type = isset($m['type']) ? (int) $m['type'] : 0;
            if (!in_array($type, [0, 1, 2], true)) {
                return 'Error: macro "'.$name.'" has invalid type '.$type.' (0=text, 1=secret, 2=vault).';
            }
            if (!array_key_exists('value', $m)) {
                return 'Error: macro "'.$name.'" is missing a value.';
            }
            if (($type === 1 || $type === 2) && trim((string) $m['value']) === '') {
                return 'Error: refusing to set secret/vault macro "'.$name.'" to an empty value.';
            }
        }

        $result = $api->updateHostMacros($hostname, $macros);

        // Report macro NAMES and whether they were secret — never the values.
        $describe = static function(array $list): array {
            $out = [];
            foreach ($list as $m) {
                $secret = ((int) ($m['type'] ?? 0) !== 0) ? ' (secret)' : '';
                $out[] = (string) ($m['macro'] ?? '').$secret;
            }
            return $out;
        };

        $created = $describe($result['created'] ?? []);
        $updated = $describe($result['updated'] ?? []);

        $parts = [];
        if ($created) {
            $parts[] = 'created: '.implode(', ', $created);
        }
        if ($updated) {
            $parts[] = 'updated: '.implode(', ', $updated);
        }

        return 'Host "'.$hostname.'" macros — '.($parts ? implode('; ', $parts) : 'no changes').'. (Secret values are not displayed.)';
    }

    private static function executeUpdateHostInterface(array $params, ZabbixApiClient $api): string {
        $interfaceid = trim((string) ($params['interfaceid'] ?? ''));
        if ($interfaceid === '') {
            return 'Error: interfaceid is required (use get_host_interfaces to find it).';
        }

        $changes = [];
        foreach (['ip', 'dns', 'port'] as $f) {
            if (isset($params[$f]) && trim((string) $params[$f]) !== '') {
                $changes[$f] = trim((string) $params[$f]);
            }
        }
        if (isset($params['useip']) && $params['useip'] !== '') {
            $u = (int) $params['useip'];
            if ($u !== 0 && $u !== 1) {
                return 'Error: useip must be 0 (connect by DNS) or 1 (connect by IP).';
            }
            $changes['useip'] = $u;
        }
        if (isset($changes['port'])
            && (!preg_match('/^\d+$/', (string) $changes['port']) || (int) $changes['port'] < 1 || (int) $changes['port'] > 65535)) {
            return 'Error: port must be a number between 1 and 65535.';
        }
        if (!$changes) {
            return 'Error: provide at least one of ip, dns, port, useip.';
        }

        $api->updateHostInterface($interfaceid, $changes);

        $desc = [];
        foreach ($changes as $k => $v) {
            if ($k === 'useip') {
                $desc[] = 'mode='.((int) $v === 1 ? 'IP' : 'DNS');
            }
            else {
                $desc[] = $k.'='.$v;
            }
        }

        return 'Interface '.$interfaceid.' updated: '.implode(', ', $desc).'.';
    }

    private static function executeCreateWebScenario(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));
        $name = trim((string) ($params['name'] ?? ''));
        $url = trim((string) ($params['url'] ?? ''));
        if ($hostname === '' || $name === '' || $url === '') {
            return 'Error: hostname, name and url are required.';
        }

        $opts = [
            'delay' => (string) ($params['delay'] ?? '60s'),
            'status_codes' => (string) ($params['status_codes'] ?? '200'),
            'step_name' => (string) ($params['step_name'] ?? 'Check')
        ];
        if (isset($params['tags']) && is_array($params['tags'])) {
            $opts['tags'] = $params['tags'];
        }

        $api->createWebScenario($hostname, $name, $url, $opts);

        $msg = 'Web scenario "'.$name.'" created on "'.$hostname.'" — '.$url.' every '.$opts['delay'].', expecting HTTP '.$opts['status_codes'].'.';

        if (Util::truthy($params['add_failure_trigger'] ?? false)) {
            $priority = 3;
            if (isset($params['trigger_priority']) && $params['trigger_priority'] !== '') {
                $tp = (int) $params['trigger_priority'];
                if ($tp >= 0 && $tp <= 5) {
                    $priority = $tp;
                }
            }
            // The scenario already exists; if the trigger fails, keep the
            // scenario-created message and just note the trigger problem.
            try {
                $tname = self::webScenarioTriggerName($hostname, $name);
                $api->createTrigger($tname, self::webScenarioFailExpression($hostname, $name), [
                    'priority' => $priority,
                    'comments' => 'Web scenario "'.$name.'" failed. See web.test.error['.$name.'] for the error.'
                ]);
                $msg .= ' A failure trigger "'.$tname.'" was also created (severity '.$priority.').';
            }
            catch (\Throwable $e) {
                $msg .= ' (The scenario was created, but the failure trigger could not be: '.$e->getMessage().')';
            }
        }

        return $msg;
    }

    private static function executeCreateWebScenarioTrigger(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ($params['host'] ?? '')));
        $scenario = trim((string) ($params['scenario_name'] ?? ''));
        if ($hostname === '' || $scenario === '') {
            return 'Error: hostname and scenario_name are required.';
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            $name = self::webScenarioTriggerName($hostname, $scenario);
        }

        $priority = 3;
        if (isset($params['priority']) && $params['priority'] !== '') {
            $p = (int) $params['priority'];
            if ($p < 0 || $p > 5) {
                return 'Error: priority must be between 0 and 5.';
            }
            $priority = $p;
        }

        $result = $api->createTrigger($name, self::webScenarioFailExpression($hostname, $scenario), [
            'priority' => $priority,
            'comments' => 'Web scenario "'.$scenario.'" failed. See web.test.error['.$scenario.'] for the error.'
        ]);
        $id = is_array($result) ? (string) ($result['triggerids'][0] ?? '') : '';

        return 'Trigger "'.$name.'" created'.($id !== '' ? ' (triggerid '.$id.')' : '').' on "'.$hostname.'" — fires when web scenario "'.$scenario.'" fails.';
    }

    private static function webScenarioFailExpression(string $hostname, string $scenario): string {
        return 'last(/'.$hostname.'/web.test.fail['.self::quoteKeyParam($scenario).'])<>0';
    }

    private static function webScenarioTriggerName(string $hostname, string $scenario): string {
        return 'Web scenario "'.$scenario.'" failed on '.$hostname;
    }

    /** Quote a value for use as a Zabbix item-key parameter (handles spaces/commas/brackets). */
    private static function quoteKeyParam(string $p): string {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $p).'"';
    }

    private static function executeCreateProblemDashboard(array $params, ZabbixApiClient $api): string {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return 'Error: name is required.';
        }

        $result = $api->createProblemDashboard($name);
        $id = is_array($result) ? (string) ($result['dashboardids'][0] ?? '') : '';

        return 'Private dashboard "'.$name.'" created'.($id !== '' ? ' (id '.$id.')' : '').' with a Problems widget. Refine its filters in Monitoring > Dashboards.';
    }

    private static function executeLinkTemplateToHost(array $params, ZabbixApiClient $api, array $context): string {
        $template = trim((string) ($params['template'] ?? ''));
        $hostnames = $params['hostnames'] ?? [];
        if ($template === '') {
            return 'Error: template is required.';
        }
        if (!is_array($hostnames) || !$hostnames) {
            return 'Error: hostnames must be a non-empty array of hostnames.';
        }

        $max = (int) ($context['config']['zabbix_actions']['bulk_max_hosts'] ?? 25);
        if ($max < 1) {
            $max = 25;
        }

        $result = $api->linkTemplateToHosts($template, $hostnames, $max);

        return 'Linked template "'.$result['template'].'" to '.count($result['hosts']).' host(s): '.implode(', ', $result['hosts']).'.';
    }

    private static function executeUnlinkTemplateFromHost(array $params, ZabbixApiClient $api, array $context): string {
        $template = trim((string) ($params['template'] ?? ''));
        $hostnames = $params['hostnames'] ?? [];
        $clear = Util::truthy($params['clear'] ?? false);
        if ($template === '') {
            return 'Error: template is required.';
        }
        if (!is_array($hostnames) || !$hostnames) {
            return 'Error: hostnames must be a non-empty array of hostnames.';
        }

        $max = (int) ($context['config']['zabbix_actions']['bulk_max_hosts'] ?? 25);
        if ($max < 1) {
            $max = 25;
        }

        $result = $api->unlinkTemplateFromHosts($template, $hostnames, $clear, $max);

        return 'Unlinked template "'.$result['template'].'"'.($clear ? ' (and cleared its items/triggers)' : '').' from '.count($result['hosts']).' host(s): '.implode(', ', $result['hosts']).'.';
    }

    private static function executeSetLldRuleStatus(array $params, ZabbixApiClient $api, bool $enable): string {
        $id = trim((string) ($params['lld_rule_id'] ?? ($params['itemid'] ?? '')));
        if ($id === '') {
            return 'Error: lld_rule_id is required (use get_lld_rules to find it).';
        }

        $api->setLldRuleStatus($id, $enable ? 0 : 1);

        return 'LLD rule '.$id.' is now '.($enable ? 'ENABLED' : 'DISABLED').'.';
    }

    private static function executeCreateHost(array $params, ZabbixApiClient $api): string {
        $hostname = trim((string) ($params['hostname'] ?? ''));
        $groups = $params['groups'] ?? [];
        if ($hostname === '') {
            return 'Error: hostname is required.';
        }
        if (!is_array($groups) || !$groups) {
            return 'Error: at least one host group is required (the "groups" parameter).';
        }

        $opts = [
            'visible_name' => (string) ($params['visible_name'] ?? ''),
            'description' => (string) ($params['description'] ?? ''),
            'templates' => (isset($params['templates']) && is_array($params['templates'])) ? $params['templates'] : [],
            'interface_ip' => (string) ($params['interface_ip'] ?? ''),
            'interface_dns' => (string) ($params['interface_dns'] ?? ''),
            'interface_port' => (string) ($params['interface_port'] ?? '10050'),
            'create_missing_groups' => Util::truthy($params['create_missing_groups'] ?? false)
        ];

        $result = $api->createHost($hostname, $groups, $opts);
        $id = is_array($result) ? (string) ($result['hostids'][0] ?? '') : '';

        $msg = 'Host "'.$hostname.'" created'.($id !== '' ? ' (hostid '.$id.')' : '').'. Groups: '.implode(', ', array_map('strval', $groups));
        if ($opts['templates']) {
            $msg .= '. Templates: '.implode(', ', array_map('strval', $opts['templates']));
        }
        return $msg.'.';
    }

    private static function executeCreateTrigger(array $params, ZabbixApiClient $api): string {
        $desc = trim((string) ($params['description'] ?? ''));
        $expr = trim((string) ($params['expression'] ?? ''));
        if ($desc === '' || $expr === '') {
            return 'Error: both description (trigger name) and expression are required.';
        }

        $opts = [];
        if (isset($params['priority']) && $params['priority'] !== '') {
            $p = (int) $params['priority'];
            if ($p < 0 || $p > 5) {
                return 'Error: priority must be between 0 (Not classified) and 5 (Disaster).';
            }
            $opts['priority'] = $p;
        }
        if (!empty($params['comments'])) {
            $opts['comments'] = (string) $params['comments'];
        }
        if (!empty($params['recovery_expression'])) {
            $opts['recovery_expression'] = (string) $params['recovery_expression'];
        }

        $result = $api->createTrigger($desc, $expr, $opts);
        $id = is_array($result) ? (string) ($result['triggerids'][0] ?? '') : '';

        return 'Trigger "'.$desc.'" created'.($id !== '' ? ' (triggerid '.$id.')' : '').'.';
    }

    private static function executeGetProxyAssignedHosts(array $params, ZabbixApiClient $api): string {
        $proxy = trim((string) ($params['proxy'] ?? ''));

        $proxies = $api->getProxyAssignedHosts($proxy);
        if (!$proxies) {
            return 'No proxies found'.($proxy !== '' ? ' matching "'.$proxy.'"' : '').' (or no Zabbix proxies are configured).';
        }

        $lines = [];
        foreach ($proxies as $p) {
            $pname = (string) ($p['name'] ?? ($p['host'] ?? ''));
            $hosts = is_array($p['hosts'] ?? null) ? $p['hosts'] : [];
            $lines[] = 'Proxy "'.$pname.'" — '.count($hosts).' host(s):';
            if ($hosts) {
                $names = [];
                foreach ($hosts as $h) {
                    $names[] = (string) ($h['host'] ?? ($h['name'] ?? ($h['hostid'] ?? '')));
                }
                sort($names);
                $lines[] = '  '.implode(', ', $names);
            }
        }
        return implode("\n", $lines);
    }

    private static function bulkCap(array $context, string $key, int $default): int {
        $v = (int) ($context['config']['zabbix_actions'][$key] ?? $default);
        return $v > 0 ? $v : $default;
    }

    /**
     * Freeze a resolved target set under a single-use, session-bound token
     * (reusing PendingActionStore) and return a human-readable preview plus the
     * token. apply_bulk_action consumes the token and acts on EXACTLY this set.
     */
    private static function storeBulkPreview(array $context, string $operation, array $ids, array $extra, string $human_list, bool $capped, int $cap): string {
        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $session = (string) ($context['server_session'] ?? '');
        if ($config === null || $session === '') {
            return 'Error: bulk previews are not available in this context.';
        }
        if (!$ids) {
            return 'Nothing matched — there is nothing to do.';
        }

        try {
            $token = PendingActionStore::create($config, $session, [
                'kind' => 'bulk_preview',
                'operation' => $operation,
                'ids' => array_values($ids),
                'params' => $extra,
                'count' => count($ids)
            ]);
        }
        catch (\Throwable $e) {
            return 'Error: could not store the preview ('.$e->getMessage().').';
        }

        $note = $capped ? "\n(NOTE: capped at ".$cap." — narrow the filter to include more.)" : '';

        return 'PREVIEW — '.count($ids).' target(s):'."\n".$human_list.$note
            ."\n\nTo apply, call apply_bulk_action with preview_token=\"".$token."\". "
            .'This needs operator confirmation and affects EXACTLY these '.count($ids).' target(s).';
    }

    private static function executePreviewDisableTriggers(array $params, ZabbixApiClient $api, array $context): string {
        $name = trim((string) ($params['name_pattern'] ?? ''));
        $group = trim((string) ($params['host_group'] ?? ''));
        if ($name === '') {
            return 'Error: name_pattern is required.';
        }

        $cap = self::bulkCap($context, 'bulk_max_items', 100);
        $rows = $api->findEnabledTriggersByName($name, $group, $cap + 1);
        if (!$rows) {
            return 'No enabled triggers match "'.$name.'"'.($group !== '' ? ' in group "'.$group.'"' : '').'.';
        }
        $capped = count($rows) > $cap;
        if ($capped) {
            $rows = array_slice($rows, 0, $cap);
        }

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- ['.$r['host'].'] '.$r['description'];
        }

        return self::storeBulkPreview($context, 'disable_triggers', array_column($rows, 'triggerid'), [], implode("\n", $lines), $capped, $cap);
    }

    private static function executePreviewDisableItemsByError(array $params, ZabbixApiClient $api, array $context): string {
        $error = trim((string) ($params['error_pattern'] ?? ''));
        $group = trim((string) ($params['host_group'] ?? ''));
        if ($error === '') {
            return 'Error: error_pattern is required.';
        }

        $cap = self::bulkCap($context, 'bulk_max_items', 100);
        $rows = $api->findUnsupportedItemsByError($error, $group, $cap + 1);
        if (!$rows) {
            return 'No unsupported items have an error matching "'.$error.'"'.($group !== '' ? ' in group "'.$group.'"' : '').'.';
        }
        $capped = count($rows) > $cap;
        if ($capped) {
            $rows = array_slice($rows, 0, $cap);
        }

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- ['.$r['host'].'] '.$r['name'].' — '.self::truncateCell((string) ($r['error'] ?? ''), 100);
        }

        return self::storeBulkPreview($context, 'disable_items', array_column($rows, 'itemid'), [], implode("\n", $lines), $capped, $cap);
    }

    private static function executePreviewEnableItems(array $params, ZabbixApiClient $api, array $context): string {
        $search = trim((string) ($params['item_search'] ?? ''));
        $group = trim((string) ($params['host_group'] ?? ''));
        if ($search === '') {
            return 'Error: item_search is required.';
        }

        $cap = self::bulkCap($context, 'bulk_max_items', 100);
        $rows = $api->findDisabledItems($search, $group, $cap + 1);
        if (!$rows) {
            return 'No disabled items match "'.$search.'"'.($group !== '' ? ' in group "'.$group.'"' : '').'.';
        }
        $capped = count($rows) > $cap;
        if ($capped) {
            $rows = array_slice($rows, 0, $cap);
        }

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- ['.$r['host'].'] '.$r['name'];
        }

        return self::storeBulkPreview($context, 'enable_items', array_column($rows, 'itemid'), [], implode("\n", $lines), $capped, $cap);
    }

    private static function executePreviewBulkAddHostTag(array $params, ZabbixApiClient $api, array $context): string {
        $group = trim((string) ($params['host_group'] ?? ''));
        $tag = trim((string) ($params['tag'] ?? ''));
        $value = (string) ($params['value'] ?? '');
        if ($group === '' || $tag === '') {
            return 'Error: host_group and tag are required.';
        }

        $cap = self::bulkCap($context, 'bulk_max_hosts', 25);
        $rows = $api->findHostsInGroup($group, $cap + 1);
        if (!$rows) {
            return 'No hosts found in group "'.$group.'".';
        }
        $capped = count($rows) > $cap;
        if ($capped) {
            $rows = array_slice($rows, 0, $cap);
        }

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- '.$r['host'];
        }

        $summary = 'tag '.$tag.($value !== '' ? '='.$value : '');
        return self::storeBulkPreview($context, 'add_host_tag', array_column($rows, 'hostid'), ['tag' => $tag, 'value' => $value], 'Will add '.$summary.' to:'."\n".implode("\n", $lines), $capped, $cap);
    }

    private static function executePreviewLinkTemplate(array $params, ZabbixApiClient $api, array $context, bool $is_unlink): string {
        $template = trim((string) ($params['template'] ?? ''));
        $group = trim((string) ($params['host_group'] ?? ''));
        $clear = $is_unlink && Util::truthy($params['clear'] ?? false);
        if ($template === '' || $group === '') {
            return 'Error: template and host_group are required.';
        }

        $tid = $api->getTemplateIdByName($template);
        if ($tid === null) {
            return 'Error: template "'.$template.'" not found.';
        }

        $cap = self::bulkCap($context, 'bulk_max_hosts', 25);
        $rows = $api->findHostsInGroup($group, $cap + 1);
        if (!$rows) {
            return 'No hosts found in group "'.$group.'".';
        }
        $capped = count($rows) > $cap;
        if ($capped) {
            $rows = array_slice($rows, 0, $cap);
        }

        $lines = [];
        foreach ($rows as $r) {
            $lines[] = '- '.$r['host'];
        }

        $op = $is_unlink ? 'unlink_template' : 'link_template';
        $verb = $is_unlink
            ? 'Will UNLINK template "'.$template.'"'.($clear ? ' AND CLEAR its items/triggers' : '').' from:'
            : 'Will LINK template "'.$template.'" to:';

        return self::storeBulkPreview(
            $context,
            $op,
            array_column($rows, 'hostid'),
            ['template' => $template, 'templateid' => (string) $tid, 'clear' => $clear],
            $verb."\n".implode("\n", $lines),
            $capped,
            $cap
        );
    }

    /**
     * Map a bulk operation to the concrete write category it really exercises,
     * so apply_bulk_action can be re-checked against the same per-category gates
     * the equivalent single-host tools enforce. Returns '' for an unknown op.
     */
    private static function bulkOperationCategory(string $op): string {
        static $map = [
            'disable_triggers' => 'triggers',
            'disable_items' => 'items',
            'enable_items' => 'items',
            'add_host_tag' => 'hosts',
            'link_template' => 'templates',
            'unlink_template' => 'templates'
        ];

        return $map[$op] ?? '';
    }

    /**
     * Enforce the underlying operation's real write category + Super-Admin gate
     * before a bulk apply executes. Returns an error string to abort, or '' when
     * authorized. Mirrors the checks in ChatExecute so the coarse 'bulk'
     * permission cannot be used to bypass them.
     */
    private static function authorizeBulkOperation(string $op, array $context): string {
        $category = self::bulkOperationCategory($op);
        if ($category === '') {
            return 'Error: unknown bulk operation "'.$op.'".';
        }

        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $actions_config = is_array($config['zabbix_actions'] ?? null) ? $config['zabbix_actions'] : [];

        if (($actions_config['mode'] ?? 'read') !== 'readwrite') {
            return 'Error: write access is not enabled, so the "'.$category.'" bulk operation cannot run.';
        }

        $write_permissions = is_array($actions_config['write_permissions'] ?? null)
            ? $actions_config['write_permissions']
            : [];
        if (empty($write_permissions[$category])) {
            return 'Error: this bulk operation requires the "'.$category.'" write permission, which is not enabled.';
        }

        if (Util::truthy($actions_config['require_super_admin_for_write'] ?? true)
            && empty($context['is_super_admin'])) {
            return 'Error: this bulk operation requires Super Admin privileges.';
        }

        return '';
    }

    private static function executeApplyBulkAction(array $params, ZabbixApiClient $api, array $context): string {
        $token = trim((string) ($params['preview_token'] ?? ''));
        if ($token === '') {
            return 'Error: preview_token is required — run a preview_* tool first.';
        }

        $config = is_array($context['config'] ?? null) ? $context['config'] : null;
        $session = (string) ($context['server_session'] ?? '');
        if ($config === null || $session === '') {
            return 'Error: bulk apply is not available in this context.';
        }

        try {
            $action = PendingActionStore::consume($config, $session, $token);
        }
        catch (\Throwable $e) {
            return 'Error: '.$e->getMessage();
        }

        if (($action['kind'] ?? '') !== 'bulk_preview') {
            return 'Error: that token is not a bulk preview.';
        }

        $op = (string) ($action['operation'] ?? '');
        $ids = is_array($action['ids'] ?? null) ? $action['ids'] : [];
        if (!$ids) {
            return 'Nothing to do — the preview had no targets.';
        }

        // Re-authorize against the REAL category of the underlying operation.
        // apply_bulk_action itself only carries the coarse 'bulk' permission, but
        // each operation maps to a concrete (often destructive) category — e.g.
        // unlink_template -> templates, disable_triggers -> triggers. Without this
        // a 'bulk' grant alone would silently authorize fleet-wide template,
        // trigger, item and host writes that otherwise each require their own
        // per-category permission (and Super-Admin gate).
        $authz_error = self::authorizeBulkOperation($op, $context);
        if ($authz_error !== '') {
            return $authz_error;
        }

        switch ($op) {
            case 'disable_triggers':
                $api->bulkSetTriggerStatus($ids, 1);
                return 'Disabled '.count($ids).' trigger(s).';

            case 'disable_items':
                $api->bulkSetItemStatus($ids, 1);
                return 'Disabled '.count($ids).' item(s).';

            case 'enable_items':
                $api->bulkSetItemStatus($ids, 0);
                return 'Enabled '.count($ids).' item(s).';

            case 'add_host_tag':
                $tag = (string) ($action['params']['tag'] ?? '');
                $value = (string) ($action['params']['value'] ?? '');
                $api->bulkAddTagToHosts($ids, $tag, $value);
                return 'Added tag '.$tag.($value !== '' ? '='.$value : '').' to '.count($ids).' host(s).';

            case 'link_template':
                $api->bulkLinkTemplateByHostIds($ids, (string) ($action['params']['templateid'] ?? ''));
                return 'Linked template "'.($action['params']['template'] ?? '').'" to '.count($ids).' host(s).';

            case 'unlink_template':
                $clear = !empty($action['params']['clear']);
                $api->bulkUnlinkTemplateByHostIds($ids, (string) ($action['params']['templateid'] ?? ''), $clear);
                return 'Unlinked template "'.($action['params']['template'] ?? '').'"'.($clear ? ' (and cleared its items/triggers)' : '').' from '.count($ids).' host(s).';

            default:
                return 'Error: unknown bulk operation "'.$op.'".';
        }
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

        $event_name = (string) ($bundle['event']['name'] ?? '');
        $hostname = (string) ($bundle['event']['hostname'] ?? '');

        $lines = [];
        $lines[] = 'Evidence bundle generated for event **'.$eventid.'**'
            .($event_name !== '' ? ' — '.$event_name : '')
            .($hostname !== '' ? ' on `'.$hostname.'`' : '').'.';
        $lines[] = '';
        $lines[] = ReportStore::downloadMarker($result);
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

    // ── SLA tooling executors ──────────────────────────────────────

    private static function executeAnalyzeSlaScope(array $params, ZabbixApiClient $api): string {
        $hostnames = (array) ($params['hostnames'] ?? []);
        $group_name = (string) ($params['group_name'] ?? '');
        $keyword = (string) ($params['keyword'] ?? '');

        if (!$hostnames && trim($group_name) === '') {
            return 'Error: provide hostnames or group_name to analyze.';
        }

        $data = $api->analyzeSlaScope($hostnames, $group_name, $keyword);
        $hosts = $data['hosts'] ?? [];

        if (!$hosts) {
            return 'No hosts resolved for SLA scope analysis. Check the host/group names.';
        }

        $tally = [];
        $record = static function (array $tags) use (&$tally): void {
            foreach ($tags as $t) {
                $name = (string) ($t['tag'] ?? '');
                if ($name === '') {
                    continue;
                }
                $k = $name.'='.(string) ($t['value'] ?? '');
                $tally[$k] = ($tally[$k] ?? 0) + 1;
            }
        };
        $fmt = static function (array $tags): string {
            $s = [];
            foreach ($tags as $t) {
                $s[] = ($t['tag'] ?? '').'='.($t['value'] ?? '');
            }
            return implode(', ', $s);
        };

        $lines = ['SLA scope analysis'.($keyword !== '' ? ' for "'.$keyword.'"' : '').':', ''];
        $lines[] = 'Hosts in scope: '.count($hosts);
        foreach ($hosts as $h) {
            $lines[] = '- '.$h['name'].' ('.$h['host'].')';
            $record($h['host_tags'] ?? []);
            if (!empty($h['host_tags'])) {
                $lines[] = '    host tags: '.$fmt($h['host_tags']);
            }
            foreach (($h['templates'] ?? []) as $tpl) {
                $record($tpl['tags'] ?? []);
                if (!empty($tpl['tags'])) {
                    $lines[] = '    template "'.$tpl['name'].'" tags: '.$fmt($tpl['tags']);
                }
            }
        }

        $triggers = $data['triggers'] ?? [];
        $availability = [];   // triggers meaning "the service/host is DOWN/unavailable"
        $down_kw = ['unavailable', 'is down', ' down', 'not running', 'not available', 'is not available', 'stopped', 'failed to', 'cannot connect', 'connection failed', 'no data', 'unreachable', 'offline'];
        if ($triggers) {
            // Classify and tally EVERY fetched trigger first — the listing is
            // capped below, but an analysis computed only over the displayed
            // slice would misreport tag uniqueness.
            $classified = [];
            foreach ($triggers as $t) {
                $record($t['tags'] ?? []);
                $scope = '';
                foreach (($t['tags'] ?? []) as $tt) {
                    if (strtolower((string) ($tt['tag'] ?? '')) === 'scope') {
                        $scope = strtolower((string) ($tt['value'] ?? ''));
                    }
                }
                $name_l = strtolower((string) $t['description']);
                $is_avail = ($scope === 'availability');
                if (!$is_avail) {
                    foreach ($down_kw as $kw) {
                        if (strpos($name_l, $kw) !== false) {
                            $is_avail = true;
                            break;
                        }
                    }
                }
                if ($is_avail) {
                    $availability[] = $t;
                }
                $classified[] = [$t, $is_avail];
            }

            $lines[] = '';
            $lines[] = 'Triggers'.($keyword !== '' ? ' matching "'.$keyword.'"' : '').' ('.count($triggers).'):';
            foreach (array_slice($classified, 0, 60) as [$t, $is_avail]) {
                $tagstr = !empty($t['tags']) ? '  tags: '.$fmt($t['tags']) : '  (no tags)';
                $lines[] = '- [ID '.$t['triggerid'].'] '.($is_avail ? '[AVAILABILITY] ' : '').$t['host'].': '.$t['description'].$tagstr;
            }
            if (count($triggers) > 60) {
                $lines[] = '… and '.(count($triggers) - 60).' more (still included in the availability list and tag tally below).';
            }

            if ($availability) {
                $lines[] = '';
                $lines[] = 'Availability signals (these mean the service/host is DOWN — an AVAILABILITY SLA should be scoped to THESE, never to performance/notice triggers):';
                foreach (array_slice($availability, 0, 40) as $t) {
                    $tagstr = !empty($t['tags']) ? '  tags: '.$fmt($t['tags']) : '  (no tags)';
                    $lines[] = '- [ID '.$t['triggerid'].'] '.$t['host'].': '.$t['description'].$tagstr;
                }
                if (count($availability) > 40) {
                    $lines[] = '… and '.(count($availability) - 40).' more availability triggers.';
                }
            }
        }
        if (!empty($data['triggers_truncated'])) {
            $lines[] = '';
            $lines[] = 'WARNING: more matching triggers exist than the 200 analyzed — the tag tally and availability list are INCOMPLETE. Narrow the analysis with a keyword or fewer hosts before trusting tag uniqueness.';
        }

        // Problems inherit item tags too, so an item tag is a valid
        // (sometimes the only) unique discriminator for problem_tags.
        $items = $data['items'] ?? [];
        if ($items) {
            $item_examples = [];
            foreach ($items as $it) {
                $record($it['tags'] ?? []);
                foreach (($it['tags'] ?? []) as $tt) {
                    $k = ($tt['tag'] ?? '').'='.($tt['value'] ?? '');
                    if (!isset($item_examples[$k])) {
                        $item_examples[$k] = $it['host'].': '.$it['name'];
                    }
                }
            }
            if ($item_examples) {
                $lines[] = '';
                $lines[] = 'Item-level tags'.($keyword !== '' ? ' on items matching "'.$keyword.'"' : '').' (problems inherit these too; tag=value — example item):';
                $shown = 0;
                foreach ($item_examples as $k => $ex) {
                    if (++$shown > 30) {
                        $lines[] = '… and '.(count($item_examples) - 30).' more distinct item tags (all counted in the tally).';
                        break;
                    }
                    $lines[] = '  '.$k.' — '.$ex;
                }
            }
        }
        if (!empty($data['items_truncated'])) {
            $lines[] = 'WARNING: more items exist than the 500 fetched — item-tag coverage is incomplete. Narrow with a keyword.';
        }

        $lines[] = '';
        $lines[] = 'Distinct tags observed across host/template/trigger/item level (tag=value : occurrences):';
        arsort($tally);
        $tally_shown = 0;
        foreach ($tally as $k => $c) {
            // Per-instance item tags (filesystem=/…, interface=…) can produce
            // thousands of distinct pairs across a host group; an unbounded
            // listing would blow the model context.
            if (++$tally_shown > 80) {
                $lines[] = '  … and '.(count($tally) - 80).' more distinct tag=value pairs — narrow the analysis with a keyword to see them.';
                break;
            }
            $lines[] = '  '.$k.' : '.$c;
        }

        $lines[] = '';
        $lines[] = 'Scope guidance:';
        $lines[] = '- FIRST decide what is being measured: (A) HOST availability = the server itself is up/reachable (ICMP ping, Zabbix agent availability); or (B) a SPECIFIC SERVICE/application on the host (e.g. the MSSQL database engine) = only that service being down should count.';
        $lines[] = '- A host-level tag is inherited by EVERY problem on the host (CPU, disk, network, every app), so it measures "any problem on the host", not one service. Using it for a service SLA makes the SLA lie (a CPU spike would count as the service being down, and the service being down may be diluted). Use a host-level tag ONLY for a genuine host-availability SLA.';
        $lines[] = '- For a SERVICE SLA, scope to that service\'s AVAILABILITY trigger(s) listed above — the "service is unavailable / down / not running" ones (usually tagged scope=availability) — and EXCLUDE performance/notice triggers (you do not want "buffer cache efficiency low" to count as downtime). Add a unique tag to those specific trigger(s) with add_trigger_tag (e.g. sla_target=mssql-db01-prod), then set the service problem_tags to that unique tag, so ONLY the service-down condition affects the SLA.';
        $lines[] = '- Make the matcher unique to this target. A tag like scope=availability is shared across hosts/services, so combine it with a service+host identifier or add a dedicated unique tag. Prefer existing tags; add a new one only when none isolates the target.';

        return implode("\n", $lines);
    }

    /**
     * True when an optional boolean-ish tool parameter is set truthy.
     */
    private static function truthyParam(array $params, string $name): bool {
        return Util::truthy($params[$name] ?? false);
    }

    /**
     * The reason (if any) a raw match-tag list carries an operator that
     * normalizeMatchTags would silently coerce to 0 (equals). The SLA create
     * paths must reject such input instead: a "contains" intent expressed as
     * the wrong literal would otherwise become a stricter equals match that
     * maps zero problems, and the SLA would silently report 100%.
     */
    private static function matchTagOperatorIssue(array $tags): ?string {
        foreach ($tags as $t) {
            if (!is_array($t) || !array_key_exists('operator', $t)
                    || $t['operator'] === null || $t['operator'] === '') {
                continue;   // missing operator defaults to 0 (equals)
            }
            $op = $t['operator'];
            $valid = (is_int($op) && in_array($op, [0, 2], true))
                || (is_string($op) && in_array(trim($op), ['0', '2'], true));
            if (!$valid) {
                return 'operator must be 0 (equals) or 2 (contains); got '.json_encode($op)
                    .' on tag "'.(is_scalar($t['tag'] ?? '') ? (string) ($t['tag'] ?? '') : '?').'"';
            }
        }

        return null;
    }

    /**
     * The reason (if any) create_sla would reject these normalised SLA
     * service_tags regardless of how many services they match. Shared with
     * the get_services verdict so the preview never certifies tags that
     * create_sla will refuse.
     */
    private static function slaTagShapeIssue(array $matchers): ?string {
        if (count($matchers) > 1) {
            return 'create_sla will reject MULTIPLE service_tags (they are OR-combined, never AND) — pass exactly one unique sla_scope tag';
        }
        foreach ($matchers as $t) {
            if ((int) $t['operator'] === 2) {
                return 'create_sla will reject operator 2 (contains) — use operator 0 (equals) with the exact sla_scope value';
            }
            if (self::isBroadTagName($t['tag'])) {
                return '"'.$t['tag'].'" is a broad shared tag name that create_sla will reject — select on the service\'s unique sla_scope tag instead (see its service tags above)';
            }
        }
        return null;
    }

    /**
     * One display line per matched service: name, ID and its service tags.
     */
    private static function formatServiceRefs(array $services, int $max = 15): string {
        $refs = [];
        foreach (array_slice($services, 0, $max) as $s) {
            $tags = [];
            foreach ((array) ($s['tags'] ?? []) as $t) {
                $tags[] = ($t['tag'] ?? '').'='.($t['value'] ?? '');
            }
            $refs[] = '"'.($s['name'] ?? '').'" (ID '.($s['serviceid'] ?? '?').($tags ? '; tags: '.implode(', ', $tags) : '').')';
        }
        if (count($services) > $max) {
            $refs[] = '… and '.(count($services) - $max).' more';
        }
        return implode('; ', $refs);
    }

    private static function executeGetServices(array $params, ZabbixApiClient $api): string {
        $service_tags = (array) ($params['service_tags'] ?? []);
        $keyword = trim((string) ($params['keyword'] ?? ''));

        if (!$service_tags && $keyword === '') {
            return 'Error: provide service_tags (to preview which services an SLA would match) and/or keyword (to search services by name).';
        }

        // Reject entries the normaliser would silently drop or coerce —
        // otherwise a malformed matcher degrades to an unfiltered listing (or
        // a stricter equals match) that is then mislabelled as the SLA's
        // match set.
        $op_issue = self::matchTagOperatorIssue($service_tags);
        if ($op_issue !== null) {
            return 'Error: '.$op_issue.'.';
        }
        $matchers = $api->normalizeMatchTags($service_tags);
        if ($service_tags && count($matchers) !== count($service_tags)) {
            return 'Error: every service_tags entry must be an object like {"tag":"sla_scope","operator":0,"value":"filezilla.prod"} (operator 0=equals, 2=contains).';
        }

        // Tag matching is evaluated WITHOUT the keyword: an SLA selects on
        // tags alone, so the verdict below must reflect the full tag scope.
        // The keyword only narrows what is displayed.
        try {
            $services = $matchers
                ? $api->getServicesDetailed($matchers)
                : $api->getServicesDetailed([], $keyword);
        }
        catch (\Throwable $e) {
            return 'Error: service.get failed: '.$e->getMessage();
        }

        if (!$services) {
            return $matchers
                ? 'No services match these service_tags — an SLA with them would measure NOTHING. Create the target service first with create_sla_service, or search existing services with get_services keyword.'
                : 'No services found matching "'.$keyword.'".';
        }

        $display = $services;
        $hidden = 0;
        $keyword_hid_all = false;
        if ($matchers && $keyword !== '') {
            $display = [];
            foreach ($services as $s) {
                if (stripos((string) ($s['name'] ?? ''), $keyword) !== false) {
                    $display[] = $s;
                }
            }
            $hidden = count($services) - count($display);
            if (!$display) {
                // The keyword hid every tag match — show them all instead of
                // an empty list; they ARE what an SLA on these tags measures.
                $display = $services;
                $hidden = 0;
                $keyword_hid_all = true;
            }
        }

        $lines = [];
        if ($matchers) {
            $lines[] = count($services).' service(s) would be matched by an SLA with these service_tags (OR logic):';
            if ($hidden > 0) {
                $lines[] = '(Showing the '.count($display).' whose name contains "'.$keyword.'" — the other '.$hidden.' still match the tags and WOULD be measured by the SLA.)';
            }
            elseif ($keyword_hid_all) {
                $lines[] = '(No tag-matched service name contains "'.$keyword.'" — showing all tag matches, since these are what an SLA on the tags would measure.)';
            }
        }
        else {
            $lines[] = count($services).' service(s) matching "'.$keyword.'":';
        }
        $lines[] = '';

        foreach (array_slice($display, 0, 50) as $s) {
            $algo = (int) ($s['algorithm'] ?? 1);
            $warn = $algo === 0 ? '  [WARNING: status rule "set to OK" — ignores problems, unusable as an SLA target]' : '';
            $lines[] = '- '.($s['name'] ?? '').' (ID '.($s['serviceid'] ?? '?').')'.$warn;

            $st = [];
            foreach ((array) ($s['tags'] ?? []) as $t) {
                $st[] = ($t['tag'] ?? '').'='.($t['value'] ?? '');
            }
            $lines[] = '    service tags (SLA selects on these): '.($st ? implode(', ', $st) : '(none — no SLA can select this service)');

            $pt = [];
            foreach ((array) ($s['problem_tags'] ?? []) as $t) {
                $op = (int) ($t['operator'] ?? 0) === 2 ? ' contains ' : '=';
                $pt[] = ($t['tag'] ?? '').$op.($t['value'] ?? '');
            }
            $lines[] = '    problem tags (AND): '.($pt ? implode('  AND  ', $pt) : '(none — status comes from children)');

            $parents = [];
            foreach ((array) ($s['parents'] ?? []) as $p) {
                $parents[] = ($p['name'] ?? '').' (ID '.($p['serviceid'] ?? '?').')';
            }
            if ($parents) {
                $lines[] = '    parent(s): '.implode(', ', $parents);
            }

            $children = [];
            foreach ((array) ($s['children'] ?? []) as $c) {
                $children[] = ($c['name'] ?? '').' (ID '.($c['serviceid'] ?? '?').')';
            }
            if ($children) {
                $lines[] = '    children: '.implode(', ', $children);
            }
        }
        if (count($display) > 50) {
            $lines[] = '… and '.(count($display) - 50).' more.';
        }

        if ($matchers) {
            $lines[] = '';
            $shape_issue = self::slaTagShapeIssue($matchers);
            // create_sla also refuses matched algorithm-0 services; the
            // verdict must never certify a scope it would reject.
            $algo0 = [];
            foreach ($services as $s) {
                if ((int) ($s['algorithm'] ?? 1) === 0) {
                    $algo0[] = '"'.($s['name'] ?? '').'" (ID '.($s['serviceid'] ?? '?').')';
                }
            }
            if (count($services) === 1) {
                if ($shape_issue !== null) {
                    $lines[] = 'Verdict: exactly ONE service matches, BUT '.$shape_issue.'.';
                }
                elseif ($algo0) {
                    $lines[] = 'Verdict: exactly ONE service matches, BUT its status rule is "set status to OK" (algorithm 0) — it never reflects problems, its SLI is pinned at 100%, and create_sla will refuse it. Change the status calculation rule to "most critical" (1 or 2) in Zabbix first.';
                }
                else {
                    $lines[] = 'Verdict: exactly ONE service matches — safe to use these service_tags in create_sla.';
                }
            }
            else {
                $lines[] = 'Verdict: MORE THAN ONE service matches these tags — an SLA with them aggregates ALL '.count($services).' (OR logic). Narrow to a unique sla_scope tag, unless the operator explicitly wants one grouped SLA over all listed services (then create_sla needs allow_multiple_matching_services=true and the confirmation must name them all).';
                if ($algo0) {
                    $lines[] = 'Note: '.implode(', ', $algo0).' use "set status to OK" (algorithm 0) — their SLI would permanently report 100%, and create_sla refuses them without allow_multiple_matching_services=true.';
                }
            }
        }

        return implode("\n", $lines);
    }

    private static function executeCreateSlaService(array $params, ZabbixApiClient $api): string {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return 'Error: name is required.';
        }
        // The API truncates to 128 chars on create; a longer name here would
        // dodge the duplicate check and report a name that never gets stored.
        if (Util::truncate($name, 128) !== $name) {
            return 'Error: service name must be at most 128 characters.';
        }

        $problem_tags = (array) ($params['problem_tags'] ?? []);

        $child_ids = [];
        foreach ((array) ($params['child_serviceids'] ?? []) as $cid) {
            if (!is_string($cid) && !is_int($cid)) {
                return 'Error: child_serviceids must be an array of numeric service IDs (use the IDs returned by create_sla_service or get_services).';
            }
            $cid = trim((string) $cid);
            if ($cid === '') {
                continue;
            }
            if (!preg_match('/^\d+$/', $cid)) {
                return 'Error: child_serviceids must contain numeric service IDs, got "'.$cid.'". Use the IDs returned by create_sla_service or get_services.';
            }
            $child_ids[] = $cid;
        }
        // Zabbix rejects a repeated child serviceid outright; duplicates
        // never change the aggregation.
        $child_ids = array_values(array_unique($child_ids));

        if (!$problem_tags && !$child_ids) {
            return 'Error: a LEAF service needs problem_tags (AND-combined tags that map problems to it); a PARENT/GROUP service needs child_serviceids instead. Provide one of them.';
        }
        if ($problem_tags && $child_ids) {
            return 'Error: Zabbix does not allow a service to have BOTH problem_tags and children. Create LEAF services with problem_tags first, then the PARENT with child_serviceids only (the parent\'s status comes from its children).';
        }

        $op_issue = self::matchTagOperatorIssue($problem_tags);
        if ($op_issue !== null) {
            return 'Error: '.$op_issue.'.';
        }

        $normalized_pt = $api->normalizeMatchTags($problem_tags);
        if (count($normalized_pt) !== count($problem_tags)) {
            return 'Error: every problem_tags entry must be an object like {"tag":"service","operator":0,"value":"filezilla"} (operator 0=equals, 2=contains).';
        }

        // Drop exact-duplicate matchers: Zabbix rejects a repeated
        // (tag, operator, value) triple outright, and duplicates never
        // change AND-matching semantics.
        $dedup = [];
        foreach ($normalized_pt as $t) {
            $dedup[$t['tag'].chr(31).$t['operator'].chr(31).$t['value']] = $t;
        }
        $normalized_pt = array_values($dedup);

        // Guard: problem tags built ONLY from broad category names (e.g.
        // service=filezilla, or service=x AND env=prod) map problems from
        // EVERY host that emits them, blending dev/test/prod into one
        // service. The combination must contain something that pins the
        // target: a host-identifying EQUALS tag (host=web01 — a "contains"
        // host matcher can blend many hosts and never narrows) or a
        // dedicated unique tag. A host-ONLY combination is legitimate for a
        // host-availability SLA and gets a warning below instead.
        $has_narrowing = !$normalized_pt;
        $host_only = (bool) $normalized_pt;
        $host_eq_values = [];   // host-identifying equals matchers: name => distinct values
        foreach ($normalized_pt as $t) {
            $pt_name = strtolower($t['tag']);
            $is_host = in_array($pt_name, self::HOST_ID_TAG_NAMES, true);
            $is_category = in_array($pt_name, self::CATEGORY_TAG_NAMES, true);
            if (!$is_host && !$is_category) {
                $has_narrowing = true;   // dedicated/custom tag
            }
            if ($is_host && (int) $t['operator'] === 0) {
                $has_narrowing = true;
                $host_eq_values[$pt_name][$t['value']] = true;
            }
            if (!$is_host) {
                $host_only = false;
            }
        }

        // Two different equals values for the same host-identity tag can
        // never AND-match: a problem originates from ONE host, so the
        // service would map nothing and its SLA would sit at 100% forever.
        foreach ($host_eq_values as $hn => $vals) {
            if (count($vals) > 1) {
                return 'Error: problem_tags contain '.count($vals).' different equals values for "'.$hn.'" ("'.implode('", "', array_keys($vals)).'") — problem_tags are AND-combined and a problem originates from ONE host, so this combination matches NOTHING and the SLA would permanently report 100%. To cover several hosts, create one leaf service per host and group them under a parent (child_serviceids).';
            }
        }

        if (!$has_narrowing && !self::truthyParam($params, 'allow_broad_problem_tags')) {
            $parts = [];
            foreach ($normalized_pt as $t) {
                $parts[] = $t['tag'].((int) $t['operator'] === 2 ? ' contains ' : '=').$t['value'];
            }
            return 'Error: the problem tag(s) '.implode(' AND ', $parts).' do not pin a specific target — broad category tags (service/env/scope/…) are shared by every environment/host that emits them (dev, test AND prod), and a host tag narrows only as an EQUALS matcher (operator 0), never as "contains". '
                .'problem_tags are AND-combined, so add a host-identifying equals tag (e.g. host=prod-app-01), or tag the exact availability trigger(s) with a unique tag via add_trigger_tag and use that. '
                .'Only if the operator explicitly confirmed a broad all-hosts/all-environments scope, retry with allow_broad_problem_tags=true.';
        }

        if ($child_ids && !isset($params['algorithm'])) {
            return 'Error: a parent/group service needs an EXPLICIT algorithm choice — ask the operator which one matches the topology: '
                .'1 = "most critical if ALL children have problems" (redundant HA/failover cluster — the service stays UP while at least one node is up, so a single-node outage is NOT downtime) or '
                .'2 = "most critical of child services" (independent nodes — ANY child problem propagates to the parent and counts as downtime). '
                .'For an HA cluster, algorithm 2 would record every failover as an SLA breach.';
        }
        $algorithm = isset($params['algorithm']) ? (int) $params['algorithm'] : 1;
        if ($algorithm === 0) {
            return 'Error: algorithm 0 ("set status to OK") makes the service ignore its problems and children entirely — an SLA on it would always report 100%. Use 1 (most critical if all children have problems) or 2 (most critical of child services).';
        }
        if (!in_array($algorithm, [1, 2], true)) {
            return 'Error: algorithm must be 1 (most critical if ALL children have problems — HA/redundant cluster) or 2 (most critical of child services — any child problem counts).';
        }

        // Refuse duplicate names — reuse the existing service instead of
        // silently creating a second one with overlapping scope.
        try {
            $existing_list = $api->getServicesByExactName($name);
        }
        catch (\Throwable $e) {
            return 'Error: could not check for an existing service named "'.$name.'" (service.get failed: '.$e->getMessage().').';
        }
        if ($existing_list) {
            $existing = $existing_list[0];
            $tags = [];
            foreach ((array) ($existing['tags'] ?? []) as $t) {
                $tags[] = ($t['tag'] ?? '').'='.($t['value'] ?? '');
            }
            return 'Error: a service named "'.$name.'" already exists (ID '.($existing['serviceid'] ?? '?').($tags ? ', service tags: '.implode(', ', $tags) : ', no service tags').'). '
                .'Reuse it — reference its service tags in create_sla, or pass its serviceid as parent_service/child_serviceids — or choose a different name.';
        }

        // Resolve the parent, an EXISTING service given by numeric ID or by
        // exact name. Zabbix allows duplicate service names, so an ambiguous
        // name is refused rather than resolved arbitrarily.
        $parent_ids = [];
        $parent_label = '';
        $parent_ref = trim((string) ($params['parent_service'] ?? ''));
        if ($parent_ref !== '') {
            try {
                if (preg_match('/^\d+$/', $parent_ref)) {
                    $names = $api->getServiceNamesByIds([$parent_ref]);
                    if (isset($names[$parent_ref])) {
                        $parent_ids = [$parent_ref];
                        $parent_label = $names[$parent_ref].' (ID '.$parent_ref.')';
                    }
                }
                if (!$parent_ids) {
                    $candidates = $api->getServicesByExactName($parent_ref);
                    if (!$candidates) {
                        return 'Error: parent service "'.$parent_ref.'" not found (checked as '.(preg_match('/^\d+$/', $parent_ref) ? 'serviceid and ' : '').'exact name). Create it first (a parent needs child_serviceids, not problem_tags) or find it with get_services.';
                    }
                    if (count($candidates) > 1) {
                        return 'Error: '.count($candidates).' services are named "'.$parent_ref.'": '.self::formatServiceRefs($candidates, 5).'. Pass the serviceid of the intended parent instead of the name.';
                    }
                    $parent_ids = [(string) $candidates[0]['serviceid']];
                    $parent_label = $candidates[0]['name'].' (ID '.$candidates[0]['serviceid'].')';
                }

                // A parent that is itself a LEAF (has problem_tags) cannot
                // take children — Zabbix forbids the combination.
                $pt_check = $api->call('service.get', [
                    'output' => ['serviceid'],
                    'serviceids' => $parent_ids,
                    'selectProblemTags' => ['tag']
                ]);
                if (!empty($pt_check[0]['problem_tags'])) {
                    return 'Error: parent service '.$parent_label.' is a LEAF (it has problem_tags), and Zabbix does not allow a service to have both problem_tags and children. Pick a parent without problem_tags, or create a new grouping parent first.';
                }
            }
            catch (\Throwable $e) {
                return 'Error: could not resolve parent service "'.$parent_ref.'" (service.get failed: '.$e->getMessage().').';
            }
        }

        // Validate that every child exists before linking.
        $child_names = [];
        if ($child_ids) {
            try {
                $found = $api->getServiceNamesByIds($child_ids);
            }
            catch (\Throwable $e) {
                return 'Error: could not verify child services (service.get failed: '.$e->getMessage().').';
            }
            $missing = array_diff($child_ids, array_keys($found));
            if ($missing) {
                return 'Error: child service ID(s) not found: '.implode(', ', $missing).'. Create the child services first (create_sla_service per host), then the parent with their serviceids.';
            }
            foreach ($child_ids as $cid) {
                $child_names[] = $found[$cid].' (ID '.$cid.')';
            }
        }

        $service_tags = (array) ($params['service_tags'] ?? []);
        if (!$service_tags) {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');
            $service_tags = [['tag' => 'sla_scope', 'value' => $slug !== '' ? $slug : 'service']];
        }

        // Clean the plain service tags: trim names AND values (a whitespace-
        // padded sla_scope value would dodge the uniqueness check yet render
        // identically everywhere, and later exact-match SLA selection would
        // miss it) and drop exact duplicates, which Zabbix rejects outright.
        $clean_tags = [];
        foreach ($service_tags as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tag_name = trim((string) ($t['tag'] ?? ''));
            if ($tag_name === '') {
                continue;
            }
            $tag_value = trim((string) ($t['value'] ?? ''));
            $clean_tags[$tag_name.chr(31).$tag_value] = ['tag' => $tag_name, 'value' => $tag_value];
        }
        $service_tags = array_values($clean_tags);

        // The SLA selection handle is the sla_scope tag: exactly ONE per
        // service, and it must be unique — if another service already carries
        // the same value, a future SLA on it would OR-match both. Only
        // sla_scope entries are checked here; descriptive tags (service, env,
        // host, owner, …) are expected to be shared and are ignored.
        $scope_matchers = [];
        foreach ($service_tags as $t) {
            if (strtolower($t['tag']) !== 'sla_scope') {
                continue;
            }
            // Zabbix tag matching is case-sensitive; a case-variant handle
            // could never be selected by an sla_scope SLA and would dodge
            // the uniqueness check.
            if ($t['tag'] !== 'sla_scope') {
                return 'Error: the SLA handle tag must be named exactly "sla_scope" (lowercase), not "'.$t['tag'].'" — Zabbix tag matching is case-sensitive.';
            }
            if ($t['value'] === '') {
                return 'Error: the sla_scope tag needs a value, e.g. {"tag":"sla_scope","value":"filezilla.prod.prod-app-01"} (<application>.<environment>.<host|cluster|all>).';
            }
            $scope_matchers[] = ['tag' => 'sla_scope', 'operator' => 0, 'value' => $t['value']];
        }
        if (!$scope_matchers) {
            return 'Error: service_tags must include the unique SLA handle {"tag":"sla_scope","value":"<application>.<environment>.<host|cluster|all>"} — this is the only tag an SLA should select on. Descriptive tags (service, env, host, …) may be added alongside, but they cannot be the handle because SLA matching is OR-based and they are shared across services.';
        }
        if (count($scope_matchers) > 1) {
            return 'Error: service_tags must contain exactly ONE sla_scope entry — the single SLA selection handle for this service; got '.count($scope_matchers).'. Extra identity belongs in descriptive tags (service, env, host, …), not in additional sla_scope values.';
        }
        if (!self::truthyParam($params, 'allow_shared_service_tag')) {
            try {
                $holders = $api->getServicesDetailed($scope_matchers);
            }
            catch (\Throwable $e) {
                return 'Error: could not verify sla_scope uniqueness (service.get failed: '.$e->getMessage().'). Refusing to create a service with an unverified SLA handle.';
            }
            if ($holders) {
                return 'Error: the sla_scope value you chose is already carried by '.count($holders).' existing service(s): '.self::formatServiceRefs($holders, 10).'. '
                    .'An SLA selecting this tag would measure those too. Pick a UNIQUE sla_scope value (e.g. add the environment/host: filezilla.prod.prod-app-01), or — only if one SLA should deliberately cover this service AND the listed ones — retry with allow_shared_service_tag=true.';
            }
        }

        $sortorder = isset($params['sortorder']) ? (int) $params['sortorder'] : 0;

        try {
            $result = $api->createSlaService($name, $normalized_pt, $service_tags, $algorithm, $sortorder, $parent_ids, $child_ids);
        }
        catch (\Throwable $e) {
            return 'Error: service.create failed: '.$e->getMessage();
        }

        $out = self::formatSlaServiceResult($result, $parent_label, $child_names);
        if ($host_only) {
            $out .= "\nWARNING: these problem_tags identify a HOST, not a service — EVERY problem on that host (CPU, disk, any application) will count against this service. That is correct for a host-availability SLA; for a service SLA scope to the service's own availability trigger tags instead.";
        }

        return $out;
    }

    private static function formatSlaServiceResult(array $r, string $parent_label = '', array $child_names = []): string {
        $algo = (int) ($r['algorithm'] ?? 1);
        $algo_label = $algo === 0
            ? 'Set status to OK'
            : ($algo === 2 ? 'Most critical of child services' : 'Most critical if all children have problems');

        $lines = [$child_names ? 'SLA parent/group service created.' : 'SLA service created.'];
        $lines[] = 'Service ID: '.($r['serviceid'] ?? '?');
        $lines[] = 'Name: '.($r['name'] ?? '');
        $lines[] = 'Status rule: '.$algo_label;

        $pt = [];
        foreach (($r['problem_tags'] ?? []) as $t) {
            $op = (int) ($t['operator'] ?? 0) === 2 ? 'contains' : '=';
            $pt[] = ($t['tag'] ?? '').' '.$op.' '.($t['value'] ?? '');
        }
        $lines[] = 'Problem tags (ALL must match → AND): '.($pt ? implode('  AND  ', $pt) : '(none — status comes from the child services)');

        $st = [];
        foreach (($r['service_tags'] ?? []) as $t) {
            $st[] = ($t['tag'] ?? '').'='.($t['value'] ?? '');
        }
        $lines[] = 'Service tags (an SLA selects on these): '.($st ? implode(', ', $st) : '(none)');

        if ($parent_label !== '') {
            $lines[] = 'Attached under parent: '.$parent_label;
        }
        if ($child_names) {
            $lines[] = 'Children: '.implode(', ', $child_names);
        }

        $lines[] = 'Next: verify the scope with get_services using the service tag above, then create_sla selecting ONLY that tag.';

        return implode("\n", $lines);
    }

    private static function executeCreateSla(array $params, ZabbixApiClient $api): string {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return 'Error: name is required.';
        }
        // sla.create stores at most 255 chars; a longer name would be
        // silently mangled and then reported back as a name that does not
        // exist in Zabbix.
        if (Util::truncate($name, 255) !== $name) {
            return 'Error: SLA name must be at most 255 characters.';
        }

        if (!array_key_exists('slo', $params) || !is_numeric($params['slo'])) {
            return 'Error: slo (target % 0-100) is required.';
        }
        $slo = (float) $params['slo'];
        if ($slo < 0 || $slo > 100) {
            return 'Error: slo must be between 0 and 100.';
        }

        $period = self::parseSlaPeriod($params['period'] ?? null);
        if ($period === null) {
            return 'Error: period must be one of daily, weekly, monthly, quarterly, annually (or 0-4).';
        }

        $service_tags = (array) ($params['service_tags'] ?? []);
        if (!$service_tags) {
            return 'Error: service_tags is required — the SLA selects services by these tags. Use the unique sla_scope tag of the service you created.';
        }

        $op_issue = self::matchTagOperatorIssue($service_tags);
        if ($op_issue !== null) {
            return 'Error: '.$op_issue.'.';
        }

        $tags = $api->normalizeMatchTags($service_tags);
        if (count($tags) !== count($service_tags)) {
            return 'Error: every service_tags entry must be an object like {"tag":"sla_scope","operator":0,"value":"filezilla.prod"} (operator 0=equals, 2=contains).';
        }

        // Exact-duplicate matchers collapse to one — they must not trip the
        // multiple-tags guard, whose OR warning would mislead here.
        $dedup = [];
        foreach ($tags as $t) {
            $dedup[$t['tag'].chr(31).$t['operator'].chr(31).$t['value']] = $t;
        }
        $tags = array_values($dedup);

        $allow_broad = self::truthyParam($params, 'allow_multiple_matching_services');

        // Guard 1 — SLA service_tags are OR-combined, never AND. Multiple
        // tags almost always means the AI expected AND scoping.
        if (count($tags) > 1 && !$allow_broad) {
            $parts = [];
            foreach ($tags as $t) {
                $parts[] = $t['tag'].'='.$t['value'];
            }
            return 'Error: multiple SLA service_tags are matched with OR logic — '.implode(' OR ', $parts).' would measure EVERY service carrying ANY of these tags; Zabbix never combines SLA service_tags with AND. '
                .'To scope this SLA to one target, pass exactly ONE unique sla_scope tag (AND-combinations belong in the service\'s problem_tags, set via create_sla_service). '
                .'If the operator explicitly asked for the OR union, retry with allow_multiple_matching_services=true and list every matched service in the confirmation.';
        }

        // Guard 2 — "contains" matching silently includes future services.
        if (!$allow_broad) {
            foreach ($tags as $t) {
                if ((int) $t['operator'] === 2) {
                    return 'Error: operator 2 (contains) on '.$t['tag'].' ~ "'.$t['value'].'" is a broad match that can silently include services created later. Use operator 0 (equals) with the exact unique sla_scope value, or retry with allow_multiple_matching_services=true if the operator explicitly confirmed a broad SLA.';
                }
            }
        }

        // Guard 3 — broad shared tag names cannot act as an SLA handle even
        // when they happen to match only one service today.
        if (!$allow_broad) {
            foreach ($tags as $t) {
                if (self::isBroadTagName($t['tag'])) {
                    return 'Error: "'.$t['tag'].'" is a broad shared tag name — services in other environments (dev/test/prod) typically carry it too, and any future service with it silently joins this SLA. Select on a dedicated unique tag instead, e.g. sla_scope=<application>.<environment>.<host> (put it on the target service with create_sla_service). Retry with allow_multiple_matching_services=true only if the operator explicitly confirmed a broad SLA.';
                }
            }
        }

        // Guard 4 — resolve EXACTLY which services this SLA will measure.
        try {
            $matched = $api->getServicesDetailed($tags);
        }
        catch (\Throwable $e) {
            return 'Error: could not resolve which services these service_tags match (service.get failed: '.$e->getMessage().'). Refusing to create an SLA with an unverified scope.';
        }

        if (!$matched) {
            return 'Error: these service_tags match ZERO services — the SLA would measure nothing. Create the target service first with create_sla_service (give it this exact tag in its service_tags), then retry. Use get_services to inspect existing services and their tags.';
        }

        if (count($matched) > 1 && !$allow_broad) {
            return 'Error: these service_tags match '.count($matched).' services: '.self::formatServiceRefs($matched).'. '
                .'The SLA would aggregate ALL of them (OR matching). Use a unique sla_scope tag that matches only the intended service, or — if the operator explicitly confirmed measuring all listed services as one SLA — retry with allow_multiple_matching_services=true and name each of them in the confirmation.';
        }

        // A matched service whose status rule is "set status to OK" never
        // reflects problems — its SLI is pinned at 100%.
        $algo0 = [];
        foreach ($matched as $s) {
            if ((int) ($s['algorithm'] ?? 1) === 0) {
                $algo0[] = '"'.($s['name'] ?? '').'" (ID '.($s['serviceid'] ?? '?').')';
            }
        }
        if ($algo0 && !$allow_broad) {
            return 'Error: matched service(s) '.implode(', ', $algo0).' use status rule "set status to OK" (algorithm 0), so they never go down and their SLI would permanently report 100%. Change the status calculation rule to "most critical" (1 or 2) in Zabbix first, then retry. For a deliberately broad SLA where this is acceptable, retry with allow_multiple_matching_services=true.';
        }

        $timezone = trim((string) ($params['timezone'] ?? ''));
        if ($timezone !== '' && !in_array($timezone, timezone_identifiers_list(), true)) {
            return 'Error: timezone "'.$timezone.'" is not a valid IANA/PHP timezone identifier (e.g. Europe/Stockholm, UTC). Omit it to use the server default.';
        }
        $status = isset($params['status']) ? (int) $params['status'] : 1;
        $description = (string) ($params['description'] ?? '');

        $effective_date = self::parseSlaDate($params['effective_date'] ?? null);
        if ($effective_date === false) {
            $raw = $params['effective_date'] ?? '';
            return 'Error: effective_date must be a calendar date formatted YYYY-MM-DD (e.g. 2026-07-12); got "'.(is_scalar($raw) ? trim((string) $raw) : gettype($raw)).'". Refusing to guess — a mis-parsed date would pull the wrong reporting periods into the SLI.';
        }
        if ($effective_date === null) {
            // Zabbix stores sla.effective_date as a date timestamp in UTC.
            $effective_date = (new \DateTime('today', new \DateTimeZone('UTC')))->getTimestamp();
        }

        try {
            $result = $api->createSla($name, $slo, $period, $tags, $timezone, $effective_date, $status, $description);
        }
        catch (\Throwable $e) {
            return 'Error: sla.create failed: '.$e->getMessage();
        }

        $out = self::formatSlaResult($result, $matched);
        if ($algo0) {
            $out .= "\nWARNING: ".implode(', ', $algo0).' use "set status to OK" — their SLI will always show 100%.';
        }
        return $out;
    }

    private static function parseSlaPeriod($val): ?int {
        if (is_int($val) || (is_string($val) && preg_match('/^\d+$/', $val))) {
            $n = (int) $val;
            return in_array($n, [0, 1, 2, 3, 4], true) ? $n : null;
        }
        $map = [
            'daily' => 0, 'day' => 0,
            'weekly' => 1, 'week' => 1,
            'monthly' => 2, 'month' => 2,
            'quarterly' => 3, 'quarter' => 3,
            'annually' => 4, 'annual' => 4, 'yearly' => 4, 'year' => 4
        ];
        $s = strtolower(trim((string) $val));
        return $map[$s] ?? null;
    }

    /**
     * Strict effective-date parsing: YYYY-MM-DD only, interpreted as
     * midnight UTC (Zabbix stores sla.effective_date as a date timestamp in
     * UTC and its reporting-period alignment assumes that). Returns the
     * timestamp, null when no date was provided (caller defaults to today),
     * or false for anything unparseable — silently substituting "today" (or
     * reading a compact numeric date as a raw epoch) would start the SLA
     * with the wrong reporting history.
     */
    private static function parseSlaDate($val): int|false|null {
        if ($val === null || $val === '' || $val === []) {
            return null;
        }
        if (!is_scalar($val)) {
            return false;
        }
        $s = trim((string) $val);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
                || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }
        $dt = \DateTime::createFromFormat('!Y-m-d', $s, new \DateTimeZone('UTC'));

        return $dt !== false ? $dt->getTimestamp() : false;
    }

    private static function formatSlaResult(array $r, array $matched_services = []): string {
        $period_labels = [0 => 'daily', 1 => 'weekly', 2 => 'monthly', 3 => 'quarterly', 4 => 'annually'];

        $lines = ['SLA created.'];
        $lines[] = 'SLA ID: '.($r['slaid'] ?? '?');
        $lines[] = 'Name: '.($r['name'] ?? '');
        $lines[] = 'Objective (SLO): '.($r['slo'] ?? '?').'%';
        $lines[] = 'Reporting period: '.($period_labels[(int) ($r['period'] ?? 2)] ?? '?');
        $lines[] = 'Timezone: '.($r['timezone'] ?? 'UTC');
        $lines[] = 'Status: '.(((int) ($r['status'] ?? 1)) === 1 ? 'enabled' : 'disabled');
        if (!empty($r['effective_date'])) {
            $lines[] = 'Effective from: '.date('Y-m-d', (int) $r['effective_date']);
        }

        $st = [];
        foreach (($r['service_tags'] ?? []) as $t) {
            $op = (int) ($t['operator'] ?? 0) === 2 ? 'contains' : '=';
            $st[] = ($t['tag'] ?? '').' '.$op.' '.($t['value'] ?? '');
        }
        $lines[] = 'Selects services where ANY matches (OR): '.($st ? implode('  OR  ', $st) : '(none)');

        if ($matched_services) {
            $lines[] = 'This SLA now measures '.count($matched_services).' service(s):';
            foreach (array_slice($matched_services, 0, 15) as $s) {
                $lines[] = '- '.($s['name'] ?? '').' (ID '.($s['serviceid'] ?? '?').')';
            }
            if (count($matched_services) > 15) {
                $lines[] = '… and '.(count($matched_services) - 15).' more.';
            }
        }

        return implode("\n", $lines);
    }

    private static function executeAddTemplateTag(array $params, ZabbixApiClient $api): string {
        $template = trim((string) ($params['template'] ?? ''));
        $tag = trim((string) ($params['tag'] ?? ''));
        $value = (string) ($params['value'] ?? '');

        if ($template === '') {
            return 'Error: template (name) is required.';
        }
        if ($tag === '') {
            return 'Error: tag (name) is required.';
        }

        $r = $api->addTemplateTag($template, $tag, $value);
        $verb = !empty($r['added']) ? 'added to' : 'already present on';

        return 'Tag '.($r['tag'] ?? '').'='.($r['value'] ?? '').' '.$verb.' template "'.($r['template'] ?? '').'" (ID '.($r['templateid'] ?? '?').'). '
            .'Template now has '.($r['total_tags'] ?? '?').' tag(s). New problems on hosts linked to this template will carry this tag.';
    }

    private static function executeAddTriggerTag(array $params, ZabbixApiClient $api): string {
        $trigger_id = trim((string) ($params['trigger_id'] ?? ''));
        $tag = trim((string) ($params['tag'] ?? ''));
        $value = (string) ($params['value'] ?? '');

        if ($trigger_id === '') {
            return 'Error: trigger_id is required.';
        }
        if ($tag === '') {
            return 'Error: tag (name) is required.';
        }

        $r = $api->addTriggerTag($trigger_id, $tag, $value);
        $verb = !empty($r['added']) ? 'added to' : 'already present on';

        return 'Tag '.($r['tag'] ?? '').'='.($r['value'] ?? '').' '.$verb.' trigger '.($r['triggerid'] ?? '?').' ("'.($r['trigger'] ?? '').'"). '
            .'Trigger now has '.($r['total_tags'] ?? '?').' tag(s). New problems from this trigger will carry this tag.';
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

        $action = (int) ($params['action'] ?? 2);
        $message = trim((string) ($params['message'] ?? ''));

        // Only close (1), acknowledge (2) and add-message (4) are safe here.
        // Severity, suppression, symptom/cause and un-acknowledge each require
        // extra parameters and have dedicated tools.
        $allowed = 1 | 2 | 4;
        if ($action <= 0 || ($action & ~$allowed) !== 0) {
            return 'Error: acknowledge_problem only supports close (1), acknowledge (2) and add-message (4). Use change_problem_severity for severity, suppress_problem for suppression, unacknowledge_problem to un-acknowledge, add_problem_message for a plain comment, and mark_problem_as_cause / mark_problem_as_symptom for ranking.';
        }
        if (($action & 4) !== 0 && $message === '') {
            return 'Error: a message is required when the action includes add-message (bit 4).';
        }

        $api->acknowledgeProblem($eventid, $action, $message);

        $actions_taken = [];
        if ($action & 1) $actions_taken[] = 'closed';
        if ($action & 2) $actions_taken[] = 'acknowledged';
        if ($action & 4) $actions_taken[] = 'message added';

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
