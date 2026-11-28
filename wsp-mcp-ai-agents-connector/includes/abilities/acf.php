<?php
/**
 * Advanced Custom Fields abilities: field groups, fields, values, post types, taxonomies and options pages.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check if Advanced Custom Fields (ACF) is active.
 */
if ( ! function_exists( 'wsp_acf_is_active' ) ) {
    /**
     * Whether Advanced Custom Fields is active.
     *
     * @return bool True when ACF is loaded.
     */
    function wsp_acf_is_active() {
        return class_exists( 'ACF' ) || function_exists( 'get_field' );
    }
}

/**
 * Capability checker helper.
 *
 * @param string $cap Capability to require. Default "edit_posts".
 * @return true|WP_Error True when the current user has the capability.
 */
function wsp_acf_check_cap( $cap = 'edit_posts' ) {
    if ( ! current_user_can( $cap ) ) {
        return new WP_Error( 'forbidden', sprintf( 'You do not have the "%s" capability.', $cap ) );
    }
    return true;
}

/**
 * Validate and resolve ACF target selectors contextually (Issues 3 & 4 Resolved).
 *
 * @param int|string $target_id   Post, page, user or term ID, a "user_5" / "term_5" style string,
 *                                or "options".
 * @param string     $target_type One of post, page, user, term or option(s). Default "post".
 * @param bool       $is_write    Whether the caller intends to modify the target, which needs a
 *                                stricter capability. Default false.
 * @return int|string|WP_Error The target in the form ACF expects, or a WP_Error when the user may
 *        not access it.
 */
function wsp_acf_validate_target( $target_id, $target_type = 'post', $is_write = false ) {
    if ( ! wsp_acf_is_active() ) {
        return new WP_Error( 'acf_inactive', 'ACF is not active.' );
    }

    // Standardize options page target (Require manage_options for any read/write on Options).
    if ( $target_id === 'option' || $target_id === 'options' || $target_type === 'option' ) {
        $opt_cap_check = wsp_acf_check_cap( 'manage_options' );
        if ( is_wp_error( $opt_cap_check ) ) {
            return $opt_cap_check;
        }
        return 'options';
    }

    // Normalize string identifiers (e.g., 'user_5' -> ID: 5, Type: user).
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
            
            // Check specific edit_post permission for the target post (Issue 4).
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
                // Editing user meta requires edit_user capability for that specific user ID (Issue 4).
                if ( ! current_user_can( 'edit_user', $id ) ) {
                    return new WP_Error( 'forbidden', 'You do not have permission to edit this user metadata.' );
                }
            } else {
                // Reading user meta requires list_users capability (or if reading self) (Issue 3).
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
            
            // Editing or reading custom taxonomy term meta is restricted to users who manage taxonomies (Issue 3 & 4).
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
 *
 * @param mixed  $data Array or object to walk.
 * @param string $path Dot-separated path, e.g. "repeater.0.text_field".
 * @return mixed The value at the path, or null when the path does not exist.
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
 * Recursively sanitize a value destined for ACF storage.
 *
 * ACF field values may be scalars or nested arrays (repeaters, groups, flexible content). To stop the
 * MCP write tools from being used to store executable payloads, every string is passed through
 * wp_kses_post(), which removes <script>/<style> tags and on* event-handler attributes while
 * preserving the post-safe HTML that legitimate WYSIWYG fields rely on. Non-string scalars (int,
 * float, bool, null) carry no executable payload and are returned as-is.
 *
 * @param mixed $value Field value: a scalar or a nested array.
 * @return mixed The sanitized value.
 */
function wsp_acf_sanitize_value( $value ) {
    if ( is_array( $value ) ) {
        $clean = array();
        foreach ( $value as $key => $item ) {
            $clean_key           = is_string( $key ) ? sanitize_text_field( $key ) : $key;
            $clean[ $clean_key ] = wsp_acf_sanitize_value( $item );
        }
        return $clean;
    }

    if ( is_string( $value ) ) {
        return wp_kses_post( $value );
    }

    return $value;
}

/**
 * Dot-notation deep setter helper.
 *
 * @param array  $data  Array to modify, passed by reference.
 * @param string $path  Dot-separated path, e.g. "repeater.0.text_field".
 * @param mixed  $value Value to store at the path.
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

/**
 * List all registered custom field groups.
 *
 * MCP tool: `wsp_acf_list_field_groups`. Requires the `edit_posts` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_list_field_groups( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $groups = acf_get_field_groups();
    return array( 'success' => true, 'groups' => $groups ? $groups : array() );
}

/**
 * Retrieve configuration parameters of a specific field group by key.
 *
 * MCP tool: `wsp_acf_get_field_group`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $key Required. Field group key (e.g. group_60a5b2).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Create a brand new custom field group configuration.
 *
 * MCP tool: `wsp_acf_create_field_group`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $title Required.
 *     @type string $key Optional. Optional group key structure.
 *     @type array $fields Optional.
 *     @type array $location Optional.
 *     @type bool $active Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Update location rules or active parameters of a field group.
 *
 * MCP tool: `wsp_acf_update_field_group`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $key Required.
 *     @type string $title Optional.
 *     @type array $location Optional.
 *     @type bool $active Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Permanently delete or trash a field group by its key.
 *
 * MCP tool: `wsp_acf_delete_field_group`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $key Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_delete_field_group( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $key = sanitize_text_field( $input['key'] );
    if ( acf_delete_field_group( $key ) ) {
        return array( 'success' => true, 'message' => 'Field group deleted.' );
    }
    return new WP_Error( 'delete_failed', 'Failed to delete field group.' );
}

/**
 * Import field groups config structure programmatically from JSON parameters.
 *
 * MCP tool: `wsp_acf_import_field_groups`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $json_data Required. JSON payload of group configurations.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * List all custom fields declared inside a specific field group.
 *
 * MCP tool: `wsp_acf_list_fields`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $group_key Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_list_fields( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $group_key = sanitize_text_field( $input['group_key'] );
    $fields = acf_get_fields( $group_key );
    return array( 'success' => true, 'fields' => $fields ? $fields : array() );
}

/**
 * Get full configurations and rules of a single field key.
 *
 * MCP tool: `wsp_acf_get_field`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_key Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Inject a new custom field config inside an existing group configuration.
 *
 * MCP tool: `wsp_acf_create_field`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type array $field_config Required. Field parameter details (name, type, parent, instructions
 *         etc.).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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
 *
 * MCP tool: `wsp_acf_update_field_config`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_key Required.
 *     @type array $config Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
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

/**
 * Delete config mapping of a field configuration.
 *
 * MCP tool: `wsp_acf_delete_field`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_key Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_delete_field( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_key = sanitize_text_field( $input['field_key'] );
    if ( acf_delete_field( $field_key ) ) {
        return array( 'success' => true, 'message' => 'Field deleted.' );
    }
    return new WP_Error( 'delete_failed', 'Failed to delete field.' );
}

/**
 * Duplicate an existing field config mapping.
 *
 * MCP tool: `wsp_acf_duplicate_field`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_key Required.
 *     @type string $parent_id Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Sync database structures with local filesystem JSON config records.
 *
 * MCP tool: `wsp_acf_sync_fields`. Requires the `manage_options` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Deep read custom field values with dot-notation pathing support (e.g. key.0.subkey).
 *
 * MCP tool: `wsp_acf_get_value_deep`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required. Target selector or ID (e.g. 101, "options", "user_1",
 *         "term_5").
 *     @type string $field_name Required.
 *     @type string $target_type Optional. post | page | user | term | option.
 *     @type string $path Optional. Dot-notation nested index selector (e.g.
 *         "repeater.0.text_field").
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_get_value_deep( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check.
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

/**
 * Deep write custom field values supporting array indices with dot-notation (e.g. repeater.0.key).
 *
 * MCP tool: `wsp_acf_update_value_deep`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required.
 *     @type string $field_name Required.
 *     @type string $value Required.
 *     @type string $target_type Optional.
 *     @type string $path Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_update_value_deep( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check.
    if ( is_wp_error( $selector ) ) return $selector;

    $field_name = sanitize_text_field( $input['field_name'] );
    $path       = isset( $input['path'] ) ? sanitize_text_field( $input['path'] ) : '';
    $value      = wsp_acf_sanitize_value( wp_unslash( $input['value'] ) );

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

/**
 * Delete specific key field metadata value.
 *
 * MCP tool: `wsp_acf_delete_value`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required.
 *     @type string $field_name Required.
 *     @type string $target_type Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_delete_value( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check.
    if ( is_wp_error( $selector ) ) return $selector;

    $field_name = sanitize_text_field( $input['field_name'] );
    delete_field( $field_name, $selector );

    return array( 'success' => true, 'message' => sprintf( 'Field value "%s" deleted.', $field_name ) );
}

/**
 * Get all raw field values mapped on any object.
 *
 * MCP tool: `wsp_acf_get_all_values`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required.
 *     @type string $target_type Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_get_all_values( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check.
    if ( is_wp_error( $selector ) ) return $selector;

    $fields = get_fields( $selector );
    return array( 'success' => true, 'fields' => $fields ? $fields : array() );
}

/**
 * Bulk update array values instantly.
 *
 * MCP tool: `wsp_acf_bulk_update_values`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required.
 *     @type array $fields Required. Key-value maps of fields structure.
 *     @type string $target_type Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_bulk_update_values( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, true ); // Contextual Write Check.
    if ( is_wp_error( $selector ) ) return $selector;

    $fields = $input['fields'];
    if ( ! is_array( $fields ) ) {
        return new WP_Error( 'invalid_fields', 'Fields payload must be a key-value list.' );
    }

    $updated = array();
    foreach ( $fields as $key => $val ) {
        $clean_val = wsp_acf_sanitize_value( wp_unslash( $val ) );
        update_field( $key, $clean_val, $selector );
        $updated[ $key ] = $clean_val;
    }

    return array( 'success' => true, 'updated' => $updated );
}

/**
 * Return both config parameter object and loaded values.
 *
 * MCP tool: `wsp_acf_get_field_object`. Requires the `edit_posts` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $target_id Required.
 *     @type string $field_selector Required.
 *     @type string $target_type Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_get_field_object( $input ) {
    $target_type = isset( $input['target_type'] ) ? sanitize_text_field( $input['target_type'] ) : 'post';
    $selector    = wsp_acf_validate_target( $input['target_id'], $target_type, false ); // Contextual Read Check.
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

/**
 * List registered post types.
 *
 * MCP tool: `wsp_acf_list_post_types`. Requires the `edit_posts` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Programmatically register brand new WordPress Post Type.
 *
 * MCP tool: `wsp_acf_create_post_type`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $post_type_slug Required.
 *     @type string $singular_name Required.
 *     @type string $plural_name Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * List taxonomies structure.
 *
 * MCP tool: `wsp_acf_list_taxonomies`. Requires the `edit_posts` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Programmatically register brand new WordPress taxonomy.
 *
 * MCP tool: `wsp_acf_create_taxonomy`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $taxonomy_slug Required.
 *     @type string $singular_name Required.
 *     @type string $plural_name Required.
 *     @type array $post_types Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * List registered global options views.
 *
 * MCP tool: `wsp_acf_list_options_pages`. Requires the `edit_posts` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_list_options_pages( $input ) {
    $cap_check = wsp_acf_check_cap( 'edit_posts' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    if ( ! function_exists( 'acf_get_options_pages' ) ) {
        return array( 'success' => true, 'options_pages' => array() );
    }

    $pages = acf_get_options_pages();
    return array( 'success' => true, 'options_pages' => $pages ? $pages : array() );
}

/**
 * Programmatically register global ACF Options Page.
 *
 * MCP tool: `wsp_acf_create_options_page`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $page_title Required.
 *     @type string $menu_title Optional.
 *     @type string $menu_slug Optional.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
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

/**
 * Read global option value metadata.
 *
 * MCP tool: `wsp_acf_get_option_value`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_name Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_get_option_value( $input ) {
    // Global option values are admin-level configuration — require manage_options.
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_name = sanitize_text_field( $input['field_name'] );
    $val = get_field( $field_name, 'options' );
    return array( 'success' => true, 'field_name' => $field_name, 'value' => $val );
}

/**
 * Write option values globally.
 *
 * MCP tool: `wsp_acf_update_option_value`. Requires the `manage_options` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $field_name Required.
 *     @type string $value Required.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_acf_update_option_value( $input ) {
    $cap_check = wsp_acf_check_cap( 'manage_options' );
    if ( is_wp_error( $cap_check ) ) return $cap_check;

    $field_name = sanitize_text_field( $input['field_name'] );
    $value = wsp_acf_sanitize_value( $input['value'] );

    // Repeater / flexible content / gallery / clone are ACF Pro field types: on free ACF the
    // write would silently store nothing useful, so say so instead of reporting success.
    if ( ! defined( 'ACF_PRO' ) && function_exists( 'get_field_object' ) ) {
        $obj = get_field_object( $field_name, 'options', false );
        if ( is_array( $obj ) && isset( $obj['type'] ) && in_array( $obj['type'], array( 'repeater', 'flexible_content', 'gallery', 'clone' ), true ) ) {
            return new WP_Error( 'unsupported', sprintf( 'Field "%s" is a %s field, which requires ACF Pro. Free ACF supports flat fields only.', $field_name, $obj['type'] ) );
        }
    }

    update_field( $field_name, $value, 'options' );
    return array( 'success' => true, 'field_name' => $field_name, 'value' => get_field( $field_name, 'options' ) );
}

// Note: no options-page delete tool — ACF options pages are re-registered on every load,
// so a runtime delete cannot persist.

