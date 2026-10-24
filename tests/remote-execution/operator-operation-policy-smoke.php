<?php
define( 'ABSPATH', __DIR__ . '/../../' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-operation-policy.php';
$tests = array(
    array( 'shell', array( 'command' => 'pwd' ), true ),
    array( 'shell', array( 'command' => '' ), false ),
    array( 'wp-cli', array( 'args' => array( 'core', 'version' ) ), true ),
    array( 'wp-cli', array( 'args' => array( '--ssh=example', 'core' ) ), false ),
    array( 'sql.read', array( 'sql' => 'SELECT 1' ), true ),
    array( 'sql.read', array( 'sql' => 'SHOW TABLES' ), true ),
    array( 'sql.read', array( 'sql' => 'DROP TABLE wp_posts' ), false ),
    array( 'sql.read', array( 'sql' => 'SELECT * FROM wp_posts FOR UPDATE' ), false ),
    array( 'sql.read', array( 'sql' => 'SELECT 1; DELETE FROM wp_posts;' ), false ),
    array( 'sql.write', array( 'sql' => 'UPDATE wp_options SET option_value = 1 WHERE option_id = 1' ), true ),
    array( 'sql.write', array( 'sql' => 'TRUNCATE wp_posts' ), false ),
    array( 'sql.schema', array( 'sql' => 'ALTER TABLE staging_test ADD COLUMN x INT' ), true ),
    array( 'sql.schema', array( 'sql' => 'UPDATE wp_options SET option_value = 1' ), false ),
);
foreach ( $tests as $i => $test ) {
    $v = MAD4B_SCP_Operator_Operation_Policy::inspect( array( 'operation' => $test[0], 'payload' => $test[1] ) );
    $passed = ( ! is_wp_error( $v ) ) === $test[2];
    if ( ! $passed ) { fwrite( STDERR, "FAIL case $i\n" ); exit( 1 ); }
    if ( ! is_wp_error( $v ) && (
        $v['execution_enabled'] !== false ||
        $v['production_authorized'] !== false ||
        $v['mutation_performed'] !== false ) ) {
        fwrite( STDERR, "FAIL: planner unexpectedly authorizes execution\n" ); exit( 1 );
    }
}
echo 'PASS ' . count( $tests ) . " operation classifier checks; no SQL or Shell executed\n";
