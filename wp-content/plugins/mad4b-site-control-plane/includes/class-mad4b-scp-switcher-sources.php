<?php
/** MAD4B Switcher data adapters; never duplicates Woo/WPML/WCML master lists or assets. */
if (!defined('ABSPATH')) exit;
final class MAD4B_SCP_Switcher_Sources {
 const CONTRACT='mad4b.switcher-sources.v1';
 public static function countries(){
  if(!class_exists('WC_Countries'))return [];
  // A failed Woo provider or WP_Error must not crash the footer/passport.
  // Source remains WooCommerce; never ship a parallel country list.
  try {
   $woo=function_exists('WC')?WC():null;
   $provider=is_object($woo)&&isset($woo->countries)
    &&is_object($woo->countries)&&method_exists($woo->countries,'get_countries')
    ?$woo->countries:new WC_Countries();
   $countries=$provider->get_countries();
   if(!is_array($countries)||!$countries)$countries=$provider->get_countries();
   if((!is_array($countries)||!$countries)&&is_object($woo)&&method_exists($woo,'plugin_path')){
    $core=$woo->plugin_path().'/i18n/countries.php';
    if(is_readable($core))$countries=include $core; // Native Woo data, no custom country list.
   }
   return is_array($countries)?$countries:[];
  } catch(\Throwable $error){return [];}
 }
 public static function valid_country($iso){
  if(!is_string($iso))return '';
  $iso=strtoupper($iso);
  return preg_match('/^[A-Z]{2}$/D',$iso)&&array_key_exists($iso,self::countries())?$iso:'';
 }
 public static function country_name($iso){$iso=self::valid_country($iso);return $iso?(string)(self::countries()[$iso]??$iso):'';}
 public static function country_flag($iso){
  $iso=self::valid_country($iso);if(!$iso)return '';
  $root=WP_PLUGIN_DIR.'/sitepress-multilingual-cms';
  if(!is_dir($root.'/res/flags'))return '';
  $stem=strtolower($iso);
  foreach([$stem.'.country.svg',$stem.'.svg'] as $file){
   if(is_file($root.'/res/flags/'.$file))return set_url_scheme(plugins_url('res/flags/'.$file,$root.'/sitepress.php'),wp_parse_url(home_url('/'),PHP_URL_SCHEME)==='https'?'https':'http');
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
  try {
   $language=class_exists('MAD4B_SCP_Market_Context')
    ?MAD4B_SCP_Market_Context::language()
    :apply_filters('wpml_current_language','');
   return is_string($language)?sanitize_key($language):'';
  } catch(\Throwable $error){return '';}
 }
 public static function currencies(){
  $settings=get_option('_wcml_settings',[]);
  if(!is_array($settings)||!is_array($settings['currency_options']??null))return [];
  $wcml=$settings['currency_options'];
  $names=function_exists('get_woocommerce_currencies')?get_woocommerce_currencies():[];
  if(!is_array($names))$names=[];
  $out=[];foreach(array_keys($wcml) as $code){
   if(!is_string($code)||!preg_match('/^[A-Z]{3}$/D',$code))continue;
   try {
    $symbol=function_exists('get_woocommerce_currency_symbol')
     ?get_woocommerce_currency_symbol($code):$code;
   } catch(\Throwable $error){$symbol=$code;}
   if(!is_string($symbol))$symbol=$code;
   $out[$code]=['code'=>$code,'name'=>is_string($names[$code]??null)?$names[$code]:$code,
    'symbol'=>html_entity_decode($symbol,ENT_QUOTES|ENT_HTML5,'UTF-8')];
  }
  return $out;
 }
 public static function approved_market($country,$language=''){
  if(!class_exists('MAD4B_SCP_Market_Profiles'))return [];
  $country=self::valid_country($country);$language=$language?:self::current_language();
  if(!$country||!$language)return [];
  try {
   $profile=MAD4B_SCP_Market_Profiles::by_country($country,$language);
   return is_array($profile)?$profile:[];
  } catch(\Throwable $error){return [];}
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
