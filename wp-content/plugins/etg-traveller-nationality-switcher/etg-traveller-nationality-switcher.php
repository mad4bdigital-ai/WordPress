<?php
/**
 * Plugin Name: ETG Traveller Nationality Switcher
 * Description: Passport-country selection for travellers, separate from WooCommerce market, shipping, billing and WCML currency.
 * Version: 0.2.0-local
 * License: GPL-2.0-or-later
 * Text Domain: etg-traveller-nationality-switcher
 */
if(!defined('ABSPATH'))exit;
final class ETG_Traveller_Nationality_Switcher {
 const CONTRACT='etg.traveller-nationality.v1';
 const SESSION='etg_nationality_preference_v1';
 const COOKIE='etg_nationality_preference_v1';
 public static function countries(){
  if(class_exists('MAD4B_SCP_Switcher_Sources'))return MAD4B_SCP_Switcher_Sources::countries();
  // MAD4B intentionally skips its heavy runtime on unrelated admin-AJAX requests.
  // Passport nationality must remain independent: resolve from native WooCommerce.
  if(!class_exists('WC_Countries'))return [];
  $wc=function_exists('WC')&&WC()->countries?WC()->countries:new WC_Countries();
  $list=$wc->get_countries();
  if(!$list)$list=$wc->get_countries();
  if(!$list&&function_exists('WC')){
   $native=WC()->plugin_path().'/i18n/countries.php';
   if(is_readable($native))$list=include $native;
  }
  return is_array($list)?$list:[];
 }
 public static function valid($iso){
  $iso=strtoupper((string)$iso);
  return preg_match('/^[A-Z]{2}$/D',$iso)&&isset(self::countries()[$iso])?$iso:'';
 }
 public static function flag_url($code){
  $code=self::valid($code);if(!$code)return '';
  if(class_exists('MAD4B_SCP_Switcher_Sources'))return MAD4B_SCP_Switcher_Sources::country_flag($code);
  $flags=WP_PLUGIN_DIR.'/sitepress-multilingual-cms/res/flags/';
  foreach([strtolower($code).'.country.svg',strtolower($code).'.svg'] as $asset)
   if(is_file($flags.$asset))return plugins_url('res/flags/'.$asset,WP_PLUGIN_DIR.'/sitepress-multilingual-cms/sitepress.php');
  return '';
 }
 public static function flag_html($code){
  $code=self::valid($code);if(!$code)return '';
  $src=self::flag_url($code);
  return $src?'<img class="mad4b-country-flag" src="'.esc_url($src).'" alt="'.esc_attr(self::countries()[$code]).'" width="18" height="14">':'<span>'.esc_html($code).'</span>';
 }
 private static function site(){return hash('sha256',home_url('/'));}
 private static function unpack($record){
  if(!is_array($record)||($record['contract']??'')!==self::CONTRACT||($record['site_uuid']??'')!==self::site())return '';
  return self::valid($record['country']??'');
 }
 public static function selected(){
  if(!self::countries())return '';
  if(function_exists('WC')&&WC()->session){$a=self::unpack(WC()->session->get(self::SESSION,[]));if($a)return $a;}
  $v=(string)($_COOKIE[self::COOKIE]??'');if(strlen($v)>1400||!str_contains($v,'.'))return '';
  [$payload,$mac]=explode('.',$v,2);
  if(!hash_equals(hash_hmac('sha256',$payload,wp_salt('auth')),$mac))return '';
  $plain=base64_decode(strtr($payload,'-_','+/'),true);
  return is_string($plain)?self::unpack(json_decode($plain,true)):'';
 }
 public static function choose($country){
  $iso=self::valid($country);if(!$iso)return new WP_Error('etg_nationality_unknown','Unsupported passport country');
  $record=['contract'=>self::CONTRACT,'site_uuid'=>self::site(),'country'=>$iso,'saved_at'=>time()];
  if(function_exists('WC')&&WC()->session){WC()->session->set(self::SESSION,$record);if(method_exists(WC()->session,'set_customer_session_cookie'))WC()->session->set_customer_session_cookie(true);}
  if(!headers_sent()){
   $payload=rtrim(strtr(base64_encode(wp_json_encode($record)),'+/','-_'),'=');
   setcookie(self::COOKIE,$payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth')),[
    'expires'=>time()+90*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
  }
  return $iso;
 }
 /** Names are country names from Woo; nationality is ISO2 passport country, not an invented demonym. */
 public static function selector($name='nationality',$chosen='',$allow_blank=true){
  $countries=self::countries();if(!$countries)return '';
  $name=(string)$name;
  if(!preg_match('/^[a-z][a-z0-9_]*(?:\[[0-9]{1,2}\])?$/D',$name))return '';
  $chosen=self::valid($chosen);
  $out='<select name="'.esc_attr($name).'" aria-label="'.esc_attr__('Passport nationality','etg-traveller-nationality-switcher').'" '.($allow_blank?'':'required').'>';
  $out.='<option value="">'.esc_html__('Select passport country','etg-traveller-nationality-switcher').'</option>';
  foreach($countries as $iso=>$label)$out.='<option value="'.esc_attr($iso).'" '.selected($chosen,$iso,false).'>'.esc_html($label).'</option>';
  return $out.'</select>';
 }
 public static function header_shortcode($atts=[]){
  if(!self::countries())return '';
  $current=self::selected();
  $out='<form class="etg-nationality-switcher" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $out.='<input type="hidden" name="action" value="etg_save_nationality">'.wp_nonce_field('etg_save_nationality','_etg_nationality_nonce',true,false);
  $out.='<label>Passport nationality '.self::flag_html($current).' '.self::selector('nationality',$current).'</label>';
  $out.='<button type="submit">'.esc_html__('Save','etg-traveller-nationality-switcher').'</button></form>';
  wp_enqueue_style('etg-nationality-switcher',plugins_url('assets/nationality.css',__FILE__),[],'0.1.0');
  wp_enqueue_script('etg-nationality-switcher',plugins_url('assets/nationality.js',__FILE__),[],'0.1.0',true);
  wp_add_inline_script('etg-nationality-switcher','window.etgNationalityAjax='.wp_json_encode(admin_url('admin-ajax.php?action=etg_nationality_state')).';','before');
  return $out;
 }
 public static function traveler_shortcode($atts=[]){
  $a=shortcode_atts(['count'=>1,'use_preference'=>'yes'],$atts,'etg_traveller_nationalities');
  $num=max(1,min(20,(int)$a['count']));$pref=($a['use_preference']==='yes')?self::selected():'';
  $html='<div class="etg-traveller-nationalities" data-traveller-count="'.$num.'">';
  for($i=0;$i<$num;$i++){
   $html.='<label class="etg-traveller-nationality-field">'.esc_html(sprintf(__('Traveller %d passport nationality','etg-traveller-nationality-switcher'),$i+1));
   $html.=self::selector('etg_traveller_nationalities['.$i.']',$pref,false).'</label>';
  }
  return $html.'</div>';
 }
 public static function validate_list($input){
  if(!is_array($input)||!$input||count($input)>20)return new WP_Error('etg_invalid_passengers','Expected 1–20 traveller nationality entries');
  $result=[];foreach($input as $index=>$iso){
   if(!is_int($index)&&!(is_string($index)&&ctype_digit($index)))return new WP_Error('etg_invalid_passenger_index','Invalid traveller index');
   $i=(int)$index;if($i<0||$i>=20||isset($result[$i])||!is_string($iso))return new WP_Error('etg_invalid_passenger_entry','Invalid traveller nationality');
   $country=self::valid($iso);if(!$country)return new WP_Error('etg_invalid_nationality','Invalid ISO2 passport country');
   $result[$i]=$country;
  }
  ksort($result);
  if(array_keys($result)!==range(0,count($result)-1))return new WP_Error('etg_noncontiguous_travellers','Traveller indexes must be consecutive');
  return $result;
 }
 public static function cart_validate($passed){
  if(!isset($_POST['etg_traveller_nationalities']))return $passed;
  $input=wp_unslash($_POST['etg_traveller_nationalities']);$checked=self::validate_list($input);
  if(is_wp_error($checked)){if(function_exists('wc_add_notice'))wc_add_notice(__('Please select a valid nationality for each traveller.','etg-traveller-nationality-switcher'),'error');return false;}
  return $passed;
 }
 public static function cart_data($data){
  if(!isset($_POST['etg_traveller_nationalities']))return $data;
  $v=self::validate_list(wp_unslash($_POST['etg_traveller_nationalities']));
  if(!is_wp_error($v))$data['etg_traveller_nationalities']=$v;
  return $data;
 }
 public static function show_cart_data($items,$cart_item){
  $values=$cart_item['etg_traveller_nationalities']??[];
  if(!is_array($values))return $items;
  foreach($values as $i=>$code){$iso=self::valid($code);if(!$iso)continue;
   $items[]=['key'=>sprintf(__('Traveller %d nationality','etg-traveller-nationality-switcher'),$i+1),'value'=>self::countries()[$iso]??$iso,'display'=>self::countries()[$iso]??$iso];}
  return $items;
 }
 public static function order_item($item,$cart_key,$values,$order){
  $data=$values['etg_traveller_nationalities']??[];
  $valid=self::validate_list($data);if(is_wp_error($valid))return;
  foreach($valid as $i=>$iso)$item->add_meta_data('Traveller '.($i+1).' passport country (ISO2)',$iso,true);
 }
 /** Opt-in Fluent Forms integration: only specifically named nationality fields. */
 public static function fluent_validate($errors,$form_data){
  if(!is_array($form_data))return $errors;
  if(isset($form_data['etg_traveller_nationalities'])){
   $check=self::validate_list($form_data['etg_traveller_nationalities']);
   if(is_wp_error($check))$errors['etg_traveller_nationalities'][]=__('Invalid passport nationality for one or more travellers.','etg-traveller-nationality-switcher');
  }
  if(isset($form_data['etg_passport_country'])&&!self::valid($form_data['etg_passport_country']))$errors['etg_passport_country'][]=__('Please choose a valid passport country.','etg-traveller-nationality-switcher');
  return $errors;
 }
 public static function fluent_normalize($form_data){
  if(!is_array($form_data))return $form_data;
  if(isset($form_data['etg_traveller_nationalities'])){
   $v=self::validate_list($form_data['etg_traveller_nationalities']);
   if(!is_wp_error($v))$form_data['etg_traveller_nationalities']=$v;
  }
  if(isset($form_data['etg_passport_country']))$form_data['etg_passport_country']=self::valid($form_data['etg_passport_country']);
  return $form_data;
 }
 public static function post(){
  if(empty($_POST['_etg_nationality_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_etg_nationality_nonce'])),'etg_save_nationality'))wp_die('Invalid nationality request','',['response'=>403]);
  $iso=strtoupper(sanitize_text_field(wp_unslash($_POST['nationality']??'')));
  $result=self::choose($iso);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'',['response'=>400]);
  nocache_headers();wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;
 }
 public static function ajax_state(){
  nocache_headers();$country=self::selected();
  wp_send_json_success(['contract'=>self::CONTRACT,'country'=>$country,
   'flag_url'=>$country?self::flag_url($country):''],200);
 }
 public static function boot(){
  add_shortcode('etg_nationality_switcher',[__CLASS__,'header_shortcode']);
  add_shortcode('etg_traveller_nationalities',[__CLASS__,'traveler_shortcode']);
  add_action('admin_post_etg_save_nationality',[__CLASS__,'post']);
  add_action('admin_post_nopriv_etg_save_nationality',[__CLASS__,'post']);
  add_action('wp_ajax_etg_nationality_state',[__CLASS__,'ajax_state']);
  add_action('wp_ajax_nopriv_etg_nationality_state',[__CLASS__,'ajax_state']);
  add_filter('woocommerce_add_to_cart_validation',[__CLASS__,'cart_validate'],10,1);
  add_filter('woocommerce_add_cart_item_data',[__CLASS__,'cart_data'],10,1);
  add_filter('woocommerce_get_item_data',[__CLASS__,'show_cart_data'],10,2);
  add_action('woocommerce_checkout_create_order_line_item',[__CLASS__,'order_item'],10,4);
  add_filter('fluentform/validation_errors',[__CLASS__,'fluent_validate'],15,2);
  add_filter('fluentform/insert_response_data',[__CLASS__,'fluent_normalize'],15,1);
 }
}
add_action('plugins_loaded',['ETG_Traveller_Nationality_Switcher','boot'],50);
require_once __DIR__.'/includes-checkout-blocks.php';
