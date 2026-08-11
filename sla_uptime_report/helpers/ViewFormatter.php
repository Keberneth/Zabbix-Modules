<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

/**
 * Small HTML fragments shared by the module page and the standalone export, so
 * a status reads identically in both. Both surfaces define the same classes.
 *
 * Status colour never carries meaning on its own: every pill is a glyph plus a
 * word, and every tinted heatmap cell also prints its value.
 */
class ViewFormatter {

	private ReportDataHelper $helper;

	public function __construct(ReportDataHelper $helper) {
		$this->helper = $helper;
	}

	public function esc($value): string {
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
			'<span class="sr-status sr-status--%s"><span class="sr-status-glyph" aria-hidden="true">%s</span>%s</span>',
			$tone,
			$glyphs[$tone],
			$this->esc($label)
		);
	}

	/**
	 * Availability pill for a host row.
	 */
	public function hostState(string $state, ?float $pct): string {
		switch ($state) {
			case 'ok':
				return $this->pill('ok', $this->helper->formatPct($pct, 2));

			case 'warn':
				return $this->pill('warning', $this->helper->formatPct($pct, 2));

			case 'bad':
				return $this->pill('critical', $this->helper->formatPct($pct, 2));

			case 'noitem':
				return $this->pill('neutral', _('No item'));

			default:
				return $this->pill('neutral', _('No data'));
		}
	}

	/**
	 * SLI heatmap cell: tinted background AND the printed value, so colour never
	 * carries the number alone.
	 */
	public function sliCell(?float $pct, ?float $slo): string {
		$state = $this->helper->sliState($pct, $slo);

		$text = $pct === null ? '—' : number_format($pct, $pct >= 100.0 ? 0 : 2, '.', ' ');

		return sprintf(
			'<td class="sr-heat sr-heat--%s">%s</td>',
			$this->esc($state),
			$this->esc($text)
		);
	}

	/**
	 * Compliance pill for a service against its SLO.
	 */
	public function sloCompliance(string $state, ?float $latest, ?float $slo): string {
		switch ($state) {
			case 'ok':
				return $this->pill('ok', _('Meeting SLO'));

			case 'warn':
				return $this->pill('warning', _('Just below SLO'));

			case 'bad':
				return $this->pill('critical', _('Below SLO'));

			default:
				return $this->pill('neutral', _('Not measured'));
		}
	}

	/**
	 * A coloured square that ties a table row to its slice of a chart.
	 */
	public function swatch(string $token): string {
		return sprintf(
			'<span class="sr-swatch" style="background:var(%s)" aria-hidden="true"></span>',
			ChartRenderer::safeToken($token)
		);
	}
}
