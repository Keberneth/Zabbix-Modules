# Focused validation

These tests use API/DB facades or browser responses. They do not contact a real
Zabbix installation, change its events, or prove deployment permissions.

Run the PHP scenarios with PHP 8.0+ (8.4 is used locally):

```sh
php tests/RuleEngineTest.php
php tests/SeverityReliabilityTest.php
php tests/OperationalSupportTest.php
php tests/OutputIdentityTest.php
```

Each can also run in a disposable `php:8.4-cli` Docker container, with networking
disabled and this module mounted read-only at `/work`.

The tests exercise source visibility and exclusions, dynamic membership, timing,
read-only preview, applied-state preservation after failed writes, shared event
ownership, durable recovery intent, retry and deletion behavior, human overrides,
portable imports, output collisions, and selected-severity discovery upgrades.

`ValidateTemplates.php` uses Zabbix's own `CYamlImportReader` and
`C70XmlValidator`, plus its `include/` and `vendor/` trees. Set
`ZABBIX_FRONTEND` to their parent directory (default `/usr/share/zabbix`). It
checks the 7.0 import schema and selected-level discovery scenarios. It does not
run the Zabbix server's discovery engine. Validation libraries can be copied
from an existing Zabbix web image into a disposable volume and mounted read-only
alongside the module in the PHP CLI container.

Browser regression checks:

```sh
node --check assets/js/trigger_correlation.js
node tests/ui.test.cjs
```

The browser harness renders the real PHP view and loads the real JavaScript/CSS,
with mocked action responses. It uses Playwright from this checkout or the
sibling Capacity_Planning checkout, plus installed Chrome. Set `PHP_BINARY` to a
local PHP executable; otherwise it uses the cached `php:8.4-cli` Docker image.
Temporary screenshots are written outside the repository. No browser credentials
or live Zabbix session are used.

Before production deployment, check on a disposable Zabbix installation:

1. Upgrade an existing receiver with **Repair automatic setup**, including a
   renamed/customized template. A new High-only rule should discover only High;
   a High/Disaster rule should discover both. Old unselected triggers follow the
   configured lost-discovery lifetime.
2. Preview without side effects, then enable and run a test rule. Check runtime
   token source visibility, discovery timing, output and comments.
3. Exercise shared escalation ownership, recovery, a temporary API failure,
   manual severity override and pending deletion. Include the new `event.get`
   token permission and a disabled/unavailable dynamic source group.
4. Verify activation/recovery delays, dynamic group additions/removals, and a
   backup/import round trip. Imported rules must remain disabled until reviewed.
