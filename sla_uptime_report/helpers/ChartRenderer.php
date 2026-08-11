<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

/**
 * Server-side SVG chart renderer.
 *
 * Every chart is a self-contained <svg> string with no script and no external
 * reference, so the exact same markup works in the module page and inside the
 * standalone HTML export (and prints).
 *
 * Colours are emitted as `style="fill:var(--sr-s1)"` rather than literal hex so
 * one renderer serves both the light and the dark theme: the consuming page
 * defines --sr-* once per theme. Chart chrome (gridlines, axis text, baseline)
 * uses CSS classes for the same reason.
 *
 * Palette: the validated 8-slot categorical set. Slots are assigned by entity,
 * never by rank, so filtering a series out never repaints the survivors.
 */
class ChartRenderer {

    /** Number of categorical slots before the tail folds into "Other". */
    public const SERIES_SLOTS = 8;

    /** Bars never fill their whole band - the leftover is deliberate air. */
    private const BAR_MAX_WIDTH = 24;

    /** Surface-coloured gap that separates touching marks (px). */
    private const SURFACE_GAP = 2;

    /** Beyond this many categories a stacked column chart becomes an area chart. */
    private const COLUMNS_MAX = 62;

    /**
     * CSS custom-property name for a categorical slot (1-based, wraps to Other).
     */
    public static function seriesToken(int $index): string {
        $slot = $index < self::SERIES_SLOTS ? $index + 1 : self::SERIES_SLOTS;

        return '--sr-s'.$slot;
    }

    /**
     * Guard for the one place a string reaches a style attribute.
     *
     * Every token today is either seriesToken() output or a literal in this
     * repo, so nothing user-controlled reaches here - but a style attribute is
     * exactly where a future refactor passing a tag value through would turn
     * into an injection. Anything that is not a plain custom-property name is
     * replaced rather than escaped, so a bad token can only ever mis-colour.
     */
    public static function safeToken(?string $token): string {
        return ($token !== null && preg_match('/^--[a-z0-9-]{1,40}$/', $token) === 1)
            ? $token
            : '--sr-s1';
    }

    /**
     * Stacked time chart: columns for short ranges, area for long ones.
     *
     * @param array $categories  Ordered x labels (dates).
     * @param array $series      [['label' => string, 'token' => '--sr-s1', 'values' => float[]], ...]
     * @param array $opts        title, subtitle, format (callable), height, empty_text
     */
    public function stackedTime(array $categories, array $series, array $opts = []): string {
        if ($categories === [] || $series === []) {
            return $this->emptyChart($opts['empty_text'] ?? _('No data for the selected period.'));
        }

        return count($categories) > self::COLUMNS_MAX
            ? $this->stackedArea($categories, $series, $opts)
            : $this->stackedColumns($categories, $series, $opts);
    }

    /**
     * Horizontal bars - the default form for "compare magnitudes across names".
     *
     * @param array $rows [['label' => string, 'value' => float, 'text' => string, 'token' => string], ...]
     */
    public function hBars(array $rows, array $opts = []): string {
        if ($rows === []) {
            return $this->emptyChart($opts['empty_text'] ?? _('Nothing to show.'));
        }

        $rows = array_slice($rows, 0, (int) ($opts['max_rows'] ?? 12));
        $width = 920;
        $row_h = 34;
        $pad_top = 8;
        $pad_bottom = 8;
        $label_w = (int) ($opts['label_width'] ?? 250);
        $value_w = 132;
        $height = $pad_top + $pad_bottom + count($rows) * $row_h;

        $max = 0.0;
        foreach ($rows as $row) {
            $max = max($max, (float) $row['value']);
        }
        if ($max <= 0) {
            $max = 1.0;
        }

        $track_x = $label_w + 12;
        $track_w = $width - $track_x - $value_w - 12;

        $svg = [];
        $svg[] = $this->openSvg($width, $height, $opts['title'] ?? '');

        $y = $pad_top;
        foreach ($rows as $row) {
            $value = max(0.0, (float) $row['value']);
            $bar_w = (int) round(($value / $max) * $track_w);
            $bar_h = 16;
            $bar_y = $y + (int) (($row_h - $bar_h) / 2);
            $token = self::safeToken($row['token'] ?? null);
            $tip = $this->text((string) $row['label']).' - '.$this->text((string) $row['text']);

            // Track: a lighter step of the same ramp, so the state reads across
            // the whole bar rather than only where it is filled.
            $svg[] = sprintf(
                '<rect class="sr-track" x="%d" y="%d" width="%d" height="%d" rx="4"/>',
                $track_x, $bar_y, $track_w, $bar_h
            );

            $svg[] = sprintf(
                '<g class="sr-mark" data-tip="%s"><title>%s</title>%s</g>',
                $tip,
                $tip,
                sprintf(
                    '<path d="%s" style="fill:var(%s)"/>',
                    $this->roundedRectPath($track_x, $bar_y, max(2, $bar_w), $bar_h, 4, 'right'),
                    $token
                )
            );

            $svg[] = sprintf(
                '<text class="sr-label" x="%d" y="%d">%s</text>',
                $label_w, $bar_y + 12, $this->text($this->truncate((string) $row['label'], 34))
            );
            $svg[] = sprintf(
                '<text class="sr-value" x="%d" y="%d">%s</text>',
                $width - 8, $bar_y + 12, $this->text((string) $row['text'])
            );

            $y += $row_h;
        }

        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * Donut for part-to-whole at a glance. Capped at 6 slices by the caller.
     *
     * @param array $slices [['label' => string, 'value' => float, 'text' => string, 'token' => string], ...]
     */
    public function donut(array $slices, string $center_value, string $center_label, array $opts = []): string {
        $total = 0.0;
        foreach ($slices as $slice) {
            $total += max(0.0, (float) $slice['value']);
        }

        $size = 260;
        $cx = $size / 2;
        $cy = $size / 2;
        $r_outer = 104;
        $r_inner = 70;

        $svg = [];
        $svg[] = $this->openSvg($size, $size, $opts['title'] ?? '');

        if ($total <= 0) {
            $svg[] = sprintf(
                '<circle class="sr-track-ring" cx="%s" cy="%s" r="%s" fill="none" stroke-width="%d"/>',
                $cx, $cy, ($r_outer + $r_inner) / 2, $r_outer - $r_inner
            );
        }
        else {
            // A 2px surface gap separates neighbouring arcs instead of a stroke.
            $gap_deg = count($slices) > 1 ? 1.6 : 0.0;
            $angle = -90.0;

            foreach ($slices as $slice) {
                $value = max(0.0, (float) $slice['value']);
                if ($value <= 0) {
                    continue;
                }

                $sweep = ($value / $total) * 360.0;
                $start = $angle + ($gap_deg / 2);
                $end = $angle + $sweep - ($gap_deg / 2);
                $angle += $sweep;

                if ($end <= $start) {
                    continue;
                }

                $tip = $this->text((string) $slice['label']).' - '.$this->text((string) $slice['text']);
                $svg[] = sprintf(
                    '<g class="sr-mark" data-tip="%s"><title>%s</title><path d="%s" style="fill:var(%s)"/></g>',
                    $tip,
                    $tip,
                    $this->arcPath($cx, $cy, $r_inner, $r_outer, $start, $end),
                    self::safeToken($slice['token'] ?? null)
                );
            }
        }

        $svg[] = sprintf(
            '<text class="sr-donut-value" x="%s" y="%s" text-anchor="middle">%s</text>',
            $cx, $cy + 4, $this->text($center_value)
        );
        $svg[] = sprintf(
            '<text class="sr-donut-label" x="%s" y="%s" text-anchor="middle">%s</text>',
            $cx, $cy + 24, $this->text($center_label)
        );
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * Single-series line with an optional dashed forecast tail and a threshold
     * rule. One y-axis only - a second scale would invent a correlation.
     *
     * @param array $points   [['label' => string, 'value' => float], ...]
     * @param array $forecast [['label' => string, 'value' => float], ...] continuing the line
     */
    public function lineChart(array $points, array $forecast = [], array $opts = []): string {
        if ($points === []) {
            return $this->emptyChart($opts['empty_text'] ?? _('No data for the selected period.'));
        }

        $width = 920;
        $height = (int) ($opts['height'] ?? 300);
        $pad = ['t' => 16, 'r' => 16, 'b' => 42, 'l' => 74];
        $plot_w = $width - $pad['l'] - $pad['r'];
        $plot_h = $height - $pad['t'] - $pad['b'];
        $format = $opts['format'] ?? static fn($v): string => (string) round((float) $v, 2);

        $all = array_merge($points, $forecast);
        $max = 0.0;
        foreach ($all as $point) {
            $max = max($max, (float) $point['value']);
        }
        $threshold = isset($opts['threshold']) ? (float) $opts['threshold'] : null;
        if ($threshold !== null) {
            $max = max($max, $threshold);
        }

        $scale = $this->niceScale($max, (float) ($opts['scale_base'] ?? 10.0));
        $count = max(1, count($all) - 1);
        $x_at = static fn(int $i): float => $pad['l'] + ($count > 0 ? ($i / $count) * $plot_w : 0);
        $y_at = fn(float $v): float => $pad['t'] + $plot_h - ($scale['max'] > 0 ? ($v / $scale['max']) * $plot_h : 0);

        $svg = [];
        $svg[] = $this->openSvg($width, $height, $opts['title'] ?? '');
        $svg[] = $this->yAxis($scale, $pad, $plot_w, $plot_h, $format);

        if ($threshold !== null) {
            $ty = $y_at($threshold);
            $svg[] = sprintf(
                '<line class="sr-threshold" x1="%s" y1="%s" x2="%s" y2="%s"/>',
                $pad['l'], $ty, $pad['l'] + $plot_w, $ty
            );
            $svg[] = sprintf(
                '<text class="sr-threshold-label" x="%s" y="%s" text-anchor="end">%s</text>',
                $pad['l'] + $plot_w, $ty - 6, $this->text((string) ($opts['threshold_label'] ?? ''))
            );
        }

        $line = [];
        $area = [];
        foreach ($points as $i => $point) {
            $x = $x_at($i);
            $y = $y_at((float) $point['value']);
            $line[] = ($i === 0 ? 'M' : 'L').round($x, 1).' '.round($y, 1);
            $area[] = ($i === 0 ? 'M' : 'L').round($x, 1).' '.round($y, 1);
        }

        if ($area !== []) {
            $last_x = $x_at(count($points) - 1);
            $area[] = 'L'.round($last_x, 1).' '.round($pad['t'] + $plot_h, 1);
            $area[] = 'L'.round($x_at(0), 1).' '.round($pad['t'] + $plot_h, 1);
            $area[] = 'Z';
            $svg[] = sprintf(
                '<path class="sr-area" d="%s" style="fill:var(%s)"/>',
                implode(' ', $area), self::safeToken($opts['token'] ?? null)
            );
        }

        $svg[] = sprintf(
            '<path class="sr-line" d="%s" style="stroke:var(%s)"/>',
            implode(' ', $line), self::safeToken($opts['token'] ?? null)
        );

        if ($forecast !== []) {
            $tail = [];
            $offset = count($points) - 1;
            $tail[] = 'M'.round($x_at($offset), 1).' '.round($y_at((float) $points[$offset]['value']), 1);
            foreach ($forecast as $i => $point) {
                $tail[] = 'L'.round($x_at($offset + $i + 1), 1).' '.round($y_at((float) $point['value']), 1);
            }
            $svg[] = sprintf(
                '<path class="sr-line sr-line--forecast" d="%s" style="stroke:var(%s)"/>',
                implode(' ', $tail), self::safeToken($opts['token'] ?? null)
            );
        }

        // Hover targets across the full plot height, one per sample.
        foreach ($all as $i => $point) {
            $tip = $this->text((string) $point['label']).' - '.$this->text($format($point['value'], 2, $scale['max']));
            $band = $plot_w / max(1, count($all));
            $svg[] = sprintf(
                '<g class="sr-mark" data-tip="%s"><title>%s</title><rect x="%s" y="%s" width="%s" height="%s" fill="transparent"/></g>',
                $tip, $tip, round($x_at($i) - $band / 2, 1), $pad['t'], round($band, 1), $plot_h
            );
        }

        // Only the end point is marked and labelled - a dot on every sample is noise.
        $end_index = count($all) - 1;
        $end_value = (float) $all[$end_index]['value'];
        $svg[] = sprintf(
            '<circle class="sr-end-dot" cx="%s" cy="%s" r="4.5" style="fill:var(%s)"/>',
            round($x_at($end_index), 1), round($y_at($end_value), 1), self::safeToken($opts['token'] ?? null)
        );

        $svg[] = $this->xAxis(array_map(static fn($p): string => (string) $p['label'], $all), $pad, $plot_w, $plot_h, false);
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * Tiny inline trend line for a table row. No axes, no labels - the row's
     * own numbers carry the values.
     */
    public function sparkline(array $values, string $token = '--sr-s1'): string {
        $token = self::safeToken($token);
        $values = array_values(array_filter($values, static fn($v): bool => $v !== null));
        if (count($values) < 2) {
            return '';
        }

        $width = 96;
        $height = 24;
        $min = min($values);
        $max = max($values);
        $span = $max - $min;
        if ($span <= 0) {
            $span = 1.0;
            $min -= 0.5;
        }

        // One point per horizontal pixel is the most the shape can show; beyond
        // that it is kilobytes of sub-pixel path data per table row. Keep the
        // extremes of each bucket so peaks and troughs survive.
        $max_points = $width - 4;
        if (count($values) > $max_points) {
            $bucket = count($values) / $max_points;
            $reduced = [];
            for ($i = 0; $i < $max_points; $i++) {
                $slice = array_slice($values, (int) floor($i * $bucket), max(1, (int) ceil($bucket)));
                if ($slice === []) {
                    continue;
                }
                $reduced[] = $i % 2 === 0 ? min($slice) : max($slice);
            }
            $values = $reduced;
        }

        $count = count($values) - 1;
        if ($count < 1) {
            return '';
        }

        $path = [];
        foreach ($values as $i => $value) {
            $x = ($i / $count) * ($width - 4) + 2;
            $y = $height - 3 - ((($value - $min) / $span) * ($height - 6));
            $path[] = ($i === 0 ? 'M' : 'L').round($x, 1).' '.round($y, 1);
        }

        return sprintf(
            '<svg class="sr-sparkline" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-hidden="true" focusable="false">'
                .'<path d="%s" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="stroke:var(%s)"/>'
                .'</svg>',
            $width, $height, $width, $height, implode(' ', $path), $token
        );
    }

    /**
     * Capacity meter. The fill carries severity; the track is a lighter step of
     * the same ramp so the state reads across the whole bar.
     */
    public function meter(?float $pct, array $opts = []): string {
        $pct = $pct === null ? 0.0 : max(0.0, min(100.0, $pct));
        $token = self::safeToken($opts['token'] ?? null);

        return sprintf(
            '<span class="sr-meter" role="img" aria-label="%s"><span class="sr-meter-fill" style="width:%s%%;background:var(%s)"></span></span>',
            $this->text((string) ($opts['label'] ?? (round($pct, 1).'%'))),
            round($pct, 2),
            $token
        );
    }

    /**
     * Legend. Always rendered for two or more series: identity must never
     * depend on colour matching alone.
     *
     * @param array $items [['label' => string, 'token' => string, 'text' => string], ...]
     */
    public function legend(array $items): string {
        if (count($items) < 2) {
            return '';
        }

        $out = ['<div class="sr-legend">'];
        foreach ($items as $item) {
            $out[] = sprintf(
                '<span class="sr-legend-item"><span class="sr-legend-swatch" style="background:var(%s)"></span>'
                    .'<span class="sr-legend-label">%s</span>%s</span>',
                self::safeToken($item['token'] ?? null),
                $this->text((string) $item['label']),
                isset($item['text']) && $item['text'] !== ''
                    ? '<span class="sr-legend-value">'.$this->text((string) $item['text']).'</span>'
                    : ''
            );
        }
        $out[] = '</div>';

        return implode('', $out);
    }

    // ---------------------------------------------------------------- internals

    private function stackedColumns(array $categories, array $series, array $opts): string {
        $width = 920;
        $height = (int) ($opts['height'] ?? 320);
        $pad = ['t' => 16, 'r' => 16, 'b' => 46, 'l' => 74];
        $plot_w = $width - $pad['l'] - $pad['r'];
        $plot_h = $height - $pad['t'] - $pad['b'];
        $format = $opts['format'] ?? static fn($v): string => (string) round((float) $v, 2);

        $totals = $this->columnTotals($categories, $series);
        $scale = $this->niceScale(max($totals) ?: 0.0, (float) ($opts['scale_base'] ?? 10.0));

        $band = $plot_w / max(1, count($categories));
        $bar_w = (int) min(self::BAR_MAX_WIDTH, max(3, $band - self::SURFACE_GAP * 2));

        $svg = [];
        $svg[] = $this->openSvg($width, $height, $opts['title'] ?? '');
        $svg[] = $this->yAxis($scale, $pad, $plot_w, $plot_h, $format);

        foreach ($categories as $i => $category) {
            $x = $pad['l'] + $band * $i + ($band - $bar_w) / 2;
            $y = $pad['t'] + $plot_h;

            // Bottom-up, so the rounded cap lands on the topmost visible segment.
            $visible = [];
            foreach ($series as $s_index => $s) {
                $value = (float) ($s['values'][$i] ?? 0.0);
                if ($value > 0) {
                    $visible[] = $s_index;
                }
            }
            $top_index = $visible === [] ? null : end($visible);

            foreach ($series as $s_index => $s) {
                $value = (float) ($s['values'][$i] ?? 0.0);
                if ($value <= 0) {
                    continue;
                }

                $seg_h = $scale['max'] > 0 ? ($value / $scale['max']) * $plot_h : 0.0;
                // The 2px surface gap is what separates touching segments.
                $draw_h = max(1.0, $seg_h - ($s_index === $top_index ? 0 : self::SURFACE_GAP));
                $y -= $seg_h;

                $tip = sprintf(
                    '%s - %s: %s',
                    $this->text((string) $category),
                    $this->text((string) $s['label']),
                    $this->text($format($value, 2, $scale['max']))
                );

                $svg[] = sprintf(
                    '<g class="sr-mark" data-tip="%s"><title>%s</title><path d="%s" style="fill:var(%s)"/></g>',
                    $tip,
                    $tip,
                    $this->roundedRectPath(
                        (int) round($x), (int) round($y), $bar_w, (int) round($draw_h), 4,
                        $s_index === $top_index ? 'top' : 'none'
                    ),
                    self::safeToken($s['token'] ?? null)
                );
            }
        }

        $svg[] = $this->xAxis(array_map('strval', $categories), $pad, $plot_w, $plot_h);
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    private function stackedArea(array $categories, array $series, array $opts): string {
        $width = 920;
        $height = (int) ($opts['height'] ?? 320);
        $pad = ['t' => 16, 'r' => 16, 'b' => 46, 'l' => 74];
        $plot_w = $width - $pad['l'] - $pad['r'];
        $plot_h = $height - $pad['t'] - $pad['b'];
        $format = $opts['format'] ?? static fn($v): string => (string) round((float) $v, 2);

        $totals = $this->columnTotals($categories, $series);
        $scale = $this->niceScale(max($totals) ?: 0.0, (float) ($opts['scale_base'] ?? 10.0));

        $count = max(1, count($categories) - 1);
        $x_at = static fn(int $i): float => $pad['l'] + ($i / $count) * $plot_w;
        $y_at = fn(float $v): float => $pad['t'] + $plot_h - ($scale['max'] > 0 ? ($v / $scale['max']) * $plot_h : 0.0);

        $svg = [];
        $svg[] = $this->openSvg($width, $height, $opts['title'] ?? '');
        $svg[] = $this->yAxis($scale, $pad, $plot_w, $plot_h, $format);

        $baseline = array_fill(0, count($categories), 0.0);
        foreach ($series as $s) {
            $upper = [];
            foreach ($categories as $i => $_c) {
                $upper[$i] = $baseline[$i] + max(0.0, (float) ($s['values'][$i] ?? 0.0));
            }

            $path = [];
            foreach ($upper as $i => $value) {
                $path[] = ($i === 0 ? 'M' : 'L').round($x_at($i), 1).' '.round($y_at($value), 1);
            }
            for ($i = count($categories) - 1; $i >= 0; $i--) {
                $path[] = 'L'.round($x_at($i), 1).' '.round($y_at($baseline[$i]), 1);
            }
            $path[] = 'Z';

            $svg[] = sprintf(
                '<path class="sr-stack-area" d="%s" style="fill:var(%s)"><title>%s</title></path>',
                implode(' ', $path),
                self::safeToken($s['token'] ?? null),
                $this->text((string) $s['label'])
            );

            $baseline = $upper;
        }

        foreach ($categories as $i => $category) {
            $tip = $this->text((string) $category).' - '.$this->text($format($totals[$i], 2, $scale['max']));
            $bandw = $plot_w / max(1, count($categories));
            $svg[] = sprintf(
                '<g class="sr-mark" data-tip="%s"><title>%s</title><rect x="%s" y="%s" width="%s" height="%s" fill="transparent"/></g>',
                $tip, $tip, round($x_at($i) - $bandw / 2, 1), $pad['t'], round($bandw, 1), $plot_h
            );
        }

        $svg[] = $this->xAxis(array_map('strval', $categories), $pad, $plot_w, $plot_h, false);
        $svg[] = '</svg>';

        return implode('', $svg);
    }

    /**
     * @return array<int,float>
     */
    private function columnTotals(array $categories, array $series): array {
        $totals = [];
        foreach ($categories as $i => $_c) {
            $sum = 0.0;
            foreach ($series as $s) {
                $sum += max(0.0, (float) ($s['values'][$i] ?? 0.0));
            }
            $totals[$i] = $sum;
        }

        return $totals;
    }

    private function yAxis(array $scale, array $pad, float $plot_w, float $plot_h, callable $format): string {
        $out = [];
        $decimals = (int) ($scale['decimals'] ?? 0);

        foreach ($scale['ticks'] as $tick) {
            $y = $pad['t'] + $plot_h - ($scale['max'] > 0 ? ($tick / $scale['max']) * $plot_h : 0.0);
            $out[] = sprintf(
                '<line class="%s" x1="%s" y1="%s" x2="%s" y2="%s"/>',
                $tick > 0 ? 'sr-grid' : 'sr-baseline',
                $pad['l'], round($y, 1), $pad['l'] + $plot_w, round($y, 1)
            );
            $out[] = sprintf(
                '<text class="sr-tick" x="%s" y="%s" text-anchor="end">%s</text>',
                $pad['l'] - 10, round($y + 4, 1), $this->text($format($tick, $decimals, $scale['max']))
            );
        }

        return implode('', $out);
    }

    /**
     * @param bool $banded true when marks occupy bands (columns), false when
     *                     they sit on points (line/area). The two use different
     *                     x mappings, and a label under the wrong one is a
     *                     mislabelled chart, not a cosmetic offset.
     */
    private function xAxis(array $labels, array $pad, float $plot_w, float $plot_h, bool $banded = true): string {
        $count = count($labels);
        if ($count === 0) {
            return '';
        }

        // Roughly 62px per label before ticks start colliding.
        $step = max(1, (int) ceil($count / max(1, (int) floor($plot_w / 62))));
        $band = $plot_w / max(1, $count);
        $out = [];

        foreach ($labels as $i => $label) {
            if ($i % $step !== 0 && $i !== $count - 1) {
                continue;
            }
            // Skip a last tick that would collide with the previous kept one.
            if ($i === $count - 1 && $count > 1 && ($count - 1) % $step !== 0 && ($count - 1) - (int) (($count - 1) / $step) * $step < $step / 2) {
                continue;
            }

            $x = $banded
                ? $pad['l'] + $band * $i + $band / 2
                : $pad['l'] + ($count > 1 ? ($i / ($count - 1)) * $plot_w : $plot_w / 2);

            $out[] = sprintf(
                '<text class="sr-tick" x="%s" y="%s" text-anchor="middle">%s</text>',
                round($x, 1), round($pad['t'] + $plot_h + 20, 1), $this->text($this->shortDate($label))
            );
        }

        return implode('', $out);
    }

    /**
     * Five axis ticks on clean numbers.
     *
     * The rounding happens in the unit the labels will actually be printed in.
     * A byte axis whose values render as TiB has to be rounded in TiB (base
     * 1024), otherwise a "clean" number of bytes such as 1.25e12 prints as an
     * untidy 1.14 TiB and consecutive ticks can even round to the same label.
     *
     * @param float $base 1024 for byte/GB axes, 10 for plain counts.
     * @return array{max:float,ticks:array<int,float>,decimals:int}
     */
    private function niceScale(float $max, float $base = 10.0): array {
        if ($max <= 0) {
            return ['max' => 1.0, 'ticks' => [0.0, 0.25, 0.5, 0.75, 1.0], 'decimals' => 2];
        }

        // The unit the formatter will switch to (KiB, MiB, GiB, ...).
        $unit = 1.0;
        if ($base > 1.0) {
            $ceiling = $base ** 5;
            while ($max / $unit >= $base && $unit < $ceiling) {
                $unit *= $base;
            }
        }

        $step_in_unit = ($max / $unit) / 4.0;
        $magnitude = 10 ** floor(log10($step_in_unit));
        $normalized = $step_in_unit / $magnitude;

        $nice = 10.0;
        foreach ([1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0] as $candidate) {
            if ($normalized <= $candidate) {
                $nice = $candidate;
                break;
            }
        }

        $step_unit_value = $nice * $magnitude;
        $step = $step_unit_value * $unit;

        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $ticks[] = $step * $i;
        }

        // Decimals must describe the unit the LABELS will print in, which is
        // chosen from the axis maximum - and step*4 can cross the next 1024
        // boundary the data max did not. Recompute the unit from the top,
        // or "0.3 GiB steps" get rendered as identical "0 TiB" ticks.
        $top = $step * 4;
        $label_unit = 1.0;
        if ($base > 1.0) {
            $ceiling = $base ** 5;
            while ($top / $label_unit >= $base && $label_unit < $ceiling) {
                $label_unit *= $base;
            }
        }

        $step_in_label_unit = $step / $label_unit;
        $decimals = 0;
        $probe = $step_in_label_unit;
        while ($decimals < 2 && abs($probe - round($probe)) > 0.0001) {
            $probe *= 10;
            $decimals++;
        }

        return ['max' => $top, 'ticks' => $ticks, 'decimals' => $decimals];
    }

    /**
     * Rectangle path with rounded corners on one end only, square at the
     * baseline, per the mark spec.
     */
    private function roundedRectPath(int $x, int $y, int $w, int $h, int $r, string $round): string {
        $r = (int) min($r, (int) floor($w / 2), (int) floor($h / 2));

        if ($r <= 0 || $round === 'none') {
            return sprintf('M%d %d h%d v%d h%d Z', $x, $y, $w, $h, -$w);
        }

        if ($round === 'top') {
            // Square at the baseline, 4px rounded at the data end.
            return sprintf(
                'M%1$d %2$d L%1$d %3$d A%4$d %4$d 0 0 1 %5$d %6$d L%7$d %6$d A%4$d %4$d 0 0 1 %8$d %3$d L%8$d %2$d Z',
                $x, $y + $h, $y + $r, $r, $x + $r, $y, $x + $w - $r, $x + $w
            );
        }

        // round === 'right'
        return sprintf(
            'M%1$d %2$d L%3$d %2$d A%4$d %4$d 0 0 1 %5$d %6$d L%5$d %7$d A%4$d %4$d 0 0 1 %3$d %8$d L%1$d %8$d Z',
            $x, $y, $x + $w - $r, $r, $x + $w, $y + $r, $y + $h - $r, $y + $h
        );
    }

    private function arcPath(float $cx, float $cy, float $r_inner, float $r_outer, float $start_deg, float $end_deg): string {
        $to_rad = static fn(float $d): float => $d * M_PI / 180.0;
        $large = ($end_deg - $start_deg) > 180 ? 1 : 0;

        $x1 = $cx + $r_outer * cos($to_rad($start_deg));
        $y1 = $cy + $r_outer * sin($to_rad($start_deg));
        $x2 = $cx + $r_outer * cos($to_rad($end_deg));
        $y2 = $cy + $r_outer * sin($to_rad($end_deg));
        $x3 = $cx + $r_inner * cos($to_rad($end_deg));
        $y3 = $cy + $r_inner * sin($to_rad($end_deg));
        $x4 = $cx + $r_inner * cos($to_rad($start_deg));
        $y4 = $cy + $r_inner * sin($to_rad($start_deg));

        return sprintf(
            'M%s %s A%s %s 0 %d 1 %s %s L%s %s A%s %s 0 %d 0 %s %s Z',
            round($x1, 2), round($y1, 2), $r_outer, $r_outer, $large, round($x2, 2), round($y2, 2),
            round($x3, 2), round($y3, 2), $r_inner, $r_inner, $large, round($x4, 2), round($y4, 2)
        );
    }

    private function openSvg(int $width, int $height, string $title): string {
        return sprintf(
            '<svg class="sr-chart" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" role="img" aria-label="%s" focusable="false">',
            $width, $height, $this->text($title)
        );
    }

    private function emptyChart(string $message): string {
        return '<p class="sr-chart-empty">'.$this->text($message).'</p>';
    }

    /** Render 2026-08-11 as 11 Aug; leave anything else alone. */
    private function shortDate(string $label): string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $label, $m) === 1) {
            return date('j M', mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
        }

        return $label;
    }

    private function truncate(string $value, int $max): string {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }

    private function text(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
