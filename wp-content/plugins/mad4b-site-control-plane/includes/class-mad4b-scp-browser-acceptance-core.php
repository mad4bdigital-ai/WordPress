<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Browser_Acceptance_Core {
	const CONTRACT = 'mad4b.browser-acceptance-core.v1';
	const MAX_EVIDENCE_BYTES = 131072;
	const MAX_EVIDENCE_DEPTH = 8;
	const MAX_EVIDENCE_NODES = 1024;
	const MAX_CASES = 8;
	private static $booted = false;
	private static $registry;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 40 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_read_adapter' ), 22 );
	}

	public static function register_read_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base' ) ) return;
		$adapter = new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'browser-acceptance-core'; }
			public function label() { return 'Browser Acceptance Core'; }
			public function is_available() { return true; }
			public function ability_names() {
				return array(
					'read' => array( 'mad4b/browser-acceptance-capabilities', 'mad4b/browser-acceptance-plan', 'mad4b/browser-acceptance-result' ),
					'content' => array(),
					'admin' => array(),
				);
			}
			public function register_abilities() {}
			protected function mutation_requires_certification() { return false; }
			protected function provider_certification( $available ) { return null; }
		};
		$registry->register( $adapter );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::add_read_ability( 'mad4b/browser-acceptance-capabilities', 'Get Browser Acceptance Capabilities', array( __CLASS__, 'capabilities' ), array() );
		self::add_read_ability( 'mad4b/browser-acceptance-plan', 'Plan Governed Browser Acceptance', array( __CLASS__, 'plan' ), self::selector_schema() );
		self::add_read_ability( 'mad4b/browser-acceptance-result', 'Reduce Governed Browser Evidence', array( __CLASS__, 'result' ), self::result_schema() );
	}

	private static function add_read_ability( $name, $label, $callback, array $input_schema ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $label . ' through the read-only MAD4B Browser Acceptance Core. Browser execution remains external; providers remain non-authorizing.',
			'category' => 'mad4b-read',
			'execute_callback' => $callback,
			'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
			'input_schema' => $input_schema,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function capabilities() {
		$inventory = self::registry()->inventory();
		$providers = array();
		foreach ( self::registry()->all() as $provider_id => $provider ) {
			$capabilities = array();
			try { $capabilities = call_user_func( $provider['capabilities_callback'] ); }
			catch ( Throwable $error ) { $capabilities = array( 'error' => 'provider_capabilities_exception' ); }
			$providers[] = array(
				'provider_id' => $provider_id,
				'contract' => $provider['contract'],
				'descriptor' => $provider['descriptor'],
				'capabilities' => is_array( $capabilities ) ? $capabilities : array( 'error' => 'provider_capabilities_invalid' ),
			);
		}
		return array(
			'contract' => 'mad4b.browser-acceptance-capabilities.v1',
			'core_contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'transport_authority' => false,
			'browser_engine_authority' => false,
			'execution_mode' => 'external_browser_agent',
			'provider_count' => count( $providers ),
			'providers' => $providers,
			'registry' => $inventory,
		);
	}

	public static function plan( $input = array() ) {
		$validated = self::validate_selector( $input );
		if ( ! empty( $validated['blocking_reasons'] ) ) return self::blocked_plan( $validated['provider_id'], $validated['profile_id'], $validated['blocking_reasons'] );
		$provider = self::resolve_provider( $validated['provider_id'] );
		if ( ! $provider ) return self::blocked_plan( $validated['provider_id'], $validated['profile_id'], array( 'provider_unavailable' ) );
		try { $plan = call_user_func( $provider['plan_callback'], array( 'profile_id' => $validated['profile_id'], 'suite' => 'browser_runtime' ) ); }
		catch ( Throwable $error ) { return self::blocked_plan( $validated['provider_id'], $validated['profile_id'], array( 'provider_plan_exception' ) ); }
		if ( ! is_array( $plan ) ) return self::blocked_plan( $validated['provider_id'], $validated['profile_id'], array( 'provider_plan_invalid' ) );
		$plan['provider_id'] = $provider['provider_id'];
		$plan['provider_contract'] = $provider['contract'];
		$plan['profile_id'] = $validated['profile_id'];
		$plan['suite'] = 'browser_runtime';
		$plan['authorizing'] = false;
		$plan['read_only'] = true;
		return $plan;
	}

	public static function result( $input = array() ) {
		$validated = self::validate_result_input( $input );
		if ( ! empty( $validated['blocking_reasons'] ) ) return self::blocked_result( $validated['provider_id'], $validated['profile_id'], $validated['blocking_reasons'] );
		$provider = self::resolve_provider( $validated['provider_id'] );
		if ( ! $provider ) return self::blocked_result( $validated['provider_id'], $validated['profile_id'], array( 'provider_unavailable' ) );
		$request = array(
			'profile_id' => $validated['profile_id'],
			'suite' => 'browser_runtime',
			'plan_digest' => $validated['plan_digest'],
			'plan_signature' => $validated['plan_signature'],
		);
		if ( null !== $validated['evidence'] ) $request['evidence'] = $validated['evidence'];
		try { $result = call_user_func( $provider['result_callback'], $request ); }
		catch ( Throwable $error ) { return self::blocked_result( $provider['provider_id'], $validated['profile_id'], array( 'provider_result_exception' ) ); }
		if ( ! is_array( $result ) ) return self::blocked_result( $provider['provider_id'], $validated['profile_id'], array( 'provider_result_invalid' ) );
		$result['provider_id'] = $provider['provider_id'];
		$result['provider_contract'] = $provider['contract'];
		$result['profile_id'] = $validated['profile_id'];
		$result['suite'] = 'browser_runtime';
		$result['authorizing'] = false;
		$result['read_only'] = true;
		return $result;
	}

	private static function validate_selector( $input ) {
		$input = is_array( $input ) ? $input : array();
		$reasons = array();
		$unknown = array_diff( array_keys( $input ), array( 'provider_id', 'profile_id', 'suite' ) );
		if ( $unknown ) $reasons[] = 'unsupported_request_fields';
		$provider_id = self::clean_id( isset( $input['provider_id'] ) ? $input['provider_id'] : '' );
		if ( isset( $input['provider_id'] ) && '' === $provider_id ) $reasons[] = 'provider_id_invalid';
		$profile_id = self::clean_id( isset( $input['profile_id'] ) ? $input['profile_id'] : '' );
		if ( '' === $profile_id ) $reasons[] = 'profile_id_required';
		$suite = self::clean_id( isset( $input['suite'] ) ? $input['suite'] : 'browser_runtime' );
		if ( ! in_array( $suite, array( 'browser', 'browser_runtime' ), true ) ) $reasons[] = 'unsupported_suite';
		if ( '' === $provider_id && empty( $reasons ) ) {
			$providers = self::registry()->all();
			if ( 1 === count( $providers ) ) $provider_id = (string) array_key_first( $providers );
			elseif ( count( $providers ) > 1 ) $reasons[] = 'provider_id_required';
			else $reasons[] = 'provider_unavailable';
		}
		return array( 'provider_id' => $provider_id, 'profile_id' => $profile_id, 'blocking_reasons' => array_values( array_unique( $reasons ) ) );
	}

	private static function validate_result_input( $input ) {
		$input = is_array( $input ) ? $input : array();
		$selector = self::validate_selector( array_intersect_key( $input, array_flip( array( 'provider_id', 'profile_id', 'suite' ) ) ) );
		$reasons = $selector['blocking_reasons'];
		$unknown = array_diff( array_keys( $input ), array( 'provider_id', 'profile_id', 'suite', 'plan_digest', 'plan_signature', 'evidence' ) );
		if ( $unknown ) $reasons[] = 'unsupported_request_fields';
		$plan_digest = isset( $input['plan_digest'] ) && is_scalar( $input['plan_digest'] ) ? strtolower( trim( (string) $input['plan_digest'] ) ) : '';
		$plan_signature = isset( $input['plan_signature'] ) && is_scalar( $input['plan_signature'] ) ? strtolower( trim( (string) $input['plan_signature'] ) ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $plan_digest ) ) $reasons[] = 'plan_digest_invalid';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $plan_signature ) ) $reasons[] = 'plan_signature_invalid';
		$evidence = array_key_exists( 'evidence', $input ) ? $input['evidence'] : null;
		if ( null !== $evidence && ! is_array( $evidence ) ) {
			$reasons[] = 'evidence_invalid';
		} elseif ( is_array( $evidence ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $evidence ) : json_encode( $evidence );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_EVIDENCE_BYTES ) $reasons[] = 'evidence_size_limit_exceeded';
			$shape = self::evidence_shape( $evidence );
			if ( $shape['max_depth'] > self::MAX_EVIDENCE_DEPTH ) $reasons[] = 'evidence_depth_limit_exceeded';
			if ( $shape['nodes'] > self::MAX_EVIDENCE_NODES ) $reasons[] = 'evidence_node_limit_exceeded';
		}
		return array(
			'provider_id' => $selector['provider_id'],
			'profile_id' => $selector['profile_id'],
			'plan_digest' => $plan_digest,
			'plan_signature' => $plan_signature,
			'evidence' => is_array( $evidence ) ? $evidence : null,
			'blocking_reasons' => array_values( array_unique( $reasons ) ),
		);
	}

	private static function evidence_shape( array $evidence ) {
		$nodes = 1;
		$max_depth = 1;
		$stack = array( array( $evidence, 1 ) );
		while ( $stack ) {
			$current = array_pop( $stack );
			$value = $current[0];
			$depth = (int) $current[1];
			if ( $depth > $max_depth ) $max_depth = $depth;
			foreach ( $value as $item ) {
				$nodes++;
				if ( $nodes > self::MAX_EVIDENCE_NODES || $max_depth > self::MAX_EVIDENCE_DEPTH ) {
					return array( 'nodes' => $nodes, 'max_depth' => $max_depth );
				}
				if ( is_array( $item ) ) {
					$next_depth = $depth + 1;
					if ( $next_depth > $max_depth ) $max_depth = $next_depth;
					$stack[] = array( $item, $next_depth );
				}
			}
		}
		return array( 'nodes' => $nodes, 'max_depth' => $max_depth );
	}

	private static function resolve_provider( $provider_id ) {
		return self::registry()->resolve( $provider_id );
	}

	private static function blocked_plan( $provider_id, $profile_id, array $reasons ) {
		return array(
			'contract' => 'mad4b.browser-acceptance-plan.v1',
			'core_contract' => self::CONTRACT,
			'provider_id' => (string) $provider_id,
			'profile_id' => (string) $profile_id,
			'suite' => 'browser_runtime',
			'state' => 'blocked',
			'authorizing' => false,
			'read_only' => true,
			'blocking_reasons' => array_values( array_unique( array_filter( $reasons ) ) ),
		);
	}

	private static function blocked_result( $provider_id, $profile_id, array $reasons ) {
		return array(
			'contract' => 'mad4b.browser-acceptance-result.v1',
			'core_contract' => self::CONTRACT,
			'provider_id' => (string) $provider_id,
			'profile_id' => (string) $profile_id,
			'suite' => 'browser_runtime',
			'authorizing' => false,
			'read_only' => true,
			'verification' => array( 'browser_runtime_parity_verified' => false ),
			'blocking_reasons' => array_values( array_unique( array_filter( $reasons ) ) ),
			'infrastructure_failures' => array(),
			'defect_reasons' => array(),
			'incomplete_evidence' => array(),
			'classification' => 'ENVIRONMENT_OR_PROVIDER_BLOCK',
			'verdict' => 'BLOCKED',
		);
	}

	private static function selector_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				'profile_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'suite' => array( 'type' => 'string', 'enum' => array( 'browser', 'browser_runtime' ) ),
			),
			'required' => array( 'profile_id' ),
			'maxProperties' => 8,
			'additionalProperties' => array( 'type' => 'string', 'maxLength' => 256 ),
		);
	}

	private static function result_schema() {
		$string80 = array( 'type' => 'string', 'maxLength' => 80 );
		$string160 = array( 'type' => 'string', 'maxLength' => 160 );
		$string2048 = array( 'type' => 'string', 'maxLength' => 2048 );
		$blocked_event_schema = array(
			'type' => 'object',
			'maxProperties' => 10,
			'properties' => array(
				'reason' => $string160,
				'group' => $string160,
				'jsf_version' => $string80,
				'supported_jsf_versions' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $string80 ),
				'blocking_reasons' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $string160 ),
				'groups' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $string160 ),
				'path_group' => $string160,
				'retry_attempts' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
				'http_status' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 599 ),
				'timeout_ms' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 120000 ),
			),
			'additionalProperties' => false,
		);
		$history_call_schema = array(
			'type' => 'object',
			'maxProperties' => 2,
			'properties' => array(
				'method' => array( 'type' => 'string', 'enum' => array( 'pushState', 'replaceState' ) ),
				'etg_source' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
		$case_schema = array(
			'type' => 'object',
			'maxProperties' => 14,
			'properties' => array(
				'contract' => $string160,
				'case_id' => array( 'type' => 'string', 'maxLength' => 128 ),
				'challenge_nonce' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[A-Fa-f0-9]{32}$' ),
				'passive' => array( 'type' => 'boolean' ),
				'authorizing' => array( 'type' => 'boolean' ),
				'runtime' => array(
					'type' => 'object', 'maxProperties' => 3,
					'properties' => array(
						'javascript_runtime' => array( 'type' => 'boolean' ),
						'jet_smart_filters_observed' => array( 'type' => 'boolean' ),
						'filter_group' => $string160,
					),
					'additionalProperties' => false,
				),
				'events' => array(
					'type' => 'object', 'maxProperties' => 3,
					'properties' => array(
						'ajax_filters_updated' => array( 'type' => 'boolean' ),
						'presentation_updated' => array( 'type' => 'boolean' ),
						'presentation_reset' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'network' => array(
					'type' => 'object', 'maxProperties' => 10,
					'properties' => array(
						'method' => array( 'type' => 'string', 'maxLength' => 16 ),
						'endpoint' => $string2048,
						'http_status' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 599 ),
						'contract' => $string160,
						'status' => $string80,
						'authorizing' => array( 'type' => 'boolean' ),
						'url_authority' => array( 'type' => 'boolean' ),
						'seo_mutation' => array( 'type' => 'boolean' ),
						'provider' => $string80,
						'query_id' => array( 'type' => 'string', 'maxLength' => 128 ),
					),
					'additionalProperties' => false,
				),
				'rendered' => array(
					'type' => 'object', 'maxProperties' => 2,
					'properties' => array(
						'result_count' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000 ),
						'ids' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					),
					'additionalProperties' => false,
				),
				'url_state' => array(
					'type' => 'object', 'maxProperties' => 4,
					'properties' => array(
						'filter_state_observed' => array( 'type' => 'boolean' ),
						'etg_history_mutation' => array( 'type' => 'boolean' ),
						'filtered_url' => $string2048,
						'reset_url' => $string2048,
					),
					'additionalProperties' => false,
				),
				'seo' => array(
					'type' => 'object', 'maxProperties' => 4,
					'properties' => array(
						'canonical_unchanged' => array( 'type' => 'boolean' ),
						'robots_unchanged' => array( 'type' => 'boolean' ),
						'hreflang_unchanged' => array( 'type' => 'boolean' ),
						'rank_math_unchanged' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'reset' => array(
					'type' => 'object', 'maxProperties' => 2,
					'properties' => array(
						'event_observed' => array( 'type' => 'boolean' ),
						'neutral_state_restored' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'blocked_events' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $blocked_event_schema ),
				'history_calls' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $history_call_schema ),
			),
			'required' => array( 'case_id' ),
			'additionalProperties' => false,
		);
		$challenge_schema = array(
			'type' => 'object',
			'maxProperties' => 5,
			'properties' => array(
				'contract' => $string160,
				'nonce' => array( 'type' => 'string', 'minLength' => 32, 'maxLength' => 32, 'pattern' => '^[A-Fa-f0-9]{32}$' ),
				'issued_at' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expires_at' => array( 'type' => 'integer', 'minimum' => 1 ),
				'signature' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64, 'pattern' => '^[A-Fa-f0-9]{64}$' ),
			),
			'additionalProperties' => false,
		);
		return array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				'profile_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'suite' => array( 'type' => 'string', 'enum' => array( 'browser', 'browser_runtime' ) ),
				'plan_digest' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'plan_signature' => array( 'type' => 'string', 'minLength' => 64, 'maxLength' => 64 ),
				'evidence' => array(
					'type' => 'object',
					'maxProperties' => 9,
					'properties' => array(
						'contract' => $string160,
						'plan_digest' => array( 'type' => 'string', 'maxLength' => 64 ),
						'plan_signature' => array( 'type' => 'string', 'maxLength' => 64 ),
						'origin' => $string2048,
						'build_identity' => array( 'type' => 'object', 'maxProperties' => 2, 'properties' => array( 'git_sha' => array( 'type' => 'string', 'maxLength' => 64 ), 'tree_sha' => array( 'type' => 'string', 'maxLength' => 64 ) ), 'additionalProperties' => false ),
						'observer' => array( 'type' => 'object', 'maxProperties' => 3, 'properties' => array( 'contract' => $string160, 'javascript_runtime' => array( 'type' => 'boolean' ), 'browser_engine' => $string80 ), 'additionalProperties' => false ),
						'challenge' => $challenge_schema,
						'cases' => array( 'type' => 'array', 'maxItems' => self::MAX_CASES, 'items' => $case_schema ),
					),
					'additionalProperties' => false,
				),
			),
			'required' => array( 'profile_id', 'plan_digest', 'plan_signature' ),
			'maxProperties' => 6,
			'additionalProperties' => false,
		);
	}

	private static function registry() {
		if ( ! self::$registry ) self::$registry = new MAD4B_SCP_Browser_Acceptance_Provider_Registry();
		return self::$registry;
	}

	private static function clean_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z0-9][a-z0-9._\-]{0,63}$/', $value ) ? $value : '';
	}
}
