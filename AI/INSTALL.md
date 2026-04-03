# Zabbix 7 AI module install instructions

## 1. Copy the module directory

Use the folder name exactly as `AI`.

Typical package installs:

```bash
sudo mkdir -p /usr/share/zabbix/modules
sudo cp -a AI /usr/share/zabbix/modules/
```

Some distributions use a different frontend root. The key requirement is that `manifest.json` is directly inside the module directory:

```text
<zabbix-frontend-root>/modules/AI/manifest.json
```

## 2. Set ownership and permissions

For Apache/httpd on RHEL:

```bash
sudo chown -R apache:apache /usr/share/zabbix/modules/AI
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;
```

For nginx + php-fpm on RHEL:

```bash
sudo chown -R nginx:nginx /usr/share/zabbix/modules/AI
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;
```

## 3. SELinux on RHEL 9

If SELinux is enforcing:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/AI(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/AI
sudo setsebool -P httpd_can_network_connect on
```

The `httpd_can_network_connect` boolean is required for the module to make outbound HTTP requests to AI providers (OpenAI, Anthropic, Ollama, etc.) and to the Zabbix API.

## 4. Required PHP modules

The module uses cURL and JSON. Verify:

```bash
php -m | egrep 'curl|json|mbstring'
```

All three should be listed. These are typically installed by default with Zabbix's PHP dependencies.

## 5. Enable the module in Zabbix

In the frontend:

```text
Administration -> General -> Modules
```

Then:
1. Click **Scan directory**
2. Enable **AI**

That is all. No database migrations, no external services, no additional packages.

## 6. Open the module pages

Menu path:

```text
Monitoring -> AI -> Chat
Monitoring -> AI -> Settings
```

Direct actions:

```text
zabbix.php?action=ai.chat
zabbix.php?action=ai.settings
```

## 7. Initial settings

Configure at least one provider.

### OpenAI-compatible
- Type: `openai_compatible`
- Endpoint: `https://api.openai.com/v1` or full `/chat/completions` URL
- Model: your model name
- API key: direct secret or env var

### Ollama
- Type: `ollama`
- Endpoint: `http://localhost:11434/api/chat`
- Model: e.g. `llama3.2:3b`

### Anthropic (Claude)
- Type: `anthropic`
- Endpoint: `https://api.anthropic.com` (or leave empty for default)
- Model: e.g. `claude-sonnet-4-20250514`
- API key: your Anthropic API key or env var

### Provider defaults

You can set different default providers for each purpose:
- **Default for chat** - normal troubleshooting conversations
- **Default for webhook** - automated webhook responses
- **Default for Zabbix actions** - AI-powered Zabbix queries and modifications

### Zabbix API

Required for AI-powered Zabbix actions. Configure:
- API URL and token (or token env var)
- The token needs read permissions for read actions, and write permissions for write actions on the relevant Zabbix objects

### Zabbix Actions

In AI Settings > Zabbix Actions:
- **Enabled**: Allow AI to interact with Zabbix via natural language
- **Mode**: "Read only" (safe default) or "Read & Write"
- **Write permissions**: Enable per category (maintenance, items, triggers, users, problems)
- **Require Super Admin for write**: Enabled by default

Optional integrations:
- NetBox URL/token for CMDB enrichment
- Webhook shared secret for internal webhook protection

## 8. Webhook endpoint

```text
https://<your-zabbix-frontend>/zabbix.php?action=ai.webhook
```

Example media type files are included under `examples/`.

## 9. Troubleshooting

### `Page not found` on `ai.chat`
Usually means one of these:
- wrong module path
- module not scanned/enabled
- `manifest.json` not directly in the `AI` directory
- files not placed in `actions/`, `views/`, `assets/`, `lib/`

### `Access denied` on `ai.settings`
`ai.settings` is intentionally limited to Super Admin. The controller checks:

- `USER_TYPE_SUPER_ADMIN` for settings
- `USER_TYPE_ZABBIX_USER` or higher for chat

### Write actions denied for a user
Write actions require:
1. Zabbix Actions mode set to "Read & Write"
2. The specific category enabled (maintenance, items, triggers, users, or problems)
3. Super Admin role (if "Require Super Admin for write" is checked)

### AI does not execute Zabbix actions
Check:
- Zabbix Actions is enabled in settings
- Zabbix API URL and token are configured
- The API token has sufficient permissions
- The AI model is capable enough (larger models handle tool calls better)

### Static assets not loading
Check web server file permissions and SELinux context.

### API calls fail
Check:
- frontend server can reach provider URL / Ollama URL / Anthropic URL / NetBox URL
- TLS validation setting matches the endpoint certificate state
- tokens and env vars are visible to php-fpm/httpd

## 10. Notes on chat storage

Chat history is stored in browser `sessionStorage` only. The module does not create tables and does not persist chat server-side.

## 11. Nginx conf

If using the standalone webhook endpoint, verify this is in `/etc/nginx/conf.d/zabbix.conf`:

```nginx
location = /ai-webhook {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /usr/share/zabbix/modules/AI/webhook.php;
    fastcgi_pass unix:/run/php-fpm/zabbix.sock;
}
```

## Complete install commands (nginx + RHEL 9)

For a quick copy-paste install:

```bash
# Copy module
sudo cp -a AI /usr/share/zabbix/modules/

# Set ownership
sudo chown -R nginx:nginx /usr/share/zabbix/modules/AI

# Set permissions
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;

# SELinux
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/AI(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/AI
sudo setsebool -P httpd_can_network_connect on
```

Then in Zabbix frontend: Administration > General > Modules > Scan directory > Enable AI.
