<?php declare(strict_types = 0);

namespace Modules\Healthcheck;

use APP,
    CMenu,
    CMenuItem,
    Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(
                _('Problems'),
                (new CMenuItem(_('Healthcheck')))->setSubMenu(
                    new CMenu([
                        (new CMenuItem(_('Heartbeat')))->setAction('healthcheck.heartbeat'),
                        (new CMenuItem(_('History')))->setAction('healthcheck.history'),
                        (new CMenuItem(_('Settings')))->setAction('healthcheck.settings')
                    ])
                )
            );
    }
}
