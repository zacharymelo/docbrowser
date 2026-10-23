<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup docbrowser
 * \brief   Document Browser setup: size limit, image resizing, share links
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('admin', 'docbrowser@docbrowser'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$error = 0;
	$mb = price2num(GETPOST('DOCBROWSER_MAX_ATTACH_MB', 'alphanohtml'));
	$values = array(
		'DOCBROWSER_MAX_ATTACH_MB' => (string) max(0, (float) $mb),
		'DOCBROWSER_RESIZE_MAX_PX' => (string) max(0, GETPOSTINT('DOCBROWSER_RESIZE_MAX_PX')),
		'DOCBROWSER_SHARE_EXPIRE_DAYS' => (string) max(0, GETPOSTINT('DOCBROWSER_SHARE_EXPIRE_DAYS')),
		'DOCBROWSER_LINKS_TITLE' => trim(GETPOST('DOCBROWSER_LINKS_TITLE', 'alphanohtml')),
	);
	foreach ($values as $name => $value) {
		if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) <= 0) {
			$error++;
		}
	}
	setEventMessages($langs->trans($error ? 'Error' : 'SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('DocBrowserSetup'), '', '', 0, 0, '', '', '', 'mod-docbrowser page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DocBrowserSetup'), $linkback, 'title_setup');
print '<span class="opacitymedium">'.$langs->trans('DocBrowserSetupPage').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td>'.$langs->trans('Description').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DocBrowserMaxAttach').'</td>';
print '<td><input type="text" class="width75 right" name="DOCBROWSER_MAX_ATTACH_MB" value="'.dol_escape_htmltag(getDolGlobalString('DOCBROWSER_MAX_ATTACH_MB', '10')).'"></td>';
print '<td class="opacitymedium">'.$langs->trans('DocBrowserMaxAttachDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DocBrowserResize').'</td>';
print '<td><input type="number" min="0" step="1" class="width75 right" name="DOCBROWSER_RESIZE_MAX_PX" value="'.getDolGlobalInt('DOCBROWSER_RESIZE_MAX_PX').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('DocBrowserResizeDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DocBrowserExpire').'</td>';
print '<td><input type="number" min="0" step="1" class="width75 right" name="DOCBROWSER_SHARE_EXPIRE_DAYS" value="'.getDolGlobalInt('DOCBROWSER_SHARE_EXPIRE_DAYS').'"></td>';
print '<td class="opacitymedium">'.$langs->trans('DocBrowserExpireDesc');
if (getDolGlobalInt('DOCBROWSER_SHARE_EXPIRE_DAYS') > 0 && !isModEnabled('cron')) {
	print '<br>'.img_warning().' '.$langs->trans('WarningModuleNotActive', $langs->transnoentities('Module2300Name'));
}
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DocBrowserLinksTitleSetting').'</td>';
print '<td><input type="text" class="minwidth200" name="DOCBROWSER_LINKS_TITLE" value="'.dol_escape_htmltag(getDolGlobalString('DOCBROWSER_LINKS_TITLE')).'"></td>';
print '<td class="opacitymedium">'.$langs->trans('DocBrowserLinksTitleSettingDesc', $langs->transnoentities('DocBrowserLinksTitle')).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DocBrowserWhere').'</td><td></td>';
print '<td class="opacitymedium">'.$langs->trans('DocBrowserWhereDesc').'</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
$db->close();
