<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-mad4b-scp-post-identity.php';

final class MAD4B_SCP_Servers {
	private static $registrations = array();
	private static $adapter_write_projection_cache = null;
	private static $external_attestation_projection_active = false;

	public static function expected_server_ids() {
		return array( 'mad4b-read', 'mad4b-chatgpt', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' );
	}

	public static function core_tools( $server_id ) {
		$governed_status = array( 'mad4b/write-authority-status', 'mad4b/write-runtime-certification', 'mad4b/rest-compatibility-status' );
		$map = array(
			'mad4b-read' => array_merge( array(
				'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/post-identity', 'mad4b/list-plugins', 'mad4b/abilities-inventory', 'mad4b/filesystem-list', 'mad4b/filesystem-read',
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status',
			), $governed_status ),
			'mad4b-chatgpt' => array_merge( array(
				'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/post-identity', 'mad4b/list-plugins', 'mad4b/abilities-inventory',
				'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status',
			), $governed_status ),
			'mad4b-content' => array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
			'mad4b-admin' => array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			),
			'mad4b-breakglass' => array( 'mad4b/database-raw-query' ),
		);
		if ( 'mad4b-write' === $server_id ) return self::write_tools();
		return isset( $map[ $server_id ] ) ? $map[ $server_id ] : array();
	}

	private static function core_write_candidates() {
		return array_merge(
			array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
			array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			)
		);
	}

	private static function registered_adapter_write_candidates() {
		$result = array();
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return $result;
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		$surface_candidates = array_values( array_unique( array_merge(
			$registry->ability_names( 'content' ),
			$registry->ability_names( 'admin' ),
			$registry->ability_names( 'write' )
		) ) );
		foreach ( $registry->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'ability_names' ) ) continue;
			$map = $adapter->ability_names();
			$provider = method_exists( $adapter, 'provider_key' ) ? sanitize_key( (string) $adapter->provider_key() ) : sanitize_key( (string) $adapter->id() );
			foreach ( array( 'content', 'admin', 'write' ) as $surface ) {
				$abilities = isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ? $map[ $surface ] : array();
				foreach ( $abilities as $ability_name ) {
					$ability_name = (string) $ability_name;
					if ( in_array( $ability_name, $surface_candidates, true ) && self::registered_mutation_ability( $ability_name ) ) $result[ $ability_name ] = $provider;
				}
			}
		}
		ksort( $result, SORT_STRING );
		return $result;
	}

	/**
	 * Runtime-eligible write tools mounted on the dedicated mad4b-write authority.
	 * Provider certification remains a hard mount gate here.
	 */
	public static function write_tools() {
		$candidates = self::core_write_candidates();
		$projection = self::adapter_write_projection();
		$candidates = array_merge( $candidates, $projection['eligible'] );
		$write = array();
		foreach ( array_values( array_unique( $candidates ) ) as $ability_name ) {
			if ( self::registered_mutation_ability( $ability_name ) ) $write[] = (string) $ability_name;
		}
		sort( $write, SORT_STRING );
		return array_values( array_unique( $write ) );
	}

	/**
	 * Stable governed ChatGPT write catalog. Discovery is deliberately independent
	 * of provider activation state; execution still requires the ability to be in
	 * write_tools(), an exact NHI grant, and a one-time approval ticket.
	 */
	public static function external_write_tools() {
		$candidates = self::core_write_candidates();
		$candidates = array_merge( $candidates, array_keys( self::registered_adapter_write_candidates() ) );
		$write = array();
		foreach ( array_values( array_unique( $candidates ) ) as $ability_name ) {
			if ( self::registered_mutation_ability( $ability_name ) ) $write[] = (string) $ability_name;
		}
		sort( $write, SORT_STRING );
		return array_values( array_unique( array_diff( $write, array( 'mad4b/database-raw-query' ) ) ) );
	}

	public static function is_external_write_candidate( $ability_name ) {
		return in_array( (string) $ability_name, self::external_write_tools(), true );
	}

	public static function blocked_write_tools() {
		$projection = self::adapter_write_projection();
		$blocked = array_values( $projection['blocked'] );
		usort( $blocked, static function ( $a, $b ) {
			return strcmp( isset( $a['ability'] ) ? $a['ability'] : '', isset( $b['ability'] ) ? $b['ability'] : '' );
		} );
		return $blocked;
	}

	/**
	 * Re-project dynamic provider eligibility over immutable external inventory
	 * evidence at read time. A same-build certification transition therefore
	 * updates gated/eligible semantics without rewriting the captured MCP receipt
	 * or requiring a new tools/list scan.
	 */
	public static function filter_external_inventory_attestation_runtime( $stored ) {
		if ( self::$external_attestation_projection_active || ! is_array( $stored ) || empty( $stored ) ) return $stored;
		if ( ! class_exists( '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer' ) ) return $stored;

		self::$external_attestation_projection_active = true;
		$external_names = self::mcp_tool_names_from_abilities( self::external_write_tools() );
		$eligible_names = self::mcp_tool_names_from_abilities( self::write_tools() );
		$blocked_abilities = array();
		foreach ( self::blocked_write_tools() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['ability'] ) ) $blocked_abilities[] = (string) $entry['ability'];
		}
		$blocked_names = self::mcp_tool_names_from_abilities( $blocked_abilities );
		$provider_gated = array_values( array_intersect( $external_names, $blocked_names ) );
		$execution_leaks = array_values( array_intersect( $eligible_names, $blocked_names ) );

		$stored['eligible_write_tool_count'] = count( $eligible_names );
		$stored['expected_eligible_write_tool_count'] = count( $eligible_names );
		$stored['provider_gated_write_tool_count'] = count( $provider_gated );
		$stored['provider_gated_write_tools'] = $provider_gated;
		$stored['provider_execution_mount_leaks'] = $execution_leaks;
		$stored['provider_blocked_tool_leaks'] = $execution_leaks;
		$stored['runtime_projection_current'] = true;
		self::$external_attestation_projection_active = false;
		return $stored;
	}

	private static function mcp_tool_names_from_abilities( array $abilities ) {
		$names = array();
		foreach ( array_values( array_unique( array_map( 'strval', $abilities ) ) ) as $ability_name ) {
			$name = \WP\MCP\Domain\Utils\McpNameSanitizer::sanitize_name( $ability_name );
			if ( is_wp_error( $name ) || ! is_string( $name ) || '' === trim( $name ) ) continue;
			$names[] = trim( $name );
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	private static function registered_mutation_ability( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( '' === $ability_name || 'mad4b/database-raw-query' === $ability_name ) return false;
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return false;
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) return false;
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return false;
		return true;
	}

	private static function adapter_write_projection() {
		$result = array( 'eligible' => array(), 'blocked' => array() );
		if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return $result;
		$cacheable = function_exists( 'did_action' )
			&& did_action( 'wp_abilities_api_init' ) > 0
			&& ( ! function_exists( 'doing_action' ) || ! doing_action( 'wp_abilities_api_init' ) );
		if ( $cacheable && is_array( self::$adapter_write_projection_cache ) ) return self::$adapter_write_projection_cache;

		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'ability_names' ) ) continue;
			$map = $adapter->ability_names();
			$mutation_candidates = array();
			// `write` is a dedicated adapter surface for mutations that must exist on
			// mad4b-write but must not leak onto mad4b-content or mad4b-admin.
			foreach ( array( 'content', 'admin', 'write' ) as $surface ) {
				$abilities = isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) ? $map[ $surface ] : array();
				foreach ( $abilities as $ability_name ) {
					$ability_name = (string) $ability_name;
					if ( self::registered_mutation_ability( $ability_name ) ) $mutation_candidates[] = $ability_name;
				}
			}
			$mutation_candidates = array_values( array_unique( $mutation_candidates ) );
			if ( empty( $mutation_candidates ) ) continue;

			$status = method_exists( $adapter, 'status' ) ? $adapter->status() : array();
			$requires_certification = ! empty( $status['mutation_requires_certification'] );
			$certification = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
			$provider = method_exists( $adapter, 'provider_key' ) ? sanitize_key( (string) $adapter->provider_key() ) : sanitize_key( (string) $adapter->id() );
			$capability_cataloged = $requires_certification
				&& class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' )
				&& MAD4B_SCP_Provider_Compatibility_Certification::supports_provider( $provider );
			$capability_projection = $capability_cataloged
				? MAD4B_SCP_Provider_Compatibility_Certification::adapter_mount_projection( $provider, $adapter )
				: array();
			$capability_eligible = array();
			$capability_blocked = array();
			if ( $capability_cataloged ) {
				foreach ( (array) ( $capability_projection['eligible'] ?? array() ) as $entry ) if ( ! empty( $entry['ability'] ) ) $capability_eligible[ (string) $entry['ability'] ] = $entry;
				foreach ( (array) ( $capability_projection['blocked'] ?? array() ) as $entry ) if ( ! empty( $entry['ability'] ) ) $capability_blocked[ (string) $entry['ability'] ] = $entry;
			}
			$legacy_runtime_contract_ok = ! $requires_certification || ! empty( $certification['legacy_runtime_contract_ok'] ) || ! empty( $certification['runtime_contract_ok'] );

			foreach ( $mutation_candidates as $ability_name ) {
				if ( ! $requires_certification ) {
					$result['eligible'][] = $ability_name;
					continue;
				}
				if ( $capability_cataloged ) {
					if ( isset( $capability_eligible[ $ability_name ] ) ) {
						$result['eligible'][] = $ability_name;
						continue;
					}
					$entry = isset( $capability_blocked[ $ability_name ] ) ? $capability_blocked[ $ability_name ] : array();
					$result['blocked'][ $ability_name ] = array(
						'ability' => $ability_name,
						'provider' => $provider,
						'reason' => 'provider_capability_not_write_eligible',
						'capability_id' => isset( $entry['capability_id'] ) ? (string) $entry['capability_id'] : '',
						'certification_level' => isset( $entry['certification_level'] ) ? (string) $entry['certification_level'] : 'UNKNOWN',
						'runtime_status' => isset( $certification['status'] ) ? (string) $certification['status'] : 'unknown',
						'installed_version' => isset( $certification['installed_version'] ) ? (string) $certification['installed_version'] : '',
						'certified_version' => isset( $certification['certified_version'] ) ? (string) $certification['certified_version'] : '',
						'violations' => array( 'capability_write_certification_required' ),
					);
					continue;
				}
				if ( $legacy_runtime_contract_ok ) {
					$result['eligible'][] = $ability_name;
					continue;
				}
				$violations = class_exists( 'MAD4B_SCP_Provider_Contracts' )
					? MAD4B_SCP_Provider_Contracts::violations_for_status( $certification )
					: array( 'certification_authority_unavailable' );
				$result['blocked'][ $ability_name ] = array(
					'ability' => $ability_name,
					'provider' => $provider,
					'reason' => 'provider_runtime_contract_not_certified',
					'runtime_status' => isset( $certification['status'] ) ? (string) $certification['status'] : 'unknown',
					'installed_version' => isset( $certification['installed_version'] ) ? (string) $certification['installed_version'] : '',
					'certified_version' => isset( $certification['certified_version'] ) ? (string) $certification['certified_version'] : '',
					'violations' => array_values( array_unique( array_map( 'strval', $violations ) ) ),
				);
			}
		}
		$result['eligible'] = array_values( array_unique( $result['eligible'] ) );
		if ( $cacheable ) self::$adapter_write_projection_cache = $result;
		return $result;
	}

	public static function chatgpt_tools() {
		$core = self::core_tools( 'mad4b-chatgpt' );
		$adapter_candidates = array();
		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			$adapter_candidates = $registry->ability_names( 'read' );
		}
		$forbidden = array(
			'mad4b/filesystem-list', 'mad4b/filesystem-read',
			'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/database-raw-query',
		);
		$tools = array_values( array_diff( $core, $forbidden ) );
		foreach ( array_values( array_unique( $adapter_candidates ) ) as $ability_name ) {
			if ( in_array( $ability_name, $forbidden, true ) ) continue;
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) continue;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) continue;
			$tools[] = (string) $ability_name;
		}
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective() ) {
			$tools = array_merge( $tools, self::write_tools() );
			$gated_write_tools = array_values( array_diff( self::external_write_tools(), self::write_tools() ) );
			$tools = array_merge( $tools, $gated_write_tools );
		}
		return array_values( array_unique( $tools ) );
	}

	private static function surface_for_server( $server_id ) {
		if ( 'mad4b-read' === $server_id || 'mad4b-chatgpt' === $server_id ) return 'read';
		if ( 'mad4b-content' === $server_id ) return 'content';
		if ( 'mad4b-admin' === $server_id ) return 'admin';
		return '';
	}

	public static function ability_is_mounted( $server_id, $ability_name ) { return null !== self::provider_for_ability( $server_id, $ability_name ); }

	private static function provider_for_external_write_candidate( $ability_name ) {
		$ability_name = (string) $ability_name;
		if ( ! self::is_external_write_candidate( $ability_name ) ) return null;
		if ( in_array( $ability_name, self::core_write_candidates(), true ) ) return 'core';
		$candidates = self::registered_adapter_write_candidates();
		return isset( $candidates[ $ability_name ] ) ? $candidates[ $ability_name ] : null;
	}

	public static function provider_for_ability( $server_id, $ability_name ) {
		$server_id = sanitize_key( (string) $server_id );
		$ability_name = (string) $ability_name;
		if ( ! in_array( $server_id, self::expected_server_ids(), true ) ) return null;
		if ( 'mad4b-write' === $server_id ) {
			if ( ! in_array( $ability_name, self::write_tools(), true ) ) return null;
			if ( in_array( $ability_name, self::core_write_candidates(), true ) ) return 'core';
			$candidates = self::registered_adapter_write_candidates();
			return isset( $candidates[ $ability_name ] ) ? $candidates[ $ability_name ] : null;
		}
		if ( 'mad4b-chatgpt' === $server_id ) {
			if ( ! in_array( $ability_name, self::chatgpt_tools(), true ) ) return null;
			if ( class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective() && self::is_external_write_candidate( $ability_name ) ) {
				if ( null !== self::provider_for_ability( 'mad4b-write', $ability_name ) ) return self::provider_for_ability( 'mad4b-write', $ability_name );
				return self::provider_for_external_write_candidate( $ability_name );
			}
		}
		if ( in_array( $ability_name, self::core_tools( $server_id ), true ) ) return 'core';
		$surface = self::surface_for_server( $server_id );
		if ( '' === $surface || ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
		$registry = MAD4B_SCP_Adapter_Registry::instance(); $registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			$map = $adapter->ability_names();
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) return method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() );
		}
		return null;
	}

	public static function registration_status() {
		$status = array(); foreach ( self::expected_server_ids() as $id ) $status[ $id ] = isset( self::$registrations[ $id ] ) ? self::$registrations[ $id ] : array( 'registered' => false, 'error' => 'not_registered' ); return $status;
	}
	public static function can_read_transport( $request = null ) { return self::transport_permission( 'mad4b-read', $request, array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public static function can_chatgpt_transport( $request = null ) { return self::transport_permission( 'mad4b-chatgpt', $request, array( 'MAD4B_SCP_Policy', 'can_read' ) ); }
	public static function can_content_transport( $request = null ) { return self::transport_permission( 'mad4b-content', $request, array( 'MAD4B_SCP_Policy', 'can_content' ) ); }
	public static function can_write_transport( $request = null ) { return self::transport_permission( 'mad4b-write', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) ); }
	public static function can_admin_transport( $request = null ) { return self::transport_permission( 'mad4b-admin', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) ); }
	public static function can_breakglass_transport( $request = null ) { return self::transport_permission( 'mad4b-breakglass', $request, array( 'MAD4B_SCP_Policy', 'can_breakglass' ) ); }

	private static function transport_permission( $server_id, $request, $policy_callback ) {
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) ) return new WP_Error( 'mad4b_transport_context_unavailable', 'MAD4B transport context is unavailable.' );
		$bound = MAD4B_SCP_Transport_Context::bind( $server_id, $request ); if ( is_wp_error( $bound ) ) return $bound;
		if ( ! is_callable( $policy_callback ) ) return new WP_Error( 'mad4b_transport_policy_unavailable', 'MAD4B transport permission policy is unavailable.' );
		return call_user_func( $policy_callback );
	}

	public function register_servers( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) { foreach ( self::expected_server_ids() as $id ) self::$registrations[ $id ] = array( 'registered' => false, 'error' => 'adapter_contract_unavailable' ); return; }
		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$error_handler = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$read_tools = array_merge( self::core_tools( 'mad4b-read' ), $registry->ability_names( 'read' ) );
		$chatgpt_tools = self::chatgpt_tools();
		$content_tools = array_merge( self::core_tools( 'mad4b-content' ), $registry->ability_names( 'content' ) );
		$write_tools = self::write_tools();
		$admin_tools = array_merge( self::core_tools( 'mad4b-admin' ), $registry->ability_names( 'admin' ) );
		$chatgpt_write_ready = class_exists( 'MAD4B_SCP_Staging_Write_Authority' ) && MAD4B_SCP_Staging_Write_Authority::effective();
		$chatgpt_description = $chatgpt_write_ready ? 'ChatGPT governed gateway with read diagnostics plus a stable governed write catalog. Provider writes may be discoverable before activation but remain fail-closed until mounted on mad4b-write, exactly granted and approved. Generic filesystem/database introspection and breakglass remain excluded.' : 'ChatGPT-safe read gateway. Generic filesystem/database inspection and all content/write/admin/breakglass mutation surfaces are excluded.';
		$this->create( $adapter, 'mad4b-read', 'MAD4B Read MCP', 'Read-only discovery and diagnostics for WordPress, plugin adapters, files and database.', array_values( array_unique( $read_tools ) ), array( __CLASS__, 'can_read_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-chatgpt', 'MAD4B ChatGPT MCP', $chatgpt_description, $chatgpt_tools, array( __CLASS__, 'can_chatgpt_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-content', 'MAD4B Content MCP', 'Governed content, media, SEO and plugin-specific editing abilities.', array_values( array_unique( $content_tools ) ), array( __CLASS__, 'can_content_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-write', 'MAD4B Write MCP', 'Unified governed write authority containing every runtime-eligible registered content/admin/write mutation explicitly annotated non-readonly. Cataloged provider mutations are projected per ability from capability certification; legacy providers retain exact runtime certification; breakglass is excluded.', array_values( array_unique( $write_tools ) ), array( __CLASS__, 'can_write_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-admin', 'MAD4B Admin MCP', 'Administrative governance, repair, mutation evidence and governed recovery abilities.', array_values( array_unique( $admin_tools ) ), array( __CLASS__, 'can_admin_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-breakglass', 'MAD4B Breakglass MCP', 'Exceptional recovery surface. Disabled unless explicitly enabled in wp-config.php.', self::core_tools( 'mad4b-breakglass' ), array( __CLASS__, 'can_breakglass_transport' ), $transport, $error_handler, $observability );
	}

	private function create( $adapter, $id, $name, $description, array $tools, $permission, $transport, $error_handler, $observability ) {
		$result = $adapter->create_server( $id, 'mcp', $id, $name, $description, MAD4B_SCP_VERSION, array( $transport ), $error_handler, $observability, $tools, array(), array(), $permission );
		if ( is_wp_error( $result ) ) { self::$registrations[ $id ] = array( 'registered' => false, 'error' => $result->get_error_code() ); error_log( '[MAD4B SCP] Failed creating ' . $id . ': ' . $result->get_error_message() ); return; }
		self::$registrations[ $id ] = array( 'registered' => true, 'error' => '' );
	}
}

add_filter( 'option_mad4b_scp_external_inventory_attestation_v1', array( 'MAD4B_SCP_Servers', 'filter_external_inventory_attestation_runtime' ), 120 );
