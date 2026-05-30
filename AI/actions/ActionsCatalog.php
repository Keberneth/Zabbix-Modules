<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\ZabbixActionExecutor;

/**
 * Returns the live AI tool registry (from ZabbixActionExecutor::allTools())
 * as JSON, grouped into read/write with categories and counts.
 *
 * This is the single source of truth for "what can the AI do" — the Settings
 * page and any external tooling can read it instead of hand-maintained lists
 * that drift out of date.
 */
class ActionsCatalog extends CController {

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
        $tools = ZabbixActionExecutor::allTools();

        $reads = [];
        $writes = [];

        foreach ($tools as $name => $def) {
            $entry = [
                'name' => $name,
                'description' => (string) ($def['description'] ?? ''),
                'params' => is_array($def['params'] ?? null) ? $def['params'] : [],
                'category' => (string) ($def['category'] ?? '')
            ];

            if ((string) ($def['rw'] ?? 'read') === 'write') {
                $writes[] = $entry;
            }
            else {
                $reads[] = $entry;
            }
        }

        $this->respond([
            'ok' => true,
            'counts' => [
                'total' => count($tools),
                'read' => count($reads),
                'write' => count($writes)
            ],
            'reads' => $reads,
            'writes' => $writes
        ]);
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
