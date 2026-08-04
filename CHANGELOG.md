# Changelog — WSP MCP - AI Agents Connector

> **AI Agents:** Read `AGENTS.md` → `CHANGELOG.md` → `HISTORY.md` in that order before touching any source file.
> After every code change you **must** update `AGENTS.md` (if architecture/tools/hooks changed) and add an entry here.

All notable changes to this plugin are listed here. Ordered newest-first.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

### Added — GeoDirectory listing tools (`includes/abilities/geodirectory.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- Added three tools, registered only while GeoDirectory is active and all OFF by default: `wsp_geodirectory_search_listings`, `wsp_geodirectory_import_listings`, and `wsp_geodirectory_update_listing`.
- Listing writes accept only `title`, `content`, `post_tags`, `post_category`, `street`, `country`, `region`, `city`, `zip`, `latitude`, `longitude`, `website`, `business_hours`, six named amenity flags, `slug`, `status`, and `featured_media`. Unknown fields fail validation; author, dates, passwords, comments, arbitrary meta, and arbitrary REST execution are not exposed.
- Creates and updates dispatch internal `WP_REST_Request` objects to GeoDirectory's registered `geodir/v2` collection/item controller. The integration never writes GeoDirectory detail tables or post meta directly.
- Imports are capped at 50 records, default to `draft` and the `Dog Park` tag, support `dry_run`, continue after row errors, and return deterministic `created`, `would_create`, `skipped_duplicate`, or `error` results plus summary counts. Duplicate checks cover editable non-trash listings and earlier rows in the same dry-run batch, and report normalized title/city/region, street/city/region, and conservative coordinate match reasons.
- Existing WSP MCP authentication, sessions, endpoint, and API-key behavior are unchanged. All three tools require `edit_posts`; publish transitions also require the GeoDirectory CPT's `publish_posts` capability, and updates enforce `edit_post` on the target object.
- Added WordPress Playground integration coverage against GeoDirectory 2.8.169 for validation, allowlisting, dry-run and row-level results, duplicate detection, REST-backed create/search/update behavior, and capability checks.

### Fixed — Website sync dropped Contact Form 7 + WPForms groups (`bin/lib-abilities.php`)
- **The public Abilities Directory on freewordpressmcp.com was missing every Contact Form 7 (10) and WPForms (12) tool**, even though both groups have shipped in `registry.php` since v2.6.6. Root cause: the website-sync generator loads `registry.php` in a stub environment (`bin/lib-abilities.php`), and that stub force-activates every plugin-gated group by defining its `wsp_*_is_active()` check to return `true`. Stubs existed for Yoast, Rank Math, Elementor, ACF, UAE, Gravity Forms, and WooCommerce — but **not** for `wsp_cf7_is_active()` / `wsp_wpforms_is_active()`. Without them, `registry.php`'s own real checks ran (`class_exists('WPCF7_ContactForm')` / `function_exists('wpforms')`), both returned false in the PHP-CLI generator, and the two groups were silently skipped. This is why the automated sync PR only ever produced a trivial diff and never surfaced the 2.6.6 form tools.
- **Fix:** added `wsp_cf7_is_active()` and `wsp_wpforms_is_active()` stubs (return `true`) to `bin/lib-abilities.php`, alongside the existing ones. The generator now emits all groups; `patch-website.php` regenerates both the `ABILITIES` array and the `GROUPS` map, so the site picks up CF7 + WPForms automatically on the next `main` push. Dev-tooling only — no plugin runtime code, tool, or shipped-zip behavior changed (the plugin already registered these tools correctly at runtime).
- **Guardrail:** documented in `AGENTS.md` ("Website sync automation") that any new plugin-gated group added to `registry.php` MUST get a matching active-check stub in `bin/lib-abilities.php`, or it will be dropped from the site.

## [2.6.6] — 2026-07-27

### Added — Direct (base64) file upload for media (`includes/abilities/media.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **`wsp_upload_media` now accepts base64 file content**, so an MCP client can upload a file attached to the chat **directly** into the media library — no public URL required. Fixes GitHub issue #17 ("still can't upload it directly"). Previously the only path was `url`, forcing users to host the image somewhere (e.g. Google Drive) first.
  - New optional inputs on `wsp_upload_media`: `data` (base64 string; a `data:<mime>;base64,` prefix is accepted and stripped) and `mime_type` (used to infer the extension when `data` has no data-URI prefix and `filename` has no extension). `url` is now optional — pass **either** `data` **or** `url`; `data` wins if both are present.
  - New callback `wsp_execute_upload_media_from_data()` in `media.php`: normalizes URL-safe/whitespaced base64, `base64_decode(..., true)` with strict validation, resolves a safe filename with an allowed image extension, writes the bytes to a `wp_tempnam()` temp file, and sideloads through `media_handle_sideload()` (same `upload_mimes` / `wp_check_filetype_and_ext` filters as the URL uploader). `wsp_execute_upload_media()` is no longer a thin wrapper — it routes to the base64 path when `data` is present, otherwise to `wsp_execute_upload_media_from_url()`.
  - **Security unchanged:** still requires `upload_files`; only image types (jpg, jpeg, png, gif, webp) are accepted; temp files are cleaned up on failure. No other tool, callback, or file behavior was modified.

## [2.6.5] — 2026-07-21

### Added — Elementor Advanced Design Tools (`includes/abilities/elementor.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **11 new Elementor design tools** for visual mockup replication and high-fidelity design workflows. All OFF by default, toggled from **MCP > Settings** under the "Elementor" group:
  - `get-active-kit` — reads global fonts, color palette, container width, and layout from the active Elementor kit.
  - `update-active-kit` — updates system colors, container width, and spacing in the active kit.
  - `regenerate-css` — clears and regenerates Elementor CSS cache for all Elementor-built posts.
  - `get-widget-schema` — queries the Elementor controls manager for a widget type; returns all control keys (margins, padding, background, typography, border) organized by tab.
  - `duplicate-element` — clones a widget or container with recursive 8-char hex ID reassignment via `wsp_elementor_clone_and_reid()` to prevent collisions.
  - `move-element` — removes an element from its current position and inserts it into a new parent or index position.
  - `convert-css` — parses CSS key-value rules (`padding`, `margin`, `border-radius`, `background-color`, `font-size`, `text-align`, etc.) into their exact Elementor settings counterparts.
  - `get-page-settings` / `update-page-settings` — read and update page-level `_elementor_page_settings` meta (template, hide_title, content_width, background).
  - `copy-styles` — copies settings from a source element ID to a destination element ID (with optional merge mode).
  - `get-breakpoints` — reads responsive viewport breakpoints (desktop 1025+, tablet 768-1024, mobile 0-767) from the active kit.

### Security
- All write tools run settings through `wsp_elementor_sanitize_settings()` (blocks `custom_css`, `_attributes`, `custom_attributes`, `__dynamic__` keys).
- `update-active-kit` and `regenerate-css` require `manage_options`; all other tools require `edit_posts`.

---

## [2.6.4] — 2026-07-21

### Added — WPForms suite (`includes/abilities/wpforms.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **12 new tools** for the WPForms integration (Lite and Pro), all write tools OFF by default and toggled from **MCP > Settings** under the "WPForms" group. Only registered when WPForms is active (`function_exists('wpforms') || class_exists('WPForms')`):
  - **Forms:** list (ON), get (ON), describe-schema (ON), get-form-stats (ON), create, update-form-settings, add-field, update-field, delete (trash or permanent). Capabilities: `wpforms_view_forms`, `wpforms_edit_forms`.
  - **Entries (Pro only):** list, get, delete (trash or permanent) — require `wsp_wpforms_pro_is_active()` (`wpforms()->is_pro()`). Lite users receive a descriptive error. Capabilities: `wpforms_view_entries`, `wpforms_edit_entries`.
- New helpers: `wsp_wpforms_is_active()`, `wsp_wpforms_pro_is_active()`, `wsp_wpforms_get_form_data()`, `wsp_wpforms_save_form_data()`, `wsp_wpforms_get_next_field_id()`, `wsp_wpforms_field_types()`.
- **Form data model:** WPForms stores forms as the `wpforms` custom post type; `post_content` is a JSON object with `fields` (array keyed by string IDs), `settings`, and `payments`. Field IDs are auto-assigned incrementally starting from 0.
- **Schema description:** `describe-schema` returns 16 supported field types (text, email, select, radio, checkbox, number, phone, file-upload, etc.) with metadata on choice support and editable attributes.
- `create-form` auto-generates a default notification targeting `{admin_email}` with `{all_fields}` body.
- All callbacks gate on `function_exists('wpforms')` and return descriptive `WP_Error` on inactive plugin.

### Security
- All text strings sanitized with `sanitize_text_field`/`sanitize_textarea_field`/`wp_kses_post`; field choices array members sanitized individually; JSON encoding uses `wp_json_encode()` + `wp_slash()`.
- Form delete gates on `wpforms_edit_forms`; entry tools require `wpforms_view_entries` / `wpforms_edit_entries`.

---

## [2.6.3] — 2026-07-21

### Added — Contact Form 7 suite (`includes/abilities/cf7.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **10 new tools** for the Contact Form 7 integration, all write tools OFF by default and toggled from **MCP > Settings** under the "Contact Form 7" group. Only registered when CF7 is active (`class_exists('WPCF7_ContactForm')`):
  - **Forms:** list (ON by default), get (ON by default), create, update, delete (trash or permanent). Capabilities: `wpcf7_edit_contact_forms`, `wpcf7_delete_contact_forms`.
  - **Entries (Flamingo):** list, get — require `class_exists('Flamingo_Inbound_Message')` as CF7 does not store entries natively. Capability: `wpcf7_edit_contact_forms`.
  - **Validation:** `validate-form` runs the built-in `WPCF7_ConfigValidator` on a form ID to catch email template and syntax errors. Capability: `wpcf7_edit_contact_forms`.
  - **Integrations:** `get-integrations` reads active integration modules and reCAPTCHA key status from the global `wpcf7` option. Capability: `manage_options`.
  - **Moderation (Flamingo):** `moderate-entry` marks a submission as spam, unspam, trash, or untrash. Capability: `wpcf7_edit_contact_forms`.
- New helper `wsp_cf7_is_active()` defined in both `registry.php` (defensive forward-declaration) and `cf7.php`; `wsp_cf7_flamingo_is_active()` also in `cf7.php`.
- `get-form` returns full form structure including scanned form tags, mail config, messages, and additional settings.
- All callbacks gate on `class_exists('WPCF7_ContactForm')` and return descriptive `WP_Error` on inactive plugin; Flamingo-dependent tools return a clear error when Flamingo is missing.

### Security
- All form strings sanitized with `sanitize_text_field`/`wp_kses_post`/`sanitize_textarea_field`; form IDs and entry IDs cast to `int`; `moderate_entry` action validated against `spam | unspam | trash | untrash` enum.
- `get-integrations` requires `manage_options` (exposes reCAPTCHA key status); all entry tools require `wpcf7_edit_contact_forms`.

---
## [2.6.2] — 2026-07-20

### Changed — Gravity Forms documentation & version bump
- Documented the complete **18-tool** Gravity Forms suite across `README.md`, `AGENTS.md`, and `readme.txt` — the v2.6.1 README under-reported the suite as "11 tools" and omitted the notification, confirmation, and form-settings write tools.
- Corrected the capability name in the docs from `gravityforms_create_forms` (plural, incorrect) to `gravityforms_create_form` (singular — the actual Gravity Forms capability) to match the registered tools.
- Bumped `Version` header and `WSP_MCP_VERSION` to `2.6.2`.

_No behavioral code changes — the 18 Gravity Forms tools shipped in 2.6.1; this release only corrects and completes the documentation._

---

## [2.6.1] — 2026-07-20

### Added — Gravity Forms suite (`includes/abilities/gravityforms.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **18 new tools** for the Gravity Forms integration, all write tools OFF by default and toggled from **MCP > Settings** under the "Gravity Forms" group (icon: 📋). Only registered when Gravity Forms is active (`class_exists('GFAPI') || class_exists('GFCommon')`):
  - **Forms:** list (ON by default), get (ON by default), create, update, delete, update-form-settings. Capabilities: `gravityforms_edit_forms`, `gravityforms_create_form`, `gravityforms_delete_forms`.
  - **Entries:** list, get, update (status, read/starred flags, field values), delete (trash or permanent). Capabilities: `gravityforms_view_entries`, `gravityforms_edit_entries`, `gravityforms_delete_entries`.
  - **Notifications:** create, update, delete (in addition to the existing get). Capability: `gravityforms_edit_forms`.
  - **Confirmations:** create, update, delete (in addition to the existing get). Capability: `gravityforms_edit_forms`.
  - **Settings:** `update-form-settings` handles label placement, restrictions, scheduling, honeypot, CSS class, save & continue, and require-login per form. Capability: `gravityforms_edit_forms`.
- New helper `wsp_gravity_is_active()` defined in both `registry.php` (defensive forward-declaration) and `gravityforms.php`; used consistently across registry, tool registration, and execution guards.
- `get_entry` resolves field labels from the form definition so responses include human-readable names alongside raw field IDs.
- All callbacks gate on `class_exists('GFAPI')` and return `WP_Error` on inactive plugin; inputs sanitized with `sanitize_text_field`/`intval`/`sanitize_textarea_field`/`wp_kses_post`.

### Security
- Strict Gravity Forms capability checks on every tool; entry status validated against `active`, `spam`, `trash` enum.

---

## [2.6.0] — 2026-07-20

### Added — Ultimate Addons for Elementor (UAE) suite (`includes/abilities/uae.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- **45 new tools** for the Ultimate Addons for Elementor integration, all OFF by default and toggled from **MCP > Settings** under the "Ultimate Addons Elementor" group:
  - **Widgets:** activate, deactivate, bulk toggle, check usage, and list UAE widgets.
  - **Templates:** create, duplicate, trash, restore, and update Header / Footer / Blocks templates.
  - **Builder / Engine:** manipulate Elementor structures — add sections, add columns, move elements, and build layouts from JSON.
  - **Settings:** get/update UAE plugin settings, theme info, extensions, and design-system tokens.

### Fixed
- `wsp_uae_builder_add_column` silently created a `container` instead of a `column`. The type validation in `wsp_execute_elementor_add_container()` (`includes/abilities/elementor.php`) only accepted `container` and `section`, so the `column` type set by the UAE wrapper was always overridden. Added `column` to the valid-type list.

### Security
- All string inputs sanitized with `wp_kses_post()`; strict per-tool capability checks (`edit_posts`, `publish_posts`, `manage_options`).

---

## [2.5.0] — 2026-07-14

### Added — Media library tool suite (`includes/abilities/media.php`, `includes/tools/native-tools.php`, `includes/registry.php`)
- Expanded the single read-only media tool into a full suite of seven tools, all OFF by default and toggled from **MCP > Settings**:
  - `wsp_list_media` (`wsp/list-media`, read) — browse and search the library by `type` (MIME), `search` keyword, `year`/`month`, with `per_page`/`page` pagination.
  - `wsp_get_media` (`wsp/get-media`, read) — **repurposed** to return the full metadata of a single attachment by `id` (title, URL, MIME, date, alt, caption, description, filename, filesize, `wp_get_attachment_metadata()`, author, parent). Browse/search behavior moved to `wsp_list_media`.
  - `wsp_count_media` (`wsp/count-media`, read) — counts grouped by MIME type plus a total, via `wp_count_attachments()`.
  - `wsp_update_media` (`wsp/update-media`, write) — update `title`, `alt`, `caption`, `description` by `id`.
  - `wsp_delete_media` (`wsp/delete-media`, write) — permanent delete via `wp_delete_attachment( $id, true )`; requires `delete_posts`.
  - `wsp_upload_media` (`wsp/upload-media`, write) and `wsp_upload_media_from_url` (`wsp/upload-media-from-url`, write) — sideload a file from a `url` via `download_url()` + `media_handle_sideload()`, with optional `filename`, `title`, `alt`, `caption`, and `post_id` to attach to. `wsp_execute_upload_media()` wraps `wsp_execute_upload_media_from_url()`.
- New shared helper `wsp_media_item_data()` normalizes attachment metadata for the get/update/upload responses.

### Security
- All inputs sanitized (`sanitize_text_field`/`wp_kses_post`/`sanitize_mime_type`/`esc_url_raw`/`sanitize_file_name`/`intval`); alt text written to `_wp_attachment_image_alt`. Read tools gated by `upload_files`, delete by `delete_posts`. Temp download files are removed with `wp_delete_file()` on sideload failure.

---

## [2.4.1] — 2026-07-08

### Security (WordPress.org review)
- **ACF value-write tools hardened against arbitrary code insertion** (`includes/abilities/acf.php`). The WordPress.org review flagged that the ACF write tools accepted arbitrary unsanitized values and stored them via `update_field()`, giving MCP clients a path to persist raw `<script>`/`<style>`/inline-handler markup (stored XSS) into fields and options. New recursive sanitizer `wsp_acf_sanitize_value()` now runs every incoming value through sanitization before storage:
  - Arrays are walked recursively (repeaters, groups, flexible content), with string keys sanitized via `sanitize_text_field()`.
  - Strings pass through `wp_kses_post()`, which strips `<script>`/`<style>` tags and `on*` event-handler attributes while preserving the post-safe HTML that legitimate WYSIWYG fields rely on.
  - Non-string scalars (int, float, bool, null) carry no executable payload and are returned unchanged.
- Applied in all three `update_field()` write paths: `wsp_execute_acf_update_value_deep()`, `wsp_execute_acf_bulk_update_values()`, and `wsp_execute_acf_update_option_value()` (each previously only ran `wp_unslash()` before saving).

### Notes
- The Claude Desktop connection snippet remains correct for macOS/Linux. Windows users whose Node.js lives under `C:\Program Files\nodejs` may hit a `cmd /C` quoting bug (`'C:\Program' is not recognized`) caused by the space in the path; the workaround is to wrap the launch as `"command": "cmd", "args": ["/c", "npx", …]`. Tracked in issue #13.

## [2.4.0] — 2026-07-04

### Added
- **OpenCode connection tab** on the **MCP > Connection** page (`includes/admin/connection-page.php`). Sixth per-client snippet, joining Claude Desktop / Cursor / Codex / Antigravity / OpenClaw. OpenCode connects natively over remote HTTP (no Node.js / mcp-remote bridge), using its `mcp.<name>.{ type: "remote", url, enabled, oauth, headers }` schema with the API key inlined in the `Authorization` header. The snippet is a **full-file** config (includes `$schema` and the top-level wrapper) so users can create a fresh `~/.config/opencode/opencode.json` and paste directly; instructions cover create-file → paste → restart. Server name auto-derives as `wsp-<host>`, consistent with the other tabs.

## [2.3.1] — 2026-07-01

### Security
- **Elementor write tools hardened against arbitrary code insertion** (`includes/abilities/elementor.php`). New guards applied in `wsp_execute_elementor_add_widget()`, `wsp_execute_elementor_update_element()`, and `wsp_execute_elementor_add_container()`:
  - `wsp_elementor_is_blocked_widget()` rejects code-bearing widget types (`html`, `shortcode`, `code`, `code-highlight`) before they can be written to `_elementor_data`.
  - `wsp_elementor_sanitize_settings()` recursively strips code-bearing setting keys (`custom_css`, `_attributes`, `custom_attributes`, `__dynamic__`) and runs every string value through `wp_kses_post()`, so `<script>`/`on*` handlers can't be injected via a normal text field. Structured content writes (heading, text-editor, image, button, layout, etc.) continue to work.
- **ACF options-page value reads now require `manage_options`** (was `edit_posts`) in both the native tool spec (`includes/tools/native-tools.php`, `wsp_acf_get_option_value`) and the callback's own cap check (`wsp_execute_acf_get_option_value`). Global options are admin-level configuration.

### Removed
- Dead `wsp_register_acf_abilities()` helper (`includes/abilities/acf.php`) — the old dual-mode `wp_register_ability` path, unhooked since the v2.2 native-only migration. Flagged by the WordPress.org review tool for a broad `edit_posts` permission_callback; deleting it removes the finding at its source.

### Changed
- `Requires at least` header/readme value changed from `6.9.0` to major-only `6.9` per WordPress.org versioning rules (the minor is ignored).

## [2.3.0] — 2026-06-30

### Added
- **Advanced Custom Fields (ACF) suite** (`includes/abilities/acf.php`) — 27 tools covering field groups, fields, field values (with dot-notation deep get/set), custom post types, taxonomies, and options pages. All OFF by default and only registered when ACF is active (`class_exists('ACF') || function_exists('get_field')`). Shipped via PR #8.
  - **Field groups:** list, get, create, update, delete, import-from-JSON.
  - **Fields:** list (by group), get, create, update config, delete, duplicate, force-sync (`acf/include_fields`).
  - **Values:** get/update deep (dot-notation, e.g. `repeater.0.subfield`), delete, get-all, bulk-update, get-field-object. Targets resolve via `wsp_acf_validate_target()` — accepts a numeric post/page ID, `user_<id>`, `term_<id>`/`category_<id>`, or `options`.
  - **CPT/taxonomy:** list post types, list taxonomies, programmatically create CPT/taxonomy (requires ACF 6.1+ `acf_update_post_type()` / `acf_update_taxonomy()`).
  - **Options pages:** list, create (ACF Pro), get/update option value.
- **Settings UI** — added "WooCommerce" (🛍️) and "Advanced Custom Fields" (🧩) group icons in `settings-page.php`.

### Security
- ACF value tools enforce **per-object** capabilities inside `wsp_acf_validate_target($target_id, $target_type, $is_write)`, not a blanket cap: `edit_post($id)` for post/page targets, `edit_user($id)` (write) / `list_users` (read, with self-read allowance) for user targets, `manage_categories` for term targets, and `manage_options` for the `options` target. String targets like `user_5` are normalized to id+type so they flow through the same capability gates (closes a pre-merge bypass where string targets skipped all checks).
- Field-group / field / CPT / taxonomy / options-page **create/update/delete** tools require `manage_options`; read and value-edit tools require `edit_posts`.

### Removed
- A proposed `wsp/acf-delete-options-page` tool was dropped before release. ACF options pages are re-registered on every load, so a runtime delete can't persist — its callback, native-tool registration, and registry entry were all removed to avoid advertising a non-functional tool.

### Fixed (WordPress.org Plugin Check)
- `includes/abilities/woocommerce.php` — replaced `parse_url()` with `wp_parse_url()` and `@unlink()` with `wp_delete_file()` (WordPress.WP.AlternativeFunctions).
- `includes/admin/settings-page.php` — the legacy-config redirect now sanitizes the `page` GET param (`sanitize_key( wp_unslash() )`) with a justified `WordPress.Security.NonceVerification.Recommended` ignore (read-only navigation routing, no state change).
- `includes/server/class-session-store.php` — moved the `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` ignore onto the actual SQL-string lines (it was on the wrong line and not suppressing the warning), and build the table name inline from `$wpdb->prefix` in `touch_session()`, `get_fingerprint()`, and `cleanup_expired()` so the Plugin Check `DirectDB.UnescapedDBParameter` sniff can verify it as a safe source (it couldn't trace the previous `self::table()` helper). All values remain bound via `$wpdb->prepare()`.

### Changed
- **Plugin slug renamed** `websensepro-mcp-abilities` → `wsp-mcp-ai-agents-connector`. The plugin folder and main file were renamed (`wsp-mcp-ai-agents-connector/wsp-mcp-ai-agents-connector.php`), the text domain was updated across all 48 i18n calls in `includes/admin/connection-page.php`, and a matching `Text Domain: wsp-mcp-ai-agents-connector` header was added to the main file (it was previously missing). Done to align the slug with the public name ahead of WordPress.org submission.

### Breaking changes
- **The plugin folder name changed.** On existing installs WordPress treats the new folder as a separate plugin: after updating, deactivate/remove the old `websensepro-mcp-abilities` copy and activate the new one. Stored options, the sessions table, and the API key are untouched (constants/option keys are unchanged), so no reconfiguration is needed beyond reactivation.

---

## [2.2.0] — 2026-06-24

### Summary
The plugin is now **native-only**. The legacy dual-mode path (registering abilities through the WordPress Abilities API / mcp-adapter when present) and the **MCP > Config Files** admin page have both been removed. This is the cleanup done ahead of WordPress.org submission — the plugin no longer references the off-directory `mcp-adapter`/`abilities-api` packages or the `@automattic/mcp-wordpress-remote` npm bridge anywhere.

### Removed
- **MCP > Config Files admin page** (`includes/admin/config-page.php`) — deleted. It generated mcp-adapter / `@automattic/mcp-wordpress-remote` config snippets, which are obsolete now that the native server is the only transport. **MCP > Connection** is the single source for connection details.
- **Dual-mode Abilities-API registration:**
  - `wsp_mcp_register_all_abilities()` and the `wp_abilities_api_init` / `wp_abilities_api_categories_init` hooks in the main file.
  - `wsp_register_ability_category()` in `registry.php`.
  - `wsp_register_*_abilities()` in every `includes/abilities/*.php` module (posts, pages, taxonomy, comments, media, users, search, site, yoast, elementor, woocommerce). The `wp_register_ability()` calls are gone; the `wsp_execute_*()` business logic is **unchanged** and still drives the native server.
  - `wsp_mcp_abilities_api_available()` in `dependency.php`.

### Changed
- `dependency.php` reduced to a stub — only `wsp_mcp_transport_available()` (always `true`) remains, kept for back-compat/readability.
- Old bookmarks to `admin.php?page=wsp-mcp-config` now **redirect** to MCP > Connection (`wsp_mcp_redirect_legacy_config_page()` on `admin_init`) instead of hitting a permission wall.
- `WSP_MCP_VERSION` and the plugin header bumped to `2.2.0` (the constant had been left at `2.0.0`).

### Breaking changes
- **Pre-2.0 connections made through the WordPress MCP Adapter stop working.** Those users must reconnect using the native endpoint from **MCP > Connection** (Application Password or the plugin API key). New installs and any connection already using the native endpoint are unaffected.

### Migration
- If you connected before v2.0 via the MCP Adapter, open **MCP > Connection**, copy the endpoint URL + API key (or use an Application Password), and reconnect your client.
- No data migration. Options, the sessions table, and per-ability toggles are untouched.

### Files removed
`includes/admin/config-page.php`

---

## [2.1.0] — 2026-06-23

### Summary
Adds a full WooCommerce integration suite — 15 tools covering products, orders, refunds, coupons, customers, stock, sales reports, and review moderation. All tools are off by default and only registered when WooCommerce is active. (This release shipped via PR #6; the changelog entry is recorded here retroactively.)

### Added
- **`wsp_woo_get_products`** — list products with limit and status filtering. Requires `edit_posts`.
- **`wsp_woo_get_product`** — get full details of a single product by ID. Requires `edit_posts`.
- **`wsp_woo_create_product`** — create a simple or variable product; supports attributes, SKU, stock quantity, and image-URL sideload. Requires `publish_posts`.
- **`wsp_woo_create_variation`** — create a variation for an existing variable product with per-variation price, SKU, attributes, and image. Requires `publish_posts`.
- **`wsp_woo_update_product`** — update name, price, sale price, description, SKU, stock, status, or featured image. Requires `edit_posts`.
- **`wsp_woo_list_orders`** — list recent orders with optional status filter. Requires `edit_posts`.
- **`wsp_woo_update_order_status`** — update an order's status; validated against the core WooCommerce statuses. Requires `edit_posts`.
- **`wsp_woo_refund_order`** — create a full or partial refund (triggers gateway refund via `wc_create_refund`). Requires `manage_woocommerce`.
- **`wsp_woo_create_coupon`** — create a percentage or fixed coupon with optional expiry; `discount_type` validated. Requires `manage_woocommerce`.
- **`wsp_woo_list_coupons`** — list coupons with usage stats. Requires `manage_woocommerce`.
- **`wsp_woo_create_order_note`** — add an internal or customer-facing note to an order. Requires `edit_posts`.
- **`wsp_woo_list_customers`** — list registered customers with billing email and phone (PII). Requires `manage_woocommerce`.
- **`wsp_woo_report_sales`** — gross/net revenue, tax, shipping, and average order value over N past days. Requires `manage_woocommerce`.
- **`wsp_woo_get_low_stock`** — products below a stock threshold plus out-of-stock products. Requires `edit_posts`.
- **`wsp_woo_moderate_review`** — approve, spam, trash, or reply to a product review; `action` validated. Requires `edit_posts`.
- New module `includes/abilities/woocommerce.php`; image-sideload helper with SSL bypass scoped to the single download request and gated to `local`/`development` environments.

### Changed
- `registry.php` and `native-tools.php` — 15 new entries each, gated on `class_exists('WooCommerce')`.

### Security
- Financial / PII tools (`refund_order`, `list_customers`, `create_coupon`, `list_coupons`) require `manage_woocommerce`.
- All enum inputs (`status`, `discount_type`, `action`) validated with `in_array(..., true)`.

### Migration
- No action required. All WooCommerce tools are off by default and invisible when WooCommerce is not active.

---

## [2.0.0] — 2026-06-20

### Summary
The plugin is now fully self-contained. It ships its own native MCP server (a WordPress REST endpoint) and no longer requires a companion plugin or Node.js bridge to connect to AI clients.

### Added
- **Native MCP server** — REST endpoint `/wp-json/wsp-mcp/v1/mcp` (Streamable HTTP + JSON-RPC 2.0).
  - Rationale: WP.org cannot express a dependency on a GitHub-only plugin via `Requires Plugins:`; a self-contained plugin is the proven-approvable architecture (two WP.org-approved precedents both went native).
  - Handles: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, `ping`, empty `resources/list` / `prompts/list`.
  - Protocol versions supported: `2024-11-05`, `2025-03-26`, `2025-06-18`, `2025-11-25`.
- **DB-backed session store** — table `{prefix}wsp_mcp_sessions`, fingerprint-bound, 24-hour sliding expiry, daily cron cleanup (`wsp_mcp_session_cleanup`).
- **Three auth paths** — plugin-generated API key (Bearer or `X-WSP-MCP-API-Key` header), WordPress Application Password (HTTP Basic). OAuth 2.0 deferred to v2.1.
- **MCP > Connection admin page** — shows the native endpoint URL and API key; one-click Regenerate; tabbed ready-to-paste config snippets for Claude Desktop, Cursor, Codex, Antigravity, and OpenClaw (API key hardcoded inline — avoids the `mcp-remote` "missing env var" failure).
- **Accordion Settings UI** — MCP > Settings groups abilities into collapsible sections; open/closed state persists in `localStorage`; live count badge per group.
- **Tool registry hook** — `do_action('wsp_mcp_register_tools', …)` so add-ons can register extra tools.
- `uninstall.php` drops the sessions table and all `wsp_mcp_*` options on plugin deletion.
- `readme.txt` for WordPress.org submission.

### Changed
- All existing `wsp_execute_*` callback logic is **unchanged** — only the transport changed from "register with mcp-adapter" to "register with the native tool registry" (`includes/tools/native-tools.php`).
- `dependency.php` repurposed: `wsp_mcp_abilities_api_available()` now gates dual-mode only; the native transport is always available.
- MCP > Config Files page now shows a deprecation notice pointing to MCP > Connection.

### Breaking changes
- None for end users. Pre-2.0 connections via the mcp-adapter keep working (dual-mode preserved).

### Migration (upgrading from v1.x)
- Existing mcp-adapter connections remain valid — the Abilities API path stays behind a `function_exists('wp_register_ability')` guard.
- New installs: use **MCP > Connection** to copy the native endpoint URL and API key.
- Claude Desktop users need the `npx -y mcp-remote` bridge (Claude Desktop config files don't support remote HTTP directly). Cursor, Codex, Antigravity support native remote HTTP natively.
- After enabling or adding tools, **fully reconnect the client** (restart Claude Desktop, not just open a new chat) — MCP clients cache `tools/list` at connect time.

### Files added
`includes/server/class-mcp-server.php`, `includes/server/class-session-store.php`,
`includes/server/class-auth.php`, `includes/tools/native-tools.php`,
`includes/admin/connection-page.php`, `readme.txt`, `LICENSE`

---

## [1.3.0] — 2026-06-19

### Summary
Adds Yoast SEO read/write abilities so AI clients can inspect and update SEO metadata on posts and pages.

### Added
- **`wsp/yoast-get-seo`** — returns SEO title, meta description, and focus keyphrase for a post or page. Requires `edit_posts`. OFF by default.
- **`wsp/yoast-update-seo`** — updates any combination of SEO title, meta description, and focus keyphrase. Rebuilds the Yoast indexable after saving. Requires `edit_posts`. OFF by default.
- Both abilities are gated on Yoast being active (`defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')`); they are silently absent when Yoast is not installed.
- Helper layer in `includes/abilities/yoast.php`: `wsp_yoast_is_active()`, `wsp_yoast_get_meta()`, `wsp_yoast_set_meta()`, `wsp_yoast_rebuild_indexable()`, `wsp_yoast_validate_post()`, `wsp_yoast_format_seo_data()`.
- Falls back to direct post-meta keys (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`) when `WPSEO_Meta` class is unavailable.

### Changed
- `registry.php` — two new entries added to `wsp_mcp_ability_registry()` under the "Yoast SEO" group.

### Migration
- No action required. Abilities appear in MCP > Settings under "Yoast SEO" only if Yoast SEO is active.

---

## [1.2.1] — 2026-06-17

### Summary
Minor fixes and additions to the Config Files admin page.

### Added
- **OpenClaw** tab on MCP > Config Files with a ready-to-paste JSON snippet (`~/.openclaw/openclaw.json`, uses `mcp.servers` schema + `mcp-remote` bridge).

### Fixed
- `create-page` ability: added `page_layout` input parameter (maps to `_wp_page_template` post meta) so AI clients can set the page template when creating a page.
- `create-page` ability: fixed Elementor initialization — new pages are now properly recognized by Elementor (`_elementor_edit_mode` meta set to `builder`).

---

## [1.2.0] — 2026-06-14

### Summary
Major refactor from a single-file plugin to a modular `includes/`-based structure. Adds a full suite of Elementor page-builder abilities.

### Added
- **Elementor abilities** (`includes/abilities/elementor.php`) — 9 abilities for reading and writing Elementor page structure:
  - Read: `elementor-list-pages`, `elementor-get-page`, `elementor-get-element`, `elementor-find-element`, `elementor-list-templates`
  - Write: `elementor-update-element`, `elementor-add-widget`, `elementor-add-container`, `elementor-remove-element`
  - All gated on `class_exists('\Elementor\Plugin')` and `edit_posts` capability.
- Helper functions for the Elementor data model: `wsp_elementor_get_data`, `wsp_elementor_save_data`, `wsp_elementor_generate_id`, `wsp_elementor_find_by_id`, `wsp_elementor_remove_by_id`, `wsp_elementor_update_by_id`, `wsp_elementor_insert_into`, `wsp_elementor_first_insertable`, `wsp_elementor_simplify_tree`, `wsp_elementor_search_tree`.

### Changed
- **Modular refactor** — moved all feature code out of the monolithic main file into `includes/abilities/` (posts, pages, taxonomy, comments, media, users, search, site). Main file reduced to a minimal loader + activation glue.
  - Rationale: single-file plugins are hard to review and extend; the new structure matches WP.org best practices.

### Migration
- No action required. Behavior is identical to v1.1.0 for all non-Elementor abilities.

---

## [1.1.0] — 2026-06-07

### Summary
Adds an admin page to generate ready-to-paste MCP config file snippets for Claude Desktop, Cursor, and Codex.

### Added
- **MCP > Config Files** admin page (`includes/admin/config-page.php`) — auto-fills the REST API URL and current WP username; user replaces the placeholder Application Password. Tabs for Claude Desktop, Cursor, and Codex (TOML).
- `readme.txt` (initial version).

---

## [1.0.0] — 2026-06-06

### Summary
Initial release. Registers WordPress content as MCP abilities via the WordPress Abilities API / mcp-adapter stack.

### Added
- Plugin scaffold: `wsp-wordpress-mcp.php` main file with all abilities inline.
- Read abilities (ON by default): `get-posts`, `get-pages`, `get-categories`, `get-tags`, `search`, `get-site-info`.
- Write abilities (OFF by default): `create-post`, `update-post`, `delete-post`, `create-page`, `update-page`, `delete-page`, `create-category`, `create-tag`.
- Sensitive read abilities (OFF by default): `get-comments`, `approve-comment`, `delete-comment`, `get-media`, `get-users`, `get-plugins`.
- Admin toggle UI (MCP > Settings) — per-ability on/off switches with write-action confirmation dialogs.
- Central ability registry (`wsp_mcp_ability_registry()`) driving both admin UI and ability registration.
- Dual-mode transport guard: `function_exists('wp_register_ability')` so the plugin degrades gracefully when the Abilities API is absent.
