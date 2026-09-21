<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

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
	public function declared_server_for_ability( $ability_name ) {
		$map = $this->ability_names();
		foreach ( array( 'content', 'admin', 'read' ) as $surface ) {
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
				if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return new WP_Error( 'mad4b_provider_contracts_unavailable', 'Provider mutation is denied because the certification authority is unavailable.' );
				$provider_guard = MAD4B_SCP_Provider_Contracts::mutation_guard( $this->certified_provider_key(), (bool) $this->is_available() );
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
