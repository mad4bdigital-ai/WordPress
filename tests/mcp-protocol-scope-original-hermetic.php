<?php
/** First-party scoped MCP runtime read evidence; no transport or grant. */
define('ABSPATH',__DIR__.'/');
class WP_Error {function __construct($code='unknown',$message='',$data=[]){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function scope_assert($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);} $GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
class MAD4B_SCP_Provider_Contracts {
 static $installed='0.99.0', $contracts=[];
 static function installed_version($id){return self::$installed;}
 static function get_for_version($id,$version){return self::$contracts[$version]??[];}
 static function get($id){return ['plugin_file'=>'mcp-adapter/mcp-adapter.php'];}
}
class MAD4B_SCP_MCP_Adapter_Dynamic_Integrity {
 static $result=['ready'=>true,'layout_checked'=>true,
  'official_package_verified'=>false];
 static $calls=0;
 static function inspect($file,$version,$with_verification){
  self::$calls++;
  return self::$result;
 }
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-mcp-protocol-profile.php';
$c='MAD4B_SCP_MCP_Protocol_Profile';
$good=[
 'request_requires_mcp_runtime'=>true,'eligible'=>true,'request_scope_bypassed'=>false,
 'canonical_symbols_pinned'=>true,'critical_class_set_pinned'=>true,
 'runtime_from_official_plugin'=>true,'runtime_preclaimed'=>false,
 'transaction_pending'=>false,'state'=>'ready'
];
$GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$good;
$verified=$c::request_scope_evidence();
scope_assert($verified['canonical_mcp_request']===true &&
 $verified['canonical_bootstrap_verified']===true &&
 $verified['authorizing']===false,
 'Original scoped WordPress MCP runtime remains observed not authorizing');
foreach(['request_requires_mcp_runtime','eligible','canonical_symbols_pinned',
 'critical_class_set_pinned','runtime_from_official_plugin'] as $key){
 foreach([new WP_Error('proof_unknown'),1,'true',null] as $value){
  $GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$good;
  $GLOBALS['mad4b_scp_mcp_mu_bootstrap'][$key]=$value;
  $unknown=$c::request_scope_evidence();
  scope_assert($unknown['canonical_bootstrap_verified']===false,
   'Unverified positive MU Bootstrap status cannot certify runtime');
 }
}
foreach(['request_scope_bypassed','runtime_preclaimed','transaction_pending'] as $key){
 foreach([new WP_Error('proof_unknown'),1,'true',null] as $value){
  $GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$good;
  $GLOBALS['mad4b_scp_mcp_mu_bootstrap'][$key]=$value;
  $unknown=$c::request_scope_evidence();
  scope_assert($unknown['canonical_bootstrap_verified']===false,
   'Unverified negative MU Bootstrap status cannot certify runtime');
 }
}
$GLOBALS['mad4b_scp_mcp_mu_bootstrap']=$good;
scope_assert($c::request_scope_evidence()['canonical_bootstrap_verified']===true,
 'Exact runtime scope recovers after uncertain observations');
// A newly installed Adapter can be considered protocol-layout compatible
// only through exact original dynamic integrity booleans, not truthy values.
$baseline=$c::adapter_transport_compatibility('0.99.0','0.7.0');
scope_assert($baseline['protocol_compatible']===true
 &&$baseline['dynamic_installed_layout_verified']===true
 &&$baseline['authorizing']===false,
 'First-party installed Adapter with exact native source proof remains compatible but nonauthorizing');
foreach(['ready','layout_checked','official_package_verified'] as $field){
 foreach([new WP_Error('source_unknown'),1,'true',null] as $bad){
  MAD4B_SCP_MCP_Adapter_Dynamic_Integrity::$result=[
   'ready'=>true,'layout_checked'=>false,'official_package_verified'=>false];
  MAD4B_SCP_MCP_Adapter_Dynamic_Integrity::$result[$field]=$bad;
  $observed=$c::adapter_transport_compatibility('0.99.0','0.7.0');
  scope_assert($observed['protocol_compatible']===false
   &&$observed['dynamic_installed_layout_verified']===false,
   'Error-shaped native installed-Adapter source evidence cannot certify a future version');
 }
}
MAD4B_SCP_MCP_Adapter_Dynamic_Integrity::$result=[
 'ready'=>true,'layout_checked'=>true,'official_package_verified'=>false];
foreach([new WP_Error('installed_unknown'),[],true,null] as $bad){
 MAD4B_SCP_Provider_Contracts::$installed=$bad;
 $result=$c::adapter_transport_compatibility('0.99.0','0.7.0');
 scope_assert(is_array($result)&&$result['protocol_compatible']===false,
  'Unknown Provider Contract installed version cannot claim a dynamic Adapter match');
}
MAD4B_SCP_Provider_Contracts::$installed='0.99.0';
foreach([new WP_Error('runtime_unknown'),[],true,null] as $bad){
 $observed=$c::adapter_transport_compatibility($bad,'0.7.0');
 scope_assert(is_array($observed)&&$observed['protocol_compatible']===false,
  'Malformed runtime Adapter version cannot become a valid protocol compatibility claim');
}
scope_assert($c::adapter_transport_compatibility('0.7.0','0.7.0')['exact_adapter_version_match']===true,
 'Exact existing provider Adapter version remains protocol compatible without new grant');
echo 'PASS: MCP_PROTOCOL_SCOPE_ORIGINAL '.($GLOBALS['checks']??0).' source assertions'.PHP_EOL;
