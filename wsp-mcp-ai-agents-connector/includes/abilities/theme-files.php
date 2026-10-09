<?php
/**
 * Single-file theme tools and chunked theme upload.
 *
 * - wsp_get_theme_file:      list a theme's files, or read one file.
 * - wsp_update_theme_file:   create/overwrite ONE file (parse-checked, previous version backed up)
 *                            so a one-line fix needs no full re-upload.
 * - wsp_upload_theme_chunk:  send a large base64 theme zip in pieces, then install it through
 *                            wsp_execute_upload_theme() — avoids truncated/oversized single calls.
 *
 * Same trust level as theme-upload.php: installs PHP code, so `install_themes` + OFF by default.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSP_THEME_READ_MAX_BYTES', 512 * KB_IN_BYTES );

/** Resolve an installed theme's directory (no traversal: slug must be an installed stylesheet). */
function wsp_theme_files_dir( $theme ) {
	$slug = sanitize_title( (string) $theme );
	if ( '' === $slug || ! wp_get_theme( $slug )->exists() ) {
		return new WP_Error( 'not_found', 'Theme not found: ' . $slug . '. Use wsp_get_themes to list installed themes.' );
	}
	return array( $slug, trailingslashit( get_theme_root( $slug ) ) . $slug );
}

function wsp_execute_get_theme_file( $input ) {
	$r = wsp_theme_files_dir( isset( $input['theme'] ) ? $input['theme'] : '' );
	if ( is_wp_error( $r ) ) return $r;
	list( $slug, $dir ) = $r;

	$path = isset( $input['path'] ) ? trim( (string) $input['path'] ) : '';
	if ( '' === $path ) {
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || $f->isLink() ) continue;
			$files[] = array( 'path' => str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) ), 'bytes' => $f->getSize() );
			if ( count( $files ) >= 2000 ) break;
		}
		return array( 'theme' => $slug, 'files' => $files, 'total' => count( $files ) );
	}

	$norm = wsp_theme_normalize_path( $path, wsp_theme_text_extensions() );
	if ( is_wp_error( $norm ) ) return $norm;
	$full = $dir . '/' . $norm;
	if ( ! is_file( $full ) || is_link( $full ) ) {
		return new WP_Error( 'not_found', 'File not found in theme: ' . $norm );
	}
	$size    = filesize( $full );
	$content = file_get_contents( $full, false, null, 0, WSP_THEME_READ_MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $content ) {
		return new WP_Error( 'read_failed', 'Could not read ' . $norm );
	}
	return array(
		'theme'     => $slug,
		'path'      => $norm,
		'bytes'     => $size,
		'sha256'    => hash_file( 'sha256', $full ),
		'truncated' => $size > WSP_THEME_READ_MAX_BYTES,
		'content'   => $content,
	);
}

function wsp_execute_update_theme_file( $input ) {
	if ( ! wp_is_file_mod_allowed( 'wsp_mcp_update_theme_file' ) ) {
		return new WP_Error( 'file_mods_disabled', 'Modifying theme files is disabled on this site (DISALLOW_FILE_MODS).' );
	}
	$r = wsp_theme_files_dir( isset( $input['theme'] ) ? $input['theme'] : '' );
	if ( is_wp_error( $r ) ) return $r;
	list( $slug, $dir ) = $r;

	if ( ! isset( $input['path'], $input['content'] ) || ! is_string( $input['content'] ) ) {
		return new WP_Error( 'invalid_input', '"path" and string "content" are required.' );
	}
	$norm = wsp_theme_normalize_path( $input['path'], wsp_theme_text_extensions() );
	if ( is_wp_error( $norm ) ) return $norm;
	if ( strlen( $input['content'] ) > WSP_THEME_MAX_BYTES ) {
		return new WP_Error( 'too_large', 'File is larger than ' . size_format( WSP_THEME_MAX_BYTES ) . '.' );
	}

	// Same pre-flight as a full upload: parse check + literal includes must resolve.
	$key     = $slug . '/' . $norm;
	$entries = wsp_theme_collect_existing( $slug, array( $key => true ) );
	$entries[ $key ] = $input['content'];
	$pf = wsp_theme_preflight( $slug, $entries, array( $key ) );
	if ( is_wp_error( $pf ) ) return $pf;

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'filesystem_unavailable', 'WordPress cannot write to the themes directory without FTP/SSH credentials.' );
	}
	global $wp_filesystem;
	$full   = $dir . '/' . $norm;
	$exists = is_file( $full );
	$backup = null;
	if ( $exists ) {
		$up = wp_upload_dir();
		$bd = trailingslashit( $up['basedir'] ) . 'wsp-mcp-theme-backups/' . $slug . '/' . dirname( $norm );
		if ( wp_mkdir_p( $bd ) ) {
			$dest = rtrim( $bd, '/.' ) . '/' . basename( $norm ) . '.' . gmdate( 'Ymd-His' ) . '.bak';
			if ( $wp_filesystem->copy( $full, $dest, true ) ) {
				$backup = str_replace( ABSPATH, '', $dest );
			}
		}
	}
	if ( ! wp_mkdir_p( dirname( $full ) ) || ! $wp_filesystem->put_contents( $full, $input['content'], FS_CHMOD_FILE ) ) {
		return new WP_Error( 'write_failed', 'Could not write ' . $norm . '.' );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $full, true );
	}
	return array(
		'success' => true,
		'theme'   => $slug,
		'path'    => $norm,
		'created' => ! $exists,
		'bytes'   => strlen( $input['content'] ),
		'sha256'  => hash( 'sha256', $input['content'] ),
		'backup'  => $backup,
	);
}

/** Temp file that accumulates one chunked upload (per user + upload_id). */
function wsp_theme_chunk_file( $upload_id ) {
	return trailingslashit( get_temp_dir() ) . 'wsp-theme-chunk-' . md5( get_current_user_id() . '|' . $upload_id ) . '.b64';
}

function wsp_execute_upload_theme_chunk( $input ) {
	$upload_id = isset( $input['upload_id'] ) ? sanitize_key( (string) $input['upload_id'] ) : '';
	if ( '' === $upload_id || strlen( $upload_id ) > 64 ) {
		return new WP_Error( 'invalid_input', '"upload_id" is required (any short unique string, same for every chunk of one theme).' );
	}
	$part = isset( $input['part'] ) ? (int) $input['part'] : -1;
	if ( $part < 0 ) {
		return new WP_Error( 'invalid_input', '"part" must be the 0-based chunk number.' );
	}
	$file = wsp_theme_chunk_file( $upload_id );
	$meta = $file . '.next';

	if ( 0 === $part ) {
		// New upload: drop any stale temp files (>1h) and restart this id.
		foreach ( (array) glob( trailingslashit( get_temp_dir() ) . 'wsp-theme-chunk-*' ) as $old ) {
			if ( is_file( $old ) && filemtime( $old ) < time() - HOUR_IN_SECONDS ) wp_delete_file( $old );
		}
		wp_delete_file( $file );
		wp_delete_file( $meta );
	}
	$expected = is_file( $meta ) ? (int) file_get_contents( $meta ) : 0; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( $part !== $expected ) {
		return new WP_Error( 'out_of_order', sprintf( 'Expected part %d but got %d. Send parts in order starting at 0 (part 0 restarts the upload).', $expected, $part ) );
	}

	$chunk = isset( $input['data'] ) ? preg_replace( '#[^A-Za-z0-9+/=_-]#', '', preg_replace( '#^data:[\w.+/-]*;base64,#i', '', trim( (string) $input['data'] ) ) ) : '';
	if ( '' === $chunk ) {
		return new WP_Error( 'invalid_input', '"data" (a base64 piece of the .zip) is required.' );
	}
	$current = is_file( $file ) ? filesize( $file ) : 0;
	if ( $current + strlen( $chunk ) > (int) ( WSP_THEME_MAX_BYTES * 1.4 ) ) {
		wp_delete_file( $file );
		wp_delete_file( $meta );
		return new WP_Error( 'too_large', 'The theme archive exceeds ' . size_format( WSP_THEME_MAX_BYTES ) . '. Upload aborted.' );
	}
	if ( false === file_put_contents( $file, $chunk, FILE_APPEND ) || false === file_put_contents( $meta, (string) ( $part + 1 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return new WP_Error( 'tmp_failed', 'Could not store the chunk on the server.' );
	}

	if ( empty( $input['complete'] ) ) {
		return array( 'success' => true, 'received_parts' => $part + 1, 'received_chars' => $current + strlen( $chunk ), 'next_part' => $part + 1 );
	}

	$b64 = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	wp_delete_file( $file );
	wp_delete_file( $meta );
	return wsp_execute_upload_theme( array(
		'data'      => $b64,
		'overwrite' => ! empty( $input['overwrite'] ),
		'activate'  => ! empty( $input['activate'] ),
	) );
}
