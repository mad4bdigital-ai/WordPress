<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class MAD4B_SCP_Adapter_Base {
	abstract public function id();
	abstract public function label();
	abstract public function is_available();
	abstract public function ability_names();
	abstract public function register_abilities();

	public function register_category() { wp_register_ability_category( 'mad4b-' . $this->id(), array( 'label' => 'MAD4B ' . $this->label(), 'description' => 'Governed ' . $this->label() . ' integration abilities.' ) ); }
	public function status() { return array( 'id' => $this->id(), 'label' => $this->label(), 'available' => (bool) $this->is_available(), 'abilities' => $this->ability_names(), 'version' => $this->detect_plugin_version() ); }

	protected function add_ability( $name, $label, $method, $permission, $input_schema = null, $surface = 'read', $readonly = true, $destructive = false, $idempotent = true ) {
		$args = array(
			'label' => $label,
			'description' => $label . ' through the governed MAD4B ' . $this->label() . ' adapter.',
			'category' => 'mad4b-' . $this->id(),
			'execute_callback' => array( $this, $method ),
			'permission_callback' => $permission,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				// MAD4B abilities are exposed only by the explicitly configured custom servers.
				// Keeping this false prevents the official default server from discovering or executing them.
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => $surface ),
				'annotations' => array( 'readonly' => (bool) $readonly, 'destructive' => (bool) $destructive, 'idempotent' => (bool) $idempotent ),
			),
		);
		if ( is_array( $input_schema ) ) $args['input_schema'] = $input_schema;
		wp_register_ability( $name, $args );
	}

	protected function schema( array $properties, array $required = array() ) { $schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false ); if ( $required ) $schema['required'] = $required; return $schema; }
	protected function json_value_schema() {
		return array( 'anyOf' => array(
			array( 'type' => 'string' ), array( 'type' => 'number' ), array( 'type' => 'integer' ), array( 'type' => 'boolean' ), array( 'type' => 'array' ), array( 'type' => 'object' ), array( 'type' => 'null' ),
		) );
	}
	protected function unavailable_error() { return new WP_Error( 'mad4b_adapter_unavailable', $this->label() . ' is not active or its expected runtime contract is unavailable.' ); }
	protected function normalize( $value ) { $encoded = wp_json_encode( $value ); if ( false === $encoded ) return null; return json_decode( $encoded, true ); }
	protected function hash_value( $value ) { return hash( 'sha256', wp_json_encode( $value ) ); }
	protected function detect_plugin_version() { return ''; }
}
