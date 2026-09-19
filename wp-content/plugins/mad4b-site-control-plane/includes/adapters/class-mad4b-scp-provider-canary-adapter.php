<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Internal Control Plane adapter used only to project the governed canary wrapper
 * through the canonical per-ability MCP compiler. It represents no external
 * provider runtime and therefore never participates in provider certification.
 */
final class MAD4B_SCP_Provider_Canary_Adapter extends MAD4B_SCP_Adapter_Base {
	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_action' ) ) return;
		self::$booted = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ), 5 );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'provider-canary'; }
	public function label() { return 'Provider Canary'; }
	public function is_available() { return class_exists( 'MAD4B_SCP_Provider_Canary_Execution' ); }
	public function ability_names() {
		return array(
			'read' => array(),
			'content' => array(),
			'admin' => array(),
			// Write-only is intentionally distinct from admin. The canonical write
			// compiler consumes this surface, while mad4b-admin does not.
			'write' => array( MAD4B_SCP_Provider_Canary_Execution::ABILITY ),
		);
	}
	public function register_abilities() { MAD4B_SCP_Provider_Canary_Execution::register_ability(); }
	protected function certified_provider_key() { return 'core'; }
	protected function provider_certification( $available ) { return null; }
	protected function mutation_requires_certification() { return false; }
}

// Behavioral recertification is a separate bounded-write lifecycle. It is
// loaded here because this internal adapter file is already loaded by the base
// adapter after MAD4B_SCP_Adapter_Base exists, avoiding a second bootstrap path.
require_once dirname( __DIR__ ) . '/class-mad4b-scp-provider-behavioral-recertification.php';
MAD4B_SCP_Provider_Behavioral_Recertification::boot_early();
MAD4B_SCP_Provider_Behavioral_Recertification_Adapter::boot();
