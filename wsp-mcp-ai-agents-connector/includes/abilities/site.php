<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

function wsp_execute_get_plugins( $input ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all    = get_plugins();
    $active = get_option( 'active_plugins', array() );
    $result = array();
    foreach ( $active as $file ) {
        if ( isset( $all[ $file ] ) ) {
            $result[] = array(
                'name'    => $all[ $file ]['Name'],
                'version' => $all[ $file ]['Version'],
                'author'  => $all[ $file ]['Author'],
                'file'    => $file,
            );
        }
    }
    return array( 'active_plugins' => $result, 'total' => count( $result ) );
}

function wsp_execute_get_debug_log( $input ) {
    $path = WP_CONTENT_DIR . '/debug.log';
    if ( ! file_exists( $path ) ) return array( 'success' => false, 'error' => 'debug.log not found. Enable WP_DEBUG_LOG in wp-config.php to start logging.' );

    $lines  = isset( $input['lines'] ) ? max( 1, min( 500, intval( $input['lines'] ) ) ) : 100;
    $search = isset( $input['search'] ) ? sanitize_text_field( wp_unslash( $input['search'] ) ) : '';

    // Tail the file without loading huge logs fully into memory.
    $size   = filesize( $path );
    $chunk  = min( $size, 2 * 1024 * 1024 ); // last 2MB is plenty to find `$lines` of recent entries
    $handle = fopen( $path, 'r' );
    fseek( $handle, -$chunk, SEEK_END );
    $content = fread( $handle, $chunk );
    fclose( $handle );

    $all = preg_split( '/\r\n|\r|\n/', trim( $content ) );
    if ( '' !== $search ) {
        $all = array_values( array_filter( $all, function( $line ) use ( $search ) {
            return false !== stripos( $line, $search );
        } ) );
    }

    $tail = array_slice( $all, -$lines );
    return array( 'success' => true, 'lines' => $tail, 'total_matched' => count( $all ), 'truncated' => $size > $chunk );
}
