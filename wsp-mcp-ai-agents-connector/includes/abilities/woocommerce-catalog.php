<?php
/**
 * WooCommerce catalog management (v2.9.5): delete tools, product categories / tags,
 * global attributes + attribute terms.
 *
 * All callbacks here return the envelope { success, data, error }. Failures are
 * returned in that envelope (not as WP_Error) so the caller always gets one shape.
 * Shared helpers (wsp_woo_ok / wsp_woo_fail / wsp_woo_guard / wsp_woo_rest) are also
 * used by woocommerce-store.php.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------
// SHARED HELPERS
// ---------------------------------------------

/**
 * Build a success envelope for the WooCommerce tools.
 *
 * @param array $data Result data. Default empty.
 * @return array Envelope with success, data and error.
 */
function wsp_woo_ok( $data = array() ) {
	return array( 'success' => true, 'data' => $data, 'error' => null );
}

/**
 * Build a failure envelope for the WooCommerce tools.
 *
 * @param string $message Error message.
 * @return array Envelope with success, data and error.
 */
function wsp_woo_fail( $message ) {
	return array( 'success' => false, 'data' => null, 'error' => (string) $message );
}

/**
 * Returns an error envelope when WooCommerce is missing or the caller lacks $cap; null when OK.
 *
 * @param string $cap Capability the caller needs. Default "manage_woocommerce".
 * @return array|null An error envelope, or null when the caller may proceed.
 */
function wsp_woo_guard( $cap = 'manage_woocommerce' ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return wsp_woo_fail( 'WooCommerce is not active on this site.' );
	}
	if ( ! current_user_can( $cap ) ) {
		return wsp_woo_fail( "You do not have permission ({$cap}) to perform this action." );
	}
	return null;
}

/**
 * Call a WooCommerce REST controller internally (wc/v3).
 *
 * Goes through the controller's own permission callback, validation and sanitization — no raw SQL.
 * Returns data or WP_Error.
 *
 * @param string $method HTTP method.
 * @param string $route  Route below wc/v3, e.g. "/products/categories".
 * @param array  $params Request parameters. Default empty.
 * @return array|WP_Error Response data without links, or an error.
 */
function wsp_woo_rest( $method, $route, $params = array() ) {
	if ( ! function_exists( 'rest_do_request' ) ) {
		return new WP_Error( 'rest_unavailable', 'REST API is unavailable.' );
	}
	$request = new WP_REST_Request( strtoupper( $method ), '/wc/v3/' . ltrim( $route, '/' ) );
	$request->set_query_params( $params );
	if ( 'GET' !== strtoupper( $method ) ) {
		$request->set_body_params( $params );
	}
	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		$err = $response->as_error();
		return new WP_Error( $err->get_error_code(), wp_strip_all_tags( $err->get_error_message() ) );
	}
	return wsp_woo_strip_links( $response->get_data() );
}

/**
 * Recursively drop REST `_links` / `_embedded` — useless noise for an AI client.
 *
 * @param mixed $data REST response data.
 * @return mixed The data without "_links" and "_embedded".
 */
function wsp_woo_strip_links( $data ) {
	if ( ! is_array( $data ) ) return $data;
	unset( $data['_links'], $data['_embedded'] );
	foreach ( $data as $k => $v ) {
		if ( is_array( $v ) ) $data[ $k ] = wsp_woo_strip_links( $v );
	}
	return $data;
}

/**
 * Keep only $keys from an associative row.
 *
 * @param array    $row  Associative row.
 * @param string[] $keys Keys to keep.
 * @return array
 */
function wsp_woo_pick( $row, $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		if ( is_array( $row ) && array_key_exists( $k, $row ) ) {
			$out[ $k ] = $row[ $k ];
		}
	}
	return $out;
}

/**
 * Strict bool for tool inputs (accepts true/"true"/1).
 *
 * @param array  $input   Tool arguments.
 * @param string $key     Argument name.
 * @param bool   $default Value used when the argument is absent. Default false.
 * @return bool
 */
function wsp_woo_flag( $input, $key, $default = false ) {
	if ( ! isset( $input[ $key ] ) ) return $default;
	return filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
}

/**
 * Resolve an array of ids to those that exist in $taxonomy; returns int[] or error string.
 *
 * @param array  $ids      Term IDs from the caller.
 * @param string $taxonomy Taxonomy the terms must belong to.
 * @return int[]|string The valid IDs, or an error message.
 */
function wsp_woo_validate_term_ids( $ids, $taxonomy ) {
	$clean = array();
	foreach ( (array) $ids as $id ) {
		$id = intval( $id );
		if ( $id <= 0 || ! term_exists( $id, $taxonomy ) ) {
			return "Term ID {$id} does not exist in {$taxonomy}.";
		}
		$clean[] = $id;
	}
	return array_values( array_unique( $clean ) );
}

// ---------------------------------------------
// DELETE TOOLS
// ---------------------------------------------

/**
 * Build the result of deleting a product, variation or coupon: trashed or permanently deleted.
 *
 * @param int    $id           Object ID.
 * @param string $name         Object name.
 * @param bool   $force        Whether permanent deletion was requested.
 * @param bool   $deleted      Whether WooCommerce deleted it.
 * @param string $status_after Post status after the call.
 * @return array Result envelope.
 */
function wsp_woo_delete_object_result( $id, $name, $force, $deleted, $status_after ) {
	if ( ! $deleted ) {
		return wsp_woo_fail( 'WooCommerce could not delete this item.' );
	}
	return wsp_woo_ok( array(
		'id'        => $id,
		'name'      => $name,
		'trashed'   => ! $force && 'trash' === $status_after,
		'permanent' => (bool) $force,
		'result'    => $force ? 'permanently_deleted' : 'trashed',
	) );
}

/**
 * Move a product (simple, variable or variation) to the trash, or permanently delete with force=true.
 *
 * MCP tool: `wsp_woo_delete_product`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type bool $force Optional. true = permanent delete. Default false = trash.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_product( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id    = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	$force = wsp_woo_flag( $input, 'force' );
	$p     = $id ? wc_get_product( $id ) : false;
	if ( ! $p ) return wsp_woo_fail( 'Product not found.' );
	if ( ! current_user_can( 'delete_post', $id ) ) return wsp_woo_fail( 'You are not allowed to delete this product.' );
	if ( ! $force && 'trash' === $p->get_status() ) {
		return wsp_woo_fail( 'Product is already in the trash. Pass force=true to delete it permanently.' );
	}
	$name = $p->get_name();
	$type = $p->get_type();
	$ok   = $p->delete( $force );
	$res  = wsp_woo_delete_object_result( $id, $name, $force, $ok, $force ? 'deleted' : get_post_status( $id ) );
	if ( $res['success'] ) {
		$res['data']['type'] = $type;
	}
	return $res;
}

/**
 * Trash or permanently delete (force=true) one variation of a variable product.
 *
 * MCP tool: `wsp_woo_delete_variation`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $product_id Required. Parent product ID.
 *     @type int $variation_id Required. Variation ID.
 *     @type bool $force Optional. true = permanent delete. Default false = trash.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_variation( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$pid   = isset( $input['product_id'] ) ? intval( $input['product_id'] ) : 0;
	$vid   = isset( $input['variation_id'] ) ? intval( $input['variation_id'] ) : 0;
	$force = wsp_woo_flag( $input, 'force' );
	$v     = $vid ? wc_get_product( $vid ) : false;
	if ( ! $v || ! $v->is_type( 'variation' ) ) return wsp_woo_fail( 'Variation not found.' );
	if ( $v->get_parent_id() !== $pid ) return wsp_woo_fail( "Variation {$vid} does not belong to product {$pid}." );
	if ( ! current_user_can( 'delete_post', $pid ) ) return wsp_woo_fail( 'You are not allowed to delete variations of this product.' );
	$name = $v->get_name();
	$ok   = $v->delete( $force );
	$res  = wsp_woo_delete_object_result( $vid, $name, $force, $ok, $force ? 'deleted' : get_post_status( $vid ) );
	if ( $res['success'] ) {
		$res['data']['product_id'] = $pid;
	}
	return $res;
}

/**
 * Trash a coupon, or permanently delete with force=true.
 *
 * MCP tool: `wsp_woo_delete_coupon`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type bool $force Optional. true = permanent delete. Default false = trash.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_coupon( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id    = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	$force = wsp_woo_flag( $input, 'force' );
	if ( ! $id || 'shop_coupon' !== get_post_type( $id ) ) return wsp_woo_fail( 'Coupon not found.' );
	if ( ! current_user_can( 'delete_post', $id ) ) return wsp_woo_fail( 'You are not allowed to delete this coupon.' );
	$coupon = new WC_Coupon( $id );
	$code   = $coupon->get_code();
	if ( ! $force && 'trash' === get_post_status( $id ) ) {
		return wsp_woo_fail( 'Coupon is already in the trash. Pass force=true to delete it permanently.' );
	}
	$ok  = $coupon->delete( $force );
	$res = wsp_woo_delete_object_result( $id, $code, $force, $ok, $force ? 'deleted' : get_post_status( $id ) );
	return $res;
}

/**
 * Delete a taxonomy term. Terms have no trash, so force=true is required.
 *
 * @param array  $input    Tool arguments with "id" and "force".
 * @param string $taxonomy Taxonomy slug.
 * @param string $label    Singular label used in messages.
 * @return array Result envelope.
 */
function wsp_woo_delete_term_tool( $input, $taxonomy, $label ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	$term = $id ? get_term( $id, $taxonomy ) : null;
	if ( ! $term || is_wp_error( $term ) ) return wsp_woo_fail( ucfirst( $label ) . ' not found.' );
	$tax = get_taxonomy( $taxonomy );
	if ( ! current_user_can( 'delete_term', $id ) || ! current_user_can( $tax->cap->delete_terms ) ) {
		return wsp_woo_fail( "You are not allowed to delete this {$label}." );
	}
	if ( ! wsp_woo_flag( $input, 'force' ) ) {
		return wsp_woo_fail( ucfirst( $label ) . 's cannot be moved to the trash. Pass force=true to delete it permanently.' );
	}
	if ( 'product_cat' === $taxonomy && (int) get_option( 'default_product_cat' ) === $id ) {
		return wsp_woo_fail( 'The default product category cannot be deleted.' );
	}
	$name = $term->name;
	$res  = wp_delete_term( $id, $taxonomy );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	if ( ! $res ) return wsp_woo_fail( 'Could not delete the ' . $label . '.' );
	return wsp_woo_ok( array( 'id' => $id, 'name' => $name, 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

/**
 * Permanently delete a product category (force=true required; terms have no trash).
 *
 * Products are not deleted.
 *
 * MCP tool: `wsp_woo_delete_category`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type bool $force Required. Must be true: this item cannot be trashed and is deleted
 *         permanently.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_category( $input ) { return wsp_woo_delete_term_tool( $input, 'product_cat', 'product category' ); }
/**
 * Permanently delete a product tag (force=true required; terms have no trash).
 *
 * MCP tool: `wsp_woo_delete_tag`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type bool $force Required. Must be true: this item cannot be trashed and is deleted
 *         permanently.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_tag( $input )      { return wsp_woo_delete_term_tool( $input, 'product_tag', 'product tag' ); }

// ---------------------------------------------
// PRODUCT CATEGORIES & TAGS
// ---------------------------------------------

/**
 * Reduce a REST category or tag to the fields returned to the client.
 *
 * @param array $t REST term data.
 * @return array
 */
function wsp_woo_term_row( $t ) {
	return array(
		'id'          => (int) $t['id'],
		'name'        => $t['name'],
		'slug'        => $t['slug'],
		'parent'      => isset( $t['parent'] ) ? (int) $t['parent'] : 0,
		'description' => isset( $t['description'] ) ? wp_strip_all_tags( $t['description'] ) : '',
		'count'       => isset( $t['count'] ) ? (int) $t['count'] : 0,
		'image_id'    => ! empty( $t['image']['id'] ) ? (int) $t['image']['id'] : null,
	);
}

/**
 * List product categories or tags through a WooCommerce REST route.
 *
 * @param string $route REST route.
 * @param array  $input Tool arguments: per_page, page and search.
 * @return array Result envelope.
 */
function wsp_woo_list_terms( $route, $input ) {
	$params = array(
		'per_page'   => isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 100,
		'page'       => isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1,
		'hide_empty' => false,
	);
	if ( ! empty( $input['search'] ) ) $params['search'] = sanitize_text_field( wp_unslash( $input['search'] ) );
	$rows = wsp_woo_rest( 'GET', $route, $params );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );
	$out = array_map( 'wsp_woo_term_row', $rows );
	return wsp_woo_ok( array( 'items' => $out, 'total' => count( $out ) ) );
}

/**
 * List product categories (id, name, slug, parent, count, image_id).
 *
 * MCP tool: `wsp_woo_get_product_categories`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $search Optional. Search term.
 *     @type int $per_page Optional. 1-100, default 100.
 *     @type int $page Optional. Page number.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_product_categories( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	return wsp_woo_list_terms( 'products/categories', $input );
}

/**
 * List product tags.
 *
 * MCP tool: `wsp_woo_get_product_tags`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $search Optional. Search term.
 *     @type int $per_page Optional. 1-100, default 100.
 *     @type int $page Optional. Page number.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_product_tags( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	return wsp_woo_list_terms( 'products/tags', $input );
}

/**
 * List product brands (product_brand taxonomy).
 *
 * Read with get_terms() rather than the wc/v3 REST route so it works on every WooCommerce version that
 * registers the taxonomy.
 *
 * MCP tool: `wsp_woo_get_product_brands`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $search Optional. Search term.
 *     @type int $per_page Optional. 1-100, default 100.
 *     @type int $page Optional. Page number.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_product_brands( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		return wsp_woo_fail( 'Brands are not available on this site: the product_brand taxonomy is not registered (WooCommerce 9.6+ includes Brands).' );
	}
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 100;
	$page     = isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1;
	$args     = array( 'taxonomy' => 'product_brand', 'hide_empty' => false );
	if ( ! empty( $input['search'] ) ) $args['search'] = sanitize_text_field( wp_unslash( $input['search'] ) );

	$total = wp_count_terms( $args );
	if ( is_wp_error( $total ) ) return wsp_woo_fail( $total->get_error_message() );
	$terms = get_terms( $args + array( 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page, 'orderby' => 'name', 'order' => 'ASC' ) );
	if ( is_wp_error( $terms ) ) return wsp_woo_fail( $terms->get_error_message() );

	$items = array();
	foreach ( $terms as $t ) {
		$items[] = array(
			'id'          => (int) $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'parent'      => (int) $t->parent,
			'description' => wp_strip_all_tags( $t->description ),
			'count'       => (int) $t->count,
		);
	}
	return wsp_woo_ok( array(
		'items'    => $items,
		'total'    => (int) $total,
		'page'     => $page,
		'per_page' => $per_page,
		'has_more' => ( ( $page - 1 ) * $per_page + count( $items ) ) < (int) $total,
	) );
}

/**
 * Build REST params for a category create/update.
 *
 * @param array $input    Tool arguments.
 * @param bool  $creating True when creating, which requires a name.
 * @return array|string REST parameters, or an error message.
 */
function wsp_woo_category_params( $input, $creating ) {
	$p = array();
	if ( isset( $input['name'] ) )        $p['name']        = sanitize_text_field( wp_unslash( $input['name'] ) );
	if ( isset( $input['slug'] ) )        $p['slug']        = sanitize_title( wp_unslash( $input['slug'] ) );
	if ( isset( $input['description'] ) ) $p['description'] = wp_kses_post( wp_unslash( $input['description'] ) );
	if ( isset( $input['parent'] ) )      $p['parent']      = max( 0, intval( $input['parent'] ) );
	if ( isset( $input['image_id'] ) ) {
		$img = intval( $input['image_id'] );
		if ( $img && 'attachment' !== get_post_type( $img ) ) return 'image_id must be a media library attachment ID.';
		$p['image'] = $img ? array( 'id' => $img ) : null;
	}
	return $p;
}

/**
 * Create a product category.
 *
 * MCP tool: `wsp_woo_create_product_category`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required. Name.
 *     @type string $slug Optional. Slug (optional).
 *     @type string $description Optional. Description (optional).
 *     @type int $parent Optional. Parent category ID.
 *     @type int $image_id Optional. Media library attachment ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_product_category( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	if ( empty( $input['name'] ) ) return wsp_woo_fail( 'name is required.' );
	$p = wsp_woo_category_params( $input, true );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	if ( ! empty( $p['parent'] ) && ! term_exists( $p['parent'], 'product_cat' ) ) return wsp_woo_fail( 'Parent category not found.' );
	$res = wsp_woo_rest( 'POST', 'products/categories', $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_term_row( $res ) );
}

/**
 * Update a product category (name, slug, parent, description, image).
 *
 * MCP tool: `wsp_woo_update_product_category`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type string $name Optional. Name.
 *     @type string $slug Optional. Slug (optional).
 *     @type string $description Optional. Description (optional).
 *     @type int $parent Optional. Parent category ID (0 = top level).
 *     @type int $image_id Optional. Attachment ID (0 removes the image).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_update_product_category( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id || ! term_exists( $id, 'product_cat' ) ) return wsp_woo_fail( 'Product category not found.' );
	$p = wsp_woo_category_params( $input, false );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	if ( isset( $p['parent'] ) && ( $p['parent'] === $id || ( $p['parent'] && ! term_exists( $p['parent'], 'product_cat' ) ) ) ) {
		return wsp_woo_fail( 'Invalid parent category.' );
	}
	$res = wsp_woo_rest( 'PUT', 'products/categories/' . $id, $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_term_row( $res ) );
}

/**
 * Create a product tag.
 *
 * MCP tool: `wsp_woo_create_product_tag`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required. Name.
 *     @type string $slug Optional. Slug (optional).
 *     @type string $description Optional. Description (optional).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_product_tag( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	if ( empty( $input['name'] ) ) return wsp_woo_fail( 'name is required.' );
	$p = array( 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) );
	if ( isset( $input['slug'] ) )        $p['slug']        = sanitize_title( wp_unslash( $input['slug'] ) );
	if ( isset( $input['description'] ) ) $p['description'] = wp_kses_post( wp_unslash( $input['description'] ) );
	$res = wsp_woo_rest( 'POST', 'products/tags', $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_term_row( $res ) );
}

/**
 * Add, replace or remove tags on many products at once.
 *
 * Tags can be given as IDs and/or names (unknown names are created). Max 200 products per call.
 * Returns per-product results.
 *
 * MCP tool: `wsp_woo_assign_product_tags`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type array $product_ids Required. Product IDs (max 200).
 *     @type array $tag_ids Optional. Existing product tag IDs.
 *     @type array $tag_names Optional. Tag names; missing ones are created.
 *     @type string $mode Optional. add (default) | replace | remove.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_assign_product_tags( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$ids = isset( $input['product_ids'] ) && is_array( $input['product_ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', $input['product_ids'] ) ) ) ) : array();
	if ( empty( $ids ) ) return wsp_woo_fail( 'product_ids is required.' );
	if ( count( $ids ) > 200 ) return wsp_woo_fail( 'At most 200 products per call.' );
	$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : 'add';
	if ( ! in_array( $mode, array( 'add', 'replace', 'remove' ), true ) ) return wsp_woo_fail( 'mode must be add, replace or remove.' );

	$tags = array();
	foreach ( (array) ( isset( $input['tag_ids'] ) ? $input['tag_ids'] : array() ) as $tid ) {
		$tid = intval( $tid );
		if ( ! $tid || ! term_exists( $tid, 'product_tag' ) ) return wsp_woo_fail( "Product tag $tid not found." );
		$tags[] = $tid;
	}
	foreach ( (array) ( isset( $input['tag_names'] ) ? $input['tag_names'] : array() ) as $name ) {
		$name = sanitize_text_field( wp_unslash( (string) $name ) );
		if ( '' === $name ) continue;
		$t = term_exists( $name, 'product_tag' );
		if ( ! $t ) {
			if ( 'remove' === $mode ) continue;
			$t = wp_insert_term( $name, 'product_tag' );
			if ( is_wp_error( $t ) ) return wsp_woo_fail( $t->get_error_message() );
		}
		$tags[] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
	}
	$tags = array_values( array_unique( $tags ) );
	if ( empty( $tags ) && 'replace' !== $mode ) return wsp_woo_fail( 'Provide tag_ids and/or tag_names.' );

	$results = array();
	foreach ( $ids as $pid ) {
		$post = get_post( $pid );
		if ( ! $post || 'product' !== $post->post_type ) { $results[] = array( 'id' => $pid, 'ok' => false, 'error' => 'Not a product.' ); continue; }
		if ( ! current_user_can( 'edit_post', $pid ) ) { $results[] = array( 'id' => $pid, 'ok' => false, 'error' => 'Not allowed to edit this product.' ); continue; }
		if ( 'remove' === $mode ) {
			$r = wp_remove_object_terms( $pid, $tags, 'product_tag' );
		} else {
			$r = wp_set_object_terms( $pid, $tags, 'product_tag', 'add' === $mode );
		}
		$results[] = is_wp_error( $r ) ? array( 'id' => $pid, 'ok' => false, 'error' => $r->get_error_message() ) : array( 'id' => $pid, 'ok' => true );
	}
	return wsp_woo_ok( array( 'mode' => $mode, 'tag_ids' => $tags, 'results' => $results ) );
}

/**
 * Update a product tag (name, slug, description).
 *
 * Returns the updated tag.
 *
 * MCP tool: `wsp_woo_update_product_tag`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type string $name Optional. Name.
 *     @type string $slug Optional. Slug (optional).
 *     @type string $description Optional. Description (optional).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_update_product_tag( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id || ! term_exists( $id, 'product_tag' ) ) return wsp_woo_fail( 'Product tag not found.' );
	$p = array();
	if ( isset( $input['name'] ) )        $p['name']        = sanitize_text_field( wp_unslash( $input['name'] ) );
	if ( isset( $input['slug'] ) )        $p['slug']        = sanitize_title( wp_unslash( $input['slug'] ) );
	if ( isset( $input['description'] ) ) $p['description'] = wp_kses_post( wp_unslash( $input['description'] ) );
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	$res = wsp_woo_rest( 'PUT', 'products/tags/' . $id, $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_term_row( $res ) );
}

// ---------------------------------------------
// GLOBAL ATTRIBUTES & TERMS
// ---------------------------------------------

/**
 * Reduce a REST attribute to the fields returned to the client.
 *
 * @param array $a REST attribute data.
 * @return array
 */
function wsp_woo_attribute_row( $a ) {
	return array(
		'id'           => (int) $a['id'],
		'name'         => $a['name'],
		'slug'         => $a['slug'],
		'taxonomy'     => 'pa_' . preg_replace( '/^pa_/', '', $a['slug'] ),
		'type'         => $a['type'],
		'order_by'     => $a['order_by'],
		'has_archives' => (bool) $a['has_archives'],
	);
}

/**
 * Validate tool input and map it to REST parameters for an attribute create or update.
 *
 * @param array $input Tool arguments.
 * @return array|string REST parameters, or an error message.
 */
function wsp_woo_attribute_params( $input ) {
	$p = array();
	if ( isset( $input['name'] ) ) $p['name'] = sanitize_text_field( wp_unslash( $input['name'] ) );
	if ( isset( $input['slug'] ) ) $p['slug'] = sanitize_title( wp_unslash( $input['slug'] ) );
	if ( isset( $input['type'] ) ) {
		$type = sanitize_key( $input['type'] );
		if ( ! in_array( $type, array( 'select', 'text' ), true ) ) return 'type must be "select" or "text".';
		$p['type'] = $type;
	}
	if ( isset( $input['order_by'] ) ) {
		$ob = sanitize_key( $input['order_by'] );
		if ( ! in_array( $ob, array( 'menu_order', 'name', 'name_num', 'id' ), true ) ) return 'order_by must be one of: menu_order, name, name_num, id.';
		$p['order_by'] = $ob;
	}
	if ( isset( $input['has_archives'] ) ) $p['has_archives'] = filter_var( $input['has_archives'], FILTER_VALIDATE_BOOLEAN );
	return $p;
}

/**
 * List global product attributes (e.g.
 *
 * Color, Size) with their taxonomy names.
 *
 * MCP tool: `wsp_woo_get_attributes`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_attributes( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$rows = wsp_woo_rest( 'GET', 'products/attributes' );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );
	$out = array_map( 'wsp_woo_attribute_row', $rows );
	return wsp_woo_ok( array( 'items' => $out, 'total' => count( $out ) ) );
}

/**
 * Create a global product attribute.
 *
 * MCP tool: `wsp_woo_create_attribute`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required. Attribute name.
 *     @type string $slug Optional. Slug (optional).
 *     @type string $type Optional. select | text. Default select.
 *     @type string $order_by Optional. menu_order | name | name_num | id.
 *     @type bool $has_archives Optional. Enable archives.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_attribute( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	if ( empty( $input['name'] ) ) return wsp_woo_fail( 'name is required.' );
	$p = wsp_woo_attribute_params( $input );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	$res = wsp_woo_rest( 'POST', 'products/attributes', $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_attribute_row( $res ) );
}

/**
 * Update a global product attribute.
 *
 * MCP tool: `wsp_woo_update_attribute`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type string $name Optional. Name.
 *     @type string $slug Optional. Slug.
 *     @type string $type Optional. select | text.
 *     @type string $order_by Optional. menu_order | name | name_num | id.
 *     @type bool $has_archives Optional. Enable archives.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_update_attribute( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id ) return wsp_woo_fail( 'id is required.' );
	$p = wsp_woo_attribute_params( $input );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	$res = wsp_woo_rest( 'PUT', 'products/attributes/' . $id, $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_attribute_row( $res ) );
}

/**
 * Permanently delete a global attribute and all its terms (force=true required).
 *
 * MCP tool: `wsp_woo_delete_attribute`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Item ID.
 *     @type bool $force Required. Must be true: this item cannot be trashed and is deleted
 *         permanently.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_attribute( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id ) return wsp_woo_fail( 'id is required.' );
	if ( ! wsp_woo_flag( $input, 'force' ) ) {
		return wsp_woo_fail( 'Attributes cannot be moved to the trash and deleting one deletes all its terms. Pass force=true to delete it permanently.' );
	}
	$res = wsp_woo_rest( 'DELETE', 'products/attributes/' . $id, array( 'force' => true ) );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'id' => $id, 'name' => $res['name'], 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

/**
 * List the terms (e.g.
 *
 * Red, Blue) of a global attribute.
 *
 * MCP tool: `wsp_woo_get_attribute_terms`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $attribute_id Required. Attribute ID.
 *     @type string $search Optional. Search term.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_get_attribute_terms( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$aid = isset( $input['attribute_id'] ) ? intval( $input['attribute_id'] ) : 0;
	if ( ! $aid ) return wsp_woo_fail( 'attribute_id is required.' );
	$params = array( 'per_page' => 100, 'hide_empty' => false );
	if ( ! empty( $input['search'] ) ) $params['search'] = sanitize_text_field( wp_unslash( $input['search'] ) );
	$rows = wsp_woo_rest( 'GET', "products/attributes/{$aid}/terms", $params );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );
	$out = array_map( 'wsp_woo_term_row', $rows );
	return wsp_woo_ok( array( 'attribute_id' => $aid, 'items' => $out, 'total' => count( $out ) ) );
}

/**
 * Add a term to a global attribute.
 *
 * MCP tool: `wsp_woo_create_attribute_term`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $attribute_id Required. Attribute ID.
 *     @type string $name Required. Term name.
 *     @type string $slug Optional. Slug (optional).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_create_attribute_term( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$aid = isset( $input['attribute_id'] ) ? intval( $input['attribute_id'] ) : 0;
	if ( ! $aid || empty( $input['name'] ) ) return wsp_woo_fail( 'attribute_id and name are required.' );
	$p = array( 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) );
	if ( isset( $input['slug'] ) ) $p['slug'] = sanitize_title( wp_unslash( $input['slug'] ) );
	$res = wsp_woo_rest( 'POST', "products/attributes/{$aid}/terms", $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	$row = wsp_woo_term_row( $res );
	$row['attribute_id'] = $aid;
	return wsp_woo_ok( $row );
}

/**
 * Permanently delete an attribute term (force=true required).
 *
 * MCP tool: `wsp_woo_delete_attribute_term`. Requires the `manage_woocommerce` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $attribute_id Required. Attribute ID.
 *     @type int $id Required. Term ID.
 *     @type bool $force Required. Must be true: this item cannot be trashed and is deleted
 *         permanently.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_woo_delete_attribute_term( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$aid = isset( $input['attribute_id'] ) ? intval( $input['attribute_id'] ) : 0;
	$id  = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $aid || ! $id ) return wsp_woo_fail( 'attribute_id and id are required.' );
	if ( ! wsp_woo_flag( $input, 'force' ) ) {
		return wsp_woo_fail( 'Attribute terms cannot be moved to the trash. Pass force=true to delete it permanently.' );
	}
	$res = wsp_woo_rest( 'DELETE', "products/attributes/{$aid}/terms/{$id}", array( 'force' => true ) );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'id' => $id, 'name' => $res['name'], 'attribute_id' => $aid, 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

// ---------------------------------------------
// PRODUCT CATEGORY / TAG / ATTRIBUTE ASSIGNMENT
// (called from wsp_execute_woo_create_product / _update_product)
// ---------------------------------------------

/**
 * Apply `categories` / `tags` (arrays of term IDs) to a product object (not saved here).
 *
 * Returns null on success or an error string.
 *
 * @param WC_Product $product Product object. Not saved here.
 * @param array      $input   Tool arguments with optional "categories" and "tags".
 * @return string|null An error message, or null on success.
 */
function wsp_woo_apply_product_terms( $product, $input ) {
	if ( isset( $input['categories'] ) ) {
		$ids = wsp_woo_validate_term_ids( $input['categories'], 'product_cat' );
		if ( is_string( $ids ) ) return $ids;
		$product->set_category_ids( $ids );
	}
	if ( isset( $input['tags'] ) ) {
		$ids = wsp_woo_validate_term_ids( $input['tags'], 'product_tag' );
		if ( is_string( $ids ) ) return $ids;
		$product->set_tag_ids( $ids );
	}
	return null;
}

/**
 * Resolve `brands` input (term IDs, slugs or names; a single value is also accepted) to product_brand
 * term IDs.
 *
 * Unknown brands are an error, not auto-created, so a typo can't add a duplicate brand. Returns int[]
 * or an error string.
 *
 * @param mixed $items Brand term IDs, slugs or names, or a single value.
 * @return int[]|string The brand term IDs, or an error message.
 */
function wsp_woo_resolve_brand_ids( $items ) {
	if ( ! taxonomy_exists( 'product_brand' ) ) {
		return 'Brands are not available on this site: the product_brand taxonomy is not registered (WooCommerce 9.6+ includes Brands).';
	}
	$ids = array();
	foreach ( (array) $items as $item ) {
		if ( ! is_scalar( $item ) ) return 'Each brand must be a brand ID, slug or name.';
		$value = trim( sanitize_text_field( (string) $item ) );
		if ( '' === $value ) continue;
		$term = null;
		if ( ctype_digit( $value ) ) {
			$term = get_term( (int) $value, 'product_brand' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), 'product_brand' );
		}
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, 'product_brand' );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return "Brand '{$value}' not found. Use wsp_woo_get_product_brands to list existing brands.";
		}
		$ids[] = (int) $term->term_id;
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Validate `gallery_image_ids` (array of media IDs, or a "1,2,3" string) for a product gallery.
 *
 * Every ID must be an image in the media library. Keeps the given order and drops duplicates. Returns
 * int[] or an error string.
 *
 * @param array|string $items Media IDs, or a "1,2,3" string.
 * @return int[]|string The image IDs, or an error message.
 */
function wsp_woo_validate_gallery_ids( $items ) {
	if ( is_string( $items ) ) {
		$items = '' === trim( $items ) ? array() : explode( ',', $items );
	}
	if ( ! is_array( $items ) ) return 'gallery_image_ids must be an array of media IDs.';
	$ids = array();
	foreach ( $items as $item ) {
		if ( ! is_scalar( $item ) || ! ctype_digit( trim( (string) $item ) ) ) {
			return 'gallery_image_ids must contain media IDs (integers) only.';
		}
		$id = (int) trim( (string) $item );
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return "Media ID {$id} not found in the media library. Upload it first with wsp_upload_media_from_url.";
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return "Media ID {$id} is not an image.";
		}
		$ids[] = $id;
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Brands currently assigned to a product, as [ { id, name, slug } ].
 *
 * @param int $product_id Product ID.
 * @return array Brands as id, name and slug.
 */
function wsp_woo_product_brands( $product_id ) {
	if ( ! taxonomy_exists( 'product_brand' ) ) return array();
	$terms = wp_get_object_terms( $product_id, 'product_brand' );
	if ( is_wp_error( $terms ) ) return array();
	$out = array();
	foreach ( $terms as $t ) {
		$out[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
	}
	return $out;
}

/**
 * Build WC_Product_Attribute objects.
 *
 * Each item: - custom attribute: { name, options: [strings], visible?, variation? } - global
 * attribute: { attribute_id | taxonomy ("pa_color"), options: [term names/slugs], visible?, variation?
 * } `variation` defaults to true for variable products, false otherwise. Returns
 * WC_Product_Attribute[] or error string.
 *
 * @param array $items       Attribute definitions from the caller.
 * @param bool  $is_variable Whether the product is variable.
 * @return WC_Product_Attribute[]|string Attribute objects, or an error message.
 */
function wsp_woo_build_attributes( $items, $is_variable ) {
	if ( ! is_array( $items ) ) return 'attributes must be an array.';
	$built    = array();
	$position = 0;
	foreach ( $items as $a ) {
		if ( ! is_array( $a ) ) return 'Each attribute must be an object.';
		$options = isset( $a['options'] ) ? $a['options'] : array();
		if ( ! is_array( $options ) ) $options = array_map( 'trim', explode( '|', (string) $options ) );
		$options = array_values( array_filter( array_map( 'sanitize_text_field', $options ), 'strlen' ) );
		if ( empty( $options ) ) continue;

		$attr = new WC_Product_Attribute();
		$tax  = '';
		if ( ! empty( $a['attribute_id'] ) ) {
			$tax = wc_attribute_taxonomy_name_by_id( intval( $a['attribute_id'] ) );
			if ( ! $tax ) return 'Global attribute ID ' . intval( $a['attribute_id'] ) . ' not found.';
		} elseif ( ! empty( $a['taxonomy'] ) ) {
			$tax = sanitize_key( $a['taxonomy'] );
			if ( 0 !== strpos( $tax, 'pa_' ) ) $tax = 'pa_' . $tax;
		}
		if ( $tax ) {
			if ( ! taxonomy_exists( $tax ) ) return "Global attribute taxonomy {$tax} does not exist. Create it with wsp_woo_create_attribute first.";
			$term_ids = array();
			foreach ( $options as $opt ) {
				$term = get_term_by( 'slug', sanitize_title( $opt ), $tax );
				if ( ! $term ) $term = get_term_by( 'name', $opt, $tax );
				if ( ! $term ) {
					$new = wp_insert_term( $opt, $tax );
					if ( is_wp_error( $new ) ) return "Could not create term '{$opt}' in {$tax}: " . $new->get_error_message();
					$term_ids[] = (int) $new['term_id'];
				} else {
					$term_ids[] = (int) $term->term_id;
				}
			}
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
			$attr->set_name( $tax );
			$attr->set_options( $term_ids );
		} else {
			if ( empty( $a['name'] ) ) return 'Each attribute needs a name (custom) or attribute_id/taxonomy (global).';
			$attr->set_name( sanitize_text_field( $a['name'] ) );
			$attr->set_options( $options );
		}
		$attr->set_position( $position++ );
		$attr->set_visible( isset( $a['visible'] ) ? filter_var( $a['visible'], FILTER_VALIDATE_BOOLEAN ) : true );
		$attr->set_variation( isset( $a['variation'] ) ? filter_var( $a['variation'], FILTER_VALIDATE_BOOLEAN ) : $is_variable );
		$built[] = $attr;
	}
	return $built;
}
