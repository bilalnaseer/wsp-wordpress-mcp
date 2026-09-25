<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

function wsp_execute_switch_theme( $input ) {
    if ( empty( $input['theme'] ) ) return array( 'success' => false, 'error' => 'theme is required.' );
    $slug   = sanitize_text_field( wp_unslash( $input['theme'] ) );
    $themes = wp_get_themes();
    if ( ! isset( $themes[ $slug ] ) ) return array( 'success' => false, 'error' => 'Theme not found: ' . $slug );
    switch_theme( $slug );
    return array( 'success' => true, 'theme' => $slug );
}
