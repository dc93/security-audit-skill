# vBulletin — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for vBulletin (vB 4.x and the vB 5 "Connect" rewrite). Read that file for the methodology; this one gives the target-specific coordinates and the historically bug-dense areas so Phase 2 doesn't start from zero.

> **Accuracy discipline — this is a public-research map, NOT a source read.** vBulletin is **closed, commercial source**, so unlike the open-platform notes this file is built from *disclosed CVEs, vendor patch announcements, and published security research*, not from reading the tree. Treat every route, class, and parameter below as a **documented lead to confirm against your own licensed copy**, never as ground truth — names and paths drift across 4.x/5.x and point releases. Do not cite a path in a finding you have not confirmed in the source you are auditing. Do not use leaked or pirated source to do that confirmation; audit only a copy you are licensed to run. If a name below doesn't resolve in your tree, you're on a different version — that's a signal, not a finding.

## Framework coordinates (vB 4.x / 5.x — confirm in your licensed copy)

- **Input** — the legacy GPC layer `$vbulletin->input->clean_gpc()` / `clean_gpc_array()` with `TYPE_*` constants (`TYPE_INT`, `TYPE_STR`, `TYPE_NOHTML`, …); `$vbulletin->GPC[...]`. **A value read without the right `TYPE_` clean, or used raw after cleaning as a string, is the classic SQLi/XSS source.** vB5 adds `vB_Request`/`vB5_Frontend` request handling and the `routestring` router.
- **Database** — `vB_Database` via `$vbulletin->db` (`->query_read()`, `->query_write()`, `->query_first()`), and in vB5 `vB::getDbAssertor()` / `vB_dB_Assertor` with query-definition arrays. Raw string interpolation into `query_read`/`query_write`, or an assertor condition built from unescaped input, is the injection sink. `$db->escape_string()` present-but-not-applied is a frequent gap.
- **Routing / API** — vB5 dispatches through `index.php?routestring=...` and an AJAX/API surface at `ajax/api/...` and `ajax/render/...` (`vB5_Frontend_Controller`, `vB_Api`). These AJAX/API endpoints are the unauthenticated attack surface behind the biggest vB RCEs — enumerate every `ajax/render/*` and `ajax/api/*` handler and what it will instantiate or evaluate.
- **Template engine** — templates are compiled to PHP and cached (historically in the `template` datastore). vB4 template **conditionals `<if condition="...">` evaluate the condition as PHP**; vB5 uses `{vb:...}` tags and widget modules. Any path that lets attacker-influenced text become template source, or that renders a widget/template whose body is attacker-controlled, is a code-execution sink.
- **Widgets** — the `widget_php` module historically executes PHP supplied in `widgetConfig[code]` (see canonical chains). Audit the widget/module render path and every `widgetConfig`/`subWidgets` field for what reaches `eval`/`call_user_func`.
- **Datastore / cache** — the datastore holds serialized configuration and compiled templates; `unserialize` on datastore/config/cookie values is object-injection surface (vB has a documented POP-chain lineage). Trace every `unserialize`.
- **Members / permissions** — `$vbulletin->userinfo`, permission bitfields via `$vbulletin->userinfo['permissions']` and `($perms & $bitfield)` checks; admin/mod via `can_administer()` / ACP session. CSRF token is `securitytoken` (`$vbulletin->userinfo['securitytoken']`), verified in POST handlers — a state-changing handler that skips the check is a CSRF lead.
- **Uploads / files / SSRF** — attachment and avatar handling, `install/`/`core/install/` upgrade scripts left reachable, and remote-fetch features (proxy image, RSS, MediaEmbed/oEmbed) as the SSRF surface.

## vBulletin-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- Raw SQL: `query_read\(|query_write\(|query_first\(` with a `$`-built string; `\$db->query` with interpolation; assertor conditions built from `\$vbulletin->GPC`
- Input without clean: `\$vbulletin->GPC\[` used in SQL/HTML without a matching `clean_gpc`/`TYPE_` nearby; `\$_(GET|POST|REQUEST)\[` reaching a sink directly
- Code-exec: `\beval\(`, `create_function`, `call_user_func`, `widget_php`, `widgetConfig`, `subWidgets`, template `<if condition=` / `{vb:php`
- Object injection: `\bunserialize\(`, datastore/config reads that feed it
- Router/API entry: `routestring`, `ajax/render/`, `ajax/api/`, `vB5_Frontend`, `vB_Api`
- CSRF: state-changing handlers missing a `securitytoken` / `verify_token` check
- Leftovers: reachable `install/` or `core/install/` upgrade scripts, debug/config flags

## Canonical high-value chains (from disclosed research — re-confirm in your version)

- **`widget_php` / `widgetConfig[code]` → unauthenticated RCE.** The most impactful vB5 lineage (the 2019 `ajax/render/widget_php` RCE and its patch-bypass follow-ups): a render/widget endpoint evaluating PHP taken from `widgetConfig[code]`, often reachable pre-auth. If your version still exposes a widget/module render path, trace whether any config field reaches `eval`/`call_user_func` — and whether a nested `subWidgets` structure bypasses a partial fix.
- **`unserialize` → POP chain RCE.** vB's documented object-injection lineage: attacker bytes reaching `unserialize` (request, cookie, datastore/config) with a gadget in the loaded class set. Build and test the chain against the real classes.
- **Template conditional → code execution.** vB4 `<if condition="...">` evaluating PHP: if a lower-privilege path can write template/widget content, or an admin action is reachable via CSRF/XSS, the compile step is RCE. Hunt the *reach*, the eval is a given.
- **GPC / assertor SQL injection.** A parameter read via `$vbulletin->GPC` / `input` without the correct `TYPE_` clean, interpolated into `query_read`/`query_write` or an assertor condition — the classic vB SQLi, historically pre- or low-auth on public endpoints and in bundled add-ons (forumrunner and similar).
- **Add-on / product surface.** Installed products/plugins (the plugin-hook system storing PHP executed at hook points, and third-party add-ons) are a large, weakly-reviewed surface — plugin code runs in core context, so a writable plugin/hook path is RCE.

## vB4 ↔ vB5 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Routing and API moved.** vB4's `*.php` script-per-page gave way to vB5's `routestring` router and `ajax/api` + `ajax/render` surface — the RCE-bearing endpoints live in the vB5 model. Re-locate the request/dispatch layer before reusing any vB4 route.
- **Template syntax changed, the risk didn't.** vB4 `<if condition>` PHP-eval vs vB5 `{vb:...}` tags and widget modules — the *concept* (template/widget body compiled or evaluated) is what to re-find, not the vB4 tag.
- **Input/DB layer changed.** vB4 `clean_gpc` + `query_read` vs vB5 request handling + `vB_dB_Assertor`. Re-verify how each version escapes, and whether a compat shim in vB5 still routes through old cleaning.
- **Patch-bypass history is the map.** vB's most valuable findings have repeatedly been *incomplete-fix bypasses* (the widget RCE was re-exploited after its first patch). For each disclosed fix, check whether your version fully closes it or leaves a variant — a bypass of a known patch is a strong, well-scoped finding.
