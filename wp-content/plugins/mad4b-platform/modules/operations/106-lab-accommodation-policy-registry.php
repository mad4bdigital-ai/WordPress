<?php
/** Lab accommodation rule registry and financial inheritance diagnostics. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function etg_lab_accommodation_rules_registry(){
 return apply_filters('etg_lab_accommodation_rules_registry',[
  'prop_eligibility'=>['state'=>'integrated','phase'=>'eligibility'],
  'unit_identity'=>['state'=>'integrated','phase'=>'eligibility'],
  'prop_rate_window'=>['state'=>'integrated','phase'=>'eligibility'],
  'unit_inventory_calendar'=>['state'=>'shadow_only','phase'=>'availability'],
  'prop_price_unit'=>['state'=>'shadow_only','phase'=>'base'],
  'prop_financial_policy'=>['state'=>'readiness_only','phase'=>'ledger'],
  'prop_board_type'=>['state'=>'not_integrated','phase'=>'addon'],
  'unit_extras'=>['state'=>'not_integrated','phase'=>'addon']]);
}
function lab_accommodation_financial_policy($property_id,$rate_id){
 if(!function_exists('shared_product_financial_field_choices'))return new WP_Error('stay_financial_contract_unavailable');
 $choices=shared_product_financial_field_choices();$effective=[];$sources=[];$missing=[];
 foreach($choices as $key=>$valid){
  foreach([['post'=>$property_id,'prefix'=>'prop_shared_financial_','label'=>'property:'],['post'=>$rate_id,'prefix'=>'prop_shared_financial_','label'=>'rate:']] as $source){
   $v=get_post_meta($source['post'],$source['prefix'].$key,true);
   if($v===''||$v==='inherit')continue;
   if(!in_array($v,$valid,true))return new WP_Error('stay_financial_policy_invalid',$key);
   $effective[$key]=$v;$sources[$key]=$source['label'].$source['post'];
  }
  if(!isset($effective[$key]))$missing[]=$key;
 }
 return ['effective'=>$effective,'sources'=>$sources,'missing'=>$missing,'status'=>$missing?'financial_policy_incomplete':'financial_policy_resolved_preview_only','checkout_ready'=>false];
}
function lab_accommodation_rule_receipts($quote){
 $financial=lab_accommodation_financial_policy($quote['property_id'],$quote['rate_id']);
 if(is_wp_error($financial))return $financial;
 return ['registry'=>etg_lab_accommodation_rules_registry(),'financial_policy'=>$financial,'amount'=>['status'=>'base_shadow_calculated','currency'=>$quote['currency'],'total'=>$quote['total']],'availability'=>['status'=>$quote['inventory_status']],'checkout_ready'=>false];
}

/** Produce a financial ledger receipt only with explicit caller-approved rates. */
function etg_lab_accommodation_financial_receipt($quote,$commercial){
 if(!is_array($quote)||!is_array($commercial))return new WP_Error('stay_financial_receipt_invalid');
 $policy=lab_accommodation_financial_policy($quote['property_id']??0,$quote['rate_id']??0);
 if(is_wp_error($policy))return $policy;
 if($policy['missing'])return new WP_Error('stay_financial_policy_incomplete',implode(',',$policy['missing']));
 foreach(['discount_percent','tax_percent','payment_fee'] as $key)if(!array_key_exists($key,$commercial))return new WP_Error('stay_commercial_rates_not_supplied',$key);
 if(!function_exists('shared_product_financial_policy_preview'))return new WP_Error('stay_financial_engine_unavailable');
 $lines=[['component'=>'base','amount'=>$quote['base_total']??$quote['total']]];
 $receipt=$quote['add_on_receipt']??null;
 if($receipt!==null){
  if(!is_array($receipt)||!is_array($receipt['lines']??null))return new WP_Error('stay_addon_receipt_invalid');
  $sum=0.;foreach($receipt['lines'] as $item){
   if(!is_array($item)||!in_array($item['kind']??null,['meal','extra'],true)||!is_numeric($item['amount']??null)||(float)$item['amount']<0)return new WP_Error('stay_addon_receipt_invalid');
   $sum+=(float)$item['amount'];$lines[]=['component'=>$item['kind'],'amount'=>(float)$item['amount']];
  }
  if(abs($sum-(float)$receipt['addon_total'])>0.005||abs((float)$quote['total']-((float)($quote['base_total']??0)+$sum))>0.005)return new WP_Error('stay_addon_receipt_not_balanced');
 }
 $ledger=shared_product_financial_policy_preview($lines,$commercial['discount_percent'],$commercial['tax_percent'],$commercial['payment_fee'],$policy['effective']);
 if(is_wp_error($ledger))return $ledger;
 return ['status'=>'financial_shadow_preview_only','ledger'=>$ledger,'input_lines'=>$lines,'policy_sources'=>$policy['sources'],'currency'=>$quote['currency'],'checkout_ready'=>false];
}
