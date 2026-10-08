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
array('executor'=>'auto','profile_id'=>'','site_provider_id'=>'https://unsafe.test'),
array('executor'=>'auto','profile_id'=>str_repeat('x',65))
) as $bad) check_browser( is_wp_error($c::normalize($bad)), 'denied unsafe input' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'steel','profile_id'=>'site-1','secret'=>'never-expose');
$pref=$c::public_selection();
check_browser( $pref['executor']==='steel' && !isset($pref['secret']), 'credential redaction' );
foreach (array('credential_verified','external_runner_connected','site_provider_registered_by_preference','authorizing') as $k) check_browser( $pref[$k] === false, 'no false authority' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'auto','profile_id'=>'','site_provider_id'=>'etg-dfsb');
check_browser( $c::public_selection()['site_provider_id'] === 'etg-dfsb', 'site provider preference projected' );
check_browser( '' === $c::public_selection()['configuration_revision'], 'legacy preference has no execution revision' );
$revision='abcdef0123456789abcdef0123456789';
$GLOBALS['browser_setting'][$c::OPTION] = array('executor'=>'auto','site_provider_id'=>'etg-dfsb','profile_id'=>'tours','configuration_revision'=>$revision);
check_browser( $c::public_selection()['configuration_revision'] === $revision, 'revision projected exactly' );
check_browser( is_wp_error( $c::normalize( array('configuration_revision'=>'not-a-revision') ) ), 'invalid revision rejected' );
check_browser( is_wp_error( $c::normalize( array('configuration_revision'=>array('bad')) ) ), 'array revision rejected' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'auto','site_provider_id'=>'site-a','profile_id'=>'royal');
check_browser( $c::runtime_target_guard('site-a','royal') === true, 'exact configured target permitted' );
check_browser( is_wp_error($c::runtime_target_guard('site-b','royal')), 'mismatched selected provider denied' );
check_browser( is_wp_error($c::runtime_target_guard('site-a','other')), 'mismatched profile denied' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'not-configured','profile_id'=>'');
check_browser( is_wp_error($c::runtime_target_guard('site-a','royal')), 'corrupt preference cannot run browser acceptance' );
check_browser( $c::public_selection()['preference_valid'] === false, 'invalid stored preference must be visible to external runner' );
$GLOBALS['browser_setting'][$c::OPTION]=array('executor'=>'unknown','profile_id'=>'');
check_browser( $c::selection()['executor']==='auto', 'invalid option fail-closed' );
$main=file_get_contents(dirname(__DIR__).'/mad4b-site-control-plane.php');
$core=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-browser-acceptance-core.php');
check_browser( substr_count($main,"require_once MAD4B_SCP_DIR . 'includes/class-mad4b-scp-browser-acceptance-admin-ui.php';")===1,'one include');
check_browser( substr_count($main,'MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();')===1,'one boot');
check_browser( strpos($core,'MAD4B_SCP_Browser_Acceptance_Admin_UI::public_selection()')!==false,'read projection');
$remote=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-remote-operation-parity.php');
$staging=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-staging-certification.php');
check_browser( strpos($remote, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::runtime_target_guard( $provider_id, $profile_id )') !== false, 'remote queue must check operator selection' );
check_browser( strpos($remote, "preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', \$provider_id )") !== false, 'remote queue must preserve dot-containing valid provider IDs' );
check_browser( strpos($remote, "preg_match( '/^[a-z0-9][a-z0-9._-]{0,63}$/D', \$profile_id )") !== false, 'remote queue must preserve dot-containing valid profile IDs' );
check_browser( strpos($remote, "sanitize_key( isset( \$input['provider_id'] )") === false, 'queue must never silently strip valid provider characters' );

check_browser( strpos($staging, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::runtime_target_guard( $provider_id, $profile_id )') !== false, 'staging certification must check operator selection' );
check_browser( strpos($main, 'MAD4B_SCP_Browser_Acceptance_Admin_UI::boot();') !== false, 'setup available on admin' );

echo "MAD4B_BROWSER_ACCEPTANCE_ADMIN_SETUP: PASS\n";
