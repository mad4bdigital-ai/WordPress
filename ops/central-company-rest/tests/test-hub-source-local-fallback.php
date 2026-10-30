<?php
/** Scoped default local fallback contract; isolated no-network regression. */
define('ABSPATH','/test/');
define('WP_CLI',true);
define('MAD4B_HUB_SOURCE_HOST_RUNNER',true);
$GLOBALS['options']=[];
$GLOBALS['actor']=7;
$GLOBALS['enrolled']=true;
$GLOBALS['bound']=false;
$GLOBALS['ready']=true;
$GLOBALS['active']=[];
$GLOBALS['created_jobs']=[];
$GLOBALS['abilities']=[];
class WP_Error {
    private $code;
    public function __construct($c,$message='',$args=[]) {$this->code=$c;}
    public function get_error_code(){return $this->code;}
}
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled(){return true;}
    public static function site_urls_match_enrollment(){return true;}
    public static function user_is_enrolled($id){return $GLOBALS['enrolled'] && $id===7;}
}
class MAD4B_SCP_ChatGPT_Auto_Binding {
    public static function status(){return ['client_observed'=>$GLOBALS['bound']];}
}
class MAD4B_Hub_Source_Sync_Jobs_V1 {
    public static function plan(){return [
        'ready'=>$GLOBALS['ready'],'existing_active_jobs'=>$GLOBALS['active'],
        'publish_enabled'=>false,'auto_merge_enabled'=>false,
        'transport'=>'wp_cli_cross_ssh','company_id'=>'tourism-almallahgroup',
        'provider_id'=>'tourism_wp','dataset_id'=>'tourism_main'];}
    public static function request($input){
        $GLOBALS['created_jobs'][]=$input;
        return ['job_id'=>'12345678-1234-4234-a234-123456789abc',
            'status'=>'queued','company_id'=>$input['company_id'],
            'provider_id'=>$input['provider_id'],'dataset_id'=>$input['dataset_id']];
    }
}
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function current_user_can($cap){return $GLOBALS['actor']===7 && $cap==='manage_options';}
function get_current_user_id(){return $GLOBALS['actor'];}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$args){}
function wp_has_ability($n){return false;}
function wp_register_ability($name,$args){$GLOBALS['abilities'][$name]=$args;return true;}
function check($ok,$label){static $n=0;$n++;if(!$ok){fwrite(STDERR,"FAIL $n: $label\n");exit(1);}$GLOBALS['tests']=$n;}
require dirname(__DIR__).'/central/277-mad4b-hub-source-local-fallback.php';
$T='MAD4B_Hub_Source_Local_Fallback_V1';
$s=$T::status();
check($s['default_fallback_enabled']===true,'Default enabled only on Staging site');
check($s['preferred_execution']==='vps_wpcli_fallback' && $s['fallback_eligible'],'Unbound external goes local');
check(!$s['fallback_on_authorization_denial'] && !$s['fallback_on_remote_401_or_403'],'No remote auth bypass');
check(!$s['automatic_polling'] && !$s['cron_enabled'],'No background polling');
check(!$s['production_allowed'] && !$s['publishing_enabled'],'No Production/publish');
check(!$s['cross_company_allowed'] && !$s['auto_merge_enabled'],'No cross-company fusion');
$input=['request_key'=>'host-root-tourism-test-01','reason'=>'external_client_unbound'];
$GLOBALS['actor']=0;
check(is_wp_error($T::request_from_host($input)),'Unprivileged WP CLI rejected');
$GLOBALS['actor']=7;$GLOBALS['enrolled']=false;
check(is_wp_error($T::request_from_host($input)),'Unenrolled administrator rejected');
$GLOBALS['enrolled']=true;
$GLOBALS['bound']=true;
check($T::status()['preferred_execution']==='external_control_mcp','Bound external is preferred');
check(is_wp_error($T::request_from_host($input)),'Bound external never falls back');
$GLOBALS['bound']=false;
$invalid=$input;$invalid['reason']='write_authorization_denied';
check(is_wp_error($T::request_from_host($invalid)),'Authorization denial not a fallback trigger');
$invalid=$input;$invalid['extra']='surprise';
check(is_wp_error($T::request_from_host($invalid)),'Unknown fallback request keys rejected');
$GLOBALS['options'][$T::ENABLED]=false;
check(!$T::status()['fallback_eligible'],'Explicit policy opt-out enforced');
check(is_wp_error($T::request_from_host($input)),'Disabled fallback cannot queue');
unset($GLOBALS['options'][$T::ENABLED]);
$GLOBALS['active']=[['job_id'=>'active','status'=>'queued']];
check(is_wp_error($T::request_from_host($input)),'Existing active Job blocks fallback');
$GLOBALS['active']=[];
$GLOBALS['ready']=false;
check(is_wp_error($T::request_from_host($input)),'Source Company policy not ready blocks fallback');
$GLOBALS['ready']=true;
$job=$T::request_from_host($input);
check(is_array($job)&&$job['status']==='queued','Enrolled VPS host queues source Job');
check(count($GLOBALS['created_jobs'])===1 &&
    $GLOBALS['created_jobs'][0]['company_id']==='tourism-almallahgroup',
    'Exactly one scoped company Job queued');
check(count(get_option($T::AUDIT,[]))===1 &&
    get_option($T::AUDIT,[])[0]['decision']==='host_request_authorized',
    'Audit recorded before the source Job');
$entry=get_option($T::AUDIT,[])[0];
check(!isset($entry['access_token'],$entry['password'],$entry['approval_ticket']),
    'No secrets or fabricated OAuth stored');
$T::register_abilities();
check(isset($GLOBALS['abilities']['mad4b/hub-source-fallback-status']) &&
    $GLOBALS['abilities']['mad4b/hub-source-fallback-status']['meta']['annotations']['readonly'],
    'Native MCP fallback status is a Read-only Ability');
echo 'PASS '.$GLOBALS['tests']." scoped VPS local fallback assertions\n";
