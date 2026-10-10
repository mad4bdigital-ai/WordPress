<?php
/** Two-step native bulk canary, journal CAS and uncertain effect tests. */
define('ABSPATH','/');
class WP_Error {private $c;function __construct($c,$m='',$d=null){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function maybe_serialize($v){return serialize($v);}
function get_option($key,$default=null){return $GLOBALS['rows'][$key]??$default;}
function add_option($key,$value,$desc='',$autoload=false){if(isset($GLOBALS['rows'][$key]))return false;$GLOBALS['rows'][$key]=$value;return true;}
function wp_cache_delete($key,$group){return true;}
class MockDB {
 public $options='wp_options';public $last_error='';
 function prepare($sql,...$args){return array($sql,$args);}
 function query($q){list($sql,$args)=$q;list($next,$key,$old)=$args;
  if(!isset($GLOBALS['rows'][$key])||serialize($GLOBALS['rows'][$key])!==$old)return 0;
  $GLOBALS['rows'][$key]=unserialize($next);return 1;}
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
 static function error($v){return new WP_Error($v);}
 static function seal($m,$p){return array('material'=>$m,'proof'=>hash_hmac('sha256',$p.json_encode($m),'test'));}
 static function unseal($s,$p){return isset($s['material'],$s['proof'])&&
  hash_equals($s['proof'],hash_hmac('sha256',$p.json_encode($s['material']),'test'))?
  $s['material']:self::error('BAD_SEAL');}
}
class MAD4B_SCP_CSO_Changes {const CONTRACT='mad4b.cso.change-plan.v1';}
class MAD4B_SCP_Database_Topology {static function assert_write_ready($v){return array('ready'=>true);}}
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
$GLOBALS['scope']['actor_sha256']=str_repeat('e',64);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk_Runtime::commit($batch['sealed_batch'],$g,array(),1)),'actor-bound plan');
echo "PASS CSO durable canary/resume/CAS/uncertain-effect replay denial\n";
