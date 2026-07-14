# Abilities (Tool Registry)

This file is the **source of truth** for the abilities shown on the
[Abilities Directory](https://freewordpressmcp.com/abilities-directory) page (`abilities-directory.html`).

Each ability is one MCP tool the **WSP MCP – AI Agents Connector** plugin exposes to a connected AI agent.
The list mirrors the plugin registry at
`wsp-mcp-ai-agents-connector/includes/registry.php` in
[bilalnaseer/wsp-wordpress-mcp](https://github.com/bilalnaseer/wsp-wordpress-mcp).

> **Generated file — do not edit by hand.** Produced by `bin/generate-abilities-md.php`; CI regenerates it whenever `registry.php` changes.

**When this file changes, update the `ABILITIES` array in `abilities-directory.html` to match** (and bump the `lastmod` date for that page in `sitemap.xml`).

## Legend

- **Access** — `read` (safe, list/fetch only) or `write` (creates, updates, or deletes site data).
- **Default** — `on` means enabled out of the box; everything else is off until the user turns it on in the WordPress dashboard.
- **Requires** — core groups are always available; plugin groups appear only when the named plugin is active.

## Totals

- **70** total abilities
- **26** core (always available)
- **31** read · **39** write

---

## Core — always available

### Posts

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-posts` | Read Posts | List published blog posts (title, URL, date, excerpt, categories, tags). | read | on |
| `wsp/create-post` | Create Post | Create a new blog post (title, content, status, categories, tags, slug). | write | off |
| `wsp/update-post` | Update Post | Update an existing post by ID. | write | off |
| `wsp/delete-post` | Delete Post | Move a post to trash by ID. | write | off |

### Pages

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-pages` | Read Pages | List published pages (title, URL, parent, status). | read | on |
| `wsp/create-page` | Create Page | Create a new WordPress page (title, content, status, parent, slug). | write | off |
| `wsp/update-page` | Update Page | Update an existing page by ID. | write | off |
| `wsp/delete-page` | Delete Page | Move a page to trash by ID. | write | off |

### Taxonomy

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-categories` | Read Categories | List all post categories with IDs, slugs, and post counts. | read | on |
| `wsp/create-category` | Create Category | Create a new post category. | write | off |
| `wsp/get-tags` | Read Tags | List all post tags with IDs, slugs, and post counts. | read | on |
| `wsp/create-tag` | Create Tag | Create a new post tag. | write | off |

### Comments

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-comments` | Read Comments | List comments with author, status, and content snippet. | read | off |
| `wsp/approve-comment` | Approve Comment | Approve a pending comment by ID. | write | off |
| `wsp/delete-comment` | Delete Comment | Move a comment to trash by ID. | write | off |

### Media

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/list-media` | List Media | Browse and search the media library by type, keyword, or date. | read | off |
| `wsp/get-media` | Get Media | Retrieve the full metadata of a specific media file by ID. | read | off |
| `wsp/count-media` | Count Media | Get media library counts grouped by MIME type, plus a total. | read | off |
| `wsp/update-media` | Update Media | Update the title, alt text, caption, or description of a media file by ID. | write | off |
| `wsp/delete-media` | Delete Media | Permanently delete a media file from the media library by ID. | write | off |
| `wsp/upload-media` | Upload Media | Upload an image or file from a URL directly into the media library. | write | off |
| `wsp/upload-media-from-url` | Upload Media From URL | Pull an image from any web link straight into your media library. | write | off |

### Users

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-users` | Read Users | List users with display name, email, and role. | read | off |

### Search

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/search` | Search Content | Search posts and pages by keyword. | read | on |

### Site

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/get-site-info` | Read Site Info | Return site name, URL, tagline, WP version, and language. | read | on |
| `wsp/get-plugins` | Read Plugins | List all active plugins with name, version, and author. | read | off |

---

## Yoast SEO — requires the Yoast SEO plugin

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/yoast-get-seo` | Get Yoast SEO Meta | Get Yoast SEO title, meta description, and focus keyphrase for a post or page. | read | off |
| `wsp/yoast-update-seo` | Update Yoast SEO Meta | Update Yoast SEO title, meta description, and/or focus keyphrase for a post or page. | write | off |

---

## WooCommerce — requires the WooCommerce plugin

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/woo-get-products` | List WooCommerce Products | List store products with filtering and pagination. | read | off |
| `wsp/woo-get-product` | Get Single Product | Get single product details by ID. | read | off |
| `wsp/woo-create-product` | Create WooCommerce Product | Create a new simple or variable product in the store with auto-attributes. | write | off |
| `wsp/woo-create-variation` | Create Product Variation | Create a new product variation / variant. | write | off |
| `wsp/woo-update-product` | Update WooCommerce Product | Update an existing product details (prices, stock, description). | write | off |
| `wsp/woo-list-orders` | List WooCommerce Orders | List recent orders with status filtering. | read | off |
| `wsp/woo-update-order-status` | Update WooCommerce Order | Update the status of an order. | write | off |
| `wsp/woo-refund-order` | Refund WooCommerce Order | Create a full or partial refund for an order. | write | off |
| `wsp/woo-create-coupon` | Create WooCommerce Coupon | Create a new coupon code (percentage or fixed discount). | write | off |
| `wsp/woo-list-coupons` | List WooCommerce Coupons | List all active store coupons with usage stats. | read | off |
| `wsp/woo-create-order-note` | Create Order Note | Add a note to an existing order (internal or customer-facing). | write | off |
| `wsp/woo-list-customers` | List Customers | List registered customers with billing details. | read | off |
| `wsp/woo-report-sales` | Get Sales Report | Get sales, orders, net revenue, and average order value reports. | read | off |
| `wsp/woo-get-low-stock` | Get Low Stock Alerts | Inspect and list products running low on stock. | read | off |
| `wsp/woo-moderate-review` | Moderate Product Reviews | Approve, spam, trash, or reply to product reviews. | write | off |

---

## Advanced Custom Fields — requires the ACF plugin

| Ability ID | Name | Description | Access | Default |
|---|---|---|---|---|
| `wsp/acf-list-field-groups` | List Field Groups | List all registered field groups. | read | off |
| `wsp/acf-get-field-group` | Get Field Group | Get detailed setup of a specific field group. | read | off |
| `wsp/acf-create-field-group` | Create Field Group | Create a brand new custom field group configuration. | write | off |
| `wsp/acf-update-field-group` | Update Field Group Settings | Update existing custom field group rules/configurations. | write | off |
| `wsp/acf-delete-field-group` | Delete Field Group | Delete/trash a field group by its key. | write | off |
| `wsp/acf-import-field-groups` | Import Field Groups | Programmatically import field groups via raw JSON parameters. | write | off |
| `wsp/acf-list-fields` | List Fields inside Group | List all registered fields configs inside a specific field group. | read | off |
| `wsp/acf-get-field` | Get Field Config Details | Fetch direct key attributes and parameters for a custom field. | read | off |
| `wsp/acf-create-field` | Create Field Configuration | Register a new field inside an existing custom group. | write | off |
| `wsp/acf-update-field-config` | Update Field Configuration | Update schema configuration for a custom field. | write | off |
| `wsp/acf-delete-field` | Delete Field Config | Deletes config parameters for a custom field. | write | off |
| `wsp/acf-duplicate-field` | Duplicate Field Config | Duplicate an existing field configuration key. | write | off |
| `wsp/acf-sync-fields` | Force Sync Fields JSON | Force reload and sync schema settings dynamically. | write | off |
| `wsp/acf-get-value-deep` | Get Field Value Deep | Dot-notation deep access to variables (e.g. repeater.0.subfield). | read | off |
| `wsp/acf-update-value-deep` | Update Field Value Deep | Update specific deep metadata locations with dot-notation. | write | off |
| `wsp/acf-delete-value` | Delete Field Value | Delete specific key field metadata value. | write | off |
| `wsp/acf-get-all-values` | Get All Fields Values | Get all raw field values mapped on any object. | read | off |
| `wsp/acf-bulk-update-values` | Bulk Update Values | Bulk update array values instantly. | write | off |
| `wsp/acf-get-field-object` | Get Value & Config Object | Return both config object and mapped values. | read | off |
| `wsp/acf-list-post-types` | List Registered Post Types | List post types with active register mappings. | read | off |
| `wsp/acf-create-post-type` | Create Custom Post Type | Programmatically register brand new WordPress Post Type. | write | off |
| `wsp/acf-list-taxonomies` | List Registered Taxonomies | List taxonomies structure. | read | off |
| `wsp/acf-create-taxonomy` | Create Custom Taxonomy | Programmatically register brand new WordPress taxonomy. | write | off |
| `wsp/acf-list-options-pages` | List ACF Options Pages | List registered global options views. | read | off |
| `wsp/acf-create-options-page` | Create Options Page | Programmatically register global ACF Options Page. | write | off |
| `wsp/acf-get-option-value` | Get Option Value | Read global option value metadata. | read | off |
| `wsp/acf-update-option-value` | Update Option Value | Write option values globally. | write | off |
