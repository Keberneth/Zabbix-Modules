# Building correlation rules — step by step

This guide walks through building correlation/escalation rules in the
**Trigger Correlation** module, with a worked example for both output modes.
Open the module at `Monitoring → Trigger Correlation`. Throughout, the example
correlation id is `public_web_app_integration_flow` — replace it with your own.

---

## Key concept: how a correlation problem is raised

The module never creates a Zabbix problem directly. Instead it **writes a
severity number (0–5) to a trapper item**, and a **normal Zabbix trigger on that
item** raises/clears the problem:

```text
your source triggers in problem
        ↓  (module evaluates the rule)
history.push  →  trapper item value = 0..5  (written by host + key)
        ↓  (a normal Zabbix trigger: last(item)=4 → High, etc.)
the correlation problem is raised / cleared
        ↓  (optional) the module comments the related triggers onto the problems
```

So every rule needs **a trapper item with triggers** to land on. The item key in
receiver-LLD mode is always `trigger.correlation.state[<your Correlation ID>]`
(unless you change the *State key template* in Settings).

---

## One-time setup (once per Zabbix)

1. **Settings tab → Zabbix API** → set:
   - **API URL** — click **Detect**, or type e.g. `https://your-zabbix/api_jsonrpc.php`
     (required; the token is never sent to a URL derived from the request host).
   - **API token** — a token whose user can read the source hosts and the
     correlation host group (Read is enough for `history.push` and comments). For
     severity escalation it also needs *change severity* on the target groups.
2. **Save your first rule.** The module then sets up everything the evaluation
   needs — templates, the engine host whose heartbeat runs the evaluation every
   minute, the evaluation shared secret on both sides, and an evaluation URL the
   Zabbix server has proven it can reach. Nothing to import, create or copy.
3. **Run self-check** (Settings) — everything should be green within a minute or
   two. **Repair automatic setup** fixes what is not.

> Already had a hand-made setup? It keeps working: any host with the heartbeat item
> is used as the engine, its URL is left alone, and the secret is only replaced when
> the heartbeat is being refused with "Invalid evaluation token". If you call
> `eval.php` from cron instead, set Settings → Automatic setup → **Evaluation driver**
> to "I call eval.php myself".

---

## Where the result item comes from (this is the part people trip on)

In **Receiver LLD** mode the module writes the severity to
`trigger.correlation.state[<your Correlation ID>]` on the receiver host, **by host
+ key**. You get that item in one of three ways:

- **A) Automatic — the shipped receiver template.** Link **Template Trigger
  Correlation Receiver** to the host. It contains:
  - an **HTTP-agent item** `trigger.correlation.eval` — calls the module every
    minute to run evaluations,
  - a **discovery rule** `trigger.correlation.discovery`, and
  - **item/trigger prototypes** `trigger.correlation.state[{#CORRELATION.ID}]`.

  On each run the module pushes a discovery row with your Correlation ID, Zabbix
  creates `trigger.correlation.state[<id>]` automatically, and the prototype
  triggers raise the problem.
  > ⚠️ **Do not replace `{#CORRELATION.ID}` with a fixed id** in the template —
  > that macro is exactly what makes discovery work.

- **B) Your own template (no discovery).** Create a template for the flow host
  with a plain *Zabbix trapper* item keyed
  `trigger.correlation.state[<your Correlation ID>]` plus value-based triggers,
  and link it to the host. The module pushes by host + key, so it lands on your
  item with no discovery. (Prefer the key `correlation.escalation[<id>]`? Set
  **Settings → State key template** to `correlation.escalation[%s]` and name your
  item to match — see the example template
  `trigger_correlation_manual_item_zabbix_7.yaml`.) Untick **Push LLD discovery
  every evaluation** in Settings to avoid a harmless discovery error.

- **C) Existing trapper item mode.** Create any trapper item + trigger (any key),
  switch the rule's Output mode to **Existing trapper item**, and pick that host +
  item. Here you select the item directly, so the Correlation ID field is not
  used.

Use **one** of these per host — don't link the receiver template *and* a manual
item with the same key on the same host.

**Resolving / no data:** the state item is a Zabbix *trapper* item, so it never
becomes "unsupported" — it just has no data until the first evaluation. The module
writes the current severity every cycle (including **0 to clear**), and pushes a final
**0** when you delete or retarget a firing rule, so a correlation problem resolves
instead of sticking at the last severity (a false positive).

---

## Walkthrough — Automatic correlation host (recommended)

1. **Create the rule** (Correlation rules tab → Rule editor):
   - **Conditions:** add the source triggers, e.g.
     - `web01` — *HTTP frontend down*
     - `db01` — *Database offline*
     - `app02` — *Cannot reach database*
     For a cluster or a farm, use **Add the same trigger from every host in a
     group** instead: pick the host group and the trigger name, and one condition
     per host is added.
   - **Output mode:** `Automatic correlation host (recommended)`. The editor shows
     which host the rule will use — a new one, or the existing host already shared
     by rules over the same source hosts.
   - **Correlation ID:** optional. It defaults to the rule name; it becomes the item
     key `trigger.correlation.state[<id>]` on the correlation host (letters, digits,
     `_ . -`, lower-cased) and must be unique among the rules on that host.
   - **Match mode / severity:** see *Escalation* below (**Cluster preset** sets
     "1 → High, all → Disaster").
2. **Save.** The message tells you what was set up, e.g. *Created the correlation
   host “Correlation: app02 + db01 + web01”*. The first result appears within about
   two minutes: Zabbix needs a heartbeat run to discover the new host's item. Until
   then the rule shows *Setting up — Zabbix is still creating the correlation item*.
3. Follow it on the **Dashboard** tab, or in `Monitoring → Latest data` on the
   correlation host (`trigger.correlation.state[...]`).

Changing a rule's source hosts later moves it to the host for the new set — or, if
no other rule shares its current host, re-keys that host in place so its history
and open problem carry over.

---

## Walkthrough — A receiver host I manage (advanced)

Use this when the correlation must land on a specific host of yours (e.g. one that
represents an integration flow, with its own actions and maintenance).

1. **Link a receiver template to that host:** **Template Trigger Correlation Auto
   Receiver** (no heartbeat — the usual choice) or, only if it should also be the
   engine, **Template Trigger Correlation Receiver**. Both are imported
   automatically once you have saved any rule; or import them from `templates/`.
2. **Create the rule** with **Output mode** `Advanced: a receiver host I manage`,
   **Receiver host** = that host's technical name, and a **Correlation ID**.
   > ⚠️ The Receiver host field is a **host name** — not a template name.
3. **Save**, then **Run evaluation now**. Check `Monitoring → Latest data` on that
   host for `trigger.correlation.state[...]`.

---

## Walkthrough — Existing trapper item

1. **Create a trapper item + triggers.** Import the example
   `templates/trigger_correlation_manual_item_zabbix_7.yaml` and link it to a
   host, or create your own:
   - Item: type **Zabbix trapper**, value type **Numeric (unsigned)**,
     key e.g. `correlation.escalation[public_web_app_integration_flow]`.
   - Triggers: `last(/host/correlation.escalation[public_web_app_integration_flow])=4` → High, etc.
2. **Create the rule:**
   - **Conditions:** your source triggers (as above).
   - **Output mode:** `Existing trapper item`
   - **Output host:** the host that has the item.
   - **Output trapper item:** start typing the item name/key and pick it.
3. **Save** and **Run evaluation now**. The module writes 0–5 to your item and
   your triggers raise the problem.

---

## Escalation (Match mode)

- **All conditions active** — fires the chosen severity only when every source
  trigger is in problem (classic AND).
- **Any condition active** — fires the chosen severity when at least one is.
- **Escalate by active count** — the severity rises with how many source
  triggers are in problem. Define tiers, e.g.:

  | When at least (active count) | Severity |
  |---|---|
  | 2 | High |
  | 3 | Disaster |

  Below the lowest tier the correlation clears (0). This is how you *raise
  criticality when more related problems pile up* — e.g. the more dependent
  apps that fail, the worse the incident.

---

## Walkthrough — Severity escalation (second feature, its own tab)

Use this when you do **not** want a new problem — you want to **raise the severity
of existing problems** while some condition holds, and restore it afterwards. No
receiver template, no LLD, no extra item is needed: it edits the problem (event)
severity in place via `event.acknowledge` and puts it back automatically.

Open the **Severity escalation** tab and create a rule:

1. **Name** it, e.g. *Raise CU to Disaster while SSMS is down*.
2. **When these source triggers are active** — add one or more source conditions
   (host + trigger). A single one is fine, e.g. `sccm01` → *SSMS service is down*.
   Pick the **Match mode** (All / Any / At least N active).
3. **Raise the severity of these problems** — add one or more **targets**. For each:
   - **Target trigger** — type and pick the trigger whose problems to raise, e.g.
     *Current month CU not installed*.
   - **Apply to**:
     - **This host only** — that exact trigger's active problem(s).
     - **A host group** — every active problem with that trigger's **name** in the
       chosen group (a host-group typeahead appears).
     - **All hosts with this problem** — the same, across all hosts.
4. **Escalated severity** — choose the severity to raise to (e.g. *5 - Critical/
   Disaster*) and leave **Only raise** on so a problem that is already higher is
   left alone.
5. **Comments** (optional) — comment each escalated problem (why it was raised)
   and/or cross-link the source problems.
6. **Save**, then **Run** (on the rule) or **Run evaluation now**.

What happens: while *SSMS service is down* is active, every matched *Current month
CU not installed* problem is bumped to Disaster with a `[TC severity]` comment that
records the old → new severity. When SSMS clears (or you disable/delete the rule),
each problem's **original** severity is restored automatically. The same 1-minute
receiver-template heartbeat drives this, so once the API URL + token are set it runs
on its own.

> The token user needs **Change severity** permission (Update problem) on the
> target host groups. Severity escalation does not use `history.push` or the
> receiver template at all.

---

## Comments (optional)

Tick in the rule editor:

- **Comment the correlation problem** — a `[TC]` summary on the correlation
  problem listing every related trigger currently in problem.
- **Cross-link each source problem** — a `[TC]` note on each source problem that
  it is part of this correlation, with the other active members.

Comments are re-posted only when the active trigger set or the severity changes.
The API token user must be allowed to *add problem update* on those hosts. The
action code (default 4 = add message) and chunk size are in
**Settings → Evaluation behavior**.

---

## Verify a rule (checklist)

- [ ] Settings: **API URL** + **API token** set; **Run self-check** is green —
      in particular the *Engine host* line ("The Zabbix server reached eval.php …")
      and *API token can write correlation hosts*.
- [ ] Automatic rules: the rule list shows "on Correlation: … (automatic)". Manual
      rules: the **Receiver host** has **Template Trigger Correlation Receiver** or
      **… Auto Receiver** linked (a template of your own without the module's
      discovery rule + `trigger.correlation.state[{#CORRELATION.ID}]` prototype
      will not work — use "Your own template (B)" / "Existing trapper item (C)").
- [ ] **Correlation ID** = a short unique id (e.g.
      `public_web_app_integration_flow`), **not** the template/item name.
- [ ] After **Run evaluation now**, `trigger.correlation.state[<your id>]` shows
      under Latest data on the correlation/receiver host (*Setting up* for a minute
      or two after the rule was saved is normal).
- [ ] Something still red? **Repair automatic setup** (Settings).
