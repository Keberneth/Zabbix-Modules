<?php declare(strict_types = 1);

/**
 * Report filter.
 *
 * Plain GET form, so it works without JavaScript. The workload-type pills are
 * built from the objects that actually exist on the selected Veeam servers -
 * a workload nobody protects is never offered here.
 *
 * @var array $report
 * @var array $filter
 * @var callable $esc
 */

$period_modes = [
    'days_back' => _('Last N days'),
    'prev_month' => _('Previous month'),
    'specific_month' => _('Specific month'),
    'custom_range' => _('Custom range')
];

$sources = [
    'auto' => _('Automatic'),
    'history' => _('Raw history'),
    'trends' => _('Hourly trends')
];

$metrics = [
    'size31d' => _('Protected data (rolling 31 days)'),
    'size24h' => _('Data written (last 24 hours)')
];
?>
<form class="vr-filter" id="vr-filter-form" method="get" action="zabbix.php" data-vr-filter>
    <input type="hidden" name="action" value="veeambackup.report.view">
    <?php /* Only the clicked submitter is sent, so without this hidden field a
             no-JS Apply would lose the current tab. The tab buttons are named
             submitters and override it; the script keeps it in sync. */ ?>
    <input type="hidden" name="filter_tab" value="<?= $esc($filter['tab']) ?>" data-vr-tab-input>

    <div class="vr-filter-row">
        <div class="vr-field">
            <label for="vr_mode"><?= $esc(_('Period')) ?></label>
            <select id="vr_mode" name="filter_mode">
                <?php foreach ($period_modes as $value => $label): ?>
                    <option value="<?= $esc($value) ?>"<?= $filter['mode'] === $value ? ' selected' : '' ?>>
                        <?= $esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="vr-field" data-vr-modes="days_back">
            <label for="vr_days_back"><?= $esc(_('Days')) ?></label>
            <input id="vr_days_back" type="number" min="1" max="366" name="filter_days_back"
                   value="<?= $esc($filter['days_back']) ?>">
        </div>

        <div class="vr-field" data-vr-modes="specific_month">
            <label for="vr_month"><?= $esc(_('Month')) ?></label>
            <input id="vr_month" type="month" name="filter_month" value="<?= $esc($filter['month']) ?>">
        </div>

        <div class="vr-field" data-vr-modes="custom_range">
            <label for="vr_date_from"><?= $esc(_('From')) ?></label>
            <input id="vr_date_from" type="date" name="filter_date_from" value="<?= $esc($filter['date_from']) ?>">
        </div>

        <div class="vr-field" data-vr-modes="custom_range">
            <label for="vr_date_to"><?= $esc(_('To')) ?></label>
            <input id="vr_date_to" type="date" name="filter_date_to" value="<?= $esc($filter['date_to']) ?>">
        </div>

        <div class="vr-field vr-field--grow">
            <span class="vr-field-label"><?= $esc(_('Veeam servers')) ?></span>
            <?php if (($report['host_options'] ?? []) === []): ?>
                <p class="vr-hint"><?= $esc(_('No Veeam servers with backup-report items were found.')) ?></p>
            <?php else: ?>
                <div class="vr-chips">
                    <?php foreach ($report['host_options'] as $host): ?>
                        <?php $on = in_array((string) $host['hostid'], (array) $filter['hostids'], true); ?>
                        <label class="vr-chip<?= $on ? ' is-on' : '' ?>">
                            <input type="checkbox" name="filter_hostids[]"
                                   value="<?= $esc($host['hostid']) ?>"<?= $on ? ' checked' : '' ?>>
                            <?= $esc($host['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="vr-hint"><?= $esc(_('Select none to include every Veeam server.')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (($report['type_options'] ?? []) !== []): ?>
        <div class="vr-filter-row">
            <div class="vr-field vr-field--full">
                <span class="vr-field-label"><?= $esc(_('Backup type')) ?></span>
                <div class="vr-chips">
                    <?php foreach ($report['type_options'] as $type): ?>
                        <?php $on = in_array((string) $type['key'], (array) $filter['types'], true); ?>
                        <label class="vr-chip<?= $on ? ' is-on' : '' ?>">
                            <input type="checkbox" name="filter_types[]"
                                   value="<?= $esc($type['key']) ?>"<?= $on ? ' checked' : '' ?>>
                            <span class="vr-chip-dot" style="background:var(<?= $esc(\Modules\VeeamBackupReport\Helpers\ChartRenderer::safeToken($type['token'])) ?>)" aria-hidden="true"></span>
                            <?= $esc($type['label']) ?>
                            <span class="vr-chip-count"><?= $esc((string) $type['objects']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="vr-hint">
                    <?= $esc(_('Only the workload types found on the selected Veeam servers are listed. Select none to include them all.')) ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
        $defaults = \Modules\VeeamBackupReport\Helpers\ReportDataHelper::getDefaultFilter();
        $advanced_open = $filter['metric'] !== $defaults['metric']
            || $filter['source'] !== $defaults['source']
            || (int) $filter['stale_hours'] !== (int) $defaults['stale_hours']
            || (int) $filter['top'] !== (int) $defaults['top']
            || $filter['object_search'] !== ''
            || $filter['repo_search'] !== '';
    ?>
    <div class="vr-filter-row vr-advanced" data-vr-advanced="<?= $advanced_open ? 'open' : 'closed' ?>">
        <div class="vr-field">
            <label for="vr_metric"><?= $esc(_('Size metric')) ?></label>
            <select id="vr_metric" name="filter_metric">
                <?php foreach ($metrics as $value => $label): ?>
                    <option value="<?= $esc($value) ?>"<?= $filter['metric'] === $value ? ' selected' : '' ?>>
                        <?= $esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="vr-field">
            <label for="vr_source"><?= $esc(_('Data source')) ?></label>
            <select id="vr_source" name="filter_source">
                <?php foreach ($sources as $value => $label): ?>
                    <option value="<?= $esc($value) ?>"<?= $filter['source'] === $value ? ' selected' : '' ?>>
                        <?= $esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="vr-hint"><?= $esc(_('Automatic uses trends beyond 7 days.')) ?></div>
        </div>

        <div class="vr-field">
            <label for="vr_stale_hours"><?= $esc(_('Overdue after (hours)')) ?></label>
            <input id="vr_stale_hours" type="number" min="1" max="720" name="filter_stale_hours"
                   value="<?= $esc($filter['stale_hours']) ?>">
            <div class="vr-hint"><?= $esc(_('Twice this age counts as critical.')) ?></div>
        </div>

        <div class="vr-field">
            <label for="vr_top"><?= $esc(_('Table rows')) ?></label>
            <input id="vr_top" type="number" min="10" max="500" name="filter_top" value="<?= $esc($filter['top']) ?>">
        </div>

        <div class="vr-field vr-field--grow">
            <label for="vr_object_search"><?= $esc(_('Find object')) ?></label>
            <input id="vr_object_search" type="text" name="filter_object_search"
                   value="<?= $esc($filter['object_search']) ?>"
                   placeholder="<?= $esc(_('Name, platform or repository…')) ?>">
        </div>

        <div class="vr-field vr-field--grow">
            <label for="vr_repo_search"><?= $esc(_('Find repository')) ?></label>
            <input id="vr_repo_search" type="text" name="filter_repo_search"
                   value="<?= $esc($filter['repo_search']) ?>"
                   placeholder="<?= $esc(_('Repository, path or Veeam server…')) ?>">
        </div>
    </div>

    <div class="vr-filter-actions">
        <button type="submit" class="vr-btn vr-btn--primary"><?= $esc(_('Apply')) ?></button>
        <a class="vr-btn" href="zabbix.php?action=veeambackup.report.view"><?= $esc(_('Reset')) ?></a>
        <span class="vr-spacer"></span>
        <button type="button" class="vr-btn vr-btn--ghost" data-vr-advanced-toggle
                aria-expanded="<?= $advanced_open ? 'true' : 'false' ?>"
                data-label-more="<?= $esc(_('More filters')) ?>" data-label-less="<?= $esc(_('Fewer filters')) ?>">
            <?= $esc($advanced_open ? _('Fewer filters') : _('More filters')) ?>
        </button>
    </div>
</form>
