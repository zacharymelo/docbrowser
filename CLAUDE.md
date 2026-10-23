# Document Browser: notes for Claude

Dolibarr module `docbrowser` (ID 194283, from Zach Melo's reserved range 194281–194309; rights 19428301). Pick files stored in Dolibarr and attach them to emails, or send them as share links when too large.

## Rules from the project owner
- Commit, push and release only when asked.
- Prefer supported core behaviour over workarounds; record each core gap in `UPSTREAM.md`.
- Plain, informative UI wording, with help text where a setting is not obvious.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Layout
- `module/class/docbrowserservice.class.php`: everything server side (types table, `resolve()`, listings, copies, size split, share links, expiry cron).
- `module/class/actions_docbrowser.class.php`: hooks. `getFormMail` (context `formmail`) prints the assets and config; `doActions` (context `globalcard`, real send only) adds share links to `$_POST['message']`.
- `module/ajax/list.php` (GET, read only, releases the session) and `module/ajax/attach.php` (POST + token, core's email form only).
- `module/js/docbrowser.js`: the dialog (`DocBrowser.open`) and the core email form integration.

## Security rules (do not weaken)
- A picked file is a key, never a path. `resolve()` is the only way to a path; listing and attaching both go through it.
- Record access is `restrictedArea(..., $mode = 1)` with the same arguments as the record's `*/document.php`. Then `dol_check_secure_access_document()` must agree on the same file. Then document.php's external-user query.
- Whitelist modulepart values (`types()`, `ecm`, `medias`) before calling `dol_check_secure_access_document()`: it `exit`s on unknown ones.
- Only copies go into FormMail's session list ("Remove all" deletes the files in it).
- Share links are created at send only.

## Dev stack
- Shared with Convomail: `../convomail/docker-compose.yml` mounts `module/` as `custom/docbrowser`, http://localhost:8114 (admin / admin).
- `dev/fixtures.php` (run in the container, after Convomail's fixtures) seeds files and limits `salesrep`. Run it as root, then `chown -R www-data:www-data /var/www/documents`.
- Core mail on the dev instance goes to GreenMail (`MAIN_MAIL_SENDMODE=smtps`, `greenmail:3025`); the admin user needs an email address to send.
- Enable or disable the module with the toggle link on the modules page (the link carries `confirm=yes`); a bare `action=reset` does nothing.

## Dolibarr gotchas
- `transnoentitiesnoconv()` without arguments removes `%s`: labels for the script are translated with `'__N__'` and filled in by JS.
- `dol_mimetype($name, '', 4)` gives the Font Awesome name without `fa-`.
- `image_format_supported()` needs `core/lib/images.lib.php`.
- `isModEnabled()` maps old module names (`commande` → `order`...), see `MODULE_MAPPING` in functions.lib.php.

## Release
`python3 bin/build.py <version>`, commit, tag `v<version>`, push, `gh release create v<version> bin/module_docbrowser-<version>.zip`.
