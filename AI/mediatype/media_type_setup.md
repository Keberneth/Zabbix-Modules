# AI Troubleshooter media type setup

The bundled media type posts problem events to the module's standalone endpoint:

```text
https://your-zabbix-frontend/ai-webhook
```

This is a machine-to-machine endpoint. It does **not** use a Zabbix frontend
session, SID, Guest user, or Guest module permission. Keep Guest disabled and
configure the `/ai-webhook` web-server mapping from `INSTALL.md` before testing.

## 1. Configure the module-side expected secret

In **Monitoring → AI → Settings → Webhook**, leave **Require shared secret**
enabled and configure the value the module should expect. Prefer one of:

- `env:AI_WEBHOOK_SECRET` in **Shared-secret vault / secret reference**;
- `file:webhook_shared_secret`, materialized beneath `ZABBIX_AI_SECRET_DIR`; or
- an inline shared secret, encrypted in the database with the module encryption
  key (stored plaintext under compatibility mode).

The first two forms are PHP-side references. The module resolves them at
request time; they are not values for the Zabbix media-type parameter.

## 2. Configure the sender-side Zabbix macro

Create a Zabbix user macro named:

```text
{$AI.WEBHOOK.SECRET}
```

For the broadest media-type availability, define it as a global macro (or at a
host/template scope guaranteed to be present for every event using this media
type). Then choose one Zabbix macro type:

- **Secret text:** enter the actual generated shared-secret value. Zabbix masks
  it in the frontend and supplies the value to the webhook script.
- **Vault:** enter a reference supported by the vault backend already configured
  for the Zabbix server. Zabbix—not this PHP module—resolves that reference before
  the webhook script runs.

The resolved value sent by `{$AI.WEBHOOK.SECRET}` must exactly match the value
resolved/stored on the module side. Do not put the module syntax
`env:AI_WEBHOOK_SECRET` or `file:webhook_shared_secret` into the Zabbix macro;
those references are meaningful only to the PHP module.

## 3. Import and configure the media type

Import `mediatype/AI_Troubleshooter_mediatypes.yaml`, or paste
`mediatype/media_type_ai_webhook.js` into a Zabbix Webhook media type. Set:

- `ai_webhook_url` to the public/internal HTTPS `/ai-webhook` URL;
- `shared_secret` to `{$AI.WEBHOOK.SECRET}`.

Keep the supplied event parameters/macros unless your action uses a different
event source. The script sends the shared secret in the
`X-AI-Webhook-Secret` header (and in the compatibility payload field); the
module validates it before processing the event.

## 4. Test

The Zabbix **Test** dialog may not have a real event/host context. Replace
unresolved event macros with representative values. If **Post update back to
event** is enabled in module settings, use a real event ID writable by the
configured Zabbix API automation token. Supply the actual shared-secret value
in the test dialog if that dialog does not expand the user macro.

A successful delivery returns structured JSON and reports the number of posted
comment chunks. Authentication failures indicate that the sender macro and the
module-side expected secret do not resolve to the same value.
