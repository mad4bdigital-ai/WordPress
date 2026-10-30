<?php
/** Compatibility-only entrypoint. Google intake and country seasons are owned by MAD4B core. */
if(!defined('ABSPATH'))return;
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce')||!class_exists('MAD4B_SCP_Season_Calendar'))return;
 add_submenu_page('woocommerce','MAD4B Country Seasons','Country Seasons','manage_options','shared-season-calendar-intake',static function(){
  if(!current_user_can('manage_options'))wp_die('Denied');
  echo '<div class="wrap"><h1>MAD4B Country Seasons</h1><p>MAD4B Brand Context Google OAuth owns calendar discovery and retrieval. Local intake can no longer independently store events.</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=mad4b-season-calendar')).'">Manage country calendars in MAD4B</a></div>';
 });
},94);
