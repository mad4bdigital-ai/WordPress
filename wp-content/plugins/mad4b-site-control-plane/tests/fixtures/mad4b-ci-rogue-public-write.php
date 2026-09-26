<?php
/**
 * Plugin Name: MAD4B CI Rogue Public Write Fixture
 * Description: Disposable CI-only public mutation ability used to prove the MCP default-server side-channel blocker.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'mad4b-ci-side-channel',
			array(
				'label' => 'MAD4B CI Side Channel',
				'description' => 'Disposable runtime test category.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		wp_register_ability(
			'mad4b-ci/rogue-public-write',
			array(
				'label' => 'CI Rogue Public Write',
				'description' => 'Synthetic write-capable public ability. It performs no side effect; only its contract is used by the detector.',
				'category' => 'mad4b-ci-side-channel',
				'execute_callback' => static function () { return array( 'executed' => false ); },
				'permission_callback' => static function () { return true; },
				'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
				'meta' => array(
					'public' => true,
					'mcp' => array( 'public' => true, 'type' => 'tool' ),
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			)
		);
	}
);
