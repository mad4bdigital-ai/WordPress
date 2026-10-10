<?php
/** CSO native approval + idempotency + indeterminate effect test doubles. */
define('ABSPATH','/');
class WP_Error {private $code;public function __construct($c,$m='',$d=null){$this->code=$c;}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function maybe_serialize($x){return serialize($x);}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
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
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64),'environment'=>'staging');
$GLOBALS['write_enabled']=true;$GLOBALS['session']=true;$GLOBALS['policy']=true;
$GLOBALS['ticket']=array();$GLOBALS['revision']='revision-old';
$GLOBALS['wp_environment']='staging';
function wp_get_environment_type(){return $GLOBALS['wp_environment'];}
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
class MAD4B_SCP_Policy {
 static function can_mutate(){return $GLOBALS['policy'];}
 static function can_approve_mutations(){return $GLOBALS['policy'];}
}
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
 static function canonical_payload_hash($agent,$server,$ability,$provider,$fp,$input,$class){
  return hash('sha256',json_encode(array($agent,$server,$ability,$provider,$fp,$input,$class)));
 }
 static function create_pending($agent,$server,$ability,$provider,$fp,$input,$class,$reason,$ttl){
  $id=sprintf('123e4567-e89b-42d3-a456-%012d',426614174000+count($GLOBALS['ticket']));
  $GLOBALS['ticket'][$id]='pending';
  $GLOBALS['ticket_meta'][$id]=array('agent_id'=>7,'ticket_class'=>$class,'server_id'=>$server,
   'ability_name'=>$ability,'provider'=>$provider,'target_fingerprint'=>$fp,
   'payload_sha256'=>self::canonical_payload_hash($agent,$server,$ability,$provider,$fp,$input,$class));
  return array('ticket_id'=>$id,'status'=>'pending');
 }
 static function get($id){
  if(!isset($GLOBALS['ticket_meta'][$id]))return null;
  return array_merge($GLOBALS['ticket_meta'][$id],array('status'=>$GLOBALS['ticket'][$id]));
 }
 static function authorize_exact($id,...$args){
  return ($GLOBALS['ticket'][$id]??'')==='approved'?array('ok'=>true):new WP_Error('NOT_APPROVED_OR_REPLAY');
 }
 static function claim_exact($id,...$args){
  if(($GLOBALS['ticket'][$id]??'')!=='approved')return new WP_Error('REPLAY');
  if(($GLOBALS['claim_failure_mode']??'')==='before_claim')return new WP_Error('CLAIM_BEFORE_EFFECT');
  $GLOBALS['ticket'][$id]='executing';
  if(($GLOBALS['claim_failure_mode']??'')==='ack_lost')return new WP_Error('CLAIM_ACK_LOST');
  return array('status'=>'executing');
 }
 static function finalize_claim($id,$status,$reason=''){
  if(($GLOBALS['ticket'][$id]??'')!=='executing')return new WP_Error('BAD_TICKET_STATE');
  $GLOBALS['ticket'][$id]=$status;return array('status'=>$status);
 }
 static function revoke($id){
  if(($GLOBALS['ticket'][$id]??'')!=='approved')return new WP_Error('REVOKE_NOT_APPROVED');
  $GLOBALS['ticket'][$id]='revoked';return true;
 }
}
class FakeAbility {
 function get_meta(){return array('mcp'=>array('mad4b_cso_certified_write'=>true,'governed_write'=>true),'annotations'=>array('readonly'=>false));}
 function check_permissions($payload){return MAD4B_SCP_CSO_Native_Executor::native_permit_matches($payload);}
 function execute($payload){
  if(!MAD4B_SCP_CSO_Native_Executor::native_permit_matches($payload,true))return new WP_Error('NO_PERMIT');
  $GLOBALS['executions']++;$GLOBALS['title']=$payload['values']['title'];
  $GLOBALS['revision']='revision-new-'.$GLOBALS['executions'];
  if(!empty($GLOBALS['throw_after_effect']))throw new RuntimeException('fixture crash after write');
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
$GLOBALS['wp_environment']='production';
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::approval_plan($sealed,'Reviewed one post title','agent-demo')),'WP production default cannot inherit staging profile writes');
ck($GLOBALS['executions']===0&&count($GLOBALS['dbrows'])===0,'environment mismatch leaves no effects');
$GLOBALS['wp_environment']='staging';
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
// Provider callback may crash after a real side effect. Preserve the ticket
// and durable uncertain journal without implicitly replaying the same write.
$m['expected_revision']='revision-new-2';$m['values']['title']='After crash';
$third=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$p3=MAD4B_SCP_CSO_Native_Executor::approval_plan($third,'Reviewed crash case','agent-demo');
$id3=$p3['ticket_id'];$GLOBALS['ticket'][$id3]='approved';
$GLOBALS['uncertain']=false;$GLOBALS['throw_after_effect']=true;
$crash=MAD4B_SCP_CSO_Native_Executor::commit($third,array('ticket_id'=>$id3,'agent_public_id'=>'agent-demo'));
ck(is_wp_error($crash)&&$GLOBALS['executions']===3,'post-effect exception cannot be marked successful');
$crash_status=MAD4B_SCP_CSO_Native_Executor::status($third,$id3);
ck(!is_wp_error($crash_status)&&$crash_status['status']==='needs_reconcile'&&!$crash_status['replay_allowed'],
 'post-effect crash remains durable and nonreplayable');
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::commit($third,array('ticket_id'=>$id3,'agent_public_id'=>'agent-demo'))),
 'crashed native write cannot retry ticket');
$GLOBALS['throw_after_effect']=false;
// Recovery is authenticated by a separate ephemeral test auditor, not by
// WordPress/MCP, and never grants the native execution permit.
if (!function_exists('openssl_pkey_new')) {
 fwrite(STDERR,"BLOCKED: OpenSSL extension required for signed recovery fixture\n");exit(2);
}
$audit_key=openssl_pkey_new(array('private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048));
ck($audit_key!==false,'ephemeral auditor key generated');
$audit_pub=openssl_pkey_get_details($audit_key);
define('MAD4B_CSO_RECONCILE_TRUSTED_PUBLIC_KEY',$audit_pub['key']);
define('MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID','fixture-independent-auditor');
function signed_recovery_proof($material,$id,$outcome,$key){
 $journal_key='mad4b_cso_write_'.hash('sha256',$id.'|'.MAD4B_SCP_CSO_Scope::digest($material));
 $journal=get_option($journal_key,null);
 ck(is_array($journal),'durable journal exists for proof');
 $body=array(
  'contract'=>MAD4B_SCP_CSO_Native_Executor::CONTRACT.'.external-proof.v1',
  'issuer'=>MAD4B_CSO_RECONCILE_EXTERNAL_AUDITOR_ID,
  'ticket_sha256'=>hash('sha256',$id),
  'plan_sha256'=>MAD4B_SCP_CSO_Scope::digest($material),
  'scope_sha256'=>MAD4B_SCP_CSO_Scope::digest($GLOBALS['scope']),
  'provider_id'=>$material['provider_id'],
  'target_sha256'=>MAD4B_SCP_CSO_Scope::digest($material['target']),
  'outcome'=>$outcome,
  'observed_revision_sha256'=>hash('sha256',$GLOBALS['revision']),
  'observed_values_sha256'=>MAD4B_SCP_CSO_Scope::digest(array('title'=>$GLOBALS['title'])),
  'evidence_ref'=>'auditor:'.substr(hash('sha256',$id),0,24),
  'writer_fenced'=>true,
  'quiesced_at'=>max(time(),$journal['updated_at']),
  'side_effects_excluded'=>$outcome==='absent',
  'issued_at'=>time(),
  'expires_at'=>time()+240
 );
 $sig='';ck(openssl_sign(wp_json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  $sig,$key,OPENSSL_ALGO_SHA256),'external proof signed');
 return array('body'=>$body,'signature'=>base64_encode($sig));
}
$proof3=signed_recovery_proof($m,$id3,'applied',$audit_key);
$bad3=$proof3;$bad3['signature']=base64_encode('not-a-signature');
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::reconcile_approval_plan(
 $third,$id3,$bad3,'agent-demo','review proof')),'forged external recovery proof denied');
$recovery3=MAD4B_SCP_CSO_Native_Executor::reconcile_approval_plan(
 $third,$id3,$proof3,'agent-demo','externally observed post-effect crash');
ck(!is_wp_error($recovery3)&&$recovery3['status']==='pending','signed applied proof plans second approval');
$recovery3id=$recovery3['ticket_id'];
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::reconcile_finalize(
 $third,$id3,$proof3,'agent-demo',$recovery3id)),'pending recovery cannot finalize');
$GLOBALS['ticket'][$recovery3id]='approved';
$final3=MAD4B_SCP_CSO_Native_Executor::reconcile_finalize(
 $third,$id3,$proof3,'agent-demo',$recovery3id);
ck(!is_wp_error($final3)&&$final3['status']==='reconciled_applied'&&
 $GLOBALS['ticket'][$id3]==='used'&&$GLOBALS['ticket'][$recovery3id]==='used',
 'post-effect crash journal and both approvals closed with signed proof');
ck($GLOBALS['executions']===3,'recovery never replays native effect');
// Crash after durable reservation but before claiming the approved ticket.
$m['expected_revision']='revision-new-3';$m['values']['title']='Never executed';
$fourth=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$p4=MAD4B_SCP_CSO_Native_Executor::approval_plan($fourth,'review reservation crash','agent-demo');
ck(!is_wp_error($p4),'fourth plan approved for fixture');
$id4=$p4['ticket_id'];$GLOBALS['ticket'][$id4]='approved';
$GLOBALS['claim_failure_mode']='before_claim';
$uncertain4=MAD4B_SCP_CSO_Native_Executor::commit($fourth,array('ticket_id'=>$id4,'agent_public_id'=>'agent-demo'));
unset($GLOBALS['claim_failure_mode']);
ck(is_wp_error($uncertain4)&&$GLOBALS['ticket'][$id4]==='approved'&&
 $GLOBALS['executions']===3,'unclaimed journal crash has no native effect');
$state4=MAD4B_SCP_CSO_Native_Executor::status($fourth,$id4);
ck(!is_wp_error($state4)&&$state4['status']==='needs_reconcile',
 'claim failure remains reconcilable, never claim_denied');
$proof4=signed_recovery_proof($m,$id4,'absent',$audit_key);
$plan4=MAD4B_SCP_CSO_Native_Executor::reconcile_approval_plan(
 $fourth,$id4,$proof4,'agent-demo','prove original claim never executed');
ck(!is_wp_error($plan4),'approved original ticket can be recovered only as absent');
$GLOBALS['ticket'][$plan4['ticket_id']]='approved';
$final4=MAD4B_SCP_CSO_Native_Executor::reconcile_finalize(
 $fourth,$id4,$proof4,'agent-demo',$plan4['ticket_id']);
ck(!is_wp_error($final4)&&$final4['status']==='reconciled_absent'&&
 $GLOBALS['ticket'][$id4]==='revoked','unclaimed ticket revoked after independent absent proof');
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::commit(
 $fourth,array('ticket_id'=>$id4,'agent_public_id'=>'agent-demo'))),
 'original ticket never becomes replayable');
// Database may commit the approval claim but lose its acknowledgment.
$m['values']['title']='Claim may have committed';
$fifth=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$p5=MAD4B_SCP_CSO_Native_Executor::approval_plan($fifth,'review lost claim ack','agent-demo');
$id5=$p5['ticket_id'];$GLOBALS['ticket'][$id5]='approved';
$GLOBALS['claim_failure_mode']='ack_lost';
$uncertain5=MAD4B_SCP_CSO_Native_Executor::commit($fifth,array('ticket_id'=>$id5,'agent_public_id'=>'agent-demo'));
unset($GLOBALS['claim_failure_mode']);
ck(is_wp_error($uncertain5)&&$GLOBALS['ticket'][$id5]==='executing'&&
 $GLOBALS['executions']===3,'lost claim ACK does not invoke provider');
$state5=MAD4B_SCP_CSO_Native_Executor::status($fifth,$id5);
ck(!is_wp_error($state5)&&$state5['status']==='needs_reconcile',
 'lost claim ACK remains in recoverable state');
$proof5=signed_recovery_proof($m,$id5,'absent',$audit_key);
$plan5=MAD4B_SCP_CSO_Native_Executor::reconcile_approval_plan(
 $fifth,$id5,$proof5,'agent-demo','signed proof claim had no effect');
ck(!is_wp_error($plan5),'post-commit lost-ACK can be planned safely');
$GLOBALS['ticket'][$plan5['ticket_id']]='approved';
$final5=MAD4B_SCP_CSO_Native_Executor::reconcile_finalize(
 $fifth,$id5,$proof5,'agent-demo',$plan5['ticket_id']);
ck(!is_wp_error($final5)&&$GLOBALS['ticket'][$id5]==='failed'&&
 $final5['status']==='reconciled_absent','executing ticket closed failed without replay');
ck($GLOBALS['executions']===3,'all reconciliation paths perform zero extra writes');
$GLOBALS['policy']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Native_Executor::approval_plan($second,'again','agent-demo')),'revoked policy');
echo "PASS CSO native exact ticket / journal / permit / uncertain effect / replay\n";
