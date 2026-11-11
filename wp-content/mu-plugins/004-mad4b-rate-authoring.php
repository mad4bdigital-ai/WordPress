<?php
/** Plugin Name: MAD4B Native Rate Authoring */
defined('ABSPATH')||exit;
require_once WPMU_PLUGIN_DIR.'/shared-product-core/pricing/rate-authoring-policy.php';
final class MAD4B_Native_Rate_Authoring {
 public static function load():void {
  if(!mad4b_rate_authoring_enabled())return;
  $root=WPMU_PLUGIN_DIR.'/shared-product-core/';
  $modules=['adapters/tour-program-source-contract-v1.php','pricing/accommodation-assignment.php','pricing/rate-generation.php','pricing/rate-source-batch.php','adapters/tour-rate-seed-bridge-v1.php'];
  // The pricing editor is needed by its seed workflow in admin/CLI, not a reason to change public source selection.
  if(is_admin()||(defined('WP_CLI')&&WP_CLI))$modules[]='adapters/presentation-tour-pricing-entry-v1.php';
  $modules[]='admin/rate-generation.php';
  $modules[]='pricing/rate-planner.php';
  foreach($modules as $module)if(!is_readable($root.$module))return;
  foreach($modules as $module)require_once $root.$module;
 }
 public static function menu():void {
  if(!mad4b_rate_authoring_enabled())return;
  add_submenu_page('edit.php?post_type=tour-rates','Rate Generation','Generate Rates','commerce_manage_pricing','mad4b-rate-generation',static function(){
   if(!MAD4B_Lifecycle_Access::can('commerce_manage_pricing'))wp_die('Forbidden',403);
   echo '<div class="wrap"><form method="post" action="'.esc_url(admin_url('admin.php?page=mad4b-rate-generation')).'">';
   do_action('woocommerce_settings_tabs_rate_generation');
   echo '</form></div>';
  });
 }
}
add_action('plugins_loaded',[MAD4B_Native_Rate_Authoring::class,'load'],35);
add_action('admin_menu',[MAD4B_Native_Rate_Authoring::class,'menu'],95);
