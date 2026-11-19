<?php
function pricing_display_currency(){
 $default=get_option('woocommerce_currency','USD');
 $wcml=$GLOBALS['woocommerce_wpml']->multi_currency??null;
 $chosen=is_object($wcml)&&method_exists($wcml,'get_client_currency')?$wcml->get_client_currency():apply_filters('wcml_client_currency',$default);
 $settings=get_option('_wcml_settings',[]);
 return is_string($chosen)&&isset($settings['currency_options'][$chosen])?$chosen:$default;
}
function pricing_currency_convert($value,$source,$target){
 if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<0)return null;
 $fx=pricing_public_fx_rate($source,$target);
 return $fx===null?null:round((float)$value*$fx,2);
}
function pricing_currency_markup($regular,$sale,$source,$target){
 $r=pricing_currency_convert($regular,$source,$target);
 if($r===null)return '<span class="price-unavailable">'.esc_html__('Price on request','woocommerce').'</span>';
 $s=$sale!==null?pricing_currency_convert($sale,$source,$target):null;
 if($s!==null&&$s>0&&$s<$r)return '<span class="price-amount"><del>'.wp_kses_post(wc_price($r,['currency'=>$target])).'</del> <ins>'.wp_kses_post(wc_price($s,['currency'=>$target])).'</ins></span>';
 return '<span class="price-amount">'.wp_kses_post(wc_price($r,['currency'=>$target])).'</span>';
}
function pricing_post_currency_display($id,$target=null){
 $post=get_post($id);if(!$post||$post->post_status!=='publish')return '<span class="price-unavailable">Price on request</span>';
 $target=$target?:pricing_display_currency();
 if($post->post_type==='tour-rates'&&function_exists('pricing_rate_presentation')){
  $p=pricing_rate_presentation($id);if(($p['status']??'')!=='available')return '<span class="price-unavailable">Price on request</span>';
  $b=$p['best'];return pricing_currency_markup($b['regular'],$b['discounted']?$b['final']:null,$p['currency'],$target);
 }
 if($post->post_type==='tours-and-activities'&&function_exists('shared_lab_tour_price_resolve')){
  $r=shared_lab_tour_price_resolve((int)$id);
  if(($r['status']??'')!=='available')return '<span class="price-unavailable">Price on request</span>';
  $amount=pricing_currency_convert($r['best']['final'],$r['currency'],$target);
  if($amount===null)return '<span class="price-unavailable">Price on request</span>';
  return '<span class="price-amount">'.wp_kses_post(wc_price($amount,['currency'=>$target])).'</span>';
 }
 if($post->post_type==='tours-and-activities'&&function_exists('pricing_tour_price_projection')){
  $amount=function_exists('shared_product_cpt_indicative_price')?shared_product_cpt_indicative_price($id):'';
  if($amount===''||!is_numeric($amount))return '<span class="price-unavailable">Price on request</span>';
  return '<span class="price-amount">'.wp_kses_post(wc_price((float)$amount,['currency'=>$target])).'</span>';
 }
 if($post->post_type==='product'&&function_exists('wc_get_product')){
  $product=wc_get_product($id);
  if(!$product)return '<span class="price-unavailable">Price on request</span>';
  return $product->get_price_html(); // WCML already handles native WooCommerce products; never convert twice.
 }
 // Extensible adapters for property, transport, and other custom rate types.
 $quote=apply_filters('pricing_post_quote',null,$post);
 if(!is_array($quote)||empty($quote['currency'])||!isset($quote['regular']))return '<span class="price-unavailable">Price on request</span>';
 return pricing_currency_markup($quote['regular'],$quote['sale']??null,$quote['currency'],$target);
}
add_shortcode('pricing_currency_price',static function($atts){$a=shortcode_atts(['id'=>0],$atts);return pricing_post_currency_display(absint($a['id'])?:get_the_ID());});
add_filter('pricing_post_quote', static function($quote,$post) {
 if ($quote!==null || $post->post_type!=='transportations')return $quote;
 $currency=get_post_meta($post->ID,'trans_base_currency',true);
 $regular=get_post_meta($post->ID,'trans_start_price',true);
 $sale=(string)get_post_meta($post->ID,'trans_is_on_sale',true)==='1'?get_post_meta($post->ID,'trans_sale_start_price',true):null;
 if(!preg_match('/^[A-Z]{3}$/',(string)$currency)||!is_numeric($regular)||(float)$regular<=0)return null;
 return ['currency'=>$currency,'regular'=>$regular,'sale'=>$sale];
},10,2);
