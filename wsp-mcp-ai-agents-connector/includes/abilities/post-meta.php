<?php
/**
 * Post meta: read, update and delete custom fields on any post object.
 *
 * Post meta is where plugins keep settings, secrets and (Elementor, page
 * builders) executable-adjacent data, so this module is deliberately narrow:
 *
 * - Access is checked per object AND per key with WordPress' own meta
 *   capabilities (`edit_post_meta` / `delete_post_meta`). Core's map_meta_cap()
 *   turns those into `do_not_allow` for protected keys (leading underscore)
 *   unless the key was registered with an auth_callback, so `_elementor_data`,
 *   `_wp_attached_file`, `_edit_lock`, `_thumbnail_id` etc. cannot be touched.
 * - Protected keys are also hidden from reads, and omitted from "all meta".
 * - Written values are sanitized (wp_kses_post on every string), so meta
 *   cannot be used to plant <script> that a theme later prints.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Meta keys: letters, digits and _ - : . only, 1–255 chars. */
function wsp_post_meta_valid_key( $key ) {
	return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_\-:.]{1,255}$/', $key );
}

/** Recursively sanitize a meta value: strings through wp_kses_post, keys through sanitize_text_field. */
function wsp_post_meta_sanitize_value( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_string( $k ) ? sanitize_text_field( $k ) : $k ] = wsp_post_meta_sanitize_value( $v );
		}
		return $out;
	}
	if ( is_string( $value ) ) {
		return wp_kses_post( $value );
	}
	if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
		return $value;
	}
	return '';
}

/** Resolve the target post and the key, running the shared guards. */
function wsp_post_meta_resolve( $input, $need_key = true ) {
	if ( empty( $input['post_id'] ) ) {
		return new WP_Error( 'missing_param', 'post_id is required.' );
	}
	$post = wsp_mcp_guard_edit_post( $input['post_id'] );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( 'revision' === $post->post_type ) {
		return new WP_Error( 'invalid_post_type', 'Revisions have no editable meta. Use the revision tools.' );
	}
	$key = '';
	if ( isset( $input['key'] ) && '' !== $input['key'] ) {
		$key = is_string( $input['key'] ) ? trim( $input['key'] ) : '';
		if ( ! wsp_post_meta_valid_key( $key ) ) {
			return new WP_Error( 'invalid_key', 'key must be 1–255 characters of letters, digits, _ - : or .' );
		}
	} elseif ( $need_key ) {
		return new WP_Error( 'missing_param', 'key is required.' );
	}
	return array( $post, $key );
}

function wsp_execute_get_post_meta( $input ) {
	$resolved = wsp_post_meta_resolve( $input, false );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post, $key ) = $resolved;

	if ( '' !== $key ) {
		if ( is_protected_meta( $key, 'post' ) ) {
			return new WP_Error( 'protected_meta', "'" . esc_html( $key ) . "' is protected meta and cannot be read." );
		}
		$exists = metadata_exists( 'post', $post->ID, $key );
		$single = ! isset( $input['single'] ) || (bool) $input['single'];
		return array(
			'post_id' => (int) $post->ID,
			'key'     => $key,
			'exists'  => $exists,
			'value'   => $exists ? get_post_meta( $post->ID, $key, $single ) : null,
		);
	}

	// All public meta. get_post_meta( id ) returns key => [ raw values ].
	$meta  = array();
	$count = 0;
	foreach ( (array) get_post_meta( $post->ID ) as $k => $values ) {
		if ( is_protected_meta( $k, 'post' ) ) {
			continue;
		}
		if ( ++$count > 200 ) {
			break;
		}
		$values    = array_map( 'maybe_unserialize', (array) $values );
		$meta[ $k ] = ( 1 === count( $values ) ) ? $values[0] : $values;
	}
	return array(
		'post_id'   => (int) $post->ID,
		'post_type' => $post->post_type,
		'meta'      => (object) $meta,
		'returned'  => count( $meta ),
		'truncated' => $count > 200,
		'note'      => 'Protected keys (leading underscore) are not shown.',
	);
}

function wsp_execute_update_post_meta( $input ) {
	$resolved = wsp_post_meta_resolve( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post, $key ) = $resolved;
	if ( ! array_key_exists( 'value', $input ) ) {
		return new WP_Error( 'missing_param', 'value is required (use wsp_delete_post_meta to remove a key).' );
	}
	if ( ! current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
		return new WP_Error( 'forbidden', "You cannot edit meta key '" . esc_html( $key ) . "' on object " . esc_html( $post->ID ) . '. Protected keys (leading underscore) are not editable.' );
	}

	$value = wsp_post_meta_sanitize_value( $input['value'] );
	$prev  = metadata_exists( 'post', $post->ID, $key ) ? get_post_meta( $post->ID, $key, true ) : null;

	// update_post_meta() expects slashed data and unslashes it itself.
	if ( array_key_exists( 'prev_value', $input ) ) {
		$result = update_post_meta( $post->ID, $key, wp_slash( $value ), wp_slash( $input['prev_value'] ) );
	} else {
		$result = update_post_meta( $post->ID, $key, wp_slash( $value ) );
	}

	// false = failure OR "value unchanged"; tell them apart.
	if ( false === $result ) {
		if ( metadata_exists( 'post', $post->ID, $key ) && get_post_meta( $post->ID, $key, true ) === $value ) {
			return array( 'success' => true, 'changed' => false, 'post_id' => (int) $post->ID, 'key' => $key, 'value' => $value );
		}
		return new WP_Error( 'update_failed', "Could not update meta key '" . esc_html( $key ) . "'." );
	}
	return array(
		'success'        => true,
		'changed'        => true,
		'created'        => null === $prev,
		'post_id'        => (int) $post->ID,
		'key'            => $key,
		'previous_value' => $prev,
		'value'          => $value,
	);
}

function wsp_execute_delete_post_meta( $input ) {
	$resolved = wsp_post_meta_resolve( $input );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	list( $post, $key ) = $resolved;
	if ( ! current_user_can( 'delete_post_meta', $post->ID, $key ) ) {
		return new WP_Error( 'forbidden', "You cannot delete meta key '" . esc_html( $key ) . "' on object " . esc_html( $post->ID ) . '. Protected keys (leading underscore) are not deletable.' );
	}
	if ( ! metadata_exists( 'post', $post->ID, $key ) ) {
		return new WP_Error( 'not_found', "Meta key '" . esc_html( $key ) . "' does not exist on object " . esc_html( $post->ID ) . '.' );
	}

	$previous = get_post_meta( $post->ID, $key, true );
	// With a value, only the rows holding that value are removed; without, every row for the key.
	$ok = array_key_exists( 'value', $input )
		? delete_post_meta( $post->ID, $key, wp_slash( $input['value'] ) )
		: delete_post_meta( $post->ID, $key );
	if ( ! $ok ) {
		return new WP_Error( 'delete_failed', "Nothing was deleted for meta key '" . esc_html( $key ) . "' (no row matched the given value)." );
	}
	return array( 'success' => true, 'post_id' => (int) $post->ID, 'key' => $key, 'previous_value' => $previous );
}
