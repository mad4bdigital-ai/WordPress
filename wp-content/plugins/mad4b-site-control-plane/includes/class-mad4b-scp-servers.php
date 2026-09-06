<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Servers {
	private static $registrations = array();

	public static function expected_server_ids() {
		return array( 'mad4b-read', 'mad4b-content', 'mad4b-write', 'mad4b-admin', 'mad4b-breakglass' );
	}

	public static function core_tools( $server_id ) {
		$map = array(
			'mad4b-read' => array(
				'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/list-plugins', 'mad4b/abilities-inventory', 'mad4b/filesystem-list', 'mad4b/filesystem-read',
				'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status', 'mad4b/connection-status',
			),
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

	/**
	 * Project only abilities that are explicitly annotated non-readonly from the
	 * existing content/admin authorities. This creates no generic dispatcher and
	 * does not infer write capability from names.
	 */
	public static function write_tools() {
		$candidates = array_merge(
			array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
			array(
				'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
				'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
			)
		);

		if ( class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) {
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			$candidates = array_merge( $candidates, $registry->ability_names( 'content' ), $registry->ability_names( 'admin' ) );
		}

		$write = array();
		foreach ( array_values( array_unique( $candidates ) ) as $ability_name ) {
			if ( ! function_exists( 'wp_get_ability' ) ) continue;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) continue;
			$write[] = (string) $ability_name;
		}
		return array_values( array_unique( $write ) );
	}

	private static function surface_for_server( $server_id ) {
		if ( 'mad4b-read' === $server_id ) return 'read';
		if ( 'mad4b-content' === $server_id ) return 'content';
		if ( 'mad4b-admin' === $server_id ) return 'admin';
		return '';
	}

	public static function ability_is_mounted( $server_id, $ability_name ) {
		return null !== self::provider_for_ability( $server_id, $ability_name );
	}

	public static function provider_for_ability( $server_id, $ability_name ) {
		$server_id = sanitize_key( (string) $server_id );
		$ability_name = (string) $ability_name;
		if ( ! in_array( $server_id, self::expected_server_ids(), true ) ) return null;

		if ( 'mad4b-write' === $server_id ) {
			if ( ! in_array( $ability_name, self::write_tools(), true ) ) return null;
			$core_candidates = array_merge(
				array( 'mad4b/content-get-post', 'mad4b/content-update-post' ),
				array(
					'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail',
					'mad4b/mutation-get', 'mad4b/mutation-undo', 'mad4b/agent-list', 'mad4b/agent-effective-access', 'mad4b/approval-plan',
				)
			);
			if ( in_array( $ability_name, $core_candidates, true ) ) return 'core';
			if ( ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
			$registry = MAD4B_SCP_Adapter_Registry::instance();
			$registry->register_defaults();
			foreach ( $registry->all() as $adapter ) {
				$map = $adapter->ability_names();
				foreach ( array( 'content', 'admin' ) as $surface ) {
					if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) {
						return method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() );
					}
				}
			}
			return null;
		}

		if ( in_array( $ability_name, self::core_tools( $server_id ), true ) ) return 'core';
		$surface = self::surface_for_server( $server_id );
		if ( '' === $surface || ! class_exists( 'MAD4B_SCP_Adapter_Registry' ) ) return null;
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		foreach ( $registry->all() as $adapter ) {
			$map = $adapter->ability_names();
			if ( isset( $map[ $surface ] ) && is_array( $map[ $surface ] ) && in_array( $ability_name, $map[ $surface ], true ) ) {
				return method_exists( $adapter, 'provider_key' ) ? $adapter->provider_key() : sanitize_key( (string) $adapter->id() );
			}
		}
		return null;
	}

	public static function registration_status() {
		$status = array();
		foreach ( self::expected_server_ids() as $id ) {
			$status[ $id ] = isset( self::$registrations[ $id ] ) ? self::$registrations[ $id ] : array( 'registered' => false, 'error' => 'not_registered' );
		}
		return $status;
	}

	public static function can_read_transport( $request = null ) {
		return self::transport_permission( 'mad4b-read', $request, array( 'MAD4B_SCP_Policy', 'can_read' ) );
	}
	public static function can_content_transport( $request = null ) {
		return self::transport_permission( 'mad4b-content', $request, array( 'MAD4B_SCP_Policy', 'can_content' ) );
	}
	public static function can_write_transport( $request = null ) {
		return self::transport_permission( 'mad4b-write', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) );
	}
	public static function can_admin_transport( $request = null ) {
		return self::transport_permission( 'mad4b-admin', $request, array( 'MAD4B_SCP_Policy', 'can_admin' ) );
	}
	public static function can_breakglass_transport( $request = null ) {
		return self::transport_permission( 'mad4b-breakglass', $request, array( 'MAD4B_SCP_Policy', 'can_breakglass' ) );
	}

	private static function transport_permission( $server_id, $request, $policy_callback ) {
		if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) ) return new WP_Error( 'mad4b_transport_context_unavailable', 'MAD4B transport context is unavailable.' );
		$bound = MAD4B_SCP_Transport_Context::bind( $server_id, $request );
		if ( is_wp_error( $bound ) ) return $bound;
		if ( ! is_callable( $policy_callback ) ) return new WP_Error( 'mad4b_transport_policy_unavailable', 'MAD4B transport permission policy is unavailable.' );
		return call_user_func( $policy_callback );
	}

	public function register_servers( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			foreach ( self::expected_server_ids() as $id ) self::$registrations[ $id ] = array( 'registered' => false, 'error' => 'adapter_contract_unavailable' );
			return;
		}

		$transport = '\\WP\\MCP\\Transport\\HttpTransport';
		$error_handler = '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler';
		$observability = '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler';
		$registry = MAD4B_SCP_Adapter_Registry::instance();

		$read_tools = array_merge( self::core_tools( 'mad4b-read' ), $registry->ability_names( 'read' ) );
		$content_tools = array_merge( self::core_tools( 'mad4b-content' ), $registry->ability_names( 'content' ) );
		$write_tools = self::write_tools();
		$admin_tools = array_merge( self::core_tools( 'mad4b-admin' ), $registry->ability_names( 'admin' ) );

		$this->create( $adapter, 'mad4b-read', 'MAD4B Read MCP', 'Read-only discovery and diagnostics for WordPress, plugin adapters, files and database.', array_values( array_unique( $read_tools ) ), array( __CLASS__, 'can_read_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-content', 'MAD4B Content MCP', 'Governed content, media, SEO and plugin-specific editing abilities.', array_values( array_unique( $content_tools ) ), array( __CLASS__, 'can_content_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-write', 'MAD4B Write MCP', 'Unified governed write ingress containing only abilities explicitly annotated non-readonly. Exact grants bind to this transport server.', array_values( array_unique( $write_tools ) ), array( __CLASS__, 'can_write_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-admin', 'MAD4B Admin MCP', 'Administrative governance, repair, mutation evidence and governed recovery abilities.', array_values( array_unique( $admin_tools ) ), array( __CLASS__, 'can_admin_transport' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-breakglass', 'MAD4B Breakglass MCP', 'Exceptional recovery surface. Disabled unless explicitly enabled in wp-config.php.', self::core_tools( 'mad4b-breakglass' ), array( __CLASS__, 'can_breakglass_transport' ), $transport, $error_handler, $observability );
	}

	private function create( $adapter, $id, $name, $description, array $tools, $permission, $transport, $error_handler, $observability ) {
		$result = $adapter->create_server( $id, 'mcp', $id, $name, $description, MAD4B_SCP_VERSION, array( $transport ), $error_handler, $observability, $tools, array(), array(), $permission );
		if ( is_wp_error( $result ) ) {
			self::$registrations[ $id ] = array( 'registered' => false, 'error' => $result->get_error_code() );
			error_log( '[MAD4B SCP] Failed creating ' . $id . ': ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		self::$registrations[ $id ] = array( 'registered' => true, 'error' => '' );
	}
}
