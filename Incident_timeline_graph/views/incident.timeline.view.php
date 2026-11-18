<?php

declare(strict_types=1);

$root = (new CDiv(_('Loading incident timeline…')))
	->setId('incident-timeline-root')
	->addClass('incident-timeline-root')
	->setAttribute('data-module-version', '1.4.0')
	->setAttribute('data-data-url', $data['data_url'])
	->setAttribute('data-initial-month', (string) $data['month'])
	->setAttribute('data-initial-from', (string) $data['from'])
	->setAttribute('data-initial-to', (string) $data['to'])
	->setAttribute('data-initial-bucket', (string) $data['bucket']);

(new CHtmlPage())
	->setTitle($data['page_title'])
	->addItem((new CDiv([
		(new CTag('p', true, _('Infrastructure insights')))->addClass('zm-eyebrow'),
		(new CTag('p', true, _('Incident timeline')))->addClass('incident-report-title'),
		(new CTag('p', true, _('Explore incident patterns, severity and the triggers that need attention.')))
			->addClass('incident-report-description')
	]))->addClass('incident-report-head'))
	->addItem($root)
	->show();
