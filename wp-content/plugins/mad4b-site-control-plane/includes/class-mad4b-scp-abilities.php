<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MAD4B_SCP_Abilities {
	const MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES = 65536;
	private static $write_dispatch_governance_envelope = array();
	private static $write_dispatch_governance_binding = '';
	private static function request_scope_admit( $surface ) {
		if ( ! class_exists( 'MAD4B_SCP_Request_Generation' ) ) return true;
		return MAD4B_SCP_Request_Generation::admit( $surface );
	}
	public static function request_scope_state() {
		return array(
			'write_governance_envelope_pending' => ! empty( self::$write_dispatch_governance_envelope ),
			'write_governance_binding_pending' => '' !== self::$write_dispatch_governance_binding,
		);
	}
	public static function reset_request_cache() {
		self::$write_dispatch_governance_envelope = array();
		self::$write_dispatch_governance_binding = '';
		return true;
	}
	public function register_categories() {
		foreach (
			array(
				'mad4b-read'       => array( 'label' => 'MAD4B Read', 'description' => 'Read-only discovery and diagnostics.' ),
				'mad4b-content'    => array( 'label' => 'MAD4B Content', 'description' => 'Governed content editing.' ),
				'mad4b-write'      => array( 'label' => 'MAD4B Write', 'description' => 'Unified governed write authority abilities.' ),
				'mad4b-admin'      => array( 'label' => 'MAD4B Admin', 'description' => 'Administrative repair abilities.' ),
				'mad4b-breakglass' => array( 'label' => 'MAD4B Breakglass', 'description' => 'Exceptional recovery abilities.' ),
			) as $slug => $args
		) {
			wp_register_ability_category( $slug, $args );
		}
	}

	public function register_abilities() {
		$this->add( 'mad4b/site-info', 'Get Site Info', 'mad4b-read', 'site_info', 'read', null, false, true, false, true );
		$this->add( 'mad4b/list-post-types', 'List Post Types', 'mad4b-read', 'list_post_types', 'read', null, false, true, false, true );
		$this->add( 'mad4b/list-plugins', 'List Plugins', 'mad4b-read', 'list_plugins', 'read', null, false, true, false, true );
		$this->add( 'mad4b/abilities-inventory', 'Abilities Inventory', 'mad4b-read', 'abilities_inventory', 'read', null, false, true, false, true );
		$this->add( 'mad4b/tool-discover', 'Discover Governed Read Abilities', 'mad4b-read', 'tool_discover', 'read', $this->schema(
			array(
				'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
			), array()
		), false, true, false, true );
		$this->add( 'mad4b/tool-info', 'Get Governed Read Ability Info', 'mad4b-read', 'tool_info', 'read', $this->schema(
			array( 'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ) ), array( 'ability_name' )
		), false, true, false, true );
		$this->add( 'mad4b/read-execute', 'Execute Governed Read Ability', 'mad4b-read', 'read_execute', 'read', $this->schema(
			array(
				'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
				'expected_input_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '[A-Fa-f0-9]{64}' ),
				'expected_execution_lane' => array( 'type' => 'string', 'enum' => array( 'read', 'write', 'content', 'admin', 'developer' ) ),
				'expected_classification_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'expected_authority_scope_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'preparation_receipt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 4096 ),
				'input' => array( 'type' => 'object', 'default' => array() ),
			), array( 'ability_name', 'expected_input_schema_sha256', 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' )
		), false, true, false, true );
		$this->add( 'mad4b/write-discover', 'Discover Governed Write Abilities', 'mad4b-read', 'write_discover', 'read', $this->schema(
			array(
				'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
			), array()
		), false, true, false, true );
		$this->add( 'mad4b/write-info', 'Get Governed Write Ability Info', 'mad4b-read', 'write_info', 'read', $this->schema(
			array( 'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ) ), array( 'ability_name' )
		), false, true, false, true );
		$this->add( 'mad4b/write-execute', 'Execute Governed Write Ability', 'mad4b-admin', 'write_execute', 'write_dispatch', $this->schema(
			array(
				'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
				'expected_input_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'expected_execution_lane' => array( 'type' => 'string', 'enum' => array( 'read', 'write', 'content', 'admin', 'developer' ) ),
				'expected_classification_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'expected_authority_scope_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'preparation_receipt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 4096 ),
				'_mad4b_approval_ticket_id' => array( 'type' => 'string', 'pattern' => MAD4B_SCP_Identifiers::APPROVAL_TICKET_SCHEMA_PATTERN ),
				'_mad4b_context_receipt' => array( 'type' => 'object', 'additionalProperties' => true ),
				'input' => array( 'type' => 'object', 'default' => array() ),
			), array( 'ability_name', 'expected_input_schema_sha256', 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' )
		), false, false, true, false );
		$this->add( 'mad4b/developer-discover', 'Discover Normal Developer Abilities', 'mad4b-read', 'developer_discover', 'read', $this->schema(
			array(
				'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
			), array()
		), false, true, false, true );
		$this->add( 'mad4b/developer-info', 'Get Normal Developer Ability Info', 'mad4b-read', 'developer_info', 'read', $this->schema(
			array( 'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ) ), array( 'ability_name' )
		), false, true, false, true );
		$this->add( 'mad4b/developer-execute', 'Execute Normal Developer Ability', 'mad4b-admin', 'developer_execute', 'developer_dispatch', $this->schema(
			array(
				'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
				'expected_input_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'expected_execution_lane' => array( 'type' => 'string', 'enum' => array( 'read', 'write', 'content', 'admin', 'developer' ) ),
				'expected_classification_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'expected_authority_scope_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'preparation_receipt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 4096 ),
				'input' => array( 'type' => 'object', 'default' => array() ),
			), array( 'ability_name', 'expected_input_schema_sha256', 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' )
		), false, false, true, false );
		$this->add( 'mad4b/enrollment-discover', 'Discover Bounded Enrollment Operations', 'mad4b-read', 'enrollment_discover', 'read', $this->schema(
			array(
				'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
			), array()
		), false, true, false, true );
		$this->add( 'mad4b/enrollment-info', 'Get Bounded Enrollment Operation Info', 'mad4b-read', 'enrollment_info', 'read', $this->schema(
			array( 'operation_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 96, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,95}$' ) ), array( 'operation_id' )
		), false, true, false, true );
		$this->add( 'mad4b/enrollment-execute', 'Execute Bounded Enrollment Operation', 'mad4b-admin', 'enrollment_execute', 'enrollment_dispatch', $this->schema(
			array(
				'operation_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 96, 'pattern' => '^[a-z0-9][a-z0-9._-]{0,95}$' ),
				'expected_registration_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'expected_dispatch_policy_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'expected_input_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'input' => array( 'type' => 'object', 'default' => array() ),
			), array( 'operation_id', 'expected_registration_digest', 'expected_dispatch_policy_digest', 'expected_input_schema_sha256' )
		), false, false, false, false );

		$this->add( 'mad4b/filesystem-list', 'List Files', 'mad4b-read', 'filesystem_list', 'read', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'default' => '' ),
			), array( 'root' )
		), false, true, false, true );
		$this->add( 'mad4b/filesystem-read', 'Read Text File', 'mad4b-read', 'filesystem_read', 'read', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'max_bytes' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1048576, 'default' => 262144 ),
			), array( 'root', 'path' )
		), false, true, false, true );
		$this->add( 'mad4b/database-list-tables', 'List Database Tables', 'mad4b-read', 'database_list_tables', 'read', null, false, true, false, true );
		$this->add( 'mad4b/database-describe-table', 'Describe Database Table', 'mad4b-read', 'database_describe_table', 'read', $this->schema(
			array( 'table' => array( 'type' => 'string', 'minLength' => 1 ) ), array( 'table' )
		), false, true, false, true );
		$this->add( 'mad4b/database-select', 'Select Database Rows', 'mad4b-read', 'database_select', 'read', $this->schema(
			array(
				'table' => array( 'type' => 'string', 'minLength' => 1 ),
				'columns' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'default' => array() ),
				'where' => array( 'type' => 'object', 'default' => array() ),
				'order_by' => array( 'type' => 'string', 'default' => '' ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50 ),
			), array( 'table' )
		), false, true, false, true );
		$this->add( 'mad4b/diagnostics-health', 'Diagnostics Health', 'mad4b-read', 'diagnostics_health', 'read', null, false, true, false, true );
		$this->add( 'mad4b/runtime-authority-status', 'Runtime Authority Status', 'mad4b-read', 'runtime_authority_status', 'read', null, false, true, false, true );
		$this->add( 'mad4b/schema-status', 'Schema Status', 'mad4b-read', 'schema_status', 'read', null, false, true, false, true );

		$this->add( 'mad4b/content-get-post', 'Get Post', 'mad4b-content', 'content_get_post', 'read_post', $this->post_schema(), false, true, false, true );
		$this->add( 'mad4b/content-update-post', 'Update Post', 'mad4b-content', 'content_update_post', 'edit_post', $this->schema(
			array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ),
				'dynamic_acceptance_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
				'post_title' => array( 'type' => 'string' ),
				'post_content' => array( 'type' => 'string' ),
				'post_excerpt' => array( 'type' => 'string' ),
				'post_status' => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish' ) ),
			), array( 'post_id', 'expected_modified_gmt' )
		), false, false, true, true );

		$this->add( 'mad4b/plugin-activate', 'Activate Plugin', 'mad4b-admin', 'plugin_activate', 'admin', $this->plugin_schema(), false, false, true, true );
		$this->add( 'mad4b/plugin-deactivate', 'Deactivate Plugin', 'mad4b-admin', 'plugin_deactivate', 'admin', $this->plugin_schema(), false, false, true, true );
		$this->add( 'mad4b/filesystem-write', 'Write Text File', 'mad4b-admin', 'filesystem_write', 'admin', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'content' => array( 'type' => 'string' ),
				'expected_sha256' => array( 'type' => 'string', 'default' => '' ),
				'allow_create' => array( 'type' => 'boolean', 'default' => false ),
				'create_backup' => array( 'type' => 'boolean', 'default' => true ),
			), array( 'root', 'path', 'content' )
		), false, false, true, true );
		$this->add( 'mad4b/filesystem-patch', 'Patch Text File', 'mad4b-admin', 'filesystem_patch', 'admin', $this->schema(
			array(
				'root' => array( 'type' => 'string', 'enum' => $this->roots() ),
				'path' => array( 'type' => 'string', 'minLength' => 1 ),
				'search' => array( 'type' => 'string', 'minLength' => 1 ),
				'replace' => array( 'type' => 'string' ),
				'expected_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'max_replacements' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1 ),
				'create_backup' => array( 'type' => 'boolean', 'default' => true ),
			), array( 'root', 'path', 'search', 'replace', 'expected_sha256' )
		), false, false, true, true );
		$this->add( 'mad4b/database-update', 'Update Database Rows', 'mad4b-admin', 'database_update', 'admin', $this->schema(
			array(
				'table' => array( 'type' => 'string', 'minLength' => 1 ),
				'data' => array( 'type' => 'object' ),
				'where' => array( 'type' => 'object' ),
				'max_affected' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1 ),
			), array( 'table', 'data', 'where' )
		), false, false, true, true );
		$this->add( 'mad4b/audit-tail', 'Read Audit Trail', 'mad4b-admin', 'audit_tail', 'admin', $this->schema(
			array( 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ) )
		), false, true, false, true );

		$this->add( 'mad4b/database-raw-query', 'Raw Database Query', 'mad4b-breakglass', 'database_raw_query', 'breakglass', $this->schema(
			array(
				'sql' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 50000 ),
				'max_rows' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			), array( 'sql', 'reason' )
		), false, false, true, false );

		do_action( 'mad4b_scp_abilities_registered', $this );
	}

	private function add( $name, $label, $category, $method, $permission, $input, $mcp_public, $readonly, $destructive, $idempotent ) {
		$mcp_meta = array( 'public' => false, 'type' => 'tool' );
		if ( in_array( (string) $name, array( 'mad4b/developer-discover', 'mad4b/developer-info', 'mad4b/developer-execute' ), true ) ) {
			$mcp_meta['surface'] = 'developer-dispatch';
			$mcp_meta['generic_remote_admin'] = false;
			$mcp_meta['production_mutation_allowed'] = false;
			$mcp_meta['breakglass_allowed'] = false;
		}
		if ( in_array( (string) $name, array( 'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute' ), true ) ) {
			$mcp_meta['surface'] = 'enrollment';
			$mcp_meta['generic_remote_admin'] = false;
			$mcp_meta['production_mutation_allowed'] = false;
		}
		if ( 'breakglass' === (string) $permission || 'mad4b-breakglass' === (string) $category ) {
			$mcp_meta['surface'] = 'breakglass';
			$mcp_meta['generic_remote_admin'] = false;
			$mcp_meta['production_mutation_allowed'] = false;
			$mcp_meta['breakglass_allowed'] = true;
		}
		$args = array(
			'label' => $label,
			'description' => $label . ' through the governed MAD4B Site Control Plane.',
			'category' => $category,
			'execute_callback' => array( $this, $method ),
			'permission_callback' => $this->mutation_permission_callback( $permission, (bool) $readonly, $name, $category ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => $mcp_meta,
				'annotations' => array(
					'readonly' => (bool) $readonly,
					'destructive' => $readonly ? (bool) $destructive : true,
					'idempotent' => (bool) $idempotent,
				),
			),
		);
		if ( is_array( $input ) ) $args['input_schema'] = $input;
		wp_register_ability( $name, $args );
	}

	private function mutation_permission_callback( $permission, $readonly, $ability_name, $server_id ) {
		if ( 'write_dispatch' === $permission ) return array( $this, 'can_write_dispatch' );
		if ( 'developer_dispatch' === $permission ) return array( $this, 'can_developer_dispatch' );
		if ( 'enrollment_dispatch' === $permission ) return array( $this, 'can_enrollment_dispatch' );
		$callback = $this->permission_callback( $permission );
		if ( $readonly ) return $callback;

		return function ( $input = null ) use ( $callback, $ability_name, $server_id ) {
			$granted = call_user_func( $callback, $input );
			if ( is_wp_error( $granted ) || ! $granted ) return $granted;
			if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_mutation_disabled', 'MAD4B mutation surfaces are disabled until the global mutation gate and a bound enabled NHI are both present.' );
			if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return new WP_Error( 'mad4b_authorization_unavailable', 'MAD4B central authorization is unavailable.' );
			$authorization = MAD4B_SCP_Authorization::authorize_mutation( $ability_name, $server_id, 'core', $input );
			if ( is_wp_error( $authorization ) ) return $authorization;
			return true;
		};
	}

	private function permission_callback( $permission ) {
		if ( 'read' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_read' );
		if ( 'admin' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_admin' );
		if ( 'breakglass' === $permission ) return array( 'MAD4B_SCP_Policy', 'can_breakglass' );
		if ( 'read_post' === $permission ) return array( $this, 'can_read_post' );
		if ( 'edit_post' === $permission ) return array( $this, 'can_edit_post' );
		return array( 'MAD4B_SCP_Policy', 'can_content' );
	}

	private function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}

	private function roots() { return array( 'wordpress', 'content', 'plugins', 'themes', 'uploads' ); }
	private function post_schema() { return $this->schema( array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'post_id' ) ); }
	private function plugin_schema() {
		return $this->schema(
			array(
				'plugin' => array( 'type' => 'string', 'minLength' => 1 ),
				'expected_active' => array( 'type' => 'boolean' ),
				'activation_scope' => array( 'type' => 'string', 'enum' => array( 'site', 'network' ), 'default' => 'site' ),
				'expected_state_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'expected_plan_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
			),
			array( 'plugin', 'expected_active', 'expected_state_sha256', 'expected_plan_sha256', 'reason' )
		);
	}

	public function site_info() {
		global $wpdb, $wp_version;
		return array( 'site' => array(
			'wordpress_version' => $wp_version,
			'php_version' => PHP_VERSION,
			'db_server_info' => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version(),
			'site_url' => site_url(), 'home_url' => home_url(), 'is_multisite' => is_multisite(),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			'control_plane' => MAD4B_SCP_VERSION,
			'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ),
			'breakglass' => MAD4B_SCP_Policy::can_breakglass(),
		) );
	}

	public function list_post_types() {
		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$items[] = array( 'name' => $name, 'label' => $object->label, 'public' => (bool) $object->public, 'show_ui' => (bool) $object->show_ui, 'show_in_rest' => (bool) $object->show_in_rest, 'hierarchical' => (bool) $object->hierarchical );
		}
		return array( 'post_types' => $items, 'count' => count( $items ) );
	}

	public function list_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$items = array();
		foreach ( get_plugins() as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			$items[] = array( 'file' => $file, 'name' => $name, 'version' => isset( $data['Version'] ) ? $data['Version'] : '', 'active' => is_plugin_active( $file ), 'network_active' => is_multisite() ? is_plugin_active_for_network( $file ) : false, 'adapter_namespace' => $this->suggest_adapter_namespace( $name . ' ' . $file ) );
		}
		return array( 'plugins' => $items, 'count' => count( $items ) );
	}

	private function suggest_adapter_namespace( $text ) {
		$text = strtolower( $text );
		$map = array( 'elementor' => 'elementor', 'jetengine' => 'jetengine', 'jet engine' => 'jetengine', 'jetsmartfilters' => 'jetsmartfilters', 'jet smart filters' => 'jetsmartfilters', 'bit integrations' => 'bitflows', 'bit flow' => 'bitflows', 'woocommerce' => 'woocommerce', 'rank math' => 'seo', 'seopress' => 'seo', 'yoast' => 'seo', 'polylang' => 'polylang', 'litespeed' => 'cache' );
		foreach ( $map as $needle => $namespace ) if ( false !== strpos( $text, $needle ) ) return $namespace;
		return 'unmapped';
	}

	public function abilities_inventory() {
		$items = array();
		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $name => $ability ) {
			$items[] = array( 'name' => $name, 'label' => method_exists( $ability, 'get_label' ) ? $ability->get_label() : '', 'description' => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '', 'category' => method_exists( $ability, 'get_category' ) ? $ability->get_category() : '' );
		}
		return array( 'abilities' => $items, 'count' => count( $items ) );
	}

	private function governed_read_target( $ability_name ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return new WP_Error( 'mad4b_dispatch_blog_switch_denied', 'Use a fresh request to the target site.' );
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_read_dispatch_target_required', 'A governed read ability_name is required.' );
		if ( in_array( $ability_name, array( 'mad4b/tool-discover', 'mad4b/tool-info', 'mad4b/read-execute' ), true ) ) return new WP_Error( 'mad4b_read_dispatch_recursion_denied', 'Nested read-dispatch execution is not allowed.' );
		// Universe admission is revalidated below; direct catalog size is not execution authority.
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_read_dispatch_target_unavailable', 'Requested ability is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_read_dispatch_contract_unavailable', 'Requested ability does not expose the required WordPress Ability contract.' );
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) return new WP_Error( 'mad4b_read_dispatch_mutation_denied', 'Only abilities explicitly annotated readonly=true may be executed through mad4b/read-execute.' );
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
		if ( is_wp_error( $row ) || 'read' !== $row['lane'] || empty( $row['execution_eligible'] ) ) return new WP_Error( 'mad4b_read_dispatch_sensitive_target_denied', 'Read annotation cannot downgrade the original authority lane.' );
		return $ability;
	}

	public function tool_discover( $input ) {
		$query = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$items = array();
		$candidates = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::chatgpt_full_catalog_candidates() : array();
		foreach ( $candidates as $ability_name ) {
			if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) continue;
			$ability = wp_get_ability( $ability_name );
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) continue;
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			if ( ! array_key_exists( 'readonly', $annotations ) || true !== $annotations['readonly'] ) continue;
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
			$haystack = strtolower( $ability_name . ' ' . $label . ' ' . $description . ' ' . $category );
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			$items[] = array( 'ability_name' => $ability_name, 'label' => $label, 'description' => $description, 'category' => $category, 'direct' => in_array( $ability_name, MAD4B_SCP_Servers::chatgpt_tools(), true ) );
			if ( count( $items ) >= $limit ) break;
		}
		return array( 'contract' => 'mad4b.chatgpt-read-discovery.v1', 'query' => $query, 'items' => $items, 'count' => count( $items ), 'read_only' => true, 'mutation_performed' => false );
	}

	public function tool_info( $input ) {
		$ability = $this->governed_read_target( $input['ability_name'] );
		if ( is_wp_error( $ability ) ) return $ability;
		return array(
			'contract' => 'mad4b.chatgpt-read-ability-info.v1',
			'ability_name' => (string) $input['ability_name'],
			'label' => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '',
			'description' => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'input_schema' => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			'input_schema_sha256' => $this->ability_input_schema_sha256( $ability ),
			'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
			'annotations' => ( $meta = $ability->get_meta() ) && isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array(),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public function read_execute( $input ) {
		$request_scope = self::request_scope_admit( 'read_execute' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_read_target( $ability_name );
		if ( is_wp_error( $ability ) ) return $ability;
		$pin = $this->validate_prepared_classification( $ability_name, $input );
		if ( is_wp_error( $pin ) ) return $pin;
		$actual_schema_sha256 = $this->ability_input_schema_sha256( $ability );
		$expected_schema_sha256 = isset( $input['expected_input_schema_sha256'] ) ? strtolower( trim( (string) $input['expected_input_schema_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_read_dispatch_schema_pin_required', 'Governed read execution requires an exact prepared input schema digest.' );
		if ( ! hash_equals( strtolower( $actual_schema_sha256 ), $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_read_dispatch_schema_drift', 'Read target schema changed after preparation.', array( 'ability_name' => $ability_name, 'current_input_schema_sha256' => $actual_schema_sha256 ) );
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		$params = array_key_exists( 'input', $input ) ? $input['input'] : null;
		$target_input_schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		if ( ( null === $target_input_schema || empty( $target_input_schema ) ) && is_array( $params ) && empty( $params ) ) $params = null;

		$execution = MAD4B_SCP_Connector_Resilience::execute_read(
			$ability_name,
			static function () use ( $ability, $params ) {
				return $ability->execute( $params );
			}
		);
		if ( is_wp_error( $execution ) ) return $execution;
		return array(
			'contract' => 'mad4b.chatgpt-read-execute.v1',
			'input_schema_sha256' => $actual_schema_sha256,
			'resilience_contract' => MAD4B_SCP_Connector_Resilience::CONTRACT,
			'ability_name' => $ability_name,
			'attempts' => isset( $execution['attempts'] ) ? (int) $execution['attempts'] : 1,
			'elapsed_ms' => isset( $execution['elapsed_ms'] ) ? (int) $execution['elapsed_ms'] : 0,
			'result' => array_key_exists( 'result', $execution ) ? $execution['result'] : null,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private function governed_developer_target( $ability_name ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return new WP_Error( 'mad4b_dispatch_blog_switch_denied', 'Use a fresh request to the target site.' );
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_developer_dispatch_target_required', 'A normal Developer ability_name is required.' );
		if ( in_array( $ability_name, array( 'mad4b/developer-discover', 'mad4b/developer-info', 'mad4b/developer-execute' ), true ) || 0 === strpos( $ability_name, 'mad4b/developer-breakglass-' ) ) return new WP_Error( 'mad4b_developer_dispatch_target_denied', 'Nested Developer dispatch and Breakglass targets are denied.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Runtime' ) || ! in_array( $ability_name, MAD4B_SCP_Developer_Runtime::tool_names( false ), true ) ) return new WP_Error( 'mad4b_developer_dispatch_target_not_cataloged', 'Requested ability is not in the normal Developer inventory.' );
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || 'core' !== MAD4B_SCP_Servers::provider_for_ability( 'mad4b-developer', $ability_name ) ) return new WP_Error( 'mad4b_developer_dispatch_target_unmounted', 'Requested Developer ability is not mounted on mad4b-developer.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_developer_dispatch_target_unavailable', 'Requested Developer ability is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_developer_dispatch_contract_unavailable', 'Requested Developer ability does not expose the required WordPress Ability contract.' );
		$meta = $ability->get_meta();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( 'developer' !== ( isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '' ) ) return new WP_Error( 'mad4b_developer_dispatch_surface_mismatch', 'Requested target is not on the normal Developer surface.' );
		return $ability;
	}

	public function can_developer_dispatch( $input = null ) {
		$request_scope = self::request_scope_admit( 'can_developer_dispatch' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		if ( ! MAD4B_SCP_Policy::can_admin() ) return false;
		if ( ! is_array( $input ) || empty( $input['ability_name'] ) || empty( $input['expected_input_schema_sha256'] ) ) return new WP_Error( 'mad4b_developer_dispatch_request_invalid', 'Developer dispatch requires an exact target and schema digest.' );
		$ability = $this->governed_developer_target( $input['ability_name'] );
		if ( is_wp_error( $ability ) ) return $ability;
		$prepared = $this->validate_prepared_classification( (string) $input['ability_name'], $input );
		if ( is_wp_error( $prepared ) ) return $prepared;
		$actual = $this->ability_input_schema_sha256( $ability );
		$expected = strtolower( trim( (string) $input['expected_input_schema_sha256'] ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( strtolower( $actual ), $expected ) ) return new WP_Error( 'mad4b_developer_dispatch_schema_drift', 'Developer target schema changed after discovery.' );
		if ( ! class_exists( 'MAD4B_SCP_Developer_Authority' ) || ! method_exists( 'MAD4B_SCP_Developer_Authority', 'chatgpt_dispatch_identity' ) ) return new WP_Error( 'mad4b_developer_dispatch_authority_unavailable', 'Bounded Developer dispatch authority is unavailable.' );
		$derived = MAD4B_SCP_Developer_Authority::chatgpt_dispatch_identity();
		return is_wp_error( $derived ) ? $derived : true;
	}

	public function developer_discover( $input ) {
		$query = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 20, absint( $input['limit'] ) ) ) : 10;
		$items = array();
		$candidates = class_exists( 'MAD4B_SCP_Developer_Runtime' ) ? MAD4B_SCP_Developer_Runtime::tool_names( false ) : array();
		foreach ( $candidates as $ability_name ) {
			$ability = $this->governed_developer_target( $ability_name );
			if ( is_wp_error( $ability ) ) continue;
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
			$meta = $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			$haystack = strtolower( $ability_name . ' ' . $label . ' ' . $description . ' ' . $category );
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			$items[] = array(
				'ability_name' => $ability_name,
				'label' => $label,
				'description' => $description,
				'category' => $category,
				'readonly' => isset( $annotations['readonly'] ) && true === $annotations['readonly'],
				'input_schema_sha256' => $this->ability_input_schema_sha256( $ability ),
				'approval_lane' => $this->developer_approval_lane( $ability_name, isset( $annotations['readonly'] ) && true === $annotations['readonly'] ),
			);
			if ( count( $items ) >= $limit ) break;
		}
		return array(
			'contract' => 'mad4b.chatgpt-developer-discovery.v1',
			'items' => $items,
			'count' => count( $items ),
			'production_authorized' => false,
			'breakglass_included' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	public function developer_info( $input ) {
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_developer_target( $ability_name );
		if ( is_wp_error( $ability ) ) return $ability;
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		return array(
			'contract' => 'mad4b.chatgpt-developer-ability-info.v1',
			'ability_name' => $ability_name,
			'label' => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '',
			'description' => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'input_schema' => method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null,
			'input_schema_sha256' => $this->ability_input_schema_sha256( $ability ),
			'annotations' => $annotations,
			'approval_lane' => $this->developer_approval_lane( $ability_name, isset( $annotations['readonly'] ) && true === $annotations['readonly'] ),
			'production_authorized' => false,
			'breakglass_included' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private function developer_approval_lane( $ability_name, $readonly ) {
		if ( $readonly ) return 'none';
		if ( class_exists( 'MAD4B_SCP_Impact_Policy' ) ) {
			$classification = MAD4B_SCP_Impact_Policy::classify( (string) $ability_name, 'core', array() );
			if ( is_array( $classification ) && isset( $classification['approval_lane'] ) && in_array( $classification['approval_lane'], array( 'ai_autonomous', 'human_only' ), true ) ) return (string) $classification['approval_lane'];
		}
		return 'human_only';
	}

	public function developer_execute( $input ) {
		$request_scope = self::request_scope_admit( 'developer_execute' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_developer_target( $ability_name );
		if ( is_wp_error( $ability ) ) return $ability;
		$pin = $this->validate_prepared_classification( $ability_name, $input );
		if ( is_wp_error( $pin ) ) return $pin;
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		$actual_schema_sha256 = $this->ability_input_schema_sha256( $ability );
		$expected_schema_sha256 = strtolower( trim( (string) $input['expected_input_schema_sha256'] ) );
		if ( ! hash_equals( strtolower( $actual_schema_sha256 ), $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_developer_dispatch_schema_drift', 'Developer target schema changed after discovery.', array( 'current_input_schema_sha256' => $actual_schema_sha256 ) );
		$params = array_key_exists( 'input', $input ) ? $input['input'] : null;
		$target_schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		if ( ( null === $target_schema || empty( $target_schema ) ) && is_array( $params ) && empty( $params ) ) $params = null;
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$readonly = isset( $annotations['readonly'] ) && true === $annotations['readonly'];

		$execute_target = static function () use ( $ability, $params, $ability_name, $actual_schema_sha256 ) {
			if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) || ! method_exists( 'MAD4B_SCP_Transport_Context', 'with_developer_dispatch_target' ) ) return new WP_Error( 'mad4b_developer_dispatch_transport_context_unavailable', 'Exact Developer dispatcher transport binding is unavailable.' );
			return MAD4B_SCP_Transport_Context::with_developer_dispatch_target(
				$ability_name,
				$actual_schema_sha256,
				static function () use ( $ability, $params ) { return $ability->execute( $params ); }
			);
		};

		$execution = $readonly
			? MAD4B_SCP_Connector_Resilience::execute_read( $ability_name, $execute_target )
			: MAD4B_SCP_Connector_Resilience::execute_mutation( 'developer', $ability_name, $execute_target );
		if ( is_wp_error( $execution ) ) return $execution;
		return array(
			'contract' => 'mad4b.chatgpt-developer-execute.v1',
			'resilience_contract' => MAD4B_SCP_Connector_Resilience::CONTRACT,
			'ability_name' => $ability_name,
			'input_schema_sha256' => $actual_schema_sha256,
			'result' => array_key_exists( 'result', $execution ) ? $execution['result'] : null,
			'mutation_performed' => ! $readonly,
			'production_mutation' => false,
			'breakglass_authorized' => false,
			'attempts' => isset( $execution['attempts'] ) ? (int) $execution['attempts'] : 1,
			'elapsed_ms' => isset( $execution['elapsed_ms'] ) ? (int) $execution['elapsed_ms'] : 0,
			'automatic_retry_performed' => false,
		);
	}

	private function validate_prepared_classification( $ability_name, array $input ) {
		$required = array( 'expected_execution_lane', 'expected_classification_sha256', 'expected_authority_scope_sha256', 'preparation_receipt' );
		foreach ( $required as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || '' === $input[ $key ] ) {
				return new WP_Error( 'mad4b_dispatch_preparation_required', 'Prepare the target again and supply its exact signed preparation identity.' );
			}
		}
		if ( ! class_exists( 'MAD4B_SCP_Preparation_Receipt' ) ) {
			return new WP_Error( 'mad4b_preparation_receipt_unavailable', 'Preparation evidence cannot be verified.' );
		}
		if ( ! in_array( $input['expected_execution_lane'], array( 'read', 'write', 'content', 'admin', 'developer' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $input['expected_classification_sha256'] )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $input['expected_authority_scope_sha256'] )
			|| strlen( $input['preparation_receipt'] ) > MAD4B_SCP_Preparation_Receipt::MAX_BYTES ) {
			return new WP_Error( 'mad4b_dispatch_preparation_required', 'Prepared execution identity is malformed; prepare the target again.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_Ability_Catalog_Transport' )
			|| ! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $input['expected_authority_scope_sha256'] ) ) {
			return new WP_Error( 'mad4b_dispatch_authority_scope_drift', 'Execution transport does not match the prepared site and authority context.' );
		}
		$receipt = MAD4B_SCP_Preparation_Receipt::verify( $input['preparation_receipt'], $ability_name );
		if ( is_wp_error( $receipt ) ) return $receipt;

		if ( ! class_exists( 'MAD4B_SCP_Capability_Descriptor_Registry' ) ) {
			return new WP_Error( 'mad4b_dispatch_classification_unavailable', 'Canonical capability descriptor cannot be revalidated.' );
		}
		$row = MAD4B_SCP_Capability_Descriptor_Registry::describe( $ability_name );
		if ( is_wp_error( $row ) || empty( $row['execution_eligible'] ) ) {
			return new WP_Error( 'mad4b_dispatch_classification_unavailable', 'Prepared target is no longer execution eligible.' );
		}
		if ( $row['execution_lane'] !== $input['expected_execution_lane'] ) {
			return new WP_Error( 'mad4b_dispatch_lane_drift', 'Original execution lane changed after preparation.' );
		}
		if ( ! hash_equals( $row['classification_sha256'], $input['expected_classification_sha256'] ) ) {
			return new WP_Error( 'mad4b_dispatch_classification_drift', 'Target classification changed after preparation.' );
		}
		return true;
	}

	private function governed_write_target( $ability_name, $require_runtime_eligible = false ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return new WP_Error( 'mad4b_dispatch_blog_switch_denied', 'Use a fresh request to the target site.' );
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_write_dispatch_target_required', 'A governed write ability_name is required.' );
		if ( in_array( $ability_name, array( 'mad4b/write-discover', 'mad4b/write-info', 'mad4b/write-execute' ), true ) ) return new WP_Error( 'mad4b_write_dispatch_recursion_denied', 'Nested write-dispatch execution is not allowed.' );
		if ( ! class_exists( 'MAD4B_SCP_Servers' ) || ! MAD4B_SCP_Servers::is_external_write_candidate( $ability_name ) ) return new WP_Error( 'mad4b_write_dispatch_target_not_cataloged', 'Requested ability is not in the stable governed write catalog.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_write_dispatch_target_unavailable', 'Requested write ability is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_write_dispatch_contract_unavailable', 'Requested write ability does not expose the required WordPress Ability contract.' );
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return new WP_Error( 'mad4b_write_dispatch_read_target_denied', 'Only abilities explicitly annotated readonly=false may be selected through mad4b/write-execute.' );
		if ( $require_runtime_eligible && ! in_array( $ability_name, MAD4B_SCP_Servers::write_tools(), true ) ) return new WP_Error( 'mad4b_write_dispatch_target_not_runtime_eligible', 'Requested write ability is not runtime-eligible on the dedicated governed write surface.' );
		return $ability;
	}

	private function ability_input_schema_sha256( $ability ) {
		$schema = is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$encoded = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) $encoded = 'null';
		return hash( 'sha256', $encoded );
	}

	public function can_write_dispatch( $input = null ) {
		$request_scope = self::request_scope_admit( 'can_write_dispatch' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		if ( ! MAD4B_SCP_Policy::can_admin() ) return false;
		if ( ! MAD4B_SCP_Policy::can_mutate() ) return new WP_Error( 'mad4b_write_dispatch_mutation_disabled', 'Governed mutation authority is not currently ready.' );
		if ( ! is_array( $input ) || empty( $input['ability_name'] ) || empty( $input['expected_input_schema_sha256'] ) ) return new WP_Error( 'mad4b_write_dispatch_target_required', 'A governed write target and prepared schema identity are required.' );
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_write_target( $ability_name, true );
		if ( is_wp_error( $ability ) ) return $ability;
		$prepared = $this->validate_prepared_classification( $ability_name, $input );
		if ( is_wp_error( $prepared ) ) return $prepared;
		$actual = $this->ability_input_schema_sha256( $ability );
		$expected = strtolower( trim( (string) $input['expected_input_schema_sha256'] ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( strtolower( $actual ), $expected ) ) return new WP_Error( 'mad4b_write_dispatch_schema_drift', 'Requested write ability input schema changed after planning.', array( 'ability_name' => $ability_name, 'current_input_schema_sha256' => $actual ) );
		// Governance metadata is bound only after the signed preparation contract
		// and exact schema identity have passed current-request revalidation.
		$captured = $this->capture_write_dispatch_governance_envelope( $input );
		if ( is_wp_error( $captured ) ) return $captured;
		return true;
	}

	public function write_discover( $input ) {
		$query = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$items = array();
		$candidates = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::external_write_tools() : array();
		$eligible = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::write_tools() : array();
		foreach ( $candidates as $ability_name ) {
			$ability = $this->governed_write_target( $ability_name, false );
			if ( is_wp_error( $ability ) ) continue;
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
			$haystack = strtolower( $ability_name . ' ' . $label . ' ' . $description . ' ' . $category );
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			$items[] = array(
				'ability_name' => $ability_name,
				'label' => $label,
				'description' => $description,
				'category' => $category,
				'runtime_eligible' => in_array( $ability_name, $eligible, true ),
				'input_schema_sha256' => $this->ability_input_schema_sha256( $ability ),
			);
			if ( count( $items ) >= $limit ) break;
		}
		return array( 'contract' => 'mad4b.chatgpt-write-discovery.v1', 'query' => $query, 'items' => $items, 'count' => count( $items ), 'read_only' => true, 'mutation_performed' => false );
	}

	public function write_info( $input ) {
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_write_target( $ability_name, false );
		if ( is_wp_error( $ability ) ) return $ability;
		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$meta = $ability->get_meta();
		return array(
			'contract' => 'mad4b.chatgpt-write-ability-info.v1',
			'ability_name' => $ability_name,
			'label' => method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '',
			'description' => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'input_schema' => $schema,
			'input_schema_sha256' => $this->ability_input_schema_sha256( $ability ),
			'annotations' => isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array(),
			'runtime_eligible' => in_array( $ability_name, MAD4B_SCP_Servers::write_tools(), true ),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private function governance_envelope_hash( $value ) {
		if ( class_exists( 'MAD4B_SCP_Ability_Contract_Inspector' ) ) {
			$digest = MAD4B_SCP_Ability_Contract_Inspector::digest( 'mad4b.write-dispatch-governance-value.v1', $value );
			return is_wp_error( $digest ) ? '' : (string) $digest;
		}
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : hash( 'sha256', $encoded );
	}

	private function write_dispatch_preparation_binding( $input ) {
		if ( ! is_array( $input ) ) return '';
		$keys = array(
			'ability_name',
			'expected_input_schema_sha256',
			'expected_execution_lane',
			'expected_classification_sha256',
			'expected_authority_scope_sha256',
			'preparation_receipt',
		);
		$identity = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || '' === $input[ $key ] ) return '';
			$identity[ $key ] = (string) $input[ $key ];
		}
		// Do not retain the signed receipt or mutation payload itself in the
		// request binding; hash both into the exact prepared invocation identity.
		$identity['preparation_receipt'] = hash( 'sha256', $identity['preparation_receipt'] );
		$target_input = array_key_exists( 'input', $input ) ? $input['input'] : null;
		$target_input_sha256 = $this->governance_envelope_hash( $target_input );
		if ( '' === $target_input_sha256 ) return '';
		$identity['target_input_sha256'] = $target_input_sha256;
		return $this->governance_envelope_hash( $identity );
	}

	private function capture_write_dispatch_governance_envelope( $input ) {
		if ( ! is_array( $input ) ) return true;
		$keys = array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt' );
		$present = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $input ) ) $present[] = $key;
		}
		$binding = $this->write_dispatch_preparation_binding( $input );
		if ( '' === $binding ) return new WP_Error( 'mad4b_write_dispatch_governance_binding_invalid', 'Governance evidence requires one exact prepared dispatcher identity.' );

		// WordPress/MCP may evaluate permission more than once. A later sanitized
		// preflight may reuse only the exact same prepared target; stale evidence
		// can never flow to another target in the same PHP request.
		if ( empty( $present ) ) {
			if ( ! empty( self::$write_dispatch_governance_envelope )
				&& ( '' === self::$write_dispatch_governance_binding || ! hash_equals( self::$write_dispatch_governance_binding, $binding ) ) ) {
				return new WP_Error( 'mad4b_write_dispatch_governance_target_conflict', 'Captured governance evidence belongs to a different prepared dispatcher target.' );
			}
			return true;
		}
		if ( ! empty( self::$write_dispatch_governance_envelope )
			&& ( '' === self::$write_dispatch_governance_binding || ! hash_equals( self::$write_dispatch_governance_binding, $binding ) ) ) {
			return new WP_Error( 'mad4b_write_dispatch_governance_target_conflict', 'Repeated dispatcher preflight attempted to move governance evidence to another prepared target.' );
		}

		$nested = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		$incoming = array();
		foreach ( $present as $key ) {
			$value = $input[ $key ];
			if ( '_mad4b_approval_ticket_id' === $key ) {
				$value = class_exists( 'MAD4B_SCP_Identifiers' ) ? MAD4B_SCP_Identifiers::approval_ticket_id( $value ) : '';
				if ( '' === $value ) return new WP_Error( 'mad4b_write_dispatch_approval_ticket_invalid', 'The dispatcher approval ticket identifier is malformed.' );
			} elseif ( ! is_array( $value ) ) {
				return new WP_Error( 'mad4b_write_dispatch_context_receipt_invalid', 'The dispatcher Context Receipt must be an object.' );
			} else {
				$receipt_json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$receipt_budget = class_exists( 'MAD4B_SCP_Context_Preflight', false )
					? MAD4B_SCP_Context_Preflight::MAX_RECEIPT_TRANSPORT_BYTES
					: self::MAX_WRITE_DISPATCH_CONTEXT_RECEIPT_BYTES;
				if ( ! is_string( $receipt_json ) || strlen( $receipt_json ) > $receipt_budget ) {
					return new WP_Error( 'mad4b_write_dispatch_context_receipt_oversized', 'The dispatcher Context Receipt exceeds its bounded transport budget.' );
				}
			}
			if ( array_key_exists( $key, $nested ) ) {
				$outer_hash = $this->governance_envelope_hash( $value );
				$inner_hash = $this->governance_envelope_hash( $nested[ $key ] );
				if ( '' === $outer_hash || '' === $inner_hash || ! hash_equals( $outer_hash, $inner_hash ) ) return new WP_Error( 'mad4b_write_dispatch_governance_envelope_conflict', 'Governance metadata in the dispatcher envelope conflicts with the selected target input.' );
			}
			$incoming[ $key ] = $value;
		}

		$next = self::$write_dispatch_governance_envelope;
		foreach ( $incoming as $key => $value ) {
			if ( array_key_exists( $key, $next ) ) {
				$current_hash = $this->governance_envelope_hash( $next[ $key ] );
				$incoming_hash = $this->governance_envelope_hash( $value );
				if ( '' === $current_hash || '' === $incoming_hash || ! hash_equals( $current_hash, $incoming_hash ) ) {
					return new WP_Error( 'mad4b_write_dispatch_governance_envelope_rebind_conflict', 'Repeated dispatcher preflight attempted to rebind governance metadata within the same request.' );
				}
				continue;
			}
			$next[ $key ] = $value;
		}

		self::$write_dispatch_governance_envelope = $next;
		self::$write_dispatch_governance_binding = $binding;
		return true;
	}

	private function forward_write_dispatch_governance_envelope( $dispatch_input, $target_input ) {
		$envelope = self::$write_dispatch_governance_envelope;
		$captured_binding = self::$write_dispatch_governance_binding;
		self::$write_dispatch_governance_envelope = array();
		self::$write_dispatch_governance_binding = '';
		if ( ! empty( $envelope ) ) {
			$current_binding = $this->write_dispatch_preparation_binding( $dispatch_input );
			if ( '' === $captured_binding || '' === $current_binding || ! hash_equals( $captured_binding, $current_binding ) ) {
				return new WP_Error( 'mad4b_write_dispatch_governance_target_conflict', 'Captured governance evidence cannot be forwarded to a different prepared dispatcher target.' );
			}
		}
		if ( is_array( $dispatch_input ) ) {
			foreach ( array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt' ) as $key ) {
				if ( ! array_key_exists( $key, $dispatch_input ) ) continue;
				if ( array_key_exists( $key, $envelope ) ) {
					$captured_hash = $this->governance_envelope_hash( $envelope[ $key ] );
					$execute_hash = $this->governance_envelope_hash( $dispatch_input[ $key ] );
					if ( '' === $captured_hash || '' === $execute_hash || ! hash_equals( $captured_hash, $execute_hash ) ) {
						return new WP_Error( 'mad4b_write_dispatch_governance_envelope_rebind_conflict', 'Governance metadata changed between dispatcher permission admission and target execution.' );
					}
					continue;
				}
				$envelope[ $key ] = $dispatch_input[ $key ];
			}
		}
		if ( empty( $envelope ) ) return $target_input;
		if ( null === $target_input ) $target_input = array();
		if ( ! is_array( $target_input ) ) return new WP_Error( 'mad4b_write_dispatch_governance_envelope_target_invalid', 'Governed dispatcher metadata requires an object target input.' );
		foreach ( $envelope as $key => $value ) {
			if ( array_key_exists( $key, $target_input ) ) {
				$outer_hash = $this->governance_envelope_hash( $value );
				$inner_hash = $this->governance_envelope_hash( $target_input[ $key ] );
				if ( '' === $outer_hash || '' === $inner_hash || ! hash_equals( $outer_hash, $inner_hash ) ) return new WP_Error( 'mad4b_write_dispatch_governance_envelope_conflict', 'Governance metadata in the dispatcher envelope conflicts with the selected target input.' );
				continue;
			}
			$target_input[ $key ] = $value;
		}
		return $target_input;
	}

	private function approval_plan_dispatch_preflight_failure( $error ) {
		$code = is_wp_error( $error ) ? sanitize_key( (string) $error->get_error_code() ) : 'unknown';
		$data = is_wp_error( $error ) ? $error->get_error_data() : array();
		$data = is_array( $data ) ? $data : array();
		return new WP_Error(
			'mad4b_approval_plan_dispatch_preflight_failed',
			'Approval planning stopped before ticket creation with blocker: ' . ( '' !== $code ? $code : 'unknown' ) . '.',
			array_merge( $data, array(
				'original_error_code' => $code,
				'mutation_state' => 'not_started',
				'target_execution_entered' => false,
				'reconciliation_required' => false,
				'blind_retry_allowed' => false,
				'fresh_plan_required' => true,
				'client_action' => 'correct_plan_input_or_authority_then_replan',
			) )
		);
	}

	public function write_execute( $input ) {
		$request_scope = self::request_scope_admit( 'write_execute' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		$ability_name = (string) $input['ability_name'];
		$ability = $this->governed_write_target( $ability_name, true );
		if ( is_wp_error( $ability ) ) return $ability;
		$pin = $this->validate_prepared_classification( $ability_name, $input );
		if ( is_wp_error( $pin ) ) return $pin;
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		$actual_schema_sha256 = $this->ability_input_schema_sha256( $ability );
		$expected_schema_sha256 = strtolower( (string) $input['expected_input_schema_sha256'] );
		if ( ! hash_equals( strtolower( $actual_schema_sha256 ), $expected_schema_sha256 ) ) return new WP_Error( 'mad4b_write_dispatch_schema_drift', 'Requested write ability input schema changed after planning.', array( 'ability_name' => $ability_name, 'current_input_schema_sha256' => $actual_schema_sha256 ) );
		$params = array_key_exists( 'input', $input ) ? $input['input'] : null;
		$target_input_schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		if ( ( null === $target_input_schema || empty( $target_input_schema ) ) && is_array( $params ) && empty( $params ) ) $params = null;
		$params = $this->forward_write_dispatch_governance_envelope( $input, $params );
		if ( is_wp_error( $params ) ) return $params;
		$approval_ticket_id = class_exists( 'MAD4B_SCP_Staging_Write_Authority' )
			? MAD4B_SCP_Staging_Write_Authority::approval_ticket_from_input( $params )
			: ( is_array( $params ) && isset( $params['_mad4b_approval_ticket_id'] ) ? strtolower( trim( (string) $params['_mad4b_approval_ticket_id'] ) ) : '' );
		$with_approval_scope = static function ( $callback ) use ( $approval_ticket_id ) {
			if ( ! is_callable( $callback ) ) return new WP_Error( 'mad4b_write_dispatch_callback_invalid', 'Governed write execution callback is invalid.' );
			if ( '' === $approval_ticket_id ) return call_user_func( $callback );
			if ( ! class_exists( 'MAD4B_SCP_Identity_Context' ) || ! method_exists( 'MAD4B_SCP_Identity_Context', 'with_approval_ticket_for_request' ) ) {
				return new WP_Error( 'mad4b_write_dispatch_approval_scope_unavailable', 'Approval ticket attribution cannot be scoped to this governed execution.' );
			}
			return MAD4B_SCP_Identity_Context::with_approval_ticket_for_request( $approval_ticket_id, $callback );
		};

		// WordPress Abilities normalizes a failed permission_callback to
		// ability_invalid_permissions. For approval-plan that would erase the
		// precise planning blocker (server/provider/agent/context drift) and could
		// incorrectly imply a ticket may have been created. Run the pure planner
		// validation before Ability::execute so invalid plans fail as not-started.
		$approval_plan_ability = class_exists( 'MAD4B_SCP_Staging_Write_Planning_Guard' ) ? MAD4B_SCP_Staging_Write_Planning_Guard::ABILITY : 'mad4b/approval-plan';
		if ( $approval_plan_ability === $ability_name && ! class_exists( 'MAD4B_SCP_Staging_Write_Planning_Guard' ) ) {
			return $this->approval_plan_dispatch_preflight_failure( new WP_Error( 'mad4b_approval_plan_guard_unavailable', 'Remote approval planning guard is unavailable.' ) );
		}
		if ( class_exists( 'MAD4B_SCP_Staging_Write_Planning_Guard' ) && MAD4B_SCP_Staging_Write_Planning_Guard::ABILITY === $ability_name ) {
			$params = MAD4B_SCP_Staging_Write_Planning_Guard::canonicalize_remote_plan_input( $params );
			if ( is_wp_error( $params ) ) return $this->approval_plan_dispatch_preflight_failure( $params );
			$planner_preflight = MAD4B_SCP_Staging_Write_Planning_Guard::validate_remote_plan_input( $params );
			if ( is_wp_error( $planner_preflight ) ) return $this->approval_plan_dispatch_preflight_failure( $planner_preflight );
		}

		$target_entered = false;
		if ( class_exists( 'MAD4B_SCP_Authorization' ) && method_exists( 'MAD4B_SCP_Authorization', 'begin_execution_callback_observation' ) ) {
			MAD4B_SCP_Authorization::begin_execution_callback_observation( $ability_name );
		}
		$execute_target = static function () use ( $ability, $params, $ability_name, $actual_schema_sha256, &$target_entered ) {
			if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) || ! method_exists( 'MAD4B_SCP_Transport_Context', 'with_write_dispatch_target' ) ) {
				return new WP_Error( 'mad4b_write_dispatch_transport_context_unavailable', 'Exact nested write-dispatch transport binding is unavailable.' );
			}
			return MAD4B_SCP_Transport_Context::with_write_dispatch_target(
				$ability_name,
				$actual_schema_sha256,
				static function () use ( $ability, $params, $ability_name, &$target_entered ) {
					try {
						return $ability->execute( $params );
					} finally {
						$target_entered = class_exists( 'MAD4B_SCP_Authorization' )
							&& method_exists( 'MAD4B_SCP_Authorization', 'execution_callback_started' )
							? MAD4B_SCP_Authorization::execution_callback_started( $ability_name )
							: true;
					}
				}
			);
		};

		if ( 'mad4b/approval-plan' === $ability_name ) {
			$started = microtime( true );
			try {
				try {
					$planner_result = $with_approval_scope( $execute_target );
				} catch ( \Throwable $throwable ) {
					return new WP_Error(
						'mad4b_approval_plan_dispatch_exception',
						'Approval planning failed inside the governed target. Reconcile Approval Decisions before retrying.',
						array(
							'mutation_state' => 'unknown',
							'reconciliation_required' => true,
							'blind_retry_allowed' => false,
							'error_class' => get_class( $throwable ),
						)
					);
				}
				if ( is_wp_error( $planner_result ) ) {
					$original_code = sanitize_key( (string) $planner_result->get_error_code() );
					if ( ! $target_entered ) return $this->approval_plan_dispatch_preflight_failure( $planner_result );
					return new WP_Error(
						'mad4b_approval_plan_dispatch_target_error',
						'Approval planning failed with blocker: ' . ( '' !== $original_code ? $original_code : 'unknown' ) . '. Reconcile Approval Decisions before retrying.',
						array(
							'original_error_code' => $original_code,
							'mutation_state' => 'unconfirmed_pending_ticket',
							'reconciliation_required' => true,
							'blind_retry_allowed' => false,
						)
					);
				}
				$execution = array(
					'result' => $planner_result,
					'attempts' => 1,
					'elapsed_ms' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ),
					'automatic_retry_performed' => false,
				);
			} finally {
				if ( class_exists( 'MAD4B_SCP_Authorization' ) && method_exists( 'MAD4B_SCP_Authorization', 'clear_execution_callback_observation' ) ) {
					MAD4B_SCP_Authorization::clear_execution_callback_observation( $ability_name );
				}
			}
		} else {
			try {
				$execution = $with_approval_scope(
					static function () use ( $ability_name, $execute_target ) {
						return MAD4B_SCP_Connector_Resilience::execute_mutation(
							'write',
							$ability_name,
							static function () use ( $execute_target ) {
								return $execute_target();
							}
						);
					}
				);
			} finally {
				if ( class_exists( 'MAD4B_SCP_Authorization' ) && method_exists( 'MAD4B_SCP_Authorization', 'clear_execution_callback_observation' ) ) {
					MAD4B_SCP_Authorization::clear_execution_callback_observation( $ability_name );
				}
			}
			if ( is_wp_error( $execution ) ) {
				if ( ! $target_entered ) {
					$data = $execution->get_error_data();
					$data = is_array( $data ) ? $data : array();
					$original_error_code = isset( $data['original_error_code'] ) ? sanitize_key( (string) $data['original_error_code'] ) : '';
					if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
						MAD4B_SCP_Audit::record( 'mad4b/write-dispatch-target-not-started', array(
							'target_ability' => $ability_name,
							'original_error_code' => $original_error_code,
							'mutation_state' => 'not_started',
							'reconciliation_required' => false,
						) );
					}
					return new WP_Error(
						'mad4b_write_dispatch_target_not_started',
						'Governed write dispatch stopped before the target execution boundary. Repair dispatch or runtime mounting, then create a fresh exact plan before retrying.',
						array(
							'ability_name' => $ability_name,
							'original_error_code' => $original_error_code,
							'category' => isset( $data['category'] ) ? sanitize_key( (string) $data['category'] ) : 'unknown',
							'client_action' => 'repair_dispatch_then_replan',
							'mutation_state' => 'not_started',
							'target_execution_entered' => false,
							'reconciliation_required' => false,
							'blind_retry_allowed' => false,
							'fresh_plan_required' => true,
							'raw_error_message_exposed' => false,
						)
					);
				}
				return $execution;
			}
		}
		return array(
			'contract' => 'mad4b.chatgpt-write-execute.v1',
			'resilience_contract' => MAD4B_SCP_Connector_Resilience::CONTRACT,
			'ability_name' => $ability_name,
			'input_schema_sha256' => $actual_schema_sha256,
			'result' => array_key_exists( 'result', $execution ) ? $execution['result'] : null,
			'mutation_performed' => true,
			'attempts' => 1,
			'elapsed_ms' => isset( $execution['elapsed_ms'] ) ? (int) $execution['elapsed_ms'] : 0,
			'automatic_retry_performed' => false,
		);
	}

	private function governed_enrollment_target( $ability_name ) {
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name ) return new WP_Error( 'mad4b_enrollment_dispatch_target_required', 'A bounded enrollment ability_name is required.' );
		if ( in_array( $ability_name, array( 'mad4b/enrollment-discover', 'mad4b/enrollment-info', 'mad4b/enrollment-execute' ), true ) ) return new WP_Error( 'mad4b_enrollment_dispatch_recursion_denied', 'Nested enrollment-dispatch execution is not allowed.' );
		if ( ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) || ! method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'enrollment_abilities' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_catalog_unavailable', 'Bounded enrollment operation catalog is unavailable.' );
		$allowed = array_values( array_unique( array_map( 'strval', MAD4B_SCP_Remote_Operation_Parity::enrollment_abilities() ) ) );
		if ( ! in_array( $ability_name, $allowed, true ) ) return new WP_Error( 'mad4b_enrollment_dispatch_target_not_allowlisted', 'Requested ability is not a bounded Remote Operation Parity enrollment operation.' );
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_enrollment_dispatch_target_unavailable', 'Requested enrollment ability is not registered in the current runtime.' );
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_contract_unavailable', 'Requested enrollment ability does not expose the required WordPress Ability contract.' );
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return new WP_Error( 'mad4b_enrollment_dispatch_read_target_denied', 'Only explicitly mutating enrollment operations may be selected through mad4b/enrollment-execute.' );
		if ( 'enrollment' !== ( isset( $mcp['surface'] ) ? (string) $mcp['surface'] : '' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_surface_mismatch', 'Requested ability is not registered on the bounded enrollment surface.' );
		if ( empty( $mcp['mad4b_remote_operation_parity'] ) || ! hash_equals( (string) MAD4B_SCP_Remote_Operation_Parity::CONTRACT, (string) $mcp['mad4b_remote_operation_parity'] ) ) return new WP_Error( 'mad4b_enrollment_dispatch_contract_mismatch', 'Requested ability is not covered by the Remote Operation Parity contract.' );
		if ( ! empty( $mcp['generic_remote_admin'] ) ) return new WP_Error( 'mad4b_enrollment_dispatch_generic_admin_denied', 'Generic remote administration is not permitted through the enrollment dispatcher.' );
		if ( ! empty( $mcp['production_mutation_allowed'] ) ) return new WP_Error( 'mad4b_enrollment_dispatch_production_denied', 'Production mutation is not permitted through the enrollment dispatcher.' );
		return $ability;
	}

	public function can_enrollment_dispatch( $input = null ) {
		$request_scope = self::request_scope_admit( 'can_enrollment_dispatch' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_policy_unavailable', 'Bounded Enrollment dispatch policy service is unavailable.' );
		$allowed = MAD4B_SCP_Enrollment_Dispatch::can_execute( $input );
		if ( is_wp_error( $allowed ) || ! $allowed ) return $allowed;
		if ( ! class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) || ! method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'can_execute' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_authority_unavailable', 'Remote Operation Parity authority check is unavailable.' );
		$params = is_array( $input ) && array_key_exists( 'input', $input ) ? $input['input'] : null;
		$parity = MAD4B_SCP_Remote_Operation_Parity::can_execute( $params );
		if ( is_wp_error( $parity ) || ! $parity ) return $parity;
		return true;
	}

	public function enrollment_discover( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_policy_unavailable', 'Bounded Enrollment dispatch policy service is unavailable.' );
		return MAD4B_SCP_Enrollment_Dispatch::discover( is_array( $input ) ? $input : array() );
	}

	public function enrollment_info( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_policy_unavailable', 'Bounded Enrollment dispatch policy service is unavailable.' );
		return MAD4B_SCP_Enrollment_Dispatch::info( is_array( $input ) ? $input : array() );
	}

	public function enrollment_execute( $input ) {
		$request_scope = self::request_scope_admit( 'enrollment_execute' );
		if ( is_wp_error( $request_scope ) ) return $request_scope;
		if ( ! class_exists( 'MAD4B_SCP_Enrollment_Dispatch' ) ) return new WP_Error( 'mad4b_enrollment_dispatch_policy_unavailable', 'Bounded Enrollment dispatch policy service is unavailable.' );
		if ( ! class_exists( 'MAD4B_SCP_Connector_Resilience' ) ) return new WP_Error( 'mad4b_connector_resilience_unavailable', 'Shared connector resilience service is unavailable.' );
		$allowed = $this->can_enrollment_dispatch( $input );
		if ( is_wp_error( $allowed ) || ! $allowed ) return $allowed;
		$operation_id = is_array( $input ) && isset( $input['operation_id'] ) ? (string) $input['operation_id'] : '';
		$execution = MAD4B_SCP_Connector_Resilience::execute_mutation(
			'enrollment',
			$operation_id,
			static function () use ( $input ) {
				return MAD4B_SCP_Enrollment_Dispatch::execute( is_array( $input ) ? $input : array() );
			}
		);
		if ( is_wp_error( $execution ) ) {
			$reconciliation = class_exists( 'MAD4B_SCP_Remote_Operation_Parity' ) && method_exists( 'MAD4B_SCP_Remote_Operation_Parity', 'reconciliation_status' )
				? MAD4B_SCP_Remote_Operation_Parity::reconciliation_status( $operation_id )
				: array();
			if ( is_array( $reconciliation ) && ! empty( $reconciliation['supported'] ) ) {
				$ready = ! empty( $reconciliation['ready'] );
				$reconciled_state = isset( $reconciliation['state'] ) ? sanitize_key( (string) $reconciliation['state'] ) : 'unknown';
				return array(
					'contract' => 'mad4b.chatgpt-enrollment-mutation-reconciliation.v1',
					'operation_id' => $operation_id,
					'state' => $ready ? 'reconciled_completed' : ( 'running' === $reconciled_state ? 'in_progress' : 'reconciliation_required' ),
					'ready' => $ready,
					'dispatch_error_code' => $execution->get_error_code(),
					'reconciliation' => $reconciliation,
					'reconciliation_required' => ! $ready,
					'blind_retry_allowed' => false,
					'automatic_retry_performed' => false,
					'production_mutation' => false,
					'mutation_performed' => null,
				);
			}
			return $execution;
		}
		return array_key_exists( 'result', $execution ) ? $execution['result'] : null;
	}


	public function filesystem_list( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], isset( $input['path'] ) ? $input['path'] : '', true );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_dir( $path ) ) return new WP_Error( 'mad4b_not_directory', 'Requested path is not a directory.' );
		$entries = scandir( $path );
		if ( false === $entries ) return new WP_Error( 'mad4b_list_failed', 'Unable to list directory.' );
		$items = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) continue;
			$full = $path . DIRECTORY_SEPARATOR . $entry;
			if ( MAD4B_SCP_Policy::is_sensitive_path( $full ) ) continue;
			$items[] = array( 'name' => $entry, 'type' => is_dir( $full ) ? 'directory' : ( is_link( $full ) ? 'symlink' : 'file' ), 'size' => is_file( $full ) ? filesize( $full ) : null, 'modified' => file_exists( $full ) ? gmdate( 'c', filemtime( $full ) ) : null );
			if ( count( $items ) >= 500 ) break;
		}
		return array( 'entries' => $items, 'count' => count( $items ) );
	}

	public function filesystem_read( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], true );
		if ( is_wp_error( $path ) ) return $path;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) return new WP_Error( 'mad4b_not_readable_file', 'Requested path is not a readable file.' );
		$max = isset( $input['max_bytes'] ) ? max( 1, min( 1048576, absint( $input['max_bytes'] ) ) ) : 262144;
		$size = filesize( $path );
		if ( false !== $size && $size > $max ) return new WP_Error( 'mad4b_file_too_large', 'File exceeds max_bytes.', array( 'size' => $size ) );
		$content = file_get_contents( $path );
		if ( false === $content ) return new WP_Error( 'mad4b_read_failed', 'Unable to read file.' );
		if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_binary_file', 'Binary files are not returned.' );
		return array( 'content' => $content, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ) );
	}

	public function database_list_tables() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		$items = array();
		foreach ( $tables as $table ) $items[] = array( 'name' => $table, 'structured_read_allowed' => MAD4B_SCP_Policy::can_structured_database_read( $table ), 'structured_write_allowed' => MAD4B_SCP_Policy::can_structured_database_write( $table ) );
		return array( 'tables' => $items, 'count' => count( $items ) );
	}

	public function database_describe_table( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::table_exists( $table ) ) return new WP_Error( 'mad4b_invalid_table', 'Table not visible to WordPress.' );
		$columns = $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A );
		foreach ( $columns as &$column ) if ( isset( $column['Field'] ) ) $column['sensitive'] = MAD4B_SCP_Policy::is_sensitive_database_column( $column['Field'] );
		return array( 'table' => $table, 'structured_read_allowed' => MAD4B_SCP_Policy::can_structured_database_read( $table ), 'columns' => $columns );
	}

	public function database_select( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::can_structured_database_read( $table ) ) return new WP_Error( 'mad4b_sensitive_table_denied', 'Structured reads are denied for this sensitive database table. Use explicitly enabled Breakglass when genuinely required.' );

		$available = array();
		foreach ( $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A ) as $definition ) if ( isset( $definition['Field'] ) ) $available[] = $definition['Field'];
		$requested = ! empty( $input['columns'] ) ? $input['columns'] : $available;
		$list = array();
		foreach ( $requested as $column ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) || ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid or unknown column identifier.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_column_denied', 'Structured reads of secret/authentication columns are denied.' );
			$list[] = '`' . $column . '`';
		}
		if ( empty( $list ) ) return new WP_Error( 'mad4b_no_safe_columns', 'No non-sensitive columns are available for structured reading.' );
		foreach ( array_keys( isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : array() ) as $column ) {
			if ( ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Unknown WHERE column.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_where_denied', 'Sensitive columns cannot be used in structured WHERE clauses.' );
		}

		$where = $this->where_sql( isset( $input['where'] ) && is_array( $input['where'] ) ? $input['where'] : array() );
		if ( is_wp_error( $where ) ) return $where;
		$order = '';
		if ( ! empty( $input['order_by'] ) ) {
			if ( ! preg_match( '/^([A-Za-z0-9_$]+)(?:\s+(ASC|DESC))?$/i', trim( $input['order_by'] ), $m ) ) return new WP_Error( 'mad4b_invalid_order', 'Invalid order_by.' );
			if ( ! in_array( $m[1], $available, true ) || MAD4B_SCP_Policy::is_sensitive_database_column( $m[1] ) ) return new WP_Error( 'mad4b_sensitive_order_denied', 'Invalid or sensitive order_by column.' );
			$order = ' ORDER BY `' . $m[1] . '` ' . ( isset( $m[2] ) ? strtoupper( $m[2] ) : 'ASC' );
		}
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$sql = 'SELECT ' . implode( ', ', $list ) . ' FROM `' . $table . '`' . $where . $order . ' LIMIT ' . $limit;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array( 'table' => $table, 'rows' => $rows, 'count' => count( $rows ) );
	}

	private function where_sql( array $where ) {
		global $wpdb; if ( ! $where ) return '';
		$parts = array();
		foreach ( $where as $column => $value ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid WHERE column.' );
			if ( null === $value ) $parts[] = '`' . $column . '` IS NULL';
			elseif ( is_scalar( $value ) ) $parts[] = $wpdb->prepare( '`' . $column . '` = %s', (string) $value );
			else return new WP_Error( 'mad4b_invalid_where', 'WHERE values must be scalar or null.' );
		}
		return ' WHERE ' . implode( ' AND ', $parts );
	}

	public function runtime_authority_status() { return MAD4B_SCP_Authorization::authority_status(); }

	public function schema_status() {
		$status = MAD4B_SCP_Schema::status( true );
		$physical = isset( $status['physical_integrity'] ) && is_array( $status['physical_integrity'] ) ? $status['physical_integrity'] : array();
		$migration = isset( $status['migration'] ) && is_array( $status['migration'] ) ? $status['migration'] : array();
		$preflight = isset( $migration['preflight'] ) && is_array( $migration['preflight'] ) ? $migration['preflight'] : array();
		$receipt = isset( $migration['receipt'] ) && is_array( $migration['receipt'] ) ? $migration['receipt'] : array();
		$missing_tables = isset( $physical['missing_tables'] ) && is_array( $physical['missing_tables'] ) ? array_values( $physical['missing_tables'] ) : array();
		$durable = array(
			'content_jobs'       => ! in_array( 'content_jobs', $missing_tables, true ),
			'content_job_events' => ! in_array( 'content_job_events', $missing_tables, true ),
			'work_leases'        => ! in_array( 'work_leases', $missing_tables, true ),
			'idempotency'        => ! in_array( 'idempotency', $missing_tables, true ),
			'execution_outbox'   => ! in_array( 'outbox', $missing_tables, true ),
			'execution_inbox'    => ! in_array( 'inbox', $missing_tables, true ),
		);
		return array(
			'contract' => 'mad4b.schema-status.v1',
			'read_only' => true,
			'mutation_performed' => false,
			'expected_version' => isset( $status['expected_version'] ) ? (int) $status['expected_version'] : MAD4B_SCP_Schema::VERSION,
			'installed_version' => isset( $status['installed_version'] ) ? (int) $status['installed_version'] : 0,
			'integrity_token_valid' => ! empty( $status['integrity_token_valid'] ),
			'migration' => array(
				'contract' => isset( $migration['contract'] ) && is_array( $migration['contract'] ) ? $migration['contract'] : array(),
				'contract_sha256' => isset( $migration['contract_sha256'] ) ? (string) $migration['contract_sha256'] : '',
				'preflight' => array(
					'contract' => isset( $preflight['contract'] ) ? (string) $preflight['contract'] : '',
					'migration_id' => isset( $preflight['migration_id'] ) ? (string) $preflight['migration_id'] : '',
					'installed_version' => isset( $preflight['installed_version'] ) ? (int) $preflight['installed_version'] : 0,
					'target_version' => isset( $preflight['target_version'] ) ? (int) $preflight['target_version'] : 0,
					'fresh_install' => ! empty( $preflight['fresh_install'] ),
					'repair_run' => ! empty( $preflight['repair_run'] ),
					'contract_sha256' => isset( $preflight['contract_sha256'] ) ? (string) $preflight['contract_sha256'] : '',
					'blockers' => isset( $preflight['blockers'] ) && is_array( $preflight['blockers'] ) ? array_values( $preflight['blockers'] ) : array(),
					'ready' => ! empty( $preflight['ready'] ),
					'read_only' => ! empty( $preflight['read_only'] ),
					'mutation_performed' => ! empty( $preflight['mutation_performed'] ),
				),
				'receipt' => array(
					'contract' => isset( $receipt['contract'] ) ? (string) $receipt['contract'] : '',
					'migration_id' => isset( $receipt['migration_id'] ) ? (string) $receipt['migration_id'] : '',
					'from_version' => isset( $receipt['from_version'] ) ? (int) $receipt['from_version'] : 0,
					'to_version' => isset( $receipt['to_version'] ) ? (int) $receipt['to_version'] : 0,
					'run_type' => isset( $receipt['run_type'] ) ? (string) $receipt['run_type'] : '',
					'contract_sha256' => isset( $receipt['contract_sha256'] ) ? (string) $receipt['contract_sha256'] : '',
					'target_integrity_token' => isset( $receipt['target_integrity_token'] ) ? (string) $receipt['target_integrity_token'] : '',
					'physical_integrity_sha256' => isset( $receipt['physical_integrity_sha256'] ) ? (string) $receipt['physical_integrity_sha256'] : '',
					'physical_verified' => ! empty( $receipt['physical_verified'] ),
					'readiness_finalized' => ! empty( $receipt['readiness_finalized'] ),
					'destructive' => ! empty( $receipt['destructive'] ),
					'authority_widened' => ! empty( $receipt['authority_widened'] ),
					'completed_at' => isset( $receipt['completed_at'] ) ? (string) $receipt['completed_at'] : '',
				),
				'receipt_valid' => ! empty( $migration['receipt_valid'] ),
			),
			'durable_tables' => $durable,
			'physical_integrity' => array(
				'contract' => isset( $physical['contract'] ) ? (string) $physical['contract'] : '',
				'ready' => ! empty( $physical['ready'] ),
				'missing_tables' => $missing_tables,
				'missing_approval_columns' => isset( $physical['missing_approval_columns'] ) && is_array( $physical['missing_approval_columns'] ) ? array_values( $physical['missing_approval_columns'] ) : array(),
				'missing_durable_columns' => isset( $physical['missing_durable_columns'] ) && is_array( $physical['missing_durable_columns'] ) ? array_values( $physical['missing_durable_columns'] ) : array(),
				'missing_durable_indexes' => isset( $physical['missing_durable_indexes'] ) && is_array( $physical['missing_durable_indexes'] ) ? array_values( $physical['missing_durable_indexes'] ) : array(),
			),
			'ready' => ! empty( $status['ready'] ) && ! empty( $physical['ready'] ),
		);
	}

	public function diagnostics_health() {
		global $wpdb; $uploads = wp_upload_dir( null, false );
		$backup_root = MAD4B_SCP_Policy::prepare_backup_root();
		return array( 'status' => 'ok', 'checks' => array(
			'database' => '1' === (string) $wpdb->get_var( 'SELECT 1' ),
			'abilities_api' => function_exists( 'wp_register_ability' ),
			'mcp_adapter' => class_exists( 'WP\\MCP\\Core\\McpAdapter' ),
			'wp_content_write' => is_writable( WP_CONTENT_DIR ),
			'plugins_write' => is_writable( WP_PLUGIN_DIR ),
			'uploads_write' => isset( $uploads['basedir'] ) ? is_writable( $uploads['basedir'] ) : false,
			'protected_backup_root' => ! is_wp_error( $backup_root ),
			'breakglass_enabled' => MAD4B_SCP_Policy::can_breakglass(),
		) );
	}

	public function can_read_post( $input ) { $id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'read_post', $id ); }
	public function can_edit_post( $input ) { $id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0; return $id > 0 && current_user_can( 'edit_post', $id ); }

	public function content_get_post( $input ) {
		$post = get_post( absint( $input['post_id'] ) ); if ( ! $post ) return new WP_Error( 'mad4b_post_missing', 'Post not found.' );
		return array( 'post' => array( 'ID' => $post->ID, 'post_type' => $post->post_type, 'post_status' => $post->post_status, 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt, 'post_parent' => $post->post_parent, 'modified_gmt' => $post->post_modified_gmt, 'permalink' => get_permalink( $post ) ) );
	}

	public function content_update_post( $input ) {
		if ( ! class_exists( 'MAD4B_SCP_Mutation_Manager' ) ) return new WP_Error(
			'mad4b_mutation_manager_unavailable',
			'Governed content mutation manager is unavailable.'
		);
		return MAD4B_SCP_Mutation_Manager::execute_post_update( is_array( $input ) ? $input : array() );
	}


	private function plugin_site_active( $plugin ) {
		$active_plugins = get_option( 'active_plugins', array() );
		return is_array( $active_plugins ) && in_array( (string) $plugin, $active_plugins, true );
	}

	public function plugin_activate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_capability_denied', 'Current user cannot activate plugins.' );
		$scope = isset( $input['activation_scope'] ) ? sanitize_key( (string) $input['activation_scope'] ) : 'site';
		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) return new WP_Error( 'mad4b_plugin_activation_scope_invalid', 'activation_scope must be site or network.' );
		if ( 'network' === $scope && ! is_multisite() ) return new WP_Error( 'mad4b_plugin_network_scope_unavailable', 'Network activation requires WordPress multisite.' );
		$network_active = is_multisite() ? is_plugin_active_for_network( $plugin ) : false;
		if ( 'site' === $scope && $network_active ) return new WP_Error( 'mad4b_plugin_network_activation_controls_site', 'Site-scoped lifecycle changes are not meaningful while the plugin is network-active; change network scope first.' );
		$current = 'network' === $scope ? $network_active : $this->plugin_site_active( $plugin );
		if ( $current !== (bool) $input['expected_active'] ) return new WP_Error( 'mad4b_stale_plugin_state', 'Plugin active state changed in the requested activation scope since it was reviewed.', array( 'activation_scope' => $scope, 'current_active' => $current ) );
		if ( $current ) return new WP_Error( 'mad4b_plugin_already_active', 'Plugin is already active in the requested activation scope.' );
		if ( 'network' === $scope && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_network_plugin_capability_denied', 'Network-wide plugin activation requires manage_network_plugins.' );
		if ( ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, 'activate' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_policy_denied', 'Plugin lifecycle mutation is disabled or the plugin is not explicitly allowlisted.' );
		$preflight = class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) ? MAD4B_SCP_Plugin_Lifecycle::mutation_preflight( $plugin, true, $input ) : new WP_Error( 'mad4b_plugin_lifecycle_preflight_unavailable', 'Plugin lifecycle preflight is unavailable.' );
		if ( is_wp_error( $preflight ) ) return $preflight;
		$result = activate_plugin( $plugin, '', 'network' === $scope ); if ( is_wp_error( $result ) ) return $result;
		$readback = MAD4B_SCP_Plugin_Lifecycle::verify_state( $plugin, true, $scope );
		if ( is_wp_error( $readback ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/plugin-activate', array( 'plugin' => $plugin, 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'readback_verified' => false ), 'failure' );
			return $readback;
		}
		MAD4B_SCP_Audit::record( 'mad4b/plugin-activate', array( 'plugin' => $plugin, 'activation_scope' => $scope, 'reason' => sanitize_text_field( $input['reason'] ), 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true ) );
		return array( 'plugin' => $plugin, 'active' => true, 'activation_scope' => $scope, 'network_active' => (bool) $readback['network_active'], 'site_active' => (bool) $readback['site_active'], 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true );
	}

	public function plugin_deactivate( $input ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = sanitize_text_field( $input['plugin'] ); $plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) return new WP_Error( 'mad4b_plugin_missing', 'Plugin is not installed.' );
		if ( ! current_user_can( 'activate_plugins' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_capability_denied', 'Current user cannot deactivate plugins.' );
		$scope = isset( $input['activation_scope'] ) ? sanitize_key( (string) $input['activation_scope'] ) : 'site';
		if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) return new WP_Error( 'mad4b_plugin_activation_scope_invalid', 'activation_scope must be site or network.' );
		if ( 'network' === $scope && ! is_multisite() ) return new WP_Error( 'mad4b_plugin_network_scope_unavailable', 'Network deactivation requires WordPress multisite.' );
		$network_active = is_multisite() ? is_plugin_active_for_network( $plugin ) : false;
		if ( 'site' === $scope && $network_active ) return new WP_Error( 'mad4b_plugin_network_activation_controls_site', 'Site-scoped lifecycle changes are not meaningful while the plugin is network-active; change network scope first.' );
		$current = 'network' === $scope ? $network_active : $this->plugin_site_active( $plugin );
		if ( $current !== (bool) $input['expected_active'] ) return new WP_Error( 'mad4b_stale_plugin_state', 'Plugin active state changed in the requested activation scope since it was reviewed.', array( 'activation_scope' => $scope, 'current_active' => $current ) );
		if ( ! $current ) return new WP_Error( 'mad4b_plugin_already_inactive', 'Plugin is already inactive in the requested activation scope.' );
		$self = plugin_basename( MAD4B_SCP_FILE );
		$name = isset( $plugins[ $plugin ]['Name'] ) ? strtolower( $plugins[ $plugin ]['Name'] ) : '';
		$text_domain = isset( $plugins[ $plugin ]['TextDomain'] ) ? strtolower( $plugins[ $plugin ]['TextDomain'] ) : '';
		$is_mcp_adapter = 0 === strpos( strtolower( $plugin ), 'mcp-adapter/' ) || 'mcp-adapter' === $text_domain || false !== strpos( $name, 'mcp adapter' );
		if ( $plugin === $self || $is_mcp_adapter || MAD4B_SCP_Policy::plugin_lifecycle_protected( $plugin ) ) return new WP_Error( 'mad4b_control_plane_dependency_protected', 'The control plane, its MCP Adapter dependency, and protected plugins cannot be deactivated through the normal admin surface.' );
		if ( ! MAD4B_SCP_Policy::plugin_lifecycle_allowed( $plugin, 'deactivate' ) ) return new WP_Error( 'mad4b_plugin_lifecycle_policy_denied', 'Plugin lifecycle mutation is disabled or the plugin is not explicitly allowlisted.' );
		$preflight = class_exists( 'MAD4B_SCP_Plugin_Lifecycle' ) ? MAD4B_SCP_Plugin_Lifecycle::mutation_preflight( $plugin, false, $input ) : new WP_Error( 'mad4b_plugin_lifecycle_preflight_unavailable', 'Plugin lifecycle preflight is unavailable.' );
		if ( is_wp_error( $preflight ) ) return $preflight;
		$network = 'network' === $scope;
		if ( $network && ! current_user_can( 'manage_network_plugins' ) ) return new WP_Error( 'mad4b_network_plugin_capability_denied', 'Network-wide plugin deactivation requires manage_network_plugins.' );
		deactivate_plugins( $plugin, false, $network );
		$readback = MAD4B_SCP_Plugin_Lifecycle::verify_state( $plugin, false, $scope );
		if ( is_wp_error( $readback ) ) {
			MAD4B_SCP_Audit::record( 'mad4b/plugin-deactivate', array( 'plugin' => $plugin, 'network_wide' => $network, 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'readback_verified' => false ), 'failure' );
			return $readback;
		}
		MAD4B_SCP_Audit::record( 'mad4b/plugin-deactivate', array( 'plugin' => $plugin, 'activation_scope' => $scope, 'network_wide' => $network, 'reason' => sanitize_text_field( $input['reason'] ), 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true ) );
		return array( 'plugin' => $plugin, 'active' => false, 'activation_scope' => $scope, 'site_active' => (bool) $readback['site_active'], 'network_active' => (bool) $readback['network_active'], 'plan_sha256' => $preflight['plan_sha256'], 'before_state_sha256' => $preflight['state_sha256'], 'after_state_sha256' => $readback['state_sha256'], 'readback_verified' => true );
	}

	public function filesystem_write( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], false ); if ( is_wp_error( $path ) ) return $path;
		$mutation_allowed = MAD4B_SCP_Policy::can_mutate_file( $input['root'], $path ); if ( is_wp_error( $mutation_allowed ) ) return $mutation_allowed;
		$content = (string) $input['content']; if ( false !== strpos( $content, "\0" ) ) return new WP_Error( 'mad4b_binary_write_denied', 'NUL bytes are not allowed.' );
		$exists = file_exists( $path ); $expected = isset( $input['expected_sha256'] ) ? strtolower( trim( $input['expected_sha256'] ) ) : '';
		if ( $exists ) { if ( ! is_file( $path ) ) return new WP_Error( 'mad4b_not_file', 'Target is not a regular file.' ); $current = hash_file( 'sha256', $path ); if ( ! $expected || ! hash_equals( $current, $expected ) ) return new WP_Error( 'mad4b_stale_file', 'Current SHA-256 is required.', array( 'current_sha256' => $current ) ); }
		elseif ( empty( $input['allow_create'] ) ) return new WP_Error( 'mad4b_create_not_allowed', 'Set allow_create=true to create a new file.' );
		$result = $this->atomic_write( $path, $content, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		MAD4B_SCP_Audit::record( 'mad4b/filesystem-write', array( 'root' => $input['root'], 'path' => $input['path'], 'sha256' => $result['sha256'], 'create' => ! $exists, 'backup_id' => $result['backup_id'] ) ); return $result;
	}

	public function filesystem_patch( $input ) {
		$path = MAD4B_SCP_Policy::resolve_path( $input['root'], $input['path'], true ); if ( is_wp_error( $path ) ) return $path;
		$mutation_allowed = MAD4B_SCP_Policy::can_mutate_file( $input['root'], $path ); if ( is_wp_error( $mutation_allowed ) ) return $mutation_allowed;
		$content = is_file( $path ) ? file_get_contents( $path ) : false; if ( false === $content ) return new WP_Error( 'mad4b_read_failed', 'Unable to read target.' );
		$current = hash( 'sha256', $content ); if ( ! hash_equals( $current, strtolower( trim( $input['expected_sha256'] ) ) ) ) return new WP_Error( 'mad4b_stale_file', 'Target SHA-256 no longer matches.', array( 'current_sha256' => $current ) );
		$count = substr_count( $content, (string) $input['search'] ); $max = isset( $input['max_replacements'] ) ? max( 1, min( 100, absint( $input['max_replacements'] ) ) ) : 1;
		if ( 0 === $count ) return new WP_Error( 'mad4b_patch_not_found', 'Search text not found.' ); if ( $count > $max ) return new WP_Error( 'mad4b_patch_too_broad', 'Search matches exceed max_replacements.', array( 'matches' => $count ) );
		$patched = str_replace( (string) $input['search'], (string) $input['replace'], $content, $replacements ); $result = $this->atomic_write( $path, $patched, ! isset( $input['create_backup'] ) || (bool) $input['create_backup'] ); if ( is_wp_error( $result ) ) return $result;
		$result['replacements'] = $replacements; MAD4B_SCP_Audit::record( 'mad4b/filesystem-patch', array( 'root' => $input['root'], 'path' => $input['path'], 'replacements' => $replacements, 'sha256' => $result['sha256'], 'backup_id' => $result['backup_id'] ) ); return $result;
	}

	private function atomic_write( $path, $content, $backup ) {
		$dir = dirname( $path ); if ( ! is_writable( $dir ) ) return new WP_Error( 'mad4b_directory_not_writable', 'Target directory is not writable.' );
		$exists = file_exists( $path );
		$mode = $exists ? ( fileperms( $path ) & 0777 ) : 0644;
		$backup_id = '';
		if ( $backup && $exists ) {
			$backup_root = MAD4B_SCP_Policy::prepare_backup_root();
			if ( is_wp_error( $backup_root ) ) return $backup_root;
			$name = sanitize_file_name( basename( $path ) ) . '-' . substr( hash( 'sha256', $path ), 0, 12 ) . '-' . gmdate( 'YmdHis' ) . '.bak';
			$name = wp_unique_filename( $backup_root, $name );
			$backup_path = trailingslashit( $backup_root ) . $name;
			if ( ! copy( $path, $backup_path ) ) return new WP_Error( 'mad4b_backup_failed', 'Unable to create protected backup.' );
			@chmod( $backup_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$backup_id = $name;
		}

		$temp = tempnam( $dir, '.mad4b-' ); if ( false === $temp ) return new WP_Error( 'mad4b_temp_failed', 'Unable to create temporary file.' );
		$bytes = file_put_contents( $temp, $content, LOCK_EX );
		if ( false === $bytes ) { @unlink( $temp ); return new WP_Error( 'mad4b_write_failed', 'Unable to write temporary file.' ); }
		@chmod( $temp, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! rename( $temp, $path ) ) { @unlink( $temp ); return new WP_Error( 'mad4b_replace_failed', 'Unable to atomically replace target.' ); }
		clearstatcache( true, $path );
		return array( 'written' => true, 'bytes' => $bytes, 'sha256' => hash( 'sha256', $content ), 'backup_id' => $backup_id, 'mode' => sprintf( '%04o', $mode ) );
	}

	public function database_update( $input ) {
		global $wpdb; $table = $input['table'];
		if ( ! MAD4B_SCP_Policy::can_structured_database_write( $table ) ) return new WP_Error( 'mad4b_sensitive_table_write_denied', 'Structured writes are denied for this sensitive database table. Use explicitly enabled Breakglass when genuinely required.' );
		if ( empty( $input['data'] ) || ! is_array( $input['data'] ) ) return new WP_Error( 'mad4b_empty_data', 'data must be non-empty.' );
		if ( empty( $input['where'] ) || ! is_array( $input['where'] ) ) return new WP_Error( 'mad4b_where_required', 'A non-empty where object is required.' );

		$available = array();
		foreach ( $wpdb->get_results( 'DESCRIBE `' . $table . '`', ARRAY_A ) as $definition ) if ( isset( $definition['Field'] ) ) $available[] = $definition['Field'];
		if ( empty( $available ) ) return new WP_Error( 'mad4b_table_definition_unavailable', 'Unable to read the target table definition.' );
		foreach ( array_keys( $input['data'] ) as $column ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) || ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid or unknown data column.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_column_write_denied', 'Structured writes to secret/authentication columns are denied.' );
		}
		foreach ( array_keys( $input['where'] ) as $column ) {
			if ( ! MAD4B_SCP_Policy::validate_identifier( $column ) || ! in_array( $column, $available, true ) ) return new WP_Error( 'mad4b_invalid_column', 'Invalid or unknown WHERE column.' );
			if ( MAD4B_SCP_Policy::is_sensitive_database_column( $column ) ) return new WP_Error( 'mad4b_sensitive_where_denied', 'Sensitive columns cannot be used in structured WHERE clauses.' );
		}

		$table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		$engine = is_array( $table_status ) && isset( $table_status['Engine'] ) ? strtolower( (string) $table_status['Engine'] ) : '';
		$transactional_engines = apply_filters( 'mad4b_scp_transactional_database_engines', array( 'innodb', 'xtradb' ), $table );
		$transactional_engines = is_array( $transactional_engines ) ? array_map( 'strtolower', $transactional_engines ) : array();
		if ( '' === $engine || ! in_array( $engine, $transactional_engines, true ) ) return new WP_Error( 'mad4b_non_transactional_table_denied', 'Structured mutation requires a certified transactional table engine.', array( 'engine' => $engine ) );

		$where = $this->where_sql( $input['where'] ); if ( is_wp_error( $where ) ) return $where;
		$max = isset( $input['max_affected'] ) ? max( 1, min( 100, absint( $input['max_affected'] ) ) ) : 1;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) return new WP_Error( 'mad4b_transaction_required', 'Unable to start the required database transaction.' );
		$matches = $wpdb->get_col( 'SELECT 1 FROM `' . $table . '`' . $where . ' LIMIT ' . ( $max + 1 ) . ' FOR UPDATE' );
		if ( null === $matches && $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'mad4b_update_preflight_failed', $wpdb->last_error ); }
		$count = count( (array) $matches );
		if ( $count > $max ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'mad4b_update_too_broad', 'Locked preflight exceeds max_affected.', array( 'matched_rows' => $count ) ); }
		$result = $wpdb->update( $table, $input['data'], $input['where'] );
		if ( false === $result ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'mad4b_update_failed', $wpdb->last_error ? $wpdb->last_error : 'Database update failed.' ); }
		if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'mad4b_commit_failed', 'Database commit failed; mutation cannot be certified.' ); }
		MAD4B_SCP_Audit::record( 'mad4b/database-update', array( 'table' => $table, 'engine' => $engine, 'affected' => $result, 'matched' => $count, 'locked_preflight' => true ) );
		return array( 'table' => $table, 'engine' => $engine, 'matched_rows' => $count, 'affected_rows' => (int) $result, 'locked_preflight' => true );
	}

	public function audit_tail( $input ) { $items = MAD4B_SCP_Audit::tail( isset( $input['limit'] ) ? absint( $input['limit'] ) : 50 ); return array( 'entries' => $items, 'count' => count( $items ) ); }

	public function database_raw_query( $input ) {
		global $wpdb;
		$sql = trim( (string) $input['sql'] );
		$reason = sanitize_text_field( (string) $input['reason'] );
		$normalized = preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$normalized = preg_replace( '/(?:--|#)[^\r\n]*/', ' ', $normalized );
		$normalized = trim( preg_replace( '/\s+/', ' ', $normalized ) );
		$normalized = preg_replace( '/;\s*$/', '', $normalized );
		if ( false !== strpos( $normalized, ';' ) ) return new WP_Error( 'mad4b_multi_statement_denied', 'Only one SQL statement is allowed.' );
		if ( preg_match( '/\b(?:GRANT|REVOKE|CREATE\s+(?:USER|ROLE)|ALTER\s+USER|DROP\s+(?:USER|ROLE)|RENAME\s+USER|SET\s+PASSWORD|LOAD\s+DATA|INTO\s+OUTFILE|INTO\s+DUMPFILE)\b|\bLOAD_FILE\s*\(/i', $normalized ) ) return new WP_Error( 'mad4b_sql_hard_denied', 'SQL operation is hard-denied.' );
		if ( ! preg_match( '/^\s*([A-Za-z]+)/', $normalized, $m ) ) return new WP_Error( 'mad4b_sql_unclassified', 'Unable to classify SQL.' );
		$verb = strtoupper( $m[1] );
		$read = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );
		$write = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );
		$ddl = array( 'ALTER', 'CREATE', 'DROP', 'TRUNCATE', 'RENAME' );
		if ( in_array( $verb, $write, true ) && ( ! class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) || ! MAD4B_SCP_Governed_Runtime_Gates::raw_sql_write_enabled() ) ) return new WP_Error( 'mad4b_sql_write_disabled', 'Raw SQL writes are disabled by the governed database policy.' );
		if ( in_array( $verb, $ddl, true ) && ( ! class_exists( 'MAD4B_SCP_Governed_Runtime_Gates' ) || ! MAD4B_SCP_Governed_Runtime_Gates::raw_sql_ddl_enabled() ) ) return new WP_Error( 'mad4b_sql_ddl_disabled', 'DDL is disabled by the governed database policy.' );
		if ( ! in_array( $verb, array_merge( $read, $write, $ddl ), true ) ) return new WP_Error( 'mad4b_sql_verb_denied', 'SQL verb is not allowed.' );

		$max = isset( $input['max_rows'] ) ? max( 1, min( 500, absint( $input['max_rows'] ) ) ) : 100;
		if ( 'SELECT' === $verb ) {
			if ( ! preg_match( '/\bLIMIT\s+(?:(\d+)\s*,\s*)?(\d+)\s*$/i', $normalized, $limit_match ) ) return new WP_Error( 'mad4b_select_limit_required', 'Breakglass SELECT must include an explicit trailing numeric LIMIT no greater than max_rows.' );
			$requested_limit = (int) $limit_match[2];
			if ( $requested_limit < 1 || $requested_limit > $max ) return new WP_Error( 'mad4b_select_limit_too_large', 'Breakglass SELECT LIMIT exceeds max_rows.', array( 'max_rows' => $max, 'requested_limit' => $requested_limit ) );
		}

		$hash = hash( 'sha256', $normalized );
		MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'reason' => $reason ), 'attempt' );
		if ( in_array( $verb, $read, true ) ) {
			$rows = $wpdb->get_results( $normalized, ARRAY_A );
			if ( null === $rows && $wpdb->last_error ) { MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash ), 'failure' ); return new WP_Error( 'mad4b_raw_query_failed', $wpdb->last_error ); }
			$rows = array_slice( (array) $rows, 0, $max );
			MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'rows' => count( $rows ) ) );
			return array( 'verb' => $verb, 'query_hash' => $hash, 'rows' => $rows, 'count' => count( $rows ) );
		}
		$result = $wpdb->query( $normalized );
		if ( false === $result ) { MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash ), 'failure' ); return new WP_Error( 'mad4b_raw_query_failed', $wpdb->last_error ? $wpdb->last_error : 'Raw query failed.' ); }
		MAD4B_SCP_Audit::record( 'mad4b/database-raw-query', array( 'verb' => $verb, 'query_hash' => $hash, 'affected' => $result ) );
		return array( 'verb' => $verb, 'query_hash' => $hash, 'affected_rows' => (int) $result );
	}
}
