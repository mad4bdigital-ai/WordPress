<?php
/** Compatibility consumer for MAD4B core market profiles. No independent authority or writes. */
if(!defined('ABSPATH'))return;
function shared_offer_market_registry(){
 return class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::records():[];
}
function shared_offer_market_site_uuid(){
 return class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::site_uuid():'';
}
function shared_offer_verified_market($id){
 return class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::approved($id):[];
}
add_filter('shared_offer/mad4b_available_market_profiles',static function($unused){
 $approved=class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::approved_list():[];
 return array_map(static fn($p)=>['profile_id'=>$p['profile_id'],'label'=>$p['label'].' ('.$p['country'].' / '.$p['language'].')','language'=>$p['language'],'country'=>$p['country']],$approved);
});
/** Never replace a WPML-hidden or unknown page language with the WordPress default. */
function shared_offer_current_visible_language(){
 if(!class_exists('MAD4B_SCP_Market_Profiles'))return '';
 $visible=MAD4B_SCP_Market_Profiles::enabled_languages();
 $visibility=MAD4B_SCP_Market_Profiles::language_visibility();
 if(($visibility['source']??'')==='wpml'){
  $language=sanitize_key((string)apply_filters('wpml_current_language',''));
  return $language&&isset($visible[$language])?$language:'';
 }
 $language=sanitize_key((string)strtok(str_replace('-','_',get_locale()),'_'));
 return isset($visible[$language])?$language:'';
}
add_filter('shared_offer/mad4b_market_profile',static function($unused){
 if(!class_exists('MAD4B_SCP_Market_Profiles')||!function_exists('shared_offer_visitor_country'))return null;
 $language=shared_offer_current_visible_language();
 if(!$language)return null;
 $record=MAD4B_SCP_Market_Profiles::by_country(shared_offer_visitor_country(),$language);
 return $record?:null;
});
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce')||!class_exists('MAD4B_SCP_Market_Profiles'))return;
 add_submenu_page('woocommerce','MAD4B Market Profiles','Market Profiles','manage_options','shared-market-profiles',static function(){
  if(!current_user_can('manage_options'))wp_die('Denied');
  echo '<div class="wrap"><h1>MAD4B Market Profiles</h1><p>This former lab registry is read-only. MAD4B is now the sole write and approval authority.</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=mad4b-market-profiles')).'">Manage profiles in MAD4B</a></div>';
 });
},92);
