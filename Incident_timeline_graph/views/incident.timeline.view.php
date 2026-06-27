<?php

declare(strict_types=1);

$root = (new CDiv(_('Loading incident timeline…')))
	->setId('incident-timeline-root')
	->addClass('incident-timeline-root')
	->setAttribute('data-data-url', $data['data_url'])
	->setAttribute('data-initial-month', (string) $data['month'])
	->setAttribute('data-initial-from', (string) $data['from'])
	->setAttribute('data-initial-to', (string) $data['to'])
	->setAttribute('data-initial-bucket', (string) $data['bucket']);

(new CHtmlPage())
	->setTitle($data['page_title'])
	->addItem($root)
	->show();
