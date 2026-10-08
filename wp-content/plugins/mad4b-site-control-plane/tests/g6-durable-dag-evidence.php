<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { private $c; public function __construct( $c, $m = '', $d = array() ) { $this->c = $c; } public function get_error_code() { return $this->c; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_current_user_id() { return $GLOBALS['test_owner']; }
function current_user_can( $cap ) { return $GLOBALS['test_owner'] > 0 && 'manage_options' === $cap; }
function wp_get_environment_type() { return 'staging'; }
class MAD4B_SCP_Site_Profile { public static function site_uuid() { return '12345678-1234-1234-1234-123456789abc'; } }
class MAD4B_SCP_Runtime_Generation_Fence { public static function capture() { return array( 'generation_sha256' => $GLOBALS['test_gen'], 'material' => array( 'generation' => $GLOBALS['test_gen'] ) ); } }
class MAD4B_SCP_Restore_Epoch { public static function material() { return array( 'epoch' => 7 ); } }
class MAD4B_SCP_Content_Jobs { public static function get_job( $input ) { return array( 'job' => $GLOBALS['test_job'] ); } }
class MAD4B_SCP_Durable_Execution {
    public static function scope_key( $site, $cap, $operation, $target ) { return hash( 'sha256', $site . ':' . $cap . ':' . $operation . ':' . $target ); }
}
class MAD4B_SCP_Schema { public static function tables() { return array( 'idempotency' => 'mad4b_idempotency' ); } }
class G6_Receipt_Test_DB {
    public $last_error = '';
    public function prepare( $sql, ...$args ) { return $args; }
    public function get_row( $query, $format ) {
        $scope = $query[0]; $key = $query[1]; $row = $GLOBALS['test_row'];
        $GLOBALS['test_read_key'] = $key;
        if ( ! $row ) return null;
        $row['scope_key'] = $scope; $row['idempotency_key'] = $key;
        return $row;
    }
}
$GLOBALS['wpdb'] = new G6_Receipt_Test_DB();
$GLOBALS['test_owner'] = 22;
$GLOBALS['test_gen'] = str_repeat( 'a', 64 );
$GLOBALS['test_job'] = array( 'job_id' => 'job-one', 'job_revision' => 5, 'state' => 'RUNNING',
    'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid() );
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-g6-operation-compiler.php';
function check( $ok, $label ) { if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); } }
function denial( $value, $code ) { check( is_wp_error( $value ) && $value->get_error_code() === $code, 'expected ' . $code ); }
$binding = MAD4B_SCP_G6_Contracts::binding( str_repeat( 'b', 64 ) );
check( ! is_wp_error( $binding ), 'runtime binding available' );
$plan = array( 'contract' => MAD4B_SCP_G6_Operation_Compiler::CONTRACT,
    'owner_user_id' => 22, 'binding' => $binding, 'job_id' => 'job-one',
    'job_revision' => 5, 'nodes' => array(
        'root' => array( 'capability_id' => 'content_experience.update', 'typed_input' => array( 'post_id' => 52 ),
            'step_sha256' => str_repeat( 'c', 64 ), 'depends_on' => array() ),
        'child' => array( 'capability_id' => 'content_experience.update', 'typed_input' => array( 'post_id' => 52 ),
            'step_sha256' => str_repeat( 'd', 64 ), 'depends_on' => array( 'root' ) ) ) );
$plan['plan_sha256'] = MAD4B_SCP_G6_Contracts::digest( $plan );
$GLOBALS['test_row'] = array(
    'request_sha256' => MAD4B_SCP_G6_Contracts::digest( $plan['nodes']['root']['typed_input'] ),
    'claim_epoch' => 1, 'status' => 'completed', 'result_json' => '{"done":true}',
    'result_sha256' => hash( 'sha256', '{"done":true}' ),
    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'root' ), 'mad4b_g6_dag_dependencies_missing' );
$ok = MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' );
check( ! is_wp_error( $ok ) && $ok['durable_records_integrity_verified'], 'durable record readback verified' );
check( ! $ok['provider_postconditions_verified'] && ! $ok['dependency_dispatch_admitted'], 'durable record alone never authorizes dependent dispatch' );
check( $plan['plan_sha256'] . ':root' === $GLOBALS['test_read_key'], 'existing short durable readback key is preserved' );
$long_dependency = str_repeat( 'n', 191 ); $long_plan = $plan;
$long_plan['nodes'][ $long_dependency ] = $long_plan['nodes']['root']; unset( $long_plan['nodes']['root'] );
$long_plan['nodes']['child']['depends_on'] = array( $long_dependency ); unset( $long_plan['plan_sha256'] );
$long_plan['plan_sha256'] = MAD4B_SCP_G6_Contracts::digest( $long_plan );
$long_evidence = MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $long_plan, 'child' );
check( ! is_wp_error( $long_evidence ) && $long_evidence['durable_records_integrity_verified'] && ! $long_evidence['dependency_dispatch_admitted'], 'full-length dependency identity can be inspected without admitting execution' );
check( strlen( $GLOBALS['test_read_key'] ) <= 191 && MAD4B_SCP_G6_Contracts::compiled_step_key( $long_plan['plan_sha256'], $long_dependency ) === $GLOBALS['test_read_key'], 'dependency readback shares the bounded dispatch key' );
$old = $GLOBALS['test_row'];
$GLOBALS['test_row']['result_sha256'] = str_repeat( 'f', 64 );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['status'] = 'pending';
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old;
$prior_zone = date_default_timezone_get();
date_default_timezone_set( 'Pacific/Honolulu' );
$GLOBALS['test_row']['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
date_default_timezone_set( $prior_zone );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['result_json'] = '{broken';
$GLOBALS['test_row']['result_sha256'] = hash( 'sha256', '{broken' );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['claim_epoch'] = '1junk';
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['claim_epoch'] = '0001';
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['claim_epoch'] = '1';
check( ! is_wp_error( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ) ), 'canonical DB integer string accepted' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_row']['expires_at'] = ( (int) gmdate( 'Y' ) + 1 ) . '-02-30 23:59:59';
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_durable_completion_missing' );
$GLOBALS['test_row'] = $old; $GLOBALS['test_job']['job_revision'] = '5junk';
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_job_changed' );
$GLOBALS['test_job']['job_revision'] = 6;
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_job_changed' );
$GLOBALS['test_job']['job_revision'] = 5; $GLOBALS['test_gen'] = str_repeat( 'e', 64 );
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_generation_changed' );
$GLOBALS['test_gen'] = str_repeat( 'a', 64 ); $GLOBALS['test_owner'] = 24;
denial( MAD4B_SCP_G6_Durable_DAG_Evidence::inspect( $plan, 'child' ), 'mad4b_g6_dag_plan_owner' );
echo "mad4b.g6-durable-dag-evidence.v1: PASS\n";
