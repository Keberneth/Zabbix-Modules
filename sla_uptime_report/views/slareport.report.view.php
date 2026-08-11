<?php declare(strict_types = 1);

use Modules\SlaUptimeReport\Helpers\ChartRenderer;
use Modules\SlaUptimeReport\Helpers\ViewFormatter;

/** @var array $data */

$helper = $data['helper'];
$report = $data['report'];
$filter = $data['filter'];
$time_from = (int) $data['time_from'];
$time_to = (int) $data['time_to'];

$chart = new ChartRenderer();
$fmt = new ViewFormatter($helper);
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$minutes_axis = static function($value, int $decimals = 0, $reference = null): string {
	$value = (float) $value;
	$scale = $reference !== null ? abs((float) $reference) : $value;

	if ($scale >= 1440) {
		return number_format($value / 1440, $value == 0 ? 0 : 1, '.', ' ').' d';
	}
	if ($scale >= 120) {
		return number_format($value / 60, $decimals > 0 || $scale < 600 ? 1 : 0, '.', ' ').' h';
	}

	return number_format($value, $decimals, '.', ' ').' min';
};

$base_query = [
	'filter_mode' => $filter['mode'],
	'filter_month' => $filter['month'],
	'filter_date_from' => $filter['date_from'],
	'filter_date_to' => $filter['date_to'],
	'filter_days_back' => $filter['days_back'],
	'filter_exclude_disabled' => $filter['exclude_disabled'],
	'filter_target' => $filter['target'],
	'filter_top' => $filter['top'],
	'filter_hostgroupids' => $filter['hostgroupids'],
	'filter_slaids' => $filter['slaids']
];

$download_url = static function(string $format) use ($base_query): string {
	return 'zabbix.php?'.http_build_query(
		array_merge(['action' => 'slareport.report.download', 'format' => $format], $base_query)
	);
};

// The badge counts the same list the Overview panel heads with.
$problem_count = count($report['attention']);

ob_start();
?>
<div class="slareport" data-sr-root data-sr-initial-tab="<?= $esc($filter['tab']) ?>">

	<div class="sr-head">
		<div>
			<p class="sr-head-title"><?= $esc(_('SLA & Uptime Report')) ?></p>
			<div class="sr-head-meta">
				<span class="sr-head-period"><?= $esc($report['period']['label']) ?></span>
				<span class="sr-chip-static"><?= $esc((int) $report['fleet']['hosts_total'] === 1
					? _('1 host')
					: sprintf(_('%1$d hosts'), (int) $report['fleet']['hosts_total'])) ?></span>
				<?php if ((int) $report['sla_summary']['slas_total'] > 0): ?>
					<span class="sr-chip-static"><?= $esc((int) $report['sla_summary']['slas_total'] === 1
						? _('1 SLA')
						: sprintf(_('%1$d SLAs'), (int) $report['sla_summary']['slas_total'])) ?></span>
				<?php endif; ?>
				<span class="sr-chip-static"><?= $esc($report['source_used'] === 'trends'
					? _('Hourly trends')
					: _('Raw history')) ?></span>
			</div>
		</div>
		<div class="sr-actions">
			<a class="sr-btn sr-btn--primary" href="<?= $esc($download_url('html')) ?>">
				<?= $esc(_('Download report')) ?>
			</a>
			<a class="sr-btn" href="<?= $esc($download_url('sla_csv')) ?>"><?= $esc(_('SLA CSV')) ?></a>
			<a class="sr-btn" href="<?= $esc($download_url('availability_csv')) ?>"><?= $esc(_('Availability CSV')) ?></a>
			<a class="sr-btn" href="<?= $esc($download_url('daily_csv')) ?>"><?= $esc(_('Daily CSV')) ?></a>
		</div>
	</div>

	<?php include __DIR__.'/../partials/slareport.filter.php'; ?>

	<?php foreach (($report['warnings'] ?? []) as $warning): ?>
		<div class="sr-alert">
			<span class="sr-alert-icon" aria-hidden="true">▲</span>
			<span><?= $esc($warning) ?></span>
		</div>
	<?php endforeach; ?>

	<div class="sr-tabs" role="tablist" aria-label="<?= $esc(_('Report sections')) ?>" data-sr-tabs>
		<?php
		$tabs = [
			'overview' => _('Overview'),
			'slas' => _('SLA compliance'),
			'availability' => _('Host availability')
		];
		foreach ($tabs as $tab_key => $tab_label):
			?>
			<?php /* Real submit buttons bound to the filter form, so the tabs
			         work with JavaScript disabled; the script intercepts the
			         click and switches locally when it is available. */ ?>
			<button type="submit" form="sr-filter-form" name="filter_tab" value="<?= $esc($tab_key) ?>"
					class="sr-tab<?= $filter['tab'] === $tab_key ? ' is-active' : '' ?>"
					role="tab" id="sr-tab-<?= $esc($tab_key) ?>"
					aria-controls="sr-panel-<?= $esc($tab_key) ?>"
					aria-selected="<?= $filter['tab'] === $tab_key ? 'true' : 'false' ?>"
					tabindex="<?= $filter['tab'] === $tab_key ? '0' : '-1' ?>"
					data-sr-tab="<?= $esc($tab_key) ?>">
				<?= $esc($tab_label) ?>
				<?php if ($tab_key === 'overview' && $problem_count > 0): ?>
					<span class="sr-tab-badge"><?= $esc((string) $problem_count) ?></span>
					<span class="sr-sr-only"><?= $esc($problem_count === 1
						? _('1 item needs attention')
						: sprintf(_('%1$d items need attention'), $problem_count)) ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>

	<div class="sr-tabpanel" role="tabpanel" id="sr-panel-overview" aria-labelledby="sr-tab-overview"
			tabindex="0" data-sr-panel="overview"<?= $filter['tab'] === 'overview' ? '' : ' hidden' ?>>
		<?php include __DIR__.'/../partials/slareport.tab.overview.php'; ?>
	</div>

	<div class="sr-tabpanel" role="tabpanel" id="sr-panel-slas" aria-labelledby="sr-tab-slas"
			tabindex="0" data-sr-panel="slas"<?= $filter['tab'] === 'slas' ? '' : ' hidden' ?>>
		<?php include __DIR__.'/../partials/slareport.tab.slas.php'; ?>
	</div>

	<div class="sr-tabpanel" role="tabpanel" id="sr-panel-availability" aria-labelledby="sr-tab-availability"
			tabindex="0" data-sr-panel="availability"<?= $filter['tab'] === 'availability' ? '' : ' hidden' ?>>
		<?php include __DIR__.'/../partials/slareport.tab.availability.php'; ?>
	</div>
</div>
<?php
$content = (string) ob_get_clean();

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem(new CObject($content))
	->show();
