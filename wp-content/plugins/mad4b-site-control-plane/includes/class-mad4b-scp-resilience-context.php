<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Code-owned passive reader. No request, manifest or discovered symbol may select one. */
interface MAD4B_SCP_Resilience_Reader {
	public function read_local( array $binding );
}

/** Exact local identity and passive facts shared by fleet and recovery decisions. */
final class MAD4B_SCP_Resilience_Context {
	const CONTRACT = 'mad4b.resilience-site-observation.v1';
	private static $reader = null;

	/** Bootstrap integration only; deliberately not an Ability or transport target. */
	public static function register_reader( MAD4B_SCP_Resilience_Reader $reader ) {
		if ( null !== self::$reader ) return self::error( 'reader_already_registered', 'A resilience reader is already pinned for this worker.' );
		self::$reader = $reader;
		return true;
	}

	public static function capture() {
		foreach ( array( 'MAD4B_SCP_Site_Profile', 'MAD4B_SCP_Runtime_Generation_Fence', 'MAD4B_SCP_Restore_Epoch' ) as $class ) {
			if ( ! class_exists( $class ) ) return self::error( 'source_unavailable', 'An exact local identity source is unavailable.' );
		}
		$generation = MAD4B_SCP_Runtime_Generation_Fence::status();
		$restore = MAD4B_SCP_Restore_Epoch::status( false, true ); // Never initializes or acknowledges a restore.
		if ( ! is_array( $generation ) || ! is_array( $restore ) ) return self::error( 'source_invalid', 'An exact local identity source returned invalid state.' );
		$identity = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		if ( is_wp_error( $identity ) || ! is_array( $identity ) ) $identity = array();
		$registry = class_exists( 'MAD4B_SCP_Certification_Pack_Registry' ) ? MAD4B_SCP_Certification_Pack_Registry::status() : array();
		if ( is_wp_error( $registry ) || ! is_array( $registry ) ) $registry = array();
		$source_blockers = array();
		if ( true !== ( $identity['identity_ready'] ?? null )
			|| true !== ( $identity['manifest_valid'] ?? null )
			|| ! self::is_hash( $identity['package_manifest_digest'] ?? '' ) )
			$source_blockers[] = 'artifact_identity_unready';
		if ( ! class_exists( 'MAD4B_SCP_Certification_Pack_Registry' )
			|| ( $registry['contract'] ?? '' ) !== MAD4B_SCP_Certification_Pack_Registry::REGISTRY_CONTRACT )
			$source_blockers[] = 'certification_registry_unready';
		if ( ! is_int( $registry['restore_epoch'] ?? null )
			|| $registry['restore_epoch'] !== (int) ( $restore['epoch'] ?? 0 ) )
			$source_blockers[] = 'registry_restore_epoch_mismatch';
		$binding = array(
			'site_uuid'=>(string) MAD4B_SCP_Site_Profile::site_uuid(),
			'blog_id'=>function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'environment'=>(string) MAD4B_SCP_Site_Profile::current_environment(),
			'canonical_origin'=>rtrim( (string) MAD4B_SCP_Site_Profile::current_origin(), '/' ),
			'runtime_generation_sha256'=>(string) ( $generation['generation_sha256'] ?? '' ),
			'artifact_sha256'=>(string) ( $identity['package_manifest_digest'] ?? '' ),
			'site_profile_revision'=>(int) MAD4B_SCP_Site_Profile::revision(),
			'site_profile_sha256'=>(string) MAD4B_SCP_Site_Profile::profile_digest(),
			'registry_revision'=>(int) ( $registry['revision'] ?? -1 ),
			'registry_sha256'=>self::digest( $registry ),
			'restore_epoch'=>(int) ( $restore['epoch'] ?? 0 ),
			'external_record_sha256'=>(string) ( $restore['external_record_sha256'] ?? '' ),
		);
		$valid = self::validate_binding( $binding );
		if ( is_wp_error( $valid ) ) return $valid;
		if ( ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) return self::error( 'origin_not_enrolled', 'Current URLs are not bound to this enrolled Site Profile.' );
		$material = isset( $generation['material'] ) && is_array( $generation['material'] ) ? $generation['material'] : array();
		$base = array(
			'contract'=>self::CONTRACT,
			'binding'=>$binding,
			'binding_sha256'=>self::digest( $binding ),
			'captured_at'=>self::now(),
			'facets'=>array(
				'database'=>self::digest( array( 'schema_installed_version'=>$material['schema_installed_version'] ?? null, 'persisted_contract_registry_sha256'=>$material['persisted_contract_registry_sha256'] ?? '' ) ),
				'files'=>self::digest( array( 'disk_runtime_file_sha256'=>$material['disk_runtime_file_sha256'] ?? '', 'disk_provenance_sha256'=>$material['disk_provenance_sha256'] ?? '', 'config_generation_sha256'=>$material['config_generation_sha256'] ?? '' ) ),
				'runtime_package'=>$binding['artifact_sha256'],
				'site_profile'=>$binding['site_profile_sha256'],
				'registry'=>$binding['registry_sha256'],
			),
			'providers'=>array(), 'host'=>array(), 'health'=>array(), 'gates'=>array(), 'external_effects'=>array(),
			'identity_blockers'=>array_values( array_unique( array_merge( (array) ( $generation['blockers'] ?? array() ), (array) ( $restore['blockers'] ?? array() ), $source_blockers ) ) ),
			'restore_bound'=>true === ( $restore['ready'] ?? null ),
			'worker_current'=>true === ( $generation['ready'] ?? null ),
			'authorizing'=>false, 'mutation_performed'=>false,
		);
		if ( null !== self::$reader ) {
			$observed = self::$reader->read_local( $binding );
			if ( is_wp_error( $observed ) ) return $observed;
			if ( ! is_array( $observed ) || ! isset( $observed['binding_sha256'] ) || ! hash_equals( $base['binding_sha256'], (string) $observed['binding_sha256'] ) ) return self::error( 'reader_binding_mismatch', 'Passive evidence does not identify the exact current local generation.' );
			foreach ( array( 'providers', 'host', 'health', 'gates', 'external_effects' ) as $key ) {
				if ( isset( $observed[ $key ] ) && is_array( $observed[ $key ] ) ) $base[ $key ] = $observed[ $key ];
			}
		}
		// Read grant truth after the passive reader. A reader must not leave a
		// pre-observation grant snapshot looking current after it was revoked.
		$authority = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::current_execution_readiness() : array();
		if ( ! is_array( $authority ) ) $authority = array();
		$candidate = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) ? MAD4B_SCP_Staging_Write_Authority::candidate_binding_status() : array();
		if ( ! is_array( $candidate ) ) $candidate = array();
		$current = self::assert_observation_identity_current( $binding, $generation, $restore, $identity );
		if ( is_wp_error( $current ) ) return $current;
		$base['authority'] = array(
			'site_uuid'=>$binding['site_uuid'], 'environment'=>$binding['environment'],
			'runtime_generation_sha256'=>$binding['runtime_generation_sha256'], 'restore_epoch'=>$binding['restore_epoch'],
			'grant_snapshot_sha256'=>(string) ( $authority['grant_rows_fingerprint'] ?? '' ),
			'candidate_binding_sha256'=>self::digest( $candidate ),
			'eligible'=>true === ( $authority['ready'] ?? null )
				&& true === ( $authority['current_grant_snapshot_ready'] ?? null )
				&& true === ( $authority['candidate_binding_match'] ?? null )
				&& self::is_hash( $authority['grant_rows_fingerprint'] ?? '' )
				&& true === ( $candidate['match'] ?? null )
				&& true === ( $restore['ready'] ?? null ) && true === ( $generation['ready'] ?? null )
				&& empty( $source_blockers ),
		);
		// Runtime observer additions cannot override authority, enrollment or generation truth.
		$base['snapshot_sha256'] = self::snapshot_digest( $base );
		return $base;
	}

	/** Pin passive evidence to the same local sources on both sides of observation. */
	private static function assert_observation_identity_current( array $binding, array $generation, array $restore, array $identity ) {
		$current_generation = MAD4B_SCP_Runtime_Generation_Fence::status();
		$current_restore = MAD4B_SCP_Restore_Epoch::status( false, true );
		$current_identity = class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) ? MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status() : array();
		if ( is_wp_error( $current_identity ) || ! is_array( $current_identity ) ) $current_identity = array();
		$current_registry = class_exists( 'MAD4B_SCP_Certification_Pack_Registry' ) ? MAD4B_SCP_Certification_Pack_Registry::status() : array();
		if ( is_wp_error( $current_registry ) || ! is_array( $current_registry ) ) $current_registry = array();
		if ( ! is_array( $current_generation ) || ! is_array( $current_restore )
			|| ( $current_generation['generation_sha256'] ?? null ) !== $binding['runtime_generation_sha256']
			|| ( $current_generation['ready'] ?? null ) !== ( $generation['ready'] ?? null )
			|| self::digest( $current_generation['blockers'] ?? array() ) !== self::digest( $generation['blockers'] ?? array() )
			|| ( $current_restore['epoch'] ?? null ) !== $binding['restore_epoch']
			|| ( $current_restore['external_record_sha256'] ?? null ) !== $binding['external_record_sha256']
			|| ( $current_restore['ready'] ?? null ) !== ( $restore['ready'] ?? null )
			|| self::digest( $current_restore['blockers'] ?? array() ) !== self::digest( $restore['blockers'] ?? array() )
			|| self::digest( $current_identity ) !== self::digest( $identity )
			|| self::digest( $current_registry ) !== $binding['registry_sha256']
			|| (string) MAD4B_SCP_Site_Profile::site_uuid() !== $binding['site_uuid']
			|| ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ) !== $binding['blog_id']
			|| (string) MAD4B_SCP_Site_Profile::current_environment() !== $binding['environment']
			|| rtrim( (string) MAD4B_SCP_Site_Profile::current_origin(), '/' ) !== $binding['canonical_origin']
			|| (int) MAD4B_SCP_Site_Profile::revision() !== $binding['site_profile_revision']
			|| (string) MAD4B_SCP_Site_Profile::profile_digest() !== $binding['site_profile_sha256']
			|| ! MAD4B_SCP_Site_Profile::origin_enrolled()
			|| ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
			return self::error( 'observation_identity_changed', 'Local identity or readiness changed while passive evidence was being observed.' );
		return true;
	}

	public static function validate_binding( array $binding ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', (string) ( $binding['site_uuid'] ?? '' ) ) || (int) ( $binding['blog_id'] ?? 0 ) < 1 ) return self::error( 'site_identity_invalid', 'Site UUID and blog identity are required.' );
		if ( ! in_array( $binding['environment'] ?? '', array( 'local', 'development', 'staging', 'production' ), true ) ) return self::error( 'environment_invalid', 'An exact environment is required.' );
		$origin = (string) ( $binding['canonical_origin'] ?? '' );
		$parsed = parse_url( $origin );
		if ( ! is_array( $parsed ) || ! in_array( $parsed['scheme'] ?? '', array( 'https', 'http' ), true ) || empty( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) || ( isset( $parsed['path'] ) && '' !== $parsed['path'] ) ) return self::error( 'origin_invalid', 'Canonical origin must be an enrolled HTTP origin without credentials or a path.' );
		foreach ( array( 'runtime_generation_sha256', 'artifact_sha256', 'site_profile_sha256', 'registry_sha256', 'external_record_sha256' ) as $key ) if ( ! self::is_hash( $binding[ $key ] ?? '' ) ) return self::error( 'binding_incomplete', 'Exact artifact, profile, registry, worker and external restore identities are required.' );
		if ( ! is_int( $binding['blog_id'] ) || ! is_int( $binding['restore_epoch'] ?? null ) || ! is_int( $binding['site_profile_revision'] ?? null ) || ! is_int( $binding['registry_revision'] ?? null ) ) return self::error( 'binding_type_invalid', 'Binding revisions and site IDs must be exact integers.' );
		if ( (int) ( $binding['restore_epoch'] ?? 0 ) < 1 || (int) ( $binding['site_profile_revision'] ?? 0 ) < 1 || (int) ( $binding['registry_revision'] ?? -1 ) < 0 ) return self::error( 'binding_revision_invalid', 'Current restore, profile and registry revisions are required.' );
		return true;
	}

	public static function site_key( array $binding ) { return (string) $binding['site_uuid'] . ':' . substr( self::digest( array( $binding['environment'], $binding['canonical_origin'], (int) $binding['blog_id'] ) ), 0, 24 ); }
	public static function snapshot_digest( array $snapshot ) { unset( $snapshot['captured_at'], $snapshot['snapshot_sha256'] ); return self::digest( $snapshot ); }
	public static function digest( $value ) { $json = json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); return is_string( $json ) ? hash( 'sha256', $json ) : ''; }
	public static function is_hash( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	public static function now() { return class_exists( 'MAD4B_SCP_Time_Policy' ) ? (int) MAD4B_SCP_Time_Policy::now_epoch() : time(); }
	private static function canonicalize( $value ) { if ( ! is_array( $value ) ) return $value; if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ksort( $value, SORT_STRING ); foreach ( $value as $key=>$item ) $value[ $key ] = self::canonicalize( $item ); return $value; }
	private static function error( $suffix, $message ) { return new WP_Error( 'mad4b_resilience_' . $suffix, $message, array( 'authorizing'=>false, 'blind_retry_allowed'=>false, 'mutation_performed'=>false ) ); }
}
