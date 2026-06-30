# WSP WordPress MCP — Connect AI Agents to WordPress

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.3.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

---

## 🎬 Watch the Tutorial

[![WSP WordPress MCP — Full Tutorial](https://img.youtube.com/vi/nHE6PcA5pfc/maxresdefault.jpg)](https://youtu.be/nHE6PcA5pfc)

---

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
| Read Media Library | read |
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
