<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Client-agnostic compatibility layer for remote MCP consumers.
 *
 * Authorization remains owned by the configured OAuth authorization server.
 * This class publishes RFC 9728 protected-resource metadata at the exact
 * path-derived well-known location expected by standards-compliant MCP
 * clients. Vendor names are evidence profiles only and create no authority.
 */
final class MAD4B_SCP_MCP_Client_Compatibility {
	const CONTRACT = 'mad4b.mcp-client-compatibility.v1';
	const WELL_KNOWN_PREFIX = '/.well-known/oauth-protected-resource';
	const RESOURCE_PATH = '/wp-json/mcp/mad4b-read';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'parse_request', array( __CLASS__, 'serve_well_known_metadata' ), 0 );
	}

	public static function status() {
		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		return array(
			'contract' => self::CONTRACT,
			'client_agnostic' => true,
			'transport' => 'streamable_http',
			'oauth_resource_metadata' => 'rfc9728',
			'authorization_server_external' => true,
			'oauth_effective' => ! empty( $oauth['effective'] ),
			'oauth_discovery_ready' => self::oauth_discovery_ready( $oauth ),
			'authoritative_well_known_url' => self::authoritative_well_known_url(),
			'compatibility_alias_url' => self::compatibility_alias_url(),
			'client_profiles' => array( 'openai_chatgpt', 'anthropic_claude', 'google_gemini', 'manus', 'generic_mcp_client' ),
			'client_profiles_create_authority' => false,
			'client_vendor_required_for_authorization' => false,
			'resource' => self::resource_identifier(),
			'scope' => class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::READ_SCOPE : 'mad4b:read',
		);
	}

	public static function resource_identifier() {
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
		return untrailingslashit( rest_url( 'mcp/mad4b-read' ) );
	}

	public static function authoritative_well_known_url() {
		return self::origin() . self::WELL_KNOWN_PREFIX . self::RESOURCE_PATH;
	}

	public static function compatibility_alias_url() {
		return self::origin() . self::WELL_KNOWN_PREFIX;
	}

	public static function is_well_known_path( $path ) {
		$path = self::normalize_path( $path );
		return self::authoritative_path() === $path || self::WELL_KNOWN_PREFIX === $path;
	}

	public static function metadata_for_path( $path ) {
		$path = self::normalize_path( $path );
		if ( self::authoritative_path() !== $path ) return new WP_Error( 'mad4b_oauth_resource_metadata_path_unknown', 'Protected-resource metadata must use the RFC 9728 path-derived location.' );
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return new WP_Error( 'mad4b_oauth_resource_bridge_unavailable', 'OAuth resource bridge is unavailable.' );
		$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
		if ( ! self::oauth_discovery_ready( $status ) ) return new WP_Error( 'mad4b_oauth_resource_discovery_not_ready', 'OAuth protected-resource discovery is not configured for this environment.' );
		$metadata = MAD4B_SCP_OAuth_Resource_Bridge::protected_resource_metadata();
		$metadata['resource'] = self::resource_identifier();
		$metadata['resource_name'] = 'MAD4B WordPress Staging Read MCP';
		return $metadata;
	}

	public static function serve_well_known_metadata() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! self::is_well_known_path( $path ) ) return;
		$path = self::normalize_path( $path );
		if ( self::WELL_KNOWN_PREFIX === $path ) {
			status_header( 302 );
			header( 'Location: ' . esc_url_raw( self::authoritative_well_known_url() ) );
			header( 'Cache-Control: no-store' );
			exit;
		}
		$metadata = self::metadata_for_path( $path );
		if ( is_wp_error( $metadata ) ) {
			status_header( 503 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			echo wp_json_encode( array( 'error' => $metadata->get_error_code(), 'contract' => self::CONTRACT ) );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $metadata );
		exit;
	}

	private static function oauth_discovery_ready( $status ) {
		if ( ! is_array( $status ) ) return false;
		$environment = isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '';
		$environment_allowed = 'staging' === $environment || ( 'production' === $environment && ! empty( $status['production_approved'] ) );
		return ! empty( $status['configured'] ) && ! empty( $status['issuer_configured'] ) && ! empty( $status['https'] ) && $environment_allowed;
	}

	private static function authoritative_path() {
		return self::WELL_KNOWN_PREFIX . self::RESOURCE_PATH;
	}

	private static function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return rtrim( $path, '/' );
	}

	private static function origin() {
		$parts = wp_parse_url( self::resource_identifier() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . absint( $parts['port'] );
		return $origin;
	}
}
