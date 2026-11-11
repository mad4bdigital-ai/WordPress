<?php
/** Independent tenant/language URL and presentation test; no WP, DB or network. */
define('ABSPATH','/offline/');
$GLOBALS['hooks']=[];
$GLOBALS['tenantHost']='wayfatours.com';
$GLOBALS['identityValid']=true;
$GLOBALS['foreignLogo']=false;
$GLOBALS['look']=[
 'mad4b_brand_logo_media'=>67442,
 'mad4b_brand_tagline'=>'From Someday to Somewhere',
 'mad4b_footer_legal_line'=>'Wayfa Tours. All rights reserved.',
 'footer_privacy_page'=>3,
 'footer_terms_page'=>69844
];
$GLOBALS['kitColors']=[
 ['_id'=>'primary','color'=>'#102D40','title'=>'Primary'],
 ['_id'=>'secondary','color'=>'#204C50','title'=>'Secondary'],
 ['_id'=>'accent','color'=>'#B48A46','title'=>'Accent'],
 ['_id'=>'text','color'=>'#102D40','title'=>'Text'],
];
class MAD4B_SCP_Company_Communication {
 public static function brandIdentity():array {return $GLOBALS['identityValid']
 ? ['valid'=>true,'brand_name'=>'Wayfa Tours','revision'=>3]:['valid'=>false];}
 public static function config():array {return ['traveler_reply_to'=>'bookings@wayfatours.com'];}
}
function home_url($path='/'){return 'https://'.$GLOBALS['tenantHost'].'/'.ltrim($path,'/');}
function get_option($key,$default=null){
 $v=['elementor_active_kit'=>60050,'look-and-feel'=>$GLOBALS['look'],
 'wp_page_for_privacy_policy'=>3,'woocommerce_terms_page_id'=>69844,
 'icl_sitepress_settings'=>['language_negotiation_type'=>1]];
 if(str_starts_with($key,'woocommerce_email_') && isset($GLOBALS['hooks']['pre_option_'.$key][25])) {
  return $GLOBALS['hooks']['pre_option_'.$key][25]($default);
 }
 return $v[$key]??$default;
}
function get_post($id){return in_array($id,[101,102,3,33,69844,68844,67442,60050],true)?(object)['ID'=>$id]:null;}
function get_post_type($id){
 if($id===60050)return 'elementor_library';
 if($id===67442)return 'attachment';
 if(in_array($id,[101,102],true))return 'tours-and-activities';
 return 'page';
}
function get_post_meta($id,$key,$single=true){
 return $id===60050&&$key==='_elementor_page_settings'
 ? ['system_colors'=>$GLOBALS['kitColors'],'custom_colors'=>[['title'=>'Warm bg','color'=>'#F9F7F5']]]
 : null;
}
function get_post_status($id){return get_post($id)?'publish':false;}
function get_permalink($id){
 $p=[101=>'/en/test-journey/',102=>'/es/viaje-prueba/',3=>'/privacy/',33=>'/es/privacidad/',
   69844=>'/terms/',68844=>'/es/terminos/'];
 return isset($p[$id])?home_url($p[$id]):false;
}
function get_the_title($id){return [101=>'Test Journey',102=>'Viaje de prueba'][$id]??'';}
function wp_get_attachment_image_url($id,$size){return $GLOBALS['foreignLogo']
 ? 'https://other-brand.example/logo.png':home_url('/wp-content/uploads/wayfa-logo-300.png');}
function wp_get_attachment_url($id){return home_url('/wp-content/uploads/master-logo.png');}
function get_post_mime_type($id){return 'image/png';}
function wp_attachment_is_image($id){return true;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function absint($id){return abs((int)$id);}
function esc_url_raw($v){return $v;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($e){return filter_var($e,FILTER_SANITIZE_EMAIL);}
function is_email($e){return filter_var($e,FILTER_VALIDATE_EMAIL);}
function has_filter($hook){return in_array($hook,['wpml_object_id','wpml_permalink',
 'wpml_element_language_code','wpml_translate_single_string',
 'wpml_active_languages'],true);}
function apply_filters($hook,$value,...$rest){
 if($hook==='wpml_object_id'){
  $id=$value;[$type,$fallback,$lang]=$rest;
  if($lang==='es')return [101=>102,3=>33,69844=>68844][$id]??0;
  if($lang==='ar')return 0; // no translation — must never use English link
  return $id;
 }
 if($hook==='wpml_active_languages'){
  return ['en'=>['language_code'=>'en'], 'es'=>['language_code'=>'es'],
          'ar'=>['language_code'=>'ar'],'de'=>['language_code'=>'de']];
 }
 if($hook==='wpml_default_language')return 'en';
 if($hook==='wpml_permalink'){
  $lang=$rest[0]??'en';
  return $lang==='es'?$value:($lang==='de'?'https://untrusted.invalid/de/':$value);
 }
 if($hook==='wpml_element_language_code'){
  $id=$rest[0]['element_id']??0;
  return in_array($id,[102,33,68844],true)?'es':'en';
 }
 if($hook==='wpml_translate_single_string'){
  $lang=$rest[2]??'en';
  return $lang==='es'?'De algún día a algún lugar':$value;
 }
 if(isset($GLOBALS['hooks'][$hook][25]))
     return $GLOBALS['hooks'][$hook][25]($value);
 return $value;
}
function add_filter($name,$fn,$priority=10,$args=1){$GLOBALS['hooks'][$name][$priority]=$fn;}
function remove_filter($name,$fn,$priority=10){
 if(($GLOBALS['hooks'][$name][$priority]??null)===$fn){unset($GLOBALS['hooks'][$name][$priority]);return true;}
 return false;
}
function check($ok,$title){if(!$ok){fwrite(STDERR,'FAIL: '.$title.PHP_EOL);exit(2);}}
require dirname(__DIR__).'/wp-content/mu-plugins/includes/mad4b-company-email-brand-v1.php';
$cls=MAD4B_Company_Email_Brand_V1::class;
$b=$cls::resolve();
check($b['bound'] && $b['name']==='Wayfa Tours','identity is site-owned');
check($b['primary']==='#102D40' && $b['accent']==='#B48A46','uses active kit');
check(str_contains($b['logo_url'],'wayfatours.com'),'logo scoped to same site');
check(!str_contains($b['logo_url'],'master'),'uses optimized derivative');
$e=$cls::experience('es',101);
check($e['language']==='es' && $e['tour']['title']==='Viaje de prueba','tour translated');
check(str_contains($e['tour']['url'],'/es/'),'translated tour permalink');
check(str_contains($e['links']['privacy'],'/es/privacidad'),'privacy translated');
check(str_contains($e['links']['terms'],'/es/terminos'),'terms translated');
check(str_contains($e['links']['home'],'/es/'),'homepage translated');
check($e['brand']['tagline']==='De algún día a algún lugar','translated tagline from WPML');
$brandedHeader=$cls::renderScoped($e['brand'],fn()=>
 apply_filters('woocommerce_email_header_image_url',home_url('/')));
check($brandedHeader===home_url('/es/'),'Woo logo link matches traveler Spanish');
check(!isset($GLOBALS['hooks']['woocommerce_email_header_image_url'][25]),'Woo logo link hook cleared');

$ar=$cls::experience('ar',101);
check(empty($ar['tour']) && empty($ar['links']['privacy']),'no wrong-language fallback');
check($ar['brand']['tagline']==='','untranslated marketing slogan hidden');
$de=$cls::experience('de',0);
check($de['links']['home']===home_url('/de/'),'active WPML directory resolves without request context');
check($cls::experience('de',101)['tour']===[],'untrusted translated link rejected');
$GLOBALS['foreignLogo']=true;
check($cls::resolve()['logo_url']==='','foreign media never shown');
$GLOBALS['foreignLogo']=false;
$GLOBALS['identityValid']=false;
check(!$cls::experience('en',101)['brand']['bound'],'unbound site blocked');
$GLOBALS['identityValid']=true;
$inside=$cls::renderScoped($cls::resolve(),function(){
 return get_option('woocommerce_email_base_color').'|'.
         get_option('woocommerce_email_header_image');
});
check(str_starts_with($inside,'#102D40|') && str_contains($inside,'wayfatours.com'),
 'Woo temporary palette and logo');
check(!isset($GLOBALS['hooks']['pre_option_woocommerce_email_base_color'][25]),
 'presentation hooks removed');
try {$cls::renderScoped($cls::resolve(),function(){throw new RuntimeException('intentional');});}
catch(RuntimeException $ex){}
check(!isset($GLOBALS['hooks']['pre_option_woocommerce_email_header_image'][25]),
 'presentation hooks removed after exception');
echo json_encode(['state'=>'PASS','cases'=>18,'network_calls'=>0,'database_writes'=>0,
 'transactional_emails_sent'=>0]).PHP_EOL;
