<?php
/** WordPress-free original Unified Approval Admin REST permission boundary. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['environment']='local';$GLOBALS['admin']=false;$GLOBALS['enrolled']=false;
$GLOBALS['agent_allowed']=false;$GLOBALS['routes']=[];$GLOBALS['actions']=[];
$GLOBALS['delegations']=[];$GLOBALS['mutations']=0;
class WP_Error{private $code;function __construct($code,$message='',$details=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function add_action($name,$callback){$GLOBALS['actions'][]=$name;}
function register_rest_route($namespace,$route,$args){$GLOBALS['routes'][$route]=$args;}
function current_user_can($cap){return $GLOBALS['admin']&&$cap==='manage_options';}
function get_current_user_id(){return 17;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function get_option($name,$default=[]){return $default;}
class MAD4B_SCP_Site_Profile{static function user_is_enrolled($uid){return $GLOBALS['enrolled']&&$uid===17;}}
class MAD4B_SCP_Background_Authorization{
 static function current(){
  return $GLOBALS['agent_allowed']?['authenticated'=>true,'auth_method'=>'wp_application_password','agent_id'=>11]:new WP_Error('background_denied');
 }
}
class MAD4B_SCP_Unified_Policy{
 static function resolve($args){return ['policy'=>'review_only','module'=>$args['module']];}
}
class MAD4B_SCP_Unified_Gate_Bridge{
 static function plan($args){return ['authorization'=>false,'owner_ticket_required'=>true,'ability'=>$args['ability']];}
}
class MAD4B_SCP_Unified_Approval_Workflow{
 static function open($domain,$item,$nonce=''){$GLOBALS['delegations'][]='open';return ['review'=>true,'domain'=>$domain];}
 static function decide_ai($id,$receipt,$confidence){$GLOBALS['delegations'][]='ai';return ['decision'=>'recorded','review_id'=>$id];}
 static function inspect($id){return ['review_id'=>$id,'authorized'=>false];}
}
class MAD4B_SCP_Unified_Approval_Queue_Bridge{
 static function gate($id){return ['review_id'=>$id,'publication_authorized'=>false];}
}
class MAD4B_SCP_Unified_Approval_Extensions{
 static function inventory(){return ['mutation_authorized'=>false,'additional_providers'=>[]];}
}
class MAD4B_SCP_Browser_Auth_Broker{
 static function plan($request){$GLOBALS['delegations'][]='browser';return ['execution_authorized'=>false];}
}
class TestReq{
 private $params;
 function __construct($p=[]){$this->params=$p;}
 function get_json_params(){return $this->params;}
 function get_param($name){return $this->params[$name]??null;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-unified-approval-admin.php';
$n=0;
function ck($msg,$yes){global $n;if(!$yes){fwrite(STDERR,'FAIL '.$msg.PHP_EOL);exit(1);}$n++;}
$ui='MAD4B_SCP_Unified_Approval_Admin';
$GLOBALS['environment']='production';$ui::boot();
ck('Production boots no routes or admin hooks',count($GLOBALS['actions'])===0&&count($GLOBALS['routes'])===0);
ck('Production cannot access REST',$ui::rest_permission()===false);
$GLOBALS['environment']='staging';$ui::boot();
ck('Staging also leaves REST disabled',count($GLOBALS['actions'])===0);
$GLOBALS['environment']='local';$ui::boot();
ck('Local original hooks registered',count($GLOBALS['actions'])===5);
$ui::register_rest();
ck('original eight routes visible only in Local',count($GLOBALS['routes'])===8);
ck('all routes require original permission method',count(array_filter($GLOBALS['routes'],static function($r){return $r['permission_callback']===['MAD4B_SCP_Unified_Approval_Admin','rest_permission'];}))===8);
ck('no DELETE or PUT route exposed',count(array_filter($GLOBALS['routes'],static function($r){return !in_array($r['methods'],['GET','POST'],true);}))===0);
ck('unauthenticated agent denied',$ui::rest_permission()===false);
$GLOBALS['admin']=true;
ck('unregistered WP admin denied',$ui::rest_permission()===false);
$GLOBALS['enrolled']=true;
ck('enrolled WP admin permitted',$ui::rest_permission()===true);
$GLOBALS['admin']=false;$GLOBALS['enrolled']=false;$GLOBALS['agent_allowed']=true;
ck('original delegated agent identity permitted',$ui::rest_permission()===true);
$GLOBALS['environment']='production';
ck('agent cannot reopen REST on Production',$ui::rest_permission()===false);
$GLOBALS['environment']='local';
$bad=$ui::rest_open(new TestReq(['domain'=>'content_job','item_id'=>'post:1','force_publish'=>true]));
ck('open rejects extra publish capability field',is_wp_error($bad)&&$bad->get_error_code()==='mad4b_unified_request_denied');
$bad=$ui::rest_ai(new TestReq(['review_id'=>str_repeat('a',64),'receipt'=>[],'force_grant'=>true]));
ck('AI decision rejects extra grant request field',is_wp_error($bad));
$bad=$ui::rest_browser_plan(new TestReq(['origin'=>'https://example.com','provider'=>'browser','operation'=>'read','bypass_mfa'=>true]));
ck('browser plan rejects MFA bypass field',is_wp_error($bad));
ck('all three unknown-field requests produced no original owner calls',count($GLOBALS['delegations'])===0);
$result=$ui::rest_open(new TestReq(['domain'=>'content_job','item_id'=>'post:1']));
ck('valid open delegates to original Workflow',is_array($result)&&$result['domain']==='content_job'&&$GLOBALS['delegations']===['open']);
$ai=$ui::rest_ai(new TestReq(['review_id'=>str_repeat('a',64),'receipt'=>['fixture'=>true],'confidence'=>0.94]));
ck('well-formed AI review delegates, not grants',$ai['decision']==='recorded'&&$GLOBALS['delegations'][1]==='ai');
$policy=$ui::rest_policy(new TestReq(['module'=>'pricing']));
ck('policy route never mints execution',$policy['policy']==='review_only');
$gate=$ui::rest_gate_plan(new TestReq(['module'=>'deployment','ability'=>'mad4b/deploy']));
ck('gate plans require independent original ticket',$gate['authorization']===false&&$gate['owner_ticket_required']===true);
$queue=$ui::rest_queue_gate(new TestReq(['review_id'=>str_repeat('b',64)]));
ck('queue route not publication',$queue['publication_authorized']===false);
$ext=$ui::rest_extensions(new TestReq());
ck('extension inventory cannot create authority',$ext['mutation_authorized']===false);
$browser=$ui::rest_browser_plan(new TestReq(['provider'=>'browser','operation'=>'read','origin'=>'https://example.com']));
ck('browser plan does not authorize action',$browser['execution_authorized']===false);
ck('none of these test routes wrote user/site records',$GLOBALS['mutations']===0);
echo 'PASS: '.$n.' original Unified Approval Admin REST and actor checks'.PHP_EOL;
