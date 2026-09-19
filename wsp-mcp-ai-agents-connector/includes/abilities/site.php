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

function wsp_execute_set_debug_log( $input ) {
    if ( ! isset( $input['enabled'] ) ) return array( 'success' => false, 'error' => 'enabled (true|false) is required.' );
    $enabled = (bool) $input['enabled'];

    $config_path = ABSPATH . 'wp-config.php';
    if ( ! file_exists( $config_path ) ) $config_path = dirname( ABSPATH ) . '/wp-config.php';
    if ( ! file_exists( $config_path ) ) return array( 'success' => false, 'error' => 'wp-config.php not found.' );
    if ( ! is_writable( $config_path ) ) return array( 'success' => false, 'error' => 'wp-config.php is not writable.' );

    $backup_path = $config_path . '.wsp-backup-' . gmdate( 'YmdHis' );
    if ( false === copy( $config_path, $backup_path ) ) {
        return array( 'success' => false, 'error' => 'Could not create a backup before editing wp-config.php; aborting.' );
    }

    $content = file_get_contents( $config_path );
    $values  = $enabled
        ? array( 'WP_DEBUG' => 'true', 'WP_DEBUG_LOG' => 'true', 'WP_DEBUG_DISPLAY' => 'false' )
        : array( 'WP_DEBUG' => 'false', 'WP_DEBUG_LOG' => 'false' );

    $changed = array();
    foreach ( $values as $name => $value ) {
        $pattern = '/define\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]\s*,\s*[^)]*\)\s*;/';
        $line    = "define( '{$name}', {$value} );";
        if ( preg_match( $pattern, $content ) ) {
            $content = preg_replace( $pattern, $line, $content, 1 );
        } else {
            $marker = "/* That's all, stop editing! Happy publishing. */";
            $content = ( false !== strpos( $content, $marker ) )
                ? str_replace( $marker, $line . "\n\n" . $marker, $content )
                : $content . "\n" . $line . "\n";
        }
        $changed[] = $name;
    }

    if ( false === file_put_contents( $config_path, $content ) ) {
        return array( 'success' => false, 'error' => 'Failed to write wp-config.php. A backup was saved at ' . $backup_path );
    }

    return array( 'success' => true, 'enabled' => $enabled, 'changed' => $changed, 'backup' => $backup_path );
}
