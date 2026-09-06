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
		);
		if ( is_array( $certification ) ) $status['provider_certification'] = $certification;
		$status['mutation_requires_certification'] = $this->mutation_requires_certification();
		return $status;
	}

	protected function certified_provider_key() { return $this->id(); }
	protected function provider_certification( $available ) {
		if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return null;
		return MAD4B_SCP_Provider_Contracts::runtime_status( $this->certified_provider_key(), (bool) $available );
	}
	protected function mutation_requires_certification() {
		$required = 'media' !== $this->id();
		return (bool) apply_filters( 'mad4b_scp_adapter_mutation_requires_certification', $required, $this->id(), $this );
	}

	private function mutation_permission_callback( $permission, $readonly ) {
		if ( $readonly ) return $permission;
		return function ( $input = null ) use ( $permission ) {
			$granted = call_user_func( $permission, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled until MAD4B_MCP_MUTATION_ENABLED is explicitly enabled.' );
			if ( ! $this->mutation_requires_certification() ) return true;
			if ( ! class_exists( 'MAD4B_SCP_Provider_Contracts' ) ) return new WP_Error( 'mad4b_provider_contracts_unavailable', 'Provider mutation is denied because the certification authority is unavailable.' );
			return MAD4B_SCP_Provider_Contracts::mutation_guard( $this->certified_provider_key(), (bool) $this->is_available() );
		};
	}

	protected function add_ability( $name, $label, $method, $permission, $input_schema = null, $surface = 'read', $readonly = true, $destructive = false, $idempotent = true ) {
		$effective_destructive = $readonly ? (bool) $destructive : true;
		$args = array(
			'label' => $label,
			'description' => $label . ' through the governed MAD4B ' . $this->label() . ' adapter.',
			'category' => 'mad4b-' . $this->id(),
			'execute_callback' => array( $this, $method ),
			'permission_callback' => $this->mutation_permission_callback( $permission, (bool) $readonly ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $surface ),
				'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => $effective_destructive, 'idempotent' => (bool) $idempotent ),
			),
		);
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
