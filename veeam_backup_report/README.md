# Veeam Backup and Replication v13 Report module for Zabbix
Needed template:
<br>
Veeam Backup and Replication by HTTP v13.yaml
<br>
https://github.com/Keberneth/Zabbix-Templates/tree/main/Veeam
<br>
This frontend module adds a **Reports → Veeam Backup Report** page to Zabbix and reads backup-report data from the **Veeam Backup and Replication by HTTP v13** template items.

The module is designed to work with the item keys from the v13 template that was created for you earlier, including:

- `veeam.backup.total.size.24h`
- `veeam.backup.total.size.31d`
- `veeam.backup.total.assigned.size.31d`
- `veeam.backup.total.shared.size.31d`
- `veeam.repositories.total.capacity.gb`
- `veeam.repositories.total.used.gb`
- `veeam.repositories.total.free.gb`
- `veeam.repository.backup.size.24h[*]`
- `veeam.repository.backup.size.31d[*]`
- `veeam.repository.backup.files.31d[*]`
- `veeam.backup.object.size.24h[*]`
- `veeam.backup.object.size.31d[*]`
- `veeam.backup.object.restorepoints.31d[*]`
- `veeam.backup.object.backupfiles.31d[*]`
- `veeam.backup.object.last.backup[*]`
- `veeam.backup.object.repositories[*]`
- `veeam.backup.object.attribution[*]`

The module **does not call the Veeam API directly**. It only reads data already collected and stored by Zabbix.

## What the module shows

The report page contains:

- **Daily totals**
  - total backup size 24h
  - total rolling 31-day backup size
  - assigned 31-day size
  - shared/unassigned 31-day size
  - attribution coverage

- **Veeam source host summary**
  - start, end, change, average and peak values for the selected metric
  - current repository capacity / used / free totals
  - current online / offline repository counts
  - current attribution coverage

- **Repository summary**
  - start, end, change, average and peak values for the selected metric
  - current files in 31-day window
  - current capacity / used / free / free %
  - current online / out-of-date state

- **Protected object summary**
  - start, end, change, average and peak values for the selected metric
  - current restore point count in 31-day window
  - current backup file count in 31-day window
  - last backup timestamp
  - repositories string
  - attribution mode

- **Exports**
  - HTML
  - daily CSV
  - Veeam hosts CSV
  - repositories CSV
  - objects CSV

## Filters

The page supports the same report style as your SLA module:

- **Period mode**
  - Previous month
  - Specific month
  - Custom range
  - Days back

- **Data source**
  - **Auto** = history for short ranges, trends for longer ranges
  - **History** = raw values from item history
  - **Trends** = hourly trend buckets from Zabbix

- **Metric**
  - **Backup size 24h**
  - **Rolling 31-day backup size**

- **Top protected objects**
  - Limits the object table to keep the frontend responsive

- **Protected object search**
  - Filters by host/object/platform/repository text

- **Repository search**
  - Filters repository table rows

- **Veeam hosts**
  - Leave empty to include every monitored Veeam host

## Important behavior

### History vs trends
This module reads data from Zabbix using the standard API-backed frontend classes.

- **History mode** uses exact raw item samples.
- **Trend mode** uses hourly trend aggregates.
- In **trend mode**, the per-day **end value** is estimated from the **last hourly `value_avg` bucket** in that day, because Zabbix trends store hourly min/avg/max aggregates rather than exact last raw samples.

### It depends on Zabbix retention
If you ask for an older month and there is no history or trend retention left for that period, the module cannot rebuild the report. In that case the tables will be empty and the page will show a warning.

### It depends on the v13 template items
If the v13 template is not linked to a host, or discovery has not populated repositories and protected objects yet, the report page will have little or no data.

## Requirements

- Zabbix frontend with module support enabled
- Your **Veeam Backup and Replication by HTTP v13** template imported and linked to one or more hosts
- Data already collected for the backup-report item family
- Numeric backup-report items must keep history/trends long enough for the period you want to review

## Installation

1. Copy the module directory to your Zabbix frontend modules path so it becomes:

   ```text
   /usr/share/zabbix/modules/veeam_backup_report
   ```

2. In Zabbix frontend, go to:

   ```text
   Administration → General → Modules
   ```

3. Click:

   ```text
   Scan directory
   ```

4. Enable:

   ```text
   Veeam Backup Report
   ```

5. Open:

   ```text
   Reports → Veeam Backup Report
   ```

## Recommended retention

For the report to be useful over past months:

- keep **history** for the short window you care about for exact raw values
- keep **trends** for longer reporting windows

A practical baseline is:

- history: 14–31 days
- trends: 12 months or more

Adjust to your database sizing and reporting needs.

## Notes about performance

Protected object reports can become heavy when you have a lot of discovered objects.

To keep the frontend responsive:

- the object table defaults to **top 100**
- auto mode switches to trends for longer ranges
- use object search when you want a specific tenant / VM / agent / host

## Troubleshooting

### The module does not appear in Zabbix
Check that the directory is exactly:

```text
/usr/share/zabbix/modules/veeam_backup_report
```

and that `manifest.json` is in that directory.

### The page loads but there are no hosts
The module looks for hosts that currently have data for:

```text
veeam.backup.total.size.24h
```

If none are found:

- confirm the v13 template is linked to a host
- confirm items are enabled and supported
- wait for the first collection cycle

### Repository or object tables are empty
That usually means one of these:

- discovery has not finished yet
- no data exists in the selected month/range
- retention has already purged that period
- filters are too narrow
- the selected source is wrong for the requested time range

### Trend mode values do not match the exact last sample
That is expected. Trend mode reads hourly aggregated data, not exact final raw history samples.

## File structure

```text
veeam_backup_report/
├── Module.php
├── README.md
├── manifest.json
├── helpers/
│   └── ReportDataHelper.php
├── actions/
│   ├── ReportDownload.php
│   └── ReportView.php
├── assets/
│   └── css/
│       └── veeamreport.css
├── partials/
│   ├── veeambackup.daily.table.php
│   ├── veeambackup.hosts.table.php
│   ├── veeambackup.objects.table.php
│   └── veeambackup.repositories.table.php
└── views/
    └── veeambackup.report.view.php
```

## Compatibility

Built for:

- Zabbix 7.x frontend module layout
- the **Veeam Backup and Replication by HTTP v13** template generated for this work

## Validation done here

The PHP files in this package were lint-checked with `php -l` before packaging.

Live runtime validation inside your own Zabbix frontend is still needed because that depends on your exact Zabbix build, user permissions, and collected item data.
