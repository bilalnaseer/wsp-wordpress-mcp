<?php
/**
 * Native MCP tool registry (Milestone M2).
 *
 * Maps each existing `wsp_execute_*` ability callback to a native MCP tool.
 * The business logic is reused verbatim — only the registration/transport
 * changes. Per-tool exposure still honours the admin toggles via `enable_key`
 * (the same `wsp/...` keys the Abilities-API path uses), so the Settings page
 * controls both transports identically.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register all native tools with WSP_MCP_Server.
 */
function wsp_mcp_register_native_tools() {
	$obj = array( 'type' => 'object', 'properties' => new stdClass() );

	// ---- Site Context (MCP > Context) — advertised only while the admin's switch is on and a document has content ----
	WSP_MCP_Server::register_tool( 'wsp_get_site_context', array(
		'description' => 'READ THIS FIRST. Returns the site administrator\'s AGENTS.md (how this site is built and the rules to follow) and CHANGELOG.md (what changed and why). Call once at the start of a session instead of exploring the site with other tools.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'file' => array( 'type' => 'string', 'description' => 'all (default) | agents | changelog.' ),
		) ),
		'callback'        => 'wsp_execute_get_site_context',
		'capability'      => '',
		'enable_key'      => '',
		'active_callback' => 'wsp_mcp_context_is_active',
	) );
	// Write side: a normal registry toggle (OFF by default), independent of the Context switch so it can fill an empty page.
	WSP_MCP_Server::register_tool( 'wsp_update_site_context', array(
		'description' => 'Write the site\'s AGENTS.md or CHANGELOG.md (the Site Context every connected agent reads first). For a large file, send the first chunk with mode=replace and the rest in order with mode=append (keep each chunk under ~40,000 characters); check total_chars / sha256 in the response. Never include passwords or API keys — every connected agent can read these documents.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'file', 'content' ), 'properties' => array(
			'file'    => array( 'type' => 'string', 'enum' => array( 'agents', 'changelog' ), 'description' => 'agents = AGENTS.md, changelog = CHANGELOG.md.' ),
			'content' => array( 'type' => 'string', 'description' => 'Markdown text. Empty string with mode=replace clears the document.' ),
			'mode'    => array( 'type' => 'string', 'enum' => array( 'replace', 'append', 'prepend' ), 'description' => 'replace (default) | append | prepend (e.g. add a new changelog entry at the top).' ),
			'enable'  => array( 'type' => 'boolean', 'description' => 'Optional. Also turn the Site Context switch on (true) or off (false).' ),
		) ),
		'callback'    => 'wsp_execute_update_site_context',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/update-site-context',
	) );

	// ---- Posts ----
	WSP_MCP_Server::register_tool( 'wsp_get_posts', array(
		'description' => 'Returns blog posts with full metadata.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'per_page' => array( 'type' => 'integer', 'description' => 'Number of posts. Default 10.' ),
			'status'   => array( 'type' => 'string', 'description' => 'publish | draft | all. Default publish.' ),
		) ),
		'callback'    => 'wsp_execute_get_posts',
		'capability'  => '',
		'enable_key'  => 'wsp/get-posts',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_post', array(
		'description' => 'Gets a single post by ID with full content, any status (draft/publish/etc).',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_get_post',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-post',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_post', array(
		'description' => 'Creates a new blog post.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => array(
			'title'      => array( 'type' => 'string' ),
			'content'    => array( 'type' => 'string' ),
			'status'     => array( 'type' => 'string' ),
			'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'excerpt'    => array( 'type' => 'string' ),
			'slug'       => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_create_post',
		'capability'  => 'publish_posts',
		'enable_key'  => 'wsp/create-post',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_post', array(
		'description' => 'Updates an existing post by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id'         => array( 'type' => 'integer' ),
			'title'      => array( 'type' => 'string' ),
			'content'    => array( 'type' => 'string' ),
			'status'     => array( 'type' => 'string' ),
			'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
		) ),
		'callback'    => 'wsp_execute_update_post',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/update-post',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_post', array(
		'description' => 'Moves a post to trash by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_post',
		'capability'  => 'delete_posts',
		'enable_key'  => 'wsp/delete-post',
	) );

	// ---- Pages ----
	WSP_MCP_Server::register_tool( 'wsp_get_pages', array(
		'description' => 'Returns published pages.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_pages',
		'capability'  => '',
		'enable_key'  => 'wsp/get-pages',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_page', array(
		'description' => 'Creates a new page (optionally Elementor-initialized).',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => array(
			'title'     => array( 'type' => 'string' ),
			'content'   => array( 'type' => 'string' ),
			'status'    => array( 'type' => 'string' ),
			'parent'    => array( 'type' => 'integer' ),
			'slug'      => array( 'type' => 'string' ),
			'elementor' => array( 'type' => 'boolean' ),
		) ),
		'callback'    => 'wsp_execute_create_page',
		'capability'  => 'publish_pages',
		'enable_key'  => 'wsp/create-page',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_page', array(
		'description' => 'Updates an existing page by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id'      => array( 'type' => 'integer' ),
			'title'   => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string' ),
			'status'  => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_update_page',
		'capability'  => 'edit_pages',
		'enable_key'  => 'wsp/update-page',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_page', array(
		'description' => 'Moves a page to trash by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_page',
		'capability'  => 'delete_pages',
		'enable_key'  => 'wsp/delete-page',
	) );

	// ---- Taxonomy ----
	WSP_MCP_Server::register_tool( 'wsp_get_categories', array(
		'description' => 'Returns all categories.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_categories',
		'capability'  => '',
		'enable_key'  => 'wsp/get-categories',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_category', array(
		'description' => 'Creates a new category (taxonomy "category", or "product_cat" for WooCommerce product categories).',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
			'taxonomy'    => array( 'type' => 'string', 'description' => 'category (default) | product_cat.' ),
			'name'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'parent'      => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_create_category',
		'capability'  => 'manage_categories',
		'enable_key'  => 'wsp/create-category',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_tags', array(
		'description' => 'Returns all tags.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_tags',
		'capability'  => '',
		'enable_key'  => 'wsp/get-tags',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_tag', array(
		'description' => 'Creates a new tag.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
			'name'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_create_tag',
		'capability'  => 'manage_categories',
		'enable_key'  => 'wsp/create-tag',
	) );

	// ---- Comments ----
	WSP_MCP_Server::register_tool( 'wsp_get_comments', array(
		'description' => 'Returns comments.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'status'   => array( 'type' => 'string', 'description' => 'hold | approve | all.' ),
			'per_page' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_get_comments',
		'capability'  => 'moderate_comments',
		'enable_key'  => 'wsp/get-comments',
	) );
	WSP_MCP_Server::register_tool( 'wsp_approve_comment', array(
		'description' => 'Approves a pending comment by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_approve_comment',
		'capability'  => 'moderate_comments',
		'enable_key'  => 'wsp/approve-comment',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_comment', array(
		'description' => 'Trashes a comment by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_comment',
		'capability'  => 'moderate_comments',
		'enable_key'  => 'wsp/delete-comment',
	) );

	// ---- Media ----
	WSP_MCP_Server::register_tool( 'wsp_list_media', array(
		'description' => 'Browse and search the WordPress media library by type, keyword, or date.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'per_page' => array( 'type' => 'integer', 'description' => 'Items per page. Default 20.' ),
			'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
			'type'     => array( 'type' => 'string', 'description' => 'MIME type filter, e.g. image or image/png.' ),
			'search'   => array( 'type' => 'string', 'description' => 'Keyword to search titles/filenames.' ),
			'year'     => array( 'type' => 'integer', 'description' => 'Filter by upload year.' ),
			'month'    => array( 'type' => 'integer', 'description' => 'Filter by upload month (1-12).' ),
		) ),
		'callback'    => 'wsp_execute_list_media',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/list-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_media', array(
		'description' => 'Retrieve the full metadata of a specific media file by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer', 'description' => 'Attachment ID.' ),
		) ),
		'callback'    => 'wsp_execute_get_media',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/get-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_count_media', array(
		'description' => 'Get media library counts grouped by MIME type, plus a total.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_count_media',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/count-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_media', array(
		'description' => 'Update the title, alt text, caption, or description of a media file by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id'          => array( 'type' => 'integer', 'description' => 'Attachment ID.' ),
			'title'       => array( 'type' => 'string' ),
			'alt'         => array( 'type' => 'string', 'description' => 'Alternative text.' ),
			'caption'     => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_update_media',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/update-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_media', array(
		'description' => 'Permanently delete a media file from the WordPress media library by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer', 'description' => 'Attachment ID.' ),
		) ),
		'callback'    => 'wsp_execute_delete_media',
		'capability'  => 'delete_posts',
		'enable_key'  => 'wsp/delete-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_upload_media', array(
		'description' => 'Upload an image into the WordPress media library. Provide EITHER "data" (base64-encoded file content — use this to upload a file attached to the chat directly, no public URL required) OR "url" (a public link to fetch). Supported types: jpg, png, gif, webp.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'data'      => array( 'type' => 'string', 'description' => 'Base64-encoded file content. A "data:<mime>;base64," prefix is accepted. Use this to upload a file straight from the chat without a URL.' ),
			'mime_type' => array( 'type' => 'string', 'description' => 'MIME type of the base64 data (e.g. image/png), used when "data" has no data-URI prefix and "filename" has no extension.' ),
			'url'       => array( 'type' => 'string', 'description' => 'Source URL of the file to upload (used only when "data" is not provided).' ),
			'filename'  => array( 'type' => 'string', 'description' => 'Optional destination filename.' ),
			'title'     => array( 'type' => 'string' ),
			'alt'       => array( 'type' => 'string' ),
			'caption'   => array( 'type' => 'string' ),
			'post_id'   => array( 'type' => 'integer', 'description' => 'Optional post ID to attach the media to.' ),
		) ),
		'callback'    => 'wsp_execute_upload_media',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/upload-media',
	) );
	WSP_MCP_Server::register_tool( 'wsp_upload_media_from_url', array(
		'description' => 'Pull an image from any web link straight into your media library.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'url' ), 'properties' => array(
			'url'      => array( 'type' => 'string', 'description' => 'Source URL of the image to import.' ),
			'filename' => array( 'type' => 'string', 'description' => 'Optional destination filename.' ),
			'title'    => array( 'type' => 'string' ),
			'alt'      => array( 'type' => 'string' ),
			'caption'  => array( 'type' => 'string' ),
			'post_id'  => array( 'type' => 'integer', 'description' => 'Optional post ID to attach the media to.' ),
		) ),
		'callback'    => 'wsp_execute_upload_media_from_url',
		'capability'  => 'upload_files',
		'enable_key'  => 'wsp/upload-media-from-url',
	) );
	WSP_MCP_Server::register_tool( 'wsp_set_featured_image', array(
		'description' => 'Set an image as the featured image (thumbnail) for a post or page.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'attachment_id' ), 'properties' => array(
			'post_id'       => array( 'type' => 'integer', 'description' => 'The post or page ID.' ),
			'attachment_id' => array( 'type' => 'integer', 'description' => 'The media attachment ID to set as featured.' ),
		) ),
		'callback'    => 'wsp_execute_set_featured_image',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/set-featured-image',
	) );

	// ---- Users / Search / Site ----
	WSP_MCP_Server::register_tool( 'wsp_get_users', array(
		'description' => 'Lists registered users.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_users',
		'capability'  => 'list_users',
		'enable_key'  => 'wsp/get-users',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_user', array(
		'description' => 'Create a new WordPress user account.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'username', 'email' ), 'properties' => array(
			'username'     => array( 'type' => 'string' ),
			'email'        => array( 'type' => 'string' ),
			'password'     => array( 'type' => 'string', 'description' => 'Optional; auto-generated if omitted.' ),
			'role'         => array( 'type' => 'string', 'description' => 'Defaults to subscriber.' ),
			'display_name' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_create_user',
		'capability'  => 'create_users',
		'enable_key'  => 'wsp/create-user',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_user', array(
		'description' => "Update a user's email, display name, role, or password.",
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id'           => array( 'type' => 'integer' ),
			'email'        => array( 'type' => 'string' ),
			'display_name' => array( 'type' => 'string' ),
			'role'         => array( 'type' => 'string' ),
			'password'     => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_update_user',
		'capability'  => 'edit_users',
		'enable_key'  => 'wsp/update-user',
	) );
	WSP_MCP_Server::register_tool( 'wsp_search', array(
		'description' => 'Search posts and pages by keyword.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'query' ), 'properties' => array(
			'query' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_search',
		'capability'  => '',
		'enable_key'  => 'wsp/search',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_site_info', array(
		'description' => 'Returns site name, URL, tagline, WP version, and language.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_site_info',
		'capability'  => '',
		'enable_key'  => 'wsp/get-site-info',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_plugins', array(
		'description' => 'Lists installed plugins with name, version, author, active status and update availability (active_plugins = active only; plugins = all).',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_plugins',
		'capability'  => 'activate_plugins',
		'enable_key'  => 'wsp/get-plugins',
	) );

	WSP_MCP_Server::register_tool( 'wsp_update_site_info', array(
		'description' => 'Update the site title, tagline, and/or admin email.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'name'        => array( 'type' => 'string' ),
			'tagline'     => array( 'type' => 'string' ),
			'admin_email' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_update_site_info',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/update-site-info',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_permalink_structure', array(
		'description' => 'Change the site permalink structure.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'structure' ), 'properties' => array(
			'structure' => array( 'type' => 'string', 'description' => 'e.g. /%postname%/, or empty string for plain.' ),
		) ),
		'callback'    => 'wsp_execute_update_permalink_structure',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/update-permalink-structure',
	) );
	WSP_MCP_Server::register_tool( 'wsp_activate_plugin', array(
		'description' => 'Activate an installed plugin by file path.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'file' ), 'properties' => array(
			'file' => array( 'type' => 'string', 'description' => 'Plugin file path, e.g. akismet/akismet.php.' ),
		) ),
		'callback'    => 'wsp_execute_activate_plugin',
		'capability'  => 'activate_plugins',
		'enable_key'  => 'wsp/activate-plugin',
	) );
	WSP_MCP_Server::register_tool( 'wsp_deactivate_plugin', array(
		'description' => 'Deactivate an active plugin by file path.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'file' ), 'properties' => array(
			'file' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_deactivate_plugin',
		'capability'  => 'activate_plugins',
		'enable_key'  => 'wsp/deactivate-plugin',
	) );

	wsp_mcp_register_defs( wsp_mcp_plugin_admin_tool_defs() ); // v2.9.5: install / install-from-url / delete / update

	// ---- Themes ----
	WSP_MCP_Server::register_tool( 'wsp_get_themes', array(
		'description' => 'List installed themes and which one is active.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_themes',
		'capability'  => 'switch_themes',
		'enable_key'  => 'wsp/get-themes',
	) );
	WSP_MCP_Server::register_tool( 'wsp_switch_theme', array(
		'description' => 'Activate a different installed theme.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'theme' ), 'properties' => array(
			'theme' => array( 'type' => 'string', 'description' => 'Theme stylesheet slug.' ),
		) ),
		'callback'    => 'wsp_execute_switch_theme',
		'capability'  => 'switch_themes',
		'enable_key'  => 'wsp/switch-theme',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_theme_file', array(
		'description' => 'Lists the files of an installed theme (omit "path"), or reads one text file from it (returns content, bytes, sha256; content is capped at 512 KB with truncated:true).',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'theme' ), 'properties' => array(
			'theme' => array( 'type' => 'string', 'description' => 'Theme folder slug (see wsp_get_themes).' ),
			'path'  => array( 'type' => 'string', 'description' => 'Relative file path, e.g. "functions.php" or "templates/index.html". Omit to list all files.' ),
		) ),
		'callback'    => 'wsp_execute_get_theme_file',
		'capability'  => 'install_themes',
		'enable_key'  => 'wsp/get-theme-file',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_theme_file', array(
		'description' => 'Creates or overwrites ONE file in an installed theme without re-uploading the whole theme. PHP files are syntax-checked and literal require/include targets must exist, otherwise nothing is written. The previous version is copied to uploads/wsp-mcp-theme-backups/ (returned as "backup"). Same path/extension rules as wsp_upload_theme.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'theme', 'path', 'content' ), 'properties' => array(
			'theme'   => array( 'type' => 'string', 'description' => 'Theme folder slug.' ),
			'path'    => array( 'type' => 'string', 'description' => 'Relative file path inside the theme.' ),
			'content' => array( 'type' => 'string', 'description' => 'Full new file content.' ),
		) ),
		'callback'    => 'wsp_execute_update_theme_file',
		'capability'  => 'install_themes',
		'enable_key'  => 'wsp/update-theme-file',
	) );
	WSP_MCP_Server::register_tool( 'wsp_upload_theme_chunk', array(
		'description' => 'Uploads a large theme .zip in pieces. Send base64 slices of the zip as part=0,1,2,… with the same upload_id (part 0 restarts); on the last piece set complete=true (plus overwrite/activate) and the assembled zip is installed exactly like wsp_upload_theme "data". Returns next_part until complete.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'upload_id', 'part', 'data' ), 'properties' => array(
			'upload_id' => array( 'type' => 'string', 'description' => 'Any short unique id, identical for all pieces of one upload.' ),
			'part'      => array( 'type' => 'integer', 'description' => '0-based piece number, sent in order.' ),
			'data'      => array( 'type' => 'string', 'description' => 'Base64 slice of the .zip (slice the base64 string, not the binary).' ),
			'complete'  => array( 'type' => 'boolean', 'description' => 'true on the final piece: assemble and install.' ),
			'overwrite' => array( 'type' => 'boolean', 'description' => 'Final piece only: replace an installed theme of the same name.' ),
			'activate'  => array( 'type' => 'boolean', 'description' => 'Final piece only: activate after install.' ),
		) ),
		'callback'    => 'wsp_execute_upload_theme_chunk',
		'capability'  => 'install_themes',
		'enable_key'  => 'wsp/upload-theme-chunk',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_mcp_diagnostics', array(
		'description' => 'MCP transport diagnostics: number of live sessions, session TTL, and counts/recent entries of rejected requests (auth_failed, origin_blocked, session_expired, session_mismatch, rate_limited) so you can see why a client is being blocked.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'hours' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 720, 'description' => 'Look-back window for rejection counts. Default 24.' ),
		) ),
		'callback'    => 'wsp_execute_get_mcp_diagnostics',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/get-mcp-diagnostics',
	) );
	WSP_MCP_Server::register_tool( 'wsp_upload_theme', array(
		'description' => 'Installs a WordPress theme into wp-content/themes — use this to install a theme you generated. Provide EXACTLY ONE source: "files" (recommended for generated themes: an object mapping relative paths to text content, e.g. {"style.css": "/*\nTheme Name: Acme\n...*/", "functions.php": "<?php ...", "templates/index.html": "<!-- wp:... -->"}, plus "slug" for the folder name and optional "binary_files" for base64 images/fonts such as screenshot.png), "data" (a base64 .zip whose root contains the theme folder), or "url" (public http(s) link to a theme .zip). style.css with a "Theme Name:" header is required; a classic theme also needs index.php, a block theme needs templates/index.html; a child theme sets "Template: <parent-slug>" and a missing parent is fetched from WordPress.org. Installs through WordPress core\'s Theme_Upgrader (same as Appearance > Themes > Upload). Existing themes are only replaced with overwrite=true. activate=true switches the site to the theme after install. Returns { slug, name, version, is_block_theme, parent, parent_installed, replaced, activated, active_theme, preview_url } (+ activation_error if install succeeded but activation did not).',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'slug'         => array( 'type' => 'string', 'description' => 'Theme folder name (required with "files"): lowercase letters, digits, "-" or "_". Ignored for zips (the zip\'s root folder is used).' ),
			'files'        => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ), 'description' => 'Theme files as { "relative/path.ext": "text content" }. Allowed: .php .css .scss .js .mjs .map .json .html .htm .txt .md .svg .xml .pot .po. No "..", absolute or hidden (dot) paths.' ),
			'binary_files' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string' ), 'description' => 'Binary theme files as { "relative/path.ext": "base64" } — only with "files". Allowed: .png .jpg .jpeg .gif .webp .avif .ico .svg .woff .woff2 .ttf .otf .eot .mo.' ),
			'data'         => array( 'type' => 'string', 'description' => 'A theme .zip as base64 (data: URI prefix allowed).' ),
			'url'          => array( 'type' => 'string', 'description' => 'Public http(s) URL of a theme .zip.' ),
			'overwrite'    => array( 'type' => 'boolean', 'description' => 'Update an already-installed theme with the same folder name. With "files", a partial map is MERGED into the installed theme (files you do not send are kept, so you can patch single files) and the old folder is first backed up to {slug}-backup-{timestamp}. Default false.' ),
			'replace_all'  => array( 'type' => 'boolean', 'description' => 'Only with "files" + overwrite: delete every installed file not in "files" (clean replace). Default false.' ),
			'activate'     => array( 'type' => 'boolean', 'description' => 'Activate the theme after installing it (needs switch_themes). Default false.' ),
		) ),
		'callback'    => 'wsp_execute_upload_theme',
		'capability'  => 'install_themes',
		'enable_key'  => 'wsp/upload-theme',
	) );

	// ---- Custom Post Types ----
	WSP_MCP_Server::register_tool( 'wsp_get_post_types', array(
		'description' => 'List registered custom post types (excludes built-in Posts/Pages).',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_post_types',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-post-types',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_cpt_items', array(
		'description' => 'List items of a given custom post type.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_type' ), 'properties' => array(
			'post_type' => array( 'type' => 'string' ),
			'per_page'  => array( 'type' => 'integer' ),
			'status'    => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_get_cpt_items',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-cpt-items',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_cpt_item', array(
		'description' => 'Create a new item of a given custom post type.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_type', 'title' ), 'properties' => array(
			'post_type' => array( 'type' => 'string' ),
			'title'     => array( 'type' => 'string' ),
			'content'   => array( 'type' => 'string' ),
			'status'    => array( 'type' => 'string' ),
			'slug'      => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_create_cpt_item',
		'capability'  => 'publish_posts',
		'enable_key'  => 'wsp/create-cpt-item',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_cpt_item', array(
		'description' => 'Update an existing custom post type item by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_type', 'id' ), 'properties' => array(
			'post_type' => array( 'type' => 'string' ),
			'id'        => array( 'type' => 'integer' ),
			'title'     => array( 'type' => 'string' ),
			'content'   => array( 'type' => 'string' ),
			'status'    => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_update_cpt_item',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/update-cpt-item',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_cpt_item', array(
		'description' => 'Move a custom post type item to trash by ID.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_type', 'id' ), 'properties' => array(
			'post_type' => array( 'type' => 'string' ),
			'id'        => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_cpt_item',
		'capability'  => 'delete_posts',
		'enable_key'  => 'wsp/delete-cpt-item',
	) );

	// ---- Menus ----
	WSP_MCP_Server::register_tool( 'wsp_get_menus', array(
		'description' => 'Lists all navigation menus with item counts and assigned theme locations.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_menus',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-menus',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_menu_items', array(
		'description' => 'Lists the items inside a specific navigation menu.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'menu' ), 'properties' => array(
			'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Menu ID, slug, or name.' ),
		) ),
		'callback'    => 'wsp_execute_get_menu_items',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-menu-items',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_menu', array(
		'description' => 'Creates a new navigation menu.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
			'name' => array( 'type' => 'string' ),
		) ),
		'callback'    => 'wsp_execute_create_menu',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/create-menu',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_menu', array(
		'description' => 'Deletes a navigation menu.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'menu' ), 'properties' => array(
			'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Menu ID, slug, or name.' ),
		) ),
		'callback'    => 'wsp_execute_delete_menu',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/delete-menu',
	) );
	WSP_MCP_Server::register_tool( 'wsp_add_menu_item', array(
		'description' => "Adds an item (custom link, post, page, or category) to a navigation menu.",
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'menu', 'type' ), 'properties' => array(
			'menu'      => array( 'type' => array( 'integer', 'string' ), 'description' => 'Menu ID, slug, or name.' ),
			'type'      => array( 'type' => 'string', 'description' => "custom | post | page | category." ),
			'title'     => array( 'type' => 'string', 'description' => 'Required for custom; optional label override otherwise.' ),
			'url'       => array( 'type' => 'string', 'description' => 'Required when type is custom.' ),
			'object_id' => array( 'type' => 'integer', 'description' => 'Post/page/category ID. Required when type is post, page, or category.' ),
			'parent'    => array( 'type' => 'integer', 'description' => 'Parent menu item ID, for a sub-item.' ),
			'order'     => array( 'type' => 'integer', 'description' => 'Menu order position.' ),
		) ),
		'callback'    => 'wsp_execute_add_menu_item',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/add-menu-item',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_menu_item', array(
		'description' => "Updates a menu item's title, URL, parent, or order.",
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'item_id' ), 'properties' => array(
			'item_id' => array( 'type' => 'integer' ),
			'title'   => array( 'type' => 'string' ),
			'url'     => array( 'type' => 'string' ),
			'parent'  => array( 'type' => 'integer' ),
			'order'   => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_update_menu_item',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/update-menu-item',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_menu_item', array(
		'description' => 'Removes an item from a navigation menu.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'item_id' ), 'properties' => array(
			'item_id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_menu_item',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/delete-menu-item',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_menu_locations', array(
		'description' => 'Lists theme menu locations and which menu is assigned to each.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_menu_locations',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-menu-locations',
	) );
	WSP_MCP_Server::register_tool( 'wsp_assign_menu_location', array(
		'description' => 'Assigns (or unassigns, when menu is omitted) a navigation menu to a theme location.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'location' ), 'properties' => array(
			'location' => array( 'type' => 'string', 'description' => 'Theme location slug, from wsp_get_menu_locations.' ),
			'menu'     => array( 'type' => 'integer', 'description' => 'Menu ID to assign. Omit or 0 to unassign.' ),
		) ),
		'callback'    => 'wsp_execute_assign_menu_location',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/assign-menu-location',
	) );

	// ---- Site Editor: Global Styles + Templates (block themes) ----
	$tpl_type = array( 'type' => 'string', 'enum' => array( 'template', 'template_part' ), 'description' => '`template` (default — index, single, archive, …) or `template_part` (header, footer, and other reusable areas).' );
	WSP_MCP_Server::register_tool( 'wsp_get_global_styles', array(
		'description' => 'Gets the Global Styles (theme.json) settings and styles of the active block theme. origin=user (default) returns only the site\'s Site Editor customizations; origin=merged returns the effective values (core + theme + user). include_variations=true also lists the theme\'s style variations as available_variations: [{ slug, title, scope }] where scope is full | color | typography; pass a slug to wsp_update_global_styles variation_slug to apply it.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'origin'             => array( 'type' => 'string', 'enum' => array( 'user', 'merged' ), 'description' => 'user (default) or merged.' ),
			'include_variations' => array( 'type' => 'boolean', 'description' => 'Also list the theme\'s style variations. Default false.' ),
		) ),
		'callback'    => 'wsp_execute_get_global_styles',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-global-styles',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_global_styles', array(
		'description' => 'Updates the site\'s Global Styles (theme.json user layer) for the active block theme. settings/styles use theme.json structure (e.g. styles.color.background, styles.elements.link.color.text, styles.blocks["core/button"].border.radius, settings.color.palette). By default values deep-merge into existing customizations — objects merge key-by-key, arrays (like a palette) replace wholesale, and null removes a key so it falls back to the theme. merge=false replaces settings/styles entirely. variation_slug applies a theme style variation first (full variations replace, color/typography partials merge). Custom CSS (`css` keys) cannot be set through this tool.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'settings'       => array( 'type' => 'object', 'description' => 'theme.json "settings" fragment.' ),
			'styles'         => array( 'type' => 'object', 'description' => 'theme.json "styles" fragment.' ),
			'variation_slug' => array( 'type' => 'string', 'description' => 'Style variation slug from wsp_get_global_styles include_variations=true.' ),
			'merge'          => array( 'type' => 'boolean', 'description' => 'Deep-merge into existing customizations (default true). false replaces.' ),
		) ),
		'callback'    => 'wsp_execute_update_global_styles',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/update-global-styles',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_templates', array(
		'description' => 'Lists block templates or template parts of the active theme. Returns { templates: [{ id, slug, title, description, type, area, status, source, has_theme_file, is_customized }], total, total_pages, page, per_page }. IDs are `theme-slug//slug` and are accepted by wsp_get_template / wsp_update_template with the same type.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'type'     => $tpl_type,
			'area'     => array( 'type' => 'string', 'description' => 'Template parts only: header | footer | uncategorized.' ),
			'search'   => array( 'type' => 'string', 'description' => 'Case-insensitive match on title, slug, or description.' ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 10.' ),
			'page'     => array( 'type' => 'integer', 'description' => 'Default 1.' ),
		) ),
		'callback'    => 'wsp_execute_get_templates',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-templates',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_template', array(
		'description' => 'Gets one block template or template part by ID (theme-slug//slug) including its full block markup content.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'template_id' ), 'properties' => array(
			'template_id' => array( 'type' => 'string', 'description' => 'e.g. twentytwentyfive//single' ),
			'type'        => $tpl_type,
		) ),
		'callback'    => 'wsp_execute_get_template',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-template',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_template', array(
		'description' => 'Creates a new block template or template part for the active theme. Fails if the slug already exists (use wsp_update_template for that, including to customize a theme-provided template).',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'slug' ), 'properties' => array(
			'slug'        => array( 'type' => 'string', 'description' => 'e.g. single-product, page-landing, or a template-part name like header-minimal.' ),
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string', 'description' => 'Block markup, e.g. <!-- wp:template-part {"slug":"header"} /-->.' ),
			'description' => array( 'type' => 'string' ),
			'type'        => $tpl_type,
			'area'        => array( 'type' => 'string', 'description' => 'Template parts only: header | footer | uncategorized (default).' ),
		) ),
		'callback'    => 'wsp_execute_create_template',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/create-template',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_template', array(
		'description' => 'Updates a block template or template part (title, content, description, and area for template parts). Updating a theme-file template that has not been customized yet creates the same database override the Site Editor creates on first save.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'template_id' ), 'properties' => array(
			'template_id' => array( 'type' => 'string', 'description' => 'theme-slug//slug' ),
			'type'        => $tpl_type,
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string', 'description' => 'Full replacement block markup.' ),
			'description' => array( 'type' => 'string' ),
			'area'        => array( 'type' => 'string', 'description' => 'Template parts only: header | footer | uncategorized.' ),
		) ),
		'callback'    => 'wsp_execute_update_template',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/update-template',
	) );

	// ---- Widgets & Sidebars (classic themes) ----
	$widget_id_prop = array( 'type' => 'string', 'description' => 'Widget id, e.g. "block-3", "text-2" (id_base-number), from wsp_get_widgets or wsp_get_sidebars.' );
	$instance_prop  = array( 'type' => 'object', 'description' => 'Widget settings keyed by setting name (each type defines its own). block: {"content": "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->"}; custom_html: {title, content}; text: {title, text, filter}; recent-posts: {title, number, show_date}; search: {title}; categories / archives: {title, count, dropdown}; nav_menu: {title, nav_menu: menu id}. All strings are filtered with wp_kses_post.' );
	WSP_MCP_Server::register_tool( 'wsp_get_sidebars', array(
		'description' => 'Lists the widget areas (sidebars, footers, …) of a classic theme with the ordered widget ids in each, plus wp_inactive_widgets (widgets removed from every area but kept). Returns { sidebars: [{ id, name, description, status, widgets }], total }. A block theme has no widget areas: returns an empty list and a hint to use wsp_get_templates (template parts) instead.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_sidebars',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-sidebars',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_sidebar', array(
		'description' => 'Sets the full contents and order of a widget area. `widgets` is the ordered list of widget ids it should contain; listed widgets are moved in from wherever they are, and widgets previously in the area but not listed move to wp_inactive_widgets (settings kept). Pass [] to empty the area. Sidebars themselves are registered by the theme and cannot be created or deleted.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'sidebar', 'widgets' ), 'properties' => array(
			'sidebar' => array( 'type' => 'string', 'description' => 'Sidebar id from wsp_get_sidebars.' ),
			'widgets' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Ordered widget ids.' ),
		) ),
		'callback'    => 'wsp_execute_update_sidebar',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/update-sidebar',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_widget_types', array(
		'description' => 'Lists registered widget types — the valid id_base values for wsp_create_widget. Returns { widget_types: [{ id, name, description, is_multi, settings_editable }], total }. Only types with settings_editable: true can be created or have their settings changed over MCP.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_widget_types',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-widget-types',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_widgets', array(
		'description' => 'Lists widgets placed in widget areas with their settings, grouped by sidebar in display order. Returns { widgets: [{ id, id_base, sidebar, position, settings_editable, settings }], total }; position is the 0-based index in its sidebar. settings is null for widget types that do not expose them.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'sidebar' => array( 'type' => 'string', 'description' => 'Only this sidebar (id from wsp_get_sidebars, or wp_inactive_widgets). Omit for all.' ),
		) ),
		'callback'    => 'wsp_execute_get_widgets',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-widgets',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_widget', array(
		'description' => 'Gets one widget with its settings and rendered front-end HTML. Returns { id, id_base, sidebar, settings_editable, settings, rendered }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'widget_id' ), 'properties' => array(
			'widget_id' => $widget_id_prop,
		) ),
		'callback'    => 'wsp_execute_get_widget',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/get-widget',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_widget', array(
		'description' => 'Creates a widget in a widget area. id_base must be a settings_editable type from wsp_get_widget_types ("block" is recommended for free-form content). position is 0-based; omit (or past the end) to add last.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'sidebar', 'id_base' ), 'properties' => array(
			'sidebar'  => array( 'type' => 'string', 'description' => 'Sidebar id from wsp_get_sidebars.' ),
			'id_base'  => array( 'type' => 'string', 'description' => 'Widget type, e.g. block, custom_html, recent-posts.' ),
			'instance' => $instance_prop,
			'position' => array( 'type' => 'integer', 'minimum' => 0 ),
		) ),
		'callback'    => 'wsp_execute_create_widget',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/create-widget',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_widget', array(
		'description' => 'Updates a widget\'s settings, moves it to another widget area, and/or reorders it. instance is merged over current settings (omitted keys keep their values) — read it first with wsp_get_widget. sidebar moves it (added last unless position is given); position is 0-based within its sidebar. Widgets whose type does not expose settings can be moved but not edited.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'widget_id' ), 'properties' => array(
			'widget_id' => $widget_id_prop,
			'instance'  => $instance_prop,
			'sidebar'   => array( 'type' => 'string', 'description' => 'Move to this sidebar. wp_inactive_widgets takes it off the site but keeps settings.' ),
			'position'  => array( 'type' => 'integer', 'minimum' => 0 ),
		) ),
		'callback'    => 'wsp_execute_update_widget',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/update-widget',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_widget', array(
		'description' => 'Removes a widget from the site. force=false (default) moves it to wp_inactive_widgets, keeping its settings (wsp_update_widget with a sidebar restores it). force=true deletes the widget and its settings permanently — cannot be undone.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'widget_id' ), 'properties' => array(
			'widget_id' => $widget_id_prop,
			'force'     => array( 'type' => 'boolean', 'description' => 'Permanently delete. Default false.' ),
		) ),
		'callback'    => 'wsp_execute_delete_widget',
		'capability'  => 'edit_theme_options',
		'enable_key'  => 'wsp/delete-widget',
	) );

	// ---- Site Health, Cron & Error Log ----
	$cron_target = array(
		'hook' => array( 'type' => 'string', 'description' => 'Exact hook name, from wsp_get_cron_events.' ),
		'key'  => array( 'type' => 'string', 'description' => 'Instance key, from wsp_get_cron_events / wsp_get_cron_event. Required only when the hook has several scheduled instances.' ),
	);
	WSP_MCP_Server::register_tool( 'wsp_get_site_health', array(
		'description' => 'Runs WordPress core\'s Site Health tests and returns the Site Health Info data. Returns { counts: { good, recommended, critical }, tests: [{ test, label, status (good | recommended | critical | error), badge, description, actions }] (critical first), skipped: [{ test, label, reason }], info: { <section>: { label, fields: { <name>: { label, value } } } }, info_truncated, info_omitted }. Text is plain. Info leaves out every field core marks private (database credentials, table prefix, paths) and redacts secret-shaped values. include_async=true also runs the slower tests (WordPress.org reachability, loopback, background updates, HTTPS, page cache) — can take several seconds.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'include_async' => array( 'type' => 'boolean', 'description' => 'Also run the slow network tests. Default false.' ),
			'include_info'  => array( 'type' => 'boolean', 'description' => 'Include Site Health Info (versions, server, database, constants, plugins, themes). Default true.' ),
		) ),
		'callback'    => 'wsp_execute_get_site_health',
		'capability'  => 'view_site_health_checks',
		'enable_key'  => 'wsp/get-site-health',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_cron_events', array(
		'description' => 'Lists scheduled WP-Cron events, soonest first. Returns { events: [{ hook, key, next_run (ISO 8601 UTC), seconds_until_run (negative when past due), overdue (>1h past due), schedule: { name, interval_seconds, display, registered } or null for one-off, has_callback (false = orphaned, nothing runs), args (secrets redacted) }], total, returned, overdue_count, cron_disabled (DISABLE_WP_CRON), now, schedules: [{ name, interval_seconds, display }] }.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'hook'  => array( 'type' => 'string', 'description' => 'Case-insensitive substring filter on the hook name.' ),
			'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Default 50.' ),
		) ),
		'callback'    => 'wsp_execute_get_cron_events',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/get-cron-events',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_cron_event', array(
		'description' => 'Inspects one cron hook: every scheduled instance (or just the one matching key) plus the callbacks attached to the hook (function / Class->method and priority). protected=true marks WSP MCP\'s own maintenance tasks, which cannot be unscheduled.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'hook' ), 'properties' => $cron_target ),
		'callback'    => 'wsp_execute_get_cron_event',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/get-cron-event',
	) );
	WSP_MCP_Server::register_tool( 'wsp_run_cron_event', array(
		'description' => 'Runs an existing scheduled cron event immediately, in this request, exactly as wp-cron.php would. A recurring event keeps its next scheduled run; a one-off event is consumed (unscheduled). Returns { success, hook, key, duration_ms, recurring, output, error? }. Refused if no callback is attached to the hook. Only events already scheduled can be run — there is no tool to schedule new ones.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'hook' ), 'properties' => $cron_target ),
		'callback'    => 'wsp_execute_run_cron_event',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/run-cron-event',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_cron_event', array(
		'description' => 'Unschedules a cron event: one instance (by hook, plus key when the hook has several) or, with all=true, every instance of the hook. Use it to clear orphaned events (has_callback: false) left by removed plugins. Unscheduling a core or active-plugin task stops that task until its plugin re-schedules it. WSP MCP\'s own wsp_mcp_* tasks are refused.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'hook' ), 'properties' => $cron_target + array(
			'all' => array( 'type' => 'boolean', 'description' => 'Unschedule every instance of the hook. Default false.' ),
		) ),
		'callback'    => 'wsp_execute_delete_cron_event',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/delete-cron-event',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_error_log', array(
		'description' => 'Returns the most recent lines of the PHP error log, newest last. Reads ONLY the PHP error_log path (where WP_DEBUG_LOG writes) or, failing that, wp-content/debug.log — never any other file. Secrets are redacted (tokens, passwords, salts, URL credentials, JWTs). Returns { path_source, path, size_bytes, modified, lines, returned, truncated, scanned_bytes }, or { path_source: null, reason } when no readable log exists (with how to enable one).',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'lines' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'How many recent (matching) lines. Default 100.' ),
			'grep'  => array( 'type' => 'string', 'description' => 'Only lines containing this text (case-insensitive, plain text).' ),
		) ),
		'callback'    => 'wsp_execute_get_error_log',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/get-error-log',
	) );

	// ---- Revisions (posts, pages, custom post types) ----
	WSP_MCP_Server::register_tool( 'wsp_get_revisions', array(
		'description' => 'Lists the saved revisions of a post, page, or custom post type item, newest first. Returns { post_id, post_type, post_title, total, returned, revisions: [{ id, parent_id, date_gmt, author, is_autosave, title, content_chars, changed_fields (which of title / content / excerpt differ from the live post) }] }. Use a revision id with wsp_get_revision or wsp_restore_revision.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
			'post_id' => array( 'type' => 'integer', 'description' => 'ID of the post, page, or custom post type item.' ),
			'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.' ),
		) ),
		'callback'    => 'wsp_execute_get_revisions',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-revisions',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_revision', array(
		'description' => 'Reads one revision in full: title, content, excerpt, date, author, plus a "current" block with the live post\'s title, content and excerpt so the two can be compared.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer', 'description' => 'Revision ID from wsp_get_revisions.' ),
		) ),
		'callback'    => 'wsp_execute_get_revision',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-revision',
	) );
	WSP_MCP_Server::register_tool( 'wsp_restore_revision', array(
		'description' => 'Rolls a post back to a revision: restores its title, content and excerpt (not status, slug, taxonomies or custom fields). The version that was live is saved as a new revision first, so the restore can be undone. Read the revision with wsp_get_revision before restoring.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer', 'description' => 'Revision ID from wsp_get_revisions.' ),
		) ),
		'callback'    => 'wsp_execute_restore_revision',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/restore-revision',
	) );

	// ---- Post Meta ----
	WSP_MCP_Server::register_tool( 'wsp_get_post_meta', array(
		'description' => 'Reads custom fields (post meta) of a post, page, attachment or custom post type item. With "key": returns { post_id, key, exists, value }. Without "key": returns { post_id, post_type, meta: { <key>: value }, returned, truncated } for all public keys (max 200). Protected keys (starting with an underscore, e.g. _elementor_data, _thumbnail_id) are never returned. For Yoast SEO use the wsp_yoast_* tools.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
			'post_id' => array( 'type' => 'integer', 'description' => 'Post / page / CPT item ID.' ),
			'key'     => array( 'type' => 'string', 'description' => 'Meta key. Omit to list all public meta.' ),
			'single'  => array( 'type' => 'boolean', 'description' => 'With key: true (default) returns one value; false returns an array of every value stored under the key.' ),
		) ),
		'callback'    => 'wsp_execute_get_post_meta',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-post-meta',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_post_meta', array(
		'description' => 'Creates or updates a custom field on a post. value may be a string, number, boolean, array or object (stored serialized). Every string is passed through wp_kses_post, so script tags and event handlers are stripped. Protected keys (leading underscore) are refused. With prev_value, only the row holding that value is changed (for keys with several rows). Returns { success, changed, created, post_id, key, previous_value, value }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'key', 'value' ), 'properties' => array(
			'post_id'    => array( 'type' => 'integer' ),
			'key'        => array( 'type' => 'string', 'description' => 'Letters, digits, _ - : . only.' ),
			'value'      => array( 'description' => 'New value (any JSON type).' ),
			'prev_value' => array( 'description' => 'Optional: only update the row currently holding this value.' ),
		) ),
		'callback'    => 'wsp_execute_update_post_meta',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/update-post-meta',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_post_meta', array(
		'description' => 'Deletes a custom field from a post: every row for the key, or with "value" only rows holding that value. Protected keys (leading underscore) are refused. Returns { success, post_id, key, previous_value }. Not undoable (post meta has no trash) — read the value first.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'key' ), 'properties' => array(
			'post_id' => array( 'type' => 'integer' ),
			'key'     => array( 'type' => 'string' ),
			'value'   => array( 'description' => 'Optional: only delete rows holding this value.' ),
		) ),
		'callback'    => 'wsp_execute_delete_post_meta',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/delete-post-meta',
	) );

	// ---- Blocks (Gutenberg) ----
	$block_id_prop = array( 'id' => array( 'type' => 'integer', 'description' => 'Reusable block (wp_block) ID from wsp_list_blocks.' ) );
	$block_status  = array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) );
	$block_sync    = array( 'type' => 'string', 'enum' => array( 'synced', 'unsynced' ), 'description' => 'synced (default): edits change every post using the block. unsynced: inserted as an independent copy.' );
	WSP_MCP_Server::register_tool( 'wsp_list_blocks', array(
		'description' => 'Lists reusable blocks (the "wp_block" post type: saved synced/unsynced patterns). Returns { blocks: [{ id, title, slug, status, sync_status, modified }], total, returned, pages }. Non-published statuses need permission to edit others\' blocks.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'search'   => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'trash', 'any' ), 'description' => 'Default publish.' ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.' ),
			'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
		) ),
		'callback'    => 'wsp_execute_list_blocks',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/list-blocks',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_block', array(
		'description' => 'Reads one reusable block: title, status, sync_status, full block markup in "content", and "used_in" (up to 50 editable posts that embed it). parse=true also returns the parsed block tree.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $block_id_prop + array(
			'parse' => array( 'type' => 'boolean', 'description' => 'Also return "blocks", the parsed tree. Default false.' ),
		) ),
		'callback'    => 'wsp_execute_get_block',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-block',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_block', array(
		'description' => 'Creates a reusable block. "content" is Gutenberg block markup, e.g. "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->". Markup is sanitized with wp_kses_post (scripts and event handlers are stripped). Default status publish. Returns { success, block }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => array(
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string', 'description' => 'Block markup.' ),
			'status'      => $block_status,
			'slug'        => array( 'type' => 'string' ),
			'sync_status' => $block_sync,
		) ),
		'callback'    => 'wsp_execute_create_block',
		'capability'  => 'publish_posts',
		'enable_key'  => 'wsp/create-block',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_block', array(
		'description' => 'Updates a reusable block (only the fields you pass). For a synced block this changes every post that embeds it — check "used_in" via wsp_get_block first. Markup is sanitized with wp_kses_post. Returns { success, block }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $block_id_prop + array(
			'title'       => array( 'type' => 'string' ),
			'content'     => array( 'type' => 'string', 'description' => 'New block markup (replaces the old).' ),
			'status'      => $block_status,
			'sync_status' => $block_sync,
		) ),
		'callback'    => 'wsp_execute_update_block',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/update-block',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_block', array(
		'description' => 'Moves a reusable block to the trash (restorable), or with force=true deletes it permanently. Returns { success, id, permanent, used_in, warning } — posts still embedding the block will render nothing for it.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => $block_id_prop + array(
			'force' => array( 'type' => 'boolean', 'description' => 'Delete permanently instead of trashing. Default false.' ),
		) ),
		'callback'    => 'wsp_execute_delete_block',
		'capability'  => 'delete_posts',
		'enable_key'  => 'wsp/delete-block',
	) );
	WSP_MCP_Server::register_tool( 'wsp_list_patterns', array(
		'description' => 'Lists registered block patterns (from WordPress core, the active theme and plugins; not user-saved reusable blocks — use wsp_list_blocks for those). Returns { patterns: [{ name, title, description, categories, keywords, block_types, content? }], total, returned, categories: [{ name, label }] }.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'search'          => array( 'type' => 'string' ),
			'category'        => array( 'type' => 'string', 'description' => 'Pattern category slug.' ),
			'include_content' => array( 'type' => 'boolean', 'description' => 'Include each pattern\'s block markup (large). Default false.' ),
			'limit'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 50.' ),
		) ),
		'callback'    => 'wsp_execute_list_patterns',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/list-patterns',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_post_blocks', array(
		'description' => 'Reads the block tree of a post, page or custom post type item (via parse_blocks). Returns { post_id, post_type, title, has_blocks, block_count, blocks: [{ blockName, attrs, innerHTML, innerContent, innerBlocks }] }. Whitespace-only freeform chunks are dropped. Pass the same shape back to wsp_update_post_blocks.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
			'post_id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_get_post_blocks',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/get-post-blocks',
	) );
	WSP_MCP_Server::register_tool( 'wsp_update_post_blocks', array(
		'description' => 'Replaces a post\'s entire block content. Provide EXACTLY ONE of: "blocks" (array of { blockName, attrs, innerHTML, innerContent, innerBlocks } — the shape wsp_get_post_blocks returns; blockName must be a registered type, see wsp_list_block_types; for a container with inner blocks give innerContent as HTML strings with one null per inner block, e.g. ["<div class=\\"wp-block-group\\">", null, "</div>"]) or "content" (raw block markup string). Markup is sanitized with wp_kses_post. This replaces ALL content, so read the current blocks first; WordPress keeps the previous version as a revision. Returns { success, post_id, block_count, revisions, link }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
			'post_id' => array( 'type' => 'integer' ),
			'blocks'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			'content' => array( 'type' => 'string', 'description' => 'Raw block markup (alternative to blocks).' ),
		) ),
		'callback'    => 'wsp_execute_update_post_blocks',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/update-post-blocks',
	) );
	WSP_MCP_Server::register_tool( 'wsp_list_block_types', array(
		'description' => 'Lists registered block types (core/*, plugin and theme blocks). Returns { block_types: [{ name, title, category, description, parent, is_dynamic, attributes? }], total, returned }. Use it to find valid blockName values and attribute names for wsp_update_post_blocks.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'search'             => array( 'type' => 'string' ),
			'namespace'          => array( 'type' => 'string', 'description' => 'e.g. "core".' ),
			'include_attributes' => array( 'type' => 'boolean', 'description' => 'Include attribute names, types and defaults. Default false.' ),
			'limit'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Default 200.' ),
		) ),
		'callback'    => 'wsp_execute_list_block_types',
		'capability'  => 'edit_posts',
		'enable_key'  => 'wsp/list-block-types',
	) );

	// ---- Redirects & 404 Manager ----
	WSP_MCP_Server::register_tool( 'wsp_list_redirects', array(
		'description' => 'Lists URL redirects, newest first. Returns { redirects: [{ id, source, destination, status_code, external, hits, last_hit, note, created_by, created_at }], total, returned, pages, limit }. "source" is a site-relative path; "destination" is a /path or full URL.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'search'   => array( 'type' => 'string', 'description' => 'Matches source, destination or note.' ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.' ),
			'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
		) ),
		'callback'    => 'wsp_execute_list_redirects',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/list-redirects',
	) );
	WSP_MCP_Server::register_tool( 'wsp_create_redirect', array(
		'description' => 'Creates a redirect that takes effect immediately for every visitor. "source": the old path on this site, e.g. "/old-page" (matched on the path only, case-insensitively, ignoring trailing slashes; no query strings; not "/", /wp-admin, /wp-login.php, /wp-json). "destination": a "/new-page" path or a full http(s) URL — a URL on a DIFFERENT domain is refused unless allow_external=true. status_code 301 (permanent, default; browsers and search engines cache it) or 302 (temporary). Refuses loops and duplicate sources. A redirect overrides any real page at the source path (a warning is returned if one exists). Returns { success, redirect, warning?, notice? }. Tip: use wsp_get_404_logs to find broken URLs worth redirecting.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'source', 'destination' ), 'properties' => array(
			'source'         => array( 'type' => 'string', 'description' => 'Old path, e.g. /old-page' ),
			'destination'    => array( 'type' => 'string', 'description' => 'New /path or full URL.' ),
			'status_code'    => array( 'type' => 'integer', 'enum' => array( 301, 302 ), 'description' => 'Default 301.' ),
			'allow_external' => array( 'type' => 'boolean', 'description' => 'Permit a destination on another domain. Default false.' ),
			'note'           => array( 'type' => 'string', 'description' => 'Optional reminder of why (max 255).' ),
		) ),
		'callback'    => 'wsp_execute_create_redirect',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/create-redirect',
	) );
	WSP_MCP_Server::register_tool( 'wsp_delete_redirect', array(
		'description' => 'Deletes a redirect by id (from wsp_list_redirects). Visitors who cached a 301 in their browser may keep being redirected for a while. Returns { success, deleted }.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
			'id' => array( 'type' => 'integer' ),
		) ),
		'callback'    => 'wsp_execute_delete_redirect',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/delete-redirect',
	) );
	WSP_MCP_Server::register_tool( 'wsp_get_404_logs', array(
		'description' => 'Lists URLs that visitors requested but were not found (404), aggregated per path. Recording is active only while this tool is enabled, so an empty result right after enabling is normal. Returns { entries: [{ id, path, hits, first_seen, last_seen, referrer, user_agent, redirect_id (set when a redirect already covers the path) }], returned, total_paths, total_hits, tracking_since }. Paths only — query strings are stripped and no IP addresses are stored. Entries expire after 30 days.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'limit'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 50.' ),
			'orderby'         => array( 'type' => 'string', 'enum' => array( 'recent', 'hits' ), 'description' => 'recent (default) or hits.' ),
			'search'          => array( 'type' => 'string', 'description' => 'Substring of the path.' ),
			'min_hits'        => array( 'type' => 'integer', 'minimum' => 1 ),
			'days'            => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Only paths seen in the last N days.' ),
			'unresolved_only' => array( 'type' => 'boolean', 'description' => 'Hide paths that already have a redirect.' ),
		) ),
		'callback'    => 'wsp_execute_get_404_logs',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/get-404-logs',
	) );
	WSP_MCP_Server::register_tool( 'wsp_clear_404_logs', array(
		'description' => 'Deletes recorded 404 entries: one by id, or everything with all=true. Returns { success, deleted }.',
		'inputSchema' => array( 'type' => 'object', 'properties' => array(
			'id'  => array( 'type' => 'integer', 'description' => 'Entry id from wsp_get_404_logs.' ),
			'all' => array( 'type' => 'boolean', 'description' => 'Clear the whole log.' ),
		) ),
		'callback'    => 'wsp_execute_clear_404_logs',
		'capability'  => 'manage_options',
		'enable_key'  => 'wsp/clear-404-logs',
	) );

	// ---- Yoast SEO (only when Yoast is active) ----
	if ( function_exists( 'wsp_yoast_is_active' ) && wsp_yoast_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_yoast_get_seo', array(
			'description' => 'Get Yoast SEO title, meta description, and focus keyphrase for a post or page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_yoast_get_seo',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/yoast-get-seo',
		) );
		WSP_MCP_Server::register_tool( 'wsp_yoast_update_seo', array(
			'description' => 'Update Yoast SEO title, meta description, and/or focus keyphrase for a post or page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'               => array( 'type' => 'integer' ),
				'seo_title'        => array( 'type' => 'string' ),
				'meta_description' => array( 'type' => 'string' ),
				'focus_keyphrase'  => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_yoast_update_seo',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/yoast-update-seo',
		) );
	}

	// ---- Rank Math SEO (only when Rank Math is active) ----
	if ( function_exists( 'wsp_rankmath_is_active' ) && wsp_rankmath_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_rankmath_get_seo', array(
			'description' => 'Get Rank Math SEO title, meta description, focus keyword, and SEO score for a post or page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_rankmath_get_seo',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/rankmath-get-seo',
		) );
		WSP_MCP_Server::register_tool( 'wsp_rankmath_update_seo', array(
			'description' => 'Update Rank Math SEO title, meta description, and/or focus keyword for a post or page. Focus keyword accepts multiple comma-separated keywords (first one is the primary). Pass an empty string to clear a field back to the global template.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'               => array( 'type' => 'integer' ),
				'seo_title'        => array( 'type' => 'string' ),
				'meta_description' => array( 'type' => 'string' ),
				'focus_keyword'    => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_rankmath_update_seo',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/rankmath-update-seo',
		) );
	}

	// ---- WooCommerce (only when WooCommerce is active) ----
	if ( class_exists( 'WooCommerce' ) ) {
		WSP_MCP_Server::register_tool( 'wsp_woo_get_products', array(
			'description' => 'List products with filtering and pagination.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'limit'  => array( 'type' => 'integer', 'description' => 'Limit. Default 10.' ),
				'status' => array( 'type' => 'string', 'description' => 'publish | draft | any.' ),
			) ),
			'callback'    => 'wsp_execute_woo_get_products',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-get-products',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_get_product', array(
			'description' => 'Get single product details by ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Product ID.' ),
			) ),
			'callback'    => 'wsp_execute_woo_get_product',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-get-product',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_create_product', array(
			'description' => 'Create a new simple or variable product in the store.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'name', 'regular_price' ), 'properties' => array(
				'name'          => array( 'type' => 'string', 'description' => 'Product name.' ),
				'regular_price' => array( 'type' => 'string', 'description' => 'Regular price.' ),
				'sale_price'    => array( 'type' => 'string', 'description' => 'Sale/discount price (optional).' ),
				'description'   => array( 'type' => 'string', 'description' => 'Product description.' ),
				'sku'           => array( 'type' => 'string', 'description' => 'Unique SKU.' ),
				'status'        => array( 'type' => 'string', 'description' => 'publish | draft. Default draft.' ),
				'type'          => array( 'type' => 'string', 'description' => 'simple | variable. Default simple.' ),
				'image_url'     => array( 'type' => 'string', 'description' => 'Direct image URL to download and set as product featured image.' ),
				'attributes'    => array(
					'type' => 'array',
					'description' => 'Product attributes (any product type; replaces existing). Custom: {"name":"Color","options":["Red","Blue"]}. Global: {"attribute_id":2,"options":["Red"]} or {"taxonomy":"pa_color",...} (missing terms are created). Optional visible (default true) and variation (default true for variable products, false otherwise).',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'name'         => array( 'type' => 'string' ),
							'attribute_id' => array( 'type' => 'integer' ),
							'taxonomy'     => array( 'type' => 'string' ),
							'options'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'visible'      => array( 'type' => 'boolean' ),
							'variation'    => array( 'type' => 'boolean' ),
						)
					)
				),
				'stock_qty'     => array( 'type' => 'integer', 'description' => 'Manage stock quantity.' ),
				'categories'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product category term IDs (see wsp_woo_get_product_categories). Replaces existing.' ),
				'tags'          => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product tag term IDs (see wsp_woo_get_product_tags). Replaces existing.' ),
			) ),
			'callback'    => 'wsp_execute_woo_create_product',
			'capability'  => 'publish_posts',
			'enable_key'  => 'wsp/woo-create-product',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_create_variation', array(
			'description' => 'Creates a variation for an existing variable product.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'parent_id', 'regular_price', 'attributes' ), 'properties' => array(
				'parent_id'     => array( 'type' => 'integer', 'description' => 'The ID of the parent variable product.' ),
				'regular_price' => array( 'type' => 'string', 'description' => 'Variation regular price.' ),
				'sale_price'    => array( 'type' => 'string', 'description' => 'Variation sale/discount price (optional).' ),
				'sku'           => array( 'type' => 'string', 'description' => 'Variation unique SKU.' ),
				'image_url'     => array( 'type' => 'string', 'description' => 'Direct image URL to download for this specific variation.' ),
				'attributes'    => array( 'type' => 'object', 'description' => 'Key-value pairs of attributes, e.g. {"size": "large", "color": "blue"}' ),
			) ),
			'callback'    => 'wsp_execute_woo_create_variation',
			'capability'  => 'publish_posts',
			'enable_key'  => 'wsp/woo-create-variation',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_update_product', array(
			'description' => 'Update an existing product details.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'            => array( 'type' => 'integer', 'description' => 'The ID of the product to update.' ),
				'name'          => array( 'type' => 'string', 'description' => 'Product name.' ),
				'regular_price' => array( 'type' => 'string', 'description' => 'Regular price.' ),
				'sale_price'    => array( 'type' => 'string', 'description' => 'Sale/discount price.' ),
				'description'   => array( 'type' => 'string', 'description' => 'Product description.' ),
				'sku'           => array( 'type' => 'string', 'description' => 'Unique SKU.' ),
				'stock_qty'     => array( 'type' => 'integer', 'description' => 'Manage stock quantity.' ),
				'categories'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product category term IDs (see wsp_woo_get_product_categories). Replaces existing.' ),
				'tags'          => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Product tag term IDs (see wsp_woo_get_product_tags). Replaces existing.' ),
				'attributes'    => array( 'type' => 'array', 'description' => 'Replace product attributes (same format as wsp_woo_create_product).', 'items' => array( 'type' => 'object' ) ),
				'stock_status'  => array( 'type' => 'string', 'description' => 'instock | outofstock.' ),
				'image_url'     => array( 'type' => 'string', 'description' => 'Direct image URL to download and replace featured image.' ),
			) ),
			'callback'    => 'wsp_execute_woo_update_product',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-update-product',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_list_orders', array(
			'description' => 'List recent orders with status filtering.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'limit'  => array( 'type' => 'integer', 'description' => 'Number of orders. Default 10.' ),
				'status' => array( 'type' => 'string', 'description' => 'any | processing | completed | pending.' ),
			) ),
			'callback'    => 'wsp_execute_woo_list_orders',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-list-orders',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_update_order_status', array(
			'description' => 'Update the status of an order.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'status' ), 'properties' => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Order ID.' ),
				'status' => array( 'type' => 'string', 'description' => 'pending | processing | on-hold | completed | cancelled | refunded.' ),
			) ),
			'callback'    => 'wsp_execute_woo_update_order_status',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-update-order-status',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_refund_order', array(
			'description' => 'Create a full or partial refund for an order.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'order_id', 'amount' ), 'properties' => array(
				'order_id' => array( 'type' => 'integer', 'description' => 'The ID of the order to refund.' ),
				'amount'   => array( 'type' => 'string', 'description' => 'Refund amount, e.g. 10.50.' ),
				'reason'   => array( 'type' => 'string', 'description' => 'Reason for refund.' ),
			) ),
			'callback'    => 'wsp_execute_woo_refund_order',
			'capability'  => 'manage_woocommerce',
			'enable_key'  => 'wsp/woo-refund-order',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_create_coupon', array(
			'description' => 'Create a new coupon code (percentage or fixed discount).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'code', 'amount' ), 'properties' => array(
				'code'          => array( 'type' => 'string', 'description' => 'Coupon code name, e.g. SUMMER20.' ),
				'amount'        => array( 'type' => 'string', 'description' => 'Discount amount, e.g. 20 or 15.50.' ),
				'discount_type' => array( 'type' => 'string', 'description' => 'percent | fixed_cart | fixed_product. Default percent.' ),
				'expiry_date'   => array( 'type' => 'string', 'description' => 'Expiry date format YYYY-MM-DD (optional).' ),
			) ),
			'callback'    => 'wsp_execute_woo_create_coupon',
			'capability' => 'manage_woocommerce',
			'enable_key'  => 'wsp/woo-create-coupon',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_list_coupons', array(
			'description' => 'List all active store coupons with usage stats.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'limit' => array( 'type' => 'integer', 'description' => 'Number of coupons to fetch. Default 20.' ),
			) ),
			'callback'    => 'wsp_execute_woo_list_coupons',
			'capability' => 'manage_woocommerce',
			'enable_key'  => 'wsp/woo-list-coupons',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_create_order_note', array(
			'description' => 'Add a note to an existing order (internal or customer-facing).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'note' ), 'properties' => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Order ID.' ),
				'note'      => array( 'type' => 'string', 'description' => 'The note text.' ),
				'is_public' => array( 'type' => 'boolean', 'description' => 'True to make the note visible to the customer (email/account), false for internal only.' ),
			) ),
			'callback'    => 'wsp_execute_woo_create_order_note',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-create-order-note',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_list_customers', array(
			'description' => 'List registered customers with billing details.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'limit' => array( 'type' => 'integer', 'description' => 'Number of customers to list. Default 10.' ),
			) ),
			'callback'    => 'wsp_execute_woo_list_customers',
			'capability'  => 'manage_woocommerce',
			'enable_key'  => 'wsp/woo-list-customers',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_report_sales', array(
			'description' => 'Get sales, orders, net revenue, and average order value reports.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'days' => array( 'type' => 'integer', 'description' => 'Number of past days to report. Default 30.' ),
			) ),
			'callback'    => 'wsp_execute_woo_report_sales',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-report-sales',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_get_low_stock', array(
			'description' => 'Inspect and list products running low on stock.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'threshold' => array( 'type' => 'integer', 'description' => 'Stock alert threshold. Default 10.' ),
			) ),
			'callback'    => 'wsp_execute_woo_get_low_stock',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-get-low-stock',
		) );
		WSP_MCP_Server::register_tool( 'wsp_woo_moderate_review', array(
			'description' => 'Approve, spam, trash, or reply to product reviews.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'action' ), 'properties' => array(
				'id'         => array( 'type' => 'integer', 'description' => 'The review/comment ID.' ),
				'action'     => array( 'type' => 'string', 'description' => 'approve | spam | trash | reply' ),
				'reply_text' => array( 'type' => 'string', 'description' => 'The reply text content (required only for reply action).' ),
			) ),
			'callback'    => 'wsp_execute_woo_moderate_review',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/woo-moderate-review',
		) );
		wsp_mcp_register_defs( wsp_mcp_woo_admin_tool_defs() ); // v2.9.5: delete / taxonomy / attributes / settings / tax / shipping / gateways
	}

	// ---- Elementor (only when Elementor is active) ----
	if ( function_exists( 'wsp_elementor_is_active' ) && wsp_elementor_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_elementor_list_pages', array(
			'description' => 'Lists pages/posts built with Elementor.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'per_page'  => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_list_pages',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-list-pages',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_page', array(
			'description' => 'Get the element tree of an Elementor page by post ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_get_page',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-page',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_element', array(
			'description' => 'Get all settings for a specific element by post ID and element ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'element_id' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_elementor_get_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-element',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_find_element', array(
			'description' => 'Find elements on a page by widget type or settings content.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
				'post_id'     => array( 'type' => 'integer' ),
				'widget_type' => array( 'type' => 'string' ),
				'search'      => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_elementor_find_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-find-element',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_list_templates', array(
			'description' => 'List Elementor saved templates from the library.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'type'     => array( 'type' => 'string' ),
				'per_page' => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_list_templates',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-list-templates',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_update_element', array(
			'description' => 'Update settings for a widget or container by element ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id', 'settings' ), 'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'element_id' => array( 'type' => 'string' ),
				'settings'   => array( 'type' => 'object' ),
			) ),
			'callback'    => 'wsp_execute_elementor_update_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-update-element',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_add_widget', array(
			'description' => 'Add a widget to a container or column on an Elementor page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'widget_type' ), 'properties' => array(
				'post_id'      => array( 'type' => 'integer' ),
				'widget_type'  => array( 'type' => 'string' ),
				'container_id' => array( 'type' => 'string' ),
				'settings'     => array( 'type' => 'object' ),
				'position'     => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_add_widget',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-add-widget',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_add_container', array(
			'description' => 'Add a layout container or section to an Elementor page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
				'post_id'   => array( 'type' => 'integer' ),
				'type'      => array( 'type' => 'string' ),
				'parent_id' => array( 'type' => 'string' ),
				'settings'  => array( 'type' => 'object' ),
				'position'  => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_add_container',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-add-container',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_remove_element', array(
			'description' => 'Remove a widget or container from an Elementor page by element ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'element_id' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_elementor_remove_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-remove-element',
		) );

		// -- Advanced Design Tools (v2.6.5) --
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_active_kit', array(
			'description' => 'Retrieve global fonts, color palette, and layout from the active Elementor kit.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_elementor_get_active_kit',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-active-kit',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_update_active_kit', array(
			'description' => 'Update colors and layout settings in the active Elementor kit.',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'system_colors'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Array of {title, color, _id} color objects.' ),
				'container_width'         => array( 'type' => 'object', 'description' => 'Container width setting object.' ),
				'space_between_widgets'   => array( 'type' => 'string', 'description' => 'Space between widgets value.' ),
			) ),
			'callback'    => 'wsp_execute_elementor_update_active_kit',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/elementor-update-active-kit',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_regenerate_css', array(
			'description' => 'Clear and regenerate all Elementor CSS cache files.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_elementor_regenerate_css',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/elementor-regenerate-css',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_widget_schema', array(
			'description' => 'Get control schema for a widget type — margins, padding, background, typography, etc.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'widget_type' ), 'properties' => array(
				'widget_type' => array( 'type' => 'string', 'description' => 'Elementor widget slug (e.g. heading, button, image, text-editor).' ),
			) ),
			'callback'    => 'wsp_execute_elementor_get_widget_schema',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-widget-schema',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_duplicate_element', array(
			'description' => 'Clone a widget or container with new unique IDs recursively.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'element_id' => array( 'type' => 'string' ),
				'parent_id'  => array( 'type' => 'string', 'description' => 'Optional parent to place the clone into.' ),
				'position'   => array( 'type' => 'integer', 'description' => 'Optional insertion position.' ),
			) ),
			'callback'    => 'wsp_execute_elementor_duplicate_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-duplicate-element',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_move_element', array(
			'description' => 'Reposition an element to a different parent or index position.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
				'post_id'       => array( 'type' => 'integer' ),
				'element_id'    => array( 'type' => 'string' ),
				'new_parent_id' => array( 'type' => 'string', 'description' => 'Target parent element ID (null = root).' ),
				'position'      => array( 'type' => 'integer', 'description' => 'Target index position.' ),
			) ),
			'callback'    => 'wsp_execute_elementor_move_element',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-move-element',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_convert_css', array(
			'description' => 'Parse CSS rules into Elementor-compatible settings structure.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'css' ), 'properties' => array(
				'css' => array( 'type' => 'object', 'description' => 'CSS key-value map (e.g. {"padding": "20px 10px", "background-color": "#ff0000"}).' ),
			) ),
			'callback'    => 'wsp_execute_elementor_convert_css',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-convert-css',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_page_settings', array(
			'description' => 'Read global page config like template, background, and custom CSS.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
			) ),
			'callback'    => 'wsp_execute_elementor_get_page_settings',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-page-settings',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_update_page_settings', array(
			'description' => 'Update page template (canvas/full-width) and page-level settings.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
				'post_id'          => array( 'type' => 'integer' ),
				'page_template'    => array( 'type' => 'string', 'description' => 'elementor_canvas | elementor_header_footer | default.' ),
				'hide_title'       => array( 'type' => 'boolean', 'description' => 'Hide page title.' ),
				'content_width'    => array( 'type' => 'object', 'description' => 'Content width {unit, size}.' ),
				'background_color' => array( 'type' => 'string', 'description' => 'Page background color.' ),
				'settings'         => array( 'type' => 'object', 'description' => 'Additional page settings to merge.' ),
			) ),
			'callback'    => 'wsp_execute_elementor_update_page_settings',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-update-page-settings',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_copy_styles', array(
			'description' => 'Copy style settings from a source element to a destination element.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_id', 'source_id', 'destination_id' ), 'properties' => array(
				'post_id'        => array( 'type' => 'integer' ),
				'source_id'      => array( 'type' => 'string', 'description' => 'Element ID to copy styles from.' ),
				'destination_id' => array( 'type' => 'string', 'description' => 'Element ID to apply styles to.' ),
				'merge'          => array( 'type' => 'boolean', 'description' => 'Merge with existing settings (true) or overwrite (false). Default: false.' ),
			) ),
			'callback'    => 'wsp_execute_elementor_copy_styles',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-copy-styles',
		) );
		WSP_MCP_Server::register_tool( 'wsp_elementor_get_breakpoints', array(
			'description' => 'Read responsive breakpoint values from Elementor configuration.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_elementor_get_breakpoints',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/elementor-get-breakpoints',
		) );
	}

	// ---- Advanced Custom Fields (only when ACF is active) ----
	if ( function_exists( 'wsp_acf_is_active' ) && wsp_acf_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_acf_list_field_groups', array(
			'description' => 'List all registered custom field groups.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_acf_list_field_groups',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-list-field-groups',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_field_group', array(
			'description' => 'Retrieve configuration parameters of a specific field group by key.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'key' ), 'properties' => array(
				'key' => array( 'type' => 'string', 'description' => 'Field group key (e.g. group_60a5b2).' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_field_group',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-get-field-group',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_create_field_group', array(
			'description' => 'Create a brand new custom field group configuration.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array(
				'title'    => array( 'type' => 'string' ),
				'key'      => array( 'type' => 'string', 'description' => 'Optional group key structure.' ),
				'fields'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'location' => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
				'active'   => array( 'type' => 'boolean' ),
			) ),
			'callback'    => 'wsp_execute_acf_create_field_group',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-create-field-group',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_update_field_group', array(
			'description' => 'Update location rules or active parameters of a field group.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'key' ), 'properties' => array(
				'key'      => array( 'type' => 'string' ),
				'title'    => array( 'type' => 'string' ),
				'location' => array( 'type' => 'array', 'items' => array( 'type' => 'array' ) ),
				'active'   => array( 'type' => 'boolean' ),
			) ),
			'callback'    => 'wsp_execute_acf_update_field_group',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-update-field-group',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_delete_field_group', array(
			'description' => 'Permanently delete or trash a field group by its key.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'key' ), 'properties' => array(
				'key' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_delete_field_group',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-delete-field-group',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_import_field_groups', array(
			'description' => 'Import field groups config structure programmatically from JSON parameters.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'json_data' ), 'properties' => array(
				'json_data' => array( 'type' => 'string', 'description' => 'JSON payload of group configurations.' ),
			) ),
			'callback'    => 'wsp_execute_acf_import_field_groups',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-import-field-groups',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_list_fields', array(
			'description' => 'List all custom fields declared inside a specific field group.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'group_key' ), 'properties' => array(
				'group_key' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_list_fields',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-list-fields',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_field', array(
			'description' => 'Get full configurations and rules of a single field key.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_key' ), 'properties' => array(
				'field_key' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_field',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-get-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_create_field', array(
			'description' => 'Inject a new custom field config inside an existing group configuration.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_config' ), 'properties' => array(
				'field_config' => array( 'type' => 'object', 'description' => 'Field parameter details (name, type, parent, instructions etc.)' ),
			) ),
			'callback'    => 'wsp_execute_acf_create_field',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-create-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_update_field_config', array(
			'description' => 'Modify attribute settings for a single field configuration parameters.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_key', 'config' ), 'properties' => array(
				'field_key' => array( 'type' => 'string' ),
				'config'    => array( 'type' => 'object' ),
			) ),
			'callback'    => 'wsp_execute_acf_update_field_config',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-update-field-config',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_delete_field', array(
			'description' => 'Delete config mapping of a field configuration.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_key' ), 'properties' => array(
				'field_key' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_delete_field',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-delete-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_duplicate_field', array(
			'description' => 'Duplicate an existing field config mapping.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_key' ), 'properties' => array(
				'field_key' => array( 'type' => 'string' ),
				'parent_id' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_duplicate_field',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-duplicate-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_sync_fields', array(
			'description' => 'Sync database structures with local filesystem JSON config records.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_acf_sync_fields',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-sync-fields',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_value_deep', array(
			'description' => 'Deep read custom field values with dot-notation pathing support (e.g. key.0.subkey).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id', 'field_name' ), 'properties' => array(
				'target_id'   => array( 'type' => 'string', 'description' => 'Target selector or ID (e.g. 101, "options", "user_1", "term_5")' ),
				'target_type' => array( 'type' => 'string', 'description' => 'post | page | user | term | option' ),
				'field_name'  => array( 'type' => 'string' ),
				'path'        => array( 'type' => 'string', 'description' => 'Dot-notation nested index selector (e.g. "repeater.0.text_field")' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_value_deep',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-get-value-deep',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_update_value_deep', array(
			'description' => 'Deep write custom field values supporting array indices with dot-notation (e.g. repeater.0.key).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id', 'field_name', 'value' ), 'properties' => array(
				'target_id'   => array( 'type' => 'string' ),
				'target_type' => array( 'type' => 'string' ),
				'field_name'  => array( 'type' => 'string' ),
				'path'        => array( 'type' => 'string' ),
				'value'       => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_update_value_deep',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-update-value-deep',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_delete_value', array(
			'description' => 'Delete specific key field metadata value.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id', 'field_name' ), 'properties' => array(
				'target_id'   => array( 'type' => 'string' ),
				'target_type' => array( 'type' => 'string' ),
				'field_name'  => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_delete_value',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-delete-value',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_all_values', array(
			'description' => 'Get all raw field values mapped on any object.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id' ), 'properties' => array(
				'target_id'   => array( 'type' => 'string' ),
				'target_type' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_all_values',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-get-all-values',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_bulk_update_values', array(
			'description' => 'Bulk update array values instantly.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id', 'fields' ), 'properties' => array(
				'target_id'   => array( 'type' => 'string' ),
				'target_type' => array( 'type' => 'string' ),
				'fields'      => array( 'type' => 'object', 'description' => 'Key-value maps of fields structure.' ),
			) ),
			'callback'    => 'wsp_execute_acf_bulk_update_values',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-bulk-update-values',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_field_object', array(
			'description' => 'Return both config parameter object and loaded values.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'target_id', 'field_selector' ), 'properties' => array(
				'target_id'      => array( 'type' => 'string' ),
				'target_type'    => array( 'type' => 'string' ),
				'field_selector' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_field_object',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-get-field-object',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_list_post_types', array(
			'description' => 'List registered post types.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_acf_list_post_types',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-list-post-types',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_create_post_type', array(
			'description' => 'Programmatically register brand new WordPress Post Type.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'post_type_slug', 'singular_name', 'plural_name' ), 'properties' => array(
				'post_type_slug' => array( 'type' => 'string' ),
				'singular_name'  => array( 'type' => 'string' ),
				'plural_name'    => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_create_post_type',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-create-post-type',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_list_taxonomies', array(
			'description' => 'List taxonomies structure.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_acf_list_taxonomies',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-list-taxonomies',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_create_taxonomy', array(
			'description' => 'Programmatically register brand new WordPress taxonomy.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'taxonomy_slug', 'singular_name', 'plural_name', 'post_types' ), 'properties' => array(
				'taxonomy_slug' => array( 'type' => 'string' ),
				'singular_name' => array( 'type' => 'string' ),
				'plural_name'   => array( 'type' => 'string' ),
				'post_types'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			) ),
			'callback'    => 'wsp_execute_acf_create_taxonomy',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-create-taxonomy',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_list_options_pages', array(
			'description' => 'List registered global options views.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_acf_list_options_pages',
			'capability'  => 'edit_posts',
			'enable_key'  => 'wsp/acf-list-options-pages',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_create_options_page', array(
			'description' => 'Programmatically register global ACF Options Page.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'page_title' ), 'properties' => array(
				'page_title' => array( 'type' => 'string' ),
				'menu_title' => array( 'type' => 'string' ),
				'menu_slug'  => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_create_options_page',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-create-options-page',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_get_option_value', array(
			'description' => 'Read global option value metadata.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_name' ), 'properties' => array(
				'field_name' => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_get_option_value',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-get-option-value',
		) );
		WSP_MCP_Server::register_tool( 'wsp_acf_update_option_value', array(
			'description' => 'Write option values globally.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'field_name', 'value' ), 'properties' => array(
				'field_name' => array( 'type' => 'string' ),
				'value'      => array( 'type' => 'string' ),
			) ),
			'callback'    => 'wsp_execute_acf_update_option_value',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/acf-update-option-value',
		) );
	}


	// ---- Ultimate Addons for Elementor ----
	if ( function_exists( 'wsp_uae_is_active' ) && wsp_uae_is_active() ) {
		$uae_tools = array(
			'widgets_activate' => array('cap'=>'manage_options', 'schema'=>array('required'=>array('widget_slug'),'properties'=>array('widget_slug'=>array('type'=>'string','description'=>'Widget slug to activate')))),
			'widgets_deactivate' => array('cap'=>'manage_options', 'schema'=>array('required'=>array('widget_slug'),'properties'=>array('widget_slug'=>array('type'=>'string','description'=>'Widget slug to deactivate')))),
			'widgets_list' => array('cap'=>'edit_posts', 'schema'=>array()),
			'widgets_bulk_toggle' => array('cap'=>'manage_options', 'schema'=>array('properties'=>array('disable_all'=>array('type'=>'boolean','description'=>'True to disable all, false to enable all')))),
			'widgets_deactivate_unused' => array('cap'=>'manage_options', 'schema'=>array()),
			'widgets_get_usage' => array('cap'=>'edit_posts', 'schema'=>array()),
			'templates_list' => array('cap'=>'edit_posts', 'schema'=>array('properties'=>array('type'=>array('type'=>'string'),'per_page'=>array('type'=>'integer')))),
			'templates_create' => array('cap'=>'publish_posts', 'schema'=>array('required'=>array('title'),'properties'=>array('title'=>array('type'=>'string'),'type'=>array('type'=>'string')))),
			'templates_delete' => array('cap'=>'delete_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'templates_duplicate' => array('cap'=>'publish_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'active_get' => array('cap'=>'edit_posts', 'schema'=>array('properties'=>array('type'=>array('type'=>'string'),'per_page'=>array('type'=>'integer')))),
			'templates_get' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'templates_update' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer'),'title'=>array('type'=>'string')))),
			'templates_restore' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'shortcode_render' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('shortcode'),'properties'=>array('shortcode'=>array('type'=>'string')))),
			'pages_list' => array('cap'=>'edit_posts', 'schema'=>array()),
			'pages_create' => array('cap'=>'publish_posts', 'schema'=>array('required'=>array('title'),'properties'=>array('title'=>array('type'=>'string'),'content'=>array('type'=>'string')))),
			'pages_delete' => array('cap'=>'delete_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'pages_restore' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer')))),
			'pages_update_meta' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id','meta_key','meta_value'),'properties'=>array('id'=>array('type'=>'integer'),'meta_key'=>array('type'=>'string'),'meta_value'=>array('type'=>'string')))),
			'pages_update_status' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('id','status'),'properties'=>array('id'=>array('type'=>'integer'),'status'=>array('type'=>'string')))),
			'builder_get_structure' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer')))),
			'builder_add_section' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer')))),
			'builder_insert_widget' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id','widget_type'),'properties'=>array('post_id'=>array('type'=>'integer'),'widget_type'=>array('type'=>'string')))),
			'builder_update_widget' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id','element_id','settings'),'properties'=>array('post_id'=>array('type'=>'integer'),'element_id'=>array('type'=>'string'),'settings'=>array('type'=>'object')))),
			'builder_remove_element' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id','element_id'),'properties'=>array('post_id'=>array('type'=>'integer'),'element_id'=>array('type'=>'string')))),
			'builder_move_element' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id','element_id','position'),'properties'=>array('post_id'=>array('type'=>'integer'),'element_id'=>array('type'=>'string'),'position'=>array('type'=>'integer')))),
			'builder_add_column' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id'),'properties'=>array('post_id'=>array('type'=>'integer'),'parent_id'=>array('type'=>'string')))),
			'builder_build' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('post_id','json_tree'),'properties'=>array('post_id'=>array('type'=>'integer'),'json_tree'=>array('type'=>'string')))),
			'builder_regenerate_css' => array('cap'=>'manage_options', 'schema'=>array()),
			'maintenance_clear_cache' => array('cap'=>'manage_options', 'schema'=>array()),
			'builder_undo' => array('cap'=>'edit_posts', 'schema'=>array('properties'=>array('post_id'=>array('type'=>'integer','description'=>'Post ID to revert changes for.')))),
			'builder_get_schema' => array('cap'=>'edit_posts', 'schema'=>array('properties'=>array('widget_type'=>array('type'=>'string','description'=>'Elementor widget slug (e.g. heading, hfe-page-title).')))),
			'builder_list_widget_types' => array('cap'=>'edit_posts', 'schema'=>array()),
			'settings_get' => array('cap'=>'manage_options', 'schema'=>array()),
			'settings_update' => array('cap'=>'manage_options', 'schema'=>array('required'=>array('settings'),'properties'=>array('settings'=>array('type'=>'string')))),
			'info_get' => array('cap'=>'edit_posts', 'schema'=>array()),
			'pro_features' => array('cap'=>'edit_posts', 'schema'=>array()),
			'extensions_list' => array('cap'=>'edit_posts', 'schema'=>array()),
			'extensions_toggle' => array('cap'=>'manage_options', 'schema'=>array('required'=>array('extension','status'),'properties'=>array('extension'=>array('type'=>'string'),'status'=>array('type'=>'boolean')))),
			'theme_get_info' => array('cap'=>'edit_posts', 'schema'=>array()),
			'theme_set_method' => array('cap'=>'manage_options', 'schema'=>array('required'=>array('method'),'properties'=>array('method'=>array('type'=>'string')))),
			'design_system_get_tokens' => array('cap'=>'edit_posts', 'schema'=>array()),
			'display_rules_get_locations' => array('cap'=>'edit_posts', 'schema'=>array()),
			'display_rules_update' => array('cap'=>'edit_posts', 'schema'=>array('required'=>array('template_id','rule'),'properties'=>array('template_id'=>array('type'=>'integer'),'rule'=>array('type'=>'string')))),
		);

		foreach ($uae_tools as $slug => $config) {
			if (empty($config['schema'])) {
				$schema = $obj;
			} else {
				$schema = array('type'=>'object');
				if (isset($config['schema']['required'])) {
					$schema['required'] = $config['schema']['required'];
				}
				$schema['properties'] = isset($config['schema']['properties']) ? $config['schema']['properties'] : new stdClass();
			}
			$key = 'uae-' . str_replace('_', '-', $slug);
			WSP_MCP_Server::register_tool( 'wsp_uae_' . $slug, array(
				'description' => 'UAE Tool: ' . str_replace('_', ' ', $slug),
				'inputSchema' => $schema,
				'callback'    => 'wsp_execute_uae_' . $slug,
				'capability'  => $config['cap'],
				'enable_key'  => 'wsp/' . $key,
			) );
		}
	}

	// ---- Gravity Forms (only when Gravity Forms is active) ----
	if ( function_exists( 'wsp_gravity_is_active' ) && wsp_gravity_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_gravity_list_forms', array(
			'description' => 'Lists all Gravity Forms (ID, title, date, active status, entry count).',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_gravity_list_forms',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-list-forms',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_get_form', array(
			'description' => 'Retrieves full JSON structure of a form (fields, labels, types, choices, rules).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_get_form',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-get-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_create_form', array(
			'description' => 'Creates a new Gravity Form structure with title and optional fields.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array(
				'title'       => array( 'type' => 'string', 'description' => 'Form title.' ),
				'description' => array( 'type' => 'string', 'description' => 'Form description.' ),
				'fields'      => array( 'type' => 'array', 'description' => 'Array of field objects per Gravity Forms schema.' ),
				'button_text' => array( 'type' => 'string', 'description' => 'Submit button text. Default: Submit.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_create_form',
			'capability'  => 'gravityforms_create_form',
			'enable_key'  => 'wsp/gravity-create-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_update_form', array(
			'description' => 'Updates form properties, fields, or active status.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'          => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'title'       => array( 'type' => 'string', 'description' => 'New form title.' ),
				'description' => array( 'type' => 'string', 'description' => 'New form description.' ),
				'is_active'   => array( 'type' => 'boolean', 'description' => 'Whether the form is active.' ),
				'fields'      => array( 'type' => 'array', 'description' => 'Updated fields array per Gravity Forms schema.' ),
				'button_text' => array( 'type' => 'string', 'description' => 'Submit button text.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_update_form',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-update-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_delete_form', array(
			'description' => 'Deletes or trashes a Gravity Form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_delete_form',
			'capability'  => 'gravityforms_delete_forms',
			'enable_key'  => 'wsp/gravity-delete-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_list_entries', array(
			'description' => 'Lists submissions/leads for a specific form (paginated).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id'  => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'per_page' => array( 'type' => 'integer', 'description' => 'Number of entries. Default 20.' ),
				'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
				'status'   => array( 'type' => 'string', 'description' => 'active | spam | trash | all.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_list_entries',
			'capability'  => 'gravityforms_view_entries',
			'enable_key'  => 'wsp/gravity-list-entries',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_get_entry', array(
			'description' => 'Retrieves complete submission details by entry ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_get_entry',
			'capability'  => 'gravityforms_view_entries',
			'enable_key'  => 'wsp/gravity-get-entry',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_update_entry', array(
			'description' => 'Updates field values or status (read/unread/starred) inside an entry.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'          => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
				'is_read'     => array( 'type' => 'boolean', 'description' => 'Mark entry as read (true) or unread (false).' ),
				'is_starred'  => array( 'type' => 'boolean', 'description' => 'Star (true) or unstar (false) the entry.' ),
				'status'      => array( 'type' => 'string', 'description' => 'active | spam | trash.' ),
				'fields'      => array( 'type' => 'object', 'description' => 'Key-value map of field IDs to new values.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_update_entry',
			'capability'  => 'gravityforms_edit_entries',
			'enable_key'  => 'wsp/gravity-update-entry',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_delete_entry', array(
			'description' => 'Trashes or permanently deletes an entry.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
				'permanent' => array( 'type' => 'boolean', 'description' => 'True for permanent deletion, false to move to trash. Default false.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_delete_entry',
			'capability'  => 'gravityforms_delete_entries',
			'enable_key'  => 'wsp/gravity-delete-entry',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_get_notifications', array(
			'description' => 'Gets notification settings (emails, feeds) for a form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_get_notifications',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-get-notifications',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_get_confirmations', array(
			'description' => 'Gets confirmation settings (thank-you messages, redirects) for a form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_get_confirmations',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-get-confirmations',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_create_notification', array(
			'description' => 'Creates a new email notification for a form (to, subject, message, from, etc.).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id'   => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'name'      => array( 'type' => 'string', 'description' => 'Notification name.' ),
				'to'        => array( 'type' => 'string', 'description' => 'Send to email. Default: {admin_email}.' ),
				'to_type'   => array( 'type' => 'string', 'description' => 'email | field | hidden. Default: email.' ),
				'subject'   => array( 'type' => 'string', 'description' => 'Email subject. Supports merge tags.' ),
				'message'   => array( 'type' => 'string', 'description' => 'Email body. Default: {all_fields}.' ),
				'from'      => array( 'type' => 'string', 'description' => 'From email.' ),
				'from_name' => array( 'type' => 'string', 'description' => 'From name.' ),
				'reply_to'  => array( 'type' => 'string', 'description' => 'Reply-to email.' ),
				'bcc'       => array( 'type' => 'string', 'description' => 'BCC recipients.' ),
				'event'     => array( 'type' => 'string', 'description' => 'Trigger event. Default: form_submission.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_create_notification',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-create-notification',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_update_notification', array(
			'description' => 'Updates an existing notification (to, subject, message, active status, etc.).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id', 'notification_id' ), 'properties' => array(
				'form_id'         => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'notification_id' => array( 'type' => 'string', 'description' => 'Notification ID to update.' ),
				'name'            => array( 'type' => 'string', 'description' => 'New name.' ),
				'to'              => array( 'type' => 'string', 'description' => 'Recipient email.' ),
				'to_type'         => array( 'type' => 'string', 'description' => 'email | field | hidden.' ),
				'subject'         => array( 'type' => 'string', 'description' => 'Email subject.' ),
				'message'         => array( 'type' => 'string', 'description' => 'Email body.' ),
				'from'            => array( 'type' => 'string', 'description' => 'From email.' ),
				'from_name'       => array( 'type' => 'string', 'description' => 'From name.' ),
				'reply_to'        => array( 'type' => 'string', 'description' => 'Reply-to email.' ),
				'bcc'             => array( 'type' => 'string', 'description' => 'BCC recipients.' ),
				'event'           => array( 'type' => 'string', 'description' => 'Trigger event.' ),
				'is_active'       => array( 'type' => 'boolean', 'description' => 'Enable/disable notification.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_update_notification',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-update-notification',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_delete_notification', array(
			'description' => 'Deletes a notification from a form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id', 'notification_id' ), 'properties' => array(
				'form_id'         => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'notification_id' => array( 'type' => 'string', 'description' => 'Notification ID to delete.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_delete_notification',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-delete-notification',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_create_confirmation', array(
			'description' => 'Creates a confirmation (thank-you message, redirect, or page) for a form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id' ), 'properties' => array(
				'form_id'      => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'name'         => array( 'type' => 'string', 'description' => 'Confirmation name.' ),
				'type'         => array( 'type' => 'string', 'description' => 'message | page | redirect. Default: message.' ),
				'message'      => array( 'type' => 'string', 'description' => 'Thank-you message (for type=message).' ),
				'url'          => array( 'type' => 'string', 'description' => 'Redirect URL (for type=redirect).' ),
				'page_id'      => array( 'type' => 'integer', 'description' => 'WordPress page ID (for type=page).' ),
				'query_string' => array( 'type' => 'string', 'description' => 'URL query string for redirect.' ),
				'is_default'   => array( 'type' => 'boolean', 'description' => 'Set as default confirmation.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_create_confirmation',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-create-confirmation',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_update_confirmation', array(
			'description' => 'Updates an existing confirmation (message, redirect URL, default status, etc.).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id', 'confirmation_id' ), 'properties' => array(
				'form_id'         => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'confirmation_id' => array( 'type' => 'string', 'description' => 'Confirmation ID to update.' ),
				'name'            => array( 'type' => 'string', 'description' => 'New name.' ),
				'type'            => array( 'type' => 'string', 'description' => 'message | page | redirect.' ),
				'message'         => array( 'type' => 'string', 'description' => 'Thank-you message.' ),
				'url'             => array( 'type' => 'string', 'description' => 'Redirect URL.' ),
				'page_id'         => array( 'type' => 'integer', 'description' => 'WP page ID.' ),
				'query_string'    => array( 'type' => 'string', 'description' => 'Query string.' ),
				'is_default'      => array( 'type' => 'boolean', 'description' => 'Set as default.' ),
				'is_active'       => array( 'type' => 'boolean', 'description' => 'Enable/disable confirmation.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_update_confirmation',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-update-confirmation',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_delete_confirmation', array(
			'description' => 'Deletes a confirmation from a form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'form_id', 'confirmation_id' ), 'properties' => array(
				'form_id'         => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'confirmation_id' => array( 'type' => 'string', 'description' => 'Confirmation ID to delete.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_delete_confirmation',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-delete-confirmation',
		) );
		WSP_MCP_Server::register_tool( 'wsp_gravity_update_form_settings', array(
			'description' => 'Updates form-level settings (label placement, restrictions, scheduling, honeypot, etc.).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'                       => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'label_placement'          => array( 'type' => 'string', 'description' => 'top_label | left_label | right_label.' ),
				'description_placement'    => array( 'type' => 'string', 'description' => 'above | below.' ),
				'sub_label_placement'      => array( 'type' => 'string', 'description' => 'above | below | inline.' ),
				'css_class'                => array( 'type' => 'string', 'description' => 'CSS class name for the form wrapper.' ),
				'enable_honeypot'          => array( 'type' => 'boolean', 'description' => 'Enable anti-spam honeypot.' ),
				'enable_animation'         => array( 'type' => 'boolean', 'description' => 'Enable form animation.' ),
				'limit_entries'            => array( 'type' => 'boolean', 'description' => 'Enable entry limit.' ),
				'limit_entries_count'      => array( 'type' => 'integer', 'description' => 'Max number of entries.' ),
				'limit_entries_period'     => array( 'type' => 'string', 'description' => 'day | week | month | year | total.' ),
				'limit_entries_message'    => array( 'type' => 'string', 'description' => 'Message when limit reached.' ),
				'schedule_form'            => array( 'type' => 'boolean', 'description' => 'Enable form scheduling.' ),
				'schedule_start'           => array( 'type' => 'string', 'description' => 'Start date/time (e.g. 2026-01-01 00:00).' ),
				'schedule_end'             => array( 'type' => 'string', 'description' => 'End date/time.' ),
				'schedule_pending_message' => array( 'type' => 'string', 'description' => 'Message before schedule starts.' ),
				'schedule_message'         => array( 'type' => 'string', 'description' => 'Message after schedule ends.' ),
				'require_login'            => array( 'type' => 'boolean', 'description' => 'Require user to be logged in.' ),
				'require_login_message'    => array( 'type' => 'string', 'description' => 'Message if not logged in.' ),
				'save_enabled'             => array( 'type' => 'boolean', 'description' => 'Enable Save & Continue.' ),
			) ),
			'callback'    => 'wsp_execute_gravity_update_form_settings',
			'capability'  => 'gravityforms_edit_forms',
			'enable_key'  => 'wsp/gravity-update-form-settings',
		) );
	}

	// ---- Contact Form 7 (only when CF7 is active) ----
	if ( function_exists( 'wsp_cf7_is_active' ) && wsp_cf7_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_cf7_list_forms', array(
			'description' => 'Lists all Contact Form 7 forms with ID, title, and shortcode.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_cf7_list_forms',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-list-forms',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_get_form', array(
			'description' => 'Retrieves full CF7 form structure (markup, mail config, messages, tags).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_get_form',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-get-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_create_form', array(
			'description' => 'Creates a new Contact Form 7 form with title and optional markup/properties.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array(
				'title'       => array( 'type' => 'string', 'description' => 'Form title.' ),
				'locale'      => array( 'type' => 'string', 'description' => 'Locale code (e.g. en_US).' ),
				'form_markup' => array( 'type' => 'string', 'description' => 'Custom form markup HTML.' ),
				'mail'        => array( 'type' => 'object', 'description' => 'Mail settings key-value pairs.' ),
				'messages'    => array( 'type' => 'object', 'description' => 'Custom messages key-value pairs.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_create_form',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-create-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_update_form', array(
			'description' => 'Updates an existing CF7 form markup, mail settings, or messages.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'                   => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'title'                => array( 'type' => 'string', 'description' => 'New form title.' ),
				'locale'               => array( 'type' => 'string', 'description' => 'Locale code.' ),
				'form_markup'          => array( 'type' => 'string', 'description' => 'Updated form markup.' ),
				'mail'                 => array( 'type' => 'object', 'description' => 'Updated mail settings.' ),
				'mail_2'               => array( 'type' => 'object', 'description' => 'Updated mail (2) settings.' ),
				'messages'             => array( 'type' => 'object', 'description' => 'Updated messages.' ),
				'additional_settings'  => array( 'type' => 'string', 'description' => 'Additional settings text.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_update_form',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-update-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_delete_form', array(
			'description' => 'Trashes or permanently deletes a Contact Form 7 form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'permanent' => array( 'type' => 'boolean', 'description' => 'True for permanent delete, false to move to trash. Default false.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_delete_form',
			'capability'  => 'wpcf7_delete_contact_forms',
			'enable_key'  => 'wsp/cf7-delete-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_list_entries', array(
			'description' => 'Lists Flamingo-stored form submissions (requires Flamingo plugin).',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'form_id'  => array( 'type' => 'integer', 'description' => 'Form ID to filter by.' ),
				'per_page' => array( 'type' => 'integer', 'description' => 'Number of entries. Default 20.' ),
				'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
				'status'   => array( 'type' => 'string', 'description' => 'publish | spam | trash | all.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_list_entries',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-list-entries',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_get_entry', array(
			'description' => 'Retrieves full details of a single Flamingo submission by ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_get_entry',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-get-entry',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_validate_form', array(
			'description' => 'Runs the built-in configuration validator to check for email/syntax errors.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_validate_form',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-validate-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_get_integrations', array(
			'description' => 'Lists active integration modules and reCAPTCHA configuration status.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_cf7_get_integrations',
			'capability'  => 'manage_options',
			'enable_key'  => 'wsp/cf7-get-integrations',
		) );
		WSP_MCP_Server::register_tool( 'wsp_cf7_moderate_entry', array(
			'description' => 'Mark a Flamingo submission as spam, unspam, trash, or untrash.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'action' ), 'properties' => array(
				'id'     => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
				'action' => array( 'type' => 'string', 'description' => 'spam | unspam | trash | untrash.' ),
			) ),
			'callback'    => 'wsp_execute_cf7_moderate_entry',
			'capability'  => 'wpcf7_edit_contact_forms',
			'enable_key'  => 'wsp/cf7-moderate-entry',
		) );
	}

	// ---- WPForms (only when WPForms is active) ----
	if ( function_exists( 'wsp_wpforms_is_active' ) && wsp_wpforms_is_active() ) {
		WSP_MCP_Server::register_tool( 'wsp_wpforms_list_forms', array(
			'description' => 'Lists all WPForms with ID, title, date, status, and field count.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_wpforms_list_forms',
			'capability'  => 'wpforms_view_forms',
			'enable_key'  => 'wsp/wpforms-list-forms',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_get_form', array(
			'description' => 'Retrieves full WPForms structure (fields, settings, payments).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_get_form',
			'capability'  => 'wpforms_view_forms',
			'enable_key'  => 'wsp/wpforms-get-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_describe_schema', array(
			'description' => 'Returns supported field types and editable attributes to guide AI on create/update field actions.',
			'inputSchema' => $obj,
			'callback'    => 'wsp_execute_wpforms_describe_schema',
			'capability'  => 'wpforms_view_forms',
			'enable_key'  => 'wsp/wpforms-describe-schema',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_get_form_stats', array(
			'description' => 'Fetch entry counts and analytics (Pro entry stats).',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Form ID (optional; omit for global stats).' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_get_form_stats',
			'capability'  => 'wpforms_view_forms',
			'enable_key'  => 'wsp/wpforms-get-form-stats',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_create_form', array(
			'description' => 'Creates a new WPForms form with fields, settings, and notification email.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'title' ), 'properties' => array(
				'title'       => array( 'type' => 'string', 'description' => 'Form title.' ),
				'description' => array( 'type' => 'string', 'description' => 'Form description.' ),
				'fields'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Array of field objects.' ),
				'submit_text' => array( 'type' => 'string', 'description' => 'Submit button text. Default: Submit.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_create_form',
			'capability'  => 'wpforms_edit_forms',
			'enable_key'  => 'wsp/wpforms-create-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_update_form_settings', array(
			'description' => 'Update form settings (title, description, submit text, AJAX, anti-spam).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'                     => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'title'                  => array( 'type' => 'string', 'description' => 'Form title.' ),
				'description'            => array( 'type' => 'string', 'description' => 'Form description.' ),
				'submit_text'            => array( 'type' => 'string', 'description' => 'Submit button text.' ),
				'submit_text_processing' => array( 'type' => 'string', 'description' => 'Text shown while submitting.' ),
				'antispam'               => array( 'type' => 'boolean', 'description' => 'Enable anti-spam honeypot.' ),
				'ajax_submit'            => array( 'type' => 'boolean', 'description' => 'Enable AJAX submission.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_update_form_settings',
			'capability'  => 'wpforms_edit_forms',
			'enable_key'  => 'wsp/wpforms-update-form-settings',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_add_field', array(
			'description' => 'Add a new field to an existing form with auto-assigned ID.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'type', 'label' ), 'properties' => array(
				'id'          => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'type'        => array( 'type' => 'string', 'description' => 'Field type (use wpforms-describe-schema to see options).' ),
				'label'       => array( 'type' => 'string', 'description' => 'Field label.' ),
				'required'    => array( 'type' => 'boolean', 'description' => 'Make field required.' ),
				'description' => array( 'type' => 'string', 'description' => 'Field description text.' ),
				'placeholder' => array( 'type' => 'string', 'description' => 'Placeholder text.' ),
				'css'         => array( 'type' => 'string', 'description' => 'CSS class.' ),
				'choices'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Array of {label, value} for choice fields.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_add_field',
			'capability'  => 'wpforms_edit_forms',
			'enable_key'  => 'wsp/wpforms-add-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_update_field', array(
			'description' => 'Update a field label, description, required status, or choices.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id', 'field_id' ), 'properties' => array(
				'id'          => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'field_id'    => array( 'type' => 'string', 'description' => 'Field ID (e.g. "0", "1").' ),
				'label'       => array( 'type' => 'string', 'description' => 'Field label.' ),
				'required'    => array( 'type' => 'boolean', 'description' => 'Make field required.' ),
				'description' => array( 'type' => 'string', 'description' => 'Field description.' ),
				'placeholder' => array( 'type' => 'string', 'description' => 'Placeholder text.' ),
				'css'         => array( 'type' => 'string', 'description' => 'CSS class.' ),
				'choices'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Array of {label, value} for choice fields.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_update_field',
			'capability'  => 'wpforms_edit_forms',
			'enable_key'  => 'wsp/wpforms-update-field',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_delete_form', array(
			'description' => 'Trashes or permanently deletes a WPForms form.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Form ID.' ),
				'permanent' => array( 'type' => 'boolean', 'description' => 'True for permanent deletion. Default false.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_delete_form',
			'capability'  => 'wpforms_edit_forms',
			'enable_key'  => 'wsp/wpforms-delete-form',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_list_entries', array(
			'description' => 'Lists submission entries for a form (requires WPForms Pro).',
			'inputSchema' => array( 'type' => 'object', 'properties' => array(
				'id'       => array( 'type' => 'integer', 'description' => 'Form ID to filter by.' ),
				'per_page' => array( 'type' => 'integer', 'description' => 'Number of entries. Default 20.' ),
				'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
				'status'   => array( 'type' => 'string', 'description' => 'publish | trash | all.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_list_entries',
			'capability'  => 'wpforms_view_entries',
			'enable_key'  => 'wsp/wpforms-list-entries',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_get_entry', array(
			'description' => 'Retrieves full details and field values of a single entry.',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id' => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_get_entry',
			'capability'  => 'wpforms_view_entries',
			'enable_key'  => 'wsp/wpforms-get-entry',
		) );
		WSP_MCP_Server::register_tool( 'wsp_wpforms_delete_entry', array(
			'description' => 'Trashes or permanently deletes a submission entry (Pro).',
			'inputSchema' => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
				'id'        => array( 'type' => 'integer', 'description' => 'Entry ID.' ),
				'permanent' => array( 'type' => 'boolean', 'description' => 'True for permanent deletion. Default false.' ),
			) ),
			'callback'    => 'wsp_execute_wpforms_delete_entry',
			'capability'  => 'wpforms_edit_entries',
			'enable_key'  => 'wsp/wpforms-delete-entry',
		) );
	}

	/**
	 * Allow add-ons to register additional native MCP tools.
	 *
	 * @param string $server_class The WSP_MCP_Server class name.
	 */
	do_action( 'wsp_mcp_register_tools', 'WSP_MCP_Server' );
}
