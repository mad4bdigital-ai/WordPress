<?php
/** Read-only hermetic regression for 0.6.x/0.7.x MCP output-schema compatibility. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['qa31_registered'] = array();
function add_action( $hook, $cb, $priority = 10 ) { return true; }
function wp_has_ability( $name ) { return false; }
function wp_register_ability( $name, $args ) {
    $GLOBALS['qa31_registered'][ $name ] = $args;
    return true;
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-operator-grant-convergence.php';
MAD4B_SCP_Operator_Grant_Convergence::register_ability();
$name = MAD4B_SCP_Operator_Grant_Convergence::ABILITY;
$args = $GLOBALS['qa31_registered'][ $name ] ?? array();
$fail = static function ( $message ) { fwrite( STDERR, 'FAIL operator_grant_mcp_schema: ' . $message . PHP_EOL ); exit( 1 ); };
$output = $args['output_schema'] ?? null;
if ( ! is_array( $output ) || ( $output['type'] ?? '' ) !== 'object' ) $fail( 'missing output object' );
if ( ( $output['additionalProperties'] ?? null ) !== true ) $fail( 'status/readback fields silently narrowed' );
if ( ! is_array( $output['properties'] ?? null ) || ! isset( $output['properties']['contract'] )
    || ( $output['properties']['contract']['type'] ?? '' ) !== 'string' ) $fail( 'missing contract discriminator' );
$encoded = json_decode( json_encode( $output ), true );
if ( ! is_array( $encoded ) || ! isset( $encoded['properties']['contract'] ) ) $fail( 'invalid MCP JSON object properties' );
if ( ( $args['input_schema']['required'] ?? array() ) !== array( 'action' )
    || ( $args['input_schema']['additionalProperties'] ?? null ) !== false
    || ! empty( $args['meta']['public'] )
    || ! empty( $args['meta']['show_in_rest'] )
    || ! empty( $args['meta']['mcp']['public'] )
    || empty( $args['meta']['mcp']['chatgpt_direct_step_up'] )
    || empty( $args['meta']['mcp']['exact_chatgpt_client_required'] )
    || ( $args['meta']['mcp']['production_mutation_allowed'] ?? null ) !== false
    || ( $args['meta']['annotations']['readonly'] ?? null ) !== false ) {
    $fail( 'exact-step-up or authorization fences changed' );
}
echo 'OPERATOR_GRANT_OUTPUT_SCHEMA: PASS; request-local only' . PHP_EOL;
