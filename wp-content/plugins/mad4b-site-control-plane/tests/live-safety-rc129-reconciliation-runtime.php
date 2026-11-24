<?php
/** Isolated, non-mutating exact guard tests for the Control Plane rc129 reconciliation. */
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/mad4b-rc129-readonly/' );
if ( ! defined( 'MAD4B_SCP_DIR' ) ) define( 'MAD4B_SCP_DIR', dirname( __DIR__ ) . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $result ) { return $result instanceof WP_Error; } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return preg_replace('/[^a-z0-9_-]/', '', strtolower( (string) $value )); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }
if ( ! class_exists( 'MAD4B_SCP_Transport_Context' ) ) {
    class MAD4B_SCP_Transport_Context { public static $server = 'mad4b-write'; public static function current_server_id() { return self::$server; } }
}
$checked = 0;
$assert = static function ( $passes, $description ) use ( &$checked ) {
    $checked++;
    if ( ! $passes ) { fwrite( STDERR, 'FAIL ' . $description . "\n" ); exit( 1 ); }
    echo 'PASS ' . $description . "\n";
};
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-self-update.php';
$verify = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'trusted_master_release_candidate' );
$identity = array( 'version' => '0.4.0-rc.129', 'source_commit_sha' => str_repeat( 'a', 40 ),
    'archive_sha256' => str_repeat( 'b', 64 ), 'build_fingerprint' => str_repeat( 'c', 64 ),
    'package_manifest_digest' => str_repeat( 'd', 64 ), 'size_bytes' => 430000 );
$manifest = array_merge( $identity, array( 'display_version' => '0.4.0-rc.129',
    'release_verdict_success' => true, 'release_root_trust_verified' => true,
    'published_from_master' => true, 'release_verdict_run_id' => 80 ) );
$assert( is_array( $verify->invoke( null, $identity, $manifest ) ) &&
    $verify->invoke( null, $identity, $manifest )['source_commit_sha'] === $identity['source_commit_sha'], 'exact signed master candidate accepted only after all bindings' );
$bad = $manifest; $bad['archive_sha256'] = str_repeat( 'e', 64 );
$assert( array() === $verify->invoke( null, $identity, $bad ), 'archive hash mismatch fails closed' );
$bad = $manifest; $bad['release_root_trust_verified'] = false;
$assert( array() === $verify->invoke( null, $identity, $bad ), 'unverified root trust fails closed' );
$bad = $manifest; $bad['release_verdict_run_id'] = 0;
$assert( array() === $verify->invoke( null, $identity, $bad ), 'absent release verdict fails closed' );
$bad = $manifest; $bad['size_bytes']++;
$assert( array() === $verify->invoke( null, $identity, $bad ), 'size mismatch fails closed' );

$zipGuard = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'reviewed_archive_no_regression' );
$addon = <<<'ADDON'
<?php 'site_bound_certified_pair'; 'unknown_pair_fallback' => 'shadow'; $certified_pair = 1 === $matching_certificates; 'production_authorized' => false; site_profile_digest
ADDON;
$updater = <<<'UPDATER'
<?php environment_allowed( array( 'staging' ), 'write' ); MAD4B_SCP_Post_Update_Continuation::prepare; MAD4B_Release_Source_Admission::require_for_release;
UPDATER;
$makeZip = static function ( $addonBody, $updaterBody ) {
    $path = tempnam( sys_get_temp_dir(), 'mad4b-guard-' );
    if ( ! $path ) throw new RuntimeException( 'temp_zip_unavailable' );
    $zip = new ZipArchive();
    if ( true !== $zip->open( $path, ZipArchive::OVERWRITE | ZipArchive::CREATE ) ) throw new RuntimeException( 'zip_open_failed' );
    if ( null !== $addonBody ) $zip->addFromString( 'mad4b-site-control-plane/includes/class-mad4b-scp-addon-registry.php', $addonBody );
    if ( null !== $updaterBody ) $zip->addFromString( 'mad4b-site-control-plane/includes/class-mad4b-scp-self-update.php', $updaterBody );
    $zip->close(); return $path;
};
$realAddon = file_get_contents( MAD4B_SCP_DIR . 'includes/class-mad4b-scp-addon-registry.php' );
$realUpdater = file_get_contents( MAD4B_SCP_DIR . 'includes/class-mad4b-scp-self-update.php' );
$tmp = $makeZip( $realAddon, $realUpdater );
$assert( true === $zipGuard->invoke( null, $tmp ), 'actual rc129 reconciled source files satisfy last-admission monotonic archive witness' );
unlink( $tmp );
$tmp = $makeZip( $addon, $updater );
$assert( true === $zipGuard->invoke( null, $tmp ), 'reviewed archive with full witnesses passes structural guard' );
unlink( $tmp );
$tmp = $makeZip( str_replace('site_profile_digest','UNTRUSTED',$addon),$updater );
$r = $zipGuard->invoke( null, $tmp );
$assert( is_wp_error($r) && $r->get_error_code()==='mad4b_forward_archive_regression_denied', 'removed addon protection rejected' );
unlink( $tmp );
$tmp = $makeZip( $addon, null );
$r = $zipGuard->invoke( null, $tmp );
$assert( is_wp_error($r) && $r->get_error_code()==='mad4b_forward_archive_guarded_file_missing', 'missing updater protected file rejected' );
unlink( $tmp );
$apply = new ReflectionMethod( 'MAD4B_SCP_Self_Update', 'apply_verified_archive' );
$r = $apply->invoke( null, '/nonexistent/mad4b-failclosed.zip', array('source_commit_sha'=>str_repeat('a',40)), 'governed_native_release_pull', '', array() );
$assert( is_wp_error($r) && $r->get_error_code()==='mad4b_release_preflight_verifier_missing', 'unknown host signature authority denies before backup/replacement' );

// Isolated authorization class load: read-only structural dependencies are
// deliberately predeclared so their unrelated WordPress bootstrap is not run.
foreach ( array( 'MAD4B_SCP_Approval_Impact_Binding','MAD4B_SCP_Authorization_Decision_Graph',
  'MAD4B_SCP_Execution_Receipt','MAD4B_SCP_Authorization_Target_Fingerprint' ) as $klass ) {
    if (!class_exists($klass)) eval('class '.$klass.' {}');
}
require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php';
$auth_source = file_get_contents( MAD4B_SCP_DIR . 'includes/class-mad4b-scp-authorization.php' );
// Match the exact content-verification branch without parsing unrelated
// policy writes; it must run before resource and provider execution planning.
$gate_position = strpos( $auth_source, 'MAD4B_SCP_Scoped_Build_Verification::governs_content_write' );
$compile_position = strpos( $auth_source, 'MAD4B_SCP_Resource_Constraint_Set::compile' );
$assert( false !== $gate_position && false !== $compile_position && $gate_position < $compile_position
    && false !== strpos( $auth_source, 'mad4b_content_material_build_not_verified' ),
    'scoped content material build verification restored before mutation resource compilation' );
$sha = new ReflectionMethod( 'MAD4B_SCP_Authorization', 'execution_attempt_sha256' );
$claim = array('ability'=>'mad4b/example','provider'=>'elementor','request_id'=>'req-1','target_fingerprint'=>str_repeat('f',64));
$a = $sha->invoke(null,$claim);$b=$sha->invoke(null,array_reverse($claim,true));
$assert( strlen($a)===64 && hash_equals($a,$b), 'execution attempt hash deterministic across associative input order' );
$assert( is_wp_error(MAD4B_SCP_Authorization::execution_attempt_state('bad-attempt')), 'invalid recovery identifier rejected' );
$assert( is_wp_error(MAD4B_SCP_Authorization::execution_attempt_state(str_repeat('b',64))), 'missing durable recovery provider fails closed' );
$assert( !MAD4B_SCP_Authorization::local_approval_planner_execution('mad4b/not-planner'), 'planner bypass limited to exact ability' );
$assert( !MAD4B_SCP_Authorization::local_approval_planner_execution('mad4b/approval-plan'), 'remote transport cannot use local planner bypass' );
MAD4B_SCP_Transport_Context::$server='local-wordpress';
$assert( MAD4B_SCP_Authorization::local_approval_planner_execution('mad4b/approval-plan'), 'local planning route allowed outside remote write servers' );
class MAD4B_Reconcile_Test_Ability {
    private $execute_callback;
    public function __construct($cb) {$this->execute_callback=$cb;}
    public function get_name() {return 'mad4b/safety-fixture';}
}
$cb=static function(){return true;};$other=static function(){return true;};
$ledger = new ReflectionProperty('MAD4B_SCP_Authorization','trusted_execution_boundaries');
$ledger->setValue( array( 'mad4b/safety-fixture'=>array($cb) ) );
$assert( MAD4B_SCP_Authorization::execution_boundary_verified(new MAD4B_Reconcile_Test_Ability($cb)), 'exact closure from local trusted boundary ledger admitted' );
$assert( !MAD4B_SCP_Authorization::execution_boundary_verified(new MAD4B_Reconcile_Test_Ability($other)), 'foreign callback rejected without byte/identity confusion' );
echo 'RECONCILED_LIVE_SAFETY_PASS ' . $checked . "\n";
