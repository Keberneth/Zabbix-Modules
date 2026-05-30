# AI frontend module for Zabbix 7

A self-contained Zabbix frontend module that adds:

- **Monitoring > AI > Chat** for operator chat inside Zabbix
- **Monitoring > AI > Settings** for provider, instruction, secret, privacy, logging and integration management
- **Monitoring > AI > Logs** for local audit log review
- **Inline AI buttons on the Problems page** with a side-drawer chat for instant problem analysis
- **AI-powered Zabbix actions** via natural language (query problems, create maintenance, modify triggers, manage host groups, etc.)
- **`zabbix.php?action=ai.webhook`** as an internal webhook endpoint for problem enrichment and AI-generated guidance
- **Problem update posting** back to the originating event through the Zabbix API
- **Item history / trend analysis** on demand for deeper AI-driven diagnostics
- **Local outbound redaction / inbound restore** for hostnames, IPs, domains, URLs, OS hints and custom replacements
- **Server-side pending write confirmations** so write-action parameters are not trusted from the browser
- **Per-provider temperature and max token controls** for fine-grained model tuning
- **Local JSONL audit logging** with retention and archive support
- **Optional NetBox enrichment** for VM/device/service context

## What this module does

### Problem page integration

- **AI buttons** appear next to each problem on the Problems page (Monitoring > Problems)
- Clicking a button opens a **side drawer** with the full problem context (trigger name, expression, host, severity, template, related items)
- The drawer automatically starts an AI analysis of the problem (configurable)
- **Include history** button fetches recent item history/trend data and sends it to the AI for deeper analysis
- **Post to event** button posts the AI answer back to the problem as an update comment
- **Full chat** button transfers the conversation to the full AI Chat page with history preserved
- Buttons are only visible to users with module access (controlled via `getAssets()` user type check)
- Survives AJAX table refreshes via MutationObserver
- Per-event in-memory chat state (no cross-contamination between different problems)

### Chat page

- Session-only chat UI inside Zabbix
- Chat history is stored in **browser `sessionStorage` only**
- No server-side chat persistence is implemented by the module
- A separate server-side alias map is used only when redaction is enabled so masked values can be restored safely during the same chat session
- Optional context fields: Event ID, hostname, problem summary, extra operator context
- **Include history** button fetches item history for the selected event and loads it into the message field for review before sending
- Button to post the **last AI answer** back to a Zabbix event as problem update comments
- **AI-powered Zabbix actions**: ask questions or give commands in natural language
- Full conversation transfer from the problem drawer via localStorage bridge

### Chat and AI Security
- **Replaces sensitive values (hostnames, IPs, domains, URLs, OS names) with safe aliases before sending data to the AI provider. When the AI responds, aliases are restored locally so you see the real values.**
- Example: prd-web-001 becomes ai-host-001 outbound. The AI works with the alias. When the reply comes back, ai-host-001 is replaced with prd-web-001 before you see it.
- More information and setup is found in Security / redaction settings in the module

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
- "Add all hosts with the MSSQL template to a 'Microsoft SQL Servers' host group"
- "Create a host group called 'Linux Web Servers'"

#### How it works

1. The AI receives tool definitions in its system prompt describing available Zabbix operations
2. When you ask something that requires Zabbix data, the AI outputs a structured tool call
3. For **read** actions, the module executes the call immediately and formats the result
4. For **write** actions, the module shows a confirmation message with **Confirm / Cancel** buttons
5. Only after you click Confirm does the write action execute
6. Raw tool call JSON is automatically stripped from responses so users only see human-readable text

#### Available tools

The AI exposes a large, growing set of tools (dozens of read and write actions) spanning problem
triage, host/trigger/item diagnostics, maintenance, problem operations, audit-log search, reporting,
notification visibility, configuration context, and NetBox inventory.

Because this set changes as tools are added, it is **not** hand-maintained here — that list always
drifts. The authoritative, always-current catalog is generated directly from the module code
(`ZabbixActionExecutor::allTools()`):

- **In the UI:** Settings → **Zabbix** tab → *Zabbix actions* → the **"?"** help box lists every tool,
  grouped by read/write and by write-permission category, with live counts.
- **Programmatically:** `GET zabbix.php?action=ai.actions.catalog` (Super Admin) returns the full
  registry as JSON — names, descriptions, parameters, categories, and counts.

Representative examples — reads: `get_problems`, `get_host_info`, `get_items`, `get_metric_summary`,
`get_host_interfaces`, `get_trigger_dependencies`, `get_noisy_triggers`, `get_audit_log`,
`get_service_impact`; writes: `create_maintenance`, `update_trigger`, `update_item`,
`acknowledge_problem`, `change_problem_severity`, `suppress_problem`, `add_hosts_to_group`.

#### Permission model

- **Read tools**: Available to any Zabbix user when Zabbix actions are enabled
- **Write tools**: Require all of the following:
  - Mode set to "Read & Write" in settings
  - The specific write category enabled (maintenance, items, triggers, users, problems, hostgroups)
  - Super Admin role (configurable, enabled by default)
- The AI only sees tools the current user is permitted to use
- All permissions are enforced server-side as a second layer

### Settings page

Every settings section has a **?** button that shows inline help with a short explanation of the feature, what each setting does, and the Linux commands needed to set up directories and permissions.

You can add/remove/manage:

- Providers (with separate defaults for chat, webhook, and Zabbix actions)
- **Per-provider temperature and max tokens** (override global defaults per provider)
- Global instruction blocks
- Reference links
- Zabbix API settings
- NetBox settings
- Webhook behavior
- Chat behavior (history limit, temperature, item history period and max rows)
- **Problem page integration** (auto-analyze on/off)
- **Security / redaction** (enable/disable masking, categories, custom exact/regex/domain-suffix rules, local alias-state path, strict mode)
- **Logging** (enable/disable, categories, log path, archive path, retention, payload logging, mapping-detail logging)
- **Zabbix Actions** (enabled/disabled, read/readwrite mode, granular write permissions including hostgroups)

### Provider types supported

- `openai_compatible` - OpenAI, Azure OpenAI, vLLM, LocalAI, any `/chat/completions` endpoint (defaults to `api.openai.com` when endpoint is left blank)
- `ollama` - Local or remote Ollama instances (defaults to `localhost:11434`)
- `anthropic` - Anthropic Claude API (native Messages API support, defaults to `api.anthropic.com`)

## Installation

See `INSTALL.md` for detailed step-by-step instructions.

Quick summary:

1. Copy the `AI` directory into your Zabbix modules folder (e.g. `/usr/share/zabbix/modules/`)
2. Set ownership and permissions
3. Configure SELinux if applicable
4. **Create writable directories** for redaction state and logging (see `INSTALL.md` section 6)
5. In Zabbix frontend: Administration > General > Modules > Scan directory > Enable AI
6. Open Monitoring > AI > Settings and configure at least one provider
7. **Enable logging** in Settings > Logging if you want audit logs (disabled by default)

## Recommended initial configuration

### 1. Provider

For OpenAI-compatible APIs:

- **Type:** `openai_compatible`
- **Endpoint:** Leave blank for `https://api.openai.com/v1` (or set a custom endpoint)
- **Model:** e.g. `gpt-4.1-mini`
- **API key:** use the field or preferably an environment variable
- **Temperature:** Leave blank to use global default, or set per-provider (0-2)
- **Max tokens:** Leave blank for provider default, or set a specific limit
- **Test connection:** Click the button on each provider row to verify connectivity. On success, the model field becomes an autocomplete populated with the provider's available models. Picking a model that requires the default temperature (e.g. GPT-5, o1/o3/o4 series) auto-sets temperature to 1.

For Ollama:

- **Type:** `ollama`
- **Endpoint:** Leave blank for `http://localhost:11434/api/chat` (or set a custom URL)
- **Model:** e.g. `llama3.1:8b`, `qwen2.5:7b`, `mistral-nemo`. Small `gemma*` and `phi*` models are weak at emitting strict tool-call JSON.
- **Context window (Ollama):** sets Ollama's `num_ctx`. Defaults to 16384 — required because Ollama's own default (2048) silently truncates the tools system prompt, leaving the model unable to call anything.

For Anthropic (Claude):

- **Type:** `anthropic`
- **Endpoint:** Leave blank for `https://api.anthropic.com` (or set a custom URL)
- **Model:** e.g. `claude-sonnet-4-20250514`
- **API key:** your Anthropic API key or env var
- **Max tokens:** defaults to 4096 if not set

### 2. Zabbix API

For logged-in frontend users, the module prefers Zabbix's internal frontend API path for chat actions, problem-page context, item history, host/problem lookup, and user-confirmed writes. This uses the current frontend user's Zabbix permissions and avoids a fragile HTTP call back to `api_jsonrpc.php` in split frontend deployments.

Configure a Zabbix API URL and token for webhook/standalone automation and as a fallback when the internal frontend API path is not available. The URL must point to the Zabbix web frontend's `api_jsonrpc.php`, not the Zabbix server daemon.

Example:

- **API URL:** `https://zabbix.example.se/api_jsonrpc.php`
- **Auth mode:** `auto`
- **Token env var:** `ZABBIX_API_TOKEN`

**Important:** Write permissions are still controlled by AI Settings > Zabbix Actions before any write is executed. When using the internal frontend API path, Zabbix also enforces the logged-in user's normal frontend/API permissions. When using the HTTP token fallback or webhook path, the token needs sufficient read/write access for the allowed operations.

### 3. Zabbix Actions

In AI Settings > Zabbix Actions:

- **Enabled:** Check to allow AI-powered Zabbix interactions
- **Mode:** "Read only" (default) or "Read & Write"
- **Write permissions:** Enable per category (maintenance, items, triggers, users, problems, hostgroups)
- **Require Super Admin for write:** Enabled by default. When checked, only Super Admin users can execute write actions

### 4. Provider defaults

You can set different default providers for:

- **Chat** - used for normal troubleshooting conversations
- **Webhook** - used for automated webhook responses
- **Zabbix actions** - used for AI-powered Zabbix queries and modifications

This lets you use a faster/cheaper model for chat and a more capable model for Zabbix actions, or vice versa.

### 5. Chat settings

- **Max history messages:** How many prior messages are sent for context (default 12)
- **Temperature:** Global AI randomness setting (default 1, can be overridden per provider)
- **Item history period:** How far back to fetch item data when "Include history" is clicked (default 24 hours)
- **Item history max rows:** Maximum data points per item (default 50)

### 6. Problem page integration

- **Auto-analyze on open:** When enabled, the AI drawer automatically starts analysis when opened (default on)

### 7. NetBox

Optional. If enabled, the module enriches context with VM/device/service data from NetBox.

### 8. Webhook

The internal webhook URL is:

```text
https://your-zabbix-frontend/zabbix.php?action=ai.webhook
```

You can protect it with a shared secret.

## Suggested media type wiring

An example Zabbix webhook script is included in:

```text
mediatype/media_type_ai_webhook.js
```

Suggested media type parameters are documented in:

```text
mediatype/media_type_setup.md
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
- Write actions are protected by multiple layers: settings mode, per-category permissions, user role checks, server-side pending action storage, and mandatory user confirmation.
- When Security / redaction is enabled, outbound AI requests can replace hostnames, IPs, FQDNs, URLs, OS hints, and any custom rules you define. Replies are restored locally before operators see them.
- Problem page AI buttons are only injected for users with module access (user type check in `getAssets()`).
- Problem context enrichment (trigger, items, templates) is resolved server-side — the browser only passes the event ID, never raw trigger data.
- Logging is local file-based and redacted by default. Storing alias-to-original mapping details is available but intentionally off by default because it is higher risk.

## Important limitations

- No chat persistence by design
- No external FastAPI service required
- The module does **not** create any new Zabbix DB tables
- Local redaction state and pending write confirmations are stored as files under the configured state path (default `/tmp/zabbix-ai-module/state`)
- Local logs are stored as JSONL files under the configured log path (default `/tmp/zabbix-ai-module/logs`) with optional archive path (default `/tmp/zabbix-ai-module/archive`)
- **Logging is disabled by default.** Enable it in Settings > Logging after setting up writable directories.
- **Writable directories must exist and be accessible by the web server process.** On systems with `PrivateTmp=yes` (common on RHEL/systemd), the default `/tmp` paths may not work. See `INSTALL.md` section 6 for setup instructions.
- AI-powered Zabbix actions depend on the AI model correctly interpreting your request and generating valid tool calls. More capable models (GPT-5, Claude Sonnet/Opus) produce better results than smaller models.
- Item history fetching uses the Zabbix `history.get` API and auto-detects the correct history type (numeric float, unsigned, string, text).

## Files of interest

```text
manifest.json                     Module registration and default config
Module.php                        Menu wiring + conditional asset injection for problem page
actions/ChatView.php              Chat page controller
actions/ChatSend.php              Chat AJAX endpoint (with tool-calling loop + problem enrichment)
actions/ChatExecute.php           Confirmed write action executor
actions/EventComment.php          Post AI response back to a Zabbix event
actions/ProblemContext.php        Problem context + CSRF tokens + item history endpoint
actions/SettingsView.php          Settings page controller
actions/SettingsSave.php          Settings save action
actions/Webhook.php               Internal webhook endpoint
lib/ProviderClient.php            LLM provider abstraction (OpenAI, Ollama, Anthropic)
lib/ZabbixApiClient.php           Zabbix API wrapper (problems, triggers, items, history, host groups)
lib/ZabbixActionExecutor.php      Tool definitions, parsing, execution, and JSON stripping
lib/NetBoxClient.php              NetBox enrichment wrapper
lib/PromptBuilder.php             System/user prompt assembly (with problem context + tool prompts)
views/ai.chat.php                 Chat page view (with action confirm UI + history button)
views/ai.settings.php             Settings page view (providers, actions, problem inline settings)
assets/js/ai.chat.js              Session-only chat logic (with action handling + state import)
assets/js/ai.problem.inline.js    Problem page AI button injection + drawer chat
assets/js/ai.settings.js          Dynamic settings rows
assets/css/ai.css                 Module styling
assets/css/ai.problem.inline.css  Problem page drawer styling
mediatype/media_type_ai_webhook.js  Example Zabbix webhook media type script
mediatype/media_type_setup.md       Media type setup guide and parameters
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
