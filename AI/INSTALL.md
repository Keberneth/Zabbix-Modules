# Install the Zabbix AI module

The normal setup is: copy the module, run the Linux setup helper once, enable
the module, and enter a provider key. The helper creates a protected master
key so API keys entered in Settings are encrypted in the Zabbix database.
No separate vault service or database migration is required.

## 1. Copy the module

Copy the complete `AI` directory into the frontend's modules directory:

```text
/usr/share/zabbix/modules/AI/manifest.json
```

Use your actual frontend root if different. Deploy the same release to every
frontend node; mixed PHP/JavaScript releases are rejected by the settings-save
version check. The helper keeps code root-owned and read-only to PHP, with
separate writable directories for runtime data.

## 2. Prepare the PHP worker

Choose the PHP-FPM pool that **serves Zabbix**, not the nginx worker account.
On RHEL-family installations it is often an `apache` pool even with nginx.
Find the pool file in `/etc/php-fpm.d/` or `/etc/php/<version>/fpm/pool.d/`.
The helper reads its `user` and `group`; it refuses conflicting overrides.

For example, if Zabbix uses `/etc/php-fpm.d/www.conf`:

```bash
sudo bash /usr/share/zabbix/modules/AI/bin/setup.sh \
  --fpm-pool-config /etc/php-fpm.d/www.conf --apply-fpm --dry-run

sudo bash /usr/share/zabbix/modules/AI/bin/setup.sh \
  --fpm-pool-config /etc/php-fpm.d/www.conf --apply-fpm
```

The helper:

- sets module directories to `root:root 0755` and files to `root:root 0644`;
- creates `/var/lib/zabbix-ai/state`, `state/pending` and `reports`, plus
  `/var/log/zabbix-ai` and its `archive` directory, writable by the PHP group;
- generates `/etc/zabbix-ai/master.key` only when absent, with 32 random bytes
  encoded as hex, owned by root and readable by the PHP group (`0640`);
- with `--apply-fpm`, adds `zz-zabbix-ai-<pool>.conf` beside the selected pool
  file containing only the key path. That directory must already be included
  by PHP-FPM's main configuration.

It never replaces an existing key, overwrites a conflicting drop-in, or
restarts services. Symlinked/hard-linked module trees and symlinked runtime
paths are refused. Re-running the helper is safe. For multiple pool sections,
add `--fpm-pool <name>`. Without `--apply-fpm`, it prints the pool snippet for
manual installation. Existing custom master-key configuration is retained;
omit `--apply-fpm` and follow [ENCRYPTION.md](ENCRYPTION.md) in that case.

**Back up the master key securely.** Keep a protected backup separate from
database-only backups. On multiple frontend nodes, provision the **same** key
before running setup on each node; do not generate a different key per node.
Changing or losing it prevents decryption of existing inline secrets.

Validate the serving PHP-FPM configuration and reload its service. For RHEL's
default service (adjust both commands on Debian/Ubuntu or custom pools):

```bash
sudo php-fpm -t
sudo systemctl reload php-fpm
```

PHP needs cURL, JSON, mbstring, and either Sodium or OpenSSL for encrypted
storage. CLI `php -m` is only a first check; Settings checks the PHP process
actually serving the frontend.

### SELinux hosts

Keep SELinux enabled. Apply these labels once using your actual module path:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/AI(/.*)?'
sudo semanage fcontext -a -t httpd_sys_content_t '/etc/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/AI /etc/zabbix-ai /var/lib/zabbix-ai /var/log/zabbix-ai
sudo setsebool -P httpd_can_network_connect on
```

If an exact `fcontext` rule already exists, use `-m` instead of `-a` for that
rule. The network boolean permits the PHP process to contact providers and
integrations. Read-only key/code labels are separate from writable data labels.

### Containers, mod_php and custom deployments

The helper requires Bash and standard Linux administration tools including
OpenSSL. With mod_php, use `--php-user <worker-user> --php-group <worker-group>`
and expose `ZABBIX_AI_ENCRYPTION_KEY_FILE=/etc/zabbix-ai/master.key` to the
serving SAPI yourself. It does not edit Apache configuration.

For containers, mount the module and key read-only and mount the data paths as
writable persistent volumes. Set the key path in the PHP process environment.
Run provisioning on persistent storage, not just a disposable container layer.
Chroot pools need matching paths inside the chroot and manual configuration.
See [ENCRYPTION.md](ENCRYPTION.md) for mod_php, containers and secret references.

## 3. Enable and configure in Zabbix

1. Open **Administration → General → Modules**, scan the directory and enable AI.
2. As a **Super Admin**, open **Monitoring → AI → Settings**. Settings and Logs
   navigation are restricted to Super Admins; ordinary permitted users see Chat.
3. Add a provider, choose its type/model, and enter the API key. Save settings.
   The key field stays blank on later visits; a stored-secret indicator confirms
   it exists. `env:NAME` and `file:NAME` remain optional alternatives.
4. Use **Test connection**, then **Test model and tools** if you will use actions.
   The latter sends two small synthetic requests to test the selected model.
5. Reopen Settings and verify **Setup checks passed**. A **Setup needs attention**
   status provides details from the PHP process actually serving Zabbix.
6. Keep **Read only** mode initially. Signed-in chat uses the caller's Zabbix
   permissions and needs no separate Zabbix API token.

New installations use these paths. Upgrades preserve existing saved paths;
update them in Settings when moving from `/tmp` or another location:

| Setting | Path |
|---|---|
| Security state | `/var/lib/zabbix-ai/state` |
| Reports directory | `/var/lib/zabbix-ai/reports` |
| Log directory | `/var/log/zabbix-ai` |
| Archive directory | `/var/log/zabbix-ai/archive` |

New installations enable audit metadata with message payloads and alias
mappings off. An existing saved logging choice is preserved. Existing
conversations and staged confirmations should be finished before changing
their state directory; changing the configured path does not migrate files.

HTTPS is the default for remote credentials. An administrator can select
**Allow HTTP for this provider (unencrypted connection)** or the equivalent
NetBox option when an internal HTTP endpoint is needed. This does not encrypt
its network traffic. Leave certificate validation enabled for HTTPS endpoints.

The explicit plaintext compatibility option remains available for deliberately
keyless setups, including staged confirmations. It is optional and does not
provide encryption at rest. See [ENCRYPTION.md](ENCRYPTION.md) for its scope,
references, migration and recovery.

## 4. Optional integrations

**AI data preview:** set **Data preview visibility** under **AI Settings > Chat** to off (default), Super Admin
only, or all authenticated users with module access. The optional before-send
preview prepares the initial redacted provider request without calling AI or
executing tools. After sending, request snapshots show what was sent during
that turn; later tool-result requests are not part of the initial preview.
Credentials, HTTP headers, administrator system instructions, private references
and tool definitions are excluded from every preview. Masked runtime context is
shown separately. Snapshots remain in page memory rather than chat history or
browser storage; normal audit logging remains controlled separately. Status and
truncation markers distinguish prepared/attempted requests and bounded views.

**Write actions:** enable Read & Write and only the required categories. User
permissions, server-side previews and confirmation still apply; keep the Super
Admin restriction unless you intend other roles to perform writes. HTTP web
checks additionally require allowed origins and appropriate egress policy on
the Zabbix server/proxies.

**Problems drawer:** the privacy-confirmation level can be changed in Settings.
`triage` permits the existing event-scoped troubleshooting subset, including
event notification recipients; `all` also includes broad inventory, macro and
other sensitive reads. These policies affect only the event-bound drawer.
Writes always require confirmation. Auto-approved results may remain in chat
history, so choose the scope deliberately.

**NetBox:** configure its endpoint and token/reference. Interactive retrieval
uses confirmed tools scoped to hosts visible through the Zabbix identity.

**Webhook:** new installations leave this disabled; enable/configure it when needed. It requires a
shared secret and a Zabbix automation token to post event comments. The token
belongs to a dedicated account with only the necessary object permissions.
The Zabbix API URL is the frontend's explicit HTTPS `api_jsonrpc.php` address.
The webhook never needs a Guest account or frontend login.

For nginx, map the exact endpoint to the standalone script using your actual
frontend path and PHP-FPM socket:

```nginx
location = /ai-webhook {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /usr/share/zabbix/modules/AI/webhook.php;
    fastcgi_pass unix:/run/php-fpm/zabbix.sock;
}
```

Validate and reload nginx after adapting the mapping. Import the supplied
media type and follow [mediatype/media_type_setup.md](mediatype/media_type_setup.md).
Zabbix Vault macros remain supported on the sending side. Secret-text macros
hide the displayed value but do not encrypt it in the Zabbix database.

The standalone endpoint uses Zabbix's database initialization, including its
TLS/Vault configuration. If the frontend root cannot be detected, set the
server-managed `ZABBIX_AI_FRONTEND_ROOT` to its absolute path. Re-import the
bundled media type on upgrade so retries, recoveries and individual event
updates carry the correct delivery identity.

Webhook delivery state lives under `state/webhook`. Defaults allow 30 new
deliveries/minute, two active calls, a 64 KiB request and a 24-hour deduplication
window. Completed retries return success without repeating AI calls/comments.
An ambiguous provider/posting failure remains uncertain until the window
expires: inspect the audit log and event before trying again. Multiple
frontends need shared storage with reliable `flock`, or route webhook traffic
to one frontend. The request-size ceiling is 1 MiB even if a higher limit is set.

## Checks and troubleshooting

Recheck filesystem provisioning without changing files:

```bash
sudo bash /usr/share/zabbix/modules/AI/bin/setup.sh \
  --fpm-pool-config /etc/php-fpm.d/www.conf --apply-fpm --check
```

This checks disk ownership and worker access. Reopen Settings to verify the
serving PHP extensions, environment and actual runtime paths.

- **Encryption unavailable:** confirm the serving pool reads the drop-in and
  was reloaded. Check key readability and Sodium/OpenSSL in that PHP process.
- **Legacy plaintext remains:** after configuring the key, save settings once.
  Existing database backups still contain their original values.
- **Permission denied:** confirm the pool user/group, filesystem mounts and
  SELinux labels. Do not make the module code writable or disable PrivateTmp.
- **Provider works but actions do not:** use a model with native tool calling;
  for Ollama, keep enough context for tool definitions (default 16384).
- **Page not found or old assets:** verify the complete module directory,
  enabled module, matching release on every node and PHP OPcache/browser cache.
- **Settings access denied:** Settings is intentionally Super Admin only.

Chat history stays in the browser session; the module creates no chat table.
Local alias state, reports and confirmations use the protected runtime paths.
