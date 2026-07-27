<?php

declare(strict_types=1);

$encode_data_attribute = static function (array $value): string {
	$encoded = json_encode(
		$value,
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			| JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	return is_string($encoded) ? $encoded : '{}';
};

$root = (new CDiv(_('Loading capacity planning report…')))
	->setId('capacity-planning-root')
	->addClass('capacity-planning-root')
	->setAttribute('data-data-url', $data['data_url'])
	->setAttribute('data-settings-save-url', $data['settings_save_url'])
	->setAttribute('data-cache-status-url', $data['cache_status_url'])
	->setAttribute('data-cache-settings', $encode_data_attribute($data['cache_settings']))
	->setAttribute('data-cache-status', $encode_data_attribute($data['cache_status']))
	->setAttribute('data-can-manage-settings', $data['can_manage_settings'] ? '1' : '0')
	->setAttribute('data-csrf-name', (string) $data['csrf_name'])
	->setAttribute('data-csrf-token', (string) $data['csrf_token'])
	->setAttribute('data-data-csrf-token', (string) $data['data_csrf_token'])
	->setAttribute('data-initial-lookback', (string) $data['lookback'])
	->setAttribute('data-initial-tab', (string) $data['tab']);

(new CHtmlPage())
	->setTitle($data['page_title'])
	->addItem($root)
	->show();
