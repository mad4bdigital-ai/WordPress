<?php
/**
 * Plugin Name: MAD4B UAEL Tour Native Server Deferred Render
 * Description: Defers rendering the original UAEL Elementor gallery until a signed tour-specific public AJAX request.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
final class MAD4B_UAEL_Server_Defer_V1 {
 const POPUP_ID=15284;
 const WIDGET_ID='1e96a81';
 const OPTION='mad4b_uael_server_defer_mode';
 private static bool $rendering=false;
 private static int $verified_program=0;
 public static function in_deferred_render():bool {return self::$rendering;}
 public static function selected_program():int {
   return self::$rendering?self::$verified_program:0;
 }
 private static function assigned_program_options(int $tour):array {
   if(!function_exists('shared_product_tour_itinerary_contract_v1'))return [];
   $canonical=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,'en');
   if(!$canonical)$canonical=$tour;
   $source=shared_product_tour_itinerary_contract_v1($canonical);
   if(($source['authoritative_source']??'')!=='taxonomy:programs')return [];
   $ids=array_values(array_unique(array_filter(array_map('absint',
     (array)($source['linked_program_ids']??[])))));
   if(count($ids)>30)return []; // never expose an uncontrolled taxonomy catalog.
   $details=apply_filters('wpml_post_language_details',null,$tour);
   $lang=is_array($details)?(string)($details['language_code']??''):'en';
   $options=[];
   foreach($ids as $id){
     if(!function_exists('shared_product_tour_program_allowed_v1')
       ||!shared_product_tour_program_allowed_v1($canonical,$id))continue;
     $local=(int)apply_filters('wpml_object_id',$id,'programs',false,$lang);
     $term=get_term($local?:$id,'programs');
     if(!$term instanceof WP_Term)$term=get_term($id,'programs');
     if(!$term instanceof WP_Term)continue;
     $options[]=['id'=>$id,'label'=>sanitize_text_field($term->name)];
   }
   return $options;
 }
 public static function eligible():bool{
   if(self::$rendering)return true;
   if(is_admin()||wp_doing_ajax()||is_preview()
      ||(defined('REST_REQUEST')&&REST_REQUEST))return false;
   if(!function_exists('mad4b_is_main_tour_singular')||!mad4b_is_main_tour_singular())return false;
   // Historically signed-in editors saw UAEL eagerly. Preserve this for
   // normal tours and a single Program. For a genuinely multi-Program
   // published Cruise, render the same signed preview selector for editors
   // viewing the public Tour page. Never enter Elementor preview or wp-admin.
   if(is_user_logged_in()){
     $tour=(int)get_queried_object_id();
     if(count(self::assigned_program_options($tour))<2)return false;
   }
   $mode=(string)get_option(self::OPTION,'off');
   if($mode!=='active'&&($mode!=='canary'||!isset($_GET['mad4b_uael_server_canary'])))return false;
   if(!class_exists('MAD4B_UAEL_Tour_Gallery_Addon_V1',false))return false;
   return MAD4B_UAEL_Tour_Gallery_Addon_V1::can_render((int)get_queried_object_id());
 }
 public static function tree($data,$document_id){
   if(self::$rendering || (int)$document_id!==self::POPUP_ID || !self::eligible()
     ||!is_array($data))return $data;
   $tour=(int)get_queried_object_id();
   if($tour<1||get_post_status($tour)!=='publish')return $data;
   // Public read-only token tied to WordPress' native keyed HMAC. Unlike a
   // 12-hour user nonce, it survives longer-lived FastCGI page caches.
   // It does not authorize editing, payment, or unpublished content.
   $nonce=wp_hash('mad4b_uael_public_'.$tour,'nonce');
   if(!$nonce)return $data;
   $mutated=0;
   $walk=static function (&$nodes)use(&$walk,$tour,$nonce,&$mutated){
      foreach($nodes as &$node){
        if(!is_array($node))continue;
        if(($node['elType']??'')==='widget'
          &&($node['widgetType']??'')==='uael-image-gallery'
          &&($node['id']??'')===self::WIDGET_ID){
          $node['widgetType']='html';
          $node['settings']=['html'=>'<div data-mad4b-uael-server-slot="1"'
           .' data-mad4b-tour-id="'.(int)$tour.'"'
           .' data-mad4b-nonce="'.esc_attr($nonce).'"'
           .' aria-label="'.esc_attr__('Tour gallery','mad4b').'"'
           .' aria-live="polite" aria-busy="false"></div>'];
          $mutated++;
          continue;
        }
        if(!empty($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
      }
      unset($node);
   };
   $walk($data);
   return $data;
 }
 private static function original_widget_node(array $nodes):?array{
   foreach($nodes as $node){
     if(!is_array($node))continue;
     if(($node['widgetType']??'')==='uael-image-gallery'&&($node['id']??'')===self::WIDGET_ID
       &&($node['elType']??'')==='widget')return $node;
     if(!empty($node['elements'])&&is_array($node['elements'])){
       $r=self::original_widget_node($node['elements']);
       if($r)return $r;
     }
   }
   return null;
 }
 /**
  * Program-specific HTML cache identity. A taxonomy relationship edit or
  * Program term-meta change does not modify the Tour post_modified timestamp.
  * Hash only the authorized, published Tour's exact Program contract; never
  * vary by raw client filters or arbitrary Program IDs.
  */
 public static function program_content_fingerprint(int $tour):string {
   if($tour<1||get_post_type($tour)!=='tours-and-activities'
      ||get_post_status($tour)!=='publish'
      ||!class_exists('MAD4B_Gallery_Itinerary_Policy_V1',false))return 'unavailable';
   $default=(string)apply_filters('wpml_default_language','en');
   $canonical=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default);
   if(!$canonical)$canonical=$tour;
   $policy=MAD4B_Gallery_Itinerary_Policy_V1::resolve($tour,$canonical);
   $program=(int)($policy['program_id']??0);
   $contract=function_exists('shared_product_tour_itinerary_contract_v1')
     ?shared_product_tour_itinerary_contract_v1($canonical):[];
   $ids=array_values(array_unique(array_filter(array_map('absint',
      (array)($contract['linked_program_ids']??[])))));
   sort($ids,SORT_NUMERIC);
   $programSources=[];
   foreach($ids as $id)
     $programSources[(string)$id]=hash('sha256',wp_json_encode(
       get_term_meta($id,'program_itinerary',true)));
   return hash('sha256',wp_json_encode([
     'authority'=>(string)($policy['authority']??''),
     'state'=>(string)($policy['state']??''),
     'canonical_tour'=>$canonical,
     'program_id'=>$program,
     'program_count'=>(int)($policy['program_count']??0),
     'assigned_program_sources'=>$programSources,
     'tour_itinerary'=>($program===0?get_post_meta($canonical,'itinerary_item',true):null),
     // Stage times are per-Tour editorial data. Include both canonical
     // and locale-specific schedules so a saved time cannot leave a stale
     // 180-second Elementor popup HTML response on either WPML locale.
     'stage_schedule'=>class_exists('MAD4B_Gallery_Stage_Policy_V1',false)
       &&MAD4B_Gallery_Stage_Policy_V1::enabled($canonical)
         ?[get_post_meta($canonical,MAD4B_Gallery_Stage_Policy_V1::META,true),
           get_post_meta($tour,MAD4B_Gallery_Stage_Policy_V1::META,true)]:null
   ]));
 }
 public static function ajax():void {
   if($_SERVER['REQUEST_METHOD']!=='POST'){
     wp_send_json_error(['code'=>'invalid_method'],405);
   }
   $tour=absint($_POST['tour_id']??0);
   $nonce=isset($_POST['_ajax_nonce'])&&is_scalar($_POST['_ajax_nonce'])
      ?(string)wp_unslash($_POST['_ajax_nonce']):'';
   $public_token=$tour>0?wp_hash('mad4b_uael_public_'.$tour,'nonce'):'';
   $verified=$tour>0&&$nonce!==''&&(
       (strlen($nonce)===strlen($public_token)&&hash_equals($public_token,$nonce))
       ||(bool)wp_verify_nonce($nonce,'mad4b_uael_public_'.$tour));
   if((string)get_option(self::OPTION,'off')==='off')
       wp_send_json_error(['code'=>'gallery_not_enabled'],404);
   if(!$tour||get_post_type($tour)!=='tours-and-activities'
      ||get_post_status($tour)!=='publish'||!$verified){
       wp_send_json_error(['code'=>'not_permitted'],403);
   }
   $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
   if($origin!==''){
     $host=strtolower((string)wp_parse_url($origin,PHP_URL_HOST));
     $own=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
     if($host!==$own)wp_send_json_error(['code'=>'origin_mismatch'],403);
   }
   // Read-only preview: option IDs are verified against this same published
   // Tour's canonical programs taxonomy before any gallery render. A Rate
   // remains separately responsible for booking/price eligibility.
   $programRaw=$_POST['program_id']??'0';
   if(!is_scalar($programRaw)||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$programRaw))
     wp_send_json_error(['code'=>'invalid_program'],400);
   $requested=(int)$programRaw;
   $programOptions=self::assigned_program_options($tour);
   $allowed=array_column($programOptions,'id');
   if($requested>0&&!in_array($requested,$allowed,true))
     wp_send_json_error(['code'=>'unassigned_program_rejected'],403);
   if(get_post_status(self::POPUP_ID)!=='publish')
      wp_send_json_error(['code'=>'popup_unavailable'],404);
   $raw=json_decode((string)get_post_meta(self::POPUP_ID,'_elementor_data',true),true);
   $node=is_array($raw)?self::original_widget_node($raw):null;
   if(!$node)wp_send_json_error(['code'=>'widget_missing'],404);
   $prior_query=$GLOBALS['wp_query']??null;
   $prior_post=$GLOBALS['post']??null;
   $prior_lang=(string)apply_filters('wpml_current_language','');
   $details=apply_filters('wpml_post_language_details',null,$tour);
   $lang=is_array($details)?(string)($details['language_code']??''):'';
   // Public gallery HTML may be reused briefly only within the same WPML
   // tour, locale, Elementor template revision and media-evidence revision.
   $proof=(array)get_option('mad4b_watermark_visual_evidence_v1',[]);
   $program_fp=self::program_content_fingerprint($tour);
   // Only this Gallery HTML cache varies when its existing PHP tab-label
   // generator changes. No FastCGI, Redis-wide or unrelated cache purge.
   $gallery_addon=WP_CONTENT_DIR.'/mu-plugins/mad4b-uael-tour-gallery-addon.php';
   $label_code_sha=is_file($gallery_addon)?hash_file('sha256',$gallery_addon):false;
   if(!is_string($label_code_sha)||$label_code_sha==='')
       wp_send_json_error(['code'=>'gallery_renderer_unavailable'],503);
   $part=implode('|',[strtolower(home_url('/')),$tour,$lang,
      $requested,$program_fp,
      (string)get_post_modified_time('U',true,$tour),
      (string)get_post_modified_time('U',true,self::POPUP_ID),
      (string)get_option('mad4b_story_gallery_media_revision_v1','1'),
      (string)($proof['evidence_revision']??'0'),
      (string)get_option('mad4b_uael_tour_gallery_mode','off'),
      $label_code_sha]);
   $cached_key=md5($part);
   if(!is_user_logged_in()){
       $cached=wp_cache_get($cached_key,'mad4b-uael-gallery-html-v1');
       if(is_string($cached)&&strlen($cached)>1000
          &&strpos($cached,'data-mad4b-uael-tour="1"')!==false){
           nocache_headers();
           wp_send_json_success(['html'=>$cached,'tour_id'=>$tour,
             'widget_id'=>self::WIDGET_ID,'program_id'=>$requested,
             'programs'=>$programOptions,'preview_only'=>true]);
       }
   }
   // Only the published tour's actual WPML locale is permitted.
   if($lang!==''&&$lang!==$prior_lang)do_action('wpml_switch_language',$lang);
   self::$rendering=true;
   self::$verified_program=$requested;
   try{
      $GLOBALS['wp_query']=new \WP_Query([
         'post_type'=>'tours-and-activities','p'=>$tour,
         'post_status'=>'publish','posts_per_page'=>1,
         'suppress_filters'=>false
      ]);
      $post=get_post($tour);
      if(!is_singular('tours-and-activities')||(int)get_queried_object_id()!==$tour
        ||!($post instanceof WP_Post))throw new \RuntimeException('tour_query_mismatch');
      $GLOBALS['post']=$post;
      setup_postdata($post);
      $instance=\Elementor\Plugin::instance()->elements_manager->create_element_instance($node);
      if(!$instance||$instance->get_name()!=='uael-image-gallery'
        ||$instance->get_id()!==self::WIDGET_ID)throw new \RuntimeException('native_widget_unavailable');
      ob_start();
      try{$instance->print_element();$html=(string)ob_get_clean();}
      catch(\Throwable $e){ob_end_clean();throw $e;}
      if(strpos($html,'data-mad4b-uael-tour="1"')===false
         ||strpos($html,'uael-grid-item')===false
         ||strlen($html)>1200000)throw new \RuntimeException('native_gallery_not_ready');
      if(!is_user_logged_in())wp_cache_set($cached_key,$html,'mad4b-uael-gallery-html-v1',180);
      nocache_headers();
      wp_send_json_success(['html'=>$html,'tour_id'=>$tour,
        'widget_id'=>self::WIDGET_ID,'program_id'=>$requested,
        'programs'=>$programOptions,'preview_only'=>true]);
   }catch(\Throwable $e){
      error_log('MAD4B_UAEL_DEFER_RENDER_FAILED '.sanitize_text_field($e->getMessage()));
      wp_send_json_error(['code'=>'gallery_render_unavailable'],503);
   }finally{
      self::$rendering=false;
      self::$verified_program=0;
      wp_reset_postdata();
      if($lang!==''&&$prior_lang!==''&&$lang!==$prior_lang)do_action('wpml_switch_language',$prior_lang);
      $GLOBALS['wp_query']=$prior_query;
      $GLOBALS['post']=$prior_post;
   }
 }
 public static function assets():void{
    if(!self::eligible())return;
    wp_enqueue_script('mad4b-uael-server-defer',
       content_url('mu-plugins/assets/mad4b-uael-server-defer-v4-20261112.js'),
       ['jquery'],'0.2.0',true);
    wp_add_inline_script('mad4b-uael-server-defer',
       'window.MAD4B_UAEL_SERVER_DEFER='.
        wp_json_encode(['url'=>admin_url('admin-ajax.php'),'action'=>'mad4b_uael_server_defer'],JSON_HEX_TAG|JSON_HEX_AMP).
        ';','before');
 }
}
add_filter('elementor/frontend/builder_content_data',
 [MAD4B_UAEL_Server_Defer_V1::class,'tree'],99999,2);
add_action('wp_ajax_mad4b_uael_server_defer',[MAD4B_UAEL_Server_Defer_V1::class,'ajax']);
add_action('wp_ajax_nopriv_mad4b_uael_server_defer',[MAD4B_UAEL_Server_Defer_V1::class,'ajax']);
add_action('wp_enqueue_scripts',[MAD4B_UAEL_Server_Defer_V1::class,'assets'],75);

// Elementor 4.x caches a fully-rendered document by template ID. Its
// page-insensitive cache would otherwise defeat the tour-scoped widget
// transformation above, even when tree() replaces the widget correctly.
// Disable that cache ONLY while this exact Popup document is current,
// and ONLY for an eligible public tour canary (or later active release).
add_filter('pre_option_elementor_element_cache_ttl',static function($prior){
    if(!class_exists('MAD4B_UAEL_Server_Defer_V1',false)
       ||!MAD4B_UAEL_Server_Defer_V1::eligible())return $prior;
    if(!class_exists('\Elementor\Plugin',false))return $prior;
    $document=\Elementor\Plugin::instance()->documents->get_current();
    if(!$document || !method_exists($document,'get_id')
       ||(int)$document->get_id()!==MAD4B_UAEL_Server_Defer_V1::POPUP_ID)
       return $prior;
    return 'disable';
}, 12000, 1);
