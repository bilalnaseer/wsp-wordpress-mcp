=== WSP MCP - AI Agents Connector ===
Contributors: bilalnaseer
Tags: mcp, ai, claude, model context protocol, woocommerce
Requires at least: 6.9
Tested up to: 7.0.1
Requires PHP: 7.4
Stable tag: 2.6.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose your WordPress site to AI agents (Claude, Cursor, and other MCP clients) through a built-in MCP server — no companion plugin required.

== Description ==

WSP MCP - AI Agents Connector turns your WordPress site into a Model Context Protocol (MCP) server. AI clients can read and edit posts, pages, categories, tags, media, comments, users, and (when installed) Yoast SEO meta and Elementor page content — all under granular, per-ability admin control.
 
The plugin ships its **own native MCP server**. You do not need the WordPress MCP Adapter or any companion plugin: activate, copy your connection details from **MCP > Connection**, and connect. WooCommerce tools (products, orders, refunds, coupons, customers, reports) are available when WooCommerce is active, Advanced Custom Fields tools (field groups, fields, values, post types, taxonomies, options pages) when ACF is active, Ultimate Addons for Elementor (UAE) tools (widgets, templates, layout building, and settings) when UAE is active, and Gravity Forms tools (forms, entries, notifications, and confirmations) when Gravity Forms is active.
 
Built and maintained by the [WebSensePro](https://websensepro.com/) team. For documentation, setup guides, and connection help, visit the plugin home at [freewordpressmcp.com](https://freewordpressmcp.com/).

= Video tutorial =

https://youtu.be/1hGSUAdRxiU

= Key features =
 
* Built-in MCP server over a single REST endpoint (Streamable HTTP, JSON-RPC 2.0) — no external dependency.
* Per-ability on/off toggles in **MCP > Settings**; write abilities are off by default.
* Two authentication methods: WordPress Application Passwords (HTTP Basic) or a plugin-generated API key (`Authorization: Bearer` or `X-WSP-MCP-API-Key`).
* Capability checks on every tool — an AI client can only do what its authenticated user can do.
* Optional Yoast SEO and Elementor tools, shown only when those plugins are active.
 
= Complete tools list =
 
Every tool is individually toggleable in **MCP > Settings**, and all write tools are off by default.
 
**Core WordPress**
 
* Posts — read, create, update, delete
* Pages — read, create, update, delete
* Categories — list, create, update, delete
* Tags — list, create, update, delete
* Comments — read, approve, and delete
* Media — read the media library
* Users — read user data
* Site info — read general site details
* Plugins — list active plugins
* Search — search across site content
 
**Yoast SEO** (requires Yoast SEO)
 
* Read SEO title, meta description, and focus keyphrase
* Update SEO title, meta description, and focus keyphrase
 
**Elementor** (requires Elementor)

* Pages — list pages/posts built with Elementor
* Page structure — read the full element tree of a page
* Elements — get a single element's settings, or find elements by widget type or content
* Templates — list saved templates from the library
* Editing — add widgets, add layout containers/sections, update element settings, and remove elements
* Code-bearing widget types (HTML, Shortcode, Code) are rejected and code-bearing settings (Custom CSS, Custom Attributes) are stripped; all text settings are sanitized with `wp_kses_post()`
 
**WooCommerce** (requires WooCommerce — financial and PII tools require the `manage_woocommerce` capability)
 
* Products — list, get, create, update
* Product variations — create
* Orders — list, update status
* Refunds — process refunds
* Coupons — create, list
* Order notes — add order notes
* Customers — read customer data
* Sales report — read sales reporting
* Low-stock alerts — read low-stock products
* Reviews — moderate product reviews
 
**Advanced Custom Fields** (requires ACF — structural changes require `manage_options`; value tools enforce per-object capabilities)
 
* Field groups — list, get, create, update, delete, import
* Fields — list, get, create, update, delete, duplicate, sync
* Field values — get and set with dot-notation deep access, delete, get-all, bulk-update, and field object
* Custom post types — manage
* Taxonomies — manage
* Options pages — manage

**Gravity Forms** (requires Gravity Forms — reads require `gravityforms_edit_forms` or `gravityforms_view_entries`; writes require form/entry-specific Gravity Forms caps)

* Forms — list (ON by default), get (ON by default), create, update, delete, update form settings
* Entries — list, get, update (status, read/starred flags, field values), delete (trash or permanent)
* Notifications — get, create, update, delete
* Confirmations — get, create, update, delete
* All 18 tools are off by default (except list-forms and get-form); only registered when Gravity Forms is active

**Ultimate Addons for Elementor** (requires UAE — structural and settings writes require `edit_posts`, `publish_posts`, or `manage_options`)

* Widgets — list, check usage, activate, deactivate, bulk toggle
* Templates — list, get, create, duplicate, update, trash, restore Header/Footer/Blocks templates
* Layout building — add sections, add columns, move elements, build layouts from JSON
* Settings — get/update UAE settings, theme info, extensions, and design-system tokens
* All 45 tools are off by default; string inputs are sanitized with `wp_kses_post()`

= Links =
 
* Plugin home & docs: [freewordpressmcp.com](https://freewordpressmcp.com/)
* Built by: [WebSensePro](https://websensepro.com/)

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

= How do I connect WordPress with OpenClaw? =

Watch the step-by-step video tutorial:

https://youtu.be/GLyLzxVOxm4

= How do I connect WordPress with Google Antigravity 2.0? =

Watch the step-by-step video tutorial:

https://youtu.be/2gRIRcqqOpo

= How do I connect WordPress with Codex? =

Watch the step-by-step video tutorial:

https://youtu.be/hxhjs3IUYQE

== Changelog ==

= 2.6.5 =
* New: Elementor Advanced Design Tools — 11 tools for high-fidelity design workflows: get/update active kit, regenerate CSS, get widget schema, duplicate/move element, convert CSS to Elementor settings, get/update page settings, copy styles, and get breakpoints. All off by default under the "Elementor" group. Security: write tools run settings through `wsp_elementor_sanitize_settings()` (strips `custom_css`, `custom_attributes`, and dynamic keys); `update-active-kit` and `regenerate-css` require `manage_options`, the rest require `edit_posts`.

= 2.6.4 =
* New: WPForms suite — 12 tools (Lite and Pro) covering forms (list, get, describe-schema, get-form-stats, create, update-settings, add-field, update-field, delete) and Pro entries (list, get, delete). All write tools off by default under the "WPForms" group; only registered when WPForms is active. Uses WPForms' native capabilities: `wpforms_view_forms` / `wpforms_edit_forms` for forms and `wpforms_view_entries` / `wpforms_edit_entries` for entries.

= 2.6.3 =
* New: Contact Form 7 suite — 10 tools covering forms (list, get, create, update, delete), Flamingo entries (list, get, moderate), form validation, and integrations status. All write tools off by default under the "Contact Form 7" group; only registered when CF7 is active. Uses CF7's native capabilities `wpcf7_edit_contact_forms` and `wpcf7_delete_contact_forms`; `get-integrations` requires `manage_options`.

= 2.6.2 =
* Docs: documented the complete 18-tool Gravity Forms suite (forms, entries, notifications, confirmations) across the readme, plugin docs, and changelog; the 2.6.1 notes under-reported it as 11 tools and omitted the notification, confirmation, and form-settings write tools. Corrected the capability name to `gravityforms_create_form`. No behavioral code changes.

= 2.6.1 =
* New: Gravity Forms suite — 18 tools covering forms (list, get, create, update, delete, update settings), entries (list, get, update, delete with trash/permanent), notifications (get, create, update, delete), and confirmations (get, create, update, delete). All write tools are off by default; list-forms and get-form are on by default. Uses Gravity Forms' own granular capabilities (`gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_view_entries`, etc.). Only registered when Gravity Forms is active (`class_exists('GFAPI')`).
* Docs: added a video tutorial to the plugin description and three connection walkthrough videos (OpenClaw, Google Antigravity 2.0, Codex) to the FAQ.

= 2.6.0 =
* New: Ultimate Addons for Elementor (UAE) tool suite — 45 tools covering widgets (list, check usage, activate, deactivate, bulk toggle), templates (list, get, create, duplicate, update, trash, restore Header/Footer/Blocks templates), layout building (add sections, add columns, move elements, build from JSON), and settings (UAE settings, theme info, extensions, design-system tokens). All off by default and only registered when UAE is active.
* Fixed: adding an Elementor column no longer creates a container instead — the type validation in the add-container handler now accepts the `column` type.
* Security: all UAE string inputs are sanitized with `wp_kses_post()`; each tool enforces a strict capability check (`edit_posts`, `publish_posts`, or `manage_options`).

= 2.5.0 =
* New: Full media library tool suite. Adds six media tools — List Media (browse/search by type, keyword, or date), Count Media (counts grouped by MIME type plus a total), Update Media (title, alt text, caption, description), Delete Media (permanent), Upload Media (from a URL), and Upload Media From URL — and repurposes Get Media to return the full metadata of a single attachment by ID. Every tool is off by default and toggled from MCP > Settings.

= 2.4.1 =
* Security: ACF field-value write tools no longer accept raw code. Every value written via `update_field()` — for posts, users, terms, and options — is now recursively sanitized (arrays walked; each string run through `wp_kses_post()`) so `<script>`/`<style>` and inline event handlers can no longer be stored through the MCP tools. Legitimate WYSIWYG/HTML field content still works. Addresses the WordPress.org "arbitrary code insertion" review finding.

= 2.4.0 =
* New: OpenCode connection tab on the MCP > Connection page — a sixth copy-paste config snippet joining Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw. OpenCode connects natively over remote HTTP (no Node.js bridge); the snippet is a full `~/.config/opencode/opencode.json` file with the API key inlined in the header, ready to create and paste.

= 2.3.1 =
* Security: Elementor write tools no longer accept raw code. Code-bearing widget types (HTML, Shortcode, Code) are rejected, code-bearing settings (Custom CSS, Custom Attributes) are stripped, and all text settings are sanitized with `wp_kses_post()` so scripts cannot be injected via `_elementor_data`.
* Security: ACF options-page value reads now require `manage_options` (was `edit_posts`), matching the admin-level nature of global options.
* Removed: unused legacy `wsp_register_acf_abilities()` dual-mode registration helper (dead code, not hooked).
* Changed: `Requires at least` now uses the major-only WordPress version format (6.9).

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
