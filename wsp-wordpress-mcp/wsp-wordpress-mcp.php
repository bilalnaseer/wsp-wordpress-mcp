<?php
/**
 * Plugin Name: WebSensePro MCP Abilities
 * Description: Exposes WordPress content to Claude AI via MCP with full read/write control. Manage all abilities from Settings > MCP.
 * Version: 1.2.1
 * Requires at least: 6.9
 * Requires PHP: 7.2
 * Author: WebSensePro
 * Author URI: https://websensepro.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSP_MCP_VERSION', '1.2.1' );
define( 'WSP_MCP_OPTION', 'wsp_mcp_abilities' );
define( 'WSP_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once WSP_MCP_DIR . 'includes/registry.php';
require_once WSP_MCP_DIR . 'includes/admin/settings-page.php';
require_once WSP_MCP_DIR . 'includes/admin/config-page.php';
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

add_action( 'admin_menu',                       'wsp_mcp_add_menu' );
add_action( 'admin_init',                       'wsp_mcp_register_settings' );
add_action( 'wp_abilities_api_categories_init', 'wsp_register_ability_category' );
add_action( 'wp_abilities_api_init',            'wsp_mcp_register_all_abilities' );

function wsp_mcp_register_all_abilities() {
    wsp_register_posts_abilities();
    wsp_register_pages_abilities();
    wsp_register_taxonomy_abilities();
    wsp_register_comments_abilities();
    wsp_register_media_abilities();
    wsp_register_users_abilities();
    wsp_register_search_abilities();
    wsp_register_site_abilities();
    wsp_register_yoast_abilities();
    wsp_register_elementor_abilities();
}
