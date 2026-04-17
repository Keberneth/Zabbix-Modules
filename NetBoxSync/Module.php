<?php declare(strict_types = 0);

namespace Modules\NetBoxSync;

use APP,
    CMenu,
    CMenuItem,
    CWebUser,
    Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Problems'),
                (new CMenuItem(_('NetBox Sync')))->setSubMenu(
                    new CMenu([
                        (new CMenuItem(_('Settings')))->setAction('netboxsync.settings'),
                        (new CMenuItem(_('Log')))->setAction('netboxsync.log')
                    ])
                )
            );
    }

    public function getAssets(): array {
        $assets = parent::getAssets();

        if (!$this->userHasModuleAccess()) {
            return $assets;
        }

        return $assets;
    }

    private function userHasModuleAccess(): bool {
        if (!class_exists('CWebUser') || !isset(CWebUser::$data) || !is_array(CWebUser::$data)) {
            return false;
        }

        $user_type = (int) (CWebUser::$data['type'] ?? 0);

        return $user_type >= USER_TYPE_ZABBIX_USER;
    }
}
