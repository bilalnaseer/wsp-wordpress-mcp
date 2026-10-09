<?php
/**
 * MCP transport diagnostics: live session count and recent rejections by reason,
 * so an admin (or agent) can see why clients are being blocked without DB access.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_mcp_diagnostics( $input ) {
	$hours = isset( $input['hours'] ) ? max( 1, min( 720, (int) $input['hours'] ) ) : 24;
	return array(
		'plugin_version'  => defined( 'WSP_MCP_VERSION' ) ? WSP_MCP_VERSION : '',
		'session_ttl_sec' => WSP_MCP_Session_Store::TTL,
		'active_sessions' => WSP_MCP_Session_Store::count_active(),
		'window_hours'    => $hours,
		'rejections'      => WSP_MCP_Audit_Log::get_rejection_counts( $hours ),
		'recent'          => WSP_MCP_Audit_Log::get_recent_rejections( 20 ),
		'rate_limit'      => array( 'per_user' => WSP_MCP_Server::RATE_MAX, 'window_sec' => WSP_MCP_Server::RATE_WINDOW, 'failed_auth_per_ip' => WSP_MCP_Server::RATE_FAIL_MAX ),
		'oauth_enabled'   => function_exists( 'wsp_mcp_oauth_is_enabled' ) && wsp_mcp_oauth_is_enabled(),
	);
}
