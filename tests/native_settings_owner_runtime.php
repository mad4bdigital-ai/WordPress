<?php
define('ABSPATH','/tmp/');define('WP_HOME','https://site.example');define('WP_SITEURL','https://site.example');
class WP_Error {public function __construct(public $code,public $message,public $data=[]) {}}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_admin(){return true;}
function current_user_can($cap){return false;}
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-general-settings-contract.php';
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-settings-locator.php';
function check($v,$msg){if(!$v)throw new Exception($msg);}
$c='MAD4B_SCP_Native_General_Settings_Contract';
$allow=['general'=>['admin_email','home','siteurl','timezone_string','blogname']];
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['SCRIPT_NAME']='/wp-admin/options.php';$_POST['option_page']='general';
$v=$c::allowed_options($allow);
check($v['general']===['timezone_string','blogname'],'native protected fields editable');
$_SERVER['REQUEST_METHOD']='GET';check($c::allowed_options($allow)===$allow,'GET must be unchanged');
$_SERVER['REQUEST_METHOD']='POST';$_POST['option_page']='discussion';check($c::allowed_options($allow)===$allow,'other option group must be unchanged');
$l='MAD4B_SCP_Native_Settings_Locator';
check(is_wp_error($l::status('unexpected')),'invalid target accepted');
check(is_wp_error($l::status('woocommerce/maxmind-geolocation')),'unprivileged target accepted');
echo "NATIVE_SETTINGS_OWNER_RUNTIME: PASS protected immutable URLs, admin email, least privilege\n";
