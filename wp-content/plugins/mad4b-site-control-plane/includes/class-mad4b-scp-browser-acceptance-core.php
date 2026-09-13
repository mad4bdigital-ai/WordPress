<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MAD4B_SCP_Browser_Acceptance_Core {
	const CONTRACT = 'mad4b.browser-acceptance-core.v1';
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
		if ( null !== $evidence && ! is_array( $evidence ) ) $reasons[] = 'evidence_invalid';
		return array(
			'provider_id' => $selector['provider_id'],
			'profile_id' => $selector['profile_id'],
			'plan_digest' => $plan_digest,
			'plan_signature' => $plan_signature,
			'evidence' => is_array( $evidence ) ? $evidence : null,
			'blocking_reasons' => array_values( array_unique( $reasons ) ),
		);
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
			'maxProperties' => 3,
			'additionalProperties' => false,
		);
	}

	private static function result_schema() {
		$bounded_string = array( 'type' => 'string', 'maxLength' => 256 );
		$case_schema = array(
			'type' => 'object',
			'maxProperties' => 10,
			'properties' => array(
				'case_id' => array( 'type' => 'string', 'maxLength' => 128 ),
				'runtime' => array( 'type' => 'object', 'maxProperties' => 4, 'additionalProperties' => true ),
				'events' => array( 'type' => 'object', 'maxProperties' => 6, 'additionalProperties' => array( 'type' => 'boolean' ) ),
				'network' => array( 'type' => 'object', 'maxProperties' => 16, 'additionalProperties' => true ),
				'rendered' => array( 'type' => 'object', 'maxProperties' => 4, 'properties' => array( 'result_count' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1000000 ), 'ids' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ) ), 'additionalProperties' => true ),
				'url_state' => array( 'type' => 'object', 'maxProperties' => 8, 'additionalProperties' => true ),
				'seo' => array( 'type' => 'object', 'maxProperties' => 8, 'additionalProperties' => true ),
				'reset' => array( 'type' => 'object', 'maxProperties' => 6, 'additionalProperties' => true ),
			),
			'required' => array( 'case_id' ),
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
					'maxProperties' => 8,
					'properties' => array(
						'contract' => array( 'type' => 'string', 'maxLength' => 160 ),
						'plan_digest' => array( 'type' => 'string', 'maxLength' => 64 ),
						'plan_signature' => array( 'type' => 'string', 'maxLength' => 64 ),
						'origin' => array( 'type' => 'string', 'maxLength' => 2048 ),
						'build_identity' => array( 'type' => 'object', 'maxProperties' => 4, 'properties' => array( 'git_sha' => array( 'type' => 'string', 'maxLength' => 64 ), 'tree_sha' => array( 'type' => 'string', 'maxLength' => 64 ) ), 'additionalProperties' => false ),
						'observer' => array( 'type' => 'object', 'maxProperties' => 6, 'properties' => array( 'contract' => array( 'type' => 'string', 'maxLength' => 160 ), 'javascript_runtime' => array( 'type' => 'boolean' ), 'browser_engine' => array( 'type' => 'string', 'maxLength' => 80 ) ), 'additionalProperties' => false ),
						'cases' => array( 'type' => 'array', 'maxItems' => 32, 'items' => $case_schema ),
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
