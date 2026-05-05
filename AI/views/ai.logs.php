<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$fetch_url = (string) ($data['fetch_url'] ?? '');
$clear_url = (string) ($data['clear_url'] ?? '');
$settings_url = (string) ($data['settings_url'] ?? '');
$chat_url = (string) ($data['chat_url'] ?? '');
$permission_note = (string) ($data['permission_note'] ?? '');

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $ai_theme = 'dark';
    }
}

$tabs = [
    'all' => _('All'),
    'chat' => _('Chat'),
    'webhook' => _('Webhook'),
    'tools' => _('Tools'),
    'settings_changes' => _('Settings'),
    'errors' => _('Errors')
];

$default_since = gmdate('Y-m-d', strtotime('-7 days'));
$default_until = gmdate('Y-m-d');

ob_start();
?>
<div id="ai-logs-root" class="ai-page ai-logs-page"
     data-ai-theme="<?= $h($ai_theme) ?>"
     data-fetch-url="<?= $h($fetch_url) ?>"
     data-clear-url="<?= $h($clear_url) ?>"
     data-csrf-name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>"
     data-csrf-clear="<?= $h(CCsrfTokenHelper::get('ai.logs.clear')) ?>">
    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('AI logs')) ?></h1>
            <p class="ai-muted"><?= $h(_('JSONL audit trail across chat, webhook, tool reads/writes, redaction, settings, and errors. Use the tabs to focus on a category, then narrow down with the facets, time range, or column filters.')) ?></p>
            <?php if (!empty($summary['current_log_file'])): ?>
                <p class="ai-muted ai-log-hint"><?= $h(_('Current file:')) ?> <code><?= $h($summary['current_log_file']) ?></code></p>
            <?php endif; ?>
        </div>
        <div class="ai-header-actions">
            <a class="btn" href="<?= $h($chat_url) ?>"><?= $h(_('Open chat')) ?></a>
            <a class="btn" href="<?= $h($settings_url) ?>"><?= $h(_('Open settings')) ?></a>
            <button type="button" id="ai-log-refresh" class="btn"><?= $h(_('Refresh')) ?></button>
            <button type="button" id="ai-log-clear" class="btn"><?= $h(_('Clear log')) ?></button>
        </div>
    </div>

    <section class="ai-card">
        <div class="ai-section-header">
            <h2><?= $h(_('Log summary')) ?></h2>
            <button type="button" class="ai-faq-toggle" data-faq-target="faq-logs-summary" title="<?= $h(_('Help')) ?>" onclick="var b=document.getElementById('faq-logs-summary');b.classList.toggle('ai-faq-visible');this.classList.toggle('ai-faq-active');">?</button>
        </div>
        <div id="faq-logs-summary" class="ai-faq-box">
            <p><strong>What is this?</strong> Local JSONL audit log for chat, webhook, Zabbix actions, redaction, settings changes, and errors.</p>
            <p><strong>Logging is disabled by default.</strong> Enable it in AI Settings &gt; Logging.</p>
            <p><strong>If "Log path writable" shows No</strong>, the web server process cannot write to the log directory. See INSTALL.md for the directory + SELinux setup commands.</p>
        </div>
        <div class="ai-repeat-grid ai-settings-grid">
            <div><strong><?= $h(_('Logging enabled')) ?>:</strong><div class="ai-muted"><?= !empty($summary['enabled']) ? $h(_('Yes')) : $h(_('No')) ?></div></div>
            <div><strong><?= $h(_('Retention')) ?>:</strong><div class="ai-muted"><?= $h($summary['retention_days'] ?? 0) ?> <?= $h(_('days')) ?></div></div>
            <div><strong><?= $h(_('Live files')) ?>:</strong><div class="ai-muted"><?= $h($summary['live_file_count'] ?? 0) ?></div></div>
            <div><strong><?= $h(_('Archived files')) ?>:</strong><div class="ai-muted"><?= $h($summary['archive_file_count'] ?? 0) ?></div></div>
            <div><strong><?= $h(_('Log path writable')) ?>:</strong><div class="ai-muted"><?= !empty($summary['path_writable']) ? $h(_('Yes')) : $h(_('No')) ?></div></div>
            <div><strong><?= $h(_('Archive path writable')) ?>:</strong><div class="ai-muted"><?= !empty($summary['archive_path_writable']) ? $h(_('Yes')) : $h(_('No')) ?></div></div>
        </div>
        <?php if ($permission_note !== ''): ?>
            <p class="ai-muted ai-top-margin"><?= $h($permission_note) ?></p>
        <?php endif; ?>
    </section>

    <div id="ai-log-status" class="ai-status" hidden></div>

    <nav class="ai-log-tabs" role="tablist">
        <?php $first = true; foreach ($tabs as $key => $label): ?>
            <button type="button"
                    role="tab"
                    class="ai-log-tab<?= $first ? ' is-active' : '' ?>"
                    data-tab="<?= $h($key) ?>"
                    aria-selected="<?= $first ? 'true' : 'false' ?>">
                <span class="ai-log-tab-label"><?= $h($label) ?></span>
                <span class="ai-log-tab-count" data-tab-count="<?= $h($key) ?>">0</span>
            </button>
        <?php $first = false; endforeach; ?>
    </nav>

    <section class="ai-card ai-log-filters">
        <div class="ai-repeat-grid ai-settings-grid">
            <div>
                <label class="ai-label" for="ai-log-since"><?= $h(_('From')) ?></label>
                <input class="ai-input" type="date" id="ai-log-since" value="<?= $h($default_since) ?>">
            </div>
            <div>
                <label class="ai-label" for="ai-log-until"><?= $h(_('To')) ?></label>
                <input class="ai-input" type="date" id="ai-log-until" value="<?= $h($default_until) ?>">
            </div>
            <div class="ai-span-2">
                <label class="ai-label" for="ai-log-q"><?= $h(_('Text search')) ?></label>
                <input class="ai-input" type="search" id="ai-log-q" placeholder="<?= $h(_('Search any field (request id, message, hostname, etc.)')) ?>">
            </div>
        </div>

        <div class="ai-log-facets" id="ai-log-facets">
            <div class="ai-log-facet" data-facet="category">
                <label class="ai-label"><?= $h(_('Category')) ?></label>
                <select class="ai-input ai-facet-select" multiple size="4" data-facet-field="category"></select>
            </div>
            <div class="ai-log-facet" data-facet="status">
                <label class="ai-label"><?= $h(_('Status')) ?></label>
                <select class="ai-input ai-facet-select" multiple size="4" data-facet-field="status"></select>
                <div class="ai-mini-help"><?= $h(_('Ctrl/⌘-click to pick several.')) ?></div>
            </div>
            <div class="ai-log-facet" data-facet="source">
                <label class="ai-label"><?= $h(_('Source')) ?></label>
                <select class="ai-input ai-facet-select" multiple size="4" data-facet-field="source"></select>
            </div>
            <div class="ai-log-facet" data-facet="tool">
                <label class="ai-label"><?= $h(_('Tool')) ?></label>
                <select class="ai-input ai-facet-select" multiple size="4" data-facet-field="tool"></select>
            </div>
        </div>

        <div class="ai-log-filter-actions">
            <button type="button" id="ai-log-apply" class="btn"><?= $h(_('Apply filters')) ?></button>
            <button type="button" id="ai-log-reset" class="btn"><?= $h(_('Reset')) ?></button>
            <span class="ai-muted" id="ai-log-count">0 <?= $h(_('rows')) ?></span>
        </div>
    </section>

    <section class="ai-card ai-log-grid-card">
        <div class="ai-log-grid-wrap">
            <table class="ai-log-grid" id="ai-log-grid">
                <thead>
                    <tr class="ai-log-grid-heads"></tr>
                    <tr class="ai-log-grid-filters"></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="ai-log-pager">
            <button type="button" id="ai-log-load-more" class="btn" hidden><?= $h(_('Load more')) ?></button>
            <span class="ai-muted" id="ai-log-pager-info"></span>
        </div>
    </section>

    <section class="ai-card ai-log-detail" id="ai-log-detail" hidden>
        <div class="ai-section-header">
            <h2><?= $h(_('Entry details')) ?></h2>
            <button type="button" class="btn-alt" id="ai-log-detail-close"><?= $h(_('Close')) ?></button>
        </div>
        <pre class="ai-msg-body" id="ai-log-detail-body"></pre>
    </section>
</div>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('AI logs'))
    ->addItem(new class($content) {
        private $html;
        public function __construct($html) { $this->html = $html; }
        public function toString($destroy = true) { return $this->html; }
    })
    ->show();
