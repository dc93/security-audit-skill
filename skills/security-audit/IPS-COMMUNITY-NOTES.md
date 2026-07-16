# IPS Community — Worked Stack Notes

#### When to use this file

This is a **concrete instantiation** of [SERVER-SIDE-WEB-FRAMEWORK.md](SERVER-SIDE-WEB-FRAMEWORK.md) for one specific stack: Invision Community (the IPS Community Suite). It exists to save Phase 2 agents from rediscovering the framework — it names the actual sink/source APIs, gives a ready grep set, and maps the canonical exploit chains. Everything below is the neutral companion's classes applied to IPS; read that file first for the methodology, this one for the target-specific coordinates.

> **Accuracy discipline — read this before trusting a name below.** These identifiers are drawn from **IPS 4.x** and are a *starting map, not ground truth*. **Verify every class, method, and signature against the target tree before building a finding** — minor versions move things, and **v5 is a large rewrite that renames and relocates much of this** (see the differential notes at the end, and run [DIFFERENTIAL-AUDIT.md](DIFFERENTIAL-AUDIT.md) since both lines are typically in scope). A name that doesn't resolve in the target is a signal you're on the wrong version, not a finding. Never cite an API in a report that you haven't confirmed exists in the audited source.

## Framework coordinates (IPS 4.x — confirm in-repo)

- **Request / input** — `\IPS\Request::i()` (singleton, array + property access to request vars); `->url()`, `->isAjax()`. CSRF material travels as a `csrfKey` request value checked against `\IPS\Session::i()->csrfKey`.
- **Database / ORM** — `\IPS\Db::i()->select( $columns, $table, $where, $order, $limit, $flags )`. `$where` may be a raw string or `array( 'col=?', $val )`; **`$columns` and `$order` are not parameterizable** — an interpolated `$order` or column list is the identifier-injection sink. `->query()` runs raw SQL. ActiveRecord is `\IPS\Patterns\ActiveRecord` (`::load()`, `->save()`); nodes are `\IPS\Node\Model`.
- **Datastore / cache** — `\IPS\Data\Store::i()->key` and `\IPS\Data\Cache::i()` persist **serialized PHP**; anything that can write a store entry and get it read back is object-injection surface. `\IPS\Settings::i()->key` for settings.
- **Members / permissions** — `\IPS\Member::loggedIn()`, `\IPS\Member::load($id)`, `->member_id`, group flags `->group['g_*']`, `->modPermission()`, ACP restrictions `->hasAcpRestriction(...)`.
- **Theme / template engine** — `\IPS\Theme` compiles theme templates (custom `{{...}}` / `{$var}` / `{expression="..."}` / `{template="..."}` / `{lang="..."}` syntax) **into PHP methods**. The ACP theme/template editor writes template *source* that is then compiled → **post-auth RCE by design**. `{expression="..."}` emits an evaluated PHP expression.
- **Hooks / add-ons** — code hooks under `hooks/` are compiled into classes that extend core. Installing a **plugin (`.xml`)** or **application (`.tar`)**, including via the Marketplace/remote fetch, can ship code hooks and PHP → **RCE on install** (admin). `\IPS\Plugin`, `\IPS\Application`.
- **HTTP client / SSRF** — `\IPS\Http\Url::external($url)` / `::internal()` then `->request()->get()`. Remote avatar/image import, oEmbed/link preview, RSS/feed import, and converters are the URL-fetch surfaces.
- **Files / uploads** — `\IPS\File::create(...)`, `\IPS\Helpers\Form\Upload`, image handling via `\IPS\Image`. Check extension/MIME/magic-byte validation and where the file lands.
- **Text / output** — `\IPS\Text\Parser` (BBCode/HTML sanitizer, CKEditor content), `\IPS\Text\LegacyParser`. Allowed-HTML gaps and BBCode edge cases are the stored-XSS surface.
- **Login / SSO / converters** — `\IPS\Login` and `\IPS\Login\Handler` subclasses (OAuth/SSO); the `\IPS\convert` converters (import from other forum software) are historically fragile parsing untrusted dumps.
- **Routing / controllers** — front and admin controllers live at `applications/<app>/modules/{front,admin}/<module>/<controller>.php` (`\IPS\Dispatcher\Controller`, `execute()`/`manage()`); the REST API at `applications/<app>/api/*` (`\IPS\Api\Controller`, OAuth / `\IPS\Api\Key`). **The API frequently enforces different checks than the front controller for the same object — diff them.**

## IPS-specific starter grep

Layer these on top of the neutral grep set in `SERVER-SIDE-WEB-FRAMEWORK.md`:

- Raw / identifier SQL: `Db::i\(\)->query\(`, `->select\(` with a `$`-built `$order`/`$columns` argument, `\$order\s*=`, `sortby|sortdirection` reaching `->select(`
- Serialized-store reads: `Data\\Store::i\(\)->`, `\bunserialize\(`, phar-triggering file calls on `\IPS\File`/image paths
- Template code sink: `\{expression`, `->getTemplate\(`, writes into theme/template storage, `\IPS\Theme` compile paths
- Add-on install / code exec: `Plugin`, `Application::install`, `->installHook`, Marketplace/download-then-execute paths, `eval\(`, `call_user_func`
- SSRF: `Http\\Url::external\(`, `->request\(\)->get\(`, remote-URL import options
- Perms/CSRF: controllers with `execute()`/`manage()` missing a permission check; `csrfKey` comparisons; state-changing actions reachable by GET
- Secrets/backdoors: `\bpassword\b|apikey|-----BEGIN`, `TODO|FIXME|HACK` near `permission|csrf|restrict`

## Canonical high-value chains

- **ACP CSRF/XSS → template edit → RCE.** The theme editor compiling template source to PHP is admin-post-auth; the *finding* is a path that reaches it without full admin intent — a CSRF on the template-save action, a stored XSS firing in an admin's ACP session, or a lower-privileged role that can write template/hook storage. This is the classic critical for this stack — hunt the *reach*, the sink is a given.
- **Write-to-datastore → object injection.** Any lower-privilege primitive that writes a `\IPS\Data\Store` / cache entry which is later `unserialize`d, chained with a gadget present in the loaded classes / `vendor/`. Build it with phpggc against the real class set.
- **Identifier SQLi via `sortby`/`$order`.** A sortable/filterable list or export whose `$order` or column argument comes from a request value without an allowlist — often reachable pre- or low-auth on public listings.
- **API vs front-end permission gap.** The same resource exposed under `applications/*/api/*` with a weaker ownership/permission check than its front controller — IDOR reached through the REST surface.
- **Converter / import parsing.** `\IPS\convert` and import/restore paths trusting attacker-shaped dumps (deserialization, path handling, SQL from imported field names).

## v4 ↔ v5 differential notes (run alongside DIFFERENTIAL-AUDIT.md)

- **Theming/front-end is the biggest delta.** v5 reworked the front-end and template layer; the *concept* (template source compiled/evaluated) is the thing to re-locate in v5, not the v4 tag syntax. Confirm what the new sink looks like before reusing v4 payloads.
- **Re-verify every API name.** Namespaces, method signatures, and the datastore/serialization format may differ. Treat the map above as v4 and confirm each in the v5 tree.
- **Hunt the migration seams.** A guard that lived in a v4 controller may have moved into shared middleware/dispatch in v5 (a route that skips it is the bug), and v4 → v5 upgrade/converter code that ingests v4-format data (serialized blobs, old tokens/sessions, legacy template markup) is prime half-migration surface.
- **Fixes crossing versions.** For each security fix visible in one line's history/changelog, check whether the other line received it — an un-backported fix or a regression reintroduced by the rewrite is a strong, patch-proven finding.
