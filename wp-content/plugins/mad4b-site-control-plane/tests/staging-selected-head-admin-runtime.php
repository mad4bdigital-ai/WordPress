<?php
/* Hermetic nonauthorizing Staging admin source selector test. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-source-selector.php';
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-staging-selected-head-admin.php';
function verify( $ok, $case ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: " . $case . "\n" ); exit( 1 ); } }
$admin = 'MAD4B_SCP_Staging_Selected_Head_Admin';
$site = array( 'environment'=>'staging', 'configured_environment'=>'staging' );
verify( $admin::visible_for_site( $site, 'staging' ), 'staging visible' );
verify( ! $admin::visible_for_site( $site, 'production' ), 'production concealed' );
verify( ! $admin::visible_for_site( array('environment'=>'staging','configured_environment'=>'production'), 'staging' ), 'profile mismatch concealed' );
$good = $admin::input_from_form( array( 'source_type'=>'branch_commit', 'branch'=>'feature/test', 'commit'=>str_repeat('a', 40) ) );
verify( is_array($good) && $good['type']==='branch_commit' && $good['reference']==='feature/test@'.str_repeat('a',40), 'exact branch commit source' );
verify( is_wp_error( $admin::input_from_form( array('source_type'=>'branch_commit','branch'=>'../bad','commit'=>str_repeat('a',40)) ) ), 'invalid branch denied' );
verify( is_wp_error( $admin::input_from_form( array('source_type'=>'commit','commit'=>'unsafe') ) ), 'invalid commit denied' );
verify( is_wp_error( $admin::input_from_form( array('source_type'=>'branch','branch'=>'bad//branch') ) ), 'untrusted URL-like branch denied' );
verify( is_wp_error( $admin::input_from_form( array('source_type'=>'unknown','reference'=>'1') ) ), 'unknown type denied' );
verify( $admin::input_from_form(array('source_type'=>'pull_request','reference'=>'420'))['type']==='pull_request', 'PR supported' );
echo "PASS: Staging branch/commit admin picker (safe read-only schema)\n";
