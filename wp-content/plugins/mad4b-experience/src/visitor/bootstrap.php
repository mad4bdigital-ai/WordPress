<?php
/**
 * Plugin Name: MAD4B Visitor Module
 * Description: Portable visitor country/passport labels, Fluent Forms international telephone assets and localization; per-site controls.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Text Domain: mad4b-visitor-experience
 */
defined('ABSPATH') || exit;
final class MAD4B_Visitor_Experience {
 const OPTION='mad4b-visitor-experience_features_v1';
 const MODULES=['fluent-phone-brand-and-dial'=>'348-mad4b-fluent-phone-brand-and-dial.php','visitor-switchers-locale'=>'350-mad4b-visitor-switchers-locale.php'];
 public static function bound():bool {
  return MAD4B_Experience_Site_Scope_V1::bound();
 }
  public static function enabled(string $feature):bool {
  if(!self::bound() || !isset(self::MODULES[$feature]))return false;
  $settings=get_option(self::OPTION,[]);
  return !is_array($settings) || !array_key_exists($feature,$settings) || (bool)$settings[$feature];
 }
 public static function setting(string $key,string $fallback=''):string {
  $settings=get_option('mad4b-visitor-experience_settings_v1',[]);
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
   $old=WPMU_PLUGIN_DIR.'/'.$module;
   if(is_file($old))continue;
   $legacy=realpath(WPMU_PLUGIN_DIR.'/portable-modules/'.$module);
   if(is_string($legacy)&&isset($included[$legacy]))continue;
   require_once __DIR__.'/modules/'.$module;
  }
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_init',[__CLASS__,'settings_registration']);
 }
 public static function settings_registration():void {
  register_setting('mad4b-visitor-experience','mad4b-visitor-experience_features_v1',[
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
  add_options_page('MAD4B Visitor Module','MAD4B Visitor Module','manage_options',
    'mad4b-visitor-experience',[__CLASS__,'render']);
 }
 public static function render():void {
  if(!current_user_can('manage_options'))return;
  $settings=get_option(self::OPTION,[]);
  if(!is_array($settings))$settings=[];
  echo '<div class="wrap"><h1>MAD4B Visitor Module</h1>';
  echo '<p>Feature modules are portable across registered sites and environments. Disable only features not used by this site.</p>';
  echo '<form method="post" action="options.php">';
  settings_fields('mad4b-visitor-experience');
  foreach(self::MODULES as $feature=>$file) {
   $on=!array_key_exists($feature,$settings)||!empty($settings[$feature]);
   echo '<p><label><input type="hidden" name="'.esc_attr(self::OPTION).'['.esc_attr($feature).']" value="0">';
   echo '<input type="checkbox" name="'.esc_attr(self::OPTION).'['.esc_attr($feature).']" value="1" '.checked($on,true,false).'>';
   echo ' '.esc_html(ucwords(str_replace('-',' ',$feature))).'</label></p>';
  }
  submit_button();echo '</form></div>';
 }
}
MAD4B_Visitor_Experience::boot();
