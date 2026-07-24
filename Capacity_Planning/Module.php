<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

final class Module extends CModule {
	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('Capacity Planning')))
					->setAction('capacity.planning.view')
			);
	}
}
