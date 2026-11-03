<?php
/** Read-only context inspection, Local Lab. Does not grant booking authority. */
defined('ABSPATH') || exit;
if (function_exists('wp_get_environment_type') && wp_get_environment_type()==='local'
 && defined('SHARED_PRODUCT_LAB_ONLY') && SHARED_PRODUCT_LAB_ONLY) {
final class Shared_Product_Presentation_Context_v1 {
 public static function inspect(int $id): array {
  if (!class_exists('Shared_Product_Presentation_Compatibility_v1'))
   return ['ok'=>false,'code'=>'UNAVAILABLE'];
  $context=Shared_Product_Presentation_Compatibility_v1::context_for_tour($id);
  if (empty($context['ok'])) return $context;
  $mode=get_post_meta($id,'tour_rates_display_style',true)==='Accordion'?'accordion':'grid';
  $descriptor=['slot'=>'tour.rate_list','mode'=>$mode,
   'template_id'=>48317,'listing_id'=>$mode==='grid'?46193:56197,
   'query_id'=>310,'provider_id'=>$mode==='grid'?'tour_rates':'tour_rates_accordion'];
  $binding=Shared_Product_Presentation_Compatibility_v1::validate_binding($descriptor);
  if(empty($binding['ok']))return ['ok'=>false,'code'=>'BINDING_UNAVAILABLE'];
  return $context+['mode'=>$mode,'binding'=>$binding,
   'can_mutate'=>false,'authorization'=>'not_evaluated'];
 }
 public static function resolve_referer(int $tour_id,string $referer,string $request_host):array {
  $context=self::inspect($tour_id);
  if(empty($context['ok']))return $context;
  $parts=wp_parse_url($referer);
  if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)
   ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))
   return ['ok'=>false,'code'=>'INVALID_REFERER'];
  $host=wp_parse_url('http://'.$request_host);
  if(!is_array($host)||empty($host['host'])
   ||strtolower((string)$parts['host'])!==strtolower((string)$host['host'])
   ||(int)($parts['port']??0)!==(int)($host['port']??0))
   return ['ok'=>false,'code'=>'CROSS_ORIGIN_REFERER'];
  $base=wp_parse_url(home_url('/'));
  if(!is_array($base)||empty($base['host']))return ['ok'=>false,'code'=>'HOME_URL_UNAVAILABLE'];
  // Normalize a same-origin Dev Tunnel route only for WordPress permalink
  // resolution. Never use the rewritten value as a redirect or HTTP URL.
  $normal=(string)($base['scheme']??'http').'://'.$base['host']
   .(isset($base['port'])?':'.$base['port']:'')
   .(string)($parts['path']??'/')
   .(isset($parts['query'])?'?'.$parts['query']:'');
  if((int)url_to_postid($normal)!==$tour_id)
   return ['ok'=>false,'code'=>'CROSS_TOUR_REFERER'];
  $params=[];
  if(isset($parts['query']))parse_str((string)$parts['query'],$params);
  $currency=isset($params['wcmlc'])&&is_scalar($params['wcmlc'])
   ?strtoupper((string)$params['wcmlc']):'';
  if($currency!==''&&!preg_match('/^[A-Z]{3}$/D',$currency))
   return ['ok'=>false,'code'=>'INVALID_CURRENCY_HINT'];
  return ['ok'=>true,'code'=>'REFERER_TOUR_VERIFIED','tour_id'=>$tour_id,
   'provider_id'=>$context['binding']['provider_id'],'mode'=>$context['mode'],
   'listing_id'=>$context['binding']['listing_id'],
   'requested_currency'=>$currency,'currency_is_rate_filter'=>false,
   'signed_request_validated'=>false,'booking_authorized'=>false];
 }
 public static function translated_term(int $id,string $taxonomy,string $language):array {
  if($id<=0||!in_array($taxonomy,['season','package_levels'],true))
   return ['ok'=>false,'code'=>'INVALID_TERM'];
  $langs=(array)apply_filters('wpml_active_languages',null);
  if(!isset($langs[$language]))return ['ok'=>false,'code'=>'UNSUPPORTED_LANGUAGE'];
  if(!term_exists($id,$taxonomy))return ['ok'=>false,'code'=>'UNKNOWN_TERM'];
  $mapped=apply_filters('wpml_object_id',$id,$taxonomy,false,$language);
  if(!$mapped||!term_exists((int)$mapped,$taxonomy))
   return ['ok'=>false,'code'=>'TRANSLATION_MISSING'];
  return ['ok'=>true,'term_id'=>(int)$mapped,'taxonomy'=>$taxonomy,
   'language'=>$language,'source_id'=>$id];
 }
}
}

// REV81: Native Elementor Tour pricing scenario (projection, never writes template).
if (!function_exists('shared_tour_presentation_scenario_v2')) {
function shared_tour_presentation_scenario_v2(int $tour):array {
 if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
  return ['scenario'=>'invalid','source'=>'unavailable'];
 $quote=function_exists('shared_lab_tour_price_resolve')?shared_lab_tour_price_resolve($tour):
  (class_exists('ETG_Tour_Rate_Display')?ETG_Tour_Rate_Display::resolve($tour):
  new WP_Error('retail_source_unavailable'));
 $source=is_wp_error($quote)?'unavailable':(($quote['status']??'available')==='unavailable'?'unavailable':($quote['source']??'unavailable'));
 $scenario=$source==='simple'?'direct':($source==='tour-rates'?'rates':'inquiry');
 $entry=class_exists('Shared_Tour_Pricing_Entry_v1')
  ?Shared_Tour_Pricing_Entry_v1::chosen($tour):[];
 if(($entry['resolved']??'')==='inquiry')$scenario='inquiry';
 return ['scenario'=>$scenario,'source'=>$source,
  'reason'=>is_wp_error($quote)?$quote->get_error_code():'validated_source',
  'entry_mode'=>$entry['resolved']??'auto','checkout_ready'=>false];
}
}
add_filter('jet-engine/options-pages/raw-fields',static function($fields,$page){
 if(($page->slug??'')!=='service-activation')return $fields;
 $fields[]=['name'=>'shared_tour_presentation_mode','title'=>'Tour Pricing Presentation Context',
   'type'=>'select','object_type'=>'field','options_source'=>'manual',
   'options'=>[
    ['key'=>'adaptive','value'=>'Adaptive — native Elementor/JetEngine display'],
    ['key'=>'native','value'=>'Original authored Elementor template unchanged']]];
 return $fields;
},40,2);
add_filter('elementor/frontend/builder_content_data',static function($nodes,$template_id){
 if(!is_array($nodes)||(int)$template_id!==48317||is_admin())return $nodes;
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return $nodes;
 if(class_exists('Elementor\Plugin')&&\Elementor\Plugin::$instance->preview->is_preview_mode())
  return $nodes;
 $options=get_option('service-activation',[]);
 if(is_array($options)&&($options['shared_tour_presentation_mode']??'adaptive')==='native')
  return $nodes;
 $context=shared_tour_presentation_scenario_v2((int)$tour_post->ID);
 if(!in_array($context['scenario'],['direct','inquiry','rates'],true))return $nodes;
 $walk=static function($items)use(&$walk,$context){
  foreach($items as &$node){
   if(!is_array($node))continue;
   if(($node['id']??'')==='060f497'&&($node['elType']??'')==='container'){
    if($context['scenario']==='rates'){
     $node['elements'][]=['id'=>'b76ef28','elType'=>'widget',
      'widgetType'=>'shortcode',
      'settings'=>['shortcode'=>'[shared_tour_included_bundle_label]'],
      'elements'=>[]];
    }else{
     $shortcode=$context['scenario']==='direct'
       ?'[etg_dynamic_tour_price]':'[shared_tour_inquiry_price]';
     $node['elements']=[['id'=>'a18fb39','elType'=>'widget','widgetType'=>'shortcode',
       'settings'=>['shortcode'=>$shortcode],'elements'=>[]]];
    }
   }elseif(isset($node['elements'])&&is_array($node['elements']))
    $node['elements']=$walk($node['elements']);
  }
  unset($node);
  return $items;
 };
 return $walk($nodes);
},185,2);
// Apply the same pricing scenario to the original Tour Content section (24784),
 // not just to the nested Rates template (48317). Do not alter saved Elementor data.
add_filter('do_shortcode_tag',static function($output,$tag){
 if($tag!=='etg_dynamic_tour_price'||is_admin())return $output;
 $tour_post=function_exists('shared_presentation_primary_tour_post_v1') ? shared_presentation_primary_tour_post_v1() : null;
 if(!($tour_post instanceof WP_Post))return $output;
 $tour=(int)$tour_post->ID;
 if(!$tour)return $output;
 $context=shared_tour_presentation_scenario_v2($tour);
 if(($context['scenario']??'')==='inquiry')
  return '<p class="tour-price-inquiry">'.esc_html__('Price on request','woocommerce').'</p>';
 return $output;
},25,2);

add_shortcode('shared_tour_inquiry_price',static function(){
 return '<p class="tour-price-inquiry">'.esc_html__('Price on request','woocommerce').'</p>';
});
add_shortcode('shared_tour_included_bundle_label',static function(){
 $tour=(int)get_queried_object_id();
 if(!function_exists('BookingExperience\tour_stay_rate_plan'))return '';
 $quote=ETG_Tour_Rate_Display::resolve($tour);
 if(is_wp_error($quote)||($quote['source']??'')!=='tour-rates')return '';
 // Page-wide included labels must not assert entitlements from one cheaper
 // Rate when the Elementor grid offers other package/Rate alternatives.
 $translations=ETG_Tour_Rate_Display::language_ids($tour);
 $related=get_posts(['post_type'=>'tour-rates','post_status'=>'publish',
  'posts_per_page'=>151,'fields'=>'ids','suppress_filters'=>true,
  'meta_query'=>[['key'=>'related_tour_id','value'=>array_map('strval',$translations),
    'compare'=>'IN']]]);
 if(count($related)>=151)return '';
 $groups=[];
 foreach($related as $rid){
  if(in_array(strtolower((string)get_post_meta($rid,'stop_sale',true)),['1','yes','true'],true))continue;
  $trid=apply_filters('wpml_element_trid',null,$rid,'post_tour-rates');
  $groups[(string)($trid?:$rid)]=true;
 }
 if(count($groups)>1)return '';
 $states=[];
 foreach($quote['rates'] as $rate){
  $id=absint($rate['id']??0);
  if(!$id)return '';
  $plan=BookingExperience\tour_stay_rate_plan($id);
  $hotel=false;$transport=false;
  if(is_array($plan)){
   $hotel=($plan['mode']??'')==='simple_or'
     ?($plan['price_policy']??'')==='included'&&!empty($plan['properties']):false;
   foreach($plan['stages']??[] as $stage)
    if(($stage['price_policy']??'')==='included')$hotel=true;
  }
  $policies=get_post_meta($id,'_booking_experience_internal_transport_policies_v1',true);
  if(is_array($policies)&&in_array('included',$policies,true))$transport=true;
  $states[]=[$hotel,$transport];
 }
 if(!$states)return '';
 // Do not promise an inclusion unless every surviving Rate confirms it.
 $hotel=!in_array(false,array_column($states,0),true);
 $transfer=!in_array(false,array_column($states,1),true);
 if(!$hotel&&!$transfer)return '';
 $labels=[];
 if($hotel)$labels[]=esc_html__('Accommodation included','woocommerce');
 if($transfer)$labels[]=esc_html__('Transfers included','woocommerce');
 return '<p class="tour-included-services">'.implode(' · ',$labels).
  ' <small>'.esc_html__('Availability subject to confirmation','woocommerce').'</small></p>';
});
