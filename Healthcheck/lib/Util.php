<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use Throwable;

class Util {

    public static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function cleanString($value, int $max_length = 0): string {
        $value = trim((string) $value);

        if ($max_length > 0 && mb_strlen($value) > $max_length) {
            $value = mb_substr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanMultiline($value, int $max_length = 0): string {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));

        if ($max_length > 0 && mb_strlen($value) > $max_length) {
            $value = mb_substr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanUrl($value): string {
        return trim((string) $value);
    }

    public static function cleanInt($value, int $default = 0, ?int $min = null, ?int $max = null): int {
        if (!is_numeric($value)) {
            $value = $default;
        }

        $value = (int) $value;

        if ($min !== null && $value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    public static function cleanId($value, string $prefix = 'id'): string {
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '_', trim((string) $value));

        if ($value === '' || $value === null) {
            $value = self::generateId($prefix);
        }

        return $value;
    }

    public static function generateId(string $prefix = 'id'): string {
        try {
            return $prefix.'_'.bin2hex(random_bytes(6));
        }
        catch (Throwable $e) {
            return $prefix.'_'.str_replace('.', '', (string) microtime(true)).'_'.mt_rand(1000, 9999);
        }
    }

    public static function truncate(string $value, int $max_length = 800): string {
        $value = trim($value);

        if (mb_strlen($value) <= $max_length) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max_length - 1)).'…';
    }

    public static function formatDurationMs($value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        $ms = max(0, (int) $value);

        if ($ms < 1000) {
            return $ms.' ms';
        }

        if ($ms < 60000) {
            return round($ms / 1000, 2).' s';
        }

        return round($ms / 60000, 2).' min';
    }

    public static function formatTimestamp($value): string {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return _('Never');
        }

        return date('Y-m-d H:i:s', (int) $value);
    }

    public static function formatAge($value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        $seconds = (int) $value;
        $negative = $seconds < 0;
        $seconds = abs($seconds);

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.'d';
        }

        if ($hours > 0 || $days > 0) {
            $parts[] = $hours.'h';
        }

        if ($minutes > 0 || $hours > 0 || $days > 0) {
            $parts[] = $minutes.'m';
        }

        $parts[] = $seconds.'s';

        return ($negative ? '-' : '').implode(' ', $parts);
    }

    public static function statusLabel(int $status): string {
        switch ($status) {
            case Storage::STATUS_OK:
                return _('OK');

            case Storage::STATUS_SKIP:
                return _('Skipped');

            default:
                return _('Failed');
        }
    }

    public static function statusCssClass(int $status): string {
        switch ($status) {
            case Storage::STATUS_OK:
                return 'hc-badge-ok';

            case Storage::STATUS_SKIP:
                return 'hc-badge-skip';

            default:
                return 'hc-badge-fail';
        }
    }

    public static function decodeJsonArray($value): array {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
