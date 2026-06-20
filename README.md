# WSP WordPress MCP — Connect AI Agents to WordPress

> **By [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Agency**

[![Version](https://img.shields.io/badge/Version-2.0.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://youtube.com/websensepro)
[![License](https://img.shields.io/badge/License-GPL%202.0-green?style=for-the-badge)](LICENSE)

---

## 🎬 Watch the Tutorial

[![WSP WordPress MCP — Full Tutorial](https://img.youtube.com/vi/nHE6PcA5pfc/maxresdefault.jpg)](https://youtu.be/nHE6PcA5pfc)

---

## ✨ What's New in v2.0.0

- 🚀 **Built-in Native MCP Server** — the plugin now ships its own MCP server at `/wp-json/wsp-mcp/v1/mcp`. **No companion plugin, WordPress MCP Adapter, or Node.js bridge required.**
- 🔌 **MCP > Connection Page** — endpoint URL, API key (with one-click regenerate), and ready-to-paste config tabs for **Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw** — the API key is pre-filled for you.
- 🔐 **Flexible Auth** — connect with a WordPress Application Password **or** the plugin's API key (`Authorization: Bearer`), with per-tool capability enforcement.
- 🗂️ **Cleaner Settings** — ability groups are now collapsible accordions with live enabled/total counts.
- ♻️ **Dual-Mode** — existing Abilities-API / MCP-Adapter connections keep working untouched.

## Previous Releases

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

---

## 🚀 Quick Start

**Prerequisites:** WordPress 6.2+, PHP 7.4+ — **that's it.** No companion plugin, no MCP Adapter, no Node.js for natively-supported clients (Cursor, Codex, Antigravity). Claude Desktop & OpenClaw use the `mcp-remote` bridge, which needs Node.js 18+.

1. Install & activate this plugin
2. Go to **MCP > Settings** in wp-admin and enable the abilities you need
3. Go to **MCP > Connection** and pick your client tab (Claude Desktop, Cursor, Codex, Antigravity, or OpenClaw)
4. Copy the snippet — the endpoint URL and API key are already filled in — and paste it into your client's config
5. Reconnect / restart the client and start prompting your AI agent

> **Upgrading from v1.x?** Your existing MCP-Adapter connection keeps working in dual-mode. The legacy **MCP > Config Files** page is still available, but the native **MCP > Connection** page is now the recommended path.

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
