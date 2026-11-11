<?php
/** Optional star classification policy on package_levels terms. */
defined('ABSPATH') || exit;
add_action('package_levels_add_form_fields',static function(){
 echo '<div class="form-field"><label>Stars Tier — eligible hotel stars (optional)</label>';
 echo '<fieldset><legend class="screen-reader-text">Allowed hotel stars</legend>';
 for($i=1;$i<=7;$i++)echo '<label style="display:inline-block;margin:0 12px 6px 0"><input type="checkbox" name="mad4b_package_stars_tier[]" value="'.$i.'"> '.esc_html($i.' Stars').'</label>';
 echo '</fieldset><p>Optional policy: read Property taxonomy "stars" and qualify only matching hotels. Empty means no star restriction; missing Property star data is flagged for review. Does not require room or Unit prices.</p></div>';
 wp_nonce_field('mad4b_package_stars_tier','mad4b_package_stars_tier_nonce');
});
add_action('package_levels_edit_form_fields',static function($term){
 $existing=function_exists('shared_product_assignment_stars_tier')
  ?shared_product_assignment_stars_tier((int)$term->term_id):[];
 if(is_wp_error($existing))$existing=[];
 echo '<tr class="form-field"><th scope="row"><label>Stars Tier</label></th><td><fieldset>';
 for($i=1;$i<=7;$i++)echo '<label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="mad4b_package_stars_tier[]" value="'.$i.'" '.checked(in_array($i,$existing,true),true,false).'> '.esc_html($i.' Stars').'</label>';
 echo '</fieldset><p class="description">Optional eligible star levels for this Package Level. Reads taxonomy "stars", not review scores or property prices. Applied across WPML translations via canonical term identity; leave blank to disable star-based filtering.</p></td></tr>';
 wp_nonce_field('mad4b_package_stars_tier','mad4b_package_stars_tier_nonce');
},20,1);
$mad4bSaveStarsTier=static function($term){
 if(!isset($_POST['mad4b_package_stars_tier_nonce'])
  ||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mad4b_package_stars_tier_nonce'])),'mad4b_package_stars_tier'))
  return;
 $taxonomy=get_taxonomy('package_levels');
 if(!$taxonomy||!current_user_can($taxonomy->cap->manage_terms))return;
 $raw=isset($_POST['mad4b_package_stars_tier'])?wp_unslash($_POST['mad4b_package_stars_tier']):[];
 if(!is_array($raw)||count($raw)>7)return;
 $values=[];
 foreach($raw as $value){
  if(!is_scalar($value)||is_bool($value)||!preg_match('/^[1-7]$/D',(string)$value))return;
  $values[(int)$value]=(int)$value;
 }
 $values=array_values($values);sort($values,SORT_NUMERIC);
 $term=shared_product_assignment_term_id((int)$term,'package_levels');
 if(!$term)return;
 if($values)update_term_meta($term,'stars_tier',$values);
 else delete_term_meta($term,'stars_tier');
};
add_action('created_package_levels',$mad4bSaveStarsTier,20,1);
add_action('edited_package_levels',$mad4bSaveStarsTier,20,1);
