<?php declare(strict_types = 1);

use Modules\VeeamBackupReport\Helpers\ChartRenderer;
use Modules\VeeamBackupReport\Helpers\ViewFormatter;

/** @var array $data */

$helper = $data['helper'];
$report = $data['report'];
$filter = $data['filter'];
$time_from = (int) $data['time_from'];
$time_to = (int) $data['time_to'];

$chart = new ChartRenderer();
$fmt = new ViewFormatter($helper);
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$metric_label = $helper->getMetricLabel((string) $filter['metric']);
$bytes_axis = static fn($value, int $decimals = 0, $reference = null): string
    => $helper->formatBytesScaled($value, $decimals, $reference);
$gb_axis = static fn($value, int $decimals = 0, $reference = null): string
    => $helper->formatGbScaled($value, $decimals, $reference);

$base_query = [
    'filter_mode' => $filter['mode'],
    'filter_month' => $filter['month'],
    'filter_date_from' => $filter['date_from'],
    'filter_date_to' => $filter['date_to'],
    'filter_days_back' => $filter['days_back'],
    'filter_source' => $filter['source'],
    'filter_metric' => $filter['metric'],
    'filter_top' => $filter['top'],
    'filter_stale_hours' => $filter['stale_hours'],
    'filter_object_search' => $filter['object_search'],
    'filter_repo_search' => $filter['repo_search'],
    'filter_hostids' => $filter['hostids'],
    'filter_types' => $filter['types']
];

$download_url = static function(string $format) use ($base_query): string {
    return 'zabbix.php?'.http_build_query(
        array_merge(['action' => 'veeambackup.report.download', 'format' => $format], $base_query)
    );
};

// The badge counts the same list the Overview panel heads with, so the two
// numbers cannot disagree.
$problem_count = count($report['attention']);

ob_start();
?>
<div class="veeamreport" data-vr-root data-vr-initial-tab="<?= $esc($filter['tab']) ?>">

    <div class="vr-head">
        <div>
            <?php /* Zabbix already renders the page <h1>; a second one would
                     give the page two top-level headings. */ ?>
            <p class="vr-head-title"><?= $esc(_('Veeam Backup Report')) ?></p>
            <div class="vr-head-meta">
                <span class="vr-head-period"><?= $esc($report['period']['label']) ?></span>
                <span class="vr-chip-static"><?= $esc(
                    _n('%1$d Veeam server', '%1$d Veeam servers', count($report['selected_hostids']))) ?></span>
                <span class="vr-chip-static"><?= $esc($metric_label) ?></span>
                <span class="vr-chip-static"><?= $esc($report['source_used'] === 'trends'
                    ? _('Hourly trends')
                    : _('Raw history')) ?></span>
            </div>
        </div>
        <div class="vr-actions">
            <a class="vr-btn vr-btn--primary" href="<?= $esc($download_url('html')) ?>">
                <?= $esc(_('Download report')) ?>
            </a>
            <a class="vr-btn" href="<?= $esc($download_url('objects_csv')) ?>"><?= $esc(_('Objects CSV')) ?></a>
            <a class="vr-btn" href="<?= $esc($download_url('jobs_csv')) ?>"><?= $esc(_('Jobs CSV')) ?></a>
            <a class="vr-btn" href="<?= $esc($download_url('repositories_csv')) ?>"><?= $esc(_('Repositories CSV')) ?></a>
        </div>
    </div>

    <?php include __DIR__.'/../partials/veeambackup.filter.php'; ?>

    <?php foreach (($report['warnings'] ?? []) as $warning): ?>
        <div class="vr-alert">
            <span class="vr-alert-icon" aria-hidden="true">▲</span>
            <span><?= $esc($warning) ?></span>
        </div>
    <?php endforeach; ?>

    <div class="vr-tabs" role="tablist" data-vr-tabs>
        <?php
        $tabs = [
            'overview' => _('Overview'),
            'jobs' => _('Backup jobs'),
            'repositories' => _('Repositories'),
            'objects' => _('Protected objects'),
            'growth' => _('Growth & forecast')
        ];
        foreach ($tabs as $tab_key => $tab_label):
            ?>
            <?php /* A real submit button bound to the filter form, so the tabs
                     still work with JavaScript disabled. The script intercepts
                     the click and switches locally when it is available. */ ?>
            <button type="submit" form="vr-filter-form" name="filter_tab" value="<?= $esc($tab_key) ?>"
                    class="vr-tab<?= $filter['tab'] === $tab_key ? ' is-active' : '' ?>"
                    role="tab" id="vr-tab-<?= $esc($tab_key) ?>"
                    aria-controls="vr-panel-<?= $esc($tab_key) ?>"
                    aria-selected="<?= $filter['tab'] === $tab_key ? 'true' : 'false' ?>"
                    tabindex="<?= $filter['tab'] === $tab_key ? '0' : '-1' ?>"
                    data-vr-tab="<?= $esc($tab_key) ?>">
                <?= $esc($tab_label) ?>
                <?php if ($tab_key === 'overview' && $problem_count > 0): ?>
                    <span class="vr-tab-badge"><?= $esc((string) $problem_count) ?></span>
                    <span class="vr-sr-only"><?= $esc(_n('%1$d item needs attention', '%1$d items need attention', $problem_count)) ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="vr-tabpanel" role="tabpanel" id="vr-panel-overview" aria-labelledby="vr-tab-overview" tabindex="0" data-vr-panel="overview"<?= $filter['tab'] === 'overview' ? '' : ' hidden' ?>>
        <?php include __DIR__.'/../partials/veeambackup.tab.overview.php'; ?>
    </div>

    <div class="vr-tabpanel" role="tabpanel" id="vr-panel-jobs" aria-labelledby="vr-tab-jobs" tabindex="0" data-vr-panel="jobs"<?= $filter['tab'] === 'jobs' ? '' : ' hidden' ?>>
        <?php include __DIR__.'/../partials/veeambackup.tab.jobs.php'; ?>
    </div>

    <div class="vr-tabpanel" role="tabpanel" id="vr-panel-repositories" aria-labelledby="vr-tab-repositories" tabindex="0" data-vr-panel="repositories"<?= $filter['tab'] === 'repositories' ? '' : ' hidden' ?>>
        <?php include __DIR__.'/../partials/veeambackup.tab.repositories.php'; ?>
    </div>

    <div class="vr-tabpanel" role="tabpanel" id="vr-panel-objects" aria-labelledby="vr-tab-objects" tabindex="0" data-vr-panel="objects"<?= $filter['tab'] === 'objects' ? '' : ' hidden' ?>>
        <?php include __DIR__.'/../partials/veeambackup.tab.objects.php'; ?>
    </div>

    <div class="vr-tabpanel" role="tabpanel" id="vr-panel-growth" aria-labelledby="vr-tab-growth" tabindex="0" data-vr-panel="growth"<?= $filter['tab'] === 'growth' ? '' : ' hidden' ?>>
        <?php include __DIR__.'/../partials/veeambackup.tab.growth.php'; ?>
    </div>
</div>
<?php
$content = (string) ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'])
    ->addItem(new CObject($content))
    ->show();
