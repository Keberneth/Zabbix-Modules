# Zabbix 7 AI module install instructions

## Zabbix 7 AI module install simple instructions
Download the AI folder and content from the git to the zabbix module folder
<br>
Usually: /usr/share/zabbix/modules/
<br>
Some distributions use a different frontend root. The key requirement is that `manifest.json` is directly inside the module directory:
<br>
Then run the following commands
```bash
WEB_GROUP=apache

# ── 1. Deploy module
sudo chown -R root:root /usr/share/zabbix/modules/AI
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;
sudo restorecon -Rv /usr/share/zabbix/modules/AI

# ── 2. Fix the writable-directory modes (the real bug: 0750 gave group r-x only, no write)
# 02770 = setgid + rwx for group, so new files inherit the apache group automatically.
sudo chgrp -R "$WEB_GROUP" /var/lib/zabbix-ai /var/lib/zabbix/ai_reports /var/log/zabbix-ai
sudo chmod 02770 /var/lib/zabbix-ai /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending
sudo chmod 02770 /var/lib/zabbix/ai_reports
sudo chmod 02770 /var/log/zabbix-ai /var/log/zabbix-ai/archive

# ── 3. Verify the php-fpm user can actually write+read each path
sudo -u "$WEB_GROUP" sh -c 'echo t > /var/lib/zabbix-ai/state/.t && cat /var/lib/zabbix-ai/state/.t > /dev/null && rm /var/lib/zabbix-ai/state/.t'   && echo "State:   OK"
sudo -u "$WEB_GROUP" sh -c 'echo t > /var/lib/zabbix/ai_reports/.t && cat /var/lib/zabbix/ai_reports/.t > /dev/null && rm /var/lib/zabbix/ai_reports/.t' && echo "Reports: OK"
sudo -u "$WEB_GROUP" sh -c 'echo t > /var/log/zabbix-ai/.t && cat /var/log/zabbix-ai/.t > /dev/null && rm /var/log/zabbix-ai/.t'                   && echo "Logs:    OK"

# ── 4. Reload php-fpm
sudo systemctl restart php-fpm
```

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

### Reports directory (downloads + graphs)

The chat can generate downloadable reports (CSV/HTML/JSON/SVG/Markdown) and inline graphs. Generated files are stored token-bound on disk and served back to the user through `zabbix.php?action=ai.report.download`. The web process needs **read and write** access to this directory, and it should be SELinux-labelled `httpd_sys_rw_content_t`.

By default the module reuses the security state path (`reports/` subdirectory). For production, configure a dedicated path in **AI Settings → Reports → Directory**, e.g. `/var/lib/zabbix-ai/reports`.

```bash
WEB_GROUP=nginx   # or: apache, www-data

sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/reports
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai/reports(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-ai/reports

# Verify the web user can write AND read back.
sudo -u $WEB_GROUP sh -c 'echo test > /var/lib/zabbix-ai/reports/.write_test && cat /var/lib/zabbix-ai/reports/.write_test && rm /var/lib/zabbix-ai/reports/.write_test' && echo "Reports: OK"
```

### Important: who is the PHP-FPM user?

On RHEL/Alma/Rocky, php-fpm runs as `apache` **by default even when nginx is the HTTP front-end** — the `WEB_GROUP` in the commands above must match the actual php-fpm worker user, not the HTTP server user. Verify with:

```bash
ps -eo user,comm | grep php-fpm | head
# or
grep -E '^\s*(user|group)' /etc/php-fpm.d/www.conf
```

If you see `apache`, use `WEB_GROUP=apache` for every write-capable directory below (state, logs, reports). Setting them to `nginx` when php-fpm actually runs as `apache` results in silent permission failures when the module tries to write reports, redaction state, or logs.

### Verify directory access

After setup, verify the web process can write:

```bash
# Test as the web server user
sudo -u nginx touch /var/lib/zabbix-ai/state/test && rm /var/lib/zabbix-ai/state/test && echo "State: OK"
sudo -u nginx touch /var/log/zabbix-ai/test && rm /var/log/zabbix-ai/test && echo "Logs: OK"
sudo -u nginx touch /var/lib/zabbix-ai/reports/test && rm /var/lib/zabbix-ai/reports/test && echo "Reports: OK"
```

If any of these say "Permission denied", the most common cause is using the wrong `WEB_GROUP` (see above) — confirm the actual php-fpm worker user and re-`chown`.

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

**Pick the right `WEB_GROUP` first.** On RHEL/Alma/Rocky with nginx + php-fpm, the **php-fpm worker** is the process that reads/writes module files — and it defaults to `apache` even when the HTTP front-end is nginx. Confirm with:

```bash
ps -eo user,comm | grep php-fpm | head
```

Set `WEB_GROUP` to whatever the php-fpm pool runs as (`apache`, `nginx`, or `www-data`).

```bash
# ── Variables ──
# IMPORTANT: This is the php-fpm worker user, not the HTTP server user.
WEB_GROUP=apache   # change to nginx or www-data if your php-fpm runs as those

# ── 1. Copy module ──
sudo cp -a AI /usr/share/zabbix/modules/
sudo chown -R root:root /usr/share/zabbix/modules/AI
sudo find /usr/share/zabbix/modules/AI -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/AI -type f -exec chmod 644 {} \;

# ── 2. Create writable directories ──
# Security / redaction state
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state/pending

# Reports (CSV/HTML/JSON/SVG/MD downloads and inline graphs)
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/reports

# Logs and archives
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai/archive

# ── 3. SELinux ──
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/AI(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/AI /var/lib/zabbix-ai /var/log/zabbix-ai
sudo setsebool -P httpd_can_network_connect on

# ── 4. Verify writable AND readable for the web user ──
# Note: this verifies the actual write+read round-trip the report download path needs.
sudo -u $WEB_GROUP sh -c 'echo t > /var/lib/zabbix-ai/state/.t && rm /var/lib/zabbix-ai/state/.t'   && echo "State:   OK"
sudo -u $WEB_GROUP sh -c 'echo t > /var/log/zabbix-ai/.t   && rm /var/log/zabbix-ai/.t'             && echo "Logs:    OK"
sudo -u $WEB_GROUP sh -c 'echo t > /var/lib/zabbix-ai/reports/.t && cat /var/lib/zabbix-ai/reports/.t > /dev/null && rm /var/lib/zabbix-ai/reports/.t' && echo "Reports: OK"

# ── 5. Reload php-fpm so it picks up the new module ──
sudo systemctl restart php-fpm
```

Then:

1. **Zabbix frontend:** Administration > General > Modules > Scan directory > Enable AI
2. **AI Settings > Security:** Set state path to `/var/lib/zabbix-ai/state`
3. **AI Settings > Reports:** Set directory to `/var/lib/zabbix-ai/reports` (leave blank to reuse the state path)
4. **AI Settings > Logging:** Set log path to `/var/log/zabbix-ai`, archive path to `/var/log/zabbix-ai/archive`, and check "Enable logging"
5. **AI Settings > Providers:** Add at least one provider
6. **Save settings**

### Troubleshooting: report download link or inline chart image is broken

If the download link in chat returns 404 or the inline chart shows a broken-image icon while the same SVG works when copied to the browser address bar:

1. Reports directory not writable by the php-fpm worker — re-run step 2 with the correct `WEB_GROUP` (see `ps -eo user,comm | grep php-fpm`).
2. SELinux missing on the reports directory — re-run step 3 then `restorecon -Rv`.
3. Reports directory configured to a path that does not exist — check AI Settings → Reports → Directory matches a directory the web user can write to.
4. Old SELinux contexts on existing files — fix with: `sudo restorecon -Rv /var/lib/zabbix-ai/reports`
5. Check for AVC denials: `sudo ausearch -m avc -ts recent | grep -i zabbix`


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
