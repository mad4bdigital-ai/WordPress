<?php
/** Inheritable financial controls. No checkout authority. Lab-only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_financial_field_choices(){return [
 'tax_price_basis'=>['exclusive','inclusive'],
 'discount_tax_order'=>['before_tax','after_tax'],
 'payment_fee_taxable'=>['yes','no'],
 'payment_fee_bearer'=>['customer','merchant','split'],
 'discount_scope'=>['all','base_only','selected_components','exclude_selected_components','none'],
 'refund_allocation'=>['proportional','line_based','nonrefundable_components'],
 'refund_payment_fee'=>['refundable','nonrefundable','proportional'],
 ];}
function shared_product_financial_override($raw){
 if($raw===null||$raw===''||$raw===[])return [];
 if(!is_array($raw))return new WP_Error('invalid_financial_override');
 $allowed=shared_product_financial_field_choices();$result=[];
 foreach($raw as $key=>$value){
  if(!isset($allowed[$key]))return new WP_Error('unknown_financial_field');
  if($value===''||$value===null||$value==='inherit')continue;
  if(!is_string($value)||!in_array($value,$allowed[$key],true))return new WP_Error('invalid_financial_mode');
  $result[$key]=$value;
 }
 return $result;
}
function shared_product_post_financial_fields($post_id){
 $values=[];
 foreach(shared_product_financial_field_choices() as $key=>$choices){
  $v=get_post_meta($post_id,'shared_financial_'.$key,true);
  if($v!=='' && $v!==null)$values[$key]=$v;
 }
 return $values;
}
function shared_product_financial_policy_resolve($rate_id){
 $context=shared_product_tour_context($rate_id);if(is_wp_error($context))return $context;
 $mode=$context['tour_mode'];$rows=shared_product_mode_policies();
 if(is_wp_error($rows))return $rows;
 if(!isset($rows[$mode])||!is_array($rows[$mode]))return new WP_Error('global_mode_financial_policy_missing');
 $global=array_intersect_key($rows[$mode],shared_product_financial_field_choices());
 $tour_fields=shared_product_post_financial_fields($context['tour_id']);
 $rate_fields=shared_product_post_financial_fields($rate_id);
 $effective=[];$trace=[];
 foreach(shared_product_financial_field_choices() as $key=>$choices){
  $source=$rows[$mode][$key.'_source']??'inherit';
  if(!in_array($source,['inherit','global','tour'],true))return new WP_Error('invalid_financial_field_source',$key);
  $base=shared_product_financial_override([$key=>$global[$key]??'inherit']);if(is_wp_error($base))return $base;
  if(isset($base[$key])){$effective[$key]=$base[$key];$trace[$key]='mode:'.$mode;}
  if($source==='tour'){
   $value=shared_product_financial_override([$key=>$tour_fields[$key]??null]);if(is_wp_error($value))return $value;
   if(!isset($value[$key]))return new WP_Error('financial_tour_meta_required',$key);
   $effective[$key]=$value[$key];$trace[$key]='tour:'.$context['tour_id'];
  }elseif($source==='inherit'){
   foreach(['tour:'.$context['tour_id']=>$tour_fields,'rate:'.$rate_id=>$rate_fields] as $label=>$values){
    $resolved=shared_product_financial_override([$key=>$values[$key]??null]);if(is_wp_error($resolved))return $resolved;
    if(isset($resolved[$key])){$effective[$key]=$resolved[$key];$trace[$key]=$label;}
   }
  }
 }
 foreach(array_keys(shared_product_financial_field_choices()) as $key){if(!isset($effective[$key]))return new WP_Error('financial_policy_unconfigured',$key);}
 return ['schema'=>1,'rate_id'=>(int)$rate_id,'tour_id'=>$context['tour_id'],'tour_mode'=>$mode,'effective'=>$effective,'sources'=>$trace,'status'=>'preview_only_not_checkout_authority'];
}
/** Deterministic policy application; caller supplies trusted line amounts and externally approved fee/tax rates. */
function shared_product_financial_policy_preview($lines,$discount_percent,$tax_percent,$payment_fee,$policy,$selected_components=[]){
 if(!is_array($lines)||!is_array($policy)||!is_array($selected_components))return new WP_Error('invalid_financial_inputs');
 $p=shared_product_financial_override($policy);if(is_wp_error($p))return $p;
 if(count($p)!==count(shared_product_financial_field_choices()))return new WP_Error('financial_policy_incomplete');
 foreach([$discount_percent,$tax_percent,$payment_fee] as $n)if(!is_numeric($n)||!is_finite((float)$n)||(float)$n<0)return new WP_Error('invalid_financial_amount');
 if($discount_percent>100||$tax_percent>100)return new WP_Error('invalid_financial_percentage');
 $net=0.;$gross=0.;$discount_base=0.;$line_details=[];
 foreach($lines as $line){
  if(!is_array($line)||!is_string($line['component']??null)||!is_numeric($line['amount']??null)||(float)$line['amount']<0)return new WP_Error('invalid_financial_line');
  $component=$line['component'];$amount=(float)$line['amount'];
  $is_target=match($p['discount_scope']){
   'all'=>true,'none'=>false,'base_only'=>$component==='base',
   'selected_components'=>in_array($component,$selected_components,true),
   'exclude_selected_components'=>!in_array($component,$selected_components,true)};
  $line_details[]=['component'=>$component,'amount'=>$amount,'discount_eligible'=>$is_target];
  $gross+=$amount;
  $base=$p['tax_price_basis']==='inclusive'?$amount/(1+$tax_percent/100):$amount;
  $net+=$base;
  if($is_target)$discount_base+=($p['discount_tax_order']==='before_tax'?$base:$amount);
 }
 $discount=round($discount_base*$discount_percent/100,2);
 $tax_before=round($p['tax_price_basis']==='inclusive'?$gross-$net:$net*$tax_percent/100,2);
 $tax_adjustment=$p['discount_tax_order']==='before_tax'?round($discount*$tax_percent/100,2):0.;
 $tax=round(max(0,$tax_before-$tax_adjustment),2);
 $fee=(float)$payment_fee;$customer_fee=match($p['payment_fee_bearer']){'customer'=>$fee,'merchant'=>0.,'split'=>round($fee/2,2)};
 $fee_tax=$p['payment_fee_taxable']==='yes'?round($customer_fee*$tax_percent/100,2):0.;
 $before_discount=$p['tax_price_basis']==='inclusive'?$gross:$gross+$tax_before;
 $total=round($before_discount-$discount-$tax_adjustment+$customer_fee+$fee_tax,2);
 return ['schema'=>1,'lines'=>$line_details,'discount'=>$discount,'tax'=>$tax,'payment_fee'=>$fee,'customer_payment_fee'=>$customer_fee,'payment_fee_tax'=>$fee_tax,'customer_total'=>$total,
 'merchant_payment_fee'=>round($fee-$customer_fee,2),'refund_allocation'=>$p['refund_allocation'],'refund_payment_fee'=>$p['refund_payment_fee'],'policy'=>$p,
 'status'=>'preview_only_not_checkout_authority'];
}

/** Refund preview based on a certified invoice breakdown, never mutable current rates. */
function shared_product_financial_refund_preview($invoice,$request){
 if(!is_array($invoice)||!is_array($request)||!is_array($invoice['lines']??null))return new WP_Error('invalid_refund_contract');
 $p=shared_product_financial_override($invoice['policy']??null);
 if(is_wp_error($p)||count($p)!==count(shared_product_financial_field_choices()))return new WP_Error('refund_policy_missing');
 $total=$invoice['customer_total']??null;
 if(!is_numeric($total)||!is_finite((float)$total)||(float)$total<0)return new WP_Error('invalid_invoice_total');
 $requested=$request['amount']??null;
 if(!is_numeric($requested)||!is_finite((float)$requested)||(float)$requested<0||(float)$requested>(float)$total)return new WP_Error('invalid_refund_amount');
 $component_amounts=[];
 foreach($invoice['lines'] as $line){
  if(!is_array($line)||!is_string($line['component']??null)||!is_numeric($line['amount']??null)||(float)$line['amount']<0)return new WP_Error('invalid_refund_line');
  $component_amounts[$line['component']]=($component_amounts[$line['component']]??0)+(float)$line['amount'];
 }
 $gross=array_sum($component_amounts);
 if($gross<=0)return new WP_Error('refund_breakdown_empty');
 $fraction=(float)$requested/(float)$total;
 if($p['refund_allocation']==='line_based'){
  $targets=$request['components']??null;
  if(!is_array($targets)||!$targets)return new WP_Error('refund_components_required');
  foreach($targets as $component)if(!isset($component_amounts[$component]))return new WP_Error('refund_component_unknown');
 }elseif($p['refund_allocation']==='nonrefundable_components'){
  $blocked=$request['nonrefundable_components']??null;
  if(!is_array($blocked))return new WP_Error('nonrefundable_components_required');
  foreach($blocked as $component)if(!isset($component_amounts[$component]))return new WP_Error('refund_component_unknown');
 }else{$targets=array_keys($component_amounts);}
 $allocation=[];
 foreach($component_amounts as $component=>$amount){
  $eligible=match($p['refund_allocation']){
   'proportional'=>true,
   'line_based'=>in_array($component,$targets,true),
   'nonrefundable_components'=>!in_array($component,$blocked,true)};
  if($eligible)$allocation[$component]=round($amount*$fraction,2);
 }
 $fee=(float)($invoice['customer_payment_fee']??0);
 $fee_refund=match($p['refund_payment_fee']){
  'refundable'=>round($fee*$fraction,2),
  'nonrefundable'=>0.0,
  'proportional'=>round($fee*$fraction,2)};
 // A financial refund cannot be certified without tax/discount proration per invoice line.
 return ['requested_amount'=>round((float)$requested,2),'component_estimates'=>$allocation,'estimated_payment_fee_refund'=>$fee_refund,
  'unallocated_amount'=>round((float)$requested-array_sum($allocation)-$fee_refund,2),
  'status'=>'preview_only_requires_order_line_tax_discount_allocation'];
}
