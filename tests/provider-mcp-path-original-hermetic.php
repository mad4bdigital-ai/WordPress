<?php
/** Exact first-party WordPress Ability discovery, not a remote MCP handshake. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message=''){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['admin'];}
function wp_get_abilities(){
 if(($GLOBALS['registry_throw']??false)===true)throw new RuntimeException('private WordPress registry failure');
 return $GLOBALS['abilities'];
}
function mcp_test($ok,$description){
 if(!$ok){fwrite(STDERR,'FAIL: '.$description.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Elementor_Native_MCP_Awareness {
 static $status=['native_mcp'=>['handshake_state'=>'NOT_PROBED','site_enabled'=>false]];
 static function inspect(){return self::$status;}
}
class MAD4B_SCP_MCP_Provider_Readiness_Matrix {
 static $status=['provider_count'=>0,'providers'=>[],'shared_mcp_runtime'=>['state'=>'NOT_PROBED']];
 static function matrix(){return self::$status;}
}
class MockMcpAbility {
 public $category;
 public function __construct($value){$this->category=$value;}
 public function get_category(){
  if($this->category==='throw')throw new RuntimeException('provider class failed');
  return $this->category;
 }
}
$GLOBALS['admin']=true;
$GLOBALS['abilities']=[
 'elementor/get-document'=>new MockMcpAbility('mad4b-read'),
 'jetengine/create-query'=>new MockMcpAbility('native-category'),
 'otherprovider/inventory'=>new MockMcpAbility('native-category')
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-provider-mcp-path-review.php';
$c='MAD4B_SCP_Provider_MCP_Path_Review';
$report=$c::observe_local();
mcp_test(is_array($report)
 &&$report['external_native_mcp_discovery_performed']===false
 &&$report['write_authorized']===false
 &&$report['authorization_preserved']===true,
 'Actual local WordPress Abilities are only observed; no MCP peer/auth grant is inferred');
mcp_test(count($report['providers'])===4
 &&$report['other_discovered_ability_namespaces']['otherprovider']===1,
 'Foreign provider namespaces remain visible as unverified local tool names');
mcp_test($c::classify('elementor',[
 'elementor/update-widget-settings'=>['origin'=>'mad4b-adapter']])['state']
  ==='MAD4B_ADAPTER_DISCOVERED_NATIVE_UNKNOWN',
 'MAD4B Adapter remains distinct from independently verified native Elementor MCP');
mcp_test($c::classify('jetengine',[
 'jetengine/create-query'=>['origin'=>'provider-native-wordpress-ability']])['state']
  ==='NATIVE_WP_ABILITY_DISCOVERED_REVIEW_REQUIRED',
 'Even explicitly native WordPress publisher origin is not permission to mutate');
mcp_test($c::classify('wpml',['wpml/translations'=>['origin'=>'origin-unverified']])
 ['state']==='ADDITIONAL_LOCAL_ABILITIES_UNVERIFIED',
 'Unknown original WPML Ability never proves native transport unavailable');
$untrusted=$c::classify('elementor',[
 'elementor/get-document'=>['origin'=>new WP_Error('origin_unknown')]
]);
mcp_test($untrusted['state']==='ABILITY_ORIGIN_UNVERIFIED'
 &&$untrusted['native_wp_abilities']===[]
 &&$untrusted['write_authorized']===false,
 'Error-shaped native publisher claim must remain unverified without crashing PHP');
foreach([new WP_Error('unknown'),1,'true',null] as $invalid){
 $GLOBALS['admin']=$invalid;
 mcp_test(is_wp_error($c::observe_local()),
  'Unverified WordPress administrator capability must not disclose provider inventory');
}
$GLOBALS['admin']=true;
$GLOBALS['abilities']=new WP_Error('registry_unavailable');
mcp_test(is_wp_error($c::observe_local()),
 'WordPress Ability Registry error is not the same as verified zero Abilities');
$GLOBALS['registry_throw']=true;
mcp_test(is_wp_error($c::observe_local()),
 'Original Ability Registry exception must not crash MCP review or assert empty providers');
$GLOBALS['registry_throw']=false;
$GLOBALS['abilities']=['elementor/get-document'=>new MockMcpAbility(new WP_Error('bad_category'))];
$unknown=$c::observe_local();
mcp_test(is_array($unknown)
 &&$unknown['providers'][0]['state']==='ABILITY_ORIGIN_UNVERIFIED',
 'Error-shaped native get_category is not authenticated MAD4B/Elementor source');
$GLOBALS['abilities']=['elementor/get-document'=>new MockMcpAbility('throw')];
$unknown=$c::observe_local();
mcp_test(is_array($unknown)
 &&$unknown['providers'][0]['state']==='ABILITY_ORIGIN_UNVERIFIED',
 'Throwing WordPress Ability category must not crash original provider discovery');
$GLOBALS['abilities']=[];
MAD4B_SCP_MCP_Provider_Readiness_Matrix::$status=new WP_Error('matrix_unavailable');
mcp_test(is_wp_error($c::observe_local()),
 'Unverified native-provider readiness matrix cannot be represented as verified empty scan');
MAD4B_SCP_MCP_Provider_Readiness_Matrix::$status=['provider_count'=>0,'providers'=>[],'shared_mcp_runtime'=>['state'=>'NOT_PROBED']];
MAD4B_SCP_Elementor_Native_MCP_Awareness::$status=new WP_Error('elementor_unavailable');
mcp_test(is_wp_error($c::observe_local()),
 'Unverified native Elementor readback cannot be converted into a verified transport state');
echo 'PASS: PROVIDER_MCP_PATH_ORIGINAL '.($GLOBALS['checks']??0).
 ' original provider/Ability negative and read-only review cases'.PHP_EOL;
