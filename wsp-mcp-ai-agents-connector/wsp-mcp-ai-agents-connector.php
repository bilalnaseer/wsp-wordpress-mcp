<?php
/**
 * Plugin Name: WSP MCP - AI Agents Connector
 * Description: Exposes WordPress content to Claude AI and other AI Agents via a built-in MCP server (no companion plugin required). Manage all abilities from Settings > MCP.
 * Version: 2.3.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: WebSensePro
 * Author URI: https://websensepro.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wsp-mcp-ai-agents-connector
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSP_MCP_VERSION', '2.3.0' );
define( 'WSP_MCP_OPTION', 'wsp_mcp_abilities' );
define( 'WSP_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once WSP_MCP_DIR . 'includes/dependency.php';
require_once WSP_MCP_DIR . 'includes/registry.php';
require_once WSP_MCP_DIR . 'includes/admin/settings-page.php';
require_once WSP_MCP_DIR . 'includes/admin/connection-page.php';
// Native MCP server (v2.0).
require_once WSP_MCP_DIR . 'includes/server/class-session-store.php';
require_once WSP_MCP_DIR . 'includes/server/class-auth.php';
require_once WSP_MCP_DIR . 'includes/server/class-mcp-server.php';
require_once WSP_MCP_DIR . 'includes/tools/native-tools.php';
require_once WSP_MCP_DIR . 'includes/abilities/posts.php';
require_once WSP_MCP_DIR . 'includes/abilities/pages.php';
require_once WSP_MCP_DIR . 'includes/abilities/taxonomy.php';
require_once WSP_MCP_DIR . 'includes/abilities/comments.php';
require_once WSP_MCP_DIR . 'includes/abilities/media.php';
require_once WSP_MCP_DIR . 'includes/abilities/users.php';
require_once WSP_MCP_DIR . 'includes/abilities/search.php';
require_once WSP_MCP_DIR . 'includes/abilities/site.php';
require_once WSP_MCP_DIR . 'includes/abilities/yoast.php';
require_once WSP_MCP_DIR . 'includes/abilities/elementor.php';
require_once WSP_MCP_DIR . 'includes/abilities/woocommerce.php';
require_once WSP_MCP_DIR . 'includes/abilities/acf.php'; // Included ACF Pro Abilities

add_action( 'admin_menu',                       'wsp_mcp_add_menu' );
add_action( 'admin_init',                       'wsp_mcp_register_settings' );

// Native MCP server (v2.0) — booted late so Elementor/Yoast/ACF classes are loaded
// before the tool registry is built. Registers its own REST endpoint.
add_action( 'plugins_loaded', array( 'WSP_MCP_Server', 'init' ) );
add_action( 'plugins_loaded', 'wsp_mcp_maybe_upgrade_db' );
add_action( 'wsp_mcp_session_cleanup', array( 'WSP_MCP_Session_Store', 'cleanup_expired' ) );

register_activation_hook( __FILE__, 'wsp_mcp_activate' );
register_deactivation_hook( __FILE__, 'wsp_mcp_deactivate' );

/** Activation: create tables, ensure an API key, schedule cleanup. */
function wsp_mcp_activate() {
    WSP_MCP_Session_Store::create_table();
    WSP_MCP_Auth::get_api_key();
    if ( ! wp_next_scheduled( 'wsp_mcp_session_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'wsp_mcp_session_cleanup' );
    }
    update_option( 'wsp_mcp_db_version', WSP_MCP_VERSION, false );
}

/** Deactivation: unschedule cleanup. Data is preserved for reactivation. */
function wsp_mcp_deactivate() {
    wp_clear_scheduled_hook( 'wsp_mcp_session_cleanup' );
}

/**
 * Heal installs upgraded via the Plugins screen, where the activation hook
 * never fires. Creates the sessions table on first load after an update.
 */
function wsp_mcp_maybe_upgrade_db() {
    if ( get_option( 'wsp_mcp_db_version' ) === WSP_MCP_VERSION ) {
        return;
    }
    WSP_MCP_Session_Store::create_table();
    if ( ! wp_next_scheduled( 'wsp_mcp_session_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'wsp_mcp_session_cleanup' );
    }
    update_option( 'wsp_mcp_db_version', WSP_MCP_VERSION, false );
}
