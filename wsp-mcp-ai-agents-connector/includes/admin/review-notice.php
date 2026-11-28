<?php
/**
 * Review request notice.
 *
 * Asks for a WordPress.org review on the Plugins screen, but only once an AI
 * client has actually completed a tool call — never on activation, when nobody
 * has an opinion yet. The first successful call is recorded by
 * wsp_mcp_review_record_success(), called from WSP_MCP_Server::do_tools_call().
 *
 * Dismissal is per admin user and needs no JavaScript: "Leave a review" and
 * "Don't show again" hide it forever, "Maybe later" hides it for
 * WSP_MCP_REVIEW_SNOOZE_DAYS days.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Option holding the timestamp of the first successful tool call. */
define( 'WSP_MCP_FIRST_SUCCESS_OPTION', 'wsp_mcp_first_success' );
/** User meta: 'dismissed', or a timestamp the snooze runs until. */
define( 'WSP_MCP_REVIEW_META', 'wsp_mcp_review_notice' );
define( 'WSP_MCP_REVIEW_SNOOZE_DAYS', 14 );
define( 'WSP_MCP_REVIEW_URL', 'https://wordpress.org/support/plugin/wsp-mcp-ai-agents-connector/reviews/#new-post' );

/**
 * Record the first successful tool call. Cheap on every later call: the
 * option is autoloaded, so get_option() is served from memory.
 */
function wsp_mcp_review_record_success() {
	if ( false === get_option( WSP_MCP_FIRST_SUCCESS_OPTION ) ) {
		add_option( WSP_MCP_FIRST_SUCCESS_OPTION, time(), '', true );
	}
}

/**
 * Sites that were already using the plugin before this notice existed have
 * successful calls in the audit log but no WSP_MCP_FIRST_SUCCESS_OPTION yet.
 * Record the option from the log so those sites don't have to wait for their
 * next call. One-way: once the option is set, the log is never read again,
 * so clearing the log can't make the notice reappear or vanish.
 *
 * @return bool True if a past success was found (and recorded).
 */
function wsp_mcp_review_backfill_from_log() {
	if ( ! class_exists( 'WSP_MCP_Audit_Log' ) ) {
		return false;
	}
	if ( WSP_MCP_Audit_Log::count_entries( array( 'status' => WSP_MCP_Audit_Log::STATUS_SUCCESS ) ) < 1 ) {
		return false;
	}
	wsp_mcp_review_record_success();
	return true;
}

/** Whether the current user should see the notice right now. */
function wsp_mcp_review_should_show() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( false === get_option( WSP_MCP_FIRST_SUCCESS_OPTION ) && ! wsp_mcp_review_backfill_from_log() ) {
		return false;
	}
	$state = get_user_meta( get_current_user_id(), WSP_MCP_REVIEW_META, true );
	if ( 'dismissed' === $state ) {
		return false;
	}
	if ( is_numeric( $state ) && (int) $state > time() ) {
		return false;
	}
	return true;
}

/**
 * Build a nonce-protected admin-post URL for one of the notice's actions.
 *
 * @param string $choice One of review, snooze or dismiss.
 * @return string Nonce-protected URL.
 */
function wsp_mcp_review_action_url( $choice ) {
	return wp_nonce_url(
		add_query_arg(
			array( 'action' => 'wsp_mcp_review_notice', 'choice' => $choice ),
			admin_url( 'admin-post.php' )
		),
		'wsp_mcp_review_notice'
	);
}

/**
 * Whether the current screen is one the notice may appear on: the Plugins
 * screen or one of this plugin's own MCP pages. Matched on the `page` query
 * arg rather than the screen ID, because submenu screen IDs are derived from
 * the (translatable) top-level menu title.
 */
function wsp_mcp_review_is_allowed_screen() {
	$screen = get_current_screen();
	if ( $screen && 'plugins' === $screen->id ) {
		return true;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return in_array( $page, array( 'wsp-mcp-abilities', 'wsp-mcp-connection', 'wsp-mcp-context', 'wsp-mcp-audit-log', 'wsp-mcp-analytics' ), true );
}

/** Render the notice on the Plugins screen and the MCP pages. */
function wsp_mcp_review_render_notice() {
	if ( ! wsp_mcp_review_is_allowed_screen() ) {
		return;
	}
	if ( ! wsp_mcp_review_should_show() ) {
		return;
	}
	?>
	<div class="notice notice-info">
		<p><strong><?php esc_html_e( 'Is WSP MCP working for you?', 'wsp-mcp-ai-agents-connector' ); ?></strong>
			<?php esc_html_e( 'A review helps other people find it.', 'wsp-mcp-ai-agents-connector' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wsp_mcp_review_action_url( 'review' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Leave a review', 'wsp-mcp-ai-agents-connector' ); ?></a>
			<a class="button" href="<?php echo esc_url( wsp_mcp_review_action_url( 'snooze' ) ); ?>"><?php esc_html_e( 'Maybe later', 'wsp-mcp-ai-agents-connector' ); ?></a>
			<a href="<?php echo esc_url( wsp_mcp_review_action_url( 'dismiss' ) ); ?>" style="margin-left:8px"><?php esc_html_e( "Don't show again", 'wsp-mcp-ai-agents-connector' ); ?></a>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'wsp_mcp_review_render_notice' );

/** Handle the notice's buttons: review, snooze, dismiss. */
function wsp_mcp_review_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wsp-mcp-ai-agents-connector' ) );
	}
	check_admin_referer( 'wsp_mcp_review_notice' );

	$choice  = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
	$user_id = get_current_user_id();

	if ( 'snooze' === $choice ) {
		update_user_meta( $user_id, WSP_MCP_REVIEW_META, time() + WSP_MCP_REVIEW_SNOOZE_DAYS * DAY_IN_SECONDS );
	} else {
		// 'review' and 'dismiss' both hide the notice for good.
		update_user_meta( $user_id, WSP_MCP_REVIEW_META, 'dismissed' );
	}

	if ( 'review' === $choice ) {
		// Fixed, hard-coded destination — not user input — so an off-site redirect is safe here.
		wp_redirect( WSP_MCP_REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url( 'plugins.php' ) );
	exit;
}
add_action( 'admin_post_wsp_mcp_review_notice', 'wsp_mcp_review_handle_action' );
