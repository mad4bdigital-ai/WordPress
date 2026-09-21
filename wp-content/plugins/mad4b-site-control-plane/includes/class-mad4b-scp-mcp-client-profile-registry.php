<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Dynamic, evidence-only catalog for remote MCP client compatibility.
 *
 * Profiles never grant authority. They provide registration/detection hints and
 * allow future clients to be added by catalog data or a bounded WordPress
 * filter without changing authorization code.
 */
final class MAD4B_SCP_MCP_Client_Profile_Registry {
	const CONTRACT = 'mad4b.mcp-client-profile-registry.v1';
	const CATALOG_CONTRACT = 'mad4b.mcp-client-profile-catalog.v1';
	const MAX_PROFILES = 50;
	const MAX_MATCHERS = 12;
	const MAX_TOKEN_LENGTH = 64;

	private static $cache = null;

	public static function status() {
		$catalog = self::catalog();
		return array(
			'contract' => self::CONTRACT,
			'catalog_contract' => isset( $catalog['contract'] ) ? $catalog['contract'] : '',
			'default_profile' => isset( $catalog['default_profile'] ) ? $catalog['default_profile'] : 'generic-mcp',
			'profile_count' => isset( $catalog['profiles'] ) && is_array( $catalog['profiles'] ) ? count( $catalog['profiles'] ) : 0,
			'authorization_model' => isset( $catalog['authorization_model'] ) ? $catalog['authorization_model'] : 'vendor-neutral-oauth-resource-server',
			'profiles_create_authority' => false,
			'detection_authoritative' => false,
			'dynamic_extension_supported' => true,
		);
	}

	public static function profiles() {
		$catalog = self::catalog();
		return isset( $catalog['profiles'] ) && is_array( $catalog['profiles'] ) ? $catalog['profiles'] : array();
	}

	public static function profile_ids() {
		$ids = array();
		foreach ( self::profiles() as $profile ) {
			if ( isset( $profile['id'] ) ) $ids[] = $profile['id'];
		}
		return $ids;
	}

	public static function default_profile() {
		$catalog = self::catalog();
		$default = isset( $catalog['default_profile'] ) ? sanitize_key( (string) $catalog['default_profile'] ) : 'generic-mcp';
		foreach ( self::profiles() as $profile ) {
			if ( isset( $profile['id'] ) && $default === $profile['id'] ) return $profile;
		}
		return array(
			'id' => 'generic-mcp',
			'display_name' => 'Generic standards-compliant MCP client',
			'match_any' => array(),
			'transports' => array( 'streamable_http' ),
			'authentication' => array( 'oauth_discovery', 'bearer_header' ),
			'registration_hint' => 'authorization_server_policy',
			'authority_effect' => 'none',
		);
	}

	public static function detect_request_profile( $user_agent = null, $client_hint = null ) {
		if ( null === $user_agent ) $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( null === $client_hint ) $client_hint = isset( $_SERVER['HTTP_X_MCP_CLIENT_NAME'] ) ? wp_unslash( $_SERVER['HTTP_X_MCP_CLIENT_NAME'] ) : '';
		$haystack = strtolower( trim( (string) $user_agent . ' ' . (string) $client_hint ) );
		if ( '' !== $haystack ) {
			foreach ( self::profiles() as $profile ) {
				$matchers = isset( $profile['match_any'] ) && is_array( $profile['match_any'] ) ? $profile['match_any'] : array();
				foreach ( $matchers as $token ) {
					if ( '' !== $token && false !== strpos( $haystack, strtolower( $token ) ) ) {
						return array( 'profile' => $profile, 'matched' => true, 'authoritative' => false );
					}
				}
			}
		}
		return array( 'profile' => self::default_profile(), 'matched' => false, 'authoritative' => false );
	}

	public static function catalog() {
		if ( is_array( self::$cache ) ) return self::$cache;
		$path = MAD4B_SCP_DIR . 'config/mcp-client-profiles.json';
		$raw = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local package configuration.
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) || self::CATALOG_CONTRACT !== ( isset( $decoded['contract'] ) ? $decoded['contract'] : '' ) ) {
			$decoded = array(
				'contract' => self::CATALOG_CONTRACT,
				'default_profile' => 'generic-mcp',
				'authorization_model' => 'vendor-neutral-oauth-resource-server',
				'profiles' => array( self::default_profile_fallback() ),
			);
		}
		$profiles = isset( $decoded['profiles'] ) && is_array( $decoded['profiles'] ) ? array_slice( $decoded['profiles'], 0, self::MAX_PROFILES ) : array();
		$bounded = array();
		$ids = array();
		foreach ( $profiles as $profile ) {
			$normalized = self::normalize_profile( $profile );
			if ( ! is_array( $normalized ) || isset( $ids[ $normalized['id'] ] ) ) continue;
			$ids[ $normalized['id'] ] = true;
			$bounded[] = $normalized;
		}
		$filtered = apply_filters( 'mad4b_scp_mcp_client_profiles', $bounded );
		if ( is_array( $filtered ) ) {
			$bounded = array();
			$ids = array();
			foreach ( array_slice( $filtered, 0, self::MAX_PROFILES ) as $profile ) {
				$normalized = self::normalize_profile( $profile );
				if ( ! is_array( $normalized ) || isset( $ids[ $normalized['id'] ] ) ) continue;
				$ids[ $normalized['id'] ] = true;
				$bounded[] = $normalized;
			}
		}
		if ( ! $bounded ) $bounded[] = self::default_profile_fallback();
		$default = isset( $decoded['default_profile'] ) ? sanitize_key( (string) $decoded['default_profile'] ) : 'generic-mcp';
		$found_default = false;
		foreach ( $bounded as $profile ) if ( $default === $profile['id'] ) $found_default = true;
		if ( ! $found_default ) $default = $bounded[0]['id'];
		self::$cache = array(
			'contract' => self::CATALOG_CONTRACT,
			'default_profile' => $default,
			'authorization_model' => 'vendor-neutral-oauth-resource-server',
			'profiles' => $bounded,
		);
		return self::$cache;
	}

	public static function reset_for_tests() { self::$cache = null; }

	private static function normalize_profile( $profile ) {
		if ( ! is_array( $profile ) ) return null;
		$id = isset( $profile['id'] ) ? sanitize_key( (string) $profile['id'] ) : '';
		if ( '' === $id || strlen( $id ) > 64 ) return null;
		$display = isset( $profile['display_name'] ) ? sanitize_text_field( (string) $profile['display_name'] ) : $id;
		$matchers = array();
		foreach ( isset( $profile['match_any'] ) && is_array( $profile['match_any'] ) ? array_slice( $profile['match_any'], 0, self::MAX_MATCHERS ) : array() as $token ) {
			if ( ! is_string( $token ) ) continue;
			$token = strtolower( trim( $token ) );
			if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LENGTH || ! preg_match( '/^[a-z0-9._ -]+$/', $token ) ) continue;
			$matchers[] = $token;
		}
		$transports = self::bounded_enum_list( isset( $profile['transports'] ) ? $profile['transports'] : array(), array( 'streamable_http', 'sse' ) );
		$authentication = self::bounded_enum_list( isset( $profile['authentication'] ) ? $profile['authentication'] : array(), array( 'oauth_discovery', 'bearer_header', 'none' ) );
		$hint = isset( $profile['registration_hint'] ) ? sanitize_key( (string) $profile['registration_hint'] ) : 'authorization_server_policy';
		if ( ! in_array( $hint, array( 'separate_oauth_client_recommended', 'authorization_server_policy', 'manual_bearer_supported' ), true ) ) $hint = 'authorization_server_policy';
		return array(
			'id' => $id,
			'display_name' => $display,
			'match_any' => array_values( array_unique( $matchers ) ),
			'transports' => $transports ? $transports : array( 'streamable_http' ),
			'authentication' => $authentication ? $authentication : array( 'oauth_discovery', 'bearer_header' ),
			'registration_hint' => $hint,
			'authority_effect' => 'none',
		);
	}

	private static function bounded_enum_list( $values, $allowed ) {
		$out = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, 8 ) : array() as $value ) {
			$value = sanitize_key( (string) $value );
			if ( in_array( $value, $allowed, true ) ) $out[] = $value;
		}
		return array_values( array_unique( $out ) );
	}

	private static function default_profile_fallback() {
		return array(
			'id' => 'generic-mcp',
			'display_name' => 'Generic standards-compliant MCP client',
			'match_any' => array(),
			'transports' => array( 'streamable_http' ),
			'authentication' => array( 'oauth_discovery', 'bearer_header' ),
			'registration_hint' => 'authorization_server_policy',
			'authority_effect' => 'none',
		);
	}
}
