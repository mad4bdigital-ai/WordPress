<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Registration schemas only; execution and permissions remain on MAD4B_SCP_Abilities. */
trait MAD4B_SCP_Abilities_Registration_Trait {
	public function register_abilities() {
		$this->add( 'mad4b/site-info', 'Get Site Info', 'mad4b-read', 'site_info', 'read', null, false, true, false, true );
		$this->add( 'mad4b/environment-journey', 'Plan Safe Environment Operation', 'mad4b-read', 'environment_journey', 'read', $this->schema(
			array( 'operation_kind' => array( 'type' => 'string', 'enum' => array(
				'diagnostics', 'derived_state', 'operational_repair', 'write_authority',
				'content_publish', 'plugin_update', 'deployment'
			), 'default' => 'diagnostics' ) ), array()
		), false, true, false, true );
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
			array(
				'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
				'prepare_execution' => array( 'type' => 'boolean', 'default' => true ),
			), array( 'ability_name' )
		), false, true, false, true );
		$this->add( 'mad4b/read-execute', 'Execute Governed Read Ability', 'mad4b-read', 'read_execute', 'read', $this->schema(
			array(
				'ability_name' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 180 ),
				'expected_input_schema_sha256' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '[A-Fa-f0-9]{64}' ),
				'expected_execution_lane' => array( 'type' => 'string', 'enum' => array( 'read', 'write', 'content', 'admin', 'developer' ) ),
				'expected_classification_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'expected_authority_scope_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'preparation_receipt' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 4096 ),
				'input' => array( 'type' => 'object' ),
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
				'idempotency_key' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ),
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
}
