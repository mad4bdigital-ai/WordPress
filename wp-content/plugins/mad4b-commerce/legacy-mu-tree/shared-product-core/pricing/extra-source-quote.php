<?php
/** One Extra source decision for existing preview consumers. No prices or bookings are written. */
defined('ABSPATH') || exit;
function shared_product_extra_source_visible($id,$type){
 if(function_exists('native_component_selection_public'))return native_component_selection_public($id,$type);
 $post=get_post($id);
 return $post&&$post->post_type===$type&&$post->post_status==='publish'&&
  (current_user_can('manage_woocommerce')||($post->post_password===''&&get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)===''));
}
/**
 * An explicit Rate is a candidate, never permission to bypass the source policy.
 * $date is the requested service day; Rate providers retain their current-date guards too.
 */
function shared_product_extra_source_quote($parent_tour_id,array $extra,$audience,$occupancy,array $quantities,$date=null,$currency=null){
 $parent=absint($parent_tour_id);$tour=filter_var($extra['tour_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 if(!$tour||!in_array($audience,['general','egyptians'],true)||!in_array($occupancy,['single','double','triple'],true))return new WP_Error('extra_source_context_invalid');
 if(!shared_product_extra_source_visible($parent,'tours-and-activities')||!shared_product_extra_source_visible($tour,'tours-and-activities'))return new WP_Error('extra_source_not_public');
 $allowed=shared_product_optional_tour_ids_for_tour($parent);if(is_wp_error($allowed))return $allowed;
 if(!in_array($tour,$allowed,true))return new WP_Error('extra_source_not_linked');
 if($date!==null&&(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!DateTimeImmutable::createFromFormat('!Y-m-d',$date)||DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('Y-m-d')!==$date))return new WP_Error('extra_source_date_invalid');
 $target=$currency?:pricing_display_currency();$settings=get_option('_wcml_settings',[]);
 if(!is_string($target)||!isset($settings['currency_options'][$target]))return new WP_Error('extra_source_currency_invalid');
 $rate=$extra['rate_id']??null;
 if($rate!==null){
  $rate=filter_var($rate,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$rate||get_post_type($rate)!=='tour-rates'||get_post_status($rate)!=='publish'||(int)get_post_meta($rate,'related_tour_id',true)!==$tour)return new WP_Error('extra_source_rate_mismatch');
 }
 $candidates=[];$details=[];
 $price=get_post_meta($tour,$audience==='egyptians'?'egyptians_start_price':'start_price',true);
 $source=get_post_meta($tour,$audience==='egyptians'?'tour_egyptians_currency':'tour_base_currency',true);
 $unit=(string)get_post_meta($tour,'tour_price_unit',true);
 if(is_scalar($price)&&!is_bool($price)&&is_numeric($price)&&is_finite((float)$price)&&(float)$price>=0&&is_string($source)&&preg_match('/^[A-Z]{3}$/D',$source)){
  $extended=shared_product_unit_extended_amount($price,$unit,$quantities);
  if(!is_wp_error($extended)){$candidates['direct']=['amount'=>$extended['amount'],'currency'=>$source];$details['direct']=['rate_id'=>null,'unit'=>$unit,'quantity'=>$extended['quantity']];}
 }
 // Missing Rate selection permits deterministic discovery, not arbitrary unlinked Rates.
 $ids=$rate?[$rate]:get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>101,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$tour,'orderby'=>'ID','order'=>'ASC']);
 if(count($ids)>100)return new WP_Error('extra_source_rate_limit');
 $rate_rows=[];$rate_fx_failed=false;
 foreach($ids as$rid){
  if(!shared_product_extra_source_visible($rid,'tour-rates')||(int)get_post_meta($rid,'related_tour_id',true)!==$tour)continue;
  if($date===null||!shared_product_booking_v2_rate_covers_date($rid,$date))continue;
  $quote=shared_product_strategy_quote($rid,$audience,$occupancy,$target);
  if(is_wp_error($quote)){foreach($quote->get_error_codes()as$code)if(preg_match('/fx|currency_conversion/',$code))$rate_fx_failed=true;continue;}
  $ru=shared_product_resolve_price_unit($rid);if(is_wp_error($ru))continue;
  $extended=shared_product_unit_extended_amount($quote['display_amount']??null,$ru['unit'],$quantities);if(is_wp_error($extended))continue;
  if(($quote['display_currency']??null)!==$target)continue;
  $rate_rows[]=['amount'=>$extended['amount'],'currency'=>$target,'rate_id'=>(int)$rid,'unit'=>$ru['unit'],'quantity'=>$extended['quantity']];
 }
 if(!$rate_fx_failed&&$rate_rows){
  usort($rate_rows,static fn($a,$b)=>($a['amount']<=>$b['amount'])?:($a['rate_id']<=>$b['rate_id']));$best=$rate_rows[0];
  $candidates['rate']=['amount'=>$best['amount'],'currency'=>$best['currency']];$details['rate']=array_intersect_key($best,array_flip(['rate_id','unit','quantity']));
 }
 $choice=shared_product_resolve_price_sources($candidates,(string)get_post_meta($tour,'tour_mode',true));if(is_wp_error($choice))return $choice;
 $amount=pricing_currency_convert($choice['amount'],$choice['currency'],$target);if($amount===null||!is_finite((float)$amount)||(float)$amount<0)return new WP_Error('extra_source_fx_unavailable');
 return array_merge(['kind'=>'extra','tour_id'=>$tour,'source'=>$choice['source'],'amount'=>round((float)$amount,2),'currency'=>$target,'status'=>'preview_only_not_checkout_authority'],$details[$choice['source']]);
}
