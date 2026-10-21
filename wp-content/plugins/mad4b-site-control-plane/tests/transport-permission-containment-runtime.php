<?php
/** Real facade methods, transport binding and Policy; no WordPress boot or network. */
define('ABSPATH',__DIR__);
class WP_Error {
    private $code; private $data;
    function __construct($code,$message='',$data=array()) {$this->code=$code;$this->data=$data;}
    function get_error_code(){return $this->code;}
    function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower($v));}
function add_filter(...$args){}
function apply_filters($name,$value){if(!empty($GLOBALS['policy_fault']))throw new RuntimeException('PRIVATE policy');return $value;}
function current_user_can($cap){return $GLOBALS['policy_allow']??true;}
trait MAD4B_SCP_Server_Write_Catalog {}
class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
    static $fault=false; static $ready=true;
    static function runtime_transaction_gate(){if(self::$fault)throw new RuntimeException('PRIVATE gate');return array('ready'=>self::$ready,'blocker'=>'quarantine');}
}
class MAD4B_Native_Incident_MU {
    static $fault=false; static $stages=array();
    static function correlation_id(){return str_repeat('a',32);}
    static function record_throwable($e,$stage,$ability,$correlation){self::$stages[]=$stage;if(self::$fault)throw new RuntimeException('PRIVATE recorder');}
}
require __DIR__.'/../includes/class-mad4b-scp-policy.php';
require __DIR__.'/../includes/class-mad4b-scp-transport-context.php';
// Load the unchanged real class body while excluding unrelated file-level WP boot.
$source=file_get_contents(__DIR__.'/../includes/class-mad4b-scp-servers.php');
eval(substr($source,strpos($source,'final class MAD4B_SCP_Servers')));
function check_transport($ok,$why){if(!$ok)throw new RuntimeException($why);}
$request=new class {function get_route(){return '/mcp/mad4b-read';}};
check_transport(true===MAD4B_SCP_Servers::can_read_transport($request),'Healthy read rejected');
foreach(array('policy','binding','transaction') as $fault) {
    foreach(array(false,true) as $recorder_fault) {
        $GLOBALS['policy_fault']=$fault==='policy';
        MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$fault=$fault==='transaction';
        MAD4B_Native_Incident_MU::$fault=$recorder_fault;
        $target=$fault==='binding'?new class {function get_route(){throw new RuntimeException('PRIVATE route');}}:$request;
        $error=MAD4B_SCP_Servers::can_read_transport($target);
        check_transport(is_wp_error($error)&&$error->get_error_code()==='mad4b_transport_permission_exception','Exception escaped boundary');
        $data=$error->get_error_data();
        check_transport($data['status']===503&&$data['permission_granted_by_boundary']===false&&$data['blind_retry_allowed']===false&&strlen($data['correlation_id'])===32,'Failure fabricated authority/retry or lost correlation');
        check_transport(strpos(json_encode($data),'PRIVATE')===false,'Private error leaked');
        check_transport(MAD4B_SCP_Transport_Context::current_server_id()==='','Failed request retained binding');
    }
}
$GLOBALS['policy_fault']=false;MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$fault=false;
check_transport(true===MAD4B_SCP_Servers::can_read_transport($request),'Healthy request did not recover');
$GLOBALS['policy_allow']=false;
check_transport(MAD4B_SCP_Servers::can_read_transport($request)->get_error_code()==='mad4b_transport_read_permission_denied','Original read denial changed');
$GLOBALS['policy_allow']=true;
$wrong=new class {function get_route(){return '/mcp/mad4b-write';}};
check_transport(MAD4B_SCP_Servers::can_read_transport($wrong)->get_error_code()==='mad4b_transport_route_mismatch','Wrong route admitted');
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$ready=false;
check_transport(MAD4B_SCP_Servers::can_read_transport($request)->get_error_code()==='quarantine','Quarantine bypassed');
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$ready=true;
$denied=new WP_Error('original_denial');
check_transport($denied===MAD4B_SCP_Transport_Context::permission('mad4b-read',$request,function()use($denied){return $denied;}),'Policy WP_Error changed');
check_transport(array_unique(MAD4B_Native_Incident_MU::$stages)===array('transport_permission'),'Stage lost');
echo "TRANSPORT_PERMISSION_CONTAINMENT_PASS\n";
