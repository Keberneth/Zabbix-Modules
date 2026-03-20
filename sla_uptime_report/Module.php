<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('SLA & Uptime Report')))
					->setAction('slareport.report.view')
			);
	}
}
