# Drupal — Worked Stack Notes

#### When to use this file

A **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for Drupal. Read that file for the methodology; this one gives the target-specific coordinates. Drupal is GPL/open source, so this map was **read from the source** — verified against the tree at `dc93/drupal` HEAD (`Drupal::VERSION = 12.0-dev`; the 10.x/11.x stable lines share this architecture).

> **Accuracy discipline — source-read, but version-specific.** Confirmed against **12.0-dev**. Drupal 8+ share the Symfony-based render/routing/entity architecture; Drupal 7 is entirely different (`hook_menu`, `db_query`, no render-array trusted-callback gate). Confirm every identifier against the exact version you audit; a Drupal 7 target needs a different map. Treat a major-version pair as a differential axis ([DIFFERENTIAL-AUDIT.md](DIFFERENTIAL-AUDIT.md)).

## Framework coordinates (verified against 12.0-dev)

- **Render arrays and trusted callbacks — Drupal's signature RCE class.** The renderer (`core/lib/Drupal/Core/Render/Renderer.php`) invokes callbacks named by `#access_callback`, `#pre_render`, `#post_render`, `#lazy_builder`, `#element_validate` via `doCallback()` → `doTrustedCallback()`. Since Drupalgeddon2 (SA-CORE-2018-002) core **requires these callables to implement `TrustedCallbackInterface`** (or be a closure) — `Renderer::doCallback` at ~L887 throws otherwise. The hunt: **anywhere untrusted input can inject render-array structure** (a `#`-prefixed key) that reaches the renderer, or contrib/legacy code that invokes a `#callback` *without* the trusted-callback gate. User-controlled arrays merged into a render array are the Drupalgeddon2/3 lineage — trace request data into `#` keys.
- **Database — placeholders, but identifiers and legacy paths aren't.** `Connection::query($query, $args, $options)` (`core/lib/Drupal/Core/Database/Connection.php`) uses **named placeholders** (`:id`), and the query builder `\Drupal::database()->select('node', 'n')->condition('nid', $nid)` binds values. `escapeLike()` for LIKE. **Injection surface:** a query string built with concatenation instead of placeholders; **field/table/`orderBy`/`condition()` operator** taken from input (not placeholdable → must be allowlisted); and the historical array-expansion class (Drupalgeddon SA-CORE-2014-005) where an array *key* reached the SQL. `{curly}` table names are prefix tokens, not user input.
- **Templates — Twig autoescape ON, but `inline_template` is SSTI.** `TwigEnvironment` sets `autoescape => 'html'` (`core/lib/Drupal/Core/Template/TwigEnvironment.php`), so normal `{{ var }}` output is escaped. Two escapes from that: **`inline_template` / `createTemplate($template_string)`** compiles a template from a *string* — if that string is attacker-controlled it's server-side template injection; and **`#markup` / `Markup::create()` / `|raw`** emit unescaped HTML (`#plain_text` is the safe alternative per `Html.php`). XSS lives at these, not in ordinary Twig output.
- **Deserialization — core mostly hardened, contrib is the risk.** Config storage uses `unserialize($raw, ['allowed_classes' => FALSE])` (`core/lib/Drupal/Core/Config/DatabaseStorage.php`) — object-blocking. But the queue/batch system and menu tree use **raw `unserialize`** on stored data (`core/lib/Drupal/Core/Queue/*`, `Menu/MenuTreeStorage.php`) — trusted sources in core. Object injection is a lead where a module feeds attacker-influenced bytes to a raw `unserialize` with a gadget in scope; the pattern to grep is `unserialize(` **without** `allowed_classes => FALSE`.
- **Access / permissions** — `$account->hasPermission($perm)` (`AccountProxy`), route requirements `_permission` / `_access` / `_entity_access` in `*.routing.yml`, `AccessManager`, and `hook_entity_access` / entity access handlers. The authz hunt: a route or controller action with no access requirement, an `_access: 'TRUE'` on a state-changing route, or an entity operation not checking `->access($op)`.
- **CSRF** — routes carrying a `_csrf_token` requirement are checked by `CsrfAccessCheck`; tokens via `CsrfTokenGenerator::get()/validate()` and `\Drupal::csrfToken()`. GET routes that change state need a CSRF token in the URL — a state-changing route (especially a link-based action) without `_csrf_token` is a lead.
- **Input / request** — Symfony `Request` via `\Drupal::request()` / controller args; form input through the Form API (`$form_state->getValue()`). Form API `#` properties are the dangerous surface (above). `Html::escape()`, `Xss::filter()` / `Xss::filterAdmin()` are the escaping/filtering helpers — output skipping them on user data is XSS.
- **Modules / hooks** — contrib modules extend via hooks and services; module code runs with full core authority. Contrib is the overwhelming majority of a real Drupal site's attack surface.

## Drupal-specific starter grep

Layer on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- Render-array injection: request/user data merged into arrays with `#` keys; `#pre_render|#post_render|#lazy_builder|#access_callback` set from non-constant sources; `doCallback`/callback invocation outside the trusted-callback gate
- SQL: `->query\(` / `db_query\(` with `.\s*\$` concatenation instead of `:placeholders`; `orderBy\(|addExpression\(|->condition\(` with input-derived field/operator; array *keys* reaching SQL
- SSTI / XSS: `inline_template`, `createTemplate\(` with a non-constant string; `Markup::create\(|#markup|->__toString`/`|raw` on user data; output missing `Html::escape`/`Xss::filter`
- Object injection: `\bunserialize\(` WITHOUT `allowed_classes` on non-queue/menu data
- CSRF: state-changing routes/links missing `_csrf_token`; GET actions with side effects
- Authz: routes with `_access: 'TRUE'` on writes, controllers with no `_permission`/`_entity_access`, entity ops skipping `->access(`

## Canonical high-value chains (source-confirmed patterns)

- **Render-array / form injection → RCE (Drupalgeddon2/3 lineage).** Untrusted input reaching a `#`-prefixed render/form key so a callback fires — worst when it hits `#lazy_builder`/`#pre_render`. Core's `TrustedCallbackInterface` gate is the mitigation; the finding is input reaching render structure, or contrib invoking callbacks without the gate.
- **Concatenated / identifier SQL injection.** A query built with string concatenation instead of placeholders, or a field/`orderBy`/operator from input without an allowlist. The array-key expansion path is the SA-CORE-2014-005 lineage.
- **`inline_template` SSTI.** A Twig template string assembled from user input and rendered via `createTemplate`/`inline_template` — autoescape protects output, not template source.
- **Unescaped `#markup`/raw → XSS.** User data placed in `#markup` or passed through `Markup::create()`/`|raw` instead of `#plain_text` / `Xss::filter`.
- **Missing access/CSRF on a route.** A route with `_access: 'TRUE'` or no CSRF token on a state-changing action; entity operations not checking access — the most common contrib bug.

## Drupal 7 ↔ 8+/10/11/12 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Two different worlds.** Drupal 7 (`hook_menu`, `db_query`, `drupal_render` without the trusted-callback gate, no Twig) vs 8+ (Symfony, render arrays with `TrustedCallbackInterface`, Twig autoescape). A render-callback path safe in 8+ because of the trusted-callback gate is *not* gated in 7 — re-map entirely per line.
- **Among 8+ minors, watch the render/callback and access refactors.** The trusted-callback enforcement and access-checking have tightened over 8→12; a contrib module written against an older minor may invoke callbacks or skip access checks the newer core would reject.
- **Deserialization hardening.** Core added `allowed_classes => FALSE` to config unserialize; check whether every stored-blob read on the line you audit has the same guard, and whether a compat path still accepts object-bearing serialized data.
- **Un-backported / SA-CORE fixes.** Drupal publishes SA-CORE advisories per branch — for each fix, confirm the branch you audit received it; an un-backported fix or a contrib module lagging a core hardening is a strong, patch-proven finding.
