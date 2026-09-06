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
			'Create one pending exact-operation approval ticket after validating agent grant, mounted server/provider authority and target fingerprint. This never approves or executes the operation.',
			'approval_plan',
			self::schema(
				array(
					'agent_public_id' => array( 'type' => 'string', 'minLength' => 36, 'maxLength' => 64 ),
					'server_id' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Servers::expected_server_ids() ),
					'ability' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191 ),
					'provider' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
					'target_fingerprint' => array( 'type' => 'string', 'maxLength' => 191 ),
					'input' => array( 'type' => 'object', 'additionalProperties' => true, 'default' => array() ),
					'ticket_class' => array( 'type' => 'string', 'enum' => array( 'mutation', 'breakglass', 'recovery' ) ),
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
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
		if ( count( $scopes ) > MAD4B_SCP_Identity_Context::MAX_SCOPES ) return new WP_Error( 'mad4b_effective_access_scopes_too_many', 'Simulation contains too many token scopes.' );
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) || '' === trim( $scope ) || strlen( $scope ) > 255 || false !== strpos( $scope, '*' ) ) return new WP_Error( 'mad4b_effective_access_scope_invalid', 'Simulation accepts exact non-wildcard token scopes only.' );
		}
		$server_id = isset( $input['server_id'] ) ? sanitize_key( (string) $input['server_id'] ) : '';
		$t = MAD4B_SCP_Schema::tables();
		$current_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		$sql = "SELECT id, effect, server_id, ability_name, provider, resource_schema_version, resource_constraints, environment FROM {$t['grants']} WHERE agent_id = %d AND environment IN ('all', %s)";
		$args = array( (int) $agent['id'], $current_environment );
		if ( '' !== $server_id ) { $sql .= ' AND server_id = %s'; $args[] = $server_id; }
		$sql .= ' ORDER BY server_id ASC, ability_name ASC, provider ASC, CASE effect WHEN \'deny\' THEN 0 ELSE 1 END, id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$groups = array();
		foreach ( $rows as $row ) {
			$key = $row['server_id'] . "\0" . $row['ability_name'] . "\0" . $row['provider'];
			if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = array();
			$groups[ $key ][] = $row;
		}

		$items = array();
		$allowed = 0;
		$denied = 0;
		$conditional = 0;
		$agent_environment_ok = in_array( $agent['environment'], array( 'all', 'unknown', $current_environment ), true );
		foreach ( $groups as $group ) {
			$sample = $group[0];
			$winning = null;
			$grant_ids = array();
			foreach ( $group as $row ) {
				$grant_ids[] = (int) $row['id'];
				if ( 'deny' === $row['effect'] ) { $winning = $row; break; }
				if ( null === $winning && 'allow' === $row['effect'] ) $winning = $row;
			}
			if ( null === $winning ) continue;

			$scope_state = empty( $scopes ) ? 'not_simulated' : ( in_array( 'ability:' . $sample['ability_name'], $scopes, true ) ? 'allowed' : 'denied' );
			$provider_state = self::provider_runtime_state( $sample['provider'] );
			$constraints = json_decode( (string) $winning['resource_constraints'], true );
			$constraints_valid = is_array( $constraints );
			if ( ! $constraints_valid ) $constraints = array( '_invalid' => true );
			$constraint_state = ! $constraints_valid ? 'invalid' : ( empty( $constraints ) ? 'none' : 'unresolved_without_target' );
			$mounted = MAD4B_SCP_Servers::ability_is_mounted( $sample['server_id'], $sample['ability_name'] );
			$provider_matches = $sample['provider'] === MAD4B_SCP_Servers::provider_for_ability( $sample['server_id'], $sample['ability_name'] );
			$base_allowed = 'enabled' === $agent['status'] && $agent_environment_ok && 'allow' === $winning['effect'] && 'denied' !== $scope_state && $mounted && $provider_matches && 'blocked' !== $provider_state['state'] && $constraints_valid;
			$is_conditional = $base_allowed && ! empty( $constraints );
			$effective = $base_allowed && ! $is_conditional;
			$decision = $effective ? 'allowed' : ( $is_conditional ? 'conditional' : 'denied' );
			if ( 'allowed' === $decision ) ++$allowed; elseif ( 'conditional' === $decision ) ++$conditional; else ++$denied;

			$items[] = array(
				'grant_ids' => $grant_ids,
				'server_id' => $sample['server_id'],
				'ability' => $sample['ability_name'],
				'provider' => $sample['provider'],
				'grant' => $winning['effect'],
				'scope' => $scope_state,
				'mounted' => $mounted,
				'provider_matches_mount' => $provider_matches,
				'provider_runtime' => $provider_state,
				'impact' => MAD4B_SCP_Impact_Policy::impact_for( $sample['ability_name'], $sample['provider'], null ),
				'approval_required' => MAD4B_SCP_Impact_Policy::requires_approval( $sample['ability_name'], $sample['provider'], null ),
				'resource_schema_version' => $winning['resource_schema_version'],
				'resource_constraints' => $constraints,
				'constraint_state' => $constraint_state,
				'decision' => $decision,
				'effective' => $effective,
			);
		}
		return array(
			'agent' => array( 'public_id' => $agent['public_id'], 'slug' => $agent['slug'], 'label' => $agent['label'], 'status' => $agent['status'], 'environment' => $agent['environment'], 'revision' => (int) $agent['revision'] ),
			'agent_environment_matches' => $agent_environment_ok,
			'token_scopes_simulated' => ! empty( $scopes ),
			'effective' => $items,
			'allowed_count' => $allowed,
			'conditional_count' => $conditional,
			'denied_count' => $denied,
		);
	}

	public static function approval_plan( $input ) {
		$agent = MAD4B_SCP_Agent_Registry::get_agent_by_public_id( (string) $input['agent_public_id'] );
		if ( ! $agent || 'enabled' !== $agent['status'] ) return new WP_Error( 'mad4b_approval_agent_invalid', 'Approval planning requires an enabled agent.' );
		$current_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown';
		if ( ! in_array( $agent['environment'], array( 'all', 'unknown', $current_environment ), true ) ) return new WP_Error( 'mad4b_approval_agent_environment_denied', 'Approval planning agent is not enabled for this environment.' );

		$server_id = sanitize_key( (string) $input['server_id'] );
		$ability_name = (string) $input['ability'];
		if ( ! wp_has_ability( $ability_name ) ) return new WP_Error( 'mad4b_approval_ability_unknown', 'Approval target ability is not registered.' );
		$expected_provider = MAD4B_SCP_Servers::provider_for_ability( $server_id, $ability_name );
		if ( null === $expected_provider ) return new WP_Error( 'mad4b_approval_server_mismatch', 'Approval target ability is not mounted on the requested MCP server.' );
		$provider = isset( $input['provider'] ) && '' !== trim( (string) $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : $expected_provider;
		if ( $provider !== $expected_provider ) return new WP_Error( 'mad4b_approval_provider_mismatch', 'Approval provider does not match the certified provider bound to this mounted ability.', array( 'expected_provider' => $expected_provider ) );

		$ability = wp_get_ability( $ability_name );
		$meta = $ability && method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		if ( ! isset( $meta['annotations']['readonly'] ) ) return new WP_Error( 'mad4b_approval_annotation_missing', 'Approval target lacks a certified readonly/mutation annotation.' );
		if ( ! empty( $meta['annotations']['readonly'] ) ) return new WP_Error( 'mad4b_approval_readonly_target', 'Approval plans are only created for mutation abilities.' );
		$grant = MAD4B_SCP_Agent_Registry::exact_grant( $agent['id'], $server_id, $ability_name, $provider );
		if ( is_wp_error( $grant ) ) return $grant;

		$operation_input = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		$impact = MAD4B_SCP_Impact_Policy::impact_for( $ability_name, $provider, $operation_input );
		if ( ! MAD4B_SCP_Impact_Policy::requires_approval( $ability_name, $provider, $operation_input ) ) return new WP_Error( 'mad4b_approval_not_required', 'Central impact policy does not require an approval ticket for this exact operation.' );
		$required_class = MAD4B_SCP_Impact_Policy::ticket_class_for( $ability_name, $provider, $operation_input );
		$ticket_class = isset( $input['ticket_class'] ) && '' !== trim( (string) $input['ticket_class'] ) ? sanitize_key( (string) $input['ticket_class'] ) : $required_class;
		if ( $ticket_class !== $required_class ) return new WP_Error( 'mad4b_approval_class_policy_mismatch', 'Requested ticket class does not match the central impact policy.' );
		$provider_state = self::provider_runtime_state( $provider );
		if ( 'blocked' === $provider_state['state'] ) return new WP_Error( 'mad4b_approval_provider_not_certified', 'Provider runtime is not certified for the planned mutation.', $provider_state );

		$target = MAD4B_SCP_Authorization::target_fingerprint( $ability_name, $provider, $operation_input, $agent, array( 'planning' => true ) );
		$asserted_target = isset( $input['target_fingerprint'] ) ? (string) $input['target_fingerprint'] : '';
		if ( '' !== $asserted_target && $asserted_target !== $target ) return new WP_Error( 'mad4b_approval_target_mismatch', 'Caller target fingerprint assertion does not match the central target resolver.', array( 'resolved_target_fingerprint' => $target ) );

		$ticket = MAD4B_SCP_Approval_Tickets::create_pending(
			$agent['public_id'], $server_id, $ability_name, $provider, $target, $operation_input, $ticket_class,
			(string) $input['reason'], isset( $input['ttl'] ) ? absint( $input['ttl'] ) : MAD4B_SCP_Approval_Tickets::DEFAULT_TTL
		);
		if ( is_wp_error( $ticket ) ) return $ticket;
		MAD4B_SCP_Audit::record( 'mad4b/approval-plan', array( 'ticket_id' => $ticket['ticket_id'], 'agent_public_id' => $agent['public_id'], 'server_id' => $server_id, 'ability' => $ability_name, 'provider' => $provider, 'impact' => $impact, 'target_fingerprint' => $target, 'status' => 'pending' ) );
		return array(
			'ticket_id' => $ticket['ticket_id'],
			'status' => $ticket['status'],
			'payload_sha256' => $ticket['payload_sha256'],
			'expires_at' => $ticket['expires_at'],
			'impact' => $impact,
			'ticket_class' => $ticket_class,
			'target_fingerprint' => $target,
			'provider_runtime' => $provider_state,
			'grant_id' => isset( $grant['id'] ) ? (int) $grant['id'] : 0,
			'auto_approved' => false,
		);
	}

	private static function provider_runtime_state( $provider ) {
		$provider = sanitize_key( (string) $provider );
		if ( in_array( $provider, array( '', 'core' ), true ) ) return array( 'state' => 'n/a', 'runtime_contract_ok' => true, 'available' => true, 'provider' => 'core' );
		$registry = MAD4B_SCP_Adapter_Registry::instance();
		$registry->register_defaults();
		$adapter = null;
		foreach ( $registry->all() as $candidate ) {
			if ( method_exists( $candidate, 'provider_key' ) && $candidate->provider_key() === $provider ) { $adapter = $candidate; break; }
		}
		if ( ! $adapter ) return array( 'state' => 'blocked', 'runtime_contract_ok' => false, 'available' => false, 'provider' => $provider, 'reason' => 'adapter_unknown' );
		$status = $adapter->status();
		$cert = isset( $status['provider_certification'] ) && is_array( $status['provider_certification'] ) ? $status['provider_certification'] : array();
		$requires = ! empty( $status['mutation_requires_certification'] );
		$available = ! empty( $status['available'] );
		$certified = ! $requires || ! empty( $cert['runtime_contract_ok'] );
		$ok = $available && $certified;
		return array(
			'state' => $ok ? ( $requires ? 'certified' : 'available' ) : 'blocked',
			'runtime_contract_ok' => $certified,
			'available' => $available,
			'version' => isset( $status['version'] ) ? (string) $status['version'] : '',
			'provider' => $provider,
			'adapter' => isset( $status['id'] ) ? (string) $status['id'] : '',
		);
	}

	private static function schema( array $properties, array $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( $required ) $schema['required'] = $required;
		return $schema;
	}
}
