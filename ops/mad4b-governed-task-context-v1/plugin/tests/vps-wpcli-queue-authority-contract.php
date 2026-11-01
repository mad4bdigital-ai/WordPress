<?php
// Standalone, no WordPress runtime or database: verify native VPS runner
// does not mistake transport access, a site label or a task ID for authority.
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
define('WP_CLI', true);
class WP_Error {
    private $code;
    public function __construct($code,$message=''){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class WP_CLI {public static function add_command($name,$handler){}}
$GLOBALS['wp_actor']=7;
$GLOBALS['current_actor']=7;
$GLOBALS['verified']=true;
$GLOBALS['can_write']=true;
$GLOBALS['activate_plugins']=true;
$GLOBALS['environment']='production';
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['current_actor'];}
function current_user_can($cap){return $cap==='activate_plugins' && $GLOBALS['activate_plugins'];}
function wp_get_environment_type(){return $GLOBALS['environment'];}
class MAD4B_Hub_Bulk_Jobs_Queue {
    public static function writable(){return $GLOBALS['can_write'];}
}
class MAD4B_Hub_CLI_Fallback {
    public static function preflight($operation){
        if($operation!=='queue-list'||!$GLOBALS['verified'])return new WP_Error('unverified');
        return array('actor_user_id'=>$GLOBALS['wp_actor'],
                     'authenticated_via'=>'verified_wordpress_wpcli');
    }
}
require dirname(__DIR__).'/includes/class-mad4b-hub-bulk-vps-runner.php';
function expect($ok,$name){if(!$ok)throw new RuntimeException($name);}
expect(MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'production_native_policy_allowed');
$GLOBALS['environment']='staging';
expect(MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'staging_native_policy_allowed');
$GLOBALS['wp_actor']=8;
expect(!MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'actor_mismatch_denied');
$GLOBALS['wp_actor']=7;
$GLOBALS['verified']=false;
expect(!MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'unverified_wpcli_denied');
$GLOBALS['verified']=true;
$GLOBALS['can_write']=false;
expect(!MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'original_policy_denial');
$GLOBALS['can_write']=true;
$GLOBALS['activate_plugins']=false;
expect(!MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'vps_runner'),'missing_wp_capability');
$GLOBALS['activate_plugins']=true;
expect(!MAD4B_Hub_Bulk_VPS_Runner::authorized(false,'mcp_client'),'no_mcp_client_impersonation');
$not_mcp=MAD4B_Hub_Bulk_VPS_Runner::tick(1,'','mcp_client',false);
expect(is_wp_error($not_mcp)&&$not_mcp->get_error_code()==='mad4b_hub_bulk_runner_executor_not_allowed','runner_cannot_claim_mcp_lane');
echo "VPS_WPCLI_QUEUE_AUTHORITY_CONTRACT=PASS\n";
