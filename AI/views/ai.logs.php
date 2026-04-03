<?php

$h = static function($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$summary = $data['summary'] ?? [];
$entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
$filters = $data['filters'] ?? [];

$logs_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.logs')
    ->getUrl();

$settings_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.settings')
    ->getUrl();

$chat_url = (new CUrl('zabbix.php'))
    ->setArgument('action', 'ai.chat')
    ->getUrl();

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'])) {
        $ai_theme = 'dark';
    }
}

ob_start();
?>
<div id="ai-logs-root" class="ai-page ai-logs-page" data-ai-theme="<?= $h($ai_theme) ?>">
    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('AI logs')) ?></h1>
            <p class="ai-muted">Recent JSONL audit records for chat, webhook, reads, writes, redaction, and settings activity.</p>
        </div>
        <div class="ai-header-actions">
            <a class="btn-alt" href="<?= $h($chat_url) ?>"><?= $h(_('Open chat')) ?></a>
            <a class="btn-alt" href="<?= $h($settings_url) ?>"><?= $h(_('Open settings')) ?></a>
        </div>
    </div>

    <section class="ai-card">
        <div class="ai-section-header">
            <h2><?= $h(_('Log summary')) ?></h2>
            <button type="button" class="ai-faq-toggle" data-faq-target="faq-logs-summary" title="<?= $h(_('Help')) ?>" onclick="var b=document.getElementById('faq-logs-summary');b.classList.toggle('ai-faq-visible');this.classList.toggle('ai-faq-active');">?</button>
        </div>
        <div id="faq-logs-summary" class="ai-faq-box">
            <p><strong>What is this?</strong> Overview of the local JSONL audit log system. Logs record chat requests, webhook calls, Zabbix actions, redaction events, settings changes, and errors.</p>
            <p><strong>Logging is disabled by default.</strong> Enable it in AI Settings &gt; Logging.</p>
            <p><strong>If "Log path writable" shows No</strong>, the web server process cannot write to the log directory. Run these commands as root:</p>
<pre># Set WEB_GROUP to your web server group: nginx, apache, or www-data
WEB_GROUP=nginx

# Create all required directories:
mkdir -p /var/log/zabbix-ai /var/log/zabbix-ai/archive
mkdir -p /var/lib/zabbix-ai/state /var/lib/zabbix-ai/state/pending

# Set ownership and permissions:
chown -R root:$WEB_GROUP /var/log/zabbix-ai /var/lib/zabbix-ai
chmod -R 0750 /var/log/zabbix-ai /var/lib/zabbix-ai

# SELinux (RHEL/CentOS/Rocky/Alma):
semanage fcontext -a -t httpd_sys_rw_content_t '/var/log/zabbix-ai(/.*)?'
semanage fcontext -a -t httpd_sys_rw_content_t '/var/lib/zabbix-ai(/.*)?'
restorecon -Rv /var/log/zabbix-ai /var/lib/zabbix-ai

# Verify:
sudo -u $WEB_GROUP touch /var/log/zabbix-ai/test &amp;&amp; rm /var/log/zabbix-ai/test &amp;&amp; echo "OK"</pre>
            <p>Then in AI Settings, set Log path to <code>/var/log/zabbix-ai</code>, Archive path to <code>/var/log/zabbix-ai/archive</code>, and Security state path to <code>/var/lib/zabbix-ai/state</code>.</p>
            <p><strong>Note:</strong> On RHEL/systemd, php-fpm may use PrivateTmp, so default <code>/tmp</code> paths may not be visible to root. Using <code>/var/log/</code> and <code>/var/lib/</code> paths avoids this issue.</p>
        </div>
        <div class="ai-repeat-grid ai-settings-grid">
            <div><strong><?= $h(_('Logging enabled')) ?>:</strong><div class="ai-muted"><?= !empty($summary['enabled']) ? $h(_('Yes')) : $h(_('No')) ?></div></div>
            <div><strong><?= $h(_('Current file')) ?>:</strong><div class="ai-muted"><?= $h($summary['current_log_file'] ?? '') ?></div></div>
            <div><strong><?= $h(_('Retention')) ?>:</strong><div class="ai-muted"><?= $h($summary['retention_days'] ?? 0) ?> <?= $h(_('days')) ?></div></div>
            <div><strong><?= $h(_('Live files')) ?>:</strong><div class="ai-muted"><?= $h($summary['live_file_count'] ?? 0) ?></div></div>
            <div><strong><?= $h(_('Archived files')) ?>:</strong><div class="ai-muted"><?= $h($summary['archive_file_count'] ?? 0) ?></div></div>
            <div><strong><?= $h(_('Log path writable')) ?>:</strong><div class="ai-muted"><?= !empty($summary['path_writable']) ? $h(_('Yes')) : $h(_('No')) ?></div></div>
        </div>
        <?php if (!empty($data['permission_note'])): ?>
            <p class="ai-muted ai-top-margin"><?= $h($data['permission_note']) ?></p>
        <?php endif; ?>
    </section>

    <section class="ai-card">
        <h2><?= $h(_('Filters')) ?></h2>
        <form method="get" action="<?= $h($logs_url) ?>" class="ai-filter-form ai-repeat-grid ai-settings-grid">
            <input type="hidden" name="action" value="ai.logs">
            <div>
                <label class="ai-label"><?= $h(_('Source / category')) ?></label>
                <input class="ai-input" type="text" name="source" value="<?= $h($filters['source'] ?? '') ?>" placeholder="chat / webhook / writes / ai.chat.send">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Status')) ?></label>
                <input class="ai-input" type="text" name="status" value="<?= $h($filters['status'] ?? '') ?>" placeholder="ok / error / pending / denied">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Search')) ?></label>
                <input class="ai-input" type="text" name="search" value="<?= $h($filters['search'] ?? '') ?>" placeholder="request id, event id, host alias, tool name">
            </div>
            <div class="ai-filter-actions">
                <button type="submit" class="btn"><?= $h(_('Apply')) ?></button>
                <a class="btn-alt" href="<?= $h($logs_url) ?>"><?= $h(_('Reset')) ?></a>
            </div>
        </form>
    </section>

    <section class="ai-card">
        <h2><?= $h(_('Entries')) ?></h2>
        <?php if (!$entries): ?>
            <div class="ai-empty-state"><?= $h(_('No matching log entries found.')) ?></div>
        <?php else: ?>
            <div class="ai-log-list">
                <?php foreach ($entries as $entry): ?>
                    <details class="ai-log-entry">
                        <summary>
                            <span class="ai-log-ts"><?= $h($entry['ts'] ?? '') ?></span>
                            <span class="ai-log-badge"><?= $h($entry['category'] ?? '') ?></span>
                            <span class="ai-log-badge"><?= $h($entry['status'] ?? '') ?></span>
                            <span class="ai-log-title"><?= $h($entry['event'] ?? '') ?></span>
                            <?php if (!empty($entry['tool'])): ?>
                                <span class="ai-log-meta">tool=<?= $h($entry['tool']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($entry['request_id'])): ?>
                                <span class="ai-log-meta">req=<?= $h($entry['request_id']) ?></span>
                            <?php endif; ?>
                        </summary>
                        <div class="ai-log-body">
                            <?php if (!empty($entry['source'])): ?>
                                <div><strong><?= $h(_('Source')) ?>:</strong> <?= $h($entry['source']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['message'])): ?>
                                <div><strong><?= $h(_('Message')) ?>:</strong> <?= $h($entry['message']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['user'])): ?>
                                <div><strong><?= $h(_('User')) ?>:</strong> <?= $h(json_encode($entry['user'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['provider'])): ?>
                                <div><strong><?= $h(_('Provider')) ?>:</strong> <pre class="ai-msg-body"><?= $h(json_encode($entry['provider'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['security'])): ?>
                                <div><strong><?= $h(_('Security')) ?>:</strong> <pre class="ai-msg-body"><?= $h(json_encode($entry['security'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['meta'])): ?>
                                <div><strong><?= $h(_('Meta')) ?>:</strong> <pre class="ai-msg-body"><?= $h(json_encode($entry['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div>
                            <?php endif; ?>
                            <?php if (!empty($entry['payload'])): ?>
                                <div><strong><?= $h(_('Payload')) ?>:</strong> <pre class="ai-msg-body"><?= $h(json_encode($entry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
