<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_docbrowser.class.php
 * \ingroup docbrowser
 * \brief   Hooks: "Add from Dolibarr…" on core's Send by email form, and share links added when it sends
 */
dol_include_once('/docbrowser/class/docbrowserservice.class.php');

/**
 * Class ActionsDocBrowser
 */
class ActionsDocBrowser
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string */
	public $resprints = '';

	/** @var array<string,mixed> */
	public $results = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Session key of the files kept aside for share links, for one email form
	 *
	 * @param  string $trackid Email form trackid
	 * @return string
	 */
	public static function linksSessionKey($trackid)
	{
		return 'docbrowser_links-'.$trackid;
	}

	/**
	 * Whether this request actually sends the email. The email form posts action=send for every
	 * button (apply template, add/remove attachment...); core only sends without those (actions_sendmails.inc.php).
	 *
	 * @return bool
	 */
	public static function isRealSend()
	{
		return GETPOST('action', 'aZ09') === 'send'
			&& !GETPOST('addfile') && !GETPOST('removedfile') && !GETPOST('removeAll') && !GETPOST('cancel') && !GETPOST('modelselected');
	}

	/**
	 * Whether the email form is (re)building its attachment list: first opening, or a template applied.
	 * Same condition FormMail::get_form() uses to clear attachments.
	 *
	 * @return bool
	 */
	public static function isAttachmentReset()
	{
		return GETPOST('mode', 'alpha') === 'init'
			|| (GETPOST('modelselected') && GETPOST('modelmailselected', 'alpha') && GETPOST('modelmailselected', 'alpha') != '-1');
	}

	/**
	 * Called by FormMail::get_form() before the form is built. The hook cannot add to the form itself,
	 * so it prints the dialog's assets and a small config; docbrowser.js then puts the button next to Attach.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters (trackid, addfileaction, ...)
	 * @param  FormMail            $object      The mail form
	 * @param  string              $action      Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0 so the core form is still rendered
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs, $conf;

		$trackid = isset($parameters['trackid']) ? (string) $parameters['trackid'] : '';
		if (!DocBrowserService::available($user) || $trackid === '' || (int) $object->withfile !== 2 || !empty($object->withfilereadonly)) {
			return 0;
		}

		$key = self::linksSessionKey($trackid);
		if (self::isAttachmentReset()) {
			unset($_SESSION[$key]);
		}
		$pending = array();
		foreach ((!empty($_SESSION[$key]) ? (array) json_decode($_SESSION[$key], true) : array()) as $k => $f) {
			$pending[] = array('key' => $k, 'name' => $f['name'], 'sizetext' => dol_print_size((int) $f['size'], 1, 1));
		}

		$langs->load('docbrowser@docbrowser');
		$config = array(
			'trackid' => $trackid,
			'addfileid' => isset($parameters['addfileaction']) ? (string) $parameters['addfileaction'] : 'addfile',
			'attachurl' => dol_buildpath('/docbrowser/ajax/attach.php', 1),
			'deleteimg' => DOL_URL_ROOT.'/theme/'.$conf->theme.'/img/delete.png',
			'pending' => $pending,
		);
		print self::assetTags();
		print '<script nonce="'.getNonce().'">window.DocBrowserFormMail = '.json_encode($config).';</script>'."\n";
		return 0;
	}

	/**
	 * Before core's card sends its email: turn the files kept aside into share links at the end of the message.
	 * Links are only created here, so opening or filling the form never makes a file public.
	 *
	 * @param  array<string,mixed> $parameters  Hook parameters
	 * @param  CommonObject        $object      Card object
	 * @param  string              $action      Current action
	 * @param  HookManager         $hookmanager Hook manager
	 * @return int                              0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('docbrowser')) {
			return 0;
		}
		$trackid = GETPOST('trackid', 'aZ09');
		if ($trackid === '') {
			return 0;
		}
		$key = self::linksSessionKey($trackid);
		if (GETPOST('removeAll', 'alpha') || GETPOST('cancel', 'alpha')) {
			unset($_SESSION[$key]);
			return 0;
		}
		if ($action !== 'send' || !self::isRealSend() || empty($_SESSION[$key])) {
			return 0;
		}
		$pending = (array) json_decode($_SESSION[$key], true);
		unset($_SESSION[$key]);

		$service = new DocBrowserService($this->db);
		$files = array();
		foreach (array_keys($pending) as $k) {
			$f = $service->resolve((string) $k, $user);
			if ($f) {
				$files[] = $f;
			}
		}
		$links = $service->shareLinks($files, $user);
		$langs->load('docbrowser@docbrowser');
		if (count($links) < count($pending) || $service->errors) {
			setEventMessages($langs->trans('DocBrowserSomeLinksFailed'), $service->errors, 'warnings');
		}
		if (empty($links)) {
			return 0;
		}

		$message = isset($_POST['message']) ? (string) $_POST['message'] : '';
		$html = dol_textishtml($message);
		$block = DocBrowserService::linksBlock($links, $html);
		$_POST['message'] = $message.($html ? '<br>'.$block : "\n\n".$block);
		setEventMessages($langs->trans('DocBrowserSentAsLinks', count($links)), null, 'mesgs');
		return 0;
	}

	/**
	 * Script and stylesheet tags of the dialog, with its translations, printed once per page
	 *
	 * @return string
	 */
	public static function assetTags()
	{
		global $langs;

		static $done = false;
		if ($done) {
			return '';
		}
		$done = true;
		$langs->load('docbrowser@docbrowser');
		$assets = DocBrowserService::assets();
		$keys = array(
			'DocBrowserAddFromDolibarr', 'DocBrowserTitle', 'DocBrowserTabLinked', 'DocBrowserTabProducts', 'DocBrowserTabDocuments',
			'DocBrowserTabSearch', 'DocBrowserDocuments', 'DocBrowserMedias', 'DocBrowserAdd', 'DocBrowserCancel', 'DocBrowserNoFiles',
			'DocBrowserNoLinked', 'DocBrowserSearchProducts', 'DocBrowserSearchFiles', 'DocBrowserSearchHelp', 'DocBrowserLoading',
			'DocBrowserRecent', 'DocBrowserWillLink', 'DocBrowserRemove', 'DocBrowserError', 'DocBrowserTypeMore', 'DocBrowserNoAccess',
			'DocBrowserSelected', 'DocBrowserUp',
		);
		$labels = array();
		foreach ($keys as $k) {
			$labels[$k] = $langs->transnoentitiesnoconv($k, '__N__');	// %s placeholders are filled in by the script
		}
		$config = array(
			'listurl' => dol_buildpath('/docbrowser/ajax/list.php', 1),
			'maxbytes' => DocBrowserService::maxBytes(),
			'labels' => $labels,
		);
		$out = '<link rel="stylesheet" href="'.dol_escape_htmltag($assets['css']).'">'."\n";
		$out .= '<script nonce="'.getNonce().'">window.DocBrowserConfig = '.json_encode($config).';</script>'."\n";
		$out .= '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag($assets['js']).'"></script>'."\n";
		return $out;
	}
}
