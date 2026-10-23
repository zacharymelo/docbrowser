<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/docbrowserservice.class.php
 * \ingroup docbrowser
 * \brief   Lists files stored in Dolibarr that a user may read, and prepares them for an email
 *
 * A picked file is a key, never a path:
 *   ['r', element, id, filename]  file of a record (third party, project, proposal...)
 *   ['e', folder, filename]       Documents module (ECM) folder
 *   ['m', folder, filename]       medias library
 * resolve() rebuilds the path on the server and applies the same checks as the record's Documents tab
 * (restrictedArea), then core's dol_check_secure_access_document() as a second gate, then the extra
 * check document.php runs for external users. Listing and attaching both go through it.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';

/**
 * Class DocBrowserService
 */
class DocBrowserService
{
	/** Max results of a filename search */
	const SEARCH_LIMIT = 50;

	/** Recent documents of a third party, per type */
	const RECENT_LIMIT = 10;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string Cron output */
	public $output = '';

	/** @var array<string,CommonObject|false> Records fetched, by element:id */
	private $records = array();

	/** @var array<string,bool> Access results, by element:id */
	private $access = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Record types whose files can be picked. Folders, modulepart and access checks are those of each type's
	 * Documents tab (htdocs/<type>/document.php), because core has no element => modulepart map.
	 * restrict: restrictedArea() arguments after $user and the id: features, tableandshare, feature2, dbt_keyfield.
	 *
	 * @return array<string,array{class:string,file:string,module:string,label:string,picto:string,table:string,modulepart:string,restrict:array{0:string,1:string,2:string,3:string}}>
	 */
	public static function types()
	{
		return array(
			'societe' => array('class' => 'Societe', 'file' => '/societe/class/societe.class.php', 'module' => 'societe', 'label' => 'ThirdParty', 'picto' => 'company', 'table' => 'societe', 'modulepart' => 'societe', 'restrict' => array('societe', '&societe', '', 'fk_soc')),
			'contact' => array('class' => 'Contact', 'file' => '/contact/class/contact.class.php', 'module' => 'societe', 'label' => 'Contact', 'picto' => 'contact', 'table' => 'socpeople', 'modulepart' => 'contact', 'restrict' => array('contact', 'socpeople&societe', '', '')),
			'project' => array('class' => 'Project', 'file' => '/projet/class/project.class.php', 'module' => 'project', 'label' => 'Project', 'picto' => 'project', 'table' => 'projet', 'modulepart' => 'project', 'restrict' => array('projet', 'projet&project', '', 'fk_soc')),
			'product' => array('class' => 'Product', 'file' => '/product/class/product.class.php', 'module' => 'product|service', 'label' => 'Product', 'picto' => 'product', 'table' => 'product', 'modulepart' => 'produit', 'restrict' => array('produit|service', 'product&product', '', '')),
			'propal' => array('class' => 'Propal', 'file' => '/comm/propal/class/propal.class.php', 'module' => 'propal', 'label' => 'Proposal', 'picto' => 'propal', 'table' => 'propal', 'modulepart' => 'propal', 'restrict' => array('propal', '', '', 'fk_soc')),
			'commande' => array('class' => 'Commande', 'file' => '/commande/class/commande.class.php', 'module' => 'order', 'label' => 'Order', 'picto' => 'order', 'table' => 'commande', 'modulepart' => 'commande', 'restrict' => array('commande', '', '', 'fk_soc')),
			'facture' => array('class' => 'Facture', 'file' => '/compta/facture/class/facture.class.php', 'module' => 'invoice', 'label' => 'Invoice', 'picto' => 'bill', 'table' => 'facture', 'modulepart' => 'facture', 'restrict' => array('facture', '', '', 'fk_soc')),
			'supplier_proposal' => array('class' => 'SupplierProposal', 'file' => '/supplier_proposal/class/supplier_proposal.class.php', 'module' => 'supplier_proposal', 'label' => 'SupplierProposal', 'picto' => 'supplier_proposal', 'table' => 'supplier_proposal', 'modulepart' => 'supplier_proposal', 'restrict' => array('supplier_proposal', '', '', 'fk_soc')),
			'order_supplier' => array('class' => 'CommandeFournisseur', 'file' => '/fourn/class/fournisseur.commande.class.php', 'module' => 'supplier_order', 'label' => 'SupplierOrder', 'picto' => 'supplier_order', 'table' => 'commande_fournisseur', 'modulepart' => 'commande_fournisseur', 'restrict' => array('fournisseur', 'commande_fournisseur', 'commande', 'fk_soc')),
			'invoice_supplier' => array('class' => 'FactureFournisseur', 'file' => '/fourn/class/fournisseur.facture.class.php', 'module' => 'supplier_invoice', 'label' => 'SupplierInvoice', 'picto' => 'supplier_invoice', 'table' => 'facture_fourn', 'modulepart' => 'facture_fournisseur', 'restrict' => array('fournisseur', 'facture_fourn', 'facture', 'fk_soc')),
			'contrat' => array('class' => 'Contrat', 'file' => '/contrat/class/contrat.class.php', 'module' => 'contract', 'label' => 'Contract', 'picto' => 'contract', 'table' => 'contrat', 'modulepart' => 'contract', 'restrict' => array('contrat', '', '', 'fk_soc')),
			'fichinter' => array('class' => 'Fichinter', 'file' => '/fichinter/class/fichinter.class.php', 'module' => 'intervention', 'label' => 'Intervention', 'picto' => 'intervention', 'table' => 'fichinter', 'modulepart' => 'ficheinter', 'restrict' => array('ficheinter', 'fichinter', '', 'fk_soc')),
			'shipping' => array('class' => 'Expedition', 'file' => '/expedition/class/expedition.class.php', 'module' => 'shipping', 'label' => 'Shipment', 'picto' => 'dolly', 'table' => 'expedition', 'modulepart' => 'expedition', 'restrict' => array('expedition', '', '', 'fk_soc')),
		);
	}

	/**
	 * Canonical element for names used by callers and by core (order => commande, thirdparty => societe...)
	 *
	 * @param  string $element Element or alias
	 * @return string          Element of types(), '' if unsupported
	 */
	public static function normalizeElement($element)
	{
		$aliases = array(
			'thirdparty' => 'societe', 'company' => 'societe', 'socpeople' => 'contact', 'projet' => 'project',
			'service' => 'product', 'produit' => 'product', 'propale' => 'propal', 'order' => 'commande',
			'invoice' => 'facture', 'supplier_order' => 'order_supplier', 'commande_fournisseur' => 'order_supplier',
			'supplier_invoice' => 'invoice_supplier', 'facture_fourn' => 'invoice_supplier', 'contract' => 'contrat',
			'intervention' => 'fichinter', 'ficheinter' => 'fichinter', 'expedition' => 'shipping', 'shipment' => 'shipping',
		);
		$element = strtolower((string) $element);
		$element = $aliases[$element] ?? $element;
		return isset(self::types()[$element]) ? $element : '';
	}

	/**
	 * Element of an email form trackid ('pro12' => array('propal', 12)), as set by core's cards
	 *
	 * @param  string $trackid Trackid
	 * @return array{0:string,1:int}|null
	 */
	public static function elementFromTrackid($trackid)
	{
		$prefixes = array(
			'thi' => 'societe', 'ctc' => 'contact', 'proj' => 'project', 'prod' => 'product', 'pro' => 'propal', 'ord' => 'commande',
			'inv' => 'facture', 'spro' => 'supplier_proposal', 'sord' => 'order_supplier', 'sinv' => 'invoice_supplier',
			'con' => 'contrat', 'int' => 'fichinter', 'shi' => 'shipping',
		);
		if (!preg_match('/^([a-z]+)([0-9]+)$/', (string) $trackid, $reg) || !isset($prefixes[$reg[1]])) {
			return null;
		}
		return array($prefixes[$reg[1]], (int) $reg[2]);
	}

	/**
	 * Whether the module of a type is enabled
	 *
	 * @param  string $element Element of types()
	 * @return bool
	 */
	public static function typeEnabled($element)
	{
		$types = self::types();
		if (!isset($types[$element])) {
			return false;
		}
		foreach (explode('|', $types[$element]['module']) as $module) {
			if (isModEnabled($module)) {
				return true;
			}
		}
		return false;
	}

	/*
	 * Keys
	 */

	/**
	 * @param  array<int,int|string> $key Key parts
	 * @return string                     URL-safe key
	 */
	public static function encodeKey(array $key)
	{
		return rtrim(strtr(base64_encode(json_encode(array_values($key))), '+/', '-_'), '=');
	}

	/**
	 * @param  string                     $key Encoded key
	 * @return array<int,int|string>|null      Key parts, null if malformed
	 */
	public static function decodeKey($key)
	{
		$json = base64_decode(strtr((string) $key, '-_', '+/'), true);
		$parts = is_string($json) ? json_decode($json, true) : null;
		if (!is_array($parts) || empty($parts[0])) {
			return null;
		}
		if ($parts[0] === 'r' && count($parts) === 4) {
			return array('r', (string) $parts[1], (int) $parts[2], (string) $parts[3]);
		}
		if (($parts[0] === 'e' || $parts[0] === 'm') && count($parts) === 3) {
			return array($parts[0], (string) $parts[1], (string) $parts[2]);
		}
		return null;
	}

	/**
	 * A file name with no folder part, not hidden, nothing core's document.php would refuse
	 *
	 * @param  string $name Name
	 * @return bool
	 */
	public static function isPlainName($name)
	{
		$name = (string) $name;
		return $name !== '' && $name[0] !== '.' && strpos($name, '..') === false && !preg_match('#[/\\\\<>|\x00]#', $name);
	}

	/**
	 * A relative folder made of plain names ('' for the root)
	 *
	 * @param  string      $folder Folder
	 * @return string|null         Normalized folder, null if refused
	 */
	public static function cleanFolder($folder)
	{
		$folder = trim((string) $folder, '/');
		if ($folder === '') {
			return '';
		}
		foreach (explode('/', $folder) as $segment) {
			if (!self::isPlainName($segment)) {
				return null;
			}
		}
		return $folder;
	}

	/*
	 * Records and access
	 */

	/**
	 * @param  string                  $element Element of types()
	 * @param  int                     $id      Id
	 * @return CommonObject|null
	 */
	public function fetchRecord($element, $id)
	{
		$cachekey = $element.':'.((int) $id);
		if (!array_key_exists($cachekey, $this->records)) {
			$this->records[$cachekey] = false;
			$types = self::types();
			if ($id > 0 && isset($types[$element]) && self::typeEnabled($element)) {
				require_once DOL_DOCUMENT_ROOT.$types[$element]['file'];
				$classname = $types[$element]['class'];
				$object = new $classname($this->db);
				if ($object->fetch((int) $id) > 0) {
					$this->records[$cachekey] = $object;
				}
			}
		}
		return $this->records[$cachekey] ?: null;
	}

	/**
	 * Whether a user may see a record's documents: the check of its Documents tab
	 *
	 * @param  string       $element Element of types()
	 * @param  CommonObject $object  Record
	 * @param  User         $user    User
	 * @return bool
	 */
	public function canRead($element, $object, User $user)
	{
		$cachekey = $element.':'.((int) $object->id).':'.((int) $user->id);
		if (!isset($this->access[$cachekey])) {
			$r = self::types()[$element]['restrict'];
			$features = $r[0];
			if ($element === 'product') {
				$features = ((int) $object->type === Product::TYPE_SERVICE) ? 'service' : 'produit';
			}
			$this->access[$cachekey] = (restrictedArea($user, $features, $object->id, $r[1], $r[2], $r[3], 'rowid', 0, 1) > 0);
		}
		return $this->access[$cachekey];
	}

	/**
	 * Document folders of a record, as its Documents tab builds them
	 *
	 * @param  string       $element Element of types()
	 * @param  CommonObject $object  Record
	 * @return array<int,array{dir:string,modulepart:string,rel:string}> rel: folder relative to the modulepart root, with trailing slash
	 */
	public function recordDirs($element, $object)
	{
		global $conf;

		$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
		$ref = dol_sanitizeFileName((string) $object->ref);
		$root = function ($modconf) use ($entity) {
			if (!is_object($modconf)) {
				return '';
			}
			if (!empty($modconf->multidir_output[$entity])) {
				return $modconf->multidir_output[$entity];
			}
			return !empty($modconf->dir_output) ? $modconf->dir_output : '';
		};
		$mp = self::types()[$element]['modulepart'];
		$out = array();

		switch ($element) {
			case 'societe':
				$base = $root($conf->societe);
				$rel = ((int) $object->id).'/';
				break;
			case 'contact':
				// The access check adds "contact/" itself
				$base = $root($conf->societe).'/contact';
				$rel = ((int) $object->id).'/';
				break;
			case 'product':
				$isservice = ((int) $object->type === Product::TYPE_SERVICE);
				$modconf = (!$isservice || !isModEnabled('service')) && isModEnabled('product') ? $conf->product : $conf->service;
				$mp = ($modconf === $conf->service) ? 'service' : 'produit';
				$base = $root($modconf);
				$rel = get_exdir(0, 0, 0, 1, $object, 'product').'/';
				if (getDolGlobalString('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
					$old = get_exdir($object->id, 2, 0, 0, $object, 'product').((int) $object->id).'/photos/';
					$out[] = array('dir' => $base.'/'.rtrim($old, '/'), 'modulepart' => $mp, 'rel' => $old);
				}
				break;
			case 'invoice_supplier':
				$base = $root($conf->fournisseur->facture ?? null);
				$rel = get_exdir($object->id, 2, 0, 0, $object, 'invoice_supplier').$ref.'/';
				break;
			case 'order_supplier':
				$base = $root($conf->fournisseur->commande ?? null);
				$rel = $ref.'/';
				break;
			case 'shipping':
				$base = $root($conf->expedition);
				$rel = 'sending/'.$ref.'/';
				break;
			case 'project':
				$base = $root($conf->project);
				$rel = $ref.'/';
				break;
			case 'propal':
				$base = $root($conf->propal);
				$rel = $ref.'/';
				break;
			case 'commande':
				$base = $root($conf->commande);
				$rel = $ref.'/';
				break;
			case 'facture':
				$base = $root($conf->facture);
				$rel = $ref.'/';
				break;
			case 'supplier_proposal':
				$base = $root($conf->supplier_proposal);
				$rel = $ref.'/';
				break;
			case 'contrat':
				$base = $root($conf->contrat);
				$rel = $ref.'/';
				break;
			case 'fichinter':
				$base = $root($conf->ficheinter);
				$rel = $ref.'/';
				break;
			default:
				return array();
		}
		if (empty($base) || $ref === '' && $element !== 'societe' && $element !== 'contact') {
			return $out;
		}
		array_unshift($out, array('dir' => $base.'/'.rtrim($rel, '/'), 'modulepart' => $mp, 'rel' => $rel));
		return $out;
	}

	/**
	 * Label of a record for display ("Proposal PR2401-0003")
	 *
	 * @param  string       $element Element of types()
	 * @param  CommonObject $object  Record
	 * @return string
	 */
	public static function recordLabel($element, $object)
	{
		global $langs;

		$langs->loadLangs(array('companies', 'projects', 'products', 'propal', 'orders', 'bills', 'supplier_proposal', 'contracts', 'interventions', 'sendings'));
		$label = $langs->transnoentitiesnoconv(self::types()[$element]['label']);
		switch ($element) {
			case 'societe':
				return $label.' '.$object->name;
			case 'contact':
				return $label.' '.$object->getFullName($langs);
			case 'project':
			case 'product':
				return $label.' '.$object->ref.' '.(string) ($element === 'project' ? $object->title : $object->label);
			default:
				return $label.' '.$object->ref;
		}
	}

	/**
	 * Resolve a key to a readable file, applying every check. Nothing else gives a path.
	 *
	 * @param  string|array<int,int|string> $key  Encoded key or key parts
	 * @param  User                         $user User
	 * @return array{key:string,path:string,name:string,size:int,mime:string,date:int,record:string,modulepart:string,rel:string,entity:int}|null
	 */
	public function resolve($key, User $user)
	{
		global $conf;

		$parts = is_array($key) ? $key : self::decodeKey($key);
		if (!$parts) {
			return null;
		}
		if ($parts[0] === 'r') {
			list(, $element, $id, $name) = $parts;
			$element = self::normalizeElement($element);
			if ($element === '' || !self::isPlainName($name)) {
				return null;
			}
			$object = $this->fetchRecord($element, $id);
			if (!$object || !$this->canRead($element, $object, $user)) {
				return null;
			}
			$entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
			foreach ($this->recordDirs($element, $object) as $d) {
				$path = $d['dir'].'/'.$name;
				if (is_file($path) && $this->coreAllows($d['modulepart'], $d['rel'].$name, $entity, $path, $user)) {
					return $this->fileInfo(array('r', $element, (int) $object->id, $name), $path, self::recordLabel($element, $object), $d['modulepart'], $d['rel'].$name, $entity);
				}
			}
			return null;
		}

		// Documents module folder or medias library
		list($kind, $folder, $name) = $parts;
		$folder = self::cleanFolder($folder);
		if ($folder === null || !self::isPlainName($name) || !$this->canBrowse($kind, $user)) {
			return null;
		}
		$root = $this->folderRoot($kind);
		$path = $root.'/'.($folder !== '' ? $folder.'/' : '').$name;
		if ($root === '' || !is_file($path) || !self::isInside($path, $root)) {
			return null;
		}
		$modulepart = ($kind === 'e') ? 'ecm' : 'medias';
		$rel = ($folder !== '' ? $folder.'/' : '').$name;
		if (!$this->coreAllows($modulepart, $rel, (int) $conf->entity, $path, $user)) {
			return null;
		}
		global $langs;
		$label = $langs->transnoentitiesnoconv($kind === 'e' ? 'DocBrowserDocuments' : 'DocBrowserMedias').($folder !== '' ? ' / '.$folder : '');
		return $this->fileInfo(array($kind, $folder, $name), $path, $label, $modulepart, $rel, (int) $conf->entity);
	}

	/**
	 * Core's own document access check, as document.php runs it, and the path it gives must be ours
	 *
	 * @param  string $modulepart Modulepart (whitelisted by types(), or ecm / medias)
	 * @param  string $rel        File relative to the modulepart root
	 * @param  int    $entity     Entity
	 * @param  string $path       Path we built
	 * @param  User   $user       User
	 * @return bool
	 */
	private function coreAllows($modulepart, $rel, $entity, $path, User $user)
	{
		$check = dol_check_secure_access_document($modulepart, $rel, $entity, $user, '', 'read');
		if (!is_array($check) || empty($check['accessallowed']) || empty($check['original_file'])) {
			return false;
		}
		if (realpath($check['original_file']) !== realpath($path)) {
			return false;
		}
		// External users: the extra check of document.php
		if ($user->socid > 0 && !empty($check['sqlprotectagainstexternals'])) {
			$resql = $this->db->query($check['sqlprotectagainstexternals']);
			if (!$resql) {
				return false;
			}
			while ($obj = $this->db->fetch_object($resql)) {
				if ((int) $user->socid !== (int) $obj->fk_soc) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @param  array<int,int|string> $parts      Key parts
	 * @param  string                $path       Full path
	 * @param  string                $record     Where the file is, for display
	 * @param  string                $modulepart Modulepart
	 * @param  string                $rel        Path relative to the modulepart root
	 * @param  int                   $entity     Entity
	 * @return array{key:string,path:string,name:string,size:int,mime:string,date:int,record:string,modulepart:string,rel:string,entity:int}
	 */
	private function fileInfo(array $parts, $path, $record, $modulepart, $rel, $entity)
	{
		$name = basename($path);
		return array(
			'key' => self::encodeKey($parts),
			'path' => $path,
			'name' => $name,
			'size' => (int) dol_filesize($path),
			'mime' => dol_mimetype($name),
			'date' => (int) dol_filemtime($path),
			'record' => $record,
			'modulepart' => $modulepart,
			'rel' => $rel,
			'entity' => (int) $entity,
		);
	}

	/**
	 * @param  string $path Path
	 * @param  string $root Root folder
	 * @return bool         True if the real path is inside the root
	 */
	private static function isInside($path, $root)
	{
		$real = realpath($path);
		$realroot = realpath($root);
		return $real !== false && $realroot !== false && strpos($real, rtrim($realroot, '/').'/') === 0;
	}

	/*
	 * Listings: every item goes through resolve()
	 */

	/**
	 * Item for the dialog, without the server path
	 *
	 * @param  array<string,mixed> $f Result of resolve()
	 * @return array<string,mixed>
	 */
	public static function publicItem(array $f)
	{
		$item = array(
			'key' => $f['key'],
			'name' => $f['name'],
			'size' => $f['size'],
			'sizetext' => dol_print_size($f['size'], 1, 1),
			'date' => $f['date'],
			'mime' => $f['mime'],
			'icon' => dol_mimetype($f['name'], '', 4),
			'record' => $f['record'],
			'thumb' => '',
		);
		if (image_format_supported($f['name']) > 0) {
			// Core's small thumbnail when it exists, else the image itself (viewimage.php checks access again)
			$dir = dirname($f['rel']);
			$small = getImageFileNameForSize($f['name'], '_small');
			$thumbpath = dirname($f['path']).'/thumbs/'.$small;
			$file = is_file($thumbpath) ? ($dir !== '.' ? $dir.'/' : '').'thumbs/'.$small : $f['rel'];
			$item['thumb'] = DOL_URL_ROOT.'/viewimage.php?modulepart='.urlencode($f['modulepart']).'&entity='.((int) $f['entity']).'&file='.urlencode($file);
		}
		return $item;
	}

	/**
	 * Files of a record the user may read, newest first
	 *
	 * @param  string $element Element (or alias)
	 * @param  int    $id      Id
	 * @param  User   $user    User
	 * @return array<int,array<string,mixed>>|null Null if the record can't be read
	 */
	public function recordFiles($element, $id, User $user)
	{
		$element = self::normalizeElement($element);
		$object = $element !== '' ? $this->fetchRecord($element, $id) : null;
		if (!$object || !$this->canRead($element, $object, $user)) {
			return null;
		}
		$items = array();
		foreach ($this->recordDirs($element, $object) as $d) {
			foreach ($this->listFolder($d['dir']) as $f) {
				$r = $this->resolve(array('r', $element, (int) $object->id, $f['name']), $user);
				if ($r) {
					$items[$r['key']] = self::publicItem($r);
				}
			}
		}
		return array_values($items);
	}

	/**
	 * Files directly in a folder (no subfolders, no thumbs, no hidden or helper files), newest first
	 *
	 * @param  string $dir Folder
	 * @return array<int,array<string,mixed>> dol_dir_list() entries
	 */
	private function listFolder($dir)
	{
		if (!dol_is_dir($dir)) {
			return array();
		}
		return dol_dir_list($dir, 'files', 0, '', array('^\.', '\.meta$', '_preview.*\.png$', '\.noexe$'), 'date', SORT_DESC, 1);
	}

	/**
	 * Records linked to an email: the record it is sent from, its third party and project, the contact,
	 * and the latest proposals, orders and invoices of the third party (files loaded when opened).
	 *
	 * @param  array{socid?:int,contactid?:int,projectid?:int,origin?:string,originid?:int} $context Context
	 * @param  User                                                                         $user    User
	 * @return array<int,array{element:string,id:int,label:string,picto:string,files:array<int,array<string,mixed>>|null,recent?:bool}>
	 */
	public function linked(array $context, User $user)
	{
		$wanted = array();
		$origin = self::normalizeElement($context['origin'] ?? '');
		$originid = (int) ($context['originid'] ?? 0);
		$socid = (int) ($context['socid'] ?? 0);
		$projectid = (int) ($context['projectid'] ?? 0);

		if ($origin !== '' && $originid > 0 && ($o = $this->fetchRecord($origin, $originid)) && $this->canRead($origin, $o, $user)) {
			$wanted[] = array($origin, $originid);
			// The third party and project of the record, when the form did not name them
			if (!$socid && !empty($o->socid)) {
				$socid = (int) $o->socid;
			}
			if ($origin === 'contact' && !$socid && !empty($o->fk_soc)) {
				$socid = (int) $o->fk_soc;
			}
			if (!$projectid && !empty($o->fk_project)) {
				$projectid = (int) $o->fk_project;
			}
		}
		if (!empty($context['contactid'])) {
			$wanted[] = array('contact', (int) $context['contactid']);
		}
		if ($projectid > 0) {
			$wanted[] = array('project', $projectid);
		}
		if ($socid > 0) {
			$wanted[] = array('societe', $socid);
		}

		$out = array();
		$seen = array();
		foreach ($wanted as $w) {
			list($element, $id) = $w;
			if (isset($seen[$element.':'.$id])) {
				continue;
			}
			$seen[$element.':'.$id] = true;
			$files = $this->recordFiles($element, $id, $user);
			if ($files === null) {
				continue;
			}
			$object = $this->fetchRecord($element, $id);
			$out[] = array('element' => $element, 'id' => $id, 'label' => self::recordLabel($element, $object), 'picto' => self::types()[$element]['picto'], 'files' => $files);
		}

		if ($socid > 0) {
			foreach ($this->recentDocuments($socid, $user) as $r) {
				if (!isset($seen[$r['element'].':'.$r['id']])) {
					$out[] = $r;
				}
			}
		}
		return $out;
	}

	/**
	 * Latest proposals, orders and invoices of a third party the user may read (files not loaded)
	 *
	 * @param  int  $socid Third party
	 * @param  User $user  User
	 * @return array<int,array{element:string,id:int,label:string,picto:string,files:null,recent:bool}>
	 */
	private function recentDocuments($socid, User $user)
	{
		$out = array();
		$entities = array('propal' => 'propal', 'commande' => 'commande', 'facture' => 'invoice');
		foreach ($entities as $element => $entityname) {
			if (!self::typeEnabled($element)) {
				continue;
			}
			$table = self::types()[$element]['table'];
			$sql = "SELECT t.rowid FROM ".MAIN_DB_PREFIX.$table." as t";
			$sql .= " WHERE t.fk_soc = ".((int) $socid)." AND t.entity IN (".getEntity($entityname).")";
			$sql .= " ORDER BY t.datec DESC, t.rowid DESC";
			$sql .= $this->db->plimit(self::RECENT_LIMIT);
			$resql = $this->db->query($sql);
			if (!$resql) {
				continue;
			}
			$ids = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
			foreach ($ids as $id) {
				$object = $this->fetchRecord($element, $id);
				if ($object && $this->canRead($element, $object, $user)) {
					$out[] = array('element' => $element, 'id' => $id, 'label' => self::recordLabel($element, $object), 'picto' => self::types()[$element]['picto'], 'files' => null, 'recent' => true);
				}
			}
		}
		return $out;
	}

	/**
	 * Products and services matching a ref or label, among those the user may read
	 *
	 * @param  string $term Search
	 * @param  User   $user User
	 * @return array<int,array{id:int,ref:string,label:string,picto:string}>
	 */
	public function productSearch($term, User $user)
	{
		$types = array();
		if (isModEnabled('product') && $user->hasRight('produit', 'lire')) {
			$types[] = 0;
		}
		if (isModEnabled('service') && $user->hasRight('service', 'lire')) {
			$types[] = 1;
		}
		$term = trim((string) $term);
		if (empty($types) || dol_strlen($term) < 2) {
			return array();
		}
		$like = "'%".$this->db->escape($this->db->escapeforlike($term))."%'";
		$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type FROM ".MAIN_DB_PREFIX."product as p";
		$sql .= " WHERE p.entity IN (".getEntity('product').")";
		$sql .= " AND p.fk_product_type IN (".$this->db->sanitize(implode(',', $types)).")";
		$sql .= " AND (p.ref LIKE ".$like." OR p.label LIKE ".$like.")";
		$sql .= " ORDER BY p.ref ASC";
		$sql .= $this->db->plimit(20);
		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'label' => (string) $obj->label, 'picto' => ((int) $obj->fk_product_type === 1 ? 'service' : 'product'));
			}
		}
		return $out;
	}

	/**
	 * Whether the user may browse the Documents module (e) or the medias library (m)
	 *
	 * @param  string $kind e or m
	 * @param  User   $user User
	 * @return bool
	 */
	public function canBrowse($kind, User $user)
	{
		if ($kind === 'e') {
			return isModEnabled('ecm') && ($user->hasRight('ecm', 'read') || $user->hasRight('ecm', 'lire'));
		}
		if ($kind === 'm') {
			// As core's medias page (ecm/index_medias.php)
			return (isModEnabled('ecm') && $user->hasRight('ecm', 'read'))
				|| (isModEnabled('mailing') && $user->hasRight('mailing', 'lire'))
				|| (isModEnabled('website') && $user->hasRight('website', 'read'));
		}
		return false;
	}

	/**
	 * @param  string $kind e or m
	 * @return string       Root folder, '' if not set up
	 */
	private function folderRoot($kind)
	{
		global $conf;

		if ($kind === 'e') {
			return !empty($conf->ecm->dir_output) ? $conf->ecm->dir_output : '';
		}
		if (!empty($conf->medias->multidir_output[$conf->entity])) {
			return $conf->medias->multidir_output[$conf->entity];
		}
		return !empty($conf->medias->dir_output) ? $conf->medias->dir_output : DOL_DATA_ROOT.'/medias';
	}

	/**
	 * Subfolders and files of a Documents module or medias folder
	 *
	 * @param  string $kind   e or m
	 * @param  string $folder Relative folder
	 * @param  User   $user   User
	 * @return array{folder:string,folders:string[],files:array<int,array<string,mixed>>}|null Null if refused
	 */
	public function folder($kind, $folder, User $user)
	{
		$folder = self::cleanFolder($folder);
		if ($folder === null || !$this->canBrowse($kind, $user)) {
			return null;
		}
		$root = $this->folderRoot($kind);
		$dir = $root.($folder !== '' ? '/'.$folder : '');
		if ($root === '' || !dol_is_dir($dir) || ($folder !== '' && !self::isInside($dir, $root))) {
			return array('folder' => $folder, 'folders' => array(), 'files' => array());
		}
		$folders = array();
		foreach (dol_dir_list($dir, 'directories', 0, '', array('^\.', '^thumbs$', '^temp$'), 'name', SORT_ASC, 0) as $d) {
			if (self::isPlainName($d['name'])) {
				$folders[] = $d['name'];
			}
		}
		$files = array();
		foreach ($this->listFolder($dir) as $f) {
			$r = $this->resolve(array($kind, $folder, $f['name']), $user);
			if ($r) {
				$files[] = self::publicItem($r);
			}
		}
		return array('folder' => $folder, 'folders' => $folders, 'files' => $files);
	}

	/**
	 * Files found by name in core's file index (llx_ecm_files), among those the user may read.
	 * Files never indexed (put on disk directly, not yet seen on their Documents tab) are not found.
	 *
	 * @param  string $term Part of the file name
	 * @param  User   $user User
	 * @return array<int,array<string,mixed>>
	 */
	public function search($term, User $user)
	{
		global $conf;

		$term = trim((string) $term);
		if (dol_strlen($term) < 2) {
			return array();
		}
		$byobject = array(
			'societe' => 'societe', 'socpeople' => 'contact', 'projet' => 'project', 'product' => 'product', 'propal' => 'propal',
			'commande' => 'commande', 'facture' => 'facture', 'supplier_proposal' => 'supplier_proposal',
			'commande_fournisseur' => 'order_supplier', 'facture_fourn' => 'invoice_supplier', 'contrat' => 'contrat',
			'fichinter' => 'fichinter', 'expedition' => 'shipping',
		);
		$like = "'%".$this->db->escape($this->db->escapeforlike($term))."%'";
		$sql = "SELECT f.filepath, f.filename, f.src_object_type, f.src_object_id FROM ".MAIN_DB_PREFIX."ecm_files as f";
		$sql .= " WHERE f.entity = ".((int) $conf->entity)." AND f.filename LIKE ".$like;
		$sql .= " ORDER BY f.date_c DESC";
		$sql .= $this->db->plimit(400);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}

		$out = array();
		foreach ($rows as $obj) {
			$key = null;
			$segments = explode('/', trim((string) $obj->filepath, '/'));
			if (isset($segments[0]) && is_numeric($segments[0])) {
				array_shift($segments);	// entity folder
			}
			$first = $segments[0] ?? '';
			if ($first === 'ecm' || $first === 'medias') {
				$key = array($first === 'ecm' ? 'e' : 'm', implode('/', array_slice($segments, 1)), (string) $obj->filename);
			} elseif (!empty($obj->src_object_type) && isset($byobject[$obj->src_object_type]) && (int) $obj->src_object_id > 0) {
				$key = array('r', $byobject[$obj->src_object_type], (int) $obj->src_object_id, (string) $obj->filename);
			} else {
				$found = $this->recordFromPath($segments);
				if ($found) {
					$key = array('r', $found[0], $found[1], (string) $obj->filename);
				}
			}
			$r = $key ? $this->resolve($key, $user) : null;
			if ($r && !isset($out[$r['key']])) {
				$out[$r['key']] = self::publicItem($r);
				if (count($out) >= self::SEARCH_LIMIT) {
					break;
				}
			}
		}
		return array_values($out);
	}

	/**
	 * Record a document folder belongs to, for index rows without a source object
	 *
	 * @param  string[]                $segments Folder segments, relative to the data root (entity removed)
	 * @return array{0:string,1:int}|null
	 */
	private function recordFromPath(array $segments)
	{
		$first = $segments[0] ?? '';
		$last = (string) end($segments);
		if ($first === 'societe') {
			if (($segments[1] ?? '') === 'contact' && isset($segments[2]) && ctype_digit($segments[2])) {
				return array('contact', (int) $segments[2]);
			}
			return (isset($segments[1]) && ctype_digit($segments[1])) ? array('societe', (int) $segments[1]) : null;
		}
		$byfolder = array(
			'projet' => 'project', 'project' => 'project', 'produit' => 'product', 'product' => 'product', 'service' => 'product',
			'propale' => 'propal', 'commande' => 'commande', 'facture' => 'facture', 'supplier_proposal' => 'supplier_proposal',
			'contract' => 'contrat', 'contrat' => 'contrat', 'ficheinter' => 'fichinter', 'expedition' => 'shipping',
		);
		if ($first === 'fournisseur') {
			$element = (($segments[1] ?? '') === 'commande') ? 'order_supplier' : ((($segments[1] ?? '') === 'facture') ? 'invoice_supplier' : '');
		} else {
			$element = $byfolder[$first] ?? '';
		}
		if ($element === '' || count($segments) < 2 || !self::typeEnabled($element)) {
			return null;
		}
		$table = self::types()[$element]['table'];
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$table." WHERE ref = '".$this->db->escape($last)."'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array($element, (int) $obj->rowid);
		}
		return null;
	}

	/*
	 * Delivery: copies, size limit, share links
	 */

	/**
	 * Copy a resolved file for an email, resized when setup limits image size
	 *
	 * @param  array<string,mixed> $file   Result of resolve()
	 * @param  string              $tmpdir Folder for the copy (created if needed)
	 * @return array{path:string,name:string,mime:string,size:int,key:string}|null
	 */
	public function copyForMail(array $file, $tmpdir)
	{
		if (dol_mkdir($tmpdir) < 0) {
			$this->errors[] = 'Cannot create '.$tmpdir;
			return null;
		}
		$name = dol_sanitizeFileName($file['name']);
		$dest = $tmpdir.'/'.$name;
		for ($i = 2; file_exists($dest); $i++) {
			$dest = $tmpdir.'/'.pathinfo($name, PATHINFO_FILENAME).'-'.$i.(pathinfo($name, PATHINFO_EXTENSION) !== '' ? '.'.pathinfo($name, PATHINFO_EXTENSION) : '');
		}
		if (!$this->writeResizedCopy($file['path'], $dest) && dol_copy($file['path'], $dest, '0', 1) < 0) {
			$this->errors[] = 'Cannot copy '.$file['name'];
			return null;
		}
		clearstatcache(true, $dest);
		return array('path' => $dest, 'name' => basename($dest), 'mime' => $file['mime'], 'size' => (int) dol_filesize($dest), 'key' => $file['key']);
	}

	/**
	 * Write a smaller copy of an image when setup limits the size and the image is larger.
	 * Only jpg/png/gif/webp this PHP can read; otherwise the caller copies the original.
	 *
	 * @param  string $src  Original file
	 * @param  string $dest Copy to write
	 * @return bool         True if a resized copy was written
	 */
	public function writeResizedCopy($src, $dest)
	{
		$max = getDolGlobalInt('DOCBROWSER_RESIZE_MAX_PX');
		if ($max <= 0) {
			return false;
		}
		$ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
		$gd = array('jpg' => 'imagecreatefromjpeg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif', 'webp' => 'imagecreatefromwebp');
		if (!isset($gd[$ext]) || !function_exists($gd[$ext])) {
			return false;
		}
		$dim = @getimagesize($src);
		if (!$dim || ($dim[0] <= $max && $dim[1] <= $max)) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
		// Fit the longest side to $max, keeping the ratio
		$newwidth = ($dim[0] >= $dim[1]) ? $max : 0;
		$newheight = ($dim[0] >= $dim[1]) ? 0 : $max;
		$result = dol_imageResizeOrCrop($src, 0, $newwidth, $newheight, 0, 0, $dest, 85);
		return ($result === $dest && dol_is_file($dest));
	}

	/**
	 * Size limit for attachments, in bytes (0 = no limit)
	 *
	 * @return int
	 */
	public static function maxBytes()
	{
		$mb = (float) getDolGlobalString('DOCBROWSER_MAX_ATTACH_MB', '10');
		return $mb > 0 ? (int) round($mb * 1024 * 1024) : 0;
	}

	/**
	 * Which picked files to attach and which to send as links: when everything together is over the limit,
	 * the largest picked files become links until the rest fits. Other attachments (uploads) always stay.
	 *
	 * @param  array<int,array<string,mixed>> $picked     Copies (copyForMail), each with 'size'
	 * @param  int                            $otherbytes Size of the other attachments
	 * @return array{attach:array<int,array<string,mixed>>,link:array<int,array<string,mixed>>}
	 */
	public static function split(array $picked, $otherbytes = 0)
	{
		$limit = self::maxBytes();
		$total = (int) $otherbytes;
		foreach ($picked as $p) {
			$total += (int) $p['size'];
		}
		if ($limit <= 0 || $total <= $limit) {
			return array('attach' => array_values($picked), 'link' => array());
		}
		$bysize = $picked;
		uasort($bysize, function ($a, $b) {
			return (int) $b['size'] <=> (int) $a['size'];
		});
		$link = array();
		foreach ($bysize as $i => $p) {
			if ($total <= $limit) {
				break;
			}
			$link[$i] = $p;
			$total -= (int) $p['size'];
		}
		$attach = array_diff_key($picked, $link);
		return array('attach' => array_values($attach), 'link' => array_values($link));
	}

	/**
	 * ECM index record of a file, created if the file was never indexed
	 *
	 * @param  string        $fullpath Absolute file path
	 * @param  bool          $create   Index the file when it has no record
	 * @return EcmFiles|null
	 */
	public function fetchEcmFile($fullpath, $create = false)
	{
		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';

		$relative = preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'[\\/]*/', '', $fullpath);
		$ecm = new EcmFiles($this->db);
		if ($ecm->fetch(0, '', $relative) > 0) {
			return $ecm;
		}
		if (!$create) {
			return null;
		}
		if (addFileIntoDatabaseIndex(dirname($fullpath), basename($fullpath), '', 'uploaded', 0) <= 0) {
			return null;
		}
		$ecm = new EcmFiles($this->db);
		return ($ecm->fetch(0, '', $relative) > 0) ? $ecm : null;
	}

	/**
	 * Public share links for resolved files (originals, never the resized copies), creating share hashes
	 * where missing. Only called when an email is sent, so opening a form never makes a file public.
	 *
	 * @param  array<int,array<string,mixed>> $files Results of resolve()
	 * @param  User                           $user  User
	 * @return array<int,array{name:string,url:string,size:int}>
	 */
	public function shareLinks(array $files, User $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

		$links = array();
		foreach ($files as $f) {
			$ecm = $this->fetchEcmFile($f['path'], true);
			if (!$ecm) {
				$this->errors[] = 'Cannot index '.$f['name'];
				continue;
			}
			if (empty($ecm->share)) {
				$ecm->share = getRandomPassword(true);
				if ($ecm->update($user) < 0) {
					$this->errors[] = $ecm->error;
					continue;
				}
				$this->trackShare($ecm);
			} else {
				// Only links this module created are tracked and expire; a link shared by hand stays as it is
				$this->touchShare($ecm);
			}
			$links[] = array('name' => $f['name'], 'url' => self::publicRoot().'/document.php?hashp='.urlencode($ecm->share), 'size' => (int) $f['size']);
		}
		return $links;
	}

	/**
	 * Record a share hash created by this module, so it can expire
	 *
	 * @param  EcmFiles $ecm File index record
	 * @return void
	 */
	private function trackShare($ecm)
	{
		global $conf;

		$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."docbrowser_share WHERE fk_ecm_files = ".((int) $ecm->id));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."docbrowser_share (entity, fk_ecm_files, share, date_last_sent)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $ecm->id).", '".$this->db->escape($ecm->share)."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
		}
	}

	/**
	 * Push back the expiry of a module-created share that is sent again
	 *
	 * @param  EcmFiles $ecm File index record
	 * @return void
	 */
	private function touchShare($ecm)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."docbrowser_share SET date_last_sent = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE fk_ecm_files = ".((int) $ecm->id)." AND share = '".$this->db->escape($ecm->share)."'";
		$this->db->query($sql);
	}

	/**
	 * Scheduled job: remove share links created by this module that were last sent more than
	 * DOCBROWSER_SHARE_EXPIRE_DAYS days ago. Links shared by hand, or replaced since, are left alone.
	 *
	 * @return int 0 if OK, <0 if KO (cron convention)
	 */
	public function expireShares()
	{
		global $conf, $user;

		$this->output = '';
		$days = getDolGlobalInt('DOCBROWSER_SHARE_EXPIRE_DAYS');
		if ($days <= 0) {
			$this->output = 'Link expiry is off (DOCBROWSER_SHARE_EXPIRE_DAYS = 0)';
			return 0;
		}

		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$limit = dol_now() - $days * 86400;
		$sql = "SELECT rowid, fk_ecm_files, share FROM ".MAIN_DB_PREFIX."docbrowser_share";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND date_last_sent < '".$this->db->idate($limit)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$revoked = 0;
		foreach ($rows as $row) {
			$ecm = new EcmFiles($this->db);
			if ($ecm->fetch((int) $row->fk_ecm_files) > 0 && $ecm->share === $row->share) {
				$ecm->share = '';
				if ($ecm->update($user) < 0) {
					$this->errors[] = $ecm->error;
					continue;
				}
				$revoked++;
			}
			$this->db->query("DELETE FROM ".MAIN_DB_PREFIX."docbrowser_share WHERE rowid = ".((int) $row->rowid));
		}

		$this->output = $revoked.' share link(s) removed (older than '.$days.' days)';
		return $this->errors ? -1 : 0;
	}

	/**
	 * Absolute URL root used in public links
	 *
	 * @return string
	 */
	public static function publicRoot()
	{
		global $dolibarr_main_url_root;

		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		return $urlwithouturlroot.DOL_URL_ROOT;
	}

	/**
	 * Links block for an email body
	 *
	 * @param  array<int,array{name:string,url:string,size:int}> $links Links
	 * @param  bool                                               $html  HTML or plain text
	 * @return string
	 */
	public static function linksBlock(array $links, $html)
	{
		global $langs;

		if (empty($links)) {
			return '';
		}
		$langs->load('docbrowser@docbrowser');
		$title = trim(getDolGlobalString('DOCBROWSER_LINKS_TITLE'));
		if ($title === '') {
			$title = $langs->transnoentities('DocBrowserLinksTitle');
		}
		if ($html) {
			$out = '<p><strong>'.dol_escape_htmltag($title).'</strong></p><ul>';
			foreach ($links as $l) {
				$out .= '<li><a href="'.dol_escape_htmltag($l['url']).'">'.dol_escape_htmltag($l['name']).'</a> ('.dol_print_size($l['size'], 1, 1).')</li>';
			}
			return $out.'</ul>';
		}
		$out = $title."\n";
		foreach ($links as $l) {
			$out .= '- '.$l['name'].': '.$l['url']."\n";
		}
		return $out;
	}

	/**
	 * Web paths of the dialog's script and stylesheet, for llxHeader() or a <script> tag
	 *
	 * @return array{js:string,css:string}
	 */
	public static function assets()
	{
		// File dates bust the browser cache after an upgrade
		$out = array();
		foreach (array('js' => '/docbrowser/js/docbrowser.js', 'css' => '/docbrowser/css/docbrowser.css') as $type => $file) {
			$out[$type] = dol_buildpath($file, 1).'?v='.((int) @filemtime(dol_buildpath($file, 0)));
		}
		return $out;
	}

	/**
	 * Whether a user may use the browser at all
	 *
	 * @param  User $user User
	 * @return bool
	 */
	public static function available(User $user)
	{
		return isModEnabled('docbrowser') && $user->hasRight('docbrowser', 'use');
	}
}
