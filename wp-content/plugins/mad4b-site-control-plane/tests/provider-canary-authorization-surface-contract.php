<?php

define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-authorization.php';

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message} expected=" . var_export( $expected, true ) . " actual=" . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$method = new ReflectionMethod( 'MAD4B_SCP_Authorization', 'declared_server_for_registration' );
$method->setAccessible( true );

$write_args = array(
	'category' => 'mad4b-admin',
	'meta' => array(
		'mcp' => array( 'surface' => 'write' ),
		'annotations' => array( 'readonly' => false ),
	),
);
expect_same( 'mad4b-write', $method->invoke( null, $write_args ), 'explicit write surface must override admin category and bind the execution claim to mad4b-write' );

$admin_args = array(
	'category' => 'mad4b-admin',
	'meta' => array(
		'mcp' => array( 'surface' => 'admin' ),
		'annotations' => array( 'readonly' => false ),
	),
);
expect_same( 'mad4b-admin', $method->invoke( null, $admin_args ), 'admin surface keeps its existing authority binding' );

$content_args = array(
	'category' => 'mad4b-content',
	'meta' => array(
		'mcp' => array( 'surface' => 'content' ),
		'annotations' => array( 'readonly' => false ),
	),
);
expect_same( 'mad4b-content', $method->invoke( null, $content_args ), 'content surface keeps its existing authority binding' );

$fallback_args = array(
	'category' => 'mad4b-admin',
	'meta' => array(
		'mcp' => array( 'surface' => 'unknown' ),
		'annotations' => array( 'readonly' => false ),
	),
);
expect_same( 'mad4b-admin', $method->invoke( null, $fallback_args ), 'unknown surface still falls back to the governed category' );

$unclassified_args = array(
	'category' => 'mad4b-other',
	'meta' => array(
		'mcp' => array(),
		'annotations' => array( 'readonly' => false ),
	),
);
expect_same( 'mad4b-write', $method->invoke( null, $unclassified_args ), 'unclassified governed mutation remains fail-closed on the dedicated write authority' );

echo "MAD4B provider canary authorization surface contract passed.\n";
