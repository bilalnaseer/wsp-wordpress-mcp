<?php
/**
 * WPForms abilities: forms, fields, entries and schema description.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wsp_wpforms_is_active' ) ) {
/**
 * Whether WPForms is active.
 *
 * @return bool
 */
function wsp_wpforms_is_active() {
    return function_exists( 'wpforms' ) || class_exists( 'WPForms' );
}
}

if ( ! function_exists( 'wsp_wpforms_pro_is_active' ) ) {
/**
 * Whether WPForms Pro is active, which entry tools require.
 *
 * @return bool
 */
function wsp_wpforms_pro_is_active() {
    if ( ! function_exists( 'wpforms' ) ) {
        return false;
    }
    return method_exists( wpforms(), 'is_pro' ) && wpforms()->is_pro();
}
}

// ---------------------------------------------
// HELPERS
// ---------------------------------------------

/**
 * Load and decode a form's JSON definition.
 *
 * @param int $form_id Form ID.
 * @return array|false The definition, or false when it is not a WPForms form.
 */
function wsp_wpforms_get_form_data( $form_id ) {
    $form = get_post( $form_id );
    if ( ! $form || 'wpforms' !== $form->post_type ) {
        return false;
    }
    $data = json_decode( $form->post_content, true );
    if ( ! is_array( $data ) ) {
        return false;
    }
    return $data;
}

/**
 * Save a form definition.
 *
 * @param int   $form_id Form ID.
 * @param array $data    Form definition.
 * @return int|WP_Error Form ID, or an error.
 */
function wsp_wpforms_save_form_data( $form_id, $data ) {
    $data['id'] = (string) $form_id;
    $data['field_id'] = wsp_wpforms_get_next_field_id( $data );
    $json = wp_json_encode( $data );
    return wp_update_post( array(
        'ID'           => $form_id,
        'post_content' => wp_slash( $json ),
    ), true );
}

/**
 * Get the next unused numeric field ID.
 *
 * @param array $data Form definition.
 * @return int
 */
function wsp_wpforms_get_next_field_id( $data ) {
    $max_id = -1;
    if ( ! empty( $data['fields'] ) && is_array( $data['fields'] ) ) {
        foreach ( $data['fields'] as $field ) {
            $fid = isset( $field['id'] ) ? intval( $field['id'] ) : -1;
            if ( $fid > $max_id ) {
                $max_id = $fid;
            }
        }
    }
    return $max_id + 1;
}

/**
 * List the field types that the tools can create.
 *
 * @return array Map of type to label and whether it accepts choices.
 */
function wsp_wpforms_field_types() {
    return array(
        'text'       => array( 'label' => 'Single Line Text', 'can_have_choices' => false ),
        'textarea'   => array( 'label' => 'Paragraph Text',   'can_have_choices' => false ),
        'select'     => array( 'label' => 'Dropdown',         'can_have_choices' => true  ),
        'radio'      => array( 'label' => 'Multiple Choice',  'can_have_choices' => true  ),
        'checkbox'   => array( 'label' => 'Checkboxes',       'can_have_choices' => true  ),
        'number'     => array( 'label' => 'Numbers',          'can_have_choices' => false ),
        'email'      => array( 'label' => 'Email',            'can_have_choices' => false ),
        'url'        => array( 'label' => 'Website / URL',    'can_have_choices' => false ),
        'phone'      => array( 'label' => 'Phone',            'can_have_choices' => false ),
        'date-time'  => array( 'label' => 'Date / Time',      'can_have_choices' => false ),
        'file-upload'=> array( 'label' => 'File Upload',      'can_have_choices' => false ),
        'password'   => array( 'label' => 'Password',         'can_have_choices' => false ),
        'payment-single' => array( 'label' => 'Single Item',  'can_have_choices' => false ),
        'name'       => array( 'label' => 'Name',             'can_have_choices' => false ),
        'address'    => array( 'label' => 'Address',          'can_have_choices' => false ),
    );
}

// ---------------------------------------------
// EXECUTE CALLBACKS
// ---------------------------------------------

/**
 * Lists all WPForms with ID, title, date, status, and field count.
 *
 * MCP tool: `wsp_wpforms_list_forms`. Requires the `wpforms_view_forms` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_list_forms( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $forms = get_posts( array(
        'post_type'      => 'wpforms',
        'posts_per_page' => -1,
        'post_status'    => array( 'publish', 'draft' ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $result = array();
    foreach ( $forms as $form ) {
        $data = json_decode( $form->post_content, true );
        $field_count = ! empty( $data['fields'] ) && is_array( $data['fields'] ) ? count( $data['fields'] ) : 0;
        $result[] = array(
            'id'          => $form->ID,
            'title'       => $form->post_title,
            'date'        => $form->post_date,
            'status'      => $form->post_status,
            'field_count' => $field_count,
        );
    }

    return array( 'forms' => $result, 'total' => count( $result ) );
}

/**
 * Retrieves full WPForms structure (fields, settings, payments).
 *
 * MCP tool: `wsp_wpforms_get_form`. Requires the `wpforms_view_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_get_form( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = get_post( $form_id );
    if ( ! $form || 'wpforms' !== $form->post_type ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $data = json_decode( $form->post_content, true );
    if ( ! is_array( $data ) ) {
        $data = array();
    }

    return array(
        'success'     => true,
        'id'          => $form->ID,
        'title'       => $form->post_title,
        'date'        => $form->post_date,
        'status'      => $form->post_status,
        'shortcode'   => sprintf( '[wpforms id="%d"]', $form->ID ),
        'fields'      => isset( $data['fields'] ) ? $data['fields'] : array(),
        'settings'    => isset( $data['settings'] ) ? $data['settings'] : array(),
        'payments'    => isset( $data['payments'] ) ? $data['payments'] : array(),
        'field_count' => isset( $data['fields'] ) && is_array( $data['fields'] ) ? count( $data['fields'] ) : 0,
    );
}

/**
 * Returns supported field types and editable attributes to guide AI on create/update field actions.
 *
 * MCP tool: `wsp_wpforms_describe_schema`. Requires the `wpforms_view_forms` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_describe_schema( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $types = wsp_wpforms_field_types();
    $field_types = array();

    foreach ( $types as $slug => $info ) {
        $entry = array(
            'type'             => $slug,
            'label'            => $info['label'],
            'can_have_choices' => $info['can_have_choices'],
            'editable_attrs'   => array( 'label', 'description', 'required', 'css' ),
        );
        if ( $info['can_have_choices'] ) {
            $entry['editable_attrs'][] = 'choices';
        }
        $field_types[] = $entry;
    }

    $form_settings = array(
        'form_title'       => 'string — Form title (stored as post_title)',
        'form_desc'        => 'string — Form description',
        'submit_text'      => 'string — Submit button text',
        'submit_processing'=> 'string — Text shown while processing',
        'antispam'         => 'boolean — Enable anti-spam honeypot',
        'ajax_submit'      => 'boolean — Enable AJAX form submission',
        'notification_email'=> 'string — Email for notifications',
    );

    return array(
        'success'      => true,
        'field_types'  => $field_types,
        'total_types'  => count( $field_types ),
        'form_settings'=> $form_settings,
        'notes'        => array(
            'Field IDs are auto-assigned as incremental integers (0, 1, 2...).',
            'Choice-based fields (select, radio, checkbox) require a \"choices\" array with \"label\" and \"value\" keys.',
            'Form data is stored as JSON in the wpforms post_type post_content.',
        ),
    );
}

/**
 * Fetch entry counts and analytics (Pro entry stats).
 *
 * MCP tool: `wsp_wpforms_get_form_stats`. Requires the `wpforms_view_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Optional. Form ID (optional; omit for global stats).
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_get_form_stats( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;

    $total_forms = count( get_posts( array(
        'post_type'      => 'wpforms',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
    ) ) );

    $stats = array(
        'total_forms'       => $total_forms,
        'entries_available' => false,
    );

    if ( wsp_wpforms_pro_is_active() && isset( wpforms()->entry ) ) {
        $stats['entries_available'] = true;

        $entry_handler = wpforms()->get( 'entry' );
        if ( $entry_handler && method_exists( $entry_handler, 'get_entries_count' ) ) {
            $args = array();
            if ( $form_id ) {
                $args['form_id'] = $form_id;
            }
            $stats['total_entries'] = $entry_handler->get_entries_count( $args );
        }

        if ( $form_id ) {
            $form = get_post( $form_id );
            if ( $form && 'wpforms' === $form->post_type ) {
                $data = json_decode( $form->post_content, true );
                $stats['form_id']    = $form_id;
                $stats['form_title'] = $form->post_title;
                $stats['form_status'] = $form->post_status;
                $stats['field_count'] = ! empty( $data['fields'] ) && is_array( $data['fields'] ) ? count( $data['fields'] ) : 0;
            }
        }

        if ( ! $form_id ) {
            $forms = get_posts( array(
                'post_type'        => 'wpforms',
                'posts_per_page'   => 5,
                'post_status'      => 'publish',
                'orderby'          => 'date',
                'order'            => 'DESC',
            ) );
            $recent = array();
            foreach ( $forms as $f ) {
                $f_args = array( 'form_id' => $f->ID );
                $count = $entry_handler->get_entries_count( $f_args );
                $recent[] = array(
                    'id'          => $f->ID,
                    'title'       => $f->post_title,
                    'entry_count' => $count,
                );
            }
            $stats['recent_forms'] = $recent;
        }
    } else {
        $stats['message'] = 'Entry statistics require WPForms Pro.';
    }

    return array(
        'success' => true,
        'stats'   => $stats,
    );
}

/**
 * Creates a new WPForms form with fields, settings, and notification email.
 *
 * MCP tool: `wsp_wpforms_create_form`. Requires the `wpforms_edit_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $title Required. Form title.
 *     @type string $description Optional. Form description.
 *     @type array $fields Optional. Array of field objects.
 *     @type string $submit_text Optional. Submit button text. Default: Submit.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_create_form( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
    if ( ! $title ) {
        return array( 'success' => false, 'error' => 'Form title is required.' );
    }

    $desc        = isset( $input['description'] ) ? sanitize_textarea_field( wp_unslash( $input['description'] ) ) : '';
    $fields      = isset( $input['fields'] ) ? $input['fields'] : array();
    $submit_text = isset( $input['submit_text'] ) ? sanitize_text_field( wp_unslash( $input['submit_text'] ) ) : 'Submit';
    $notify_email= isset( $input['notification_email'] ) ? sanitize_email( wp_unslash( $input['notification_email'] ) ) : '{admin_email}';
    $notify_subject= isset( $input['notification_subject'] ) ? sanitize_text_field( wp_unslash( $input['notification_subject'] ) ) : 'New Entry: ' . $title;

    $form_data = array(
        'fields'   => array(),
        'settings' => array(
            'form_title'              => $title,
            'form_desc'               => $desc,
            'form_class'              => '',
            'submit_text'             => $submit_text,
            'submit_text_processing'  => 'Sending...',
            'antispam'                => '1',
            'ajax_submit'             => '1',
            'notification_enable'     => '1',
            'notifications'           => array(
                '1' => array(
                    'email'          => $notify_email,
                    'subject'        => $notify_subject,
                    'sender_name'    => get_bloginfo( 'name' ),
                    'sender_address' => '{admin_email}',
                    'replyto'        => '{field_id="1"}',
                    'message'        => '{all_fields}',
                ),
            ),
            'confirmations' => array(
                '1' => array(
                    'type'    => 'message',
                    'message' => '<p>Thanks for contacting us! We will be in touch with you shortly.</p>',
                ),
            ),
        ),
    );

    if ( ! empty( $fields ) && is_array( $fields ) ) {
        foreach ( $fields as $idx => $f ) {
            $fid = intval( $idx );
            $field = array(
                'id'    => $fid,
                'type'  => isset( $f['type'] ) ? sanitize_text_field( $f['type'] ) : 'text',
                'label' => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '',
            );
            if ( ! empty( $f['required'] ) ) {
                $field['required'] = '1';
            }
            if ( ! empty( $f['description'] ) ) {
                $field['description'] = sanitize_textarea_field( $f['description'] );
            }
            if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
                $field['choices'] = array();
                foreach ( $f['choices'] as $cidx => $choice ) {
                    $field['choices'][] = array(
                        'label' => sanitize_text_field( isset( $choice['label'] ) ? $choice['label'] : $choice ),
                        'value' => sanitize_text_field( isset( $choice['value'] ) ? $choice['value'] : $choice ),
                    );
                }
            }
            if ( ! empty( $f['placeholder'] ) ) {
                $field['placeholder'] = sanitize_text_field( $f['placeholder'] );
            }
            $form_data['fields'][ $fid ] = $field;
        }
    }

    $form_id = wp_insert_post( array(
        'post_type'    => 'wpforms',
        'post_title'   => $title,
        'post_content' => '',
        'post_status'  => 'publish',
    ), true );

    if ( is_wp_error( $form_id ) ) {
        return array( 'success' => false, 'error' => $form_id->get_error_message() );
    }

    $form_data['id']       = (string) $form_id;
    $form_data['field_id'] = count( $form_data['fields'] );
    $json = wp_json_encode( $form_data );
    wp_update_post( array(
        'ID'           => $form_id,
        'post_content' => wp_slash( $json ),
    ) );

    return array(
        'success'   => true,
        'id'        => $form_id,
        'title'     => $title,
        'shortcode' => sprintf( '[wpforms id="%d"]', $form_id ),
    );
}

/**
 * Update form settings (title, description, submit text, AJAX, anti-spam).
 *
 * MCP tool: `wsp_wpforms_update_form_settings`. Requires the `wpforms_edit_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type string $title Optional. Form title.
 *     @type string $description Optional. Form description.
 *     @type string $submit_text Optional. Submit button text.
 *     @type string $submit_text_processing Optional. Text shown while submitting.
 *     @type bool $antispam Optional. Enable anti-spam honeypot.
 *     @type bool $ajax_submit Optional. Enable AJAX submission.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_update_form_settings( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = get_post( $form_id );
    if ( ! $form || 'wpforms' !== $form->post_type ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $data = json_decode( $form->post_content, true );
    if ( ! is_array( $data ) ) {
        $data = array( 'fields' => array(), 'settings' => array() );
    }
    if ( ! isset( $data['settings'] ) ) {
        $data['settings'] = array();
    }

    $updated = array();
    $settings_map = array(
        'title'                 => 'form_title',
        'description'           => 'form_desc',
        'submit_text'           => 'submit_text',
        'submit_text_processing'=> 'submit_text_processing',
        'antispam'              => 'antispam',
        'ajax_submit'           => 'ajax_submit',
    );

    if ( isset( $input['title'] ) ) {
        $new_title = sanitize_text_field( wp_unslash( $input['title'] ) );
        wp_update_post( array( 'ID' => $form_id, 'post_title' => $new_title ) );
        $updated[] = 'title';
    }

    foreach ( $settings_map as $input_key => $data_key ) {
        if ( ! isset( $input[ $input_key ] ) ) {
            continue;
        }
        if ( in_array( $input_key, array( 'antispam', 'ajax_submit' ), true ) ) {
            $data['settings'][ $data_key ] = (bool) $input[ $input_key ] ? '1' : '';
        } elseif ( 'description' === $input_key ) {
            $data['settings'][ $data_key ] = sanitize_textarea_field( wp_unslash( $input[ $input_key ] ) );
        } else {
            $data['settings'][ $data_key ] = sanitize_text_field( wp_unslash( $input[ $input_key ] ) );
        }
        $updated[] = $input_key;
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No settings provided to update.' );
    }

    $result = wsp_wpforms_save_form_data( $form_id, $data );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success' => true,
        'id'      => $form_id,
        'updated' => $updated,
    );
}

/**
 * Add a new field to an existing form with auto-assigned ID.
 *
 * MCP tool: `wsp_wpforms_add_field`. Requires the `wpforms_edit_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type string $type Required. Field type (use wpforms-describe-schema to see options).
 *     @type string $label Required. Field label.
 *     @type bool $required Optional. Make field required.
 *     @type string $description Optional. Field description text.
 *     @type string $placeholder Optional. Placeholder text.
 *     @type string $css Optional. CSS class.
 *     @type array $choices Optional. Array of {label, value} for choice fields.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_add_field( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $data = wsp_wpforms_get_form_data( $form_id );
    if ( ! $data ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $field_type = isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'text';
    $types = wsp_wpforms_field_types();
    if ( ! isset( $types[ $field_type ] ) ) {
        return array( 'success' => false, 'error' => 'Invalid field type. Use wpforms-describe-schema to see available types.' );
    }

    $label = isset( $input['label'] ) ? sanitize_text_field( wp_unslash( $input['label'] ) ) : '';
    if ( ! $label ) {
        return array( 'success' => false, 'error' => 'Field label is required.' );
    }

    if ( ! isset( $data['fields'] ) ) {
        $data['fields'] = array();
    }

    $new_id = wsp_wpforms_get_next_field_id( $data );
    $field = array(
        'id'    => $new_id,
        'type'  => $field_type,
        'label' => $label,
    );

    if ( ! empty( $input['required'] ) ) {
        $field['required'] = '1';
    }
    if ( ! empty( $input['description'] ) ) {
        $field['description'] = sanitize_textarea_field( wp_unslash( $input['description'] ) );
    }
    if ( ! empty( $input['placeholder'] ) ) {
        $field['placeholder'] = sanitize_text_field( wp_unslash( $input['placeholder'] ) );
    }
    if ( ! empty( $input['css'] ) ) {
        $field['css'] = sanitize_text_field( wp_unslash( $input['css'] ) );
    }

    if ( $types[ $field_type ]['can_have_choices'] && ! empty( $input['choices'] ) && is_array( $input['choices'] ) ) {
        $field['choices'] = array();
        foreach ( $input['choices'] as $choice ) {
            $field['choices'][] = array(
                'label' => sanitize_text_field( isset( $choice['label'] ) ? $choice['label'] : $choice ),
                'value' => sanitize_text_field( isset( $choice['value'] ) ? $choice['value'] : $choice ),
            );
        }
    }

    $data['fields'][ $new_id ] = $field;

    $result = wsp_wpforms_save_form_data( $form_id, $data );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'   => true,
        'form_id'   => $form_id,
        'field_id'  => $new_id,
        'field'     => $field,
    );
}

/**
 * Update a field label, description, required status, or choices.
 *
 * MCP tool: `wsp_wpforms_update_field`. Requires the `wpforms_edit_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type string $field_id Required. Field ID (e.g. "0", "1").
 *     @type string $label Optional. Field label.
 *     @type bool $required Optional. Make field required.
 *     @type string $description Optional. Field description.
 *     @type string $placeholder Optional. Placeholder text.
 *     @type string $css Optional. CSS class.
 *     @type array $choices Optional. Array of {label, value} for choice fields.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_update_field( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id  = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    $field_id = isset( $input['field_id'] ) ? sanitize_text_field( wp_unslash( $input['field_id'] ) ) : '';
    if ( ! $form_id || '' === $field_id ) {
        return array( 'success' => false, 'error' => 'Form ID and Field ID are required.' );
    }

    $data = wsp_wpforms_get_form_data( $form_id );
    if ( ! $data ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    if ( ! isset( $data['fields'][ $field_id ] ) ) {
        return array( 'success' => false, 'error' => 'Field not found.' );
    }

    $field = &$data['fields'][ $field_id ];
    $updated = array();

    $updatable = array( 'label', 'description', 'placeholder', 'css' );
    foreach ( $updatable as $attr ) {
        if ( isset( $input[ $attr ] ) ) {
            if ( 'description' === $attr ) {
                $field[ $attr ] = sanitize_textarea_field( wp_unslash( $input[ $attr ] ) );
            } else {
                $field[ $attr ] = sanitize_text_field( wp_unslash( $input[ $attr ] ) );
            }
            $updated[] = $attr;
        }
    }

    if ( isset( $input['required'] ) ) {
        $field['required'] = (bool) $input['required'] ? '1' : '';
        $updated[] = 'required';
    }

    if ( isset( $input['choices'] ) && is_array( $input['choices'] ) ) {
        $field['choices'] = array();
        foreach ( $input['choices'] as $choice ) {
            $field['choices'][] = array(
                'label' => sanitize_text_field( isset( $choice['label'] ) ? $choice['label'] : $choice ),
                'value' => sanitize_text_field( isset( $choice['value'] ) ? $choice['value'] : $choice ),
            );
        }
        $updated[] = 'choices';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }

    $result = wsp_wpforms_save_form_data( $form_id, $data );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'  => true,
        'form_id'  => $form_id,
        'field_id' => $field_id,
        'updated'  => $updated,
    );
}

/**
 * Trashes or permanently deletes a WPForms form.
 *
 * MCP tool: `wsp_wpforms_delete_form`. Requires the `wpforms_edit_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type bool $permanent Optional. True for permanent deletion. Default false.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_delete_form( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = get_post( $form_id );
    if ( ! $form || 'wpforms' !== $form->post_type ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $permanent = isset( $input['permanent'] ) ? (bool) $input['permanent'] : false;

    if ( $permanent ) {
        $result = wp_delete_post( $form_id, true );
        if ( ! $result ) {
            return array( 'success' => false, 'error' => 'Failed to permanently delete form.' );
        }
    } else {
        $result = wp_trash_post( $form_id );
        if ( ! $result ) {
            return array( 'success' => false, 'error' => 'Failed to move form to trash.' );
        }
    }

    return array(
        'success' => true,
        'message' => $permanent ? 'Form permanently deleted.' : 'Form moved to trash.',
        'id'      => $form_id,
    );
}

/**
 * Lists submission entries for a form (requires WPForms Pro).
 *
 * MCP tool: `wsp_wpforms_list_entries`. Requires the `wpforms_view_entries` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Optional. Form ID to filter by.
 *     @type int $per_page Optional. Number of entries. Default 20.
 *     @type int $page Optional. Page number. Default 1.
 *     @type string $status Optional. publish | trash | all.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_list_entries( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    if ( ! wsp_wpforms_pro_is_active() ) {
        return array( 'success' => false, 'error' => 'WPForms Pro is required for entry storage. Please upgrade to WPForms Pro.' );
    }

    $entry_handler = wpforms()->get( 'entry' );
    if ( ! $entry_handler ) {
        return array( 'success' => false, 'error' => 'Entry system is not available.' );
    }

    $form_id  = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $page     = isset( $input['page'] ) ? intval( $input['page'] ) : 1;
    $status   = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : '';

    $args = array(
        'number' => $per_page,
        'offset' => ( $page - 1 ) * $per_page,
        'order'  => 'DESC',
    );

    if ( $form_id ) {
        $args['form_id'] = $form_id;
    }

    if ( $status && 'all' !== $status ) {
        $args['entry_status'] = $status;
    }

    $entries = $entry_handler->get_entries( $args );
    $total = $entry_handler->get_entries_count( $args );

    $items = array();
    if ( is_array( $entries ) ) {
        foreach ( $entries as $entry ) {
            $items[] = array(
                'entry_id'    => $entry->entry_id,
                'form_id'     => $entry->form_id,
                'date'        => $entry->date,
                'status'      => isset( $entry->status ) ? $entry->status : 'publish',
                'starred'     => ! empty( $entry->starred ),
                'viewed'      => ! empty( $entry->viewed ),
            );
        }
    }

    return array(
        'entries'  => $items,
        'total'    => (int) $total,
        'page'     => $page,
        'per_page' => $per_page,
    );
}

/**
 * Retrieves full details and field values of a single entry.
 *
 * MCP tool: `wsp_wpforms_get_entry`. Requires the `wpforms_view_entries` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Entry ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_get_entry( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    if ( ! wsp_wpforms_pro_is_active() ) {
        return array( 'success' => false, 'error' => 'WPForms Pro is required for entry storage. Please upgrade to WPForms Pro.' );
    }

    $entry_handler = wpforms()->get( 'entry' );
    if ( ! $entry_handler ) {
        return array( 'success' => false, 'error' => 'Entry system is not available.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = $entry_handler->get( $entry_id );
    if ( ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $fields = array();
    if ( method_exists( $entry_handler, 'get_entry_fields' ) ) {
        $entry_fields = $entry_handler->get_entry_fields( array(
            'entry_id' => $entry_id,
            'number'   => -1,
        ) );
        if ( is_array( $entry_fields ) ) {
            foreach ( $entry_fields as $ef ) {
                $fields[] = array(
                    'field_id' => $ef->field_id,
                    'label'    => sanitize_text_field( isset( $ef->field_label ) ? $ef->field_label : '' ),
                    'value'    => $ef->value,
                    'type'     => isset( $ef->type ) ? $ef->type : '',
                );
            }
        }
    }

    $form_title = '';
    if ( $entry->form_id ) {
        $form_post = get_post( $entry->form_id );
        if ( $form_post ) {
            $form_title = $form_post->post_title;
        }
    }

    return array(
        'success'    => true,
        'entry_id'   => $entry->entry_id,
        'form_id'    => $entry->form_id,
        'form_title' => $form_title,
        'date'       => $entry->date,
        'status'     => isset( $entry->status ) ? $entry->status : 'publish',
        'starred'    => ! empty( $entry->starred ),
        'viewed'     => ! empty( $entry->viewed ),
        'user_ip'    => isset( $entry->ip_address ) ? $entry->ip_address : '',
        'user_agent' => isset( $entry->user_agent ) ? $entry->user_agent : '',
        'fields'     => $fields,
    );
}

/**
 * Trashes or permanently deletes a submission entry (Pro).
 *
 * MCP tool: `wsp_wpforms_delete_entry`. Requires the `wpforms_edit_entries` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Entry ID.
 *     @type bool $permanent Optional. True for permanent deletion. Default false.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_wpforms_delete_entry( $input ) {
    if ( ! function_exists( 'wpforms' ) ) {
        return array( 'success' => false, 'error' => 'WPForms is not active.' );
    }

    if ( ! wsp_wpforms_pro_is_active() ) {
        return array( 'success' => false, 'error' => 'WPForms Pro is required for entry storage. Please upgrade to WPForms Pro.' );
    }

    $entry_handler = wpforms()->get( 'entry' );
    if ( ! $entry_handler ) {
        return array( 'success' => false, 'error' => 'Entry system is not available.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = $entry_handler->get( $entry_id );
    if ( ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $permanent = isset( $input['permanent'] ) ? (bool) $input['permanent'] : false;

    if ( $permanent && method_exists( $entry_handler, 'delete' ) ) {
        $entry_handler->delete( $entry_id );
    } elseif ( method_exists( $entry_handler, 'update_status' ) ) {
        $entry_handler->update_status( $entry_id, 'trash' );
    } else {
        return array( 'success' => false, 'error' => 'Entry deletion is not supported in this version of WPForms.' );
    }

    return array(
        'success'  => true,
        'message'  => $permanent ? 'Entry permanently deleted.' : 'Entry moved to trash.',
        'entry_id' => $entry_id,
    );
}
