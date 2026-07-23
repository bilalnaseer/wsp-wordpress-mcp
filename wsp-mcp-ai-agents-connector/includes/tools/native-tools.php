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
		'description' => 'Creates a new category.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
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
		'description' => 'Upload an image or file from a URL directly into the WordPress media library.',
		'inputSchema' => array( 'type' => 'object', 'required' => array( 'url' ), 'properties' => array(
			'url'      => array( 'type' => 'string', 'description' => 'Source URL of the file to upload.' ),
			'filename' => array( 'type' => 'string', 'description' => 'Optional destination filename.' ),
			'title'    => array( 'type' => 'string' ),
			'alt'      => array( 'type' => 'string' ),
			'caption'  => array( 'type' => 'string' ),
			'post_id'  => array( 'type' => 'integer', 'description' => 'Optional post ID to attach the media to.' ),
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
		'description' => 'Lists active plugins with name, version, and author.',
		'inputSchema' => $obj,
		'callback'    => 'wsp_execute_get_plugins',
		'capability'  => 'activate_plugins',
		'enable_key'  => 'wsp/get-plugins',
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
					'description' => 'Attributes for variable products. Array of objects containing "name" and "options" array. E.g. [{"name": "color", "options": ["Red", "Blue"]}]',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'name'    => array( 'type' => 'string' ),
							'options' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						)
					)
				),
				'stock_qty'     => array( 'type' => 'integer', 'description' => 'Manage stock quantity.' ),
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
