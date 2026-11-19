<?php
/** Native service integration: real source, isolated fake WP, no site/network. */
define('ABSPATH', __DIR__ . '/');
define('MAD4B_CSO_LOCAL_HTTP_LOOPBACK', true);
define('MAD4B_CSO_LOCAL_HTTP_TRANSPORT', 'ssh_tunnel');
define('WP_PLUGIN_DIR', sys_get_temp_dir().'/mad4b-provider-source-test-'.getmypid());
if(!function_exists('str_contains')){
  function str_contains($haystack,$needle){return strpos($haystack,$needle)!==false;}
}
if(!function_exists('str_starts_with')){
  function str_starts_with($haystack,$needle){return substr($haystack,0,strlen($needle))===$needle;}
}
class WP_Error {
 private $code;
 function __construct($c,$m='',$data=array()){$this->code=$c;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$s));}
function wp_parse_url($x,$component=-1){return $component<0?parse_url($x):parse_url($x,$component);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return $GLOBALS['native_env'];}
function home_url($path=''){return $GLOBALS['native_home'].$path;}
function is_ssl(){return $GLOBALS['native_ssl'];}
function current_user_can($cap){return !empty($GLOBALS['native_perms'][$cap]);}
function get_option($name,$fallback=array()){
 if($name==='active_plugins')return $GLOBALS['native_active_plugins'];
 return $fallback;
}
function get_plugins(){
 $GLOBALS['native_plugin_listing_calls']=($GLOBALS['native_plugin_listing_calls']??0)+1;
 return $GLOBALS['native_installed_plugins'];
}
function admin_url($path=''){return $GLOBALS['native_admin_base'].$path;}
class MAD4B_SCP_Site_Profile {
 public static function status(){return $GLOBALS['native_profile'];}
}
class MAD4B_SCP_Adaptive_Interaction_Broker {
 public static function permitted(){return $GLOBALS['native_broker_allowed'];}
}
class WooCommerce {}
class FakeNativeMaxmindDb {
 function get_database_path(){return $GLOBALS['native_geoip_path'];}
}
class FakeNativeMaxmindIntegration {
 function get_database_service(){return new FakeNativeMaxmindDb();}
 function get_option($key){return $GLOBALS['native_license_key'];}
}
class FakeNativeIntegrationRegistry {
 function get_integration($id) {
  return $GLOBALS['native_has_maxmind']?new FakeNativeMaxmindIntegration():null;
 }
}
function WC(){return $GLOBALS['native_wc'];}
function original_check($ok,$label){
 if(!$ok){fwrite(STDERR,"FAIL: ".$label.PHP_EOL);exit(1);}
 $GLOBALS['original_checks']=($GLOBALS['original_checks']??0)+1;
}
function reset_native(){
 $GLOBALS['native_env']='local';
 $GLOBALS['native_home']='http://127.0.0.1:8080';
 $GLOBALS['native_admin_base']='https://admin.example.invalid/wp-admin/';
 $GLOBALS['native_ssl']=false;
 $GLOBALS['native_perms']=array('manage_woocommerce'=>true);
 $GLOBALS['native_broker_allowed']=true;
 $GLOBALS['native_has_maxmind']=true;
 $GLOBALS['native_license_key']='';
 $GLOBALS['native_geoip_path']=WP_PLUGIN_DIR.'/../unavailable-geoip.mmdb';
 $GLOBALS['native_profile']=array('origin_match'=>true,'environment_match'=>true,
  'configured_environment'=>'local','wordpress_environment'=>'local');
 $GLOBALS['native_wc']=(object)array('integrations'=>new FakeNativeIntegrationRegistry());
 $GLOBALS['native_active_plugins']=array();
 $GLOBALS['native_installed_plugins']=array();
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-local-transport-policy.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-native-settings-locator.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-provider-installed-artifact.php';
reset_native();
$local='MAD4B_SCP_Local_Transport_Policy';
$origin='http://127.0.0.1:8080';
original_check($local::loopback_enabled($origin),'Exact verified local host may use OS loopback');
$server=array('HTTP_HOST'=>'127.0.0.1:8080','HTTP_ORIGIN'=>$origin,
  'HTTP_SEC_FETCH_SITE'=>'same-origin');
original_check($local::request_allowed($origin,$server,true),
 'POST request must bind same-origin OS loopback and local site');
foreach(array(
 'http://localhost:8080', 'http://127.0.0.1:80',
 'http://127.0.0.1:8080/wrong','https://127.0.0.1:8080',
 'http://user@127.0.0.1:8080','http://127.0.0.2:8080',
 'http://127.0.0.1:8080?x=1','http://[::1]:8080'
) as $foreign){
 original_check(!$local::loopback_enabled($foreign),
  'Remote/different Host or ambiguous authority cannot enable HTTP local: '.$foreign);
}
foreach(array('HTTP_X_FORWARDED_FOR','HTTP_FORWARDED','HTTP_X_REAL_IP',
 'HTTP_X_FORWARDED_HOST','HTTP_ORIGINAL_URL') as $proxy){
 $unsafe=$server;$unsafe[$proxy]='127.0.0.1';
 original_check(!$local::request_allowed($origin,$unsafe,true),
  'Reverse-proxy headers must not masquerade as OS loopback: '.$proxy);
}
$unsafe=$server;$unsafe['HTTP_SEC_FETCH_SITE']='cross-site';
original_check(!$local::request_allowed($origin,$unsafe,true),
 'Cross-origin POST cannot use local transport');
$unsafe=$server;$unsafe['HTTP_ORIGIN']='http://localhost:8080';
original_check(!$local::request_allowed($origin,$unsafe,true),
 'Origin mismatch cannot use local transport');
$GLOBALS['native_profile']['environment_match']=false;
original_check(!$local::loopback_enabled($origin),'Site Profile environment drift is blocked');
$GLOBALS['native_profile']['environment_match']=true;
$GLOBALS['native_env']='production';
original_check(!$local::loopback_enabled($origin),'Production cannot borrow local HTTP');
$GLOBALS['native_env']='local';
original_check($local::status($origin)['authorizing']===false
 && $local::status($origin)['enables_secret_provider']===false,
 'Local HTTP eligibility is nonauthorizing and never a secrets provider');

$settings='MAD4B_SCP_Native_Settings_Locator';
reset_native();
original_check(is_wp_error($settings::status('woocommerce/other')),
 'Unknown native settings target fails closed');
$GLOBALS['native_broker_allowed']=false;
original_check(is_wp_error($settings::status($settings::MAXMIND)),
 'Original adaptive broker authorization is required');
$GLOBALS['native_broker_allowed']=true;
$GLOBALS['native_perms']=array();
original_check(is_wp_error($settings::status($settings::MAXMIND)),
 'WooCommerce capability is required independently');
$GLOBALS['native_perms']=array('manage_woocommerce'=>true);
$GLOBALS['native_has_maxmind']=false;
original_check(is_wp_error($settings::status($settings::MAXMIND)),
 'Missing native Woo integration is not silently created');
$GLOBALS['native_has_maxmind']=true;
$waiting=$settings::status($settings::MAXMIND);
original_check(is_array($waiting)&&$waiting['state']==='WAITING_NATIVE_KEY'
 && $waiting['secret_value_exposed']===false
 && $waiting['native_write_performed']===false,
 'Native settings never expose credential or mutate configuration');
$GLOBALS['native_license_key']='fake-test-key-not-shared';
$pending=$settings::status($settings::MAXMIND);
original_check($pending['state']==='WAITING_NATIVE_DATABASE'
 && $pending['license_key_present']===true
 && !isset($pending['license_key']) && !$pending['automatic_retry_authorized'],
 'Credential presence is a boolean only; unverified DB remains missing');
original_check($pending['transport_attested']===false,
 'External admin page cannot inherit local transport attestation');
$GLOBALS['native_admin_base']='http://127.0.0.1:8080/wp-admin/';
$same_site=$settings::status($settings::MAXMIND);
original_check($same_site['transport_attested']===true
  && $same_site['native_write_performed']===false,
 'Same-origin, explicitly approved Local Lab may report transport eligibility without writing');

$artifact='MAD4B_SCP_Provider_Installed_Artifact';
$dir=WP_PLUGIN_DIR;
if(!is_dir($dir.'/sample-plugin'))mkdir($dir.'/sample-plugin',0700,true);
if(!is_dir($dir.'/other-plugin'))mkdir($dir.'/other-plugin',0700,true);
file_put_contents($dir.'/sample-plugin/plugin.php','<?php /* Test Plugin Version 3.1 */');
file_put_contents($dir.'/other-plugin/plugin.php','<?php /* Other Version 3.1 */');
reset_native();
$GLOBALS['native_installed_plugins']=array(
 'sample-plugin/plugin.php'=>array('Version'=>'3.1'),
 'other-plugin/plugin.php'=>array('Version'=>'3.1')
);
$GLOBALS['native_active_plugins']=array('sample-plugin/plugin.php');
$observed=$artifact::observe('sample',array(
 'artifact_plugin_files'=>array('sample-plugin/plugin.php')));
original_check($observed['bound']===true
 && $observed['state']==='exact_active_plugin_observed'
 && $observed['installed_version']==='3.1'
 && preg_match('/^[a-f0-9]{64}$/D',$observed['artifact_fingerprint'])
 && $observed['runtime_identity_only']===true
 && $observed['write_authorized']===false
 && $observed['external_package_certified']===false,
 'Exact installed ACTIVE plugin is evidence of identity, not of permission');
$disabled=$artifact::observe('sample',array(
 'artifact_plugin_files'=>array('other-plugin/plugin.php')));
original_check($disabled['bound']===false,'Inactive provider plugin cannot claim runtime artifact');
foreach(array('../sample-plugin/plugin.php',
 '/tmp/evil.php','../../etc/passwd') as $traversal){
 $bad=$artifact::observe('sample',array('artifact_plugin_files'=>array($traversal)));
 original_check($bad['bound']===false,
   'Untrusted traversal and absolute paths fail closed: '.$traversal);
}
$GLOBALS['native_active_plugins'][]='other-plugin/plugin.php';
$ambiguous=$artifact::observe('sample',array(
 'artifact_plugin_files'=>array('sample-plugin/plugin.php','other-plugin/plugin.php')));
original_check($ambiguous['bound']===false,
 'Two active candidates cannot be guessed as one provider');
$GLOBALS['native_active_plugins']=array('sample-plugin/plugin.php');
$adapter=new class {function runtime_version(){return '3.2';}};
$version_mismatch=$artifact::observe('sample',array(
 'artifact_plugin_files'=>array('sample-plugin/plugin.php')),$adapter);
original_check($version_mismatch['state']==='adapter_version_mismatch'
 && !$version_mismatch['bound'],
 'Original adapter runtime version drift blocks installed identity');
$calls_before=$GLOBALS['native_plugin_listing_calls'];
$long_candidate_list=array();
for($i=0;$i<9;$i++)$long_candidate_list[]='vendor-'.$i.'/plugin.php';
$overflow=$artifact::observe('sample',array(
 'artifact_plugin_files'=>$long_candidate_list));
original_check($overflow['state']==='not_resolved'
 && $overflow['runtime_identity_only']===true
 && !$overflow['bound']
 && $GLOBALS['native_plugin_listing_calls']===$calls_before,
 'A provider candidate list above eight is refused before enumerating installed plugins');
$outside=sys_get_temp_dir().'/mad4b-provider-external-test-'.getmypid().'.php';
file_put_contents($outside,'<?php /* Untrusted external plugin */');
$link=$dir.'/sample-plugin/outside.php';
symlink($outside,$link);
$GLOBALS['native_installed_plugins']['sample-plugin/outside.php']=array('Version'=>'3.1');
$GLOBALS['native_active_plugins']=array('sample-plugin/outside.php');
$symlinked=$artifact::observe('sample',array(
 'artifact_plugin_files'=>array('sample-plugin/outside.php')));
original_check(!$symlinked['bound'],
 'Symlink escaping the registered WordPress plugin root is not a native artifact');
unlink($link);unlink($outside);
unlink($dir.'/sample-plugin/plugin.php');
unlink($dir.'/other-plugin/plugin.php');
rmdir($dir.'/sample-plugin');
rmdir($dir.'/other-plugin');
rmdir($dir);
echo 'PASS: ORIGINAL_NATIVE_SERVICE_TRUST '.$GLOBALS['original_checks'].
  ' first-party read-only/permission denial cases'.PHP_EOL;
