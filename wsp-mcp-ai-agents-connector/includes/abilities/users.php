<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_execute_get_users( $input ) {
    $users  = get_users();
    $result = array();
    foreach ( $users as $u ) {
        $result[] = array(
            'id'           => $u->ID,
            'display_name' => $u->display_name,
            'email'        => $u->user_email,
            'roles'        => $u->roles,
            'registered'   => $u->user_registered,
        );
    }
    return array( 'users' => $result );
}

function wsp_execute_create_user( $input ) {
    if ( empty( $input['username'] ) || empty( $input['email'] ) ) {
        return array( 'success' => false, 'error' => 'username and email are required.' );
    }
    $username = sanitize_user( wp_unslash( $input['username'] ) );
    $email    = sanitize_email( wp_unslash( $input['email'] ) );
    if ( ! is_email( $email ) )        return array( 'success' => false, 'error' => 'Invalid email address.' );
    if ( username_exists( $username ) ) return array( 'success' => false, 'error' => 'That username is already taken.' );
    if ( email_exists( $email ) )       return array( 'success' => false, 'error' => 'That email is already registered.' );

    $args = array(
        'user_login' => $username,
        'user_email' => $email,
        'user_pass'  => ! empty( $input['password'] ) ? $input['password'] : wp_generate_password( 16 ),
        'role'       => ! empty( $input['role'] ) ? sanitize_key( $input['role'] ) : 'subscriber',
    );
    if ( ! empty( $input['display_name'] ) ) $args['display_name'] = sanitize_text_field( wp_unslash( $input['display_name'] ) );

    $id = wp_insert_user( $args );
    if ( is_wp_error( $id ) ) return array( 'success' => false, 'error' => $id->get_error_message() );
    return array( 'success' => true, 'id' => $id, 'username' => $username, 'email' => $email );
}

function wsp_execute_update_user( $input ) {
    if ( empty( $input['id'] ) ) return array( 'success' => false, 'error' => 'id is required.' );
    $id   = intval( $input['id'] );
    $user = get_userdata( $id );
    if ( ! $user ) return array( 'success' => false, 'error' => 'User not found.' );

    $args = array( 'ID' => $id );
    if ( isset( $input['email'] ) ) {
        $email = sanitize_email( wp_unslash( $input['email'] ) );
        if ( ! is_email( $email ) ) return array( 'success' => false, 'error' => 'Invalid email address.' );
        $args['user_email'] = $email;
    }
    if ( isset( $input['display_name'] ) ) $args['display_name'] = sanitize_text_field( wp_unslash( $input['display_name'] ) );
    if ( isset( $input['role'] ) )         $args['role']         = sanitize_key( $input['role'] );
    if ( ! empty( $input['password'] ) )   $args['user_pass']    = $input['password'];

    $result = wp_update_user( $args );
    if ( is_wp_error( $result ) ) return array( 'success' => false, 'error' => $result->get_error_message() );
    return array( 'success' => true, 'id' => $id );
}
