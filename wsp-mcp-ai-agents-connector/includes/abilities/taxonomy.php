<?php
/**
 * Taxonomy abilities: list and create categories and tags.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns all categories.
 *
 * MCP tool: `wsp_get_categories`. Any authenticated user may call it.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_categories( $input ) {
    $cats   = get_categories( array( 'hide_empty' => false ) );
    $result = array();
    foreach ( $cats as $c ) {
        $result[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent );
    }
    return array( 'categories' => $result );
}

/**
 * Creates a new category (taxonomy "category", or "product_cat" for WooCommerce product categories).
 *
 * MCP tool: `wsp_create_category`. Requires the `manage_categories` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required.
 *     @type string $taxonomy Optional. category (default) | product_cat.
 *     @type string $description Optional.
 *     @type int $parent Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_create_category( $input ) {
    $args = array();
    if ( ! empty( $input['description'] ) ) $args['description'] = sanitize_text_field( wp_unslash( $input['description'] ) );
    if ( ! empty( $input['parent'] ) )      $args['parent']      = intval( $input['parent'] );
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : 'category';
    if ( ! in_array( $taxonomy, array( 'category', 'product_cat' ), true ) || ! taxonomy_exists( $taxonomy ) ) {
        return array( 'success' => false, 'error' => 'taxonomy must be "category" or "product_cat" (WooCommerce).' );
    }
    if ( ! current_user_can( get_taxonomy( $taxonomy )->cap->manage_terms ) ) {
        return array( 'success' => false, 'error' => 'You do not have permission to create terms in ' . $taxonomy . '.' );
    }
    if ( ! empty( $args['parent'] ) && ! term_exists( $args['parent'], $taxonomy ) ) {
        return array( 'success' => false, 'error' => 'Parent term not found in ' . $taxonomy . '.' );
    }
    $result = wp_insert_term( sanitize_text_field( wp_unslash( $input['name'] ) ), $taxonomy, $args );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $result['term_id'], 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ), 'taxonomy' => $taxonomy );
}

/**
 * Returns all tags.
 *
 * MCP tool: `wsp_get_tags`. Any authenticated user may call it.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_tags( $input ) {
    $tags   = get_tags( array( 'hide_empty' => false ) );
    $result = array();
    foreach ( $tags as $t ) {
        $result[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
    }
    return array( 'tags' => $result );
}

/**
 * Creates a new tag.
 *
 * MCP tool: `wsp_create_tag`. Requires the `manage_categories` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Required.
 *     @type string $description Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_create_tag( $input ) {
    $args   = ! empty( $input['description'] ) ? array( 'description' => sanitize_text_field( wp_unslash( $input['description'] ) ) ) : array();
    $result = wp_insert_term( sanitize_text_field( wp_unslash( $input['name'] ) ), 'post_tag', $args );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $result['term_id'], 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) );
}
