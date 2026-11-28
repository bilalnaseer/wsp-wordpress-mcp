<?php
/**
 * Contact Form 7 abilities: forms, Flamingo entries, validation, integrations and moderation.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wsp_cf7_is_active' ) ) {
/**
 * Whether Contact Form 7 is active.
 *
 * @return bool
 */
function wsp_cf7_is_active() {
    return class_exists( 'WPCF7_ContactForm' );
}
}

if ( ! function_exists( 'wsp_cf7_flamingo_is_active' ) ) {
/**
 * Whether the Flamingo add-on, which stores Contact Form 7 submissions, is active.
 *
 * @return bool
 */
function wsp_cf7_flamingo_is_active() {
    return class_exists( 'Flamingo_Inbound_Message' );
}
}

// ---------------------------------------------
// EXECUTE CALLBACKS
// ---------------------------------------------

/**
 * Lists all Contact Form 7 forms with ID, title, and shortcode.
 *
 * MCP tool: `wsp_cf7_list_forms`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_list_forms( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $forms = WPCF7_ContactForm::find();
    $result = array();

    foreach ( $forms as $form ) {
        $cf7 = wpcf7_contact_form( $form->id() );
        $result[] = array(
            'id'        => $form->id(),
            'title'     => $form->title(),
            'shortcode' => $cf7 ? $cf7->shortcode() : '[contact-form-7 id="' . $form->id() . '" title="' . esc_attr( $form->title() ) . '"]',
            'slug'      => $form->name(),
            'locale'    => $form->locale(),
        );
    }

    return array( 'forms' => $result, 'total' => count( $result ) );
}

/**
 * Retrieves full CF7 form structure (markup, mail config, messages, tags).
 *
 * MCP tool: `wsp_cf7_get_form`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_get_form( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = WPCF7_ContactForm::get_instance( $form_id );
    if ( ! $form ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $tags = array();
    $form_tags = $form->scan_form_tags();
    foreach ( $form_tags as $tag ) {
        $tags[] = array(
            'name'  => $tag->name,
            'type'  => $tag->type,
            'basetype'=> $tag->basetype,
            'raw_name'=> $tag->raw_name,
            'options' => $tag->options,
            'values'  => $tag->values,
            'labels'  => $tag->labels,
        );
    }

    return array(
        'success'     => true,
        'id'          => $form->id(),
        'title'       => $form->title(),
        'slug'        => $form->name(),
        'shortcode'   => $form->shortcode(),
        'locale'      => $form->locale(),
        'form_markup' => $form->prop( 'form' ),
        'mail'        => $form->prop( 'mail' ),
        'mail_2'      => $form->prop( 'mail_2' ),
        'messages'    => $form->prop( 'messages' ),
        'additional_settings' => $form->prop( 'additional_settings' ),
        'tags'        => $tags,
    );
}

/**
 * Creates a new Contact Form 7 form with title and optional markup/properties.
 *
 * MCP tool: `wsp_cf7_create_form`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type string $title Required. Form title.
 *     @type string $locale Optional. Locale code (e.g. en_US).
 *     @type string $form_markup Optional. Custom form markup HTML.
 *     @type array $mail Optional. Mail settings key-value pairs.
 *     @type array $messages Optional. Custom messages key-value pairs.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_create_form( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $title = isset( $input['title'] ) ? sanitize_text_field( wp_unslash( $input['title'] ) ) : '';
    if ( ! $title ) {
        return array( 'success' => false, 'error' => 'Form title is required.' );
    }

    $template = WPCF7_ContactForm::get_template();
    $template->set_title( $title );

    if ( isset( $input['locale'] ) ) {
        $template->set_locale( sanitize_text_field( wp_unslash( $input['locale'] ) ) );
    }

    if ( isset( $input['form_markup'] ) ) {
        $template->set_properties( array(
            'form' => wp_kses_post( wp_unslash( $input['form_markup'] ) ),
        ) );
    }

    if ( isset( $input['mail'] ) && is_array( $input['mail'] ) ) {
        $mail = array();
        foreach ( $input['mail'] as $k => $v ) {
            $mail[ sanitize_text_field( $k ) ] = sanitize_text_field( wp_unslash( $v ) );
        }
        $props = $template->get_properties();
        $props['mail'] = $mail;
        $template->set_properties( $props );
    }

    if ( isset( $input['messages'] ) && is_array( $input['messages'] ) ) {
        $messages = array();
        foreach ( $input['messages'] as $k => $v ) {
            $messages[ sanitize_text_field( $k ) ] = wp_kses_post( wp_unslash( $v ) );
        }
        $props = $template->get_properties();
        $props['messages'] = $messages;
        $template->set_properties( $props );
    }

    $form_id = $template->save();

    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Failed to create form.' );
    }

    return array(
        'success'   => true,
        'id'        => $form_id,
        'title'     => $title,
        'shortcode' => sprintf( '[contact-form-7 id="%s" title="%s"]', $form_id, esc_attr( $title ) ),
    );
}

/**
 * Updates an existing CF7 form markup, mail settings, or messages.
 *
 * MCP tool: `wsp_cf7_update_form`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type string $title Optional. New form title.
 *     @type string $locale Optional. Locale code.
 *     @type string $form_markup Optional. Updated form markup.
 *     @type array $mail Optional. Updated mail settings.
 *     @type array $mail_2 Optional. Updated mail (2) settings.
 *     @type array $messages Optional. Updated messages.
 *     @type string $additional_settings Optional. Additional settings text.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_update_form( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = WPCF7_ContactForm::get_instance( $form_id );
    if ( ! $form ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $updated = array();

    if ( isset( $input['title'] ) ) {
        $form->set_title( sanitize_text_field( wp_unslash( $input['title'] ) ) );
        $updated[] = 'title';
    }

    if ( isset( $input['locale'] ) ) {
        $form->set_locale( sanitize_text_field( wp_unslash( $input['locale'] ) ) );
        $updated[] = 'locale';
    }

    $props = $form->get_properties();

    if ( isset( $input['form_markup'] ) ) {
        $props['form'] = wp_kses_post( wp_unslash( $input['form_markup'] ) );
        $updated[] = 'form_markup';
    }

    if ( isset( $input['mail'] ) && is_array( $input['mail'] ) ) {
        $mail = isset( $props['mail'] ) ? $props['mail'] : array();
        foreach ( $input['mail'] as $k => $v ) {
            $mail[ sanitize_text_field( $k ) ] = sanitize_text_field( wp_unslash( $v ) );
        }
        $props['mail'] = $mail;
        $updated[] = 'mail';
    }

    if ( isset( $input['mail_2'] ) && is_array( $input['mail_2'] ) ) {
        $mail_2 = isset( $props['mail_2'] ) ? $props['mail_2'] : array();
        foreach ( $input['mail_2'] as $k => $v ) {
            $mail_2[ sanitize_text_field( $k ) ] = sanitize_text_field( wp_unslash( $v ) );
        }
        $props['mail_2'] = $mail_2;
        $updated[] = 'mail_2';
    }

    if ( isset( $input['messages'] ) && is_array( $input['messages'] ) ) {
        $messages = isset( $props['messages'] ) ? $props['messages'] : array();
        foreach ( $input['messages'] as $k => $v ) {
            $messages[ sanitize_text_field( $k ) ] = wp_kses_post( wp_unslash( $v ) );
        }
        $props['messages'] = $messages;
        $updated[] = 'messages';
    }

    if ( isset( $input['additional_settings'] ) ) {
        $props['additional_settings'] = sanitize_textarea_field( wp_unslash( $input['additional_settings'] ) );
        $updated[] = 'additional_settings';
    }

    if ( empty( $updated ) ) {
        return array( 'success' => false, 'error' => 'No fields to update provided.' );
    }

    $form->set_properties( $props );
    $result = $form->save();

    if ( ! $result ) {
        return array( 'success' => false, 'error' => 'Failed to update form.' );
    }

    return array(
        'success' => true,
        'id'      => $form_id,
        'updated' => $updated,
    );
}

/**
 * Trashes or permanently deletes a Contact Form 7 form.
 *
 * MCP tool: `wsp_cf7_delete_form`. Requires the `wpcf7_delete_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 *     @type bool $permanent Optional. True for permanent delete, false to move to trash. Default
 *         false.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_delete_form( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = WPCF7_ContactForm::get_instance( $form_id );
    if ( ! $form ) {
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
        'success'  => true,
        'message'  => $permanent ? 'Form permanently deleted.' : 'Form moved to trash.',
        'id'       => $form_id,
    );
}

/**
 * Lists Flamingo-stored form submissions (requires Flamingo plugin).
 *
 * MCP tool: `wsp_cf7_list_entries`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $form_id Optional. Form ID to filter by.
 *     @type int $per_page Optional. Number of entries. Default 20.
 *     @type int $page Optional. Page number. Default 1.
 *     @type string $status Optional. publish | spam | trash | all.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_list_entries( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
        return array( 'success' => false, 'error' => 'Flamingo plugin is required for entry storage. Please install and activate Flamingo.' );
    }

    $form_id = isset( $input['form_id'] ) ? intval( $input['form_id'] ) : 0;
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $page     = isset( $input['page'] ) ? intval( $input['page'] ) : 1;
    $status   = isset( $input['status'] ) ? sanitize_text_field( wp_unslash( $input['status'] ) ) : '';

    $args = array(
        'posts_per_page' => $per_page,
        'offset'         => ( $page - 1 ) * $per_page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    if ( $form_id ) {
        $args['channel'] = get_post_field( 'post_name', $form_id );
    }

    if ( $status && 'all' !== $status ) {
        if ( 'spam' === $status ) {
            $args['meta_key']   = '_spam_';
            $args['meta_value'] = '1';
        } elseif ( 'trash' === $status ) {
            $args['post_status'] = 'trash';
        } else {
            $args['post_status'] = 'publish';
        }
    }

    $total = Flamingo_Inbound_Message::count( $args );
    $entries = Flamingo_Inbound_Message::find( $args );

    $items = array();
    foreach ( $entries as $entry ) {
        $items[] = array(
            'id'        => $entry->id(),
            'subject'   => $entry->subject,
            'from'      => $entry->from,
            'from_email'=> $entry->from_email,
            'channel'   => $entry->channel,
            'date'      => $entry->date,
            'spam'      => ! empty( $entry->spam ),
            'status'    => get_post_status( $entry->id() ),
            'fields'    => $entry->fields,
        );
    }

    return array(
        'entries'  => $items,
        'total'    => (int) $total,
        'page'     => $page,
        'per_page' => $per_page,
    );
}

/**
 * Retrieves full details of a single Flamingo submission by ID.
 *
 * MCP tool: `wsp_cf7_get_entry`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Entry ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_get_entry( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
        return array( 'success' => false, 'error' => 'Flamingo plugin is required for entry storage. Please install and activate Flamingo.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $entry = new Flamingo_Inbound_Message( $entry_id );
    if ( ! $entry || ! $entry->id() ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    $channel_title = '';
    if ( $entry->channel ) {
        $channel_post = get_page_by_path( $entry->channel, OBJECT, 'wpcf7_contact_form' );
        if ( $channel_post ) {
            $channel_title = get_the_title( $channel_post );
        }
    }

    return array(
        'success'       => true,
        'id'            => $entry->id(),
        'subject'       => $entry->subject,
        'from'          => $entry->from,
        'from_email'    => $entry->from_email,
        'from_name'     => $entry->from_name,
        'channel'       => $entry->channel,
        'channel_title' => $channel_title,
        'date'          => $entry->date,
        'spam'          => ! empty( $entry->spam ),
        'status'        => get_post_status( $entry->id() ),
        'fields'        => $entry->fields,
        'meta'          => $entry->meta,
        'consent'       => $entry->consent,
        'remote_ip'     => $entry->remote_ip,
        'user_agent'    => $entry->user_agent,
    );
}

/**
 * Runs the built-in configuration validator to check for email/syntax errors.
 *
 * MCP tool: `wsp_cf7_validate_form`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Form ID.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_validate_form( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $form_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $form_id ) {
        return array( 'success' => false, 'error' => 'Form ID is required.' );
    }

    $form = WPCF7_ContactForm::get_instance( $form_id );
    if ( ! $form ) {
        return array( 'success' => false, 'error' => 'Form not found.' );
    }

    $errors = array();

    $mail = $form->prop( 'mail' );

    if ( empty( $mail ) || ! is_array( $mail ) ) {
        $errors[] = array(
            'code'    => 'no_mail_settings',
            'message' => 'Mail settings are missing or empty.',
        );
    } else {
        if ( empty( $mail['recipient'] ) ) {
            $errors[] = array(
                'code'    => 'mail_empty_recipient',
                'message' => 'Mail recipient email is empty.',
            );
        }
        if ( empty( $mail['subject'] ) ) {
            $errors[] = array(
                'code'    => 'mail_empty_subject',
                'message' => 'Mail subject is empty.',
            );
        }
        if ( empty( $mail['sender'] ) ) {
            $errors[] = array(
                'code'    => 'mail_empty_sender',
                'message' => 'Mail sender is empty.',
            );
        }
    }

    $form_markup = $form->prop( 'form' );
    if ( empty( trim( $form_markup ) ) ) {
        $errors[] = array(
            'code'    => 'empty_form_template',
            'message' => 'Form template is empty — no form tags defined.',
        );
    } else {
        $form_tags = $form->scan_form_tags();
        $required_tags = array();
        foreach ( $form_tags as $tag ) {
            if ( ! empty( $tag->required ) || ( $tag->type && strpos( $tag->type, '*' ) !== false ) ) {
                $required_tags[] = $tag->name;
            }
        }
        if ( empty( $required_tags ) ) {
            $errors[] = array(
                'code'    => 'no_required_fields',
                'message' => 'No required fields found in the form. Consider marking at least one field as required.',
            );
        }
    }

    $mail_2 = $form->prop( 'mail_2' );
    if ( ! empty( $mail_2 ) && is_array( $mail_2 ) && ! empty( $mail_2['active'] ) ) {
        if ( empty( $mail_2['recipient'] ) ) {
            $errors[] = array(
                'code'    => 'mail2_empty_recipient',
                'message' => 'Mail (2) auto-reply recipient is empty.',
            );
        }
    }

    if ( class_exists( 'WPCF7_ConfigValidator' ) ) {
        $validator = new WPCF7_ConfigValidator( $form );
        $validator->validate();

        $validator_errors = array();
        if ( isset( $validator->error ) && is_array( $validator->error ) && ! empty( $validator->error ) ) {
            $validator_errors = $validator->error;
        } elseif ( method_exists( $validator, 'collect_error_messages' ) ) {
            $collected = $validator->collect_error_messages();
            if ( is_array( $collected ) ) {
                $validator_errors = $collected;
            }
        }

        foreach ( $validator_errors as $code => $message ) {
            $errors[] = array(
                'code'    => sanitize_text_field( $code ),
                'message' => sanitize_text_field( $message ),
            );
        }
    }

    return array(
        'success'       => true,
        'id'            => $form_id,
        'title'         => $form->title(),
        'is_valid'      => count( $errors ) === 0,
        'error_count'   => count( $errors ),
        'errors'        => $errors,
    );
}

/**
 * Lists active integration modules and reCAPTCHA configuration status.
 *
 * MCP tool: `wsp_cf7_get_integrations`. Requires the `manage_options` capability.
 *
 * @param array $input Tool arguments (this tool takes none).
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_get_integrations( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    $integrations = array();

    if ( method_exists( 'WPCF7_Integration', 'list_modules' ) ) {
        $modules = WPCF7_Integration::list_modules();
        foreach ( $modules as $module_slug => $module ) {
            $integrations[] = array(
                'id'        => $module_slug,
                'name'      => sanitize_text_field( $module->name ),
                'is_active' => $module->is_active(),
            );
        }
    }

    $wpcf7_option = get_option( 'wpcf7', array() );
    $recaptcha    = isset( $wpcf7_option['recaptcha'] ) ? $wpcf7_option['recaptcha'] : array();

    $recaptcha_info = array();
    foreach ( $recaptcha as $site => $keys ) {
        if ( is_array( $keys ) ) {
            $recaptcha_info[] = array(
                'site'        => sanitize_text_field( $site ),
                'has_site_key'   => ! empty( $keys['sitekey'] ),
                'has_secret_key' => ! empty( $keys['secret'] ),
            );
        }
    }

    return array(
        'success'               => true,
        'active_integrations'   => $integrations,
        'total_integrations'    => count( $integrations ),
        'recaptcha_configured'  => $recaptcha_info,
        'has_recaptcha'         => ! empty( $recaptcha ),
    );
}

/**
 * Mark a Flamingo submission as spam, unspam, trash, or untrash.
 *
 * MCP tool: `wsp_cf7_moderate_entry`. Requires the `wpcf7_edit_contact_forms` capability.
 *
 * @param array $input {
 *     Tool arguments.
 *
 *     @type int $id Required. Entry ID.
 *     @type string $action Required. spam | unspam | trash | untrash.
 * }
 * @return array|WP_Error Tool result, or a WP_Error describing the failure.
 */
function wsp_execute_cf7_moderate_entry( $input ) {
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        return array( 'success' => false, 'error' => 'Contact Form 7 is not active.' );
    }

    if ( ! class_exists( 'Flamingo_Inbound_Message' ) ) {
        return array( 'success' => false, 'error' => 'Flamingo plugin is required for entry storage. Please install and activate Flamingo.' );
    }

    $entry_id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( ! $entry_id ) {
        return array( 'success' => false, 'error' => 'Entry ID is required.' );
    }

    $action = isset( $input['action'] ) ? sanitize_text_field( wp_unslash( $input['action'] ) ) : '';
    $allowed_actions = array( 'spam', 'unspam', 'trash', 'untrash' );

    if ( ! in_array( $action, $allowed_actions, true ) ) {
        return array( 'success' => false, 'error' => 'Invalid action. Allowed: spam, unspam, trash, untrash.' );
    }

    $entry = new Flamingo_Inbound_Message( $entry_id );
    if ( ! $entry || ! $entry->id() ) {
        return array( 'success' => false, 'error' => 'Entry not found.' );
    }

    switch ( $action ) {
        case 'spam':
            $entry->spam();
            break;
        case 'unspam':
            $entry->unspam();
            break;
        case 'trash':
            wp_trash_post( $entry_id );
            break;
        case 'untrash':
            wp_untrash_post( $entry_id );
            break;
    }

    return array(
        'success' => true,
        'message' => 'Entry ' . $action . ' successfully.',
        'id'      => $entry_id,
        'action'  => $action,
    );
}
