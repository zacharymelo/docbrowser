<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/list.php
 * \ingroup docbrowser
 * \brief   Files the user may attach, for the Document Browser dialog. JSON, read only.
 *
 * GET mode:
 *   linked   socid, contactid, projectid, origin, originid (or trackid of core's email form)
 *            => {records: [{element, id, label, picto, files|null, recent}]}
 *   record   element, id                   => {files: [...]}
 *   products term                          => {products: [{id, ref, label, picto}]}
 *   folder   kind (e = Documents, m = medias), folder => {folder, folders: [...], files: [...]}
 *   search   term                          => {files: [...]}
 * A file is {key, name, size, sizetext, date, mime, icon, record, thumb}. Keys are checked again on attach.
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

dol_include_once('/docbrowser/class/docbrowserservice.class.php');

top_httphead('application/json');

if (!DocBrowserService::available($user)) {
	http_response_code(403);
	print json_encode(array('error' => 'forbidden'));
	exit;
}

// Listing only reads: free the session so the rest of Dolibarr is not blocked while big folders are scanned
session_write_close();

$langs->load('docbrowser@docbrowser');
$service = new DocBrowserService($db);
$mode = GETPOST('mode', 'aZ09');
$out = array();

switch ($mode) {
	case 'linked':
		$context = array(
			'socid' => GETPOSTINT('socid'),
			'contactid' => GETPOSTINT('contactid'),
			'projectid' => GETPOSTINT('projectid'),
			'origin' => GETPOST('origin', 'aZ09'),
			'originid' => GETPOSTINT('originid'),
		);
		$fromtrackid = DocBrowserService::elementFromTrackid(GETPOST('trackid', 'aZ09'));
		if ($fromtrackid && empty($context['origin'])) {
			$context['origin'] = $fromtrackid[0];
			$context['originid'] = $fromtrackid[1];
		}
		$out['records'] = $service->linked($context, $user);
		break;

	case 'record':
		$files = $service->recordFiles(GETPOST('element', 'aZ09'), GETPOSTINT('id'), $user);
		if ($files === null) {
			http_response_code(403);
			$out['error'] = 'forbidden';
		} else {
			$out['files'] = $files;
		}
		break;

	case 'products':
		$out['products'] = $service->productSearch(GETPOST('term', 'alphanohtml'), $user);
		break;

	case 'folder':
		$kind = GETPOST('kind', 'aZ09');
		$result = in_array($kind, array('e', 'm'), true) ? $service->folder($kind, GETPOST('folder', 'restricthtml'), $user) : null;
		if ($result === null) {
			http_response_code(403);
			$out['error'] = 'forbidden';
		} else {
			$out = $result;
		}
		break;

	case 'search':
		$out['files'] = $service->search(GETPOST('term', 'alphanohtml'), $user);
		break;

	default:
		http_response_code(400);
		$out['error'] = 'bad mode';
}

print json_encode($out);
$db->close();
