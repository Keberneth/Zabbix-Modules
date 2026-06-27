# Healthcheck module for Zabbix 7

This module adds:

- **Monitoring → Healthcheck → Heartbeat** for the latest status per configured check
- **Monitoring → Healthcheck → History** for recent runs and aggregate statistics
- **Monitoring → Healthcheck → Settings** for configuring one or more checks
- A **CLI runner** that can be executed from `systemd` or `cron`

It supports single-server, multi-server, and Docker deployments. Settings are stored in
the Zabbix `module` table so every frontend node shares the same configuration.

## What a check does

Each configured check performs the same sequence as the supplied health-check script:

1. Verify that the Zabbix API answers `apiinfo.version`
2. Count monitored hosts
3. Count active enabled problem triggers
4. Count enabled items
5. Inspect the freshest item data timestamp across monitored items
6. Send the success ping to the configured healthcheck URL

If one step fails, the run is marked as failed and the ping is not sent.

## Ping if working

If the health checks return OK, a ping can be sent. We recommend https://healthchecks.io/.

healthchecks.io can be configured to raise a problem if a ping is not received within a
specified time period. It can be integrated with multiple services, for example PagerDuty,
GitHub, Microsoft Teams, Slack and more.

This raises a problem if there is any issue with Zabbix or with the internet connection
from Zabbix.

## Installation

1. Copy the `Healthcheck` directory to your Zabbix frontend modules directory.

   Typical location from the Zabbix frontend documentation:

       /usr/share/zabbix/ui/modules/Healthcheck

   Some distro packages use a different equivalent frontend path. Use the directory that
   your Zabbix frontend scans for modules and keep it consistent in the commands below.

2. Set permissions.

       # Set ownership
       sudo chown -R nginx:nginx /usr/share/zabbix/ui/modules/Healthcheck
       sudo find /usr/share/zabbix/ui/modules/Healthcheck -type d -exec chmod 755 {} \;
       sudo find /usr/share/zabbix/ui/modules/Healthcheck -type f -exec chmod 644 {} \;

       # Make runtime/ writable for lock and throttle files
       sudo chmod 775 /usr/share/zabbix/ui/modules/Healthcheck/runtime

3. If SELinux is enabled, label the directory and allow outbound connections.

       # SELinux: label module files for the web server
       sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/ui/modules/Healthcheck(/.*)?'
       sudo semanage fcontext -a -t httpd_sys_rw_content_t '/usr/share/zabbix/ui/modules/Healthcheck/runtime(/.*)?'
       sudo restorecon -Rv /usr/share/zabbix/ui/modules/Healthcheck

       # SELinux: allow the web server to make outbound connections (API + ping)
       sudo setsebool -P httpd_can_network_connect on

       # SELinux: allow the web server to connect to the database over TCP
       # (skip if Zabbix uses a local Unix socket)
       sudo setsebool -P httpd_can_network_connect_db on

4. In the Zabbix frontend, open the module administration page.

5. Click **Scan directory**, then enable **Healthcheck**.

6. Open:

       Monitoring → Healthcheck → Settings

7. Configure at least one check, then save.

### Database tables

The module can create its own history tables automatically on first use. If your Zabbix
frontend DB account does not have `CREATE TABLE` privileges, pre-create them with the
included SQL files:

    sql/mysql.sql
    sql/postgresql.sql

## Settings field reference

Each check row in **Settings** accepts the following fields:

| Field | Meaning |
|---|---|
| **Name** | Human-readable label shown on the Heartbeat and History pages. |
| **Enabled** | When unchecked, the check is skipped by both the runner and the background scheduler. |
| **Interval (seconds)** | How often this check is due to run. The runner skips checks that ran more recently than this. Minimum 30s. |
| **Timeout (seconds)** | Per-request timeout applied to the Zabbix API calls and the outbound ping. |
| **Fresh data max age** (`freshness_max_age`) | Maximum allowed age, in seconds, of the most recent item value. If the freshest item value is older than this, the check fails. |
| **API auth mode** (`auth_mode`) | How the module authenticates to the Zabbix API: *Automatic* (try bearer token, then the legacy `auth` field), *Bearer token* (Authorization header only), or *Legacy auth field* (the `auth` JSON-RPC field, for older servers). |
| **Ping URL** | The success URL pinged after all steps pass (e.g. a healthchecks.io check URL). Only `http`/`https` URLs are accepted. |
| **Zabbix API URL** | The `api_jsonrpc.php` endpoint to query. If left blank, it is derived from the current frontend host. |
| **Zabbix API token** | API token used for authentication. Leave blank on save to keep the existing token. |
| **Token environment variable** | Read the token from this environment variable instead of storing it in the DB. Useful for the CLI runner. |
| **Verify TLS** (`verify_peer`) | Whether outbound TLS certificates are validated for the API and ping requests. |
| **Host limit** (`host_limit`) | Upper bound on hosts considered. Retained for compatibility; the freshness step now uses a single bounded item query. |
| **Item limit per host** (`item_limit_per_host`) | Upper bound used to size the bounded item sample when computing the freshest data timestamp. |

History and retention fields:

| Field | Meaning |
|---|---|
| **Retention (days)** | Runs and steps older than this are pruned on each runner execution. |
| **Default history period (days)** | Default time window for the History page. |
| **Recent run rows to keep in UI** | Maximum rows displayed on the History page. |

## Scheduler

The module runs due checks opportunistically after a frontend page load (throttled to at
most once per 60 seconds, with a non-blocking lock so concurrent page loads do not stack).
For unattended operation when nobody is browsing the UI, also schedule the CLI runner.

Recommended command:

    /usr/bin/php /usr/share/zabbix/ui/modules/Healthcheck/bin/healthcheck-runner.php --json

The runner is intended to be called every minute; it skips checks that are not yet due
based on each row's **Interval (seconds)**.

### systemd timer

Example files are included in:

    examples/systemd/healthcheck-runner.service
    examples/systemd/healthcheck-runner.timer

Install them, adjust the service user if needed, then:

    systemctl daemon-reload
    systemctl enable --now healthcheck-runner.timer

### cron

An example line is included in:

    examples/cron.example

The **Settings → Scheduler integration** section can generate ready-to-paste cron and
systemd commands for the web-server user of your choice.

## Troubleshooting

- **`Permission denied` reading `zabbix.conf.php` from the runner.** The config file is
  often owned by `apache` and not readable by `nginx`. Either run the scheduler as
  `apache`, or add `nginx` to the `apache` group and set the file to mode `640`.
- **Freshness step fails immediately.** Confirm the API token has read access to items,
  and that **Fresh data max age** is larger than your slowest item's polling interval.
- **`Only http and https URLs are allowed`.** The Ping URL or API URL used an unsupported
  scheme (for example `file://`). Use an `http`/`https` URL.
- **History tables are missing.** Pre-create them with `sql/mysql.sql` or
  `sql/postgresql.sql` if the frontend DB user lacks `CREATE TABLE`.
- **No runs appear.** Make sure at least one check is enabled, and that either the
  background scheduler is active (a logged-in user has loaded a page) or the CLI runner is
  scheduled.

## Notes

- The Zabbix API token field behaves like the AI module: if left blank during save, the
  existing token is kept.
- You can use an environment variable instead of storing the API token directly.
- History is stored in dedicated module tables:
  - `module_healthcheck_run`
  - `module_healthcheck_run_step`
- The runner prunes history older than the configured retention period on each execution.

## Screenshots

**Healthchecks**
![Healthchecks example](../Example%20Pictures/Healtchecks.png)

**Healthchecks settings**
![Healthchecks settings example](../Example%20Pictures/Healtchecks_settings.png)

**Healthchecks history**
![Healthchecks history example](../Example%20Pictures/Healtchecks_history.png)
