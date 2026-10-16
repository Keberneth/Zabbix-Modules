<?php

namespace Modules\Rebrand;

use APP;
use CMenuItem;
use CWebUser;
use Modules\Rebrand\Lib\ServerNameOverride;

class Module extends \Zabbix\Core\CModule {

	public function init(): void {
		(new ServerNameOverride())->apply();

		// Every Branding action requires Super Admin, so only Super Admins get the
		// menu item; for anyone else findOrAdd() would create an Administration
		// section whose only entry leads to an access-denied page.
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			return;
		}

		APP::Component()->get('menu.main')
			->findOrAdd(_('Administration'))
				->getSubmenu()
					->add(
						(new CMenuItem(_('Branding')))->setAction('rebrand.config')
					);
	}
}
