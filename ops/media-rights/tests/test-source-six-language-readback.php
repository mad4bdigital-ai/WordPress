<?php
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WPCLI_REQUIRED');
$slugs=['en'=>'image-attribution','fr'=>'credits-photographiques','de'=>'bildnachweise','es'=>'creditos-de-imagenes','it'=>'crediti-fotografici','ar'=>'huquq-alsuwar'];
$previous=apply_filters('wpml_current_language','en');$base=get_page_by_path('image-attribution',OBJECT,'page');
if(!$base||$base->post_status!=='publish')throw new RuntimeException('ORIGINAL_PAGE_UNAVAILABLE');
$factory=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types('media_rights');
if(!$factory||!$factory->db->is_table_exists())throw new RuntimeException('NATIVE_CCT_MISSING');
$factory->db->set_format_flag(ARRAY_A);$credits=$factory->db->query(['cct_status'=>'publish'],12);if(count($credits)!==1)throw new RuntimeException('UNEXPECTED_SOURCE_CREDIT_COUNT');
$old=get_option('mad4b_licensed_media_credits_v1',[]);if(!is_array($old)||!isset($old[$credits[0]['credit_key']])||$old[$credits[0]['credit_key']]['author']!==$credits[0]['author'])throw new RuntimeException('CCT_LEGACY_BACKUP_DRIFT');
$results=[];$trids=[];
try{
 foreach($slugs as $lang=>$slug){
  do_action('wpml_switch_language',$lang);
  $id=apply_filters('wpml_object_id',$base->ID,'page',false,$lang);
  if(!$id||get_post_status($id)!=='publish'||get_post($id)->post_name!==$slug)throw new RuntimeException('PAGE_WPML_DRIFT_'.$lang);
  $trids[]=apply_filters('wpml_element_trid',null,$id,'post_page');
  if(!preg_match('/\\[elementor-template\\s+id="(\\d+)"\\]/',(string)get_post($id)->post_content,$m))throw new RuntimeException('ELEMENTOR_PAGE_BINDING_MISSING_'.$lang);
  $template=(int)$m[1];if(get_post_status($template)!=='publish'||get_post_meta($template,'_elementor_edit_mode',true)!=='builder')throw new RuntimeException('ELEMENTOR_TEMPLATE_DRIFT_'.$lang);
  $rendered=MAD4B_Media_Attribution_Registry_V1::render();$footer=do_shortcode('[mad4b_footer_legal]');
  if(substr_count($rendered,'<article class="wayfa-credits-card">')!==1||!str_contains($rendered,esc_html($credits[0]['author'])))throw new RuntimeException('RIGHTS_RENDER_DRIFT_'.$lang);
  if(substr_count($footer,'mad4b-media-rights-footer-link')!==1||!str_contains($footer,esc_url(get_permalink($id))))throw new RuntimeException('FOOTER_TRANSLATION_DRIFT_'.$lang);
  if($lang==='ar'&&!str_contains($rendered,'dir="rtl"'))throw new RuntimeException('ARABIC_RTL_DRIFT');
  $results[$lang]=['page_id'=>(int)$id,'template_id'=>$template,'card_count'=>1,'footer_link'=>true,'page_template'=>get_page_template_slug($id)];
 }
}finally{do_action('wpml_switch_language',$previous);}
if(count(array_unique($trids))!==1)throw new RuntimeException('WPML_TRANSLATION_GROUP_DRIFT');
if(has_action('wp_footer','MAD4B_Media_Attribution_Registry_V1::render'))throw new RuntimeException('OLD_GLOBAL_FOOTER_BANNER_ACTIVE');
WP_CLI::line(wp_json_encode(['state'=>'SOURCE_SIX_LANG_CCT_ELEMENTOR_FOOTER_PASS','languages'=>$results,'canonical_rights'=>count($credits),'legacy_option_still_readonly'=>true]));
