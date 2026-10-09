# WSP WordPress MCP — Agent & Contributor Guide (AGENTS.md)

> **🚫 PROTECTED FILE:** These files should not be changed/deleted/touched on a PR from GitHub - PR changes should be only in `wsp-mcp-ai-agents-connector` folder.
> (`AGENTS.md`, `CHANGELOG.md` and `HISTORY.md` are maintained by the maintainer on `main`, outside of contributor PRs.)

---

## ⚠️ MANDATORY FIRST STEP FOR ALL AI AGENTS ⚠️

**Before reading any source code, before asking any questions, before writing a single line:**

1. **Read `AGENTS.md`** (this file) — current architecture, all tools, all hooks, security patterns, naming conventions.
2. **Read `CHANGELOG.md`** — what changed in every version and why; migration notes per release.
3. **Read `HISTORY.md`** — why the native MCP server was built, what alternatives were rejected, and the milestone plan rationale.

These three files give you complete project understanding without touching the codebase.
**Do not read source files until you have read all three.** The files are kept accurate by the team's update rule (see below).

### Update rule — enforced on every change

> **After every code change made directly on `main` by the maintainer (or an agent working for them), you MUST update both `AGENTS.md` and `CHANGELOG.md` in the same commit.** A PR from GitHub (contributor/fork) must **not** touch these files — it changes only `wsp-mcp-ai-agents-connector/`; the maintainer updates the docs when merging.
>
> - `AGENTS.md`: update any section whose facts changed (architecture, tool table, hooks, constants, admin UX, security patterns, directory structure).
> - `CHANGELOG.md`: add a bullet under the correct version (or create a new `## [X.Y.Z] — YYYY-MM-DD` block) describing what changed and why.
> - `HISTORY.md`: only update when a significant architectural decision is made. Day-to-day feature work does not belong there.
>
> Agents that skip this step leave the next agent flying blind. Don't do it.

---

> **This file is the universal source of truth for every agent and human working on this plugin**
> (Claude, Cursor, Codex, Gemini/Antigravity, OpenClaw, and the human team).

## What this plugin is

**Plugin Name:** WSP MCP - Free MCP Plugin for WordPress: Connect Claude, ChatGPT & AI Agents  
(must match the `=== … ===` title line in `readme.txt` — Plugin Check flags a mismatch)  
**Version:** 2.9.5
**Slug/prefix:** `wsp`  
**WP option key:** `wsp_mcp_abilities`  
**Constant prefix:** `WSP_MCP_`

This is a WordPress plugin that exposes WordPress content to AI agents (Claude, Cursor, Codex, etc.) via the **Model Context Protocol (MCP)**. The site admin controls which operations ("tools"/"abilities") are active via a toggle UI in **WP Admin > MCP > Settings**.

**As of v2.0 the plugin ships its OWN native MCP server** (REST endpoint `/wp-json/wsp-mcp/v1/mcp`) — no companion plugin or WordPress MCP Adapter is required. **As of v2.2 the plugin is native-only:** the former dual-mode Abilities-API registration path and the MCP > Config Files page have been removed (see CHANGELOG `[2.2.0]`). See **"## v2.0 Architecture (CURRENT — read this first)"** below before changing transport/tool code.

---

## v2.0 Architecture (CURRENT — read this first)

> Other agents (Claude/Gemini/Cursor) and forks: this section is the single source of truth for
> how MCP works in v2.0. You should not need to read the whole codebase to add a tool.

**Transport:** a hand-rolled native MCP server. One REST route, `/wp-json/wsp-mcp/v1/mcp`
(`register_rest_route('wsp-mcp/v1','/mcp', …)`, `permission_callback => '__return_true'`, auth
enforced inside the handler). Speaks Streamable HTTP + JSON-RPC 2.0: `initialize` (echoes the
client's `protocolVersion` if recognized; supported = `2024-11-05`/`2025-03-26`/`2025-06-18`/`2025-11-25`),
`notifications/initialized`, `tools/list`, `tools/call`, `ping`, empty `resources/list` & `prompts/list`.

**`includes/response-guard.php` (v2.8.0, required before every other include):** a site running
this plugin can have any number of *other* plugins active, of any quality — this plugin can't
control that. `wsp_mcp_output_guard_start()` opens an output buffer the instant this file is
`require`d (top of the main plugin file, before anything else loads) whenever
`wsp_mcp_is_own_endpoint_request()` says the raw request URI is this plugin's own MCP REST route or
one of the OAuth discovery/registration/authorize/token endpoints — a no-op on every other request.
`wsp_mcp_output_guard_flush()` discards just that one buffer level right before the real response is
sent, so a stray PHP notice/warning some other plugin printed during `init`/`wp_loaded`/etc. can
never land in front of the JSON body (which breaks every MCP client's JSON parser and surfaces in
Claude as "connected" with "no tools available") and never causes a "headers already sent" warning
on this plugin's own `header()` calls. Wired at exactly two choke points: `WSP_MCP_Server`'s
`rest_pre_echo_response` filter (covers every JSON-RPC response, since all of them are
`WP_REST_Response` objects — including the 401 challenge in `class-auth.php`) and
`WSP_MCP_OAuth_Server::send_json()` (the single function every OAuth JSON response goes through).
Cannot catch output printed before this plugin's own file is included — there is no earlier hook a
regular (non-mu) plugin has — but that covers the rare case; everything from `plugins_loaded` onward,
where real-world stray output actually happens, is covered.

**Server-layer files (`includes/server/`):**
- `class-mcp-server.php` — `WSP_MCP_Server`: REST route, JSON-RPC dispatch, in-memory tool
  registry (`register_tool()`), Origin validation, rate limiting (120/60s), `no-store` headers.
  Booted on `plugins_loaded` via `WSP_MCP_Server::init()`.
- `class-session-store.php` — `WSP_MCP_Session_Store`: DB table `{prefix}wsp_mcp_sessions`,
  `Mcp-Session-Id` issued on `initialize`, fingerprint-bound, sliding 24h expiry, daily cron
  `wsp_mcp_session_cleanup`. **`touch_session()` must never treat a 0-row UPDATE as "no session"**
  (v2.6.8): expiry is second-resolution, so a request in the same second as `initialize` rewrites
  the identical `expires_at` and MySQL reports 0 *changed* rows. `false` = DB error → reject,
  `> 0` → accept, `0` → ambiguous, resolved with a `SELECT 1 … expires_at > NOW` existence check.
- `class-auth.php` — `WSP_MCP_Auth`: accepts **(1)** Application Password (HTTP Basic, validated
  by WP core), **(2)** plugin API key via `Authorization: Bearer <key>`, **(3)** same key via
  `X-WSP-MCP-API-Key`, **(4)** an OAuth access token, also as `Authorization: Bearer <token>`, tried
  whenever a bearer value isn't the static key. API key stored in option `wsp_mcp_api_key`
  (admin-only → mapped to lowest-ID admin so capability checks resolve); an OAuth token instead
  binds to the specific user who approved consent. `require_cap()` gates each tool. Both the OAuth
  token path and the `resource_metadata` pointer in the 401 challenge are gated on
  `wsp_mcp_oauth_is_enabled()` — see the OAuth section below.
- `class-oauth-server.php` / `class-oauth-store.php` — `WSP_MCP_OAuth_Server` /
  `WSP_MCP_OAuth_Store`: a native OAuth 2.1 authorization server (RFC 8414 + 9728 discovery, RFC
  7591 DCR, RFC 6749 + PKCE S256) powering Claude's "paste a URL only" Connector flow. Endpoints are
  matched against the raw request path on `init` priority 0, not through the REST API. Three tables
  (`wsp_mcp_oauth_clients` / `_codes` / `_tokens`), daily cron `wsp_mcp_oauth_cleanup`. **Read the
  invariants below before touching either file.**

**Server instructions & resources (v2.9.5):** `initialize` may carry an `instructions` string and `capabilities.resources`, and `resources/list|read` serve real documents — all driven by the admin's Site Context feature, see **"### Site Context"** below. A tool spec may use `active_callback` (fn(): bool) instead of `enable_key`.

**Tools (`includes/tools/native-tools.php`):** `wsp_mcp_register_native_tools()` registers every
tool with `WSP_MCP_Server::register_tool($name, $spec)`. It **reuses the existing
`wsp_execute_*()` callbacks verbatim** (in `includes/abilities/*.php`) — only the transport
changed. Tool spec keys: `description`, `inputSchema` (JSON Schema), `callback` (the
`wsp_execute_*` fn), `capability` (`''` = authenticated only), `enable_key` (the `wsp/...`
registry key driving the admin toggle). Add-ons can hook `do_action('wsp_mcp_register_tools', …)`.

**Naming:** MCP tool names use underscores (`wsp_get_posts`); the matching admin-toggle
`enable_key` uses the slash form (`wsp/get-posts`). The native server only advertises tools whose
`enable_key` is enabled via `wsp_mcp_is_enabled()`, so **MCP > Settings controls both transports**.
Yoast tools register only if `wsp_yoast_is_active()`; Elementor tools only if `wsp_elementor_is_active()`;
WooCommerce tools only if `class_exists('WooCommerce')`; ACF tools only if `wsp_acf_is_active()`.

**Admin:** `includes/admin/connection-page.php` adds **MCP > Connection** (endpoint URL, API key +
regenerate, and per-client tabbed config snippets for Claude Desktop / Cursor / Codex / Antigravity /
OpenClaw / OpenCode — all native, no MCP Adapter). `dependency.php` provides
`wsp_mcp_abilities_api_available()` (gates dual-mode) and `wsp_mcp_transport_available()` (always
true in v2.0).

### OAuth authorization server — security invariants (do not regress these)

The OAuth server is the plugin's only **unauthenticated, publicly-reachable** surface: it accepts
client registrations from strangers and mints credentials for whoever clicks Allow. Each rule below
exists because its absence is an exploitable bug, not a style preference.

1. **Off unless an admin opted in.** Option `wsp_mcp_oauth_enabled` (constant
   `WSP_MCP_OAUTH_OPTION`), default `false`, read via `wsp_mcp_oauth_is_enabled()`.
   `WSP_MCP_OAuth_Server::init()` returns early when off, so nothing is routed and no discovery
   document is served. `class-auth.php` gates the OAuth token path and the 401 `resource_metadata`
   pointer on the same check, so switching it off also stops honouring already-issued tokens.
   Toggled from MCP > Connection (`admin_post_wsp_mcp_toggle_oauth`, nonce + `manage_options`);
   turning it off calls `WSP_MCP_OAuth_Store::revoke_everything()`. **Never make this default-on** —
   a plugin update must not publish login endpoints on a site that didn't ask for them.
2. **Consent requires a real capability, not just a login.** `handle_authorize()` checks
   `wsp_mcp_oauth_min_capability()` (default `edit_posts`, filterable) before rendering *or*
   accepting the consent form. Login alone is not enough: six tools register with
   `'capability' => ''`, and `wsp_get_posts` with `status=all` returns every draft/pending/scheduled
   post with no author filter — so on a site with open registration, "any logged-in user" would mean
   any visitor who signs up can read unpublished content through Claude.
3. **The consent screen must name the destination.** `client_name` comes from the unauthenticated
   registration endpoint — it is a self-assigned label, not an identity. `render_consent_page()`
   therefore shows the redirect **host** and full URI prominently and states in the page that the
   name is unverified. Without that, registering a client called "WordPress Security Update" that
   points at an attacker callback turns one Allow click by a logged-in editor into a token bound to
   their account.
4. **No OAuth HTML page may be frameable.** `render_consent_page()` and `render_error_page()` both
   call `send_frame_protection_headers()` (`X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` +
   `Referrer-Policy`). These render on `init`, outside wp-admin, so core's own framing protection
   never applies — without the headers the Allow button is clickjackable.
5. **Registration is throttled and bounded.** `handle_register()` calls
   `enforce_rate_limit( 'register', … )` (5 per 10 min per IP — its own bucket, separate from the
   token endpoint's deliberately generous 60/60s, which has to absorb Anthropic's shared egress
   range) and refuses past `WSP_MCP_OAuth_Store::MAX_CLIENTS`, pruning abandoned rows first.
   `prune_unused_clients()` (also on the daily cron) deletes clients older than `UNUSED_CLIENT_TTL`
   that never produced a token, so junk registrations can't hold the ceiling against real clients.
6. **Refresh-token replay revokes the family.** `rotate_refresh_token()` revokes the presented pair
   on use; if a *spent* (already-rotated) token is presented again, `find_rotated_token()` detects it
   and `revoke_all_for( client, user )` kills every live token for that pair (RFC 9700 §4.14.2).
7. **Unchanged invariants from the original implementation:** redirect_uri is exact-matched against
   the registered allowlist *before* any redirect happens (canonicalized on both sides;
   userinfo/fragment rejected); `wp_redirect()` not `wp_safe_redirect()` is correct **only** because
   of that match; PKCE S256 is mandatory and compared with `hash_equals()`; codes are single-use and
   deleted on first sight; tokens are stored as SHA-256 hashes only.

> **Still outstanding (not fixed here):** there is no admin screen listing registered clients or live
> tokens and no per-connector revoke button — the only controls are the global off switch and
> `revoke_everything()`. Add one before advertising OAuth as the primary connection path.

### How to add a NEW MCP tool (v2.0)

1. Write/keep the logic as a `wsp_execute_<name>( $input )` function returning an array or
   `WP_Error` (in an `includes/abilities/*.php` file — new file is fine).
2. Add a `WSP_MCP_Server::register_tool('wsp_<name>', [...])` entry in
   `includes/tools/native-tools.php` (description, inputSchema, callback, capability, enable_key).
3. Add the ability's metadata to `wsp_mcp_ability_registry()` in `registry.php` (so the
   admin toggle exists; set `default`).
4. Enable it in MCP > Settings, then **reconnect the client** (see gotcha below).

> The public tool list on **wspmcp.com** is maintained separately in the website repo —
> it is **not** generated from this repo. (The old `bin/` generators + `sync-abilities.yml` GitHub
> Action were removed; see CHANGELOG `[Unreleased]`.)

### Gotcha: client tool-list caching

MCP clients fetch `tools/list` once at connect and cache it. After enabling/adding tools, the user
must **reconnect** (fully restart Claude Desktop, not just open a new chat) before new tools appear.

---

## Directory structure

```
wsp-wordpress-mcp/                        ← repo root (NOT the plugin — docs live here)
└── wsp-mcp-ai-agents-connector/          ← plugin root (the installable folder)
    ├── wsp-mcp-ai-agents-connector.php   ← main file: constants, requires, hooks, activation/migration
    ├── readme.txt              ← WP.org readme (v2.0)
    ├── uninstall.php           ← deletes wsp_mcp_* options + drops sessions table
    └── includes/
        ├── response-guard.php  ← output-buffer guard (v2.8.0): swallows stray output from other
        │                          active plugins/themes on this plugin's own MCP/OAuth requests so
        │                          it can never corrupt the JSON response — required first, started
        │                          before any other include
        ├── dependency.php      ← stub: wsp_mcp_transport_available() (always true) — kept for back-compat
        ├── registry.php        ← central ability registry + settings helpers
        ├── context.php         ← Site Context (v2.9.5): admin-written AGENTS.md/CHANGELOG.md storage + delivery to agents
        ├── server/             ← v2.0 native MCP server
        │   ├── class-mcp-server.php     ← transport + JSON-RPC dispatch + tool registry
        │   ├── class-session-store.php  ← DB-backed sessions
        │   └── class-auth.php           ← API key + App Password + bearer + caps
        ├── tools/
        │   ├── native-tools.php ← registers every wsp_execute_* as a native MCP tool
        │   └── admin-tool-defs.php ← table of v2.9.5 Woo-store + plugin tools (registry + MCP registration)
        ├── admin/
        │   ├── promo-cards.php      ← shared sidebar cards + UTM link builder (both admin pages)
        │   ├── plugin-links.php     ← Settings | Connection | About Us links under the name on the Plugins screen
        │   ├── review-notice.php    ← WP.org review request on the Plugins screen + MCP pages, after first successful tool call
        │   ├── context-page.php     ← MCP > Context: Site Context switch + AGENTS.md / CHANGELOG.md editors
        │   ├── settings-page.php    ← toggle UI (MCP > Settings) — accordion groups + legacy-page redirect
        │   ├── connection-page.php  ← native endpoint + API key + per-client tabs (MCP > Connection)
        │   └── about-page.php       ← static WebSensePro info (MCP > About Us, after Analytics)
        └── abilities/           ← wsp_execute_* logic (called by the native server)
            ├── posts.php  pages.php  taxonomy.php  comments.php  media.php
            ├── users.php  search.php  site.php  menus.php  site-editor.php  widgets.php  health.php  revisions.php  post-meta.php  blocks.php  redirects.php  themes.php  theme-upload.php
            ├── yoast.php  elementor.php
            ├── woocommerce.php  woocommerce-catalog.php  woocommerce-store.php  plugins.php  acf.php
```

**Rule:** The main file is a minimal loader (+ activation/migration glue) only. All feature logic lives in `includes/`. Never put feature code in `wsp-mcp-ai-agents-connector.php`.

---

## Constants

| Constant | Value |
|---|---|
| `WSP_MCP_VERSION` | `'2.9.5'` |
| `WSP_MCP_OPTION` | `'wsp_mcp_abilities'` (per-ability on/off toggles) |
| `WSP_MCP_DIR` | `plugin_dir_path(__FILE__)` |

**Other persistent state:** option `wsp_mcp_api_key` (native API key), option `wsp_mcp_db_version`
(migration gate), option `wsp_mcp_oauth_enabled` (OAuth opt-in, **default off**), options
`wsp_mcp_context_enabled` (Site Context switch, **default off**), `wsp_mcp_context_agents` /
`wsp_mcp_context_changelog` (the two Markdown documents, non-autoloaded), option
`wsp_mcp_first_success` (timestamp of the first successful tool call — gates the review notice),
user meta `wsp_mcp_review_notice` (`'dismissed'` or a snooze-until timestamp), DB tables
`{prefix}wsp_mcp_sessions`, `{prefix}wsp_mcp_audit_log`, `{prefix}wsp_mcp_oauth_clients`,
`{prefix}wsp_mcp_oauth_codes`, `{prefix}wsp_mcp_oauth_tokens`, `{prefix}wsp_mcp_redirects`, `{prefix}wsp_mcp_404_log` (+ options
`wsp_mcp_redirects_db_version`, `wsp_mcp_redirects_count`), cron events
`wsp_mcp_session_cleanup`, `wsp_mcp_audit_log_cleanup`, `wsp_mcp_oauth_cleanup`, `wsp_mcp_404_cleanup`.
All of the above are removed in `uninstall.php`.

---

## Core hooks (registered in main file)

| Hook | Callback | Purpose |
|---|---|---|
| `admin_menu` | `wsp_mcp_add_menu` | Top-level "MCP" menu + Settings/Config submenus (Connection submenu added in `connection-page.php`) |
| `admin_init` | `wsp_mcp_register_settings` | Registers `wsp_mcp_abilities` option with Settings API |
| `plugins_loaded` | `WSP_MCP_Server::init` | **v2.0** — builds tool registry + registers the native REST endpoint |
| `plugins_loaded` | `wsp_mcp_maybe_upgrade_db` | **v2.0** — heals table on upgrade (db_version gate) |
| `wsp_mcp_session_cleanup` | `WSP_MCP_Session_Store::cleanup_expired` | **v2.0** — daily expired-session purge |
| `admin_init` | `wsp_mcp_redirect_legacy_config_page` | **v2.2** — redirects the removed `page=wsp-mcp-config` URL to MCP > Connection |

Activation (`wsp_mcp_activate`): create sessions table, ensure API key, schedule cron.
Deactivation (`wsp_mcp_deactivate`): clear cron.

---

## Registry (`includes/registry.php`)

### Key functions

**`wsp_mcp_ability_registry()`** — returns the master array of all abilities. Each entry:
```php
'wsp/ability-key' => [
    'label'       => 'Human label',
    'description' => 'What it does',
    'group'       => 'Posts',         // display group in admin UI
    'access'      => 'read'|'write',  // used for UI badge + write confirmation prompt
    'default'     => true|false,      // whether enabled out of the box
]
```
Elementor abilities are only appended if `\Elementor\Plugin` class exists; WooCommerce abilities if
`class_exists('WooCommerce')`; ACF abilities if `wsp_acf_is_active()`
(`class_exists('ACF') || function_exists('get_field')`).

**`wsp_mcp_get_settings()`** — merges saved option with registry defaults. Returns `['wsp/key' => bool]`.

**`wsp_mcp_is_enabled($key)`** — returns `true` if a given ability is toggled on.

**`wsp_mcp_sanitize_settings($input)`** — sanitize callback for Settings API. Casts each known key to bool.

---

## Ability modules

Each file in `includes/abilities/` contains one `wsp_execute_*()` callback per ability — the
actual business logic, returning an array or `WP_Error`. These callbacks are wired to the native
MCP server in `includes/tools/native-tools.php` (`WSP_MCP_Server::register_tool()`), and the
admin toggle for each is driven by its entry in `wsp_mcp_ability_registry()` (`registry.php`).

> As of v2.2 there are **no** `wsp_register_*_abilities()` functions or `wp_register_ability()`
> calls — the dual-mode Abilities-API path was removed. Files like `yoast.php`, `elementor.php`,
> and `woocommerce.php` keep their plugin-specific helper functions (e.g. `wsp_yoast_is_active()`,
> `wsp_woo_sideload_image_by_url()`) alongside the execute callbacks.

### Ability reference table

#### Posts (`posts.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-posts` | Get Blog Posts | read | ON | `__return_true` | `per_page` (int), `status` (publish\|draft\|all) |
| `wsp/get-post` | Read Post | read | OFF | `edit_posts` + `read_post` (object) | `id`* |
| `wsp/create-post` | Create Post | write | OFF | `publish_posts` | `title`*, `content`*, `status`, `categories[]`, `tags[]`, `excerpt`, `slug` |
| `wsp/update-post` | Update Post | write | OFF | `edit_posts` | `id`*, `title`, `content`, `status`, `categories[]`, `tags[]` |
| `wsp/delete-post` | Delete Post | write | OFF | `delete_posts` | `id`* |

- Delete moves to trash, not permanent deletion.
- `status=all` expands to `['publish','draft','pending','future']`.
- `get-post` (v2.9.0) returns one post in **any** status (including trash) with full `post_content`, pinned to `post_type === 'post'`. Not-found and forbidden return `WP_Error( 'not_found' | 'forbidden' )` — never a plain array — so `do_tools_call()` logs them as error/denied rather than success.

#### Pages (`pages.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-pages` | Get Pages | read | ON | `__return_true` | none |
| `wsp/create-page` | Create Page | write | OFF | `publish_pages` | `title`*, `content`*, `status`, `parent` (int), `slug` |
| `wsp/update-page` | Update Page | write | OFF | `edit_pages` | `id`*, `title`, `content`, `status` |
| `wsp/delete-page` | Delete Page | write | OFF | `delete_pages` | `id`* |

#### Taxonomy (`taxonomy.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-categories` | Get Categories | read | ON | `__return_true` | none |
| `wsp/create-category` | Create Category | write | OFF | `manage_categories` | `name`*, `description`, `parent` (int) |
| `wsp/get-tags` | Get Tags | read | ON | `__return_true` | none |
| `wsp/create-tag` | Create Tag | write | OFF | `manage_categories` | `name`*, `description` |

- `get_categories` uses `hide_empty => false` so all categories appear.

#### Comments (`comments.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-comments` | Get Comments | read | OFF | `moderate_comments` | `status` (hold\|approve\|all), `per_page` |
| `wsp/approve-comment` | Approve Comment | write | OFF | `moderate_comments` | `id`* |
| `wsp/delete-comment` | Delete Comment | write | OFF | `moderate_comments` | `id`* |

#### Media (`media.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/list-media` | List Media | read | OFF | `upload_files` | `per_page`, `page`, `type` (MIME e.g. `image`), `search`, `year`, `month` |
| `wsp/get-media` | Get Media | read | OFF | `upload_files` | `id` (req) — returns full metadata for one attachment |
| `wsp/count-media` | Count Media | read | OFF | `upload_files` | — (counts grouped by MIME type + total) |
| `wsp/update-media` | Update Media | write | OFF | `upload_files` | `id` (req), `title`, `alt`, `caption`, `description` |
| `wsp/delete-media` | Delete Media | write | OFF | `delete_posts` | `id` (req) — permanent `wp_delete_attachment()` |
| `wsp/upload-media` | Upload Media | write | OFF | `upload_files` | **`data`** (base64) *or* `url` (one req), `mime_type`, `filename`, `title`, `alt`, `caption`, `post_id` |
| `wsp/upload-media-from-url` | Upload Media From URL | write | OFF | `upload_files` | `url` (req), `filename`, `title`, `alt`, `caption`, `post_id` |

- `wsp/get-media` returns the full metadata of a **single** attachment by ID (browse/search moved to `wsp/list-media`).
- Uploads sideload via `download_url()` (URL path) or `wp_tempnam()` + `file_put_contents()` (base64 path), then `media_handle_sideload()` (requires `wp-admin/includes/{file,media,image}.php`, loaded inside the callback). `wsp_execute_upload_media()` routes to `wsp_execute_upload_media_from_data()` when a non-empty `data` (base64) input is present, otherwise to `wsp_execute_upload_media_from_url()`. The base64 path (v2.6.6, fixes #17) strips an optional `data:<mime>;base64,` prefix, normalizes URL-safe base64, strictly validates via `base64_decode(..., true)`, and infers the extension from `filename`/`mime_type`/data-URI. Both paths accept only image types (jpg, png, gif, webp).
- `wsp_media_item_data()` is the shared normalizer used by get/update/upload responses.

#### Users (`users.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-users` | Get Users | read | OFF | `list_users` | none |
| `wsp/create-user` | Create User | write | OFF | `create_users` | `username`*, `email`*, `password` (auto-generated if omitted), `role` (default `subscriber`), `display_name` |
| `wsp/update-user` | Update User | write | OFF | `edit_users` | `id`*, `email`, `display_name`, `role`, `password` |

- `get-users` returns: `id`, `display_name`, `email`, `roles[]`, `registered`.
- `create-user` / `update-user` added via PR #42. Errors come back as `array( 'success' => false, 'error' => … )`, not `WP_Error`.
- **No delete-user tool.** `wsp/delete-user` shipped in PR #42 but failed in testing, so its callback,
  native-tool registration, and registry entry were all removed before release. Don't reintroduce it
  without testing it end to end.

#### Search (`search.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/search` | Search Content | read | ON | `__return_true` | `query`* |

- Uses `WP_Query` with `s` param. Searches posts and pages. Returns top 10, publish only.

#### Site (`site.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-site-info` | Get Site Info | read | ON | `__return_true` | none |
| `wsp/get-plugins` | Read Plugins | read | OFF | `activate_plugins` | none |
| `wsp/update-site-info` | Update Site Info | write | OFF | `manage_options` | `name`, `tagline`, `admin_email` |
| `wsp/update-permalink-structure` | Update Permalink Structure | write | OFF | `manage_options` | `structure`* (e.g. `/%postname%/`; empty string = plain) |
| `wsp/update-site-context` | Update Site Context | write | OFF | `manage_options` | `file`* (agents\|changelog), `content`*, `mode` (replace\|append\|prepend), `enable` — callback lives in `context.php`, see "### Site Context" |
| `wsp/activate-plugin` | Activate Plugin | write | OFF | `activate_plugins` | `file`* (e.g. `akismet/akismet.php`) |
| `wsp/deactivate-plugin` | Deactivate Plugin | write | OFF | `activate_plugins` | `file`* |

- `get-site-info` returns: `name`, `url`, `tagline`, `admin_email`, `wp_version`, `language`.
- `get-plugins` loads `wp-admin/includes/plugin.php` if needed, then intersects all plugins with active list.
- `deactivate-plugin` refuses to deactivate this plugin itself. Otherwise the MCP connection would cut itself off.

#### Custom Post Types (`cpt.php`) — added v2.9.3 (PR #43)

All OFF by default. Settings-page icon: 🗂️. Every tool takes a `post_type` slug resolved by
`wsp_cpt_resolve_type()`, which **only accepts public, non-built-in types** (posts/pages/attachments
and block/template types are refused with `reserved_type` — they have their own tools; internal types
like `user_request`, orders, form definitions return `not_found`) **and requires the type's own
`cap->edit_posts`** (e.g. WooCommerce `edit_products`), not just the tool's broad capability.

| Ability key | Label | Access | Tool capability | Inputs |
|---|---|---|---|---|
| `wsp/get-post-types` | Read Post Types | read | `edit_posts` | none |
| `wsp/get-cpt-items` | Read CPT Items | read | `edit_posts` | `post_type`*, `per_page`, `status` (publish\|draft\|pending\|future\|private\|all) |
| `wsp/create-cpt-item` | Create CPT Item | write | `publish_posts` | `post_type`*, `title`*, `content`, `status` (default draft), `slug` |
| `wsp/update-cpt-item` | Update CPT Item | write | `edit_posts` | `post_type`*, `id`*, `title`, `content`, `status` |
| `wsp/delete-cpt-item` | Delete CPT Item | write | `delete_posts` | `post_type`*, `id`* (trash, not permanent) |

- Object-level guards: update → `wsp_mcp_guard_edit_post()` + `wsp_mcp_guard_post_status()`; delete →
  `wsp_mcp_guard_delete_post()`; create checks the type's `cap->create_posts`, and `cap->publish_posts`
  for publish/future/private. `get-cpt-items` limits non-published results to the caller's own items
  unless they hold the type's `cap->edit_others_posts`.

#### Themes (`themes.php`) — added v2.9.1

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-themes` | Read Themes | read | OFF | `switch_themes` | none |
| `wsp/switch-theme` | Switch Theme | write | OFF | `switch_themes` | `theme`* (stylesheet slug) |

- `switch-theme` checks the slug against `wp_get_themes()` before calling `switch_theme()`. Settings-page icon: 🎨.

#### Menus (`menus.php`) — added v2.9.0

All nine require `edit_theme_options` (the capability WP core's menu editor and REST menus controller use). Menus are site-wide, not per-user content, so there is no object-level guard. All OFF by default. Settings-page icon: 🧭.

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/get-menus` | Read Menus | read | none |
| `wsp/get-menu-items` | Read Menu Items | read | `menu`* (id\|slug\|name) |
| `wsp/create-menu` | Create Menu | write | `name`* |
| `wsp/delete-menu` | Delete Menu | write | `menu`* |
| `wsp/add-menu-item` | Add Menu Item | write | `menu`*, `type`* (custom\|post\|page\|category), `title`, `url`, `object_id`, `parent`, `order` |
| `wsp/update-menu-item` | Update Menu Item | write | `item_id`*, `title`, `url`, `parent`, `order` |
| `wsp/delete-menu-item` | Delete Menu Item | write | `item_id`* |
| `wsp/get-menu-locations` | Read Menu Locations | read | none |
| `wsp/assign-menu-location` | Assign Menu Location | write | `location`*, `menu` (id; omit or 0 to unassign) |

- `menu` is resolved with `wp_get_nav_menu_object()`, so ID, slug, or name all work.
- `add-menu-item`: `custom` needs `title` + `url`; `post`/`page`/`category` need `object_id`. For `post`/`page` the resolved `post_type` **must equal** the requested `type` or the tool returns `object_id: {type} not found.` — a `page` type with a blog-post ID is rejected, not silently stored.
- `update-menu-item` reads the current item via `wp_setup_nav_menu_item()` and only overrides the fields supplied; `type`/`object`/`object_id` are preserved.
- `delete-menu-item` calls `wp_delete_post( $id, true )` (force-delete, no trash).
- `assign-menu-location` writes the `nav_menu_locations` theme mod directly; unknown location slugs are rejected against `get_registered_nav_menus()`.

#### Site Editor — Global Styles + Templates (`site-editor.php`) — added v2.9.4

All six require `edit_theme_options` (what core's global-styles and templates REST controllers use). Site-wide structures, so no object-level guard. All OFF by default. Not plugin-gated (always registered) — callbacks return `WP_Error( 'not_block_theme' )` at call time when the active theme is classic (templates also accept classic themes with `block-templates` support). Settings-page icon: 🎨. All errors are `WP_Error`, never `success => false` arrays.

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/get-global-styles` | Read Global Styles | read | `origin` (user\|merged), `include_variations` (bool) |
| `wsp/update-global-styles` | Update Global Styles | write | `settings` (object), `styles` (object), `variation_slug`, `merge` (bool, default true) — at least one of the first three |
| `wsp/get-templates` | List Templates | read | `type` (template\|template_part), `area`, `search`, `per_page` (1–100, default 10), `page` |
| `wsp/get-template` | Read Template | read | `template_id`* (`theme//slug`), `type` |
| `wsp/create-template` | Create Template | write | `slug`*, `title`, `content`, `description`, `type`, `area` |
| `wsp/update-template` | Update Template | write | `template_id`*, `type`, `title`, `content`, `description`, `area` |

- **Global styles storage:** the user layer of theme.json lives in the active theme's `wp_global_styles` post (ID from `WP_Theme_JSON_Resolver::get_user_global_styles_post_id()`), JSON with `version` / `isGlobalStylesUserThemeJSON` / `settings` / `styles`. `origin=merged` returns `wp_get_global_settings()` / `wp_get_global_styles()` instead.
- **Merge semantics** (`wsp_site_editor_merge()`): associative arrays merge key-by-key; lists (palettes, font families) and scalars replace wholesale; `null` deletes the key (falls back to theme). `merge=false` replaces `settings`/`styles` entirely.
- **Variations:** `wsp_site_editor_style_variations()` wraps `WP_Theme_JSON_Resolver::get_style_variations()`, classifies each as `full` / `color` / `typography`, and assigns unique slugs (partials sharing a full variation's title get `-color`/`-typography`; collisions get `-2`, `-3`, …). Applying a full variation replaces the user layer; a partial merges into it. Applied before `settings`/`styles` input.
- **Custom CSS guard (do not regress):** `css` keys are stripped from caller `styles` input (`wsp_site_editor_strip_custom_css()`), so MCP can never set custom CSS. The existing top-level `styles.css` (saved by an admin in the Site Editor) is carried over on every write so a replace/full variation doesn't wipe it. `WP_Theme_JSON::remove_insecure_properties()` runs only for users without `unfiltered_html`, matching core — running it for admins drops valid non-preset settings.
- **Templates:** `type` maps to `wp_template` / `wp_template_part`. Returned rows carry `id, slug, title, description, type, area, status, source, has_theme_file, is_customized` (+ `content` from get/create/update). `create` refuses an existing `theme//slug`. `update` on a theme-file template without a DB copy (`wp_id` empty) inserts the override post, seeded from the theme file for fields not supplied — the same thing the Site Editor does on first save. Template-part `area` is validated against `get_allowed_block_template_part_areas()`.
- **Terms:** `wp_theme` / `wp_template_part_area` are set with `wp_set_object_terms()`, never `tax_input` — `wp_insert_post()` silently skips `tax_input` unless the user can `assign_terms`, which would leave an orphaned template not tied to any theme.
- Caller-supplied template `content` goes through `wp_kses_post()` but **not** `wp_unslash()` (see Security patterns); post arrays are `wp_slash()`ed before `wp_insert_post()`/`wp_update_post()` so backslashes in block-comment JSON survive.

#### Widgets & Sidebars (`widgets.php`) — added v2.9.4

All eight require `edit_theme_options` (Appearance > Widgets / core REST widgets controller). All OFF by default. Not plugin-gated — on a block theme (no registered sidebars) every tool returns `WP_Error( 'no_widget_areas' )` pointing at the template tools, except `get-sidebars`, which returns `{ sidebars: [], total: 0, hint }`. Settings-page icon: 🧱.

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/get-sidebars` | Read Sidebars | read | none — includes `wp_inactive_widgets` with `status: inactive` |
| `wsp/update-sidebar` | Update Sidebar | write | `sidebar`*, `widgets`* (ordered id list; `[]` empties) |
| `wsp/get-widget-types` | Read Widget Types | read | none |
| `wsp/get-widgets` | Read Widgets | read | `sidebar` |
| `wsp/get-widget` | Read Widget | read | `widget_id`* — adds `rendered` HTML (`wp_render_widget()`) |
| `wsp/create-widget` | Create Widget | write | `sidebar`*, `id_base`*, `instance`, `position` |
| `wsp/update-widget` | Update Widget | write | `widget_id`*, `instance`, `sidebar`, `position` (≥1 of the last three) |
| `wsp/delete-widget` | Delete Widget | write | `widget_id`*, `force` (default false = move to inactive) |

- **Storage:** instances live in option `widget_{id_base}` keyed by number (read/written via `WP_Widget::get_settings()` / `save_settings()`); placement in option `sidebars_widgets` (read with the plugin's own `wsp_widgets_get_sidebars()`, written with `wp_set_sidebars_widgets()`). **Never call core's `wp_get_sidebars_widgets()`** — it is `@access private` and Plugin Check fails it as `Generic.PHP.ForbiddenFunctions.Found`; the helper reads the raw option (drops `array_version`, normalizes lists), which is also the correct input for read-modify-write since core's version applies the runtime `sidebars_widgets` filter. Widget ids are `{id_base}-{number}`, parsed with `wp_parse_widget_id()`.
- **Writes go through the widget's own `WP_Widget::update( $new, $old )`** so each type sanitizes as in Appearance > Widgets; `update()` returning `false` → `WP_Error( 'rejected' )`. `update-widget` merges `instance` over the stored one first, so omitted keys survive. New instance number = highest existing + 1 (min 2); `_set()` + `_register_one()` register it for the current request so it can be rendered.
- **`settings_editable` = the type's `show_instance_in_rest` opt-in** (core's REST rule). Non-opted-in and legacy (non-`WP_Widget`) widgets: settings are `null`, create / instance-edit refused with `WP_Error( 'read_only' )`, move/reorder/delete still allowed. Don't relax this — third-party widgets can store API keys in their instance.
- **Code-insertion guard (do not regress):** `wsp_widgets_sanitize_instance()` runs `wp_kses_post()` over every string in caller `instance` *before* `update()`, regardless of `unfiltered_html` — this is what keeps `custom_html` / `text` / `block` from injecting `<script>`.
- **Positioning:** `wsp_widgets_place()` removes the id from every sidebar then inserts at the 0-based `position` (null / past end = last). Responses include `{ position, sidebar_widgets }` when `position` was given.
- **No sidebar create/delete tool** — sidebars come from `register_sidebar()` in theme PHP on every load, so a runtime create/delete can't persist (same reasoning as the dropped ACF delete-options-page tool). `update-sidebar` is the sidebar-level write: listed widgets are pulled in from anywhere, widgets it drops go to `wp_inactive_widgets` (never deleted).

#### Site Health, Cron & Error Log (`health.php`) — added v2.9.4

All OFF by default. Settings-page group **Site Health & Cron**, icon 🩺. Cron and error-log tools additionally call `wsp_health_require_network_admin()` — on multisite only a super admin (`WP_Error( 'forbidden' )` otherwise), because cron and the PHP log are shared network/server-wide.

| Ability key | Label | Access | Capability | Inputs |
|---|---|---|---|---|
| `wsp/get-site-health` | Read Site Health | read | `view_site_health_checks` (core meta cap) | `include_async` (bool, default false), `include_info` (bool, default true) |
| `wsp/get-cron-events` | List Cron Events | read | `manage_options` | `hook` (substring), `limit` (1–500, default 50) |
| `wsp/get-cron-event` | Inspect Cron Event | read | `manage_options` | `hook`*, `key` |
| `wsp/run-cron-event` | Run Cron Event | write | `manage_options` | `hook`*, `key` (required if the hook has >1 instance) |
| `wsp/delete-cron-event` | Unschedule Cron Event | write | `manage_options` | `hook`*, `key`, `all` (bool) |
| `wsp/get-error-log` | Read Error Log | read | `manage_options` | `lines` (1–1000, default 100), `grep` |

- **Site Health:** `wsp_health_load_admin_includes()` `require_once`s the wp-admin files `WP_Site_Health` / `WP_Debug_Data` need (not loaded on REST requests). Direct tests run via `get_test_{name}` or a callable `test`; async tests (only with `include_async`) run via their `async_direct_test` callback, else land in `skipped`. Every result passes through core's `site_status_test_result` filter, and every test is wrapped in `try/catch (Throwable)` so a broken third-party test becomes `status: error` instead of failing the call. Info drops fields with `private` (core's "Copy site info" set — DB credentials, prefix, paths), skips `wp-paths-sizes`, redacts values, caps 150 fields/section (`info_truncated`).
- **Cron reads** use `_get_cron_array()` + `wp_get_schedules()`. Each event has a `key` (core's md5 of its args) to address one instance. `has_callback` = `has_action( $hook )`; `false` means orphaned (its plugin is gone).
- **`run-cron-event`** runs only an **already-scheduled** event: `do_action_ref_array( $hook, $args )` in the MCP request, same as wp-cron.php. One-off events are unscheduled first (as wp-cron.php does); recurring ones keep their next run. Output is captured by unwinding every buffer above the starting `ob_get_level()` (so a callback that leaves a buffer open can't leak into the JSON response), then plain-texted, redacted, capped at 2000 chars. Refused when no callback is attached. A callback that calls `exit`/`die` will still kill the request — unavoidable.
- **Invariants (do not regress):** (1) **No schedule-new-event tool** — an arbitrary hook + args is a primitive for firing any action. (2) **`delete-cron-event` refuses `wsp_mcp_*` hooks** — they're only re-added in `wsp_mcp_activate` / `wsp_mcp_maybe_upgrade_db`, so removing one silently stops session / audit-log / OAuth cleanup until the next version bump. (3) **Error log reads only** the `error_log` ini path (if a readable file, not `syslog`) or `WP_CONTENT_DIR/debug.log` — never a caller-supplied path. Last ≤2 MB scanned, lines capped at 2000 chars, response capped at 64 KB (oldest dropped, `truncated: true`), path shown relative to `ABSPATH` or as basename only.
- **Redaction** (`wsp_health_redact()` / `_deep()`): applied to cron args, run output, error-log lines and Site Health Info values — `define()`d keys/salts/secrets, Bearer/Basic tokens, `user:pass@` URL credentials, JWTs, `password=` / `token:` / `api_key=`-style pairs, and opaque tokens ≥40 chars. Error-log `grep` matches *after* redaction, so it can't be used to probe redacted secrets.

#### Revisions (`revisions.php`) — unreleased

Works on any post type with `revisions` support (posts, pages, CPTs). All OFF by default; settings group **Revisions** (🕘). Tool capability is `edit_posts`; the real gate is the **parent post's** `edit_post` (via `wsp_mcp_guard_edit_post()`), matching core's revisions REST controller.

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/get-revisions` | List Revisions | read | `post_id`*, `limit` (1–100, default 20) |
| `wsp/get-revision` | Read Revision | read | `id`* (revision ID) — full title/content/excerpt plus a `current` block of the live post |
| `wsp/restore-revision` | Restore Revision | write | `id`* |

- Each list row carries `changed_fields` (which of title/content/excerpt differ from the live post) and `is_autosave`.
- Restore uses `wp_restore_post_revision()`: restores title, content, excerpt only (not status, slug, taxonomies, meta unless registered for revisions). `wp_update_post()` snapshots the live version as a new revision first, so a restore is undoable. All errors are `WP_Error`.

#### Post Meta (`post-meta.php`) — unreleased

Any post type except `revision`. All OFF by default; settings group **Post Meta** (🏷️). Tool capability `edit_posts`; real gates are `wsp_mcp_guard_edit_post()` (object) plus core's meta caps `edit_post_meta` / `delete_post_meta` (per key).

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/get-post-meta` | Read Post Meta | read | `post_id`*, `key` (omit = all public meta, max 200), `single` (default true) |
| `wsp/update-post-meta` | Update Post Meta | write | `post_id`*, `key`*, `value`* (any JSON type), `prev_value` |
| `wsp/delete-post-meta` | Delete Post Meta | write | `post_id`*, `key`*, `value` |

- **Protected keys (leading underscore, `is_protected_meta()`) are refused for read, update and delete** and hidden from the all-meta listing — keeps `_elementor_data` (code-bearing, see Elementor write guards), `_thumbnail_id`, `_wp_*`, and other plugins' secrets out of reach. Don't add a bypass; use dedicated tools (Yoast, Elementor, ACF).
- Keys validated `^[A-Za-z0-9_\-:.]{1,255}$`. Values sanitized recursively with `wp_kses_post()` (`wsp_post_meta_sanitize_value()`), then `wp_slash()`ed because `update_post_meta()` unslashes (so no `wp_unslash()` on input — same reasoning as block markup).
- `update_post_meta()` returns false for both failure and "unchanged"; the callback tells them apart and reports `changed: false`. Delete returns the `previous_value`; post meta has no trash.

#### Blocks / Gutenberg (`blocks.php`) — unreleased

Nine tools, all OFF by default; settings group **Blocks** (🧩). "Blocks" = reusable blocks (`wp_block` posts). Patterns and block types are read-only registry views.

| Ability key | Label | Access | Capability | Inputs |
|---|---|---|---|---|
| `wsp/list-blocks` | List Reusable Blocks | read | `edit_posts` | `search`, `status`, `per_page`, `page` |
| `wsp/get-block` | Read Reusable Block | read | `edit_posts` | `id`*, `parse` |
| `wsp/create-block` | Create Reusable Block | write | `publish_posts` | `title`*, `content`*, `status`, `slug`, `sync_status` |
| `wsp/update-block` | Update Reusable Block | write | `edit_posts` | `id`*, `title`, `content`, `status`, `sync_status` |
| `wsp/delete-block` | Delete Reusable Block | write | `delete_posts` | `id`*, `force` (default trash) |
| `wsp/list-patterns` | List Block Patterns | read | `edit_posts` | `search`, `category`, `include_content`, `limit` |
| `wsp/get-post-blocks` | Read Post Blocks | read | `edit_posts` | `post_id`* |
| `wsp/update-post-blocks` | Update Post Blocks | write | `edit_posts` | `post_id`*, exactly one of `blocks[]` \| `content` |
| `wsp/list-block-types` | List Block Types | read | `edit_posts` | `search`, `namespace`, `include_attributes`, `limit` |

- Update/delete use `wsp_mcp_guard_edit_post/delete_post( $id, 'wp_block' )`; create checks the type's `cap->create_posts` / `cap->publish_posts`; status changes go through `wsp_mcp_guard_post_status()`. Post-level tools use `wsp_mcp_guard_edit_post()` and refuse revision/attachment/nav_menu_item.
- Sync mode = meta `wp_pattern_sync_status`: `unsynced` stored, `synced` = meta deleted (matches the editor).
- **`update-post-blocks`:** `wsp_blocks_normalize()` validates the tree (registered block names only, `^[a-z0-9-]+/[a-z0-9-]+$`, max 2000 nodes / 20 deep, `innerContent` must hold one `null` per inner block), strings in `attrs` are kses'd, then `serialize_blocks()` and a final `wp_kses_post()` over the markup. `wp_slash()` before `wp_update_post()`, no `wp_unslash()` (block-markup rule). Replaces ALL content; WP stores the old version as a revision.
- `delete-block` and `get-block` report `used_in` (posts embedding `{"ref":ID}`, caller-editable only, max 50).

#### Redirects & 404 Manager (`redirects.php` + `includes/seo/class-redirects.php`) — unreleased

Five tools, all OFF by default, all `manage_options`; settings group **Redirects & 404** (↪️). Logic = callbacks in `abilities/redirects.php`; storage + front-end runtime = class `WSP_MCP_Redirects`.

| Ability key | Label | Access | Inputs |
|---|---|---|---|
| `wsp/list-redirects` | List Redirects | read | `search`, `per_page`, `page` |
| `wsp/create-redirect` | Create Redirect | write | `source`*, `destination`*, `status_code` (301\|302, default 301), `allow_external`, `note` |
| `wsp/delete-redirect` | Delete Redirect | write | `id`* |
| `wsp/get-404-logs` | Read 404 Log | read | `limit`, `orderby` (recent\|hits), `search`, `min_hits`, `days`, `unresolved_only` |
| `wsp/clear-404-logs` | Clear 404 Log | write | `id` \| `all` |

- **Tables:** `{prefix}wsp_mcp_redirects` (unique `source_hash`) and `{prefix}wsp_mcp_404_log` (one aggregated row per path, unique `path_hash`, upserted with `ON DUPLICATE KEY`). Option `wsp_mcp_redirects_db_version` is the class's **own** schema gate (`WSP_MCP_Redirects::init()` on `plugins_loaded` → `install()`), independent of `wsp_mcp_db_version`, because the plugin version was not bumped when this shipped. Autoloaded option `wsp_mcp_redirects_count` lets requests skip the lookup query when no redirects exist. Cron `wsp_mcp_404_cleanup` (daily; retention filter `wsp_mcp_404_log_retention_days`, default 30). All removed in `uninstall.php`.
- **Runtime (`template_redirect`):** priority 1 `handle_redirect()` — exact path match (case-insensitive, trailing slash and home sub-directory ignored, query string ignored, GET/HEAD only), one indexed query, hit counter update, `wp_redirect()` + `exit`. Priority 999 `log_404()` — only when `is_404()` **and** `wsp/get-404-logs` is enabled (admin opt-in; turning the ability off stops recording). Redirects keep working when their tools are toggled off — they are site configuration.
- **Invariants (do not regress):** (1) destinations are `/path` or http(s) URLs; **other domains need `allow_external=true`** (open-redirect/phishing primitive), `//host`, backslashes, credentials refused — this is why plain `wp_redirect()` is acceptable at runtime; (2) sources under `/wp-admin`, `/wp-login.php`, `/wp-json`, `/wp-cron.php`, `/xmlrpc.php` and `/` are refused (a redirect on `/wp-json` would sever the MCP endpoint); (3) loops (direct or via ≤10 hops) refused on create, plus a runtime self-redirect guard; (4) **404 log stores no IPs, strips query strings from path and referrer**, is capped at 1000 rows (random-1/20 prune on insert + daily cron); (5) caps: 1000 redirects, 500-char paths.
- No update/toggle-redirect tool (delete + create). No regex/wildcard matching.

#### Theme upload (`theme-upload.php`) — added v2.9.4

Lives in the existing **Themes** settings group (🎨) next to Read Themes / Switch Theme (`themes.php`, v2.9.1). OFF by default. Not plugin-gated. **Keep the handler in `theme-upload.php`, not `themes.php`** — it was first written into `themes.php`, the 2.9.3 merge kept upstream's version of that file, `wsp_execute_upload_theme()` vanished, and every call failed with "tool has no handler" (the `is_callable()` check in `WSP_MCP_Server::do_tools_call()`).

| Ability key | Label | Access | Capability | Inputs |
|---|---|---|---|---|
| `wsp/upload-theme` | Upload / Install Theme | write | `install_themes` | exactly one of `files` (+ `slug`*, `binary_files`) \| `data` \| `url`; `overwrite` (bool), `activate` (bool) |

- **Why it exists:** AI-generated themes had no install path — an agent could write theme files but not get them into `wp-content/themes`. `files` (path → text) is the source agents should use; it is zipped server-side under `{slug}/` (`ZipArchive`, fallback to core's bundled `PclZip` with `PCLZIP_ATT_FILE_CONTENT`). `binary_files` (path → base64) covers `screenshot.png` / fonts. `data` = base64 zip, `url` = http(s) zip (`download_url()` → `wp_safe_remote_get()`, so private/loopback hosts are refused).
- **Install always goes through core's `Theme_Upgrader::install( $zip, [ 'overwrite_package' => $overwrite ] )`** with a `WP_Ajax_Upgrader_Skin` — the Appearance > Themes > Upload code path. Core therefore does the authoritative validation (style.css `Theme Name`, `index.php` or `templates/index.html`, Requires PHP/WP), installs a missing parent from WordPress.org, and handles replace + rollback. Don't hand-roll file writes into the themes directory.
- **Capability:** `install_themes` — `map_meta_cap()` turns this into `do_not_allow` under `DISALLOW_FILE_MODS` and for non-super-admins on multisite, so both are enforced without extra code (the callback also checks `wp_is_file_mod_allowed()` for a clearer message). `activate=true` additionally needs `switch_themes`; on multisite a non-network-enabled theme is network-enabled only if the caller has `manage_network_themes`. Activation runs `validate_theme_requirements()` then `switch_theme()`; an activation failure is returned as `activation_error` alongside the successful install, not as a tool error.
- **Path guard (do not regress):** `wsp_theme_normalize_path()` rejects absolute / drive-letter paths, `..`, empty segments, hidden (dot) segments (no `.htaccess`), characters outside `[A-Za-z0-9._-]`, and any extension outside `wsp_theme_text_extensions()` / `wsp_theme_binary_extensions()`. Limits: `WSP_THEME_MAX_FILES` (1000) and `WSP_THEME_MAX_BYTES` (20 MB decoded).
- **Content is NOT sanitized** — a theme *is* PHP code. This is deliberate and identical to core's upload screen; the safety boundary is the admin-only capability + OFF-by-default toggle. Text content is stored verbatim (no `wp_unslash()`, same reasoning as block markup).
- Without `overwrite`, an existing slug is refused (`theme_exists` / core's `folder_exists`, both telling the agent about `overwrite`). If `WP_Filesystem()` can't get direct/credentialed access, returns `filesystem_unavailable` instead of an FTP prompt. Upgrader output is buffered and discarded so it can't corrupt the JSON response.

#### WooCommerce store management (`woocommerce-catalog.php`, `woocommerce-store.php`) — added v2.9.5

Registered from the table in `includes/tools/admin-tool-defs.php` (`wsp_mcp_woo_admin_tool_defs()`), which feeds **both** `wsp_mcp_register_defs()` (MCP) and `wsp_mcp_defs_registry_rows()` (registry) — add a tool by adding a row there plus a `wsp_execute_<name>()` callback. All OFF by default, Woo-gated, group **WooCommerce**. Envelope `{ success, data, error }` (`wsp_woo_ok()` / `wsp_woo_fail()`); `wsp_woo_guard( $cap )` checks WooCommerce-active + capability. WooCommerce is driven through `wsp_woo_rest()` (internal `wc/v3` `rest_do_request()`) or CRUD classes — never raw SQL.

- **Delete** (`manage_woocommerce`): `wsp_woo_delete_product|variation|coupon` default to trash, `force=true` = permanent. `delete_category|tag|attribute|attribute_term|tax_rate|shipping_method` have no trash → **require `force=true`**.
- **Catalog:** `wsp_woo_get|create|update_product_category`, `get|create_product_tag`, `get|create|update|delete_attribute`, `get|create|delete_attribute_term`. `wsp_woo_create_product`/`update_product` accept `categories`/`tags` (term IDs, validated) and `attributes` via `wsp_woo_build_attributes()` (custom or global; `variation` defaults true only for variable products). `wsp_create_category` takes `taxonomy` (`category`|`product_cat`).
- **Store (`manage_options` for settings + gateways, else `manage_woocommerce`):** `wsp_woo_get|update_settings` (groups general/products/tax/shipping/checkout/account/email; one REST PUT per option, unknown ids reported), tax (`get_tax_classes`, `create|update|delete_tax_rate`), shipping (`get_shipping_zones`, `create_shipping_zone`, `get_shipping_methods`, `add|update|delete_shipping_method`), `wsp_woo_get_payment_gateways`/`update_payment_gateway`.
- **Response hygiene (do not regress):** `wsp_woo_rest()` strips `_links`/`_embedded` from all data (`wsp_woo_strip_links()`). `wsp_woo_get_settings` omits select `options` by default (`options_count` shown); `include_options`, `setting_id`/`setting_ids` opt in, and `WSP_WOO_SETTINGS_MAX_CHARS` (50,000) drops options with `truncated_options:true`. Values are never dropped. Shipping zone 0 can't be edited or deleted; `update_shipping_zone` `locations` replaces the list; `delete_shipping_zone` also removes the zone's methods (no `force` param by design).
- **Secret masking (do not regress):** `wsp_woo_is_secret_field()` masks password-type and key/secret/token/webhook-named values as `WSP_WOO_SECRET_MASK` in every response; a masked value passed back in an update is skipped, never written.

#### Plugin management (`plugins.php`) — added v2.9.5

Table in `admin-tool-defs.php` (`wsp_mcp_plugin_admin_tool_defs()`), group **Site**, OFF by default, envelope as above. `wsp_install_plugin` (slug → `plugins_api()`), `wsp_install_plugin_from_url` (https only, no URL credentials, `wp_http_validate_url()`), `wsp_delete_plugin` (must be inactive; refuses this plugin), `wsp_update_plugin`. Caps `install_plugins` / `delete_plugins` / `update_plugins`; `wsp_plugins_guard()` also checks `wp_is_file_mod_allowed()` and `WP_Filesystem()`. Core `Plugin_Upgrader` + `WP_Ajax_Upgrader_Skin`, output buffered. Plugin code is not sanitized (it is PHP) — the boundary is the capability + OFF toggle, same as `wsp_upload_theme`. `wsp_get_plugins` returns `plugins` (all, with `active`/`update_available`/`new_version`) plus the legacy `active_plugins`/`total`.

#### Yoast SEO (`yoast.php`)

Only registered if `wsp_yoast_is_active()` (`defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')`). Both abilities require `edit_posts` capability.

| Ability key | Label | Access | Default | Inputs |
|---|---|---|---|---|
| `wsp/yoast-get-seo` | Get Yoast SEO Meta | read | OFF | `id`* (int — post/page ID) |
| `wsp/yoast-update-seo` | Update Yoast SEO Meta | write | OFF | `id`*, `seo_title`, `meta_description`, `focus_keyphrase` (at least one required) |

- `get-seo` returns: `post_id`, `post_type`, `title` (WP title), `seo_title`, `meta_description`, `focus_keyphrase`, `url`.
- `update-seo` rebuilds the Yoast indexable (`do_action('wp_insert_post', …)`) after saving.
- Falls back to direct post-meta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`) when `WPSEO_Meta` class is unavailable.
- Only supports `post` and `page` post types; returns `WP_Error` for others.

#### Elementor (`elementor.php`)

Only registered if `class_exists('\Elementor\Plugin')`. All abilities require `edit_posts` capability.

| Ability key | Label | Access | Default | Inputs |
|---|---|---|---|---|
| `wsp/elementor-list-pages` | List Elementor Pages | read | OFF | `post_type`, `status`, `per_page` |
| `wsp/elementor-get-page` | Get Page Structure | read | OFF | `post_id`* |
| `wsp/elementor-get-element` | Get Element Settings | read | OFF | `post_id`*, `element_id`* |
| `wsp/elementor-find-element` | Find Element | read | OFF | `post_id`*, `widget_type`, `search` |
| `wsp/elementor-list-templates` | List Templates | read | OFF | `type`, `per_page` |
| `wsp/elementor-update-element` | Update Element | write | OFF | `post_id`*, `element_id`*, `settings`* (object) |
| `wsp/elementor-add-widget` | Add Widget | write | OFF | `post_id`*, `widget_type`*, `container_id`, `settings`, `position` |
| `wsp/elementor-add-container` | Add Container | write | OFF | `post_id`*, `type` (container\|section), `parent_id`, `settings`, `position` |
| `wsp/elementor-remove-element` | Remove Element | write | OFF | `post_id`*, `element_id`* |
| `wsp/elementor-get-active-kit` | Get Active Kit | read | OFF | none |
| `wsp/elementor-update-active-kit` | Update Active Kit | write | OFF | `system_colors[]`, `container_width`, `space_between_widgets` |
| `wsp/elementor-regenerate-css` | Regenerate CSS | write | OFF | none |
| `wsp/elementor-get-widget-schema` | Get Widget Schema | read | OFF | `widget_type`* |
| `wsp/elementor-duplicate-element` | Duplicate Element | write | OFF | `post_id`*, `element_id`*, `parent_id`, `position` |
| `wsp/elementor-move-element` | Move Element | write | OFF | `post_id`*, `element_id`*, `new_parent_id`, `position` |
| `wsp/elementor-convert-css` | Convert CSS | read | OFF | `css`* (object) |
| `wsp/elementor-get-page-settings` | Get Page Settings | read | OFF | `post_id`* |
| `wsp/elementor-update-page-settings` | Update Page Settings | write | OFF | `post_id`*, `page_template`, `hide_title`, `content_width`, `background_color` |
| `wsp/elementor-copy-styles` | Copy Styles | write | OFF | `post_id`*, `source_id`*, `destination_id`*, `merge` |
| `wsp/elementor-get-breakpoints` | Get Breakpoints | read | OFF | none |

**Elementor data model:**
**Elementor Advanced Design Tools (v2.6.5):** added `get-active-kit`, `update-active-kit`, `regenerate-css`, `get-widget-schema`, `duplicate-element`, `move-element`, `convert-css`, `get-page-settings`, `update-page-settings`, `copy-styles`, `get-breakpoints`. `convert-css` parses CSS shorthand into Elementor settings format. `duplicate-element` / `clone-and-reid` recursively assigns new 8-char hex IDs. All write tools run through `wsp_elementor_sanitize_settings()`.
- Elementor page data is stored in `_elementor_data` post meta as a JSON-encoded recursive element tree.
- Each element: `{ id (8-char hex), elType, widgetType?, settings{}, elements[], isInner? }`
- Root elements are sections (legacy) or containers (modern). Widgets cannot be placed directly inside a section — they need a column inside the section.
- `wsp_elementor_get_data($post_id)` — reads and JSON-decodes `_elementor_data`. Returns `WP_Error` if not an Elementor page.
- `wsp_elementor_save_data($post_id, $data)` — JSON-encodes, saves with `wp_slash`, clears Elementor file cache.
- `wsp_elementor_generate_id()` — 8-char hex via `md5(uniqid(...))`.
- Helper functions for tree traversal: `wsp_elementor_find_by_id`, `wsp_elementor_remove_by_id`, `wsp_elementor_update_by_id`, `wsp_elementor_insert_into`, `wsp_elementor_first_insertable`, `wsp_elementor_simplify_tree`, `wsp_elementor_search_tree`.

**Elementor write guards (v2.3.1 — no arbitrary code):** every write tool (`add_widget`, `update_element`, `add_container`) must run incoming data through these before `wsp_elementor_save_data()`:
- `wsp_elementor_is_blocked_widget($widget_type)` — rejects code-bearing widget types (`html`, `shortcode`, `code`, `code-highlight`). `add_widget` returns an error for these.
- `wsp_elementor_sanitize_settings($settings)` — recursively drops code-bearing keys (`custom_css`, `_attributes`, `custom_attributes`, `__dynamic__`) and runs every string through `wp_kses_post()`. Applied in all three write tools.
- Rationale: WordPress.org prohibits arbitrary HTML/JS/CSS/PHP insertion; `_elementor_data` + the HTML widget was the vector. **Any new Elementor write tool must reuse both guards.**

#### WooCommerce (`woocommerce.php`)

Only registered if `class_exists('WooCommerce')`. All 15 tools are OFF by default (added in v2.1.0). MCP tool names use the `wsp_woo_*` form; enable keys use `wsp/woo-*`.

| Ability key | Label | Access | Default | Capability | Inputs |
|---|---|---|---|---|---|
| `wsp/woo-get-products` | List Products | read | OFF | `edit_posts` | `limit`, `status` |
| `wsp/woo-get-product` | Get Single Product | read | OFF | `edit_posts` | `id`* |
| `wsp/woo-create-product` | Create Product | write | OFF | `publish_posts` | `name`*, `regular_price`*, `sale_price`, `description`, `sku`, `status`, `type`, `image_url`, `attributes[]`, `stock_qty` |
| `wsp/woo-create-variation` | Create Variation | write | OFF | `publish_posts` | `parent_id`*, `regular_price`*, `attributes`*, `sale_price`, `sku`, `image_url` |
| `wsp/woo-update-product` | Update Product | write | OFF | `edit_posts` | `id`*, `name`, `regular_price`, `sale_price`, `description`, `sku`, `stock_qty`, `stock_status`, `image_url` |
| `wsp/woo-list-orders` | List Orders | read | OFF | `edit_posts` | `limit`, `status` |
| `wsp/woo-update-order-status` | Update Order Status | write | OFF | `edit_posts` | `id`*, `status`* (validated) |
| `wsp/woo-refund-order` | Refund Order | write | OFF | `manage_woocommerce` | `order_id`*, `amount`*, `reason` |
| `wsp/woo-create-coupon` | Create Coupon | write | OFF | `manage_woocommerce` | `code`*, `amount`*, `discount_type` (validated), `expiry_date` |
| `wsp/woo-list-coupons` | List Coupons | read | OFF | `manage_woocommerce` | `limit` |
| `wsp/woo-create-order-note` | Create Order Note | write | OFF | `edit_posts` | `id`*, `note`*, `is_public` |
| `wsp/woo-list-customers` | List Customers | read | OFF | `manage_woocommerce` | `limit` |
| `wsp/woo-report-sales` | Sales Report | read | OFF | `manage_woocommerce` | `days` |
| `wsp/woo-get-low-stock` | Low Stock Alerts | read | OFF | `edit_posts` | `threshold` |
| `wsp/woo-moderate-review` | Moderate Reviews | write | OFF | `edit_posts` | `id`*, `action`* (validated), `reply_text` |

- Financial/PII tools (`refund-order`, `create-coupon`, `list-coupons`, `list-customers`) require `manage_woocommerce`.
- Enum inputs (`status`, `discount_type`, `action`) are validated with `in_array(..., true)` in the execute callbacks.
- `wsp_woo_sideload_image_by_url()` downloads/attaches product images; SSL verification is bypassed only for the single download request and only on `local`/`development` environments.

#### Advanced Custom Fields (`acf.php`)

Only registered if `wsp_acf_is_active()` (`class_exists('ACF') || function_exists('get_field')`).
**27 functional tools**, all OFF by default (added via PR #8). MCP tool names use the `wsp_acf_*` form;
enable keys use `wsp/acf-*`. Structural tools (create/update/delete field groups, fields, CPTs,
taxonomies, options pages) require `manage_options`; list/read and value-edit tools require `edit_posts`.

| Ability key | Label | Access | Capability |
|---|---|---|---|
| `wsp/acf-list-field-groups` | List Field Groups | read | `edit_posts` |
| `wsp/acf-get-field-group` | Get Field Group | read | `edit_posts` |
| `wsp/acf-create-field-group` | Create Field Group | write | `manage_options` |
| `wsp/acf-update-field-group` | Update Field Group | write | `manage_options` |
| `wsp/acf-delete-field-group` | Delete Field Group | write | `manage_options` |
| `wsp/acf-import-field-groups` | Import Field Groups (JSON) | write | `manage_options` |
| `wsp/acf-list-fields` | List Fields in Group | read | `edit_posts` |
| `wsp/acf-get-field` | Get Field Config | read | `edit_posts` |
| `wsp/acf-create-field` | Create Field | write | `manage_options` |
| `wsp/acf-update-field-config` | Update Field Config | write | `manage_options` |
| `wsp/acf-delete-field` | Delete Field | write | `manage_options` |
| `wsp/acf-duplicate-field` | Duplicate Field | write | `manage_options` |
| `wsp/acf-sync-fields` | Force Sync Fields | write | `manage_options` |
| `wsp/acf-get-value-deep` | Get Field Value (deep) | read | `edit_posts` |
| `wsp/acf-update-value-deep` | Update Field Value (deep) | write | `edit_posts` |
| `wsp/acf-delete-value` | Delete Field Value | write | `edit_posts` |
| `wsp/acf-get-all-values` | Get All Values | read | `edit_posts` |
| `wsp/acf-bulk-update-values` | Bulk Update Values | write | `edit_posts` |
| `wsp/acf-get-field-object` | Get Field Object | read | `edit_posts` |
| `wsp/acf-list-post-types` | List Post Types | read | `edit_posts` |
| `wsp/acf-create-post-type` | Create Custom Post Type | write | `manage_options` |
| `wsp/acf-list-taxonomies` | List Taxonomies | read | `edit_posts` |
| `wsp/acf-create-taxonomy` | Create Taxonomy | write | `manage_options` |
| `wsp/acf-list-options-pages` | List Options Pages | read | `edit_posts` |
| `wsp/acf-create-options-page` | Create Options Page | write | `manage_options` |
| `wsp/acf-get-option-value` | Get Option Value | read | `manage_options` |
| `wsp/acf-update-option-value` | Update Option Value | write | `manage_options` |

- **Target resolution** — value tools (`get-value-deep`, `update-value-deep`, `delete-value`,
  `get-all-values`, `bulk-update-values`, `get-field-object`) resolve the object via
  `wsp_acf_validate_target( $target_id, $target_type, $is_write )`. Accepts a numeric post/page ID,
  `user_<id>`, `term_<id>`/`category_<id>`, or `options`. String forms (`user_5`) are normalized to
  id + type so they pass through the same checks.
- **Per-object capabilities** (enforced in `validate_target`, on top of the tool capability):
  `edit_post($id)` for post/page, `edit_user($id)` (write) / `list_users` (read, self-read allowed)
  for user, `manage_categories` for term, `manage_options` for the `options` target.
- **Deep get/set** — `wsp_acf_get_nested_value()` / `wsp_acf_set_nested_value()` walk a dot-notation
  `path` (e.g. `repeater.0.text_field`) over the field's array/object value.
- **Value sanitization (v2.4.1)** — every value written through `update_field()` is passed through
  `wsp_acf_sanitize_value()` first: it recurses into arrays (repeaters/groups/flexible content),
  sanitizes string keys with `sanitize_text_field()`, runs each string value through `wp_kses_post()`
  (strips `<script>`/`<style>` and `on*` handlers, keeps post-safe HTML for WYSIWYG), and returns
  non-string scalars unchanged. Applied in `update_value_deep`, `bulk_update_values`, and
  `update_option_value`. Closes the WordPress.org "arbitrary code insertion" finding (stored XSS).
- **CPT/taxonomy creation** requires ACF 6.1+ (`acf_update_post_type()` / `acf_update_taxonomy()`);
  options-page creation requires ACF Pro (`acf_add_options_page()`). Each falls back to a `WP_Error`
  `unsupported` when the underlying function is absent.
- Helpers: `wsp_acf_is_active()`, `wsp_acf_check_cap()`, `wsp_acf_validate_target()`,
  `wsp_acf_get_nested_value()`, `wsp_acf_set_nested_value()`, `wsp_acf_sanitize_value()`.

> **No delete-options-page tool.** A `wsp/acf-delete-options-page` tool was proposed but dropped:
> ACF options pages are re-registered on every load (via `acf_add_options_page()` hooks), so a
> runtime delete can't persist. Its callback, native-tool registration, and registry entry were all
> removed — don't reintroduce it without a durable deletion mechanism.

---


#### Gravity Forms (`gravityforms.php`)

Only registered if `wsp_gravity_is_active()` (`class_exists('GFAPI') || class_exists('GFCommon')`).
**18 tools**, all write operations OFF by default (read tools ON by default). MCP tool names use the `wsp_gravity_*` form; enable keys use `wsp/gravity-*`.

| Ability key | Label | Access | Default | Capability | Inputs |
|---|---|---|---|---|---|
| `wsp/gravity-list-forms` | List Forms | read | ON | `gravityforms_edit_forms` | none |
| `wsp/gravity-get-form` | Get Form | read | ON | `gravityforms_edit_forms` | `id`* (int — form ID) |
| `wsp/gravity-create-form` | Create Form | write | OFF | `gravityforms_create_form` | `title`*, `description`, `fields[]`, `button_text` |
| `wsp/gravity-update-form` | Update Form | write | OFF | `gravityforms_edit_forms` | `id`*, `title`, `description`, `is_active`, `fields[]`, `button_text` |
| `wsp/gravity-delete-form` | Delete Form | write | OFF | `gravityforms_delete_forms` | `id`* (int) |
| `wsp/gravity-list-entries` | List Entries | read | OFF | `gravityforms_view_entries` | `form_id`*, `per_page`, `page`, `status` |
| `wsp/gravity-get-entry` | Get Entry | read | OFF | `gravityforms_view_entries` | `id`* (int — entry ID) |
| `wsp/gravity-update-entry` | Update Entry | write | OFF | `gravityforms_edit_entries` | `id`*, `is_read`, `is_starred`, `status`, `fields` (object) |
| `wsp/gravity-delete-entry` | Delete Entry | write | OFF | `gravityforms_delete_entries` | `id`*, `permanent` (bool) |
| `wsp/gravity-get-notifications`| Get Notifications | read | OFF | `gravityforms_edit_forms` | `form_id`* (int) |
| `wsp/gravity-get-confirmations`| Get Confirmations | read | OFF | `gravityforms_edit_forms` | `form_id`* (int) |
| `wsp/gravity-create-notification`| Create Notification | write | OFF | `gravityforms_edit_forms` | `form_id`*, `name`, `to`, `to_type`, `subject`, `message`, `from`, `from_name`, `reply_to`, `bcc`, `event` |
| `wsp/gravity-update-notification`| Update Notification | write | OFF | `gravityforms_edit_forms` | `form_id`*, `notification_id`*, `name`, `to`, `to_type`, `subject`, `message`, `from`, `from_name`, `reply_to`, `bcc`, `event`, `is_active` |
| `wsp/gravity-delete-notification`| Delete Notification | write | OFF | `gravityforms_edit_forms` | `form_id`*, `notification_id`* |
| `wsp/gravity-create-confirmation`| Create Confirmation | write | OFF | `gravityforms_edit_forms` | `form_id`*, `name`, `type`, `message`, `url`, `page_id`, `query_string`, `is_default` |
| `wsp/gravity-update-confirmation`| Update Confirmation | write | OFF | `gravityforms_edit_forms` | `form_id`*, `confirmation_id`*, `name`, `type`, `message`, `url`, `page_id`, `query_string`, `is_default`, `is_active` |
| `wsp/gravity-delete-confirmation`| Delete Confirmation | write | OFF | `gravityforms_edit_forms` | `form_id`*, `confirmation_id`* |
| `wsp/gravity-update-form-settings`| Update Form Settings | write | OFF | `gravityforms_edit_forms` | `id`*, `label_placement`, `description_placement`, `sub_label_placement`, `css_class`, `enable_honeypot`, `enable_animation`, `limit_entries`, `limit_entries_count`, `limit_entries_period`, `limit_entries_message`, `schedule_form`, `schedule_start`, `schedule_end`, `schedule_pending_message`, `schedule_message`, `require_login`, `require_login_message`, `save_enabled` |

- `gravityforms_delete_entries` cap check on `delete_entry`; `entry_status` valid values are `active`, `spam`, `trash`.
- All callbacks gate on `class_exists('GFAPI')` and return `WP_Error` if Gravity Forms is inactive.
- `get_entry` resolves field labels from the form definition and returns human-readable field data.

#### Contact Form 7 (`cf7.php`)

Only registered if `wsp_cf7_is_active()` (`class_exists('WPCF7_ContactForm')`).
**10 tools** covering forms, Flamingo entries, validation, integrations, and moderation. MCP tool names use the `wsp_cf7_*` form; enable keys use `wsp/cf7-*`.

| Ability key | Label | Access | Default | Capability | Inputs |
|---|---|---|---|---|---|---|
| `wsp/cf7-list-forms` | List CF7 Forms | read | ON | `wpcf7_edit_contact_forms` | none |
| `wsp/cf7-get-form` | Get CF7 Form | read | ON | `wpcf7_edit_contact_forms` | `id`* (int — form ID) |
| `wsp/cf7-create-form` | Create CF7 Form | write | OFF | `wpcf7_edit_contact_forms` | `title`*, `locale`, `form_markup`, `mail` (object), `messages` (object) |
| `wsp/cf7-update-form` | Update CF7 Form | write | OFF | `wpcf7_edit_contact_forms` | `id`*, `title`, `locale`, `form_markup`, `mail`, `mail_2`, `messages`, `additional_settings` |
| `wsp/cf7-delete-form` | Delete CF7 Form | write | OFF | `wpcf7_delete_contact_forms` | `id`*, `permanent` (bool) |
| `wsp/cf7-list-entries` | List CF7 Entries | read | OFF | `wpcf7_edit_contact_forms` | `form_id`, `per_page`, `page`, `status` |
| `wsp/cf7-get-entry` | Get CF7 Entry | read | OFF | `wpcf7_edit_contact_forms` | `id`* (int — entry ID) |
| `wsp/cf7-validate-form` | Validate CF7 Form | read | OFF | `wpcf7_edit_contact_forms` | `id`* (int) |
| `wsp/cf7-get-integrations` | Get CF7 Integrations | read | OFF | `manage_options` | none |
| `wsp/cf7-moderate-entry` | Moderate CF7 Entry | write | OFF | `wpcf7_edit_contact_forms` | `id`*, `action`* (spam | unspam | trash | untrash) |

- **Flamingo dependency:** `list-entries`, `get-entry`, and `moderate-entry` require `class_exists('Flamingo_Inbound_Message')` — Contact Form 7 does not store entries natively. These tools return a descriptive `WP_Error` if Flamingo is not active.
- `validate-form` uses the built-in `WPCF7_ConfigValidator` to check email templates and form syntax.
- `get-integrations` reads active integration modules via `WPCF7_Integration::list_modules()` and reCAPTCHA keys from the global `wpcf7` option.
- All callbacks gate on `class_exists('WPCF7_ContactForm')` and return `WP_Error` if CF7 is inactive.
- All CF7 tools use `wpcf7_edit_contact_forms` (except `delete-form`, which uses `wpcf7_delete_contact_forms`, and `get-integrations`, which uses `manage_options`).

#### WPForms (`wpforms.php`)

Only registered if `wsp_wpforms_is_active()` (`function_exists('wpforms') || class_exists('WPForms')`).
**12 tools** covering forms, fields, entries (Pro), and schema description. MCP tool names use the `wsp_wpforms_*` form; enable keys use `wsp/wpforms-*`.

| Ability key | Label | Access | Default | Capability | Inputs |
|---|---|---|---|---|---|---|
| `wsp/wpforms-list-forms` | List WPForms | read | ON | `wpforms_view_forms` | none |
| `wsp/wpforms-get-form` | Get Form | read | ON | `wpforms_view_forms` | `id`* (int — form ID) |
| `wsp/wpforms-describe-schema` | Describe Schema | read | ON | `wpforms_view_forms` | none |
| `wsp/wpforms-get-form-stats` | Get Form Stats | read | ON | `wpforms_view_forms` | `id` (int, optional) |
| `wsp/wpforms-create-form` | Create Form | write | OFF | `wpforms_edit_forms` | `title`*, `description`, `fields[]`, `submit_text` |
| `wsp/wpforms-update-form-settings` | Update Form Settings | write | OFF | `wpforms_edit_forms` | `id`*, `title`, `description`, `submit_text`, `antispam`, `ajax_submit` |
| `wsp/wpforms-add-field` | Add Field | write | OFF | `wpforms_edit_forms` | `id`*, `type`*, `label`*, `required`, `description`, `placeholder`, `css`, `choices[]` |
| `wsp/wpforms-update-field` | Update Field | write | OFF | `wpforms_edit_forms` | `id`*, `field_id`*, `label`, `required`, `description`, `placeholder`, `css`, `choices[]` |
| `wsp/wpforms-delete-form` | Delete Form | write | OFF | `wpforms_edit_forms` | `id`*, `permanent` (bool) |
| `wsp/wpforms-list-entries` | List Entries | read | OFF | `wpforms_view_entries` | `id`, `per_page`, `page`, `status` |
| `wsp/wpforms-get-entry` | Get Entry | read | OFF | `wpforms_view_entries` | `id`* (int — entry ID) |
| `wsp/wpforms-delete-entry` | Delete Entry | write | OFF | `wpforms_edit_entries` | `id`*, `permanent` (bool) |

- **Pro dependency:** `list-entries`, `get-entry`, and `delete-entry` require WPForms Pro (`wsp_wpforms_pro_is_active()` checks `wpforms()->is_pro()`). Lite users receive a descriptive error.
- **Form data model:** Forms are stored as the `wpforms` custom post type with `post_content` as a JSON object containing `fields` (array, keyed by string ID), `settings`, and `payments`.
- **Field IDs:** Auto-assigned as incremental integers starting from 0. `wsp_wpforms_get_next_field_id()` finds the highest existing ID + 1.
- **Schema description:** `describe-schema` returns 16 supported field types with metadata on which accept choices and which attributes are editable.
- All callbacks gate on `function_exists('wpforms')` and return `WP_Error` if WPForms is inactive.
#### Ultimate Addons for Elementor (`uae.php`)

Only registered if `wsp_uae_is_active()`. Adds 45 tools to manipulate UAE widgets, templates (Header/Footer/Blocks), settings, and display rules. 
- **Key Prefix:** `wsp/uae-*`
- **MCP Tool Prefix:** `wsp_uae_*`
- **Capabilities:** Mostly `edit_posts` (reads/basic updates), `publish_posts` (creates), and `manage_options` (global widgets/settings).




## Admin UI

### Settings page (`MCP > Settings`) — `settings-page.php`

- Registered as top-level menu at position 3, icon `dashicons-admin-generic`.
- Groups abilities by `group` field from registry, displays toggle switches.
- Stats bar: total abilities, enabled count, active write count.
- **Collapsible accordion groups:** each `group` renders as a `.wsp-group` whose `.wsp-gh` header
  toggles a `.wsp-gbody` (the rows). **All groups start collapsed.** Open/closed state persists
  per-browser in `localStorage` key `wsp_acc_open` (array of group names). The chevron
  (`.wsp-chev`) rotates on the `.wsp-open` class.
- **Header tally (v2.6.7):** each group header shows two pills inside `.wsp-gcounts` — `N Enabled`
  (`.wsp-gcount--on`, green) and `N Disabled` (`.wsp-gcount--off`, red). Whichever count is `0` also
  gets `.wsp-gcount--zero`, which greys it out. `refreshCount()` rewrites both labels and the zero
  class live as toggles change. Replaced the single `enabled / total` badge.
- **Two-column layout (v2.6.7):** `.wsp-wrap` (max-width `1180px`) wraps a `.wsp-layout` flex row of
  `.wsp-main` (settings, capped at `860px`) and `.wsp-side` (sticky promo cards). Collapses to one
  column below `1100px`. See "Promo cards" below.
- JS: write abilities show a `confirm()` dialog before enabling. "Toggle All" per group (its click is
  excluded from the accordion toggle via `e.target.closest('.wsp-toggle-all')`).
- Saves to `wsp_mcp_abilities` option via Settings API (`wsp_mcp_settings_group`).

### Connection page (`MCP > Connection`) — `connection-page.php`

- **Primary, native-transport page (v2.0).** Top card shows the native endpoint
  (`rest_url('wsp-mcp/v1/mcp')`) + API key with a Regenerate button (nonce-protected admin-post action
  `wsp_mcp_regenerate_key` → `WSP_MCP_Auth::regenerate_api_key()`).
- **Seven tabs** total: the zero-config-file "Claude Connectors" tab (below) plus six tabbed,
  copy-to-clipboard snippets, all pointing at the native endpoint with the **API key hardcoded into
  the auth header** (no `${VAR}` env interpolation — avoids the mcp-remote "missing env var"
  failure). Server name auto-derives as `wsp-<host>`:
  - **Claude Connectors (v2.8.0, default-active tab, `#wsp-tab-claudeweb`):** not a config-file
    snippet at all — Claude's **Customize > Connectors > Add custom connector** screen (a different
    product surface from `claude_desktop_config.json`, shared by claude.ai/Desktop/mobile) takes a
    bare **Remote MCP server URL** + a **Request header** instead of a file to edit. The tab shows
    two copyable values: `#wsp-code-ccurl` (`$endpoint`) and `#wsp-code-ccheader` (`Bearer
    <api_key>`, paste as the value of an `Authorization` header, Authentication set to `None`).
    Wired with the same `makeCopyBtn()` helper as everything else, ids `wsp-copy-ccurl` /
    `wsp-copy-ccheader`. Two constraints called out inline (not fixable from this plugin's side):
    the site must be publicly reachable (Claude's cloud can't reach `localhost`), and Claude's
    Request-headers field is a beta rolling out gradually — accounts without it yet fall back to the
    **Claude Desktop (config file)** tab, which is unchanged, just no longer default-active.
    Verified against Anthropic's docs (`/docs/connectors/custom/remote-mcp
    #authenticating-with-request-headers`, `/docs/connectors/building/authentication`) before
    shipping — do **not** invent a different header/UI flow without re-checking those pages, this
    surface changes independently of this plugin.
  - **Claude Desktop (`#wsp-tab-claude`)** — `mcpServers` + `npx -y mcp-remote <url> --header "Authorization: Bearer <key>"` (stdio bridge, needs Node.js; Claude Desktop config files don't support remote HTTP directly).
  - **Cursor** — native remote HTTP: `mcpServers.<name>.{ url, headers: { Authorization: "Bearer <key>" } }` (`~/.cursor/mcp.json`).
  - **Codex** — native streamable HTTP TOML: `[mcp_servers.<name>]` `url` + `http_headers = { "Authorization" = "Bearer <key>" }` (`~/.codex/config.toml`).
  - **Antigravity** — native remote HTTP, but the URL key is **`serverUrl`** (not `url`): `mcpServers.<name>.{ serverUrl, headers }` (`~/.gemini/config/mcp_config.json`).
  - **OpenClaw** — nested **`mcp.servers`** schema (not top-level `mcpServers`) + `mcp-remote` bridge, key inlined in the header (`~/.openclaw/openclaw.json`).
  - **OpenCode** — native remote HTTP under the **`mcp`** key: `mcp.<name>.{ type: "remote", url, enabled, oauth, headers: { Authorization: "Bearer <key>" } }`. Full-file snippet including `$schema` so users can create a fresh `~/.config/opencode/opencode.json`.
- Self-contained tab/copy UI markup + JS.
- **Copy buttons (v2.6.7):** `copyText()` uses `navigator.clipboard` only when `window.isSecureContext`
  is true, else falls back to an off-screen `<textarea>` + `document.execCommand("copy")`. The
  Clipboard API is undefined on plain-HTTP hosts (e.g. `http://*.local` dev sites), where the old
  direct call threw synchronously and the buttons silently did nothing.
- Same `.wsp-layout` / `.wsp-main` / `.wsp-side` two-column shell as the Settings page (v2.6.7).
- **Configuration Generator (v2.8.0):** a new `.wsp-gen-box` section sits between the facts table and
  the six static tabs. Tool `<select>` + a 3-way auth-method pill group (`API Key` / `Application
  Password` / `OAuth`) drive a live, client-side-only preview (`#wsp-gen-code`) with its own copy
  button (`#wsp-gen-copy`), re-rendered on every `change`/`input` event — no page reload, no server
  round trip. Data needed by the JS (`endpoint`, `apiKey`, `connSlug`, `siteUrl`) is emitted once as
  `window.WSP_MCP_GEN` via an inline `<script>` in the page markup (JSON-encoded with
  `wp_json_encode()`, which escapes slashes by default so `</script>` cannot break out).
  - **API Key** (default/recommended): identical output to the matching static tab below it — the
    Bearer key is embedded directly in the header, same as the rest of the page.
  - **Application Password:** reveals a WordPress-Username + Application-Password pair of inputs
    (`#wsp-gen-username` / `#wsp-gen-password`, `type=password`). Nothing in these fields is ever
    submitted to the server — the Basic-auth token is computed in-browser with `btoa()` and embedded
    literally into the generated config's `--header`/`headers` value, matching how the API-key path
    already embeds its secret directly rather than relying on `${VAR}` interpolation. For Claude
    Desktop specifically, the generated `env` block also carries `WP_API_URL` / `WP_API_USERNAME` /
    `WP_API_PASSWORD` as a human-readable record of the credential — those three keys are cosmetic
    only, nothing in this plugin's own transport reads them back. **Do not resurrect this as a reason
    to depend on `@automattic/mcp-wordpress-remote`** — the actual bridge is still `mcp-remote` →
    the native `wsp-mcp/v1/mcp` endpoint (see the removed-in-v2.2 note above).
    Below the HTTPS-only note (`#wsp-gen-apppw-notice`) is shown only when `location.protocol` is
    plain HTTP and the host isn't `localhost`/`127.0.0.1`, since WP core itself refuses to let a
    non-HTTPS, non-local site create Application Passwords.
  - **OAuth:** selectable (so the picker matches what other modern MCP integrations expose) but
    **not implemented server-side** — `class-auth.php` has no OAuth flow. Selecting it swaps the code
    box for a "coming soon" notice (`#wsp-gen-oauth-note`) and disables the copy button. If OAuth
    support is ever added to `WSP_MCP_Auth`, wire a real branch into `buildSnippet()`/`authHeader()`
    here instead of leaving the placeholder.
- **One-Click Automated Connector (v2.8.0):** removes the manual copy-paste step entirely, on both
  the six static tabs and the live generator.
  - **Download button:** every `.wsp-config-header` now wraps its buttons in a `.wsp-config-actions`
    flex row — a new **Download** button sits beside **Copy**. Client-side `downloadFile(filename,
    content)` builds a throwaway `Blob` + `<a download>` and clicks it, so one click writes the exact
    file to the browser's download location — nothing to select, nothing to paste. `makeDownloadBtn(
    btnId, codeId, filename)` wires the six static-tab buttons (`#wsp-download-claude/cursor/codex/
    antigravity/openclaw/opencode` → `claude_desktop_config.json` / `mcp.json` / `config.toml` /
    `mcp_config.json` / `openclaw.json` / `opencode.json`); the generator's own `#wsp-gen-download`
    button reuses the same `downloadFile()` helper but derives its filename live from
    `snip.filename.split("/").pop()` each render (the on-screen label still shows the full
    `~/.cursor/mcp.json`-style path — browsers can't pick a destination *directory*, only a
    filename, so the download uses the basename).
  - **Cursor one-click connect:** Cursor supports a documented deep link —
    `cursor://anysphere.cursor-deeplink/mcp/install?name=<slug>&config=<base64>` (see
    https://cursor.com/docs/mcp/install-links) — where `config` is the **base64 of the bare server
    object** (`{ url, headers }`, *not* wrapped in `{ "<name>": {...} }` — the name is carried
    separately in the `name` param). Clicking it opens Cursor, which shows its own install-confirm
    dialog and writes `~/.cursor/mcp.json` itself — no file to open or paste into at all. A
    `.wsp-connect-callout` box with a `.wsp-connect-btn` link renders this:
    - On the static **Cursor** tab, the href is built server-side in PHP (`$cursor_deeplink`,
      computed right after `$cursor_json`) since the API key is already known.
      **Gotcha:** `esc_url()` strips any protocol not in `wp_allowed_protocols()` by default, which
      does **not** include `cursor` — echoing `esc_url( $cursor_deeplink )` bare would have silently
      mangled the scheme and dead-ended the button. Fixed by passing the protocol allowlist
      explicitly: `esc_url( $cursor_deeplink, array( 'cursor', 'https', 'http' ) )`. Any future
      custom-scheme link on this page needs the same third argument.
    - In the **generator**, `#wsp-gen-connect-callout` / `#wsp-gen-connect-btn` are hidden by default
      and only shown when `tool === "cursor"` **and** `authHeader(method)` returns a real (non-null,
      non-placeholder) header — i.e. API Key always qualifies, Application Password only once both
      credential fields are filled in. `buildCursorDeeplink(name, endpoint, header)` (uses the
      existing `b64()` helper) rebuilds the `href` on every `render()` call, same lifecycle as
      `buildSnippet()`. Setting `.href` via JS is not passed through `esc_url()`/kses, so no
      protocol-allowlist issue there — only the PHP-side echo needed the fix above.
    - No equivalent deep link is wired for the other five clients — Claude Desktop, Codex,
      Antigravity, OpenClaw and OpenCode have no publicly documented one-click MCP-install protocol
      handler as of this writing. Don't invent one; if a client adds official support later, follow
      the same pattern (verify the exact scheme/param shape against that vendor's docs before
      shipping — see the Cursor link above for the level of confirmation expected).

### Site Context — `context.php` + `admin/context-page.php` (v2.9.5)

- **Purpose:** the admin writes `AGENTS.md` and `CHANGELOG.md` for *their site* (not this repo's docs) on
  **MCP > Context**; a connected agent gets them first, so it doesn't crawl the site to learn its structure
  (saves tokens/time). Menu slug `wsp-mcp-context`, `admin_menu` priority 22 (after Connection), `manage_options`.
- **Master switch:** option `wsp_mcp_context_enabled`, **default off**, toggled by the switch at the top of the page
  and saved together with both documents by `admin_post_wsp_mcp_save_context` (nonce + `manage_options`).
  `wsp_mcp_context_is_active()` = switch on **and** at least one document non-empty; that — not the bare switch —
  gates everything below, so an empty page advertises nothing.
- **Delivery (three channels, all from `context.php`):**
  1. `initialize` result `instructions` (`wsp_mcp_context_instructions()`): preamble + first
     `WSP_MCP_CONTEXT_AGENTS_PUSH_CHARS` (6000) of AGENTS.md + first `WSP_MCP_CONTEXT_CHANGELOG_PUSH_CHARS` (1500)
     of the changelog (so keep **newest entries first**). Ends with a truncated/complete pointer to the tool. Also
     adds `capabilities.resources`. Absent when inactive.
  2. Tool `wsp_get_site_context` (`file`: all|agents|changelog, full uncapped text). Registered in `native-tools.php`
     with the new tool-spec key **`active_callback`** (`wsp_mcp_context_is_active`) instead of an `enable_key` — it is
     not in `wsp_mcp_ability_registry()` and has no Settings-page toggle, because the Context switch is its only gate.
     `WSP_MCP_Server::enabled_tools()` honours `active_callback` before `enable_key`.
  3. Resources `wsp://context/agents.md` / `wsp://context/changelog.md` (`resources/list`, `resources/read`; unknown
     URI → JSON-RPC error `-32002`).
- **Write tool `wsp_update_site_context` (unreleased):** `wsp_execute_update_site_context()` in `context.php`. Inputs
  `file`* (agents|changelog), `content`*, `mode` (replace|append|prepend, default replace), `enable` (bool, sets the
  master switch). Registry key `wsp/update-site-context` (group **Site**, OFF by default), capability `manage_options`.
  Unlike the read tool it uses a normal `enable_key`, **not** `active_callback`, so it works while the Context page is
  still empty/off. Large files are sent in chunks (`replace` then `append`); content is sanitized with `$trim = false`
  so chunk boundaries survive. Over-limit writes return `WP_Error( 'too_large' )` — never truncate silently. Response
  includes `total_chars` and `sha256` for verification. No `wp_unslash()` (MCP args are never slashed).
- **Sanitization:** documents are admin-authored plain text, never rendered as HTML (admin textarea uses
  `esc_textarea()`, MCP output is JSON), so `wsp_mcp_context_sanitize( $text, $trim = true )` normalises UTF-8/newlines,
  drops control characters and caps at `WSP_MCP_CONTEXT_MAX_CHARS` (300,000; was 50,000) — it deliberately does **not**
  strip tags, since Markdown contains `<placeholders>`/inline HTML. Don't "fix" this with `wp_kses_post()`; it would
  corrupt the documents. The admin "Load from file" picker alerts when a file exceeds the limit.
- **Trust/leak note (do not regress):** the tool has capability `''` and `instructions` go to every authenticated
  client, including low-privilege Application Password users — the page tells admins never to put secrets in these
  documents. Don't add anything dynamic (user data, options, keys) to the pushed text.
- Clients cache `initialize`, so edits reach agents only after they reconnect (same gotcha as tool lists).
- Cleanup: all three options removed in `uninstall.php`.

### Review notice — `review-notice.php` (v2.9.2)

- Asks admins for a WordPress.org review: "Is WSP MCP working for you? A review helps other people
  find it." **Never on activation** — it only appears once option `wsp_mcp_first_success` exists.
- `wsp_mcp_review_record_success()` is called from `WSP_MCP_Server::do_tools_call()` right after the
  `STATUS_SUCCESS` audit-log write; it `add_option()`s the timestamp once (autoloaded, so later calls
  cost nothing). **Backfill:** while the option is missing, `wsp_mcp_review_backfill_from_log()`
  checks the audit log for any past `success` row (so sites that used the plugin before this notice
  existed qualify immediately) and records the option if found. One-way only — once the option is set
  the log is never consulted again, so clearing/pruning the log can't make the notice reappear or vanish.
- Rendered on `admin_notices`, **only** on the Plugins screen and this plugin's five MCP pages
  (`page=` `wsp-mcp-abilities` / `wsp-mcp-connection` / `wsp-mcp-context` / `wsp-mcp-audit-log` / `wsp-mcp-analytics`),
  checked by `wsp_mcp_review_is_allowed_screen()`; only for `manage_options`. MCP pages are matched on
  the `page` query arg, not the screen ID, because submenu screen IDs derive from the translatable
  menu title. **Do not add the Dashboard or make it site-wide** — that's the nag pattern WP.org
  guideline 11 targets.
- Three no-JS buttons, each a nonce-protected `admin_post_wsp_mcp_review_notice` link with `choice=`:
  `review` (dismiss forever, then `wp_redirect()` to the hard-coded `WSP_MCP_REVIEW_URL`), `snooze`
  (hide for `WSP_MCP_REVIEW_SNOOZE_DAYS` = 14 days), `dismiss` (hide forever). State is **per user**
  in user meta `wsp_mcp_review_notice`. There is intentionally no `is-dismissible` X — core's X only
  hides client-side and would bring the notice back on the next load.
- Don't make it reappear after "Don't show again", and don't show it to non-admins — both break
  WordPress.org guideline 11.

### Plugins-screen links — `plugin-links.php`

- `wsp_mcp_plugin_action_links()` on `plugin_action_links_<basename>` prepends **Settings | Connection |
  Context | About Us** before core's Deactivate link. Shown only to `manage_options` users (the pages need it).

### About Us page (`MCP > About Us`) — `about-page.php`

- Submenu slug `wsp-mcp-about`, registered on `admin_menu` priority **40** so it sits directly below
  Analytics (35). `manage_options` only.
- Content (agency description, stats, services, values, contact details, social links) is
  **hard-coded** from websensepro.com — the page makes no runtime HTTP requests. Edit
  `wsp_mcp_about_page()` when the website's figures change.
- websensepro.com links go through `wsp_mcp_promo_url()` with campaign `about_page`; social links are
  plain. All links `target="_blank" rel="noopener"`.
- Not in `wsp_mcp_review_is_allowed_screen()` — the review notice does not show here.

### Promo cards — `promo-cards.php` (v2.6.7)

Shared by **both** admin pages; loaded before them in the main plugin file so the functions exist.

- `wsp_mcp_promo_url( $url, $content, $campaign )` — appends UTM params for Google Analytics:
  `utm_source=wsp_mcp_plugin`, `utm_medium=plugin_admin`, `utm_campaign=<screen>`,
  `utm_content=<card>`, `utm_term=WSP_MCP_VERSION` (doubles as version-adoption tracking).
- `wsp_mcp_promo_css()` — returns the `.wsp-layout` / `.wsp-side` / `.wsp-promo` CSS, concatenated
  onto each page's own inline stylesheet (both pages call `wp_add_inline_style('common', …)`).
- `wsp_mcp_render_promo_cards( $campaign )` — echoes the `.wsp-side` column: **Video Tutorials**
  (`/tutorials`) and **200+ Tools Available** (`/abilities-directory`), both on wspmcp.com.
- Campaigns in use: `abilities_page` (settings-page.php), `connection_page` (connection-page.php).
- Links use `target="_blank" rel="noopener"` — **not** `noreferrer`, which would strip the referrer
  and break GA attribution on our own destination site. URLs pass through `esc_url()`.
- **Adding a card:** edit `wsp_mcp_render_promo_cards()` only — never duplicate markup into a page.
  `.wsp-promo + .wsp-promo` handles spacing automatically.

> **Removed in v2.2:** the old **MCP > Config Files** page (`config-page.php`) that generated
> mcp-adapter / `@automattic/mcp-wordpress-remote` snippets is gone. `wsp_mcp_redirect_legacy_config_page()`
> (on `admin_init`, in `settings-page.php`) redirects the dead `page=wsp-mcp-config` URL to MCP > Connection.

---

## Security patterns used

- All text input: `sanitize_text_field(wp_unslash($input['x']))`.
- HTML content: `wp_kses_post(wp_unslash($input['content']))`. **Exception — block markup** (templates in `site-editor.php`, widget instances in `widgets.php`, since v2.9.4): `wp_kses_post()` only, no `wp_unslash()`. MCP args are decoded JSON and never slashed, so unslashing strips real backslashes and corrupts escaped JSON (`<`) in block-comment attributes. Older modules (posts/pages) still unslash; that's a latent bug for block content with escapes, not a pattern to copy.
- IDs: `intval($input['id'])`.
- Slugs: `sanitize_title($input['slug'])`.
- MIME types: `sanitize_mime_type($input['type'])`.
- Permission callbacks: `__return_true` for public reads; `current_user_can('cap')` closures for writes and sensitive reads.
- MCP requests are authenticated inside the native server handler (App Password / Bearer key); per-tool capability checks via `require_cap()`.
- **`require_cap()` only checks the ONE broad primitive capability recorded at registration — it cannot answer "may this user act on THIS object?".** Every write callback that accepts a caller-supplied object ID MUST additionally call the object-level guards in `includes/abilities/guard.php`: `wsp_mcp_guard_edit_post($id, $type)` / `wsp_mcp_guard_delete_post($id, $type)` enforce the `edit_post` / `delete_post` meta capability and pin the post type; `wsp_mcp_guard_post_status($post, $status)` blocks `publish`/`future`/`private` transitions unless the caller holds `publish_posts`. All return `WP_Error` on denial. Application Password callers run as their real (possibly Contributor-level) user, so skipping this is a broken-access-control bug (see CHANGELOG `[2.7.1]`, Patchstack). Currently applied in `posts.php`, `pages.php`, `media.php`, `cpt.php`; `yoast.php` / `rankmath.php` do their own equivalent `edit_post` check.
- Admin-post actions (e.g. API-key regenerate) are nonce-protected with `wp_nonce_field()` / `check_admin_referer()` and gated by `current_user_can('manage_options')`.
- Output in admin pages is escaped (`esc_html`/`esc_attr`/`esc_url`/`esc_textarea`/`esc_js`).

---

## Defaults at a glance

**ON by default:** `get-posts`, `get-pages`, `get-categories`, `get-tags`, `search`, `get-site-info`

**OFF by default:** everything else (all write abilities, comments, media, users, plugins, all Elementor abilities)

---

## Naming conventions

- PHP functions: `wsp_` prefix, snake_case.
- Ability keys: `wsp/kebab-case`.
- MCP tool names: `wsp_snake_case` (underscores).
- Option: `wsp_mcp_abilities` (single serialized array).
- No classes for feature logic — procedural PHP throughout; the only classes are the server layer (`WSP_MCP_Server`, `WSP_MCP_Session_Store`, `WSP_MCP_Auth`).
- Each ability file is self-contained: registration + execute callbacks together.

---

## Contributing (team)

Read **"## v2.0 Architecture (CURRENT — read this first)"** before writing any transport/tool code.
This file (`AGENTS.md`) is the shared contract — **the maintainer updates it (never a GitHub PR) whenever
architecture, hooks, tools, constants, or admin UX change.**

**Adding a feature / tool:** follow **"### How to add a NEW MCP tool (v2.0)"** above (logic →
`native-tools.php` registration → `registry.php` metadata).
Keep business logic in `includes/abilities/*.php`; keep transport wiring in `includes/server/` and
`includes/tools/`. **Never** put feature code in `wsp-mcp-ai-agents-connector.php` (loader + activation glue only).

**Conventions:**
- Match the existing procedural style and `wsp_`/`wsp/`/`wsp_…` naming above.
- Sanitize every input, escape every output, enforce a capability on every write/sensitive read
  (see "## Security patterns used"). WP.org review will reject otherwise.
- New persistent state (options/tables/cron): add cleanup to `uninstall.php`, and gate any schema
  change behind the `wsp_mcp_db_version` migration in `wsp_mcp_maybe_upgrade_db`.
- Bump `WSP_MCP_VERSION` (main file header + `define`) **and** `Stable tag:` in `readme.txt` together;
  add a `== Changelog ==` entry.

**Branch / PR workflow:**
- Branch off `main`; never commit straight to `main`.
- One logical change per PR; update the `readme.txt` changelog in the same PR. **Do not touch `AGENTS.md` / `CHANGELOG.md` / `HISTORY.md` in a GitHub PR** — PR changes belong only in `wsp-mcp-ai-agents-connector/`; the maintainer updates those docs on merge.
- End commit messages with the project's Co-Authored-By trailer when an agent made the change.

**Testing note:** the primary dev machine has PHP (Homebrew, `/opt/homebrew/bin/php`) and Node, but
no MySQL or WP-CLI.
- **Lint:** `find wsp-mcp-ai-agents-connector -name '*.php' -print0 | xargs -0 -n1 php -l`
- **Run WordPress locally (SQLite, nothing to install):** from the repo root,
  `npx @wp-playground/cli@latest server --port=9400 --login --mount=./wsp-mcp-ai-agents-connector:/wordpress/wp-content/plugins/wsp-mcp-ai-agents-connector`
  plus a blueprint with an `activatePlugin` step (`wsp-mcp-ai-agents-connector/wsp-mcp-ai-agents-connector.php`).
  Login is `admin` / `password`. **Gotcha:** `--login` makes Playground 302-redirect cookieless
  requests to log in, including REST calls — send the logged-in cookie jar with MCP `curl` tests
  (Bearer auth still applies), or start without `--login`.
- Smoke test the endpoint with `initialize` → `tools/list` → `tools/call` (copy the API key from MCP > Connection).
Before release, still test on a real WordPress site and run **Plugin Check** in WP admin.

**Native-only (since v2.2):** the dual-mode Abilities-API path was removed. The plugin no longer
calls `wp_register_ability()` and does not reference `mcp-adapter` / `abilities-api` /
`@automattic/mcp-wordpress-remote`. The native server is the sole transport. Don't reintroduce a
hard dependency on off-directory packages — it blocks WordPress.org approval (see HISTORY.md).

---

## Historical research & architectural decisions

The full rationale for why the native MCP server was built (transport options evaluated, two
WP.org-approved precedents studied, Path A/B/C analysis, v2.0 milestone plan) lives in
**[HISTORY.md](./HISTORY.md)**.

Read it when you need to understand *why* the architecture is the way it is, or before
proposing a significant change to the transport layer. For day-to-day feature work, the
sections above are sufficient.

