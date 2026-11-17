<?php
/** MU Presentation view-state adapter; inherits Shared Product Core Rate policy. */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function():void {
 $presentation=defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true;
 $commerce=defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true;
 // Commerce sites already use the original Platform owner; only load for presentation tenants.
 if(!$presentation || $commerce || !is_singular('tours-and-activities')) return;
 if(!class_exists('MAD4B_SCP_Site_Profile',false)) return;
 $p=MAD4B_SCP_Site_Profile::status();
 if(empty($p['configured'])||empty($p['origin_match'])||empty($p['deployment_binding_match'])||empty($p['environment_match'])) return;
 $tour=(int)get_queried_object_id();
 if($tour<=0||get_post_type($tour)!=='tours-and-activities'
   ||get_post_status($tour)!=='publish')return;
 // View identity only. Original visibility and Booking/Finance gates remain authoritative.
 $visible=static function(int $postId,string $type):bool {
  if(function_exists('native_component_selection_public'))return native_component_selection_public($postId,$type);
  $post=get_post($postId);return $post&&$post->post_type===$type&&$post->post_status==='publish'
   &&($post->post_password===''||current_user_can('manage_woocommerce'));
 };
 $rateIds=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>201,
  'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$tour,'orderby'=>'ID','order'=>'ASC']);
 $allowedRateIds=count($rateIds)<=200?array_values(array_filter(array_map('intval',$rateIds),static function($id)use($visible,$tour){
  if(!$visible($id,'tour-rates'))return false;
  // Existing Rates inherit unchanged. Explicit locale overrides must match
  // the common server policy used by Elementor, JetEngine, Woo and Booking.
  if(!function_exists('shared_product_rate_locale_decision'))return true;
  $policy=shared_product_rate_locale_decision((int)$id,$tour);
  return !empty($policy['allowed']);
 })): [];
 $allowedExtras=[];
 if(function_exists('native_component_selection_plan')){
  $plan=native_component_selection_plan($tour,'extras');
  foreach($plan['groups']??[] as $group)if(($group['type']??'')==='extra')$allowedExtras=array_merge($allowedExtras,(array)($group['ids']??[]));
 }
 $allowedExtras=array_values(array_unique(array_map('intval',$allowedExtras)));
 $file=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-selection-state-v1.js';
 if(!is_readable($file))return;
 $handle='shared-presentation-selection-v1';
 wp_register_script($handle,content_url('mu-plugins/shared-product-core/assets/presentation-selection-state-v1.js'),
   [],(string)filemtime($file),true);
 wp_add_inline_script($handle,'window.SharedPresentationSelectionConfigV1='.
   wp_json_encode(['tourId'=>$tour,'schemaVersion'=>'1.0.0','allowedRateIds'=>$allowedRateIds,'allowedExtraTourIds'=>$allowedExtras],JSON_UNESCAPED_SLASHES).';','before');
 wp_enqueue_script($handle);
},25);
