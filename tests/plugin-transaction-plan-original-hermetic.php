<?php
/** Original first-party WordPress plugin planning only: never runs install/update. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$args=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function sanitize_text_field($v){return trim($v);}
function wp_normalize_path($v){return str_replace('\\','/',$v);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_has_ability($name){
 return array_key_exists('has_ability',$GLOBALS)
  ? $GLOBALS['has_ability'] : isset($GLOBALS['abilities'][$name]);
}
function wp_get_ability($name){return $GLOBALS['abilities'][$name]??null;}
function plugin_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class DummyPlanner {
 public $result=[];
 public $executed=0;
 public $throw=false;
 public function execute($input){
  $this->executed++;
  if($this->throw)throw new RuntimeException('native planner unavailable');
  return $this->result;
 }
}
$planner=new DummyPlanner;
$planner->result=['contract'=>'mad4b.original-plugin-plan.v1','operation'=>'activate',
 'eligible'=>true,'plan_sha256'=>str_repeat('d',64),'blockers'=>[]];
$GLOBALS['abilities']=['mad4b/plugin-lifecycle-plan'=>$planner];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-plugin-transaction.php';
$c='MAD4B_SCP_Plugin_Transaction';
$input=['action'=>'activate','plugin'=>'example/entry.php',
 'reason'=>'Operator reviewed plugin lifecycle'];
$result=$c::plan($input);
plugin_check(is_array($result)&&$result['eligible']===true
 &&$result['executor']==='mad4b/plugin-activate'
 &&$result['generic_mutation_dispatch']===false
 &&$result['authority_created']===false
 &&$result['mutation_performed']===false
 &&strlen($result['transaction_sha256'])===64,
 'Original WordPress plugin planner projects exact named executor without creating a grant or mutation');
plugin_check($c::plan($input)['transaction_sha256']===$result['transaction_sha256'],
 'Exact original plan envelope is deterministic and never silently chooses another plugin');
$planner->result['eligible']=new WP_Error('origin_evidence_unavailable');
$untrusted=$c::plan($input);
plugin_check($untrusted['eligible']===false,
 'Original error-shaped eligible status cannot mark plugin lifecycle transaction executable');
$planner->result['eligible']=1;
plugin_check($c::plan($input)['eligible']===false,
 'Truthy numeric eligibility cannot replace original typed WordPress policy');
$planner->result['eligible']='true';
plugin_check($c::plan($input)['eligible']===false,
 'String eligibility cannot bypass original planner authority');
$planner->result['eligible']=true;
foreach([new WP_Error('wp_ability_unknown'),null,1,'true'] as $unknown) {
 $GLOBALS['has_ability']=$unknown;
 $before=$planner->executed;
 plugin_check(is_wp_error($c::plan($input))&&$planner->executed===$before,
  'Unknown native WordPress Ability registration cannot call the source planner');
}
unset($GLOBALS['has_ability']);
$planner->throw=true;
plugin_check(is_wp_error($c::plan($input)),
 'Original plugin lifecycle planner exception returns bounded failure rather than fatal');
$planner->throw=false;
foreach([
 ['action'=>new WP_Error('bad_action')],
 ['plugin'=>new WP_Error('bad_plugin')],
 ['provider_id'=>new WP_Error('bad_provider')],
 ['component'=>new WP_Error('bad_component')],
 ['activation_scope'=>new WP_Error('bad_scope')],
 ['reason'=>new WP_Error('bad_reason')],
 ['action'=>['activate']]
] as $bad){
 $before=$planner->executed;
 plugin_check(is_wp_error($c::plan(array_replace($input,$bad)))
  &&$planner->executed===$before,
  'Untrusted or non-scalar operation request cannot crash PHP or reach native planner');
}
$planner->result=['operation'=>'deactivate','eligible'=>true,'blockers'=>[]];
$diff=$c::plan($input);
plugin_check($diff['eligible']===false
 &&in_array('native_lifecycle_operation_mismatch',$diff['blockers'],true),
 'Original lifecycle operation mismatch stays blocked despite a positive native planner');
$planner->result=['operation'=>'activate','eligible'=>true,
 'blockers'=>[new WP_Error('foreign_blocker')]];
plugin_check(is_wp_error($c::plan($input)),
 'Error-shaped blockers cannot be serialized into a valid plugin executor plan');
echo 'PASS: PLUGIN_TRANSACTION_PLAN_ORIGINAL '.($GLOBALS['checks']??0).
 ' original plugin planner, typed eligibility and no-mutation cases'.PHP_EOL;
