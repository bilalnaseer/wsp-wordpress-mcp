<?php
/**
 * Widgets & sidebars abilities (classic themes): list widget areas and widget
 * types, read widgets, create/update/move/delete widgets, and set the contents
 * and order of a widget area.
 *
 * Gated by 'edit_theme_options' throughout — the capability Appearance >
 * Widgets and core's REST widgets/sidebars controllers require. Widget areas
 * are a site-wide structure with no per-object ownership.
 *
 * Storage model (core): each widget type keeps its instances in the option
 * `widget_{id_base}` keyed by instance number; the option `sidebars_widgets`
 * maps sidebar id => ordered list of widget ids (`{id_base}-{number}`).
 * `wp_inactive_widgets` holds widgets removed from every area but kept.
 *
 * Sidebars themselves are registered in theme PHP (register_sidebar()) on
 * every load, so they cannot be durably created or deleted at runtime — the
 * sidebar-level write is wsp_update_sidebar (set contents/order, or empty it).
 *
 * Code-insertion guard: every string in a caller-supplied widget instance goes
 * through wp_kses_post() before the widget's own update() runs, so custom_html /
 * text / block widgets can't be used to inject <script> even by admins.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const WSP_WIDGETS_INACTIVE = 'wp_inactive_widgets';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/** WP_Error unless the active theme registers at least one widget area. */
function wsp_widgets_require_areas() {
	global $wp_registered_sidebars;
	if ( empty( $wp_registered_sidebars ) ) {
		return new WP_Error( 'no_widget_areas', 'The active theme registers no widget areas (it is probably a block theme). Edit headers and footers with wsp_get_templates / wsp_update_template instead.' );
	}
	return true;
}

/**
 * True if $sidebar_id is a registered area or the inactive-widgets bin.
 *
 * @param string $sidebar_id Widget area ID.
 * @return bool
 */
function wsp_widgets_is_valid_sidebar( $sidebar_id ) {
	global $wp_registered_sidebars;
	return WSP_WIDGETS_INACTIVE === $sidebar_id || isset( $wp_registered_sidebars[ $sidebar_id ] );
}

/**
 * WP_Widget object for an id_base, or null for unknown / legacy (non-WP_Widget) types.
 *
 * @param string $id_base Widget type ID base, e.g. "text".
 * @return WP_Widget|null
 */
function wsp_widgets_object( $id_base ) {
	global $wp_widget_factory;
	$obj = $wp_widget_factory->get_widget_object( $id_base );
	return $obj instanceof WP_Widget ? $obj : null;
}

/**
 * Whether a widget type's settings may be read/written over MCP.
 *
 * Mirrors core's REST rule: a type must opt in with `show_instance_in_rest`, because some third-party
 * widgets store secrets (API keys) in their instance.
 *
 * @param WP_Widget|null $obj Widget type object.
 * @return bool
 */
function wsp_widgets_is_editable( $obj ) {
	return $obj && ! empty( $obj->widget_options['show_instance_in_rest'] );
}

/**
 * Stored instance array for one widget, or null.
 *
 * @param WP_Widget $obj    Widget type object.
 * @param int       $number Instance number.
 * @return array|null
 */
function wsp_widgets_get_instance( $obj, $number ) {
	if ( ! $obj || ! $number ) return null;
	$all = $obj->get_settings();
	return isset( $all[ $number ] ) && is_array( $all[ $number ] ) ? $all[ $number ] : null;
}

/**
 * Stored sidebar => widget-id-list map, read straight from the `sidebars_widgets`
 * option (the same storage wp_set_sidebars_widgets() writes).
 *
 * Use this instead of core's wp_get_sidebars_widgets(): that function is marked as
 * private ("@access private") and Plugin Check rejects it (Generic.PHP.ForbiddenFunctions).
 * Reading the raw option is also the right input for read-modify-write: core's
 * version runs the `sidebars_widgets` filter, and saving filtered (runtime-only)
 * placements back would make them permanent.
 */
function wsp_widgets_get_sidebars() {
	$sidebars = get_option( 'sidebars_widgets', array() );
	if ( ! is_array( $sidebars ) ) return array();
	unset( $sidebars['array_version'] );
	foreach ( $sidebars as $sidebar_id => $ids ) {
		$sidebars[ $sidebar_id ] = is_array( $ids ) ? array_values( $ids ) : array();
	}
	return $sidebars;
}

/**
 * Sidebar id that currently holds $widget_id, or null.
 *
 * @param string     $widget_id Widget ID, e.g. "text-2".
 * @param array|null $sidebars  Map of sidebar to widget IDs. Defaults to the stored placement.
 * @return string|null
 */
function wsp_widgets_find_sidebar( $widget_id, $sidebars = null ) {
	if ( null === $sidebars ) $sidebars = wsp_widgets_get_sidebars();
	foreach ( $sidebars as $sidebar_id => $ids ) {
		if ( is_array( $ids ) && in_array( $widget_id, $ids, true ) ) return $sidebar_id;
	}
	return null;
}

/**
 * Remove $widget_id from every sidebar, then insert it into $sidebar_id at $position (null/past end =
 * last).
 *
 * @param array    $sidebars   Map of sidebar to widget IDs.
 * @param string   $widget_id  Widget ID.
 * @param string   $sidebar_id Destination widget area.
 * @param int|null $position   Zero-based position. Null or past the end places it last.
 * @return array The updated map.
 */
function wsp_widgets_place( $sidebars, $widget_id, $sidebar_id, $position = null ) {
	foreach ( $sidebars as $sid => $ids ) {
		if ( is_array( $ids ) ) $sidebars[ $sid ] = array_values( array_diff( $ids, array( $widget_id ) ) );
	}
	$list = isset( $sidebars[ $sidebar_id ] ) && is_array( $sidebars[ $sidebar_id ] ) ? $sidebars[ $sidebar_id ] : array();
	$pos  = null === $position ? count( $list ) : max( 0, min( (int) $position, count( $list ) ) );
	array_splice( $list, $pos, 0, array( $widget_id ) );
	$sidebars[ $sidebar_id ] = $list;
	return $sidebars;
}

/**
 * Recursively run wp_kses_post() over every string in a widget instance.
 *
 * @param mixed $value Widget instance data.
 * @return mixed The data with every string filtered by wp_kses_post().
 */
function wsp_widgets_sanitize_instance( $value ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$key         = is_string( $k ) ? sanitize_text_field( $k ) : $k;
			$out[ $key ] = wsp_widgets_sanitize_instance( $v );
		}
		return $out;
	}
	// No wp_unslash(): MCP args are decoded JSON (never slashed), and unslashing would
	// corrupt escaped JSON (e.g. <) inside block-widget comment attributes.
	return is_string( $value ) ? wp_kses_post( $value ) : $value;
}

/**
 * True if $widget_id names a widget that exists (registered, placed, or with a stored instance).
 *
 * @param string $widget_id Widget ID.
 * @return bool
 */
function wsp_widgets_exists( $widget_id ) {
	global $wp_registered_widgets;
	if ( isset( $wp_registered_widgets[ $widget_id ] ) || null !== wsp_widgets_find_sidebar( $widget_id ) ) return true;
	$parsed = wp_parse_widget_id( $widget_id );
	$obj    = wsp_widgets_object( $parsed['id_base'] );
	return null !== wsp_widgets_get_instance( $obj, isset( $parsed['number'] ) ? $parsed['number'] : 0 );
}

/**
 * Normalize one widget to a plain array; $render adds its front-end HTML.
 *
 * @param string   $widget_id  Widget ID.
 * @param string   $sidebar_id Widget area that holds it.
 * @param int|null $position   Zero-based position in the area.
 * @param bool     $render     Include the rendered HTML. Default false.
 * @return array
 */
function wsp_widgets_to_array( $widget_id, $sidebar_id, $position = null, $render = false ) {
	$parsed   = wp_parse_widget_id( $widget_id );
	$obj      = wsp_widgets_object( $parsed['id_base'] );
	$editable = wsp_widgets_is_editable( $obj );
	$data     = array(
		'id'                => $widget_id,
		'id_base'           => $parsed['id_base'],
		'sidebar'           => $sidebar_id,
		'settings_editable' => $editable,
		'settings'          => $editable ? wsp_widgets_get_instance( $obj, isset( $parsed['number'] ) ? $parsed['number'] : 0 ) : null,
	);
	if ( null !== $position ) $data['position'] = $position;
	if ( $render ) {
		$data['rendered'] = ( $sidebar_id && WSP_WIDGETS_INACTIVE !== $sidebar_id ) ? wp_render_widget( $widget_id, $sidebar_id ) : '';
	}
	return $data;
}

/**
 * Append { position, sidebar_widgets } for $widget_id to a response array.
 *
 * @param array  $data       Response array.
 * @param string $widget_id  Widget ID.
 * @param string $sidebar_id Widget area that holds it.
 * @return array
 */
function wsp_widgets_with_position( $data, $widget_id, $sidebar_id ) {
	$sidebars = wsp_widgets_get_sidebars();
	$list     = isset( $sidebars[ $sidebar_id ] ) ? (array) $sidebars[ $sidebar_id ] : array();
	$data['position']        = array_search( $widget_id, $list, true );
	$data['sidebar_widgets'] = array_values( $list );
	return $data;
}

// ---------------------------------------------------------------------------
// Sidebars
// ---------------------------------------------------------------------------

/**
 * Lists the widget areas (sidebars, footers, …) of a classic theme with the ordered widget ids in
 * each, plus wp_inactive_widgets (widgets removed from every area but kept).
 *
 * Returns { sidebars: [{ id, name, description, status, widgets }], total }. A block theme has no
 * widget areas: returns an empty list and a hint to use wsp_get_templates (template parts) instead.
 *
 * MCP tool: `wsp_get_sidebars`. Requires the `edit_theme_options` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_sidebars( $input ) {
	global $wp_registered_sidebars;
	if ( empty( $wp_registered_sidebars ) ) {
		return array(
			'sidebars' => array(),
			'total'    => 0,
			'hint'     => 'The active theme registers no widget areas (probably a block theme). Headers and footers are template parts — use wsp_get_templates with type=template_part.',
		);
	}
	$sidebars = wsp_widgets_get_sidebars();
	$result   = array();
	foreach ( $wp_registered_sidebars as $id => $sb ) {
		$result[] = array(
			'id'          => $id,
			'name'        => $sb['name'],
			'description' => isset( $sb['description'] ) ? $sb['description'] : '',
			'status'      => 'active',
			'widgets'     => isset( $sidebars[ $id ] ) ? array_values( (array) $sidebars[ $id ] ) : array(),
		);
	}
	$result[] = array(
		'id'          => WSP_WIDGETS_INACTIVE,
		'name'        => 'Inactive Widgets',
		'description' => 'Widgets removed from every area, kept with their settings.',
		'status'      => 'inactive',
		'widgets'     => isset( $sidebars[ WSP_WIDGETS_INACTIVE ] ) ? array_values( (array) $sidebars[ WSP_WIDGETS_INACTIVE ] ) : array(),
	);
	return array( 'sidebars' => $result, 'total' => count( $result ) );
}

/**
 * Set a widget area's full contents and order.
 *
 * Listed widgets are moved in from wherever they are; widgets previously in the area but not listed go
 * to wp_inactive_widgets (settings kept). An empty list clears the area.
 *
 * MCP tool: `wsp_update_sidebar`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $sidebar Required. Sidebar id from wsp_get_sidebars.
 *     @type array $widgets Required. Ordered widget ids.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_update_sidebar( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['sidebar'] ) ) return new WP_Error( 'missing_input', 'sidebar is required.' );
	if ( ! isset( $input['widgets'] ) || ! is_array( $input['widgets'] ) ) {
		return new WP_Error( 'missing_input', 'widgets is required: the ordered list of widget ids the area should contain ([] empties it).' );
	}

	$sidebar_id = sanitize_text_field( $input['sidebar'] );
	if ( ! wsp_widgets_is_valid_sidebar( $sidebar_id ) ) return new WP_Error( 'not_found', "Sidebar '{$sidebar_id}' not found. Use wsp_get_sidebars." );

	$wanted = array();
	foreach ( $input['widgets'] as $wid ) {
		$wid = sanitize_text_field( (string) $wid );
		if ( ! wsp_widgets_exists( $wid ) ) return new WP_Error( 'not_found', "Widget '{$wid}' not found." );
		if ( ! in_array( $wid, $wanted, true ) ) $wanted[] = $wid;
	}

	$sidebars = wsp_widgets_get_sidebars();
	$previous = isset( $sidebars[ $sidebar_id ] ) ? (array) $sidebars[ $sidebar_id ] : array();

	foreach ( $sidebars as $sid => $ids ) {
		if ( is_array( $ids ) ) $sidebars[ $sid ] = array_values( array_diff( $ids, $wanted ) );
	}
	$sidebars[ $sidebar_id ] = $wanted;

	$removed = array_values( array_diff( $previous, $wanted ) );
	if ( $removed && WSP_WIDGETS_INACTIVE !== $sidebar_id ) {
		$inactive = isset( $sidebars[ WSP_WIDGETS_INACTIVE ] ) ? (array) $sidebars[ WSP_WIDGETS_INACTIVE ] : array();
		$sidebars[ WSP_WIDGETS_INACTIVE ] = array_values( array_unique( array_merge( $inactive, $removed ) ) );
	}
	wp_set_sidebars_widgets( $sidebars );

	return array(
		'success'          => true,
		'sidebar'          => $sidebar_id,
		'widgets'          => $wanted,
		'moved_to_inactive' => $removed,
	);
}

// ---------------------------------------------------------------------------
// Widgets
// ---------------------------------------------------------------------------

/**
 * Lists registered widget types — the valid id_base values for wsp_create_widget.
 *
 * Returns { widget_types: [{ id, name, description, is_multi, settings_editable }], total }. Only
 * types with settings_editable: true can be created or have their settings changed over MCP.
 *
 * MCP tool: `wsp_get_widget_types`. Requires the `edit_theme_options` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_widget_types( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	global $wp_widget_factory;
	$types = array();
	foreach ( $wp_widget_factory->widgets as $obj ) {
		if ( ! $obj instanceof WP_Widget ) continue;
		$types[] = array(
			'id'                => $obj->id_base,
			'name'              => $obj->name,
			'description'       => isset( $obj->widget_options['description'] ) ? $obj->widget_options['description'] : '',
			'is_multi'          => true,
			'settings_editable' => wsp_widgets_is_editable( $obj ),
		);
	}
	return array( 'widget_types' => $types, 'total' => count( $types ) );
}

/**
 * Lists widgets placed in widget areas with their settings, grouped by sidebar in display order.
 *
 * Returns { widgets: [{ id, id_base, sidebar, position, settings_editable, settings }], total };
 * position is the 0-based index in its sidebar. settings is null for widget types that do not expose
 * them.
 *
 * MCP tool: `wsp_get_widgets`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $sidebar Optional. Only this sidebar (id from wsp_get_sidebars, or
 *         wp_inactive_widgets). Omit for all.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_widgets( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;

	$only = ! empty( $input['sidebar'] ) ? sanitize_text_field( $input['sidebar'] ) : '';
	if ( $only && ! wsp_widgets_is_valid_sidebar( $only ) ) return new WP_Error( 'not_found', "Sidebar '{$only}' not found. Use wsp_get_sidebars." );

	$widgets = array();
	foreach ( wsp_widgets_get_sidebars() as $sidebar_id => $ids ) {
		if ( ! is_array( $ids ) || ( $only && $sidebar_id !== $only ) ) continue;
		foreach ( array_values( $ids ) as $pos => $wid ) {
			$widgets[] = wsp_widgets_to_array( $wid, $sidebar_id, $pos );
		}
	}
	return array( 'widgets' => $widgets, 'total' => count( $widgets ) );
}

/**
 * Gets one widget with its settings and rendered front-end HTML.
 *
 * Returns { id, id_base, sidebar, settings_editable, settings, rendered }.
 *
 * MCP tool: `wsp_get_widget`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $widget_id Required. Widget id, e.g. "block-3", "text-2" (id_base-number), from
 *         wsp_get_widgets or wsp_get_sidebars.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_get_widget( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['widget_id'] ) ) return new WP_Error( 'missing_input', 'widget_id is required.' );
	$widget_id = sanitize_text_field( $input['widget_id'] );
	if ( ! wsp_widgets_exists( $widget_id ) ) return new WP_Error( 'not_found', "Widget '{$widget_id}' not found." );
	return wsp_widgets_to_array( $widget_id, wsp_widgets_find_sidebar( $widget_id ), null, true );
}

/**
 * Creates a widget in a widget area. id_base must be a settings_editable type from
 * wsp_get_widget_types ("block" is recommended for free-form content). position is 0-based; omit (or
 * past the end) to add last.
 *
 * MCP tool: `wsp_create_widget`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $sidebar Required. Sidebar id from wsp_get_sidebars.
 *     @type string $id_base Required. Widget type, e.g. block, custom_html, recent-posts.
 *     @type array $instance Optional. Widget settings keyed by setting name (each type defines its
 *         own). block: {"content": "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->"};
 *         custom_html: {title, content}; text: {title, text, filter}; recent-posts: {title, number,
 *         show_date}; search: {title}; categories / archives: {title, count, dropdown}; nav_menu:
 *         {title, nav_menu: menu id}. All strings are filtered with wp_kses_post.
 *     @type int $position Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_create_widget( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['sidebar'] ) || empty( $input['id_base'] ) ) return new WP_Error( 'missing_input', 'sidebar and id_base are required.' );

	$sidebar_id = sanitize_text_field( $input['sidebar'] );
	if ( ! wsp_widgets_is_valid_sidebar( $sidebar_id ) ) return new WP_Error( 'not_found', "Sidebar '{$sidebar_id}' not found. Use wsp_get_sidebars." );

	$id_base = sanitize_text_field( $input['id_base'] );
	$obj     = wsp_widgets_object( $id_base );
	if ( ! $obj ) return new WP_Error( 'not_found', "Widget type '{$id_base}' not found. Use wsp_get_widget_types." );
	if ( ! wsp_widgets_is_editable( $obj ) ) return new WP_Error( 'read_only', "Widget type '{$id_base}' does not expose its settings (no show_instance_in_rest), so it cannot be created over MCP." );

	$instance = isset( $input['instance'] ) && is_array( $input['instance'] ) ? wsp_widgets_sanitize_instance( $input['instance'] ) : array();
	$instance = $obj->update( $instance, array() );
	if ( false === $instance ) return new WP_Error( 'rejected', "The '{$id_base}' widget rejected these settings." );

	$settings = $obj->get_settings();
	$numbers  = array_filter( array_keys( $settings ), 'is_int' );
	$number   = ( $numbers ? max( $numbers ) : 1 ) + 1;
	$settings[ $number ] = $instance;
	$obj->save_settings( $settings );

	// Register the new instance for this request so wp_render_widget() can render it.
	$obj->_set( $number );
	$obj->_register_one( $number );

	$widget_id = $id_base . '-' . $number;
	$position  = isset( $input['position'] ) ? intval( $input['position'] ) : null;
	wp_set_sidebars_widgets( wsp_widgets_place( wsp_widgets_get_sidebars(), $widget_id, $sidebar_id, $position ) );

	$data = array( 'success' => true ) + wsp_widgets_to_array( $widget_id, $sidebar_id, null, true );
	return null !== $position ? wsp_widgets_with_position( $data, $widget_id, $sidebar_id ) : $data;
}

/**
 * Updates a widget's settings, moves it to another widget area, and/or reorders it. instance is merged
 * over current settings (omitted keys keep their values) — read it first with wsp_get_widget. sidebar
 * moves it (added last unless position is given); position is 0-based within its sidebar.
 *
 * Widgets whose type does not expose settings can be moved but not edited.
 *
 * MCP tool: `wsp_update_widget`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $widget_id Required. Widget id, e.g. "block-3", "text-2" (id_base-number), from
 *         wsp_get_widgets or wsp_get_sidebars.
 *     @type array $instance Optional. Widget settings keyed by setting name (each type defines its
 *         own). block: {"content": "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->"};
 *         custom_html: {title, content}; text: {title, text, filter}; recent-posts: {title, number,
 *         show_date}; search: {title}; categories / archives: {title, count, dropdown}; nav_menu:
 *         {title, nav_menu: menu id}. All strings are filtered with wp_kses_post.
 *     @type string $sidebar Optional. Move to this sidebar. wp_inactive_widgets takes it off the
 *         site but keeps settings.
 *     @type int $position Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_update_widget( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['widget_id'] ) ) return new WP_Error( 'missing_input', 'widget_id is required.' );

	$has_instance = isset( $input['instance'] ) && is_array( $input['instance'] );
	$has_sidebar  = ! empty( $input['sidebar'] );
	$has_position = isset( $input['position'] );
	if ( ! $has_instance && ! $has_sidebar && ! $has_position ) {
		return new WP_Error( 'missing_input', 'Provide at least one of: instance, sidebar, position.' );
	}

	$widget_id = sanitize_text_field( $input['widget_id'] );
	if ( ! wsp_widgets_exists( $widget_id ) ) return new WP_Error( 'not_found', "Widget '{$widget_id}' not found." );
	$parsed  = wp_parse_widget_id( $widget_id );
	$obj     = wsp_widgets_object( $parsed['id_base'] );
	$number  = isset( $parsed['number'] ) ? (int) $parsed['number'] : 0;
	$current = wsp_widgets_find_sidebar( $widget_id );

	$target = $has_sidebar ? sanitize_text_field( $input['sidebar'] ) : $current;
	if ( $has_sidebar && ! wsp_widgets_is_valid_sidebar( $target ) ) return new WP_Error( 'not_found', "Sidebar '{$target}' not found. Use wsp_get_sidebars." );
	if ( ! $target && $has_position ) return new WP_Error( 'missing_input', 'This widget is not in any sidebar; pass sidebar along with position.' );

	if ( $has_instance ) {
		if ( ! wsp_widgets_is_editable( $obj ) || ! $number ) {
			return new WP_Error( 'read_only', "Widget '{$widget_id}' does not expose its settings, so its instance cannot be changed over MCP (it can still be moved or deleted)." );
		}
		$old = wsp_widgets_get_instance( $obj, $number );
		$old = $old ? $old : array();
		// Merge so omitted keys keep their values, then let the widget sanitize as Appearance > Widgets would.
		$new = $obj->update( array_merge( $old, wsp_widgets_sanitize_instance( $input['instance'] ) ), $old );
		if ( false === $new ) return new WP_Error( 'rejected', "The '{$parsed['id_base']}' widget rejected these settings." );
		$settings            = $obj->get_settings();
		$settings[ $number ] = $new;
		$obj->save_settings( $settings );
	}

	if ( $has_sidebar || $has_position ) {
		$position = $has_position ? intval( $input['position'] ) : null;
		wp_set_sidebars_widgets( wsp_widgets_place( wsp_widgets_get_sidebars(), $widget_id, $target, $position ) );
	}

	$data = array( 'success' => true ) + wsp_widgets_to_array( $widget_id, $target, null, true );
	return $has_position ? wsp_widgets_with_position( $data, $widget_id, $target ) : $data;
}

/**
 * Removes a widget from the site. force=false (default) moves it to wp_inactive_widgets, keeping its
 * settings (wsp_update_widget with a sidebar restores it). force=true deletes the widget and its
 * settings permanently — cannot be undone.
 *
 * MCP tool: `wsp_delete_widget`. Requires the `edit_theme_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $widget_id Required. Widget id, e.g. "block-3", "text-2" (id_base-number), from
 *         wsp_get_widgets or wsp_get_sidebars.
 *     @type bool $force Optional. Permanently delete. Default false.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_delete_widget( $input ) {
	$ok = wsp_widgets_require_areas();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['widget_id'] ) ) return new WP_Error( 'missing_input', 'widget_id is required.' );

	$widget_id = sanitize_text_field( $input['widget_id'] );
	if ( ! wsp_widgets_exists( $widget_id ) ) return new WP_Error( 'not_found', "Widget '{$widget_id}' not found." );
	$previous = wsp_widgets_find_sidebar( $widget_id );

	if ( empty( $input['force'] ) ) {
		wp_set_sidebars_widgets( wsp_widgets_place( wsp_widgets_get_sidebars(), $widget_id, WSP_WIDGETS_INACTIVE ) );
		return array( 'success' => true, 'id' => $widget_id, 'deleted' => false, 'sidebar' => WSP_WIDGETS_INACTIVE, 'previous_sidebar' => $previous );
	}

	// Permanent: drop it from every sidebar and delete its stored instance.
	$sidebars = wsp_widgets_get_sidebars();
	foreach ( $sidebars as $sid => $ids ) {
		if ( is_array( $ids ) ) $sidebars[ $sid ] = array_values( array_diff( $ids, array( $widget_id ) ) );
	}
	wp_set_sidebars_widgets( $sidebars );

	$parsed = wp_parse_widget_id( $widget_id );
	$obj    = wsp_widgets_object( $parsed['id_base'] );
	if ( $obj && ! empty( $parsed['number'] ) ) {
		$settings = $obj->get_settings();
		unset( $settings[ (int) $parsed['number'] ] );
		$obj->save_settings( $settings );
	}
	return array( 'success' => true, 'id' => $widget_id, 'deleted' => true, 'previous_sidebar' => $previous );
}
