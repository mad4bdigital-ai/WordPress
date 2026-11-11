<?php
/** Local-only per-service admin preview. No change to public Service Activation. */
if(!defined('ABSPATH') || wp_get_environment_type()!=='local')return;
function abk_preview_types(){
 return ['tours-and-activities'=>'tours','properties'=>'properties','transportations'=>'transportations','travel-visa'=>'visas'];
}
function abk_private_preview_url($id){
 $id=absint($id);
 if(!isset(abk_preview_types()[get_post_type($id)]))return '';
 return wp_nonce_url(admin_url('admin-post.php?action=abk_service_preview&post_id='.$id),'abk_service_preview_'.$id);
}
add_action('admin_post_abk_service_preview', static function(){
 if(!current_user_can('manage_options'))wp_die('Forbidden','',['response'=>403]);
 $id=absint($_GET['post_id']??0);$post=$id?get_post($id):null;
 $map=abk_preview_types();
 if(!$post || !isset($map[$post->post_type]) || $post->post_status!=='publish')wp_die('Invalid service post','',['response'=>404]);
 check_admin_referer('abk_service_preview_'.$id);
 // The original Tour Single is the QA discovery experience, not a
 // separate shortcode/cart page. Redirect only the verified protected
 // QA fixture to its native permalink on the current forwarded origin.
 $cart_qa=get_option('commerce_cart_lab_fixture_20261026_v1',[]);
 if(is_array($cart_qa) && ($cart_qa['schema']??'')==='cart-lab-fixture-v1'
    && $id===(int)($cart_qa['tour_id']??0)
    && $id===66996
    && function_exists('partner_rights_local_write_hold_active')
    && partner_rights_local_write_hold_active()
    && get_post_meta($id,'_booking_experience_qa_pricing_fixture',true)==='cart-lab-20261026:tour'){
    // Relative URLs never redirect a remote preview browser to its own localhost.
    wp_safe_redirect(wp_make_link_relative(get_permalink($id)),
       302,'Local QA original Tour discovery');
    exit;
 }
 define('ABK_PRIVATE_SERVICE_PREVIEW',true);
 $GLOBALS['abk_private_service_preview_key']=$map[$post->post_type];
 nocache_headers();header('X-Robots-Tag: noindex, nofollow, noarchive',true);
 status_header(200);
 echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><title>Private Service Preview</title>';
 wp_head();echo '</head><body class="abk-private-service-preview">';
 $previous=$GLOBALS['post']??null;$GLOBALS['post']=$post;setup_postdata($post);
 // Render the matching Elementor Single template if explicitly assigned.
 $template=abk_private_service_template($post->post_type);
 if($template && class_exists('Elementor\Plugin')){
  echo \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($template,true);
 } else {
  echo '<main class="abk-preview-fallback"><h1>'.esc_html(get_the_title($id)).'</h1>';
  echo apply_filters('the_content',$post->post_content);echo '</main>';
 }
 wp_reset_postdata();$GLOBALS['post']=$previous;
 wp_footer();echo '</body></html>';exit;
});
add_filter('post_row_actions','abk_private_service_preview_row_action',20,2);
add_filter('page_row_actions','abk_private_service_preview_row_action',20,2);
function abk_private_service_preview_row_action($actions,$post){
 if(!isset(abk_preview_types()[$post->post_type])||$post->post_status!=='publish'||!current_user_can('manage_options'))return $actions;
 $url=abk_private_preview_url($post->ID);
 $actions['abk_private_preview']='<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Private Service Preview</a>';
 return $actions;
}

/** Only exact singular conditions qualify; ambiguous matches fail closed. */
function abk_private_service_template($post_type){
 if(!isset(abk_preview_types()[$post_type]))return 0;
 $candidates=get_posts(['post_type'=>'elementor_library','post_status'=>'publish','posts_per_page'=>-1,
  'meta_query'=>[['key'=>'_elementor_template_type','value'=>'single-page']]]);
 $matches=[];
 foreach($candidates as $candidate){
  $conditions=get_post_meta($candidate->ID,'_elementor_conditions',true);
  if(is_array($conditions)&&in_array('include/singular/'.$post_type,$conditions,true))$matches[]=(int)$candidate->ID;
 }
 return count($matches)===1?$matches[0]:0;
}
add_action('post_submitbox_misc_actions',static function($post){
 if(!current_user_can('manage_options')||$post->post_status!=='publish'||!isset(abk_preview_types()[$post->post_type]))return;
 $url=abk_private_preview_url($post->ID);
 echo '<div class="misc-pub-section"><a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">Private Service Preview</a></div>';
});
