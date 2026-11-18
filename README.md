# Zabbix-Modules
Frontend modules that extend Zabbix 7.0. Each module lives in its own folder with its own README and screenshots.

## Modules

| Module | Menu | Description |
|---|---|---|
| [AI](./AI/README.md) | Monitoring → AI | AI chat and webhook troubleshooting assistant with redaction and audit logging. |
| [Branding](./Branding/README.md) | Administration → Branding | Custom logos for the login page, sidebar and browser favicon, and a custom or hidden server name under the sidebar logo. |
| [Capacity Planning](./Capacity_Planning/README.md) | Reports → Capacity Planning | Disk, CPU and RAM forecasting with risk levels, runway chart and saturation episodes. |
| [Healthcheck](./Healthcheck/README.md) | Monitoring → Healthcheck | Scheduled checks of Zabbix itself, with heartbeat ping and run history. |
| [Incident Timeline](./Incident_timeline_graph/README.md) | Reports → Incident Timeline | Multi-month incident timeline and top-triggers report. |
| [NetBox Sync](./NetBoxSync/README.md) | Monitoring → NetBox Sync | Syncs Zabbix hosts, disks, interfaces and services to NetBox. |
| [Network Map](./NetworkMap/README.md) | Monitoring → Network map | Interactive map of observed TCP connections between hosts, with full-screen view. |
| [Patch Calendar](./Patch_Calendar/README.md) | Dashboard widget | Calendar of maintenance (patch) windows, and a template that alarms the day before and on the day of a host's patch window. |
| [SLA & Uptime Report](./sla_uptime_report/README.md) | Reports → SLA & Uptime Report | SLA heatmaps, error budgets and host availability with downtime charts. |
| [Today's Reminder](./Todays_Reminder/README.md) | Monitoring → Today's Reminder | Top banner and overview of open High/Critical problems, monitoring health and maintenance. |
| [Trigger Correlation](./TriggerCorrelation/README.md) | Monitoring → Trigger Correlation | Combines related problems across hosts into one correlation problem or escalates their severity. |
| [Veeam Backup Report](./veeam_backup_report/README.md) | Reports → Veeam Backup Report | Backup jobs, repositories, protected objects and growth forecast from the Veeam v13 template. |

The HANA Dashboard module is maintained in [Zabbix-HANA-Monitoring](https://github.com/Keberneth/Zabbix-HANA-Monitoring).

## Consistent appearance, independent installation

All 12 modules use the same card, control, typography and color system. The
appearance follows the user's Zabbix theme: **Blue**, **Dark**, **High-contrast
light**, or **High-contrast dark**. **System default** uses the theme resolved by
Zabbix; the operating system's dark-mode preference does not override it.
High-contrast themes use stronger borders and text without decorative shadows.
Keyboard focus and reduced-motion preferences are supported throughout.

Each module includes its own self-contained `assets/css/module-theme.css`, loaded
before its component styles. There is no shared runtime folder, cross-module CSS
import, or extra module to install. Install any one module, any pair, or all of them.

Deploy the complete updated module folder (including its manifest and CSS assets)
and refresh the frontend to see the new design.

## Simple Installation Guide
Download the module folder to the Zabbix Web frontend Server and place in:
/usr/share/zabbix/modules/

Then enable it in **Administration → General → Modules → Scan directory**.

### Some modules have extra setup steps in their own docs
Branding, Healthcheck and Patch Calendar (README), AI and NetBox Sync (INSTALL.md), Trigger Correlation (SETUP.md).

## Set permissions
### Folders and Files Permissions
sudo chown -R nginx:nginx /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type d -exec chmod 755 {} \;<br>
sudo find /usr/share/zabbix/modules/MODULE_FOLDER_NAME -type f -exec chmod 644 {} \;<br>

### SELinux
sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/MODULE_FOLDER_NAME(/.*)?'<br>
sudo restorecon -Rv /usr/share/zabbix/modules/MODULE_FOLDER_NAME<br>
sudo setsebool -P httpd_can_network_connect on
