<?php
/**
 * Native MCP server (Milestone M1 + M2).
 *
 * Implements the Model Context Protocol over a single Streamable-HTTP REST
 * endpoint, with no dependency on the WordPress MCP Adapter. Speaks JSON-RPC
 * 2.0: initialize, tools/list, tools/call, ping. Tools are contributed by the
 * native tool registry, which wraps the plugin's existing wsp_execute_*
 * callbacks.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSP_MCP_Server {

	/** @var array<string,array> name => tool spec. */
	private static $tools = array();

	/** MCP protocol versions this server recognizes. */
	const SUPPORTED_PROTOCOLS = array( '2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25' );
	const DEFAULT_PROTOCOL     = '2025-06-18';

	/** Rate limit: requests per window per authenticated user (Claude egresses from a rotating IP pool, so IP is useless). */
	const RATE_MAX    = 120;
	const RATE_WINDOW = 60;

	/** Failed-auth attempts per window per IP (brute-force guard only). */
	const RATE_FAIL_MAX = 30;

	/** Boot: register tools and the REST route. */
	public static function init() {
		wsp_mcp_register_native_tools();
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Discards any stray output another active plugin/theme printed during
		// this request (see includes/response-guard.php) the instant before WP
		// core echoes the real JSON-RPC body — a no-op on every REST response
		// that isn't ours, so this is safe to leave unconditional site-wide.
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'flush_output_guard' ) );
	}

	/** @see wsp_mcp_output_guard_flush() */
	public static function flush_output_guard( $result ) {
		wsp_mcp_output_guard_flush();
		return $result;
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name MCP tool name (a-z0-9_).
	 * @param array  $spec {
	 *     @type string   $description Human/agent-facing description.
	 *     @type array    $inputSchema JSON Schema for arguments.
	 *     @type callable $callback    fn(array $args): array|WP_Error.
	 *     @type string   $capability  Required capability ('' = authenticated only).
	 *     @type string   $enable_key  Registry key for the admin on/off toggle.
	 *     @type callable $active_callback Optional fn(): bool — when set, the tool is
	 *                                 advertised/callable only while it returns true
	 *                                 (used by tools driven by a non-registry switch,
	 *                                 e.g. the Site Context feature).
	 * }
	 */
	public static function register_tool( $name, array $spec ) {
		self::$tools[ $name ] = wp_parse_args( $spec, array(
			'description' => '',
			'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass() ),
			'callback'    => null,
			'capability'  => '',
			'enable_key'  => '',
			'active_callback' => null,
		) );
	}

	/** Register the MCP REST endpoint. Auth is enforced inside the handler. */
	public static function register_routes() {
		register_rest_route( 'wsp-mcp/v1', '/mcp', array(
			'methods'             => array( 'GET', 'POST', 'DELETE', 'OPTIONS' ),
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Auth handled in WSP_MCP_Auth.
		) );
	}

	/** Tools enabled by the admin toggles, keyed by name. */
	private static function enabled_tools() {
		$enabled = array();
		foreach ( self::$tools as $name => $spec ) {
			if ( is_callable( $spec['active_callback'] ) ) {
				if ( call_user_func( $spec['active_callback'] ) ) {
					$enabled[ $name ] = $spec;
				}
				continue;
			}
			if ( '' === $spec['enable_key'] || wsp_mcp_is_enabled( $spec['enable_key'] ) ) {
				$enabled[ $name ] = $spec;
			}
		}
		return $enabled;
	}

	/** Main dispatch by HTTP method. */
	public static function handle( WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( 'OPTIONS' === $method ) {
			return self::cors_preflight();
		}

		$origin = self::validate_origin( $request );
		if ( true !== $origin ) {
			WSP_MCP_Audit_Log::log_rejection( 'origin_blocked', 'Origin rejected on ' . $method . ' /mcp.' );
			return $origin;
		}

		if ( 'GET' === $method ) {
			// SSE not hosted here; tell clients to use POST (spec-compliant 405).
			$resp = self::rpc_error( null, -32600, 'Use HTTP POST for MCP communication.', 405 );
			$resp->header( 'Allow', 'POST, DELETE, OPTIONS' );
			return $resp;
		}

		if ( 'DELETE' === $method ) {
			return self::handle_delete( $request );
		}

		return self::handle_post( $request );
	}

	/** Handle a JSON-RPC POST. */
	private static function handle_post( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] ) {
			return self::rpc_error( null, -32600, 'Invalid JSON-RPC request.', 400 );
		}

		$rpc_method = isset( $body['method'] ) ? $body['method'] : '';
		$params     = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$id         = isset( $body['id'] ) ? $body['id'] : null;

		// Authenticate every request.
		$auth = self::authenticate_and_limit( $request );
		if ( true !== $auth ) {
			return $auth;
		}

		// Notifications (no id) need no response.
		if ( in_array( $rpc_method, array( 'notifications/initialized', 'initialized' ), true ) ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( 'initialize' === $rpc_method ) {
			return self::do_initialize( $request, $params, $id );
		}

		// All other methods require a valid, credential-bound session.
		$session_check = self::validate_session( $request, $id );
		if ( true !== $session_check ) {
			return $session_check;
		}

		switch ( $rpc_method ) {
			case 'tools/list':
				return self::do_tools_list( $id );
			case 'tools/call':
				return self::do_tools_call( $id, $params );
			case 'ping':
				return self::rpc_result( $id, new stdClass() );
			case 'resources/list':
				return self::rpc_result( $id, array( 'resources' => wsp_mcp_context_resources() ) );
			case 'resources/read':
				$content = wsp_mcp_context_read_resource( isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '' );
				if ( null === $content ) {
					return self::rpc_error( $id, -32002, 'Resource not found.', 200 );
				}
				return self::rpc_result( $id, array( 'contents' => array( $content ) ) );
			case 'prompts/list':
				return self::rpc_result( $id, array( 'prompts' => array() ) );
			default:
				return self::rpc_error( $id, -32601, 'Method not found: ' . $rpc_method, 200 );
		}
	}

	/** initialize: negotiate protocol, open a session. */
	private static function do_initialize( WP_REST_Request $request, $params, $id ) {
		$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
		$protocol  = in_array( $requested, self::SUPPORTED_PROTOCOLS, true ) ? $requested : self::DEFAULT_PROTOCOL;

		$session_id  = bin2hex( random_bytes( 16 ) );
		$fingerprint = WSP_MCP_Auth::fingerprint( $request );
		WSP_MCP_Session_Store::create_session( $session_id, $fingerprint );

		$result = array(
			'protocolVersion' => $protocol,
			'serverInfo'      => array(
				'name'    => 'WebSensePro MCP',
				'version' => defined( 'WSP_MCP_VERSION' ) ? WSP_MCP_VERSION : '2.0.0',
			),
			'capabilities'    => array(
				'tools' => new stdClass(),
			),
		);

		// Site Context (MCP > Context): hand the agent the admin's AGENTS.md /
		// CHANGELOG.md up front. Absent entirely unless the admin enabled it.
		$instructions = wsp_mcp_context_instructions();
		if ( '' !== $instructions ) {
			$result['instructions']             = $instructions;
			$result['capabilities']['resources'] = new stdClass();
		}

		$response = self::rpc_result( $id, $result );
		$response->header( 'Mcp-Session-Id', $session_id );
		return $response;
	}

	/** tools/list: advertise enabled tools. */
	private static function do_tools_list( $id ) {
		$tools = array();
		foreach ( self::enabled_tools() as $name => $spec ) {
			$tools[] = array(
				'name'        => $name,
				'description' => $spec['description'],
				'inputSchema' => $spec['inputSchema'],
			);
		}
		return self::rpc_result( $id, array( 'tools' => $tools ) );
	}

	/** tools/call: capability-gate then invoke the wrapped callback. */
	private static function do_tools_call( $id, $params ) {
		$name  = isset( $params['name'] ) ? $params['name'] : '';
		$args  = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$start = microtime( true );

		$enabled = self::enabled_tools();
		if ( ! isset( $enabled[ $name ] ) ) {
			WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_ERROR, 'Unknown or disabled tool.', self::elapsed_ms( $start ), '' );
			return self::rpc_error( $id, -32602, 'Unknown or disabled tool: ' . $name, 200 );
		}
		$spec     = $enabled[ $name ];
		$category = self::tool_category( $spec );

		$cap = WSP_MCP_Auth::require_cap( $spec['capability'] );
		if ( is_wp_error( $cap ) ) {
			WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_DENIED, $cap->get_error_message(), self::elapsed_ms( $start ), $category );
			return self::tool_text( $id, 'Error: ' . $cap->get_error_message(), true );
		}

		if ( ! is_callable( $spec['callback'] ) ) {
			WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_ERROR, 'Tool has no handler.', self::elapsed_ms( $start ), $category );
			return self::tool_text( $id, 'Error: tool has no handler.', true );
		}

		self::arm_fatal_guard( $id, $name, $category, $start );
		try {
			$result = call_user_func( $spec['callback'], $args );
		} catch ( \Throwable $e ) {
			WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_ERROR, $e->getMessage(), self::elapsed_ms( $start ), $category );
			return self::tool_text( $id, 'Error: ' . $e->getMessage(), true );
		}

		self::$fatal_armed = false;

		if ( is_wp_error( $result ) ) {
			// Object-level guards (guard.php, acf.php, yoast.php, rankmath.php, …)
			// return WP_Error( 'forbidden', … ) when the caller lacks permission
			// on the specific object — log those as authorization denials, not
			// generic errors, so they surface correctly for security auditing.
			$status = ( 'forbidden' === $result->get_error_code() ) ? WSP_MCP_Audit_Log::STATUS_DENIED : WSP_MCP_Audit_Log::STATUS_ERROR;
			WSP_MCP_Audit_Log::log( $name, $status, $result->get_error_message(), self::elapsed_ms( $start ), $category );
			return self::tool_text( $id, 'Error: ' . $result->get_error_message(), true );
		}

		WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_SUCCESS, '', self::elapsed_ms( $start ), $category );
		wsp_mcp_review_record_success();
		return self::tool_text( $id, wp_json_encode( $result, JSON_PRETTY_PRINT ), false );
	}

	/** True while a tool callback is running (see arm_fatal_guard()). */
	private static $fatal_armed = false;

	/**
	 * A tool (e.g. a theme upload that leaves broken PHP behind) can hit a true
	 * PHP fatal, which try/catch cannot intercept and which would surface as a
	 * bare 500/502. On shutdown after such a fatal, log it and answer with a
	 * proper JSON-RPC tool error so the client stays connected.
	 */
	private static function arm_fatal_guard( $id, $name, $category, $start ) {
		self::$fatal_armed = true;
		register_shutdown_function( function () use ( $id, $name, $category, $start ) {
			if ( ! self::$fatal_armed ) {
				return;
			}
			$err = error_get_last();
			if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				return;
			}
			$msg = 'PHP fatal during tool execution: ' . $err['message'];
			WSP_MCP_Audit_Log::log( $name, WSP_MCP_Audit_Log::STATUS_ERROR, $msg, self::elapsed_ms( $start ), $category );
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( ! headers_sent() ) {
				status_header( 200 );
				header( 'Content-Type: application/json; charset=utf-8' );
			}
			echo wp_json_encode( array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => array(
					'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . wp_strip_all_tags( $msg ) ) ),
					'isError' => true,
				),
			) );
		} );
	}

	/** Milliseconds elapsed since $start (a microtime(true) value), rounded to the nearest int. */
	private static function elapsed_ms( $start ) {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	/**
	 * Map a registered tool spec to its ability-registry group (e.g. "Elementor",
	 * "WooCommerce", "Posts") for the Analytics usage breakdown. Falls back to ''
	 * (displayed as "Uncategorized") when the registry is unavailable or the tool
	 * has no matching entry.
	 */
	private static function tool_category( array $spec ) {
		$enable_key = isset( $spec['enable_key'] ) ? $spec['enable_key'] : '';
		if ( '' === $enable_key || ! function_exists( 'wsp_mcp_ability_registry' ) ) {
			return '';
		}
		static $registry = null;
		if ( null === $registry ) {
			$registry = wsp_mcp_ability_registry();
		}
		return isset( $registry[ $enable_key ]['group'] ) ? $registry[ $enable_key ]['group'] : '';
	}

	/** DELETE: terminate a session. */
	private static function handle_delete( WP_REST_Request $request ) {
		$auth = self::authenticate_and_limit( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		$session_id = $request->get_header( 'mcp-session-id' );
		if ( is_string( $session_id ) && '' !== $session_id ) {
			WSP_MCP_Session_Store::delete_session( $session_id );
		}
		return self::with_headers( new WP_REST_Response( null, 200 ) );
	}

	/**
	 * Validate the Mcp-Session-Id header against the store and its fingerprint.
	 *
	 * @return true|WP_REST_Response
	 */
	private static function validate_session( WP_REST_Request $request, $id ) {
		$session_id = $request->get_header( 'mcp-session-id' );
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return self::rpc_error( $id, -32600, 'Mcp-Session-Id header required. Call initialize first.', 400 );
		}
		$prefix = substr( $session_id, 0, 8 );
		if ( ! WSP_MCP_Session_Store::touch_session( $session_id ) ) {
			WSP_MCP_Audit_Log::log_rejection( 'session_expired', 'Unknown or expired session ' . $prefix . '… (404, client should re-initialize).' );
			return self::rpc_error( $id, -32600, 'Session not found or expired. Re-initialize.', 404 );
		}
		$stored = WSP_MCP_Session_Store::get_fingerprint( $session_id );
		if ( '' !== $stored && ! hash_equals( $stored, WSP_MCP_Auth::fingerprint( $request ) ) ) {
			// 404, not 403: per the MCP spec a 404 tells the client the session is
			// gone and to send a fresh initialize. Clients do not recover from 403.
			WSP_MCP_Audit_Log::log_rejection( 'session_mismatch', 'Session ' . $prefix . '… belongs to a different user (404, client should re-initialize).' );
			return self::rpc_error( $id, -32600, 'Session not found or expired. Re-initialize.', 404 );
		}
		return true;
	}

	/**
	 * Authenticate, then apply the per-user rate limit. Failed authentication is
	 * throttled per IP (brute-force guard) and audit-logged by WSP_MCP_Auth.
	 *
	 * @return true|WP_REST_Response
	 */
	private static function authenticate_and_limit( WP_REST_Request $request ) {
		$auth = WSP_MCP_Auth::authenticate( $request );
		if ( true !== $auth ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
			if ( true !== self::bump_rate( 'wsp_mcp_rate_fail_' . md5( $ip ), self::RATE_FAIL_MAX ) ) {
				WSP_MCP_Audit_Log::log_rejection( 'rate_limited', 'Too many failed authentication attempts from one IP (429).' );
				return self::rpc_error( null, -32600, 'Rate limit exceeded.', 429 );
			}
			return $auth;
		}
		$user_id = get_current_user_id();
		if ( true !== self::bump_rate( 'wsp_mcp_rate_u_' . $user_id, self::RATE_MAX ) ) {
			WSP_MCP_Audit_Log::log_rejection( 'rate_limited', 'User ' . $user_id . ' exceeded ' . self::RATE_MAX . ' requests/' . self::RATE_WINDOW . 's (429).' );
			return self::rpc_error( null, -32600, 'Rate limit exceeded.', 429 );
		}
		return true;
	}

	/* ---------- Origin / rate limiting ---------- */

	private static function validate_origin( WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( empty( $origin ) ) {
			return true; // Non-browser MCP client.
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( ! $host ) {
			return self::rpc_error( null, -32600, 'Invalid Origin header.', 400 );
		}
		$allowed = apply_filters( 'wsp_mcp_allowed_origins', array(
			wp_parse_url( home_url(), PHP_URL_HOST ),
			'localhost', '127.0.0.1', '::1',
			'claude.ai', 'www.claude.ai', 'chatgpt.com', 'chat.openai.com',
		) );
		if ( ! in_array( $host, $allowed, true ) ) {
			return self::rpc_error( null, -32600, 'Origin not allowed.', 403 );
		}
		return true;
	}

	/** Count one hit against a transient bucket. @return true|false false when over $max. */
	private static function bump_rate( $key, $max ) {
		$data = get_transient( $key );
		if ( false === $data || ! is_array( $data ) ) {
			set_transient( $key, array( 'count' => 1, 'start' => time() ), self::RATE_WINDOW );
			return true;
		}
		$data['count']++;
		// Keep the original window: remaining TTL, not a fresh one.
		$left = max( 1, self::RATE_WINDOW - ( time() - (int) $data['start'] ) );
		set_transient( $key, $data, $left );
		return $data['count'] <= $max;
	}

	/* ---------- Response helpers ---------- */

	private static function cors_preflight() {
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, Mcp-Session-Id, X-WSP-MCP-API-Key' );
		$response->header( 'Access-Control-Max-Age', '86400' );
		return $response;
	}

	private static function with_headers( WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		$response->header( 'Access-Control-Expose-Headers', 'Mcp-Session-Id' );
		return $response;
	}

	private static function rpc_result( $id, $result ) {
		return self::with_headers( new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		), 200 ) );
	}

	private static function rpc_error( $id, $code, $message, $status = 200 ) {
		return self::with_headers( new WP_REST_Response( array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array( 'code' => $code, 'message' => $message ),
		), $status ) );
	}

	/** Wrap a tool result as an MCP content block. */
	private static function tool_text( $id, $text, $is_error ) {
		$result = array(
			'content' => array( array( 'type' => 'text', 'text' => (string) $text ) ),
		);
		if ( $is_error ) {
			$result['isError'] = true;
		}
		return self::rpc_result( $id, $result );
	}
}
