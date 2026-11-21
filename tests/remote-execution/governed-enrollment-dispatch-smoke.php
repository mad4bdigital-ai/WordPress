<?php
/** Real Census + Enrollment Dispatcher + registered operation; no DB/OAuth/network. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('MAD4B_SCP_DIR', ABSPATH . 'wp-content/plugins/mad4b-site-control-plane/');
class WP_Error {
    private $code;
    public function __construct($code,$msg=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function add_action(...$args){}
function current_user_can($ability){return $ability==='manage_options';}
function get_current_user_id(){return 2;}
function wp_has_ability($id){return isset($GLOBALS['abilities'][$id]);}
function wp_get_ability($id){return $GLOBALS['abilities'][$id]??null;}
function wp_get_abilities(){return $GLOBALS['abilities'];}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=new WP_Ability($name,$args);return true;}
class WP_Ability {
    private $name,$meta,$schema,$execute_callback,$permission_callback;
    public function __construct($name,$args){$this->name=$name;$this->meta=$args['meta'];$this->schema=$args['input_schema'];$this->execute_callback=$args['execute_callback'];$this->permission_callback=$args['permission_callback'];}
    public function get_name(){return $this->name;}
    public function get_meta(){return $this->meta;}
    public function get_input_schema(){return $this->schema;}
    public function execute($args){$GLOBALS['execute_calls']=($GLOBALS['execute_calls']??0)+1;return call_user_func($this->execute_callback,$args);}
}
class MAD4B_SCP_Canonicalization {
    public static function semantic_operation_id($s){return preg_match('/^[a-z][a-z0-9.\-]{1,100}$/D',$s)?$s:new WP_Error('bad_operation_id');}
    public static function ability_name($s){return preg_match('#^[a-z0-9][a-z0-9_-]*/[a-z0-9-]+$#D',$s)?$s:new WP_Error('bad_ability');}
}
class MAD4B_SCP_Site_Profile {
    public static function configured(){return empty($GLOBALS['not_configured']);}
    public static function current_environment(){return $GLOBALS['env']??'production';}
    public static function status(){return array('authority_ready'=>empty($GLOBALS['site_revoked']),'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c');}
    public static function user_is_enrolled($id){return $id===2;}
    public static function site_uuid(){return 'a3a7cc36-db7e-4a14-8456-b41857def05c';}
    public static function revision(){return 4;}
    public static function profile_digest(){return str_repeat('a',64);}
    public static function origin_enrolled(){return empty($GLOBALS['site_revoked']);}
    public static function site_urls_match_enrollment(){return empty($GLOBALS['origin_wrong']);}
    public static function managed_runtime_enabled(){return empty($GLOBALS['runtime_off']);}
    public static function write_enabled(){return empty($GLOBALS['write_off']);}
}
class MAD4B_SCP_Governed_Runtime_Gates {
    public static function raw_sql_breakglass_enabled(){return false;}
    public static function production_mutation_enabled(){return !empty($GLOBALS['production_enabled']);}
}
class MAD4B_SCP_Policy_Resolution {
    public static function environment_allowed($env){return empty($GLOBALS['policy_denies'])&&in_array($env,array('production','staging','development'),true);}
    public static function current_operating_mode(){return 'SINGLE_OWNER_HARDENED';}
    public static function config_digest(){return str_repeat('b',64);}
    public static function resolve($facts){return array('decision'=>empty($GLOBALS['governance_denies'])?'ALLOW':'DENY','decision_sha256'=>hash('sha256',json_encode($facts)));}
}
class MAD4B_SCP_Developer_Authority {
    public static function can_access($input=null){return true;}
}
class MAD4B_SCP_Remote_Operation_Parity {
    public static function catalog(){return array();}
    public static function enrollment_abilities(){return array();}
    public static function reviewed_owner_staging_convergence($id,$row){return false;}
    public static function can_execute($params=null){
        $GLOBALS['parity_calls']=($GLOBALS['parity_calls']??0)+1;
        return 'production'===MAD4B_SCP_Site_Profile::current_environment()
            ? new WP_Error('mad4b_remote_operation_staging_only'):true;
    }
}
class MAD4B_SCP_Servers {
    public static function ability_is_mounted($server,$ability){return $server==='mad4b-enrollment'&&$ability==='mad4b/operator-grants-converge';}
}
class MAD4B_SCP_Local_OAuth_Server {const CHATGPT_CIMD_CLIENT_ID='https://chatgpt.com/connector-client';}
class MAD4B_SCP_OAuth_Resource_Bridge {
    const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
    public static function verified_bearer_active(){return empty($GLOBALS['bearer_revoked']);}
    public static function verified_bearer_has_scope($v){return $v===self::AUTHORITY_STEP_UP_SCOPE;}
    public static function verified_bearer_client_is($c){return $c===MAD4B_SCP_Local_OAuth_Server::CHATGPT_CIMD_CLIENT_ID;}
}
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-operation-registry.php';
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-registered-write-admission.php';
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-governed-ability-census.php';
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-enrollment-dispatch.php';
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-operator-grant-convergence.php';
MAD4B_SCP_Operator_Grant_Convergence::register_ability();
function expect($name,$allowed,$expected_code=''){
    $ids=MAD4B_SCP_Governed_Ability_Census::eligible();
    $candidate=$ids['mad4b/operator-grants-converge']??null;
    if(!$candidate||empty($candidate['policy_governed'])) {fwrite(STDERR,'FAIL '.$name.' candidate discovery'.PHP_EOL);exit(3);}
    $r=MAD4B_SCP_Enrollment_Dispatch::can_execute(array('operation_id'=>$candidate['operation_id']));
    $code=is_wp_error($r)?$r->get_error_code():'';
    if(($allowed&&!($r===true))||(!$allowed&&$code!==$expected_code)){
        fwrite(STDERR,'FAIL '.$name.' expected '.$expected_code.' got '.$code.PHP_EOL);exit(4);
    }
    echo 'PASS '.$name.PHP_EOL;
}
$GLOBALS['production_enabled']=true;
expect('Production profile plus effective registry governance structurally eligible',true);
$GLOBALS['production_enabled']=false;
expect('Production inactive runtime governance denied',false,'mad4b_registered_write_runtime_policy_required');
$GLOBALS['production_enabled']=true;
$GLOBALS['policy_denies']=true;
expect('Government policy denies even if Profile grants Write',false,'mad4b_enrollment_dispatch_managed_profile_required');
unset($GLOBALS['policy_denies']);
$GLOBALS['governance_denies']=true;
expect('Registered governance denies execution',false,'mad4b_registered_write_governance_denied');
unset($GLOBALS['governance_denies']);
$GLOBALS['env']='staging';$GLOBALS['production_enabled']=false;
expect('Staging uses same governed admission and grants none',true);
if(!empty($GLOBALS['execute_calls']))exit(5);
$meta=wp_get_ability('mad4b/operator-grants-converge')->get_meta()['mcp'];
if(($meta['production_mutation_allowed']??'')!=='policy_governed'||($meta['production_allowed']??'')!=='policy_governed')exit(6);
if(($meta['governed_operation_policy']??'')!=='registered_write_policy_v1')exit(7);
echo 'PASS metadata is conditional governance not unconditional Production grant'.PHP_EOL;
// A direct call to Enrollment::execute() must enforce can_execute before
// inspecting input digests or reaching the child execution-fence path.
$GLOBALS['env']='production';$GLOBALS['production_enabled']=false;
$registered=MAD4B_SCP_Governed_Ability_Census::eligible();
$id=$registered['mad4b/operator-grants-converge']['operation_id'];
$direct=MAD4B_SCP_Enrollment_Dispatch::execute(array('operation_id'=>$id));
if(!is_wp_error($direct)||$direct->get_error_code()!=='mad4b_registered_write_runtime_policy_required')exit(8);
if(!empty($GLOBALS['execute_calls']))exit(9);
echo 'PASS direct dispatcher cannot bypass current runtime governance'.PHP_EOL;
$GLOBALS['production_enabled']=true;
$stale=MAD4B_SCP_Enrollment_Dispatch::execute(array('operation_id'=>$id,
    'expected_registration_digest'=>str_repeat('0',64)));
if(!is_wp_error($stale)||$stale->get_error_code()!=='mad4b_enrollment_dispatch_registration_drift')exit(10);
if(!empty($GLOBALS['execute_calls']))exit(11);
echo 'PASS exact broker digest drift denies execution'.PHP_EOL;
// Malicious metadata should not confer a governed route, even if the
// registered first-party callback identities are unchanged.
$ability=wp_get_ability('mad4b/operator-grants-converge');
$old_meta=$ability->get_meta();
$forged=$old_meta;$forged['mcp']['production_mutation_allowed']=true;
$GLOBALS['abilities']['mad4b/operator-grants-converge']=new WP_Ability(
    'mad4b/operator-grants-converge',array('meta'=>$forged,
        'input_schema'=>$ability->get_input_schema(),
        'execute_callback'=>array('MAD4B_SCP_Operator_Grant_Convergence','execute'),
        'permission_callback'=>array('MAD4B_SCP_Operator_Grant_Convergence','can_access')));
if(isset(MAD4B_SCP_Governed_Ability_Census::eligible()['mad4b/operator-grants-converge']))exit(12);
$GLOBALS['abilities']['mad4b/operator-grants-converge']=$ability;
echo 'PASS forged unconditional Production metadata not accepted'.PHP_EOL;
$diagnostic=MAD4B_SCP_Governed_Ability_Census::diagnostic('mad4b/operator-grants-converge');
if(empty($diagnostic['callback_provenance']['execute_callback'])
    ||empty($diagnostic['callback_provenance']['permission_callback']))exit(13);
echo 'PASS first-party callback provenance is independently verified'.PHP_EOL;
