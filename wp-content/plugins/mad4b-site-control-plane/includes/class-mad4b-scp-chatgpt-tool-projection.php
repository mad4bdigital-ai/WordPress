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
						'query' => array( 'type' => 'string', 'default' => '', 'maxLength' => 160 ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
						'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5000, 'default' => 0 ),
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
				'idempotent' => true,
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
		return $state;
	}

	private static function schema_sha256( $ability ) {
		$schema = is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) $json = 'null';
		return hash( 'sha256', $json );
	}

	private static function ability_row( $ability_name ) {
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
		if ( ! array_key_exists( 'readonly', $annotations ) || ! is_bool( $annotations['readonly'] ) ) {
			return new WP_Error( 'mad4b_chatgpt_projection_readonly_classification_required', 'Requested Ability must explicitly declare annotations.readonly.', array( 'ability_name' => $ability_name ) );
		}
		$mcp = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();
		$lane = isset( $mcp['surface'] ) ? (string) $mcp['surface'] : ( true === $annotations['readonly'] ? 'read' : 'write' );
		$classification_json = wp_json_encode( array( 'meta' => $meta, 'category' => method_exists( $ability, 'get_category' ) ? $ability->get_category() : '', 'output_schema' => method_exists( $ability, 'get_output_schema' ) ? $ability->get_output_schema() : null ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $classification_json ) ) return new WP_Error( 'mad4b_chatgpt_projection_classification_invalid', 'Projection classification cannot be serialized.' );
		$breakglass = in_array( $lane, array( 'breakglass', 'developer-breakglass' ), true ) || in_array( $ability_name, array_merge(
			class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-breakglass' ) : array(),
			class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::core_tools( 'mad4b-developer-breakglass' ) : array()
		), true );
		return array(
			'ability_name' => $ability_name,
			'input_schema_sha256' => self::schema_sha256( $ability ),
			'classification_sha256' => hash( 'sha256', $classification_json ),
			'lane' => $lane,
			'readonly' => true === $annotations['readonly'],
			'breakglass' => (bool) $breakglass,
			'category' => method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '',
		);
	}

	public static function all_site_ability_names() {
		$names = array();
		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $name => $ability ) {
			unset( $ability );
			$name = (string) $name;
			if ( '' !== $name ) $names[] = $name;
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	public static function discover( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$query = isset( $input['query'] ) ? strtolower( trim( (string) $input['query'] ) ) : '';
		$limit = isset( $input['limit'] ) ? max( 1, min( 100, absint( $input['limit'] ) ) ) : 50;
		$offset = isset( $input['offset'] ) ? max( 0, absint( $input['offset'] ) ) : 0;
		$projected = array_fill_keys( self::projected_ability_names(), true );
		$items = array();
		$matched = 0;
		foreach ( self::all_site_ability_names() as $ability_name ) {
			$row = self::ability_row( $ability_name );
			if ( is_wp_error( $row ) ) continue;
			$ability = wp_get_ability( $ability_name );
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$haystack = strtolower( $ability_name . ' ' . $label . ' ' . $description . ' ' . $row['category'] );
			if ( '' !== $query && false === strpos( $haystack, $query ) ) continue;
			if ( $matched++ < $offset ) continue;
			$items[] = array_merge( $row, array(
				'label' => $label,
				'description' => $description,
				'currently_projected' => isset( $projected[ $ability_name ] ),
			) );
			if ( count( $items ) >= $limit ) break;
		}
		return array(
			'contract' => self::CONTRACT,
			'query' => $query,
			'offset' => $offset,
			'limit' => $limit,
			'items' => $items,
			'count' => count( $items ),
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
		$optional = array_keys( $rows );
		$requested_tools = array_values( array_unique( array_merge( $base, $optional ) ) );
		$base_optional = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::chatgpt_reviewed_direct_step_up_tools() : array();
		$all_optional = array_values( array_unique( array_merge( $base_optional, $optional ) ) );
		$budget = class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' )
			? MAD4B_SCP_MCP_Catalog_Diagnostics::budget_projection( $requested_tools, $all_optional )
			: array( 'ready' => false, 'blocker' => 'mcp_catalog_budget_unavailable' );
		$mcp_preflight = class_exists( 'MAD4B_SCP_MCP_Catalog_Diagnostics' )
			? MAD4B_SCP_MCP_Catalog_Diagnostics::preflight( $requested_tools, $all_optional )
			: array( 'ready' => false, 'blocker' => 'mcp_catalog_preflight_unavailable' );
		$preflight_tools = isset( $mcp_preflight['tools'] ) && is_array( $mcp_preflight['tools'] ) ? $mcp_preflight['tools'] : array();
		$unprojectable = array_values( array_diff( $optional, $preflight_tools ) );
		$ready_for_apply = ! empty( $mcp_preflight['ready'] ) && empty( $unprojectable );
		return array(
			'contract' => self::CONTRACT,
			'plan_sha256' => $plan_sha256,
			'current_revision' => (int) self::raw_state()['revision'],
			'desired_count' => count( $rows ),
			'desired_abilities' => array_values( $rows ),
			'base_tool_count' => count( $base ),
			'budget' => $budget,
			'mcp_preflight' => $mcp_preflight,
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
		$input = is_array( $input ) ? $input : array();
		if ( ! isset( $input['confirmation'] ) || self::CONFIRMATION !== (string) $input['confirmation'] ) return new WP_Error( 'mad4b_chatgpt_projection_confirmation_required', 'Exact projection confirmation is required.' );
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) return $plan;
		if ( empty( $plan['ready_for_apply'] ) ) {
			return new WP_Error(
				'mad4b_chatgpt_projection_preflight_blocked',
				'Projection cannot be applied because every requested Ability must survive the exact resulting MCP catalog preflight and budget.',
				array(
					'blocker' => isset( $plan['mcp_preflight']['blocker'] ) ? (string) $plan['mcp_preflight']['blocker'] : 'unknown',
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
		);
		if ( false === get_option( self::OPTION, false ) ) add_option( self::OPTION, $next, '', false );
		else update_option( self::OPTION, $next, false );
		if ( self::raw_state() !== $next ) return new WP_Error( 'mad4b_chatgpt_projection_persistence_failed', 'Projection persistence could not be verified.' );
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
		) );
	}

	public static function effective_projection_rows() {
		$rows = array();
		foreach ( self::raw_state()['abilities'] as $ability_name => $stored ) {
			if ( ! is_array( $stored ) ) continue;
			$current = self::ability_row( $ability_name );
			if ( is_wp_error( $current ) ) continue;
			$expected = isset( $stored['input_schema_sha256'] ) ? strtolower( (string) $stored['input_schema_sha256'] ) : '';
			if ( '' === $expected || ! hash_equals( $expected, strtolower( (string) $current['input_schema_sha256'] ) ) ) continue;
			if ( empty( $stored['classification_sha256'] ) || ! hash_equals( (string) $stored['classification_sha256'], $current['classification_sha256'] ) ) continue;
			if ( ! empty( $current['breakglass'] ) && ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::can_breakglass() ) ) continue;
			$rows[ $ability_name ] = $current;
		}
		ksort( $rows, SORT_STRING );
		return $rows;
	}

	public static function projected_ability_names() {
		return array_keys( self::effective_projection_rows() );
	}

	public static function is_projected( $ability_name ) {
		return isset( self::effective_projection_rows()[ (string) $ability_name ] );
	}

	public static function status( $input = null ) {
		unset( $input );
		$state = self::raw_state();
		$effective = self::effective_projection_rows();
		$stored = array();
		foreach ( $state['abilities'] as $ability_name => $row ) {
			$current = self::ability_row( $ability_name );
			$stale = is_wp_error( $current ) || ! is_array( $row ) || empty( $row['input_schema_sha256'] ) || ! hash_equals( strtolower( (string) $row['input_schema_sha256'] ), strtolower( is_wp_error( $current ) ? str_repeat( '0', 64 ) : (string) $current['input_schema_sha256'] ) );
			$stale = $stale || empty( $row['classification_sha256'] ) || ( ! is_wp_error( $current ) && ! hash_equals( (string) $row['classification_sha256'], $current['classification_sha256'] ) );
			$stored[] = array(
				'ability_name' => (string) $ability_name,
				'input_schema_sha256' => is_array( $row ) && isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
				'readonly' => is_array( $row ) && isset( $row['readonly'] ) ? (bool) $row['readonly'] : null,
				'breakglass' => is_array( $row ) && ! empty( $row['breakglass'] ),
				'effective' => isset( $effective[ $ability_name ] ),
				'stale' => (bool) $stale,
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'revision' => (int) $state['revision'],
			'stored_count' => count( $state['abilities'] ),
			'effective_count' => count( $effective ),
			'universe_count' => count( self::all_site_ability_names() ),
			'abilities' => $stored,
			'projection_changes_authority' => false,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}
}
MAD4B_SCP_ChatGPT_Tool_Projection::boot();
