<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modDocBrowser.class.php
 * \ingroup docbrowser
 * \brief   Description and activation file for module Document Browser
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Document Browser (pick files stored in Dolibarr and attach them to emails)
 */
class modDocBrowser extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Official ID from the range reserved by Zach Melo (194281-194309)
		$this->numero = 194283;

		$this->family = "ecm";
		$this->module_position = '50';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Attach files stored in Dolibarr (records, products, Documents, medias) to emails, as attachments or share links";
		$this->version = '0.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'folder-open';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			// formmail: the button on core's Send by email form; globalcard: share links added when that form sends
			'hooks' => array('data' => array('formmail', 'globalcard'), 'entity' => '0'),
		);

		$this->dirs = array();

		$this->config_page_url = array("setup.php@docbrowser");

		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("docbrowser@docbrowser");
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(20, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			0 => array('DOCBROWSER_MAX_ATTACH_MB', 'chaine', '10', 'Above this total, the largest picked files are sent as share links', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		$this->cronjobs = array(
			0 => array(
				'label'         => 'DocBrowserCronExpireShares',
				'jobtype'       => 'method',
				'class'         => '/docbrowser/class/docbrowserservice.class.php',
				'objectname'    => 'DocBrowserService',
				'method'        => 'expireShares',
				'parameters'    => '',
				'comment'       => 'DocBrowserCronExpireSharesHelp',
				'frequency'     => 1,
				'unitfrequency' => 86400,
				'status'        => 1,
				'test'          => 'isModEnabled("docbrowser")',
				'priority'      => 50,
			),
		);

		// Permissions
		$this->rights = array();
		$this->rights_class = 'docbrowser';

		$r = 0;
		$r++;
		$this->rights[$r][0] = 19428301;
		$this->rights[$r][1] = 'Attach files stored in Dolibarr to emails';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'use';

		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string $options Options when enabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/docbrowser/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options when disabling module ('', 'noboxes')
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
