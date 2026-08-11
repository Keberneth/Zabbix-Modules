<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Helpers;

/**
 * Small HTML fragments shared by the module page and the standalone export, so
 * a status reads identically in both. Both surfaces define the same classes.
 *
 * Status colour never carries meaning on its own here: every pill is a glyph
 * plus a word.
 */
class ViewFormatter {

    private ReportDataHelper $helper;

    public function __construct(ReportDataHelper $helper) {
        $this->helper = $helper;
    }

    public function esc($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Status pill: glyph + label, tinted by state.
     */
    public function pill(string $state, string $label): string {
        $glyphs = [
            'ok' => '●',
            'warning' => '▲',
            'critical' => '■',
            'neutral' => '○'
        ];

        $tone = in_array($state, ['ok', 'warning', 'critical'], true) ? $state : 'neutral';

        return sprintf(
            '<span class="vr-status vr-status--%s"><span class="vr-status-glyph" aria-hidden="true">%s</span>%s</span>',
            $tone,
            $glyphs[$tone],
            $this->esc($label)
        );
    }

    /**
     * How a job ended.
     */
    public function jobResult(array $job): string {
        $map = [
            'success' => ['ok', _('Success')],
            'warning' => ['warning', _('Warning')],
            'failed' => ['critical', _('Failed')],
            'none' => ['neutral', _('Not run yet')],
            'unknown' => ['neutral', _('Unknown')]
        ];

        [$tone, $label] = $map[$job['result_state']] ?? ['neutral', _('Unknown')];

        // Prefer whatever Veeam actually said, when it says something.
        if ((string) $job['last_result'] !== '' && $job['result_state'] !== 'unknown') {
            $label = (string) $job['last_result'];
        }

        return $this->pill($tone, $label);
    }

    /**
     * Whether a backup is recent enough.
     */
    public function freshness(string $state, ?int $age_seconds): string {
        switch ($state) {
            case 'ok':
                return $this->pill('ok', $this->helper->formatAge($age_seconds));

            case 'warning':
                return $this->pill('warning', $this->helper->formatAge($age_seconds));

            case 'critical':
                return $this->pill('critical', $this->helper->formatAge($age_seconds));

            default:
                return $this->pill('neutral', _('No data'));
        }
    }

    public function repositoryState(string $state, bool $online, bool $out_of_date): string {
        if (!$online) {
            return $this->pill('critical', _('Offline'));
        }
        if ($state === 'critical') {
            return $this->pill('critical', _('Almost full'));
        }
        if ($state === 'warning') {
            return $this->pill('warning', _('Low space'));
        }
        if ($out_of_date) {
            return $this->pill('warning', _('Out of date'));
        }
        if ($state === 'unknown') {
            return $this->pill('neutral', _('Unknown'));
        }

        return $this->pill('ok', _('Healthy'));
    }

    /**
     * Signed change, coloured by direction. Growth is not automatically bad, so
     * this stays informational rather than alarming.
     */
    public function delta($bytes): string {
        if ($bytes === null) {
            return '<span class="vr-delta-flat">—</span>';
        }

        $value = (float) $bytes;
        if (abs($value) < 1) {
            return '<span class="vr-delta-flat">'._('no change').'</span>';
        }

        $class = $value > 0 ? 'vr-delta-up' : 'vr-delta-down';
        $arrow = $value > 0 ? '↑' : '↓';

        return sprintf(
            '<span class="%s">%s %s</span>',
            $class,
            $arrow,
            $this->esc($this->helper->formatBytes(abs($value)))
        );
    }

    /**
     * A coloured square that ties a table row to its slice of a chart.
     */
    public function swatch(string $token): string {
        return sprintf(
            '<span class="vr-swatch" style="background:var(%s)" aria-hidden="true"></span>',
            ChartRenderer::safeToken($token)
        );
    }

    /**
     * Plain-language summary of what the volume chart shows.
     */
    public function growthSentence(array $growth, string $metric_label): string {
        if ($growth['change'] === null || $growth['pct'] === null) {
            return _('Not enough history in this period to describe a trend.');
        }

        $direction = $growth['change'] >= 0 ? _('grew') : _('shrank');

        return sprintf(
            _('%1$s %2$s by %3$s (%4$s) over this period, about %5$s per month at the current rate.'),
            $metric_label,
            $direction,
            $this->helper->formatBytes(abs((float) $growth['change'])),
            $this->helper->formatPct(abs((float) $growth['pct']), 1),
            $this->helper->formatBytes(abs((float) $growth['per_month']))
        );
    }
}
