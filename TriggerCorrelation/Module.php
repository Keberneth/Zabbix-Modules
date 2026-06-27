<?php

declare(strict_types=1);

namespace Modules\TriggerCorrelation;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;
use CWebUser;

class Module extends CModule {
    public function init(): void {
        // Every action in this module requires Super Admin, so only add the menu
        // item for Super Admins — otherwise lower-privilege users would see a
        // link that lands on an "access denied" page.
        if (!$this->userIsSuperAdmin()) {
            return;
        }

        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Problems'),
                (new CMenuItem(_('Trigger Correlation')))->setAction('triggercorrelation')
            );
    }

    private function userIsSuperAdmin(): bool {
        if (!class_exists('CWebUser') || !isset(CWebUser::$data) || !is_array(CWebUser::$data)) {
            return false;
        }

        return (int) (CWebUser::$data['type'] ?? 0) >= USER_TYPE_SUPER_ADMIN;
    }
}
