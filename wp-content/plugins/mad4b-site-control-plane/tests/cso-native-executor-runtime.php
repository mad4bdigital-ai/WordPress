<?php
/** CSO native approval + idempotency + indeterminate effect test doubles. */
define('ABSPATH','/');
class WP_Error {private $code;public function __construct($c,$m='',$d=null){$this->code=$c;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function maybe_serialize($x){return serialize($x);}
function get_option($key,$default=null){return $GLOBALS['dbrows'][$key]??$default;}
function add_option($key,$value,$unused='',$autoload=false){
 if(array_key_exists($key,$GLOBALS['dbrows']))return false;
 $GLOBALS['dbrows'][$key]=$value;return true;
}
function wp_cache_delete($key,$group){return true;}
class FakeDB {
 public $options='wp_options';public $last_error='';
 public function prepare($sql,...$args){return array($sql,$args);}
 public function query($packed){
  if(!is_array($packed))throw new RuntimeException('bad SQL');
  list($sql,$args)=$packed;
  if(strpos($sql,'UPDATE ')!==0)throw new RuntimeException('unexpected SQL');
  list($next,$key,$old)=$args;
  if(!isset($GLOBALS['dbrows'][$key])||serialize($GLOBALS['dbrows'][$key])!==$old)return 0;
  $GLOBALS['dbrows'][$key]=unserialize($next);return 1;
 }
}
$GLOBALS['wpdb']=new FakeDB();$GLOBALS['dbrows']=array();
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64));
$GLOBALS['write_enabled']=true;$GLOBALS['session']=true;$GLOBALS['policy']=true;
$GLOBALS['ticket']=array();$GLOBALS['revision']='revision-old';
$GLOBALS['title']='Old';$GLOBALS['executions']=0;$GLOBALS['uncertain']=false;
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return $GLOBALS['write_enabled'];}
 static function first_party_session(){return $GLOBALS['session'];}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($s){return $s===$GLOBALS['scope']?true:self::error('DRIFT');}
 static function safe_data($v){return is_array($v)&&!isset($v['api_key']);}
 static function digest($x){return hash('sha256',json_encode($x));}
 static function error($code){return new WP_Error($code);}
 static function seal($m,$c){return array('material'=>$m,'proof'=>hash_hmac('sha256',$c.json_encode($m),'testkey'));}
 static function unseal($s,$c){
  return is_array($s)&&isset($s['material'],$s['proof'])&&
   hash_equals($s['proof'],hash_hmac('sha256',$c.json_encode($s['material']),'testkey'))
   ?$s['material']:self::error('TAMPER');
 }
}
class MAD4B_SCP_CSO_Changes {const CONTRACT='mad4b.cso.change-plan.v1';}
class MAD4B_SCP_Policy {static function can_mutate(){return $GLOBALS['policy'];}}
class MAD4B_SCP_Operational_Integrity {
 static function capture(){return array('scope'=>$GLOBALS['scope']);}
 static function assert_unchanged($s,$write){return $GLOBALS['policy']?true:new WP_Error('REVOKED');}
}
class MAD4B_SCP_Write_Runtime_Certification {
 static function current_status(){return array('ready'=>$GLOBALS['certified']??true,'current_truth'=>true);}
}
class MAD4B_SCP_Database_Topology {
 static function assert_write_ready($strict){return array('ready'=>true);}
}
class MAD4B_SCP_Agent_Registry {
 static function get_agent_by_public_id($id){return $id==='agent-demo'?
  array('id'=>7,'public_id'=>$id,'status'=>'enabled'):null;}
}
class MAD4B_SCP_Approval_Tickets {
 static function create_pending($agent,$server,$ability,$provider,$fp,$input,$class,$reason,$ttl){
  $id='123e4567-e89b-42d3-a456-426614174000';
  if(!empty($GLOBALS['ticket']))$id='123e4567-e89b-42d3-a456-426614174001';
  $GLOBALS['ticket'][$id]='pending';return array('ticket_id'=>$id,'status'=>'pending');
 }
 static function authorize_exact($id,...$args){
  return ($GLOBALS['ticket'][$id]??'')==='approved'?array('ok'=>true):new WP_Error('NOT_APPROVED_OR_REPLAY');
 }
 static function claim_exact($id,...$args){
  if(($GLOBALS['ticket'][$id]??'')!=='approved')return new WP_Error('REPLAY');
  $GLOBALS['ticket'][$id]='executing';return array('status'=>'executing');
 }
 static function finalize_claim($id,$status){
  if(($GLOBALS['ticket'][$id]??'')!=='executing')return new WP_Error('BAD_TICKET_STATE');
  $GLOBALS['ticket'][$id]=$status;return array('status'=>$status);
 }
}
class FakeAbility {
 function get_meta(){return array('mcp'=>array('mad4b_cso_certified_write'=>true,'governed_write'=>true),'annotations'=>array('readonly'=>false));}
 function check_permissions($payload){return MAD4B_SCP_CSO_Native_Executor::native_permit_matches($payload);}
 function execute($payload){
  if(!MAD4B_SCP_CSO_Native_Executor::native_permit_matches($payload,true))return new WP_Error('NO_PERMIT');
  $GLOBALS['executions']++;$GLOBALS['title']=$payload['values']['title'];
  $GLOBALS['revision']='revision-new-'.$GLOBALS['executions'];
  return $GLOBALS['uncertain']?new WP_Error('AFTER_WRITE_UNCERTAIN'):array('status'=>'written');
 }
}
class MAD4B_SCP_Execution_Fence {
 static function final_execution_wrapper_verified($n){return $n==='mad4b-cso/fixture-write';}
}
function wp_get_ability($n){return $n==='mad4b-cso/fixture-write'?new FakeAbility():null;}
class MAD4B_SCP_CSO_Storage_Adapters {
 static function snapshot($provider,$target){
  if($provider!=='fixture-post'||$target!==array('post_id'=>22))return new WP_Error('FOREIGN');
  return array('revision'=>$GLOBALS['revision'],
   'descriptor'=>array('descriptor_sha256'=>str_repeat('c',64)),
   'values'=>array('title'=>$GLOBALS['title']));
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-native-executor.php';
function ck($x,$label){if(!$x){fwrite(STDERR,'FAIL: '.$label."\n");exit(1);}}
$m=array('contract'=>MAD4B_SCP_CSO_Changes::CONTRACT,
 'scope_fingerprint'=>$GLOBALS['scope']['binding_sha256'],
 'actor_sha256'=>$GLOBALS['scope']['actor_sha256'],
 'provider_id'=>'fixture-post','target'=>array('post_id'=>22),
 'native_write_ability'=>'mad4b-cso/fixture-write',
 'descriptor_sha256'=>str_repeat('c',64),'expected_revision'=>'revision-old',
 'values'=>array('title'=>'New'),'field_changes'=>array(), 'expires_at'=>time()+300,
 'write_authorized'=>false);
$sealed=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$ability=wp_get_ability('mad4b-cso/fixture-write');
ck(is_wp_error($ability->execute(array('values'=>array('title'=>'Injected')))),'direct bypass denied');
ck(!MAD4B_SCP_CSO_Native_Executor::native_permit_matches(array()),'no permission outside executor');
$p=MAD4B_SCP_CSO_Native_Executor::approval_plan($sealed,'Reviewed one post title','agent-demo');
ck(!is_wp_error($p)&&$p['status']==='pending'&&$GLOBALS['executions']===0,'pending without write');
$id=$p['ticket_id'];
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::commit($sealed,array('ticket_id'=>$id,'agent_public_id'=>'agent-demo'))),'unapproved denied');
ck($GLOBALS['executions']===0&&count($GLOBALS['dbrows'])===0,'no journal/effect without approval');
$GLOBALS['ticket'][$id]='approved';
$r=MAD4B_SCP_CSO_Native_Executor::commit($sealed,array('ticket_id'=>$id,'agent_public_id'=>'agent-demo'));
ck(!is_wp_error($r)&&$r['status']==='verified'&&$GLOBALS['ticket'][$id]==='used','exact approved native effect verified');
ck($GLOBALS['executions']===1&&$GLOBALS['title']==='New','exactly one native effect');
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::commit($sealed,array('ticket_id'=>$id,'agent_public_id'=>'agent-demo'))),'ticket replay denied');
$status=MAD4B_SCP_CSO_Native_Executor::status($sealed,$id);
ck(!is_wp_error($status)&&$status['status']==='verified'&&!$status['replay_allowed'],'terminal status read');
$inspection=MAD4B_SCP_CSO_Native_Executor::reconcile_inspect($sealed,$id);
ck(!is_wp_error($inspection)&&$inspection['observation_available']&&$inspection['provider_values_match']&&!$inspection['effect_verified_independently'],'recovery observation never promoted to independent proof');
$GLOBALS['scope']['actor_sha256']=str_repeat('d',64);
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::status($sealed,$id)),'actor isolation');
$GLOBALS['scope']['actor_sha256']=str_repeat('a',64);
$m['expected_revision']='revision-new-1';$m['values']['title']='After uncertain';
$second=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$p2=MAD4B_SCP_CSO_Native_Executor::approval_plan($second,'Reviewed second change','agent-demo');
$id2=$p2['ticket_id'];$GLOBALS['ticket'][$id2]='approved';$GLOBALS['uncertain']=true;
$err=MAD4B_SCP_CSO_Native_Executor::commit($second,array('ticket_id'=>$id2,'agent_public_id'=>'agent-demo'));
ck(is_wp_error($err)&&$GLOBALS['executions']===2,'uncertain write not represented as verified');
$status2=MAD4B_SCP_CSO_Native_Executor::status($second,$id2);
ck(!is_wp_error($status2)&&$status2['status']==='needs_reconcile'&&!$status2['replay_allowed'],'unknown effect durable without retry');
$observe2=MAD4B_SCP_CSO_Native_Executor::reconcile_inspect($second,$id2);
ck(!is_wp_error($observe2)&&$observe2['provider_values_match']&&$observe2['journal_status']==='needs_reconcile'&&!$observe2['effect_verified_independently'],'unknown-effect evidence requires independent resolution');
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::commit($second,array('ticket_id'=>$id2,'agent_public_id'=>'agent-demo'))),'uncertain effect replay denied');
$GLOBALS['policy']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::approval_plan($second,'again','agent-demo')),'revoked policy');
echo "PASS CSO native exact ticket / journal / permit / uncertain effect / replay\n";
