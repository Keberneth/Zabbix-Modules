# Zabbix-Modules
Frontend modules that extend Zabbix 7.0. Each module lives in its own folder with its own README and screenshots.

## Modules

| Module | Menu | Description |
|---|---|---|
| [AI](./AI/README.md) | Monitoring → AI | AI chat and webhook troubleshooting assistant with redaction and audit logging. |
| [Branding](./Branding/README.md) | Administration → Branding | Custom logos for the login page, sidebar and browser favicon. |
| [Capacity Planning](./Capacity_Planning/README.md) | Reports → Capacity Planning | Disk, CPU and RAM forecasting with risk levels, runway chart and saturation episodes. |
| [Healthcheck](./Healthcheck/README.md) | Monitoring → Healthcheck | Scheduled checks of Zabbix itself, with heartbeat ping and run history. |
| [Incident Timeline](./Incident_timeline_graph/README.md) | Reports → Incident Timeline | Multi-month incident timeline and top-triggers report. |
| [NetBox Sync](./NetBoxSync/README.md) | Monitoring → NetBox Sync | Syncs Zabbix hosts, disks, interfaces and services to NetBox. |
| [Network Map](./NetworkMap/README.md) | Monitoring → Network map | Interactive map of observed TCP connections between hosts, with full-screen view. |
| [SLA & Uptime Report](./sla_uptime_report/README.md) | Reports → SLA & Uptime Report | SLA heatmaps, error budgets and host availability with downtime charts. |
| [Today's Reminder](./Todays_Reminder/README.md) | Monitoring → Today's Reminder | Top banner and overview of open High/Critical problems, monitoring health and maintenance. |
| [Trigger Correlation](./TriggerCorrelation/README.md) | Monitoring → Trigger Correlation | Combines related problems across hosts into one correlation problem or escalates their severity. |
| [Veeam Backup Report](./veeam_backup_report/README.md) | Reports → Veeam Backup Report | Backup jobs, repositories, protected objects and growth forecast from the Veeam v13 template. |

The HANA Dashboard module is maintained in [Zabbix-HANA-Monitoring](https://github.com/Keberneth/Zabbix-HANA-Monitoring).

## Simple Installation Guide
Download the module folder to the Zabbix Web frontend Server and place in:
/usr/share/zabbix/modules/

Then enable it in **Administration → General → Modules → Scan directory**.

### Some modules have extra setup steps in their own docs
Branding and Healthcheck (README), AI and NetBox Sync (INSTALL.md), Trigger Correlation (SETUP.md).

## Set permissions
### Folders and Files Permissions
sudo chown -R nginx:nginx /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type d -exec chmod 755 {} \;<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type f -exec chmod 644 {} \;<br>

### SELinux
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/MODULE_FOLDER_NAME(/.*)?'<br>
sudo restorecon -Rv /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
sudo setsebool -P httpd_can_network_connect on
