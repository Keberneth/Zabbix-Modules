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

1. **Settings tab** → set:
   - **API URL** — e.g. `https://your-zabbix/api_jsonrpc.php` (required; the token
     is never sent to a URL derived from the request host).
   - **API token** — a token whose user can read the source hosts and write
     history to the receiver host. If you enable comments, it also needs
     *add problem update* permission on those hosts.
   - **Evaluation shared secret** — type any long random string. **Required:** while
     it is blank the evaluation endpoint answers every call with *Access denied*.
     It is stored as a one-way hash in the (shared) Zabbix database, so on
     Docker/split installs every frontend container uses it — no env var needed.
     (The *Evaluation token env var* field is just an optional alternative.)
2. **Run evaluation automatically:** the receiver template ships an HTTP-agent
   item that calls the module every minute. On the receiver host set the macros
   (host-level, **not** global — the template default shadows a global macro):
   - `{$TRIGGER.CORRELATION.URL}` = `https://your-zabbix/modules/TriggerCorrelation/eval.php`
     (full eval-endpoint URL, reachable from the Zabbix **server**, not just the host name).
   - `{$TRIGGER.CORRELATION.TOKEN}` = **exactly** the Evaluation shared secret above.
3. Click **Test API** (top right) — it should report a host count.

> Testing the eval URL in a browser always returns *Access denied* (there is no token
> header). Test from the UI with **Run evaluation now** instead, or let the HTTP-agent
> item run with the matching macro token.

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

## Walkthrough — Receiver LLD (recommended)

1. **Import** `templates/trigger_correlation_receiver_zabbix_7.yaml`
   (`Data collection → Templates → Import`). It creates **Template Trigger
   Correlation Receiver**.
2. **Link the template to a host.** Use the default **Zabbix Correlation Engine**,
   or your own host representing the flow (e.g. *Public Web App Integration Flow*).
   > ⚠️ The **Receiver host** field is a **host name**, and that host must have the
   > **module's** receiver template linked. It is **not** a template name.
3. **Create the rule** (Rules tab → Rule editor):
   - **Conditions:** add the source triggers, e.g.
     - `web01` — *HTTP frontend down*
     - `db01` — *Database offline*
     - `app02` — *Cannot reach database*
   - **Output mode:** `Receiver LLD template`
   - **Receiver host:** the host you linked the template to.
   - **Correlation ID:** a **short unique id you choose**, e.g.
     `public_web_app_integration_flow`.
     > ⚠️ The Correlation ID is **not** an item name and **not** a template name.
     > The module creates `trigger.correlation.state[public_web_app_integration_flow]`
     > on the receiver host. Letters, digits, `_ . -` only (lower-cased).
   - **Match mode / severity:** see *Escalation* below.
4. **Save**, then **Run evaluation now**. The first run may say *discovery
   pending* until Zabbix processes the LLD — run it again after ~1 minute. Check
   `Monitoring → Latest data` on the receiver host for
   `trigger.correlation.state[...]`.

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

## Verify a receiver-LLD rule (checklist)

- [ ] **Receiver host** = a host that has **Template Trigger Correlation
      Receiver** linked. (A custom template of your own that does not contain the
      module's discovery rule + `trigger.correlation.state[{#CORRELATION.ID}]`
      prototype will **not** work for receiver-LLD — use the shipped template, or
      switch to "Your own template (B)" / "Existing trapper item (C)" above.)
- [ ] **Correlation ID** = a short unique id (e.g.
      `public_web_app_integration_flow`), **not** the template/item name.
- [ ] Settings: **API URL** + **API token** set, **Test API** passes.
- [ ] Receiver host macros `{$TRIGGER.CORRELATION.URL}` and
      `{$TRIGGER.CORRELATION.TOKEN}` set (for automatic 1-minute evaluation).
- [ ] After **Run evaluation now**, `trigger.correlation.state[<your id>]` shows
      under Latest data on the receiver host (re-run once if *discovery pending*).
