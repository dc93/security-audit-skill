# phpBB — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for phpBB. Read that file for the methodology; this one gives the target-specific coordinates. phpBB is GPL/open source, so this map was **read from the source** — verified against the tree at `dc93/phpbb` HEAD (`PHPBB_VERSION 4.0.0-a3-dev`; the stable line is 3.3.x, which shares most of this architecture).

> **Accuracy discipline — source-read, but version-specific.** Confirmed against **4.0.0-a3-dev**. The 3.3.x stable line uses the same core building blocks (the request class, the `sql_*` DB API, Twig templating, form-key CSRF, the ACL system, the event/extension model) but paths and details drift — confirm every identifier against the exact version you audit, and treat the 3.3↔4.0 delta as its own hunting axis ([DIFFERENTIAL-AUDIT.md](DIFFERENTIAL-AUDIT.md)).

## Framework coordinates (verified against 4.0.0-a3-dev)

- **Input** — `\phpbb\request\request`: `$request->variable($name, $default, $multibyte, $super_global)`. **The `$default`'s type coerces the result** — `variable('x', 0)` is an int, `variable('x', '')` a string. Superglobals are disabled/`deactivated`, so `$request->variable` (and `->server`/`->header`/`->is_set_post`) is the input surface. The SQLi/XSS source is a **string** `variable(...)` used without `sql_escape`/escaping; an int-typed default is safe by coercion.
- **Database** — `$db` (`\phpbb\db\driver\*`): `$db->sql_query($sql)`, `sql_query_limit()`, `sql_escape($msg)`, `sql_in_set($field, $array)`, `sql_build_query('SELECT', $ary)`. **phpBB builds SQL as strings with manual escaping — there is no prepared-statement API in the driver.** So the injection sink is `sql_query("… " . $request->variable('x','') . " …")` without `sql_escape`, an `sql_in_set` fed unescaped values, or a `sql_build_query` array whose WHERE/ON fragments carry raw input. Integer IDs are usually safe only because the request default coerced them — verify the default.
- **Templates — Twig, but autoescape is OFF.** phpBB renders through `\phpbb\template\twig`, and its Twig environment sets **`'autoescape' => false`** (`phpbb/template/twig/environment.php`): escaping is **phpBB's responsibility**, done on variable assignment / via explicit filters, not by Twig. So do **not** assume the template layer neutralizes XSS. The hunt is template variables that reach output unescaped (assigned raw, or emitted through a no-escape path), and — since custom extensions ship their own templates — any extension template that interpolates user data without escaping. Server-side template injection only arises if an extension lets user input become template *source*.
- **Deserialization — raw `unserialize`, NO safe wrapper.** Unlike MyBB, phpBB calls PHP's raw `unserialize()` on stored data throughout: `log.log_data`, auth `role_cache`, cache driver payloads, `notification_data`, nestedset `parents`, extension `ext_state` (`phpbb/log/log.php`, `phpbb/auth/auth.php`, `phpbb/cache/driver/file.php`, `phpbb/notification/type/base.php`, `phpbb/extension/manager.php`). Most sources are admin/trusted, **but object injection is not closed by design** — the lead is any of these blobs (or an extension's own `unserialize`) that an attacker can influence, with a gadget in the loaded classes. Trace who can write each serialized column.
- **BBCode / text** — `\phpbb\textformatter\s9e` wraps **s9e\TextFormatter** (`phpbb/textformatter/s9e/factory.php`), which replaced the legacy `bbcode.php`. It is designed to output safe HTML; the XSS surface is custom BBCode definitions, misconfigured allowed HTML, and the `generate_text_for_display`/`_storage` boundary — not raw string BBCode as in older forums.
- **CSRF** — `add_form_key($form_name)` in the form, `check_form_key($form_name)` in the handler (`phpBB/includes/functions.php`). A state-changing POST handler (front or ACP/MCP) that omits `check_form_key` is a CSRF lead.
- **Authorization** — `$auth->acl_get($opt, $forum_id)` (`phpbb/auth/auth.php`) and `acl_gets`/`acl_getf`; permissions are per-forum. The authz hunt is `acl_get` called with the wrong option or forum id, or a state change with no acl check — especially in the MCP (moderator control panel) and ACP.
- **Redirect** — `redirect($url)` and `append_sid()` (`phpBB/includes/functions.php`); `redirect()` historically enforces same-site URLs, so an open-redirect finding must show a concrete bypass of that check, not just that the function is called.
- **Extensions / events** — `$phpbb_dispatcher->trigger_event($name, $data)` (`phpbb/event/dispatcher.php`). Extension listeners execute in full core context; the extension surface (`ext/`) is where fresh, less-reviewed sinks live.

## phpBB-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- SQL source→sink: `sql_query\(` with `.\s*\$request->variable`, `\$request->variable\('[^']+',\s*''\)` (string-typed) reaching SQL, `sql_in_set\(` / `sql_build_query\(` with unescaped input
- Missing escape: string `variable(...)` used in SQL without a nearby `sql_escape`/int default
- Object injection: `\bunserialize\(` outside the trusted cache/role paths — trace the column's writer
- XSS: template variables assigned raw (no escaping) given autoescape is off; extension templates emitting user data; any `|raw`-style no-escape output
- CSRF: POST/ACP/MCP handlers missing `check_form_key\(`
- Authz: `acl_get\(` with a questionable option/forum, or a state change with no `acl_` call
- Redirect: `redirect\(` / `append_sid\(` fed request-derived URLs (then prove the same-site check is bypassed)

## Canonical high-value chains (source-confirmed patterns)

- **String `$request->variable` → SQL injection.** A string-typed request value concatenated into `sql_query`/`sql_in_set`/`sql_build_query` without `sql_escape`. phpBB's no-prepared-statement model means every dynamic query is a candidate; int-typed values are safe only via default coercion — check it.
- **Attacker-influenced serialized blob → object injection.** Raw `unserialize` on a stored column (notification data, log data, an extension's state, a custom field) that a lower-privilege actor can write, with a gadget in scope. phpBB lacks MyBB's object-blocking wrapper, so this is live where the write is reachable.
- **Unescaped template variable → XSS.** Because Twig autoescape is off, a variable assigned without phpBB's escaping (often in an extension's controller/template) reaches the DOM raw. Confirm the assignment path, not just the `{{ }}` in the template.
- **Missing `check_form_key` / `acl_get` → CSRF or authz bypass.** A state-changing action in the front end, MCP, or ACP that skips the form-key or the per-forum ACL check — highest impact in the moderator/admin panels.
- **Extension sinks.** Third-party `ext/` code hooked via the event dispatcher, running with core authority over event data — the largest weakly-reviewed surface.

## phpBB 3.3 ↔ 4.0 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Same primitives, drifting details.** Both lines use the request class, `sql_*`, Twig, form keys, ACL, and the event system — so a bug in one is worth checking in the other, but confirm the exact call sites, since 4.0 refactors namespaces and controllers.
- **Escaping consistency across the Twig-autoescape-off model.** Verify each line escapes template variables the same way; a variable escaped on assignment in 3.3 but assigned raw in a rewritten 4.0 controller (or vice versa) is a regression.
- **Deserialization reach.** Check whether 4.0 changed what is stored serialized vs JSON; a column that moved formats, with a compat path still accepting the old serialized form, is prime object-injection/half-migration surface.
- **Un-backported fixes.** phpBB maintains 3.3 stable alongside 4.0 dev — for each security fix in one line, confirm the other received it; an un-backported fix or a refactor-introduced regression is a strong, patch-proven finding.
