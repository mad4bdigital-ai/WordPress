<?php
/**
 * Plugin Name: MAD4B ChatbotX Elementor Kit Appearance
 * Description: Site-scoped visual integration with the active Elementor Kit. Preserves ChatbotX's original widget, lazy iframe and provider behavior.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
final class MAD4B_ChatbotX_Elementor_Kit {
 private static ?string $color = null;
 public static function scope():bool { return MAD4B_Presentation_Experience::enabled('chatbotx-elementor-kit'); }
 public static function primary_color():string {
  if(self::$color!==null)return self::$color;
  $fallback='#102D40';
  if(class_exists('Elementor\Plugin',false)){
   $plugin=\Elementor\Plugin::$instance;
   if($plugin&&isset($plugin->kits_manager)){
    $kit=$plugin->kits_manager->get_active_kit();
    if($kit&&method_exists($kit,'get_settings_for_display')){
     $colors=$kit->get_settings_for_display('system_colors');
     if(is_array($colors))foreach($colors as $item){
      if(!is_array($item)||($item['_id']??'')!=='primary')continue;
      $valid=sanitize_hex_color((string)($item['color']??''));
      if($valid && strlen($valid)===7){$fallback=strtoupper($valid);break;}
     }
    }
   }
  }
  return self::$color=$fallback;
 }
 public static function live_bridge_setting($value){
  if(!self::scope()||is_admin()||!is_array($value))return $value;
  if(!class_exists('Customer_Messaging_Bridge',false))return $value;
  // CMB uses the same option on synchronous checkout and delayed public pages.
  // Runtime view only: no duplicated stored brand setting or provider source edits.
  $value['brand_color']=self::primary_color();
  return $value;
 }
 public static function assets():void {
  if(!self::scope()||is_admin()
   ||!class_exists('Customer_Messaging_Bridge',false)
   ||!Customer_Messaging_Bridge::ready())return;
  $file=WP_CONTENT_DIR.'/mu-plugins/assets/mad4b-chatbotx-elementor-kit.css';
  if(is_readable($file))
   wp_enqueue_style('mad4b-chatbotx-elementor-kit',
    MAD4B_Presentation_Experience::asset_url('mad4b-chatbotx-elementor-kit.css'),
    [],(string)filemtime($file));
 }
 public static function boot():void {
  if(!self::scope())return;
  add_filter('option_cmb_settings_v1',[__CLASS__,'live_bridge_setting'],60);
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],105);
 }
}
add_action('init',['MAD4B_ChatbotX_Elementor_Kit','boot'],20);
