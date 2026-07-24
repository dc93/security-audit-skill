# WordPress — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for WordPress. Read that file for the methodology; this one gives the target-specific coordinates. WordPress is GPL/open source, so this map was **read from the source** — verified against the `dc93/wordpress-develop` tree at HEAD (`$wp_version = 7.1-beta` trunk; the 6.x stable line shares these APIs).

> **Accuracy discipline — source-read, and mind where the bugs actually live.** Confirmed against **trunk (7.1-beta)**; 6.x stable matches. The single most important framing for WordPress: **core is comparatively hardened — the real attack surface is plugins and themes.** Most WordPress CVEs are in the ecosystem, using the exact core APIs below *incorrectly*. Audit core to learn the correct pattern, then hunt the plugins/themes for deviations. Confirm every identifier against the version you audit.

## Framework coordinates (verified against trunk 7.1-beta)

- **Database** — `$wpdb`: `$wpdb->prepare($query, ...$args)` with `%s` (string), `%d` (int), `%f` (float), and **`%i` (identifier — table/field names)**; `$wpdb->query()`, `esc_sql()`, `_real_escape()`. **The classic WordPress SQLi is `prepare()` *misuse*, not its absence:** interpolating input into the query string *before* `prepare`, calling `$wpdb->query("… $var …")` with no prepare, or building a table/column name by concatenation instead of the `%i` placeholder / an allowlist. `%i` (`WHERE %i = %s`) is the modern identifier-safe form — code predating it that concatenates identifiers is the lead.
- **CSRF vs authorization — the defining WordPress distinction.** **Nonces are CSRF tokens, NOT authorization.** `wp_verify_nonce()`, `wp_create_nonce()`, `check_admin_referer()`, `check_ajax_referer()` (`src/wp-includes/pluggable.php`) only prove the request came from a WP page — they do **not** check the user is allowed to do the action. Authorization is **`current_user_can($capability)`** / `user_can` / `map_meta_cap` (`src/wp-includes/capabilities.php`). **The #1 WordPress access-control bug: a handler that checks a nonce but never calls `current_user_can`** (or checks a too-weak capability). Report the missing capability check, not the nonce.
- **AJAX surface** — `admin-ajax.php` dispatches `do_action("wp_ajax_{$action}")` for logged-in users and **`do_action("wp_ajax_nopriv_{$action}")` for unauthenticated users**. A `wp_ajax_nopriv_*` handler is an **unauthenticated entry point** — enumerate every one and check what it does without auth. Missing nonce *and* missing capability on an `wp_ajax_*` action is the canonical WP privilege/CSRF bug.
- **REST API** — `register_rest_route($ns, $route, $args)` (`src/wp-includes/rest-api.php`) **requires a `permission_callback`** (core emits `_doing_it_wrong` if absent). **The classic broken-authz REST bug is `'permission_callback' => '__return_true'`** on a route that reads or writes privileged data — grep every route's callback.
- **Deserialization / object injection** — `maybe_unserialize()` calls **raw `@unserialize( trim( $data ) )`** when `is_serialized()` is true (`src/wp-includes/functions.php`). Options, post/user meta, and transients round-trip through it, so **attacker-controlled serialized bytes in any of those → PHP object injection** with a POP chain in the loaded plugins/core. This is a large, live WordPress class (many ecosystem CVEs). Trace who can write an option/meta value that later hits `maybe_unserialize`.
- **Output / XSS** — escape at output with `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `wp_kses()`/`wp_kses_post()`. **`add_query_arg()` / `remove_query_arg()` return an UNescaped URL** (confirmed in the docblock: "not escaped by default… late-escape with `esc_url()`") — echoing their result directly is reflected XSS, a recurring WordPress/plugin bug. Stored XSS lives where user content is saved and later printed without `esc_*`/`wp_kses`.
- **Code-execution sinks (admin-gated)** — the theme/plugin **file editor** writes PHP to disk: `wp_ajax_edit_theme_plugin_file()` → `wp_edit_theme_plugin_file( wp_unslash( $_POST ) )` (`src/wp-admin/includes/ajax-actions.php` / `file.php`) → RCE, gated by `edit_themes`/`edit_plugins` and `DISALLOW_FILE_EDIT`. Plugin/theme **upload/install** runs shipped PHP → RCE. Both are admin-by-design; the finding is a lower-privilege *reach* (a capability bug, CSRF, or SSRF-to-install).
- **Hooks / plugins-themes** — `add_action`/`add_filter`/`do_action`/`apply_filters`. Everything extends through hooks running in core context; the ecosystem is where the sinks above get used without their guard.

## WordPress-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- SQL: `\$wpdb->query\(` without `prepare`; `prepare\(` with `$`-interpolation *inside* the query string; identifier built by concatenation instead of `%i`; `"\s*\.\s*\$_(GET|POST|REQUEST)` reaching `$wpdb`
- Authorization: `wp_ajax_` / `register_rest_route` / admin handlers with a nonce but **no `current_user_can\(`**; `'permission_callback'\s*=>\s*'__return_true'`; `wp_ajax_nopriv_` handlers that mutate state
- Object injection: `maybe_unserialize\(` / `\bunserialize\(` on option/meta/transient/request data the attacker can write
- XSS: `add_query_arg\(|remove_query_arg\(` echoed without `esc_url`; `echo`/`printf` of user data without `esc_html`/`esc_attr`/`wp_kses`
- Code exec: `edit_theme_plugin_file`, plugin/theme upload/install paths, any `eval\(`/`call_user_func` on option or request data
- Nonce misuse: `check_admin_referer`/`wp_verify_nonce` treated as the *only* gate on a privileged action

## Canonical high-value chains (source-confirmed patterns)

- **Missing `current_user_can` → privilege escalation / broken access control.** An `wp_ajax_*` action or REST route with `permission_callback => __return_true` (or nonce-only) that performs a privileged operation. **The most common serious WordPress bug** — cite the missing capability.
- **`$wpdb->prepare` misuse → SQL injection.** Input interpolated before/around `prepare`, a raw `$wpdb->query`, or a concatenated identifier where `%i` was needed. Rife in plugins.
- **`maybe_unserialize` on attacker data → object injection.** A writable option/meta/transient reaching `maybe_unserialize` with a POP gadget in the loaded code.
- **`add_query_arg` / unescaped output → reflected or stored XSS.** Echoing `add_query_arg()` or a user field without `esc_url`/`esc_html`/`wp_kses`.
- **Unauthenticated `wp_ajax_nopriv` action → whatever it exposes.** A nopriv handler that leaks data or changes state without auth.
- **Editor/installer → RCE, reached by a lower-priv bug.** The theme/plugin file editor or install path, chained from a capability/CSRF/SSRF bug rather than assuming full admin.

## Ecosystem note (where to spend the effort)

Unlike the forum stacks, WordPress core rarely holds the bug — **the plugins and themes do**, using these exact APIs wrong: a nonce check mistaken for authorization, `permission_callback => __return_true`, `$wpdb->query` with raw input, `add_query_arg` echoed unescaped, `maybe_unserialize` on a user-writable option. Point Phase 2 agents at the `wp-content/plugins` and `wp-content/themes` of the target, using core (this map) as the reference for what *correct* looks like.

## WordPress version differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **`%i` adoption is a seam.** The identifier placeholder is relatively new; a plugin (or older core path) that concatenates table/column names predates it — diff old vs current query-building for identifier handling.
- **REST `permission_callback` enforcement tightened over time.** Core moved from optional to `_doing_it_wrong`-warned; routes registered against an older API may lack it.
- **Capability/nonce conventions drift.** Confirm each handler in the version you audit applies both a nonce and a capability; a control added in a newer release may be absent in a plugin written against an older one.
- **Un-backported fixes.** For each core or plugin security release, confirm the branch/version you audit received it; an un-patched plugin against a known CVE is a strong, disclosed-fix-proven finding.
