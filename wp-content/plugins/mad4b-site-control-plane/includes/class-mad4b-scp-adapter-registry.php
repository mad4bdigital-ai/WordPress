<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/adapters/class-mad4b-scp-core-content-modeling-adapter.php';
require_once __DIR__ . '/adapters/class-mad4b-scp-dynamic-content-adapter.php';
require_once __DIR__ . '/adapters/class-mad4b-scp-dynamic-recovery-adapter.php';

final class MAD4B_SCP_Adapter_Registry {
	private static $instance;
	private static $ability_hooks_bound = false;
	private $adapters = array();
	private $defaults_registered = false;

	public static function instance() { if ( ! self::$instance ) self::$instance = new self(); return self::$instance; }
	public static function boot_ability_registration() {
		if ( self::$ability_hooks_bound ) return;
		self::$ability_hooks_bound = true;
		$registry = self::instance();
		// Bind only. Provider Adapter objects are materialized lazily when the
		// canonical WordPress Abilities lifecycle actually requests definitions.
		add_action( 'wp_abilities_api_categories_init', array( $registry, 'register_categories' ), 20 );
		add_action( 'wp_abilities_api_init', array( $registry, 'register_abilities' ), 20 );
	}

	/**
	 * Discover *already loaded, bundled* adapter classes by type and origin.
	 * The trusted plugin bootstrap controls includes. Never scan third-party
	 * directories or autoload unreviewed PHP on a read request.
	 */
	public static function bundled_adapter_classes() {
		$root = realpath( __DIR__ . '/adapters' );
		if ( false === $root || ! class_exists( 'MAD4B_SCP_Adapter_Base', false ) ) return array();
		$found = array();
		foreach ( get_declared_classes() as $name ) {
			if ( ! is_subclass_of( $name, 'MAD4B_SCP_Adapter_Base', true ) ) continue;
			$reflection = new ReflectionClass( $name );
			if ( $reflection->isAbstract() || ! $reflection->isInstantiable() ) continue;
			$file = $reflection->getFileName();
			$real = is_string( $file ) ? realpath( $file ) : false;
			if ( false === $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) continue;
			$constructor = $reflection->getConstructor();
			if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) continue;
			$found[] = $name;
		}
		sort( $found, SORT_STRING );
		return $found;
	}

	/**
	 * Registration policy is checked independently of discovery. Newly bundled
	 * adapters are visible to inventory but NOT mounted until source-reviewed.
	 * No WordPress filter or option can expand this root-of-trust list.
	 */
	public static function approved_bundled_adapter_classes() {
		$file = __DIR__ . '/../config/adapter-registration-policy.json';
		if ( ! is_readable( $file ) ) return array();
		$raw = file_get_contents( $file );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data )
			|| ( $data['contract'] ?? '' ) !== 'mad4b.adapter-registration-policy.v1'
			|| ! isset( $data['approved_classes'] ) || ! is_array( $data['approved_classes'] ) )
			return array();
		$available = array_fill_keys( self::bundled_adapter_classes(), true );
		$approved = array();
		foreach ( $data['approved_classes'] as $class ) {
			if ( ! is_string( $class ) || ! isset( $available[ $class ] ) ) continue;
			$approved[ $class ] = true;
		}
		return array_keys( $approved );
	}

	public function register_defaults() {
		if ( $this->defaults_registered ) return;
		$this->defaults_registered = true;
		foreach ( self::approved_bundled_adapter_classes() as $class ) $this->register( new $class() );
		// Legacy hook may propose adapters but cannot mount unreviewed classes.
		// Separate source-reviewed registration policy controls all providers.
		do_action( 'mad4b_scp_register_adapters', $this );
	}
	public function register( $adapter ) {
		if ( ! $adapter instanceof MAD4B_SCP_Adapter_Base ) return false;
		// Adapter_Base inheritance alone is not authority. Third-party hooks
		// and new bundled classes cannot silently register executable abilities.
		if ( ! in_array( get_class( $adapter ), self::approved_bundled_adapter_classes(), true ) ) return false;
		$id = $adapter->id();
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) ) return false;
		if ( isset( $this->adapters[ $id ] ) ) return false; // Reject silent collisions/overrides.
		$this->adapters[ $id ] = $adapter;
		return true;
	}
	public function get( $id ) { return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null; }
	public function all() { return $this->adapters; }
	public function register_categories() {
		$this->register_defaults();
		wp_register_ability_category( 'mad4b-adapters', array( 'label' => 'MAD4B Adapters', 'description' => 'Adapter discovery, support requirements and runtime contract status.' ) );
		foreach ( $this->adapters as $adapter ) $adapter->register_category();
	}
	public function register_abilities() {
		$this->register_defaults();
		$this->register_registry_ability( 'mad4b/adapters-inventory', 'Adapters Inventory', 'inventory', 'List registered MAD4B adapters and their runtime availability/reversible contracts.' );
		$this->register_registry_ability( 'mad4b/plugin-adapter-coverage', 'Plugin Adapter Coverage', 'plugin_coverage', 'Discover installed plugins and classify their governed adapter coverage without installing, enabling or generating code.' );
		$this->register_registry_ability( 'mad4b/adapter-support-requests', 'Adapter Support Requests', 'adapter_support_requests', 'Return deterministic read-only support requirements for plugins that need an adapter, provider certification, reversible certification, or side-channel isolation.' );
		$this->register_registry_ability( 'mad4b/provider-functional-coverage', 'Provider Functional Coverage', 'provider_functional_coverage', 'Show capability-level provider coverage, status-only candidates, safety blockers and next safe actions.' );
		$this->register_registry_ability( 'mad4b/provider-contract-discovery', 'Provider Contract Discovery', 'provider_contract_discovery', 'List active providers whose exact functional contract is not yet captured, including required evidence, safe read scope, prohibited scope and next action.' );
		$this->register_registry_ability( 'mad4b/provider-candidate-matrix', 'Provider Candidate Matrix', 'provider_candidate_matrix', 'Classify every installed plugin into deterministic L0-L4 governed support levels from runtime evidence without creating authority or mutation.' );
		$this->register_registry_ability( 'mad4b/plugin-capability-graph', 'Plugin Capability Graph', 'plugin_capability_graph', 'Inventory dynamically discovered installed plugins, declared adapter reads, registered abilities and dependencies with fail-closed unreviewed capability proposals.' );
		$this->register_registry_ability( 'mad4b/functional-gap-runtime-evidence', 'Functional Gap Runtime Evidence', 'functional_gap_runtime_evidence', 'Collect exact read-only runtime provider evidence and compare it with build-embedded repository evidence without shell, SQL, remote requests or authority changes.' );
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
		$discovered = self::bundled_adapter_classes();
		$approved = self::approved_bundled_adapter_classes();
		$pending_review = array_values( array_diff( $discovered, $approved ) );
		return array( 'adapters' => $items, 'count' => count( $items ),
			'reversible_adapter_count' => $reversible,
			'bundled_class_discovery_count' => count( $discovered ),
			'approved_class_count' => count( $approved ),
			'unreviewed_bundled_candidates' => $pending_review,
			'new_candidate_write_enabled' => false,
			'registration_policy' => 'source_reviewed_manifest_only' );
	}
	public function plugin_coverage() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::coverage() : array( 'contract' => 'mad4b.plugin-adapter-discovery.v1', 'discovery_only' => true, 'error' => 'plugin_discovery_unavailable' );
	}
	public function adapter_support_requests() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::support_requests() : array( 'contract' => 'mad4b.adapter-support-requests.v1', 'discovery_only' => true, 'requests' => array(), 'count' => 0, 'error' => 'plugin_discovery_unavailable' );
	}
	public function provider_functional_coverage() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::functional_coverage_report() : array( 'contract' => 'mad4b.provider-functional-coverage.v1', 'read_only' => true, 'items' => array(), 'count' => 0, 'error' => 'plugin_discovery_unavailable' );
	}
	public function provider_contract_discovery() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::contract_discovery_report() : array( 'contract' => 'mad4b.provider-contract-discovery.v1', 'read_only' => true, 'items' => array(), 'count' => 0, 'error' => 'plugin_discovery_unavailable' );
	}
	public function plugin_capability_graph() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' )
			? MAD4B_SCP_Plugin_Discovery::capability_graph()
			: array( 'contract' => 'mad4b.plugin-capability-graph.v1', 'read_only' => true,
				'complete' => false, 'state' => 'plugin_discovery_unavailable', 'items' => array() );
	}
	public function provider_candidate_matrix() {
		return class_exists( 'MAD4B_SCP_Plugin_Discovery' ) ? MAD4B_SCP_Plugin_Discovery::provider_candidate_matrix() : array( 'contract' => 'mad4b.provider-candidate-matrix.v1', 'read_only' => true, 'items' => array(), 'count' => 0, 'error' => 'plugin_discovery_unavailable' );
	}

	public function functional_gap_runtime_evidence() {
		return class_exists( 'MAD4B_SCP_Functional_Gap_Evidence' ) ? MAD4B_SCP_Functional_Gap_Evidence::snapshot() : array( 'contract' => 'mad4b.functional-gap-zero-touch.v1', 'read_only' => true, 'error' => 'functional_gap_evidence_unavailable' );
	}

	private function core_ability_names() {
		return array(
			'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/list-plugins', 'mad4b/abilities-inventory',
			'mad4b/filesystem-list', 'mad4b/filesystem-read', 'mad4b/database-list-tables', 'mad4b/database-describe-table',
			'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status',
			'mad4b/write-authority-status', 'mad4b/write-runtime-certification', 'mad4b/rest-compatibility-status',
			'mad4b/plugin-lifecycle-plan', 'mad4b/plugin-package-plan', 'mad4b/plugin-remote-update-plan', 'mad4b/workflow-provider-status', 'mad4b/workflow-plan',
			'mad4b/content-get-post', 'mad4b/content-update-post',
			'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/plugin-package-apply', 'mad4b/plugin-remote-update-apply', 'mad4b/filesystem-write', 'mad4b/filesystem-patch',
			'mad4b/database-update', 'mad4b/audit-tail', 'mad4b/mutation-get', 'mad4b/mutation-undo',
			'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			'mad4b/database-raw-query', 'mad4b/adapters-inventory', 'mad4b/plugin-adapter-coverage', 'mad4b/capability-atlas', 'mad4b/adapter-support-requests', 'mad4b/provider-functional-coverage', 'mad4b/provider-contract-discovery', 'mad4b/provider-candidate-matrix', 'mad4b/plugin-capability-graph', 'mad4b/functional-gap-runtime-evidence', 'mad4b/runtime-self-test',
			'mad4b/skills-list', 'mad4b/skill-get', 'mad4b/skills-export-status', 'mad4b/skills-runtime-certification',
		);
	}

	private function abilities_snapshot() {
		if ( did_action( 'init' ) < 1 || ! function_exists( 'wp_get_abilities' ) ) return array();
		$abilities = wp_get_abilities();
		return is_array( $abilities ) ? $abilities : array();
	}

	public function runtime_self_test() {
		$unexpected_missing = array();
		$registered_but_not_exposed = array();
		$intentionally_unavailable = array();
		$public_candidates = array();
		$public_leaks = array();
		$provider_contract_blockers = array();
		$provider_contract_advisories = array();
		$provider_capability_health = array();
		$provider_version_drift = array();
		$required_provider_missing = array();
		$mutation_blocked_adapters = array();
		$provider_adapter_ids = array();
		$ability_names = $this->core_ability_names();
		$surface_abilities = array();
		foreach ( array( 'read', 'content', 'admin' ) as $surface ) {
			$surface_abilities[ $surface ] = $this->ability_names( $surface );
			$ability_names = array_merge( $ability_names, $surface_abilities[ $surface ] );
		}
		$ability_names = array_values( array_unique( $ability_names ) );
		$abilities = $this->abilities_snapshot();

		foreach ( $ability_names as $name ) {
			if ( ! isset( $abilities[ $name ] ) || ! is_object( $abilities[ $name ] ) ) { $unexpected_missing[] = $name; continue; }
			$ability = $abilities[ $name ];
			if ( method_exists( $ability, 'get_meta' ) ) {
				$meta = $ability->get_meta();
				if ( ! empty( $meta['public'] ) || ! empty( $meta['mcp']['public'] ) ) $public_candidates[] = $name;
			}
		}

		$provider_isolation = class_exists( 'MAD4B_SCP_MCP_Provider_Isolation' )
			? MAD4B_SCP_MCP_Provider_Isolation::status()
			: array( 'effective' => false, 'default_server_suppressed' => false );
		$default_server_suppressed = ! empty( $provider_isolation['effective'] ) && ! empty( $provider_isolation['default_server_suppressed'] );
		$public_candidates = array_values( array_unique( $public_candidates ) );
		$public_leaks = $default_server_suppressed ? array() : $public_candidates;

		$inventory = $this->inventory();
		$plugin_coverage = $this->plugin_coverage();
		$active_adapter_ids = array();
		foreach ( isset( $plugin_coverage['plugins'] ) && is_array( $plugin_coverage['plugins'] ) ? $plugin_coverage['plugins'] : array() as $plugin_item ) {
			if ( empty( $plugin_item['active'] ) || empty( $plugin_item['adapter_id'] ) ) continue;
			$active_adapter_ids[ sanitize_key( (string) $plugin_item['adapter_id'] ) ] = true;
		}
		$available = 0;
		$provider_runtime = array();
		foreach ( $inventory['adapters'] as $adapter ) {
			if ( ! empty( $adapter['available'] ) ) ++$available;
			if ( isset( $adapter['provider_certification']['provider'] ) ) {
				$key = (string) $adapter['provider_certification']['provider'];
				$provider_runtime[ $key ] = $adapter['provider_certification'];
				if ( ! empty( $adapter['id'] ) ) $provider_adapter_ids[ $key ] = sanitize_key( (string) $adapter['id'] );
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
			if ( empty( $violations ) ) continue;
			$adapter_id = isset( $provider_adapter_ids[ $provider ] ) ? $provider_adapter_ids[ $provider ] : '';
			$provider_active = 'mcp_adapter' === (string) $provider || ( '' !== $adapter_id && isset( $active_adapter_ids[ $adapter_id ] ) );
			$capability_assessment = array();
			$exposed_read_blockers = array();
			$eligible_writes = array();
			if ( $provider_active && 'mcp_adapter' !== (string) $provider && '' !== $adapter_id && class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) ) {
				$adapter_object = $this->get( $adapter_id );
				if ( is_object( $adapter_object ) ) {
					$capability_assessment = MAD4B_SCP_Provider_Compatibility_Certification::assess_provider( $provider, $adapter_object );
					foreach ( isset( $capability_assessment['capabilities'] ) && is_array( $capability_assessment['capabilities'] ) ? $capability_assessment['capabilities'] : array() as $capability_id => $capability ) {
						if ( empty( $capability['surface_exposed'] ) ) continue;
						$risk = isset( $capability['risk'] ) ? (string) $capability['risk'] : 'unknown';
						if ( 'read' === $risk && empty( $capability['read_eligible'] ) ) $exposed_read_blockers[] = sanitize_key( (string) $capability_id );
						if ( 'read' !== $risk && ! empty( $capability['write_eligible'] ) ) $eligible_writes[] = sanitize_key( (string) $capability_id );
					}
				}
			}
			$provider_capability_health[ $provider ] = array(
				'adapter_id' => $adapter_id,
				'active' => $provider_active,
				'compatibility_state' => isset( $capability_assessment['compatibility_state'] ) ? (string) $capability_assessment['compatibility_state'] : '',
				'exposed_read_blockers' => array_values( array_unique( $exposed_read_blockers ) ),
				'eligible_writes_under_drift' => array_values( array_unique( $eligible_writes ) ),
			);
			$drift_is_advisory = $provider_active
				&& 'mcp_adapter' !== (string) $provider
				&& ! empty( $capability_assessment )
				&& empty( $exposed_read_blockers )
				&& empty( $eligible_writes )
				&& in_array( (string) ( $capability_assessment['compatibility_state'] ?? '' ), array( 'compatible_unattested', 'certified' ), true );
			if ( $provider_active && ! $drift_is_advisory ) {
				$provider_contract_blockers[ $provider ] = $violations;
				if ( isset( $status['status'] ) && 'unavailable' === $status['status'] ) $required_provider_missing[] = $provider;
			} else {
				$provider_contract_advisories[ $provider ] = array(
					'violations' => $violations,
					'adapter_id' => $adapter_id,
					'active' => $provider_active,
					'blocking' => false,
					'reason' => $provider_active ? 'active_provider_drift_read_compatible_writes_fail_closed' : 'provider_not_active_in_current_runtime',
					'compatibility_state' => isset( $capability_assessment['compatibility_state'] ) ? (string) $capability_assessment['compatibility_state'] : '',
					'exposed_read_blockers' => array_values( array_unique( $exposed_read_blockers ) ),
					'eligible_writes_under_drift' => array_values( array_unique( $eligible_writes ) ),
				);
			}
			if ( in_array( 'version_drift', $violations, true ) ) $provider_version_drift[] = $provider;
		}

		$server_status = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		$server_registration_ok = ! empty( $server_status );
		foreach ( $server_status as $server ) if ( empty( $server['registered'] ) ) $server_registration_ok = false;
		if ( class_exists( 'MAD4B_SCP_Servers' ) ) {
			$surface_servers = array( 'read' => 'mad4b-read', 'content' => 'mad4b-content', 'admin' => 'mad4b-admin' );
			foreach ( $ability_names as $name ) {
				if ( ! isset( $abilities[ $name ] ) || ! is_object( $abilities[ $name ] ) ) continue;
				$mounted = false;
				foreach ( $surface_servers as $surface => $server_id ) {
					if ( ! empty( $server_status[ $server_id ]['registered'] ) && in_array( $name, $surface_abilities[ $surface ], true ) ) {
						$mounted = true;
						break;
					}
				}
				if ( ! $mounted ) {
					foreach ( MAD4B_SCP_Servers::expected_server_ids() as $server_id ) {
						if ( MAD4B_SCP_Servers::ability_is_mounted( $server_id, $name ) ) { $mounted = true; break; }
					}
				}
				if ( ! $mounted ) $registered_but_not_exposed[] = $name;
			}
		}

		$mcp_peer_governance = class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ? MAD4B_SCP_MCP_Peer_Governance::status() : array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$mcp_peer_governance_ok = ! empty( $mcp_peer_governance['inventory_ready'] )
			&& empty( $mcp_peer_governance['write_side_channel_detected'] )
			&& empty( $mcp_peer_governance['foreign_transport_unreviewed'] );
		$support_requests = isset( $plugin_coverage['support_requests'] ) && is_array( $plugin_coverage['support_requests'] ) ? $plugin_coverage['support_requests'] : array();
		$functional_family_counts = isset( $plugin_coverage['functional_family_counts'] ) && is_array( $plugin_coverage['functional_family_counts'] ) ? $plugin_coverage['functional_family_counts'] : array();
		$functional_family_states = isset( $plugin_coverage['functional_family_states'] ) && is_array( $plugin_coverage['functional_family_states'] ) ? $plugin_coverage['functional_family_states'] : array();
		$contract_discovery_families = array();
		foreach ( $functional_family_states as $family_id => $functional_state ) {
			if ( 'contract_discovery_required' === sanitize_key( (string) $functional_state ) ) $contract_discovery_families[] = sanitize_key( (string) $family_id );
		}
		sort( $contract_discovery_families, SORT_STRING );
		$active_adapter_gaps = array();
		$active_gap_reasons = array( 'no_registered_adapter', 'reversible_certification_incomplete', 'provider_certification_required', 'parallel_mcp_write_plane_requires_isolation' );
		foreach ( $support_requests as $request ) {
			if ( ! empty( $request['active'] ) && isset( $request['reason_code'] ) && in_array( $request['reason_code'], $active_gap_reasons, true ) ) {
				$active_adapter_gaps[] = $request['support_request_id'];
			}
		}
		$provider_contract_ok = empty( $provider_contract_blockers );
		$unexpected_missing = array_values( array_unique( $unexpected_missing ) );
		$registered_but_not_exposed = array_values( array_unique( $registered_but_not_exposed ) );
		$passed = empty( $unexpected_missing ) && empty( $registered_but_not_exposed ) && empty( $public_leaks ) && $mcp_adapter && $provider_contract_ok && $server_registration_ok && $mcp_peer_governance_ok;

		return array(
			'contract' => 'mad4b.runtime-self-test.v2',
			'status' => $passed ? 'passed' : 'degraded',
			'wordpress_abilities' => function_exists( 'wp_register_ability' ),
			'ability_catalog_snapshot_count' => count( $abilities ),
			'unexpected_missing_abilities' => $unexpected_missing,
			'registered_but_not_exposed' => $registered_but_not_exposed,
			'intentionally_unavailable' => $intentionally_unavailable,
			'mcp_adapter' => $mcp_adapter,
			'mcp_adapter_certification' => $mcp_certification,
			'provider_certification_ok' => $provider_contract_ok,
			'provider_contract_blockers' => $provider_contract_blockers,
			'provider_contract_advisories' => $provider_contract_advisories,
			'provider_capability_health' => $provider_capability_health,
			'provider_inactive_drift_is_blocking' => false,
			'provider_active_drift_is_blocking_when_reads_compatible_and_writes_fail_closed' => false,
			'provider_version_drift' => array_values( array_unique( $provider_version_drift ) ),
			'required_providers' => $required_providers,
			'required_provider_missing' => array_values( array_unique( $required_provider_missing ) ),
			'mutation_blocked_adapters' => array_values( array_unique( $mutation_blocked_adapters ) ),
			'plugin_adapter_discovery' => array(
				'contract' => isset( $plugin_coverage['contract'] ) ? $plugin_coverage['contract'] : '',
				'counts' => isset( $plugin_coverage['counts'] ) ? $plugin_coverage['counts'] : array(),
				'support_request_count' => count( $support_requests ),
				'active_adapter_gap_request_ids' => array_values( array_unique( $active_adapter_gaps ) ),
				'active_adapter_gap_reason_codes' => $active_gap_reasons,
				'unknown_plugin_write_default' => 'deny',
				'functional_family_counts' => $functional_family_counts,
				'contract_discovery_family_count' => count( $contract_discovery_families ),
				'contract_discovery_families' => $contract_discovery_families,
				'contract_discovery_is_non_authorizing' => true,
			),
			'custom_server_isolation' => empty( $public_leaks ) && $mcp_peer_governance_ok,
			'default_server_public_candidates' => $public_candidates,
			'default_server_suppressed' => $default_server_suppressed,
			'provider_mcp_isolation' => $provider_isolation,
			'custom_server_registration_ok' => $server_registration_ok,
			'custom_servers' => $server_status,
			'mcp_peer_governance_ok' => $mcp_peer_governance_ok,
			'mcp_peer_governance' => $mcp_peer_governance,
			'registered_adapter_count' => count( $inventory['adapters'] ),
			'reversible_adapter_count' => isset( $inventory['reversible_adapter_count'] ) ? $inventory['reversible_adapter_count'] : 0,
			'available_adapter_count' => $available,
			// Backward-compatible alias for callers that still consume the v1 field.
			'missing_abilities' => $unexpected_missing,
			'default_server_exposure_leaks' => array_values( array_unique( $public_leaks ) ),
			'adapters' => $inventory['adapters'],
		);
	}
	public function ability_names( $surface ) {
		$names = array(); if ( 'read' === $surface ) $names = array( 'mad4b/adapters-inventory', 'mad4b/plugin-adapter-coverage', 'mad4b/capability-atlas', 'mad4b/adapter-support-requests', 'mad4b/provider-functional-coverage', 'mad4b/provider-contract-discovery', 'mad4b/functional-gap-runtime-evidence', 'mad4b/runtime-self-test' );
		foreach ( $this->adapters as $adapter ) { $map = $adapter->ability_names(); if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) $names = array_merge( $names, $map[ $surface ] ); }
		return array_values( array_unique( $names ) );
	}
}
