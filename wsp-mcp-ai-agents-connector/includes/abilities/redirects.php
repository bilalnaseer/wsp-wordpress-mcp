<?php
/**
 * Redirects & 404 Manager — MCP tool callbacks.
 *
 * Storage and the front-end runtime live in includes/seo/class-redirects.php. All tools
 * require `manage_options`: redirects change what every visitor sees and 404 entries
 * include referrer URLs.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Normalize one redirect row for output. */
function wsp_redirects_format_row( $row ) {
	$user = ! empty( $row->created_by ) ? get_userdata( (int) $row->created_by ) : false;
	$host = (string) wp_parse_url( $row->destination, PHP_URL_HOST );
	return array(
		'id'          => (int) $row->id,
		'source'      => $row->source,
		'destination' => $row->destination,
		'status_code' => (int) $row->status_code,
		'external'    => '' !== $host && 0 !== strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
		'hits'        => (int) $row->hits,
		'last_hit'    => WSP_MCP_Redirects::iso( $row->last_hit ),
		'note'        => $row->note,
		'created_by'  => $user ? $user->user_login : null,
		'created_at'  => WSP_MCP_Redirects::iso( $row->created_at ),
	);
}

function wsp_execute_list_redirects( $input ) {
	global $wpdb;
	$t        = WSP_MCP_Redirects::redirects_table();
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1;

	$search = '';
	$like   = '';
	if ( ! empty( $input['search'] ) ) {
		$search = sanitize_text_field( wp_unslash( $input['search'] ) );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$offset = ( $page - 1 ) * $per_page;

	// Constant SQL: the search filter is optional ("'' = skip"), so no WHERE fragment is concatenated.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR source LIKE %s OR destination LIKE %s OR note LIKE %s )",
			$t,
			$search,
			$like,
			$like,
			$like
		)
	);
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM %i WHERE ( %s = '' OR source LIKE %s OR destination LIKE %s OR note LIKE %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
			$t,
			$search,
			$like,
			$like,
			$like,
			$per_page,
			$offset
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return array(
		'redirects' => array_map( 'wsp_redirects_format_row', (array) $rows ),
		'total'     => $total,
		'returned'  => count( (array) $rows ),
		'pages'     => (int) ceil( $total / $per_page ),
		'limit'     => WSP_MCP_Redirects::MAX_REDIRECTS,
	);
}

function wsp_execute_create_redirect( $input ) {
	global $wpdb;
	$t = WSP_MCP_Redirects::redirects_table();

	$source = WSP_MCP_Redirects::validate_source( isset( $input['source'] ) ? $input['source'] : '' );
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	$dest = WSP_MCP_Redirects::validate_destination( isset( $input['destination'] ) ? $input['destination'] : '', ! empty( $input['allow_external'] ) );
	if ( is_wp_error( $dest ) ) {
		return $dest;
	}
	$code = isset( $input['status_code'] ) ? intval( $input['status_code'] ) : 301;
	if ( ! in_array( $code, array( 301, 302 ), true ) ) {
		return new WP_Error( 'invalid_param', 'status_code must be 301 (permanent) or 302 (temporary).' );
	}
	if ( WSP_MCP_Redirects::creates_loop( $source, $dest['destination'] ) ) {
		return new WP_Error( 'redirect_loop', 'This redirect would loop back to ' . esc_html( $source ) . ' (directly or through other redirects).' );
	}

	$hash = WSP_MCP_Redirects::hash( $source );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_hash = %s', $t, $hash ) );
	if ( $existing ) {
		return new WP_Error( 'redirect_exists', 'A redirect from ' . esc_html( $source ) . ' already exists (id ' . (int) $existing . '). Delete it first to replace it.' );
	}
	if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ) >= WSP_MCP_Redirects::MAX_REDIRECTS ) {
		return new WP_Error( 'limit_reached', 'The redirect limit (' . WSP_MCP_Redirects::MAX_REDIRECTS . ') is reached. Delete unused redirects first.' );
	}
	$ok = $wpdb->insert(
		$t,
		array(
			'source'      => $source,
			'source_hash' => $hash,
			'destination' => $dest['destination'],
			'status_code' => $code,
			'note'        => isset( $input['note'] ) ? substr( sanitize_text_field( wp_unslash( $input['note'] ) ), 0, 255 ) : '',
			'created_by'  => get_current_user_id(),
			'created_at'  => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
	);
	// phpcs:enable
	if ( ! $ok ) {
		return new WP_Error( 'insert_failed', 'Could not save the redirect.' );
	}
	$id = (int) $wpdb->insert_id;
	WSP_MCP_Redirects::refresh_count();

	$result = array(
		'success'  => true,
		'redirect' => wsp_redirects_format_row( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	);
	// A redirect takes priority over real content — say so when the source currently resolves to a page.
	if ( url_to_postid( home_url( $source ) ) ) {
		$result['warning'] = 'The source path currently serves an existing post/page. The redirect overrides it for all visitors.';
	}
	if ( $dest['external'] ) {
		$result['notice'] = 'Destination is on another domain.';
	}
	return $result;
}

function wsp_execute_delete_redirect( $input ) {
	global $wpdb;
	$t  = WSP_MCP_Redirects::redirects_table();
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( $id < 1 ) {
		return new WP_Error( 'missing_param', 'id is required (from wsp_list_redirects).' );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $t, $id ) );
	if ( ! $row ) {
		return new WP_Error( 'not_found', 'Redirect ' . esc_html( $id ) . ' was not found.' );
	}
	$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
	// phpcs:enable
	WSP_MCP_Redirects::refresh_count();
	return array( 'success' => true, 'deleted' => wsp_redirects_format_row( $row ) );
}

function wsp_execute_get_404_logs( $input ) {
	global $wpdb;
	$l = WSP_MCP_Redirects::log_table();
	$r = WSP_MCP_Redirects::redirects_table();

	$limit    = isset( $input['limit'] ) ? max( 1, min( 200, intval( $input['limit'] ) ) ) : 50;
	$by_hits  = isset( $input['orderby'] ) && 'hits' === $input['orderby'];
	$search   = '';
	$like     = '';
	$min_hits = 0;
	$since    = '1970-01-01 00:00:00';
	if ( ! empty( $input['search'] ) ) {
		$search = sanitize_text_field( wp_unslash( $input['search'] ) );
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
	}
	if ( ! empty( $input['min_hits'] ) ) {
		$min_hits = max( 1, intval( $input['min_hits'] ) );
	}
	if ( ! empty( $input['days'] ) ) {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, intval( $input['days'] ) ) * DAY_IN_SECONDS );
	}
	$unresolved = empty( $input['unresolved_only'] ) ? 0 : 1;

	// Constant SQL: every filter is optional ("'' / 0 = skip"), so no WHERE fragment is concatenated.
	// The two ORDER BY forms are separate literal statements so the query is never assembled from variables.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $by_hits ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.*, r.id AS redirect_id FROM %i l LEFT JOIN %i r ON r.source_hash = l.path_hash
				 WHERE ( %s = \'\' OR l.path LIKE %s ) AND l.hits >= %d AND l.last_seen >= %s AND ( %d = 0 OR r.id IS NULL )
				 ORDER BY l.hits DESC, l.last_seen DESC LIMIT %d',
				$l,
				$r,
				$search,
				$like,
				$min_hits,
				$since,
				$unresolved,
				$limit
			)
		);
	} else {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.*, r.id AS redirect_id FROM %i l LEFT JOIN %i r ON r.source_hash = l.path_hash
				 WHERE ( %s = \'\' OR l.path LIKE %s ) AND l.hits >= %d AND l.last_seen >= %s AND ( %d = 0 OR r.id IS NULL )
				 ORDER BY l.last_seen DESC LIMIT %d',
				$l,
				$r,
				$search,
				$like,
				$min_hits,
				$since,
				$unresolved,
				$limit
			)
		);
	}
	$sum = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS paths, COALESCE(SUM(hits), 0) AS hits, MIN(first_seen) AS since FROM %i', $l ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[] = array(
			'id'          => (int) $row->id,
			'path'        => $row->path,
			'hits'        => (int) $row->hits,
			'first_seen'  => WSP_MCP_Redirects::iso( $row->first_seen ),
			'last_seen'   => WSP_MCP_Redirects::iso( $row->last_seen ),
			'referrer'    => $row->referrer,
			'user_agent'  => $row->user_agent,
			'redirect_id' => $row->redirect_id ? (int) $row->redirect_id : null,
		);
	}
	return array(
		'entries'     => $out,
		'returned'    => count( $out ),
		'total_paths' => $sum ? (int) $sum->paths : 0,
		'total_hits'  => $sum ? (int) $sum->hits : 0,
		'tracking_since' => $sum ? WSP_MCP_Redirects::iso( $sum->since ) : null,
		'note'        => 'Paths only (query strings stripped, no IP addresses). Entries are kept 30 days and capped at ' . WSP_MCP_Redirects::MAX_404_ROWS . '. Logging runs only while this ability is enabled.',
	);
}

function wsp_execute_clear_404_logs( $input ) {
	global $wpdb;
	$l  = WSP_MCP_Redirects::log_table();
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	if ( $id > 0 ) {
		$n = (int) $wpdb->delete( $l, array( 'id' => $id ), array( '%d' ) );
		if ( ! $n ) {
			return new WP_Error( 'not_found', '404 entry ' . esc_html( $id ) . ' was not found.' );
		}
		return array( 'success' => true, 'deleted' => 1 );
	}
	if ( ! empty( $input['all'] ) ) {
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $l ) );
		return array( 'success' => true, 'deleted' => $n );
	}
	// phpcs:enable
	return new WP_Error( 'missing_param', 'Provide either "id" (one entry) or all=true (clear the whole 404 log).' );
}
