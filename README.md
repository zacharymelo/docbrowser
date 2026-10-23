# Document Browser for Dolibarr

Attach files that are already stored in Dolibarr to emails: a proposal PDF, a project drawing, product photos, files of the Documents module. When the attachments of an email are too large together, the largest picked files are sent as share links instead.

**Status:** version 0.1.0, tested on Dolibarr 23. Module ID 194283.

## What it does

- **Add from Dolibarr…** appears next to the **Attach** button of the **Send by email** form on every card (third party, contact, project, product, proposal, order, invoice, supplier proposal/order/invoice, contract, intervention, shipment).
- The same picker is available to other modules. [Convomail](https://github.com/zacharymelo/convomail) uses it in its compose page when this module is enabled.
- The picker has four tabs:
  - **Linked records:** files of the record the email is sent from, its third party and project, the contact, and the latest proposals, orders and invoices of the third party (click one to see its files).
  - **Products:** search a product or service by ref or label, then pick among its photos and documents.
  - **Documents:** the folders of the Documents (ECM) module, and the medias library.
  - **Search:** file names in Dolibarr's file index.
- Images show as thumbnails. Tick files, then **Add**.

## Who sees what

A user only sees, and can only attach, files they could open on the record's own **Linked files** tab:
- module rights, the "own customers only" sales representative restriction, external users, and project contacts all apply;
- the Documents module needs its read right; medias need the Documents, Emailing or Websites read right, as in core.

The browser never sends a file path. Each picked file is a key (record + file name, or folder + file name) that the server checks again when the file is attached and when the email is sent.

The originals are never changed or deleted: what goes into the email is a copy.

## Size limit and share links

- **Maximum total size of attachments** (default 10 MB): when the attachments of an email together would go over it, the largest files picked from Dolibarr are not attached. Instead, a **Files:** list of share links is added to the message when it is sent. Files uploaded from the computer are always attached.
- The links are Dolibarr's own public file links (`document.php?hashp=…`). They are only created when the email is sent, never when the form is just opened.
- **Remove share links after N days** (default 0 = keep): a daily scheduled job removes the links this module created, counted from the last email that included them. Links you share by hand are never removed. Needs the Scheduled jobs module.

## Image resizing

**Resize attached images** (longest side in pixels, default 0 = originals): JPEG, PNG, GIF and WebP images larger than this are attached as a smaller copy. Share links always give the original.

## Installation

1. Download `module_docbrowser-<version>.zip` from the [releases](https://github.com/zacharymelo/docbrowser/releases).
2. Home → Setup → Modules → **Deploy/install external app/module**, upload the zip.
3. Enable **Document Browser** (Electronic Content Management family).
4. Give the right **Attach files stored in Dolibarr to emails** to the users or groups who need it (on by default for new users).
5. Optionally, adjust the settings in the module's setup page.

## Upgrading

Deploy the new zip over the old one, then **disable and enable** the module so new hooks and tables are registered. Settings and share links are kept.

## For module developers

On a page of your module:

```php
dol_include_once('/docbrowser/class/docbrowserservice.class.php');
dol_include_once('/docbrowser/class/actions_docbrowser.class.php');
if (isModEnabled('docbrowser') && class_exists('DocBrowserService') && DocBrowserService::available($user)) {
	print ActionsDocBrowser::assetTags();	// script, stylesheet and translations, once per page
}
```

```js
DocBrowser.open({
	context: { socid: 12, projectid: 3, origin: 'propal', originid: 45 },	// or a function returning it
	onPick: function (files) { /* [{key, name, size, sizetext, mime, icon, thumb, record}] */ }
});
```

Post the picked `key`s with your form. On the server:

```php
$service = new DocBrowserService($db);
$file = $service->resolve($key, $user);	// null if the user may not read it
$copy = $service->copyForMail($file, $tmpdir);	// resized if set up
$split = DocBrowserService::split($copies, $bytesofotherattachments);	// {attach: [...], link: [...]}
$links = $service->shareLinks(array_column($split['link'], 'resolved'), $user);
$html = DocBrowserService::linksBlock($links, true);
```

## Development

The module is developed in [Convomail](https://github.com/zacharymelo/convomail)'s dev stack: clone this repo as `doli-docbrowser` next to it (`git clone https://github.com/zacharymelo/docbrowser.git ~/doli-docbrowser` beside `~/convomail`), and its `docker-compose.yml` mounts `module/` as `custom/docbrowser` on http://localhost:8114.

```bash
docker cp dev/fixtures.php convomail-dolibarr-1:/tmp/docbrowser_fixtures.php
docker compose -f ../convomail/docker-compose.yml exec dolibarr php /tmp/docbrowser_fixtures.php
python3 bin/build.py 0.1.0
```

`dev/fixtures.php` adds files to Convomail's fixtures (Acme Hospital, Dana Rivera, PJ-OXY-001), a product with photos, a proposal with its PDF, a Documents folder and a medias image. It also limits the `salesrep` user to its own customer, without proposals or the Documents module, to check what a restricted user can see.

## Licence

GPL v3 or later. See [LICENSE](LICENSE).
