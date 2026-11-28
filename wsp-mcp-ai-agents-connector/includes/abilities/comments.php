<?php
/**
 * Comment abilities: list, approve and trash comments.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns comments.
 *
 * MCP tool: `wsp_get_comments`. Requires the `moderate_comments` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $status Optional. hold | approve | all.
 *     @type int $per_page Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_comments( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $status   = isset( $input['status'] )   ? sanitize_text_field( wp_unslash( $input['status'] ) ) : '';
    $args     = array( 'number' => $per_page );
    if ( $status && 'all' !== $status ) $args['status'] = $status;
    $comments = get_comments( $args );
    $result   = array();
    foreach ( $comments as $c ) {
        $result[] = array(
            'id'      => $c->comment_ID,
            'post_id' => $c->comment_post_ID,
            'author'  => $c->comment_author,
            'email'   => $c->comment_author_email,
            'content' => wp_trim_words( $c->comment_content, 20 ),
            'status'  => $c->comment_approved,
            'date'    => $c->comment_date,
        );
    }
    return array( 'comments' => $result, 'total' => count( $result ) );
}

/**
 * Approves a pending comment by ID.
 *
 * MCP tool: `wsp_approve_comment`. Requires the `moderate_comments` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_approve_comment( $input ) {
    return wp_set_comment_status( intval( $input['id'] ), 'approve' )
        ? array( 'success' => true,  'message' => 'Comment approved.' )
        : array( 'success' => false, 'error'   => 'Failed to approve.' );
}

/**
 * Trashes a comment by ID.
 *
 * MCP tool: `wsp_delete_comment`. Requires the `moderate_comments` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_delete_comment( $input ) {
    return wp_trash_comment( intval( $input['id'] ) )
        ? array( 'success' => true,  'message' => 'Comment trashed.' )
        : array( 'success' => false, 'error'   => 'Failed to trash comment.' );
}
