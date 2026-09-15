<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_posts( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 10;
    $status   = isset( $input['status'] )   ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'publish';
    if ( 'all' === $status ) $status = array( 'publish', 'draft', 'pending', 'future' );
    $q     = new WP_Query( array( 'post_status' => $status, 'posts_per_page' => $per_page, 'orderby' => 'date', 'order' => 'DESC' ) );
    $posts = array();
    foreach ( $q->posts as $p ) {
        $posts[] = array(
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'url'        => get_permalink( $p->ID ),
            'status'     => $p->post_status,
            'date'       => get_the_date( 'Y-m-d', $p->ID ),
            'author'     => get_the_author_meta( 'display_name', $p->post_author ),
            'categories' => wp_get_post_categories( $p->ID, array( 'fields' => 'names' ) ),
            'tags'       => wp_get_post_tags( $p->ID, array( 'fields' => 'names' ) ),
            'excerpt'    => has_excerpt( $p->ID ) ? get_the_excerpt( $p ) : wp_trim_words( $p->post_content, 40 ),
        );
    }
    return array( 'posts' => $posts, 'total' => $q->found_posts );
}

function wsp_execute_get_post( $input ) {
    $post = wsp_mcp_guard_read_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;

    $thumbnail_id = get_post_thumbnail_id( $post->ID );
    $raw_meta     = get_post_meta( $post->ID );
    $meta         = array();
    foreach ( $raw_meta as $key => $values ) {
        if ( is_protected_meta( $key, 'post' ) ) continue; // Skip internal "_"-prefixed keys.
        $meta[ $key ] = 1 === count( $values ) ? $values[0] : $values;
    }

    return array(
        'id'             => $post->ID,
        'title'          => $post->post_title,
        'content'        => $post->post_content,
        'excerpt'        => has_excerpt( $post->ID ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 55 ),
        'status'         => $post->post_status,
        'slug'           => $post->post_name,
        'url'            => get_permalink( $post->ID ),
        'date'           => get_the_date( 'Y-m-d H:i:s', $post->ID ),
        'modified'       => get_the_modified_date( 'Y-m-d H:i:s', $post->ID ),
        'author'         => array(
            'id'   => intval( $post->post_author ),
            'name' => get_the_author_meta( 'display_name', $post->post_author ),
        ),
        'categories'     => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
        'tags'           => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
        'featured_image' => $thumbnail_id ? array( 'id' => $thumbnail_id, 'url' => wp_get_attachment_url( $thumbnail_id ) ) : null,
        'comment_status' => $post->comment_status,
        'comment_count'  => intval( $post->comment_count ),
        'meta'           => $meta,
    );
}

function wsp_execute_create_post( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => wp_kses_post( wp_unslash( $input['content'] ) ),
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
        'post_type'    => 'post',
    );
    if ( ! empty( $input['excerpt'] ) )    $args['post_excerpt']  = sanitize_text_field( wp_unslash( $input['excerpt'] ) );
    if ( ! empty( $input['slug'] ) )       $args['post_name']     = sanitize_title( $input['slug'] );
    if ( ! empty( $input['categories'] ) ) $args['post_category'] = array_map( 'intval', $input['categories'] );
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( ! empty( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => $args['post_status'] );
}

function wsp_execute_update_post( $input ) {
    $post = wsp_mcp_guard_edit_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;
    if ( isset( $input['status'] ) ) {
        $status_check = wsp_mcp_guard_post_status( $post, $input['status'] );
        if ( is_wp_error( $status_check ) ) return $status_check;
    }
    $args = array( 'ID' => $post->ID );
    if ( isset( $input['title'] ) )      $args['post_title']    = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) )    $args['post_content']  = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) )     $args['post_status']   = sanitize_text_field( wp_unslash( $input['status'] ) );
    if ( isset( $input['categories'] ) ) $args['post_category'] = array_map( 'intval', $input['categories'] );
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( isset( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ) );
}

function wsp_execute_delete_post( $input ) {
    $post = wsp_mcp_guard_delete_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Post {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash post.' );
}
