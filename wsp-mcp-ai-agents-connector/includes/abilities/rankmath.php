<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_rankmath_is_active() {
    return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
}

function wsp_rankmath_meta_key( $key ) {
    $map = array(
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
    );

    return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

function wsp_rankmath_get_meta( $post_id, $key ) {
    $meta_key = wsp_rankmath_meta_key( $key );
    if ( '' === $meta_key ) {
        return '';
    }

    $value = get_post_meta( $post_id, $meta_key, true );
    return is_string( $value ) ? $value : '';
}

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

function wsp_execute_rankmath_get_seo( $input ) {
    $post_id = intval( $input['id'] );
    $post    = wsp_rankmath_validate_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }

    return wsp_rankmath_format_seo_data( $post );
}

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
