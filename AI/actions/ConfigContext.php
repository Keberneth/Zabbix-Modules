<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

/**
 * Provides context for AI-assisted item/trigger/discovery creation and editing.
 *
 * GET ?action=ai.config.context&hostid={hostid}&form_type={item|trigger|discovery|item_prototype|trigger_prototype}
 *     [&parent_discoveryid={id}]
 *     [&itemid={id}]        — fetch full details + preprocessing for a specific item
 *     [&triggerid={id}]      — fetch full details for a specific trigger
 *     [&discoveryid={id}]    — fetch full details for a specific discovery rule
 */
class ConfigContext extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $hostid = Util::cleanString($_GET['hostid'] ?? $_POST['hostid'] ?? '', 128);
            $form_type = Util::cleanString($_GET['form_type'] ?? $_POST['form_type'] ?? 'item', 64);

            $config = Config::get();
            $client = ZabbixApiClient::fromConfig($config);

            $itemid = Util::cleanString($_GET['itemid'] ?? $_POST['itemid'] ?? '', 128);
            $triggerid = Util::cleanString($_GET['triggerid'] ?? $_POST['triggerid'] ?? '', 128);
            $discoveryid = Util::cleanString($_GET['discoveryid'] ?? $_POST['discoveryid'] ?? '', 128);

            $payload = [
                'ok' => true,
                'form_type' => $form_type,
                'host' => null,
                'current_item' => null,
                'current_trigger' => null,
                'current_discovery' => null,
                'items' => [],
                'triggers' => [],
                'discovery_rules' => [],
                'templates' => []
            ];

            // All API fetches are best-effort — if one fails, we still
            // return CSRF tokens and whatever context we could gather.
            if ($client !== null) {
                // Fetch the specific item/trigger/discovery being edited.
                try {
                    if ($itemid !== '' && in_array($form_type, ['item', 'item_prototype', 'history'], true)) {
                        $payload['current_item'] = $this->getItemFull($client, $itemid);
                    }
                } catch (\Throwable $e) {
                    $payload['current_item_error'] = $e->getMessage();
                }

                // For history pages, also fetch item history data, related triggers,
                // host macros, and recent problem events.
                if ($form_type === 'history' && $itemid !== '') {
                    // Use request params if provided (from drawer controls),
                    // otherwise fall back to config defaults.
                    $config_limit = (int) ($config['chat']['item_history_max_rows'] ?? 50);
                    $config_period = (int) ($config['chat']['item_history_period_hours'] ?? 24);

                    $history_limit = (int) ($_GET['history_limit'] ?? $_POST['history_limit'] ?? $config_limit);
                    $history_period = (int) ($_GET['history_period'] ?? $_POST['history_period'] ?? $config_period);

                    // Custom time range (from/to datetimes).
                    $time_from_str = Util::cleanString($_GET['time_from'] ?? $_POST['time_from'] ?? '', 64);
                    $time_to_str = Util::cleanString($_GET['time_to'] ?? $_POST['time_to'] ?? '', 64);

                    $time_from = 0;
                    $time_to = 0;

                    if ($time_from_str !== '' && $time_to_str !== '') {
                        $time_from = strtotime($time_from_str);
                        $time_to = strtotime($time_to_str);
                        if ($time_from === false) $time_from = 0;
                        if ($time_to === false) $time_to = 0;
                        // Override period based on custom range.
                        if ($time_from > 0 && $time_to > 0 && $time_to > $time_from) {
                            $history_period = max(1, (int) ceil(($time_to - $time_from) / 3600));
                        }
                    }

                    // Clamp to safe bounds (respect config max, minimum 1h).
                    $history_limit = max(10, min($history_limit, max($config_limit, 1000)));
                    $history_period = max(1, min($history_period, 8760)); // max 1 year

                    $payload['history_params'] = [
                        'limit' => $history_limit,
                        'period_hours' => $history_period,
                        'time_from' => $time_from > 0 ? date('Y-m-d H:i:s', $time_from) : null,
                        'time_to' => $time_to > 0 ? date('Y-m-d H:i:s', $time_to) : null
                    ];

                    try {
                        $payload['item_history'] = $this->getItemHistoryData(
                            $client, $itemid, $history_limit, $history_period,
                            $time_from > 0 ? $time_from : 0,
                            $time_to > 0 ? $time_to : 0
                        );
                    } catch (\Throwable $e) {
                        $payload['item_history_error'] = $e->getMessage();
                    }

                    // Get total count of data points in the period (for display).
                    try {
                        $payload['history_total_count'] = $this->getItemHistoryCount(
                            $client, $itemid,
                            $time_from > 0 ? $time_from : (time() - $history_period * 3600),
                            $time_to > 0 ? $time_to : time()
                        );
                    } catch (\Throwable $e) {}

                    try {
                        $payload['item_triggers'] = $this->getItemTriggers($client, $itemid);
                    } catch (\Throwable $e) {}

                    // Derive hostid from the item if not provided.
                    if ($hostid === '' && !empty($payload['current_item']['hosts'])) {
                        $hostid = $payload['current_item']['hosts'][0]['hostid'] ?? '';
                    }

                    // Fetch host macros — critical for understanding trigger thresholds.
                    if ($hostid !== '') {
                        try {
                            $payload['host_macros'] = $this->getHostMacros($client, $hostid);
                        } catch (\Throwable $e) {}
                    }

                    // Fetch recent problem events for the related triggers.
                    if (!empty($payload['item_triggers'])) {
                        try {
                            $trigger_ids = array_column($payload['item_triggers'], 'triggerid');
                            $payload['recent_problems'] = $this->getRecentProblems($client, $trigger_ids);
                        } catch (\Throwable $e) {}
                    }
                }

                try {
                    if ($triggerid !== '' && in_array($form_type, ['trigger', 'trigger_prototype'], true)) {
                        $payload['current_trigger'] = $this->getTriggerFull($client, $triggerid);
                    }
                } catch (\Throwable $e) {
                    $payload['current_trigger_error'] = $e->getMessage();
                }

                try {
                    if ($discoveryid !== '' && $form_type === 'discovery') {
                        $payload['current_discovery'] = $this->getDiscoveryRuleFull($client, $discoveryid);
                    }
                } catch (\Throwable $e) {
                    $payload['current_discovery_error'] = $e->getMessage();
                }

                // Fetch host info if hostid is provided.
                if ($hostid !== '') {
                    try {
                        $payload['host'] = $this->getHostDetails($client, $hostid);
                    } catch (\Throwable $e) {}

                    try {
                        $payload['items'] = $this->getHostItems($client, $hostid);
                    } catch (\Throwable $e) {}

                    try {
                        if (in_array($form_type, ['trigger', 'trigger_prototype'], true)) {
                            $payload['triggers'] = $this->getHostTriggers($client, $hostid);
                        }
                    } catch (\Throwable $e) {}

                    try {
                        if ($form_type === 'discovery') {
                            $payload['discovery_rules'] = $this->getHostDiscoveryRules($client, $hostid);
                        }
                    } catch (\Throwable $e) {}

                    try {
                        if (in_array($form_type, ['item_prototype', 'trigger_prototype'], true)) {
                            $parent_discoveryid = Util::cleanString($_GET['parent_discoveryid'] ?? $_POST['parent_discoveryid'] ?? '', 128);
                            if ($parent_discoveryid !== '') {
                                $payload['parent_discovery'] = $this->getDiscoveryRuleDetails($client, $parent_discoveryid);
                            }
                        }
                    } catch (\Throwable $e) {}

                    try {
                        $payload['templates'] = $this->getHostTemplates($client, $hostid);
                    } catch (\Throwable $e) {}
                }
            }

            // CSRF tokens for chat endpoint.
            $payload['csrf'] = [
                'field_name' => \CCsrfTokenHelper::CSRF_TOKEN_NAME,
                'chat_send' => \CCsrfTokenHelper::get('ai.chat.send')
            ];

            // Provider info.
            $providers = [];
            foreach ($config['providers'] as $provider) {
                if (Util::truthy($provider['enabled'] ?? false)) {
                    $providers[] = [
                        'id' => $provider['id'] ?? '',
                        'name' => $provider['name'] ?? ''
                    ];
                }
            }
            $payload['default_provider_id'] = (string) $config['default_chat_provider_id'];
            $payload['providers'] = $providers;

            $this->respond($payload);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Fetch a single item with ALL details including preprocessing steps.
     */
    private function getItemFull(ZabbixApiClient $client, string $itemid): ?array {
        $items = $client->call('item.get', [
            'itemids' => [$itemid],
            'output' => 'extend',
            'selectPreprocessing' => 'extend',
            'selectTags' => 'extend',
            'selectHosts' => ['hostid', 'host', 'name'],
            'limit' => 1
        ]);

        if (!$items) {
            // Try item prototype.
            $items = $client->call('itemprototype.get', [
                'itemids' => [$itemid],
                'output' => 'extend',
                'selectPreprocessing' => 'extend',
                'selectTags' => 'extend',
                'selectHosts' => ['hostid', 'host', 'name'],
                'limit' => 1
            ]);
        }

        if (!$items) {
            return null;
        }

        $item = $items[0];

        // Map numeric type IDs to human-readable labels.
        $type_labels = [
            '0' => 'Zabbix agent',
            '2' => 'Zabbix trapper',
            '3' => 'Simple check',
            '5' => 'Zabbix internal',
            '7' => 'Zabbix agent (active)',
            '8' => 'Zabbix aggregate',
            '9' => 'Web item',
            '10' => 'External check',
            '11' => 'Database monitor',
            '12' => 'IPMI agent',
            '13' => 'SSH agent',
            '14' => 'Telnet agent',
            '15' => 'Calculated',
            '16' => 'JMX agent',
            '17' => 'SNMP trap',
            '18' => 'Dependent item',
            '19' => 'HTTP agent',
            '20' => 'SNMP agent',
            '21' => 'Script',
            '22' => 'Browser'
        ];

        $value_type_labels = [
            '0' => 'Numeric (float)',
            '1' => 'Character',
            '2' => 'Log',
            '3' => 'Numeric (unsigned)',
            '4' => 'Text'
        ];

        $preprocessing_type_labels = [
            '1'  => 'Custom multiplier',
            '2'  => 'Right trim',
            '3'  => 'Left trim',
            '4'  => 'Trim',
            '5'  => 'Regular expression',
            '6'  => 'Boolean to decimal',
            '7'  => 'Octal to decimal',
            '8'  => 'Hexadecimal to decimal',
            '9'  => 'Simple change',
            '10' => 'Change per second',
            '11' => 'XML XPath',
            '12' => 'JSONPath',
            '13' => 'In range',
            '14' => 'Matches regular expression',
            '15' => 'Does not match regular expression',
            '16' => 'Check for error in JSON',
            '17' => 'Check for error in XML',
            '18' => 'Check for error using regular expression',
            '19' => 'Discard unchanged',
            '20' => 'Discard unchanged with heartbeat',
            '21' => 'JavaScript',
            '22' => 'Prometheus pattern',
            '23' => 'Prometheus to JSON',
            '24' => 'CSV to JSON',
            '25' => 'Replace',
            '26' => 'Check unsupported',
            '27' => 'XML to JSON',
            '28' => 'SNMP walk value',
            '29' => 'SNMP walk to JSON',
            '30' => 'SNMP get value'
        ];

        $preprocessing = [];
        foreach ($item['preprocessing'] ?? [] as $step) {
            $step_type = (string) ($step['type'] ?? '');
            $preprocessing[] = [
                'type' => $step_type,
                'type_label' => $preprocessing_type_labels[$step_type] ?? 'Unknown ('.$step_type.')',
                'params' => $step['params'] ?? '',
                'error_handler' => $step['error_handler'] ?? '0',
                'error_handler_params' => $step['error_handler_params'] ?? ''
            ];
        }

        return [
            'itemid' => $item['itemid'],
            'name' => $item['name'] ?? '',
            'key_' => $item['key_'] ?? '',
            'type' => $item['type'] ?? '',
            'type_label' => $type_labels[(string) ($item['type'] ?? '')] ?? 'Unknown',
            'value_type' => $item['value_type'] ?? '',
            'value_type_label' => $value_type_labels[(string) ($item['value_type'] ?? '')] ?? 'Unknown',
            'delay' => $item['delay'] ?? '',
            'history' => $item['history'] ?? '',
            'trends' => $item['trends'] ?? '',
            'status' => $item['status'] ?? '',
            'units' => $item['units'] ?? '',
            'description' => $item['description'] ?? '',
            'logtimefmt' => $item['logtimefmt'] ?? '',
            'preprocessing' => $preprocessing,
            'tags' => $item['tags'] ?? [],
            'hosts' => $item['hosts'] ?? [],
            'master_itemid' => $item['master_itemid'] ?? '0'
        ];
    }

    /**
     * Fetch a single trigger with ALL details.
     */
    private function getTriggerFull(ZabbixApiClient $client, string $triggerid): ?array {
        $triggers = $client->call('trigger.get', [
            'triggerids' => [$triggerid],
            'output' => 'extend',
            'selectHosts' => ['hostid', 'host', 'name'],
            'selectItems' => ['itemid', 'name', 'key_'],
            'selectTags' => 'extend',
            'selectDependencies' => ['triggerid', 'description'],
            'expandExpression' => true,
            'expandDescription' => true,
            'limit' => 1
        ]);

        if (!$triggers) {
            // Try trigger prototype.
            $triggers = $client->call('triggerprototype.get', [
                'triggerids' => [$triggerid],
                'output' => 'extend',
                'selectHosts' => ['hostid', 'host', 'name'],
                'selectItems' => ['itemid', 'name', 'key_'],
                'selectTags' => 'extend',
                'selectDependencies' => ['triggerid', 'description'],
                'expandExpression' => true,
                'limit' => 1
            ]);
        }

        if (!$triggers) {
            return null;
        }

        $trigger = $triggers[0];
        $severity_labels = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

        return [
            'triggerid' => $trigger['triggerid'],
            'description' => $trigger['description'] ?? '',
            'expression' => $trigger['expression'] ?? '',
            'recovery_expression' => $trigger['recovery_expression'] ?? '',
            'priority' => $trigger['priority'] ?? '0',
            'priority_label' => $severity_labels[(int) ($trigger['priority'] ?? 0)] ?? 'Unknown',
            'status' => $trigger['status'] ?? '',
            'comments' => $trigger['comments'] ?? '',
            'url' => $trigger['url'] ?? '',
            'type' => $trigger['type'] ?? '0',
            'recovery_mode' => $trigger['recovery_mode'] ?? '0',
            'correlation_mode' => $trigger['correlation_mode'] ?? '0',
            'correlation_tag' => $trigger['correlation_tag'] ?? '',
            'manual_close' => $trigger['manual_close'] ?? '0',
            'event_name' => $trigger['event_name'] ?? '',
            'opdata' => $trigger['opdata'] ?? '',
            'items' => $trigger['items'] ?? [],
            'tags' => $trigger['tags'] ?? [],
            'dependencies' => $trigger['dependencies'] ?? [],
            'hosts' => $trigger['hosts'] ?? []
        ];
    }

    /**
     * Fetch a single discovery rule with ALL details including preprocessing.
     */
    private function getDiscoveryRuleFull(ZabbixApiClient $client, string $discoveryid): ?array {
        $rules = $client->call('discoveryrule.get', [
            'itemids' => [$discoveryid],
            'output' => 'extend',
            'selectPreprocessing' => 'extend',
            'selectFilter' => 'extend',
            'selectLLDMacroPaths' => 'extend',
            'selectHosts' => ['hostid', 'host', 'name'],
            'limit' => 1
        ]);

        if (!$rules) {
            return null;
        }

        $rule = $rules[0];

        $preprocessing = [];
        foreach ($rule['preprocessing'] ?? [] as $step) {
            $step_type = (string) ($step['type'] ?? '');
            $preprocessing[] = [
                'type' => $step_type,
                'params' => $step['params'] ?? '',
                'error_handler' => $step['error_handler'] ?? '0',
                'error_handler_params' => $step['error_handler_params'] ?? ''
            ];
        }

        return [
            'itemid' => $rule['itemid'],
            'name' => $rule['name'] ?? '',
            'key_' => $rule['key_'] ?? '',
            'type' => $rule['type'] ?? '',
            'delay' => $rule['delay'] ?? '',
            'status' => $rule['status'] ?? '',
            'lifetime' => $rule['lifetime'] ?? '',
            'description' => $rule['description'] ?? '',
            'preprocessing' => $preprocessing,
            'filter' => $rule['filter'] ?? [],
            'lld_macro_paths' => $rule['lld_macro_paths'] ?? [],
            'hosts' => $rule['hosts'] ?? []
        ];
    }

    /**
     * Fetch recent history data for an item (last N values).
     */
    /**
     * Fetch all user macros for a host, including inherited template macros.
     * These are critical for understanding trigger expressions that reference
     * macros like {$MSSQL.PAGE_READS.MIN_FOR_RATIO}.
     */
    private function getHostMacros(ZabbixApiClient $client, string $hostid): array {
        $macros = $client->call('usermacro.get', [
            'hostids' => [$hostid],
            'output' => ['macro', 'value', 'type', 'description'],
            'selectHosts' => ['hostid', 'host'],
            'sortfield' => 'macro',
            'inherited' => true
        ]);

        $result = [];
        foreach ($macros as $macro) {
            $source = 'host';
            if (!empty($macro['hosts'])) {
                $macroHostId = $macro['hosts'][0]['hostid'] ?? '';
                if ($macroHostId !== $hostid) {
                    $source = 'template (' . ($macro['hosts'][0]['host'] ?? 'unknown') . ')';
                }
            }

            $result[] = [
                'macro' => $macro['macro'] ?? '',
                'value' => ($macro['type'] ?? '0') === '1' ? '***SECRET***' : ($macro['value'] ?? ''),
                'type' => ($macro['type'] ?? '0') === '1' ? 'secret' : 'text',
                'description' => $macro['description'] ?? '',
                'source' => $source
            ];
        }

        return $result;
    }

    /**
     * Fetch recent problem events for specific trigger IDs.
     * Shows when and how often triggers fired recently.
     */
    private function getRecentProblems(ZabbixApiClient $client, array $trigger_ids, int $days = 7): array {
        if (empty($trigger_ids)) {
            return [];
        }

        $events = $client->call('event.get', [
            'objectids' => $trigger_ids,
            'source' => 0,
            'object' => 0,
            'output' => ['eventid', 'name', 'clock', 'r_clock', 'severity', 'acknowledged', 'objectid'],
            'selectHosts' => ['hostid', 'host', 'name'],
            'sortfield' => ['clock'],
            'sortorder' => 'DESC',
            'time_from' => time() - ($days * 86400),
            'limit' => 100,
            'value' => 1 // PROBLEM events only
        ]);

        $result = [];
        foreach ($events as $event) {
            $duration = '';
            $r_clock = (int) ($event['r_clock'] ?? 0);
            $clock = (int) ($event['clock'] ?? 0);
            if ($r_clock > 0 && $clock > 0) {
                $secs = $r_clock - $clock;
                if ($secs < 60) {
                    $duration = $secs . 's';
                } elseif ($secs < 3600) {
                    $duration = round($secs / 60) . 'm';
                } else {
                    $duration = round($secs / 3600, 1) . 'h';
                }
            } elseif ($r_clock === 0) {
                $duration = 'still active';
            }

            $hostname = '';
            if (!empty($event['hosts'])) {
                $hostname = $event['hosts'][0]['host'] ?? '';
            }

            $result[] = [
                'eventid' => $event['eventid'],
                'name' => $event['name'] ?? '',
                'time' => date('Y-m-d H:i:s', $clock),
                'resolved' => $r_clock > 0 ? date('Y-m-d H:i:s', $r_clock) : 'not resolved',
                'duration' => $duration,
                'severity' => $event['severity'] ?? '0',
                'acknowledged' => ($event['acknowledged'] ?? '0') === '1',
                'hostname' => $hostname,
                'triggerid' => $event['objectid'] ?? ''
            ];
        }

        // Add summary statistics.
        $summary = [
            'total_events' => count($result),
            'period_days' => $days,
            'events' => $result
        ];

        // Count per trigger.
        $per_trigger = [];
        foreach ($result as $evt) {
            $tid = $evt['triggerid'];
            if (!isset($per_trigger[$tid])) {
                $per_trigger[$tid] = ['count' => 0, 'name' => $evt['name']];
            }
            $per_trigger[$tid]['count']++;
        }
        $summary['per_trigger'] = $per_trigger;

        return $summary;
    }

    private function getItemHistoryData(ZabbixApiClient $client, string $itemid, int $limit = 50, int $period_hours = 24, int $time_from = 0, int $time_to = 0): array {
        // Get item info to determine value type.
        $items = $client->call('item.get', [
            'itemids' => [$itemid],
            'output' => ['itemid', 'value_type', 'name', 'key_', 'units'],
            'limit' => 1
        ]);

        if (!$items) {
            return [];
        }

        $item = $items[0];
        $value_type = (int) ($item['value_type'] ?? 0);

        if ($time_from > 0 && $time_to > 0) {
            // Custom time range: call history API directly with from/to.
            $values = $this->getHistoryRange($client, $itemid, $value_type, $limit, $time_from, $time_to);
        } else {
            $values = $client->getItemHistory($itemid, $limit, $value_type, $period_hours);
        }

        $result = [];
        foreach ($values as $v) {
            $result[] = [
                'time' => date('Y-m-d H:i:s', (int) ($v['clock'] ?? 0)),
                'value' => $v['value'] ?? ''
            ];
        }

        return $result;
    }

    /**
     * Fetch history values for a custom time range.
     */
    private function getHistoryRange(ZabbixApiClient $client, string $itemid, int $value_type, int $limit, int $time_from, int $time_to): array {
        $params = [
            'itemids' => [$itemid],
            'output' => ['clock', 'value', 'ns'],
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => min($limit, 1000),
            'history' => $value_type,
            'time_from' => $time_from,
            'time_till' => $time_to
        ];

        $result = $client->call('history.get', $params);

        // If no results with this value type, try others.
        if (!$result && $value_type === 0) {
            foreach ([3, 1, 4] as $fallback_type) {
                $params['history'] = $fallback_type;
                $result = $client->call('history.get', $params);
                if ($result) break;
            }
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Get the total count of history data points in a time range.
     */
    private function getItemHistoryCount(ZabbixApiClient $client, string $itemid, int $time_from, int $time_to): int {
        // Get item value type.
        $items = $client->call('item.get', [
            'itemids' => [$itemid],
            'output' => ['value_type'],
            'limit' => 1
        ]);

        if (!$items) return 0;

        $value_type = (int) ($items[0]['value_type'] ?? 0);

        $result = $client->call('history.get', [
            'itemids' => [$itemid],
            'history' => $value_type,
            'time_from' => $time_from,
            'time_till' => $time_to,
            'countOutput' => true
        ]);

        // countOutput returns a single number (as string or int).
        if (is_array($result) && isset($result[0])) {
            return (int) $result[0];
        }

        return (int) $result;
    }

    /**
     * Get triggers associated with a specific item.
     */
    private function getItemTriggers(ZabbixApiClient $client, string $itemid): array {
        $triggers = $client->call('trigger.get', [
            'itemids' => [$itemid],
            'output' => ['triggerid', 'description', 'expression', 'priority', 'status', 'value', 'lastchange'],
            'expandExpression' => true,
            'expandDescription' => true,
            'limit' => 20
        ]);

        $severity_labels = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
        $result = [];

        foreach ($triggers as $trigger) {
            $result[] = [
                'triggerid' => $trigger['triggerid'],
                'description' => $trigger['description'],
                'expression' => $trigger['expression'],
                'priority' => $trigger['priority'],
                'priority_label' => $severity_labels[(int) ($trigger['priority'] ?? 0)] ?? 'Unknown',
                'status' => $trigger['status'] === '0' ? 'Enabled' : 'Disabled',
                'value' => $trigger['value'] === '1' ? 'PROBLEM' : 'OK',
                'lastchange' => date('Y-m-d H:i:s', (int) ($trigger['lastchange'] ?? 0))
            ];
        }

        return $result;
    }

    private function getHostDetails(ZabbixApiClient $client, string $hostid): ?array {
        $result = $client->call('host.get', [
            'hostids' => [$hostid],
            'output' => ['hostid', 'host', 'name', 'status'],
            'selectGroups' => ['groupid', 'name'],
            'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'port', 'type', 'main'],
            'selectParentTemplates' => ['templateid', 'name'],
            'limit' => 1
        ]);

        if (!$result) {
            // It might be a template, not a host.
            $result = $client->call('template.get', [
                'templateids' => [$hostid],
                'output' => ['templateid', 'host', 'name'],
                'limit' => 1
            ]);

            if ($result) {
                $tpl = $result[0];
                return [
                    'hostid' => $tpl['templateid'],
                    'host' => $tpl['host'],
                    'name' => $tpl['name'],
                    'is_template' => true,
                    'interfaces' => [],
                    'groups' => [],
                    'parent_templates' => []
                ];
            }

            return null;
        }

        $host = $result[0];
        return [
            'hostid' => $host['hostid'],
            'host' => $host['host'],
            'name' => $host['name'],
            'is_template' => false,
            'interfaces' => $host['interfaces'] ?? [],
            'groups' => $host['groups'] ?? [],
            'parent_templates' => $host['parentTemplates'] ?? []
        ];
    }

    private function getHostItems(ZabbixApiClient $client, string $hostid): array {
        $items = $client->call('item.get', [
            'hostids' => [$hostid],
            'output' => ['itemid', 'name', 'key_', 'type', 'value_type', 'delay', 'status', 'units'],
            'sortfield' => 'name',
            'limit' => 200
        ]);

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'itemid' => $item['itemid'],
                'name' => $item['name'],
                'key_' => $item['key_'],
                'type' => $item['type'],
                'value_type' => $item['value_type'],
                'delay' => $item['delay'] ?? '',
                'status' => $item['status'],
                'units' => $item['units'] ?? ''
            ];
        }

        return $result;
    }

    private function getHostTriggers(ZabbixApiClient $client, string $hostid): array {
        $triggers = $client->call('trigger.get', [
            'hostids' => [$hostid],
            'output' => ['triggerid', 'description', 'expression', 'priority', 'status'],
            'expandExpression' => true,
            'sortfield' => 'description',
            'limit' => 100
        ]);

        $result = [];
        foreach ($triggers as $trigger) {
            $result[] = [
                'triggerid' => $trigger['triggerid'],
                'description' => $trigger['description'],
                'expression' => $trigger['expression'],
                'priority' => $trigger['priority'],
                'status' => $trigger['status']
            ];
        }

        return $result;
    }

    private function getHostDiscoveryRules(ZabbixApiClient $client, string $hostid): array {
        $rules = $client->call('discoveryrule.get', [
            'hostids' => [$hostid],
            'output' => ['itemid', 'name', 'key_', 'type', 'delay', 'status'],
            'sortfield' => 'name',
            'limit' => 50
        ]);

        $result = [];
        foreach ($rules as $rule) {
            $result[] = [
                'itemid' => $rule['itemid'],
                'name' => $rule['name'],
                'key_' => $rule['key_'],
                'type' => $rule['type'],
                'delay' => $rule['delay'] ?? '',
                'status' => $rule['status']
            ];
        }

        return $result;
    }

    private function getDiscoveryRuleDetails(ZabbixApiClient $client, string $discoveryid): ?array {
        $rules = $client->call('discoveryrule.get', [
            'itemids' => [$discoveryid],
            'output' => ['itemid', 'name', 'key_', 'type'],
            'selectItems' => ['itemid', 'name', 'key_'],
            'limit' => 1
        ]);

        return $rules[0] ?? null;
    }

    private function getHostTemplates(ZabbixApiClient $client, string $hostid): array {
        $templates = $client->call('template.get', [
            'hostids' => [$hostid],
            'output' => ['templateid', 'name'],
            'sortfield' => 'name'
        ]);

        $result = [];
        foreach ($templates as $tpl) {
            $result[] = [
                'templateid' => $tpl['templateid'],
                'name' => $tpl['name']
            ];
        }

        return $result;
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }
}
