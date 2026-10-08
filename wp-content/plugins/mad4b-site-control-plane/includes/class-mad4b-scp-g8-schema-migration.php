<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Additive migration DAG for owned non-authorizing G8 observation registries.
 * Existing authoritative WordPress policy/grant registries are never migrated
 * here. Their semantic transitions require the pre-existing governed machinery.
 */
final class MAD4B_SCP_G8_Schema_Migration {
	const CONTRACT = 'mad4b.g8-schema-migration.v1';
	const OPTION = 'mad4b_scp_g8_schema_migration_v1';
	const MAX_BYTES = 65536;
	const MAX_RECEIPTS = 8;

	private static function steps() {
		return array(
			'registry' => array(
				1 => array( 'to' => 2, 'defaults' => array( 'observation_source' => 'unknown' ) ),
				2 => array( 'to' => 3, 'min_reader_version' => 2, 'defaults' => array( 'compatibility_state' => 'unverified' ) ),
			),
			'manifest' => array(
				1 => array( 'to' => 2, 'defaults' => array( 'supply_status' => 'unverified' ) ),
				2 => array( 'to' => 3, 'min_reader_version' => 2, 'defaults' => array( 'evidence_status' => 'pending' ) ),
			),
			'policy' => array(
				1 => array( 'to' => 2, 'defaults' => array( 'governance_review' => 'required' ) ),
			),
		);
	}

	private static function guard() {
		return class_exists( 'MAD4B_SCP_G8_Record', false ) && MAD4B_SCP_G8_Record::staging()
			&& function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	private static function initial() {
		$binding = MAD4B_SCP_G8_Record::binding();
		if ( is_wp_error( $binding ) ) return $binding;
		$row = array( 'contract' => self::CONTRACT, 'profile_digest' => MAD4B_SCP_G8_Record::profile(),
			'restore_binding' => $binding, 'revision' => 0, 'documents' => array(), 'receipts' => array(), 'authorizing' => false );
		$row['seal'] = MAD4B_SCP_G8_Record::seal( $row );
		return $row;
	}

	private static function valid_receipt( $receipt, array $binding, $revision ) {
		if ( ! is_array( $receipt ) || ! self::name_ok( $receipt['domain'] ?? null )
			|| ! self::doc_ok( $receipt['domain'], $receipt['before'] ?? null )
			|| ! is_string( $receipt['before_sha256'] ?? null )
			|| ! hash_equals( MAD4B_SCP_G8_Record::digest( $receipt['before'] ), $receipt['before_sha256'] )
			|| ! is_string( $receipt['after_sha256'] ?? null )
			|| ! is_string( $receipt['plan_sha256'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $receipt['after_sha256'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $receipt['plan_sha256'] )
			|| ( $receipt['restore_binding'] ?? null ) !== $binding
			|| ! is_int( $receipt['cutover_revision'] ?? null ) || $receipt['cutover_revision'] < 1
			|| $receipt['cutover_revision'] > $revision || false !== ( $receipt['authorizing'] ?? true ) )
			return false;
		return true;
	}

	private static function read_state() {
		$row = MAD4B_SCP_G8_Record::read( self::OPTION );
		if ( is_wp_error( $row ) ) return $row;
		if ( null === $row ) return self::initial();
		$binding = MAD4B_SCP_G8_Record::binding();
		if ( is_wp_error( $binding ) || ! MAD4B_SCP_G8_Record::valid( $row, self::CONTRACT )
			|| ( $row['profile_digest'] ?? '' ) !== MAD4B_SCP_G8_Record::profile()
			|| ( $row['restore_binding'] ?? null ) !== $binding
			|| ! is_int( $row['revision'] ?? null ) || $row['revision'] < 0
			|| ! is_array( $row['documents'] ?? null ) || count( $row['documents'] ) > 32
			|| ! is_array( $row['receipts'] ?? null ) || count( $row['receipts'] ) > self::MAX_RECEIPTS
			|| strlen( serialize( $row ) ) > self::MAX_BYTES )
			return new WP_Error( 'mad4b_g8_migration_state_invalid', 'Migration state is stale, malformed or belongs to another epoch.' );

		foreach ( $row['documents'] as $domain => $doc ) {
			if ( ! self::name_ok( $domain ) || ! self::doc_ok( $domain, $doc ) )
				return new WP_Error( 'mad4b_g8_migration_document_invalid', 'Stored observation document is invalid.' );
		}
		$last_revision = 0;
		foreach ( $row['receipts'] as $receipt ) {
			if ( ! self::valid_receipt( $receipt, $binding, $row['revision'] )
				|| $receipt['cutover_revision'] <= $last_revision )
				return new WP_Error( 'mad4b_g8_migration_receipt_invalid', 'Stored cutover receipt is malformed or replayed.' );
			$last_revision = $receipt['cutover_revision'];
		}
		return $row;
	}

	private static function clean( $value, $depth = 0 ) {
		if ( $depth > 8 || is_object( $value ) || is_resource( $value ) ) return false;
		if ( is_array( $value ) ) {
			if ( count( $value ) > 128 ) return false;
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ( ! is_string( $key ) || 1 !== preg_match( '/^[a-zA-Z0-9_.-]{1,100}$/D', $key )
					|| preg_match( '/password|secret|token|credential|private.key|grant|permission|approval|authority|can_execute|risk_level|is_enabled/i', $key ) ) ) return false;
				if ( ! self::clean( $item, $depth + 1 ) ) return false;
			}
			return true;
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 4096 ) return false;
			// Unknown observation fields are retained, but their VALUES must
			// not bypass the secret guard merely by using an innocuous key.
			if ( class_exists( 'MAD4B_SCP_Structural_Redaction', false )
				&& MAD4B_SCP_Structural_Redaction::sensitive_scalar( $value ) ) return false;
			if ( preg_match( '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|\\bBearer\\s+[A-Za-z0-9._~+\\/-]{12,}|\\b(?:sk-|rk-|ghp_|github_pat_|xox[baprs]-)[A-Za-z0-9_-]{16,}|\\bAKIA[0-9A-Z]{16}\\b/i', $value ) ) return false;
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value )
			|| ( is_float( $value ) && is_finite( $value ) );
	}

	private static function name_ok( $name ) {
		return is_string( $name ) && 1 === preg_match( '/^[a-z0-9_.-]{1,80}$/D', $name );
	}

	private static function doc_ok( $domain, $doc ) {
		return isset( self::steps()[ $domain ] ) && is_array( $doc )
			&& ( $doc['contract'] ?? '' ) === 'mad4b.g8-observation-document.v1'
			&& ( $doc['domain'] ?? '' ) === $domain
			&& is_int( $doc['version'] ?? null ) && $doc['version'] > 0 && $doc['version'] <= 3
			&& is_int( $doc['min_reader_version'] ?? null ) && $doc['min_reader_version'] >= 1
			&& $doc['min_reader_version'] <= $doc['version']
			&& $doc['min_reader_version'] >= max( 1, $doc['version'] - 1 )
			&& is_array( $doc['data'] ?? null ) && self::clean( $doc['data'] )
			&& strlen( serialize( $doc ) ) < 32768 && false === ( $doc['authorizing'] ?? true );
	}

	/** Register only a bounded, non-authorizing owned observation snapshot. */
	public static function register_observation( $domain, array $data, $expected_revision = 0 ) {
		if ( ! self::guard() ) return new WP_Error( 'mad4b_g8_migration_staging_admin_required', 'Staging administrator required.' );
		if ( ! self::name_ok( $domain ) || ! isset( self::steps()[ $domain ] ) || 'policy' === $domain )
			return new WP_Error( 'mad4b_g8_migration_domain_denied', 'Only observation registry or manifest snapshots may be registered.' );
		$before = MAD4B_SCP_G8_Record::read( self::OPTION );
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return $state;
		if ( $state['revision'] !== $expected_revision || isset( $state['documents'][ $domain ] ) )
			return new WP_Error( 'mad4b_g8_migration_revision_conflict', 'Snapshot exists or revision changed.' );
		$doc = array( 'contract' => 'mad4b.g8-observation-document.v1', 'domain' => $domain,
			'version' => 1, 'min_reader_version' => 1, 'data' => $data, 'authorizing' => false );
		if ( ! self::doc_ok( $domain, $doc ) ) return new WP_Error( 'mad4b_g8_migration_document_invalid', 'Observation payload is invalid or contains authority/secrets.' );
		$state['documents'][ $domain ] = $doc;
		++$state['revision']; $state['seal'] = MAD4B_SCP_G8_Record::seal( $state );
		return MAD4B_SCP_G8_Record::replace( self::OPTION, $before, $state );
	}

	/** A deterministic, semantic dry run; never trusts client-provided diff. */
	public static function preview( $domain, $target ) {
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return $state;
		if ( ! self::name_ok( $domain ) || ! isset( $state['documents'][ $domain ] ) )
			return new WP_Error( 'mad4b_g8_migration_document_missing', 'Unknown owned observation document.' );
		$doc = $state['documents'][ $domain ];
		if ( ! self::doc_ok( $domain, $doc ) ) return new WP_Error( 'mad4b_g8_migration_document_invalid', 'Stored observation document invalid.' );
		if ( ! is_int( $target ) || $target <= $doc['version'] || $target > 3 )
			return new WP_Error( 'mad4b_g8_migration_target_invalid', 'Downgrade, no-op or unsupported target generation.' );
		$next = $doc; $path = array(); $added = array(); $step_count = 0;
		while ( $next['version'] < $target ) {
			if ( ++$step_count > 3 ) return new WP_Error( 'mad4b_g8_migration_cycle', 'Bounded migration DAG exceeded.' );
			$step = self::steps()[ $domain ][ $next['version'] ] ?? null;
			if ( ! is_array( $step ) || ! isset( $step['to'] ) || $step['to'] <= $next['version'] || $step['to'] > $target )
				return new WP_Error( 'mad4b_g8_migration_path_missing', 'Version transition is not declared.' );
			foreach ( $step['defaults'] as $field => $value ) {
				if ( ! array_key_exists( $field, $next['data'] ) ) {
					$next['data'][ $field ] = $value; $added[] = $field;
				}
			}
			if ( isset( $step['min_reader_version'] ) )
				$next['min_reader_version'] = max( $next['min_reader_version'], (int) $step['min_reader_version'] );
			$path[] = array( 'from' => $next['version'], 'to' => $step['to'] );
			$next['version'] = $step['to'];
		}
		$before_sha = MAD4B_SCP_G8_Record::digest( $doc );
		$after_sha = MAD4B_SCP_G8_Record::digest( $next );
		$plan = array( 'domain' => $domain, 'from' => $doc['version'], 'to' => $target,
			'revision' => $state['revision'], 'restore_binding' => $state['restore_binding'],
			'before_sha256' => $before_sha, 'after_sha256' => $after_sha, 'path' => $path );
		return array( 'contract' => self::CONTRACT, 'state' => 'DRY_RUN', 'domain' => $domain,
			'path' => $path, 'added_fields' => $added, 'unknown_fields_preserved' => true,
			'prestate_sha256' => $before_sha, 'poststate_sha256' => $after_sha,
			'plan_sha256' => MAD4B_SCP_G8_Record::digest( $plan ), 'current_revision' => $state['revision'],
			'review_required' => 'policy' === $domain, 'automatic_authority_change' => false,
			'next_document' => $next, 'mutation_performed' => false, 'authorizing' => false );
	}

	public static function apply( $domain, $target, $expected_revision, $expected_plan_sha256 ) {
		if ( ! self::guard() ) return new WP_Error( 'mad4b_g8_migration_staging_admin_required', 'Staging administrator required.' );
		if ( 'policy' === $domain ) return new WP_Error( 'mad4b_g8_migration_policy_review_required', 'Policy migration requires separate governed authority.' );
		$before = MAD4B_SCP_G8_Record::read( self::OPTION );
		$plan = self::preview( $domain, $target );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( ! is_int( $expected_revision ) || $expected_revision !== $plan['current_revision']
			|| ! is_string( $expected_plan_sha256 ) || ! hash_equals( $plan['plan_sha256'], $expected_plan_sha256 ) )
			return new WP_Error( 'mad4b_g8_migration_plan_stale', 'Exact dry-run plan or revision changed.' );
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return $state;
		if ( $state['revision'] !== $expected_revision
			|| ! hash_equals( $plan['prestate_sha256'], MAD4B_SCP_G8_Record::digest( $state['documents'][ $domain ] ) ) )
			return new WP_Error( 'mad4b_g8_migration_prestate_conflict', 'Registry changed before cutover.' );
		$receipt = array( 'domain' => $domain, 'before' => $state['documents'][ $domain ],
			'before_sha256' => $plan['prestate_sha256'], 'after_sha256' => $plan['poststate_sha256'],
			'plan_sha256' => $plan['plan_sha256'], 'restore_binding' => $state['restore_binding'],
			'cutover_revision' => $state['revision'] + 1, 'authorizing' => false );
		if ( count( $state['receipts'] ) >= self::MAX_RECEIPTS )
			return new WP_Error( 'mad4b_g8_migration_receipt_capacity', 'Snapshot retention capacity exhausted; do not evict rollback evidence.' );
		$state['documents'][ $domain ] = $plan['next_document'];
		$state['receipts'][] = $receipt;
		++$state['revision']; $state['seal'] = MAD4B_SCP_G8_Record::seal( $state );
		$done = MAD4B_SCP_G8_Record::replace( self::OPTION, $before, $state );
		if ( is_wp_error( $done ) ) return $done;
		return array( 'contract' => self::CONTRACT, 'state' => 'COMMITTED',
			'revision' => $state['revision'], 'plan_sha256' => $plan['plan_sha256'],
			'prestate_sha256' => $receipt['before_sha256'], 'poststate_sha256' => $receipt['after_sha256'],
			'authorizing' => false );
	}

	public static function rollback_last( $expected_revision, $expected_plan_sha256 ) {
		if ( ! self::guard() ) return new WP_Error( 'mad4b_g8_migration_staging_admin_required', 'Staging administrator required.' );
		$before = MAD4B_SCP_G8_Record::read( self::OPTION );
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return $state;
		$receipt = $state['receipts'] ? end( $state['receipts'] ) : null;
		if ( ! is_array( $receipt ) || ! is_int( $expected_revision ) || $state['revision'] !== $expected_revision
			|| ! is_string( $expected_plan_sha256 ) || ! hash_equals( $receipt['plan_sha256'], $expected_plan_sha256 )
			|| ! self::valid_receipt( $receipt, $state['restore_binding'], $state['revision'] )
			|| ( $receipt['restore_binding'] ?? null ) !== $state['restore_binding']
			|| ! isset( $state['documents'][ $receipt['domain'] ] )
			|| ! hash_equals( $receipt['after_sha256'], MAD4B_SCP_G8_Record::digest( $state['documents'][ $receipt['domain'] ] ) ) )
			return new WP_Error( 'mad4b_g8_migration_rollback_stale', 'Current cutover does not match the exact reversible snapshot.' );
		$state['documents'][ $receipt['domain'] ] = $receipt['before'];
		array_pop( $state['receipts'] );
		++$state['revision']; $state['seal'] = MAD4B_SCP_G8_Record::seal( $state );
		$done = MAD4B_SCP_G8_Record::replace( self::OPTION, $before, $state );
		if ( is_wp_error( $done ) ) return $done;
		return array( 'contract' => self::CONTRACT, 'state' => 'ROLLED_BACK',
			'revision' => $state['revision'], 'restored_sha256' => $receipt['before_sha256'], 'authorizing' => false );
	}

	/** Mixed-version readers may inspect data, but this path cannot enable an old worker. */
	public static function view( $domain, $reader_version ) {
		if ( ! self::name_ok( $domain ) ) return new WP_Error( 'mad4b_g8_migration_document_invalid', 'Invalid observation domain.' );
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return $state;
		$doc = $state['documents'][ $domain ] ?? null;
		if ( ! self::doc_ok( $domain, $doc ) ) return new WP_Error( 'mad4b_g8_migration_document_invalid', 'Observation document invalid or missing.' );
		if ( ! is_int( $reader_version ) || $reader_version < $doc['min_reader_version'] || $reader_version > $doc['version'] )
			return new WP_Error( 'mad4b_g8_migration_reader_incompatible', 'Stale worker cannot reinterpret unknown generations.' );
		return array( 'contract' => self::CONTRACT, 'document' => $doc,
			'reader_current' => $reader_version === $doc['version'],
			'mutations_allowed' => false, 'authorizing' => false );
	}

	public static function status() {
		$state = self::read_state();
		if ( is_wp_error( $state ) ) return array( 'contract' => self::CONTRACT, 'state' => 'RECONCILIATION_REQUIRED',
			'reason' => $state->get_error_code(), 'authorizing' => false );
		$documents = array();
		foreach ( $state['documents'] as $name => $doc ) {
			$documents[ $name ] = array( 'version' => $doc['version'], 'min_reader_version' => $doc['min_reader_version'],
				'digest' => MAD4B_SCP_G8_Record::digest( $doc ) );
		}
		return array( 'contract' => self::CONTRACT, 'state' => 'OBSERVED',
			'revision' => $state['revision'], 'documents' => $documents,
			'rollback_receipt_count' => count( $state['receipts'] ), 'authorizing' => false );
	}
}
