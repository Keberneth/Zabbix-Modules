<?php
declare(strict_types=1);

namespace Modules\NetworkMap\Actions;

require_once __DIR__ . '/../lib/bootstrap.php';

use CController;
use Modules\NetworkMap\Lib\ActionHelperTrait;
use Modules\NetworkMap\Lib\MapBuilder;

final class NetworkMapData extends CController {
    use ActionHelperTrait;

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'force' => 'in 0,1',
            'days'  => 'int32'
        ];

        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    /**
     * Read-only JSON endpoint that returns the cached/rebuilt network map.
     *
     * Validation/user errors surface as HTTP 400 with a meaningful message;
     * unexpected failures are logged server-side and returned as a generic
     * HTTP 500 so DB/API/path internals never leak to the client.
     */
    protected function doAction(): void {
        set_time_limit(300);

        try {
            $force_refresh = (string) $this->getInput('force', '0') === '1';

            $history_hours = null;
            if ($this->hasInput('days')) {
                // Clamp the requested window to a sane 1..90 day range.
                $days = max(1, min(90, (int) $this->getInput('days')));
                $history_hours = $days * 24;
            }

            $builder = new MapBuilder();
            $payload = $builder->getMap($force_refresh, $this->currentUserId(), $history_hours);

            $this->respondJson($payload);
        }
        catch (\InvalidArgumentException $e) {
            $this->respondJsonError($e->getMessage(), 400);
        }
        catch (\Throwable $e) {
            error_log('NetworkMap: ' . $e->getMessage());
            $this->respondJsonError(
                _('An internal error occurred while building the network map.'),
                500
            );
        }
    }
}
