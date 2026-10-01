<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only local truth for MAD4B MCP connection readiness. */
final class MAD4B_SCP_Connection_Status {
	const CONTRACT = 'mad4b.connection-readiness.v4';
	const PREVIOUS_CONTRACT = 'mad4b.connection-readiness.v3';

	public static function status( $force_deep = false ) {
		$environment = class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::effective() : ( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' );
		$https = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) );
		$adapter_available = class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		$adapter_version = '';
		if ( defined( 'WP_MCP_VERSION' ) ) {
			$adapter_version = (string) WP_MCP_VERSION;
		} elseif ( $adapter_available && defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ) {
			$adapter_version = (string) \WP\MCP\Core\McpAdapter::VERSION;
		}
		$protocol_hotpath = ! $force_deep
			&& class_exists( 'MAD4B_SCP_MCP_Request_Scope', false )
			&& MAD4B_SCP_MCP_Request_Scope::current_request_is_protocol_hotpath();
		$admin_shallow = ! $force_deep && self::admin_shallow_surface();
		$lightweight = $protocol_hotpath || $admin_shallow;
		$provider = class_exists( 'MAD4B_SCP_Provider_Contracts' )
			? ( $lightweight
				? MAD4B_SCP_Provider_Contracts::runtime_identity_status( 'mcp_adapter', $adapter_available )
				: MAD4B_SCP_Provider_Contracts::runtime_status( 'mcp_adapter', $adapter_available ) )
			: array( 'status' => 'unavailable', 'runtime_contract_ok' => false, 'identity_contract_ok' => false );
		$provider_ok = $lightweight ? ! empty( $provider['identity_contract_ok'] ) : ! empty( $provider['runtime_contract_ok'] );
		$servers = self::server_status( $lightweight );
		$expected_count = class_exists( 'MAD4B_SCP_Servers' ) ? count( MAD4B_SCP_Servers::expected_server_ids() ) : 7;
		$server_ok = count( $servers ) === $expected_count;
		foreach ( $servers as $server ) {
			if ( $lightweight ) {
				if ( empty( $server['registration_identity_ready'] ) ) { $server_ok = false; break; }
			} elseif ( empty( $server['registered'] ) ) {
				$server_ok = false;
				break;
			}
			if ( ! $protocol_hotpath && empty( $server['deep_route_validation_deferred'] ) && ( empty( $server['route_registered'] ) || empty( $server['permission_callback_match'] ) ) ) { $server_ok = false; break; }
		}
		$peer = $lightweight
			? array(
				'inventory_ready' => false,
				'write_side_channel_detected' => false,
				'blockers' => array( $protocol_hotpath ? 'mcp_peer_inventory_deferred_protocol_hotpath' : 'mcp_peer_inventory_deferred_admin_hotpath' ),
				'state' => $protocol_hotpath ? 'deferred_protocol_hotpath' : 'deferred_admin_hotpath',
				'deep_inventory_performed' => false,
			)
			: ( class_exists( 'MAD4B_SCP_MCP_Peer_Governance' )
				? MAD4B_SCP_MCP_Peer_Governance::status()
				: array( 'inventory_ready' => false, 'write_side_channel_detected' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) ) );
		$identity = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : new WP_Error( 'mad4b_identity_context_unavailable', 'Identity context is unavailable.' );
		$isolation = class_exists( 'MAD4B_SCP_MCP_Provider_Isolation' ) ? MAD4B_SCP_MCP_Provider_Isolation::status() : array( 'configured' => false, 'effective' => false );
		$oauth = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
			? ( $lightweight && method_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', 'runtime_identity_status' )
				? MAD4B_SCP_OAuth_Resource_Bridge::runtime_identity_status()
				: MAD4B_SCP_OAuth_Resource_Bridge::status() )
			: array( 'available' => false );
		$handshake = class_exists( 'MAD4B_SCP_External_Handshake_Evidence' )
			? ( $lightweight && method_exists( 'MAD4B_SCP_External_Handshake_Evidence', 'persisted_identity_status' )
				? MAD4B_SCP_External_Handshake_Evidence::persisted_identity_status()
				: MAD4B_SCP_External_Handshake_Evidence::status() )
			: array( 'verified' => false, 'status' => 'evidence_component_unavailable' );
		$oauth_blockers = self::oauth_preflight_blockers( $oauth );
		$mcp_registration_lifecycle = self::bounded_mcp_registration_lifecycle();

		$local_blockers = array();
		if ( ! $adapter_available ) $local_blockers[] = 'mcp_adapter_unavailable';
		if ( $adapter_available && ! $provider_ok ) $local_blockers[] = 'mcp_adapter_not_certified';
		if ( ! $server_ok ) $local_blockers[] = 'mad4b_transport_registration_incomplete';
		if ( ! $lightweight && empty( $peer['inventory_ready'] ) ) $local_blockers[] = 'mcp_peer_inventory_unavailable';
		if ( ! empty( $peer['write_side_channel_detected'] ) ) $local_blockers[] = 'mcp_write_side_channel_detected';
		if ( ! $lightweight && ! empty( $peer['blockers'] ) && is_array( $peer['blockers'] ) ) $local_blockers = array_merge( $local_blockers, $peer['blockers'] );
		$local_blockers = array_values( array_unique( array_map( 'sanitize_key', $local_blockers ) ) );

		$remote_preflight_blockers = array_merge( $local_blockers, $oauth_blockers );
		if ( ! $https ) $remote_preflight_blockers[] = 'https_required_for_remote_mcp';
		$remote_preflight_blockers = array_values( array_unique( array_map( 'sanitize_key', $remote_preflight_blockers ) ) );

		$certification_blockers = $remote_preflight_blockers;
		if ( $admin_shallow ) $certification_blockers[] = 'deep_connection_diagnostics_deferred';
		if ( empty( $handshake['verified'] ) ) {
			$handshake_status = isset( $handshake['status'] ) ? sanitize_key( (string) $handshake['status'] ) : 'unverified';
			$certification_blockers[] = in_array( $handshake_status, array( 'stale_build_evidence', 'stale_tool_inventory_evidence', 'stale_time_evidence' ), true ) ? 'external_handshake_stale' : 'external_handshake_unverified';
		}
		$certification_blockers = array_values( array_unique( array_map( 'sanitize_key', $certification_blockers ) ) );
		$connection_certified = empty( $certification_blockers );
		$profile_enrolled = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled();
		$portable_readonly_ready = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) && MAD4B_SCP_Portable_Readonly_Connection::effective();
		$environment_key = sanitize_key( (string) $environment );
		$environment_supported = ( $profile_enrolled || $portable_readonly_ready ) && in_array( $environment_key, array( 'local', 'development', 'staging', 'production' ), true );

		return array(
			'contract' => self::CONTRACT,
			'environment' => $environment_key,
			'wordpress_environment' => class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::wordpress() : $environment_key,
			'wordpress_environment_explicit' => class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::wordpress_explicit() : defined( 'WP_ENVIRONMENT_TYPE' ),
			'effective_environment' => $environment_key,
			'environment_resolution' => class_exists( 'MAD4B_SCP_Environment' ) ? MAD4B_SCP_Environment::snapshot() : array(),
			'environment_supported' => $environment_supported,
			'site_profile_enrolled' => $profile_enrolled,
			'portable_readonly_ready' => $portable_readonly_ready,
			'environment_is_staging' => 'staging' === $environment_key,
			'site_url' => esc_url_raw( site_url() ),
			'home_url' => esc_url_raw( home_url() ),
			'rest_url' => esc_url_raw( rest_url() ),
			'https' => (bool) $https,
			'control_plane_version' => defined( 'MAD4B_SCP_VERSION' ) ? (string) MAD4B_SCP_VERSION : '',
			'mcp_adapter_available' => (bool) $adapter_available,
			'mcp_adapter_version' => $adapter_version,
			'mcp_adapter_certification' => $provider,
			'mcp_adapter_certified' => (bool) $provider_ok,
			'mcp_registration_lifecycle' => $mcp_registration_lifecycle,
			'local_transport_ready' => empty( $local_blockers ),
			'local_blockers' => $local_blockers,
			'remote_endpoint_preflight_ready' => empty( $remote_preflight_blockers ),
			'remote_preflight_blockers' => $remote_preflight_blockers,
			'connection_certified' => $connection_certified,
			'certification_blockers' => $certification_blockers,
			'servers' => $servers,
			'transport_deep_validation_deferred' => $lightweight || self::route_validation_deferred( $servers ),
			'explicit_deep_validation' => (bool) $force_deep,
			'status_mode' => $force_deep ? 'deep_explicit' : ( $protocol_hotpath ? 'protocol_identity' : ( $admin_shallow ? 'admin_shallow' : 'standard' ) ),
			'deferred_checks' => $lightweight ? array( 'route_permission_validation', 'mcp_peer_inventory', 'write_catalog_inventory', 'live_handshake_revalidation', 'provider_runtime_integrity' ) : array(),
			'write_surface' => self::write_surface_summary( $servers, $lightweight ),
			'provider_mcp_isolation' => self::bounded_isolation_status( $isolation ),
			'oauth_resource_server' => self::bounded_oauth_status( $oauth, $oauth_blockers ),
			'authentication' => array(
				'transport_model' => 'wordpress-authenticated-request-plus-server-bound-mad4b-transport-context',
				'credential_material_exposed' => false,
				'credential_creation_supported_here' => false,
				'current_request_subject' => self::identity_status( $identity ),
				'remote_subject_bridge_required' => true,
			),
			'external_handshake' => self::bounded_handshake_status( $handshake, $remote_preflight_blockers ),
			'mcp_peer_governance' => self::bounded_peer_summary( $peer ),
			'breakglass' => array(
				'configured_enabled' => defined( 'MAD4B_MCP_BREAKGLASS_ENABLED' ) && true === constant( 'MAD4B_MCP_BREAKGLASS_ENABLED' ),
				'effective_for_current_request' => class_exists( 'MAD4B_SCP_Policy' ) ? (bool) MAD4B_SCP_Policy::can_breakglass() : false,
			),
		);
	}

	private static function bounded_mcp_registration_lifecycle() {
		$status = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) ? MAD4B_SCP_MCP_Registration_Bridge::status() : array();
		if ( ! is_array( $status ) ) $status = array();
		return array(
			'rest_init_seen_before_bridge_boot' => ! empty( $status['rest_init_seen_before_bridge_boot'] ),
			'adapter_init_seen_before_bridge_boot' => ! empty( $status['adapter_init_seen_before_bridge_boot'] ),
			'missed_rest_recovery_scheduled' => ! empty( $status['missed_rest_recovery_scheduled'] ),
			'missed_rest_recovery_attempted' => ! empty( $status['missed_rest_recovery_attempted'] ),
			'missed_rest_recovery_succeeded' => ! empty( $status['missed_rest_recovery_succeeded'] ),
			'missed_rest_recovery_state' => isset( $status['missed_rest_recovery_state'] ) ? sanitize_key( (string) $status['missed_rest_recovery_state'] ) : '',
			'missed_rest_recovery_blocker' => isset( $status['missed_rest_recovery_blocker'] ) ? sanitize_key( (string) $status['missed_rest_recovery_blocker'] ) : '',
			'mcp_adapter_init_count' => isset( $status['mcp_adapter_init_count'] ) ? max( 0, (int) $status['mcp_adapter_init_count'] ) : 0,
			'rest_api_init_count' => isset( $status['rest_api_init_count'] ) ? max( 0, (int) $status['rest_api_init_count'] ) : 0,
			'first_rest_observed' => ! empty( $status['first_rest_observed'] ),
			'plugins_loaded_count_at_first_rest' => isset( $status['plugins_loaded_count_at_first_rest'] ) ? max( 0, (int) $status['plugins_loaded_count_at_first_rest'] ) : 0,
			'init_count_at_first_rest' => isset( $status['init_count_at_first_rest'] ) ? max( 0, (int) $status['init_count_at_first_rest'] ) : 0,
			'wp_loaded_count_at_first_rest' => isset( $status['wp_loaded_count_at_first_rest'] ) ? max( 0, (int) $status['wp_loaded_count_at_first_rest'] ) : 0,
			'doing_plugins_loaded_at_first_rest' => ! empty( $status['doing_plugins_loaded_at_first_rest'] ),
			'doing_init_at_first_rest' => ! empty( $status['doing_init_at_first_rest'] ),
			'jetengine_registry_class_loaded_at_first_rest' => ! empty( $status['jetengine_registry_class_loaded_at_first_rest'] ),
			'jetengine_registry_callback_present_at_first_rest' => ! empty( $status['jetengine_registry_callback_present_at_first_rest'] ),
			'jetengine_rest_manager_class_loaded_at_first_rest' => ! empty( $status['jetengine_rest_manager_class_loaded_at_first_rest'] ),
			'jetengine_rest_manager_callback_present_at_first_rest' => ! empty( $status['jetengine_rest_manager_callback_present_at_first_rest'] ),
			'mcp_adapter_callback_present_at_first_rest' => ! empty( $status['mcp_adapter_callback_present_at_first_rest'] ),
			'first_rest_classification' => isset( $status['first_rest_classification'] ) ? sanitize_key( (string) $status['first_rest_classification'] ) : 'not_observed',
			'caller_trace' => self::bounded_first_rest_caller_trace( isset( $status['caller_trace'] ) ? $status['caller_trace'] : array() ),
		);
	}

	private static function bounded_first_rest_caller_trace( $trace ) {
		$result = array();
		foreach ( is_array( $trace ) ? $trace : array() as $frame ) {
			if ( ! is_array( $frame ) ) continue;
			$file = isset( $frame['relative_file'] ) ? str_replace( '\\', '/', sanitize_text_field( (string) $frame['relative_file'] ) ) : '';
			if ( '' !== $file && ( '/' === substr( $file, 0, 1 ) || preg_match( '/^[A-Za-z]:\//', $file ) || false !== strpos( $file, '../' ) ) ) $file = '';
			$result[] = array(
				'class' => isset( $frame['class'] ) ? sanitize_text_field( (string) $frame['class'] ) : '',
				'function' => isset( $frame['function'] ) ? sanitize_text_field( (string) $frame['function'] ) : '',
				'relative_file' => $file,
				'line' => isset( $frame['line'] ) ? max( 0, (int) $frame['line'] ) : 0,
			);
			if ( count( $result ) >= 16 ) break;
		}
		return $result;
	}

	private static function admin_shallow_surface() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) return false;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification.
		return in_array( $page, array( 'mad4b-control-plane-connection', 'mad4b-control-plane-chatgpt' ), true );
	}

	private static function oauth_preflight_blockers( $oauth ) {
		if ( ! is_array( $oauth ) || isset( $oauth['available'] ) && false === $oauth['available'] ) return array( 'oauth_resource_bridge_unavailable' );
		$blockers = array();
		if ( empty( $oauth['configured'] ) ) $blockers[] = 'oauth_resource_bridge_not_configured';
		if ( empty( $oauth['issuer_configured'] ) ) $blockers[] = 'oauth_issuer_unconfigured';
		if ( empty( $oauth['wp_user_id'] ) ) $blockers[] = 'oauth_wp_subject_unconfigured';
		elseif ( empty( $oauth['wp_user_capable'] ) ) $blockers[] = 'oauth_wp_subject_invalid';
		if ( empty( $oauth['https'] ) ) $blockers[] = 'oauth_https_required';
		$env = isset( $oauth['environment'] ) ? sanitize_key( (string) $oauth['environment'] ) : '';
		$profile_ok = class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::oauth_enabled();
		$portable_ok = class_exists( 'MAD4B_SCP_Portable_Readonly_Connection' ) && MAD4B_SCP_Portable_Readonly_Connection::effective();
		$environment_allowed = ( $profile_ok || $portable_ok ) && ( in_array( $env, array( 'local', 'development', 'staging' ), true ) || ( 'production' === $env && ! empty( $oauth['production_approved'] ) ) );
		if ( ! $environment_allowed ) $blockers[] = 'oauth_environment_not_allowed';
		if ( empty( $oauth['effective'] ) && ! $blockers ) $blockers[] = 'oauth_resource_bridge_not_effective';
		return array_values( array_unique( $blockers ) );
	}

	private static function bounded_oauth_status( $oauth, array $blockers ) {
		if ( ! is_array( $oauth ) ) $oauth = array();
		$authoritative_metadata = class_exists( 'MAD4B_SCP_MCP_Client_Compatibility' ) ? MAD4B_SCP_MCP_Client_Compatibility::authoritative_well_known_url() : '';
		$metadata_candidates = isset( $oauth['authorization_server_metadata_urls'] ) && is_array( $oauth['authorization_server_metadata_urls'] ) ? array_slice( array_map( 'esc_url_raw', $oauth['authorization_server_metadata_urls'] ), 0, 4 ) : array();
		$accepted_algorithms = isset( $oauth['accepted_bearer_algorithms'] ) && is_array( $oauth['accepted_bearer_algorithms'] ) ? array_slice( array_map( 'sanitize_text_field', $oauth['accepted_bearer_algorithms'] ), 0, 8 ) : array();
		return array(
			'contract' => isset( $oauth['contract'] ) ? sanitize_text_field( (string) $oauth['contract'] ) : '',
			'available' => ! isset( $oauth['available'] ) || false !== $oauth['available'],
			'configured' => ! empty( $oauth['configured'] ),
			'effective' => ! empty( $oauth['effective'] ),
			'environment' => isset( $oauth['environment'] ) ? sanitize_key( (string) $oauth['environment'] ) : '',
			'production_approved' => ! empty( $oauth['production_approved'] ),
			'issuer' => isset( $oauth['issuer'] ) ? esc_url_raw( (string) $oauth['issuer'] ) : '',
			'issuer_configured' => ! empty( $oauth['issuer_configured'] ),
			'resource' => isset( $oauth['resource'] ) ? esc_url_raw( (string) $oauth['resource'] ) : '',
			'authoritative_metadata_url' => esc_url_raw( $authoritative_metadata ),
			'authorization_server_metadata_urls' => $metadata_candidates,
			'scopes_supported' => isset( $oauth['scopes_supported'] ) && is_array( $oauth['scopes_supported'] ) ? array_slice( array_map( 'sanitize_text_field', $oauth['scopes_supported'] ), 0, 20 ) : array(),
			'wp_user_id' => isset( $oauth['wp_user_id'] ) ? absint( $oauth['wp_user_id'] ) : 0,
			'wp_user_capable' => ! empty( $oauth['wp_user_capable'] ),
			'https' => ! empty( $oauth['https'] ),
			'accepted_bearer_algorithms' => $accepted_algorithms,
			'jwks_x5c_required' => ! empty( $oauth['jwks_x5c_required'] ),
			'jwks_rsa_ne_supported' => ! empty( $oauth['jwks_rsa_ne_supported'] ),
			'outbound_discovery_on_admin' => false,
			'deep_status_deferred' => isset( $oauth['projection'] ) && 'runtime_identity' === sanitize_key( (string) $oauth['projection'] ),
			'stores_bearer_tokens' => ! empty( $oauth['stores_bearer_tokens'] ),
			'creates_credentials' => ! empty( $oauth['creates_credentials'] ),
			'write_surfaces_enabled' => ! empty( $oauth['write_surfaces_enabled'] ),
			'preflight_ready' => empty( $blockers ),
			'blockers' => array_values( array_map( 'sanitize_key', $blockers ) ),
		);
	}

	private static function bounded_handshake_status( $handshake, array $remote_preflight_blockers ) {
		if ( ! is_array( $handshake ) ) $handshake = array();
		$verified = ! empty( $handshake['verified'] );
		return array(
			'contract' => isset( $handshake['contract'] ) ? sanitize_text_field( (string) $handshake['contract'] ) : '',
			'verified' => $verified,
			'status' => isset( $handshake['status'] ) ? sanitize_key( (string) $handshake['status'] ) : ( $verified ? 'verified_external_chatgpt_session' : ( empty( $remote_preflight_blockers ) ? 'requires_real_remote_mcp_session' : 'local_remote_preflight_incomplete' ) ),
			'environment' => isset( $handshake['environment'] ) ? sanitize_key( (string) $handshake['environment'] ) : '',
			'server_id' => isset( $handshake['server_id'] ) ? sanitize_key( (string) $handshake['server_id'] ) : '',
			'client_id' => isset( $handshake['client_id'] ) ? esc_url_raw( (string) $handshake['client_id'] ) : '',
			'resource' => isset( $handshake['resource'] ) ? esc_url_raw( (string) $handshake['resource'] ) : '',
			'issuer' => isset( $handshake['issuer'] ) ? esc_url_raw( (string) $handshake['issuer'] ) : '',
			'auth_method' => isset( $handshake['auth_method'] ) ? sanitize_key( (string) $handshake['auth_method'] ) : '',
			'wp_user_id' => isset( $handshake['wp_user_id'] ) ? absint( $handshake['wp_user_id'] ) : 0,
			'subject_fingerprint_present' => ! empty( $handshake['subject_fingerprint_present'] ),
			'mcp_session_fingerprint_present' => ! empty( $handshake['mcp_session_fingerprint_present'] ),
			'scope_set' => isset( $handshake['scope_set'] ) && is_array( $handshake['scope_set'] ) ? array_slice( array_map( 'sanitize_text_field', $handshake['scope_set'] ), 0, 20 ) : array(),
			'tool_count' => isset( $handshake['tool_count'] ) ? (int) $handshake['tool_count'] : 0,
			'expected_tool_count' => isset( $handshake['expected_tool_count'] ) ? (int) $handshake['expected_tool_count'] : 0,
			'write_tool_count' => isset( $handshake['write_tool_count'] ) ? (int) $handshake['write_tool_count'] : 0,
			'expected_write_tool_count' => isset( $handshake['expected_write_tool_count'] ) ? (int) $handshake['expected_write_tool_count'] : 0,
			'tool_inventory_fingerprint' => isset( $handshake['tool_inventory_fingerprint'] ) ? sanitize_text_field( (string) $handshake['tool_inventory_fingerprint'] ) : '',
			'expected_tool_inventory_fingerprint' => isset( $handshake['expected_tool_inventory_fingerprint'] ) ? sanitize_text_field( (string) $handshake['expected_tool_inventory_fingerprint'] ) : '',
			'tool_inventory_match' => ! empty( $handshake['tool_inventory_match'] ),
			'verified_at' => isset( $handshake['verified_at'] ) ? sanitize_text_field( (string) $handshake['verified_at'] ) : '',
			'build_fingerprint_match' => ! empty( $handshake['build_fingerprint_match'] ),
			'credential_material_stored' => false,
			'note' => $verified ? 'Verified from a real enrolled-site REST OAuth bearer session that completed MCP initialize and tools/list on the same hashed session identity. No bearer or raw MCP session id is persisted.' : 'Local readiness never self-certifies the external connection; a real ChatGPT OAuth/MCP session must complete initialize and tools/list.',
		);
	}

	private static function server_status( $protocol_hotpath = false ) {
		$ids = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::expected_server_ids() : array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-enrollment', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-developer', 'mad4b-developer-breakglass', 'mad4b-breakglass' );
		$registration = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::registration_status() : array();
		if ( $protocol_hotpath ) {
			$out = array();
			foreach ( $ids as $id ) {
				$row = isset( $registration[ $id ] ) && is_array( $registration[ $id ] ) ? $registration[ $id ] : array();
				$identity = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' ) && method_exists( 'MAD4B_SCP_MCP_Registration_Bridge', 'server_registration_identity_status' )
					? MAD4B_SCP_MCP_Registration_Bridge::server_registration_identity_status( $id )
					: array();
				$actual_registered = ! empty( $row['registered'] );
				$identity_ready = ! empty( $identity ) ? ! empty( $identity['identity_ready'] ) : $actual_registered;
				$out[] = array(
					'server_id' => $id,
					'registered' => $actual_registered,
					'registration_identity_ready' => $identity_ready,
					'registration_state' => isset( $identity['state'] ) ? sanitize_key( (string) $identity['state'] ) : ( $actual_registered ? 'registered' : 'not_ready' ),
					'registration_error' => isset( $identity['blocking_registration_error'] ) ? sanitize_key( (string) $identity['blocking_registration_error'] ) : ( isset( $row['error'] ) ? sanitize_key( (string) $row['error'] ) : '' ),
					'observed_registration_error' => isset( $identity['observed_registration_error'] ) ? sanitize_key( (string) $identity['observed_registration_error'] ) : ( isset( $row['error'] ) ? sanitize_key( (string) $row['error'] ) : '' ),
					'deep_registration_deferred' => ! empty( $identity['deep_registration_deferred'] ),
					'materialized' => ! empty( $row['materialized'] ),
					'tool_count' => isset( $row['tool_count'] ) ? max( 0, (int) $row['tool_count'] ) : 0,
					'route_registered' => null,
					'permission_callback_match' => null,
					'deep_route_validation_deferred' => true,
				);
			}
			return $out;
		}
		$expected_permissions = array(
			'mad4b-read' => array( 'MAD4B_SCP_Servers', 'can_read_transport' ),
			'mad4b-chatgpt' => array( 'MAD4B_SCP_Servers', 'can_chatgpt_transport' ),
			'mad4b-enrollment' => array( 'MAD4B_SCP_Servers', 'can_enrollment_transport' ),
			'mad4b-content' => array( 'MAD4B_SCP_Servers', 'can_content_transport' ),
			'mad4b-write' => array( 'MAD4B_SCP_Servers', 'can_write_transport' ),
			'mad4b-admin' => array( 'MAD4B_SCP_Servers', 'can_admin_transport' ),
			'mad4b-developer' => array( 'MAD4B_SCP_Servers', 'can_developer_transport' ),
			'mad4b-developer-breakglass' => array( 'MAD4B_SCP_Servers', 'can_developer_breakglass_transport' ),
			'mad4b-breakglass' => array( 'MAD4B_SCP_Servers', 'can_breakglass_transport' ),
		);
		$adapter_servers = array();
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			try {
				$adapter = \WP\MCP\Core\McpAdapter::instance();
				if ( is_object( $adapter ) && method_exists( $adapter, 'get_servers' ) ) {
					$found = $adapter->get_servers();
					if ( is_array( $found ) ) foreach ( $found as $server ) if ( is_object( $server ) && method_exists( $server, 'get_server_id' ) ) $adapter_servers[ (string) $server->get_server_id() ] = $server;
				}
			} catch ( Throwable $e ) { $adapter_servers = array(); }
		}

		$rest = class_exists( 'MAD4B_SCP_Provider_Diagnostic_Policy' )
			? MAD4B_SCP_Provider_Diagnostic_Policy::current_rest_server()
			: null;
		$rest_materialized = is_object( $rest ) && method_exists( $rest, 'get_routes' );
		try {
			$routes = $rest_materialized ? $rest->get_routes() : array();
		} catch ( Throwable $e ) { $routes = array(); }
		if ( ! is_array( $routes ) ) $routes = array();

		$out = array();
		foreach ( $ids as $id ) {
			$server = isset( $adapter_servers[ $id ] ) ? $adapter_servers[ $id ] : null;
			$namespace = 'mcp'; $route = '/' . $id; $permission = null; $server_version = '';
			if ( is_object( $server ) ) {
				try {
					if ( method_exists( $server, 'get_server_route_namespace' ) ) $namespace = trim( (string) $server->get_server_route_namespace(), '/' );
					if ( method_exists( $server, 'get_server_route' ) ) $route = '/' . ltrim( (string) $server->get_server_route(), '/' );
					if ( method_exists( $server, 'get_transport_permission_callback' ) ) $permission = $server->get_transport_permission_callback();
					if ( method_exists( $server, 'get_server_version' ) ) $server_version = (string) $server->get_server_version();
				} catch ( Throwable $e ) { $permission = null; }
			}
			$full_route = '/' . trim( $namespace, '/' ) . $route;
			$expected_permission = isset( $expected_permissions[ $id ] ) ? $expected_permissions[ $id ] : array();
			$out[] = array(
				'server_id' => $id,
				'registered' => ! empty( $registration[ $id ]['registered'] ),
				'materialized' => is_object( $server ),
				'registration_error' => isset( $registration[ $id ]['error'] ) ? sanitize_key( (string) $registration[ $id ]['error'] ) : '',
				'route_namespace' => $namespace,
				'route' => $route,
				'endpoint' => esc_url_raw( rest_url( trim( $namespace, '/' ) . $route ) ),
				'route_registered' => $rest_materialized ? array_key_exists( $full_route, $routes ) : null,
				'deep_route_validation_deferred' => ! $rest_materialized,
				'permission_callback' => self::callback_label( $permission ),
				'permission_callback_match' => self::callbacks_equal( $permission, $expected_permission ),
				'server_version' => $server_version,
				'surface' => self::surface_label( $id ),
			);
		}
		return $out;
	}

	private static function route_validation_deferred( array $servers ) {
		foreach ( $servers as $server ) {
			if ( is_array( $server ) && ! empty( $server['deep_route_validation_deferred'] ) ) return true;
		}
		return false;
	}

	private static function write_surface_summary( array $servers, $protocol_hotpath = false ) {
		$server = array();
		foreach ( $servers as $candidate ) {
			if ( isset( $candidate['server_id'] ) && 'mad4b-write' === $candidate['server_id'] ) { $server = $candidate; break; }
		}
		$tools = ( ! $protocol_hotpath && class_exists( 'MAD4B_SCP_Servers' ) ) ? MAD4B_SCP_Servers::write_tools() : array();
		return array(
			'server_id' => 'mad4b-write',
			'registered' => ! empty( $server['registered'] ),
			'registration_identity_ready' => ! empty( $server['registration_identity_ready'] ) || ! empty( $server['registered'] ),
			'transport_ready_for_projection' => $protocol_hotpath ? ( ! empty( $server['registration_identity_ready'] ) || ! empty( $server['registered'] ) ) : ! empty( $server['registered'] ),
			'route_registered' => $protocol_hotpath ? null : ! empty( $server['route_registered'] ),
			'permission_callback_match' => $protocol_hotpath ? null : ! empty( $server['permission_callback_match'] ),
			'endpoint' => isset( $server['endpoint'] ) ? esc_url_raw( $server['endpoint'] ) : esc_url_raw( rest_url( 'mcp/mad4b-write' ) ),
			'mounted_write_tool_count' => $protocol_hotpath ? null : ( is_array( $tools ) ? count( $tools ) : 0 ),
			'write_catalog_deferred' => (bool) $protocol_hotpath,
			'mutation_global_enabled' => defined( 'MAD4B_MCP_MUTATION_ENABLED' ) && true === MAD4B_MCP_MUTATION_ENABLED,
			'mutation_effective_for_current_request' => class_exists( 'MAD4B_SCP_Policy' ) ? (bool) MAD4B_SCP_Policy::can_mutate() : false,
			'exact_transport_grant_required' => true,
			'generic_dispatcher_exposed' => false,
		);
	}

	private static function identity_status( $identity ) {
		if ( is_wp_error( $identity ) ) return array( 'valid' => false, 'authenticated' => false, 'subject_type' => '', 'auth_method' => '', 'wp_user_id' => get_current_user_id(), 'error' => sanitize_key( $identity->get_error_code() ) );
		return array(
			'valid' => is_array( $identity ),
			'authenticated' => ! empty( $identity['authenticated'] ),
			'subject_type' => isset( $identity['subject_type'] ) ? sanitize_key( (string) $identity['subject_type'] ) : '',
			'auth_method' => isset( $identity['auth_method'] ) ? sanitize_key( (string) $identity['auth_method'] ) : '',
			'wp_user_id' => isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : get_current_user_id(),
			'subject_fingerprint_present' => ! empty( $identity['subject_fingerprint'] ),
			'token_scope_count' => isset( $identity['token_scopes'] ) && is_array( $identity['token_scopes'] ) ? count( $identity['token_scopes'] ) : 0,
			'error' => '',
		);
	}

	private static function bounded_isolation_status( $status ) {
		if ( ! is_array( $status ) ) $status = array();
		$routes = isset( $status['removed_routes'] ) && is_array( $status['removed_routes'] ) ? $status['removed_routes'] : array();
		return array(
			'contract' => isset( $status['contract'] ) ? sanitize_text_field( (string) $status['contract'] ) : '',
			'configured' => ! empty( $status['configured'] ),
			'effective' => ! empty( $status['effective'] ),
			'environment' => isset( $status['environment'] ) ? sanitize_key( (string) $status['environment'] ) : '',
			'production_approved' => ! empty( $status['production_approved'] ),
			'default_server_suppressed' => ! empty( $status['default_server_suppressed'] ),
			'wpmedia_oauth_server_suppressed' => ! empty( $status['wpmedia_oauth_server_suppressed'] ),
			'removed_route_count' => isset( $status['removed_route_count'] ) ? (int) $status['removed_route_count'] : 0,
			'removed_routes' => array_slice( array_map( 'sanitize_text_field', $routes ), 0, 100 ),
			'unknown_routes_fail_closed' => ! empty( $status['unknown_routes_fail_closed'] ),
			'changes_provider_settings' => ! empty( $status['changes_provider_settings'] ),
			'creates_authority' => ! empty( $status['creates_authority'] ),
		);
	}

	private static function bounded_peer_summary( $peer ) {
		if ( ! is_array( $peer ) ) return array( 'inventory_ready' => false, 'state' => 'unavailable', 'deep_inventory_performed' => false, 'blockers' => array( 'mcp_peer_inventory_unavailable' ) );
		$foreign = isset( $peer['foreign_transport_inventory'] ) && is_array( $peer['foreign_transport_inventory'] ) ? $peer['foreign_transport_inventory'] : array();
		$peers = array();
		foreach ( isset( $peer['peers'] ) && is_array( $peer['peers'] ) ? array_slice( $peer['peers'], 0, 100 ) : array() as $item ) {
			$reasons = array();
			foreach ( isset( $item['risks'] ) && is_array( $item['risks'] ) ? array_slice( $item['risks'], 0, 20 ) : array() as $risk ) if ( is_array( $risk ) && isset( $risk['reason'] ) ) $reasons[] = sanitize_key( (string) $risk['reason'] );
			$peers[] = array( 'server_id' => isset( $item['server_id'] ) ? sanitize_text_field( (string) $item['server_id'] ) : '', 'governed' => ! empty( $item['governed'] ), 'tool_count' => isset( $item['tool_count'] ) ? (int) $item['tool_count'] : 0, 'risk_count' => isset( $item['risk_count'] ) ? (int) $item['risk_count'] : 0, 'risk_reasons' => array_values( array_unique( $reasons ) ) );
		}
		return array(
			'inventory_ready' => ! empty( $peer['inventory_ready'] ),
			'state' => isset( $peer['state'] ) ? sanitize_key( (string) $peer['state'] ) : ( ! empty( $peer['inventory_ready'] ) ? 'ready' : 'unknown' ),
			'deep_inventory_performed' => ! empty( $peer['deep_inventory_performed'] ),
			'write_side_channel_detected' => ! empty( $peer['write_side_channel_detected'] ),
			'server_count' => isset( $peer['server_count'] ) ? (int) $peer['server_count'] : 0,
			'external_peer_count' => isset( $peer['external_peer_count'] ) ? (int) $peer['external_peer_count'] : 0,
			'risk_count' => isset( $peer['risk_count'] ) ? (int) $peer['risk_count'] : 0,
			'blockers' => isset( $peer['blockers'] ) && is_array( $peer['blockers'] ) ? array_values( array_map( 'sanitize_key', $peer['blockers'] ) ) : array(),
			'peers' => $peers,
			'foreign_transport' => array(
				'inventory_ready' => ! empty( $foreign['inventory_ready'] ),
				'detected' => ! empty( $foreign['foreign_mcp_detected'] ),
				'reviewed_non_transport_route_count' => isset( $foreign['reviewed_non_transport_route_count'] ) ? (int) $foreign['reviewed_non_transport_route_count'] : 0,
				'reviewed_non_transport_routes' => isset( $foreign['reviewed_non_transport_routes'] ) && is_array( $foreign['reviewed_non_transport_routes'] ) ? array_slice( array_map( 'sanitize_text_field', $foreign['reviewed_non_transport_routes'] ), 0, 100 ) : array(),
				'route_count' => isset( $foreign['foreign_route_count'] ) ? (int) $foreign['foreign_route_count'] : 0,
				'routes' => isset( $foreign['foreign_routes'] ) && is_array( $foreign['foreign_routes'] ) ? array_slice( array_map( 'sanitize_text_field', $foreign['foreign_routes'] ), 0, 100 ) : array(),
				'plugin_count' => isset( $foreign['foreign_plugin_count'] ) ? (int) $foreign['foreign_plugin_count'] : 0,
				'plugins' => isset( $foreign['foreign_plugins'] ) && is_array( $foreign['foreign_plugins'] ) ? array_slice( array_map( 'sanitize_text_field', $foreign['foreign_plugins'] ), 0, 100 ) : array(),
			),
		);
	}

	private static function callback_label( $callback ) {
		if ( is_array( $callback ) && 2 === count( $callback ) ) { $left = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0]; return sanitize_text_field( $left . '::' . (string) $callback[1] ); }
		if ( is_string( $callback ) ) return sanitize_text_field( $callback );
		if ( null === $callback ) return '';
		return 'callable';
	}

	private static function callbacks_equal( $actual, array $expected ) {
		if ( ! is_array( $actual ) || 2 !== count( $actual ) || 2 !== count( $expected ) ) return false;
		$actual_class = is_object( $actual[0] ) ? get_class( $actual[0] ) : (string) $actual[0];
		return $actual_class === (string) $expected[0] && (string) $actual[1] === (string) $expected[1];
	}

	private static function surface_label( $id ) {
		if ( 'mad4b-read' === $id ) return 'read';
		if ( 'mad4b-chatgpt' === $id ) return 'chatgpt-read';
		if ( 'mad4b-enrollment' === $id ) return 'enrollment';
		if ( 'mad4b-content' === $id ) return 'content';
		if ( 'mad4b-write' === $id ) return 'write';
		if ( 'mad4b-admin' === $id ) return 'admin';
		if ( 'mad4b-developer' === $id ) return 'developer';
		if ( 'mad4b-developer-breakglass' === $id ) return 'developer-breakglass';
		if ( 'mad4b-breakglass' === $id ) return 'breakglass';
		return 'unknown';
	}
}