<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['quickstart'] = [
  'admin'=>true,'manage_options'=>true,'environment'=>'production',
  'page'=>'mad4b-control-plane-site-profile',
  'origin'=>'https://egypttourgates.com','siteurl'=>'https://egypttourgates.com',
  'existing'=>null,'configured'=>false,'uuid'=>'','audit'=>true
];
function add_action($tag,$handler,$priority=10){$GLOBALS['registered_hook']=$tag;$GLOBALS['registered_handler']=$handler;}
function is_admin(){return $GLOBALS['quickstart']['admin'];}
function current_user_can($cap){return $cap==='manage_options' && $GLOBALS['quickstart']['manage_options'];}
function wp_get_environment_type(){return $GLOBALS['quickstart']['environment'];}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_\-]/','',$x));}
function wp_unslash($x){return $x;}
function home_url($s='/'){return $GLOBALS['quickstart']['origin'].'/';}
function site_url($s='/'){return $GLOBALS['quickstart']['siteurl'].'/';}
function untrailingslashit($x){return rtrim($x,'/');}
function get_option($x,$d=null){return $GLOBALS['quickstart']['existing'];}
function esc_html__($s,$d=''){return htmlspecialchars($s,ENT_QUOTES);}
function __($s,$d=''){return $s;}
function esc_url($s){return htmlspecialchars($s,ENT_QUOTES);}
function admin_url($s=''){return 'https://example.test/wp-admin/'.$s;}
function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="UNIT_TEST_NONCE">';$GLOBALS['nonce_action']=$action;}
function submit_button($label,$type,$name,$wrap){echo '<button type="submit">'.htmlspecialchars($label,ENT_QUOTES).'</button>';}
class MAD4B_SCP_Site_Profile {
  public const OPTION='mad4b_scp_site_profile_v2';
  public static function status(){return ['configured'=>$GLOBALS['quickstart']['configured'],'site_uuid'=>$GLOBALS['quickstart']['uuid']];}
}
class MAD4B_SCP_Site_Profile_Admin {public const ACTION_SAVE='mad4b_site_profile_save';}
class MAD4B_SCP_Audit {public static function storage_status(){return ['ready'=>$GLOBALS['quickstart']['audit']];}}
require dirname(__DIR__).'/wp-content/mu-plugins/502-mad4b-production-identity-quickstart.php';
$_GET['page']='mad4b-control-plane-site-profile';
$c='MAD4B_Production_Identity_Quickstart_V1';$passed=0;
$check=function($test,$message)use(&$passed){if(!$test)throw new RuntimeException($message);$passed++;};
$check($GLOBALS['registered_hook']==='admin_notices','only admin notice hook');
$check($c::eligible(),'allowed administrator of unconfigured exact Production site');
ob_start();$c::render();$html=ob_get_clean();
$check(strpos($html,'value="mad4b_site_profile_save"')!==false,'original save action');
$check(strpos($html,'value="production"')!==false,'explicit production environment');
$check(strpos($html,'value="profile_only"')!==false,'no host-managed environment change');
$check(strpos($html,'name="expected_revision" value="0"')!==false,'fixed initial revision');
$check(strpos($html,'name="expected_profile_digest" value=""')!==false,'empty initial digest');
$check(strpos($html,'_wpnonce')!==false && $GLOBALS['nonce_action']==='mad4b_site_profile_save','original nonce');
$check(strpos($html,'name="write_enabled"')===false,'no governed write flag');
$check(strpos($html,'name="production_write_confirmed"')===false,'no production write confirmation');
$check(strpos($html,'asdk_app_')===false,'no mandatory ChatGPT App');
foreach([
  ['admin',false],['manage_options',false],['environment','staging'],
  ['page','other'],['origin','https://staging.egypttourgates.com'],
  ['siteurl','https://other.example'],['existing',['pending_audit'=>true]],
  ['existing','invalid'],['configured',true],['uuid','already-configured'],
  ['audit',false]
] as [$key,$bad]){
  $before=$GLOBALS['quickstart'][$key];$GLOBALS['quickstart'][$key]=$bad;
  if($key==='page')$_GET['page']=$bad;
  $check(!$c::eligible(),'guard rejects '.$key);
  $GLOBALS['quickstart'][$key]=$before;
  if($key==='page')$_GET['page']=$before;
}
$GLOBALS['quickstart']['origin']='https://tourism.almallahgroup-mg.com';
$GLOBALS['quickstart']['siteurl']='https://tourism.almallahgroup-mg.com';
$check($c::eligible(),'other exact enrolled Production origin');
echo json_encode(['contract'=>$c::CONTRACT,'passed'=>$passed,'writes'=>0,'uuid_generated_by_quickstart'=>false]),"\n";
