# MyBB — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for MyBB. Read that file for the methodology; this one gives the target-specific coordinates. Unlike the vBulletin notes, MyBB is GPL/open source, so this map was **read from the source** — verified against the tree at `dc93/mybb` HEAD (`MYBB_VERSION 1.9.0-alpha`, version_code 1900).

> **Accuracy discipline — source-read, but version-specific.** These identifiers were confirmed against **1.9.0-alpha**, which is **mid-refactor**: front-end templating is being moved off the classic `eval`-based mechanism while the stable **1.8.x** line still uses it end to end. So the single biggest thing to establish first is *which version you are auditing* — several sinks below exist in 1.8 and are being removed in 1.9, or vice versa. Confirm every path against the exact version in front of you; a name that doesn't resolve means you're on the other line. The 1.8↔1.9 delta is itself a hunting axis — see the differential notes and [DIFFERENTIAL-AUDIT.md](DIFFERENTIAL-AUDIT.md).

## Framework coordinates (verified against 1.9.0-alpha)

- **Input** — `$mybb->input` is the raw incoming array (`parse_incoming()`), and `$mybb->get_input($name, $type)` is the typed accessor with `MyBB::INPUT_STRING` (0, default), `INPUT_INT` (1), `INPUT_ARRAY` (2), `INPUT_FLOAT` (3), `INPUT_BOOL` (4). **The SQLi/XSS source is raw `$mybb->input['x']` (or `get_input('x')` left as a string) interpolated into a query or output without escaping.** `INPUT_ARRAY` returning an array where scalar is expected is the array-shape injection angle.
- **Database** — `$db` (mysqli/pdo drivers, `db_base` interface): `$db->query($sql)`, `write_query()`, `simple_select($table, $fields, $conditions, $options)`, `insert_query($table, $array)`, `update_query($table, $array, $where, $limit, $no_quote)`, with `escape_string()`, `escape_string_like()`, `escape_binary()`. **Sinks:** a raw `$db->query("… {$mybb->input[...]} …")`, a `simple_select` `$conditions` / `update_query` `$where` built from unescaped input, or `insert_query`/`update_query` with `$no_quote = true`. `escape_string` present-but-not-applied is the usual gap.
- **Template engine — code-execution sink, version-dependent.** `inc/class_templates.php`: `render()` returns `'return "'.$this->get($title).'";'` — i.e. template bodies are compiled into a **double-quoted PHP string that is `eval`'d**, so `{$...}` in a template evaluates and template content is a code context. In **1.8.x** this drives front-end rendering broadly (stored template → `eval` → RCE, reachable via the ACP template editor). In **1.9-alpha** the front-end is migrating away from this (the old `eval("\$header = …")` sites in `global.php` are gone), **but `eval` of stored content persists** — confirmed live at `admin/modules/config/settings.php` (`eval("\$setting_code = \"".$setting['optionscode']."\";")`), `inc/functions_user.php` (`$ucp_nav_tracking`), and dynamic class creation in `admin/index.php`. Trace where stored template/setting text reaches `eval`.
- **Deserialization — object injection is mitigated by design.** MyBB ships `my_unserialize()` (`inc/functions.php`), a **safe replacement that does NOT unserialize objects** (it throws on objects/resources). Cookies and most stored blobs go through it, so classic PHP object injection is *closed* in core — do **not** report a generic `my_unserialize` call as object injection. The real hunt angle is **`native_unserialize()`** (raw PHP `unserialize`) on attacker-influenced data: core uses it only on the trusted datacache (`inc/class_datacache.php`), so a plugin or code path that calls `native_unserialize`/`unserialize` on request/user data is the object-injection lead.
- **Hooks / plugins** — `$plugins->run_hooks($hook, &$arguments)` and `add_hook()` (`inc/class_plugins.php`). Plugin code runs in full core context at hook points; a writable plugin/hook path is RCE, and plugins are the largest weakly-reviewed surface.
- **CSRF** — `verify_post_check($code)` (`inc/functions.php`) against the user's `my_post_key`. A state-changing POST handler that doesn't call it is a CSRF lead — and CSRF into the ACP template/settings editor is the canonical path to the `eval` sinks above.
- **Output / parser (XSS)** — `inc/class_parser.php`: `parse_message()`, `parse_mycode()`, `parse_html()`, `mycode_parse_img()`, etc. MyCode/BBCode handling and any place `$parser` is invoked with `allow_html` or a permissive option set is the stored-XSS surface; `htmlspecialchars_uni()` is the intended escaper — find output that skips it.
- **Admin** — `admin/modules/{config,forum,home,tools,user}`. The ACP is where template/settings `eval` sinks live; audit its own auth (admin session, `admin_session`) and CSRF on top of the front-end.

## MyBB-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- Raw SQL source: `\$mybb->input\[` interpolated into a query; `\$db->query\("` with `{$` inside; `simple_select\(.*\$mybb->`; `update_query|insert_query` with `true` as the no-quote arg
- Input not typed: `get_input\('[^']+'\)` used in SQL/HTML with no `INPUT_INT`/escape; `\$mybb->input\[` reaching a sink
- Code exec: `\beval\(` (esp. on `optionscode`, template/setting text, `$ucp_nav`), template `render(`/`get(` output reaching `eval`
- Object injection: `native_unserialize\(|\bunserialize\(` on non-datacache data (any hit outside `class_datacache.php` deserving a trace)
- CSRF: POST handlers missing `verify_post_check(`
- XSS: `$parser->parse_message` / `parse_mycode` with `allow_html`/permissive options; output missing `htmlspecialchars_uni`
- Plugins: `run_hooks\(` points where `&$arguments` carries request data into plugin code

## Canonical high-value chains (source-confirmed patterns)

- **Raw `$mybb->input` → SQL injection.** A value pulled as `$mybb->input[...]` or `get_input(..., INPUT_STRING)` and interpolated into `$db->query` / a `simple_select` condition without `escape_string` or an int cast. The most common MyBB core/plugin bug; historically pre- or low-auth on public listing/search endpoints.
- **Stored setting/template text → `eval` → RCE.** The ACP settings `optionscode` and (in 1.8) template bodies are `eval`'d. The finding is the *reach*: CSRF/XSS into the ACP, or a lower-privilege write into settings/templates. Admin-by-design is not a finding (Anti-Pattern 4) — a non-admin path to the `eval` is.
- **`native_unserialize` on untrusted data → object injection.** Only where a code path bypasses `my_unserialize` and feeds raw `unserialize`/`native_unserialize` attacker bytes with a gadget in the loaded classes. Core restricts this to the datacache; plugins are where it reappears.
- **MyCode / parser → stored XSS.** A message or profile field rendered with an over-permissive parser option (`allow_html`, unescaped output) firing in other users' sessions.
- **Plugin hook code execution.** Third-party plugin code at `run_hooks` points, running with full core authority over request-carried `$arguments`.

## MyBB 1.8 ↔ 1.9 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Templating is the migration seam.** 1.8 renders front-end templates via `eval` of a `return "…";` string broadly; 1.9 is removing that from front controllers while keeping `eval` of stored content in the ACP (settings `optionscode`, ucp nav) and datacache. Re-locate the render mechanism per version; a payload that reaches `eval` in 1.8 may hit a new, non-eval path in 1.9 (or a half-migrated controller that still evals).
- **Half-migrated controllers.** In 1.9-alpha, front controllers building structured arrays (`$headerMessages`) sit next to code still using the old accessors — the seam between the two rendering styles is prime half-migration territory.
- **Input/escaping consistency.** Confirm that both lines escape the same way at each sink; a control tightened in one line may lag in the other.
- **Backported vs missed fixes.** For each security fix in one line's history, check the other — MyBB maintains 1.8 stable alongside 1.9 dev, so an un-backported fix or a regression introduced by the refactor is a strong, patch-proven finding.
