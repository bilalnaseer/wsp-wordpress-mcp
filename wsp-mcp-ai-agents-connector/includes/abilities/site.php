<?php
/**
 * Site abilities: site information, plugins, site settings, permalink structure and Site Context.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns site name, URL, tagline, WP version, and language.
 *
 * MCP tool: `wsp_get_site_info`. Any authenticated user may call it.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_site_info( $input ) {
    return array(
        'name'        => get_bloginfo( 'name' ),
        'url'         => get_site_url(),
        'tagline'     => get_bloginfo( 'description' ),
        'admin_email' => get_option( 'admin_email' ),
        'wp_version'  => get_bloginfo( 'version' ),
        'language'    => get_bloginfo( 'language' ),
    );
}

/**
 * Lists installed plugins with name, version, author, active status and update availability
 * (active_plugins = active only; plugins = all).
 *
 * MCP tool: `wsp_get_plugins`. Requires the `activate_plugins` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_plugins( $input ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all     = get_plugins();
    $updates = get_site_transient( 'update_plugins' );
    $result  = array();
    $active  = array();
    foreach ( $all as $file => $info ) {
        $is_active = is_plugin_active( $file ) || is_plugin_active_for_network( $file );
        $new       = ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) ? $updates->response[ $file ] : null;
        $row = array(
            'name'             => $info['Name'],
            'version'          => $info['Version'],
            'author'           => $info['Author'],
            'file'             => $file,
            'active'           => $is_active,
            'update_available' => (bool) $new,
            'new_version'      => $new && isset( $new->new_version ) ? $new->new_version : null,
        );
        $result[] = $row;
        if ( $is_active ) $active[] = $row;
    }
    return array( 'active_plugins' => $active, 'plugins' => $result, 'total' => count( $active ), 'total_installed' => count( $result ) );
}

/**
 * Update the site title, tagline, and/or admin email.
 *
 * MCP tool: `wsp_update_site_info`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $name Optional.
 *     @type string $tagline Optional.
 *     @type string $admin_email Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_update_site_info( $input ) {
    if ( isset( $input['name'] ) )    update_option( 'blogname', sanitize_text_field( wp_unslash( $input['name'] ) ) );
    if ( isset( $input['tagline'] ) ) update_option( 'blogdescription', sanitize_text_field( wp_unslash( $input['tagline'] ) ) );
    if ( isset( $input['admin_email'] ) ) {
        $email = sanitize_email( wp_unslash( $input['admin_email'] ) );
        if ( ! is_email( $email ) ) return array( 'success' => false, 'error' => 'Invalid email address.' );
        update_option( 'admin_email', $email );
    }
    return array( 'success' => true );
}

/**
 * Change the site permalink structure.
 *
 * MCP tool: `wsp_update_permalink_structure`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $structure Required. e.g. /%postname%/, or empty string for plain.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_update_permalink_structure( $input ) {
    if ( ! isset( $input['structure'] ) ) return array( 'success' => false, 'error' => 'structure is required.' );
    $structure = sanitize_text_field( wp_unslash( $input['structure'] ) );
    update_option( 'permalink_structure', $structure );
    flush_rewrite_rules();
    return array( 'success' => true, 'structure' => $structure );
}

/**
 * Activate an installed plugin by file path.
 *
 * MCP tool: `wsp_activate_plugin`. Requires the `activate_plugins` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $file Required. Plugin file path, e.g. akismet/akismet.php.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_activate_plugin( $input ) {
    if ( empty( $input['file'] ) ) return array( 'success' => false, 'error' => 'file is required.' );
    $file = sanitize_text_field( wp_unslash( $input['file'] ) );
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! isset( get_plugins()[ $file ] ) ) return array( 'success' => false, 'error' => 'Plugin not found: ' . $file );
    $result = activate_plugin( $file );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'file' => $file );
}

/**
 * Deactivate an active plugin by file path.
 *
 * MCP tool: `wsp_deactivate_plugin`. Requires the `activate_plugins` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $file Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_deactivate_plugin( $input ) {
    if ( empty( $input['file'] ) ) return array( 'success' => false, 'error' => 'file is required.' );
    $file = sanitize_text_field( wp_unslash( $input['file'] ) );
    if ( plugin_basename( WSP_MCP_DIR . 'wsp-mcp-ai-agents-connector.php' ) === $file ) {
        return array( 'success' => false, 'error' => 'Refusing to deactivate this MCP plugin itself.' );
    }
    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    deactivate_plugins( $file );
    return array( 'success' => true, 'file' => $file );
}
