<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only discovery of installed plugins and their MAD4B adapter coverage.
 *
 * Discovery never grants mutation authority, installs plugins or creates code. Unknown
 * plugins produce deterministic adapter-support requests and remain fail-closed for
 * plugin-specific writes until an explicit adapter contract is registered/certified.
 */
final class MAD4B_SCP_Plugin_Discovery {
	const CONTRACT = 'mad4b.plugin-adapter-discovery.v1';
	const MAX_PLUGINS = 500;

	private static $catalog = null;

	public static function catalog() {
		if ( null !== self::$catalog ) return self::$catalog;
		$path = MAD4B_SCP_DIR . 'config/adapter-support-catalog.json';
		$data = array();
		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path );
			$decoded = false === $raw ? null : json_decode( $raw, true );
			if ( is_array( $decoded ) ) $data = $decoded;
		}
		$data = apply_filters( 'mad4b_scp_plugin_adapter_discovery_catalog', $data );
		self::$catalog = is_array( $data ) ? $data : array();
		return self::$catalog;
	}

	public static function coverage() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! is_array( $plugins ) ) $plugins = array();
		ksort( $plugins, SORT_STRING );
		$items = array();
		$requests = array();
		$counts = array(
			'installed' => 0,
			'active' => 0,
			'supported_reversible' => 0,
			'supported_governed' => 0,
			'read_only_supported' => 0,
			'adapter_registered_inactive' => 0,
			'adapter_present_certification_required' => 0,
			'adapter_present_side_channel_blocked' => 0,
			'adapter_required' => 0,
			'excluded_high_risk' => 0,
			'priority_external_missing' => 0,
		);
		$functional_counts = array( 'functional_ready'=>0, 'status_only_candidate'=>0, 'contract_discovery_required'=>0, 'safety_blocked'=>0, 'adapter_missing'=>0, 'intentionally_excluded'=>0, 'inactive'=>0 );
		$functional_family_states = array();
		$functional_severity = array( 'inactive'=>0, 'functional_ready'=>1, 'intentionally_excluded'=>2, 'status_only_candidate'=>3, 'contract_discovery_required'=>4, 'adapter_missing'=>5, 'safety_blocked'=>6 );

		foreach ( $plugins as $plugin_file => $headers ) {
			if ( count( $items ) >= self::MAX_PLUGINS ) break;
			$item = self::describe_installed_plugin( (string) $plugin_file, is_array( $headers ) ? $headers : array() );
			$items[] = $item;
			if ( isset( $item['functional_coverage']['state'] ) && isset( $functional_counts[ $item['functional_coverage']['state'] ] ) ) ++$functional_counts[ $item['functional_coverage']['state'] ];
			if ( ! empty( $item['active'] ) && isset( $item['functional_coverage']['state'] ) ) {
				$family_key = ! empty( $item['family'] ) ? sanitize_key( (string) $item['family'] ) : self::normalize_plugin_file( $plugin_file );
				$family_state = sanitize_key( (string) $item['functional_coverage']['state'] );
				$current_state = isset( $functional_family_states[ $family_key ] ) ? $functional_family_states[ $family_key ] : '';
				$current_rank = isset( $functional_severity[ $current_state ] ) ? (int) $functional_severity[ $current_state ] : -1;
				$new_rank = isset( $functional_severity[ $family_state ] ) ? (int) $functional_severity[ $family_state ] : 0;
				if ( '' === $current_state || $new_rank > $current_rank ) $functional_family_states[ $family_key ] = $family_state;
			}
			++$counts['installed'];
			if ( ! empty( $item['active'] ) ) ++$counts['active'];
			if ( isset( $counts[ $item['coverage_state'] ] ) ) ++$counts[ $item['coverage_state'] ];
			if ( ! empty( $item['support_request'] ) ) $requests[] = $item['support_request'];
		}

		$priority = self::priority_external_items( $plugins );
		foreach ( $priority as $item ) {
			if ( 'priority_external_missing' === $item['coverage_state'] ) ++$counts['priority_external_missing'];
			if ( ! empty( $item['support_request'] ) ) $requests[] = $item['support_request'];
		}

		return array(
			'contract' => self::CONTRACT,
			'discovery_only' => true,
			'auto_install' => false,
			'auto_generate_adapter' => false,
			'auto_create_authority' => false,
			'unknown_plugin_write_default' => 'deny',
			'plugins' => $items,
			'priority_external' => $priority,
			'support_requests' => self::dedupe_requests( $requests ),
			'counts' => $counts,
			'functional_counts' => $functional_counts,
			'functional_family_counts' => self::functional_state_counts( $functional_family_states ),
			'functional_family_states' => $functional_family_states,
			'truncated' => count( $plugins ) > self::MAX_PLUGINS,
		);
	}

	private static function functional_state_counts( array $states ) {
		$counts = array( 'functional_ready'=>0, 'status_only_candidate'=>0, 'contract_discovery_required'=>0, 'safety_blocked'=>0, 'adapter_missing'=>0, 'intentionally_excluded'=>0, 'inactive'=>0 );
		foreach ( $states as $state ) {
			$state = sanitize_key( (string) $state );
			if ( isset( $counts[ $state ] ) ) ++$counts[ $state ];
		}
		return $counts;
	}

	public static function functional_coverage_report() {
		$coverage = self::coverage();
		$items = array();
		foreach ( isset( $coverage['plugins'] ) && is_array( $coverage['plugins'] ) ? $coverage['plugins'] : array() as $plugin ) {
			if ( empty( $plugin['active'] ) || empty( $plugin['functional_coverage'] ) || ! is_array( $plugin['functional_coverage'] ) ) continue;
			$items[] = array(
				'plugin_file' => isset( $plugin['plugin_file'] ) ? $plugin['plugin_file'] : '',
				'plugin_name' => isset( $plugin['name'] ) ? $plugin['name'] : '',
				'family' => isset( $plugin['family'] ) ? $plugin['family'] : '',
				'adapter_id' => isset( $plugin['adapter_id'] ) ? $plugin['adapter_id'] : '',
				'risk' => isset( $plugin['risk'] ) ? $plugin['risk'] : '',
				'functional_coverage' => $plugin['functional_coverage'],
			);
		}
		return array(
			'contract' => 'mad4b.provider-functional-coverage.v1',
			'read_only' => true,
			'authority_created' => false,
			'counts' => isset( $coverage['functional_family_counts'] ) ? $coverage['functional_family_counts'] : ( isset( $coverage['functional_counts'] ) ? $coverage['functional_counts'] : array() ),
			'plugin_counts' => isset( $coverage['functional_counts'] ) ? $coverage['functional_counts'] : array(),
			'family_states' => isset( $coverage['functional_family_states'] ) ? $coverage['functional_family_states'] : array(),
			'items' => $items,
			'count' => count( $items ),
		);
	}

	public static function support_requests() {
		$coverage = self::coverage();
		return array(
			'contract' => 'mad4b.adapter-support-requests.v1',
			'discovery_only' => true,
			'network_request_sent' => false,
			'authority_created' => false,
			'requests' => isset( $coverage['support_requests'] ) ? $coverage['support_requests'] : array(),
			'count' => isset( $coverage['support_requests'] ) ? count( $coverage['support_requests'] ) : 0,
		);
	}

	private static function describe_installed_plugin( $plugin_file, array $headers ) {
		$plugin_file = self::normalize_plugin_file( $plugin_file );
		$descriptor = self::descriptor_for( $plugin_file );
		$adapter_id = isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '';
		$adapter = '' !== $adapter_id && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id ) : null;
		$active = self::is_active( $plugin_file );
		$network_active = self::is_network_active( $plugin_file );
		$strategy = isset( $descriptor['strategy'] ) ? sanitize_key( (string) $descriptor['strategy'] ) : 'adapter_required';
		$risk = isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown';
		$status_value = is_object( $adapter ) && method_exists( $adapter, 'status' ) ? $adapter->status() : array();
		$status = is_array( $status_value ) ? $status_value : array();
		if ( is_wp_error( $status_value ) ) {
			$status['_discovery_error'] = sanitize_key( (string) $status_value->get_error_code() );
		} elseif ( ! is_array( $status_value ) && null !== $status_value ) {
			$status['_discovery_error'] = 'adapter_status_invalid_contract';
		}
		$side_channel_blocker = self::parallel_mcp_blocker( $descriptor, $active );
		$state = self::coverage_state( $strategy, $adapter, $active, $status, $side_channel_blocker );
		$reversible = self::adapter_reversible_contracts( $adapter );
		$request = self::support_request_for(
			$plugin_file,
			isset( $headers['Name'] ) ? (string) $headers['Name'] : self::plugin_slug( $plugin_file ),
			isset( $headers['Version'] ) ? (string) $headers['Version'] : '',
			$descriptor,
			$state,
			$active
		);

		$certification = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
		return array(
			'plugin_file' => $plugin_file,
			'slug' => self::plugin_slug( $plugin_file ),
			'name' => isset( $headers['Name'] ) ? sanitize_text_field( (string) $headers['Name'] ) : '',
			'version' => isset( $headers['Version'] ) ? sanitize_text_field( (string) $headers['Version'] ) : '',
			'active' => $active,
			'network_active' => $network_active,
			'family' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : 'unknown',
			'adapter_id' => $adapter_id,
			'adapter_registered' => is_object( $adapter ),
			'adapter_runtime_available' => is_object( $adapter ) ? (bool) $adapter->is_available() : false,
			'coverage_state' => $state,
			'risk' => $risk,
			'reversible_contracts' => $reversible,
			'provider_certification_required' => ! empty( $status['mutation_requires_certification'] ),
			'provider_certification_ok' => ! empty( $certification['runtime_contract_ok'] ),
			'provider_status' => isset( $certification['status'] ) ? sanitize_key( (string) $certification['status'] ) : '',
			'side_channel_blocker' => $side_channel_blocker,
			'mutation_auto_enabled' => false,
			'functional_coverage' => self::functional_coverage( $adapter, $status, $descriptor, $active, $state ),
			'support_request' => $request,
		);
	}

	private static function functional_coverage( $adapter, array $status, array $descriptor, $active, $coverage_state ) {
		$requested = isset( $descriptor['requested_contracts'] ) && is_array( $descriptor['requested_contracts'] ) ? array_values( array_map( 'sanitize_key', $descriptor['requested_contracts'] ) ) : array();
		$mode = isset( $descriptor['functional_mode'] ) ? sanitize_key( (string) $descriptor['functional_mode'] ) : 'review_required';
		$declared_rationale = isset( $descriptor['functional_rationale'] ) ? sanitize_text_field( (string) $descriptor['functional_rationale'] ) : '';
		$declared_next = isset( $descriptor['functional_next_action'] ) ? sanitize_key( (string) $descriptor['functional_next_action'] ) : '';
		$evidence_requirements = isset( $descriptor['functional_evidence_requirements'] ) && is_array( $descriptor['functional_evidence_requirements'] ) ? array_values( array_filter( array_map( 'sanitize_key', $descriptor['functional_evidence_requirements'] ) ) ) : array();
		$safe_now = isset( $descriptor['functional_safe_now'] ) && is_array( $descriptor['functional_safe_now'] ) ? array_values( array_filter( array_map( 'sanitize_key', $descriptor['functional_safe_now'] ) ) ) : array();
		$prohibited_until_certified = isset( $descriptor['functional_prohibited_until_certified'] ) && is_array( $descriptor['functional_prohibited_until_certified'] ) ? array_values( array_filter( array_map( 'sanitize_key', $descriptor['functional_prohibited_until_certified'] ) ) ) : array();
		$cross = isset( $descriptor['functional_cross_surface_abilities'] ) && is_array( $descriptor['functional_cross_surface_abilities'] ) ? array_values( array_map( 'sanitize_text_field', $descriptor['functional_cross_surface_abilities'] ) ) : array();
		$map = is_object( $adapter ) && method_exists( $adapter, 'ability_names' ) ? $adapter->ability_names() : array();
		$reads = isset( $map['read'] ) && is_array( $map['read'] ) ? array_values( $map['read'] ) : array();
		$writes = array();
		foreach ( array( 'content', 'write', 'admin' ) as $surface ) if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ) $writes = array_merge( $writes, $map[ $surface ] );
		$writes = array_values( array_unique( $writes ) );
		$state = 'functional_ready'; $reason = ''; $next = 'no_action_required'; $blockers = array();

		if ( ! $active ) {
			$state = 'inactive'; $reason = 'plugin_not_active'; $next = 'activate_only_if_operationally_required';
		} elseif ( 'excluded_high_risk' === $coverage_state || 'intentionally_excluded' === $mode || 'intentionally_restricted' === $mode ) {
			$state = 'intentionally_excluded'; $reason = 'normal_writer_excluded_by_policy'; $next = 'retain_restricted_scope_unless_separately_reviewed';
		} elseif ( ! is_object( $adapter ) ) {
			$state = 'adapter_missing'; $reason = 'no_registered_adapter'; $next = 'implement_and_certify_provider_adapter';
		} elseif ( ! empty( $status['_discovery_error'] ) ) {
			$state = 'safety_blocked';
			$reason = 'adapter_status_unavailable';
			$blockers = array( sanitize_key( (string) $status['_discovery_error'] ) );
			$next = 'inspect_adapter_status_contract_before_treating_provider_as_ready';
		} else {
			$execution = isset( $status['execution'] ) && is_array( $status['execution'] ) ? $status['execution'] : array();
			$desired = isset( $execution['desired_execution_abilities'] ) && is_array( $execution['desired_execution_abilities'] ) ? $execution['desired_execution_abilities'] : array();
			$mounted = isset( $execution['mounted_execution_abilities'] ) && is_array( $execution['mounted_execution_abilities'] ) ? $execution['mounted_execution_abilities'] : array();
			$execution_blockers = array();
			foreach ( array( 'import', 'export' ) as $lane ) if ( isset( $execution[ $lane ]['blockers'] ) && is_array( $execution[ $lane ]['blockers'] ) ) $execution_blockers = array_merge( $execution_blockers, $execution[ $lane ]['blockers'] );
			$execution_blockers = array_values( array_unique( array_filter( array_map( 'sanitize_key', $execution_blockers ) ) ) );
			if ( ! empty( $desired ) && count( $mounted ) < count( $desired ) ) {
				$state = 'safety_blocked'; $reason = 'desired_execution_not_certified_or_mounted'; $next = 'close_reported_execution_readiness_blockers_before_mount'; $blockers = $execution_blockers;
			} elseif ( 'contract_discovery' === $mode ) {
				$state = 'contract_discovery_required';
				$reason = '' !== $declared_rationale ? $declared_rationale : 'provider_contract_evidence_incomplete';
				$blockers = array( 'provider_contract_evidence_incomplete' );
				$next = '' !== $declared_next ? $declared_next : 'capture_provider_contract_evidence_before_expanding_functional_scope';
			} elseif ( in_array( $mode, array( 'inventory_only','platform_core','external_authority','cross_surface','specialized' ), true ) ) {
				$state = 'functional_ready';
				$reason = 'cross_surface' === $mode ? 'governed_functionality_available_on_separate_surface' : ( 'external_authority' === $mode ? 'execution_delegated_to_external_authority' : 'declared_functional_scope_satisfied' );
				$next = 'cross_surface' === $mode ? 'use_declared_cross_surface_abilities' : ( 'external_authority' === $mode ? 'use_external_authority_for_execution' : 'no_action_required' );
			} elseif ( 'mad4b.repository-family-read-adapter.v1' === ( isset( $status['contract'] ) ? (string) $status['contract'] : '' ) && count( $reads ) <= 1 && empty( $writes ) ) {
				$state = 'status_only_candidate';
				$reason = 'specialized_candidate' === $mode ? 'known_provider_functions_exceed_status_only_surface' : ( '' !== $declared_rationale ? $declared_rationale : 'provider_functional_scope_requires_review' );
				$next = '' !== $declared_next ? $declared_next : 'review_provider_functions_and_add_read_plan_execute_contracts_where_justified';
			}
		}
		return array(
			'contract' => 'mad4b.provider-functional-coverage-item.v1',
			'state' => $state,
			'reason' => $reason,
			'functional_mode' => $mode,
			'cross_surface_abilities' => $cross,
			'declared_rationale' => $declared_rationale,
			'evidence_requirements' => $evidence_requirements,
			'safe_now' => $safe_now,
			'prohibited_until_certified' => $prohibited_until_certified,
			'read_ability_count' => count( $reads ),
			'write_ability_count' => count( $writes ),
			'read_abilities' => $reads,
			'write_abilities' => $writes,
			'requested_contracts' => $requested,
			'blockers' => $blockers,
			'next_action' => $next,
			'authority_created' => false,
		);
	}

	private static function priority_external_items( array $installed ) {
		$catalog = self::catalog();
		$priority = isset( $catalog['priority_external'] ) && is_array( $catalog['priority_external'] ) ? $catalog['priority_external'] : array();
		$items = array();
		foreach ( $priority as $descriptor ) {
			if ( ! is_array( $descriptor ) ) continue;
			$files = isset( $descriptor['plugin_files'] ) && is_array( $descriptor['plugin_files'] ) ? $descriptor['plugin_files'] : array();
			$found = '';
			foreach ( $files as $file ) {
				$file = self::normalize_plugin_file( $file );
				if ( isset( $installed[ $file ] ) ) { $found = $file; break; }
			}
			if ( '' !== $found ) continue;
			$adapter_id = isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '';
			$adapter = '' !== $adapter_id && class_exists( 'MAD4B_SCP_Adapter_Registry' ) ? MAD4B_SCP_Adapter_Registry::instance()->get( $adapter_id ) : null;
			$name = isset( $descriptor['label'] ) ? sanitize_text_field( (string) $descriptor['label'] ) : ( isset( $descriptor['id'] ) ? sanitize_text_field( (string) $descriptor['id'] ) : '' );
			$request = self::support_request_for( isset( $files[0] ) ? self::normalize_plugin_file( $files[0] ) : '', $name, '', $descriptor, 'priority_external_missing', false );
			$items[] = array(
				'id' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : '',
				'name' => $name,
				'installed' => false,
				'active' => false,
				'adapter_id' => $adapter_id,
				'adapter_registered' => is_object( $adapter ),
				'coverage_state' => 'priority_external_missing',
				'risk' => isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown',
				'reversible_contracts' => self::adapter_reversible_contracts( $adapter ),
				'support_request' => $request,
			);
		}
		return $items;
	}

	private static function descriptor_for( $plugin_file ) {
		$catalog = self::catalog();
		$families = isset( $catalog['families'] ) && is_array( $catalog['families'] ) ? $catalog['families'] : array();
		foreach ( $families as $descriptor ) {
			if ( ! is_array( $descriptor ) || empty( $descriptor['match'] ) || ! is_array( $descriptor['match'] ) ) continue;
			foreach ( $descriptor['match'] as $prefix ) {
				$prefix = self::normalize_plugin_file( $prefix );
				if ( '' !== $prefix && 0 === strpos( $plugin_file, $prefix ) ) return $descriptor;
			}
		}
		$default = isset( $catalog['default'] ) && is_array( $catalog['default'] ) ? $catalog['default'] : array();
		$default['id'] = 'unknown';
		$default['adapter_id'] = '';
		return $default;
	}

	private static function coverage_state( $strategy, $adapter, $active, array $status, $side_channel_blocker = '' ) {
		if ( 'platform' === $strategy ) return 'supported_governed';
		if ( 'excluded_high_risk' === $strategy ) return 'excluded_high_risk';
		if ( ! is_object( $adapter ) ) return 'adapter_required';
		if ( ! $active || ! $adapter->is_available() ) return 'adapter_registered_inactive';
		if ( '' !== $side_channel_blocker ) return 'adapter_present_side_channel_blocked';
		$contracts = self::adapter_reversible_contracts( $adapter );
		if ( ! empty( $contracts ) ) {
			if ( ! empty( $status['mutation_requires_certification'] ) ) {
				$certification = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
				if ( empty( $certification['runtime_contract_ok'] ) ) return 'adapter_present_certification_required';
			}
			return 'supported_reversible';
		}
		$map = method_exists( $adapter, 'ability_names' ) ? $adapter->ability_names() : array();
		$writes = array_merge( isset( $map['content'] ) && is_array( $map['content'] ) ? $map['content'] : array(), isset( $map['admin'] ) && is_array( $map['admin'] ) ? $map['admin'] : array() );
		return ! empty( $writes ) ? 'supported_governed' : 'read_only_supported';
	}

	private static function parallel_mcp_blocker( array $descriptor, $active ) {
		if ( ! $active || empty( $descriptor['known_parallel_mcp_namespace'] ) || ! class_exists( 'MAD4B_SCP_MCP_Peer_Governance' ) ) return '';
		$namespace = trim( strtolower( (string) $descriptor['known_parallel_mcp_namespace'] ), '/' );
		if ( '' === $namespace ) return '';
		$status = MAD4B_SCP_MCP_Peer_Governance::status();
		$foreign = isset( $status['foreign_transport_inventory'] ) && is_array( $status['foreign_transport_inventory'] ) ? $status['foreign_transport_inventory'] : array();
		$routes = isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? $foreign['foreign_routes'] : array();
		$prefix = '/' . $namespace . '/';
		foreach ( $routes as $route ) {
			$route = strtolower( (string) $route );
			if ( 0 === strpos( $route, $prefix ) && false !== strpos( $route, 'mcp' ) ) return 'mcp_foreign_transport_unreviewed';
		}
		return '';
	}

	private static function adapter_reversible_contracts( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'reversible_contracts' ) ) return array();
		$contracts = $adapter->reversible_contracts();
		if ( ! is_array( $contracts ) ) return array();
		$out = array();
		foreach ( $contracts as $ability => $contract ) {
			$ability = (string) $ability;
			$contract = (string) $contract;
			if ( '' !== $ability && preg_match( '/^mad4b\.rollback\.[a-z0-9._-]+\.v[0-9]+$/', $contract ) ) $out[ $ability ] = $contract;
		}
		return $out;
	}

	private static function support_request_for( $plugin_file, $name, $version, array $descriptor, $state, $active ) {
		if ( ! in_array( $state, array( 'adapter_required', 'supported_governed', 'adapter_present_certification_required', 'adapter_present_side_channel_blocked', 'excluded_high_risk', 'priority_external_missing' ), true ) ) return null;
		if ( 'adapter_required' === $state ) $reason = 'no_registered_adapter';
		elseif ( 'supported_governed' === $state ) $reason = 'reversible_certification_incomplete';
		elseif ( 'adapter_present_certification_required' === $state ) $reason = 'provider_certification_required';
		elseif ( 'adapter_present_side_channel_blocked' === $state ) $reason = 'parallel_mcp_write_plane_requires_isolation';
		elseif ( 'excluded_high_risk' === $state ) $reason = 'normal_writer_excluded_by_risk';
		else $reason = 'priority_external_not_installed';
		$requested = isset( $descriptor['requested_contracts'] ) && is_array( $descriptor['requested_contracts'] ) ? array_values( array_map( 'sanitize_key', $descriptor['requested_contracts'] ) ) : array( 'read', 'bounded_write', 'reversible_restore' );
		$seed = array( 'plugin_file' => $plugin_file, 'version' => (string) $version, 'reason' => $reason, 'contracts' => $requested );
		return array(
			'support_request_id' => 'asr-' . substr( hash( 'sha256', wp_json_encode( $seed ) ), 0, 24 ),
			'plugin_file' => $plugin_file,
			'plugin_name' => sanitize_text_field( (string) $name ),
			'plugin_version' => sanitize_text_field( (string) $version ),
			'active' => (bool) $active,
			'family' => isset( $descriptor['id'] ) ? sanitize_key( (string) $descriptor['id'] ) : 'unknown',
			'adapter_id' => isset( $descriptor['adapter_id'] ) ? sanitize_key( (string) $descriptor['adapter_id'] ) : '',
			'reason_code' => $reason,
			'risk' => isset( $descriptor['risk'] ) ? sanitize_key( (string) $descriptor['risk'] ) : 'unknown',
			'requested_contracts' => $requested,
			'auto_create_authority' => false,
			'auto_install' => false,
			'normal_write_allowed' => false,
		);
	}

	private static function dedupe_requests( array $requests ) {
		$out = array();
		foreach ( $requests as $request ) {
			if ( ! is_array( $request ) || empty( $request['support_request_id'] ) ) continue;
			$out[ $request['support_request_id'] ] = $request;
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	private static function normalize_plugin_file( $plugin_file ) {
		return ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $plugin_file ) ), '/' );
	}

	private static function plugin_slug( $plugin_file ) {
		$plugin_file = self::normalize_plugin_file( $plugin_file );
		$parts = explode( '/', $plugin_file );
		return sanitize_key( isset( $parts[0] ) ? $parts[0] : $plugin_file );
	}

	private static function is_active( $plugin_file ) {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) return true;
		return self::is_network_active( $plugin_file );
	}

	private static function is_network_active( $plugin_file ) {
		return function_exists( 'is_plugin_active_for_network' ) && is_multisite() && is_plugin_active_for_network( $plugin_file );
	}
}
