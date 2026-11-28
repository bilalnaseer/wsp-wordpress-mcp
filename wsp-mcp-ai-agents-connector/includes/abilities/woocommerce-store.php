<?php
/**
 * WooCommerce store configuration (v2.9.5): settings, tax, shipping, payment gateways.
 *
 * Everything goes through WooCommerce's own wc/v3 REST controllers (wsp_woo_rest(), defined in
 * woocommerce-catalog.php) so validation, sanitization and per-option capability checks are
 * WooCommerce's, not ours. Envelope: { success, data, error }.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const WSP_WOO_SECRET_MASK = '********';

/** True when an option/setting id or type looks like a credential that must never be echoed. */
function wsp_woo_is_secret_field( $id, $type = '' ) {
	if ( 'password' === $type ) return true;
	return (bool) preg_match( '/(secret|token|passw|private|api_?key|_key$|signature|webhook|salt)/i', (string) $id );
}

/** Mask the `value` of a settings row (REST setting shape) when it's a secret and non-empty. */
function wsp_woo_mask_setting_row( $row ) {
	if ( is_array( $row ) && isset( $row['id'] ) && wsp_woo_is_secret_field( $row['id'], isset( $row['type'] ) ? $row['type'] : '' ) ) {
		foreach ( array( 'value', 'default' ) as $k ) {
			if ( isset( $row[ $k ] ) && '' !== $row[ $k ] ) $row[ $k ] = WSP_WOO_SECRET_MASK;
		}
	}
	return $row;
}

// ---------------------------------------------
// SETTINGS
// ---------------------------------------------

function wsp_woo_settings_groups() {
	return array( 'general', 'products', 'tax', 'shipping', 'checkout', 'account', 'email' );
}

function wsp_woo_settings_group_arg( $input ) {
	$group = isset( $input['group'] ) ? sanitize_key( $input['group'] ) : '';
	return in_array( $group, wsp_woo_settings_groups(), true ) ? $group : null;
}

const WSP_WOO_SETTINGS_MAX_CHARS = 50000;

function wsp_execute_woo_get_settings( $input ) {
	$e = wsp_woo_guard( 'manage_options' );
	if ( $e ) {
		return $e;
	}
	$e = wsp_woo_guard( 'manage_woocommerce' );
	if ( $e ) {
		return $e;
	}
	$group = wsp_woo_settings_group_arg( $input );
	if ( ! $group ) return wsp_woo_fail( 'group is required. One of: ' . implode( ', ', wsp_woo_settings_groups() ) . '.' );

	$wanted = array();
	foreach ( array( 'setting_id', 'setting_ids' ) as $k ) {
		if ( isset( $input[ $k ] ) ) {
			foreach ( (array) $input[ $k ] as $sid ) {
				$sid = sanitize_key( $sid );
				if ( '' !== $sid ) $wanted[] = $sid;
			}
		}
	}
	$wanted          = array_values( array_unique( $wanted ) );
	$include_options = wsp_woo_flag( $input, 'include_options' );

	$rows = wsp_woo_rest( 'GET', 'settings/' . $group );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );

	$out   = array();
	$found = array();
	foreach ( $rows as $r ) {
		if ( ! isset( $r['type'] ) || in_array( $r['type'], array( 'title', 'sectionend', 'slider' ), true ) ) continue;
		if ( $wanted && ! in_array( $r['id'], $wanted, true ) ) continue;
		$found[] = $r['id'];
		$r   = wsp_woo_mask_setting_row( $r );
		$row = wsp_woo_pick( $r, array( 'id', 'label', 'description', 'type', 'default', 'value' ) );
		if ( isset( $row['description'] ) ) $row['description'] = wp_strip_all_tags( $row['description'] );
		if ( ! empty( $r['options'] ) && is_array( $r['options'] ) ) {
			$row['options_count'] = count( $r['options'] );
			if ( $include_options ) $row['options'] = $r['options'];
		}
		$out[] = $row;
	}
	if ( $wanted && ! $out ) {
		return wsp_woo_fail( "None of the requested setting ids exist in group '{$group}': " . implode( ', ', $wanted ) . '.' );
	}

	$data = array( 'group' => $group, 'settings' => $out, 'total' => count( $out ) );
	if ( $wanted && array_diff( $wanted, $found ) ) $data['not_found'] = array_values( array_diff( $wanted, $found ) );

	// Hard safety limit: drop options (every value is kept) if the response would be too large.
	if ( $include_options && strlen( wp_json_encode( $data ) ) > WSP_WOO_SETTINGS_MAX_CHARS ) {
		foreach ( $data['settings'] as &$row ) unset( $row['options'] );
		unset( $row );
		$data['truncated_options'] = true;
		$data['note'] = 'Options were dropped because the response exceeded ' . WSP_WOO_SETTINGS_MAX_CHARS . ' characters. Request fewer settings with setting_id/setting_ids plus include_options=true.';
	}
	return wsp_woo_ok( $data );
}

function wsp_execute_woo_update_settings( $input ) {
	$e = wsp_woo_guard( 'manage_options' );
	if ( $e ) {
		return $e;
	}
	$e = wsp_woo_guard( 'manage_woocommerce' );
	if ( $e ) {
		return $e;
	}
	$group = wsp_woo_settings_group_arg( $input );
	if ( ! $group ) return wsp_woo_fail( 'group is required. One of: ' . implode( ', ', wsp_woo_settings_groups() ) . '.' );
	if ( empty( $input['settings'] ) || ! is_array( $input['settings'] ) ) return wsp_woo_fail( 'settings must be a non-empty object of option id => value.' );

	$known = wsp_woo_rest( 'GET', 'settings/' . $group );
	if ( is_wp_error( $known ) ) return wsp_woo_fail( $known->get_error_message() );
	$ids = array();
	foreach ( $known as $r ) {
		if ( isset( $r['id'], $r['type'] ) && ! in_array( $r['type'], array( 'title', 'sectionend', 'slider' ), true ) ) $ids[ $r['id'] ] = $r;
	}

	$updated = array();
	$errors  = array();
	foreach ( $input['settings'] as $key => $value ) {
		$key = sanitize_key( $key );
		if ( ! isset( $ids[ $key ] ) ) {
			$errors[ $key ] = "Unknown option for group '{$group}'.";
			continue;
		}
		if ( is_string( $value ) ) {
			// A masked secret echoed back from a get call must not overwrite the real value.
			if ( WSP_WOO_SECRET_MASK === $value ) continue;
			$value = sanitize_textarea_field( $value );
		} elseif ( is_array( $value ) ) {
			$value = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? 'yes' : 'no';
		} else {
			$value = (string) $value;
		}
		$res = wsp_woo_rest( 'PUT', "settings/{$group}/{$key}", array( 'value' => $value ) );
		if ( is_wp_error( $res ) ) {
			$errors[ $key ] = $res->get_error_message();
			continue;
		}
		$res = wsp_woo_mask_setting_row( $res );
		$updated[ $key ] = isset( $res['value'] ) ? $res['value'] : $value;
	}

	if ( empty( $updated ) && ! empty( $errors ) ) {
		return wsp_woo_fail( 'No settings were updated: ' . wp_json_encode( $errors ) );
	}
	$data = array( 'group' => $group, 'updated' => $updated );
	if ( $errors ) $data['errors'] = $errors;
	return wsp_woo_ok( $data );
}

// ---------------------------------------------
// TAX
// ---------------------------------------------

function wsp_woo_tax_rate_row( $r ) {
	return wsp_woo_pick( $r, array( 'id', 'country', 'state', 'postcode', 'city', 'rate', 'name', 'priority', 'compound', 'shipping', 'order', 'class' ) );
}

function wsp_execute_woo_get_tax_classes( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	$classes = wsp_woo_rest( 'GET', 'taxes/classes' );
	if ( is_wp_error( $classes ) ) return wsp_woo_fail( $classes->get_error_message() );
	$clean = array();
	foreach ( (array) $classes as $c ) $clean[] = wsp_woo_pick( $c, array( 'slug', 'name' ) );
	$data = array( 'classes' => $clean, 'taxes_enabled' => wc_tax_enabled() );
	if ( wsp_woo_flag( $input, 'include_rates' ) ) {
		$rates = wsp_woo_rest( 'GET', 'taxes', array( 'per_page' => 100 ) );
		if ( is_wp_error( $rates ) ) return wsp_woo_fail( $rates->get_error_message() );
		$data['rates'] = array_map( 'wsp_woo_tax_rate_row', $rates );
	}
	return wsp_woo_ok( $data );
}

function wsp_woo_tax_rate_params( $input ) {
	$p = array();
	if ( isset( $input['country'] ) )  $p['country']  = strtoupper( sanitize_text_field( wp_unslash( $input['country'] ) ) );
	if ( isset( $input['state'] ) )    $p['state']    = strtoupper( sanitize_text_field( wp_unslash( $input['state'] ) ) );
	if ( isset( $input['postcode'] ) ) $p['postcode'] = sanitize_text_field( wp_unslash( $input['postcode'] ) );
	if ( isset( $input['city'] ) )     $p['city']     = sanitize_text_field( wp_unslash( $input['city'] ) );
	if ( isset( $input['name'] ) )     $p['name']     = sanitize_text_field( wp_unslash( $input['name'] ) );
	if ( isset( $input['priority'] ) ) $p['priority'] = max( 1, intval( $input['priority'] ) );
	if ( isset( $input['compound'] ) ) $p['compound'] = filter_var( $input['compound'], FILTER_VALIDATE_BOOLEAN );
	if ( isset( $input['shipping'] ) ) $p['shipping'] = filter_var( $input['shipping'], FILTER_VALIDATE_BOOLEAN );
	if ( isset( $input['class'] ) )    $p['class']    = sanitize_title( wp_unslash( $input['class'] ) );
	if ( isset( $input['rate'] ) ) {
		if ( ! is_numeric( $input['rate'] ) || $input['rate'] < 0 || $input['rate'] > 100 ) return 'rate must be a number between 0 and 100 (percent).';
		$p['rate'] = (string) $input['rate'];
	}
	if ( isset( $p['country'] ) && '' !== $p['country'] && ! preg_match( '/^[A-Z]{2}$/', $p['country'] ) ) return 'country must be a 2-letter ISO code (e.g. US).';
	return $p;
}

function wsp_execute_woo_create_tax_rate( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['rate'] ) ) return wsp_woo_fail( 'rate is required.' );
	$p = wsp_woo_tax_rate_params( $input );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	$res = wsp_woo_rest( 'POST', 'taxes', $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_tax_rate_row( $res ) );
}

function wsp_execute_woo_update_tax_rate( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id ) return wsp_woo_fail( 'id is required.' );
	$p = wsp_woo_tax_rate_params( $input );
	if ( is_string( $p ) ) return wsp_woo_fail( $p );
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	$res = wsp_woo_rest( 'PUT', 'taxes/' . $id, $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_tax_rate_row( $res ) );
}

function wsp_execute_woo_delete_tax_rate( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	$id = isset( $input['id'] ) ? intval( $input['id'] ) : 0;
	if ( ! $id ) return wsp_woo_fail( 'id is required.' );
	if ( ! wsp_woo_flag( $input, 'force' ) ) return wsp_woo_fail( 'Tax rates cannot be trashed. Pass force=true to delete it permanently.' );
	$res = wsp_woo_rest( 'DELETE', 'taxes/' . $id, array( 'force' => true ) );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'id' => $id, 'name' => isset( $res['name'] ) ? $res['name'] : '', 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

// ---------------------------------------------
// SHIPPING
// ---------------------------------------------

function wsp_woo_shipping_method_row( $m ) {
	$row = wsp_woo_pick( $m, array( 'id', 'instance_id', 'title', 'order', 'enabled', 'method_id', 'method_title' ) );
	if ( isset( $m['settings'] ) && is_array( $m['settings'] ) ) {
		$row['settings'] = array();
		foreach ( $m['settings'] as $k => $s ) {
			$row['settings'][ $k ] = isset( $s['value'] ) ? $s['value'] : $s;
		}
	}
	return $row;
}

/** Turn ["US", "US:CA", "postcode:90210", "continent:EU"] (or {code,type} objects) into REST location objects. */
function wsp_woo_parse_zone_locations( $locations ) {
	$out = array();
	foreach ( (array) $locations as $loc ) {
		if ( is_array( $loc ) && isset( $loc['code'] ) ) {
			$code = sanitize_text_field( $loc['code'] );
			$type = isset( $loc['type'] ) ? sanitize_key( $loc['type'] ) : 'country';
		} else {
			$code = sanitize_text_field( (string) $loc );
			$type = 'country';
			if ( 0 === strpos( $code, 'postcode:' ) )       { $type = 'postcode';  $code = substr( $code, 9 ); }
			elseif ( 0 === strpos( $code, 'continent:' ) )  { $type = 'continent'; $code = substr( $code, 10 ); }
			elseif ( false !== strpos( $code, ':' ) )       { $type = 'state'; }
		}
		if ( '' === $code ) continue;
		if ( ! in_array( $type, array( 'country', 'state', 'postcode', 'continent' ), true ) ) return 'Invalid location type: ' . $type;
		$out[] = array( 'code' => 'postcode' === $type ? $code : strtoupper( $code ), 'type' => $type );
	}
	return $out;
}

/**
 * Replace a zone's locations via WC_Shipping_Zone and save. (The REST locations endpoint reads the
 * raw JSON body as the list, so wrapping it in {"locations":[...]} silently saved nothing.)
 * Returns true or an error string.
 */
function wsp_woo_zone_set_locations( $zone_id, $locations ) {
	$zone = new WC_Shipping_Zone( intval( $zone_id ) );
	if ( ! $zone->get_id() ) return 'Shipping zone not found.';
	$zone->clear_locations( array( 'state', 'country', 'continent', 'postcode' ) );
	foreach ( $locations as $loc ) {
		$zone->add_location( wc_clean( $loc['code'] ), $loc['type'] );
	}
	$zone->save();
	return true;
}

/** Re-read a zone's saved locations from the database as [{code,type}]. */
function wsp_woo_zone_read_locations( $zone_id ) {
	$zone = new WC_Shipping_Zone( intval( $zone_id ) );
	$out  = array();
	foreach ( $zone->get_zone_locations() as $l ) {
		$out[] = array( 'code' => $l->code, 'type' => $l->type );
	}
	return $out;
}

function wsp_execute_woo_get_shipping_zones( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	$zones = wsp_woo_rest( 'GET', 'shipping/zones' );
	if ( is_wp_error( $zones ) ) return wsp_woo_fail( $zones->get_error_message() );
	$out = array();
	foreach ( $zones as $z ) {
		$meth = wsp_woo_rest( 'GET', 'shipping/zones/' . intval( $z['id'] ) . '/methods' );
		$out[] = array(
			'id'        => (int) $z['id'],
			'name'      => $z['name'],
			'order'     => (int) $z['order'],
			'locations' => wsp_woo_zone_read_locations( $z['id'] ),
			'methods'   => is_wp_error( $meth ) ? array() : array_map( 'wsp_woo_shipping_method_row', $meth ),
		);
	}
	return wsp_woo_ok( array( 'zones' => $out, 'total' => count( $out ) ) );
}

function wsp_execute_woo_create_shipping_zone( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( empty( $input['name'] ) ) return wsp_woo_fail( 'name is required.' );
	$locations = array();
	if ( ! empty( $input['locations'] ) ) {
		$locations = wsp_woo_parse_zone_locations( $input['locations'] );
		if ( is_string( $locations ) ) return wsp_woo_fail( $locations );
	}
	$zone = wsp_woo_rest( 'POST', 'shipping/zones', array( 'name' => sanitize_text_field( wp_unslash( $input['name'] ) ) ) );
	if ( is_wp_error( $zone ) ) return wsp_woo_fail( $zone->get_error_message() );
	$data = array( 'id' => (int) $zone['id'], 'name' => $zone['name'] );
	if ( $locations ) {
		$set = wsp_woo_zone_set_locations( $zone['id'], $locations );
		if ( true !== $set ) $data['location_error'] = $set;
	}
	$data['locations'] = wsp_woo_zone_read_locations( $zone['id'] );
	return wsp_woo_ok( $data );
}

function wsp_execute_woo_update_shipping_zone( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['id'] ) ) return wsp_woo_fail( 'id is required.' );
	$id = intval( $input['id'] );
	if ( 0 === $id ) return wsp_woo_fail( 'Zone 0 ("Locations not covered by your other zones") cannot be edited. Manage its methods with the shipping method tools instead.' );
	$zone = wsp_woo_rest( 'GET', 'shipping/zones/' . $id );
	if ( is_wp_error( $zone ) ) return wsp_woo_fail( 'Shipping zone not found.' );

	$p = array();
	if ( isset( $input['name'] ) ) {
		$p['name'] = sanitize_text_field( wp_unslash( $input['name'] ) );
		if ( '' === $p['name'] ) return wsp_woo_fail( 'name cannot be empty.' );
	}
	if ( isset( $input['order'] ) ) $p['order'] = intval( $input['order'] );
	$locations = null;
	if ( isset( $input['locations'] ) ) {
		if ( ! is_array( $input['locations'] ) ) return wsp_woo_fail( 'locations must be an array of {code, type}.' );
		$locations = wsp_woo_parse_zone_locations( $input['locations'] );
		if ( is_string( $locations ) ) return wsp_woo_fail( $locations );
	}
	if ( empty( $p ) && null === $locations ) return wsp_woo_fail( 'No fields to update provided.' );

	if ( $p ) {
		$zone = wsp_woo_rest( 'PUT', 'shipping/zones/' . $id, $p );
		if ( is_wp_error( $zone ) ) return wsp_woo_fail( $zone->get_error_message() );
	}
	// Provided locations REPLACE the existing list ([] clears it).
	if ( null !== $locations ) {
		$set = wsp_woo_zone_set_locations( $id, $locations );
		if ( true !== $set ) return wsp_woo_fail( $set );
	}
	return wsp_woo_ok( array(
		'id'        => $id,
		'name'      => $zone['name'],
		'order'     => (int) $zone['order'],
		'locations' => wsp_woo_zone_read_locations( $id ),
	) );
}

function wsp_execute_woo_delete_shipping_zone( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['id'] ) ) return wsp_woo_fail( 'id is required.' );
	$id = intval( $input['id'] );
	if ( 0 === $id ) return wsp_woo_fail( 'Zone 0 ("Locations not covered by your other zones") cannot be deleted.' );
	$zone = wsp_woo_rest( 'GET', 'shipping/zones/' . $id );
	if ( is_wp_error( $zone ) ) return wsp_woo_fail( 'Shipping zone not found.' );
	$methods = wsp_woo_rest( 'GET', "shipping/zones/{$id}/methods" );
	$count   = is_wp_error( $methods ) ? 0 : count( $methods );
	$res     = wsp_woo_rest( 'DELETE', 'shipping/zones/' . $id, array( 'force' => true ) );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'id' => $id, 'name' => $zone['name'], 'removed_methods' => $count, 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

function wsp_execute_woo_get_shipping_methods( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['zone_id'] ) ) return wsp_woo_fail( 'zone_id is required (0 = "Locations not covered by your other zones").' );
	$zid = intval( $input['zone_id'] );
	$res = wsp_woo_rest( 'GET', "shipping/zones/{$zid}/methods" );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'zone_id' => $zid, 'methods' => array_map( 'wsp_woo_shipping_method_row', $res ) ) );
}

function wsp_woo_method_settings( $input ) {
	if ( ! isset( $input['settings'] ) ) return array();
	if ( ! is_array( $input['settings'] ) ) return 'settings must be an object of key => value.';
	$out = array();
	foreach ( $input['settings'] as $k => $v ) {
		$out[ sanitize_key( $k ) ] = is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
	}
	return $out;
}

function wsp_execute_woo_add_shipping_method( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['zone_id'] ) || empty( $input['method_id'] ) ) return wsp_woo_fail( 'zone_id and method_id are required.' );
	$zid    = intval( $input['zone_id'] );
	$method = sanitize_key( $input['method_id'] );
	$avail  = array_keys( WC()->shipping()->get_shipping_methods() );
	if ( ! in_array( $method, $avail, true ) ) return wsp_woo_fail( 'Unknown method_id. Available: ' . implode( ', ', $avail ) . '.' );
	$settings = wsp_woo_method_settings( $input );
	if ( is_string( $settings ) ) return wsp_woo_fail( $settings );

	$created = wsp_woo_rest( 'POST', "shipping/zones/{$zid}/methods", array( 'method_id' => $method ) );
	if ( is_wp_error( $created ) ) return wsp_woo_fail( $created->get_error_message() );
	$iid = (int) $created['id'];
	if ( $settings || isset( $input['enabled'] ) ) {
		$p = array();
		if ( $settings ) $p['settings'] = $settings;
		if ( isset( $input['enabled'] ) ) $p['enabled'] = filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN );
		$updated = wsp_woo_rest( 'PUT', "shipping/zones/{$zid}/methods/{$iid}", $p );
		if ( is_wp_error( $updated ) ) {
			return wsp_woo_fail( "Method was added (instance_id {$iid}) but settings failed: " . $updated->get_error_message() );
		}
		$created = $updated;
	}
	return wsp_woo_ok( array( 'zone_id' => $zid, 'method' => wsp_woo_shipping_method_row( $created ) ) );
}

function wsp_execute_woo_update_shipping_method( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['zone_id'] ) || empty( $input['instance_id'] ) ) return wsp_woo_fail( 'zone_id and instance_id are required.' );
	$zid = intval( $input['zone_id'] );
	$iid = intval( $input['instance_id'] );
	$p   = array();
	$settings = wsp_woo_method_settings( $input );
	if ( is_string( $settings ) ) return wsp_woo_fail( $settings );
	if ( $settings ) $p['settings'] = $settings;
	if ( isset( $input['enabled'] ) ) $p['enabled'] = filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN );
	if ( isset( $input['order'] ) )   $p['order']   = intval( $input['order'] );
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	$res = wsp_woo_rest( 'PUT', "shipping/zones/{$zid}/methods/{$iid}", $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'zone_id' => $zid, 'method' => wsp_woo_shipping_method_row( $res ) ) );
}

function wsp_execute_woo_delete_shipping_method( $input ) {
	$e = wsp_woo_guard();
	if ( $e ) {
		return $e;
	}
	if ( ! isset( $input['zone_id'] ) || empty( $input['instance_id'] ) ) return wsp_woo_fail( 'zone_id and instance_id are required.' );
	if ( ! wsp_woo_flag( $input, 'force' ) ) return wsp_woo_fail( 'Shipping methods cannot be trashed. Pass force=true to delete it permanently.' );
	$zid = intval( $input['zone_id'] );
	$iid = intval( $input['instance_id'] );
	$res = wsp_woo_rest( 'DELETE', "shipping/zones/{$zid}/methods/{$iid}", array( 'force' => true ) );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( array( 'id' => $iid, 'name' => isset( $res['title'] ) ? $res['title'] : '', 'zone_id' => $zid, 'trashed' => false, 'permanent' => true, 'result' => 'permanently_deleted' ) );
}

// ---------------------------------------------
// PAYMENT GATEWAYS (secrets are always masked)
// ---------------------------------------------

function wsp_woo_gateway_row( $g ) {
	$row = wsp_woo_pick( $g, array( 'id', 'title', 'description', 'order', 'enabled', 'method_title', 'method_description' ) );
	$row['settings'] = array();
	if ( isset( $g['settings'] ) && is_array( $g['settings'] ) ) {
		foreach ( $g['settings'] as $k => $s ) {
			$s = wsp_woo_mask_setting_row( is_array( $s ) ? $s + array( 'id' => $k ) : array( 'id' => $k, 'value' => $s ) );
			$row['settings'][ $k ] = isset( $s['value'] ) ? $s['value'] : '';
		}
	}
	return $row;
}

function wsp_execute_woo_get_payment_gateways( $input ) {
	$e = wsp_woo_guard( 'manage_options' );
	if ( $e ) {
		return $e;
	}
	$e = wsp_woo_guard( 'manage_woocommerce' );
	if ( $e ) {
		return $e;
	}
	$rows = wsp_woo_rest( 'GET', 'payment_gateways' );
	if ( is_wp_error( $rows ) ) return wsp_woo_fail( $rows->get_error_message() );
	return wsp_woo_ok( array( 'gateways' => array_map( 'wsp_woo_gateway_row', $rows ), 'total' => count( $rows ) ) );
}

function wsp_execute_woo_update_payment_gateway( $input ) {
	$e = wsp_woo_guard( 'manage_options' );
	if ( $e ) {
		return $e;
	}
	$e = wsp_woo_guard( 'manage_woocommerce' );
	if ( $e ) {
		return $e;
	}
	$id = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
	if ( '' === $id ) return wsp_woo_fail( 'id is required (e.g. bacs, cheque, cod, stripe).' );
	$p = array();
	if ( isset( $input['enabled'] ) )     $p['enabled']     = filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN );
	if ( isset( $input['title'] ) )       $p['title']       = sanitize_text_field( wp_unslash( $input['title'] ) );
	if ( isset( $input['description'] ) ) $p['description'] = wp_kses_post( wp_unslash( $input['description'] ) );
	if ( isset( $input['settings'] ) ) {
		if ( ! is_array( $input['settings'] ) ) return wsp_woo_fail( 'settings must be an object of key => value.' );
		$settings = array();
		foreach ( $input['settings'] as $k => $v ) {
			if ( ! is_scalar( $v ) || WSP_WOO_SECRET_MASK === $v ) continue; // never write a masked placeholder back
			$settings[ sanitize_key( $k ) ] = sanitize_textarea_field( (string) $v );
		}
		if ( $settings ) $p['settings'] = $settings;
	}
	if ( empty( $p ) ) return wsp_woo_fail( 'No fields to update provided.' );
	$res = wsp_woo_rest( 'PUT', 'payment_gateways/' . $id, $p );
	if ( is_wp_error( $res ) ) return wsp_woo_fail( $res->get_error_message() );
	return wsp_woo_ok( wsp_woo_gateway_row( $res ) );
}
