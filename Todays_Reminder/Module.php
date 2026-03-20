<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay;

use APP;
use CMenu;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
			->getSubmenu()
			->insertAfter(_('Problems'),
				(new CMenuItem(_('Today\'s Reminder')))->setSubMenu(
					new CMenu([
						(new CMenuItem(_('Overview')))->setAction('motd.view')
					])
				)
			);
	}
}
