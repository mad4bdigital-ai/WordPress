<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Administrative governance visibility and planning abilities.
 *
 * These abilities never create NHI authority. Agent/grant mutation remains an explicit
 * human-admin configuration concern. approval-plan may create only a pending local ticket
 * and can never approve or execute the target operation.
 */
final class MAD4B_SCP_Governance_Abilities {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 30 );
	}

	public static function register_abilities() {
		self::register(
			'mad4b/agent-list',
			'List MAD4B Agents',
			'List bounded non-secret NHI governance summaries.',
			'agent_list',
			self::schema(
				array(
					'status' => array( 'type' => 'string', 'enum' => array( 'all', 'enabled', 'disabled' ), 'default' => 'all' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				)
			),
			true,
			false,
			true
		);

		self::register(
			'mad4b/agent-effective-access',
			'Preview Agent Effective Access',
			'Simulate exact NHI grants, optional token scopes, provider runtime state, impact and approval requirements without executing or consuming authority.',
			'agent_effective_access',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'token_scopes' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ), 'maxItems' => MAD4B_SCP_Identity_Context::MAX_SCOPES, 'default' => array() ),
					'server_id' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), MAD4B_SCP_Servers::expected_server_ids() ), 'default' => '' ),
				),
				array( 'agent_public_id' )
			),
			true,
			false,
			true
		);

		self::register(
			'mad4b/approval-plan',
			'Plan Exact Mutation Approval',
			'Create one pending, exact-operation approval ticket after validating agent grant, server membership and provider runtime. This never approves or executes the operation.',
			'approval_plan',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'server_id' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Servers::expected_server_ids() ),
					'ability' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
					'provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'default' => 'core' ),
					'target_fingerprint' => array( 'type' => 'string', 'maxLength' => 191, 'default' => '' ),
					'input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
					'ticket_class' => array( 'type' => 'string', 'enum' => array( 'mutation', 'breakglass', 'recovery' ), 'default' => 'mutation' ),
					'reason' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 500 ),
					'ttl' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => MAD4B_SCP_Approval_Tickets::MAX_TTL, 'default' => MAD4B_SCP_Approval_Tickets::DEFAULT_TTL ),
				),
				array( 'agent_public_id', 'server_id', 'ability', 'reason' )
			),
			false,
			false,
			false
		);
	}

	private static function register( $name, $label, $description, $method, array $input_schema, $readonly, $destructive, $idempotent ) {
		if ( wp_has_ability( $name ) ) return;
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $description,
				'category' => 'mad4b-admin',
				'execute_callback' => array( __CLASS__, $method ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'input_schema' => $input_schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'admin' ),
					'annotations' => array(
						'readonly' => (bool) $readonly,
						'destructive' => (bool) $destructive,
						'idempotent' => (bool) $idempotent,
					),
				),
			)
		);
	}

	public static function can_manage( $input = null ) { return current_user_can( 'manage_options' ); }

	public static function agent_list( $input ) {
		global $wpdb;
		$t = MAD4B_SCP_Schema::tables();
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'all';
		$limit = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$where = '';
		$args = array();
		if ( in_array( $status, array( 'enabled', 'disabled' ), true ) ) {
			$where = ' WHERE status = %s';
			$args[] = $status;
		}
		$sql = "SELECT id, public_id, slug, label, status, wp_user_id, environment, revision, created_at, updated_at FROM {$t['agents']}{$where} ORDER BY id ASC LIMIT %d";
		$args[] = $limit;
		$prepared = $wpdb->prepare( $sql, $args );
		$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$items = array();
		foreach ( $rows as $row ) {
			$agent_id = (int) $row['id'];
			unset( $row['id'] );
			$row['subjects'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['subjects']} WHERE agent_id = %d AND status = 'enabled'", $agent_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$row['grants'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['grants']} WHERE agent_id = %d", $agent_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$row['budgets'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['budgets']} WHERE agent_id = %d", $agent_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$items[] = $row;
		}
		return array( 'agents' => $items, 'count' => count( $items ), 'limit' => $limit );
	}

	public static function agent_effective_access( $input ) {
		global $wpdb;
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $input['agent_public_id'] );
		if ( ! $agent ) return new WP_Error( 'mad4b_agent_missing', 'Agent not found.' );
		$scopes = isset( $input['token_scopes'] ) && is_array( $input['token_scopes'] ) ? array_values( array_unique( $input['token_scopes'] ) ) : array();
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || '' === trim( $scope ) || false !== strpos( $scope, '*' ) ) return new WP_Error( 'mad4b_effective_access_scope_invalid', 'Simulation accepts exact non-wildcard token scopes only.' );
		}
		$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
		$t = MAD4B_SCP_Schema::tables();
		$current_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$sql = "SELECT id, effect, server_id, ability_name, provider, resource_schema_version, resource_constraints, environment FROM {$t['grants']} WHERE agent_id = %d AND environment IN ('all', %s)";
		$args = array( (int) $agent['id'], $current_environment );
		if ( '' !== $server_id ) { $sql .= ' AND server_id = %s'; $args[] = $server_id; }
		$sql .= ' ORDER BY server_id ASC, ability_name ASC, CASE effect WHEN \'deny\' THEN 0 ELSE 1 END, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$items = array();
		$allowed = 0;
		$denied = 0;
		foreach ( $rows as $row ) {
			$scope_state = empty( $scopes ) ? 'not_simulated' : ( in_array( 'ability:' . $row['ability_name'], $scopes, true ) ? 'allowed' : 'denied' );
			$provider_state = self::provider_runtime_state( $row['provider'] );
			$constraints = json_decode( (string) $row['resource_constraints'], true );
			if ( ! is_array( $constraints ) ) $constraints = array( '_invalid' => true );
			$mounted = MAD4B_SCP_Servers::ability_is_mounted( $row['server_id'], $row['ability_name'] );
			$effective = 'enabled' === $agent['status'] && 'allow' === $row['effect'] && 'denied' !== $scope_state && $mounted && 'blocked' !== $provider_state['state'];
			if ( $effective ) ++$allowed; else ++$denied;
			$items[] = array(
				'grant_id' => (int) $row['id'],
				'server_id' => $row['server_id'],
				'ability' => $row['ability_name'],
				'provider' => $row['provider'],
				'grant' => $row['effect'],
				'scope' => $scope_state,
				'mounted' => $mounted,
				'provider_runtime' => $provider_state,
				'impact' => MAD4B_SCP_Impact_Policy::impact_for( $row['ability_name'], $row['provider'], null ),
				'approval_required' => MAD4B_SCP_Impact_Policy::requires_approval( $row['ability_name'], $row['provider'], null ),
				'resource_schema_version' => $row['resource_schema_version'],
				'resource_constraints' => $constraints,
				'effective' => $effective,
			);
		}
		return array(
			'agent' => array( 'public_id' => $agent['public_id'], 'slug' => $agent['slug'], 'label' => $agent['label'], 'status' => $agent['status'], 'environment' => $agent['environment'], 'revision' => (int) $agent['revision'] ),
			'token_scopes_simulated' => ! empty( $scopes ),
			'effective' => $items,
			'allowed_count' => $allowed,
			'denied_count' => $denied,
		);
	}

	public static function approval_plan( $input ) {
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $input['agent_public_id'] );
		if ( ! $agent || 'enabled' !== $agent['status'] ) return new WP_Error( 'mad4b_approval_agent_invalid', 'Approval planning requires an enabled agent.' );
		$server_id = sanitize_key( (string) $input['server_id'] );
		$ability_name = (string) $input['ability'];
		$provider = isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : 'core';
		if ( '' === $provider ) $provider = 'core';
		if ( ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_approval_ability_unknown', 'Approval target ability is not registered.' );
		if ( ! MAD4B_SCP_Servers::ability_is_mounted( $server_id, $ability_name ) ) return new WP_Error( 'mad4b_approval_server_mismatch', 'Approval target ability is not mounted on the requested MCP server.' );
		$ability = wp_get_ability( $ability_name );
		$meta = $ability && method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		if ( ! empty( $meta['annotations']['readonly'] ) ) return new WP_Error( 'mad4b_approval_readonly_target', 'Approval plans are only created for mutation abilities.' );
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability_name, $provider );
		if ( is_wp_error( $grant ) ) return $grant;

		$operation_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		$impact = MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $operation_input );
		$required_class = MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $operation_input );
		$ticket_class = isset( $input['ticket_class'] ) ? sanitize_key( (string) $input['ticket_class'] ) : $required_class;
		if ( $ticket_class !== $required_class ) return new WP_Error( 'mad4b_approval_class_policy_mismatch', 'Requested ticket class does not match the central impact policy.' );
		$provider_state = self::provider_runtime_state( $provider );
		if ( 'blocked' === $provider_state['state'] ) return new WP_Error( 'mad4b_approval_provider_not_certified', 'Provider runtime is not certified for the planned mutation.', $provider_state );

		$target = isset( $input['target_fingerprint'] ) ? (string) $input['target_fingerprint'] : '';
		$ticket = MAD4B_SCP_Approval_Tickets::create_pending(
			$agent['public_id'], $server_id, $ability_name, $provider, $target, $operation_input, $ticket_class,
			(string) $input['reason'], isset( $input['ttl'] ) ? absint( $input['ttl'] ) : MAD4B_SCP_Approval_Tickets::DEFAULT_TTL
		);
		if ( is_wp_error( $ticket ) ) return $ticket;
		MAD4B_SCP_Audit::record( 'mad4b/approval-plan', array( 'ticket_id' => $ticket['ticket_id'], 'agent_public_id' => $agent['public_id'], 'server_id' => $server_id, 'ability' => $ability_name, 'provider' => $provider, 'impact' => $impact, 'status' => 'pending' ) );
		return array(
			'ticket_id' => $ticket['ticket_id'],
			'status' => $ticket['status'],
			'payload_sha256' => $ticket['payload_sha256'],
			'expires_at' => $ticket['expires_at'],
			'impact' => $impact,
			'ticket_class' => $ticket_class,
			'provider_runtime' => $provider_state,
			'grant_id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
			'auto_approved' => false,
		);
	}

	private static function provider_runtime_state( $provider ) {
		$provider = sanitize_key( (string) $provider );
		if ( in_array( $provider, array( '', 'core', 'media' ), true ) ) return array( 'state' => 'n/a', 'runtime_contract_ok' => true );
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		$adapter = $registry->get( $provider );
		if ( ! $adapter ) return array( 'state' => 'blocked', 'runtime_contract_ok' => false, 'reason' => 'adapter_unknown' );
		$status = $adapter->status();
		$cert = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
		$ok = empty( $status['mutation_requires_certification'] ) || ! empty( $cert['runtime_contract_ok'] );
		return array(
			'state' => $ok ? 'certified' : 'blocked',
			'runtime_contract_ok' => $ok,
			'available' => ! empty( $status['available'] ),
			'version' => isset( $status['version'] ) ? (string) $status['version'] : '',
			'provider' => $provider,
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}
}
