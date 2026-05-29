<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util;

class LogsExport extends CController {

    private const MAX_ROWS = 2000;

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $config = Config::get();
        $filters = $this->buildFilters($_REQUEST);
        $format = strtolower((string) ($_REQUEST['format'] ?? 'csv')) === 'json' ? 'json' : 'csv';

        $result = AuditLogger::query($config, $filters, self::MAX_ROWS, 0);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];

        $category = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($filters['category'] ?? 'all'));
        if ($category === '') {
            $category = 'all';
        }
        $filename = 'ai-logs-'.$category.'-'.date('Ymd-His').'.'.$format;

        if ($format === 'json') {
            $body = (string) json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $content_type = 'application/json; charset=UTF-8';
        }
        else {
            $body = $this->toCsv($items);
            $content_type = 'text/csv; charset=UTF-8';
        }

        header('Content-Type: '.$content_type);
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('X-Content-Type-Options: nosniff');

        $this->setResponse(
            (new CControllerResponseData(['main_block' => $body]))->disableView()
        );
    }

    /**
     * Flat one-row-per-entry CSV. Nested data (full reply, redaction mapping
     * details, raw payload) is intentionally omitted here — use the JSON export
     * for the complete record.
     */
    private function toCsv(array $items): string {
        $columns = [
            'ts', 'category', 'status', 'event', 'source', 'tool', 'request_id',
            'message', 'user', 'remote_addr', 'duration_ms', 'provider', 'model',
            'redaction_total', 'redaction_unique'
        ];

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }

        // UTF-8 BOM so spreadsheet apps detect the encoding correctly.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $columns);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $user = $item['user'] ?? null;
            $username = is_array($user)
                ? (string) ($user['username'] ?? $user['userid'] ?? '')
                : (string) ($user ?? '');

            $provider = is_array($item['provider'] ?? null) ? $item['provider'] : [];
            $security = is_array($item['security'] ?? null) ? $item['security'] : [];
            $stats = is_array($security['stats'] ?? null) ? $security['stats'] : [];

            fputcsv($fh, [
                (string) ($item['ts'] ?? ''),
                (string) ($item['category'] ?? ''),
                (string) ($item['status'] ?? ''),
                (string) ($item['event'] ?? ''),
                (string) ($item['source'] ?? ''),
                (string) ($item['tool'] ?? ''),
                (string) ($item['request_id'] ?? ''),
                Util::truncate((string) ($item['message'] ?? ''), 500),
                $username,
                (string) ($item['remote_addr'] ?? ''),
                (string) ($item['duration_ms'] ?? ''),
                (string) ($provider['name'] ?? ''),
                (string) ($provider['model'] ?? ''),
                (string) ($stats['total'] ?? ''),
                (string) ($stats['mapping_count'] ?? '')
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv === false ? '' : $csv;
    }

    private function buildFilters(array $request): array {
        $filters = [
            'category' => (string) ($request['category'] ?? ''),
            'since' => (string) ($request['since'] ?? ''),
            'until' => (string) ($request['until'] ?? ''),
            'q' => (string) ($request['q'] ?? '')
        ];

        foreach (['status', 'source', 'tool', 'event', 'request_id'] as $key) {
            if (!isset($request[$key])) {
                continue;
            }

            $value = $request[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $filters[$key] = $value;
            }
            elseif (is_array($value)) {
                $clean = [];
                foreach ($value as $item) {
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $clean[] = $item;
                    }
                }
                if ($clean !== []) {
                    $filters[$key] = $clean;
                }
            }
        }

        return $filters;
    }
}
