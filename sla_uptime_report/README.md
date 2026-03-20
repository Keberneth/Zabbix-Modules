# SLA & Uptime Report module

This is a rebuilt version of the module intended to replace the earlier white-screening build.

## What changed

- Reworked the menu registration to use the supported Zabbix menu API.
- Replaced the original view/controller flow with a simpler frontend implementation.
- Kept the two main report blocks:
  - rolling 12 month SLA heatmap
  - host availability by host group
- Export buttons now produce:
  - HTML report
  - SLA CSV
  - Availability CSV

## Install

1. Remove the old `ui/modules/sla_uptime_report` directory.
2. Unpack this archive so the module directory becomes:
   `ui/modules/sla_uptime_report`
3. In Zabbix frontend, go to **Administration → General → Modules**
4. Click **Scan directory**
5. Enable **SLA & Uptime Report**

## Notes

- Availability windows longer than 7 days use trend data to keep the frontend responsive.
- Exports are intentionally HTML/CSV only in this version to avoid bundling extra PHP libraries into the Zabbix frontend.
- If the page is still blank after replacing the module, check the PHP-FPM / Apache / Nginx error log for the exact fatal error.
