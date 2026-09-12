<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Supplies local metadata for the certified MCP Adapter dependency on the exact
 * governed Staging admin boundary and normalizes known third-party MCP metadata
 * deprecations without changing provider execution semantics.
 *
 * WordPress core asks WordPress.org for plugin_information even when a required
 * plugin is already installed. MCP Adapter is distributed from the official
 * WordPress GitHub repository rather than the WordPress.org plugin directory,
 * so that lookup returns 404. This bridge short-circuits only that one metadata
 * request. It also mirrors deprecated Rank Math top-level MCP resource metadata
 * into the MCP Adapter 0.5+ namespace on exact Staging only. Existing mcp.*
 * values always win and legacy provider metadata remains untouched.
 *
 * The bridge never changes installation/update state, outbound HTTP globally,
 * credentials, authority, callbacks, permissions, or Production behavior.
 */
final class MAD4B_SCP_MCP_Adapter_Metadata_Bridge {
	const CONTRACT = 'mad4b.mcp-adapter-metadata-bridge.v1';
	const RANK_MATH_META_CONTRACT = 'mad4b.rank-math-mcp-meta-compat.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const SLUG = 'mcp-adapter';
	const VERSION = '0.6.1';

	private static $booted = false;
	private static $hook_registered = false;
	private static $ability_meta_hook_registered = false;
	private static $short_circuit_count = 0;
	private static $rank_math_meta_mirror_count = 0;

	public static function bootstrap() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Rank Math currently supplies legacy top-level resource metadata that MCP
		// Adapter 0.5+ still accepts only through a deprecated fallback. Mirror the
		// exact known keys into meta.mcp before an ability is materialized. This is
		// intentionally exact-Staging-only and copy-if-missing: provider values,
		// callbacks, schemas, permissions, and the original legacy keys are preserved.
		if ( self::site_eligible() ) {
			add_filter( 'wp_register_ability_args', array( __CLASS__, 'normalize_rank_math_mcp_meta' ), 40, 2 );
			self::$ability_meta_hook_registered = true;
		}

		if ( ! self::eligible() ) return;

		// This is a fallback, not an ownership claim. Let any existing provider
		// answer first; only replace the final false sentinel that would send Core
		// to WordPress.org for this exact non-directory dependency.
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_information' ), PHP_INT_MAX, 3 );
		self::$hook_registered = true;
	}

	/**
	 * Mirror MCP Adapter 0.5+ resource metadata for Rank Math abilities only.
	 *
	 * @param array  $args Ability registration arguments.
	 * @param string $name Ability name.
	 * @return array
	 */
	public static function normalize_rank_math_mcp_meta( $args, $name ) {
		if ( ! self::site_eligible() || ! is_array( $args ) ) return $args;
		$name = (string) $name;
		if ( 0 !== strpos( $name, 'rank-math/' ) ) return $args;
		if ( empty( $args['meta'] ) || ! is_array( $args['meta'] ) ) return $args;

		$meta = $args['meta'];
		if ( isset( $meta['mcp'] ) && ! is_array( $meta['mcp'] ) ) return $args;
		$mcp = isset( $meta['mcp'] ) ? $meta['mcp'] : array();
		$changed = 0;

		// These are the MCP Adapter resource keys that are namespaced under mcp.*.
		// Do not remove the legacy keys because Rank Math may still consume them.
		foreach ( array( 'uri', 'mimeType', 'size', 'annotations' ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) || array_key_exists( $key, $mcp ) ) continue;
			$mcp[ $key ] = $meta[ $key ];
			$changed++;
		}

		if ( $changed > 0 ) {
			$args['meta']['mcp'] = $mcp;
			self::$rank_math_meta_mirror_count += $changed;
		}

		return $args;
	}

	/**
	 * Short-circuit the exact Core plugin_information query only when no earlier
	 * provider has already supplied a result.
	 */
	public static function filter_plugin_information( $result, $action, $args ) {
		if ( false !== $result ) return $result;
		if ( 'plugin_information' !== (string) $action ) return $result;
		if ( ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== (string) $args->slug ) return $result;
		if ( ! self::eligible() ) return $result;

		self::$short_circuit_count++;

		return (object) array(
			'name' => 'MCP Adapter',
			'slug' => self::SLUG,
			'version' => self::VERSION,
			'author' => 'WP Core AI Team',
			'homepage' => 'https://github.com/WordPress/mcp-adapter',
			'requires' => '6.9',
			'tested' => '6.9',
			'requires_php' => '7.4',
			'short_description' => 'Official WordPress MCP Adapter for exposing authorized WordPress Abilities through the Model Context Protocol.',
			'icons' => array(),
			'sections' => array(
				'description' => 'Official WordPress MCP Adapter used by the governed MAD4B Staging MCP runtime.',
			),
		);
	}

	public static function status() {
		return array(
			'contract' => self::CONTRACT,
			'eligible' => self::eligible(),
			'site_eligible' => self::site_eligible(),
			'hook_registered' => self::$hook_registered,
			'ability_meta_hook_registered' => self::$ability_meta_hook_registered,
			'admin_request_only' => true,
			'fallback_priority' => PHP_INT_MAX,
			'slug' => self::SLUG,
			'version' => self::VERSION,
			'short_circuit_count' => self::$short_circuit_count,
			'rank_math_meta_contract' => self::RANK_MATH_META_CONTRACT,
			'rank_math_meta_staging_only' => true,
			'rank_math_meta_copy_if_missing' => true,
			'rank_math_legacy_keys_preserved' => true,
			'rank_math_meta_mirror_count' => self::$rank_math_meta_mirror_count,
			'production_changed' => false,
			'other_plugin_api_requests_changed' => false,
			'outbound_http_changed' => false,
			'credentials_changed' => false,
			'installation_or_update_state_changed' => false,
		);
	}

	private static function eligible() {
		return function_exists( 'is_admin' ) && is_admin() && self::site_eligible();
	}

	private static function site_eligible() {
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) return false;
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) return false;

		$host = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.' ) );
		return self::STAGING_HOST === $host;
	}
}
