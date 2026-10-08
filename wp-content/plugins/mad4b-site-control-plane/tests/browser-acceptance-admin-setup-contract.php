<?php
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct( $code ) { $this->code=$code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
$GLOBALS['browser_setting'] = array();
function get_option( $name, $default = false ) { return isset( $GLOBALS['browser_setting'][$name] ) ? $GLOBALS['browser_setting'][$name] : $default; }
require_once dirname( __DIR__ ) . '/includes/class-mad4b-scp-browser-acceptance-admin-ui.php';
function check_browser( $ok, $message ) { if ( ! $ok ) { fwrite(STDERR,"FAIL $message\n"); exit(1); } }
$c='MAD4B_SCP_Browser_Acceptance_Admin_UI';
check_browser( count($c::managed_executors()) === 4, 'catalog' );
foreach (array('auto','cloudflare','browserbase','browserless','steel') as $p) check_browser( !is_wp_error($c::normalize(array('executor'=>$p,'profile_id'=>'s-1'))), 'valid executor' );
foreach (array(
array('executor'=>'bad','profile_id'=>''),
array('executor'=>array('auto'),'profile_id'=>''),
array('executor'=>'auto','profile_id'=>'https://bad.test'),
array('executor'=>'auto','profile_id'=>'','api_key'=>'secret'),
array('executor'=>'auto','profile_id'=>'','javascript'=>'alert(1)'),
array('executor'=>'auto','profile_id'=>str_repeat('x',65))
) as $bad) check_browser( is_wp_error($c::normalize($bad)), 'denied unsafe input' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'steel','profile_id'=>'site-1','secret'=>'never-expose');
$pref=$c::public_selection();
check_browser( $pref['executor']==='steel' && !isset($pref['secret']), 'credential redaction' );
foreach (array('credential_verified','external_runner_connected','site_provider_registered_by_preference','authorizing') as $k) check_browser( $pref[$k] === false, 'no false authority' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'unknown','profile_id'=>'');
check_browser( $c::selection()['executor']==='auto', 'invalid option fail-closed' );
$main=file_get_contents(dirname(__DIR__).'/mad4b-site-control-plane.php');
$core=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-browser-acceptance-core.php');
check_browser( substr_count($main,"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-browser-acceptance-admin-ui.php';")===1,'one include');
check_browser( substr_count($main,'MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();')===1,'one boot');
check_browser( strpos($core,'MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection()')!==false,'read projection');
echo "MAD4B_BROWSER_ACCEPTANCE_ADMIN_SETUP: PASS\n";
