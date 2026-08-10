<?php
/**
 * DB-backed MCP session store (Milestone M3).
 *
 * Sessions are persisted in a dedicated table rather than transients so they
 * survive object-cache eviction on hosts that run a persistent cache drop-in.
 * Each session is bound to a credential fingerprint and slides its expiry on
 * every valid hit.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Session_Store {

	/** Session lifetime in seconds (24h). */
	const TTL = 86400;

	/** @return string Fully-qualified table name. */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsp_mcp_sessions';
	}

	/** Create the sessions table. Idempotent (dbDelta). */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			session_id varchar(64) NOT NULL,
			fingerprint varchar(64) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (session_id),
			KEY expires_at (expires_at)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Persist a new session.
	 *
	 * @param string $session_id  Session identifier.
	 * @param string $fingerprint SHA-256 of the authenticating credential.
	 */
	public static function create_session( $session_id, $fingerprint = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			self::table(),
			array(
				'session_id'  => $session_id,
				'fingerprint' => $fingerprint,
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Validate a session and slide its expiry forward.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool True if the session exists and has not expired.
	 */
	
	
	// public static function touch_session( $session_id ) {
	// 	global $wpdb;
	// 	// Built inline from $wpdb->prefix (safe source) so static analysis can verify the table name.
	// 	$table = $wpdb->prefix . 'wsp_mcp_sessions';
	// 	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	// 	$updated = $wpdb->query( $wpdb->prepare(
	// 		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix (no user input); values are bound via prepare().
	// 		"UPDATE {$table} SET expires_at = %s WHERE session_id = %s AND expires_at > %s",
	// 		gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
	// 		$session_id,
	// 		current_time( 'mysql', true )
	// 	) );
	// 	return (bool) $updated;
	// }


	public static function touch_session( $session_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wsp_mcp_sessions';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.DB.DirectDatabaseQuery.DirectQuery
    $updated = $wpdb->query( $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix (not user input).
        "UPDATE {$table} SET expires_at = %s WHERE session_id = %s AND expires_at > %s",
        gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
        $session_id,
        current_time( 'mysql', true )
    ) );
    
    // Remote DB latency ke liye chhota sa wait
    if ( $updated === false ) {
        usleep( 100000 ); // 0.1 second delay
    }
    return (bool) $updated;
}

	/**
	 * Return the credential fingerprint bound to a session.
	 *
	 * @param string $session_id Session identifier.
	 * @return string Fingerprint, or '' if none.
	 */
	public static function get_fingerprint( $session_id ) {
		global $wpdb;
		// Built inline from $wpdb->prefix (safe source) so static analysis can verify the table name.
		$table = $wpdb->prefix . 'wsp_mcp_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$fp = $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix (no user input); values are bound via prepare().
			"SELECT fingerprint FROM {$table} WHERE session_id = %s AND expires_at > %s",
			$session_id,
			current_time( 'mysql', true )
		) );
		return is_string( $fp ) ? $fp : '';
	}

	/** Delete a session. */
	public static function delete_session( $session_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'session_id' => $session_id ), array( '%s' ) );
	}

	/** Remove expired sessions (daily cron). */
	public static function cleanup_expired() {
		global $wpdb;
		// Built inline from $wpdb->prefix (safe source) so static analysis can verify the table name.
		$table = $wpdb->prefix . 'wsp_mcp_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix (no user input); values are bound via prepare().
			"DELETE FROM {$table} WHERE expires_at <= %s",
			current_time( 'mysql', true )
		) );
	}
}
