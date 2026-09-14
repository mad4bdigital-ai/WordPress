<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral site identity and governance enrollment.
 *
 * Installation never creates mutation authority for an unknown site. A site is
 * governed only after an explicit local profile exists or a reviewed legacy
 * preset matches the exact current origin. Profile identity is intentionally
 * separate from the Plugin build so the same binary can be deployed safely to
 * many independent WordPress sites.
 */
final class MAD4B_SCP_Site_Profile {
	const CONTRACT = 'mad4b.site-profile.v1';
	const PRESET_CONTRACT = 'mad4b.site-profile-presets.v1';
	const OPTION = 'mad4b_scp_site_profile_v1';
	const VERSION = 1;
	const PRESET_FILE = 'config/site-profile-presets.json';
	const PRODUCTION_WRITE_CONFIRMATION = 'ENABLE GOVERNED PRODUCTION WRITE';

	private static $profile = null;
	private static $status = null;
	private static $bootstrapping = false;

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 8 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		wp_register_ability( 'mad4b/site-profile-status', array(
			'label' => 'MAD4B Site Profile Status',
			'description' => 'Read the tenant-neutral site enrollment, origin binding and authority feature state.',
			'category' => 'mad4b-governance',
			'execute_callback' => array( __CLASS__, 'status' ),
			'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function bootstrap() {
		if ( null !== self::$status || self::$bootstrapping ) return self::status_without_bootstrap();
		self::$bootstrapping = true;

		$record = get_option( self::OPTION, array() );
		$source = 'stored';
		if ( ! self::valid_record( $record ) ) {
			$record = self::matching_preset();
			$source = ! empty( $record ) ? 'legacy_preset' : 'none';
			if ( ! empty( $record ) && self::valid_record( $record ) ) {
				update_option( self::OPTION, $record, false );
				$source = 'legacy_preset_migrated';
			}
		}

		self::$profile = self::valid_record( $record ) ? self::normalize_record( $record ) : array();
		self::$status = self::build_status( self::$profile, $source );
		self::$bootstrapping = false;
		return self::$status;
	}

	public static function status() {
		return null === self::$status ? self::bootstrap() : self::$status;
	}

	private static function status_without_bootstrap() {
		return is_array( self::$status ) ? self::$status : self::build_status( array(), 'none' );
	}

	public static function profile() {
		self::status();
		return is_array( self::$profile ) ? self::$profile : array();
	}

	public static function reset_cache() {
		self::$profile = null;
		self::$status = null;
	}

	public static function configured() {
		$status = self::status();
		return ! empty( $status['configured'] );
	}

	public static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	public static function current_origin() {
		if ( ! function_exists( 'home_url' ) ) return '';
		return self::normalize_origin( home_url( '/' ) );
	}

	public static function current_host() {
		$origin = self::current_origin();
		$parts = '' !== $origin && function_exists( 'wp_parse_url' ) ? wp_parse_url( $origin ) : parse_url( $origin );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	public static function site_origin() {
		$profile = self::profile();
		return isset( $profile['canonical_origin'] ) ? (string) $profile['canonical_origin'] : '';
	}

	public static function site_host() {
		$origin = self::site_origin();
		$parts = '' !== $origin && function_exists( 'wp_parse_url' ) ? wp_parse_url( $origin ) : parse_url( $origin );
		return is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( rtrim( (string) $parts['host'], '.' ) ) : '';
	}

	public static function site_uuid() {
		$profile = self::profile();
		return isset( $profile['site_uuid'] ) ? strtolower( (string) $profile['site_uuid'] ) : '';
	}

	public static function revision() {
		$profile = self::profile();
		return isset( $profile['revision'] ) ? max( 0, absint( $profile['revision'] ) ) : 0;
	}

	public static function profile_digest() {
		$profile = self::profile();
		if ( empty( $profile ) ) return '';
		$canonical = self::canonicalize( $profile );
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && '' !== $json ? hash( 'sha256', $json ) : '';
	}

	public static function origin_enrolled() {
		$status = self::status();
		return ! empty( $status['origin_match'] ) && ! empty( $status['environment_match'] );
	}

	public static function feature_enabled( $feature ) {
		$profile = self::profile();
		$feature = sanitize_key( (string) $feature );
		if ( ! self::origin_enrolled() || empty( $profile['features'] ) || ! is_array( $profile['features'] ) ) return false;
		if ( empty( $profile['features'][ $feature ] ) ) return false;
		if ( 'write' === $feature && 'production' === self::current_environment() && empty( $profile['features']['production_write_confirmed'] ) ) return false;
		return true;
	}

	public static function oauth_enabled() { return self::feature_enabled( 'oauth' ); }
	public static function skills_enabled() { return self::feature_enabled( 'skills' ); }
	public static function write_enabled() { return self::feature_enabled( 'write' ); }
	public static function acceptance_enabled() { return self::feature_enabled( 'acceptance' ); }
	public static function provider_isolation_enabled() { return self::feature_enabled( 'provider_isolation' ); }
	public static function managed_runtime_enabled() { return self::feature_enabled( 'managed_runtime' ); }

	public static function display_name() {
		$profile = self::profile();
		if ( ! empty( $profile['display_name'] ) ) return (string) $profile['display_name'];
		if ( function_exists( 'get_bloginfo' ) ) {
			$name = trim( (string) get_bloginfo( 'name' ) );
			if ( '' !== $name ) return $name;
		}
		return self::current_host();
	}

	public static function chatgpt_app_id() {
		$profile = self::profile();
		$value = isset( $profile['chatgpt_app_id'] ) ? trim( (string) $profile['chatgpt_app_id'] ) : '';
		return preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $value ) ? $value : '';
	}

	public static function oauth_user_ids() {
		$profile = self::profile();
		$users = isset( $profile['oauth_user_ids'] ) && is_array( $profile['oauth_user_ids'] ) ? $profile['oauth_user_ids'] : array();
		return array_values( array_unique( array_filter( array_map( 'absint', $users ) ) ) );
	}

	public static function user_is_enrolled( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) return false;
		$users = self::oauth_user_ids();
		if ( in_array( $user_id, $users, true ) ) return true;
		$profile = self::profile();
		return empty( $users ) && ! empty( $profile['legacy_zero_touch'] ) && ( $user = get_userdata( $user_id ) ) && user_can( $user, 'manage_options' );
	}

	public static function related_origin( $environment ) {
		$profile = self::profile();
		$environment = sanitize_key( (string) $environment );
		if ( self::current_environment() === $environment && self::origin_enrolled() ) return self::current_origin();
		$related = isset( $profile['related_origins'] ) && is_array( $profile['related_origins'] ) ? $profile['related_origins'] : array();
		return isset( $related[ $environment ] ) ? self::normalize_origin( $related[ $environment ] ) : '';
	}

	public static function governed_runtime_ready() {
		return self::origin_enrolled() && self::oauth_enabled();
	}

	public static function governed_write_ready() {
		return self::origin_enrolled() && self::write_enabled();
	}

	public static function agent_slug() {
		$profile = self::profile();
		if ( ! empty( $profile['legacy_agent_slug'] ) ) return sanitize_key( (string) $profile['legacy_agent_slug'] );
		return 'chatgpt-governed-write';
	}

	public static function save_current_site( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_admin_required', 'Administrator capability is required to enroll this site.' );
		$environment = self::current_environment();
		$origin = self::current_origin();
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) return new WP_Error( 'mad4b_site_profile_environment_invalid', 'WordPress environment type is not supported for site enrollment.' );
		if ( '' === $origin ) return new WP_Error( 'mad4b_site_profile_origin_invalid', 'A canonical WordPress home origin is required for site enrollment.' );
		if ( 'local' !== $environment && 'https' !== strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_site_profile_https_required', 'Non-local governed sites require HTTPS.' );

		$existing = get_option( self::OPTION, array() );
		$current_revision = is_array( $existing ) && self::valid_record( $existing ) && isset( $existing['revision'] ) ? absint( $existing['revision'] ) : 0;
		$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : $current_revision;
		if ( $expected_revision !== $current_revision ) return new WP_Error( 'mad4b_site_profile_stale', 'Site profile changed since this form was loaded. Reload before saving.' );
		$site_uuid = is_array( $existing ) && ! empty( $existing['site_uuid'] ) && self::valid_uuid( $existing['site_uuid'] ) ? strtolower( (string) $existing['site_uuid'] ) : wp_generate_uuid4();
		$revision = $current_revision + 1;
		$app_id = isset( $input['chatgpt_app_id'] ) ? trim( sanitize_text_field( (string) $input['chatgpt_app_id'] ) ) : '';
		if ( '' !== $app_id && ! preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $app_id ) ) return new WP_Error( 'mad4b_site_profile_app_id_invalid', 'ChatGPT App ID is invalid.' );

		$user_ids = isset( $input['oauth_user_ids'] ) ? self::normalize_user_ids( $input['oauth_user_ids'] ) : array();
		if ( empty( $user_ids ) ) $user_ids = array( get_current_user_id() );
		foreach ( $user_ids as $user_id ) if ( ! get_userdata( $user_id ) ) return new WP_Error( 'mad4b_site_profile_user_invalid', 'Every enrolled OAuth user must exist on this WordPress site.' );

		$write = ! empty( $input['write_enabled'] );
		$production_confirmed = ! empty( $input['production_write_confirmed'] );
		$production_confirmation = isset( $input['production_write_confirmation'] ) ? trim( sanitize_text_field( (string) $input['production_write_confirmation'] ) ) : '';
		if ( 'production' === $environment && $write ) {
			if ( ! $production_confirmed || ! hash_equals( self::PRODUCTION_WRITE_CONFIRMATION, $production_confirmation ) ) {
				return new WP_Error( 'mad4b_site_profile_production_write_confirmation_required', 'Production governed write requires the exact typed confirmation phrase.' );
			}
		}
		if ( $write && ( ! class_exists( 'MAD4B_SCP_Audit' ) || empty( MAD4B_SCP_Audit::storage_status()['ready'] ) ) ) {
			return new WP_Error( 'mad4b_site_profile_audit_required', 'Governed write enrollment requires ready append-only audit storage.' );
		}

		$related = array();
		foreach ( array( 'development', 'staging', 'production' ) as $key ) {
			$raw = isset( $input[ $key . '_origin' ] ) ? self::normalize_origin( $input[ $key . '_origin' ] ) : '';
			if ( '' !== $raw ) $related[ $key ] = $raw;
		}
		$related[ $environment ] = $origin;

		$record = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'site_uuid' => $site_uuid,
			'revision' => $revision,
			'environment' => $environment,
			'canonical_origin' => $origin,
			'display_name' => isset( $input['display_name'] ) ? substr( sanitize_text_field( (string) $input['display_name'] ), 0, 191 ) : self::display_name(),
			'chatgpt_app_id' => $app_id,
			'oauth_user_ids' => $user_ids,
			'related_origins' => $related,
			'features' => array(
				'oauth' => ! empty( $input['oauth_enabled'] ),
				'skills' => ! empty( $input['skills_enabled'] ),
				'write' => $write,
				'production_write_confirmed' => $production_confirmed,
				'provider_isolation' => ! empty( $input['provider_isolation_enabled'] ),
				'managed_runtime' => ! empty( $input['managed_runtime_enabled'] ),
				'acceptance' => ! empty( $input['acceptance_enabled'] ),
			),
			'legacy_agent_slug' => '',
			'legacy_zero_touch' => false,
			'created_at' => is_array( $existing ) && ! empty( $existing['created_at'] ) ? (string) $existing['created_at'] : gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		$before_digest = is_array( $existing ) && self::valid_record( $existing ) ? self::digest_record( self::normalize_record( $existing ) ) : '';
		if ( false === update_option( self::OPTION, $record, false ) ) return new WP_Error( 'mad4b_site_profile_save_failed', 'Site profile could not be persisted.' );
		self::reset_cache();
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/site-profile-updated', array(
				'site_uuid' => $site_uuid,
				'previous_revision' => $current_revision,
				'revision' => $revision,
				'previous_profile_digest' => $before_digest,
				'profile_digest' => self::profile_digest(),
				'environment' => $environment,
				'canonical_origin' => $origin,
				'write_enabled' => self::write_enabled(),
				'oauth_user_count' => count( $user_ids ),
			), 'ok' );
			if ( is_wp_error( $audit ) ) {
				if ( $current_revision > 0 && is_array( $existing ) ) update_option( self::OPTION, $existing, false );
				else delete_option( self::OPTION );
				self::reset_cache();
				return new WP_Error( 'mad4b_site_profile_audit_failed', 'Site profile change was rolled back because append-only audit evidence could not be recorded.', array( 'audit_error' => $audit->get_error_code() ) );
			}
		}
		return self::status();
	}

	public static function disable_authority( $expected_revision = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_admin_required', 'Administrator capability is required to change site authority.' );
		$profile = self::profile();
		if ( empty( $profile ) ) return self::status();
		$current_revision = self::revision();
		if ( null !== $expected_revision && absint( $expected_revision ) !== $current_revision ) return new WP_Error( 'mad4b_site_profile_stale', 'Site profile changed since this form was loaded. Reload before disabling authority.' );
		$profile['revision'] = $current_revision + 1;
		if ( ! isset( $profile['features'] ) || ! is_array( $profile['features'] ) ) $profile['features'] = array();
		$profile['features']['write'] = false;
		$profile['features']['production_write_confirmed'] = false;
		$profile['updated_at'] = gmdate( 'c' );
		if ( false === update_option( self::OPTION, $profile, false ) ) return new WP_Error( 'mad4b_site_profile_save_failed', 'Site profile authority state could not be persisted.' );
		self::reset_cache();
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/site-profile-write-disabled', array(
				'site_uuid' => self::site_uuid(),
				'previous_revision' => $current_revision,
				'revision' => self::revision(),
				'profile_digest' => self::profile_digest(),
			), 'ok' );
		}
		return self::status();
	}

	public static function validate_record_for_test( array $record, $environment, $origin ) {
		$record = self::normalize_record( $record );
		if ( ! self::valid_record( $record ) ) return false;
		return sanitize_key( (string) $environment ) === (string) $record['environment']
			&& self::normalize_origin( $origin ) === (string) $record['canonical_origin'];
	}

	private static function build_status( array $profile, $source ) {
		$environment = self::current_environment();
		$origin = self::current_origin();
		$configured = self::valid_record( $profile );
		$environment_match = $configured && hash_equals( (string) $profile['environment'], $environment );
		$origin_match = $configured && '' !== $origin && hash_equals( (string) $profile['canonical_origin'], $origin );
		$blockers = array();
		if ( ! $configured ) $blockers[] = 'site_profile_unconfigured';
		if ( $configured && ! $environment_match ) $blockers[] = 'site_profile_environment_drift';
		if ( $configured && ! $origin_match ) $blockers[] = 'site_profile_origin_drift';
		return array(
			'contract' => self::CONTRACT,
			'configured' => $configured,
			'source' => sanitize_key( (string) $source ),
			'site_uuid' => $configured ? (string) $profile['site_uuid'] : '',
			'revision' => $configured ? absint( $profile['revision'] ) : 0,
			'profile_digest' => $configured ? self::digest_record( $profile ) : '',
			'environment' => $environment,
			'configured_environment' => $configured ? (string) $profile['environment'] : '',
			'current_origin' => $origin,
			'canonical_origin' => $configured ? (string) $profile['canonical_origin'] : '',
			'environment_match' => $environment_match,
			'origin_match' => $origin_match,
			'write_enabled' => $configured && $environment_match && $origin_match && self::record_feature_enabled( $profile, 'write', $environment ),
			'oauth_enabled' => $configured && $environment_match && $origin_match && self::record_feature_enabled( $profile, 'oauth', $environment ),
			'skills_enabled' => $configured && $environment_match && $origin_match && self::record_feature_enabled( $profile, 'skills', $environment ),
			'blockers' => $blockers,
		);
	}

	private static function digest_record( array $record ) {
		$json = wp_json_encode( self::canonicalize( $record ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) && '' !== $json ? hash( 'sha256', $json ) : '';
	}

	private static function record_feature_enabled( array $profile, $feature, $environment ) {
		if ( empty( $profile['features'] ) || ! is_array( $profile['features'] ) || empty( $profile['features'][ $feature ] ) ) return false;
		if ( 'write' === $feature && 'production' === $environment && empty( $profile['features']['production_write_confirmed'] ) ) return false;
		return true;
	}

	private static function matching_preset() {
		$path = defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . self::PRESET_FILE : '';
		if ( '' === $path || ! is_readable( $path ) ) return array();
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || self::PRESET_CONTRACT !== ( isset( $decoded['contract'] ) ? (string) $decoded['contract'] : '' ) || empty( $decoded['profiles'] ) || ! is_array( $decoded['profiles'] ) ) return array();
		$environment = self::current_environment();
		$origin = self::current_origin();
		foreach ( $decoded['profiles'] as $preset ) {
			if ( ! is_array( $preset ) ) continue;
			$preset = self::normalize_record( $preset );
			if ( ! self::valid_record( $preset ) ) continue;
			if ( ! hash_equals( (string) $preset['environment'], $environment ) || ! hash_equals( (string) $preset['canonical_origin'], $origin ) ) continue;
			return $preset;
		}
		return array();
	}

	private static function valid_record( $record ) {
		if ( ! is_array( $record ) ) return false;
		if ( self::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( self::VERSION !== absint( isset( $record['version'] ) ? $record['version'] : 0 ) ) return false;
		if ( ! self::valid_uuid( isset( $record['site_uuid'] ) ? $record['site_uuid'] : '' ) ) return false;
		if ( absint( isset( $record['revision'] ) ? $record['revision'] : 0 ) < 1 ) return false;
		$environment = sanitize_key( isset( $record['environment'] ) ? (string) $record['environment'] : '' );
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) return false;
		return '' !== self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
	}

	private static function normalize_record( array $record ) {
		$record['contract'] = isset( $record['contract'] ) ? (string) $record['contract'] : '';
		$record['version'] = absint( isset( $record['version'] ) ? $record['version'] : 0 );
		$record['site_uuid'] = strtolower( trim( isset( $record['site_uuid'] ) ? (string) $record['site_uuid'] : '' ) );
		$record['revision'] = max( 1, absint( isset( $record['revision'] ) ? $record['revision'] : 1 ) );
		$record['environment'] = sanitize_key( isset( $record['environment'] ) ? (string) $record['environment'] : '' );
		$record['canonical_origin'] = self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
		$record['display_name'] = isset( $record['display_name'] ) ? substr( sanitize_text_field( (string) $record['display_name'] ), 0, 191 ) : '';
		$record['chatgpt_app_id'] = isset( $record['chatgpt_app_id'] ) ? trim( (string) $record['chatgpt_app_id'] ) : '';
		$record['oauth_user_ids'] = self::normalize_user_ids( isset( $record['oauth_user_ids'] ) ? $record['oauth_user_ids'] : array() );
		$record['related_origins'] = isset( $record['related_origins'] ) && is_array( $record['related_origins'] ) ? array_filter( array_map( array( __CLASS__, 'normalize_origin' ), $record['related_origins'] ) ) : array();
		$record['features'] = isset( $record['features'] ) && is_array( $record['features'] ) ? array_map( 'boolval', $record['features'] ) : array();
		$record['legacy_agent_slug'] = isset( $record['legacy_agent_slug'] ) ? sanitize_key( (string) $record['legacy_agent_slug'] ) : '';
		$record['legacy_zero_touch'] = ! empty( $record['legacy_zero_touch'] );
		return $record;
	}

	private static function normalize_user_ids( $value ) {
		if ( is_string( $value ) ) $value = preg_split( '/[\s,]+/', $value );
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
	}

	private static function normalize_origin( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) return '';
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) return '';
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host ) return '';
		$origin = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) $origin .= ':' . absint( $parts['port'] );
		$path = isset( $parts['path'] ) ? '/' . ltrim( (string) $parts['path'], '/' ) : '';
		$path = '/' === $path ? '' : rtrim( $path, '/' );
		if ( '' !== $path ) $origin .= $path;
		return $origin;
	}

	private static function valid_uuid( $value ) {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', trim( (string) $value ) );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) return array_map( array( __CLASS__, 'canonicalize' ), $value );
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}
