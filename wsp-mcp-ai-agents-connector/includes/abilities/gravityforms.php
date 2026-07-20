<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wsp_gravity_is_active' ) ) {
function wsp_gravity_is_active() {
    return class_exists( 'GFAPI' ) || class_exists( 'GFCommon' );
}
}

// ---------------------------------------------
// EXECUTE CALLBACKS
// ---------------------------------------------

function wsp_execute_gravity_list_forms( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $forms = GFAPI::get_forms();
    if ( is_wp_error( $forms ) ) {
        return array( 'success' => false, 'error' => $forms->get_error_message() );
    }

    $result = array();
    foreach ( $forms as $form ) {
        $entry_count = 0;
        if ( class_exists( 'GFAPI' ) ) {
            $count = GFAPI::count_entries( $form['id'] );
            if ( ! is_wp_error( $count ) ) {
                $entry_count = (int) $count;
            }
        }
        $result[] = array(
            'id'          => $form['id'],
            'title'       => $form['title'],
            'date_created'=> isset( $form['date_created'] ) ? $form['date_created'] : '',
            'is_active'   => ! empty( $form['is_active'] ),
            'entry_count' => $entry_count,
        );
    }
    return array( 'forms' => $result, 'total' => count( $result ) );
}

function wsp_execute_gravity_get_form( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => $form->get_error_message() );
    }
    if ( ! $form ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    return array( 'success' => true, 'form' => $form );
}

function wsp_execute_gravity_create_form( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
    if ( ! $title ) {
        return array( 'success' => false, 'error' => 'Form title is required.' );
    }

    $description = isset( $input['description'] ) ? sanitize_textarea_field( wp_unslash( $input['description'] ) ) : '';

    $form_meta = array(
        'title'       => $title,
        'description' => $description,
        'fields'      => isset( $input['fields'] ) ? $input['fields'] : array(),
        'button'      => array(
            'type' => 'text',
            'text' => isset( $input['button_text'] ) ? sanitize_text_field( wp_unslash( $input['button_text'] ) ) : 'Submit',
        ),
    );

    $form_id = GFAPI::add_form( $form_meta );
    if ( is_wp_error( $form_id ) ) {
        return array( 'success' => false, 'error' => $form_id->get_error_message() );
    }

    return array(
        'success' => true,
        'id'      => $form_id,
        'title'   => $title,
    );
}

function wsp_execute_gravity_update_form( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $updated = array();
    if ( isset( $input['title'] ) ) {
        $form['title'] = sanitize_text_field( wp_unslash( $input['title'] ) );
        $updated[] = 'title';
    }
    if ( isset( $input['description'] ) ) {
        $form['description'] = sanitize_textarea_field( wp_unslash( $input['description'] ) );
        $updated[] = 'description';
    }
    if ( isset( $input['is_active'] ) ) {
        $form['is_active'] = (bool) $input['is_active'];
        $updated[] = 'is_active';
    }
    if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
        $form['fields'] = $input['fields'];
        $updated[] = 'fields';
    }
    if ( isset( $input['button_text'] ) ) {
        if ( ! isset( $form['button'] ) ) {
            $form['button'] = array( 'type' => 'text' );
        }
        $form['button']['text'] = sanitize_text_field( wp_unslash( $input['button_text'] ) );
        $updated[] = 'button_text';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success' => true,
        'id'      => $form_id,
        'updated' => $updated,
    );
}

function wsp_execute_gravity_delete_form( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $result = GFAPI::delete_form( $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success' => true,
        'message' => 'Form deleted successfully.',
        'id'      => $form_id,
    );
}

function wsp_execute_gravity_list_entries( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $page     = isset( $input['page'] ) ? intval( $input['page'] ) : 1;
    $status   = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : '';

    $paging = array(
        'page_size' => $per_page,
        'offset'    => ( $page - 1 ) * $per_page,
    );

    $search_criteria = array();
    if ( $status && 'all' !== $status ) {
        $search_criteria['status'] = $status;
    }

    $total = GFAPI::count_entries( $form_id, $search_criteria );
    $entries = GFAPI::get_entries( $form_id, $search_criteria, array(), $paging );

    if ( is_wp_error( $entries ) ) {
        return array( 'success' => false, 'error' => $entries->get_error_message() );
    }

    $items = array();
    foreach ( $entries as $entry ) {
        $items[] = array(
            'id'         => $entry['id'],
            'form_id'    => $entry['form_id'],
            'date_created'=> $entry['date_created'],
            'status'     => isset( $entry['status'] ) ? $entry['status'] : 'active',
            'is_read'    => ! empty( $entry['is_read'] ),
            'is_starred' => ! empty( $entry['is_starred'] ),
        );
    }

    return array(
        'entries' => $items,
        'total'   => is_wp_error( $total ) ? 0 : (int) $total,
        'page'    => $page,
        'per_page'=> $per_page,
    );
}

function wsp_execute_gravity_get_entry( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = GFAPI::get_entry( $entry_id );
    if ( is_wp_error( $entry ) ) {
        return array( 'success' => false, 'error' => $entry->get_error_message() );
    }
    if ( ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $form = GFAPI::get_form( $entry['form_id'] );

    $fields = array();
    foreach ( $entry as $key => $value ) {
        if ( is_numeric( $key ) ) {
            $field_id = floatval( $key );
            $label    = $key;
            if ( $form && ! is_wp_error( $form ) ) {
                foreach ( $form['fields'] as $f ) {
                    if ( $f['id'] == $field_id ) {
                        $label = sanitize_text_field( $f['label'] );
                        break;
                    }
                }
            }
            $fields[] = array(
                'field_id' => $field_id,
                'label'    => $label,
                'value'    => $value,
            );
        }
    }

    return array(
        'success'    => true,
        'id'         => $entry['id'],
        'form_id'    => $entry['form_id'],
        'date_created'=> $entry['date_created'],
        'status'     => isset( $entry['status'] ) ? $entry['status'] : 'active',
        'is_read'    => ! empty( $entry['is_read'] ),
        'is_starred' => ! empty( $entry['is_starred'] ),
        'fields'     => $fields,
    );
}

function wsp_execute_gravity_update_entry( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = GFAPI::get_entry( $entry_id );
    if ( is_wp_error( $entry ) || ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $updated = array();

    if ( isset( $input['is_read'] ) ) {
        $value = (bool) $input['is_read'];
        $entry['is_read'] = $value ? '1' : '0';
        $updated[] = 'is_read';
    }

    if ( isset( $input['is_starred'] ) ) {
        $value = (bool) $input['is_starred'];
        $entry['is_starred'] = $value ? '1' : '0';
        $updated[] = 'is_starred';
    }

    if ( isset( $input['status'] ) ) {
        $allowed = array( 'active', 'spam', 'trash' );
        $value   = sanitize_text_field( wp_unslash( $input['status'] ) );
        if ( ! in_array( $value, $allowed, true ) ) {
            return array( 'success' => false, 'error' => 'Invalid status. Allowed: active, spam, trash.' );
        }
        $entry['status'] = $value;
        $updated[] = 'status';
    }

    if ( isset( $input['fields'] ) && is_array( $input['fields'] ) ) {
        foreach ( $input['fields'] as $field_id => $field_value ) {
            if ( is_numeric( $field_id ) ) {
                $entry[ strval( $field_id ) ] = sanitize_text_field( wp_unslash( $field_value ) );
            }
        }
        $updated[] = 'field_values';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }

    $result = GFAPI::update_entry( $entry, $entry_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success' => true,
        'id'      => $entry_id,
        'updated' => $updated,
    );
}

function wsp_execute_gravity_delete_entry( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = GFAPI::get_entry( $entry_id );
    if ( is_wp_error( $entry ) || ! $entry ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $permanent = isset( $input['permanent'] ) ? (bool) $input['permanent'] : false;

    if ( $permanent ) {
        $result = GFAPI::delete_entry( $entry_id );
    } else {
        $entry['status'] = 'trash';
        $result = GFAPI::update_entry( $entry, $entry_id );
    }

    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'  => true,
        'message'  => $permanent ? 'Entry permanently deleted.' : 'Entry moved to trash.',
        'id'       => $entry_id,
    );
}

function wsp_execute_gravity_get_notifications( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $notifications = isset( $form['notifications'] ) ? $form['notifications'] : array();

    $items = array();
    foreach ( $notifications as $key => $n ) {
        $items[] = array(
            'id'       => $key,
            'name'     => sanitize_text_field( $n['name'] ),
            'is_active'=> ! empty( $n['isActive'] ),
            'to_type'  => isset( $n['toType'] ) ? sanitize_text_field( $n['toType'] ) : '',
            'to'       => isset( $n['to'] ) ? sanitize_text_field( $n['to'] ) : '',
            'subject'  => isset( $n['subject'] ) ? sanitize_text_field( $n['subject'] ) : '',
        );
    }

    return array(
        'success'       => true,
        'form_id'       => $form_id,
        'notifications' => $items,
        'total'         => count( $items ),
    );
}

function wsp_execute_gravity_get_confirmations( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $confirmations = isset( $form['confirmations'] ) ? $form['confirmations'] : array();

    $items = array();
    foreach ( $confirmations as $key => $c ) {
        $items[] = array(
            'id'       => $key,
            'name'     => sanitize_text_field( $c['name'] ),
            'type'     => isset( $c['type'] ) ? sanitize_text_field( $c['type'] ) : 'message',
            'is_default'=> ! empty( $c['isDefault'] ),
            'is_active' => ! empty( $c['isActive'] ),
            'message'  => isset( $c['message'] ) ? wp_kses_post( $c['message'] ) : '',
            'url'      => isset( $c['url'] ) ? esc_url_raw( $c['url'] ) : '',
        );
    }

    return array(
        'success'       => true,
        'form_id'       => $form_id,
        'confirmations' => $items,
        'total'         => count( $items ),
    );
}

function wsp_execute_gravity_create_notification( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $notif_id    = uniqid();
    $notif_name  = isset( $input['name'] ) ? sanitize_text_field( wp_unslash( $input['name'] ) ) : 'New Notification';
    $temp_name   = '_wsp_tmp_' . $notif_id . '_' . wp_rand( 1000, 9999 );

    $notification = array(
        'id'      => $notif_id,
        'name'    => $temp_name,
        'isActive'=> true,
        'toType'  => isset( $input['to_type'] ) ? sanitize_text_field( wp_unslash( $input['to_type'] ) ) : 'email',
        'to'      => isset( $input['to'] ) ? sanitize_text_field( wp_unslash( $input['to'] ) ) : '{admin_email}',
        'subject' => isset( $input['subject'] ) ? sanitize_text_field( wp_unslash( $input['subject'] ) ) : 'New submission from {form_title}',
        'message' => isset( $input['message'] ) ? wp_kses_post( wp_unslash( $input['message'] ) ) : '{all_fields}',
        'from'    => isset( $input['from'] ) ? sanitize_text_field( wp_unslash( $input['from'] ) ) : '{admin_email}',
        'fromName'=> isset( $input['from_name'] ) ? sanitize_text_field( wp_unslash( $input['from_name'] ) ) : get_bloginfo( 'name' ),
        'replyTo' => isset( $input['reply_to'] ) ? sanitize_text_field( wp_unslash( $input['reply_to'] ) ) : '',
        'bcc'     => isset( $input['bcc'] ) ? sanitize_text_field( wp_unslash( $input['bcc'] ) ) : '',
        'event'   => isset( $input['event'] ) ? sanitize_text_field( wp_unslash( $input['event'] ) ) : 'form_submission',
    );

    if ( ! isset( $form['notifications'] ) ) {
        $form['notifications'] = array();
    }
    $form['notifications'][ $notif_id ] = $notification;

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    $updated_form = GFAPI::get_form( $form_id );
    $actual_id    = $notif_id;
    if ( $updated_form && ! is_wp_error( $updated_form ) && isset( $updated_form['notifications'] ) ) {
        foreach ( $updated_form['notifications'] as $key => $n ) {
            if ( isset( $n['name'] ) && $n['name'] === $temp_name ) {
                $actual_id = $key;
                $updated_form['notifications'][ $key ]['name'] = $notif_name;
                $updated_form['notifications'][ $key ]['id']   = $actual_id;
                GFAPI::update_form( $updated_form, $form_id );
                break;
            }
        }
    }

    return array(
        'success'        => true,
        'form_id'        => $form_id,
        'notification_id'=> $actual_id,
        'name'           => $notif_name,
    );
}

function wsp_execute_gravity_update_notification( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    $notif_id = isset( $input['notification_id'] ) ? sanitize_text_field( wp_unslash( $input['notification_id'] ) ) : '';
    if ( ! $form_id || '' === $notif_id ) {
        return array( 'success' => false, 'error' => 'Form ID and Notification ID are required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }
    if ( ! isset( $form['notifications'][ $notif_id ] ) ) {
        return array( 'success' => false, 'error' => 'Notification not found.' );
    }

    $updatable = array( 'name', 'to', 'toType', 'subject', 'message', 'from', 'fromName', 'replyTo', 'bcc', 'event' );
    foreach ( $updatable as $key ) {
        $input_key = ( 'toType' === $key ) ? 'to_type' : ( ( 'fromName' === $key ) ? 'from_name' : ( ( 'replyTo' === $key ) ? 'reply_to' : $key ) );
        if ( isset( $input[ $input_key ] ) ) {
            $value = wp_unslash( $input[ $input_key ] );
            if ( 'message' === $key ) {
                $form['notifications'][ $notif_id ][ $key ] = wp_kses_post( $value );
            } else {
                $form['notifications'][ $notif_id ][ $key ] = sanitize_text_field( $value );
            }
        }
    }
    if ( isset( $input['is_active'] ) ) {
        $form['notifications'][ $notif_id ]['isActive'] = (bool) $input['is_active'];
    }

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'         => true,
        'form_id'         => $form_id,
        'notification_id' => $notif_id,
    );
}

function wsp_execute_gravity_delete_notification( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    $notif_id = isset( $input['notification_id'] ) ? sanitize_text_field( wp_unslash( $input['notification_id'] ) ) : '';
    if ( ! $form_id || '' === $notif_id ) {
        return array( 'success' => false, 'error' => 'Form ID and Notification ID are required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }
    if ( ! isset( $form['notifications'][ $notif_id ] ) ) {
        return array( 'success' => false, 'error' => 'Notification not found.' );
    }

    unset( $form['notifications'][ $notif_id ] );

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'         => true,
        'form_id'         => $form_id,
        'notification_id' => $notif_id,
        'message'         => 'Notification deleted successfully.',
    );
}

function wsp_execute_gravity_create_confirmation( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $conf_id   = uniqid();
    $conf_name = isset( $input['name'] ) ? sanitize_text_field( wp_unslash( $input['name'] ) ) : 'New Confirmation';
    $temp_name = '_wsp_tmp_' . $conf_id . '_' . wp_rand( 1000, 9999 );

    $confirmation = array(
        'id'        => $conf_id,
        'name'      => $temp_name,
        'isDefault' => isset( $input['is_default'] ) ? (bool) $input['is_default'] : false,
        'type'      => isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'message',
        'message'   => isset( $input['message'] ) ? wp_kses_post( wp_unslash( $input['message'] ) ) : 'Thanks for contacting us!',
        'url'       => isset( $input['url'] ) ? esc_url_raw( wp_unslash( $input['url'] ) ) : '',
        'pageId'    => isset( $input['page_id'] ) ? intval( $input['page_id'] ) : '',
        'queryString'=> isset( $input['query_string'] ) ? sanitize_text_field( wp_unslash( $input['query_string'] ) ) : '',
    );

    if ( ! isset( $form['confirmations'] ) ) {
        $form['confirmations'] = array();
    }
    $form['confirmations'][ $conf_id ] = $confirmation;

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    $updated_form = GFAPI::get_form( $form_id );
    $actual_id    = $conf_id;
    if ( $updated_form && ! is_wp_error( $updated_form ) && isset( $updated_form['confirmations'] ) ) {
        foreach ( $updated_form['confirmations'] as $key => $c ) {
            if ( isset( $c['name'] ) && $c['name'] === $temp_name ) {
                $actual_id = $key;
                $updated_form['confirmations'][ $key ]['name'] = $conf_name;
                $updated_form['confirmations'][ $key ]['id']   = $actual_id;
                GFAPI::update_form( $updated_form, $form_id );
                break;
            }
        }
    }

    return array(
        'success'          => true,
        'form_id'          => $form_id,
        'confirmation_id'  => $actual_id,
        'name'             => $conf_name,
    );
}

function wsp_execute_gravity_update_confirmation( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    $conf_id = isset( $input['confirmation_id'] ) ? sanitize_text_field( wp_unslash( $input['confirmation_id'] ) ) : '';
    if ( ! $form_id || '' === $conf_id ) {
        return array( 'success' => false, 'error' => 'Form ID and Confirmation ID are required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }
    if ( ! isset( $form['confirmations'][ $conf_id ] ) ) {
        return array( 'success' => false, 'error' => 'Confirmation not found.' );
    }

    $updatable = array( 'name', 'type', 'message', 'url', 'pageId', 'queryString' );
    foreach ( $updatable as $key ) {
        $input_key = ( 'pageId' === $key ) ? 'page_id' : ( ( 'queryString' === $key ) ? 'query_string' : $key );
        if ( isset( $input[ $input_key ] ) ) {
            $value = wp_unslash( $input[ $input_key ] );
            if ( 'message' === $key ) {
                $form['confirmations'][ $conf_id ][ $key ] = wp_kses_post( $value );
            } elseif ( 'url' === $key ) {
                $form['confirmations'][ $conf_id ][ $key ] = esc_url_raw( $value );
            } elseif ( 'pageId' === $key ) {
                $form['confirmations'][ $conf_id ][ $key ] = intval( $value );
            } else {
                $form['confirmations'][ $conf_id ][ $key ] = sanitize_text_field( $value );
            }
        }
    }
    if ( isset( $input['is_default'] ) ) {
        $form['confirmations'][ $conf_id ]['isDefault'] = (bool) $input['is_default'];
    }
    if ( isset( $input['is_active'] ) ) {
        $form['confirmations'][ $conf_id ]['isActive'] = (bool) $input['is_active'];
    }

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'         => true,
        'form_id'         => $form_id,
        'confirmation_id' => $conf_id,
    );
}

function wsp_execute_gravity_delete_confirmation( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    $conf_id = isset( $input['confirmation_id'] ) ? sanitize_text_field( wp_unslash( $input['confirmation_id'] ) ) : '';
    if ( ! $form_id || '' === $conf_id ) {
        return array( 'success' => false, 'error' => 'Form ID and Confirmation ID are required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }
    if ( ! isset( $form['confirmations'][ $conf_id ] ) ) {
        return array( 'success' => false, 'error' => 'Confirmation not found.' );
    }

    unset( $form['confirmations'][ $conf_id ] );

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success'         => true,
        'form_id'         => $form_id,
        'confirmation_id' => $conf_id,
        'message'         => 'Confirmation deleted successfully.',
    );
}

function wsp_execute_gravity_update_form_settings( $input ) {
    if ( ! class_exists( 'GFAPI' ) ) {
        return array( 'success' => false, 'error' => 'Gravity Forms is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = GFAPI::get_form( $form_id );
    if ( ! $form || is_wp_error( $form ) ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $updated = array();

    $settings_map = array(
        'label_placement'        => 'labelPlacement',
        'description_placement'  => 'descriptionPlacement',
        'sub_label_placement'    => 'subLabelPlacement',
        'css_class'              => 'cssClass',
        'enable_honeypot'        => 'enableHoneypot',
        'enable_animation'       => 'enableAnimation',
        'limit_entries'          => 'limitEntries',
        'limit_entries_count'    => 'limitEntriesCount',
        'limit_entries_period'   => 'limitEntriesPeriod',
        'limit_entries_message'  => 'limitEntriesMessage',
        'schedule_form'          => 'scheduleForm',
        'schedule_start'         => 'scheduleStart',
        'schedule_end'           => 'scheduleEnd',
        'schedule_pending_message'=> 'schedulePendingMessage',
        'schedule_message'       => 'scheduleMessage',
        'require_login'          => 'requireLogin',
        'require_login_message'  => 'requireLoginMessage',
        'save_enabled'           => 'save',
    );

    foreach ( $settings_map as $input_key => $form_key ) {
        if ( ! isset( $input[ $input_key ] ) ) {
            continue;
        }
        $value = $input[ $input_key ];
        if ( in_array( $input_key, array( 'css_class', 'limit_entries_message', 'schedule_message', 'schedule_pending_message', 'require_login_message' ), true ) ) {
            $form[ $form_key ] = sanitize_text_field( wp_unslash( $value ) );
        } elseif ( in_array( $input_key, array( 'enable_honeypot', 'enable_animation', 'limit_entries', 'schedule_form', 'require_login', 'save_enabled' ), true ) ) {
            $form[ $form_key ] = (bool) $value;
        } elseif ( 'limit_entries_count' === $input_key ) {
            $form[ $form_key ] = intval( $value );
        } else {
            $form[ $form_key ] = sanitize_text_field( wp_unslash( $value ) );
        }
        $updated[] = $input_key;
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No settings provided to update.' );
    }

    $result = GFAPI::update_form( $form, $form_id );
    if ( is_wp_error( $result ) ) {
        return array( 'success' => false, 'error' => $result->get_error_message() );
    }

    return array(
        'success' => true,
        'id'      => $form_id,
        'updated' => $updated,
    );
}
