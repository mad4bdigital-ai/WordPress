<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conservative upgrade continuity and reconnect diagnostics.
 *
 * Fresh installs remain zero-authority. A migrated v1 Site Profile, or a
 * previously verified local OAuth snapshot that survived an older build, can
 * recover only the previously proven read/OAuth connection on the exact same
 * non-Production origin, site UUID and administrator subject. Write, Skills,
 * acceptance and Production authority are never restored by this migration.
 */
final class MAD4B_SCP_Upgrade_Continuity {
	const CONTRACT = 'mad4b.upgrade-continuity.v1';
	const PRIOR_OAUTH_OPTION = 'mad4b_scp_staging_oauth_autoconfig_v1';
	const RECOVERY_MARKER_OPTION = 'mad4b_scp_upgrade_continuity_recovery_v1';

	private static $pre_booted = false;
	private static $booted = false;
	private static $recovery = array();

	public static function pre_boot() {
		if ( self::$pre_booted ) return self::$recovery;
		self::$pre_booted = true;
		self::$recovery = self::recover_verified_read_continuity();
		return self::$recovery;
	}

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'parse_request', array( __CLASS__, 'guard_reconnect_paths' ), -30 );
		add_action( 'admin_notices', array( __CLASS__, 'replace_ambiguous_governance_notice' ), 0 );
		add_action( 'admin_notices', array( __CLASS__, 'connection_admin_notice' ), 1 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
	}

	public static function recovery_status() {
		return is_array( self::$recovery ) && ! empty( self::$recovery ) ? self::$recovery : self::recovery_result( 'not_checked', false, '' );
	}

	/**
	 * Restore only a prior read/OAuth connection when exact same-site evidence
	 * survived the upgrade. This deliberately does not restore write authority.
	 */
	public static function recover_verified_read_continuity() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return self::recovery_result( 'site_profile_class_unavailable', false, 'site_profile_class_unavailable' );

		$current = get_option( MAD4B_SCP_Site_Profile::OPTION, null );
		$has_current = null !== $current && false !== $current;
		if ( ! self::valid_v2_migration_pending_record( $current ) ) {
			if ( $has_current ) return self::recovery_result( 'blocked', false, 'current_site_profile_invalid' );
			return self::recover_snapshot_only_read_continuity();
		}

		$environment = self::current_environment();
		if ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return self::recovery_result( 'blocked', false, 'nonproduction_only' );
		$origin = self::current_origin();
		if ( '' === $origin || ! hash_equals( self::normalize_origin( $current['canonical_origin'] ), $origin ) || ! hash_equals( sanitize_key( (string) $current['environment'] ), $environment ) ) {
			return self::recovery_result( 'blocked', false, 'current_profile_origin_or_environment_drift' );
		}

		$legacy = get_option( MAD4B_SCP_Site_Profile::LEGACY_OPTION, array() );
		if ( ! self::valid_legacy_record( $legacy ) ) return self::recovery_result( 'blocked', false, 'legacy_profile_evidence_missing' );
		if ( ! hash_equals( strtolower( trim( (string) $current['site_uuid'] ) ), strtolower( trim( (string) $legacy['site_uuid'] ) ) ) ) return self::recovery_result( 'blocked', false, 'legacy_site_uuid_mismatch' );
		if ( ! hash_equals( $origin, self::normalize_origin( $legacy['canonical_origin'] ) ) || ! hash_equals( $environment, sanitize_key( (string) $legacy['environment'] ) ) ) return self::recovery_result( 'blocked', false, 'legacy_origin_or_environment_mismatch' );

		$snapshot = get_option( self::PRIOR_OAUTH_OPTION, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_evidence_missing' );
		$snapshot_environment = isset( $snapshot['environment'] ) ? sanitize_key( (string) $snapshot['environment'] ) : '';
		$snapshot_origin = isset( $snapshot['canonical_origin'] ) ? self::normalize_origin( $snapshot['canonical_origin'] ) : '';
		$snapshot_uuid = isset( $snapshot['site_uuid'] ) ? strtolower( trim( (string) $snapshot['site_uuid'] ) ) : '';
		$snapshot_issuer = isset( $snapshot['issuer'] ) ? untrailingslashit( trim( (string) $snapshot['issuer'] ) ) : '';
		$expected_issuer = untrailingslashit( home_url( '/oauth/mcp' ) );
		$owner_user_id = isset( $snapshot['primary_owner_user_id'] ) ? absint( $snapshot['primary_owner_user_id'] ) : ( isset( $snapshot['wp_user_id'] ) ? absint( $snapshot['wp_user_id'] ) : 0 );
		$snapshot_revision = isset( $snapshot['profile_revision'] ) ? absint( $snapshot['profile_revision'] ) : 0;
		$legacy_revision = absint( isset( $legacy['revision'] ) ? $legacy['revision'] : 0 );

		if ( ! hash_equals( $environment, $snapshot_environment ) || ! hash_equals( $origin, $snapshot_origin ) || ! hash_equals( strtolower( trim( (string) $current['site_uuid'] ) ), $snapshot_uuid ) ) {
			return self::recovery_result( 'blocked', false, 'prior_oauth_identity_mismatch' );
		}
		if ( '' === $snapshot_issuer || ! hash_equals( $expected_issuer, $snapshot_issuer ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_issuer_mismatch' );
		if ( $snapshot_revision > 0 && $legacy_revision > 0 && $snapshot_revision !== $legacy_revision ) return self::recovery_result( 'blocked', false, 'prior_oauth_revision_mismatch' );
		if ( $owner_user_id < 1 ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_missing' );
		$user = class_exists( 'WP_User' ) ? new WP_User( $owner_user_id ) : ( function_exists( 'get_userdata' ) ? get_userdata( $owner_user_id ) : false );
		if ( ! $user || ! function_exists( 'user_can' ) || ! user_can( $user, 'manage_options' ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_administrator' );
		if ( isset( $snapshot['oauth_user_ids'] ) && is_array( $snapshot['oauth_user_ids'] ) && ! empty( $snapshot['oauth_user_ids'] ) ) {
			$prior_users = array_values( array_unique( array_filter( array_map( 'absint', $snapshot['oauth_user_ids'] ) ) ) );
			if ( ! in_array( $owner_user_id, $prior_users, true ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_in_snapshot' );
		}

		$next = $current;
		$next['revision'] = max( 1, absint( $current['revision'] ) + 1 );
		$next['oauth_user_ids'] = array( $owner_user_id );
		$next['features'] = self::read_only_features();
		$next['chatgpt_app_id'] = '';
		$next['legacy_agent_slug'] = '';
		$next['legacy_zero_touch'] = false;
		$next['migration_requires_reenrollment'] = false;
		$next['migration_read_continuity_recovered'] = true;
		$next['migration_write_reenrollment_required'] = true;
		$next['migration_read_continuity_contract'] = self::CONTRACT;
		$next['migration_read_continuity_evidence_sha256'] = hash( 'sha256', implode( "\n", array( $environment, $origin, strtolower( trim( (string) $current['site_uuid'] ) ), (string) $owner_user_id, $snapshot_issuer ) ) );
		$next['updated_at'] = gmdate( 'c' );

		if ( false === update_option( MAD4B_SCP_Site_Profile::OPTION, $next, false ) ) return self::recovery_result( 'blocked', false, 'read_continuity_persist_failed' );
		return self::recovery_result( 'recovered', true, '', array(
			'site_uuid' => strtolower( trim( (string) $current['site_uuid'] ) ),
			'environment' => $environment,
			'canonical_origin' => $origin,
			'owner_user_id' => $owner_user_id,
			'previous_revision' => absint( $current['revision'] ),
			'revision' => absint( $next['revision'] ),
			'recovery_source' => 'legacy_site_profile_and_prior_oauth_snapshot',
			'write_restored' => false,
			'production_authority_restored' => false,
		) );
	}

	/**
	 * Older zero-touch builds could persist the exact local OAuth snapshot before
	 * Site Profile v1/v2 existed. Recover that identity only when the snapshot is
	 * still exact-bound to this non-Production origin and an Administrator.
	 */
	private static function recover_snapshot_only_read_continuity() {
		$snapshot = get_option( self::PRIOR_OAUTH_OPTION, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) return self::recovery_result( 'not_applicable', false, '' );

		$marker = get_option( self::RECOVERY_MARKER_OPTION, array() );
		if ( is_array( $marker ) && 'consumed' === ( isset( $marker['state'] ) ? sanitize_key( (string) $marker['state'] ) : '' ) ) {
			return self::recovery_result( 'blocked', false, 'snapshot_recovery_already_consumed' );
		}

		$environment = self::current_environment();
		if ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return self::recovery_result( 'blocked', false, 'nonproduction_only' );
		$origin = self::current_origin();
		if ( '' === $origin ) return self::recovery_result( 'blocked', false, 'current_origin_unavailable' );

		$snapshot_issuer = isset( $snapshot['issuer'] ) ? untrailingslashit( trim( (string) $snapshot['issuer'] ) ) : '';
		$expected_issuer = untrailingslashit( home_url( '/oauth/mcp' ) );
		$owner_user_id = isset( $snapshot['primary_owner_user_id'] ) ? absint( $snapshot['primary_owner_user_id'] ) : ( isset( $snapshot['wp_user_id'] ) ? absint( $snapshot['wp_user_id'] ) : 0 );
		if ( $owner_user_id < 1 ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_missing' );
		$user = class_exists( 'WP_User' ) ? new WP_User( $owner_user_id ) : ( function_exists( 'get_userdata' ) ? get_userdata( $owner_user_id ) : false );
		if ( ! $user || ! function_exists( 'user_can' ) || ! user_can( $user, 'manage_options' ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_administrator' );

		$format = self::snapshot_format( $snapshot );
		if ( 'unsupported' === $format ) return self::recovery_result( 'blocked', false, 'prior_oauth_snapshot_format_unsupported' );

		$snapshot_revision = 0;
		$snapshot_uuid = '';
		if ( 'profile_bound' === $format ) {
			$snapshot_environment = isset( $snapshot['environment'] ) ? sanitize_key( (string) $snapshot['environment'] ) : '';
			$snapshot_origin = isset( $snapshot['canonical_origin'] ) ? self::normalize_origin( $snapshot['canonical_origin'] ) : '';
			$snapshot_uuid = isset( $snapshot['site_uuid'] ) ? strtolower( trim( (string) $snapshot['site_uuid'] ) ) : '';
			$snapshot_revision = isset( $snapshot['profile_revision'] ) ? absint( $snapshot['profile_revision'] ) : 0;
			if ( ! self::valid_uuid( $snapshot_uuid ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_site_uuid_invalid' );
			if ( ! hash_equals( $environment, $snapshot_environment ) || ! hash_equals( $origin, $snapshot_origin ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_identity_mismatch' );
			if ( '' === $snapshot_issuer || ! hash_equals( $expected_issuer, $snapshot_issuer ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_issuer_mismatch' );
			if ( isset( $snapshot['profile_digest'] ) && '' !== trim( (string) $snapshot['profile_digest'] ) && ! self::valid_sha256( $snapshot['profile_digest'] ) ) {
				return self::recovery_result( 'blocked', false, 'prior_oauth_profile_digest_invalid' );
			}
			if ( isset( $snapshot['oauth_user_ids'] ) && is_array( $snapshot['oauth_user_ids'] ) && ! empty( $snapshot['oauth_user_ids'] ) ) {
				$prior_users = array_values( array_unique( array_filter( array_map( 'absint', $snapshot['oauth_user_ids'] ) ) ) );
				if ( ! in_array( $owner_user_id, $prior_users, true ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_in_snapshot' );
			}
		} else {
			if ( '' === $snapshot_issuer || ! hash_equals( $expected_issuer, $snapshot_issuer ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_issuer_mismatch' );
			$snapshot_uuid = self::legacy_snapshot_site_uuid( $origin, $owner_user_id, $snapshot_issuer );
			if ( ! self::valid_uuid( $snapshot_uuid ) ) return self::recovery_result( 'blocked', false, 'legacy_snapshot_site_uuid_derivation_failed' );
		}

		$evidence_sha256 = hash( 'sha256', implode( "\n", array( self::CONTRACT, $format, $environment, $origin, $snapshot_uuid, (string) $owner_user_id, $snapshot_issuer ) ) );
		$now = gmdate( 'c' );
		$next = array(
			'contract' => MAD4B_SCP_Site_Profile::CONTRACT,
			'version' => MAD4B_SCP_Site_Profile::VERSION,
			'site_uuid' => $snapshot_uuid,
			'revision' => max( 1, $snapshot_revision + 1 ),
			'environment' => $environment,
			'canonical_origin' => $origin,
			'display_name' => function_exists( 'get_bloginfo' ) ? substr( sanitize_text_field( (string) get_bloginfo( 'name' ) ), 0, 191 ) : '',
			'chatgpt_app_id' => '',
			'oauth_user_ids' => array( $owner_user_id ),
			'related_origins' => array( $environment => $origin ),
			'features' => self::read_only_features(),
			'legacy_agent_slug' => '',
			'legacy_zero_touch' => false,
			'migration_requires_reenrollment' => false,
			'migration_read_continuity_recovered' => true,
			'migration_read_continuity_source' => 'legacy_zero_touch' === $format ? 'legacy_zero_touch_oauth_snapshot_v1' : 'prior_oauth_snapshot',
			'migration_snapshot_format' => $format,
			'migration_write_reenrollment_required' => true,
			'migration_read_continuity_contract' => self::CONTRACT,
			'migration_read_continuity_evidence_sha256' => $evidence_sha256,
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( false === update_option( MAD4B_SCP_Site_Profile::OPTION, $next, false ) ) return self::recovery_result( 'blocked', false, 'read_continuity_persist_failed' );

		$marker = array(
			'contract' => self::CONTRACT,
			'state' => 'consumed',
			'source' => (string) $next['migration_read_continuity_source'],
			'site_uuid' => $snapshot_uuid,
			'environment' => $environment,
			'canonical_origin' => $origin,
			'evidence_sha256' => $evidence_sha256,
			'consumed_at' => $now,
		);
		if ( false === update_option( self::RECOVERY_MARKER_OPTION, $marker, false ) ) {
			delete_option( MAD4B_SCP_Site_Profile::OPTION );
			return self::recovery_result( 'blocked', false, 'recovery_marker_persist_failed' );
		}

		return self::recovery_result( 'recovered', true, '', array(
			'site_uuid' => $snapshot_uuid,
			'environment' => $environment,
			'canonical_origin' => $origin,
			'owner_user_id' => $owner_user_id,
			'previous_revision' => $snapshot_revision,
			'revision' => absint( $next['revision'] ),
			'recovery_source' => (string) $next['migration_read_continuity_source'],
			'snapshot_format' => $format,
			'write_restored' => false,
			'production_authority_restored' => false,
		) );
	}

	private static function snapshot_format( array $snapshot ) {
		$version = isset( $snapshot['version'] ) ? absint( $snapshot['version'] ) : 0;
		$issuer = isset( $snapshot['issuer'] ) ? trim( (string) $snapshot['issuer'] ) : '';
		$owner = isset( $snapshot['primary_owner_user_id'] ) ? absint( $snapshot['primary_owner_user_id'] ) : ( isset( $snapshot['wp_user_id'] ) ? absint( $snapshot['wp_user_id'] ) : 0 );
		$has_profile_identity = ! empty( $snapshot['site_uuid'] ) || ! empty( $snapshot['canonical_origin'] ) || ! empty( $snapshot['environment'] ) || ! empty( $snapshot['profile_revision'] ) || ! empty( $snapshot['profile_digest'] );
		if ( $has_profile_identity ) return 'profile_bound';
		if ( 1 === $version && $owner > 0 && '' !== $issuer && ! empty( $snapshot['updated_at'] ) ) return 'legacy_zero_touch';
		return 'unsupported';
	}

	private static function legacy_snapshot_site_uuid( $origin, $owner_user_id, $issuer ) {
		$hex = hash( 'sha256', implode( "\n", array( self::CONTRACT, 'legacy-zero-touch-site', (string) $origin, (string) absint( $owner_user_id ), (string) $issuer ) ) );
		if ( ! is_string( $hex ) || strlen( $hex ) < 32 ) return '';
		$hex = substr( strtolower( $hex ), 0, 32 );
		$hex[12] = '5';
		$variant = hexdec( $hex[16] );
		$hex[16] = dechex( ( $variant & 0x3 ) | 0x8 );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	private static function valid_sha256( $value ) {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( (string) $value ) ) );
	}

	private static function read_only_features() {
		return array(
			'oauth' => true,
			'skills' => false,
			'write' => false,
			'production_write_confirmed' => false,
			'provider_isolation' => true,
			'managed_runtime' => true,
			'acceptance' => false,
		);
	}

	public static function guard_reconnect_paths() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) return;
		$path = self::normalize_path( $path );

		if ( self::is_known_oauth_protocol_path( $path ) ) {
			$status = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
			if ( ! empty( $status['effective'] ) ) return;
			$blocker = self::oauth_blocker( $status );
			self::send_json( array(
				'error' => 'temporarily_unavailable',
				'error_description' => 'MAD4B Local OAuth is unavailable (' . $blocker . ').',
				'mad4b_blocker' => $blocker,
				'contract' => self::CONTRACT,
				'resource' => self::resource_identifier(),
			), 503 );
		}

		if ( self::resource_path() === $path ) {
			$profile_blockers = self::profile_reconnect_blockers();
			if ( ! empty( $profile_blockers ) ) {
				self::send_json( array(
					'error' => 'mad4b_mcp_reconnect_not_ready',
					'blockers' => $profile_blockers,
					'contract' => self::CONTRACT,
					'resource' => self::resource_identifier(),
				), 503 );
			}
		}
	}

	public static function is_known_oauth_protocol_path( $path ) {
		$path = self::normalize_path( $path );
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ) return false;
		$urls = array(
			MAD4B_SCP_Local_OAuth_Server::authorize_url(),
			MAD4B_SCP_Local_OAuth_Server::token_url(),
			MAD4B_SCP_Local_OAuth_Server::jwks_url(),
			MAD4B_SCP_Local_OAuth_Server::revocation_url(),
			MAD4B_SCP_Local_OAuth_Server::metadata_url(),
		);
		foreach ( $urls as $url ) {
			$candidate = wp_parse_url( (string) $url, PHP_URL_PATH );
			if ( is_string( $candidate ) && hash_equals( self::normalize_path( $candidate ), $path ) ) return true;
		}
		return false;
	}

	public static function reconnect_status() {
		$profile = class_exists( 'MAD4B_SCP_Site_Profile' ) ? MAD4B_SCP_Site_Profile::status() : array();
		$local = class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) ? MAD4B_SCP_Local_OAuth_Server::status() : array();
		$bridge = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::status() : array();
		$registration = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ? MAD4B_SCP_MCP_Registration_Bridge::status() : array();
		$blockers = self::profile_reconnect_blockers();
		if ( empty( $local['effective'] ) ) $blockers[] = self::oauth_blocker( $local );
		if ( empty( $bridge['effective'] ) ) $blockers[] = 'oauth_resource_bridge_not_effective';
		if ( ! empty( $registration['missed_rest_recovery_blocker'] ) ) $blockers[] = sanitize_key( (string) $registration['missed_rest_recovery_blocker'] );
		if ( isset( $registration['registration_errors'] ) && is_array( $registration['registration_errors'] ) ) {
			foreach ( $registration['registration_errors'] as $error ) if ( is_string( $error ) && '' !== $error ) $blockers[] = sanitize_key( $error );
		}
		$blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
		return array(
			'contract' => self::CONTRACT,
			'ready' => empty( $blockers ),
			'blockers' => $blockers,
			'resource' => self::resource_identifier(),
			'oauth_authorize_url' => untrailingslashit( home_url( '/oauth/mcp/authorize' ) ),
			'protected_resource_metadata_url' => class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url() : '',
			'site_profile' => self::bounded_profile_status( $profile ),
			'local_oauth' => self::bounded_local_oauth_status( $local ),
			'oauth_resource_bridge_effective' => ! empty( $bridge['effective'] ),
			'mcp_registration' => self::bounded_registration_status( $registration ),
			'upgrade_recovery' => self::recovery_status(),
			'write_auto_enabled' => false,
			'production_authority_auto_enabled' => false,
			'breakglass_auto_enabled' => false,
		);
	}

	public static function governance_status() {
		$schema = class_exists( 'MAD4B_SCP_Schema' ) ? MAD4B_SCP_Schema::status( true ) : array();
		$audit = class_exists( 'MAD4B_SCP_Audit' ) ? MAD4B_SCP_Audit::storage_status() : array();
		$physical = isset( $schema['physical_integrity'] ) && is_array( $schema['physical_integrity'] ) ? $schema['physical_integrity'] : array();
		$schema_ready = ! empty( $schema['ready'] ) && ( empty( $physical ) || ! empty( $physical['ready'] ) );
		$audit_ready = ! empty( $audit['ready'] );
		$bootstrap_error = class_exists( 'MAD4B_SCP_Plugin' ) && method_exists( 'MAD4B_SCP_Plugin', 'governance_bootstrap_error_code' ) ? sanitize_key( (string) MAD4B_SCP_Plugin::governance_bootstrap_error_code() ) : '';
		$bootstrap_error_data = class_exists( 'MAD4B_SCP_Plugin' ) && method_exists( 'MAD4B_SCP_Plugin', 'governance_bootstrap_error_data' ) ? MAD4B_SCP_Plugin::governance_bootstrap_error_data() : array();
		if ( ! is_array( $bootstrap_error_data ) ) $bootstrap_error_data = array();
		$kind = '';
		$code = '';
		if ( ! $schema_ready ) {
			$kind = 'schema';
			$code = 0 === strpos( $bootstrap_error, 'mad4b_governance_schema_' ) ? $bootstrap_error : 'mad4b_governance_schema_unavailable';
		} elseif ( ! $audit_ready ) {
			$kind = 'audit';
			$code = 0 === strpos( $bootstrap_error, 'mad4b_audit_' ) ? $bootstrap_error : self::audit_blocker_code( $audit );
		}
		return array(
			'contract' => 'mad4b.governance-bootstrap-status.v1',
			'ready' => $schema_ready && $audit_ready,
			'blocker_kind' => $kind,
			'blocker_code' => $code,
			'schema' => is_array( $schema ) ? $schema : array(),
			'bootstrap_error_data' => $bootstrap_error_data,
			'audit' => self::bounded_audit_status( $audit ),
			'mutation_fail_closed' => ! ( $schema_ready && $audit_ready ),
		);
	}

	public static function replace_ambiguous_governance_notice() {
		remove_action( 'admin_notices', array( 'MAD4B_SCP_Plugin', 'schema_notice' ) );
		if ( ! current_user_can( 'manage_options' ) ) return;
		$status = self::governance_status();
		if ( ! empty( $status['ready'] ) ) return;
		$code = isset( $status['blocker_code'] ) ? sanitize_key( (string) $status['blocker_code'] ) : 'governance_unavailable';
		if ( 'schema' === $status['blocker_kind'] ) {
			$physical = isset( $status['schema']['physical_integrity'] ) && is_array( $status['schema']['physical_integrity'] ) ? $status['schema']['physical_integrity'] : array();
			$error_data = isset( $status['bootstrap_error_data'] ) && is_array( $status['bootstrap_error_data'] ) ? $status['bootstrap_error_data'] : array();
			$details = array();
			if ( isset( $error_data['from_version'], $error_data['target_version'] ) ) $details[] = 'schema=' . (int) $error_data['from_version'] . '→' . (int) $error_data['target_version'];
			foreach ( array(
				'missing_tables' => 'missing_tables',
				'missing_approval_columns' => 'missing_approval_columns',
				'missing_durable_columns' => 'missing_durable_columns',
				'missing_durable_indexes' => 'missing_durable_indexes',
			) as $key => $label ) {
				$items = ! empty( $physical[ $key ] ) && is_array( $physical[ $key ] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $physical[ $key ] ) ) ) : array();
				if ( $items ) $details[] = $label . '=' . implode( ',', array_slice( $items, 0, 12 ) );
			}
			$dbdelta_errors = array();
			foreach ( isset( $error_data['dbdelta_diagnostics'] ) && is_array( $error_data['dbdelta_diagnostics'] ) ? $error_data['dbdelta_diagnostics'] : array() as $row ) {
				if ( ! is_array( $row ) || empty( $row['last_error'] ) ) continue;
				$table = ! empty( $row['table'] ) ? sanitize_key( (string) $row['table'] ) : 'unknown_table';
				$error = sanitize_text_field( substr( trim( (string) $row['last_error'] ), 0, 220 ) );
				if ( '' !== $error ) $dbdelta_errors[] = $table . ':' . $error;
				if ( count( $dbdelta_errors ) >= 3 ) break;
			}
			if ( $dbdelta_errors ) $details[] = 'dbdelta=' . implode( ' | ', $dbdelta_errors );
			$message = 'MAD4B governance schema is unavailable [' . $code . ']. Mutation remains fail-closed.' . ( empty( $details ) ? '' : ' ' . implode( '; ', $details ) . '.' );
		} else {
			$audit = isset( $status['audit'] ) && is_array( $status['audit'] ) ? $status['audit'] : array();
			$flags = array();
			foreach ( array( 'tables_ready', 'transactional', 'legacy_chain_valid', 'head_initialized', 'head_consistent', 'legacy_anchor_match' ) as $key ) if ( array_key_exists( $key, $audit ) ) $flags[] = $key . '=' . ( ! empty( $audit[ $key ] ) ? 'true' : 'false' );
			$message = 'MAD4B append-only audit bootstrap is unavailable [' . $code . ']. Governance mutation remains fail-closed.' . ( empty( $flags ) ? '' : ' ' . implode( '; ', $flags ) . '.' );
		}
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function connection_admin_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( 'mad4b-control-plane-chatgpt' !== $page ) return;
		$status = self::reconnect_status();
		$recovery = self::recovery_status();
		if ( ! empty( $recovery['recovered'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'MAD4B recovered the previously verified read/OAuth connection for this exact non-Production Site Profile. Write authority remains disabled and requires explicit administrator re-enrollment.', 'mad4b-site-control-plane' ) . '</p></div>';
		}
		if ( ! empty( $status['ready'] ) ) return;
		$blockers = ! empty( $status['blockers'] ) ? implode( ', ', array_map( 'sanitize_key', $status['blockers'] ) ) : 'reconnect_not_ready';
		echo '<div class="notice notice-warning inline"><p>' . esc_html( 'ChatGPT reconnect is not ready. Blockers: ' . $blockers . '.' ) . '</p></div>';
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
		self::register_read_ability( 'mad4b/reconnect-readiness', 'MAD4B Reconnect Readiness', 'Inspect Site Profile, OAuth and MCP reconnect readiness without changing authority.', 'reconnect_status' );
		self::register_read_ability( 'mad4b/governance-bootstrap-status', 'MAD4B Governance Bootstrap Status', 'Distinguish governance schema readiness from append-only audit readiness without mutation.', 'governance_status' );
	}

	private static function register_read_ability( $name, $label, $description, $method ) {
		if ( wp_has_ability( $name ) ) return;
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $description,
			'category' => 'mad4b-governance',
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
			'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	private static function profile_reconnect_blockers() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array( 'site_profile_unavailable' );
		$status = MAD4B_SCP_Site_Profile::status();
		$blockers = isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? $status['blockers'] : array();
		if ( empty( $status['configured'] ) ) $blockers[] = 'site_profile_unconfigured';
		if ( empty( $status['environment_match'] ) ) $blockers[] = 'site_profile_environment_drift';
		if ( empty( $status['origin_match'] ) ) $blockers[] = 'site_profile_origin_drift';
		if ( ! MAD4B_SCP_Site_Profile::oauth_enabled() ) $blockers[] = 'site_profile_oauth_disabled';
		if ( ! MAD4B_SCP_Site_Profile::managed_runtime_enabled() ) $blockers[] = 'site_profile_managed_runtime_disabled';
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $blockers ) ) ) );
	}

	private static function oauth_blocker( $status ) {
		$profile = self::profile_reconnect_blockers();
		if ( ! empty( $profile ) ) return $profile[0];
		if ( ! is_array( $status ) || empty( $status ) ) return 'local_oauth_status_unavailable';
		if ( empty( $status['configured'] ) ) return 'local_oauth_disabled';
		if ( empty( $status['issuer_configuration_valid'] ) ) return ! empty( $status['runtime_error'] ) ? sanitize_key( (string) $status['runtime_error'] ) : 'local_oauth_issuer_invalid';
		if ( empty( $status['oauth_store_ready'] ) ) return 'oauth_store_not_ready';
		if ( empty( $status['private_key_present'] ) ) return ! empty( $status['runtime_error'] ) ? sanitize_key( (string) $status['runtime_error'] ) : 'oauth_private_key_not_ready';
		if ( ! empty( $status['runtime_error'] ) ) return sanitize_key( (string) $status['runtime_error'] );
		return 'local_oauth_not_effective';
	}

	private static function audit_blocker_code( $audit ) {
		if ( ! is_array( $audit ) ) return 'mad4b_audit_storage_unavailable';
		if ( empty( $audit['schema_ready'] ) || isset( $audit['schema_physical_ready'] ) && empty( $audit['schema_physical_ready'] ) ) return 'mad4b_audit_schema_unavailable';
		if ( empty( $audit['tables_ready'] ) ) return 'mad4b_audit_storage_unavailable';
		if ( empty( $audit['transactional'] ) ) return 'mad4b_audit_transaction_required';
		if ( empty( $audit['legacy_chain_valid'] ) ) return 'mad4b_audit_legacy_chain_invalid';
		if ( empty( $audit['head_initialized'] ) ) return 'mad4b_audit_head_missing';
		if ( empty( $audit['head_consistent'] ) || isset( $audit['legacy_anchor_match'] ) && empty( $audit['legacy_anchor_match'] ) ) return 'mad4b_audit_legacy_anchor_drift';
		return 'mad4b_audit_unavailable';
	}

	private static function bounded_profile_status( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'configured' => ! empty( $status['configured'] ),
			'source' => isset( $status['source'] ) ? sanitize_key( (string) $status['source'] ) : '',
			'site_uuid' => isset( $status['site_uuid'] ) ? sanitize_text_field( (string) $status['site_uuid'] ) : '',
			'revision' => isset( $status['revision'] ) ? absint( $status['revision'] ) : 0,
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'environment_match' => ! empty( $status['environment_match'] ),
			'origin_match' => ! empty( $status['origin_match'] ),
			'reenrollment_required' => ! empty( $status['reenrollment_required'] ),
			'write_enabled' => ! empty( $status['write_enabled'] ),
			'oauth_enabled' => ! empty( $status['oauth_enabled'] ),
			'blockers' => isset( $status['blockers'] ) && is_array( $status['blockers'] ) ? array_values( array_map( 'sanitize_key', $status['blockers'] ) ) : array(),
		);
	}

	private static function bounded_local_oauth_status( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'configured' => ! empty( $status['configured'] ),
			'effective' => ! empty( $status['effective'] ),
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'issuer' => isset( $status['issuer'] ) ? esc_url_raw( (string) $status['issuer'] ) : '',
			'issuer_configuration_valid' => ! empty( $status['issuer_configuration_valid'] ),
			'private_key_present' => ! empty( $status['private_key_present'] ),
			'oauth_store_ready' => ! empty( $status['oauth_store_ready'] ),
			'runtime_error' => isset( $status['runtime_error'] ) ? sanitize_key( (string) $status['runtime_error'] ) : '',
		);
	}

	private static function bounded_registration_status( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'bridge_booted' => ! empty( $status['bridge_booted'] ),
			'mcp_adapter_init_count' => isset( $status['mcp_adapter_init_count'] ) ? (int) $status['mcp_adapter_init_count'] : 0,
			'rest_api_init_count' => isset( $status['rest_api_init_count'] ) ? (int) $status['rest_api_init_count'] : 0,
			'missed_rest_recovery_state' => isset( $status['missed_rest_recovery_state'] ) ? sanitize_key( (string) $status['missed_rest_recovery_state'] ) : '',
			'missed_rest_recovery_blocker' => isset( $status['missed_rest_recovery_blocker'] ) ? sanitize_key( (string) $status['missed_rest_recovery_blocker'] ) : '',
			'adapter_runtime_from_official_plugin' => ! empty( $status['adapter_runtime_from_official_plugin'] ),
			'registration_errors' => isset( $status['registration_errors'] ) && is_array( $status['registration_errors'] ) ? array_map( 'sanitize_key', $status['registration_errors'] ) : array(),
		);
	}

	private static function bounded_audit_status( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		$out = array();
		foreach ( array( 'ready', 'schema_ready', 'schema_physical_ready', 'tables_ready', 'transactional', 'legacy_chain_valid', 'head_initialized', 'head_consistent', 'legacy_anchor_match' ) as $key ) $out[ $key ] = ! empty( $status[ $key ] );
		foreach ( array( 'legacy_entry_count', 'head_sequence', 'event_count' ) as $key ) $out[ $key ] = isset( $status[ $key ] ) ? (int) $status[ $key ] : 0;
		$out['legacy_anchor_sha256'] = isset( $status['legacy_anchor_sha256'] ) ? sanitize_text_field( (string) $status['legacy_anchor_sha256'] ) : '';
		return $out;
	}

	private static function valid_v2_migration_pending_record( $record ) {
		if ( ! is_array( $record ) || MAD4B_SCP_Site_Profile::CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( MAD4B_SCP_Site_Profile::VERSION !== absint( isset( $record['version'] ) ? $record['version'] : 0 ) ) return false;
		if ( MAD4B_SCP_Site_Profile::LEGACY_CONTRACT !== ( isset( $record['migrated_from_contract'] ) ? (string) $record['migrated_from_contract'] : '' ) ) return false;
		if ( empty( $record['migration_requires_reenrollment'] ) ) return false;
		if ( ! self::valid_uuid( isset( $record['site_uuid'] ) ? $record['site_uuid'] : '' ) ) return false;
		return absint( isset( $record['revision'] ) ? $record['revision'] : 0 ) > 0 && '' !== self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
	}

	private static function valid_legacy_record( $record ) {
		if ( ! is_array( $record ) || MAD4B_SCP_Site_Profile::LEGACY_CONTRACT !== ( isset( $record['contract'] ) ? (string) $record['contract'] : '' ) ) return false;
		if ( MAD4B_SCP_Site_Profile::LEGACY_VERSION !== absint( isset( $record['version'] ) ? $record['version'] : 0 ) ) return false;
		if ( ! self::valid_uuid( isset( $record['site_uuid'] ) ? $record['site_uuid'] : '' ) ) return false;
		return absint( isset( $record['revision'] ) ? $record['revision'] : 0 ) > 0 && '' !== self::normalize_origin( isset( $record['canonical_origin'] ) ? $record['canonical_origin'] : '' );
	}

	private static function recovery_result( $state, $recovered, $blocker, array $extra = array() ) {
		return array_merge( array(
			'contract' => self::CONTRACT,
			'state' => sanitize_key( (string) $state ),
			'recovered' => (bool) $recovered,
			'blocker' => sanitize_key( (string) $blocker ),
			'read_oauth_only' => true,
			'write_restored' => false,
			'production_authority_restored' => false,
			'breakglass_restored' => false,
		), $extra );
	}

	private static function resource_identifier() {
		return class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) ? MAD4B_SCP_OAuth_Resource_Bridge::resource_identifier() : untrailingslashit( rest_url( 'mcp/mad4b-chatgpt' ) );
	}

	private static function resource_path() {
		$path = wp_parse_url( self::resource_identifier(), PHP_URL_PATH );
		return is_string( $path ) ? self::normalize_path( $path ) : '/wp-json/mcp/mad4b-chatgpt';
	}

	private static function send_json( array $payload, $status ) {
		nocache_headers();
		status_header( (int) $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload );
		exit;
	}

	private static function current_environment() {
		return function_exists( 'wp_get_environment_type' ) ? sanitize_key( (string) wp_get_environment_type() ) : 'unknown';
	}

	private static function current_origin() {
		return self::normalize_origin( home_url( '/' ) );
	}

	private static function normalize_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	private static function normalize_origin( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) return '';
		$parts = wp_parse_url( $url );
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
}
