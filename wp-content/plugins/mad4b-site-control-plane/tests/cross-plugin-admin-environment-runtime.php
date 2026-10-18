<?php
if(!defined('ABSPATH')) define('ABSPATH','/tmp/mad4b-admin-fixture/');
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['site_environment']; }
    public static function origin_enrolled() { return $GLOBALS['enrolled']; }
}
function wp_get_environment_type() { return $GLOBALS['wp_environment']; }
require_once dirname(__DIR__).'/includes/class-mad4b-scp-cross-plugin-admin.php';
function require_test($pass,$label) { if(!$pass){fwrite(STDERR,$label."\n");exit(1);} }
$admin='MAD4B_SCP_Cross_Plugin_Admin';
$GLOBALS['enrolled']=true;
$GLOBALS['wp_environment']='staging'; $GLOBALS['site_environment']='staging';
require_test($admin::environment()==='staging','staging mode missing');
$GLOBALS['wp_environment']='production';
require_test($admin::environment()==='not_verified','production spoof via staging profile');
$GLOBALS['site_environment']='production';
require_test($admin::environment()==='production','production read planning unsupported');
$GLOBALS['enrolled']=false;
require_test($admin::environment()==='not_verified','unenrolled site accepted');
echo "CROSS_PLUGIN_ADMIN_ENVIRONMENT: PASS\n";
