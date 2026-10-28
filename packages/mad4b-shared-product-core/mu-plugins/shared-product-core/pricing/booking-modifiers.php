<?php
/** Guide-language and transportation surcharges layered on a base booking. Lab only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_booking_modifiers($rate_id,$language,$transport,$people=1,$currency=null){
 $tour=shared_product_tour_context($rate_id);if(is_wp_error($tour))return $tour;
 $count=filter_var($people,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
 if($count===false||$count===null)return new WP_Error('invalid_person_count');
 $raw=get_post_meta($tour['tour_id'],'shared_product_modifier_rules',true);
 if(!is_string($raw)||$raw==='')return new WP_Error('modifier_rules_unconfigured');
 $rules=json_decode($raw,true);
 if(!is_array($rules)||($rules['schema']??null)!==1||!is_array($rules['languages']??null)||!is_array($rules['transportation']??null))return new WP_Error('invalid_modifier_rules');
 $source=$rules['currency']??'';if(!is_string($source)||!preg_match('/^[A-Z]{3}$/D',$source))return new WP_Error('invalid_currency');
 $target=strtoupper($currency?:pricing_display_currency());$wcml=get_option('_wcml_settings',[]);
 if(!isset($wcml['currency_options'][$target]))return new WP_Error('unsupported_currency');
 $lines=[];$total=0;
 foreach(['guide_language'=>[$language,'languages'],'transportation'=>[$transport,'transportation']] as $kind=>$spec){
  [$chosen,$key]=$spec;if($chosen===null||$chosen==='')continue;
  if(!is_string($chosen)||sanitize_key($chosen)!==$chosen)return new WP_Error('invalid_modifier_code');
  if(!isset($rules[$key][$chosen])||!is_array($rules[$key][$chosen]))return new WP_Error('modifier_unavailable');
  if($kind==='guide_language'){
   if(!taxonomy_exists('guide-languages')||!get_term_by('slug',$chosen,'guide-languages'))return new WP_Error('language_not_registered');
  }else{
   if(!in_array($chosen,['vehicle','train','air','transfer'],true))return new WP_Error('transport_not_registered');
   $flag=['vehicle'=>'tour_vehicles_transport_status','train'=>'tour_train_transport_status','air'=>'tour_air_transport_status'];
   if(isset($flag[$chosen])&&(string)get_post_meta($tour['tour_id'],$flag[$chosen],true)==='0')return new WP_Error('transport_disabled');
  }
  $row=$rules[$key][$chosen];$basis=$row['basis']??'';$amount=$row['amount']??null;
  if(!in_array($basis,['per_person','per_booking'],true)||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0)return new WP_Error('invalid_modifier_amount');
  $source_total=(float)$amount*($basis==='per_person'?$count:1);
  $converted=$source_total===0?0:pricing_currency_convert($source_total,$source,$target);
  if($converted===null||!is_finite((float)$converted))return new WP_Error('modifier_conversion_unavailable');
  $converted=round((float)$converted,2);$total+=$converted;
  $lines[]=['type'=>$kind,'code'=>$chosen,'basis'=>$basis,'source_total'=>$source_total,'amount'=>$converted];
 }
 return ['parent_rate_id'=>(int)$rate_id,'currency'=>$target,'lines'=>$lines,'modifiers_total'=>round($total,2),'status'=>'preview_only_not_checkout_authority'];
}
