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

Using the `$OWNER` you identified in step 2:

```bash
sudo chown -R "$OWNER:$OWNER" /usr/share/zabbix/modules/NetBoxSync
sudo find /usr/share/zabbix/modules/NetBoxSync -type d -exec chmod 755 {} \;
sudo find /usr/share/zabbix/modules/NetBoxSync -type f -exec chmod 644 {} \;

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

No Zabbix URL or token is needed. The module reads Zabbix data through the
built-in frontend API facade, same pattern as the AI module.

On the settings page, set:

- NetBox base URL
- NetBox API token, or a `token_env` pointing to an environment variable
- (optional) runner shared secret for cron triggers

## 8. Save settings and test a manual run

Click **Run now** on the settings page. The first successful run populates:

- `state/timestamps.json`
- `state/last_summary.json`
- `events/YYYY-MM-DD.jsonl` (powers the new **Log** page)

Then open **Monitoring → NetBox Sync → Log** to review Added / Changed /
Removed / Errors.

## 9. Add a scheduler

Use cron or a systemd timer; examples live in `samples/`. The runner URL is:

```
https://<zabbix>/zabbix.php?action=netboxsync.run
```

Calls must include the shared secret header
`X-NetBox-Sync-Secret: <shared_secret>`.

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
