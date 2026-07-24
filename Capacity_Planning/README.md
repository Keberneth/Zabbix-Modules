# Capacity Planning & Prediction

A Zabbix **frontend report module** that turns the trend data you already collect into a predictive capacity report: robust growth trends for every filesystem, sustained CPU/memory baselines, projected ETAs to each host's *actual* Zabbix alarm thresholds, and a risk classification that tells you what needs action — all rendered as interactive charts and sortable tables in the Zabbix UI.

Built and tested on **Zabbix 7.0**.

---

## Screenshots

![Overview with capacity runway and risk distribution](docs/images/01-overview.jpg)

The **Overview** tab: scope cards, the capacity-runway chart (days until each filesystem reaches its next alarm threshold), the risk distribution and the most urgent findings.

![Filesystem forecast table](docs/images/02-filesystems.jpg)

The **Filesystems** tab: per-filesystem growth, warning/critical/full ETAs and confidence. Note the context-aware thresholds — `/var` warns at its host-macro override, the remote NFS share uses the stricter remote defaults.

![Filesystem usage chart with projection](docs/images/03-filesystem-detail.jpg)

Clicking a row opens the usage chart: daily min–max band, average line, the projected growth crossing the host's own warning/critical threshold lines, with crossing markers and dates.

![CPU and memory baselines](docs/images/04-resources.jpg)

The **CPU & Memory** tab: sustained utilization against each host's alarm thresholds — average, p95 and time-above-threshold, with a drill-down chart per metric.

## Features

- **Real forecasting, not just charts** — a robust Theil–Sen trend (median of pairwise slopes) is fitted over nested 12-month/6-month/3-month/1-month/1-week windows; the best-covered stable window is chosen automatically and recent acceleration shortens the estimate.
- **ETAs to the thresholds that actually alarm** — warning/critical percentage macros (`{$VFS.FS.PUSED.MAX.WARN/CRIT}` with `label(name)`/FSNAME contexts, regex contexts included) and absolute free-space macros are resolved with real Zabbix precedence: host → template chain by depth → global. Fallback defaults are used only when no macro resolves, and every fallback is reported.
- **Risk classification** — every finding is classified Critical / High / Medium / Watch / Healthy / Unknown from current breaches, projected ETAs and forecast confidence, so the report leads with what needs action.
- **CPU & memory baselines** — average, p95, peak and time-above-threshold from trends; a single spike is never treated as an upgrade decision.
- **Capacity runway chart** — one glance shows which filesystems cross a threshold in the next year, colored by risk.
- **Interactive drill-down** — click any row for the historical usage chart with min–max band, projection, threshold lines, crossing markers and hover tooltips.
- **Filters & deep links** — host group / host / template / name filters, a risk-level filter, and URL state for bookmarking and sharing.
- **Exports** — CSV (action list, full filesystem forecast, resource baselines), a standalone HTML report and PNG chart export. CSV output neutralizes spreadsheet formula injection.
- **Dark theme** — every surface has `dark-theme` and `hc-dark` styling, and the charts re-render with a dark palette.

## Built for scale

- The report loads progressively: the inventory (items, thresholds, current state) renders first, then forecasts stream in small batches with the riskiest filesystems computed first.
- Trend series are fetched per item with hard row caps, sorted server-side (the trends API returns unsorted rows) and downsampled to daily points before they reach the browser.
- Hosts, items, findings and data-quality lists all have named caps; when a cap is hit the UI says so instead of silently truncating.
- If an item has no hourly trends, a bounded raw-history fallback (7 days, bucketed hourly) is used and marked as low-confidence.

> Forecast dates are planning estimates, not guarantees. The ETA is the projected threshold crossing — not the exact moment a Zabbix problem event fires (triggers may require sustained breaches).

## Install

1. Copy the `Capacity_Planning` folder to the Zabbix frontend modules directory:

   ```bash
   cp -r Capacity_Planning /usr/share/zabbix/modules/
   ```

2. Set ownership to the web-server user (`www-data` for Apache/Debian, `nginx` for RHEL with nginx):

   ```bash
   chown -R www-data:www-data /usr/share/zabbix/modules/Capacity_Planning
   ```

3. On SELinux systems, restore the file context:

   ```bash
   semanage fcontext -a -t httpd_sys_content_t "/usr/share/zabbix/modules/Capacity_Planning(/.*)?"
   restorecon -Rv /usr/share/zabbix/modules/Capacity_Planning
   ```

4. In the Zabbix UI go to **Administration → General → Modules**, press **Scan directory**, then enable **Capacity Planning & Prediction**.

5. Open **Reports → Capacity Planning**.

## Usage

- Pick an **analysis lookback** (3–24 months). Longer lookbacks give more stable growth models; the forecast itself always projects up to one year ahead.
- Use the filter bar to scope by host group, host or template — the same free-text matching as the Incident Timeline module. The *contains* filter narrows rows client-side.
- The **risk filter** hides levels you do not care about (e.g. show only Critical/High/Medium for an action meeting).
- Click a runway bar or a table row to open the drill-down chart. `⚠` next to the confidence means recent growth is accelerating beyond the long-term model.
- **Export CSV** exports the active tab (Overview → action list); **Export HTML** produces a self-contained report; **Export PNG** renders the visible charts.

## Notes / limits

- Any Zabbix user can open the report; API permissions decide which hosts and items each user sees, so two users can legitimately get different reports.
- Filesystem discovery covers `vfs.fs.size[...]` and `vfs.fs.dependent.size[...]` keys (used/free/total/pused/pfree); CPU/memory discovery covers `system.cpu.util`, `vm.memory.utilization`, `vm.memory.util`, `vm.memory.size[pused|pavailable|total]` and the `vm.cpu.util`/`vm.mem.util` shorthand keys. Windows perf-counter-only CPU items are not yet recognized.
- Forecasts need numeric (float/uint) items with trends; Zabbix housekeeping limits how far back the model can look. The raw-history fallback scans oldest-first with a 50k-row cap, so extremely high-frequency items (sustained >5 values/min) may lose their newest samples in fallback mode.
- Threshold macros are resolved with `nopermissions` so every user sees the thresholds the server actually alarms on, even when the defining template is not readable to them; only text macros matching the threshold-name prefixes are read, and secret/vault macros are skipped.
- Threshold macros with secret/vault values cannot be read and fall back to defaults (reported under Data quality).
- Remote filesystems (NFS/CIFS/…) are classified and get the stricter remote defaults when no macro resolves; block-device I/O saturation is out of scope for this module.
