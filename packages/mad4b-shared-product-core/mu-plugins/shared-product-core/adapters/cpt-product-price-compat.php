<?php
/** Lab-only CPT product indicative getter. Reuse established rate projection and source policy. */
if (!defined('ABSPATH') || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
function shared_product_cpt_indicative_price($id) {
 $type=get_post_type($id);
 if($type==='tours-and-activities'){
  $candidates=[];
  $direct=get_post_meta($id,'start_price',true);
  $dc=strtoupper((string)get_post_meta($id,'tour_base_currency',true));
  if(is_numeric($direct)&&is_finite((float)$direct)&&(float)$direct>=0&&preg_match('/^[A-Z]{3}$/D',$dc))
   $candidates['direct']=['amount'=>(float)$direct,'currency'=>$dc];
  $projection=function_exists('pricing_tour_price_projection')?pricing_tour_price_projection($id):['status'=>'unavailable'];
  if(($projection['status']??'')==='available'){
   $b=$projection['best'];$candidates['rate']=['amount'=>$b['final'],'currency'=>$b['currency']];
  }
  // Never downgrade to Direct if an eligible Rate could not be converted.
  if(($projection['reason']??'')==='fx_unavailable_for_eligible_rate')return '';
  $choice=shared_product_resolve_price_sources($candidates,(string)get_post_meta($id,'tour_mode',true));
  if(is_wp_error($choice))return '';
  $amount=$choice['amount'];$from=$choice['currency'];
  $to=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
  if($from!==$to){
   $amount=function_exists('pricing_currency_convert')?pricing_currency_convert($amount,$from,$to):null;
   if($amount===null||!is_numeric($amount)||!is_finite((float)$amount))return '';
  }
  return wc_format_decimal($amount);
 }
 if($type==='tour-rates'){
  $p=function_exists('pricing_rate_presentation')?pricing_rate_presentation($id):['status'=>'unavailable'];
  if(($p['status']??'')!=='available')return '';
  $amount=$p['best']['final'];$from=$p['currency'];
  $to=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
  if($from!==$to){
   $amount=function_exists('pricing_currency_convert')?pricing_currency_convert($amount,$from,$to):null;
   if($amount===null)return '';
  }
  return wc_format_decimal($amount);
 }
 return null;
}
add_filter('woocommerce_product_get_price',static function($price,$product){
 if(!$product instanceof WC_Product)return $price;
 $type=get_post_type($product->get_id());
 if(!in_array($type,['tour-rates','tours-and-activities'],true))return $price;
 // Do not fall back to WC raw _price when current rate is ineligible or policy is strict.
 return shared_product_cpt_indicative_price($product->get_id());
},30,2);
