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

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'forbidden', 'You do not have permission to edit this post.' );
    }

    return $post;
}

function wsp_yoast_validate_term( $term_id, $taxonomy ) {
    if ( ! taxonomy_exists( $taxonomy ) ) {
        return new WP_Error( 'invalid_taxonomy', 'Taxonomy "' . $taxonomy . '" does not exist.' );
    }

    $term = get_term( $term_id, $taxonomy );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'term_not_found', 'Term not found.' );
    }

    $tax_obj = get_taxonomy( $taxonomy );
    $cap     = $tax_obj ? $tax_obj->cap->edit_terms : 'manage_categories';
    if ( ! current_user_can( $cap ) ) {
        return new WP_Error( 'forbidden', 'You do not have permission to edit terms in this taxonomy.' );
    }

    return $term;
}

function wsp_yoast_get_term_meta( $term_id, $taxonomy, $key ) {
    $map = array(
        'title'   => 'wpseo_title',
        'desc'    => 'wpseo_desc',
        'focuskw' => 'wpseo_focuskw',
    );

    // Try Yoast's own API (option-based store, pre-20.x)
    if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
        $value = WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, $key );
        if ( is_string( $value ) && '' !== $value ) {
            return $value;
        }
    }

    // Fallback: direct term meta (Yoast 20+ / WooCommerce SEO integration)
    if ( ! isset( $map[ $key ] ) ) {
        return '';
    }

    $value = get_term_meta( $term_id, $map[ $key ], true );
    return is_string( $value ) ? $value : '';
}

function wsp_yoast_set_term_meta( $term_id, $taxonomy, $key, $value ) {
    $map = array(
        'title'   => 'wpseo_title',
        'desc'    => 'wpseo_desc',
        'focuskw' => 'wpseo_focuskw',
    );

    // Always write via Yoast's option store when available
    if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
        WPSEO_Taxonomy_Meta::set_value( $key, $value, $taxonomy, $term_id );
    }

    // Also write directly to term meta so Yoast 20+ and WooCommerce SEO read it back correctly
    if ( isset( $map[ $key ] ) ) {
        if ( '' === $value ) {
            delete_term_meta( $term_id, $map[ $key ] );
        } else {
            update_term_meta( $term_id, $map[ $key ], $value );
        }
    }
}

function wsp_yoast_format_term_seo_data( $term ) {
    $term_id  = $term->term_id;
    $taxonomy = $term->taxonomy;

    return array(
        'term_id'          => $term_id,
        'taxonomy'         => $taxonomy,
        'name'             => $term->name,
        'seo_title'        => wsp_yoast_get_term_meta( $term_id, $taxonomy, 'title' ),
        'meta_description' => wsp_yoast_get_term_meta( $term_id, $taxonomy, 'desc' ),
        'focus_keyphrase'  => wsp_yoast_get_term_meta( $term_id, $taxonomy, 'focuskw' ),
        'url'              => get_term_link( $term ),
    );
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

function wsp_execute_yoast_get_term_seo( $input ) {
    $term_id  = intval( $input['term_id'] );
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

    if ( ! $taxonomy ) {
        return new WP_Error( 'missing_taxonomy', 'Provide a taxonomy (e.g. product_cat, product_tag, product_brand).' );
    }

    $term = wsp_yoast_validate_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) ) {
        return $term;
    }

    return wsp_yoast_format_term_seo_data( $term );
}

function wsp_execute_yoast_update_term_seo( $input ) {
    $term_id  = intval( $input['term_id'] );
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

    if ( ! $taxonomy ) {
        return new WP_Error( 'missing_taxonomy', 'Provide a taxonomy (e.g. product_cat, product_tag, product_brand).' );
    }

    $term = wsp_yoast_validate_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) ) {
        return $term;
    }

    $updated = array();

    if ( isset( $input['seo_title'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['seo_title'] ) );
        wsp_yoast_set_term_meta( $term->term_id, $taxonomy, 'title', $value );
        $updated['seo_title'] = $value;
    }

    if ( isset( $input['meta_description'] ) ) {
        $value = sanitize_textarea_field( wp_unslash( $input['meta_description'] ) );
        wsp_yoast_set_term_meta( $term->term_id, $taxonomy, 'desc', $value );
        $updated['meta_description'] = $value;
    }

    if ( isset( $input['focus_keyphrase'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['focus_keyphrase'] ) );
        wsp_yoast_set_term_meta( $term->term_id, $taxonomy, 'focuskw', $value );
        $updated['focus_keyphrase'] = $value;
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'no_fields', 'Provide at least one of: seo_title, meta_description, focus_keyphrase.' );
    }

    return array(
        'success' => true,
        'term_id' => $term->term_id,
        'updated' => $updated,
        'seo'     => wsp_yoast_format_term_seo_data( get_term( $term->term_id, $taxonomy ) ),
    );
}
