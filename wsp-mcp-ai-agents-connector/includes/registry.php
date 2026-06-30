<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Helper to check if Advanced Custom Fields is active.
 * Defined here defensively in case registry.php loads before acf.php.
 */
if ( ! function_exists( 'wsp_acf_is_active' ) ) {
    function wsp_acf_is_active() {
        return class_exists( 'ACF' ) || function_exists( 'get_field' );
    }
}

function wsp_mcp_ability_registry() {
    $abilities = array(
        // POSTS
        'wsp/get-posts'    => array( 'label' => 'Read Posts',    'description' => 'List published blog posts (title, URL, date, excerpt, categories, tags).', 'group' => 'Posts',    'access' => 'read',  'default' => true  ),
        'wsp/create-post'  => array( 'label' => 'Create Post',   'description' => 'Create a new blog post (title, content, status, categories, tags, slug).', 'group' => 'Posts',    'access' => 'write', 'default' => false ),
        'wsp/update-post'  => array( 'label' => 'Update Post',   'description' => 'Update an existing post by ID.',                                            'group' => 'Posts',    'access' => 'write', 'default' => false ),
        'wsp/delete-post'  => array( 'label' => 'Delete Post',   'description' => 'Move a post to trash by ID.',                                               'group' => 'Posts',    'access' => 'write', 'default' => false ),
        // PAGES
        'wsp/get-pages'    => array( 'label' => 'Read Pages',    'description' => 'List published pages (title, URL, parent, status).',                        'group' => 'Pages',    'access' => 'read',  'default' => true  ),
        'wsp/create-page'  => array( 'label' => 'Create Page',   'description' => 'Create a new WordPress page (title, content, status, parent, slug).',       'group' => 'Pages',    'access' => 'write', 'default' => false ),
        'wsp/update-page'  => array( 'label' => 'Update Page',   'description' => 'Update an existing page by ID.',                                            'group' => 'Pages',    'access' => 'write', 'default' => false ),
        'wsp/delete-page'  => array( 'label' => 'Delete Page',   'description' => 'Move a page to trash by ID.',                                               'group' => 'Pages',    'access' => 'write', 'default' => false ),
        // TAXONOMY
        'wsp/get-categories'  => array( 'label' => 'Read Categories', 'description' => 'List all post categories with IDs, slugs, and post counts.', 'group' => 'Taxonomy', 'access' => 'read',  'default' => true  ),
        'wsp/create-category' => array( 'label' => 'Create Category', 'description' => 'Create a new post category.',                                'group' => 'Taxonomy', 'access' => 'write', 'default' => false ),
        'wsp/get-tags'        => array( 'label' => 'Read Tags',       'description' => 'List all post tags with IDs, slugs, and post counts.',       'group' => 'Taxonomy', 'access' => 'read',  'default' => true  ),
        'wsp/create-tag'      => array( 'label' => 'Create Tag',      'description' => 'Create a new post tag.',                                     'group' => 'Taxonomy', 'access' => 'write', 'default' => false ),
        // COMMENTS
        'wsp/get-comments'    => array( 'label' => 'Read Comments',   'description' => 'List comments with author, status, and content snippet.',    'group' => 'Comments', 'access' => 'read',  'default' => false ),
        'wsp/approve-comment' => array( 'label' => 'Approve Comment', 'description' => 'Approve a pending comment by ID.',                           'group' => 'Comments', 'access' => 'write', 'default' => false ),
        'wsp/delete-comment'  => array( 'label' => 'Delete Comment',  'description' => 'Move a comment to trash by ID.',                             'group' => 'Comments', 'access' => 'write', 'default' => false ),
        // MEDIA
        'wsp/get-media'       => array( 'label' => 'Read Media',      'description' => 'List media library items (title, URL, MIME type, date).',    'group' => 'Media',    'access' => 'read',  'default' => false ),
        // USERS
        'wsp/get-users'       => array( 'label' => 'Read Users',      'description' => 'List users with display name, email, and role.',             'group' => 'Users',    'access' => 'read',  'default' => false ),
        // SEARCH
        'wsp/search'          => array( 'label' => 'Search Content',  'description' => 'Search posts and pages by keyword.',                         'group' => 'Search',   'access' => 'read',  'default' => true  ),
        // SITE
        'wsp/get-site-info'   => array( 'label' => 'Read Site Info',  'description' => 'Return site name, URL, tagline, WP version, and language.', 'group' => 'Site',     'access' => 'read',  'default' => true  ),
        'wsp/get-plugins'     => array( 'label' => 'Read Plugins',    'description' => 'List all active plugins with name, version, and author.',    'group' => 'Site',     'access' => 'read',  'default' => false ),
    );

    if ( wsp_yoast_is_active() ) {
        $abilities += array(
            // YOAST SEO
            'wsp/yoast-get-seo'    => array( 'label' => 'Get Yoast SEO Meta',    'description' => 'Get Yoast SEO title, meta description, and focus keyphrase for a post or page.', 'group' => 'Yoast SEO', 'access' => 'read',  'default' => false ),
            'wsp/yoast-update-seo' => array( 'label' => 'Update Yoast SEO Meta', 'description' => 'Update Yoast SEO title, meta description, and/or focus keyphrase for a post or page.', 'group' => 'Yoast SEO', 'access' => 'write', 'default' => false ),
        );
    }

    if ( class_exists( 'WooCommerce' ) ) {
        $abilities += array(
            // WOOCOMMERCE
            'wsp/woo-get-products'        => array( 'label' => 'List WooCommerce Products',   'description' => 'List store products with filtering and pagination.', 'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-get-product'         => array( 'label' => 'Get Single Product',          'description' => 'Get single product details by ID.',                  'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-create-product'      => array( 'label' => 'Create WooCommerce Product',  'description' => 'Create a new simple or variable product in the store with auto-attributes.', 'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-create-variation'    => array( 'label' => 'Create Product Variation',    'description' => 'Create a new product variation / variant.',          'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-update-product'      => array( 'label' => 'Update WooCommerce Product',  'description' => 'Update an existing product details (prices, stock, description).', 'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-list-orders'         => array( 'label' => 'List WooCommerce Orders',     'description' => 'List recent orders with status filtering.',          'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-update-order-status' => array( 'label' => 'Update WooCommerce Order',    'description' => 'Update the status of an order.',                     'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-refund-order'         => array( 'label' => 'Refund WooCommerce Order',    'description' => 'Create a full or partial refund for an order.',      'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-create-coupon'       => array( 'label' => 'Create WooCommerce Coupon',   'description' => 'Create a new coupon code (percentage or fixed discount).', 'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-list-coupons'        => array( 'label' => 'List WooCommerce Coupons',     'description' => 'List all active store coupons with usage stats.',    'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-create-order-note'   => array( 'label' => 'Create Order Note',           'description' => 'Add a note to an existing order (internal or customer-facing).', 'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
            'wsp/woo-list-customers'      => array( 'label' => 'List Customers',              'description' => 'List registered customers with billing details.',    'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-report-sales'        => array( 'label' => 'Get Sales Report',            'description' => 'Get sales, orders, net revenue, and average order value reports.', 'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-get-low-stock'       => array( 'label' => 'Get Low Stock Alerts',        'description' => 'Inspect and list products running low on stock.',    'group' => 'WooCommerce', 'access' => 'read',  'default' => false ),
            'wsp/woo-moderate-review'     => array( 'label' => 'Moderate Product Reviews',    'description' => 'Approve, spam, trash, or reply to product reviews.',  'group' => 'WooCommerce', 'access' => 'write', 'default' => false ),
        );
    }

    if ( class_exists( '\Elementor\Plugin' ) ) {
        $abilities += array(
            // ELEMENTOR
            'wsp/elementor-list-pages'     => array( 'label' => 'List Elementor Pages',  'description' => 'List pages/posts built with Elementor (title, ID, URL, status).',          'group' => 'Elementor', 'access' => 'read',  'default' => false ),
            'wsp/elementor-get-page'       => array( 'label' => 'Get Page Structure',    'description' => 'Get the element tree of an Elementor page by post ID.',                    'group' => 'Elementor', 'access' => 'read',  'default' => false ),
            'wsp/elementor-get-element'    => array( 'label' => 'Get Element Settings',  'description' => 'Get all settings for a specific element by post ID and element ID.',       'group' => 'Elementor', 'access' => 'read',  'default' => false ),
            'wsp/elementor-find-element'   => array( 'label' => 'Find Element',          'description' => 'Find elements on a page by widget type or settings content search.',       'group' => 'Elementor', 'access' => 'read',  'default' => false ),
            'wsp/elementor-list-templates' => array( 'label' => 'List Templates',        'description' => 'List Elementor saved templates from the library.',                         'group' => 'Elementor', 'access' => 'read',  'default' => false ),
            'wsp/elementor-update-element' => array( 'label' => 'Update Element',        'description' => 'Update settings for a widget or container by element ID.',                 'group' => 'Elementor', 'access' => 'write', 'default' => false ),
            'wsp/elementor-add-widget'     => array( 'label' => 'Add Widget',            'description' => 'Add a widget to a container or column on an Elementor page.',              'group' => 'Elementor', 'access' => 'write', 'default' => false ),
            'wsp/elementor-add-container'  => array( 'label' => 'Add Container',         'description' => 'Add a layout container or section to an Elementor page.',                  'group' => 'Elementor', 'access' => 'write', 'default' => false ),
            'wsp/elementor-remove-element' => array( 'label' => 'Remove Element',        'description' => 'Remove a widget or container from an Elementor page by element ID.',       'group' => 'Elementor', 'access' => 'write', 'default' => false ),
        );
    }

    if ( wsp_acf_is_active() ) {
        $abilities += array(
            // ADVANCED CUSTOM FIELDS
            'wsp/acf-list-field-groups'    => array( 'label' => 'List Field Groups',       'description' => 'List all registered field groups.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-get-field-group'     => array( 'label' => 'Get Field Group',         'description' => 'Get detailed setup of a specific field group.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-create-field-group'  => array( 'label' => 'Create Field Group',      'description' => 'Create a brand new custom field group configuration.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-update-field-group'  => array( 'label' => 'Update Field Group Settings', 'description' => 'Update existing custom field group rules/configurations.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-delete-field-group'  => array( 'label' => 'Delete Field Group',      'description' => 'Delete/trash a field group by its key.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-import-field-groups' => array( 'label' => 'Import Field Groups',     'description' => 'Programmatically import field groups via raw JSON parameters.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-list-fields'         => array( 'label' => 'List Fields inside Group', 'description' => 'List all registered fields configs inside a specific field group.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-get-field'           => array( 'label' => 'Get Field Config Details', 'description' => 'Fetch direct key attributes and parameters for a custom field.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-create-field'        => array( 'label' => 'Create Field Configuration', 'description' => 'Register a new field inside an existing custom group.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-update-field-config' => array( 'label' => 'Update Field Configuration', 'description' => 'Update schema configuration for a custom field.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-delete-field'        => array( 'label' => 'Delete Field Config',     'description' => 'Deletes config parameters for a custom field.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-duplicate-field'     => array( 'label' => 'Duplicate Field Config',  'description' => 'Duplicate an existing field configuration key.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-sync-fields'         => array( 'label' => 'Force Sync Fields JSON',  'description' => 'Force reload and sync schema settings dynamically.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-get-value-deep'      => array( 'label' => 'Get Field Value Deep',    'description' => 'Dot-notation deep access to variables (e.g. repeater.0.subfield).', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-update-value-deep'   => array( 'label' => 'Update Field Value Deep', 'description' => 'Update specific deep metadata locations with dot-notation.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-delete-value'        => array( 'label' => 'Delete Field Value',      'description' => 'Delete specific key field metadata value.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-get-all-values'      => array( 'label' => 'Get All Fields Values',   'description' => 'Get all raw field values mapped on any object.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-bulk-update-values'  => array( 'label' => 'Bulk Update Values',      'description' => 'Bulk update array values instantly.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-get-field-object'    => array( 'label' => 'Get Value & Config Object', 'description' => 'Return both config object and mapped values.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-list-post-types'     => array( 'label' => 'List Registered Post Types', 'description' => 'List post types with active register mappings.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-create-post-type'    => array( 'label' => 'Create Custom Post Type', 'description' => 'Programmatically register brand new WordPress Post Type.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-list-taxonomies'     => array( 'label' => 'List Registered Taxonomies', 'description' => 'List taxonomies structure.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-create-taxonomy'     => array( 'label' => 'Create Custom Taxonomy',  'description' => 'Programmatically register brand new WordPress taxonomy.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-list-options-pages'  => array( 'label' => 'List ACF Options Pages',  'description' => 'List registered global options views.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-create-options-page' => array( 'label' => 'Create Options Page',     'description' => 'Programmatically register global ACF Options Page.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
            'wsp/acf-get-option-value'    => array( 'label' => 'Get Option Value',        'description' => 'Read global option value metadata.', 'group' => 'Advanced Custom Fields', 'access' => 'read',  'default' => false ),
            'wsp/acf-update-option-value' => array( 'label' => 'Update Option Value',     'description' => 'Write option values globally.', 'group' => 'Advanced Custom Fields', 'access' => 'write', 'default' => false ),
        );
    }

    return $abilities;
}

function wsp_mcp_get_settings() {
    $saved    = get_option( WSP_MCP_OPTION, array() );
    $registry = wsp_mcp_ability_registry();
    $settings = array();
    foreach ( $registry as $key => $cfg ) {
        $settings[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : $cfg['default'];
    }
    return $settings;
}

function wsp_mcp_is_enabled( $key ) {
    $s = wsp_mcp_get_settings();
    return ! empty( $s[ $key ] );
}

function wsp_mcp_sanitize_settings( $input ) {
    $clean = array();
    foreach ( wsp_mcp_ability_registry() as $key => $cfg ) {
        $clean[ $key ] = ! empty( $input[ $key ] );
    }
    return $clean;
}

function wsp_mcp_register_settings() {
    register_setting( 'wsp_mcp_settings_group', WSP_MCP_OPTION, array( 'sanitize_callback' => 'wsp_mcp_sanitize_settings' ) );
}
