<?php
/**
 * Plugin management (v2.9.5): install (wordpress.org / https zip), delete, update.
 *
 * Mirrors theme-upload.php: installs go through core's Plugin_Upgrader with a
 * WP_Ajax_Upgrader_Skin (the wp-admin code path), so core does the validation.
 * Envelope: { success, data, error }. Plugin code is NOT sanitized — a plugin is PHP;
 * the safety boundary is the install_plugins capability + the OFF-by-default toggles.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_plugins_ok( $data = array() ) {
	return array( 'success' => true, 'data' => $data, 'error' => null );
}

function wsp_plugins_fail( $message ) {
	return array( 'success' => false, 'data' => null, 'error' => (string) $message );
}

function wsp_plugins_load_admin_includes() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

/** Capability + file-mod gate. Returns an error envelope or null. */
function wsp_plugins_guard( $cap ) {
	if ( ! current_user_can( $cap ) ) {
		return wsp_plugins_fail( "You do not have permission ({$cap}) to perform this action." );
	}
	if ( ! wp_is_file_mod_allowed( 'wsp_mcp_' . $cap ) ) {
		return wsp_plugins_fail( 'File modifications are disabled on this site (DISALLOW_FILE_MODS).' );
	}
	wsp_plugins_load_admin_includes();
	if ( ! WP_Filesystem() ) {
		return wsp_plugins_fail( 'WordPress cannot write to wp-content/plugins without FTP/SSH credentials. Define FS_METHOD (or the FTP_* constants) in wp-config.php.' );
	}
	return null;
}

/** Validate a plugin file path against installed plugins; returns the file or an error envelope. */
function wsp_plugins_resolve_file( $input ) {
	$file = isset( $input['file'] ) ? sanitize_text_field( wp_unslash( $input['file'] ) ) : '';
	if ( '' === $file || false !== strpos( $file, '..' ) ) {
		return wsp_plugins_fail( 'file is required (e.g. akismet/akismet.php).' );
	}
	if ( ! isset( get_plugins()[ $file ] ) ) {
		return wsp_plugins_fail( 'Plugin not found: ' . $file );
	}
	return $file;
}

function wsp_plugins_is_self( $file ) {
	return plugin_basename( WSP_MCP_DIR . 'wsp-mcp-ai-agents-connector.php' ) === $file;
}

/** Run an upgrader call with output buffered so nothing leaks into the JSON-RPC response. */
function wsp_plugins_run_upgrader( $callback ) {
	$level = ob_get_level();
	ob_start();
	try {
		$result = call_user_func( $callback );
	} finally {
		while ( ob_get_level() > $level ) ob_end_clean();
	}
	return $result;
}

/** Normalize the failure modes of an Upgrader run into an error string, or null on success. */
function wsp_plugins_upgrader_error( $result, $skin ) {
	if ( is_wp_error( $result ) ) return $result->get_error_message();
	if ( is_wp_error( $skin->result ) ) return $skin->result->get_error_message();
	if ( $skin->get_errors()->has_errors() ) return wp_strip_all_tags( $skin->get_error_messages() );
	if ( ! $result ) return 'The upgrader failed (unknown error).';
	return null;
}

/** Shared tail of both install tools: run the upgrader, optionally activate, report. */
function wsp_plugins_install_package( $package, $activate ) {
	if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
		return wsp_plugins_fail( 'activate=true needs the activate_plugins capability.' );
	}
	$before   = array_keys( get_plugins() );
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = wsp_plugins_run_upgrader( function () use ( $upgrader, $package ) {
		return $upgrader->install( $package );
	} );
	$err = wsp_plugins_upgrader_error( $result, $skin );
	if ( $err ) return wsp_plugins_fail( $err );

	wp_clean_plugins_cache( true );
	$file = $upgrader->plugin_info();
	if ( ! $file || ! isset( get_plugins()[ $file ] ) ) {
		return wsp_plugins_fail( 'The package was unpacked but WordPress could not find a valid plugin in it.' );
	}
	$info = get_plugins()[ $file ];
	$data = array(
		'file'      => $file,
		'name'      => $info['Name'],
		'version'   => $info['Version'],
		'installed' => ! in_array( $file, $before, true ),
		'activated' => false,
	);
	if ( $activate ) {
		$act = activate_plugin( $file );
		if ( is_wp_error( $act ) ) {
			$data['activation_error'] = $act->get_error_message();
		} else {
			$data['activated'] = true;
		}
	}
	return wsp_plugins_ok( $data );
}

function wsp_execute_install_plugin( $input ) {
	$e = wsp_plugins_guard( 'install_plugins' );
	if ( $e ) {
		return $e;
	}
	$slug = isset( $input['slug'] ) ? sanitize_title( wp_unslash( $input['slug'] ) ) : '';
	if ( '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $slug ) ) {
		return wsp_plugins_fail( 'slug is required: the wordpress.org plugin slug, e.g. "woocommerce".' );
	}
	// Already installed? (folder name == slug)
	foreach ( array_keys( get_plugins() ) as $f ) {
		if ( 0 === strpos( $f, $slug . '/' ) ) {
			return wsp_plugins_fail( "A plugin with slug '{$slug}' is already installed ({$f}). Use wsp_activate_plugin or wsp_update_plugin." );
		}
	}
	$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false, 'short_description' => false ) ) );
	if ( is_wp_error( $api ) ) return wsp_plugins_fail( 'wordpress.org lookup failed: ' . $api->get_error_message() );
	if ( empty( $api->download_link ) ) return wsp_plugins_fail( 'wordpress.org returned no download link for this plugin.' );
	return wsp_plugins_install_package( $api->download_link, wsp_woo_flag( $input, 'activate' ) );
}

function wsp_execute_install_plugin_from_url( $input ) {
	$e = wsp_plugins_guard( 'install_plugins' );
	if ( $e ) {
		return $e;
	}
	$raw = isset( $input['zip_url'] ) ? trim( (string) $input['zip_url'] ) : '';
	$url = esc_url_raw( $raw, array( 'https' ) );
	$parts = wp_parse_url( $url );
	if ( '' === $url || empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! wp_http_validate_url( $url ) ) {
		return wsp_plugins_fail( 'zip_url must be a public https:// URL to a plugin .zip file (no credentials in the URL).' );
	}
	return wsp_plugins_install_package( $url, wsp_woo_flag( $input, 'activate' ) );
}

function wsp_execute_delete_plugin( $input ) {
	$e = wsp_plugins_guard( 'delete_plugins' );
	if ( $e ) {
		return $e;
	}
	$file = wsp_plugins_resolve_file( $input );
	if ( is_array( $file ) ) return $file;
	if ( wsp_plugins_is_self( $file ) ) return wsp_plugins_fail( 'Refusing to delete this MCP plugin itself.' );
	if ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
		return wsp_plugins_fail( 'Plugin is active. Deactivate it first with wsp_deactivate_plugin.' );
	}
	$name   = get_plugins()[ $file ]['Name'];
	$result = wsp_plugins_run_upgrader( function () use ( $file ) {
		return delete_plugins( array( $file ) );
	} );
	if ( is_wp_error( $result ) ) return wsp_plugins_fail( $result->get_error_message() );
	if ( true !== $result ) return wsp_plugins_fail( 'WordPress could not delete the plugin files (check file permissions).' );
	return wsp_plugins_ok( array( 'file' => $file, 'name' => $name, 'deleted' => true ) );
}

function wsp_execute_update_plugin( $input ) {
	$e = wsp_plugins_guard( 'update_plugins' );
	if ( $e ) {
		return $e;
	}
	$file = wsp_plugins_resolve_file( $input );
	if ( is_array( $file ) ) return $file;

	wsp_plugins_run_upgrader( function () { wp_update_plugins(); } );
	$updates = get_site_transient( 'update_plugins' );
	$before  = get_plugins()[ $file ];
	if ( empty( $updates->response[ $file ] ) ) {
		return wsp_plugins_ok( array( 'file' => $file, 'name' => $before['Name'], 'version' => $before['Version'], 'updated' => false, 'message' => 'No update available.' ) );
	}
	$was_active = is_plugin_active( $file );
	$skin       = new WP_Ajax_Upgrader_Skin();
	$upgrader   = new Plugin_Upgrader( $skin );
	$result     = wsp_plugins_run_upgrader( function () use ( $upgrader, $file ) {
		return $upgrader->upgrade( $file );
	} );
	$err = wsp_plugins_upgrader_error( $result, $skin );
	if ( $err ) return wsp_plugins_fail( $err );

	wp_clean_plugins_cache( true );
	if ( $was_active && ! is_plugin_active( $file ) ) {
		activate_plugin( $file );
	}
	$after = get_plugins()[ $file ];
	return wsp_plugins_ok( array(
		'file'         => $file,
		'name'         => $after['Name'],
		'old_version'  => $before['Version'],
		'version'      => $after['Version'],
		'updated'      => true,
		'active'       => is_plugin_active( $file ),
	) );
}
