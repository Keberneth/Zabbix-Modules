<?php

declare(strict_types=1);

$root = (new CDiv(_('Loading capacity planning report…')))
	->setId('capacity-planning-root')
	->addClass('capacity-planning-root')
	->setAttribute('data-data-url', $data['data_url'])
	->setAttribute('data-initial-lookback', (string) $data['lookback'])
	->setAttribute('data-initial-tab', (string) $data['tab']);

(new CHtmlPage())
	->setTitle($data['page_title'])
	->addItem($root)
	->show();
