<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tenant-neutral site identity and governance enrollment.
 *
 * Installation never creates mutation authority for an unknown site. A site is
 * governed only after an explicit local profile exists. Optional legacy preset
 * migration is disabled by default and must be explicitly enabled by the host.
 * Profile identity is intentionally
 * separate from the Plugin build so the same binary can be deployed safely to
 * many independent WordPress sites.
 */
final class MAD4B_SCP_Site_Profile {
	const CONTRACT = 'mad4b.site-profile.v2';
	const LEGACY_CONTRACT = 'mad4b.site-profile.v1';
	const PRESET_CONTRACT = 'mad4b.site-profile-presets.v2';
	const OPTION = 'mad4b_scp_site_profile_v2';
	const LEGACY_OPTION = 'mad4b_scp_site_profile_v1';
	const VERSION = 2;
	const LEGACY_VERSION = 1;
	const PRESET_FILE = 'config/site-profile-presets.json';
	const PRODUCTION_WRITE_CONFIRMATION = 'ENABLE GOVERNED PRODUCTION WRITE';
	const NONPRODUCTION_OVERRIDE_CONFIRMATION = 'CONFIRM THIS ORIGIN IS NON-PRODUCTION';

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
		$record = get_option( self::OPTION, null );
		$source = 'stored';
		$has_current_record = null !== $record && false !== $record;
		if ( ! self::valid_record( $record ) ) {
			if ( $has_current_record ) {
				$record = array();
				$source = 'stored_invalid';
			} else {
				$legacy = get_option( self::LEGACY_OPTION, array() );
				$migrated = self::migrate_legacy_record( $legacy );
				if ( ! empty( $migrated ) && self::valid_record( $migrated ) && false !== update_option( self::OPTION, $migrated, false ) ) {
					$record = $migrated;
					$source = 'legacy_v1_migrated';
				} else {
					$record = self::matching_preset();
					$source = ! empty( $record ) ? 'preset' : 'none';
					if ( ! empty( $record ) && self::valid_record( $record ) && false !== update_option( self::OPTION, $record, false ) ) $source = 'preset_migrated';
				}
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

	public static function legacy_preset_migration_enabled() {
		$enabled = defined( 'MAD4B_SCP_ENABLE_LEGACY_SITE_PROFILE_PRESETS' )
			? (bool) constant( 'MAD4B_SCP_ENABLE_LEGACY_SITE_PROFILE_PRESETS' )
			: false;
		return function_exists( 'apply_filters' )
			? (bool) apply_filters( 'mad4b_scp_enable_legacy_site_profile_presets', $enabled )
			: $enabled;
	}

	public static function wordpress_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	public static function wordpress_environment_explicit() {
		$explicit = defined( 'WP_ENVIRONMENT_TYPE' );
		if ( ! $explicit && function_exists( 'getenv' ) ) {
			$raw = getenv( 'WP_ENVIRONMENT_TYPE' );
			$raw = false === $raw ? '' : sanitize_key( (string) $raw );
			$explicit = in_array( $raw, array( 'local', 'development', 'staging', 'production' ), true );
		}
		// Explicit host configuration is a one-way safety fact. Filters may harden
		// an implicit environment into an explicit one, but must never downgrade a
		// real WP_ENVIRONMENT_TYPE constant/environment variable and thereby let an
		// exact Site Profile override an explicitly configured Production runtime.
		if ( $explicit ) return true;
		return function_exists( 'apply_filters' )
			? (bool) apply_filters( 'mad4b_scp_wordpress_environment_explicit', false, self::wordpress_environment() )
			: false;
	}

	/**
	 * MAD4B effective environment.
	 *
	 * WordPress defaults to "production" when WP_ENVIRONMENT_TYPE is absent.
	 * An exact-origin Site Profile may replace only that implicit default. An
	 * explicitly configured WordPress environment remains authoritative and any
	 * disagreement fails closed. Copied/foreign profiles never influence it.
	 */
	/** Optional host identity outside the database; only its SHA-256 persists. */
	public static function deployment_binding_digest() {
		$value = '';
		if ( defined( 'MAD4B_SCP_DEPLOYMENT_BINDING' ) ) {
			$candidate = constant( 'MAD4B_SCP_DEPLOYMENT_BINDING' );
			if ( is_string( $candidate ) ) $value = trim( $candidate );
		}
		if ( '' === $value && function_exists( 'apply_filters' ) ) {
			$candidate = apply_filters( 'mad4b_scp_deployment_binding', '' );
			if ( is_string( $candidate ) ) $value = trim( $candidate );
		}
		if ( '' === $value || strlen( $value ) > 1024 ) return '';
		return hash( 'sha256', $value );
	}

	private static function record_deployment_binding_matches( array $profile ) {
		$stored = isset( $profile['deployment_binding_digest'] ) && is_string( $profile['deployment_binding_digest'] )
			? strtolower( trim( $profile['deployment_binding_digest'] ) )
			: '';
		// Backward compatibility: profiles created before deployment binding remain
		// usable until their next successful save, when the current host binds them.
		if ( '' === $stored ) return true;
		$current = self::deployment_binding_digest();
		return '' !== $current && hash_equals( $stored, $current );
	}

	/** Cheap MU-routing proof; not an authorization credential. */
	public static function diagnostic_mu_proof() {
		$profile = self::exact_stored_profile();
		if ( empty( $profile['site_uuid'] ) || empty( $profile['revision'] ) ) return '';
		$secret = '';
		foreach ( array( 'NONCE_SALT', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && strlen( constant( $constant ) ) >= 32 ) {
				$secret = (string) constant( $constant );
				break;
			}
		}
		if ( '' === $secret ) return '';
		$material = "mad4b-mcp-diagnostic-mu-v1\0" . (string) $profile['site_uuid'] . "\0" . (string) absint( $profile['revision'] );
		return hash_hmac( 'sha256', $material, $secret );
	}

	public static function current_environment() {
		$wordpress = self::wordpress_environment();
		$bound = self::exact_stored_profile();
		if ( empty( $bound['environment'] ) ) return $wordpress;
		$profile_environment = sanitize_key( (string) $bound['environment'] );
		if ( hash_equals( $profile_environment, $wordpress ) ) return $profile_environment;
		if ( 'production' === $wordpress && ! self::wordpress_environment_explicit()
			&& ! empty( $bound['implicit_production_override_confirmed'] ) ) return $profile_environment;
		return $wordpress;
	}

	/** Pure MU-phase binding: no profile migration, persistence or runtime boot. */
	public static function early_managed_runtime_binding() {
		$profile = self::exact_stored_profile();
		$environment = self::current_environment();
		$wordpress = self::wordpress_environment();
		$implicit_override = ! empty( $profile )
			&& 'production' === $wordpress
			&& ! self::wordpress_environment_explicit()
			&& 'production' !== (string) $profile['environment'];
		$override_ready = ! $implicit_override || ! empty( $profile['implicit_production_override_confirmed'] );
		return array(
			'eligible' => ! empty( $profile )
				&& empty( $profile['mutation_state'] )
				&& $override_ready
				&& empty( $profile['migration_requires_reenrollment'] )
				&& hash_equals( (string) $profile['environment'], $environment )
				&& in_array( $environment, array( 'local', 'development', 'staging' ), true )
				&& ! empty( $profile['features']['managed_runtime'] ),
			'environment' => $environment,
			'wordpress_environment' => $wordpress,
			'implicit_nonproduction_override' => $implicit_override,
			'implicit_nonproduction_override_confirmed' => $override_ready && $implicit_override,
			'wordpress_environment_explicit' => self::wordpress_environment_explicit(),
			'site_uuid' => ! empty( $profile['site_uuid'] ) ? (string) $profile['site_uuid'] : '',
			'revision' => ! empty( $profile['revision'] ) ? absint( $profile['revision'] ) : 0,
			'deployment_binding_configured' => '' !== self::deployment_binding_digest(),
		);
	}

	/**
	 * Advisory enrollment default only. Hostname hints never grant authority.
	 */
	public static function suggested_environment() {
		$wordpress = self::wordpress_environment();
		$bound = self::exact_stored_profile();
		if ( ! empty( $bound['environment'] ) ) {
			$profile_environment = sanitize_key( (string) $bound['environment'] );
			if ( hash_equals( $profile_environment, $wordpress ) ) return $profile_environment;
			if ( 'production' === $wordpress && ! self::wordpress_environment_explicit() ) return $profile_environment;
			return $wordpress;
		}

		if ( in_array( $wordpress, array( 'local', 'development', 'staging' ), true ) ) return $wordpress;
		if ( 'production' !== $wordpress || self::wordpress_environment_explicit() ) return $wordpress;

		$host = self::current_host();
		if ( '' === $host ) return $wordpress;
		$parts = explode( '.', $host );
		$label = sanitize_key( isset( $parts[0] ) ? (string) $parts[0] : '' );
		$hints = array(
			'local' => 'local',
			'dev' => 'development',
			'development' => 'development',
			'stage' => 'staging',
			'staging' => 'staging',
		);
		return isset( $hints[ $label ] ) ? $hints[ $label ] : $wordpress;
	}

	public static function environment_resolution() {
		$wordpress = self::wordpress_environment();
		$wordpress_explicit = self::wordpress_environment_explicit();
		$bound = self::exact_stored_profile();
		$profile_environment = ! empty( $bound['environment'] ) ? sanitize_key( (string) $bound['environment'] ) : '';
		$profile_matches_wordpress = '' !== $profile_environment && hash_equals( $profile_environment, $wordpress );
		$profile_default_override_requested = '' !== $profile_environment && ! $profile_matches_wordpress && 'production' === $wordpress && ! $wordpress_explicit;
		$profile_default_override = $profile_default_override_requested && ! empty( $bound['implicit_production_override_confirmed'] );
		$profile_authoritative = $profile_matches_wordpress || $profile_default_override;
		$effective = $profile_authoritative ? $profile_environment : $wordpress;
		$source = $profile_default_override
			? 'exact_site_profile_default_override'
			: ( $profile_matches_wordpress ? 'exact_site_profile' : ( $wordpress_explicit ? 'wordpress_explicit' : 'wordpress_default' ) );
		return array(
			'contract' => 'mad4b.site-profile-environment-resolution.v1',
			'wordpress_environment' => $wordpress,
			'wordpress_environment_explicit' => $wordpress_explicit,
			'profile_environment' => $profile_environment,
			'exact_profile_bound' => '' !== $profile_environment,
			'profile_environment_authoritative' => $profile_authoritative,
			'profile_default_override_requested' => $profile_default_override_requested,
			'profile_default_override_confirmed' => $profile_default_override,
			'effective_environment' => $effective,
			'effective_source' => $source,
			'suggested_environment' => self::suggested_environment(),
			'wordpress_profile_mismatch' => '' !== $profile_environment && ! $profile_matches_wordpress,
			'hostname_hint_used_for_authority' => false,
		);
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
		return ! empty( $status['authority_ready'] );
	}

	public static function site_urls_match_enrollment() {
		if ( ! self::origin_enrolled() ) return false;
		$expected = self::site_origin();
		if ( '' === $expected ) return false;
		$home = function_exists( 'home_url' ) ? self::normalize_origin( home_url( '/' ) ) : '';
		$site = function_exists( 'site_url' ) ? self::normalize_origin( site_url( '/' ) ) : $home;
		return '' !== $home && '' !== $site && hash_equals( $expected, $home ) && hash_equals( $expected, $site );
	}

	public static function environment_allowed( array $environments, $feature = '' ) {
		if ( ! self::origin_enrolled() ) return false;
		$allowed = array_values( array_unique( array_filter( array_map( 'sanitize_key', $environments ) ) ) );
		if ( ! in_array( self::current_environment(), $allowed, true ) ) return false;
		$feature = sanitize_key( (string) $feature );
		return '' === $feature ? true : self::feature_enabled( $feature );
	}

	public static function nonproduction_governed( $feature = '' ) {
		return self::environment_allowed( array( 'local', 'development', 'staging' ), $feature );
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

	private static function option_values_equal( $left, $right ) {
		return serialize( $left ) === serialize( $right );
	}

	private static function clear_option_read_cache( $aggressive = false ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) return;
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( $aggressive && function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'options' );
	}

	public static function persist_record_exact( array $record ) {
		if ( ! self::valid_record( $record ) ) return false;

		self::clear_option_read_cache( true );
		$current = get_option( self::OPTION, false );
		if ( false !== $current && self::option_values_equal( $current, $record ) ) {
			self::reset_cache();
			return true;
		}

		update_option( self::OPTION, $record, false );

		self::clear_option_read_cache();
		$readback = get_option( self::OPTION, false );
		if ( self::option_values_equal( $readback, $record ) ) {
			self::reset_cache();
			return true;
		}

		// Persistent object-cache drop-ins may retain stale option existence or
		// value state. Re-resolve after flushing only the Options cache group,
		// retry through the public Options API, then require exact readback.
		self::clear_option_read_cache( true );
		$current = get_option( self::OPTION, false );
		update_option( self::OPTION, $record, false );

		self::clear_option_read_cache( true );
		$readback = get_option( self::OPTION, false );
		$verified = self::option_values_equal( $readback, $record );
		if ( $verified ) self::reset_cache();
		return $verified;
	}


	/**
	 * Atomically replace the Site Profile only when the database row still
	 * contains the exact record this worker reviewed. WordPress always provides
	 * $wpdb in production; the Options-API fallback exists only for isolated
	 * contract fixtures that do not bootstrap a database.
	 */
	private static function persist_record_compare_and_swap( $expected, array $record ) {
		if ( ! self::valid_record( $record ) ) return false;
		self::clear_option_read_cache( true );
		global $wpdb;
		$database_cas = is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'query' )
			&& function_exists( 'maybe_serialize' );
		if ( $database_cas ) {
			if ( null === $expected ) {
				if ( ! add_option( self::OPTION, $record, '', false ) ) return false;
			} else {
				$sql = $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
					maybe_serialize( $record ),
					self::OPTION,
					maybe_serialize( $expected )
				);
				$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
				if ( 1 !== (int) $changed ) return false;
			}
		} else {
			$current = get_option( self::OPTION, null );
			if ( ! self::option_values_equal( $current, $expected ) ) return false;
			if ( null === $expected ) {
				if ( function_exists( 'add_option' ) ) {
					if ( ! add_option( self::OPTION, $record, '', false ) ) return false;
				} elseif ( false === update_option( self::OPTION, $record, false ) ) return false;
			} elseif ( false === update_option( self::OPTION, $record, false ) ) return false;
		}
		self::clear_option_read_cache( true );
		$readback = get_option( self::OPTION, null );
		$verified = self::option_values_equal( $readback, $record );
		if ( $verified ) self::reset_cache();
		return $verified;
	}

	private static function restore_record_compare_and_swap( array $expected_current, $previous ) {
		self::clear_option_read_cache( true );
		global $wpdb;
		$database_cas = is_object( $wpdb )
			&& isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'query' )
			&& function_exists( 'maybe_serialize' );
		if ( $database_cas ) {
			if ( null === $previous ) {
				$sql = $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
					self::OPTION,
					maybe_serialize( $expected_current )
				);
			} else {
				$sql = $wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
					maybe_serialize( $previous ),
					self::OPTION,
					maybe_serialize( $expected_current )
				);
			}
			$changed = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 !== (int) $changed ) return false;
		} else {
			$current = get_option( self::OPTION, null );
			if ( ! self::option_values_equal( $current, $expected_current ) ) return false;
			if ( null === $previous ) {
				if ( false === delete_option( self::OPTION ) ) return false;
			} elseif ( false === update_option( self::OPTION, $previous, false ) ) return false;
		}
		self::clear_option_read_cache( true );
		$readback = get_option( self::OPTION, null );
		$verified = self::option_values_equal( $readback, $previous );
		if ( $verified ) self::reset_cache();
		return $verified;
	}


	public static function delete_record_verified() {
		self::clear_option_read_cache( true );
		if ( false === get_option( self::OPTION, false ) ) {
			self::reset_cache();
			return true;
		}
		delete_option( self::OPTION );
		self::clear_option_read_cache();
		if ( false === get_option( self::OPTION, false ) ) {
			self::reset_cache();
			return true;
		}
		self::clear_option_read_cache( true );
		delete_option( self::OPTION );
		self::clear_option_read_cache( true );
		$verified = false === get_option( self::OPTION, false );
		if ( $verified ) self::reset_cache();
		return $verified;
	}

	/**
	 * Commit an exact existing Site Profile mutation without exposing authority
	 * before append-only audit succeeds. All authority-sensitive secondary
	 * mutation surfaces should use this primitive rather than update_option.
	 */
	public static function commit_record_with_audit( array $before, array $next, $audit_action, array $audit_payload = array(), $error_prefix = 'mad4b_site_profile_mutation' ) {
		$error_prefix = sanitize_key( (string) $error_prefix );
		if ( '' === $error_prefix ) $error_prefix = 'mad4b_site_profile_mutation';
		$audit_action = trim( (string) $audit_action );
		if ( '' === $audit_action || 1 !== preg_match( '#^[a-z0-9_/-]{1,191}$#D', $audit_action ) ) {
			return new WP_Error( $error_prefix . '_audit_action_invalid', 'Site Profile mutation audit action is invalid.' );
		}
		if ( ! self::valid_record( $before ) || ! self::valid_record( $next ) ) {
			return new WP_Error( $error_prefix . '_record_invalid', 'Site Profile mutation records are invalid.' );
		}
		if ( isset( $before['mutation_state'] ) || isset( $next['mutation_state'] ) ) {
			return new WP_Error( $error_prefix . '_mutation_state_invalid', 'Site Profile mutation inputs must be committed records.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Audit' ) ) return new WP_Error( $error_prefix . '_audit_unavailable', 'Append-only audit storage is unavailable.' );
		$audit_status = MAD4B_SCP_Audit::storage_status();
		if ( empty( $audit_status['ready'] ) ) return new WP_Error( $error_prefix . '_audit_unavailable', 'Ready append-only audit storage is required.' );

		$pending = $next;
		$pending['mutation_state'] = 'pending_audit';
		$pending['mutation_id'] = function_exists( 'wp_generate_uuid4' )
			? strtolower( wp_generate_uuid4() )
			: substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 32 );
		if ( ! self::persist_record_compare_and_swap( $before, $pending ) ) {
			return new WP_Error( $error_prefix . '_conflict', 'Site Profile changed concurrently before this mutation could enter audit.' );
		}

		$payload = array_merge(
			array(
				'site_uuid' => isset( $next['site_uuid'] ) ? (string) $next['site_uuid'] : '',
				'previous_revision' => isset( $before['revision'] ) ? (int) $before['revision'] : 0,
				'revision' => isset( $next['revision'] ) ? (int) $next['revision'] : 0,
				'previous_profile_digest' => self::digest_record( self::normalize_record( $before ) ),
				'profile_digest' => self::digest_record( self::normalize_record( $next ) ),
				'mutation_id' => $pending['mutation_id'],
			),
			$audit_payload
		);
		$audit = MAD4B_SCP_Audit::record( $audit_action, $payload, 'ok' );
		if ( is_wp_error( $audit ) ) {
			$restored = self::restore_record_compare_and_swap( $pending, $before );
			if ( ! $restored ) {
				self::reset_cache();
				return new WP_Error(
					$error_prefix . '_rollback_failed',
					'Site Profile audit failed and the exact pending generation could not be rolled back; authority remains quarantined.',
					array( 'audit_error' => $audit->get_error_code(), 'mutation_id' => $pending['mutation_id'] )
				);
			}
			return new WP_Error( $error_prefix . '_audit_failed', 'Site Profile mutation was rolled back because append-only audit evidence could not be committed.', array( 'audit_error' => $audit->get_error_code() ) );
		}
		if ( ! self::persist_record_compare_and_swap( $pending, $next ) ) {
			self::reset_cache();
			return new WP_Error(
				$error_prefix . '_finalize_failed',
				'Site Profile audit succeeded but the exact pending generation could not be finalized; authority remains quarantined.',
				array( 'mutation_id' => $pending['mutation_id'] )
			);
		}
		return array(
			'revision' => isset( $next['revision'] ) ? (int) $next['revision'] : 0,
			'profile_digest' => self::digest_record( self::normalize_record( $next ) ),
			'mutation_id' => $pending['mutation_id'],
		);
	}

	public static function save_current_site( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_admin_required', 'Administrator capability is required to enroll this site.' );
		self::bootstrap();
		$wordpress_environment = self::wordpress_environment();
		$requested_environment = isset( $input['environment'] ) ? sanitize_key( (string) $input['environment'] ) : '';
		$environment = '' !== $requested_environment ? $requested_environment : self::suggested_environment();
		$origin = self::current_origin();
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) return new WP_Error( 'mad4b_site_profile_environment_invalid', 'Selected MAD4B environment is not supported for site enrollment.' );
		if ( self::wordpress_environment_explicit() && ! hash_equals( $environment, $wordpress_environment ) ) {
			return new WP_Error( 'mad4b_site_profile_environment_conflicts_explicit_wordpress', 'Selected MAD4B environment conflicts with an explicitly configured WordPress environment.' );
		}
		if ( '' === $origin ) return new WP_Error( 'mad4b_site_profile_origin_invalid', 'A canonical WordPress home origin is required for site enrollment.' );
		if ( 'local' !== $environment && 'https' !== strtolower( (string) wp_parse_url( $origin, PHP_URL_SCHEME ) ) ) return new WP_Error( 'mad4b_site_profile_https_required', 'Non-local governed sites require HTTPS.' );

		$existing = get_option( self::OPTION, null );
		$existing_valid = is_array( $existing ) && self::valid_record( $existing );
		$existing_normalized = $existing_valid ? self::normalize_record( $existing ) : array();
		if ( $existing_valid && 'pending_audit' === ( $existing_normalized['mutation_state'] ?? '' ) ) {
			return new WP_Error( 'mad4b_site_profile_mutation_pending', 'A previous Site Profile mutation is pending audit/finalization and remains quarantined.' );
		}
		$current_revision = $existing_valid && isset( $existing_normalized['revision'] ) ? absint( $existing_normalized['revision'] ) : 0;
		$expected_revision = isset( $input['expected_revision'] ) ? absint( $input['expected_revision'] ) : $current_revision;
		if ( $expected_revision !== $current_revision ) return new WP_Error( 'mad4b_site_profile_stale', 'Site profile changed since this form was loaded. Reload before saving.' );
		// Revision resets on identity rebind. A UI tab must also match the exact
		// profile it displayed, so old revision 1 cannot overwrite new revision 1.
		if ( array_key_exists( 'expected_profile_digest', $input ) ) {
			$current_digest = $existing_valid ? self::digest_record( $existing_normalized ) : '';
			if ( ! is_string( $input['expected_profile_digest'] ) || ! hash_equals( $current_digest, $input['expected_profile_digest'] ) ) {
				return new WP_Error( 'mad4b_site_profile_identity_stale', 'Site Profile identity changed since this form was loaded. Reload before saving.' );
			}
		}
		$deployment_binding_digest = self::deployment_binding_digest();
		$existing_identity_matches = $existing_valid
			&& hash_equals( (string) $existing_normalized['environment'], $environment )
			&& hash_equals( (string) $existing_normalized['canonical_origin'], $origin )
			&& self::record_deployment_binding_matches( $existing_normalized );
		$identity_rebound = $existing_valid && ! $existing_identity_matches;

		// WordPress reports Production by default when WP_ENVIRONMENT_TYPE is
		// absent. Reclassifying that implicit default as non-Production is a
		// governance downgrade and requires an exact, one-time local attestation.
		$implicit_nonproduction_override = 'production' === $wordpress_environment
			&& ! self::wordpress_environment_explicit()
			&& 'production' !== $environment;
		$existing_override_confirmed = $existing_identity_matches
			&& ! empty( $existing_normalized['implicit_production_override_confirmed'] );
		$override_checkbox = ! empty( $input['nonproduction_override_confirmed'] );
		$override_phrase = isset( $input['nonproduction_override_confirmation'] )
			? trim( sanitize_text_field( (string) $input['nonproduction_override_confirmation'] ) )
			: '';
		$nonproduction_override_confirmed = $implicit_nonproduction_override
			&& ( $existing_override_confirmed
				|| ( $override_checkbox && hash_equals( self::NONPRODUCTION_OVERRIDE_CONFIRMATION, $override_phrase ) ) );
		if ( $implicit_nonproduction_override && ! $nonproduction_override_confirmed ) {
			return new WP_Error(
				'mad4b_site_profile_nonproduction_override_confirmation_required',
				'WordPress is using its implicit Production default. Confirm this exact origin is non-Production and type CONFIRM THIS ORIGIN IS NON-PRODUCTION.'
			);
		}
		$site_uuid = $existing_identity_matches && ! empty( $existing_normalized['site_uuid'] )
			? strtolower( (string) $existing_normalized['site_uuid'] )
			: wp_generate_uuid4();
		$revision = $existing_identity_matches ? $current_revision + 1 : 1;
		$app_id = isset( $input['chatgpt_app_id'] ) ? trim( sanitize_text_field( (string) $input['chatgpt_app_id'] ) ) : '';
		if ( '' !== $app_id && ! preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/', $app_id ) ) return new WP_Error( 'mad4b_site_profile_app_id_invalid', 'ChatGPT App ID is invalid.' );

		$user_ids = isset( $input['oauth_user_ids'] ) ? self::normalize_user_ids( $input['oauth_user_ids'] ) : array();
		if ( empty( $user_ids ) ) $user_ids = array( get_current_user_id() );
		foreach ( $user_ids as $user_id ) if ( ! get_userdata( $user_id ) ) return new WP_Error( 'mad4b_site_profile_user_invalid', 'Every enrolled OAuth user must exist on this WordPress site.' );

		$write = ! empty( $input['write_enabled'] );
		$production_confirmed = 'production' === $environment && $write && ! empty( $input['production_write_confirmed'] );
		$production_confirmation = isset( $input['production_write_confirmation'] ) ? trim( sanitize_text_field( (string) $input['production_write_confirmation'] ) ) : '';
		if ( 'production' === $environment && $write ) {
			if ( ! $production_confirmed || ! hash_equals( self::PRODUCTION_WRITE_CONFIRMATION, $production_confirmation ) ) {
				return new WP_Error( 'mad4b_site_profile_production_write_confirmation_required', 'To save Production with governed write enabled, select its authorization checkbox and type ENABLE GOVERNED PRODUCTION WRITE. To save without writes, clear Governed write authority.' );
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
		$current_display_name = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		if ( '' === $current_display_name ) $current_display_name = self::current_host();
		$display_name = isset( $input['display_name'] )
			? substr( sanitize_text_field( (string) $input['display_name'] ), 0, 191 )
			: ( $existing_identity_matches && ! empty( $existing_normalized['display_name'] )
				? substr( sanitize_text_field( (string) $existing_normalized['display_name'] ), 0, 191 )
				: substr( sanitize_text_field( $current_display_name ), 0, 191 ) );

		$record = array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'site_uuid' => $site_uuid,
			'revision' => $revision,
			'environment' => $environment,
			'canonical_origin' => $origin,
			'deployment_binding_digest' => $deployment_binding_digest,
			'implicit_production_override_confirmed' => (bool) $nonproduction_override_confirmed,
			'display_name' => $display_name,
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
			'created_at' => $existing_identity_matches && ! empty( $existing_normalized['created_at'] ) ? (string) $existing_normalized['created_at'] : gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
		$before_digest = $existing_valid ? self::digest_record( $existing_normalized ) : '';
		$final_digest = self::digest_record( $record );
		$pending = $record;
		$pending['mutation_state'] = 'pending_audit';
		$pending['mutation_id'] = function_exists( 'wp_generate_uuid4' )
			? strtolower( wp_generate_uuid4() )
			: substr( hash( 'sha256', microtime( true ) . ':' . uniqid( '', true ) ), 0, 32 );
		if ( ! self::persist_record_compare_and_swap( $existing, $pending ) ) {
			return new WP_Error( 'mad4b_site_profile_conflict', 'Site Profile changed concurrently before this save could commit. Reload and review the latest state.' );
		}

		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			$audit = MAD4B_SCP_Audit::record( 'mad4b/site-profile-updated', array(
				'site_uuid' => $site_uuid,
				'previous_revision' => $current_revision,
				'revision' => $revision,
				'previous_profile_digest' => $before_digest,
				'profile_digest' => $final_digest,
				'mutation_id' => $pending['mutation_id'],
				'environment' => $environment,
				'wordpress_environment' => $wordpress_environment,
				'wordpress_environment_explicit' => self::wordpress_environment_explicit(),
				'implicit_nonproduction_override' => $implicit_nonproduction_override,
				'nonproduction_override_confirmed' => $nonproduction_override_confirmed,
				'deployment_binding_configured' => '' !== $deployment_binding_digest,
				'environment_source' => '' !== $requested_environment ? 'explicit_site_profile' : ( $environment === $wordpress_environment ? 'wordpress' : 'suggested_enrollment' ),
				'canonical_origin' => $origin,
				'identity_rebound' => $identity_rebound,
				'previous_environment' => $existing_valid ? (string) $existing_normalized['environment'] : '',
				'previous_canonical_origin' => $existing_valid ? (string) $existing_normalized['canonical_origin'] : '',
				'write_enabled' => $write,
				'oauth_user_count' => count( $user_ids ),
			), 'ok' );
			if ( is_wp_error( $audit ) ) {
				$restored = self::restore_record_compare_and_swap( $pending, $existing );
				if ( ! $restored ) {
					self::reset_cache();
					return new WP_Error( 'mad4b_site_profile_audit_rollback_failed', 'Site Profile audit failed and the pending mutation remains quarantined because rollback no longer owned the exact current generation.', array( 'audit_error' => $audit->get_error_code(), 'mutation_id' => $pending['mutation_id'] ) );
				}
				return new WP_Error( 'mad4b_site_profile_audit_failed', 'Site Profile change was rolled back because append-only audit evidence could not be recorded.', array( 'audit_error' => $audit->get_error_code() ) );
			}
		}

		if ( ! self::persist_record_compare_and_swap( $pending, $record ) ) {
			self::reset_cache();
			return new WP_Error( 'mad4b_site_profile_commit_finalize_failed', 'Site Profile audit completed but the pending generation could not be atomically finalized; authority remains quarantined.', array( 'mutation_id' => $pending['mutation_id'] ) );
		}
		if ( function_exists( 'do_action' ) ) do_action( 'mad4b_scp_site_profile_saved' );
		return self::status();
	}

	public static function disable_authority( $expected_revision = null ) {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_site_profile_admin_required', 'Administrator capability is required to change site authority.' );
		$before = function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null;
		if ( ! is_array( $before ) || ! self::valid_record( $before ) ) return self::status();
		$profile = self::normalize_record( $before );
		if ( 'pending_audit' === ( $profile['mutation_state'] ?? '' ) ) return new WP_Error( 'mad4b_site_profile_mutation_pending', 'Pending Site Profile mutation must be reconciled before authority can be changed.' );
		$current_revision = absint( $profile['revision'] ?? 0 );
		if ( null !== $expected_revision && absint( $expected_revision ) !== $current_revision ) return new WP_Error( 'mad4b_site_profile_stale', 'Site profile changed since this form was loaded. Reload before disabling authority.' );

		$next = $profile;
		$next['revision'] = $current_revision + 1;
		if ( ! isset( $next['features'] ) || ! is_array( $next['features'] ) ) $next['features'] = array();
		$next['features']['write'] = false;
		$next['features']['production_write_confirmed'] = false;
		$next['updated_at'] = gmdate( 'c' );

		// Revocation is safety-reducing, so it must not be blocked or rolled back
		// merely because audit storage is degraded. Commit the exact disabled
		// generation atomically first; audit failure is reported truthfully while
		// the reduced authority remains in force.
		if ( ! self::persist_record_compare_and_swap( $before, $next ) ) {
			return new WP_Error( 'mad4b_site_profile_disable_authority_conflict', 'Site Profile changed concurrently before write authority could be disabled.' );
		}
		self::reset_cache();
		if ( function_exists( 'do_action' ) ) do_action( 'mad4b_scp_site_profile_saved' );

		$status = self::status();
		$audit_ready = class_exists( 'MAD4B_SCP_Audit' ) && ! empty( MAD4B_SCP_Audit::storage_status()['ready'] );
		if ( ! $audit_ready ) {
			return new WP_Error(
				'mad4b_site_profile_disable_authority_audit_unavailable',
				'Write authority was disabled atomically, but append-only audit storage is unavailable.',
				array(
					'authority_disabled' => true,
					'revision' => isset( $status['revision'] ) ? (int) $status['revision'] : 0,
					'profile_digest' => isset( $status['profile_digest'] ) ? (string) $status['profile_digest'] : '',
				)
			);
		}
		$audit = MAD4B_SCP_Audit::record( 'mad4b/site-profile-write-disabled', array(
			'site_uuid' => isset( $status['site_uuid'] ) ? (string) $status['site_uuid'] : '',
			'previous_revision' => $current_revision,
			'revision' => isset( $status['revision'] ) ? (int) $status['revision'] : 0,
			'profile_digest' => isset( $status['profile_digest'] ) ? (string) $status['profile_digest'] : '',
			'write_enabled' => false,
		), 'ok' );
		if ( is_wp_error( $audit ) ) {
			return new WP_Error(
				'mad4b_site_profile_disable_authority_audit_failed',
				'Write authority was disabled atomically, but the mandatory audit event could not be appended.',
				array(
					'authority_disabled' => true,
					'audit_error' => $audit->get_error_code(),
					'revision' => isset( $status['revision'] ) ? (int) $status['revision'] : 0,
					'profile_digest' => isset( $status['profile_digest'] ) ? (string) $status['profile_digest'] : '',
				)
			);
		}
		return $status;
	}

	public static function validate_record_for_test( array $record, $environment, $origin ) {
		if ( ! self::valid_record( $record ) ) return false;
		$record = self::normalize_record( $record );
		if ( ! self::valid_record( $record ) ) return false;
		return sanitize_key( (string) $environment ) === (string) $record['environment']
			&& self::normalize_origin( $origin ) === (string) $record['canonical_origin'];
	}


	private static function valid_legacy_record( $record ) {
		if ( ! self::valid_record_shape( $record ) ) return false;
		if ( self::LEGACY_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( self::LEGACY_VERSION !== absint( isset( $record['version'] ) ? $record['version'] : 0 ) ) return false;
		if ( ! self::valid_uuid( isset( $record['site_uuid'] ) ? $record['site_uuid'] : '' ) ) return false;
		if ( absint( isset( $record['revision'] ) ? $record['revision'] : 0 ) < 1 ) return false;
		$environment = sanitize_key( isset( $record['environment'] ) ? (string) $record['environment'] : '' );
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) return false;
		return '' !== self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
	}

	private static function migrate_legacy_record( $record ) {
		if ( ! self::valid_legacy_record( $record ) ) return array();
		$legacy = self::normalize_record( $record );
		$environment = self::current_environment();
		$origin = self::current_origin();
		if ( '' === $origin || ! hash_equals( (string) $legacy['canonical_origin'], $origin ) ) return array();
		if ( ! hash_equals( (string) $legacy['environment'], $environment ) ) {
			$wordpress = self::wordpress_environment();
			$implicit_production_default = 'production' === $wordpress && ! self::wordpress_environment_explicit();
			if ( ! $implicit_production_default ) return array();
			$environment = sanitize_key( (string) $legacy['environment'] );
		}
		return array(
			'contract' => self::CONTRACT,
			'version' => self::VERSION,
			'site_uuid' => (string) $legacy['site_uuid'],
			'revision' => max( 1, absint( $legacy['revision'] ) + 1 ),
			'environment' => (string) $legacy['environment'],
			'canonical_origin' => (string) $legacy['canonical_origin'],
			'display_name' => isset( $legacy['display_name'] ) ? (string) $legacy['display_name'] : '',
			'chatgpt_app_id' => '',
			'oauth_user_ids' => array(),
			'related_origins' => isset( $legacy['related_origins'] ) && is_array( $legacy['related_origins'] ) ? $legacy['related_origins'] : array(),
			'features' => array( 'oauth' => false, 'skills' => false, 'write' => false, 'production_write_confirmed' => false, 'provider_isolation' => false, 'managed_runtime' => false, 'acceptance' => false ),
			'legacy_agent_slug' => '',
			'legacy_zero_touch' => false,
			'migrated_from_contract' => self::LEGACY_CONTRACT,
			'migration_requires_reenrollment' => true,
			'created_at' => ! empty( $legacy['created_at'] ) ? (string) $legacy['created_at'] : gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		);
	}

	private static function build_status( array $profile, $source ) {
		$resolution = self::environment_resolution();
		$environment = sanitize_key( (string) $resolution['effective_environment'] );
		$origin = self::current_origin();
		$configured = self::valid_record( $profile );
		$environment_match = $configured && hash_equals( (string) $profile['environment'], $environment );
		$origin_match = $configured && '' !== $origin && hash_equals( (string) $profile['canonical_origin'], $origin );
		$current_deployment_binding = self::deployment_binding_digest();
		$stored_deployment_binding = $configured && isset( $profile['deployment_binding_digest'] ) && is_string( $profile['deployment_binding_digest'] )
			? strtolower( trim( $profile['deployment_binding_digest'] ) )
			: '';
		$deployment_binding_match = $configured
			? ( '' === $stored_deployment_binding || ( '' !== $current_deployment_binding && hash_equals( $stored_deployment_binding, $current_deployment_binding ) ) )
			: false;
		$implicit_override = $configured
			&& 'production' === (string) $resolution['wordpress_environment']
			&& empty( $resolution['wordpress_environment_explicit'] )
			&& 'production' !== (string) $profile['environment'];
		$override_confirmed = $implicit_override && ! empty( $profile['implicit_production_override_confirmed'] );
		$attestation_required = $implicit_override && ! $override_confirmed;
		$blockers = array();
		$reenrollment_required = $configured && ! empty( $profile['migration_requires_reenrollment'] );
		$mutation_pending = $configured && 'pending_audit' === ( $profile['mutation_state'] ?? '' );
		$authority_ready = $configured && $environment_match && $origin_match && $deployment_binding_match && ! $reenrollment_required && ! $attestation_required && ! $mutation_pending;
		$binding_state = ! $configured
			? 'unconfigured'
			: ( ! $origin_match
				? 'foreign_origin'
				: ( ! $deployment_binding_match
					? 'deployment_drift'
					: ( $mutation_pending
						? 'mutation_pending_audit'
						: ( $attestation_required
							? 'nonproduction_override_unconfirmed'
						: ( ! $environment_match
								? 'environment_drift'
								: ( $reenrollment_required ? 'reenrollment_required' : 'exact' ) ) ) ) ) );
		$foreign_profile_detected = $configured && ( ! $origin_match || ! $environment_match || ! $deployment_binding_match );
		if ( ! $configured ) $blockers[] = 'site_profile_unconfigured';
		if ( $configured && ! $environment_match ) $blockers[] = 'site_profile_environment_drift';
		if ( $configured && ! $origin_match ) $blockers[] = 'site_profile_origin_drift';
		if ( $configured && ! $deployment_binding_match ) $blockers[] = 'site_profile_deployment_binding_drift';
		if ( $attestation_required ) $blockers[] = 'site_profile_nonproduction_override_confirmation_required';
		if ( $mutation_pending ) $blockers[] = 'site_profile_mutation_pending_audit';
		if ( $reenrollment_required ) $blockers[] = 'site_profile_reenrollment_required';
		return array(
			'contract' => self::CONTRACT,
			'configured' => $configured,
			'source' => sanitize_key( (string) $source ),
			'legacy_preset_migration_enabled' => self::legacy_preset_migration_enabled(),
			'site_uuid' => $configured ? (string) $profile['site_uuid'] : '',
			'revision' => $configured ? absint( $profile['revision'] ) : 0,
			'profile_digest' => $configured ? self::digest_record( $profile ) : '',
			'environment' => $environment,
			'wordpress_environment' => (string) $resolution['wordpress_environment'],
			'wordpress_environment_explicit' => ! empty( $resolution['wordpress_environment_explicit'] ),
			'profile_environment_authoritative' => ! empty( $resolution['profile_environment_authoritative'] ),
			'effective_environment_source' => (string) $resolution['effective_source'],
			'suggested_environment' => (string) $resolution['suggested_environment'],
			'wordpress_profile_mismatch' => ! empty( $resolution['wordpress_profile_mismatch'] ),
			'hostname_hint_used_for_authority' => false,
			'configured_environment' => $configured ? (string) $profile['environment'] : '',
			'current_origin' => $origin,
			'canonical_origin' => $configured ? (string) $profile['canonical_origin'] : '',
			'environment_match' => $environment_match,
			'origin_match' => $origin_match,
			'deployment_binding_configured' => '' !== $current_deployment_binding,
			'deployment_binding_bound' => '' !== $stored_deployment_binding,
			'deployment_binding_match' => $deployment_binding_match,
			'same_origin_clone_protection' => '' !== $stored_deployment_binding && $deployment_binding_match,
			'implicit_nonproduction_override' => $implicit_override,
			'implicit_nonproduction_override_confirmed' => $override_confirmed,
			'nonproduction_override_attestation_required' => $attestation_required,
			'authority_ready' => $authority_ready,
			'mutation_pending_audit' => $mutation_pending,
			'mutation_id' => $mutation_pending && isset( $profile['mutation_id'] ) ? (string) $profile['mutation_id'] : '',
			'binding_state' => $binding_state,
			'foreign_profile_detected' => $foreign_profile_detected,
			'profile_authority_quarantined' => ! $authority_ready,
			'reenrollment_required' => $reenrollment_required,
			'write_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'write', $environment ),
			'oauth_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'oauth', $environment ),
			'skills_enabled' => $authority_ready && self::record_feature_enabled( $profile, 'skills', $environment ),
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
		if ( ! self::legacy_preset_migration_enabled() ) return array();
		$path = defined( 'MAD4B_SCP_LEGACY_SITE_PROFILE_PRESET_FILE' )
			? trim( (string) constant( 'MAD4B_SCP_LEGACY_SITE_PROFILE_PRESET_FILE' ) )
			: ( defined( 'MAD4B_SCP_DIR' ) ? trailingslashit( MAD4B_SCP_DIR ) . self::PRESET_FILE : '' );
		if ( function_exists( 'apply_filters' ) ) $path = (string) apply_filters( 'mad4b_scp_legacy_site_profile_preset_file', $path );
		if ( '' === $path || ! is_readable( $path ) ) return array();
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || self::PRESET_CONTRACT !== ( isset( $decoded['contract'] ) ? (string) $decoded['contract'] : '' ) || empty( $decoded['profiles'] ) || ! is_array( $decoded['profiles'] ) ) return array();
		$environment = self::current_environment();
		$origin = self::current_origin();
		$implicit_production_default = 'production' === self::wordpress_environment() && ! self::wordpress_environment_explicit();
		foreach ( $decoded['profiles'] as $preset ) {
			if ( ! is_array( $preset ) || ! self::valid_record( $preset ) ) continue;
			$preset = self::normalize_record( $preset );
			if ( ! hash_equals( (string) $preset['canonical_origin'], $origin ) ) continue;
			if ( ! hash_equals( (string) $preset['environment'], $environment ) && ! $implicit_production_default ) continue;
			return $preset;
		}
		return array();
	}

	private static function exact_stored_profile() {
		if ( ! function_exists( 'get_option' ) ) return array();
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! self::valid_record( $stored ) ) return array();
		$stored = self::normalize_record( $stored );
		$origin = self::current_origin();
		if ( '' === $origin || ! hash_equals( (string) $stored['canonical_origin'], $origin ) ) return array();
		if ( ! self::record_deployment_binding_matches( $stored ) ) return array();
		return $stored;
	}

	private static function valid_record( $record ) {
		if ( ! self::valid_record_shape( $record ) ) return false;
		if ( self::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( self::VERSION !== absint( isset( $record['version'] ) ? $record['version'] : 0 ) ) return false;
		if ( ! self::valid_uuid( isset( $record['site_uuid'] ) ? $record['site_uuid'] : '' ) ) return false;
		if ( absint( isset( $record['revision'] ) ? $record['revision'] : 0 ) < 1 ) return false;
		$environment = sanitize_key( isset( $record['environment'] ) ? (string) $record['environment'] : '' );
		if ( ! in_array( $environment, array( 'local', 'development', 'staging', 'production' ), true ) ) return false;
		return '' !== self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
	}

	/** Validate before coercion: corrupt stored data must never become authority. */
	private static function valid_record_shape( $record ) {
		if ( ! is_array( $record ) ) return false;
		foreach ( array( 'contract', 'site_uuid', 'environment', 'canonical_origin' ) as $field ) {
			if ( ! isset( $record[ $field ] ) || ! is_string( $record[ $field ] ) ) return false;
		}
		foreach ( array( 'version', 'revision' ) as $field ) {
			$value = $record[ $field ] ?? null;
			if ( ! is_int( $value ) && ! is_string( $value ) ) return false;
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', (string) $value ) || false === filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) return false;
		}
		if ( ! in_array( $record['environment'], array( 'local', 'development', 'staging', 'production' ), true ) ) return false;
		if ( isset( $record['features'] ) ) {
			if ( ! is_array( $record['features'] ) ) return false;
			foreach ( $record['features'] as $value ) if ( ! in_array( $value, array( true, false, 1, 0, '1', '0' ), true ) ) return false;
		}
		if ( isset( $record['oauth_user_ids'] ) ) {
			if ( ! is_array( $record['oauth_user_ids'] ) ) return false;
			foreach ( $record['oauth_user_ids'] as $user_id ) {
				if ( ! is_int( $user_id ) && ! is_string( $user_id ) ) return false;
				if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', (string) $user_id ) || false === filter_var( $user_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) return false;
			}
		}
		if ( isset( $record['related_origins'] ) ) {
			if ( ! is_array( $record['related_origins'] ) ) return false;
			foreach ( $record['related_origins'] as $key => $value ) {
				if ( ! is_string( $key ) || ! in_array( $key, array( 'local', 'development', 'staging', 'production' ), true ) || ! is_string( $value ) || '' === self::normalize_origin( $value ) ) return false;
			}
		}
		if ( isset( $record['mutation_state'] ) ) {
			if ( ! is_string( $record['mutation_state'] ) || 'pending_audit' !== $record['mutation_state'] ) return false;
			if ( ! isset( $record['mutation_id'] ) || ! is_string( $record['mutation_id'] ) || 1 !== preg_match( '/^(?:[a-f0-9]{32}|[a-f0-9-]{36})$/i', $record['mutation_id'] ) ) return false;
		}
		foreach ( array( 'legacy_zero_touch', 'implicit_production_override_confirmed', 'migration_requires_reenrollment' ) as $field ) {
			if ( isset( $record[ $field ] ) && ! is_bool( $record[ $field ] ) ) return false;
		}
		if ( isset( $record['deployment_binding_digest'] ) ) {
			if ( ! is_string( $record['deployment_binding_digest'] ) ) return false;
			$digest = strtolower( trim( $record['deployment_binding_digest'] ) );
			if ( '' !== $digest && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) return false;
		}
		foreach ( array( 'display_name', 'chatgpt_app_id', 'legacy_agent_slug', 'created_at', 'updated_at' ) as $field ) {
			if ( isset( $record[ $field ] ) && ! is_string( $record[ $field ] ) ) return false;
		}
		if ( isset( $record['chatgpt_app_id'] ) && '' !== trim( $record['chatgpt_app_id'] ) && 1 !== preg_match( '/^plugin_asdk_app_[A-Za-z0-9]+$/D', trim( $record['chatgpt_app_id'] ) ) ) return false;
		return true;
	}

	private static function normalize_record( array $record ) {
		$record['contract'] = isset( $record['contract'] ) ? (string) $record['contract'] : '';
		$record['version'] = absint( isset( $record['version'] ) ? $record['version'] : 0 );
		$record['site_uuid'] = strtolower( trim( isset( $record['site_uuid'] ) ? (string) $record['site_uuid'] : '' ) );
		$record['revision'] = max( 1, absint( isset( $record['revision'] ) ? $record['revision'] : 1 ) );
		$record['environment'] = sanitize_key( isset( $record['environment'] ) ? (string) $record['environment'] : '' );
		$record['canonical_origin'] = self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
		$record['deployment_binding_digest'] = isset( $record['deployment_binding_digest'] ) && is_string( $record['deployment_binding_digest'] ) ? strtolower( trim( $record['deployment_binding_digest'] ) ) : '';
		$record['implicit_production_override_confirmed'] = isset( $record['implicit_production_override_confirmed'] ) && true === $record['implicit_production_override_confirmed'];
		$record['display_name'] = isset( $record['display_name'] ) ? substr( sanitize_text_field( (string) $record['display_name'] ), 0, 191 ) : '';
		$record['chatgpt_app_id'] = isset( $record['chatgpt_app_id'] ) ? trim( (string) $record['chatgpt_app_id'] ) : '';
		$record['oauth_user_ids'] = self::normalize_user_ids( isset( $record['oauth_user_ids'] ) ? $record['oauth_user_ids'] : array() );
		$record['related_origins'] = isset( $record['related_origins'] ) && is_array( $record['related_origins'] ) ? array_filter( array_map( array( __CLASS__, 'normalize_origin' ), $record['related_origins'] ) ) : array();
		$record['features'] = isset( $record['features'] ) && is_array( $record['features'] ) ? array_map( 'boolval', $record['features'] ) : array();
		$record['legacy_agent_slug'] = isset( $record['legacy_agent_slug'] ) ? sanitize_key( (string) $record['legacy_agent_slug'] ) : '';
		$record['legacy_zero_touch'] = isset( $record['legacy_zero_touch'] ) && true === $record['legacy_zero_touch'];
		if ( isset( $record['mutation_state'] ) && is_string( $record['mutation_state'] ) && 'pending_audit' === sanitize_key( $record['mutation_state'] ) ) {
			$record['mutation_state'] = 'pending_audit';
			$record['mutation_id'] = isset( $record['mutation_id'] ) && is_string( $record['mutation_id'] ) ? strtolower( trim( $record['mutation_id'] ) ) : '';
		} else {
			unset( $record['mutation_state'], $record['mutation_id'] );
		}
		return $record;
	}

	private static function normalize_user_ids( $value ) {
		if ( is_string( $value ) ) $value = preg_split( '/[\s,]+/', $value );
		if ( ! is_array( $value ) ) return array();
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_int( $item ) && ! is_string( $item ) ) continue;
			$item = trim( (string) $item );
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $item ) ) continue;
			$validated = filter_var( $item, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false !== $validated ) $out[] = (int) $validated;
		}
		return array_values( array_unique( $out ) );
	}

	private static function normalize_origin( $url ) {
		if ( ! is_string( $url ) ) return '';
		$url = trim( $url );
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
		if ( ! is_string( $value ) ) return false;
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
