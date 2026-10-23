<?php
define('ABSPATH',__DIR__.'/../../');
define('MAD4B_MCP_OPERATOR_EXECUTION_ENABLED',true);
class WP_Error{public function __construct(public $code){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$args){}
class MAD4B_SCP_Developer_Runtime{
  public static function kill_switch_enabled(){return false;}
}
class MAD4B_SCP_Operator_Sessions{
  public static function can_access(){return true;}
  public static function verify($input,$op){return array(
    'session_id'=>'00000000-0000-4000-8000-000000000001',
    'expires_at_epoch'=>time()+120,
    'site_uuid'=>'00000000-0000-4000-8000-000000000002',
    'client_fingerprint'=>str_repeat('a',64));}
}
class MAD4B_SCP_Operator_Operation_Policy{
  public static function inspect($input){
    return array('payload'=>$input['payload'],'payload_sha256'=>hash('sha256',json_encode($input['payload'])),
      'risk'=>$input['operation']==='sql.read'?'read_only_db_role_required':'mutation_possible');
  }
}
class MAD4B_SCP_Operator_Runner_Profile{public static function current_id(){return 'fixture-site-a-runner-v1';}}
class MAD4B_SCP_Host_Bridge{
  public static function plan($input){
    if(!MAD4B_SCP_Operator_Bridge::request_authority_matches($input['operation_id'],$input['arguments']))
      return new WP_Error('unbound');
    return array('operation_id'=>$input['operation_id'],'arguments'=>$input['arguments'],'plan_sha256'=>str_repeat('b',64));
  }
  public static function apply($input){
    if(!MAD4B_SCP_Operator_Bridge::request_authority_matches($input['plan']['operation_id'],$input['plan']['arguments']))
      return new WP_Error('unbound');
    return array('job_id'=>'00000000-0000-4000-8000-000000000099','queued'=>true);
  }
}
require_once __DIR__.'/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-bridge.php';
$input=array('session_id'=>'00000000-0000-4000-8000-000000000001','session_token'=>str_repeat('x',43),
  'operation'=>'sql.read','payload'=>array('sql'=>'SELECT 1'),'idempotency_key'=>'test-scoped-12345');
$plan=MAD4B_SCP_Operator_Bridge::plan($input);
if(is_wp_error($plan))exit(1);
if(MAD4B_SCP_Operator_Bridge::request_authority_matches('operator.sql.read',$plan['arguments']))exit(2);
$result=MAD4B_SCP_Operator_Bridge::submit($input);
if(is_wp_error($result)||empty($result['queued'])||$result['host_mutation_performed']!==false)exit(3);
$input['operation']='shell';
$input['payload']=array('command'=>'pwd');
if(!is_wp_error(MAD4B_SCP_Operator_Bridge::submit($input)))exit(4);
echo "PASS operator request-local admission and shell approval denial; no host execution\n";
