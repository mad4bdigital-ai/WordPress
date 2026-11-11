<?php
/**
 * QA-only additive Elementor native discovery/select bridge.
 * Reuses ORIGINAL: Tour template 42179, Rate listing template 48317,
 * Stay/Unit shortcode widget from 66565 and existing Fluent Form #6.
 * Does not write Order, Form Submission or Pricing state.
 */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='local')return;
function booking_hybrid_qa_manifest(): array {
 $m=get_option('commerce_cart_lab_fixture_20261026_v1',[]);
 return is_array($m)&&($m['schema']??'')==='cart-lab-fixture-v1'?$m:[];
}
function booking_hybrid_qa_allowed(): bool {
 return current_user_can('manage_woocommerce') && partner_rights_local_write_hold_active();
}
function booking_hybrid_qa_active(): bool {
 // Original Elementor Tour layout is the operator's current baseline.
 // Keep the experimental enhancement dormant until explicitly re-enabled.
 if(get_option('commerce_cart_lab_native_baseline_mode_20261026',false))return false;
 if(!booking_hybrid_qa_allowed())return false;
 $m=booking_hybrid_qa_manifest();
 if(!$m)return false;
 $id=get_queried_object_id();
 if(!$id && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post)
   $id=(int)$GLOBALS['post']->ID;
 return $id===(int)$m['tour_id']
   || $id===(int)get_option('commerce_cart_lab_hybrid_preview_page_20261026',0);
}
function booking_hybrid_qa_context_tour(): int {
 return (int)(booking_hybrid_qa_manifest()['tour_id']??0);
}
function booking_hybrid_qa_ref_rate($maybe): int {
 $id=filter_var($maybe,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
 $m=booking_hybrid_qa_manifest();
 return ($id && in_array((int)$id,array_map('intval',$m['rate_ids']??[]),true)
    && (int)get_post_meta((int)$id,'related_tour_id',true)===(int)$m['tour_id'])?(int)$id:0;
}
function booking_hybrid_qa_assets(): void {
 if(!booking_hybrid_qa_active())return;
 $url=content_url('mu-plugins/hybrid-native-selection-assets/');
 // QA-only cache keys follow exact on-disk asset revisions. Otherwise an
 // operator can keep old selection logic after the Elementor preview changes.
 $assets=dirname(__DIR__).'/hybrid-native-selection-assets/';
 $css=$assets.'native-hybrid.css';
 $js=$assets.'native-hybrid.js';
 $css_version=is_file($css)?(string)filemtime($css):'1.0.0';
 $js_version=is_file($js)?(string)filemtime($js):'1.0.0';
 wp_enqueue_style('booking-hybrid-native-qa',$url.'native-hybrid.css',[],$css_version);
 wp_enqueue_script('booking-hybrid-native-qa',$url.'native-hybrid.js',[],$js_version,true);
 $m=booking_hybrid_qa_manifest();
 wp_add_inline_script('booking-hybrid-native-qa',
   'window.BookingHybridQA='.wp_json_encode([
    'tourId'=>(int)$m['tour_id'],
    'rateIds'=>array_map('intval',$m['rate_ids']??[]),
    'defaultRate'=>(int)$m['default_rate_id'],
    'formId'=>6,
    'originalRateTemplate'=>48317,
    'nativeRateWidgetIds'=>['854289f','eb639ca'],
    'originalFormWidget'=>'ebdaa9c',
    'authoritativeCheckout'=>false,
    'savedSubmission'=>false,
  ],JSON_UNESCAPED_SLASHES).';','before');
}
add_action('wp_enqueue_scripts','booking_hybrid_qa_assets',12);
/**
 * One QA fixture, one native customer route. A private QA wrapper built from
 * a standalone Arabic content section is NOT the actual English single Tour
 * layout and caused the incorrect language and missing discovery sections.
 * Send authorized operators to the original WPML-aware Tour single instead.
 * This redirect does not affect Elementor editing or other site pages.
 */
add_action('template_redirect',static function(){
 if(!booking_hybrid_qa_allowed())return;
 if(!is_page((int)get_option('commerce_cart_lab_hybrid_preview_page_20261026',0)))return;
 if(defined('REST_REQUEST')&&REST_REQUEST)return;
 if(wp_doing_ajax())return;
 if(isset($_GET['elementor-preview'])||isset($_GET['action'])
   && $_GET['action']==='elementor')return;
 $m=booking_hybrid_qa_manifest();
 if((int)($m['tour_id']??0)!==66996 || get_post_status(66996)!=='publish'
   || get_post_meta(66996,'_booking_experience_qa_pricing_fixture',true)!=='cart-lab-20261026:tour')
   return;
 // Explicit reversible QA switch: the experiment uses the protected
 // fixture Tour, while the baseline mode uses an unchanged English Tour.
 // No published Elementor templates or commercial records are edited.
 $experimental=!get_option('commerce_cart_lab_native_baseline_mode_20261026',false);
 // Native cards can be reviewed on the fixture independently of Hybrid.
 $native_fixture=get_option('commerce_cart_lab_native_fixture_preview_20261027',false);
 $reference=($experimental||$native_fixture)?66996:60629;
 $language=apply_filters('wpml_post_language_details',null,$reference);
 if(get_post_type($reference)!=='tours-and-activities'
    || get_post_status($reference)!=='publish'
    || !is_array($language) || ($language['language_code']??'')!=='en'
    || !get_post_thumbnail_id($reference)
    || !get_post_meta($reference,'gallery',true))return;
 $destination=wp_make_link_relative(get_permalink($reference));
 if(!$destination || !str_starts_with($destination,'/tours-and-activities/'))return;
 nocache_headers();
 wp_safe_redirect($destination,302,$experimental?
   'Local QA temporary Hybrid tour':'Local QA original English Tour baseline');
 exit;
},9);

// The older standalone Cart UI stays available at its shortcode,
// but is not also loaded on the native hybrid QA preview page.
add_action('wp_enqueue_scripts',static function(){
 if(!booking_hybrid_qa_active())return;
 if(get_queried_object_id()===(int)get_option('commerce_cart_lab_hybrid_preview_page_20261026',0)){
  wp_dequeue_script('cart-variation-qa');
  wp_dequeue_style('cart-variation-qa');
 }
},30);
/** Render the original template in the context of a QA Tour for a private QA preview page. */
add_shortcode('booking_hybrid_native_template', static function($attrs) {
 if(!booking_hybrid_qa_active())return '';
 if(!class_exists('\Elementor\Plugin'))return '';
 $tour=get_post(booking_hybrid_qa_context_tour());
 if(!$tour || $tour->post_type!=='tours-and-activities')return '';
 // Respect WPML even when an operator explicitly renders the legacy
 // fragment for QA. The source 42179 is Arabic; never hardwire it on EN.
 $language=apply_filters('wpml_current_language',null);
 if(!is_string($language)||$language==='')$language='en';
 $template=(int)apply_filters('wpml_object_id',42179,'elementor_library',false,$language);
 $details=$template?apply_filters('wpml_post_language_details',null,$template):null;
 if(!$template || get_post_status($template)!=='publish'
   || (is_array($details)&&($details['language_code']??null)!==$language))
   return '<p role="alert">QA language template is unavailable; no fallback to a different language.</p>';
 $oldPost=$GLOBALS['post']??null;
 $query=$GLOBALS['wp_query']??null;
 $oldQueried=is_object($query)?($query->queried_object??null):null;
 $oldQueriedId=is_object($query)?($query->queried_object_id??null):null;
 $oldIsSingle=is_object($query)?($query->is_single??null):null;
 $GLOBALS['post']=$tour;
 if(is_object($query)){
  $query->queried_object=$tour;
  $query->queried_object_id=$tour->ID;
  $query->is_single=true;
 }
 setup_postdata($tour);
 try{
  ob_start();
  $html=\Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template,true);
  $echoed=ob_get_clean();
  return '<section class="booking-hybrid-native-qa" data-booking-hybrid-qa data-tour-id="'.(int)$tour->ID.'">'.
    $echoed.$html.'</section>';
 }catch(\Throwable $e){
  if(ob_get_level()>0)ob_end_clean();
  error_log('hybrid native QA render rejected');
  return '';
 }finally{
  $GLOBALS['post']=$oldPost;
  if(is_object($query)){
   $query->queried_object=$oldQueried;$query->queried_object_id=$oldQueriedId;
   $query->is_single=$oldIsSingle;
  }
  if($oldPost instanceof \WP_Post)setup_postdata($oldPost);else wp_reset_postdata();
 }
});
/** Context adapter for the ORIGINAL Elementor stay widget (template 66565).
 * Only QA: the original shortcode has no explicit attrs when edited in Elementor.
 * Supply source identity without copying or overriding the widget itself.
 */
add_filter('shortcode_atts_booking_tour_stay_options',static function($out){
 if(!booking_hybrid_qa_active())return $out;
 $manifest=booking_hybrid_qa_manifest();
 if(!(int)($out['tour_id']??0))$out['tour_id']=(int)$manifest['tour_id'];
 if(!(int)($out['rate_id']??0))$out['rate_id']=(int)$manifest['default_rate_id'];
 return $out;
},10,1);
/** Insert the EXISTING Elementor stay template 66565 after native Rate widget.
 * No replacement of ListingGrid, Jet filters, exploration tabs or Fluent Form.
 */
add_action('elementor/frontend/widget/after_render',static function($widget){
 if(!booking_hybrid_qa_active() || $widget->get_id()!=='1985c71')return;
 $m=booking_hybrid_qa_manifest();
 // Grid Listing already includes the native stay widget in each Rate card.
 if((class_exists('MAD4B_Tour_Rate_View_Inheritance_V1') ? MAD4B_Tour_Rate_View_Inheritance_V1::resolve((int)$m['tour_id'])['style']==='grid' : get_post_meta((int)$m['tour_id'],'tour_rates_display_style',true)==='Grid Listing'))return;
 $rate=booking_hybrid_qa_ref_rate((int)$m['default_rate_id']);
 if(!$rate)return;
 echo '<div class="booking-hybrid-native-stay-slot" data-hybrid-stay-slot>';
 echo '<div class="booking-hybrid-section-intro"><strong>Explore accommodation & build your stay</strong><p>Review the available hotels and rooms without leaving the original Tour Rate offers.</p></div>';
 // Render ORIGINAL Elementor Stay widget/template (not a copied shortcode UI).
 ob_start();
 $rendered=\Elementor\Plugin::instance()->frontend->get_builder_content_for_display(66565,true);
 $styles=ob_get_clean();
 echo $styles.$rendered;
 echo '</div>';
},15);
add_action('elementor/frontend/widget/before_render',static function($widget){
 if(!booking_hybrid_qa_active()||$widget->get_id()!=='ebdaa9c')return;
 echo '<section class="booking-hybrid-inline-summary" data-hybrid-summary role="region" aria-label="Your selected trip">';
 echo '<strong>Trip options</strong><p data-hybrid-summary-rate>Explore rates and select an option.</p>';
 echo '<p data-hybrid-summary-stay>Accommodation is chosen in the original stay widget.</p>';
 echo '<small>Request preview only. Availability, total and supplier prices are unconfirmed.</small>';
 echo '</section>';
},8);
/**
 * Private operator QA Tour may open on a forwarded browser origin.
 * Internal absolute localhost asset / image / REST URLs cannot load
 * on that browser. Scope ONLY to the original QA Tour request.
 * Never mutate WordPress siteurl/home, cookies or a commercial post.
 */
function booking_hybrid_qa_original_tour_request(): bool {
 $m=booking_hybrid_qa_manifest();
 return booking_hybrid_qa_allowed()
   && (int)($m['tour_id']??0)===66996
   && isset($GLOBALS['wp_the_query'])
   && $GLOBALS['wp_the_query'] instanceof \WP_Query
   && $GLOBALS['wp_the_query']->is_singular('tours-and-activities')
   && (int)$GLOBALS['wp_the_query']->get_queried_object_id()===66996;
}
function booking_hybrid_qa_origin_relative($source) {
 if(!is_string($source)||$source==='')return $source;
 $host=wp_parse_url($source,PHP_URL_HOST);
 $original=wp_parse_url(home_url('/'),PHP_URL_HOST);
 if(!$host || !$original || strtolower($host)!==strtolower($original))return $source;
 return wp_make_link_relative($source);
}
add_filter('body_class',static function($classes){
 if(booking_hybrid_qa_original_tour_request())$classes[]='booking-hybrid-native-qa';
 return $classes;
},95,1);
foreach(['style_loader_src','script_loader_src','wp_get_attachment_url'] as $tag){
 add_filter($tag,static function($source){
   return booking_hybrid_qa_original_tour_request()
     ? booking_hybrid_qa_origin_relative($source) : $source;
 },95,1);
}
add_filter('wp_calculate_image_srcset',static function($sources){
 if(!booking_hybrid_qa_original_tour_request() || !is_array($sources))return $sources;
 foreach($sources as &$source){
  if(isset($source['url']))$source['url']=booking_hybrid_qa_origin_relative($source['url']);
 }
 unset($source);
 return $sources;
},95,1);
add_filter('rest_url',static function($url){
 return booking_hybrid_qa_original_tour_request()
   ? booking_hybrid_qa_origin_relative($url) : $url;
},95,1);
add_filter('redirect_canonical',static function($redirect){
 return booking_hybrid_qa_original_tour_request()?false:$redirect;
},95,1);

// Password-protected QA tour remains inaccessible to visitors;
// site operators may preview the actual original Tour template.
add_filter('post_password_required',static function($required,$post){
 $m=booking_hybrid_qa_manifest();
 if(booking_hybrid_qa_allowed()&&$post instanceof \WP_Post
    && $post->ID===(int)($m['tour_id']??0))return false;
 return $required;
},10,2);
