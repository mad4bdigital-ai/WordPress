<?php
/** Read-only DAG and batch plan: no remote provider or WordPress mutation. */
define('ABSPATH','/');
class WP_Error {private $code;function __construct($v,$m='',$d=null){$this->code=$v;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_CSO_Scope {
 static function enabled($k){return in_array($k,array('workflow','bulk','single_write'),true);}
 static function first_party_session(){return $GLOBALS['cookie'];}
 static function current(){return $GLOBALS['scope'];}
 static function assert_current($v){return $v===$GLOBALS['scope']?true:self::error('SCOPE_CHANGED');}
 static function bounded($v){return strlen(json_encode($v))<=131072;}
 static function safe_data($v){
  if(is_array($v)){foreach($v as $k=>$x){if(is_string($k)&&preg_match('/api_key|secret|password/i',$k)||!self::safe_data($x))return false;}return true;}
  return !is_string($v)||false===stripos($v,'Bearer ');
 }
 static function error($v){return new WP_Error($v);}
 static function digest($v){return hash('sha256',json_encode($v));}
 static function seal($v,$purpose){return array('material'=>$v,'proof'=>hash_hmac('sha256',$purpose.json_encode($v),'fixture-key'));}
 static function unseal($v,$purpose){
  return isset($v['material'],$v['proof'])&&hash_equals($v['proof'],hash_hmac('sha256',$purpose.json_encode($v['material']),'fixture-key'))
  ?$v['material']:self::error('TAMPERED');
 }
}
class MAD4B_SCP_CSO_Changes {const CONTRACT='mad4b.cso.change-plan.v1';}
$GLOBALS['cookie']=true;$GLOBALS['scope']=array('binding_sha256'=>str_repeat('b',64),'actor_sha256'=>str_repeat('a',64));
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-bulk.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-cso-workflows.php';
function ck($v,$label){if(!$v){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}}
$nodes=array(
 array('id'=>'read','kind'=>'read','requires'=>array()),
 array('id'=>'approve','kind'=>'approval','requires'=>array('read')),
 array('id'=>'write','kind'=>'write_intent','requires'=>array('approve'),'plan_sha256'=>str_repeat('c',64)),
);
$compiled=MAD4B_SCP_CSO_Workflows::compile($nodes);
ck(!is_wp_error($compiled)&&$compiled['ordered_node_ids']===array('read','approve','write')&&!$compiled['execution_allowed'],'topological plan without execution');
$cycle=$nodes;$cycle[0]['requires']=array('write');
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::compile($cycle)),'DAG cycle denied');
$duplicate=$nodes;$duplicate[1]['id']='read';
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::compile($duplicate)),'duplicate workflow id denied');
$bad=$nodes;$bad[2]['api_key']='secret';
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::compile($bad)),'secret-like node metadata denied');
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::run($compiled,array(),1)),'no workflow executor');
$intent=array('contract'=>MAD4B_SCP_CSO_Changes::CONTRACT,
 'scope_fingerprint'=>$GLOBALS['scope']['binding_sha256'],
 'actor_sha256'=>$GLOBALS['scope']['actor_sha256'],
 'expires_at'=>time()+120,'write_authorized'=>false);
$plan=array('plan'=>MAD4B_SCP_CSO_Scope::seal($intent,MAD4B_SCP_CSO_Changes::CONTRACT),
 'plan_sha256'=>MAD4B_SCP_CSO_Scope::digest($intent));
$b=MAD4B_SCP_CSO_Bulk::plan(array($plan),array('indexes'=>array(0)),1);
ck(!is_wp_error($b)&&$b['count']===1&&!$b['commit_allowed'],'canary plan nonexecuting');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk::plan(array($plan,$plan),array(),1)),'duplicate plan refused');
$GLOBALS['scope']['actor_sha256']=str_repeat('d',64);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk::plan(array($plan),array(),1)),'other actor denied');
$GLOBALS['scope']['actor_sha256']=str_repeat('a',64);
$forged=$plan;$forged['plan']['material']['actor_sha256']=str_repeat('e',64);
ck(is_wp_error(MAD4B_SCP_CSO_Bulk::plan(array($forged),array(),1)),'tampered batch plan refused');
ck(is_wp_error(MAD4B_SCP_CSO_Bulk::commit($b,array(),array(),1)),'no bulk write executor');
$GLOBALS['cookie']=false;
ck(is_wp_error(MAD4B_SCP_CSO_Workflows::compile($nodes)),'anonymous workflow denied');
echo "PASS CSO bounded DAG/cycle/bulk/canary/replay negative suite\n";
