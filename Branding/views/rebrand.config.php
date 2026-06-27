<?php

/**
 * Rebrand module - Branding configuration view.
 *
 * @var CView $this
 * @var array $data
 */

$user_theme = getUserTheme(CWebUser::$data);
$is_dark_theme = in_array($user_theme, ['dark-theme', 'hc-dark'], true);

$html_page = (new CHtmlPage())->setTitle(_('Branding'));

$form = (new CForm('post'))
	->setName('rebrand-form')
	->setAttribute('action', 'zabbix.php')
	->setAttribute('enctype', 'multipart/form-data')
	->addVar('action', 'rebrand.config.update')
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('rebrand.config.update')))->removeId());

$logos_url = $data['storage_url'];
$storage_dir = $data['storage_dir'];

/**
 * Build a cache-busting query param from the stored file's mtime so the preview
 * URL only changes when the file actually changes (instead of on every render).
 */
$cache_bust = static function (?string $filename) use ($storage_dir): string {
	if ($filename === null || $filename === '' || $storage_dir === '') {
		return '1';
	}

	$mtime = @filemtime($storage_dir.'/'.$filename);

	return ($mtime !== false) ? (string) $mtime : '1';
};

/**
 * Render an upload row: optional themed preview + remove checkbox, plus the file
 * input and a help note. Keeps size styling inline; colors live in rebrand.css.
 */
$build_logo_fields = static function (
		?string $current_file, string $logo_key, string $remove_name, string $remove_label,
		string $accept, string $help_text, string $preview_style, bool $compact_preview
	) use ($logos_url, $cache_bust): array {
	$fields = [];

	if ($current_file) {
		$img = (new CTag('img', true))
			->setAttribute('src', $logos_url.'/'.$current_file.'?'.$cache_bust($current_file))
			->addClass('rebrand-logo-preview')
			->setAttribute('style', $preview_style);

		if ($compact_preview) {
			$img->addClass('rebrand-logo-preview-compact');
		}

		$fields[] = (new CDiv($img));
		$fields[] = (new CDiv([
			(new CCheckBox($remove_name, '1')),
			' ',
			$remove_label
		]))->addClass('rebrand-remove-label');
	}

	$fields[] = (new CTag('input', false))
		->setAttribute('type', 'file')
		->setAttribute('name', $logo_key)
		->setAttribute('accept', $accept);
	$fields[] = (new CTag('div', true, $help_text))
		->addClass(ZBX_STYLE_GREY)
		->setAttribute('style', 'margin-top: 4px;');

	return $fields;
};

$logo_main_fields = $build_logo_fields(
	$data['logo_main'], 'logo_main', 'remove_logo_main', _('Remove current logo'),
	'.png,.jpg,.jpeg,.gif',
	_('Recommended: 114 x 30 pixels. Formats: PNG, JPG, GIF.'),
	'max-height: 50px; max-width: 300px;', false
);

$logo_sidebar_fields = $build_logo_fields(
	$data['logo_sidebar'], 'logo_sidebar', 'remove_logo_sidebar', _('Remove current logo'),
	'.png,.jpg,.jpeg,.gif',
	_('Recommended: 91 x 24 pixels. Formats: PNG, JPG, GIF.'),
	'max-height: 40px; max-width: 200px;', false
);

$logo_compact_fields = $build_logo_fields(
	$data['logo_compact'], 'logo_compact', 'remove_logo_compact', _('Remove current logo'),
	'.png,.jpg,.jpeg,.gif,.ico',
	_('Recommended: 24 x 24 pixels. Formats: PNG, JPG, GIF, ICO.'),
	'max-height: 32px; max-width: 32px;', true
);

$favicon_fields = $build_logo_fields(
	$data['favicon'], 'favicon', 'remove_favicon', _('Remove current favicon'),
	'.ico,.png,.gif,.jpg,.jpeg',
	_('Saved to assets/logos/favicon.ico. Requires a one-time symlink from /usr/share/zabbix/favicon.ico to that file — see the module README. Recommended: 32 x 32 pixels. Formats: ICO, PNG, GIF, JPG.'),
	'max-height: 32px; max-width: 32px;', true
);

// --- Build form list ---

$form_list = (new CFormList())
	->addRow(_('Login page logo'), $logo_main_fields)
	->addRow(_('Sidebar logo'), $logo_sidebar_fields)
	->addRow(_('Compact sidebar icon'), $logo_compact_fields)
	->addRow(_('Browser favicon'), $favicon_fields)
	->addRow(_('Footer text'),
		(new CTextBox('brand_footer', $data['brand_footer'], false, 128))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', _('e.g. My Company'))
	)
	->addRow(_('Help URL'),
		(new CTextBox('brand_help_url', $data['brand_help_url'], false, 255))
			->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('placeholder', _('e.g. https://example.com/help'))
	);

if ($data['runtime_error']) {
	$form_list->addRow('',
		(new CDiv($data['runtime_error']))->addClass('rebrand-banner')->addClass('rebrand-banner-error')
	);
}

if (!$data['storage_writable'] || !$data['conf_writable']) {
	$form_list->addRow('',
		(new CDiv(
			'Warning: Logo files are stored in '.$data['storage_dir'].' and branding config in '.$data['brand_conf_file'].'. ' .
			'The PHP process user "'.$data['runtime_user'].'" needs write access to these paths. ' .
			'If SELinux is enforcing, label both paths with httpd_sys_rw_content_t.'
		))->addClass('rebrand-banner')->addClass('rebrand-banner-warning')
	);
}

// --- Tab view ---

$tab_view = (new CTabView())->addTab('rebrand_tab', _('Logo settings'), $form_list);

$tab_view->setFooter(makeFormFooter(
	new CSubmit('update', _('Update'))
));

$form->addItem($tab_view);

$page_wrapper = (new CDiv($form))
	->addClass('rebrand-page')
	->setAttribute('data-rebrand-theme', $is_dark_theme ? 'dark' : 'light');

$html_page->addItem($page_wrapper);
$html_page->show();
