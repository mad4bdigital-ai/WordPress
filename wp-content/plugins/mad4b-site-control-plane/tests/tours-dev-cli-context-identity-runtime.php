<?php
/** No live WP-CLI calls; verify enrolled source identity and operation boundaries. */
error_reporting(E_ALL);define('ABSPATH',__DIR__.'/');define('WP_CLI',true);define('WP_ENVIRONMENT_TYPE','staging');define('DB_NAME','fixture_database');
class WP_CLI {public static function add_command($name,$callback){$GLOBALS['commands'][]=$name;}}
function get_current_user_id(){return $GLOBALS['user_id'];}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function home_url($path='/'){return 'https://staging.example.test';}
function check_it($ok,$why){if(!$ok){fwrite(STDERR,"FAIL $why\n");exit(1);}}
$GLOBALS['user_id']=0;$GLOBALS['environment']='staging';$GLOBALS['commands']=array();
class MAD4B_SCP_Site_Profile {static $bound=true;static function status(){return array('site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','configured'=>true,'authority_ready'=>true,'same_origin_clone_protection'=>self::$bound,'deployment_binding_match'=>true,'origin_match'=>true,'mutation_pending_audit'=>false,'configured_environment'=>'staging','write_enabled'=>false);}}
class MAD4B_SCP_Audit {static function storage_status(){return array('ready'=>true,'append_only'=>true);}}
require dirname(__DIR__).'/includes/class-mad4b-scp-cli-execution-context.php';
check_it(in_array('mad4b context check',$GLOBALS['commands'],true),'Read-only CLI context command registers');
$v=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
check_it($v['read_context_verified']===true && $v['local_operation_authorized']===false,'Bound site can inspect read-only identity');
$unknown=MAD4B_SCP_CLI_Execution_Context::resolve('any.write.operation',true);
check_it($unknown['local_operation_authorized']===false,'Unknown mutation operation denied');
$GLOBALS['user_id']=99;
$imp=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
check_it($imp['read_context_verified']===false,'WP user impersonation denied');
$GLOBALS['user_id']=0;MAD4B_SCP_Site_Profile::$bound=false;
$clone=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
check_it($clone['read_context_verified']===false,'Clone protection mismatch denied');
MAD4B_SCP_Site_Profile::$bound=true;$GLOBALS['environment']='production';
$wrong=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
check_it($wrong['read_context_verified']===false,'Environment mismatch denied');
check_it($wrong['production_mutation_authorized']===false,'No production mutation grant');
echo "PASS: CLI signed Host prerequisite identity and non-impersonation guards (7+ checks)\n";
