<?php
declare(strict_types=1);
define('ABSPATH','/test/');
final class WP_Error {
    public function __construct(public string $code, public string $message, public array $data=[]) {}
}
final class WP_REST_Response {
    public array $headers=[];
    public function __construct(public array $data) {}
    public function header($k,$v){$this->headers[$k]=$v;}
}
class WP_REST_Request implements ArrayAccess {
    public function __construct(private array $parameters=[]) {}
    public function offsetExists($key):bool{return isset($this->parameters[$key]);}
    public function offsetGet($key):mixed{return $this->parameters[$key]??null;}
    public function offsetSet($key,$value):void{$this->parameters[$key]=$value;}
    public function offsetUnset($key):void{unset($this->parameters[$key]);}
}
function is_wp_error($item):bool {return $item instanceof WP_Error;}
function rest_ensure_response($item){return new WP_REST_Response($item);}
$GLOBALS['options']=[];
$GLOBALS['routes']=[];
$GLOBALS['hooks']=[];
function add_action($hook,$fn,$priority=10){$GLOBALS['hooks'][$hook][]=$fn;}
function register_rest_route($ns,$path,$args){$GLOBALS['routes'][$ns.$path]=$args;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
final class MAD4B_Central_SSH_Command_Intake_V1 {const REQUEST_PREFIX='req:';}
final class MAD4B_Central_VPS_Broker_V1 {const JOB='job:'; const CLAIM='claim:';}
final class MAD4B_Manager_Auto_Write_V1 {
    public static function company_target($company){
        if($company!=='wayfatours')return [];
        return ['state'=>'REGISTERED','site_uuid'=>'00000000-0000-4000-8000-000000000001',
            'environment'=>'staging','canonical_origin'=>'https://wayfa.example'];
    }
}
final class MAD4B_Company_Portfolio_Manager_V1 {
    public static bool $authorized=false;
    public static function admin_permission(){return self::$authorized?true:new WP_Error('denied','Forbidden',[]);}
    public static function company_binding($company){
        if($company!=='wayfatours')return new WP_Error('unknown','Unknown',[]);
        return ['company_site_uuid'=>'00000000-0000-4000-8000-000000000001'];
    }
}
require dirname(__DIR__).'/includes/class-mad4b-company-faore-broker-readback.php';
function prove($truth,$message){if(!$truth)throw new RuntimeException($message);}
$c='MAD4B_Company_FAORE_Broker_Readback';
$job=str_repeat('a',32);
$record=['request_id'=>$job,'company'=>'wayfatours','environment'=>'staging',
 'site_uuid'=>'00000000-0000-4000-8000-000000000001',
 'canonical_origin'=>'https://wayfa.example',
 'status'=>'ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'execution_performed'=>false,'expires_epoch'=>time()+600];
$c::boot();($GLOBALS['hooks']['rest_api_init'][0])();
prove(is_wp_error($c::permission()),'admin must be required');
prove(is_array($GLOBALS['routes']['mad4b/v1/company-manager/faore/broker/(?P<company>[a-z][a-z0-9-]{2,47})/(?P<job_id>[a-f0-9]{32})']),'route not installed');
prove(is_wp_error($c::inspect('bad',$job)),'invalid company');
prove(is_wp_error($c::inspect('wayfatours','bad')),'invalid job');
prove(is_wp_error($c::inspect('other',$job)),'foreign tenant');
prove(is_wp_error($c::inspect('wayfatours',$job)),'missing 305');
$GLOBALS['options']['req:'.$job]=$record;
$one=$c::inspect('wayfatours',$job);
prove($one['stage_305']==='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY','305');
prove($one['stage_306']==='NOT_VERIFIED','unclaimed 306');
prove($one['execution_verified']===false,'305 cannot imply success');
$GLOBALS['options']['job:'.$job]=[
 'job_id'=>$job,'company'=>'wayfatours','environment'=>'staging',
 'site_uuid'=>$record['site_uuid'],'canonical_origin'=>$record['canonical_origin'],
 'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
 'executed'=>false,'expires_epoch'=>time()+500,
];
$GLOBALS['options']['claim:'.$job]=[
 'job_id'=>$job,'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
];
$claimed=$c::inspect('wayfatours',$job);
prove($claimed['stage_306']==='BROKER_CLAIM_OBSERVED_NOT_EXECUTED','306');
prove($claimed['stage_313']==='PENDING_INDEPENDENT_NATIVE_READBACK','313 false positive');
prove($claimed['write_authorized']===false,'no write authority');
$GLOBALS['options']['req:'.$job]['site_uuid']='00000000-0000-4000-8000-000000000099';
prove(is_wp_error($c::inspect('wayfatours',$job)),'cross tenant denied');
$GLOBALS['options']['req:'.$job]=$record;
$GLOBALS['options']['job:'.$job]['executed']=true;
prove($c::inspect('wayfatours',$job)['stage_306']==='NOT_VERIFIED','executed claim cannot prove pending');
$GLOBALS['options']['job:'.$job]['executed']=false;
MAD4B_Company_Portfolio_Manager_V1::$authorized=true;
$response=$c::respond(new WP_REST_Request(['company'=>'wayfatours','job_id'=>$job]));
prove($response instanceof WP_REST_Response,'REST response');
prove($response->headers['Cache-Control']==='private, no-store','no-store');
prove(!isset($response->data['grant_id']),'grant details not exposed');
echo "FAORE original broker inspection test PASS\n";
