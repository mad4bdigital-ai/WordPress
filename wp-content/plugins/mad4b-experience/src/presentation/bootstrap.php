<?php
/**
 * Plugin Name: MAD4B Presentation Module
 * Description: Portable Elementor/JetEngine/WPML layouts, dynamic navigation and rendering assets; site-owned feature settings.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Text Domain: mad4b-presentation-experience
 */
defined('ABSPATH') || exit;
final class MAD4B_Presentation_Experience {
 const OPTION='mad4b-presentation-experience_features_v1';
 const MODULES=['chatbotx-elementor-kit'=>'343-mad4b-chatbotx-elementor-kit.php','mobile-scroll-action'=>'344-mad4b-mobile-scroll-action.php','tour-inquiry-sidebar'=>'347-mad4b-tour-inquiry-sidebar-sticky.php','jetengine-count-locale'=>'356-mad4b-jetengine-count-locale.php','archive-mobile-dock'=>'363-mad4b-archive-single-mobile-dock.php','featured-listing-banner'=>'364-mad4b-featured-listing-banner-parity.php','city-visible-image'=>'366-mad4b-city-visible-image.php','layout-switcher-compatibility'=>'369-layout-switcher-compatibility.php','sticky-hover-protection'=>'371-mad4b-sticky-hover-protection.php','uael-critical-css'=>'performance/307-mad4b-uael-critical-css-policy.php','city-first-card-lcp'=>'performance/310-mad4b-city-slider-lcp-preload.php','wpml-flag-preload-trim'=>'performance/329-mad4b-home-wpml-flag-preload-trim.php','city-card-intermediate-image'=>'performance/jet-city-slider-image-size.php','newsletter-image-css-sync'=>'performance/newsletter-image-options-cache-sync.php'];
 public static function bound():bool {
  static $cached=null;
  if($cached!==null)return $cached;
  $home=rtrim(strtolower((string)home_url('/')),'/');
  $scheme=(string)wp_parse_url($home,PHP_URL_SCHEME);
  if(!in_array($scheme,['http','https'],true))return $cached=false;
  // Public production sites require HTTPS; local/dev may use native HTTP.
  if(wp_get_environment_type()==='production'&&$scheme!=='https')return $cached=false;
  if(defined('MAD4B_TENANT_ORIGIN') && $home!==rtrim(strtolower((string)MAD4B_TENANT_ORIGIN),'/'))return $cached=false;
  if(defined('MAD4B_TENANT_ENVIRONMENT') &&
     wp_get_environment_type()!==(string)MAD4B_TENANT_ENVIRONMENT)return $cached=false;
  if(defined('MAD4B_TENANT_SITE_UUID')) {
   $uuid=(string)MAD4B_TENANT_SITE_UUID;
   if(!wp_is_uuid($uuid))return $cached=false;
   $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
   if(is_array($profile)&&isset($profile['site_uuid'])&&$profile['site_uuid']!==$uuid)return $cached=false;
   if(class_exists('MAD4B_SCP_Site_Profile',false) && method_exists('MAD4B_SCP_Site_Profile','site_uuid')
      && MAD4B_SCP_Site_Profile::site_uuid()!==$uuid)return $cached=false;
  }
  return $cached=true;
 }
 public static function site_uuid():string {
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  $profile=is_array($p)?(string)($p['site_uuid']??''):'';
  $uuid=defined('MAD4B_TENANT_SITE_UUID')?(string)MAD4B_TENANT_SITE_UUID:$profile;
  if(!wp_is_uuid($uuid) || ($profile!=='' && $profile!==$uuid))return '';
  return $uuid;
 }
 public static function enabled(string $feature):bool {
  if(!self::bound() || !isset(self::MODULES[$feature]))return false;
  $settings=get_option(self::OPTION,[]);
  return !is_array($settings) || !array_key_exists($feature,$settings) || (bool)$settings[$feature];
 }
 public static function setting(string $key,string $fallback=''):string {
  $settings=get_option('mad4b-presentation-experience_settings_v1',[]);
  $value=is_array($settings)?($settings[$key]??$fallback):$fallback;
  return is_string($value)&&strlen($value)<120&&preg_match('/^[a-zA-Z0-9_-]+$/D',$value)?$value:$fallback;
 }
 public static function asset_path(string $name):string { return __DIR__.'/assets/'.$name; }
 public static function asset_url(string $name):string { return set_url_scheme(plugin_dir_url(__FILE__).'assets/'.rawurlencode($name),(string)wp_parse_url(home_url('/'),PHP_URL_SCHEME)==='https'?'https':'http'); }
 public static function boot():void {
  // Legacy MU loaders may include modules from portable-modules/ before
  // ordinary plugins load. During reconciliation they remain sole hook owners.
  // This is runtime coexistence, not a permanent dependency on MU packaging.
  $included=array_fill_keys(get_included_files(),true);
  foreach(self::MODULES as $feature=>$module) {
   $filename=basename($module);
   $old=WPMU_PLUGIN_DIR.'/'.$filename;
   if(is_file($old))continue;
   $legacy=realpath(WPMU_PLUGIN_DIR.'/portable-modules/'.$filename);
   if(is_string($legacy)&&isset($included[$legacy]))continue;
   require_once __DIR__.'/modules/'.$module;
  }
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_init',[__CLASS__,'settings_registration']);
 }
 public static function settings_registration():void {
  register_setting('mad4b-presentation-experience','mad4b-presentation-experience_features_v1',[
   'type'=>'array','default'=>[],
   'sanitize_callback'=>static function($value):array {
    $out=[];
    if(!is_array($value))return $out;
    foreach(self::MODULES as $feature=>$file)$out[$feature]=!empty($value[$feature]);
    return $out;
   }
  ]);
 }
 public static function menu():void {
  if(!current_user_can('manage_options'))return;
  add_options_page('MAD4B Presentation Module','MAD4B Presentation Module','manage_options',
    'mad4b-presentation-experience',[__CLASS__,'render']);
 }
 public static function render():void {
  if(!current_user_can('manage_options'))return;
  $settings=get_option(self::OPTION,[]);
  if(!is_array($settings))$settings=[];
  echo '<div class="wrap"><h1>MAD4B Presentation Module</h1>';
  echo '<p>Feature modules are portable across registered sites and environments. Disable only features not used by this site.</p>';
  echo '<form method="post" action="options.php">';
  settings_fields('mad4b-presentation-experience');
  foreach(self::MODULES as $feature=>$file) {
   $on=!array_key_exists($feature,$settings)||!empty($settings[$feature]);
   echo '<p><label><input type="hidden" name="'.esc_attr(self::OPTION).'['.esc_attr($feature).']" value="0">';
   echo '<input type="checkbox" name="'.esc_attr(self::OPTION).'['.esc_attr($feature).']" value="1" '.checked($on,true,false).'>';
   echo ' '.esc_html(ucwords(str_replace('-',' ',$feature))).'</label></p>';
  }
  submit_button();echo '</form></div>';
 }
}
MAD4B_Presentation_Experience::boot();
