<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Local OAuth bootstrap with tenant-neutral Site Profile support.
 *
 * Enrolled sites bind the OAuth WordPress subject to the explicit Site Profile.
 * Production read-only OAuth still requires a separate administrator opt-in and
 * never grants mutation authority by itself. Historical ETG Production remains
 * a compatibility fallback only while no Site Profile exists.
 */
final class MAD4B_SCP_Staging_OAuth_Autoconfig {
	const CONTRACT = 'mad4b.staging-oauth-autoconfig.v2';
	const OPTION = 'mad4b_scp_staging_oauth_autoconfig_v1';
	const PRODUCTION_OPTION = 'mad4b_scp_production_readonly_oauth_v1';
	const PRODUCTION_HOST = 'egypttourgates.com';
	const VERSION = 1;

	private static $bootstrapped = false;
	private static $admin_actions_booted = false;
	private static $status = array();

	public static function bootstrap() {
		self::boot_admin_actions();
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::enrolled();
		$profile = $profile_enrolled ? MAD4B_SCP_Site_Profile::status() : array();
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
			'production_readonly_supported' => true,
			'production_readonly_enabled' => false,
			'write_authority_enabled' => false,
			'breakglass_enabled' => false,
			'profile_enrolled' => $profile_enrolled,
			'profile_revision' => ! empty( $profile['profile_revision'] ) ? (int) $profile['profile_revision'] : 0,
			'profile_digest' => ! empty( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
			'site_uuid' => ! empty( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
		);

		if ( $profile_enrolled && empty( $profile['ready'] ) ) {
			self::$status['blocker'] = ! empty( $profile['blockers'] ) ? (string) reset( $profile['blockers'] ) : 'site_profile_not_ready';
			return self::$status;
		}

		if ( 'production' === $environment ) return self::bootstrap_production_readonly( $profile );

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

		$selection = self::select_subject_user( $profile );
		$user_id = isset( $selection['user_id'] ) ? absint( $selection['user_id'] ) : 0;
		if ( $user_id < 1 ) {
			self::$status['blocker'] = isset( $selection['blocker'] ) ? sanitize_key( (string) $selection['blocker'] ) : 'staging_subject_unavailable';
			return self::$status;
		}

		$subject = 'user:' . $user_id;
		$record = array(
			'version' => self::VERSION,
			'wp_user_id' => $user_id,
			'issuer' => $issuer,
			'profile_revision' => ! empty( $profile['profile_revision'] ) ? (int) $profile['profile_revision'] : 0,
			'profile_digest' => ! empty( $profile['profile_digest'] ) ? (string) $profile['profile_digest'] : '',
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

	private static function bootstrap_production_readonly( array $profile = array() ) {
		$profile_enrolled = ! empty( self::$status['profile_enrolled'] );
		if ( $profile_enrolled ) {
			if ( empty( $profile['ready'] ) || empty( $profile['origin_match'] ) || empty( $profile['environment_match'] ) || 'production' !== (string) $profile['environment'] ) {
				self::$status['blocker'] = 'site_profile_production_origin_mismatch';
				return self::$status;
			}
		} elseif ( self::PRODUCTION_HOST !== self::home_host() ) {
			self::$status['blocker'] = 'origin_not_governed_production';
			return self::$status;
		}
		self::$status['eligible'] = true;

		$record = get_option( self::PRODUCTION_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record['enabled'] ) ) {
			self::$status['blocker'] = 'production_readonly_opt_in_required';
			return self::$status;
		}

		$user_id = $profile_enrolled && ! empty( $profile['subject_user_id'] ) ? absint( $profile['subject_user_id'] ) : ( isset( $record['wp_user_id'] ) ? absint( $record['wp_user_id'] ) : 0 );
		if ( ! self::subject_capable( $user_id ) ) {
			self::$status['blocker'] = 'production_readonly_user_invalid';
			return self::$status;
		}

		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) {
			self::$status['blocker'] = 'explicit_local_oauth_disabled';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) {
			self::$status['blocker'] = 'explicit_local_oauth_production_disabled';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) {
			self::$status['blocker'] = 'explicit_resource_oauth_production_disabled';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' !== sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) ) ) {
			self::$status['blocker'] = 'explicit_non_local_oauth_mode';
			return self::$status;
		}

		$issuer = self::local_issuer();
		if ( '' === $issuer ) {
			self::$status['blocker'] = 'local_issuer_unavailable';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) {
			$configured_issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
			if ( '' !== $configured_issuer && ! hash_equals( $issuer, $configured_issuer ) ) {
				self::$status['blocker'] = 'explicit_external_oauth_issuer';
				return self::$status;
			}
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) && absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) !== $user_id ) {
			self::$status['blocker'] = 'explicit_wp_user_conflict';
			return self::$status;
		}

		$subject = 'user:' . $user_id;
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) && ! in_array( $subject, self::normalize_subjects( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ), true ) ) {
			self::$status['blocker'] = 'explicit_subject_policy_conflict';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) && ! self::binding_allows( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ), $issuer, $subject ) ) {
			self::$status['blocker'] = 'explicit_subject_binding_conflict';
			return self::$status;
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) && ! self::user_mapping_allows( constant( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ), $issuer, $user_id ) ) {
			self::$status['blocker'] = 'explicit_wp_user_mapping_conflict';
			return self::$status;
		}

		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_MODE' ) ) define( 'MAD4B_MCP_OAUTH_MODE', 'local' );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $user_id );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', array( $subject ) );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS', array( $issuer => array( $subject ) ) );

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = $user_id;
		self::$status['configuration_source'] = $profile_enrolled ? 'site_profile_production_readonly_opt_in' : 'production_readonly_opt_in';
		self::$status['production_readonly_enabled'] = true;
		self::$status['blocker'] = '';
		return self::$status;
	}

	public static function status() {
		return self::$bootstrapped ? self::$status : self::bootstrap();
	}

	public static function production_profile_enabled() {
		$status = self::status();
		return 'production' === $status['environment'] && ! empty( $status['production_readonly_enabled'] );
	}

	private static function boot_admin_actions() {
		if ( self::$admin_actions_booted ) return;
		self::$admin_actions_booted = true;
		add_action( 'admin_post_mad4b_enable_production_readonly_oauth', array( __CLASS__, 'handle_enable_production_readonly' ) );
		add_action( 'admin_post_mad4b_disable_production_readonly_oauth', array( __CLASS__, 'handle_disable_production_readonly' ) );
	}

	public static function handle_enable_production_readonly() {
		self::assert_production_admin_action();
		check_admin_referer( 'mad4b_production_readonly_oauth' );
		$user_id = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::enrolled() ? MAD4B_SCP_Site_Profile::subject_user_id() : get_current_user_id();
		if ( ! self::subject_capable( $user_id ) ) wp_die( esc_html__( 'Configured connection subject capability is required.', 'mad4b-site-control-plane' ) );
		update_option(
			self::PRODUCTION_OPTION,
			array(
				'version' => self::VERSION,
				'enabled' => true,
				'wp_user_id' => $user_id,
				'profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
				'updated_at' => gmdate( 'c' ),
			),
			false
		);
		self::redirect_connection_page( 'enabled' );
	}

	public static function handle_disable_production_readonly() {
		self::assert_production_admin_action();
		check_admin_referer( 'mad4b_production_readonly_oauth' );
		delete_option( self::PRODUCTION_OPTION );
		self::redirect_connection_page( 'disabled' );
	}

	private static function assert_production_admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Administrator capability is required.', 'mad4b-site-control-plane' ) );
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::enrolled();
		if ( $profile_enrolled ) {
			$profile = MAD4B_SCP_Site_Profile::status();
			if ( 'production' !== $environment || empty( $profile['ready'] ) || empty( $profile['origin_match'] ) || 'production' !== (string) $profile['environment'] ) {
				wp_die( esc_html__( 'Production read-only OAuth can only be changed on the exact enrolled Production origin.', 'mad4b-site-control-plane' ) );
			}
			return;
		}
		if ( 'production' !== $environment || self::PRODUCTION_HOST !== self::home_host() ) {
			wp_die( esc_html__( 'Production read-only OAuth can only be changed on the exact governed Production origin.', 'mad4b-site-control-plane' ) );
		}
	}

	private static function redirect_connection_page( $state ) {
		$url = add_query_arg(
			array(
				'page' => 'mad4b-control-plane-chatgpt',
				'mad4b_production_readonly_oauth' => sanitize_key( (string) $state ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function select_subject_user( array $profile = array() ) {
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) {
			$user_id = absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) );
			return self::subject_capable( $user_id )
				? array( 'user_id' => $user_id, 'source' => 'explicit_wp_user' )
				: array( 'user_id' => 0, 'blocker' => 'explicit_wp_user_invalid' );
		}

		if ( ! empty( $profile['subject_user_id'] ) ) {
			$user_id = absint( $profile['subject_user_id'] );
			return self::subject_capable( $user_id )
				? array( 'user_id' => $user_id, 'source' => 'site_profile_subject' )
				: array( 'user_id' => 0, 'blocker' => 'site_profile_subject_invalid' );
		}

		$record = get_option( self::OPTION, array() );
		$stored_user_id = is_array( $record ) && isset( $record['wp_user_id'] ) ? absint( $record['wp_user_id'] ) : 0;
		if ( self::subject_capable( $stored_user_id ) ) return array( 'user_id' => $stored_user_id, 'source' => 'persisted_staging_auto' );

		$current_user_id = get_current_user_id();
		if ( self::subject_capable( $current_user_id ) ) return array( 'user_id' => $current_user_id, 'source' => 'current_staging_subject' );

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
		if ( 1 === count( $administrators ) && self::subject_capable( $administrators[0] ) ) {
			return array( 'user_id' => $administrators[0], 'source' => 'single_staging_admin' );
		}
		return array( 'user_id' => 0, 'blocker' => empty( $administrators ) ? 'staging_subject_unavailable' : 'staging_subject_ambiguous' );
	}

	private static function subject_capable( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return false;
		$user = get_userdata( $user_id );
		if ( ! $user ) return false;
		$capability = apply_filters( 'mad4b_scp_connection_subject_capability', 'manage_options', $user_id );
		return is_string( $capability ) && '' !== $capability && user_can( $user, $capability );
	}

	private static function local_issuer() {
		$url = untrailingslashit( home_url( '/oauth/mcp' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) return '';
		return $url;
	}

	private static function home_host() {
		$parts = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	private static function normalize_subjects( $value ) {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$subjects = array();
		foreach ( is_array( $items ) ? array_slice( $items, 0, 500 ) : array() as $item ) {
			if ( ! is_string( $item ) ) continue;
			$item = trim( $item );
			if ( '' !== $item ) $subjects[] = $item;
		}
		return array_values( array_unique( $subjects ) );
	}

	private static function binding_allows( $bindings, $issuer, $subject ) {
		if ( ! is_array( $bindings ) ) return false;
		foreach ( $bindings as $bound_issuer => $subjects ) {
			if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
			return in_array( $subject, self::normalize_subjects( $subjects ), true );
		}
		return false;
	}

	private static function user_mapping_allows( $mapping, $issuer, $user_id ) {
		if ( ! is_array( $mapping ) ) return false;
		foreach ( $mapping as $bound_issuer => $mapped_user_id ) {
			if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
			return absint( $mapped_user_id ) === absint( $user_id );
		}
		return false;
	}
}
