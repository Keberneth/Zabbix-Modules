<?php

declare(strict_types=1);

namespace Modules\IncidentTimeline;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

final class Module extends CModule {
	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Reports'))
			->getSubmenu()
			->add(
				(new CMenuItem(_('Incident Timeline')))
					->setAction('incident.timeline.view')
			);
	}
}
