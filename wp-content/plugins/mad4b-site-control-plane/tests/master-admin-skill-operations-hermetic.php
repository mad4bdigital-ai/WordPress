<?php
/** Read-only company workspace and admin editor isolation; no WP bootstrap. */
define('ABSPATH','/fixture/');
$GLOBALS['allowed']=false;
$GLOBALS['calls']=0;
$GLOBALS['menus']=[];
function current_user_can($cap){return $GLOBALS['allowed']&&$cap==='mad4b_manage_company_operations';}
function home_url($path='/'){return 'https://company.test'.$path;}
function get_role($name){return (object)['capabilities'=>[
 'mad4b_manage_company_operations'=>true,'manage_woocommerce'=>true]];}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_url($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html__($s,$domain=''){return $s;}
function __($s,$domain=''){return $s;}
function admin_url($p){return 'https://company.test/wp-admin/'.$p;}
function wp_die($s){throw new RuntimeException('DENIED');}
function add_menu_page($a,$b,$c,$d,$e,$f,$g){$GLOBALS['menus'][$d]=$c;}
function add_submenu_page($a,$b,$c,$d,$e,$f){$GLOBALS['menus'][$e]=$d;}
class WP_Error {
 public $code;
 function __construct($code,$description='',array $data=[]){$this->code=$code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class MAD4B_SCP_Admin_UI {const PAGE_SLUG='mad4b-control-plane';}
class MAD4B_SCP_Skill_Registry {
 public static function list_skills(){++$GLOBALS['calls'];return [
 ['name'=>'wordpress-site-diagnostics','level'=>'site','target'=>'_site','enabled'=>true],
 ['name'=>'wordpress-release-orchestration','level'=>'workflow','target'=>'release-orchestration','enabled'=>false]
 ];}
 public static function status(){return ['catalog_enabled'=>true];}
}
class MAD4B_SCP_Skill_Runtime_Certification {
 public static function current_status(){return ['checks'=>['skills_feature_enabled'=>true],'blockers'=>['profile_app_mapping_mismatch']];}
 public static function persisted_status(){return ['ready'=>false,'build_identity_current'=>true];}
 public static function capability_readiness(array $x){return [
  'catalog_read_ready'=>true,'external_app_projection_ready'=>false,'authoring_ready'=>false,
  'editor_disabled_by_policy'=>true,'catalog_blockers'=>[],
  'external_app_blockers'=>['profile_app_mapping_mismatch'],'authoring_blockers'=>[]
 ];}
}
class MAD4B_SCP_Site_Profile {public static function status(){return ['skills_enabled'=>true];}}
require __DIR__.'/../includes/class-mad4b-scp-skills-admin-ui.php';
$n=0;function ok($cond,$msg){global $n;if(!$cond){fwrite(STDERR,"FAIL $msg\n");exit(1);}++$n;echo "PASS $msg\n";}
$r=MAD4B_SCP_Skills_Admin_UI::operations_snapshot();
ok($r instanceof WP_Error && $r->code==='mad4b_master_skills_view_denied','Company permission enforced before reading catalog');
ok($GLOBALS['calls']===0,'Unauthorized request triggers no registry or provider IO');
$GLOBALS['allowed']=true;
$r=MAD4B_SCP_Skills_Admin_UI::operations_snapshot();
ok($r['read_only'] && $r['authority_effect']==='none','Snapshot is explicitly non-authorizing');
ok($r['catalog_count']===2 && $r['catalog_enabled_count']===1,'Enabled and total counts are distinct');
ok($r['profile_skills_enabled']===true,'Live feature state not mistaken for stale persisted certificate');
ok($r['historical_certificate_ready']===false,'Historical certificate remains historical');
ok($r['role_audit']['role_review_required']===true,'Risky WooCommerce role drift exposed');
ok(!array_key_exists('content',$r['skills'][0]),'Skill source/instructions not disclosed on manager dashboard');
ob_start();
MAD4B_SCP_Skills_Admin_UI::register_menu();
$menu=ob_get_clean();
ok(($GLOBALS['menus'][MAD4B_SCP_Skills_Admin_UI::OPERATIONS_PAGE_SLUG]??'')==='mad4b_manage_company_operations',
 'Company dashboard navigation uses explicit company operations capability');
ok(($GLOBALS['menus'][MAD4B_SCP_Skills_Admin_UI::PAGE_SLUG]??'')==='manage_options',
 'Skill authoring retains administrator-only permission');
ob_start();
MAD4B_SCP_Skills_Admin_UI::render_operations_page();
$html=ob_get_clean();
ok(strpos($html,'wordpress-site-diagnostics')!==false,'Manager sees available Skills');
ok(strpos($html,'profile_app_mapping_mismatch')!==false,'Manager sees exact unresolved binding');
ok(strpos($html,'Skill authoring (administrator only)')===false,'Manager has no editor link');
ok(strpos($html,'name="mad4b_skill_action"')===false,'Dashboard never exposes write forms');
ok(strpos($html,'https://company.test')!==false,'Dashboard uses current company rather than source hard-code');
$GLOBALS['allowed']=false;
try {MAD4B_SCP_Skills_Admin_UI::render_operations_page();ok(false,'Expected deny');}
catch(RuntimeException $e){ok(true,'Rendering denies unauthorized caller');}
echo "PASS $n master-admin Skills UI assertions\n";
