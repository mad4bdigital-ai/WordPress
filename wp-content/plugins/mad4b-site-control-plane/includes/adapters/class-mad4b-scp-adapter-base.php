<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) {
	require_once dirname( __DIR__ ) . '/class-mad4b-scp-provider-compatibility-certification.php';
}

abstract class MAD4B_SCP_Adapter_Base {
	abstract public function id();
	abstract public function label();
	abstract public function is_available();
	abstract public function ability_names();
	abstract public function register_abilities();

	public function register_category() { wp_register_ability_category( 'mad4b-' . $this->id(), array( 'label' => 'MAD4B ' . $this->label(), 'description' => 'Governed ' . $this->label() . ' integration abilities.' ) ); }

	public function status() {
		$available = (bool) $this->is_available();
		$version = $this->detect_plugin_version();
		$certification = $this->provider_certification( $available );
		if ( '' === $version && is_array( $certification ) && ! empty( $certification['installed_version'] ) ) $version = (string) $certification['installed_version'];
		$status = array(
			'id' => $this->id(), 'label' => $this->label(), 'available' => $available,
			'abilities' => $this->ability_names(), 'version' => $version,
			'mutation_master_enabled' => MAD4B_SCP_Policy::can_mutate(),
			'reversible_contracts' => $this->reversible_contracts(),
		);
		if ( is_array( $certification ) ) $status['provider_certification'] = $certification;
		$status['mutation_requires_certification'] = $this->mutation_requires_certification();
		if ( class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) && MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $this->provider_key() ) ) {
			// Keep provider_certification as immutable artifact/runtime truth. Capability
			// certification is a separate authority and must never rewrite
			// runtime_contract_ok, especially when a drifted capability is restored by
			// bounded behavioral evidence.
			$status['capability_certification'] = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( $this->provider_key(), $this );
			$status['capability_mount_projection'] = MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection( $this->provider_key(), $this );
			$status['capability_certification_mode'] = 'per_ability_separate_from_artifact_truth';
		}
		return $status;
	}

	public function provider_key() { return sanitize_key( (string) $this->certified_provider_key() ); }
	protected function certified_provider_key() { return $this->id(); }
	protected function provider_certification( $available ) {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return null;
		return MAD4B_SCP_Provider_Contracts::runtime_status( $this->certified_provider_key(), (bool) $available );
	}
	protected function mutation_requires_certification() {
		$required = 'media' !== $this->id();
		return (bool) apply_filters( 'mad4b_scp_adapter_mutation_requires_certification', $required, $this->id(), $this );
	}

	/**
	 * Explicit ability => restore-contract map. Empty means governed but not reversible.
	 * Subclasses must opt in per ability; discovery never infers reversibility from a writer.
	 */
	public function reversible_contracts() { return array(); }
	public function reversible_contract_for( $ability_name ) {
		$contracts = $this->reversible_contracts();
		$contract = isset( $contracts[ $ability_name ] ) ? (string) $contracts[ $ability_name ] : '';
		return preg_match( '/^mad4b\.rollback\.[a-z0-9._-]+\.v[0-9]+$/', $contract ) ? $contract : '';
	}
	public function capture_reversible_state( $ability_name, array $input ) { return new WP_Error( 'mad4b_reversible_capture_unsupported', 'This adapter ability has no reversible capture implementation.' ); }
	public function read_reversible_state( $ability_name, array $target ) { return new WP_Error( 'mad4b_reversible_readback_unsupported', 'This adapter ability has no reversible readback implementation.' ); }
	public function restore_reversible_state( $ability_name, array $target, array $state, array $record ) { return new WP_Error( 'mad4b_reversible_restore_unsupported', 'This adapter ability has no reversible restore implementation.' ); }

	/**
	 * High-risk canary execution is opt-in per adapter and per exact ability.
	 * The default is permanently fail-closed. The Control Plane wrapper owns NHI,
	 * one-time approval, budgets and evidence binding; an adapter opt-in must still
	 * re-run its native/runtime safety guards before producing any side effect.
	 */
	public function supports_canary_execution( $ability_name ) { return false; }
	public function execute_canary( $ability_name, array $input ) {
		return new WP_Error( 'mad4b_provider_canary_execution_unsupported', 'This adapter does not implement governed canary execution for the requested ability.' );
	}

	/**
	 * Public canary responses never expose the raw provider result. Adapters may
	 * explicitly opt into a bounded, non-sensitive summary. Empty is the safe
	 * default; the wrapper still records a digest of the complete provider result.
	 */
	public function canary_result_summary( $ability_name, $result ) { return array(); }

	public function declared_server_for_ability( $ability_name ) {
		$map = $this->ability_names();
		foreach ( array( 'content', 'write', 'admin', 'read' ) as $surface ) {
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) return 'mad4b-' . $surface;
		}
		return '';
	}

	private function mutation_permission_callback( $permission, $readonly, $ability_name, $surface ) {
		if ( $readonly ) return $permission;
		return function ( $input = null ) use ( $permission, $ability_name, $surface ) {
			$granted = call_user_func( $permission, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled until the global mutation gate and a bound enabled NHI are both present.' );
			if ( $this->mutation_requires_certification() ) {
				if ( class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) && MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $this->provider_key() ) ) {
					$provider_guard = MAD4B_SCP_Provider_Compatibility_Certification::mutation_guard( $this->provider_key(), $ability_name, (bool) $this->is_available(), $this );
				} else {
					if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return new WP_Error( 'mad4b_provider_contracts_unavailable', 'Provider mutation is denied because the certification authority is unavailable.' );
					$provider_guard = MAD4B_SCP_Provider_Contracts::mutation_guard( $this->certified_provider_key(), (bool) $this->is_available() );
				}
				if ( is_wp_error( $provider_guard ) || true !== $provider_guard ) return $provider_guard;
			}
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
			$authorization = MAD4B_SCP_Authorization::authorize_mutation( $ability_name, 'mad4b-' . sanitize_key( $surface ), $this->certified_provider_key(), $input );
			if ( is_wp_error( $authorization ) ) return $authorization;
			return true;
		};
	}

	protected function add_ability( $name, $label, $method, $permission, $input_schema = null, $surface = 'read', $readonly = true, $destructive = false, $idempotent = true ) {
		$effective_destructive = $readonly ? (bool) $destructive : true;
		$execute_callback = array( $this, $method );
		$reversible_contract = $readonly ? '' : $this->reversible_contract_for( $name );
		if ( '' !== $reversible_contract ) {
			$execute_callback = function ( $input = array() ) use ( $name, $method ) {
				if ( ! class_exists( 'MAD4B_SCP_Reversible_Adapter_Mutations' ) ) return new WP_Error( 'mad4b_reversible_adapter_manager_unavailable', 'Reversible adapter mutation manager is unavailable.' );
				return MAD4B_SCP_Reversible_Adapter_Mutations::execute( $this, $name, $method, is_array( $input ) ? $input : array() );
			};
		}
		$args = array(
			'label' => $label,
			'description' => $label . ' through the governed MAD4B ' . $this->label() . ' adapter.',
			'category' => 'mad4b-' . $this->id(),
			'execute_callback' => $execute_callback,
			'permission_callback' => $this->mutation_permission_callback( $permission, (bool) $readonly, $name, $surface ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $surface ),
				'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => $effective_destructive, 'idempotent' => (bool) $idempotent ),
			),
		);
		if ( '' !== $reversible_contract ) $args['meta']['mcp']['mad4b_reversible_contract'] = $reversible_contract;
		if ( is_array( $input_schema ) ) $args['input_schema'] = $input_schema;
		wp_register_ability( $name, $args );
	}

	protected function schema( array $properties, array $required = array() ) { $schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ); if ( $required ) $schema['required'] = $required; return $schema; }
	protected function json_value_schema() { return array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'number' ), array( 'type' => 'integer' ), array( 'type' => 'boolean' ), array( 'type' => 'array' ), array( 'type' => 'object' ), array( 'type' => 'null' ) ) ); }
	protected function unavailable_error() { return new WP_Error( 'mad4b_adapter_unavailable', $this->label() . ' is not active or its expected runtime contract is unavailable.' ); }
	protected function normalize( $value ) { $encoded = wp_json_encode( $value ); if ( false === $encoded ) return null; return json_decode( $encoded, true ); }
	protected function hash_value( $value ) { return hash( 'sha256', wp_json_encode( $value ) ); }
	protected function detect_plugin_version() { return ''; }
}

// The canary subsystem is part of adapter governance, not a provider-specific
// side channel. Load it immediately after the base contract exists so its
// extension hook is registered before the first deterministic registry build.
require_once dirname( __DIR__ ) . '/class-mad4b-scp-provider-canary-execution.php';
require_once __DIR__ . '/class-mad4b-scp-provider-canary-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-acceptance-target-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-full-content-operations-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-translation-bridge-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-native-provider-bridge-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-jetengine-diagnostics-adapter.php';
require_once __DIR__ . '/class-mad4b-scp-mutation-semantics-adapter.php';
MAD4B_SCP_Provider_Canary_Execution::boot_early();
MAD4B_SCP_Provider_Canary_Adapter::boot();
MAD4B_SCP_Full_Content_Operations_Adapter::boot();
MAD4B_SCP_Translation_Bridge_Adapter::boot();
MAD4B_SCP_Native_Provider_Bridge_Adapter::boot();
MAD4B_SCP_JetEngine_Diagnostics_Bootstrap::boot();
MAD4B_SCP_Mutation_Semantics_Adapter::boot();