<?php
// Hermetic policy fixture. No WordPress site, DB credentials, owner identity,
// external dispatch or actual file mutation is needed.
define('ABSPATH','/');
define('WP_CLI',true);
define('DB_NAME','mad4b_test_db');
define('WP_ENVIRONMENT_TYPE',$argv[1]??'staging');
class WP_CLI {public static function add_command($name,$fn) {}}
class MAD4B_SCP_Site_Profile {
    public static $authority=true;
    public static function status() {
        return [
            'site_uuid'=>'test-site-uuid','configured'=>true,
            'configured_environment'=>WP_ENVIRONMENT_TYPE,'authority_ready'=>self::$authority,
            'same_origin_clone_protection'=>true,'deployment_binding_match'=>true,
            'origin_match'=>true,'mutation_pending_audit'=>false,'write_enabled'=>false,
        ];
    }
}
class MAD4B_SCP_Audit {
    public static $ready=true;
    public static function storage_status(){return ['ready'=>self::$ready,'append_only'=>self::$ready];}
}
class MAD4B_SCP_Content_Readiness_Alerts {
    public static $enabled=true;
    public static function rules(){return ['scheduled_scan_enabled'=>self::$enabled];}
}
function home_url(){return 'https://test-company.example';}
function wp_get_environment_type(){return WP_ENVIRONMENT_TYPE;}
function get_current_user_id(){return $GLOBALS['fake_user_id']??0;}
function wp_json_encode($x,$options=0){return json_encode($x,$options);}
require dirname(__DIR__,3).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-cli-execution-context.php';
function verify($name,$ok) {
    if (!$ok){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}
    echo "PASS ".$name."\n";
}
foreach (['MAD4B_CLI_TARGET_SITE_UUID','MAD4B_CLI_TARGET_ORIGIN',
          'MAD4B_CLI_TARGET_DATABASE','MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS'] as $name)
    putenv($name);
$read=MAD4B_SCP_CLI_Execution_Context::resolve('runtime.status.read',false);
verify('read uses enrolled site binding',$read['read_context_verified']===true);
$scan=MAD4B_SCP_CLI_Execution_Context::resolve('content_readiness.scheduled_scan',true);
verify('scheduled readiness is site-bound and allowed',$scan['local_operation_authorized']===true);
verify('production metadata is truthful',$scan['production_mutation_authorized']===(WP_ENVIRONMENT_TYPE==='production'));
foreach (['plugin.install','site.config.write','schema.apply','broker.dispatch'] as $op) {
    $blocked=MAD4B_SCP_CLI_Execution_Context::resolve($op,true);
    verify('unknown operation denied: '.$op,$blocked['local_operation_authorized']===false);
}
putenv('MAD4B_CLI_TARGET_SITE_UUID=foreign-company');
verify('partial foreign host assertion blocked',
    MAD4B_SCP_CLI_Execution_Context::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false);
putenv('MAD4B_CLI_TARGET_SITE_UUID');
MAD4B_SCP_Audit::$ready=false;
verify('audit loss blocks mutation',
    MAD4B_SCP_CLI_Execution_Context::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false);
MAD4B_SCP_Audit::$ready=true;
MAD4B_SCP_Content_Readiness_Alerts::$enabled=false;
verify('disabled site rule blocks mutation',
    MAD4B_SCP_CLI_Execution_Context::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false);
MAD4B_SCP_Content_Readiness_Alerts::$enabled=true;
$GLOBALS['fake_user_id']=99;
verify('WP CLI impersonation rejected',
    MAD4B_SCP_CLI_Execution_Context::resolve('content_readiness.scheduled_scan',true)['local_operation_authorized']===false);
echo json_encode(['result'=>'PASS','environment'=>WP_ENVIRONMENT_TYPE,'purpose'=>'exact local content-readiness only'])."\n";
