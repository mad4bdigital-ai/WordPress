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

	public static function boot_admin_actions_early() {
		self::boot_admin_actions();
	}

	public static function bootstrap() {
		self::boot_admin_actions();
		if ( self::$bootstrapped ) return self::$status;
		self::$bootstrapped = true;

		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
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

		if ( 'production' === $environment ) {
			// Production read-only keeps its explicit opt-in. Governed Production
			// write is already an explicit Site Profile authority and therefore may
			// bootstrap OAuth directly from that exact confirmed profile.
			if ( MAD4B_SCP_Site_Profile::write_enabled() ) return self::bootstrap_production_governed();
			return self::bootstrap_production_readonly();
		}
		return self::bootstrap_enrolled_nonproduction();
	}

	/**
	 * Project exact Site Profile OAuth identity into request-local constants only.
	 *
	 * This is the Tier-0 identity projection used before passive/zero-touch
	 * classification. It deliberately performs no option writes, schema work,
	 * provider discovery, key generation or outbound I/O.
	 */
	public static function project_request_identity() {
		$environment = class_exists( 'MAD4B_SCP_Environment' )
			? MAD4B_SCP_Environment::effective()
			: ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$result = array(
			'contract' => 'mad4b.oauth-request-identity-projection.v1',
			'projected' => false,
			'effective' => false,
			'source' => 'exact_site_profile',
			'environment' => sanitize_key( (string) $environment ),
			'issuer' => '',
			'wp_user_id' => 0,
			'oauth_user_ids' => array(),
			'blocker' => '',
			'request_local_only' => true,
			'durable_mutation_performed' => false,
			'outbound_io_performed' => false,
			'provider_discovery_performed' => false,
		);

		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ) {
			$result['blocker'] = 'site_profile_unconfigured';
			return $result;
		}
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
			$result['blocker'] = 'site_profile_not_enrolled';
			return $result;
		}
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) {
			$result['blocker'] = 'site_profile_oauth_disabled';
			return $result;
		}
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) {
			$result['blocker'] = 'environment_not_supported';
			return $result;
		}

		$production = 'production' === $environment;
		if ( $production && ! self::production_profile_enabled() ) {
			$result['blocker'] = 'production_readonly_opt_in_required';
			return $result;
		}
		if ( $production ) {
			if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_LOCAL_OAUTH_PRODUCTION_APPROVED' ) ) {
				$result['blocker'] = 'explicit_local_oauth_production_disabled';
				return $result;
			}
			if ( defined( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) && true !== constant( 'MAD4B_MCP_OAUTH_PRODUCTION_APPROVED' ) ) {
				$result['blocker'] = 'explicit_resource_oauth_production_disabled';
				return $result;
			}
		}

		$prepared = self::prepare_profile_subjects();
		if ( is_wp_error( $prepared ) ) {
			$result['blocker'] = sanitize_key( (string) $prepared->get_error_code() );
			return $result;
		}
		$configured = self::apply_local_oauth_configuration(
			$prepared['issuer'],
			$prepared['user_ids'],
			$prepared['owner_user_id'],
			$production
		);
		if ( is_wp_error( $configured ) ) {
			$result['blocker'] = sanitize_key( (string) $configured->get_error_code() );
			return $result;
		}

		$result['projected'] = true;
		$result['effective'] = true;
		$result['issuer'] = (string) $prepared['issuer'];
		$result['wp_user_id'] = (int) $prepared['owner_user_id'];
		$result['oauth_user_ids'] = array_values( array_map( 'absint', $prepared['user_ids'] ) );
		return $result;
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
		);
		$existing = get_option( self::OPTION, array() );
		$existing_semantic = is_array( $existing ) ? $existing : array();
		unset( $existing_semantic['updated_at'] );
		if ( $existing_semantic !== $record ) {
			$record['updated_at'] = gmdate( 'c' );
			update_option( self::OPTION, $record, false );
		} elseif ( is_array( $existing ) ) {
			$record = $existing;
		}

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['primary_owner_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['oauth_user_ids'] = $record['oauth_user_ids'];
		self::$status['configuration_source'] = 'site_profile';
		self::$status['blocker'] = '';
		return self::$status;
	}

	private static function bootstrap_production_governed() {
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
			'production_governed_write' => true,
		);
		$existing = get_option( self::OPTION, array() );
		$existing_semantic = is_array( $existing ) ? $existing : array();
		unset( $existing_semantic['updated_at'] );
		if ( $existing_semantic !== $record ) {
			$record['updated_at'] = gmdate( 'c' );
			update_option( self::OPTION, $record, false );
		} elseif ( is_array( $existing ) ) {
			$record = $existing;
		}

		self::$status['configured'] = true;
		self::$status['wp_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['primary_owner_user_id'] = (int) $prepared['owner_user_id'];
		self::$status['oauth_user_ids'] = $record['oauth_user_ids'];
		self::$status['configuration_source'] = 'site_profile_production_governed';
		self::$status['production_readonly_enabled'] = false;
		self::$status['write_authority_enabled'] = true;
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
		if ( defined( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ) {
			$configured_local_issuer = rtrim( trim( (string) constant( 'MAD4B_MCP_LOCAL_OAUTH_ISSUER' ) ), '/' );
			if ( '' !== $configured_local_issuer && ! hash_equals( $issuer, $configured_local_issuer ) ) return new WP_Error( 'explicit_local_oauth_issuer_conflict', 'Explicit local OAuth issuer conflicts with the enrolled Site Profile issuer.' );
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
		if ( defined( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' ) ) {
			$mapping = constant( 'MAD4B_MCP_OAUTH_WP_USER_BY_ISSUER' );
			$mapped_user = 0;
			if ( is_array( $mapping ) ) {
				foreach ( $mapping as $bound_issuer => $user_id ) {
					if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
					$mapped_user = absint( $user_id );
					break;
				}
			}
			if ( $mapped_user !== $primary_user_id ) return new WP_Error( 'explicit_wp_user_conflict', 'Per-issuer OAuth user mapping conflicts with the Site Profile trust owner.' );
		}

		if ( defined( 'MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS' ) ) {
			$raw = constant( 'MAD4B_MCP_OAUTH_ADVERTISED_ISSUERS' );
			$items = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
			$advertised = array();
			foreach ( is_array( $items ) ? array_slice( $items, 0, 32 ) : array() as $candidate ) {
				if ( ! is_string( $candidate ) ) continue;
				$candidate = rtrim( trim( $candidate ), '/' );
				if ( '' !== $candidate ) $advertised[] = $candidate;
			}
			if ( ! in_array( $issuer, array_values( array_unique( $advertised ) ), true ) ) return new WP_Error( 'explicit_advertised_issuer_conflict', 'Explicit advertised OAuth issuers omit the enrolled local issuer.' );
		}
		if ( defined( 'MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER' ) ) {
			$policies = constant( 'MAD4B_MCP_OAUTH_RESOURCE_POLICY_BY_ISSUER' );
			if ( is_array( $policies ) ) {
				foreach ( $policies as $bound_issuer => $resources ) {
					if ( ! is_string( $bound_issuer ) || ! hash_equals( $issuer, rtrim( trim( $bound_issuer ), '/' ) ) ) continue;
					$items = is_array( $resources ) ? $resources : preg_split( '/[\s,]+/', (string) $resources );
					$allowed = array();
					foreach ( is_array( $items ) ? array_slice( $items, 0, 16 ) : array() as $server_id ) {
						if ( ! is_string( $server_id ) ) continue;
						$server_id = sanitize_key( $server_id );
						if ( '' !== $server_id ) $allowed[] = $server_id;
					}
					if ( ! in_array( 'mad4b-chatgpt', array_values( array_unique( $allowed ) ), true ) ) return new WP_Error( 'explicit_resource_policy_conflict', 'Explicit OAuth resource policy excludes the ChatGPT MCP resource.' );
					break;
				}
			}
		}

		// Site Profile OAuth means the HTTP protected-resource bridge must be
		// available as well as the local authorization server. Honor an explicit
		// operator disable instead of silently overriding it.
		if ( defined( 'MAD4B_MCP_OAUTH_ENABLED' ) && true !== constant( 'MAD4B_MCP_OAUTH_ENABLED' ) ) {
			return new WP_Error( 'explicit_resource_oauth_disabled', 'OAuth protected-resource handling is explicitly disabled.' );
		}
		if ( ! defined( 'MAD4B_MCP_OAUTH_ENABLED' ) ) define( 'MAD4B_MCP_OAUTH_ENABLED', true );
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
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' )
			|| ! MAD4B_SCP_Site_Profile::configured()
			|| 'production' !== sanitize_key( (string) MAD4B_SCP_Site_Profile::current_environment() )
			|| ! MAD4B_SCP_Site_Profile::origin_enrolled()
			|| ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			|| ! MAD4B_SCP_Site_Profile::oauth_enabled() ) {
			return false;
		}

		// Governed Production write is itself an explicit Site Profile authority.
		// It may project Production OAuth without forcing a read-only opt-in.
		if ( MAD4B_SCP_Site_Profile::write_enabled() ) return true;

		// Read-only Production OAuth keeps its separate database opt-in. This
		// probe must remain side-effect free because it is used by early runtime
		// authority evaluation before pluggable user functions are guaranteed.
		$record = get_option( self::PRODUCTION_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record['enabled'] ) ) return false;

		$site_uuid = strtolower( trim( (string) MAD4B_SCP_Site_Profile::site_uuid() ) );
		$profile_revision = (int) MAD4B_SCP_Site_Profile::revision();
		$profile_digest = strtolower( trim( (string) MAD4B_SCP_Site_Profile::profile_digest() ) );
		$record_uuid = isset( $record['site_uuid'] ) ? strtolower( trim( (string) $record['site_uuid'] ) ) : '';
		$record_revision = isset( $record['profile_revision'] ) ? (int) $record['profile_revision'] : 0;
		$record_digest = isset( $record['profile_digest'] ) ? strtolower( trim( (string) $record['profile_digest'] ) ) : '';

		return '' !== $site_uuid
			&& '' !== $profile_digest
			&& '' !== $record_uuid
			&& '' !== $record_digest
			&& hash_equals( $site_uuid, $record_uuid )
			&& $profile_revision === $record_revision
			&& hash_equals( $profile_digest, $record_digest );
	}

	private static function boot_admin_actions() {
		if ( self::$admin_actions_booted ) return;
		self::$admin_actions_booted = true;
		add_action( 'admin_post_mad4b_enable_production_readonly_oauth', array( __CLASS__, 'handle_enable_production_readonly' ) );
		add_action( 'wp_ajax_mad4b_enable_production_readonly_oauth', array( __CLASS__, 'handle_enable_production_readonly' ) );
		add_action( 'admin_post_mad4b_disable_production_readonly_oauth', array( __CLASS__, 'handle_disable_production_readonly' ) );
		add_action( 'wp_ajax_mad4b_disable_production_readonly_oauth', array( __CLASS__, 'handle_disable_production_readonly' ) );
	}

	public static function handle_enable_production_readonly() {
		self::assert_production_admin_action();
		self::verify_production_admin_nonce();
		$record = array(
			'version' => self::VERSION,
			'enabled' => true,
			'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
			'profile_revision' => MAD4B_SCP_Site_Profile::revision(),
			'profile_digest' => MAD4B_SCP_Site_Profile::profile_digest(),
			'canonical_origin' => MAD4B_SCP_Site_Profile::site_origin(),
			'approved_by' => get_current_user_id(),
			'updated_at' => gmdate( 'c' ),
		);
		$verified = self::persist_production_option( $record );
		if ( self::is_ajax_request() ) {
			if ( ! $verified ) wp_send_json_error( array( 'code' => 'mad4b_production_readonly_oauth_readback_mismatch', 'message' => __( 'Production read-only OAuth setting could not be verified after save.', 'mad4b-site-control-plane' ) ), 500 );
			wp_send_json_success( array(
				'message' => __( 'Production read-only OAuth enabled and verified.', 'mad4b-site-control-plane' ),
				'persistence_verified' => true,
				'readback' => array( 'enabled' => true ),
			) );
		}
		if ( ! $verified ) wp_die( esc_html__( 'Production read-only OAuth setting could not be verified after save.', 'mad4b-site-control-plane' ) );
		self::redirect_connection_page( 'enabled' );
	}

	public static function handle_disable_production_readonly() {
		self::assert_production_admin_action();
		self::verify_production_admin_nonce();
		$verified = self::delete_production_option_verified();
		if ( self::is_ajax_request() ) {
			if ( ! $verified ) wp_send_json_error( array( 'code' => 'mad4b_production_readonly_oauth_delete_readback_mismatch', 'message' => __( 'Production read-only OAuth disable could not be verified.', 'mad4b-site-control-plane' ) ), 500 );
			wp_send_json_success( array(
				'message' => __( 'Production read-only OAuth disabled and verified.', 'mad4b-site-control-plane' ),
				'persistence_verified' => true,
				'readback' => array( 'enabled' => false ),
			) );
		}
		if ( ! $verified ) wp_die( esc_html__( 'Production read-only OAuth disable could not be verified.', 'mad4b-site-control-plane' ) );
		self::redirect_connection_page( 'disabled' );
	}

	private static function production_option_values_equal( $left, $right ) {
		return serialize( $left ) === serialize( $right );
	}

	private static function clear_production_option_cache( $aggressive = false ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) return;
		wp_cache_delete( self::PRODUCTION_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( $aggressive && function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'options' );
	}

	private static function persist_production_option( array $record ) {
		self::clear_production_option_cache( true );
		$current = get_option( self::PRODUCTION_OPTION, false );
		if ( false !== $current && self::production_option_values_equal( $current, $record ) ) return true;
		update_option( self::PRODUCTION_OPTION, $record, false );
		self::clear_production_option_cache();
		if ( self::production_option_values_equal( get_option( self::PRODUCTION_OPTION, false ), $record ) ) return true;
		self::clear_production_option_cache( true );
		update_option( self::PRODUCTION_OPTION, $record, false );
		self::clear_production_option_cache( true );
		return self::production_option_values_equal( get_option( self::PRODUCTION_OPTION, false ), $record );
	}

	private static function delete_production_option_verified() {
		self::clear_production_option_cache( true );
		if ( false === get_option( self::PRODUCTION_OPTION, false ) ) return true;
		delete_option( self::PRODUCTION_OPTION );
		self::clear_production_option_cache();
		if ( false === get_option( self::PRODUCTION_OPTION, false ) ) return true;
		self::clear_production_option_cache( true );
		delete_option( self::PRODUCTION_OPTION );
		self::clear_production_option_cache( true );
		return false === get_option( self::PRODUCTION_OPTION, false );
	}

	private static function is_ajax_request() {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	private static function verify_production_admin_nonce() {
		if ( self::is_ajax_request() ) {
			if ( false === check_ajax_referer( 'mad4b_production_readonly_oauth', '_wpnonce', false ) ) wp_send_json_error( array( 'code' => 'mad4b_production_readonly_oauth_nonce_invalid', 'message' => __( 'The Production read-only OAuth settings request expired.', 'mad4b-site-control-plane' ) ), 403 );
			return;
		}
		check_admin_referer( 'mad4b_production_readonly_oauth' );
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
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown' );
		$scheme = strtolower( (string) $parts['scheme'] );
		$host = strtolower( (string) $parts['host'] );
		$local_loopback = 'local' === $environment && 'http' === $scheme && in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
		if ( 'https' !== $scheme && ! $local_loopback ) return '';
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
