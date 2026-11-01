<?php
function pricing_price_amount($raw) { return is_scalar($raw) && is_numeric($raw) && is_finite((float)$raw) && (float)$raw > 0 ? (float)$raw : null; }
function shared_product_card_date_policy(){
 $v=function_exists('shared_product_jet_policy_raw')?shared_product_jet_policy_raw('shared_price_card_date_mode'):null;
 return is_string($v)&&in_array($v,['active_today','upcoming'],true)?$v:'active_today';
}
function pricing_rate_presentation($rate_id,$projection_mode=false) {
 $rate=get_post($rate_id);
 if (!$rate || $rate->post_type!=='tour-rates' || $rate->post_status!=='publish')return ['status'=>'unavailable'];
 if ((string)get_post_meta($rate_id,'avilabile_status',true)==='0' || (string)get_post_meta($rate_id,'stop_sale',true)==='1')return ['status'=>'unavailable'];
 $today=current_time('timestamp',true);
 $start=(int)get_post_meta($rate_id,'tour_rate_start_date',true);$end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
 if($end && $today>$end)return ['status'=>'unavailable'];
 if($start && $today<$start && (!$projection_mode || shared_product_card_date_policy()!=='upcoming'))return ['status'=>'unavailable'];
 $currency=strtoupper((string)get_post_meta($rate_id,'base_currency',true));
 if(!preg_match('/^[A-Z]{3}$/',$currency)) return ['status'=>'unavailable'];
 $options=[];
 foreach(['single','double','triple'] as $occupancy){
  $regular=pricing_price_amount(get_post_meta($rate_id,$occupancy.'_price',true));
  $discount=pricing_price_amount(get_post_meta($rate_id,$occupancy.'_sale_price',true));
  if(!$regular)continue;
  $sale_active=(string)get_post_meta($rate_id,'is_on_sale',true)==='1';
  $final=($sale_active && $discount && $discount<$regular)?$discount:$regular;
  $options[]=['occupancy'=>$occupancy,'regular'=>$regular,'final'=>$final,'discounted'=>$final<$regular];
 }
 if(!$options)return ['status'=>'unavailable'];
 usort($options,static fn($a,$b)=>$a['final']<=>$b['final']);
 return ['status'=>'available','currency'=>$currency,'best'=>$options[0],'options'=>$options];
}
function pricing_price_html($rate_id){
 $p=pricing_rate_presentation($rate_id);
 if($p['status']!=='available')return '<span class="price-on-request">'.esc_html__('Price on request','pricing').'</span>';
 $b=$p['best'];$code=esc_html($p['currency']);$fmt=static fn($v)=>esc_html(number_format_i18n($v,2));
 $html='<span class="price-label">'.esc_html__('From','pricing').' </span>';
 if($b['discounted'])$html.='<del class="price-regular">'.$code.' '.$fmt($b['regular']).'</del> ';
 $html.='<ins class="price-current">'.$code.' '.$fmt($b['final']).'</ins>';
 return '<span class="price-display" data-price-state="'.($b['discounted']?'discounted':'regular').'">'.$html.'</span>';
}
add_shortcode('pricing_price_display',static function($atts){
 $atts=shortcode_atts(['rate_id'=>0],$atts);$id=absint($atts['rate_id']);
 if(!$id && get_post_type(get_the_ID())==='tour-rates')$id=get_the_ID();
 return $id?pricing_price_html($id):'<span class="price-on-request">'.esc_html__('Price on request','pricing').'</span>';
});

/** Tour-card projection. Only explicitly linked, currently valid rates qualify. */
function pricing_tour_price_projection($tour_id) {
 if(function_exists("shared_lab_tour_price_resolve"))return shared_lab_tour_price_resolve((int)$tour_id);
 global $wpdb;
 $tour_id=absint($tour_id);
 if(!$tour_id || get_post_type($tour_id)!=='tours-and-activities')return ['status'=>'unavailable','reason'=>'invalid_tour'];
 if(function_exists('pricing_scope_resolve')) {
  $scoped=pricing_scope_resolve($tour_id,0,[]);
  if(!empty($scoped['values']['stop_sale_global']))return ['status'=>'unavailable','reason'=>'stop_sale'];
 }
 $ids=$wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s",'related_tour_id',(string)$tour_id));
 $eligible=[];$target=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
 $failed_fx=false;
 foreach(array_unique(array_map('intval',$ids)) as $rate_id){
  $p=pricing_rate_presentation($rate_id,true);
  if($p['status']!=='available')continue;
  $converted=pricing_currency_convert($p['best']['final'],$p['currency'],$target);
  $regular=pricing_currency_convert($p['best']['regular'],$p['currency'],$target);
  if($converted===null||$regular===null){$failed_fx=true;continue;}
  $eligible[]=['rate_id'=>$rate_id,'currency'=>$target,'source_currency'=>$p['currency'],'regular'=>$regular,'final'=>$converted,'discounted'=>$converted<$regular,'occupancy'=>$p['best']['occupancy']];
 }
 if($failed_fx)return ['status'=>'unavailable','reason'=>'fx_unavailable_for_eligible_rate'];
 if(!$eligible)return ['status'=>'unavailable','reason'=>'no_valid_rate'];
 usort($eligible,static fn($a,$b)=>($a['final']<=>$b['final'])?:($a['rate_id']<=>$b['rate_id']));
 return ['status'=>'available','best'=>$eligible[0],'rate_count'=>count($eligible),'currency'=>$target];

}
add_shortcode('pricing_tour_card_price',static function($atts){
 $atts=shortcode_atts(['tour_id'=>0],$atts);$id=absint($atts['tour_id'])?:get_the_ID();
 $p=pricing_tour_price_projection($id);
 if($p['status']!=='available')return '<span class="price-on-request">'.esc_html__('Price on request','pricing').'</span>';
 return do_shortcode('[etg_dynamic_tour_price tour_id="'.absint($id).'"]');
});
