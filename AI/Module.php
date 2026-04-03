<?php declare(strict_types = 0);

namespace Modules\AI;

use APP,
    CMenu,
    CMenuItem,
    Zabbix\Core\CModule;

class Module extends CModule {

    public function init(): void {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->insertAfter(_('Problems'),
                (new CMenuItem(_('AI')))->setSubMenu(
                    new CMenu([
                        (new CMenuItem(_('Chat')))->setAction('ai.chat'),
                        (new CMenuItem(_('Settings')))->setAction('ai.settings'),
                        (new CMenuItem(_('Logs')))->setAction('ai.logs')
                    ])
                )
            );
    }
}
