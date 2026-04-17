# NetBox Sync module for Zabbix 7

This frontend module adds a configurable NetBox sync page under **Monitoring → NetBox Sync → Settings**.

## What it does

- Syncs Zabbix hosts into NetBox VMs using the logic from the current `sync_zabbix_netbox.py` script:
  - create/update VM
  - operating system + EOL custom fields
  - Microsoft SQL Server license custom field
  - virtual disks
  - interfaces
  - primary IPv4 assignment
- Optionally syncs listening services into NetBox `ipam/services` using the Zabbix listening-service plugins/templates.
- Adds a new device flow for NetBox devices:
  - create/update device
  - resolve or create manufacturer
  - resolve or create device type
  - patch serial
- Exposes every built-in mapping on the settings page with:
  - enabled/disabled toggle
  - Zabbix source description
  - NetBox target description
  - per-sync interval override
- Supports reusable **custom mappings** so you can add more syncs without changing code:
  - direct field patch
  - relation lookup
  - ensure device type

## Module structure

- `manifest.json`
- `Module.php`
- `actions/`
- `views/`
- `lib/`
- `assets/`
- `samples/`

## Service-sync dependency

If you enable the built-in **Listening services** sync, the matching Zabbix plugin/template must be deployed:

- Linux: `https://github.com/Keberneth/Zabbix-Plugins/tree/main/Linux/linux_service_listening_port`
- Windows: `https://github.com/Keberneth/Zabbix-Plugins/tree/main/Windows/windows_service_listening_port`

The default item names expected by this module are:

- `Listening Services JSON`
- `Linux Listening Services JSON`

## Scheduler model

A Zabbix frontend module does not run on its own.  
This module therefore includes a secure runner action:

- `zabbix.php?action=netboxsync.run`

Use that runner with cron or a systemd timer.  
Examples are included in `samples/`.

## Secrets

The module supports both:

- stored secrets in module settings
- environment-variable secrets, similar to the AI module pattern

Supported env settings:

- `netbox[token_env]`
- `runner[shared_secret_env]`

The module reads Zabbix hosts, items, and interfaces directly through the built-in `API::` facade inside the Zabbix frontend, so no Zabbix URL or token is required.

## Filesystem permissions

The web/PHP user must be able to write to:

- runner state path (default `/var/lib/zabbix-netbox-sync/state`)
- runner log path (default `/var/log/zabbix-netbox-sync`)

**Pre-create both directories before the first run.** `/var/lib` and
`/var/log` are not writable by nginx/apache, so the module cannot create them
itself. `OWNER` must be the **PHP-FPM pool user**, not the nginx user —
check `/etc/php-fpm.d/zabbix.conf` (on RHEL/Alma/Rocky with Zabbix packages
this is usually `apache`, even when the HTTP server is nginx):

```bash
sudo install -d -o "$OWNER" -g "$OWNER" -m 0770 /var/lib/zabbix-netbox-sync/state
sudo install -d -o "$OWNER" -g "$OWNER" -m 0770 /var/log/zabbix-netbox-sync
```

On SELinux systems (RHEL/Alma/Rocky/Fedora), also run:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-netbox-sync(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-netbox-sync(/.*)?'
sudo restorecon -Rv /var/lib/zabbix-netbox-sync /var/log/zabbix-netbox-sync
```

See `INSTALL.md` for full step-by-step instructions.

## Log page

**Monitoring → NetBox Sync → Log** shows structured sync events in an
Excel-style grid with four tabs: Added, Changed, Removed, Errors. Stack
facets (Host, OS, Target, Sync, Field, Disk) and per-column filters to
isolate changes — e.g. filter OS = `Windows Server 2019` + `Windows Server
2022`, then Sync = `vm_disks`, to see every Windows 2019/2022 disk delta.

## Notes

- End-of-life lookup uses `endoflife.date`, like the current VM sync script logic.
- Device-type matching is intentionally flexible and can reuse existing NetBox device types even when the Zabbix model item contains a friendly string with a code in parentheses, for example `FortiGate 100F (FG100F)`.
