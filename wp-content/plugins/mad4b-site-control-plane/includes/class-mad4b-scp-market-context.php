<?php
/** MAD4B Market Context v1: WooCommerce/MaxMind is a country hint, not a commercial entitlement. */
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Market_Context {
 const CONTRACT='mad4b.market-context.v1';
 const SESSION_KEY='mad4b_selected_market_v1';
 private static $booted=false;
 public static function language(){
  if(!class_exists('MAD4B_SCP_Market_Profiles'))return '';
  $visible=MAD4B_SCP_Market_Profiles::enabled_languages();
  if(isset($GLOBALS['sitepress'])&&is_object($GLOBALS['sitepress'])){
   $lang=sanitize_key((string)apply_filters('wpml_current_language',''));
   return isset($visible[$lang])?$lang:'';
  }
  $lang=sanitize_key((string)strtok(str_replace('-','_',get_locale()),'_'));
  return isset($visible[$lang])?$lang:'';
 }
 public static function country($code){
  $code=strtoupper((string)$code);
  if(!preg_match('/^[A-Z]{2}$/D',$code)||!function_exists('WC')||!WC()->countries)return '';
  return isset(WC()->countries->get_countries()[$code])?$code:'';
 }
 /** DB lookup only. Never request an external IP-service/API or persist an IP. */
 public static function woo_geo_hint($ip=null){
  if(!class_exists('WC_Geolocation')||!function_exists('WC')||!WC()->integrations)return '';
  $adapter=WC()->integrations->get_integration('maxmind_geolocation');
  if(!$adapter||!method_exists($adapter,'get_database_service'))return '';
  $service=$adapter->get_database_service();
  if(!$service||!method_exists($service,'get_database_path'))return '';
  $path=$service->get_database_path();
  if(!$path||!is_file($path)||!is_readable($path))return '';
  $ip=is_string($ip)?$ip:(string)($_SERVER['REMOTE_ADDR']??'');
  if(!filter_var($ip,FILTER_VALIDATE_IP))return '';
  // No loopback, reserved, private, proxy or CDN address guess. Proxy configuration stays Woo's responsibility.
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return '';
  $country=$service->get_iso_country_code_for_ip($ip);
  return self::country($country);
 }
 public static function status(){
  $configured=false;$db_ready=false;$last=0;
  if(function_exists('WC')&&WC()->integrations){
   $adapter=WC()->integrations->get_integration('maxmind_geolocation');
   if($adapter){$configured=(bool)$adapter->get_option('license_key');
    $service=$adapter->get_database_service();if($service){$path=$service->get_database_path();$db_ready=$path&&is_file($path)&&is_readable($path);if($db_ready)$last=(int)filemtime($path);}}
  }
  return ['contract'=>self::CONTRACT,'site_bound'=>class_exists('MAD4B_SCP_Market_Profiles')&&MAD4B_SCP_Market_Profiles::site_uuid()!=='',
   'provider'=>'woocommerce_maxmind_geolite2','woo_default_location'=>(string)get_option('woocommerce_default_customer_address',''),
   'maxmind_license_present'=>$configured,'local_geoip_db_ready'=>(bool)$db_ready,'db_last_modified'=>$last?:null,
   'enabled_languages'=>class_exists('MAD4B_SCP_Market_Profiles')?array_keys(MAD4B_SCP_Market_Profiles::enabled_languages()):[],
   'approved_markets'=>class_exists('MAD4B_SCP_Market_Profiles')?count(MAD4B_SCP_Market_Profiles::approved_list()):0,
   'geoip_role'=>'advisory_country_only','sets_billing_country'=>false,'commercial_entitlement_from_geoip'=>false];
 }
 private static function get_session_selection(){
  if(!function_exists('WC')||!WC()->session)return '';
  $s=WC()->session->get(self::SESSION_KEY,[]);
  if((!is_array($s)||($s['contract']??'')!==self::CONTRACT)&&class_exists('MAD4B_SCP_Market_Preferences')){
   $manual=MAD4B_SCP_Market_Preferences::saved();
   $profile=sanitize_key((string)($manual['market_profile_id']??''));
   $approved=$profile?MAD4B_SCP_Market_Profiles::approved($profile):[];
   if($approved)$s=['contract'=>self::CONTRACT,'profile_id'=>$profile,'revision'=>$approved['revision'],'site_uuid'=>$approved['site_uuid']];
  }
  if(!is_array($s)||($s['contract']??'')!==self::CONTRACT)return '';
  $id=sanitize_key((string)($s['profile_id']??''));$r=$id?MAD4B_SCP_Market_Profiles::approved($id):[];
  if(!$r||($s['site_uuid']??'')!==$r['site_uuid']||($s['revision']??null)!==$r['revision'])return '';
  return $id;
 }
 /** Pure decision, with explicit profile permission checked again for every request. */
 public static function evaluate($explicit_profile,$language,$geo_country=''){
  $language=sanitize_key((string)$language);$available=class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::enabled_languages():[];
  $base=['contract'=>self::CONTRACT,'language'=>$language,'selected_market'=>null,'country_hint'=>'','suggested_market'=>null,
   'selected_source'=>'none','auto_approved'=>false,'geoip_is_commercial_eligibility'=>false];
  if(!isset($available[$language]))return $base;
  $profile=$explicit_profile?MAD4B_SCP_Market_Profiles::approved(sanitize_key((string)$explicit_profile)):[];
  if($profile&&$profile['language']===$language){$base['selected_market']=$profile;$base['selected_source']='explicit_woocommerce_session';}
  $hint=self::country($geo_country);$base['country_hint']=$hint;
  if($hint)$base['suggested_market']=MAD4B_SCP_Market_Profiles::by_country($hint,$language)?:null;
  return $base;
 }
 public static function current(){
  $lang=self::language();$selected=self::get_session_selection();
  $geo=self::woo_geo_hint();
  return self::evaluate($selected,$lang,$geo);
 }
 /** Explicit human selection; unchanged Woo billing/taxes/pricing. */
 public static function choose($profile_id){
  if(!class_exists('MAD4B_SCP_Market_Profiles')||!function_exists('WC')||!WC()->session)return new WP_Error('mad4b_market_session_unavailable','WooCommerce session unavailable');
  $id=sanitize_key((string)$profile_id);$profile=MAD4B_SCP_Market_Profiles::approved($id);
  if(!$profile||$profile['language']!==self::language())return new WP_Error('mad4b_market_not_approved','Market is not approved for this visible language');
  WC()->session->set(self::SESSION_KEY,['contract'=>self::CONTRACT,'profile_id'=>$id,'revision'=>$profile['revision'],'site_uuid'=>$profile['site_uuid']]);
  if(method_exists(WC()->session,'set_customer_session_cookie'))WC()->session->set_customer_session_cookie(true);
  return ['selected_market'=>$profile,'source'=>'explicit_woocommerce_session'];
 }
 public static function clear(){
  if(function_exists('WC')&&WC()->session)WC()->session->__unset(self::SESSION_KEY);
 }
 /** Public selector can be used in a Header shortcode, but has no implicit auto-selection. */
 public static function picker(){
  if(!class_exists('MAD4B_SCP_Market_Profiles'))return '';
  $lang=self::language();$profiles=array_values(array_filter(MAD4B_SCP_Market_Profiles::approved_list(),static fn($p)=>$p['language']===$lang));
  if(!$lang||!$profiles)return '';
  wp_enqueue_script('mad4b-market-selector',plugins_url('assets/js/mad4b-market-selector.js',dirname(__DIR__).'/mad4b-site-control-plane.php'),[], '1.0.0',true);
  wp_add_inline_script('mad4b-market-selector','window.mad4bMarketSelector='.wp_json_encode(['ajaxUrl'=>admin_url('admin-ajax.php')]).';','before');
  $out='<form class="mad4b-market-picker" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $out.='<input type="hidden" name="action" value="mad4b_market_choose">'.wp_nonce_field('mad4b_market_choose','_market_nonce',true,false);
  $out.='<label for="mad4b-market-choice">'.esc_html__('Your market','mad4b-site-control-plane').'</label> <select id="mad4b-market-choice" name="market_profile_id" required>';
  $out.='<option value="">'.esc_html__('Choose country','mad4b-site-control-plane').'</option>';
  foreach($profiles as $p)$out.='<option value="'.esc_attr($p['profile_id']).'" '.''.'>'.esc_html($p['label'].' ('.$p['country'].')').'</option>';
  $out.='</select> <button type="submit">'.esc_html__('Apply','mad4b-site-control-plane').'</button><span class="mad4b-market-picker__hint" role="status" aria-live="polite"></span></form>';
  return $out;
 }
 public static function post_choose(){
  if(!isset($_POST['_market_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_market_nonce'])),'mad4b_market_choose'))wp_die('Invalid market selection',403);
  $id=sanitize_key(wp_unslash($_POST['market_profile_id']??''));$result=self::choose($id);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'', ['response'=>400]);
  $target=wp_get_referer();wp_safe_redirect($target?:home_url('/'));exit;
 }
 public static function ajax_read(){
  nocache_headers();$current=self::current();
  // No raw IP, location coordinates, Woo customer data or payment values in the response.
  $public=static function($m){return is_array($m)?array_intersect_key($m,array_flip(['profile_id','label','country','language'])):null;};
  wp_send_json_success(['contract'=>self::CONTRACT,'selected_market'=>$public($current['selected_market']),
   'country_hint'=>$current['country_hint'],'suggested_market'=>$public($current['suggested_market']),
   'selected_source'=>$current['selected_source']],200);
 }
 public static function boot(){
  if(self::$booted)return;self::$booted=true;
  add_shortcode('mad4b_market_selector',[__CLASS__,'picker']);
  add_action('admin_post_mad4b_market_choose',[__CLASS__,'post_choose']);
  add_action('admin_post_nopriv_mad4b_market_choose',[__CLASS__,'post_choose']);
  add_action('wp_ajax_mad4b_market_context_read',[__CLASS__,'ajax_read']);
  add_action('wp_ajax_nopriv_mad4b_market_context_read',[__CLASS__,'ajax_read']);
 }
}
