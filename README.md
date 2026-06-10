# WSP WordPress MCP — Connect AI Coding Agents to WordPress

> **WordPress MCP server by [WebSensePro](https://websensepro.com) — Official Shopify Partner & WordPress Development Agency**

[![WordPress MCP](https://img.shields.io/badge/WordPress-MCP%20Server-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://github.com/bilalnaseer/wsp-wordpress-mcp)
[![WebSensePro](https://img.shields.io/badge/Built%20by-WebSensePro-FF6B35?style=for-the-badge)](https://websensepro.com)
[![YouTube](https://img.shields.io/badge/YouTube-140K%2B%20Subscribers-FF0000?style=for-the-badge&logo=youtube&logoColor=white)](https://m.youtube.com/websensepro)
[![Version](https://img.shields.io/badge/Version-1.1.0-blue?style=for-the-badge)](https://github.com/bilalnaseer/wsp-wordpress-mcp/releases)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)

---

## 🤖 What is WordPress MCP?

**WordPress MCP** (Model Context Protocol) is a standardized bridge that connects AI coding agents — like **Claude**, **Cursor**, **GitHub Copilot**, and **ChatGPT** — directly to your WordPress site. This allows AI assistants to read, manage, and manipulate your WordPress content, settings, and data through natural language commands.

The **WSP WordPress MCP** server is built and maintained by [WebSensePro](https://websensepro.com). We've packaged our real-world WordPress development experience into this open-source WordPress MCP integration.

---

## ✨ Features

- 🔌 **Plug-and-play WordPress MCP server** — connects your WordPress site to any MCP-compatible AI agent
- 📝 **Content Management** — create, update, and publish posts/pages via AI commands
- ⚙️ **Site Administration** — manage settings, plugins, and themes through natural language
- 🛡️ **Secure by Design** — authentication and permission controls built in
- 🔄 **REST API Bridge** — translates MCP tool calls into WordPress REST API requests
- 🤝 **Multi-Agent Support** — works with Claude Desktop, Cursor, Codex, and Google Antigravity
- 🗂️ **Built-in Config Generator** — wp-admin Config Files page auto-generates ready-to-paste configs for Claude Desktop, Cursor, Codex, and Antigravity
- 🔒 **Granular Ability Controls** — enable/disable each read or write ability individually from wp-admin
- 🚀 **Agency-Grade** — built by developers who run WordPress at scale for clients worldwide

---

## 🧠 Why Use a WordPress MCP Server?

Traditional WordPress development requires manually switching between your IDE, wp-admin, and your AI assistant. With a **WordPress MCP server**, your AI coding agent can:

- Query and update posts, pages, and custom post types directly
- Install or configure plugins and themes via conversation
- Pull site health data, user info, and environment details on demand
- Automate repetitive content workflows without leaving your editor
- Enable true AI-assisted WordPress development end-to-end

The Model Context Protocol (MCP) was designed specifically to standardize how AI agents connect to external systems — and WordPress MCP makes your site a first-class citizen in that ecosystem.

---

## 🎬 Video Demo

[![WSP WordPress MCP — Video Demo](https://img.youtube.com/vi/nHE6PcA5pfc/maxresdefault.jpg)](https://youtu.be/nHE6PcA5pfc)

---

## 🚀 Quick Start

### Prerequisites

- WordPress site (self-hosted, v6.0+)
- **[WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)** — must be installed first 
- Node.js 18+ (for the MCP client/proxy)
- An MCP-compatible AI client (Claude Desktop, Cursor, etc.)

### Installation

**Step 1: Install and Activate the WordPress Plugin**

> **Important:** WordPress needs the plugin PHP file at the correct level inside the ZIP.
>
> - **Easiest:** Upload `wsp-wordpress-mcp.zip` from this repo (Plugins → Add New → Upload Plugin).
> - **If you downloaded from GitHub:** Use the ZIP that contains `wsp-wordpress-mcp.php` at the root, **or** zip only the inner `wsp-wordpress-mcp` folder — not the whole `wsp-wordpress-mcp-main` folder with README only.
>
> If you see *"No valid plugins were found"*, the ZIP structure is wrong. Use `wsp-wordpress-mcp.zip` instead.

**Step 2: Enable the MCP Server**

Navigate to **MCP > Settings** in wp-admin and choose the abilities.

**Step 3: Configure Your AI Client**

Add the following to your MCP client configuration (e.g., `claude_desktop_config.json` for Claude Desktop):

```json
{
    "mcpServers": {
        "wsp-wordpress-mcp": {
            "command": "npx",
            "args": [
                "-y",
                "@automattic/mcp-wordpress-remote@latest"
            ],
            "env": {
                "WP_API_URL": "https://yourwebsite.com/wp-json/mcp/mcp-adapter-default-server",
                "WP_API_USERNAME": "your-wp-user-name",
                "WP_API_PASSWORD": "replace-with-your-application-password"
            }
        }
    }
}
```

**Step 4: Start Prompting**

Open your AI assistant and start issuing WordPress commands in plain English:

> *"Show me all draft posts from the last 7 days"*
> *"Publish the post titled 'Summer Sale' and set the featured image"*
> *"List all active plugins and their versions"*

---

## 🛠️ Available MCP Tools

The WSP WordPress MCP server exposes the following tools to connected AI agents:

### Content Tools
| Tool | Description |
|------|-------------|
| `get_posts` | Retrieve posts with filters (status, category, date) |
| `create_post` | Create a new post or page |
| `update_post` | Update post content, status, or metadata |
| `delete_post` | Move a post to trash |
| `get_media` | List media library items |

### Administration Tools
| Tool | Description |
|------|-------------|
| `get_site_info` | Retrieve site name, URL, WordPress version |
| `get_plugins` | List installed and active plugins |
| `get_users` | Retrieve user list with roles |
| `get_options` | Read WordPress site options |

---

## 🤝 Compatible AI Agents

This **WordPress MCP** server has been tested with:

| AI Agent | Status |
|----------|--------|
| Claude (Anthropic) — Desktop & Code | ✅ Fully Supported |
| Cursor | ✅ Fully Supported |
| Codex (OpenAI) | ✅ Fully Supported |
| Google Antigravity | ✅ Fully Supported |

---

## 🏢 About WebSensePro

**WSP WordPress MCP** is built and maintained by [WebSensePro](https://websensepro.com) — a digital agency specializing in WordPress development, Shopify stores, and AI-powered automation.

- 🏆 **Official Shopify Partner** — [shopify.com/partners/websensepro1](https://www.shopify.com/partners/directory/partner/websensepro1)
- 🎥 **140K+ YouTube Subscribers** — [youtube.com/websensepro](https://m.youtube.com/websensepro)
- 🤖 **Official n8n Creator** — [n8n.io/creators/websensepro](https://n8n.io/creators/websensepro/)

We build WordPress solutions for SMBs across the US and globally, from Queens, NY to Karachi.

---

## 💬 Support & Contact

Need help setting up your WordPress MCP server? Reach out:

| Channel | Link |
|---------|------|
| 🌐 Website | [websensepro.com](https://websensepro.com) |
| 📧 Email | [info@websensepro.com](mailto:info@websensepro.com) |
| 🎥 YouTube | [m.youtube.com/websensepro](https://m.youtube.com/websensepro) 

---

## 🤲 Contributing

Contributions are welcome! If you find a bug or want to add a new MCP tool:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-mcp-tool`)
3. Commit your changes (`git commit -m 'Add: new MCP tool for XYZ'`)
4. Push to the branch (`git push origin feature/new-mcp-tool`)
5. Open a Pull Request

Please follow WordPress coding standards and include tool descriptions compatible with the MCP specification.

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

## 🔑 Keywords

`wordpress mcp` · `wordpress mcp server` · `model context protocol wordpress` · `ai wordpress integration` · `wordpress ai agent` · `claude wordpress` · `cursor wordpress` · `wordpress automation` · `wp mcp` · `websensepro`

---

<div align="center">

**Built with ❤️ by [WebSensePro](https://websensepro.com) **

*Official Shopify Partner · WordPress Development · AI Automation*

[⭐ Star this repo](https://github.com/bilalnaseer/wsp-wordpress-mcp) · [🍴 Fork it](https://github.com/bilalnaseer/wsp-wordpress-mcp/fork) · [🐛 Report a bug](https://github.com/bilalnaseer/wsp-wordpress-mcp/issues)

</div>
