=== WSP MCP - AI Agents Connector ===
Contributors: websensepro
Tags: mcp, ai, claude, model context protocol, woocommerce
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose your WordPress site to AI agents (Claude, Cursor, and other MCP clients) through a built-in MCP server — no companion plugin required.

== Description ==

WSP MCP - AI Agents Connector turns your WordPress site into a Model Context Protocol (MCP) server. AI clients can read and edit posts, pages, categories, tags, media, comments, users, and (when installed) Yoast SEO meta and Elementor page content — all under granular, per-ability admin control.

The plugin ships its **own native MCP server**. You do not need the WordPress MCP Adapter or any companion plugin: activate, copy your connection details from **MCP > Connection**, and connect. WooCommerce tools (products, orders, refunds, coupons, customers, reports) are available when WooCommerce is active, and Advanced Custom Fields tools (field groups, fields, values, post types, taxonomies, options pages) when ACF is active.

= Key features =

* Built-in MCP server over a single REST endpoint (Streamable HTTP, JSON-RPC 2.0) — no external dependency.
* Per-ability on/off toggles in **MCP > Settings**; write abilities are off by default.
* Two authentication methods: WordPress Application Passwords (HTTP Basic) or a plugin-generated API key (`Authorization: Bearer` or `X-WSP-MCP-API-Key`).
* Capability checks on every tool — an AI client can only do what its authenticated user can do.
* Optional Yoast SEO and Elementor tools, shown only when those plugins are active.

= What AI clients can do =

* Read / create / update / delete posts and pages
* Manage categories and tags
* Read and moderate comments
* Read media, users, site info, and active plugins
* Search content
* Read and update Yoast SEO meta (requires Yoast SEO)
* Read and edit Elementor page structure (requires Elementor)
* Manage WooCommerce products, variations, orders, refunds, coupons, customers, order notes, sales reports, low-stock alerts, and review moderation (requires WooCommerce)
* Manage Advanced Custom Fields — field groups, fields, field values (with dot-notation deep access), custom post types, taxonomies, and options pages (requires ACF)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Go to **MCP > Settings** and enable the abilities you want to expose.
3. Go to **MCP > Connection** to copy your endpoint URL and API key (or use a WordPress Application Password).
4. Add the connection to your MCP client (Claude Desktop config, or any HTTP MCP client / IDE).

== Frequently Asked Questions ==

= Do I need the WordPress MCP Adapter plugin? =

No. This plugin includes its own MCP server and connects directly. As of v2.2 the older MCP Adapter / Abilities-API compatibility path has been removed; connect using the native endpoint shown on **MCP > Connection**.

= How does authentication work? =

Use a WordPress Application Password (sent via HTTP Basic auth) or the plugin-generated API key shown on the Connection page. Either is validated on every request, and tool actions are limited by the authenticated user's capabilities.

= Which AI clients are supported? =

Any client that supports the Streamable HTTP MCP transport — Claude Desktop, MCP Inspector, IDEs, and scripts.

== Changelog ==

= 2.3.0 =
* New: 27 Advanced Custom Fields tools — field groups (list, get, create, update, delete, import), fields (list, get, create, update, delete, duplicate, sync), values with dot-notation deep get/set (delete, get-all, bulk-update, field object), custom post types, taxonomies, and options pages.
* All ACF tools are off by default and only registered when ACF is active. Structural changes (groups, fields, CPTs, taxonomies, options pages) require `manage_options`; value reads/writes enforce per-object capabilities (`edit_post`, `edit_user`, `list_users`, `manage_categories`, `manage_options`).
* Changed: plugin slug renamed to `wsp-mcp-ai-agents-connector` (folder, main file, and text domain) to match the public name ahead of WordPress.org submission.
* Breaking: the plugin folder name changed — on existing installs, remove the old copy and activate the renamed plugin. Saved settings, the sessions table, and the API key are preserved.

= 2.2.0 =
* Removed: the **MCP > Config Files** page and the legacy dual-mode Abilities-API / mcp-adapter registration path. The plugin is now native-only.
* Changed: bookmarks to the old Config Files page now redirect to **MCP > Connection**.
* Breaking: connections made before v2.0 through the WordPress MCP Adapter must be re-created using the native endpoint on **MCP > Connection**.

= 2.1.0 =
* New: 15 WooCommerce tools — products (list, get, create, create variation, update), orders (list, update status, refund), coupons (create, list), order notes, customers, sales report, low-stock alerts, and review moderation.
* All WooCommerce tools are off by default and only registered when WooCommerce is active.
* Financial and PII tools (refund, customers, coupons) require the `manage_woocommerce` capability.
* Product/variation image URLs are sideloaded safely; SSL bypass is scoped to the single request and environment-gated.

= 2.0.0 =
* New: built-in native MCP server — no companion plugin or WordPress MCP Adapter required.
* New: MCP > Connection page with endpoint URL, API key, and per-client config tabs for Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw (native, no adapter).
* New: Application Password + API key authentication; per-tool capability enforcement.
* New: DB-backed session store with daily cleanup.
* Improved: MCP > Settings groups are now collapsible accordions with live enabled/total counts.
* Dual-mode: existing Abilities-API connections keep working when that transport is present.

= 1.3.0 =
* Added Yoast SEO abilities (read/update SEO title, meta description, focus keyphrase).

= 1.2.1 =
* Added OpenClaw tab to the Config Files page.

= 1.2.0 =
* Elementor abilities, modular architecture, auto config generator.
