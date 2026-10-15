<?php
/** Native write-only workflow DAG is a sealed ordered bulk admission. */
define('ABSPATH','/');
class WP_Error {private $c;function __construct($c,$m='',$d=null){$this->c=$c;}function get_error_code(){return $this->c;}}
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64));
$GLOBALS['ran']=0;
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return true;}
 static function first_party_session(){return true;}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($s){return $s===$GLOBALS['scope'];}
 static function error($c){return new WP_Error($c);}
 static function safe_data($v){return true;}
 static function bounded($v){return strlen(json_encode($v))<131072;}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function seal($m,$p){return array('material'=>$m,'proof'=>hash_hmac('sha256',$p.json_encode($m),'test'));}
 static function unseal($s,$p){return isset($s['material'],$s['proof'])&&
  hash_equals($s['proof'],hash_hmac('sha256',$p.json_encode($s['material']),'test'))?
  $s['material']:self::error('BAD_SEAL');}
}
class MAD4B_SCP_CSO_Changes {const CONTRACT='mad4b.cso.change-plan.v1';}
class MAD4B_SCP_CSO_Bulk {
 static function plan($plans,$selection,$canary){
  return array('sealed_batch'=>MAD4B_SCP_CSO_Scope::seal(array('plans'=>$plans),'batch-fixture'));
 }
}
class MAD4B_SCP_CSO_Bulk_Runtime {
 static function commit($batch,$gov,$checkpoint,$max){
  $GLOBALS['ran']++;return array('status'=>'paused','completed_items'=>1,'checkpoint'=>array(),'native_ticket_gated'=>true);
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-workflows.php';
function ck($v,$label){if(!$v){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}}
$m=array('contract'=>MAD4B_SCP_CSO_Changes::CONTRACT,
 'scope_fingerprint'=>$GLOBALS['scope']['binding_sha256'],
 'actor_sha256'=>$GLOBALS['scope']['actor_sha256'],'expires_at'=>time()+200);
$sealed=MAD4B_SCP_CSO_Scope::seal($m,MAD4B_SCP_CSO_Changes::CONTRACT);
$n=array(array('id'=>'write1','kind'=>'write_intent','requires'=>array(),
 'plan_sha256'=>MAD4B_SCP_CSO_Scope::digest($m),'sealed_plan'=>$sealed));
$compiled=MAD4B_SCP_CSO_Workflows::compile($n);
ck(!is_wp_error($compiled)&&$compiled['execution_supported']&&!$compiled['execution_allowed'],
 'signed write-only execution link is nonauthorizing');
$gov=array('items'=>array(array('ticket_id'=>'fake','agent_public_id'=>'fake')),
 'canary_reviewed'=>false,'checkpoint'=>array());
$r=MAD4B_SCP_CSO_Workflows::run($compiled,$gov,1);
ck(!is_wp_error($r)&&$r['status']==='paused'&&$GLOBALS['ran']===1,'delegates to native bulk canary');
$forged=$compiled;$forged['graph_sha256']=str_repeat('e',64);
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::run($forged,$gov,1)),'forged graph rejected');
$n[0]['sealed_plan']['material']['expires_at']++;
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::compile($n)),'tampered plan not executable');
$read=array(array('id'=>'read1','kind'=>'read','requires'=>array()));
$compiled=MAD4B_SCP_CSO_Workflows::compile($read);
ck(!is_wp_error($compiled)&&!$compiled['execution_supported'],'read node cannot be falsely skipped');
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::run($compiled,$gov,1)),'mixed DAG cannot execute');
echo "PASS CSO native DAG sealed batch/read fail-close/tamper test\n";
