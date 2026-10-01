<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action( 'wp_abilities_api_init', static function () {
if ( ! wp_has_ability( 'mad4b-ci/unclassified-projection-fixture' ) ) {
	wp_register_ability( 'mad4b-ci/unclassified-projection-fixture', array(
		'label' => 'Unclassified Projection Fixture',
		'description' => 'CI fixture proving a registered third-party-style Ability without annotations.readonly can still be projected conservatively.',
		'category' => 'mad4b-read',
		'execute_callback' => static function ( $input = null ) { unset( $input ); $GLOBALS['mad4b_projection_unsafe_calls'] = ( $GLOBALS['mad4b_projection_unsafe_calls'] ?? 0 ) + 1; return array( 'ok' => true ); },
		'permission_callback' => static function () { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ) ),
	) );
}

	wp_register_ability( 'mad4b-ci/unsafe-write-projection-fixture', array(
		'label' => 'Unsafe Write Projection Fixture', 'description' => 'CI only: declared write without governed execution wrapper.', 'category' => 'mad4b-read',
		'execute_callback' => static function() { $GLOBALS['mad4b_projection_unsafe_calls'] = 1 + ( $GLOBALS['mad4b_projection_unsafe_calls'] ?? 0 ); return array( 'ok' => true ); },
		'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ), 'annotations' => array( 'readonly' => false ) ),
	) );
}, 99 );
