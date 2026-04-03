# AI frontend module for Zabbix 7

A self-contained Zabbix frontend module that adds:

- **Monitoring > AI > Chat** for operator chat inside Zabbix
- **Monitoring > AI > Settings** for provider, instruction, secret and integration management
- **AI-powered Zabbix actions** via natural language (query problems, create maintenance, modify triggers, etc.)
- **`zabbix.php?action=ai.webhook`** as an internal webhook endpoint for problem enrichment and AI-generated first-line guidance
- **Problem update posting** back to the originating event through the Zabbix API
- **Optional NetBox enrichment** for VM/device/service context

## What this module does

### Chat page

- Session-only chat UI inside Zabbix
- Chat history is stored in **browser `sessionStorage` only**
- No server-side chat persistence is implemented by the module
- Optional context fields: Event ID, hostname, problem summary, extra operator context
- Button to post the **last AI answer** back to a Zabbix event as problem update comments
- **AI-powered Zabbix actions**: ask questions or give commands in natural language

### AI-powered Zabbix actions

When enabled, you can type natural language commands in the chat and the AI will interact with Zabbix on your behalf. Examples:

**Read actions** (execute immediately):

- "Show me all unacknowledged problems with severity High or above"
- "Give me a list of all unsupported items"
- "What is the uptime for server1"
- "What OS does DB-server5 have"
- "Show me all triggers for host web-01"
- "List items on host db-01 that contain 'cpu'"

**Write actions** (always ask for confirmation first):

- "Create a maintenance window for host db-01 for 2 hours"
- "Create a maintenance window for host db-01 for 2 hours on 2026-05-21 starting at 16:00"
- "This trigger is set to trigger a problem after 60 days. Change it to 65 days."
- "Acknowledge problem event 12345 with message 'Investigating'"
- "Disable item 'CPU idle time' on host web-01"

#### How it works

1. The AI receives tool definitions in its system prompt describing available Zabbix operations
2. When you ask something that requires Zabbix data, the AI outputs a structured tool call
3. For **read** actions, the module executes the call immediately and formats the result
4. For **write** actions, the module shows a confirmation message with **Confirm / Cancel** buttons
5. Only after you click Confirm does the write action execute

#### Available tools

| Tool | Type | Description |
|------|------|-------------|
| `get_problems` | Read | Get active problems with severity/acknowledged/host filters |
| `get_unsupported_items` | Read | Get items in unsupported state, grouped by host |
| `get_host_info` | Read | Host details: inventory, groups, interfaces, tags |
| `get_host_uptime` | Read | Query system.uptime item value |
| `get_host_os` | Read | Query OS via system.sw.os |
| `get_triggers` | Read | Get triggers with filters |
| `get_items` | Read | Get items with filters |
| `create_maintenance` | Write | Create a maintenance window for host(s) |
| `update_trigger` | Write | Modify trigger expression, status, priority |
| `update_item` | Write | Modify item settings (status, interval, etc.) |
| `create_user` | Write | Create a Zabbix user |
| `acknowledge_problem` | Write | Acknowledge, close, or comment on a problem |

#### Permission model

- **Read tools**: Available to any Zabbix user when Zabbix actions are enabled
- **Write tools**: Require all of the following:
  - Mode set to "Read & Write" in settings
  - The specific write category enabled (maintenance, items, triggers, users, problems)
  - Super Admin role (configurable, enabled by default)
- The AI only sees tools the current user is permitted to use
- All permissions are enforced server-side as a second layer

### Settings page

You can add/remove/manage:

- Providers (with separate defaults for chat, webhook, and Zabbix actions)
- Global instruction blocks
- Reference links
- Zabbix API settings
- NetBox settings
- Webhook behavior
- Chat behavior
- **Zabbix Actions** (enabled/disabled, read/readwrite mode, granular write permissions)

### Provider types supported

- `openai_compatible` - OpenAI, Azure OpenAI, vLLM, LocalAI, any `/chat/completions` endpoint
- `ollama` - Local or remote Ollama instances
- `anthropic` - Anthropic Claude API (native Messages API support)

## Installation

See `INSTALL.md` for detailed step-by-step instructions.

Quick summary:

1. Copy the `AI` directory into your Zabbix modules folder (e.g. `/usr/share/zabbix/modules/`)
2. Set ownership and permissions
3. Configure SELinux if applicable
4. In Zabbix frontend: Administration > General > Modules > Scan directory > Enable AI
5. Open Monitoring > AI > Settings and configure at least one provider

## Recommended initial configuration

### 1. Provider

For OpenAI-compatible APIs:

- **Type:** `openai_compatible`
- **Endpoint:** `https://api.openai.com/v1` or full `/chat/completions` URL
- **Model:** e.g. `gpt-4.1-mini`
- **API key:** use the field or preferably an environment variable

For Ollama:

- **Type:** `ollama`
- **Endpoint:** `http://localhost:11434/api/chat`
- **Model:** e.g. `llama3.2:3b`

For Anthropic (Claude):

- **Type:** `anthropic`
- **Endpoint:** `https://api.anthropic.com` (or leave empty for default)
- **Model:** e.g. `claude-sonnet-4-20250514`
- **API key:** your Anthropic API key or env var

### 2. Zabbix API

Configure a Zabbix API URL and token. Required for:

- AI-powered Zabbix actions (querying and modifying Zabbix)
- OS lookup by hostname
- Problem update comments back to the event

Example:

- **API URL:** `https://zabbix.example.se/api_jsonrpc.php`
- **Auth mode:** `auto`
- **Token env var:** `ZABBIX_API_TOKEN`

**Important:** The API token needs sufficient permissions for the operations you want the AI to perform. For write actions (maintenance, trigger updates, etc.) the token needs write access to those Zabbix objects.

### 3. Zabbix Actions

In AI Settings > Zabbix Actions:

- **Enabled:** Check to allow AI-powered Zabbix interactions
- **Mode:** "Read only" (default) or "Read & Write"
- **Write permissions:** Enable per category (maintenance, items, triggers, users, problems)
- **Require Super Admin for write:** Enabled by default. When checked, only Super Admin users can execute write actions

### 4. Provider defaults

You can set different default providers for:

- **Chat** - used for normal troubleshooting conversations
- **Webhook** - used for automated webhook responses
- **Zabbix actions** - used for AI-powered Zabbix queries and modifications

This lets you use a faster/cheaper model for chat and a more capable model for Zabbix actions, or vice versa.

### 5. NetBox

Optional. If enabled, the module enriches context with VM/device/service data from NetBox.

### 6. Webhook

The internal webhook URL is:

```text
https://your-zabbix-frontend/zabbix.php?action=ai.webhook
```

You can protect it with a shared secret.

## Suggested media type wiring

An example Zabbix webhook script is included in:

```text
examples/media_type_ai_webhook.js
```

Suggested media type parameters are documented in:

```text
examples/media_type_setup.md
```

## Webhook payload compatibility

The module accepts either:

- a direct JSON payload with fields like `eventid`, `trigger_name`, `hostname`, etc.
- or a payload containing:

```json
{
  "message": "{...json string...}"
}
```

## Security notes

- Prefer **environment variables** for secrets over storing them directly in module config.
- Enable TLS verification unless you have a specific internal reason not to.
- The webhook endpoint does **not** require a logged-in Zabbix UI session, so use a shared secret if you expose it beyond localhost/internal networks.
- Write actions are protected by multiple layers: settings mode, per-category permissions, user role checks, and mandatory user confirmation.

## Important limitations

- No chat persistence by design
- No external FastAPI service required
- The module does **not** create any new Zabbix DB tables
- AI-powered Zabbix actions depend on the AI model correctly interpreting your request and generating valid tool calls. More capable models (GPT-4, Claude Sonnet/Opus) produce better results than smaller models.

## Files of interest

```text
manifest.json                     Module registration and default config
Module.php                        Menu wiring
actions/ChatView.php              Chat page controller
actions/ChatSend.php              Chat AJAX endpoint (with tool-calling loop)
actions/ChatExecute.php           Confirmed write action executor
actions/EventComment.php          Post AI response back to a Zabbix event
actions/SettingsView.php          Settings page controller
actions/SettingsSave.php          Settings save action
actions/Webhook.php               Internal webhook endpoint
lib/ProviderClient.php            LLM provider abstraction (OpenAI, Ollama, Anthropic)
lib/ZabbixApiClient.php           Zabbix API wrapper (extended with action methods)
lib/ZabbixActionExecutor.php      Tool definitions, parsing, and execution dispatcher
lib/NetBoxClient.php              NetBox enrichment wrapper
lib/PromptBuilder.php             System/user prompt assembly (with tool prompts)
views/ai.chat.php                 Chat page view (with action confirm UI)
views/ai.settings.php             Settings page view (with Zabbix actions section)
assets/js/ai.chat.js              Session-only chat logic (with action handling)
assets/js/ai.settings.js          Dynamic settings rows
assets/css/ai.css                 Module styling
examples/media_type_ai_webhook.js Example Zabbix webhook media type script
examples/media_type_setup.md      Suggested media type parameters/macros
```

## Quick webhook smoke test

```bash
curl -k -X POST \
  'https://your-zabbix-frontend/zabbix.php?action=ai.webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-AI-Webhook-Secret: your-shared-secret' \
  -d '{
        "eventid": "123456",
        "event_value": "1",
        "trigger_name": "CPU utilization is too high",
        "hostname": "server01",
        "severity": "High",
        "opdata": "CPU: 97%",
        "event_tags": [
          {"tag": "service", "value": "api"},
          {"tag": "team", "value": "platform"}
        ]
      }'
```

## Notes for future extension

- Add markdown rendering instead of `<pre>` transcript display
- Add per-provider temperature/max token controls
- Add a problem-page launcher button
- Add more Zabbix action tools (template management, host group operations, etc.)
