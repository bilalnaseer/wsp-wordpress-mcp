<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_posts( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 10;
    $status   = isset( $input['status'] )   ? sanitize_key( wp_unslash( $input['status'] ) ) : 'publish';
    if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'all' ), true ) ) $status = 'publish';
    $args = array( 'post_type' => 'post', 'posts_per_page' => max( 1, min( 100, $per_page ) ), 'orderby' => 'date', 'order' => 'DESC' );
    if ( 'publish' === $status ) {
        $args['post_status'] = 'publish';
    } elseif ( ! current_user_can( 'edit_posts' ) ) {
        // Unpublished content is never listed for users who can't edit posts (e.g. Subscribers).
        $args['post_status'] = 'publish';
    } else {
        $args['post_status'] = ( 'all' === $status ) ? array( 'publish', 'draft', 'pending', 'future' ) : $status;
        // Non-published posts of other authors need edit_others_posts (Contributors/Authors see only their own).
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            $args['author__in'] = array( get_current_user_id() );
            if ( 'all' === $status ) {
                // Published posts of anyone plus own unpublished: run as two-part merge below.
                $args['post_status'] = array( 'draft', 'pending', 'future' );
            }
        }
    }
    $q = new WP_Query( $args );
    if ( 'all' === $status && isset( $args['author__in'] ) && current_user_can( 'edit_posts' ) ) {
        $pub = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $args['posts_per_page'], 'orderby' => 'date', 'order' => 'DESC' ) );
        $merged = array_slice( array_merge( $pub->posts, $q->posts ), 0, $args['posts_per_page'] );
        usort( $merged, function ( $a, $b ) { return strcmp( $b->post_date, $a->post_date ); } );
        $q->posts = $merged;
        $q->found_posts += $pub->found_posts;
    }
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
    $id   = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    $post = $id ? get_post( $id ) : null;
    if ( ! $post || 'post' !== $post->post_type ) return new WP_Error( 'not_found', 'Post not found.' );
    if ( ! current_user_can( 'read_post', $id ) ) return new WP_Error( 'forbidden', 'You do not have permission to read object ' . $id . '.' );
    return array(
        'id'         => $post->ID,
        'title'      => $post->post_title,
        'content'    => $post->post_content,
        'url'        => get_permalink( $post->ID ),
        'status'     => $post->post_status,
        'date'       => get_the_date( 'Y-m-d', $post->ID ),
        'author'     => get_the_author_meta( 'display_name', $post->post_author ),
        'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
        'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
        'excerpt'    => has_excerpt( $post->ID ) ? get_the_excerpt( $post ) : wp_trim_words( $post->post_content, 40 ),
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
    $date_check = wsp_mcp_apply_post_date( $args, $input );
    if ( is_wp_error( $date_check ) ) return $date_check;
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( ! empty( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
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
    $date_check = wsp_mcp_apply_post_date( $args, $input, $post );
    if ( is_wp_error( $date_check ) ) return $date_check;
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    if ( isset( $input['tags'] ) ) wp_set_post_tags( $id, array_map( 'intval', $input['tags'] ) );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

function wsp_execute_delete_post( $input ) {
    $post = wsp_mcp_guard_delete_post( isset( $input['id'] ) ? $input['id'] : 0, 'post' );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Post {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash post.' );
}
