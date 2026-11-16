<?php
/**
 * First-party Native MCP Provider Matrix; registration plus evidence-only
 * readiness under synthetic installed-plugin and ability registries.
 * No MCP initialize, tools/list, OAuth, classloader mutation or live probes.
 */
define('ABSPATH',__DIR__.'/');
if(!function_exists('str_starts_with')){function str_starts_with($text,$prefix){return substr($text,0,strlen($prefix))===$prefix;}}
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/mad4b-native-mcp-policy-'.getmypid();
if(!mkdir($tmp,0700)||!mkdir($tmp.'/config',0700))
 throw new RuntimeException('Isolated MCP policy fixture directory unavailable');
define('MAD4B_SCP_DIR',$tmp.'/');
$policyPath=$tmp.'/config/mcp-provider-handshake-inheritance.json';
$raw=file_get_contents($root.'/wp-content/plugins/mad4b-site-control-plane/config/mcp-provider-handshake-inheritance.json');
if(!is_string($raw)||!file_put_contents($policyPath,$raw))throw new RuntimeException('Source policy unavailable');
$GLOBALS['mcp_registry']=array();
$GLOBALS['mcp_plugin_options']=array(
 'elementor/elementor.php','seo-by-rank-math/rank-math.php');
$GLOBALS['mcp_php_abilities']=array('elementor/native-editor'=>array(),
 'fluent-crm/manage-contact'=>array(),
 'mad4b/site-profile-status'=>array());
$GLOBALS['mcp_writes']=0;
function add_action($name,$callback,$priority=10){}
function wp_register_ability($name,$cfg){$GLOBALS['mcp_registry'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['mcp_registry'][$name]);}
function get_option($name,$default=array()){
 return $name==='active_plugins'?$GLOBALS['mcp_plugin_options']:$default;
}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['mcp_writes'];throw new RuntimeException('Unexpected MCP write');
}
function wp_get_environment_type(){return 'production';}
function wp_get_abilities(){return $GLOBALS['mcp_php_abilities'];}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
class WP_Error {
 private $code;public function __construct($name,$description=''){$this->code=$name;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Site_Profile {
 public static function status(){return array('site_uuid'=>'site-provenance-demo');}
}
class MAD4B_SCP_Provider_Transport_Registry {
 public static function descriptors(){return array(
  array('provider_id'=>'elementor','kind'=>'native_mcp'),
  array('provider_id'=>'fluent-crm','kind'=>'native_ability_declaration')
 );}
}
class MAD4B_SCP_MCP_Hybrid {
 public static function manifest(){return array(
  'hostinger'=>array('bindings'=>array('tools/list'=>true))); }
}
function assert_mcp($ok,$message){if(!$ok)throw new RuntimeException('FAIL MCP MATRIX: '.$message);}
require $root.'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-provider-readiness-matrix.php';
$c='MAD4B_SCP_MCP_Provider_Readiness_Matrix';
$c::register_abilities();
assert_mcp(count($GLOBALS['mcp_registry'])===2,
 'Only two bounded original read-only WordPress abilities');
foreach($GLOBALS['mcp_registry'] as $name=>$config)
 assert_mcp($config['permission_callback']===array('MAD4B_SCP_Policy','can_read')
  && $config['meta']['annotations']['readonly']===true
  && $config['meta']['annotations']['destructive']===false
  && $config['meta']['mcp']['public']===false
  && $config['input_schema']['additionalProperties']===false,
 'Original provider inspection cannot register a write tool: '.$name);
$report=$c::matrix();
assert_mcp(is_array($report)
 && $report['provider_count']>=3
 && $report['provider_writes_performed']===false
 && $report['handshake_transport_probe_performed']===false
 && $report['auth_or_oauth_changes_performed']===false
 && $report['adapter_reinitialization_performed']===false
 && $report['write_authorized']===false
 && $report['shared_mcp_runtime']['oauth_session_inheritance_allowed']===false
 && $report['shared_mcp_runtime']['rebind_in_current_request_allowed']===false,
 'Local plugin metadata/Ability names may not become authenticated MCP');
foreach($report['providers'] as $item){
 assert_mcp($item['native_mcp']['initialize_verified']===false
  && $item['native_mcp']['tools_list_verified']===false
  && $item['native_mcp']['client_and_site_binding_verified']===false
  && $item['native_mcp']['native_mcp_write_ready']===false
  && $item['provider_execution_permitted_by_this_read']===false
  && $item['inherited_policy']['require_real_initialize']===true
  && $item['inherited_policy']['allow_write_from_local_registry_only']===false,
 'A discovered provider cannot inherit stale OAuth or writes');
}
$focused=$c::matrix(array('provider_id'=>'elementor'));
assert_mcp($focused['provider_count']===1
 && ($focused['providers']['elementor']['plugin_active']??null)===true,
 'Dynamic provider filter must retain installed-but-unhandshaken signal');
$unknown=$c::preflight(array('provider_id'=>'unknown-one'));
assert_mcp($unknown['provider_found']===false
 && $unknown['select_native_mcp_execution']===false
 && $unknown['reason']==='PROVIDER_NOT_IN_REGISTRY',
 'Missing provider cannot be approved through an arbitrary name');
$known=$c::preflight(array('provider_id'=>'fluent-crm'));
assert_mcp($known['provider_found']===true
 && $known['write_authorized']===false
 && $known['reason']==='AUTHENTICATED_INITIALIZE_TOOLS_LIST_AND_PROVIDER_BINDING_NOT_ATTESTED',
 'FluentCRM local Ability count must not become MCP handshake');
foreach(array('bad/foreign','Elementor','invalid space','rank.math','../elementor',str_repeat('a',65),123) as $noncanonical) {
 $bad=$c::matrix(array('provider_id'=>$noncanonical));
 $preflight=$c::preflight(array('provider_id'=>$noncanonical));
 assert_mcp($bad instanceof WP_Error && $preflight instanceof WP_Error
  && $bad->get_error_code()==='mad4b_mcp_readiness_provider_invalid'
  && $preflight->get_error_code()==='mad4b_mcp_readiness_provider_invalid',
  'Noncanonical input must never be silently mapped: '.json_encode($noncanonical).' matrix:'.($bad instanceof WP_Error?$bad->get_error_code():'not_error').' preflight:'.($preflight instanceof WP_Error?$preflight->get_error_code():'not_error'));
}
// Deliberately hostile JSON tries to turn off authentication, enable shared
// OAuth session inheritance and permit local-only writes. Original code
// inherits the strict baseline and must reject those overrides at runtime.
$unsafe=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
$unsafe['inherited_defaults']['require_real_initialize']=false;
$unsafe['inherited_defaults']['require_authenticated_principal']=false;
$unsafe['inherited_defaults']['allow_shared_oauth_session_inheritance']=true;
$unsafe['inherited_defaults']['allow_write_from_local_registry_only']=true;
$unsafe['inherited_defaults']['allow_automatic_adapter_class_rebinding']=true;
file_put_contents($policyPath,json_encode($unsafe));
$against=$c::preflight(array('provider_id'=>'fluent-crm'));
assert_mcp($against['provider']['inherited_policy']['require_real_initialize']===true
 && $against['provider']['inherited_policy']['require_authenticated_principal']===true
 && $against['provider']['inherited_policy']['allow_shared_oauth_session_inheritance']===false
 && $against['provider']['inherited_policy']['allow_write_from_local_registry_only']===false
 && $against['provider']['inherited_policy']['allow_automatic_adapter_class_rebinding']===false
 && $against['write_authorized']===false,
 'Untrusted provider JSON must never weaken inherited protocol and OAuth requirements');
assert_mcp($GLOBALS['mcp_writes']===0,'Readiness must not touch WordPress configuration');
unlink($policyPath);rmdir($tmp.'/config');rmdir($tmp);
echo "PASS: MCP_PROVIDER_MATRIX_SOURCE discovery without handshake and hostile policy overrides\n";
