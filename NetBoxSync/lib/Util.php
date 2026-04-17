<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

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

    public static function cleanEnum($value, array $allowed, string $default): string {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : $default;
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

    public static function slugify(string $value, int $max_length = 80): string {
        $value = trim($value);
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');

        if ($value === '') {
            $value = 'item';
        }

        if ($max_length > 0 && strlen($value) > $max_length) {
            $value = rtrim(substr($value, 0, $max_length), '-');
        }

        return $value;
    }

    public static function truncate(string $value, int $max_length = 800): string {
        $value = trim($value);

        if (self::strLen($value) <= $max_length) {
            return $value;
        }

        return rtrim(self::subStr($value, 0, max(1, $max_length - 1))).'…';
    }

    public static function decodeJson($value, $default = null) {
        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? $default : $decoded;
    }

    public static function getPath($value, string $path, $default = null) {
        if ($path === '') {
            return $value;
        }

        $segments = explode('.', $path);
        $current = $value;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_array($current) && ctype_digit($segment)) {
                $index = (int) $segment;
                if (array_key_exists($index, $current)) {
                    $current = $current[$index];
                    continue;
                }
            }

            return $default;
        }

        return $current;
    }

    public static function setPath(array &$array, string $path, $value): void {
        if ($path === '') {
            return;
        }

        $segments = explode('.', $path);
        $current = &$array;

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }

            if ($index === count($segments) - 1) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    public static function isAbsoluteUrl(string $value): bool {
        return (bool) preg_match('#^https?://#i', $value);
    }

    public static function joinUrl(string $base, string $path): string {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    public static function normalizeNetBoxBaseUrl(string $url): string {
        $url = rtrim(trim($url), '/');

        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';

        if ($path === '/api' || str_ends_with($path, '/api')) {
            return $url;
        }

        return $url.'/api';
    }

    public static function interpolate(string $template, array $vars): string {
        return preg_replace_callback('/\{([A-Za-z0-9_.-]+)\}/', static function(array $matches) use ($vars) {
            $key = $matches[1];
            return array_key_exists($key, $vars) ? (string) $vars[$key] : $matches[0];
        }, $template) ?? $template;
    }

    public static function strLen(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function detectProcessUser(): string {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        foreach (['USER', 'USERNAME', 'LOGNAME'] as $var) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (function_exists('get_current_user')) {
            $value = get_current_user();
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'the PHP-FPM pool user';
    }

    public static function detectOwnerName(string $path): string {
        if (!function_exists('fileowner')) {
            return '';
        }

        $uid = @fileowner($path);
        if ($uid === false) {
            return '';
        }

        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid((int) $uid);
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return '#'.(int) $uid;
    }

    public static function buildDirectoryHint(string $path, string $reason, string $label = 'Path'): string {
        $user = self::detectProcessUser();
        $owner = self::detectOwnerName($path);
        $parent_owner = self::detectOwnerName(dirname($path));

        $msg = $label.' "'.$path.'" '.$reason.' as the PHP process user "'.$user.'".';

        if ($owner !== '' && $owner !== $user) {
            $msg .= ' The directory is owned by "'.$owner.'", but PHP is running as "'.$user.'" —'
                .' on RHEL/Alma/Rocky with Zabbix + nginx the PHP-FPM pool normally runs as "apache", not "nginx".'
                .' Check /etc/php-fpm.d/zabbix.conf for the "user =" and "group =" directives and use that name in the chown/install commands below.';
        }
        elseif ($parent_owner !== '' && $parent_owner !== $user && !is_dir($path)) {
            $msg .= ' Parent "'.dirname($path).'" is owned by "'.$parent_owner.'" and is not writable by "'.$user.'",'
                .' so the module cannot create the subdirectory itself — pre-create it as shown below.';
        }

        $msg .= ' Example: sudo install -d -o '.$user.' -g '.$user.' -m 0770 '.$path
            .' (on SELinux also run: sudo semanage fcontext -a -t httpd_sys_rw_content_t "'.$path.'(/.*)?" && sudo restorecon -Rv '.$path.').';

        return $msg;
    }

    public static function subStr(string $value, int $start, ?int $length = null): string {
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, $start, null, 'UTF-8')
                : mb_substr($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
