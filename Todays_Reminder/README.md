# Today's Reminder

A Zabbix 7.0 frontend module that surfaces an at-a-glance operational reminder for everyone using
the frontend. It injects a non-intrusive sticky banner on top of every page and provides a dedicated
overview page with the full breakdown.

> The module id / namespace is `Message_of_the_Day` (kept stable for upgrade compatibility); the
> human-facing product name is **Today's Reminder**.

## What it shows

Banner (sticky, top of every page):
- A one-line summary (Critical / High / Unacknowledged / Suppressed counts, maintenance today and
  later this week).
- Severity/health chips (Critical, High, Unacked, Suppressed, Stale items, Unreachable, Queue,
  New hosts, maintenance Today / This week).
- A short list of the most relevant items needing attention (top problems, oldest open problem,
  today's maintenance windows).

Overview page (Monitoring -> Today's Reminder):
- **High / Critical problem summary** with counts and links to the matching Problems view.
- **Unacknowledged High / Critical** problems (top 5).
- **Longest-running open problems** (top 5, oldest first).
- **Recently resolved (24h)** problems with per-incident MTTR and an average MTTR.
- **Monitoring health**: items in "not supported", unreachable hosts, unreachable proxies, server
  queue (> 10m) backlog, and new hosts added in the last 24h.
- **Today's maintenance windows** and **upcoming windows later this week**, expanded from one-time,
  daily, weekly, and monthly recurring maintenance periods.

## Behavior

- The banner is injected automatically on frontend pages after login.
- The first page load in a browser session shows the expanded banner; subsequent loads in the same
  tab collapse it automatically so it stays visible but unobtrusive. The collapse state is keyed to
  a content fingerprint stored in `sessionStorage`.
- The banner refreshes every 60 seconds per open tab (skipped while the tab is hidden). The client
  sends its last content fingerprint; when nothing changed the server returns a lightweight
  "not modified" response and the banner is not re-rendered.

## Performance

- The aggregated payload is cached server-side for ~45 seconds (file cache under the system temp
  directory, keyed by user type), so frequent banner refreshes across many users do not hammer the
  Zabbix API.
- All trigger/host lookups for the problem sections are resolved in a single batched API call.
- Host/item scans used for health counts are bounded; totals use `countOutput` rather than fetching
  rows.

## Permissions

- Requires at least `USER_TYPE_ZABBIX_USER`. The module only reads data via the Zabbix API and never
  changes state.

## Deployment

Works on single-server, multi-server, and Docker deployments.

1. Copy the module directory into `ui/modules/`.
2. In Zabbix, go to **Administration -> General -> Modules**.
3. Click **Scan directory** and enable **Today's Reminder**.
