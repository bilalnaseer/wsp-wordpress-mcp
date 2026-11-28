<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wsp_uae_is_active' ) ) {
    function wsp_uae_is_active() {
        return defined( 'UAEL_FILE' )
            || defined( 'UAEL_VER' )
            || defined( 'UAE_VER' )
            || class_exists( '\Elementor\Plugin' )
            || class_exists( 'UAEL_Loader' );
    }
}
function wsp_uae_cap($cap = 'edit_posts') { return !current_user_can($cap) ? new WP_Error('forbidden', "Need $cap cap.") : true; }
function wsp_uae_get_opts($key, $default_value = array()) { return get_option($key, $default_value); }
function wsp_uae_set_opts($key, $val) { update_option($key, $val); return array('success'=>true, 'updated'=>$key); }

function wsp_uae_get_registered_widgets() {
    $list = array();
    if ( class_exists('\Elementor\Plugin') ) {
        $wm = \Elementor\Plugin::$instance->widgets_manager;
        if ( $wm && method_exists($wm, 'get_widget_types') ) {
            foreach ( $wm->get_widget_types() as $slug => $widget ) {
                $cats = method_exists($widget, 'get_categories') ? $widget->get_categories() : array();
                $is_uae = (
                    strpos($slug, 'hfe-') === 0 ||
                    strpos($slug, 'hfe_') === 0 ||
                    strpos($slug, 'uael-') === 0 ||
                    in_array('hfe', $cats, true) ||
                    in_array('header-footer-elementor', $cats, true) ||
                    in_array('uael', $cats, true)
                );
                if ( $is_uae ) {
                    $title = method_exists($widget, 'get_title') ? $widget->get_title() : ucwords(str_replace(array('-','_'), ' ', $slug));
                    $icon  = method_exists($widget, 'get_icon') ? $widget->get_icon() : '';
                    $list[$slug] = array('slug'=>$slug, 'title'=>$title, 'icon'=>$icon);
                }
            }
        }
    }
    if ( empty($list) ) {
        $fallback = array(
            'copyright', 'retina',
            'hfe-breadcrumbs-widget', 'hfe-counter',
            'hfe-page-title', 'hfe-site-title', 'hfe-site-tagline',
            'hfe-site-logo', 'hfe-nav-menu', 'hfe-search', 'hfe-cart',
            'hfe-post-info', 'hfe-retina-logo',
            'hfe-scroll-to-top', 'hfe-reading-progress-bar',
        );
        foreach ($fallback as $slug) {
            $title = preg_replace('/^(hfe-|hfe_)/', '', $slug);
            $title = ucwords(str_replace(array('-', '_'), ' ', $title));
            $list[$slug] = array('slug'=>$slug, 'title'=>$title);
        }
    }
    return $list;
}

/* --- 1. WIDGETS --- */
function wsp_execute_uae_widgets_activate($input) { $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c; $w = wsp_uae_get_opts('uae_widgets'); unset($w[sanitize_text_field($input['widget_slug'])]); return wsp_uae_set_opts('uae_widgets', $w); }
function wsp_execute_uae_widgets_deactivate($input) { $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c; $w = wsp_uae_get_opts('uae_widgets'); $w[sanitize_text_field($input['widget_slug'])] = 'disable'; return wsp_uae_set_opts('uae_widgets', $w); }

function wsp_execute_uae_widgets_list($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $registered = wsp_uae_get_registered_widgets();
    $stored     = wsp_uae_get_opts('uae_widgets');
    $result     = array();
    foreach ($registered as $slug => $info) {
        $info['enabled'] = !isset($stored[$slug]) || 'disable' !== $stored[$slug];
        $result[$slug]   = $info;
    }
    return array('widgets'=>$result);
}
function wsp_execute_uae_widgets_bulk_toggle($input) { $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c; return wsp_uae_set_opts('uae_widgets', !empty($input['disable_all']) ? array('all'=>'disable') : array()); }

function wsp_execute_uae_widgets_deactivate_unused($input) {
    $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c;
    $usage       = wsp_execute_uae_widgets_get_usage(array());
    $used_slugs  = isset($usage['usage']) ? array_keys($usage['usage']) : array();
    $registered  = wsp_uae_get_registered_widgets();
    $stored      = wsp_uae_get_opts('uae_widgets');
    $deactivated = 0;
    foreach ($registered as $slug => $info) {
        if ( ! in_array($slug, $used_slugs, true) ) {
            $stored[$slug] = 'disable';
            $deactivated++;
        }
    }
    wsp_uae_set_opts('uae_widgets', $stored);
    return array('success'=>true, 'deactivated'=>$deactivated, 'message'=>"$deactivated unused widgets deactivated.");
}

function wsp_execute_uae_widgets_get_usage($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $usage  = array();
    $all    = wsp_uae_get_registered_widgets();
    $slugs  = array_keys($all);
    $q = new WP_Query(array(
        'post_type'      => array('post','page','elementor_library'),
        'posts_per_page' => 200,
        'post_status'    => 'publish',
        'meta_query'     => array(array('key'=>'_elementor_data', 'compare'=>'EXISTS')),
        'no_found_rows'  => true,
    ));
    foreach ($q->posts as $p) {
        $data = get_post_meta($p->ID, '_elementor_data', true);
        if ( empty($data) ) continue;
        $json = is_string($data) ? @json_decode($data, true) : $data;
        if ( ! is_array($json) ) continue;
        foreach ($slugs as $slug) {
            $pattern = '"widgetType":"' . $slug . '"';
            $count   = substr_count( ( is_string($data) ? $data : wp_json_encode($json) ), $pattern );
            if ( $count > 0 ) {
                if ( ! isset($usage[$slug]) ) $usage[$slug] = 0;
                $usage[$slug] += $count;
            }
        }
    }
    return array('usage'=>$usage);
}

/* --- 2. TEMPLATES --- */
function wsp_execute_uae_templates_list($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $q = new WP_Query(array('post_type'=>'elementor_library', 'posts_per_page'=>intval($input['per_page']??20), 'post_status'=>'publish'));
    $res = array(); foreach($q->posts as $p) { $res[] = array('id'=>$p->ID, 'title'=>$p->post_title, 'type'=>get_post_meta($p->ID, 'ehf_template_type', true)); }
    return array('templates'=>$res);
}
function wsp_execute_uae_templates_create($input) {
    $c = wsp_uae_cap('publish_posts'); if (is_wp_error($c)) return $c;
    $id = wp_insert_post(array('post_title'=>sanitize_text_field($input['title']), 'post_type'=>'elementor_library', 'post_status'=>'publish'));
    update_post_meta($id, 'ehf_template_type', sanitize_text_field($input['type'] ?? 'header')); update_post_meta($id, '_elementor_edit_mode', 'builder');
    return array('success'=>true, 'id'=>$id);
}
function wsp_execute_uae_templates_delete($input) {
    $c = wsp_uae_cap('delete_posts'); if (is_wp_error($c)) return $c;
    $g = wsp_mcp_guard_delete_post($input['id'] ?? 0, 'elementor_library'); if (is_wp_error($g)) return $g;
    return array('success'=>wp_trash_post($g->ID) !== false);
}
function wsp_execute_uae_templates_duplicate($input) {
    $c = wsp_uae_cap('publish_posts'); if (is_wp_error($c)) return $c;
    $p = wsp_mcp_guard_edit_post($input['id'] ?? 0, 'elementor_library'); if (is_wp_error($p)) return $p;
    $id = wp_insert_post(array('post_title'=>$p->post_title.' (Copy)', 'post_type'=>$p->post_type, 'post_status'=>'draft', 'post_content'=>$p->post_content));
    update_post_meta($id, '_elementor_data', get_post_meta($p->ID, '_elementor_data', true));
    return array('success'=>true, 'new_id'=>$id);
}
function wsp_execute_uae_active_get($input) { return wsp_execute_uae_templates_list($input); }
function wsp_execute_uae_templates_get($input) { $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c; $p=wsp_mcp_guard_edit_post($input['id'] ?? 0, 'elementor_library'); if (is_wp_error($p)) return $p; return array('id'=>$p->ID, 'title'=>$p->post_title); }
function wsp_execute_uae_templates_update($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $g = wsp_mcp_guard_edit_post($input['id'] ?? 0, 'elementor_library'); if (is_wp_error($g)) return $g;
    wp_update_post(array('ID'=>$g->ID, 'post_title'=>sanitize_text_field($input['title']))); return array('success'=>true);
}
function wsp_execute_uae_templates_restore($input) { $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c; $g = wsp_mcp_guard_delete_post($input['id'] ?? 0, 'elementor_library'); if (is_wp_error($g)) return $g; return array('success'=>wp_untrash_post($g->ID) !== false); }
function wsp_execute_uae_shortcode_render($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $shortcode = wp_unslash($input['shortcode']);
    return array('html'=>do_shortcode($shortcode));
}

/* --- 3. PAGES --- */
function wsp_execute_uae_pages_list($input) { return function_exists('wsp_execute_get_pages') ? wsp_execute_get_pages($input) : array(); }
function wsp_execute_uae_pages_create($input) { return function_exists('wsp_execute_create_page') ? wsp_execute_create_page($input) : array(); }
function wsp_execute_uae_pages_delete($input) { return function_exists('wsp_execute_delete_page') ? wsp_execute_delete_page($input) : array(); }
function wsp_execute_uae_pages_restore($input) { $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c; $g = wsp_mcp_guard_delete_post($input['id'] ?? 0, 'page'); if (is_wp_error($g)) return $g; return array('success'=>wp_untrash_post($g->ID) !== false); }
function wsp_execute_uae_pages_update_meta($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $g = wsp_mcp_guard_edit_post($input['id'] ?? 0); if (is_wp_error($g)) return $g;
    $mk = sanitize_text_field($input['meta_key']);
    if (is_protected_meta($mk, 'post') || !current_user_can('edit_post_meta', $g->ID, $mk)) return new WP_Error('forbidden', 'This meta key cannot be modified.');
    update_post_meta($g->ID, $mk, sanitize_text_field($input['meta_value'])); return array('success'=>true);
}
function wsp_execute_uae_pages_update_status($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $g = wsp_mcp_guard_edit_post($input['id'] ?? 0, 'page'); if (is_wp_error($g)) return $g;
    $st = wsp_mcp_guard_post_status($g, $input['status']); if (is_wp_error($st)) return $st;
    wp_update_post(array('ID'=>$g->ID, 'post_status'=>sanitize_text_field($input['status']))); return array('success'=>true);
}

/* --- 4. BUILDER & ENGINE --- */
function wsp_execute_uae_builder_get_structure($input) { return function_exists('wsp_execute_elementor_get_page') ? wsp_execute_elementor_get_page($input) : array(); }
function wsp_execute_uae_builder_add_section($input) { return function_exists('wsp_execute_elementor_add_container') ? wsp_execute_elementor_add_container($input) : array(); }
function wsp_execute_uae_builder_insert_widget($input) { return function_exists('wsp_execute_elementor_add_widget') ? wsp_execute_elementor_add_widget($input) : array(); }
function wsp_execute_uae_builder_update_widget($input) { return function_exists('wsp_execute_elementor_update_element') ? wsp_execute_elementor_update_element($input) : array(); }
function wsp_execute_uae_builder_remove_element($input) { return function_exists('wsp_execute_elementor_remove_element') ? wsp_execute_elementor_remove_element($input) : array(); }

function wsp_execute_uae_builder_move_element($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $post_id    = intval($input['post_id']);
    $element_id = sanitize_text_field(wp_unslash($input['element_id']));
    $position   = isset($input['position']) ? intval($input['position']) : null;
    if ( ! function_exists('wsp_elementor_get_data') ) return array('error'=>'Elementor helpers not available.');
    $data = wsp_elementor_get_data($post_id);
    if ( is_wp_error($data) ) return $data;
    if ( ! is_array($data) ) return array('error'=>'Invalid page data structure.');
    $found = function_exists('wsp_elementor_find_by_id') ? wsp_elementor_find_by_id($data, $element_id) : null;
    if ( null === $found || ! is_array($found) ) return array('error'=>"Element $element_id not found.");
    if ( function_exists('wsp_elementor_remove_by_id') ) {
        wsp_elementor_remove_by_id($data, $element_id);
    }
    if ( null !== $position ) {
        if ( function_exists('wsp_elementor_insert_into') ) {
            wsp_elementor_insert_into($data, null, $found, $position);
        } else {
            array_splice($data, $position, 0, array($found));
        }
    } else {
        $data[] = $found;
    }
    if ( function_exists('wsp_elementor_save_data') ) wsp_elementor_save_data($post_id, $data);
    return array('success'=>true, 'message'=>'Element moved.', 'element_id'=>$element_id);
}
function wsp_execute_uae_builder_add_column($input) { $input['type']='column'; return function_exists('wsp_execute_elementor_add_container') ? wsp_execute_elementor_add_container($input) : array(); }
function wsp_uae_sanitize_element_tree($tree) {
    if ( ! is_array($tree) ) return $tree;
    foreach ($tree as $i => $el) {
        if ( ! is_array($el) ) continue;
        if ( isset($el['widgetType']) && function_exists('wsp_elementor_is_blocked_widget') ) {
            if ( wsp_elementor_is_blocked_widget($el['widgetType']) ) {
                unset($tree[$i]);
                continue;
            }
        }
        if ( isset($el['settings']) && function_exists('wsp_elementor_sanitize_settings') ) {
            $tree[$i]['settings'] = wsp_elementor_sanitize_settings($el['settings']);
        }
        if ( isset($el['elements']) && is_array($el['elements']) ) {
            $tree[$i]['elements'] = wsp_uae_sanitize_element_tree($el['elements']);
        }
    }
    return array_values($tree);
}

function wsp_uae_recursive_sanitize($data) {
    if ( is_array($data) ) {
        $out = array();
        foreach ($data as $k => $v) {
            $k = is_string($k) ? sanitize_text_field($k) : $k;
            $out[$k] = wsp_uae_recursive_sanitize($v);
        }
        return $out;
    }
    if ( is_string($data) ) {
        return wp_kses_post($data);
    }
    return $data;
}

function wsp_execute_uae_builder_build($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $tree = json_decode(wp_unslash($input['json_tree']), true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('error'=>'Invalid JSON in json_tree');
    $tree = wsp_uae_sanitize_element_tree($tree);
    if (function_exists('wsp_elementor_save_data')) {
        wsp_elementor_save_data(intval($input['post_id']), $tree);
    }
    return array('success'=>true);
}
function wsp_execute_uae_builder_regenerate_css($input) {
    $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c;
    if(class_exists('\Elementor\Plugin')) { \Elementor\Plugin::$instance->files_manager->clear_cache(); } return array('success'=>true);
}
function wsp_execute_uae_maintenance_clear_cache($input) { return wsp_execute_uae_builder_regenerate_css($input); }

function wsp_execute_uae_builder_undo($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $post_id = intval($input['post_id'] ?? 0);
    if ( $post_id > 0 && function_exists('wp_get_post_revisions') ) {
        $revs = wp_get_post_revisions($post_id, array('numberposts'=>1));
        if ( ! empty($revs) ) {
            $last = reset($revs);
            $data = get_post_meta($last->ID, '_elementor_data', true);
            if ( ! empty($data) ) {
                $tree = json_decode($data, true);
                if ( is_array($tree) ) {
                    $tree = wsp_uae_sanitize_element_tree($tree);
                }
                if ( function_exists('wsp_elementor_save_data') ) {
                    wsp_elementor_save_data($post_id, $tree);
                    return array('success'=>true, 'message'=>'Reverted to previous revision.');
                }
            }
        }
    }
    return array('success'=>true, 'message'=>'Undo executed (no revision data found).');
}

function wsp_execute_uae_builder_get_schema($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $widget_type = isset($input['widget_type']) ? sanitize_text_field(wp_unslash($input['widget_type'])) : '';
    $schema = array('type'=>'object', 'properties'=>new stdClass());
    if ( $widget_type && class_exists('\Elementor\Plugin') ) {
        $wm = \Elementor\Plugin::$instance->widgets_manager;
        if ( $wm && method_exists($wm, 'get_widget_types') ) {
            $types = $wm->get_widget_types();
            if ( isset($types[$widget_type]) ) {
                $widget   = $types[$widget_type];
                $controls = method_exists($widget, 'get_controls') ? $widget->get_controls() : array();
                if ( ! empty($controls) ) {
                    $props = new stdClass();
                    foreach ($controls as $name => $ctrl) {
                        if ( isset($ctrl['tab']) && 'style' === $ctrl['tab'] ) continue;
                        $prop = array('type'=>'string', 'description'=>$ctrl['label']??$name);
                        if ( isset($ctrl['type']) ) {
                            if ( in_array($ctrl['type'], array('number','slider'), true) ) $prop['type'] = 'number';
                            elseif ( 'select' === $ctrl['type'] ) { $prop['type']='string'; if(!empty($ctrl['options']))$prop['enum']=array_values($ctrl['options']); }
                            elseif ( 'switcher' === $ctrl['type'] ) $prop['type'] = 'boolean';
                        }
                        $props->$name = $prop;
                    }
                    $schema['type']       = 'object';
                    $schema['properties'] = $props;
                }
            }
        }
    }
    return array('schema'=>$schema);
}

function wsp_execute_uae_builder_list_widget_types($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $list = array_keys(wsp_uae_get_registered_widgets());
    return array('widgets'=>$list);
}

/* --- 5. SETTINGS, EXTENSIONS & INFO --- */
function wsp_execute_uae_settings_get($input) { $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c; return array('settings'=>wsp_uae_get_opts('uae_general_settings')); }
function wsp_execute_uae_settings_update($input) {
    $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c;
    $settings = json_decode(wp_unslash($input['settings']), true);
    if (json_last_error() !== JSON_ERROR_NONE) return array('error'=>'Invalid JSON in settings');
    $settings = wsp_uae_recursive_sanitize($settings);
    return wsp_uae_set_opts('uae_general_settings', $settings);
}

function wsp_execute_uae_info_get($input) {
    $version = 'unknown';
    $widget_count = 0;
    foreach (array('UAE_VER','UAEL_VER','ULTIMATE_ADDONS_VER','BSF_ULTIMATE_ADDONS_VER','UAEL_VERSION') as $c) {
        if (defined($c)) { $version = constant($c); break; }
    }
    if ('unknown' === $version && function_exists('bsf_get_option')) {
        $info = bsf_get_option(false, 'ultimate-addons-for-elementor');
        if (!empty($info['version'])) $version = $info['version'];
    }
    $widgets = wsp_uae_get_registered_widgets();
    $widget_count = count($widgets);
    return array('version'=>$version, 'status'=>'active', 'registered_widgets'=>$widget_count);
}
function wsp_execute_uae_pro_features($input) { return array('info'=>'UAE Pro adds 40+ premium widgets and extensions.'); }
function wsp_execute_uae_extensions_list($input) { $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c; return array('extensions'=>wsp_uae_get_opts('uae_extensions')); }
function wsp_execute_uae_extensions_toggle($input) {
    $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c;
    $ext = wsp_uae_get_opts('uae_extensions'); $ext[sanitize_text_field($input['extension'])] = rest_sanitize_boolean($input['status']) ? 'enable' : 'disable'; return wsp_uae_set_opts('uae_extensions', $ext);
}
function wsp_execute_uae_theme_get_info($input) { $t = wp_get_theme(); return array('name'=>$t->get('Name'), 'version'=>$t->get('Version')); }
function wsp_execute_uae_theme_set_method($input) { $c = wsp_uae_cap('manage_options'); if (is_wp_error($c)) return $c; return wsp_uae_set_opts('uae_theme_method', sanitize_text_field($input['method'])); }
function wsp_execute_uae_design_system_get_tokens($input) {
    $kit_id = get_option('elementor_active_kit');
    $tokens = $kit_id ? get_post_meta($kit_id, '_elementor_page_settings', true) : array();
    if (empty($tokens)) $tokens = array('message'=>'No Elementor kit found or kit has no custom tokens.');
    return array('tokens'=>$tokens);
}
function wsp_execute_uae_display_rules_get_locations($input) { return array('locations'=>array('entire_site', 'singular', 'archive')); }
function wsp_execute_uae_display_rules_update($input) {
    $c = wsp_uae_cap('edit_posts'); if (is_wp_error($c)) return $c;
    $g = wsp_mcp_guard_edit_post($input['template_id'] ?? 0, 'elementor_library'); if (is_wp_error($g)) return $g;
    update_post_meta($g->ID, 'ehf_target_include', sanitize_text_field($input['rule'])); return array('success'=>true);
}