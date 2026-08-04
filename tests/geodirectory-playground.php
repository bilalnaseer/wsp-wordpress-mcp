<?php
/**
 * Focused GeoDirectory integration tests, executed inside WordPress Playground.
 *
 * This file intentionally uses a tiny assertion runner so the repository does
 * not need to vendor PHPUnit or GeoDirectory. The caller mounts both plugin
 * checkouts into the ephemeral Playground instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require '/wordpress/wp-load.php';
}

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'WordPress could not be bootstrapped.' );
}

$wsp_test_failures = 0;
$wsp_test_count    = 0;

function wsp_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wsp_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}
}

function wsp_test_run( $name, $callback ) {
	global $wsp_test_count, $wsp_test_failures;
	$wsp_test_count++;
	try {
		$callback();
		echo "PASS {$name}\n";
	} catch ( Throwable $error ) {
		$wsp_test_failures++;
		echo "FAIL {$name}: {$error->getMessage()}\n";
	}
}

wp_set_current_user( 1 );
rest_get_server();

wsp_test_run( 'GeoDirectory and WSP integration are loaded', function () {
	wsp_test_assert( function_exists( 'geodir_get_posttypes' ), 'GeoDirectory is not active.' );
	wsp_test_assert( function_exists( 'wsp_execute_geodirectory_import_listings' ), 'GeoDirectory WSP callbacks are not loaded.' );
	wsp_test_assert( wsp_geodirectory_is_active(), 'The active check did not detect GeoDirectory.' );
} );

wsp_test_run( 'registers exactly three default-off GeoDirectory tools with edit_posts gates', function () {
	$registry = wsp_mcp_ability_registry();
	$keys     = array_values(
		array_filter(
			array_keys( $registry ),
			function ( $key ) {
				return 0 === strpos( $key, 'wsp/geodirectory-' );
			}
		)
	);

	wsp_test_assert_same(
		array(
			'wsp/geodirectory-search-listings',
			'wsp/geodirectory-import-listings',
			'wsp/geodirectory-update-listing',
		),
		$keys,
		'Registry keys differ'
	);
	foreach ( $keys as $key ) {
		wsp_test_assert_same( 'GeoDirectory', $registry[ $key ]['group'], 'Wrong group for ' . $key );
		wsp_test_assert_same( false, $registry[ $key ]['default'], 'GeoDirectory tools must default off.' );
	}

	$reflection = new ReflectionClass( 'WSP_MCP_Server' );
	$property   = $reflection->getProperty( 'tools' );
	$property->setAccessible( true );
	$tools = $property->getValue();
	$names = array_values(
		array_filter(
			array_keys( $tools ),
			function ( $name ) {
				return 0 === strpos( $name, 'wsp_geodirectory_' );
			}
		)
	);

	wsp_test_assert_same(
		array(
			'wsp_geodirectory_search_listings',
			'wsp_geodirectory_import_listings',
			'wsp_geodirectory_update_listing',
		),
		$names,
		'Native tool names differ'
	);
	foreach ( $names as $name ) {
		wsp_test_assert_same( 'edit_posts', $tools[ $name ]['capability'], 'Wrong capability for ' . $name );
	}
} );

wsp_test_run( 'rejects unknown fields and validates/sanitizes the allowlist', function () {
	$unknown = wsp_geodirectory_validate_listing_fields(
		array(
			'title'  => 'Unsafe',
			'author' => 99,
		),
		false
	);
	wsp_test_assert( is_wp_error( $unknown ), 'Unknown fields must fail validation.' );
	wsp_test_assert_same( 'wsp_geodirectory_unknown_field', $unknown->get_error_code(), 'Wrong unknown-field error code.' );

	$clean = wsp_geodirectory_validate_listing_fields(
		array(
			'title'        => " <b>Lake\tPark</b> ",
			'content'      => '<p>Safe</p><script>alert(1)</script>',
			'post_tags'    => ' Dog Park ',
			'post_category'=> array( '4', 7 ),
			'street'       => ' 1 Main St ',
			'country'      => ' United States ',
			'region'       => ' Illinois ',
			'city'         => ' Chicago ',
			'zip'          => ' 60601 ',
			'latitude'     => '41.881',
			'longitude'    => '-87.623',
			'website'      => 'https://example.com/park?q=1',
			'business_hours' => " Daily 6am-11pm ",
			'has_benches'  => true,
			'off_leash'    => '0',
			'slug'         => ' Lake Park! ',
			'status'       => 'draft',
			'featured_media' => '12',
		),
		true
	);
	wsp_test_assert( ! is_wp_error( $clean ), is_wp_error( $clean ) ? $clean->get_error_message() : 'Validation failed.' );
	wsp_test_assert_same( 'Lake Park', $clean['title'], 'Title was not sanitized.' );
	wsp_test_assert( false === strpos( $clean['content'], '<script' ), 'Unsafe HTML survived content sanitization.' );
	wsp_test_assert_same( array( 4, 7 ), $clean['post_category'], 'Category IDs were not normalized.' );
	wsp_test_assert_same( 1, $clean['has_benches'], 'Boolean amenity was not normalized to 1.' );
	wsp_test_assert_same( 0, $clean['off_leash'], 'String zero was not normalized to 0.' );
	wsp_test_assert_same( 'lake-park', $clean['slug'], 'Slug was not sanitized.' );
	wsp_test_assert_same( 12, $clean['featured_media'], 'Featured media was not normalized.' );

	$bad_latitude = wsp_geodirectory_validate_listing_fields( array( 'latitude' => 91 ), false );
	wsp_test_assert( is_wp_error( $bad_latitude ), 'Out-of-range latitude must fail.' );
	$bad_amenity = wsp_geodirectory_validate_listing_fields( array( 'has_parking' => 2 ), false );
	wsp_test_assert( is_wp_error( $bad_amenity ), 'Amenities must be limited to 0 or 1.' );
	$bad_category = wsp_geodirectory_validate_listing_fields( array( 'post_category' => array( 4, 'bad' ) ), false );
	wsp_test_assert( is_wp_error( $bad_category ), 'Invalid category IDs must not be silently dropped.' );
	foreach ( array( 'website', 'slug', 'status' ) as $scalar_field ) {
		$bad_scalar = wsp_geodirectory_validate_listing_fields( array( $scalar_field => array( 'not', 'scalar' ) ), false );
		wsp_test_assert( is_wp_error( $bad_scalar ), $scalar_field . ' must reject non-scalar input.' );
	}
	$bad_media = wsp_geodirectory_validate_listing_fields( array( 'featured_media' => -1 ), false );
	wsp_test_assert( is_wp_error( $bad_media ), 'Negative featured media IDs must fail.' );
} );

wsp_test_run( 'validates GeoDirectory post types, required create fields, and the 50-row cap', function () {
	$post_type = wsp_geodirectory_validate_post_type( 'post' );
	wsp_test_assert( is_wp_error( $post_type ), 'A normal WordPress post type must be rejected.' );

	$missing = wsp_geodirectory_validate_listing_fields( array( 'title' => 'Missing address' ), true );
	wsp_test_assert( is_wp_error( $missing ), 'Missing required create fields must fail.' );
	wsp_test_assert_same( 'wsp_geodirectory_required_field', $missing->get_error_code(), 'Wrong required-field error code.' );

	$too_many = wsp_execute_geodirectory_import_listings(
		array(
			'listings' => array_fill( 0, 51, array() ),
			'dry_run'  => true,
		)
	);
	wsp_test_assert( is_wp_error( $too_many ), 'More than 50 records must be rejected.' );
	wsp_test_assert_same( 'wsp_geodirectory_batch_too_large', $too_many->get_error_code(), 'Wrong batch-cap error code.' );
} );

wsp_test_run( 'continues after row errors and dry-run writes nothing', function () {
	$before = wp_count_posts( 'gd_place' );
	$result = wsp_execute_geodirectory_import_listings(
		array(
			'dry_run' => true,
			'listings' => array(
				array( 'title' => 'Invalid row', 'author' => 1 ),
				array(
					'title'     => 'WSP Dry Run Park',
					'street'    => '100 Dry Run Ave',
					'country'   => 'United States',
					'region'    => 'Illinois',
					'city'      => 'Chicago',
					'latitude'  => 41.90001,
					'longitude' => -87.65001,
				),
			),
		)
	);
	wsp_test_assert( ! is_wp_error( $result ), 'Import unexpectedly returned a top-level error.' );
	wsp_test_assert_same( 2, $result['summary']['total'], 'Wrong dry-run total.' );
	wsp_test_assert_same( 1, $result['summary']['errors'], 'Wrong dry-run error count.' );
	wsp_test_assert_same( 1, $result['summary']['would_create'], 'Wrong dry-run would-create count.' );
	wsp_test_assert_same( 'error', $result['results'][0]['action'], 'Invalid first row did not return an error result.' );
	wsp_test_assert_same( 'would_create', $result['results'][1]['action'], 'Valid second row was not evaluated.' );
	$after = wp_count_posts( 'gd_place' );
	wsp_test_assert_same( $before->draft, $after->draft, 'Dry-run created a draft.' );
} );

wsp_test_run( 'dry-run detects duplicates within the incoming batch', function () {
	$listing = array(
		'title'     => 'WSP Repeated Dry Run Park',
		'street'    => '101 Repeat Avenue',
		'country'   => 'United States',
		'region'    => 'Illinois',
		'city'      => 'Chicago',
		'latitude'  => 41.90002,
		'longitude' => -87.65002,
	);
	$result = wsp_execute_geodirectory_import_listings(
		array(
			'dry_run' => true,
			'listings' => array( $listing, $listing ),
		)
	);
	wsp_test_assert( ! is_wp_error( $result ), 'Dry-run duplicate check returned a top-level error.' );
	wsp_test_assert_same( 1, $result['summary']['would_create'], 'The first new row should be would_create.' );
	wsp_test_assert_same( 1, $result['summary']['skipped_duplicates'], 'The repeated row should be skipped during dry-run.' );
	wsp_test_assert_same( 'would_create', $result['results'][0]['action'], 'Wrong first-row dry-run action.' );
	wsp_test_assert_same( 'skipped_duplicate', $result['results'][1]['action'], 'Wrong repeated-row dry-run action.' );
	wsp_test_assert_same( 0, $result['results'][1]['duplicate_of_index'], 'Dry-run duplicate did not identify its earlier batch row.' );
} );

wsp_test_run( 'uses a conservative coordinate duplicate threshold', function () {
	wsp_test_assert( wsp_geodirectory_coordinates_match( 41.000000, -87.000000, 41.000009, -87.000009 ), 'Coordinates within 0.00001 degrees should match.' );
	wsp_test_assert( ! wsp_geodirectory_coordinates_match( 41.000000, -87.000000, 41.000020, -87.000000 ), 'Coordinates outside 0.00001 degrees should not match.' );
} );

$wsp_created_listing_id = 0;
wsp_test_run( 'creates through GeoDirectory REST and skips duplicates with reasons', function () use ( &$wsp_created_listing_id ) {
	$listing = array(
		'title'     => 'WSP Playground Dog Park',
		'street'    => '200 Harness Street',
		'country'   => 'United States',
		'region'    => 'Illinois',
		'city'      => 'Chicago',
		'latitude'  => 41.910000,
		'longitude' => -87.660000,
	);
	$created = wsp_execute_geodirectory_import_listings( array( 'listings' => array( $listing ) ) );
	wsp_test_assert( ! is_wp_error( $created ), is_wp_error( $created ) ? $created->get_error_message() : 'Create failed.' );
	wsp_test_assert_same( 1, $created['summary']['created'], 'Listing was not created: ' . wp_json_encode( $created['results'] ) );
	wsp_test_assert_same( 'created', $created['results'][0]['action'], 'Wrong create action.' );
	$wsp_created_listing_id = $created['results'][0]['id'];
	wsp_test_assert( $wsp_created_listing_id > 0, 'Create response omitted the listing ID.' );

	$duplicate = $listing;
	$duplicate['latitude']  = 41.910009;
	$duplicate['longitude'] = -87.660009;
	$skipped = wsp_execute_geodirectory_import_listings( array( 'listings' => array( $duplicate ) ) );
	wsp_test_assert_same( 1, $skipped['summary']['skipped_duplicates'], 'Duplicate was not skipped.' );
	wsp_test_assert_same( 'skipped_duplicate', $skipped['results'][0]['action'], 'Wrong duplicate action.' );
	wsp_test_assert_same( $wsp_created_listing_id, $skipped['results'][0]['id'], 'Duplicate ID was not returned.' );
	$reasons = $skipped['results'][0]['match_reasons'];
	wsp_test_assert( in_array( 'title_city_region', $reasons, true ), 'Title/city/region reason missing.' );
	wsp_test_assert( in_array( 'street_city_region', $reasons, true ), 'Street/city/region reason missing.' );
	wsp_test_assert( in_array( 'coordinates_within_0.00001_degrees', $reasons, true ), 'Coordinate reason missing.' );
} );

wsp_test_run( 'search returns normalized listings and respects filters', function () use ( &$wsp_created_listing_id ) {
	$invalid_status = wsp_execute_geodirectory_search_listings( array( 'status' => 'trash' ) );
	wsp_test_assert( is_wp_error( $invalid_status ), 'Unsupported search statuses must fail clearly.' );
	wsp_test_assert_same( 'wsp_geodirectory_invalid_status', $invalid_status->get_error_code(), 'Wrong invalid-search-status error.' );

	$result = wsp_execute_geodirectory_search_listings(
		array(
			'query'    => 'WSP Playground',
			'city'     => 'Chicago',
			'region'   => 'Illinois',
			'status'   => 'draft',
			'per_page' => 200,
		)
	);
	wsp_test_assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Search failed.' );
	wsp_test_assert_same( 100, $result['per_page'], 'per_page was not bounded at 100.' );
	wsp_test_assert( ! empty( $result['items'] ), 'Search returned no items.' );
	$item = $result['items'][0];
	wsp_test_assert_same( $wsp_created_listing_id, $item['id'], 'Search returned the wrong listing.' );
	foreach ( array( 'id', 'title', 'status', 'url', 'street', 'city', 'region', 'country', 'latitude', 'longitude' ) as $key ) {
		wsp_test_assert( array_key_exists( $key, $item ), 'Normalized search item omitted ' . $key . '.' );
	}
} );

wsp_test_run( 'enforces edit, object, and publish capabilities on updates', function () use ( &$wsp_created_listing_id ) {
	$regular_post = wp_insert_post( array( 'post_title' => 'Not a listing', 'post_type' => 'post', 'post_status' => 'draft' ) );
	$wrong_type = wsp_execute_geodirectory_update_listing(
		array(
			'id'     => $regular_post,
			'fields' => array( 'title' => 'Still not a listing' ),
		)
	);
	wsp_test_assert( is_wp_error( $wrong_type ), 'A non-GeoDirectory ID must be rejected.' );
	wsp_test_assert_same( 'wsp_geodirectory_invalid_listing', $wrong_type->get_error_code(), 'Wrong post-type mismatch error.' );

	$empty = wsp_execute_geodirectory_update_listing( array( 'id' => $wsp_created_listing_id, 'fields' => array() ) );
	wsp_test_assert( is_wp_error( $empty ), 'Empty update fields must be rejected.' );

	$contributor_id = wp_insert_user(
		array(
			'user_login' => 'wsp_geo_contributor',
			'user_pass'  => 'test-only-password',
			'user_email' => 'wsp-geo-contributor@example.test',
			'role'       => 'contributor',
		)
	);
	wp_set_current_user( $contributor_id );
	$object_denied = wsp_execute_geodirectory_update_listing(
		array(
			'id'     => $wsp_created_listing_id,
			'fields' => array( 'title' => 'Unauthorized edit' ),
		)
	);
	wsp_test_assert( is_wp_error( $object_denied ), 'Per-object edit permission was not enforced.' );
	wsp_test_assert_same( 'wsp_geodirectory_cannot_edit_listing', $object_denied->get_error_code(), 'Wrong object permission error.' );

	$publish_denied = wsp_execute_geodirectory_import_listings(
		array(
			'dry_run' => true,
			'listings' => array(
				array(
					'title'     => 'Contributor Publish Attempt',
					'street'    => '300 Publish Street',
					'country'   => 'United States',
					'region'    => 'Illinois',
					'city'      => 'Chicago',
					'latitude'  => 41.92,
					'longitude' => -87.67,
					'status'    => 'publish',
				),
			),
		)
	);
	wsp_test_assert_same( 'wsp_geodirectory_cannot_publish', $publish_denied['results'][0]['error']['code'], 'Publish capability was not enforced.' );

	wp_set_current_user( 1 );
	$updated = wsp_execute_geodirectory_update_listing(
		array(
			'id'     => $wsp_created_listing_id,
			'fields' => array(
				'title'  => 'WSP Playground Dog Park QA Complete',
				'status' => 'publish',
			),
		)
	);
	wsp_test_assert( ! is_wp_error( $updated ), is_wp_error( $updated ) ? $updated->get_error_message() : 'Admin update failed.' );
	wsp_test_assert_same( 'publish', $updated['status'], 'Draft was not published.' );
	wsp_test_assert_same( array( 'title', 'status' ), $updated['updated_fields'], 'Updated field keys are not deterministic.' );
} );

wsp_test_run( 'search and import callbacks require edit_posts', function () {
	wp_set_current_user( 0 );
	$search = wsp_execute_geodirectory_search_listings( array() );
	wsp_test_assert( is_wp_error( $search ), 'Search did not enforce edit_posts.' );
	$import = wsp_execute_geodirectory_import_listings( array( 'dry_run' => true, 'listings' => array() ) );
	wsp_test_assert( is_wp_error( $import ), 'Import did not enforce edit_posts.' );
	wp_set_current_user( 1 );
} );

echo "\n{$wsp_test_count} tests, {$wsp_test_failures} failures.\n";
exit( $wsp_test_failures > 0 ? 1 : 0 );
