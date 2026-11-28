<?php
/**
 * Yoast SEO abilities: read and update SEO titles, descriptions and focus keywords for posts and terms.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether Yoast SEO is active.
 *
 * @return bool
 */
function wsp_yoast_is_active() {
    return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
}

/**
 * Read one Yoast SEO field from a post.
 *
 * @param int    $post_id Post ID.
 * @param string $key     One of title, metadesc or focuskw.
 * @return string
 */
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

/**
 * Save one Yoast SEO field on a post.
 *
 * @param int    $post_id Post ID.
 * @param string $key     One of title, metadesc or focuskw.
 * @param string $value   New value.
 */
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

/**
 * Fire wp_insert_post so that Yoast rebuilds the post's indexable.
 *
 * @param int $post_id Post ID.
 */
function wsp_yoast_rebuild_indexable( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }

    do_action( 'wp_insert_post', $post_id, $post, true );
}

/**
 * Check the post exists and the current user may edit it.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error
 */
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

/**
 * Check the term exists and the current user may edit terms in its taxonomy.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @return WP_Term|WP_Error
 */
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

/**
 * Read one Yoast SEO field from a term.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @param string $key      One of title, desc or focuskw.
 * @return string
 */
function wsp_yoast_get_term_meta( $term_id, $taxonomy, $key ) {
    $map = array(
        'title'   => 'wpseo_title',
        'desc'    => 'wpseo_desc',
        'focuskw' => 'wpseo_focuskw',
    );

    // Try Yoast's own API (option-based store, pre-20.x).
    if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
        $value = WPSEO_Taxonomy_Meta::get_term_meta( $term_id, $taxonomy, $key );
        if ( is_string( $value ) && '' !== $value ) {
            return $value;
        }
    }

    // Fallback: direct term meta (Yoast 20+ / WooCommerce SEO integration).
    if ( ! isset( $map[ $key ] ) ) {
        return '';
    }

    $value = get_term_meta( $term_id, $map[ $key ], true );
    return is_string( $value ) ? $value : '';
}

/**
 * Save one Yoast SEO field on a term.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @param string $key      One of title, desc or focuskw.
 * @param string $value    New value.
 */
function wsp_yoast_set_term_meta( $term_id, $taxonomy, $key, $value ) {
    $map = array(
        'title'   => 'wpseo_title',
        'desc'    => 'wpseo_desc',
        'focuskw' => 'wpseo_focuskw',
    );

    // Always write via Yoast's option store when available.
    if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
        WPSEO_Taxonomy_Meta::set_value( $key, $value, $taxonomy, $term_id );
    }

    // Also write directly to term meta so Yoast 20+ and WooCommerce SEO read it back correctly.
    if ( isset( $map[ $key ] ) ) {
        if ( '' === $value ) {
            delete_term_meta( $term_id, $map[ $key ] );
        } else {
            update_term_meta( $term_id, $map[ $key ], $value );
        }
    }
}

/**
 * Build the SEO data array returned for a term.
 *
 * @param WP_Term $term Term.
 * @return array
 */
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

/**
 * Build the SEO data array returned for a post.
 *
 * @param WP_Post $post Post.
 * @return array
 */
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

/**
 * Get Yoast SEO title, meta description, and focus keyphrase for any post type including WooCommerce
 * products.
 *
 * MCP tool: `wsp_yoast_get_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_yoast_get_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_yoast_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    return wsp_yoast_format_seo_data( $post );
}

/**
 * Update Yoast SEO title, meta description, and/or focus keyphrase for any post type including
 * WooCommerce products.
 *
 * MCP tool: `wsp_yoast_update_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 *     @type string $seo_title Optional.
 *     @type string $meta_description Optional.
 *     @type string $focus_keyphrase Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Get Yoast SEO title, meta description, and focus keyphrase for a taxonomy term (e.g.
 *
 * WooCommerce product_cat, product_tag, product_brand).
 *
 * MCP tool: `wsp_yoast_get_term_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $term_id Required. Term ID.
 *     @type string $taxonomy Required. Taxonomy slug, e.g. product_cat, product_tag, product_brand.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Update Yoast SEO title, meta description, and/or focus keyphrase for a taxonomy term (e.g.
 *
 * WooCommerce product_cat, product_tag, product_brand).
 *
 * MCP tool: `wsp_yoast_update_term_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $term_id Required. Term ID.
 *     @type string $taxonomy Required. Taxonomy slug, e.g. product_cat, product_tag, product_brand.
 *     @type string $seo_title Optional.
 *     @type string $meta_description Optional.
 *     @type string $focus_keyphrase Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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
