# AI module fix bundle

This package fixes two separate problems:

1. Zabbix module action controllers are placed under `actions/`, which is where Zabbix expects custom module action classes.
2. The webhook controller and media type are updated so direct webhook POSTs work correctly and return clearer errors.

## What changed

- Added/placed module controllers in `actions/`:
  - `ChatView.php`
  - `SettingsView.php`
  - `SettingsSave.php`
  - `ChatSend.php`
  - `EventComment.php`
  - `Webhook.php`
- `Webhook.php` now disables SID validation for direct-link access and accepts plain tags or JSON tags.
- The webhook media type script now prints structured module errors properly.
- The example YAML now prefers `hostname={HOST.HOST}` and `event_tags={EVENT.TAGS}`.

## Installation

Copy the contents of this package into your module directory so the resulting tree looks like this:

- `AI/manifest.json`
- `AI/Module.php`
- `AI/actions/*.php`
- `AI/assets/css/ai.css`
- `AI/assets/js/ai.chat.js`
- `AI/assets/js/ai.settings.js`
- `AI/mediatype/media_type_ai_webhook.js`
- `AI/mediatype/media_type_ai_webhook.yaml`
- `AI/lib/*.php`
- `AI/views/ai.chat.php`
- `AI/views/ai.settings.php`

If your module is already enabled, a frontend refresh is usually enough after replacing the files. If Zabbix still returns `Page not found` for `action=ai.webhook`, open **Administration -> General -> Modules**, confirm the module is enabled, and click **Scan directory** if needed.

## Media type

Re-import `mediatype/media_type_ai_webhook.yaml` or paste `mediatype/media_type_ai_webhook.js` into your Webhook media type.

In the **Test** dialog, replace macros with real values. If **Add problem update** is enabled in module settings, use a real writable event ID.
