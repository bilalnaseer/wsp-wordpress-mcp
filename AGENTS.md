# WSP WordPress MCP — Agent & Contributor Guide (AGENTS.md)

---

## ⚠️ MANDATORY FIRST STEP FOR ALL AI AGENTS ⚠️

**Before reading any source code, before asking any questions, before writing a single line:**

1. **Read `AGENTS.md`** (this file) — current architecture, all tools, all hooks, security patterns, naming conventions.
2. **Read `CHANGELOG.md`** — what changed in every version and why; migration notes per release.
3. **Read `HISTORY.md`** — why the native MCP server was built, what alternatives were rejected, and the milestone plan rationale.

These three files give you complete project understanding without touching the codebase.
**Do not read source files until you have read all three.** The files are kept accurate by the team's update rule (see below).

### Update rule — enforced on every change

> **After every code change, you MUST update both `AGENTS.md` and `CHANGELOG.md` in the same commit/PR.**
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

**Plugin Name:** WSP MCP - AI Agents Connector  
**Version:** 2.6.5
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

**Server-layer files (`includes/server/`):**
- `class-mcp-server.php` — `WSP_MCP_Server`: REST route, JSON-RPC dispatch, in-memory tool
  registry (`register_tool()`), Origin validation, rate limiting (120/60s), `no-store` headers.
  Booted on `plugins_loaded` via `WSP_MCP_Server::init()`.
- `class-session-store.php` — `WSP_MCP_Session_Store`: DB table `{prefix}wsp_mcp_sessions`,
  `Mcp-Session-Id` issued on `initialize`, fingerprint-bound, sliding 24h expiry, daily cron
  `wsp_mcp_session_cleanup`.
- `class-auth.php` — `WSP_MCP_Auth`: accepts **(1)** Application Password (HTTP Basic, validated
  by WP core), **(2)** plugin API key via `Authorization: Bearer <key>`, **(3)** same key via
  `X-WSP-MCP-API-Key`. API key stored in option `wsp_mcp_api_key` (admin-only → mapped to lowest-ID
  admin so capability checks resolve). `require_cap()` gates each tool.

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

### How to add a NEW MCP tool (v2.0)

1. Write/keep the logic as a `wsp_execute_<name>( $input )` function returning an array or
   `WP_Error` (in an `includes/abilities/*.php` file — new file is fine).
2. Add a `WSP_MCP_Server::register_tool('wsp_<name>', [...])` entry in
   `includes/tools/native-tools.php` (description, inputSchema, callback, capability, enable_key).
3. Add the ability's metadata to `wsp_mcp_ability_registry()` in `registry.php` (so the
   admin toggle exists; set `default`).
4. Enable it in MCP > Settings, then **reconnect the client** (see gotcha below).

> The public tool list on **freewordpressmcp.com** updates itself — see
> **"## Website sync automation"** below. You do **not** hand-edit the site's `abilities.md`
> or `abilities-directory.html`; pushing your `registry.php` change to `main` regenerates them.

### Gotcha: client tool-list caching

MCP clients fetch `tools/list` once at connect and cache it. After enabling/adding tools, the user
must **reconnect** (fully restart Claude Desktop, not just open a new chat) before new tools appear.

---

## Website sync automation

The public **[freewordpressmcp.com](https://freewordpressmcp.com/abilities-directory)** tool
directory is generated **from this repo's `registry.php`** — it is never hand-edited. When the
tool list changes, a GitHub Action regenerates the site content and opens a PR on the website repo.

**Single source of truth:** `wsp-mcp-ai-agents-connector/includes/registry.php`. Everything the
website shows is derived from it. Generated artifacts live **only** in the website repo, never here
(`abilities.md` / `abilities.json` are `.gitignore`d in this repo on purpose).

**Tooling (`bin/`, dev-only — never shipped in the plugin zip):**
- `bin/lib-abilities.php` — loads `registry.php` in a minimal WP stub (defines `ABSPATH`, stubs
  `wsp_yoast_is_active()` / `wsp_elementor_is_active()` / `wsp_acf_is_active()` / a `WooCommerce`
  class so **all** plugin-gated groups render), then exposes the registry + totals.
- `bin/generate-abilities-md.php` — prints `abilities.md` (default) or `abilities.json` (`--json`).
- `bin/patch-website.php <html> <sitemap>` — rewrites the `var ABILITIES = [ … ];` array inside
  the site's `abilities-directory.html` in place, and bumps the `<lastmod>` for that page in
  `sitemap.xml`. Exits non-zero if the array marker isn't found (so CI stops rather than committing
  a half-patched page).

**Workflow (`.github/workflows/sync-abilities.yml`):** triggers on push to `main` touching
`registry.php`, `bin/**`, or the workflow file (plus manual `workflow_dispatch`). It:
1. Regenerates `abilities.md` + `abilities.json` (ephemeral — **not** committed to this repo).
2. Checks out the website repo `bilalnaseer/freewordpressmcp.com` using the `WEBSITE_REPO_TOKEN` secret.
3. Copies the docs in and runs `patch-website.php`.
4. Opens/updates a PR (`peter-evans/create-pull-request`, branch `sync/abilities-from-plugin`,
   `delete-branch: true`) on the website repo.

**The one-time setup (already done, documented so it can be recreated):**
- **Secret:** `WEBSITE_REPO_TOKEN` on *this* repo (Settings → Secrets → Actions) — a fine-grained
  PAT scoped to `freewordpressmcp.com` with **Contents: Read and write** + **Pull requests: Read
  and write**. Bad/expired token → the "Checkout website repo" step fails with `Bad credentials`.
- **Website repo ruleset:** the "block force pushes" ruleset must target **only the default branch
  (`main`)**, not all branches. The PR action force-pushes its own `sync/…` branch each run; if the
  rule covers all branches the push is rejected with `GH013 … Cannot force-push to this branch`.

**Normal loop for a contributor:** edit `registry.php` → push to `main` → the workflow opens a
website PR → **review the diff and merge it**. The `sync/abilities-from-plugin` branch auto-deletes
on merge. Do not delete that branch without merging its PR first — that discards the update.

---

## Directory structure

```
wsp-wordpress-mcp/                        ← repo root (NOT the plugin — dev tooling lives here)
├── bin/                       ← dev-only tooling (see "Website sync automation"); never in the zip
│   ├── lib-abilities.php       ← loads registry.php in a WP stub
│   ├── generate-abilities-md.php ← emits abilities.md / abilities.json
│   └── patch-website.php       ← patches the website's HTML + sitemap
├── .github/workflows/
│   └── sync-abilities.yml      ← regenerates the website tool list, opens a PR on the site repo
└── wsp-mcp-ai-agents-connector/          ← plugin root (the installable folder)
    ├── wsp-mcp-ai-agents-connector.php   ← main file: constants, requires, hooks, activation/migration
    ├── readme.txt              ← WP.org readme (v2.0)
    ├── uninstall.php           ← deletes wsp_mcp_* options + drops sessions table
    └── includes/
        ├── dependency.php      ← stub: wsp_mcp_transport_available() (always true) — kept for back-compat
        ├── registry.php        ← central ability registry + settings helpers
        ├── server/             ← v2.0 native MCP server
        │   ├── class-mcp-server.php     ← transport + JSON-RPC dispatch + tool registry
        │   ├── class-session-store.php  ← DB-backed sessions
        │   └── class-auth.php           ← API key + App Password + bearer + caps
        ├── tools/
        │   └── native-tools.php ← registers every wsp_execute_* as a native MCP tool
        ├── admin/
        │   ├── settings-page.php    ← toggle UI (MCP > Settings) — accordion groups + legacy-page redirect
        │   └── connection-page.php  ← native endpoint + API key + per-client tabs (MCP > Connection)
        └── abilities/           ← wsp_execute_* logic (called by the native server)
            ├── posts.php  pages.php  taxonomy.php  comments.php  media.php
            ├── users.php  search.php  site.php  yoast.php  elementor.php
            ├── woocommerce.php  acf.php
```

**Rule:** The main file is a minimal loader (+ activation/migration glue) only. All feature logic lives in `includes/`. Never put feature code in `wsp-mcp-ai-agents-connector.php`.

---

## Constants

| Constant | Value |
|---|---|
| `WSP_MCP_VERSION` | `'2.6.5'` |
| `WSP_MCP_OPTION` | `'wsp_mcp_abilities'` (per-ability on/off toggles) |
| `WSP_MCP_DIR` | `plugin_dir_path(__FILE__)` |

**Other persistent state:** option `wsp_mcp_api_key` (native API key), option `wsp_mcp_db_version`
(migration gate), DB table `{prefix}wsp_mcp_sessions`, cron event `wsp_mcp_session_cleanup`.

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
| `wsp/create-post` | Create Post | write | OFF | `publish_posts` | `title`*, `content`*, `status`, `categories[]`, `tags[]`, `excerpt`, `slug` |
| `wsp/update-post` | Update Post | write | OFF | `edit_posts` | `id`*, `title`, `content`, `status`, `categories[]`, `tags[]` |
| `wsp/delete-post` | Delete Post | write | OFF | `delete_posts` | `id`* |

- Delete moves to trash, not permanent deletion.
- `status=all` expands to `['publish','draft','pending','future']`.

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
| `wsp/upload-media` | Upload Media | write | OFF | `upload_files` | `url` (req), `filename`, `title`, `alt`, `caption`, `post_id` |
| `wsp/upload-media-from-url` | Upload Media From URL | write | OFF | `upload_files` | `url` (req), `filename`, `title`, `alt`, `caption`, `post_id` |

- `wsp/get-media` returns the full metadata of a **single** attachment by ID (browse/search moved to `wsp/list-media`).
- Uploads sideload via `download_url()` + `media_handle_sideload()` (requires `wp-admin/includes/{file,media,image}.php`, loaded inside the callback). `wsp_execute_upload_media()` is a thin wrapper over `wsp_execute_upload_media_from_url()`.
- `wsp_media_item_data()` is the shared normalizer used by get/update/upload responses.

#### Users (`users.php`)

| Ability key | Label | Access | Default | Permission | Inputs |
|---|---|---|---|---|---|
| `wsp/get-users` | Get Users | read | OFF | `list_users` | none |

- Returns: `id`, `display_name`, `email`, `roles[]`, `registered`.

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

- `get-site-info` returns: `name`, `url`, `tagline`, `admin_email`, `wp_version`, `language`.
- `get-plugins` loads `wp-admin/includes/plugin.php` if needed, then intersects all plugins with active list.

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
  toggles a `.wsp-gbody` (the rows). **All groups start collapsed.** The header shows a count badge
  (`enabled / total`, green when any are on) that updates live via JS as toggles change. Open/closed
  state persists per-browser in `localStorage` key `wsp_acc_open` (array of group names). The chevron
  (`.wsp-chev`) rotates on the `.wsp-open` class.
- JS: write abilities show a `confirm()` dialog before enabling. "Toggle All" per group (its click is
  excluded from the accordion toggle via `e.target.closest('.wsp-toggle-all')`).
- Saves to `wsp_mcp_abilities` option via Settings API (`wsp_mcp_settings_group`).

### Connection page (`MCP > Connection`) — `connection-page.php`

- **Primary, native-transport page (v2.0).** Top card shows the native endpoint
  (`rest_url('wsp-mcp/v1/mcp')`) + API key with a Regenerate button (nonce-protected admin-post action
  `wsp_mcp_regenerate_key` → `WSP_MCP_Auth::regenerate_api_key()`).
- Six tabbed, copy-to-clipboard snippets, all pointing at the native endpoint with the **API key
  hardcoded into the auth header** (no `${VAR}` env interpolation — avoids the mcp-remote "missing env
  var" failure). Server name auto-derives as `wsp-<host>`:
  - **Claude Desktop** — `mcpServers` + `npx -y mcp-remote <url> --header "Authorization: Bearer <key>"` (stdio bridge, needs Node.js; Claude Desktop config files don't support remote HTTP directly).
  - **Cursor** — native remote HTTP: `mcpServers.<name>.{ url, headers: { Authorization: "Bearer <key>" } }` (`~/.cursor/mcp.json`).
  - **Codex** — native streamable HTTP TOML: `[mcp_servers.<name>]` `url` + `http_headers = { "Authorization" = "Bearer <key>" }` (`~/.codex/config.toml`).
  - **Antigravity** — native remote HTTP, but the URL key is **`serverUrl`** (not `url`): `mcpServers.<name>.{ serverUrl, headers }` (`~/.gemini/config/mcp_config.json`).
  - **OpenClaw** — nested **`mcp.servers`** schema (not top-level `mcpServers`) + `mcp-remote` bridge, key inlined in the header (`~/.openclaw/openclaw.json`).
  - **OpenCode** — native remote HTTP under the **`mcp`** key: `mcp.<name>.{ type: "remote", url, enabled, oauth, headers: { Authorization: "Bearer <key>" } }`. Full-file snippet including `$schema` so users can create a fresh `~/.config/opencode/opencode.json`.
- Self-contained tab/copy UI markup + JS.

> **Removed in v2.2:** the old **MCP > Config Files** page (`config-page.php`) that generated
> mcp-adapter / `@automattic/mcp-wordpress-remote` snippets is gone. `wsp_mcp_redirect_legacy_config_page()`
> (on `admin_init`, in `settings-page.php`) redirects the dead `page=wsp-mcp-config` URL to MCP > Connection.

---

## Security patterns used

- All text input: `sanitize_text_field(wp_unslash($input['x']))`.
- HTML content: `wp_kses_post(wp_unslash($input['content']))`.
- IDs: `intval($input['id'])`.
- Slugs: `sanitize_title($input['slug'])`.
- MIME types: `sanitize_mime_type($input['type'])`.
- Permission callbacks: `__return_true` for public reads; `current_user_can('cap')` closures for writes and sensitive reads.
- MCP requests are authenticated inside the native server handler (App Password / Bearer key); per-tool capability checks via `require_cap()`.
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
This file (`AGENTS.md`) is the shared contract — **update it in the same PR whenever you change
architecture, hooks, tools, constants, or admin UX.**

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
- One logical change per PR; update `AGENTS.md`, `CHANGELOG.md`, and `readme.txt` changelog in the same PR.
- End commit messages with the project's Co-Authored-By trailer when an agent made the change.

**Testing note:** there is **no PHP runtime on the primary dev machine** — PHP cannot be linted or
run locally. Validate changes by installing the plugin in a real WordPress site and exercising the
endpoint with MCP Inspector / a connected client. Before release, run **Plugin Check** in WP admin.

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

