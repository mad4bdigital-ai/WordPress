<?php
/** Company-owned visual identity. Display context only; never booking authority. */
defined('ABSPATH') || exit;
if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)return;
function company_brand_media_id($value):int {
 if(is_array($value))$value=$value['id']??$value['attachment_id']??$value['url']??0;
 if(is_scalar($value)&&ctype_digit((string)$value)){
  $id=(int)$value;return get_post_type($id)==='attachment'?$id:0;
 }
 if(!is_string($value)||strlen($value)>2048)return 0;
 $path=wp_parse_url($value,PHP_URL_PATH);
 if(!is_string($path)||!preg_match('~/wp-content/uploads/(.+)$~D',$path,$m)||str_contains($m[1],'..'))return 0;
 $uploads=wp_upload_dir();
 return (int)attachment_url_to_postid(trailingslashit($uploads['baseurl']).$m[1]);
}
function company_brand_media_url($value):string {
 $id=company_brand_media_id($value);if(!$id)return '';
 $mime=(string)get_post_mime_type($id);
 if(!in_array($mime,['image/png','image/jpeg','image/webp','image/x-icon','image/vnd.microsoft.icon'],true))return '';
 $url=wp_get_attachment_url($id);return is_string($url)?esc_url_raw($url):'';
}
function company_brand_preview_parameters(string $profile='wayfa',int $until=0):array {
 if($profile!=='wayfa')return [];
 $until=$until?:time()+7*DAY_IN_SECONDS;
 return ['company_brand_preview'=>$profile,'company_brand_until'=>(string)$until,
  'company_brand_token'=>hash_hmac('sha256',$profile.'|'.$until,wp_salt('nonce'))];
}
function company_brand_preview_parameters_valid(array $params):bool {
 if(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED')||!MAD4B_COMMERCE_RUNTIME_ENABLED)return false;
 foreach(['company_brand_preview','company_brand_until','company_brand_token']as$key)if(!is_string($params[$key]??null))return false;
 if($params['company_brand_preview']!=='wayfa'||!ctype_digit($params['company_brand_until'])||!preg_match('/^[a-f0-9]{64}$/D',$params['company_brand_token']))return false;
 $until=(int)$params['company_brand_until'];if($until<time()||$until>time()+8*DAY_IN_SECONDS)return false;
 return hash_equals(company_brand_preview_parameters('wayfa',$until)['company_brand_token'],$params['company_brand_token']);
}
function company_brand_preview_active():bool {
 if(!wp_doing_ajax())return company_brand_preview_parameters_valid(wp_unslash($_GET));
 if(($_REQUEST['action']??'')!=='jet_smart_filters')return false;
 $ref=wp_get_referer();$parts=is_string($ref)?wp_parse_url($ref):null;$home=wp_parse_url(home_url('/'));
 if(!is_array($parts)||!is_array($home)||!in_array($parts['scheme']??'',['http','https'],true)||isset($parts['user'])||isset($parts['pass'])
  ||!company_brand_same_origin($parts,$home))return false;
 $params=[];parse_str((string)($parts['query']??''),$params);return company_brand_preview_parameters_valid($params);
}
final class Company_Brand_Context_v1 {
 public static function from_options(array $o):array {
  $requested=$o['company_brand_profile']??'inherit';
  $profile=in_array($requested,['inherit','wayfa','custom'],true)?$requested:'inherit';
  $palettes=[
   'midnight'=>['#102D40','#204C50','#B48A46','#F6F2E9'],
   'mineral'=>['#204C50','#102D40','#D4B67C','#EEEADF'],
   'terracotta'=>['#853E2F','#102D40','#E0B97D','#F6E8D7'],
   'azure'=>['#176B83','#102D40','#F0C783','#EAF3EF'],
   'ivory'=>['#102D40','#204C50','#B48A46','#F6F2E9'],
   'ink'=>['#152027','#204C50','#C2C4BE','#F3F2EC']];
  $requested_palette=$o['company_brand_palette']??'midnight';
  $palette=is_string($requested_palette)&&array_key_exists($requested_palette,$palettes)?$requested_palette:'midnight';
  [$primary,$secondary,$accent,$surface]=$palettes[$palette];
  $colors=['primary'=>$primary,'secondary'=>$secondary,'accent'=>$accent,'text'=>$primary,'muted'=>'#52636D','surface'=>$surface,'border'=>'#DCDDD7','focus'=>$primary];
  $kit_settings=[];
  if($profile==='inherit'||($profile==='custom'&&empty($o['company_brand_palette']))){
   $kit_settings=get_post_meta((int)get_option('elementor_active_kit'),'_elementor_page_settings',true);$kit_settings=is_array($kit_settings)?$kit_settings:[];
   foreach((array)($kit_settings['system_colors']??[])as$row){$role=$row['_id']??'';$value=$row['color']??'';if(isset($colors[$role])&&is_string($value)&&preg_match('/^#[a-f0-9]{6}$/iD',$value))$colors[$role]=strtoupper($value);}
   $colors['surface']='#FFFFFF';
  }
  foreach($colors as$key=>$fallback){$value=$o['company_brand_color_'.$key]??'';if(is_string($value)&&preg_match('/^#[a-f0-9]{6}$/iD',$value))$colors[$key]=strtoupper($value);}
  $fonts=['heading'=>$profile==='wayfa'?'Playfair Display':'Nunito','body'=>'Nunito','arabic'=>'Cairo'];
  foreach((array)($kit_settings['system_typography']??[])as$row){$key=['primary'=>'heading','text'=>'body'][$row['_id']??'']??'';if($key&&is_string($row['typography_font_family']??null)&&preg_match('/^[\pL\pN -]{1,64}$/uD',$row['typography_font_family']))$fonts[$key]=$row['typography_font_family'];}
  foreach($fonts as$key=>$fallback){$value=$o['company_brand_font_'.$key]??'';if(is_string($value)&&preg_match('/^[\pL\pN -]{1,64}$/uD',$value))$fonts[$key]=$value;}
  $name=$profile==='wayfa'?'WAYFA Tours':(string)get_bloginfo('name');
  if(is_string($o['company_brand_name']??null)&&trim($o['company_brand_name'])!=='')$name=sanitize_text_field($o['company_brand_name']);
  $radii=[];foreach(['button'=>24,'card'=>18,'input'=>10]as$key=>$default){$v=$o['company_brand_radius_'.$key]??null;$radii[$key]=is_numeric($v)?max(0,min(32,(float)$v)):$default;}
  $bundle=get_option('company_brand_asset_bundles',[]);$bundle=is_array($bundle)?($bundle[$profile]??[]):[];$bundle=is_array($bundle)?$bundle:[];
  $media=[];$ids=[];
  foreach(['logo_default','logo_inverse','logo_compact','logo_mobile','favicon','pattern']as$key){
   $option='company_brand_'.($key==='pattern'?'pattern_media':$key);
   $id=company_brand_media_id($o[$option]??0);if(!$id)$id=company_brand_media_id($bundle[$key]??0);
   $ids[$key]=$id;$media[$key]=company_brand_media_url($id);
  }
  if($profile==='inherit'){
   $legacy=get_option('contact-and-help-info',[]);$legacy=is_array($legacy)?$legacy:[];
   $legacy_logo=company_brand_media_id($legacy['site_icon_280x100_site_header']??0);$legacy_icon=company_brand_media_id($legacy['site_icon_512x512']??0);
   foreach(['logo_default'=>$legacy_logo,'logo_inverse'=>$legacy_logo,'logo_compact'=>$legacy_icon?:$legacy_logo,'logo_mobile'=>$legacy_icon?:$legacy_logo,'favicon'=>$legacy_icon]as$key=>$fallback)if(!$ids[$key]&&$fallback){$ids[$key]=$fallback;$media[$key]=company_brand_media_url($fallback);}
  }
  $font_urls=[];foreach(['heading_regular','heading_italic']as$key){
   $id=company_brand_media_id($bundle[$key]??0);$mime=$id?(string)get_post_mime_type($id):'';
   $font_urls[$key]=in_array($mime,['font/woff2','font/ttf','application/x-font-ttf','application/font-sfnt'],true)?(string)wp_get_attachment_url($id):'';
  }
  return ['enabled'=>defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED&&$profile!=='inherit','profile'=>$profile,'name'=>$name,'palette'=>$palette,
   'revision'=>sanitize_text_field(is_string($o['company_brand_revision']??null)?$o['company_brand_revision']:'1'),'colors'=>$colors,'fonts'=>$fonts,'media'=>$media,'media_ids'=>$ids,'font_urls'=>$font_urls,'font_face_family'=>$profile==='wayfa'?'Playfair Display':'','radii'=>$radii,
   'heading_scale'=>is_numeric($o['company_brand_heading_scale']??null)?max(.85,min(1.2,(float)$o['company_brand_heading_scale'])):1,
   'body_size'=>is_numeric($o['company_brand_body_size']??null)?max(14,min(20,(int)$o['company_brand_body_size'])):16,
   'density'=>in_array($o['company_brand_density']??'comfortable',['balanced','comfortable','compact'],true)?($o['company_brand_density']??'comfortable'):'comfortable',
   'motion'=>($o['company_brand_motion']??'calm')==='native'?'native':'calm','decoration'=>($o['company_brand_decoration']??'subtle')==='none'?'none':'subtle'];
 }
}
function company_brand_context():array {
 static $resolved=[],$publicMemo=[];
 // Every Elementor element and JetEngine listing requests the same brand
 // context. Re-serializing the entire Look & Feel option and media bundles
 // during every render recursively invokes WPML / option normalization.
 // Request-local memoization is safe only for public read-only Staging GET.
 // Signed previews, editors, AJAX, REST and WP-CLI retain native logic.
 $eligible=wp_get_environment_type()==='staging'
    && ($_SERVER['REQUEST_METHOD']??'')==='GET'
    && !is_admin() && !is_user_logged_in() && !wp_doing_ajax()
    && !(defined('REST_REQUEST') && REST_REQUEST)
    && !(defined('WP_CLI') && WP_CLI)
    && !isset($_GET['company_brand_preview'])
    && !isset($_GET['company_brand_until'])
    && !isset($_GET['company_brand_token']);
 if($eligible){
    // Safe A/B canary. After browser parity, the company-local option
    // enables anonymous Staging GET by default; ?mad4b_brand_memo=0
    // explicitly retains the original source path for regression checks.
    $eligible=array_key_exists('mad4b_brand_memo',$_GET)
       ? (string)$_GET['mad4b_brand_memo']==='1'
       : get_option('mad4b_brand_request_memo_v1','on')==='on';
 }
 $lang=$eligible?(string)apply_filters('wpml_current_language','en'):'';
 $memoKey=$eligible?get_current_blog_id().'|'.$lang.'|'.(int)($GLOBALS['mad4b_brand_context_epoch']??0):'';
 if($eligible && isset($publicMemo[$memoKey])){
    if(($_SERVER['HTTP_X_MAD4B_BRAND_PROFILE']??'')==='v1')
        $GLOBALS['mad4b_brand_context_memo_hits']=(int)($GLOBALS['mad4b_brand_context_memo_hits']??0)+1;
    return $publicMemo[$memoKey];
 }
 $options=get_option('look-and-feel',[]);$options=is_array($options)?$options:[];
 $preview=company_brand_preview_active();if($preview)$options['company_brand_profile']='wayfa';
 // Existing canonical hash remains authoritative for all other contexts,
 // including authorized company-brand previews and WPML live editor renders.
 $key=hash('sha256',wp_json_encode([$options,$preview,get_option('company_brand_asset_bundles',[]),
   $eligible?$lang:(string)apply_filters('wpml_current_language','en'),get_option('home')]));
 if(isset($resolved[$key]))$context=$resolved[$key];
 else {
    $context=Company_Brand_Context_v1::from_options($options);
    $context['preview']=$preview;
    $resolved[$key]=$context;
 }
 if($eligible){
    // Keep small and bounded even if a page changes language during render.
    if(count($publicMemo)>16) $publicMemo=[];
    $publicMemo[$memoKey]=$context;
    if(($_SERVER['HTTP_X_MAD4B_BRAND_PROFILE']??'')==='v1')
        $GLOBALS['mad4b_brand_context_memo_builds']=(int)($GLOBALS['mad4b_brand_context_memo_builds']??0)+1;
 }
 return $context;
}
// A rare in-request settings update must invalidate public request memos.
// Does not touch persistent caches, WPML settings or any company policy.
foreach(['updated_option','added_option','deleted_option'] as $mad4bBrandOptionHook){
 add_action($mad4bBrandOptionHook,static function($name){
    if(in_array((string)$name,['look-and-feel','company_brand_asset_bundles',
       'contact-and-help-info','elementor_active_kit','home','blogname'],true))
      $GLOBALS['mad4b_brand_context_epoch']=(int)($GLOBALS['mad4b_brand_context_epoch']??0)+1;
 },55,1);
}
add_action('updated_post_meta',static function($metaId,$postId,$key){
 if((string)$key==='_elementor_page_settings' &&
    (int)$postId===(int)get_option('elementor_active_kit'))
    $GLOBALS['mad4b_brand_context_epoch']=(int)($GLOBALS['mad4b_brand_context_epoch']??0)+1;
},55,3);
add_action('shutdown',static function(){
 if(wp_get_environment_type()!=='staging'
   || ($_SERVER['HTTP_X_MAD4B_BRAND_PROFILE']??'')!=='v1'
   || is_user_logged_in())return;
 error_log('MAD4B_BRAND_CONTEXT_PROFILE builds='.(int)($GLOBALS['mad4b_brand_context_memo_builds']??0)
   .' memo_hits='.(int)($GLOBALS['mad4b_brand_context_memo_hits']??0));
},9998);

// Keep authored JetEngine logo bindings, changing only their company-owned source.
add_filter('option_contact-and-help-info',static function($value){
 if(!is_array($value)||(is_admin()&&!wp_doing_ajax()))return $value;
 $raw=get_option('look-and-feel',[]);if((!is_array($raw)||($raw['company_brand_profile']??'inherit')==='inherit')&&!company_brand_preview_active())return $value;
 $c=company_brand_context();if(!$c['enabled'])return $value;
 $logo=$c['media']['logo_default'];if($logo!=='')$value['site_icon_280x100_site_header']=$logo;
 if($c['media']['favicon']!=='')$value['site_icon_512x512']=$c['media']['favicon'];
 return $value;
},50);
add_filter('get_site_icon_url',static function($url){$c=company_brand_context();return $c['enabled']&&$c['media']['favicon']!==''?$c['media']['favicon']:$url;},50);
add_filter('company_pattern_options_for_context',static function($options){
 $c=company_brand_context();if(!$c['enabled'])return $options;
 $options['company_pattern_accent']=$c['colors']['accent'];
 if($c['decoration']==='none')$options['company_pattern_preset']='none';
 elseif($c['media_ids']['pattern']){$options['company_pattern_preset']='custom';$options['company_pattern_media']=$c['media_ids']['pattern'];$options['company_pattern_strength']='light';$options['company_pattern_mobile']='reduce';}
 return $options;
});
add_action('template_redirect',static function(){if(company_brand_preview_active()){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}},1);
// Elementor fragment caches vary by identity, asset revision and language.
add_filter('elementor/element_cache/unique_id',static function($key){
 $c=company_brand_context();if(!$c['enabled'])return $key;
 return (string)$key.'-brand-'.substr(hash('sha256',wp_json_encode(['1.0.1',$c,(string)apply_filters('wpml_current_language','en'),company_brand_preview_request_parameters()])),0,20);
});
function company_brand_preview_link(string $url):string {
 if(!company_brand_preview_active())return $url;
 $parts=wp_parse_url($url);$home=wp_parse_url(home_url('/'));
 if(!is_array($parts)||!is_array($home)||!company_brand_same_origin($parts,$home)||isset($parts['user'])||isset($parts['pass']))return $url;
 $path=(string)($parts['path']??'/');if(preg_match('~/(wp-admin|wp-json|wp-login\.php|cart|checkout|my-account)(/|$)~',$path))return $url;
 $params=wp_unslash($_GET);if(wp_doing_ajax()){$params=[];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$params);}
 if(!company_brand_preview_parameters_valid($params))return $url;
 return add_query_arg(array_intersect_key($params,array_flip(['company_brand_preview','company_brand_until','company_brand_token'])),$url);
}
foreach(['post_type_link','page_link','term_link']as$hook)add_filter($hook,'company_brand_preview_link',90);
add_filter('nav_menu_link_attributes',static function($attrs){if(isset($attrs['href'])&&is_string($attrs['href']))$attrs['href']=company_brand_preview_link($attrs['href']);return $attrs;},90);

function company_brand_preview_request_parameters():array {
 $params=wp_unslash($_GET);
 if(wp_doing_ajax()){$params=[];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$params);}
 return company_brand_preview_parameters_valid($params)?array_intersect_key($params,array_flip(['company_brand_preview','company_brand_until','company_brand_token'])):[];
}
add_filter('wp_get_attachment_image_attributes',static function($attrs,$attachment){
 $c=company_brand_context();if(!$c['enabled']||!in_array((int)$attachment->ID,array_filter([$c['media_ids']['logo_default'],$c['media_ids']['logo_inverse'],$c['media_ids']['logo_mobile'],$c['media_ids']['logo_compact']]),true))return $attrs;
 $attrs['loading']='eager';$attrs['fetchpriority']='high';$attrs['data-no-lazy']='1';return $attrs;
},50,2);
// Project the native video settings before Elementor builds its active settings.
add_filter('elementor/frontend/builder_content_data',static function($nodes){
 $c=company_brand_context();if(!$c['enabled']||$c['motion']!=='calm'||!is_array($nodes))return $nodes;
 $walk=function($items)use(&$walk){foreach($items as&$node){$settings=&$node['settings'];
  if(is_array($settings)&&($settings['company_brand_role']??'')==='hero'&&($settings['background_background']??'')==='video'){
   $url=company_brand_media_url($settings['background_image']??0);if(!$url)$url=company_brand_media_url($settings['background_video_fallback']??0);
   if($url){$settings['background_background']='classic';$settings['background_video_link']='';$settings['company_brand_poster_url']=$url;}
  }
  unset($settings);if(!empty($node['elements']))$node['elements']=$walk($node['elements']);
 }unset($node);return $items;};return $walk($nodes);
},90);
add_action('elementor/frontend/container/before_render',static function($element){
 $c=company_brand_context();if(!$c['enabled']||$c['motion']!=='calm')return;
 $url=$element->get_settings('company_brand_poster_url');
 if(is_string($url)&&$url!==''&&$element->get_settings('company_brand_role')==='hero')$element->add_render_attribute('_wrapper','style','background-image:url("'.esc_url($url).'");background-size:cover;background-position:center;');
},5);
function company_brand_same_origin(array $a,array $b):bool {
 $as=strtolower((string)($a['scheme']??''));$bs=strtolower((string)($b['scheme']??''));
 if(!in_array($as,['http','https'],true)||$as!==$bs||strtolower((string)($a['host']??''))!==strtolower((string)($b['host']??'')))return false;
 return (int)($a['port']??($as==='https'?443:80))===(int)($b['port']??($bs==='https'?443:80));
}
/** Normalize legacy URL-formatted option fields without rewriting stored data. */
function company_brand_normalize_option_media(array $values):array {
 static $cache=[];$key=hash('sha256',wp_json_encode([$values,get_option('home')]));if(isset($cache[$key]))return $cache[$key];
 $fields=['home_page_header_image','tour_search_header_image','continents_archive_header_image','countries_archive_header_image','cities_archive_header_image','location_archive_header_image','attraction_archive_header_image','tours_archive_header_image','properties_archive_header_image','units_archive_header_image','transportations_archive_header_image','transportations_companies_archive_header_image','dmcs_archive_header_image','drivers_archive_header_image','jops_archive_header_image','guides_archive_header_image','company_about_customization','review_hero_image'];
 foreach($fields as$field){if(!isset($values[$field]))continue;$url=company_brand_media_url($values[$field]);if($url!=='')$values[$field]=$url;}
 $gallery=$values['review_gellery_slider']??null;
 if(is_string($gallery)&&strlen($gallery)<65536){$parts=explode(',',$gallery);foreach($parts as&$part){$url=company_brand_media_url(trim($part));if($url!=='')$part=$url;}unset($part);$values['review_gellery_slider']=implode(',',$parts);}
 $cache[$key]=$values;return $values;
}
add_filter('option_look-and-feel',static function($value){return is_array($value)?company_brand_normalize_option_media($value):$value;},15);
