<?php
/**
 * Full Audit Log for MCP tool executions.
 *
 * Every `tools/call` handled by WSP_MCP_Server is recorded here: tool name,
 * timestamp, acting user (or "unauthenticated"), request IP, and the
 * outcome (success / denied / error). Entries are stored in a dedicated,
 * self-hosted database table — no external service, API, or paid
 * dependency is involved. Old entries are pruned automatically so the
 * table cannot grow without bound.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Audit_Log {

	/** Default retention window in days. Filterable via wsp_mcp_audit_log_retention_days. */
	const DEFAULT_RETENTION_DAYS = 90;

	/** Recognized status values, for display and filtering. */
	const STATUS_SUCCESS = 'success';
	const STATUS_DENIED  = 'denied';
	const STATUS_ERROR   = 'error';

	/** @return string Fully-qualified table name. */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_audit_log';
	}

	/** Create the audit log table. Idempotent (dbDelta). */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tool_name varchar(191) NOT NULL DEFAULT '',
			category varchar(60) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY tool_name (tool_name),
			KEY category (category),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record one MCP tool execution. Never throws — a logging failure must
	 * not break the tool call itself.
	 *
	 * @param string $tool_name   Requested tool name (untrusted; sanitized/truncated here).
	 * @param string $status      One of STATUS_SUCCESS, STATUS_DENIED, STATUS_ERROR.
	 * @param string $message     Optional short human-readable detail (e.g. error text).
	 * @param int    $duration_ms Optional wall-clock execution time in milliseconds.
	 * @param string $category    Optional ability group (e.g. "Elementor", "WooCommerce", "Posts")
	 *                            used to power the Analytics dashboard's usage breakdown.
	 */
	public static function log( $tool_name, $status, $message = '', $duration_ms = 0, $category = '' ) {
		global $wpdb;

		$user_id    = get_current_user_id();
		$user_login = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user_login = $user->user_login;
			}
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				self::table(),
				array(
					'tool_name'   => mb_substr( sanitize_text_field( (string) $tool_name ), 0, 191 ),
					'category'    => mb_substr( sanitize_text_field( (string) $category ), 0, 60 ),
					'status'      => sanitize_key( $status ),
					'duration_ms' => max( 0, (int) $duration_ms ),
					'user_id'     => $user_id,
					'user_login'  => mb_substr( $user_login, 0, 60 ),
					'ip_address'  => self::get_client_ip(),
					'message'     => mb_substr( wp_strip_all_tags( (string) $message ), 0, 500 ),
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			// Swallow — auditing must never take down the MCP request itself.
			return;
		}
	}

	/**
	 * Record a request rejected before tool dispatch (auth, origin, rate limit,
	 * session checks). Shown in the log as tool "mcp:<reason>" with status denied,
	 * so admins can see why clients are being blocked.
	 *
	 * @param string $reason  Short machine code, e.g. session_mismatch.
	 * @param string $detail  Human detail (route, session id prefix, …).
	 */
	public static function log_rejection( $reason, $detail = '' ) {
		self::log( 'mcp:' . sanitize_key( $reason ), self::STATUS_DENIED, $detail, 0, 'Protocol' );
	}

	/** Rejections (tool_name "mcp:*") grouped by reason within the last $hours hours. */
	public static function get_rejection_counts( $hours = 24 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; values bound.
			"SELECT tool_name, COUNT(*) AS n FROM {$table} WHERE tool_name LIKE %s AND created_at >= %s GROUP BY tool_name ORDER BY n DESC",
			'mcp:%',
			gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) )
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ substr( $row['tool_name'], 4 ) ] = (int) $row['n'];
		}
		return $out;
	}

	/** Most recent rejection rows. */
	public static function get_recent_rejections( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; values bound.
			"SELECT tool_name, message, ip_address, user_login, created_at FROM {$table} WHERE tool_name LIKE %s ORDER BY id DESC LIMIT %d",
			'mcp:%',
			max( 1, min( 100, (int) $limit ) )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Best-effort client IP for the audit trail. Only the direct connection
	 * (REMOTE_ADDR) is trusted — proxy headers (X-Forwarded-For, etc.) are
	 * attacker-controlled and are not used, so this cannot be spoofed to
	 * frame another address.
	 *
	 * @return string A validated IPv4/IPv6 address, or '' if unavailable.
	 */
	private static function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Fetch a page of log entries, most recent first.
	 *
	 * @param array $args {
	 *     @type int    $per_page Rows per page. Default 20.
	 *     @type int    $paged    1-indexed page number. Default 1.
	 *     @type string $status   Filter by status ('' = all).
	 *     @type string $tool     Filter by exact tool name ('' = all).
	 * }
	 * @return array<int,object> Rows.
	 */
	public static function get_entries( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 20;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$status   = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$tool     = isset( $args['tool'] ) ? sanitize_text_field( $args['tool'] ) : '';

		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( '' !== $tool ) {
			$where[]  = 'tool_name = %s';
			$values[] = $tool;
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * $per_page;
		$values[]  = $per_page;
		$values[]  = $offset;

		// Table name is built from $wpdb->prefix (no user input); all values are bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
			$values
		) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count log entries matching the same filters as get_entries().
	 *
	 * @param array $args See get_entries().
	 * @return int
	 */
	public static function count_entries( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$tool   = isset( $args['tool'] ) ? sanitize_text_field( $args['tool'] ) : '';

		$where  = array( '1=1' );
		$values = array();
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		if ( '' !== $tool ) {
			$where[]  = 'tool_name = %s';
			$values[] = $tool;
		}
		$where_sql = implode( ' AND ', $where );

		if ( empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
			$values
		) );
	}

	/** Distinct tool names seen in the log, for the filter dropdown. */
	public static function get_distinct_tools() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( "SELECT DISTINCT tool_name FROM {$table} ORDER BY tool_name ASC" );
		return is_array( $names ) ? $names : array();
	}

	/** Simple counts for the summary bar: total, success, denied, error. */
	public static function get_summary() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$summary = array(
			'total'                => 0,
			self::STATUS_SUCCESS   => 0,
			self::STATUS_DENIED    => 0,
			self::STATUS_ERROR     => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n = (int) $row['n'];
			$summary['total'] += $n;
			if ( isset( $summary[ $row['status'] ] ) ) {
				$summary[ $row['status'] ] = $n;
			}
		}
		return $summary;
	}

	/** Delete every log entry. Used by the admin "Clear log" action. */
	public static function clear_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * High-level metrics for the Analytics dashboard summary cards.
	 *
	 * @param int $days Look-back window in days ('0' = all time).
	 * @return array{total_requests:int,most_used_tool:string,most_used_count:int,avg_duration_ms:float,error_rate:float,error_count:int}
	 */
	public static function get_analytics_overview( $days = 30 ) {
		global $wpdb;
		$table  = self::table();
		$days   = (int) $days;
		$where  = '1=1';
		$values = array();
		if ( $days > 0 ) {
			$where    = 'created_at >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		// Table name is built from $wpdb->prefix (no user input); all values are bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = empty( $values )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $values ) );

		$error_values = array_merge( $values, array( self::STATUS_ERROR ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$errors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where} AND status = %s", $error_values ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$avg_duration = empty( $values )
			? $wpdb->get_var( "SELECT AVG(duration_ms) FROM {$table} WHERE {$where} AND duration_ms > 0" )
			: $wpdb->get_var( $wpdb->prepare( "SELECT AVG(duration_ms) FROM {$table} WHERE {$where} AND duration_ms > 0", $values ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$most_used = empty( $values )
			? $wpdb->get_row( "SELECT tool_name, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY tool_name ORDER BY n DESC LIMIT 1" )
			: $wpdb->get_row( $wpdb->prepare( "SELECT tool_name, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY tool_name ORDER BY n DESC LIMIT 1", $values ) );

		return array(
			'total_requests'  => $total,
			'most_used_tool'  => $most_used ? (string) $most_used->tool_name : '',
			'most_used_count' => $most_used ? (int) $most_used->n : 0,
			'avg_duration_ms' => $avg_duration ? round( (float) $avg_duration, 1 ) : 0.0,
			'error_rate'      => $total > 0 ? round( ( $errors / $total ) * 100, 1 ) : 0.0,
			'error_count'     => $errors,
		);
	}

	/**
	 * Usage counts grouped by ability category (tool group), for the Analytics
	 * "tool usage" progress bars / table.
	 *
	 * @param int $days  Look-back window in days ('0' = all time).
	 * @param int $limit Max number of categories to return, largest first.
	 * @return array<int,array{category:string,count:int,percent:float,avg_ms:float}>
	 */
	public static function get_category_breakdown( $days = 30, $limit = 12 ) {
		global $wpdb;
		$table = self::table();
		$days  = (int) $days;
		$limit = max( 1, min( 50, (int) $limit ) );

		// The query is written out in full at each call site rather than built
		// into a variable first: $wpdb->prepare() can only be verified — by a
		// reviewer or by PHPCS — when the query it receives is a literal, so
		// passing a pre-assembled $sql string trips
		// WordPress.DB.PreparedSQL.NotPrepared even when every value is bound.
		// The windowed and all-time forms are therefore separate statements,
		// the same way get_analytics_overview() above handles the same split.
		// Table name is built from $wpdb->prefix (no user input); every value
		// is bound via prepare().
		if ( $days > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; values bound below.
				"SELECT IF(category = '', 'Uncategorized', category) AS category, COUNT(*) AS n, AVG(duration_ms) AS avg_ms
				 FROM {$table} WHERE created_at >= %s GROUP BY category ORDER BY n DESC LIMIT %d",
				$since,
				$limit
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; value bound below.
				"SELECT IF(category = '', 'Uncategorized', category) AS category, COUNT(*) AS n, AVG(duration_ms) AS avg_ms
				 FROM {$table} GROUP BY category ORDER BY n DESC LIMIT %d",
				$limit
			), ARRAY_A );
		}
		$rows = is_array( $rows ) ? $rows : array();

		$total = 0;
		foreach ( $rows as $row ) {
			$total += (int) $row['n'];
		}
		foreach ( $rows as &$row ) {
			$row['n']       = (int) $row['n'];
			$row['avg_ms']  = round( (float) $row['avg_ms'], 1 );
			$row['percent'] = $total > 0 ? round( ( $row['n'] / $total ) * 100, 1 ) : 0.0;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Most recent requests with timing/status, for the performance log table.
	 *
	 * @param int $limit Max rows to return.
	 * @return array<int,object>
	 */
	public static function get_recent_performance( $limit = 25 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 100, (int) $limit ) );

		// Table name is built from $wpdb->prefix (no user input); value is bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tool_name, category, status, duration_ms, user_login, user_id, created_at
			 FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		) );

		return is_array( $rows ) ? $rows : array();
	}

	/** Prune entries older than the retention window (daily cron). */
	public static function cleanup_old_entries() {
		global $wpdb;
		$table = self::table();
		$days  = (int) apply_filters( 'wsp_mcp_audit_log_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $days <= 0 ) {
			return; // Retention disabled.
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// Table name is built from $wpdb->prefix (no user input); value is bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
