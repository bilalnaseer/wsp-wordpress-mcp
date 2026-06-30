<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check if Advanced Custom Fields (ACF) is active.
 */
if ( ! function_exists( 'wsp_acf_is_active' ) ) {
    function wsp_acf_is_active() {
        return class_exists( 'ACF' ) || function_exists( 'get_field' );
    }
}

/**
 * Capability checker helper.
 */
function wsp_acf_check_cap( $cap = 'edit_posts' ) {
    if ( ! current_user_can( $cap ) ) {
        return new WP_Error( 'forbidden', sprintf( 'You do not have the "%s" capability.', $cap ) );
    }
    return true;
}

/**
 * Validate and resolve ACF target selectors contextually (Issues 3 & 4 Resolved).
 */
function wsp_acf_validate_target( $target_id, $target_type = 'post', $is_write = false ) {
    if ( ! wsp_acf_is_active() ) {
        return new WP_Error( 'acf_inactive', 'ACF is not active.' );
    }

    // Standardize options page target (Require manage_options for any read/write on Options)
    if ( $target_id === 'option' || $target_id === 'options' || $target_type === 'option' ) {
        $opt_cap_check = wsp_acf_check_cap( 'manage_options' );
        if ( is_wp_error( $opt_cap_check ) ) {
            return $opt_cap_check;
        }
        return 'options';
    }

    // Normalize string identifiers (e.g., 'user_5' -> ID: 5, Type: user)
    $clean_id = $target_id;
    if ( is_string( $target_id ) ) {
        if ( strpos( $target_id, 'user_' ) === 0 ) {
            $clean_id = intval( str_replace( 'user_', '', $target_id ) );
            $target_type = 'user';
        } elseif ( strpos( $target_id, 'term_' ) === 0 || strpos( $target_id, 'category_' ) === 0 ) {
            $clean_id = intval( str_replace( array( 'term_', 'category_' ), '', $target_id ) );
            $target_type = 'term';
        }
    }

    if ( is_numeric( $clean_id ) ) {
        $id = intval( $clean_id );
        if ( $target_type === 'post' || $target_type === 'page' ) {
            $post = get_post( $id );
            if ( ! $post ) {
                return new WP_Error( 'post_not_found', 'Target post/page not found.' );
            }
            
            // Check specific edit_post permission for the target post (Issue 4)
            if ( ! current_user_can( 'edit_post', $id ) ) {
                return new WP_Error( 'forbidden', 'You do not have permission to view or edit this content.' );
            }
            return $id;
        } elseif ( $target_type === 'user' ) {
            $user = get_userdata( $id );
            if ( ! $user ) {
                return new WP_Error( 'user_not_found', 'Target user not found.' );
            }
            
            if ( $is_write ) {
                // Editing user meta requires edit_user capability for that specific user ID (Issue 4)
                if ( ! current_user_can( 'edit_user', $id ) ) {
                    return new WP_Error( 'forbidden', 'You do not have permission to edit this user metadata.' );
                }
            } else {
                // Reading user meta requires list_users capability (or if reading self) (Issue 3)
                if ( ! current_user_can( 'list_users' ) && get_current_user_id() !== $id ) {
                    return new WP_Error( 'forbidden', 'You do not have permission to view this user metadata.' );
                }
            }
            return 'user_' . $id;
        } elseif ( $target_type === 'term' ) {
            $term = get_term( $id );
            if ( is_wp_error( $term ) || ! $term ) {
                return new WP_Error( 'term_not_found', 'Target term not found.' );
            }
            
            // Editing or reading custom taxonomy term meta is restricted to users who manage taxonomies (Issue 3 & 4)
            if ( ! current_user_can( 'manage_categories' ) ) {
                return new WP_Error( 'forbidden', 'You do not have permission to manage terms.' );
            }
            return 'term_' . $id;
        }
    }

    return new WP_Error( 'invalid_target', 'Invalid target configuration.' );
}

/**
 * Dot-notation deep getter helper.
 */
function wsp_acf_get_nested_value( $data, $path ) {
    $keys = explode( '.', $path );
    foreach ( $keys as $key ) {
        if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
            $data = $data[ $key ];
        } elseif ( is_object( $data ) && isset( $data->$key ) ) {
            $data = $data->$key;
        } else {
            return null;
        }
    }
    return $data;
}

/**
 * Dot-notation deep setter helper.
 */
function wsp_acf_set_nested_value( &$data, $path, $value ) {
    $keys = explode( '.', $path );
    $temp = &$data;
    foreach ( $keys as $key ) {
        if ( ! is_array( $temp ) ) {
            $temp = array();
        }
        if ( ! isset( $temp[ $key ] ) ) {
            $temp[ $key ] = array();
        }
        $temp = &$temp[ $key ];
    }
    $temp = $value;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. FIELD GROUPS (CRUD)
// ─────────────────────────────────────────────────────────────────────────────

function wsp_execute_acf_list_field_groups( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $groups = acf_get_field_groups();
    return array( 'success' => true, 'groups' => $groups ? $groups : array() );
}

function wsp_execute_acf_get_field_group( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $key = sanitize_text_field( $input['key'] );
    $group = acf_get_field_group( $key );
    if ( ! $group ) {
        return new WP_Error( 'group_not_found', 'Field group not found.' );
    }
    return array( 'success' => true, 'group' => $group );
}

function wsp_execute_acf_create_field_group( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $group = array(
        'title'    => sanitize_text_field( $input['title'] ),
        'key'      => isset( $input['key'] ) ? sanitize_text_field( $input['key'] ) : uniqid( 'group_' ),
        'fields'   => isset( $input['fields'] ) ? $input['fields'] : array(),
        'location' => isset( $input['location'] ) ? $input['location'] : array(),
        'active'   => isset( $input['active'] ) ? (bool) $input['active'] : true,
    );

    $saved = acf_update_field_group( $group );
    return array( 'success' => true, 'group' => $saved );
}

function wsp_execute_acf_update_field_group( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $key = sanitize_text_field( $input['key'] );
    $existing = acf_get_field_group( $key );
    if ( ! $existing ) {
        return new WP_Error( 'group_not_found', 'Field group not found.' );
    }

    if ( isset( $input['title'] ) ) $existing['title'] = sanitize_text_field( $input['title'] );
    if ( isset( $input['location'] ) ) $existing['location'] = $input['location'];
    if ( isset( $input['active'] ) ) $existing['active'] = (bool) $input['active'];

    $saved = acf_update_field_group( $existing );
    return array( 'success' => true, 'group' => $saved );
}

function wsp_execute_acf_delete_field_group( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $key = sanitize_text_field( $input['key'] );
    if ( acf_delete_field_group( $key ) ) {
        return array( 'success' => true, 'message' => 'Field group deleted.' );
    }
    return new WP_Error( 'delete_failed', 'Failed to delete field group.' );
}

function wsp_execute_acf_import_field_groups( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $json = $input['json_data'];
    $data = json_decode( $json, true );
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_json', 'Invalid JSON payload.' );
    }

    $imported = array();
    $groups = isset( $data['key'] ) ? array( $data ) : $data;

    foreach ( $groups as $group ) {
        if ( isset( $group['key'] ) ) {
            $imported[] = acf_import_field_group( $group );
        }
    }

    return array( 'success' => true, 'imported' => $imported );
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. FIELDS MANAGEMENT (CRUD)
// ─────────────────────────────────────────────────────────────────────────────

function wsp_execute_acf_list_fields( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $group_key = sanitize_text_field( $input['group_key'] );
    $fields = acf_get_fields( $group_key );
    return array( 'success' => true, 'fields' => $fields ? $fields : array() );
}

function wsp_execute_acf_get_field( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_key = sanitize_text_field( $input['field_key'] );
    $field = acf_get_field( $field_key );
    if ( ! $field ) {
        return new WP_Error( 'field_not_found', 'Field not found.' );
    }
    return array( 'success' => true, 'field' => $field );
}

function wsp_execute_acf_create_field( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field = $input['field_config'];
    if ( ! isset( $field['parent'] ) || ! isset( $field['name'] ) || ! isset( $field['type'] ) ) {
        return new WP_Error( 'missing_params', 'Field config must contain parent, name, and type.' );
    }

    $saved = acf_update_field( $field );
    return array( 'success' => true, 'field' => $saved );
}

/**
 * Update configuration for a single field.
 */
function wsp_execute_acf_update_field_config( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_key = sanitize_text_field( $input['field_key'] );
    $config = $input['config'];

    $field = acf_get_field( $field_key );
    if ( ! $field ) {
        return new WP_Error( 'field_not_found', 'Field configuration not found.' );
    }

    $merged = array_merge( $field, $config );
    $saved = acf_update_field( $merged );
    return array( 'success' => true, 'field' => $saved );
}

function wsp_execute_acf_delete_field( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_key = sanitize_text_field( $input['field_key'] );
    if ( acf_delete_field( $field_key ) ) {
        return array( 'success' => true, 'message' => 'Field deleted.' );
    }
    return new WP_Error( 'delete_failed', 'Failed to delete field.' );
}

function wsp_execute_acf_duplicate_field( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_key = sanitize_text_field( $input['field_key'] );
    $parent_id = isset( $input['parent_id'] ) ? sanitize_text_field( $input['parent_id'] ) : '';

    $field = acf_get_field( $field_key );
    if ( ! $field ) {
        return new WP_Error( 'field_not_found', 'Field not found to duplicate.' );
    }

    $duplicate = acf_duplicate_field( $field, $parent_id );
    return array( 'success' => true, 'duplicated_field' => $duplicate );
}

function wsp_execute_acf_sync_fields( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    if ( ! function_exists( 'acf_get_instance' ) ) {
        return new WP_Error( 'unsupported', 'ACF framework functions are unavailable.' );
    }

    do_action( 'acf/include_fields' );
    return array( 'success' => true, 'message' => 'ACF fields synchronization triggered.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. VALUES & METADATA (WITH CONTEXTUAL CAPABILITY CHECKS)
// ─────────────────────────────────────────────────────────────────────────────

function wsp_execute_acf_get_value_deep( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check
    if ( is_wp_error( $selector ) ) return $selector;

    $field_name = sanitize_text_field( $input['field_name'] );
    $path       = isset( $input['path'] ) ? sanitize_text_field( $input['path'] ) : '';

    $raw_val = get_field( $field_name, $selector );
    if ( empty( $path ) ) {
        return array( 'success' => true, 'value' => $raw_val );
    }

    $deep_val = wsp_acf_get_nested_value( $raw_val, $path );
    return array( 'success' => true, 'value' => $deep_val, 'path' => $path );
}

function wsp_execute_acf_update_value_deep( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check
    if ( is_wp_error( $selector ) ) return $selector;

    $field_name = sanitize_text_field( $input['field_name'] );
    $path       = isset( $input['path'] ) ? sanitize_text_field( $input['path'] ) : '';
    $value      = wp_unslash( $input['value'] );

    if ( empty( $path ) ) {
        update_field( $field_name, $value, $selector );
        return array( 'success' => true, 'value' => get_field( $field_name, $selector ) );
    }

    $root_val = get_field( $field_name, $selector );
    wsp_acf_set_nested_value( $root_val, $path, $value );
    update_field( $field_name, $root_val, $selector );

    return array(
        'success' => true,
        'path'    => $path,
        'value'   => wsp_acf_get_nested_value( get_field( $field_name, $selector ), $path )
    );
}

function wsp_execute_acf_delete_value( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check
    if ( is_wp_error( $selector ) ) return $selector;

    $field_name = sanitize_text_field( $input['field_name'] );
    delete_field( $field_name, $selector );

    return array( 'success' => true, 'message' => sprintf( 'Field value "%s" deleted.', $field_name ) );
}

function wsp_execute_acf_get_all_values( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check
    if ( is_wp_error( $selector ) ) return $selector;

    $fields = get_fields( $selector );
    return array( 'success' => true, 'fields' => $fields ? $fields : array() );
}

function wsp_execute_acf_bulk_update_values( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check
    if ( is_wp_error( $selector ) ) return $selector;

    $fields = $input['fields'];
    if ( ! is_array( $fields ) ) {
        return new WP_Error( 'invalid_fields', 'Fields payload must be a key-value list.' );
    }

    $updated = array();
    foreach ( $fields as $key => $val ) {
        $clean_val = wp_unslash( $val );
        update_field( $key, $clean_val, $selector );
        $updated[ $key ] = $clean_val;
    }

    return array( 'success' => true, 'updated' => $updated );
}

function wsp_execute_acf_get_field_object( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check
    if ( is_wp_error( $selector ) ) return $selector;

    $field_selector = sanitize_text_field( $input['field_selector'] );
    $obj = get_field_object( $field_selector, $selector );

    if ( ! $obj ) {
        return new WP_Error( 'not_found', 'Field object configuration not found.' );
    }

    return array( 'success' => true, 'field_object' => $obj );
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. CUSTOM POST TYPES & TAXONOMIES
// ─────────────────────────────────────────────────────────────────────────────

function wsp_execute_acf_list_post_types( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $post_types = get_post_types( array(), 'objects' );
    $data = array();
    foreach ( $post_types as $slug => $obj ) {
        $data[ $slug ] = array(
            'label'        => $obj->label,
            'public'       => $obj->public,
            'hierarchical' => $obj->hierarchical,
            'has_archive'  => $obj->has_archive,
        );
    }
    return array( 'success' => true, 'post_types' => $data );
}

function wsp_execute_acf_create_post_type( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $post_type_slug = sanitize_key( $input['post_type_slug'] );
    $singular       = sanitize_text_field( $input['singular_name'] );
    $plural         = sanitize_text_field( $input['plural_name'] );

    if ( function_exists( 'acf_update_post_type' ) ) {
        $config = array(
            'key'           => 'post_type_' . $post_type_slug,
            'post_type'     => $post_type_slug,
            'title'         => $plural,
            'singular_name' => $singular,
            'plural_name'   => $plural,
            'active'        => true,
            'public'        => true,
        );
        acf_update_post_type( $config );
        return array( 'success' => true, 'message' => sprintf( 'CPT "%s" created successfully.', $post_type_slug ), 'config' => $config );
    }

    return new WP_Error( 'unsupported', 'Programmatic ACF post type creation requires ACF 6.1+.' );
}

function wsp_execute_acf_list_taxonomies( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $taxonomies = get_taxonomies( array(), 'objects' );
    $data = array();
    foreach ( $taxonomies as $slug => $obj ) {
        $data[ $slug ] = array(
            'label'        => $obj->label,
            'public'       => $obj->public,
            'hierarchical' => $obj->hierarchical,
            'post_types'   => $obj->object_type,
        );
    }
    return array( 'success' => true, 'taxonomies' => $data );
}

function wsp_execute_acf_create_taxonomy( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $taxonomy_slug = sanitize_key( $input['taxonomy_slug'] );
    $singular      = sanitize_text_field( $input['singular_name'] );
    $plural        = sanitize_text_field( $input['plural_name'] );
    $post_types    = array_map( 'sanitize_key', $input['post_types'] );

    if ( function_exists( 'acf_update_taxonomy' ) ) {
        $config = array(
            'key'           => 'taxonomy_' . $taxonomy_slug,
            'taxonomy'      => $taxonomy_slug,
            'title'         => $plural,
            'singular_name' => $singular,
            'plural_name'   => $plural,
            'object_type'   => $post_types,
            'active'        => true,
            'public'        => true,
        );
        acf_update_taxonomy( $config );
        return array( 'success' => true, 'message' => sprintf( 'Taxonomy "%s" registered.', $taxonomy_slug ), 'config' => $config );
    }

    return new WP_Error( 'unsupported', 'Programmatic ACF taxonomy registration requires ACF 6.1+.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. OPTIONS PAGES
// ─────────────────────────────────────────────────────────────────────────────

function wsp_execute_acf_list_options_pages( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    if ( ! function_exists( 'acf_get_options_pages' ) ) {
        return array( 'success' => true, 'options_pages' => array() );
    }

    $pages = acf_get_options_pages();
    return array( 'success' => true, 'options_pages' => $pages ? $pages : array() );
}

function wsp_execute_acf_create_options_page( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    if ( ! function_exists( 'acf_add_options_page' ) ) {
        return new WP_Error( 'unsupported', 'ACF Options Page requires ACF Pro.' );
    }

    $args = array(
        'page_title' => sanitize_text_field( $input['page_title'] ),
        'menu_title' => isset( $input['menu_title'] ) ? sanitize_text_field( $input['menu_title'] ) : sanitize_text_field( $input['page_title'] ),
        'menu_slug'  => isset( $input['menu_slug'] ) ? sanitize_key( $input['menu_slug'] ) : sanitize_title( $input['page_title'] ),
        'capability' => 'edit_posts',
        'redirect'   => false,
    );

    $page = acf_add_options_page( $args );
    return array( 'success' => true, 'options_page' => $page );
}

function wsp_execute_acf_get_option_value( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_name = sanitize_text_field( $input['field_name'] );
    $val = get_field( $field_name, 'options' );
    return array( 'success' => true, 'field_name' => $field_name, 'value' => $val );
}

function wsp_execute_acf_update_option_value( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_name = sanitize_text_field( $input['field_name'] );
    $value = wp_unslash( $input['value'] );

    update_field( $field_name, $value, 'options' );
    return array( 'success' => true, 'field_name' => $field_name, 'value' => get_field( $field_name, 'options' ) );
}

// Note: no options-page delete tool — ACF options pages are re-registered on every load,
// so a runtime delete cannot persist.

// ─────────────────────────────────────────────────────────────────────────────
// REGISTRATION (Back-compat / Dual Mode registration)
// ─────────────────────────────────────────────────────────────────────────────

function wsp_register_acf_abilities() {
    if ( ! wsp_acf_is_active() ) return;

    $base      = array( 'category' => 'wsp', 'output_schema' => array( 'type' => 'object' ), 'meta' => array( 'mcp' => array( 'public' => true ) ) );
    $can_edit  = function() { return current_user_can( 'edit_posts' ); };
    $can_admin = function() { return current_user_can( 'manage_options' ); };

    $mapping = array(
        'wsp/acf-list-field-groups'    => array( 'label' => 'List ACF Field Groups',    'desc' => 'List field groups.',  'cap' => $can_edit,  'callback' => 'wsp_execute_acf_list_field_groups' ),
        'wsp/acf-get-field-group'     => array( 'label' => 'Get ACF Field Group',     'desc' => 'Get field group config.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_get_field_group' ),
        'wsp/acf-create-field-group'  => array( 'label' => 'Create ACF Field Group',  'desc' => 'Create field group.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_create_field_group' ),
        'wsp/acf-update-field-group'  => array( 'label' => 'Update ACF Field Group',  'desc' => 'Update field group.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_update_field_group' ),
        'wsp/acf-delete-field-group'  => array( 'label' => 'Delete ACF Field Group',  'desc' => 'Delete field group.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_delete_field_group' ),
        'wsp/acf-import-field-groups' => array( 'label' => 'Import ACF Field Groups',  'desc' => 'Import JSON group configs.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_import_field_groups' ),
        'wsp/acf-list-fields'         => array( 'label' => 'List Fields inside Group', 'desc' => 'List all registered fields configs inside a specific field group.', 'cap' => $can_edit,  'callback' => 'wsp_execute_acf_list_fields' ),
        'wsp/acf-get-field'           => array( 'label' => 'Get Field Config Details', 'desc' => 'Fetch direct key attributes and parameters for a custom field.', 'cap' => $can_edit,  'callback' => 'wsp_execute_acf_get_field' ),
        'wsp/acf-create-field'        => array( 'label' => 'Create Field Configuration', 'desc' => 'Register a new field inside an existing custom group.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_create_field' ),
        'wsp/acf-update-field-config' => array( 'label' => 'Update Field Configuration', 'desc' => 'Update schema configuration for a custom field.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_update_field_config' ),
        'wsp/acf-delete-field'        => array( 'label' => 'Delete ACF Field Config', 'desc' => 'Delete field configuration.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_delete_field' ),
        'wsp/acf-duplicate-field'     => array( 'label' => 'Duplicate ACF Field',     'desc' => 'Duplicate an existing field config.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_duplicate_field' ),
        'wsp/acf-sync-fields'         => array( 'label' => 'Sync ACF Fields',         'desc' => 'Sync fields structure.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_sync_fields' ),
        'wsp/acf-get-value-deep'      => array( 'label' => 'Get ACF Value Deep',      'desc' => 'Deep read with dot notation.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_get_value_deep' ),
        'wsp/acf-update-value-deep'   => array( 'label' => 'Update ACF Value Deep',   'desc' => 'Deep write with dot notation.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_update_value_deep' ),
        'wsp/acf-delete-value'        => array( 'label' => 'Delete ACF Value',        'desc' => 'Delete field value metadata.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_delete_value' ),
        'wsp/acf-get-all-values'      => array( 'label' => 'Get All ACF Values',      'desc' => 'Get all fields values.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_get_all_values' ),
        'wsp/acf-bulk-update-values'  => array( 'label' => 'Bulk Update ACF Values',  'desc' => 'Bulk update values.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_bulk_update_values' ),
        'wsp/acf-get-field-object'    => array( 'label' => 'Get ACF Field Object',    'desc' => 'Get metadata with values.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_get_field_object' ),
        'wsp/acf-list-post-types'     => array( 'label' => 'List Custom Post Types',  'desc' => 'List CPTs.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_list_post_types' ),
        'wsp/acf-create-post-type'    => array( 'label' => 'Create Custom Post Type', 'desc' => 'Programmatically register a CPT.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_create_post_type' ),
        'wsp/acf-list-taxonomies'     => array( 'label' => 'List Taxonomies',         'desc' => 'List taxonomies.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_list_taxonomies' ),
        'wsp/acf-create-taxonomy'     => array( 'label' => 'Create Taxonomy',         'desc' => 'Programmatically register taxonomy.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_create_taxonomy' ),
        'wsp/acf-list-options-pages'  => array( 'label' => 'List Options Pages',      'desc' => 'List ACF Options pages.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_list_options_pages' ),
        'wsp/acf-create-options-page' => array( 'label' => 'Create Options Page',    'desc' => 'Register Options Page.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_create_options_page' ),
        'wsp/acf-get-option-value'    => array( 'label' => 'Get Options Page Value',  'desc' => 'Get value from options page.', 'cap' => $can_edit, 'callback' => 'wsp_execute_acf_get_option_value' ),
        'wsp/acf-update-option-value' => array( 'label' => 'Update Options Value',    'desc' => 'Update value on options page.', 'cap' => $can_admin, 'callback' => 'wsp_execute_acf_update_option_value' ),
    );

    foreach ( $mapping as $key => $data ) {
        if ( wsp_mcp_is_enabled( $key ) ) {
            wp_register_ability( $key, array_merge( $base, array(
                'label'               => $data['label'],
                'description'         => $data['desc'],
                'permission_callback' => $data['cap'],
                'execute_callback'    => $data['callback'],
            ) ) );
        }
    }
}
