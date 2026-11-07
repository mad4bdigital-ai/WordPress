<?php
define('ABSPATH','/tmp');
define('DAY_IN_SECONDS',86400);
$GLOBALS['saved']=[];
$GLOBALS['admin']=false;
class WP_Error {
    private string $code;
    public function __construct(string $code,string $message='',array $data=[]){$this->code=$code;}
    public function get_error_code():string{return $this->code;}
}
function is_wp_error($value):bool{return $value instanceof WP_Error;}
function current_user_can($cap):bool{return $GLOBALS['admin']&&$cap==='manage_options';}
function wp_is_uuid($value):bool{return is_string($value)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',$value)===1;}
function sanitize_key($name){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$name));}
function wp_json_encode($data,$options=0){return json_encode($data,$options);}
function get_option($name,$default=false){return $GLOBALS['saved'][$name]??$default;}
function add_option($name,$value,$empty='',$autoload=false){
 if(array_key_exists($name,$GLOBALS['saved']))return false;
 $GLOBALS['saved'][$name]=$value;return true;
}
function do_action($name,...$args){}
function add_action($name,$cb,$priority=10,$args=1){}
function register_rest_route($namespace,$route,$settings){}
class MAD4B_Company_Strategy_V1 {
 public static function central():bool{return true;}
}
class MAD4B_Company_Environment_Registry_V2 {
 public static function discover():array {
 return ['wayfatours'=>['environments'=>['staging'=>[
 'site_uuid'=>'00000000-0000-4000-8000-000000000001',
 'canonical_origin'=>'https://wayfatours.example',
 'authorized_connections'=>['signed_identity'=>['centrally_verified'=>true]]
 ]]]];
 }
}
class MAD4B_Company_Policy_Registry_V2 {
 public static function validate_rule(array $raw) {
    if(!isset($raw['provider_id'],$raw['values']))return new WP_Error('policy_provider_unknown');
    return array_replace(['state'=>'draft','conditions'=>[],'priority'=>0,'valid_from'=>'','valid_to'=>''], $raw);
 }
}
function check($label,$pass){if(!$pass)throw new Exception('FAIL '.$label);echo "PASS $label\n";}
function code($r){return is_wp_error($r)?$r->get_error_code():'OK';}
$source=getenv('MAD4B_BROKER_BRIDGE_FILE');
if(!$source)throw new Exception('MAD4B_BROKER_BRIDGE_FILE not set');
require $source;
$targets=MAD4B_Company_Policy_Broker_Bridge::targets();
check('dynamic target recognized',count($targets)===1);
check('identity verified target',$targets[0]['executor_allowed']);
$req=[
 'company_code'=>'wayfatours','environment'=>'staging',
 'site_uuid'=>'00000000-0000-4000-8000-000000000001',
 'canonical_origin'=>'https://wayfatours.example',
 'expected_registry_revision'=>7,
 'idempotency_key'=>'policy-tour-qa-test-v1',
 'policy'=>['provider_id'=>'native-rate-arbitration','scope'=>'tour','scope_id'=>123,
             'values'=>['mode'=>'unambiguous_only'],'state'=>'draft']
];
check('unauthenticated cannot plan',code(MAD4B_Company_Policy_Broker_Bridge::plan($req))==='policy_broker_central_admin_required');
$GLOBALS['admin']=true;
$r=MAD4B_Company_Policy_Broker_Bridge::plan($req);
check('valid plan created',is_array($r)&&$r['changed']===true);
check('plan has pinned remote UUID',$r['plan']['payload']['target_site_uuid']===$req['site_uuid']);
check('plan has expected target revision',$r['plan']['payload']['expected_registry_revision']===7);
check('no remote mutation performed',$r['plan']['remote_write_performed']===false);
check('requires original broker authority',$r['plan']['requires_original_305_authority']===true);
$repeat=MAD4B_Company_Policy_Broker_Bridge::plan($req);
check('idempotent repeat no duplicate',is_array($repeat)&&$repeat['changed']===false);
$changed=$req;$changed['policy']['values']['mode']='lowest_verified';
check('same idempotency key altered payload rejected',
 code(MAD4B_Company_Policy_Broker_Bridge::plan($changed))==='policy_broker_idempotency_key_reused');
$wrong=$req;$wrong['idempotency_key']='policy-other-site-v1';$wrong['site_uuid']='99999999-9999-4999-8999-999999999999';
check('wrong target cannot be planned',code(MAD4B_Company_Policy_Broker_Bridge::plan($wrong))==='policy_broker_target_binding_unverified');
$active=$req;$active['idempotency_key']='policy-active-qa-v1';$active['policy']['state']='active';
check('auto activation denied',code(MAD4B_Company_Policy_Broker_Bridge::plan($active))==='policy_broker_automatic_active_not_allowed');
echo "PASS 11 BROKER PLAN FIXTURES\n";
