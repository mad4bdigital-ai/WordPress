<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'MAD4B_SCP_Provider_Behavioral_Evidence' ) ) {
	require_once __DIR__ . '/class-mad4b-scp-provider-behavioral-evidence.php';
}

/**
 * Capability-first provider compatibility and certification truth.
 *
 * Artifact identity, structural compatibility, behavioral evidence and runtime
 * activation are intentionally separate authorities. A version/hash match may
 * prove the repository baseline, but it never self-activates high-risk writes.
 */
final class MAD4B_SCP_Provider_Compatibility_Certification {
	const CONTRACT = 'mad4b.provider-compatibility-certification.v1';
	const CATALOG_CONTRACT = 'mad4b.provider-capability-contracts.v1';
	const LEVEL_UNKNOWN = 'UNKNOWN';
	const LEVEL_DISCOVERED = 'DISCOVERED';
	const LEVEL_READ = 'READ_COMPATIBLE';
	const LEVEL_BOUNDED_WRITE = 'BOUNDED_WRITE_COMPATIBLE';
	const LEVEL_REVERSIBLE_WRITE = 'REVERSIBLE_WRITE_CERTIFIED';
	const LEVEL_FULL = 'FULLY_CERTIFIED';
	const LEVEL_QUARANTINED = 'QUARANTINED';
	const ACTIVATION_SHADOW = 'shadow';
	const ACTIVATION_CANARY = 'canary';
	const ACTIVATION_ACTIVE = 'active';

	private static $catalog = null;
	private static $assessment_cache = array();

	public static function boot_early() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 38 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_adapter' ), 22 );
	}

	public static function register_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base' ) ) return;
		$registry->register( new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'provider-compatibility'; }
			public function label() { return 'Provider Compatibility'; }
			public function is_available() { return true; }
			public function ability_names() {
				return array( 'read' => array(
					'mad4b/provider-compatibility-inventory',
					'mad4b/provider-capability-certification',
					'mad4b/provider-behavioral-evidence-status',
					'mad4b/provider-recertification-plan',
					'mad4b/provider-mcp-mount-plan',
				), 'content' => array(), 'admin' => array() );
			}
			public function register_abilities() { MAD4B_SCP_Provider_Compatibility_Certification::register_abilities(); }
			protected function mutation_requires_certification() { return false; }
			protected function provider_certification( $available ) { return null; }
		} );
	}

	public static function clear_request_cache() { self::$assessment_cache = array(); }

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read_ability( 'mad4b/provider-compatibility-inventory', 'Provider Compatibility Inventory', array( __CLASS__, 'inventory' ) );
		self::register_read_ability( 'mad4b/provider-capability-certification', 'Provider Capability Certification', array( __CLASS__, 'capability_certification' ), self::selector_schema( false ) );
		self::register_read_ability( 'mad4b/provider-behavioral-evidence-status', 'Provider Behavioral Evidence Status', array( __CLASS__, 'behavioral_evidence_status' ), self::selector_schema( true ) );
		self::register_read_ability( 'mad4b/provider-recertification-plan', 'Provider Recertification Plan', array( __CLASS__, 'recertification_plan' ), self::selector_schema( true ) );
		self::register_read_ability( 'mad4b/provider-mcp-mount-plan', 'Provider MCP Mount Plan', array( __CLASS__, 'mcp_mount_plan' ) );
	}

	private static function register_read_ability( $name, $label, $callback, $input_schema = null ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) return;
		$args = array(
			'label' => $label,
			'description' => $label . ' from capability-first, fail-closed provider compatibility evidence. This ability never creates mutation or activation authority.',
			'category' => 'mad4b-read',
			'execute_callback' => $callback,
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		);
		if ( is_array( $input_schema ) ) $args['input_schema'] = $input_schema;
		wp_register_ability( $name, $args );
	}

	private static function selector_schema( $provider_required ) {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]+$' ),
				'capability_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[a-z0-9._-]+$' ),
				'ability' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 160, 'pattern' => '^[A-Za-z0-9._/-]+$' ),
			),
			'additionalProperties' => false,
		);
		if ( $provider_required ) $schema['required'] = array( 'provider_id' );
		return $schema;
	}

	public static function supports_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$catalog = self::catalog();
		return '' !== $provider && isset( $catalog['providers'][ $provider ] ) && is_array( $catalog['providers'][ $provider ] );
	}

	public static function inventory() {
		$items = array();
		$registry = self::adapter_registry();
		$catalog = self::catalog();
		foreach ( (array) ( $catalog['providers'] ?? array() ) as $provider => $declaration ) {
			$adapter_id = isset( $declaration['adapter_id'] ) ? sanitize_key( (string) $declaration['adapter_id'] ) : sanitize_key( (string) $provider );
			$adapter = $registry && method_exists( $registry, 'get' ) ? $registry->get( $adapter_id ) : null;
			$items[ $provider ] = self::assess_provider( $provider, $adapter );
		}
		ksort( $items, SORT_STRING );
		return array(
			'contract' => self::CONTRACT,
			'catalog_contract' => isset( $catalog['contract'] ) ? (string) $catalog['contract'] : '',
			'catalog_digest' => self::stable_digest( $catalog ),
			'authorizing' => false,
			'read_only' => true,
			'providers' => $items,
			'provider_count' => count( $items ),
			'principle' => 'artifact_discovery_to_capability_contract_to_trusted_behavioral_evidence_to_governed_mount',
		);
	}

	public static function capability_certification( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		if ( '' === $provider ) return self::blocked_selector( 'provider_id_required' );
		if ( ! self::supports_provider( $provider ) ) return self::blocked_selector( 'provider_not_cataloged', $provider );
		$assessment = self::assess_provider( $provider, self::adapter_for_provider( $provider ) );
		$capability_id = isset( $input['capability_id'] ) ? sanitize_key( str_replace( '.', '_', (string) $input['capability_id'] ) ) : '';
		$ability = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		$matches = array();
		foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $id => $status ) {
			$id_compare = sanitize_key( str_replace( '.', '_', (string) $id ) );
			if ( '' !== $capability_id && $capability_id !== $id_compare ) continue;
			if ( '' !== $ability && ! in_array( $ability, (array) ( $status['abilities'] ?? array() ), true ) ) continue;
			$matches[ $id ] = $status;
		}
		return array(
			'contract' => 'mad4b.provider-capability-certification-result.v1',
			'provider_id' => $provider,
			'compatibility_state' => isset( $assessment['compatibility_state'] ) ? $assessment['compatibility_state'] : 'unknown',
			'artifact' => isset( $assessment['artifact'] ) ? $assessment['artifact'] : array(),
			'capabilities' => $matches,
			'match_count' => count( $matches ),
			'authorizing' => false,
			'read_only' => true,
		);
	}

	public static function behavioral_evidence_status( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		if ( '' === $provider ) return self::blocked_selector( 'provider_id_required' );
		if ( ! self::supports_provider( $provider ) ) return self::blocked_selector( 'provider_not_cataloged', $provider );
		$assessment = self::assess_provider( $provider, self::adapter_for_provider( $provider ) );
		$capability_selector = isset( $input['capability_id'] ) ? sanitize_key( str_replace( '.', '_', (string) $input['capability_id'] ) ) : '';
		$ability = isset( $input['ability'] ) ? (string) $input['ability'] : '';
		$items = array();
		foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $id => $capability ) {
			if ( '' !== $capability_selector && $capability_selector !== sanitize_key( str_replace( '.', '_', (string) $id ) ) ) continue;
			if ( '' !== $ability && ! in_array( $ability, (array) ( $capability['abilities'] ?? array() ), true ) ) continue;
			$items[ $id ] = isset( $capability['behavioral_evidence'] ) ? $capability['behavioral_evidence'] : array();
		}
		return array(
			'contract' => 'mad4b.provider-behavioral-evidence-status.v1',
			'provider_id' => $provider,
			'artifact_fingerprint' => isset( $assessment['artifact']['runtime_artifact_fingerprint'] ) ? $assessment['artifact']['runtime_artifact_fingerprint'] : '',
			'capabilities' => $items,
			'match_count' => count( $items ),
			'authorizing' => false,
			'activation_granted' => false,
			'mutation_granted' => false,
			'read_only' => true,
		);
	}

	public static function recertification_plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$provider = isset( $input['provider_id'] ) ? sanitize_key( (string) $input['provider_id'] ) : '';
		if ( '' === $provider ) return self::blocked_selector( 'provider_id_required' );
		if ( ! self::supports_provider( $provider ) ) return self::blocked_selector( 'provider_not_cataloged', $provider );
		$assessment = self::assess_provider( $provider, self::adapter_for_provider( $provider ) );
		$steps = array();
		$owner_review = false;
		$quarantined = false;
		$behavioral_recertified = false;
		foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $id => $capability ) {
			$level = isset( $capability['certification_level'] ) ? (string) $capability['certification_level'] : self::LEVEL_UNKNOWN;
			$risk = isset( $capability['risk'] ) ? (string) $capability['risk'] : 'unknown';
			$activation_stage = isset( $capability['activation_stage'] ) ? (string) $capability['activation_stage'] : self::ACTIVATION_SHADOW;
			$surface_exposed = ! empty( $capability['surface_exposed'] );
			if ( ! $surface_exposed ) {
				$steps[] = array(
					'capability_id' => $id,
					'action' => self::LEVEL_QUARANTINED === $level ? 'record_latent_structural_drift' : 'record_unmounted_capability_state',
					'risk' => $risk,
					'activation_stage' => $activation_stage,
					'surface_exposed' => false,
				);
				continue;
			}
			if ( empty( $assessment['available'] ) ) {
				$steps[] = array( 'capability_id' => $id, 'action' => 'adapter_runtime_reconciliation_required', 'risk' => $risk, 'activation_stage' => $activation_stage, 'surface_exposed' => true );
				$quarantined = true;
				continue;
			}
			if ( self::LEVEL_QUARANTINED === $level ) {
				$steps[] = array( 'capability_id' => $id, 'action' => 'structural_reconciliation_required', 'risk' => $risk, 'activation_stage' => $activation_stage, 'surface_exposed' => true );
				$quarantined = true;
				continue;
			}
			if ( 'read' !== $risk && ! empty( $capability['artifact_authority_required'] ) ) {
				$steps[] = array( 'capability_id' => $id, 'action' => 'establish_artifact_authority_before_behavioral_recertification', 'risk' => $risk, 'activation_stage' => $activation_stage, 'surface_exposed' => true );
				$owner_review = true;
				continue;
			}
			if ( 'read' === $risk && self::LEVEL_READ === $level ) {
				$steps[] = array( 'capability_id' => $id, 'action' => 'record_compatible_read_candidate', 'risk' => $risk, 'activation_stage' => $activation_stage );
				continue;
			}
			if ( 'behavioral_receipt' === (string) ( $capability['certification_source'] ?? '' ) && ! empty( $capability['write_eligible'] ) ) $behavioral_recertified = true;
			if ( 'high_risk_write' === $risk && self::ACTIVATION_ACTIVE !== $activation_stage ) {
				$action = self::ACTIVATION_CANARY === $activation_stage ? 'owner_governed_canary_execution_required' : 'run_behavioral_probe_then_owner_authorize_canary';
				$steps[] = array( 'capability_id' => $id, 'action' => $action, 'risk' => $risk, 'activation_stage' => $activation_stage, 'canary_eligible' => ! empty( $capability['canary_eligible'] ) );
				$owner_review = true;
				continue;
			}
			if ( 'read' !== $risk && empty( $capability['write_eligible'] ) ) {
				$steps[] = array( 'capability_id' => $id, 'action' => ! empty( $capability['reversible'] ) ? 'run_behavioral_and_rollback_probe' : 'run_behavioral_probe_and_owner_review', 'risk' => $risk, 'activation_stage' => $activation_stage );
				$owner_review = true;
			}
		}
		$classification = $quarantined ? 'QUARANTINED' : ( $owner_review ? 'OWNER_REVIEW_REQUIRED' : ( $behavioral_recertified ? 'BEHAVIORALLY_RECERTIFIED' : ( 'certified' === (string) ( $assessment['compatibility_state'] ?? '' ) ? 'CERTIFIED' : 'AUTO_CERTIFIABLE_READ_COMPATIBILITY' ) ) );
		return array(
			'contract' => 'mad4b.provider-recertification-plan.v1',
			'provider_id' => $provider,
			'classification' => $classification,
			'compatibility_state' => isset( $assessment['compatibility_state'] ) ? $assessment['compatibility_state'] : 'unknown',
			'artifact_fingerprint' => isset( $assessment['artifact']['runtime_artifact_fingerprint'] ) ? $assessment['artifact']['runtime_artifact_fingerprint'] : '',
			'structural_fingerprint' => isset( $assessment['structural_fingerprint'] ) ? $assessment['structural_fingerprint'] : '',
			'steps' => $steps,
			'owner_review_required' => $owner_review,
			'authorizing' => false,
			'read_only' => true,
		);
	}

	public static function mcp_mount_plan() {
		$inventory = self::inventory();
		$eligible = array();
		$blocked = array();
		$latent = array();
		foreach ( (array) ( $inventory['providers'] ?? array() ) as $provider => $assessment ) {
			foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $capability_id => $capability ) {
				$mounted_abilities = array_fill_keys( (array) ( $capability['mounted_abilities'] ?? array() ), true );
				foreach ( (array) ( $capability['abilities'] ?? array() ) as $ability ) {
					$entry = array(
						'provider' => $provider,
						'capability_id' => $capability_id,
						'ability' => $ability,
						'certification_level' => isset( $capability['certification_level'] ) ? $capability['certification_level'] : self::LEVEL_UNKNOWN,
						'certification_source' => isset( $capability['certification_source'] ) ? $capability['certification_source'] : 'unknown',
						'risk' => isset( $capability['risk'] ) ? $capability['risk'] : 'unknown',
						'activation_stage' => isset( $capability['activation_stage'] ) ? $capability['activation_stage'] : self::ACTIVATION_SHADOW,
						'activation_required' => ! empty( $capability['activation_required'] ),
						'canary_eligible' => ! empty( $capability['canary_eligible'] ),
						'behavioral_evidence_state' => isset( $capability['behavioral_evidence']['state'] ) ? $capability['behavioral_evidence']['state'] : 'not_applicable',
					);
					if ( ! isset( $mounted_abilities[ (string) $ability ] ) ) {
						$entry['surface'] = 'latent';
						$entry['eligible'] = false;
						$entry['reason'] = 'ability_not_declared_by_adapter_surface';
						$latent[] = $entry;
						continue;
					}
					if ( 'read' === $entry['risk'] ) {
						$entry['surface'] = 'read';
						$entry['eligible'] = ! empty( $capability['read_eligible'] );
					} else {
						$entry['surface'] = 'write';
						$entry['eligible'] = ! empty( $capability['write_eligible'] );
					}
					if ( $entry['eligible'] ) $eligible[] = $entry; else $blocked[] = $entry;
				}
			}
		}
		return array(
			'contract' => 'mad4b.provider-mcp-mount-plan.v1',
			'authorizing' => false,
			'read_only' => true,
			'eligible' => $eligible,
			'blocked' => $blocked,
			'eligible_count' => count( $eligible ),
			'blocked_count' => count( $blocked ),
			'latent' => $latent,
			'latent_count' => count( $latent ),
			'note' => 'Behavioral recertification is per-capability and artifact-bound. Only abilities actually declared by the adapter surface participate in mount eligibility; catalog-only latent capabilities are reported separately. High-risk writes never become active from behavioral evidence alone; canary/active promotion remains separately governed. Execution still requires environment policy, exact NHI grants, approval, budgets, stale-state guards and authorization.',
		);
	}

	public static function ability_status( $provider, $ability_name, $adapter = null ) {
		$provider = sanitize_key( (string) $provider );
		$ability_name = (string) $ability_name;
		if ( ! self::supports_provider( $provider ) ) return array();
		$assessment = self::assess_provider( $provider, $adapter ?: self::adapter_for_provider( $provider ) );
		foreach ( (array) ( $assessment['capabilities'] ?? array() ) as $capability_id => $capability ) {
			if ( in_array( $ability_name, (array) ( $capability['abilities'] ?? array() ), true ) ) {
				$capability['provider'] = $provider;
				$capability['capability_id'] = $capability_id;
				$capability['compatibility_state'] = isset( $assessment['compatibility_state'] ) ? $assessment['compatibility_state'] : 'unknown';
				$capability['artifact'] = isset( $assessment['artifact'] ) ? $assessment['artifact'] : array();
				return $capability;
			}
		}
		return array();
	}

	public static function adapter_mount_projection( $provider, $adapter = null ) {
		$provider = sanitize_key( (string) $provider );
		if ( ! self::supports_provider( $provider ) ) {
			return array( 'provider_id' => $provider, 'cataloged' => false, 'write_abilities' => array(), 'eligible' => array(), 'blocked' => array(), 'all_write_abilities_eligible' => false, 'authorizing' => false );
		}
		if ( ! is_object( $adapter ) ) $adapter = self::adapter_for_provider( $provider );
		$map = is_object( $adapter ) && method_exists( $adapter, 'ability_names' ) ? $adapter->ability_names() : array();
		$write_abilities = array();
		foreach ( array( 'content', 'admin' ) as $surface ) {
			foreach ( (array) ( isset( $map[ $surface ] ) ? $map[ $surface ] : array() ) as $ability ) {
				$ability = (string) $ability;
				if ( '' !== $ability ) $write_abilities[] = $ability;
			}
		}
		$write_abilities = array_values( array_unique( $write_abilities ) );
		$eligible = array();
		$blocked = array();
		foreach ( $write_abilities as $ability ) {
			$status = self::ability_status( $provider, $ability, $adapter );
			$entry = array(
				'ability' => $ability,
				'capability_id' => isset( $status['capability_id'] ) ? (string) $status['capability_id'] : '',
				'certification_level' => isset( $status['certification_level'] ) ? (string) $status['certification_level'] : self::LEVEL_UNKNOWN,
				'certification_source' => isset( $status['certification_source'] ) ? (string) $status['certification_source'] : 'unknown',
				'risk' => isset( $status['risk'] ) ? (string) $status['risk'] : 'unknown',
				'activation_stage' => isset( $status['activation_stage'] ) ? (string) $status['activation_stage'] : self::ACTIVATION_SHADOW,
				'canary_eligible' => ! empty( $status['canary_eligible'] ),
				'behavioral_evidence_state' => isset( $status['behavioral_evidence']['state'] ) ? (string) $status['behavioral_evidence']['state'] : 'not_applicable',
			);
			if ( ! empty( $status['write_eligible'] ) ) $eligible[ $ability ] = $entry;
			else {
				$entry['reason'] = empty( $status ) ? 'ability_capability_not_cataloged' : ( 'high_risk_write' === $entry['risk'] ? 'high_risk_activation_required' : 'capability_write_certification_required' );
				$blocked[ $ability ] = $entry;
			}
		}
		return array(
			'provider_id' => $provider,
			'cataloged' => true,
			'write_abilities' => $write_abilities,
			'eligible' => array_values( $eligible ),
			'blocked' => array_values( $blocked ),
			'all_write_abilities_eligible' => ! empty( $write_abilities ) && empty( $blocked ),
			'authorizing' => false,
		);
	}

	public static function mutation_guard( $provider, $ability_name, $available = null, $adapter = null ) {
		$provider = sanitize_key( (string) $provider );
		$status = self::ability_status( $provider, $ability_name, $adapter );
		if ( ! empty( $status['write_eligible'] ) ) return true;
		$violations = array();
		if ( empty( $status ) ) $violations[] = 'ability_capability_not_cataloged';
		else {
			if ( ! empty( $status['artifact_authority_required'] ) ) $violations[] = 'artifact_authority_required';
			if ( empty( $status['structural_compatible'] ) ) $violations[] = 'structural_contract_not_satisfied';
			if ( isset( $status['certification_level'] ) && in_array( $status['certification_level'], array( self::LEVEL_DISCOVERED, self::LEVEL_READ, self::LEVEL_UNKNOWN ), true ) ) $violations[] = 'write_behavioral_certification_required';
			if ( 'high_risk_write' === (string) ( $status['risk'] ?? '' ) && self::ACTIVATION_ACTIVE !== (string) ( $status['activation_stage'] ?? self::ACTIVATION_SHADOW ) ) $violations[] = 'high_risk_activation_required';
			if ( self::LEVEL_QUARANTINED === (string) ( $status['certification_level'] ?? '' ) ) $violations[] = 'capability_quarantined';
		}
		return new WP_Error(
			'mad4b_provider_capability_mutation_not_certified',
			'Provider mutation is denied until this exact ability reaches a governed write certification and activation level.',
			array( 'provider' => $provider, 'ability' => (string) $ability_name, 'violations' => array_values( array_unique( $violations ) ), 'capability_status' => $status )
		);
	}

	public static function assess_provider( $provider, $adapter = null ) {
		$provider = sanitize_key( (string) $provider );
		if ( '' === $provider || ! self::supports_provider( $provider ) ) return array();
		$cache_key = $provider . ':' . ( is_object( $adapter ) ? spl_object_hash( $adapter ) : 'none' );
		if ( isset( self::$assessment_cache[ $cache_key ] ) ) return self::$assessment_cache[ $cache_key ];

		$catalog = self::catalog();
		$declaration = isset( $catalog['providers'][ $provider ] ) && is_array( $catalog['providers'][ $provider ] ) ? $catalog['providers'][ $provider ] : array();
		$available = is_object( $adapter ) && method_exists( $adapter, 'is_available' ) ? (bool) $adapter->is_available() : false;
		$runtime = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::runtime_status( $provider, $available ) : array( 'status' => 'certification_authority_unavailable', 'runtime_contract_ok' => false );
		$exact_certified = ! empty( $runtime['runtime_contract_ok'] );
		$artifact = self::artifact_evidence( $provider, $runtime, $adapter );
		$artifact_fingerprint = isset( $artifact['runtime_artifact_fingerprint'] ) ? (string) $artifact['runtime_artifact_fingerprint'] : '';
		$artifact_authority_bound = ! empty( $artifact['artifact_authority_bound'] );
		$adapter_ability_names = array();
		if ( is_object( $adapter ) && method_exists( $adapter, 'ability_names' ) ) {
			$adapter_map = $adapter->ability_names();
			foreach ( array( 'read', 'content', 'write', 'admin' ) as $surface ) {
				if ( ! isset( $adapter_map[ $surface ] ) || ! is_array( $adapter_map[ $surface ] ) ) continue;
				foreach ( $adapter_map[ $surface ] as $ability_name ) {
					$ability_name = (string) $ability_name;
					if ( '' !== $ability_name ) $adapter_ability_names[ $ability_name ] = true;
				}
			}
		}
		$capabilities = array();
		$exposed_capabilities = array();
		$exposed_compatible = array();
		$exposed_incompatibilities = array();
		$latent_capabilities = array();
		$latent_incompatibilities = array();
		foreach ( (array) ( $declaration['capabilities'] ?? array() ) as $capability_id => $capability ) {
			$probes = self::evaluate_probes( (array) ( $capability['probes'] ?? array() ), $adapter );
			$structural = ! empty( $probes['compatible'] );
			$declared_abilities = array_values( array_unique( array_map( 'strval', (array) ( $capability['abilities'] ?? array() ) ) ) );
			$mounted_abilities = array_values( array_filter( $declared_abilities, static function ( $ability_name ) use ( $adapter_ability_names ) {
				return isset( $adapter_ability_names[ (string) $ability_name ] );
			} ) );
			$surface_exposed = ! empty( $mounted_abilities );
			if ( $surface_exposed ) {
				$exposed_capabilities[] = (string) $capability_id;
				if ( $structural ) $exposed_compatible[] = (string) $capability_id;
				else $exposed_incompatibilities[] = (string) $capability_id;
			} else {
				$latent_capabilities[] = (string) $capability_id;
				if ( ! $structural ) $latent_incompatibilities[] = (string) $capability_id;
			}
			$risk = isset( $capability['risk'] ) ? sanitize_key( (string) $capability['risk'] ) : 'read';
			$reversible = ! empty( $capability['reversible'] );
			$capability_contract_digest = self::stable_digest( $capability );
			if ( 'read' === $risk ) {
				$behavioral = self::not_applicable_behavioral_evidence( $provider, $capability_id, $artifact_fingerprint, $capability_contract_digest );
			} elseif ( ! $artifact_authority_bound ) {
				$behavioral = self::artifact_authority_required_behavioral_evidence( $provider, $capability_id, $artifact_fingerprint, $capability_contract_digest );
			} else {
				$behavioral = MAD4B_SCP_Provider_Behavioral_Evidence::status( array(
					'provider_id' => $provider,
					'capability_id' => $capability_id,
					'artifact_fingerprint' => $artifact_fingerprint,
					'capability_contract_digest' => $capability_contract_digest,
					'risk' => $risk,
					'reversible' => $reversible,
				) );
			}
			$level = self::level_for( $available, $structural, $exact_certified, $risk, $reversible, $behavioral, $artifact_authority_bound );
			$activation_stage = self::activation_stage_for( $risk, $level, $behavioral );
			$write_level_eligible = in_array( $level, array( self::LEVEL_BOUNDED_WRITE, self::LEVEL_REVERSIBLE_WRITE, self::LEVEL_FULL ), true );
			$behavioral_verified = ! empty( $behavioral['behavioral_verified'] );
			$rollback_verified = ! empty( $behavioral['rollback_verified'] );
			$certification_source = 'structural_only';
			if ( 'read' !== $risk && ! $artifact_authority_bound ) $certification_source = 'artifact_authority_missing';
			elseif ( $exact_certified && 'high_risk_write' !== $risk ) $certification_source = 'repository_exact_baseline';
			elseif ( 'read' !== $risk && $write_level_eligible && $behavioral_verified ) $certification_source = 'behavioral_receipt';
			elseif ( 'read' === $risk ) $certification_source = $exact_certified ? 'repository_exact_baseline' : 'structural_compatibility';
			$capabilities[ $capability_id ] = array(
				'capability_id' => $capability_id,
				'capability_contract_digest' => $capability_contract_digest,
				'risk' => $risk,
				'abilities' => $declared_abilities,
				'mounted_abilities' => $mounted_abilities,
				'surface_exposed' => $surface_exposed,
				'reversible' => $reversible,
				'rollback_contract' => isset( $capability['rollback_contract'] ) ? (string) $capability['rollback_contract'] : '',
				'structural_compatible' => $structural,
				'structural_probes' => $probes['probes'],
				'certification_level' => $level,
				'certification_source' => $certification_source,
				'activation_stage' => $activation_stage,
				'activation_required' => 'high_risk_write' === $risk,
				'canary_eligible' => 'high_risk_write' === $risk && $behavioral_verified,
				'owner_promotion_required' => 'high_risk_write' === $risk,
				'read_eligible' => 'read' === $risk && in_array( $level, array( self::LEVEL_READ, self::LEVEL_FULL ), true ),
				'artifact_authority_bound' => $artifact_authority_bound,
				'artifact_authority_required' => 'read' !== $risk && ! $artifact_authority_bound,
				'write_eligible' => 'read' !== $risk && $artifact_authority_bound && $write_level_eligible && self::ACTIVATION_ACTIVE === $activation_stage,
				'behavioral_evidence' => $behavioral,
				'behavioral_probe_required' => 'read' !== $risk && $artifact_authority_bound && ! $behavioral_verified && ( ! $exact_certified || 'high_risk_write' === $risk ),
				'rollback_probe_required' => 'read' !== $risk && $artifact_authority_bound && $reversible && ! $exact_certified && ! $rollback_verified,
			);
		}
		$installed_artifact_present = ! empty( $artifact['installed_version'] );
		if ( ! $available ) {
			$compatibility_state = $installed_artifact_present ? 'adapter_runtime_unavailable' : 'unavailable';
		} elseif ( ! empty( $exposed_incompatibilities ) ) {
			$compatibility_state = ! empty( $exposed_compatible ) ? 'partially_compatible' : 'breaking_contract_change';
		} elseif ( $exact_certified ) {
			$compatibility_state = 'certified';
		} else {
			$compatibility_state = 'compatible_unattested';
		}
		$structural_fingerprint = self::stable_digest( array_map( static function ( $item ) {
			return array(
				'structural_compatible' => ! empty( $item['structural_compatible'] ),
				'surface_exposed' => ! empty( $item['surface_exposed'] ),
				'mounted_abilities' => isset( $item['mounted_abilities'] ) ? $item['mounted_abilities'] : array(),
				'probes' => isset( $item['structural_probes'] ) ? $item['structural_probes'] : array(),
			);
		}, $capabilities ) );
		$result = array(
			'provider_id' => $provider,
			'adapter_id' => isset( $declaration['adapter_id'] ) ? sanitize_key( (string) $declaration['adapter_id'] ) : $provider,
			'available' => $available,
			'compatibility_state' => $compatibility_state,
			'exact_runtime_certified' => $exact_certified,
			'artifact' => $artifact,
			'structural_fingerprint' => $structural_fingerprint,
			'structural_scope' => array(
				'exposed_capability_count' => count( $exposed_capabilities ),
				'exposed_compatible_count' => count( $exposed_compatible ),
				'exposed_incompatibility_count' => count( $exposed_incompatibilities ),
				'exposed_capabilities' => $exposed_capabilities,
				'exposed_incompatibilities' => $exposed_incompatibilities,
				'latent_capability_count' => count( $latent_capabilities ),
				'latent_incompatibility_count' => count( $latent_incompatibilities ),
				'latent_capabilities' => $latent_capabilities,
				'latent_incompatibilities' => $latent_incompatibilities,
			),
			'capabilities' => $capabilities,
			'authority' => array( 'authorizing' => false, 'mutation_granted' => false, 'activation_granted' => false, 'production_activation' => false ),
		);
		self::$assessment_cache[ $cache_key ] = $result;
		return $result;
	}

	private static function level_for( $available, $structural, $exact_certified, $risk, $reversible, array $behavioral, $artifact_authority_bound = true ) {
		if ( ! $available ) return self::LEVEL_UNKNOWN;
		if ( ! $structural ) return self::LEVEL_QUARANTINED;
		if ( 'read' === $risk ) return $exact_certified ? self::LEVEL_FULL : self::LEVEL_READ;
		if ( ! $artifact_authority_bound ) return self::LEVEL_DISCOVERED;
		if ( 'high_risk_write' === $risk ) return self::LEVEL_DISCOVERED;
		if ( $exact_certified ) return $reversible ? self::LEVEL_REVERSIBLE_WRITE : ( 'bounded_write' === $risk ? self::LEVEL_BOUNDED_WRITE : self::LEVEL_DISCOVERED );
		if ( empty( $behavioral['behavioral_verified'] ) ) return self::LEVEL_DISCOVERED;
		if ( $reversible && empty( $behavioral['rollback_verified'] ) ) return self::LEVEL_DISCOVERED;
		if ( $reversible ) return self::LEVEL_REVERSIBLE_WRITE;
		return 'bounded_write' === $risk ? self::LEVEL_BOUNDED_WRITE : self::LEVEL_DISCOVERED;
	}

	private static function activation_stage_for( $risk, $level, array $behavioral ) {
		if ( 'read' === $risk ) return self::ACTIVATION_ACTIVE;
		if ( 'high_risk_write' === $risk ) return ! empty( $behavioral['behavioral_verified'] ) ? self::ACTIVATION_CANARY : self::ACTIVATION_SHADOW;
		return in_array( $level, array( self::LEVEL_BOUNDED_WRITE, self::LEVEL_REVERSIBLE_WRITE, self::LEVEL_FULL ), true ) ? self::ACTIVATION_ACTIVE : self::ACTIVATION_SHADOW;
	}

	private static function not_applicable_behavioral_evidence( $provider, $capability_id, $artifact_fingerprint, $capability_contract_digest ) {
		return array(
			'contract' => MAD4B_SCP_Provider_Behavioral_Evidence::CONTRACT,
			'provider_id' => (string) $provider,
			'capability_id' => (string) $capability_id,
			'artifact_fingerprint' => (string) $artifact_fingerprint,
			'capability_contract_digest' => (string) $capability_contract_digest,
			'state' => 'not_applicable',
			'behavioral_verified' => false,
			'rollback_verified' => false,
			'authorizing' => false,
			'activation_granted' => false,
			'mutation_granted' => false,
		);
	}

	private static function artifact_authority_required_behavioral_evidence( $provider, $capability_id, $artifact_fingerprint, $capability_contract_digest ) {
		return array(
			'contract' => MAD4B_SCP_Provider_Behavioral_Evidence::CONTRACT,
			'provider_id' => (string) $provider,
			'capability_id' => (string) $capability_id,
			'artifact_fingerprint' => (string) $artifact_fingerprint,
			'capability_contract_digest' => (string) $capability_contract_digest,
			'state' => 'artifact_authority_required',
			'behavioral_verified' => false,
			'rollback_verified' => false,
			'authorizing' => false,
			'activation_granted' => false,
			'mutation_granted' => false,
			'probe_permitted' => false,
		);
	}

	private static function evaluate_probes( array $probes, $adapter ) {
		$results = array();
		$compatible = true;
		foreach ( array_slice( $probes, 0, 30 ) as $probe ) {
			if ( ! is_array( $probe ) ) continue;
			$type = isset( $probe['type'] ) ? sanitize_key( (string) $probe['type'] ) : '';
			$observed = array();
			$passed = false;
			if ( 'adapter_available' === $type ) {
				$passed = is_object( $adapter ) && method_exists( $adapter, 'is_available' ) && (bool) $adapter->is_available();
				$observed[] = $passed ? 'adapter_available' : 'adapter_unavailable';
			} elseif ( 'class_any' === $type ) {
				foreach ( array_slice( (array) ( $probe['values'] ?? array() ), 0, 20 ) as $class ) if ( is_string( $class ) && class_exists( $class ) ) { $passed = true; $observed[] = $class; }
			} elseif ( 'function_any' === $type ) {
				foreach ( array_slice( (array) ( $probe['values'] ?? array() ), 0, 20 ) as $function ) if ( is_string( $function ) && function_exists( $function ) ) { $passed = true; $observed[] = $function; }
			} elseif ( 'symbol_any' === $type ) {
				foreach ( array_slice( (array) ( $probe['classes'] ?? array() ), 0, 20 ) as $class ) if ( is_string( $class ) && class_exists( $class ) ) { $passed = true; $observed[] = 'class:' . $class; }
				foreach ( array_slice( (array) ( $probe['functions'] ?? array() ), 0, 20 ) as $function ) if ( is_string( $function ) && function_exists( $function ) ) { $passed = true; $observed[] = 'function:' . $function; }
			} elseif ( 'declared_class_suffix_any' === $type ) {
				$suffixes = array_values( array_filter( array_map( 'strval', (array) ( $probe['values'] ?? array() ) ) ) );
				foreach ( get_declared_classes() as $class ) {
					foreach ( $suffixes as $suffix ) {
						if ( '' !== $suffix && strlen( $class ) >= strlen( $suffix ) && substr( $class, -strlen( $suffix ) ) === $suffix ) { $passed = true; $observed[] = $class; break 2; }
					}
				}
			}
			if ( ! $passed ) $compatible = false;
			$results[] = array( 'type' => $type, 'passed' => $passed, 'observed' => array_slice( array_values( array_unique( $observed ) ), 0, 20 ) );
		}
		return array( 'compatible' => $compatible, 'probes' => $results );
	}

	private static function artifact_evidence( $provider, array $runtime, $adapter = null ) {
		$contract = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::get( $provider ) : array();
		$integrity = isset( $runtime['runtime_integrity'] ) && is_array( $runtime['runtime_integrity'] ) ? $runtime['runtime_integrity'] : array();
		$adapter_reported_version = is_object( $adapter ) && method_exists( $adapter, 'runtime_version' ) ? trim( (string) $adapter->runtime_version() ) : '';
		$runtime_installed_version = isset( $runtime['installed_version'] ) ? trim( (string) $runtime['installed_version'] ) : '';
		$artifact_authority_bound = ! empty( $contract ) && ( '' !== $runtime_installed_version || ! empty( $runtime['components'] ) );
		$component_artifacts = array();
		if ( ! empty( $contract['components'] ) && is_array( $contract['components'] ) ) {
			foreach ( $contract['components'] as $key => $component ) {
				if ( ! is_array( $component ) ) continue;
				$key = sanitize_key( (string) $key );
				$runtime_component = isset( $runtime['components'][ $key ] ) && is_array( $runtime['components'][ $key ] ) ? $runtime['components'][ $key ] : array();
				$component_artifacts[ $key ] = array(
					'plugin_file' => isset( $component['plugin_file'] ) ? (string) $component['plugin_file'] : '',
					'archive' => isset( $component['archive'] ) ? (string) $component['archive'] : '',
					'archive_sha256' => isset( $component['archive_sha256'] ) ? strtolower( (string) $component['archive_sha256'] ) : '',
					'certified_version' => isset( $component['version'] ) ? (string) $component['version'] : '',
					'installed_version' => isset( $runtime_component['installed_version'] ) ? (string) $runtime_component['installed_version'] : '',
					'status' => isset( $runtime_component['status'] ) ? (string) $runtime_component['status'] : 'unknown',
				);
			}
			ksort( $component_artifacts, SORT_STRING );
		}
		$baseline_sha = isset( $contract['archive_sha256'] ) ? (string) $contract['archive_sha256'] : '';
		if ( '' === $baseline_sha && ! empty( $component_artifacts ) ) {
			$baseline_sha = self::stable_digest( array_map( static function ( $item ) { return isset( $item['archive_sha256'] ) ? $item['archive_sha256'] : ''; }, $component_artifacts ) );
		}
		$fingerprint_payload = array(
			'provider' => $provider,
			'installed_version' => $runtime_installed_version,
			'adapter_reported_version' => $adapter_reported_version,
			'artifact_authority_bound' => $artifact_authority_bound,
			'plugin_file' => isset( $contract['plugin_file'] ) ? (string) $contract['plugin_file'] : '',
			'components' => $component_artifacts,
			'certification_authority' => isset( $runtime['certification_authority'] ) ? (string) $runtime['certification_authority'] : '',
			'verified' => array_values( (array) ( $integrity['verified'] ?? array() ) ),
			'missing' => array_values( (array) ( $integrity['missing'] ?? array() ) ),
			'mismatched' => (array) ( $integrity['mismatched'] ?? array() ),
		);
		return array(
			'installed_version' => $runtime_installed_version,
			'adapter_reported_version' => $adapter_reported_version,
			'artifact_authority_bound' => $artifact_authority_bound,
			'artifact_authority_state' => $artifact_authority_bound ? 'bound' : 'missing',
			'certified_versions' => array_values( (array) ( $runtime['certified_versions'] ?? array() ) ),
			'certification_authority' => isset( $runtime['certification_authority'] ) ? (string) $runtime['certification_authority'] : 'repository_baseline',
			'plugin_file' => isset( $contract['plugin_file'] ) ? (string) $contract['plugin_file'] : '',
			'component_artifacts' => $component_artifacts,
			'baseline_package_sha256' => $baseline_sha,
			'runtime_status' => isset( $runtime['status'] ) ? (string) $runtime['status'] : 'unknown',
			'runtime_integrity' => $integrity,
			'runtime_artifact_fingerprint' => self::stable_digest( $fingerprint_payload ),
		);
	}

	private static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = defined( 'MAD4B_SCP_DIR' ) ? MAD4B_SCP_DIR . 'config/provider-capability-contracts.json' : '';
		if ( '' === $path || ! is_readable( $path ) ) return self::$catalog = array( 'contract' => '', 'providers' => array() );
		$raw = file_get_contents( $path );
		$data = false === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || self::CATALOG_CONTRACT !== (string) ( $data['contract'] ?? '' ) || ! isset( $data['providers'] ) || ! is_array( $data['providers'] ) ) return self::$catalog = array( 'contract' => '', 'providers' => array() );
		self::$catalog = $data;
		return self::$catalog;
	}

	private static function adapter_registry() {
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		return $registry;
	}

	private static function adapter_for_provider( $provider ) {
		$catalog = self::catalog();
		$declaration = isset( $catalog['providers'][ $provider ] ) && is_array( $catalog['providers'][ $provider ] ) ? $catalog['providers'][ $provider ] : array();
		$adapter_id = isset( $declaration['adapter_id'] ) ? sanitize_key( (string) $declaration['adapter_id'] ) : sanitize_key( (string) $provider );
		$registry = self::adapter_registry();
		return $registry && method_exists( $registry, 'get' ) ? $registry->get( $adapter_id ) : null;
	}

	private static function stable_digest( $value ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private static function blocked_selector( $reason, $provider = '' ) {
		return array(
			'contract' => 'mad4b.provider-capability-certification-result.v1',
			'state' => 'blocked',
			'provider_id' => (string) $provider,
			'blocking_reasons' => array( (string) $reason ),
			'authorizing' => false,
			'read_only' => true,
		);
	}
}

MAD4B_SCP_Provider_Compatibility_Certification::boot_early();
