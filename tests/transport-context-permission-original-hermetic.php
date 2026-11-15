<?php
/** Original MCP HTTP transport permission and request-local cleanup: no mutations. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code,$data;
 function __construct($code,$message='',$data=[]){$this->code=$code;$this->data=$data;}
 function get_error_code(){return $this->code;}
 function get_error_data(){return $this->data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return (string)$v;}
function transport_test($ok,$msg){
 if(!$ok){fwrite(STDERR,'FAIL: '.$msg.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Servers {
 static $expected=['mad4b-read','mad4b-chatgpt','mad4b-write'];
 static $candidate=true, $mounted=true;
 static function expected_server_ids(){return self::$expected;}
 static function is_external_write_candidate($name){return self::$candidate;}
 static function ability_is_mounted($server,$ability){return self::$mounted;}
}
class MAD4B_SCP_Staging_Write_Authority {
 static $effective=true, $bootstrap=false, $write=true;
 static function effective(){return self::$effective;}
 static function candidate_bootstrap_allowed($ability,$input){return self::$bootstrap;}
 static function candidate_bootstrap_status($ability,$input){return ['eligible'=>false];}
 static function is_write_ability($name){return self::$write;}
}

class MAD4B_SCP_MCP_MU_Bootstrap_Refresh {
 static $gate=['ready'=>true,'state'=>'committed'];
 static function runtime_transaction_gate(){return self::$gate;}
}
function wp_has_ability($name){return array_key_exists('has_ability',$GLOBALS)?$GLOBALS['has_ability']:true;}
function wp_get_ability($name){
 return new class {
  public function get_input_schema(){return ['type'=>'object','additionalProperties'=>false];}
 };
}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class TestMcpRequest {
 private $route;
 function __construct($route){$this->route=$route;}
 function get_route(){return $this->route;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-transport-context.php';
$c='MAD4B_SCP_Transport_Context';
$request=new TestMcpRequest('/mcp/mad4b-read');
transport_test($c::permission('mad4b-read',$request,function(){return true;})===true
 &&$c::current_server_id()==='mad4b-read',
 'Native original permission true preserves expected MCP transport for an authenticated request');
foreach([new WP_Error('policy_unknown'),1,'true',null,[]] as $unknown){
 $ret=$c::permission('mad4b-read',$request,function()use($unknown){return $unknown;});
 transport_test(is_wp_error($ret)||$ret===false,
  'Nonboolean policy decision is not an accepted MCP permission');
 transport_test($c::current_server_id()==='',
  'Denied or uncertain request does not retain a previous request-local MCP server binding');
}
$denied=new WP_Error('original_read_denial');
$ret=$c::permission('mad4b-read',$request,function()use($denied){return $denied;});
transport_test($ret===$denied&&$c::current_server_id()==='',
 'Original WordPress policy denial remains unchanged and clears transport binding');
$ret=$c::permission('mad4b-read',$request,function(){return false;});
transport_test(is_wp_error($ret)
 &&$ret->get_error_code()==='mad4b_transport_read_permission_denied'
 &&$c::current_server_id()==='',
 'False read permission returns existing native refusal and removes stale binding');
foreach([new WP_Error('mu_unknown'),false,1,'true',null,[]] as $not_ready){
 MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$gate=$not_ready;
 $ret=$c::permission('mad4b-read',$request,function(){return true;});
 transport_test(is_wp_error($ret)&&$c::current_server_id()==='',
  'Only original MU filesystem transaction boolean true can permit transport binding');
}
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$gate=['ready'=>true,'state'=>'committed'];
foreach([new WP_Error('unknown'),1,'true',[],null] as $wrong){
 MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$gate=['ready'=>$wrong,'blocker'=>new WP_Error('bad_blocker')];
 $ret=$c::permission('mad4b-read',$request,function(){return true;});
 transport_test(is_wp_error($ret)&&$c::current_server_id()==='',
  'Malformed MU blocker cannot crash or manufacture an authenticated transport');
}
MAD4B_SCP_MCP_MU_Bootstrap_Refresh::$gate=['ready'=>true,'state'=>'committed'];
$wrong=new TestMcpRequest('/mcp/mad4b-write');
$ret=$c::permission('mad4b-read',$wrong,function(){return true;});
transport_test(is_wp_error($ret)&&$c::current_server_id()==='',
 'Cross-server route mismatch is denied without retained identity');
foreach([new WP_Error('server_registry_unknown'),null,1,'true'] as $invalid){
 MAD4B_SCP_Servers::$expected=$invalid;
 $ret=$c::permission('mad4b-read',$request,function(){return true;});
 transport_test(is_wp_error($ret)&&$c::current_server_id()==='',
  'Unexpected native server registry cannot become a granted MCP endpoint');
}
MAD4B_SCP_Servers::$expected=['mad4b-read','mad4b-chatgpt','mad4b-write'];
$ret=$c::permission('mad4b-read',$request,function(){return true;});
transport_test($ret===true&&$c::current_server_id()==='mad4b-read',
 'Original legitimate read transport recovers after unverified requests');
$chat=new TestMcpRequest('/mcp/mad4b-chatgpt');
transport_test($c::permission('mad4b-chatgpt',$chat,function(){return true;})===true,
 'Original ChatGPT request-local binding is established for validated discovery');
$write_ability='mad4b/reviewed-provider-write';
transport_test($c::resolve_server_for_ability('mad4b-chatgpt',$write_ability)
  ==='mad4b-write','Exact native original Write authority and mounted Ability preserve governed lane');
foreach([new WP_Error('source_unknown'),1,'true',null,false] as $wrong){
 MAD4B_SCP_Staging_Write_Authority::$effective=$wrong;
 $result=$c::resolve_server_for_ability('mad4b-chatgpt',$write_ability);
 transport_test(is_wp_error($result),
  'Uncertain original Staging Write effective() must never select write execution lane');
}
MAD4B_SCP_Staging_Write_Authority::$effective=false;
foreach([new WP_Error('bootstrap_unknown'),1,'true',null,false] as $wrong){
 MAD4B_SCP_Staging_Write_Authority::$bootstrap=$wrong;
 $result=$c::resolve_server_for_ability('mad4b-chatgpt',$write_ability);
 transport_test(is_wp_error($result),
  'Uncertain original candidate bootstrap status cannot route write execution');
}
MAD4B_SCP_Staging_Write_Authority::$bootstrap=false;
MAD4B_SCP_Staging_Write_Authority::$effective=true;
foreach([new WP_Error('ability_unknown'),1,'true',null,false] as $wrong){
 MAD4B_SCP_Staging_Write_Authority::$write=$wrong;
 transport_test(is_wp_error($c::resolve_server_for_ability('mad4b-chatgpt',$write_ability)),
  'Unknown original certified write Ability cannot be routed to mad4b-write');
}
MAD4B_SCP_Staging_Write_Authority::$write=true;
foreach([new WP_Error('mount_unknown'),1,'true',null,false] as $wrong){
 MAD4B_SCP_Servers::$mounted=$wrong;
 transport_test(is_wp_error($c::resolve_server_for_ability('mad4b-chatgpt',$write_ability)),
  'Unverified original write ability mount cannot authorize nested ChatGPT write routing');
}
MAD4B_SCP_Servers::$mounted=true;
foreach([new WP_Error('source_unknown'),1,'true',null] as $wrong){
 MAD4B_SCP_Servers::$candidate=$wrong;
 transport_test(is_wp_error($c::resolve_server_for_ability('mad4b-chatgpt',$write_ability)),
  'Unknown external write candidacy cannot fall through to a mounted ChatGPT execution lane');
}
MAD4B_SCP_Servers::$candidate=false;
transport_test(is_wp_error($c::resolve_server_for_ability('mad4b-write',$write_ability)),
 'A missing Write catalog entry cannot be routed as an ordinary ChatGPT read Ability');
transport_test($c::resolve_server_for_ability('mad4b-chatgpt','mad4b/read-catalog')
 ==='mad4b-chatgpt','A verified nonwrite mounted Ability keeps the existing normal ChatGPT transport');
MAD4B_SCP_Servers::$candidate=true;
transport_test($c::resolve_server_for_ability('mad4b-chatgpt',$write_ability)
 ==='mad4b-write','Verified original Write execution lane recovers after negative probes');
$schema_sha=hash('sha256',wp_json_encode(['type'=>'object','additionalProperties'=>false],
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$performed=0;
$callback=function()use(&$performed){$performed++;return 'original_bound_callback';};
foreach([new WP_Error('ability_unknown'),1,'true',null,false] as $wrong){
 $GLOBALS['has_ability']=$wrong;
 $actual=$c::with_write_dispatch_target($write_ability,$schema_sha,$callback);
 transport_test(is_wp_error($actual)&&$performed===0
  &&$c::write_dispatch_target_matches($write_ability)===false,
  'Unknown native WordPress Ability cannot run bound hidden write callback');
}
$GLOBALS['has_ability']=true;
foreach([new WP_Error('candidate_unknown'),1,'true',null,false] as $wrong){
 MAD4B_SCP_Servers::$candidate=$wrong;
 $actual=$c::with_write_dispatch_target($write_ability,$schema_sha,$callback);
 transport_test(is_wp_error($actual)&&$performed===0,
  'Unknown original write candidate cannot execute the nested dispatcher');
}
MAD4B_SCP_Servers::$candidate=true;
$actual=$c::with_write_dispatch_target($write_ability,$schema_sha,$callback);
transport_test($actual==='original_bound_callback' &&$performed===1
 &&$c::write_dispatch_target_matches($write_ability)===false,
 'Exactly scoped native dispatcher callback runs once with no persistent nested transport binding');
$bad=$c::with_write_dispatch_target($write_ability,str_repeat('f',64),$callback);
transport_test(is_wp_error($bad)&&$performed===1,
 'Changed source Ability input schema cannot execute a stale preapproved dispatcher plan');
$c::clear();
echo 'PASS: TRANSPORT_CONTEXT_PERMISSION '.($GLOBALS['checks']??0).
 ' original policy, gate, registry and request-bound denial cases'.PHP_EOL;
