<?php
define('ABSPATH', __DIR__);
define('WP_CLI', true);
$_SERVER['REQUEST_METHOD'] = 'GET'; // WP-CLI frequently sets this.
unset($_SERVER['HTTP_AUTHORIZATION']);
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string)$s)); }
$GLOBALS['uid'] = 1;
$GLOBALS['env'] = 'staging';
$GLOBALS['enrolled'] = true;
$GLOBALS['origin'] = true;
$GLOBALS['breakglass'] = false;
function get_current_user_id(){ return $GLOBALS['uid']; }
function current_user_can($cap){ return $GLOBALS['uid'] > 0 && in_array($cap, ['manage_options','activate_plugins'], true); }
class MAD4B_SCP_Site_Profile {
    public static function configured(){ return true; }
    public static function current_environment(){ return $GLOBALS['env']; }
    public static function origin_enrolled(){ return $GLOBALS['origin']; }
    public static function site_urls_match_enrollment(){ return $GLOBALS['origin']; }
    public static function user_is_enrolled($uid){ return $uid === 1 && $GLOBALS['enrolled']; }
}
class MAD4B_SCP_Policy {
    public static function can_breakglass(){ return $GLOBALS['breakglass']; }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-full-staging-authority.php';
function check_case($ok,$message){ if(!$ok){fwrite(STDERR,"FAIL ".$message."\n");exit(1);} }
$cls='MAD4B_SCP_Full_Staging_Authority';
check_case($cls::can_access() === true, 'enrolled CLI admin is allowed without OAuth');
check_case($cls::can_apply() === true, 'enrolled CLI can preflight apply');
$GLOBALS['uid']=2;
check_case(is_wp_error($cls::can_access()), 'unenrolled administrator denied');
check_case(is_wp_error($cls::can_apply()), 'unenrolled administrator cannot apply');
$GLOBALS['uid']=1; $GLOBALS['env']='production';
check_case(is_wp_error($cls::can_access()), 'Production denied');
$GLOBALS['env']='staging'; $GLOBALS['origin']=false;
check_case(is_wp_error($cls::can_access()), 'unmatched origin denied');
$GLOBALS['origin']=true; $GLOBALS['breakglass']=true;
check_case(is_wp_error($cls::can_access()), 'raw-sql breakglass denied');
$GLOBALS['breakglass']=false; $_SERVER['HTTP_AUTHORIZATION']='unexpected';
check_case(is_wp_error($cls::can_access()), 'mixed auth channel denied');
echo "PASS Full Staging Authority trusted WP-CLI access (8 checks)\n";
