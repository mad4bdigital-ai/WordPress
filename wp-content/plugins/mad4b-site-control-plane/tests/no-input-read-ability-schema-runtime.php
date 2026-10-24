<?php
/** Regression: no-argument MCP reads must accept the transport's empty object. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['mad4b_registered_abilities'] = array();
function wp_register_ability( $name, $args ) { $GLOBALS['mad4b_registered_abilities'][ $name ] = $args; return true; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';
$instance = ( new ReflectionClass( 'MAD4B_SCP_Abilities' ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( 'MAD4B_SCP_Abilities', 'add' );
$method->setAccessible( true );
$cases = array(
    array( 'mad4b/site-info', null, true, true ),
    array( 'mad4b/list-plugins', null, true, true ),
    array( 'mad4b/write-test', null, false, false ),
    array( 'mad4b/read-parameters', array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ), 'required' => array( 'id' ) ), true, false ),
);
foreach ( $cases as $case ) {
    list( $name, $input, $readonly, $expects_empty ) = $case;
    $method->invoke( $instance, $name, $name, 'mad4b-read', 'site_info', $readonly ? 'read' : 'admin', $input, false, $readonly, false, true );
    $result = $GLOBALS['mad4b_registered_abilities'][ $name ];
    if ( $expects_empty ) {
        if ( ( $result['input_schema']['type'] ?? null ) !== array( 'object', 'null' ) || ( $result['input_schema']['properties'] ?? null ) !== array() || ( $result['input_schema']['additionalProperties'] ?? null ) !== false ) throw new Exception( 'Empty schema missing: ' . $name );
    } elseif ( null === $input && array_key_exists( 'input_schema', $result ) ) throw new Exception( 'Write schema unexpectedly modified' );
    elseif ( is_array( $input ) && $result['input_schema'] !== $input ) throw new Exception( 'Parameterized schema changed' );
}
$accepts = new ReflectionMethod( 'MAD4B_SCP_Abilities', 'input_schema_accepts_object' );
$accepts->setAccessible( true );
foreach ( array(
    array( array( 'type' => 'object' ), true ),
    array( array( 'type' => array( 'object', 'null' ) ), true ),
    array( array( 'type' => array( 'null', 'object' ) ), true ),
    array( array( 'type' => 'null' ), false ),
    array( array( 'type' => 'string' ), false ),
    array( null, false ),
) as $test ) {
    if ( $accepts->invoke( null, $test[0] ) !== $test[1] )
        throw new Exception( 'Governed read dispatcher object schema compatibility failed' );
}
echo "PASS: NO_INPUT_READ_ABILITY_SCHEMA_RUNTIME (10 cases)\\n";
