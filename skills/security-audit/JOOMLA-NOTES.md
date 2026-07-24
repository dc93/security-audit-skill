# Joomla — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for Joomla. Read that file for the methodology; this one gives the target-specific coordinates. Joomla is GPL/open source, so this map was **read from the source** — verified against the tree at `dc93/joomla-cms` HEAD (`MAJOR.MINOR.PATCH = 5.4.8`, DEV_STATUS Development).

> **Accuracy discipline — source-read, but version-specific.** Confirmed against **5.4.x**. Joomla 3.x/4.x share the MVC/component model and most of this API surface but differ in namespaces and details (3.x is pre-namespace `JInput`/`JFactory`). Confirm every identifier against the exact version you audit; treat a major-version pair as a differential axis ([DIFFERENTIAL-AUDIT.md](DIFFERENTIAL-AUDIT.md)).

## Framework coordinates (verified against 5.4.8-dev)

- **Input** — `Joomla\CMS\Input\Input` (`$app->getInput()` / `$input`): `$input->get($name, $default, $filter)` plus typed accessors `getInt`, `getCmd`, `getString`, `getRaw`, `getHtml`, `getArray`. **The filter is the control**: `getCmd` restricts to `[A-Za-z0-9_.-]`, `getInt` coerces, but **`getRaw` / filter `'RAW'` returns unfiltered input** — that is the SQLi/XSS/path source. A default `get()` runs `InputFilter`, which strips much XSS but is not a SQL escaper.
- **Database** — `Joomla\Database` driver (`$db`): `$db->setQuery($query)` then `loadResult`/`loadObjectList`/`execute`; `$db->quote($value)` for **values**, `$db->quoteName($identifier)` for **identifiers**, `$db->escape($text, $extra)`. Query builder: `$query = $db->getQuery(true); $query->select(...)->from(...)->where(...)`. **Injection sink:** input concatenated into `where("… $x …")` or `setQuery("… $x …")` without `quote`/`quoteName`, or an `ORDER BY`/column built from `getCmd`-that-should-have-been-allowlisted. `quote` ≠ `quoteName` — using the wrong one on an identifier is a classic Joomla SQLi.
- **Object injection / deserialization — raw `unserialize`, with history.** The session store deserializes its payload (`libraries/src/Session/Storage/JoomlaStorage.php` — `unserialize(base64_decode($_SESSION['joomla']))`), and the cache controllers `unserialize` cached data (`libraries/src/Cache/Controller/*Controller.php`). This is the lineage of Joomla's famous session object-injection RCE — object injection is **not** closed by design. The lead is any path where attacker bytes reach one of these `unserialize` calls (session value, poisoned cache entry, a component storing serialized data) with a gadget in the loaded classes.
- **Code-execution sinks (admin-gated).** Two source-confirmed write-to-code paths: the **template manager** writes editor content straight to a template file — `administrator/components/com_templates/src/Model/TemplateModel.php` (`File::write($filePath, $data['source'])`), i.e. an admin can write PHP into a served template → RCE; and the **extension installer** (`Joomla\CMS\Installer\Installer`, `com_installer`) runs installed package PHP, including **install-from-URL**, so package substitution / a malicious package is RCE (and the URL fetch is SSRF surface). Both are admin-privileged by design — the finding is the *reach* (CSRF/XSS into admin, an ACL bypass), not that admins can do admin things.
- **CSRF** — `Session::checkToken($method)`, reached in controllers via `$this->checkToken()` (`libraries/src/MVC/Controller/BaseController.php`; `AdminController` calls it in each write action). Form token via `HTMLHelper::_('form.token')`. A component controller action that changes state without `checkToken` is a CSRF lead — the highest-value target being one that reaches the template/installer sinks above.
- **Authorization** — `$user->authorise($action, $assetname)` (`libraries/src/User/User.php`) and `Joomla\CMS\Access\Access`. Permissions are per-asset (component/category/item). The authz hunt is an action missing an `authorise` check, or checking the wrong asset — especially in component `admin` views reachable without the right ACL.
- **Components / MVC** — front `components/com_*`, admin `administrator/components/com_*`, each with `src/Controller|Model|View`. Third-party components are the largest surface; the same object often has a front and an admin path with different checks (diff them).
- **Output / XSS** — views escape via `$this->escape(...)`; `InputFilter` on input. XSS lives where output skips `escape()` / uses `getRaw` data, or a component echoes a stored field raw.

## Joomla-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- SQL: `->where\(` / `setQuery\(` with `.\s*\$` interpolation and no `quote(`/`quoteName(`; `getRaw\(`/`'RAW'` reaching a query; `quote(` used where `quoteName(` was needed (identifier)
- Input source: `->getRaw\(|->get\('[^']+',\s*[^,]+,\s*'RAW'`; unfiltered input reaching a sink
- Object injection: `\bunserialize\(` outside the session/cache trusted paths — trace the writer of the bytes
- Code exec: `File::write\(` with editor/user content (com_templates), `Installer` install-from-URL, any `eval\(` in a component
- CSRF: controller write actions missing `checkToken\(`
- Authz: state changes with no `authorise\(` / `Access::check`, or the wrong `$assetname`
- SSRF: `Joomla\CMS\Http`/`HttpFactory` fetching request-derived or install URLs

## Canonical high-value chains (source-confirmed patterns)

- **`getRaw`/unquoted input → SQL injection.** A raw or wrongly-filtered value concatenated into `where()`/`setQuery()` without `quote`/`quoteName`, or an identifier passed through `quote` instead of `quoteName`. Joomla components are historically a rich SQLi source.
- **Session/cache bytes → object injection RCE.** Attacker-influenced data reaching the session or cache `unserialize` with a gadget chain — Joomla's marquee historical RCE class; confirm the write path and a gadget in scope.
- **Admin editor/installer → RCE, reached by a lower-priv bug.** `com_templates` `File::write` of template source, or `com_installer` package/URL install. Chain a CSRF/XSS/ACL-bypass into the admin action; the write-to-PHP is the payload.
- **Missing `checkToken`/`authorise` → CSRF or privilege escalation.** A component action that changes state without the token or the ACL check — worst when it drives the template/installer sinks or edits another user's asset.
- **Third-party component sinks.** `com_*` extensions with their own SQL, unserialize, file writes, and ACL checks — the weakly-reviewed bulk of a real Joomla install.

## Joomla 3/4 ↔ 5 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Namespacing is the big seam.** 3.x is pre-namespace (`JInput`, `JFactory`, `JDatabase`); 4.x/5.x moved to `Joomla\CMS\*` and PSR-ish structure. A guard present in a legacy `J*` path may be absent or relocated in the namespaced rewrite (and vice versa) — re-locate each control per version.
- **Input filtering and DB API consistency.** Confirm both lines filter input and quote identifiers the same way at each sink; a query safe via `quoteName` in one line but concatenated in a rewritten model is a regression.
- **Deserialization format.** Check whether the newer line moved any stored blob from serialized to JSON, and whether a compat path still accepts the serialized form — prime object-injection/half-migration territory given Joomla's history.
- **Un-backported fixes.** Joomla runs an LTS/stable line alongside development — for each security fix in one, confirm the other has it; an un-backported fix or a refactor regression is a strong, patch-proven finding.
