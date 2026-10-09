<?php
/**
 * Post revisions: list, read, and restore.
 *
 * Works for any post type that supports revisions (posts, pages, and custom
 * post types), using WordPress' native revision functions. Access always goes
 * through the PARENT post's `edit_post` meta capability — the same rule core's
 * revisions REST controller applies — so a Contributor cannot read or restore
 * revisions of someone else's content just because they hold `edit_posts`.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Resolve a parent post for revision access.
 *
 * @param int $post_id Caller-supplied post ID.
 * @return WP_Post|WP_Error
 */
function wsp_revisions_resolve_parent( $post_id ) {
	$post = wsp_mcp_guard_edit_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
		return new WP_Error( 'revisions_unsupported', "Post type '" . esc_html( $post->post_type ) . "' does not support revisions." );
	}
	return $post;
}

/**
 * Resolve a revision and its parent, enforcing the parent-level capability.
 *
 * @param int $revision_id Caller-supplied revision ID.
 * @return array{0:WP_Post,1:WP_Post}|WP_Error [ revision, parent ]
 */
function wsp_revisions_resolve_revision( $revision_id ) {
	$revision_id = intval( $revision_id );
	$revision    = $revision_id ? wp_get_post_revision( $revision_id ) : null;
	if ( ! $revision ) {
		return new WP_Error( 'not_found', 'Revision ' . esc_html( $revision_id ) . ' was not found.' );
	}
	$parent = wsp_revisions_resolve_parent( $revision->post_parent );
	if ( is_wp_error( $parent ) ) {
		return $parent;
	}
	return array( $revision, $parent );
}

/**
 * Normalize one revision row.
 *
 * @param WP_Post $revision Revision post.
 * @param WP_Post $parent   Its parent post.
 * @param bool    $full     Include title/content/excerpt bodies.
 * @return array
 */
function wsp_revisions_item_data( $revision, $parent, $full = false ) {
	$author = get_userdata( (int) $revision->post_author );
	$data   = array(
		'id'            => (int) $revision->ID,
		'parent_id'     => (int) $revision->post_parent,
		'date_gmt'      => gmdate( 'c', strtotime( $revision->post_modified_gmt . ' UTC' ) ),
		'author_id'     => (int) $revision->post_author,
		'author'        => $author ? $author->display_name : '',
		'is_autosave'   => wp_is_post_autosave( $revision ) ? true : false,
		'title'         => $revision->post_title,
		'content_chars' => mb_strlen( $revision->post_content ),
		'changed_fields' => array_values( array_filter( array( 'title', 'content', 'excerpt' ), function ( $f ) use ( $revision, $parent ) {
			return $revision->{"post_$f"} !== $parent->{"post_$f"};
		} ) ),
	);
	if ( $full ) {
		$data['content'] = $revision->post_content;
		$data['excerpt'] = $revision->post_excerpt;
	}
	return $data;
}

function wsp_execute_get_revisions( $input ) {
	if ( empty( $input['post_id'] ) ) {
		return new WP_Error( 'missing_param', 'post_id is required.' );
	}
	$post = wsp_revisions_resolve_parent( $input['post_id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$limit = isset( $input['limit'] ) ? max( 1, min( 100, intval( $input['limit'] ) ) ) : 20;

	// Newest first. Returns an array keyed by revision ID, or false-ish when none.
	$all   = wp_get_post_revisions( $post->ID, array( 'order' => 'DESC', 'orderby' => 'date ID' ) );
	$all   = is_array( $all ) ? array_values( $all ) : array();
	$items = array();
	foreach ( array_slice( $all, 0, $limit ) as $revision ) {
		$items[] = wsp_revisions_item_data( $revision, $post );
	}

	return array(
		'post_id'    => (int) $post->ID,
		'post_type'  => $post->post_type,
		'post_title' => $post->post_title,
		'total'      => count( $all ),
		'returned'   => count( $items ),
		'revisions'  => $items,
	);
}

function wsp_execute_get_revision( $input ) {
	if ( empty( $input['id'] ) ) {
		return new WP_Error( 'missing_param', 'id is required.' );
	}
	$resolved = wsp_revisions_resolve_revision( $input['id'] );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $revision, $parent ) = $resolved;

	$data            = wsp_revisions_item_data( $revision, $parent, true );
	$data['current'] = array(
		'title'    => $parent->post_title,
		'content'  => $parent->post_content,
		'excerpt'  => $parent->post_excerpt,
		'modified' => gmdate( 'c', strtotime( $parent->post_modified_gmt . ' UTC' ) ),
	);
	return $data;
}

function wsp_execute_restore_revision( $input ) {
	if ( empty( $input['id'] ) ) {
		return new WP_Error( 'missing_param', 'id is required.' );
	}
	$resolved = wsp_revisions_resolve_revision( $input['id'] );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $revision, $parent ) = $resolved;

	// Restoring rewrites the live post, so the parent must be editable (checked
	// above) and the revision must really belong to it.
	if ( (int) $revision->post_parent !== (int) $parent->ID ) {
		return new WP_Error( 'invalid_revision', 'Revision does not belong to this post.' );
	}

	// wp_update_post() snapshots the CURRENT content as a new revision first, so
	// a restore can itself be undone by restoring that newer revision.
	$result = wp_restore_post_revision( $revision->ID );
	if ( ! $result ) {
		return new WP_Error( 'restore_failed', 'WordPress could not restore revision ' . esc_html( $revision->ID ) . '.' );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$updated = get_post( $parent->ID );
	return array(
		'success'     => true,
		'post_id'     => (int) $parent->ID,
		'restored_id' => (int) $revision->ID,
		'title'       => $updated->post_title,
		'status'      => $updated->post_status,
		'link'        => get_permalink( $parent->ID ),
		'note'        => 'Title, content and excerpt were restored. The previous live version was saved as a new revision.',
	);
}
