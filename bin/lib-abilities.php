<?php
/**
 * Shared loader for the plugin ability registry, used by the abilities
 * generator and the HTML patcher.
 *
 * `wsp-mcp-ai-agents-connector/includes/registry.php` is the single source of
 * truth. This file executes it in a minimal stub environment so every
 * plugin-gated group (Yoast, WooCommerce, Elementor, ACF) is included.
 *
 * DEV TOOLING — lives outside the plugin folder, never shipped in the zip,
 * only reads registry.php.
 *
 * @package WSP_MCP
 */

// --- Minimal stubs so registry.php runs outside WordPress -------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // registry.php bails without this.
}
if ( ! function_exists( 'wsp_yoast_is_active' ) )     { function wsp_yoast_is_active()     { return true; } }
if ( ! function_exists( 'wsp_rankmath_is_active' ) )  { function wsp_rankmath_is_active()  { return true; } }
if ( ! function_exists( 'wsp_elementor_is_active' ) ) { function wsp_elementor_is_active() { return true; } }
if ( ! function_exists( 'wsp_acf_is_active' ) )       { function wsp_acf_is_active()       { return true; } }
if ( ! function_exists( 'wsp_uae_is_active' ) )       { function wsp_uae_is_active()       { return true; } }
if ( ! class_exists( 'WooCommerce' ) )                { class WooCommerce {} }

require __DIR__ . '/../wsp-mcp-ai-agents-connector/includes/registry.php';

if ( ! function_exists( 'wsp_mcp_ability_registry' ) ) {
	fwrite( STDERR, "Error: wsp_mcp_ability_registry() not found after including registry.php.\n" );
	exit( 1 );
}

/** @return array<string,array> The full ability registry (id => meta). */
function wsp_abilities_all() {
	return wsp_mcp_ability_registry();
}

/** @return string[] Core group names, in display order. */
function wsp_abilities_core_groups() {
	return array( 'Posts', 'Pages', 'Taxonomy', 'Comments', 'Media', 'Users', 'Search', 'Site' );
}

/** @return array<string,string> Plugin group => "requires the X plugin", in display order. */
function wsp_abilities_plugin_sections() {
	return array(
		'Yoast SEO'              => 'requires the Yoast SEO plugin',
		'Rank Math SEO'          => 'requires the Rank Math SEO plugin',
		'WooCommerce'            => 'requires the WooCommerce plugin',
		'Elementor'              => 'requires the Elementor plugin',
		'Ultimate Addons Elementor' => 'requires the Ultimate Addons for Elementor plugin',
		'Advanced Custom Fields' => 'requires the ACF plugin',
	);
}

/** @return array{total:int,core:int,read:int,write:int} */
function wsp_abilities_totals() {
	$core_groups = wsp_abilities_core_groups();
	$totals      = array( 'total' => 0, 'core' => 0, 'read' => 0, 'write' => 0 );
	foreach ( wsp_abilities_all() as $a ) {
		$totals['total']++;
		if ( in_array( $a['group'], $core_groups, true ) ) { $totals['core']++; }
		if ( 'read' === $a['access'] ) { $totals['read']++; } else { $totals['write']++; }
	}
	return $totals;
}
