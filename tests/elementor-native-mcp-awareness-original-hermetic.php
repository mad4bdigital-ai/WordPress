<?php
namespace Elementor\Modules\Mcp {
 class Module {
  public static $active=true,$exposed=true;
  public static function is_active(){
   if(self::$active==='throw')throw new \RuntimeException('Original module unavailable');
   return self::$active;
  }
  public static function is_site_mcp_exposure_enabled(){
   if(self::$exposed==='throw')throw new \RuntimeException('Original site exposure unavailable');
   return self::$exposed;
  }
 }
}
namespace WP\MCP\Core { class McpAdapter {} }
namespace {
 define('ABSPATH',__DIR__.'/');
 class WP_Error {
  function __construct($code='unknown'){}
 }
 function absint($id){return abs((int)$id);}
 function get_current_user_id(){return $GLOBALS['wp_user'];}
 function current_user_can($cap,...$args){return $GLOBALS['caps'][$cap]??false;}
 function wp_get_environment_type(){return 'staging';}
 function wp_get_abilities(){return [
  'elementor/edit-post'=>(object)[],
  'another/edit-post'=>(object)[]
 ];}
 class MAD4B_SCP_Site_Profile {
  static $state=['site_uuid'=>'11111111-1111-4111-8111-111111111111'];
  static function status(){return self::$state;}
 }
 function awareness_check($ok,$reason){
  if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
  $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
 }
 $GLOBALS['wp_user']=17;
 $GLOBALS['caps']=['edit_post'=>true,'edit_posts'=>true,'unfiltered_html'=>true];
 require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-elementor-native-mcp-awareness.php';
 $c='MAD4B_SCP_Elementor_Native_MCP_Awareness';
 $ready=$c::inspect(42);
 awareness_check($ready['native_mcp']['module_loaded']===true
  &&$ready['versions']['mcp_adapter_present']===true,
  'Native real Elementor and WordPress MCP adapter class names resolve accurately');
 awareness_check($ready['native_mcp']['adapter_compatible']===true
  &&$ready['native_mcp']['site_enabled']===true
  &&$ready['native_mcp']['handshake_verified']===false
  &&$ready['native_mcp']['native_execution_authorized']===false,
  'Local module status alone never fabricates authenticated MCP handshake or grant');
 awareness_check($ready['actor']['can_edit_target']===true
  &&$ready['authoring']['native_edit_ready_for_actor']===true
  &&$ready['production_mutation_allowed']===false
  &&$ready['write_authorized']===false,
  'Verified WordPress local editor may use visual UI but cannot silently grant MCP mutation');
 foreach([new WP_Error('native_unavailable'),null,1,'true'] as $uncertain){
  \Elementor\Modules\Mcp\Module::$active=$uncertain;
  $status=$c::inspect(42);
  awareness_check($status['native_mcp']['adapter_compatible']===false
   &&$status['native_mcp']['native_execution_authorized']===false,
   'Unverified native module active result is never a transport proof');
 }
 \Elementor\Modules\Mcp\Module::$active='throw';
 awareness_check($c::inspect(42)['native_mcp']['adapter_compatible']===false,
  'Original Elementor module exception cannot break public site readiness');
 \Elementor\Modules\Mcp\Module::$active=true;
 foreach([new WP_Error('exposure_unknown'),null,1,'true'] as $uncertain){
  \Elementor\Modules\Mcp\Module::$exposed=$uncertain;
  $status=$c::inspect(42);
  awareness_check($status['native_mcp']['site_enabled']===false
   &&$status['routing']['mcp']==='NATIVE_MCP_DISABLED_OR_UNAVAILABLE',
   'Unknown native Elementor site exposure cannot become enabled transport');
 }
 \Elementor\Modules\Mcp\Module::$exposed='throw';
 awareness_check($c::inspect(42)['native_mcp']['site_enabled']===false,
  'Original Elementor exposure exception cannot invent site-enabled MCP');
 \Elementor\Modules\Mcp\Module::$exposed=true;
 $GLOBALS['caps']['unfiltered_html']=false;
 $status=$c::inspect(42);
 awareness_check($status['authoring']['native_edit_ready_for_actor']===false
  &&$status['routing']['fallback']==='AWAIT_AUTHENTICATED_EDITOR',
  'WordPress unfiltered HTML absence cannot be bypassed to edit sensitive CSS');
 MAD4B_SCP_Site_Profile::$state=new WP_Error('original_site_identity_unknown');
 $unknown_site=$c::inspect(42);
 awareness_check($unknown_site['site_uuid']===''
  &&$unknown_site['production_mutation_allowed']===false,
  'Uncertain original Site Profile readback cannot be mistaken for site-bound execution');
 MAD4B_SCP_Site_Profile::$state=['site_uuid'=>'11111111-1111-4111-8111-111111111111'];
 $GLOBALS['wp_user']=0;
 $status=$c::inspect(42);
 awareness_check($status['actor']['authenticated_wordpress_user']===false
  &&$status['routing']['fallback']==='AWAIT_AUTHENTICATED_EDITOR',
  'Anonymous MCP discovery never proves WordPress editor authority');
 echo 'PASS: ELEMENTOR_NATIVE_MCP_AWARENESS '.($GLOBALS['checks']??0).
  ' exact native module/provider and no-handshake cases'.PHP_EOL;
}
