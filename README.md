# WSP MCP — Free MCP Plugin for WordPress

**Connect Claude, ChatGPT, Cursor & any AI agent to WordPress, and manage your site by chat.**

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.9.7-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-150K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

WSP MCP is a **free MCP plugin for WordPress**. Install it, connect your AI app, and just ask:

- *"Write a blog post about our summer sale and save it as a draft."*
- *"Find every page missing a meta description and suggest one."*
- *"Show me this week's WooCommerce orders and mark the shipped ones as completed."*
- *"Add a Contact link to the main menu."*

**Why WSP MCP?**

- 💸 **100% free** — every feature and all 200+ tools, no paid version.
- 🔌 **Everything built in** — no companion plugin, MCP Adapter, or account needed.
- 🤖 **Works with your AI app** — Claude (web, desktop, mobile), ChatGPT, Cursor, Codex, Google Antigravity, OpenClaw, OpenCode, and any other MCP client.
- 🛡️ **Safe by default** — every tool has its own on/off switch, anything that changes your site is off until you turn it on, and the AI can only do what its WordPress user is allowed to do.
- 📋 **See everything the AI did** — a built-in Audit Log and usage dashboard, stored in your own database.

> **What is MCP?** The Model Context Protocol is the standard way AI assistants connect to other apps. Once your site has an MCP plugin, any AI app that supports MCP can read and update your site — with your permission.

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

> **ChatGPT:** ChatGPT connects through the same OAuth sign-in as Claude Connectors — enable the OAuth server on **MCP > Connection**, then add your site's MCP URL as a connector in ChatGPT.

### Connection guides by client

| Client | Guide |
|--------|-------|
| Claude (claude.ai / Desktop / mobile) | [Full tutorial](https://youtu.be/kD2FSvL7EE0) |
| OpenClaw | [Video](https://youtu.be/GLyLzxVOxm4) |
| Google Antigravity | [Video](https://youtu.be/2gRIRcqqOpo) |
| Codex | [Video](https://youtu.be/hxhjs3IUYQE) |
| All tutorials & abilities directory | [wspmcp.com](https://wspmcp.com/) |

---

## ✨ What's New in v2.9.7

- 💬 **ChatGPT tab** on **MCP > Connection** with step-by-step instructions and a walkthrough GIF; the Claude Connectors tab now has a GIF too.
- 🌐 **OAuth sign-in works on more hosts** — discovery is also served from a non-`.well-known` address, fixing connection failures on hosts that block `/.well-known/` (e.g. SiteGround).

## v2.9.6

- 🔒 **Security: hardened authorization checks** on post listing, Elementor, Ultimate Addons for Elementor and media-upload tools. Unpublished posts are only listed to users who can edit them, and write tools now verify the caller may act on the specific post. **Update recommended.**
- 🔁 **Sessions survive OAuth token refresh**, rate limiting is now per user, and rejected requests appear in the Audit Log.
- 🛡️ **Safer theme overwrite** — `wsp_upload_theme` with `overwrite` merges into the installed theme, backs up the old folder first, and checks PHP before swapping.

## v2.9.5

- 🧭 **Site Context** — new **MCP > Context** page: write `AGENTS.md` (how your site is built, rules for agents) and `CHANGELOG.md` (what changed and why), switch on **Enable Site Context** (off by default), and every connected agent reads them first instead of exploring the whole site — fewer tokens, less time. Delivered as server instructions on connect, via the `wsp_get_site_context` tool, and as MCP resources. Reconnect the client after editing; don't store secrets in these documents.
- 🛒 **WooCommerce store management** — 34 new tools: delete products, variations, coupons, categories and tags (trash by default); manage product categories, tags, global attributes and terms; read and update store settings, tax rates, shipping zones and methods, and payment gateways. Products can now be created and updated with categories, tags and attributes. Secrets (keys, tokens, passwords) are always masked.
- 🔌 **Plugin management** — install a plugin from WordPress.org or an https .zip URL, update it, or delete it (must be deactivated first). Administrators only.
- All new tools are **off by default** — enable them in MCP > Settings.

## v2.9.4

- 🎨 **Site Editor tool group** — six tools for block themes: read and update Global Styles (colors, typography, spacing, style variations) and list, read, create and update block templates and template parts.
- 🧱 **Widgets & Sidebars tool group** — eight tools for classic themes: list widget areas and types, and read, create, update, move and delete widgets.
- 🩺 **Site Health, Cron & Error Log tool group** — six diagnostics tools: run Site Health checks, list/inspect/run/unschedule WP-Cron events, and read recent PHP/WordPress error-log lines (secrets redacted).
- 📦 **Upload / Install Theme** — install a theme your AI generated (or a theme .zip) and optionally activate it. Administrators only.
- All new tools are **off by default** — enable them in MCP > Settings. The plugin now ships **200+ tools**.

## v2.9.3

- 🗂️ **Custom Post Types tool group** — five new tools: list your public custom post types, then list, create, update, and trash their items. Each item is checked against the post type's own permissions (a Contributor can't edit others' items or publish; an Author can't touch types like products that need extra rights). **Off by default.** Contributed by [@dulaj44](https://github.com/dulaj44) in [#43](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/43).
- 👋 **About Us page** under MCP, plus **Settings | Connection | About Us** links on the Plugins screen.
- ✏️ **Plain-language name, description and readme** — "WSP MCP - Free MCP Plugin for WordPress: Connect Claude, ChatGPT & AI Agents".

## v2.9.2

- Minor updates.

## v2.9.1

- 👥 **Create / Update Users** — add new users (password auto-generated if you don't supply one, role defaults to `subscriber`) and edit email, display name, role, or password. Require `create_users` / `edit_users`; **off by default**.
- ⚙️ **Update Site Info & Permalinks** — change the site title, tagline, and admin email, and set the permalink structure (e.g. `/%postname%/`). Require `manage_options`; **off by default**.
- 🔌 **Activate / Deactivate Plugins** — toggle any installed plugin by its file path (e.g. `akismet/akismet.php`). This plugin refuses to deactivate itself so the MCP connection can't cut itself off. Require `activate_plugins`; **off by default**.
- 🎨 **Themes tool group** — list installed themes and switch the active theme. Require `switch_themes`; **off by default**.

Contributed by [@dulaj44](https://github.com/dulaj44) in [#42](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/42).

## v2.9.0

- 🧭 **Navigation Menus tool group** — nine new tools to list menus and their items, create and delete menus, add / update / remove menu items (custom links, posts, pages, categories), list your theme's menu locations, and assign or unassign a menu to a location. Require `edit_theme_options`; **off by default**. Contributed by [@dulaj44](https://github.com/dulaj44) in [#41](https://github.com/bilalnaseer/wsp-wordpress-mcp/pull/41).
- 📄 **Read Post tool** — fetch a single post by ID in **any** status (draft, pending, private, trash) with its full content, so an agent can review a draft before updating it. Enforces per-post read permission; **off by default**. Closes [#38](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues/38).
- 🐛 **Audit Log accuracy** — permission-denied results from the new Read Post tool are now logged as denied, not success.
- 🐛 **Add Menu Item validation** — an `object_id` whose post type doesn't match the requested `type` is now rejected instead of silently stored.

**Recent releases:** v2.9.7 — ChatGPT connection guide + OAuth discovery fix for hosts blocking /.well-known/ · v2.9.6 — security hardening of authorization checks · v2.9.5 — Site Context + WooCommerce store management · v2.9.0 — Navigation Menus tool group + Read Post tool · v2.8.0 — one-click Claude Connector sign-in (OAuth 2.1) + Analytics dashboard · v2.7.1 — object-level authorization on write tools (Patchstack) · v2.7.0 — Audit Log · v2.6.x — WPForms, Contact Form 7, Gravity Forms, UAE, Elementor design tools.

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
| Create / Update Users | write |
| Search Content | read |
| Read Site Info & Active Plugins | read |
| Update Site Info (title, tagline, admin email) / Permalink Structure | write |
| Activate / Deactivate Plugins | write |
| Read Themes | read |
| Switch Theme | write |
| Read Menus / Menu Items / Menu Locations | read |
| Create / Delete Menu, Add / Update / Delete Menu Item, Assign Location | write |

### Custom Post Types *(any public custom post type — books, events, portfolio, products…)*
| Ability | Access |
|---------|--------|
| Read Post Types / Read Items | read |
| Create / Update / Trash Item | write |

> 5 tools, off by default. Each item is checked against its post type's own permissions, so the AI can't edit other people's items or publish unless its user could do that in wp-admin.

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

Built by [WebSensePro](https://websensepro.com) — an AI-powered digital media agency delivering web development, WordPress, Shopify, SEO and AI automation for growing businesses, with offices in Denver, CO, Queens Village, NY and Karachi, Pakistan.

**1,200+** projects delivered · **850+** websites launched · **500+** clients worldwide · **120+** AI automations deployed · **10+** years in digital services

- 🏆 [Official Shopify Partner](https://www.shopify.com/partners/directory/partner/websensepro1)
- 🎥 [150K+ YouTube Subscribers](https://m.youtube.com/websensepro)
- 🤖 [Official n8n Creator](https://n8n.io/creators/websensepro/)
- 📧 [info@websensepro.com](mailto:info@websensepro.com)

---

<div align="center">

[⭐ Star this repo](https://github.com/bilalnaseer/wsp-wordpress-mcp) · [🍴 Fork it](https://github.com/bilalnaseer/wsp-wordpress-mcp/fork) · [🐛 Report a bug](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues)

</div>
