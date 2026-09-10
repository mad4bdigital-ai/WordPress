<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Supplies local metadata for the certified MCP Adapter dependency on the exact
 * governed Staging admin boundary.
 *
 * WordPress core asks WordPress.org for plugin_information even when a required
 * plugin is already installed. MCP Adapter is distributed from the official
 * WordPress GitHub repository rather than the WordPress.org plugin directory,
 * so that lookup returns 404. This bridge short-circuits only that one metadata
 * request. It never changes installation/update state, outbound HTTP globally,
 * credentials, authority, or Production behavior.
 */
final class MAD4B_SCP_MCP_Adapter_Metadata_Bridge {
	const CONTRACT = 'mad4b.mcp-adapter-metadata-bridge.v1';
	const STAGING_HOST = 'staging.egypttourgates.com';
	const SLUG = 'mcp-adapter';
	const VERSION = '0.6.1';

	private static $booted = false;
	private static $hook_registered = false;
	private static $short_circuit_count = 0;

	public static function bootstrap() {
		if ( self::$booted ) return;
		self::$booted = true;
		if ( ! self::eligible() ) return;

		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_information' ), 5, 3 );
		self::$hook_registered = true;
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
			'hook_registered' => self::$hook_registered,
			'admin_request_only' => true,
			'slug' => self::SLUG,
			'version' => self::VERSION,
			'short_circuit_count' => self::$short_circuit_count,
			'production_changed' => false,
			'other_plugin_api_requests_changed' => false,
			'outbound_http_changed' => false,
			'credentials_changed' => false,
			'installation_or_update_state_changed' => false,
		);
	}

	private static function eligible() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		if ( ! function_exists( 'wp_get_environment_type' ) || 'staging' !== wp_get_environment_type() ) return false;
		if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) return false;

		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return self::STAGING_HOST === $host;
	}
}
