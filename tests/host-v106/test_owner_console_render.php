<?php
declare(strict_types=1);
// Isolated WordPress stubs. No live session, keys, options or network.
define('ABSPATH','/test-only/');
$actor=7;
$options=[];
$scripts=[];
$caps=['manage_options','activate_plugins'];
$unified=false;
$menus=[];
function is_admin(){return false;}
function current_user_can($cap){return in_array($cap,$GLOBALS['caps'],true);}
function add_submenu_page($parent,$title,$menu,$cap,$slug,$callback){
 $GLOBALS['menus'][]=['parent'=>$parent,'cap'=>$cap,'slug'=>$slug];
 return 'test-hook';
}
function get_current_user_id(){return $GLOBALS['actor'];}
function is_user_logged_in(){return $GLOBALS['actor']>0;}
function wp_die($s){throw new RuntimeException((string)$s);}
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function esc_html($text){return htmlspecialchars((string)$text,ENT_QUOTES);}
function wp_unslash($str){return $str;}
function sanitize_key($s){return (string)$s;}
function rest_url($s){return 'https://tours.dev.mad4b.com/wp-json/'.$s;}
function wp_make_link_relative($s){return parse_url($s,PHP_URL_PATH);}
function wp_create_nonce($key){return 'fake-test-nonce';}
function wp_json_encode($data,$opts=0){return json_encode($data,$opts|JSON_THROW_ON_ERROR);}
function wp_register_script($name,$src,$dep,$ver,$footer){$GLOBALS['scripts']['src']=$src;}
function wp_enqueue_script($name){$GLOBALS['scripts']['queued']=$name;}
function wp_add_inline_script($name,$contents,$position){$GLOBALS['scripts'][$position]=$contents;}
class wpdb {
 public $options='wp_options';
 function prepare($query,$v){return $query;}
 function esc_like($v){return $v;}
 function get_col($query){return [];}
}
$wpdb=new wpdb();
class MAD4B_Central_Dual_Lane_Registry_V1 {static function central(){return true;}}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const PREFIX='mad4b_test_grant_';
 const TRANSPORT_PREFIX='mad4b_test_transport_';
 const REQUEST_PREFIX='mad4b_test_request_';
}
class MAD4B_Company_Native_WPCLI_Enrollment_V1 {const SLOT='mad4b_test_slot_';}
class MAD4B_Original_Remote_Hostinger_328_V1 {static function active($a,$b){return true;}}
class MAD4B_Original_Native_Operation_Providers_V1 {
 static function first_anchor_bootstrap_intake_registered($op,$scope,$format) {
  return $op==='company.host-authority.anchor.issue'
    &&$scope==='host'&&$format==='host.anchor.ref.v2';
 }}
class MAD4B_SCP_Site_Profile {static function user_is_enrolled($uid){return $uid===7;}}
class MAD4B_Company_Manager_Unified_Administrator_Principal {
 static function verified(){return $GLOBALS['unified'];}
}
class MAD4B_Native_Host_Exact_Grant_V1 {
 static function current_anchor_readback_v2($a,$b) {
  return ['state'=>'MISSING_OR_INVALID','revision'=>0];}}
require dirname(__DIR__,2).'/wp-content/mu-plugins/393-mad4b-host-authority-owner-console.php';
$host='a'.str_repeat('a',31);
$parentId=str_repeat('b',32);
$site='49c562d1-8f2f-456f-b454-26816c6ba4cb';
$options[MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.'all-royal-egypt_staging']=[
 'transport_id'=>$host,'grant_id'=>$parentId,'site_uuid'=>$site];
$options[MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$parentId]=[
 'central_principal_id'=>7,'site_uuid'=>$site];
$options[MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$host]=[
 'central_principal_id'=>7,'company'=>'all-royal-egypt',
 'environment'=>'staging','state'=>'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED',
 'host_id'=>'hosting-2','host_principal'=>'u948953904'];
$ui=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($ui['owner_verified']!==true || $ui['phase']!=='AWAITING_OWNER_APPROVAL')
 throw new RuntimeException('REAL_ORIGINAL_OWNER_SCOPE_NOT_RECOGNIZED');
ob_start();
MAD4B_Host_Authority_Owner_Console_V106::screen();
$page=ob_get_clean();
if(!str_contains($page,'Issue First Host Authority Anchor')
   ||!str_contains($page,'Approve Bootstrap Grant')
   ||!str_contains($page,'Original312 plan SHA-256')
   ||str_contains($page,'http://tours.dev.mad4b.com:8080'))
 throw new RuntimeException('OWNER_PAGE_MISSING_REQUIRED_ACTIONS');
// The actual admin HTML must deliver the owner script after the controls,
 // not merely register a source-less wp_footer script handle.
if(!preg_match('/window\\.MAD4BHostAuthorityV106=(\\{[^\\n]+\\});/', $page,$match))
 throw new RuntimeException('DIRECT_OWNER_JSON_CONFIG_NOT_IN_PAGE');
$decoded=json_decode($match[1],true,512,JSON_THROW_ON_ERROR);
if(($decoded['nonce']??'')!=='fake-test-nonce'
  ||($decoded['restBase']??'')!=='/wp-json/mad4b/v1/central-execution-lanes/all-royal-egypt/'
  ||($decoded['owner']??false)!==true
  ||!str_contains($page,'id="mad4b-host-v108-owner-controls"')
  ||!str_contains($page,'Owner controls ready. Check the approval box')
  ||strpos($page,'id="mad4b-host-v108-owner-controls"') <
    strpos($page,'id="mad4b-ha-grant"'))
 throw new RuntimeException('DIRECT_OWNER_RUNTIME_MISSING_OR_EARLY');
// The existing master_admin role can inspect Company pages, but cannot
// gain Original305 authority from the role alone. A separate original
// Unified Administrator proof may authorize the exact enrolled actor.
MAD4B_Host_Authority_Owner_Console_V106::menu();
$slugs=array_column($menus,'slug');
if(!in_array('mad4b-host-authority',$slugs,true)
   ||!in_array('mad4b-company-host-authority',$slugs,true))
 throw new RuntimeException('DUAL_MASTER_ADMIN_MENU_MISSING');
$caps=['mad4b_manage_company_operations'];
$unified=false;
$ownerOnly=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($ownerOnly['owner_verified']!==false)
 throw new RuntimeException('MASTER_ADMIN_ROLE_MINTED_AUTHORITY');
ob_start();
MAD4B_Host_Authority_Owner_Console_V106::screen();
$companyReadOnly=ob_get_clean();
if(str_contains($companyReadOnly,'id="mad4b-ha-grant"'))
 throw new RuntimeException('UNVERIFIED_MASTER_ADMIN_BUTTON_VISIBLE');
$unified=true;
$companyVerified=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($companyVerified['owner_verified']!==true)
 throw new RuntimeException('REAL_UNIFIED_OWNER_PROOF_NOT_ACCEPTED');
$_GET['page']='mad4b-company-host-authority';
ob_start();
MAD4B_Host_Authority_Owner_Console_V106::screen();
$companyVerifiedHTML=ob_get_clean();
if(!str_contains($companyVerifiedHTML,'id="mad4b-host-v108-owner-controls"')
   ||!str_contains($companyVerifiedHTML,'fake-test-nonce'))
 throw new RuntimeException('COMPANY_OWNER_SCRIPT_NOT_DIRECTLY_RENDERED');
$unified=false;
$caps=['manage_options','activate_plugins'];
$_GET['page']='mad4b-host-authority';
$actor=8;
$notOwner=MAD4B_Host_Authority_Owner_Console_V106::snapshot();
if($notOwner['owner_verified']!==false || $notOwner['phase']!=='OWNER_SESSION_REQUIRED')
 throw new RuntimeException('UNENROLLED_ADMIN_NOT_RESTRICTED');
ob_start();
MAD4B_Host_Authority_Owner_Console_V106::screen();
$readOnly=ob_get_clean();
if(str_contains($readOnly,'id="mad4b-ha-grant"')
   ||str_contains($readOnly,'id="mad4b-host-v108-owner-controls"')
   ||!str_contains($readOnly,'original enrolled WordPress owner'))
 throw new RuntimeException('UNVERIFIED_ADMIN_ACTION_VISIBLE');
echo json_encode([
 'state'=>'PASS','checks'=>14,'owner_session_required'=>true,
 'owner_action_rendered_in_verified_fixture'=>true,
 'no_wp_option_mutations'=>true,
 'no_public_mu_plugin_asset_required'=>true,
 'real_owner_authenticated'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
