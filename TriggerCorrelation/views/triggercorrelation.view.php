<?php

/**
 * Trigger Correlation module page.
 *
 * Server-rendered with the same design system as the reference AI module
 * (ai-page / ai-card / ai-settings-tabs / ai-repeat-grid / ai-label / ai-input /
 * ai-faq-box / ai-status / ai-searchable-dropdown), wrapped in CHtmlPage, with
 * real CCsrfTokenHelper tokens on every state-changing POST.
 */

$h = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$config = $data['config'] ?? [];
$settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];
$rules = is_array($config['rules'] ?? null) ? array_values($config['rules']) : [];
$severity_rules = is_array($config['severity_rules'] ?? null) ? array_values($config['severity_rules']) : [];
$secret_storage = is_array($config['secret_storage'] ?? null) ? $config['secret_storage'] : ['available' => false, 'backend' => 'none'];
$storage_label = (string) ($config['storage'] ?? '');

$url = static function (string $action): string {
    return (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();
};

$csrf_field = CCsrfTokenHelper::CSRF_TOKEN_NAME;

$ai_theme = 'light';
if (function_exists('getUserTheme')) {
    $zt = getUserTheme(CWebUser::$data);
    if (in_array($zt, ['dark-theme', 'hc-dark'], true)) {
        $ai_theme = 'dark';
    }
}

$severity_options = [
    1 => '1 - Information',
    2 => '2 - Warning',
    3 => '3 - Average',
    4 => '4 - High',
    5 => '5 - Critical/Disaster'
];
$state_labels = [0 => 'OK', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Critical/Disaster'];
$state_class = static function (int $state): string {
    if ($state >= 5) {
        return 'critical';
    }
    if ($state >= 4) {
        return 'high';
    }
    return $state > 0 ? 'warn' : 'ok';
};

$render_condition = static function (array $condition = []) use ($h): string {
    ob_start();
    ?>
    <div class="ai-repeat-row tc-condition" data-condition>
        <div class="ai-repeat-grid">
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('Host')) ?></label>
                <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="host">
                    <input class="ai-input cond-host" type="text" autocomplete="off"
                        value="<?= $h($condition['host'] ?? '') ?>"
                        placeholder="<?= $h(_('Start typing a host. If a trigger is typed, the list is filtered by it.')) ?>">
                    <input type="hidden" class="cond-hostid" value="<?= $h($condition['hostid'] ?? '') ?>">
                    <div class="ai-dropdown-list ai-hidden"></div>
                </div>
            </div>
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('Trigger')) ?></label>
                <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="trigger">
                    <input class="ai-input cond-trigger" type="text" autocomplete="off"
                        value="<?= $h($condition['trigger'] ?? '') ?>"
                        placeholder="<?= $h(_('Start typing a trigger. If a host is selected, only that host is searched.')) ?>">
                    <input type="hidden" class="cond-triggerid" value="<?= $h($condition['triggerid'] ?? '') ?>">
                    <div class="ai-dropdown-list ai-hidden"></div>
                </div>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn tc-remove-condition"><?= $h(_('Remove source trigger')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$render_tier = static function () use ($h, $severity_options): string {
    ob_start();
    ?>
    <div class="ai-repeat-row tc-tier" data-tier>
        <div class="ai-repeat-grid">
            <div>
                <label class="ai-label"><?= $h(_('When at least (active count)')) ?></label>
                <input class="ai-input tc-tier-min" type="number" min="1" max="999" value="2">
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Severity')) ?></label>
                <select class="ai-input tc-tier-value">
                    <?php foreach ($severity_options as $v => $label): ?>
                        <option value="<?= $h($v) ?>" <?= ($v === 4) ? 'selected' : '' ?>><?= $h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn tc-remove-tier"><?= $h(_('Remove tier')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

$render_starget = static function () use ($h): string {
    ob_start();
    ?>
    <div class="ai-repeat-row tc-starget" data-starget>
        <div class="ai-repeat-grid">
            <div class="ai-span-2">
                <label class="ai-label"><?= $h(_('Target trigger')) ?></label>
                <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="starget-trigger">
                    <input class="ai-input starget-trigger" type="text" autocomplete="off"
                        placeholder="<?= $h(_('Start typing the trigger to escalate')) ?>">
                    <input type="hidden" class="starget-triggerid">
                    <input type="hidden" class="starget-host">
                    <input type="hidden" class="starget-hostid">
                    <div class="ai-dropdown-list ai-hidden"></div>
                </div>
            </div>
            <div>
                <label class="ai-label"><?= $h(_('Apply to')) ?></label>
                <select class="ai-input starget-scope">
                    <option value="host"><?= $h(_('This host only')) ?></option>
                    <option value="hostgroup"><?= $h(_('A host group')) ?></option>
                    <option value="all"><?= $h(_('All hosts with this problem')) ?></option>
                </select>
            </div>
            <div class="starget-group-wrap ai-hidden">
                <label class="ai-label"><?= $h(_('Host group')) ?></label>
                <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="starget-group">
                    <input class="ai-input starget-group" type="text" autocomplete="off"
                        placeholder="<?= $h(_('Start typing a host group')) ?>">
                    <input type="hidden" class="starget-groupid">
                    <div class="ai-dropdown-list ai-hidden"></div>
                </div>
            </div>
            <div class="starget-match-wrap ai-hidden">
                <label class="ai-label"><?= $h(_('Problem name match')) ?></label>
                <select class="ai-input starget-match">
                    <option value="exact"><?= $h(_('Exact')) ?></option>
                    <option value="contains"><?= $h(_('Contains')) ?></option>
                </select>
            </div>
        </div>
        <div class="ai-repeat-row-actions">
            <button type="button" class="btn tc-remove-starget"><?= $h(_('Remove target')) ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

ob_start();
?>
<div id="tc-root" class="ai-page tc-page" data-ai-theme="<?= $h($ai_theme) ?>" data-active-tab="rules"
    data-csrf-field-name="<?= $h($csrf_field) ?>"
    data-url-rules-get="<?= $h($url('triggercorrelation.rules.get')) ?>"
    data-url-rule-save="<?= $h($url('triggercorrelation.rule.save')) ?>"
    data-url-rule-delete="<?= $h($url('triggercorrelation.rule.delete')) ?>"
    data-url-severity-rule-save="<?= $h($url('triggercorrelation.severity.rule.save')) ?>"
    data-url-severity-rule-delete="<?= $h($url('triggercorrelation.severity.rule.delete')) ?>"
    data-url-settings-save="<?= $h($url('triggercorrelation.settings.save')) ?>"
    data-url-search-hosts="<?= $h($url('triggercorrelation.search.hosts')) ?>"
    data-url-search-triggers="<?= $h($url('triggercorrelation.search.triggers')) ?>"
    data-url-search-items="<?= $h($url('triggercorrelation.search.items')) ?>"
    data-url-search-hostgroups="<?= $h($url('triggercorrelation.search.hostgroups')) ?>"
    data-url-run="<?= $h($url('triggercorrelation.run')) ?>"
    data-url-api-test="<?= $h($url('triggercorrelation.api.test')) ?>"
    data-url-selfcheck="<?= $h($url('triggercorrelation.selfcheck')) ?>"
    data-csrf-rule-save="<?= $h(CCsrfTokenHelper::get('triggercorrelation.rule.save')) ?>"
    data-csrf-rule-delete="<?= $h(CCsrfTokenHelper::get('triggercorrelation.rule.delete')) ?>"
    data-csrf-severity-rule-save="<?= $h(CCsrfTokenHelper::get('triggercorrelation.severity.rule.save')) ?>"
    data-csrf-severity-rule-delete="<?= $h(CCsrfTokenHelper::get('triggercorrelation.severity.rule.delete')) ?>"
    data-csrf-run="<?= $h(CCsrfTokenHelper::get('triggercorrelation.run')) ?>"
    data-csrf-api-test="<?= $h(CCsrfTokenHelper::get('triggercorrelation.api.test')) ?>">

    <div class="ai-header">
        <div>
            <h1><?= $h($data['title'] ?? _('Trigger Correlation')) ?></h1>
            <p class="ai-muted"><?= $h(_('Raise a new, higher-severity Zabbix problem when two or more selected trigger problems are active at the same time.')) ?></p>
        </div>
        <div class="ai-header-actions">
            <button type="button" class="btn" id="tc-test-api"><?= $h(_('Test API')) ?></button>
            <button type="button" class="btn" id="tc-run-all"><?= $h(_('Run evaluation now')) ?></button>
        </div>
    </div>

    <div id="tc-status" class="ai-status ai-hidden" role="status" aria-live="polite"></div>

    <nav class="ai-settings-tabs" role="tablist" aria-label="<?= $h(_('Sections')) ?>">
        <button type="button" role="tab" class="ai-settings-tab is-active" data-tab="rules" aria-selected="true" tabindex="0"><?= $h(_('Correlation rules')) ?></button>
        <button type="button" role="tab" class="ai-settings-tab" data-tab="severity" aria-selected="false" tabindex="-1"><?= $h(_('Severity escalation')) ?></button>
        <button type="button" role="tab" class="ai-settings-tab" data-tab="settings" aria-selected="false" tabindex="-1"><?= $h(_('Settings')) ?></button>
        <button type="button" role="tab" class="ai-settings-tab" data-tab="help" aria-selected="false" tabindex="-1"><?= $h(_('Help')) ?></button>
    </nav>
    <noscript>
        <style>.ai-settings-tabs{display:none!important}.ai-tab-section{display:block!important}</style>
    </noscript>

    <!-- ── RULES ─────────────────────────────────────────────────────── -->
    <section class="ai-card ai-tab-section" data-tab="rules">
        <div class="ai-section-header">
            <h2><?= $h(_('Correlation rules')) ?></h2>
            <button type="button" class="ai-faq-toggle" data-faq-target="faq-rules" title="<?= $h(_('Help')) ?>">?</button>
        </div>
        <div id="faq-rules" class="ai-faq-box">
            <p><strong><?= $h(_('What is this?')) ?></strong> <?= $h(_('Each rule lists two or more source trigger problems. When all of them are active at once, the module writes a synthetic severity value to the receiver host with history.push, and a normal Zabbix trigger on the receiver raises/clears the correlation problem.')) ?></p>
            <p><?= $h(_('Edit a rule to load it into the editor below. "Run" evaluates only that rule now.')) ?></p>
        </div>
        <?php if (!$rules): ?>
            <div class="ai-note-box ai-muted"><?= $h(_('No rules yet. Create your first rule in the editor below.')) ?></div>
        <?php else: ?>
            <div class="tc-table-wrap">
                <table class="tc-table">
                    <thead>
                        <tr>
                            <th><?= $h(_('Status')) ?></th>
                            <th><?= $h(_('Name')) ?></th>
                            <th><?= $h(_('Source triggers')) ?></th>
                            <th><?= $h(_('State')) ?></th>
                            <th><?= $h(_('Last run')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rules as $rule): ?>
                            <?php
                            $rid = (string) ($rule['id'] ?? '');
                            $state = (int) ($rule['last_state'] ?? 0);
                            $corr = (string) (($rule['output']['correlation_id'] ?? '') ?: $rid);
                            $last_err = (string) ($rule['last_error'] ?? '');
                            ?>
                            <tr>
                                <td><?= ($rule['enabled'] ?? true) ? $h(_('Enabled')) : '<span class="ai-muted">'.$h(_('Disabled')).'</span>' ?></td>
                                <td>
                                    <strong><?= $h($rule['name'] ?? $rid) ?></strong>
                                    <div class="ai-muted"><?= $h($corr) ?></div>
                                </td>
                                <td>
                                    <?php foreach ((array) ($rule['conditions'] ?? []) as $c): ?>
                                        <div><strong><?= $h($c['host'] ?? '') ?></strong>: <?= $h($c['trigger'] ?? '') ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><span class="tc-pill <?= $h($state_class($state)) ?>"><?= $h($state === 0 ? _('OK') : ($state_labels[$state] ?? $state)) ?></span></td>
                                <td>
                                    <span class="ai-muted"><?= $h($rule['last_evaluated_iso'] ?? '') ?></span>
                                    <?php if ($last_err !== ''): ?>
                                        <div class="tc-error-text"><?= $h($last_err) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="tc-row-actions">
                                    <button type="button" class="btn tc-edit" data-id="<?= $h($rid) ?>"><?= $h(_('Edit')) ?></button>
                                    <button type="button" class="btn tc-run" data-id="<?= $h($rid) ?>"><?= $h(_('Run')) ?></button>
                                    <button type="button" class="btn tc-delete" data-id="<?= $h($rid) ?>"><?= $h(_('Delete')) ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="ai-card ai-tab-section" data-tab="rules">
        <div class="ai-section-header">
            <h2 id="tc-editor-title"><?= $h(_('Rule editor')) ?></h2>
        </div>
        <div id="tc-rule-editor" data-rule-id="">
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" id="tc-rule-enabled" checked> <?= $h(_('Rule is active')) ?></label>
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Rule name')) ?></label>
                    <input class="ai-input" type="text" id="tc-rule-name" placeholder="<?= $h(_('Windows server update problem')) ?>">
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Description')) ?></label>
                    <textarea class="ai-textarea" id="tc-rule-description" rows="2" placeholder="<?= $h(_('Why this correlation exists and what operators should check.')) ?>"></textarea>
                </div>
            </div>

            <h3><?= $h(_('Source trigger problems')) ?>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-conditions" title="<?= $h(_('Help')) ?>">?</button>
            </h3>
            <div id="faq-conditions" class="ai-faq-box">
                <p><strong><?= $h(_('What is this?')) ?></strong> <?= $h(_('Pick the existing host triggers that, when in problem together, mean a bigger correlated incident — add a host then its trigger, and click “Add another source trigger” for each related one (e.g. a frontend down, its database offline, and an app that depends on that database). A rule needs at least two.')) ?></p>
                <p><?= $h(_('Type a host then pick its trigger from the dropdown, or type the trigger first and the host is filled in. The selection (a hidden id) is what gets stored, so re-pick from the dropdown if you edit the text afterwards.')) ?></p>
            </div>
            <div id="tc-conditions" class="ai-repeat-list">
                <?= $render_condition() ?>
                <?= $render_condition() ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-add-condition"><?= $h(_('Add another source trigger')) ?></button>
            </div>

            <h3><?= $h(_('Output & severity')) ?></h3>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label">
                        <?= $h(_('Output mode')) ?>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-output-mode" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <select class="ai-input" id="tc-output-mode">
                        <option value="receiver_lld"><?= $h(_('Receiver LLD template')) ?></option>
                        <option value="existing_item"><?= $h(_('Existing trapper item')) ?></option>
                    </select>
                </div>
                <div>
                    <label class="ai-label">
                        <?= $h(_('Match mode')) ?>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-match-mode" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <select class="ai-input" id="tc-match-mode">
                        <option value="all"><?= $h(_('All conditions active')) ?></option>
                        <option value="any"><?= $h(_('Any condition active')) ?></option>
                        <option value="count"><?= $h(_('Escalate by active count')) ?></option>
                    </select>
                </div>
                <div id="tc-match-value-wrap">
                    <label class="ai-label"><?= $h(_('Severity when it fires')) ?></label>
                    <select class="ai-input" id="tc-match-value">
                        <?php foreach ($severity_options as $v => $label): ?>
                            <option value="<?= $h($v) ?>" <?= ($v === 4) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="faq-output-mode" class="ai-faq-box">
                <p><strong><?= $h(_('Receiver LLD template')) ?></strong> — <?= $h(_('recommended. The module auto-creates the result item and trigger on a “receiver host” via low-level discovery. That host MUST have the “Template Trigger Correlation Receiver” linked (import templates/trigger_correlation_receiver_zabbix_7.yaml). You only provide a Receiver host and a Correlation ID.')) ?></p>
                <p><strong><?= $h(_('Existing trapper item')) ?></strong> — <?= $h(_('point the rule at a Zabbix trapper item you already created (on any host) that has its own trigger. The module just writes the severity number to that item. Use this when you want full control of the item and trigger, e.g. from your own template.')) ?></p>
            </div>
            <div id="faq-match-mode" class="ai-faq-box">
                <p><strong><?= $h(_('All / Any')) ?></strong> — <?= $h(_('fire the chosen severity when all (or any) of the source triggers are in problem.')) ?></p>
                <p><strong><?= $h(_('Escalate by active count')) ?></strong> — <?= $h(_('raise the severity as more related triggers go into problem. Define tiers like “≥2 active → High, ≥3 → Disaster”. Below the lowest tier the correlation clears.')) ?></p>
            </div>
            <div id="tc-tiers-wrap" class="ai-hidden">
                <label class="ai-label"><?= $h(_('Severity tiers (minimum active count → severity)')) ?></label>
                <div id="tc-tiers" class="ai-repeat-list"></div>
                <div class="ai-section-actions">
                    <button type="button" class="btn" id="tc-add-tier"><?= $h(_('Add tier')) ?></button>
                </div>
            </div>

            <div id="tc-output-receiver" class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-2">
                    <label class="ai-label">
                        <?= $h(_('Receiver host')) ?>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-receiver" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <input class="ai-input" type="text" id="tc-receiver-host" value="<?= $h($settings['receiver_host'] ?? '') ?>" placeholder="Zabbix Correlation Engine">
                </div>
                <div>
                    <label class="ai-label">
                        <?= $h(_('Correlation ID')) ?>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-correlation-id" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <input class="ai-input" type="text" id="tc-correlation-id" placeholder="public_web_app_integration_flow">
                </div>
            </div>
            <div id="faq-receiver" class="ai-faq-box">
                <p><strong><?= $h(_('Receiver host')) ?></strong> — <?= $h(_('the Zabbix host that holds the generated correlation item and problem. For the automatic path it MUST have the “Template Trigger Correlation Receiver” linked (that template provides the discovery rule and the severity-1–5 trigger prototypes). Use the default “Zabbix Correlation Engine” host, or a host of your own that represents this integration/flow — just link that template to it. This is the host’s name, not a template name.')) ?></p>
            </div>
            <div id="faq-correlation-id" class="ai-faq-box">
                <p><strong><?= $h(_('Correlation ID')) ?></strong> — <?= $h(_('a short unique id YOU choose for this one correlation, e.g. public_web_app_integration_flow. It is NOT an item name and NOT a template name.')) ?></p>
                <p><?= $h(_('The module discovers an item trigger.correlation.state[<your id>] on the receiver host and writes the severity to it; the template’s trigger prototypes then raise the problem. Use letters, digits, _ . - (it is lower-cased automatically).')) ?></p>
            </div>

            <div id="tc-output-existing" class="ai-repeat-grid ai-settings-grid ai-hidden">
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Output host')) ?></label>
                    <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="output-host">
                        <input class="ai-input" type="text" id="tc-output-host" autocomplete="off" placeholder="<?= $h(_('Start typing a host')) ?>">
                        <input type="hidden" id="tc-output-hostid">
                        <div class="ai-dropdown-list ai-hidden"></div>
                    </div>
                </div>
                <div class="ai-span-2">
                    <label class="ai-label">
                        <?= $h(_('Output trapper item')) ?>
                        <button type="button" class="ai-faq-toggle" data-faq-target="faq-existing" title="<?= $h(_('Help')) ?>">?</button>
                    </label>
                    <div class="tc-typeahead ai-searchable-dropdown" data-typeahead="output-item">
                        <input class="ai-input" type="text" id="tc-output-item" autocomplete="off" placeholder="<?= $h(_('Start typing an item key or name')) ?>">
                        <input type="hidden" id="tc-output-itemid">
                        <div class="ai-dropdown-list ai-hidden"></div>
                    </div>
                </div>
            </div>
            <div id="faq-existing" class="ai-faq-box">
                <p><strong><?= $h(_('Existing trapper item mode')) ?></strong> — <?= $h(_('choose a host and one of its Zabbix trapper items. Create that item (type “Zabbix trapper”, numeric/unsigned) and a trigger on it beforehand — see the example template trigger_correlation_manual_item_zabbix_7.yaml. The module writes the severity number (0–5) to that item and your trigger raises the problem. No discovery and no receiver template are needed in this mode.')) ?></p>
            </div>

            <h3><?= $h(_('Problem comments')) ?>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-comments" title="<?= $h(_('Help')) ?>">?</button>
            </h3>
            <div id="faq-comments" class="ai-faq-box">
                <p><strong><?= $h(_('What is this?')) ?></strong> <?= $h(_('When the correlation is active the module can add a comment (a Zabbix problem update) listing the related triggers in problem — on the correlation problem itself, and/or cross-linked onto each source problem so an operator on any of them sees the bigger picture. Comments are only re-posted when the set of active triggers or the severity changes.')) ?></p>
                <p><?= $h(_('The API token user must be allowed to add problem updates on those hosts. The action code and chunk size are in Settings → Evaluation behavior.')) ?></p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-2">
                    <label class="ai-checkbox"><input type="checkbox" id="tc-comment-correlation" checked> <?= $h(_('Comment the correlation problem with the related triggers in problem')) ?></label>
                </div>
                <div class="ai-span-2">
                    <label class="ai-checkbox"><input type="checkbox" id="tc-comment-source" checked> <?= $h(_('Cross-link each source problem (note it is part of this correlation)')) ?></label>
                </div>
            </div>

            <p class="ai-muted"><?= $h(_('The module writes 0 when the correlation is no longer true, so the Zabbix trigger resolves automatically.')) ?></p>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-save-rule"><?= $h(_('Save rule')) ?></button>
                <button type="button" class="btn" id="tc-reset-rule"><?= $h(_('New escalation rule')) ?></button>
            </div>
        </div>
    </section>

    <!-- ── SEVERITY ESCALATION ───────────────────────────────────────── -->
    <section class="ai-card ai-tab-section" data-tab="severity">
        <div class="ai-section-header">
            <h2><?= $h(_('Severity escalation rules')) ?></h2>
            <button type="button" class="ai-faq-toggle" data-faq-target="faq-severity" title="<?= $h(_('Help')) ?>">?</button>
        </div>
        <div id="faq-severity" class="ai-faq-box">
            <p><strong><?= $h(_('What is this?')) ?></strong> <?= $h(_('Like correlation, but instead of raising a NEW problem this RAISES THE SEVERITY of one or more existing problems while the source condition holds — then restores their original severity when it clears. It changes the problem (event) severity through Zabbix problem updates, never the trigger configuration, so it is fully reversible and adds the same explanatory comments.')) ?></p>
            <p><?= $h(_('Example: when “demo-sccm01: SSMS service is down” is active, raise “Current month CU not installed” to High — on just that host, a host group, or every host that currently has that problem.')) ?></p>
        </div>
        <?php if (!$severity_rules): ?>
            <div class="ai-note-box ai-muted"><?= $h(_('No severity escalation rules yet. Create your first one in the editor below.')) ?></div>
        <?php else: ?>
            <div class="tc-table-wrap">
                <table class="tc-table">
                    <thead>
                        <tr>
                            <th><?= $h(_('Status')) ?></th>
                            <th><?= $h(_('Name')) ?></th>
                            <th><?= $h(_('When (source triggers)')) ?></th>
                            <th><?= $h(_('Escalate to')) ?></th>
                            <th><?= $h(_('State')) ?></th>
                            <th><?= $h(_('Last run')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($severity_rules as $srule): ?>
                            <?php
                            $srid = (string) ($srule['id'] ?? '');
                            $sstate = (int) ($srule['last_state'] ?? 0);
                            $sapplied = is_array($srule['applied'] ?? null) ? count($srule['applied']) : 0;
                            $sseverity = (int) ($srule['severity'] ?? 4);
                            $serr = (string) ($srule['last_error'] ?? '');
                            ?>
                            <tr>
                                <td><?= ($srule['enabled'] ?? true) ? $h(_('Enabled')) : '<span class="ai-muted">'.$h(_('Disabled')).'</span>' ?></td>
                                <td><strong><?= $h($srule['name'] ?? $srid) ?></strong></td>
                                <td>
                                    <?php foreach ((array) ($srule['conditions'] ?? []) as $c): ?>
                                        <div><strong><?= $h($c['host'] ?? '') ?></strong>: <?= $h($c['trigger'] ?? '') ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <span class="tc-pill <?= $h($state_class($sseverity)) ?>"><?= $h($state_labels[$sseverity] ?? $sseverity) ?></span>
                                    <?php foreach ((array) ($srule['targets'] ?? []) as $t): ?>
                                        <div class="ai-muted">
                                            <?= $h($t['trigger'] ?? '') ?>
                                            <?php
                                            $scope = (string) ($t['scope'] ?? 'host');
                                            $scope_label = $scope === 'hostgroup' ? sprintf(_('in %s'), (string) ($t['group'] ?? '')) : ($scope === 'all' ? _('on all hosts') : sprintf(_('on %s'), (string) ($t['host'] ?? '')));
                                            ?>
                                            <em><?= $h($scope_label) ?></em>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <span class="tc-pill <?= $sstate ? $h($state_class($sseverity)) : 'ok' ?>"><?= $sstate ? $h(sprintf(_('Escalating (%d)'), $sapplied)) : $h(_('Idle')) ?></span>
                                </td>
                                <td>
                                    <span class="ai-muted"><?= $h($srule['last_evaluated_iso'] ?? '') ?></span>
                                    <?php if ($serr !== ''): ?>
                                        <div class="tc-error-text"><?= $h($serr) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="tc-row-actions">
                                    <button type="button" class="btn sev-edit" data-id="<?= $h($srid) ?>"><?= $h(_('Edit')) ?></button>
                                    <button type="button" class="btn sev-run" data-id="<?= $h($srid) ?>"><?= $h(_('Run')) ?></button>
                                    <button type="button" class="btn sev-delete" data-id="<?= $h($srid) ?>"><?= $h(_('Delete')) ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="ai-card ai-tab-section" data-tab="severity">
        <div class="ai-section-header">
            <h2 id="tc-sev-editor-title"><?= $h(_('Severity escalation editor')) ?></h2>
        </div>
        <div id="tc-sev-editor" data-rule-id="">
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Enabled')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" id="tc-sev-enabled" checked> <?= $h(_('Rule is active')) ?></label>
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Rule name')) ?></label>
                    <input class="ai-input" type="text" id="tc-sev-name" placeholder="<?= $h(_('Raise CU severity while SSMS is down')) ?>">
                </div>
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('Description')) ?></label>
                    <textarea class="ai-textarea" id="tc-sev-description" rows="2" placeholder="<?= $h(_('Why this escalation exists and what operators should check.')) ?>"></textarea>
                </div>
            </div>

            <h3><?= $h(_('When these source triggers are active')) ?>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-sev-conditions" title="<?= $h(_('Help')) ?>">?</button>
            </h3>
            <div id="faq-sev-conditions" class="ai-faq-box">
                <p><?= $h(_('Pick one or more existing host triggers. When they are in problem (per the match mode), the escalation fires. A single condition is allowed — e.g. “when SSMS service is down”.')) ?></p>
            </div>
            <div id="tc-sev-conditions" class="ai-repeat-list">
                <?= $render_condition() ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-sev-add-condition"><?= $h(_('Add another source trigger')) ?></button>
            </div>

            <h3><?= $h(_('Match mode')) ?></h3>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Fire when')) ?></label>
                    <select class="ai-input" id="tc-sev-match-mode">
                        <option value="all"><?= $h(_('All source triggers active')) ?></option>
                        <option value="any"><?= $h(_('Any source trigger active')) ?></option>
                        <option value="count"><?= $h(_('At least N source triggers active')) ?></option>
                    </select>
                </div>
                <div id="tc-sev-min-active-wrap" class="ai-hidden">
                    <label class="ai-label"><?= $h(_('Minimum active count')) ?></label>
                    <input class="ai-input" type="number" min="1" max="999" id="tc-sev-min-active" value="1">
                </div>
            </div>

            <h3><?= $h(_('Raise the severity of these problems')) ?>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-sev-targets" title="<?= $h(_('Help')) ?>">?</button>
            </h3>
            <div id="faq-sev-targets" class="ai-faq-box">
                <p><strong><?= $h(_('Target trigger')) ?></strong> — <?= $h(_('the trigger whose active problem(s) you want to raise. Type and pick it from the dropdown.')) ?></p>
                <p><strong><?= $h(_('Apply to')) ?></strong> — <?= $h(_('“This host only” escalates exactly that trigger. “A host group” / “All hosts with this problem” escalate every active problem whose name matches the chosen trigger across that scope (so you can raise “Current month CU not installed” everywhere it is firing).')) ?></p>
            </div>
            <div id="tc-sev-targets" class="ai-repeat-list">
                <?= $render_starget() ?>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-sev-add-target"><?= $h(_('Add another target')) ?></button>
            </div>

            <h3><?= $h(_('Escalated severity')) ?></h3>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Raise matched problems to')) ?></label>
                    <select class="ai-input" id="tc-sev-severity">
                        <?php foreach ($severity_options as $v => $label): ?>
                            <option value="<?= $h($v) ?>" <?= ($v === 4) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Direction')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" id="tc-sev-only-raise" checked> <?= $h(_('Only raise (never lower an already-higher severity)')) ?></label>
                </div>
            </div>

            <h3><?= $h(_('Problem comments')) ?></h3>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-2">
                    <label class="ai-checkbox"><input type="checkbox" id="tc-sev-comment-target" checked> <?= $h(_('Comment each escalated problem (why its severity was raised)')) ?></label>
                </div>
                <div class="ai-span-2">
                    <label class="ai-checkbox"><input type="checkbox" id="tc-sev-comment-source" checked> <?= $h(_('Cross-link the source problems (note what they are escalating)')) ?></label>
                </div>
            </div>

            <p class="ai-muted"><?= $h(_('When the source condition clears (or the rule is disabled/deleted) the original severity is restored automatically.')) ?></p>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-sev-save"><?= $h(_('Save escalation rule')) ?></button>
                <button type="button" class="btn" id="tc-sev-reset"><?= $h(_('New escalation rule')) ?></button>
            </div>
        </div>
    </section>

    <!-- ── SETTINGS ──────────────────────────────────────────────────── -->
    <form id="tc-settings-form" method="post" action="<?= $h($url('triggercorrelation.settings.save')) ?>">
        <input type="hidden" name="<?= $h($csrf_field) ?>" value="<?= $h(CCsrfTokenHelper::get('triggercorrelation.settings.save')) ?>">

        <section class="ai-card ai-tab-section" data-tab="settings">
            <div class="ai-section-header">
                <h2><?= $h(_('Evaluation self-check')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-selfcheck" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-selfcheck" class="ai-faq-box">
                <p><?= $h(_('Checks everything automatic evaluation needs and tells you what is missing or wrong: the API URL/token, the evaluation shared secret, whether the Zabbix API is reachable on the token path the eval endpoint uses, and whether eval.php is deployed and reachable.')) ?></p>
            </div>
            <div class="ai-section-actions">
                <button type="button" class="btn" id="tc-selfcheck-btn"><?= $h(_('Run self-check')) ?></button>
            </div>
            <div id="tc-selfcheck-results" class="tc-checks"></div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="settings">
            <div class="ai-section-header">
                <h2><?= $h(_('Zabbix API')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-api" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-api" class="ai-faq-box">
                <p><strong><?= $h(_('What is this?')) ?></strong> <?= $h(_('The rule-builder reads hosts/triggers/items using the Zabbix frontend internal API under your own session, so no token is needed just to browse. The API token below is required only for the unattended evaluation endpoint (history.push / problem.get), which the Zabbix server calls with no user session.')) ?></p>
                <p><?= $h(_('Set the API URL explicitly (e.g. https://your-zabbix/api_jsonrpc.php). It is required for the evaluation endpoint — for safety the API token is never sent to a URL guessed from the incoming request host.')) ?></p>
                <p><?= $h(_('Prefer delivering the token via the environment variable so it never sits in the database.')) ?></p>
            </div>
            <?php if (!empty($secret_storage['available'])): ?>
                <div class="ai-status ai-status-ok">
                    <strong><?= $h(_('Secret storage: encrypted at rest')) ?></strong>
                    <?= $h(sprintf(_('The Zabbix API token is encrypted in the database using the ZABBIX_TRIGGER_CORRELATION_ENCRYPTION_KEY environment key (%s). Use the same value on every frontend node/container.'), (string) $secret_storage['backend'])) ?>
                </div>
            <?php else: ?>
                <div class="ai-warning">
                    <strong><?= $h(_('Secret storage: not encrypted')) ?></strong>
                    <?= $h(_('Any Zabbix API token you paste here is stored unencrypted in the Zabbix database (module configuration). To encrypt it at rest, set the ZABBIX_TRIGGER_CORRELATION_ENCRYPTION_KEY environment variable for the PHP/web process (the SAME value on every frontend node/container) and re-save. Alternatively, leave the token blank and set its environment-variable name instead so it never touches the database.')) ?>
                </div>
            <?php endif; ?>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-3">
                    <label class="ai-label"><?= $h(_('API URL')) ?></label>
                    <input class="ai-input" type="text" name="api_url" value="<?= $h($settings['api_url'] ?? '') ?>" placeholder="https://zabbix.example.com/api_jsonrpc.php">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('API token')) ?></label>
                    <input class="ai-input" type="password" name="api_token" autocomplete="new-password" value="" placeholder="<?= !empty($settings['api_token_set']) ? $h(_('Configured — leave blank to keep')) : $h(_('Paste API token')) ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($settings['api_token_set'])): ?><span class="ai-muted"><?= $h(_('Stored token exists.')) ?></span><?php endif; ?>
                        <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="clear_api_token" value="1"> <?= $h(_('Clear stored token')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('API token env var')) ?></label>
                    <input class="ai-input" type="text" name="api_token_env" value="<?= $h($settings['api_token_env'] ?? '') ?>" placeholder="ZABBIX_TRIGGER_CORRELATION_API_TOKEN">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('API auth mode')) ?></label>
                    <select class="ai-input" name="api_auth_mode">
                        <?php foreach (['auto' => _('Auto'), 'bearer' => _('Authorization Bearer'), 'auth_property' => _('auth property (Zabbix 7.0/7.1)')] as $v => $label): ?>
                            <option value="<?= $h($v) ?>" <?= (($settings['api_auth_mode'] ?? 'auto') === $v) ? 'selected' : '' ?>><?= $h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('API timeout (seconds)')) ?></label>
                    <input class="ai-input" type="number" min="3" max="120" name="timeout" value="<?= $h((int) ($settings['timeout'] ?? 15)) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Verify TLS certificate')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="verify_peer" value="1" <?= !empty($settings['verify_peer']) ? 'checked' : '' ?>> <?= $h(_('Enable certificate validation')) ?></label>
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="settings">
            <div class="ai-section-header">
                <h2><?= $h(_('Evaluation endpoint')) ?></h2>
                <button type="button" class="ai-faq-toggle" data-faq-target="faq-eval" title="<?= $h(_('Help')) ?>">?</button>
            </div>
            <div id="faq-eval" class="ai-faq-box">
                <p><strong><?= $h(_('Evaluation shared secret')) ?></strong> <?= $h(_('authenticates the receiver template HTTP-agent item that drives automatic evaluation. REQUIRED: while it is blank the evaluation endpoint rejects every call with “Access denied”.')) ?></p>
                <p><?= $h(_('Type any long random string here and set the SAME value in the receiver host macro {$TRIGGER.CORRELATION.TOKEN}. They must match. The secret is sent only via the X-Trigger-Correlation-Token header and is stored as a one-way hash.')) ?></p>
                <p><?= $h(_('Docker / split installs: this is enough — the hash lives in the shared Zabbix database, so every frontend container uses it; no per-container setup is needed. The “Evaluation token env var” is an optional alternative (provide the secret via an environment variable instead of storing it) — you do not need it.')) ?></p>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Evaluation shared secret')) ?></label>
                    <input class="ai-input" type="password" name="eval_token" autocomplete="new-password" value="" placeholder="<?= !empty($settings['eval_token_set']) ? $h(_('Configured — leave blank to keep')) : $h(_('Long random secret')) ?>">
                    <div class="ai-inline-notes">
                        <?php if (!empty($settings['eval_token_set'])): ?><span class="ai-muted"><?= $h(_('Stored secret exists.')) ?></span><?php endif; ?>
                        <label class="ai-checkbox ai-checkbox-danger"><input type="checkbox" name="clear_eval_token" value="1"> <?= $h(_('Clear stored secret')) ?></label>
                    </div>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Evaluation token env var')) ?></label>
                    <input class="ai-input" type="text" name="eval_token_env" value="<?= $h($settings['eval_token_env'] ?? '') ?>" placeholder="ZABBIX_TRIGGER_CORRELATION_EVAL_TOKEN">
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="settings">
            <div class="ai-section-header">
                <h2><?= $h(_('Receiver')) ?></h2>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Default receiver host')) ?></label>
                    <input class="ai-input" type="text" name="receiver_host" value="<?= $h($settings['receiver_host'] ?? '') ?>" placeholder="Zabbix Correlation Engine">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Discovery key')) ?></label>
                    <input class="ai-input" type="text" name="receiver_discovery_key" value="<?= $h($settings['receiver_discovery_key'] ?? '') ?>" placeholder="trigger.correlation.discovery">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('State key template')) ?></label>
                    <input class="ai-input" type="text" name="receiver_state_key_template" value="<?= $h($settings['receiver_state_key_template'] ?? '') ?>" placeholder="trigger.correlation.state[%s]">
                </div>
                <div class="ai-span-2">
                    <label class="ai-label"><?= $h(_('Context key template')) ?></label>
                    <input class="ai-input" type="text" name="receiver_context_key_template" value="<?= $h($settings['receiver_context_key_template'] ?? '') ?>" placeholder="trigger.correlation.context[%s]">
                </div>
            </div>
        </section>

        <section class="ai-card ai-tab-section" data-tab="settings">
            <div class="ai-section-header">
                <h2><?= $h(_('Evaluation behavior')) ?></h2>
            </div>
            <div class="ai-repeat-grid ai-settings-grid">
                <div>
                    <label class="ai-label"><?= $h(_('Min active seconds before match')) ?></label>
                    <input class="ai-input" type="number" min="0" max="86400" name="min_active_seconds" value="<?= $h((int) ($settings['min_active_seconds'] ?? 0)) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Problem update action code')) ?></label>
                    <input class="ai-input" type="number" min="1" max="256" name="problem_update_action" value="<?= $h((int) ($settings['problem_update_action'] ?? 4)) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Comment chunk size')) ?></label>
                    <input class="ai-input" type="number" min="200" max="2000" name="comment_chunk_size" value="<?= $h((int) ($settings['comment_chunk_size'] ?? 1900)) ?>">
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Ignore suppressed source problems')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="ignore_suppressed" value="1" <?= !empty($settings['ignore_suppressed']) ? 'checked' : '' ?>> <?= $h(_('Skip suppressed problems')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Ignore symptom source problems')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="ignore_symptoms" value="1" <?= !empty($settings['ignore_symptoms']) ? 'checked' : '' ?>> <?= $h(_('Skip symptom problems')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Push LLD discovery every evaluation')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="push_discovery_every_eval" value="1" <?= !empty($settings['push_discovery_every_eval']) ? 'checked' : '' ?>> <?= $h(_('Keep receiver items discovered')) ?></label>
                </div>
                <div>
                    <label class="ai-label"><?= $h(_('Clear disabled rules')) ?></label>
                    <label class="ai-checkbox"><input type="checkbox" name="clear_disabled_rules" value="1" <?= !empty($settings['clear_disabled_rules']) ? 'checked' : '' ?>> <?= $h(_('Push 0 for disabled rules')) ?></label>
                </div>
            </div>
        </section>

        <div class="ai-tab-section ai-section-actions ai-sticky-actions" data-tab="settings">
            <button type="submit" class="btn"><?= $h(_('Save settings')) ?></button>
            <span class="ai-muted"><?= $h(_('Storage')) ?>: <?= $h($storage_label) ?></span>
        </div>
    </form>

    <!-- ── HELP ──────────────────────────────────────────────────────── -->
    <section class="ai-card ai-tab-section" data-tab="help">
        <div class="ai-section-header"><h2><?= $h(_('How it works')) ?></h2></div>
        <div class="ai-faq-box ai-faq-visible">
            <p><strong><?= $h(_('Pipeline')) ?>:</strong> <?= $h(_('Zabbix trigger problems → this module evaluates each rule → history.push writes a severity number (0–5) to a trapper item → a normal Zabbix trigger on that item raises/clears the correlation problem. Optionally the module then comments the problems with the related triggers.')) ?></p>
            <p><strong><?= $h(_('One-time setup')) ?>:</strong></p>
            <ul>
                <li><?= $h(_('Settings tab: set the Zabbix API URL, an API token, and an evaluation shared secret.')) ?></li>
                <li><?= $h(_('Import templates/trigger_correlation_receiver_zabbix_7.yaml (for receiver-LLD rules).')) ?></li>
                <li><?= $h(_('On the receiver host set macros {$TRIGGER.CORRELATION.URL} (frontend URL reachable from the Zabbix server) and {$TRIGGER.CORRELATION.TOKEN} (the same shared secret).')) ?></li>
            </ul>
            <p><strong><?= $h(_('Split / Docker')) ?>:</strong> <?= $h(_('All configuration and rules live in the Zabbix database, so every frontend node/container shares the same state and nothing is lost on restart.')) ?></p>
        </div>
    </section>

    <section class="ai-card ai-tab-section" data-tab="help">
        <div class="ai-section-header"><h2><?= $h(_('Severity escalation (second feature)')) ?></h2></div>
        <div class="ai-faq-box ai-faq-visible">
            <p><?= $h(_('The Severity escalation tab does NOT create a new problem. While a source condition holds, it RAISES THE SEVERITY of one or more existing problems, and restores their original severity when it clears (or when the rule is disabled/deleted).')) ?></p>
            <p><strong><?= $h(_('How')) ?>:</strong> <?= $h(_('it changes each problem\'s manual event severity through a Zabbix problem update (event.acknowledge, change-severity action) — never the trigger\'s configured priority — so it is fully reversible and a re-fired problem keeps its normal priority. No receiver template, LLD or extra item is needed.')) ?></p>
            <p><strong><?= $h(_('A rule has')) ?>:</strong></p>
            <ul>
                <li><strong><?= $h(_('Source conditions')) ?></strong> — <?= $h(_('one or more host+trigger problems (the “when”), with match mode All / Any / at least N. A single condition is allowed.')) ?></li>
                <li><strong><?= $h(_('Targets')) ?></strong> — <?= $h(_('one or more triggers to raise, each with an “Apply to” scope: this host only (that exact trigger), a host group, or all hosts with that problem (matched by the trigger name across the scope).')) ?></li>
                <li><strong><?= $h(_('Escalated severity')) ?></strong> — <?= $h(_('the severity to raise to, with an “Only raise” option so an already-higher problem is left alone.')) ?></li>
                <li><strong><?= $h(_('Comments')) ?></strong> — <?= $h(_('comment each escalated problem and/or cross-link the source problems, like correlation.')) ?></li>
            </ul>
            <p><strong><?= $h(_('Example')) ?>:</strong> <?= $h(_('when “sccm01: SSMS service is down” is active, raise “Current month CU not installed” to Disaster on the affected host (or a host group, or all hosts). The same 1-minute heartbeat (eval.php) drives it; the API token user needs “Change severity” permission on the target host groups.')) ?></p>
        </div>
    </section>

    <section class="ai-card ai-tab-section" data-tab="help">
        <div class="ai-section-header"><h2><?= $h(_('Build a correlation rule')) ?></h2></div>
        <div class="ai-faq-box ai-faq-visible">
            <p><strong><?= $h(_('1. Conditions')) ?></strong> — <?= $h(_('add two or more host + trigger conditions (e.g. a frontend down, its database offline, and an app that depends on that database).')) ?></p>
            <p><strong><?= $h(_('2. Severity')) ?></strong> — <?= $h(_('choose a Match mode: All / Any → a fixed severity; or Escalate by active count → tiers like “≥2 active → High, ≥3 → Disaster”.')) ?></p>
            <p><strong><?= $h(_('3. Output')) ?></strong> — <?= $h(_('pick where the correlation problem is raised:')) ?></p>
            <ul>
                <li><strong><?= $h(_('Receiver LLD template (recommended)')) ?></strong> — <?= $h(_('set Receiver host to a host that has “Template Trigger Correlation Receiver” linked (the default “Zabbix Correlation Engine”, or your own flow host with that template linked). Set Correlation ID to a short unique id you choose, e.g. public_web_app_integration_flow. The module discovers trigger.correlation.state[public_web_app_integration_flow] there and the template raises the problem. The Correlation ID is NOT an item or template name.')) ?></li>
                <li><strong><?= $h(_('Existing trapper item')) ?></strong> — <?= $h(_('create your own “Zabbix trapper” item + trigger (see templates/trigger_correlation_manual_item_zabbix_7.yaml) and select that host + item here. The module writes the severity to it; your trigger raises the problem.')) ?></li>
            </ul>
            <p><strong><?= $h(_('4. Comments (optional)')) ?></strong> — <?= $h(_('tick to annotate the correlation problem and/or the source problems with the related triggers in problem.')) ?></p>
            <p><strong><?= $h(_('5. Save')) ?></strong>, <?= $h(_('then “Run evaluation now” to test. Add as many rules as you need — one per integration/escalation.')) ?></p>
            <p class="ai-muted"><?= $h(_('A full step-by-step walkthrough for both modes is in SETUP_RULES.md.')) ?></p>
        </div>
    </section>

    <section class="ai-card ai-tab-section" data-tab="help">
        <div class="ai-section-header"><h2><?= $h(_('Where the correlation item comes from')) ?></h2></div>
        <div class="ai-faq-box ai-faq-visible">
            <p><?= $h(_('In Receiver-LLD mode the module writes the severity (0–5) to the item key trigger.correlation.state[<your Correlation ID>] on the receiver host, by host + key. You get that item in one of three ways:')) ?></p>
            <p><strong><?= $h(_('A) Automatic — the shipped receiver template')) ?></strong> — <?= $h(_('link “Template Trigger Correlation Receiver” to the host. It has three parts: an HTTP-agent item (trigger.correlation.eval) that calls the module every minute to run evaluations; a discovery rule (trigger.correlation.discovery); and item/trigger PROTOTYPES with the LLD macro trigger.correlation.state[{#CORRELATION.ID}]. On each run the module pushes a discovery row with your Correlation ID, Zabbix creates trigger.correlation.state[<id>] automatically, and the prototype triggers raise the problem. Do NOT replace {#CORRELATION.ID} with a fixed id — that macro is exactly what makes discovery work.')) ?></p>
            <p><strong><?= $h(_('B) Your own template for the flow (no discovery)')) ?></strong> — <?= $h(_('create a template for the flow host with a plain “Zabbix trapper” item whose key is exactly trigger.correlation.state[<your Correlation ID>] (e.g. trigger.correlation.state[public_web_app_integration_flow]) plus value-based triggers, and link it to the host. Because the module pushes by host + key, it lands on your item — no discovery needed. You may untick “Push LLD discovery every evaluation” in Settings to avoid a harmless discovery error. Prefer a key like correlation.escalation[%s] instead? Set Settings → State key template to it and name your item to match (correlation.escalation[<your Correlation ID>]).')) ?></p>
            <p><strong><?= $h(_('C) Existing trapper item mode')) ?></strong> — <?= $h(_('or create any trapper item + trigger (see the “Template Trigger Correlation Manual Item” example), switch the rule’s Output mode to “Existing trapper item”, and pick that host + item. Here you select the item directly, so the key can be anything and the Correlation ID field is not used.')) ?></p>
        </div>
    </section>

    <section class="ai-card ai-tab-section" data-tab="help">
        <div class="ai-section-header"><h2><?= $h(_('Troubleshooting')) ?></h2></div>
        <div class="ai-faq-box ai-faq-visible">
            <p><strong><?= $h(_('Eval endpoint: “Access denied”')) ?></strong> — <?= $h(_('the Evaluation shared secret is not set, or the receiver host macro {$TRIGGER.CORRELATION.TOKEN} does not match it. Set the secret in Settings and the same value in the macro. (Opening the eval URL in a browser always shows Access denied — there is no token header — use “Run evaluation now” to test from the UI.)')) ?></p>
            <p><strong><?= $h(_('Eval endpoint: “Page not found”')) ?></strong> — <?= $h(_('the module is not enabled, or it was renamed/upgraded and needs Administration → General → Modules → Scan directory, then Enable “Trigger Correlation”.')) ?></p>
            <p><strong><?= $h(_('“Discovery pending”')) ?></strong> — <?= $h(_('receiver-LLD mode creates the state item by discovery; the first run may report this until Zabbix processes the LLD. Run evaluation again after a minute.')) ?></p>
            <p><strong><?= $h(_('No correlation problem appears')) ?></strong> — <?= $h(_('confirm the receiver host has “Template Trigger Correlation Receiver” linked, the API URL + token are set (Test API), and the source triggers are actually in problem. Check Latest data on the receiver host for trigger.correlation.state[...].')) ?></p>
            <p><strong><?= $h(_('No comments posted')) ?></strong> — <?= $h(_('the API token user needs “add problem update” permission on those hosts; comments are throttled (re-posted only when the active trigger set or severity changes).')) ?></p>
            <p><strong><?= $h(_('API token never sent')) ?></strong> — <?= $h(_('set an explicit API URL in Settings; for safety the token is never sent to a URL derived from the request host.')) ?></p>
        </div>
    </section>
</div>

<script type="text/template" id="tc-condition-template"><?= str_replace('</script>', '<\/script>', $render_condition()) ?></script>
<script type="text/template" id="tc-tier-template"><?= str_replace('</script>', '<\/script>', $render_tier()) ?></script>
<script type="text/template" id="tc-starget-template"><?= str_replace('</script>', '<\/script>', $render_starget()) ?></script>
<script type="application/json" id="tc-rules-data"><?= str_replace('</', '<\/', json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
<script type="application/json" id="tc-severity-rules-data"><?= str_replace('</', '<\/', json_encode($severity_rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
<?php
$content = ob_get_clean();

(new CHtmlPage())
    ->setTitle($data['title'] ?? _('Trigger Correlation'))
    ->addItem(new class($content) {
        private $html;
        public function __construct($html) { $this->html = $html; }
        public function toString($destroy = true) { return $this->html; }
    })
    ->show();
