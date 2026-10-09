<?php
/**
 * Redirects & 404 Manager — storage and front-end runtime.
 *
 * Two self-hosted tables (no external service):
 *   {prefix}wsp_mcp_redirects  one row per source path -> destination, with hit counter
 *   {prefix}wsp_mcp_404_log    one row per distinct 404 path (aggregated, never one row per request)
 *
 * Runtime (front end only, `template_redirect`):
 *   - priority 1   : exact-path redirect lookup (one indexed query, skipped entirely when no redirects exist)
 *   - priority 999 : 404 tracking, ONLY while an admin has the "Read 404 Log" ability switched on
 *
 * Privacy: no IP address is stored; query strings are stripped from both the logged path and the
 * referrer (they routinely carry reset keys, emails and tokens); the log is capped and pruned.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Redirects {

	const DB_VERSION     = '1';
	const DB_OPTION      = 'wsp_mcp_redirects_db_version';
	const COUNT_OPTION   = 'wsp_mcp_redirects_count';
	const CRON_HOOK      = 'wsp_mcp_404_cleanup';
	const MAX_REDIRECTS  = 1000;
	const MAX_404_ROWS   = 1000;
	const MAX_PATH_CHARS = 500;
	const LOG_ABILITY    = 'wsp/get-404-logs';

	/** Paths a redirect may never start from — redirecting these can lock admins out or break the MCP endpoint itself. */
	public static function protected_prefixes() {
		return array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php' );
	}

	public static function redirects_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_redirects';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_404_log';
	}

	/* ---------------------------------------------------------------- boot */

	public static function init() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirect' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 999 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_404' ) );
	}

	/** Create tables and schedule cleanup. Idempotent; called on activation and by init()'s version gate. */
	public static function install() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$r       = self::redirects_table();
		$l       = self::log_table();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$r} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(500) NOT NULL DEFAULT '',
			source_hash char(32) NOT NULL DEFAULT '',
			destination varchar(2000) NOT NULL DEFAULT '',
			status_code smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash)
		) {$collate};" );

		dbDelta( "CREATE TABLE {$l} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(500) NOT NULL DEFAULT '',
			path_hash char(32) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			referrer varchar(500) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY last_seen (last_seen),
			KEY hits (hits)
		) {$collate};" );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		self::refresh_count();
	}

	/** Keep the autoloaded counter in sync, so requests with no redirects never touch the table. */
	public static function refresh_count() {
		global $wpdb;
		$t = self::redirects_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		update_option( self::COUNT_OPTION, $n, true );
		return $n;
	}

	/* ------------------------------------------------------ path handling */

	/** GMT MySQL datetime -> ISO 8601 UTC. */
	public static function iso( $mysql_gmt ) {
		if ( empty( $mysql_gmt ) || 0 === strpos( $mysql_gmt, '0000' ) ) {
			return null;
		}
		$ts = strtotime( $mysql_gmt . ' UTC' );
		return $ts ? gmdate( 'c', $ts ) : null;
	}

	/** Canonical site-relative path: leading slash, no duplicate/trailing slashes, home sub-directory removed. */
	public static function normalize_path( $path ) {
		$path = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $path );
		$path = str_replace( '\\', '/', (string) $path );
		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $base ) {
			if ( 0 === strcasecmp( $path, $base ) ) {
				$path = '/';
			} elseif ( 0 === stripos( $path, $base . '/' ) ) {
				$path = substr( $path, strlen( $base ) );
			}
		}
		$path = '/' . ltrim( (string) preg_replace( '#/{2,}#', '/', $path ), '/' );
		return ( strlen( $path ) > 1 ) ? rtrim( $path, '/' ) : '/';
	}

	/** Case-insensitive lookup key for a normalized path. */
	public static function hash( $path ) {
		return md5( function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path ) );
	}

	/** Normalized path of the current request, or null. */
	public static function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and normalized below; sanitize_text_field() would strip %xx octets.
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( ! is_string( $uri ) ) {
			return null;
		}
		$path = wp_parse_url( '/' . ltrim( $uri, '/' ), PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		return self::normalize_path( rawurldecode( $path ) );
	}

	/** Absolute URL to send the visitor to. Relative destinations are rooted at home_url(). */
	public static function resolve_destination( $destination ) {
		return ( 0 === strpos( $destination, '/' ) ) ? home_url( $destination ) : $destination;
	}

	/* ------------------------------------------------------ validation */

	/**
	 * Validate a caller-supplied redirect source.
	 *
	 * @return string|WP_Error Normalized site-relative path.
	 */
	public static function validate_source( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return new WP_Error( 'missing_param', 'source is required.' );
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
			return new WP_Error( 'invalid_source', 'source contains control characters.' );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$p = wp_parse_url( $raw );
			if ( empty( $p['host'] ) || 0 !== strcasecmp( $p['host'], (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return new WP_Error( 'invalid_source', 'source must be a path on this site (e.g. /old-page).' );
			}
			$raw = isset( $p['path'] ) ? $p['path'] : '/';
			if ( ! empty( $p['query'] ) ) {
				return new WP_Error( 'invalid_source', 'Query strings are not supported in source; redirects match on the path only.' );
			}
		} elseif ( 0 !== strpos( $raw, '/' ) ) {
			return new WP_Error( 'invalid_source', 'source must start with "/" (e.g. /old-page).' );
		} elseif ( false !== strpos( $raw, '?' ) ) {
			return new WP_Error( 'invalid_source', 'Query strings are not supported in source; redirects match on the path only.' );
		}
		$raw = (string) strtok( $raw, '#' );
		$path = self::normalize_path( rawurldecode( $raw ) );
		if ( '/' === $path ) {
			return new WP_Error( 'invalid_source', 'The home page cannot be a redirect source.' );
		}
		if ( strlen( $path ) > self::MAX_PATH_CHARS ) {
			return new WP_Error( 'invalid_source', 'source is longer than ' . self::MAX_PATH_CHARS . ' characters.' );
		}
		$lower = strtolower( $path );
		foreach ( self::protected_prefixes() as $prefix ) {
			if ( $lower === $prefix || 0 === strpos( $lower, $prefix . '/' ) ) {
				return new WP_Error( 'protected_source', 'Redirects from ' . esc_html( $prefix ) . ' are not allowed (it would break admin login or the API).' );
			}
		}
		return $path;
	}

	/**
	 * Validate a destination: an internal path ("/new-page?x=1"), or an http(s) URL.
	 * Other hosts are refused unless $allow_external is true — a redirect to a foreign
	 * domain is an open-redirect/phishing primitive, so it must be asked for explicitly.
	 *
	 * @return array|WP_Error { destination: string, external: bool }
	 */
	public static function validate_destination( $raw, $allow_external ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return new WP_Error( 'missing_param', 'destination is required.' );
		}
		if ( preg_match( '/[\x00-\x1F\x7F\\\\\s]/', $raw ) || strlen( $raw ) > 2000 ) {
			return new WP_Error( 'invalid_destination', 'destination contains spaces, backslashes or control characters, or is too long (max 2000).' );
		}
		// Internal path. "//host" and "/\host" are protocol-relative tricks, not paths.
		if ( 0 === strpos( $raw, '/' ) ) {
			if ( 0 === strpos( $raw, '//' ) ) {
				return new WP_Error( 'invalid_destination', 'Protocol-relative destinations (//host) are not allowed. Use a /path or a full http(s) URL.' );
			}
			return array( 'destination' => $raw, 'external' => false );
		}
		$p = wp_parse_url( $raw );
		if ( empty( $p['scheme'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) || empty( $p['host'] ) ) {
			return new WP_Error( 'invalid_destination', 'destination must be a /path on this site or a full http(s) URL.' );
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return new WP_Error( 'invalid_destination', 'destination must not contain credentials.' );
		}
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$external  = ( strtolower( $p['host'] ) !== $home_host );
		if ( $external && ! $allow_external ) {
			return new WP_Error( 'external_not_allowed', 'destination points to another domain (' . esc_html( $p['host'] ) . '). Pass allow_external=true if that is intended.' );
		}
		return array( 'destination' => esc_url_raw( $raw ), 'external' => $external );
	}

	/**
	 * Would a redirect source -> destination loop? Follows internal hops (max 10).
	 *
	 * @param string $source_path Normalized source path.
	 * @param string $destination Validated destination.
	 */
	public static function creates_loop( $source_path, $destination ) {
		global $wpdb;
		$t     = self::redirects_table();
		$start = self::hash( $source_path );
		$dest  = $destination;
		for ( $i = 0; $i < 10; $i++ ) {
			$p = wp_parse_url( self::resolve_destination( $dest ) );
			if ( empty( $p['host'] ) || 0 !== strcasecmp( $p['host'], (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return false; // left the site
			}
			$hash = self::hash( self::normalize_path( rawurldecode( isset( $p['path'] ) ? $p['path'] : '/' ) ) );
			if ( $hash === $start ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$next = $wpdb->get_var( $wpdb->prepare( "SELECT destination FROM {$t} WHERE source_hash = %s", $hash ) );
			if ( ! $next ) {
				return false;
			}
			$dest = $next;
		}
		return true; // chain longer than 10 hops: treat as a loop
	}

	/* ------------------------------------------------------ runtime */

	/** template_redirect @1: send the visitor on if the path has a stored redirect. */
	public static function handle_redirect() {
		if ( ! (int) get_option( self::COUNT_OPTION, 0 ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$path = self::request_path();
		if ( null === $path || '/' === $path ) {
			return;
		}
		global $wpdb;
		$t = self::redirects_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, destination, status_code FROM {$t} WHERE source_hash = %s", self::hash( $path ) ) );
		if ( ! $row ) {
			return;
		}
		$target = self::resolve_destination( $row->destination );

		// Runtime loop guard: never redirect a page to itself.
		$tp = wp_parse_url( $target );
		if ( ! empty( $tp['host'] ) && 0 === strcasecmp( $tp['host'], (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
			&& self::hash( self::normalize_path( rawurldecode( isset( $tp['path'] ) ? $tp['path'] : '/' ) ) ) === self::hash( $path ) && empty( $tp['query'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), $row->id ) );

		$code = ( 302 === (int) $row->status_code ) ? 302 : 301;
		// Destinations were validated on write (internal, or external with explicit consent), so wp_redirect() is intended.
		wp_redirect( $target, $code, 'WSP MCP' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/** template_redirect @999: record a 404 — only while the admin has the 404-log ability enabled. */
	public static function log_404() {
		if ( ! is_404() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		if ( ! function_exists( 'wsp_mcp_is_enabled' ) || ! wsp_mcp_is_enabled( self::LOG_ABILITY ) ) {
			return;
		}
		$path = self::request_path();
		// '/' + 404 means a bad ?query on the home page; the query is not logged, so the entry would be meaningless.
		if ( null === $path || '/' === $path || strlen( $path ) > self::MAX_PATH_CHARS ) {
			return;
		}

		$referrer = '';
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$ref      = (string) strtok( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), '?#' );
			$referrer = substr( $ref, 0, 500 );
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		global $wpdb;
		$t   = self::log_table();
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (path, path_hash, hits, referrer, user_agent, first_seen, last_seen)
			 VALUES (%s, %s, 1, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) <> '', VALUES(referrer), referrer), user_agent = VALUES(user_agent)",
			$path, self::hash( $path ), $referrer, $agent, $now, $now
		) );

		// rows_affected: 1 = new path inserted. Occasionally enforce the row cap so scanner noise cannot grow the table.
		if ( 1 === (int) $wpdb->rows_affected && 1 === wp_rand( 1, 20 ) ) {
			self::enforce_cap();
		}
	}

	/** Drop the oldest-seen rows beyond MAX_404_ROWS (plus 10% headroom). */
	public static function enforce_cap() {
		global $wpdb;
		$t = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		if ( $count > self::MAX_404_ROWS ) {
			$excess = $count - self::MAX_404_ROWS + (int) ( self::MAX_404_ROWS / 10 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} ORDER BY last_seen ASC LIMIT %d", $excess ) );
		}
	}

	/** Daily cron: delete 404 entries not seen for the retention window (default 30 days). */
	public static function cleanup_404() {
		global $wpdb;
		$days = (int) apply_filters( 'wsp_mcp_404_log_retention_days', 30 );
		if ( $days < 1 ) {
			return;
		}
		$t = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		self::enforce_cap();
	}
}
