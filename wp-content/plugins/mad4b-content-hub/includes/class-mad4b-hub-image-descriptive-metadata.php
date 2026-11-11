<?php
defined('ABSPATH')||exit;
/**
 * Shared non-SEO image metadata derived from verified source binding and
 * actually reviewed visual observations. Existing values are never overwritten.
 */
final class MAD4B_Hub_Image_Descriptive_Metadata {
 const CONTRACT='mad4b.hub.image-descriptive-metadata.v1';
 public static function propose($candidate,$observed){
  if(!is_array($candidate)||!is_array($observed)||!$observed)return [];
  $names=[];
  foreach(array_slice($observed,0,10) as $term){
   if(!is_string($term))continue;
   $term=trim(sanitize_text_field($term));
   if($term!==''&&mb_strlen($term)<=90)$names[]=$term;
  }
  if(!$names)return [];
  $visual=mb_substr(implode(', ',$names),0,220);
  // Source alt/title are context hints, not verified identity or a rights grant.
  // Use reviewed visual observations as the canonical descriptive input.
  return [
   'title'=>mb_substr($visual,0,220),
   'alt'=>mb_substr($visual,0,220),
   'caption'=>mb_substr($visual,0,220),
   'description'=>mb_substr('Observed image subjects: '.$visual,0,1500),
   'credit'=>'',
   'origin'=>'human_or_provider_reviewed_visual_subjects',
   'source_title'=>(string)($candidate['original_title']??''),
   'source_alt'=>(string)($candidate['original_alt']??''),
   'seo_fields_generated'=>false
  ];
 }
 public static function fill_missing_after_verified_bind($attachment_id,$proposal){
  $id=absint($attachment_id);
  if(wp_get_environment_type()!=='staging'||!current_user_can('edit_post',$id)
    ||!current_user_can('upload_files')||get_post_type($id)!=='attachment'
    ||!wp_attachment_is_image($id))
   return new WP_Error('mad4b_shared_image_metadata_denied','Authorized image editor required',['status'=>403]);
  if(!is_array($proposal)||($proposal['origin']??'')!=='human_or_provider_reviewed_visual_subjects')
   return new WP_Error('mad4b_shared_image_metadata_unverified','Verified visual observations required',['status'=>409]);
  $post=get_post($id);
  $update=['ID'=>$id];$changes=[];
  $fields=['title'=>'post_title','caption'=>'post_excerpt','description'=>'post_content'];
  foreach($fields as $key=>$wpKey){
   if(trim((string)$post->$wpKey)!=='')continue;
   $value=trim(sanitize_textarea_field((string)($proposal[$key]??'')));
   if($value==='')continue;
   $update[$wpKey]=$value;$changes[]=$key;
  }
  $alt=(string)get_post_meta($id,'_wp_attachment_image_alt',true);
  if(trim($alt)===''&&trim((string)($proposal['alt']??''))!==''){
   $update['meta_input']['_wp_attachment_image_alt']=sanitize_text_field($proposal['alt']);
   $changes[]='alt';
  }
  if(count($update)>1){
   $result=wp_update_post($update,true);
   if(is_wp_error($result))return $result;
  }
  // Credits are deliberately not guessed. Only explicit verified attribution
  // can populate the shared credit field.
  $credit=trim((string)get_post_meta($id,'_mad4b_image_credit',true));
  if($credit===''&&trim((string)($proposal['credit']??''))!==''){
   $credit=sanitize_text_field($proposal['credit']);
   update_post_meta($id,'_mad4b_image_credit',$credit);$changes[]='credit';
  }
  if($changes){
   update_post_meta($id,'_mad4b_hub_image_descriptive_metadata_provenance',[
    'contract'=>self::CONTRACT,
    'fields_written'=>$changes,'origin'=>$proposal['origin'],
    'updated_by_user_id'=>get_current_user_id(),'created_at'=>gmdate('c'),
    'company_seo_overridden'=>false
   ]);
  }
  $back=get_post($id);
  if(!$back)return new WP_Error('mad4b_shared_image_metadata_readback_missing','Missing attachment after update',['status'=>500]);
  foreach($fields as $key=>$wpKey)
   if(in_array($key,$changes,true)&&$back->$wpKey!==$update[$wpKey])
    return new WP_Error('mad4b_shared_image_metadata_readback_mismatch','Metadata field did not persist',['status'=>500]);
  if(in_array('alt',$changes,true)
    &&(string)get_post_meta($id,'_wp_attachment_image_alt',true)!==(string)$update['meta_input']['_wp_attachment_image_alt'])
   return new WP_Error('mad4b_shared_image_alt_readback_mismatch','Alt text did not persist',['status'=>500]);
  return ['contract'=>self::CONTRACT,'attachment_id'=>$id,
   'fields_filled'=>$changes,'existing_values_preserved'=>true,
   'credit_requires_verified_attribution'=>true,'company_seo_overridden'=>false,
   'published'=>false,'mutation_performed'=>!empty($changes)];
 }
}
