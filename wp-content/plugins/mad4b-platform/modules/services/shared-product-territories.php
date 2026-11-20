<?php
/** Lab-only business coverage assignments, separate from product execution. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
add_action('init',function(){
 register_post_type('territory_assignment',['label'=>'Territory Assignments','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title'],'map_meta_cap'=>true]);
 foreach(['_organization_id','_territory_id','_priority'] as $key)register_post_meta('territory_assignment',$key,['type'=>'integer','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'absint','auth_callback'=>function(){return current_user_can('manage_options');}]);
 foreach(['_territory_role','_territory_status','_valid_from','_valid_until'] as $key)register_post_meta('territory_assignment',$key,['type'=>'string','single'=>true,'show_in_rest'=>true,'sanitize_callback'=>'sanitize_text_field','auth_callback'=>function(){return current_user_can('manage_options');}]);
},12);
final class Shared_Product_Territory_Assignment {
 public const TYPES=['countries','cities','locations'];
 public const ROLES=['operator','supplier','dmc','transport','marketing_partner','content_partner'];
 public static function check(int $org,int $place,string $role,int $exclude=0){
  if(get_post_type($org)!=='organization'||get_post_status($org)!=='publish')return new WP_Error('invalid_organization','Published organization required');
  if(!in_array(get_post_type($place),self::TYPES,true)||get_post_status($place)!=='publish')return new WP_Error('invalid_territory','Published country, city, or location required');
  if(!in_array($role,self::ROLES,true))return new WP_Error('invalid_territory_role','Unsupported territory role');
  $ids=get_posts(['post_type'=>'territory_assignment','post_status'=>['publish','draft','pending','private','future'],'posts_per_page'=>-1,'fields'=>'ids','suppress_filters'=>true,'meta_query'=>[['key'=>'_organization_id','value'=>$org],['key'=>'_territory_id','value'=>$place],['key'=>'_territory_role','value'=>$role]]]);
  foreach($ids as $id)if((int)$id!==$exclude)return new WP_Error('duplicate_territory_assignment','Organization already has that role in this territory');
  return true;
 }
 public static function create(int $org,int $place,string $role,int $priority=50){
  $check=self::check($org,$place,$role);if(is_wp_error($check))return $check;
  if($priority<0||$priority>100)return new WP_Error('invalid_priority','Priority must be 0-100');
  $id=wp_insert_post(['post_type'=>'territory_assignment','post_status'=>'publish','post_title'=>sprintf('%d @ %d (%s)',$org,$place,$role)],true);if(is_wp_error($id))return $id;
  foreach(['_organization_id'=>$org,'_territory_id'=>$place,'_territory_role'=>$role,'_priority'=>$priority,'_territory_status'=>'active'] as $key=>$val)update_post_meta($id,$key,$val);
  return $id;
 }
}
