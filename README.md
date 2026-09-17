# WSP WordPress MCP — Connect AI Agents to WordPress

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.9.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

Turn any WordPress site into a **Model Context Protocol (MCP) server** so AI agents — Claude, Cursor, Codex, Antigravity, OpenClaw, OpenCode — can read and edit your posts, pages, media, menus, WooCommerce store, forms, SEO meta, and Elementor layouts. The MCP server is **built in**: no companion plugin, no MCP Adapter, no Node.js bridge for natively-supported clients. Every ability is an individual on/off switch in wp-admin, write abilities are off by default, and every call is recorded in a self-hosted audit log.

---

## 🎬 Watch the Tutorial

[![WSP WordPress MCP — Full Tutorial](https://img.youtube.com/vi/kD2FSvL7EE0/maxresdefault.jpg)](https://youtu.be/kD2FSvL7EE0)

---

## 🚀 Quick Start

**Prerequisites:** WordPress 6.9+ (7.0.3 or 6.9.6+ recommended), PHP 7.4+ — **that's it.** Claude Desktop and OpenClaw use the `mcp-remote` bridge, which needs Node.js 18+; Cursor, Codex, Antigravity, and OpenCode connect natively.

1. Install & activate this plugin
2. Go to **MCP > Settings** in wp-admin and enable the abilities you need
3. Go to **MCP > Connection** and pick your client:
   - **Claude (claude.ai, Desktop, or mobile):** enable the OAuth server on the **Claude Connectors** tab, then paste just the server URL into **Customize > Connectors > Add custom connector** and sign in with your WordPress account
   - **Everything else (Cursor, Codex, Antigravity, OpenClaw, OpenCode):** use the Configuration Generator, then **Copy** or **Download** the snippet — the endpoint URL and credentials are already filled in — and paste it into your client's config (Cursor also gets a one-click **Connect Cursor Automatically** button)
4. Reconnect / restart the client and start prompting your AI agent
5. Review what your agent actually did under **MCP > Audit Log**, and check usage and response times under **MCP > Analytics**

> **Upgrading from before v2.0?** The legacy MCP-Adapter / Abilities-API path and the **MCP > Config Files** page were removed in v2.2. Re-create your connection using the native endpoint on **MCP > Connection**.

### Connection guides by client

| Client | Guide |
|--------|-------|
| Claude (claude.ai / Desktop / mobile) | [Full tutorial](https://youtu.be/kD2FSvL7EE0) |
| OpenClaw | [Video](https://youtu.be/GLyLzxVOxm4) |
| Google Antigravity | [Video](https://youtu.be/2gRIRcqqOpo) |
| Codex | [Video](https://youtu.be/hxhjs3IUYQE) |
| All tutorials & abilities directory | [freewordpressmcp.com](https://freewordpressmcp.com/) |

---

## ✨ What's New in v2.9.0

- 🧭 **Navigation Menus tool group** — nine new tools to list menus and their items, create and delete menus, add / update / remove menu items (custom links, posts, pages, categories), list your theme's menu locations, and assign or unassign a menu to a location. Require `edit_theme_options`; **off by default**. Contributed by [@dulaj44](https://github.com/dulaj44) in [#41](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/41).
- 📄 **Read Post tool** — fetch a single post by ID in **any** status (draft, pending, private, trash) with its full content, so an agent can review a draft before updating it. Enforces per-post read permission; **off by default**. Closes [#38](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues/38).
- 🐛 **Audit Log accuracy** — permission-denied results from the new Read Post tool are now logged as denied, not success.
- 🐛 **Add Menu Item validation** — an `object_id` whose post type doesn't match the requested `type` is now rejected instead of silently stored.

**Recent releases:** v2.8.0 — one-click Claude Connector sign-in (OAuth 2.1) + Analytics dashboard · v2.7.1 — object-level authorization on write tools (Patchstack) · v2.7.0 — Audit Log · v2.6.x — WPForms, Contact Form 7, Gravity Forms, UAE, Elementor design tools.

📋 **Full history:** see [CHANGELOG.md](CHANGELOG.md).

---

## 🔐 Security model

- **Off by default** — every write ability is disabled until an administrator enables it in **MCP > Settings**.
- **Per-tool capability checks** — each tool declares the WordPress capability it requires (`edit_posts`, `manage_options`, `manage_woocommerce`, …) and runs as the connected WordPress user, so an agent can only do what that account can do.
- **Per-object guards** — tools that take a post/page/media ID additionally enforce ownership (`edit_post` / `delete_post` / `read_post`) and post type, matching WordPress core's own REST checks.
- **Three auth methods** — OAuth 2.1 (one-click Claude Connector, off by default), WordPress Application Passwords, or a plugin-generated API key.
- **Audit Log** — every `tools/call` is stored in `wp_wsp_mcp_audit_log` with user, IP, outcome (success / denied / error), and duration. Nothing leaves your server. Auto-pruned after 90 days.

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
| Update / Delete / Upload Media *(from URL or base64)* | write |
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

### Rank Math SEO *(requires Rank Math plugin)*
| Ability | Access |
|---------|--------|
| Get Rank Math SEO Meta (title, description, focus keyword, score) | read |
| Update Rank Math SEO Meta | write |

### Elementor *(requires Elementor plugin)*
| Ability | Access |
|---------|--------|
| List Elementor Pages / Templates | read |
| Get Page Structure / Element Settings / Find Element | read |
| Update Element, Add Widget, Add Container / Section, Remove Element | write |
| Duplicate / Move Element, Copy Element Styles | write |
| Get / Update Active Kit (global colors, fonts, layout) | read / write |
| Get / Update Page Settings | read / write |
| Get Widget Schema / Breakpoints | read |
| Convert CSS to Elementor Settings | write |
| Regenerate CSS | write |

> 20 tools. `update-active-kit` and `regenerate-css` require `manage_options`; the rest require `edit_posts`. All writes run through `wsp_elementor_sanitize_settings()`.

### Ultimate Addons for Elementor *(requires UAE plugin)*
| Ability | Access |
|---------|--------|
| List UAE Widgets / Check Widget Usage | read |
| Activate / Deactivate / Bulk Toggle Widgets | write |
| List / Get Header, Footer & Blocks Templates | read |
| Create / Duplicate / Update / Trash / Restore Template | write |
| Add Section / Add Column / Move Element / Build Layout from JSON | write |
| Get UAE Settings / Theme Info / Extensions / Design Tokens | read |
| Update UAE Settings / Design Tokens | write |

> 45 tools, off by default. Writes require `edit_posts`, `publish_posts`, or `manage_options`.

### WooCommerce *(requires WooCommerce plugin)*
| Ability | Access |
|---------|--------|
| List / Get Products | read |
| Create Product / Create Variation / Update Product | write |
| List Orders / Update Order Status | read / write |
| Refund Order *(requires `manage_woocommerce`)* | write |
| Create / List Coupons *(requires `manage_woocommerce`)* | write / read |
| Create Order Note | write |
| List Customers *(requires `manage_woocommerce`)* | read |
| Sales Report / Low-Stock Alerts | read |
| Moderate Product Reviews | write |

### Advanced Custom Fields *(requires ACF plugin)*
| Ability | Access |
|---------|--------|
| List / Get Field Groups | read |
| Create / Update / Delete / Import Field Group | write |
| List / Get Fields | read |
| Create / Update / Delete / Duplicate Field, Force Sync | write |
| Get / Get-All / Get Field Object (values) | read |
| Update Deep *(dot-notation)* / Bulk Update / Delete Value | write |
| List Post Types / Taxonomies | read |
| Create Custom Post Type / Taxonomy *(ACF 6.1+)* | write |
| List / Create Options Page *(create needs ACF Pro)* | read / write |
| Get / Update Option Value | read / write |

> 27 tools. Value reads/writes accept a post/page ID, `user_<id>`, `term_<id>`, or `options` and enforce per-object capabilities. Structural changes require `manage_options`.

### Gravity Forms *(requires Gravity Forms plugin)*
| Ability | Access |
|---------|--------|
| List / Get Forms | read |
| Create / Update / Delete Form, Update Form Settings | write |
| List / Get Entries | read |
| Update / Delete Entry | write |
| Get / Create / Update / Delete Notifications | read / write |
| Get / Create / Update / Delete Confirmations | read / write |

> 18 tools. List Forms and Get Form are ON by default. Uses Gravity Forms' own capabilities.

### Contact Form 7 *(requires Contact Form 7 plugin)*
| Ability | Access |
|---------|--------|
| List / Get Forms | read |
| Create / Update / Delete Form | write |
| List / Get Entries *(requires Flamingo)* | read |
| Validate Form | read |
| Get Integrations (modules, reCAPTCHA status) | read |
| Moderate Entry (spam / unspam / trash / untrash) | write |

> 10 tools. List Forms and Get Form are ON by default. Uses CF7's own capabilities; `get-integrations` requires `manage_options`.

### WPForms *(requires WPForms Lite or Pro)*
| Ability | Access |
|---------|--------|
| List / Get Forms, Describe Schema, Get Form Stats | read |
| Create Form, Update Form Settings, Add / Update Field, Delete Form | write |
| List / Get / Delete Entries *(WPForms Pro)* | read / write |

> 12 tools. List Forms, Get Form, Describe Schema, and Get Form Stats are ON by default. Uses WPForms' own capabilities.

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
