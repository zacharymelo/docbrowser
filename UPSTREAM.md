# Gaps in Dolibarr core met while building Document Browser

Things this module works around. Each could be a core improvement; none is proposed yet.

1. **No picker for existing files in the email form.** `FormMail` only pre-attaches the card's generated document (`withmaindocfile`, `fileinit`). Attaching any other file stored in Dolibarr means downloading and uploading it again.

2. **The `getFormMail` hook cannot add to the form.** It can only replace the whole form (return > 0 with `resPrint`) or do nothing. Document Browser prints its script before the form and adds its button with JavaScript next to the Attach button.

3. **No element → modulepart / documents folder helper.** Each `*/document.php` builds its own upload folder and modulepart. `getElementProperties()` has no modulepart, and `getMultidirOutput()` is wrong for contacts (`contact/` missing), shipments (`sending/` missing) and supplier invoices (the ref folder is missing). Document Browser keeps its own table per type (`DocBrowserService::types()`, `recordDirs()`).

4. **`dol_check_secure_access_document()` is not a complete access check.**
   - For internal users it only checks the module right, except for projects. It ignores the sales representative "own customers only" restriction.
   - The external-user check (`sqlprotectagainstexternals`) is run by document.php, not by the function.
   - It calls `exit` on an unknown or disabled modulepart.

   Document Browser calls `restrictedArea(..., $mode = 1)` with each Documents tab's arguments, then this function as a second gate, then repeats document.php's external-user query.

5. **"Remove all" in the email form deletes the files of the list** (`actions_sendmails.inc.php`, `dol_delete_file()` on every path), even a file that is not a temporary upload. Document Browser only ever puts copies in the list.

6. **The file index (`llx_ecm_files`) is incomplete.** Files put on disk directly are only indexed when their Documents tab is opened. Search is therefore limited to indexed files; the other tabs read the folders.
