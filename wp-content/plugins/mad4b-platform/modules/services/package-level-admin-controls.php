<?php
/** Plugin Name: Package Level — Controlled Tour and Rate Editors (Local) */
if(!defined('ABSPATH'))exit;

/** Existing published terms only; the taxonomy registration is unchanged. */
function shared_package_catalog() {
 $terms=get_terms(['taxonomy'=>'package_levels','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
 if(is_wp_error($terms)||!is_array($terms))return [];
 $result=[];
 foreach($terms as $t)if($t instanceof WP_Term)$result[(int)$t->term_id]=$t->name;
 return $result;
}
function shared_package_allowed_terms($tour_id){
 if(get_post_type($tour_id)!=='tours-and-activities')return [];
 $ids=wp_get_object_terms($tour_id,'package_levels',['fields'=>'ids']);
 if(is_wp_error($ids))return [];
 $catalog=shared_package_catalog();
 $ids=array_values(array_intersect(array_map('intval',$ids),array_keys($catalog)));
 sort($ids,SORT_NUMERIC);
 return $ids;
}
function shared_package_rate_allowed($rate_id,$term_id,$tour_id=0) {
 $rate_id=absint($rate_id);$term_id=absint($term_id);
 if(get_post_type($rate_id)!=='tour-rates')return new WP_Error('not_a_tour_rate');
 if(!$tour_id)$tour_id=absint(get_post_meta($rate_id,'related_tour_id',true));
 if(get_post_type($tour_id)!=='tours-and-activities')return new WP_Error('tour_not_linked');
 if(!$term_id)return true; // Draft may remain without a package.
 if(!isset(shared_package_catalog()[$term_id]))return new WP_Error('unknown_package_level');
 if(!in_array($term_id,shared_package_allowed_terms($tour_id),true))return new WP_Error('package_not_offered_by_tour');
 return true;
}
/**
 * Prevent removing a level used by ANY non-trash Rate of this tour, including
 * drafts; a draft with an orphaned term is still an invalid Rate relationship.
 */
function shared_package_tour_can_assign($tour_id,$levels){
 if(get_post_type($tour_id)!=='tours-and-activities'||!is_array($levels))return new WP_Error('invalid_tour_or_levels');
 $levels=array_values(array_unique(array_map('absint',$levels)));
 $catalog=shared_package_catalog();
 foreach($levels as $id)if(!$id||!isset($catalog[$id]))return new WP_Error('unknown_package_level');
 $related=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','pending','future','private'],'posts_per_page'=>-1,'fields'=>'ids',
   'meta_key'=>'related_tour_id','meta_value'=>(string)$tour_id,'no_found_rows'=>true]);
 foreach($related as $id){
  $level=absint(get_post_meta($id,'related_package_term_id',true));
  if($level && !in_array($level,$levels,true))
   return new WP_Error('package_level_used_by_published_rate',sprintf('Level %d is used by published Rate %d. Update that Rate before removing its Tour level.',$level,$id));
 }
 return true;
}
function shared_package_tour_editor($post){
 $options=shared_package_catalog();$assigned=shared_package_allowed_terms($post->ID);
 wp_nonce_field('shared_package_tour_save','shared_package_tour_nonce');
 echo '<fieldset><legend class="screen-reader-text">Allowed package levels</legend>';
 foreach($options as $term=>$label){
  echo '<label style="display:block;margin:7px 0"><input type="checkbox" name="shared_package_tour_terms[]" value="'.esc_attr((string)$term).'" '.checked(in_array($term,$assigned,true),true,false).' /> '.esc_html($label).'</label>';
 }
 if(!$options)echo '<p>No package levels are configured.</p>';
 echo '</fieldset><p class="description">Select existing approved levels. Rates may use only one of these levels. Levels already used by linked Rates cannot be removed here.</p>';
}
function shared_package_rate_editor($post){
 $tour=absint(get_post_meta($post->ID,'related_tour_id',true));
 $allowed=shared_package_allowed_terms($tour);
 $catalog=shared_package_catalog();
 $chosen=absint(get_post_meta($post->ID,'related_package_term_id',true));
 wp_nonce_field('shared_package_rate_save','shared_package_rate_nonce');
 echo '<p><label for="shared-package-rate-level">Rate package level</label></p>';
 echo '<select id="shared-package-rate-level" name="shared_package_rate_level" style="max-width:100%;width:100%">';
 echo '<option value="">— Select a level —</option>';
 foreach($allowed as $id){
  echo '<option value="'.esc_attr((string)$id).'" '.selected($chosen,$id,false).'>'.esc_html($catalog[$id]??(string)$id).'</option>';
 }
 if($chosen && !in_array($chosen,$allowed,true)){
  echo '<option value="'.esc_attr((string)$chosen).'" selected disabled>Previously selected level is not offered by linked Tour</option>';
 }
 echo '</select><p class="description">Linked Tour ID: '.esc_html((string)$tour).'. Only levels assigned to that Tour are offered.</p>';
}
add_action('add_meta_boxes_tours-and-activities',static function(){
 // Suppress generic tag entry UI without changing taxonomy semantics.
 remove_meta_box('tagsdiv-package_levels','tours-and-activities','side');
 add_meta_box('shared-package-tour-picker','Allowed Package Levels','shared_package_tour_editor','tours-and-activities','side','default');
},100);
add_action('add_meta_boxes_tour-rates',static function(){
 add_meta_box('shared-package-rate-picker','Rate Package Level','shared_package_rate_editor','tour-rates','side','default');
},100);

/** ID-only Classic/JetEngine editor form saves, with nonce/capability checks. */
add_action('save_post_tours-and-activities',static function($id,$post,$update){
 if(!isset($_POST['shared_package_tour_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_package_tour_nonce'])),'shared_package_tour_save'))return;
 if(wp_is_post_revision($id)||wp_is_post_autosave($id)||!current_user_can('edit_post',$id))return;
 $raw=isset($_POST['shared_package_tour_terms'])?wp_unslash($_POST['shared_package_tour_terms']):[];
 if(!is_array($raw)||count($raw)>100)return;
 $terms=array_values(array_unique(array_map('absint',$raw)));
 $allowed=shared_package_tour_can_assign($id,$terms);
 if(is_wp_error($allowed)){set_transient('shared_package_admin_notice_'.get_current_user_id(),$allowed->get_error_message(),60);return;}
 wp_set_object_terms($id,$terms,'package_levels',false);
},999,3);
add_action('save_post_tour-rates',static function($id,$post,$update){
 if(!isset($_POST['shared_package_rate_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_package_rate_nonce'])),'shared_package_rate_save'))return;
 if(wp_is_post_revision($id)||wp_is_post_autosave($id)||!current_user_can('edit_post',$id))return;
 $raw=(string)wp_unslash($_POST['shared_package_rate_level']??'');
 if($raw!==''&&!ctype_digit($raw))return;
 $level=$raw===''?0:absint($raw);
 $valid=shared_package_rate_allowed($id,$level);
 if(is_wp_error($valid)){set_transient('shared_package_admin_notice_'.get_current_user_id(),$valid->get_error_message(),60);return;}
 if($level)update_post_meta($id,'related_package_term_id',(string)$level);
 else delete_post_meta($id,'related_package_term_id');
},999,3);
add_action('admin_notices',static function(){
 if(!current_user_can('edit_posts'))return;
 $k='shared_package_admin_notice_'.get_current_user_id();
 if($message=get_transient($k)){
  delete_transient($k);
  echo '<div class="notice notice-error"><p>'.esc_html($message).'</p></div>';
 }
});

/** Gutenberg REST editing is ID-based; preserve taxonomy's native REST data. */
add_filter('rest_pre_insert_tours-and-activities',static function($prepared,$request){
 if(is_wp_error($prepared))return $prepared;
 if(!$request->has_param('package_levels'))return $prepared;
 $tour=absint($request['id']??0);
 if(!$tour)return $prepared;
 $result=shared_package_tour_can_assign($tour,(array)$request->get_param('package_levels'));
 return is_wp_error($result)?$result:$prepared;
},10,2);
add_action('init',static function(){
 register_post_meta('tour-rates','related_package_term_id',[
  'single'=>true,'type'=>'string','default'=>'','show_in_rest'=>true,
  'sanitize_callback'=>static function($v){return is_scalar($v)?(string)absint($v):'';},
  'auth_callback'=>static function($allowed,$meta_key,$id){return current_user_can('edit_post',$id);},
 ]);
},99);
add_filter('rest_pre_insert_tour-rates',static function($prepared,$request){
 if(is_wp_error($prepared)||!$request->has_param('meta'))return $prepared;
 $meta=(array)$request->get_param('meta');
 if(!array_key_exists('related_package_term_id',$meta))return $prepared;
 $id=absint($request['id']??0);
 if(!$id)return new WP_Error('rate_must_be_linked_first',
   'Create and link the Rate to its Tour before assigning a Package Level.',
   ['status'=>400]);
 $raw=(string)$meta['related_package_term_id'];
 if($raw!==''&&!ctype_digit($raw))return new WP_Error('invalid_package_level','Choose an approved Package Level',['status'=>400]);
 $tour=absint($meta['related_tour_id']??get_post_meta($id,'related_tour_id',true));
 $valid=shared_package_rate_allowed($id,absint($raw),$tour);
 return is_wp_error($valid)?new WP_Error($valid->get_error_code(),$valid->get_error_message(),['status'=>400]):$prepared;
},10,2);

/** Gutenberg: replaces generic free-text taxonomy editing with fixed IDs. */
add_action('enqueue_block_editor_assets',static function(){
 $screen=function_exists('get_current_screen')?get_current_screen():null;
 if(!$screen || !in_array($screen->post_type,['tours-and-activities','tour-rates'],true))return;
 $id=absint($_GET['post']??0);
 $tour=$screen->post_type==='tours-and-activities'?$id:absint(get_post_meta($id,'related_tour_id',true));
 $ids=$screen->post_type==='tour-rates'?shared_package_allowed_terms($tour):array_keys(shared_package_catalog());
 $source=shared_package_catalog();
 $opts=[];
 foreach($ids as $term_id)if(isset($source[$term_id]))$opts[]=['id'=>$term_id,'name'=>$source[$term_id]];
 $required=[];
 if($screen->post_type==='tours-and-activities'){
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>['publish','draft','pending','future','private'],'posts_per_page'=>-1,
   'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$id]);
  foreach($rates as $rate){$level=absint(get_post_meta($rate,'related_package_term_id',true));if($level)$required[]=$level;}
 }
 $args=['isTour'=>$screen->post_type==='tours-and-activities','tourId'=>$tour,
  'options'=>$opts,'required'=>array_values(array_unique($required))];
 wp_enqueue_script('shared-package-editor',content_url('mu-plugins/package-level-editor.js'),
   ['wp-plugins','wp-edit-post','wp-element','wp-data','wp-components','wp-dom-ready'],'1.0.1',true);
 wp_add_inline_script('shared-package-editor','window.sharedPackageLevelEditor='.wp_json_encode($args).';','before');
},30);
