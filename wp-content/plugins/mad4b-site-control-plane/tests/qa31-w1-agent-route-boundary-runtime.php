<?php
/** QA31 W1: REST authority of newly imported owner and agent grant surfaces. */
declare(strict_types=1);
define('ABSPATH','/tmp/qa31-rest-fixture');
define('WP_ENVIRONMENT_TYPE','staging');
$GLOBALS['qa31_hooks']=[];
$GLOBALS['qa31_routes']=[];
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['qa31_hooks'][$name][]=$callback;}
function register_rest_route($namespace,$route,$options){$GLOBALS['qa31_routes'][$namespace.$route]=$options;}
function wp_get_environment_type(){return 'staging';}
function current_user_can($cap){return false;}
function get_current_user_id(){return 0;}
function wp_get_session_token(){return '';}
function wp_verify_nonce($nonce,$action){return false;}
class WP_Error{
    private string $code;
    public function __construct($code,$message='',$data=null){$this->code=(string)$code;}
    public function get_error_code(){return $this->code;}
}
function qa31_assert($yes,$msg){if(!$yes){fwrite(STDERR,"FAIL $msg\n");exit(1);}echo "PASS $msg\n";}
require_once dirname(__DIR__).'/includes/class-mad4b-scp-company-owner-activation.php';
require_once dirname(__DIR__).'/includes/class-mad4b-scp-agent-grant-lifecycle.php';
MAD4B_SCP_Agent_Grant_Lifecycle::register_rest();
foreach(($GLOBALS['qa31_hooks']['rest_api_init']??[]) as $cb){$cb();}
$company=['/company-activation/plan'=>'GET','/company-activation/commit'=>'POST'];
foreach($company as $path=>$method){
    $route=$GLOBALS['qa31_routes']['mad4b/v1'.$path]??null;
    qa31_assert(is_array($route) && $route['methods']===$method, 'company route locked to declared method: '.$path);
    qa31_assert($route['permission_callback']===['MAD4B_SCP_Company_Owner_Activation','ownerPermission'], 'company owner callback required: '.$path);
    qa31_assert(call_user_func($route['permission_callback']) instanceof WP_Error, 'company API denies anonymous: '.$path);
}
foreach(['status','plan','request','decision','apply','revoke-plan','revoke-apply'] as $op){
    $route=$GLOBALS['qa31_routes']['mad4b/v1/agent-grants/'.$op]??null;
    qa31_assert(is_array($route) && $route['methods']==='POST', 'agent route requires POST: '.$op);
    qa31_assert($route['permission_callback']===['MAD4B_SCP_Agent_Grant_Lifecycle','owner'], 'agent grant requires owner callback: '.$op);
    qa31_assert(!call_user_func($route['permission_callback']), 'agent grant denies non-owner CLI: '.$op);
}
qa31_assert(count($GLOBALS['qa31_routes'])===9, 'only expected nine native routes registered');
echo "QA31_W1_AGENT_REST_ROUTING: PASS\n";
