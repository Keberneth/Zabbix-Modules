<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

class PromptBuilder {

    /**
     * Build the system prompt.
     *
     * If a $redactor is supplied, instruction blocks marked `sensitive=true`
     * are passed through the redactor on the given $channel; non-sensitive
     * instructions and admin-authored reference links are kept verbatim.
     * The caller MUST NOT pass the resulting system message back through
     * Redactor::redactMessages — it has already been processed.
     */
    public static function buildSystemPrompt(array $config, array $context = [], ?Redactor $redactor = null, string $channel = 'chat'): string {
        $config = Config::mergeWithDefaults($config);

        $blocks = [];
        $had_instruction = false;

        foreach ($config['instructions'] as $instruction) {
            if (!is_array($instruction) || !Util::truthy($instruction['enabled'] ?? false)) {
                continue;
            }

            $content = Util::cleanMultiline($instruction['content'] ?? '', 50000);

            if ($content === '') {
                continue;
            }

            $had_instruction = true;

            if ($redactor !== null && Util::truthy($instruction['sensitive'] ?? false)) {
                $content = $redactor->redactText($content, $channel);
            }

            $blocks[] = $content;
        }

        if (!$had_instruction) {
            $blocks[] = Config::defaults()['instructions'][0]['content'];
        }

        $enabled_links = [];

        foreach ($config['reference_links'] as $link) {
            if (!is_array($link) || !Util::truthy($link['enabled'] ?? false)) {
                continue;
            }

            $url = Util::cleanUrl($link['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $title = Util::cleanString($link['title'] ?? '', 128);
            $enabled_links[] = ($title !== '') ? ('- '.$title.': '.$url) : ('- '.$url);
        }

        if ($enabled_links) {
            $blocks[] = "If useful, suggest these operator reference links exactly as written:\n".implode("\n", $enabled_links);
        }

        if (!empty($context['mode'])) {
            $blocks[] = 'Current mode: '.Util::cleanString($context['mode'], 64).'.';
        }

        if (!empty($context['response_style'])) {
            $blocks[] = Util::cleanMultiline($context['response_style'], 1000);
        }

        return trim(implode("\n\n", array_filter($blocks, static function($value) {
            return trim((string) $value) !== '';
        })));
    }

    public static function buildChatContextBlock(array $context): string {
        $lines = [];

        if (!empty($context['eventid'])) {
            $lines[] = 'Event ID: '.Util::cleanString($context['eventid'], 128);
        }

        if (!empty($context['hostname'])) {
            $lines[] = 'Hostname: '.Util::cleanString($context['hostname'], 255);
        }

        if (!empty($context['problem_summary'])) {
            $lines[] = 'Problem summary: '.Util::cleanMultiline($context['problem_summary'], 2000);
        }

        if (!empty($context['os_type'])) {
            $lines[] = 'Host OS: '.Util::cleanString($context['os_type'], 128);
        }

        if (!empty($context['netbox_info'])) {
            $lines[] = "NetBox / CMDB context:\n".$context['netbox_info'];
        }

        if (!empty($context['problem_context']) && is_array($context['problem_context'])) {
            $pc = $context['problem_context'];

            if (!empty($pc['trigger_name'])) {
                $lines[] = 'Trigger name: '.Util::cleanString($pc['trigger_name'], 2000);
            }

            if (!empty($pc['trigger_expression'])) {
                $lines[] = 'Trigger expression: '.Util::cleanString($pc['trigger_expression'], 4000);
            }

            if (!empty($pc['trigger_comments'])) {
                $lines[] = 'Trigger description/comments: '.Util::cleanMultiline($pc['trigger_comments'], 4000);
            }

            if (!empty($pc['items']) && is_array($pc['items'])) {
                $item_lines = [];
                foreach ($pc['items'] as $item) {
                    $parts = [];
                    if (!empty($item['name'])) {
                        $parts[] = 'name: '.$item['name'];
                    }
                    if (!empty($item['key_'])) {
                        $parts[] = 'key: '.$item['key_'];
                    }
                    if (!empty($item['description'])) {
                        $parts[] = 'description: '.$item['description'];
                    }
                    if ($parts) {
                        $item_lines[] = '  - '.implode(', ', $parts);
                    }
                }
                if ($item_lines) {
                    $lines[] = "Related items:\n".implode("\n", $item_lines);
                }
            }

            if (!empty($pc['template_names']) && is_array($pc['template_names'])) {
                $tpl_names = array_filter($pc['template_names'], function($n) { return trim((string) $n) !== ''; });
                if ($tpl_names) {
                    $lines[] = 'Template(s): '.implode(', ', $tpl_names);
                }
            }
        }

        if (!empty($context['extra_context'])) {
            $lines[] = "Additional operator context:\n".Util::cleanMultiline($context['extra_context'], 60000);
        }

        return trim(implode("\n\n", $lines));
    }

    public static function buildWebhookUserPrompt(array $payload, array $context): string {
        $lines = [];
        $lines[] = 'Generate first-line troubleshooting guidance for the following Zabbix problem.';

        if (!empty($payload['trigger_name'])) {
            $lines[] = 'Problem: '.Util::cleanMultiline($payload['trigger_name'], 2000);
        }

        if (!empty($payload['hostname'])) {
            $lines[] = 'Hostname: '.Util::cleanString($payload['hostname'], 255);
        }

        if (!empty($payload['eventid'])) {
            $lines[] = 'Event ID: '.Util::cleanString($payload['eventid'], 128);
        }

        if (!empty($payload['severity'])) {
            $lines[] = 'Severity: '.Util::cleanString($payload['severity'], 128);
        }

        if (!empty($payload['opdata'])) {
            $lines[] = "Operational data:\n".Util::cleanMultiline($payload['opdata'], 4000);
        }

        if (!empty($payload['event_url'])) {
            $lines[] = 'Event URL: '.Util::cleanUrl($payload['event_url']);
        }

        if (!empty($payload['event_tags_text'])) {
            $lines[] = "Event tags:\n".$payload['event_tags_text'];
        }

        if (!empty($context['os_type'])) {
            $lines[] = 'Host OS: '.Util::cleanString($context['os_type'], 128);
        }

        if (!empty($context['netbox_info'])) {
            $lines[] = "NetBox / CMDB context:\n".$context['netbox_info'];
        }

        $lines[] = 'Reply in Markdown.';

        return implode("\n\n", $lines);
    }

    /**
     * Build the system prompt that includes Zabbix action tool definitions.
     * This is appended to the regular system prompt when zabbix_actions is enabled.
     */
    public static function buildActionsSystemPrompt(array $config, array $permissions): string {
        $tool_block = ZabbixActionExecutor::buildToolSystemPrompt($permissions);

        if ($tool_block === '') {
            return '';
        }

        $blocks = [];
        $blocks[] = $tool_block;
        $blocks[] = 'Important rules for tool calls:';
        $blocks[] = '- For read tools: output ONLY the JSON tool call, no surrounding text.';
        $blocks[] = '- For write tools: output ONLY the JSON tool call with "confirm": true and a "confirm_message" describing the action.';
        $blocks[] = '- If a multi-step action is needed (e.g. find a trigger then update it), do ONE step at a time. First call the read tool, then after getting results, call the write tool.';
        $blocks[] = '- If the user asks something that does not require a Zabbix tool, respond with normal text — do not output JSON.';
        $blocks[] = '- Never invent data. Only report what the tools return.';
        $blocks[] = '';
        $blocks[] = 'CRITICAL Zabbix terminology for triggers:';
        $blocks[] = '- In Zabbix API, a trigger\'s "description" field is the TRIGGER NAME (e.g. "{HOST.NAME} has uptime over 60 days"). Do NOT change it unless the user explicitly wants to rename the trigger.';
        $blocks[] = '- The "comments" field is the operational notes / comment text. When the user says "update comment", "change comment", "add notes", or "set description to..." they almost always mean the "comments" field.';
        $blocks[] = '- The "expression" field is the trigger logic formula. NEVER change it unless the user explicitly asks to modify the expression or threshold.';
        $blocks[] = '- When the user mentions a template name (e.g. "Windows Monitoring Zabbix Agent Active"), use the "template" parameter in get_triggers, NOT "hostname".';
        $blocks[] = '- Templates and hosts are different in Zabbix. A template name looks like "Windows Monitoring Zabbix Agent Active" or "Linux by Zabbix agent". A hostname is the actual server name like "db-01" or "web-server-03".';

        return implode("\n", $blocks);
    }
}
