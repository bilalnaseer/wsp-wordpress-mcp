<?php
/**
 * Object-level capability guards for write abilities.
 *
 * The native tool registry records ONE broad primitive capability per tool
 * (e.g. `edit_posts`) and the server checks only that before invoking a
 * callback. A primitive check cannot answer "may this user act on THIS
 * object?" — a Contributor holds `edit_posts` but must not edit an
 * Administrator-owned post, a page, or publish content.
 *
 * Every write callback that accepts a caller-supplied object ID must call the
 * matching guard here and bail on a WP_Error. These mirror the meta-capability
 * checks WordPress core's own REST controllers perform.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Guard an edit against a specific post object.
 *
 * @param int               $id            Post ID supplied by the caller.
 * @param string|string[]|null $allowed_types Restrict to these post type(s); null allows any.
 * @return WP_Post|WP_Error The post on success, WP_Error if missing / wrong type / forbidden.
 */
function wsp_mcp_guard_edit_post( $id, $allowed_types = null ) {
	$id   = intval( $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Object ' . esc_html( $id ) . ' was not found.' );
	}
	if ( null !== $allowed_types && ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
		return new WP_Error( 'invalid_post_type', 'Object ' . esc_html( $id ) . " is a '" . esc_html( $post->post_type ) . "', which this tool cannot modify." );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'forbidden', 'You do not have permission to edit object ' . esc_html( $id ) . '.' );
	}
	return $post;
}

/**
 * Guard a delete/trash against a specific post object.
 *
 * @param int               $id            Post ID supplied by the caller.
 * @param string|string[]|null $allowed_types Restrict to these post type(s); null allows any.
 * @return WP_Post|WP_Error
 */
function wsp_mcp_guard_delete_post( $id, $allowed_types = null ) {
	$id   = intval( $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Object ' . esc_html( $id ) . ' was not found.' );
	}
	if ( null !== $allowed_types && ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
		return new WP_Error( 'invalid_post_type', 'Object ' . esc_html( $id ) . " is a '" . esc_html( $post->post_type ) . "', which this tool cannot delete." );
	}
	if ( ! current_user_can( 'delete_post', $id ) ) {
		return new WP_Error( 'forbidden', 'You do not have permission to delete object ' . esc_html( $id ) . '.' );
	}
	return $post;
}

/**
 * Guard a requested status transition. Publishing / scheduling / privatising
 * content requires the post type's publish capability, which a Contributor
 * lacks.
 *
 * @param WP_Post $post   The target post.
 * @param string  $status Requested post_status.
 * @return true|WP_Error
 */
function wsp_mcp_guard_post_status( $post, $status ) {
	$status = sanitize_key( $status );
	if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
		return true;
	}
	$type = get_post_type_object( $post->post_type );
	if ( ! $type || ! current_user_can( $type->cap->publish_posts ) ) {
		return new WP_Error( 'forbidden', 'You do not have permission to set status \'' . esc_html( $status ) . "' on object " . esc_html( $post->ID ) . '.' );
	}
	return true;
}

/**
 * Apply the caller's `date` input to a post array before wp_insert_post() /
 * wp_update_post(). Without it, status 'future' was saved with the current
 * time and WordPress silently published the item immediately (issue #60).
 *
 * The date is read in the site's timezone unless the string carries its own
 * offset (e.g. `2026-12-01T09:00:00Z`). A date in the future with status
 * 'publish' is scheduled by WordPress itself, as in the editor.
 *
 * @param array        $args  Post array being built; post_date / post_date_gmt / edit_date are set on it.
 * @param array        $input Tool input (reads `date`).
 * @param WP_Post|null $post  Existing post on update, null on create.
 * @return true|WP_Error
 */
function wsp_mcp_apply_post_date( &$args, $input, $post = null ) {
	if ( isset( $args['post_status'] ) ) {
		$status = $args['post_status'];
	} else {
		$status = $post ? $post->post_status : 'draft';
	}

	if ( isset( $input['date'] ) && ! is_scalar( $input['date'] ) ) {
		return new WP_Error( 'invalid_date', "'date' must be an ISO 8601 string, e.g. 2026-12-01T09:00:00." );
	}

	if ( isset( $input['date'] ) && '' !== trim( (string) $input['date'] ) ) {
		$raw = sanitize_text_field( (string) $input['date'] );
		$dt  = date_create_immutable( $raw, wp_timezone() );
		if ( false === $dt ) {
			return new WP_Error( 'invalid_date', "Could not parse date '" . esc_html( $raw ) . "'. Use ISO 8601, e.g. 2026-12-01T09:00:00 (site timezone) or 2026-12-01T09:00:00Z (UTC)." );
		}
		$args['post_date']     = $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		$args['post_date_gmt'] = $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$args['edit_date']     = true;
		$timestamp             = $dt->getTimestamp();
	} elseif ( 'future' === $status ) {
		// No date supplied: an update may keep the post's existing (future) date.
		$timestamp = ( $post && '0000-00-00 00:00:00' !== $post->post_date_gmt ) ? strtotime( $post->post_date_gmt . ' UTC' ) : 0;
	} else {
		return true;
	}

	if ( 'future' === $status && $timestamp <= time() ) {
		return new WP_Error( 'invalid_date', "Status 'future' needs a 'date' in the future (site timezone: " . wp_timezone_string() . '); otherwise WordPress publishes immediately.' );
	}
	return true;
}
