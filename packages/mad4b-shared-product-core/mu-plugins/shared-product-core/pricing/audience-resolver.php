<?php
/** Lab-only unified quote resolver. Audience and source currency are distinct from display currency. */
if (!defined('ABSPATH')) return;
function pricing_audience_quote($rate_id,$audience,$occupancy){
 $rate_id=absint($rate_id);
 if(!in_array($audience,['general','egyptians'],true)||!in_array($occupancy,['single','double','triple'],true))return ['status'=>'invalid_request'];
 $p=get_post($rate_id);
 if(!$p||$p->post_type!=='tour-rates'||$p->post_status!=='publish')return ['status'=>'unavailable','reason'=>'unpublished'];
 if((string)get_post_meta($rate_id,'stop_sale',true)==='1'||(string)get_post_meta($rate_id,'avilabile_status',true)==='0')return ['status'=>'unavailable','reason'=>'stopped'];
 $now=current_time('timestamp',true);$start=(int)get_post_meta($rate_id,'tour_rate_start_date',true);$end=(int)get_post_meta($rate_id,'tour_rate_end_date',true);
 if(($start&&$now<$start)||($end&&$now>$end))return ['status'=>'unavailable','reason'=>'outside_period'];
 if($audience==='egyptians'&&(string)get_post_meta($rate_id,'has_egyptians_price',true)!=='1')return ['status'=>'unavailable','reason'=>'audience_disabled'];
 $prefix=$audience==='egyptians'?'egyptians_':'';
 $currency=strtoupper(trim((string)get_post_meta($rate_id,$prefix==='egyptians_'?'egyptians_currency':'base_currency',true)));
 if(!preg_match('/^[A-Z]{3}$/D',$currency)||!array_key_exists($currency,get_woocommerce_currencies()))return ['status'=>'unavailable','reason'=>'source_currency_invalid'];
 $regular=get_post_meta($rate_id,$prefix.$occupancy.'_price',true);$sale=get_post_meta($rate_id,$prefix.$occupancy.'_sale_price',true);
 if(!is_numeric($regular)||!is_finite((float)$regular)||(float)$regular<=0)return ['status'=>'unavailable','reason'=>'price_missing'];
 $valid_sale=(string)get_post_meta($rate_id,'is_on_sale',true)==='1'&&is_numeric($sale)&&is_finite((float)$sale)&&(float)$sale>0&&(float)$sale<(float)$regular;
 return ['status'=>'available','rate_id'=>$rate_id,'audience'=>$audience,'occupancy'=>$occupancy,'source_currency'=>$currency,'regular'=>(float)$regular,'sale'=>$valid_sale?(float)$sale:null,'final'=>$valid_sale?(float)$sale:(float)$regular,'discounted'=>$valid_sale];
}
function pricing_audience_display($quote,$role='final',$currency=null){
 if(($quote['status']??null)!=='available'||!in_array($role,['final','regular','sale','previous'],true))return '';
 if($role==='sale'&&!$quote['discounted']||$role==='previous'&&!$quote['discounted'])return '';
 $amount=$role==='sale'?$quote['sale']:($role==='previous'||$role==='regular'?$quote['regular']:$quote['final']);
 $target=$currency?:pricing_display_currency();$value=pricing_currency_convert($amount,$quote['source_currency'],$target);
 return $value===null?'':wp_kses_post(wc_price($value,['currency'=>$target]));
}
