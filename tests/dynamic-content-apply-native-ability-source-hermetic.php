<?php
/**
 * Original Dynamic Content Adapter Ability registration, schema and denied
 * execution. Native Adapter Base, not an imitation. No WP posts, Meta,
 * taxonomy, OAuth, network, Site Profile, grants or database changes.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['native_registered']=[];
$GLOBALS['native_mutations']=0;
$GLOBALS['native_user_can']=false;
function add_action(...$args){}
function add_filter(...$args){}
function apply_filters($name,$default){return $default;}
function sanitize_key($name){return strtolower((string)$name);}
function absint($v){return abs((int)$v);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_has_ability($name){return isset($GLOBALS['native_registered'][$name]);}
function wp_register_ability($name,$cfg){
 if(isset($GLOBALS['native_registered'][$name]))throw new RuntimeException('Duplicate WordPress Ability');
 $GLOBALS['native_registered'][$name]=$cfg;
}
function is_wp_error($val){return $val instanceof WP_Error;}
function post_type_exists($pt){return $pt==='tours-and-activities';}
function get_post_type_object($pt){
 return (object)['show_ui'=>true,'public'=>true,'show_in_rest'=>true,
 'cap'=>(object)['create_posts'=>'edit_posts','edit_posts'=>'edit_posts']];
}
function wp_get_environment_type(){return 'local';}
function current_user_can($cap){return $GLOBALS['native_user_can'];}
function get_post_types(){return ['tours-and-activities'=>'tours-and-activities'];}
function wp_insert_post($post){++$GLOBALS['native_mutations'];throw new RuntimeException('Content write forbidden');}
function wp_set_object_terms(){++$GLOBALS['native_mutations'];throw new RuntimeException('Taxonomy write forbidden');}
class WP_Error{
 private $code,$data;
 public function __construct($code,$message='',$data=[]){$this->code=$code;$this->data=$data;}
 public function get_error_code(){return $this->code;}
 public function get_error_data($code=''){return $this->data;}
}
class MAD4B_SCP_Policy{
 public static function can_read(){return true;}
 public static function can_plan_mutations(){return true;}
 public static function can_native_content_draft($input){return false;}
 public static function can_mutate(){return true;}
}
class MAD4B_SCP_Market_Growth_Policies{}
class MAD4B_SCP_Market_Content_Exchange{}
function ncheck($ok,$reason){if(!$ok)throw new RuntimeException('FAIL DYNAMIC NATIVE BUNDLE: '.$reason);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-adapter-base.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dynamic-content-adapter.php';
$a=new MAD4B_SCP_Dynamic_Content_Adapter();
ncheck($a->is_available()===true,'Original native WordPress APIs in test fixture must enable core Adapter');
$a->register_abilities();
$name=MAD4B_SCP_Dynamic_Content_Adapter::APPLY;
$b=$GLOBALS['native_registered'][$name]??null;
ncheck(is_array($b),'Original content-apply-bundle must be registered by dynamic Adapter');
ncheck($b['category']==='mad4b-dynamic-content'
 && $b['meta']['mcp']['surface']==='content'
 && $b['meta']['annotations']['readonly']===false
 && $b['meta']['annotations']['destructive']===true
 && $b['meta']['annotations']['idempotent']===false
 && $b['input_schema']['additionalProperties']===false,
 'Native content write metadata must not masquerade as read or an idempotent operation');
$schema=$b['input_schema'];
foreach(['post_type','operation_key','post','expected_pipeline_settings_sha256','expected_bundle_sha256'] as $required)
 ncheck(in_array($required,$schema['required'],true),'Exact source binding missing: '.$required);
ncheck($schema['properties']['post']['properties']['post_status']['enum']===['draft','pending'],
 'Content draft apply must exclude direct publish/private in original schema');
ncheck(in_array($name,$a->ability_names()['content'],true)
 && !in_array($name,$a->ability_names()['read'],true)
 && $a->mutation_ability_runtime_eligibility($name)===true,
 'Original source declares only a content mutation candidate, not an approval');
ncheck(is_wp_error($a->mutation_ability_runtime_eligibility('mad4b/foreign-mutator')),
 'Unknown operation cannot inherit native Content mutating rights');
$bad=['post_type'=>'bad-record','operation_key'=>'fixture-draft-2026',
 'post'=>['post_title'=>'Ignore this'], 'expected_pipeline_settings_sha256'=>str_repeat('a',64),
 'expected_bundle_sha256'=>str_repeat('b',64)];
$pre=$a->can_apply_bundle($bad);
ncheck(is_wp_error($pre)&&$pre->get_error_code()==='mad4b_dynamic_post_type_missing',
 'Original validate_bundle must reject foreign content type without mutation');
$direct=call_user_func($b['permission_callback'],$bad);
ncheck(is_wp_error($direct)
 && $direct->get_error_code()==='mad4b_dynamic_post_type_missing',
 'Original mutation permission must enforce content schema before any grant');
$bad['post_type']='tours-and-activities';
$GLOBALS['native_user_can']=false;
$next=call_user_func($b['permission_callback'],$bad);
ncheck(is_wp_error($next),
 'Invalid pipeline, user capability or missing source grants must fail closed');
ncheck($GLOBALS['native_mutations']===0,'Original class source registration cannot write posts');
// The real source must not report an ordinary retryable error if content
// changed successfully but the final Operation Journal commit failed.
ncheck(method_exists($a,'completion_journal_uncertain'),
 'Original adapter must classify post-write completion-journal loss as recovery required');
$failure=new WP_Error('mad4b_operation_journal_commit_uncertain','',[
 'reconciliation_required'=>true,'cause_code'=>'db_commit_unverified']);
$operation=['operation_id'=>str_repeat('a',64),
 'operation_key'=>'fixture-draft-2026',
 'operation_binding_sha256'=>str_repeat('b',64)];
$completion=new ReflectionMethod($a,'completion_journal_uncertain');
if(PHP_VERSION_ID<80100)$completion->setAccessible(true);
$report=$completion->invoke($a,$failure,$operation,['post_id'=>88]);
ncheck(is_wp_error($report)
 && $report->get_error_code()==='mad4b_dynamic_completion_journal_unverified',
 'Unexpected success/failure marker for lost post-write journal');
$data=$report->get_error_data();
ncheck(is_array($data)
 && ($data['blind_retry_allowed']??null)===false
 && ($data['recovery_required']??null)===true
 && ($data['reconciliation_required']??null)===true
 && ($data['post_id']??null)===88
 && ($data['operation_id']??null)===$operation['operation_id']
 && ($data['operation_binding_sha256']??null)===$operation['operation_binding_sha256']
 && ($data['content_mutation_may_have_completed']??null)===true
 && ($data['publication_authorized']??null)===false
 && $GLOBALS['native_mutations']===0,
 'A lost completion receipt cannot permit blind retry or falsely claim rollback');
echo "DYNAMIC_CONTENT_APPLY_NATIVE: PASS registration, strict denied mutation and uncertain completion receipt\n";
