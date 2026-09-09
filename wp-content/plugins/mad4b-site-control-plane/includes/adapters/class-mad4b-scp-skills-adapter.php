<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Ownership adapter for Skill-registry read abilities.
 *
 * The abilities themselves are registered by MAD4B_SCP_Skill_Abilities. This
 * adapter exists so MCP surface composition and provider_for_ability() can
 * attribute them to a governed owner instead of treating them as anonymous
 * extras. It carries no write surface and no provider certification dependency.
 */
final class MAD4B_SCP_Skills_Adapter extends MAD4B_SCP_Adapter_Base {
	private static $hooked = false;

	public static function boot() {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'mad4b_scp_register_adapters', array( __CLASS__, 'register_with_registry' ) );
	}

	public static function register_with_registry( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) $registry->register( new self() );
	}

	public function id() { return 'skills'; }
	public function label() { return 'Skills'; }
	public function is_available() { return class_exists( 'MAD4B_SCP_Skill_Registry' ); }

	public function ability_names() {
		return array(
			'read' => array(
				'mad4b/skills-list',
				'mad4b/skill-get',
				'mad4b/skills-export-status',
			),
			'content' => array(),
			'admin' => array(),
		);
	}

	public function register_abilities() {
		// Registered centrally by MAD4B_SCP_Skill_Abilities at priority 30.
		// Keeping registration single-owned prevents duplicate ability definitions.
	}

	protected function provider_certification( $available ) { return null; }
	protected function mutation_requires_certification() { return false; }
}
