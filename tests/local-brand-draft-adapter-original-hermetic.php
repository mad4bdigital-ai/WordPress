<?php
/** Hermetic native source adapter: no actual WordPress write, account or grant. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$msg='',$meta=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function original_check($ok,$msg){
 if(!$ok){fwrite(STDERR,"FAIL: ".$msg.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
abstract class MAD4B_SCP_Adapter_Base {
 abstract public function id();
 abstract public function label();
 abstract public function is_available();
 abstract public function ability_names();
 abstract public function register_abilities();
 protected function schema($fields,$required=[]){return compact('fields','required');}
 protected function add_ability($name,$label,$method,$permission,$schema=[],$surface='read',$a=true,$b=false,$c=false){
  $GLOBALS['registered'][$name]=['method'=>$method,'permission'=>$permission,
   'surface'=>$surface];
 }
}
class MAD4B_SCP_Policy {
 static $allow=true;
 static function can_mutate(){return self::$allow;}
}
class MAD4B_SCP_Staging_Write_Authority {
 static $ready=['ready'=>true,'candidate_bootstrap_exception'=>false];
 static $calls=0;
 static function current_execution_readiness($ability,$input){
  self::$calls++; return self::$ready;
 }
}
class MAD4B_SCP_Brand_Context_Builder {
 const MAX_DRAFT_BYTES=2048;
 static $preflight=['quality_gate_pass'=>true];
 static $calls=0;
 static function draft_preflight($input){return self::$preflight;}
 static function create_draft($input){
  self::$calls++;
  if(!empty($GLOBALS['draft_throw']))throw new \RuntimeException('source write unknown');
  return $GLOBALS['draft_result'];
 }
 static function append_draft($input){
  self::$calls++;
  if(!empty($GLOBALS['draft_throw']))throw new \RuntimeException('source write unknown');
  return $GLOBALS['draft_result'];
 }
}
class MAD4B_SCP_Artifacts {
 static $read;
 static function get_artifact($input){
  if(!empty($GLOBALS['readback_throw']))throw new \RuntimeException('artifact read unknown');
  return self::$read;
 }
}
class MAD4B_SCP_Content_Jobs {
 static $read;
 static function get_job($input){return self::$read;}
}
class MAD4B_SCP_Recovery_Attempt_Budget {
 static $reserved=0,$finished=0,$outcome='';
 static $reservation=['attempt'=>1,'scope_sha256'=>'PLACEHOLDER','persisted'=>true,'authorizes_context_write'=>false];
 static $finish=true;
 static function reserve($lane,$input){self::$reserved++;return self::$reservation;}
 static function finish($reservation,$outcome){
  self::$finished++;self::$outcome=$outcome;
  if(!empty($GLOBALS['finish_throw']))throw new \RuntimeException('journal finish unknown');
  return self::$finish;
 }
}
MAD4B_SCP_Recovery_Attempt_Budget::$reservation['scope_sha256']=str_repeat('f',64);
$GLOBALS['environment']='staging';$GLOBALS['registered']=[];
$input=[
 'category'=>'tone_of_voice','content'=>'Original brand evidence draft',
 'expected_plan_sha256'=>str_repeat('a',64),
 'evidence_digest'=>str_repeat('b',64),
 'draft_preflight_sha256'=>str_repeat('c',64)
];
MAD4B_SCP_Brand_Context_Builder::$preflight['draft_preflight_sha256']=$input['draft_preflight_sha256'];
$artifact=[
 'artifact_id'=>'art_1','job_id'=>'job_1',
 'artifact_type'=>'brand_context_draft','status'=>'active'
];
$GLOBALS['draft_result']=[
 'artifact'=>$artifact,'brand_core_ready'=>false,
 'review_performed'=>false,'materialization_performed'=>false
];
MAD4B_SCP_Artifacts::$read=['artifact'=>$artifact];
MAD4B_SCP_Content_Jobs::$read=['job'=>['job_id'=>'job_1',
 'content_type'=>'brand_context_generation']];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-local-brand-draft-adapter.php';
$c='MAD4B_SCP_Local_Brand_Draft_Adapter';
$adapter=new $c();
original_check($adapter->is_available()===true &&
 count($adapter->ability_names()['write'])===2,
 'Only original ContentJob local writer with exact draft methods is mounted');
$adapter->register_abilities();
original_check(count($GLOBALS['registered'])===2 &&
 $GLOBALS['registered']['context/brand-draft-create']['surface']==='write',
 'Native original core adapter registration stays a guarded write, never read/public');
$GLOBALS['environment']='production';
original_check(is_wp_error($c::can_propose_local_create($input)),
 'Original Staging-only draft is never a Production write bypass');
$GLOBALS['environment']='staging';
foreach([new WP_Error('policy_unknown'),null,1,'true'] as $unknown){
 MAD4B_SCP_Policy::$allow=$unknown;
 original_check(is_wp_error($c::can_propose_local_create($input)),
  'Unknown native Policy can_mutate proof cannot authorize local Brand draft');
}
MAD4B_SCP_Policy::$allow=true;
foreach([new WP_Error('source_registry_unknown'),null,['ready'=>new WP_Error('gate_unknown')],
 ['ready'=>1],['ready'=>'true'],['ready'=>true,'candidate_bootstrap_exception'=>new WP_Error('unverified')]] as $unknown){
 MAD4B_SCP_Staging_Write_Authority::$ready=$unknown;
 original_check(is_wp_error($c::can_propose_local_append($input)),
  'Unknown site/actor/candidate Readiness is denied without a grant or fallback');
}
MAD4B_SCP_Staging_Write_Authority::$ready=['ready'=>true,'candidate_bootstrap_exception'=>false];
foreach([
 ['category'=>'unregistered'],
 ['content'=>''],
 ['content'=>str_repeat('x',2049)],
 ['expected_plan_sha256'=>str_repeat('!',64)],
 ['evidence_digest'=>'outdated'],
] as $delta){
 original_check(is_wp_error($c::can_propose_local_create(array_replace($input,$delta))),
  'Source category, bounded text and exact receipt digests are necessary for a brand draft');
}
original_check($c::can_propose_local_create($input)===true,
 'Only the exact native authorized candidate and category may propose');
foreach([new WP_Error('preflight_error'),['quality_gate_pass'=>new WP_Error('fake')],['quality_gate_pass'=>1],
 ['quality_gate_pass'=>'true']] as $unknown){
 MAD4B_SCP_Brand_Context_Builder::$preflight=$unknown;
 MAD4B_SCP_Recovery_Attempt_Budget::$reserved=0;
 original_check(is_wp_error($adapter->brand_draft_create($input)) &&
  MAD4B_SCP_Recovery_Attempt_Budget::$reserved===0,
  'Unknown original brand preflight must not reserve durable execution or create content');
}
MAD4B_SCP_Brand_Context_Builder::$preflight=['quality_gate_pass'=>true,
 'draft_preflight_sha256'=>$input['draft_preflight_sha256']];
$stale=$input;$stale['draft_preflight_sha256']=str_repeat('d',64);
original_check(is_wp_error($adapter->brand_draft_append($stale)),
 'Stale append preflight digest cannot modify a previously approved Brand draft');
MAD4B_SCP_Recovery_Attempt_Budget::$reserved=0;
MAD4B_SCP_Recovery_Attempt_Budget::$finished=0;
MAD4B_SCP_Brand_Context_Builder::$calls=0;
MAD4B_SCP_Recovery_Attempt_Budget::$reservation=new WP_Error('attempt_denied');
original_check(is_wp_error($adapter->brand_draft_create($input))
 &&MAD4B_SCP_Brand_Context_Builder::$calls===0,
 'Original Recovery Budget denial must prevent even provisional ContentJob creation');
MAD4B_SCP_Recovery_Attempt_Budget::$reservation=[
 'attempt'=>1,'scope_sha256'=>str_repeat('f',64),
 'persisted'=>true,'authorizes_context_write'=>false];
foreach([null,true,1,'true',['persisted'=>true],
 ['attempt'=>1,'scope_sha256'=>str_repeat('f',64),'persisted'=>new WP_Error('unk'),'authorizes_context_write'=>false],
 ['attempt'=>1,'scope_sha256'=>str_repeat('f',64),'persisted'=>true,'authorizes_context_write'=>true]] as $bad_reservation) {
 MAD4B_SCP_Recovery_Attempt_Budget::$reservation=$bad_reservation;
 MAD4B_SCP_Brand_Context_Builder::$calls=0;
 original_check(is_wp_error($adapter->brand_draft_create($input))
  &&MAD4B_SCP_Brand_Context_Builder::$calls===0,
  'Unknown durable lease receipt cannot trigger an original nonreversible ContentJob');
}
MAD4B_SCP_Recovery_Attempt_Budget::$reservation=[
 'attempt'=>1,'scope_sha256'=>str_repeat('f',64),
 'persisted'=>true,'authorizes_context_write'=>false];
$result=$adapter->brand_draft_create($input);
original_check(is_array($result)&&$result['reversible']===false
 &&$result['review_performed']===false&&$result['materialization_performed']===false
 &&$result['brand_core_ready']===false
 &&MAD4B_SCP_Recovery_Attempt_Budget::$outcome==='succeeded',
 'A matching original Artifact+ContentJob readback creates only a nonreversible unreviewed local draft');
MAD4B_SCP_Artifacts::$read=['artifact'=>array_replace($artifact,['job_id'=>'wrong_job'])];
MAD4B_SCP_Brand_Context_Builder::$calls=0;
$bad=$adapter->brand_draft_create($input);
original_check(is_wp_error($bad)&&MAD4B_SCP_Recovery_Attempt_Budget::$outcome==='uncertain',
 'Cross-job original Artifact readback cannot be accepted as a saved Brand draft');
MAD4B_SCP_Artifacts::$read=['artifact'=>$artifact];
$GLOBALS['draft_throw']=true;
$before=MAD4B_SCP_Recovery_Attempt_Budget::$finished;
$thrown=$adapter->brand_draft_create($input);
original_check(is_wp_error($thrown)
 &&MAD4B_SCP_Recovery_Attempt_Budget::$finished===$before+1
 &&MAD4B_SCP_Recovery_Attempt_Budget::$outcome==='uncertain',
 'Original builder failure after reservation must be durably journaled as uncertain');
$GLOBALS['draft_throw']=false;
$GLOBALS['readback_throw']=true;
$before=MAD4B_SCP_Recovery_Attempt_Budget::$finished;
$thrown=$adapter->brand_draft_create($input);
original_check(is_wp_error($thrown)
 &&MAD4B_SCP_Recovery_Attempt_Budget::$finished===$before+1
 &&MAD4B_SCP_Recovery_Attempt_Budget::$outcome==='uncertain',
 'Original artifact readback exception must preserve retry-budget uncertainty');
$GLOBALS['readback_throw']=false;
$GLOBALS['finish_throw']=true;
$thrown=$adapter->brand_draft_create($input);
original_check(is_wp_error($thrown),
 'Unknown original journal finish exception must not claim a successful draft');
$GLOBALS['finish_throw']=false;
foreach([new WP_Error('journal_unknown'),false,null,1,'true'] as $unknown_finish){
 MAD4B_SCP_Recovery_Attempt_Budget::$finish=$unknown_finish;
 $bad=$adapter->brand_draft_create($input);
 original_check(is_wp_error($bad),'Only exact boolean true from original durable journal can report success');
}
echo 'PASS: LOCAL_BRAND_DRAFT_ORIGINAL '.($GLOBALS['checks']??0).
 ' first-party policy, preflight, CAS and no-blind-retry checks'.PHP_EOL;
