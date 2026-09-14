<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral local OAuth bootstrap.
 *
 * OAuth is identity/authentication only. Installation does not create a remote
 * identity. A local OAuth surface is configured only for an exact enrolled Site
 * Profile with the OAuth feature enabled. Production additionally requires a
 * separate human opt-in bound to the exact Site Profile revision/digest.
 *
 * Multiple WordPress users may be enrolled. One enrolled Administrator is
 * selected as the compatibility/trust owner required by the existing OAuth
 * resource bridge, while the allowlist contains every enrolled `user:<id>`
 * subject. After cryptographic verification the subject-user bridge remaps the
 * request to the exact delegated WordPress user, so the owner never becomes a
 * blanket execution identity for other subjects.
 */
final class MAD4B_SCP_Staging_OAuth_Autoconfig {
	const CONTRACT = 'mad4b.oauth-autoconfig.v3';
	const OPTION = 'mad4b_scp_staging_oauth_autoconfig_v1';
	const PRODUCTION_OPTION = 'mad4b_scp_production_readonly_oauth_v1';
	const VERSION = 3;

	private static $bootstrapped = false;
	private static $admin_actions_booted = false;
	private static $status = array();

	public static function bootstrap() {
		self::boot_admin_actions();
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		self::$status = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'environment' => $environment,
			'eligible' => false,
			'configured' => false,
			'wp_user_id' => 0,
			'primary_owner_user_id' => 0,
			'oauth_user_ids' => array(),
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
			'profile_revision' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::revision() : 0,
			'profile_digest' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::profile_digest() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::site_uuid() : '',
		);

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) {
			self::$status['blocker'] = 'site_profile_unconfigured';
			return self::$status;
		}
		if ( ! $profile_enrolled ) {
			self::$status['blocker'] = 'site_profile_not_enrolled';
			return self::$status;
		}
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) {
			self::$status['blocker'] = 'site_profile_oauth_disabled';
			return self::$status;
		}

		if ( ! in_array( $environment, array( 'staging', 'production', 'development', 'local' ), true ) ) {
			self::$status['blocker'] = 'environment_not_supported';
			return self::$status;
		}
		self::$status['eligible'] = true;

		if ( 'production' === $environment ) return self::bootstrap_production_readonly();
		return self::bootstrap_enrolled_nonproduction();
	}

	private static function bootstrap_enrolled_nonproduction() {
		$prepared = self::prepare_profile_subjects();
		if ( is_wp_error( $prepared ) ) {
			self::$status['blocker'] = $prepared->get_error_code();
			return self::$status;
		}
		$configured = self::apply_local_oauth_configuration( $prepared['issuer'], $prepared['user_ids'], $prepared['owner_user_id'] );
		if ( is_wp_error( $configured ) ) {
			self::$status['blocker'] = $configured->get_error_code();
			return self::$status;
		}

		$record = array(
			'version' => self::VERSION,
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'profile_revision' => MAD4B_SCP_Site_Profile::revision(),
			'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'environment' => MAD4B_SCP_Site_Profile::current_environment(),
			'wp_user_id' => (int) $prepared['owner_user_id'],
			'primary_owner_user_id' => (int) $prepared['owner_user_id'],
			'oauth_user_ids' => array_values( array_map( 'absint', $prepared['user_ids'] ) ),
			'issuer' => $prepared['issuer'],
			'updated_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION, $record, false );

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['primary_owner_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['oauth_user_ids'] = $record['oauth_user_ids'];
		self::$status['configuration_source'] = 'site_profile';
		self::$status['blocker'] = '';
		return self::$status;
	}

	private static function bootstrap_production_readonly() {
		$record = get_option( self::PRODUCTION_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record['enabled'] ) ) {
			self::$status['blocker'] = 'production_readonly_opt_in_required';
			return self::$status;
		}

		$site_uuid = MAD4B_SCP_Site_Profile::site_uuid();
		$revision = MAD4B_SCP_Site_Profile::revision();
		$digest = MAD4B_SCP_Site_Profile::profile_digest();
		$record_uuid = isset( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
		$record_revision = isset( $record['profile_revision'] ) ? absint( $record['profile_revision'] ) : 0;
		$record_digest = isset( $record['profile_digest'] ) ? strtolower( trim( (string) $record['profile_digest'] ) ) : '';
		if ( '' === $site_uuid || '' === $digest || ! hash_equals( $site_uuid, $record_uuid ) || $revision !== $record_revision || ! hash_equals( $digest, $record_digest ) ) {
			self::$status['blocker'] = 'production_readonly_profile_stale';
			return self::$status;
		}

		$prepared = self::prepare_profile_subjects();
		if ( is_wp_error( $prepared ) ) {
			self::$status['blocker'] = $prepared->get_error_code();
			return self::$status;
		}
		$configured = self::apply_local_oauth_configuration( $prepared['issuer'], $prepared['user_ids'], $prepared['owner_user_id'], true );
		if ( is_wp_error( $configured ) ) {
			self::$status['blocker'] = $configured->get_error_code();
			return self::$status;
		}

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['primary_owner_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['oauth_user_ids'] = array_values( array_map( 'absint', $prepared['user_ids'] ) );
		self::$status['configuration_source'] = 'site_profile_production_readonly_opt_in';
		self::$status['production_readonly_enabled'] = true;
		self::$status['blocker'] = '';
		return self::$status;
	}

	private static function prepare_profile_subjects() {
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) return new WP_Error( 'explicit_local_oauth_disabled', 'Local OAuth is explicitly disabled.' );
		if ( defined( 'MAD4B_MCP_OAUTH_MODE' ) && 'local' !== sanitize_key( (string) constant( 'MAD4B_MCP_OAUTH_MODE' ) ) ) return new WP_Error( 'explicit_non_local_oauth_mode', 'An explicit non-local OAuth mode is configured.' );

		$issuer = self::local_issuer();
		if ( '' === $issuer ) return new WP_Error( 'local_issuer_unavailable', 'The exact local OAuth issuer is unavailable.' );
		if ( defined( 'MAD4B_MCP_OAUTH_ISSUER' ) ) {
			$configured_issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_OAUTH_ISSUER' ) ), '/' );
			if ( '' !== $configured_issuer && ! hash_equals( $issuer, $configured_issuer ) ) return new WP_Error( 'explicit_external_oauth_issuer', 'Explicit OAuth issuer conflicts with the enrolled local issuer.' );
		}

		$user_ids = MAD4B_SCP_Site_Profile::oauth_user_ids();
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) return new WP_Error( 'site_profile_subject_unavailable', 'Site Profile has no OAuth users.' );
		foreach ( $user_ids as $user_id ) if ( ! self::subject_capable( $user_id ) ) return new WP_Error( 'site_profile_subject_invalid', 'One or more Site Profile OAuth users are not connection-capable.' );
		$owner_user_id = self::primary_owner_user_id( $user_ids );
		if ( $owner_user_id < 1 ) return new WP_Error( 'site_profile_admin_owner_required', 'Local OAuth requires at least one enrolled Administrator as the compatibility trust owner.' );
		return array( 'issuer' => $issuer, 'user_ids' => $user_ids, 'owner_user_id' => $owner_user_id );
	}

	private static function apply_local_oauth_configuration( $issuer, array $user_ids, $primary_user_id, $production = false ) {
		$subjects = array_values( array_map( static function ( $user_id ) { return 'user:' . absint( $user_id ); }, $user_ids ) );
		$primary_user_id = absint( $primary_user_id );
		if ( $primary_user_id < 1 || ! in_array( $primary_user_id, array_map( 'absint', $user_ids ), true ) ) return new WP_Error( 'site_profile_admin_owner_required', 'Primary OAuth owner must be one of the enrolled users.' );

		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) {
			$configured = self::normalize_subjects( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) );
			foreach ( $subjects as $subject ) if ( ! in_array( $subject, $configured, true ) ) return new WP_Error( 'explicit_subject_policy_conflict', 'Explicit OAuth subject policy omits an enrolled Site Profile user.' );
		}
		if ( defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) {
			foreach ( $subjects as $subject ) if ( ! self::binding_allows( constant( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ), $issuer, $subject ) ) return new WP_Error( 'explicit_subject_binding_conflict', 'Explicit issuer/subject binding omits an enrolled Site Profile user.' );
		}
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) && absint( constant( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) !== $primary_user_id ) return new WP_Error( 'explicit_wp_user_conflict', 'Legacy primary OAuth user conflicts with the Site Profile trust owner.' );

		if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_ENABLED', true );
		if ( ! defined( 'MAD4B_MCP_OAUTH_MODE' ) ) define( 'MAD4B_MCP_OAUTH_MODE', 'local' );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_ID' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_ID', $primary_user_id );
		if ( ! defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) ) define( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER', array( $issuer => $primary_user_id ) );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECTS', $subjects );
		if ( ! defined( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS' ) ) define( 'MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS', array( $issuer => $subjects ) );
		if ( $production ) {
			if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) return new WP_Error( 'explicit_local_oauth_production_disabled', 'Production local OAuth is explicitly disabled.' );
			if ( defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) return new WP_Error( 'explicit_resource_oauth_production_disabled', 'Production OAuth resource bridge is explicitly disabled.' );
			if ( ! defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED', true );
			if ( ! defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) define( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED', true );
		}
		return true;
	}

	public static function status() { return self::$bootstrapped ? self::$status : self::bootstrap(); }

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
		update_option( self::PRODUCTION_OPTION, array(
			'version' => self::VERSION,
			'enabled' => true,
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'profile_revision' => MAD4B_SCP_Site_Profile::revision(),
			'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'approved_by' => get_current_user_id(),
			'updated_at' => gmdate( 'c' ),
		), false );
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
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::origin_enrolled() || 'production' !== MAD4B_SCP_Site_Profile::current_environment() || ! MAD4B_SCP_Site_Profile::oauth_enabled() ) {
			wp_die( esc_html__( 'Production read-only OAuth can only be changed on the exact enrolled Production origin.', 'mad4b-site-control-plane' ) );
		}
	}

	private static function redirect_connection_page( $state ) {
		$url = add_query_arg( array( 'page' => 'mad4b-control-plane-chatgpt', 'mad4b_production_readonly_oauth' => sanitize_key( (string) $state ) ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function subject_capable( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return false;
		$user = get_userdata( $user_id );
		if ( ! $user ) return false;
		if ( class_exists( 'MAD4B_SCP_Policy' ) && method_exists( 'MAD4B_SCP_Policy', 'can_connect_user' ) ) return MAD4B_SCP_Policy::can_connect_user( $user_id );
		return user_can( $user, 'manage_options' );
	}

	private static function primary_owner_user_id( array $user_ids ) {
		foreach ( $user_ids as $user_id ) {
			$user_id = absint( $user_id );
			if ( $user_id < 1 ) continue;
			$user = get_userdata( $user_id );
			if ( $user && user_can( $user, 'manage_options' ) ) return $user_id;
		}
		return 0;
	}

	private static function local_issuer() {
		$url = untrailingslashit( home_url( '/oauth/mcp' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$environment = function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( 'local' === $environment && 'http' === $scheme ) ) return '';
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) return '';
		return $url;
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
}
