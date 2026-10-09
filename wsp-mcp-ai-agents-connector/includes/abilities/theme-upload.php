<?php
/**
 * Theme upload / install ability.
 *
 * Kept separate from themes.php (wsp_get_themes / wsp_switch_theme) on purpose:
 * this file holds the handler for wsp_upload_theme, and the tool registration in
 * native-tools.php fails with "tool has no handler" if it isn't loaded.
 *
 * - wsp_upload_theme: installs a theme an AI agent generated (or a theme zip)
 *   into wp-content/themes, optionally replacing an existing copy and
 *   optionally activating it.
 *
 * Three mutually exclusive sources:
 *   - `files`        — map of relative path → text content (style.css, functions.php,
 *                      templates/index.html, …), plus optional `binary_files`
 *                      (path → base64) for screenshot.png / fonts / images. Zipped
 *                      server-side under `{slug}/`.
 *   - `data`         — a base64 theme zip.
 *   - `url`          — an http(s) URL to a theme zip (fetched with download_url(),
 *                      which uses wp_safe_remote_get()).
 *
 * Every source ends up as a zip handed to core's Theme_Upgrader, exactly like
 * Appearance > Themes > Add New > Upload: core validates style.css headers,
 * index.php / templates/index.html, PHP/WP requirements, installs a missing
 * parent theme from WordPress.org, and handles overwrite + rollback.
 *
 * Gated by `install_themes`, which map_meta_cap() already denies when
 * DISALLOW_FILE_MODS is set and, on multisite, to anyone but a super admin.
 * Activation additionally needs `switch_themes`. Installing a theme is
 * installing PHP code — this is the same trust level as core's upload screen,
 * which is why the tool is OFF by default and admin-only.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Hard limits for the `files` / `binary_files` path (decoded bytes). */
define( 'WSP_THEME_MAX_FILES', 1000 );
define( 'WSP_THEME_MAX_BYTES', 20 * MB_IN_BYTES );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Extensions accepted as text in `files`. */
function wsp_theme_text_extensions() {
	return array( 'php', 'css', 'scss', 'js', 'mjs', 'map', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'pot', 'po' );
}

/** Extensions accepted as base64 in `binary_files`. */
function wsp_theme_binary_extensions() {
	return array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mo' );
}

/**
 * Validate and normalize one theme-relative path. Returns the path or WP_Error.
 * Rejects absolute paths, traversal, empty / hidden segments and unknown extensions.
 */
function wsp_theme_normalize_path( $path, $allowed_ext ) {
	$path = str_replace( '\\', '/', trim( (string) $path ) );
	$path = preg_replace( '#^\./#', '', $path );

	if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === $path[0] || preg_match( '#^[a-z]:#i', $path ) ) {
		return new WP_Error( 'invalid_path', sprintf( 'Invalid file path "%s": use a relative path inside the theme, e.g. "templates/index.html".', $path ) );
	}
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || '.' === $segment[0] || ! preg_match( '/^[A-Za-z0-9._-]+$/', $segment ) ) {
			return new WP_Error( 'invalid_path', sprintf( 'Invalid file path "%s": segments may only contain letters, digits, ".", "_" and "-", may not be empty, and may not start with "." (no "..", no hidden files).', $path ) );
		}
	}
	if ( strlen( $path ) > 200 ) {
		return new WP_Error( 'invalid_path', sprintf( 'File path "%s" is too long (max 200 characters).', $path ) );
	}
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $allowed_ext, true ) ) {
		return new WP_Error( 'invalid_path', sprintf( 'File "%s" has a disallowed extension. Allowed: %s.', $path, implode( ', ', $allowed_ext ) ) );
	}
	return $path;
}

/** Decode a base64 string strictly (accepts data: URIs and URL-safe base64). Returns bytes or false. */
function wsp_theme_b64_decode( $raw ) {
	$raw = trim( (string) $raw );
	$raw = preg_replace( '#^data:[\w.+/-]*;base64,#i', '', $raw );
	$raw = strtr( $raw, '-_', '+/' );
	$raw = preg_replace( '#[^A-Za-z0-9+/=]#', '', (string) $raw );
	$out = base64_decode( $raw, true );
	return ( false === $out || '' === $out ) ? false : $out;
}

/**
 * Write a zip of $entries ( zip path => bytes ) to $zip_path.
 * Uses ZipArchive, falling back to core's bundled PclZip (virtual file content).
 */
function wsp_theme_write_zip( $zip_path, $entries ) {
	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_failed', 'Could not create the theme archive.' );
		}
		foreach ( $entries as $name => $bytes ) {
			$zip->addFromString( $name, $bytes );
		}
		if ( ! $zip->close() ) {
			return new WP_Error( 'zip_failed', 'Could not write the theme archive.' );
		}
		return true;
	}

	require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
	$list = array();
	foreach ( $entries as $name => $bytes ) {
		$list[] = array( PCLZIP_ATT_FILE_NAME => $name, PCLZIP_ATT_FILE_CONTENT => $bytes );
	}
	$zip = new PclZip( $zip_path );
	if ( 0 === $zip->create( $list ) ) {
		return new WP_Error( 'zip_failed', 'Could not create the theme archive: ' . $zip->errorInfo( true ) );
	}
	return true;
}


/**
 * Pre-flight check of the PHP being installed: every provided .php file must parse
 * (token_get_all with TOKEN_PARSE — no shell/exec needed), and literal
 * require/include of a theme file (get_template_directory() . '/inc/x.php',
 * get_theme_file_path( 'inc/x.php' ), …) must point at a file that will exist in
 * the final theme. This is what a partial upload used to break (functions.php
 * requiring an inc/ file that was deleted). Returns true or WP_Error.
 *
 * @param string $slug     Theme folder.
 * @param array  $entries  Final zip entries ( "slug/path" => bytes ), merged.
 * @param array  $provided Zip entry names supplied by the caller (only these are parse-checked).
 */
function wsp_theme_preflight( $slug, $entries, $provided ) {
	foreach ( $provided as $name ) {
		if ( 'php' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) continue;
		try {
			token_get_all( $entries[ $name ], TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error( 'php_syntax_error', sprintf( 'PHP syntax error in %s on line %d: %s. Nothing was installed.', substr( $name, strlen( $slug ) + 1 ), $e->getLine(), $e->getMessage() ) );
		}
	}
	$re = '#\b(?:require|include)(?:_once)?\s*\(?\s*(?:(?:get_template_directory|get_stylesheet_directory)\s*\(\s*\)\s*\.\s*|get_theme_file_path\s*\(\s*|get_parent_theme_file_path\s*\(\s*)[\'"]/?([^\'"]+)[\'"]#';
	foreach ( $provided as $name ) {
		if ( 'php' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) continue;
		if ( ! preg_match_all( $re, $entries[ $name ], $m ) ) continue;
		foreach ( array_unique( $m[1] ) as $rel ) {
			if ( ! isset( $entries[ $slug . '/' . ltrim( $rel, '/' ) ] ) ) {
				return new WP_Error( 'missing_include', sprintf( '%s requires "%s", which is not in the theme being installed. Include that file (or upload with replace_all=false so existing files are kept). Nothing was installed.', substr( $name, strlen( $slug ) + 1 ), $rel ) );
			}
		}
	}
	return true;
}

/**
 * Existing files of an installed theme as zip entries, skipping paths in $skip.
 * Only files the upload path would itself accept are carried over.
 */
function wsp_theme_collect_existing( $slug, $skip ) {
	$dir = trailingslashit( get_theme_root() ) . $slug;
	if ( ! is_dir( $dir ) ) return array();
	$text = wsp_theme_text_extensions();
	$bin  = wsp_theme_binary_extensions();
	$out  = array();
	$total = 0;
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || $file->isLink() ) continue;
		$rel = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
		$key = $slug . '/' . $rel;
		if ( isset( $skip[ $key ] ) ) continue;
		if ( is_wp_error( wsp_theme_normalize_path( $rel, array_unique( array_merge( $text, $bin ) ) ) ) ) continue;
		$total += $file->getSize();
		if ( count( $out ) >= WSP_THEME_MAX_FILES || $total > WSP_THEME_MAX_BYTES ) break;
		$bytes = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $bytes ) $out[ $key ] = $bytes;
	}
	return $out;
}

/** Copy an installed theme folder to {slug}-backup-{timestamp}. Returns the backup slug, '' if none, or WP_Error. */
function wsp_theme_backup( $slug ) {
	global $wp_filesystem;
	$src = trailingslashit( get_theme_root() ) . $slug;
	if ( ! is_dir( $src ) ) return '';
	$backup = $slug . '-backup-' . gmdate( 'Ymd-His' );
	$dest   = trailingslashit( get_theme_root() ) . $backup;
	if ( ! $wp_filesystem->mkdir( $dest ) ) {
		return new WP_Error( 'backup_failed', 'Could not create a backup of the existing theme, so it was left untouched.' );
	}
	$copied = copy_dir( $src, $dest );
	if ( is_wp_error( $copied ) ) {
		$wp_filesystem->delete( $dest, true );
		return new WP_Error( 'backup_failed', 'Could not back up the existing theme (' . $copied->get_error_message() . '), so it was left untouched.' );
	}
	return $backup;
}

/**
 * Build a temp zip from the `files` / `binary_files` inputs.
 * Returns the temp zip path or WP_Error. Caller deletes the file.
 */
function wsp_theme_zip_from_files( $slug, $files, $binary_files, $merge_existing = false ) {
	if ( ! is_array( $files ) || empty( $files ) ) {
		return new WP_Error( 'invalid_input', '"files" must be a non-empty object of { "relative/path": "file content" }.' );
	}
	if ( null !== $binary_files && ! is_array( $binary_files ) ) {
		return new WP_Error( 'invalid_input', '"binary_files" must be an object of { "relative/path": "base64 content" }.' );
	}
	$binary_files = (array) $binary_files;
	if ( count( $files ) + count( $binary_files ) > WSP_THEME_MAX_FILES ) {
		return new WP_Error( 'too_large', sprintf( 'A theme may contain at most %d files.', WSP_THEME_MAX_FILES ) );
	}

	$entries = array();
	$total   = 0;

	// Text files: MCP args are decoded JSON, never slashed — store content verbatim
	// (no wp_unslash(), which would strip real backslashes from PHP / CSS / JSON).
	foreach ( $files as $path => $content ) {
		$norm = wsp_theme_normalize_path( $path, wsp_theme_text_extensions() );
		if ( is_wp_error( $norm ) ) return $norm;
		if ( ! is_string( $content ) ) {
			return new WP_Error( 'invalid_input', sprintf( 'Content of "%s" must be a string.', $norm ) );
		}
		if ( isset( $entries[ $slug . '/' . $norm ] ) ) {
			return new WP_Error( 'invalid_input', sprintf( 'Duplicate file path "%s".', $norm ) );
		}
		$entries[ $slug . '/' . $norm ] = $content;
		$total += strlen( $content );
	}

	foreach ( $binary_files as $path => $b64 ) {
		$norm = wsp_theme_normalize_path( $path, wsp_theme_binary_extensions() );
		if ( is_wp_error( $norm ) ) return $norm;
		if ( isset( $entries[ $slug . '/' . $norm ] ) ) {
			return new WP_Error( 'invalid_input', sprintf( 'Duplicate file path "%s" (present in both files and binary_files).', $norm ) );
		}
		$bytes = is_string( $b64 ) ? wsp_theme_b64_decode( $b64 ) : false;
		if ( false === $bytes ) {
			return new WP_Error( 'invalid_input', sprintf( 'binary_files["%s"] is not valid base64.', $norm ) );
		}
		$entries[ $slug . '/' . $norm ] = $bytes;
		$total += strlen( $bytes );
	}

	if ( $total > WSP_THEME_MAX_BYTES ) {
		return new WP_Error( 'too_large', sprintf( 'Theme files total %s; the limit is %s.', size_format( $total ), size_format( WSP_THEME_MAX_BYTES ) ) );
	}

	// Partial upload over an installed theme: keep every existing file the caller did not send.
	$provided = array_keys( $entries );
	if ( $merge_existing ) {
		$entries += wsp_theme_collect_existing( $slug, $entries );
	}
	$preflight = wsp_theme_preflight( $slug, $entries, $provided );
	if ( is_wp_error( $preflight ) ) return $preflight;

	// Friendly pre-check; Theme_Upgrader::check_package() re-validates authoritatively.
	$style = isset( $entries[ $slug . '/style.css' ] ) ? $entries[ $slug . '/style.css' ] : '';
	if ( '' === $style ) {
		return new WP_Error( 'invalid_theme', 'The theme must include a "style.css" at its root.' );
	}
	if ( ! preg_match( '/^[ \t\/*#@]*Theme Name:\s*\S/mi', $style ) ) {
		return new WP_Error( 'invalid_theme', 'style.css must start with a header comment containing "Theme Name: …".' );
	}

	$tmp = wp_tempnam( $slug . '.zip' );
	if ( ! $tmp ) {
		return new WP_Error( 'tmp_failed', 'Could not create a temporary file for the theme archive.' );
	}
	$written = wsp_theme_write_zip( $tmp, $entries );
	if ( is_wp_error( $written ) ) {
		wp_delete_file( $tmp );
		return $written;
	}
	return $tmp;
}

/** Decode the `data` input (base64 zip) to a temp file. Returns path or WP_Error. */
function wsp_theme_zip_from_data( $data ) {
	$bytes = wsp_theme_b64_decode( $data );
	if ( false === $bytes ) {
		return new WP_Error( 'invalid_input', '"data" is not valid base64 (it may have been truncated in transit — try "files" or "url" instead).' );
	}
	if ( 0 !== strpos( $bytes, "PK\x03\x04" ) ) {
		return new WP_Error( 'invalid_input', '"data" does not decode to a .zip archive.' );
	}
	if ( strlen( $bytes ) > WSP_THEME_MAX_BYTES ) {
		return new WP_Error( 'too_large', sprintf( 'The theme archive is larger than %s.', size_format( WSP_THEME_MAX_BYTES ) ) );
	}
	$tmp = wp_tempnam( 'theme.zip' );
	if ( ! $tmp ) {
		return new WP_Error( 'tmp_failed', 'Could not create a temporary file for the theme archive.' );
	}
	if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		wp_delete_file( $tmp );
		return new WP_Error( 'tmp_failed', 'Could not write the theme archive to disk.' );
	}
	// Validate the archive up front: a truncated base64 string otherwise surfaces much later
	// as an opaque PCLZIP_ERR_BAD_FORMAT from core's unzipper.
	$bad = '';
	if ( class_exists( 'ZipArchive' ) ) {
		$za = new ZipArchive();
		$rc = $za->open( $tmp, ZipArchive::CHECKCONS );
		if ( true !== $rc ) {
			$bad = 'ZipArchive error ' . (int) $rc;
		} else {
			$za->close();
		}
	} else {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$pz = new PclZip( $tmp );
		if ( 0 === $pz->listContent() ) {
			$bad = $pz->errorInfo( true );
		}
	}
	if ( '' !== $bad ) {
		wp_delete_file( $tmp );
		return new WP_Error( 'invalid_zip', sprintf( 'The decoded archive (%s) is not a valid .zip (%s) — the base64 was probably truncated or altered in transit. Send it with wsp_upload_theme_chunk, or use "files".', size_format( strlen( $bytes ) ), $bad ) );
	}
	return $tmp;
}

/** Activate an installed theme. Returns true or WP_Error. */
function wsp_theme_activate( $theme ) {
	if ( ! current_user_can( 'switch_themes' ) ) {
		return new WP_Error( 'forbidden', 'The theme was installed, but you do not have permission to activate themes (switch_themes).' );
	}
	if ( $theme->errors() ) {
		return new WP_Error( 'theme_broken', 'The theme was installed but cannot be activated: ' . $theme->errors()->get_error_message() );
	}
	if ( is_multisite() && ! $theme->is_allowed() ) {
		if ( ! current_user_can( 'manage_network_themes' ) ) {
			return new WP_Error( 'forbidden', 'The theme was installed but is not network-enabled for this site.' );
		}
		WP_Theme::network_enable_theme( $theme->get_stylesheet() );
	}
	$requirements = validate_theme_requirements( $theme->get_stylesheet() );
	if ( is_wp_error( $requirements ) ) {
		return $requirements;
	}
	switch_theme( $theme->get_stylesheet() );
	if ( get_stylesheet() !== $theme->get_stylesheet() ) {
		return new WP_Error( 'activation_failed', 'The theme was installed but WordPress did not switch to it.' );
	}
	return true;
}

// ---------------------------------------------------------------------------
// Tool
// ---------------------------------------------------------------------------

function wsp_execute_upload_theme( $input ) {
	if ( ! wp_is_file_mod_allowed( 'wsp_mcp_upload_theme' ) ) {
		return new WP_Error( 'file_mods_disabled', 'Installing themes is disabled on this site (DISALLOW_FILE_MODS).' );
	}

	$has_files = ! empty( $input['files'] );
	$has_data  = isset( $input['data'] ) && '' !== trim( (string) $input['data'] );
	$has_url   = isset( $input['url'] ) && '' !== trim( (string) $input['url'] );
	if ( 1 !== (int) $has_files + (int) $has_data + (int) $has_url ) {
		return new WP_Error( 'invalid_input', 'Provide exactly one source: "files" (theme files as text), "data" (base64 .zip) or "url" (link to a .zip).' );
	}

	$overwrite = ! empty( $input['overwrite'] );
	$activate  = ! empty( $input['activate'] );

	// Large themes take a while to zip/unpack; don't let the default limit kill the call.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
	}
	wp_raise_memory_limit( 'admin' );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$tmp = '';
	if ( $has_files ) {
		$slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
		if ( '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug ) ) {
			return new WP_Error( 'invalid_input', '"slug" (the theme folder name, e.g. "acme-studio") is required with "files": lowercase letters, digits, "-" or "_", max 64 characters.' );
		}
		if ( ! $overwrite && wp_get_theme( $slug )->exists() ) {
			return new WP_Error( 'theme_exists', sprintf( 'A theme named "%s" is already installed. Pass overwrite=true to replace it, or use a different slug.', $slug ) );
		}
		// With overwrite, a partial `files` map is merged into the installed theme by default;
		// only replace_all=true makes it a clean replace that deletes everything not sent.
		$merge = $overwrite && empty( $input['replace_all'] ) && wp_get_theme( $slug )->exists();
		$tmp = wsp_theme_zip_from_files( $slug, $input['files'], isset( $input['binary_files'] ) ? $input['binary_files'] : null, $merge );
		if ( is_wp_error( $tmp ) ) return $tmp;
		$package = $tmp;
	} elseif ( $has_data ) {
		$tmp = wsp_theme_zip_from_data( $input['data'] );
		if ( is_wp_error( $tmp ) ) return $tmp;
		$package = $tmp;
	} else {
		$package = esc_url_raw( trim( (string) $input['url'] ), array( 'http', 'https' ) );
		if ( '' === $package || ! wp_http_validate_url( $package ) ) {
			return new WP_Error( 'invalid_input', '"url" must be a public http(s) URL to a theme .zip file.' );
		}
	}

	// Make sure we can write to wp-content/themes without prompting for FTP credentials.
	if ( ! WP_Filesystem() ) {
		if ( $tmp ) wp_delete_file( $tmp );
		return new WP_Error( 'filesystem_unavailable', 'WordPress cannot write to the themes directory without FTP/SSH credentials. Define FS_METHOD (or the FTP_* constants) in wp-config.php, or install the theme from WP Admin.' );
	}

	$before = array_keys( wp_get_themes( array( 'errors' => null ) ) );

	// Back up the theme folder that is about to be replaced.
	$backup = '';
	if ( $overwrite ) {
		$target = isset( $slug ) ? $slug : '';
		if ( '' !== $target && wp_get_theme( $target )->exists() ) {
			$backup = wsp_theme_backup( $target );
			if ( is_wp_error( $backup ) ) {
				if ( $tmp ) wp_delete_file( $tmp );
				return $backup;
			}
		}
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Theme_Upgrader( $skin );

	// Upgrader / third-party hooks may print; keep it out of the JSON response.
	$ob_level = ob_get_level();
	ob_start();
	$result = $upgrader->install( $package, array( 'overwrite_package' => $overwrite ) );
	while ( ob_get_level() > $ob_level ) {
		ob_end_clean();
	}

	if ( $tmp ) wp_delete_file( $tmp );

	if ( is_wp_error( $result ) ) return $result;
	if ( is_wp_error( $skin->result ) ) return $skin->result;
	if ( $skin->get_errors()->has_errors() ) {
		$err = $skin->get_errors();
		$msg = $skin->get_error_messages(); // WP_Ajax_Upgrader_Skin joins them into one string.
		// folder_exists → tell the agent about `overwrite`.
		if ( 'folder_exists' === $err->get_error_code() ) {
			$msg .= ' Pass overwrite=true to replace the installed copy.';
		}
		return new WP_Error( $err->get_error_code(), wp_strip_all_tags( $msg ) );
	}
	if ( ! $result ) {
		return new WP_Error( 'install_failed', 'The theme could not be installed (unknown upgrader error).' );
	}

	$theme = $upgrader->theme_info();
	if ( ! $theme || ! $theme->exists() ) {
		return new WP_Error( 'install_failed', 'The theme was unpacked but WordPress cannot read it.' );
	}
	$stylesheet = $theme->get_stylesheet();
	$is_child   = $theme->get_template() !== $stylesheet;

	$response = array(
		'success'          => true,
		'slug'             => $stylesheet,
		'name'             => $theme->get( 'Name' ),
		'version'          => $theme->get( 'Version' ),
		'author'           => wp_strip_all_tags( $theme->get( 'Author' ) ),
		'is_block_theme'   => $theme->is_block_theme(),
		'parent'           => $is_child ? $theme->get_template() : null,
		'parent_installed' => $is_child ? wp_get_theme( $theme->get_template() )->exists() : null,
		'replaced'         => in_array( $stylesheet, $before, true ),
		'activated'        => false,
		'backup'           => $backup ? $backup : null,
		'merged_with_existing' => isset( $merge ) ? $merge : false,
		'active_theme'     => get_stylesheet(),
		'preview_url'      => add_query_arg( 'theme', $stylesheet, admin_url( 'customize.php' ) ),
	);
	if ( $theme->errors() ) {
		$response['theme_errors'] = $theme->errors()->get_error_messages();
	}

	if ( $activate ) {
		$activated = wsp_theme_activate( $theme );
		if ( is_wp_error( $activated ) ) {
			// Installed fine; report the activation problem without hiding the install.
			$response['activation_error'] = $activated->get_error_message();
		} else {
			$response['activated']    = true;
			$response['active_theme'] = get_stylesheet();
		}
	}

	return $response;
}
