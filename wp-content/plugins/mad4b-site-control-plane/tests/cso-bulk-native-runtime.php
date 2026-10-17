<?php
/** Two-step native bulk canary, journal CAS and uncertain effect tests. */
define('ABSPATH','/');
class WP_Error {private $c;function __construct($c,$m='',$d=null){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function maybe_serialize($v){return serialize($v);}
function get_current_blog_id(){return $GLOBALS['blog_id']??1;}
function get_option($key,$default=null){
 if(array_key_exists('option_override',$GLOBALS))return $GLOBALS['option_override'];
 return isset($GLOBALS['rows'][$key])?unserialize($GLOBALS['rows'][$key]):$default;
}
function add_option($key,$value,$desc='',$autoload=false){
 $GLOBALS['option_adds']=($GLOBALS['option_adds']??0)+1;
 // WordPress stale-precheck ON DUPLICATE KEY UPDATE path, not an atomic lock.
 $GLOBALS['rows'][$key]=serialize($value);return true;
}
function wp_cache_delete($key,$group){$GLOBALS['cache_deletes'][]=array($key,$group);return true;}
class MockDB {
 public $options='wp_options';public $last_error='';
 function get_blog_prefix($blog){return $blog===1?'wp_':'wp_'.$blog.'_';}
 function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];return array($sql,$args);}
 function get_var($q){$this->last_error='';$raw=$GLOBALS['rows'][$q[1][0]]??null;
  return is_string($raw)&&strlen($raw)>16384?'':$raw;}
 function query($q){list($sql,$args)=$q;$this->last_error='';
  if(strpos($sql,'INSERT INTO ')===0){
   ck(strpos($sql,'ON DUPLICATE')===false&&strpos($sql,'IGNORE')===false,'INSERT ONLY SQL');
   list($key,$raw)=$args;
   if(!empty($GLOBALS['insert_race_once'])){
    unset($GLOBALS['insert_race_once']);$winner=unserialize($raw);
    $winner['status']='inflight';$winner['effect_ticket_sha256']=str_repeat('f',64);
    $GLOBALS['rows'][$key]=serialize($winner);$GLOBALS['race_key']=$key;$GLOBALS['race_raw']=$GLOBALS['rows'][$key];
   }
   if(isset($GLOBALS['rows'][$key])){$this->last_error='duplicate key';return false;}
   $GLOBALS['rows'][$key]=$raw;
   if(!empty($GLOBALS['insert_ack_lost'])){unset($GLOBALS['insert_ack_lost']);$this->last_error='lost insert acknowledgment';return false;}
   return 1;
  }
  if(strpos($sql,'UPDATE ')!==0)throw new RuntimeException('unexpected SQL');
  list($next,$key,$old)=$args;
  if(!empty($GLOBALS['cas_race_once'])){
   unset($GLOBALS['cas_race_once']);$winner=unserialize($GLOBALS['rows'][$key]);$winner['status']='inflight';
   $winner['effect_ticket_sha256']=str_repeat('e',64);$GLOBALS['rows'][$key]=serialize($winner);
   $GLOBALS['race_key']=$key;$GLOBALS['race_raw']=$GLOBALS['rows'][$key];
  }
  if(!isset($GLOBALS['rows'][$key])||$GLOBALS['rows'][$key]!==$old)return 0;
  $GLOBALS['rows'][$key]=$next;return 1;
 }
}
$GLOBALS['wpdb']=new MockDB();$GLOBALS['rows']=array();
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64));
$GLOBALS['runs']=0;$GLOBALS['fail_at']=0;
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return true;}
 static function first_party_session(){return true;}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($s){return $s===$GLOBALS['scope'];}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function bounded($v){return strlen(json_encode($v))<131072;}
 static function safe_data($v){
  if(is_array($v)){foreach($v as $k=>$x){if($k==='api_key'||!self::safe_data($x))return false;}return true;}
  return is_scalar($v)||$v===null;
 }
 static function error($v){return new WP_Error($v);}
 static function seal($m,$p){return array('material'=>$m,'proof'=>hash_hmac('sha256',$p.json_encode($m),'test'));}
 static function unseal($s,$p){return isset($s['material'],$s['proof'])&&
  hash_equals($s['proof'],hash_hmac('sha256',$p.json_encode($s['material']),'test'))?
  $s['material']:self::error('BAD_SEAL');}
}
class MAD4B_SCP_CSO_Changes {const CONTRACT='mad4b.cso.change-plan.v1';}
class MAD4B_SCP_Database_Topology {static function assert_write_ready($v){return array('ready'=>true);}}
$GLOBALS['can_approve']=true;
class MAD4B_SCP_Policy {
 static function can_approve_mutations(){return $GLOBALS['can_approve'];}
}
class MAD4B_SCP_Operational_Integrity {
 static function capture(){return array('actor'=>$GLOBALS['scope']['actor_sha256']);}
 static function assert_unchanged($x,$mutate){return $x['actor']===$GLOBALS['scope']['actor_sha256']?true:new WP_Error('DRIFT');}
}
class MAD4B_SCP_CSO_Native_Executor {
 static function commit($plan,$ticket){$GLOBALS['runs']++;
  return $GLOBALS['runs']===$GLOBALS['fail_at']?new WP_Error('UNCERTAIN'):
   array('status'=>'verified');}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-bulk.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-bulk-runtime.php';
function ck($ok,$label){if(!$ok){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}}
function item($name) {
 $m=array('contract'=>MAD4B_SCP_CSO_Changes::CONTRACT,
  'scope_fingerprint'=>$GLOBALS['scope']['binding_sha256'],
  'actor_sha256'=>$GLOBALS['scope']['actor_sha256'],
  'expires_at'=>time()+200,'write_authorized'=>false,'values'=>array('title'=>$name));
 return array('plan'=>MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT),
  'plan_sha256'=>MAD4B_SCP_CSO_Scope::digest($m));
}
$items=array(item('A'),item('B'),item('C'));
$batch=MAD4B_SCP_CSO_Bulk::plan($items,array('indexes'=>array(0,1,2)),1);
ck(!is_wp_error($batch)&&isset($batch['sealed_batch']),'scope-sealed bulk');
$g=array('canary_reviewed'=>false,'items'=>array(
 array('ticket_id'=>'t1','agent_public_id'=>'agent-demo'),
 array('ticket_id'=>'t2','agent_public_id'=>'agent-demo'),
 array('ticket_id'=>'t3','agent_public_id'=>'agent-demo')));
$r=MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,array(),1);
ck(!is_wp_error($r)&&$r['status']==='paused'&&$r['completed_items']===1&&$GLOBALS['runs']===1,'one canary then pause');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,array(),2)),'resume without checkpoint denied');
$g['canary_reviewed']=true;
$GLOBALS['can_approve']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,$r['checkpoint'],2)),'canary review requires current approving actor');
ck($GLOBALS['runs']===1,'no new effect for unapproved canary resume');
$GLOBALS['can_approve']=true;
$r2=MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,$r['checkpoint'],2);
ck(!is_wp_error($r2)&&$r2['status']==='complete'&&$GLOBALS['runs']===3,'resume two, complete three');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,$r2['checkpoint'],1)),'completed batch cannot replay');
$another=MAD4B_SCP_CSO_Bulk::plan(array(item('D'),item('E')),array(),1);
$GLOBALS['fail_at']=4;
$g['items']=array(array('ticket_id'=>'t4','agent_public_id'=>'agent-demo'),
 array('ticket_id'=>'t5','agent_public_id'=>'agent-demo'));
$failed=MAD4B_SCP_CSO_Bulk_Runtime::commit($another['sealed_batch'],$g,array(),1);
ck(is_wp_error($failed)&&$GLOBALS['runs']===4,'unknown effect blocked');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($another['sealed_batch'],$g,array(),1)),'unknown effect not replayed');

// A stale empty read followed by an already committed winner must not turn
// INSERT admission into an UPSERT that resets cursor/state or repeats effects.
$race_batch=MAD4B_SCP_CSO_Bulk::plan(array(item('F'),item('G')),array(),1);
$GLOBALS['insert_race_once']=true;$before=$GLOBALS['runs'];
$race_result=MAD4B_SCP_CSO_Bulk_Runtime::commit($race_batch['sealed_batch'],$g,array(),1);
ck(is_wp_error($race_result)&&$GLOBALS['runs']===$before,'racing initial insert does not execute item');
ck($GLOBALS['rows'][$GLOBALS['race_key']]===$GLOBALS['race_raw'],'initial winner journal bytes preserved');
$GLOBALS['option_override']=null;
$race_retry=MAD4B_SCP_CSO_Bulk_Runtime::commit($race_batch['sealed_batch'],$g,array(),1);
unset($GLOBALS['option_override']);
ck(is_wp_error($race_retry)&&$GLOBALS['runs']===$before&&
 $GLOBALS['rows'][$GLOBALS['race_key']]===$GLOBALS['race_raw'],'stale Option API cannot erase durable in-flight winner');
// Resume races are guarded by byte-exact CAS, not the submitted checkpoint.
$cas_batch=MAD4B_SCP_CSO_Bulk::plan(array(item('H'),item('I')),array(),1);
$g['canary_reviewed']=false;
$canary=MAD4B_SCP_CSO_Bulk_Runtime::commit($cas_batch['sealed_batch'],$g,array(),1);
ck(!is_wp_error($canary)&&$canary['completed_items']===1,'separate race canary completes once');
$before=$GLOBALS['runs'];$g['canary_reviewed']=true;$GLOBALS['cas_race_once']=true;
$cas_result=MAD4B_SCP_CSO_Bulk_Runtime::commit($cas_batch['sealed_batch'],$g,$canary['checkpoint'],1);
ck(is_wp_error($cas_result)&&$GLOBALS['runs']===$before&&
 $GLOBALS['rows'][$GLOBALS['race_key']]===$GLOBALS['race_raw'],'resume CAS loser cannot reset winner or execute');
$ack_batch=MAD4B_SCP_CSO_Bulk::plan(array(item('J'),item('K')),array(),1);
$GLOBALS['insert_ack_lost']=true;
$ack_result=MAD4B_SCP_CSO_Bulk_Runtime::commit($ack_batch['sealed_batch'],$g,array(),1);
ck(is_wp_error($ack_result)&&$GLOBALS['runs']===$before,'lost reservation ACK denies item execution');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($ack_batch['sealed_batch'],$g,array(),1)),
 'lost reservation ACK never returns to fresh initial admission');
$GLOBALS['wpdb']->options='wp_2_options';
$foreign_batch=MAD4B_SCP_CSO_Bulk::plan(array(item('L'),item('M')),array(),1);
$old_rows=$GLOBALS['rows'];
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($foreign_batch['sealed_batch'],$g,array(),1))&&
 $GLOBALS['rows']===$old_rows&&$GLOBALS['runs']===$before,'wrong current-blog options table cannot reserve');
$GLOBALS['wpdb']->options='wp_options';
class BatchWakeup {function __wakeup(){$GLOBALS['batch_woke']=true;}}
$race_key=$GLOBALS['race_key'];$winner_raw=$GLOBALS['rows'][$race_key];
$corrupt=unserialize($winner_raw);$corrupt['extra']=new BatchWakeup();$GLOBALS['rows'][$race_key]=serialize($corrupt);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($cas_batch['sealed_batch'],$g,$canary['checkpoint'],1))&&
 empty($GLOBALS['batch_woke']),'stored objects are rejected without wakeup');
$GLOBALS['rows'][$race_key]=str_repeat('x',16385);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($cas_batch['sealed_batch'],$g,$canary['checkpoint'],1)),
 'oversize stored journal is bounded before deserialization');
$GLOBALS['rows'][$race_key]=$winner_raw;
ck(empty($GLOBALS['option_adds']),'bulk admission never uses upserting add_option');
ck(in_array(array('notoptions','options'),$GLOBALS['cache_deletes'],true)&&
 in_array(array('alloptions','options'),$GLOBALS['cache_deletes'],true),'bulk invalidates all option caches');

$GLOBALS['scope']['actor_sha256']=str_repeat('e',64);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,array(),1)),'actor-bound plan');
echo "PASS CSO durable canary/resume/CAS/uncertain-effect replay denial\n";
