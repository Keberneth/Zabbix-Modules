# Secret storage, local vault references, and database encryption

The AI module supports three secret-storage modes. Use them in this order of
preference:

1. **Vault/secret reference (recommended):** the Zabbix database stores only an
   `env:NAME` or `file:NAME` reference. The secret is supplied to PHP at runtime.
2. **Encrypted inline secret:** the secret is stored as authenticated ciphertext
   (`enc:v1:...`) in the Zabbix module configuration.
3. **Plaintext compatibility mode:** an explicitly warned, development-only
   fallback. It also governs whether pending confirmations, sensitive reads and
   bulk previews can run without an encryption key, and how their identity
   digests are derived. Do not use this in production.

No external vault service is required. A local encrypted source such as an
[Ansible Vault](https://docs.ansible.com/projects/ansible/latest/vault_guide/vault.html)
file can deploy secrets into protected runtime files, and the AI module reads
those files through `file:NAME` references. The same interface also works with
any deployment or vault agent that can materialize a file or inject an
environment variable.

Ansible Vault is a **deployment-time** encrypted source, not a runtime format
understood by PHP. The module does not decrypt Ansible Vault files and must not
be given or configured with an Ansible vault password. Ansible (or another
deployment process) decrypts the durable source and materializes a narrowly
scoped runtime file; PHP receives read-only access only to that runtime value.

The module stores or references these credentials:

- provider API keys
- provider custom-header JSON
- Zabbix API tokens
- NetBox tokens
- the standalone webhook shared secret

Pending writes, sensitive reads, and bulk previews are encrypted in the local
state directory whenever an encryption key is configured. Under plaintext
compatibility mode they are stored unencrypted, so the state directory then
holds readable action parameters. Confidentiality is lost; tamper detection is
not, because each unencrypted record carries an HMAC keyed with the operator's
server session ID, of which only a SHA-256 is written to the record. A modified
record is refused with "The pending action was modified after it was staged."
That mode is for isolated development systems only.

## Recommended: keep credentials out of the database

Every secret-reference field in **AI → Settings** accepts:

- `env:OPENAI_API_KEY` — read an environment variable at request time
- `file:openai_api_key` — read a protected runtime file beneath the directory in
  `ZABBIX_AI_SECRET_DIR`
- `OPENAI_API_KEY` — legacy shorthand for `env:OPENAI_API_KEY`

When a reference is saved, the module removes the old inline copy. If the
reference is missing or empty, the request fails closed; it never falls back to
a stale database secret.

`file:` accepts a logical name only, not a path. Values such as `file:../secret`
and `file:/etc/passwd` are rejected. The target must remain inside the
server-admin-controlled secret directory after symlink resolution, must not be
group/world writable on POSIX systems, and must be no larger than 64 KiB. This
prevents a settings user from turning a credential field into an arbitrary file
reader.

### Simple local-vault pattern

This pattern needs no network vault:

1. Keep the source values in your existing local encrypted deployment file
   (for example, Ansible Vault).
2. At boot/deploy time, materialize only provider/integration values under a
   runtime directory such as `/run/zabbix-ai-provider-secrets`.
3. Give the PHP-FPM worker read access, but no write access.
4. Put `file:NAME` in the corresponding AI Settings field.

Example directory policy for a PHP-FPM worker in the `apache` group:

```bash
sudo install -d -o root -g apache -m 0750 /run/zabbix-ai-provider-secrets
sudo install -o root -g apache -m 0640 /secure/deploy-output/openai_api_key \
  /run/zabbix-ai-provider-secrets/openai_api_key
```

Do not keep `/secure/deploy-output/openai_api_key` as a persistent plaintext
file. The example represents the output of your deployment step; remove it or
write directly to the runtime directory. `/run` is normally memory-backed and
cleared at boot, so arrange for the deployment/service to repopulate it.

An Ansible-style deployment can use a vaulted variable without exposing it in
logs:

```yaml
- name: Create Zabbix AI runtime secret directory
  ansible.builtin.file:
    path: /run/zabbix-ai-provider-secrets
    state: directory
    owner: root
    group: apache
    mode: "0750"

- name: Materialize provider key from encrypted variables
  ansible.builtin.copy:
    content: "{{ vault_openai_api_key }}\n"
    dest: /run/zabbix-ai-provider-secrets/openai_api_key
    owner: root
    group: apache
    mode: "0640"
  no_log: true
```

The playbook controller supplies the vault password through its normal secure
Ansible workflow. Never copy that password into PHP-FPM, AI Settings, the
Zabbix database, or the runtime secret directory.

Expose only the non-secret directory path to PHP-FPM:

```ini
env[ZABBIX_AI_SECRET_DIR] = "/run/zabbix-ai-provider-secrets"
```

Reload PHP-FPM, then enter this in the provider's **Vault / secret reference**
field:

```text
file:openai_api_key
```

The database now contains the reference, not the API key. Provider custom
headers can use a separate reference whose file contains the complete JSON
object.

### Environment references

Use `env:NAME` when your container, service manager, or deployment system
already injects credentials into the PHP process. To prevent AI Settings from
reading unrelated process credentials, environment references are limited to
the built-in names `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `ZABBIX_API_TOKEN`,
`NETBOX_TOKEN`, and `AI_WEBHOOK_SECRET`; any name beginning
`ZABBIX_AI_SECRET_`; or exact names listed by the server administrator in
`ZABBIX_AI_ALLOWED_SECRET_ENV_VARS`. Encryption/control variables are always
reserved and cannot be referenced.

PHP-FPM commonly runs with `clear_env = yes`, so allow the specific variable in
the serving pool:

```ini
env[OPENAI_API_KEY] = $OPENAI_API_KEY
```

Then set `env:OPENAI_API_KEY` in AI Settings. A missing configured variable is a
hard error. For a custom existing name, also configure the non-secret allowlist:

```ini
env[MY_PROVIDER_KEY] = $MY_PROVIDER_KEY
env[ZABBIX_AI_ALLOWED_SECRET_ENV_VARS] = "MY_PROVIDER_KEY,MY_GATEWAY_HEADERS"
```

Both lines are required for a custom name: the first exposes the value to the
serving PHP-FPM pool, and the second authorizes AI Settings to resolve that
name. Existing installations may already contain a bare custom name in a
legacy `*_env` field. Before upgrading/testing it, add that name to the
allowlist; after saving, the UI normalizes it to the explicit `env:NAME` form.

### Testing unsaved values

The provider and NetBox **Test connection** buttons may use a freshly entered
inline key/token for that single request even when no encryption key is
configured. The value is transient and is not saved to the database. A new or
changed `env:`/`file:` reference and its provider/NetBox destination must be
saved first, then tested; this prevents an unsaved reference from being paired
with a different stored destination. A successful transient test does not mean
the inline value was persisted.

### SELinux, mod_php, and containers

On an SELinux-enforcing host, runtime secret directories and files need a
read-only web-content label, not the writable state-directory label. For
example, after materializing both runtime directories:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t '/run/zabbix-ai-provider-secrets(/.*)?'
sudo semanage fcontext -a -t httpd_sys_content_t '/run/zabbix-ai-master(/.*)?'
sudo restorecon -Rv /run/zabbix-ai-provider-secrets /run/zabbix-ai-master
```

Because `/run` is recreated at boot, make the materialization unit/playbook run
`restorecon` after it creates the files. Keep filesystem access read-only for
the PHP worker; `httpd_sys_rw_content_t` is only for the module's separate
writable state/log/report directories.

With Apache **mod_php**, non-secret paths/control values can be set in the
serving virtual host, for example:

```apache
SetEnv ZABBIX_AI_SECRET_DIR /run/zabbix-ai-provider-secrets
SetEnv ZABBIX_AI_ENCRYPTION_KEY_FILE /run/zabbix-ai-master/db_encryption_key
SetEnv ZABBIX_AI_ALLOWED_SECRET_ENV_VARS MY_PROVIDER_KEY
```

Do not put an API key or master-key value directly in `SetEnv`; that merely
stores it in plaintext in Apache configuration. Prefer `file:NAME`, or inject a
custom environment value into the Apache service and expose it with
`PassEnv MY_PROVIDER_KEY`. `SetEnv`/`PassEnv` visibility can vary with the
serving SAPI, so verify it from the AI Settings page; a CLI `php` process is not
that SAPI.

For Docker/Compose, mount runtime secrets read-only and expose only paths:

```yaml
services:
  zabbix-web:
    environment:
      ZABBIX_AI_SECRET_DIR: /run/zabbix-ai-provider-secrets
      ZABBIX_AI_ENCRYPTION_KEY_FILE: /run/zabbix-ai-master/db_encryption_key
    volumes:
      - ./runtime/provider-secrets:/run/zabbix-ai-provider-secrets:ro
      - ./runtime/master:/run/zabbix-ai-master:ro
```

The host paths above should themselves be materialized from a durable encrypted
source. As an alternative, a container orchestrator may inject an allowed
environment variable directly:

```yaml
services:
  zabbix-web:
    environment:
      ZABBIX_AI_SECRET_OPENAI: "${ZABBIX_AI_SECRET_OPENAI}"
```

`ZABBIX_AI_SECRET_*` names are allowed automatically; a different custom name
also needs `ZABBIX_AI_ALLOWED_SECRET_ENV_VARS` in the container. Container
environment values are often visible to users allowed to inspect the container,
so prefer read-only secret mounts where possible. Never bake resolved secrets
into the image or commit them in `compose.yaml`/`.env`; the substitution above
assumes the deployment environment supplies the value securely.

## Encrypt inline secrets in the database

Inline values entered into password/token fields are encrypted before they are
saved. The module derives a 256-bit key and uses Sodium `secretbox` when
available, otherwise AES-256-GCM through OpenSSL. Database dumps, backups, and
module exports contain `enc:v1:...` ciphertext instead of the credential.

The encryption key is what keeps pending confirmations confidential at rest.
Configure it for any non-development deployment even when every long-lived
credential uses a reference, so Read & Write actions, sensitive reads and bulk
previews are staged as ciphertext.

### Recommended master-key source: a protected runtime file

Generate one long random key:

```bash
openssl rand -hex 32
```

Immediately place the generated value in a **durable encrypted source** (for
example, an encrypted password manager record or Ansible Vault) and back that
source up according to your recovery policy. The plaintext file under `/run`
is only a runtime materialization; it is cleared at boot and is not a backup.
Losing the durable key makes existing `enc:v1:` values unrecoverable.

Have the local encrypted deployment mechanism materialize the master key
separately from provider/integration secrets, in a **separate directory that is
not `ZABBIX_AI_SECRET_DIR`**, for example:

```text
/run/zabbix-ai-master/db_encryption_key
```

Use owner/group permissions such as `root:apache 0640`, and point PHP-FPM at the
file path:

```ini
env[ZABBIX_AI_ENCRYPTION_KEY_FILE] = "/run/zabbix-ai-master/db_encryption_key"
```

Never place the master key beneath `ZABBIX_AI_SECRET_DIR`. The module rejects a
`file:NAME` reference that resolves to the configured master-key file, but
separate directories also keep the trust boundary obvious and prevent future
misconfiguration.

Only the path is present in the pool configuration. The module refuses a
missing, empty, relative, oversized, group/world-writable, or unreadable key
file. `ZABBIX_AI_ENCRYPTION_KEY` remains supported for existing deployments,
but if both are set, the direct environment value takes precedence.

There is no way for an unattended service to decrypt data without access to
key material at runtime. The goal is to avoid a long-lived plaintext key in the
PHP-FPM pool and avoid provider credentials in the database—not to claim that a
running root/PHP compromise cannot read runtime secrets.

### Migrate existing plaintext database values

1. Configure `ZABBIX_AI_ENCRYPTION_KEY_FILE` (or the legacy direct key) on every
   frontend node.
2. Reload/restart the PHP web process.
3. Open **AI → Settings**. While legacy values remain, the banner should show
   **“Encryption ready — plaintext migration pending”**, along with the count.
4. Click **Save settings** once.
5. Reopen the page and verify the banner is green and says **“Secret storage:
   encrypted at rest”** with the expected backend/key source.
6. Untick the persisted **Allow plaintext secrets** compatibility checkbox if it
   was enabled. Also remove `ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS` from the serving
   process environment, reload PHP, and verify that the page no longer reports
   either plaintext override as active.

Existing inline plaintext credentials are replaced with `enc:v1:...`
ciphertext. Alternatively, set an `env:`/`file:` reference; saving it removes
the inline database copy instead.

## Multiple frontend nodes and containers

- Every node that reads encrypted configuration or pending actions must receive
  the identical encryption key.
- Every node must be able to resolve the same logical `file:NAME` references
  from its own `ZABBIX_AI_SECRET_DIR`.
- A missing or different key fails closed; it never downgrades ciphertext to
  plaintext.
- Runtime secret files should be mounted or materialized independently in each
  container/node rather than copied into an image.

## Plaintext compatibility mode

For an isolated development system, a Super Admin can open **AI → Settings**
and enable:

> Allow inline secrets to be read and saved as plaintext when encryption is
> unavailable

The first enable requires a separate risk acknowledgment. The page displays a
permanent danger warning, and the before/after state is included in settings-
change audit entries when module audit logging is enabled. It exposes inline
provider keys/headers, Zabbix and NetBox tokens, and the webhook secret in the
database, dumps, backups, and configuration exports.

The server-side environment override remains supported:

```text
ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS=1
```

The Settings checkbox cannot disable an environment-managed override. Either
form of override also lets pending confirmed actions, sensitive reads and bulk
previews run with no encryption key. The cost is that their staged payloads are
written unencrypted under the module state path, and provider/Zabbix/NetBox
confirmation identity digests fall back from a keyed HMAC to an unkeyed,
purpose-domain-separated SHA-256 (prefixed `u1:` so it can never be confused
with a keyed value). A Sodium or OpenSSL backend is required only for
encrypted-at-rest operation.

## Verification

- **AI → Settings** reports `encrypted at rest` and the expected key source.
- Secret-reference fields show `env:...` or `file:...` and inline stored-secret
  indicators are absent after saving a reference.
- In the database, inline secrets begin with `enc:v1:`; referenced secrets are
  absent and only the reference name is stored.
- Removing a referenced environment variable/file makes the relevant provider
  or integration fail with a secret-reference error.

For PostgreSQL, an administrator can inspect the module row:

```sql
SELECT config FROM module WHERE id = 'custom_ai';
```

Never paste that output into a ticket while plaintext compatibility mode is
enabled.

## Rotation and recovery

- **Referenced provider/token secret:** update the environment variable or
  runtime file. The next request reads the new value; no database save is
  required.
- **Encryption key:** pending confirmations created with the old key become
  invalid. Re-enter any inline credentials after installing the new key, then
  save so they are encrypted with it. Coordinate the change across all frontend
  nodes.
- **Lost encryption key:** existing `enc:v1:` values cannot be recovered.
  Configure a new key and re-enter those credentials, or replace them with
  references.

## Troubleshooting

**The banner reports encryption is required, or unencrypted compatibility mode.**

- If compatibility mode is intentionally enabled, the page reports unencrypted
  compatibility storage rather than "encryption required", and chat,
  host/problem context, tool calls, confirmations, pending writes, sensitive
  reads and bulk previews all function without a key.
- Confirm the variable is visible to the PHP-FPM pool, not only your shell.
- Confirm `ZABBIX_AI_ENCRYPTION_KEY_FILE` is absolute and its file is readable
  by the PHP worker.
- On POSIX, confirm the key file and its containing directory are not group/world
  writable.
- Confirm Sodium or OpenSSL is available.

**"Cannot bind provider authentication identity without ZABBIX_AI_ENCRYPTION_KEY…"**

- This must no longer appear while compatibility mode is active. If it does, the
  Settings checkbox did not persist (check the save confirmation) or the
  environment override is not visible to the PHP-FPM pool.

**A `file:NAME` reference is refused.**

- Set an absolute `ZABBIX_AI_SECRET_DIR` for the PHP process.
- Use a flat logical name; slashes and `..` are forbidden.
- Keep the resolved target inside that directory, including through symlinks.
- Use a non-empty file no larger than 64 KiB and remove group/world write bits.

**A stored plaintext secret was refused.**

Choose one safe migration path: configure the encryption key and save once, or
replace the inline value with an `env:`/`file:` reference. Use plaintext
compatibility mode only for isolated development.
