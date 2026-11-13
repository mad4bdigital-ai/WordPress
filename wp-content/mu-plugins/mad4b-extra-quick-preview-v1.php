<?php
/**
 * Plugin Name: MAD4B Optional Extra Quick Preview — Native Tour Single
 * Description: A same-origin, authorized activity preview reusing the existing Elementor Tour Single page; no second product/template or payment action.
 * Version: 1.1.0
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Extra_Quick_Preview_V1',false)){
final class MAD4B_Extra_Quick_Preview_V1 {
 const KEY='mad4b_extra_quickview';
 const MAX_RELATIONS=100;
 private static function linked(int $parent,int $extra):bool {
  if(!$parent||!$extra||$parent===$extra||get_post_type($parent)!=='tours-and-activities'||get_post_type($extra)!=='tours-and-activities'
    ||(string)get_post_meta($parent,'tour_extras_status',true)!=='1')return false;
  $related=get_post_meta($parent,'tour_optional_extras',true);
  if(is_string($related))$related=maybe_unserialize($related);
  if(is_string($related))$related=explode(',',$related);
  if(!is_array($related)||count($related)>self::MAX_RELATIONS)return false;
  foreach($related as $id)if(is_scalar($id)&&ctype_digit(trim((string)$id))&&(int)$id===$extra)return true;
  return false;
 }
 public static function authorized(int $parent,int $extra,string $nonce=''):bool {
  if(!self::linked($parent,$extra))return false;
  $post=get_post($extra);$owner=get_post($parent);
  if(!$post||!$owner||$post->post_status!=='publish'||$post->post_password!==''||$owner->post_password!==''
    ||!in_array((string)get_post_meta($extra,'tour_mode',true),['activity','extra_trip'],true)
    ||in_array(strtolower((string)get_post_meta($extra,'stop_sale',true)),['1','yes','true'],true))return false;
  $parentLang=apply_filters('wpml_post_language_details',null,$parent);
  $extraLang=apply_filters('wpml_post_language_details',null,$extra);
  if(is_array($parentLang)&&is_array($extraLang)&&!empty($parentLang['language_code'])&&!empty($extraLang['language_code'])
    &&$parentLang['language_code']!==$extraLang['language_code'])return false;
  $editorPreview=$owner->post_status==='draft' && is_user_logged_in() &&current_user_can('edit_post',$parent)
    &&$nonce!=='' && wp_verify_nonce($nonce,'mad4b-extra-preview:'.$parent.':'.$extra);
  $public=$owner->post_status==='publish'
    &&!in_array((string)get_post_meta($parent,'tour_public_status',true),['0','false'],true)
    &&!in_array((string)get_post_meta($extra,'tour_public_status',true),['0','false'],true);
  return (bool)($public||$editorPreview);
 }
 public static function url(int $parent,int $extra):string {
  $nonce='';
  if(get_post_status($parent)==='draft'&&is_user_logged_in()&&current_user_can('edit_post',$parent))
    $nonce=wp_create_nonce('mad4b-extra-preview:'.$parent.':'.$extra);
  if(!self::authorized($parent,$extra,$nonce))return '';
  $url=get_permalink($extra);if(!$url)return '';
  $params=[self::KEY=>'1','mad4b_parent'=>(string)$parent,'mad4b_extra'=>(string)$extra];
  if($nonce)$params['mad4b_preview_nonce']=$nonce;
  return add_query_arg($params,$url);
 }
 public static function request():array {
  if((string)($_GET[self::KEY]??'')!=='1')return [0,0,''];
  $parent=absint($_GET['mad4b_parent']??0);$child=absint($_GET['mad4b_extra']??0);
  $nonce=isset($_GET['mad4b_preview_nonce'])&&is_string($_GET['mad4b_preview_nonce'])
    ?sanitize_text_field(wp_unslash($_GET['mad4b_preview_nonce'])):'';
  return [$parent,$child,$nonce];
 }
 public static function authorized_request():int {
  [$parent,$child,$nonce]=self::request();
  return $child&&self::authorized($parent,$child,$nonce)?$child:0;
 }
 public static function guest_safe_query_allowlist(WP_Query $query):int {
  $child=self::authorized_request();if(!$child||!$query->is_main_query())return 0;
  $post=get_post($child);if(!$post)return 0;
  $slug=(string)$query->get('name');
  $postId=absint($query->get('p'));
  if($slug!==''&&$slug===$post->post_name)return $child;
  if($postId===$child)return $child;
  return 0;
 }
 public static function validate_request():void {
  [$parent,$child]=self::request();if(!$parent&&!$child)return;
  if((string)($_GET[self::KEY]??'')!=='1')return;
  $authorized=self::authorized_request();
  if(!$authorized||!is_singular('tours-and-activities')||(int)get_queried_object_id()!==$authorized){
   status_header(403);nocache_headers();wp_die(esc_html__('This optional experience preview is unavailable.','mad4b'),'Preview unavailable',['response'=>403]);
  }
  nocache_headers();header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: same-origin');
  header('Content-Security-Policy: frame-ancestors \'self\'');
 }
 public static function body_class(array $classes):array {
  if(self::authorized_request()&&(int)get_queried_object_id()===self::authorized_request())$classes[]='mad4b-extra-quickview-frame';
  return $classes;
 }
 public static function embed_style():void {
  if(!self::authorized_request()||(int)get_queried_object_id()!==self::authorized_request())return;
  echo '<style id="mad4b-extra-quickview-frame-style">'
    .'body.mad4b-extra-quickview-frame{margin:0!important;padding:0!important}'
    .'body.mad4b-extra-quickview-frame #wpadminbar,'
    .'body.mad4b-extra-quickview-frame .elementor-location-header,'
    .'body.mad4b-extra-quickview-frame .elementor-location-footer,'
    .'body.mad4b-extra-quickview-frame #masthead,'
    .'body.mad4b-extra-quickview-frame #colophon,'
    .'body.mad4b-extra-quickview-frame .ast-header-break-point .main-header-bar,'
    .'body.mad4b-extra-quickview-frame .mad4b-tour-inquiry-column,'
    .'body.mad4b-extra-quickview-frame .elementor-element-c064aeb,'
    .'body.mad4b-extra-quickview-frame .tour-booking-form,'
    .'body.mad4b-extra-quickview-frame .shared-extra-feature-carousel,'
    .'body.mad4b-extra-quickview-frame .shared-extra-standard-listing{display:none!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-711a0ab ~ .elementor-element,'
    .'body.mad4b-extra-quickview-frame .elementor-element-e11446e ~ .elementor-element,'
    .'body.mad4b-extra-quickview-frame .elementor-element-5131633 ~ .elementor-element{display:none!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-e11446e{--content-width:100%!important;width:100%!important;max-width:100%!important;gap:0!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-e11446e > .e-con-inner{width:100%!important;max-width:100%!important;min-width:0!important;gap:0!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-e11446e > .e-con-inner > .elementor-element-fd7e73a{--width:100%!important;flex:0 0 100%!important;width:100%!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important;padding-inline:clamp(10px,2vw,20px)!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-711a0ab{padding-bottom:clamp(20px,3vw,34px)!important}'
    .'body.mad4b-extra-quickview-frame .elementor-element-711a0ab .elementor-element-02fb620{margin-top:clamp(16px,3vw,32px)!important}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-action{display:flex;flex-direction:column;align-items:flex-start;gap:9px;margin-top:18px;max-width:100%}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-add{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;max-width:100%;padding:12px 22px;background:#164a53;color:#fff;border:1px solid #164a53;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;white-space:normal;text-align:center}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-add:hover{background:#21646b;color:#fff}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-add:focus-visible{outline:3px solid #b98e4a;outline-offset:3px}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-add:disabled{opacity:.62;cursor:wait}'
    .'body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-message{font-size:13px;line-height:1.5;color:#b13d27;max-width:100%}'
    .'@media(max-width:767px){body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-action{align-items:stretch}body.mad4b-extra-quickview-frame .mad4b-extra-preview-cta-add{width:100%}}'
    .'body.mad4b-extra-quickview-frame .site-content,body.mad4b-extra-quickview-frame .ast-container{margin:0 auto!important;max-width:1200px!important;padding:0 16px!important}'
    .'body.mad4b-extra-quickview-frame{--wp-admin--admin-bar--height:0px;scroll-padding-top:8px}'
    // Only the authorized iframe: let the ORIGINAL Elementor popup own the
    // single vertical scrolling surface. No iframe-body/outer dialog scroll.
    .'html.mad4b-extra-quickview-gallery-active,body.mad4b-extra-quickview-frame.mad4b-extra-quickview-gallery-active{height:100%!important;overflow:hidden!important}'
    .'body.mad4b-extra-quickview-frame.mad4b-extra-quickview-gallery-active .elementor-popup-modal:has([data-mad4b-uael-tour="1"]){inset:0!important;width:100vw!important;height:100dvh!important;max-height:100dvh!important;box-sizing:border-box!important}'
    .'body.mad4b-extra-quickview-frame.mad4b-extra-quickview-gallery-active .elementor-popup-modal:has([data-mad4b-uael-tour="1"]) .dialog-widget-content{width:100%!important;max-width:100%!important;height:100dvh!important;max-height:100dvh!important;border-radius:0!important;box-sizing:border-box!important}'
    .'body.mad4b-extra-quickview-frame.mad4b-extra-quickview-gallery-active .elementor-popup-modal:has([data-mad4b-uael-tour="1"]) .dialog-message{height:100%!important;max-height:100dvh!important;overflow-x:hidden!important;overflow-y:auto!important;overscroll-behavior:contain!important;scrollbar-gutter:stable;box-sizing:border-box!important;padding:clamp(10px,2vw,24px)!important}'
    .'body.mad4b-extra-quickview-frame.mad4b-extra-quickview-gallery-active .elementor-popup-modal:has([data-mad4b-uael-tour="1"]) .dialog-close-button{position:absolute!important;z-index:120!important;inset-block-start:8px!important;inset-inline-end:12px!important}'
    .'</style>';
 }
 // Use the original Elementor document tree, without modifying stored templates.
 // On authorized quickview only, preserve the original Tour Soft CTA
 // ("Ready to experience this journey?") as the last content section.
 // Everything following it is pruned through each enclosing layout.
 private static function preview_document_ids(int $base):array {
  $ids=[$base];
  $trid=apply_filters('wpml_element_trid',null,$base,'post_elementor_library');
  if($trid){
   $translations=apply_filters('wpml_get_element_translations',[],$trid,'post_elementor_library');
   if(is_array($translations))foreach($translations as $translation){
    $id=is_object($translation)?(int)($translation->element_id??0):0;
    if($id>0)$ids[]=$id;
   }
  }
  return array_values(array_unique($ids));
 }
 private static function contains_tour_content_reference(array $node,array $ids):bool {
  if(($node['widgetType']??'')==='template'
    &&in_array(absint($node['settings']['template_id']??0),$ids,true))return true;
  $short=(string)($node['settings']['shortcode']??'');
  if($short!=='' && preg_match('/\[elementor-template\s+id=[\x22\x27]?(\d+)/i',$short,$m)
    &&in_array((int)$m[1],$ids,true))return true;
  foreach(($node['elements']??[]) as $child)if(is_array($child)
    &&self::contains_tour_content_reference($child,$ids))return true;
  return false;
 }
 private static function truncate_after_anchor(array $nodes,callable $anchor,bool &$found):array {
  $out=[];
  foreach($nodes as $node){
   if($found)break;
   if(!is_array($node)){$out[]=$node;continue;}
   if($anchor($node)){$out[]=$node;$found=true;break;}
   if(isset($node['elements'])&&is_array($node['elements']))
    $node['elements']=self::truncate_after_anchor($node['elements'],$anchor,$found);
   $out[]=$node;
  }
  return $out;
 }
 public static function preview_sections_until_cta($data,$document_id) {
  if(!is_array($data)||!is_numeric($document_id)
    ||get_post_type((int)$document_id)!=='elementor_library')return $data;
  $child=self::authorized_request();
  if(!$child||!is_singular('tours-and-activities')
    ||(int)get_queried_object_id()!==$child)return $data;
  $content_ids=self::preview_document_ids(24784);
  $found=false;
  if(in_array((int)$document_id,$content_ids,true)){
   $anchor=static function(array $node):bool {
    if(($node['elType']??'')!=='container')return false;
    $title=strtolower(trim((string)($node['settings']['_title']??'')));
    return ($node['id']??'')==='711a0ab'||$title==='soft cta';
   };
   $result=self::truncate_after_anchor($data,$anchor,$found);
   return $found?$result:$data;
  }
  // Discover the main Tour Content boundary through its native Elementor
  // Template reference, not an independently-maintained operation/section list.
  $anchor=static function(array $node)use($content_ids):bool {
   return ($node['elType']??'')==='container'
    &&self::contains_tour_content_reference($node,$content_ids);
  };
  $result=self::truncate_after_anchor($data,$anchor,$found);
  return $found?$result:$data;
 }
 // Elementor's authored inquiry column must not render inside the authorized
 // same-origin Extras Quick View. No form markup/handlers in the iframe.
 // The original Tour Single and all six Elementor translations stay unchanged.
 public static function suppress_inquiry_sidebar($should_render,$element){
  if(!$should_render||!is_object($element)||!method_exists($element,'get_id')||!method_exists($element,'get_settings'))return $should_render;
  $authorized=self::authorized_request();
  if(!$authorized||!is_singular('tours-and-activities')||(int)get_queried_object_id()!==$authorized)return $should_render;
  $element_id=(string)$element->get_id();
  $title=strtolower(trim((string)$element->get_settings('_title')));
  if($element_id==='c064aeb'||in_array($title,['side inquery form','side inquiry form'],true))return false;
  return $should_render;
 }
 // Attach a selection affordance to the EXISTING Elementor Soft CTA heading.
 // This is preview-only markup; selections remain owned by the native card.
 public static function cta_select_markup($html,$widget){
  if(!is_string($html)||!is_object($widget)||!method_exists($widget,'get_id')||
     (string)$widget->get_id()!=='02fb620')return $html;
  [$parent,$extra]=self::request();
  if(!$parent||!$extra||self::authorized_request()!==$extra||
     !is_singular('tours-and-activities')||(int)get_queried_object_id()!==$extra)return $html;
  $label=function_exists('mad4b_shared_extra_ui_label_v1')
    ?mad4b_shared_extra_ui_label_v1('select'):__('Select extra','mad4b');
  $action='<div class="mad4b-extra-preview-cta-action">'
    .'<button type="button" class="mad4b-extra-preview-cta-add"'
    .' data-mad4b-preview-select="'.esc_attr((string)$extra).'"'
    .' data-mad4b-preview-parent="'.esc_attr((string)$parent).'">'
    .esc_html($label).'</button>'
    .'<output class="mad4b-extra-preview-cta-message" aria-live="polite"></output></div>';
  return $html.$action;
 }
 public static function frame_assets():void{
  if(!self::authorized_request()||!is_singular('tours-and-activities')||
    (int)get_queried_object_id()!==self::authorized_request())return;
  $js=__DIR__.'/mad4b-extra-quick-preview/quick-preview-frame.js';
  if(is_readable($js))
    wp_enqueue_script('mad4b-extra-quick-preview-frame',
      content_url('mu-plugins/mad4b-extra-quick-preview/quick-preview-frame.js'),
      [],(string)filemtime($js),true);
 }
 public static function shortcode_action($output,$tag,$attr,$m){
  if($tag!=='extra_select_actions'||!is_string($output)||$output===''||!str_contains($output,'data-extra-actions='))return $output;
  global $wp_the_query;
  if(!($wp_the_query instanceof WP_Query)||!$wp_the_query->is_singular('tours-and-activities'))return $output;
  $parent=absint($wp_the_query->get_queried_object_id());$child=absint(get_the_ID());
  $url=self::url($parent,$child);if(!$url)return $output;
  $button='<button type="button" class="mad4b-extra-preview-open" data-mad4b-extra-preview="'.esc_attr((string)$child).'"'
      .' data-mad4b-extra-preview-url="'.esc_url($url).'"'
      .' data-mad4b-extra-preview-title="'.esc_attr(get_the_title($child)).'"'
      .' aria-haspopup="dialog">'.esc_html__('Quick preview','mad4b').'</button>';
  return str_replace('<output aria-live="polite"></output>',$button.'<output aria-live="polite"></output>',$output);
 }
 public static function assets():void {
  global $wp_the_query;
  if(!($wp_the_query instanceof WP_Query)||!$wp_the_query->is_singular('tours-and-activities')||self::authorized_request())return;
  $parent=absint($wp_the_query->get_queried_object_id());
  $ids=get_post_meta($parent,'tour_optional_extras',true);
  if(is_string($ids))$ids=maybe_unserialize($ids);
  if(!is_array($ids))return;
  $has=false;foreach(array_slice($ids,0,self::MAX_RELATIONS) as $id){if(is_scalar($id)&&ctype_digit(trim((string)$id))&&self::url($parent,(int)$id)!==''){$has=true;break;}}
  if(!$has)return;
  $js=__DIR__.'/mad4b-extra-quick-preview/quick-preview.js';$css=__DIR__.'/mad4b-extra-quick-preview/quick-preview.css';
  if(!is_readable($js)||!is_readable($css))return;
  wp_enqueue_script('mad4b-extra-quick-preview',content_url('mu-plugins/mad4b-extra-quick-preview/quick-preview.js'),[],(string)filemtime($js),true);
  wp_enqueue_style('mad4b-extra-quick-preview',content_url('mu-plugins/mad4b-extra-quick-preview/quick-preview.css'),[],(string)filemtime($css));
 }
}}
add_filter('elementor/frontend/builder_content_data',[MAD4B_Extra_Quick_Preview_V1::class,'preview_sections_until_cta'],99,2);
add_filter('elementor/frontend/container/should_render',[MAD4B_Extra_Quick_Preview_V1::class,'suppress_inquiry_sidebar'],80,2);
add_filter('elementor/widget/render_content',[MAD4B_Extra_Quick_Preview_V1::class,'cta_select_markup'],90,2);
add_filter('do_shortcode_tag',[MAD4B_Extra_Quick_Preview_V1::class,'shortcode_action'],70,4);
add_action('template_redirect',[MAD4B_Extra_Quick_Preview_V1::class,'validate_request'],1);
add_filter('body_class',[MAD4B_Extra_Quick_Preview_V1::class,'body_class']);
add_action('wp_head',[MAD4B_Extra_Quick_Preview_V1::class,'embed_style'],80);
add_action('wp_enqueue_scripts',[MAD4B_Extra_Quick_Preview_V1::class,'assets'],116);
add_action('wp_enqueue_scripts',[MAD4B_Extra_Quick_Preview_V1::class,'frame_assets'],117);
