# SLA & Uptime Report

A Zabbix 7.0 frontend module that adds an **SLA & Uptime Report** page under **Reports**. It
combines two views on one screen:

1. **SLA overview** — a rolling 12-month SLI heatmap for the SLAs configured in
   *Services → SLA*, colour-coded against each SLA's SLO.
2. **Availability overview** — per-host-group uptime percentages and downtime, derived from each
   host's availability item, with green/yellow/red banding.

Both views can be exported as a self-contained HTML report or as CSV.

---

## Features

- Rolling **12-month SLA heatmap** (one row per service, one column per month) with a per-SLA
  summary line (services meeting / below SLO, 12-month average).
- **Host availability** per host group, with group averages, ≥99 % / 90–99 % / <90 % / N/A band
  counts, and human-readable downtime.
- **Period modes:** previous month, a specific month, a custom date range, or a rolling
  "days back" window. Only the fields used by the selected mode are shown.
- **Typeahead filters** for host groups and SLAs (native Zabbix multiselect autocomplete) — no
  giant unbounded option lists.
- **Exports:** download an HTML report, an SLA CSV, or an availability CSV.
- **Dark-theme aware** (supports `dark-theme` and `hc-dark`).
- **Multi-install / Docker compatible** — uses only the public Zabbix PHP API and frontend
  framework; no server-side daemons, no external libraries, no filesystem writes.

---

## Requirements

- Zabbix **7.0** frontend.
- **Permissions:** any user with at least *Zabbix user* role (`USER_TYPE_ZABBIX_USER`) can open the
  report. Data is still scoped by each user's host-group and service permissions.
- **SLA services** configured under *Services → SLA* (enabled) for the SLA heatmap to show data.
- **An availability item** on each host for the uptime view. Any one of these item keys is used
  (first match per host wins):
  - `agent.ping`
  - `icmpping`
  - `zabbix[host,agent,available]`

  Hosts without any of these keys are reported as *Item not found*.

---

## Install / enable

1. Copy this directory to `ui/modules/sla_uptime_report` in your Zabbix frontend
   (for Docker, mount it into the web container at the same path).
2. In the frontend go to **Administration → General → Modules**.
3. Click **Scan directory**.
4. Enable **SLA & Uptime Report**.
5. Open **Reports → SLA & Uptime Report**.

---

## Using the filter

| Period mode | Fields used |
|---|---|
| Previous month | (none — uses the previous calendar month) |
| Specific month | *Specific month* (`YYYY-MM`) |
| Custom range | *From date* / *To date* (`YYYY-MM-DD`, UTC) |
| Days back | *Days back* (1–366) |

- **Host groups** — narrow the availability view; leave empty for all groups you can see.
- **SLAs** — narrow the SLA heatmap; leave empty for all enabled SLAs.
- **Exclude disabled hosts** — when on, only monitored hosts are counted.

The reporting window is interpreted in UTC and shown in the info bar above the tables.

---

## How availability is calculated

The module picks the calculation source automatically based on the window length:

- **≤ 7 days → raw history.** Availability per host = OK samples / expected samples, where the
  expected count includes polling gaps (missing samples count as downtime). The polling interval is
  taken from the item's configured delay, falling back to the median observed interval.
- **> 7 days → hourly trends.** Each hourly trend's `value_avg` is treated as the fraction of "up"
  samples that hour; the sum over **covered** hours is divided by the number of covered hours
  (hours with no trend data are excluded from the denominator rather than silently counted as
  downtime). `value_avg` is clamped to `[0,1]`, so the `zabbix[host,agent,available]` item
  (values 0/1/2) cannot inflate uptime. Trend availability is therefore an approximation; for exact
  figures use a window of 7 days or less.

### Performance & limits

To stay responsive on large installs the heavy path is bounded (see `ReportDataHelper` constants):

| Bound | Default | Effect when hit |
|---|---|---|
| `MAX_RANGE_DAYS` | 768 | Oversized custom ranges are clamped to the most recent 768 days. |
| `MAX_HOSTS` | 5000 | Availability is limited to the first 5000 hosts (note shown). |
| `MAX_SLAS` | 200 | SLA heatmap limited to the first 200 SLAs (note shown). |
| `MAX_HISTORY_ROWS` | 200000 | History scan stops; percentages become approximate (note shown). |
| `FETCH_BATCH` | 2000 | History is read in clock-keyset pages of this size. |

History is fetched in **one batched, item-chunked, paginated query** (not one query per host), and
trend/item/history lookups are `array_chunk`'d, so the report scales to hundreds of hosts.
Whenever a cap is reached the UI shows a note explaining the truncation.

---

## Exports

All three exports honour the current filter.

- **HTML report** — a standalone, self-contained document (inline styles, no external assets).
- **SLA CSV** — columns: `SLA ID, SLA name, Service ID, Service name, SLO, Month, SLI`.
- **Availability CSV** — columns: `Host group, Host, Availability, Availability pct,
  Uptime seconds, Downtime seconds, Window start UTC, Window end UTC`.

CSV files are UTF-8 with a BOM for spreadsheet compatibility.

---

## Troubleshooting

- **"Item not found" for every host** — the host has none of the supported availability item keys;
  add `agent.ping`, `icmpping`, or `zabbix[host,agent,available]`.
- **Empty SLA heatmap** — no enabled SLAs, or the selected SLAs have no linked services.
- **A truncation note appears** — a limit above was reached; narrow the host-group / SLA filter or
  shorten the date range.
- **An internal error message** — the underlying cause is written to the PHP/web-server error log
  prefixed with `SLA Uptime Report:`; check that log for the exact failure.

---

## Security notes

- Every action declares a strict input whitelist and runs a permission check.
- All heavy actions are wrapped in `try/catch`: validation problems return a clear message, while
  unexpected failures are logged server-side and surface only a generic message (no DB/API/path
  internals leak to the browser).
- The standalone HTML/CSV export is hand-built outside `CHtmlPage`; every interpolated value is
  escaped via a mandatory `h()` helper (`htmlspecialchars`, `ENT_QUOTES`).
