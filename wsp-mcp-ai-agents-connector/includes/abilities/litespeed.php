<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Check if LiteSpeed Cache is active.
 */
if ( ! function_exists( 'wsp_litespeed_is_active' ) ) {
    function wsp_litespeed_is_active() {
        return class_exists( '\LiteSpeed\Conf' ) && class_exists( '\LiteSpeed\Purge' );
    }
}

function wsp_execute_litespeed_purge_all( $input ) {
    if ( ! wsp_litespeed_is_active() ) return array( 'success' => false, 'error' => 'LiteSpeed Cache plugin is not active.' );
    do_action( 'litespeed_purge_all' );
    return array( 'success' => true, 'message' => 'Purged all LiteSpeed caches.' );
}

function wsp_execute_litespeed_purge_url( $input ) {
    if ( ! wsp_litespeed_is_active() ) return array( 'success' => false, 'error' => 'LiteSpeed Cache plugin is not active.' );
    if ( empty( $input['url'] ) ) return array( 'success' => false, 'error' => 'url is required.' );
    $url = esc_url_raw( wp_unslash( $input['url'] ) );
    do_action( 'litespeed_purge_url', $url );
    return array( 'success' => true, 'url' => $url );
}

function wsp_execute_litespeed_purge_post( $input ) {
    if ( ! wsp_litespeed_is_active() ) return array( 'success' => false, 'error' => 'LiteSpeed Cache plugin is not active.' );
    if ( empty( $input['post_id'] ) ) return array( 'success' => false, 'error' => 'post_id is required.' );
    $post_id = intval( $input['post_id'] );
    if ( ! get_post( $post_id ) ) return array( 'success' => false, 'error' => 'Post not found: ' . $post_id );
    do_action( 'litespeed_purge_post', $post_id );
    return array( 'success' => true, 'post_id' => $post_id );
}

function wsp_execute_litespeed_get_cache_status( $input ) {
    if ( ! wsp_litespeed_is_active() ) return array( 'success' => false, 'error' => 'LiteSpeed Cache plugin is not active.' );
    $conf = \LiteSpeed\Conf::cls();
    return array(
        'success'       => true,
        'cache_enabled' => (bool) $conf->conf( \LiteSpeed\Base::O_CACHE ),
    );
}

function wsp_execute_litespeed_set_cache_status( $input ) {
    if ( ! wsp_litespeed_is_active() ) return array( 'success' => false, 'error' => 'LiteSpeed Cache plugin is not active.' );
    if ( ! isset( $input['enabled'] ) ) return array( 'success' => false, 'error' => 'enabled (true|false) is required.' );
    $enabled = (bool) $input['enabled'];
    \LiteSpeed\Conf::cls()->update_confs( array( \LiteSpeed\Base::O_CACHE => $enabled ) );
    return array( 'success' => true, 'cache_enabled' => $enabled );
}
