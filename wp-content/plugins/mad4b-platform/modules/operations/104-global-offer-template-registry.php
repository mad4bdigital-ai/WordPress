<?php
/** Local lab: scoped Elementor offer component templates, never replaces listing identity. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
function shared_global_offer_template_id($slot='badge',$lang=''){
 $registry=get_option('shared_global_offer_template_registry',[]);
 if(!is_array($registry))return 0;
 $lang=$lang?: (defined('ICL_LANGUAGE_CODE')?ICL_LANGUAGE_CODE:'');
 $candidates=array_filter([$lang,'default']);
 foreach($candidates as $key){
  $id=absint($registry[$slot][$key]??0);
  if(!$id)continue;
  $post=get_post($id);
  if($post&&$post->post_type==='elementor_library'&&$post->post_status==='publish')return $id;
 }
 return 0;
}
function shared_global_offer_template_component($atts=[]){
 $atts=shortcode_atts(['slot'=>'badge','product_id'=>0],$atts,'global_offer_template');
 $slot=sanitize_key($atts['slot']);if(!in_array($slot,['badge','header'],true))return '';
 if(!function_exists('global_offer_resolved')||!global_offer_resolved())return '';
 if($slot==='badge' && !shared_global_offer_product_badge(['product_id'=>absint($atts['product_id'])]))return '';
 $id=shared_global_offer_template_id($slot);if(!$id)return $slot==='badge'?do_shortcode('[global_offer_product_badge product_id="'.absint($atts['product_id']).'"]'):'';
 static $rendering=[];
 if(isset($rendering[$id]))return '';
 // Do not render the active listing as an embedded template.
 if((int)get_the_ID()===$id)return '';
 if(!class_exists('Elementor\\Plugin'))return '';
 $rendering[$id]=true;
 $previous=$GLOBALS['shared_global_offer_render_product_id']??null;
 $GLOBALS['shared_global_offer_render_product_id']=absint($atts['product_id'])?:get_the_ID();
 try{return (string)\Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id,true);}
 finally{unset($rendering[$id]);if($previous===null)unset($GLOBALS['shared_global_offer_render_product_id']);else $GLOBALS['shared_global_offer_render_product_id']=$previous;}
}
add_shortcode('global_offer_template','shared_global_offer_template_component');
