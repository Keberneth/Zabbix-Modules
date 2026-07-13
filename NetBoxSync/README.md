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
- `bin/`
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

A Zabbix frontend module does not run on its own. The recommended unattended
path is the bundled CLI worker:

```bash
php /usr/share/zabbix/modules/NetBoxSync/bin/netboxsync.php --json
```

It reads this module's settings from the Zabbix frontend database and reads
hosts through the Zabbix JSON-RPC API using a dedicated API token. It therefore
works under systemd or cron with no logged-in user and no browser session.

The frontend `netboxsync.run` action remains available for interactive use,
but it is not an anonymous scheduler endpoint: Zabbix must load the module for
an authenticated user before the controller can run. Use the CLI worker for
session-free scheduling. Ready-to-use systemd and cron examples are included
in `samples/`.

## Secrets

The module supports both:

- stored secrets in module settings
- environment-variable secrets, similar to the AI module pattern

Supported env settings:

- `netbox[token_env]`
- `zabbix_api[token_env]`
- `runner[shared_secret_env]`

Interactive **Run now** requests use the logged-in Zabbix frontend session.
Unattended CLI runs use `zabbix_api[url]` plus a dedicated Zabbix API token, so
they are independent of frontend sessions.

## Filesystem permissions

The PHP-FPM user and unattended CLI service account must be able to write to:

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

## Configuration reference

All settings live on **Monitoring → NetBox Sync → Settings**, grouped into cards.
Secrets (NetBox token, Zabbix API token, runner shared secret) are never echoed
back to the page — leave the field blank to keep the stored value, or tick
*Clear* to remove it.

### Connections → NetBox

| Field | Meaning |
| --- | --- |
| Enabled | Master switch for all NetBox writes. A run aborts early if this is off. |
| Base URL | NetBox root, e.g. `https://netbox.example.com` (a trailing `/api` is added automatically). |
| API token | NetBox API token. Prefer `Token environment variable` over storing it here. |
| Token environment variable | Name of an env var read at runtime (overrides the stored token when set). |
| Verify TLS | Validate the NetBox certificate (turn off only for self-signed lab setups). |
| Timeout | Per-request cURL timeout in seconds (5–300). |
| **Test connection** | Runs a single `GET /status/` using the values currently in the form (blank fields fall back to the stored config) and reports success or a generic failure. |

### Connections → Zabbix API for unattended sync

| Field | Meaning |
| --- | --- |
| API endpoint | Full JSON-RPC URL, for example `https://zabbix.example.com/api_jsonrpc.php`. |
| API token | Token for a dedicated Zabbix service user with read access to the host groups being synchronized. |
| Token environment variable | Name of an env var read at runtime; this overrides the stored token when it is present. |
| Verify TLS | Validate the Zabbix frontend certificate. Disable only for a controlled lab setup. |
| Timeout | Per-request cURL timeout in seconds (5–300). |
| **Test Zabbix API** | Verifies the endpoint and token with a bounded `host.get` request. For environment-only systemd credentials, use the CLI `--check` command from that service account. |

### Runner and scheduling

| Field | Meaning |
| --- | --- |
| Runner enabled | Allow unattended CLI runs and the legacy frontend trigger. |
| Global interval | Default seconds between runs of each sync/mapping unless it overrides it. |
| Default prefix length | Mask used when creating a NetBox prefix for a discovered primary IP. |
| Max hosts per run | 0 = all (capped at 50000). Otherwise processes at most this many hosts. |
| Shared secret / env var | Legacy frontend-trigger secret. It is not used by the CLI worker and does not bypass Zabbix's requirement that the module action be loaded for an authenticated user. |
| Lock TTL | Advisory lock lifetime preventing overlapping runs. |
| State path / Log path | Writable directories for run state and the structured event log (see Filesystem permissions). |

### Built-in sync catalogue
Each row mirrors one step of the original sync scripts (VM base object, OS/EOL,
SQL license, disks, interfaces, primary IP, listening services, device object,
device serial). Toggle rows independently and optionally set a per-row interval
override (`0` = use the global interval).

### VM sync defaults
Item keys/names used to read OS, CPU, memory, SQL version, disks, and interfaces
from Zabbix, plus VM creation policy (create/update/require-OS), the NetBox
memory/disk **unit** (MB vs GB — NetBox 4.3+ stores disk size in GB), and
prune toggles for stale disks/interfaces.

### Listening-services sync
Enables the `vm_services` row. Point `Windows item name` / `Linux item name`
at the items populated by the listening-service plugins/templates.

### Device sync defaults
Device creation policy and the source (mode + value) for device name,
manufacturer, model, and serial, plus auto-create toggles for missing
manufacturers and device types.

### Custom mappings
Reusable, code-free syncs: pick a source (item key/name, static, host name,
agent IP), an optional JSON path + transform, a target object (VM / Device /
Custom URL) and target field. Advanced options add a host-name regex gate
(evaluated under ReDoS guards), relation lookups, and ensure-if-missing logic.

## Notes

- End-of-life lookup uses `endoflife.date`, like the current VM sync script logic.
- Device-type matching is intentionally flexible and can reuse existing NetBox device types even when the Zabbix model item contains a friendly string with a code in parentheses, for example `FortiGate 100F (FG100F)`.
