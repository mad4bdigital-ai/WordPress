<?php
/** MAD4B visitor language/currency preferences over WooCommerce Multilingual and WPML. */
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Market_Preferences {
 const CONTRACT='mad4b.market-preferences.v1';
 const SESSION='mad4b_market_preferences_v1';
 const COOKIE='mad4b_market_preferences_v1';
 private static $booted=false;
 private static $applying=false;
 public static function currencies(){
  $options=get_option('_wcml_settings',[]);
  $c=array_keys((array)($options['currency_options']??[]));
  $base=(string)get_option('woocommerce_currency','USD');
  if(!$c)$c=[$base];
  return array_values(array_unique(array_filter($c,static fn($x)=>is_string($x)&&preg_match('/^[A-Z]{3}$/D',$x))));
 }
 public static function defaults($country){
  $country=MAD4B_SCP_Market_Context::country($country);
  $language_preferred=['EG'=>'ar','US'=>'en','GB'=>'en','DE'=>'de','ES'=>'es','IT'=>'it','FR'=>'fr','SA'=>'ar'];
  $currency_preferred=['EG'=>'EGP','US'=>'USD','GB'=>'GBP','DE'=>'EUR','ES'=>'EUR','IT'=>'EUR','FR'=>'EUR','SA'=>'SAR'];
  $active=MAD4B_SCP_Market_Profiles::enabled_languages();
  $lang=$language_preferred[$country]??'en';
  // Unserved languages or unapproved territories cannot create a targetable Market Profile.
  if(!MAD4B_SCP_Market_Profiles::is_targetable($country,$lang)){
   $fallback='en';$lang=isset($active[$fallback])?$fallback:(array_key_first($active)?:'');
  }
  $base=(string)get_option('woocommerce_currency','USD');$available=self::currencies();
  $currency=$currency_preferred[$country]??$base;
  if(!in_array($currency,$available,true))$currency=in_array($base,$available,true)?$base:($available[0]??'');
  $market=$country&&$lang?MAD4B_SCP_Market_Profiles::by_country($country,$lang):[];
  // SA is explicitly excluded despite its potentially public WPML language and unavailable SAR currency.
  if(!$country||!MAD4B_SCP_Market_Profiles::is_targetable($country,$lang))$market=[];
  return ['country_hint'=>$country,'language'=>$lang,'currency'=>$currency,
   'market_profile_id'=>$market['profile_id']??'','market_approved'=>(bool)$market,
   'source'=>'woo_maxmind_initial_default','commercial_entitlement'=>false];
 }
 private static function valid_preferences($p){
  if(!is_array($p)||($p['contract']??'')!==self::CONTRACT||($p['site_uuid']??'')!==MAD4B_SCP_Market_Profiles::site_uuid())return [];
  $lang=sanitize_key((string)($p['language']??''));$currency=(string)($p['currency']??'');
  if(!isset(MAD4B_SCP_Market_Profiles::targeted_languages()[$lang])||!in_array($currency,self::currencies(),true))return [];
  $profile=sanitize_key((string)($p['market_profile_id']??''));
  if($profile){$approved=MAD4B_SCP_Market_Profiles::approved($profile);
   if(!$approved||$approved['language']!==$lang||($p['revision']??null)!==$approved['revision'])$profile='';
  }
  return ['language'=>$lang,'currency'=>$currency,'market_profile_id'=>$profile,'source'=>'manual_saved'];
 }
 public static function saved(){
  $v=function_exists('WC')&&WC()->session?WC()->session->get(self::SESSION,null):null;
  $decoded=self::valid_preferences($v);if($decoded)return $decoded;
  $cookie=(string)($_COOKIE[self::COOKIE]??'');
  if(!$cookie||strlen($cookie)>1600||!str_contains($cookie,'.'))return [];
  [$payload,$sig]=explode('.',$cookie,2);
  if(!hash_equals(hash_hmac('sha256',$payload,wp_salt('auth')),$sig))return [];
  $raw=base64_decode(strtr($payload,'-_','+/'),true);if(!is_string($raw))return [];
  return self::valid_preferences(json_decode($raw,true));
 }
 public static function set($language,$currency,$market_id=''){
  $lang=sanitize_key((string)$language);$currency=strtoupper((string)$currency);$id=sanitize_key((string)$market_id);
  if(!isset(MAD4B_SCP_Market_Profiles::targeted_languages()[$lang])||!in_array($currency,self::currencies(),true))return new WP_Error('mad4b_preferences_invalid','Unsupported language or currency');
  $approved=$id?MAD4B_SCP_Market_Profiles::approved($id):[];
  if($id&&(!$approved||$approved['language']!==$lang))return new WP_Error('mad4b_preferences_market_rejected','Market not approved for language');
  $record=['contract'=>self::CONTRACT,'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),
   'language'=>$lang,'currency'=>$currency,'market_profile_id'=>$id,'revision'=>$approved['revision']??0,
   'saved_at'=>time()];
  if(function_exists('WC')&&WC()->session){
   WC()->session->set(self::SESSION,$record);
   if($approved)WC()->session->set(MAD4B_SCP_Market_Context::SESSION_KEY,[
    'contract'=>MAD4B_SCP_Market_Context::CONTRACT,'profile_id'=>$id,
    'revision'=>$approved['revision'],'site_uuid'=>$approved['site_uuid']]);
   else WC()->session->__unset(MAD4B_SCP_Market_Context::SESSION_KEY);
   if(method_exists(WC()->session,'set_customer_session_cookie'))WC()->session->set_customer_session_cookie(true);
  }
  if(!headers_sent()){
   $b64=rtrim(strtr(base64_encode(wp_json_encode($record)),'+/','-_'),'=');
   setcookie(self::COOKIE,$b64.'.'.hash_hmac('sha256',$b64,wp_salt('auth')),
    ['expires'=>time()+90*DAY_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
  }
  if(is_user_logged_in())update_user_meta(get_current_user_id(),self::SESSION,$record);
  return ['source'=>'manual_saved','language'=>$lang,'currency'=>$currency,'market_profile_id'=>$id];
 }
 public static function effective($country_hint=''){
  $manual=self::saved();
  if($manual)return $manual+['country_hint'=>$country_hint];
  return self::defaults($country_hint?:MAD4B_SCP_Market_Context::woo_geo_hint());
 }
 public static function apply_currency(){
  if(is_admin()&&!wp_doing_ajax())return;
  if(self::$applying)return;
  $multi=$GLOBALS['woocommerce_wpml']->multi_currency??null;
  if(!$multi||!method_exists($multi,'get_client_currency')||!method_exists($multi,'set_client_currency'))return;
  $manual=self::saved();$geo='';
  if(!$manual){$geo=MAD4B_SCP_Market_Context::woo_geo_hint();if(!$geo)return;}
  $prefs=$manual?:self::defaults($geo);$desired=$prefs['currency']??'';
  if(!$desired||!in_array($desired,self::currencies(),true))return;
  if($multi->get_client_currency()===$desired)return;
  // No currency switches during an active cart/checkout; existing order prices win.
  if(function_exists('WC')&&WC()->cart&&WC()->cart->get_cart_contents_count()>0)return;
  self::$applying=true;
  try{$multi->set_client_currency($desired);}finally{self::$applying=false;}
 }
 public static function native_wcml_manual($new_currency){
  if(self::$applying)return;
  $selected=self::saved();$lang=$selected['language']??MAD4B_SCP_Market_Context::language();
  if(!$lang||!in_array((string)$new_currency,self::currencies(),true))return;
  self::set($lang,$new_currency,$selected['market_profile_id']??'');
 }
 public static function manual_post(){
  if(!isset($_POST['_preferences_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_preferences_nonce'])),'mad4b_save_market_preferences'))wp_die('Invalid preference request',403);
  $lang=sanitize_key(wp_unslash($_POST['language']??''));$cur=sanitize_key(wp_unslash($_POST['currency']??''));
  $market=sanitize_key(wp_unslash($_POST['market_profile_id']??''));
  $result=self::set($lang,strtoupper($cur),$market);
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'', ['response'=>400]);
  self::apply_currency();
  $target=wp_get_referer()?:home_url('/');
  $target=apply_filters('wpml_permalink',$target,$lang);
  wp_safe_redirect($target?:home_url('/'));exit;
 }
 public static function selector(){
  $langs=MAD4B_SCP_Market_Profiles::targeted_languages();$currencies=self::currencies();
  $saved=self::saved();$prefs=$saved?:self::defaults('');
  $out='<form class="mad4b-market-preferences" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  $out.='<input type="hidden" name="action" value="mad4b_save_market_preferences">'.wp_nonce_field('mad4b_save_market_preferences','_preferences_nonce',true,false);
  $out.='<label>Language <select name="language">';foreach($langs as $lang=>$name)$out.='<option value="'.esc_attr($lang).'" '.selected($prefs['language'],$lang,false).'>'.esc_html($name).'</option>'; $out.='</select></label> ';
  $out.='<label>Currency <select name="currency">';foreach($currencies as $cur)$out.='<option value="'.esc_attr($cur).'" '.selected($prefs['currency'],$cur,false).'>'.esc_html($cur).'</option>'; $out.='</select></label> ';
  $out.='<label>Market <select name="market_profile_id"><option value="">No commercial market selected</option>';
  foreach(MAD4B_SCP_Market_Profiles::approved_list() as $profile)$out.='<option value="'.esc_attr($profile['profile_id']).'" '.selected($prefs['market_profile_id']??'',$profile['profile_id'],false).'>'.esc_html($profile['label'].' ('.$profile['country'].'/'.$profile['language'].')').'</option>';
  $out.='</select></label> <button type="submit">Save preferences</button></form>';
  return $out;
 }
 public static function initial_locale_url($country,$lang=''){
  if(self::saved())return '';
  $x=self::defaults($country);
  if(!$x['market_approved']||$x['language']===($lang?:MAD4B_SCP_Market_Context::language()))return '';
  $url=apply_filters('wpml_permalink',home_url('/'),$x['language']);
  $a=wp_parse_url($url);$b=wp_parse_url(home_url('/'));
  return is_array($a)&&($a['host']??'')===($b['host']??'')?$url:'';
 }
 public static function initial_locale_redirect(){
  if(is_admin()||wp_doing_ajax()||!is_front_page()||self::saved()||isset($_COOKIE['mad4b_locale_seen']))return;
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||!empty($_SERVER['QUERY_STRING']))return;
  if((string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!==(string)wp_parse_url(home_url('/'),PHP_URL_PATH))return;
  $geo=MAD4B_SCP_Market_Context::woo_geo_hint();if(!$geo)return;
  $dest=self::initial_locale_url($geo);if(!$dest)return;
  if(!headers_sent())setcookie('mad4b_locale_seen','1',['expires'=>time()+90*DAY_IN_SECONDS,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
  wp_safe_redirect($dest,302);exit;
 }
 public static function status(){return ['contract'=>self::CONTRACT,'available_currencies'=>self::currencies(),'manual_saved'=>(bool)self::saved(),'geoip_db_ready'=>MAD4B_SCP_Market_Context::status()['local_geoip_db_ready'],'commercial_auto_approval'=>false];}
 public static function boot(){if(self::$booted)return;self::$booted=true;
  add_shortcode('mad4b_market_preferences',[__CLASS__,'selector']);
  add_action('admin_post_mad4b_save_market_preferences',[__CLASS__,'manual_post']);
  add_action('admin_post_nopriv_mad4b_save_market_preferences',[__CLASS__,'manual_post']);
  add_action('wp',[__CLASS__,'apply_currency'],9);
  add_action('template_redirect',[__CLASS__,'initial_locale_redirect'],7);
  add_action('wcml_switch_currency',[__CLASS__,'native_wcml_manual'],10,1);
 }
}
