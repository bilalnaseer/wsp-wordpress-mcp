<?php
/**
 * MCP Analytics & Performance Dashboard admin page.
 *
 * Read-only view over the wsp_mcp_audit_log table (see
 * includes/audit/class-audit-log.php), which already records every
 * `tools/call` request together with its ability category, execution
 * duration, and success/denied/error outcome. This page only aggregates and
 * displays that data — no external service, tracking script, or paid
 * dependency is involved.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Register the Analytics submenu under the MCP top-level menu. */
function wsp_mcp_add_analytics_menu() {
	$page_hook = add_submenu_page(
		'wsp-mcp-abilities',
		'Analytics',
		'Analytics',
		'manage_options',
		'wsp-mcp-analytics',
		'wsp_mcp_analytics_page'
	);

	add_action( 'load-' . $page_hook, 'wsp_mcp_enqueue_analytics_assets' );
}
add_action( 'admin_menu', 'wsp_mcp_add_analytics_menu', 35 );

/** Enqueue this page's inline styles. */
function wsp_mcp_enqueue_analytics_assets() {
	add_action( 'admin_enqueue_scripts', function () {
		$custom_css = '
			.wsp-wrap{max-width:1180px;margin:24px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			.wsp-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap}
			.wsp-header h1{margin:0;font-size:22px;font-weight:700;color:#1d2327}
			.wsp-desc{color:#646970;margin:0 0 20px;font-size:13.5px;line-height:1.65}

			.wsp-range-form{display:flex;align-items:center;gap:8px}
			.wsp-range-form select{min-height:32px}

			.wsp-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
			.wsp-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.wsp-card-l{font-size:11px;color:#787c82;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:6px}
			.wsp-card-n{font-size:26px;font-weight:700;color:#1d2327;line-height:1.2;word-break:break-word}
			.wsp-card-s{font-size:12px;color:#787c82;margin-top:4px}
			.wsp-card--requests .wsp-card-n{color:#2271b1}
			.wsp-card--tool .wsp-card-n{font-size:17px;color:#1d2327}
			.wsp-card--time .wsp-card-n{color:#8a6100}
			.wsp-card--errors .wsp-card-n{color:#d63638}
			.wsp-card--errors.wsp-ok .wsp-card-n{color:#00a32a}

			.wsp-panels{display:grid;grid-template-columns:1.3fr 1fr;gap:16px;align-items:start;margin-bottom:16px}
			@media (max-width:960px){.wsp-panels{grid-template-columns:1fr}}

			.wsp-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);overflow:hidden}
			.wsp-panel-h{padding:14px 18px;border-bottom:1px solid #f0f0f1;font-weight:700;font-size:13.5px;color:#1d2327;display:flex;align-items:center;justify-content:space-between}
			.wsp-panel-h span.wsp-panel-sub{font-weight:400;color:#787c82;font-size:12px}
			.wsp-panel-body{padding:16px 18px}

			.wsp-bar-row{margin-bottom:14px}
			.wsp-bar-row:last-child{margin-bottom:0}
			.wsp-bar-top{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:5px;gap:10px}
			.wsp-bar-name{font-size:12.5px;font-weight:600;color:#1d2327}
			.wsp-bar-meta{font-size:11.5px;color:#787c82;white-space:nowrap}
			.wsp-bar-track{background:#f0f0f1;border-radius:20px;height:8px;overflow:hidden}
			.wsp-bar-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#2271b1,#72aee6)}

			.wsp-empty{padding:30px 20px;text-align:center;color:#787c82;font-size:13px}

			.wsp-log-table{width:100%;border-collapse:collapse}
			.wsp-log-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#787c82;background:#f6f7f7;padding:9px 18px;border-bottom:1px solid #dcdcde}
			.wsp-log-table td{padding:10px 18px;font-size:12.5px;color:#1d2327;border-bottom:1px solid #f0f0f1;vertical-align:top}
			.wsp-log-table tr:last-child td{border-bottom:none}
			.wsp-log-table code{background:#f0f0f1;padding:2px 6px;border-radius:4px;font-size:11.5px}

			.wsp-status{display:inline-block;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px}
			.wsp-status--success{background:#e3f4e6;color:#00802b}
			.wsp-status--denied{background:#fdf3d9;color:#8a6100}
			.wsp-status--error{background:#fdeaea;color:#b32d2e}

			.wsp-duration{font-variant-numeric:tabular-nums}
			.wsp-duration--slow{color:#b32d2e;font-weight:700}
			.wsp-duration--mid{color:#8a6100;font-weight:600}
		';
		wp_add_inline_style( 'common', $custom_css );
	} );
}

/**
 * Render a status pill (shared look with the Audit Log page).
 *
 * @param string $status Audit-log status: success, denied or error.
 */
function wsp_mcp_analytics_status_badge( $status ) {
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

/**
 * Render a duration cell, colour-coded by rough response-time thresholds.
 *
 * @param int $ms Duration in milliseconds.
 */
function wsp_mcp_analytics_duration_cell( $ms ) {
	$ms    = (int) $ms;
	$class = 'wsp-duration';
	if ( $ms >= 2000 ) {
		$class .= ' wsp-duration--slow';
	} elseif ( $ms >= 500 ) {
		$class .= ' wsp-duration--mid';
	}
	printf( '<span class="%s">%s ms</span>', esc_attr( $class ), esc_html( number_format_i18n( $ms ) ) );
}

/** Render the Analytics & Performance Dashboard page. */
function wsp_mcp_analytics_page() {
	// Only site administrators may view MCP analytics.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view MCP analytics.', 'wsp-mcp-ai-agents-connector' ) );
	}

	// Read-only range selector; no state is changed, so (like the Audit Log
	// page's filters) this does not require a nonce.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter control, no state change.
	$days = isset( $_GET['range'] ) ? absint( wp_unslash( $_GET['range'] ) ) : 30;
	if ( ! in_array( $days, array( 0, 7, 30, 90 ), true ) ) {
		$days = 30;
	}

	$overview   = WSP_MCP_Audit_Log::get_analytics_overview( $days );
	$breakdown  = WSP_MCP_Audit_Log::get_category_breakdown( $days, 10 );
	$recent     = WSP_MCP_Audit_Log::get_recent_performance( 25 );
	$range_label = array(
		0  => __( 'all time', 'wsp-mcp-ai-agents-connector' ),
		7  => __( 'last 7 days', 'wsp-mcp-ai-agents-connector' ),
		30 => __( 'last 30 days', 'wsp-mcp-ai-agents-connector' ),
		90 => __( 'last 90 days', 'wsp-mcp-ai-agents-connector' ),
	);
	?>
	<div class="wsp-wrap">
		<div class="wsp-header">
			<h1>📊 Analytics &amp; Performance Dashboard</h1>
			<form method="get" class="wsp-range-form">
				<input type="hidden" name="page" value="wsp-mcp-analytics" />
				<select name="range" onchange="this.form.submit()">
					<option value="7" <?php selected( $days, 7 ); ?>><?php esc_html_e( 'Last 7 days', 'wsp-mcp-ai-agents-connector' ); ?></option>
					<option value="30" <?php selected( $days, 30 ); ?>><?php esc_html_e( 'Last 30 days', 'wsp-mcp-ai-agents-connector' ); ?></option>
					<option value="90" <?php selected( $days, 90 ); ?>><?php esc_html_e( 'Last 90 days', 'wsp-mcp-ai-agents-connector' ); ?></option>
					<option value="0" <?php selected( $days, 0 ); ?>><?php esc_html_e( 'All time', 'wsp-mcp-ai-agents-connector' ); ?></option>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'wsp-mcp-ai-agents-connector' ); ?></button></noscript>
			</form>
		</div>
		<p class="wsp-desc">
			<?php
			printf(
				/* translators: %s: human-readable time range, e.g. "last 30 days". */
				esc_html__( 'Tool usage, response times, and error rates for MCP requests over the %s. Computed entirely from your own Audit Log database — no external analytics service is involved.', 'wsp-mcp-ai-agents-connector' ),
				esc_html( $range_label[ $days ] )
			);
			?>
		</p>

		<div class="wsp-cards">
			<div class="wsp-card wsp-card--requests">
				<div class="wsp-card-l">📨 <?php esc_html_e( 'Total Requests', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-card-n"><?php echo esc_html( number_format_i18n( $overview['total_requests'] ) ); ?></div>
			</div>
			<div class="wsp-card wsp-card--tool">
				<div class="wsp-card-l">🏆 <?php esc_html_e( 'Most Used Tool', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<?php if ( '' !== $overview['most_used_tool'] ) : ?>
					<div class="wsp-card-n"><code><?php echo esc_html( $overview['most_used_tool'] ); ?></code></div>
					<div class="wsp-card-s">
						<?php
						printf(
							/* translators: %s: call count. */
							esc_html__( '%s calls', 'wsp-mcp-ai-agents-connector' ),
							esc_html( number_format_i18n( $overview['most_used_count'] ) )
						);
						?>
					</div>
				<?php else : ?>
					<div class="wsp-card-n">—</div>
				<?php endif; ?>
			</div>
			<div class="wsp-card wsp-card--time">
				<div class="wsp-card-l">⏱️ <?php esc_html_e( 'Avg Response Time', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-card-n"><?php echo esc_html( number_format_i18n( $overview['avg_duration_ms'], 0 ) ); ?> ms</div>
			</div>
			<div class="wsp-card wsp-card--errors<?php echo $overview['error_rate'] <= 1.0 ? ' wsp-ok' : ''; ?>">
				<div class="wsp-card-l">⚠️ <?php esc_html_e( 'Error Rate', 'wsp-mcp-ai-agents-connector' ); ?></div>
				<div class="wsp-card-n"><?php echo esc_html( number_format_i18n( $overview['error_rate'], 1 ) ); ?>%</div>
				<div class="wsp-card-s">
					<?php
					printf(
						/* translators: %s: error count. */
						esc_html__( '%s errors', 'wsp-mcp-ai-agents-connector' ),
						esc_html( number_format_i18n( $overview['error_count'] ) )
					);
					?>
				</div>
			</div>
		</div>

		<div class="wsp-panels">
			<div class="wsp-panel">
				<div class="wsp-panel-h">
					<span><?php esc_html_e( 'Tool Usage Breakdown', 'wsp-mcp-ai-agents-connector' ); ?></span>
					<span class="wsp-panel-sub"><?php esc_html_e( 'by category', 'wsp-mcp-ai-agents-connector' ); ?></span>
				</div>
				<div class="wsp-panel-body">
					<?php if ( empty( $breakdown ) ) : ?>
						<div class="wsp-empty"><?php esc_html_e( 'No MCP tool calls recorded in this range yet.', 'wsp-mcp-ai-agents-connector' ); ?></div>
					<?php else : ?>
						<?php foreach ( $breakdown as $row ) : ?>
							<div class="wsp-bar-row">
								<div class="wsp-bar-top">
									<span class="wsp-bar-name"><?php echo esc_html( $row['category'] ); ?></span>
									<span class="wsp-bar-meta">
										<?php
										printf(
											/* translators: 1: call count, 2: percent of total, 3: average duration in ms. */
											esc_html__( '%1$s calls · %2$s%% · avg %3$sms', 'wsp-mcp-ai-agents-connector' ),
											esc_html( number_format_i18n( $row['n'] ) ),
											esc_html( number_format_i18n( $row['percent'], 1 ) ),
											esc_html( number_format_i18n( $row['avg_ms'], 0 ) )
										);
										?>
									</span>
								</div>
								<div class="wsp-bar-track">
									<div class="wsp-bar-fill" style="width:<?php echo esc_attr( max( 2, min( 100, (float) $row['percent'] ) ) ); ?>%"></div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="wsp-panel">
				<div class="wsp-panel-h">
					<span><?php esc_html_e( 'Status Mix', 'wsp-mcp-ai-agents-connector' ); ?></span>
					<span class="wsp-panel-sub"><?php echo esc_html( $range_label[ $days ] ); ?></span>
				</div>
				<div class="wsp-panel-body">
					<?php
					$total = max( 1, (int) $overview['total_requests'] );
					$success_n = max( 0, $overview['total_requests'] - $overview['error_count'] );
					$status_rows = array(
						array( 'label' => __( 'Success', 'wsp-mcp-ai-agents-connector' ), 'n' => $success_n, 'class' => 'wsp-status--success' ),
						array( 'label' => __( 'Error', 'wsp-mcp-ai-agents-connector' ), 'n' => $overview['error_count'], 'class' => 'wsp-status--error' ),
					);
					foreach ( $status_rows as $row ) :
						$pct = round( ( $row['n'] / $total ) * 100, 1 );
						?>
						<div class="wsp-bar-row">
							<div class="wsp-bar-top">
								<span class="wsp-bar-name"><?php echo esc_html( $row['label'] ); ?></span>
								<span class="wsp-bar-meta"><?php echo esc_html( number_format_i18n( $row['n'] ) ); ?> (<?php echo esc_html( number_format_i18n( $pct, 1 ) ); ?>%)</span>
							</div>
							<div class="wsp-bar-track">
								<div class="wsp-bar-fill" style="width:<?php echo esc_attr( max( 0, min( 100, $pct ) ) ); ?>%;background:<?php echo 'wsp-status--error' === $row['class'] ? '#d63638' : '#00a32a'; ?>"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="wsp-panel">
			<div class="wsp-panel-h">
				<span><?php esc_html_e( 'Recent Requests', 'wsp-mcp-ai-agents-connector' ); ?></span>
				<span class="wsp-panel-sub"><?php esc_html_e( 'most recent first', 'wsp-mcp-ai-agents-connector' ); ?></span>
			</div>
			<?php if ( empty( $recent ) ) : ?>
				<div class="wsp-empty"><?php esc_html_e( 'No MCP tool calls have been logged yet.', 'wsp-mcp-ai-agents-connector' ); ?></div>
			<?php else : ?>
				<div style="overflow-x:auto">
				<table class="wsp-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Category', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'wsp-mcp-ai-agents-connector' ); ?></th>
							<th><?php esc_html_e( 'User', 'wsp-mcp-ai-agents-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><code><?php echo esc_html( $row->tool_name ); ?></code></td>
							<td><?php echo esc_html( $row->category ? $row->category : '—' ); ?></td>
							<td><?php wsp_mcp_analytics_status_badge( $row->status ); ?></td>
							<td><?php wsp_mcp_analytics_duration_cell( $row->duration_ms ); ?></td>
							<td>
								<?php if ( $row->user_id ) : ?>
									<?php echo esc_html( $row->user_login ? $row->user_login : ( '#' . $row->user_id ) ); ?>
								<?php else : ?>
									<em><?php esc_html_e( 'unauthenticated', 'wsp-mcp-ai-agents-connector' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
