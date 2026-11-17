<?php
/** ETG Local Lab: verified Brand Context consumption and generation provenance. */
if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') return;
function brand_context_generation_context($simulate_changed_id=0) {
 if(!class_exists('MAD4B_SCP_Context_Authority'))return new WP_Error('brand_registry_unavailable','Brand Context authority unavailable');
 $status=MAD4B_SCP_Context_Authority::status();
 if(empty($status['ready']))return new WP_Error('brand_context_not_ready','Brand Context is not ready');
 $items=MAD4B_SCP_Context_Authority::assets();$needed=['brand_strategy','tone_of_voice','editorial_guidelines'];$out=[];
 foreach($needed as $category){
  $matches=array_values(array_filter($items,static function($a)use($category){return ($a['category']??'')===$category && !empty($a['required']) && ($a['review_status']??'')==='approved';}));
  if(count($matches)!==1)return new WP_Error('brand_authority_ambiguous','Expected one approved required asset for '.$category);
  $a=$matches[0];$id=(int)($a['file_id']??0);$file=$id?get_attached_file($id):'';
  if(($a['provider']??'')!=='wordpress_media'||!$file||!is_file($file))return new WP_Error('brand_source_missing','Expected a readable WordPress Media source');
  $hash=hash_file('sha256',$file);
  if($id===(int)$simulate_changed_id)$hash=str_repeat('0',64);
  if(!hash_equals((string)$a['content_hash'],$hash))return new WP_Error('brand_context_changed_requires_review','Source content differs from approved hash: '.$category);
  $text=file_get_contents($file);
  if(!is_string($text)||!mb_check_encoding($text,'UTF-8')||$text==='')return new WP_Error('brand_source_unreadable','Source content missing or invalid');
  $out[$category]=['asset_id'=>$a['asset_id'],'attachment_id'=>$id,'sha256'=>$hash,'content'=>$text];
 }
 return ['site_uuid'=>$status['site_uuid'],'brand_id'=>$status['brand_id'],'registry_revision'=>$status['registry_revision'],'context_fingerprint'=>$status['context_fingerprint'],'authority_manifest_fingerprint'=>$status['authority_manifest_fingerprint'],'assets'=>$out];
}
function brand_context_generation_create_draft($title,$html){
 if(!current_user_can('edit_posts'))return new WP_Error('generation_permission_denied','Editor required');
 $context=brand_context_generation_context();if(is_wp_error($context))return $context;
 $payload=$context;foreach($payload['assets'] as &$a)unset($a['content']);unset($a);
 $post_id=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>sanitize_text_field($title),'post_content'=>wp_kses_post($html),'post_author'=>get_current_user_id()],true);
 if(is_wp_error($post_id))return $post_id;
 update_post_meta($post_id,'_brand_context_generation_provenance',wp_json_encode($payload));
 return $post_id;
}
