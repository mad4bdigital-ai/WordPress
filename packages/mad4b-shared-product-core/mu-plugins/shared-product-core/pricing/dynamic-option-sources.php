<?php
/** Live registries for financial policy fields. Read-only; lab only. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_country_options(){
 $countries=[];$terms=get_terms(['taxonomy'=>'destinations','hide_empty'=>false]);
 if(is_wp_error($terms))return $countries;
 foreach($terms as $term){if(!is_wp_error(shared_product_destination_country($term->term_id)))$countries[(int)$term->term_id]=$term->name;}
 return $countries;
}
function shared_product_gateway_options(){
 if(!function_exists('WC')||!WC()->payment_gateways())return [];
 $items=[];foreach(WC()->payment_gateways()->payment_gateways() as $id=>$gateway){if(is_string($id)&&preg_match('/^[a-z0-9_-]+$/D',$id))$items[$id]=$gateway->get_title()?:$id;}
 return $items;
}
function shared_product_validate_policy_scopes($rules){
 if(!is_array($rules))return new WP_Error('invalid_policy_rules');
 $methods=shared_product_gateway_options();$countries=shared_product_country_options();
 foreach(($rules['payment_fees']??[]) as $method=>$fee){if(!isset($methods[$method]))return new WP_Error('unknown_gateway_id');}
 foreach(($rules['offers']??[]) as $offer){
  if(!is_array($offer))return new WP_Error('invalid_offer');
  foreach(($offer['payment_methods']??[]) as $method)if(!isset($methods[$method]))return new WP_Error('unknown_offer_gateway');
  foreach(($offer['country_ids']??[]) as $id)if(!isset($countries[(int)$id]))return new WP_Error('unknown_offer_country');
  foreach(($offer['nationalities']??[]) as $nationality)if(!is_string($nationality)||!preg_match('/^[A-Z]{2}$/D',$nationality))return new WP_Error('invalid_offer_nationality');
 }
 return true;
}

/** JetEngine's documented option filter resolves values on each admin render. */
add_filter('jet-engine/meta-fields/field-options', static function($options,$field){
 if(($field['options_source']??'')!=='shared_dynamic')return $options;
 $source=$field['shared_source']??'';
 if($source==='payment_gateways')$values=shared_product_gateway_options();
 elseif($source==='destination_countries')$values=shared_product_country_options();
 else return [];
 return array_map(static fn($key,$label)=>['key'=>(string)$key,'value'=>(string)$label],array_keys($values),array_values($values));
},50,2);
