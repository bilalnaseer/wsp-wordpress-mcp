# Changelog — WSP MCP - AI Agents Connector

> **AI Agents:** Read `AGENTS.md` → `CHANGELOG.md` → `HISTORY.md` in that order before touching any source file.
> After every code change you **must** update `AGENTS.md` (if architecture/tools/hooks changed) and add an entry here.

All notable changes to this plugin are listed here. Ordered newest-first.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
