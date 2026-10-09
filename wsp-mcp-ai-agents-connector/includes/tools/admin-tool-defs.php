<?php
/**
 * Table-driven definitions for the v2.9.5 WooCommerce store-management and plugin-management tools.
 *
 * One table feeds BOTH wsp_mcp_ability_registry() (admin toggles) and wsp_mcp_register_native_tools()
 * (MCP registration), so the two can never drift apart. Row shape:
 *   tool => [ label, description, access (read|write), capability, props, required ]
 * props: name => [ type, description ] — type is i|s|b|o|ia|sa (integer, string, boolean, object,
 * integer[], string[]).
 * Ability key is derived: wsp_woo_get_tax_classes -> wsp/woo-get-tax-classes.
 * Callback is derived:    wsp_woo_get_tax_classes -> wsp_execute_woo_get_tax_classes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function wsp_mcp_woo_admin_tool_defs() {
	$id    = array( 'i', 'Item ID.' );
	$force = array( 'b', 'Must be true: this item cannot be trashed and is deleted permanently.' );
	$term  = array(
		'name'        => array( 's', 'Name.' ),
		'slug'        => array( 's', 'Slug (optional).' ),
		'description' => array( 's', 'Description (optional).' ),
	);
	$rate  = array(
		'country'  => array( 's', '2-letter ISO country code, e.g. US. Empty = all.' ),
		'state'    => array( 's', 'State code, e.g. CA.' ),
		'postcode' => array( 's', 'Postcode(s), ";"-separated, wildcards allowed.' ),
		'city'     => array( 's', 'City name(s), ";"-separated.' ),
		'rate'     => array( 's', 'Tax rate percentage 0-100, e.g. "7.5".' ),
		'name'     => array( 's', 'Rate label shown to customers, e.g. VAT.' ),
		'priority' => array( 'i', 'Priority (>=1).' ),
		'compound' => array( 'b', 'Compound on top of other taxes.' ),
		'shipping' => array( 'b', 'Apply to shipping.' ),
		'class'    => array( 's', 'Tax class slug ("" = standard).' ),
	);
	$method_settings = array( 'o', 'Method settings key/value, e.g. {"title":"Flat rate","cost":"10"}.' );
	$W = 'manage_woocommerce';
	$A = 'manage_options';

	return array(
		// ---- Delete ----
		'wsp_woo_delete_product'   => array( 'Delete WooCommerce Product', 'Move a product (simple, variable or variation) to the trash, or permanently delete with force=true.', 'write', $W, array( 'id' => $id, 'force' => array( 'b', 'true = permanent delete. Default false = trash.' ) ), array( 'id' ) ),
		'wsp_woo_delete_variation' => array( 'Delete Product Variation', 'Trash or permanently delete (force=true) one variation of a variable product.', 'write', $W, array( 'product_id' => array( 'i', 'Parent product ID.' ), 'variation_id' => array( 'i', 'Variation ID.' ), 'force' => array( 'b', 'true = permanent delete. Default false = trash.' ) ), array( 'product_id', 'variation_id' ) ),
		'wsp_woo_delete_coupon'    => array( 'Delete WooCommerce Coupon', 'Trash a coupon, or permanently delete with force=true.', 'write', $W, array( 'id' => $id, 'force' => array( 'b', 'true = permanent delete. Default false = trash.' ) ), array( 'id' ) ),
		'wsp_woo_delete_category'  => array( 'Delete Product Category', 'Permanently delete a product category (force=true required; terms have no trash). Products are not deleted.', 'write', $W, array( 'id' => $id, 'force' => $force ), array( 'id', 'force' ) ),
		'wsp_woo_delete_tag'       => array( 'Delete Product Tag', 'Permanently delete a product tag (force=true required; terms have no trash).', 'write', $W, array( 'id' => $id, 'force' => $force ), array( 'id', 'force' ) ),

		// ---- Categories / tags ----
		'wsp_woo_get_product_categories'  => array( 'List Product Categories', 'List product categories (id, name, slug, parent, count, image_id).', 'read', $W, array( 'search' => array( 's', 'Search term.' ), 'per_page' => array( 'i', '1-100, default 100.' ), 'page' => array( 'i', 'Page number.' ) ), array() ),
		'wsp_woo_create_product_category' => array( 'Create Product Category', 'Create a product category.', 'write', $W, $term + array( 'parent' => array( 'i', 'Parent category ID.' ), 'image_id' => array( 'i', 'Media library attachment ID.' ) ), array( 'name' ) ),
		'wsp_woo_update_product_category' => array( 'Update Product Category', 'Update a product category (name, slug, parent, description, image).', 'write', $W, array( 'id' => $id ) + $term + array( 'parent' => array( 'i', 'Parent category ID (0 = top level).' ), 'image_id' => array( 'i', 'Attachment ID (0 removes the image).' ) ), array( 'id' ) ),
		'wsp_woo_get_product_tags'        => array( 'List Product Tags', 'List product tags.', 'read', $W, array( 'search' => array( 's', 'Search term.' ), 'per_page' => array( 'i', '1-100, default 100.' ), 'page' => array( 'i', 'Page number.' ) ), array() ),
		'wsp_woo_update_product_tag'      => array( 'Update Product Tag', 'Update a product tag (name, slug, description). Returns the updated tag.', 'write', $W, array( 'id' => $id ) + $term, array( 'id' ) ),
		'wsp_woo_assign_product_tags'     => array( 'Assign Product Tags (bulk)', 'Add, replace or remove tags on many products at once. Tags can be given as IDs and/or names (unknown names are created). Max 200 products per call. Returns per-product results.', 'write', $W, array( 'product_ids' => array( 'ia', 'Product IDs (max 200).' ), 'tag_ids' => array( 'ia', 'Existing product tag IDs.' ), 'tag_names' => array( 'sa', 'Tag names; missing ones are created.' ), 'mode' => array( 's', 'add (default) | replace | remove.' ) ), array( 'product_ids' ) ),
		'wsp_woo_create_product_tag'      => array( 'Create Product Tag', 'Create a product tag.', 'write', $W, $term, array( 'name' ) ),

		// ---- Attributes ----
		'wsp_woo_get_attributes'        => array( 'List Product Attributes', 'List global product attributes (e.g. Color, Size) with their taxonomy names.', 'read', $W, array(), array() ),
		'wsp_woo_create_attribute'      => array( 'Create Product Attribute', 'Create a global product attribute.', 'write', $W, array( 'name' => array( 's', 'Attribute name.' ), 'slug' => array( 's', 'Slug (optional).' ), 'type' => array( 's', 'select | text. Default select.' ), 'order_by' => array( 's', 'menu_order | name | name_num | id.' ), 'has_archives' => array( 'b', 'Enable archives.' ) ), array( 'name' ) ),
		'wsp_woo_update_attribute'      => array( 'Update Product Attribute', 'Update a global product attribute.', 'write', $W, array( 'id' => $id, 'name' => array( 's', 'Name.' ), 'slug' => array( 's', 'Slug.' ), 'type' => array( 's', 'select | text.' ), 'order_by' => array( 's', 'menu_order | name | name_num | id.' ), 'has_archives' => array( 'b', 'Enable archives.' ) ), array( 'id' ) ),
		'wsp_woo_delete_attribute'      => array( 'Delete Product Attribute', 'Permanently delete a global attribute and all its terms (force=true required).', 'write', $W, array( 'id' => $id, 'force' => $force ), array( 'id', 'force' ) ),
		'wsp_woo_get_attribute_terms'   => array( 'List Attribute Terms', 'List the terms (e.g. Red, Blue) of a global attribute.', 'read', $W, array( 'attribute_id' => array( 'i', 'Attribute ID.' ), 'search' => array( 's', 'Search term.' ) ), array( 'attribute_id' ) ),
		'wsp_woo_create_attribute_term' => array( 'Create Attribute Term', 'Add a term to a global attribute.', 'write', $W, array( 'attribute_id' => array( 'i', 'Attribute ID.' ), 'name' => array( 's', 'Term name.' ), 'slug' => array( 's', 'Slug (optional).' ) ), array( 'attribute_id', 'name' ) ),
		'wsp_woo_delete_attribute_term' => array( 'Delete Attribute Term', 'Permanently delete an attribute term (force=true required).', 'write', $W, array( 'attribute_id' => array( 'i', 'Attribute ID.' ), 'id' => array( 'i', 'Term ID.' ), 'force' => $force ), array( 'attribute_id', 'id', 'force' ) ),

		// ---- Settings ----
		'wsp_woo_get_settings'    => array( 'Get WooCommerce Settings', 'Read WooCommerce settings for a group: general (currency, position, separators, store address, selling/shipping countries, enable taxes), products, tax, shipping, checkout, account, email. Returns id, label, type, default and value only; dropdown choices (countries, currencies) are omitted unless include_options=true (each dropdown then shows options_count). Use setting_id / setting_ids to fetch just the settings you need, e.g. woocommerce_currency. Secret values are masked.', 'read', $A, array( 'group' => array( 's', 'general | products | tax | shipping | checkout | account | email.' ), 'setting_id' => array( 's', 'Return only this setting, e.g. woocommerce_currency or woocommerce_default_country.' ), 'setting_ids' => array( 'sa', 'Return only these settings.' ), 'include_options' => array( 'b', 'Include the full options list of select/multiselect settings. Default false. Best combined with setting_id; if the response would exceed ~50,000 characters, options are dropped and truncated_options:true is returned.' ) ), array( 'group' ) ),
		'wsp_woo_update_settings' => array( 'Update WooCommerce Settings', 'Update WooCommerce settings in a group. Use option ids exactly as returned by wsp_woo_get_settings, e.g. {"woocommerce_currency":"EUR","woocommerce_calc_taxes":"yes"}.', 'write', $A, array( 'group' => array( 's', 'general | products | tax | shipping | checkout | account | email.' ), 'settings' => array( 'o', 'Option id => value pairs.' ) ), array( 'group', 'settings' ) ),

		// ---- Tax ----
		'wsp_woo_get_tax_classes' => array( 'Get Tax Classes', 'List tax classes and whether taxes are enabled; optionally include configured tax rates.', 'read', $W, array( 'include_rates' => array( 'b', 'Also return up to 100 tax rates.' ) ), array() ),
		'wsp_woo_create_tax_rate' => array( 'Create Tax Rate', 'Create a tax rate.', 'write', $W, $rate, array( 'rate' ) ),
		'wsp_woo_update_tax_rate' => array( 'Update Tax Rate', 'Update a tax rate.', 'write', $W, array( 'id' => $id ) + $rate, array( 'id' ) ),
		'wsp_woo_delete_tax_rate' => array( 'Delete Tax Rate', 'Permanently delete a tax rate (force=true required).', 'write', $W, array( 'id' => $id, 'force' => $force ), array( 'id', 'force' ) ),

		// ---- Shipping ----
		'wsp_woo_get_shipping_zones'     => array( 'Get Shipping Zones', 'List shipping zones with their locations and methods.', 'read', $W, array(), array() ),
		'wsp_woo_create_shipping_zone'   => array( 'Create Shipping Zone', 'Create a shipping zone. locations: "US", "US:CA", "postcode:90210", "continent:EU".', 'write', $W, array( 'name' => array( 's', 'Zone name.' ), 'locations' => array( 'sa', 'Location codes.' ) ), array( 'name' ) ),
		'wsp_woo_update_shipping_zone'   => array( 'Update Shipping Zone', 'Update a shipping zone name, order and locations. When locations is provided it REPLACES the existing list ([] clears it). Each location is {code, type} with type country, state, postcode or continent (strings like "US:CA" also work). Zone 0 cannot be edited.', 'write', $W, array( 'id' => array( 'i', 'Zone ID.' ), 'name' => array( 's', 'Zone name.' ), 'order' => array( 'i', 'Sort order.' ), 'locations' => array( 'o_arr', 'Replacement locations.' ) ), array( 'id' ) ),
		'wsp_woo_delete_shipping_zone'   => array( 'Delete Shipping Zone', 'Permanently delete a shipping zone AND all of its shipping methods. Returns the zone name and the number of removed methods. Zone 0 ("Locations not covered by your other zones") cannot be deleted.', 'write', $W, array( 'id' => array( 'i', 'Zone ID (not 0).' ) ), array( 'id' ) ),
		'wsp_woo_get_shipping_methods'   => array( 'Get Shipping Methods', 'List the shipping methods of a zone (zone_id 0 = locations not covered by other zones).', 'read', $W, array( 'zone_id' => array( 'i', 'Zone ID.' ) ), array( 'zone_id' ) ),
		'wsp_woo_add_shipping_method'    => array( 'Add Shipping Method', 'Add a shipping method to a zone: flat_rate, free_shipping, local_pickup (or another installed method id).', 'write', $W, array( 'zone_id' => array( 'i', 'Zone ID.' ), 'method_id' => array( 's', 'flat_rate | free_shipping | local_pickup | ...' ), 'enabled' => array( 'b', 'Enabled.' ), 'settings' => $method_settings ), array( 'zone_id', 'method_id' ) ),
		'wsp_woo_update_shipping_method' => array( 'Update Shipping Method', 'Update a zone shipping method (settings, enabled, order).', 'write', $W, array( 'zone_id' => array( 'i', 'Zone ID.' ), 'instance_id' => array( 'i', 'Method instance ID.' ), 'enabled' => array( 'b', 'Enabled.' ), 'order' => array( 'i', 'Sort order.' ), 'settings' => $method_settings ), array( 'zone_id', 'instance_id' ) ),
		'wsp_woo_delete_shipping_method' => array( 'Delete Shipping Method', 'Permanently remove a shipping method from a zone (force=true required).', 'write', $W, array( 'zone_id' => array( 'i', 'Zone ID.' ), 'instance_id' => array( 'i', 'Method instance ID.' ), 'force' => $force ), array( 'zone_id', 'instance_id', 'force' ) ),

		// ---- Payment gateways ----
		'wsp_woo_get_payment_gateways'   => array( 'Get Payment Gateways', 'List payment gateways with enabled state, title and settings. Secret keys/tokens are masked.', 'read', $A, array(), array() ),
		'wsp_woo_update_payment_gateway' => array( 'Update Payment Gateway', 'Enable/disable a gateway or change its title, description and settings. Masked secret values are never written back.', 'write', $A, array( 'id' => array( 's', 'Gateway id, e.g. bacs, cheque, cod, stripe.' ), 'enabled' => array( 'b', 'Enabled.' ), 'title' => array( 's', 'Checkout title.' ), 'description' => array( 's', 'Checkout description.' ), 'settings' => array( 'o', 'Gateway settings key/value.' ) ), array( 'id' ) ),
	);
}

function wsp_mcp_plugin_admin_tool_defs() {
	$file = array( 's', 'Plugin file path, e.g. akismet/akismet.php.' );
	return array(
		'wsp_install_plugin'          => array( 'Install Plugin (wordpress.org)', 'Install a plugin from wordpress.org by slug, optionally activating it.', 'write', 'install_plugins', array( 'slug' => array( 's', 'wordpress.org plugin slug, e.g. woocommerce.' ), 'activate' => array( 'b', 'Activate after install. Default false.' ) ), array( 'slug' ) ),
		'wsp_install_plugin_from_url' => array( 'Install Plugin From URL', 'Install a plugin from a public https .zip URL. Only use sources you trust: the zip runs as PHP code.', 'write', 'install_plugins', array( 'zip_url' => array( 's', 'https:// URL of the plugin zip.' ), 'activate' => array( 'b', 'Activate after install. Default false.' ) ), array( 'zip_url' ) ),
		'wsp_delete_plugin'           => array( 'Delete Plugin', 'Delete an installed plugin (must be deactivated first; this plugin cannot delete itself).', 'write', 'delete_plugins', array( 'file' => $file ), array( 'file' ) ),
		'wsp_update_plugin'           => array( 'Update Plugin', 'Update an installed plugin to its latest available version.', 'write', 'update_plugins', array( 'file' => $file ), array( 'file' ) ),
	);
}

/** Convert a defs row's prop table into a JSON-Schema inputSchema. */
function wsp_mcp_defs_schema( $props, $required ) {
	$types = array( 'i' => 'integer', 's' => 'string', 'b' => 'boolean', 'o' => 'object' );
	$out   = array();
	foreach ( $props as $name => $p ) {
		if ( 'o_arr' === $p[0] ) {
			$out[ $name ] = array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'code' => array( 'type' => 'string' ), 'type' => array( 'type' => 'string', 'description' => 'country | state | postcode | continent' ) ) ), 'description' => $p[1] );
		} elseif ( 'ia' === $p[0] || 'sa' === $p[0] ) {
			$out[ $name ] = array( 'type' => 'array', 'items' => array( 'type' => 'ia' === $p[0] ? 'integer' : 'string' ), 'description' => $p[1] );
		} else {
			$out[ $name ] = array( 'type' => $types[ $p[0] ], 'description' => $p[1] );
		}
	}
	$schema = array( 'type' => 'object', 'properties' => $out ? $out : new stdClass() );
	if ( $required ) $schema['required'] = $required;
	return $schema;
}

/** Register a defs table with the native server. */
function wsp_mcp_register_defs( $defs ) {
	foreach ( $defs as $tool => $d ) {
		WSP_MCP_Server::register_tool( $tool, array(
			'description' => $d[1],
			'inputSchema' => wsp_mcp_defs_schema( $d[4], $d[5] ),
			'callback'    => 'wsp_execute_' . substr( $tool, 4 ),
			'capability'  => $d[3],
			'enable_key'  => 'wsp/' . str_replace( '_', '-', substr( $tool, 4 ) ),
		) );
	}
}

/** Convert a defs table to ability-registry rows for the given settings-page group. */
function wsp_mcp_defs_registry_rows( $defs, $group ) {
	$rows = array();
	foreach ( $defs as $tool => $d ) {
		$rows[ 'wsp/' . str_replace( '_', '-', substr( $tool, 4 ) ) ] = array(
			'label' => $d[0], 'description' => $d[1], 'group' => $group, 'access' => $d[2], 'default' => false,
		);
	}
	return $rows;
}
