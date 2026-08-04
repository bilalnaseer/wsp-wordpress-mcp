<?php
/**
 * Shared loader for the plugin ability registry, used by the abilities
 * generator and the HTML patcher.
 *
 * `wsp-mcp-ai-agents-connector/includes/registry.php` is the single source of
 * truth. This file executes it in a minimal stub environment so every
 * plugin-gated group (Yoast, Rank Math, WooCommerce, Elementor, ACF, UAE,
 * Gravity Forms, Contact Form 7, WPForms) is included. Every active-check the
 * registry gates a group behind MUST be stubbed here, or that group is
 * silently dropped from the generated docs and the website.
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
if ( ! function_exists( 'wsp_gravity_is_active' ) )  { function wsp_gravity_is_active()  { return true; } }
if ( ! function_exists( 'wsp_cf7_is_active' ) )       { function wsp_cf7_is_active()       { return true; } }
if ( ! function_exists( 'wsp_wpforms_is_active' ) )   { function wsp_wpforms_is_active()   { return true; } }
if ( ! function_exists( 'wsp_geodirectory_is_active' ) ) { function wsp_geodirectory_is_active() { return true; } }
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

/**
 * Plugin group => "requires the X plugin", in registry order.
 *
 * Derived from the registry itself: every group that is not a core group is a
 * plugin section. This must never be hand-maintained — a plugin group present
 * in the registry but absent here breaks the website (GROUPS[a.group] is
 * undefined) and silently drops the group from abilities.md.
 *
 * @return array<string,string>
 */
function wsp_abilities_plugin_sections() {
	// Groups whose "requires" wording differs from their display name.
	$overrides = array(
		'Ultimate Addons Elementor' => 'requires the Ultimate Addons for Elementor plugin',
		'Advanced Custom Fields'    => 'requires the ACF plugin',
	);

	$core_groups = wsp_abilities_core_groups();
	$sections    = array();
	foreach ( wsp_abilities_all() as $a ) {
		$group = $a['group'];
		if ( in_array( $group, $core_groups, true ) || isset( $sections[ $group ] ) ) {
			continue;
		}
		$sections[ $group ] = isset( $overrides[ $group ] )
			? $overrides[ $group ]
			: sprintf( 'requires the %s plugin', $group );
	}
	return $sections;
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
