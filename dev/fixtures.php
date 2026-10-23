<?php
// Dev fixtures for Document Browser, on top of Convomail's dev fixtures (Acme Hospital, Dana Rivera, PJ-OXY-001,
// user salesrep). Safe to run again. Run inside the dolibarr container: php /tmp/docbrowser_fixtures.php
// Needs the Products, Commercial proposals and Documents (ECM) modules enabled (Home > Setup > Modules).
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmdirectory.class.php';

$user = new User($db);
$user->fetch(1);
$user->loadRights();

/** A small valid PDF with one line of text */
function fixture_pdf($path, $text)
{
	$stream = "BT /F1 18 Tf 72 720 Td (".addcslashes($text, '()\\').") Tj ET";
	$objs = array(
		"<< /Type /Catalog /Pages 2 0 R >>",
		"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
		"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
		"<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream",
		"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
	);
	$out = "%PDF-1.4\n";
	$offsets = array();
	foreach ($objs as $i => $o) {
		$offsets[] = strlen($out);
		$out .= ($i + 1)." 0 obj\n".$o."\nendobj\n";
	}
	$xref = strlen($out);
	$out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
	foreach ($offsets as $off) {
		$out .= sprintf("%010d 00000 n \n", $off);
	}
	$out .= "trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
	dol_mkdir(dirname($path));
	file_put_contents($path, $out);
}

/** A JPEG of the given size; noisy images are large (to test the size limit) */
function fixture_jpg($path, $w, $h, $label, $noisy = false)
{
	$im = imagecreatetruecolor($w, $h);
	imagefill($im, 0, 0, imagecolorallocate($im, 40, 90, 160));
	if ($noisy) {
		for ($y = 0; $y < $h; $y += 2) {
			for ($x = 0; $x < $w; $x += 2) {
				imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
			}
		}
	}
	imagestring($im, 5, 20, 20, $label, imagecolorallocate($im, 255, 255, 255));
	dol_mkdir(dirname($path));
	imagejpeg($im, $path, 92);
	imagedestroy($im);
}

/** Put a file in core's file index, as an upload from the record's Documents tab does */
function fixture_index($path, $srctype = '', $srcid = 0)
{
	global $db;
	$rel = preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'[\\/]*/', '', dirname($path));
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."ecm_files WHERE filepath = '".$db->escape($rel)."' AND filename = '".$db->escape(basename($path))."'");
	$obj = null;
	if ($srctype) {
		$obj = new stdClass();
		$obj->table_element = $srctype;
		$obj->id = $srcid;
	}
	addFileIntoDatabaseIndex(dirname($path), basename($path), '', 'uploaded', 0, $obj);
}

$acme = new Societe($db);
$acme->fetch(0, 'Acme Hospital');
$north = new Societe($db);
$north->fetch(0, 'Northwind Supply');
$project = new Project($db);
$project->fetch(0, 'PJ-OXY-001');
$dana = new Contact($db);
$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."socpeople WHERE email = 'dana.rivera@acme.test'");
$dana->fetch((int) $db->fetch_object($res)->rowid);

$socdir = $conf->societe->multidir_output[1];
fixture_pdf($socdir.'/'.$acme->id.'/Acme-supply-agreement.pdf', 'Acme supply agreement');
fixture_index($socdir.'/'.$acme->id.'/Acme-supply-agreement.pdf', 'societe', $acme->id);
fixture_jpg($socdir.'/'.$acme->id.'/Acme-loading-dock.jpg', 1600, 1200, 'Acme loading dock');
fixture_pdf($socdir.'/contact/'.$dana->id.'/Dana-business-card.pdf', 'Dana Rivera');
fixture_pdf($socdir.'/'.$north->id.'/Northwind-price-list.pdf', 'Northwind confidential price list');
fixture_index($socdir.'/'.$north->id.'/Northwind-price-list.pdf', 'societe', $north->id);
fixture_pdf($conf->project->multidir_output[1].'/'.$project->ref.'/Oxygen-meter-install-plan.pdf', 'Install plan');
fixture_index($conf->project->multidir_output[1].'/'.$project->ref.'/Oxygen-meter-install-plan.pdf', 'projet', $project->id);
print "record files done\n";

// Product with photos (one large and noisy, about 3 MB) and a datasheet
$prod = new Product($db);
if ($prod->fetch(0, 'OXY-METER-200') <= 0) {
	$prod = new Product($db);
	$prod->ref = 'OXY-METER-200';
	$prod->label = 'Oxygen purity meter 200';
	$prod->type = Product::TYPE_PRODUCT;
	$prod->status = 1;
	$prod->status_buy = 1;
	$prod->price = 450;
	$prod->create($user);
	$prod->fetch(0, 'OXY-METER-200');
}
$pdir = $conf->product->multidir_output[1].'/'.get_exdir(0, 0, 0, 1, $prod, 'product');
fixture_jpg($pdir.'/oxy-meter-front.jpg', 1200, 900, 'OXY-METER-200 front');
fixture_jpg($pdir.'/oxy-meter-detail-hires.jpg', 2400, 1800, 'OXY-METER-200 detail', true);
fixture_pdf($pdir.'/oxy-meter-datasheet.pdf', 'OXY-METER-200 datasheet');
fixture_index($pdir.'/oxy-meter-datasheet.pdf', 'product', $prod->id);
print "product ".$prod->id." files done\n";

// Proposal for Acme with a generated PDF
$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."propal WHERE fk_soc = ".((int) $acme->id)." LIMIT 1");
$propal = new Propal($db);
if ($res && ($o = $db->fetch_object($res))) {
	$propal->fetch((int) $o->rowid);
} else {
	$propal->socid = $acme->id;
	$propal->fk_project = $project->id;
	$propal->date = dol_now();
	$propal->duree_validite = 30;
	$propal->create($user);
	$propal->addline('Oxygen purity meter 200', 450, 4, 0, 0, 0, $prod->id);
	$propal->valid($user);
	$propal->fetch($propal->id);
}
$langs->load('main');
$r = $propal->generateDocument('cyan', $langs);
print "propal ".$propal->ref." pdf=".$r." ".$propal->error."\n";

// Documents module folder and medias
$ecm = new EcmDirectory($db);
if ($ecm->fetch(0, '') <= 0 || $ecm->label !== 'Brochures') {
	$res = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."ecm_directories WHERE label = 'Brochures'");
	if (!$res || !$db->fetch_object($res)) {
		$ecm = new EcmDirectory($db);
		$ecm->label = 'Brochures';
		$ecm->fk_parent = 0;
		$ecm->create($user);
	}
}
fixture_pdf($conf->ecm->dir_output.'/Brochures/Oxygen-range-brochure-2026.pdf', 'Oxygen range brochure');
fixture_index($conf->ecm->dir_output.'/Brochures/Oxygen-range-brochure-2026.pdf');
fixture_jpg(DOL_DATA_ROOT.'/medias/logos/dpg-logo.jpg', 400, 200, 'DPG');
print "ecm and medias done\n";

// salesrep: Document Browser right, read on third parties / products / projects, but only own customers (Acme),
// and no right on proposals or the Documents module
$sales = new User($db);
$sales->fetch(0, 'salesrep');
foreach (array(19428301, 121, 31, 41) as $rid) {	// docbrowser use, societe lire, produit lire, projet lire
	$sales->addrights($rid);
}
foreach (array(122, 262, 21, 2501) as $rid) {	// removes "see all customers" (262), proposals (21), ecm (2501)
	$sales->delrights($rid);
}
$db->query("DELETE FROM ".MAIN_DB_PREFIX."societe_commerciaux WHERE fk_user = ".((int) $sales->id));
$db->query("INSERT INTO ".MAIN_DB_PREFIX."societe_commerciaux (fk_soc, fk_user) VALUES (".((int) $acme->id).", ".((int) $sales->id).")");
print "salesrep rights done\n";
