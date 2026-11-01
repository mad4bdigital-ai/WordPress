<?php
/** Standalone local WP-CLI fallback contract; no database/network. */
define('ABSPATH',__DIR__.'/');
define('WP_CLI',true);
class WP_Error {
    private $code;
    public function __construct($code,$message='',$extra=array()){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class WP_CLI {
    public static $user='2';
    public static function get_config($key){return self::$user;}
    public static function add_command($name,$callback){}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function get_current_user_id(){return 2;}
function user_can($user,$cap){return (int)$user->ID===2;}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_get_environment_type(){return $GLOBALS['env']??'staging';}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
class MAD4B_Content_Hub {public static function permission(){return true;}}
class MAD4B_SCP_Identity_Context {public static function current(){return array();}}
class MAD4B_SCP_Delegation_Mode {
    public static function verified_wordpress_user($identity){
        return WP_CLI::$user==='2'?(object)array('ID'=>2):null;
    }
}
class MAD4B_SCP_Policy {
    public static $may_write=true;
    public static function can_mutate(){return self::$may_write;}
}
class MAD4B_SCP_Audit {
    public static $events=array();
    public static $summaries=array();
    public static function record($name,$summary,$status='ok'){
        self::$events[]=$status;
        self::$summaries[]=$summary;
        return array('status'=>$status);
    }
}
class FakeAbility {
    public function execute($input){
        if(!empty($input['reject']))return new WP_Error('fixture_write_rejected');
        return array('saved'=>true);
    }
}
function wp_get_ability($name){return new FakeAbility();}
require dirname(__DIR__).'/includes/class-mad4b-hub-cli-fallback.php';
function expect($value,$name){if(!$value)throw new RuntimeException($name);}
$read=MAD4B_Hub_CLI_Fallback::execute('status',array());
expect(!is_wp_error($read) && $read['transport']==='vps_wpcli_fallback','status');
expect($read['chatgpt_oauth_verified']===false,'honest_oauth');
expect(count(MAD4B_SCP_Audit::$events)===0,'read_no_audit_mutation');
$queue=MAD4B_Hub_CLI_Fallback::execute('queue-list',array());
expect(!is_wp_error($queue) && $queue['ability']==='mad4b/hub-bulk-list_queue','original_queue_read');
expect(count(MAD4B_SCP_Audit::$events)===0,'queue_read_without_write_audit');
expect($queue['authenticated_via']==='verified_wordpress_wpcli' && !$queue['chatgpt_oauth_verified'],'identity_not_spoofed');
$write=MAD4B_Hub_CLI_Fallback::execute('submit-keywords',array('job_id'=>'test'));
expect(!is_wp_error($write) && $write['result']['saved'],'write');
expect(MAD4B_SCP_Audit::$events===array('requested','ok'),'audit_success');
expect($write['result_received_from_ability']===true && $write['independent_readback_verified_by_transport']===false,'result_not_a_receipt');
expect(strlen(MAD4B_SCP_Audit::$summaries[1]['result_sha256'])===64,'audit_result_digest');
expect(MAD4B_SCP_Audit::$summaries[1]['principal_authentication']==='verified_wordpress_wpcli','audit_auth_provenance');
$fail=MAD4B_Hub_CLI_Fallback::execute('submit-keywords',array('reject'=>true));
expect(is_wp_error($fail)&&$fail->get_error_code()==='fixture_write_rejected','failed_write');
expect(array_slice(MAD4B_SCP_Audit::$events,-2)===array('requested','failed'),'audit_failure');
$unknown=MAD4B_Hub_CLI_Fallback::preflight('shell');
expect(is_wp_error($unknown)&&$unknown->get_error_code()==='mad4b_hub_cli_fallback_operation_not_allowlisted','shell_not_allowed');
WP_CLI::$user='';
$no_user=MAD4B_Hub_CLI_Fallback::preflight('status');
expect(is_wp_error($no_user),'missing_wp_user');
WP_CLI::$user='2';
$GLOBALS['env']='production';
$prod=MAD4B_Hub_CLI_Fallback::preflight('submit-keywords');
expect(is_array($prod) && $prod['site_environment']==='production','production_original_policy_governs');
MAD4B_SCP_Policy::$may_write=false;
$denied=MAD4B_Hub_CLI_Fallback::preflight('submit-keywords');
expect(is_wp_error($denied)&&$denied->get_error_code()==='mad4b_hub_cli_fallback_site_write_gate_denied','original_write_policy_controls');
echo "VPS_WPCLI_FALLBACK_CONTRACT=PASS\n";
