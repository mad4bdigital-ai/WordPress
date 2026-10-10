<?php
/* Standalone source-neutral plugin update/CI outage recovery regression suite.
 * Does not bootstrap WordPress, download packages or invoke mutation abilities.
 */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
 private $name;
 public function __construct( $name, $message = '' ) { $this->name = $name; }
 public function get_error_code() { return $this->name; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
class MAD4B_SCP_Provider_Contracts {
 public static $providers = array();
 public static function all() { return self::$providers; }
}
class MAD4B_SCP_Plugin_Package {
 public static $last_request = null;
 public static function plan( $input ) {
  self::$last_request = $input;
  return array( 'eligible' => true, 'plan_sha256' => str_repeat( 'f', 64 ),
    'provider_id' => $input['provider_id'], 'component' => $input['component'],
    'source' => array( 'available' => true ), 'blockers' => array() );
 }
}
$GLOBALS['installed_plugins'] = array();
function get_plugins() { return $GLOBALS['installed_plugins']; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-plugin-update-recovery.php';
function insist( $valid, $message ) {
 if ( ! $valid ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit(1); }
}
$c = 'MAD4B_SCP_Plugin_Update_Recovery';
$good = array( 'plugin_file' => 'example-one/example.php', 'version' => '1.2.0',
 'archive_sha256' => str_repeat( 'a', 64 ), 'critical_files' => array( 'example.php' => str_repeat('b',64) ) );
$two = $good; $two['plugin_file']='example-two/plugin.php';
MAD4B_SCP_Provider_Contracts::$providers = array(
 'provider_one' => $good,
 'provider_two' => $two,
 'bad_provider' => array( 'plugin_file' => 'bad/example.php', 'version' => '2.0', 'archive_sha256' => 'bad' ),
 'composite' => array( 'components' => array( 'first' => array_merge($good, array('plugin_file'=>'part-a/part.php')) ) ),
);
$map = $c::catalog( MAD4B_SCP_Provider_Contracts::all() );
insist( count($map)===3, 'Only three certified records discovered' );
insist( $map['example-one/example.php']['provider_id']==='provider_one', 'Unique provider owner' );
insist( $map['part-a/part.php']['component']==='first', 'Composite provider component discovery' );
$dup = MAD4B_SCP_Provider_Contracts::$providers;
$dup['second_owner'] = $good;
$conflicted = $c::catalog( $dup );
insist( $conflicted['example-one/example.php']['conflict'], 'Duplicate authority fail-closed' );
$GLOBALS['installed_plugins'] = array(
 'example-one/example.php' => array('Version'=>'1.0.0'),
 'example-two/plugin.php' => array('Version'=>'1.1.0'),
 'uncertified/something.php' => array('Version'=>'5.4'),
 'hello.php' => array('Version'=>'1.7'),
 'misc-hack/../bad.php' => array('Version'=>'9'),
);
$inventory = $c::inventory( get_plugins(), $map, true );
insist( count($inventory)===4, 'All valid installed and single-file plugins discovered; unsafe identifier omitted' );
insist( count(array_filter($inventory,static function($r){return $r['certified_provider'];}))===2, 'Two certified and one uncertified' );
$only = $c::inventory( get_plugins(), $map, false );
insist( count($only)===2, 'Optional uncertified filter' );
insist( count($c::inventory( get_plugins(), $map, true, 1 ))===1, 'Bounded inventory limit' );
$unregistered = array_values(array_filter($inventory,static function($v){return !$v['certified_provider'];}))[0];
insist( $unregistered['update_apply_ability']==='' && !$unregistered['automatic_install'], 'Unregistered plugins never gain executor' );
$read = $c::discover(array('limit'=>5));
insist( !is_wp_error($read) && $read['installed_plugin_count']===5, 'Read-only dynamic installed inventory' );
insist( is_wp_error( $c::discover(array('filter'=>'../wp-config')) ), 'Unsafe discovery input denied' );
$policy=$c::ci_policy('queued',array(),true);
insist(!$policy['eligible']&&!$policy['github_ci_queue_alone_is_blocker'], 'Queue never counts as a test PASS' );
$all=array_fill_keys(array('source_exact','package_verified','tests_passed',
 'owner_reviewed','independently_signed','signature_trust_verified','evidence_fresh','same_plugin_and_site'),true);
foreach (array('queued','unavailable','infrastructure_failure','unknown') as $status) {
 $result=$c::ci_policy($status,$all,true);
 insist($result['eligible']&&$result['evidence_mode']==='certified_provider_with_signed_native_tests',
  'Trusted independent tests can replace unavailable CI diagnostic '.$status);
}
insist(!$c::ci_policy('test_failure',$all,true)['eligible'],'Actual native failure cannot bypass' );
insist(!$c::ci_policy('security_failure',$all,true)['eligible'],'Confirmed security gate cannot bypass' );
insist(!$c::ci_policy('queued',$all,false)['eligible'],'Unknown source does not become certified' );
$plan=$c::plan(array('plugin_file'=>'example-one/example.php','reason'=>'Reviewed Staging plugin update','source'=>'auto_certified'));
insist(!is_wp_error($plan)&&$plan['plan_sha256']===str_repeat('f',64),'Original plugin package plan remains authoritative');
insist($plan['update_executor']==='mad4b/plugin-package-apply'&&!$plan['generic_auto_apply_allowed'],'Existing secure updater reused');
insist(MAD4B_SCP_Plugin_Package::$last_request['provider_id']==='provider_one','Source provider resolved internally');
insist(is_wp_error($c::plan(array('plugin_file'=>'uncertified/something.php','reason'=>'review'))),'No unsigned package install route');
insist(is_wp_error($c::plan(array('plugin_file'=>'example-one/example.php','reason'=>'review','native_evidence'=>array('tests_passed'=>true)))),'Untrusted PASS input rejected');
echo "PASS: provider-neutral plugin discovery, source authority, CI outage and executor denial fixtures\n";
