<?php
/**
 * MCP > Context admin page (v2.9.5).
 *
 * Lets the site admin switch on "Site Context" and edit the two Markdown
 * documents (AGENTS.md, CHANGELOG.md) that connected agents receive first.
 * Storage + delivery live in includes/context.php; this file is UI only.
 * Agents can also write the documents via the wsp_update_site_context tool.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Register the Context submenu under MCP, right after Connection (20). */
function wsp_mcp_add_context_menu() {
	$page_hook = add_submenu_page(
		'wsp-mcp-abilities',
		'Context',
		'Context',
		'manage_options',
		'wsp-mcp-context',
		'wsp_mcp_context_page'
	);

	add_action( 'load-' . $page_hook, 'wsp_mcp_enqueue_context_assets' );
}
add_action( 'admin_menu', 'wsp_mcp_add_context_menu', 22 );

/** Enqueue this page's inline styles. */
function wsp_mcp_enqueue_context_assets() {
	add_action( 'admin_enqueue_scripts', function () {
		$custom_css = '
			.wsp-wrap{max-width:1180px;margin:24px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			.wsp-header h1{margin:0 0 6px;font-size:22px;font-weight:700;color:#1d2327}
			.wsp-desc{color:#646970;margin:0 0 20px;font-size:13.5px;line-height:1.65}
			.wsp-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:16px;overflow:hidden}
			.wsp-panel-h{padding:14px 18px;border-bottom:1px solid #f0f0f1;font-weight:700;font-size:13.5px;color:#1d2327;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
			.wsp-panel-body{padding:16px 18px;font-size:13px;line-height:1.65;color:#3c434a}
			.wsp-panel-body p{margin:0 0 10px}
			.wsp-ctx-toggle{display:flex;align-items:center;gap:16px;padding:18px}
			.wsp-ctx-toggle-text{flex:1}
			.wsp-ctx-toggle-text strong{display:block;font-size:14px;color:#1d2327;margin-bottom:2px}
			.wsp-ctx-toggle-text span{font-size:12.5px;color:#646970;line-height:1.55}
			.wsp-ctx-state{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:.4px}
			.wsp-ctx-state--on{background:#e3f4e6;color:#00802b}
			.wsp-ctx-state--off{background:#f0f0f1;color:#646970}
			.wsp-sw{position:relative;display:inline-block;width:46px;height:26px;flex-shrink:0}
			.wsp-sw input{opacity:0;width:0;height:0}
			.wsp-sl{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:34px;transition:.25s}
			.wsp-sl:before{position:absolute;content:"";height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
			input:checked+.wsp-sl{background:#00a32a}
			input:checked+.wsp-sl:before{transform:translateX(20px)}
			.wsp-ctx-area{width:100%;min-height:300px;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.55;resize:vertical;box-sizing:border-box}
			.wsp-ctx-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:8px;font-size:12px;color:#787c82;flex-wrap:wrap}
			.wsp-ctx-file{font-size:12px}
			.wsp-ctx-flow{margin:0;padding-left:18px}
			.wsp-ctx-flow li{margin-bottom:4px}
			.wsp-savebar{display:flex;align-items:center;gap:16px;padding:18px 20px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
			.wsp-savebar .button-primary{font-size:14px;padding:7px 20px;height:auto}
			.wsp-savenote{font-size:12px;color:#787c82}
		' . wsp_mcp_promo_css();
		wp_add_inline_style( 'common', $custom_css );
	} );
}

/** Handle the page's save form. */
function wsp_mcp_handle_save_context() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wsp-mcp-ai-agents-connector' ) );
	}
	check_admin_referer( 'wsp_mcp_save_context' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
	$enabled   = ! empty( $_POST['wsp_context_enabled'] );
	$agents    = isset( $_POST['wsp_context_agents'] ) ? wsp_mcp_context_sanitize( wp_unslash( $_POST['wsp_context_agents'] ) ) : '';
	$changelog = isset( $_POST['wsp_context_changelog'] ) ? wsp_mcp_context_sanitize( wp_unslash( $_POST['wsp_context_changelog'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	update_option( WSP_MCP_CONTEXT_ENABLED_OPTION, $enabled ? 1 : 0, false );
	update_option( WSP_MCP_CONTEXT_AGENTS_OPTION, $agents, false );
	update_option( WSP_MCP_CONTEXT_CHANGELOG_OPTION, $changelog, false );

	wp_safe_redirect( add_query_arg(
		array( 'page' => 'wsp-mcp-context', 'wsp_context_saved' => '1' ),
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_post_wsp_mcp_save_context', 'wsp_mcp_handle_save_context' );

/** Render the Context page. */
function wsp_mcp_context_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$enabled   = wsp_mcp_context_is_enabled();
	$active    = wsp_mcp_context_is_active();
	$agents    = wsp_mcp_context_get( 'agents' );
	$changelog = wsp_mcp_context_get( 'changelog' );
	$max       = WSP_MCP_CONTEXT_MAX_CHARS;

	$agents_placeholder = "# About this site\n"
		. "What the site is, who it is for, and the stack (theme, page builder, key plugins).\n\n"
		. "# Structure\n"
		. "- Pages and where they live (e.g. Home = page ID 12, Pricing = /pricing/)\n"
		. "- Custom post types, menus, and widget areas in use\n\n"
		. "# Rules for agents\n"
		. "- Brand voice and tone\n"
		. "- Never edit / delete: ...\n"
		. "- Always ask before: ...\n";

	$changelog_placeholder = "## 2026-01-15\n- Rebuilt the Pricing page with Elementor; old page moved to draft.\n\n"
		. "## 2026-01-02\n- Switched theme to Astra child theme.\n";

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
	$saved = isset( $_GET['wsp_context_saved'] );
	?>
	<div class="wrap wsp-wrap">
		<div class="wsp-header"><h1><?php esc_html_e( 'Site Context for AI Agents', 'wsp-mcp-ai-agents-connector' ); ?></h1></div>
		<p class="wsp-desc"><?php esc_html_e( 'Write AGENTS.md and CHANGELOG.md once. When Site Context is on, every connected agent receives them first, so it does not have to read your whole site to learn how it is built. That saves tokens and time.', 'wsp-mcp-ai-agents-connector' ); ?></p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Context saved. Connected agents pick up changes the next time they connect — restart or reconnect the MCP client.', 'wsp-mcp-ai-agents-connector' ); ?></p></div>
		<?php endif; ?>

		<div class="wsp-layout">
			<div class="wsp-main">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wsp_mcp_save_context">
					<?php wp_nonce_field( 'wsp_mcp_save_context' ); ?>

					<div class="wsp-panel">
						<div class="wsp-ctx-toggle">
							<div class="wsp-ctx-toggle-text">
								<strong><?php esc_html_e( 'Enable Site Context', 'wsp-mcp-ai-agents-connector' ); ?></strong>
								<span><?php esc_html_e( 'Off by default. When on, the documents below are the first thing a connected agent reads.', 'wsp-mcp-ai-agents-connector' ); ?></span>
							</div>
							<?php if ( $active ) : ?>
								<span class="wsp-ctx-state wsp-ctx-state--on"><?php esc_html_e( 'Live', 'wsp-mcp-ai-agents-connector' ); ?></span>
							<?php elseif ( $enabled ) : ?>
								<span class="wsp-ctx-state wsp-ctx-state--off"><?php esc_html_e( 'On — add content', 'wsp-mcp-ai-agents-connector' ); ?></span>
							<?php else : ?>
								<span class="wsp-ctx-state wsp-ctx-state--off"><?php esc_html_e( 'Off', 'wsp-mcp-ai-agents-connector' ); ?></span>
							<?php endif; ?>
							<label class="wsp-sw">
								<input type="checkbox" name="wsp_context_enabled" value="1" <?php checked( $enabled ); ?>>
								<span class="wsp-sl"></span>
							</label>
						</div>
					</div>

					<?php
					$docs = array(
						array(
							'id'          => 'agents',
							'file'        => 'AGENTS.md',
							'name'        => 'wsp_context_agents',
							'title'       => __( 'AGENTS.md — how this site works', 'wsp-mcp-ai-agents-connector' ),
							'value'       => $agents,
							'placeholder' => $agents_placeholder,
						),
						array(
							'id'          => 'changelog',
							'file'        => 'CHANGELOG.md',
							'name'        => 'wsp_context_changelog',
							'title'       => __( 'CHANGELOG.md — what changed and why (newest first)', 'wsp-mcp-ai-agents-connector' ),
							'value'       => $changelog,
							'placeholder' => $changelog_placeholder,
						),
					);
					foreach ( $docs as $doc ) :
						?>
						<div class="wsp-panel">
							<div class="wsp-panel-h">
								<span><?php echo esc_html( $doc['title'] ); ?></span>
								<label class="wsp-ctx-file">
									<?php esc_html_e( 'Load from file:', 'wsp-mcp-ai-agents-connector' ); ?>
									<input type="file" accept=".md,.markdown,.txt,text/markdown,text/plain" class="wsp-ctx-load" data-target="wsp-ctx-<?php echo esc_attr( $doc['id'] ); ?>">
								</label>
							</div>
							<div class="wsp-panel-body">
								<textarea id="wsp-ctx-<?php echo esc_attr( $doc['id'] ); ?>" name="<?php echo esc_attr( $doc['name'] ); ?>" class="wsp-ctx-area" maxlength="<?php echo esc_attr( $max ); ?>" placeholder="<?php echo esc_attr( $doc['placeholder'] ); ?>" spellcheck="false"><?php echo esc_textarea( $doc['value'] ); ?></textarea>
								<div class="wsp-ctx-meta">
									<span><span class="wsp-ctx-count" data-for="wsp-ctx-<?php echo esc_attr( $doc['id'] ); ?>">0</span> / <?php echo esc_html( number_format_i18n( $max ) ); ?> <?php esc_html_e( 'characters', 'wsp-mcp-ai-agents-connector' ); ?> · ≈ <span class="wsp-ctx-tokens" data-for="wsp-ctx-<?php echo esc_attr( $doc['id'] ); ?>">0</span> <?php esc_html_e( 'tokens', 'wsp-mcp-ai-agents-connector' ); ?></span>
									<span><?php esc_html_e( 'Plain Markdown. Never put passwords or API keys here.', 'wsp-mcp-ai-agents-connector' ); ?></span>
								</div>
							</div>
						</div>
					<?php endforeach; ?>

					<div class="wsp-savebar">
						<?php submit_button( __( 'Save Context', 'wsp-mcp-ai-agents-connector' ), 'primary', 'submit', false ); ?>
						<span class="wsp-savenote"><?php esc_html_e( 'Agents already connected keep the old text until they reconnect.', 'wsp-mcp-ai-agents-connector' ); ?></span>
					</div>
				</form>

				<div class="wsp-panel" style="margin-top:16px">
					<div class="wsp-panel-h"><?php esc_html_e( 'What the agent receives', 'wsp-mcp-ai-agents-connector' ); ?></div>
					<div class="wsp-panel-body">
						<ol class="wsp-ctx-flow">
							<li><?php
								printf(
									/* translators: 1: AGENTS.md character limit, 2: changelog character limit */
									esc_html__( 'On connect, the MCP server sends the start of AGENTS.md (first %1$s characters) and the newest CHANGELOG.md entries (first %2$s characters) as server instructions. Most clients put this straight into the model\'s context.', 'wsp-mcp-ai-agents-connector' ),
									esc_html( number_format_i18n( WSP_MCP_CONTEXT_AGENTS_PUSH_CHARS ) ),
									esc_html( number_format_i18n( WSP_MCP_CONTEXT_CHANGELOG_PUSH_CHARS ) )
								);
							?></li>
							<li><?php esc_html_e( 'The full, uncapped text is available from the always-on tool wsp_get_site_context.', 'wsp-mcp-ai-agents-connector' ); ?></li>
							<li><?php esc_html_e( 'Both files are also exposed as MCP resources for clients that support them.', 'wsp-mcp-ai-agents-connector' ); ?></li>
						</ol>
						<p style="margin-top:10px"><?php esc_html_e( 'Let your AI keep these files up to date: enable "Update Site Context" in MCP > Settings (Site group). The tool wsp_update_site_context replaces, appends to or prepends to either file, and accepts large files in chunks. Administrators only.', 'wsp-mcp-ai-agents-connector' ); ?></p>
						<p style="margin-top:10px"><?php esc_html_e( 'Keep the top of each file the most important: put the newest changelog entries first.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					</div>
				</div>
			</div>
			<?php wsp_mcp_render_promo_cards( 'context_page' ); ?>
		</div>
	</div>
	<script>
	(function () {
		function refresh(area) {
			var n = area.value.length;
			document.querySelectorAll('[data-for="' + area.id + '"]').forEach(function (el) {
				el.textContent = el.classList.contains('wsp-ctx-tokens') ? Math.ceil(n / 4) : n;
			});
		}
		document.querySelectorAll('.wsp-ctx-area').forEach(function (area) {
			refresh(area);
			area.addEventListener('input', function () { refresh(area); });
		});
		document.querySelectorAll('.wsp-ctx-load').forEach(function (input) {
			input.addEventListener('change', function () {
				var file = input.files && input.files[0];
				var area = document.getElementById(input.getAttribute('data-target'));
				if (!file || !area) { return; }
				var reader = new FileReader();
				reader.onload = function () {
					var text = String(reader.result);
					if (text.length > area.maxLength) {
						window.alert(<?php echo wp_json_encode( __( 'This file is longer than the limit and was cut off at the end. Shorten it, or split it and upload it through the wsp_update_site_context tool.', 'wsp-mcp-ai-agents-connector' ) ); ?>);
						text = text.slice(0, area.maxLength);
					}
					area.value = text;
					refresh(area);
				};
				reader.readAsText(file);
			});
		});
	})();
	</script>
	<?php
}
