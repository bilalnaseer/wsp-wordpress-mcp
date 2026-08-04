# WSP WordPress MCP — Connect AI Agents to WordPress

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.7.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

---

## 🎬 Watch the Tutorial

[![WSP WordPress MCP — Full Tutorial](https://img.youtube.com/vi/1hGSUAdRxiU/maxresdefault.jpg)](https://youtu.be/1hGSUAdRxiU)

---


## ✨ What's New in v2.7.0

- **GeoDirectory listing tools (3 tools)** — search editable listings, import up to 50 listings with dry-run and per-record results, and update one existing listing. All three are OFF by default and appear only when GeoDirectory is active. The write tools accept a fixed listing-field allowlist, use GeoDirectory's own REST controller, skip likely duplicates with explicit match reasons, default new records to draft, preflight create/publish/taxonomy/media permissions, and flag partial creates for review without changing WSP authentication or API-key access.

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
- 📦 **WP.org Ready** — proper headers, license, `uninstall.php`, and PHP 7.2+ support

---

## 🛠️ Available Abilities

### Core WordPress
| Ability | Access |
|---------|--------|
| Read / Create / Update / Delete Posts | read / write |
| Read / Create / Update / Delete Pages | read / write |
| Read Categories & Tags / Create | read / write |
| Read / Approve / Delete Comments | read / write |
| List / Get / Count Media | read |
| Update / Delete / Upload Media *(upload from URL)* | write |
| Read Users | read |
| Search Content | read |
| Read Site Info & Active Plugins | read |

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

**Prerequisites:** WordPress 6.2+, PHP 7.4+ — **that's it.** No companion plugin, no MCP Adapter, no Node.js for natively-supported clients (Cursor, Codex, Antigravity). Claude Desktop & OpenClaw use the `mcp-remote` bridge, which needs Node.js 18+.

1. Install & activate this plugin
2. Go to **MCP > Settings** in wp-admin and enable the abilities you need
3. Go to **MCP > Connection** and pick your client tab (Claude Desktop, Cursor, Codex, Antigravity, or OpenClaw)
4. Copy the snippet — the endpoint URL and API key are already filled in — and paste it into your client's config
5. Reconnect / restart the client and start prompting your AI agent

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
