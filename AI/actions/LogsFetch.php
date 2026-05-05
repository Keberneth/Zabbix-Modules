<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util;

class LogsFetch extends CController {

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
        try {
            $config = Config::get();
            $filters = $this->buildFilters($_REQUEST);
            $mode = strtolower((string) ($_REQUEST['mode'] ?? 'items'));

            if ($mode === 'facets') {
                $this->respond([
                    'ok' => true,
                    'facets' => AuditLogger::facets($config, $filters)
                ]);
                return;
            }

            $limit = Util::cleanInt($_REQUEST['limit'] ?? 250, 250, 1, 2000);
            $offset = Util::cleanInt($_REQUEST['offset'] ?? 0, 0, 0, 1000000);
            $result = AuditLogger::query($config, $filters, $limit, $offset);

            $this->respond([
                'ok' => true,
                'items' => $result['items'],
                'count' => $result['count'],
                'offset' => $result['offset'],
                'limit' => $result['limit'],
                'has_more' => $result['has_more'],
                'facets' => AuditLogger::facets($config, $filters)
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 2000)
            ], 500);
        }
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

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }
}
