<?php
/**
 * Navigation menu abilities: read/create/delete menus, manage items, and
 * assign menus to theme locations. Gated by 'edit_theme_options' throughout
 * (the same capability WP core's own menu editor and REST menus controller
 * require) — menus are a site-wide structure, not per-object content, so
 * there is no per-item ownership to additionally guard.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_menus( $input ) {
	$menus = wp_get_nav_menus();
	$locs  = get_nav_menu_locations();
	$result = array();
	foreach ( $menus as $m ) {
		$items = wp_get_nav_menu_items( $m->term_id );
		$result[] = array(
			'id'         => $m->term_id,
			'name'       => $m->name,
			'slug'       => $m->slug,
			'item_count' => is_array( $items ) ? count( $items ) : 0,
			'locations'  => array_keys( $locs, $m->term_id, true ),
		);
	}
	return array( 'menus' => $result );
}

/** Map a WP_Post nav-menu-item (post-processed by wp_setup_nav_menu_item) to a plain array. */
function wsp_menu_item_to_array( $item ) {
	return array(
		'id'        => (int) $item->ID,
		'title'     => $item->title,
		'url'       => $item->url,
		'parent'    => (int) $item->menu_item_parent,
		'order'     => (int) $item->menu_order,
		'type'      => $item->type,   // custom | post_type | taxonomy
		'object'    => $item->object, // post | page | category | ...
		'object_id' => (int) $item->object_id,
		'target'    => $item->target,
		'classes'   => is_array( $item->classes ) ? implode( ' ', array_filter( $item->classes ) ) : '',
	);
}

function wsp_execute_get_menu_items( $input ) {
	if ( empty( $input['menu'] ) ) return array( 'success' => false, 'error' => 'menu is required.' );
	$menu = wp_get_nav_menu_object( $input['menu'] ); // accepts id, slug, or name
	if ( ! $menu ) return array( 'success' => false, 'error' => 'Menu not found.' );
	$items  = wp_get_nav_menu_items( $menu->term_id );
	$result = array();
	foreach ( (array) $items as $item ) $result[] = wsp_menu_item_to_array( $item );
	return array( 'menu' => array( 'id' => $menu->term_id, 'name' => $menu->name ), 'items' => $result );
}

function wsp_execute_create_menu( $input ) {
	if ( empty( $input['name'] ) ) return array( 'success' => false, 'error' => 'name is required.' );
	$name = sanitize_text_field( wp_unslash( $input['name'] ) );
	$id   = wp_create_nav_menu( $name );
	if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
	return array( 'success' => true, 'id' => $id, 'name' => $name );
}

function wsp_execute_delete_menu( $input ) {
	if ( empty( $input['menu'] ) ) return array( 'success' => false, 'error' => 'menu is required.' );
	$menu = wp_get_nav_menu_object( $input['menu'] );
	if ( ! $menu ) return array( 'success' => false, 'error' => 'Menu not found.' );
	$ok = wp_delete_nav_menu( $menu->term_id );
	if ( is_wp_error( $ok ) || ! $ok ) {
		return array( 'success' => false, 'error' => is_wp_error( $ok ) ? $ok->get_error_message() : 'Delete failed.' );
	}
	return array( 'success' => true, 'id' => $menu->term_id );
}

function wsp_execute_add_menu_item( $input ) {
	if ( empty( $input['menu'] ) ) return array( 'success' => false, 'error' => 'menu is required.' );
	$menu = wp_get_nav_menu_object( $input['menu'] );
	if ( ! $menu ) return array( 'success' => false, 'error' => 'Menu not found.' );

	$type = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'custom';
	$args = array( 'menu-item-status' => 'publish' );
	if ( isset( $input['parent'] ) ) $args['menu-item-parent-id'] = intval( $input['parent'] );
	if ( isset( $input['order'] ) )  $args['menu-item-position']  = intval( $input['order'] );

	if ( 'custom' === $type ) {
		if ( empty( $input['url'] ) || empty( $input['title'] ) ) {
			return array( 'success' => false, 'error' => 'url and title are required for a custom link.' );
		}
		$args['menu-item-type']  = 'custom';
		$args['menu-item-title'] = sanitize_text_field( wp_unslash( $input['title'] ) );
		$args['menu-item-url']   = esc_url_raw( wp_unslash( $input['url'] ) );
	} elseif ( in_array( $type, array( 'post', 'page' ), true ) ) {
		$object_id = ! empty( $input['object_id'] ) ? intval( $input['object_id'] ) : 0;
		$post      = $object_id ? get_post( $object_id ) : null;
		if ( ! $post ) return array( 'success' => false, 'error' => 'object_id: post not found.' );
		$args['menu-item-type']      = 'post_type';
		$args['menu-item-object']    = $post->post_type;
		$args['menu-item-object-id'] = $post->ID;
		if ( ! empty( $input['title'] ) ) $args['menu-item-title'] = sanitize_text_field( wp_unslash( $input['title'] ) );
	} elseif ( 'category' === $type ) {
		$object_id = ! empty( $input['object_id'] ) ? intval( $input['object_id'] ) : 0;
		$term      = $object_id ? get_term( $object_id, 'category' ) : null;
		if ( ! $term || is_wp_error( $term ) ) return array( 'success' => false, 'error' => 'object_id: category not found.' );
		$args['menu-item-type']      = 'taxonomy';
		$args['menu-item-object']    = 'category';
		$args['menu-item-object-id'] = $term->term_id;
		if ( ! empty( $input['title'] ) ) $args['menu-item-title'] = sanitize_text_field( wp_unslash( $input['title'] ) );
	} else {
		return array( 'success' => false, 'error' => "type must be 'custom', 'post', 'page', or 'category'." );
	}

	$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
	if ( is_wp_error( $item_id ) ) return array( 'success' => false, 'error' => $item_id->get_error_message() );
	return array( 'success' => true, 'id' => $item_id, 'menu_id' => $menu->term_id );
}

function wsp_execute_update_menu_item( $input ) {
	if ( empty( $input['item_id'] ) ) return array( 'success' => false, 'error' => 'item_id is required.' );
	$item_id = intval( $input['item_id'] );
	$post    = get_post( $item_id );
	if ( ! $post || 'nav_menu_item' !== $post->post_type ) return array( 'success' => false, 'error' => 'Menu item not found.' );

	$terms = wp_get_object_terms( $item_id, 'nav_menu' );
	if ( is_wp_error( $terms ) || empty( $terms ) ) return array( 'success' => false, 'error' => 'Could not resolve parent menu.' );
	$menu_id = $terms[0]->term_id;
	$current = wp_setup_nav_menu_item( $post );

	$args = array(
		'menu-item-title'     => isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : $current->title,
		'menu-item-url'       => isset( $input['url'] ) ? esc_url_raw( wp_unslash( $input['url'] ) ) : $current->url,
		'menu-item-parent-id' => isset( $input['parent'] ) ? intval( $input['parent'] ) : (int) $current->menu_item_parent,
		'menu-item-position'  => isset( $input['order'] ) ? intval( $input['order'] ) : (int) $current->menu_order,
		'menu-item-type'      => $current->type,
		'menu-item-object'    => $current->object,
		'menu-item-object-id' => $current->object_id,
		'menu-item-status'    => 'publish',
	);
	$result = wp_update_nav_menu_item( $menu_id, $item_id, $args );
	if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
	return array( 'success' => true, 'id' => $item_id );
}

function wsp_execute_delete_menu_item( $input ) {
	if ( empty( $input['item_id'] ) ) return array( 'success' => false, 'error' => 'item_id is required.' );
	$item_id = intval( $input['item_id'] );
	$post    = get_post( $item_id );
	if ( ! $post || 'nav_menu_item' !== $post->post_type ) return array( 'success' => false, 'error' => 'Menu item not found.' );
	if ( ! wp_delete_post( $item_id, true ) ) return array( 'success' => false, 'error' => 'Delete failed.' );
	return array( 'success' => true, 'id' => $item_id );
}

function wsp_execute_get_menu_locations( $input ) {
	$registered = get_registered_nav_menus();
	$assigned   = get_nav_menu_locations();
	$result     = array();
	foreach ( $registered as $slug => $description ) {
		$menu_id  = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;
		$menu     = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
		$result[] = array(
			'location'    => $slug,
			'description' => $description,
			'menu_id'     => $menu_id,
			'menu_name'   => $menu ? $menu->name : '',
		);
	}
	return array( 'locations' => $result );
}

function wsp_execute_assign_menu_location( $input ) {
	if ( empty( $input['location'] ) ) return array( 'success' => false, 'error' => 'location is required.' );
	$location   = sanitize_key( $input['location'] );
	$registered = get_registered_nav_menus();
	if ( ! isset( $registered[ $location ] ) ) return array( 'success' => false, 'error' => 'Unknown theme location: ' . $location );

	$menu_id = isset( $input['menu'] ) ? intval( $input['menu'] ) : 0;
	if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) return array( 'success' => false, 'error' => 'Menu not found.' );

	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( $menu_id ) {
		$locations[ $location ] = $menu_id;
	} else {
		unset( $locations[ $location ] ); // menu omitted or 0 unassigns the location.
	}
	set_theme_mod( 'nav_menu_locations', $locations );
	return array( 'success' => true, 'location' => $location, 'menu_id' => $menu_id );
}
