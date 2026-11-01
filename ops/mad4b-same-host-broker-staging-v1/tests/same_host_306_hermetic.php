<?php
declare(strict_types=1);
define('ABSPATH', '/test/');
class WP_REST_Request implements ArrayAccess {
 private array $p; private array $j;
 public function __construct(array $p,array $j){$this->p=$p;$this->j=$j;}
 public function offsetExists(mixed $x):bool{return array_key_exists($x,$this->p);}
 public function offsetGet(mixed $x):mixed{return $this->p[$x]??null;}
 public function offsetSet(mixed $x,mixed $y):void{$this->p[$x]=$y;}
 public function offsetUnset(mixed $x):void{unset($this->p[$x]);}
 public function get_json_params():array{return $this->j;}
}
class WP_REST_Response {public function __construct(public array $data,public int $status=200){}}
class WP_Error {}
function is_wp_error($a){return $a instanceof WP_Error;}
function add_action(...$args){return true;}
function register_rest_route(...$args){return true;}
function rest_ensure_response($x){return new WP_REST_Response($x);}
function get_current_user_id(){return 26;}
function get_option($k,$fallback=null){return $GLOBALS['options'][$k]??$fallback;}
function add_option($k,$v,$deprecated='',$autoload=false){
 if(array_key_exists($k,$GLOBALS['options'])) return false;
 $GLOBALS['options'][$k]=$v;return true;
}
class MAD4B_Central_SSH_Command_Intake_V1 {
 const REQUEST_PREFIX='req:';const PREFIX='grant:';const TRANSPORT_PREFIX='transport:';
 public static function permitted($req){return true;}
}
class MAD4B_Company_Operation_Admission_V1 {public static function manager_id(){return 'central-manager';}}
class MAD4B_Manager_Auto_Write_V1 {
 public static function company_target($company){return ['state'=>'REGISTERED','site_uuid'=>'site-test-uuid','canonical_origin'=>'https://company-staging.example','environment'=>'staging'];}
}
class MAD4B_Company_Portfolio_Manager_V1{
 public static function has_delegation($manager,$company,$capability,$user){return $manager==='central-manager'&&$company==='staging-customer'&&$capability==='operations_manage'&&$user===26;}
}
class MAD4B_Native_VPS_Principal_V1 {}
function must($x,$tag){if(!$x){fwrite(STDERR,"FAILED: $tag\n");exit(1);}}
require dirname(__DIR__).'/candidate/306-mad4b-central-vps-broker.php';
$id=str_repeat('a',32);$g=str_repeat('b',32);$t=str_repeat('c',32);
$grant=['state'=>'CENTRAL_OPERATION_SCOPE_GRANTED','company'=>'staging-customer',
 'central_principal_id'=>26,'operation'=>'company.remote.wpcli.full','expires_epoch'=>null];
$req=['company'=>'staging-customer','status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'execution_performed'=>false,'expires_epoch'=>time()+240,'grant_id'=>$g,'central_principal_id'=>26,
 'operation'=>'company.remote.wpcli.full','grant_mode'=>'persistent','transport_id'=>$t,
 'target_host_id'=>'srv2030908','site_uuid'=>'site-test-uuid','canonical_origin'=>'https://company-staging.example',
 'environment'=>'staging','target_ssh_principal'=>'root','command_sha256'=>str_repeat('d',64),
 'command_format'=>'wpcli.argv.json.v1','target_scope'=>'wordpress','correlation_id'=>'staging-only-test'];
$transport=['state'=>'PENDING_INDEPENDENT_SSH_VERIFICATION','company'=>'staging-customer','host_id'=>'srv2030908','site_uuid'=>'site-test-uuid'];
$GLOBALS['options']=['req:'.$id=>$req,'grant:'.$g=>$grant,'transport:'.$t=>$transport,
 MAD4B_Central_VPS_Broker_V1::HOST_ID=>'srv2030908'];
$one=MAD4B_Central_VPS_Broker_V1::dispatch(new WP_REST_Request(['company'=>'staging-customer'],['request_id'=>$id]));
must($one instanceof WP_REST_Response,'exact dispatch');
must($one->status===202,'dispatched once');
must(($one->data['job_type']??null)==='signed_vps_wpcli','signed WordPress job format');
must(($one->data['command_format']??null)==='wpcli.argv.json.v1','canonical args');
must(($one->data['target_scope']??null)==='wordpress','wordPress target');
must(($one->data['target_host_id']??null)==='srv2030908','same host registered');
must(($one->data['executed']??true)===false,'dispatch never executes');
$again=MAD4B_Central_VPS_Broker_V1::dispatch(new WP_REST_Request(['company'=>'staging-customer'],['request_id'=>$id]));
must($again instanceof WP_REST_Response&&$again->status===200,'idempotent dispatch');
must(count(array_filter(array_keys($GLOBALS['options']),fn($k)=>str_starts_with($k,'mad4b_central_broker_job_v1_')))===1,'one job only');
echo "SAME_HOST_306_SIGNED_JOB_CONTRACT_PASS\n";
