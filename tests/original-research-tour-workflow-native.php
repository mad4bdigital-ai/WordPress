<?php
/** Original Research Task and Tour workflow: hermetic owned draft and receipts. */
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
 private $code;
 public function __construct($c,$m='',$data=null){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($x){return abs((int)$x);}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_get_environment_type(){return $GLOBALS['tour_environment'];}
function get_current_user_id(){return 17;}
function current_user_can($cap,$post=0){return !empty($GLOBALS['tour_permissions'][$cap]);}
function get_post($id){return $GLOBALS['tour_post_id']===$id?$GLOBALS['tour_post']:null;}
function get_posts($args){return $GLOBALS['tour_attachment_ids'];}
function get_post_meta($post,$key,$single=false){
 return $GLOBALS['tour_post_meta'][$post][$key]??'';
}
function update_post_meta($post,$key,$value){
 $GLOBALS['tour_meta_writes']++;
 if(!$GLOBALS['tour_meta_writable'])return false;
 $GLOBALS['tour_post_meta'][$post][$key]=$value;
 return true;
}
final class MAD4B_SCP_Site_Profile {
 public static function status(){return $GLOBALS['tour_site_profile'];}
}
final class MAD4B_SCP_Research_Groups {
 public static function group($task,$site,$locale){
   if($GLOBALS['tour_group_error'])return new WP_Error('group_identity_unavailable');
   return array('group_uid'=>'group-original-1','id'=>17,'task_key'=>$task,
    'site_uuid'=>$site,'source_locale'=>$locale);
 }
 public static function schema_ready(){return $GLOBALS['tour_schema_ready'];}
 public static function records($id){
  if($GLOBALS['tour_records_error'])return new WP_Error('records_unavailable');
  return array(array('provider_id'=>'firecrawl','locale'=>'en'));
 }
}
final class MAD4B_SCP_Firecrawl_Provider {
 public static function probe(){return array('ready'=>$GLOBALS['tour_firecrawl_ready']);}
}
final class MAD4B_SCP_Restore_Epoch {
 public static function status($a=false,$b=true){return array(
  'ready'=>$GLOBALS['tour_restore_ready'],'epoch'=>23);}
}
final class OriginalDb {
 public $prefix='wp_';
 public function prepare($sql,$value){return str_replace('%s','"'.$value.'"',$sql);}
 public function get_row($query,$mode){return $GLOBALS['tour_research_group_record'];}
}
$wpdb=new OriginalDb();
function review_assert($value,$reason){
 if(!$value){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['tour_cases']=($GLOBALS['tour_cases']??0)+1;
}
function reset_tour(){
 $GLOBALS['tour_environment']='staging';
 $GLOBALS['tour_permissions']=array('manage_options'=>true,'edit_post'=>true);
 $GLOBALS['tour_site_profile']=array('authority_ready'=>true,'origin_match'=>true);
 $GLOBALS['tour_post_id']=42;
 $GLOBALS['tour_post']=(object)array('ID'=>42,'post_type'=>'tours-and-activities','post_status'=>'draft');
 $GLOBALS['tour_post_meta']=array();$GLOBALS['tour_meta_writes']=0;
 $GLOBALS['tour_meta_writable']=true;
 $GLOBALS['tour_group_error']=false;$GLOBALS['tour_schema_ready']=true;
 $GLOBALS['tour_records_error']=false;$GLOBALS['tour_restore_ready']=true;
 $GLOBALS['tour_firecrawl_ready']=true;$GLOBALS['tour_attachment_ids']=array(100);
 $GLOBALS['tour_research_group_record']=array('id'=>17,'task_key'=>'research-17',
  'group_uid'=>'group-original-1','site_uuid'=>'11111111-1111-4111-8111-111111111111',
  'source_locale'=>'en');
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-research-task-bridge.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-tour-workflow-runtime.php';
reset_tour();
$bridge='MAD4B_SCP_Research_Task_Bridge';
$workflow='MAD4B_SCP_Tour_Workflow_Runtime';
$site='11111111-1111-4111-8111-111111111111';
$bound=$bridge::bind('research-17',$site,42);
review_assert(is_array($bound)&&$bound['binding_verified']===true
 && $GLOBALS['tour_meta_writes']===2,
 'First-party tour draft may bind to exact recorded research group');
$again=$bridge::bind('research-17',$site,42);
review_assert(is_array($again)&&$again['group_uid']==='group-original-1',
 'Same exact research group can be read back idempotently');
$GLOBALS['tour_post_meta'][42][$bridge::BINDING_META]='foreign-group-5';
$prior_writes=$GLOBALS['tour_meta_writes'];
review_assert(is_wp_error($bridge::bind('research-17',$site,42))
 && $GLOBALS['tour_meta_writes']===$prior_writes,
 'Foreign research group may never silently overwrite bound draft');
$GLOBALS['tour_post_meta'][42][$bridge::BINDING_META]='group-original-1';
$GLOBALS['tour_site_profile']['authority_ready']=false;
review_assert(is_wp_error($bridge::bind('research-17',$site,42))
 && is_wp_error($bridge::preflight(42)),
 'Unready site cannot bind research or view task preflight');
$GLOBALS['tour_site_profile']=new WP_Error('missing_site_profile');
review_assert(is_wp_error($bridge::bind('research-17',$site,42))
 && is_wp_error($bridge::preflight(42)),
 'Original Site Profile error cannot masquerade as an authorized source');
$GLOBALS['tour_site_profile']=array('authority_ready'=>true,'origin_match'=>true);
$GLOBALS['tour_permissions']['manage_options']=false;
review_assert(is_wp_error($bridge::bind('research-17',$site,42)),
 'Site manager permission remains independent of schema/host');
$GLOBALS['tour_permissions']['manage_options']=true;
$GLOBALS['tour_permissions']['edit_post']=false;
review_assert(is_wp_error($bridge::bind('research-17',$site,42)),
 'Uneditable tour post cannot be bound to original research');
$GLOBALS['tour_permissions']['edit_post']=true;
$GLOBALS['tour_post']->post_status='publish';
review_assert(is_wp_error($bridge::bind('research-17',$site,42)),
 'Published tour never qualifies as a draft Research Task target');
$GLOBALS['tour_post']->post_status='draft';
$GLOBALS['tour_group_error']=true;
review_assert(is_wp_error($bridge::bind('research-17',$site,42)),
 'Research source provider error propagates without fake group');
$GLOBALS['tour_group_error']=false;
$GLOBALS['tour_schema_ready']=false;
review_assert(is_wp_error($bridge::preflight(42)),
 'Uninstalled research tables never give false-positive source evidence');
$GLOBALS['tour_schema_ready']=true;
$GLOBALS['tour_records_error']=true;
review_assert(is_wp_error($bridge::preflight(42)),
 'Original record store errors must not produce successful stage evidence');
$GLOBALS['tour_records_error']=false;
$pre=$bridge::preflight(42);
review_assert(is_array($pre)&&$pre['mutation_performed']===false
 && $pre['publication_ready']===false
 && count($pre['stages'])===12
 && in_array('approval_missing',$pre['blocking_reasons'],true),
 'Research preflight projects all original stages but never grants publication');
$review=$workflow::evaluate(42);
review_assert(is_array($review) && $review['stage_count']===12
 && $review['dry_run']===true && $review['mutation_performed']===false
 && $review['preflight']['publication_authorized']===false
 && $review['preflight']['write_authorized']===false,
 'Original twelve-stage Tour Workflow remains a no-side-effect plan');
$GLOBALS['tour_restore_ready']=false;
$held=$workflow::evaluate(42);
review_assert(in_array('restore_epoch_not_ready',$held['blockers'],true)
 && is_wp_error($workflow::checkpoint(42,'source_discovery',array('ready'=>true),str_repeat('a',64))),
 'Unavailable Restore Epoch blocks stage checkpoint before write');
$GLOBALS['tour_restore_ready']=true;
$receipt=array('status'=>'evidence_present','source_sha256'=>str_repeat('a',64));
$hash=str_repeat('b',64);
$first=$workflow::checkpoint(42,'source_discovery',$receipt,$hash);
review_assert(is_array($first)&&$first['reused']===false
 && $first['checkpoint']['publication_authorized']===false,
 'Original stage checkpoint persists bounded receipt without publication');
$before=$GLOBALS['tour_meta_writes'];
$repeat=$workflow::checkpoint(42,'source_discovery',$receipt,$hash);
review_assert(is_array($repeat)&&$repeat['reused']===true
 && $GLOBALS['tour_meta_writes']===$before,
 'Exact checkpoint reuses verified first-party receipt without repeat write');
foreach(array('not_a_stage','wpml.sql','publish_now') as $stage){
 review_assert(is_wp_error($workflow::checkpoint(42,$stage,$receipt,$hash)),
   'Unknown stage cannot be injected: '.$stage);
}
review_assert(is_wp_error($workflow::checkpoint(42,'source_discovery',$receipt,'bad')),
 'Original stage checkpoint requires exact dependency SHA256');
$GLOBALS['tour_environment']='production';
review_assert(is_wp_error($workflow::evaluate(42))
 && is_wp_error($workflow::checkpoint(42,'source_discovery',$receipt,$hash)),
 'Production cannot inherit the Staging-only WordPress checkpoint writer');
echo 'PASS: ORIGINAL_RESEARCH_TOUR_WORKFLOW '.$GLOBALS['tour_cases'].
  ' source-bound task and stage receipt assertions'.PHP_EOL;
