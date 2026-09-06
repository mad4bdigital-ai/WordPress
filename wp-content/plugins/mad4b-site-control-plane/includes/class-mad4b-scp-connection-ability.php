<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only MCP ability for transport/connection truth.
 *
 * It deliberately exposes no credential material and performs no remote probe.
 */
final class MAD4B_SCP_Connection_Ability {
	const ABILITY = 'mad4b/connection-status';

	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 25 );
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::ABILITY ) ) return;
		wp_register_ability(
			self::ABILITY,
			array(
				'label' => 'Connection Status',
				'description' => 'Read-only MAD4B MCP endpoint, transport, authentication-bridge and peer-governance readiness.',
				'category' => 'mad4b-read',
				'execute_callback' => array( __CLASS__, 'execute' ),
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function execute() {
		if ( ! class_exists( 'MAD4B_SCP_Connection_Status' ) ) {
			return new WP_Error( 'mad4b_connection_status_unavailable', 'MAD4B connection readiness service is unavailable.' );
		}
		return MAD4B_SCP_Connection_Status::status();
	}
}
