<?php
/**
 * WooCommerce catalog management (v2.9.5): delete tools, product categories / tags,
 * global attributes + attribute terms.
 *
 * All callbacks here return the envelope { success, data, error }. Failures are
 * returned in that envelope (not as WP_Error) so the caller always gets one shape.
 * Shared helpers (wsp_woo_ok / wsp_woo_fail / wsp_woo_guard / wsp_woo_rest) are also
 * used by woocommerce-store.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------
// SHARED HELPERS
// ---------------------------------------------

function wsp_woo_ok( $data = array() ) {
	return array( 'success' => true, 'data' => $data, 'error' => null );
}

function wsp_woo_fail( $message ) {
	return array( 'success' => false, 'data' => null, 'error' => (string) $message );
}

/** Returns an error envelope when WooCommerce is missing or the caller lacks $cap; null when OK. */
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
 * Call a WooCommerce REST controller internally (wc/v3). Goes through the controller's own
 * permission callback, validation and sanitization — no raw SQL. Returns data or WP_Error.
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

/** Recursively drop REST `_links` / `_embedded` — useless noise for an AI client. */
function wsp_woo_strip_links( $data ) {
	if ( ! is_array( $data ) ) return $data;
	unset( $data['_links'], $data['_embedded'] );
	foreach ( $data as $k => $v ) {
		if ( is_array( $v ) ) $data[ $k ] = wsp_woo_strip_links( $v );
	}
	return $data;
}

/** Keep only $keys from an associative row. */
function wsp_woo_pick( $row, $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		if ( is_array( $row ) && array_key_exists( $k, $row ) ) {
			$out[ $k ] = $row[ $k ];
		}
	}
	return $out;
}

/** Strict bool for tool inputs (accepts true/"true"/1). */
function wsp_woo_flag( $input, $key, $default = false ) {
	if ( ! isset( $input[ $key ] ) ) return $default;
	return filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
}

/** Resolve an array of ids to those that exist in $taxonomy; returns int[] or error string. */
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

/** Terms have no trash in WordPress, so permanent deletion needs an explicit force=true. */
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

function wsp_execute_woo_delete_category( $input ) { return wsp_woo_delete_term_tool( $input, 'product_cat', 'product category' ); }
function wsp_execute_woo_delete_tag( $input )      { return wsp_woo_delete_term_tool( $input, 'product_tag', 'product tag' ); }

// ---------------------------------------------
// PRODUCT CATEGORIES & TAGS
// ---------------------------------------------

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

function wsp_execute_woo_get_product_categories( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	return wsp_woo_list_terms( 'products/categories', $input );
}

function wsp_execute_woo_get_product_tags( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	return wsp_woo_list_terms( 'products/tags', $input );
}

/** Build REST params for a category create/update. */
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

function wsp_execute_woo_get_attributes( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	$rows = wsp_woo_rest( 'GET', 'products/attributes' );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );
	$out = array_map( 'wsp_woo_attribute_row', $rows );
	return wsp_woo_ok( array( 'items' => $out, 'total' => count( $out ) ) );
}

function wsp_execute_woo_create_attribute( $input ) {
	if ( $e = wsp_woo_guard() ) return $e;
	if ( empty( $input['name'] ) ) return wsp_woo_fail( 'name is required.' );
	$p = wsp_woo_attribute_params( $input );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	$res = wsp_woo_rest( 'POST', 'products/attributes', $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_attribute_row( $res ) );
}

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
 * Returns null on success or an error string.
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
 * Build WC_Product_Attribute objects. Each item:
 *  - custom attribute: { name, options: [strings], visible?, variation? }
 *  - global attribute: { attribute_id | taxonomy ("pa_color"), options: [term names/slugs], visible?, variation? }
 * `variation` defaults to true for variable products, false otherwise.
 * Returns WC_Product_Attribute[] or error string.
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
