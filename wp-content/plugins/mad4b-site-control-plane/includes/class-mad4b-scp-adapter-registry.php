<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private $adapters = array();
	private $defaults_registered = false;

	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public function register_defaults() {
		if ( $this->defaults_registered ) return; $this->defaults_registered = true;
		$classes = array( 'MAD4B_SCP_Elementor_Adapter', 'MAD4B_SCP_JetEngine_Adapter', 'MAD4B_SCP_JetSmartFilters_Adapter', 'MAD4B_SCP_BitFlows_Adapter', 'MAD4B_SCP_Media_Adapter', 'MAD4B_SCP_SEO_Adapter', 'MAD4B_SCP_WooCommerce_Adapter', 'MAD4B_SCP_Polylang_Adapter', 'MAD4B_SCP_LiteSpeed_Adapter' );
		foreach ( $classes as $class ) if ( class_exists( $class ) ) $this->register( new $class() );
		do_action( 'mad4b_scp_register_adapters', $this );
	}
	public function register( $adapter ) { if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false; $this->adapters[ $adapter->id() ] = $adapter; return true; }
	public function get( $id ) { return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null; }
	public function all() { return $this->adapters; }
	public function register_categories() {
		wp_register_ability_category( 'mad4b-adapters', array( 'label' => 'MAD4B Adapters', 'description' => 'Adapter discovery and runtime contract status.' ) );
		foreach ( $this->adapters as $adapter ) $adapter->register_category();
	}
	public function register_abilities() {
		$this->register_registry_ability( 'mad4b/adapters-inventory', 'Adapters Inventory', 'inventory', 'List supported MAD4B adapters and their runtime availability.' );
		$this->register_registry_ability( 'mad4b/runtime-self-test', 'Runtime Self Test', 'runtime_self_test', 'Verify registered abilities, MCP dependency, custom-server isolation and certified provider runtime contracts.' );
		foreach ( $this->adapters as $adapter ) $adapter->register_abilities();
	}
	private function register_registry_ability( $name, $label, $method, $description ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $description,
			'category' => 'mad4b-adapters',
			'execute_callback' => array( $this, $method ),
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}
	public function inventory() { $items = array(); foreach ( $this->adapters as $adapter ) $items[] = $adapter->status(); return array( 'adapters' => $items, 'count' => count( $items ) ); }
	public function runtime_self_test() {
		$missing = array();
		$public_leaks = array();
		$provider_version_drift = array();
		$surfaces = array( 'read', 'content', 'admin' );
		foreach ( $surfaces as $surface ) {
			foreach ( $this->ability_names( $surface ) as $name ) {
				if ( ! wp_has_ability( $name ) ) {
					$missing[] = $name;
					continue;
				}
				$ability = wp_get_ability( $name );
				if ( $ability && method_exists( $ability, 'get_meta' ) ) {
					$meta = $ability->get_meta();
					if ( ! empty( $meta['public'] ) || ! empty( $meta['mcp']['public'] ) ) $public_leaks[] = $name;
				}
			}
		}

		$inventory = $this->inventory();
		$available = 0;
		foreach ( $inventory['adapters'] as $adapter ) {
			if ( empty( $adapter['available'] ) ) {
				continue;
			}
			++$available;
			if ( isset( $adapter['provider_certification']['status'] ) && 'version_drift' === $adapter['provider_certification']['status'] ) {
				$provider_version_drift[] = isset( $adapter['id'] ) ? $adapter['id'] : 'unknown';
			}
		}

		$mcp_adapter = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$mcp_certification = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $mcp_adapter ) : array();
		if ( isset( $mcp_certification['status'] ) && 'version_drift' === $mcp_certification['status'] ) {
			$provider_version_drift[] = 'mcp_adapter';
		}
		$provider_version_drift = array_values( array_unique( $provider_version_drift ) );
		$provider_certification_ok = empty( $provider_version_drift ) && $mcp_adapter && ( empty( $mcp_certification['status'] ) || 'certified' === $mcp_certification['status'] );
		$passed = empty( $missing ) && empty( $public_leaks ) && $mcp_adapter && $provider_certification_ok;

		return array(
			'status' => $passed ? 'passed' : 'degraded',
			'wordpress_abilities' => function_exists( 'wp_register_ability' ),
			'mcp_adapter' => $mcp_adapter,
			'mcp_adapter_certification' => $mcp_certification,
			'provider_certification_ok' => $provider_certification_ok,
			'provider_version_drift' => $provider_version_drift,
			'custom_server_isolation' => empty( $public_leaks ),
			'registered_adapter_count' => count( $inventory['adapters'] ),
			'available_adapter_count' => $available,
			'missing_abilities' => array_values( array_unique( $missing ) ),
			'default_server_exposure_leaks' => array_values( array_unique( $public_leaks ) ),
			'adapters' => $inventory['adapters'],
		);
	}
	public function ability_names( $surface ) {
		$names = array(); if ( 'read' === $surface ) $names = array( 'mad4b/adapters-inventory', 'mad4b/runtime-self-test' );
		foreach ( $this->adapters as $adapter ) { $map = $adapter->ability_names(); if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) $names = array_merge( $names, $map[ $surface ] ); }
		return array_values( array_unique( $names ) );
	}
}
