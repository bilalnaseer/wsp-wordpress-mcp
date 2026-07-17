<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Media library abilities.
 *
 * Read:  wsp_execute_list_media, wsp_execute_get_media, wsp_execute_count_media
 * Write: wsp_execute_update_media, wsp_execute_delete_media,
 *        wsp_execute_upload_media, wsp_execute_upload_media_from_url,
 *        wsp_execute_set_featured_image
 */

/**
 * Build a normalized metadata array for one attachment.
 *
 * @param int|WP_Post $attachment Attachment ID or post object.
 * @return array|null
 */
function wsp_media_item_data( $attachment ) {
    $post = get_post( $attachment );
    if ( ! $post || 'attachment' !== $post->post_type ) {
        return null;
    }
    $id   = $post->ID;
    $file = get_attached_file( $id );
    return array(
        'id'          => $id,
        'title'       => $post->post_title,
        'url'         => wp_get_attachment_url( $id ),
        'type'        => $post->post_mime_type,
        'date'        => get_the_date( 'Y-m-d', $id ),
        'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
        'caption'     => $post->post_excerpt,
        'description' => $post->post_content,
        'filename'    => $file ? wp_basename( $file ) : '',
        'filesize'    => ( $file && file_exists( $file ) ) ? filesize( $file ) : null,
        'metadata'    => wp_get_attachment_metadata( $id ),
        'author'      => get_the_author_meta( 'display_name', $post->post_author ),
        'parent'      => (int) $post->post_parent,
    );
}

/**
 * Browse and search the media library by type, keyword, or date.
 */
function wsp_execute_list_media( $input ) {
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20,
        'paged'          => isset( $input['page'] ) ? max( 1, intval( $input['page'] ) ) : 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if ( ! empty( $input['type'] ) ) {
        $args['post_mime_type'] = sanitize_mime_type( $input['type'] );
    }
    if ( ! empty( $input['search'] ) ) {
        $args['s'] = sanitize_text_field( wp_unslash( $input['search'] ) );
    }
    if ( ! empty( $input['year'] ) )  $args['year']     = intval( $input['year'] );
    if ( ! empty( $input['month'] ) ) $args['monthnum'] = intval( $input['month'] );

    $q      = new WP_Query( $args );
    $result = array();
    foreach ( $q->posts as $item ) {
        $result[] = array(
            'id'    => $item->ID,
            'title' => $item->post_title,
            'url'   => wp_get_attachment_url( $item->ID ),
            'type'  => $item->post_mime_type,
            'date'  => get_the_date( 'Y-m-d', $item->ID ),
        );
    }
    return array( 'media' => $result, 'total' => $q->found_posts );
}

/**
 * Retrieve the full metadata of a specific media file by ID.
 */
function wsp_execute_get_media( $input ) {
    $id   = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    $data = wsp_media_item_data( $id );
    if ( null === $data ) {
        return array( 'success' => false, 'error' => 'Media item not found.' );
    }
    return array( 'success' => true, 'media' => $data );
}

/**
 * Get media library counts grouped by MIME type, plus a total.
 */
function wsp_execute_count_media( $input ) {
    $counts = (array) wp_count_attachments();
    $by_type = array();
    $total   = 0;
    foreach ( $counts as $mime => $count ) {
        $count            = intval( $count );
        $by_type[ $mime ] = $count;
        $total           += $count;
    }
    return array( 'by_type' => $by_type, 'total' => $total );
}

/**
 * Update the title, alt text, caption, or description of a media file by ID.
 */
function wsp_execute_update_media( $input ) {
    $id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( 'attachment' !== get_post_type( $id ) ) {
        return array( 'success' => false, 'error' => 'Media item not found.' );
    }
    $args = array( 'ID' => $id );
    if ( isset( $input['title'] ) )       $args['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( isset( $input['caption'] ) )     $args['post_excerpt'] = sanitize_text_field( wp_unslash( $input['caption'] ) );
    if ( isset( $input['description'] ) ) $args['post_content'] = wp_kses_post( wp_unslash( $input['description'] ) );

    if ( count( $args ) > 1 ) {
        $res = wp_update_post( $args, true );
        if ( is_wp_error( $res ) ) {
            return array( 'success' => false, 'error' => $res->get_error_message() );
        }
    }
    if ( isset( $input['alt'] ) ) {
        update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( wp_unslash( $input['alt'] ) ) );
    }
    return array( 'success' => true, 'media' => wsp_media_item_data( $id ) );
}

/**
 * Permanently delete a media file from the media library by ID.
 */
function wsp_execute_delete_media( $input ) {
    $id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
    if ( 'attachment' !== get_post_type( $id ) ) {
        return array( 'success' => false, 'error' => 'Media item not found.' );
    }
    $deleted = wp_delete_attachment( $id, true );
    return $deleted
        ? array( 'success' => true,  'message' => "Media {$id} permanently deleted." )
        : array( 'success' => false, 'error'   => 'Could not delete media item.' );
}

/**
 * Download a file from a URL and sideload it into the media library.
 * Shared implementation for wsp_upload_media and wsp_upload_media_from_url.
 */
function wsp_execute_upload_media_from_url( $input ) {
    $url = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
    if ( empty( $url ) ) {
        return array( 'success' => false, 'error' => 'A valid "url" is required.' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url( $url );
    if ( is_wp_error( $tmp ) ) {
        return array( 'success' => false, 'error' => $tmp->get_error_message() );
    }

    $name = ! empty( $input['filename'] )
        ? sanitize_file_name( $input['filename'] )
        : wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
    if ( empty( $name ) ) {
        $name = 'upload-' . time();
    }

    if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $name ) ) {
        if ( preg_match( '/\.(jpg|jpeg|jpe|png|gif|webp)$/i', basename( $url ) ) ) {
            $name .= substr( basename( $url ), strrpos( basename( $url ), '.' ) );
        } else {
            return array( 'success' => false, 'error' => 'Only image files (jpg, png, gif, webp) are supported.' );
        }
    }

    $file_array = array( 'name' => $name, 'tmp_name' => $tmp );
    $post_id    = isset( $input['post_id'] ) ? intval( $input['post_id'] ) : 0;

    $mime_filter = function( $mimes ) {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['png'] = 'image/png';
        $mimes['gif'] = 'image/gif';
        $mimes['webp'] = 'image/webp';
        return $mimes;
    };

    $filetype_filter = function( $checked, $file, $filename, $mimes ) {
        if ( empty( $checked['type'] ) ) {
            $filetype_info = wp_check_filetype( $filename, $mimes );
            $checked['type'] = $filetype_info['type'];
            $checked['ext']  = $filetype_info['ext'];
        }
        return $checked;
    };

    add_filter( 'upload_mimes', $mime_filter );
    add_filter( 'wp_check_filetype_and_ext', $filetype_filter, 10, 4 );

    $id = media_handle_sideload( $file_array, $post_id );

    remove_filter( 'upload_mimes', $mime_filter );
    remove_filter( 'wp_check_filetype_and_ext', $filetype_filter, 10, 4 );

    if ( is_wp_error( $id ) ) {
        if ( file_exists( $tmp ) ) {
            wp_delete_file( $tmp );
        }
        return array( 'success' => false, 'error' => $id->get_error_message() );
    }

    $update = array( 'ID' => $id );
    if ( ! empty( $input['title'] ) )   $update['post_title']   = sanitize_text_field( wp_unslash( $input['title'] ) );
    if ( ! empty( $input['caption'] ) ) $update['post_excerpt'] = sanitize_text_field( wp_unslash( $input['caption'] ) );
    if ( count( $update ) > 1 ) {
        wp_update_post( $update );
    }
    if ( ! empty( $input['alt'] ) ) {
        update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( wp_unslash( $input['alt'] ) ) );
    }

    return array( 'success' => true, 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'media' => wsp_media_item_data( $id ) );
}

/**
 * Upload an image or file from a URL directly into the media library.
 * Thin wrapper around the shared sideload implementation.
 */
function wsp_execute_upload_media( $input ) {
    return wsp_execute_upload_media_from_url( $input );
}

/**
 * Set an image as the featured image (thumbnail) for a post or page.
 */
function wsp_execute_set_featured_image( $input ) {
    $post_id       = isset( $input['post_id'] ) ? intval( $input['post_id'] ) : 0;
    $attachment_id = isset( $input['attachment_id'] ) ? intval( $input['attachment_id'] ) : 0;

    if ( ! $post_id ) {
        return array( 'success' => false, 'error' => 'post_id is required.' );
    }
    if ( ! $attachment_id ) {
        return array( 'success' => false, 'error' => 'attachment_id is required.' );
    }

    if ( ! get_post( $post_id ) ) {
        return array( 'success' => false, 'error' => "Post {$post_id} not found." );
    }
    if ( 'attachment' !== get_post_type( $attachment_id ) ) {
        return array( 'success' => false, 'error' => "Attachment {$attachment_id} not found or not an image." );
    }

    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return array( 'success' => false, 'error' => 'Attachment must be an image.' );
    }

    if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
        return array( 'success' => false, 'error' => 'Failed to set featured image.' );
    }

    return array(
        'success'           => true,
        'post_id'           => $post_id,
        'featured_image_id' => $attachment_id,
        'message'           => "Featured image set successfully for post {$post_id}."
    );
}
