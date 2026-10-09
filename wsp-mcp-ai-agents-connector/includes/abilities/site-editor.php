<?php
/**
 * Site Editor (Full Site Editing) abilities: read/update Global Styles
 * (the user-level theme.json stored in the wp_global_styles post) and
 * list/read/create/update block templates and template parts.
 *
 * Gated by 'edit_theme_options' throughout — the capability WP core's own
 * global-styles and templates REST controllers require. Like menus, these are
 * site-wide structures with no per-object ownership, so no extra object-level
 * guard applies.
 *
 * Code-insertion guards (WordPress.org policy, same rationale as the Elementor
 * and ACF write guards):
 *   - Global styles: `css` keys (custom CSS) are stripped from caller input, so
 *     custom CSS can't be set through MCP (existing Site Editor CSS is preserved);
 *     callers without unfiltered_html also get WP_Theme_JSON::remove_insecure_properties().
 *   - Templates: caller-supplied content goes through wp_kses_post(). It is NOT
 *     wp_unslash()ed: MCP args are decoded JSON (never slashed), and unslashing
 *     would corrupt escaped JSON (e.g. <) in block-comment attributes.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/** WP_Error unless the active theme is a block theme (Global Styles only exist there). */
function wsp_site_editor_require_block_theme() {
	if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
		return new WP_Error( 'not_block_theme', 'The active theme is not a block theme. Global Styles require a block (Full Site Editing) theme.' );
	}
	return true;
}

/** WP_Error unless the active theme supports block templates (block themes, or classic themes opting in). */
function wsp_site_editor_require_templates() {
	if ( ! function_exists( 'get_block_templates' ) ) {
		return new WP_Error( 'unsupported', 'Block templates require WordPress 5.9 or newer.' );
	}
	$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	if ( ! $block_theme && ! current_theme_supports( 'block-templates' ) ) {
		return new WP_Error( 'not_block_theme', 'The active theme does not support block templates. Activate a block (Full Site Editing) theme.' );
	}
	return true;
}

/**
 * Deep-merge $patch into $base. Associative arrays merge key-by-key; lists
 * (e.g. a colour palette) and scalars replace wholesale; a null value removes
 * the key, so a caller can drop a customization and fall back to the theme.
 */
function wsp_site_editor_merge( $base, $patch ) {
	if ( ! is_array( $base ) ) $base = array();
	foreach ( (array) $patch as $key => $value ) {
		if ( null === $value ) {
			unset( $base[ $key ] );
		} elseif ( is_array( $value ) && ! empty( $value ) && ! wp_is_numeric_array( $value )
			&& isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! wp_is_numeric_array( $base[ $key ] ) ) {
			$base[ $key ] = wsp_site_editor_merge( $base[ $key ], $value );
		} else {
			$base[ $key ] = $value;
		}
	}
	return $base;
}

/** Recursively drop every `css` key (custom CSS) from a styles tree. */
function wsp_site_editor_strip_custom_css( $tree ) {
	if ( ! is_array( $tree ) ) return $tree;
	unset( $tree['css'] );
	foreach ( $tree as $key => $value ) {
		if ( is_array( $value ) ) $tree[ $key ] = wsp_site_editor_strip_custom_css( $value );
	}
	return $tree;
}

/** ID of the active theme's wp_global_styles post (created on first access by core). */
function wsp_site_editor_global_styles_post_id() {
	if ( method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
		return (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
	}
	$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
	return isset( $post['ID'] ) ? (int) $post['ID'] : 0;
}

/** Decode the user-level global styles config stored in the wp_global_styles post. */
function wsp_site_editor_read_user_config( $post_id ) {
	$post   = $post_id ? get_post( $post_id ) : null;
	$config = $post ? json_decode( $post->post_content, true ) : null;
	if ( ! is_array( $config ) ) $config = array();
	return array(
		'title'    => $post ? $post->post_title : '',
		'settings' => isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array(),
		'styles'   => isset( $config['styles'] ) && is_array( $config['styles'] ) ? $config['styles'] : array(),
	);
}

/** Classify a style variation as full, color-only or typography-only (mirrors the Site Editor's grouping). */
function wsp_site_editor_variation_scope( $variation ) {
	$sections = array();
	foreach ( array( 'settings', 'styles' ) as $root ) {
		if ( ! empty( $variation[ $root ] ) && is_array( $variation[ $root ] ) ) {
			$sections = array_merge( $sections, array_keys( $variation[ $root ] ) );
		}
	}
	$sections = array_unique( $sections );
	if ( $sections && ! array_diff( $sections, array( 'color' ) ) )      return 'color';
	if ( $sections && ! array_diff( $sections, array( 'typography' ) ) ) return 'typography';
	return 'full';
}

/**
 * The active theme's style variations (styles/*.json), each with a unique slug.
 * A partial that shares a title with a full variation gets a `-color` /
 * `-typography` suffix; any remaining collision gets `-2`, `-3`, ...
 */
function wsp_site_editor_style_variations() {
	if ( ! method_exists( 'WP_Theme_JSON_Resolver', 'get_style_variations' ) ) return array();
	$raw = (array) WP_Theme_JSON_Resolver::get_style_variations();

	$full_titles = array();
	foreach ( $raw as $v ) {
		if ( 'full' === wsp_site_editor_variation_scope( $v ) && ! empty( $v['title'] ) ) $full_titles[ $v['title'] ] = true;
	}

	$out   = array();
	$taken = array();
	foreach ( $raw as $v ) {
		if ( empty( $v['title'] ) ) continue;
		$scope = wsp_site_editor_variation_scope( $v );
		$base  = sanitize_title( $v['title'] );
		if ( 'full' !== $scope && isset( $full_titles[ $v['title'] ] ) ) $base .= '-' . $scope;
		$slug = $base;
		for ( $n = 2; isset( $taken[ $slug ] ); $n++ ) $slug = $base . '-' . $n;
		$taken[ $slug ] = true;
		$out[] = array(
			'slug'     => $slug,
			'title'    => $v['title'],
			'scope'    => $scope,
			'settings' => isset( $v['settings'] ) && is_array( $v['settings'] ) ? $v['settings'] : array(),
			'styles'   => isset( $v['styles'] ) && is_array( $v['styles'] ) ? $v['styles'] : array(),
		);
	}
	return $out;
}

/** Map the MCP-facing type to the WP post type. */
function wsp_site_editor_template_post_type( $input ) {
	$type = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'template';
	if ( 'template' === $type || 'wp_template' === $type )           return 'wp_template';
	if ( 'template_part' === $type || 'wp_template_part' === $type ) return 'wp_template_part';
	return new WP_Error( 'invalid_type', "type must be 'template' or 'template_part'." );
}

/** Valid template-part areas (header, footer, uncategorized, plus any registered via filter). */
function wsp_site_editor_template_part_areas() {
	if ( ! function_exists( 'get_allowed_block_template_part_areas' ) ) return array( 'header', 'footer', 'uncategorized' );
	return wp_list_pluck( get_allowed_block_template_part_areas(), 'area' );
}

/** Normalize a WP_Block_Template to a plain array. */
function wsp_site_editor_template_to_array( $tpl, $with_content = false ) {
	$is_part = 'wp_template_part' === $tpl->type;
	$data = array(
		'id'             => $tpl->id,
		'slug'           => $tpl->slug,
		'title'          => is_string( $tpl->title ) ? $tpl->title : '',
		'description'    => is_string( $tpl->description ) ? $tpl->description : '',
		'type'           => $is_part ? 'template_part' : 'template',
		'area'           => $is_part && isset( $tpl->area ) ? (string) $tpl->area : '',
		'status'         => $tpl->status,
		'source'         => $tpl->source, // theme | plugin | custom
		'has_theme_file' => (bool) $tpl->has_theme_file,
		'is_customized'  => ! empty( $tpl->wp_id ),
	);
	if ( $with_content ) $data['content'] = $tpl->content;
	return $data;
}

/** Validate an area for a template part; empty input falls back to $default. */
function wsp_site_editor_resolve_area( $input, $default ) {
	if ( ! isset( $input['area'] ) || '' === $input['area'] ) return $default;
	$area = sanitize_key( $input['area'] );
	if ( ! in_array( $area, wsp_site_editor_template_part_areas(), true ) ) {
		return new WP_Error( 'invalid_area', 'area must be one of: ' . implode( ', ', wsp_site_editor_template_part_areas() ) . '.' );
	}
	return $area;
}

/**
 * Insert a wp_template / wp_template_part post for $theme. Terms are set with
 * wp_set_object_terms() rather than tax_input, because wp_insert_post() silently
 * skips tax_input unless the user holds the taxonomy's assign_terms capability.
 */
function wsp_site_editor_insert_template_post( $post_type, $theme, $slug, $title, $content, $description, $area ) {
	$post_id = wp_insert_post( wp_slash( array(
		'post_type'    => $post_type,
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
		'post_excerpt' => $description,
	) ), true );
	if ( is_wp_error( $post_id ) ) return $post_id;
	wp_set_object_terms( $post_id, $theme, 'wp_theme' );
	if ( 'wp_template_part' === $post_type ) wp_set_object_terms( $post_id, $area, 'wp_template_part_area' );
	return $post_id;
}

// ---------------------------------------------------------------------------
// Global Styles
// ---------------------------------------------------------------------------

function wsp_execute_get_global_styles( $input ) {
	$ok = wsp_site_editor_require_block_theme();
	if ( is_wp_error( $ok ) ) return $ok;

	$post_id = wsp_site_editor_global_styles_post_id();
	$origin  = isset( $input['origin'] ) ? sanitize_key( $input['origin'] ) : 'user';

	if ( 'merged' === $origin ) {
		$result = array(
			'id'       => $post_id,
			'origin'   => 'merged',
			'title'    => get_the_title( $post_id ),
			'settings' => wp_get_global_settings(),
			'styles'   => wp_get_global_styles(),
		);
	} else {
		$user   = wsp_site_editor_read_user_config( $post_id );
		$result = array(
			'id'       => $post_id,
			'origin'   => 'user',
			'title'    => $user['title'],
			'settings' => $user['settings'],
			'styles'   => $user['styles'],
		);
	}
	$result['theme'] = get_stylesheet();

	if ( ! empty( $input['include_variations'] ) ) {
		$result['available_variations'] = array();
		foreach ( wsp_site_editor_style_variations() as $v ) {
			$result['available_variations'][] = array( 'slug' => $v['slug'], 'title' => $v['title'], 'scope' => $v['scope'] );
		}
	}
	return $result;
}

function wsp_execute_update_global_styles( $input ) {
	$ok = wsp_site_editor_require_block_theme();
	if ( is_wp_error( $ok ) ) return $ok;

	$has_settings  = isset( $input['settings'] ) && is_array( $input['settings'] );
	$has_styles    = isset( $input['styles'] ) && is_array( $input['styles'] );
	$has_variation = ! empty( $input['variation_slug'] );
	if ( ! $has_settings && ! $has_styles && ! $has_variation ) {
		return new WP_Error( 'missing_input', 'Provide at least one of: settings, styles, variation_slug.' );
	}

	$post_id = wsp_site_editor_global_styles_post_id();
	if ( ! $post_id ) return new WP_Error( 'not_found', 'Could not locate or create the global styles record for the active theme.' );

	$user     = wsp_site_editor_read_user_config( $post_id );
	$settings = $user['settings'];
	$styles   = $user['styles'];
	$replace  = isset( $input['merge'] ) && false === (bool) $input['merge'];

	// 1) Apply a theme style variation. A full variation replaces the user layer (as the
	//    Site Editor's "Browse styles" does); a colour/typography partial merges into it.
	$applied = null;
	if ( $has_variation ) {
		$slug = sanitize_title( $input['variation_slug'] );
		foreach ( wsp_site_editor_style_variations() as $v ) {
			if ( $v['slug'] === $slug ) { $applied = $v; break; }
		}
		if ( ! $applied ) return new WP_Error( 'not_found', "Style variation '{$slug}' not found. Call wsp_get_global_styles with include_variations=true to list them." );
		if ( 'full' === $applied['scope'] ) {
			$settings = $applied['settings'];
			$styles   = $applied['styles'];
		} else {
			$settings = wsp_site_editor_merge( $settings, $applied['settings'] );
			$styles   = wsp_site_editor_merge( $styles, $applied['styles'] );
		}
	}

	// 2) Apply caller-supplied settings/styles on top (merge by default, replace when merge=false).
	//    Custom CSS can never be set through MCP: `css` keys are stripped from caller input.
	if ( $has_settings ) $settings = $replace ? $input['settings'] : wsp_site_editor_merge( $settings, $input['settings'] );
	if ( $has_styles ) {
		$patch = wsp_site_editor_strip_custom_css( $input['styles'] );
		if ( $patch !== $input['styles'] ) {
			// Refuse loudly instead of "succeeding" while silently dropping the CSS.
			return new WP_Error( 'custom_css_not_allowed', 'Custom CSS ("css" keys in styles) cannot be set through MCP and nothing was saved. Remove the "css" key, or add the CSS in Appearance > Editor > Styles > Additional CSS, or in the theme\'s style.css via wsp_upload_theme.' );
		}
		$styles = $replace ? $patch : wsp_site_editor_merge( $styles, $patch );
	}

	// Keep any site-wide custom CSS an admin already saved in the Site Editor — it is neither
	// settable nor removable from here, so a replace or full variation must not wipe it.
	if ( isset( $user['styles']['css'] ) ) $styles['css'] = $user['styles']['css'];

	// 3) Core's own theme.json sanitizer, applied exactly when core applies it (users without
	//    unfiltered_html) — running it for admins would also drop valid non-preset settings.
	$config = array(
		'version'  => WP_Theme_JSON::LATEST_SCHEMA,
		'settings' => $settings,
		'styles'   => $styles,
	);
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$config = WP_Theme_JSON::remove_insecure_properties( $config );
	}

	$stored = array(
		'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
		'isGlobalStylesUserThemeJSON' => true,
		'settings'                    => ! empty( $config['settings'] ) ? $config['settings'] : new stdClass(),
		'styles'                      => ! empty( $config['styles'] ) ? $config['styles'] : new stdClass(),
	);

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => wp_slash( wp_json_encode( $stored ) ),
	), true );
	if ( is_wp_error( $result ) ) return $result;

	if ( method_exists( 'WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) WP_Theme_JSON_Resolver::clean_cached_data();
	if ( function_exists( 'wp_clean_theme_json_cache' ) ) wp_clean_theme_json_cache();

	$saved = wsp_site_editor_read_user_config( $post_id );
	return array(
		'success'           => true,
		'id'                => $post_id,
		'applied_variation' => $applied ? $applied['slug'] : null,
		'settings'          => $saved['settings'],
		'styles'            => $saved['styles'],
	);
}

// ---------------------------------------------------------------------------
// Templates & template parts
// ---------------------------------------------------------------------------

function wsp_execute_get_templates( $input ) {
	$ok = wsp_site_editor_require_templates();
	if ( is_wp_error( $ok ) ) return $ok;
	$post_type = wsp_site_editor_template_post_type( $input );
	if ( is_wp_error( $post_type ) ) return $post_type;

	$area     = isset( $input['area'] ) ? sanitize_key( $input['area'] ) : '';
	$search   = isset( $input['search'] ) ? strtolower( sanitize_text_field( wp_unslash( $input['search'] ) ) ) : '';
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, intval( $input['per_page'] ) ) ) : 10;
	$page     = isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1;

	$items = array();
	foreach ( get_block_templates( array(), $post_type ) as $tpl ) {
		$row = wsp_site_editor_template_to_array( $tpl );
		if ( $area && 'template_part' === $row['type'] && $row['area'] !== $area ) continue;
		if ( '' !== $search ) {
			$hay = strtolower( $row['title'] . ' ' . $row['slug'] . ' ' . $row['description'] );
			if ( false === strpos( $hay, $search ) ) continue;
		}
		$items[] = $row;
	}

	$total = count( $items );
	return array(
		'templates'   => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
		'total'       => $total,
		'total_pages' => (int) ceil( $total / $per_page ),
		'page'        => $page,
		'per_page'    => $per_page,
	);
}

function wsp_execute_get_template( $input ) {
	$ok = wsp_site_editor_require_templates();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['template_id'] ) ) return new WP_Error( 'missing_input', 'template_id is required (format: theme-slug//template-slug).' );
	$post_type = wsp_site_editor_template_post_type( $input );
	if ( is_wp_error( $post_type ) ) return $post_type;

	$tpl = get_block_template( sanitize_text_field( wp_unslash( $input['template_id'] ) ), $post_type );
	if ( ! $tpl ) return new WP_Error( 'not_found', 'Template not found. IDs look like theme-slug//template-slug; check type (template vs template_part).' );
	return wsp_site_editor_template_to_array( $tpl, true );
}

function wsp_execute_create_template( $input ) {
	$ok = wsp_site_editor_require_templates();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['slug'] ) ) return new WP_Error( 'missing_input', 'slug is required.' );
	$post_type = wsp_site_editor_template_post_type( $input );
	if ( is_wp_error( $post_type ) ) return $post_type;

	$slug  = sanitize_title( $input['slug'] );
	$theme = get_stylesheet();
	$id    = $theme . '//' . $slug;
	if ( '' === $slug ) return new WP_Error( 'invalid_slug', 'slug is empty after sanitization.' );
	if ( get_block_template( $id, $post_type ) ) {
		return new WP_Error( 'exists', "'{$id}' already exists. Use wsp_update_template to modify it." );
	}

	$area = 'wp_template_part' === $post_type ? wsp_site_editor_resolve_area( $input, 'uncategorized' ) : '';
	if ( is_wp_error( $area ) ) return $area;

	$post_id = wsp_site_editor_insert_template_post(
		$post_type,
		$theme,
		$slug,
		! empty( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : $slug,
		isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
		isset( $input['description'] ) ? sanitize_text_field( wp_unslash( $input['description'] ) ) : '',
		$area
	);
	if ( is_wp_error( $post_id ) ) return $post_id;

	$tpl = get_block_template( $id, $post_type );
	return array( 'success' => true, 'template' => $tpl ? wsp_site_editor_template_to_array( $tpl, true ) : array( 'id' => $id ) );
}

function wsp_execute_update_template( $input ) {
	$ok = wsp_site_editor_require_templates();
	if ( is_wp_error( $ok ) ) return $ok;
	if ( empty( $input['template_id'] ) ) return new WP_Error( 'missing_input', 'template_id is required (format: theme-slug//template-slug).' );
	$post_type = wsp_site_editor_template_post_type( $input );
	if ( is_wp_error( $post_type ) ) return $post_type;

	$fields = array( 'title', 'content', 'description', 'area' );
	if ( ! array_intersect( $fields, array_keys( $input ) ) ) {
		return new WP_Error( 'missing_input', 'Provide at least one of: title, content, description, area.' );
	}

	$id  = sanitize_text_field( wp_unslash( $input['template_id'] ) );
	$tpl = get_block_template( $id, $post_type );
	if ( ! $tpl ) return new WP_Error( 'not_found', 'Template not found. IDs look like theme-slug//template-slug; check type (template vs template_part).' );

	$title       = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : null;
	$content     = isset( $input['content'] ) ? wp_kses_post( $input['content'] ) : null;
	$description = isset( $input['description'] ) ? sanitize_text_field( wp_unslash( $input['description'] ) ) : null;
	$area        = '';
	if ( 'wp_template_part' === $post_type ) {
		$area = wsp_site_editor_resolve_area( $input, $tpl->area ? $tpl->area : 'uncategorized' );
		if ( is_wp_error( $area ) ) return $area;
	}

	if ( ! empty( $tpl->wp_id ) ) {
		// Already customized: update the existing database copy.
		$args = array( 'ID' => (int) $tpl->wp_id );
		if ( null !== $title )       $args['post_title']   = $title;
		if ( null !== $content )     $args['post_content'] = $content;
		if ( null !== $description ) $args['post_excerpt'] = $description;
		if ( count( $args ) > 1 ) {
			$result = wp_update_post( wp_slash( $args ), true );
			if ( is_wp_error( $result ) ) return $result;
		}
		if ( 'wp_template_part' === $post_type ) wp_set_object_terms( (int) $tpl->wp_id, $area, 'wp_template_part_area' );
	} else {
		// Theme-file template never customized: create the database override the Site Editor
		// would create on first save, seeded from the theme file for any field not supplied.
		$parts   = explode( '//', $tpl->id, 2 );
		$post_id = wsp_site_editor_insert_template_post(
			$post_type,
			$parts[0],
			$tpl->slug,
			null !== $title ? $title : $tpl->title,
			null !== $content ? $content : $tpl->content,
			null !== $description ? $description : $tpl->description,
			$area
		);
		if ( is_wp_error( $post_id ) ) return $post_id;
	}

	$fresh = get_block_template( $tpl->id, $post_type );
	return array( 'success' => true, 'template' => $fresh ? wsp_site_editor_template_to_array( $fresh, true ) : array( 'id' => $tpl->id ) );
}
