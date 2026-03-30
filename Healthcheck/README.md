# Healthcheck module for Zabbix 7

This module adds:

- **Monitoring → Healthcheck → Heartbeat** for the latest status per configured check
- **Monitoring → Healthcheck → History** for recent runs and aggregate statistics
- **Monitoring → Healthcheck → Settings** for configuring one or more checks
- A **CLI runner** that can be executed from `systemd` or `cron`

## What a check does

Each configured check performs the same sequence as the supplied health-check script:

1. Verify that the Zabbix API answers `apiinfo.version`
2. Count monitored hosts
3. Count active enabled problem triggers
4. Count enabled items
5. Inspect the freshest item data timestamp across monitored hosts
6. Send the success ping to the configured healthcheck URL

If one step fails, the run is marked as failed and the ping is not sent.

## Ping if working
If the healtchecks return OK a ping can be sent. I recomend https://healthchecks.io/ 
<br>
healthchecks.io can be configured to sent triggered problems if ping is not recived within specified time period.
<br>
Can be integrated with multiple services for example: PagerDuty, Github, Teams, Slack and more

## Installation

1. Copy the `Healthcheck` directory to your Zabbix frontend modules directory.

   Typical location from Zabbix frontend documentation:

       /usr/share/zabbix/ui/modules/Healthcheck

   Some distro packages use a different equivalent frontend path. Use the directory that your Zabbix frontend scans for modules.

2. Set permissions.

       chown -R nginx:nginx /usr/share/zabbix/ui/modules/Healthcheck
       find /usr/share/zabbix/ui/modules/Healthcheck -type d -exec chmod 0755 {} \;
       find /usr/share/zabbix/ui/modules/Healthcheck -type f -exec chmod 0644 {} \;
       chmod 0755 /usr/share/zabbix/ui/modules/Healthcheck/bin/healthcheck-runner.php

3. If SELinux is enabled, label the directory and allow outbound connections.

       semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/ui/modules/Healthcheck(/.*)?'
       restorecon -Rv /usr/share/zabbix/ui/modules/Healthcheck
       setsebool -P httpd_can_network_connect on

4. In the Zabbix frontend, open the module administration page.

5. Click **Scan directory**, then enable **Healthcheck**.

6. Open:

       Monitoring → Healthcheck → Settings

7. Configure at least one check, then save.

### Database tables

The module can create its own history tables automatically on first use. If your Zabbix frontend DB account does not have `CREATE TABLE` privileges, pre-create them with the included SQL files:

    sql/mysql.sql
    sql/postgresql.sql


## Scheduler

The module does not schedule itself. Use the included CLI runner every minute and let the module decide which checks are due based on each row's **Interval (seconds)**.

Recommended command:

    /usr/bin/php /usr/share/zabbix/ui/modules/Healthcheck/bin/healthcheck-runner.php --json

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

## Notes

- The Zabbix API token field behaves like the AI module: if left blank during save, the existing token is kept.
- You can use an environment variable instead of storing the API token directly.
- History is stored in dedicated module tables:
  - `module_healthcheck_run`
  - `module_healthcheck_run_step`
- The runner prunes history older than the configured retention period on each execution.
