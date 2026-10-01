<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, site-neutral connection diagnostics and differential snapshots. */
final class MAD4B_SCP_Connection_Doctor {
	const CONTRACT = 'mad4b.connection-doctor.v1';
	const STATUS_ABILITY = 'mad4b/connection-doctor';
	const DIFF_ABILITY = 'mad4b/connection-differential';
	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 13 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;
		self::register_read(
			self::STATUS_ABILITY,
			'Connection Doctor',
			'Inspect the canonical site/OAuth/MCP connection contract without mutation or outbound probing.',
			'snapshot',
			array( 'type' => 'object', 'additionalProperties' => false )
		);
		self::register_read(
			self::DIFF_ABILITY,
			'Connection Differential',
			'Compare this site canonical connection projection with another non-secret Connection Doctor snapshot.',
			'differential',
			array(
				'type' => 'object',
				'properties' => array(
					'reference' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				'required' => array( 'reference' ),
				'additionalProperties' => false,
			)
		);
	}

	private static function register_read( $name, $label, $description, $method, array $schema ) {
		wp_register_ability( $name, array(
			'label' => $label,
			'description' => $description,
			'category' => 'mad4b-read',
			'execute_callback' => array( __CLASS__, $method ),
			'permission_callback' => class_exists( 'MAD4B_SCP_Policy' ) ? array( 'MAD4B_SCP_Policy', 'can_read' ) : '__return_false',
			'input_schema' => $schema,
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'public' => false,
				'show_in_rest' => false,
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	public static function snapshot( $input = array() ) {
		unset( $input );
		$connection = class_exists( 'MAD4B_SCP_Connection_Identity_Resolver' )
			? MAD4B_SCP_Connection_Identity_Resolver::resolve()
			: array();
		$registration = class_exists( 'MAD4B_SCP_MCP_Registration_Bridge' )
			? MAD4B_SCP_MCP_Registration_Bridge::status()
			: array();
		$servers = class_exists( 'MAD4B_SCP_Servers', false ) ? MAD4B_SCP_Servers::registration_status() : array();
		$chatgpt = isset( $servers['mad4b-chatgpt'] ) ? $servers['mad4b-chatgpt'] : array();
		return array(
			'contract' => self::CONTRACT,
			'connection' => $connection,
			'mcp' => array(
				'adapter_version' => isset( $connection['runtime_projection']['adapter_version'] ) ? (string) $connection['runtime_projection']['adapter_version'] : '',
				'bridge_booted' => ! empty( $registration['bridge_booted'] ),
				'registration_errors' => isset( $registration['registration_errors'] ) && is_array( $registration['registration_errors'] ) ? $registration['registration_errors'] : array(),
				'chatgpt_registration' => $chatgpt,
			),
			'external_acceptance' => array(
				'performed' => false,
				'contract' => isset( $connection['edge_contract'] ) ? $connection['edge_contract'] : array(),
				'note' => 'Run the external edge-acceptance script from an independent network/CI runner; local WordPress truth does not self-certify the public edge.',
			),
			'read_only' => true,
			'mutation_performed' => false,
			'outbound_probe_performed' => false,
		);
	}

	public static function differential( $input ) {
		$input = is_array( $input ) ? $input : array();
		$reference = isset( $input['reference'] ) && is_array( $input['reference'] ) ? $input['reference'] : array();
		$left = self::snapshot();
		$current = isset( $left['connection'] ) && is_array( $left['connection'] ) ? $left['connection'] : array();
		$other = isset( $reference['connection'] ) && is_array( $reference['connection'] ) ? $reference['connection'] : $reference;
		$diff = class_exists( 'MAD4B_SCP_Connection_Identity_Resolver' )
			? MAD4B_SCP_Connection_Identity_Resolver::differential( $current, $other )
			: array( 'match' => false, 'differences' => array( array( 'path' => 'resolver', 'left' => 'unavailable', 'right' => 'unknown' ) ) );
		return array(
			'contract' => 'mad4b.connection-doctor-differential.v1',
			'current_connection_fingerprint' => isset( $current['connection_fingerprint'] ) ? (string) $current['connection_fingerprint'] : '',
			'reference_connection_fingerprint' => isset( $other['connection_fingerprint'] ) ? sanitize_text_field( (string) $other['connection_fingerprint'] ) : '',
			'differential' => $diff,
			'read_only' => true,
			'mutation_performed' => false,
		);
	}
}

MAD4B_SCP_Connection_Doctor::boot();
