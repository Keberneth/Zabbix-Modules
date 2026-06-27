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
        // Read-only JSON data endpoint.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'mode' => 'string',
            'type' => 'string',
            'limit' => 'int32',
            'offset' => 'int32',
            'since' => 'string',
            'until' => 'string',
            'q' => 'string',
            'host' => 'array',
            'os' => 'array',
            'target_type' => 'array',
            'target_name' => 'array',
            'sync_id' => 'array',
            'field' => 'array',
            'disk_name' => 'array',
            'col' => 'array'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->respond([
                'ok' => false,
                'error' => _('Invalid request parameters.')
            ], 400);
        }

        return $ret;
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

            if ($mode === 'counts') {
                $result = $store->counts($filters);

                $this->respond([
                    'ok' => true,
                    'counts' => $result['counts'],
                    'capped' => $result['capped']
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
                'facets' => $result['facets']
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync LogFetch: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => _('An internal error occurred while reading the log. Check the server error log for details.')
            ], 500);
        }
    }

    /**
     * Translate the request into the LogStore filter shape:
     *   - exact[field] => [values]  (multi-select facets, exact match)
     *   - col[field]   => needle    (per-column substring boxes)
     *   - q / since / until / type  (scalars)
     */
    private function buildFilters(array $request): array {
        $filters = [
            'type' => strtolower(trim((string) ($request['type'] ?? ''))),
            'since' => (string) ($request['since'] ?? ''),
            'until' => (string) ($request['until'] ?? ''),
            'q' => trim((string) ($request['q'] ?? '')),
            'exact' => [],
            'col' => []
        ];

        foreach (LogStore::facetFields() as $key) {
            if (!isset($request[$key]) || !is_array($request[$key])) {
                continue;
            }

            $clean = [];
            foreach ($request[$key] as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $clean[] = $item;
                }
            }

            if ($clean !== []) {
                $filters['exact'][$key] = $clean;
            }
        }

        if (isset($request['col']) && is_array($request['col'])) {
            $allowed = array_flip(LogStore::columnFields());
            foreach ($request['col'] as $key => $value) {
                $key = (string) $key;
                if (!isset($allowed[$key]) || !is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($value !== '') {
                    $filters['col'][$key] = $value;
                }
            }
        }

        return $filters;
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => $json
            ]))->disableView()
        );
    }
}
