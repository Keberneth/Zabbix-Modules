<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\LogStore,
    Modules\NetBoxSync\Lib\Util;

class LogFetch extends CController {

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
            $log_path = (string) ($config['runner']['log_path'] ?? '');
            $store = new LogStore($log_path);

            $mode = strtolower((string) ($_REQUEST['mode'] ?? 'items'));
            $filters = $this->buildFilters($_REQUEST);

            if ($mode === 'facets') {
                $this->respond([
                    'ok' => true,
                    'facets' => $store->facets($filters)
                ]);
                return;
            }

            $limit = Util::cleanInt($_REQUEST['limit'] ?? 250, 250, 1, 2000);
            $offset = Util::cleanInt($_REQUEST['offset'] ?? 0, 0, 0, 1000000);
            $result = $store->query($filters, $limit, $offset);

            $this->respond([
                'ok' => true,
                'items' => $result['items'],
                'count' => $result['count'],
                'offset' => $result['offset'],
                'limit' => $result['limit'],
                'has_more' => $result['has_more'],
                'facets' => $store->facets($filters)
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
            'type' => (string) ($request['type'] ?? ''),
            'since' => (string) ($request['since'] ?? ''),
            'until' => (string) ($request['until'] ?? ''),
            'q' => (string) ($request['q'] ?? '')
        ];

        foreach (['host', 'target_type', 'target_name', 'sync_id', 'field', 'os', 'disk_name'] as $key) {
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
