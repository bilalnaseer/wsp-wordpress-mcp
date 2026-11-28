<?php
/**
 * Search ability: find published posts and pages by keyword.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Search posts and pages by keyword.
 *
 * MCP tool: `wsp_search`. Any authenticated user may call it.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $query Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_search( $input ) {
    $keyword = isset( $input['query'] ) ? sanitize_text_field( wp_unslash( $input['query'] ) ) : '';
    $q       = new WP_Query( array( 's' => $keyword, 'post_status' => 'publish', 'posts_per_page' => 10 ) );
    $results = array();
    foreach ( $q->posts as $p ) {
        $results[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p->ID ), 'type' => $p->post_type );
    }
    return array( 'results' => $results, 'total' => $q->found_posts );
}
