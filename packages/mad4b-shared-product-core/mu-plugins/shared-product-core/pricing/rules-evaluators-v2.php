<?php
/** Declarative evaluator pipeline: new factors register without changing quote engine. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_rule_evaluators_v2(){
 $factors=[
  'source_policy'=>['phase'=>5,'callback'=>'shared_product_rule_source_policy_v2'],
  'rate_identity'=>['phase'=>10,'callback'=>'shared_product_rule_rate_identity_v2'],
  'property_selection'=>['phase'=>20,'callback'=>'shared_product_rule_property_v2'],
  'quote_integrity'=>['phase'=>90,'callback'=>'shared_product_rule_integrity_v2']
 ];
 $factors=apply_filters('shared_product_rule_evaluators_v2',$factors);
 if(!is_array($factors)||count($factors)>40)return new WP_Error('rules_registry_invalid');
 $normalized=[];
 foreach($factors as $name=>$definition){
  if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/D',$name)||!is_array($definition)||array_diff(array_keys($definition),['phase','callback'])||!isset($definition['phase'],$definition['callback']))return new WP_Error('rules_registry_definition_invalid');
  $phase=filter_var($definition['phase'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
  if($phase===false||!is_callable($definition['callback']))return new WP_Error('rules_registry_callback_missing');
  $normalized[$name]=['phase'=>$phase,'callback'=>$definition['callback']];
 }
 uksort($normalized,static function($a,$b)use(&$normalized){return ($normalized[$a]['phase']<=>$normalized[$b]['phase'])?:strcmp($a,$b);});
 return $normalized;
}
function shared_product_rule_source_policy_v2($context){
 $c=$context['configuration'];
 $mode=(string)get_post_meta($c['tour_id'],'tour_mode',true);
 $policy=shared_product_price_source_policy($mode);
 if(is_wp_error($policy))return $policy;
 $preferred=$policy['direct_weight']>$policy['rate_weight']?'direct':($policy['direct_weight']<$policy['rate_weight']?'rate':$policy['tie_break']);
 // Never silently declare one selected price authoritative over another available source.
 // Existing Booking v2 already protects direct source when an eligible Rate takes precedence.
 return ['status'=>'source_policy_checked_not_full_cross_source_certification','selected'=>$c['source'],'preferred'=>$preferred,'fallback'=>$policy['fallback'],'visibility'=>$policy['visibility']];
}
function shared_product_rule_rate_identity_v2($context){
 $c=$context['configuration'];
 if($c['source']==='direct')return ['status'=>'validated_direct_source'];
 if((int)get_post_meta($c['rate_id'],'related_tour_id',true)!==$c['tour_id'])return new WP_Error('rules_rate_tour_mismatch');
 if($c['travel_date']!==null&&!shared_product_booking_v2_rate_covers_date($c['rate_id'],$c['travel_date']))return new WP_Error('rules_rate_date_unavailable');
 return ['status'=>'validated_rate_identity','rate_id'=>$c['rate_id'],'date_checked'=>$c['travel_date']!==null];
}
function shared_product_rule_property_v2($context){
 $c=$context['configuration'];$property=(int)($c['selections']['property_id']??0);
 if(!$property)return ['status'=>'not_selected'];
 if($c['source']!=='rate')return new WP_Error('rules_property_requires_rate');
 $validation=shared_product_select_package_accommodation($c['rate_id'],$property,$c['selections']['package_term_id']??null);
 if(is_wp_error($validation))return $validation;
 return ['status'=>'selection_validated_pricing_not_activated','property_id'=>$property,'package_term_id'=>$validation['package_term_id']];
}
function shared_product_rule_integrity_v2($context){
 $quote=$context['quote'];$parts=$context['components'];$sum=0;
 foreach($parts as $part){if(!is_numeric($part['amount'])||!is_finite((float)$part['amount']))return new WP_Error('rules_component_invalid');$sum+=(float)$part['amount'];}
 if(abs(round($sum,2)-(float)$quote['total'])>0.009)return new WP_Error('rules_component_sum_mismatch');
 return ['status'=>'balanced','total'=>(float)$quote['total'],'currency'=>$quote['currency']];
}
function shared_product_evaluate_registered_rules_v2($configuration,$quote,$components){
 $registered=shared_product_rule_evaluators_v2();if(is_wp_error($registered))return $registered;
 $ctx=['configuration'=>$configuration,'quote'=>$quote,'components'=>$components];$receipts=[];
 foreach($registered as $name=>$def){
  try{$result=call_user_func($def['callback'],$ctx);}catch(Throwable $e){return new WP_Error('rules_evaluator_failed',$name);}
  if(is_wp_error($result))return $result;
  if(!is_array($result)||!isset($result['status'])||!is_string($result['status']))return new WP_Error('rules_evaluator_receipt_invalid');
  $receipts[$name]=$result;
 }
 return $receipts;
}
