<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only runtime evaluator for Feature 007 PARTIAL workstreams.
 *
 * Repository presence is necessary but never sufficient. Live evidence must be
 * recomputed by trusted runtime producers against the exact deployed candidate.
 * Caller-supplied booleans or evidence payloads are intentionally ignored.
 */
final class MAD4B_SCP_Workstream_Certification {
	const CONTRACT = 'mad4b.feature007-workstream-certification-status.v1';
	const POLICY_CONTRACT = 'mad4b.feature007-workstream-certification-policy.v1';
	const ABILITY = 'mad4b/feature-007-workstream-certification-status';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Feature 007 Workstream Certification Status',
				'description' => 'Read-only exact-candidate certification projection for Feature 007 workstreams. Caller evidence cannot close live gates.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'workstream_id' => array( 'type' => 'string', 'maxLength' => 96 ),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$requested = sanitize_key( isset( $input['workstream_id'] ) ? (string) $input['workstream_id'] : '' );
		$policy = self::policy();
		if ( is_wp_error( $policy ) ) return $policy;

		$identity = self::candidate_identity();
		if ( is_wp_error( $identity ) ) return $identity;

		$rows = array();
		foreach ( isset( $policy['workstreams'] ) && is_array( $policy['workstreams'] ) ? $policy['workstreams'] : array() as $definition ) {
			if ( ! is_array( $definition ) ) continue;
			$id = sanitize_key( isset( $definition['id'] ) ? (string) $definition['id'] : '' );
			if ( '' === $id ) continue;
			if ( '' !== $requested && ! hash_equals( $requested, $id ) ) continue;
			$rows[] = self::evaluate( $definition, $identity );
		}
		if ( '' !== $requested && empty( $rows ) ) return new WP_Error( 'mad4b_feature007_workstream_unknown', 'Requested Feature 007 workstream is not registered in certification policy.' );

		$counts = array(
			'total' => count( $rows ),
			'READY' => 0,
			'REPOSITORY_BLOCKED' => 0,
			'LIVE_BLOCKED' => 0,
			'EXTERNAL_EVIDENCE_REQUIRED' => 0,
		);
		foreach ( $rows as $row ) {
			$state = isset( $row['state'] ) ? (string) $row['state'] : 'LIVE_BLOCKED';
			if ( isset( $counts[ $state ] ) ) ++$counts[ $state ];
		}

		return array(
			'contract' => self::CONTRACT,
			'policy_contract' => self::POLICY_CONTRACT,
			'candidate_identity' => $identity,
			'counts' => $counts,
			'items' => $rows,
			'caller_live_evidence_accepted' => false,
			'repository_structure_is_live_certification' => false,
			'production_ready_implied' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function evaluate( array $definition, array $identity ) {
		$id = sanitize_key( isset( $definition['id'] ) ? (string) $definition['id'] : '' );
		$gate = sanitize_key( isset( $definition['gate'] ) ? (string) $definition['gate'] : '' );
		$repo_paths = isset( $definition['repository_paths'] ) && is_array( $definition['repository_paths'] ) ? $definition['repository_paths'] : array();
		$repo = self::repository_evidence( $repo_paths );

		$live = isset( $definition['live_evidence'] ) && is_array( $definition['live_evidence'] ) ? $definition['live_evidence'] : array();
		$live_required = ! empty( $live['required'] );
		$stage = sanitize_key( isset( $live['production_certification_stage'] ) ? (string) $live['production_certification_stage'] : '' );
		$additional = isset( $live['additional_requirements'] ) && is_array( $live['additional_requirements'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $live['additional_requirements'] ) ) ) )
			: array();

		$stage_evidence = array(
			'applicable' => '' !== $stage,
			'stage_id' => $stage,
			'ready' => '' === $stage,
			'blockers' => array(),
			'producer_evidence_sha256' => '',
		);
		if ( '' !== $stage ) {
			if ( ! class_exists( 'MAD4B_SCP_Production_Certification' ) ) {
				$stage_evidence['blockers'][] = 'production_certification_runtime_unavailable';
			} else {
				$result = MAD4B_SCP_Production_Certification::execute( array( 'stage_id' => $stage ) );
				if ( is_wp_error( $result ) ) {
					$stage_evidence['blockers'][] = (string) $result->get_error_code();
				} else {
					$stage_evidence['ready'] = ! empty( $result['ready'] );
					$stage_evidence['blockers'] = isset( $result['blockers'] ) && is_array( $result['blockers'] ) ? array_values( $result['blockers'] ) : array();
					$stage_evidence['producer_evidence_sha256'] = isset( $result['producer_evidence_sha256'] ) ? strtolower( (string) $result['producer_evidence_sha256'] ) : '';
					if ( $stage_evidence['ready'] && 1 !== preg_match( '/^[a-f0-9]{64}$/', $stage_evidence['producer_evidence_sha256'] ) ) {
						$stage_evidence['ready'] = false;
						$stage_evidence['blockers'][] = 'producer_evidence_digest_missing';
					}
				}
			}
		}

		$external = array();
		foreach ( $additional as $requirement ) {
			$external[] = array(
				'requirement' => $requirement,
				'state' => 'EXTERNAL_EVIDENCE_REQUIRED',
				'caller_evidence_accepted' => false,
			);
		}

		$blockers = array();
		foreach ( isset( $repo['missing'] ) ? $repo['missing'] : array() as $path ) $blockers[] = 'repository_path_missing:' . $path;
		foreach ( isset( $repo['symlinks'] ) ? $repo['symlinks'] : array() as $path ) $blockers[] = 'repository_path_symlink:' . $path;
		foreach ( isset( $stage_evidence['blockers'] ) ? $stage_evidence['blockers'] : array() as $blocker ) $blockers[] = 'live_stage:' . sanitize_key( (string) $blocker );
		foreach ( $additional as $requirement ) $blockers[] = 'external_requirement:' . $requirement;

		$state = 'READY';
		if ( empty( $repo['ready'] ) ) {
			$state = 'REPOSITORY_BLOCKED';
		} elseif ( ! empty( $additional ) ) {
			$state = 'EXTERNAL_EVIDENCE_REQUIRED';
		} elseif ( $live_required && ! empty( $stage_evidence['applicable'] ) && empty( $stage_evidence['ready'] ) ) {
			$state = 'LIVE_BLOCKED';
		} elseif ( $live_required && empty( $stage_evidence['applicable'] ) ) {
			$state = 'EXTERNAL_EVIDENCE_REQUIRED';
			$blockers[] = 'external_requirement:trusted_live_producer_required';
		}

		return array(
			'id' => $id,
			'gate' => $gate,
			'state' => $state,
			'repository' => $repo,
			'live_stage' => $stage_evidence,
			'external_requirements' => $external,
			'blockers' => array_values( array_unique( $blockers ) ),
			'candidate_source_commit_sha' => isset( $identity['source_commit_sha'] ) ? (string) $identity['source_commit_sha'] : '',
			'candidate_build_fingerprint' => isset( $identity['build_fingerprint'] ) ? (string) $identity['build_fingerprint'] : '',
			'repository_ready_is_not_live_ready' => true,
			'production_ready_implied' => false,
			'production_authorized' => false,
			'authorizing' => false,
			'mutation_performed' => false,
		);
	}

	private static function repository_evidence( array $paths ) {
		$root = dirname( __DIR__ );
		$present = array();
		$missing = array();
		$symlinks = array();
		$digests = array();
		foreach ( $paths as $relative ) {
			$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '../' ) ) {
				$missing[] = $relative;
				continue;
			}
			$path = $root . '/' . $relative;
			if ( ! file_exists( $path ) ) {
				$missing[] = $relative;
				continue;
			}
			if ( is_link( $path ) ) {
				$symlinks[] = $relative;
				continue;
			}
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$missing[] = $relative;
				continue;
			}
			$present[] = $relative;
			$sha = hash_file( 'sha256', $path );
			$digests[ $relative ] = is_string( $sha ) ? strtolower( $sha ) : '';
		}
		ksort( $digests, SORT_STRING );
		return array(
			'ready' => empty( $missing ) && empty( $symlinks ) && count( $present ) === count( $paths ),
			'declared_path_count' => count( $paths ),
			'present' => $present,
			'missing' => $missing,
			'symlinks' => $symlinks,
			'path_sha256' => $digests,
			'evidence_sha256' => self::digest( $digests ),
			'live_certification' => false,
		);
	}

	private static function candidate_identity() {
		if ( ! class_exists( 'MAD4B_SCP_Live_Acceptance_Observer' ) || ! method_exists( 'MAD4B_SCP_Live_Acceptance_Observer', 'build_provenance_identity_status' ) ) {
			return new WP_Error( 'mad4b_feature007_candidate_identity_unavailable', 'Exact deployed candidate identity producer is unavailable.' );
		}
		$identity = MAD4B_SCP_Live_Acceptance_Observer::build_provenance_identity_status();
		if ( ! is_array( $identity ) || empty( $identity['identity_ready'] ) ) {
			return new WP_Error( 'mad4b_feature007_candidate_identity_not_ready', 'Exact deployed candidate identity is not ready.' );
		}
		foreach ( array( 'source_commit_sha', 'build_fingerprint', 'package_manifest_digest', 'artifact_identity' ) as $field ) {
			if ( empty( $identity[ $field ] ) ) return new WP_Error( 'mad4b_feature007_candidate_identity_incomplete', 'Exact deployed candidate identity is incomplete.', array( 'field' => $field ) );
		}
		return array(
			'identity_ready' => true,
			'source_commit_sha' => strtolower( (string) $identity['source_commit_sha'] ),
			'build_fingerprint' => strtolower( (string) $identity['build_fingerprint'] ),
			'package_manifest_digest' => strtolower( (string) $identity['package_manifest_digest'] ),
			'artifact_identity' => (string) $identity['artifact_identity'],
		);
	}

	private static function policy() {
		$path = dirname( __DIR__ ) . '/config/feature-007-workstream-certification.json';
		if ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_feature007_workstream_policy_unavailable', 'Feature 007 workstream certification policy is unavailable.' );
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || self::POLICY_CONTRACT !== (string) ( isset( $decoded['contract'] ) ? $decoded['contract'] : '' ) ) return new WP_Error( 'mad4b_feature007_workstream_policy_invalid', 'Feature 007 workstream certification policy contract is invalid.' );
		$rules = isset( $decoded['rules'] ) && is_array( $decoded['rules'] ) ? $decoded['rules'] : array();
		if ( empty( $rules['exact_candidate_identity_required'] )
			|| ! array_key_exists( 'caller_live_evidence_accepted', $rules )
			|| false !== $rules['caller_live_evidence_accepted']
			|| empty( $rules['repository_structure_is_not_live_certification'] )
			|| empty( $rules['unknown_live_evidence_fails_closed'] ) ) {
			return new WP_Error( 'mad4b_feature007_workstream_policy_not_fail_closed', 'Feature 007 workstream certification policy does not satisfy fail-closed invariants.' );
		}
		return $decoded;
	}

	private static function digest( $value ) {
		$json = wp_json_encode( self::canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : hash( 'sha256', $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) return $value;
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) $value[ $key ] = self::canonicalize( $item );
		return $value;
	}
}

MAD4B_SCP_Workstream_Certification::boot();
