<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {private $code; public function __construct($code,$message=''){$this->code=$code;} public function get_error_code(){return $this->code;}}
function is_wp_error($r){return $r instanceof WP_Error;}
function admin_url($path){return 'http://local.example/wp-admin/'.$path;}
function current_user_can($cap){return !empty($GLOBALS['admin']);}
function get_option($key,$default=array()){return $GLOBALS['option'][$key]??$default;}
function wp_get_connector($id){return $GLOBALS['connectors'][$id]??null;}
function _wp_connectors_get_api_key_source($name,$env='',$constant=''){return isset($GLOBALS['secret_options'][$name])?'database':'none';}
function wp_validate_redirect($url,$default=''){return str_starts_with($url,'http://local.example/wp-admin/')?$url:$default;}
function esc_url($x){return htmlspecialchars($x,ENT_QUOTES);}
function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES);}
$GLOBALS['admin']=true;$GLOBALS['option']=array();$GLOBALS['secret_options']=array('connectors_mad4b_openrouter_api_key'=>'highly-confidential-fixture');
$GLOBALS['connectors']=array(
 'google'=>array('type'=>'ai_provider','authentication'=>array('method'=>'api_key','setting_name'=>'connectors_ai_google_api_key'),'plugin'=>array('is_active'=>'__return_false')),
 'mad4b_openrouter'=>array('type'=>'mad4b_model_route','authentication'=>array('method'=>'api_key','setting_name'=>'connectors_mad4b_openrouter_api_key'),'plugin'=>array('is_active'=>'__return_true')),
);
function __return_false(){return false;}
function __return_true(){return true;}
class MAD4B_SCP_Browser_Acceptance_Admin_UI {
    public static function managed_executors(){return array(
     'cloudflare'=>array('Cloudflare Browser Run',array('CLOUDFLARE_ACCOUNT_ID','CLOUDFLARE_BROWSER_RUN_API_TOKEN')),
     'browserbase'=>array('Browserbase',array('BROWSERBASE_API_KEY')),
     'browserless'=>array('Browserless',array('BROWSERLESS_TOKEN')),
     'steel'=>array('Steel',array('STEEL_API_KEY')),
    );}
    public static function public_selection(){return array('executor'=>'browserbase','preference_valid'=>true);}
}
class MAD4B_SCP_Browser_Acceptance_Core {public static function capabilities(){return array('provider_count'=>2);}}
interface MAD4B_SCP_Search_SERP_Enrollment {public function enrollment();}
class TestSearch implements MAD4B_SCP_Search_SERP_Enrollment {
 public function enrollment(){return array('label'=>'TestSearch','fields'=>array('api_key'=>array('type'=>'password')));}
}
class MAD4B_SCP_Search_Providers {public static function adapters(){return array('testsearch'=>new TestSearch());}}
class MAD4B_SCP_Search_Provider_Connections {
 public static function status($id){return array('configured'=>true,'connection_state'=>'VERIFIED');}
}
class MAD4B_SCP_Provider_Connection_Center {
 public static function discover(){return array('providers'=>array(array('provider'=>'google_drive','label'=>'Google Drive',
 'connected'=>false,'health_state'=>'oauth_reconnect_required','owner_handoff_url'=>'http://local.example/wp-admin/admin.php?page=context',
 'injected_secret'=>'SENSITIVE_SHOULD_NEVER_PASS')));}
}
class MAD4B_SCP_G5_External_Providers {
 public static function adapters(){return !empty($GLOBALS['g5_enabled'])?array('new_verified_provider'=>new stdClass()):array();}
 public static function setup_preview($args){return array('provider_id'=>$args['provider_id'],
   'fields'=>array('account_id'=>array('label'=>'Account','kind'=>'account','required'=>true,'max_length'=>128),
                   'api_key'=>array('label'=>'API key','kind'=>'credential','required'=>true,'max_length'=>4096)));}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-setup-coverage.php';
$pass=0;
function ok($label,$cond){global $pass;if(!$cond){fwrite(STDERR,"FAIL $label\n");exit(1);}++$pass;echo "$label: PASS\n";}
$inventory=MAD4B_SCP_Provider_Setup_Coverage::inventory();
ok('inventory_read_only',is_array($inventory)&&!$inventory['mutation_performed']&&!$inventory['authorizing']);
ok('count_is_derived',count($inventory['providers'])===$inventory['provider_count']);
ok('browser_modes_four',count(array_filter($inventory['providers'],fn($x)=>$x['family']==='browser_runner'))===4);
$by_id=array_column($inventory['providers'],null,'provider_id');
ok('cloudflare_requires_two_real_fields',$by_id['cloudflare']['field_names']===array('CLOUDFLARE_ACCOUNT_ID','CLOUDFLARE_BROWSER_RUN_API_TOKEN'));
ok('browserbase_source_external',$by_id['browserbase']['credential_custody']==='external_runner_secret_store');
ok('selected_browser_not_certified',$by_id['browserbase']['execution_state']==='not_certified_by_preference');
ok('external_browser_secret_unverified',$by_id['browserless']['credential_state']==='unverifiable_from_wordpress');
ok('native_browser_no_api_key',$by_id['native_hybrid']['credential_state']==='not_required');
ok('core_inactive_provider_not_ready',$by_id['google']['execution_state']==='provider_plugin_inactive');
ok('openrouter_key_present_not_live_tested',$by_id['mad4b_openrouter']['connection_state']==='credential_present_not_live_tested');
ok('openrouter_key_field_exact',$by_id['mad4b_openrouter']['field_names']===array('connectors_mad4b_openrouter_api_key'));
ok('search_stored_credential_does_not_certify_paid_execution',$by_id['testsearch']['execution_state']==='account_tested_not_capture_certified');
ok('oauth_disconnected_distinct_from_credential',$by_id['google_drive']['execution_state']==='not_connected');
ok('generic_extractor_source_explicit',$by_id['generic_extractor']['field_names']===array('MAD4B_CONTEXT_EXTRACTOR_URL','MAD4B_CONTEXT_EXTRACTOR_TOKEN'));
ok('vault_fields_explicit',count($by_id['g6_secret_vault']['field_names'])===2);
ok('growth_adapter_count_unconfigured',$by_id['g5_adapter_registry']['credential_state']==='no_registered_adapters');
ok('no_password_leak',strpos(json_encode($inventory),'highly-confidential-fixture')===false);
ok('no_connector_account_secret_leak',strpos(json_encode($inventory),'SENSITIVE_SHOULD_NEVER_PASS')===false);
ok('no_secret_handles_or_value',count(array_filter($inventory['providers'],fn($x)=>$x['secret_included']||$x['secret_handle_included']))===0);
$GLOBALS['g5_enabled']=true;
$expanded=MAD4B_SCP_Provider_Setup_Coverage::inventory();
$growth=array_column($expanded['providers'],null,'provider_id');
ok('new_certified_g5_adapter_auto_discovered',isset($growth['new_verified_provider']));
ok('new_g5_fields_come_from_original_setup_preview',
   $growth['new_verified_provider']['field_names']===array('account_id','api_key'));
ok('g5_account_not_authorized_by_field_exposure',!$growth['new_verified_provider']['authorizing']
    && $growth['new_verified_provider']['execution_state']==='retained_reads_require_separate_execution_grant');

$GLOBALS['admin']=false;
ok('non_admin_cannot_inspect',is_wp_error(MAD4B_SCP_Provider_Setup_Coverage::inventory()));
echo "SUMMARY pass=$pass fail=0\n";
