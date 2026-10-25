<?php
/**
 * Plugin Name: MAD4B Country Switcher
 * Description: Site-approved country selector over MAD4B Market Profiles, WPML, WooCommerce and MaxMind. No pricing or language duplication.
 * Version: 0.1.0-local
 * Requires Plugins: woocommerce, mad4b-site-control-plane
 * License: GPL-2.0-or-later
 * Text Domain: mad4b-country-switcher
 */
if(!defined('ABSPATH'))exit;
final class MAD4B_Country_Switcher {
 const CONTRACT='mad4b.country-switcher.v1';
 const SESSION='mad4b_country_selected_v1';
 const COOKIE='mad4b_country_selected_v1';
 private static function available(){return class_exists('MAD4B_SCP_Switcher_Sources')&&MAD4B_SCP_Switcher_Sources::CONTRACT==='mad4b.switcher-sources.v1'&&class_exists('MAD4B_SCP_Market_Profiles');}
 private static function uuid(){return self::available()?MAD4B_SCP_Market_Profiles::site_uuid():'';}
 private static function validated_record($a){
  if(!is_array($a)||($a['contract']??'')!==self::CONTRACT||($a['site_uuid']??'')!==self::uuid())return '';
  $iso=MAD4B_SCP_Switcher_Sources::valid_country($a['country']??'');
  return $iso&&isset(MAD4B_SCP_Switcher_Sources::market_countries()[$iso])?$iso:'';
 }
 public static function saved_country(){
  if(!self::available())return '';
  if(function_exists('WC')&&WC()->session){$s=self::validated_record(WC()->session->get(self::SESSION,[]));if($s)return $s;}
  $raw=(string)($_COOKIE[self::COOKIE]??'');if(strlen($raw)>1500||!str_contains($raw,'.'))return '';
  [$payload,$sig]=explode('.',$raw,2);
  if(!hash_equals(hash_hmac('sha256',$payload,wp_salt('auth')),$sig))return '';
  $decoded=base64_decode(strtr($payload,'-_','+/'),true);
  return is_string($decoded)?self::validated_record(json_decode($decoded,true)):'';
 }
 public static function resolve($country,$language=''){
  if(!self::available())return [];
  return MAD4B_SCP_Switcher_Sources::approved_market($country,$language?:MAD4B_SCP_Switcher_Sources::current_language());
 }
 public static function context(){
  $explicit=self::saved_country();
  $hint=(!$explicit&&class_exists('MAD4B_SCP_Market_Context'))?MAD4B_SCP_Market_Context::woo_geo_hint():'';
  $country=$explicit?:$hint;
  $market=$explicit?self::resolve($country):[];
  return ['contract'=>self::CONTRACT,'country'=>$country,'explicit'=>$explicit!=='',
   'suggested_country'=>$hint,'selected_market'=>$market?:null,
   'source'=>$explicit?'manual_country':($hint?'woo_maxmind_advisory':'none'),
   'eligible_market'=>(bool)$market];
 }
 public static function set_country($country){
  if(!self::available())return new WP_Error('mad4b_country_dependency','Missing MAD4B source');
  $iso=MAD4B_SCP_Switcher_Sources::valid_country($country);
  if(!$iso||!isset(MAD4B_SCP_Switcher_Sources::market_countries()[$iso]))return new WP_Error('mad4b_country_unapproved','Country not targeted');
  $record=['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),'country'=>$iso,'time'=>time()];
  if(function_exists('WC')&&WC()->session){
   WC()->session->set(self::SESSION,$record);
   if(method_exists(WC()->session,'set_customer_session_cookie'))WC()->session->set_customer_session_cookie(true);
  }
  if(!headers_sent()){
   $payload=rtrim(strtr(base64_encode(wp_json_encode($record)),'+/','-_'),'=');
   setcookie(self::COOKIE,$payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth')),[
    'expires'=>time()+90*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
  }
  do_action('mad4b_country_switcher_country_selected',$iso);
  return ['country'=>$iso,'market_profile_id'=>self::resolve($iso)['profile_id']??null];
 }
 public static function filter_profile($previous,$language){
  $country=self::saved_country();
  if(!$country)return $previous;
  $profile=self::resolve($country,$language);
  return $profile['profile_id']??''; // A country without a matching page language has no marketing entitlement.
 }
 public static function post(){
  if(empty($_POST['_mad4b_country_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_mad4b_country_nonce'])),'mad4b_country_choose'))wp_die('Invalid country request','',['response'=>403]);
  $country=strtoupper(sanitize_text_field(wp_unslash($_POST['market_country']??'')));
  $result=self::set_country($country);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'',['response'=>400]);
  nocache_headers();wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;
 }
 public static function shortcode($atts=[]){
  if(!self::available())return '';
  $countries=MAD4B_SCP_Switcher_Sources::market_countries();if(!$countries)return '';
  $a=shortcode_atts(['label'=>'Country','class'=>''],$atts,'mad4b_country_switcher');
  $context=self::context();$current=$context['country'];$class=sanitize_html_class($a['class']);
  $out='<form class="mad4b-country-switcher '.esc_attr($class).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $out.='<input type="hidden" name="action" value="mad4b_country_choose">'.wp_nonce_field('mad4b_country_choose','_mad4b_country_nonce',true,false);
  $out.='<label><span class="mad4b-switcher-label">'.esc_html($a['label']).'</span> '.MAD4B_SCP_Switcher_Sources::flag_html($current);
  $out.='<select name="market_country" required><option value="">'.esc_html__('Select country','mad4b-country-switcher').'</option>';
  foreach($countries as $iso=>$label){
   $availability=self::resolve($iso);
   $suffix=$availability?'':' (choose supported language)';
   $out.='<option value="'.esc_attr($iso).'" '.selected($current,$iso,false).'>'.esc_html($label.$suffix).'</option>';
  }
  $out.='</select></label><button type="submit">'.esc_html__('Apply','mad4b-country-switcher').'</button>';
  $out.='<span class="mad4b-country-market-status" role="status" aria-live="polite">';
  if($context['explicit']&&!$context['eligible_market'])$out.=esc_html__('Choose a supported language for this country.','mad4b-country-switcher');
  elseif($context['source']==='woo_maxmind_advisory')$out.=esc_html__('Suggested by location; please confirm.','mad4b-country-switcher');
  $out.='</span></form>';
  wp_enqueue_style('mad4b-country-switcher',plugins_url('assets/switcher.css',__FILE__),[], '0.1.0');
  wp_enqueue_script('mad4b-country-switcher',plugins_url('assets/switcher.js',__FILE__),[], '0.1.0',true);
  wp_add_inline_script('mad4b-country-switcher','window.mad4bCountryAjax='.wp_json_encode(admin_url('admin-ajax.php?action=mad4b_country_state')).';','before');
  return $out;
 }
 /** Safe client-side read prevents cached headers from showing another visitor's choice. */
 public static function ajax_state(){
  nocache_headers();$ctx=self::context();$country=$ctx['country'];
  wp_send_json_success(['contract'=>self::CONTRACT,'country'=>$country,'explicit'=>$ctx['explicit'],
   'market_profile_id'=>$ctx['selected_market']['profile_id']??null,
   'flag_url'=>$country?MAD4B_SCP_Switcher_Sources::country_flag($country):''],200);
 }
 public static function bootstrap(){
  add_shortcode('mad4b_country_switcher',[__CLASS__,'shortcode']);
  add_filter('mad4b/market_context/explicit_profile',[__CLASS__,'filter_profile'],25,2);
  add_action('admin_post_mad4b_country_choose',[__CLASS__,'post']);
  add_action('admin_post_nopriv_mad4b_country_choose',[__CLASS__,'post']);
  add_action('wp_ajax_mad4b_country_state',[__CLASS__,'ajax_state']);
  add_action('wp_ajax_nopriv_mad4b_country_state',[__CLASS__,'ajax_state']);
 }
}
add_action('plugins_loaded',['MAD4B_Country_Switcher','bootstrap'],50);
