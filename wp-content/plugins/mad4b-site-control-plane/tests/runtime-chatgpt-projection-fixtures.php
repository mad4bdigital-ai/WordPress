<?php
if ( ! defined( 'ABSPATH' ) ) exit;
add_action( 'wp_abilities_api_init', static function () {
// Foreign REST requests deliberately use the zero-touch bootstrap.
if ( ! class_exists( 'MAD4B_SCP_Authorization' ) ) return;
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

	if ( ! wp_has_ability( 'mad4b-ci/arabic-metadata-fixture' ) ) {
		wp_register_ability( 'mad4b-ci/arabic-metadata-fixture', array(
			'label' => str_repeat( 'مرحبا', 80 ),
			'description' => str_repeat( 'محتوى عربي للاختبار ', 80 ),
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
	}

	if ( ! wp_has_ability( 'mad4b-ci/denied-read-dispatch' ) ) {
		wp_register_ability( 'mad4b-ci/denied-read-dispatch', array(
		    'label' => 'Denied read dispatcher fixture',
		    'description' => 'Checks preservation of the original permission callback.',
		    'category' => 'mad4b-read',
		    'execute_callback' => static function () { throw new RuntimeException( 'Denied callback executed.' ); },
		    'permission_callback' => static function () { return false; },
		    'input_schema' => array( 'type' => 'object' ),
		    'output_schema' => array( 'type' => 'object' ),
		    'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
	}

	if ( ! wp_has_ability( 'mad4b-ci/oversized-read-projection-fixture' ) ) {
		wp_register_ability( 'mad4b-ci/oversized-read-projection-fixture', array(
			'label' => 'Oversized Read Projection Fixture',
			'description' => 'CI fixture proving that transport chunking does not imply direct tools/list admission.',
			'category' => 'mad4b-read',
			'execute_callback' => static function ( $input = null ) { unset( $input ); return array( 'ok' => true ); },
			'permission_callback' => static function () { return true; },
			'input_schema' => array(
				'type' => 'object',
				'properties' => array(),
				'description' => str_repeat( 'bounded-direct-tool-schema-', 5000 ),
				'additionalProperties' => false,
			),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array(
				'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}

	if ( ! wp_has_ability( 'mad4b-ci/readonly-projection-fixture' ) ) {
		wp_register_ability( 'mad4b-ci/readonly-projection-fixture', array(
			'label' => 'Readonly Projection Fixture',
			'description' => 'CI fixture proving a classified third-party read Ability outside the fixed dispatcher catalog requires explicit dynamic projection.',
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
	}

	wp_register_ability( 'mad4b-ci/unsafe-write-projection-fixture', array(
		'label' => 'Unsafe Write Projection Fixture', 'description' => 'CI only: declared write without governed execution wrapper.', 'category' => 'mad4b-read',
		'execute_callback' => static function() { $GLOBALS['mad4b_projection_unsafe_calls'] = 1 + ( $GLOBALS['mad4b_projection_unsafe_calls'] ?? 0 ); return array( 'ok' => true ); },
		'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ), 'annotations' => array( 'readonly' => false ) ),
	) );
	foreach ( array( 'internal', 'developer', 'breakglass' ) as $lane ) wp_register_ability( 'mad4b-ci/readonly-' . $lane, array(
		'label' => 'Sensitive read fixture', 'description' => 'Readonly is not an authority lane.', 'category' => 'mad4b-read',
		'execute_callback' => static function() { return array( 'ok' => true ); }, 'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array() ), 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'annotations' => array( 'readonly' => true ), 'mcp' => array( 'type' => 'tool', 'surface' => $lane ) ),
	) );
    // Simulate a later plugin replacing a genuinely wrapped callback while
    // leaving its claimed boundary metadata intact. The actual callback must fail provenance.
    $strip_boundary = static function ( $args, $name ) {
        if ( 'mad4b-ci/spoofed-boundary' === $name ) $args['execute_callback'] = static function () { return array( 'ok' => true ); };
        return $args;
    };
    add_filter( 'wp_register_ability_args', $strip_boundary, PHP_INT_MAX, 2 );
	wp_register_ability( 'mad4b-ci/spoofed-boundary', array(
		'label' => 'Spoofed boundary fixture', 'description' => 'Metadata does not prove wrapper provenance.', 'category' => 'mad4b-read',
		'execute_callback' => static function() { return array( 'ok' => true ); }, 'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array() ), 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'annotations' => array( 'readonly' => false ), 'mcp' => array( 'type' => 'tool', 'surface' => 'write', 'mad4b_execution_boundary' => MAD4B_SCP_Authorization::EXECUTION_BOUNDARY_CONTRACT ) ),
	) );
    remove_filter( 'wp_register_ability_args', $strip_boundary, PHP_INT_MAX );
}, 99 );
