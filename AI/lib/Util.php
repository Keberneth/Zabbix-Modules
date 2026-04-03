<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

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

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanMultiline($value, int $max_length = 0): string {
        $value = str_replace(["\r\n", "\r"], "\n", trim((string) $value));

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
    }

    public static function cleanUrl($value): string {
        return trim((string) $value);
    }

    public static function cleanPath($value, int $max_length = 1024): string {
        $value = str_replace(["\0"], '', trim((string) $value));
        $value = preg_replace('#/+#', '/', $value);

        if ($max_length > 0 && self::strLen($value) > $max_length) {
            $value = self::subStr($value, 0, $max_length);
        }

        return $value;
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

    public static function cleanFloat($value, float $default = 0.0, ?float $min = null, ?float $max = null): float {
        if (!is_numeric($value)) {
            $value = $default;
        }

        $value = (float) $value;

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

    public static function cleanEnum($value, array $allowed, string $default): string {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function generateId(string $prefix = 'id'): string {
        try {
            return $prefix.'_'.bin2hex(random_bytes(6));
        }
        catch (Throwable $e) {
            return $prefix.'_'.str_replace('.', '', (string) microtime(true)).'_'.mt_rand(1000, 9999);
        }
    }

    public static function normalizeMessages(array $messages, int $max_messages = 12): array {
        $normalized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? '';
            $content = self::cleanMultiline($message['content'] ?? '', 20000);

            if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content
            ];
        }

        if ($max_messages > 0 && count($normalized) > $max_messages) {
            $normalized = array_slice($normalized, -$max_messages);
        }

        return array_values($normalized);
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

    public static function truncate(string $value, int $max_length = 800): string {
        $value = trim($value);

        if (self::strLen($value) <= $max_length) {
            return $value;
        }

        return rtrim(self::subStr($value, 0, $max_length - 1)).'…';
    }

    public static function chunkText(string $text, int $max_length = 1900): array {
        $text = self::cleanMultiline($text);
        $max_length = max(200, $max_length);

        if ($text === '') {
            return [''];
        }

        $chunks = [];
        $buffer = '';
        $paragraphs = preg_split("/\n{2,}/", $text);

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph === '') {
                continue;
            }

            $candidate = ($buffer === '') ? $paragraph : $buffer."\n\n".$paragraph;

            if (self::strLen($candidate) <= $max_length) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            while (self::strLen($paragraph) > $max_length) {
                $slice = self::subStr($paragraph, 0, $max_length);
                $cut_positions = array_filter([
                    self::strrPos($slice, "\n"),
                    self::strrPos($slice, '. '),
                    self::strrPos($slice, '; '),
                    self::strrPos($slice, ', '),
                    self::strrPos($slice, ' ')
                ], static function($candidate) {
                    return $candidate !== false;
                });

                $cut = $cut_positions ? max($cut_positions) : false;

                if ($cut === false || $cut < (int) ($max_length * 0.60)) {
                    $cut = $max_length;
                }

                $chunks[] = trim(self::subStr($paragraph, 0, (int) $cut));
                $paragraph = trim(self::subStr($paragraph, (int) $cut));
            }

            $buffer = $paragraph;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks ?: [''];
    }

    public static function formatTags($tags): string {
        if (!is_array($tags) || $tags === []) {
            return '';
        }

        $lines = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $name = trim((string) ($tag['tag'] ?? $tag['name'] ?? ''));
            $value = trim((string) ($tag['value'] ?? ''));

            if ($name === '') {
                continue;
            }

            $lines[] = ($value !== '') ? ($name.': '.$value) : $name;
        }

        return implode("\n", $lines);
    }

    /**
     * Recursively apply a callback to every string in an array/scalar value.
     */
    public static function mapStrings($value, callable $callback) {
        if (is_string($value)) {
            return $callback($value);
        }

        if (is_array($value)) {
            $mapped = [];
            foreach ($value as $key => $item) {
                $mapped[$key] = self::mapStrings($item, $callback);
            }
            return $mapped;
        }

        return $value;
    }

    /**
     * Truncate nested data for logging without breaking structure.
     */
    public static function truncateMixed($value, int $max_string_length = 2000, int $max_items = 100) {
        if (is_string($value)) {
            return self::truncate($value, $max_string_length);
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if ($count >= $max_items) {
                    $result['__truncated__'] = 'Additional items omitted.';
                    break;
                }
                $result[$key] = self::truncateMixed($item, $max_string_length, $max_items);
                $count++;
            }
            return $result;
        }

        if (is_object($value)) {
            return self::truncateMixed((array) $value, $max_string_length, $max_items);
        }

        return $value;
    }


    private static function strLen(string $value): int {
        return function_exists('mb_strlen') ? (int) mb_strlen($value) : strlen($value);
    }

    private static function subStr(string $value, int $start, ?int $length = null): string {
        if (function_exists('mb_substr')) {
            return $length === null ? (string) mb_substr($value, $start) : (string) mb_substr($value, $start, $length);
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    private static function strrPos(string $haystack, string $needle) {
        return function_exists('mb_strrpos') ? mb_strrpos($haystack, $needle) : strrpos($haystack, $needle);
    }

    public static function sortByLengthDesc(array $values): array {
        usort($values, static function($a, $b) {
            return self::strLen((string) $b) <=> self::strLen((string) $a);
        });

        return $values;
    }
}
