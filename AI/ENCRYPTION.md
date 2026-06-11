# Encrypting stored secrets at rest

The AI module stores a few secrets in the Zabbix **module configuration** (a row
in the `module` database table):

- provider **API keys**
- the **Zabbix API token**
- the **NetBox token**
- the **webhook shared secret**

By default these are stored **unencrypted**. Anyone with database, backup or
configuration‑export access can read them. This guide shows how to turn on
**encryption at rest** so a database dump no longer exposes them.

> **TL;DR** — Set the `ZABBIX_AI_ENCRYPTION_KEY` environment variable for the
> PHP process to a long random value, reload PHP, then open **AI → Settings** and
> click **Save** once. The banner turns green and your secrets are encrypted.

---

## How it works (in one minute)

- The module derives a 256‑bit key from `ZABBIX_AI_ENCRYPTION_KEY` and encrypts
  secrets with **AES‑256‑GCM** (libsodium, with an OpenSSL fallback).
- The key is **never written to the database**, so a DB dump/backup/export only
  contains ciphertext.
- It is **opt‑in and safe**:
  - With no key set, the module behaves exactly as before (plaintext). Nothing
    breaks just by upgrading.
  - Encrypted values are tagged `enc:v1:`. Any untagged value is treated as
    plaintext, so old secrets keep working and are upgraded to ciphertext the
    next time you **Save**.
  - If the key is ever missing or wrong, the module **fails safe**: the affected
    feature reports the secret as unavailable instead of crashing the UI.

---

## Before you start

- Decide the value of the key. A long random string is best:

  ```bash
  openssl rand -hex 32
  ```

  Hex is recommended over base64 because it has no `+ / =` characters to escape
  in config files.

- **Save this value in your password manager.** If you lose it, the encrypted
  secrets cannot be recovered — you would have to re‑enter them after setting a
  new key.

- If you run **more than one frontend node** (multi‑server or Docker), you must
  set the **same** value on **every** node. A node with a different or missing
  key cannot decrypt secrets saved elsewhere.

---

## Important gotcha: php‑fpm clears the environment

php‑fpm runs with `clear_env = yes` by **default**, which means it **strips** the
ambient environment. So the following do **NOT** work on their own:

- `export ZABBIX_AI_ENCRYPTION_KEY=...` in a shell
- adding the variable to `/etc/environment`
- a systemd `Environment=` on an unrelated unit

The reliable way is the php‑fpm pool **`env[...]`** directive, which is applied
to the workers even when `clear_env = yes`. That is what the steps below use.

---

## Setup — php‑fpm + nginx/Apache (most common)

### 1. Generate the key

```bash
openssl rand -hex 32
```

Copy the output.

### 2. Find the pool that serves the Zabbix frontend

It is usually the default `www` pool:

```bash
ls /etc/php-fpm.d/
```

If you have several pools and are unsure which one serves Zabbix, find the socket
the web server talks to and match it to the pool's `listen =` line:

```bash
grep -riE 'fastcgi_pass|SetHandler|proxy:unix' /etc/nginx /etc/httpd 2>/dev/null
grep -rn 'listen' /etc/php-fpm.d/
```

### 3. Add the env directive

Edit the pool file (e.g. `/etc/php-fpm.d/www.conf`) and add this line inside the
`[www]` section:

```ini
env[ZABBIX_AI_ENCRYPTION_KEY] = "PASTE_THE_HEX_VALUE"
```

The pool file now contains the key in plaintext on disk, so restrict it:

```bash
sudo chmod 640 /etc/php-fpm.d/www.conf
```

This is the intended trade‑off: the secret moves **out of the database** (which
gets dumped/backed up/exported) and onto the server filesystem, protected by the
key file.

### 4. Reload php‑fpm

```bash
sudo systemctl reload php-fpm
```

(Use the right service name if yours differs, e.g. `php-fpm8.3`.)

### 5. Verify and migrate

1. Re‑open **AI → Settings**.
2. The banner at the top of the **Providers** section should now read
   **“Secret storage: encrypted at rest”** (green).
3. Click **Save** once. This re‑encrypts the secrets that are already stored in
   the database (your existing plaintext API key becomes ciphertext).

Done.

---

## Setup — Apache + mod_php

With mod_php there is no php‑fpm pool. Set the variable for the Apache process so
the PHP runtime inherits it.

Add to the Zabbix vhost (or a conf in `conf.d`):

```apache
SetEnv ZABBIX_AI_ENCRYPTION_KEY "PASTE_THE_HEX_VALUE"
```

Then reload Apache:

```bash
sudo systemctl reload httpd      # RHEL/Alma/Rocky
# or: sudo systemctl reload apache2   # Debian/Ubuntu
```

> Note: `SetEnv` is read by PHP via `$_SERVER`/`getenv()` in mod_php setups. If
> for any reason it is not visible, set it on the Apache **service** instead with
> a systemd drop‑in:
>
> ```bash
> sudo systemctl edit httpd
> ```
> ```ini
> [Service]
> Environment=ZABBIX_AI_ENCRYPTION_KEY=PASTE_THE_HEX_VALUE
> ```
> ```bash
> sudo systemctl daemon-reload && sudo systemctl restart httpd
> ```

Then verify and migrate as in step 5 above.

---

## Setup — Docker / docker‑compose

Set the variable on **every** frontend container.

`docker-compose.yml`:

```yaml
services:
  zabbix-web-nginx-pgsql:        # use your actual web service name
    environment:
      ZABBIX_AI_ENCRYPTION_KEY: "PASTE_THE_HEX_VALUE"
```

For better secret hygiene, use an `.env` file or Docker/Compose secrets instead
of inlining the value:

```yaml
    environment:
      ZABBIX_AI_ENCRYPTION_KEY: "${ZABBIX_AI_ENCRYPTION_KEY}"
```

Recreate the container(s):

```bash
docker compose up -d
```

The official Zabbix web images run php‑fpm internally but pass container
environment variables through to it, so no pool edit is needed. Verify and
migrate as in step 5 above.

---

## Multi‑server frontends

If multiple frontend nodes serve the same Zabbix database:

- Put the **identical** `ZABBIX_AI_ENCRYPTION_KEY` on **every** node (same value).
- A node missing the key (or with a different one) will **fail safe**: the
  secret reads back empty and that feature reports it as unavailable on that node
  only — nothing is corrupted.

---

## Verifying it worked

- **AI → Settings** shows the green **“encrypted at rest”** banner.
- In the database, the stored values are now prefixed with `enc:v1:` instead of
  the raw key. For example (PostgreSQL):

  ```sql
  SELECT config FROM module WHERE id = 'custom_ai';
  ```

  The `api_key` / `token` / `shared_secret` fields should look like
  `enc:v1:....` rather than the plaintext value.

---

## Key management

- **Back up the key** in a password manager / secrets store. It is the only way
  to decrypt the stored secrets.
- **Rotating the key:** set a new `ZABBIX_AI_ENCRYPTION_KEY`, reload PHP, then go
  to **AI → Settings**, re‑enter each secret, and **Save**. (Secrets are
  re‑encrypted with the new key on save. Old ciphertext encrypted with the
  previous key can no longer be read, which is expected on rotation.)
- **Lost the key:** set a new one, then re‑enter the API keys/tokens/secret in
  **AI → Settings** and **Save**.

---

## Troubleshooting

**Banner still says “not encrypted” after a reload.**

1. Wrong pool — you edited a pool that does not serve the frontend. Confirm which
   pool the web server uses (see step 2) and add the line there.
2. php‑fpm was not actually reloaded:
   ```bash
   sudo systemctl status php-fpm      # check the last reload time
   ```
3. The variable is set in the shell / `/etc/environment` only — php‑fpm strips
   that (`clear_env = yes`). Use the pool `env[...]` directive instead.
4. Typo in the variable name. It must be exactly `ZABBIX_AI_ENCRYPTION_KEY`.

**A feature reports the secret as unavailable after enabling encryption.**

The key in the environment does not match the key used to encrypt the stored
value (e.g. a node has a different key, or the key changed). Set the correct key
and reload, or re‑enter the secret in **AI → Settings** and **Save**.

---

## Alternative: keep secrets out of the database entirely

Instead of (or in addition to) encryption, you can leave a secret field **blank**
in the UI and set its **“Secret environment variable”** name. The module then
resolves the value from the environment at request time — it is never stored in
the database at all. This pairs well with a secrets manager (e.g. a
Vault‑populated environment variable) and is the closest equivalent to a
“vault secret”.

The environment variable for that field must be visible to the PHP process the
same way as above (php‑fpm `env[...]`, Apache `SetEnv`/`Environment=`, or a
container `environment:` entry).
