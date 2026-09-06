<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Adapter_Registry {

	private static $instance;
	private $adapters = array();
	private $defaults_registered = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_defaults() {
		if ( $this->defaults_registered ) {
			return;
		}
		$this->defaults_registered = true;

		$classes = array(
			'MAD4B_SCP_Elementor_Adapter',
			'MAD4B_SCP_JetEngine_Adapter',
			'MAD4B_SCP_JetSmartFilters_Adapter',
			'MAD4B_SCP_BitFlows_Adapter',
			'MAD4B_SCP_Media_Adapter',
			'MAD4B_SCP_SEO_Adapter',
			'MAD4B_SCP_WooCommerce_Adapter',
			'MAD4B_SCP_Polylang_Adapter',
			'MAD4B_SCP_LiteSpeed_Adapter',
		);

		foreach ( $classes as $class ) {
			if ( class_exists( $class ) ) {
				$this->register( new $class() );
			}
		}

		do_action( 'mad4b_scp_register_adapters', $this );
	}

	public function register( $adapter ) {
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) {
			return false;
		}
		$this->adapters[ $adapter->id() ] = $adapter;
		return true;
	}

	public function get( $id ) {
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}

	public function all() {
		return $this->adapters;
	}

	public function register_categories() {
		wp_register_ability_category(
			'mad4b-adapters',
			array(
				'label'       => 'MAD4B Adapters',
				'description' => 'Adapter discovery and runtime contract status.',
			)
		);
		foreach ( $this->adapters as $adapter ) {
			$adapter->register_category();
		}
	}

	public function register_abilities() {
		wp_register_ability(
			'mad4b/adapters-inventory',
			array(
				'label'               => 'Adapters Inventory',
				'description'         => 'List supported MAD4B adapters and their runtime availability.',
				'category'            => 'mad4b-adapters',
				'execute_callback'    => array( $this, 'inventory' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'output_schema'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta'                => array(
					'public'       => false,
					'show_in_rest' => false,
					'mcp'          => array( 'public' => true, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);

		foreach ( $this->adapters as $adapter ) {
			$adapter->register_abilities();
		}
	}

	public function inventory() {
		$items = array();
		foreach ( $this->adapters as $adapter ) {
			$items[] = $adapter->status();
		}
		return array( 'adapters' => $items, 'count' => count( $items ) );
	}

	public function ability_names( $surface ) {
		$names = array();
		if ( 'read' === $surface ) {
			$names[] = 'mad4b/adapters-inventory';
		}
		foreach ( $this->adapters as $adapter ) {
			$map = $adapter->ability_names();
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) {
				$names = array_merge( $names, $map[ $surface ] );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
