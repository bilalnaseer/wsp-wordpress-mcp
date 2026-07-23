<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────

function wsp_elementor_is_active() {
    return class_exists( '\Elementor\Plugin' );
}

function wsp_elementor_get_data( $post_id ) {
    if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
        return new WP_Error( 'not_elementor_page', 'This post was not built with Elementor.' );
    }
    $raw  = get_post_meta( $post_id, '_elementor_data', true );
    $data = $raw ? json_decode( $raw, true ) : array();
    return is_array( $data ) ? $data : array();
}

function wsp_elementor_save_data( $post_id, $data ) {
    update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    if ( wsp_elementor_is_active() && isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}

function wsp_elementor_generate_id() {
    return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
}

// ─────────────────────────────────────────────
// WRITE GUARDS — no arbitrary HTML/JS/CSS/PHP
//
// The MCP write tools accept a widget type and a settings object from the
// client. To prevent them being used as a raw code-injection vector (e.g. the
// Elementor "HTML", "Shortcode" or "Code" widgets, or the per-element Custom
// CSS / Custom Attributes controls), incoming writes are constrained here:
//   • code-bearing widget types are rejected outright, and
//   • code-bearing settings keys are stripped while every string value is run
//     through wp_kses_post() so markup like <script> cannot slip in via a
//     normal text field.
// ─────────────────────────────────────────────

// Widget types that can execute or embed raw HTML/JS/CSS/PHP. Never accepted.
function wsp_elementor_blocked_widget_types() {
    return array( 'html', 'shortcode', 'code', 'code-highlight' );
}

function wsp_elementor_is_blocked_widget( $widget_type ) {
    return in_array( strtolower( (string) $widget_type ), wsp_elementor_blocked_widget_types(), true );
}

// Settings keys that carry raw code / arbitrary attributes. Stripped everywhere.
function wsp_elementor_blocked_setting_keys() {
    return array( 'custom_css', '_attributes', 'custom_attributes', '__dynamic__' );
}

// Recursively sanitize an incoming settings array: drop code-bearing keys and
// run every string through wp_kses_post() so <script>/on* handlers can't be
// injected via text fields. Non-string scalars (colors, numbers, booleans) pass
// through unchanged.
function wsp_elementor_sanitize_settings( $settings ) {
    if ( ! is_array( $settings ) ) {
        return array();
    }
    $blocked = wsp_elementor_blocked_setting_keys();
    $clean   = array();
    foreach ( $settings as $key => $value ) {
        if ( in_array( (string) $key, $blocked, true ) ) {
            continue;
        }
        if ( is_array( $value ) ) {
            $clean[ $key ] = wsp_elementor_sanitize_settings( $value );
        } elseif ( is_string( $value ) ) {
            $clean[ $key ] = wp_kses_post( $value );
        } else {
            $clean[ $key ] = $value;
        }
    }
    return $clean;
}

// Recursively find an element by ID.
function wsp_elementor_find_by_id( $elements, $id ) {
    foreach ( $elements as $el ) {
        if ( $el['id'] === $id ) return $el;
        if ( ! empty( $el['elements'] ) ) {
            $found = wsp_elementor_find_by_id( $el['elements'], $id );
            if ( null !== $found ) return $found;
        }
    }
    return null;
}

// Recursively remove an element by ID.
function wsp_elementor_remove_by_id( &$elements, $id ) {
    foreach ( $elements as $i => $el ) {
        if ( $el['id'] === $id ) {
            array_splice( $elements, $i, 1 );
            return true;
        }
        if ( ! empty( $elements[ $i ]['elements'] ) ) {
            if ( wsp_elementor_remove_by_id( $elements[ $i ]['elements'], $id ) ) return true;
        }
    }
    return false;
}

// Recursively merge settings into an element by ID.
function wsp_elementor_update_by_id( &$elements, $id, $settings ) {
    foreach ( $elements as &$el ) {
        if ( $el['id'] === $id ) {
            $el['settings'] = array_merge( isset( $el['settings'] ) ? $el['settings'] : array(), $settings );
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( wsp_elementor_update_by_id( $el['elements'], $id, $settings ) ) return true;
        }
    }
    return false;
}

// Insert a new element into a specific parent by ID.
function wsp_elementor_insert_into( &$elements, $parent_id, $new_element, $position ) {
    foreach ( $elements as &$el ) {
        if ( $el['id'] === $parent_id ) {
            if ( null !== $position ) {
                array_splice( $el['elements'], $position, 0, array( $new_element ) );
            } else {
                $el['elements'][] = $new_element;
            }
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( wsp_elementor_insert_into( $el['elements'], $parent_id, $new_element, $position ) ) return true;
        }
    }
    return false;
}

// Find the ID of the first insertable element (container or column).
function wsp_elementor_first_insertable( $elements ) {
    foreach ( $elements as $el ) {
        $type = isset( $el['elType'] ) ? $el['elType'] : '';
        if ( in_array( $type, array( 'container', 'column' ), true ) ) return $el['id'];
        if ( ! empty( $el['elements'] ) ) {
            $found = wsp_elementor_first_insertable( $el['elements'] );
            if ( $found ) return $found;
        }
    }
    return null;
}

// Build a simplified tree for get-page response.
function wsp_elementor_simplify_tree( $elements ) {
    $result = array();
    foreach ( $elements as $el ) {
        $item = array(
            'id'   => $el['id'],
            'type' => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
        );
        if ( ! empty( $el['widgetType'] ) ) {
            $item['widget_type'] = $el['widgetType'];
        }
        // Surface the first recognisable text setting as a preview.
        $s = isset( $el['settings'] ) ? $el['settings'] : array();
        foreach ( array( 'title', 'text', 'editor', 'html', 'url' ) as $k ) {
            if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
                $item['preview'] = wp_trim_words( wp_strip_all_tags( $s[ $k ] ), 8 );
                break;
            }
        }
        if ( ! empty( $el['elements'] ) ) {
            $item['children'] = wsp_elementor_simplify_tree( $el['elements'] );
        }
        $result[] = $item;
    }
    return $result;
}

// Collect matching elements into $results.
function wsp_elementor_search_tree( $elements, $widget_type, $search, &$results ) {
    foreach ( $elements as $el ) {
        $type_match   = ! $widget_type || ( isset( $el['widgetType'] ) && $el['widgetType'] === $widget_type );
        $search_match = ! $search      || false !== stripos( wp_json_encode( isset( $el['settings'] ) ? $el['settings'] : array() ), $search );

        if ( $type_match && $search_match && ( $widget_type || $search ) ) {
            $results[] = array(
                'id'          => $el['id'],
                'type'        => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
                'widget_type' => isset( $el['widgetType'] ) ? $el['widgetType'] : null,
            );
        }
        if ( ! empty( $el['elements'] ) ) {
            wsp_elementor_search_tree( $el['elements'], $widget_type, $search, $results );
        }
    }
}

// ─────────────────────────────────────────────
// EXECUTE CALLBACKS
// ─────────────────────────────────────────────

function wsp_execute_elementor_list_pages( $input ) {
    $post_type = isset( $input['post_type'] ) ? sanitize_text_field( wp_unslash( $input['post_type'] ) ) : 'page';
    $status    = isset( $input['status'] )    ? sanitize_text_field( wp_unslash( $input['status'] ) )    : 'publish';
    $per_page  = isset( $input['per_page'] )  ? intval( $input['per_page'] ) : 20;

    $q = new WP_Query( array(
        'post_type'      => $post_type,
        'post_status'    => $status,
        'posts_per_page' => $per_page,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required to identify Elementor-built pages; result set is paginated.
        'meta_key'       => '_elementor_edit_mode',
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required to identify Elementor-built pages; result set is paginated.
        'meta_value'     => 'builder',
    ) );

    $pages = array();
    foreach ( $q->posts as $post ) {
        $pages[] = array(
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'url'    => get_permalink( $post->ID ),
            'status' => $post->post_status,
            'type'   => $post->post_type,
        );
    }
    return array( 'pages' => $pages, 'total' => $q->found_posts );
}

function wsp_execute_elementor_get_page( $input ) {
    $post_id = intval( $input['post_id'] );
    $data    = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );
    return array( 'post_id' => $post_id, 'structure' => wsp_elementor_simplify_tree( $data ) );
}

function wsp_execute_elementor_get_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $element = wsp_elementor_find_by_id( $data, $element_id );
    if ( ! $element ) return array( 'success' => false, 'error' => 'Element not found.' );

    return array(
        'id'          => $element['id'],
        'type'        => isset( $element['elType'] )    ? $element['elType']    : 'unknown',
        'widget_type' => isset( $element['widgetType'] ) ? $element['widgetType'] : null,
        'settings'    => isset( $element['settings'] )  ? $element['settings']  : array(),
    );
}

function wsp_execute_elementor_find_element( $input ) {
    $post_id     = intval( $input['post_id'] );
    $widget_type = isset( $input['widget_type'] ) ? sanitize_text_field( wp_unslash( $input['widget_type'] ) ) : '';
    $search      = isset( $input['search'] )      ? sanitize_text_field( wp_unslash( $input['search'] ) )      : '';

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $results = array();
    wsp_elementor_search_tree( $data, $widget_type, $search, $results );
    return array( 'results' => $results, 'total' => count( $results ) );
}

function wsp_execute_elementor_list_templates( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $args     = array(
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
    );
    if ( ! empty( $input['type'] ) ) {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required to filter Elementor templates by type; result set is paginated.
        $args['tax_query'] = array( array(
            'taxonomy' => 'elementor_library_type',
            'field'    => 'slug',
            'terms'    => sanitize_text_field( wp_unslash( $input['type'] ) ),
        ) );
    }
    $q         = new WP_Query( $args );
    $templates = array();
    foreach ( $q->posts as $post ) {
        $templates[] = array(
            'id'    => $post->ID,
            'title' => $post->post_title,
            'type'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
            'date'  => get_the_date( 'Y-m-d', $post->ID ),
        );
    }
    return array( 'templates' => $templates, 'total' => $q->found_posts );
}

function wsp_execute_elementor_update_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );
    $settings   = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $settings   = wsp_elementor_sanitize_settings( $settings );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    if ( ! wsp_elementor_update_by_id( $data, $element_id, $settings ) ) {
        return array( 'success' => false, 'error' => 'Element not found.' );
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $element_id );
}

function wsp_execute_elementor_add_widget( $input ) {
    $post_id      = intval( $input['post_id'] );
    $widget_type  = sanitize_text_field( wp_unslash( $input['widget_type'] ) );
    $container_id = isset( $input['container_id'] ) ? sanitize_text_field( wp_unslash( $input['container_id'] ) ) : null;
    $settings     = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $position     = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    if ( wsp_elementor_is_blocked_widget( $widget_type ) ) {
        return array( 'success' => false, 'error' => sprintf( 'Widget type "%s" is not allowed because it can embed raw HTML, JavaScript, or CSS. Use structured widgets (heading, text-editor, image, button, etc.) instead.', $widget_type ) );
    }
    $settings = wsp_elementor_sanitize_settings( $settings );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $new_widget = array(
        'id'         => wsp_elementor_generate_id(),
        'elType'     => 'widget',
        'widgetType' => $widget_type,
        'settings'   => $settings,
        'elements'   => array(),
    );

    if ( $container_id ) {
        $target = wsp_elementor_find_by_id( $data, $container_id );
        if ( ! $target ) return array( 'success' => false, 'error' => 'Container not found.' );

        // Widgets cannot go directly inside a legacy section — they need a column.
        if ( isset( $target['elType'] ) && 'section' === $target['elType'] ) {
            return array( 'success' => false, 'error' => 'Target is a section (legacy layout). Please target a column inside the section instead.' );
        }

        if ( ! wsp_elementor_insert_into( $data, $container_id, $new_widget, $position ) ) {
            return array( 'success' => false, 'error' => 'Failed to insert widget.' );
        }
    } else {
        $auto_parent = wsp_elementor_first_insertable( $data );
        if ( ! $auto_parent ) {
            return array( 'success' => false, 'error' => 'No container or column found. Create one first or provide container_id.' );
        }
        if ( ! wsp_elementor_insert_into( $data, $auto_parent, $new_widget, null ) ) {
            return array( 'success' => false, 'error' => 'Failed to insert widget.' );
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $new_widget['id'], 'widget_type' => $widget_type );
}

function wsp_execute_elementor_add_container( $input ) {
    $post_id   = intval( $input['post_id'] );
    $type      = isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'container';
    $parent_id = isset( $input['parent_id'] ) ? sanitize_text_field( wp_unslash( $input['parent_id'] ) ) : null;
    $settings  = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $settings  = wsp_elementor_sanitize_settings( $settings );
    $position  = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    // Normalise type — only container (modern) and section (legacy) are valid root types.
	if ( ! in_array( $type, array( 'container', 'section', 'column' ), true ) ) {
        $type = 'container';
    }

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $new_element = array(
        'id'       => wsp_elementor_generate_id(),
        'elType'   => $type,
        'settings' => $settings,
        'elements' => array(),
        'isInner'  => false,
    );

    if ( $parent_id ) {
        if ( ! wsp_elementor_insert_into( $data, $parent_id, $new_element, $position ) ) {
            return array( 'success' => false, 'error' => 'Parent element not found.' );
        }
    } else {
        if ( null !== $position ) {
            array_splice( $data, $position, 0, array( $new_element ) );
        } else {
            $data[] = $new_element;
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $new_element['id'], 'type' => $type );
}

function wsp_execute_elementor_remove_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    if ( ! wsp_elementor_remove_by_id( $data, $element_id ) ) {
        return array( 'success' => false, 'error' => 'Element not found.' );
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'message' => "Element {$element_id} removed." );
}
// ─────────────────────────────────────────────
// ADVANCED DESIGN TOOLS (v2.6.5)
// ─────────────────────────────────────────────

function wsp_execute_elementor_get_active_kit( $input ) {
    if ( ! wsp_elementor_is_active() ) {
        return array( 'success' => false, 'error' => 'Elementor is not active.' );
    }

    $kit_id = get_option( 'elementor_active_kit' );
    if ( ! $kit_id ) {
        return array( 'success' => false, 'error' => 'No active Elementor kit found.' );
    }

    $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $kit_post = get_post( $kit_id );

    $fonts = array();
    $colors = array();
    $layout = array();

    if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
        foreach ( $settings['system_colors'] as $c ) {
            $colors[] = array(
                'title' => isset( $c['title'] ) ? $c['title'] : '',
                'color' => isset( $c['color'] ) ? $c['color'] : '',
            );
        }
    }

    if ( ! empty( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ) {
        foreach ( $settings['system_typography'] as $t ) {
            $fonts[] = array(
                'title'      => isset( $t['title'] ) ? $t['title'] : '',
                'typography_font_family' => isset( $t['typography_font_family'] ) ? $t['typography_font_family'] : '',
                'typography_font_size'   => isset( $t['typography_font_size'] ) ? $t['typography_font_size'] : '',
                'typography_font_weight' => isset( $t['typography_font_weight'] ) ? $t['typography_font_weight'] : '',
            );
        }
    }

    $layout = array(
        'container_width'        => isset( $settings['container_width'] ) ? $settings['container_width'] : array(),
        'space_between_widgets'  => isset( $settings['space_between_widgets'] ) ? $settings['space_between_widgets'] : '',
        'page_title_selector'    => isset( $settings['page_title_selector'] ) ? $settings['page_title_selector'] : '',
        'viewport_lg'            => isset( $settings['viewport_lg'] ) ? $settings['viewport_lg'] : 1025,
        'viewport_md'            => isset( $settings['viewport_md'] ) ? $settings['viewport_md'] : 768,
    );

    return array(
        'success'  => true,
        'kit_id'   => $kit_id,
        'kit_title'=> $kit_post ? $kit_post->post_title : '',
        'colors'   => $colors,
        'fonts'    => $fonts,
        'layout'   => $layout,
        'raw_settings' => $settings,
    );
}

function wsp_execute_elementor_update_active_kit( $input ) {
    if ( ! wsp_elementor_is_active() ) {
        return array( 'success' => false, 'error' => 'Elementor is not active.' );
    }

    $kit_id = get_option( 'elementor_active_kit' );
    if ( ! $kit_id ) {
        return array( 'success' => false, 'error' => 'No active Elementor kit found.' );
    }

    $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $updated = array();
    $settings = wsp_elementor_sanitize_settings( $settings );

    if ( isset( $input['system_colors'] ) && is_array( $input['system_colors'] ) ) {
        $settings['system_colors'] = array();
        foreach ( $input['system_colors'] as $c ) {
            $settings['system_colors'][] = array(
                '_id'   => isset( $c['_id'] ) ? sanitize_text_field( $c['_id'] ) : wsp_elementor_generate_id(),
                'title' => isset( $c['title'] ) ? sanitize_text_field( $c['title'] ) : '',
                'color' => isset( $c['color'] ) ? sanitize_text_field( $c['color'] ) : '',
            );
        }
        $updated[] = 'system_colors';
    }

    if ( isset( $input['container_width'] ) && is_array( $input['container_width'] ) ) {
        $settings['container_width'] = $input['container_width'];
        $updated[] = 'container_width';
    }

    if ( isset( $input['space_between_widgets'] ) ) {
        $settings['space_between_widgets'] = sanitize_text_field( wp_unslash( $input['space_between_widgets'] ) );
        $updated[] = 'space_between_widgets';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No settings provided to update.' );
    }

    update_post_meta( $kit_id, '_elementor_page_settings', $settings );

    if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    return array(
        'success' => true,
        'kit_id'  => $kit_id,
        'updated' => $updated,
    );
}

function wsp_execute_elementor_regenerate_css( $input ) {
    if ( ! wsp_elementor_is_active() ) {
        return array( 'success' => false, 'error' => 'Elementor is not active.' );
    }

    try {
        if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            $all_pages = get_posts( array(
                'post_type'      => array( 'page', 'post', 'elementor_library' ),
                'posts_per_page' => -1,
                'meta_key'       => '_elementor_edit_mode',
                'meta_value'     => 'builder',
                'fields'         => 'ids',
            ) );
            foreach ( $all_pages as $pid ) {
                $css = new \Elementor\Core\Files\CSS\Post( $pid );
                $css->enqueue();
            }
        }

        return array(
            'success' => true,
            'message' => 'Elementor CSS cache cleared and regenerated.',
        );
    } catch ( Exception $e ) {
        return array( 'success' => false, 'error' => $e->getMessage() );
    }
}

function wsp_execute_elementor_get_widget_schema( $input ) {
    if ( ! wsp_elementor_is_active() ) {
        return array( 'success' => false, 'error' => 'Elementor is not active.' );
    }

    $widget_type = isset( $input['widget_type'] ) ? sanitize_text_field( wp_unslash( $input['widget_type'] ) ) : '';

    if ( ! $widget_type ) {
        return array( 'success' => false, 'error' => 'widget_type is required.' );
    }

    if ( wsp_elementor_is_blocked_widget( $widget_type ) ) {
        return array( 'success' => false, 'error' => 'Schema not available for blocked widget types.' );
    }

    $manager = \Elementor\Plugin::$instance->widgets_manager;
    $widget  = $manager->get_widget_types( $widget_type );

    if ( ! $widget ) {
        return array( 'success' => false, 'error' => 'Widget type not found.' );
    }

    $controls = $widget->get_controls();
    $schema   = array(
        'widget_type' => $widget_type,
        'title'       => $widget->get_title(),
        'icon'        => $widget->get_icon(),
        'categories'  => $widget->get_categories(),
    );

    $controls_list = array();
    $key_categories = array(
        'content'  => array(),
        'style'    => array(),
        'layout'   => array(),
        'typography' => array(),
        'background' => array(),
        'border'   => array(),
        'spacing'  => array(),
        'advanced' => array(),
        'other'    => array(),
    );

    foreach ( $controls as $ckey => $control ) {
        $c = array(
            'name'        => $ckey,
            'label'       => isset( $control['label'] ) ? $control['label'] : $ckey,
            'type'        => isset( $control['type'] ) ? $control['type'] : '',
            'description' => isset( $control['description'] ) ? $control['description'] : '',
            'default'     => isset( $control['default'] ) ? $control['default'] : null,
            'selectors'   => isset( $control['selectors'] ) ? $control['selectors'] : null,
        );

        if ( ! empty( $control['options'] ) ) {
            $c['options'] = $control['options'];
        }

        if ( ! empty( $control['condition'] ) ) {
            $c['condition'] = $control['condition'];
        }

        $tab = isset( $control['tab'] ) ? $control['tab'] : '';
        if ( 'content' === $tab ) {
            $key_categories['content'][] = $c;
        } elseif ( 'style' === $tab ) {
            $key_categories['style'][] = $c;
        } else {
            $key_categories['other'][] = $c;
        }

        $controls_list[] = $c;
    }

    $schema['controls_by_tab'] = $key_categories;
    $schema['total_controls']  = count( $controls_list );
    $schema['editable_properties'] = array(
        'margins'    => array( 'margin', '_margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left' ),
        'padding'    => array( 'padding', '_padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left' ),
        'background' => array( 'background_background', 'background_color', 'background_image', 'background_gradient', 'background_overlay' ),
        'border'     => array( 'border_border', '_border_width', '_border_radius', 'border_color' ),
        'typography' => array( 'typography_typography', 'typography_font_family', 'typography_font_size', 'typography_font_weight', 'typography_line_height', 'typography_letter_spacing' ),
        'layout'     => array( 'width', 'height', 'align', 'position', 'z_index' ),
    );

    return array( 'success' => true, 'schema' => $schema );
}

function wsp_elementor_clone_and_reid( $element ) {
    $clone = $element;
    $clone['id'] = wsp_elementor_generate_id();
    if ( ! empty( $clone['elements'] ) && is_array( $clone['elements'] ) ) {
        $clone['elements'] = array();
        foreach ( $element['elements'] as $child ) {
            $clone['elements'][] = wsp_elementor_clone_and_reid( $child );
        }
    }
    return $clone;
}

function wsp_execute_elementor_duplicate_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );
    $parent_id  = isset( $input['parent_id'] ) ? sanitize_text_field( wp_unslash( $input['parent_id'] ) ) : null;
    $position   = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $source = wsp_elementor_find_by_id( $data, $element_id );
    if ( ! $source ) return array( 'success' => false, 'error' => 'Source element not found.' );

    $clone = wsp_elementor_clone_and_reid( $source );

    if ( $parent_id ) {
        if ( ! wsp_elementor_insert_into( $data, $parent_id, $clone, $position ) ) {
            return array( 'success' => false, 'error' => 'Parent element not found.' );
        }
    } else {
        $parent_id = wsp_elementor_find_by_id( $data, $element_id );
        $root_found = false;
        foreach ( $data as $i => $root ) {
            if ( $root['id'] === $element_id ) {
                array_splice( $data, $i + 1, 0, array( $clone ) );
                $root_found = true;
                break;
            }
        }
        if ( ! $root_found ) {
            if ( null !== $position ) {
                array_splice( $data, $position, 0, array( $clone ) );
            } else {
                $data[] = $clone;
            }
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $clone['id'], 'original_id' => $element_id );
}

function wsp_execute_elementor_move_element( $input ) {
    $post_id       = intval( $input['post_id'] );
    $element_id    = sanitize_text_field( wp_unslash( $input['element_id'] ) );
    $new_parent_id = isset( $input['new_parent_id'] ) ? sanitize_text_field( wp_unslash( $input['new_parent_id'] ) ) : null;
    $new_position  = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $element = wsp_elementor_find_by_id( $data, $element_id );
    if ( ! $element ) return array( 'success' => false, 'error' => 'Element not found.' );

    if ( ! wsp_elementor_remove_by_id( $data, $element_id ) ) {
        return array( 'success' => false, 'error' => 'Failed to remove element from old position.' );
    }

    if ( $new_parent_id ) {
        if ( ! wsp_elementor_insert_into( $data, $new_parent_id, $element, $new_position ) ) {
            $data[] = $element;
            return array( 'success' => false, 'error' => 'Target parent not found. Element placed at root level.' );
        }
    } else {
        if ( null !== $new_position ) {
            array_splice( $data, $new_position, 0, array( $element ) );
        } else {
            $data[] = $element;
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $element_id, 'message' => 'Element moved successfully.' );
}

function wsp_execute_elementor_convert_css( $input ) {
    $css_rules = isset( $input['css'] ) ? $input['css'] : array();
    if ( ! is_array( $css_rules ) ) {
        $css_rules = array();
    }

    $result = array();

    foreach ( $css_rules as $property => $value ) {
        $prop = strtolower( trim( $property ) );
        $val  = trim( (string) $value );

        switch ( $prop ) {
            case 'padding':
                $parts = preg_split( '/\s+/', $val );
                if ( 1 === count( $parts ) ) {
                    $unit = 'px';
                    $num = floatval( $parts[0] );
                    $result['_padding'] = array( 'top' => (string) $num, 'right' => (string) $num, 'bottom' => (string) $num, 'left' => (string) $num, 'unit' => $unit, 'isLinked' => true );
                } elseif ( 2 === count( $parts ) ) {
                    $result['_padding'] = array( 'top' => (string) floatval( $parts[0] ), 'right' => (string) floatval( $parts[1] ), 'bottom' => (string) floatval( $parts[0] ), 'left' => (string) floatval( $parts[1] ), 'unit' => 'px', 'isLinked' => false );
                } elseif ( 4 === count( $parts ) ) {
                    $result['_padding'] = array( 'top' => (string) floatval( $parts[0] ), 'right' => (string) floatval( $parts[1] ), 'bottom' => (string) floatval( $parts[2] ), 'left' => (string) floatval( $parts[3] ), 'unit' => 'px', 'isLinked' => false );
                }
                break;

            case 'margin':
                $parts = preg_split( '/\s+/', $val );
                if ( 1 === count( $parts ) ) {
                    $num = floatval( $parts[0] );
                    $result['_margin'] = array( 'top' => (string) $num, 'right' => (string) $num, 'bottom' => (string) $num, 'left' => (string) $num, 'unit' => 'px', 'isLinked' => true );
                } elseif ( 4 === count( $parts ) ) {
                    $result['_margin'] = array( 'top' => (string) floatval( $parts[0] ), 'right' => (string) floatval( $parts[1] ), 'bottom' => (string) floatval( $parts[2] ), 'left' => (string) floatval( $parts[3] ), 'unit' => 'px', 'isLinked' => false );
                }
                break;

            case 'border-radius':
            case 'border_radius':
                $num = floatval( $val );
                $result['_border_radius'] = array( 'top' => (string) $num, 'right' => (string) $num, 'bottom' => (string) $num, 'left' => (string) $num, 'unit' => 'px', 'isLinked' => true );
                break;

            case 'border-width':
                $num = floatval( $val );
                $result['_border_width'] = array( 'top' => (string) $num, 'right' => (string) $num, 'bottom' => (string) $num, 'left' => (string) $num, 'unit' => 'px', 'isLinked' => true );
                break;

            case 'background-color':
            case 'background_color':
                $result['background_background'] = 'classic';
                $result['background_color'] = $val;
                break;

            case 'color':
            case 'text_color':
                $result['title_color'] = $val;
                break;

            case 'font-size':
            case 'font_size':
                $num = floatval( $val );
                $result['typography_font_size'] = array( 'unit' => 'px', 'size' => $num );
                break;

            case 'font-weight':
            case 'font_weight':
                $result['typography_font_weight'] = $val;
                break;

            case 'text-align':
            case 'text_align':
                $result['align'] = $val;
                break;

            case 'width':
                $result['width'] = array( 'unit' => '%' === substr( $val, -1 ) ? '%' : 'px', 'size' => floatval( $val ) );
                break;

            case 'height':
                $result['height'] = array( 'unit' => '%' === substr( $val, -1 ) ? '%' : 'px', 'size' => floatval( $val ) );
                break;

            case 'gap':
            case 'column_gap':
                $num = floatval( $val );
                $result['gap'] = array( 'unit' => 'px', 'size' => $num );
                break;

            case 'border-style':
                $result['border_border'] = $val;
                break;

            case 'border-color':
                $result['border_color'] = $val;
                break;

            case 'opacity':
                $result['opacity'] = array( 'unit' => '%', 'size' => floatval( $val ) * 100 );
                break;

            case 'display':
                $result['display'] = $val;
                break;

            default:
                $result[ $prop ] = $val;
                break;
        }
    }

    return array(
        'success' => true,
        'input_rules'  => $css_rules,
        'elementor_settings' => $result,
        'parsed_count' => count( $result ),
    );
}

function wsp_execute_elementor_get_page_settings( $input ) {
    $post_id = isset( $input['post_id'] ) ? intval( $input['post_id'] ) : 0;
    if ( ! $post_id ) {
        return array( 'success' => false, 'error' => 'post_id is required.' );
    }

    $settings = get_post_meta( $post_id, '_elementor_page_settings', true );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $template = get_post_meta( $post_id, '_wp_page_template', true );

    return array(
        'success'       => true,
        'post_id'       => $post_id,
        'page_template' => $template ?: 'default',
        'settings'      => $settings,
    );
}

function wsp_execute_elementor_update_page_settings( $input ) {
    $post_id = isset( $input['post_id'] ) ? intval( $input['post_id'] ) : 0;
    if ( ! $post_id ) {
        return array( 'success' => false, 'error' => 'post_id is required.' );
    }

    $updated = array();
    $settings = get_post_meta( $post_id, '_elementor_page_settings', true );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    if ( isset( $input['page_template'] ) ) {
        $template = sanitize_text_field( wp_unslash( $input['page_template'] ) );
        update_post_meta( $post_id, '_wp_page_template', $template );

        if ( 'elementor_canvas' === $template ) {
            $settings['template'] = 'elementor_canvas';
        } elseif ( 'elementor_header_footer' === $template ) {
            $settings['template'] = 'elementor_header_footer';
        } else {
            $settings['template'] = 'default';
        }
        $updated[] = 'page_template';
    }

    if ( isset( $input['hide_title'] ) ) {
        $settings['hide_title'] = (bool) $input['hide_title'] ? 'yes' : '';
        $updated[] = 'hide_title';
    }

    if ( isset( $input['content_width'] ) ) {
        $cw = isset( $input['content_width']['size'] ) ? $input['content_width'] : array( 'unit' => 'px', 'size' => floatval( $input['content_width'] ) );
        $settings['content_width'] = $cw;
        $updated[] = 'content_width';
    }

    if ( isset( $input['background_color'] ) ) {
        $settings['background_background'] = 'classic';
        $settings['background_color'] = sanitize_text_field( wp_unslash( $input['background_color'] ) );
        $updated[] = 'background_color';
    }

    if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
        $extra = wsp_elementor_sanitize_settings( $input['settings'] );
        $settings = array_merge( $settings, $extra );
        $updated[] = 'settings';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No settings provided to update.' );
    }

    update_post_meta( $post_id, '_elementor_page_settings', $settings );

    return array(
        'success' => true,
        'post_id' => $post_id,
        'updated' => $updated,
    );
}

function wsp_execute_elementor_copy_styles( $input ) {
    $post_id       = intval( $input['post_id'] );
    $source_id     = sanitize_text_field( wp_unslash( $input['source_id'] ) );
    $destination_id= sanitize_text_field( wp_unslash( $input['destination_id'] ) );
    $merge         = isset( $input['merge'] ) ? (bool) $input['merge'] : false;

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $source = wsp_elementor_find_by_id( $data, $source_id );
    if ( ! $source ) return array( 'success' => false, 'error' => 'Source element not found.' );

    $dest = wsp_elementor_find_by_id( $data, $destination_id );
    if ( ! $dest ) return array( 'success' => false, 'error' => 'Destination element not found.' );

    $source_settings = isset( $source['settings'] ) ? $source['settings'] : array();
    // Strip disallowed keys (custom_css, custom_attributes, __dynamic__, etc.) before
    // propagating source styles to the destination — mirrors update-active-kit / update-page-settings.
    $source_settings = wsp_elementor_sanitize_settings( $source_settings );

    if ( $merge ) {
        $merged = array_merge( isset( $dest['settings'] ) ? $dest['settings'] : array(), $source_settings );
        wsp_elementor_update_by_id( $data, $destination_id, $merged );
    } else {
        wsp_elementor_update_by_id( $data, $destination_id, $source_settings );
    }

    wsp_elementor_save_data( $post_id, $data );

    return array(
        'success'        => true,
        'post_id'        => $post_id,
        'source_id'      => $source_id,
        'destination_id' => $destination_id,
        'merge'          => $merge,
    );
}

function wsp_execute_elementor_get_breakpoints( $input ) {
    if ( ! wsp_elementor_is_active() ) {
        return array( 'success' => false, 'error' => 'Elementor is not active.' );
    }

    $kit_id = get_option( 'elementor_active_kit' );
    $kit_settings = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : array();
    if ( ! is_array( $kit_settings ) ) {
        $kit_settings = array();
    }

    $breakpoints = array(
        'desktop' => array(
            'label' => 'Desktop',
            'min'   => isset( $kit_settings['viewport_lg'] ) ? intval( $kit_settings['viewport_lg'] ) : 1025,
            'max'   => '',
        ),
        'tablet' => array(
            'label' => 'Tablet',
            'min'   => isset( $kit_settings['viewport_md'] ) ? intval( $kit_settings['viewport_md'] ) : 768,
            'max'   => isset( $kit_settings['viewport_lg'] ) ? intval( $kit_settings['viewport_lg'] ) - 1 : 1024,
        ),
        'mobile' => array(
            'label' => 'Mobile',
            'min'   => 0,
            'max'   => isset( $kit_settings['viewport_md'] ) ? intval( $kit_settings['viewport_md'] ) - 1 : 767,
        ),
        'mobile_extra' => array(
            'label' => 'Mobile Extra',
            'min'   => isset( $kit_settings['viewport_mobile'] ) ? intval( $kit_settings['viewport_mobile'] ) : 360,
            'max'   => '',
        ),
    );

    return array(
        'success'     => true,
        'breakpoints' => $breakpoints,
        'description' => 'Use these breakpoints when setting responsive properties. Elementor supports _tablet and _mobile suffixes on settings keys for responsive overrides.',
    );
}
