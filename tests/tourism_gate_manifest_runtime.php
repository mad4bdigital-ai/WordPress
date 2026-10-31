<?php
define('ABSPATH','/tmp/');
class WP_Error {public function __construct(public $code, public $message=''){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function apply_filters($name,$value){return $value;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function absint($v){return abs((int)$v);}
$GLOBALS['env']='production';$GLOBALS['admin']=false;$GLOBALS['writes']=0;
function wp_get_environment_type(){return $GLOBALS['env'];}
function current_user_can($name){return $GLOBALS['admin'];}
function get_current_user_id(){return 5;}
function get_post($id){return $id===17?(object)['ID'=>17,'post_status'=>'draft','post_type'=>'post']:null;}
function get_post_types($args,$output){return ['post'=>'post'];}
function get_post_meta($id,$name,$single){return $GLOBALS['meta'][$id][$name]??null;}
function update_post_meta($id,$name,$value){$GLOBALS['writes']++;$GLOBALS['meta'][$id][$name]=$value;return true;}
function get_posts($args){return [];}
class FakeWPDB {public $prefix='wp_';public function prepare($sql,...$args){return $sql;}public function get_row($sql,$type){return null;}}
$GLOBALS['wpdb']=new FakeWPDB;
define('ARRAY_A','ARRAY_A');
class MAD4B_SCP_Research_Groups {
 public static function group($task,$site,$locale){return ['id'=>17,'group_uid'=>'rg_'.hash('sha256',$site.'|'.$task)];}
 public static function records($id){return [];}
}
class MAD4B_SCP_Schema {
 const VERSION=12;
 public static function critical_ready(){return false;}
 public static function status($full){return ['ready'=>true,'expected_version'=>12,'installed_version'=>11,'physical_integrity'=>['ready'=>false,'missing_tables'=>['content_jobs','outbox']]];}
}
class MAD4B_SCP_Context_Authority {
 public static function status(){return ['ready'=>true,'site_uuid'=>'u','registry_revision'=>2];}
 public static function review_policy(){return ['mode'=>'strict'];}
}
function check($value,$case){if(!$value)throw new Exception($case);}
$root=__DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/';
foreach(['class-mad4b-scp-unified-gate-funnel.php','class-mad4b-scp-tourism-profile-manifest.php','class-mad4b-scp-schema-status-projection.php','class-mad4b-scp-research-task-bridge.php','class-mad4b-scp-tour-workflow-runtime.php'] as $file)require $root.$file;
$g=MAD4B_SCP_Unified_Gate_Funnel::plan();
check($g['execution_authorized']===false&&$g['approval_authorized']===false,'gate plan authorized execution');
check($g['gate_count']>=6,'required gates missing');
$ready=MAD4B_SCP_Unified_Gate_Funnel::readiness(['content.execution'=>true]);
check($ready['execution_authorized']===false&&in_array('content.execution',$ready['mandatory_blockers'],true),'caller invented gate readiness');
$intake=['company_code'=>'exampleco','brand_name'=>'Example','domain'=>'example.test','email'=>'a@example.test','phone_e164'=>'+201111111111','logo_url'=>'','colors'=>[]];
$m=MAD4B_SCP_Tourism_Profile_Manifest::compose($intake,'12345678-1234-1234');
check(!is_wp_error($m)&&$m['native_enrollment_verified']===false&&$m['write_authority_granted']===false&&$m['public_live']===false,'manifest granted write/publication');
check(MAD4B_SCP_Tourism_Profile_Manifest::matches($m,$intake,'12345678-1234-1234'),'intent did not match');
check(is_wp_error(MAD4B_SCP_Tourism_Profile_Manifest::compose(array_merge($intake,['company_code'=>'INVALID!']),'12345678-1234-1234')),'invalid company accepted');
$projection=MAD4B_SCP_Schema_Status_Projection::status();
check($projection['read_only']&&$projection['mutation_performed']===false&&!$projection['ready'],'schema view incorrectly ready or mutable');
check($projection['durable_tables']['content_jobs']===false&&$projection['durable_tables']['execution_outbox']===false,'missing tables concealed');
check(is_wp_error(MAD4B_SCP_Research_Task_Bridge::bind('task','site',17)),'research bound outside staging');
check(is_wp_error(MAD4B_SCP_Tour_Workflow_Runtime::evaluate(17)),'workflow evaluated outside staging');
check($GLOBALS['writes']===0,'production test wrote metadata');
$GLOBALS['env']='staging';$GLOBALS['admin']=true;
check(is_wp_error(MAD4B_SCP_Tour_Workflow_Runtime::checkpoint(17,'unknown',[],str_repeat('a',64))),'unknown stage accepted');
check($GLOBALS['writes']===0,'invalid checkpoint mutated data');
$first=MAD4B_SCP_Research_Task_Bridge::bind('research-task-a','12345678-1234-1234',17);
check(!is_wp_error($first)&&$first['binding_verified']===true,'valid draft binding denied');
$writes=$GLOBALS['writes'];
$conflict=MAD4B_SCP_Research_Task_Bridge::bind('research-task-b','12345678-1234-1234',17);
check(is_wp_error($conflict)&&$GLOBALS['writes']===$writes,'conflicting research group overwrote binding');
$evaluation=MAD4B_SCP_Tour_Workflow_Runtime::evaluate(17);
check(!is_wp_error($evaluation)&&$evaluation['stage_count']===12&&$evaluation['dry_run']===true&&!$evaluation['mutation_performed'],'workflow evaluation not readonly');
check(is_wp_error(MAD4B_SCP_Tour_Workflow_Runtime::checkpoint(17,'approval',['status'=>'ok'],str_repeat('a',64))),'unverified restore epoch allowed checkpoint');
check($GLOBALS['writes']===$writes,'unverified checkpoint mutated metadata');
echo "TOURISM_GATE_MANIFEST_RUNTIME PASS immutable intent, gate denial, schema and checkpoint constraints\n";
