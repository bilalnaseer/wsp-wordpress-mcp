<?php
/**
 * MCP Connection admin page (Milestone M6).
 *
 * Surfaces the native MCP endpoint URL and API key, with per-client copy-paste
 * connection snippets (Claude Desktop, Cursor, Codex, Antigravity, OpenClaw) for
 * the native v2.0 server — no companion plugin or MCP Adapter required. Handles
 * API-key regeneration via an admin-post action.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Register the Connection submenu under the MCP top-level menu. */
function wsp_mcp_add_connection_menu() {
	$page_hook = add_submenu_page(
		'wsp-mcp-abilities',
		'Connection',
		'Connection',
		'manage_options',
		'wsp-mcp-connection',
		'wsp_mcp_connection_page'
	);

	// Safely enqueue scripts and styles only on this specific page
	add_action( 'load-' . $page_hook, 'wsp_mcp_enqueue_connection_assets' );
}
add_action( 'admin_menu', 'wsp_mcp_add_connection_menu', 20 );

/** Enqueue assets for the Connection page contextually. */
function wsp_mcp_enqueue_connection_assets() {
	add_action( 'admin_enqueue_scripts', function() {
		$custom_css = '
			.wsp-wrap{max-width:860px;margin:24px 20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
			.wsp-header h1{margin:0 0 6px;font-size:22px;font-weight:700;color:#1d2327}
			.wsp-desc{color:#646970;margin:0 0 20px;font-size:13.5px;line-height:1.65}
			.wsp-facts{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:4px 20px;margin-bottom:24px}
			.wsp-facts table{width:100%;border-collapse:collapse}
			.wsp-facts th{text-align:left;padding:12px 0;width:120px;color:#1d2327;font-size:13.5px;vertical-align:top}
			.wsp-facts td{padding:12px 0;font-size:13.5px}
			.wsp-facts code{background:#f0f0f1;padding:3px 8px;border-radius:4px;font-size:12.5px;color:#1d2327;font-family:Consolas,Monaco,monospace;word-break:break-all}
			.wsp-tabs{display:flex;gap:0;border-bottom:2px solid #dcdcde;flex-wrap:wrap}
			.wsp-tab-btn{background:none;border:none;padding:10px 20px;font-size:14px;font-weight:600;color:#787c82;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s}
			.wsp-tab-btn:hover{color:#1d2327}
			.wsp-tab-btn.wsp-tab-active{color:#0073aa;border-bottom-color:#0073aa}
			.wsp-tab-panel{display:none}
			.wsp-tab-panel.wsp-tab-panel-active{display:block}
			.wsp-config-box{background:#fff;border:1px solid #dcdcde;border-radius:0 0 8px 8px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.wsp-instructions{padding:18px 20px;border-bottom:1px solid #f0f0f1;background:#fff}
			.wsp-instructions p{margin:0 0 9px;color:#3c434a;font-size:13.5px;line-height:1.6}
			.wsp-instructions p:last-child{margin:0}
			.wsp-instructions code{background:#f0f0f1;padding:3px 6px;border-radius:4px;font-size:12.5px;color:#d63638;font-family:monospace}
			.wsp-config-header{background:#f6f7f7;padding:11px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #dcdcde}
			.wsp-config-title{font-weight:700;font-size:13px;color:#1d2327;margin:0;font-family:Consolas,Monaco,monospace}
			.wsp-copy-btn{font-size:12px;color:#0073aa;cursor:pointer;background:none;border:none;padding:0;font-weight:600;display:flex;align-items:center;gap:4px;transition:color .2s}
			.wsp-copy-btn:hover{color:#00a32a}
			.wsp-code-area{background:#1e1e1e;color:#d4d4d4;padding:20px;margin:0;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;overflow-x:auto;white-space:pre}
			.wsp-badge{display:inline-block;background:#edf6ff;color:#0073aa;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;margin-left:6px;vertical-align:middle}
			.wsp-badge-node{background:#fff4e5;color:#996800}
		';
		wp_add_inline_style( 'common', $custom_css );

		$custom_js = '
			document.addEventListener("DOMContentLoaded", function() {
				document.querySelectorAll(".wsp-tab-btn").forEach(function(btn) {
					btn.addEventListener("click", function() {
						document.querySelectorAll(".wsp-tab-btn").forEach(function(b){ b.classList.remove("wsp-tab-active"); });
						document.querySelectorAll(".wsp-tab-panel").forEach(function(p){ p.classList.remove("wsp-tab-panel-active"); });
						btn.classList.add("wsp-tab-active");
						document.getElementById("wsp-tab-" + btn.dataset.tab).classList.add("wsp-tab-panel-active");
					});
				});

				function makeCopyBtn(btnId, codeId) {
					var btn  = document.getElementById(btnId);
					var code = document.getElementById(codeId);
					if (!btn || !code) return;
					btn.addEventListener("click", function() {
						navigator.clipboard.writeText(code.innerText).then(function() {
							var orig = btn.innerHTML;
							btn.innerHTML = \'<span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span> Copied!\';
							btn.style.color = "#00a32a";
							setTimeout(function(){ btn.innerHTML = orig; btn.style.color = ""; }, 2500);
						}).catch(function(){ alert("Failed to copy. Please select and copy manually."); });
					});
				}
				makeCopyBtn("wsp-copy-claude",      "wsp-code-claude");
				makeCopyBtn("wsp-copy-cursor",      "wsp-code-cursor");
				makeCopyBtn("wsp-copy-codex",       "wsp-code-codex");
				makeCopyBtn("wsp-copy-antigravity", "wsp-code-antigravity");
				makeCopyBtn("wsp-copy-openclaw",    "wsp-code-openclaw");
			});
		';
		wp_add_inline_script( 'common', $custom_js );
	} );
}

/** Handle API-key regeneration. */
function wsp_mcp_handle_regenerate_key() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wsp-mcp-ai-agents-connector' ) );
	}
	check_admin_referer( 'wsp_mcp_regenerate_key' );
	WSP_MCP_Auth::regenerate_api_key();
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'wsp-mcp-connection', 'wsp_key_regenerated' => '1' ),
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_post_wsp_mcp_regenerate_key', 'wsp_mcp_handle_regenerate_key' );

/** Render the Connection page. */
function wsp_mcp_connection_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$endpoint = esc_url_raw( rest_url( 'wsp-mcp/v1/mcp' ) );
	$api_key  = WSP_MCP_Auth::get_api_key();
	$conn     = 'wsp-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
	$bearer   = 'Authorization: Bearer ' . $api_key;

	// --- Build per-client snippets (API key embedded directly in the header). ---

	// Claude Desktop: stdio only -> mcp-remote bridge (requires Node.js).
	$claude_json = wp_json_encode(
		array(
			'mcpServers' => array(
				$conn => array(
					'command' => 'npx',
					'args'    => array( '-y', 'mcp-remote', $endpoint, '--header', $bearer ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);

	// Cursor: native remote HTTP via url + headers (no Node.js).
	$cursor_json = wp_json_encode(
		array(
			'mcpServers' => array(
				$conn => array(
					'url'     => $endpoint,
					'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);

	// Codex: native streamable HTTP via url + http_headers (TOML).
	$codex_toml = "[mcp_servers.{$conn}]\n"
		. "url = \"{$endpoint}\"\n"
		. "http_headers = { \"Authorization\" = \"Bearer {$api_key}\" }";

	// Antigravity (Gemini): native remote HTTP — note the key is `serverUrl`, not `url`.
	$antigravity_json = wp_json_encode(
		array(
			'mcpServers' => array(
				$conn => array(
					'serverUrl' => $endpoint,
					'headers'   => array( 'Authorization' => 'Bearer ' . $api_key ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);

	// OpenClaw: nested mcp.servers schema + mcp-remote bridge (requires Node.js).
	$openclaw_json = "\"mcp\": {\n"
		. "    \"servers\": {\n"
		. "        \"{$conn}\": {\n"
		. "            \"command\": \"npx\",\n"
		. "            \"args\": [\n"
		. "                \"-y\",\n"
		. "                \"mcp-remote\",\n"
		. "                \"{$endpoint}\",\n"
		. "                \"--header\",\n"
		. "                \"Authorization: Bearer {$api_key}\"\n"
		. "            ]\n"
		. "        }\n"
		. "    }\n"
		. "},";
	?>
	<div class="wsp-wrap">
		<div class="wsp-header">
			<h1><?php esc_html_e( 'MCP Connection', 'wsp-mcp-ai-agents-connector' ); ?></h1>
		</div>
		<p class="wsp-desc">
			<?php esc_html_e( 'Connect any MCP-capable AI client directly to this site. No companion plugin or WordPress MCP Adapter is required — this plugin serves MCP natively. The API key below is already embedded in each snippet.', 'wsp-mcp-ai-agents-connector' ); ?>
		</p>

		<?php if ( isset( $_GET['wsp_key_regenerated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'API key regenerated. Re-copy the snippet into each connected client.', 'wsp-mcp-ai-agents-connector' ); ?>
			</p></div>
		<?php endif; ?>

		<div class="wsp-facts">
			<table role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Endpoint URL', 'wsp-mcp-ai-agents-connector' ); ?></th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'wsp-mcp-ai-agents-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( $api_key ); ?></code>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:8px;">
							<input type="hidden" name="action" value="wsp_mcp_regenerate_key" />
							<?php wp_nonce_field( 'wsp_mcp_regenerate_key' ); ?>
							<button type="submit" class="button button-secondary"
								onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? All connected clients will need the new snippet.', 'wsp-mcp-ai-agents-connector' ) ); ?>');">
								<?php esc_html_e( 'Regenerate', 'wsp-mcp-ai-agents-connector' ); ?>
							</button>
						</form>
					</td>
				</tr>
			</table>
		</div>

		<div class="wsp-tabs">
			<button type="button" class="wsp-tab-btn wsp-tab-active" data-tab="claude">Claude Desktop</button>
			<button type="button" class="wsp-tab-btn" data-tab="cursor">Cursor</button>
			<button type="button" class="wsp-tab-btn" data-tab="codex">Codex</button>
			<button type="button" class="wsp-tab-btn" data-tab="antigravity">Antigravity</button>
			<button type="button" class="wsp-tab-btn" data-tab="openclaw">OpenClaw</button>
		</div>

		<!-- Claude Desktop -->
		<div class="wsp-tab-panel wsp-tab-panel-active" id="wsp-tab-claude">
			<div class="wsp-config-box">
				<div class="wsp-instructions">
					<p><span class="wsp-badge wsp-badge-node"><?php esc_html_e( 'Requires Node.js', 'wsp-mcp-ai-agents-connector' ); ?></span> <?php esc_html_e( 'Claude Desktop config files only support local (stdio) servers, so this uses the mcp-remote bridge to reach the HTTP endpoint.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>1. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <strong>Settings &gt; Developer &gt; Edit Config</strong>, <?php esc_html_e( 'or edit', 'wsp-mcp-ai-agents-connector' ); ?> <code>claude_desktop_config.json</code> <?php esc_html_e( 'directly.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>2. <?php esc_html_e( 'Paste the snippet below (merge into an existing', 'wsp-mcp-ai-agents-connector' ); ?> <code>mcpServers</code> <?php esc_html_e( 'block if you already have one).', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>3. <?php esc_html_e( 'Fully quit and reopen Claude Desktop so it re-reads the tool list.', 'wsp-mcp-ai-agents-connector' ); ?></p>
				</div>
				<div class="wsp-config-header">
					<span class="wsp-config-title">claude_desktop_config.json</span>
					<button type="button" class="wsp-copy-btn" id="wsp-copy-claude">
						<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Copy', 'wsp-mcp-ai-agents-connector' ); ?>
					</button>
				</div>
				<pre class="wsp-code-area" id="wsp-code-claude"><?php echo esc_html( $claude_json ); ?></pre>
			</div>
		</div>

		<!-- Cursor -->
		<div class="wsp-tab-panel" id="wsp-tab-cursor">
			<div class="wsp-config-box">
				<div class="wsp-instructions">
					<p><span class="wsp-badge"><?php esc_html_e( 'Direct HTTP', 'wsp-mcp-ai-agents-connector' ); ?></span> <?php esc_html_e( 'Cursor connects to the endpoint natively — no Node.js needed.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>1. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <code>~/.cursor/mcp.json</code> (<?php esc_html_e( 'global', 'wsp-mcp-ai-agents-connector' ); ?>) <?php esc_html_e( 'or', 'wsp-mcp-ai-agents-connector' ); ?> <code>.cursor/mcp.json</code> <?php esc_html_e( 'in your project root.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>2. <?php esc_html_e( 'Paste the snippet below (merge into an existing', 'wsp-mcp-ai-agents-connector' ); ?> <code>mcpServers</code> <?php esc_html_e( 'block if present).', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>3. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <strong>Settings &gt; MCP</strong> <?php esc_html_e( 'and confirm the server shows green.', 'wsp-mcp-ai-agents-connector' ); ?></p>
				</div>
				<div class="wsp-config-header">
					<span class="wsp-config-title">~/.cursor/mcp.json</span>
					<button type="button" class="wsp-copy-btn" id="wsp-copy-cursor">
						<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Copy', 'wsp-mcp-ai-agents-connector' ); ?>
					</button>
				</div>
				<pre class="wsp-code-area" id="wsp-code-cursor"><?php echo esc_html( $cursor_json ); ?></pre>
			</div>
		</div>

		<!-- Codex -->
		<div class="wsp-tab-panel" id="wsp-tab-codex">
			<div class="wsp-config-box">
				<div class="wsp-instructions">
					<p><span class="wsp-badge"><?php esc_html_e( 'Direct HTTP', 'wsp-mcp-ai-agents-connector' ); ?></span> <?php esc_html_e( 'Codex reaches the streamable-HTTP endpoint natively via url + http_headers.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>1. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <code>~/.codex/config.toml</code>.</p>
					<p>2. <?php esc_html_e( 'Append the block below.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>3. <?php esc_html_e( 'Restart Codex, then run', 'wsp-mcp-ai-agents-connector' ); ?> <code>/mcp</code> <?php esc_html_e( 'to verify the server is listed.', 'wsp-mcp-ai-agents-connector' ); ?></p>
				</div>
				<div class="wsp-config-header">
					<span class="wsp-config-title">~/.codex/config.toml</span>
					<button type="button" class="wsp-copy-btn" id="wsp-copy-codex">
						<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Copy', 'wsp-mcp-ai-agents-connector' ); ?>
					</button>
				</div>
				<pre class="wsp-code-area" id="wsp-code-codex"><?php echo esc_html( $codex_toml ); ?></pre>
			</div>
		</div>

		<!-- Antigravity -->
		<div class="wsp-tab-panel" id="wsp-tab-antigravity">
			<div class="wsp-config-box">
				<div class="wsp-instructions">
					<p><span class="wsp-badge"><?php esc_html_e( 'Direct HTTP', 'wsp-mcp-ai-agents-connector' ); ?></span> <?php esc_html_e( 'Antigravity uses serverUrl (not url) for remote HTTP servers.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>1. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <code>~/.gemini/config/mcp_config.json</code>, <?php esc_html_e( 'or use', 'wsp-mcp-ai-agents-connector' ); ?> <strong>Manage MCP Servers &gt; View raw config</strong>.</p>
					<p>2. <?php esc_html_e( 'Paste the snippet below (merge into an existing', 'wsp-mcp-ai-agents-connector' ); ?> <code>mcpServers</code> <?php esc_html_e( 'block if present).', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>3. <?php esc_html_e( 'Refresh the MCP server list in Antigravity.', 'wsp-mcp-ai-agents-connector' ); ?></p>
				</div>
				<div class="wsp-config-header">
					<span class="wsp-config-title">~/.gemini/config/mcp_config.json</span>
					<button type="button" class="wsp-copy-btn" id="wsp-copy-antigravity">
						<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Copy', 'wsp-mcp-ai-agents-connector' ); ?>
					</button>
				</div>
				<pre class="wsp-code-area" id="wsp-code-antigravity"><?php echo esc_html( $antigravity_json ); ?></pre>
			</div>
		</div>

		<!-- OpenClaw -->
		<div class="wsp-tab-panel" id="wsp-tab-openclaw">
			<div class="wsp-config-box">
				<div class="wsp-instructions">
					<p><span class="wsp-badge wsp-badge-node"><?php esc_html_e( 'Requires Node.js', 'wsp-mcp-ai-agents-connector' ); ?></span> <?php esc_html_e( 'OpenClaw uses the nested mcp.servers schema and reaches the endpoint via the mcp-remote bridge.', 'wsp-mcp-ai-agents-connector' ); ?></p>
					<p>1. <?php esc_html_e( 'Open', 'wsp-mcp-ai-agents-connector' ); ?> <code>~/.openclaw/openclaw.json</code> <?php esc_html_e( 'and paste the block below on the line right after the top-level opening', 'wsp-mcp-ai-agents-connector' ); ?> <code>{</code>.</p>
					<p>2. <?php esc_html_e( 'Save the file, then restart the gateway:', 'wsp-mcp-ai-agents-connector' ); ?> <code>openclaw gateway restart</code></p>
					<p>3. <?php esc_html_e( 'Verify:', 'wsp-mcp-ai-agents-connector' ); ?> <code>openclaw mcp status --verbose</code></p>
				</div>
				<div class="wsp-config-header">
					<span class="wsp-config-title">~/.openclaw/openclaw.json</span>
					<button type="button" class="wsp-copy-btn" id="wsp-copy-openclaw">
						<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;"></span> <?php esc_html_e( 'Copy', 'wsp-mcp-ai-agents-connector' ); ?>
					</button>
				</div>
				<pre class="wsp-code-area" id="wsp-code-openclaw"><?php echo esc_html( $openclaw_json ); ?></pre>
			</div>
		</div>

		<p class="wsp-desc" style="margin-top:18px;">
			<?php esc_html_e( 'Advanced: any client can also connect by sending an', 'wsp-mcp-ai-agents-connector' ); ?>
			<code style="background:#f0f0f1;padding:2px 5px;border-radius:4px;">Authorization: Bearer &lt;API Key&gt;</code>
			<?php esc_html_e( 'header to the endpoint URL, or a WordPress Application Password via HTTP Basic auth.', 'wsp-mcp-ai-agents-connector' ); ?>
		</p>
	</div>
	<?php
}
