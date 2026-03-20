<?php

declare(strict_types=1);

$root = (new CDiv(_('Loading incident timeline…')))
	->setId('incident-timeline-root')
	->addClass('incident-timeline-root')
	->setAttribute('data-data-url', $data['data_url'])
	->setAttribute('data-initial-month', (string) $data['month']);

(new CHtmlPage())
	->setTitle($data['page_title'])
	->addItem($root)
	->show();
