<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Post types with dedicated abilities elsewhere, or internal, are out of scope here. */
function wsp_cpt_resolve_type( $slug ) {
    $slug = sanitize_key( $slug );
    if ( in_array( $slug, array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ), true ) ) {
        return new WP_Error( 'reserved_type', "'{$slug}' has its own dedicated tools; use those instead." );
    }
    $obj = get_post_type_object( $slug );
    if ( ! $obj ) return new WP_Error( 'not_found', "Post type not found: {$slug}" );
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
    $status   = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'publish';
    if ( 'all' === $status ) $status = array( 'publish', 'draft', 'pending', 'future' );

    $q = new WP_Query( array( 'post_type' => $type->name, 'post_status' => $status, 'posts_per_page' => $per_page, 'orderby' => 'date', 'order' => 'DESC' ) );
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

    $args = array(
        'post_type'    => $type->name,
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => isset( $input['content'] ) ? wp_kses_post( wp_unslash( $input['content'] ) ) : '',
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
    );
    if ( ! empty( $input['slug'] ) ) $args['post_name'] = sanitize_title( $input['slug'] );

    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => $args['post_status'] );
}

function wsp_execute_update_cpt_item( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;
    if ( empty( $input['id'] ) ) return array( 'success' => false, 'error' => 'id is required.' );

    $id   = intval( $input['id'] );
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== $type->name ) return array( 'success' => false, 'error' => 'Item not found for that post_type.' );

    $args = array( 'ID' => $id );
    if ( isset( $input['title'] ) )   $args['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) ) $args['post_content'] = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) )  $args['post_status']  = sanitize_text_field( wp_unslash( $input['status'] ) );

    $result = wp_update_post( $args, true );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ) );
}

function wsp_execute_delete_cpt_item( $input ) {
    $type = wsp_cpt_resolve_type( isset( $input['post_type'] ) ? $input['post_type'] : '' );
    if ( is_wp_error( $type ) ) return $type;
    if ( empty( $input['id'] ) ) return array( 'success' => false, 'error' => 'id is required.' );

    $id   = intval( $input['id'] );
    $post = get_post( $id );
    if ( ! $post || $post->post_type !== $type->name ) return array( 'success' => false, 'error' => 'Item not found for that post_type.' );

    return wp_trash_post( $id )
        ? array( 'success' => true, 'message' => "Item {$id} moved to trash." )
        : array( 'success' => false, 'error' => 'Could not trash item.' );
}
