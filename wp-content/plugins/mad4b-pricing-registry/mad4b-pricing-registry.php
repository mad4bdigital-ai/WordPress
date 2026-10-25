<?php
/**
 * Plugin Name: MAD4B Pricing Source Registry
 * Description: Read-only dynamic discovery and capability registry for existing pricing providers.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Pricing_Source_Registry {
 const CONTRACT='mad4b.pricing-source-registry.v1';
 public static function definitions(){
  $entries=[
   'tour_rate'=>['domain'=>'tour','capabilities'=>['dated','travellers','season'],'dependency'=>'shared_product_resolve_price_unit','source_type'=>'jetengine'],
   'tour_direct'=>['domain'=>'tour','capabilities'=>['travellers','fallback'],'dependency'=>'shared_product_resolve_price_unit','source_type'=>'post_meta'],
   'accommodation_rate'=>['domain'=>'accommodation','capabilities'=>['dated','occupancy','price_only'],'dependency'=>'abk_accommodation_shadow_quote','source_type'=>'jetengine'],
   'transport_direct'=>['domain'=>'transportation','capabilities'=>['vehicle','price_only'],'dependency'=>'shared_product_resolve_price_unit','source_type'=>'post_meta'],
   'market_adjustment'=>['domain'=>'common','capabilities'=>['market','nationality'],'dependency'=>'shared_product_market_price_apply','source_type'=>'policy'],
   'strategy_preset'=>['domain'=>'common','capabilities'=>['priority','adjustment'],'dependency'=>'BookingExperience\\pricing_preset_apply','source_type'=>'policy'],
  ];
  $entries=apply_filters('mad4b/pricing_registry/definitions',$entries);
  if(!is_array($entries))return [];
  $out=[];
  foreach($entries as $key=>$value){
   if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$key)||!is_array($value))continue;
   $domain=$value['domain']??'';$type=$value['source_type']??'';
   if(!is_string($domain)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$domain))continue;
   if(!in_array($type,['jetengine','post_meta','policy','external'],true))continue;
   $caps=array_values(array_filter($value['capabilities']??[],static fn($c)=>is_string($c)&&preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$c)));
   $dependency=$value['dependency']??'';
   if(!is_string($dependency)||!preg_match('/^[a-zA-Z_\\][a-zA-Z0-9_\\]*$/D',$dependency))continue;
   $out[$key]=['domain'=>$domain,'source_type'=>$type,'capabilities'=>array_values(array_unique($caps)),'available'=>function_exists($dependency),'dependency'=>$dependency];
  }
  ksort($out,SORT_STRING);
  return $out;
 }
 public static function snapshot($domain=''){
  $entries=self::definitions();
  if($domain!=='')$entries=array_filter($entries,static fn($e)=>$e['domain']===$domain||$e['domain']==='common');
  return ['contract'=>self::CONTRACT,'domain'=>$domain,'sources'=>$entries,'source_count'=>count($entries),'mutation_performed'=>false,'quote_authorized'=>false];
 }
 public static function rest(){
  register_rest_route('mad4b-pricing/v1','/sources',[
   'methods'=>'GET','callback'=>static function($request){$domain=sanitize_key((string)$request->get_param('domain'));return rest_ensure_response(self::snapshot($domain));},
   'permission_callback'=>static fn()=>current_user_can('manage_woocommerce'),
  ]);
 }
}
add_action('rest_api_init',['MAD4B_Pricing_Source_Registry','rest']);
