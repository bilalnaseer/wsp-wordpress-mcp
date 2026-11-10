<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolve a caller-supplied post type slug.
 *
 * Only public, non-built-in types (the same set wsp_get_post_types lists) are
 * in scope. Internal types such as user_request (privacy requests, titled with
 * email addresses), orders or form definitions are refused, and the caller
 * must hold the type's own edit capability — the tool's broad `edit_posts`
 * says nothing about e.g. WooCommerce's `edit_products`.
 */
function wsp_cpt_resolve_type( $slug ) {
    $slug = sanitize_key( $slug );
    if ( in_array( $slug, array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ), true ) ) {
        return new WP_Error( 'reserved_type', "'{$slug}' has its own dedicated tools; use those instead." );
    }
    $obj = get_post_type_object( $slug );
    if ( ! $obj || ! $obj->public || $obj->_builtin ) return new WP_Error( 'not_found', "Post type not found: {$slug}" );
    if ( ! current_user_can( $obj->cap->edit_posts ) ) {
        return new WP_Error( 'forbidden', "You do not have permission to manage '{$slug}' items." );
    }
    return $obj;
}

function wsp_execute_get_post_types( $input ) {
    $types  = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
    $result = array();
    foreach ( $types as $slug => $obj ) {
        $result[] = array(
            'slug'          => $slug,
            'label'         => $obj->label,
            'hierarchical'  => $obj->hierarchical,
            'has_archive'   => (bool) $obj->has_archive,
        );
    }
    return array( 'post_types' => $result );
}

function wsp_execute_get_cpt_items( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;

    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 10;
    $status   = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
    if ( 'all' === $status ) {
        $status = array( 'publish', 'draft', 'pending', 'future' );
    } elseif ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'private' ), true ) ) {
        $status = 'publish';
    }

    $args = array( 'post_type' => $type->name, 'post_status' => $status, 'posts_per_page' => $per_page, 'orderby' => 'date', 'order' => 'DESC' );
    // Unpublished items of other authors are only visible to users who may edit them.
    if ( array( 'publish' ) !== (array) $status && ! current_user_can( $type->cap->edit_others_posts ) ) {
        $args['author'] = get_current_user_id();
    }
    $q = new WP_Query( $args );
    $items = array();
    foreach ( $q->posts as $p ) {
        $items[] = array(
            'id'     => $p->ID,
            'title'  => $p->post_title,
            'url'    => get_permalink( $p->ID ),
            'status' => $p->post_status,
            'date'   => get_the_date( 'Y-m-d', $p->ID ),
        );
    }
    return array( 'items' => $items, 'total' => $q->found_posts );
}

function wsp_execute_create_cpt_item( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;
    if ( empty( $input['title'] ) ) return array( 'success' => false, 'error' => 'title is required.' );
    if ( ! current_user_can( $type->cap->create_posts ) ) {
        return new WP_Error( 'forbidden', "You do not have permission to create '{$type->name}' items." );
    }

    $status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
    if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) {
        return new WP_Error( 'forbidden', "You do not have permission to set status '{$status}' on '{$type->name}' items." );
    }

    $args = array(
        'post_type'    => $type->name,
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => isset( $input['content'] ) ? wp_kses_post( wp_unslash( $input['content'] ) ) : '',
        'post_status'  => $status,
    );
    if ( ! empty( $input['slug'] ) ) $args['post_name'] = sanitize_title( $input['slug'] );
    $date_check = wsp_mcp_apply_post_date( $args, $input );
    if ( is_wp_error( $date_check ) ) return $date_check;

    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

function wsp_execute_update_cpt_item( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;
    if ( empty( $input['id'] ) ) return array( 'success' => false, 'error' => 'id is required.' );

    $post = wsp_mcp_guard_edit_post( $input['id'], $type->name );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;

    $args = array( 'ID' => $id );
    if ( isset( $input['title'] ) )   $args['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) ) $args['post_content'] = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) ) {
        $status_check = wsp_mcp_guard_post_status( $post, $input['status'] );
        if ( is_wp_error( $status_check ) ) return $status_check;
        $args['post_status'] = sanitize_key( $input['status'] );
    }
    $date_check = wsp_mcp_apply_post_date( $args, $input, $post );
    if ( is_wp_error( $date_check ) ) return $date_check;

    $result = wp_update_post( $args, true );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

function wsp_execute_delete_cpt_item( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;
    if ( empty( $input['id'] ) ) return array( 'success' => false, 'error' => 'id is required.' );

    $post = wsp_mcp_guard_delete_post( $input['id'], $type->name );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;

    return wp_trash_post( $id )
        ? array( 'success' => true, 'message' => "Item {$id} moved to trash." )
        : array( 'success' => false, 'error' => 'Could not trash item.' );
}
