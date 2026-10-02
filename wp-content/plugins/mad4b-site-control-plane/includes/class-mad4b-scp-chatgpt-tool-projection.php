<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Governed dynamic projection of registered WordPress Abilities into the compact
 * mad4b-chatgpt tools/list surface.
 *
 * Projection changes visibility only. It never grants execution authority and
 * never bypasses the target Ability permission callback / authorization path.
 */
final class MAD4B_SCP_ChatGPT_Tool_Projection {
	const CONTRACT = 'mad4b.chatgpt-tool-projection.v1';
	const OPTION = 'mad4b_scp_chatgpt_tool_projection_v1';
	const STATUS_ABILITY = 'mad4b/chatgpt-tool-projection-status';
	const DISCOVER_ABILITY = 'mad4b/chatgpt-tool-projection-discover';
	const PLAN_ABILITY = 'mad4b/chatgpt-tool-projection-plan';
	const APPLY_ABILITY = 'mad4b/chatgpt-tool-projection-apply';
	const CONFIRMATION = 'APPLY CHATGPT TOOL PROJECTION';
	const MAX_SELECTED = 64;

	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 34 );
		add_filter( 'mcp_adapter_pre_tool_call', array( __CLASS__, 'guard_tool_call' ), PHP_INT_MAX, 4 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::STATUS_ABILITY ) ) ) {
			wp_register_ability( self::STATUS_ABILITY, array(
				'label' => 'Get ChatGPT Dynamic Tool Projection',
				'description' => 'Inspect the bounded dynamic projection of registered site Abilities into the ChatGPT tools/list surface, including schema drift and budget state.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'status' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => self::meta( true ),
			) );
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::DISCOVER_ABILITY ) ) ) {
			wp_register_ability( self::DISCOVER_ABILITY, array(
				'label' => 'Discover Site Abilities for ChatGPT Projection',
				'description' => 'Search every registered WordPress Ability on this site and inspect its projection-relevant classification without changing tools/list.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'discover' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => array(
					'type' => 'object',
					'properties' => array(
						'transport_action' => array( 'type' => 'string', 'enum' => array( 'capabilities', 'manifest', 'schema', 'chunk' ) ),
						'cursor' => array( 'type' => 'string', 'maxLength' => 4096 ),
						'schema_format' => array( 'type' => 'string', 'enum' => array( 'source', 'wire' ) ),
						'force_refresh' => array( 'type' => 'boolean' ),
						'gateway_action' => array( 'type' => 'string', 'enum' => array( 'negotiate', 'search', 'prepare', 'schema', 'chunk' ) ),
						'task' => array( 'type' => 'string', 'maxLength' => 512 ),
						'keywords' => array( 'type' => 'array', 'maxItems' => 24, 'items' => array( 'type' => 'string', 'maxLength' => 80 ) ),
						'ability_names' => array( 'type' => 'array', 'maxItems' => 16, 'items' => array( 'type' => 'string', 'maxLength' => 180 ) ),
						'ability_name' => array( 'type' => 'string', 'maxLength' => 180 ),
						'client_capabilities' => array( 'type' => 'object', 'additionalProperties' => true ),
						'known_schemas' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ),

						'snapshot' => array( 'type' => 'string' ),
						'known_snapshot' => array( 'type' => 'string' ),
						'schema_sha256' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'chunk_index' => array( 'type' => 'integer', 'minimum' => 0 ),
						'chunk_bytes' => array( 'type' => 'integer', 'minimum' => 1024, 'maximum' => 1048576 ),
						'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
						'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 2147483647, 'default' => 0 ),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => self::meta( true ),
			) );
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::PLAN_ABILITY ) ) ) {
			wp_register_ability( self::PLAN_ABILITY, array(
				'label' => 'Plan ChatGPT Dynamic Tool Projection',
				'description' => 'Plan an exact schema-pinned set of registered site Abilities to project into ChatGPT tools/list without changing execution authority.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'plan' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => self::change_schema( false ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => self::meta( true ),
			) );
		}

		if ( ! ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::APPLY_ABILITY ) ) ) {
			wp_register_ability( self::APPLY_ABILITY, array(
				'label' => 'Apply ChatGPT Dynamic Tool Projection',
				'description' => 'Persist one exact schema-pinned Staging projection plan. Projection changes tools/list visibility only and never grants target execution authority.',
				'category' => 'mad4b-governance',
				'execute_callback' => array( __CLASS__, 'apply' ),
				'permission_callback' => array( __CLASS__, 'can_apply' ),
				'input_schema' => self::change_schema( true ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => self::meta( false ),
			) );
		}
	}

	private static function meta( $readonly ) {
		return array(
			'public' => false,
			'show_in_rest' => false,
			'mcp' => array(
				'public' => false,
				'type' => 'tool',
				'surface' => $readonly ? 'read' : 'enrollment',
				'chatgpt_tool_projection_contract' => self::CONTRACT,
				'chatgpt_direct_step_up' => ! $readonly,
				'exact_chatgpt_client_required' => ! $readonly,
				'projection_changes_authority' => false,
				'production_mutation_allowed' => false,
				'generic_remote_admin' => false,
			),
			'annotations' => array(
				'readonly' => (bool) $readonly,
				'destructive' => false,
				'idempotent' => (bool) $readonly,
			),
		);
	}

	private static function change_schema( $apply ) {
		$properties = array(
			'mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'add', 'remove' ), 'default' => 'replace' ),
			'ability_names' => array(
				'type' => 'array',
				'minItems' => 0,
				'maxItems' => self::MAX_SELECTED,
				'uniqueItems' => true,
				'items' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9._+\\/-]+$' ),
			),
			'include_breakglass' => array( 'type' => 'boolean', 'default' => false ),
		);
		$required = array( 'ability_names' );
		if ( $apply ) {
			$properties['expected_plan_sha256'] = array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' );
			$properties['confirmation'] = array( 'type' => 'string', 'enum' => array( self::CONFIRMATION ) );
			$required[] = 'expected_plan_sha256';
			$required[] = 'confirmation';
		}
		return array(
			'type' => 'object',
			'properties' => $properties,
			'required' => $required,
			'additionalProperties' => false,
		);
	}

	private static function raw_state() {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) || ! isset( $state['contract'] ) || self::CONTRACT !== (string) $state['contract'] ) {
			return array(
				'contract' => self::CONTRACT,
				'revision' => 0,
				'abilities' => array(),
				'updated_at' => '',
			);
		}
		$state['revision'] = isset( $state['revision'] ) ? max( 0, (int) $state['revision'] ) : 0;
		$state['abilities'] = isset( $state['abilities'] ) && is_array( $state['abilities'] ) ? $state['abilities'] : array();
		if ( count( $state['abilities'] ) > self::MAX_SELECTED ) { $state['abilities'] = array(); $state['quarantine_reason'] = 'registry_size_exceeded'; }
		return $state;
	}

	private static function schema_sha256( $ability ) {
		$schema = is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) return '';
		return hash( 'sha256', $json );
	}

	public static function bounded_metadata( $value, $bytes ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $bytes ) return $value;
		if ( function_exists( 'mb_strcut' ) ) return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		return wp_check_invalid_utf8( substr( $value, 0, $bytes ), true );
	}

	public static function describe_ability( $name ) { return self::ability_row( $name ); }

	private static function ability_row( $ability_name ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return new WP_Error( 'mad4b_projection_blog_switch_denied', 'Use a fresh request to the target site.' );
		try { return self::inspect_ability_row( $ability_name ); }
		catch ( Throwable $error ) { return new WP_Error( 'mad4b_chatgpt_projection_ability_inspection_failed', 'Ability inspection failed; this projection is unavailable.' ); }
	}

	private static function inspect_ability_row( $ability_name ) {
		$ability_name = trim( (string) $ability_name );
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_ability_unavailable', 'Requested Ability is not registered in the current site runtime.', array( 'ability_name' => $ability_name ) );
		}
		$ability = wp_get_ability( $ability_name );
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) || ! method_exists( $ability, 'execute' ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_ability_contract_unavailable', 'Requested Ability does not expose the required WordPress Ability contract.', array( 'ability_name' => $ability_name ) );
		}
		$meta = $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		$readonly_declared = array_key_exists( 'readonly', $annotations ) && is_bool( $annotations['readonly'] );
		$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$surface = isset( $mcp['surface'] ) ? sanitize_key( (string) $mcp['surface'] ) : '';
		$mutation_lanes = array( 'write', 'developer', 'enrollment', 'internal', 'breakglass', 'developer-breakglass' );
		$lane = 'unclassified';
		if ( in_array( $surface, $mutation_lanes, true ) || in_array( $surface, array( 'admin', 'content' ), true ) ) $lane = $surface;
		elseif ( $readonly_declared && true === $annotations['readonly'] && in_array( $surface, array( '', 'read' ), true ) ) $lane = 'read';
		elseif ( $readonly_declared && false === $annotations['readonly'] && '' === $surface ) $lane = 'write';
		$readonly = $readonly_declared && true === $annotations['readonly'];
		$known = 'unclassified' !== $lane;
		$schema_digest = self::schema_sha256( $ability );
		if ( '' === $schema_digest ) return new WP_Error( 'mad4b_chatgpt_projection_schema_invalid', 'Ability schema cannot be serialized.' );
		$provider = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::provider_for_ability( 'mad4b-' . $lane, $ability_name ) : null;
		$breakglass = in_array( $lane, array( 'breakglass', 'developer-breakglass' ), true ) || in_array( $ability_name, array_merge(
			class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' ) : array(),
			class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-developer-breakglass' ) : array()
		), true );

		$projection_blockers = array();
		if ( ! $known ) $projection_blockers[] = 'ability_classification_required';
		if ( 'internal' === $lane ) $projection_blockers[] = 'internal_surface_not_direct';
		$projection_eligible = empty( $projection_blockers );
		
		$boundary_verified = class_exists( 'MAD4B_SCP_Authorization' ) && MAD4B_SCP_Authorization::execution_boundary_verified( $ability );
		$execution_blocker = ! $projection_eligible ? 'ability_projection_policy_blocked' : ( ! $readonly && ! $boundary_verified ? 'governed_execution_boundary_required' : ( 'read' !== $lane && null === $provider ? 'original_lane_not_mounted' : '' ) );
		$execution_eligible = '' === $execution_blocker;
		$execution_lane = $execution_eligible ? $lane : 'none';

		$classification_json = wp_json_encode(
			array(
				'meta' => $meta,
				'category' => $category,
				'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null,
				'execution_provider' => $provider,
				'boundary_verified' => $boundary_verified,
				'classification' => $lane,
				'known' => $known,
				'projection_eligible' => $projection_eligible,
				'execution_lane' => $execution_lane,
				'projection_blockers' => $projection_blockers,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $classification_json ) ) return new WP_Error( 'mad4b_chatgpt_projection_classification_invalid', 'Projection classification cannot be serialized.' );

		return array(
			'ability_name' => $ability_name,
			'input_schema_sha256' => $schema_digest,
			'classification_sha256' => hash( 'sha256', $classification_json ),
			'classification' => $lane,
			'known' => (bool) $known,
			'lane' => $lane,
			'readonly' => (bool) $readonly,
			'readonly_declared' => (bool) $readonly_declared,
			'conservative_mutation' => ! $readonly_declared,
			'breakglass' => (bool) $breakglass,
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
			'execution_boundary' => isset( $mcp['mad4b_execution_boundary'] ) ? (string) $mcp['mad4b_execution_boundary'] : '',
			'execution_provider' => null === $provider ? '' : (string) $provider,
			'execution_boundary_verified' => $boundary_verified,
			'execution_blocker' => $execution_blocker,
			'label' => method_exists( $ability, 'get_label' ) ? self::bounded_metadata( $ability->get_label(), 160 ) : '',
			'description' => method_exists( $ability, 'get_description' ) ? self::bounded_metadata( $ability->get_description(), 2048 ) : '',
			'projection_eligible' => (bool) $projection_eligible,
			'execution_eligible' => (bool) $execution_eligible,
			'execution_lane' => $execution_lane,
			'projection_blockers' => $projection_blockers,
		);
	}

	public static function current_binding() {
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ) return array();
		return array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(), 'origin' => MAD4B_SCP_Site_Profile::current_origin(), 'environment' => MAD4B_SCP_Site_Profile::current_environment(), 'profile_revision' => MAD4B_SCP_Site_Profile::revision() );
	}

	private static function binding_matches( array $state ) {
		return class_exists( 'MAD4B_SCP_Site_Profile' ) && MAD4B_SCP_Site_Profile::configured()
			&& 'staging' === MAD4B_SCP_Site_Profile::current_environment()
			&& MAD4B_SCP_Site_Profile::origin_enrolled() && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
			&& isset( $state['binding'] ) && $state['binding'] === self::current_binding();
	}

	/** Execution admission is independent of tools/list visibility and grants no authority. */
	public static function guard_tool_call( $args, $tool_name, $tool, $server ) {
		unset( $tool_name );
		if ( is_wp_error( $args ) ) return $args;
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || 'mad4b-chatgpt' !== $server->get_server_id() ) return $args;
		$meta = is_object( $tool ) && method_exists( $tool, 'get_adapter_meta' ) ? $tool->get_adapter_meta() : array();
		$name = isset( $meta['ability'] ) ? (string) $meta['ability'] : '';
		// Existing base tools retain their independent permission and execution contracts.
		if ( in_array( $name, MAD4B_SCP_Servers::chatgpt_base_tools(), true ) ) return $args;
		$state = self::raw_state();
		if ( ! self::binding_matches( $state ) ) return new WP_Error( 'mad4b_projection_binding_mismatch', 'Dynamic projection is not bound to this enrolled Staging runtime.' );
		$row = self::effective_row( $name, $state, true );
		if ( is_wp_error( $row ) ) return $row;
		if ( ! self::materialized_tool_matches( $tool, $server ) ) return new WP_Error( 'mad4b_projection_materialized_drift', 'Materialized tool contract changed; refresh registration.' );
		if ( empty( $row['execution_eligible'] ) ) return new WP_Error( 'mad4b_projection_execution_blocked', $row['execution_blocker'] );
		if ( ! empty( $row['breakglass'] ) ) {
			$breakglass_scope = class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false )
				&& MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
				&& (
					MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::BREAKGLASS_SCOPE )
					|| MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'ability:' . $name )
				);
			if ( ! $breakglass_scope ) return new WP_Error( 'mad4b_projection_breakglass_scope_required', 'Projected Breakglass execution requires an exact Breakglass server or Ability OAuth scope.' );
		}
		if ( 'read' !== $row['lane'] || empty( $row['readonly'] ) ) {
			$step_up = self::can_apply();
			if ( is_wp_error( $step_up ) ) return $step_up;
			if ( empty( $row['readonly'] ) && empty( $row['execution_boundary_verified'] ) ) return new WP_Error( 'mad4b_projection_execution_boundary_required', 'Mutating or unclassified Ability requires a governed execution boundary before remote execution.' );
			$lane = self::execution_server( $name );
			if ( is_wp_error( $lane ) ) return $lane;
		}
		return $args;
	}

	private static function property_value( $object, $name ) {
		$property = ( new ReflectionObject( $object ) )->getProperty( $name );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	public static function callback_identity( $tool ) {
		try {
			$a = self::property_value( $tool, 'ability' );
			return array( self::property_value( $a, 'execute_callback' ), self::property_value( $a, 'permission_callback' ) );
		} catch ( Throwable $e ) { return null; }
	}
	public static function materialized_tool_matches( $tool, $server = null ) {
		try {
			$meta = $tool->get_adapter_meta(); $name = $meta['ability']; $current = wp_get_ability( $name );
			$bound = self::property_value( $tool, 'ability' );
			if ( ! $current || ! $bound ) return false;
			foreach ( array( 'execute_callback', 'permission_callback' ) as $property ) if ( self::property_value( $current, $property ) !== self::property_value( $bound, $property ) ) return false;
			$built = \WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build( $current );
			if ( is_wp_error( $built ) ) return false;
			$actual = $tool->get_protocol_dto()->toArray();
			if ( wp_json_encode( $actual ) !== wp_json_encode( $built['tool']->toArray() ) || $bound->get_meta() !== $current->get_meta() ) return false;
			if ( is_object( $server ) && method_exists( $server, 'get_mcp_tool' ) ) {
				if ( ! MAD4B_SCP_MCP_Catalog_Diagnostics::materialized_callbacks_match( $tool, $server ) ) return false;
				$receipt = MAD4B_SCP_MCP_Catalog_Diagnostics::classification_snapshot()[ $actual['name'] ] ?? array();
				$row = self::ability_row( $name );
				if ( is_wp_error( $row ) || ( $receipt['input_schema_sha256'] ?? '' ) !== $row['input_schema_sha256'] || ( $receipt['classification_sha256'] ?? '' ) !== $row['classification_sha256'] || ( $receipt['wire_sha256'] ?? '' ) !== hash( 'sha256', wp_json_encode( $actual ) ) ) return false;
			}
			return true;
		} catch ( Throwable $e ) { return false; }
	}

	public static function execution_server( $name ) {
		$state = self::raw_state();
		if ( ! self::binding_matches( $state ) ) return new WP_Error( 'mad4b_projection_binding_mismatch', 'Projection binding is unavailable.' );
		$row = self::effective_row( $name, $state );
		if ( is_wp_error( $row ) ) return $row;
		$server = 'mad4b-' . $row['lane'];
		if ( ! MAD4B_SCP_Servers::ability_is_mounted( $server, $name ) ) return new WP_Error( 'mad4b_projection_original_lane_unmounted', 'Ability is not mounted on its original governed execution lane.' );
		return $server;
	}

	private static function effective_row( $name, array $state, $request_authority = false ) {
		$stored = isset( $state['abilities'][ $name ] ) ? $state['abilities'][ $name ] : null;
		if ( ! is_array( $stored ) ) return new WP_Error( 'mad4b_projection_not_selected', 'Ability is not selected for dynamic projection.' );
		$current = self::ability_row( $name );
		if ( is_wp_error( $current ) ) return $current;
		if ( empty( $current['projection_eligible'] ) ) return new WP_Error( 'mad4b_chatgpt_projection_policy_blocked', 'Ability classification does not permit projection.' );
		foreach ( array( 'input_schema_sha256', 'classification_sha256' ) as $pin ) {
			if ( empty( $stored[ $pin ] ) || ! hash_equals( (string) $stored[ $pin ], $current[ $pin ] ) ) return new WP_Error( 'mad4b_projection_identity_drift', 'Ability schema or authority classification changed after projection review.' );
		}
		// Registration precedes bearer verification. Only list/call admission may
		// evaluate request identity; structural registration grants no visibility.
		if ( $request_authority && $current['breakglass'] && ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_breakglass() ) ) return new WP_Error( 'mad4b_projection_breakglass_disabled', 'Breakglass is not currently authorized.' );
		return $current;
	}

	public static function all_site_ability_names() {
		$names = array();
		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $name => $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) $name = $ability->get_name();
			$name = (string) $name;
			if ( '' !== $name ) $names[] = $name;
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	public static function discover( $input = array() ) {
		if ( is_array( $input ) && isset( $input['gateway_action'] ) ) { $input['action'] = $input['gateway_action']; return MAD4B_SCP_Ability_Catalog_Transport::mcp_result( MAD4B_SCP_Unified_Capability_Gateway::dispatch( $input, 'mcp' ) ); }
		if ( is_array( $input ) && isset( $input['transport_action'] ) ) return MAD4B_SCP_Ability_Catalog_Transport::mcp_result( MAD4B_SCP_Ability_Catalog_Transport::handle( $input ) );
		$input = is_array( $input ) ? $input : array();
		$query = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$offset = isset( $input['offset'] ) ? max( 0, absint( $input['offset'] ) ) : 0;
		$projected = array_fill_keys( self::projected_ability_names(), true );
		$items = array();
		$matched = 0;
		$has_more = false;
		foreach ( self::all_site_ability_names() as $ability_name ) {
			// Match and paginate metadata before inspecting schemas/classification.
			try {
				$ability = wp_get_ability( $ability_name );
				$label = self::bounded_metadata( $ability->get_label(), 160 );
				$description = self::bounded_metadata( $ability->get_description(), 2048 );
				$haystack = strtolower( $ability_name . ' ' . $label . ' ' . $description . ' ' . $ability->get_category() );
			} catch ( Throwable $error ) { $haystack = strtolower( $ability_name ); }
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			if ( $matched++ < $offset ) continue;
			if ( count( $items ) >= $limit ) { $has_more = true; break; }
			$row = self::ability_row( $ability_name );
			$blocked = is_wp_error( $row );
			if ( $blocked ) $row = array( 'ability_name' => $ability_name, 'category' => '', 'label' => '', 'description' => '', 'projection_blocker' => $row->get_error_code() );
			$label = $row['label'];
			$description = $row['description'];
			$items[] = array_merge( $row, array(
				'label' => $label,
				'description' => $description,
				'currently_projected' => isset( $projected[ $ability_name ] ),
				'classification_available' => ! $blocked,
				'execution_governed' => ! $blocked && ! empty( $row['execution_eligible'] ),
			) );
		}
		return array(
			'contract' => self::CONTRACT,
			'query' => $query,
			'offset' => $offset,
			'limit' => $limit,
			'items' => $items,
			'count' => count( $items ),
			'has_more' => $has_more,
			'next_offset' => $has_more ? $offset + count( $items ) : null,
			'universe_count' => count( self::all_site_ability_names() ),
			'read_only' => true,
			'mutation_performed' => false,
		);
	}

	private static function desired_names( array $input ) {
		$requested = isset( $input['ability_names'] ) && is_array( $input['ability_names'] ) ? array_values( array_unique( array_map( 'strval', $input['ability_names'] ) ) ) : array();
		sort( $requested, SORT_STRING );
		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'replace';
		if ( ! in_array( $mode, array( 'replace', 'add', 'remove' ), true ) ) $mode = 'replace';
		$current = array_keys( self::raw_state()['abilities'] );
		if ( 'add' === $mode ) $desired = array_merge( $current, $requested );
		elseif ( 'remove' === $mode ) $desired = array_diff( $current, $requested );
		else $desired = $requested;
		$desired = array_values( array_unique( array_map( 'strval', $desired ) ) );
		sort( $desired, SORT_STRING );
		if ( count( $desired ) > self::MAX_SELECTED ) return new WP_Error( 'mad4b_chatgpt_projection_selection_too_large', 'Dynamic projection selection exceeds the bounded registry size.' );
		return $desired;
	}

	private static function canonical_plan_payload( array $rows, array $input ) {
		$current = self::raw_state();
		$payload = array(
			'contract' => self::CONTRACT,
			'projection_revision' => (int) $current['revision'],
			'binding' => self::current_binding(),
			'environment' => class_exists( 'MAD4B_SCP_Site_Profile' ) ? (string) MAD4B_SCP_Site_Profile::current_environment() : '',
			'site_uuid' => class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '',
			'mode' => isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : 'replace',
			'include_breakglass' => ! empty( $input['include_breakglass'] ),
			'abilities' => array_values( $rows ),
		);
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return array( $payload, is_string( $json ) ? hash( 'sha256', $json ) : '' );
	}

	public static function plan( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$desired = self::desired_names( $input );
		if ( is_wp_error( $desired ) ) return $desired;
		$include_breakglass = ! empty( $input['include_breakglass'] );
		$rows = array();
		foreach ( $desired as $ability_name ) {
			$row = self::ability_row( $ability_name );
			if ( is_wp_error( $row ) ) return $row;
			if ( ! empty( $row['breakglass'] ) && ! $include_breakglass ) {
				return new WP_Error( 'mad4b_chatgpt_projection_breakglass_explicit_opt_in_required', 'Breakglass Ability projection requires include_breakglass=true.', array( 'ability_name' => $ability_name ) );
			}
			if ( ! empty( $row['breakglass'] ) && ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_breakglass() ) ) {
				return new WP_Error( 'mad4b_chatgpt_projection_breakglass_not_ready', 'Breakglass Ability projection is unavailable while Breakglass authority is not enabled.', array( 'ability_name' => $ability_name ) );
			}
			$rows[ $ability_name ] = $row;
		}
		ksort( $rows, SORT_STRING );
		list( $payload, $plan_sha256 ) = self::canonical_plan_payload( $rows, $input );

		$base = class_exists( 'MAD4B_SCP_Servers' ) && method_exists( 'MAD4B_SCP_Servers', 'chatgpt_base_tools' )
			? MAD4B_SCP_Servers::chatgpt_base_tools()
			: array();
		$optional = array();
		$policy_blocked = array();
		foreach ( $rows as $ability_name => $row ) {
			if ( empty( $row['projection_eligible'] ) ) {
				$policy_blocked[ $ability_name ] = isset( $row['projection_blockers'] ) && is_array( $row['projection_blockers'] )
					? array_values( array_unique( array_map( 'strval', $row['projection_blockers'] ) ) )
					: array( 'ability_projection_policy_blocked' );
				continue;
			}
			$optional[] = (string) $ability_name;
		}
		$requested_tools = array_values( array_unique( array_merge( $base, $optional ) ) );
		$base_optional = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools() : array();
		$required_base = array_diff( $base, $base_optional );
		$all_optional = array_values( array_diff( array_unique( array_merge( $base_optional, $optional ) ), $required_base ) );
		$budget = class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' )
			? MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( $requested_tools, $all_optional )
			: array( 'ready' => false, 'blocker' => 'mcp_catalog_budget_unavailable' );
		$mcp_preflight = class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' )
			? MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $requested_tools, $all_optional )
			: array( 'ready' => false, 'blocker' => 'mcp_catalog_preflight_unavailable' );
		$preflight_tools = isset( $mcp_preflight['tools'] ) && is_array( $mcp_preflight['tools'] ) ? $mcp_preflight['tools'] : array();
		$unprojectable = array_values( array_unique( array_merge( array_keys( $policy_blocked ), array_diff( $optional, $preflight_tools ) ) ) );
		sort( $unprojectable, SORT_STRING );
		$ready_for_apply = ! empty( $mcp_preflight['ready'] ) && empty( $unprojectable ) && empty( $policy_blocked );
		return array(
			'contract' => self::CONTRACT,
			'plan_sha256' => $plan_sha256,
			'current_revision' => (int) self::raw_state()['revision'],
			'desired_count' => count( $rows ),
			'desired_abilities' => array_values( $rows ),
			'base_tool_count' => count( $base ),
			'budget' => $budget,
			'mcp_preflight' => $mcp_preflight,
			'projection_policy_blockers' => $policy_blocked,
			'unprojectable_abilities' => $unprojectable,
			'ready_for_apply' => $ready_for_apply,
			'projection_changes_authority' => false,
			'execution_permission_callbacks_preserved' => true,
			'read_only' => true,
			'mutation_performed' => false,
			'payload' => $payload,
		);
	}

	public static function can_apply( $input = null ) {
		if ( class_exists( 'MAD4B_SCP_Unified_Capability_Gateway' ) && ! MAD4B_SCP_Unified_Capability_Gateway::runtime_blog_matches() ) return new WP_Error( 'mad4b_projection_blog_switch_denied', 'Use a fresh request to the target site.' );
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'mad4b_chatgpt_projection_admin_required', 'Administrator capability is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() || 'staging' !== MAD4B_SCP_Site_Profile::current_environment() || ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() ) {
			return new WP_Error( 'mad4b_chatgpt_projection_staging_profile_required', 'Exact enrolled Staging Site Profile is required.' );
		}
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return new WP_Error( 'mad4b_chatgpt_projection_bearer_required', 'Verified OAuth bearer identity is required.' );
		if ( ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( MAD4B_SCP_OAuth_Resource_Bridge::AUTHORITY_STEP_UP_SCOPE ) ) return new WP_Error( 'mad4b_chatgpt_projection_step_up_required', 'Dedicated authority step-up scope is required.' );
		if ( ! class_exists( 'MAD4B_SCP_Local_OAuth_Server' ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_client_is( MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID ) ) return new WP_Error( 'mad4b_chatgpt_projection_chatgpt_client_required', 'Projection mutation requires the exact ChatGPT CIMD client.' );
		return true;
	}

	public static function apply( $input = array() ) {
		$permission = self::can_apply( $input );
		if ( is_wp_error( $permission ) ) return $permission;
		$original_option = get_option( self::OPTION, false );
		$input = is_array( $input ) ? $input : array();
		if ( ! isset( $input['confirmation'] ) || self::CONFIRMATION !== (string) $input['confirmation'] ) return new WP_Error( 'mad4b_chatgpt_projection_confirmation_required', 'Exact projection confirmation is required.' );
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['ready_for_apply'] ) ) {
			$policy_blocked = isset( $plan['projection_policy_blockers'] ) && is_array( $plan['projection_policy_blockers'] ) ? $plan['projection_policy_blockers'] : array();
			return new WP_Error(
				'mad4b_chatgpt_projection_preflight_blocked',
				'Projection cannot be applied because every requested Ability must pass projection policy and survive the exact resulting MCP catalog preflight and budget.',
				array(
					'blocker' => ! empty( $policy_blocked ) ? 'ability_projection_policy_blocked' : ( isset( $plan['mcp_preflight']['blocker'] ) ? (string) $plan['mcp_preflight']['blocker'] : 'unknown' ),
					'projection_policy_blockers' => $policy_blocked,
					'unprojectable_abilities' => isset( $plan['unprojectable_abilities'] ) ? $plan['unprojectable_abilities'] : array(),
				)
			);
		}
		$expected = isset( $input['expected_plan_sha256'] ) ? strtolower( trim( (string) $input['expected_plan_sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( strtolower( (string) $plan['plan_sha256'] ), $expected ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_plan_drift', 'Projection plan changed after review.', array( 'current_plan_sha256' => $plan['plan_sha256'] ) );
		}
		$state = self::raw_state();
		$abilities = array();
		foreach ( $plan['desired_abilities'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['ability_name'] ) ) continue;
			$abilities[ (string) $row['ability_name'] ] = $row;
		}
		ksort( $abilities, SORT_STRING );
		$next = array(
			'contract' => self::CONTRACT,
			'revision' => (int) $state['revision'] + 1,
			'abilities' => $abilities,
			'updated_at' => gmdate( 'c' ),
			'last_plan_sha256' => (string) $plan['plan_sha256'],
			'binding' => $plan['payload']['binding'],
		);
		if ( $plan['payload']['binding'] !== self::current_binding() ) return new WP_Error( 'mad4b_projection_binding_changed', 'Site enrollment changed during plan application.' );
		$persisted = self::persist_compare_and_swap( $original_option, $next );
		if ( is_wp_error( $persisted ) ) return $persisted;
		if ( class_exists( 'MAD4B_SCP_Audit' ) ) {
			MAD4B_SCP_Audit::record( self::APPLY_ABILITY, array(
				'projection_revision' => (int) $next['revision'],
				'projected_ability_count' => count( $abilities ),
				'plan_sha256' => (string) $plan['plan_sha256'],
				'authority_widened' => false,
				'production_mutation' => false,
			) );
		}

		return array_merge( self::status(), array(
			'applied_plan_sha256' => $plan['plan_sha256'],
			'mutation_performed' => true,
			'production_mutation' => false,
			'authority_widened' => false,
			'catalog_refresh_required' => true,
			'catalog_refresh_action' => 'Request tools/list again; refresh the client connection if it caches tool definitions.',
		) );
	}

	/** The database predicate, not a cached readback, fences concurrent plans. */
	private static function persist_compare_and_swap( $original, array $next ) {
		if ( false === $original ) {
			if ( ! add_option( self::OPTION, $next, '', false ) ) return new WP_Error( 'mad4b_projection_concurrent_update', 'Another projection update won; review a new plan.' );
		} else {
			global $wpdb;
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), self::OPTION, maybe_serialize( $original ) ) );
			if ( false === $result ) return new WP_Error( 'mad4b_projection_storage_failed', 'Projection storage failed.' );
			if ( 1 !== $result ) return new WP_Error( 'mad4b_projection_concurrent_update', 'Projection changed during apply; review a new plan.' );
		}
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( get_option( self::OPTION, false ) !== $next ) return new WP_Error( 'mad4b_chatgpt_projection_persistence_failed', 'Projection persistence could not be verified.' );
		return true;
	}

	public static function effective_projection_rows() {
		$rows = array();
		$state = self::raw_state();
		if ( ! self::binding_matches( $state ) ) return $rows;
		$required_base = class_exists( 'MAD4B_SCP_Servers' ) ? array_diff( MAD4B_SCP_Servers::chatgpt_base_tools(), MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools() ) : array();
		foreach ( $state['abilities'] as $ability_name => $stored ) {
			if ( in_array( $ability_name, $required_base, true ) ) continue;
			if ( ! is_array( $stored ) ) continue;
			$current = self::effective_row( $ability_name, $state );
			if ( is_wp_error( $current ) ) continue;
			$rows[ $ability_name ] = $current;
		}
		ksort( $rows, SORT_STRING );
		return $rows;
	}

	public static function projected_ability_names() {
		return array_keys( self::effective_projection_rows() );
	}

	public static function is_projected( $ability_name ) {
		$state = self::raw_state();
		$required_base = array_diff( MAD4B_SCP_Servers::chatgpt_base_tools(), MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools() );
		return ! in_array( (string) $ability_name, $required_base, true ) && self::binding_matches( $state ) && ! is_wp_error( self::effective_row( (string) $ability_name, $state ) );
	}

	public static function status( $input = null ) {
		unset( $input );
		$state = self::raw_state();
		$effective = self::effective_projection_rows();
		$catalog = array_values( array_unique( array_merge( MAD4B_SCP_Servers::chatgpt_base_tools(), array_keys( $effective ) ) ) );
		$preflight = MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $catalog, MAD4B_SCP_MCP_Catalog_Diagnostics::optional_projections( $catalog ) );
		$stored = array();
		foreach ( $state['abilities'] as $ability_name => $row ) {
			$current = self::ability_row( $ability_name );
			$stale = is_wp_error( $current ) || ! is_array( $row ) || empty( $row['input_schema_sha256'] ) || ! hash_equals( strtolower( (string) $row['input_schema_sha256'] ), strtolower( is_wp_error( $current ) ? str_repeat( '0', 64 ) : (string) $current['input_schema_sha256'] ) );
			$stale = $stale || empty( $row['classification_sha256'] ) || ( ! is_wp_error( $current ) && ! hash_equals( (string) $row['classification_sha256'], $current['classification_sha256'] ) );
			$stored[] = array(
				'ability_name' => (string) $ability_name,
				'input_schema_sha256' => is_array( $row ) && isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
				'classification' => ! is_wp_error( $current ) && isset( $current['classification'] ) ? (string) $current['classification'] : 'unavailable',
				'readonly' => is_array( $row ) && isset( $row['readonly'] ) ? (bool) $row['readonly'] : null,
				'breakglass' => is_array( $row ) && ! empty( $row['breakglass'] ),
				'projection_eligible' => ! is_wp_error( $current ) && ! empty( $current['projection_eligible'] ),
				'execution_eligible' => ! is_wp_error( $current ) && ! empty( $current['execution_eligible'] ),
				'projection_blockers' => ! is_wp_error( $current ) && isset( $current['projection_blockers'] ) && is_array( $current['projection_blockers'] ) ? $current['projection_blockers'] : array(),
				'effective' => isset( $effective[ $ability_name ] ),
				'stale' => (bool) $stale,
				'lane' => is_array( $row ) && isset( $row['lane'] ) ? $row['lane'] : '',
				'inactive_reason' => isset( $effective[ $ability_name ] ) ? '' : ( ! self::binding_matches( $state ) ? 'site_binding_mismatch' : ( $stale ? 'ability_identity_drift' : 'base_tool_or_authority_unavailable' ) ),
				'catalog_selected' => in_array( $ability_name, $preflight['tools'] ?? array(), true ),
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'revision' => (int) $state['revision'],
			'stored_count' => count( $state['abilities'] ),
			'effective_count' => count( $effective ),
			'universe_count' => count( self::all_site_ability_names() ),
			'binding_match' => self::binding_matches( $state ),
			'catalog_preflight' => $preflight,
			'budgets' => array(
				'max_selected' => self::MAX_SELECTED,
				'max_tools' => MAD4B_SCP_MCP_Catalog_Diagnostics::MAX_TOOLS,
				'max_serialized_tool_bytes' => MAD4B_SCP_MCP_Catalog_Diagnostics::MAX_SERIALIZED_TOOL_BYTES,
				'schema_size_policy' => 'bounded_direct_projection_with_dispatcher_fallback',
			),
			'catalog_refresh_action' => 'Request tools/list after a projection change; reconnect if the host caches tools.',
			'primary_execution_mode' => 'fixed_dispatch',
			'projection_role' => 'optional_hot_set',
			'server_tools_list_changed' => false,
			'storage' => MAD4B_SCP_Catalog_Object_Store::status(),
			'abilities' => $stored,
			'projection_changes_authority' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}
}
MAD4B_SCP_ChatGPT_Tool_Projection::boot();
