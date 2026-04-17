<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$fetch_url = (string) ($data['fetch_url'] ?? '');
$clear_url = (string) ($data['clear_url'] ?? '');
$settings_url = (string) ($data['settings_url'] ?? '');
$log_path = (string) ($data['log_path'] ?? '');

$nbs_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $nbs_theme = 'dark';
    }
}

$tabs = [
    'added' => _('Added'),
    'changed' => _('Changed'),
    'removed' => _('Removed'),
    'error' => _('Errors')
];

$default_since = gmdate('Y-m-d', strtotime('-7 days'));
$default_until = gmdate('Y-m-d');
?>
<div id="nbs-log-root" class="nbs-page nbs-log-page"
     data-nbs-theme="<?= $h($nbs_theme) ?>"
     data-fetch-url="<?= $h($fetch_url) ?>"
     data-clear-url="<?= $h($clear_url) ?>"
     data-csrf-name="<?= $h(CCsrfTokenHelper::CSRF_TOKEN_NAME) ?>"
     data-csrf-clear="<?= $h(CCsrfTokenHelper::get('netboxsync.log.clear')) ?>">
    <div class="nbs-header">
        <div>
            <h1><?= $h($data['title'] ?? _('NetBox sync log')) ?></h1>
            <p class="nbs-muted"><?= $h(_('Structured change log from every sync run. Switch tabs to focus on additions, updates, removals, or errors. Filters stack — combine OS, disk, sync, or field to isolate what you need.')) ?></p>
            <?php if ($log_path !== ''): ?>
                <p class="nbs-muted nbs-log-hint"><?= $h(_('Log path:')) ?> <code><?= $h($log_path.'/events/') ?></code></p>
            <?php endif; ?>
        </div>
        <div class="nbs-header-actions">
            <a class="btn-alt" href="<?= $h($settings_url) ?>"><?= $h(_('Back to settings')) ?></a>
            <button type="button" id="nbs-log-refresh" class="btn-alt"><?= $h(_('Refresh')) ?></button>
            <button type="button" id="nbs-log-clear" class="btn-alt"><?= $h(_('Clear log')) ?></button>
        </div>
    </div>

    <div id="nbs-log-status" class="nbs-status" hidden></div>

    <nav class="nbs-log-tabs" role="tablist">
        <?php $first = true; foreach ($tabs as $key => $label): ?>
            <button type="button"
                    role="tab"
                    class="nbs-log-tab<?= $first ? ' is-active' : '' ?>"
                    data-tab="<?= $h($key) ?>"
                    aria-selected="<?= $first ? 'true' : 'false' ?>">
                <span class="nbs-log-tab-label"><?= $h($label) ?></span>
                <span class="nbs-log-tab-count" data-tab-count="<?= $h($key) ?>">0</span>
            </button>
        <?php $first = false; endforeach; ?>
    </nav>

    <section class="nbs-card nbs-log-filters">
        <div class="nbs-settings-grid">
            <div>
                <label class="nbs-label" for="nbs-log-since"><?= $h(_('From')) ?></label>
                <input class="nbs-input" type="date" id="nbs-log-since" value="<?= $h($default_since) ?>">
            </div>
            <div>
                <label class="nbs-label" for="nbs-log-until"><?= $h(_('To')) ?></label>
                <input class="nbs-input" type="date" id="nbs-log-until" value="<?= $h($default_until) ?>">
            </div>
            <div class="nbs-span-2">
                <label class="nbs-label" for="nbs-log-q"><?= $h(_('Text search')) ?></label>
                <input class="nbs-input" type="search" id="nbs-log-q" placeholder="<?= $h(_('Search any field')) ?>">
            </div>
        </div>

        <div class="nbs-log-facets" id="nbs-log-facets">
            <div class="nbs-log-facet" data-facet="host">
                <label class="nbs-label"><?= $h(_('Host')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="host"></select>
            </div>
            <div class="nbs-log-facet" data-facet="os">
                <label class="nbs-label"><?= $h(_('Operating system')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="os"></select>
                <div class="nbs-mini-help"><?= $h(_('Ctrl/⌘-click to pick several. Partial matches work.')) ?></div>
            </div>
            <div class="nbs-log-facet" data-facet="target_type">
                <label class="nbs-label"><?= $h(_('Target')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="target_type"></select>
            </div>
            <div class="nbs-log-facet" data-facet="sync_id">
                <label class="nbs-label"><?= $h(_('Sync')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="sync_id"></select>
            </div>
            <div class="nbs-log-facet" data-facet="field">
                <label class="nbs-label"><?= $h(_('Field')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="field"></select>
            </div>
            <div class="nbs-log-facet" data-facet="disk_name">
                <label class="nbs-label"><?= $h(_('Disk')) ?></label>
                <select class="nbs-input nbs-facet-select" multiple size="4" data-facet-field="disk_name"></select>
            </div>
        </div>

        <div class="nbs-log-filter-actions">
            <button type="button" id="nbs-log-apply" class="btn-alt"><?= $h(_('Apply filters')) ?></button>
            <button type="button" id="nbs-log-reset" class="btn-alt"><?= $h(_('Reset')) ?></button>
            <span class="nbs-muted" id="nbs-log-count">0 <?= $h(_('rows')) ?></span>
        </div>
    </section>

    <section class="nbs-card nbs-log-grid-card">
        <div class="nbs-log-grid-wrap">
            <table class="nbs-log-grid" id="nbs-log-grid">
                <thead>
                    <tr class="nbs-log-grid-heads"></tr>
                    <tr class="nbs-log-grid-filters"></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="nbs-log-pager">
            <button type="button" id="nbs-log-load-more" class="btn-alt" hidden><?= $h(_('Load more')) ?></button>
            <span class="nbs-muted" id="nbs-log-pager-info"></span>
        </div>
    </section>
</div>
