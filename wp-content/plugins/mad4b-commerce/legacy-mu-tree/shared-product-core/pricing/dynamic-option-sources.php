<?php
/** Live registries for financial policy fields. Read-only; lab only. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || MAD4B_COMMERCE_RUNTIME_ENABLED!==true))return;
function shared_product_country_options(){
 // This list is public taxonomy data, but other callers include payment
 // validation. Share a short-lived read-only result ONLY for an unpersonalized
 // anonymous front page. All other contexts retain the original live lookup.
 // WPML's options provider executes during init, before is_front_page()
 // is meaningful. Identify ONLY the unpersonalized public homepage route
 // using the already-resolved WPML language and the actual request path.
 $locale=(string)apply_filters('wpml_current_language',null);
 $default_locale=(string)apply_filters('wpml_default_language',null);
 $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
 $public_home=PHP_SAPI!=='cli'
  &&($_SERVER['REQUEST_METHOD']??'')==='GET'
  &&!is_admin()&&!wp_doing_ajax()&&!is_user_logged_in()
  &&wp_using_ext_object_cache()
  &&$locale!==''&&$default_locale!==''
  &&(($path==='/'&&$locale===$default_locale)
     ||($path==='/'.$locale.'/'&&$locale!==$default_locale))
  &&strcasecmp((string)($_SERVER['HTTP_HOST']??''),
    (string)wp_parse_url(home_url(),PHP_URL_HOST))===0
  &&empty($_SERVER['HTTP_COOKIE'])&&empty($_SERVER['QUERY_STRING'])
  &&empty($_SERVER['HTTP_AUTHORIZATION'])
  &&empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
  &&empty($_SERVER['HTTP_X_GEO_COUNTRY'])
  &&empty($_SERVER['HTTP_CF_IPCOUNTRY'])
  &&empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY']);
 $cache_key='';
 if($public_home){
  // Term cache timestamps may churn during Elementor/WPML rendering; keep
  // this anonymous-only result bounded to the same 30-second public HTML TTL.
  // Admin, policy validation and any personalized request bypass the cache.
  $cache_key='v3:'.md5(serialize([get_current_blog_id(),$locale]));
  $cached=wp_cache_get($cache_key,'mad4b_public_destination_options');
  if(is_array($cached))return $cached;
 }
 $countries=[];$terms=get_terms(['taxonomy'=>'destinations','hide_empty'=>false]);
 if(is_wp_error($terms))return $countries;
 foreach($terms as $term){if(!is_wp_error(shared_product_destination_country($term->term_id)))$countries[(int)$term->term_id]=$term->name;}
 // TTL also bounds any WPML-specific term translation edits that do not
 // trigger WordPress core term-cache invalidation.
 if($cache_key!=='')wp_cache_set($cache_key,$countries,'mad4b_public_destination_options',30);
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
