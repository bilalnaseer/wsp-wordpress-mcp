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

/**
 * Normalize one redirect row for output.
 *
 * @param object $row Row from the redirects table.
 * @return array
 */
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

/**
 * Lists URL redirects, newest first.
 *
 * Returns { redirects: [{ id, source, destination, status_code, external, hits, last_hit, note,
 * created_by, created_at }], total, returned, pages, limit }. "source" is a site-relative path;
 * "destination" is a /path or full URL.
 *
 * MCP tool: `wsp_list_redirects`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $search Optional. Matches source, destination or note.
 *     @type int $per_page Optional. Default 20.
 *     @type int $page Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_list_redirects( $input ) {
	global $wpdb;
	$t        = WSP_MCP_Redirects::redirects_table();
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 20;
	$page     = isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1;

	$where = '1=1';
	$args  = array();
	if ( ! empty( $input['search'] ) ) {
		$like   = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $input['search'] ) ) ) . '%';
		$where .= ' AND (source LIKE %s OR destination LIKE %s OR note LIKE %s)';
		$args   = array( $like, $like, $like );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$total = (int) ( $args
		? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) )
		: $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
		array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
	) );
	// phpcs:enable

	return array(
		'redirects' => array_map( 'wsp_redirects_format_row', (array) $rows ),
		'total'     => $total,
		'returned'  => count( (array) $rows ),
		'pages'     => (int) ceil( $total / $per_page ),
		'limit'     => WSP_MCP_Redirects::MAX_REDIRECTS,
	);
}

/**
 * Creates a redirect that takes effect immediately for every visitor.
 *
 * "source": the old path on this site, e.g. "/old-page" (matched on the path only, case-insensitively,
 * ignoring trailing slashes; no query strings; not "/", /wp-admin, /wp-login.php, /wp-json).
 * "destination": a "/new-page" path or a full http(s) URL — a URL on a DIFFERENT domain is refused
 * unless allow_external=true. status_code 301 (permanent, default; browsers and search engines cache
 * it) or 302 (temporary). Refuses loops and duplicate sources. A redirect overrides any real page at
 * the source path (a warning is returned if one exists). Returns { success, redirect, warning?,
 * notice? }. Tip: use wsp_get_404_logs to find broken URLs worth redirecting.
 *
 * MCP tool: `wsp_create_redirect`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $source Required. Old path, e.g. /old-page.
 *     @type string $destination Required. New /path or full URL.
 *     @type int $status_code Optional. Default 301. One of: 301, 302.
 *     @type bool $allow_external Optional. Permit a destination on another domain. Default false.
 *     @type string $note Optional. Optional reminder of why (max 255).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE source_hash = %s", $hash ) );
	if ( $existing ) {
		return new WP_Error( 'redirect_exists', 'A redirect from ' . esc_html( $source ) . ' already exists (id ' . (int) $existing . '). Delete it first to replace it.' );
	}
	if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) >= WSP_MCP_Redirects::MAX_REDIRECTS ) {
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
		'redirect' => wsp_redirects_format_row( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

/**
 * Deletes a redirect by id (from wsp_list_redirects).
 *
 * Visitors who cached a 301 in their browser may keep being redirected for a while. Returns { success,
 * deleted }.
 *
 * MCP tool: `wsp_delete_redirect`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_delete_redirect( $input ) {
	global $wpdb;
	$t  = WSP_MCP_Redirects::redirects_table();
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( $id < 1 ) {
		return new WP_Error( 'missing_param', 'id is required (from wsp_list_redirects).' );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	if ( ! $row ) {
		return new WP_Error( 'not_found', 'Redirect ' . esc_html( $id ) . ' was not found.' );
	}
	$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
	// phpcs:enable
	WSP_MCP_Redirects::refresh_count();
	return array( 'success' => true, 'deleted' => wsp_redirects_format_row( $row ) );
}

/**
 * Lists URLs that visitors requested but were not found (404), aggregated per path.
 *
 * Recording is active only while this tool is enabled, so an empty result right after enabling is
 * normal. Returns { entries: [{ id, path, hits, first_seen, last_seen, referrer, user_agent,
 * redirect_id (set when a redirect already covers the path) }], returned, total_paths, total_hits,
 * tracking_since }. Paths only — query strings are stripped and no IP addresses are stored. Entries
 * expire after 30 days.
 *
 * MCP tool: `wsp_get_404_logs`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $limit Optional. Default 50.
 *     @type string $orderby Optional. recent (default) or hits. One of: recent, hits.
 *     @type string $search Optional. Substring of the path.
 *     @type int $min_hits Optional.
 *     @type int $days Optional. Only paths seen in the last N days.
 *     @type bool $unresolved_only Optional. Hide paths that already have a redirect.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_404_logs( $input ) {
	global $wpdb;
	$l = WSP_MCP_Redirects::log_table();
	$r = WSP_MCP_Redirects::redirects_table();

	$limit   = isset( $input['limit'] ) ? max( 1, min( 200, intval( $input['limit'] ) ) ) : 50;
	$orderby = ( isset( $input['orderby'] ) && 'hits' === $input['orderby'] ) ? 'l.hits DESC, l.last_seen DESC' : 'l.last_seen DESC';

	$where = '1=1';
	$args  = array();
	if ( ! empty( $input['search'] ) ) {
		$where .= ' AND l.path LIKE %s';
		$args[] = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $input['search'] ) ) ) . '%';
	}
	if ( ! empty( $input['min_hits'] ) ) {
		$where .= ' AND l.hits >= %d';
		$args[] = max( 1, intval( $input['min_hits'] ) );
	}
	if ( ! empty( $input['days'] ) ) {
		$where .= ' AND l.last_seen >= %s';
		$args[] = gmdate( 'Y-m-d H:i:s', time() - max( 1, intval( $input['days'] ) ) * DAY_IN_SECONDS );
	}
	if ( ! empty( $input['unresolved_only'] ) ) {
		$where .= ' AND r.id IS NULL';
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	$sql  = "SELECT l.*, r.id AS redirect_id FROM {$l} l LEFT JOIN {$r} r ON r.source_hash = l.path_hash WHERE {$where} ORDER BY {$orderby} LIMIT %d";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit ) ) ) );
	$sum  = $wpdb->get_row( "SELECT COUNT(*) AS paths, COALESCE(SUM(hits), 0) AS hits, MIN(first_seen) AS since FROM {$l}" );
	// phpcs:enable

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

/**
 * Deletes recorded 404 entries: one by id, or everything with all=true.
 *
 * Returns { success, deleted }.
 *
 * MCP tool: `wsp_clear_404_logs`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Optional. Entry id from wsp_get_404_logs.
 *     @type bool $all Optional. Clear the whole log.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_clear_404_logs( $input ) {
	global $wpdb;
	$l  = WSP_MCP_Redirects::log_table();
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $id > 0 ) {
		$n = (int) $wpdb->delete( $l, array( 'id' => $id ), array( '%d' ) );
		if ( ! $n ) {
			return new WP_Error( 'not_found', '404 entry ' . esc_html( $id ) . ' was not found.' );
		}
		return array( 'success' => true, 'deleted' => 1 );
	}
	if ( ! empty( $input['all'] ) ) {
		$n = (int) $wpdb->query( "DELETE FROM {$l}" );
		return array( 'success' => true, 'deleted' => $n );
	}
	// phpcs:enable
	return new WP_Error( 'missing_param', 'Provide either "id" (one entry) or all=true (clear the whole 404 log).' );
}
