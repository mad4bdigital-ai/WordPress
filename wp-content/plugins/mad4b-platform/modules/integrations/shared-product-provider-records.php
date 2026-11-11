<?php
/** Lab-scoped declarative registry. CPT records never execute arbitrary PHP. */
if (!defined('ABSPATH')) exit;
add_action('init',function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
 register_post_type('availability_source',['label'=>'Availability Providers','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title'],'capability_type'=>'post','map_meta_cap'=>true]);
 register_post_meta('availability_source','_provider_key',['type'=>'string','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'sanitize_key','auth_callback'=>function(){return current_user_can('manage_options');}]);
 register_post_meta('availability_source','_product_post_type',['type'=>'string','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'sanitize_key','auth_callback'=>function(){return current_user_can('manage_options');}]);
},8);
final class Shared_Product_Provider_CPT_Registry {
 public static function providers(int $product_id): array {
  $type=get_post_type($product_id);if(!$type)return [];
  $ids=get_posts(['post_type'=>'availability_source','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true]);
  $providers=[];
  foreach($ids as $id){if(get_post_meta($id,'_product_post_type',true)!==$type)continue;
   $key=get_post_meta($id,'_provider_key',true);
   // Allowlist of trusted server-side implementations, never class names supplied by content.
   if($key==='tour_rates')$providers[]=new Shared_Product_Tour_Rate_Provider();
  }
  return $providers;
 }
}
