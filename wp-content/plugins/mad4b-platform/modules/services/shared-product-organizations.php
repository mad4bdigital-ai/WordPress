<?php
/** Lab-only organization and per-product commercial role records. */
if (!defined('ABSPATH')) exit;
add_action('init',function(){
 if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
 register_post_type('organization',['label'=>'Organizations','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title','editor','thumbnail'],'capability_type'=>'post','map_meta_cap'=>true]);
 register_taxonomy('organization_role',['organization'],['label'=>'Organization Roles','hierarchical'=>false,'public'=>false,'show_ui'=>true,'show_in_rest'=>true,'rewrite'=>false]);
 register_post_type('partner_assignment',['label'=>'Product Partner Assignments','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title'],'capability_type'=>'post','map_meta_cap'=>true]);
 foreach(['_organization_id','_product_id'] as $key) register_post_meta('partner_assignment',$key,['type'=>'integer','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'absint','auth_callback'=>function(){return current_user_can('edit_posts');}]);
 foreach(['_assignment_role','_assignment_status','_valid_from','_valid_until','_commercial_model','_content_rights'] as $key)register_post_meta('partner_assignment',$key,['type'=>'string','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'sanitize_text_field','auth_callback'=>function(){return current_user_can('edit_posts');}]);
},12);
final class Shared_Product_Partner_Assignment {
 const ROLES=['operator','supplier','content_source','marketing_partner','fulfillment_partner'];
 public static function validate(int $organization,int $product,string $role):bool {
  return $organization>0 && get_post_type($organization)==='organization' && get_post_status($organization)==='publish' && $product>0 && get_post($product) && in_array($role,self::ROLES,true);
 }
 public static function create(int $organization,int $product,string $role,$status='active') {
  if(!self::validate($organization,$product,$role))return new WP_Error('invalid_assignment','Invalid organization, product, or role');
  if(!in_array($status,['active','inactive','pending'],true))return new WP_Error('invalid_status','Invalid assignment status');
  $existing=get_posts(['post_type'=>'partner_assignment','post_status'=>'any','fields'=>'ids','posts_per_page'=>-1,'meta_query'=>[['key'=>'_organization_id','value'=>$organization],['key'=>'_product_id','value'=>$product],['key'=>'_assignment_role','value'=>$role]]]);
  if($existing)return new WP_Error('duplicate_assignment','Assignment already exists');
  $id=wp_insert_post(['post_type'=>'partner_assignment','post_status'=>'publish','post_title'=>sprintf('%d / %d / %s',$organization,$product,$role)],true);if(is_wp_error($id))return $id;
  update_post_meta($id,'_organization_id',$organization);update_post_meta($id,'_product_id',$product);update_post_meta($id,'_assignment_role',$role);update_post_meta($id,'_assignment_status',$status);return $id;
 }
}
