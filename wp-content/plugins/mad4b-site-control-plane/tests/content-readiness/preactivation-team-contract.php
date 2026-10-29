<?php
/** Hermetic WordPress-free contract for company team preparation drafts. */
define('ABSPATH','/isolated/');
class WP_Error {
    public function __construct(private string $code,private string $message='',private array $data=[]) {}
    public function get_error_code(){return $this->code;}
    public function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($a,$b,$c=10){}
function current_user_can($cap){return !empty($GLOBALS['manager']);}
function get_current_user_id(){return 8;}
function get_current_blog_id(){return 1;}
function get_option($k,$d=null){return $GLOBALS['opts'][$k]??$d;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function get_role($role){return in_array($role,['editor','operator','shop_manager','company_translation_manager'],true)?(object)['name'=>$role]:null;}
function get_user_by($field,$id){return $GLOBALS['user_objects'][$id]??null;}
function get_users($query=[]){return array_values($GLOBALS['user_objects']);}
function user_can($user,$cap){return $cap==='edit_others_posts' && in_array('editor',$user->roles,true);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function rest_ensure_response($v){return $v;}
function yes($ok,$name){if(!$ok){fwrite(STDERR,"FAIL ".$name."\n");exit(5);}echo "PASS ".$name."\n";}
class MAD4B_SCP_Readiness_Workflow_Options {
 const GROUPS=['media'=>'Media','origin'=>'Origin','wpml'=>'WPML','pricing'=>'Pricing',
  'destinations'=>'Destinations','content'=>'Content','operations'=>'Operations',
  'seo'=>'SEO','general'=>'General'];
 public static function settings(){return ['board_id'=>2,'stage_id'=>9];}
}
class MAD4B_SCP_Readiness_PreActivation {
 const HOOK='mad4b/readiness/scope_digest';
 public static function can_manage(){return current_user_can('manage_options');}
 public static function settings(){return ['tasks'=>['max_per_cycle'=>5]];}
 public static function mock_task($scope){return ['preview_only'=>true,'would_create'=>false,'source_id'=>$scope['scope_id']];}
 public static function mock_digest($batch){return ['preview_only'=>true,'would_send_message'=>false,'batch_id'=>$batch['batch_id']];}
}
class MAD4B_SCP_Readiness_Scopes {
 const TASK_SOURCE='mad4b-readiness-scope';
 public static function build(){return [
  'scope1'=>['scope_id'=>'scope1','batch_id'=>'batch1','group'=>'media','total'=>37,'language'=>'en'],
  'scope2'=>['scope_id'=>'scope2','batch_id'=>'batch1','group'=>'media','total'=>24,'language'=>'en'],
  'scope3'=>['scope_id'=>'scope3','batch_id'=>'batch2','group'=>'pricing','total'=>3,'language'=>'fr'],
 ];}
 public static function batches(){return [
  'batch1'=>['batch_id'=>'batch1','group'=>'media','issue_count'=>61,'work_packet_count'=>2],
  'batch2'=>['batch_id'=>'batch2','group'=>'pricing','issue_count'=>3,'work_packet_count'=>1],
 ];}
}
class MAD4B_SCP_Company_Master_Admin {
 public static function managedStaff($id){return $id===101;}
}
class Req {
 public function __construct(private array $j){}
 public function get_json_params(){return $this->j;}
}
$GLOBALS['opts']=[];
$GLOBALS['manager']=true;
$GLOBALS['user_objects']=[
 101=>(object)['ID'=>101,'display_name'=>'Verified Editor','roles'=>['editor']],
 102=>(object)['ID'=>102,'display_name'=>'Not Managed','roles'=>['editor']],
 103=>(object)['ID'=>103,'display_name'=>'Site Admin','roles'=>['administrator']],
];
require dirname(__DIR__,2).'/includes/class-mad4b-scp-readiness-team-preparation.php';
$c='MAD4B_SCP_Readiness_Team_Preparation';
$d=$c::defaults();
yes($d['state']==='draft'&&!$d['provider_mutations_enabled'],'defaults cannot execute');
yes(!is_wp_error($c::normalize($d)),'default schema is valid');
$bad=$d;$bad['provider_mutations_enabled']=true;
yes(is_wp_error($c::normalize($bad)),'enabled provider is rejected');
$bad=$d;$bad['assignments']['media']['proposed_user_id']=103;
yes(is_wp_error($c::normalize($bad)),'site administrators cannot be proposed');
$bad=$d;$bad['assignments']['media']['proposed_user_id']=102;
yes(is_wp_error($c::normalize($bad)),'non-managed WP user is rejected');
$valid=$d;$valid['assignments']['media']['proposed_user_id']=101;
yes(!is_wp_error($c::normalize($valid)),'managed editorial staff can be drafted');
yes(count($c::candidates())===1,'eligible candidates exclude untrusted accounts');
$plan=$c::plan();
yes($plan['proposals']['media']['issue_count']===61 &&
  $plan['proposals']['pricing']['issue_count']===3,'group summaries preserve native scope counts');
$preview=$c::blueprints();
yes(count($preview['fluent_boards']['template_examples'])===3 &&
    count($preview['bitflows']['samples'])===2 &&
    $preview['all_providers_execution_disabled']===true &&
    $preview['fluent_boards']['template_examples'][0]['would_create']===false,
 'provider blueprints cannot cause live calls');
$stale=$c::rest_patch(new Req(['if_match'=>str_repeat('0',64),'settings'=>$d]));
yes(is_wp_error($stale)&&$stale->get_error_data()['status']===409,'stale write blocked');
$write=$c::rest_patch(new Req(['if_match'=>$c::revision(),'settings'=>$d]));
yes(!is_wp_error($write)&&get_option($c::OPTION)['state']==='draft','draft persists with exact readback');
$rev=$c::revision();
$write2=$c::rest_patch(new Req(['if_match'=>$rev,'settings'=>$valid]));
yes(!is_wp_error($write2) && $c::plan()['proposals']['media']['status']==='draft_needs_human_approval','draft never assigns a live scope');
$GLOBALS['manager']=false;
yes(!$c::can_manage(),'unprivileged user cannot modify');
yes(is_wp_error($c::rest_patch(new Req(['if_match'=>$c::revision(),'settings'=>$d]))),'unprivileged patch blocked');
echo "TEAM_PREACTIVATION_DRAFT_CONTRACT: PASS\n";
