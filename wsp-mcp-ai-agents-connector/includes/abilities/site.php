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

/**
 * Installs a plugin from the WordPress.org repository by slug. Repository-only
 * by design (no arbitrary URL/zip upload) so this can't be used to sideload
 * unvetted code onto the site.
 */
function wsp_execute_install_plugin( $input ) {
    if ( empty( $input['slug'] ) ) {
        return array( 'success' => false, 'error' => 'slug is required.' );
    }
    $slug = sanitize_key( $input['slug'] );

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

    $api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
    if ( is_wp_error( $api ) ) {
        return array( 'success' => false, 'error' => 'Plugin not found on WordPress.org: ' . $api->get_error_message() );
    }

    foreach ( get_plugins() as $file => $data ) {
        if ( $file === $slug . '.php' || 0 === strpos( $file, $slug . '/' ) ) {
            $out = array( 'success' => true, 'already_installed' => true, 'file' => $file, 'name' => $data['Name'], 'version' => $data['Version'] );
            if ( ! empty( $input['activate'] ) && is_plugin_inactive( $file ) ) {
                $activated        = activate_plugin( $file );
                $out['activated'] = ! is_wp_error( $activated );
            }
            return $out;
        }
    }

    if ( ! class_exists( 'WSP_MCP_Silent_Upgrader_Skin' ) ) {
        class WSP_MCP_Silent_Upgrader_Skin extends WP_Upgrader_Skin {
            public function header() {}
            public function footer() {}
            public function feedback( $feedback, ...$args ) {}
        }
    }

    ob_start();
    $upgrader = new Plugin_Upgrader( new WSP_MCP_Silent_Upgrader_Skin() );
    $ok       = $upgrader->install( $api->download_link );
    ob_end_clean();

    if ( is_wp_error( $ok ) ) {
        return array( 'success' => false, 'error' => $ok->get_error_message() );
    }
    if ( ! $ok ) {
        return array( 'success' => false, 'error' => 'Installation failed (filesystem access or permissions issue).' );
    }

    $file = $upgrader->plugin_info();
    if ( ! $file ) {
        return array( 'success' => false, 'error' => 'Installed but could not determine the plugin file.' );
    }

    $out = array( 'success' => true, 'file' => $file, 'name' => $api->name, 'version' => $api->version );
    if ( ! empty( $input['activate'] ) ) {
        $activated = activate_plugin( $file );
        $out['activated'] = ! is_wp_error( $activated );
        if ( is_wp_error( $activated ) ) {
            $out['activate_error'] = $activated->get_error_message();
        }
    }
    return $out;
}
