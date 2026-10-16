<?php
/** No bearer can write through a logged-in local WordPress owner preview. */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
 private $c;
 function __construct($c,$message=''){ $this->c=$c; }
 function get_error_code(){return $this->c;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($c){return $GLOBALS['admin'] && $c==='manage_options';}
function is_admin(){return $GLOBALS['wp_admin'];}
function is_user_logged_in(){return $GLOBALS['logged_in'];}
function get_current_user_id(){return 7;}
function sanitize_key($v){return $v;}
class MAD4B_SCP_Staging_Write_Owner_Intent {static function owner(){return $GLOBALS['enrolled_owner'];}}
class MAD4B_SCP_Site_Profile {
 static function configured(){return true;}
 static function current_environment(){return 'staging';}
 static function origin_enrolled(){return true;}
 static function site_urls_match_enrollment(){return true;}
 static function user_is_enrolled($u){return 7===$u;}
}
class MAD4B_SCP_OAuth_Resource_Bridge {
 const AUTHORITY_STEP_UP_SCOPE='mad4b:authority:step-up';
 static function verified_bearer_active(){return false;}
 static function verified_bearer_has_scope($s){return false;}
}
class MAD4B_SCP_Policy { static function can_breakglass(){return false;} }
class MAD4B_SCP_Governed_Runtime_Gates {static function raw_sql_breakglass_enabled(){return false;}}
class MAD4B_SCP_Identity_Context {static function current(){return new WP_Error('no_bearer');}}
$GLOBALS['admin']=true;$GLOBALS['wp_admin']=true;$GLOBALS['logged_in']=true;$GLOBALS['enrolled_owner']=true;
require dirname(__DIR__) . '/includes/class-mad4b-scp-staging-write-authority-convergence.php';
function check($v,$label){if(!$v){fwrite(STDERR,"FAIL ".$label."\n");exit(1);}}
$cls='MAD4B_SCP_Staging_Write_Authority_Convergence';
$remote=$cls::can_access();
check(is_wp_error($remote)&&'mad4b_staging_write_authority_bearer_required'===$remote->get_error_code(),'no bearer cannot read normal remote route');
$reflection=new ReflectionClass($cls);
$flag=$reflection->getProperty('local_owner_preview');$flag->setAccessible(true);
$flag->setValue(null,true);
check($cls::can_access()===true,'temporary local preview allows registered owner read only');
$write=$cls::can_apply();
check(is_wp_error($write)&&'mad4b_local_owner_preview_never_executes'===$write->get_error_code(),'local preview cannot apply write');
$flag->setValue(null,false);
check(is_wp_error($cls::can_access()),'preview authority reset after read');
$GLOBALS['logged_in']=false;
$attempt=$cls::plan_for_local_owner();
check(is_wp_error($attempt)&&'mad4b_local_owner_preview_denied'===$attempt->get_error_code(),'logged-out owner denied');
$GLOBALS['logged_in']=true;$GLOBALS['enrolled_owner']=false;
$attempt=$cls::plan_for_local_owner();
check(is_wp_error($attempt)&&'mad4b_local_owner_preview_denied'===$attempt->get_error_code(),'unenrolled user denied');
$GLOBALS['enrolled_owner']=true;$GLOBALS['wp_admin']=false;
$attempt=$cls::plan_for_local_owner();
check(is_wp_error($attempt)&&'mad4b_local_owner_preview_denied'===$attempt->get_error_code(),'non-admin route denied');
echo "STAGING_WRITE_LOCAL_OWNER_READ_ONLY_ISOLATION: PASS\n";
