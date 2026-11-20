<?php
/** No WordPress bootstrap, database or network: hermetic integrity-policy test. */
define( 'ABSPATH', '/tmp/' );
$dir = '/tmp/mad4b-scoped-build-fixture-' . getmypid() . '/';
mkdir( $dir, 0700, true );
define( 'MAD4B_SCP_DIR', $dir );
class WP_Error { public function get_error_code() { return 'test_error'; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $name, $callback, $priority = 10 ) { return true; }
function wp_has_ability( $a ) { return in_array( $a, array( 'mad4b/tours-and-activities-r1-create-apply', 'mad4b/tours-and-activities-r1-update-apply', 'mad4b/tours-and-activities-r1-publish-apply', 'mad4b/plugin-package-apply' ), true ); }
function sanitize_key( $x ) { return strtolower( trim( (string) $x ) ); }
function absint( $x ) { return abs( (int) $x ); }
function get_post_status( $id ) { return 7 === $id ? 'draft' : ( 8 === $id ? 'publish' : false ); }
class MAD4B_SCP_Site_Profile {
 public static function origin_enrolled() { return true; }
 public static function site_urls_match_enrollment() { return true; }
 public static function current_environment() { return 'production'; }
}
class MAD4B_SCP_Content_Experience_Profiles {
 public static function profile_for_ability( $name ) {
  $routes = array( 'create_apply' => 'mad4b/tours-and-activities-r1-create-apply', 'update_apply' => 'mad4b/tours-and-activities-r1-update-apply', 'publish_apply' => 'mad4b/tours-and-activities-r1-publish-apply' );
  return in_array( $name, $routes, true ) ? array( 'enabled' => true, 'creation_status' => 'draft', 'live_update_mode' => 'draft_first', 'routes' => $routes ) : new WP_Error();
 }
}
class MAD4B_SCP_Impact_Policy {
 public static function classify( $name, $provider, $input ) { return array( 'impact' => 'low', 'approval_required' => false ); }
}
class MAD4B_SCP_Live_Acceptance_Observer {
 public static function build_provenance_identity_status() { return array( 'identity_ready' => true, 'manifest_valid' => true ); }
 public static function build_provenance_status() { return array( 'manifest_valid' => true, 'runtime_manifest_match' => false, 'stale' => true, 'provenance_mismatch' => array( 'unrelated_css_changed' ) ); }
}
require __DIR__ . '/../../wp-content/mu-plugins/530-mad4b-scoped-build-verification.php';
function assert_case( $truth, $message ) { if ( ! $truth ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$cls = 'MAD4B_SCP_Scoped_Build_Verification';
$deps = array();
foreach ( array( 'common_dependencies', 'content_dependencies' ) as $method ) {
 $rm = new ReflectionMethod( $cls, $method );
 $deps = array_merge( $deps, $rm->invoke( null ) );
}
$deps = array_values( array_unique( $deps ) );
$deps[] = 'assets/unrelated.css';
$files = array(); $lines = array();
foreach ( $deps as $name ) {
 $path = MAD4B_SCP_DIR . $name;
 if ( ! is_dir( dirname( $path ) ) ) mkdir( dirname( $path ), 0700, true );
 $bytes = 'trusted-original-' . $name;
 file_put_contents( $path, $bytes );
 $hash = hash( 'sha256', $bytes );
 $entry = array( 'path' => $name, 'bytes' => strlen( $bytes ), 'sha256' => $hash );
 $files[] = $entry;
 $lines[] = $name . "\0" . strlen( $bytes ) . "\0" . $hash . "\n";
}
sort( $lines, SORT_STRING );
$manifest = array( 'package_files' => $files, 'package_manifest_digest' => hash( 'sha256', implode( '', $lines ) ) );
file_put_contents( MAD4B_SCP_DIR . 'MAD4B-BUILD-PROVENANCE.json', json_encode( $manifest ) );
$create='mad4b/tours-and-activities-r1-create-apply';
$r=$cls::verify($create, array('post_status'=>'draft'));
assert_case($r['scope']==='scoped' && $r['verification_ready']===true && $r['verified_files']===count($deps)-1, 'scoped draft verified against manifest');
file_put_contents(MAD4B_SCP_DIR.'assets/unrelated.css','CHANGED CSS DOES NOT AFFECT CONTENT AUTHORITY');
$r=$cls::verify($create, array('post_status'=>'draft'));
assert_case($r['verification_ready']===true && $r['exemption_applies']===true,'unrelated asset drift ignored');
$r=$cls::verify('mad4b/tours-and-activities-r1-publish-apply', array());
assert_case($r['scope']==='full' && $r['verification_ready']===false,'publication requires full package');
$r=$cls::verify('mad4b/plugin-package-apply', array());
assert_case($r['scope']==='full' && $r['verification_ready']===false,'package install requires full package');
$r=$cls::verify('mad4b/tours-and-activities-r1-update-apply', array('post_id'=>8));
assert_case($r['scope']==='full' && $r['verification_ready']===false,'published target cannot be routine');
file_put_contents(MAD4B_SCP_DIR.'includes/class-mad4b-scp-authorization.php','CHANGED SECURITY CODE');
$r=$cls::verify($create, array('post_status'=>'draft'));
assert_case($r['scope']==='scoped' && !$r['verification_ready'] && in_array('material_file_mismatch:includes/class-mad4b-scp-authorization.php',$r['blockers'],true),'authorization drift blocks scoped bypass');
$manifest['package_files'][0]['sha256']=str_repeat('0',64);
file_put_contents(MAD4B_SCP_DIR.'MAD4B-BUILD-PROVENANCE.json',json_encode($manifest));
$r=$cls::verify($create, array('post_status'=>'draft'));
assert_case(!$r['verification_ready'] && in_array('package_manifest_digest_invalid',$r['blockers'],true),'tampered manifest fails closed');
echo 'PASS: 7 scoped/full integrity scenarios, including unrelated drift, security drift, published updates and manifest tampering'."\n";
