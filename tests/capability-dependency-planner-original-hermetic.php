<?php
/** First-party original operation registry planning; no tool execution. */
define('ABSPATH', __DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$meta=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($v){return json_encode($v);}
function wp_has_ability($name){return $GLOBALS['abilities'][$name]??false;}
function dep_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Policy {
 static $read=true;
 static function can_read(){return self::$read;}
}
class MAD4B_SCP_Operation_Registry {
 const CONTRACT='mad4b.operation-registry.v1';
 static $discover;
 static $stages;
 static $bindings;
 static function discover($input){return self::$discover;}
 static function pipeline_profile($id){return self::$stages;}
 static function stage_binding($id,$operation){return self::$bindings[$id]??new WP_Error('no_stage_binding');}
}
$GLOBALS['abilities']=['mad4b/read'=>true,'mad4b/write'=>true,'mad4b/verify'=>true];
MAD4B_SCP_Operation_Registry::$discover=[
 'operation'=>['id'=>'wordpress.content.apply','pipeline_profile'=>'governed_write'],
 'planner'=>'mad4b/plan','executor'=>'mad4b/execute'
];
MAD4B_SCP_Operation_Registry::$stages=[
 ['id'=>'read','type'=>'inspection','required'=>true],
 ['id'=>'approval','type'=>'authorization','required'=>true,'when'=>'business_policy'],
 ['id'=>'execute','type'=>'executor','required'=>true]
];
MAD4B_SCP_Operation_Registry::$bindings=[
 'read'=>['binding_type'=>'native_read','ability'=>'mad4b/read'],
 'approval'=>['binding_type'=>'policy_boundary','ability'=>'mad4b/verify'],
 'execute'=>['binding_type'=>'plan_bound_executor','ability'=>'mad4b/write']
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-capability-dependency-planner.php';
$c='MAD4B_SCP_Capability_Dependency_Planner';
$valid=['operation'=>'wordpress.content.apply','target_kind'=>'post'];
$proof=$c::inspect($valid);
dep_check(is_array($proof)&&$proof['execution_ready']===false
 &&$proof['approval_issued']===false&&$proof['automatic_execution']===false
 &&$proof['mutation_performed']===false&&$proof['authority_effect']==='none',
 'Exact pipeline graph is an observation only and never grants execution');
dep_check(count($proof['nodes'])===3&&count($proof['edges'])===2
 &&$proof['unresolved_dependencies'] &&$proof['missing_registered_abilities']===[],
 'Original pipeline dependency stage order, conditional gates and registered abilities are preserved');
dep_check($proof['graph_sha256']===$c::inspect($valid)['graph_sha256'],
 'Source-backed dependency graph uses a deterministic hash, not a generated policy');
foreach([new WP_Error('policy_unknown'),null,1,'true',false] as $unknown){
 MAD4B_SCP_Policy::$read=$unknown;
 dep_check(is_wp_error($c::inspect($valid)),
  'Original read Policy must return exact true, never truthy unknown evidence');
}
MAD4B_SCP_Policy::$read=true;
foreach([
 ['operation'=>'DELETE /etc/passwd'],
 ['operation'=>''],
 ['operation'=>str_repeat('z',123)],
 ['operation'=>'a'],
 ['operation'=>'wordpress.content.apply','target_kind'=>'<script>']
] as $bad){
 dep_check(is_wp_error($c::inspect($bad)),
  'Malformed capability identity cannot invoke original Operation Registry discovery');
}
foreach([new WP_Error('ability_unknown'),null,1,'true'] as $unknown){
 $GLOBALS['abilities']['mad4b/write']=$unknown;
 $unverified=$c::inspect($valid);
 dep_check(is_array($unverified)
  &&$unverified['nodes'][2]['ability_registration_observed']===false
  &&in_array('mad4b/write',$unverified['missing_registered_abilities'],true)
  &&$unverified['state']==='REGISTERED_DEPENDENCIES_MISSING',
  'Unknown provider Ability cannot be marked registered or ready for an executor stage');
}
$GLOBALS['abilities']['mad4b/write']=true;
$prior=MAD4B_SCP_Operation_Registry::$bindings['approval'];
MAD4B_SCP_Operation_Registry::$bindings['approval']=new WP_Error('original_stage_unavailable');
dep_check(is_wp_error($c::inspect($valid)),
 'Unknown stage binding must not silently remove the original required Policy boundary');
MAD4B_SCP_Operation_Registry::$bindings['approval']=$prior;
MAD4B_SCP_Operation_Registry::$stages=array_fill(0,65,['id'=>'a','type'=>'read']);
dep_check(is_wp_error($c::inspect($valid)),
 'Over 64 first-party pipeline stages cannot bypass bounded dependency review');
MAD4B_SCP_Operation_Registry::$stages=[
 ['id'=>'same','type'=>'read'],['id'=>'same','type'=>'write']
];
dep_check(is_wp_error($c::inspect($valid)),
 'Duplicated canonical stage ID cannot create an ambiguous graph');
echo 'PASS: CAPABILITY_DEPENDENCY_PLANNER '.($GLOBALS['checks']??0).
 ' original operation graph, read gate and unknown-Ability cases'.PHP_EOL;
