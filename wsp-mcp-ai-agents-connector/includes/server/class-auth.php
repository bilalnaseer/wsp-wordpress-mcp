<?php
/**
 * MCP authentication (Milestone M4; OAuth added later for "paste a URL only"
 * Claude Connectors).
 *
 * Accepts four credential types at the MCP endpoint:
 *   1. Application Password (HTTP Basic) — validated by WordPress core before
 *      our handler runs, so the current user is already set when we see it.
 *   2. Plugin API key via the X-WSP-MCP-API-Key header.
 *   3. The same API key presented as `Authorization: Bearer <key>` (the
 *      convention most MCP clients use for static credentials).
 *   4. An OAuth access token, also presented as `Authorization: Bearer
 *      <token>`, issued by this plugin's own native authorization server
 *      (see class-oauth-server.php + class-oauth-store.php). Tried whenever a
 *      bearer value doesn't match the static API key, since both share the
 *      same header. Unlike the static key (always mapped to an admin), an
 *      OAuth token is bound to whichever WordPress user actually clicked
 *      "Allow" on the consent screen, so capability checks reflect that
 *      specific user.
 *
 * The API key is stored in admin-only settings, so a request bearing it is
 * treated as administrator-trusted and mapped to a site admin user so that
 * capability checks on tools resolve correctly.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Auth {

	const OPTION_KEY = 'wsp_mcp_api_key';

	/** @return string The plugin API key, generating one on first access. */
	public static function get_api_key() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( '' === $key ) {
			$key = self::regenerate_api_key();
		}
		return $key;
	}

	/** Generate, store and return a fresh API key. */
	public static function regenerate_api_key() {
		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::OPTION_KEY, $key, false );
		return $key;
	}

	/**
	 * Authenticate an MCP request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_REST_Response True on success, JSON-RPC error response otherwise.
	 */
	public static function authenticate( $request ) {
		// 1. Application Password — WordPress core has already validated the
		// Basic header and set the current user by the time we run.
		if ( is_user_logged_in() ) {
			return true;
		}

		$settings_key = self::get_api_key();

		// 2. Authorization: Bearer <api-key-or-oauth-token>.
		$auth = $request->get_header( 'authorization' );
		if ( is_string( $auth ) && 0 === stripos( $auth, 'bearer ' ) ) {
			$token = trim( substr( $auth, 7 ) );
			if ( hash_equals( $settings_key, $token ) ) {
				self::assume_admin();
				return true;
			}
			// Not the static key — try it as a token from this plugin's own
			// OAuth authorization server (class-oauth-server.php). Gated on
			// the same admin opt-in that serves the OAuth endpoints, so
			// switching the feature off also stops honouring any token it
			// previously issued rather than leaving live credentials behind.
			if ( class_exists( 'WSP_MCP_OAuth_Store' ) && function_exists( 'wsp_mcp_oauth_is_enabled' ) && wsp_mcp_oauth_is_enabled() ) {
				$oauth = WSP_MCP_OAuth_Store::validate_access_token( $token );
				if ( is_array( $oauth ) && $oauth['user_id'] > 0 ) {
					wp_set_current_user( $oauth['user_id'] );
					return true;
				}
			}
		}

		// 3. X-WSP-MCP-API-Key header.
		$api_key = $request->get_header( 'x-wsp-mcp-api-key' );
		if ( is_string( $api_key ) && '' !== $api_key && hash_equals( $settings_key, $api_key ) ) {
			self::assume_admin();
			return true;
		}

		WSP_MCP_Audit_Log::log_rejection( 'auth_failed', 'Missing or invalid credentials (401).' );
		return self::challenge();
	}

	/**
	 * Fingerprint used to bind a session to whoever created it.
	 *
	 * Bound to the authenticated WordPress user, NOT the raw credential: OAuth
	 * clients (Claude) refresh their access token mid-session, which changes the
	 * Authorization header while the session is still legitimate. Call after
	 * authenticate() so the current user is resolved.
	 *
	 * @param WP_REST_Request $request Incoming request (unused; kept for back-compat).
	 * @return string Fingerprint hash.
	 */
	public static function fingerprint( $request = null ) {
		return hash( 'sha256', 'user:' . get_current_user_id() );
	}

	/**
	 * Capability gate for a tool.
	 *
	 * @param string $cap Capability to require (empty = authenticated only).
	 * @return true|WP_Error
	 */
	public static function require_cap( $cap ) {
		if ( '' === $cap || null === $cap ) {
			return true;
		}
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', "You do not have permission ({$cap}) to call this tool." );
		}
		return true;
	}

	/** Map an API-key request to the lowest-ID administrator. */
	private static function assume_admin() {
		if ( is_user_logged_in() ) {
			return;
		}
		$admins = get_users( array(
			'role'    => 'administrator',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'fields'  => 'ID',
		) );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
	}

	/**
	 * Build a 401 challenge response.
	 *
	 * The WWW-Authenticate header's `resource_metadata` parameter is what lets
	 * Claude (and any other spec-compliant MCP client) discover this plugin's
	 * native OAuth authorization server with nothing more than the endpoint
	 * URL — see class-oauth-server.php and
	 * https://claude.com/docs/connectors/building/authentication. Claude only
	 * honors this pointer on a 401, never on a 200.
	 */
	private static function challenge() {
		$response = new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32001,
				'message' => 'Authentication required. Use an Application Password (Basic), Authorization: Bearer <api-key-or-oauth-token>, or the X-WSP-MCP-API-Key header.',
			),
		), 401 );
		// Only advertise OAuth discovery when the authorization server is
		// actually switched on — pointing a client at metadata this install
		// will not serve produces a connector that authenticates against
		// nothing and then fails every call.
		$resource_metadata = ( class_exists( 'WSP_MCP_OAuth_Server' ) && function_exists( 'wsp_mcp_oauth_is_enabled' ) && wsp_mcp_oauth_is_enabled() )
			? WSP_MCP_OAuth_Server::protected_resource_metadata_url()
			: '';
		$www_authenticate = $resource_metadata
			? 'Bearer resource_metadata="' . $resource_metadata . '"'
			: 'Bearer';
		$response->header( 'WWW-Authenticate', $www_authenticate );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		return $response;
	}
}
