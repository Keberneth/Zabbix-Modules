<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class Filesystem {

    public static function ensureDir(string $path, int $mode = 0750): void {
        $path = Util::cleanPath($path);

        if ($path === '') {
            throw new RuntimeException('Directory path cannot be empty.');
        }

        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create directory: '.$path);
        }
    }

    public static function writeJsonAtomic(string $path, array $data): void {
        $dir = dirname($path);
        self::ensureDir($dir);

        $tmp = $path.'.tmp.'.Util::generateId('json');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON for '.$path);
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary file: '.$tmp);
        }

        @chmod($tmp, 0640);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move temporary file into place: '.$path);
        }
    }

    public static function readJson(string $path): array {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function appendLine(string $path, string $line): void {
        $dir = dirname($path);
        self::ensureDir($dir);

        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            throw new RuntimeException('Could not open file for append: '.$path);
        }

        try {
            if (!@flock($fh, LOCK_EX)) {
                throw new RuntimeException('Could not lock file for append: '.$path);
            }

            if (@fwrite($fh, $line."\n") === false) {
                throw new RuntimeException('Could not write log line: '.$path);
            }
        }
        finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }

        @chmod($path, 0640);
    }

    public static function moveFile(string $from, string $to): void {
        self::ensureDir(dirname($to));

        if (!@rename($from, $to)) {
            if (!@copy($from, $to)) {
                throw new RuntimeException('Could not move file from '.$from.' to '.$to);
            }
            @unlink($from);
        }
    }

    public static function safeGlob(string $pattern): array {
        $matches = glob($pattern);

        return is_array($matches) ? $matches : [];
    }
}
