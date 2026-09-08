<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Client-agnostic compatibility layer for remote MCP consumers.
 *
 * Authorization remains owned by the configured OAuth authority registry. This
 * class publishes RFC 9728 protected-resource metadata at the exact path-derived
 * well-known location expected by standards-compliant MCP clients. Client
 * profiles are dynamic evidence only and never authorize.
 */
final class MAD4B_SCP_MCP_Client_Compatibility {
	const CONTRACT = 'mad4b.mcp-client-compatibility.v3';
	const WELL_KNOWN_PREFIX = '/.well-known/oauth-protected-resource';
	const RESOURCE_PATH = '/wp-json/mcp/mad4b-read';
	const MANIFEST_NAMESPACE = 'mad4b/v1';
	const MANIFEST_ROUTE = '/client-compatibility';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'parse_request', array( __CLASS__, 'serve_well_known_metadata' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_manifest_route' ), 1 );
	}

	public static function register_manifest_route() {
		register_rest_route(
			self::MANIFEST_NAMESPACE,
			self::MANIFEST_ROUTE,
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( __CLASS__, 'manifest_endpoint' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function manifest_endpoint() {
		$response = new WP_REST_Response( self::manifest(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function manifest() {
		$status = self::status();
		$detected = class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::detect_request_profile() : array();
		return array(
			'contract' => self::CONTRACT,
			'client_agnostic' => true,
			'resource' => self::resource_identifier(),
			'resource_name' => self::resource_name(),
			'transport' => 'streamable_http',
			'authentication' => array(
				'methods' => array( 'oauth_discovery', 'bearer_header' ),
				'protected_resource_metadata' => self::authoritative_well_known_url(),
				'scope' => $status['scope'],
				'authority_mode' => $status['oauth_authority_mode'],
				'authorization_servers' => self::authorization_servers(),
			),
			'remote_oauth_read_policy' => class_exists( 'MAD4B_SCP_Governed_Ability_Overrides' ) ? MAD4B_SCP_Governed_Ability_Overrides::remote_oauth_read_policy_status() : array(),
			'profile_registry' => class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::status() : array(),
			'profiles' => class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::profiles() : array(),
			'detected_profile' => isset( $detected['profile'] ) ? $detected['profile'] : array(),
			'detection_matched' => ! empty( $detected['matched'] ),
			'detection_authoritative' => false,
			'authorization_depends_on_vendor' => false,
			'unknown_client_fallback' => 'generic-mcp',
			'write_surfaces_exposed_by_this_manifest' => false,
		);
	}

	public static function status() {
		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$registry = class_exists( 'MAD4B_SCP_MCP_Client_Profile_Registry' ) ? MAD4B_SCP_MCP_Client_Profile_Registry::status() : array();
		$mode = isset( $oauth['authority_mode'] ) ? sanitize_key( (string) $oauth['authority_mode'] ) : '';
		$count = isset( $oauth['authority_count'] ) ? (int) $oauth['authority_count'] : 0;
		$local_key_ready = self::local_key_policy_ready( $oauth );
		$discovery_ready = self::oauth_discovery_ready( $oauth );
		return array(
			'contract' => self::CONTRACT,
			'client_agnostic' => true,
			'transport' => 'streamable_http',
			'oauth_resource_metadata' => 'rfc9728',
			'oauth_authority_mode' => $mode,
			'authorization_server_external' => in_array( $mode, array( 'external', 'hybrid' ), true ),
			'authorization_server_local' => in_array( $mode, array( 'local', 'hybrid' ), true ),
			'authorization_server_hybrid' => 'hybrid' === $mode,
			'authorization_server_count' => $count,
			'oauth_effective' => ! empty( $oauth['effective'] ) && $local_key_ready,
			'oauth_discovery_ready' => $discovery_ready,
			'local_key_path_policy_ready' => $local_key_ready,
			'authoritative_well_known_url' => self::authoritative_well_known_url(),
			'compatibility_alias_url' => self::compatibility_alias_url(),
			'compatibility_manifest_url' => untrailingslashit( rest_url( self::MANIFEST_NAMESPACE . self::MANIFEST_ROUTE ) ),
			'profile_registry_contract' => isset( $registry['contract'] ) ? $registry['contract'] : '',
			'profile_count' => isset( $registry['profile_count'] ) ? (int) $registry['profile_count'] : 0,
			'client_profiles_create_authority' => false,
			'client_vendor_required_for_authorization' => false,
			'unknown_clients_supported' => true,
			'environment' => isset( $oauth['environment'] ) ? sanitize_key( (string) $oauth['environment'] ) : '',
			'resource' => self::resource_identifier(),
			'resource_name' => self::resource_name(),
			'scope' => class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::READ_SCOPE : 'mad4b:read',
		);
	}

	public static function resource_identifier() {
		if ( class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier();
		return untrailingslashit( rest_url( 'mcp/mad4b-read' ) );
	}

	public static function authoritative_well_known_url() { return self::origin() . self::WELL_KNOWN_PREFIX . self::RESOURCE_PATH; }
	public static function compatibility_alias_url() { return self::origin() . self::WELL_KNOWN_PREFIX; }

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
		$metadata['resource_name'] = self::resource_name();
		$metadata['mad4b_authority_mode'] = isset( $status['authority_mode'] ) ? $status['authority_mode'] : '';
		$metadata['mad4b_client_compatibility'] = untrailingslashit( rest_url( self::MANIFEST_NAMESPACE . self::MANIFEST_ROUTE ) );
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

	private static function authorization_servers() {
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ) return array();
		$status = MAD4B_SCP_OAuth_Resource_Bridge::status();
		if ( ! self::oauth_discovery_ready( $status ) ) return array();
		$metadata = MAD4B_SCP_OAuth_Resource_Bridge::protected_resource_metadata();
		return isset( $metadata['authorization_servers'] ) && is_array( $metadata['authorization_servers'] ) ? $metadata['authorization_servers'] : array();
	}

	private static function oauth_discovery_ready( $status ) {
		if ( ! is_array( $status ) ) return false;
		$environment = isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '';
		$environment_allowed = 'staging' === $environment || ( 'production' === $environment && ! empty( $status['production_approved'] ) );
		return ! empty( $status['configured'] )
			&& ! empty( $status['effective'] )
			&& self::local_key_policy_ready( $status )
			&& ! empty( $status['authority_registry_valid'] )
			&& ! empty( $status['subject_policy_ready'] )
			&& ! empty( $status['authority_count'] )
			&& ! empty( $status['https'] )
			&& $environment_allowed;
	}

	private static function local_key_policy_ready( $status ) {
		$mode = is_array( $status ) && isset( $status['authority_mode'] ) ? sanitize_key( (string) $status['authority_mode'] ) : '';
		if ( ! in_array( $mode, array( 'local', 'hybrid' ), true ) ) return true;
		return class_exists( 'MAD4B_SCP_Local_OAuth_Key_Path_Policy' ) && MAD4B_SCP_Local_OAuth_Key_Path_Policy::transport_ready();
	}

	private static function resource_name() {
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$label = 'MAD4B WordPress';
		if ( 'staging' === $environment ) $label .= ' Staging';
		elseif ( 'production' === $environment ) $label .= ' Production';
		elseif ( '' !== $environment && 'unknown' !== $environment ) $label .= ' ' . ucfirst( $environment );
		return $label . ' Read MCP';
	}

	private static function authoritative_path() { return self::WELL_KNOWN_PREFIX . self::RESOURCE_PATH; }
	private static function normalize_path( $path ) { $path = '/' . ltrim( (string) $path, '/' ); return rtrim( $path, '/' ); }

	private static function origin() {
		$parts = wp_parse_url( self::resource_identifier() );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( isset( $parts['port'] ) ) $origin .= ':' . absint( $parts['port'] );
		return $origin;
	}
}
