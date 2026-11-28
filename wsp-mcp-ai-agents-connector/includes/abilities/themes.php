<?php
/**
 * Theme abilities: list installed themes and switch the active theme.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * List installed themes and which one is active.
 *
 * MCP tool: `wsp_get_themes`. Requires the `switch_themes` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_themes( $input ) {
    $themes = wp_get_themes();
    $active = get_stylesheet();
    $result = array();
    foreach ( $themes as $slug => $theme ) {
        $result[] = array(
            'slug'    => $slug,
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'active'  => $slug === $active,
        );
    }
    return array( 'themes' => $result );
}

/**
 * Activate a different installed theme.
 *
 * MCP tool: `wsp_switch_theme`. Requires the `switch_themes` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $theme Required. Theme stylesheet slug.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_switch_theme( $input ) {
    if ( empty( $input['theme'] ) ) return array( 'success' => false, 'error' => 'theme is required.' );
    $slug   = sanitize_text_field( wp_unslash( $input['theme'] ) );
    $themes = wp_get_themes();
    if ( ! isset( $themes[ $slug ] ) ) return array( 'success' => false, 'error' => 'Theme not found: ' . $slug );
    switch_theme( $slug );
    return array( 'success' => true, 'theme' => $slug );
}
