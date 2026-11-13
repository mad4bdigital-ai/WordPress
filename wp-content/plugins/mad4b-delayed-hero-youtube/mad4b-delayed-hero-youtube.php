<?php
/**
 * Plugin Name: MAD4B · Delayed YouTube Hero
 * Description: Local branded poster, then device-specific muted YouTube autoplay. No global LiteSpeed JS delay.
 * Version: 0.5.3
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;
final class MAD4B_Delayed_Hero_YT {
 const HERO='e991bc9';
 private static function host():string {
  return strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
 }
 public static function authorized():bool {
  $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
  $site_uuid=is_array($p)?(string)($p['site_uuid']??''):'';
  return in_array(wp_get_environment_type(),['staging','production'],true)
   && (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$site_uuid)
   && self::host()!==''
   && get_option('mad4b_hero_poster_runtime_enabled',true)!==false;
 }
 public static function active_page():bool {
  $home=is_front_page();
  if(!$home&&is_page()){
   $front=(int)get_option('page_on_front',0);
   $current=(int)get_queried_object_id();
   $language=(string)apply_filters('wpml_current_language',null);
   $translated=$front?(int)apply_filters('wpml_object_id',$front,'page',false,$language):0;
   $home=$translated>0&&$current===$translated;
  }
  return self::authorized()&&!is_admin()&&!wp_doing_ajax()&&!is_preview()&&$home;
 }
 public static function opt():array {
  $a=get_option('look-and-feel',[]);return is_array($a)?$a:[];
 }
 public static function enabled():bool {
  return (string)(self::opt()['company_brand_hero_video_enabled']??'on')!=='off';
 }
 public static function custom_video_url():string {
  $v=self::opt()['company_brand_hero_video_link']??'';
  return is_string($v)?trim($v):'';
 }
 public static function mode():string {
  // Explicit off always wins, including when Elementor native playback is selected.
  if(!self::enabled())return 'poster_only';
  $v=self::opt()['company_brand_hero_video_strategy']??'delayed_auto';
  return in_array($v,['click_to_play','delayed_auto','poster_only','native'],true)?$v:'delayed_auto';
 }
 /**
  * JetEngine native options-page controls: persisted under look-and-feel.
  * Keep this in the hero addon so company provisioning and WPML use exactly
  * the same field definitions without copying Elementor templates.
  */
 public static function fields($fields,$page) {
  if(!is_array($fields)||($page->slug??'')!=='look-and-feel')return $fields;
  $has=[];$anchor=-1;$existing_hero_tab=false;
  foreach($fields as $i=>$row)if(is_array($row)&&!empty($row['name'])){
   $name=(string)$row['name'];$has[$name]=true;
   if($name==='company_brand_hero_video_strategy')$anchor=(int)$i;
   if($name==='company_brand_tab_hero_video')$existing_hero_tab=true;
  }
  $extra=[];
  if(!isset($has['company_brand_hero_video_enabled'])){
   $extra[]=[
    'name'=>'company_brand_hero_video_enabled',
    'title'=>'Enable homepage hero video',
    'type'=>'select','object_type'=>'field','width'=>'50%',
    'options_source'=>'manual',
    'options'=>[['key'=>'on','value'=>'Enabled (automatic)'],['key'=>'off','value'=>'Disabled (poster only)']],
    'default_val'=>'on',
    'description'=>'Off prevents Elementor/YouTube video loading. Keeps the local hero poster and homepage content.'
   ];
  }
  if(!isset($has['company_brand_hero_video_link'])){
   $extra[]=[
    'name'=>'company_brand_hero_video_link',
    'title'=>'Homepage hero video URL (YouTube)',
    'type'=>'text','object_type'=>'field','width'=>'100%',
    'description'=>'Paste a valid youtube.com/watch, youtu.be, Shorts or YouTube embed URL. Leave blank to use the source video link from Elementor. Invalid links never load an external iframe.'
   ];
  }
  if(!$extra)return $fields;
  // Insert beside the existing native Brand Hero controls when available.
  if($anchor>=0){array_splice($fields,$anchor+1,0,$extra);return $fields;}
  // Lightweight independent tab for companies without the Brand Core module.
  if(!$existing_hero_tab)$fields[]=[
   'name'=>'company_brand_tab_hero_video',
   'title'=>'Brand · Hero video','object_type'=>'tab','tab_layout'=>'horizontal'
  ];
  foreach($extra as $row)$fields[]=$row;
  return $fields;
 }

 public static function delay(string $device):int {
  $mobile=$device==='mobile';$k='company_brand_hero_video_delay_'.$device;
  $v=self::opt()[$k]??($mobile?3000:1800);
  return is_numeric($v)?max($mobile?900:600,min($mobile?10000:8000,(int)$v)):($mobile?3000:1800);
 }
 public static function video_id(string $src):string {
  $u=wp_parse_url($src);if(!is_array($u)||($u['scheme']??'')!=='https')return '';
  $host=strtolower((string)($u['host']??''));$id='';
  if(in_array($host,['youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com'],true)){
   parse_str((string)($u['query']??''),$q);$id=(string)($q['v']??'');
   if(!$id&&preg_match('#^/(?:embed|shorts)/([a-zA-Z0-9_-]{11})#',(string)($u['path']??''),$m))$id=$m[1];
  }elseif(in_array($host,['youtu.be','www.youtu.be'],true))$id=trim((string)($u['path']??''),'/');
  return preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)?$id:'';
 }
 public static function valid_image($input):string {
  $id=is_numeric($input)?(int)$input:(is_array($input)?(int)($input['id']??0):0);
  $url='';
  if($id){
   if(!in_array(get_post_mime_type($id),['image/jpeg','image/png','image/webp','image/avif'],true))return '';
   $url=(string)wp_get_attachment_image_url($id,'full');
  }else if(is_string($input))$url=$input;
  else if(is_array($input))$url=(string)($input['url']??'');
  if(strlen($url)>1500)return '';
  $u=wp_parse_url($url);
  if(!is_array($u)||($u['scheme']??'')!=='https'||strtolower((string)($u['host']??''))!==self::host()
   ||!preg_match('#^/wp-content/uploads/[a-zA-Z0-9/_~.\\%-]+\\.(?:jpe?g|png|webp|avif)$#iD',(string)($u['path']??'')))return '';
  return esc_url_raw($url);
 }
 /**
  * Explicit opt-in, source-neutral mobile first-paint policy.
  * Nginx supplies device class; WPML provides locale; companies opt in only
  * after a verified route/locale mobile critical CSS and hero acceptance.
  * An explicit brand poster or native video overrides the auto poster.
  */
 public static function mobile_hero_priority():bool {
  if(!self::active_page()||self::mode()==='native'||self::responsive_lcp_enabled())return false;
  if((string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'')!=='mobile')return false;
  if(get_option('mad4b_perf_mobile_hero_first_priority_v1','off')!=='on')return false;
  $languages=get_option('mad4b_perf_mobile_hero_priority_languages_v1',[]);
  $locale=(string)apply_filters('wpml_current_language',null);
  if(!is_array($languages)||!in_array($locale,$languages,true))return false;
  if(!empty(self::opt()['company_brand_hero_video_poster']))return false;
  return is_file(WP_CONTENT_DIR.'/uploads/2025/11/home-mix-mad4b-900.webp');
 }
 /** The same public HTML/cache key must work on phones, tablets and desktop. */
 public static function responsive_lcp_enabled():bool {
  if(!self::active_page() || self::mode()==='native' || get_option('mad4b_perf_hero_responsive_lcp_v1','off')!=='on')return false;
  $langs=get_option('mad4b_perf_hero_responsive_languages_v1',[]);
  $lang=(string)apply_filters('wpml_current_language',null);
  return is_array($langs) && in_array($lang,$langs,true);
 }
 private static function mobile_breakpoint():int {
  $kit=absint(get_option('elementor_active_kit',0));
  $s=$kit?get_post_meta($kit,'_elementor_page_settings',true):[];
  $v=is_array($s)?absint($s['viewport_mobile']??767):767;
  return $v>=480&&$v<=1100?$v:767;
 }
 private static function media_pair():array {
  $v=self::mobile_breakpoint();
  return [ '(max-width:'.$v.'px) and (max-resolution:3.01dppx)',
           '(min-width:'.($v+.01).'px), (min-resolution:3.02dppx)' ];
 }
 private static function local_upload_file(string $url):string {
  $parts=wp_parse_url($url);
  if(!is_array($parts)||strtolower((string)($parts['host']??''))!==self::host())return '';
  $urlpath=(string)($parts['path']??'');
  $prefix='/wp-content/uploads/';
  if(strpos($urlpath,$prefix)!==0)return '';
  $root=realpath(WP_CONTENT_DIR.'/uploads');
  $candidate=realpath(WP_CONTENT_DIR.'/uploads/'.substr($urlpath,strlen($prefix)));
  return $root&&$candidate&&strpos($candidate,$root.DIRECTORY_SEPARATOR)===0&&is_file($candidate)?$candidate:'';
 }
 /** Discover siblings of the selected, company-owned poster, no fixed IDs. */
 public static function responsive_sources():array {
  if(!self::responsive_lcp_enabled())return [];
  $wide=self::poster();
  if(!$wide || !($wideFile=self::local_upload_file($wide)))return [];
  $policy=get_option('mad4b_perf_hero_responsive_sources_v1',[]);
  if(!is_array($policy))$policy=[];
  $opts=self::opt();
  $small=self::valid_image($policy['compact']??($opts['company_brand_hero_mobile_poster']??null));
  if(!$small && !empty($opts['company_brand_hero_video_poster']))return [];
  if(!$small){
   if(preg_match('/^(.*)-([0-9]{3,5})(\.(?:webp|avif|jpe?g|png))$/iD',basename($wideFile),$m)){
    $w=(int)$m[2];$target=(int)round($w*.5625);$best='';$delta=PHP_INT_MAX;
    foreach((array)glob(dirname($wideFile).'/'.$m[1].'-[0-9]*'.$m[3]) as $file){
     if(!is_file($file)||is_link($file)||$file===$wideFile)continue;
     if(!preg_match('/-([0-9]{3,5})'.preg_quote($m[3],'/').'$/iD',basename($file),$sub))continue;
     $width=(int)$sub[1];
     if($width<600||$width>=$w||$width>=($w*.85))continue;
     $diff=abs($width-$target);
     if($diff<$delta){$best=$file;$delta=$diff;}
    }
    if($best){
     $relative=substr($best,strlen(WP_CONTENT_DIR.'/uploads/'));
     $small=self::valid_image(set_url_scheme(content_url('/uploads/'.$relative),'https'));
    }
   }
  }
  if(!$small||$small===$wide||!($smallFile=self::local_upload_file($small)))return [];
  $wideDimensions=@getimagesize($wideFile);
  $smallDimensions=@getimagesize($smallFile);
  $wideWidth=is_array($wideDimensions)?(int)$wideDimensions[0]:0;
  $smallWidth=is_array($smallDimensions)?(int)$smallDimensions[0]:0;
  if($smallWidth<600 || $wideWidth<1200 || $smallWidth>=$wideWidth)return [];
  [$smallMedia,$wideMedia]=self::media_pair();
  return ['compact'=>$small,'wide'=>$wide,'compact_media'=>$smallMedia,'wide_media'=>$wideMedia,'breakpoint'=>self::mobile_breakpoint(), 'compact_width'=>$smallWidth,'wide_width'=>$wideWidth];
 }
 public static function poster(array $settings=[]):string {
  $o=self::opt();
  $poster_file=!self::responsive_lcp_enabled() && self::mobile_hero_priority()
   ? 'home-mix-mad4b-900.webp' : 'home-mix-mad4b-1600.webp';
  $optimized=WP_CONTENT_DIR.'/uploads/2025/11/'.$poster_file;
  $local_poster=is_file($optimized)
   ? set_url_scheme(content_url('/uploads/2025/11/'.$poster_file),'https') : null;
  // An explicit site choice wins. The local optimized demo poster preserves
  // the existing home-mix hero instead of copying another company's brand art.
  foreach([$o['company_brand_hero_video_poster']??null,$local_poster,
           $settings['background_video_fallback']??null,
           $o['home_page_header_image']??null,$o['tours_archive_header_image']??null] as $x){
   if($v=self::valid_image($x))return $v;
  }
  return '';
 }
 public static function data($nodes,$template_id=0) {
  if(!self::active_page()||!is_array($nodes))return $nodes;
  $walk=static function($items)use(&$walk){
   foreach($items as &$n){
    if(!is_array($n))continue;
    $s=$n['settings']??[];
    if(($n['id']??'')===MAD4B_Delayed_Hero_YT::HERO
       &&($s['background_background']??'')==='video'){
     $custom=MAD4B_Delayed_Hero_YT::custom_video_url();
     $src=$custom!==''?$custom:(string)($s['background_video_link']??'');
     $id=MAD4B_Delayed_Hero_YT::video_id($src);
     $poster=MAD4B_Delayed_Hero_YT::poster($s);
     // Native strategy still inherits the settings-page URL dynamically.
     // Invalid/disabled links never fall through to an Elementor iframe.
     if(MAD4B_Delayed_Hero_YT::mode()==='native' && $id){
      $s['background_video_link']='https://www.youtube-nocookie.com/embed/'.$id;
      $s['background_video_privacy_mode']='yes';
      $n['settings']=$s;
     }else{
      $s['mad4b_youtube_hero_id']=$id&&MAD4B_Delayed_Hero_YT::enabled()?$id:'';
      $s['mad4b_youtube_hero_poster']=$poster;
      $s['mad4b_youtube_hero_off']=!MAD4B_Delayed_Hero_YT::enabled()||!$id?'yes':'';
      $s['mad4b_youtube_hero_start']=max(0,min(600,(int)($s['background_video_start']??0)));
      $s['mad4b_youtube_hero_end']=max(0,min(3600,(int)($s['background_video_end']??0)));
      $s['background_background']='classic';$s['background_video_link']='';
      $n['settings']=$s;
     }
    }
    if(!empty($n['elements'])&&is_array($n['elements']))$n['elements']=$walk($n['elements']);
   }unset($n);return $items;
  };
  return $walk($nodes);
 }
 public static function container($el):void {
  if(!self::active_page()||(string)$el->get_id()!==self::HERO)return;
  $id=(string)$el->get_settings('mad4b_youtube_hero_id');
  $poster=(string)$el->get_settings('mad4b_youtube_hero_poster');
  if(!$poster)return;
  if($id && self::enabled()){
   foreach(['id'=>$id,'poster'=>$poster,'mode'=>self::mode(),
            'start'=>(string)$el->get_settings('mad4b_youtube_hero_start'),
            'end'=>(string)$el->get_settings('mad4b_youtube_hero_end'),
            'desktop'=>(string)self::delay('desktop'),'mobile'=>(string)self::delay('mobile')] as $k=>$v){
    $el->add_render_attribute('_wrapper','data-mad4b-yt-'.$k,$v);
   }
  } else {
   $el->add_render_attribute('_wrapper','data-mad4b-yt-disabled','1');
  }
  $colors=get_option('mad4b_hero_poster_overlay_v1',[]);
  $gradient='';
  if(is_array($colors)&&isset($colors['from'],$colors['to'])){
   $stops=[];
   foreach(['from','to'] as $part){
    $rgba=$colors[$part];
    if(!is_array($rgba)||count($rgba)!==4){$stops=[];break;}
    $rgba=array_values($rgba);
    if(!is_numeric($rgba[0])||!is_numeric($rgba[1])||!is_numeric($rgba[2])||!is_numeric($rgba[3])){$stops=[];break;}
    $stops[]='rgba('.implode(', ',[
     max(0,min(255,(int)$rgba[0])),max(0,min(255,(int)$rgba[1])),max(0,min(255,(int)$rgba[2])),
     max(0,min(1,(float)$rgba[3]))
    ]).')';
   }
   if(count($stops)===2)$gradient='linear-gradient('.implode(', ',$stops).'),';
  }
  // Override stale Elementor CSS with the exact local poster, never a second image.
  // Brand-specific gradient is stored as numeric company settings, not copied CSS.
  $sources=self::responsive_sources();
  if($sources && $poster===$sources['wide']){
   $el->add_render_attribute('_wrapper','data-mad4b-lcp-responsive','1');
   $image='var(--mad4b-lcp-image)';
  }else $image='url('.wp_json_encode($poster,JSON_UNESCAPED_SLASHES).')';
  $el->add_render_attribute('_wrapper','style','background-image:'.$gradient.$image.' !important;background-size:cover;background-position:center;');
 }
 public static function assets():void {
  if(!self::active_page()||self::mode()==='native')return;
  $url=plugin_dir_url(__FILE__);
  wp_enqueue_style('mad4b-hero-poster',$url.'assets/hero.css',[],(string)filemtime(__DIR__.'/assets/hero.css'));
  // Disabled or invalid video URLs never enqueue the YouTube loader.
  $custom=self::custom_video_url();
  if(self::enabled() && ($custom==='' || self::video_id($custom)!=='')
      && in_array(self::mode(),['click_to_play','delayed_auto'],true))
   wp_enqueue_script('mad4b-hero-delay',$url.'assets/hero.js',[],(string)filemtime(__DIR__.'/assets/hero.js'),['in_footer'=>true,'strategy'=>'defer']);
 }
 public static function preload():void {
  if(!self::active_page()||self::mode()==='native')return;
  $src=self::responsive_sources();
  if($src){
   $selector='.elementor-element-'.self::HERO.'[data-mad4b-lcp-responsive="1"]';
   $large=wp_json_encode($src['wide'],JSON_UNESCAPED_SLASHES);
   $compact=wp_json_encode($src['compact'],JSON_UNESCAPED_SLASHES);
   $css=$selector.'{--mad4b-lcp-image:url('.$large.');}'
      .'@media '.$src['compact_media'].'{'.$selector.'{--mad4b-lcp-image:url('.$compact.');}}';
   echo '<style id="mad4b-lcp-responsive-v1" data-no-optimize="1">'.$css.'</style>' . "\n";
   // The parser's responsive preload scanner can speculatively fetch both
   // URLs on foldables before it settles on the actual CSS viewport. A tiny
   // synchronous head resolver uses the SAME media predicate as the hero CSS.
   // Never create a preload if matchMedia is unavailable: CSS still works.
   $compactUrl=wp_json_encode($src['compact'],JSON_UNESCAPED_SLASHES);
   $wideUrl=wp_json_encode($src['wide'],JSON_UNESCAPED_SLASHES);
   $media=wp_json_encode($src['compact_media'],JSON_UNESCAPED_SLASHES);
   echo '<script id="mad4b-lcp-preload-resolver-v1" data-no-optimize="1">'
    .'(function(){if(!window.matchMedia)return;var small=window.matchMedia('.$media.').matches;'
    .'var p=document.createElement("link");p.rel="preload";p.as="image";'
    .'p.setAttribute("fetchpriority","high");p.href=small?'.$compactUrl.':'.$wideUrl.';'
    .'p.setAttribute("data-mad4b-hero-poster",small?"compact":"wide");'
    .'document.head.appendChild(p);})()</script>' . "\n";
   return;
  }
  $poster=self::poster();
  if($poster)echo '<link rel="preload" as="image" fetchpriority="high" href="'.esc_url($poster).'" data-mad4b-hero-poster="fallback">' . "\n";
 }
 public static function rest():void {
  register_rest_route('mad4b/v1','/hero-youtube-delay',[
   'methods'=>'GET','permission_callback'=>static function(){return current_user_can('manage_options');},
   'callback'=>static function(){return rest_ensure_response([
    'scope'=>self::authorized(),'mode'=>self::mode(),'poster'=>self::poster(),
    'desktop_ms'=>self::delay('desktop'),'mobile_ms'=>self::delay('mobile')]);}]);
 }
 public static function boot():void {
  if(!self::authorized())return;
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'data'],85,2);
  add_action('elementor/frontend/container/before_render',[__CLASS__,'container'],12);
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],70);
  add_action('wp_head',[__CLASS__,'preload'],2);
  add_action('rest_api_init',[__CLASS__,'rest']);
 }
}
/**
 * Invalidate the homepage rendering chain when Hero Look & Feel fields change.
 * Elementor caches inner template HTML separately from LiteSpeed and Nginx.
 */
function mad4b_brand_hero_options_changed_v1($old,$new):void {
 if(!is_array($old)||!is_array($new))return;
 $keys=['company_brand_hero_video_enabled','company_brand_hero_video_link',
  'company_brand_hero_video_strategy','company_brand_hero_video_poster',
  'company_brand_hero_video_delay_desktop','company_brand_hero_video_delay_mobile'];
 $changed=false;foreach($keys as $key)if(($old[$key]??null)!==($new[$key]??null)){$changed=true;break;}
 if(!$changed)return;
 global $wpdb;
 // Only templates containing the authored Elementor hero, regardless of
 // translated template IDs. This runs on save, never on normal page loads.
 $like=$wpdb->esc_like('"e991bc9"');
 $ids=$wpdb->get_col($wpdb->prepare(
  "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id
   WHERE pm.meta_key='_elementor_data' AND p.post_type='elementor_library' AND pm.meta_value LIKE %s",
  '%'.$like.'%'
 ));
 $home=(int)get_option('page_on_front',0);
 if($home>0)$ids[]=$home;
 foreach(['en','ar','fr','de','it','es'] as $lang){
  $id=(int)apply_filters('wpml_object_id',$home,'page',false,$lang);
  if($id>0)$ids[]=$id;
 }
 foreach(array_unique(array_map('intval',$ids)) as $id)if($id>0){
  delete_post_meta($id,'_elementor_element_cache');
  clean_post_cache($id);
  $url=get_permalink($id);
  if(is_string($url)&&$url!=='')do_action('litespeed_purge_url',$url);
 }
 // Existing site-scoped FastCGI host watcher uses this signal for homepage
 // variants across both companies; no global Redis/cache purge.
 $signal=WP_CONTENT_DIR.'/.mad4b-home-reviews-purge.signal';
 if(is_writable(WP_CONTENT_DIR))
  @file_put_contents($signal,gmdate('c').' hero-option-changed '.wp_generate_uuid4()."\n",LOCK_EX);
}
add_action('update_option_look-and-feel','mad4b_brand_hero_options_changed_v1',30,2);

// The editor controls must be available independently of the frontend
// execution policy; JetEngine receives these on every Look & Feel page load.
add_filter('jet-engine/options-pages/raw-fields',['MAD4B_Delayed_Hero_YT','fields'],80,2);
add_action('init',['MAD4B_Delayed_Hero_YT','boot'],11);

