<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code; public function __construct($code,$message=''){$this->code=$code;}public function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($x,$y){}
$GLOBALS['setup_admin']=false;
$GLOBALS['setup_enrolled']=false;
$GLOBALS['setup_environment']='staging';
function current_user_can($cap){return $cap==='manage_options' && $GLOBALS['setup_admin'];}
function get_current_user_id(){return 17;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
class MAD4B_SCP_Site_Profile {
 public static function user_is_enrolled($id){return $id===17 && $GLOBALS['setup_enrolled'];}
 public static function current_environment(){return $GLOBALS['setup_environment'];}
}
class MAD4B_SCP_Developer_Authority {
 public static function status(){return [
  'developer_enabled'=>false,'direct_execution_enabled'=>false,'kill_switch_enabled'=>true,
  'normal_authority'=>['ready'=>false,'state'=>'not_granted'],
  'breakglass_enabled'=>false,'breakglass_authority'=>['ready'=>false,'state'=>'not_granted']
 ];}
}
class MAD4B_SCP_Full_Staging_Authority {
 public static function status(){return [
  'authority_ready'=>false,'operational_ready'=>false,'operational_blockers'=>['owner_attest_pending']
 ];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-authority-setup-ui.php';
$checks=0;
function pass($name,$condition){global $checks;if(!$condition){fwrite(STDERR,"FAIL $name\n");exit(1);}++$checks;echo $name.": PASS\n";}
$flows=MAD4B_SCP_Authority_Setup_UI::workflows();
pass('three_authority_workflows_present',count($flows)===3);
pass('developer_apply_ability_original',$flows['developer']['apply_ability']==='mad4b/developer-authority-apply');
pass('breakglass_apply_ability_original',$flows['developer_breakglass']['apply_ability']==='mad4b/developer-breakglass-authority-apply');
pass('full_authority_apply_ability_original',$flows['full_staging']['apply_ability']==='mad4b/full-staging-authority-apply');
pass('full_authority_staging_only',$flows['full_staging']['environment']==='staging only');
pass('developer_breakglass_distinct_from_raw_sql',strpos($flows['developer_breakglass']['label'],'not raw SQL')!==false);
pass('all_confirmation_fields_visible',count(array_filter($flows,static function($f){return isset($f['fields']['confirmation']);}))===3);
$base=array(
 'expected_plan_sha256'=>str_repeat('a',64),
 'expected_source_commit_sha'=>str_repeat('b',40),
 'expected_site_uuid'=>'123e4567-e89b-12d3-a456-426614174000',
 'expected_environment'=>'local',
 'expected_profile_revision'=>'13',
 'expected_profile_digest'=>str_repeat('c',64),
 'confirmation'=>'PROVISION MAD4B DEVELOPER AGENT',
);
$r=MAD4B_SCP_Authority_Setup_UI::validate_payload('developer',$base);
pass('developer_prepared_without_mutation',is_array($r)&&$r['prepared_only']===true&&$r['plan_verified']===false&&$r['mutation_performed']===false);
pass('revision_cast_to_integer',is_array($r)&&$r['input']['expected_profile_revision']===13);
$b=$base;$b['confirmation']='PROVISION MAD4B DEVELOPER BREAKGLASS';
$r=MAD4B_SCP_Authority_Setup_UI::validate_payload('developer_breakglass',$b);
pass('breakglass_separate_exact_confirmation',is_array($r));
$b['confirmation']=$base['confirmation'];
pass('breakglass_wrong_confirmation_rejected',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('developer_breakglass',$b)));
$b=$base;$b['expected_plan_sha256']=str_repeat('z',64);
pass('invalid_digest_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('developer',$b)));
$b=$base;$b['unexpected_grant']='wildcard';
pass('unknown_field_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('developer',$b)));
$b=$base;$b['expected_environment']='production';
pass('developer_production_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('developer',$b)));
$b=$base;$b['expected_profile_revision']='0';
pass('invalid_profile_revision_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('developer',$b)));
$b=$base;
unset($b['expected_environment']);
$b['expected_build_fingerprint']=str_repeat('d',64);
$b['expected_package_manifest_digest']=str_repeat('e',64);
$b['expected_artifact_identity']=str_repeat('f',40);
$b['confirmation']='ENABLE FULL STAGING AUTHORITY';
$r=MAD4B_SCP_Authority_Setup_UI::validate_payload('full_staging',$b);
pass('full_staging_exact_payload_prepared',is_array($r)&&$r['input']['expected_artifact_identity']===$b['expected_artifact_identity']);
$b['expected_artifact_identity']='x';
pass('invalid_artifact_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('full_staging',$b)));
pass('unrecognized_workflow_denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::validate_payload('raw_sql_breakglass',$base)));
pass('anonymous setup status denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::project_status()));
$GLOBALS['setup_admin']=true;
pass('unregistered admin denied',!MAD4B_SCP_Authority_Setup_UI::authorized());
pass('unregistered admin status denied',is_wp_error(MAD4B_SCP_Authority_Setup_UI::project_status()));
$GLOBALS['setup_enrolled']=true;
pass('enrolled admin allowed to inspect',MAD4B_SCP_Authority_Setup_UI::authorized());
$projection=MAD4B_SCP_Authority_Setup_UI::project_status();
pass('status only no grants',is_array($projection)&&$projection['mutation_performed']===false
 && $projection['no_implicit_apply']===true&&$projection['oauth_bearer_required']===true);
pass('breakglass excludes SQL',$projection['raw_sql_breakglass_not_included']===true);
pass('staging status is not ready',!$projection['developer']['authority_ready']
 && !$projection['full_staging']['authority_ready'] && $projection['full_staging']['eligible_environment']===true);
$GLOBALS['setup_environment']='production';
$production=MAD4B_SCP_Authority_Setup_UI::project_status();
pass('production not staging eligible',is_array($production)
 && $production['full_staging']['eligible_environment']===false && $production['mutation_performed']===false);
echo "SUMMARY pass=$checks fail=0\n";
