<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-mad4b-scp-browser-acceptance-provider-registry.php';
require_once __DIR__ . '/class-mad4b-scp-browser-acceptance-core.php';

final class MAD4B_SCP_Acceptance_Core {
	const CONTRACT = 'mad4b.acceptance-core.v1';
	private static $booted = false;
	private static $registry;
	private static $planner;
	private static $reducer;
	private static $runner;

	public static function boot_early() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 39 );
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_read_adapter' ), 21 );
		MAD4B_SCP_Browser_Acceptance_Core::boot_early();
	}

	public static function register_read_adapter( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) || ! class_exists( 'MAD4B_SCP_Adapter_Base' ) ) return;
		$adapter = new class extends MAD4B_SCP_Adapter_Base {
			public function id() { return 'acceptance-core'; }
			public function label() { return 'Acceptance Core'; }
			public function is_available() { return true; }
			public function ability_names() {
				return array(
					'read' => array( 'mad4b/acceptance-capabilities', 'mad4b/acceptance-plan', 'mad4b/acceptance-run', 'mad4b/acceptance-result' ),
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
		self::add_read_ability( 'mad4b/acceptance-capabilities', 'Get Acceptance Capabilities', array( __CLASS__, 'capabilities' ), array() );
		$selector = array(
			'type' => 'object',
			'properties' => array(
				'provider_id' => array( 'type' => 'string', 'maxLength' => 64 ),
				'profile_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ),
				'suite' => array( 'type' => 'string', 'enum' => array( 'semantic', 'full_semantic' ) ),
			),
			'required' => array( 'profile_id' ),
			'maxProperties' => 8,
			'additionalProperties' => array( 'type' => 'string', 'maxLength' => 256 ),
		);
		self::add_read_ability( 'mad4b/acceptance-plan', 'Plan Governed Acceptance', array( __CLASS__, 'plan' ), $selector );
		self::add_read_ability( 'mad4b/acceptance-run', 'Run Governed Acceptance', array( __CLASS__, 'run' ), $selector );
		self::add_read_ability( 'mad4b/acceptance-result', 'Get Canonical Acceptance Result', array( __CLASS__, 'result' ), $selector );
	}

	private static function add_read_ability( $name, $label, $callback, array $input_schema ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $label . ' through the read-only MAD4B Acceptance Core. Providers remain non-authorizing and profile-governed.',
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
			'contract' => 'mad4b.acceptance-capabilities.v1',
			'core_contract' => self::CONTRACT,
			'authorizing' => false,
			'read_only' => true,
			'transport_authority' => false,
			'provider_count' => count( $providers ),
			'providers' => $providers,
			'registry' => $inventory,
		);
	}

	public static function plan( $input = array() ) { return self::planner()->plan( $input ); }
	public static function run( $input = array() ) { return self::runner()->run( $input ); }
	public static function result( $input = array() ) {
		$run = self::runner()->run( $input );
		$result = isset( $run['result'] ) && is_array( $run['result'] ) ? $run['result'] : array();
		$result['provider_id'] = isset( $run['provider_id'] ) ? $run['provider_id'] : '';
		$result['provider_contract'] = isset( $run['provider_contract'] ) ? $run['provider_contract'] : '';
		$result['profile_id'] = isset( $run['profile_id'] ) ? $run['profile_id'] : '';
		$result['suite'] = isset( $run['suite'] ) ? $run['suite'] : 'semantic';
		$result['plan_digest'] = isset( $run['plan_digest'] ) ? $run['plan_digest'] : '';
		return $result;
	}

	private static function registry() { if ( ! self::$registry ) self::$registry = new MAD4B_SCP_Acceptance_Provider_Registry(); return self::$registry; }
	private static function planner() { if ( ! self::$planner ) self::$planner = new MAD4B_SCP_Acceptance_Planner( self::registry() ); return self::$planner; }
	private static function reducer() { if ( ! self::$reducer ) self::$reducer = new MAD4B_SCP_Acceptance_Verdict_Reducer(); return self::$reducer; }
	private static function runner() { if ( ! self::$runner ) self::$runner = new MAD4B_SCP_Acceptance_Runner( self::registry(), self::planner(), self::reducer() ); return self::$runner; }
}
