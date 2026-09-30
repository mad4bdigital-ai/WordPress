<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_options'] = array();
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $c, $m = '', $d = null ) { $this->code=$c; $this->message=$m; $this->data=$d; }
	public function get_error_code(){ return $this->code; }
	public function get_error_message(){ return $this->message; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function sanitize_key( $v ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function get_option( $k, $d = false ){ return array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ? $GLOBALS['mad4b_test_options'][ $k ] : $d; }
function add_option( $k, $v, $deprecated = '', $autoload = null ){ if ( array_key_exists( $k, $GLOBALS['mad4b_test_options'] ) ) return false; $GLOBALS['mad4b_test_options'][$k]=$v; return true; }
function update_option( $k, $v, $autoload = null ){ $GLOBALS['mad4b_test_options'][$k]=$v; return true; }
function delete_option( $k ){ unset( $GLOBALS['mad4b_test_options'][$k] ); return true; }
function wp_generate_uuid4(){ static $n=0; ++$n; return sprintf( '11111111-1111-4111-8111-%012d', $n ); }

require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-runtime-maintenance-lease.php';

function ok( $condition, $message ){ if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }

$token = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'runtime_convergence' );
ok( is_string( $token ) && '' !== $token, 'first worker must acquire lease' );
$status = MAD4B_SCP_Runtime_Maintenance_Lease::status();
ok( ! empty( $status['active'] ) && 'runtime_convergence' === $status['owner'], 'lease status must expose owner' );

$contender = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_wp_error( $contender ) && 'mad4b_runtime_maintenance_busy' === $contender->get_error_code(), 'competing worker must fail closed' );

$shared = $GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Maintenance_Lease::OPTION ];
$shared['expires_at'] = time() - 1;
$GLOBALS['mad4b_test_options'][ MAD4B_SCP_Runtime_Maintenance_Lease::OPTION ] = $shared;
foreach ( MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options() as $option ) {
	$row = $GLOBALS['mad4b_test_options'][ $option ];
	$row['expires_at'] = time() - 1;
	$GLOBALS['mad4b_test_options'][ $option ] = $row;
}
$contender = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_wp_error( $contender ) && 'mad4b_runtime_maintenance_busy' === $contender->get_error_code(), 'expired soft lease must remain fenced until hard deadline' );

$refresh = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( $token, 'runtime_convergence' );
ok( true === $refresh, 'owner must renew lease' );
ok( MAD4B_SCP_Runtime_Maintenance_Lease::owned( $token, 'runtime_convergence' ), 'renewed token must retain ownership' );

$wrong = MAD4B_SCP_Runtime_Maintenance_Lease::refresh( 'wrong-token', 'runtime_convergence' );
ok( is_wp_error( $wrong ) && 'mad4b_runtime_maintenance_lease_lost' === $wrong->get_error_code(), 'stale token must be fenced' );

MAD4B_SCP_Runtime_Maintenance_Lease::release( $token, 'runtime_convergence' );
ok( false === get_option( MAD4B_SCP_Runtime_Maintenance_Lease::OPTION, false ), 'shared lease must be released' );
foreach ( MAD4B_SCP_Runtime_Maintenance_Lease::legacy_options() as $option ) ok( false === get_option( $option, false ), 'legacy fence must be released' );

$schema = MAD4B_SCP_Runtime_Maintenance_Lease::acquire( 'schema_lifecycle' );
ok( is_string( $schema ) && '' !== $schema, 'next owner must acquire after verified release' );
MAD4B_SCP_Runtime_Maintenance_Lease::release( $schema, 'schema_lifecycle' );

echo "mad4b.runtime-maintenance-lease.v1: PASS\n";
