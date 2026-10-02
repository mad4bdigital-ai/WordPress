<?php
if ( ! defined( 'ABSPATH' ) ) throw new RuntimeException( 'WordPress is not loaded.' );

$fail = static function ( $message, $data = null ) {
	fwrite( STDERR, 'FAIL runtime-execution-fence-recursion: ' . $message . ( null !== $data ? ' ' . wp_json_encode( $data ) : '' ) . PHP_EOL );
	exit( 1 );
};
$check = static function ( $condition, $message, $data = null ) use ( $fail ) { if ( ! $condition ) $fail( $message, $data ); };
$code = static function ( $value ) { return is_wp_error( $value ) ? $value->get_error_code() : ''; };

$check( class_exists( 'MAD4B_SCP_Execution_Fence' ), 'Execution fence unavailable.' );

$child_name = 'mad4b-ci/recursive-child-fixture';
$parent_name = 'mad4b-ci/recursive-parent-fixture';
$GLOBALS['mad4b_recursive_child_calls'] = 0;

if ( ! wp_has_ability( $child_name ) ) {
	wp_register_ability( $child_name, array(
		'label' => 'Recursive child fixture',
		'description' => 'Mutating child fixture for explicit governed child permits.',
		'category' => 'mad4b-read',
		'execute_callback' => static function ( $input = null ) {
			$GLOBALS['mad4b_recursive_child_calls']++;
			return array( 'ok' => true, 'input' => $input );
		},
		'permission_callback' => static function () { return true; },
		'input_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'write' ), 'annotations' => array( 'readonly' => false ) ),
	) );
}
if ( ! wp_has_ability( $parent_name ) ) {
	wp_register_ability( $parent_name, array(
		'label' => 'Recursive parent fixture',
		'description' => 'Readonly parent fixture driving nested child mutation tests.',
		'category' => 'mad4b-read',
		'execute_callback' => static function ( $input = null ) use ( $child_name ) {
			$mode = is_array( $input ) && isset( $input['mode'] ) ? (string) $input['mode'] : 'direct';
			$child = wp_get_ability( $child_name );
			$child_input = array( '_mad4b_approval_ticket_id' => '00000000-0000-4000-8000-000000000999', 'value' => 1 );
			if ( 'direct' === $mode ) return $child->execute( $child_input );
			if ( 'permitted' === $mode ) {
				return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) {
					return $child->execute( $child_input );
				}, 'fixture' );
			}
			if ( 'mismatch' === $mode ) {
				return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) {
					$changed = $child_input;
					$changed['value'] = 2;
					return $child->execute( $changed );
				}, 'fixture' );
			}
			if ( 'replay' === $mode ) {
				return MAD4B_SCP_Execution_Fence::with_governed_child( $child_name, $child_input, static function () use ( $child, $child_input ) {
					$first = $child->execute( $child_input );
					$second = $child->execute( $child_input );
					return array( 'first' => $first, 'second' => $second );
				}, 'fixture' );
			}
			return new WP_Error( 'fixture_mode_invalid', 'Unknown fixture mode.' );
		},
		'permission_callback' => static function () { return true; },
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'mode' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
		'output_schema' => array( 'type' => 'object', 'additionalProperties' => true ),
		'meta' => array( 'mcp' => array( 'public' => false, 'type' => 'tool', 'surface' => 'read' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	) );
}

$parent = wp_get_ability( $parent_name );
$direct = $parent->execute( array( 'mode' => 'direct' ) );
$check( 'mad4b_recursive_dispatch_child_operation_required' === $code( $direct ), 'Unexpected nested mutation was not denied.', $direct );
$check( 0 === (int) $GLOBALS['mad4b_recursive_child_calls'], 'Denied recursive child reached the original callback.' );

$permitted = $parent->execute( array( 'mode' => 'permitted' ) );
$check( ! is_wp_error( $permitted ) && ! empty( $permitted['ok'] ) && 1 === (int) $GLOBALS['mad4b_recursive_child_calls'], 'Explicit governed child permit did not execute exactly once.', $permitted );

$mismatch = $parent->execute( array( 'mode' => 'mismatch' ) );
$check( 'mad4b_child_operation_permit_mismatch' === $code( $mismatch ), 'Child input/evidence mutation after permit was not denied.', $mismatch );
$check( 1 === (int) $GLOBALS['mad4b_recursive_child_calls'], 'Mismatched child permit reached the original callback.' );

$replay = $parent->execute( array( 'mode' => 'replay' ) );
$check( is_array( $replay ) && ! is_wp_error( $replay['first'] ) && 'mad4b_recursive_dispatch_child_operation_required' === $code( $replay['second'] ), 'One-time child permit was reusable.', $replay );
$check( 2 === (int) $GLOBALS['mad4b_recursive_child_calls'], 'One-time child permit execution count is incorrect.' );

$status = MAD4B_SCP_Execution_Fence::status();
$check( 0 === (int) $status['execution_depth'] && empty( $status['child_permit_pending'] ), 'Execution stack or child permit leaked after recursive tests.', $status );

echo "mad4b.execution-fence.recursion.v1: PASS\n";
