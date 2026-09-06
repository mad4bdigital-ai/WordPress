<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Servers {
	private static $registrations = array();

	public static function expected_server_ids() {
		return array( 'mad4b-read', 'mad4b-content', 'mad4b-admin', 'mad4b-breakglass' );
	}

	public static function registration_status() {
		$status = array();
		foreach ( self::expected_server_ids() as $id ) {
			$status[ $id ] = isset( self::$registrations[ $id ] ) ? self::$registrations[ $id ] : array( 'registered' => false, 'error' => 'not_registered' );
		}
		return $status;
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

		$read_tools = array_merge( array(
			'mad4b/site-info', 'mad4b/list-post-types', 'mad4b/list-plugins', 'mad4b/abilities-inventory', 'mad4b/filesystem-list', 'mad4b/filesystem-read', 'mad4b/database-list-tables', 'mad4b/database-describe-table', 'mad4b/database-select', 'mad4b/diagnostics-health', 'mad4b/runtime-authority-status',
		), $registry->ability_names( 'read' ) );

		$content_tools = array_merge( array( 'mad4b/content-get-post', 'mad4b/content-update-post' ), $registry->ability_names( 'content' ) );
		$admin_tools = array_merge( array( 'mad4b/plugin-activate', 'mad4b/plugin-deactivate', 'mad4b/filesystem-write', 'mad4b/filesystem-patch', 'mad4b/database-update', 'mad4b/audit-tail', 'mad4b/mutation-get', 'mad4b/mutation-undo' ), $registry->ability_names( 'admin' ) );

		$this->create( $adapter, 'mad4b-read', 'MAD4B Read MCP', 'Read-only discovery and diagnostics for WordPress, plugin adapters, files and database.', array_values( array_unique( $read_tools ) ), array( 'MAD4B_SCP_Policy', 'can_read' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-content', 'MAD4B Content MCP', 'Governed content, media, SEO and plugin-specific editing abilities.', array_values( array_unique( $content_tools ) ), array( 'MAD4B_SCP_Policy', 'can_content' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-admin', 'MAD4B Admin MCP', 'Administrative repair, mutation evidence and governed recovery abilities.', array_values( array_unique( $admin_tools ) ), array( 'MAD4B_SCP_Policy', 'can_admin' ), $transport, $error_handler, $observability );
		$this->create( $adapter, 'mad4b-breakglass', 'MAD4B Breakglass MCP', 'Exceptional recovery surface. Disabled unless explicitly enabled in wp-config.php.', array( 'mad4b/database-raw-query' ), array( 'MAD4B_SCP_Policy', 'can_breakglass' ), $transport, $error_handler, $observability );
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
