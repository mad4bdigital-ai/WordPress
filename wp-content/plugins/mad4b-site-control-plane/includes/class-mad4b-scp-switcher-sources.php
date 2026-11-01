<?php
/** MAD4B Switcher data adapters; never duplicates Woo/WPML/WCML master lists or assets. */
if (!defined('ABSPATH')) exit;
final class MAD4B_SCP_Switcher_Sources {
 const CONTRACT='mad4b.switcher-sources.v1';
 public static function countries(){
  if(!class_exists('WC_Countries'))return [];
  $provider=function_exists('WC')&&WC()->countries?WC()->countries:new WC_Countries();
  $countries=$provider->get_countries();
  if(!$countries)$countries=$provider->get_countries(); // Woo locale cache may be building.
  if(!$countries&&function_exists('WC')){
   $core=WC()->plugin_path().'/i18n/countries.php';
   if(is_readable($core))$countries=include $core; // Native Woo data, no custom country list.
  }
  return is_array($countries)?$countries:[];
 }
 public static function valid_country($iso){
  $iso=strtoupper((string)$iso);
  return preg_match('/^[A-Z]{2}$/D',$iso)&&array_key_exists($iso,self::countries())?$iso:'';
 }
 public static function country_name($iso){$iso=self::valid_country($iso);return $iso?(string)(self::countries()[$iso]??$iso):'';}
 public static function country_flag($iso){
  $iso=self::valid_country($iso);if(!$iso)return '';
  $root=WP_PLUGIN_DIR.'/sitepress-multilingual-cms';
  if(!is_dir($root.'/res/flags'))return '';
  $stem=strtolower($iso);
  foreach([$stem.'.country.svg',$stem.'.svg'] as $file){
   if(is_file($root.'/res/flags/'.$file))return plugins_url('res/flags/'.$file,$root.'/sitepress.php');
  }
  return '';
 }
 public static function flag_html($iso,$size=18){
  $code=self::valid_country($iso);if(!$code)return '';
  $url=self::country_flag($code);
  $text=esc_attr(self::country_name($code));
  if(!$url)return '<span class="mad4b-country-flag--fallback" aria-label="'.$text.'">'.esc_html($code).'</span>';
  $size=max(12,min(48,(int)$size));
  return '<img class="mad4b-country-flag" src="'.esc_url($url).'" width="'.$size.'" height="'.max(9,(int)round($size*0.75)).'" alt="'. $text.'" loading="lazy" decoding="async">';
 }
 public static function languages(){
  $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
  if(!is_array($langs))return [];
  $out=[];foreach($langs as $code=>$lang){
   if(!is_array($lang))continue;
   $out[$code]=['code'=>(string)$code,'name'=>(string)($lang['native_name']??$code),
    'flag_url'=>(string)($lang['country_flag_url']??''),'url'=>(string)($lang['url']??'')];
  }
  return $out;
 }
 public static function current_language(){
  return class_exists('MAD4B_SCP_Market_Context')?(string)MAD4B_SCP_Market_Context::language():sanitize_key((string)apply_filters('wpml_current_language',''));
 }
 public static function currencies(){
  $wcml=(array)(get_option('_wcml_settings',[])['currency_options']??[]);
  $names=function_exists('get_woocommerce_currencies')?get_woocommerce_currencies():[];
  $out=[];foreach(array_keys($wcml) as $code){
   if(!preg_match('/^[A-Z]{3}$/D',$code))continue;
   $out[$code]=['code'=>$code,'name'=>(string)($names[$code]??$code),
    'symbol'=>html_entity_decode((string)(function_exists('get_woocommerce_currency_symbol')?get_woocommerce_currency_symbol($code):$code),ENT_QUOTES|ENT_HTML5,'UTF-8')];
  }
  return $out;
 }
 public static function approved_market($country,$language=''){
  if(!class_exists('MAD4B_SCP_Market_Profiles'))return [];
  $country=self::valid_country($country);$language=$language?:self::current_language();
  return $country&&$language?MAD4B_SCP_Market_Profiles::by_country($country,$language):[];
 }
 public static function market_countries(){
  if(!class_exists('MAD4B_SCP_Market_Profiles'))return [];
  $countries=self::countries();$list=[];
  foreach(MAD4B_SCP_Market_Profiles::approved_list() as $profile){
   $iso=$profile['country']??'';
   if(isset($countries[$iso]))$list[$iso]=$countries[$iso];
  }
  asort($list,SORT_NATURAL|SORT_FLAG_CASE);
  return $list;
 }
 public static function status(){return ['contract'=>self::CONTRACT,'country_count'=>count(self::countries()),
  'market_country_count'=>count(self::market_countries()),'language_codes'=>array_keys(self::languages()),
  'currency_codes'=>array_keys(self::currencies()),'bundled_flag_assets'=>0];}
}
