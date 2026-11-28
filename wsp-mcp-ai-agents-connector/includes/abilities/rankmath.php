<?php
/**
 * Rank Math SEO abilities: read and update SEO titles, descriptions and focus keywords for posts and terms.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether Rank Math SEO is active.
 *
 * @return bool
 */
function wsp_rankmath_is_active() {
    return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
}

/**
 * Map a short field name to its Rank Math post-meta key.
 *
 * @param string $key One of title, description or focus_keyword.
 * @return string The meta key, or an empty string when unknown.
 */
function wsp_rankmath_meta_key( $key ) {
    $map = array(
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
    );

    return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/**
 * Read one Rank Math field from a post.
 *
 * @param int    $post_id Post ID.
 * @param string $key     One of title, description or focus_keyword.
 * @return string
 */
function wsp_rankmath_get_meta( $post_id, $key ) {
    $meta_key = wsp_rankmath_meta_key( $key );
    if ( '' === $meta_key ) {
        return '';
    }

    $value = get_post_meta( $post_id, $meta_key, true );
    return is_string( $value ) ? $value : '';
}

/**
 * Save one Rank Math field on a post. An empty value deletes it so Rank Math falls back to its global
 * template.
 *
 * @param int    $post_id Post ID.
 * @param string $key     One of title, description or focus_keyword.
 * @param string $value   New value.
 */
function wsp_rankmath_set_meta( $post_id, $key, $value ) {
    $meta_key = wsp_rankmath_meta_key( $key );
    if ( '' === $meta_key ) {
        return;
    }

    // Rank Math treats missing meta as "use the global template",
    // so clearing a field should remove the row rather than store ''.
    if ( '' === $value ) {
        delete_post_meta( $post_id, $meta_key );
        return;
    }

    update_post_meta( $post_id, $meta_key, $value );
}

/**
 * Check the post exists and the current user may edit it.
 *
 * @param int $post_id Post ID.
 * @return WP_Post|WP_Error
 */
function wsp_rankmath_validate_post( $post_id ) {
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
function wsp_rankmath_validate_term( $term_id, $taxonomy ) {
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
 * Read one Rank Math field from a term.
 *
 * @param int    $term_id Term ID.
 * @param string $key     One of title, description or focus_keyword.
 * @return string
 */
function wsp_rankmath_get_term_meta( $term_id, $key ) {
    $map = array(
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
    );

    if ( ! isset( $map[ $key ] ) ) {
        return '';
    }

    $value = get_term_meta( $term_id, $map[ $key ], true );
    return is_string( $value ) ? $value : '';
}

/**
 * Save one Rank Math field on a term. An empty value deletes it.
 *
 * @param int    $term_id Term ID.
 * @param string $key     One of title, description or focus_keyword.
 * @param string $value   New value.
 */
function wsp_rankmath_set_term_meta( $term_id, $key, $value ) {
    $map = array(
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
    );

    if ( ! isset( $map[ $key ] ) ) {
        return;
    }

    if ( '' === $value ) {
        delete_term_meta( $term_id, $map[ $key ] );
        return;
    }

    update_term_meta( $term_id, $map[ $key ], $value );
}

/**
 * Build the SEO data array returned for a term.
 *
 * @param WP_Term $term Term.
 * @return array
 */
function wsp_rankmath_format_term_seo_data( $term ) {
    $term_id = $term->term_id;
    $score   = get_term_meta( $term_id, 'rank_math_seo_score', true );

    return array(
        'term_id'          => $term_id,
        'taxonomy'         => $term->taxonomy,
        'name'             => $term->name,
        'seo_title'        => wsp_rankmath_get_term_meta( $term_id, 'title' ),
        'meta_description' => wsp_rankmath_get_term_meta( $term_id, 'description' ),
        'focus_keyword'    => wsp_rankmath_get_term_meta( $term_id, 'focus_keyword' ),
        'seo_score'        => '' === $score ? null : intval( $score ),
        'url'              => get_term_link( $term ),
    );
}

/**
 * Build the SEO data array returned for a post.
 *
 * @param WP_Post $post Post.
 * @return array
 */
function wsp_rankmath_format_seo_data( $post ) {
    $post_id = $post->ID;
    $score   = get_post_meta( $post_id, 'rank_math_seo_score', true );

    return array(
        'post_id'          => $post_id,
        'post_type'        => $post->post_type,
        'title'            => get_the_title( $post_id ),
        'seo_title'        => wsp_rankmath_get_meta( $post_id, 'title' ),
        'meta_description' => wsp_rankmath_get_meta( $post_id, 'description' ),
        'focus_keyword'    => wsp_rankmath_get_meta( $post_id, 'focus_keyword' ),
        'seo_score'        => '' === $score ? null : intval( $score ),
        'url'              => get_permalink( $post_id ),
    );
}

// ─────────────────────────────────────────────
// EXECUTE
// ─────────────────────────────────────────────

/**
 * Get Rank Math SEO title, meta description, focus keyword, and SEO score for any post type including
 * WooCommerce products.
 *
 * MCP tool: `wsp_rankmath_get_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_rankmath_get_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_rankmath_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    return wsp_rankmath_format_seo_data( $post );
}

/**
 * Update Rank Math SEO title, meta description, and/or focus keyword for any post type including
 * WooCommerce products.
 *
 * Focus keyword accepts multiple comma-separated keywords (first one is the primary). Pass an empty
 * string to clear a field back to the global template.
 *
 * MCP tool: `wsp_rankmath_update_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 *     @type string $seo_title Optional.
 *     @type string $meta_description Optional.
 *     @type string $focus_keyword Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_rankmath_update_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_rankmath_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    $updated = array();

    if ( isset( $input['seo_title'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['seo_title'] ) );
        wsp_rankmath_set_meta( $post_id, 'title', $value );
        $updated['seo_title'] = $value;
    }

    if ( isset( $input['meta_description'] ) ) {
        $value = sanitize_textarea_field( wp_unslash( $input['meta_description'] ) );
        wsp_rankmath_set_meta( $post_id, 'description', $value );
        $updated['meta_description'] = $value;
    }

    if ( isset( $input['focus_keyword'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['focus_keyword'] ) );
        wsp_rankmath_set_meta( $post_id, 'focus_keyword', $value );
        $updated['focus_keyword'] = $value;
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'no_fields', 'Provide at least one of: seo_title, meta_description, focus_keyword.' );
    }

    return array(
        'success' => true,
        'id'      => $post_id,
        'updated' => $updated,
        'seo'     => wsp_rankmath_format_seo_data( get_post( $post_id ) ),
    );
}

/**
 * Get Rank Math SEO title, meta description, focus keyword, and SEO score for a taxonomy term (e.g.
 *
 * WooCommerce product_cat, product_tag, product_brand).
 *
 * MCP tool: `wsp_rankmath_get_term_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $term_id Required. Term ID.
 *     @type string $taxonomy Required. Taxonomy slug, e.g. product_cat, product_tag, product_brand.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_rankmath_get_term_seo( $input ) {
    $term_id  = intval( $input['term_id'] );
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

    if ( ! $taxonomy ) {
        return new WP_Error( 'missing_taxonomy', 'Provide a taxonomy (e.g. product_cat, product_tag, product_brand).' );
    }

    $term = wsp_rankmath_validate_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) ) {
        return $term;
    }

    return wsp_rankmath_format_term_seo_data( $term );
}

/**
 * Update Rank Math SEO title, meta description, and/or focus keyword for a taxonomy term (e.g.
 *
 * WooCommerce product_cat, product_tag, product_brand). Pass an empty string to clear a field.
 *
 * MCP tool: `wsp_rankmath_update_term_seo`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $term_id Required. Term ID.
 *     @type string $taxonomy Required. Taxonomy slug, e.g. product_cat, product_tag, product_brand.
 *     @type string $seo_title Optional.
 *     @type string $meta_description Optional.
 *     @type string $focus_keyword Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_rankmath_update_term_seo( $input ) {
    $term_id  = intval( $input['term_id'] );
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

    if ( ! $taxonomy ) {
        return new WP_Error( 'missing_taxonomy', 'Provide a taxonomy (e.g. product_cat, product_tag, product_brand).' );
    }

    $term = wsp_rankmath_validate_term( $term_id, $taxonomy );
    if ( is_wp_error( $term ) ) {
        return $term;
    }

    $updated = array();

    if ( isset( $input['seo_title'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['seo_title'] ) );
        wsp_rankmath_set_term_meta( $term->term_id, 'title', $value );
        $updated['seo_title'] = $value;
    }

    if ( isset( $input['meta_description'] ) ) {
        $value = sanitize_textarea_field( wp_unslash( $input['meta_description'] ) );
        wsp_rankmath_set_term_meta( $term->term_id, 'description', $value );
        $updated['meta_description'] = $value;
    }

    if ( isset( $input['focus_keyword'] ) ) {
        $value = sanitize_text_field( wp_unslash( $input['focus_keyword'] ) );
        wsp_rankmath_set_term_meta( $term->term_id, 'focus_keyword', $value );
        $updated['focus_keyword'] = $value;
    }

    if ( empty( $updated ) ) {
        return new WP_Error( 'no_fields', 'Provide at least one of: seo_title, meta_description, focus_keyword.' );
    }

    return array(
        'success' => true,
        'term_id' => $term->term_id,
        'updated' => $updated,
        'seo'     => wsp_rankmath_format_term_seo_data( get_term( $term->term_id, $taxonomy ) ),
    );
}
