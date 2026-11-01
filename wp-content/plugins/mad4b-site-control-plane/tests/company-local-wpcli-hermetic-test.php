<?php
define('ABSPATH','/tmp/');
define('WP_CLI',true);
class WP_Error {private $code;function __construct($c,$m){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function home_url(){return $GLOBALS['home'];}
function wp_get_environment_type(){return $GLOBALS['env'];}
function get_user_by($field,$value){
    if (($field==='id' && (int)$value===42) || ($field==='login' && $value==='operator') || ($field==='email' && $value==='operator@example.test'))
        return (object)array('ID'=>42);
    if (($field==='id' && (int)$value===7)||($field==='login'&&$value==='other'))return (object)array('ID'=>7);
    return false;
}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class WP_CLI {
    static $user='operator';static $runs=0;
    static function get_config($k){return $k==='user'?self::$user:'';}
    static function runcommand($cmd,$opts){self::$runs++;if(!in_array($cmd,array('plugin list --format=json --fields=name,status,version','theme list --format=json --fields=name,status,version'),true))throw new Exception('Unexpected command');return '[{"name":"safe","status":"active","version":"1.0"}]';}
    static function add_command($n,$c){}
    static function error($x){throw new Exception($x);}
    static function line($x){}
}
class MAD4B_SCP_Policy {static $allowed=true;static function can_read(){return self::$allowed;}}
class MAD4B_SCP_Site_Profile {static $enrolled=true;static function configured(){return true;}static function origin_enrolled(){return self::$enrolled;}}
class MAD4B_SCP_Operational_Integrity {
    static $site='e5e64bca-ed62-48d2-b2b3-dff693cd702a';static $actor=42;
    static function capture(){return array('scope'=>array('site_uuid'=>self::$site,'environment'=>$GLOBALS['env'],'deployment_mode'=>'wordpress_dedicated'),'actor_user_id'=>self::$actor,'fingerprint'=>str_repeat('a',64));}
    static function assert_unchanged($before,$mutation=false){return true;}
}
class MAD4B_SCP_Operation_Registry {
    static $effect='read_only';
    static function operation($n){return array('effect'=>self::$effect,'pipeline_profile'=>'governed_read_only_v1','generic_mutation_dispatch'=>false);}
}
class MAD4B_SCP_Operation_Pipeline {
    static $mutating=false;
    static function compile($input){return array('ready'=>true,'executor'=>'mad4b/company-code-reconciliation-compare','authority_created'=>false,'mutation_performed'=>self::$mutating);}
}
require __DIR__.'/class-mad4b-scp-company-local-wpcli.php';
$GLOBALS['env']='staging';$GLOBALS['home']='https://tours.dev.mad4b.com';$n=0;
function chk($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(2);} $n++;}
function denied($x){return is_wp_error($x);}
$ok=MAD4B_SCP_Company_Local_WPCLI::inspect('plugins');
chk(is_array($ok) && $ok['items_count']===1 && $ok['site_uuid']===MAD4B_SCP_Operational_Integrity::$site && $ok['authorizing']===false && $ok['mutation_performed']===false,'official central staging readback');
chk(WP_CLI::$runs===1,'one fixed command');
$ok=MAD4B_SCP_Company_Local_WPCLI::inspect('themes');
chk(is_array($ok)&&$ok['kind']==='themes','theme readback');
chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('users')),'unsupported_kind');
// Temporary company Staging is NOT the official central Staging plane.
$central=MAD4B_SCP_Operational_Integrity::$site;
MAD4B_SCP_Operational_Integrity::$site='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_company_staging_site');
MAD4B_SCP_Operational_Integrity::$site=$central;
$GLOBALS['home']='https://staging.company.example.test';
chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_company_staging_origin');
$GLOBALS['home']='https://tours.dev.mad4b.com';
WP_CLI::$user='';chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'missing_cli_actor');
WP_CLI::$user='other';chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'mismatched_cli_actor');
WP_CLI::$user='operator';MAD4B_SCP_Operational_Integrity::$actor=0;chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'no_actor');
MAD4B_SCP_Operational_Integrity::$actor=42;$GLOBALS['env']='production';chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_production');
$GLOBALS['env']='staging';MAD4B_SCP_Site_Profile::$enrolled=false;chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_unenrolled');
MAD4B_SCP_Site_Profile::$enrolled=true;MAD4B_SCP_Policy::$allowed=false;chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_unprivileged');
MAD4B_SCP_Policy::$allowed=true;MAD4B_SCP_Operation_Registry::$effect='mutate';chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_mutation_operation');
MAD4B_SCP_Operation_Registry::$effect='read_only';MAD4B_SCP_Operation_Pipeline::$mutating=true;chk(denied(MAD4B_SCP_Company_Local_WPCLI::inspect('plugins')),'deny_mutating_pipeline');
echo 'QA31_COMPANY_LOCAL_WPCLI_HERMETIC: PASS '.$n." assertions\n";
