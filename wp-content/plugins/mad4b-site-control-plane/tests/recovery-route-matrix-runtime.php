<?php
/** Hermetic, non-authorizing WordPress vs MCP recovery capability matrix. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['admin'] = true;
$GLOBALS['env'] = 'staging';
$GLOBALS['profile_env'] = 'staging';
$GLOBALS['mounted'] = array(
    'mad4b/deployment-binding-native-readback' => true,
    'mad4b/staging-write-authority-convergence-handshake' => true,
);
$GLOBALS['registered'] = array(
    'mad4b/deployment-binding-native-readback' => true,
    'mad4b/staging-write-authority-convergence-handshake' => true,
    'mad4b/staging-write-authority-convergence-apply' => true,
    'mad4b/deployment-binding-production-status' => true,
);
function add_action( $hook, $cb, $priority = 10 ) {}
function current_user_can( $c ) { return $GLOBALS['admin'] && 'manage_options' === $c; }
function wp_get_environment_type() { return $GLOBALS['env']; }
function wp_has_ability( $name ) { return !empty( $GLOBALS['registered'][$name] ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { private $code; function __construct( $code, $message = '' ) { $this->code = $code; } function get_error_code(){ return $this->code; } }
class MAD4B_SCP_Site_Profile { static function current_environment() { return $GLOBALS['profile_env']; } }
class MAD4B_SCP_Servers { static function ability_is_mounted( $server, $ability ) { return !empty( $GLOBALS['mounted'][$ability]) && ( 'mad4b-read' === $server || 'mad4b-chatgpt' === $server ); } }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-recovery-route-matrix.php';
function check( $ok, $reason ) { if(!$ok){fwrite(STDERR,"FAIL ".$reason."\n");exit(1);} }
$r=MAD4B_SCP_Recovery_Route_Matrix::inspect();
check( is_array($r) && $r['exact_environment'] && count($r['stages'])>=15, 'dynamic matrix exists' );
check( $r['registered_count']===4 && $r['remote_mount_verified_count']===2, 'real registry and mount observations not assumed' );
$seen=array();
foreach($r['stages'] as $item){
  check(!isset($seen[$item['stage']]), 'unique stages');$seen[$item['stage']]=true;
  check($item['remote_authority_granted']===false && $item['execution_performed']===false, 'discovery not authorization');
  if('write_execute'===$item['stage'])
    check($item['ability_registered'] && !$item['remote_mount_verified'] && 'REGISTERED_MCP_NOT_VERIFIED'===$item['state'], 'registered write not equivalent remote mount');
  if('production_host_identity'===$item['stage'])
    check(!$item['environment_applicable'] && 'NOT_APPLICABLE_TO_EXACT_ENVIRONMENT'===$item['state'], 'production not invoked as staging');
}
check(isset($seen['owner_review']) && isset($seen['write_readback']) && isset($seen['context_onboarding']), 'approval and after included');
$GLOBALS['env']='production';$GLOBALS['profile_env']='production';
$prod=MAD4B_SCP_Recovery_Route_Matrix::inspect();
foreach($prod['stages'] as $item){
 if('host_binding_create'===$item['stage'])check(!$item['environment_applicable'], 'production cannot use staging secret creation');
 if('production_host_identity'===$item['stage'])check($item['environment_applicable'], 'production read path works');
}
$GLOBALS['profile_env']='staging';$mismatch=MAD4B_SCP_Recovery_Route_Matrix::inspect();
check(!$mismatch['exact_environment'], 'mismatched profile fails closed');
foreach($mismatch['stages'] as $item)check(!$item['environment_applicable'], 'no routes when environment mismatch');
$GLOBALS['admin']=false;
check(is_wp_error(MAD4B_SCP_Recovery_Route_Matrix::inspect()), 'unprivileged denied');
echo "RECOVERY_ROUTE_MATRIX_DYNAMIC_DISCOVERY_AND_SCOPE: PASS\n";
