# WSP WordPress MCP — Connect AI Agents to WordPress

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.9.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

---

## 🎬 Watch the Tutorial

[![WSP WordPress MCP — Full Tutorial](https://img.youtube.com/vi/kD2FSvL7EE0/maxresdefault.jpg)](https://youtu.be/kD2FSvL7EE0)

---

## ✨ What's New in v2.9.0

- 🧭 **Navigation Menus tool group** — nine new tools to list menus and their items, create and delete menus, add / update / remove menu items (custom links, posts, pages, categories), list your theme's menu locations, and assign or unassign a menu to a location. All require `edit_theme_options` (the same capability the WordPress menu editor needs) and are **off by default**. Contributed by [@dulaj44](https://github.com/dulaj44) in [#41](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/41).
- 📄 **Read Post tool** (`wsp_get_post`) — fetch a single post by ID in **any** status (draft, pending, private, trash) with its full content, so an agent can review a draft before `wsp_update_post` overwrites it. Requires `edit_posts` plus per-post read permission — a Contributor cannot read another author's private post. **Off by default.** Closes [#38](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues/38).
- 🐛 **Audit Log accuracy** — permission-denied and not-found results from the new Read Post tool were being recorded as successful calls; they are now logged as denied / error like every other tool.
- 🐛 **Add Menu Item validation** — an `object_id` whose post type does not match the requested `type` (e.g. `type: page` with a blog-post ID) is now rejected instead of silently stored.

## ✨ What's New in v2.8.0

- 🔗 **One-click Claude Connector sign-in** — the plugin now runs its own **OAuth 2.1 authorization server**, so you can connect Claude by pasting only the server URL into **Customize > Connectors > Add custom connector** — no config file, no API key, no request header. Claude sends you to this site's own login page; whoever clicks **Allow** connects as themselves, and Claude can then do only what that WordPress account is permitted to do. **Off by default** — enable it from **MCP > Connection**. The existing API key and Application Password methods are unchanged and do not require it.
- 📊 **Analytics & Performance Dashboard** — new **MCP > Analytics** page with summary cards for total requests, most-used tool, average response time, and error rate; a per-category tool-usage breakdown with lightweight CSS progress bars; and a recent-requests performance log. Built entirely on the existing Audit Log table (`wp_wsp_mcp_audit_log`), which now also records each request's ability category and execution duration in milliseconds — no external service involved. Restricted to administrators (`manage_options`).
- 🧭 **"Claude Connectors" tab on MCP > Connection** — now the first tab, covering the URL-only connection path for claude.ai, Claude Desktop, and Claude mobile (they share one Connectors screen). The classic config-file method is kept as its own tab.
- ⚙️ **Configuration Generator** — pick an AI tool (Claude Desktop, Cursor, Codex, Antigravity, OpenClaw, OpenCode) and an authentication method, and the correct config snippet is built live in your browser with a one-click copy button. Application Password mode never sends your credentials to the server; the header is computed client-side.
- ⬇️ **Download button on every snippet** — beside **Copy**, saving the exact config file directly. Cursor users also get a **Connect Cursor Automatically** button using Cursor's official one-click MCP install link.
- 🔒 **Hardened OAuth server** — ships after a pre-release review: off until an administrator enables it, and switching it off disconnects anything already connected; approving a connector requires an account that can edit posts, so opening registration on your site does not open MCP access with it; the consent screen names the exact address access will be sent to and warns that an application's name is self-assigned and unverified; consent and error pages cannot be framed (clickjacking); client registration is rate-limited and capped with automatic pruning; and replaying a spent refresh token revokes the whole token family.
- 🐛 **OAuth discovery on subdirectory installs — fixed** — installs like `https://example.com/test/` could leave a connected connector with "no tools available." Discovery documents are now served at every URL spelling the install can actually reach, and the two-installs-on-one-domain case is disambiguated with a base-path-aware issuer identity.
- 🐛 **Stray output from other plugins breaking JSON responses — fixed** — a PHP notice/warning printed by any other active plugin during an ordinary WordPress hook could land in front of this plugin's JSON response and break every MCP client's parser (Claude showed the connector as connected but with "no tools available," and it could trigger a "headers already sent" warning). A new output-buffer guard opens the instant this plugin's own MCP or OAuth endpoint is requested and discards any such stray output right before the real JSON is sent, regardless of what else is installed.

## ✨ What's New in v2.7.1

- 🔒 **Security: object-level authorization on post, page & media write tools** — reported by Patchstack (Ananda Dhakal) as an *Authenticated (Contributor+) Broken Access Control* issue in `<= 2.7.0`. **Update Post**, **Delete Post**, **Update Page**, **Delete Page**, **Update Media**, **Delete Media**, and **Set Featured Image** only checked a broad primitive capability (`edit_posts` / `delete_posts`) — which a stock Contributor holds — and then passed a caller-chosen object ID straight to WordPress without checking ownership, post type, or the requested status. Once an admin had enabled one of these write tools, a Contributor using their own Application Password could overwrite, publish, unpublish, or trash a post, page, or attachment owned by an Administrator or Editor. All of these callbacks now load the target object and enforce the per-object `edit_post` / `delete_post` meta capability, restrict each tool to its expected post type (`post` / `page` / `attachment`), and require the post type's publish capability before accepting a `publish`, `future`, or `private` status — the same checks WordPress core's own REST endpoints perform. Editors and Administrators are unaffected. Write tools remain **off by default**. Merged from [bilalnaseer/wsp-wordpress-mcp](https://github.com/bilalnaseer/wsp-wordpress-mcp).

## ✨ What's New in v2.7.0

- 🧾 **Full Audit Log** — every MCP `tools/call` request is now recorded in a dedicated, self-hosted table (`wp_wsp_mcp_audit_log`): tool name, timestamp (UTC), acting user (or *unauthenticated*), request IP, and the outcome — **success**, **denied**, or **error** — with a short message for failures. Nothing leaves your server: no external API, no third-party service, no paid dependency. Only the direct connection (`REMOTE_ADDR`) is logged, since proxy headers like `X-Forwarded-For` are attacker-controlled and could be used to frame another address. Logging can never break a tool call — a write failure is swallowed silently.
- 🗂️ **MCP > Audit Log admin page** — browse the trail newest-first, filter by status or tool name, page through it, and clear it in one click. Restricted to administrators (`manage_options`), like every other MCP screen.
- 🧹 **Automatic pruning** — a daily cron task (`wsp_mcp_audit_log_cleanup`) deletes entries older than **90 days**, so the table stays small. Change the window with the `wsp_mcp_audit_log_retention_days` filter. The table is created on activation *and* on the first load after a Plugins-screen update, and is dropped on uninstall.

## ✨ What's New in v2.6.8

- 🐛 **"Session not found or expired" on the very first request — fixed** — clients that send `tools/list` immediately after `initialize` (Claude Desktop via `mcp-remote`, and any fast script) were rejected with `Session not found or expired. Re-initialize.` The connection looked healthy, tools appeared to load, and then every command timed out. Inserting a 2-second pause made it work, which pointed at slow or remote database hosting — but that was a red herring. The session row was always there: sessions store their expiry to the second, so a request landing in the *same second* as `initialize` rewrote an identical expiry timestamp, and MySQL/MariaDB report **changed** rows rather than **matched** rows — returning `0`, which the plugin read as "no such session." A zero-row update is now confirmed with an existence check before any session is rejected. Missing and expired sessions are still rejected exactly as before. No delay, workaround, or client-side change needed. Fixes [#30](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues/30) — with thanks to [@WikiZell](https://github.com/WikiZell) for isolating the cause and testing the patch.

## ✨ What's New in v2.6.7

- 🐛 **Copy buttons fixed on plain-HTTP sites** — the **Copy** button on every snippet tab in **MCP > Connection** did nothing on sites not served over HTTPS (typically local dev hosts like `http://mysite.local/`). The browser Clipboard API is only available in a *secure context* — HTTPS or `localhost` — so on any other hostname it was missing entirely and the click failed silently. Copying now falls back to a hidden textarea when the Clipboard API is unavailable. All six client tabs are fixed.
- 🟢 **Enabled/disabled tally per group** — each ability group header now shows a green **"N Enabled"** pill next to a red **"N Disabled"** pill, replacing the single `enabled / total` badge that looked the same whether a group was partly or fully on. Counts update live as you flip switches or use **Toggle All**.
- 🎬 **Tutorials & directory links in the admin** — **MCP > Settings** and **MCP > Connection** now carry sidebar cards linking to our [video tutorials](https://freewordpressmcp.com/tutorials) and the full [abilities directory](https://freewordpressmcp.com/abilities-directory).
- 🔒 **Verified against WordPress 7.0.3** — no plugin changes were needed. The `kses` (CSS injection) and HTTP URL-validation (SSRF) fixes in that security release are inherited automatically, because this plugin calls the core APIs rather than reimplementing them. Since this plugin exposes tools to AI agents, we recommend running **WordPress 7.0.3 or 6.9.6+** so those fixes are in place.

## ✨ What's New in v2.6.6

New: Direct file upload for media. wsp_upload_media (Upload Media) now accepts base64 file content via a new data parameter — an MCP client can upload a file attached to the chat straight into the media library without first hosting it at a public URL. The url parameter still works as before; pass either one. An optional mime_type hint and data: URI prefixes are supported. Only image types (jpg, png, gif, webp) are allowed, decoded bytes are written through media_handle_sideload(), and the tool still requires upload_files.

## ✨ What's New in v2.6.5

- 🎨 **Elementor Advanced Design Tools (11 tools)** — new tools for high-fidelity design work, all OFF by default and toggled from **MCP > Settings** under the "Elementor" group. Read the active kit's global colors, fonts, and layout (`get-active-kit`) and update them (`update-active-kit`); regenerate the Elementor CSS cache (`regenerate-css`); fetch a widget's full control schema — margins, padding, typography, borders (`get-widget-schema`); duplicate an element with fresh unique IDs (`duplicate-element`) or move it to a new spot (`move-element`); turn plain CSS into Elementor settings (`convert-css`); read/update page-level settings like template and background (`get-page-settings` / `update-page-settings`); copy one element's styles onto another (`copy-styles`); and read the responsive breakpoints (`get-breakpoints`). Every write tool runs through the same `wsp_elementor_sanitize_settings()` guard as the rest of the Elementor suite, so no code can be injected. `update-active-kit` and `regenerate-css` require `manage_options`; the rest require `edit_posts`.

## ✨ What's New in v2.6.4

- 📊 **WPForms Suite (12 tools)** — full support for WPForms (Lite and Pro), toggled from **MCP > Settings** under the "WPForms" group. **List Forms**, **Get Form**, **Describe Schema**, and **Get Form Stats** are ON by default; all write tools are OFF. Covers **forms** (list, get, describe-schema, get-form-stats, create, update settings, add field, update field, delete) and **entries** (list, get, delete — WPForms Pro only). Uses WPForms' own capabilities (`wpforms_view_forms`, `wpforms_edit_forms`, `wpforms_view_entries`, `wpforms_edit_entries`); all strings are sanitized before saving. Only registered when WPForms is active.

## ✨ What's New in v2.6.3

- 📬 **Contact Form 7 Suite (10 tools)** — full support for Contact Form 7, toggled from **MCP > Settings** under the "Contact Form 7" group. **List Forms** and **Get Form** are ON by default; all write tools are OFF. Covers **forms** (list, get, create, update, delete), **entries** via Flamingo (list, get), **validation** (`validate-form` catches email/syntax errors), **integrations** (active modules + reCAPTCHA status), and **moderation** (spam/unspam/trash/untrash a submission). Entry tools require the Flamingo plugin, since CF7 doesn't store entries on its own. Uses CF7's capabilities (`wpcf7_edit_contact_forms`, `wpcf7_delete_contact_forms`); `get-integrations` requires `manage_options`. Only registered when Contact Form 7 is active.

## ✨ What's New in v2.6.2

- 📬 **Gravity Forms Suite (18 tools)** — full read/write control over Gravity Forms, with **List Forms** and **Get Form** ON by default and all write tools OFF. Toggled from **MCP > Settings** under the "Gravity Forms" group (icon: 📋). Covers **forms** (list, get, create, update, delete, update settings), **entries** (list, get, update, delete with trash/permanent support), **notifications** (get, create, update, delete), and **confirmations** (get, create, update, delete — message/redirect/page types). All callbacks use `GFAPI` and enforce strict Gravity Forms capability checks (`gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_delete_forms`, `gravityforms_view_entries`, `gravityforms_edit_entries`, `gravityforms_delete_entries`). Only registered when Gravity Forms is active.

## ✨ What's New in v2.6.1

- 📬 **Gravity Forms Suite** — introduced the 18-tool Gravity Forms integration, with List Forms and Get Form ON by default and all write tools OFF. Toggled from **MCP > Settings** under the "Gravity Forms" group. Covers **forms**, **entries**, **notifications**, and **confirmations**. All callbacks use `GFAPI` and enforce strict Gravity Forms capability checks (`gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_delete_forms`, `gravityforms_view_entries`, `gravityforms_edit_entries`, `gravityforms_delete_entries`). Only registered when Gravity Forms is active.

## ✨ What's New in v2.6.0

- 🧱 **Ultimate Addons for Elementor (UAE) Suite** — 45 new tools for UAE, all off by default and toggled from **MCP > Settings** under the "Ultimate Addons Elementor" group. Covers **widgets** (activate, deactivate, bulk toggle, check usage, list), **templates** (create, duplicate, trash, restore, and update Header/Footer/Blocks templates), the **builder/engine** (add sections, add columns, move elements, build layouts from JSON), and **settings** (get/update UAE plugin settings, theme info, extensions, and design-system tokens). String inputs are sanitized with `wp_kses_post()` and every tool enforces a strict capability check (`edit_posts`, `publish_posts`, or `manage_options`). Only registered when UAE is active.
- 🐛 **Fixed `wsp_uae_builder_add_column`** — it silently created a `container` instead of a `column` because the type validation in `wsp_execute_elementor_add_container()` only accepted `container` and `section`. `column` is now a valid type.

## ✨ What's New in v2.5.0

- 🖼️ **Full Media Library Suite** — the single read-only media tool is now a complete set of seven: **List Media** (browse/search by type, keyword, or date), **Get Media** (full metadata of a single attachment by ID), **Count Media** (counts grouped by MIME type + total), **Update Media** (title, alt text, caption, description), **Delete Media** (permanent), and **Upload Media** / **Upload Media From URL** (import a file straight from any web link). All off by default and toggled from **MCP > Settings**. Reads require `upload_files`, deletes require `delete_posts`; uploads sanitize the source URL and sideload via WordPress core.
- ⚠️ **`wsp_get_media` behavior changed** — it now returns the full metadata of a **single** attachment by ID. The old "list the library" behavior moved to the new **`wsp_list_media`** tool. If you relied on `wsp_get_media` to list media, switch to `wsp_list_media`.

## ✨ What's New in v2.4.1

- 🔒 **Hardened ACF writes** — all ACF field-value write tools now recursively sanitize incoming values before saving (each string is run through `wp_kses_post()`), so `<script>`/`<style>` and inline event handlers can no longer be stored through the MCP tools. Legitimate WYSIWYG/HTML content still works. Resolves the WordPress.org "arbitrary code insertion" review finding.

## ✨ What's New in v2.4.0

- 🔌 **OpenCode connection tab** — a sixth copy-paste config snippet on **MCP > Connection**, joining Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw. OpenCode connects natively over remote HTTP (no Node.js bridge); the snippet is a full `~/.config/opencode/opencode.json` file ready to create and paste.

## ✨ What's New in v2.3.0

- 🧩 **Advanced Custom Fields Suite** — 27 new tools for ACF: field groups, fields, field values with **dot-notation deep access** (e.g. `repeater.0.subfield`), custom post types, taxonomies, and options pages. All off by default and only registered when ACF is active; structural changes (create/update/delete groups, fields, CPTs, taxonomies) require `manage_options`, with per-object capability checks on every value read/write.
- 🏷️ **Plugin slug renamed** to `wsp-mcp-ai-agents-connector` to match the public name ahead of WordPress.org submission. ⚠️ **Breaking on existing installs** — WordPress treats the renamed folder as a separate plugin, so remove the old `websensepro-mcp-abilities` copy and activate the new one. Saved settings, the sessions table, and the API key are preserved (no reconfiguration needed).

## ✨ What's New in v2.2.0

- 🧹 **Native-Only** — the legacy dual-mode Abilities-API / MCP-Adapter registration path and the **MCP > Config Files** page have been removed. The built-in native server is now the single transport.
- 🔁 **Seamless Redirects** — old bookmarks to the Config Files page now redirect to **MCP > Connection**.
- ⚠️ **Breaking** — connections made before v2.0 through the WordPress MCP Adapter must be re-created using the native endpoint on **MCP > Connection**. New installs and native connections are unaffected.

## Previous Releases

**v2.1.0** — 🛒 **WooCommerce Suite** — 15 new tools covering products (list, get, create, create variation, update), orders (list, update status, refund), coupons (create, list), order notes, customers, sales reports, low-stock alerts, and review moderation. All off by default and only registered when WooCommerce is active; financial/PII tools require the `manage_woocommerce` capability.

**v2.0.0**
- 🚀 **Built-in Native MCP Server** — the plugin ships its own MCP server at `/wp-json/wsp-mcp/v1/mcp`. **No companion plugin, WordPress MCP Adapter, or Node.js bridge required.**
- 🔌 **MCP > Connection Page** — endpoint URL, API key (with one-click regenerate), and ready-to-paste config tabs for **Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw** — the API key is pre-filled for you.
- 🔐 **Flexible Auth** — connect with a WordPress Application Password **or** the plugin's API key (`Authorization: Bearer`), with per-tool capability enforcement.
- 🗂️ **Cleaner Settings** — ability groups are now collapsible accordions with live enabled/total counts.


**v1.3.0** — 🔍 Yoast SEO abilities (read/update SEO title, meta description, focus keyphrase); group only appears when Yoast is active.

**v1.2.1** — Add OpenClaw tab to Config Files page

**v1.2.0**
- ⚡ **Elementor Abilities** — list pages, get page structure, find/get/update elements, add widgets & containers, remove elements
- 🗂️ **Modular Plugin Architecture** — refactored into `includes/` with separate files per feature group
- 🔧 **Auto Config Generator** — generates ready-to-paste configs for Claude Desktop & Codex from wp-admin
- 🔒 **Granular Ability Controls** — enable/disable each ability individually; Elementor group only shown when Elementor is active
- 📦 **WP.org Ready** — proper headers, license, `uninstall.php`, and PHP 7.4+ support

---

## 🛠️ Available Abilities

### Core WordPress
| Ability | Access |
|---------|--------|
| Read / Get / Create / Update / Delete Posts | read / write |
| Read / Create / Update / Delete Pages | read / write |
| Read Categories & Tags / Create | read / write |
| Read / Approve / Delete Comments | read / write |
| List / Get / Count Media | read |
| Update / Delete / Upload Media *(upload from URL)* | write |
| Read Users | read |
| Search Content | read |
| Read Site Info & Active Plugins | read |
| Read Menus / Menu Items / Menu Locations | read |
| Create / Delete Menu, Add / Update / Delete Menu Item, Assign Location | write |

### Yoast SEO *(requires Yoast SEO plugin)*
| Ability | Access |
|---------|--------|
| Get Yoast SEO Meta (title, meta description, focus keyphrase) | read |
| Update Yoast SEO Meta | write |

### Elementor *(requires Elementor plugin)*
| Ability | Access |
|---------|--------|
| List Elementor Pages | read |
| Get Page Structure (element tree) | read |
| Get Element Settings | read |
| Find Element by type or content | read |
| List Templates | read |
| Update Element settings | write |
| Add Widget to page | write |
| Add Container / Section | write |
| Remove Element | write |

### WooCommerce *(requires WooCommerce plugin)*
| Ability | Access |
|---------|--------|
| List / Get Products | read |
| Create Product / Create Variation | write |
| Update Product | write |
| List Orders / Update Order Status | read / write |
| Refund Order *(requires `manage_woocommerce`)* | write |
| Create / List Coupons *(requires `manage_woocommerce`)* | write / read |
| Create Order Note | write |
| List Customers *(requires `manage_woocommerce`)* | read |
| Sales Report | read |
| Low-Stock Alerts | read |
| Moderate Product Reviews | write |

### Advanced Custom Fields *(requires ACF plugin)*
| Ability | Access |
|---------|--------|
| List / Get Field Groups | read |
| Create / Update / Delete Field Group | write |
| Import Field Groups (JSON) | write |
| List / Get Fields | read |
| Create / Update / Delete / Duplicate Field | write |
| Force Sync Fields | write |
| Get / Get-All / Get Field Object (values) | read |
| Update Deep / Bulk Update / Delete Value | write |
| List Post Types / Taxonomies | read |
| Create Custom Post Type / Taxonomy *(ACF 6.1+)* | write |
| List / Create Options Page *(create needs ACF Pro)* | read / write |
| Get / Update Option Value | read / write |

> Value reads/writes accept a target of a post/page ID, `user_<id>`, `term_<id>`, or `options`, and enforce per-object capabilities (e.g. `edit_post`, `edit_user`, `manage_categories`, `manage_options`). Structural changes require `manage_options`.

### Ultimate Addons for Elementor *(requires UAE plugin)*
| Ability | Access |
|---------|--------|
| List UAE Widgets / Check Widget Usage | read |
| Activate / Deactivate / Bulk Toggle Widgets | write |
| List / Get Header, Footer & Blocks Templates | read |
| Create / Duplicate / Update Template | write |
| Trash / Restore Template | write |
| Add Section / Add Column / Move Element | write |
| Build Layout from JSON | write |
| Get UAE Settings / Theme Info / Extensions / Design Tokens | read |
| Update UAE Settings / Design Tokens | write |

> 45 tools in total, off by default and only registered when UAE is active. Structural and settings writes require `edit_posts`, `publish_posts`, or `manage_options`; all string inputs are sanitized with `wp_kses_post()`.

### Gravity Forms *(requires Gravity Forms plugin)*
| Ability | Access |
|---------|--------|
| List / Get Forms | read |
| Create / Update / Delete Form | write |
| Update Form Settings | write |
| List / Get Entries | read |
| Update / Delete Entry | write |
| Get Notifications | read |
| Create / Update / Delete Notification | write |
| Get Confirmations | read |
| Create / Update / Delete Confirmation | write |

> 18 tools in total. List Forms and Get Form are ON by default; all write tools are OFF by default. All callbacks require appropriate Gravity Forms capabilities (`gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_delete_forms`, `gravityforms_view_entries`, `gravityforms_edit_entries`, `gravityforms_delete_entries`).

---

## 🚀 Quick Start

**Prerequisites:** WordPress 6.9+ (7.0.3 or 6.9.6+ recommended — see v2.6.7 notes), PHP 7.4+ — **that's it.** No companion plugin, no MCP Adapter, no Node.js for natively-supported clients (Cursor, Codex, Antigravity). Claude Desktop & OpenClaw use the `mcp-remote` bridge, which needs Node.js 18+.

1. Install & activate this plugin
2. Go to **MCP > Settings** in wp-admin and enable the abilities you need
3. Go to **MCP > Connection** and pick your client tab:
   - **Claude (claude.ai, Desktop, or mobile):** enable the OAuth server on the **Claude Connectors** tab, then paste just the server URL into **Customize > Connectors > Add custom connector** and sign in with your WordPress account
   - **Everything else (Cursor, Codex, Antigravity, OpenClaw, OpenCode):** use the Configuration Generator, then **Copy** or **Download** the snippet — the endpoint URL and credentials are already filled in — and paste it into your client's config (Cursor also gets a one-click **Connect Cursor Automatically** button)
4. Reconnect / restart the client and start prompting your AI agent
5. Review what your agent actually did under **MCP > Audit Log**, and check usage and response times under **MCP > Analytics**

> **Upgrading from before v2.0?** As of v2.2 the legacy MCP-Adapter / Abilities-API path and the **MCP > Config Files** page have been removed. Re-create your connection using the native endpoint on **MCP > Connection**.

---

## 🏢 About WebSensePro

Built by [WebSensePro](https://websensepro.com) — WordPress & Shopify agency from Queens, NY.

- 🏆 [Official Shopify Partner](https://www.shopify.com/partners/directory/partner/websensepro1)
- 🎥 [140K+ YouTube Subscribers](https://m.youtube.com/websensepro)
- 🤖 [Official n8n Creator](https://n8n.io/creators/websensepro/)
- 📧 [info@websensepro.com](mailto:info@websensepro.com)

---

<div align="center">

[⭐ Star this repo](https://github.com/bilalnaseer/wsp-wordpress-mcp) · [🍴 Fork it](https://github.com/bilalnaseer/wsp-wordpress-mcp/fork) · [🐛 Report a bug](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues)

</div>
