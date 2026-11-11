<?php
/** ETG lab-only seasonal tour catalog projection; no production deployment. */
if (!defined('ABSPATH')) exit;
final class ETG_Lab_Season_Catalog_Sync {
 private static $busy=false;
 public static function boot(){
  if(wp_get_environment_type()!=='local')return;
  add_action('save_post_tour-rates',[__CLASS__,'on_rate_save'],200,3);
  add_action('set_object_terms',[__CLASS__,'on_terms'],200,6);
  add_action('transition_post_status',[__CLASS__,'on_status'],200,3);
  add_action('before_delete_post',[__CLASS__,'on_delete'],20,2);
  add_action('added_post_meta',[__CLASS__,'on_meta'],200,4);
  add_action('updated_post_meta',[__CLASS__,'on_meta'],200,4);
  add_action('deleted_post_meta',[__CLASS__,'on_meta'],200,4);
 }
 public static function sync_tour($tour,$exclude=0){
  $tour=(int)$tour;if(!$tour||get_post_type($tour)!=='tours-and-activities'||self::$busy)return false;
  self::$busy=true;
  try {
   $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$tour,'suppress_filters'=>true]);
   $wanted=[];foreach($rates as $rate){if((int)$rate===(int)$exclude)continue;$terms=wp_get_object_terms($rate,'season',['fields'=>'ids']);if(is_wp_error($terms))continue;foreach($terms as $term)$wanted[(int)$term]=true;}
   $old=wp_get_object_terms($tour,'season',['fields'=>'ids']);if(is_wp_error($old))return false;
   $managed=array_map('intval',(array)get_post_meta($tour,'_etg_projected_season_ids',true));
   // Never remove manually assigned terms not recorded as managed.
   $keep=array_diff(array_map('intval',$old),$managed);
   $next=array_values(array_unique(array_merge($keep,array_keys($wanted))));sort($next);
   $oldSorted=array_map('intval',$old);sort($oldSorted);
   if($next!==$oldSorted){$res=wp_set_object_terms($tour,$next,'season',false);if(is_wp_error($res))return false;}
   update_post_meta($tour,'_etg_projected_season_ids',array_keys($wanted));return true;
  } finally { self::$busy=false; }
 }
 public static function on_rate_save($id,$post,$update){self::sync_rate($id);}
 public static function on_terms($id,$terms,$tt_ids,$taxonomy,$append,$old_tt_ids){if($taxonomy==='season'&&get_post_type($id)==='tour-rates')self::sync_rate($id);}
 public static function on_status($new,$old,$post){if($post->post_type==='tour-rates'&&$new!==$old)self::sync_rate($post->ID);}
 public static function on_meta($meta_id,$object_id,$key,$value){if($key==='related_tour_id'&&get_post_type($object_id)==='tour-rates')self::sync_rate($object_id);}
 public static function on_delete($id,$post){
  if(!$post||$post->post_type!=='tour-rates')return;
  $tour=(int)get_post_meta($id,'related_tour_id',true);
  $old=(int)get_post_meta($id,'_etg_last_synced_tour_id',true);
  if($tour)self::sync_tour($tour,$id);
  if($old&&$old!==$tour)self::sync_tour($old,$id);
 }
 public static function sync_rate($id){if(self::$busy||get_post_type($id)!=='tour-rates')return;$current=(int)get_post_meta($id,'related_tour_id',true);$previous=(int)get_post_meta($id,'_etg_last_synced_tour_id',true);
  if($previous&&$previous!==$current)self::sync_tour($previous);
  if($current)self::sync_tour($current);
  update_post_meta($id,'_etg_last_synced_tour_id',$current);
 }
}
add_action('init',['ETG_Lab_Season_Catalog_Sync','boot'],40);
