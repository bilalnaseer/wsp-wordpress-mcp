<?php
/**
 * Sidebar promo cards shared by the Abilities and Connection admin pages.
 *
 * Both pages render the same two cards in a right-hand column; only the UTM
 * campaign differs so clicks can be attributed to the originating screen.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Append UTM campaign parameters to an outbound wspmcp.com link so
 * clicks from the plugin admin are attributable in Google Analytics.
 *
 * @param string $url      Destination URL.
 * @param string $content  utm_content value identifying which card was clicked.
 * @param string $campaign utm_campaign value identifying the admin screen.
 * @return string URL with UTM parameters appended.
 */
function wsp_mcp_promo_url( $url, $content, $campaign ) {
    return add_query_arg(
        array(
            'utm_source'   => 'wsp_mcp_plugin',
            'utm_medium'   => 'plugin_admin',
            'utm_campaign' => $campaign,
            'utm_content'  => $content,
            'utm_term'     => WSP_MCP_VERSION,
        ),
        $url
    );
}

/**
 * CSS for the two-column layout and the promo cards.
 *
 * Appended to each page's own inline stylesheet so the class names stay
 * defined wherever the cards are rendered.
 *
 * @return string
 */
function wsp_mcp_promo_css() {
    return '
        /* Two columns on wide screens; the promo cards stack under the content below 1100px. */
        .wsp-layout{display:flex;align-items:flex-start;gap:20px}
        .wsp-main{flex:1;min-width:0;max-width:860px}
        .wsp-side{width:280px;flex-shrink:0;position:sticky;top:52px}
        @media screen and (max-width:1100px){
            .wsp-layout{display:block}
            .wsp-side{width:auto;max-width:860px;position:static;margin-top:20px}
        }
        .wsp-promo{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .wsp-promo+.wsp-promo{margin-top:16px}
        .wsp-promo-icon{font-size:26px;line-height:1;margin-bottom:10px}
        .wsp-promo h2{margin:0 0 8px;font-size:15px;font-weight:700;color:#1d2327}
        .wsp-promo p{margin:0 0 14px;font-size:13px;line-height:1.6;color:#646970}
        .wsp-promo .button{width:100%;text-align:center}
    ';
}

/**
 * Render the sidebar column containing the promo cards.
 *
 * @param string $campaign utm_campaign value for this screen.
 */
function wsp_mcp_render_promo_cards( $campaign ) {
    ?>
    <div class="wsp-side">
        <div class="wsp-promo">
            <div class="wsp-promo-icon">&#127916;</div>
            <h2><?php esc_html_e( 'Video Tutorials', 'wsp-mcp-ai-agents-connector' ); ?></h2>
            <p><?php esc_html_e( 'Check out our video tutorials for connecting your WordPress site with popular AI agents.', 'wsp-mcp-ai-agents-connector' ); ?></p>
            <a class="button button-secondary"
                href="<?php echo esc_url( wsp_mcp_promo_url( 'https://wspmcp.com/tutorials', 'tutorials_card', $campaign ) ); ?>"
                target="_blank"
                rel="noopener"><?php esc_html_e( 'Watch Tutorials', 'wsp-mcp-ai-agents-connector' ); ?></a>
        </div>

        <div class="wsp-promo">
            <div class="wsp-promo-icon">&#129520;</div>
            <h2><?php esc_html_e( '200+ Tools Available', 'wsp-mcp-ai-agents-connector' ); ?></h2>
            <p><?php esc_html_e( 'This plugin ships over 200 MCP tools across WordPress core and popular plugins. Browse the full list in our abilities directory.', 'wsp-mcp-ai-agents-connector' ); ?></p>
            <a class="button button-secondary"
                href="<?php echo esc_url( wsp_mcp_promo_url( 'https://wspmcp.com/abilities-directory', 'directory_card', $campaign ) ); ?>"
                target="_blank"
                rel="noopener"><?php esc_html_e( 'Browse All Tools', 'wsp-mcp-ai-agents-connector' ); ?></a>
        </div>
    </div>
    <?php
}
