<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_register_pages_abilities() {
    $base = array( 'category' => 'wsp', 'output_schema' => array( 'type' => 'object' ), 'meta' => array( 'mcp' => array( 'public' => true ) ) );

    if ( wsp_mcp_is_enabled( 'wsp/get-pages' ) ) {
        wp_register_ability( 'wsp/get-pages', array_merge( $base, array(
            'label'              => 'Get Pages',
            'description'        => 'Returns published pages.',
            'input_schema'       => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => '__return_true',
            'execute_callback'   => 'wsp_execute_get_pages',
        ) ) );
    }

    if ( wsp_mcp_is_enabled( 'wsp/create-page' ) ) {
        wp_register_ability( 'wsp/create-page', array_merge( $base, array(
            'label'              => 'Create Page',
            'description'        => 'Creates a new page.',
            'input_schema'       => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => array(
                'title'    => array( 'type' => 'string',  'description' => 'Page title.' ),
                'content'  => array( 'type' => 'string',  'description' => 'Page content.' ),
                'status'   => array( 'type' => 'string',  'description' => 'publish | draft.' ),
                'parent'   => array( 'type' => 'integer', 'description' => 'Parent page ID.' ),
                'slug'     => array( 'type' => 'string',  'description' => 'URL slug.' ),
                'elementor' => array( 'type' => 'boolean', 'description' => 'Set true to initialize Elementor on the new page.' ),
            ) ),
            'permission_callback' => function() { return current_user_can( 'publish_pages' ); },
            'execute_callback'   => 'wsp_execute_create_page',
        ) ) );
    }

    if ( wsp_mcp_is_enabled( 'wsp/update-page' ) ) {
        wp_register_ability( 'wsp/update-page', array_merge( $base, array(
            'label'              => 'Update Page',
            'description'        => 'Updates an existing page.',
            'input_schema'       => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'      => array( 'type' => 'integer', 'description' => 'Page ID.' ),
                'title'   => array( 'type' => 'string',  'description' => 'New title.' ),
                'content' => array( 'type' => 'string',  'description' => 'New content.' ),
                'status'  => array( 'type' => 'string',  'description' => 'New status.' ),
            ) ),
            'permission_callback' => function() { return current_user_can( 'edit_pages' ); },
            'execute_callback'   => 'wsp_execute_update_page',
        ) ) );
    }

    if ( wsp_mcp_is_enabled( 'wsp/delete-page' ) ) {
        wp_register_ability( 'wsp/delete-page', array_merge( $base, array(
            'label'              => 'Delete Page',
            'description'        => 'Moves a page to trash.',
            'input_schema'       => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Page ID.' ),
            ) ),
            'permission_callback' => function() { return current_user_can( 'delete_pages' ); },
            'execute_callback'   => 'wsp_execute_delete_page',
        ) ) );
    }
}

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

function wsp_execute_create_page( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => wp_kses_post( wp_unslash( $input['content'] ) ),
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
        'post_type'    => 'page',
    );
    if ( ! empty( $input['parent'] ) ) $args['post_parent'] = intval( $input['parent'] );
    if ( ! empty( $input['slug'] ) )   $args['post_name']   = sanitize_title( $input['slug'] );
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
    $args = array( 'ID' => intval( $input['id'] ), 'post_type' => 'page' );
    if ( isset( $input['title'] ) )   $args['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['content'] ) ) $args['post_content'] = wp_kses_post( wp_unslash( $input['content'] ) );
    if ( isset( $input['status'] ) )  $args['post_status']  = sanitize_text_field( wp_unslash( $input['status'] ) );
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ) );
}

function wsp_execute_delete_page( $input ) {
    $id = intval( $input['id'] );
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Page {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash page.' );
}
