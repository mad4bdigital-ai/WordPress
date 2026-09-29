<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral zero-touch read-only connection bootstrap.
 *
 * This class intentionally does NOT create or mutate a Site Profile. A fresh
 * installation therefore remains zero-authority for governed write, Skills,
 * Developer and Breakglass surfaces while becoming discoverable as a safe
 * ChatGPT MCP read resource.
 */
final class MAD4B_SCP_Portable_Readonly_Connection {
	const CONTRACT = 'mad4b.portable-readonly-connection.v1';
	const READ_SCOPE = 'mad4b:read';

	private static $bootstrapped = false;
	private static $status = array();

	public static function bootstrap() {
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$origin = self::origin();
		self::$status = array(
			'contract' => self::CONTRACT,
			'configured' => false,
			'effective' => false,
			'environment' => $environment,
			'origin' => $origin,
			'issuer' => self::issuer(),
			'user_ids' => array(),
			'primary_user_id' => 0,
			'scopes' => array( self::READ_SCOPE, 'offline_access' ),
			'write_enabled' => false,
			'developer_enabled' => false,
			'breakglass_enabled' => false,
			'skills_enabled' => false,
			'production_readonly' => 'production' === $environment,
			'profile_present' => false,
			'profile_binding_state' => 'unconfigured',
			'profile_origin_match' => false,
			'profile_environment_match' => false,
			'profile_canonical_origin' => '',
			'profile_configured_environment' => '',
			'foreign_profile_quarantined' => false,
			'profile_authority_inherited' => false,
			'requires_site_enrollment' => false,
			'upgrade_continuity_state' => '',
			'upgrade_continuity_blocker' => '',
			'blocker' => '',
		);

		if ( ! self::enabled() ) return self::block( 'portable_readonly_disabled' );

		$profile_status = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$profile_present = ! empty( $profile_status['configured'] );
		$profile_origin_match = ! empty( $profile_status['origin_match'] );
		$profile_environment_match = ! empty( $profile_status['environment_match'] );
		$profile_exact = $profile_present && $profile_origin_match && $profile_environment_match;
		$profile_binding_state = isset( $profile_status['binding_state'] )
			? sanitize_key( (string) $profile_status['binding_state'] )
			: ( $profile_present
				? ( $profile_origin_match ? 'environment_drift' : 'foreign_origin' )
				: 'unconfigured' );
		self::$status['profile_present'] = $profile_present;
		self::$status['profile_binding_state'] = $profile_binding_state;
		self::$status['profile_origin_match'] = $profile_origin_match;
		self::$status['profile_environment_match'] = $profile_environment_match;
		self::$status['profile_canonical_origin'] = isset( $profile_status['canonical_origin'] ) ? (string) $profile_status['canonical_origin'] : '';
		self::$status['profile_configured_environment'] = isset( $profile_status['configured_environment'] ) ? sanitize_key( (string) $profile_status['configured_environment'] ) : '';

		if ( isset( $profile_status['source'] ) && 'stored_invalid' === sanitize_key( (string) $profile_status['source'] ) ) {
			return self::block( 'site_profile_invalid_fail_closed' );
		}
		if ( $profile_exact ) return self::block( 'site_profile_present' );
		if ( $profile_present ) {
			// A cloned/moved Site Profile belongs to another exact tenant binding.
			// Keep its authority quarantined and expose only a fresh, current-origin
			// read-only OAuth surface until an administrator explicitly enrolls this site.
			self::$status['foreign_profile_quarantined'] = true;
			self::$status['requires_site_enrollment'] = true;
		}

		if ( class_exists( 'MAD4B_SCP_Upgrade_Continuity' ) ) {
			$continuity = MAD4B_SCP_Upgrade_Continuity::recovery_status();
			self::$status['upgrade_continuity_state'] = isset( $continuity['state'] ) ? sanitize_key( (string) $continuity['state'] ) : '';
			self::$status['upgrade_continuity_blocker'] = isset( $continuity['blocker'] ) ? sanitize_key( (string) $continuity['blocker'] ) : '';
			// Continuity is an exact-old-identity recovery path. A blocked/stale recovery
			// must never import that identity, but it also must not suppress the independent
			// current-origin portable read-only path. Invalid stored profiles already fail
			// closed above; explicit OAuth constants are still honored below.
		}
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) {
			return self::block( 'environment_not_supported' );
		}
		if ( ! self::transport_allowed( $environment, $origin ) ) return self::block( 'portable_https_required' );

		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) {
			return self::block( 'explicit_local_oauth_disabled' );
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) {
			return self::block( 'explicit_resource_oauth_disabled' );
		}
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' !== sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) ) ) {
			return self::block( 'explicit_non_local_oauth_mode' );
		}

		$issuer = self::issuer();
		if ( '' === $issuer ) return self::block( 'portable_issuer_unavailable' );
		if ( defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) {
			$configured_issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
			if ( '' !== $configured_issuer && ! hash_equals( $issuer, $configured_issuer ) ) {
				return self::block( 'explicit_external_oauth_issuer' );
			}
		}

		$user_ids = self::administrator_user_ids();
		if ( empty( $user_ids ) ) return self::block( 'portable_admin_subject_unavailable' );
		$primary_user_id = (int) reset( $user_ids );
		$subjects = array_values( array_map( static function ( $user_id ) {
			return 'user:' . absint( $user_id );
		}, $user_ids ) );

		if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_MODE' ) ) define( 'MAD4B_MCP_OAUTH_MODE', 'local' );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) define( 'MAD4B_MCP_OAUTH_ISSUER', $issuer );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $primary_user_id );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER', array( $issuer => $primary_user_id ) );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', $subjects );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS', array( $issuer => $subjects ) );

		// Production auto-connect authorizes only the local OAuth read resource.
		// It does not enable any MAD4B mutation, Developer or Breakglass grant.
		if ( 'production' === $environment ) {
			if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) {
				return self::block( 'explicit_local_oauth_production_disabled' );
			}
			if ( defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) {
				return self::block( 'explicit_resource_oauth_production_disabled' );
			}
			if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true );
			if ( ! defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
		}

		self::$status['configured'] = true;
		self::$status['effective'] = true;
		self::$status['user_ids'] = $user_ids;
		self::$status['primary_user_id'] = $primary_user_id;
		self::$status['blocker'] = '';
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	public static function effective() {
		$status = self::status();
		return ! empty( $status['effective'] );
	}

	public static function user_is_enrolled( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 || ! self::effective() ) return false;
		return in_array( $user_id, self::$status['user_ids'], true );
	}

	public static function connection_uuid() {
		$origin = self::origin();
		if ( '' === $origin ) return '';
		$hex = substr( hash( 'sha256', self::CONTRACT . "\0" . $origin ), 0, 32 );
		$hex[12] = '5';
		$variant = hexdec( $hex[16] );
		$hex[16] = dechex( ( $variant & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	public static function connection_digest() {
		$status = self::status();
		if ( empty( $status['effective'] ) ) return '';
		$material = array(
			'contract' => self::CONTRACT,
			'origin' => $status['origin'],
			'environment' => $status['environment'],
			'user_ids' => $status['user_ids'],
			'scopes' => $status['scopes'],
		);
		$json = wp_json_encode( $material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function enabled() {
		return defined( 'MAD4B_SCP_PORTABLE_READONLY_AUTO_CONNECT' )
			? true === constant( 'MAD4B_SCP_PORTABLE_READONLY_AUTO_CONNECT' )
			: true;
	}

	private static function administrator_user_ids() {
		if ( ! function_exists( 'get_users' ) ) return array();
		$ids = get_users( array(
			'role' => 'administrator',
			'fields' => 'ID',
			'number' => 50,
			'orderby' => 'ID',
			'order' => 'ASC',
		) );
		$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();
		return $ids;
	}

	private static function origin() {
		if ( ! function_exists( 'home_url' ) ) return '';
		$parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( isset( $parts['port'] ) ) $origin .= ':' . absint( $parts['port'] );
		$path = isset( $parts['path'] ) ? '/' . ltrim( rtrim( (string) $parts['path'], '/' ), '/' ) : '';
		if ( '/' === $path ) $path = '';
		return $origin . $path;
	}

	private static function issuer() {
		$origin = self::origin();
		return '' === $origin ? '' : untrailingslashit( $origin . '/oauth/mcp' );
	}

	private static function transport_allowed( $environment, $origin ) {
		$scheme = strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) );
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( 'https' === $scheme ) return true;
		return 'local' === $environment && 'http' === $scheme && in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
	}

	private static function block( $code ) {
		self::$status['blocker'] = sanitize_key( (string) $code );
		return self::$status;
	}
}
