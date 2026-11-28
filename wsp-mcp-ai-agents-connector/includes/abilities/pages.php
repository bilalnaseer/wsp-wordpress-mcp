<?php
/**
 * Page abilities: list, create, update and trash pages.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns published pages.
 *
 * MCP tool: `wsp_get_pages`. Any authenticated user may call it.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Creates a new page (optionally Elementor-initialized).
 *
 * To schedule it, pass status "future" (or "publish") with a future date.
 *
 * MCP tool: `wsp_create_page`. Requires the `publish_pages` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $title Required.
 *     @type string $content Required.
 *     @type string $status Optional.
 *     @type string $date Optional. Publish date, ISO 8601 (e.g. 2026-12-01T09:00:00). Site timezone
 *         unless an offset or Z is given. A future date with status "publish" or "future" schedules
 *         the item; status "future" requires a future date.
 *     @type int $parent Optional.
 *     @type string $slug Optional.
 *     @type bool $elementor Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_create_page( $input ) {
    $args = array(
        'post_title'   => sanitize_text_field( wp_unslash( $input['title'] ) ),
        'post_content' => wp_kses_post( wp_unslash( $input['content'] ) ),
        'post_status'  => isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : 'draft',
        'post_type'    => 'page',
    );
    if ( ! empty( $input['parent'] ) ) $args['post_parent'] = intval( $input['parent'] );
    if ( ! empty( $input['slug'] ) )   $args['post_name']   = sanitize_title( $input['slug'] );
    $date_check = wsp_mcp_apply_post_date( $args, $input );
    if ( is_wp_error( $date_check ) ) return $date_check;
    $id = wp_insert_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );

    $elementor_initialized = false;
    if ( ! empty( $input['elementor'] ) && wsp_elementor_is_active() ) {
        wsp_elementor_save_data( $id, array() );
        $elementor_initialized = true;
    }

    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ), 'elementor' => $elementor_initialized );
}

/**
 * Updates an existing page by ID.
 *
 * MCP tool: `wsp_update_page`. Requires the `edit_pages` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 *     @type string $title Optional.
 *     @type string $content Optional.
 *     @type string $status Optional.
 *     @type string $date Optional. Publish date, ISO 8601 (e.g. 2026-12-01T09:00:00). Site timezone
 *         unless an offset or Z is given. A future date with status "publish" or "future" schedules
 *         the item; status "future" requires a future date.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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
    $date_check = wsp_mcp_apply_post_date( $args, $input, $post );
    if ( is_wp_error( $date_check ) ) return $date_check;
    $id = wp_update_post( $args, true );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'url' => get_permalink( $id ), 'status' => get_post_status( $id ), 'date' => get_post_field( 'post_date', $id ) );
}

/**
 * Moves a page to trash by ID.
 *
 * MCP tool: `wsp_delete_page`. Requires the `delete_pages` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_delete_page( $input ) {
    $post = wsp_mcp_guard_delete_post( isset( $input['id'] ) ? $input['id'] : 0, 'page' );
    if ( is_wp_error( $post ) ) return $post;
    $id = $post->ID;
    return wp_trash_post( $id )
        ? array( 'success' => true,  'message' => "Page {$id} moved to trash." )
        : array( 'success' => false, 'error'   => 'Could not trash page.' );
}
