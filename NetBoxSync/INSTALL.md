# Installation

## 1. Copy the module

Copy the `NetBoxSync` directory into your Zabbix frontend modules directory:

```bash
sudo cp -a NetBoxSync /usr/share/zabbix/modules/
```

## 2. Identify the PHP-FPM pool user

**This is the #1 source of "not writable" errors.** On a stock RHEL/Alma/
Rocky 9 install with **nginx + Zabbix**, the nginx server itself runs as
`nginx`, but the Zabbix PHP-FPM pool is configured to run as **`apache`**
(see `/etc/php-fpm.d/zabbix.conf`). Every chown/install command below must
use the PHP-FPM pool user — not the nginx user.

Check which user PHP actually runs as:

```bash
grep -E '^(user|group)\s*=' /etc/php-fpm.d/zabbix.conf
# user = apache
# group = apache
```

Export that name as `$OWNER` for the rest of the steps. Common values:

- RHEL/Alma/Rocky + Zabbix packages: `apache`
- Debian/Ubuntu + nginx: `www-data`
- Custom setups: whatever `user =` says in the PHP-FPM pool config

```bash
OWNER=apache       # <-- replace with the PHP-FPM pool user
```

## 3. Set module ownership, permissions, and SELinux context

Keep the module source owned by root. The PHP-FPM and CLI service account only
needs to read it:

```bash
sudo chown -R root:root /usr/share/zabbix/modules/NetBoxSync
sudo find /usr/share/zabbix/modules/NetBoxSync -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/NetBoxSync -type f -exec chmod 644 {} \;
sudo chmod 755 /usr/share/zabbix/modules/NetBoxSync/bin/netboxsync.php

# SELinux (RHEL/Alma/Rocky/Fedora)
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/NetBoxSync(/.*)?'
sudo restorecon -Rv /usr/share/zabbix/modules/NetBoxSync
sudo setsebool -P httpd_can_network_connect on
```

## 4. Pre-create the runner state and log directories

`/var/lib` and `/var/log` are not writable by nginx/apache, so the module
cannot create the defaults itself. Pre-create them with ownership matching
the PHP-FPM pool user from step 2:

```bash
sudo install -d -o "$OWNER" -g "$OWNER" -m 0770 /var/lib/zabbix-netbox-sync/state
sudo install -d -o "$OWNER" -g "$OWNER" -m 0770 /var/log/zabbix-netbox-sync

# SELinux (read AND write)
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-netbox-sync(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-netbox-sync(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-netbox-sync /var/log/zabbix-netbox-sync
```

Verify the result matches the PHP-FPM pool user:

```bash
ls -ld /var/lib/zabbix-netbox-sync/state /var/log/zabbix-netbox-sync
# drwxrwx--- 2 apache apache ...  <-- owner must match /etc/php-fpm.d/zabbix.conf
```

If you change these paths on the settings page, re-run the same `install`,
`semanage`, and `restorecon` commands against the new paths.

Typical symptom if you chown'd to the wrong user:

> Path "/var/lib/zabbix-netbox-sync/state" is not writable as the PHP process
> user "apache". The directory is owned by "nginx", but PHP is running as
> "apache" — on RHEL/Alma/Rocky the PHP-FPM pool normally runs as "apache",
> not "nginx".

## 5. Enable the module in Zabbix

- Go to **Administration → General → Modules**
- Find **NetBox Sync**
- Enable it

## 6. Open the settings page

- Go to **Monitoring → NetBox Sync → Settings**

## 7. Configure NetBox

On the settings page, set:

- NetBox base URL
- NetBox API token, or a `token_env` pointing to an environment variable

Then configure **Zabbix API for unattended sync**:

- Create a dedicated, enabled Zabbix service user.
- Give its user group read access to every host group that NetBox Sync should
  see.
- Give its role API access to `host.get`, `item.get`, and
  `hostinterface.get` (or a broader read-only API allow-list).
- Create an API token for that user.
- Enter the full endpoint, for example
  `https://zabbix.example.com/api_jsonrpc.php`.
- Enter the API token, or set **Token environment variable** to
  `NETBOXSYNC_ZABBIX_API_TOKEN` and provide it to the service in step 9.

Click **Test Zabbix API** when the token is stored in the settings or exposed
to PHP-FPM. Environment variables defined only in the systemd environment file
are tested later with `--check`.

The legacy shared secret is not needed by the CLI worker. It does not turn the
frontend action into an anonymous endpoint because Zabbix performs module
access checks before that action can run.

## 8. Save settings and test a manual run

Click **Run now** on the settings page. The first successful run populates:

- `state/timestamps.json`
- `state/last_summary.json`
- `events/YYYY-MM-DD.jsonl` (powers the new **Log** page)

Then open **Monitoring → NetBox Sync → Log** to review Added / Changed /
Removed / Errors.

## 9. Add a scheduler

Use cron or a systemd timer. The bundled CLI worker reads the saved module
settings directly from the Zabbix frontend database, then authenticates to the
Zabbix API with the dedicated token. It does not use a browser cookie or an
active frontend session.

The PHP CLI runtime needs the cURL extension and the PDO driver matching your
Zabbix database (`pdo_mysql` or `pdo_pgsql`). It must also be able to read the
Zabbix frontend `zabbix.conf.php` file.

Ready-to-use examples ship in `samples/`:

- `samples/systemd/netboxsync.service` and `samples/systemd/netboxsync.timer`
- `samples/netboxsync-run.sh` — an optional direct CLI wrapper for cron.

Cron alternative when the API tokens are stored in module settings (install
this in the `$OWNER` user's crontab):

```bash
*/15 * * * * /bin/sh /usr/share/zabbix/modules/NetBoxSync/samples/netboxsync-run.sh --json
```

For environment-backed tokens, use the systemd service below or arrange a
protected cron environment for the `$OWNER` account.

### 9a. systemd timer (recommended)

Install the two unit files from `samples/systemd/` into
`/etc/systemd/system/` as `root:root`:

```bash
sudo install -o root -g root -m 0644 \
    samples/systemd/netboxsync.service /etc/systemd/system/netboxsync.service
sudo install -o root -g root -m 0644 \
    samples/systemd/netboxsync.timer   /etc/systemd/system/netboxsync.timer
```

Create the environment file referenced by the service
(`EnvironmentFile=/etc/sysconfig/zabbix-netbox-sync`). It contains API tokens,
so it must be readable only by root. The environment variable name must match
the **Token environment variable** field saved in module settings:

```bash
sudo install -o root -g root -m 0600 /dev/null /etc/sysconfig/zabbix-netbox-sync
sudo tee /etc/sysconfig/zabbix-netbox-sync >/dev/null <<'EOF'
NETBOXSYNC_ZABBIX_API_TOKEN=<zabbix-service-user-api-token>
# NETBOX_API_TOKEN=<netbox-api-token>
# ZABBIX_WEB_CONFIG=/etc/zabbix/web/zabbix.conf.php
# NETBOXSYNC_ZABBIX_DB_USER=zabbix
# NETBOXSYNC_ZABBIX_DB_PASSWORD=<database-password>
EOF
sudo chmod 0600 /etc/sysconfig/zabbix-netbox-sync
```

`ZABBIX_WEB_CONFIG` is optional when the worker finds a standard frontend
configuration path automatically. Set it when your `zabbix.conf.php` lives
elsewhere. The two `NETBOXSYNC_ZABBIX_DB_*` variables are needed only when the
frontend configuration delegates its database credentials to Vault; the
standalone worker cannot call the frontend's Vault provider.

Before enabling the timer, run a non-writing configuration/API check as the
same account and with the same environment as the service. This root shell
loads the root-only environment file, then drops privileges for the PHP worker
(use `/etc/default/zabbix-netbox-sync` in this command on Debian/Ubuntu):

```bash
sudo env OWNER="$OWNER" /bin/sh -c '
  set -a
  . /etc/sysconfig/zabbix-netbox-sync
  set +a
  exec runuser -u "$OWNER" --preserve-environment -- \
    /usr/bin/php /usr/share/zabbix/modules/NetBoxSync/bin/netboxsync.php --check --json
'
```

If the Zabbix API token is stored in module settings instead, the shorter
`sudo -u "$OWNER" /usr/bin/php .../bin/netboxsync.php --check --json` command
is sufficient.

On Debian/Ubuntu use `/etc/default/zabbix-netbox-sync` instead and update
the `EnvironmentFile=` line in `netboxsync.service` to match.

Summary of file placement and permissions:

| File                                       | Owner       | Mode   |
| ------------------------------------------ | ----------- | ------ |
| `/etc/systemd/system/netboxsync.service`   | `root:root` | `0644` |
| `/etc/systemd/system/netboxsync.timer`     | `root:root` | `0644` |
| `/etc/sysconfig/zabbix-netbox-sync`        | `root:root` | `0600` |

Reload systemd and enable the timer:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now netboxsync.timer
systemctl list-timers netboxsync.timer
sudo systemctl start netboxsync.service      # first real one-shot run
journalctl -u netboxsync.service -n 50 --no-pager
```

The sample service uses `User=apache` and `Group=apache`. Change both before
installing it if `$OWNER` is different. It also restricts writes to the default
state/log paths; update `ReadWritePaths=` if you changed those settings.

## 10. Optional: enable listening-services sync

Deploy the matching Zabbix plugin/template first:

- Linux: <https://github.com/Keberneth/Zabbix-Plugins/tree/main/Linux/linux_service_listening_port>
- Windows: <https://github.com/Keberneth/Zabbix-Plugins/tree/main/Windows/windows_service_listening_port>

Then enable:

- `services[enabled]`
- built-in sync `vm_services`

## 11. Optional: enable device sync

Configure:

- device name source
- manufacturer source
- model source
- serial source
- default site / role / status

Then enable:

- `device[enabled]`
- built-in sync `device_object`
- optionally `device_serial`

## Troubleshooting

- **`is not writable as the PHP process user "X". The directory is owned by
  "Y"`** — classic nginx-vs-apache mismatch. The error itself tells you who
  PHP runs as (`X`) and who owns the folder (`Y`). Re-run step 4 with
  `OWNER=X`.
- **`could not be created`** — parent (`/var/lib` or `/var/log`) is not
  writable by the PHP user; pre-create per step 4.
- **Error text says "the PHP-FPM pool user"** — PHP's `posix` extension is
  disabled, so the module could not auto-detect the user. Install it
  (`sudo dnf install php-process`) or read `user =` from
  `/etc/php-fpm.d/zabbix.conf` manually.
- **`avc denied` in `/var/log/audit/audit.log`** — SELinux context is missing;
  re-run the `semanage fcontext` + `restorecon` commands from step 4.
- **`NetBox HTTP 403`** — check the NetBox token and that
  `httpd_can_network_connect` SELinux boolean is on.
- **`zabbix_api.token is required`** — save a token in the settings, or make
  sure the configured token environment variable is present in the CLI service
  environment.
- **`PDO driver ... is not installed`** — install the PHP CLI PDO extension for
  your Zabbix database (`php-mysqlnd`/`pdo_mysql` or
  `php-pgsql`/`pdo_pgsql`, depending on the distribution).
- **API check succeeds but returns zero hosts** — grant the service user's
  group read access to the relevant Zabbix host groups.
- **Frontend config is not readable** — set `ZABBIX_WEB_CONFIG` to the correct
  `zabbix.conf.php` path and ensure the service account can read it.
- **Vault-backed database configuration** — set
  `NETBOXSYNC_ZABBIX_DB_USER` and `NETBOXSYNC_ZABBIX_DB_PASSWORD` in the
  root-only service environment file; the standalone worker does not resolve
  frontend Vault providers itself.
