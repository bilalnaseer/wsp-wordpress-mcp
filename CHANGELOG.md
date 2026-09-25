# Changelog — WSP MCP - AI Agents Connector

> **AI Agents:** Read `AGENTS.md` → `CHANGELOG.md` → `HISTORY.md` in that order before touching any source file.
> After every code change you **must** update `AGENTS.md` (if architecture/tools/hooks changed) and add an entry here.

All notable changes to this plugin are listed here. Ordered newest-first.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Removed — Website sync automation (repo dev tooling only; plugin unchanged)

- Deleted `.github/workflows/sync-abilities.yml` and the `bin/` generators (`lib-abilities.php`,
  `generate-abilities-md.php`, `patch-website.php`). freewordpressmcp.com is being rebuilt and its
  tool list is no longer generated from `registry.php`, so pushes to `main` no longer open PRs on
  the website repo. Dropped the now-unused `abilities.md` / `abilities.json` `.gitignore` entries
  and the "Website sync automation" section of `AGENTS.md`.
- Nothing in the shipped plugin zip changed — no version bump.

---

## [2.9.0] — 2026-09-17

### Added — Navigation Menus ability group (`includes/abilities/menus.php` — new file)

- Nine tools for reading and editing WordPress navigation menus: `wsp_get_menus`,
  `wsp_get_menu_items`, `wsp_create_menu`, `wsp_delete_menu`, `wsp_add_menu_item`,
  `wsp_update_menu_item`, `wsp_delete_menu_item`, `wsp_get_menu_locations`, and
  `wsp_assign_menu_location`. All gated by `edit_theme_options` — the same capability WP core's
  own menu editor and REST menus controller require. Menus are a site-wide structure with no
  per-item ownership, so no additional object-level guard is needed. All nine are OFF by default.
- `wsp_add_menu_item` accepts `type` of `custom` (needs `title` + `url`), `post`, `page`, or
  `category` (needs `object_id`), plus optional `parent` and `order`. The `menu` argument on every
  tool accepts an ID, slug, or name (via `wp_get_nav_menu_object()`).
- Contributed by [@dulaj44](https://github.com/dulaj44) in [#41](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/41).

### Added — `wsp_get_post` (`includes/abilities/posts.php`)

- Reads one post by ID in any status (draft, pending, private, trash, …) and returns its full
  `content` alongside title, URL, status, date, author, categories, tags, and excerpt. Closes
  [#38](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues/38): `wsp_update_post` could
  already write to any post by ID, but `wsp_get_posts` is a published-only list with excerpts, so
  there was no way to read a draft back before overwriting it.
- Registered with the `edit_posts` primitive capability, then enforces the per-object `read_post`
  meta capability inside the callback, so a Contributor cannot read another author's private or
  draft post. OFF by default. Contributed by [@dulaj44](https://github.com/dulaj44).

### Fixed

- **`wsp_get_post` audit-log classification.** The not-found and permission-denied paths returned
  a plain `array( 'success' => false, … )` instead of a `WP_Error`, so `do_tools_call()` in
  `class-mcp-server.php` fell through to the success branch and recorded every denied read as
  `STATUS_SUCCESS` in the Audit Log. They now return `WP_Error( 'not_found', … )` and
  `WP_Error( 'forbidden', … )` like every other object-level guard in the plugin, so denials are
  logged as `STATUS_DENIED` and surface correctly in **MCP > Audit Log** and **MCP > Analytics**.
- **`wsp_add_menu_item` type/object mismatch.** With `type: page`, an `object_id` pointing at a
  blog post (or vice versa) was accepted and silently stored under the object's real post type. The
  resolved post type must now match the requested `type`, otherwise the tool returns
  `object_id: page not found.` / `object_id: post not found.`

### Changed

- README and readme.txt now point to the updated full tutorial video.

## [2.8.0] — 2026-09-11

First release since 2.7.1. Consolidates every change made since then: the versions numbered
2.7.2, 2.7.3, 2.7.4 and 2.9.0–2.11.0 in earlier drafts of this file were never tagged or shipped,
and their contents are folded in below rather than presented as releases users could have had.

### Added — Native OAuth 2.1 authorization server / one-click Claude Connector (`includes/server/class-oauth-server.php`, `includes/server/class-oauth-store.php` — new files)

- **Connect Claude by pasting a URL and nothing else.** The plugin now runs its own OAuth 2.1
  authorization server, so Claude's **Customize > Connectors > Add custom connector** screen can
  discover it, register itself, and send the user through a real WordPress login — no config file,
  no API key to copy, no `Authorization` header to paste. Implements enough of RFC 8414 (AS
  metadata), RFC 9728 (protected-resource metadata), RFC 7591 (Dynamic Client Registration) and
  RFC 6749 + PKCE S256 (RFC 7636) for Claude's documented `oauth_dcr` flow.
- **Endpoints** are matched against the raw request path on `init` priority 0, not through the REST
  API: the two `.well-known/*` discovery documents must sit outside any REST namespace, and the
  token endpoint must accept `application/x-www-form-urlencoded` bodies. Discovery is served at
  every path spelling a given install can actually reach, so subdirectory installs resolve to
  themselves rather than to whatever owns the domain root.
- **Tokens are bound to the user who clicked Allow**, so tool calls run through the same
  `require_cap()` checks as any other logged-in user — a stricter model than the static API key,
  which maps every request to the lowest-ID administrator. Public client only (no `client_secret`
  is ever issued); authorization codes and refresh tokens are single-use and rotated; all tokens
  are stored as SHA-256 hashes, so a database read alone is not a usable credential.
- **Three new tables** (`{prefix}wsp_mcp_oauth_clients` / `_codes` / `_tokens`), created via
  `dbDelta` alongside the existing sessions and audit-log tables, plus a daily cleanup cron
  (`wsp_mcp_oauth_cleanup`). All are dropped in `uninstall.php`.
- **Off by default** — see the Security section below for why, and for the hardening applied to
  this feature before it shipped.

### Added — Analytics & Performance dashboard (`includes/admin/analytics-page.php` — new file, `includes/audit/class-audit-log.php`)

- **New `MCP > Analytics` screen** (`manage_options`): summary cards for total requests, most-used
  tool, average response time and error rate; a per-category tool-usage breakdown rendered with
  lightweight CSS progress bars; and a recent-requests performance log. A range selector covers the
  last 7 / 30 / 90 days or all time.
- **Computed entirely from the existing self-hosted audit log** (`{prefix}wsp_mcp_audit_log`) — no
  external analytics service, no outbound request, no third-party script.
- The audit-log table gained two columns to support it: `category` (the ability-registry group the
  tool belongs to, e.g. "Elementor", "WooCommerce") and `duration_ms` (wall-clock execution time).
  `WSP_MCP_Audit_Log::log()` takes both as new optional parameters, and `do_tools_call()` now times
  every call and resolves its category via the registry.
- `do_tools_call()` also now records an object-level permission failure (`WP_Error` code
  `forbidden`, returned by the `guard.php` / ACF / Yoast / Rank Math guards) as an authorization
  **denial** rather than a generic error, so those surface correctly when auditing.
- New reporting helpers: `get_analytics_overview()`, `get_category_breakdown()`,
  `get_recent_performance()`.

### New — "Claude Connectors" tab on `MCP > Connection` (`includes/admin/connection-page.php`)
- **Zero-config-file connection path**, now the default first tab (`data-tab="claudeweb"`, replacing
  the old default-active state on the classic Claude Desktop tab). Claude's own **Customize >
  Connectors > Add custom connector** screen — a different product surface from the
  `claude_desktop_config.json` file, shared across claude.ai, Claude Desktop, and Claude mobile —
  accepts a bare **Remote MCP server URL** plus a **Request header** (`static_headers`, beta) instead
  of an OAuth flow or a locally-edited JSON file. This tab surfaces exactly the two values that
  screen needs: `#wsp-code-ccurl` (the endpoint, same `$endpoint` used everywhere else on this page)
  and `#wsp-code-ccheader` (`Bearer <api_key>`, ready to paste as the `Authorization` header value),
  each with its own `makeCopyBtn()`-wired Copy button. No PHP/JS logic changed elsewhere — this is
  additive markup only, reusing existing helpers.
- **Two hard constraints called out directly on the tab**, since neither is something this plugin
  can fix: (1) Claude's servers connect from Anthropic's cloud infrastructure, not the user's
  machine, so the site must be reachable on the public internet — `localhost` / private-network
  installs cannot use this path and still need the config-file tab. (2) The Request-headers field is
  a beta Anthropic is rolling out gradually per-organization; accounts that don't have it yet won't
  see the field in their Add-custom-connector dialog and should use the (now second, no-longer-
  default) **Claude Desktop (config file)** tab instead.
- The old Claude Desktop tab is unchanged in content — only its `id`/button label and default-active
  state moved (`wsp-tab-active` / `wsp-tab-panel-active` now live on `claudeweb`, not `claude`).
- No server-side auth capability changed: this exposes the **existing** Bearer-API-key mechanism
  through a different Claude UI, it does not add OAuth. See the "OAuth: Coming soon" note already on
  the Configuration Generator (v2.9.0) for why a true zero-copy, zero-beta path would require a real
  OAuth 2.1 authorization server implementation — out of scope here.
- Source verified against Anthropic's own docs at time of writing: `/docs/connectors/custom/remote-
  mcp#authenticating-with-request-headers` and `/docs/connectors/building/authentication`.

### New — One-Click Automated Connector on `MCP > Connection` (`includes/admin/connection-page.php`)
- **Download button on every snippet** (all six static tabs plus the live generator): each
  `.wsp-config-header` now holds a `.wsp-config-actions` pair — **Download** next to the existing
  **Copy**. A new client-side `downloadFile(filename, content)` helper builds a throwaway `Blob` +
  `<a download>` and clicks it, so one click writes the exact file (`claude_desktop_config.json`,
  `mcp.json`, `config.toml`, `mcp_config.json`, `openclaw.json`, `opencode.json`) straight to disk —
  nothing to select, nothing to paste. The generator's download button derives its filename live
  from the currently-rendered snippet (`snip.filename.split("/").pop()`), so it always matches
  whichever tool/auth combination is on screen.
- **True one-click connect for Cursor**: Cursor supports a documented deep link,
  `cursor://anysphere.cursor-deeplink/mcp/install?name=<slug>&config=<base64-of-{url,headers}>`
  (https://cursor.com/docs/mcp/install-links), that opens Cursor directly and lets *it* write
  `~/.cursor/mcp.json` — no config file to open or paste into at all. A new `.wsp-connect-callout` /
  `.wsp-connect-btn` box surfaces this both on the static **Cursor** tab (link built server-side in
  PHP from the already-known API key) and in the live generator (link rebuilt client-side on every
  `render()`, shown only once a real Bearer or Basic auth header is available — i.e. immediately for
  API Key, or once both Application Password fields are filled in).
  - Caught and fixed during implementation: WordPress's `esc_url()` strips any URL protocol that
    isn't in its default `wp_allowed_protocols()` list, which does not include `cursor` — echoing the
    deep link through bare `esc_url()` would have silently mangled the scheme and left the button
    dead. Fixed by passing the protocol explicitly: `esc_url( $cursor_deeplink, array( 'cursor',
    'https', 'http' ) )`.
  - No equivalent deep link exists yet for Claude Desktop, Codex, Antigravity, OpenClaw, or
    OpenCode — none of them publish a documented one-click MCP-install protocol handler, so only
    Cursor gets the connect button; the other five keep Download + Copy.
- Purely additive: existing Copy-button behavior, tab switching, and the Configuration Generator's
  snippet output (v2.9.0) are unchanged.
- `AGENTS.md` "Connection page" section documents the new element IDs, JS helpers, and the
  `esc_url()` protocol-allowlist gotcha for future agents.

### New — Live Configuration Generator on `MCP > Connection` (`includes/admin/connection-page.php`)
- **A new `.wsp-gen-box` section** sits above the existing six static per-client tabs: pick an **AI Tool** (Claude Desktop, Cursor, Codex, Antigravity, OpenClaw, OpenCode) from a `<select>`, and an **Authentication Method** from a 3-way pill group (**API Key**, **Application Password**, **OAuth**). The output snippet re-renders live, entirely client-side, on every change — no page reload, no server round trip — with its own "Copy" button reusing the page's existing `copyText()` clipboard helper.
- **API Key** (default, marked Recommended) reuses the same Bearer-token construction as the static tabs below it.
- **Application Password** reveals a WordPress-Username + Application-Password input pair. Neither value is ever submitted to the server: the `Authorization: Basic <base64>` token is computed in the browser with `btoa()` and embedded literally into the generated snippet, the same way the API-key path already embeds its own secret directly (no `${VAR}` env interpolation, avoiding the known mcp-remote "missing env var" failure mode). For **Claude Desktop** specifically, the generated config's `env` block also lists `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` as a human-readable record of the credential — these three keys are informational only; the actual transport is still the `mcp-remote` bridge to this plugin's own native `wsp-mcp/v1/mcp` endpoint, **not** a reintroduction of the `@automattic/mcp-wordpress-remote` package removed in v2.2 (see `[2.2.0]` below).
- **OAuth** is shown as a selectable method — matching what modern MCP client pickers expose — but is **not implemented server-side** (`class-auth.php` has no OAuth flow). Selecting it swaps the code preview for a "coming soon" notice and disables the copy button, rather than generating a config that would silently fail to authenticate.
- A conditional HTTPS notice appears under the Application Password fields only when the current admin page itself is served over plain HTTP on a non-local host, since WordPress core itself refuses to let such a site create Application Passwords.
- `readme.txt` "Key features" and changelog updated; `AGENTS.md` "Connection page" section documents the new markup/JS contract for future agents.

### Security — Hardening of the native OAuth authorization server before first release

Pre-release review of the OAuth/Connectors work in this same branch. None of these shipped to users
(the OAuth server has never been in a released build), but each was exploitable as written.

- **The OAuth server is now off until an administrator turns it on** (`includes/server/class-oauth-server.php`, `includes/admin/connection-page.php`). `WSP_MCP_OAuth_Server::init()` was hooked unconditionally on `plugins_loaded` with no setting to disable it, so simply taking the update would have published two discovery documents plus unauthenticated registration, authorize and token endpoints on every site. New option `wsp_mcp_oauth_enabled` (default `false`, constant `WSP_MCP_OAUTH_OPTION`, helper `wsp_mcp_oauth_is_enabled()`) gates routing, discovery, the `resource_metadata` pointer in the 401 challenge, **and** acceptance of already-issued OAuth tokens — so the off switch actually disconnects, rather than just refusing new grants. Toggled from MCP > Connection via nonce-protected `admin_post_wsp_mcp_toggle_oauth` (`manage_options`); switching off calls the new `WSP_MCP_OAuth_Store::revoke_everything()`.
- **The consent screen now shows where the authorization is going** (`render_consent_page()`). It previously displayed only `client_name` — a value the client supplies to the *unauthenticated* registration endpoint, i.e. a self-assigned label with no verification behind it — and never showed the redirect target at all. An attacker could register a client named e.g. "WordPress Core Security Update" pointing at their own callback, send a logged-in editor or administrator the `/authorize` link, and a single "Allow" click would mint an access token bound to that user's account (1h access + 30-day rotating refresh, with every enabled write tool). The redirect **host** and full URI are now shown prominently, with an explicit note that the application's name is unverified.
- **Approving a connector now requires a real capability, not just a login** (`handle_authorize()`). The only check was `is_user_logged_in()`. Six tools register with an empty capability — `require_cap()` treats that as "any authenticated user" — and `wsp_execute_get_posts()` accepts `status=all`, returning every draft, pending and scheduled post with no author filter. On any site with open registration (WooCommerce, membership, LMS), anyone who could sign up could connect Claude and read unpublished content. New `wsp_mcp_oauth_min_capability()` (default `edit_posts`, filterable via `wsp_mcp_oauth_min_capability`) is enforced before the consent screen renders and again on the consent POST.
- **The consent and error pages can no longer be framed** (`send_frame_protection_headers()`). Both render on `init`, outside wp-admin, so WordPress's own admin framing protection never applied to them and the "Allow" button was clickjackable. Both now send `X-Frame-Options: DENY`, `Content-Security-Policy: frame-ancestors 'none'`, and `Referrer-Policy: strict-origin-when-cross-origin`.
- **Dynamic Client Registration is throttled and bounded** (`handle_register()`, `includes/server/class-oauth-store.php`). The per-IP rate limiter existed but was called only from the token endpoint, leaving `/wsp-mcp-oauth/register` — necessarily unauthenticated under RFC 7591 — as an open, unlimited row-insert for anyone on the internet, with no cleanup path (`cleanup_expired()` never touched the clients table). `enforce_rate_limit()` now takes a bucket, max and window, and registration gets its own tight budget (5 per 10 minutes per IP) separate from the token endpoint's deliberately generous 60/60s. Added `MAX_CLIENTS` (250) as an absolute ceiling, plus `count_clients()` and `prune_unused_clients()`, which deletes registrations older than `UNUSED_CLIENT_TTL` (24h) that never produced a token — run both opportunistically at registration and on the daily `wsp_mcp_oauth_cleanup` cron, so a burst of junk cannot hold the ceiling against legitimate clients.
- **Refresh-token replay now revokes the whole token family** (`rotate_refresh_token()`). Rotation revoked only the single presented row, so replaying a stolen-but-already-spent refresh token just returned `invalid_grant` while the successor token stayed live for whoever held it. A replay is now detected via the new `find_rotated_token()` and triggers `revoke_all_for( client_id, user_id )`, per RFC 9700 §4.14.2.
- `uninstall.php` also removes the new `wsp_mcp_oauth_enabled` option.

### Known gap
- There is still **no admin screen listing registered OAuth clients or live tokens, and no per-connector revoke button** — the only controls are the global off switch and `revoke_everything()`. Tracked in `AGENTS.md` ("OAuth authorization server — security invariants"); should land before OAuth is promoted as the primary connection path.

### Fixed — stray output from other plugins/themes could corrupt the JSON response ("no tools available")

- **A site can have any number of other plugins active, of any quality — this plugin has no
  control over that, and never should have assumed it.** If any one of them printed a PHP
  notice/warning/deprecation string directly to output during an ordinary WordPress hook
  (`init`, `wp_loaded`, `rest_api_init`, a template part, …) on a request this plugin was about to
  answer with strict JSON, that stray text landed in front of the JSON body. Every MCP client's JSON
  parser then failed on the response — Claude showed the connector as connected, with *"This
  connector has no tools available,"* indistinguishable from an actual bug in this plugin. The same
  stray output could also trigger PHP's "headers already sent" warning on this plugin's own
  `header()` calls (the 401 challenge, the OAuth discovery documents, the token endpoint, …).
- **New file `includes/response-guard.php`**, required — and its `wsp_mcp_output_guard_start()`
  called — before any other include in the main plugin file, so it is the earliest this plugin's own
  bootstrap can act. `wsp_mcp_is_own_endpoint_request()` checks the raw request URI for this plugin's
  MCP REST route or its OAuth discovery/registration/authorize/token endpoints (a handful of
  `strpos()` calls, since this runs on every request to the site); when it matches, an output buffer
  opens immediately. `wsp_mcp_output_guard_flush()` discards exactly that one buffer level right
  before the real response goes out — nothing else on the site (e.g. a compression buffer opened by
  the server) is touched — leaving clean JSON regardless of what any other active plugin printed in
  between.
- Wired at the two places every JSON response from this plugin passes through: `WSP_MCP_Server`'s new
  `rest_pre_echo_response` filter (covers every JSON-RPC response and the 401 challenge, since all of
  them are `WP_REST_Response` objects — a no-op on every REST response that isn't this plugin's own,
  so it's safe to leave unconditional site-wide) and `WSP_MCP_OAuth_Server::send_json()` (the single
  choke point every OAuth JSON response — discovery documents, dynamic client registration, the token
  endpoint — already goes through).
- **Known residual gap:** this cannot catch output printed before this plugin's own file is
  `include`d by WordPress's plugin loader (e.g. a stray `echo` at the top level of some other
  plugin's main file that happens to load first, alphabetically or otherwise) — there is no earlier
  hook a regular, non-mu plugin has access to. Everything from `plugins_loaded` onward, which is
  where real-world stray output actually happens, is covered.

### Fixed — OAuth discovery on subdirectory installs ("connector has no tools available")

- **A WordPress install in a subdirectory (`https://example.com/test/`) could complete the Claude
  Connector OAuth flow and then fail every MCP call.** Claude showed the connector as connected,
  with *"This connector has no tools available."*
- **Cause.** The 401 challenge advertised `resource_metadata` at the bare origin
  (`https://example.com/.well-known/oauth-protected-resource`), and both discovery documents were
  only served at that origin-root path. A subdirectory install never receives origin-root requests —
  the web server routes them to the document root, not to `/test/index.php`. Claude therefore read
  whatever else owned the document root (a static file, or a *second* WordPress install running this
  same plugin), ran the whole authorization-code exchange against **that** server, and presented the
  resulting token — minted from that other database — to `/test/`, which rejected it with 401 on
  `initialize` and `tools/list`.
- `protected_resource_metadata_url()` is now `home_url()`-relative, so the one pointer a client
  follows verbatim (RFC 9728 §5.1) always names a URL this install can actually serve.
- New `WSP_MCP_OAuth_Server::as_issuer()` — origin **plus** `home_url()`'s base path — is now the
  `issuer` in the authorization-server metadata and the entry in `authorization_servers`. Two installs
  on one domain (`/mcp` and `/test`) are now distinct authorization servers instead of both claiming
  the bare origin. On a root install it is byte-identical to `issuer()`, so nothing changes there.
  `issuer()` itself is unchanged and still used to rebuild an absolute URL from `REQUEST_URI` (the
  login round-trip in `handle_authorize()`), where adding the base path would double it.
- `maybe_dispatch()` now serves each discovery document at every spelling this install can reach:
  the origin root (root installs), the base-path form (`/test/.well-known/…`), the RFC 9728 §3.1
  path-inserted form derived from `rest_url()` (so a non-default REST prefix still matches), and — on
  a subdirectory install only — `{base}/.well-known/openid-configuration`, which is the one variant
  the MCP authorization spec's fallback chain both tries and can reach there. The origin-root
  `openid-configuration` path is deliberately **not** claimed, so a root-level OpenID provider on the
  same domain is left alone.

**Upgrade note.** If you previously worked around this by hand-placing a static
`.well-known/oauth-protected-resource` file in the domain's document root, delete it and reconnect —
it will otherwise keep pointing every connector on the domain at whichever install it names.

---

## [2.7.1] — 2026-09-04

### Security — object-level capability checks on write tools (merged from upstream `bilalnaseer/wsp-wordpress-mcp`)
- **Fixed a broken access control issue reported by Patchstack (Ananda Dhakal): "Authenticated (Contributor+)
  Broken Access Control", WSP MCP `<= 2.7.0`.** `wsp/update-post`, `wsp/delete-post`,
  `wsp/update-page`, `wsp/delete-page`, `wsp/update-media`, `wsp/delete-media`, and
  `wsp_execute_set_featured_image` previously checked only the broad primitive capability
  (`edit_posts` / `delete_posts`) before acting, not whether the caller could act on *that specific*
  object. A Contributor authenticating with their own Application Password could edit, publish,
  unpublish, or trash a post/page/attachment owned by an Administrator or Editor once the
  corresponding write tool was enabled in MCP > Settings.
- **New file `includes/abilities/guard.php`** — shared helpers used by every affected callback:
  `wsp_mcp_guard_edit_post( $id, $allowed_types )` / `wsp_mcp_guard_delete_post( $id, $allowed_types )`
  load the target, verify its post type, and enforce `current_user_can( 'edit_post', $id )` /
  `current_user_can( 'delete_post', $id )` (WordPress's own per-object meta-capability, not just the
  primitive); `wsp_mcp_guard_post_status( $post, $status )` additionally requires the post type's
  publish capability before accepting a `publish`, `future`, or `private` status. Mirrors the checks
  WordPress core's own REST controllers perform.
- `posts.php`, `pages.php`, `media.php`: `update`/`delete` (and `set_featured_image`) now resolve the
  target through the matching guard and bail with its `WP_Error` before touching anything.
- No existing tool, ability, or admin UI was removed or changed by this merge — additive only.

---

## [2.6.8] — 2026-08-12

### Fixed — `tools/list` straight after `initialize` rejected as an unknown session (`includes/server/class-session-store.php`)
- **Every request that arrived in the same wall-clock second as `initialize` failed with `Session not found or expired. Re-initialize.` (JSON-RPC `-32600`, HTTP 404).** Claude Desktop via `mcp-remote` hit this consistently because it fires `tools/list` milliseconds after the handshake; reporters saw 0/10 success at zero delay and 10/10 with a 2-second delay, which made it look like replication lag on remote-DB hosting.
- **Root cause is affected-row semantics, not visibility.** `touch_session()` slides the expiry with `UPDATE … SET expires_at = %s WHERE session_id = %s AND expires_at > %s` and returned `(bool) $updated`. `create_session()` and `touch_session()` both compute `expires_at` as `gmdate( 'Y-m-d H:i:s', time() + TTL )` — second resolution — so a touch within the same second as creation writes a byte-identical value. MySQL/MariaDB report **changed** rows, not **matched** rows, so the query returns `0`, which cast to `false`. The row existed and was unexpired the whole time. Below-one-second timing is why the failure looked random.
- **Fix:** `false` (a real DB error) still returns `false` and `> 0` still returns `true`, but `0` is now treated as ambiguous and resolved with `SELECT 1 FROM … WHERE session_id = %s AND expires_at > %s`. Missing and expired sessions are still rejected; only the no-op timestamp write is now accepted. No schema change, no TTL change, no behaviour change for clients that already worked.
- Diagnosis and the patch shape came from @WikiZell in GitHub #30, verified on WordPress 7.0.3 / PHP 8.3 / MariaDB.

---

## [2.6.7] — 2026-08-08

### Fixed — Copy buttons dead on plain-HTTP sites (`includes/admin/connection-page.php`)
- **The "Copy" button on every Connection-page snippet tab did nothing on non-HTTPS installs** (e.g. a local dev host like `http://testing-wsp-mcp-plugin.local/`). `navigator.clipboard` is only exposed in a **secure context** — HTTPS or `localhost` — so on a plain-HTTP custom hostname the API is `undefined` and `navigator.clipboard.writeText(...)` threw a `TypeError` synchronously. Because the throw happened *before* the promise was constructed, the existing `.catch()` never attached either, so the user got no error and no fallback — just an inert button. Unrelated to WP version, theme, or other plugins.
- **Fix:** new `copyText()` helper wraps both paths — it uses the Clipboard API only when `window.isSecureContext && navigator.clipboard?.writeText` is available, and otherwise falls back to an off-screen readonly `<textarea>` + `document.execCommand("copy")`. Both branches return a promise, so the "Copied!" confirmation and the failure `alert()` work unchanged. Applies to all six client tabs at once (they share `makeCopyBtn`).

### Changed — Per-group enabled/disabled tally (`includes/admin/settings-page.php`)
- **Group headers now show two colour-coded pills instead of one `enabled / total` badge**: `N Enabled` in green (`.wsp-gcount--on`) and `N Disabled` in red (`.wsp-gcount--off`), wrapped in `.wsp-gcounts`. Previously a single badge turned green whenever *any* ability was on, so `2 / 4` and `4 / 4` were visually identical at a glance.
- Whichever count is `0` also receives `.wsp-gcount--zero`, which greys the pill out — so a fully-enabled group reads "4 Enabled" in green next to a muted "0 Disabled" rather than an alarming red zero.
- `refreshCount()` rewrites both labels **and** the zero-state class live as switches flip or "Toggle All" fires, before anything is saved.
- Colour is reinforcement only — the numbers carry the same information, so there is no accessibility regression.

### Added — Sidebar promo cards on both admin pages (`includes/admin/promo-cards.php` — new file)
- **Two cards now sit in the previously-empty right gutter of MCP > Settings and MCP > Connection:** "Video Tutorials" → `freewordpressmcp.com/tutorials`, and "170+ Tools Available" → `freewordpressmcp.com/abilities-directory`.
- **New shared file** rather than duplicated markup, exposing three functions: `wsp_mcp_promo_url( $url, $content, $campaign )` (UTM builder), `wsp_mcp_promo_css()` (layout + card CSS, concatenated onto each page's existing inline stylesheet), and `wsp_mcp_render_promo_cards( $campaign )` (renders the `.wsp-side` column). Required from the main plugin file **before** both admin pages. To add or edit a card, change `wsp_mcp_render_promo_cards()` only.
- **Layout:** both pages' `.wsp-wrap` widened to `1180px` and their content wrapped in `.wsp-layout > .wsp-main` (still capped at `860px`, so the existing UI is pixel-unchanged) beside a sticky 280px `.wsp-side`. Below `1100px` the layout collapses to a single column and the cards drop under the content — necessary because the WP admin menu already eats ~160px.
- **Analytics:** links carry `utm_source=wsp_mcp_plugin`, `utm_medium=plugin_admin`, `utm_campaign=abilities_page|connection_page`, `utm_content=tutorials_card|directory_card`, and `utm_term=<plugin version>` (which doubles as a read on version adoption in the wild).
- **`rel="noopener"`, deliberately not `noreferrer`.** All current browsers imply `noopener` for `target="_blank"` anyway, but it is kept because WP.org reviewers and security scanners expect it. `noreferrer` was **removed** — it strips the `Referer` header, which would log this traffic as "direct" in Google Analytics and destroy attribution on our own destination site.
- **WP.org compliance:** contextual links on the plugin's own pages, not admin notices and not sitewide; no upsell injected elsewhere in wp-admin; no external HTTP request is made (a plain `<a href>`, so no consent/disclosure requirement is triggered); all URLs escaped with `esc_url()`.

### Security — Verified against WordPress 7.0.3 (no code changes required)
- WordPress **7.0.3** (2026-08-06) is a security release fixing 12 vulnerabilities. Two of the revised core files intersect this plugin, and in both cases the plugin **consumes the core API rather than reimplementing it**, so the fixes are inherited with no action needed:
  - **`wp-includes/kses.php`** — Author+ CSS injection via a bypass of the safe CSS attribute filter. Every agent-supplied HTML string in this plugin goes through `wp_kses_post()` (20+ call sites across `posts.php`, `pages.php`, `media.php`, `elementor.php`, `acf.php`, `woocommerce.php`, `gravityforms.php`, `cf7.php`, `uae.php`), which calls the now-patched `safecss_filter_attr()` internally.
  - **`wp-includes/http.php`** — SSRF in URL validation allowing requests to link-local ranges. `wsp_execute_upload_media_from_url()` calls `download_url()` → `wp_safe_remote_get()` → `wp_http_validate_url()`, exactly the hardened path. This is the plugin's **only** outbound HTTP surface (no `wp_remote_*`, `curl_*`, or `file_get_contents` anywhere in the codebase).
- **Operator note:** `Requires at least: 6.9`, and **WP 6.9 is affected by 11 of the 12 vulnerabilities** until updated to 6.9.6. The SSRF fix matters more here than for a typical plugin: on an unpatched site, an agent steered by prompt injection could have used `wsp_upload_media_from_url` to reach link-local addresses such as cloud instance metadata (`169.254.169.254`). Patched core closes this; the plugin's mitigation is entirely inherited, so users should be on **7.0.3 or 6.9.6+**.
- Scope: this was a targeted check against the surfaces 7.0.3 actually revised, not a full audit of the plugin.

### Fixed — Website sync dropped Contact Form 7 + WPForms groups (`bin/lib-abilities.php`)
- **The public Abilities Directory on freewordpressmcp.com was missing every Contact Form 7 (10) and WPForms (12) tool**, even though both groups have shipped in `registry.php` since v2.6.6. Root cause: the website-sync generator loads `registry.php` in a stub environment (`bin/lib-abilities.php`), and that stub force-activates every plugin-gated group by defining its `wsp_*_is_active()` check to return `true`. Stubs existed for Yoast, Rank Math, Elementor, ACF, UAE, Gravity Forms, and WooCommerce — but **not** for `wsp_cf7_is_active()` / `wsp_wpforms_is_active()`. Without them, `registry.php`'s own real checks ran (`class_exists('WPCF7_ContactForm')` / `function_exists('wpforms')`), both returned false in the PHP-CLI generator, and the two groups were silently skipped. This is why the automated sync PR only ever produced a trivial diff and never surfaced the 2.6.6 form tools.
- **Fix:** added `wsp_cf7_is_active()` and `wsp_wpforms_is_active()` stubs (return `true`) to `bin/lib-abilities.php`, alongside the existing ones. The generator now emits all groups; `patch-website.php` regenerates both the `ABILITIES` array and the `GROUPS` map, so the site picks up CF7 + WPForms automatically on the next `main` push. Dev-tooling only — no plugin runtime code, tool, or shipped-zip behavior changed (the plugin already registered these tools correctly at runtime).
- **Guardrail:** documented in `AGENTS.md` ("Website sync automation") that any new plugin-gated group added to `registry.php` MUST get a matching active-check stub in `bin/lib-abilities.php`, or it will be dropped from the site.

---

## [2.6.6] — 2026-07-27

### Added — Direct (base64) file upload for media (`includes/abilities/media.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **`wsp_upload_media` now accepts base64 file content**, so an MCP client can upload a file attached to the chat **directly** into the media library — no public URL required. Fixes GitHub issue #17 ("still can't upload it directly"). Previously the only path was `url`, forcing users to host the image somewhere (e.g. Google Drive) first.
  - New optional inputs on `wsp_upload_media`: `data` (base64 string; a `data:<mime>;base64,` prefix is accepted and stripped) and `mime_type` (used to infer the extension when `data` has no data-URI prefix and `filename` has no extension). `url` is now optional — pass **either** `data` **or** `url`; `data` wins if both are present.
  - New callback `wsp_execute_upload_media_from_data()` in `media.php`: normalizes URL-safe/whitespaced base64, `base64_decode(..., true)` with strict validation, resolves a safe filename with an allowed image extension, writes the bytes to a `wp_tempnam()` temp file, and sideloads through `media_handle_sideload()` (same `upload_mimes` / `wp_check_filetype_and_ext` filters as the URL uploader). `wsp_execute_upload_media()` is no longer a thin wrapper — it routes to the base64 path when `data` is present, otherwise to `wsp_execute_upload_media_from_url()`.
  - **Security unchanged:** still requires `upload_files`; only image types (jpg, jpeg, png, gif, webp) are accepted; temp files are cleaned up on failure. No other tool, callback, or file behavior was modified.

---

## [2.6.5] — 2026-07-21

### Added — Elementor Advanced Design Tools (`includes/abilities/elementor.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **11 new Elementor design tools** for visual mockup replication and high-fidelity design workflows. All OFF by default, toggled from **MCP > Settings** under the "Elementor" group:
  - `get-active-kit` — reads global fonts, color palette, container width, and layout from the active Elementor kit.
  - `update-active-kit` — updates system colors, container width, and spacing in the active kit.
  - `regenerate-css` — clears and regenerates Elementor CSS cache for all Elementor-built posts.
  - `get-widget-schema` — queries the Elementor controls manager for a widget type; returns all control keys (margins, padding, background, typography, border) organized by tab.
  - `duplicate-element` — clones a widget or container with recursive 8-char hex ID reassignment via `wsp_elementor_clone_and_reid()` to prevent collisions.
  - `move-element` — removes an element from its current position and inserts it into a new parent or index position.
  - `convert-css` — parses CSS key-value rules (`padding`, `margin`, `border-radius`, `background-color`, `font-size`, `text-align`, etc.) into their exact Elementor settings counterparts.
  - `get-page-settings` / `update-page-settings` — read and update page-level `_elementor_page_settings` meta (template, hide_title, content_width, background).
  - `copy-styles` — copies settings from a source element ID to a destination element ID (with optional merge mode).
  - `get-breakpoints` — reads responsive viewport breakpoints (desktop 1025+, tablet 768-1024, mobile 0-767) from the active kit.

### Security
- All write tools run settings through `wsp_elementor_sanitize_settings()` (blocks `custom_css`, `_attributes`, `custom_attributes`, `__dynamic__` keys).
- `update-active-kit` and `regenerate-css` require `manage_options`; all other tools require `edit_posts`.

---

## [2.6.4] — 2026-07-21

### Added — WPForms suite (`includes/abilities/wpforms.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **12 new tools** for the WPForms integration (Lite and Pro), all write tools OFF by default and toggled from **MCP > Settings** under the "WPForms" group. Only registered when WPForms is active (`function_exists('wpforms') || class_exists('WPForms')`):
  - **Forms:** list (ON), get (ON), describe-schema (ON), get-form-stats (ON), create, update-form-settings, add-field, update-field, delete (trash or permanent). Capabilities: `wpforms_view_forms`, `wpforms_edit_forms`.
  - **Entries (Pro only):** list, get, delete (trash or permanent) — require `wsp_wpforms_pro_is_active()` (`wpforms()->is_pro()`). Lite users receive a descriptive error. Capabilities: `wpforms_view_entries`, `wpforms_edit_entries`.
- New helpers: `wsp_wpforms_is_active()`, `wsp_wpforms_pro_is_active()`, `wsp_wpforms_get_form_data()`, `wsp_wpforms_save_form_data()`, `wsp_wpforms_get_next_field_id()`, `wsp_wpforms_field_types()`.
- **Form data model:** WPForms stores forms as the `wpforms` custom post type; `post_content` is a JSON object with `fields` (array keyed by string IDs), `settings`, and `payments`. Field IDs are auto-assigned incrementally starting from 0.
- **Schema description:** `describe-schema` returns 16 supported field types (text, email, select, radio, checkbox, number, phone, file-upload, etc.) with metadata on choice support and editable attributes.
- `create-form` auto-generates a default notification targeting `{admin_email}` with `{all_fields}` body.
- All callbacks gate on `function_exists('wpforms')` and return descriptive `WP_Error` on inactive plugin.

### Security
- All text strings sanitized with `sanitize_text_field`/`sanitize_textarea_field`/`wp_kses_post`; field choices array members sanitized individually; JSON encoding uses `wp_json_encode()` + `wp_slash()`.
- Form delete gates on `wpforms_edit_forms`; entry tools require `wpforms_view_entries` / `wpforms_edit_entries`.

---

## [2.6.3] — 2026-07-21

### Added — Contact Form 7 suite (`includes/abilities/cf7.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **10 new tools** for the Contact Form 7 integration, all write tools OFF by default and toggled from **MCP > Settings** under the "Contact Form 7" group. Only registered when CF7 is active (`class_exists('WPCF7_ContactForm')`):
  - **Forms:** list (ON by default), get (ON by default), create, update, delete (trash or permanent). Capabilities: `wpcf7_edit_contact_forms`, `wpcf7_delete_contact_forms`.
  - **Entries (Flamingo):** list, get — require `class_exists('Flamingo_Inbound_Message')` as CF7 does not store entries natively. Capability: `wpcf7_edit_contact_forms`.
  - **Validation:** `validate-form` runs the built-in `WPCF7_ConfigValidator` on a form ID to catch email template and syntax errors. Capability: `wpcf7_edit_contact_forms`.
  - **Integrations:** `get-integrations` reads active integration modules and reCAPTCHA key status from the global `wpcf7` option. Capability: `manage_options`.
  - **Moderation (Flamingo):** `moderate-entry` marks a submission as spam, unspam, trash, or untrash. Capability: `wpcf7_edit_contact_forms`.
- New helper `wsp_cf7_is_active()` defined in both `registry.php` (defensive forward-declaration) and `cf7.php`; `wsp_cf7_flamingo_is_active()` also in `cf7.php`.
- `get-form` returns full form structure including scanned form tags, mail config, messages, and additional settings.
- All callbacks gate on `class_exists('WPCF7_ContactForm')` and return descriptive `WP_Error` on inactive plugin; Flamingo-dependent tools return a clear error when Flamingo is missing.

### Security
- All form strings sanitized with `sanitize_text_field`/`wp_kses_post`/`sanitize_textarea_field`; form IDs and entry IDs cast to `int`; `moderate_entry` action validated against `spam | unspam | trash | untrash` enum.
- `get-integrations` requires `manage_options` (exposes reCAPTCHA key status); all entry tools require `wpcf7_edit_contact_forms`.

---

## [2.6.2] — 2026-07-20

### Changed — Gravity Forms documentation & version bump
- Documented the complete **18-tool** Gravity Forms suite across `README.md`, `AGENTS.md`, and `readme.txt` — the v2.6.1 README under-reported the suite as "11 tools" and omitted the notification, confirmation, and form-settings write tools.
- Corrected the capability name in the docs from `gravityforms_create_forms` (plural, incorrect) to `gravityforms_create_form` (singular — the actual Gravity Forms capability) to match the registered tools.
- Bumped `Version` header and `WSP_MCP_VERSION` to `2.6.2`.

_No behavioral code changes — the 18 Gravity Forms tools shipped in 2.6.1; this release only corrects and completes the documentation._

---

## [2.6.1] — 2026-07-20

### Added — Gravity Forms suite (`includes/abilities/gravityforms.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **18 new tools** for the Gravity Forms integration, all write tools OFF by default and toggled from **MCP > Settings** under the "Gravity Forms" group (icon: 📋). Only registered when Gravity Forms is active (`class_exists('GFAPI') || class_exists('GFCommon')`):
  - **Forms:** list (ON by default), get (ON by default), create, update, delete, update-form-settings. Capabilities: `gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_delete_forms`.
  - **Entries:** list, get, update (status, read/starred flags, field values), delete (trash or permanent). Capabilities: `gravityforms_view_entries`, `gravityforms_edit_entries`, `gravityforms_delete_entries`.
  - **Notifications:** create, update, delete (in addition to the existing get). Capability: `gravityforms_edit_forms`.
  - **Confirmations:** create, update, delete (in addition to the existing get). Capability: `gravityforms_edit_forms`.
  - **Settings:** `update-form-settings` handles label placement, restrictions, scheduling, honeypot, CSS class, save & continue, and require-login per form. Capability: `gravityforms_edit_forms`.
- New helper `wsp_gravity_is_active()` defined in both `registry.php` (defensive forward-declaration) and `gravityforms.php`; used consistently across registry, tool registration, and execution guards.
- `get_entry` resolves field labels from the form definition so responses include human-readable names alongside raw field IDs.
- All callbacks gate on `class_exists('GFAPI')` and return `WP_Error` on inactive plugin; inputs sanitized with `sanitize_text_field`/`intval`/`sanitize_textarea_field`/`wp_kses_post`.

### Security
- Strict Gravity Forms capability checks on every tool; entry status validated against `active`, `spam`, `trash` enum.

---

## [2.6.0] — 2026-07-20

### Added — Ultimate Addons for Elementor (UAE) suite (`includes/abilities/uae.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **45 new tools** for the Ultimate Addons for Elementor integration, all OFF by default and toggled from **MCP > Settings** under the "Ultimate Addons Elementor" group:
  - **Widgets:** activate, deactivate, bulk toggle, check usage, and list UAE widgets.
  - **Templates:** create, duplicate, trash, restore, and update Header / Footer / Blocks templates.
  - **Builder / Engine:** manipulate Elementor structures — add sections, add columns, move elements, and build layouts from JSON.
  - **Settings:** get/update UAE plugin settings, theme info, extensions, and design-system tokens.

### Fixed
- `wsp_uae_builder_add_column` silently created a `container` instead of a `column`. The type validation in `wsp_execute_elementor_add_container()` (`includes/abilities/elementor.php`) only accepted `container` and `section`, so the `column` type set by the UAE wrapper was always overridden. Added `column` to the valid-type list.

### Security
- All string inputs sanitized with `wp_kses_post()`; strict per-tool capability checks (`edit_posts`, `publish_posts`, `manage_options`).

---

## [2.5.0] — 2026-07-14

### Added — Media library tool suite (`includes/abilities/media.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- Expanded the single read-only media tool into a full suite of seven tools, all OFF by default and toggled from **MCP > Settings**:
  - `wsp_list_media` (`wsp/list-media`, read) — browse and search the library by `type` (MIME), `search` keyword, `year`/`month`, with `per_page`/`page` pagination.
  - `wsp_get_media` (`wsp/get-media`, read) — **repurposed** to return the full metadata of a single attachment by `id` (title, URL, MIME, date, alt, caption, description, filename, filesize, `wp_get_attachment_metadata()`, author, parent). Browse/search behavior moved to `wsp_list_media`.
  - `wsp_count_media` (`wsp/count-media`, read) — counts grouped by MIME type plus a total, via `wp_count_attachments()`.
  - `wsp_update_media` (`wsp/update-media`, write) — update `title`, `alt`, `caption`, `description` by `id`.
  - `wsp_delete_media` (`wsp/delete-media`, write) — permanent delete via `wp_delete_attachment( $id, true )`; requires `delete_posts`.
  - `wsp_upload_media` (`wsp/upload-media`, write) and `wsp_upload_media_from_url` (`wsp/upload-media-from-url`, write) — sideload a file from a `url` via `download_url()` + `media_handle_sideload()`, with optional `filename`, `title`, `alt`, `caption`, and `post_id` to attach to. `wsp_execute_upload_media()` wraps `wsp_execute_upload_media_from_url()`.
- New shared helper `wsp_media_item_data()` normalizes attachment metadata for the get/update/upload responses.

### Security
- All inputs sanitized (`sanitize_text_field`/`wp_kses_post`/`sanitize_mime_type`/`esc_url_raw`/`sanitize_file_name`/`intval`); alt text written to `_wp_attachment_image_alt`. Read tools gated by `upload_files`, delete by `delete_posts`. Temp download files are removed with `wp_delete_file()` on sideload failure.

---

## [2.4.1] — 2026-07-08

### Security (WordPress.org review)
- **ACF value-write tools hardened against arbitrary code insertion** (`includes/abilities/acf.php`). The WordPress.org review flagged that the ACF write tools accepted arbitrary unsanitized values and stored them via `update_field()`, giving MCP clients a path to persist raw `<script>`/`<style>`/inline-handler markup (stored XSS) into fields and options. New recursive sanitizer `wsp_acf_sanitize_value()` now runs every incoming value through sanitization before storage:
  - Arrays are walked recursively (repeaters, groups, flexible content), with string keys sanitized via `sanitize_text_field()`.
  - Strings pass through `wp_kses_post()`, which strips `<script>`/`<style>` tags and `on*` event-handler attributes while preserving the post-safe HTML that legitimate WYSIWYG fields rely on.
  - Non-string scalars (int, float, bool, null) carry no executable payload and are returned unchanged.
- Applied in all three `update_field()` write paths: `wsp_execute_acf_update_value_deep()`, `wsp_execute_acf_bulk_update_values()`, and `wsp_execute_acf_update_option_value()` (each previously only ran `wp_unslash()` before saving).

### Notes
- The Claude Desktop connection snippet remains correct for macOS/Linux. Windows users whose Node.js lives under `C:\Program Files\nodejs` may hit a `cmd /C` quoting bug (`'C:\Program' is not recognized`) caused by the space in the path; the workaround is to wrap the launch as `"command": "cmd", "args": ["/c", "npx", …]`. Tracked in issue #13.

---

## [2.4.0] — 2026-07-04

### Added
- **OpenCode connection tab** on the **MCP > Connection** page (`includes/admin/connection-page.php`). Sixth per-client snippet, joining Claude Desktop / Cursor / Codex / Antigravity / OpenClaw. OpenCode connects natively over remote HTTP (no Node.js / mcp-remote bridge), using its `mcp.<name>.{ type: "remote", url, enabled, oauth, headers }` schema with the API key inlined in the `Authorization` header. The snippet is a **full-file** config (includes `$schema` and the top-level wrapper) so users can create a fresh `~/.config/opencode/opencode.json` and paste directly; instructions cover create-file → paste → restart. Server name auto-derives as `wsp-<host>`, consistent with the other tabs.

---

## [2.3.1] — 2026-07-01

### Security
- **Elementor write tools hardened against arbitrary code insertion** (`includes/abilities/elementor.php`). New guards applied in `wsp_execute_elementor_add_widget()`, `wsp_execute_elementor_update_element()`, and `wsp_execute_elementor_add_container()`:
  - `wsp_elementor_is_blocked_widget()` rejects code-bearing widget types (`html`, `shortcode`, `code`, `code-highlight`) before they can be written to `_elementor_data`.
  - `wsp_elementor_sanitize_settings()` recursively strips code-bearing setting keys (`custom_css`, `_attributes`, `custom_attributes`, `__dynamic__`) and runs every string value through `wp_kses_post()`, so `<script>`/`on*` handlers can't be injected via a normal text field. Structured content writes (heading, text-editor, image, button, layout, etc.) continue to work.
- **ACF options-page value reads now require `manage_options`** (was `edit_posts`) in both the native tool spec (`includes/tools/native-tools.php`, `wsp_acf_get_option_value`) and the callback's own cap check (`wsp_execute_acf_get_option_value`). Global options are admin-level configuration.

### Removed
- Dead `wsp_register_acf_abilities()` helper (`includes/abilities/acf.php`) — the old dual-mode `wp_register_ability` path, unhooked since the v2.2 native-only migration. Flagged by the WordPress.org review tool for a broad `edit_posts` permission_callback; deleting it removes the finding at its source.

### Changed
- `Requires at least` header/readme value changed from `6.9.0` to major-only `6.9` per WordPress.org versioning rules (the minor is ignored).

---

## [2.3.0] — 2026-06-30

### Added
- **Advanced Custom Fields (ACF) suite** (`includes/abilities/acf.php`) — 27 tools covering field groups, fields, field values (with dot-notation deep get/set), custom post types, taxonomies, and options pages. All OFF by default and only registered when ACF is active (`class_exists('ACF') || function_exists('get_field')`). Shipped via PR #8.
  - **Field groups:** list, get, create, update, delete, import-from-JSON.
  - **Fields:** list (by group), get, create, update config, delete, duplicate, force-sync (`acf/include_fields`).
  - **Values:** get/update deep (dot-notation, e.g. `repeater.0.subfield`), delete, get-all, bulk-update, get-field-object. Targets resolve via `wsp_acf_validate_target()` — accepts a numeric post/page ID, `user_<id>`, `term_<id>`/`category_<id>`, or `options`.
  - **CPT/taxonomy:** list post types, list taxonomies, programmatically create CPT/taxonomy (requires ACF 6.1+ `acf_update_post_type()` / `acf_update_taxonomy()`).
  - **Options pages:** list, create (ACF Pro), get/update option value.
- **Settings UI** — added "WooCommerce" (🛍️) and "Advanced Custom Fields" (🧩) group icons in `settings-page.php`.

### Security
- ACF value tools enforce **per-object** capabilities inside `wsp_acf_validate_target($target_id, $target_type, $is_write)`, not a blanket cap: `edit_post($id)` for post/page targets, `edit_user($id)` (write) / `list_users` (read, with self-read allowance) for user targets, `manage_categories` for term targets, and `manage_options` for the `options` target. String targets like `user_5` are normalized to id+type so they flow through the same capability gates (closes a pre-merge bypass where string targets skipped all checks).
- Field-group / field / CPT / taxonomy / options-page **create/update/delete** tools require `manage_options`; read and value-edit tools require `edit_posts`.

### Removed
- A proposed `wsp/acf-delete-options-page` tool was dropped before release. ACF options pages are re-registered on every load, so a runtime delete can't persist — its callback, native-tool registration, and registry entry were all removed to avoid advertising a non-functional tool.

### Fixed (WordPress.org Plugin Check)
- `includes/abilities/woocommerce.php` — replaced `parse_url()` with `wp_parse_url()` and `@unlink()` with `wp_delete_file()` (WordPress.WP.AlternativeFunctions).
- `includes/admin/settings-page.php` — the legacy-config redirect now sanitizes the `page` GET param (`sanitize_key( wp_unslash() )`) with a justified `WordPress.Security.NonceVerification.Recommended` ignore (read-only navigation routing, no state change).
- `includes/server/class-session-store.php` — moved the `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` ignore onto the actual SQL-string lines (it was on the wrong line and not suppressing the warning), and build the table name inline from `$wpdb->prefix` in `touch_session()`, `get_fingerprint()`, and `cleanup_expired()` so the Plugin Check `DirectDB.UnescapedDBParameter` sniff can verify it as a safe source (it couldn't trace the previous `self::table()` helper). All values remain bound via `$wpdb->prepare()`.

### Changed
- **Plugin slug renamed** `websensepro-mcp-abilities` → `wsp-mcp-ai-agents-connector`. The plugin folder and main file were renamed (`wsp-mcp-ai-agents-connector/wsp-mcp-ai-agents-connector.php`), the text domain was updated across all 48 i18n calls in `includes/admin/connection-page.php`, and a matching `Text Domain: wsp-mcp-ai-agents-connector` header was added to the main file (it was previously missing). Done to align the slug with the public name ahead of WordPress.org submission.

### Breaking changes
- **The plugin folder name changed.** On existing installs WordPress treats the new folder as a separate plugin: after updating, deactivate/remove the old `websensepro-mcp-abilities` copy and activate the new one. Stored options, the sessions table, and the API key are untouched (constants/option keys are unchanged), so no reconfiguration is needed beyond reactivation.

---

## [2.2.0] — 2026-06-24

### Summary
The plugin is now **native-only**. The legacy dual-mode path (registering abilities through the WordPress Abilities API / mcp-adapter when present) and the **MCP > Config Files** admin page have both been removed. This is the cleanup done ahead of WordPress.org submission — the plugin no longer references the off-directory `mcp-adapter`/`abilities-api` packages or the `@automattic/mcp-wordpress-remote` npm bridge anywhere.

### Removed
- **MCP > Config Files admin page** (`includes/admin/config-page.php`) — deleted. It generated mcp-adapter / `@automattic/mcp-wordpress-remote` config snippets, which are obsolete now that the native server is the only transport. **MCP > Connection** is the single source for connection details.
- **Dual-mode Abilities-API registration:**
  - `wsp_mcp_register_all_abilities()` and the `wp_abilities_api_init` / `wp_abilities_api_categories_init` hooks in the main file.
  - `wsp_register_ability_category()` in `registry.php`.
  - `wsp_register_*_abilities()` in every `includes/abilities/*.php` module (posts, pages, taxonomy, comments, media, users, search, site, yoast, elementor, woocommerce). The `wp_register_ability()` calls are gone; the `wsp_execute_*()` business logic is **unchanged** and still drives the native server.
  - `wsp_mcp_abilities_api_available()` in `dependency.php`.

### Changed
- `dependency.php` reduced to a stub — only `wsp_mcp_transport_available()` (always `true`) remains, kept for back-compat/readability.
- Old bookmarks to `admin.php?page=wsp-mcp-config` now **redirect** to MCP > Connection (`wsp_mcp_redirect_legacy_config_page()` on `admin_init`) instead of hitting a permission wall.
- `WSP_MCP_VERSION` and the plugin header bumped to `2.2.0` (the constant had been left at `2.0.0`).

### Breaking changes
- **Pre-2.0 connections made through the WordPress MCP Adapter stop working.** Those users must reconnect using the native endpoint from **MCP > Connection** (Application Password or the plugin API key). New installs and any connection already using the native endpoint are unaffected.

### Migration
- If you connected before v2.0 via the MCP Adapter, open **MCP > Connection**, copy the endpoint URL + API key (or use an Application Password), and reconnect your client.
- No data migration. Options, the sessions table, and per-ability toggles are untouched.

### Files removed
`includes/admin/config-page.php`

---

## [2.1.0] — 2026-06-23

### Summary
Adds a full WooCommerce integration suite — 15 tools covering products, orders, refunds, coupons, customers, stock, sales reports, and review moderation. All tools are off by default and only registered when WooCommerce is active. (This release shipped via PR #6; the changelog entry is recorded here retroactively.)

### Added
- **`wsp_woo_get_products`** — list products with limit and status filtering. Requires `edit_posts`.
- **`wsp_woo_get_product`** — get full details of a single product by ID. Requires `edit_posts`.
- **`wsp_woo_create_product`** — create a simple or variable product; supports attributes, SKU, stock quantity, and image-URL sideload. Requires `publish_posts`.
- **`wsp_woo_create_variation`** — create a variation for an existing variable product with per-variation price, SKU, attributes, and image. Requires `publish_posts`.
- **`wsp_woo_update_product`** — update name, price, sale price, description, SKU, stock, status, or featured image. Requires `edit_posts`.
- **`wsp_woo_list_orders`** — list recent orders with optional status filter. Requires `edit_posts`.
- **`wsp_woo_update_order_status`** — update an order's status; validated against the core WooCommerce statuses. Requires `edit_posts`.
- **`wsp_woo_refund_order`** — create a full or partial refund (triggers gateway refund via `wc_create_refund`). Requires `manage_woocommerce`.
- **`wsp_woo_create_coupon`** — create a percentage or fixed coupon with optional expiry; `discount_type` validated. Requires `manage_woocommerce`.
- **`wsp_woo_list_coupons`** — list coupons with usage stats. Requires `manage_woocommerce`.
- **`wsp_woo_create_order_note`** — add an internal or customer-facing note to an order. Requires `edit_posts`.
- **`wsp_woo_list_customers`** — list registered customers with billing email and phone (PII). Requires `manage_woocommerce`.
- **`wsp_woo_report_sales`** — gross/net revenue, tax, shipping, and average order value over N past days. Requires `manage_woocommerce`.
- **`wsp_woo_get_low_stock`** — products below a stock threshold plus out-of-stock products. Requires `edit_posts`.
- **`wsp_woo_moderate_review`** — approve, spam, trash, or reply to a product review; `action` validated. Requires `edit_posts`.
- New module `includes/abilities/woocommerce.php`; image-sideload helper with SSL bypass scoped to the single download request and gated to `local`/`development` environments.

### Changed
- `registry.php` and `native-tools.php` — 15 new entries each, gated on `class_exists('WooCommerce')`.

### Security
- Financial / PII tools (`refund_order`, `list_customers`, `create_coupon`, `list_coupons`) require `manage_woocommerce`.
- All enum inputs (`status`, `discount_type`, `action`) validated with `in_array(..., true)`.

### Migration
- No action required. All WooCommerce tools are off by default and invisible when WooCommerce is not active.

---

## [2.0.0] — 2026-06-20

### Summary
The plugin is now fully self-contained. It ships its own native MCP server (a WordPress REST endpoint) and no longer requires a companion plugin or Node.js bridge to connect to AI clients.

### Added
- **Native MCP server** — REST endpoint `/wp-json/wsp-mcp/v1/mcp` (Streamable HTTP + JSON-RPC 2.0).
  - Rationale: WP.org cannot express a dependency on a GitHub-only plugin via `Requires Plugins:`; a self-contained plugin is the proven-approvable architecture (two WP.org-approved precedents both went native).
  - Handles: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, `ping`, empty `resources/list` / `prompts/list`.
  - Protocol versions supported: `2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`.
- **DB-backed session store** — table `{prefix}wsp_mcp_sessions`, fingerprint-bound, 24-hour sliding expiry, daily cron cleanup (`wsp_mcp_session_cleanup`).
- **Three auth paths** — plugin-generated API key (Bearer or `X-WSP-MCP-API-Key` header), WordPress Application Password (HTTP Basic). OAuth 2.0 deferred to v2.1.
- **MCP > Connection admin page** — shows the native endpoint URL and API key; one-click Regenerate; tabbed ready-to-paste config snippets for Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw (API key hardcoded inline — avoids the `mcp-remote` "missing env var" failure).
- **Accordion Settings UI** — MCP > Settings groups abilities into collapsible sections; open/closed state persists in `localStorage`; live count badge per group.
- **Tool registry hook** — `do_action('wsp_mcp_register_tools', …)` so add-ons can register extra tools.
- `uninstall.php` drops the sessions table and all `wsp_mcp_*` options on plugin deletion.
- `readme.txt` for WordPress.org submission.

### Changed
- All existing `wsp_execute_*` callback logic is **unchanged** — only the transport changed from "register with mcp-adapter" to "register with the native tool registry" (`includes/tools/native-tools.php`).
- `dependency.php` repurposed: `wsp_mcp_abilities_api_available()` now gates dual-mode only; the native transport is always available.
- MCP > Config Files page now shows a deprecation notice pointing to MCP > Connection.

### Breaking changes
- None for end users. Pre-2.0 connections via the mcp-adapter keep working (dual-mode preserved).

### Migration (upgrading from v1.x)
- Existing mcp-adapter connections remain valid — the Abilities API path stays behind a `function_exists('wp_register_ability')` guard.
- New installs: use **MCP > Connection** to copy the native endpoint URL and API key.
- Claude Desktop users need the `npx -y mcp-remote` bridge (Claude Desktop config files don't support remote HTTP directly). Cursor, Codex, Antigravity support native remote HTTP natively.
- After enabling or adding tools, **fully reconnect the client** (restart Claude Desktop, not just open a new chat) — MCP clients cache `tools/list` at connect time.

### Files added
`includes/server/class-mcp-server.php`, `includes/server/class-session-store.php`,
`includes/server/class-auth.php`, `includes/tools/native-tools.php`,
`includes/admin/connection-page.php`, `readme.txt`, `LICENSE`

---

## [1.3.0] — 2026-06-19

### Summary
Adds Yoast SEO read/write abilities so AI clients can inspect and update SEO metadata on posts and pages.

### Added
- **`wsp/yoast-get-seo`** — returns SEO title, meta description, and focus keyphrase for a post or page. Requires `edit_posts`. OFF by default.
- **`wsp/yoast-update-seo`** — updates any combination of SEO title, meta description, and focus keyphrase. Rebuilds the Yoast indexable after saving. Requires `edit_posts`. OFF by default.
- Both abilities are gated on Yoast being active (`defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')`); they are silently absent when Yoast is not installed.
- Helper layer in `includes/abilities/yoast.php`: `wsp_yoast_is_active()`, `wsp_yoast_get_meta()`, `wsp_yoast_set_meta()`, `wsp_yoast_rebuild_indexable()`, `wsp_yoast_validate_post()`, `wsp_yoast_format_seo_data()`.
- Falls back to direct post-meta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`) when `WPSEO_Meta` class is unavailable.

### Changed
- `registry.php` — two new entries added to `wsp_mcp_ability_registry()` under the "Yoast SEO" group.

### Migration
- No action required. Abilities appear in MCP > Settings under "Yoast SEO" only if Yoast SEO is active.

---

## [1.2.1] — 2026-06-17

### Summary
Minor fixes and additions to the Config Files admin page.

### Added
- **OpenClaw** tab on MCP > Config Files with a ready-to-paste JSON snippet (`~/.openclaw/openclaw.json`, uses `mcp.servers` schema + `mcp-remote` bridge).

### Fixed
- `create-page` ability: added `page_layout` input parameter (maps to `_wp_page_template` post meta) so AI clients can set the page template when creating a page.
- `create-page` ability: fixed Elementor initialization — new pages are now properly recognized by Elementor (`_elementor_edit_mode` meta set to `builder`).

---

## [1.2.0] — 2026-06-14

### Summary
Major refactor from a single-file plugin to a modular `includes/`-based structure. Adds a full suite of Elementor page-builder abilities.

### Added
- **Elementor abilities** (`includes/abilities/elementor.php`) — 9 abilities for reading and writing Elementor page structure:
  - Read: `elementor-list-pages`, `elementor-get-page`, `elementor-get-element`, `elementor-find-element`, `elementor-list-templates`
  - Write: `elementor-update-element`, `elementor-add-widget`, `elementor-add-container`, `elementor-remove-element`
  - All gated on `class_exists('\Elementor\Plugin')` and `edit_posts` capability.
- Helper functions for the Elementor data model: `wsp_elementor_get_data`, `wsp_elementor_save_data`, `wsp_elementor_generate_id`, `wsp_elementor_find_by_id`, `wsp_elementor_remove_by_id`, `wsp_elementor_update_by_id`, `wsp_elementor_insert_into`, `wsp_elementor_first_insertable`, `wsp_elementor_simplify_tree`, `wsp_elementor_search_tree`.

### Changed
- **Modular refactor** — moved all feature code out of the monolithic main file into `includes/abilities/` (posts, pages, taxonomy, comments, media, users, search, site). Main file reduced to a minimal loader + activation glue.
  - Rationale: single-file plugins are hard to review and extend; the new structure matches WP.org best practices.

### Migration
- No action required. Behavior is identical to v1.1.0 for all non-Elementor abilities.

---

## [1.1.0] — 2026-06-07

### Summary
Adds an admin page to generate ready-to-paste MCP config file snippets for Claude Desktop, Cursor, and Codex.

### Added
- **MCP > Config Files** admin page (`includes/admin/config-page.php`) — auto-fills the REST API URL and current WP username; user replaces the placeholder Application Password. Tabs for Claude Desktop, Cursor, and Codex (TOML).
- `readme.txt` (initial version).

---

## [1.0.0] — 2026-06-06

### Summary
Initial release. Registers WordPress content as MCP abilities via the WordPress Abilities API / mcp-adapter stack.

### Added
- Plugin scaffold: `wsp-wordpress-mcp.php` main file with all abilities inline.
- Read abilities (ON by default): `get-posts`, `get-pages`, `get-categories`, `get-tags`, `search`, `get-site-info`.
- Write abilities (OFF by default): `create-post`, `update-post`, `delete-post`, `create-page`, `update-page`, `delete-page`, `create-category`, `create-tag`.
- Sensitive read abilities (OFF by default): `get-comments`, `approve-comment`, `delete-comment`, `get-media`, `get-users`, `get-plugins`.
- Admin toggle UI (MCP > Settings) — per-ability on/off switches with write-action confirmation dialogs.
- Central ability registry (`wsp_mcp_ability_registry()`) driving both admin UI and ability registration.
- Dual-mode transport guard: `function_exists('wp_register_ability')` so the plugin degrades gracefully when the Abilities API is absent.

---
