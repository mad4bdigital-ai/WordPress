<?php
/** Synthetic WordPress contracts. All test users and origins are fixtures. */
declare(strict_types=1);
define('ABSPATH','/unit-test/');
class WP_Error {
    private string $code;
    public function __construct(string $code,string $message='',array $meta=[]){$this->code=$code;}
    public function get_error_code():string{return $this->code;}
}
class WP_REST_Request implements ArrayAccess {
    private array $args;
    private array $json;
    private string $nonce;
    public function __construct(string $company,array $json=[],string $nonce='test-nonce'){
        $this->args=['company'=>$company];$this->json=$json;$this->nonce=$nonce;
    }
    public function offsetExists(mixed $offset):bool{return isset($this->args[$offset]);}
    public function offsetGet(mixed $offset):mixed{return $this->args[$offset]??null;}
    public function offsetSet(mixed $offset,mixed $value):void{$this->args[$offset]=$value;}
    public function offsetUnset(mixed $offset):void{unset($this->args[$offset]);}
    public function get_json_params():array{return $this->json;}
    public function get_header(string $name):string{return $name==='X-WP-Nonce'?$this->nonce:'';}
}
class FakeDB {
    public function prepare($sql,$arg){return str_replace('%s',"'".addslashes($arg)."'",$sql);}
    public function get_var($sql){return str_contains($sql,'GET_LOCK')?1:1;}
}
$GLOBALS['wpdb']=new FakeDB;
$GLOBALS['opts']=[];
$GLOBALS['uid']=1;
function get_option($n,$fallback=false){return $GLOBALS['opts'][$n]??$fallback;}
function update_option($n,$v,$autoload=false){$GLOBALS['opts'][$n]=$v;return true;}
function get_current_user_id(){return $GLOBALS['uid'];}
function is_user_logged_in(){return get_current_user_id()>0;}
function current_user_can($x){return in_array($x,['manage_options','activate_plugins'],true);}
function wp_verify_nonce($n,$action){return $n==='test-nonce' && $action==='wp_rest'?1:false;}
function wp_is_uuid($s){return is_string($s)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$s)===1;}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function rest_ensure_response($v){return $v;}
function add_action($name,$fn){return null;}
function must($x,$label){if(!$x)throw new RuntimeException($label);}
final class MAD4B_Company_Environment_Portfolio_Evidence_V1 {
    const ASSIGNMENTS='mad4b_company_environment_manager_assignments_v1';
    public static function enabled():bool{return true;}
    public static function readback($company,$env):array {
        return ['signed_identity_verified'=>true,'environment'=>'staging',
            'canonical_origin'=>'https://staging.example.test',
            'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000',
            'portfolio_binding_sha256'=>str_repeat('a',64)];
    }
}
final class MAD4B_Manager_Auto_Write_V1 {
    public static function enrolled_owner():int{return 1;}
}
final class MAD4B_Company_Portfolio_Manager_V1 {
    const MANAGERS='managers';const MEMBERS='members';
}
final class MAD4B_Company_Operation_Admission_V1 {
    public static function manager_id():string{return 'd9813e2d-d48a-52c7-ad48-8967a22656df';}
}
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$GLOBALS['opts']['managers']=[$manager=>[
    'manager_id'=>$manager,'manager_code'=>'wo-vacation','state'=>'registered']];
$GLOBALS['opts']['members']=[$manager.'|1'=>[
    'user_id'=>1,'state'=>'active']];
require dirname(__DIR__).'/317-mad4b-environment-manager-assignment.php';
$cls=MAD4B_Environment_Manager_Assignment_V1::class;
$company='example-company';$first=$cls::plan($company);
must($first['eligible']===true,'manager and signed site ready');
must($first['assignment_state']==='missing','no assignment implies missing');
must($first['native_executor_authorized']===false,'read-only status');
$payload=['action'=>'propose','expected_revision'=>0,
    'expected_binding_sha256'=>str_repeat('a',64)];
$notOwner=$cls::rest_write(new WP_REST_Request($company,$payload,'invalid'));
must(is_wp_error($notOwner)&&$notOwner->get_error_code()==='mad4b_environment_manager_original_owner_stepup_required','invalid nonce rejected');
must(get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[])===[],'no writes on auth reject');
$proposed=$cls::rest_write(new WP_REST_Request($company,$payload));
must(!is_wp_error($proposed),'owner proposal admitted');
must($proposed['assignment_state']==='proposed' && $proposed['revision']===1,'proposal readback');
$hash=$proposed['proposal_sha256'];
$badApprove=$cls::rest_write(new WP_REST_Request($company,[
    'action'=>'approve','expected_revision'=>1,
    'expected_binding_sha256'=>str_repeat('a',64),
    'expected_proposal_sha256'=>str_repeat('b',64)]));
must(is_wp_error($badApprove),'wrong exact proposal SHA denied');
$approved=$cls::rest_write(new WP_REST_Request($company,[
    'action'=>'approve','expected_revision'=>1,
    'expected_binding_sha256'=>str_repeat('a',64),
    'expected_proposal_sha256'=>$hash]));
must(!is_wp_error($approved) && $approved['assignment_state']==='approved'
     && $approved['revision']===2,'owner exact-proposal approval');
$activated=$cls::rest_write(new WP_REST_Request($company,[
    'action'=>'activate','expected_revision'=>2,
    'expected_binding_sha256'=>str_repeat('a',64),
    'expected_proposal_sha256'=>$hash]));
must(!is_wp_error($activated) && $activated['assignment_state']==='active'
     && $activated['revision']===3,'activation exact revision');
$records=get_option(MAD4B_Company_Environment_Portfolio_Evidence_V1::ASSIGNMENTS,[]);
must(count($records)===1 && ($records[$manager.'|'.$company.'|staging']['rights']??[])===['view_company','operations_manage'],'one scoped assignment');
must(($records[$manager.'|'.$company.'|staging']['remote_write_granted']??true)===false
     && ($records[$manager.'|'.$company.'|staging']['native_executor_enabled']??true)===false,
     'active portfolio is not remote write grant');
$stale=$cls::rest_write(new WP_REST_Request($company,[
    'action'=>'activate','expected_revision'=>2,
    'expected_binding_sha256'=>str_repeat('a',64),
    'expected_proposal_sha256'=>$hash]));
must(is_wp_error($stale),'stale revision denied');
$revoked=$cls::rest_write(new WP_REST_Request($company,[
    'action'=>'revoke','expected_revision'=>3,
    'expected_binding_sha256'=>str_repeat('a',64),
    'expected_proposal_sha256'=>$hash]));
must(!is_wp_error($revoked) && $revoked['assignment_state']==='revoked','revocation works');
$invalid=$cls::plan('invalid!!!');must($invalid['eligible']===false,'invalid company denied');
echo "MAD4B_EXACT_ENVIRONMENT_MANAGER_LIFECYCLE_PASS\n";
