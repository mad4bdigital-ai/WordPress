<?php
/** ETG Lab binds the approved MAD4B-selected market to the existing seasonal system. */
if(!defined('ABSPATH'))return;
add_filter('shared_offer/mad4b_market_profile',static function($candidate){
 if(!class_exists('MAD4B_SCP_Market_Context'))return null;
 $context=MAD4B_SCP_Market_Context::current();
 return is_array($context['selected_market']??null)?$context['selected_market']:null;
},90);
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce')||!class_exists('MAD4B_SCP_Market_Context'))return;
 add_submenu_page('woocommerce','MAD4B MaxMind Market Context','Market GeoIP','manage_woocommerce','mad4b-woo-market-geoip',static function(){
  if(!current_user_can('manage_woocommerce'))wp_die('Access denied');
  $s=MAD4B_SCP_Market_Context::status();
  echo '<div class="wrap"><h1>WooCommerce MaxMind / MAD4B Markets</h1>';
  echo '<p>MaxMind suggests a country; only an explicitly selected approved Market Profile is used for commercial targeting.</p>';
  foreach(['WooCommerce customer default'=>$s['woo_default_location'],'MaxMind license present'=>$s['maxmind_license_present']?'Yes':'No','GeoLite2 database available'=>$s['local_geoip_db_ready']?'Yes':'No','Approved markets'=>(int)$s['approved_markets']] as $label=>$value)echo '<p><strong>'.esc_html($label).':</strong> '.esc_html((string)$value).'</p>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=integration&section=maxmind_geolocation')).'">Configure WooCommerce MaxMind</a></p>';
  echo '<p>Market selector shortcode: <code>[mad4b_market_selector]</code>. Detecting a country does not authorize prices, discounts, taxes or payment methods.</p></div>';
 });
},96);
