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

# ── 2. Fix the writable-directory modes
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

# Zabbix 7 AI module install full instructions

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

Deploy the complete directory to every frontend node before serving requests,
then reload PHP-FPM/Apache to clear OPcache and hard-refresh the browser so PHP,
the settings view, and `ai.settings.js` all come from the same release. A mixed
deployment is rejected by the settings save/version handshake instead of being
reported as a successful save.

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

The module uses cURL, JSON, and mbstring. Encrypted-at-rest storage of inline
secrets and of confirmed writes, sensitive reads and bulk previews also requires
**either Sodium or OpenSSL in the PHP SAPI that actually serves Zabbix**. A
development instance running in plaintext compatibility mode needs neither
backend. Verify the serving
PHP-FPM/mod_php installation:

```bash
php -m | egrep 'curl|json|mbstring|openssl|sodium'
```

`curl`, `json`, and `mbstring` should be listed, plus at least one of `openssl`
or `sodium`. The CLI command is only a first-pass check: distributions can load
different extensions for CLI and the web SAPI. Confirm with the AI Settings
storage banner (or a temporary `phpinfo()` page served by the same pool), then
remove any diagnostic page.

## 5. Enable the module in Zabbix

In the frontend:

```text
Administration -> General -> Modules
```

Then:
1. Click **Scan directory**
2. Enable **AI**

That is all. No database migrations, no external services, no additional packages.

## 5b. Configure secret references and encryption

The recommended setup keeps provider/tokens out of the database entirely:

- `env:NAME` reads a deployment-managed environment variable.
- `file:NAME` reads a protected runtime file beneath the server-admin-set
  `ZABBIX_AI_SECRET_DIR`. A local encrypted deployment file (for example Ansible
  Vault) or any vault agent can materialize these files.

The PHP module does not decrypt Ansible Vault files and must never receive the
Ansible vault password. Ansible decrypts at deployment/boot time and writes the
specific runtime files PHP may read.

`file:` accepts a logical name only—never an arbitrary path. Missing references
fail closed and do not fall back to old database credentials.

Read & Write actions, sensitive reads, and bulk previews are staged server-side
and should be protected by a master key. Configure one for any non-development
deployment. Prefer materializing that key into a protected runtime file and set
only its path for PHP-FPM:

```ini
env[ZABBIX_AI_SECRET_DIR] = "/run/zabbix-ai-provider-secrets"
env[ZABBIX_AI_ENCRYPTION_KEY_FILE] = "/run/zabbix-ai-master/db_encryption_key"
```

### Problems-page AI drawer: confirmation level for reads

By default every privacy-sensitive read pauses for a **Confirm** click on every
surface, so clicking **AI** on a problem row shows a preview before it analyses
anything. If that is too much friction for your operators, a Super Admin can
change **AI settings → Zabbix actions → Problems-page AI drawer: privacy
confirmations for reads**:

| Level | Effect |
|---|---|
| `off` (default) | Every sensitive read is confirmed, everywhere. |
| `triage` | 15 event- and host-scoped triage reads run immediately in the drawer: related problems, event timeline, problems, problem graph, host info, host interfaces, items, triggers, trigger dependencies, unsupported items, active maintenance, alerts and actions for the event, escalation path, service impact. Note that `get_alerts_for_event` / `get_actions_for_event` do disclose the notification recipients and media addresses for that event. Fleet inventory, the media-type and action configuration, effective macro values, NetBox records, audit history, the service tree, bulk previews and report builders keep asking. |
| `all` | Every sensitive read runs immediately in the drawer, including the six `preview_*` bulk previews that are otherwise the operator's last look before a bulk write. |

The relaxation applies **only** to the drawer opened from a problem row, and only
when the server itself resolves that event through the same Zabbix identity the
reads will use. The full AI chat page always asks. **No write action can be
auto-approved at any level** — the write branch of the confirmation gate is
independent of this setting. Auto-approved reads are recorded as
`zabbix.sensitive_read.auto_confirmed` with the tool name and event ID, but only
if module logging is enabled (it is off by default); enable it on the Logging
tab if you want that trail.

Note that redaction masks hostnames, addresses, FQDNs, URLs and OS strings, but
not macro values, notification destinations, usernames, item keys, trigger
expressions or free text. At `all`, those reach the AI provider without a
per-call confirmation.


Reload the serving pool after changing this block:

```bash
sudo systemctl reload php-fpm
```

Keep the master key outside `ZABBIX_AI_SECRET_DIR`; that directory contains
only credentials which module settings may reference by logical name. Generic
environment references are likewise restricted to standard module names,
`ZABBIX_AI_SECRET_*`, or exact names in the server-side
`ZABBIX_AI_ALLOWED_SECRET_ENV_VARS` allowlist.

Use the identical key on every frontend node. Keep its authoritative copy and
backup in a durable encrypted source; `/run/zabbix-ai-master/...` is only a
separately materialized runtime copy and is not a backup. Inline secrets are
authenticated and stored as `enc:v1:...` ciphertext. If legacy plaintext values
exist, AI Settings first shows **Encryption ready — plaintext migration
pending**. Click **Save settings** once, reopen the page, and verify the green
**Secret storage: encrypted at rest** banner. Then untick the persisted
plaintext compatibility option and remove
`ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS` from the PHP environment, reload PHP-FPM,
and verify neither override is reported active. The direct
`ZABBIX_AI_ENCRYPTION_KEY` variable remains supported for existing deployments
but no longer needs to be placed as plaintext in the pool file.

On SELinux-enforcing hosts, label the read-only runtime secret/master-key paths
`httpd_sys_content_t` and run `restorecon` after each boot-time materialization;
reserve `httpd_sys_rw_content_t` for the separate writable state/log/report
paths. Apache mod_php and Docker/Compose variants are documented in
[ENCRYPTION.md](ENCRYPTION.md).

For isolated development only, a Super Admin can enable the warned **Allow
plaintext secrets** compatibility option in AI Settings (or use the legacy
`ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS=1` server override). This can expose
credentials in the database, dumps, backups, and exports. It lets the whole
module run with no encryption key — chat, host/problem context, tool calls,
confirmation previews, pending writes, sensitive reads and bulk previews — with
those confirmations stored unencrypted under the state path.

See [ENCRYPTION.md](ENCRYPTION.md) for the complete local-vault/reference setup,
Ansible-style example, database encryption, multi-node rules, rotation, and
troubleshooting.

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
# Determine the php-fpm worker user. On RHEL/Alma/Rocky with nginx + php-fpm
# this is usually "apache", not "nginx". Confirm with:
#   ps -eo user,comm | grep php-fpm
WEB_GROUP=apache   # or: nginx, www-data

# 02770 = setgid + rwx for the group, so new files inherit the group.
sudo install -d -o root -g $WEB_GROUP -m 02770 /tmp/zabbix-ai-module
sudo install -d -o root -g $WEB_GROUP -m 02770 /tmp/zabbix-ai-module/state
sudo install -d -o root -g $WEB_GROUP -m 02770 /tmp/zabbix-ai-module/state/pending
sudo install -d -o root -g $WEB_GROUP -m 02770 /tmp/zabbix-ai-module/logs
sudo install -d -o root -g $WEB_GROUP -m 02770 /tmp/zabbix-ai-module/archive
```

Note: `/tmp` directories may be cleared on reboot. This is fine for the default setup since redaction state is ephemeral and logs are optional.

### Using persistent paths (recommended for production)

For production, use a dedicated path:

```bash
WEB_GROUP=apache   # or: nginx, www-data — must match the php-fpm worker user

# Redaction state
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state/pending

# Logs and archives
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai/archive
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
- API key: preferably an `env:NAME` or `file:NAME` vault/secret reference; an inline value is encrypted in the database

The provider **Test connection** button can use a freshly entered inline key for
that one request even when no encryption key is configured; it is not saved.
Save a new/changed secret reference and provider destination before testing the
reference.

### Ollama
- Type: `ollama`
- Endpoint: `http://localhost:11434/api/chat`
- Model: e.g. `llama3.2:3b`

### Anthropic (Claude)
- Type: `anthropic`
- Endpoint: `https://api.anthropic.com` (or leave empty for default)
- Model: e.g. `claude-sonnet-4-20250514`
- API key: preferably an `env:NAME` or `file:NAME` vault/secret reference; an inline value is encrypted in the database

### Provider defaults

You can set different default providers for each purpose:
- **Default for chat** - chat turns where Zabbix Actions are disabled for that turn
- **Default for webhook** - automated webhook responses
- **Default for Zabbix actions** - every action-enabled chat turn, even if the model ultimately does not call a tool

An operator's explicit provider-selector choice overrides these defaults.

### Zabbix API

Logged-in chat/problem-page actions use Zabbix's internal frontend API path and the caller's permissions. Configure the service-token API URL and token for webhook/standalone automation:
- API URL must be an explicit HTTPS URL pointing to the Zabbix web frontend `api_jsonrpc.php`; it is never derived from the request host
- Token or `env:NAME`/`file:NAME` secret reference is required for webhook posting
- The token needs read permissions for read actions and write permissions for allowed write actions on the relevant Zabbix objects
- Interactive reads fail closed if the frontend identity is unavailable. Split/token-only deployments can explicitly enable the shared-token read fallback in AI Settings; this makes those reads use the token owner's scope.

### Zabbix Actions

In AI Settings > Zabbix Actions:
- **Enabled**: Allow AI to interact with Zabbix via natural language
- **Mode**: "Read only" (safe default) or "Read & Write"
- **Write permissions**: Enable the corresponding category shown in AI Settings
- **Require Super Admin for write**: Enabled by default
- **Web scenario allowed origins**: Add exact origins before enabling AI-created web checks; keep Zabbix server/proxy egress rules as the final outbound boundary

The module validates the origin and current DNS answers before creating a web
scenario, but the HTTP request is later made by a Zabbix server or proxy. Enforce
an outbound firewall/proxy policy on every executing server/proxy as the final
control against DNS rebinding and future address changes. Prefer exact origins;
use wildcard origins only for DNS zones fully controlled by your administrators.

Optional integrations:
- NetBox URL/token for confirmed interactive CMDB reads and optional webhook enrichment
- Webhook shared secret for internal webhook protection

The same test rule applies to NetBox: a freshly entered inline token is
request-local, while a new/changed `env:`/`file:` token reference and NetBox URL
must be saved before **Test connection** uses them.

## 9. Enable security / redaction

Security/redaction is **enabled by default** in the module config. When enabled, outbound AI requests can have hostnames, IPs, FQDNs, URLs, and OS hints replaced with safe aliases. Replies are restored locally before you see them. Enabled administrator reference-link URLs are deliberately inserted into the system prompt verbatim; never store credentials or signed/secret query parameters in them.

The configuration/history assistant shows a separate consent prompt before sending its displayed form/API context to the selected provider. That context can include preprocessing JavaScript, interface addresses, item/history values, triggers, non-secret macro values, and recent problem metadata. Secret/vault macros are masked. The untrusted-data fence is an instruction boundary, not a substitute for data minimization or configured redaction.

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
https://<your-zabbix-frontend>/ai-webhook
```

Media type files are included under `mediatype/`. This standalone endpoint does not require a Zabbix frontend session. Configure the nginx mapping in section 14 before importing the media type, and keep the shared secret enabled. Do not enable the Zabbix Guest user or grant Guest access to the module for webhook delivery.

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
- non-Guest `USER_TYPE_ZABBIX_USER` or higher for chat

### Write actions denied for a user
Write actions require:
1. Zabbix Actions mode set to "Read & Write"
2. The corresponding write category enabled in AI Settings
3. Super Admin role (if "Require Super Admin for write" is checked)

### AI does not execute Zabbix actions
Check:
- Zabbix Actions is enabled in settings
- For logged-in chat, the request is running inside a valid Zabbix frontend session
- For webhook/standalone or an explicitly enabled split-deployment fallback, an HTTPS Zabbix API URL and token are configured
- The current frontend user or API token has sufficient permissions
- **Ollama provider only:** keep the per-provider "Context window (Ollama)" at least ~8000. Ollama's native default `num_ctx=2048` is often too small for the policy, conversation context, monitoring evidence, and native tool schemas. The module defaults to 16384; raise a customized lower value if the model stops selecting tools. Symptom: the "Tools" tab in **Monitoring > AI > Logs** stays at 0 and the AI replies "I cannot do it" to questions like "list all active problems".
- The provider/model must support native tool calling and select functions reliably. Small models may ignore or misuse a large tool catalog; try a tool-capable `llama3.1:8b`, `qwen2.5:7b`, or larger model to confirm.

### Logging shows no entries / log directory does not exist

1. **Is logging enabled?** It is disabled by default. Go to AI Settings > Logging and check "Enable logging".
2. **Are log categories selected?** At least one category must be checked (chat, webhook, reads, writes, etc.).
3. **Can the web process write to the log path?** Check with: `sudo -u nginx ls -la /tmp/zabbix-ai-module/logs/` (replace `nginx` with your web server user). If "No such file or directory", create the directories per section 6.
4. **Is PrivateTmp enabled?** If `systemctl show php-fpm | grep PrivateTmp` shows `yes`, the web process uses a private `/tmp`. Use a persistent path like `/var/log/zabbix-ai/` instead (see section 6).
5. **Is SELinux blocking writes?** Check `ausearch -m avc -ts recent` for denials. Apply the SELinux context per section 6.

### The model prints tool-call JSON instead of calling a tool

JSON-looking assistant text is deliberately never executable. If the model prints `{"tool": ...}` instead of issuing a native call:
1. Confirm the provider and model implement native tool/function calling; Actions cannot be used with a prose-only model.
2. For Ollama, increase `num_ctx` and use a model version with tool support.
3. If using custom replacement rules, make sure they do not rewrite operation names in the conversation or policy text.

### Static assets not loading
Check web server file permissions and SELinux context.

### API calls fail
Check:
- frontend server can reach provider URL / Ollama URL / Anthropic URL / NetBox URL
- TLS validation setting matches the endpoint certificate state
- token references resolve for php-fpm/httpd (`env:NAME` is visible, or `file:NAME` exists under `ZABBIX_AI_SECRET_DIR`)

## 13. Notes on chat storage

Chat history is stored in browser `sessionStorage` only. The module does not create tables and does not persist chat server-side.

## 14. Nginx conf

The bundled media type uses the standalone webhook endpoint. Verify this is in `/etc/nginx/conf.d/zabbix.conf`:

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
- directories: `02770` (setgid + rwx for owner and group — the setgid bit makes new files inherit the group automatically, so future log files stay writable)
- files: `0640`
- owner: `root`
- group: the **php-fpm worker user**, which on RHEL/Alma/Rocky with nginx + php-fpm is usually `apache`, not `nginx`. Confirm with `ps -eo user,comm | grep php-fpm`.

Example:

```bash
WEB_GROUP=apache   # match the php-fpm worker user
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/lib/zabbix-ai/state
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai
sudo install -d -o root -g $WEB_GROUP -m 02770 /var/log/zabbix-ai/archive
```

### SELinux for writable custom paths on RHEL

Module code under `/usr/share/zabbix/modules/AI` should stay `httpd_sys_content_t`, but custom writable state/log paths must allow web writes.

Example:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-ai /var/log/zabbix-ai
```
