<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only closure matrix for provider-gated governed write abilities.
 *
 * This class never certifies, mounts or activates a provider capability. It
 * correlates the live write-catalog partition with capability-first provider
 * certification truth so operators and remote agents can discover the exact
 * next safe action without hard-coding vendor/version assumptions.
 */
final class MAD4B_SCP_Provider_Closure_Matrix {
	const CONTRACT = 'mad4b.provider-closure-matrix.v1';
	const ABILITY = 'mad4b/provider-closure-matrix';

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 39 );
		add_filter( 'mad4b_scp_remote_operation_catalog', array( __CLASS__, 'register_operation_catalog_entry' ), 30 );
	}

	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Provider Closure Matrix',
				'description' => 'Correlate live provider-gated governed write abilities with capability certification evidence and the next safe closure action. Read-only and non-authorizing.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'matrix' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'ability' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9._/-]+$' ),
						'provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 96, 'pattern' => '^[A-Za-z0-9._-]+$' ),
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

	public static function register_operation_catalog_entry( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		$rows['provider_closure_matrix'] = array(
			'registrar_id' => 'mad4b-core-provider-certification',
			'source_plugin' => 'mad4b-site-control-plane',
			'trust_class' => 'core',
			'feature_id' => 'provider-certification',
			'capability_tags' => array( 'provider', 'certification', 'write-eligibility', 'closure', 'diagnostics' ),
			'provider' => 'core',
			'status_ability' => self::ABILITY,
			'local_surface' => '',
			'remote_ability' => self::ABILITY,
			'authority_surface' => 'mad4b-read',
			'executor' => 'wordpress_native',
			'remote_mode' => 'read_only_diagnostic',
			'production_policy' => 'read_only',
			'human_decision_required' => false,
		);
		$rows['provider_behavioral_recertification'] = array(
			'registrar_id' => 'mad4b-core-provider-certification',
			'source_plugin' => 'mad4b-site-control-plane',
			'trust_class' => 'core',
			'feature_id' => 'provider-certification',
			'capability_tags' => array( 'provider', 'behavioral', 'recertification', 'rollback', 'write-eligibility' ),
			'provider' => 'core',
			'status_ability' => self::ABILITY,
			'local_surface' => '',
			'remote_ability' => 'mad4b/provider-behavioral-recertify',
			'authority_surface' => 'mad4b-write',
			'executor' => 'wordpress_native',
			'remote_mode' => 'exact_reversible_probe',
			'production_policy' => 'deny',
			'human_decision_required' => true,
		);
		$rows['provider_canary_execution'] = array(
			'registrar_id' => 'mad4b-core-provider-certification',
			'source_plugin' => 'mad4b-site-control-plane',
			'trust_class' => 'core',
			'feature_id' => 'provider-certification',
			'capability_tags' => array( 'provider', 'canary', 'activation', 'high-risk-write', 'rollback' ),
			'provider' => 'core',
			'status_ability' => self::ABILITY,
			'local_surface' => '',
			'remote_ability' => 'mad4b/provider-canary-execute',
			'authority_surface' => 'mad4b-write',
			'executor' => 'wordpress_native',
			'remote_mode' => 'owner_governed_canary',
			'production_policy' => 'deny',
			'human_decision_required' => true,
		);
		return $rows;
	}

	public static function matrix( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$ability_filter = isset( $input['ability'] ) ? trim( (string) $input['ability'] ) : '';
		$provider_filter = isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';

		$blocked = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'blocked_write_tools' )
			? array_values( (array) MAD4B_SCP_Servers::blocked_write_tools() )
			: array();
		$authority_status = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'status' )
			? (array) MAD4B_SCP_Staging_Write_Authority::status()
			: array();
		$binding_status = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && method_exists( 'MAD4B_SCP_Staging_Write_Authority', 'candidate_binding_status' )
			? (array) MAD4B_SCP_Staging_Write_Authority::candidate_binding_status()
			: array();

		$inventory = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
			? MAD4B_SCP_Provider_Compatibility_Certification::inventory()
			: array();
		$providers = isset( $inventory['providers'] ) && is_array( $inventory['providers'] ) ? $inventory['providers'] : array();
		$ability_index = self::ability_index( $providers );

		$items = array();
		foreach ( $blocked as $row ) {
			if ( ! is_array( $row ) ) continue;
			$ability = isset( $row['ability'] ) ? trim( (string) $row['ability'] ) : '';
			$surface_provider = isset( $row['provider'] ) ? sanitize_key( (string) $row['provider'] ) : '';
			$surface_reason = isset( $row['reason'] ) ? sanitize_key( (string) $row['reason'] ) : 'provider_gated';
			if ( '' === $ability ) continue;
			if ( '' !== $ability_filter && $ability !== $ability_filter ) continue;
			if ( '' !== $provider_filter && $surface_provider !== $provider_filter ) {
				$matches_provider = false;
				foreach ( isset( $ability_index[ $ability ] ) ? $ability_index[ $ability ] : array() as $candidate ) {
					if ( $provider_filter === sanitize_key( (string) $candidate['provider_id'] ) ) { $matches_provider = true; break; }
				}
				if ( ! $matches_provider ) continue;
			}

			$candidates = isset( $ability_index[ $ability ] ) ? $ability_index[ $ability ] : array();
			$selection = self::select_candidate( $surface_provider, $candidates );
			$selected = isset( $selection['selected'] ) && is_array( $selection['selected'] ) ? $selection['selected'] : array();
			$ambiguous = ! empty( $selection['ambiguous'] );
			$closure = $ambiguous
				? array(
					'closure_class' => 'ambiguous_provider_capability_mapping',
					'next_action' => 'resolve_provider_capability_mapping',
					'evidence_required' => array( 'surface_provider', 'candidate_provider_ids', 'candidate_capability_ids', 'provider_capability_contract' ),
					'owner_review_required' => false,
				)
				: self::closure_for( $ability, $surface_reason, $selected, $providers );

			$items[] = array(
				'ability' => $ability,
				'surface_provider' => $surface_provider,
				'surface_reason' => $surface_reason,
				'catalog_provider_id' => isset( $selected['provider_id'] ) ? (string) $selected['provider_id'] : '',
				'ambiguous_mapping' => $ambiguous,
				'candidate_count' => count( $candidates ),
				'candidates' => array_values( array_map( static function ( $candidate ) {
					return array(
						'provider_id' => isset( $candidate['provider_id'] ) ? (string) $candidate['provider_id'] : '',
						'capability_id' => isset( $candidate['capability_id'] ) ? (string) $candidate['capability_id'] : '',
					);
				}, $candidates ) ),
				'capability_id' => isset( $selected['capability_id'] ) ? (string) $selected['capability_id'] : '',
				'risk' => isset( $selected['status']['risk'] ) ? (string) $selected['status']['risk'] : 'unknown',
				'certification_level' => isset( $selected['status']['certification_level'] ) ? (string) $selected['status']['certification_level'] : 'UNKNOWN',
				'certification_source' => isset( $selected['status']['certification_source'] ) ? (string) $selected['status']['certification_source'] : 'unknown',
				'activation_stage' => isset( $selected['status']['activation_stage'] ) ? (string) $selected['status']['activation_stage'] : 'shadow',
				'read_eligible' => ! empty( $selected['status']['read_eligible'] ),
				'write_eligible' => ! empty( $selected['status']['write_eligible'] ),
				'reversible' => ! empty( $selected['status']['reversible'] ),
				'canary_eligible' => ! empty( $selected['status']['canary_eligible'] ),
				'artifact_authority_required' => ! empty( $selected['status']['artifact_authority_required'] ),
				'artifact_authority_bound' => ! empty( $selected['status']['artifact_authority_bound'] ),
				'behavioral_evidence_state' => isset( $selected['status']['behavioral_evidence']['state'] ) ? (string) $selected['status']['behavioral_evidence']['state'] : 'unavailable',
				'closure_class' => $closure['closure_class'],
				'next_action' => $closure['next_action'],
				'evidence_required' => $closure['evidence_required'],
				'owner_review_required' => $closure['owner_review_required'],
				'auto_activation_allowed' => false,
			);
		}
		usort( $items, static function ( $a, $b ) { return strcmp( $a['ability'], $b['ability'] ); } );

		$counts = array();
		foreach ( $items as $item ) {
			$key = isset( $item['closure_class'] ) ? (string) $item['closure_class'] : 'unknown';
			$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
		}
		ksort( $counts, SORT_STRING );

		return array(
			'contract' => self::CONTRACT,
			'read_only' => true,
			'authorizing' => false,
			'mutation_performed' => false,
			'provider_gated_count' => count( $items ),
			'closure_class_counts' => $counts,
			'items' => $items,
			'candidate_binding_match' => isset( $binding_status['match'] ) ? (bool) $binding_status['match'] : ( isset( $authority_status['candidate_binding_match'] ) ? (bool) $authority_status['candidate_binding_match'] : false ),
			'write_authority_ready' => isset( $authority_status['ready'] ) ? (bool) $authority_status['ready'] : false,
			'principle' => 'observe_live_gate_then_correlate_capability_truth_then_require_exact_evidence_before_activation',
		);
	}

	private static function ability_index( array $providers ) {
		$index = array();
		foreach ( $providers as $provider_id => $assessment ) {
			foreach ( (array) ( isset( $assessment['capabilities'] ) ? $assessment['capabilities'] : array() ) as $capability_id => $status ) {
				foreach ( (array) ( isset( $status['abilities'] ) ? $status['abilities'] : array() ) as $ability ) {
					$ability = trim( (string) $ability );
					if ( '' === $ability ) continue;
					if ( ! isset( $index[ $ability ] ) ) $index[ $ability ] = array();
					$index[ $ability ][] = array(
						'provider_id' => sanitize_key( (string) $provider_id ),
						'capability_id' => (string) $capability_id,
						'status' => is_array( $status ) ? $status : array(),
					);
				}
			}
		}
		return $index;
	}

	private static function select_candidate( $surface_provider, array $candidates ) {
		if ( empty( $candidates ) ) return array( 'selected' => array(), 'ambiguous' => false );
		$surface_provider = sanitize_key( (string) $surface_provider );
		$exact = array_values( array_filter( $candidates, static function ( $candidate ) use ( $surface_provider ) {
			return '' !== $surface_provider
				&& $surface_provider === sanitize_key( (string) ( isset( $candidate['provider_id'] ) ? $candidate['provider_id'] : '' ) );
		} ) );
		if ( 1 === count( $exact ) ) return array( 'selected' => $exact[0], 'ambiguous' => false );
		if ( count( $exact ) > 1 ) return array( 'selected' => array(), 'ambiguous' => true );
		if ( 1 === count( $candidates ) ) return array( 'selected' => $candidates[0], 'ambiguous' => false );
		return array( 'selected' => array(), 'ambiguous' => true );
	}

	private static function closure_for( $ability, $surface_reason, array $selected, array $providers ) {
		$status = isset( $selected['status'] ) && is_array( $selected['status'] ) ? $selected['status'] : array();
		$provider_id = isset( $selected['provider_id'] ) ? sanitize_key( (string) $selected['provider_id'] ) : '';
		$capability_id = isset( $selected['capability_id'] ) ? (string) $selected['capability_id'] : '';

		$plan_step = array();
		if ( '' !== $provider_id && class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) {
			$plan = MAD4B_SCP_Provider_Compatibility_Certification::recertification_plan( array( 'provider_id' => $provider_id ) );
			foreach ( (array) ( is_array( $plan ) && isset( $plan['steps'] ) ? $plan['steps'] : array() ) as $step ) {
				if ( is_array( $step ) && isset( $step['capability_id'] ) && (string) $step['capability_id'] === $capability_id ) { $plan_step = $step; break; }
			}
		}

		$next = isset( $plan_step['action'] ) ? sanitize_key( (string) $plan_step['action'] ) : '';
		$evidence = array();
		$owner = false;
		$class = 'diagnostic_required';

		if ( empty( $selected['provider_id'] ) || empty( $status ) ) {
			$class = 'adapter_or_catalog_reconciliation';
			$next = 'correlate_adapter_surface_with_provider_capability_catalog';
			$evidence = array( 'adapter_surface', 'provider_capability_contract', 'exact_runtime_version' );
		} elseif ( ! empty( $status['artifact_authority_required'] ) && empty( $status['artifact_authority_bound'] ) ) {
			$class = 'artifact_authority';
			if ( '' === $next ) $next = 'establish_artifact_authority_before_behavioral_recertification';
			$evidence = array( 'exact_package_identity', 'runtime_artifact_fingerprint', 'capability_contract_digest' );
			$owner = true;
		} elseif ( 'provider_runtime_contract_not_certified' === $surface_reason ) {
			$class = 'runtime_contract_certification';
			if ( '' === $next ) $next = 'certify_exact_runtime_contract';
			$evidence = array( 'installed_version', 'runtime_artifact_fingerprint', 'structural_probe', 'capability_contract' );
		} elseif ( ! empty( $status['reversible'] ) && empty( $status['write_eligible'] ) ) {
			$class = 'behavioral_recertification';
			if ( '' === $next ) $next = 'run_behavioral_and_rollback_probe';
			$evidence = array( 'bounded_target', 'before_readback', 'mutation_result', 'after_readback', 'exact_rollback_readback', 'signed_behavioral_receipt' );
			$owner = true;
		} elseif ( 'high_risk_write' === ( isset( $status['risk'] ) ? (string) $status['risk'] : '' ) && 'active' !== ( isset( $status['activation_stage'] ) ? (string) $status['activation_stage'] : 'shadow' ) ) {
			$class = 'canary_activation';
			if ( '' === $next ) $next = 'owner_governed_canary_execution_required';
			$evidence = array( 'behavioral_receipt', 'canary_plan', 'bounded_target', 'rollback_contract', 'owner_approval' );
			$owner = true;
		} elseif ( empty( $status['write_eligible'] ) ) {
			$class = 'capability_write_certification';
			if ( '' === $next ) $next = 'run_behavioral_probe_and_owner_review';
			$evidence = array( 'structural_compatibility', 'behavioral_evidence', 'capability_contract_digest' );
			$owner = true;
		} else {
			$class = 'mount_or_runtime_reconciliation';
			if ( '' === $next ) $next = 'reconcile_runtime_mount_projection';
			$evidence = array( 'write_eligible_capability', 'adapter_mount_projection', 'runtime_catalog_readback' );
		}
		if ( ! empty( $plan_step['action'] ) ) $owner = $owner || in_array( (string) $plan_step['action'], array( 'owner_governed_canary_execution_required', 'run_behavioral_probe_then_owner_authorize_canary', 'run_behavioral_probe_and_owner_review', 'run_behavioral_and_rollback_probe', 'establish_artifact_authority_before_behavioral_recertification' ), true );

		return array(
			'closure_class' => $class,
			'next_action' => $next,
			'evidence_required' => array_values( array_unique( $evidence ) ),
			'owner_review_required' => $owner,
		);
	}
}

MAD4B_SCP_Provider_Closure_Matrix::boot();
