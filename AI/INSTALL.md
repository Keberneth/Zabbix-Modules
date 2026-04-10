# Zabbix 7 AI module install instructions

## 1. Copy the module directory

Use the folder name exactly as `AI`.

Download the AI folder and content from the git to the zabbix module folder
<br>
Usually: /usr/share/zabbix/modules/
<br>
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

## 6. Create writable directories for security state and logging

The module needs writable directories for two features: **redaction alias state** (when security/redaction is enabled) and **audit logging** (when logging is enabled). Both features fail silently if the directories are not writable.

**Important:** On RHEL/CentOS/Fedora with systemd, the `php-fpm` and `httpd` services often run with `PrivateTmp=yes`, which means the web process sees a **private** `/tmp` that is different from what you see as root. This means the default paths under `/tmp/zabbix-ai-module/` may not work as expected. You can check this with:

```bash
systemctl show php-fpm | grep PrivateTmp
systemctl show httpd | grep PrivateTmp
```

If `PrivateTmp=yes`, either:
- **Option A (recommended):** Use a persistent path outside `/tmp` (see below), or
- **Option B:** Set `PrivateTmp=no` in a systemd override (less secure, not recommended)

### Using the default `/tmp` paths

If your web server does NOT use PrivateTmp, the module will auto-create the directories under `/tmp/zabbix-ai-module/`. You can pre-create them for reliability:

```bash
# Determine your web server group (apache, nginx, www-data, etc.)
WEB_GROUP=nginx   # or: apache, www-data

sudo mkdir -p /tmp/zabbix-ai-module/state /tmp/zabbix-ai-module/state/pending
sudo mkdir -p /tmp/zabbix-ai-module/logs /tmp/zabbix-ai-module/archive
sudo chown -R root:$WEB_GROUP /tmp/zabbix-ai-module
sudo chmod -R 0750 /tmp/zabbix-ai-module
```

Note: `/tmp` directories may be cleared on reboot. This is fine for the default setup since redaction state is ephemeral and logs are optional.

### Using persistent paths (recommended for production)

For production, use a dedicated path:

```bash
WEB_GROUP=nginx   # or: apache, www-data

# Redaction state
sudo mkdir -p /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending
sudo chown -R root:$WEB_GROUP /var/lib/zabbix-ai
sudo chmod -R 0750 /var/lib/zabbix-ai

# Logs and archives
sudo mkdir -p /var/log/zabbix-ai /var/log/zabbix-ai/archive
sudo chown -R root:$WEB_GROUP /var/log/zabbix-ai
sudo chmod -R 0750 /var/log/zabbix-ai
```

Then update the paths in **AI Settings > Security** and **AI Settings > Logging**:
- Security state path: `/var/lib/zabbix-ai/state`
- Log path: `/var/log/zabbix-ai`
- Archive path: `/var/log/zabbix-ai/archive`

### SELinux for writable paths

On SELinux-enforcing systems, the web process needs the `httpd_sys_rw_content_t` context on writable paths:

```bash
# For persistent paths
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-ai /var/log/zabbix-ai

# For /tmp paths (if using defaults without PrivateTmp)
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/tmp/zabbix-ai-module(/.*)?'
sudo restorecon -Rv /tmp/zabbix-ai-module
```

### Verify directory access

After setup, verify the web process can write:

```bash
# Test as the web server user
sudo -u nginx touch /var/lib/zabbix-ai/state/test && rm /var/lib/zabbix-ai/state/test && echo "OK"
sudo -u nginx touch /var/log/zabbix-ai/test && rm /var/log/zabbix-ai/test && echo "OK"
```

## 7. Open the module pages

Menu path:

```text
Monitoring -> AI -> Chat
Monitoring -> AI -> Settings
Monitoring -> AI -> Logs
```

Direct actions:

```text
zabbix.php?action=ai.chat
zabbix.php?action=ai.settings
```

## 8. Initial settings

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

## 9. Enable security / redaction

Security/redaction is **enabled by default** in the module config. When enabled, outbound AI requests will have hostnames, IPs, FQDNs, URLs, and OS hints replaced with safe aliases. Replies are restored locally before you see them.

To configure, go to **AI Settings > Security / redaction**:

1. **Enable redaction** - master toggle (on by default)
2. **Strict mode** - blocks requests if any known sensitive value still remains after masking (on by default)
3. **Apply masking on** - choose which channels to mask (chat, webhook, action reads/writes/formatting)
4. **Categories** - choose what to mask (hostnames, IPv4, IPv6, FQDNs, URLs, OS)
5. **Custom replacements** - add exact, regex, or domain-suffix rules for site-specific terms (e.g., replace `skarnes.se` with `mypartdomain.example`)
6. **Local state path** - where alias mappings are stored between requests in the same chat session

If you do NOT want redaction, uncheck "Enable redaction" in settings.

## 10. Enable logging

Logging is **disabled by default**. To enable:

1. Go to **AI Settings > Logging**
2. Check **Enable logging**
3. Select which categories to log (chat, webhook, reads, writes, translations, user activity, settings changes, errors)
4. Optionally enable archive and compression
5. Set retention period (default 30 days)
6. Save settings

After enabling, verify logs are being written:

```bash
ls -la /tmp/zabbix-ai-module/logs/       # default path
# or
ls -la /var/log/zabbix-ai/               # if using custom path
```

If the directory does not exist or is empty after making chat requests, the web process cannot write to the path. See section 6 above for directory setup.

View logs in the Zabbix frontend at **Monitoring > AI > Logs**.

## 11. Webhook endpoint

```text
https://<your-zabbix-frontend>/zabbix.php?action=ai.webhook
```

Media type files are included under `mediatype/`.

## 12. Troubleshooting

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

### Logging shows no entries / log directory does not exist

1. **Is logging enabled?** It is disabled by default. Go to AI Settings > Logging and check "Enable logging".
2. **Are log categories selected?** At least one category must be checked (chat, webhook, reads, writes, etc.).
3. **Can the web process write to the log path?** Check with: `sudo -u nginx ls -la /tmp/zabbix-ai-module/logs/` (replace `nginx` with your web server user). If "No such file or directory", create the directories per section 6.
4. **Is PrivateTmp enabled?** If `systemctl show php-fpm | grep PrivateTmp` shows `yes`, the web process uses a private `/tmp`. Use a persistent path like `/var/log/zabbix-ai/` instead (see section 6).
5. **Is SELinux blocking writes?** Check `ausearch -m avc -ts recent` for denials. Apply the SELinux context per section 6.

### Security redaction causes AI to output invalid tool calls

If the AI outputs `{"tool": "tool_name", ...}` or similar generic placeholders instead of real tool names, check:
1. This was a bug in earlier versions where the hostname redactor treated snake_case programming identifiers (like `get_problems`) as hostnames. Update to the latest module code.
2. If using custom replacement rules, make sure they don't match tool names or JSON keywords.

### Static assets not loading
Check web server file permissions and SELinux context.

### API calls fail
Check:
- frontend server can reach provider URL / Ollama URL / Anthropic URL / NetBox URL
- TLS validation setting matches the endpoint certificate state
- tokens and env vars are visible to php-fpm/httpd

## 13. Notes on chat storage

Chat history is stored in browser `sessionStorage` only. The module does not create tables and does not persist chat server-side.

## 14. Nginx conf

If using the standalone webhook endpoint, verify this is in `/etc/nginx/conf.d/zabbix.conf`:

```nginx
location = /ai-webhook {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /usr/share/zabbix/modules/AI/webhook.php;
    fastcgi_pass unix:/run/php-fpm/zabbix.sock;
}
```

## Complete install commands (nginx + RHEL 9)

Copy-paste all commands to install the module, create writable directories, set permissions, and configure SELinux in one go.

Change `WEB_GROUP=nginx` to `apache` or `www-data` if that is your web server group.

```bash
# ── Variables ──
WEB_GROUP=nginx

# ── 1. Copy module ──
sudo cp -a AI /usr/share/zabbix/modules/
sudo chown -R $WEB_GROUP:$WEB_GROUP /usr/share/zabbix/modules/AI
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;

# ── 2. Create writable directories ──
# Security / redaction state
sudo mkdir -p /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending
sudo chown -R root:$WEB_GROUP /var/lib/zabbix-ai
sudo chmod -R 0750 /var/lib/zabbix-ai

# Logs and archives
sudo mkdir -p /var/log/zabbix-ai /var/log/zabbix-ai/archive
sudo chown -R root:$WEB_GROUP /var/log/zabbix-ai
sudo chmod -R 0750 /var/log/zabbix-ai

# ── 3. SELinux ──
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/AI(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/AI /var/lib/zabbix-ai /var/log/zabbix-ai
sudo setsebool -P httpd_can_network_connect on

# ── 4. Verify writable ──
sudo -u $WEB_GROUP touch /var/lib/zabbix-ai/state/test && sudo rm /var/lib/zabbix-ai/state/test && echo "State: OK"
sudo -u $WEB_GROUP touch /var/log/zabbix-ai/test && sudo rm /var/log/zabbix-ai/test && echo "Logs: OK"
```

Then:

1. **Zabbix frontend:** Administration > General > Modules > Scan directory > Enable AI
2. **AI Settings > Security:** Set state path to `/var/lib/zabbix-ai/state`
3. **AI Settings > Logging:** Set log path to `/var/log/zabbix-ai`, archive path to `/var/log/zabbix-ai/archive`, and check "Enable logging"
4. **AI Settings > Providers:** Add at least one provider
5. **Save settings**


## 15. Custom paths for redaction state and logs (reference)

Defaults:
- redaction state: `/tmp/zabbix-ai-module/state`
- logs: `/tmp/zabbix-ai-module/logs`
- archives: `/tmp/zabbix-ai-module/archive`

If you change these to a persistent custom path, the active web/PHP process must be able to create, read, append, rename, and delete files there.

Recommended Linux permissions:
- directories: `0750`
- files: `0640`
- owner: `root`
- group: your web server / php-fpm group (`apache`, `nginx`, or similar)

Example:

```bash
sudo mkdir -p /var/lib/zabbix-ai/state /var/log/zabbix-ai /var/log/zabbix-ai/archive
sudo chown -R root:nginx /var/lib/zabbix-ai /var/log/zabbix-ai
sudo chmod 0750 /var/lib/zabbix-ai /var/lib/zabbix-ai/state /var/log/zabbix-ai /var/log/zabbix-ai/archive
```

### SELinux for writable custom paths on RHEL

Module code under `/usr/share/zabbix/modules/AI` should stay `httpd_sys_content_t`, but custom writable state/log paths must allow web writes.

Example:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-ai /var/log/zabbix-ai
```
