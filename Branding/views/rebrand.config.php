<?php

/**
 * Rebrand module - Branding configuration view.
 *
 * @var CView $this
 * @var array $data
 */

$html_page = (new CHtmlPage())->setTitle(_('Branding'));

$form = (new CForm('post'))
	->setName('rebrand-form')
	->setAttribute('action', 'zabbix.php')
	->setAttribute('enctype', 'multipart/form-data')
	->addVar('action', 'rebrand.config.update');

$logos_url = $data['storage_url'];

// --- Login page logo ---

$logo_main_fields = [];

if ($data['logo_main']) {
	$logo_main_fields[] = (new CDiv(
		(new CTag('img', true))
			->setAttribute('src', $logos_url.'/'.$data['logo_main'].'?'.time())
			->setAttribute('style', 'max-height: 50px; max-width: 300px; margin-bottom: 8px; display: block; background: #333; padding: 8px; border-radius: 4px;')
	));
	$logo_main_fields[] = (new CDiv([
		(new CCheckBox('remove_logo_main', '1')),
		' ',
		_('Remove current logo')
	]))->setAttribute('style', 'display: block; margin-bottom: 8px; color: #c00;');
}

$logo_main_fields[] = (new CTag('input', false))
	->setAttribute('type', 'file')
	->setAttribute('name', 'logo_main')
	->setAttribute('accept', '.svg,.png,.jpg,.jpeg,.gif');
$logo_main_fields[] = (new CTag('div', true, _('Recommended: 114 x 30 pixels. Formats: SVG, PNG, JPG, GIF.')))
	->addClass(ZBX_STYLE_GREY)
	->setAttribute('style', 'margin-top: 4px;');

// --- Sidebar logo ---

$logo_sidebar_fields = [];

if ($data['logo_sidebar']) {
	$logo_sidebar_fields[] = (new CDiv(
		(new CTag('img', true))
			->setAttribute('src', $logos_url.'/'.$data['logo_sidebar'].'?'.time())
			->setAttribute('style', 'max-height: 40px; max-width: 200px; margin-bottom: 8px; display: block; background: #333; padding: 8px; border-radius: 4px;')
	));
	$logo_sidebar_fields[] = (new CDiv([
		(new CCheckBox('remove_logo_sidebar', '1')),
		' ',
		_('Remove current logo')
	]))->setAttribute('style', 'display: block; margin-bottom: 8px; color: #c00;');
}

$logo_sidebar_fields[] = (new CTag('input', false))
	->setAttribute('type', 'file')
	->setAttribute('name', 'logo_sidebar')
	->setAttribute('accept', '.svg,.png,.jpg,.jpeg,.gif');
$logo_sidebar_fields[] = (new CTag('div', true, _('Recommended: 91 x 24 pixels. Formats: SVG, PNG, JPG, GIF.')))
	->addClass(ZBX_STYLE_GREY)
	->setAttribute('style', 'margin-top: 4px;');

// --- Compact sidebar icon ---

$logo_compact_fields = [];

if ($data['logo_compact']) {
	$logo_compact_fields[] = (new CDiv(
		(new CTag('img', true))
			->setAttribute('src', $logos_url.'/'.$data['logo_compact'].'?'.time())
			->setAttribute('style', 'max-height: 32px; max-width: 32px; margin-bottom: 8px; display: block; background: #333; padding: 4px; border-radius: 4px;')
	));
	$logo_compact_fields[] = (new CDiv([
		(new CCheckBox('remove_logo_compact', '1')),
		' ',
		_('Remove current logo')
	]))->setAttribute('style', 'display: block; margin-bottom: 8px; color: #c00;');
}

$logo_compact_fields[] = (new CTag('input', false))
	->setAttribute('type', 'file')
	->setAttribute('name', 'logo_compact')
	->setAttribute('accept', '.svg,.png,.jpg,.jpeg,.gif,.ico');
$logo_compact_fields[] = (new CTag('div', true, _('Recommended: 24 x 24 pixels. Formats: SVG, PNG, JPG, GIF, ICO.')))
	->addClass(ZBX_STYLE_GREY)
	->setAttribute('style', 'margin-top: 4px;');

// --- Browser favicon ---

$favicon_fields = [];

if ($data['favicon']) {
	$favicon_fields[] = (new CDiv(
		(new CTag('img', true))
			->setAttribute('src', $logos_url.'/'.$data['favicon'].'?'.time())
			->setAttribute('style', 'max-height: 32px; max-width: 32px; margin-bottom: 8px; display: block; background: #333; padding: 4px; border-radius: 4px;')
	));
	$favicon_fields[] = (new CDiv([
		(new CCheckBox('remove_favicon', '1')),
		' ',
		_('Remove current favicon')
	]))->setAttribute('style', 'display: block; margin-bottom: 8px; color: #c00;');
}

$favicon_fields[] = (new CTag('input', false))
	->setAttribute('type', 'file')
	->setAttribute('name', 'favicon')
	->setAttribute('accept', '.ico,.png,.svg,.gif,.jpg,.jpeg');
$favicon_fields[] = (new CTag('div', true, _('Saved to assets/logos/favicon.ico. Requires a one-time symlink from /usr/share/zabbix/favicon.ico to that file — see the module README. Recommended: 32 x 32 pixels. Formats: ICO, PNG, SVG, GIF, JPG.')))
	->addClass(ZBX_STYLE_GREY)
	->setAttribute('style', 'margin-top: 4px;');

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
		(new CTag('div', true, $data['runtime_error']))
			->setAttribute('style', 'color: #c00; font-weight: bold; padding: 8px; background: #fff3f3; border: 1px solid #fcc; border-radius: 4px;')
	);
}

if ($data['using_legacy_storage']) {
	$form_list->addRow('',
		(new CTag('div', true,
			'Legacy branding files were detected in '.$data['legacy_storage_dir'].'. On the next successful update they will be migrated to '.$data['storage_dir'].'.'
		))
			->setAttribute('style', 'padding: 8px; background: #fffbe6; border: 1px solid #f2d27a; border-radius: 4px;')
	);
}

if (!$data['storage_writable'] || !$data['conf_writable']) {
	$form_list->addRow('',
		(new CTag('div', true,
			'Warning: Logo files are stored in '.$data['storage_dir'].' and branding config in '.$data['brand_conf_file'].'. ' .
			'The PHP process user "'.$data['runtime_user'].'" needs write access to these paths. ' .
			'If SELinux is enforcing, label both paths with httpd_sys_rw_content_t.'
		))
			->setAttribute('style', 'color: #c00; font-weight: bold; padding: 8px; background: #fff3f3; border: 1px solid #fcc; border-radius: 4px;')
	);
}

// --- Tab view ---

$tab_view = (new CTabView())->addTab('rebrand_tab', _('Logo settings'), $form_list);

$tab_view->setFooter(makeFormFooter(
	new CSubmit('update', _('Update'))
));

$form->addItem($tab_view);
$html_page->addItem($form);
$html_page->show();
