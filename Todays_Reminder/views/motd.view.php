<?php

require_once __DIR__.'/../helpers/OverviewRenderer.php';

use Modules\MessageOfTheDay\Helpers\OverviewRenderer;

$h = static function($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$motd = is_array($data['motd'] ?? null) ? $data['motd'] : [];
$filters = $motd['filters'] ?? ['groupid' => '0', 'tag' => '', 'tag_value' => ''];
$bootstrap = array_intersect_key($motd, array_flip([
	'fingerprint', 'generated_at', 'generated_at_text', 'timezone', 'status', 'issues', 'ui', 'preference_key', 'filters', 'idle_timeout'
]));
ob_start();
?>
<div class="motd-page">
	<div class="motd-page__header">
		<div id="motd-overview-status" class="motd-page__meta motd-status" role="status" aria-live="polite">
			<?= $h(_('Updated')) ?> <?= $h(!empty($motd['generated_at_text']) ? $motd['generated_at_text'] : _('Unavailable')) ?>
			<?php if (!empty($motd['timezone'])): ?> · <?= $h($motd['timezone']) ?><?php endif; ?>
		</div>
		<div class="motd-page__header-actions">
			<button type="button" id="motd-refresh" class="motd-page__btn"><?= $h(_('Refresh')) ?></button>
			<button type="button" id="motd-snooze" class="motd-page__btn"><?= $h(_('Hide banner for 1 hour')) ?></button>
			<button type="button" id="motd-resume" class="motd-page__btn" hidden><?= $h(_('Show banner again')) ?></button>
			<?php if (!empty($motd['problems']['all_url'])): ?><a class="motd-page__btn" href="<?= $h($motd['problems']['all_url']) ?>"><?= $h(_('Open Problems')) ?></a><?php endif; ?>
		</div>
	</div>
	<p id="motd-snooze-status" class="motd-subtext" role="status"></p>
	<form id="motd-filter-form" class="motd-filters" method="get" action="zabbix.php">
		<input type="hidden" name="action" value="motd.view">
		<div><label for="motd-groupid"><?= $h(_('Host group')) ?></label>
			<select id="motd-groupid" name="groupid"><option value="0"><?= $h(_('All host groups')) ?></option>
			<?php foreach ($motd['filter_options']['groups'] ?? [] as $group): ?>
				<option value="<?= $h($group['groupid']) ?>"<?= (string) ($filters['groupid'] ?? '0') === (string) $group['groupid'] ? ' selected' : '' ?>><?= $h($group['name']) ?></option>
			<?php endforeach; ?></select>
		</div>
		<div><label for="motd-tag"><?= $h(_('Tag')) ?></label><input id="motd-tag" name="tag" maxlength="255" value="<?= $h($filters['tag'] ?? '') ?>"></div>
		<div><label for="motd-tag-value"><?= $h(_('Exact tag value')) ?></label><input id="motd-tag-value" name="tag_value" maxlength="255" value="<?= $h($filters['tag_value'] ?? '') ?>"></div>
		<button type="submit" class="motd-page__btn"><?= $h(_('Apply filters')) ?></button>
		<button type="button" id="motd-filter-reset" class="motd-page__btn"><?= $h(_('Reset filters')) ?></button>
		<p class="motd-subtext motd-filters__help"><?= $h(_('Filters apply to the overview and banner and are saved for your account in this browser. A tag without a value matches any value. Queue and proxy health remain global.')) ?></p>
	</form>
	<div id="motd-overview-content" aria-busy="false"><?= OverviewRenderer::render($motd) ?></div>
	<script type="application/json" id="motd-bootstrap"><?= json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</div>
<?php
$content = (string) ob_get_clean();
(new CHtmlPage())
	->setTitle($data['title'] ?? _('Today\'s Reminder'))
	->addItem(new class($content) {
		private $html;
		public function __construct(string $html) { $this->html = $html; }
		public function toString($destroy = true) { return $this->html; }
	})
	->show();
