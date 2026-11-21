<?php
/** MU Presentation view-state adapter; inherits Shared Product Core Rate policy. */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts',static function():void {
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||!is_singular('tours-and-activities'))
  return;
 $tour=(int)get_queried_object_id();
 $editorPreview=function_exists('mad4b_native_extra_editor_preview')
  &&mad4b_native_extra_editor_preview($tour);
 if($tour<=0||get_post_type($tour)!=='tours-and-activities'
   ||(get_post_status($tour)!=='publish'&&!$editorPreview))return;
 // View identity only. Original visibility and Booking/Finance gates remain authoritative.
 $visible=static function(int $postId,string $type):bool {
  if(function_exists('native_component_selection_public'))return native_component_selection_public($postId,$type);
  $post=get_post($postId);return $post&&$post->post_type===$type&&$post->post_status==='publish'
   &&($post->post_password===''||current_user_can('manage_woocommerce'));
 };
 $rateSourceTour=$tour;
 if(class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false)){
  $resolved=MAD4B_Tour_Rate_Language_Policy_V1::resolve_context($tour);
  $candidate=absint($resolved['query_tour_id']??0);
  if($candidate>0 && get_post_type($candidate)==='tours-and-activities'
     && get_post_status($candidate)==='publish')$rateSourceTour=$candidate;
 }
 $rateIds=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>201,
  'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>$rateSourceTour,
  'suppress_filters'=>$rateSourceTour!==$tour,'orderby'=>'ID','order'=>'ASC']);
 $allowedRateIds=count($rateIds)<=200?array_values(array_filter(array_map('intval',$rateIds),static function($id)use($visible,$tour,$rateSourceTour){
  if(!$visible($id,'tour-rates'))return false;
  if($rateSourceTour!==$tour &&
    (!class_exists('MAD4B_Tour_Rate_Language_Policy_V1',false) ||
     !MAD4B_Tour_Rate_Language_Policy_V1::eligible_rate($tour,$id)))return false;
  // Existing Rates inherit unchanged. Explicit locale overrides must match
  // the common server policy used by Elementor, JetEngine, Woo and Booking.
  if(!function_exists('shared_product_rate_locale_decision'))return true;
  $policy=shared_product_rate_locale_decision((int)$id,$rateSourceTour);
  return !empty($policy['allowed']);
 })): [];
 // Draft Rates are admitted only into the current editor's read-only
 // Presentation state; WooCommerce, payments and form submission gates
 // continue to reject draft Tour/Rate commercial mutations.
 if($editorPreview){
  $previewRates=get_posts(['post_type'=>'tour-rates','post_status'=>['draft','publish'],
   'posts_per_page'=>81,'fields'=>'ids','meta_key'=>'related_tour_id',
   'meta_value'=>$tour,'orderby'=>'ID','order'=>'ASC']);
  $allowedRateIds=count($previewRates)<=80?array_values(array_filter(
   array_map('intval',$previewRates),
   static fn($rate)=>get_post_status($rate)==='draft'
     &&(int)get_post_meta($rate,'related_tour_id',true)===$tour
     &&current_user_can('edit_post',$rate)
  )):[];
 }
 $allowedExtras=[];
 if(function_exists('native_component_selection_plan')){
  $plan=native_component_selection_plan($tour,'extras');
  foreach($plan['groups']??[] as $group)if(($group['type']??'')==='extra')$allowedExtras=array_merge($allowedExtras,(array)($group['ids']??[]));
 }
 $allowedExtras=array_values(array_unique(array_map('intval',$allowedExtras)));
 // Source-authorized transport IDs are a presentation allowlist, not
 // supplier inventory, price or payment authority.
 $allowedTransportGroups=[];
 if(function_exists('native_component_selection_plan')){
  foreach(['vehicles','train','air'] as $mode){
   $plan=native_component_selection_plan($tour,$mode);
   foreach($plan['groups']??[] as $group)
    if(($group['key']??'')==='transport-'.$mode)
     $allowedTransportGroups[$group['key']]=array_values(array_unique(
       array_map('intval',(array)($group['ids']??[]))));
  }
 }
 $pricingMode=class_exists('Shared_Tour_Pricing_Entry_v1')
  ?(Shared_Tour_Pricing_Entry_v1::inspect($rateSourceTour)['effective_mode']??'inquiry')
  :($allowedRateIds?'existing_rates':'inquiry');
 $file=WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-selection-state-v1.js';
 if(!is_readable($file))return;
 $handle='shared-presentation-selection-v1';
 wp_register_script($handle,content_url('mu-plugins/shared-product-core/assets/presentation-selection-state-v1.js'),
   [],(string)filemtime($file),true);
 wp_add_inline_script($handle,'window.SharedPresentationSelectionConfigV1='.
   wp_json_encode(['tourId'=>$tour,'schemaVersion'=>'1.0.0','allowedRateIds'=>$allowedRateIds,'allowedExtraTourIds'=>$allowedExtras,
    'allowedTransportGroups'=>$allowedTransportGroups,
    'pricingEntryEffectiveMode'=>$pricingMode,
    'editorPreviewOnly'=>$editorPreview,'commercialAuthority'=>false],JSON_UNESCAPED_SLASHES).';','before');
 wp_enqueue_script($handle);
},25);
