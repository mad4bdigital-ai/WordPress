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


	if ( ! wp_has_ability( 'mad4b-ci/recursive-child-fixture' ) ) {
		wp_register_ability( 'mad4b-ci/recursive-child-fixture', array(
			'label' => 'Recursive child fixture',
			'description' => 'Mutating child fixture for explicit governed child permits.',
			'category' => 'mad4b-read',
			'execute_callback' => static function ( $input = null ) {
				$GLOBALS['mad4b_recursive_child_calls'] = (int) ( $GLOBALS['mad4b_recursive_child_calls'] ?? 0 ) + 1;
				return array( 'ok' => true, 'input' => $input );
			},
			'permission_callback' => static function () { return true; },
			'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ), 'annotations' => array( 'readonly' => false ) ),
		) );
	}
	if ( ! wp_has_ability( 'mad4b-ci/recursive-parent-fixture' ) ) {
		wp_register_ability( 'mad4b-ci/recursive-parent-fixture', array(
			'label' => 'Recursive parent fixture',
			'description' => 'Readonly parent fixture driving nested child mutation tests.',
			'category' => 'mad4b-read',
			'execute_callback' => static function ( $input = null ) {
				$child_name = 'mad4b-ci/recursive-child-fixture';
				$mode = is_array( $input ) && isset( $input['mode'] ) ? (string) $input['mode'] : 'direct';
				$child = wp_get_ability( $child_name );
				if ( ! $child ) return new WP_Error( 'mad4b_recursive_fixture_child_missing', 'Recursive child fixture is unavailable.' );
				$child_input = array(
					'_mad4b_approval_ticket_id' => '00000000-0000-4000-8000-000000000999',
					'preparation_receipt' => 'fixture-preparation-receipt',
					'_mad4b_context_receipt' => array( 'receipt_sha256' => str_repeat( 'a', 64 ) ),
					'idempotency_key' => 'fixture-idempotency-key',
					'operation_id' => 'fixture-operation',
					'execution_id' => 'fixture-execution',
					'claim_epoch' => 7,
					'request_sha256' => str_repeat( 'b', 64 ),
					'value' => 1,
				);
				if ( 'direct' === $mode ) return $child->execute( $child_input );
				if ( 'permitted' === $mode ) return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) { return $child->execute( $child_input ); }, 'fixture' );
				if ( 'mismatch' === $mode ) return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) { $changed=$child_input; $changed['value']=2; return $child->execute( $changed ); }, 'fixture' );
				if ( 'evidence_mismatch' === $mode ) return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) { $changed=$child_input; $changed['idempotency_key']='fixture-idempotency-key-changed'; return $child->execute( $changed ); }, 'fixture' );
				if ( 'replay' === $mode ) return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) { $first=$child->execute( $child_input ); $second=$child->execute( $child_input ); return array( 'first'=>$first, 'second'=>$second ); }, 'fixture' );
				return new WP_Error( 'fixture_mode_invalid', 'Unknown fixture mode.' );
			},
			'permission_callback' => static function () { return true; },
			'input_schema' => array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string' ) ), 'additionalProperties' => true ),
			'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
			'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
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
        if ( in_array( $name, array( 'mad4b-ci/spoofed-boundary', 'mad4b-ci/late-read-admission-fixture' ), true ) ) $args['execute_callback'] = static function () { return array( 'ok' => true ); };
        return $args;
    };
    add_filter( 'wp_register_ability_args', $strip_boundary, PHP_INT_MAX, 2 );
	wp_register_ability( 'mad4b-ci/spoofed-boundary', array(
		'label' => 'Spoofed boundary fixture', 'description' => 'Metadata does not prove wrapper provenance.', 'category' => 'mad4b-read',
		'execute_callback' => static function() { return array( 'ok' => true ); }, 'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array() ), 'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'annotations' => array( 'readonly' => false ), 'mcp' => array( 'type' => 'tool', 'surface' => 'write', 'mad4b_execution_boundary' => MAD4B_SCP_Authorization::EXECUTION_BOUNDARY_CONTRACT ) ),
	) );
	wp_register_ability( 'mad4b-ci/late-read-admission-fixture', array(
		'label' => 'Late read admission fixture',
		'description' => 'Readonly fixture whose final execution wrapper is intentionally replaced by a later same-priority registration filter.',
		'category' => 'mad4b-read',
		'execute_callback' => static function() { return array( 'ok' => true ); },
		'permission_callback' => static function() { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array(
			'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
    remove_filter( 'wp_register_ability_args', $strip_boundary, PHP_INT_MAX );
}, 99 );
