<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/attach.php
 * \ingroup docbrowser
 * \brief   Adds a picked file to core's Send by email form (the attachment list kept in the session). JSON.
 *
 * POST token, trackid, key, action=attach|unlink
 * attach: the file is copied to the user's mail temp folder (resized if set up) and the copy is attached,
 *         so core's "Remove all", which deletes the files of the list, never touches the original.
 *         If attaching it would take the total over the size limit, it is kept aside instead and sent as a
 *         share link when the email is sent (see ActionsDocBrowser::doActions).
 *         => {mode: attach, index, name, sizetext} or {mode: link, key, name, sizetext}
 * unlink: removes a file kept aside for a link => {ok: 1}
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
dol_include_once('/docbrowser/class/docbrowserservice.class.php');
dol_include_once('/docbrowser/class/actions_docbrowser.class.php');

top_httphead('application/json');

/**
 * Print a JSON error and stop
 *
 * @param  int    $code    HTTP status
 * @param  string $message Message
 * @return void
 */
function docbrowser_fail($code, $message)
{
	http_response_code($code);
	print json_encode(array('error' => $message));
	exit;
}

if (!DocBrowserService::available($user)) {
	docbrowser_fail(403, 'forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	docbrowser_fail(405, 'POST only');
}

$langs->load('docbrowser@docbrowser');
$trackid = GETPOST('trackid', 'aZ09');
$action = GETPOST('action', 'aZ09');
$key = GETPOST('key', 'alphanohtml');
if ($trackid === '') {
	docbrowser_fail(400, 'trackid required');
}

$sessionkey = ActionsDocBrowser::linksSessionKey($trackid);
$pending = !empty($_SESSION[$sessionkey]) ? (array) json_decode($_SESSION[$sessionkey], true) : array();

if ($action === 'unlink') {
	unset($pending[$key]);
	$_SESSION[$sessionkey] = json_encode($pending);
	print json_encode(array('ok' => 1));
	exit;
}

$service = new DocBrowserService($db);
$file = $service->resolve($key, $user);
if (!$file) {
	docbrowser_fail(403, $langs->transnoentities('DocBrowserFileRefused'));
}

$formmail = new FormMail($db);
$formmail->trackid = $trackid;
$attached = $formmail->get_attached_files();
$total = 0;
foreach ($attached['paths'] as $path) {
	$total += (int) @filesize($path);
}

$copy = $service->copyForMail($file, $conf->user->dir_output.'/'.((int) $user->id).'/temp');
if (!$copy) {
	docbrowser_fail(500, $langs->transnoentities('DocBrowserCopyFailed', $file['name']));
}

$limit = DocBrowserService::maxBytes();
if ($limit > 0 && $total + $copy['size'] > $limit) {
	// Too big to attach with the rest: sent as a share link of the original when the email goes out
	dol_delete_file($copy['path'], 0, 1, 1, null, false, 0);
	$pending[$file['key']] = array('name' => $file['name'], 'size' => $file['size']);
	$_SESSION[$sessionkey] = json_encode($pending);
	print json_encode(array('mode' => 'link', 'key' => $file['key'], 'name' => $file['name'], 'sizetext' => dol_print_size($file['size'], 1, 1)));
	exit;
}

$formmail->add_attached_files($copy['path'], $copy['name'], $copy['mime']);
$after = $formmail->get_attached_files();
$index = array_search($copy['path'], $after['paths'], true);
print json_encode(array('mode' => 'attach', 'index' => ($index === false ? -1 : (int) $index), 'name' => $copy['name'], 'sizetext' => dol_print_size($copy['size'], 1, 1)));
$db->close();
