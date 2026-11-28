<?php
/**
 * MCP Audit Log admin page.
 *
 * Read-only view over the wsp_mcp_audit_log table (see
 * includes/audit/class-audit-log.php). Every entry is written server-side
 * whenever WSP_MCP_Server handles a tools/call — this page only displays
 * and prunes that data. No external service is involved.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Register the Audit Log submenu under the MCP top-level menu. */
function wsp_mcp_add_audit_log_menu() {
	$page_hook = add_submenu_page(
		'wsp-mcp-abilities',
		__( 'Audit Log', 'wsp-mcp-ai-agents-connector' ),
		__( 'Audit Log', 'wsp-mcp-ai-agents-connector' ),
		'manage_options',
		'wsp-mcp-audit-log',
		'wsp_mcp_audit_log_page'
	);

	add_action( 'load-' . $page_hook, 'wsp_mcp_enqueue_audit_log_assets' );
}
add_action( 'admin_menu', 'wsp_mcp_add_audit_log_menu', 30 );

/** Enqueue this page's inline styles. */
function wsp_mcp_enqueue_audit_log_assets() {
	add_action( 'admin_enqueue_scripts', function () {
		$custom_css = '
			.wsp-wrap{max-width:1180px;margin:24px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			.wsp-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap}
			.wsp-header h1{margin:0;font-size:22px;font-weight:700;color:#1d2327}
			.wsp-desc{color:#646970;margin:0 0 20px;font-size:13.5px;line-height:1.65}
			.wsp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
			.wsp-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.wsp-stat-n{font-size:26px;font-weight:700;color:#1d2327}
			.wsp-stat-l{font-size:11px;color:#787c82;margin-top:2px;text-transform:uppercase;letter-spacing:.5px}
			.wsp-stat--ok .wsp-stat-n{color:#00a32a}
			.wsp-stat--denied .wsp-stat-n{color:#dba617}
			.wsp-stat--err .wsp-stat-n{color:#d63638}
			.wsp-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin-bottom:14px}
			.wsp-filters select,.wsp-filters input[type=text]{min-height:32px}
			.wsp-log-table{background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.wsp-log-table table{border-collapse:collapse;width:100%}
			.wsp-log-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#787c82;background:#f6f7f7;padding:10px 16px;border-bottom:1px solid #dcdcde}
			.wsp-log-table td{padding:11px 16px;font-size:13px;color:#1d2327;border-bottom:1px solid #f0f0f1;vertical-align:top}
			.wsp-log-table tr:last-child td{border-bottom:none}
			.wsp-log-table code{background:#f0f0f1;padding:2px 6px;border-radius:4px;font-size:12px}
			.wsp-status{display:inline-block;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px}
			.wsp-status--success{background:#e3f4e6;color:#00802b}
			.wsp-status--denied{background:#fdf3d9;color:#8a6100}
			.wsp-status--error{background:#fdeaea;color:#b32d2e}
			.wsp-empty{padding:40px 20px;text-align:center;color:#787c82;font-size:13.5px}
			.wsp-pagination{margin-top:16px;display:flex;justify-content:flex-end;gap:4px}
			.wsp-pagination .page-numbers{display:inline-block;padding:6px 11px;border:1px solid #dcdcde;border-radius:4px;background:#fff;color:#2271b1;font-size:12.5px;text-decoration:none}
			.wsp-pagination .page-numbers.current{background:#2271b1;color:#fff;border-color:#2271b1}
		';
		wp_add_inline_style( 'common', $custom_css );
	} );
}

/** Handle the "Clear log" admin-post action. */
function wsp_mcp_handle_clear_audit_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wsp-mcp-ai-agents-connector' ) );
	}
	check_admin_referer( 'wsp_mcp_clear_audit_log' );
	WSP_MCP_Audit_Log::clear_all();
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'wsp-mcp-audit-log', 'wsp_log_cleared' => '1' ),
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_post_wsp_mcp_clear_audit_log', 'wsp_mcp_handle_clear_audit_log' );

/** Render a status pill. */
function wsp_mcp_audit_status_badge( $status ) {
	$labels = array(
		WSP_MCP_Audit_Log::STATUS_SUCCESS => 'Success',
		WSP_MCP_Audit_Log::STATUS_DENIED  => 'Denied',
		WSP_MCP_Audit_Log::STATUS_ERROR   => 'Error',
	);
	$label = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	printf(
		'<span class="wsp-status wsp-status--%s">%s</span>',
		esc_attr( $status ),
		esc_html( $label )
	);
}

/** Render the Audit Log page. */
function wsp_mcp_audit_log_page() {
	// Only site administrators may view the audit log.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view the MCP audit log.', 'wsp-mcp-ai-agents-connector' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination controls, no state change.
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination controls, no state change.
	$tool = isset( $_GET['tool'] ) ? sanitize_text_field( wp_unslash( $_GET['tool'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination controls, no state change.
	$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

	$per_page = 20;
	$args     = array( 'status' => $status, 'tool' => $tool, 'per_page' => $per_page, 'paged' => $paged );
	$entries  = WSP_MCP_Audit_Log::get_entries( $args );
	$total    = WSP_MCP_Audit_Log::count_entries( $args );
	$tools    = WSP_MCP_Audit_Log::get_distinct_tools();
	$summary  = WSP_MCP_Audit_Log::get_summary();
	$pages    = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="wsp-wrap">
		<div class="wsp-header">
			<h1>🛡️ MCP Audit Log</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wsp_mcp_clear_audit_log" />
				<?php wp_nonce_field( 'wsp_mcp_clear_audit_log' ); ?>
				<button type="submit" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Permanently delete all audit log entries? This cannot be undone.', 'wsp-mcp-ai-agents-connector' ) ); ?>');">
					<?php esc_html_e( 'Clear Log', 'wsp-mcp-ai-agents-connector' ); ?>
				</button>
			</form>
		</div>
		<p class="wsp-desc">
			<?php esc_html_e( 'Every incoming MCP tool call is recorded here — tool name, time, acting user, request IP, and outcome. Stored entirely in your own database, self-hosted, with no external service involved.', 'wsp-mcp-ai-agents-connector' ); ?>
		</p>

		<?php if ( isset( $_GET['wsp_log_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Audit log cleared.', 'wsp-mcp-ai-agents-connector' ); ?>
			</p></div>
		<?php endif; ?>

		<div class="wsp-stats">
			<div class="wsp-stat"><div class="wsp-stat-n"><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></div><div class="wsp-stat-l"><?php esc_html_e( 'Total Calls', 'wsp-mcp-ai-agents-connector' ); ?></div></div>
			<div class="wsp-stat wsp-stat--ok"><div class="wsp-stat-n"><?php echo esc_html( number_format_i18n( $summary[ WSP_MCP_Audit_Log::STATUS_SUCCESS ] ) ); ?></div><div class="wsp-stat-l"><?php esc_html_e( 'Success', 'wsp-mcp-ai-agents-connector' ); ?></div></div>
			<div class="wsp-stat wsp-stat--denied"><div class="wsp-stat-n"><?php echo esc_html( number_format_i18n( $summary[ WSP_MCP_Audit_Log::STATUS_DENIED ] ) ); ?></div><div class="wsp-stat-l"><?php esc_html_e( 'Denied', 'wsp-mcp-ai-agents-connector' ); ?></div></div>
			<div class="wsp-stat wsp-stat--err"><div class="wsp-stat-n"><?php echo esc_html( number_format_i18n( $summary[ WSP_MCP_Audit_Log::STATUS_ERROR ] ) ); ?></div><div class="wsp-stat-l"><?php esc_html_e( 'Error', 'wsp-mcp-ai-agents-connector' ); ?></div></div>
		</div>

		<form method="get" class="wsp-filters">
			<input type="hidden" name="page" value="wsp-mcp-audit-log" />
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'wsp-mcp-ai-agents-connector' ); ?></option>
				<option value="success" <?php selected( $status, 'success' ); ?>><?php esc_html_e( 'Success', 'wsp-mcp-ai-agents-connector' ); ?></option>
				<option value="denied" <?php selected( $status, 'denied' ); ?>><?php esc_html_e( 'Denied', 'wsp-mcp-ai-agents-connector' ); ?></option>
				<option value="error" <?php selected( $status, 'error' ); ?>><?php esc_html_e( 'Error', 'wsp-mcp-ai-agents-connector' ); ?></option>
			</select>
			<select name="tool">
				<option value=""><?php esc_html_e( 'All tools', 'wsp-mcp-ai-agents-connector' ); ?></option>
				<?php foreach ( $tools as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tool, $t ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wsp-mcp-ai-agents-connector' ); ?></button>
			<?php if ( '' !== $status || '' !== $tool ) : ?>
				<a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=wsp-mcp-audit-log' ) ); ?>"><?php esc_html_e( 'Reset', 'wsp-mcp-ai-agents-connector' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="wsp-log-table">
			<?php if ( empty( $entries ) ) : ?>
				<div class="wsp-empty"><?php esc_html_e( 'No MCP tool calls have been logged yet.', 'wsp-mcp-ai-agents-connector' ); ?></div>
			<?php else : ?>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'User', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'IP Address', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'wsp-mcp-ai-agents-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $entries as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><code><?php echo esc_html( $row->tool_name ); ?></code></td>
							<td><?php wsp_mcp_audit_status_badge( $row->status ); ?></td>
							<td>
								<?php if ( $row->user_id ) : ?>
									<?php echo esc_html( $row->user_login ? $row->user_login : ( '#' . $row->user_id ) ); ?>
								<?php else : ?>
									<em><?php esc_html_e( 'unauthenticated', 'wsp-mcp-ai-agents-connector' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row->ip_address ? $row->ip_address : '—' ); ?></td>
							<td><?php echo esc_html( $row->message ? $row->message : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( $pages > 1 ) : ?>
			<div class="wsp-pagination">
				<?php
				echo wp_kses_post( paginate_links( array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				) ) );
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
