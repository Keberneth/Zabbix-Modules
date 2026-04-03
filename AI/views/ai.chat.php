<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$chat_send_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat.send')
    ->getUrl();

$event_comment_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.event.comment')
    ->getUrl();

$hosts_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat.hosts')
    ->getUrl();

$problems_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat.problems')
    ->getUrl();

$execute_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat.execute')
    ->getUrl();

$settings_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.settings')
    ->getUrl();

$providers = is_array($data['providers'] ?? null) ? $data['providers'] : [];

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $ai_theme = 'dark';
    }
}

ob_start();
?>
<div
    id="ai-chat-root"
    class="ai-page ai-chat-page"
    data-ai-theme="<?= $h($ai_theme) ?>"
    data-send-url="<?= $h($chat_send_url) ?>"
    data-comment-url="<?= $h($event_comment_url) ?>"
    data-chat-csrf="<?= $h(CCsrfTokenHelper::get('ai.chat.send')) ?>"
    data-comment-csrf="<?= $h(CCsrfTokenHelper::get('ai.event.comment')) ?>"
    data-csrf-field-name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>"
    data-history-limit="<?= $h($data['history_limit'] ?? 12) ?>"
    data-has-zabbix-api="<?= $h(($data['has_zabbix_api'] ?? false) ? '1' : '0') ?>"
    data-hosts-url="<?= $h($hosts_url) ?>"
    data-problems-url="<?= $h($problems_url) ?>"
    data-execute-url="<?= $h($execute_url) ?>"
    data-execute-csrf="<?= $h(CCsrfTokenHelper::get('ai.chat.execute')) ?>"
>
    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('AI chat')) ?></h1>
            <p class="ai-muted">
                Session-only chat. Conversation history stays in this browser tab via sessionStorage and is not written by the module.
            </p>
        </div>
        <div class="ai-header-actions">
            <a class="btn-alt" href="<?= $h($settings_url) ?>"><?= $h(_('Open settings')) ?></a>
        </div>
    </div>

    <div class="ai-grid">
        <section class="ai-card ai-context-card">
            <h2><?= $h(_('Session context')) ?></h2>

            <?php if (!$providers): ?>
                <div class="ai-warning">
                    <?= $h(_('No provider is configured yet. Open AI settings and add at least one provider.')) ?>
                </div>
            <?php endif; ?>

            <label class="ai-label" for="ai-provider-id"><?= $h(_('Provider')) ?></label>
            <select id="ai-provider-id" class="ai-input" <?= $providers ? '' : 'disabled' ?>>
                <?php foreach ($providers as $provider): ?>
                    <?php
                        $selected = (($provider['id'] ?? '') === ($data['default_provider_id'] ?? '')) ? 'selected' : '';
                        $disabled = !($provider['enabled'] ?? false) ? 'disabled' : '';
                        $label = trim((string) ($provider['name'] ?? $provider['id'] ?? ''));
                        $model = trim((string) ($provider['model'] ?? ''));
                        if ($model !== '') {
                            $label .= ' — '.$model;
                        }
                        if (!($provider['enabled'] ?? false)) {
                            $label .= ' ['._('disabled').']';
                        }
                    ?>
                    <option value="<?= $h($provider['id'] ?? '') ?>" <?= $selected ?> <?= $disabled ?>><?= $h($label) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="ai-label" for="ai-hostname-search"><?= $h(_('Hostname (optional)')) ?></label>
            <div class="ai-searchable-dropdown" id="ai-hostname-dropdown">
                <input id="ai-hostname-search" class="ai-input" type="text" autocomplete="off" placeholder="<?= $h(_('Search hosts…')) ?>">
                <input id="ai-hostname" type="hidden" value="<?= $h($data['initial_hostname'] ?? '') ?>">
                <input id="ai-hostname-id" type="hidden" value="">
                <div class="ai-dropdown-list ai-hidden" id="ai-hostname-list"></div>
            </div>

            <label class="ai-label" for="ai-eventid-search"><?= $h(_('Event / Problem (optional)')) ?></label>
            <div class="ai-searchable-dropdown" id="ai-eventid-dropdown">
                <input id="ai-eventid-search" class="ai-input" type="text" autocomplete="off" placeholder="<?= $h(_('Search problems…')) ?>">
                <input id="ai-eventid" type="hidden" value="<?= $h($data['initial_eventid'] ?? '') ?>">
                <div class="ai-dropdown-list ai-hidden" id="ai-eventid-list"></div>
            </div>

            <label class="ai-label" for="ai-problem-summary"><?= $h(_('Problem summary (optional)')) ?></label>
            <textarea id="ai-problem-summary" class="ai-textarea" rows="4" placeholder="<?= $h(_('Short problem description or trigger text')) ?>"><?= $h($data['initial_problem_summary'] ?? '') ?></textarea>

            <label class="ai-label" for="ai-extra-context"><?= $h(_('Extra operator context (optional)')) ?></label>
            <textarea id="ai-extra-context" class="ai-textarea" rows="6" placeholder="<?= $h(_('Anything useful for the model: recent changes, error text, environment notes, links to internal docs, etc.')) ?>"></textarea>

            <div class="ai-side-actions">
                <button type="button" class="btn-alt" id="ai-clear-session"><?= $h(_('Clear session')) ?></button>
                <button
                    type="button"
                    class="btn-alt"
                    id="ai-post-last-answer"
                    <?= ($data['has_zabbix_api'] ?? false) ? '' : 'disabled' ?>
                    title="<?= $h(($data['has_zabbix_api'] ?? false) ? _('Post the last AI answer as a problem update comment.') : _('Configure Zabbix API settings first.')) ?>"
                >
                    <?= $h(_('Post last answer to event')) ?>
                </button>
            </div>

            <div id="ai-side-status" class="ai-status ai-hidden"></div>
        </section>

        <section class="ai-card ai-chat-card">
            <h2><?= $h(_('Chat')) ?></h2>
            <div id="ai-transcript" class="ai-transcript" aria-live="polite"></div>

            <form id="ai-compose-form" class="ai-compose-form">
                <label class="ai-label" for="ai-message"><?= $h(_('Message')) ?></label>
                <textarea id="ai-message" class="ai-textarea ai-compose-box" rows="6" placeholder="<?= $h(_('Ask for troubleshooting help, escalation text, log review guidance, or a safer next step.')) ?>"></textarea>
                <div class="ai-compose-actions">
                    <button type="submit" class="btn" id="ai-send-button" <?= $providers ? '' : 'disabled' ?>><?= $h(_('Send')) ?></button>
                </div>
            </form>
        </section>
    </div>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('AI chat'))
    ->addItem(new class($content) {
        private $html;
        public function __construct($html) { $this->html = $html; }
        public function toString($destroy = true) { return $this->html; }
    })
    ->show();
