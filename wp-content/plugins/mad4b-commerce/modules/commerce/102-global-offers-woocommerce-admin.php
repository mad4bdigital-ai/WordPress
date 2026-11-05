<?php
/** Lab-only WooCommerce offers control center. Reuses existing CPT/options; no duplicated pricing policy. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce'))return;
 add_submenu_page('woocommerce','Offers Control Center','Offers','manage_woocommerce','shared-global-offers', 'shared_global_offers_admin_screen');
},90);
add_action('admin_post_shared_global_offers_save',static function(){
 if(!current_user_can('manage_woocommerce'))wp_die('Insufficient permissions');
 check_admin_referer('shared_global_offers_save');
 $old=get_option('global-offers',[]);$old=is_array($old)?$old:[];
 $candidate=absint(wp_unslash($_POST['active_offer_id']??0));
 if($candidate){$post=get_post($candidate);if(!$post||$post->post_type!=='offer-campaign')wp_die('Invalid campaign');}
 $old['active_offer_id']=$candidate;
 foreach(['display_home','display_archive','display_single','desktop_enabled','tablet_enabled','mobile_enabled','dismissible'] as $field){$old[$field]=isset($_POST[$field])&&wp_unslash($_POST[$field])==='1';}
 // Enabling offers is a separate controlled workflow; this screen never flips it on.
 // Preserve activation state; this screen does not grant activation authority.
 update_option('global-offers',$old);
 wp_safe_redirect(add_query_arg(['page'=>'shared-global-offers','saved'=>'1'],admin_url('admin.php')));exit;
});
function shared_global_offers_admin_screen(){
 if(!current_user_can('manage_woocommerce'))wp_die(esc_html__('Insufficient permissions','woocommerce'));
 $settings=get_option('global-offers',[]);$settings=is_array($settings)?$settings:[];
 $readiness=function_exists('global_offer_readiness_report')?global_offer_readiness_report():['blockers'=>['readiness_unavailable']];
 $campaigns=get_posts(['post_type'=>'offer-campaign','post_status'=>['publish','draft','pending','future','private'],'posts_per_page'=>100,'orderby'=>'date','order'=>'DESC']);
 echo '<div class="wrap"><h1>WooCommerce Offers</h1><p>Single administration entry point. Existing JetEngine options, Shared Product Core pricing policies and Offer Campaign CPT remain authoritative.</p>';
 echo '<h2>Campaign and display settings</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
 echo '<input type="hidden" name="action" value="shared_global_offers_save">';wp_nonce_field('shared_global_offers_save');
 echo '<p><label for="go_active">Selected campaign </label><select id="go_active" name="active_offer_id"><option value="0">No campaign</option>';
 foreach($campaigns as $campaign)echo '<option value="'.(int)$campaign->ID.'" '.selected(absint($settings['active_offer_id']??0),$campaign->ID,false).'>'.esc_html($campaign->post_title?:('#'.$campaign->ID)).' ('.esc_html($campaign->post_status).')</option>';
 echo '</select></p><fieldset><legend>Visibility</legend>';
 foreach(['display_home'=>'Homepage','display_archive'=>'Archives','display_single'=>'Single pages','desktop_enabled'=>'Desktop','tablet_enabled'=>'Tablet','mobile_enabled'=>'Mobile','dismissible'=>'Dismiss button'] as $key=>$label){echo '<p><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($settings[$key]),true,false).'> '.esc_html($label).'</label></p>';}
 echo '</fieldset><p><strong>Safety:</strong> This screen cannot activate offers; activation and checkout certification are handled separately.</p>';submit_button('Save display settings');echo '</form>';
 echo '<h2>Current status</h2><table class="widefat striped"><tbody>';
 foreach(['Offers enabled'=>!empty($settings['offers_enabled'])?'Yes':'No','Active campaign ID'=>absint($settings['active_offer_id']??0),'Checkout coupon adapter'=>!empty($readiness['woo_native_coupon_adapter'])?'Registered':'Unavailable','JetWooBuilder'=>!empty($readiness['jetwoobuilder_active'])?'Active':'Inactive'] as $label=>$value){echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';}
 echo '</tbody></table><h2>Campaigns</h2><p><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type=offer-campaign')).'">Add campaign</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=offer-campaign')).'">Manage campaigns</a></p>';
 echo '<table class="widefat striped"><thead><tr><th>Campaign</th><th>Status</th><th>Shared rule</th><th>Products</th></tr></thead><tbody>';
 foreach($campaigns as $post){$url=get_edit_post_link($post->ID);echo '<tr><td><a href="'.esc_url($url).'">'.esc_html($post->post_title?:('#'.$post->ID)).'</a></td><td>'.esc_html($post->post_status).'</td><td>'.esc_html((string)get_post_meta($post->ID,'promotion_rule_id',true)).'</td><td>'.esc_html((string)get_post_meta($post->ID,'eligible_product_ids',true)).'</td></tr>';}
 if(!$campaigns)echo '<tr><td colspan="4">No campaigns configured.</td></tr>';
 echo '</tbody></table><h2>Readiness blockers</h2><ul>';
 foreach((array)($readiness['blockers']??[]) as $issue)echo '<li><code>'.esc_html((string)$issue).'</code></li>';
 echo '</ul><p>Pricing authority remains in JetEngine Shared Product Core. This screen does not activate offers or modify prices.</p></div>';
}
