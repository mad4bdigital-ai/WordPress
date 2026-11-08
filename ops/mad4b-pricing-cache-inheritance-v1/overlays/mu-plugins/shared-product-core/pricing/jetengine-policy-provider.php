<?php
/** Resolve policy settings through the registered JetEngine Options Page, no hardcoded site slug. */
if(!defined('ABSPATH') || !(defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED === true) && !(defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED === true)) return;
function shared_product_jet_policy_raw($field){
 static $request_cache = [];
 $cache_allowed = !is_admin() && !wp_doing_ajax()
     && !(defined('WP_CLI') && WP_CLI)
     && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET');
 $key = (string)$field;
 if ($cache_allowed && array_key_exists($key, $request_cache)) {
     return $request_cache[$key];
 }
 $value = shared_product_jet_policy_raw_uncached($field);
 if ($cache_allowed) $request_cache[$key] = $value;
 return $value;
}
function shared_product_jet_policy_raw_uncached($field){
 if(!function_exists('jet_engine')||!isset(jet_engine()->options_pages->data))return new WP_Error('policy_options_provider_missing');
 foreach(jet_engine()->options_pages->data->get_items() as $page){
  $fields=maybe_unserialize($page['meta_fields']??[]);if(!is_array($fields))continue;
  $names=array_column($fields,'name');
  if(!in_array('shared_financial_rules_json',$names,true)||!in_array($field,$names,true))continue;
  $args=maybe_unserialize($page['args']??[]);
  if(($args['storage_type']??'')!=='separate'||empty($args['option_prefix']))return new WP_Error('policy_options_storage_unsupported');
  $value=get_option($page['slug'].'_'.$field,null);
  return $value===null||$value===''?new WP_Error('policy_not_configured'):$value;
 }
 return new WP_Error('policy_field_missing');
}
function shared_product_jet_policy_json($field){
 $raw=shared_product_jet_policy_raw($field);if(is_wp_error($raw))return $raw;
 if(!is_string($raw))return new WP_Error('policy_invalid_type');
 $decoded=json_decode($raw,true);
 if(json_last_error()!==JSON_ERROR_NONE||!is_array($decoded))return new WP_Error('policy_invalid_json');
 return $decoded;
}
function shared_product_financial_policies(){
 $structured=shared_product_structured_financial_policies();
 if(!is_wp_error($structured))return $structured;
 if($structured->get_error_code()!=='structured_financial_policies_incomplete')return $structured;
 $jet=shared_product_jet_policy_json('shared_financial_rules_json');
 if(!is_wp_error($jet))return $jet;
 if($jet->get_error_code()==='policy_not_configured'||$jet->get_error_code()==='policy_field_missing'){
   $legacy=get_option('shared_product_payment_offer_rules',null);
   if(is_array($legacy))return $legacy;
 }
 return $jet;
}
function shared_product_mode_policy_defaults_v1(){
 $defaults=[];
 foreach(['tour'=>50,'activity'=>50,'extra_trip'=>999,'cruise_tour'=>50] as $mode=>$max){
  $row=['strategy'=>'rate_occupancy','enabled'=>true,'minimum_travelers'=>1,'maximum_travelers'=>$max,'tax_percent_override'=>null,'service_fee_percent_override'=>null];
  if(function_exists('shared_product_financial_field_choices'))foreach(array_keys(shared_product_financial_field_choices()) as $key)$row[$key.'_source']='inherit';
  $defaults[$mode]=$row;
 }
 return apply_filters('shared_product_mode_policy_defaults_v1',$defaults);
}
function shared_product_apply_mode_defaults_v1($configured){
 if(is_wp_error($configured))return $configured;
 if(!is_array($configured))return new WP_Error('invalid_mode_policy_map');
 foreach(shared_product_mode_policy_defaults_v1() as $mode=>$default){
  if(!array_key_exists($mode,$configured))$configured[$mode]=$default;
  elseif(is_array($configured[$mode]))$configured[$mode]=array_replace($default,$configured[$mode]);
  else return new WP_Error('invalid_mode_policy_row');
 }
 return $configured;
}
function shared_product_mode_policies($cache_context=[]){
 if(class_exists('MAD4B_Pricing_Cache_Inheritance_V1',false))
  return MAD4B_Pricing_Cache_Inheritance_V1::memoize_mode_policies(
    static fn()=>shared_product_mode_policies_uncached(),is_array($cache_context)?$cache_context:[]);
 return shared_product_mode_policies_uncached();
}
function shared_product_mode_policies_uncached(){
 $structured=shared_product_structured_mode_policies();
 if(!is_wp_error($structured))return shared_product_apply_mode_defaults_v1($structured);
 if(!in_array($structured->get_error_code(),['policy_not_configured','policy_field_missing'],true))return $structured;
 $jet=shared_product_jet_policy_json('shared_mode_policies_json');
 if(!is_wp_error($jet))return shared_product_apply_mode_defaults_v1($jet);
 if(in_array($jet->get_error_code(),['policy_not_configured','policy_field_missing'],true)){
  $legacy=get_option('shared_product_pricing_policies',[]);
  return shared_product_apply_mode_defaults_v1($legacy);
 }
 return $jet;
}

/** JetEngine structured repeaters become canonical when configured. */
function shared_product_structured_policy_rows($field){
 $rows=shared_product_jet_policy_raw($field);
 if(is_wp_error($rows))return $rows;
 if(is_string($rows))$rows=maybe_unserialize($rows);
 return is_array($rows)?$rows:new WP_Error('invalid_structured_policy_rows');
}
function shared_product_structured_mode_policies(){
 $rows=shared_product_structured_policy_rows('shared_mode_policy_rows');if(is_wp_error($rows))return $rows;
 $result=[];
 foreach($rows as $row){
  if(!is_array($row))return new WP_Error('invalid_mode_policy_row');
  $mode=sanitize_key($row['mode']??'');$strategy=sanitize_key($row['strategy']??'');
  $glossary=shared_product_unit_glossary('tours-and-activities','tour_mode');
  if(is_wp_error($glossary)||!isset($glossary['values'][$mode]))return new WP_Error('unknown_tour_mode');
  if(!$mode||!isset(shared_product_strategy_types()[$strategy])||isset($result[$mode]))return new WP_Error('invalid_mode_policy_row');
  $min=filter_var($row['minimum_travelers']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
  $max=filter_var($row['maximum_travelers']??999,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);
  if($min===false||$max===false||$min>$max)return new WP_Error('invalid_mode_policy_bounds');
  $policy=['strategy'=>$strategy,'enabled'=>in_array($row['enabled']??false,[true,1,'1','true','on'],true),'minimum_travelers'=>$min,'maximum_travelers'=>$max];
  foreach(['tax_percent_override','service_fee_percent_override'] as $field){$raw=$row[$field]??'';if($raw===''){$policy[$field]=null;continue;}if(!is_numeric($raw)||(float)$raw<0||(float)$raw>100)return new WP_Error('invalid_mode_percentage');$policy[$field]=(float)$raw;}
  foreach(shared_product_financial_field_choices() as $key=>$choices){$value=$row[$key]??'inherit';if($value!==''&&$value!=='inherit')$policy[$key]=$value;}
  foreach(array_keys(shared_product_financial_field_choices()) as $key){$source=$row[$key.'_source']??'inherit';if(!in_array($source,['inherit','global','tour'],true))return new WP_Error('invalid_financial_field_source');$policy[$key.'_source']=$source;}
  $result[$mode]=$policy;
 }
 return $result;
}
function shared_product_structured_financial_policies(){
 $fees=shared_product_structured_policy_rows('shared_payment_fee_rows');$offers=shared_product_structured_policy_rows('shared_promotion_rows');
 if(is_wp_error($fees)||is_wp_error($offers))return new WP_Error('structured_financial_policies_incomplete');
 $rules=['schema'=>1,'payment_fees'=>[],'offers'=>[]];
 foreach($fees as $row){
  if(!is_array($row))return new WP_Error('invalid_fee_row');
  $method=sanitize_key($row['method']??'');if(!$method||isset($rules['payment_fees'][$method]))return new WP_Error('duplicate_or_invalid_gateway');
  $rules['payment_fees'][$method]=['percent'=>$row['percent']??null,'fixed'=>$row['fixed']??null];
 }
 foreach($offers as $row){
  if(!is_array($row))return new WP_Error('invalid_offer_row');
  $offer=['id'=>$row['id']??null,'enabled'=>in_array($row['enabled']??false,[true,1,'1','true','on'],true),'start'=>$row['start']??null,'end'=>$row['end']??null,'percent'=>$row['percent']??null];
  foreach(['payment_methods','country_ids','nationalities'] as $key){
   $v=$row[$key]??'';
   if(is_string($v))$v=explode(',',$v);
   if(!is_array($v))return new WP_Error('invalid_offer_scope');
   $offer[$key]=array_values(array_filter(array_map('trim',$v),static fn($x)=>$x!==''));
   if($key==='country_ids'){
    foreach($offer[$key] as $country){if(!ctype_digit($country)||is_wp_error(shared_product_destination_country((int)$country)))return new WP_Error('invalid_offer_country');}
    $offer[$key]=array_map('intval',$offer[$key]);
   }
  }
  $rules['offers'][]=$offer;
 }
 if(!function_exists('shared_product_validate_financial_rules')){
  // Validator shipped in core, not in the retired admin page.
  return shared_product_validate_policy_contract($rules);
 }
 $validated=shared_product_validate_financial_rules($rules);
 if(is_wp_error($validated))return $validated;
 $scopes=shared_product_validate_policy_scopes($rules);
 return is_wp_error($scopes)?$scopes:$rules;
}
function shared_product_validate_policy_contract($rules){
 foreach($rules['payment_fees'] as $fee){if(!is_numeric($fee['percent'])||!is_numeric($fee['fixed'])||$fee['percent']<0||$fee['percent']>100||$fee['fixed']<0)return new WP_Error('invalid_payment_fee');}
 foreach($rules['offers'] as $offer){if(!is_string($offer['id'])||!preg_match('/^[a-z0-9_-]+$/D',$offer['id'])||!is_numeric($offer['percent'])||$offer['percent']<0||$offer['percent']>100||!is_string($offer['start'])||!is_string($offer['end'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$offer['start'])||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$offer['end'])||$offer['start']>$offer['end'])return new WP_Error('invalid_offer');}
 return $rules;
}

// Make the same read-only policy inventory available wherever pricing policies are readable.
require_once dirname(__DIR__) . '/admin/pricing-policy-readiness.php';
