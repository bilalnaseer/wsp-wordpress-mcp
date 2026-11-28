<?php
/**
 * WooCommerce abilities: products, variations, orders, refunds, coupons, customers, reports and reviews.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Download an image from a URL into the media library and attach it to a post.
 *
 * @param string $url     Image URL.
 * @param int    $post_id Post to attach the image to.
 * @return int|false Attachment ID, or false on failure.
 */
function wsp_woo_sideload_image_by_url( $url, $post_id ) {
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return false;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Scoped SSL bypass only for this specific image download.
    $ssl_bypass = function( $args, $req_url ) use ( $url ) {
        if ( $req_url === $url ) {
            if ( function_exists( 'wp_get_environment_type' ) ) {
                if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
                    $args['sslverify'] = false;
                }
            }
        }
        return $args;
    };
    add_filter( 'http_request_args', $ssl_bypass, 10, 2 );

    $tmp = download_url( $url );

    // Remove the filter immediately so it doesn't affect the rest of the site.
    remove_filter( 'http_request_args', $ssl_bypass, 10 );

    if ( is_wp_error( $tmp ) ) {
        return false;
    }

    $file_array = array(
        'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
        'tmp_name' => $tmp,
    );

    $id = media_handle_sideload( $file_array, $post_id );
    if ( is_wp_error( $id ) ) {
        wp_delete_file( $file_array['tmp_name'] );
        return false;
    }

    return $id;
}

/**
 * Apply attribute definitions to a product object.
 *
 * @param WC_Product $product          Product object.
 * @param array      $attributes_input Attributes with name and options.
 */
function wsp_woo_set_product_attributes( $product, $attributes_input ) {
    if ( ! is_array( $attributes_input ) ) {
        return;
    }

    $product_attributes = array();

    foreach ( $attributes_input as $attr_data ) {
        if ( empty( $attr_data['name'] ) || empty( $attr_data['options'] ) ) {
            continue;
        }

        $attribute = new WC_Product_Attribute();
        $attribute->set_name( sanitize_text_field( $attr_data['name'] ) );
        
        $options = is_array( $attr_data['options'] ) ? $attr_data['options'] : array_map( 'trim', explode( '|', $attr_data['options'] ) );
        $attribute->set_options( array_map( 'sanitize_text_field', $options ) );
        
        $attribute->set_position( 0 );
        $attribute->set_visible( true );
        $attribute->set_variation( true ); 

        $product_attributes[] = $attribute;
    }

    $product->set_attributes( $product_attributes );
}

// ---------------------------------------------
// EXECUTE CALLBACKS
// ---------------------------------------------

/**
 * List products with filtering and pagination, newest first.
 *
 * "total" is the full count of matching products; when "has_more" is true, call again with "next_page"
 * (or "next_offset") to get the rest.
 *
 * MCP tool: `wsp_woo_get_products`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $limit Optional. Products per page. Default 10.
 *     @type string $status Optional. publish | draft | any.
 *     @type int $page Optional. Page number, starting at 1. Default 1.
 *     @type int $offset Optional. Number of products to skip. Overrides page when set.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_products( $input ) {
    $limit  = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    if ( 0 === $limit || $limit < -1 ) {
        $limit = 10; // -1 still means "all".
    }
    $status = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'any';
    $page  = isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1;
    $offset = isset( $input['offset'] ) ? max( 0, intval( $input['offset'] ) ) : null;

    $args = array(
        'limit'    => $limit,
        'status'   => $status,
        // Newest first as before; ID breaks date ties so pages never overlap or skip.
        'orderby'  => array( 'date' => 'DESC', 'ID' => 'DESC' ),
        'paginate' => true,
    );
    // An explicit offset wins over page (WP_Query ignores "paged" when "offset" is set).
    if ( null !== $offset ) {
        $args['offset'] = $offset;
    } else {
        $args['page'] = $page;
        $offset       = $limit > 0 ? ( $page - 1 ) * $limit : 0;
    }

    $query  = wc_get_products( $args );
    $result = array();
    foreach ( $query->products as $p ) {
        $result[] = array(
            'id'            => $p->get_id(),
            'name'          => $p->get_name(),
            'price'         => $p->get_price(),
            'regular_price' => $p->get_regular_price(),
            'status'        => $p->get_status(),
            'sku'           => $p->get_sku(),
            'stock_status'  => $p->get_stock_status(),
        );
    }
    $total    = (int) $query->total;
    $count    = count( $result );
    if ( 0 === $count && $offset > 0 ) {
        // WP_Query skips the found-rows count when a page comes back empty, so a page past
        // the end would report total 0. Ask for the real count with a one-item query.
        $count_query = wc_get_products( array(
            'limit'    => 1,
            'status'   => $status,
            'return'   => 'ids',
            'paginate' => true,
        ) );
        $total = (int) $count_query->total;
        if ( ! isset( $args['offset'] ) ) {
            $query->max_num_pages = $limit > 0 ? (int) ceil( $total / $limit ) : 1;
        }
    }
    $has_more = $limit > 0 && ( $offset + $count ) < $total;

    $response = array(
        'products'    => $result,
        'total'       => $total,
        'count'       => $count,
        'limit'       => $limit,
        'offset'      => $offset,
        'has_more'    => $has_more,
    );
    if ( ! isset( $args['offset'] ) ) {
        $response['page']        = $page;
        $response['total_pages'] = $limit > 0 ? (int) $query->max_num_pages : 1;
    }
    if ( $has_more ) {
        $response['next_offset'] = $offset + $count;
        if ( isset( $response['page'] ) ) {
            $response['next_page'] = $page + 1;
        }
    }
    return $response;
}

/**
 * Get single product details by ID.
 *
 * MCP tool: `wsp_woo_get_product`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Product ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_product( $input ) {
    $id = intval( $input['id'] );
    $p  = wc_get_product( $id );
    if ( ! $p ) return array( 'success' => false, 'error' => 'Product not found.' );

    return array(
        'id'            => $p->get_id(),
        'name'          => $p->get_name(),
        'description'   => wp_strip_all_tags( $p->get_description() ),
        'price'         => $p->get_price(),
        'regular_price' => $p->get_regular_price(),
        'sku'           => $p->get_sku(),
        'status'        => $p->get_status(),
    );
}

/**
 * Create a new simple or variable product in the store.
 *
 * MCP tool: `wsp_woo_create_product`. Requires the `publish_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required. Product name.
 *     @type string $regular_price Required. Regular price.
 *     @type string $sale_price Optional. Sale/discount price (optional).
 *     @type string $description Optional. Full product description (long description).
 *     @type string $short_description Optional. Product short description shown near the price.
 *     @type string $sku Optional. Unique SKU.
 *     @type string $status Optional. publish | draft. Default draft.
 *     @type string $type Optional. simple | variable. Default simple.
 *     @type string $image_url Optional. Direct image URL to download and set as product featured
 *         image.
 *     @type array $attributes Optional. Product attributes (any product type; replaces existing).
 *         Custom: {"name":"Color","options":["Red","Blue"]}. Global:
 *         {"attribute_id":2,"options":["Red"]} or {"taxonomy":"pa_color",...} (missing terms are
 *         created). Optional visible (default true) and variation (default true for variable
 *         products, false otherwise).
 *     @type int $stock_qty Optional. Manage stock quantity.
 *     @type array $categories Optional. Product category term IDs (see
 *         wsp_woo_get_product_categories). Replaces existing.
 *     @type array $tags Optional. Product tag term IDs (see wsp_woo_get_product_tags). Replaces
 *         existing.
 *     @type array $brands Optional. Product brands (product_brand taxonomy) as brand IDs, slugs or
 *         names, e.g. ["FNIRSI"] (see wsp_woo_get_product_brands). Brands must already exist.
 *     @type array $gallery_image_ids Optional. Product gallery images: media library image IDs in
 *         display order (upload with wsp_upload_media_from_url first). The featured image is set
 *         separately with image_url.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_product( $input ) {
    $name        = sanitize_text_field( wp_unslash( $input['name'] ) );
    $price       = sanitize_text_field( wp_unslash( $input['regular_price'] ) );
    $desc        = isset( $input['description'] ) ? wp_kses_post( wp_unslash( $input['description'] ) ) : '';
    $short_desc  = isset( $input['short_description'] ) ? wp_kses_post( wp_unslash( $input['short_description'] ) ) : '';
    $sku         = isset( $input['sku'] ) ? sanitize_text_field( wp_unslash( $input['sku'] ) ) : '';
    $status      = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft';
    $type        = isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'simple';

    if ( 'variable' === $type ) {
        $p = new WC_Product_Variable();
    } else {
        $p = new WC_Product_Simple();
    }

    $p->set_name( $name );
    $p->set_status( $status );
    $p->set_description( $desc );
    if ( '' !== $short_desc ) {
        $p->set_short_description( $short_desc );
    }

    if ( 'variable' !== $type ) {
        $p->set_regular_price( $price );
        if ( ! empty( $input['sale_price'] ) ) {
            $p->set_sale_price( sanitize_text_field( wp_unslash( $input['sale_price'] ) ) );
        }
        if ( isset( $input['stock_qty'] ) ) {
            $p->set_manage_stock( true );
            $p->set_stock_quantity( intval( $input['stock_qty'] ) );
        }
    }

    if ( ! empty( $input['attributes'] ) ) {
        $attrs = wsp_woo_build_attributes( $input['attributes'], 'variable' === $type );
        if ( is_string( $attrs ) ) return array( 'success' => false, 'error' => $attrs );
        $p->set_attributes( $attrs );
    }

    $terms_err = wsp_woo_apply_product_terms( $p, $input );
    if ( $terms_err ) return array( 'success' => false, 'error' => $terms_err );

    // Resolve brands before saving so an unknown brand never leaves a half-created product.
    $brand_ids = null;
    if ( isset( $input['brands'] ) ) {
        $brand_ids = wsp_woo_resolve_brand_ids( $input['brands'] );
        if ( is_string( $brand_ids ) ) return array( 'success' => false, 'error' => $brand_ids );
    }

    if ( isset( $input['gallery_image_ids'] ) ) {
        $gallery = wsp_woo_validate_gallery_ids( $input['gallery_image_ids'] );
        if ( is_string( $gallery ) ) return array( 'success' => false, 'error' => $gallery );
        $p->set_gallery_image_ids( $gallery );
    }

    if ( ! empty( $sku ) ) {
        $p->set_sku( $sku );
    }

    $product_id = $p->save();
    if ( ! $product_id ) return array( 'success' => false, 'error' => 'Could not save product.' );

    if ( null !== $brand_ids ) {
        // WC_Product has no brand setter; brands are plain terms on the product post.
        $set = wp_set_object_terms( $product_id, $brand_ids, 'product_brand' );
        if ( is_wp_error( $set ) ) {
            return array( 'success' => false, 'error' => 'Product ' . $product_id . ' was created but the brand could not be set: ' . $set->get_error_message(), 'id' => $product_id );
        }
    }

    $image_id = false;
    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $product_id );
        if ( $img_id ) {
            $p->set_image_id( $img_id );
            $p->save();
            $image_id = $img_id;
        }
    }

    return array(
        'success'   => true,
        'id'        => $product_id,
        'name'      => $name,
        'type'      => $type,
        'price'     => $p->get_price(),
        'image_id'  => $image_id,
        'categories' => $p->get_category_ids(),
        'tags'       => $p->get_tag_ids(),
        'brands'     => wsp_woo_product_brands( $product_id ),
        'gallery_image_ids' => array_map( 'intval', $p->get_gallery_image_ids() ),
        'permalink' => get_permalink( $product_id )
    );
}

/**
 * Creates a variation for an existing variable product.
 *
 * MCP tool: `wsp_woo_create_variation`. Requires the `publish_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $parent_id Required. The ID of the parent variable product.
 *     @type string $regular_price Required. Variation regular price.
 *     @type array $attributes Required. Key-value pairs of attributes, e.g. {"size": "large",
 *         "color": "blue"}.
 *     @type string $sale_price Optional. Variation sale/discount price (optional).
 *     @type string $sku Optional. Variation unique SKU.
 *     @type string $image_url Optional. Direct image URL to download for this specific variation.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_variation( $input ) {
    $parent_id = intval( $input['parent_id'] );
    $parent    = wc_get_product( $parent_id );
    if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
        return array( 'success' => false, 'error' => 'Parent product must be a variable product.' );
    }

    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );

    if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
        $formatted_attrs = array();
        foreach ( $input['attributes'] as $key => $val ) {
            $attr_key = sanitize_title( $key );
            if ( 0 !== strpos( $attr_key, 'attribute_' ) ) {
                $attr_key = 'attribute_' . $attr_key;
            }
            $formatted_attrs[ $attr_key ] = sanitize_title( $val );
        }
        $variation->set_attributes( $formatted_attrs );
    }

    $variation->set_regular_price( sanitize_text_field( wp_unslash( $input['regular_price'] ) ) );
    if ( ! empty( $input['sale_price'] ) ) {
        $variation->set_sale_price( sanitize_text_field( wp_unslash( $input['sale_price'] ) ) );
    }
    if ( ! empty( $input['sku'] ) ) {
        $variation->set_sku( sanitize_text_field( wp_unslash( $input['sku'] ) ) );
    }

    $variation_id = $variation->save();
    if ( ! $variation_id ) return array( 'success' => false, 'error' => 'Could not save variation.' );

    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $variation_id );
        if ( $img_id ) {
            $variation->set_image_id( $img_id );
            $variation->save();
        }
    }

    return array(
        'success'      => true,
        'variation_id' => $variation_id,
        'parent_id'    => $parent_id,
        'price'        => $variation->get_price(),
    );
}

/**
 * Update an existing product details.
 *
 * MCP tool: `wsp_woo_update_product`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. The ID of the product to update.
 *     @type string $name Optional. Product name.
 *     @type string $regular_price Optional. Regular price.
 *     @type string $sale_price Optional. Sale/discount price.
 *     @type string $description Optional. Full product description (long description).
 *     @type string $short_description Optional. Product short description shown near the price.
 *     @type string $sku Optional. Unique SKU.
 *     @type int $stock_qty Optional. Manage stock quantity.
 *     @type array $categories Optional. Product category term IDs (see
 *         wsp_woo_get_product_categories). Replaces existing.
 *     @type array $tags Optional. Product tag term IDs (see wsp_woo_get_product_tags). Replaces
 *         existing.
 *     @type array $brands Optional. Product brands (product_brand taxonomy) as brand IDs, slugs or
 *         names, e.g. ["FNIRSI"] (see wsp_woo_get_product_brands). Brands must already exist.
 *         Replaces existing; [] removes all brands.
 *     @type array $gallery_image_ids Optional. Product gallery images: media library image IDs in
 *         display order (upload with wsp_upload_media_from_url first). Replaces the whole gallery;
 *         [] removes all gallery images. The featured image is not changed.
 *     @type array $attributes Optional. Replace product attributes (same format as
 *         wsp_woo_create_product).
 *     @type string $stock_status Optional. instock | outofstock.
 *     @type string $image_url Optional. Direct image URL to download and replace featured image.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_update_product( $input ) {
    $id = intval( $input['id'] );
    $p  = wc_get_product( $id );
    if ( ! $p ) {
        return array( 'success' => false, 'error' => 'Product not found.' );
    }

    // Resolve brands first so an unknown brand fails before anything is changed.
    $brand_ids = null;
    if ( isset( $input['brands'] ) ) {
        $brand_ids = wsp_woo_resolve_brand_ids( $input['brands'] );
        if ( is_string( $brand_ids ) ) return array( 'success' => false, 'error' => $brand_ids );
    }
    $gallery = null;
    if ( isset( $input['gallery_image_ids'] ) ) {
        $gallery = wsp_woo_validate_gallery_ids( $input['gallery_image_ids'] );
        if ( is_string( $gallery ) ) return array( 'success' => false, 'error' => $gallery );
    }

    $updated = array();

    if ( null !== $gallery ) {
        $p->set_gallery_image_ids( $gallery );
        $updated['gallery_image_ids'] = $gallery;
    }

    if ( isset( $input['name'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['name'] ) );
        $p->set_name( $value );
        $updated['name'] = $value;
    }

    if ( isset( $input['regular_price'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['regular_price'] ) );
        $p->set_regular_price( $value );
        $updated['regular_price'] = $value;
    }

    if ( isset( $input['sale_price'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['sale_price'] ) );
        $p->set_sale_price( $value );
        $updated['sale_price'] = $value;
    }

    if ( isset( $input['description'] ) ) {
        $value = wp_kses_post( wp_unslash( $input['description'] ) );
        $p->set_description( $value );
        $updated['description'] = $value;
    }

    if ( isset( $input['short_description'] ) ) {
        $value = wp_kses_post( wp_unslash( $input['short_description'] ) );
        $p->set_short_description( $value );
        $updated['short_description'] = $value;
    }

    if ( isset( $input['sku'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['sku'] ) );
        $p->set_sku( $value );
        $updated['sku'] = $value;
    }

    if ( isset( $input['stock_qty'] ) ) {
        $p->set_manage_stock( true );
        $p->set_stock_quantity( intval( $input['stock_qty'] ) );
        $updated['stock_qty'] = intval( $input['stock_qty'] );
    }

    if ( isset( $input['stock_status'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['stock_status'] ) );
        $p->set_stock_status( $value );
        $updated['stock_status'] = $value;
    }

    if ( ! empty( $input['image_url'] ) ) {
        $img_id = wsp_woo_sideload_image_by_url( $input['image_url'], $id );
        if ( $img_id ) {
            $p->set_image_id( $img_id );
            $updated['image_url'] = sanitize_text_field( wp_unslash( $input['image_url'] ) );
        }
    }

    if ( isset( $input['attributes'] ) ) {
        $attrs = wsp_woo_build_attributes( $input['attributes'], $p->is_type( 'variable' ) );
        if ( is_string( $attrs ) ) return array( 'success' => false, 'error' => $attrs );
        $p->set_attributes( $attrs );
        $updated['attributes'] = count( $attrs );
    }

    if ( isset( $input['categories'] ) || isset( $input['tags'] ) ) {
        $terms_err = wsp_woo_apply_product_terms( $p, $input );
        if ( $terms_err ) return array( 'success' => false, 'error' => $terms_err );
        if ( isset( $input['categories'] ) ) $updated['categories'] = $p->get_category_ids();
        if ( isset( $input['tags'] ) )       $updated['tags']       = $p->get_tag_ids();
    }

    if ( empty( $updated ) && null === $brand_ids ) {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }
    // Always save, even for a brands-only update, so WooCommerce clears its product caches.
    $p->save();

    if ( null !== $brand_ids ) {
        $set = wp_set_object_terms( $id, $brand_ids, 'product_brand' );
        if ( is_wp_error( $set ) ) {
            return array( 'success' => false, 'error' => 'Could not set brands: ' . $set->get_error_message(), 'updated' => $updated );
        }
        $updated['brands'] = wsp_woo_product_brands( $id );
    }

    return array(
        'success' => true,
        'id'      => $id,
        'updated' => $updated,
    );
}

/**
 * Create a new coupon code (percentage or fixed discount).
 *
 * MCP tool: `wsp_woo_create_coupon`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $code Required. Coupon code name, e.g. SUMMER20.
 *     @type string $amount Required. Discount amount, e.g. 20 or 15.50.
 *     @type string $discount_type Optional. percent | fixed_cart | fixed_product. Default percent.
 *     @type string $expiry_date Optional. Expiry date format YYYY-MM-DD (optional).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_coupon( $input ) {
    $code   = sanitize_text_field( wp_unslash( $input['code'] ) );
    $amount = sanitize_text_field( wp_unslash( $input['amount'] ) );
    $type   = isset( $input['discount_type'] ) ? sanitize_text_field( wp_unslash( $input['discount_type'] ) ) : 'percent';

    // Fixed: Added validation for discount_type.
    $allowed_types = array( 'percent', 'fixed_cart', 'fixed_product' );
    if ( ! in_array( $type, $allowed_types, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid discount_type. Allowed values: percent, fixed_cart, fixed_product' );
    }

    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_amount( $amount );
    $coupon->set_discount_type( $type );
    
    if ( ! empty( $input['expiry_date'] ) ) {
        $coupon->set_date_expires( strtotime( $input['expiry_date'] ) );
    }

    $coupon_id = $coupon->save();
    if ( ! $coupon_id ) return array( 'success' => false, 'error' => 'Could not save coupon.' );

    return array(
        'success' => true,
        'id'      => $coupon_id,
        'code'    => $code,
        'amount'  => $amount,
        'type'    => $type,
    );
}

/**
 * List all active store coupons with usage stats.
 *
 * MCP tool: `wsp_woo_list_coupons`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $limit Optional. Number of coupons to fetch. Default 20.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_list_coupons( $input ) {
    $limit = isset( $input['limit'] ) ? intval( $input['limit'] ) : 20;
    
    $coupon_posts = get_posts( array(
        'post_type'      => 'shop_coupon',
        'posts_per_page' => $limit,
        'post_status'    => 'any',
    ) );

    $result = array();
    foreach ( $coupon_posts as $post ) {
        $c = new WC_Coupon( $post->ID );
        $result[] = array(
            'id'            => $c->get_id(),
            'code'          => $c->get_code(),
            'amount'        => $c->get_amount(),
            'discount_type' => $c->get_discount_type(),
            'usage_count'   => $c->get_usage_count(),
            'expiry_date'   => $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : 'Never',
        );
    }
    return array( 'coupons' => $result, 'total' => count( $result ) );
}

/**
 * Add a note to an existing order (internal or customer-facing).
 *
 * MCP tool: `wsp_woo_create_order_note`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Order ID.
 *     @type string $note Required. The note text.
 *     @type bool $is_public Optional. True to make the note visible to the customer
 *         (email/account), false for internal only.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_order_note( $input ) {
    $id        = intval( $input['id'] );
    $note      = sanitize_textarea_field( wp_unslash( $input['note'] ) );
    $is_public = ! empty( $input['is_public'] ) ? 1 : 0;

    $order = wc_get_order( $id );
    if ( ! $order ) return array( 'success' => false, 'error' => 'Order not found.' );

    $comment_id = $order->add_order_note( $note, $is_public, true );
    if ( ! $comment_id ) return array( 'success' => false, 'error' => 'Could not add order note.' );

    return array(
        'success'   => true,
        'id'        => $id,
        'note_id'   => $comment_id,
        'is_public' => $is_public,
    );
}

/**
 * List registered customers with billing details.
 *
 * MCP tool: `wsp_woo_list_customers`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $limit Optional. Number of customers to list. Default 10.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_list_customers( $input ) {
    $limit = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    
    $users = get_users( array(
        'role'    => 'customer',
        'number'  => $limit,
        'orderby' => 'registered',
        'order'   => 'DESC',
    ) );

    $result = array();
    foreach ( $users as $u ) {
        $result[] = array(
            'id'            => $u->ID,
            'display_name'  => $u->display_name,
            'email'         => $u->user_email,
            'billing_phone' => get_user_meta( $u->ID, 'billing_phone', true ),
            'order_count'   => wc_get_customer_order_count( $u->ID ),
        );
    }
    return array( 'customers' => $result, 'total' => count( $result ) );
}

/**
 * Get sales, orders, net revenue, and average order value reports.
 *
 * MCP tool: `wsp_woo_report_sales`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $days Optional. Number of past days to report. Default 30.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_report_sales( $input ) {
    $days = isset( $input['days'] ) ? intval( $input['days'] ) : 30;
    $date_after = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

    $orders = wc_get_orders( array(
        'limit'        => -1,
        'status'       => array( 'processing', 'completed' ),
        'date_created' => '>=' . $date_after,
    ) );

    $total_orders  = count( $orders );
    $total_revenue = 0.0;
    $total_tax     = 0.0;
    $total_shipping = 0.0;

    foreach ( $orders as $order ) {
        $total_revenue  += (float) $order->get_total();
        $total_tax      += (float) $order->get_cart_tax() + (float) $order->get_shipping_tax();
        $total_shipping += (float) $order->get_shipping_total();
    }

    $net_revenue = $total_revenue - $total_tax - $total_shipping;
    $avg_order   = $total_orders > 0 ? ( $total_revenue / $total_orders ) : 0.0;

    return array(
        'success'            => true,
        'days'               => $days,
        'orders_count'       => $total_orders,
        'gross_revenue'      => round( $total_revenue, 2 ),
        'net_revenue'        => round( $net_revenue, 2 ),
        'total_tax'          => round( $total_tax, 2 ),
        'total_shipping'     => round( $total_shipping, 2 ),
        'average_order_val'  => round( $avg_order, 2 ),
        'currency'           => get_woocommerce_currency(),
    );
}

/**
 * Inspect and list products running low on stock.
 *
 * MCP tool: `wsp_woo_get_low_stock`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $threshold Optional. Stock alert threshold. Default 10.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_low_stock( $input ) {
    $threshold = isset( $input['threshold'] ) ? intval( $input['threshold'] ) : 10;
    $products = wc_get_products( array(
        'limit'        => -1,
        'stock_status' => 'instock',
        'manage_stock' => true,
    ) );

    $low_stock = array();
    foreach ( $products as $p ) {
        $qty = $p->get_stock_quantity();
        if ( null !== $qty && $qty <= $threshold ) {
            $low_stock[] = array(
                'id'        => $p->get_id(),
                'name'      => $p->get_name(),
                'sku'       => $p->get_sku(),
                'stock_qty' => $qty,
            );
        }
    }

    $out_of_stock_products = wc_get_products( array(
        'limit'        => -1,
        'stock_status' => 'outofstock',
    ) );
    $out_of_stock = array();
    foreach ( $out_of_stock_products as $p ) {
        $out_of_stock[] = array(
            'id'   => $p->get_id(),
            'name' => $p->get_name(),
            'sku'  => $p->get_sku(),
        );
    }

    return array(
        'success'      => true,
        'low_stock'    => $low_stock,
        'out_of_stock' => $out_of_stock,
    );
}

/**
 * Approve, spam, trash, or reply to product reviews.
 *
 * MCP tool: `wsp_woo_moderate_review`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. The review/comment ID.
 *     @type string $action Required. approve | spam | trash | reply.
 *     @type string $reply_text Optional. The reply text content (required only for reply action).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_moderate_review( $input ) {
    $review_id = intval( $input['id'] );
    $action    = sanitize_text_field( wp_unslash( $input['action'] ) ); 
    
    // Fixed: Added validation for action.
    $allowed_actions = array( 'approve', 'spam', 'trash', 'reply' );
    if ( ! in_array( $action, $allowed_actions, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid action. Allowed values: approve, spam, trash, reply' );
    }

    $review = get_comment( $review_id );
    if ( ! $review ) {
        return array( 'success' => false, 'error' => 'Review not found.' );
    }

    if ( 'reply' === $action ) {
        if ( empty( $input['reply_text'] ) ) {
            return array( 'success' => false, 'error' => 'Reply text is required for reply action.' );
        }
        
        $user = wp_get_current_user();
        $comment_id = wp_insert_comment( array(
            'comment_post_ID' => $review->comment_post_ID,
            'comment_author'  => $user->display_name,
            'comment_author_email' => $user->user_email,
            'comment_content' => sanitize_textarea_field( wp_unslash( $input['reply_text'] ) ),
            'comment_parent'  => $review_id,
            'user_id'         => $user->ID,
            'comment_approved' => 1,
        ) );
        
        return array( 'success' => true, 'message' => 'Reply added successfully.', 'reply_id' => $comment_id );
    }

    $status = 'approve';
    if ( 'spam' === $action ) $status = 'spam';
    if ( 'trash' === $action ) $status = 'trash';

    $result = wp_set_comment_status( $review_id, $status );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array( 'success' => true, 'message' => 'Review status updated to: ' . $status );
}

/**
 * List recent orders with status filtering.
 *
 * MCP tool: `wsp_woo_list_orders`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $limit Optional. Number of orders. Default 10.
 *     @type string $status Optional. any | processing | completed | pending.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_list_orders( $input ) {
    $limit  = isset( $input['limit'] ) ? intval( $input['limit'] ) : 10;
    $status = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'any';

    $orders = wc_get_orders( array( 'limit' => $limit, 'status' => $status ) );
    $result = array();
    foreach ( $orders as $o ) {
        $result[] = array(
            'id'            => $o->get_id(),
            'status'        => $o->get_status(),
            'total'         => $o->get_total(),
            'customer_name' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
        );
    }
    return array( 'orders' => $result, 'total' => count( $result ) );
}

/**
 * Update the status of an order.
 *
 * MCP tool: `wsp_woo_update_order_status`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Order ID.
 *     @type string $status Required. pending | processing | on-hold | completed | cancelled |
 *         refunded.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_update_order_status( $input ) {
    $id     = intval( $input['id'] );
    $status = sanitize_text_field( wp_unslash( $input['status'] ) );

    // Fixed: Added validation for order status.
    $allowed = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
    if ( ! in_array( str_replace('wc-', '', $status), $allowed, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid status. Allowed values: ' . implode(', ', $allowed) );
    }

    $o = wc_get_order( $id );
    if ( ! $o ) return array( 'success' => false, 'error' => 'Order not found.' );

    $o->update_status( $status, 'Updated via AI Agent (MCP).' );
    $o->save();

    return array( 'success' => true, 'id' => $id, 'new_status' => $status );
}

/**
 * Create a full or partial refund for an order.
 *
 * MCP tool: `wsp_woo_refund_order`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $order_id Required. The ID of the order to refund.
 *     @type string $amount Required. Refund amount, e.g. 10.50.
 *     @type string $reason Optional. Reason for refund.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_refund_order( $input ) {
    $order_id = intval( $input['order_id'] );
    $amount   = (float) $input['amount'];
    $reason   = isset( $input['reason'] ) ? sanitize_text_field( wp_unslash( $input['reason'] ) ) : 'Refunded via AI Agent';

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return array( 'success' => false, 'error' => 'Order not found.' );
    }

    if ( $amount <= 0 ) {
        return array( 'success' => false, 'error' => 'Refund amount must be greater than 0.' );
    }

    $refund = wc_create_refund( array(
        'amount'         => $amount,
        'reason'         => $reason,
        'order_id'       => $order_id,
        'refund_payment' => true, 
    ) );

    if ( is_wp_error( $refund ) ) {
        return array( 'success' => false, 'error' => $refund->get_error_message() );
    }

    return array(
        'success'   => true,
        'refund_id' => $refund->get_id(),
        'order_id'  => $order_id,
        'amount'    => $amount,
        'reason'    => $reason,
    );
}
?>