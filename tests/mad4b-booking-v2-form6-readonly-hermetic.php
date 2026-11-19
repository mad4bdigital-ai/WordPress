<?php
/** Execute the real site-scoped bootstrap without a WordPress database. */
declare(strict_types=1);
$mode=$argv[1]??'disabled';
if(!in_array($mode,['commerce','presentation','presentation_unadmitted','disabled'],true))exit(3);
define('ABSPATH','/');
$plugin=dirname(__DIR__).'/wp-content/plugins/mad4b-commerce';
define('WPMU_PLUGIN_DIR',$plugin.'/legacy-mu-tree');
if($mode==='commerce')define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
if($mode==='presentation'||$mode==='presentation_unadmitted')define('MAD4B_PRESENTATION_RUNTIME_ENABLED',true);
$GLOBALS['callbacks']=[]; $GLOBALS['hook_names']=[];
function add_action(string $name,$callback=null,int $priority=10,...$rest){$GLOBALS['callbacks'][$name][$priority][]=$callback;$GLOBALS['hook_names'][]=$name;}
function add_filter(string $name,$callback=null,int $priority=10,...$rest){$GLOBALS['callbacks'][$name][$priority][]=$callback;$GLOBALS['hook_names'][]=$name;}
final class MAD4B_SCP_Site_Profile {
 public static function status():array{
  $ok=($GLOBALS['mode']??'')!=='presentation_unadmitted';
  return ['configured'=>$ok,'origin_match'=>$ok,'environment_match'=>$ok,'deployment_binding_match'=>$ok];
 }
}
$GLOBALS['mode']=$mode;
require_once $plugin.'/modules/presentation/company-booking-v2-readonly-bootstrap-v1.php';
foreach($GLOBALS['callbacks']['plugins_loaded'][20]??[] as $callback)$callback();
if($mode==='commerce'){
 require_once WPMU_PLUGIN_DIR.'/shared-product-core/pricing/booking-configuration-v2.php';
 require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/woocommerce-booking-configuration.php';
}
$expected=in_array($mode,['commerce','presentation'],true);
$declared=function_exists('shared_product_booking_v2_preview')
 &&function_exists('shared_product_booking_configuration_quote')
 &&($mode!=='presentation'||function_exists('shared_presentation_form6_price_quote'));
$wooHooks=array_values(array_filter($GLOBALS['hook_names'],static fn($name)=>str_starts_with($name,'woocommerce_')));
$readOnly=defined('MAD4B_BOOKING_V2_READONLY_ENABLED')&&MAD4B_BOOKING_V2_READONLY_ENABLED===true;
$pass=$declared===$expected && $readOnly===($mode==='presentation') && ($mode==='commerce'?count($wooHooks)>=4:count($wooHooks)===0);
echo json_encode(['mode'=>$mode,'declared'=>$declared,'expected'=>$expected,'readonly_admitted'=>$readOnly,'woocommerce_hooks'=>count($wooHooks),'passed'=>$pass]),"\n";
exit($pass?0:2);
