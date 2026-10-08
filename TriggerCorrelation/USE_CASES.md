# Use cases — worked examples

Concrete, end-to-end examples for the two features of the **Trigger Correlation**
module. Open the module at `Monitoring → Trigger Correlation`. Substitute your own
host names, trigger names and trigger expressions — the ones here are illustrative
(and match the demo data the module was tested against).

Quick recap of the two features:

| Feature | Tab | What it does |
|---|---|---|
| **Correlation** | Correlation rules | Raises a **new** synthetic problem when a set of source triggers are active together. |
| **Severity escalation** | Severity escalation | **Raises the severity of existing problems** while a source condition holds, then restores it when it clears. |

Both are driven by the same once-a-minute heartbeat of the engine host (`eval.php`).
Once the API URL + token (Settings tab) are set and a rule is saved, the module
sets up the engine host, the evaluation secret and — for correlation rules — a
shared correlation host per set of source hosts by itself, so everything runs
automatically. Follow it all live on the **Dashboard** tab.

---

## Use case 1 — Windows update incident (the original example)

**Goal:** when a patch-infrastructure service is down **and** a specific server is
missing its current cumulative update, that combination is a real incident — raise
it as a single **High** problem.

### 1a. Correlation rule (raise a new High incident)

`Correlation rules` tab → Rule editor:

```text
Rule name:        Windows server update problem
Source triggers:
  Host: sccm01    Trigger: SSMS service is down
  Host: web01     Trigger: Current month CU not installed
Match mode:       All conditions active
Output mode:      Automatic correlation host   (→ "Correlation: sccm01 + web01", created on save)
Correlation ID:   windows_update_web01_current_cu
Severity:         4 - High
Comments:         (leave both on)
```

Result: while **both** source problems are active, a new problem
**`Correlation HIGH: Windows server update problem`** is raised on the receiver
host (and resolves automatically when either source clears). Each problem gets a
`[TC]` comment listing the related triggers.

### 1b. Severity escalation variant (raise the existing CU problem instead)

If you would rather **not** create a new problem and instead make the existing
`Current month CU not installed` problem critical while the patch service is down:

`Severity escalation` tab → editor:

```text
Rule name:                 Raise CU to Disaster while SSMS is down
When these source triggers are active:
  Host: sccm01   Trigger: SSMS service is down
Match mode:                All source triggers active
Raise the severity of:
  Target trigger: Current month CU not installed
  Apply to:       This host only           (web01)
Escalated severity:        5 - Critical/Disaster
Only raise:                on
```

Result: while `SSMS service is down` is active, `Current month CU not installed`
on web01 is bumped **Warning → Disaster** (with a `[TC severity]` comment recording
the change), and dropped back to Warning when SSMS clears.

> Use 1a **or** 1b depending on whether you want a *new* incident or to *re-rank*
> the existing one. You can also use both.

---

## Use case 2 — Database backend degradation → critical service incident

**Scenario:** the MySQL backend `db01` becomes unhealthy in one of two ways —
**CPU pinned ≥ 90% for 15 minutes**, **or** a **deadlock storm** — and the web/app
tier that depends on it starts throwing **database read errors / performance
problems**. On their own each is a routine alert; together it is a **critical
service-down incident**. We want to (a) raise one **Critical** correlation incident,
and (b) make **every** affected web/app problem critical so on-call cannot miss any.

### Triggers used in this example

```text
db01 (MySQL backend):
  "MySQL CPU saturated (15m)"   e.g.  min(/db01/system.cpu.util,15m)>90
  "MySQL deadlock storm"        e.g.  min(/db01/mysql.innodb_deadlocks.rate,15m)>0

web-a, web-b, … (the dependent tier — same trigger NAME on each host):
  "Database read errors"        e.g.  min(/web-a/app.db.read.errors,5m)>0
```

> The two DB symptoms (CPU / deadlocks) are an **OR**: either one means "backend
> unhealthy". How you express that OR depends on the feature — see below.

### 2a. Severity escalation — make every dependent problem Critical (handles the OR natively)

This is the cleanest fit, because the severity feature's match mode is per-rule and
its target scope can fan out across **every** host that has the problem.

`Severity escalation` tab → editor:

```text
Rule name:                 Escalate DB read failures while MySQL is unhealthy
When these source triggers are active:
  Host: db01   Trigger: MySQL CPU saturated (15m)
  Host: db01   Trigger: MySQL deadlock storm
Match mode:                Any source trigger active       ← this is the OR
Raise the severity of:
  Target trigger: Database read errors
  Apply to:       All hosts with this problem     (or: A host group, e.g. "Web servers")
  Problem name match: Exact
Escalated severity:        5 - Critical/Disaster
Only raise:                on
Comments:                  (leave both on)
```

Result (verified): while **either** DB symptom is active, **every** active
`Database read errors` problem — on web-a, web-b and any other host — is raised to
**Disaster**, each with a `[TC severity]` comment ("Raised to Disaster … because:
db01: MySQL CPU saturated (15m)"), and the DB problem is cross-linked with what it
is escalating. When the DB recovers, all of them drop back to their original
severity automatically.

- **OR** between the two DB symptoms → `Match mode: Any source trigger active`.
- **"every host with that problem"** → target `Apply to: All hosts with this
  problem` (matches by the trigger **name**). Scope it down with `A host group`
  (e.g. only your "Web servers" group) when you don't want it truly global.

### 2b. Correlation — raise ONE Critical incident for the service

Use this in addition to (or instead of) 2a when you also want a single, named
**Critical** incident to drive escalation/on-call, rather than (or as well as) many
elevated problems.

Correlation conditions are a flat list with one match mode, so "(CPU **or**
deadlocks) **and** read-errors" cannot be written as one rule directly. Two clean
ways to model it:

**Recommended — push the OR into one Zabbix trigger.** On `db01`, make a single
composite health trigger:

```text
Trigger: "MySQL backend unhealthy"
Expression: min(/db01/system.cpu.util,15m)>90 or min(/db01/mysql.innodb_deadlocks.rate,15m)>0
```

Then one correlation rule:

```text
Rule name:        Critical: web service degraded by MySQL backend
Source triggers:
  Host: db01      Trigger: MySQL backend unhealthy
  Host: web-a     Trigger: Database read errors
Match mode:       All conditions active
Output mode:      Automatic correlation host
Correlation ID:   db_backend_critical_incident
Severity:         5 - Critical/Disaster
```

> A correlation **condition** is a specific host + trigger. If several services
> depend on the DB, add one rule per dependent service (give each a unique
> Correlation ID), or correlate the DB-health trigger with a single "service
> degraded" trigger that aggregates your tier. The fan-out-across-every-host job
> is what **2a (severity escalation)** is for.

**Alternative — two correlation rules (no composite trigger).** One per DB symptom,
each with its **own** Correlation ID (a receiver-LLD rule must have a unique
Correlation ID per receiver host — the editor enforces this):

```text
Rule 1: [db01: MySQL CPU saturated (15m)] + [web-a: Database read errors]
        → Critical,  Correlation ID: db_backend_critical_cpu
Rule 2: [db01: MySQL deadlock storm]      + [web-a: Database read errors]
        → Critical,  Correlation ID: db_backend_critical_deadlock
```

Either DB symptom + the app symptom then raises a Critical correlation problem.

### Combining 2a + 2b

A common pattern is to run **both**: 2b raises one **Critical incident** that pages
on-call, while 2a quietly **re-ranks every dependent problem to Disaster** so the
blast radius is visible at a glance. Both clear themselves when `db01` recovers.

---

## Use case 3 — Cluster availability: one node down is High, both down is Disaster

**Goal:** a two-node cluster. Losing one node is serious (no redundancy left) but
the service still runs; losing both is an outage. Operators should see **one**
problem whose severity tracks the cluster's state — not two unrelated node alarms.

`Correlation rules` tab → Rule editor:

1. **Add the same trigger from every host in a group** → host group = the cluster
   (here *Demo Cluster*), trigger name = *Cluster node is down* → **Add from group**.
   One condition per node is added (the exact name is preferred; with no exact
   match, names that contain the text are used).
2. **Cluster preset** → *Escalate by active count*, tiers **≥1 → High**,
   **≥2 → Disaster** (the top tier is "every node").
3. Output: **Automatic correlation host**. Save → *Created the correlation host
   “Correlation: demo-cluster-node01 + demo-cluster-node02”*.

```text
Rule name:     Demo cluster availability
Conditions:    demo-cluster-node01 → Cluster node is down
               demo-cluster-node02 → Cluster node is down
Match mode:    Escalate by active count   ≥1 → High, ≥2 → Disaster
Output:        Automatic correlation host
```

| Nodes down | Result |
|---|---|
| none | no correlation problem |
| one | `Correlation HIGH: Demo cluster availability` |
| both | `Correlation CRITICAL: Demo cluster availability` (the High one resolves) |

For a three-node cluster the preset gives ≥1 → High, ≥3 → Disaster; add a
≥2 → Average/High tier in between if a two-node loss deserves its own step.

The demo nodes in the local test install are plain trapper flags, so you can play
it through by hand:

```bash
# node01 down → HIGH; add node02 → CRITICAL; set both to 0 → clears
zabbix_sender -z <server> -s demo-cluster-node01 -k demo.cluster.node.down -o 1
zabbix_sender -z <server> -s demo-cluster-node02 -k demo.cluster.node.down -o 1
```

---

## Modelling cheat-sheet

| You want… | Feature / setting |
|---|---|
| A **new** problem when triggers coincide | Correlation rule |
| To **re-rank existing** problems (no new problem) | Severity escalation rule |
| **A and B and C** | Correlation/Severity, `Match mode: All` |
| **A or B** | `Match mode: Any` (Severity), or an `or` in a Zabbix trigger expression (Correlation) |
| **The more that break, the worse it gets** | Correlation `Escalate by active count` with tiers (e.g. ≥2 → High, ≥3 → Disaster) |
| **Cluster: one node → High, all nodes → Disaster** | *Add the same trigger from every host in a group* + **Cluster preset** |
| **(A or B) and C** | Composite Zabbix trigger for `A or B`, then a `Match: All` rule with C |
| Raise **one specific** problem | Severity target `Apply to: This host only` |
| Raise the problem **everywhere it is firing** | Severity target `Apply to: A host group` / `All hosts with this problem` |

### Permissions

- Correlation rules need the API token user to read the source problems and
  `history.push` to the receiver host (and `event.acknowledge` for comments).
- Severity escalation needs the token user to have **Change severity** (Update
  problem) on the target host groups. It does **not** use the receiver template or
  `history.push`.

See `SETUP_RULES.md` for the field-by-field walkthrough and `README.md` for the
full design.
