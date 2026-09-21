<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Zero-touch Staging bootstrap for the WordPress-local ChatGPT OAuth authority.
 *
 * The bootstrap stores only a bounded WordPress user id/configuration marker.
 * It never stores OAuth credentials or signing material. Explicit operator
 * constants always win, Production is never auto-enabled, and ambiguous
 * administrator selection fails closed.
 */
final class MAD4B_SCP_Staging_OAuth_Autoconfig {
	const CONTRACT = 'mad4b.staging-oauth-autoconfig.v1';
	const OPTION = 'mad4b_scp_staging_oauth_autoconfig_v1';
	const VERSION = 1;

	private static $bootstrapped = false;
	private static $status = array();

	public static function bootstrap() {
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		self::$status = array(
			'contract' => self::CONTRACT,
			'environment' => $environment,
			'eligible' => false,
			'configured' => false,
			'wp_user_id' => 0,
			'configuration_source' => 'none',
			'blocker' => '',
			'stores_credentials' => false,
			'stores_private_key' => false,
			'production_auto_enable' => false,
		);

		if ( 'staging' !== $environment ) {
			self::$status['blocker'] = 'environment_not_staging';
			return self::$status;
		}
		self::$status['eligible'] = true;

		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) {
			self::$status['blocker'] = 'explicit_local_oauth_disabled';
			return self::$status;
		}

		$issuer = self::local_issuer();
		if ( '' === $issuer ) {
			self::$status['blocker'] = 'local_issuer_unavailable';
			return self::$status;
		}

		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) ) {
			$mode = sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) );
			if ( 'local' !== $mode ) {
				self::$status['blocker'] = 'explicit_non_local_oauth_mode';
				return self::$status;
			}
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) {
			$configured_issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
			if ( '' !== $configured_issuer && ! hash_equals( $issuer, $configured_issuer ) ) {
				self::$status['blocker'] = 'explicit_external_oauth_issuer';
				return self::$status;
			}
		}

		$selection = self::select_subject_user();
		$user_id = isset( $selection['user_id'] ) ? absint( $selection['user_id'] ) : 0;
		if ( $user_id < 1 ) {
			self::$status['blocker'] = isset( $selection['blocker'] ) ? sanitize_key( (string) $selection['blocker'] ) : 'staging_admin_subject_unavailable';
			return self::$status;
		}

		$subject = 'user:' . $user_id;
		$record = array(
			'version' => self::VERSION,
			'wp_user_id' => $user_id,
			'issuer' => $issuer,
			'updated_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION, $record, false );

		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_MODE' ) ) define( 'MAD4B_MCP_OAUTH_MODE', 'local' );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $user_id );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', array( $subject ) );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS', array( $issuer => array( $subject ) ) );

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = $user_id;
		self::$status['configuration_source'] = isset( $selection['source'] ) ? sanitize_key( (string) $selection['source'] ) : 'staging_auto';
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	private static function select_subject_user() {
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) {
			$user_id = absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) );
			return self::admin_capable( $user_id )
				? array( 'user_id' => $user_id, 'source' => 'explicit_wp_user' )
				: array( 'user_id' => 0, 'blocker' => 'explicit_wp_user_invalid' );
		}

		$record = get_option( self::OPTION, array() );
		$stored_user_id = is_array( $record ) && isset( $record['wp_user_id'] ) ? absint( $record['wp_user_id'] ) : 0;
		if ( self::admin_capable( $stored_user_id ) ) return array( 'user_id' => $stored_user_id, 'source' => 'persisted_staging_auto' );

		$current_user_id = get_current_user_id();
		if ( self::admin_capable( $current_user_id ) ) return array( 'user_id' => $current_user_id, 'source' => 'current_staging_admin' );

		$administrators = get_users(
			array(
				'role' => 'administrator',
				'fields' => 'ID',
				'number' => 2,
				'orderby' => 'ID',
				'order' => 'ASC',
			)
		);
		$administrators = array_values( array_filter( array_map( 'absint', is_array( $administrators ) ? $administrators : array() ) ) );
		if ( 1 === count( $administrators ) && self::admin_capable( $administrators[0] ) ) {
			return array( 'user_id' => $administrators[0], 'source' => 'single_staging_admin' );
		}
		return array( 'user_id' => 0, 'blocker' => empty( $administrators ) ? 'staging_admin_subject_unavailable' : 'staging_admin_subject_ambiguous' );
	}

	private static function admin_capable( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return false;
		$user = get_userdata( $user_id );
		return $user && user_can( $user, 'manage_options' );
	}

	private static function local_issuer() {
		$url = untrailingslashit( home_url( '/oauth/mcp' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) return '';
		return $url;
	}
}
