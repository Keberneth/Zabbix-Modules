<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/**
 * Token-based on-disk storage for generated reports.
 *
 * Reports are generated synchronously from row data, written to a private
 * directory under the security state path, and retrieved through a one-time
 * token bound to the user's server session. Files expire after a TTL and are
 * cleaned up opportunistically.
 *
 * Supported formats: csv, html, json. No external dependencies.
 */
class ReportStore {

    public const ALLOWED_FORMATS = ['csv', 'html', 'json'];
    public const ALLOWED_DOCUMENT_FORMATS = ['md', 'json', 'svg', 'html'];

    private const DEFAULT_TTL_SECONDS = 3600;

    /**
     * Generate a report file from rows and return the download metadata.
     *
     * @param array  $config          Module config.
     * @param string $server_session  Server session identifier (hashed for binding).
     * @param string $report_type     Logical report name (e.g. "unsupported_items").
     * @param string $format          One of ALLOWED_FORMATS.
     * @param array  $columns         Ordered list of column keys for tabular formats.
     * @param array  $headers         Display headers matching $columns.
     * @param array  $rows            Array of associative arrays keyed by column key.
     * @param array  $meta            Optional metadata (title, generated_at, filters).
     *
     * @return array{token: string, filename: string, format: string, url: string, expires_at: int, size: int, row_count: int}
     */
    public static function create(array $config, string $server_session, string $report_type, string $format, array $columns, array $headers, array $rows, array $meta = []): array {
        $server_session = trim($server_session);

        if ($server_session === '') {
            throw new RuntimeException('Server session is required to generate a report.');
        }

        $format = strtolower(trim($format));

        if (!in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new RuntimeException('Unsupported report format: '.$format.'. Allowed: '.implode(', ', self::ALLOWED_FORMATS));
        }

        $report_type = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($report_type)) ?: 'report';
        $title = (string) ($meta['title'] ?? ucfirst(str_replace('_', ' ', $report_type)));
        $generated_at = (int) ($meta['generated_at'] ?? time());

        $content = self::render($format, $title, $columns, $headers, $rows, $meta, $generated_at);

        $token = self::generateToken();
        $filename = $report_type.'_'.date('Ymd_His', $generated_at).'.'.$format;

        $path = self::filePath($config, $token, $format);
        $dir = dirname($path);
        Filesystem::ensureDir($dir);

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write report file.');
        }

        @chmod($path, 0640);

        $ttl = self::ttlSeconds($config);
        $expires_at = time() + $ttl;
        $size = (int) @filesize($path);

        $meta_data = [
            'token' => $token,
            'server_session_hash' => hash('sha256', $server_session),
            'format' => $format,
            'filename' => $filename,
            'report_type' => $report_type,
            'created_at' => time(),
            'expires_at' => $expires_at,
            'size' => $size,
            'row_count' => count($rows)
        ];

        Filesystem::writeJsonAtomic(self::metaPath($config, $token), $meta_data);
        self::cleanup($config);

        return [
            'token' => $token,
            'filename' => $filename,
            'format' => $format,
            'url' => self::downloadUrl($token),
            'expires_at' => $expires_at,
            'size' => $size,
            'row_count' => count($rows)
        ];
    }

    /**
     * Persist a freeform document (e.g. an evidence-bundle Markdown report) and
     * return the download metadata. Unlike create(), the caller supplies the
     * already-rendered $content string.
     */
    public static function createDocument(array $config, string $server_session, string $report_type, string $format, string $content, array $meta = []): array {
        $server_session = trim($server_session);

        if ($server_session === '') {
            throw new RuntimeException('Server session is required to generate a report.');
        }

        $format = strtolower(trim($format));

        if (!in_array($format, self::ALLOWED_DOCUMENT_FORMATS, true)) {
            throw new RuntimeException('Unsupported document format: '.$format.'. Allowed: '.implode(', ', self::ALLOWED_DOCUMENT_FORMATS));
        }

        $report_type = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($report_type)) ?: 'document';
        $generated_at = (int) ($meta['generated_at'] ?? time());

        $token = self::generateToken();
        $filename = $report_type.'_'.date('Ymd_His', $generated_at).'.'.$format;

        $path = self::filePath($config, $token, $format);
        Filesystem::ensureDir(dirname($path));

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write document file.');
        }

        @chmod($path, 0640);

        $ttl = self::ttlSeconds($config);
        $expires_at = time() + $ttl;
        $size = (int) @filesize($path);

        $meta_data = [
            'token' => $token,
            'server_session_hash' => hash('sha256', $server_session),
            'format' => $format,
            'filename' => $filename,
            'report_type' => $report_type,
            'created_at' => time(),
            'expires_at' => $expires_at,
            'size' => $size,
            'row_count' => 0
        ];

        Filesystem::writeJsonAtomic(self::metaPath($config, $token), $meta_data);
        self::cleanup($config);

        return [
            'token' => $token,
            'filename' => $filename,
            'format' => $format,
            'url' => self::downloadUrl($token),
            'expires_at' => $expires_at,
            'size' => $size,
            'row_count' => 0
        ];
    }

    /**
     * Load report metadata for a token, verifying ownership and TTL.
     *
     * @throws RuntimeException when missing, expired, or session mismatch.
     */
    public static function load(array $config, string $server_session, string $token): array {
        $server_session = trim($server_session);
        $token = self::cleanToken($token);

        if ($server_session === '' || $token === '') {
            throw new RuntimeException('Report token is required.');
        }

        $meta = Filesystem::readJson(self::metaPath($config, $token));

        if ($meta === []) {
            throw new RuntimeException('Report not found or already removed.');
        }

        if (($meta['server_session_hash'] ?? '') !== hash('sha256', $server_session)) {
            throw new RuntimeException('Report does not belong to this session.');
        }

        if ((int) ($meta['expires_at'] ?? 0) < time()) {
            self::delete($config, $token);
            throw new RuntimeException('Report has expired.');
        }

        $format = (string) ($meta['format'] ?? '');
        $all_formats = array_unique(array_merge(self::ALLOWED_FORMATS, self::ALLOWED_DOCUMENT_FORMATS));

        if (!in_array($format, $all_formats, true)) {
            throw new RuntimeException('Report has an invalid format.');
        }

        $file_path = self::filePath($config, $token, $format);

        if (!is_file($file_path)) {
            throw new RuntimeException('Report file is missing.');
        }

        $meta['file_path'] = $file_path;

        return $meta;
    }

    public static function delete(array $config, string $token): void {
        $token = self::cleanToken($token);

        if ($token === '') {
            return;
        }

        $meta_path = self::metaPath($config, $token);
        $meta = Filesystem::readJson($meta_path);

        if (is_file($meta_path)) {
            @unlink($meta_path);
        }

        $format = (string) ($meta['format'] ?? '');
        $all_formats = array_unique(array_merge(self::ALLOWED_FORMATS, self::ALLOWED_DOCUMENT_FORMATS));

        if (in_array($format, $all_formats, true)) {
            $file_path = self::filePath($config, $token, $format);
            if (is_file($file_path)) {
                @unlink($file_path);
            }
        }
    }

    public static function cleanup(array $config): void {
        static $cleaned = false;

        if ($cleaned) {
            return;
        }
        $cleaned = true;

        $dir = self::baseDir($config);

        if (!is_dir($dir)) {
            return;
        }

        foreach (Filesystem::safeGlob($dir.'/meta_*.json') as $meta_file) {
            $meta = Filesystem::readJson($meta_file);

            if ($meta === [] || (int) ($meta['expires_at'] ?? 0) < time()) {
                $token = (string) ($meta['token'] ?? '');
                if ($token !== '') {
                    self::delete($config, $token);
                }
                else {
                    @unlink($meta_file);
                }
            }
        }
    }

    public static function contentType(string $format): string {
        switch (strtolower($format)) {
            case 'csv':
                return 'text/csv; charset=UTF-8';
            case 'html':
                return 'text/html; charset=UTF-8';
            case 'json':
                return 'application/json; charset=UTF-8';
            case 'md':
                return 'text/markdown; charset=UTF-8';
            case 'svg':
                return 'image/svg+xml; charset=UTF-8';
        }

        return 'application/octet-stream';
    }

    private static function render(string $format, string $title, array $columns, array $headers, array $rows, array $meta, int $generated_at): string {
        switch ($format) {
            case 'csv':
                return self::renderCsv($columns, $headers, $rows);
            case 'html':
                return self::renderHtml($title, $columns, $headers, $rows, $meta, $generated_at);
            case 'json':
                return self::renderJson($title, $columns, $headers, $rows, $meta, $generated_at);
        }

        throw new RuntimeException('Unsupported format: '.$format);
    }

    private static function renderCsv(array $columns, array $headers, array $rows): string {
        $fh = fopen('php://temp', 'r+');

        if ($fh === false) {
            throw new RuntimeException('Could not open temp stream for CSV.');
        }

        // UTF-8 BOM so Excel opens it correctly.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $line[] = (string) $value;
            }
            fputcsv($fh, $line);
        }

        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        return (string) $content;
    }

    private static function renderHtml(string $title, array $columns, array $headers, array $rows, array $meta, int $generated_at): string {
        $h = static function ($value): string {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $filter_lines = [];
        foreach (($meta['filters'] ?? []) as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $filter_lines[] = '<li><strong>'.$h($k).':</strong> '.$h($v).'</li>';
        }
        $filters_html = $filter_lines ? '<ul class="meta">'.implode('', $filter_lines).'</ul>' : '';

        $thead = '';
        foreach ($headers as $header) {
            $thead .= '<th>'.$h($header).'</th>';
        }

        $tbody = '';
        foreach ($rows as $row) {
            $tbody .= '<tr>';
            foreach ($columns as $col) {
                $tbody .= '<td>'.$h($row[$col] ?? '').'</td>';
            }
            $tbody .= '</tr>';
        }

        if ($tbody === '') {
            $tbody = '<tr><td colspan="'.count($columns).'" class="empty">No rows.</td></tr>';
        }

        $generated_str = date('Y-m-d H:i:s', $generated_at);
        $row_count = count($rows);

        return '<!DOCTYPE html>'."\n"
            .'<html lang="en"><head><meta charset="UTF-8">'
            .'<title>'.$h($title).'</title>'
            .'<style>'
            .'body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:24px;color:#222;}'
            .'h1{font-size:20px;margin:0 0 8px 0;}'
            .'.meta{margin:0 0 16px 0;padding:0;list-style:none;color:#555;font-size:13px;}'
            .'.meta li{display:inline-block;margin-right:16px;}'
            .'table{border-collapse:collapse;width:100%;font-size:13px;}'
            .'th,td{border:1px solid #ddd;padding:6px 10px;text-align:left;vertical-align:top;}'
            .'th{background:#f5f5f5;font-weight:600;}'
            .'tr:nth-child(even) td{background:#fafafa;}'
            .'td.empty{text-align:center;color:#888;padding:24px;}'
            .'.summary{margin-bottom:12px;color:#444;font-size:13px;}'
            .'</style></head><body>'
            .'<h1>'.$h($title).'</h1>'
            .'<div class="summary">Generated '.$h($generated_str).' &middot; '.$h($row_count).' row(s)</div>'
            .$filters_html
            .'<table><thead><tr>'.$thead.'</tr></thead><tbody>'.$tbody.'</tbody></table>'
            .'</body></html>';
    }

    private static function renderJson(string $title, array $columns, array $headers, array $rows, array $meta, int $generated_at): string {
        $payload = [
            'title' => $title,
            'generated_at' => date('c', $generated_at),
            'row_count' => count($rows),
            'columns' => $columns,
            'headers' => $headers,
            'filters' => (array) ($meta['filters'] ?? []),
            'rows' => array_values($rows)
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($encoded === false) {
            throw new RuntimeException('Failed to encode report as JSON.');
        }

        return $encoded;
    }

    private static function ttlSeconds(array $config): int {
        $ttl = (int) ($config['reports']['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
        return max(300, min($ttl, 86400));
    }

    private static function baseDir(array $config): string {
        $configured = trim((string) ($config['reports']['directory'] ?? ''));

        if ($configured !== '') {
            $base = Util::cleanPath($configured);
        }
        else {
            $base = RedactionStore::baseDir($config).'/reports';
        }

        Filesystem::ensureDir($base);
        return $base;
    }

    private static function metaPath(array $config, string $token): string {
        return self::baseDir($config).'/meta_'.self::cleanToken($token).'.json';
    }

    private static function filePath(array $config, string $token, string $format): string {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($format)) ?: 'bin';
        return self::baseDir($config).'/file_'.self::cleanToken($token).'.'.$ext;
    }

    private static function cleanToken(string $token): string {
        return preg_replace('/[^A-Za-z0-9]/', '', $token);
    }

    private static function generateToken(): string {
        try {
            return bin2hex(random_bytes(24));
        }
        catch (\Throwable $e) {
            return bin2hex(hash('sha256', uniqid('', true).mt_rand(), true));
        }
    }

    private static function downloadUrl(string $token): string {
        return 'zabbix.php?action=ai.report.download&token='.urlencode($token);
    }
}
