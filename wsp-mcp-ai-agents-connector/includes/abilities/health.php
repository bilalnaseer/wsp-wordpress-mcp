<?php
/**
 * Site Health, WP-Cron and error-log abilities (diagnostics).
 *
 * - wsp_get_site_health: runs core's Site Health tests (WP_Site_Health) and
 *   returns the Site Health Info data (WP_Debug_Data), minus every field core
 *   marks private. Gated by core's `view_site_health_checks` meta capability.
 * - Cron: list / inspect / run-now / unschedule existing WP-Cron events.
 *   Deliberately NO "schedule new event" tool: scheduling an arbitrary hook with
 *   arbitrary args is a primitive for firing any action in WordPress. The
 *   plugin's own `wsp_mcp_*` hooks can't be unscheduled — they are only
 *   re-added on activation / version change, so removing them would silently
 *   stop session, audit-log and OAuth cleanup.
 * - wsp_get_error_log: tails ONLY the PHP `error_log` ini path (where
 *   WP_DEBUG_LOG writes) or wp-content/debug.log. Never a caller-supplied path.
 *
 * Cron and error-log tools require `manage_options`, and on multisite a super
 * admin (cron events and the PHP log are shared network/server-wide).
 * Secret-shaped values (tokens, passwords, salts, URL credentials, JWTs) are
 * redacted from cron args, error-log lines and Site Health Info values.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/** On multisite, only a super admin may touch network-shared cron/log state. */
function wsp_health_require_network_admin() {
	if ( is_multisite() && ! is_super_admin() ) {
		return new WP_Error( 'forbidden', 'On multisite only a network (super) administrator can use this tool — cron events and the PHP error log are shared by every site.' );
	}
	return true;
}

/** Redact secret-shaped substrings from a string. */
function wsp_health_redact( $text ) {
	if ( ! is_string( $text ) || '' === $text ) return $text;
	$patterns = array(
		// define( 'AUTH_KEY', '...' ) style salts/secrets.
		'/(define\(\s*[\'"][A-Z0-9_]*(?:KEY|SALT|PASSWORD|SECRET|TOKEN)[\'"]\s*,\s*[\'"])[^\'"]*/i' => '$1[REDACTED]',
		// Authorization header values.
		'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [REDACTED]',
		// user:pass@ in URLs.
		'/(\b[a-z][a-z0-9+.-]*:\/\/)[^\/\s:@]+:[^\/\s@]+@/i' => '$1[REDACTED]@',
		// JWTs.
		'/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => '[REDACTED_JWT]',
		// key=value / key: value pairs for secret-looking names.
		'/\b((?:pass(?:word|wd)?|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|client[_-]?secret|auth)["\']?\s*[=:]\s*["\']?)[^\s"\'&,;)]+/i' => '$1[REDACTED]',
		// Long opaque tokens (API keys, hashes used as credentials).
		'/\b[A-Za-z0-9_\-]{40,}\b/' => '[REDACTED]',
	);
	return preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );
}

/** Recursively redact every string in a value. */
function wsp_health_redact_deep( $value ) {
	if ( is_array( $value ) ) return array_map( 'wsp_health_redact_deep', $value );
	return is_string( $value ) ? wsp_health_redact( $value ) : $value;
}

/** HTML → single-line plain text. */
function wsp_health_plain( $html ) {
	if ( ! is_string( $html ) ) return '';
	return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
}

/** UTC ISO 8601 for a Unix timestamp. */
function wsp_health_iso( $ts ) {
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
}

// ---------------------------------------------------------------------------
// Site Health
// ---------------------------------------------------------------------------

/** Load the wp-admin files WP_Site_Health / WP_Debug_Data depend on (not loaded on REST requests). */
function wsp_health_load_admin_includes() {
	foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'update.php', 'theme.php', 'class-wp-site-health.php', 'class-wp-debug-data.php' ) as $file ) {
		require_once ABSPATH . 'wp-admin/includes/' . $file;
	}
}

/** Normalize one Site Health test result (core shape) to plain text. */
function wsp_health_format_test( $key, $result ) {
	return array(
		'test'        => isset( $result['test'] ) ? $result['test'] : $key,
		'label'       => wsp_health_plain( isset( $result['label'] ) ? $result['label'] : '' ),
		'status'      => isset( $result['status'] ) ? $result['status'] : 'error',
		'badge'       => isset( $result['badge']['label'] ) ? wsp_health_plain( $result['badge']['label'] ) : '',
		'description' => wsp_health_plain( isset( $result['description'] ) ? $result['description'] : '' ),
		'actions'     => wsp_health_plain( isset( $result['actions'] ) ? $result['actions'] : '' ),
	);
}

/** Run one test definition from WP_Site_Health::get_tests(). Returns result array, or null when it can't run server-side. */
function wsp_health_run_test( $health, $test, $async ) {
	$callback = null;
	if ( $async && ! empty( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
		$callback = $test['async_direct_test'];
	} elseif ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
		$callback = array( $health, 'get_test_' . $test['test'] );
	} elseif ( ! $async && is_callable( $test['test'] ) ) {
		$callback = $test['test'];
	}
	if ( ! $callback ) return null;
	// Same filter core applies when it runs a test, so third-party adjustments still apply.
	return apply_filters( 'site_status_test_result', call_user_func( $callback ) );
}

/** Flatten a Site Health Info field value to a string. */
function wsp_health_info_value( $value ) {
	if ( is_bool( $value ) ) return $value ? 'true' : 'false';
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $k => $v ) {
			$v       = wsp_health_info_value( $v );
			$parts[] = is_string( $k ) ? "{$k}: {$v}" : $v;
		}
		return implode( ', ', $parts );
	}
	return wsp_health_plain( (string) $value );
}

function wsp_execute_get_site_health( $input ) {
	wsp_health_load_admin_includes();
	if ( ! class_exists( 'WP_Site_Health' ) ) return new WP_Error( 'unsupported', 'Site Health is not available on this WordPress install.' );

	$include_async = ! empty( $input['include_async'] );
	$include_info  = ! isset( $input['include_info'] ) || (bool) $input['include_info'];

	$health  = WP_Site_Health::get_instance();
	$all     = WP_Site_Health::get_tests();
	$tests   = array();
	$skipped = array();
	$counts  = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );

	$groups = array( 'direct' => false );
	if ( $include_async ) $groups['async'] = true;
	foreach ( $groups as $group => $is_async ) {
		foreach ( isset( $all[ $group ] ) ? (array) $all[ $group ] : array() as $key => $test ) {
			$label = isset( $test['label'] ) ? wsp_health_plain( $test['label'] ) : $key;
			try {
				$result = wsp_health_run_test( $health, $test, $is_async );
			} catch ( Throwable $e ) {
				$tests[] = array( 'test' => $key, 'label' => $label, 'status' => 'error', 'badge' => '', 'description' => wsp_health_redact( $e->getMessage() ), 'actions' => '' );
				continue;
			}
			if ( ! is_array( $result ) ) {
				$skipped[] = array( 'test' => $key, 'label' => $label, 'reason' => 'This test only runs in the browser (via the REST API) and has no server-side callback.' );
				continue;
			}
			$row     = wsp_health_format_test( $key, $result );
			$tests[] = $row;
			if ( isset( $counts[ $row['status'] ] ) ) $counts[ $row['status'] ]++;
		}
	}
	if ( ! $include_async && ! empty( $all['async'] ) ) {
		foreach ( $all['async'] as $key => $test ) {
			$skipped[] = array( 'test' => $key, 'label' => isset( $test['label'] ) ? wsp_health_plain( $test['label'] ) : $key, 'reason' => 'Slow test; pass include_async=true to run it.' );
		}
	}

	// Critical first, then recommended, then good — what needs attention leads.
	$rank = array( 'critical' => 0, 'error' => 1, 'recommended' => 2, 'good' => 3 );
	usort( $tests, function ( $a, $b ) use ( $rank ) {
		$ra = isset( $rank[ $a['status'] ] ) ? $rank[ $a['status'] ] : 4;
		$rb = isset( $rank[ $b['status'] ] ) ? $rank[ $b['status'] ] : 4;
		return $ra - $rb;
	} );

	$result = array( 'counts' => $counts, 'tests' => $tests, 'skipped' => $skipped );

	if ( $include_info && class_exists( 'WP_Debug_Data' ) ) {
		$info      = array();
		$omitted   = array();
		$truncated = false;
		$max       = 150; // fields per section — keeps the response bounded on sites with hundreds of plugins.
		foreach ( WP_Debug_Data::debug_data() as $section_key => $section ) {
			// Directory sizes are computed via a slow AJAX call in core; skip, and skip private sections.
			if ( 'wp-paths-sizes' === $section_key || ! empty( $section['private'] ) ) {
				$omitted[] = $section_key;
				continue;
			}
			$fields = array();
			foreach ( isset( $section['fields'] ) ? (array) $section['fields'] : array() as $name => $field ) {
				if ( ! empty( $field['private'] ) ) continue; // same set core's "Copy site info" leaves out
				if ( count( $fields ) >= $max ) { $truncated = true; break; }
				$fields[ $name ] = array(
					'label' => wsp_health_plain( isset( $field['label'] ) ? $field['label'] : $name ),
					'value' => wsp_health_redact( wsp_health_info_value( isset( $field['value'] ) ? $field['value'] : '' ) ),
				);
			}
			$info[ $section_key ] = array( 'label' => wsp_health_plain( isset( $section['label'] ) ? $section['label'] : $section_key ), 'fields' => $fields );
		}
		$result['info']           = $info;
		$result['info_truncated'] = $truncated;
		$result['info_omitted']   = $omitted;
	}
	return $result;
}

// ---------------------------------------------------------------------------
// WP-Cron
// ---------------------------------------------------------------------------

/** Flat list of every scheduled event, soonest first. */
function wsp_health_cron_events() {
	$crons  = _get_cron_array();
	$events = array();
	foreach ( is_array( $crons ) ? $crons : array() as $ts => $hooks ) {
		if ( ! is_array( $hooks ) ) continue;
		foreach ( $hooks as $hook => $instances ) {
			foreach ( (array) $instances as $key => $data ) {
				$events[] = array(
					'timestamp' => (int) $ts,
					'hook'      => $hook,
					'key'       => $key,
					'schedule'  => isset( $data['schedule'] ) ? $data['schedule'] : false,
					'interval'  => isset( $data['interval'] ) ? (int) $data['interval'] : null,
					'args'      => isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array(),
				);
			}
		}
	}
	usort( $events, function ( $a, $b ) { return $a['timestamp'] - $b['timestamp']; } );
	return $events;
}

/** Public representation of one event. */
function wsp_health_cron_to_array( $e, $now ) {
	$schedule = null;
	if ( $e['schedule'] ) {
		$schedules = wp_get_schedules();
		$known     = isset( $schedules[ $e['schedule'] ] );
		$schedule  = array(
			'name'             => $e['schedule'],
			'interval_seconds' => $known ? (int) $schedules[ $e['schedule'] ]['interval'] : $e['interval'],
			'display'          => $known ? $schedules[ $e['schedule'] ]['display'] : '',
			'registered'       => $known, // false = the plugin that added this schedule is gone
		);
	}
	$until = $e['timestamp'] - $now;
	return array(
		'hook'              => $e['hook'],
		'key'               => $e['key'],
		'next_run'          => wsp_health_iso( $e['timestamp'] ),
		'seconds_until_run' => $until,
		'overdue'           => $until < -HOUR_IN_SECONDS,
		'schedule'          => $schedule,
		'has_callback'      => (bool) has_action( $e['hook'] ), // false = nothing runs when it fires (orphaned)
		'args'              => wsp_health_redact_deep( $e['args'] ),
	);
}

/** Human-readable names of the callbacks attached to a hook. */
function wsp_health_hook_callbacks( $hook ) {
	global $wp_filter;
	$out = array();
	if ( ! isset( $wp_filter[ $hook ] ) ) return $out;
	foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'];
			if ( is_string( $fn ) ) {
				$name = $fn;
			} elseif ( $fn instanceof Closure ) {
				$name = '{closure}';
			} elseif ( is_array( $fn ) && 2 === count( $fn ) ) {
				$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) . '->' : $fn[0] . '::' ) . $fn[1];
			} elseif ( is_object( $fn ) ) {
				$name = get_class( $fn ) . '::__invoke';
			} else {
				$name = '(unknown)';
			}
			$out[] = array( 'callback' => $name, 'priority' => (int) $priority );
		}
	}
	return $out;
}

/**
 * Resolve the single event a run/delete call targets. With no key, the hook must
 * have exactly one instance; otherwise the caller must pick one by key.
 */
function wsp_health_find_cron_event( $input ) {
	if ( empty( $input['hook'] ) ) return new WP_Error( 'missing_input', 'hook is required.' );
	$hook    = sanitize_text_field( $input['hook'] );
	$key     = ! empty( $input['key'] ) ? sanitize_text_field( $input['key'] ) : '';
	$matches = array();
	foreach ( wsp_health_cron_events() as $e ) {
		if ( $e['hook'] === $hook && ( '' === $key || $e['key'] === $key ) ) $matches[] = $e;
	}
	if ( ! $matches ) return new WP_Error( 'not_found', "No scheduled event for hook '{$hook}'" . ( $key ? " with key '{$key}'" : '' ) . '.' );
	if ( count( $matches ) > 1 ) {
		return new WP_Error( 'ambiguous', "Hook '{$hook}' has " . count( $matches ) . ' scheduled instances; pass key (from wsp_get_cron_event) to pick one. Keys: ' . implode( ', ', wp_list_pluck( $matches, 'key' ) ) );
	}
	return $matches[0];
}

function wsp_execute_get_cron_events( $input ) {
	$ok = wsp_health_require_network_admin();
	if ( is_wp_error( $ok ) ) return $ok;

	$filter = isset( $input['hook'] ) ? strtolower( sanitize_text_field( $input['hook'] ) ) : '';
	$limit  = isset( $input['limit'] ) ? max( 1, min( 500, intval( $input['limit'] ) ) ) : 50;
	$now    = time();

	$all     = array();
	$overdue = 0;
	foreach ( wsp_health_cron_events() as $e ) {
		if ( '' !== $filter && false === strpos( strtolower( $e['hook'] ), $filter ) ) continue;
		$row = wsp_health_cron_to_array( $e, $now );
		if ( $row['overdue'] ) $overdue++;
		$all[] = $row;
	}

	$schedules = array();
	foreach ( wp_get_schedules() as $name => $s ) {
		$schedules[] = array( 'name' => $name, 'interval_seconds' => (int) $s['interval'], 'display' => $s['display'] );
	}

	return array(
		'events'        => array_slice( $all, 0, $limit ),
		'total'         => count( $all ),
		'returned'      => min( $limit, count( $all ) ),
		'overdue_count' => $overdue,
		'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'now'           => wsp_health_iso( $now ),
		'schedules'     => $schedules,
	);
}

function wsp_execute_get_cron_event( $input ) {
	$ok = wsp_health_require_network_admin();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['hook'] ) ) return new WP_Error( 'missing_input', 'hook is required.' );

	$hook = sanitize_text_field( $input['hook'] );
	$key  = ! empty( $input['key'] ) ? sanitize_text_field( $input['key'] ) : '';
	$now  = time();
	$instances = array();
	foreach ( wsp_health_cron_events() as $e ) {
		if ( $e['hook'] === $hook && ( '' === $key || $e['key'] === $key ) ) $instances[] = wsp_health_cron_to_array( $e, $now );
	}
	if ( ! $instances ) return new WP_Error( 'not_found', "No scheduled event for hook '{$hook}'" . ( $key ? " with key '{$key}'" : '' ) . '.' );

	return array(
		'hook'      => $hook,
		'callbacks' => wsp_health_hook_callbacks( $hook ),
		'protected' => 0 === strpos( $hook, 'wsp_mcp_' ),
		'instances' => $instances,
	);
}

/**
 * Run an existing event now, in this request — the same do_action_ref_array()
 * wp-cron.php performs. A one-off event is unscheduled first (as wp-cron.php
 * does); a recurring event keeps its next scheduled run untouched.
 */
function wsp_execute_run_cron_event( $input ) {
	$ok = wsp_health_require_network_admin();
	if ( is_wp_error( $ok ) ) return $ok;
	$e = wsp_health_find_cron_event( $input );
	if ( is_wp_error( $e ) ) return $e;
	if ( ! has_action( $e['hook'] ) ) {
		return new WP_Error( 'no_callback', "Nothing is attached to hook '{$e['hook']}' (its plugin is probably inactive), so running it would do nothing." );
	}

	if ( ! $e['schedule'] ) wp_unschedule_event( $e['timestamp'], $e['hook'], $e['args'] );

	$level = ob_get_level();
	ob_start();
	$start = microtime( true );
	$error = null;
	try {
		do_action_ref_array( $e['hook'], $e['args'] );
	} catch ( Throwable $t ) {
		$error = $t->getMessage();
	}
	$duration = (int) round( ( microtime( true ) - $start ) * 1000 );
	// Collect everything the callback printed, including buffers it opened and left open.
	$output = '';
	while ( ob_get_level() > $level ) $output = ob_get_clean() . $output;

	$result = array(
		'success'     => null === $error,
		'hook'        => $e['hook'],
		'key'         => $e['key'],
		'duration_ms' => $duration,
		'recurring'   => (bool) $e['schedule'],
		'output'      => wsp_health_redact( substr( wsp_health_plain( $output ), 0, 2000 ) ),
	);
	if ( null !== $error ) $result['error'] = wsp_health_redact( $error );
	if ( ! $e['schedule'] ) $result['note'] = 'One-off event: it has been consumed and is no longer scheduled.';
	return $result;
}

function wsp_execute_delete_cron_event( $input ) {
	$ok = wsp_health_require_network_admin();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['hook'] ) ) return new WP_Error( 'missing_input', 'hook is required.' );

	$hook = sanitize_text_field( $input['hook'] );
	if ( 0 === strpos( $hook, 'wsp_mcp_' ) ) {
		return new WP_Error( 'protected', "'{$hook}' is WSP MCP's own maintenance task (session / audit-log / OAuth cleanup) and cannot be unscheduled from here." );
	}

	if ( ! empty( $input['all'] ) ) {
		$count = wp_unschedule_hook( $hook, true );
		if ( is_wp_error( $count ) ) return $count;
		if ( ! $count ) return new WP_Error( 'not_found', "No scheduled event for hook '{$hook}'." );
		return array( 'success' => true, 'hook' => $hook, 'unscheduled' => (int) $count );
	}

	$e = wsp_health_find_cron_event( $input );
	if ( is_wp_error( $e ) ) {
		if ( 'ambiguous' === $e->get_error_code() ) $e->add( 'ambiguous_hint', 'Or pass all=true to unschedule every instance.' );
		return $e;
	}
	$done = wp_unschedule_event( $e['timestamp'], $e['hook'], $e['args'], true );
	if ( is_wp_error( $done ) ) return $done;
	return array( 'success' => true, 'hook' => $e['hook'], 'key' => $e['key'], 'unscheduled' => 1, 'was_next_run' => wsp_health_iso( $e['timestamp'] ) );
}

// ---------------------------------------------------------------------------
// Error log
// ---------------------------------------------------------------------------

/** The only two files this tool will ever read. Returns [ path, source ] or null. */
function wsp_health_error_log_path() {
	$ini = ini_get( 'error_log' );
	if ( $ini && 'syslog' !== $ini && is_file( $ini ) && is_readable( $ini ) ) {
		return array( $ini, 'error_log ini' );
	}
	$debug = WP_CONTENT_DIR . '/debug.log';
	if ( is_file( $debug ) && is_readable( $debug ) ) {
		return array( $debug, 'wp-content/debug.log' );
	}
	return null;
}

function wsp_execute_get_error_log( $input ) {
	$ok = wsp_health_require_network_admin();
	if ( is_wp_error( $ok ) ) return $ok;

	$found = wsp_health_error_log_path();
	if ( ! $found ) {
		$reason = 'No readable error log found. Enable logging in wp-config.php with define( \'WP_DEBUG\', true ); define( \'WP_DEBUG_LOG\', true ); define( \'WP_DEBUG_DISPLAY\', false );';
		if ( 'syslog' === ini_get( 'error_log' ) ) $reason = 'PHP logs to syslog, which cannot be read from WordPress.';
		return array( 'path_source' => null, 'reason' => $reason );
	}
	list( $path, $source ) = $found;

	$want      = isset( $input['lines'] ) ? max( 1, min( 1000, intval( $input['lines'] ) ) ) : 100;
	$grep      = isset( $input['grep'] ) ? strtolower( sanitize_text_field( $input['grep'] ) ) : '';
	$scan_max  = 2 * MB_IN_BYTES; // never read more than the last 2 MB of the file
	$resp_max  = 64 * KB_IN_BYTES;
	$size      = (int) filesize( $path );
	$read      = min( $size, $scan_max );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only tail of a fixed log path; WP_Filesystem cannot seek.
	$fh = fopen( $path, 'rb' );
	if ( ! $fh ) return array( 'path_source' => null, 'reason' => 'The error log exists but could not be opened.' );
	fseek( $fh, -$read, SEEK_END );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
	$chunk = $read > 0 ? fread( $fh, $read ) : '';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $fh );

	$raw = preg_split( '/\r\n|\r|\n/', (string) $chunk );
	if ( $read < $size ) array_shift( $raw ); // first line is partial when we started mid-file
	$lines = array();
	foreach ( $raw as $line ) {
		if ( '' === trim( $line ) ) continue;
		$line = wsp_health_redact( substr( $line, 0, 2000 ) );
		if ( '' !== $grep && false === strpos( strtolower( $line ), $grep ) ) continue;
		$lines[] = $line;
	}
	$lines     = array_slice( $lines, -$want );
	$truncated = $read < $size && count( $lines ) < $want;

	// Keep the response bounded: drop oldest lines until under the cap.
	$bytes = 0;
	foreach ( $lines as $l ) $bytes += strlen( $l ) + 1;
	while ( $bytes > $resp_max && $lines ) {
		$bytes    -= strlen( array_shift( $lines ) ) + 1;
		$truncated = true;
	}

	$root    = wp_normalize_path( ABSPATH );
	$norm    = wp_normalize_path( $path );
	$display = 0 === strpos( $norm, $root ) ? substr( $norm, strlen( $root ) ) : basename( $norm );

	return array(
		'path_source'   => $source,
		'path'          => $display,
		'size_bytes'    => $size,
		'modified'      => wsp_health_iso( filemtime( $path ) ),
		'lines'         => array_values( $lines ),
		'returned'      => count( $lines ),
		'truncated'     => $truncated,
		'scanned_bytes' => $read,
	);
}
