<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_yoast_is_active() {
    return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
}

function wsp_yoast_get_meta( $post_id, $key ) {
    if ( class_exists( 'WPSEO_Meta' ) ) {
        return WPSEO_Meta::get_value( $key, $post_id );
    }

    $map = array(
        'title'    => '_yoast_wpseo_title',
        'metadesc' => '_yoast_wpseo_metadesc',
        'focuskw'  => '_yoast_wpseo_focuskw',
    );

    if ( ! isset( $map[ $key ] ) ) {
        return '';
    }

    $value = get_post_meta( $post_id, $map[ $key ], true );
    return is_string( $value ) ? $value : '';
}

function wsp_yoast_set_meta( $post_id, $key, $value ) {
    if ( class_exists( 'WPSEO_Meta' ) ) {
        WPSEO_Meta::set_value( $key, $value, $post_id );
        return;
    }

    $map = array(
        'title'    => '_yoast_wpseo_title',
        'metadesc' => '_yoast_wpseo_metadesc',
        'focuskw'  => '_yoast_wpseo_focuskw',
    );

    if ( isset( $map[ $key ] ) ) {
        update_post_meta( $post_id, $map[ $key ], $value );
    }
}

function wsp_yoast_rebuild_indexable( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    do_action( 'wp_insert_post', $post_id, $post, true );
}

function wsp_yoast_validate_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'post_not_found', 'Post not found.' );
    }

    if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
        return new WP_Error( 'invalid_post_type', 'Yoast SEO meta is only supported for posts and pages.' );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
    }

    return $post;
}

function wsp_yoast_format_seo_data( $post ) {
    $post_id = $post->ID;

    return array(
        'post_id'          => $post_id,
        'post_type'        => $post->post_type,
        'title'            => get_the_title( $post_id ),
        'seo_title'        => wsp_yoast_get_meta( $post_id, 'title' ),
        'meta_description' => wsp_yoast_get_meta( $post_id, 'metadesc' ),
        'focus_keyphrase'  => wsp_yoast_get_meta( $post_id, 'focuskw' ),
        'url'              => get_permalink( $post_id ),
    );
}

// ─────────────────────────────────────────────
// REGISTRATION
// ─────────────────────────────────────────────

function wsp_register_yoast_abilities() {
    if ( ! wsp_yoast_is_active() ) {
        return;
    }

    $base     = array( 'category' => 'wsp', 'output_schema' => array( 'type' => 'object' ), 'meta' => array( 'mcp' => array( 'public' => true ) ) );
    $can_edit = function() { return current_user_can( 'edit_posts' ); };

    if ( wsp_mcp_is_enabled( 'wsp/yoast-get-seo' ) ) {
        wp_register_ability( 'wsp/yoast-get-seo', array_merge( $base, array(
            'label'              => 'Get Yoast SEO Meta',
            'description'        => 'Returns Yoast SEO title, meta description, and focus keyphrase for a post or page.',
            'input_schema'       => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'   => 'wsp_execute_yoast_get_seo',
        ) ) );
    }

    if ( wsp_mcp_is_enabled( 'wsp/yoast-update-seo' ) ) {
        wp_register_ability( 'wsp/yoast-update-seo', array_merge( $base, array(
            'label'              => 'Update Yoast SEO Meta',
            'description'        => 'Updates Yoast SEO title, meta description, and/or focus keyphrase for a post or page.',
            'input_schema'       => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'               => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
                'seo_title'        => array( 'type' => 'string', 'description' => 'Yoast SEO title (supports variables like %%title%%).' ),
                'meta_description' => array( 'type' => 'string', 'description' => 'Yoast meta description.' ),
                'focus_keyphrase'  => array( 'type' => 'string', 'description' => 'Yoast focus keyphrase.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'   => 'wsp_execute_yoast_update_seo',
        ) ) );
    }
}

// ─────────────────────────────────────────────
// EXECUTE
// ─────────────────────────────────────────────

function wsp_execute_yoast_get_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_yoast_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    return wsp_yoast_format_seo_data( $post );
}

function wsp_execute_yoast_update_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_yoast_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    $updated = array();

    if ( isset( $input['seo_title'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['seo_title'] ) );
        wsp_yoast_set_meta( $post_id, 'title', $value );
        $updated['seo_title'] = $value;
    }

    if ( isset( $input['meta_description'] ) ) {
        $value = sanitize_textarea_field( wp_unslash( $input['meta_description'] ) );
        wsp_yoast_set_meta( $post_id, 'metadesc', $value );
        $updated['meta_description'] = $value;
    }

    if ( isset( $input['focus_keyphrase'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['focus_keyphrase'] ) );
        wsp_yoast_set_meta( $post_id, 'focuskw', $value );
        $updated['focus_keyphrase'] = $value;
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'no_fields', 'Provide at least one of: seo_title, meta_description, focus_keyphrase.' );
    }

    wsp_yoast_rebuild_indexable( $post_id );

    return array(
        'success' => true,
        'id'      => $post_id,
        'updated' => $updated,
        'seo'     => wsp_yoast_format_seo_data( get_post( $post_id ) ),
    );
}
