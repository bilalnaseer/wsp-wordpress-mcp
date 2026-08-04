<?php
/**
 * GeoDirectory listing abilities.
 *
 * These callbacks deliberately proxy GeoDirectory's registered REST
 * controller. They do not write GeoDirectory detail tables or arbitrary post
 * meta directly.
 *
 * @package WSP_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wsp_geodirectory_is_active' ) ) {
	function wsp_geodirectory_is_active() {
		return function_exists( 'geodir_get_posttypes' ) || defined( 'GEODIRECTORY_VERSION' );
	}
}

/** Return the only listing fields accepted by the write tools. */
function wsp_geodirectory_allowed_listing_fields() {
	return array(
		'title',
		'content',
		'post_tags',
		'post_category',
		'street',
		'country',
		'region',
		'city',
		'zip',
		'latitude',
		'longitude',
		'website',
		'business_hours',
		'has_benches',
		'has_water_features',
		'has_parking',
		'off_leash',
		'has_gates',
		'has_separate_smalllarge_areas',
		'slug',
		'status',
		'featured_media',
	);
}

/** JSON Schema properties shared by the native import and update tools. */
function wsp_geodirectory_listing_schema_properties() {
	$properties = array(
		'title'         => array( 'type' => 'string' ),
		'content'       => array( 'type' => 'string' ),
		'post_tags'     => array( 'type' => 'string', 'description' => 'Comma-separated GeoDirectory listing tags. Defaults to Dog Park on create.' ),
		'post_category' => array( 'type' => 'array', 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		'street'        => array( 'type' => 'string' ),
		'country'       => array( 'type' => 'string' ),
		'region'        => array( 'type' => 'string' ),
		'city'          => array( 'type' => 'string' ),
		'zip'           => array( 'type' => 'string' ),
		'latitude'      => array( 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ),
		'longitude'     => array( 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ),
		'website'       => array( 'type' => 'string', 'format' => 'uri' ),
		'business_hours'=> array( 'type' => 'string' ),
		'slug'          => array( 'type' => 'string' ),
		'status'        => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
		'featured_media'=> array( 'type' => 'integer', 'minimum' => 0 ),
	);
	foreach ( array( 'has_benches', 'has_water_features', 'has_parking', 'off_leash', 'has_gates', 'has_separate_smalllarge_areas' ) as $field ) {
		$properties[ $field ] = array( 'type' => array( 'boolean', 'integer' ), 'description' => 'Amenity flag; boolean or 0/1.' );
	}
	return $properties;
}

/** Validate that a requested post type is owned by GeoDirectory. */
function wsp_geodirectory_validate_post_type( $post_type = 'gd_place' ) {
	if ( ! wsp_geodirectory_is_active() || ! function_exists( 'geodir_get_posttypes' ) ) {
		return new WP_Error( 'wsp_geodirectory_unavailable', 'GeoDirectory is not active.' );
	}
	if ( ! is_scalar( $post_type ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_post_type', 'The GeoDirectory post type must be a string.' );
	}

	$post_type = $post_type ? sanitize_key( $post_type ) : 'gd_place';
	$post_types = geodir_get_posttypes();
	if ( ! in_array( $post_type, $post_types, true ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_post_type', 'The post type is not registered by GeoDirectory.' );
	}
	return $post_type;
}

/**
 * Validate and sanitize listing fields without permitting arbitrary meta.
 *
 * @param array $fields Listing fields.
 * @param bool  $create Whether required create fields/defaults apply.
 * @return array|WP_Error
 */
function wsp_geodirectory_validate_listing_fields( $fields, $create = false ) {
	if ( ! is_array( $fields ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_fields', 'Listing fields must be an object.' );
	}

	$unknown = array_values( array_diff( array_keys( $fields ), wsp_geodirectory_allowed_listing_fields() ) );
	if ( $unknown ) {
		return new WP_Error(
			'wsp_geodirectory_unknown_field',
			'Unsupported GeoDirectory listing field(s): ' . implode( ', ', $unknown ) . '.',
			array( 'fields' => $unknown )
		);
	}

	$clean = array();
	$text_fields = array( 'title', 'post_tags', 'street', 'country', 'region', 'city', 'zip', 'business_hours' );
	foreach ( $text_fields as $field ) {
		if ( array_key_exists( $field, $fields ) ) {
			if ( ! is_scalar( $fields[ $field ] ) ) {
				return new WP_Error( 'wsp_geodirectory_invalid_field', $field . ' must be a string.' );
			}
			$clean[ $field ] = sanitize_text_field( wp_unslash( (string) $fields[ $field ] ) );
		}
	}

	if ( array_key_exists( 'content', $fields ) ) {
		if ( ! is_scalar( $fields['content'] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'content must be a string.' );
		}
		$clean['content'] = wp_kses_post( wp_unslash( (string) $fields['content'] ) );
	}

	if ( array_key_exists( 'post_category', $fields ) ) {
		if ( ! is_array( $fields['post_category'] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'post_category must be an array of positive term IDs.' );
		}
		$categories = array();
		foreach ( $fields['post_category'] as $category ) {
			if ( ! is_int( $category ) && ! ( is_string( $category ) && ctype_digit( $category ) ) ) {
				return new WP_Error( 'wsp_geodirectory_invalid_field', 'post_category contains a non-integer term ID.' );
			}
			$category = (int) $category;
			if ( $category < 1 ) {
				return new WP_Error( 'wsp_geodirectory_invalid_field', 'post_category term IDs must be positive.' );
			}
			$categories[] = $category;
		}
		$clean['post_category'] = array_values( array_unique( $categories ) );
	}

	foreach ( array( 'latitude' => 90, 'longitude' => 180 ) as $field => $limit ) {
		if ( array_key_exists( $field, $fields ) ) {
			if ( ! is_numeric( $fields[ $field ] ) ) {
				return new WP_Error( 'wsp_geodirectory_invalid_field', $field . ' must be numeric.' );
			}
			$value = (float) $fields[ $field ];
			if ( $value < -$limit || $value > $limit ) {
				return new WP_Error( 'wsp_geodirectory_invalid_field', $field . ' is outside its valid range.' );
			}
			$clean[ $field ] = $value;
		}
	}

	if ( array_key_exists( 'website', $fields ) ) {
		if ( ! is_scalar( $fields['website'] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'website must be a string.' );
		}
		$website = esc_url_raw( wp_unslash( (string) $fields['website'] ) );
		if ( '' !== (string) $fields['website'] && '' === $website ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'website must be a valid URL.' );
		}
		$clean['website'] = $website;
	}

	foreach ( array( 'has_benches', 'has_water_features', 'has_parking', 'off_leash', 'has_gates', 'has_separate_smalllarge_areas' ) as $field ) {
		if ( array_key_exists( $field, $fields ) ) {
			$value = $fields[ $field ];
			if ( true === $value || 1 === $value || '1' === $value ) {
				$clean[ $field ] = 1;
			} elseif ( false === $value || 0 === $value || '0' === $value ) {
				$clean[ $field ] = 0;
			} else {
				return new WP_Error( 'wsp_geodirectory_invalid_field', $field . ' must be boolean or 0/1.' );
			}
		}
	}

	if ( array_key_exists( 'slug', $fields ) ) {
		if ( ! is_scalar( $fields['slug'] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'slug must be a string.' );
		}
		$clean['slug'] = sanitize_title( wp_unslash( (string) $fields['slug'] ) );
	}
	if ( array_key_exists( 'status', $fields ) ) {
		if ( ! is_scalar( $fields['status'] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'status must be a string.' );
		}
		$status = sanitize_key( $fields['status'] );
		if ( ! in_array( $status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'status must be draft, pending, private, or publish.' );
		}
		$clean['status'] = $status;
	}
	if ( array_key_exists( 'featured_media', $fields ) ) {
		if ( ! is_int( $fields['featured_media'] ) && ! ( is_string( $fields['featured_media'] ) && ctype_digit( $fields['featured_media'] ) ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'featured_media must be a non-negative attachment ID.' );
		}
		$clean['featured_media'] = (int) $fields['featured_media'];
		if ( $clean['featured_media'] < 0 ) {
			return new WP_Error( 'wsp_geodirectory_invalid_field', 'featured_media must be a non-negative attachment ID.' );
		}
	}

	if ( $create ) {
		if ( ! array_key_exists( 'post_tags', $clean ) || '' === $clean['post_tags'] ) {
			$clean['post_tags'] = 'Dog Park';
		}
		if ( ! array_key_exists( 'status', $clean ) ) {
			$clean['status'] = 'draft';
		}
		// GeoDirectory's REST schema has a malformed empty default on a
		// fresh install. An explicit empty array keeps category optional.
		if ( ! array_key_exists( 'post_category', $clean ) ) {
			$clean['post_category'] = array();
		}
		foreach ( array( 'title', 'street', 'country', 'region', 'city', 'latitude', 'longitude' ) as $required ) {
			if ( ! array_key_exists( $required, $clean ) || ( is_string( $clean[ $required ] ) && '' === $clean[ $required ] ) ) {
				return new WP_Error( 'wsp_geodirectory_required_field', 'Missing required listing field: ' . $required . '.', array( 'field' => $required ) );
			}
		}
	}

	return $clean;
}

/** Set sanitized fields using the scalar types GeoDirectory REST declares. */
function wsp_geodirectory_set_rest_params( $request, $fields ) {
	foreach ( $fields as $field => $value ) {
		if ( in_array( $field, array( 'latitude', 'longitude' ), true ) ) {
			$value = (string) $value;
		}
		$request->set_param( $field, $value );
	}
}

/** Return the registered GeoDirectory REST collection route for a CPT. */
function wsp_geodirectory_rest_route( $post_type, $id = 0 ) {
	$object = get_post_type_object( $post_type );
	if ( ! $object || empty( $object->rest_base ) ) {
		return new WP_Error( 'wsp_geodirectory_rest_unavailable', 'GeoDirectory REST routing is unavailable for this post type.' );
	}
	$namespace = ! empty( $object->rest_namespace ) ? $object->rest_namespace : 'geodir/v2';
	$route = '/' . trim( $namespace, '/' ) . '/' . trim( $object->rest_base, '/' );
	return $id ? $route . '/' . (int) $id : $route;
}

/** Turn an internal REST response failure into a stable WP_Error. */
function wsp_geodirectory_rest_error( $response ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( ! $response instanceof WP_REST_Response ) {
		return new WP_Error( 'wsp_geodirectory_rest_error', 'GeoDirectory returned an invalid REST response.' );
	}
	if ( $response->get_status() >= 400 ) {
		$data = $response->get_data();
		return new WP_Error(
			isset( $data['code'] ) ? $data['code'] : 'wsp_geodirectory_rest_error',
			isset( $data['message'] ) ? $data['message'] : 'GeoDirectory REST request failed.',
			$data
		);
	}
	return false;
}

/** Read a scalar from either a REST raw/rendered object or a direct value. */
function wsp_geodirectory_rest_scalar( $value ) {
	if ( is_array( $value ) ) {
		if ( array_key_exists( 'raw', $value ) ) return $value['raw'];
		if ( array_key_exists( 'rendered', $value ) ) return wp_strip_all_tags( $value['rendered'] );
		return '';
	}
	return is_scalar( $value ) ? $value : '';
}

/** Normalize a GeoDirectory REST item for tool results and comparisons. */
function wsp_geodirectory_normalize_item( $data ) {
	$item = array(
		'id'         => isset( $data['id'] ) ? (int) $data['id'] : 0,
		'title'      => isset( $data['title'] ) ? (string) wsp_geodirectory_rest_scalar( $data['title'] ) : '',
		'slug'       => isset( $data['slug'] ) ? (string) wsp_geodirectory_rest_scalar( $data['slug'] ) : '',
		'status'     => isset( $data['status'] ) ? (string) wsp_geodirectory_rest_scalar( $data['status'] ) : '',
		'url'        => isset( $data['link'] ) ? (string) $data['link'] : '',
		'content'    => isset( $data['content'] ) ? (string) wsp_geodirectory_rest_scalar( $data['content'] ) : '',
		'post_tags'  => isset( $data['post_tags'] ) ? wsp_geodirectory_rest_scalar( $data['post_tags'] ) : '',
		'post_category' => isset( $data['post_category'] ) ? $data['post_category'] : array(),
	);
	foreach ( array( 'street', 'country', 'region', 'city', 'zip', 'website', 'business_hours' ) as $field ) {
		$item[ $field ] = isset( $data[ $field ] ) ? (string) wsp_geodirectory_rest_scalar( $data[ $field ] ) : '';
	}
	foreach ( array( 'latitude', 'longitude' ) as $field ) {
		$value = isset( $data[ $field ] ) ? wsp_geodirectory_rest_scalar( $data[ $field ] ) : '';
		$item[ $field ] = is_numeric( $value ) ? (float) $value : null;
	}
	foreach ( array( 'has_benches', 'has_water_features', 'has_parking', 'off_leash', 'has_gates', 'has_separate_smalllarge_areas' ) as $field ) {
		$value = isset( $data[ $field ] ) ? wsp_geodirectory_rest_scalar( $data[ $field ] ) : 0;
		$item[ $field ] = ! empty( $value ) ? 1 : 0;
	}
	$item['featured_media'] = isset( $data['featured_media'] ) ? (int) $data['featured_media'] : 0;
	return $item;
}

/** Fetch matching listings through GeoDirectory's own REST collection controller. */
function wsp_geodirectory_rest_listings( $post_type, $search = '' ) {
	$route = wsp_geodirectory_rest_route( $post_type );
	if ( is_wp_error( $route ) ) return $route;

	$items = array();
	$page = 1;
	do {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'status', array( 'any' ) );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', $page );
		if ( '' !== $search ) $request->set_param( 'search', $search );
		$response = rest_do_request( $request );
		$error = wsp_geodirectory_rest_error( $response );
		if ( $error ) return $error;
		$data = $response->get_data();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$items[] = wsp_geodirectory_normalize_item( $row );
		}
		$headers = array_change_key_case( $response->get_headers(), CASE_LOWER );
		$total_pages = max( 1, isset( $headers['x-wp-totalpages'] ) ? (int) $headers['x-wp-totalpages'] : 1 );
		$page++;
	} while ( $page <= $total_pages );
	return $items;
}

/** Stable comparison normalization for titles and addresses. */
function wsp_geodirectory_comparison_value( $value ) {
	$value = remove_accents( strtolower( trim( (string) $value ) ) );
	return preg_replace( '/[^a-z0-9]+/', '', $value );
}

/** Coordinate comparison kept deliberately narrow to avoid nearby-park false positives. */
function wsp_geodirectory_coordinates_match( $lat_a, $lon_a, $lat_b, $lon_b ) {
	if ( ! is_numeric( $lat_a ) || ! is_numeric( $lon_a ) || ! is_numeric( $lat_b ) || ! is_numeric( $lon_b ) ) return false;
	return abs( (float) $lat_a - (float) $lat_b ) <= 0.00001 && abs( (float) $lon_a - (float) $lon_b ) <= 0.00001;
}

/** Return the strongest existing duplicate plus every reason that matched it. */
function wsp_geodirectory_find_duplicate( $listing, $candidates ) {
	$matches = array();
	foreach ( $candidates as $candidate ) {
		$reasons = array();
		if (
			wsp_geodirectory_comparison_value( $listing['title'] ) &&
			wsp_geodirectory_comparison_value( $listing['title'] ) === wsp_geodirectory_comparison_value( $candidate['title'] ) &&
			wsp_geodirectory_comparison_value( $listing['city'] ) === wsp_geodirectory_comparison_value( $candidate['city'] ) &&
			wsp_geodirectory_comparison_value( $listing['region'] ) === wsp_geodirectory_comparison_value( $candidate['region'] )
		) $reasons[] = 'title_city_region';
		if (
			wsp_geodirectory_comparison_value( $listing['street'] ) &&
			wsp_geodirectory_comparison_value( $listing['street'] ) === wsp_geodirectory_comparison_value( $candidate['street'] ) &&
			wsp_geodirectory_comparison_value( $listing['city'] ) === wsp_geodirectory_comparison_value( $candidate['city'] ) &&
			wsp_geodirectory_comparison_value( $listing['region'] ) === wsp_geodirectory_comparison_value( $candidate['region'] )
		) $reasons[] = 'street_city_region';
		if ( wsp_geodirectory_coordinates_match( $listing['latitude'], $listing['longitude'], $candidate['latitude'], $candidate['longitude'] ) ) {
			$reasons[] = 'coordinates_within_0.00001_degrees';
		}
		if ( $reasons ) {
			$matches[] = array( 'candidate' => $candidate, 'reasons' => $reasons );
		}
	}
	if ( ! $matches ) return false;
	usort( $matches, function ( $a, $b ) {
		$count = count( $b['reasons'] ) - count( $a['reasons'] );
		return $count ? $count : ( $a['candidate']['id'] - $b['candidate']['id'] );
	} );
	return $matches[0];
}

/** Enforce the CPT's publish capability for publish transitions. */
function wsp_geodirectory_can_publish( $post_type ) {
	$object = get_post_type_object( $post_type );
	return $object && current_user_can( $object->cap->publish_posts );
}

/** Convert an internal WP_Error to a deterministic per-row result. */
function wsp_geodirectory_row_error( $index, $error ) {
	return array(
		'index'  => (int) $index,
		'action' => 'error',
		'error'  => array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
		),
	);
}

/** Search GeoDirectory listings, including editable non-public statuses. */
function wsp_execute_geodirectory_search_listings( $input ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'wsp_geodirectory_forbidden', 'You are not allowed to search editable GeoDirectory listings.' );
	}
	if ( ! is_array( $input ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_search', 'Search input must be an object.' );
	}
	$post_type = wsp_geodirectory_validate_post_type( isset( $input['post_type'] ) ? $input['post_type'] : 'gd_place' );
	if ( is_wp_error( $post_type ) ) return $post_type;
	if ( isset( $input['query'] ) && ! is_scalar( $input['query'] ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_search', 'query must be a string.' );
	}
	$query = isset( $input['query'] ) ? sanitize_text_field( wp_unslash( $input['query'] ) ) : '';
	// Retrieve through GeoDirectory REST, then apply the small administrative
	// result filter in PHP. This avoids database-specific full-text behavior
	// while preserving GeoDirectory as the read source.
	$items = wsp_geodirectory_rest_listings( $post_type );
	if ( is_wp_error( $items ) ) return $items;

	if ( isset( $input['status'] ) && ! is_scalar( $input['status'] ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_status', 'status must be a string.' );
	}
	$status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any';
	if ( ! in_array( $status, array( 'any', 'all', 'publish', 'draft', 'pending', 'private' ), true ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_status', 'status must be any, all, publish, draft, pending, or private.' );
	}
	$query_value = wsp_geodirectory_comparison_value( $query );
	$filters = array();
	foreach ( array( 'city', 'region', 'country' ) as $field ) {
		if ( isset( $input[ $field ] ) && ! is_scalar( $input[ $field ] ) ) {
			return new WP_Error( 'wsp_geodirectory_invalid_search', $field . ' must be a string.' );
		}
		if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) $filters[ $field ] = wsp_geodirectory_comparison_value( $input[ $field ] );
	}
	$items = array_values( array_filter( $items, function ( $item ) use ( $status, $filters, $query_value ) {
		if ( ! in_array( $status, array( 'any', 'all' ), true ) && $item['status'] !== $status ) return false;
		if ( $query_value ) {
			$haystack = wsp_geodirectory_comparison_value( $item['title'] . ' ' . $item['street'] . ' ' . $item['city'] . ' ' . $item['region'] );
			if ( false === strpos( $haystack, $query_value ) ) return false;
		}
		foreach ( $filters as $field => $value ) {
			if ( wsp_geodirectory_comparison_value( $item[ $field ] ) !== $value ) return false;
		}
		return true;
	} ) );
	$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
	return array( 'items' => array_slice( $items, 0, $per_page ), 'total' => count( $items ), 'per_page' => $per_page );
}

/** Import up to 50 listings with duplicate skipping and row-level results. */
function wsp_execute_geodirectory_import_listings( $input ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'wsp_geodirectory_forbidden', 'You are not allowed to import GeoDirectory listings.' );
	}
	if ( ! isset( $input['listings'] ) || ! is_array( $input['listings'] ) ) {
		return new WP_Error( 'wsp_geodirectory_invalid_batch', 'listings must be an array.' );
	}
	if ( count( $input['listings'] ) > 50 ) {
		return new WP_Error( 'wsp_geodirectory_batch_too_large', 'A GeoDirectory import is limited to 50 listings.' );
	}
	$post_type = wsp_geodirectory_validate_post_type( isset( $input['post_type'] ) ? $input['post_type'] : 'gd_place' );
	if ( is_wp_error( $post_type ) ) return $post_type;
	$dry_run = ! empty( $input['dry_run'] );
	$candidates = wsp_geodirectory_rest_listings( $post_type );
	if ( is_wp_error( $candidates ) ) return $candidates;
	$route = wsp_geodirectory_rest_route( $post_type );
	if ( is_wp_error( $route ) ) return $route;

	$summary = array( 'total' => count( $input['listings'] ), 'created' => 0, 'skipped_duplicates' => 0, 'would_create' => 0, 'errors' => 0 );
	$results = array();
	foreach ( array_values( $input['listings'] ) as $index => $listing ) {
		$fields = wsp_geodirectory_validate_listing_fields( $listing, true );
		if ( is_wp_error( $fields ) ) {
			$summary['errors']++;
			$results[] = wsp_geodirectory_row_error( $index, $fields );
			continue;
		}
		if ( 'publish' === $fields['status'] && ! wsp_geodirectory_can_publish( $post_type ) ) {
			$summary['errors']++;
			$results[] = wsp_geodirectory_row_error( $index, new WP_Error( 'wsp_geodirectory_cannot_publish', 'You are not allowed to publish this GeoDirectory post type.' ) );
			continue;
		}
		$duplicate = wsp_geodirectory_find_duplicate( $fields, $candidates );
		if ( $duplicate ) {
			$summary['skipped_duplicates']++;
			$result = array(
				'index'         => $index,
				'action'        => 'skipped_duplicate',
				'match_reasons' => $duplicate['reasons'],
			);
			if ( isset( $duplicate['candidate']['_batch_index'] ) ) {
				$result['duplicate_of_index'] = (int) $duplicate['candidate']['_batch_index'];
			} else {
				$result['id'] = $duplicate['candidate']['id'];
				$result['status'] = $duplicate['candidate']['status'];
				$result['url'] = $duplicate['candidate']['url'];
			}
			$results[] = $result;
			continue;
		}
		if ( $dry_run ) {
			$summary['would_create']++;
			$results[] = array( 'index' => $index, 'action' => 'would_create', 'title' => $fields['title'], 'status' => $fields['status'] );
			$virtual_candidate = $fields;
			$virtual_candidate['id'] = 0;
			$virtual_candidate['url'] = '';
			$virtual_candidate['_batch_index'] = $index;
			$candidates[] = $virtual_candidate;
			continue;
		}

		$request = new WP_REST_Request( 'POST', $route );
		wsp_geodirectory_set_rest_params( $request, $fields );
		$response = rest_do_request( $request );
		$error = wsp_geodirectory_rest_error( $response );
		if ( $error ) {
			$summary['errors']++;
			$results[] = wsp_geodirectory_row_error( $index, $error );
			continue;
		}
		$item = wsp_geodirectory_normalize_item( $response->get_data() );
		$candidates[] = $item;
		$summary['created']++;
		$results[] = array( 'index' => $index, 'action' => 'created', 'id' => $item['id'], 'status' => $item['status'], 'url' => $item['url'], 'listing' => $item );
	}
	return array( 'summary' => $summary, 'results' => $results );
}

/** Update one existing GeoDirectory listing through its item REST route. */
function wsp_execute_geodirectory_update_listing( $input ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'wsp_geodirectory_forbidden', 'You are not allowed to update GeoDirectory listings.' );
	}
	$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post ) return new WP_Error( 'wsp_geodirectory_invalid_listing', 'The listing does not exist.' );
	$post_type = wsp_geodirectory_validate_post_type( $post->post_type );
	if ( is_wp_error( $post_type ) ) return new WP_Error( 'wsp_geodirectory_invalid_listing', 'The ID is not a GeoDirectory listing.' );
	if ( isset( $input['post_type'] ) && sanitize_key( $input['post_type'] ) !== $post_type ) {
		return new WP_Error( 'wsp_geodirectory_invalid_listing', 'The ID does not belong to the requested GeoDirectory post type.' );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'wsp_geodirectory_cannot_edit_listing', 'You are not allowed to edit this listing.' );
	}
	if ( ! isset( $input['fields'] ) || ! is_array( $input['fields'] ) || ! $input['fields'] ) {
		return new WP_Error( 'wsp_geodirectory_invalid_fields', 'fields must contain at least one allowlisted listing field.' );
	}
	$fields = wsp_geodirectory_validate_listing_fields( $input['fields'], false );
	if ( is_wp_error( $fields ) ) return $fields;
	if ( isset( $fields['status'] ) && 'publish' === $fields['status'] && ! wsp_geodirectory_can_publish( $post_type ) ) {
		return new WP_Error( 'wsp_geodirectory_cannot_publish', 'You are not allowed to publish this GeoDirectory post type.' );
	}
	$route = wsp_geodirectory_rest_route( $post_type, $id );
	if ( is_wp_error( $route ) ) return $route;
	$request = new WP_REST_Request( 'POST', $route );
	wsp_geodirectory_set_rest_params( $request, $fields );
	$response = rest_do_request( $request );
	$error = wsp_geodirectory_rest_error( $response );
	if ( $error ) return $error;
	$item = wsp_geodirectory_normalize_item( $response->get_data() );
	return array(
		'success'        => true,
		'id'             => $item['id'],
		'title'          => $item['title'],
		'status'         => $item['status'],
		'url'            => $item['url'],
		'updated_fields' => array_keys( $fields ),
	);
}
