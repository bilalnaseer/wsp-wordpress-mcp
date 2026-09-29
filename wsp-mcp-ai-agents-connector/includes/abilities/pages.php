<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_pages( $input ) {
    $pages  = get_pages( array( 'post_status' => 'publish' ) );
    $result = array();
    foreach ( $pages as $page ) {
        $result[] = array(
            'id'     => $page->ID,
            'title'  => $page->post_title,
            'url'    => get_permalink( $page->ID ),
            'parent' => $page->post_parent,
            'status' => $page->post_status,
        );
    }
    return array( 'pages' => $result );
}

function wsp_execute_get_page_templates( $input ) {
    if ( ! function_exists( 'get_page_templates' ) ) {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
    }
    $templates = get_page_templates();
    $result    = array( array( 'name' => 'Default', 'file' => 'default' ) );
    foreach ( $templates as $name => $file ) {
        $result[] = array( 'name' => $name, 'file' => $file );
    }
    return array( 'templates' => $result );
}

function wsp_execute_create_page( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => wp_kses_post( wp_unslash( $input['content'] ) ),
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
        'post_type'    => 'page',
    );
    if ( ! empty( $input['parent'] ) )   $args['post_parent']   = intval( $input['parent'] );
    if ( ! empty( $input['slug'] ) )     $args['post_name']     = sanitize_title( $input['slug'] );
    if ( ! empty( $input['template'] ) ) $args['page_template'] = sanitize_text_field( wp_unslash( $input['template'] ) );
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );

    $elementor_initialized = false;
    if ( ! empty( $input['elementor'] ) && wsp_elementor_is_active() ) {
        wsp_elementor_save_data( $id, array() );
        $elementor_initialized = true;
    }

    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'elementor' => $elementor_initialized );
}

function wsp_execute_update_page( $input ) {
    $post = wsp_mcp_guard_edit_post( isset( $input['id'] ) ? $input['id'] : 0, 'page' );
    if ( is_wp_error( $post ) ) return $post;
    if ( isset( $input['status'] ) ) {
        $status_check = wsp_mcp_guard_post_status( $post, $input['status'] );
        if ( is_wp_error( $status_check ) ) return $status_check;
    }
    $args = array( 'ID' => $post->ID, 'post_type' => 'page' );
    if ( isset( $input['title'] ) )   $args['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) ) $args['post_content'] = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) )  $args['post_status']  = sanitize_text_field( wp_unslash( $input['status'] ) );
    if ( isset( $input['template'] ) ) $args['page_template'] = sanitize_text_field( wp_unslash( $input['template'] ) );
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ) );
}

function wsp_execute_delete_page( $input ) {
    $post = wsp_mcp_guard_delete_post( isset( $input['id'] ) ? $input['id'] : 0, 'page' );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Page {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash page.' );
}
