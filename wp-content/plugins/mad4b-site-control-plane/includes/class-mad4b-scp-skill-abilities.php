<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only MCP/Abilities projection for the file-backed Skill registry.
 * Authoring remains a local wp-admin operation behind explicit environment
 * gates and is deliberately not exposed as a ChatGPT mutation tool.
 */
final class MAD4B_SCP_Skill_Abilities {
	public static function boot() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 30 );
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) return;

		self::add(
			'mad4b/skills-list',
			'List Skills',
			array(
				'type' => 'object',
				'properties' => array(
					'level' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Skill_Registry::levels() ),
					'target' => array( 'type' => 'string', 'maxLength' => 120 ),
					'enabled' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'skills_list' )
		);

		self::add(
			'mad4b/skill-get',
			'Get Skill',
			array(
				'type' => 'object',
				'properties' => array(
					'level' => array( 'type' => 'string', 'enum' => MAD4B_SCP_Skill_Registry::levels() ),
					'target' => array( 'type' => 'string', 'maxLength' => 120 ),
					'name' => array( 'type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' ),
				),
				'required' => array( 'level', 'name' ),
				'additionalProperties' => false,
			),
			array( __CLASS__, 'skill_get' )
		);

		self::add(
			'mad4b/skills-export-status',
			'Get Skills Export Status',
			array( 'type' => 'object', 'additionalProperties' => false ),
			array( __CLASS__, 'skills_export_status' )
		);
	}

	private static function add( $name, $label, array $input_schema, $callback ) {
		wp_register_ability(
			$name,
			array(
				'label' => $label,
				'description' => $label . ' through the governed MAD4B file-backed Skill registry.',
				'category' => 'mad4b-read',
				'execute_callback' => $callback,
				'permission_callback' => array( 'MAD4B_SCP_Policy', 'can_read' ),
				'input_schema' => $input_schema,
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => false,
					'show_in_rest' => false,
					'mcp' => array( 'public' => false, 'type' => 'tool' ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
	}

	public static function skills_list( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$filters = array();
		if ( isset( $input['level'] ) ) $filters['level'] = $input['level'];
		if ( isset( $input['target'] ) ) $filters['target'] = $input['target'];
		if ( array_key_exists( 'enabled', $input ) ) $filters['enabled'] = (bool) $input['enabled'];
		$skills = MAD4B_SCP_Skill_Registry::list_skills( $filters );
		return array( 'contract' => 'mad4b.skills-list.v1', 'count' => count( $skills ), 'skills' => $skills );
	}

	public static function skill_get( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$level = isset( $input['level'] ) ? $input['level'] : '';
		$target = isset( $input['target'] ) ? $input['target'] : '';
		$name = isset( $input['name'] ) ? $input['name'] : '';
		$skill = MAD4B_SCP_Skill_Registry::get_skill( $level, $target, $name );
		if ( is_wp_error( $skill ) ) return $skill;
		return array( 'contract' => 'mad4b.skill-get.v1', 'skill' => $skill );
	}

	public static function skills_export_status() {
		return array(
			'contract' => 'mad4b.skills-export-status.v1',
			'registry' => MAD4B_SCP_Skill_Registry::status(),
			'portable_snapshot' => MAD4B_SCP_Skill_Registry::portable_snapshot(),
			'note' => 'ChatGPT/Codex packaged or MCP-imported skills are snapshots. Runtime WordPress edits require a new package or a new Scan Tools import before the installed Plugin skill badges change.',
		);
	}
}
