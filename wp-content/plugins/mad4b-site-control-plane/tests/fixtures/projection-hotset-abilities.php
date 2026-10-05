<?php
/**
 * Hermetic read-Ability fixture for the hot-set recommender runtime.
 *
 * This file is copied into the disposable CI WordPress mu-plugins directory
 * only for the recommender proof, then removed. It does not ship as runtime
 * registration and grants no authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_abilities_api_init', static function () {
	if ( ! function_exists( 'wp_register_ability' ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'mad4b-ci/readonly-projection-fixture' ) ) ) return;
	wp_register_ability( 'mad4b-ci/readonly-projection-fixture', array(
		'label' => 'Readonly Projection Hot-set Fixture',
		'description' => 'CI-only classified read Ability for non-authorizing hot-set recommendation evidence.',
		'category' => 'mad4b-read',
		'execute_callback' => static function ( $input = null ) { unset( $input ); return array( 'ok' => true ); },
		'permission_callback' => static function () { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array(
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
}, 90 );
