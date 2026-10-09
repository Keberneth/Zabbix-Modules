# Capacity Planning & Prediction

A Zabbix **frontend report module** that turns the trend and history data you already collect into an evidence-based capacity report: robust growth forecasts for every filesystem, qualified CPU/memory baselines, confirmed saturation episodes, and risk classifications tied to each host's *actual* Zabbix alarm thresholds — all rendered as interactive charts and sortable tables in the Zabbix UI.

Built and tested on **Zabbix 7.0**.

Current release: **1.4.0** (build **1.4.0-20260801.1**).

---

## Quick installation

Zabbix frontend modules must live in their own folder below the frontend's `modules` directory. Deploy only the runtime files shown below — keep the public `tests/` directory out of the web-served module path. After copying, `/usr/share/zabbix/modules/Capacity_Planning/manifest.json` must exist.

Run the copy commands from the parent directory that contains the cloned `Capacity_Planning/` folder (`cd ..` first if your shell is currently inside the repository).

### Linux server (Nginx + PHP-FPM, SELinux)

The commands below match a package installation whose frontend service user is `nginx`. Keep the PHP module code root-owned and read-only to the web process; only the cache directory is owned by PHP-FPM. Change the cache owner `nginx:nginx` if `ps -eo user,group,comm | grep php-fpm` shows a different PHP-FPM user.

```bash
sudo dnf install -y policycoreutils-python-utils  # provides semanage on RHEL/Rocky/Alma

MODULE_SOURCE=./Capacity_Planning
MODULE_DIR=/usr/share/zabbix/modules/Capacity_Planning
CACHE_DIR=/var/cache/zabbix-capacity-planning

sudo install -d -o root -g root -m 0755 "$MODULE_DIR"
sudo cp -a "$MODULE_SOURCE/Module.php" "$MODULE_SOURCE/manifest.json" \
  "$MODULE_SOURCE/actions" "$MODULE_SOURCE/assets" "$MODULE_SOURCE/lib" \
  "$MODULE_SOURCE/views" "$MODULE_DIR/"
# Remove tests left by an older whole-repository deployment.
sudo rm -rf -- "$MODULE_DIR/tests"

sudo chown -R root:root "$MODULE_DIR"
sudo find "$MODULE_DIR" -type d -exec chmod 0755 {} +
sudo find "$MODULE_DIR" -type f -exec chmod 0644 {} +
sudo install -d -o nginx -g nginx -m 0700 "$CACHE_DIR"

sudo semanage fcontext -a -t httpd_sys_content_t '/usr/share/zabbix/modules/Capacity_Planning(/.*)?'
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/var/cache/zabbix-capacity-planning(/.*)?'
sudo restorecon -Rv "$MODULE_DIR" "$CACHE_DIR"
```

The terminating `+` in both `find -exec` commands is intentional. If you prefer the one-file-at-a-time form, the semicolon must be escaped as `{} \;`; a bare `{} ;` is consumed by the shell and produces `find: missing argument to -exec`.

The two `semanage fcontext -a` commands are for a first installation. If a rule already exists during an upgrade, rerun that command with `-m` instead of `-a`, then run `restorecon` again.

Add these lines inside the Zabbix PHP-FPM pool (commonly `/etc/php-fpm.d/zabbix.conf` on RHEL-family systems):

```ini
env[CAPACITY_PLANNING_CACHE_DIR] = /var/cache/zabbix-capacity-planning
env[CAPACITY_PLANNING_CACHE_NAMESPACE] = test-zabbix
env[CAPACITY_PLANNING_FRONTEND_ROOT] = /usr/share/zabbix
```

Use a stable namespace unique to this Zabbix installation; see [Deployment namespace](#deployment-namespace). Then reload PHP-FPM and Nginx:

```bash
sudo systemctl restart php-fpm nginx
```

`httpd_can_network_connect` is not a special requirement of this module. Keep your existing SELinux boolean if the Zabbix frontend needs it to reach a networked database or other Zabbix component; do not enable the broad boolean solely for the disk cache.

### Docker Compose

The official Zabbix 7.0 web image runs as UID/GID `1997:1995`. Run these commands from the directory containing both `Capacity_Planning/` and your Compose file (Compose resolves relative bind paths from its project directory). First build a runtime-only bind-mount directory, then create the private cache directory:

```bash
RUNTIME_DIR=./Capacity_Planning-runtime
rm -rf -- "$RUNTIME_DIR"
mkdir -p "$RUNTIME_DIR"
cp -a ./Capacity_Planning/Module.php ./Capacity_Planning/manifest.json \
  ./Capacity_Planning/actions ./Capacity_Planning/assets ./Capacity_Planning/lib \
  ./Capacity_Planning/views "$RUNTIME_DIR/"

mkdir -p ./zabbix-capacity-cache
sudo chown 1997:1995 ./zabbix-capacity-cache
sudo chmod 0700 ./zabbix-capacity-cache
find "$RUNTIME_DIR" -type d -exec chmod 0755 {} +
find "$RUNTIME_DIR" -type f -exec chmod 0644 {} +
```

Add these entries to the existing Zabbix **web frontend** service (MySQL or PostgreSQL; Nginx or Apache):

```yaml
services:
  zabbix-web-nginx-mysql: # replace with your actual frontend service name
    volumes:
      - ./Capacity_Planning-runtime:/usr/share/zabbix/modules/Capacity_Planning:ro
      - ./zabbix-capacity-cache:/var/cache/zabbix-capacity-planning:rw
    environment:
      CAPACITY_PLANNING_CACHE_DIR: /var/cache/zabbix-capacity-planning
      CAPACITY_PLANNING_CACHE_NAMESPACE: test-zabbix
      CAPACITY_PLANNING_FRONTEND_ROOT: /usr/share/zabbix
```

On an SELinux Docker host, use `:ro,Z` and `:rw,Z` on those two bind mounts for one frontend container. Use shared `:z` labels only when multiple containers intentionally mount the same paths. Recreate the frontend container so the mounts and environment are applied:

```bash
docker compose up -d --force-recreate zabbix-web-nginx-mysql
```

### Upgrade checklist

Treat the PHP controllers and libraries, JavaScript/CSS assets and manifest as one release. A partial copy can leave the browser and PHP-FPM running different builds.

1. Confirm `manifest.json` reports version **1.4.0**, registers `assets/js/capacity-planning-1.4.0-20260801.1.js`, and `lib/Build.php` reports build **1.4.0-20260801.1**. The build-versioned JavaScript URL forces browsers and proxies to fetch each release instead of reusing an older bundle.
2. Replace the complete runtime set together: `Module.php`, `manifest.json`, `actions/`, `assets/`, `lib/` (including `lib/Build.php`) and `views/`. Both installation examples copy the complete `lib/` directory, so the build handshake is included. Build a clean runtime-only directory first when using a bind mount; do not overlay only the files that appeared in a commit.
3. Restart the frontend PHP runtime after the copy so no old OPcache bytecode remains (`sudo systemctl restart php-fpm` on the Linux example). Recreate the web frontend container for Docker deployments. Reload Nginx/Apache too when its static-asset policy requires it.
4. In **Administration → General → Modules**, use **Scan directory** when the module/version is not refreshed automatically, then confirm that Capacity Planning remains enabled.
5. Hard-refresh the browser (or clear this site's cached assets) before validating the report. Confirm the displayed/frontend and server build identifiers agree when the build check is available.
6. Open one representative filesystem and verify that Warning, Critical and Full dates are ordered and that the selected model windows match the detail text.

The shared series cache contains sanitized numeric history/trend shards, not PHP code, JavaScript, forecasts, thresholds or recommendations. Clearing it does **not** fix a partial deployment, stale OPcache or stale browser assets. Use **Clear shared cache** only for cache-health/invalidation work; use the complete copy, PHP restart and browser refresh above for a code upgrade.

For either installation, finish in **Administration → General → Modules**: press **Scan directory**, then enable **Capacity Planning & Prediction**. Open **Reports → Capacity Planning**, and check **Settings** to confirm that the cache backend is available. The report still works from live Zabbix data if the private cache cannot be initialized.

### Deployment namespace

`CAPACITY_PLANNING_CACHE_NAMESPACE` is a non-secret, stable label used to prevent cached item IDs from one Zabbix installation being mistaken for item IDs from another installation.

- Choose a short unique value such as `test-zabbix`, `prod-eu-zabbix` or `customer-a-prod`. Do not put a password, token, hostname inventory or other secret in it.
- All frontend nodes that connect to the **same Zabbix database** may use the same namespace. Prefer a private local cache directory per frontend node. Intentional cross-node reuse additionally requires the same managed `CAPACITY_PLANNING_BOOT_ID`, the same numeric PHP-FPM UID, POSIX ownership/mode preservation, working cross-node `flock`, and atomic same-directory rename semantics on the shared filesystem. Without the shared generation value, each node automatically keeps its own boot-scoped generation even inside a shared directory.
- Frontends connected to different Zabbix databases must use different namespaces, even if item IDs happen to overlap.
- Keep the value stable across ordinary upgrades and restarts. Changing it starts a new logical namespace, but the module cannot visit the old namespace to clean it. Clear the shared cache **before** changing the value; if it was already changed, verify and remove the old private namespace directory manually.
- If no explicit value is configured, the module derives a hashed namespace from the initialized Zabbix database connection. If that identity is incomplete or untrustworthy, caching fails closed instead of using a generic shared namespace.
- Containers may additionally set `CAPACITY_PLANNING_BOOT_ID` to a trustworthy deployment generation and rotate it when the frontend/Zabbix stack is recreated. It is required for intentional cross-node shard reuse. On a normal single-node Linux server the module uses the operating-system boot identity automatically. A Zabbix-service-only restart has no dependable frontend API epoch, so use **Refresh now** or **Clear shared cache** when a full rebuild is required.
- `CAPACITY_PLANNING_FRONTEND_ROOT` is an optional authoritative frontend or alias root. Set it when the frontend is mounted or symlinked through a layout the module cannot infer. The cache is rejected if its path overlaps this root in either direction; the examples set `/usr/share/zabbix` explicitly.

## Screenshots

![Overview with capacity runway and risk distribution](docs/images/01-overview.jpg)

The **Overview** tab: scope cards, the capacity-runway chart (days until each filesystem reaches its next alarm threshold), the risk distribution and the most urgent findings.

![Filesystem forecast table](docs/images/02-filesystems.jpg)

The **Filesystems** tab: per-filesystem growth, warning/critical/full ETAs and confidence. Thresholds are context-aware: a host-macro override wins, and remote shares such as `nas01:/backup` use the stricter remote defaults.

![Filesystem usage chart with projection](docs/images/03-filesystem-detail.jpg)

Clicking a row opens the usage chart in a modal window: daily min–max band, average line, the projected growth crossing the host's own warning/critical threshold lines, with crossing markers and dates. Drag across historical data to zoom into a smaller range.

![CPU capacity evidence](docs/images/04-resources.jpg)

The separate **CPU** and **RAM** tabs: sustained utilization against each host's alarm thresholds — current value, p95 and average, peak exposure, confirmed saturation episodes with their longest and total duration, and the baseline and saturation verdicts behind the risk. Each row opens a drill-down chart.

## Features

- **Real disk forecasting, not just charts** — a robust Theil–Sen trend (median of pairwise slopes) is fitted over nested 12-month/6-month/3-month/1-month/1-week windows; the best-qualified window is chosen automatically and a well-supported recent acceleration can shorten the estimate. The same acceleration rule is applied to the byte model and to a direct used-percentage series in its own units, so the two models describe the same time period; when their estimates still diverge (for example after a capacity change), the report says so explicitly. The growth noise floor is capacity-relative, so small filesystems with real growth are still forecast while noise-level slopes on large volumes are discarded by both models together. The exact formulas and thresholds are listed in [How the calculations work](#how-the-calculations-work).
- **Conservative filesystem truth** — stale current metrics are excluded, usable capacity prefers `used + free`, Linux `total` is never treated as usable capacity by itself, and direct `pused` history drives percentage ETAs when available while byte growth independently drives free-space ETAs. Current breaches remain visible even when there is too little history to fit a model.
- **ETAs to the thresholds that actually alarm** — warning/critical percentage macros (`{$VFS.FS.PUSED.MAX.WARN/CRIT}` with `label(name)`/FSNAME contexts, regex contexts included) and absolute free-space macros are resolved with real Zabbix precedence: host → template chain by depth → global. Fallback defaults are used only when no macro resolves, and every fallback is reported.
- **Risk classification** — every finding is classified Critical / High / Medium / Watch / Healthy / Unknown from current breaches, projected ETAs and forecast confidence, so the report leads with what needs action.
- **CPU & memory need from sustained evidence** — coverage-qualified 7-day to 12-month baselines combine average, p95, peak and sample-weighted time above warning/critical thresholds. Sparse evidence stays `Unknown`; a single spike is never presented as proof that capacity must be added.
- **Confirmed saturation episodes** — the most recent 31 days of raw resource history are bucketed to five minutes. Duration is counted only for sufficiently covered buckets with multiple samples, while recurrence, distinct days, longest episode, total duration and near-full peaks are reported separately.
- **Regime-change isolation** — a persistent recent increase or decrease can replace an older baseline, while pre-change saturation remains visible as historical evidence instead of distorting current need.
- **Capacity runway chart** — one glance shows which filesystems cross a threshold in the next year, colored by risk.
- **Interactive modal drill-down** — open any row without leaving or scrolling down the report. The keyboard-accessible modal contains the full evidence and historical chart with min–max band, projection, threshold lines, crossing markers, hover tooltips and drag-to-zoom. When the history shows a statistically confirmed repeating pattern (for example weekly cleanup dips), a second fainter "Possible path" line additionally continues that rhythm around the straight projection — display only: every ETA, marker and number still comes from the linear model, and the extra line never appears without a confirmed pattern.
- **Fast filters & deep links** — Inventory scope previews permission-filtered host group / host / template matches while you type and accepts comma-separated contains terms or explicit regular expressions, plus instant host group, exact host, resource type, data-status, name and capacity-risk filters. Filter state is kept in the URL for bookmarking and sharing.
- **Compact long lists** — main tables default to 25 rows with 25/50/100 row pagination, sticky headings, exact result counts, and a shorter CPU/RAM evidence table; the complete evidence remains in the modal and exports.
- **Separate CPU and RAM workspaces** — each resource has its own tab, filters, deep link and CSV export while reusing the same loaded inventory.
- **Maintenance-aware current state** — active maintenance is visible in tables, exports and detail windows. Maintenance without data collection labels values as last accepted and cannot turn an old value into a current alarm; gaps that pre-date maintenance remain data-quality warnings.
- **Permission-safe shared series cache** — anonymous numeric trend/history shards can be reused between authorized users, but every requested item is re-authorized through Zabbix before a cache read. Host names, groups, inventory, thresholds, current state, forecasts and recommendations are never cached.
- **Exports** — CSV (action list, full filesystem forecast, resource baselines), a standalone HTML report and PNG chart export. CSV output neutralizes spreadsheet formula injection.
- **Dark theme** — every surface has `dark-theme` and `hc-dark` styling. Charts read the palette at render time, so they pick up the dark palette the next time they are drawn (switching the Zabbix theme reloads the frontend anyway).

## Built for scale

- The report loads progressively: the inventory (items, thresholds, current state) renders first, then forecasts stream in small batches with the riskiest filesystems computed first.
- Trend series are fetched per item with hard row caps, sorted before analysis and downsampled to daily points before they reach the browser.
- CPU and memory additionally fetch up to 31 days of raw history in five-minute buckets. Recent history replaces an hourly trend bucket for baseline math only when that hour has enough coverage; sparse buckets can still show peaks but cannot invent sustained duration.
- CPU/RAM forecasts use smaller request batches than disk forecasts so the high-resolution evidence stays within practical frontend memory and timeout limits.
- The server cache is split into calendar shards. Expanding 3M to 6M or 12M reuses authorized shards and loads the missing range. The 15/30/60-minute setting is a refresh interval for mutable current-day/current-month shards only: it controls when newer samples are loaded, and is **not** a retention or deletion timer. Historical shards remain stored until age/size cleanup or a manual clear. An operating-system generation change stops reusing the older generation, whose files then remain protected until cleanup removes them.
- Completed lookback results are also kept for the lifetime of the open page, so returning to an already calculated range is immediate.
- Instant display filters, sorting and pagination reuse the loaded analysis; only changing the inventory scope or pressing **Refresh now** repeats discovery.
- Hosts, items, findings and data-quality lists all have named caps; when a cap is hit the UI says so instead of silently truncating.
- If an item has no hourly trends, a bounded raw-history fallback (7 days, bucketed hourly) is used and marked as low-confidence.

> Forecast dates are planning estimates, not guarantees. The ETA is the projected threshold crossing — not the exact moment a Zabbix problem event fires (triggers may require sustained breaches).

## How the calculations work

All calculations run on the server in `actions/CapacityPlanningData.php`, except the display-only "Possible path" pattern, which the browser computes. The input is Zabbix hourly **trends** (`min`/`avg`/`max`/`num`). CPU and memory also use up to 31 days of raw **history**. Every number in the report comes from the rules below; nothing is machine-learned or tuned per installation.

### 1. Data preparation

- **Daily points**: hourly trend rows are combined into one value per UTC day, averaged with the hour's sample count (`num`) as weight.
- **Analysis windows**: 12 months (365 days), 6 months (183), 3 months (92), 1 month (31), 2 weeks (14) and 1 week (7), all ending now and limited by the selected lookback.
- **Coverage** of a window is the share of its hours that contain data: `distinct hours with data ÷ (window days × 24)`.
- **Per-window statistics**: sample-weighted average, sample-weighted 95th percentile (p95), peak (highest hourly max), percentage of samples above the review and alarm thresholds, trend slope and R².

### 2. Trend line: Theil–Sen estimator

The growth rate is a **Theil–Sen** robust linear regression over the window's daily points:

- **slope** = the median of the slopes between *every pair* of daily points, `(yⱼ − yᵢ) / (xⱼ − xᵢ)`. Long windows are evenly sampled down to 60 points (about 1,800 pairs). At least 3 points are required.
- **intercept** = the median of `y − slope · x`.
- **R²** = `1 − Σ(residual²) / Σ(y − mean)²` against that line, clamped to 0–1. It measures how linear the growth really is.

Theil–Sen is used instead of ordinary least squares because it is robust: up to about 29% of the points can be outliers (a log cleanup, a one-off restore, a spike) without moving the slope.

### 3. Filesystem forecast

**Usable capacity** is `used + free`, otherwise `used ÷ pused`, and on Windows `total`. A Linux `total` is never used on its own, because it includes reserved blocks.

Two independent models are fitted and the worse result wins:

- **Byte model**: growth of used bytes per day. It drives the full date and the free-space thresholds.
- **Percentage model**: growth of used % per day, taken from the `pused` history directly or derived from bytes ÷ capacity. It drives the percentage thresholds.

**Window choice**: the first window in this order that meets both minimums is used:

| Order | Window | Minimum days with data | Minimum coverage |
|---|---|---|---|
| 1 | 3 months | 60 | 55% |
| 2 | 6 months | 90 | 45% |
| 3 | 12 months | 180 | 45% |
| 4 | 1 month | 21 | 55% |
| 5 | 1 week | 5 | 55% |

If none qualifies, the longest window with at least 5 days and 25% coverage is used. Otherwise there is no model and the result is **Unknown**.

**Acceleration**: the 1-month slope replaces the selected slope when both of these hold:
- the 1-month window has ≥ 21 days, ≥ 70% coverage and R² ≥ 0.35;
- its slope is greater than `max(1.5 × selected slope, noise floor)`.

This is the ⚠ "accelerating" marker in the report.

**Noise floor**: a byte slope below `min(1 MiB/day, capacity × 0.00001 per day)` counts as no growth. The percentage model uses the same floor converted to percentage points, or 0.01 pp/day when capacity is unknown.

**ETA**: the remaining distance divided by the slope, in days. A threshold that is already crossed gives 0, and zero or negative growth gives no ETA.

| ETA | Formula |
|---|---|
| Warning / critical (percent) | `(threshold % − current used %) ÷ percentage slope` |
| Warning / critical (free space) | `(current free − free-space threshold) ÷ byte slope` |
| Full | `current free ÷ byte slope` |

When both a percentage and a free-space threshold exist, the earlier ETA is reported together with its basis.

**Forecast confidence**:

- **High**: ≥ 60 days of data, ≥ 70% coverage, R² ≥ 0.55, and the recent 1-month trend points the same way.
- **Medium**: ≥ 21 days and ≥ 45% coverage, with the same direction check.
- **Low**: anything else.

The direction check only counts when the 1-month window itself has ≥ 14 days and ≥ 45% coverage.

**Filesystem risk**:

| Condition | Risk |
|---|---|
| Used % above the critical threshold now, or free space below the critical free-space threshold | Critical |
| Above the warning threshold now | at least High |
| Next critical event (earliest of critical ETA and full ETA) ≤ 7 / 30 / 90 / 180 days | Critical / High / Medium / Watch |
| Warning ETA ≤ 7 / 30 / 90 days | High / Medium / Watch |
| A model exists but no ETA falls in these ranges | Healthy |
| No qualified model | Unknown |

The higher of the critical-ETA and warning-ETA results wins. **Low confidence caps the result at Medium.**

**Threshold macros**: these are resolved with Zabbix precedence (host → templates by depth → global; exact context, then regex context, then the plain macro).

| Threshold | Macro | Default when no macro resolves |
|---|---|---|
| Disk warning % | `{$VFS.FS.PUSED.MAX.WARN:"<fs>"}` | 90 (remote filesystems 85) |
| Disk critical % | `{$VFS.FS.PUSED.MAX.CRIT:"<fs>"}` | 95 (remote filesystems 90) |
| Free-space warning / critical | `{$VFS.FS.MAX.GB.WARN/CRIT}`, then `{$VFS.FS.FREE.MIN.WARN/CRIT}` | disabled |

On Windows the context is the item label, for example `System(C:)`. A bare number in a free-space macro means bytes (`5G` = 5 GiB).

### 4. CPU and memory

CPU and memory are judged on **sustained evidence**, not on a trend line.

**Window choice**: the first window in this order that meets both minimums:

| Order | Window | Minimum days | Minimum coverage |
|---|---|---|---|
| 1 | 1 month | 21 | 55% |
| 2 | 2 weeks | 10 | 60% |
| 3 | 3 months | 60 | 45% |
| 4 | 1 week | 5 | 60% |
| 5 | 6 months | 120 | 40% |
| 6 | 12 months | 180 | 40% |

**Thresholds**:

| Threshold | Source | Default |
|---|---|---|
| CPU alarm | `{$CPU.UTIL.CRIT}` | 99 |
| CPU review | `{$CPU.UTIL.WARN}`, or the legacy `{$CPU.UTIL.WARNING}` (non-Windows only), when configured | alarm − 10 pp (at most 90) |
| Memory alarm | `{$MEMORY.UTIL.MAX}` | 95 |
| Memory review | — | alarm − 5 pp (at most 90) |

The stock Linux and Windows templates only define the alarm macros, so the review level is normally derived from the alarm.

**Baseline risk**: computed from the selected window. "Above" means the share of samples above the threshold.

| Risk | Rule |
|---|---|
| Critical | average ≥ alarm, **or** ≥ 10% above alarm and p95 ≥ alarm |
| High | p95 ≥ alarm and ≥ 2% above alarm, **or** ≥ 20% above review and p95 ≥ review |
| Medium | p95 ≥ review and ≥ 5% above review |
| Watch | the fresh current value is ≥ review |
| Healthy | none of the above |
| Unknown | no window qualified (Watch if the current value is ≥ review) |

**Confirmed saturation episodes**: computed over the last 31 days of raw history in 5-minute buckets.

- A bucket counts as saturated only when all of these hold:
  - the bucket is complete;
  - it holds at least 2 samples;
  - its sample coverage is ≥ 75%;
  - its **minimum** is ≥ 95%.
- Hourly trend rows can also qualify under the same rules (complete, ≥ 2 samples, ≥ 75% coverage, minimum ≥ 95%).
- Consecutive saturated buckets form an episode. Episodes shorter than 15 minutes are ignored, and those of 30 minutes or more count as long.
- A maximum ≥ 99% is recorded as a "near-full" observation, but on its own it never proves duration.
- Episodes are rated as follows:

| Risk | Rule |
|---|---|
| Critical | an ongoing episode ≥ 60 min, **or** ≥ 3 episodes of ≥ 60 min on ≥ 3 days totalling ≥ 360 min |
| High | ≥ 3 episodes of ≥ 30 min on ≥ 3 days, **or** longest ≥ 60 min with a total ≥ 120 min |
| Medium | ≥ 2 episodes on ≥ 2 days, **or** a total ≥ 60 min |
| Watch | ≥ 1 episode, **or** ≥ 6 near-full observations across ≥ 2 days |

The overall CPU or memory risk is the higher of the baseline risk and the saturation risk. When the baseline is Unknown and saturation is Healthy, the result stays Unknown, because no saturation evidence is not the same as proof of health.

**Regime change**: the last *N* days are compared with the *N* days before, for N = 7, 14, 21, 28 and 31. A change is accepted when all of these hold:
- both halves have data on ≥ 75% of their days and ≥ 70% coverage;
- the averages differ by ≥ 10 percentage points **and** ≥ 20% relative;
- ≥ 70% of the recent days stay beyond the prior median ± 6 pp.

The newer level then replaces the older baseline, and pre-change saturation remains visible as history.

**Confidence**:
- **High**: ≥ 80% coverage and ≥ 80% of the window's days present.
- **Medium**: ≥ 60% coverage and ≥ 65% of the days present.
- **Low**: anything else.

A stale current value lowers confidence by one step.

### 5. "Possible path" (chart only)

The detail chart can draw a second, fainter line that continues a repeating pattern, such as weekly cleanups. The browser removes the linear trend from the daily values and runs a **masked autocorrelation** on what remains. The line is drawn only when all of these hold:

- at least 21 days of data and at least 3 complete cycles;
- the correlation at the detected period is a local peak and is ≥ max(0.5, 3 ÷ √pairs);
- the correlation at twice the period is ≥ 0.25;
- the cycle amplitude over the last 3 cycles varies by no more than 2.5×.

This line never changes any ETA, marker, risk or export value. Those always come from the linear model.

## Usage

- The default **analysis lookback** is 3M. Presets are 3M / 6M / 12M; **Custom** accepts 7–730 days ending today. The forecast itself always projects up to one year ahead.
- Use **Inventory scope** to reduce what Zabbix loads and analyzes. Plain text is a case-insensitive contains match. Separate alternatives with commas (for example `Databases, SAP`) or use an explicit regular expression such as `/^prod-(eu|us)-\d+$/i`; supported flags are `i`, `m`, `s`, `u` and `x`. Alternatives within one field are ORed; host group, host and template fields are ANDed together. Permission-filtered matches appear while you type, but the full analysis reloads only when you select **Apply scope**. A literal comma can be written as `\,`; suggestions add the required escaping automatically.
- Use **Displayed results** to filter the loaded report instantly by search text, exact host group, exact host, resource type or data status. These filters also control overview counts and exports.
- The **capacity risk** checkboxes filter calculated Critical / High / Medium / Watch / Healthy / Unknown results. **Action required** selects Critical, High and Medium in one click.
- Use the **CPU** and **RAM** tabs to work with one resource class at a time. Existing `tab=resources` bookmarks are redirected to CPU.
- Open a runway bar or table row to show the detail modal. Drag across the historical timeline to zoom, and use **Reset zoom** to return to the full chart. `⚠` in the Filesystems table's Confidence column means recent growth is accelerating beyond the long-term model; the detail modal shows the same signal as "(accelerating)" and the CSV export carries it in its own column.
- **Export CSV** exports the active tab (Overview → action list); **Export HTML** produces a self-contained report; **Export PNG** exports the open detail chart, or falls back to the Overview runway and risk-distribution charts when no detail window is open.
- All report state is deep-linkable: `lookback` (7–730 days), `tab` (`overview`/`disks`/`cpu`/`memory`/`settings`; `resources` redirects to `cpu`), the scope fields `group`/`host`/`template`, and the display filters `name`, `result_group`, `result_host` (host ID), `type`, `status`, `rows` (25/50/100) and `risks` (comma-separated list, or `none`). Bookmarks restore the exact filtered view.
- The scope suggestion list is keyboard-accessible: ArrowDown opens it and focuses the first match, ArrowUp/ArrowDown cycle, Enter applies the highlighted suggestion and Escape returns to the input.
- CPU/RAM recommendations describe the evidence and urgency. Provisioned vCPU/RAM is shown for context, but the module does not claim an exact amount to add from utilization percentages alone.
- **Settings** is intentionally small: a Super admin can turn the shared cache off or choose a 15/30/60-minute recent-shard refresh interval, inspect its health and clear it. The interval never clears historical cache files. Disabled hosts are always excluded and maintenance behavior is automatic, so neither needs a setting.

## Cache security and deployment

- The cache is server-side disk storage. Without `CAPACITY_PLANNING_CACHE_DIR`, the module creates its own private child below PHP's temporary directory; the production examples use the dedicated `/var/cache/zabbix-capacity-planning` path. It requires private directories (`0700`) and files (`0600`), rejects symbolic links and unsafe/web-accessible paths, and falls back to live Zabbix reads if those checks fail.
- Prefer a dedicated `CAPACITY_PLANNING_CACHE_DIR` on multi-user hosts. The fallback path below the world-writable system temporary directory has a predictable name, so another local OS user could pre-create it; the ownership checks then fail closed — cached data is never exposed, but the report permanently runs on live reads until the directory is reclaimed or a dedicated path is configured.
- Cached files contain numeric item series only. Filenames are hashed, and a live permission-filtered `item.get` check happens before any shared value can be returned to the current user.
- The cache has bounded size and opportunistic age/size cleanup. The default age threshold is 30 days (overridable with `CAPACITY_PLANNING_CACHE_MAX_IDLE_SECONDS`); it is separate from the 15/30/60-minute recent-shard refresh interval and is not an exact purge schedule. Large manual clears are processed in bounded server requests; the browser continues those requests from the same **Clear shared cache** click and reports the accumulated number removed. A cache-schema change or operating-system boot selects a new generation automatically. Because the frontend API does not provide a dependable Zabbix-server service start identifier, a service-only restart is handled with **Refresh now** or **Clear shared cache** when a rebuild is required.
- Set `CAPACITY_PLANNING_CACHE_DIR` to use a dedicated private cache directory, `CAPACITY_PLANNING_CACHE_NAMESPACE` to identify the Zabbix deployment, and `CAPACITY_PLANNING_FRONTEND_ROOT` when an additional frontend/alias root must be excluded. Use `CAPACITY_PLANNING_BOOT_ID` in containers if the orchestrator can rotate a trustworthy generation value on restart, and use the same value for intentional cross-node cache sharing. Size and idle limits can be set with `CAPACITY_PLANNING_CACHE_MAX_BYTES` and `CAPACITY_PLANNING_CACHE_MAX_IDLE_SECONDS`.
- Database and PHP-session caching are deliberately avoided: they normally remain plaintext too, enlarge the Zabbix database or session store, and do not provide the same bounded shared-series behavior. Turn the cache **Off** if local policy forbids protected numeric data on disk; report calculations remain the same and only loading time changes.

## Notes / limits

- Any Zabbix user can open the report; API permissions decide which hosts and items each user sees, so two users can legitimately get different reports. Disabled hosts are excluded before inventory and forecast discovery.
- Inventory scope accepts at most 20 values in total, including at most five regular expressions. Resolution is capped at 5,000 permission-visible matches; if completeness cannot be proven, **Apply scope** is blocked and the UI asks for a narrower expression instead of analyzing a partial allow-list.
- Filesystem discovery covers `vfs.fs.size[...]` and `vfs.fs.dependent.size[...]` keys (used/free/total/pused/pfree); CPU/memory discovery covers `system.cpu.util`, `vm.memory.utilization`, `vm.memory.util`, `vm.memory.size[pused|pavailable|total]` and the `vm.cpu.util`/`vm.mem.util` shorthand keys. Windows perf-counter-only CPU items are not yet recognized.
- Forecasts need numeric (float/uint) items; Zabbix housekeeping limits how far back the analysis can look. Raw history is fetched newest-first with a 50k-row cap, so a safety-limit truncation preserves the evidence closest to now and explicitly reports that older samples were omitted.
- Threshold macros are resolved with `nopermissions` so every user sees the thresholds the server actually alarms on, even when the defining template is not readable to them; only text macros matching the threshold-name prefixes are read, and secret/vault macros are skipped.
- Threshold macros with secret/vault values cannot be read and fall back to defaults (reported under Data quality).
- Conflicting threshold macros inherited from templates at the same depth follow Zabbix 7 precedence: the value from the lowest numeric template ID wins. Ambiguity is reported only when multiple matching candidates remain on that same winning entity.
- Template ancestry is fetched only for templates reachable from the analyzed hosts, in bounded batches with cycle protection. If the explicit safety limit or an incomplete API response prevents full traversal, the Data quality tab reports that macro precedence may be incomplete instead of silently presenting the fallback as authoritative.
- Remote filesystems (NFS/CIFS/…) are classified and get the stricter remote defaults when no macro resolves; block-device I/O saturation is out of scope for this module.

## Calculation regression tests

The tests remain in the public source repository for review and development, but they are non-web test runners and are intentionally excluded by the production installation commands above.

The pure calculation suite covers sparse resource data, isolated maxima, recurring saturation episodes, downward regime changes, invalid percentages, Linux/Windows usable-capacity rules, freshness-first item/family selection, sparse disk history, current disk breaches without a model, and same-depth macro precedence:

```bash
php tests/CapacityPlanningMathTest.php
```

The cache suite covers authorization-before-cache access, namespace failure behavior, range completeness, future-range rejection, incremental extension, private storage, restart generations, hard storage quota, chronological multi-day raw-history assembly, duplicate replacement and inclusive range-boundary fast paths. Its full disk round-trip runs on a POSIX filesystem:

```bash
php tests/CapacityPlanningCacheTest.php
```

The browser regression test covers Settings permissions and lazy loading, live multi-value/regex scope previews, pagination, instant facets, risk filtering, modal keyboard behavior, focus restoration, no-scroll opening, drag-to-zoom and custom lookback. Playwright is pinned in `package-lock.json`; install exactly that dependency set and its Chromium binary before running it:

```bash
npm ci
npx playwright install chromium
export PLAYWRIGHT_CHROMIUM_EXECUTABLE="$(node -e "process.stdout.write(require('playwright').chromium.executablePath())")"
npm test
```

`npm test` first checks that the manifest, package version, PHP build constant, JavaScript build constant and build-versioned JavaScript filename agree, then runs the browser suite. Use `npm run test:build` or `npm run test:ui` to run either stage alone.

Linux/macOS must set `PLAYWRIGHT_CHROMIUM_EXECUTABLE` as shown (or point it at another compatible Chrome/Chromium). On Windows, set the same environment variable when needed; if it is omitted, the test runner uses the standard Google Chrome installation path.

### Release validation matrix

The root-level GitHub Actions workflow runs both PHP suites on Linux with every PHP version supported by Zabbix 7.0 (**8.0, 8.1, 8.2, 8.3, 8.4 and 8.5**), and runs the build-consistency and browser suites on Node.js 20 with the locked Playwright Chromium. It syntax-checks every runtime and test `.php` file and rejects any manifest/PHP/JavaScript version mismatch. Production deployment still excludes `tests/`, `package.json` and `package-lock.json`.
