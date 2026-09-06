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
		wp_register_ability_category( 'mad4b-adapters', array( 'label' => 'MAD4B Adapters', 'description' => 'Adapter discovery, support requirements and runtime contract status.' ) );
		foreach ( $this->adapters as $adapter ) $adapter->register_category();
	}
	public function register_abilities() {
		$this->register_registry_ability( 'mad4b/adapters-inventory', 'Adapters Inventory', 'inventory', 'List registered MAD4B adapters and their runtime availability/reversible contracts.' );
		$this->register_registry_ability( 'mad4b/plugin-adapter-coverage', 'Plugin Adapter Coverage', 'plugin_coverage', 'Discover installed plugins and classify their governed adapter coverage without installing, enabling or generating code.' );
		$this->register_registry_ability( 'mad4b/adapter-support-requests', 'Adapter Support Requests', 'adapter_support_requests', 'Return deterministic read-only support requirements for plugins that need an adapter or reversible certification.' );
		$this->register_registry_ability( 'mad4b/runtime-self-test', 'Runtime Self Test', 'runtime_self_test', 'Verify registered abilities, MCP dependency, custom-server isolation, provider contracts and adapter coverage evidence.' );
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
	public function inventory() {
		$items = array();
		$reversible = 0;
		foreach ( $this->adapters as $adapter ) {
			$status = $adapter->status();
			$items[] = $status;
			if ( ! empty( $status['reversible_contracts'] ) ) ++$reversible;
		}
		return array( 'adapters' => $items, 'count' => count( $items ), 'reversible_adapter_count' => $reversible );
	}
	public function plugin_coverage() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::coverage() : array( 'contract' => 'mad4b.plugin-adapter-discovery.v1', 'discovery_only' => true, 'error' => 'plugin_discovery_unavailable' );
	}
	public function adapter_support_requests() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::support_requests() : array( 'contract' => 'mad4b.adapter-support-requests.v1', 'discovery_only' => true, 'requests' => array(), 'count' => 0, 'error' => 'plugin_discovery_unavailable' );
	}

	private function core_ability_names() {
		return array(
			'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/list-plugins', 'mad4b/abilities-inventory',
			'mad4b/filesystem-list', 'mad4b/filesystem-read', 'mad4b/database-list-tables', 'mad4b/database-describe-table',
			'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/content-get-post', 'mad4b/content-update-post',
			'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch',
			'mad4b/database-update', 'mad4b/audit-tail', 'mad4b/mutation-get', 'mad4b/mutation-undo',
			'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			'mad4b/database-raw-query', 'mad4b/adapters-inventory', 'mad4b/plugin-adapter-coverage', 'mad4b/adapter-support-requests', 'mad4b/runtime-self-test',
		);
	}

	public function runtime_self_test() {
		$missing = array();
		$public_leaks = array();
		$provider_contract_blockers = array();
		$provider_version_drift = array();
		$required_provider_missing = array();
		$mutation_blocked_adapters = array();
		$ability_names = $this->core_ability_names();
		foreach ( array( 'read', 'content', 'admin' ) as $surface ) $ability_names = array_merge( $ability_names, $this->ability_names( $surface ) );
		$ability_names = array_values( array_unique( $ability_names ) );

		foreach ( $ability_names as $name ) {
			if ( ! wp_has_ability( $name ) ) { $missing[] = $name; continue; }
			$ability = wp_get_ability( $name );
			if ( $ability && method_exists( $ability, 'get_meta' ) ) {
				$meta = $ability->get_meta();
				if ( ! empty( $meta['public'] ) || ! empty( $meta['mcp']['public'] ) ) $public_leaks[] = $name;
			}
		}

		$inventory = $this->inventory();
		$available = 0;
		$provider_runtime = array();
		foreach ( $inventory['adapters'] as $adapter ) {
			if ( ! empty( $adapter['available'] ) ) ++$available;
			if ( isset( $adapter['provider_certification']['provider'] ) ) {
				$key = (string) $adapter['provider_certification']['provider'];
				$provider_runtime[ $key ] = $adapter['provider_certification'];
			}
			if ( ! empty( $adapter['mutation_requires_certification'] ) ) {
				$cert = isset( $adapter['provider_certification'] ) && is_array( $adapter['provider_certification'] ) ? $adapter['provider_certification'] : array();
				if ( empty( $cert['runtime_contract_ok'] ) ) $mutation_blocked_adapters[] = isset( $adapter['id'] ) ? $adapter['id'] : 'unknown';
			}
		}

		$mcp_adapter = class_exists( 'WP\\MCP\\Core\\McpAdapter' );
		$mcp_certification = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $mcp_adapter ) : array();
		$provider_runtime['mcp_adapter'] = $mcp_certification;

		$required_providers = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::required_providers() : array( 'mcp_adapter' );
		foreach ( $required_providers as $provider ) {
			$status = isset( $provider_runtime[ $provider ] ) ? $provider_runtime[ $provider ] : MAD4B_SCP_Provider_Contracts::runtime_status( $provider, false );
			$violations = class_exists( 'MAD4B_SCP_Provider_Contracts' ) ? MAD4B_SCP_Provider_Contracts::violations_for_status( $status ) : array( 'certification_authority_unavailable' );
			if ( ! empty( $violations ) ) {
				$provider_contract_blockers[ $provider ] = $violations;
				if ( in_array( 'version_drift', $violations, true ) ) $provider_version_drift[] = $provider;
				if ( isset( $status['status'] ) && 'unavailable' === $status['status'] ) $required_provider_missing[] = $provider;
			}
		}

		$server_status = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$server_registration_ok = ! empty( $server_status );
		foreach ( $server_status as $server ) if ( empty( $server['registered'] ) ) $server_registration_ok = false;

		$mcp_peer_governance = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$mcp_peer_governance_ok = ! empty( $mcp_peer_governance['inventory_ready'] ) && empty( $mcp_peer_governance['write_side_channel_detected'] );
		$plugin_coverage = $this->plugin_coverage();
		$support_requests = isset( $plugin_coverage['support_requests'] ) && is_array( $plugin_coverage['support_requests'] ) ? $plugin_coverage['support_requests'] : array();
		$active_adapter_gaps = array();
		foreach ( $support_requests as $request ) if ( ! empty( $request['active'] ) && isset( $request['reason_code'] ) && in_array( $request['reason_code'], array( 'no_registered_adapter', 'reversible_certification_incomplete' ), true ) ) $active_adapter_gaps[] = $request['support_request_id'];
		$provider_contract_ok = empty( $provider_contract_blockers );
		$passed = empty( $missing ) && empty( $public_leaks ) && $mcp_adapter && $provider_contract_ok && $server_registration_ok && $mcp_peer_governance_ok;

		return array(
			'status' => $passed ? 'passed' : 'degraded',
			'wordpress_abilities' => function_exists( 'wp_register_ability' ),
			'mcp_adapter' => $mcp_adapter,
			'mcp_adapter_certification' => $mcp_certification,
			'provider_certification_ok' => $provider_contract_ok,
			'provider_contract_blockers' => $provider_contract_blockers,
			'provider_version_drift' => array_values( array_unique( $provider_version_drift ) ),
			'required_providers' => $required_providers,
			'required_provider_missing' => array_values( array_unique( $required_provider_missing ) ),
			'mutation_blocked_adapters' => array_values( array_unique( $mutation_blocked_adapters ) ),
			'plugin_adapter_discovery' => array(
				'contract' => isset( $plugin_coverage['contract'] ) ? $plugin_coverage['contract'] : '',
				'counts' => isset( $plugin_coverage['counts'] ) ? $plugin_coverage['counts'] : array(),
				'support_request_count' => count( $support_requests ),
				'active_adapter_gap_request_ids' => array_values( array_unique( $active_adapter_gaps ) ),
				'unknown_plugin_write_default' => 'deny',
			),
			'custom_server_isolation' => empty( $public_leaks ),
			'custom_server_registration_ok' => $server_registration_ok,
			'custom_servers' => $server_status,
			'mcp_peer_governance_ok' => $mcp_peer_governance_ok,
			'mcp_peer_governance' => $mcp_peer_governance,
			'registered_adapter_count' => count( $inventory['adapters'] ),
			'reversible_adapter_count' => isset( $inventory['reversible_adapter_count'] ) ? $inventory['reversible_adapter_count'] : 0,
			'available_adapter_count' => $available,
			'missing_abilities' => array_values( array_unique( $missing ) ),
			'default_server_exposure_leaks' => array_values( array_unique( $public_leaks ) ),
			'adapters' => $inventory['adapters'],
		);
	}
	public function ability_names( $surface ) {
		$names = array(); if ( 'read' === $surface ) $names = array( 'mad4b/adapters-inventory', 'mad4b/plugin-adapter-coverage', 'mad4b/adapter-support-requests', 'mad4b/runtime-self-test' );
		foreach ( $this->adapters as $adapter ) { $map = $adapter->ability_names(); if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) $names = array_merge( $names, $map[ $surface ] ); }
		return array_values( array_unique( $names ) );
	}
}
